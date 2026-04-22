<?php
// =============================================================================
// SliceHub Enterprise — MM (Inter-Warehouse Transfer) Endpoint
// POST /api/warehouse/transfer.php
//
// Moves stock from one warehouse to another within the same tenant.
// Source AVCO carries over to target with weighted-average recalculation.
//
// Request body:
//   { source_warehouse_id, target_warehouse_id,
//     lines: [{ sku, quantity }] }
//
// Success → 200  { success: true,  data: { mm_document: { ... } } }
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
    require_once __DIR__ . '/../../core/MmEngine.php';

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

    $sourceWhId = trim($input['source_warehouse_id'] ?? '');
    $targetWhId = trim($input['target_warehouse_id'] ?? '');

    if ($sourceWhId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required field: source_warehouse_id.']);
        exit;
    }
    if ($targetWhId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required field: target_warehouse_id.']);
        exit;
    }
    if (empty($input['lines']) || !is_array($input['lines'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing or empty: lines.']);
        exit;
    }

    $result = MmEngine::processTransfer(
        $pdo,
        $tenant_id,
        $sourceWhId,
        $targetWhId,
        $input['lines'],
        $user_id
    );

    echo json_encode([
        'success' => true,
        'data'    => ['mm_document' => $result['mm_document']],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    error_log('[MM Transfer] InvalidArgumentException: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);

} catch (\RuntimeException $e) {
    http_response_code(409);
    error_log('[MM Transfer] RuntimeException: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    error_log('[MM Transfer] PDOException: ' . $e->getMessage());

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[MM Transfer] ' . $e->getMessage());
}

exit;
