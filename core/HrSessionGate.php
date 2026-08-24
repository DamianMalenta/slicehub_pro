<?php

declare(strict_types=1);

/**
 * HrSessionGate — miękki gate „aktywna sesja HR" dla dyspozytorni (silos Logistyki).
 *
 * CROSS-SILO REGUŁA (_docs/18_BACKOFFICE_HR_LOGIC.md §9.3):
 *   Wyłącznie read-only SELECT na sh_work_sessions/sh_employees (lustrzany
 *   odpowiednik HrClockEngine::driverBusy po stronie HR). Zero cross-silo write,
 *   zero require_once HrClockEngine.
 *
 * Feature flag (sh_tenant_settings):
 *   tenant_id + setting_key='HR_REQUIRE_OPEN_SESSION_FOR_DISPATCH'
 *   + setting_value='1' → ON; brak wpisu lub inna wartość → OFF (default).
 *
 * Tryb: MIĘKKI — brak twardej blokady; wynik służy do badge/alertu managera.
 * Grace window: shift kasowy młodszy niż 5 min przechodzi bez sesji HR
 * (chroni przed race z best-effort clock-in z mobilki).
 */

function slicehubHrGateGraceSeconds(): int
{
    return 300;
}

function slicehubHrGateEnabled(PDO $pdo, int $tenantId): bool
{
    $stmt = $pdo->prepare(
        "SELECT setting_value FROM sh_tenant_settings
         WHERE tenant_id = :tid AND setting_key = 'HR_REQUIRE_OPEN_SESSION_FOR_DISPATCH'
         LIMIT 1"
    );
    $stmt->execute([':tid' => $tenantId]);
    $v = $stmt->fetchColumn();
    return $v === '1' || strtolower((string)$v) === 'true' || strtolower((string)$v) === 'on';
}

/**
 * Point-lookup po uq_ws_single_open / idx_ws_employee — O(1).
 * true, gdy kierowca (user_id) ma otwartą sesję HR
 * LUB aktywny shift kasowy młodszy niż grace window.
 */
function slicehubDriverHrSessionOk(PDO $pdo, int $tenantId, int $driverUserId): bool
{
    if ($tenantId <= 0 || $driverUserId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM sh_work_sessions ws
         JOIN sh_employees e
           ON e.id = ws.employee_id AND e.tenant_id = ws.tenant_id
         WHERE ws.tenant_id = :tid
           AND e.user_id = :uid
           AND e.is_deleted = 0
           AND ws.end_time IS NULL
         LIMIT 1"
    );
    $stmt->execute([':tid' => $tenantId, ':uid' => $driverUserId]);
    if ($stmt->fetchColumn()) {
        return true;
    }

    $grace = slicehubHrGateGraceSeconds();
    $stmtG = $pdo->prepare(
        "SELECT 1 FROM sh_driver_shifts
         WHERE tenant_id = :tid AND driver_id = :did AND status = 'active'
           AND created_at >= DATE_SUB(NOW(), INTERVAL " . (int)$grace . " SECOND)
         LIMIT 1"
    );
    $stmtG->execute([':tid' => $tenantId, ':did' => (string)$driverUserId]);
    return (bool)$stmtG->fetchColumn();
}
