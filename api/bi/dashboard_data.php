<?php

declare(strict_types=1);

/**
 * BI — P&L dashboard data (thin HTTP wrapper over BiEngine::generateDashboard).
 *
 * GET  ?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD
 * POST JSON: { "date_from": "...", "date_to": "..." }
 *
 * RBAC: owner | admin | manager (read-only financial aggregate).
 */

@ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed.', 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

function biJsonOut(bool $ok, $data, ?string $msg = null, int $http = 200): void
{
    http_response_code($http);
    echo json_encode(
        ['success' => $ok, 'message' => $msg, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
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

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/../../core/BiEngine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $role = biLoadActorRole($pdo, $tenant_id, $user_id);
    if (!in_array($role, ['owner', 'admin', 'manager'], true)) {
        biJsonOut(false, null, 'Forbidden: BI dashboard requires owner, admin, or manager role.', 403);
    }

    $dateFrom = '';
    $dateTo = '';
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
        $dateTo = trim((string) ($_GET['date_to'] ?? ''));
    } else {
        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        if (is_array($body)) {
            $dateFrom = trim((string) ($body['date_from'] ?? ''));
            $dateTo = trim((string) ($body['date_to'] ?? ''));
        }
    }

    $dash = BiEngine::generateDashboard($pdo, $tenant_id, $dateFrom, $dateTo);
    biJsonOut(true, $dash, null, 200);
} catch (InvalidArgumentException $e) {
    biJsonOut(false, null, $e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[bi/dashboard_data] ' . $e->getMessage());
    biJsonOut(false, null, 'Internal server error.', 500);
}
