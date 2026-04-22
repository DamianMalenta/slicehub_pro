<?php

declare(strict_types=1);

/**
 * SliceHub Integration Worker — async dispatcher for 3rd-party POS adapters.
 *
 * Flow:
 *   sh_event_outbox  (m026)
 *      └─► IntegrationDispatcher (m028)
 *              ├─ AdapterRegistry::resolveForTenant()
 *              ├─ PapuAdapter / DotykackaAdapter / GastroSoftAdapter
 *              └─ sh_integration_deliveries + sh_integration_attempts
 *
 * Ten worker jest NIEZALEŻNY od worker_webhooks.php — obydwa konsumują
 * ten sam outbox, ale trzymają oddzielny state i mają różne target-y
 * (webhooks = generic HTTP, integrations = konkretni providerzy POS).
 *
 * ── Usage ────────────────────────────────────────────────────────────────
 *
 *   Single batch (cron):
 *     php scripts/worker_integrations.php
 *
 *   Continuous loop (systemd/docker):
 *     php scripts/worker_integrations.php --loop --sleep=10
 *
 *   Dry run (no HTTP):
 *     php scripts/worker_integrations.php --dry-run -v
 *
 * ── Cron example ─────────────────────────────────────────────────────────
 *
 *   (star)/2 * * * * cd /var/www/slicehub && /usr/bin/php scripts/worker_integrations.php >> logs/integrations.log 2>&1
 *
 * ── Exit codes ───────────────────────────────────────────────────────────
 *
 *   0 — OK
 *   1 — DB connection / missing tables
 *   2 — Another worker running (PID lock)
 *   3 — Uncaught exception
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only.';
    exit(1);
}

$opts = getopt('v', ['loop', 'sleep::', 'batch::', 'dry-run', 'help', 'max-batches::']);

if (isset($opts['help'])) {
    echo <<<HELP

SliceHub Integration Worker — cron dispatcher for 3rd-party POS adapters (m028).

Usage:
  php scripts/worker_integrations.php [options]

Options:
  --loop              Continuous loop (exit only on SIGTERM/SIGINT)
  --sleep=N           Seconds between batches in loop mode (default: 10)
  --batch=N           Max events per batch (default: 50)
  --max-batches=N     Stop after N batches (default: 1 single / ∞ loop)
  --dry-run           Simulate dispatch without HTTP requests
  -v                  Verbose STDERR logging

Exit codes:
  0  OK
  1  DB/config error
  2  Another worker running
  3  Runtime exception

HELP;
    exit(0);
}

$verbose    = isset($opts['v']);
$loopMode   = isset($opts['loop']);
$sleepSec   = isset($opts['sleep']) ? max(1, (int)$opts['sleep']) : 10;
$batchSize  = isset($opts['batch']) ? max(1, (int)$opts['batch']) : 50;
$dryRun     = isset($opts['dry-run']);
$maxBatches = isset($opts['max-batches']) ? max(1, (int)$opts['max-batches']) : ($loopMode ? PHP_INT_MAX : 1);

// ── Boot ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../core/db_config.php';
require_once __DIR__ . '/../core/Integrations/BaseAdapter.php';
require_once __DIR__ . '/../core/Integrations/PapuAdapter.php';
require_once __DIR__ . '/../core/Integrations/DotykackaAdapter.php';
require_once __DIR__ . '/../core/Integrations/GastroSoftAdapter.php';
require_once __DIR__ . '/../core/Integrations/AdapterRegistry.php';
require_once __DIR__ . '/../core/Integrations/IntegrationDispatcher.php';

use SliceHub\Integrations\IntegrationDispatcher;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "[worker_integrations] FATAL: \$pdo not available from db_config.php\n");
    exit(1);
}

// ── PID lock ─────────────────────────────────────────────────────────────
$pidDir  = __DIR__ . '/../logs';
if (!is_dir($pidDir)) { @mkdir($pidDir, 0775, true); }
$pidFile = $pidDir . '/worker_integrations.pid';
$pidHandle = @fopen($pidFile, 'c+');

if (!$pidHandle) {
    fwrite(STDERR, "[worker_integrations] FATAL: cannot open PID file {$pidFile}\n");
    exit(1);
}

if (!flock($pidHandle, LOCK_EX | LOCK_NB)) {
    $existingPid = trim((string)fread($pidHandle, 32));
    fclose($pidHandle);
    fwrite(STDERR, "[worker_integrations] another instance is running (pid={$existingPid}) — exiting\n");
    exit(2);
}

ftruncate($pidHandle, 0);
fwrite($pidHandle, (string)getmypid());
fflush($pidHandle);

register_shutdown_function(function () use ($pidHandle, $pidFile) {
    if (is_resource($pidHandle)) {
        flock($pidHandle, LOCK_UN);
        fclose($pidHandle);
    }
    @unlink($pidFile);
});

// ── Signal handling ──────────────────────────────────────────────────────
$shouldStop = false;
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $sig = function () use (&$shouldStop) { $shouldStop = true; };
    pcntl_signal(SIGTERM, $sig);
    pcntl_signal(SIGINT,  $sig);
}

// ── Transport (dry-run override) ─────────────────────────────────────────
$transport = null;
if ($dryRun) {
    $transport = static function (string $method, string $url, array $headers, string $body, int $timeout): array {
        fwrite(STDERR, "[DRY-RUN] {$method} {$url} (timeout={$timeout}s)\n");
        $hdrPreview = array_slice($headers, 0, 3);
        fwrite(STDERR, '[DRY-RUN] headers: ' . implode(' | ', $hdrPreview) . "…\n");
        fwrite(STDERR, '[DRY-RUN] body   : ' . substr($body, 0, 200) . (strlen($body) > 200 ? '…' : '') . "\n");
        return ['code' => 200, 'body' => '{"id":"dry-run-ext-id","dry_run":true}', 'error' => null];
    };
}

$dispatcher = new IntegrationDispatcher($pdo, $transport, $batchSize, $verbose);

// ── Main loop ────────────────────────────────────────────────────────────
$totalStats = [];
$batchesRun = 0;
$startedAt  = time();

fwrite(STDERR, sprintf(
    "[worker_integrations] started pid=%d loop=%s dry_run=%s batch=%d sleep=%ds maxBatches=%s\n",
    getmypid(), $loopMode ? 'yes' : 'no', $dryRun ? 'yes' : 'no',
    $batchSize, $sleepSec,
    $maxBatches === PHP_INT_MAX ? '∞' : (string)$maxBatches
));

try {
    do {
        $stats = $dispatcher->runBatch();
        foreach ($stats as $k => $v) {
            $totalStats[$k] = ($totalStats[$k] ?? 0) + $v;
        }
        $batchesRun++;

        if ($stats['processed'] > 0 || $stats['skipped'] > 0 || $verbose) {
            fwrite(STDERR, sprintf(
                "[worker_integrations] batch %d: processed=%d delivered=%d failed=%d dead=%d no_adapters=%d skipped=%d\n",
                $batchesRun,
                $stats['processed'], $stats['delivered'], $stats['failed'],
                $stats['dead'], $stats['no_adapters'], $stats['skipped']
            ));
        }

        if ($shouldStop) {
            fwrite(STDERR, "[worker_integrations] signal received — graceful shutdown\n");
            break;
        }

        if ($batchesRun >= $maxBatches) break;

        if ($loopMode) {
            $sleepMs = ($stats['processed'] === $batchSize) ? 100_000 : ($sleepSec * 1_000_000);
            usleep($sleepMs);
        }
    } while ($loopMode);

} catch (Throwable $e) {
    fwrite(STDERR, sprintf(
        "[worker_integrations] FATAL: %s\n  in %s:%d\n  %s\n",
        $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()
    ));
    exit(3);
}

$elapsed = time() - $startedAt;
fwrite(STDERR, sprintf(
    "[worker_integrations] done. batches=%d elapsed=%ds totals: processed=%d delivered=%d failed=%d dead=%d no_adapters=%d skipped=%d\n",
    $batchesRun, $elapsed,
    $totalStats['processed']   ?? 0,
    $totalStats['delivered']   ?? 0,
    $totalStats['failed']      ?? 0,
    $totalStats['dead']        ?? 0,
    $totalStats['no_adapters'] ?? 0,
    $totalStats['skipped']     ?? 0
));

exit(0);
