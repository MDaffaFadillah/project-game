<?php

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

// WAJIB: Memanggil file koneksi database Adam
require_once __DIR__ . '/db.php';

class Logic implements MessageComponentInterface {

    // =========================================================
    //  KONSTANTA GAME
    // =========================================================
    const MAX_PLAYERS    = 2;    // Jumlah pemain untuk mulai game
    const MAX_ROUNDS     = 5;    // Total ronde per sesi
    const DELAY_MIN_MS   = 2000; // Delay minimum sebelum sinyal GO (ms)
    const DELAY_MAX_MS   = 5000; // Delay maksimum sebelum sinyal GO (ms)
    const PENALTY_POINTS = 50;   // Penalti poin jika klik terlalu cepat

    // =========================================================
    //  STATE SERVER
    // =========================================================
    private \SplObjectStorage $clients;  
    private array $players = [];
    private bool  $gameStarted   = false;
    private bool  $waitingForGo  = false; 
    private bool  $goSignalSent  = false; 
    private int   $currentRound  = 0;
    private ?int  $goTimerStart  = null;  

    public function __construct() {
        $this->clients = new \SplObjectStorage();
        echo "[SERVER] Logic siap. Menunggu " . self::MAX_PLAYERS . " pemain...\n";
    }

    // =========================================================
    //  RATCHET CALLBACKS
    // =========================================================
    public function onOpen(ConnectionInterface $conn): void {
        $this->clients->attach($conn);
        echo "[OPEN]   Koneksi baru: #{$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg): void {
        $data = json_decode($msg, true);

        if (!$data || !isset($data['type'])) {
            echo "[WARN]   Pesan tidak valid dari #{$from->resourceId}\n";
            return;
        }

        // --- FITUR LEADERBOARD (ADAM) ---
        if ($data['type'] === 'get_leaderboard') {
            $db = getDB();
            if ($db) {
                $stmt = $db->query("SELECT username, score, avg_reaction_time FROM players_stats ORDER BY score DESC LIMIT 10");
                $leaderboard = $stmt->fetchAll();
                
                $from->send(json_encode([
                    'type' => 'leaderboard_data',
                    'data' => $leaderboard
                ]));
                echo "[DB]     Mengirim leaderboard ke #{$from->resourceId}\n";
            }
            return; // Berhenti di sini untuk pesan tipe ini
        }

        echo "[MSG]    Dari #{$from->resourceId} → type: {$data['type']}\n";

        switch ($data['type']) {
            case 'JOIN':
                $this->handleJoin($from, $data);
                break;
            case 'REACTION_TIME':
                $this->handleReactionTime($from, $data);
                break;
            case 'TOO_EARLY':
                $this->handleTooEarly($from, $data);
                break;
        }
    }

    public function onClose(ConnectionInterface $conn): void {
        $this->clients->detach($conn);
        $username = $this->getUsernameByConn($conn) ?? "#{$conn->resourceId}";
        $this->removePlayer($conn);
        echo "[CLOSE]  {$username} telah keluar.\n";

        if ($this->gameStarted) {
            $this->resetGame();
            $this->broadcastAll([
                'type'    => 'SYSTEM',
                'message' => "{$username} keluar. Game dibatalkan."
            ]);
        }
        $this->broadcastPlayerList();
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void {
        echo "[ERROR]  #{$conn->resourceId}: {$e->getMessage()}\n";
        $conn->close();
    }

    // =========================================================
    //  HANDLERS
    // =========================================================

    private function handleJoin(ConnectionInterface $conn, array $data): void {
        $username = trim($data['username'] ?? '');
        if ($username === '') return;

        foreach ($this->players as $p) {
            if (strtolower($p['username']) === strtolower($username)) {
                $conn->send(json_encode(['type' => 'ERROR', 'message' => 'Nama sudah dipakai.']));
                return;
            }
        }

        if (count($this->players) >= self::MAX_PLAYERS) {
            $conn->send(json_encode(['type' => 'ERROR', 'message' => 'Room penuh.']));
            return;
        }

        $this->players[] = [
            'conn'        => $conn,
            'username'    => $username,
            'score'       => 0,
            'reactionLog' => [],
            'penalties'   => 0,
        ];

        $this->broadcastPlayerList();
        if (count($this->players) >= self::MAX_PLAYERS) {
            $this->startGame();
        }
    }

    private function handleReactionTime(ConnectionInterface $from, array $data): void {
        if (!$this->goSignalSent) return;

        $username     = $data['username'] ?? '?';
        $clientTimeMs = (float)($data['time'] ?? 0);
        $serverTimeMs = round((microtime(true) * 1000) - $this->goTimerStart, 2);

        $this->goSignalSent = false;
        $finalTime = (abs($clientTimeMs - $serverTimeMs) > 500) ? $serverTimeMs : round(($clientTimeMs + $serverTimeMs) / 2, 2);

        $this->addScore($username, 100);
        $this->addReactionLog($username, $finalTime);

        $this->broadcastAll([
            'type'   => 'RESULT',
            'winner' => $username,
            'time'   => $finalTime,
        ]);

        if ($this->currentRound >= self::MAX_ROUNDS) {
            $this->scheduleCall(2500, fn() => $this->endGame());
        } else {
            $this->scheduleCall(2500, fn() => $this->startRound());
        }
    }

    private function handleTooEarly(ConnectionInterface $from, array $data): void {
        $username = $data['username'] ?? '?';
        $this->addPenalty($username);
        $this->broadcastAll(['type' => 'TOO_EARLY', 'culprit' => $username]);

        if ($this->waitingForGo) {
            $this->waitingForGo = false;
            $this->scheduleCall(1500, fn() => $this->scheduleGoSignal());
        }
    }

    // =========================================================
    //  GAME FLOW
    // =========================================================

    private function startGame(): void {
        $this->gameStarted  = true;
        $this->currentRound = 0;
        $this->broadcastAll(['type' => 'START_GAME']);
        $this->scheduleCall(1500, fn() => $this->startRound());
    }

    private function startRound(): void {
        $this->currentRound++;
        $this->goSignalSent = false;
        $this->waitingForGo = false;
        $this->broadcastAll(['type' => 'ROUND_UPDATE', 'round' => $this->currentRound]);
        $this->broadcastAll(['type' => 'WAIT']);
        $this->scheduleGoSignal();
    }

    private function scheduleGoSignal(): void {
        $this->waitingForGo = true;
        $delayMs = rand(self::DELAY_MIN_MS, self::DELAY_MAX_MS);
        $this->scheduleCall($delayMs, function () {
            if (!$this->waitingForGo) return;
            $this->waitingForGo = false;
            $this->goSignalSent  = true;
            $this->goTimerStart  = round(microtime(true) * 1000, 2);
            $this->broadcastAll(['type' => 'GO']);
        });
    }

    private function endGame(): void {
        $this->gameStarted = false;
        $statsPerPlayer = [];

        foreach ($this->players as $p) {
            $log = $p['reactionLog'];
            $avg = (count($log) > 0) ? round(array_sum($log) / count($log), 2) : 0;
            $best = (count($log) > 0) ? round(min($log), 2) : 0;

            $statsPerPlayer[] = [
                'username'    => $p['username'],
                'score'       => $p['score'],
                'avgTime'     => $avg,
                'bestTime'    => $best,
                'penalties'   => $p['penalties'],
                'roundsWon'   => count($log),
            ];
        }

        // --- SIMPAN KE DATABASE ADAM ---
        $this->saveFinalStats($statsPerPlayer);

        usort($statsPerPlayer, fn($a, $b) => $b['score'] <=> $a['score']);
        $this->broadcastAll(['type' => 'GAME_OVER', 'stats' => $statsPerPlayer]);

        $this->scheduleCall(5000, fn() => $this->resetGame());
    }

    private function saveFinalStats(array $stats): void {
        $db = getDB();
        if (!$db) {
            echo "[DB]     Gagal simpan: Koneksi DB mati.\n";
            return;
        }

        try {
            // ON DUPLICATE KEY UPDATE: Menambah skor lama dengan skor baru
            $stmt = $db->prepare("INSERT INTO players_stats (username, score, avg_reaction_time, best_time) 
                                  VALUES (:user, :score, :avg, :best)
                                  ON DUPLICATE KEY UPDATE 
                                  score = score + VALUES(score), 
                                  avg_reaction_time = VALUES(avg_reaction_time),
                                  best_time = LEAST(best_time, VALUES(best_time))");
            
            foreach ($stats as $s) {
                $stmt->execute([
                    ':user'  => $s['username'],
                    ':score' => $s['score'],
                    ':avg'   => $s['avgTime'],
                    ':best'  => $s['bestTime']
                ]);
            }
            echo "[DB]     Berhasil menyimpan statistik ke database.\n";
        } catch (\Exception $e) {
            echo "[DB]     Error simpan: " . $e->getMessage() . "\n";
        }
    }

    private function resetGame(): void {
        $this->gameStarted  = false;
        $this->waitingForGo = false;
        $this->goSignalSent  = false;
        $this->currentRound = 0;
        foreach ($this->players as &$p) {
            $p['score'] = 0;
            $p['reactionLog'] = [];
            $p['penalties'] = 0;
        }
    }

    // =========================================================
    //  HELPERS
    // =========================================================
    private function addScore(string $user, int $pts): void {
        foreach ($this->players as &$p) { if ($p['username'] === $user) { $p['score'] += $pts; break; } }
    }
    private function addReactionLog(string $user, float $t): void {
        foreach ($this->players as &$p) { if ($p['username'] === $user) { $p['reactionLog'][] = $t; break; } }
    }
    private function addPenalty(string $user): void {
        foreach ($this->players as &$p) { if ($p['username'] === $user) { $p['penalties']++; $p['score'] = max(0, $p['score'] - self::PENALTY_POINTS); break; } }
    }
    private function broadcastAll(array $payload): void {
        $json = json_encode($payload);
        foreach ($this->clients as $c) { $c->send($json); }
    }
    private function broadcastPlayerList(): void {
        $list = array_map(fn($p) => ['name' => $p['username'], 'score' => $p['score'], 'ready' => true], $this->players);
        $this->broadcastAll(['type' => 'PLAYER_LIST', 'players' => $list]);
    }
    private function removePlayer($conn): void {
        $this->players = array_values(array_filter($this->players, fn($p) => $p['conn'] !== $conn));
    }
    private function getUsernameByConn($conn): ?string {
        foreach ($this->players as $p) { if ($p['conn'] === $conn) return $p['username']; }
        return null;
    }
    private function scheduleCall(int $ms, callable $cb): void {
        \React\EventLoop\Loop::get()->addTimer($ms / 1000.0, $cb);
    }
}
