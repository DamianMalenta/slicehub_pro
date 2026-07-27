<?php
// =============================================================================
// STATUS: WRAPPER (Prawo VIII domknięte 2026-07-27) — thin alias for external
// integrations that prefer GET over POST. Delegates to TeamPayrollEngine::getAggregate,
// the same engine used by api/backoffice/hr/engine.php?action=payroll_report.
// =============================================================================
// SliceHub Enterprise — Boss dashboard: team aggregate payroll (GET)
// /api/dashboard/team_payroll.php?period_type=month&period_offset=0
//
// Success → 200  { success: true, data: { team_payroll: { ... } } }
// =============================================================================

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
// Brak `Access-Control-Allow-Origin: *` — agregat płac całego zespołu nie ma
// prawa być czytelny cross-origin. Konsument jest same-origin.
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
    require_once __DIR__ . '/../../core/TeamPayrollEngine.php';
    require_once __DIR__ . '/../../core/HrRoles.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    // AUTORYZACJA: auth_guard robi tylko uwierzytelnienie. Agregat płac całego
    // zespołu = dane wrażliwe → wyłącznie owner/manager/admin (ten sam gate co
    // hrRequireManager w api/backoffice/hr/engine.php#payroll_report).
    if (!HrRoles::isManager($pdo, $tenant_id, $user_id)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Forbidden: only owner/manager/admin can read team payroll.',
        ]);
        exit;
    }

    $periodType   = strtolower(trim($_GET['period_type'] ?? 'month'));
    $periodOffset = (int)($_GET['period_offset'] ?? 0);

    $payload = TeamPayrollEngine::getAggregate(
        $pdo,
        $tenant_id,
        $periodType,
        $periodOffset
    );

    echo json_encode(
        ['success' => true, 'data' => $payload],
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
    error_log('[Team Payroll] PDOException: ' . $e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[Team Payroll] ' . $e->getMessage());
}

exit;
