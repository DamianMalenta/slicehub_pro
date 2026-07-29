<?php

declare(strict_types=1);

/**
 * SLA Thresholds — SSOT pobierania progów SLA z sh_tenant_settings.
 *
 * Prymityw infrastrukturalny (jak `Uuid`, `Money`, `DriverFleetHelper`),
 * NIE silnik domenowy — `require_once` z dowolnego silosu (courses, kds,
 * pos, orders) NIE narusza izolacji z `_docs/18_BACKOFFICE_HR_LOGIC.md §9`.
 *
 * Zastąpił 5 powielonych kopii tego samego SELECT-a (api/courses/engine.php
 * ×2, api/kds/engine.php, api/pos/engine.php, api/orders/sla_monitor.php).
 *
 * Schema: sh_tenant_settings (kolumny sla_green_min, sla_yellow_min,
 * wiersz z setting_key=''). Default 10/5 zgodny z 001_init_slicehub_pro_v2.sql
 * i api/orders/sla_monitor.php.
 *
 * @return array{green_min:int, yellow_min:int}
 */
function slicehubSlaThresholds(PDO $pdo, int $tenantId): array
{
    if ($tenantId <= 0) {
        return ['green_min' => 10, 'yellow_min' => 5];
    }
    $stmt = $pdo->prepare(
        "SELECT sla_green_min, sla_yellow_min
         FROM sh_tenant_settings
         WHERE tenant_id = :tid AND setting_key = ''
         LIMIT 1"
    );
    $stmt->execute([':tid' => $tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'green_min'  => (int)($row['sla_green_min']  ?? 10),
        'yellow_min' => (int)($row['sla_yellow_min'] ?? 5),
    ];
}
