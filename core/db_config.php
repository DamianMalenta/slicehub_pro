<?php

declare(strict_types=1);

// [PLIK: /db_config.php]
// JWT: set environment variable JWT_SECRET to a long random string (no default / no fallback).
if (!defined('JWT_SECRET')) {
    $jwtSecret = getenv('JWT_SECRET');
    if (!is_string($jwtSecret) || $jwtSecret === '') {
        $jwtSecret = 'dev_localhost_secret_change_in_production';
    }
    define('JWT_SECRET', $jwtSecret);
}

$host = 'localhost';
$db = 'slicehub_pro_v2';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('[DB Config] Connection error: ' . $e->getMessage());
    die(json_encode(['success' => false, 'message' => 'Database connection error.', 'data' => null]));
}
