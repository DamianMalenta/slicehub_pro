<?php

declare(strict_types=1);

/**
 * SliceHub — KSeF Inbox Worker
 *
 * Cron-callable script which polls KSeF API for new invoices addressed to
 * our NIP, parses them through FA(2) Parser, runs AutoScan match per line,
 * and INSERTs into sh_ksef_invoices (m046). After this, faktury are
 * automatically visible in Procurement Inbox UI (modules/procurement/).
 *
 * URUCHOMIENIE:
 *   1. CLI (cron na uti.pl / VPS):
 *      php /path/to/scripts/worker_ksef_inbox.php [tenant_id]
 *      Cron: "(slash)15 (gwiazdki)" php scripts/worker_ksef_inbox.php  (każde 15 min)
 *
 *   2. HTTP (external cron-job.org → szare hostingi bez cron-a):
 *      https://slicehub.net/scripts/worker_ksef_inbox.php?key=<SLICEHUB_SCRIPT_KEY>&tenant_id=1
 *      (klucz z core/local_secrets.php — taki sam jak install_panel.php)
 *
 *   3. Manual trigger z Settings UI:
 *      POST /api/settings/engine.php action=ksef_poll_now
 *
 * MODE:
 *   - Auto-detect środowisko z sh_tenant_integrations (provider='ksef').
 *   - Brak konfiguracji = mock mode (3 fixture invoices per tenant — dev test).
 *
 * DEDUP:
 *   UNIQUE (tenant_id, ksef_reference_id) + SELECT przed INSERT; przy wyścigu dwóch workerów
 *   duplikat SQL (1062) jest łapany i traktowany jak pominięcie.
 *
 * AUDIT:
 *   sh_ksef_inbox_state per tenant: last_polled_at, last_invoice_seen_id,
 *   error_count, last_error. UI Settings i procurement/inbox pokazują
 *   "Ostatni poll: 5 min temu".
 *
 * Sesja F4 · 2026-05-11.
 */

declare(ticks=1);

// =============================================================================
// Bootstrap: CLI vs HTTP
// =============================================================================
$isCli = (PHP_SAPI === 'cli');
$out = static function (string $msg) use ($isCli): void {
    if ($isCli) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    } else {
        echo htmlspecialchars($msg, ENT_QUOTES) . "<br>\n";
    }
};

$localSecrets = __DIR__ . '/../core/local_secrets.php';
if (is_file($localSecrets)) require_once $localSecrets;

require_once __DIR__ . '/../core/db_config.php';
require_once __DIR__ . '/../core/AutoScanEngine.php';
require_once __DIR__ . '/../core/Ksef/Parser.php';
require_once __DIR__ . '/../core/Ksef/Client.php';
require_once __DIR__ . '/../core/Ksef/InboxInvoiceRepository.php';

if (!isset($pdo)) {
    http_response_code(500);
    $out('FATAL: brak \$pdo z db_config.php');
    exit(1);
}

// HTTP auth — klucz w query string (analogia install_panel.php)
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $expectedKey = defined('SLICEHUB_SCRIPT_KEY') ? (string) constant('SLICEHUB_SCRIPT_KEY') : '';
    $key = (string) ($_GET['key'] ?? '');
    if ($expectedKey === '' || !hash_equals($expectedKey, $key)) {
        http_response_code(403);
        $out('403 — wymaga SLICEHUB_SCRIPT_KEY');
        exit;
    }
}

// =============================================================================
// Tenant scope
// =============================================================================
$tenantArg = $isCli
    ? ($argv[1] ?? null)
    : ($_GET['tenant_id'] ?? null);

if ($tenantArg !== null) {
    $tenantIds = [(int) $tenantArg];
} else {
    // Wszystkie aktywne tenanty z włączonym auto_poll
    $st = $pdo->query(
        "SELECT t.id FROM sh_tenant t
           LEFT JOIN sh_ksef_inbox_state s ON s.tenant_id = t.id
          WHERE COALESCE(s.auto_poll_enabled, 0) = 1
          ORDER BY t.id"
    );
    $tenantIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($tenantIds === []) {
        $out('Brak tenantów z auto_poll_enabled=1. Aby uruchomić mock test: php worker_ksef_inbox.php 1');
        exit(0);
    }
}

// =============================================================================
// Worker per tenant
// =============================================================================
$globalStats = [
    'tenants_processed' => 0,
    'invoices_fetched'  => 0,
    'invoices_inserted' => 0,
    'invoices_skipped'  => 0,
    'errors'            => 0,
];

foreach ($tenantIds as $tid) {
    $out("=== Tenant {$tid} ===");
    $globalStats['tenants_processed']++;

    try {
        $client = new \SliceHub\Ksef\Client($pdo, $tid);
        $env = $client->getEnvironment();
        $out("Environment: {$env}");

        $stAct = $pdo->prepare(
            "SELECT COALESCE(is_active, 1) FROM sh_tenant_integrations
              WHERE tenant_id = :tid AND provider = 'ksef' LIMIT 1"
        );
        $stAct->execute([':tid' => $tid]);
        $intActive = (int) $stAct->fetchColumn();
        if ($intActive !== 1) {
            $out('  ⏭  integracja KSeF wyłączona (is_active=0) — pomijam tenant.');
            continue;
        }

        // Wczytaj cursor z sh_ksef_inbox_state
        $cur = $pdo->prepare(
            "SELECT last_polled_at, last_invoice_seen_id FROM sh_ksef_inbox_state
              WHERE tenant_id = :tid LIMIT 1"
        );
        $cur->execute([':tid' => $tid]);
        $cursor = $cur->fetch(PDO::FETCH_ASSOC) ?: ['last_polled_at' => null, 'last_invoice_seen_id' => null];

        // Query inbox (od ostatniego polla -1 dzień margines)
        $sinceDate = $cursor['last_polled_at']
            ? date('Y-m-d', strtotime((string) $cursor['last_polled_at'] . ' -1 day'))
            : null;

        $qres = $client->queryInbox($sinceDate, $cursor['last_invoice_seen_id'] ?: null);
        if (!$qres['success']) {
            $out('  ❌ queryInbox failed: ' . ($qres['message'] ?? 'unknown'));
            recordError($pdo, $tid, $qres['message'] ?? 'queryInbox failed');
            $globalStats['errors']++;
            continue;
        }

        $invoices = $qres['invoices'];
        $globalStats['invoices_fetched'] += count($invoices);
        $out('  📋 znaleziono ' . count($invoices) . ' faktur w KSeF API');

        $lastSeenRef = null;
        foreach ($invoices as $inv) {
            $refId = (string) $inv['ref_id'];
            if ($refId === '') continue;

            // Sprawdź dedup przez UNIQUE (tenant_id, ksef_reference_id)
            $dup = $pdo->prepare(
                "SELECT id FROM sh_ksef_invoices
                  WHERE tenant_id = :tid AND ksef_reference_id = :ref LIMIT 1"
            );
            $dup->execute([':tid' => $tid, ':ref' => $refId]);
            if ($dup->fetchColumn()) {
                $out("  ⏭  pominięto (już w bazie): {$refId}");
                $globalStats['invoices_skipped']++;
                continue;
            }

            // Fetch full XML
            $fres = $client->fetchInvoiceXml($refId);
            if (!$fres['success']) {
                $out("  ❌ fetchInvoiceXml({$refId}) failed: " . ($fres['message'] ?? 'unknown'));
                $globalStats['errors']++;
                continue;
            }
            $xml = (string) $fres['xml'];

            // Parse + insert
            try {
                $insertedId = insertInvoiceFromXml($pdo, $tid, $refId, $xml);
                $globalStats['invoices_inserted']++;
                $lastSeenRef = $refId;
                $out("  ✅ wstawiono: {$refId} (sh_ksef_invoices.id={$insertedId})");
            } catch (\Throwable $e) {
                if (\SliceHub\Ksef\InboxInvoiceRepository::isMysqlDuplicateKey($e)) {
                    $out("  ⏭  pominięto (duplikat SQL / race): {$refId}");
                    $globalStats['invoices_skipped']++;
                    continue;
                }
                $out("  ❌ insert {$refId} failed: " . $e->getMessage());
                $globalStats['errors']++;
            }
        }

        // Update cursor
        upsertCursor($pdo, $tid, $lastSeenRef);

    } catch (\Throwable $e) {
        $out('  ❌ FATAL tenant: ' . $e->getMessage());
        recordError($pdo, $tid, $e->getMessage());
        $globalStats['errors']++;
    }
}

$out('');
$out('=== Podsumowanie ===');
$out('  Tenanty: ' . $globalStats['tenants_processed']);
$out('  Faktur pobranych: ' . $globalStats['invoices_fetched']);
$out('  Wstawionych: ' . $globalStats['invoices_inserted']);
$out('  Pominiętych (dedup): ' . $globalStats['invoices_skipped']);
$out('  Błędów: ' . $globalStats['errors']);

if (!$isCli) {
    echo "\n<pre>" . htmlspecialchars(json_encode($globalStats, JSON_PRETTY_PRINT)) . "</pre>";
}
exit($globalStats['errors'] > 0 ? 1 : 0);

// =============================================================================
// Helpers
// =============================================================================

function insertInvoiceFromXml(\PDO $pdo, int $tenantId, string $refId, string $xml): int
{
    $parser = new \SliceHub\Ksef\Parser();
    $parsed = $parser->parse($xml);
    if (!$parsed['success']) {
        throw new \RuntimeException('Parser: ' . implode('; ', $parsed['errors']));
    }

    $invoiceId = \SliceHub\Ksef\InboxInvoiceRepository::insertInvoiceWithLines(
        $pdo,
        $tenantId,
        $refId,
        $xml,
        $parsed,
        'KSEF-' . $refId
    );
    \SliceHub\Ksef\InboxInvoiceRepository::matchInvoiceLines($pdo, $tenantId, $invoiceId);

    return $invoiceId;
}

function upsertCursor(\PDO $pdo, int $tenantId, ?string $lastSeenRef): void
{
    $st = $pdo->prepare(
        "INSERT INTO sh_ksef_inbox_state (tenant_id, last_polled_at, last_invoice_seen_id, error_count, last_error)
         VALUES (:tid, NOW(), :ref, 0, NULL)
         ON DUPLICATE KEY UPDATE
             last_polled_at = NOW(),
             last_invoice_seen_id = COALESCE(:ref2, last_invoice_seen_id),
             error_count = 0,
             last_error = NULL"
    );
    $st->execute([':tid' => $tenantId, ':ref' => $lastSeenRef, ':ref2' => $lastSeenRef]);
}

function recordError(\PDO $pdo, int $tenantId, string $error): void
{
    $st = $pdo->prepare(
        "INSERT INTO sh_ksef_inbox_state (tenant_id, last_polled_at, error_count, last_error)
         VALUES (:tid, NOW(), 1, :err)
         ON DUPLICATE KEY UPDATE
             last_polled_at = NOW(),
             error_count = error_count + 1,
             last_error = :err2"
    );
    $st->execute([':tid' => $tenantId, ':err' => $error, ':err2' => $error]);
}
