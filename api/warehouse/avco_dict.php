<?php

declare(strict_types=1);

/**
 * GET /api/warehouse/avco_dict.php?warehouse_id=MAIN
 * Płaski słownik { sku => current_avco_price } dla kalkulatorów (Margin Guardian).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed.', 'data' => null]);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $warehouseId = trim((string)($_GET['warehouse_id'] ?? 'MAIN'));
    if ($warehouseId === '') {
        $warehouseId = 'MAIN';
    }

    $stmt = $pdo->prepare("
        SELECT sku, current_avco_price
        FROM wh_stock
        WHERE tenant_id = :tid
          AND warehouse_id = :wid
          AND current_avco_price > 0
    ");
    $stmt->execute([':tid' => $tenant_id, ':wid' => $warehouseId]);

    $dict = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dict[$row['sku']] = (float) $row['current_avco_price'];
    }

    echo json_encode([
        'success' => true,
        'message' => 'Słownik AVCO pobrany.',
        'data'    => empty($dict) ? new \stdClass() : $dict,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[warehouse/avco_dict] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error.',
        'data'    => null,
    ], JSON_UNESCAPED_UNICODE);
}
