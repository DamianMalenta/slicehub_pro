<?php
// =============================================================================
// SliceHub Enterprise — KOR (Void / Correction) Endpoint
// POST /api/warehouse/correction.php
//
// Reverses a completed WZ document by returning the exact original quantities
// back into warehouse stock. Uses the WZ document as the single source of truth.
//
// Request body:
//   { order_id, reason }
//
// Success → 200  { success: true,  data: { kor_document: { ... } } }
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
    require_once __DIR__ . '/../../core/KorEngine.php';

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

    $orderId = trim($input['order_id'] ?? '');
    $reason  = trim($input['reason'] ?? '');

    if ($orderId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required field: order_id.']);
        exit;
    }
    if ($reason === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required field: reason.']);
        exit;
    }

    $result = KorEngine::processCorrection(
        $pdo,
        $tenant_id,
        $orderId,
        $reason,
        $user_id
    );

    echo json_encode([
        'success' => true,
        'data'    => ['kor_document' => $result['kor_document']],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    error_log('[KOR Correction] InvalidArgumentException: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);

} catch (\RuntimeException $e) {
    http_response_code(409);
    error_log('[KOR Correction] RuntimeException: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    error_log('[KOR Correction] PDOException: ' . $e->getMessage());

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[KOR Correction] ' . $e->getMessage());
}

exit;
