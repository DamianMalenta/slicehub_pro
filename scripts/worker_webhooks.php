<?php

declare(strict_types=1);

/**
 * SliceHub Webhook Worker — CLI dispatcher for sh_event_outbox.
 *
 * ── Usage ────────────────────────────────────────────────────────────────
 *
 *   Single batch (cron-friendly):
 *     php scripts/worker_webhooks.php
 *
 *   Continuous loop (systemd / docker):
 *     php scripts/worker_webhooks.php --loop --sleep=5
 *
 *   Verbose (debug):
 *     php scripts/worker_webhooks.php -v
 *
 *   Dry run (no HTTP requests):
 *     php scripts/worker_webhooks.php --dry-run
 *
 *   Override batch size:
 *     php scripts/worker_webhooks.php --batch=100
 *
 * ── Cron example (every minute) ──────────────────────────────────────────
 *
 *   * * * * * cd /var/www/slicehub && /usr/bin/php scripts/worker_webhooks.php >> logs/webhooks.log 2>&1
 *
 * ── Exit codes ───────────────────────────────────────────────────────────
 *
 *   0 — OK (batch processed, worker exits)
 *   1 — DB connection failed / missing outbox table
 *   2 — Another instance running (PID file lock held)
 *   3 — Uncaught exception during batch processing
 *
 * ── Safety ───────────────────────────────────────────────────────────────
 *
 * PID-file locking prevents two parallel workers from processing the same
 * batch. In multi-worker setups (multiple nodes), atomic claim at row level
 * (UPDATE … WHERE status='pending') prevents double dispatch — the PID lock
 * is a soft additional layer for single-node deployments.
 */

// ── 0. CLI guard ─────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'This script must be run from the command line.';
    exit(1);
}

// ── 1. Parse CLI args ────────────────────────────────────────────────────
$opts = getopt('v', ['loop', 'sleep::', 'batch::', 'dry-run', 'help', 'max-batches::']);

if (isset($opts['help'])) {
    echo <<<HELP

SliceHub Webhook Worker — cron dispatcher for sh_event_outbox.

Usage:
  php scripts/worker_webhooks.php [options]

Options:
  --loop              Continuous loop (exit only on SIGTERM/SIGINT)
  --sleep=N           Seconds to sleep between batches in loop mode (default: 5)
  --batch=N           Max events per batch (default: 50)
  --max-batches=N     Stop after N batches (default: infinite in loop mode, 1 in single mode)
  --dry-run           Simulate delivery without HTTP requests
  -v                  Verbose logging to STDERR

Exit codes:
  0  OK
  1  DB/config error
  2  Another worker is running
  3  Runtime exception

HELP;
    exit(0);
}

$verbose     = isset($opts['v']);
$loopMode    = isset($opts['loop']);
$sleepSec    = isset($opts['sleep']) ? max(1, (int)$opts['sleep']) : 5;
$batchSize   = isset($opts['batch']) ? max(1, (int)$opts['batch']) : 50;
$dryRun      = isset($opts['dry-run']);
$maxBatches  = isset($opts['max-batches']) ? max(1, (int)$opts['max-batches']) : ($loopMode ? PHP_INT_MAX : 1);

// ── 2. Boot ──────────────────────────────────────────────────────────────
require_once __DIR__ . '/../core/db_config.php';
require_once __DIR__ . '/../core/WebhookDispatcher.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "[worker_webhooks] FATAL: \$pdo not available from db_config.php\n");
    exit(1);
}

// ── 3. PID lock (prevent duplicate workers on same node) ─────────────────
$pidDir = __DIR__ . '/../logs';
if (!is_dir($pidDir)) {
    @mkdir($pidDir, 0775, true);
}
$pidFile = $pidDir . '/worker_webhooks.pid';
$pidHandle = @fopen($pidFile, 'c+');

if (!$pidHandle) {
    fwrite(STDERR, "[worker_webhooks] FATAL: cannot open PID file {$pidFile}\n");
    exit(1);
}

if (!flock($pidHandle, LOCK_EX | LOCK_NB)) {
    // Another instance holds the lock
    $existingPid = trim((string)fread($pidHandle, 32));
    fclose($pidHandle);
    fwrite(STDERR, "[worker_webhooks] another instance is running (pid={$existingPid}) — exiting\n");
    exit(2);
}

ftruncate($pidHandle, 0);
fwrite($pidHandle, (string)getmypid());
fflush($pidHandle);

// Release lock on exit
register_shutdown_function(function () use ($pidHandle, $pidFile) {
    if (is_resource($pidHandle)) {
        flock($pidHandle, LOCK_UN);
        fclose($pidHandle);
    }
    @unlink($pidFile);
});

// ── 4. Signal handling (graceful shutdown w --loop) ──────────────────────
$shouldStop = false;
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $sig = function () use (&$shouldStop) { $shouldStop = true; };
    pcntl_signal(SIGTERM, $sig);
    pcntl_signal(SIGINT,  $sig);
}

// ── 5. Dispatcher setup (z opcjonalnym dry-run transportem) ──────────────
$transport = null;
if ($dryRun) {
    $transport = static function (string $method, string $url, array $headers, string $body, int $timeout): array {
        fwrite(STDERR, "[DRY-RUN] {$method} {$url}\n");
        fwrite(STDERR, '[DRY-RUN] headers: ' . implode(' | ', array_slice($headers, 0, 3)) . "…\n");
        fwrite(STDERR, '[DRY-RUN] body   : ' . substr($body, 0, 200) . (strlen($body) > 200 ? '…' : '') . "\n");
        return ['code' => 200, 'body' => '{"dry_run":true}', 'error' => null];
    };
}

$dispatcher = new WebhookDispatcher($pdo, $transport, $batchSize, $verbose);

// ── 6. Main loop ─────────────────────────────────────────────────────────
$totalStats = ['processed' => 0, 'delivered' => 0, 'failed' => 0, 'dead' => 0, 'no_subscribers' => 0];
$batchesRun = 0;
$startedAt  = time();

fwrite(STDERR, sprintf(
    "[worker_webhooks] started pid=%d loop=%s dry_run=%s batch=%d sleep=%ds maxBatches=%s\n",
    getmypid(),
    $loopMode ? 'yes' : 'no',
    $dryRun ? 'yes' : 'no',
    $batchSize,
    $sleepSec,
    $maxBatches === PHP_INT_MAX ? '∞' : (string)$maxBatches
));

try {
    do {
        $stats = $dispatcher->runBatch();

        foreach ($stats as $key => $val) {
            $totalStats[$key] = ($totalStats[$key] ?? 0) + $val;
        }
        $batchesRun++;

        if ($stats['processed'] > 0 || $verbose) {
            fwrite(STDERR, sprintf(
                "[worker_webhooks] batch %d: processed=%d delivered=%d failed=%d dead=%d no_subs=%d\n",
                $batchesRun,
                $stats['processed'],
                $stats['delivered'],
                $stats['failed'],
                $stats['dead'],
                $stats['no_subscribers']
            ));
        }

        if ($shouldStop) {
            fwrite(STDERR, "[worker_webhooks] signal received — graceful shutdown\n");
            break;
        }

        if ($batchesRun >= $maxBatches) {
            break;
        }

        if ($loopMode) {
            // Jeśli poprzedni batch był pusty → idle sleep.
            // Jeśli był pełny (processed == batchSize) → 0.1s (chcemy chomikować dalej).
            $sleepMs = ($stats['processed'] === $batchSize) ? 100_000 : ($sleepSec * 1_000_000);
            usleep($sleepMs);
        }
    } while ($loopMode);

} catch (Throwable $e) {
    fwrite(STDERR, sprintf(
        "[worker_webhooks] FATAL exception: %s\n  in %s:%d\n  trace: %s\n",
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));
    exit(3);
}

// ── 7. Summary ───────────────────────────────────────────────────────────
$elapsed = time() - $startedAt;
fwrite(STDERR, sprintf(
    "[worker_webhooks] done. batches=%d elapsed=%ds totals: processed=%d delivered=%d failed=%d dead=%d no_subs=%d\n",
    $batchesRun,
    $elapsed,
    $totalStats['processed'],
    $totalStats['delivered'],
    $totalStats['failed'],
    $totalStats['dead'],
    $totalStats['no_subscribers']
));

exit(0);
