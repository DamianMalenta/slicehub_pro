<?php
// 🛡️ SLICEHUB MAPPING API - V3.1 CZYSTY JSON
require_once 'db_connect.php';

function sendResponse($status, $payload = [], $errorMsg = null) {
    echo json_encode(['status' => $status, 'payload' => $payload, 'error' => $errorMsg]);
    exit;
}

require_role(['admin', 'manager', 'owner']);
$action = trim($action ?? '');

if ($action === 'get_mapping_data') {
    $products = $pdo->prepare("SELECT id, name FROM sh_products WHERE tenant_id = ? ORDER BY name ASC");
    $products->execute([$tenant_id]);
    
    $mappings = $pdo->prepare("
        SELECT m.id, m.external_name, p.name AS internal_name 
        FROM sh_product_mapping m
        JOIN sh_products p ON m.product_id = p.id
        WHERE m.tenant_id = ? ORDER BY m.id DESC
    ");
    $mappings->execute([$tenant_id]);
    
    sendResponse('success', [
        'products' => $products->fetchAll(), 
        'mappings' => $mappings->fetchAll()
    ]);
}

if ($action === 'add_mapping') {
    $external_name = trim($input['external_name'] ?? '');
    $product_id = (int)($input['internal_product_id'] ?? 0);

    if (empty($external_name) || $product_id === 0) {
        sendResponse('error', null, 'Brakuje danych do mapowania.');
    }

    $stmt = $pdo->prepare("INSERT INTO sh_product_mapping (tenant_id, external_name, product_id) VALUES (?, ?, ?)");
    $stmt->execute([$tenant_id, $external_name, $product_id]);
    
    sendResponse('success', ['inserted_id' => $pdo->lastInsertId()]);
}

if ($action === 'delete_mapping') {
    $mapping_id = (int)($input['mapping_id'] ?? 0);
    $stmt = $pdo->prepare("DELETE FROM sh_product_mapping WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$mapping_id, $tenant_id]);
    
    sendResponse('success', ['deleted_id' => $mapping_id]);
}

sendResponse('error', null, "Nieznana akcja: [" . $action . "]");