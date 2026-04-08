<?php
// [PLIK: /db_config.php]
$host = 'localhost';
$db = 'slicehub_pro_v2';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(["status" => "error", "message" => "Błąd krytyczny połączenia z bazą: " . $e->getMessage()]));
}
?>