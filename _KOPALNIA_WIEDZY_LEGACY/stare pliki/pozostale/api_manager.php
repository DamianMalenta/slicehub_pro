<?php
// 🛡️ SLICEHUB MANAGER API - ZMODULARYZOWANY
require_once 'db_connect.php';

// Dostęp tylko dla kadry zarządzającej
require_role(['manager', 'admin', 'owner']);

// 🟢 1. POBIERANIE ZESPOŁU
if ($action === 'get_team') {
    // Używamy is_active = 1 zgodnie z nową bazą danych
    $stmt = $pdo->prepare("SELECT id, username, first_name, last_name, position, role FROM sh_users WHERE tenant_id = ? AND is_active = 1 AND role != 'owner'");
    $stmt->execute([$tenant_id]);
    echo json_encode(['success' => true, 'team' => $stmt->fetchAll()]);
    exit;
}

// 🟢 2. ZAPISYWANIE WNIOSKÓW FINANSOWYCH (Premie/Zaliczki/Kary)
if ($action === 'submit_finance') {
    $target_id = (int)($input['target_id'] ?? $_POST['target_id'] ?? 0);
    $type = trim($input['type'] ?? $_POST['type'] ?? '');
    $amount = (float)($input['amount'] ?? $_POST['amount'] ?? 0);
    $desc = trim($input['desc'] ?? $_POST['desc'] ?? '');
    $is_paid = (int)($input['is_paid'] ?? $_POST['is_paid'] ?? 0);

    if ($target_id === 0 || $amount <= 0 || empty($type)) {
        echo json_encode(['success' => false, 'error' => 'Brakujące lub nieprawidłowe dane wniosku.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO sh_finance_requests (tenant_id, target_user_id, created_by_id, type, amount, description, is_paid_cash) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tenant_id, $target_id, $user_id, $type, $amount, $desc, $is_paid]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Błąd zapisu wniosku: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Nieznana akcja API.']);
?>