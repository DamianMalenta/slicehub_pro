<?php

declare(strict_types=1);

/**
 * GET /api/warehouse/stock_list.php?warehouse_id=MAIN
 * Zwraca słownik surowców (sys_items) ze stanami wh_stock dla jednego magazynu.
 * Format odpowiedzi: { success, message, data: array<row> } — zgodny z ApiClient.
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
        SELECT
            s.sku,
            s.name,
            s.base_unit,
            IFNULL(w.quantity, 0) AS quantity,
            IFNULL(w.unit_net_cost, 0) AS unit_net_cost,
            IFNULL(w.current_avco_price, 0) AS current_avco_price
        FROM sys_items s
        LEFT JOIN wh_stock w
            ON w.sku = s.sku
           AND w.tenant_id = s.tenant_id
           AND w.warehouse_id = :wid
        WHERE s.tenant_id = :tid
        ORDER BY s.name ASC
    ");
    $stmt->execute([':tid' => $tenant_id, ':wid' => $warehouseId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => 'Stan magazynu pobrany pomyślnie.',
        'data'    => $rows ?: [],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[warehouse/stock_list] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error.',
        'data'    => null,
    ], JSON_UNESCAPED_UNICODE);
}
