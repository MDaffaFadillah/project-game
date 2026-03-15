<?php
// public/db_api.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: GET, POST");

require_once __DIR__ . '/../src/db.php';

$action = $_GET['action'] ?? '';
$db = getDB();

// Cek apakah koneksi database berhasil
if (!$db) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed'
    ]);
    exit;
}

switch ($action) {
    // 1. Ambil Leaderboard
    case 'get_leaderboard':
        try {
            $stmt = $db->query("SELECT username, score, avg_reaction_time, best_time 
                                FROM players_stats 
                                ORDER BY score DESC LIMIT 10");
            $data = $stmt->fetchAll();
            echo json_encode(['status' => 'success', 'data' => $data]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    // 2. Ambil Riwayat Ronde Terakhir
    case 'get_recent_logs':
        try {
            $stmt = $db->query("SELECT * FROM round_logs ORDER BY created_at DESC LIMIT 50");
            $data = $stmt->fetchAll();
            echo json_encode(['status' => 'success', 'data' => $data]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    // 3. Jika action tidak ditemukan
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Action not found. Use get_leaderboard or get_recent_logs'
        ]);
        break;
}
