<?php
// =============================================================================
// STATUS: WRAPPER (Prawo VIII domknięte 2026-07-27) — thin alias for external
// integrations that prefer GET over POST. Delegates to PayrollEngine::calculate,
// the same engine used by api/backoffice/hr/engine.php?action=payroll_report.
// =============================================================================
// SliceHub Enterprise — Payroll calculation (read-only)
// GET /api/staff/payroll.php?user_id=&period_type=month&period_offset=0
//
// user_id defaults to the authenticated session user when omitted.
// period_type: week | month | year (default month)
// period_offset: 0 = current period, 1 = previous full period, etc.
//
// Success → 200  { success: true, data: { payroll: { ... } } }
// =============================================================================

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
// Brak `Access-Control-Allow-Origin: *` — to dane płacowe, a konsument jest
// same-origin. Jeśli kiedyś stanie tu integracja zewnętrzna, wpisz konkretny
// origin z allowlisty, nigdy gwiazdkę.
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use GET.']);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/../../core/PayrollEngine.php';
    require_once __DIR__ . '/../../core/HrRoles.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $targetUser = trim($_GET['user_id'] ?? '');
    if ($targetUser === '') {
        $targetUser = (string)$user_id;
    }

    // AUTORYZACJA: auth_guard robi tylko uwierzytelnienie. Cudze wypłaty widzi
    // wyłącznie owner/manager/admin (ten sam gate co hrRequireManager w
    // api/backoffice/hr/engine.php). Własne dane — każdy zalogowany.
    if ((int)$targetUser !== $user_id && !HrRoles::isManager($pdo, $tenant_id, $user_id)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Forbidden: only owner/manager/admin can read another user payroll.',
        ]);
        exit;
    }

    $periodType   = strtolower(trim($_GET['period_type'] ?? 'month'));
    $periodOffset = (int)($_GET['period_offset'] ?? 0);

    $result = PayrollEngine::calculate(
        $pdo,
        $tenant_id,
        $targetUser,
        $periodType,
        $periodOffset
    );

    echo json_encode(
        ['success' => true, 'data' => ['payroll' => $result['payroll']]],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);

} catch (\RuntimeException $e) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    error_log('[Staff Payroll] PDOException: ' . $e->getMessage());

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[Staff Payroll] ' . $e->getMessage());
}

exit;
