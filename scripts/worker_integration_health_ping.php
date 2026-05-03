#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cron: sandbox ping wszystkich aktywnych integracji (sh_tenant_integrations).
 * Sugestia crontab (co godzinę): 0 * * * * php /path/to/scripts/worker_integration_health_ping.php
 *
 * Opcje CLI:
 *   --tenant=ID   tylko jeden tenant
 *   --quiet       bez stdout (tylko exit code / error_log przy fail)
 *
 * Exit: 0 = OK, 1 = przynajmniej jeden ping failed lub wyjątek.
 */

$opts = getopt('', ['tenant::', 'quiet']);
$onlyTenant = isset($opts['tenant']) ? max(0, (int)$opts['tenant']) : 0;
$quiet = isset($opts['quiet']);

require_once __DIR__ . '/../core/db_config.php';
require_once __DIR__ . '/../core/CredentialVault.php';
require_once __DIR__ . '/../core/Integrations/BaseAdapter.php';
require_once __DIR__ . '/../core/Integrations/PapuAdapter.php';
require_once __DIR__ . '/../core/Integrations/DotykackaAdapter.php';
require_once __DIR__ . '/../core/Integrations/GastroSoftAdapter.php';
require_once __DIR__ . '/../core/Integrations/AdapterRegistry.php';
require_once __DIR__ . '/../core/SettingsPingLib.php';

if (!isset($pdo)) {
    fwrite(STDERR, "[integration_health_ping] No DB connection.\n");
    exit(1);
}

$sql = "SELECT id, tenant_id, provider, display_name, api_base_url,
               credentials, direction, events_bridged, is_active,
               COALESCE(timeout_seconds, 8) AS timeout_seconds,
               COALESCE(max_retries, 6) AS max_retries
        FROM sh_tenant_integrations
        WHERE is_active = 1";
$params = [];
if ($onlyTenant > 0) {
    $sql .= ' AND tenant_id = :tid';
    $params[':tid'] = $onlyTenant;
}

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('[integration_health_ping] query failed: ' . $e->getMessage());
    exit(1);
}

$anyFail = false;
foreach ($rows as $row) {
    $tid = (int)$row['tenant_id'];
    $iid = (int)$row['id'];
    $report = SettingsPingLib::integrationPing($row);
    $ok = !empty($report['ok']);
    if (!$ok) {
        $anyFail = true;
        $msg = sprintf(
            '[integration_health_ping] FAIL tenant=%d integration=%d provider=%s stage=%s http=%s err=%s',
            $tid,
            $iid,
            (string)$row['provider'],
            (string)($report['stage'] ?? '?'),
            isset($report['http_code']) ? (string)$report['http_code'] : '-',
            (string)($report['message'] ?? $report['error'] ?? $report['transport_error'] ?? 'unknown')
        );
        error_log($msg);
    } elseif (!$quiet) {
        printf(
            "OK tenant=%d integration=%d provider=%s http=%s ms=%s\n",
            $tid,
            $iid,
            (string)$row['provider'],
            (string)($report['http_code'] ?? '-'),
            (string)($report['duration_ms'] ?? '?')
        );
    }
}

exit($anyFail ? 1 : 0);
