<?php
// public/db_api.php
// Fokus Adam: Menyediakan data statistik untuk frontend (dashboard.html)

// Wajib agar browser tahu file ini mengirimkan data JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Mengizinkan akses dari front-end

// Panggil koneksi database (naik satu folder ke root, lalu masuk ke src)
require_once '../src/db.php';

// Menangkap perintah dari front-end (misal: db_api.php?action=leaderboard)
$action = $_GET['action'] ?? '';

try {
    if ($action === 'leaderboard') {
        // Query untuk mengambil klasemen top 10
        $stmt = $pdo->query("SELECT username, total_score, matches_played FROM users ORDER BY total_score DESC LIMIT 10");
        $data = $stmt->fetchAll();
        
        echo json_encode(['status' => 'success', 'data' => $data]);
    } 
    elseif ($action === 'stats') {
        // Query untuk Nilai Akademik (Rata-rata & Konsistensi)
        $user_id = $_GET['user_id'] ?? 1; // Default ambil ID 1 jika tidak dikirim
        
        $stmt = $pdo->prepare("
            SELECT 
                AVG(reaction_time_ms) as avg_time,
                MIN(reaction_time_ms) as fastest,
                MAX(reaction_time_ms) as slowest,
                (MAX(reaction_time_ms) - MIN(reaction_time_ms)) as consistency
            FROM match_logs 
            WHERE user_id = ? AND reaction_time_ms > 0
        ");
        $stmt->execute([$user_id]);
        $data = $stmt->fetch();
        
        echo json_encode(['status' => 'success', 'data' => $data]);
    } 
    else {
        // Jika perintah tidak dikenali
        echo json_encode(['status' => 'error', 'message' => 'Action tidak valid']);
    }

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Query Gagal: ' . $e->getMessage()]);
}
?>