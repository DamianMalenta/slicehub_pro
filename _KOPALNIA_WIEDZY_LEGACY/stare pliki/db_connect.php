<?php
// 🛡️ SLICEHUB CORE - RDZEŃ V3.1
session_start();
header('Content-Type: application/json; charset=utf-8');

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
    die(json_encode(['status' => 'error', 'error' => 'Błąd bazy danych.']));
}

// TA FUNKCJA MUSI TU BYĆ - TO JEJ SZUKA SERWER
function require_login($type = 'system') {
    if ($type === 'kiosk') {
        if (!isset($_SESSION['kiosk_user_id'])) {
            echo json_encode(['status' => 'error', 'error' => 'Kiosk: Sesja wygasła.']);
            exit;
        }
    } else {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['status' => 'error', 'error' => 'System: Sesja wygasła.']);
            exit;
        }
    }
}

function require_role($allowed_roles = []) {
    require_login('system'); // POS zawsze wymaga sesji systemowej
    if (!empty($allowed_roles) && !in_array($_SESSION['user_role'] ?? '', $allowed_roles)) {
        echo json_encode(['status' => 'error', 'error' => 'Brak uprawnień do tego modułu.']);
        exit;
    }
}

// Uniwersalny odbiór danych
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($_GET['action'] ?? ($_POST['action'] ?? ($input['action'] ?? '')));
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$user_id = $_SESSION['user_id'] ?? null;