<?php
// =============================================================================
// SliceHub Enterprise — INW (Physical Inventory Count) Endpoint
// POST /api/warehouse/inventory.php
//
// Processes a physical stock count, computes variances against system quantities,
// determines approval tier, and generates compensating RW/PW documents when
// auto-approved.
//
// Request body:
//   { warehouse_id, lines: [{ sku, counted_qty }],
//     tolerances?: { auto_approve_pct, critical_pct } }
//
// Success → 200  { success: true,  data: { inw_document: { ... } } }
// Error   → 4xx  { success: false, message }
// =============================================================================

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/../../core/InwEngine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
        exit;
    }

    $warehouseId = trim($input['warehouse_id'] ?? '');
    if ($warehouseId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required field: warehouse_id.']);
        exit;
    }

    if (empty($input['lines']) || !is_array($input['lines'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing or empty: lines.']);
        exit;
    }

    $tolerances = [
        'auto_approve_pct' => (float)($input['tolerances']['auto_approve_pct'] ?? 2.0),
        'critical_pct'     => (float)($input['tolerances']['critical_pct'] ?? 10.0),
    ];

    $result = InwEngine::processCount(
        $pdo,
        $tenant_id,
        $warehouseId,
        $input['lines'],
        $tolerances,
        $user_id
    );

    echo json_encode([
        'success' => true,
        'data'    => ['inw_document' => $result['inw_document']],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    error_log('[INW Inventory] InvalidArgumentException: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    error_log('[INW Inventory] PDOException: ' . $e->getMessage());

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[INW Inventory] ' . $e->getMessage());
}

exit;
