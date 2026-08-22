<?php
// =============================================================================
// SliceHub Enterprise — Cart Preview Endpoint
// POST /api/cart/calculate.php
//
// Thin HTTP wrapper around CartEngine. Returns a fully authoritative,
// grosze-accurate cart snapshot without any state mutation.
// =============================================================================

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
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use POST.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/CartEngine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $result = CartEngine::calculate($pdo, $tenant_id, $input);

    echo json_encode(['success' => true, 'data' => $result['response']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (CartEngineException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log('[CartEngine] PDOException: ' . $e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log('[CartEngine] ' . $e->getMessage());
}

exit;
