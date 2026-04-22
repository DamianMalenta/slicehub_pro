<?php
declare(strict_types=1);

/**
 * SliceHub — Reorder Nudge Cron.
 *
 * Działa raz dziennie (np. w piątek wieczór lub codziennie o 17:00).
 * Szuka klientów z rytmem zamawiania (np. "co piątek") którzy dziś są
 * "zaległą pizzą" — i wysyła SMS-a przez personal_phone lub sms_gateway.
 *
 * Logika:
 *   1. Pobierz kontakty z order_count >= 3 i last_order_at >= 7 dni temu
 *   2. Dla każdego sprawdź historię zamówień — czy jest wzorzec tygodniowy
 *      (zamawia w DOW = dzisiaj, co tydzień przez co najmniej 3 tygodnie)
 *   3. Jeśli wzorzec → sprawdź czy dziś jeszcze nie zamówił
 *   4. Wyślij nudge przez sh_event_outbox (marketing.campaign event)
 *
 * Cron: 0 17 * * 5  php scripts/cron_reorder_nudge.php  (piątki o 17:00)
 * lub:  0 17 * * *   php scripts/cron_reorder_nudge.php  (codziennie)
 *
 * Wymaga:
 *   - sh_customer_contacts (z sms_consent = 1 i marketing_consent = 1)
 *   - sh_orders (historia zamówień po customer_phone)
 *   - sh_notification_channels (personal_phone lub sms_gateway aktywny)
 *   - szablon 'reorder.nudge' w sh_notification_templates
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403); exit('CLI only.');
}

$opts    = getopt('v', ['dry-run', 'tenant:', 'dow:']);
$verbose = isset($opts['v']);
$dryRun  = isset($opts['dry-run']);
$forceTenantId = isset($opts['tenant']) ? (int)$opts['tenant'] : null;
$forceDow      = isset($opts['dow'])    ? (int)$opts['dow']    : null; // 0=Nie, 1=Pon, ..., 5=Pią, 6=Sob

$root = dirname(__DIR__);
require_once $root . '/core/db_config.php';

if (!isset($pdo)) { fwrite(STDERR, "PDO not available\n"); exit(1); }

$log = function (string $msg) use ($verbose): void {
    if ($verbose) echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
};

// Dzisiejszy DOW (1=Pon..7=Nie wg PHP date('N'))
$todayDow = $forceDow ?? (int)date('N'); // 1=Mon, 5=Fri, 7=Sun

$log("Reorder Nudge Cron — DOW={$todayDow}, dryRun=" . ($dryRun ? 'YES' : 'NO'));

// Pobierz aktywnych tenantów
try {
    $tenantsStmt = $pdo->query("SELECT id FROM sh_tenant LIMIT 50");
    $tenants = $tenantsStmt->fetchAll(\PDO::FETCH_COLUMN);
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot fetch tenants: " . $e->getMessage() . "\n");
    exit(1);
}

if ($forceTenantId) {
    $tenants = [$forceTenantId];
}

$totalNudged = 0;

foreach ($tenants as $tenantId) {
    $tenantId = (int)$tenantId;
    $log("Processing tenant {$tenantId}...");

    // Pobierz szablon nudge
    try {
        $tplStmt = $pdo->prepare(
            "SELECT id, body FROM sh_notification_templates
             WHERE tenant_id = :tid AND event_type = 'reorder.nudge' AND is_active = 1
             LIMIT 1"
        );
        $tplStmt->execute([':tid' => $tenantId]);
        $tpl = $tplStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$tpl) {
            $log("  No template 'reorder.nudge' for tenant {$tenantId} — skipping");
            continue;
        }
    } catch (\Throwable $e) {
        $log("  Template error: " . $e->getMessage());
        continue;
    }

    // Pobierz kontakty kwalifikujące się
    try {
        $cStmt = $pdo->prepare(
            "SELECT id, phone, name, last_order_at, order_count
             FROM sh_customer_contacts
             WHERE tenant_id = :tid
               AND sms_consent = 1
               AND marketing_consent = 1
               AND (sms_optout_at IS NULL OR sms_optout_at < COALESCE(updated_at, created_at))
               AND order_count >= 3
               AND last_order_at >= DATE_SUB(NOW(), INTERVAL 35 DAY)
               AND last_order_at < DATE_SUB(NOW(), INTERVAL 6 DAY)
             LIMIT 500"
        );
        $cStmt->execute([':tid' => $tenantId]);
        $contacts = $cStmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $log("  Contacts error: " . $e->getMessage());
        continue;
    }

    $log("  Candidate contacts: " . count($contacts));

    foreach ($contacts as $contact) {
        // Sprawdź wzorzec tygodniowy — czy zamawiał w dzisiejszy DOW przez 3+ tygodnie
        try {
            $histStmt = $pdo->prepare(
                "SELECT DATE(created_at) AS ord_date, DAYOFWEEK(created_at) AS dow_mysql
                 FROM sh_orders
                 WHERE tenant_id = :tid AND customer_phone = :phone
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                 ORDER BY created_at DESC
                 LIMIT 20"
            );
            $histStmt->execute([':tid' => $tenantId, ':phone' => $contact['phone']]);
            $history = $histStmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            continue;
        }

        if (empty($history)) continue;

        // MySQL DAYOFWEEK: 1=Sun, 2=Mon, ..., 7=Sat. PHP date('N'): 1=Mon..7=Sun
        // Konwersja: MySQL DOW = (PHP DOW % 7) + 1
        $mysqlDow = ($todayDow % 7) + 1;

        $matchDays = array_filter($history, fn($h) => (int)$h['dow_mysql'] === $mysqlDow);

        // Minimum 2 poprzednie zamówienia w ten dzień tygodnia
        if (count($matchDays) < 2) continue;

        // Sprawdź czy dzisiaj już zamówił
        try {
            $todayStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM sh_orders
                 WHERE tenant_id = :tid AND customer_phone = :phone AND DATE(created_at) = CURDATE()"
            );
            $todayStmt->execute([':tid' => $tenantId, ':phone' => $contact['phone']]);
            if ((int)$todayStmt->fetchColumn() > 0) {
                $log("  Skip {$contact['phone']} — already ordered today");
                continue;
            }
        } catch (\Throwable $e) { continue; }

        $log("  Nudge: {$contact['phone']} (pattern match, {$matchDays} days this DOW)");

        if (!$dryRun) {
            // Rozlej event do outboxa
            $storeName = '';
            try {
                $snStmt = $pdo->prepare(
                    "SELECT setting_value FROM sh_tenant_settings WHERE tenant_id=:tid AND setting_key='storefront_name' LIMIT 1"
                );
                $snStmt->execute([':tid' => $tenantId]);
                $storeName = (string)$snStmt->fetchColumn();
            } catch (\Throwable $e) {}

            $nudgeBody = str_replace(
                ['{{customer_name}}', '{{store_name}}'],
                [$contact['name'] ?: 'Kliencie', $storeName ?: 'Restauracja'],
                $tpl['body']
            );

            $payload = [
                'customer_phone'    => $contact['phone'],
                'customer_name'     => $contact['name'],
                'sms_consent'       => true,
                'marketing_consent' => true,
                'nudge_body'        => $nudgeBody,
                '_meta' => ['event_type' => 'reorder.nudge', 'published_at' => date('c')],
            ];

            $idk = "nudge:{$tenantId}:{$contact['id']}:" . date('Y-m-d');
            try {
                $pdo->prepare(
                    "INSERT IGNORE INTO sh_event_outbox
                        (tenant_id, event_type, aggregate_type, aggregate_id,
                         idempotency_key, payload, source, actor_type, actor_id,
                         status, attempts, created_at)
                     VALUES (:tid, 'marketing.campaign', 'nudge', :cid, :idk, :pl, 'cron', 'system', 'nudge_cron', 'pending', 0, NOW())"
                )->execute([
                    ':tid' => $tenantId,
                    ':cid' => (string)$contact['id'],
                    ':idk' => $idk,
                    ':pl'  => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ]);
                $totalNudged++;
            } catch (\Throwable $e) {
                $log("  DB error: " . $e->getMessage());
            }
        }
    }
}

$log("Done. Total nudges queued: {$totalNudged}");
exit(0);
