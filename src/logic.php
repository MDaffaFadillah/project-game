<?php

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

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
    private \SplObjectStorage $clients;  // Semua koneksi aktif

    private array $players = [];
    /*
     * Format per-entry:
     * [
     *   'conn'        => ConnectionInterface,
     *   'username'    => string,
     *   'score'       => int,
     *   'reactionLog' => float[],   // histori waktu reaksi (ms) yang menang
     *   'penalties'   => int,       // jumlah klik terlalu cepat
     * ]
     */

    private bool  $gameStarted   = false;
    private bool  $waitingForGo  = false; // true = sedang dalam fase countdown sebelum GO
    private bool  $goSignalSent  = false; // true = GO sudah dikirim, menunggu klik pertama
    private int   $currentRound  = 0;
    private ?int  $goTimerStart  = null;  // microtime saat GO dikirim (ms)

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

            default:
                echo "[WARN]   Tipe pesan tidak dikenal: {$data['type']}\n";
        }
    }

    public function onClose(ConnectionInterface $conn): void {
        $this->clients->detach($conn);
        $username = $this->getUsernameByConn($conn) ?? "#{$conn->resourceId}";
        $this->removePlayer($conn);
        echo "[CLOSE]  {$username} telah keluar.\n";

        // Jika game sedang berjalan lalu ada yang disconnect, reset sesi
        if ($this->gameStarted) {
            echo "[RESET]  Pemain keluar saat game aktif. Mereset sesi...\n";
            $this->resetGame();
            $this->broadcastAll([
                'type'    => 'SYSTEM',
                'message' => "{$username} keluar. Game dibatalkan."
            ]);
        }

        // Update daftar pemain ke yang masih tersisa
        $this->broadcastPlayerList();
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void {
        echo "[ERROR]  #{$conn->resourceId}: {$e->getMessage()}\n";
        $conn->close();
    }

    // =========================================================
    //  HANDLER PESAN
    // =========================================================

    /**
     * Pemain baru bergabung ke ruangan.
     */
    private function handleJoin(ConnectionInterface $conn, array $data): void {
        $username = trim($data['username'] ?? '');

        if ($username === '') {
            $conn->send($this->encode(['type' => 'ERROR', 'message' => 'Username tidak boleh kosong.']));
            return;
        }

        // Cek nama duplikat
        foreach ($this->players as $p) {
            if (strtolower($p['username']) === strtolower($username)) {
                $conn->send($this->encode([
                    'type'    => 'ERROR',
                    'message' => "Nama '{$username}' sudah dipakai. Pilih nama lain."
                ]));
                return;
            }
        }

        // Tolak jika room sudah penuh
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

        echo "[JOIN]   {$username} bergabung. Total pemain: " . count($this->players) . "\n";

        $this->broadcastPlayerList();

        // Mulai game jika sudah cukup pemain
        if (count($this->players) >= self::MAX_PLAYERS) {
            $this->startGame();
        }
    }

    /**
     * Menerima waktu reaksi dari client.
     * Hanya klik pertama yang sah (server sebagai wasit).
     */
    private function handleReactionTime(ConnectionInterface $from, array $data): void {
        // Abaikan jika GO belum dikirim atau sudah ada pemenang ronde ini
        if (!$this->goSignalSent) {
            echo "[IGNORE] REACTION_TIME diterima tapi GO belum dikirim.\n";
            return;
        }

        $username     = $data['username'] ?? '?';
        $clientTimeMs = (float)($data['time'] ?? 0);

        // Hitung waktu reaksi dari sisi server (sebagai referensi anti-cheat)
        $serverTimeMs = round((microtime(true) * 1000) - $this->goTimerStart, 2);

        echo "[REACT]  {$username} klik! Client: {$clientTimeMs}ms | Server: {$serverTimeMs}ms\n";

        // Validasi sederhana: tolak jika selisih > 500ms (kemungkinan cheat/lag ekstrem)
        if (abs($clientTimeMs - $serverTimeMs) > 500) {
            echo "[CHEAT?] Selisih waktu mencurigakan untuk {$username}. Menggunakan waktu server.\n";
            $finalTime = $serverTimeMs;
        } else {
            // Gunakan rata-rata client & server untuk fairness
            $finalTime = round(($clientTimeMs + $serverTimeMs) / 2, 2);
        }

        // Tandai GO sudah diklaim (ronde selesai)
        $this->goSignalSent = false;

        // Tambah skor pemenang
        $this->addScore($username, 100);
        $this->addReactionLog($username, $finalTime);

        echo "[RESULT] Pemenang ronde {$this->currentRound}: {$username} ({$finalTime}ms)\n";

        $this->broadcastAll([
            'type'   => 'RESULT',
            'winner' => $username,
            'time'   => $finalTime,
        ]);

        // Lanjut ke ronde berikutnya atau akhiri game
        if ($this->currentRound >= self::MAX_ROUNDS) {
            $this->scheduleCall(2500, fn() => $this->endGame());
        } else {
            $this->scheduleCall(2500, fn() => $this->startRound());
        }
    }

    /**
     * Client mengklik sebelum sinyal GO.
     */
    private function handleTooEarly(ConnectionInterface $from, array $data): void {
        $username = $data['username'] ?? '?';

        echo "[EARLY]  {$username} klik terlalu cepat!\n";

        $this->addPenalty($username);

        // Beritahu semua pemain siapa yang terlalu cepat
        $this->broadcastAll([
            'type'    => 'TOO_EARLY',
            'culprit' => $username,
        ]);

        // Jika masih dalam fase countdown (belum GO), restart timer-nya
        if ($this->waitingForGo) {
            echo "[TIMER]  Mereset timer GO karena {$username} curang...\n";
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

        echo "[GAME]   Game dimulai!\n";

        $this->broadcastAll(['type' => 'START_GAME']);
        $this->scheduleCall(1500, fn() => $this->startRound());
    }

    private function startRound(): void {
        $this->currentRound++;
        $this->goSignalSent  = false;
        $this->waitingForGo  = false;

        echo "[ROUND]  Memulai ronde {$this->currentRound} / " . self::MAX_ROUNDS . "\n";

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
        echo "[TIMER]  Mengirim GO dalam {$delayMs}ms...\n";

        $this->scheduleCall($delayMs, function () {
            // Pastikan belum ada yang terlalu cepat yang mereset timer
            if (!$this->waitingForGo) {
                return;
            }

            $this->waitingForGo = false;
            $this->goSignalSent  = true;
            $this->goTimerStart  = round(microtime(true) * 1000, 2);

            echo "[GO]     Sinyal GO dikirim!\n";
            $this->broadcastAll(['type' => 'GO']);
        });
    }

    private function endGame(): void {
        $this->gameStarted = false;
        echo "[GAME]   Game selesai! Menghitung statistik...\n";

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
            ];
        }

        // Urutkan berdasarkan skor tertinggi
        usort($statsPerPlayer, fn($a, $b) => $b['score'] <=> $a['score']);

        $this->broadcastAll([
            'type'  => 'GAME_OVER',
            'stats' => $statsPerPlayer,
        ]);

        echo "[STATS]  " . json_encode($statsPerPlayer, JSON_PRETTY_PRINT) . "\n";

        // Reset untuk game berikutnya
        $this->scheduleCall(5000, fn() => $this->resetGame());
    }

    private function resetGame(): void {
        $this->gameStarted  = false;
        $this->waitingForGo = false;
        $this->goSignalSent  = false;
        $this->currentRound = 0;
        $this->goTimerStart = null;

        // Reset skor & log pemain (koneksi tetap ada)
        foreach ($this->players as &$p) {
            $p['score']       = 0;
            $p['reactionLog'] = [];
            $p['penalties']   = 0;
        }
        unset($p);

        echo "[RESET]  Sesi direset. Menunggu game baru...\n";
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
    //  HELPER — BROADCAST & LOOKUP
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
            if ($p['conn'] === $conn) {
                return $p['username'];
            }
        }
        return null;
    }

    private function encode(array $data): string {
        return json_encode($data);
    }

    /**
     * Pseudo-async: jadwalkan callback setelah $ms milidetik.
     *
     * Ratchet berjalan di atas ReactPHP event loop. Cara paling simpel
     * tanpa inject $loop ke constructor adalah menggunakan
     * LoopInterface yang bisa diakses via LoopFactory.
     *
     * Jika ingin full async, inject ReactPHP\EventLoop\LoopInterface
     * ke constructor dan ganti usleep() di bawah dengan addTimer().
     *
     * Untuk saat ini kita gunakan addTimer via loop global React.
     */
    private function scheduleCall(int $ms, callable $callback): void {
        // Ambil event loop aktif (tersedia sejak ReactPHP 1.x)
        $loop = \React\EventLoop\Loop::get();
        $loop->addTimer($ms / 1000.0, $callback);
    }
}