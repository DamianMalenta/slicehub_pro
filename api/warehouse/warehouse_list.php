<?php

declare(strict_types=1);

/**
 * GET /api/warehouse/warehouse_list.php
 * Zwraca listę magazynów (DISTINCT warehouse_id z wh_stock + seed MAIN).
 * { success, data: [ {warehouse_id, item_count, total_value} ] }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed.', 'data' => null]);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';

    if (!isset($pdo)) throw new RuntimeException('Database connection unavailable.');

    $stmt = $pdo->prepare("
        SELECT
            warehouse_id,
            COUNT(DISTINCT sku) AS item_count,
            ROUND(SUM(quantity * current_avco_price), 2) AS total_value
        FROM wh_stock
        WHERE tenant_id = :tid
        GROUP BY warehouse_id
        ORDER BY warehouse_id ASC
    ");
    $stmt->execute([':tid' => $tenant_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $rows = [['warehouse_id' => 'MAIN', 'item_count' => 0, 'total_value' => '0.00']];
    }

    echo json_encode(['success' => true, 'message' => 'OK', 'data' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[warehouse/warehouse_list] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error.', 'data' => null], JSON_UNESCAPED_UNICODE);
}
