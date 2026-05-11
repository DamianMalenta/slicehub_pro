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
 * SliceHub — Procurement / AutoScan Suggest endpoint
 *
 * /api/procurement/suggest.php
 *
 * AKCJE (pole `action` w JSON body):
 *   - suggest         — match jednej linii (input: external_name, qty?, unit?)
 *   - suggest_bulk    — match wielu linii (input: lines: [{external_name, qty?, unit?}, ...])
 *   - learn           — zapis mapping external_name → sku do sh_product_mapping
 *   - learn_bulk      — wiele mappingów naraz (po manual confirm)
 *   - threshold_get   — odczyt aktualnego progu auto-accept dla tenanta
 *   - threshold_set   — zapis progu auto-accept (owner only)
 *
 * AUTH (Konstytucja v5 § Prawo IV — Zero Zaufania):
 *   - auth_guard.php (session+JWT) → wszystkie akcje wymagają zalogowanego usera
 *   - RBAC:
 *     - suggest / suggest_bulk: owner / admin / manager (każda osoba w
 *       backoffice / na zmianie może zobaczyć propozycje)
 *     - learn / learn_bulk: owner / manager (operacyjna decyzja - manager
 *       który przyjmuje fakturę, owner który nadzoruje)
 *     - threshold_get: owner / admin
 *     - threshold_set: WYŁĄCZNIE owner (decyzja biznesowa o automatyzacji)
 *   - Bariera tenant_id zawsze (Konstytucja § Prawo VI Snajper)
 *
 * AUDIT:
 *   - learn / learn_bulk → wpis do sh_settings_audit (action='autoscan_learn',
 *     entity_type='product_mapping', external_name + sku)
 *
 * Sesja F2 · 2026-05-11. Pełna logika dopasowania w `core/AutoScanEngine.php`.
 */

function procResponse(bool $ok, $data = null, ?string $msg = null, ?string $code = null): void
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

function procFail(int $httpCode, string $code, ?string $msg = null): void
{
    http_response_code($httpCode);
    procResponse(false, null, $msg ?? $code, $code);
}

function procLoadActorRole(PDO $pdo, int $tenantId, int $userId): string
{
    $st = $pdo->prepare(
        'SELECT LOWER(role) FROM sh_users WHERE id = :uid AND tenant_id = :tid AND is_deleted = 0 LIMIT 1'
    );
    $st->execute([':uid' => $userId, ':tid' => $tenantId]);
    $r = $st->fetchColumn();
    return is_string($r) ? $r : '';
}

function procRequireRole(string $actorRole, array $allowed): void
{
    if (!in_array($actorRole, $allowed, true)) {
        procFail(403, 'FORBIDDEN',
            'Wymagana rola: ' . implode(' / ', $allowed) . '. Aktualna: ' . ($actorRole ?: 'unknown') . '.');
    }
}

function procAuditLearn(
    PDO $pdo, int $tenantId, int $userId,
    string $externalName, string $sku, string $action = 'autoscan_learn'
): void {
    try {
        $st = $pdo->prepare(
            "INSERT INTO sh_settings_audit
                (tenant_id, user_id, actor_ip, action, entity_type, entity_id, before_json, after_json)
             VALUES
                (:tid, :uid, :ip, :act, 'product_mapping', NULL, NULL, :after)"
        );
        $st->execute([
            ':tid'   => $tenantId,
            ':uid'   => $userId,
            ':ip'    => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ':act'   => $action,
            ':after' => json_encode([
                'external_name' => $externalName,
                'internal_sku'  => $sku,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    } catch (\Throwable $e) {
        error_log('[procurement/suggest.audit] ' . $e->getMessage());
    }
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/../../core/AutoScanEngine.php';

    /** @var PDO $pdo */
    /** @var int $tenant_id */
    /** @var int $user_id */

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        procFail(405, 'METHOD_NOT_ALLOWED', 'Only POST is allowed.');
    }

    $raw = file_get_contents('php://input') ?: '{}';
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        procFail(400, 'INVALID_JSON', 'Request body must be a JSON object.');
    }

    $action = trim((string)($input['action'] ?? ''));
    if ($action === '') {
        procFail(400, 'ACTION_REQUIRED', 'Missing "action" field.');
    }

    $actorRole = procLoadActorRole($pdo, $tenant_id, $user_id);

    switch ($action) {
        // ---------------------------------------------------------------------
        case 'suggest': {
            procRequireRole($actorRole, ['owner', 'admin', 'manager']);
            $externalName = trim((string) ($input['external_name'] ?? ''));
            if ($externalName === '') {
                procFail(400, 'EXTERNAL_NAME_REQUIRED', 'Pole external_name jest wymagane.');
            }
            $threshold = isset($input['threshold']) ? (int) $input['threshold'] : null;
            $result = AutoScanEngine::match($pdo, $tenant_id, $externalName, $threshold);
            procResponse(true, $result, 'OK');
            break;
        }

        // ---------------------------------------------------------------------
        case 'suggest_bulk': {
            procRequireRole($actorRole, ['owner', 'admin', 'manager']);
            $lines = $input['lines'] ?? [];
            if (!is_array($lines) || $lines === []) {
                procFail(400, 'LINES_REQUIRED', 'Pole lines jest wymagane (niepusta tablica).');
            }
            if (count($lines) > 500) {
                procFail(400, 'TOO_MANY_LINES', 'Max 500 linii per call.');
            }
            $threshold = isset($input['threshold']) ? (int) $input['threshold'] : null;
            $results = AutoScanEngine::matchBulk($pdo, $tenant_id, $lines, $threshold);

            // Statystyki agregacji
            $stats = [
                'total'         => count($results),
                'auto_accept'   => 0,
                'needs_confirm' => 0,
                'no_match'      => 0,
                'by_match_type' => [],
            ];
            foreach ($results as $r) {
                if (!empty($r['should_auto_accept'])) {
                    $stats['auto_accept']++;
                } elseif (($r['match_type'] ?? '') === AutoScanEngine::MATCH_NONE) {
                    $stats['no_match']++;
                } else {
                    $stats['needs_confirm']++;
                }
                $mt = $r['match_type'] ?? 'NONE';
                $stats['by_match_type'][$mt] = ($stats['by_match_type'][$mt] ?? 0) + 1;
            }

            procResponse(true, ['results' => $results, 'stats' => $stats], 'OK');
            break;
        }

        // ---------------------------------------------------------------------
        case 'learn': {
            procRequireRole($actorRole, ['owner', 'manager']);
            $externalName = trim((string) ($input['external_name'] ?? ''));
            $sku = trim((string) ($input['sku'] ?? ''));
            if ($externalName === '' || $sku === '') {
                procFail(400, 'INVALID_INPUT', 'Pola external_name i sku są wymagane.');
            }
            $r = AutoScanEngine::learnMapping($pdo, $tenant_id, $externalName, $sku);
            if (!$r['success']) {
                procFail(400, 'LEARN_FAILED', $r['error'] ?? 'Nie udało się zapisać mappingu.');
            }
            if (!empty($r['learned'])) {
                procAuditLearn($pdo, $tenant_id, $user_id, $externalName, $sku);
            }
            procResponse(true, $r, $r['learned'] ? 'Zapisano mapping.' : 'Mapping już istniał (idempotent).');
            break;
        }

        // ---------------------------------------------------------------------
        case 'learn_bulk': {
            procRequireRole($actorRole, ['owner', 'manager']);
            $mappings = $input['mappings'] ?? [];
            if (!is_array($mappings) || $mappings === []) {
                procFail(400, 'MAPPINGS_REQUIRED', 'Pole mappings jest wymagane.');
            }
            if (count($mappings) > 200) {
                procFail(400, 'TOO_MANY_MAPPINGS', 'Max 200 mappingów per call.');
            }
            $learned = 0;
            $skipped = 0;
            $errors  = [];
            foreach ($mappings as $idx => $m) {
                $extName = trim((string) ($m['external_name'] ?? ''));
                $sku = trim((string) ($m['sku'] ?? ''));
                if ($extName === '' || $sku === '') {
                    $errors[] = ['index' => (int) $idx, 'error' => 'external_name lub sku puste'];
                    continue;
                }
                $r = AutoScanEngine::learnMapping($pdo, $tenant_id, $extName, $sku);
                if (!$r['success']) {
                    $errors[] = ['index' => (int) $idx, 'error' => $r['error'] ?? 'unknown'];
                    continue;
                }
                if (!empty($r['learned'])) {
                    $learned++;
                    procAuditLearn($pdo, $tenant_id, $user_id, $extName, $sku);
                } else {
                    $skipped++;
                }
            }
            procResponse(true, [
                'learned' => $learned,
                'skipped' => $skipped,
                'errors'  => $errors,
                'total'   => count($mappings),
            ], "Zapisano {$learned}, pominięto {$skipped} (już istniały), błędy: " . count($errors));
            break;
        }

        // ---------------------------------------------------------------------
        case 'threshold_get': {
            procRequireRole($actorRole, ['owner', 'admin']);
            $stmt = $pdo->prepare(
                "SELECT setting_value FROM sh_tenant_settings
                  WHERE tenant_id = :tid AND setting_key = 'autoscan_auto_accept_threshold'
                  LIMIT 1"
            );
            $stmt->execute([':tid' => $tenant_id]);
            $value = $stmt->fetchColumn();
            $threshold = is_string($value) && ctype_digit(trim($value))
                ? (int) trim($value)
                : AutoScanEngine::DEFAULT_AUTO_ACCEPT_THRESHOLD;
            procResponse(true, [
                'threshold'         => $threshold,
                'is_default'        => !is_string($value) || trim($value) === '',
                'default_threshold' => AutoScanEngine::DEFAULT_AUTO_ACCEPT_THRESHOLD,
            ]);
            break;
        }

        // ---------------------------------------------------------------------
        case 'threshold_set': {
            procRequireRole($actorRole, ['owner']);
            $value = (int) ($input['threshold'] ?? -1);
            if ($value < 0 || $value > 100) {
                procFail(400, 'INVALID_THRESHOLD', 'Próg auto-accept musi być w zakresie 0–100.');
            }
            $stmt = $pdo->prepare(
                "INSERT INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
                 VALUES (:tid, 'autoscan_auto_accept_threshold', :v)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $stmt->execute([':tid' => $tenant_id, ':v' => (string) $value]);
            procAuditLearn($pdo, $tenant_id, $user_id, "threshold={$value}", '', 'autoscan_threshold_set');
            procResponse(true, ['threshold' => $value], "Ustawiono próg auto-accept na {$value}%.");
            break;
        }

        // ---------------------------------------------------------------------
        default:
            procFail(400, 'UNKNOWN_ACTION', "Nieznana akcja: {$action}");
    }
} catch (\Throwable $e) {
    error_log('[procurement/suggest] FATAL: ' . $e->getMessage());
    procFail(500, 'INTERNAL_ERROR', 'Błąd serwera: ' . $e->getMessage());
}
