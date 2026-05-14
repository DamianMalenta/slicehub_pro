<?php

declare(strict_types=1);

/**
 * BI — cienki wrapper JSON dla dashboardu P&L (BiEngine).
 * GET ?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
 * RBAC: owner, admin.
 */

@ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function posResponse(bool $ok, $data = null, ?string $msg = null): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function biLoadActorRole(PDO $pdo, int $tenantId, int $userId): string
{
    $st = $pdo->prepare(
        'SELECT LOWER(role) FROM sh_users WHERE id = :uid AND tenant_id = :tid AND is_deleted = 0 LIMIT 1'
    );
    $st->execute([':uid' => $userId, ':tid' => $tenantId]);
    $r = $st->fetchColumn();

    return is_string($r) ? $r : '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    posResponse(false, null, 'Method Not Allowed. Use GET.');
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/../../core/BiEngine.php';

    /** @var PDO $pdo */
    /** @var int $tenant_id */
    /** @var int $user_id */

    $role = biLoadActorRole($pdo, $tenant_id, $user_id);
    if (!in_array($role, ['owner', 'admin'], true)) {
        http_response_code(403);
        posResponse(false, null, 'Wymagana rola: owner lub admin.');
    }

    $start = trim((string) ($_GET['start_date'] ?? ''));
    $end   = trim((string) ($_GET['end_date'] ?? ''));
    if ($start === '' || $end === '') {
        http_response_code(400);
        posResponse(false, null, 'Parametry start_date i end_date (YYYY-MM-DD) są wymagane.');
    }

    $data = BiEngine::generateDashboard($pdo, $tenant_id, $start, $end);
    posResponse(true, $data);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    posResponse(false, null, $e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[bi/dashboard_data] ' . $e->getMessage());
    posResponse(false, null, 'Internal error.');
}
