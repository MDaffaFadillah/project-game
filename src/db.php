<?php
// src/db.php

function getDB(): ?PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    // Railway MySQL env vars — cek semua kemungkinan nama
    $host = getenv('MYSQLHOST')     ?: getenv('MYSQL_HOST')     ?: 'localhost';
    $port = getenv('MYSQLPORT')     ?: getenv('MYSQL_PORT')     ?: '3306';
    $db   = getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE') ?: getenv('MYSQL_DB') ?: 'railway';
    $user = getenv('MYSQLUSER')     ?: getenv('MYSQL_USER')     ?: 'root';
    $pass = getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD') ?: '';

    // Debug: tampilkan env yang dipakai (tanpa password)
    echo "[DB]     Mencoba konek: host={$host} port={$port} db={$db} user={$user}\n";

    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        echo "[DB]     ✅ Koneksi database berhasil! ({$db}@{$host})\n";
        return $pdo;

    } catch (\PDOException $e) {
        echo "[DB ERR] ❌ Koneksi gagal: {$e->getMessage()}\n";
        echo "[DB ERR] DSN yang dicoba: mysql:host={$host};port={$port};dbname={$db}\n";
        return null;
    }
}