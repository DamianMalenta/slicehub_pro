<?php

declare(strict_types=1);

@ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * SliceHub — Słownik kategorii kosztów OPEX (KSeF linie EXPENSE).
 *
 * /api/procurement/expense_categories.php
 *
 * Akcje: list | create | update | delete
 * RBAC: owner / admin / manager (jak inbox list).
 */

function ecResponse(bool $ok, $data = null, ?string $msg = null, ?string $code = null): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $out = ['success' => $ok, 'data' => $data, 'message' => $msg];
    if ($code !== null) {
        $out['code'] = $code;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

function ecFail(int $httpCode, string $code, ?string $msg = null): void
{
    http_response_code($httpCode);
    ecResponse(false, null, $msg ?? $code, $code);
}

function ecLoadActorRole(PDO $pdo, int $tenantId, int $userId): string
{
    $st = $pdo->prepare(
        'SELECT LOWER(role) FROM sh_users WHERE id = :uid AND tenant_id = :tid AND is_deleted = 0 LIMIT 1'
    );
    $st->execute([':uid' => $userId, ':tid' => $tenantId]);
    $r = $st->fetchColumn();

    return is_string($r) ? $r : '';
}

function ecRequireRole(string $actorRole, array $allowed): void
{
    if (!in_array($actorRole, $allowed, true)) {
        ecFail(403, 'FORBIDDEN',
            'Wymagana rola: ' . implode(' / ', $allowed) . '. Aktualna: ' . ($actorRole ?: 'unknown') . '.');
    }
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';

    /** @var PDO $pdo */
    /** @var int $tenant_id */
    /** @var int $user_id */

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ecFail(405, 'METHOD_NOT_ALLOWED', 'Only POST is allowed.');
    }

    $raw = file_get_contents('php://input') ?: '{}';
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        ecFail(400, 'INVALID_JSON', 'Request body must be a JSON object.');
    }

    $action = trim((string) ($input['action'] ?? ''));
    if ($action === '') {
        ecFail(400, 'ACTION_REQUIRED', 'Missing "action" field.');
    }

    $actorRole = ecLoadActorRole($pdo, $tenant_id, $user_id);

    switch ($action) {
        case 'list': {
            ecRequireRole($actorRole, ['owner', 'admin', 'manager']);
            $st = $pdo->prepare(
                "SELECT id, tenant_id, name, is_system, is_active, is_deleted, created_at
                   FROM sh_expense_categories
                  WHERE tenant_id = :tid AND is_deleted = 0
                  ORDER BY is_system DESC, name ASC"
            );
            $st->execute([':tid' => $tenant_id]);
            ecResponse(true, ['categories' => $st->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        case 'create': {
            ecRequireRole($actorRole, ['owner', 'admin', 'manager']);
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '' || strlen($name) > 128) {
                ecFail(400, 'INVALID_NAME', 'Nazwa kategorii jest wymagana (max 128 znaków).');
            }
            $pdo->prepare(
                "INSERT INTO sh_expense_categories (tenant_id, name, is_system, is_active, is_deleted)
                 VALUES (:tid, :name, 0, 1, 0)"
            )->execute([':tid' => $tenant_id, ':name' => $name]);
            $id = (int) $pdo->lastInsertId();
            ecResponse(true, ['id' => $id, 'name' => $name], 'Kategoria utworzona.');
            break;
        }

        case 'update': {
            ecRequireRole($actorRole, ['owner', 'admin', 'manager']);
            $id = (int) ($input['id'] ?? 0);
            $name = trim((string) ($input['name'] ?? ''));
            if ($id <= 0 || $name === '' || strlen($name) > 128) {
                ecFail(400, 'INVALID_INPUT', 'Wymagane: id, name.');
            }
            $st = $pdo->prepare(
                "SELECT id FROM sh_expense_categories
                  WHERE id = :id AND tenant_id = :tid AND is_deleted = 0 LIMIT 1"
            );
            $st->execute([':id' => $id, ':tid' => $tenant_id]);
            if (!$st->fetchColumn()) {
                ecFail(404, 'NOT_FOUND');
            }
            $pdo->prepare(
                "UPDATE sh_expense_categories SET name = :name WHERE id = :id AND tenant_id = :tid"
            )->execute([':name' => $name, ':id' => $id, ':tid' => $tenant_id]);
            ecResponse(true, ['id' => $id, 'name' => $name], 'Kategoria zaktualizowana.');
            break;
        }

        case 'delete': {
            ecRequireRole($actorRole, ['owner', 'admin', 'manager']);
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                ecFail(400, 'INVALID_ID');
            }
            $st = $pdo->prepare(
                "SELECT is_system FROM sh_expense_categories
                  WHERE id = :id AND tenant_id = :tid AND is_deleted = 0 LIMIT 1"
            );
            $st->execute([':id' => $id, ':tid' => $tenant_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                ecFail(404, 'NOT_FOUND');
            }
            if ((int) ($row['is_system'] ?? 0) === 1) {
                ecFail(403, 'SYSTEM_CATEGORY_PROTECTED', 'Nie można usunąć kategorii systemowej.');
            }
            $pdo->prepare(
                "UPDATE sh_expense_categories SET is_deleted = 1, is_active = 0
                  WHERE id = :id AND tenant_id = :tid"
            )->execute([':id' => $id, ':tid' => $tenant_id]);
            ecResponse(true, ['id' => $id], 'Kategoria usunięta (soft-delete).');
            break;
        }

        default:
            ecFail(400, 'UNKNOWN_ACTION', "Nieznana akcja: {$action}");
    }
} catch (\Throwable $e) {
    error_log('[procurement/expense_categories] FATAL: ' . $e->getMessage());
    ecFail(500, 'INTERNAL_ERROR', 'Błąd serwera: ' . $e->getMessage());
}
