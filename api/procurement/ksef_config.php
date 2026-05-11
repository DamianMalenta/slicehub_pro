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
 * SliceHub — KSeF Inbox Configuration endpoint
 *
 * /api/procurement/ksef_config.php
 *
 * Akcje:
 *   - config_get        — odczyt aktualnej konfiguracji KSeF (token redacted)
 *   - config_save       — zapis token + environment (owner only)
 *   - test_connection   — strzał do KSeF API (Session/Status) lub mock
 *   - poll_now          — manualny trigger worker_ksef_inbox dla tenanta
 *   - state             — odczyt sh_ksef_inbox_state (last_polled_at, errors)
 *   - toggle_auto_poll  — włącz/wyłącz auto-poll dla tenanta (cron użyje)
 *
 * RBAC (Konstytucja v5 § Prawo VI):
 *   - config_get / state: owner / admin
 *   - config_save / toggle_auto_poll: WYŁĄCZNIE owner (decyzja biznesowa)
 *   - test_connection / poll_now: owner / admin (debug)
 *
 * Token KSeF: szyfrowany przez CredentialVault::encrypt przed zapisem do
 * sh_tenant_integrations.credentials (JSON z environment + token).
 *
 * Sesja F4 · 2026-05-11.
 */

function kfResponse(bool $ok, $data = null, ?string $msg = null, ?string $code = null): void
{
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    while (ob_get_level() > 0) ob_end_clean();
    $out = ['success' => $ok, 'data' => $data, 'message' => $msg];
    if ($code !== null) $out['code'] = $code;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

function kfFail(int $httpCode, string $code, ?string $msg = null): void
{
    http_response_code($httpCode);
    kfResponse(false, null, $msg ?? $code, $code);
}

function kfLoadRole(\PDO $pdo, int $tenantId, int $userId): string
{
    $st = $pdo->prepare(
        'SELECT LOWER(role) FROM sh_users WHERE id = :uid AND tenant_id = :tid AND is_deleted = 0 LIMIT 1'
    );
    $st->execute([':uid' => $userId, ':tid' => $tenantId]);
    $r = $st->fetchColumn();
    return is_string($r) ? $r : '';
}

function kfRequireRole(string $role, array $allowed): void
{
    if (!in_array($role, $allowed, true)) {
        kfFail(403, 'FORBIDDEN', 'Wymagana rola: ' . implode(' / ', $allowed) . '. Aktualna: ' . ($role ?: 'unknown') . '.');
    }
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/../../core/CredentialVault.php';
    require_once __DIR__ . '/../../core/Ksef/Client.php';

    /** @var PDO $pdo */
    /** @var int $tenant_id */
    /** @var int $user_id */

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') kfFail(405, 'METHOD_NOT_ALLOWED');

    $raw = file_get_contents('php://input') ?: '{}';
    $input = json_decode($raw, true);
    if (!is_array($input)) kfFail(400, 'INVALID_JSON');

    $action = trim((string) ($input['action'] ?? ''));
    if ($action === '') kfFail(400, 'ACTION_REQUIRED');

    $actorRole = kfLoadRole($pdo, $tenant_id, $user_id);

    switch ($action) {
        case 'config_get': {
            kfRequireRole($actorRole, ['owner', 'admin']);
            $st = $pdo->prepare(
                "SELECT id, display_name, api_base_url, credentials, is_active, last_sync_at,
                        consecutive_failures, last_failure_at, max_retries
                   FROM sh_tenant_integrations
                  WHERE tenant_id = :tid AND provider = 'ksef' LIMIT 1"
            );
            $st->execute([':tid' => $tenant_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                kfResponse(true, [
                    'configured'         => false,
                    'environment'        => 'mock',
                    'has_token'          => false,
                    'token_preview'      => null,
                    'is_active'          => false,
                    'auto_poll_enabled'  => false,
                ]);
            }
            $credsRaw = (string) ($row['credentials'] ?? '');
            $plain = $credsRaw !== '' ? \CredentialVault::decrypt($credsRaw) : null;
            if ($plain === null && $credsRaw !== '') $plain = $credsRaw;
            $creds = is_string($plain) ? json_decode($plain, true) : [];
            $token = is_array($creds) ? (string) ($creds['token'] ?? '') : '';

            // Auto-poll state
            $stState = $pdo->prepare(
                "SELECT auto_poll_enabled FROM sh_ksef_inbox_state WHERE tenant_id = :tid LIMIT 1"
            );
            $stState->execute([':tid' => $tenant_id]);
            $autoPoll = (bool) $stState->fetchColumn();

            kfResponse(true, [
                'configured'        => true,
                'environment'       => is_array($creds) ? (string) ($creds['environment'] ?? 'mock') : 'mock',
                'has_token'         => $token !== '',
                'token_preview'     => $token !== '' ? '••••' . substr($token, -4) : null,
                'api_base_url'      => $row['api_base_url'],
                'is_active'         => (bool) $row['is_active'],
                'last_sync_at'      => $row['last_sync_at'],
                'consecutive_failures' => (int) $row['consecutive_failures'],
                'last_failure_at'   => $row['last_failure_at'],
                'auto_poll_enabled' => $autoPoll,
            ]);
            break;
        }

        case 'config_save': {
            kfRequireRole($actorRole, ['owner']);
            $env = strtolower(trim((string) ($input['environment'] ?? 'mock')));
            if (!in_array($env, ['mock', 'sandbox', 'prod'], true)) {
                kfFail(400, 'INVALID_ENV', 'environment musi być: mock | sandbox | prod.');
            }
            $token = trim((string) ($input['token'] ?? ''));
            // Token wymagany dla sandbox/prod; dla mock opcjonalny
            if ($env !== 'mock' && $token === '') {
                kfFail(400, 'TOKEN_REQUIRED', 'Token KSeF wymagany dla sandbox/prod.');
            }

            $credsJson = json_encode([
                'environment' => $env,
                'token'       => $token,
            ], JSON_UNESCAPED_UNICODE);
            $encrypted = \CredentialVault::encrypt($credsJson);

            // Upsert sh_tenant_integrations (provider='ksef')
            $upsert = $pdo->prepare(
                "INSERT INTO sh_tenant_integrations
                    (tenant_id, provider, display_name, credentials, direction, is_active)
                 VALUES (:tid, 'ksef', 'KSeF Inbox', :creds, 'pull', 1)
                 ON DUPLICATE KEY UPDATE
                    credentials = VALUES(credentials),
                    is_active = 1,
                    updated_at = NOW()"
            );
            $upsert->execute([':tid' => $tenant_id, ':creds' => $encrypted]);

            // Audit
            try {
                $pdo->prepare(
                    "INSERT INTO sh_settings_audit
                        (tenant_id, user_id, actor_ip, action, entity_type, entity_id, before_json, after_json)
                     VALUES (:tid, :uid, :ip, 'ksef_config_save', 'integration', NULL, NULL, :after)"
                )->execute([
                    ':tid'   => $tenant_id,
                    ':uid'   => $user_id,
                    ':ip'    => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                    ':after' => json_encode(['environment' => $env, 'has_token' => $token !== ''], JSON_UNESCAPED_UNICODE),
                ]);
            } catch (\Throwable $e) { error_log('[ksef_config.audit] ' . $e->getMessage()); }

            kfResponse(true, [
                'environment' => $env,
                'has_token'   => $token !== '',
            ], 'Konfiguracja KSeF zapisana.');
            break;
        }

        case 'test_connection': {
            kfRequireRole($actorRole, ['owner', 'admin']);
            $client = new \SliceHub\Ksef\Client($pdo, $tenant_id);
            $r = $client->testConnection();
            kfResponse($r['success'], $r, $r['message'] ?? null);
            break;
        }

        case 'poll_now': {
            kfRequireRole($actorRole, ['owner', 'admin']);
            // Wykonaj inline (jak worker, ale tylko 1 tenant) — wymaga wczytania worker
            // Wywołanie poprzez file execution byłoby OK, ale bezpieczniej re-use the logic:
            require_once __DIR__ . '/../../core/AutoScanEngine.php';
            require_once __DIR__ . '/../../core/Ksef/Parser.php';

            $client = new \SliceHub\Ksef\Client($pdo, $tenant_id);

            // Cursor
            $cur = $pdo->prepare(
                "SELECT last_polled_at, last_invoice_seen_id FROM sh_ksef_inbox_state WHERE tenant_id = :tid LIMIT 1"
            );
            $cur->execute([':tid' => $tenant_id]);
            $cursor = $cur->fetch(PDO::FETCH_ASSOC) ?: ['last_polled_at' => null, 'last_invoice_seen_id' => null];

            $sinceDate = $cursor['last_polled_at']
                ? date('Y-m-d', strtotime((string) $cursor['last_polled_at'] . ' -1 day'))
                : null;

            $qres = $client->queryInbox($sinceDate, $cursor['last_invoice_seen_id'] ?: null);
            if (!$qres['success']) {
                kfFail(502, 'KSEF_QUERY_FAILED', $qres['message'] ?? 'Query inbox failed');
            }

            $stats = ['fetched' => 0, 'inserted' => 0, 'skipped' => 0, 'errors' => 0];
            $lastSeenRef = null;
            foreach ($qres['invoices'] as $inv) {
                $stats['fetched']++;
                $refId = (string) $inv['ref_id'];
                if ($refId === '') { $stats['errors']++; continue; }

                $dup = $pdo->prepare(
                    "SELECT id FROM sh_ksef_invoices WHERE tenant_id = :tid AND ksef_reference_id = :ref LIMIT 1"
                );
                $dup->execute([':tid' => $tenant_id, ':ref' => $refId]);
                if ($dup->fetchColumn()) { $stats['skipped']++; continue; }

                $fres = $client->fetchInvoiceXml($refId);
                if (!$fres['success']) { $stats['errors']++; continue; }

                try {
                    pollInsertInvoice($pdo, $tenant_id, $refId, (string) $fres['xml']);
                    $stats['inserted']++;
                    $lastSeenRef = $refId;
                } catch (\Throwable $e) {
                    error_log('[ksef poll_now] ' . $e->getMessage());
                    $stats['errors']++;
                }
            }

            // Update cursor
            $pdo->prepare(
                "INSERT INTO sh_ksef_inbox_state (tenant_id, last_polled_at, last_invoice_seen_id, error_count, last_error)
                 VALUES (:tid, NOW(), :ref, 0, NULL)
                 ON DUPLICATE KEY UPDATE last_polled_at = NOW(),
                                         last_invoice_seen_id = COALESCE(:ref2, last_invoice_seen_id),
                                         error_count = 0, last_error = NULL"
            )->execute([':tid' => $tenant_id, ':ref' => $lastSeenRef, ':ref2' => $lastSeenRef]);

            kfResponse(true, ['stats' => $stats, 'environment' => $client->getEnvironment()],
                "Pobrano: {$stats['fetched']}, wstawiono: {$stats['inserted']}, pominięto: {$stats['skipped']}, błędy: {$stats['errors']}.");
            break;
        }

        case 'state': {
            kfRequireRole($actorRole, ['owner', 'admin']);
            $st = $pdo->prepare(
                "SELECT last_polled_at, last_invoice_seen_id, error_count, last_error,
                        auto_poll_enabled, updated_at
                   FROM sh_ksef_inbox_state WHERE tenant_id = :tid LIMIT 1"
            );
            $st->execute([':tid' => $tenant_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [
                'last_polled_at'        => null,
                'last_invoice_seen_id'  => null,
                'error_count'           => 0,
                'last_error'            => null,
                'auto_poll_enabled'     => 0,
                'updated_at'            => null,
            ];
            $row['auto_poll_enabled'] = (bool) $row['auto_poll_enabled'];
            kfResponse(true, $row);
            break;
        }

        case 'toggle_auto_poll': {
            kfRequireRole($actorRole, ['owner']);
            $enabled = !empty($input['enabled']) ? 1 : 0;
            $st = $pdo->prepare(
                "INSERT INTO sh_ksef_inbox_state (tenant_id, auto_poll_enabled)
                 VALUES (:tid, :en)
                 ON DUPLICATE KEY UPDATE auto_poll_enabled = :en2"
            );
            $st->execute([':tid' => $tenant_id, ':en' => $enabled, ':en2' => $enabled]);
            kfResponse(true, ['auto_poll_enabled' => (bool) $enabled],
                $enabled ? 'Auto-poll WŁĄCZONY (worker będzie pobierał faktury).' : 'Auto-poll WYŁĄCZONY.');
            break;
        }

        default:
            kfFail(400, 'UNKNOWN_ACTION', "Nieznana akcja: {$action}");
    }
} catch (\Throwable $e) {
    error_log('[procurement/ksef_config] FATAL: ' . $e->getMessage());
    kfFail(500, 'INTERNAL_ERROR', 'Błąd serwera: ' . $e->getMessage());
}

// =============================================================================
// Helper: parse XML + insert do sh_ksef_invoices (re-use logic z worker_ksef_inbox)
// =============================================================================
function pollInsertInvoice(\PDO $pdo, int $tenantId, string $refId, string $xml): void
{
    $parser = new \SliceHub\Ksef\Parser();
    $parsed = $parser->parse($xml);
    if (!$parsed['success']) {
        throw new \RuntimeException('Parser: ' . implode('; ', $parsed['errors']));
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            "INSERT INTO sh_ksef_invoices
                (tenant_id, ksef_reference_id,
                 supplier_nip, supplier_name, supplier_address,
                 buyer_nip, buyer_name,
                 invoice_number, issue_date, sale_date, payment_due_date, currency,
                 total_net_minor, total_vat_minor, total_gross_minor,
                 xml_blob, parsed_json, status)
             VALUES
                (:tid, :ref, :snip, :sname, :saddr, :bnip, :bname,
                 :inum, :issd, :sald, :payd, :cur,
                 :tnet, :tvat, :tgross, :xml, :pjson, 'draft')"
        );
        $st->execute([
            ':tid' => $tenantId, ':ref' => $refId,
            ':snip' => $parsed['supplier']['nip'] ?: null,
            ':sname' => $parsed['supplier']['name'] ?: null,
            ':saddr' => $parsed['supplier']['address'] ?: null,
            ':bnip' => $parsed['buyer']['nip'] ?: null,
            ':bname' => $parsed['buyer']['name'] ?: null,
            ':inum' => $parsed['invoice']['number'] ?: ('KSEF-' . $refId),
            ':issd' => $parsed['invoice']['issue_date'],
            ':sald' => $parsed['invoice']['sale_date'],
            ':payd' => $parsed['invoice']['payment_due_date'],
            ':cur'  => $parsed['invoice']['currency'] ?: 'PLN',
            ':tnet' => $parsed['totals']['total_net_minor'] ?? 0,
            ':tvat' => $parsed['totals']['total_vat_minor'] ?? 0,
            ':tgross' => $parsed['totals']['total_gross_minor'] ?? 0,
            ':xml' => $xml,
            ':pjson' => json_encode($parsed, JSON_UNESCAPED_UNICODE),
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $stL = $pdo->prepare(
            "INSERT INTO sh_ksef_invoice_lines
                (ksef_invoice_id, line_no, external_name, external_description,
                 gtu_code, pkwiu, unit, qty, unit_net, line_net_minor, vat_rate)
             VALUES
                (:iid, :lno, :name, :desc, :gtu, :pkwiu, :unit, :qty, :unet, :lnet, :vat)"
        );
        foreach ($parsed['lines'] as $line) {
            $stL->execute([
                ':iid' => $invoiceId, ':lno' => $line['line_no'],
                ':name' => $line['external_name'], ':desc' => $line['description'] ?: null,
                ':gtu' => $line['gtu_code'] ?: null, ':pkwiu' => $line['pkwiu'] ?: null,
                ':unit' => $line['unit'] ?: null, ':qty' => $line['qty'],
                ':unet' => $line['unit_net'], ':lnet' => $line['line_net_minor'],
                ':vat' => $line['vat_rate'],
            ]);
        }
        $pdo->commit();

        // AutoScan po commit
        $linesStmt = $pdo->prepare(
            "SELECT id, external_name FROM sh_ksef_invoice_lines WHERE ksef_invoice_id = :iid"
        );
        $linesStmt->execute([':iid' => $invoiceId]);
        $upd = $pdo->prepare(
            "UPDATE sh_ksef_invoice_lines SET resolved_sku = :sku, match_type = :mt,
                    match_confidence = :conf, match_candidates_json = :cand, resolved_at = NOW()
              WHERE id = :id"
        );
        foreach ($linesStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $r = \AutoScanEngine::match($pdo, $tenantId, (string) $row['external_name']);
            $upd->execute([
                ':sku' => $r['sku'], ':mt' => $r['match_type'] ?? 'NONE',
                ':conf' => $r['confidence'] ?? 0,
                ':cand' => json_encode($r['candidates'] ?? [], JSON_UNESCAPED_UNICODE),
                ':id' => $row['id'],
            ]);
        }
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
