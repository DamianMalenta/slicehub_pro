<?php
// =============================================================================
// SliceHub Enterprise — PZ (Goods Receipt) Endpoint
// POST /api/warehouse/receipt.php
//
// Receives a supplier delivery payload, resolves external product names to
// internal SKUs, computes AVCO, upserts stock, and returns the full PZ
// document matching the Section 19 Data Blueprint.
//
// Request body:
//   { warehouse_id, supplier_name, supplier_invoice, lines: [{
//       external_name?, resolved_sku?, quantity, unit_net_cost, vat_rate? }] }
//
// Success → 200  { success: true,  data: { pz_document: { ... } } }
// Mapping → 400  { success: false, message, data: { unmapped_product } }
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
    require_once __DIR__ . '/../../core/PzEngine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    // --- Parse input ---
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

    // --- Execute engine ---
    $result = PzEngine::processReceipt(
        $pdo,
        $tenant_id,
        $warehouseId,
        $input,
        (string)$user_id
    );

    echo json_encode([
        'success' => true,
        'data'    => ['pz_document' => $result['pz_document']],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (PzMappingException $e) {
    http_response_code(400);
    error_log('[PZ Receipt] PzMappingException: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error.',
        'data'    => ['unmapped_product' => $e->getExternalName()],
    ], JSON_UNESCAPED_UNICODE);

} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    error_log('[PZ Receipt] InvalidArgumentException: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    error_log('[PZ Receipt] PDOException: ' . $e->getMessage());

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[PZ Receipt] ' . $e->getMessage());
}

exit;
