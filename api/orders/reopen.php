<?php
declare(strict_types=1);

// =============================================================================
// SliceHub Enterprise — Order Reopen (RFC-001 Faza 3)
// POST /api/orders/reopen.php
//
// Bezpieczne ponowne otwarcie zamówienia: completed → pending (tylko owner/admin).
// Wywołuje core/OrderReopenEngine::reopen.
//
// Request:  { action: "reopen", order_id, reason }
// Response: { order_id, old_status, new_status, wh_kor_document }
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
    echo json_encode([
        'success' => false,
        'message' => 'Method Not Allowed. Use POST.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function reopenOut(bool $ok, $data = null, ?string $msg = null, int $http = 200): void
{
    http_response_code($http);
    echo json_encode(
        ['success' => $ok, 'data' => $data, 'message' => $msg],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function reopenLoadRole(PDO $pdo, int $tid, int $uid): string
{
    $st = $pdo->prepare(
        'SELECT LOWER(role) FROM sh_users WHERE id = :uid AND tenant_id = :tid AND is_deleted = 0 LIMIT 1'
    );
    $st->execute([':uid' => $uid, ':tid' => $tid]);
    $r = $st->fetchColumn();
    return is_string($r) ? $r : '';
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/../../core/OrderReopenEngine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    // ── RBAC: owner / admin ──────────────────────────────────────────
    $role = reopenLoadRole($pdo, $tenant_id, $user_id);
    if (!in_array($role, ['owner', 'admin'], true)) {
        reopenOut(false, null, 'Forbidden: wymagana rola owner/admin.', 403);
    }

    // ── Parse input ───────────────────────────────────────────────────
    $raw   = file_get_contents('php://input') ?: '{}';
    $input = json_decode($raw, true) ?? [];
    $action = trim((string)($input['action'] ?? ''));

    if ($action !== 'reopen') {
        reopenOut(false, null, 'Nieznana akcja. Obsługiwana: "reopen".', 400);
    }

    $orderId = trim((string)($input['order_id'] ?? ''));
    if ($orderId === '') {
        reopenOut(false, null, 'order_id jest wymagane.', 400);
    }

    $reason = trim((string)($input['reason'] ?? ''));
    if (strlen($reason) < 10) {
        reopenOut(false, null, 'Powód jest wymagany (min. 10 znaków).', 400);
    }

    // ── Execute reopen ────────────────────────────────────────────────
    $result = OrderReopenEngine::reopen($pdo, $tenant_id, $orderId, $user_id, $reason);

    if (!$result['success']) {
        reopenOut(false, null, $result['message'] ?? 'Reopen failed.', 409);
    }

    reopenOut(true, [
        'order_id'        => $orderId,
        'old_status'      => $result['old_status'] ?? 'completed',
        'new_status'      => $result['new_status'] ?? 'pending',
        'wh_kor_document' => $result['wh_kor_document'] ?? null,
        'audit_logged'    => true,
    ]);
} catch (PDOException $e) {
    error_log('[orders/reopen] PDOException: ' . $e->getMessage());
    reopenOut(false, null, 'Błąd bazy danych.', 500);
} catch (Throwable $e) {
    error_log('[orders/reopen] ' . $e->getMessage());
    reopenOut(false, null, 'Błąd serwera.', 500);
}
