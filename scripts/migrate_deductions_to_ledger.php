<?php

declare(strict_types=1);

/**
 * migrate_deductions_to_ledger — jednorazowa migracja legacy potrąceń do ledgera.
 *
 * Kontekst (patrz _docs/18_BACKOFFICE_HR_LOGIC.md §6.2 + §13.4 pkt 3):
 *   Do Fazy 4 potrącenia żyły w `sh_deductions` (dowolny `type`) oraz w `sh_meals`
 *   (posiłki pracownicze). Po rewrite `PayrollEngine` czyta wyłącznie z
 *   `sh_payroll_ledger` — dane historyczne trzeba tam przenieść, inaczej raporty
 *   za zamknięte okresy „zgubią" potrącenia.
 *
 * MAPOWANIE:
 *   sh_deductions     → entry_type = 'adjustment'    (amount_minor < 0, oryginalny type w description)
 *   sh_meals          → entry_type = 'meal_deduction' (amount_minor < 0, ref_meal_id)
 *   sh_work_sessions  → entry_type = 'work_earnings'  (accrual dla sesji zamkniętych PRZED
 *                       uruchomieniem worker_payroll_accrual; `entry_uuid` = `session_uuid`,
 *                       więc worker i backfill nigdy się nie zdublują)
 *
 *   Klucz pracownika: legacy tabele trzymają `user_id`, ledger `employee_id`
 *   → lookup w `sh_employees` (tenant_id, user_id, is_deleted = 0).
 *   Wiersze bez profilu pracownika są pomijane i raportowane (nie są błędem —
 *   np. konto techniczne bez profilu HR).
 *
 *   Okres rozliczeniowy: rok/miesiąc z `created_at` wiersza legacy.
 *
 * IDEMPOTENCY: `entry_uuid` = deterministyczny hash ('legacy-ded-<id>' /
 *   'legacy-meal-<id>'), więc ponowne uruchomienie nie duplikuje wpisów.
 *
 * Okresy zamknięte (`is_locked`) są pomijane — ledger nie przyjmuje wpisów do
 * zablokowanego okresu; takie wiersze trafiają do statystyki `skipped_locked`.
 *
 * Uruchomienie:
 *   php scripts/migrate_deductions_to_ledger.php [--tenant=N] [--dry-run]
 */

require_once dirname(__DIR__) . '/core/db_config.php';
require_once dirname(__DIR__) . '/core/PayrollLedger.php';
/** @var PDO $pdo */

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tenantFilter = null;
$dryRun       = false;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--tenant=(\d+)$/', $arg, $m)) {
        $tenantFilter = (int)$m[1];
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
}

$stats = [
    'deductions_migrated' => 0,
    'meals_migrated'      => 0,
    'sessions_accrued'    => 0,
    'skipped_no_rate'     => 0,
    'skipped_no_employee' => 0,
    'skipped_locked'      => 0,
    'skipped_zero'        => 0,
    'failed'              => 0,
];

/**
 * Deterministyczny UUID v5-like z tekstowego seeda (idempotency ledger entries).
 */
function legacyLedgerUuid(string $seed): string
{
    $hash  = sha1($seed);
    $bytes = '';
    foreach (str_split(substr($hash, 0, 32), 2) as $hex) {
        $bytes .= chr((int)hexdec($hex));
    }
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $h = bin2hex($bytes);

    return sprintf('%s-%s-%s-%s-%s',
        substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4),
        substr($h, 16, 4), substr($h, 20, 12));
}

/**
 * user_id → employee_id w obrębie tenanta (cache per proces).
 */
function resolveEmployeeId(PDO $pdo, int $tenantId, int $userId): ?int
{
    static $cache = [];
    $key = $tenantId . ':' . $userId;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare('
        SELECT id FROM sh_employees
        WHERE tenant_id = :tid AND user_id = :uid AND is_deleted = 0
        ORDER BY id ASC
        LIMIT 1
    ');
    $stmt->execute([':tid' => $tenantId, ':uid' => $userId]);
    $id = $stmt->fetchColumn();

    return $cache[$key] = ($id === false ? null : (int)$id);
}

/**
 * PLN (DECIMAL) → grosze, HALF_UP, jako ujemna kwota potrącenia.
 */
function toNegativeMinor(string $amountDecimal): int
{
    $minor = (int)round(((float)$amountDecimal) * 100.0);

    return -abs($minor);
}

/**
 * @param list<array<string,mixed>> $rows
 */
function migrateRows(PDO $pdo, array $rows, string $kind, bool $dryRun, array &$stats): void
{
    foreach ($rows as $row) {
        $tenantId = (int)$row['tenant_id'];
        $userId   = (int)$row['user_id'];
        $legacyId = (int)$row['id'];

        $employeeId = resolveEmployeeId($pdo, $tenantId, $userId);
        if ($employeeId === null) {
            $stats['skipped_no_employee']++;
            continue;
        }

        $amountMinor = toNegativeMinor((string)$row['amount']);
        if ($amountMinor === 0) {
            $stats['skipped_zero']++;
            continue;
        }

        $createdAt = new DateTimeImmutable((string)$row['created_at']);
        $year      = (int)$createdAt->format('Y');
        $month     = (int)$createdAt->format('n');

        if (PayrollLedger::isPeriodLocked($pdo, $tenantId, $year, $month)) {
            $stats['skipped_locked']++;
            continue;
        }

        $isMeal      = $kind === 'meal';
        $entryType   = $isMeal ? PayrollLedger::TYPE_MEAL_DEDUCTION : PayrollLedger::TYPE_ADJUSTMENT;
        $description = $isMeal
            ? 'Legacy meal #' . $legacyId . ' (sh_meals)'
            : 'Legacy deduction #' . $legacyId . ' type=' . (string)($row['type'] ?? 'other');

        if ($dryRun) {
            printf(
                "[dry-run] tenant=%d employee=%d %s %s %d gr (%04d-%02d)\n",
                $tenantId, $employeeId, $entryType, $description, $amountMinor, $year, $month
            );
            $stats[$isMeal ? 'meals_migrated' : 'deductions_migrated']++;
            continue;
        }

        $payload = [
            'entry_uuid'   => legacyLedgerUuid(($isMeal ? 'legacy-meal-' : 'legacy-ded-') . $legacyId),
            'employee_id'  => $employeeId,
            'period_year'  => $year,
            'period_month' => $month,
            'entry_type'   => $entryType,
            'amount_minor' => $amountMinor,
            'currency'     => 'PLN',
            'description'  => $description,
        ];
        if ($isMeal) {
            $payload['ref_meal_id'] = $legacyId;
        }

        try {
            PayrollLedger::record($pdo, $tenantId, $payload);
            $stats[$isMeal ? 'meals_migrated' : 'deductions_migrated']++;
        } catch (\Throwable $e) {
            $stats['failed']++;
            fwrite(STDERR, sprintf(
                "FAIL %s #%d (tenant %d): %s\n", $kind, $legacyId, $tenantId, $e->getMessage()
            ));
        }
    }
}

/**
 * Stawka godzinowa (grosze) obowiązująca w momencie `$at`.
 */
function rateMinorAt(PDO $pdo, int $tenantId, int $employeeId, string $at): ?int
{
    $stmt = $pdo->prepare("
        SELECT amount_minor
        FROM sh_employee_rates
        WHERE tenant_id = :tid
          AND employee_id = :eid
          AND rate_type = 'hourly'
          AND effective_from <= :at
          AND (effective_to IS NULL OR effective_to > :at)
        ORDER BY effective_from DESC
        LIMIT 1
    ");
    $stmt->execute([':tid' => $tenantId, ':eid' => $employeeId, ':at' => $at]);
    $minor = $stmt->fetchColumn();
    if ($minor !== false) {
        return (int)$minor;
    }

    // Backfill dotyczy danych sprzed wprowadzenia stawek temporalnych — sesja może
    // być starsza niż najwcześniejszy `effective_from`. Bierzemy wtedy pierwszą
    // znaną stawkę (backfill z migracji 041 = stan „od zawsze").
    $stmt = $pdo->prepare("
        SELECT amount_minor
        FROM sh_employee_rates
        WHERE tenant_id = :tid AND employee_id = :eid AND rate_type = 'hourly'
        ORDER BY effective_from ASC
        LIMIT 1
    ");
    $stmt->execute([':tid' => $tenantId, ':eid' => $employeeId]);
    $minor = $stmt->fetchColumn();

    return $minor === false ? null : (int)$minor;
}

/**
 * Accrual historycznych sesji pracy (te sprzed Fazy 3C nie mają wpisu w ledgerze).
 *
 * @param list<array<string,mixed>> $rows
 */
function backfillSessions(PDO $pdo, array $rows, bool $dryRun, array &$stats): void
{
    foreach ($rows as $row) {
        $tenantId  = (int)$row['tenant_id'];
        $sessionId = (int)$row['id'];

        $employeeId = resolveEmployeeId($pdo, $tenantId, (int)$row['user_id']);
        if ($employeeId === null) {
            $stats['skipped_no_employee']++;
            continue;
        }

        $startTime = (string)$row['start_time'];
        $rateMinor = rateMinorAt($pdo, $tenantId, $employeeId, $startTime);
        if ($rateMinor === null || $rateMinor <= 0) {
            $stats['skipped_no_rate']++;
            continue;
        }

        $hours = (float)$row['total_hours'];
        if ($hours <= 0.0) {
            $stats['skipped_zero']++;
            continue;
        }

        // grosze = round(hours * rate_minor), HALF_UP na intach (milli-hours).
        $hoursMilli   = (int)round($hours * 10000);
        $earningsMinor = intdiv($hoursMilli * $rateMinor + 5000, 10000);
        if ($earningsMinor <= 0) {
            $stats['skipped_zero']++;
            continue;
        }

        $started = new DateTimeImmutable($startTime);
        $year    = (int)$started->format('Y');
        $month   = (int)$started->format('n');

        if (PayrollLedger::isPeriodLocked($pdo, $tenantId, $year, $month)) {
            $stats['skipped_locked']++;
            continue;
        }

        if ($dryRun) {
            printf(
                "[dry-run] tenant=%d employee=%d work_earnings session #%d %.4f h × %d gr = %d gr (%04d-%02d)\n",
                $tenantId, $employeeId, $sessionId, $hours, $rateMinor, $earningsMinor, $year, $month
            );
            $stats['sessions_accrued']++;
            continue;
        }

        try {
            PayrollLedger::record($pdo, $tenantId, [
                'entry_uuid'          => (string)$row['session_uuid'],
                'employee_id'         => $employeeId,
                'period_year'         => $year,
                'period_month'        => $month,
                'entry_type'          => PayrollLedger::TYPE_WORK_EARNINGS,
                'amount_minor'        => $earningsMinor,
                'currency'            => 'PLN',
                'hours_qty'           => $hours,
                'rate_applied_minor'  => $rateMinor,
                'ref_work_session_id' => $sessionId,
                'description'         => 'Backfill accrual for session #' . $sessionId,
            ]);
            $stats['sessions_accrued']++;
        } catch (\Throwable $e) {
            $stats['failed']++;
            fwrite(STDERR, sprintf(
                "FAIL session #%d (tenant %d): %s\n", $sessionId, $tenantId, $e->getMessage()
            ));
        }
    }
}

$tenantSql    = $tenantFilter !== null ? ' WHERE tenant_id = :tid' : '';
$tenantParams = $tenantFilter !== null ? [':tid' => $tenantFilter] : [];

$stmt = $pdo->prepare('SELECT id, tenant_id, user_id, type, amount, created_at FROM sh_deductions' . $tenantSql . ' ORDER BY id ASC');
$stmt->execute($tenantParams);
migrateRows($pdo, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'deduction', $dryRun, $stats);

$stmt = $pdo->prepare('SELECT id, tenant_id, user_id, employee_price AS amount, created_at FROM sh_meals' . $tenantSql . ' ORDER BY id ASC');
$stmt->execute($tenantParams);
migrateRows($pdo, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'meal', $dryRun, $stats);

$sessionWhere = $tenantFilter !== null ? ' AND ws.tenant_id = :tid' : '';
$stmt = $pdo->prepare('
    SELECT ws.id, ws.tenant_id, ws.user_id, ws.session_uuid, ws.start_time,
           COALESCE(ws.total_hours, TIMESTAMPDIFF(SECOND, ws.start_time, ws.end_time) / 3600.0) AS total_hours
    FROM sh_work_sessions ws
    WHERE ws.end_time IS NOT NULL' . $sessionWhere . '
      AND NOT EXISTS (
          SELECT 1 FROM sh_payroll_ledger l
          WHERE l.tenant_id = ws.tenant_id AND l.ref_work_session_id = ws.id
      )
    ORDER BY ws.id ASC
');
$stmt->execute($tenantParams);
backfillSessions($pdo, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], $dryRun, $stats);

echo ($dryRun ? '[DRY-RUN] ' : '') . 'migrate_deductions_to_ledger: ' . json_encode($stats, JSON_UNESCAPED_UNICODE) . PHP_EOL;
