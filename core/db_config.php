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

// DB credentials: czytamy ENV-y zanim spadniemy na XAMPP-owe defaulty.
// Hosting (uti.pl, inne) → ustaw zmienne środowiskowe SLICEHUB_DB_HOST/NAME/USER/PASS
// w panelu hostingu lub w pliku PHP-FPM env. Lokalnie (XAMPP) zostaw bez ustawień
// — wpadnie na 'localhost' / 'slicehub_pro_v2' / 'root' / '' i będzie działać jak dotąd.
$envHost = getenv('SLICEHUB_DB_HOST');
$envDb   = getenv('SLICEHUB_DB_NAME');
$envUser = getenv('SLICEHUB_DB_USER');
$envPass = getenv('SLICEHUB_DB_PASS');

$host = (is_string($envHost) && $envHost !== '') ? $envHost : 'localhost';
$db   = (is_string($envDb)   && $envDb   !== '') ? $envDb   : 'slicehub_pro_v2';
$user = (is_string($envUser) && $envUser !== '') ? $envUser : 'root';
$pass = is_string($envPass) ? $envPass : '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('[DB Config] Connection error: ' . $e->getMessage());
    die(json_encode(['success' => false, 'message' => 'Database connection error.', 'data' => null]));
}
