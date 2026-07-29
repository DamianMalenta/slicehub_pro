<?php

declare(strict_types=1);

/**
 * worker_sla_monitor — CLI cron script for SLA breach detection.
 *
 * Iterates over all tenants, checks active delivery orders against their
 * promised_time, and logs breaches to sh_sla_breaches (UPSERT — keeps
 * breach_minutes dynamic per poll).
 *
 * Logic mirrors api/orders/sla_monitor.php but without HTTP/auth —
 * this is a standalone CLI worker for cron.
 *
 * Usage: php scripts/worker_sla_monitor.php
 * Cron:  every 1-2 minutes
 */

require_once dirname(__DIR__) . '/core/db_config.php';
require_once dirname(__DIR__) . '/core/Uuid.php';
/** @var PDO $pdo */

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stats = [
    'tenants'   => 0,
    'orders'    => 0,
    'breached'  => 0,
    'upserted'  => 0,
    'failed'    => 0,
];

// ----------------------------------------------------------------------
// 1. Fetch all tenants
// ----------------------------------------------------------------------
$stmtTenants = $pdo->query("SELECT id FROM sh_tenant ORDER BY id ASC");
$tenantIds = $stmtTenants->fetchAll(PDO::FETCH_COLUMN);

if (!$tenantIds) {
    echo "[sla_monitor] no tenants found.\n";
    exit(0);
}

$tz = new DateTimeZone('Europe/Warsaw');
$now = new DateTime('now', $tz);
$nowStr = $now->format('Y-m-d H:i:s');

foreach ($tenantIds as $tid) {
    $tid = (int)$tid;
    $stats['tenants']++;

    // --------------------------------------------------------------
    // 2. Fetch active delivery orders with promised_time
    // --------------------------------------------------------------
    $stmtOrders = $pdo->prepare(
        "SELECT id, promised_time, driver_id, course_id
         FROM sh_orders
         WHERE tenant_id = :tid
           AND status NOT IN ('completed', 'cancelled')
           AND order_type = 'delivery'
           AND promised_time IS NOT NULL"
    );
    $stmtOrders->execute([':tid' => $tid]);
    $activeOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

    $breachedOrders = [];

    foreach ($activeOrders as $order) {
        $stats['orders']++;
        $promised = new DateTime($order['promised_time'], $tz);
        $diffMin  = (int)floor(($promised->getTimestamp() - $now->getTimestamp()) / 60);

        if ($diffMin <= 0) {
            $breachedOrders[] = [
                'order_id'  => $order['id'],
                'late_min'  => abs($diffMin),
                'driver_id' => $order['driver_id'],
                'course_id' => $order['course_id'],
            ];
            $stats['breached']++;
        }
    }

    // --------------------------------------------------------------
    // 4. Dynamic breach logging (UPSERT)
    // --------------------------------------------------------------
    if (count($breachedOrders) > 0) {
        $pdo->beginTransaction();
        try {
            $stmtBreach = $pdo->prepare(
                "INSERT INTO sh_sla_breaches
                    (id, tenant_id, order_id, breach_minutes, driver_id, course_id, logged_at)
                 VALUES
                    (:id, :tid, :oid, :late_min, :did, :cid, :now)
                 ON DUPLICATE KEY UPDATE
                    breach_minutes = VALUES(breach_minutes),
                    driver_id      = VALUES(driver_id),
                    course_id      = VALUES(course_id),
                    logged_at      = VALUES(logged_at)"
            );

            foreach ($breachedOrders as $b) {
                $stmtBreach->execute([
                    ':id'       => \Uuid::v4(),
                    ':tid'      => $tid,
                    ':oid'      => $b['order_id'],
                    ':late_min' => $b['late_min'],
                    ':did'      => $b['driver_id'],
                    ':cid'      => $b['course_id'],
                    ':now'      => $nowStr,
                ]);
                $stats['upserted']++;
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $stats['failed']++;
            error_log('[sla_monitor] tenant ' . $tid . ': ' . $e->getMessage());
        }
    }
}

echo "[sla_monitor] done: " . json_encode($stats) . "\n";
