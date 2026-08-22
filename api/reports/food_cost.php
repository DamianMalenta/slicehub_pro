<?php
// =============================================================================
// STATUS: DOMKNIĘTE 2026-07-29 (Faza D, Prawo VIII).
// Consumer: modules/backoffice/food_cost/ (Food Cost Report panel).
//   GET api/reports/food_cost.php?item_sku=...&warehouse_id=...
// Pickera zasilają: api/warehouse/warehouse_list.php + api/backoffice/api_menu_studio.php#get_menu_tree.
// Per-item food cost + margin breakdown with per-channel analysis. Backed by FoodCostEngine + AVCO.
// =============================================================================
// SliceHub Enterprise — Food Cost & Margin Report Endpoint
// GET /api/reports/food_cost.php?item_sku=...&warehouse_id=...
//
// Read-only endpoint that returns theoretical food cost, ingredient breakdown,
// modifier cost analysis, and per-channel margin status for a single menu item.
//
// Query params:
//   item_sku      (required) — ascii_key of the menu item
//   warehouse_id  (required) — warehouse for AVCO lookup
//
// Success → 200  { success: true, data: { item_sku, food_cost_analysis: {...} } }
// Error   → 4xx  { success: false, message }
// =============================================================================

declare(strict_types=1);

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
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use GET.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/../../core/FoodCostEngine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $itemSku     = trim($_GET['item_sku'] ?? '');
    $warehouseId = trim($_GET['warehouse_id'] ?? '');

    if ($itemSku === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required query parameter: item_sku.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($warehouseId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required query parameter: warehouse_id.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $result = FoodCostEngine::calculateForSku(
        $pdo,
        $tenant_id,
        $warehouseId,
        $itemSku
    );

    echo json_encode([
        'success' => true,
        'data'    => [
            'item_sku'           => $result['item_sku'],
            'food_cost_analysis' => $result['food_cost_analysis'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (\RuntimeException $e) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log('[Food Cost Report] PDOException: ' . $e->getMessage());

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log('[Food Cost Report] ' . $e->getMessage());
}

exit;
