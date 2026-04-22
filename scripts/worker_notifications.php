<?php
declare(strict_types=1);

/**
 * SliceHub Notification Worker — CLI dispatcher powiadomień klientów.
 *
 * ── Usage ────────────────────────────────────────────────────────────────
 *
 *   Single batch (cron-friendly):
 *     php scripts/worker_notifications.php
 *
 *   Continuous loop:
 *     php scripts/worker_notifications.php --loop --sleep=5
 *
 *   Verbose (debug):
 *     php scripts/worker_notifications.php -v
 *
 * ── Cron example (every minute) ──────────────────────────────────────────
 *
 *   * * * * * cd /var/www/slicehub && /usr/bin/php scripts/worker_notifications.php >> logs/notifications.log 2>&1
 *
 * ── Exit codes ───────────────────────────────────────────────────────────
 *
 *   0 — OK
 *   1 — DB connection failed
 *   2 — Another instance running
 *   3 — Uncaught exception
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

// ── Args ────────────────────────────────────────────────────────────────
$opts    = getopt('v', ['loop', 'sleep:', 'dry-run', 'base-url:']);
$loop    = isset($opts['loop']);
$sleep   = max(1, (int)($opts['sleep'] ?? 5));
$verbose = isset($opts['v']);
$dryRun  = isset($opts['dry-run']);
$baseUrl = (string)($opts['base-url'] ?? getenv('SLICEHUB_BASE_URL') ?: 'http://localhost/slicehub');

$root = dirname(__DIR__);

// ── PID lock ────────────────────────────────────────────────────────────
$pidFile = sys_get_temp_dir() . '/slicehub_worker_notifications.pid';
if (file_exists($pidFile)) {
    $oldPid = (int)file_get_contents($pidFile);
    if ($oldPid > 0 && function_exists('posix_kill') && posix_kill($oldPid, 0)) {
        fwrite(STDERR, "[NotifWorker] Another instance running (PID {$oldPid}). Exiting.\n");
        exit(2);
    }
}
file_put_contents($pidFile, getmypid());

// ── Bootstrap ───────────────────────────────────────────────────────────
try {
    require_once $root . '/core/db_config.php';
    require_once $root . '/core/Notifications/DeliveryResult.php';
    require_once $root . '/core/Notifications/ChannelInterface.php';
    require_once $root . '/core/Notifications/ChannelRegistry.php';
    require_once $root . '/core/Notifications/TemplateRenderer.php';
    require_once $root . '/core/Notifications/NotificationDispatcher.php';
    require_once $root . '/core/CustomerContactRepository.php';
} catch (\Throwable $e) {
    fwrite(STDERR, "[NotifWorker] Bootstrap failed: {$e->getMessage()}\n");
    @unlink($pidFile);
    exit(1);
}

if (!isset($pdo)) {
    fwrite(STDERR, "[NotifWorker] \$pdo not available after db_config.php\n");
    @unlink($pidFile);
    exit(1);
}

// ── Run ─────────────────────────────────────────────────────────────────
$log = function (string $msg) use ($verbose): void {
    if ($verbose) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    }
};

register_shutdown_function(function () use ($pidFile) {
    @unlink($pidFile);
});

try {
    $dispatcher = new NotificationDispatcher($pdo, $baseUrl, $verbose);

    do {
        $processed = $dispatcher->processBatch();
        $log("Processed: {$processed} events");

        if ($loop && $processed === 0) {
            sleep($sleep);
        }
    } while ($loop);

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[NotifWorker] Fatal: {$e->getMessage()}\n{$e->getTraceAsString()}\n");
    exit(3);
}
