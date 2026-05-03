<?php

declare(strict_types=1);

/**
 * worker_payroll_accrual — accrual zarobków z zamkniętych sesji pracy.
 *
 * Kontekst (patrz _docs/18_BACKOFFICE_HR_LOGIC.md §3.5):
 *   - HrClockEngine emituje `employee.clocked_out` do `sh_event_outbox` w momencie zamknięcia sesji.
 *   - Ten worker konsumuje te eventy i tworzy wpisy `work_earnings` w `sh_payroll_ledger`.
 *   - Ledger trzyma SIGNED kwoty w groszach — accrual dodaje do kolumny earnings (+).
 *
 * ŚWIĘTOŚĆ PIENIĄDZA:
 *   - `total_hours DECIMAL(10,4)` × `rate_amount_minor INT` = `earnings_minor` (int grosze).
 *   - Obliczenia na intach z rounding HALF_UP (`intdiv(x + half, scale)`).
 *   - Zero floats w pipeline wynagrodzeniowym.
 *
 * RESOLVER STAWKI (w tej kolejności):
 *   1. `rate_at_clock_in` z payloadu eventu (snapshot w momencie clock_in).
 *   2. Lookup w `sh_employee_rates` po `start_time` sesji (temporalna stawka aktywna).
 *   3. Brak → event delivered jako no-op (pracownik może nie być rozliczany godzinowo,
 *      np. owner, B2B kontraktor — to NIE jest błąd).
 *
 * IDEMPOTENCY:
 *   - `entry_uuid` ledgera = `session_uuid` sesji. Retry eventu → ledger zwraca istniejące id.
 *   - Okres rozliczeniowy wpisu: `start_time.year/month` (HR-6 midnight-crossing allocation —
 *     TODO Faza 4: `fn_allocate_hours` dla rozbicia sesji na 2 okresy).
 *
 * CROSS-SILO: worker czyta outbox (shared infra) + `sh_work_sessions` + `sh_employee_rates`
 * (tego samego silosu) + pisze do `sh_payroll_ledger` (tego samego silosu). Zero naruszenia §9.
 *
 * Uruchomienie: cron, np. co 1-5 min.
 *   php scripts/worker_payroll_accrual.php [--batch=50]
 */

require_once dirname(__DIR__) . '/core/db_config.php';
require_once dirname(__DIR__) . '/core/PayrollLedger.php';
/** @var PDO $pdo */

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ----------------------------------------------------------------------
$batchSize = 50;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--batch=(\d+)$/', $arg, $m)) {
        $batchSize = max(1, min(500, (int)$m[1]));
    }
}

$HANDLED_EVENT_TYPE     = 'employee.clocked_out';
$HANDLED_AGGREGATE_TYPE = 'shift';
$MAX_ATTEMPTS           = 5;
$HOURS_SCALE            = 10000;           // DECIMAL(10,4) → *10000 → int milli-hours
$HALF_UP_OFFSET         = (int)($HOURS_SCALE / 2);

$stats = [
    'processed'     => 0,
    'accrued'       => 0,
    'skipped_zero_hours' => 0,
    'skipped_no_rate'    => 0,
    'failed'        => 0,
];

// ----------------------------------------------------------------------
// Helpery
// ----------------------------------------------------------------------

/**
 * Resolve hourly rate dla sesji.
 *
 * @return array{amount_minor:int, currency:string, source:string}|null
 */
function resolveRate(PDO $pdo, int $tenantId, int $employeeId, string $startTime, array $payload): ?array
{
    // 1) Z payloadu eventu
    if (isset($payload['rate_at_clock_in']) && is_int($payload['rate_at_clock_in']) && $payload['rate_at_clock_in'] > 0) {
        $currency = isset($payload['rate_currency']) && is_string($payload['rate_currency']) && preg_match('/^[A-Z]{3}$/', $payload['rate_currency'])
            ? $payload['rate_currency']
            : 'PLN';
        return [
            'amount_minor' => (int)$payload['rate_at_clock_in'],
            'currency'     => $currency,
            'source'       => 'event_snapshot',
        ];
    }

    // 2) Temporal lookup w sh_employee_rates
    $stmt = $pdo->prepare("
        SELECT amount_minor, currency
          FROM sh_employee_rates
         WHERE tenant_id = :tid
           AND employee_id = :eid
           AND rate_type = 'hourly'
           AND effective_from <= :st
           AND (effective_to IS NULL OR effective_to > :st)
         ORDER BY effective_from DESC
         LIMIT 1
    ");
    $stmt->execute([':tid' => $tenantId, ':eid' => $employeeId, ':st' => $startTime]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && (int)$row['amount_minor'] > 0) {
        return [
            'amount_minor' => (int)$row['amount_minor'],
            'currency'     => (string)$row['currency'],
            'source'       => 'employee_rates',
        ];
    }

    return null;
}

/**
 * earnings_minor = total_hours × rate_minor (z rounding HALF_UP do grosza).
 *
 * total_hours jest stringiem z DECIMAL(10,4), np. "8.5000".
 * rate_minor jest intem (grosze/h).
 *
 * Kroki:
 *   hours_milli = round(total_hours * 10000)    ← int dokładnie, bo DECIMAL(10,4)
 *   micro       = rate_minor * hours_milli        ← int
 *   minor       = (micro + 5000) intdiv 10000     ← HALF_UP do grosza
 */
function computeEarnings(string $totalHoursDec, int $rateMinor, int $scale, int $halfUpOffset): int
{
    // Parsowanie DECIMAL(10,4) bez floatów: "8.5000" → 85000
    $hoursMilli = decimalToScaledInt($totalHoursDec, 4);
    if ($hoursMilli <= 0) return 0;

    // micro = rate_minor * hoursMilli (grosze × 10000)
    // Safety: PHP_INT_MAX ≈ 9.2e18. Typowe: 10000 * 450000 = 4.5e9. OK.
    $micro = $rateMinor * $hoursMilli;

    // HALF_UP rounding do grosza (scale = 10000)
    return intdiv($micro + $halfUpOffset, $scale);
}

/**
 * Parsuje DECIMAL string "12.3456" z N miejscami dziesiętnymi → int skalowany o 10^N.
 * Tolerancja: krótsza część po kropce → dopełnia zerami z prawej; dłuższa → ucina.
 * Obsługuje znak.
 */
function decimalToScaledInt(string $dec, int $decimals): int
{
    $dec = trim($dec);
    if ($dec === '') return 0;
    $sign = 1;
    if ($dec[0] === '-') { $sign = -1; $dec = substr($dec, 1); }
    elseif ($dec[0] === '+') { $dec = substr($dec, 1); }
    if (strpos($dec, '.') === false) {
        $intPart = $dec; $fracPart = '';
    } else {
        [$intPart, $fracPart] = explode('.', $dec, 2);
    }
    if (!ctype_digit($intPart) || ($fracPart !== '' && !ctype_digit($fracPart))) {
        throw new \RuntimeException('INVALID_DECIMAL: ' . $dec);
    }
    $fracPart = substr(str_pad($fracPart, $decimals, '0', STR_PAD_RIGHT), 0, $decimals);
    $scaled = ltrim($intPart . $fracPart, '0');
    $scaled = $scaled === '' ? '0' : $scaled;
    return $sign * (int)$scaled;
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
// MAIN
// ----------------------------------------------------------------------

$selectStmt = $pdo->prepare("
    SELECT id, tenant_id, event_type, aggregate_id, payload, attempts
      FROM sh_event_outbox
     WHERE status = 'pending'
       AND aggregate_type = :agg
       AND event_type = :et
       AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
     ORDER BY id ASC
     LIMIT $batchSize
");
$selectStmt->execute([':agg' => $HANDLED_AGGREGATE_TYPE, ':et' => $HANDLED_EVENT_TYPE]);
$events = $selectStmt->fetchAll(PDO::FETCH_ASSOC);

if (!$events) {
    echo "[payroll_accrual] no pending clocked_out events.\n";
    exit(0);
}

foreach ($events as $ev) {
    $eventId  = (int)$ev['id'];
    $tenantId = (int)$ev['tenant_id'];
    $attempts = (int)$ev['attempts'] + 1;

    // Claim → 'dispatching'
    $claim = $pdo->prepare("
        UPDATE sh_event_outbox
           SET status = 'dispatching', dispatched_at = NOW(), attempts = :at
         WHERE id = :id AND status = 'pending'
    ");
    $claim->execute([':at' => $attempts, ':id' => $eventId]);
    if ($claim->rowCount() === 0) continue;

    $stats['processed']++;

    try {
        $payload = json_decode((string)$ev['payload'], true);
        if (!is_array($payload)) {
            throw new \RuntimeException('INVALID_PAYLOAD_JSON');
        }

        $sessionUuid = (string)($payload['session_uuid'] ?? '');
        $employeeId  = (int)($payload['employee_id'] ?? 0);
        if ($sessionUuid === '' || $employeeId <= 0) {
            throw new \RuntimeException('PAYLOAD_MISSING_FIELDS');
        }

        // Fetch sesji (scoped do tenanta)
        $sStmt = $pdo->prepare("
            SELECT id, session_uuid, employee_id, start_time, end_time, total_hours
              FROM sh_work_sessions
             WHERE tenant_id = :tid AND session_uuid = :uuid AND employee_id = :eid
             LIMIT 1
        ");
        $sStmt->execute([':tid' => $tenantId, ':uuid' => $sessionUuid, ':eid' => $employeeId]);
        $session = $sStmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            throw new \RuntimeException('SESSION_NOT_FOUND');
        }
        if ($session['end_time'] === null || $session['total_hours'] === null) {
            throw new \RuntimeException('SESSION_NOT_CLOSED');
        }

        // Zero/ujemny czas — skip (np. artefakty testowe)
        $hoursMilli = decimalToScaledInt((string)$session['total_hours'], 4);
        if ($hoursMilli <= 0) {
            markEventOutcome($pdo, $eventId, 'delivered', 'zero-hours session — skipped', $attempts);
            $stats['skipped_zero_hours']++;
            continue;
        }

        // Resolver stawki
        $rate = resolveRate($pdo, $tenantId, $employeeId, (string)$session['start_time'], $payload);
        if ($rate === null) {
            markEventOutcome($pdo, $eventId, 'delivered', 'no rate — employee not hourly-paid', $attempts);
            $stats['skipped_no_rate']++;
            continue;
        }

        // Earnings (int-safe, HALF_UP)
        $earningsMinor = computeEarnings((string)$session['total_hours'], $rate['amount_minor'], $HOURS_SCALE, $HALF_UP_OFFSET);
        if ($earningsMinor <= 0) {
            markEventOutcome($pdo, $eventId, 'delivered', 'rounded to 0 earnings — skipped', $attempts);
            $stats['skipped_zero_hours']++;
            continue;
        }

        // Okres = start_time miesiąc (HR-6 allocation TODO Faza 4)
        $startTs = strtotime((string)$session['start_time']);
        $pYear   = (int)date('Y', $startTs);
        $pMonth  = (int)date('n', $startTs);

        // Deterministyczny UUID: session_uuid (36 chars, już unikalny)
        $entryUuid = $sessionUuid;

        // total_hours → float dla ledgera (DECIMAL(10,4) → PHP float jest OK bo ledger przechowuje DECIMAL)
        // Tu nie liczymy pieniędzy — tylko zapisujemy obserwację ile h.
        $hoursFloat = (float)$session['total_hours'];

        $ledgerId = PayrollLedger::record($pdo, $tenantId, [
            'entry_uuid'          => $entryUuid,
            'employee_id'         => $employeeId,
            'period_year'         => $pYear,
            'period_month'        => $pMonth,
            'entry_type'          => PayrollLedger::TYPE_WORK_EARNINGS,
            'amount_minor'        => $earningsMinor,
            'currency'            => $rate['currency'],
            'hours_qty'           => $hoursFloat,
            'rate_applied_minor'  => $rate['amount_minor'],
            'ref_work_session_id' => (int)$session['id'],
            'description'         => 'Accrual for session #' . $session['id'] . ' (rate: ' . $rate['source'] . ')',
        ]);

        markEventOutcome($pdo, $eventId, 'delivered', 'accrued ledger #' . $ledgerId, $attempts);
        $stats['accrued']++;

    } catch (\Throwable $e) {
        $stats['failed']++;
        $isDead = $attempts >= $MAX_ATTEMPTS;
        $errTxt = $e->getMessage();
        if (!$isDead) {
            $r = $pdo->prepare("
                UPDATE sh_event_outbox
                   SET status = 'pending',
                       last_error = :err,
                       next_attempt_at = DATE_ADD(NOW(), INTERVAL :delay SECOND)
                 WHERE id = :id
            ");
            $r->execute([':err' => $errTxt, ':delay' => $attempts * 60, ':id' => $eventId]);
        } else {
            markEventOutcome($pdo, $eventId, 'dead', 'MAX_ATTEMPTS: ' . $errTxt, $attempts);
        }
    }
}

echo "[payroll_accrual] done: " . json_encode($stats) . "\n";
