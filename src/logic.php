<?php

require_once __DIR__ . '/db.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Logic implements MessageComponentInterface {

    // =========================================================
    //  KONSTANTA GAME
    // =========================================================
    const MAX_PLAYERS    = 2;
    const MAX_ROUNDS     = 5;
    const DELAY_MIN_MS   = 2000;
    const DELAY_MAX_MS   = 5000;
    const PENALTY_POINTS = 50;
    const ANTI_CHEAT_MS  = 500;  // toleransi selisih waktu client vs server

    // =========================================================
    //  STATE SERVER
    // =========================================================
    private \SplObjectStorage $clients;

    private array $players = [];
    /*
     * Struktur per entry $players:
     * [
     *   'conn'        => ConnectionInterface,
     *   'username'    => string,
     *   'score'       => int,
     *   'reactionLog' => float[],   // histori reaction time yang menang (ms)
     *   'penalties'   => int,
     * ]
     */

    private bool   $gameStarted   = false;
    private bool   $waitingForGo  = false;
    private bool   $goSignalSent  = false;
    private int    $currentRound  = 0;
    private ?float $goTimerStart  = null; // microtime(true)*1000 saat GO dikirim

    // Histori ronde untuk disimpan ke DB saat game selesai
    private array $roundLog = [];

    // =========================================================
    //  KONSTRUKTOR
    // =========================================================
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

            case 'get_leaderboard':
                $this->handleGetLeaderboard($from);
                break;

            default:
                echo "[WARN]   Tipe pesan tidak dikenal: {$data['type']}\n";
        }
    }

    public function onClose(ConnectionInterface $conn): void {
        $this->clients->detach($conn);
        $username = $this->getUsernameByConn($conn) ?? "#{$conn->resourceId}";
        $this->removePlayer($conn);
        echo "[CLOSE]  {$username} telah keluar.\n";

        if ($this->gameStarted) {
            echo "[RESET]  Pemain keluar saat game aktif. Mereset sesi...\n";
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
    //  HANDLER — JOIN
    // =========================================================
    private function handleJoin(ConnectionInterface $conn, array $data): void {
        $username = trim($data['username'] ?? '');

        if ($username === '') {
            $conn->send($this->encode(['type' => 'ERROR', 'message' => 'Username tidak boleh kosong.']));
            return;
        }

        foreach ($this->players as $p) {
            if (strtolower($p['username']) === strtolower($username)) {
                $conn->send($this->encode([
                    'type'    => 'ERROR',
                    'message' => "Nama '{$username}' sudah dipakai. Pilih nama lain."
                ]));
                return;
            }
        }

        if (count($this->players) >= self::MAX_PLAYERS) {
            $conn->send($this->encode([
                'type'    => 'ERROR',
                'message' => 'Room sudah penuh (' . self::MAX_PLAYERS . ' pemain).'
            ]));
            return;
        }

        $this->players[] = [
            'conn'        => $conn,
            'username'    => $username,
            'score'       => 0,
            'reactionLog' => [],
            'penalties'   => 0,
        ];

        echo "[JOIN]   {$username} bergabung. Total: " . count($this->players) . "\n";
        $this->broadcastPlayerList();

        if (count($this->players) >= self::MAX_PLAYERS) {
            $this->startGame();
        }
    }

    // =========================================================
    //  HANDLER — REACTION TIME
    // =========================================================
    private function handleReactionTime(ConnectionInterface $from, array $data): void {
        if (!$this->goSignalSent) {
            echo "[IGNORE] REACTION_TIME diterima tapi GO belum dikirim.\n";
            return;
        }

        $username     = $data['username'] ?? '?';
        $clientTimeMs = (float)($data['time'] ?? 0);

        // Hitung waktu server sebagai referensi anti-cheat
        $serverTimeMs = round((microtime(true) * 1000) - $this->goTimerStart, 2);

        echo "[REACT]  {$username} | Client: {$clientTimeMs}ms | Server: {$serverTimeMs}ms\n";

        // Anti-cheat: jika selisih > 500ms, pakai waktu server
        if (abs($clientTimeMs - $serverTimeMs) > self::ANTI_CHEAT_MS) {
            echo "[CHEAT?] Selisih mencurigakan untuk {$username}. Pakai waktu server.\n";
            $finalTime = $serverTimeMs;
        } else {
            // Rata-rata client & server untuk fairness terhadap latency
            $finalTime = round(($clientTimeMs + $serverTimeMs) / 2, 2);
        }

        // Tandai ronde selesai — hanya klik pertama yang sah
        $this->goSignalSent = false;

        $this->addScore($username, 100);
        $this->addReactionLog($username, $finalTime);

        // Simpan log ronde
        $this->roundLog[] = [
            'round'  => $this->currentRound,
            'winner' => $username,
            'time'   => $finalTime,
        ];

        echo "[RESULT] Pemenang ronde {$this->currentRound}: {$username} ({$finalTime}ms)\n";

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

    // =========================================================
    //  HANDLER — TOO EARLY
    // =========================================================
    private function handleTooEarly(ConnectionInterface $from, array $data): void {
        $username = $data['username'] ?? '?';
        echo "[EARLY]  {$username} klik terlalu cepat!\n";

        $this->addPenalty($username);

        $this->broadcastAll([
            'type'    => 'TOO_EARLY',
            'culprit' => $username,
        ]);

        // Jika masih countdown → reset timer GO
        if ($this->waitingForGo) {
            $this->waitingForGo = false;
            $this->scheduleCall(1500, fn() => $this->scheduleGoSignal());
        }
    }

    // =========================================================
    //  HANDLER — GET LEADERBOARD (WebSocket API)
    // =========================================================
    private function handleGetLeaderboard(ConnectionInterface $from): void {
        echo "[LB]     Request leaderboard dari #{$from->resourceId}\n";

        try {
            $db   = getDB();
            $rows = $db->query("
                SELECT username, score, avg_reaction_time, best_time
                FROM players_stats
                ORDER BY score DESC
                LIMIT 10
            ")->fetchAll(\PDO::FETCH_ASSOC);

            $from->send($this->encode([
                'type' => 'leaderboard_data',
                'data' => $rows,
            ]));

            echo "[LB]     Terkirim " . count($rows) . " entri ke #{$from->resourceId}\n";

        } catch (\PDOException $e) {
            echo "[DB ERR] Leaderboard gagal: {$e->getMessage()}\n";
            $from->send($this->encode([
                'type'    => 'leaderboard_data',
                'data'    => [],
                'warning' => 'Database sedang tidak tersedia.',
            ]));
        }
    }

    // =========================================================
    //  GAME FLOW
    // =========================================================
    private function startGame(): void {
        $this->gameStarted  = true;
        $this->currentRound = 0;
        $this->roundLog     = [];

        echo "[GAME]   Game dimulai!\n";
        $this->broadcastAll(['type' => 'START_GAME']);
        $this->scheduleCall(1500, fn() => $this->startRound());
    }

    private function startRound(): void {
        $this->currentRound++;
        $this->goSignalSent = false;
        $this->waitingForGo = false;

        echo "[ROUND]  Ronde {$this->currentRound} / " . self::MAX_ROUNDS . "\n";

        $this->broadcastAll([
            'type'  => 'ROUND_UPDATE',
            'round' => $this->currentRound,
        ]);

        $this->broadcastAll(['type' => 'WAIT']);
        $this->scheduleGoSignal();
    }

    private function scheduleGoSignal(): void {
        $this->waitingForGo = true;
        $delayMs = rand(self::DELAY_MIN_MS, self::DELAY_MAX_MS);
        echo "[TIMER]  GO dalam {$delayMs}ms...\n";

        $this->scheduleCall($delayMs, function () {
            if (!$this->waitingForGo) return;

            $this->waitingForGo  = false;
            $this->goSignalSent  = true;
            $this->goTimerStart  = round(microtime(true) * 1000, 2);

            echo "[GO]     Sinyal GO dikirim!\n";
            $this->broadcastAll(['type' => 'GO']);
        });
    }

    private function endGame(): void {
        $this->gameStarted = false;
        echo "[GAME]   Game selesai! Menyusun statistik...\n";

        $statsPerPlayer = [];

        foreach ($this->players as $p) {
            $log = $p['reactionLog'];

            if (count($log) > 0) {
                $avg         = round(array_sum($log) / count($log), 2);
                $best        = round(min($log), 2);
                $consistency = round(max($log) - min($log), 2);
            } else {
                $avg = $best = $consistency = null;
            }

            $statsPerPlayer[] = [
                'username'    => $p['username'],
                'score'       => $p['score'],
                'avgTime'     => $avg,
                'bestTime'    => $best,
                'consistency' => $consistency,
                'penalties'   => $p['penalties'],
                'roundsWon'   => count($log),
                'reactionLog' => $log,
            ];
        }

        // Urutkan berdasarkan skor
        usort($statsPerPlayer, fn($a, $b) => $b['score'] <=> $a['score']);

        $this->broadcastAll([
            'type'  => 'GAME_OVER',
            'stats' => $statsPerPlayer,
        ]);

        echo "[STATS]  " . json_encode($statsPerPlayer, JSON_PRETTY_PRINT) . "\n";

        // Simpan ke database (tidak crash server jika DB down)
        $this->saveToDatabase($statsPerPlayer);

        $this->scheduleCall(5000, fn() => $this->resetGame());
    }

    private function resetGame(): void {
        $this->gameStarted  = false;
        $this->waitingForGo = false;
        $this->goSignalSent = false;
        $this->currentRound = 0;
        $this->goTimerStart = null;
        $this->roundLog     = [];

        foreach ($this->players as &$p) {
            $p['score']       = 0;
            $p['reactionLog'] = [];
            $p['penalties']   = 0;
        }
        unset($p);

        echo "[RESET]  Sesi direset. Menunggu game baru...\n";
        $this->broadcastPlayerList();
    }

    // =========================================================
    //  DATABASE — AUTO SAVE (ON DUPLICATE KEY UPDATE)
    // =========================================================
    private function saveToDatabase(array $statsPerPlayer): void {
        try {
            $db = getDB();

            // Jika DB tidak tersedia, skip simpan — server tidak crash
            if ($db === null) {
                echo "[DB ERR] getDB() null, skip penyimpanan.\n";
                return;
            }

            $stmt = $db->prepare("
                INSERT INTO players_stats
                    (username, score, avg_reaction_time, best_time)
                VALUES
                    (:username, :score, :avg, :best)
                ON DUPLICATE KEY UPDATE
                    score             = score + VALUES(score),
                    avg_reaction_time = IF(
                        avg_reaction_time IS NULL,
                        VALUES(avg_reaction_time),
                        ROUND((avg_reaction_time + VALUES(avg_reaction_time)) / 2, 2)
                    ),
                    best_time         = IF(
                        best_time IS NULL OR VALUES(best_time) < best_time,
                        VALUES(best_time),
                        best_time
                    )
            ");

            foreach ($statsPerPlayer as $p) {
                // Lewati pemain yang tidak menang satu pun ronde
                if ($p['roundsWon'] === 0 && $p['score'] === 0) continue;

                $stmt->execute([
                    ':username' => $p['username'],
                    ':score'    => $p['score'],
                    ':avg'      => $p['avgTime'],
                    ':best'     => $p['bestTime'],
                ]);

                echo "[DB]     Disimpan: {$p['username']} | Skor: {$p['score']} | Avg: {$p['avgTime']}ms\n";
            }

            // ── Simpan tiap ronde ke round_logs ──────────────────────────
            if (!empty($this->roundLog)) {
                $stmtRound = $db->prepare("
                    INSERT INTO round_logs (username, reaction_time, is_foul)
                    VALUES (:username, :time, 0)
                ");

                foreach ($this->roundLog as $r) {
                    $stmtRound->execute([
                        ':username' => $r['winner'],
                        ':time'     => $r['time'],
                    ]);
                }

                echo "[DB]     Round logs disimpan: " . count($this->roundLog) . " ronde\n";
            }

        } catch (\PDOException $e) {
            // Server TIDAK crash — hanya log error
            echo "[DB ERR] Gagal simpan ke database: {$e->getMessage()}\n";
            echo "[DB ERR] Game tetap berjalan normal.\n";
        }
    }

    // =========================================================
    //  HELPER — SKOR & LOG
    // =========================================================
    private function addScore(string $username, int $points): void {
        foreach ($this->players as &$p) {
            if ($p['username'] === $username) {
                $p['score'] += $points;
                break;
            }
        }
        unset($p);
    }

    private function addReactionLog(string $username, float $time): void {
        foreach ($this->players as &$p) {
            if ($p['username'] === $username) {
                $p['reactionLog'][] = $time;
                break;
            }
        }
        unset($p);
    }

    private function addPenalty(string $username): void {
        foreach ($this->players as &$p) {
            if ($p['username'] === $username) {
                $p['penalties']++;
                $p['score'] = max(0, $p['score'] - self::PENALTY_POINTS);
                break;
            }
        }
        unset($p);
    }

    // =========================================================
    //  HELPER — BROADCAST & UTILITY
    // =========================================================
    private function broadcastAll(array $payload): void {
        $json = $this->encode($payload);
        foreach ($this->clients as $client) {
            $client->send($json);
        }
    }

    private function broadcastPlayerList(): void {
        $list = array_map(fn($p) => [
            'name'  => $p['username'],
            'score' => $p['score'],
            'ready' => true,
        ], $this->players);

        $this->broadcastAll([
            'type'    => 'PLAYER_LIST',
            'players' => $list,
        ]);
    }

    private function removePlayer(ConnectionInterface $conn): void {
        $this->players = array_values(
            array_filter($this->players, fn($p) => $p['conn'] !== $conn)
        );
    }

    private function getUsernameByConn(ConnectionInterface $conn): ?string {
        foreach ($this->players as $p) {
            if ($p['conn'] === $conn) return $p['username'];
        }
        return null;
    }

    private function encode(array $data): string {
        return json_encode($data);
    }

    private function scheduleCall(int $ms, callable $callback): void {
        \React\EventLoop\Loop::get()->addTimer($ms / 1000.0, $callback);
    }
}
