<?php

declare(strict_types=1);

/**
 * worker_driver_fanout — konsument eventów shift → status kierowcy.
 *
 * Kontekst (patrz _docs/18_BACKOFFICE_HR_LOGIC.md §6, HR-4):
 *   - HrClockEngine emituje `employee.clocked_in` / `employee.clocked_out`
 *     do `sh_event_outbox` (aggregate_type='shift').
 *   - Ten worker subskrybuje te eventy i — POD FEATURE FLAG
 *     `HR_USE_EVENT_DRIVER_FANOUT` — fluktuuje `sh_drivers.status`.
 *   - Dzięki temu HR nie robi synchronicznego cross-silo write do Logistyki.
 *
 * CROSS-SILO REGUŁA (patrz _docs/18 §9):
 *   Ten worker jest PO STRONIE LOGISTYKI (zmienia `sh_drivers`). Czyta z
 *   outboxa (shared infra), nie wywołuje bezpośrednio żadnego Engine-a HR.
 *
 * Polityka aktualizacji `sh_drivers.status`:
 *   - `employee.clocked_in`  + primary_role=driver + driver.status in ('offline')
 *       → SET status='available'
 *   - `employee.clocked_out` + primary_role=driver + driver.status in ('available')
 *       → SET status='offline'
 *   - driver.status='busy' NIGDY nie jest zmieniany przez tego workera —
 *     kierowca w trasie kończy kurs niezależnie od clock-out.
 *
 * Feature flag (sh_tenant_settings):
 *   tenant_id + setting_key='HR_USE_EVENT_DRIVER_FANOUT' + setting_value='1' → ON
 *   brak wpisu lub setting_value != '1' → OFF (default)
 *
 * Uruchomienie: cron, np. co 30s / 1 min.
 *   php scripts/worker_driver_fanout.php [--batch=50]
 */

require_once dirname(__DIR__) . '/core/db_config.php';
/** @var PDO $pdo */

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ----------------------------------------------------------------------
// Konfiguracja
// ----------------------------------------------------------------------
$batchSize = 50;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--batch=(\d+)$/', $arg, $m)) {
        $batchSize = max(1, min(500, (int)$m[1]));
    }
}

$FF_KEY = 'HR_USE_EVENT_DRIVER_FANOUT';
$HANDLED_EVENT_TYPES = ['employee.clocked_in', 'employee.clocked_out'];
$HANDLED_AGGREGATE_TYPE = 'shift';
$MAX_ATTEMPTS = 5;

$stats = [
    'processed'   => 0,
    'skipped_ff'  => 0,
    'skipped_role'=> 0,
    'updated'     => 0,
    'no_change'   => 0,
    'failed'      => 0,
];

// ----------------------------------------------------------------------
// Per-request cache: tenant_id -> bool (FF value)
// ----------------------------------------------------------------------
$flagCache = [];

function isFanoutEnabled(PDO $pdo, int $tenantId, string $ffKey, array &$cache): bool
{
    if (isset($cache[$tenantId])) return $cache[$tenantId];

    $stmt = $pdo->prepare("
        SELECT setting_value FROM sh_tenant_settings
        WHERE tenant_id = :tid AND setting_key = :k
        LIMIT 1
    ");
    $stmt->execute([':tid' => $tenantId, ':k' => $ffKey]);
    $v = $stmt->fetchColumn();
    $on = ($v === '1' || strtolower((string)$v) === 'true' || strtolower((string)$v) === 'on');
    $cache[$tenantId] = $on;
    return $on;
}

function markEventOutcome(PDO $pdo, int $eventId, string $finalStatus, ?string $error, int $attempts): void
{
    $stmt = $pdo->prepare("
        UPDATE sh_event_outbox
           SET status = :st,
               attempts = :at,
               last_error = :err,
               completed_at = CASE WHEN :st2 = 'delivered' THEN NOW() ELSE completed_at END,
               dispatched_at = IFNULL(dispatched_at, NOW())
         WHERE id = :id
    ");
    $stmt->execute([
        ':st'  => $finalStatus,
        ':st2' => $finalStatus,
        ':at'  => $attempts,
        ':err' => $error,
        ':id'  => $eventId,
    ]);
}

// ----------------------------------------------------------------------
// Claim: SELECT + UPDATE pending → dispatching (atomic per row)
// ----------------------------------------------------------------------
$placeholders = implode(',', array_fill(0, count($HANDLED_EVENT_TYPES), '?'));

$selectStmt = $pdo->prepare("
    SELECT id, tenant_id, event_type, aggregate_id, payload, attempts
      FROM sh_event_outbox
     WHERE status = 'pending'
       AND aggregate_type = ?
       AND event_type IN ($placeholders)
       AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
     ORDER BY id ASC
     LIMIT $batchSize
");
$params = array_merge([$HANDLED_AGGREGATE_TYPE], $HANDLED_EVENT_TYPES);
$selectStmt->execute($params);
$events = $selectStmt->fetchAll(\PDO::FETCH_ASSOC);

if (!$events) {
    echo "[driver_fanout] no pending shift events.\n";
    exit(0);
}

foreach ($events as $ev) {
    $eventId  = (int)$ev['id'];
    $tenantId = (int)$ev['tenant_id'];
    $eType    = (string)$ev['event_type'];
    $attempts = (int)$ev['attempts'] + 1;

    // Przejście do 'dispatching' — claim (ochrona przed dwoma workerami)
    $claim = $pdo->prepare("
        UPDATE sh_event_outbox
           SET status = 'dispatching', dispatched_at = NOW(), attempts = :at
         WHERE id = :id AND status = 'pending'
    ");
    $claim->execute([':at' => $attempts, ':id' => $eventId]);
    if ($claim->rowCount() === 0) continue; // ktoś inny nas wyprzedził

    $stats['processed']++;

    try {
        // 1) Feature flag check — per-tenant
        if (!isFanoutEnabled($pdo, $tenantId, $FF_KEY, $flagCache)) {
            markEventOutcome($pdo, $eventId, 'delivered', 'FF off — no-op', $attempts);
            $stats['skipped_ff']++;
            continue;
        }

        // 2) Decode payload
        $payload = json_decode((string)$ev['payload'], true);
        if (!is_array($payload)) {
            throw new \RuntimeException('INVALID_PAYLOAD_JSON');
        }

        $role      = (string)($payload['primary_role'] ?? '');
        $employeeId= (int)($payload['employee_id'] ?? 0);

        // 3) Tylko kierowcy — reszta to no-op
        if ($role !== 'driver') {
            markEventOutcome($pdo, $eventId, 'delivered', 'not a driver — no-op', $attempts);
            $stats['skipped_role']++;
            continue;
        }

        if ($employeeId <= 0) {
            throw new \RuntimeException('PAYLOAD_MISSING_EMPLOYEE_ID');
        }

        // 4) Pobierz user_id pracownika (employee_id → user_id, w tym tenancie)
        $uStmt = $pdo->prepare("
            SELECT user_id FROM sh_employees
            WHERE id = :eid AND tenant_id = :tid AND is_deleted = 0
            LIMIT 1
        ");
        $uStmt->execute([':eid' => $employeeId, ':tid' => $tenantId]);
        $userId = (int)($uStmt->fetchColumn() ?: 0);
        if ($userId <= 0) {
            // Employee nie ma powiązanego user_id — to legalne (kontraktor bez konta),
            // ale wtedy nie ma też wpisu w sh_drivers. Event zaliczony jako no-op.
            markEventOutcome($pdo, $eventId, 'delivered', 'employee has no user_id — no-op', $attempts);
            $stats['skipped_role']++;
            continue;
        }

        // 5) Update sh_drivers z policy
        //    clock_in:  offline → available   (NIE dotykamy busy/available)
        //    clock_out: available → offline   (NIE dotykamy busy → kończy kurs)
        if ($eType === 'employee.clocked_in') {
            $upd = $pdo->prepare("
                UPDATE sh_drivers
                   SET status = 'available'
                 WHERE tenant_id = :tid AND user_id = :uid
                   AND status IN ('offline')
            ");
            $upd->execute([':tid' => $tenantId, ':uid' => $userId]);
            $changed = $upd->rowCount();
            if ($changed > 0) { $stats['updated']++; } else { $stats['no_change']++; }
        } elseif ($eType === 'employee.clocked_out') {
            $upd = $pdo->prepare("
                UPDATE sh_drivers
                   SET status = 'offline'
                 WHERE tenant_id = :tid AND user_id = :uid
                   AND status IN ('available')
            ");
            $upd->execute([':tid' => $tenantId, ':uid' => $userId]);
            $changed = $upd->rowCount();
            if ($changed > 0) { $stats['updated']++; } else { $stats['no_change']++; }
        } else {
            throw new \RuntimeException('UNHANDLED_EVENT_TYPE');
        }

        markEventOutcome($pdo, $eventId, 'delivered', null, $attempts);
    } catch (\Throwable $e) {
        $stats['failed']++;
        $isDead = $attempts >= $MAX_ATTEMPTS;
        $errTxt = $e->getMessage();
        if (!$isDead) {
            // Retry z backoff: next_attempt_at = NOW() + attempts * 60s
            $r = $pdo->prepare("
                UPDATE sh_event_outbox
                   SET status = 'pending',
                       last_error = :err,
                       next_attempt_at = DATE_ADD(NOW(), INTERVAL :delay SECOND)
                 WHERE id = :id
            ");
            $r->execute([
                ':err'   => $errTxt,
                ':delay' => $attempts * 60,
                ':id'    => $eventId,
            ]);
        } else {
            markEventOutcome($pdo, $eventId, 'dead', 'MAX_ATTEMPTS: ' . $errTxt, $attempts);
        }
    }
}

echo "[driver_fanout] done: " . json_encode($stats) . "\n";
