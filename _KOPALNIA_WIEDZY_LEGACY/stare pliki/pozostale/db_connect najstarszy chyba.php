<?php
// 🛡️ SLICEHUB CORE - GŁÓWNY RDZEŃ BAZY I ZABEZPIECZEŃ (db_connect.php)
session_start();
header('Content-Type: application/json; charset=utf-8');

// 1. POŁĄCZENIE Z BAZĄ DANYCH
$host = 'localhost';
$db   = 'baza_slicehub';
$user = 'damian_admin';
$pass = 'Dammalq123123@';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (Exception $e) {
    die(json_encode(['success' => false, 'error' => 'Krytyczny błąd połączenia z bazą (Rdzeń).']));
}

// 2. TARCZA OCHRONNA
function require_login() {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Sesja wygasła. Zaloguj się ponownie.']);
        exit;
    }
}

function require_role($allowed_roles = []) {
    require_login();
    if (!empty($allowed_roles) && !in_array($_SESSION['user_role'] ?? '', $allowed_roles)) {
        echo json_encode(['success' => false, 'error' => 'Brak uprawnień. Ten obszar jest zablokowany.']);
        exit;
    }
}

// 3. ODBIÓR DANYCH (Uniwersalny Standard ASCII dla kluczy, UTF-8 dla wartości)
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? ($_POST['action'] ?? ($input['action'] ?? ''));

// Zmienne systemowe dostępne w każdym API po zalogowaniu
$tenant_id = $_SESSION['tenant_id'] ?? 1; 
$user_id   = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['user_role'] ?? null;
?>