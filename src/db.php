<?php
// src/db.php
// Fokus Adam: Koneksi PDO yang Aman & Dinamis (Railway & Local)

// Mengambil variabel dari sistem Railway. 
// Tanda "?:" berarti jika variabel Railway kosong, gunakan default (sebelah kanannya)
$host = getenv('mongodb.railway.internal')     ?: 'localhost';
$port = getenv('27017')     ?: '3306';
$db   = getenv('project-game') ?: 'project-game';
$user = getenv('mongo')     ?: 'root';
$pass = getenv('SsKhCOOKGFNicurxudWrOaRMrLgACupI') ?: '';
$charset = 'utf8mb4';

// Merangkai DSN (Data Source Name)
$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

// Opsi keamanan PDO untuk mencegah SQL Injection
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Langsung tampilkan error jika query salah
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Ambil data dalam bentuk array rapi
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Gunakan prepared statement asli MySQL
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    // JANGAN lakukan echo apapun di file ini. 
    // Biarkan kosong jika berhasil, agar tidak merusak format JSON di db_api.php nanti.
} catch (\PDOException $e) {
    // Matikan eksekusi dan tampilkan pesan jika gagal terkoneksi
    die(json_encode([
        'status' => 'error', 
        'message' => 'Koneksi Database Gagal: ' . $e->getMessage()
    ]));
}
?>