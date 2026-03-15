<?php
// src/db.php

function getDB(): ?PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    // Nama env var resmi dari Railway MySQL
    $host    = getenv('MYSQLHOST')     ?: 'localhost';
    $port    = getenv('MYSQLPORT')     ?: '3306';
    $db      = getenv('MYSQLDATABASE') ?: 'railway';
    $user    = getenv('MYSQLUSER')     ?: 'root';
    $pass    = getenv('MYSQLPASSWORD') ?: '';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        echo "[DB]     Koneksi database berhasil!\n";
        return $pdo;

    } catch (\PDOException $e) {
        // TIDAK pakai die() — server tetap hidup meski DB down
        echo "[DB ERR] Koneksi gagal: {$e->getMessage()}\n";
        return null;
    }
}
