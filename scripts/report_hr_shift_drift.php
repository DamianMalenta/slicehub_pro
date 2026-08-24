<?php

declare(strict_types=1);

/**
 * report_hr_shift_drift — read-only raport rozjazdów shift kasowy ↔ sesja HR.
 *
 * Kontekst (patrz _docs/18_BACKOFFICE_HR_LOGIC.md §9.3):
 *   - Korelacja odbywa się przez sh_driver_shifts.work_session_uuid
 *     = sh_work_sessions.session_uuid (klucz VARCHAR, bez FK cross-silo).
 *   - Raport jest read-only (dozwolony cross-silo SELECT), niczego nie mutuje.
 *
 * Wykrywane rozjazdy (per tenant):
 *   A) aktywny shift kasowy bez powiązanej otwartej sesji HR,
 *   B) otwarta sesja HR kierowcy bez aktywnego shiftu kasowego.
 *
 * Uruchomienie: php scripts/report_hr_shift_drift.php [--tenant=1]
 */

require_once dirname(__DIR__) . '/core/db_config.php';
/** @var PDO $pdo */

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tenantFilter = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--tenant=(\d+)$/', $arg, $m)) {
        $tenantFilter = (int)$m[1];
    }
}

$tenantSql   = $tenantFilter !== null ? ' AND ds.tenant_id = :tid' : '';
$tenantSqlWs = $tenantFilter !== null ? ' AND ws.tenant_id = :tid' : '';

// A) Aktywny shift kasowy bez otwartej sesji HR
$sqlShiftNoHr = "
    SELECT ds.tenant_id, ds.driver_id, ds.id AS shift_id,
           ds.work_session_uuid, ds.created_at
    FROM sh_driver_shifts ds
    WHERE ds.status = 'active'
      AND (
            ds.work_session_uuid IS NULL
            OR NOT EXISTS (
                SELECT 1 FROM sh_work_sessions ws
                WHERE ws.tenant_id = ds.tenant_id
                  AND ws.session_uuid = ds.work_session_uuid
                  AND ws.end_time IS NULL
            )
          )
      {$tenantSql}
    ORDER BY ds.tenant_id, ds.created_at
";

// B) Otwarta sesja HR kierowcy bez aktywnego shiftu kasowego
$sqlHrNoShift = "
    SELECT ws.tenant_id, ws.employee_id, ws.session_uuid, ws.start_time
    FROM sh_work_sessions ws
    JOIN sh_employees e
      ON e.id = ws.employee_id AND e.tenant_id = ws.tenant_id
    WHERE ws.end_time IS NULL
      AND e.primary_role = 'driver'
      AND e.user_id IS NOT NULL
      AND NOT EXISTS (
            SELECT 1 FROM sh_driver_shifts ds
            WHERE ds.tenant_id = ws.tenant_id
              AND ds.driver_id = CAST(e.user_id AS CHAR) COLLATE utf8mb4_unicode_ci
              AND ds.status = 'active'
      )
      {$tenantSqlWs}
    ORDER BY ws.tenant_id, ws.start_time
";

$params = $tenantFilter !== null ? [':tid' => $tenantFilter] : [];

$stmtA = $pdo->prepare($sqlShiftNoHr);
$stmtA->execute($params);
$shiftNoHr = $stmtA->fetchAll(PDO::FETCH_ASSOC);

$stmtB = $pdo->prepare($sqlHrNoShift);
$stmtB->execute($params);
$hrNoShift = $stmtB->fetchAll(PDO::FETCH_ASSOC);

echo '[hr_shift_drift] A) aktywne shifty bez otwartej sesji HR: ' . count($shiftNoHr) . PHP_EOL;
foreach ($shiftNoHr as $row) {
    echo sprintf(
        "  tenant=%d driver=%s shift_id=%d uuid=%s od=%s\n",
        (int)$row['tenant_id'],
        (string)$row['driver_id'],
        (int)$row['shift_id'],
        $row['work_session_uuid'] !== null ? (string)$row['work_session_uuid'] : '-',
        (string)$row['created_at']
    );
}

echo '[hr_shift_drift] B) otwarte sesje HR kierowców bez aktywnego shiftu: ' . count($hrNoShift) . PHP_EOL;
foreach ($hrNoShift as $row) {
    echo sprintf(
        "  tenant=%d employee=%d session=%s od=%s\n",
        (int)$row['tenant_id'],
        (int)$row['employee_id'],
        (string)$row['session_uuid'],
        (string)$row['start_time']
    );
}

exit(count($shiftNoHr) + count($hrNoShift) > 0 ? 1 : 0);
