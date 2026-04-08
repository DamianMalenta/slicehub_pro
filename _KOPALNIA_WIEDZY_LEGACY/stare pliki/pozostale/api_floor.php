<?php
// 🛡️ SLICEHUB FLOOR API - V3.1 CZYSTY JSON
require_once 'db_connect.php';

function sendResponse($status, $payload = [], $errorMsg = null) {
    echo json_encode(['status' => $status, 'payload' => $payload, 'error' => $errorMsg]);
    exit;
}

require_role(['admin', 'manager', 'owner']);
$action = trim($action ?? '');

if ($action === 'get_floor_init') {
    $stmt = $pdo->prepare("SELECT id, table_number, status, qr_key FROM sh_tables WHERE tenant_id = ? ORDER BY table_number ASC");
    $stmt->execute([$tenant_id]);
    sendResponse('success', ['tables' => $stmt->fetchAll()]);
}

if ($action === 'save_table') {
    $table_id = (int)($input['table_id'] ?? 0);
    $table_number = trim($input['table_number'] ?? '');

    if (empty($table_number)) sendResponse('error', null, 'Podaj numer stolika.');

    if ($table_id > 0) {
        $stmt = $pdo->prepare("UPDATE sh_tables SET table_number = ? WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$table_number, $table_id, $tenant_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO sh_tables (tenant_id, table_number, status, qr_key) VALUES (?, ?, 'free', '')");
        $stmt->execute([$tenant_id, $table_number]);
        $table_id = $pdo->lastInsertId();
    }
    sendResponse('success', ['table_id' => $table_id]);
}

if ($action === 'generate_qr') {
    $table_id = (int)($input['table_id'] ?? 0);
    // Generujemy unikalny, bezpieczny klucz dla stolika
    $qr_key = strtoupper("SH-QR-" . bin2hex(random_bytes(4)));
    
    $stmt = $pdo->prepare("UPDATE sh_tables SET qr_key = ? WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$qr_key, $table_id, $tenant_id]);
    sendResponse('success', ['qr_key' => $qr_key]);
}

if ($action === 'delete_table') {
    $table_id = (int)($input['table_id'] ?? 0);
    $stmt = $pdo->prepare("DELETE FROM sh_tables WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$table_id, $tenant_id]);
    sendResponse('success', []);
}

sendResponse('error', null, "Nieznana akcja: [" . $action . "]");