<?php

declare(strict_types=1);

/**
 * payroll_engine_rewrite_parity — driver equivalence Legacy vs Rewrite (Faza 4).
 *
 * Wymóg z `_docs/18_BACKOFFICE_HR_LOGIC.md §13.4 pkt 4`: dla tych samych
 * `tenant/employee/period` legacy silnik (readery `sh_work_sessions` +
 * `sh_deductions` + `sh_meals` + `sh_users.hourly_rate`) i przepisany
 * `PayrollEngine` (ledger) muszą dać różnicę **0 gr**.
 *
 * Legacy jest tu odtworzony inline (nie ma już drugiego pliku silnika — SSOT),
 * dlatego driver ma sens tylko na danych sprzed migracji do ledgera, tzn.
 * po uruchomieniu `scripts/migrate_deductions_to_ledger.php`.
 *
 * Uruchomienie:
 *   php tests/payroll_engine_rewrite_parity.php --tenant=1 [--period=month] [--offset=1]
 *
 * Exit code: 0 = parity OK, 1 = rozjazd (kwota lub godziny).
 */

require_once dirname(__DIR__) . '/core/db_config.php';
require_once dirname(__DIR__) . '/core/PayrollEngine.php';
/** @var PDO $pdo */

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tenantId   = 0;
$periodType = 'month';
$offset     = 1;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--tenant=(\d+)$/', $arg, $m))            $tenantId   = (int)$m[1];
    if (preg_match('/^--period=(week|month|year)$/', $arg, $m)) $periodType = $m[1];
    if (preg_match('/^--offset=(\d+)$/', $arg, $m))             $offset     = (int)$m[1];
}
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php tests/payroll_engine_rewrite_parity.php --tenant=N [--period=month] [--offset=1]\n");
    exit(2);
}

/**
 * @return array{start:string, end:string}
 */
function parityBounds(string $type, int $offset): array
{
    $now = new DateTimeImmutable('now');

    if ($type === 'month') {
        $start = $now->modify('first day of this month')->setTime(0, 0, 0)->modify("-{$offset} months");
        $end   = $offset === 0 ? $now : $start->modify('last day of this month')->setTime(23, 59, 59);
    } elseif ($type === 'week') {
        $dow   = (int)$now->format('N');
        $start = $now->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0)->modify('-' . (7 * $offset) . ' days');
        $end   = $offset === 0 ? $now : $start->modify('+6 days')->setTime(23, 59, 59);
    } else {
        $year  = (int)$now->format('Y') - $offset;
        $start = new DateTimeImmutable("{$year}-01-01 00:00:00");
        $end   = $offset === 0 ? $now : new DateTimeImmutable("{$year}-12-31 23:59:59");
    }

    return ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')];
}

/**
 * Legacy code-path (stan sprzed Fazy 4), odtworzony wyłącznie na potrzeby porównania.
 *
 * `hours` to godziny zamknięte; `gross` zawiera dodatkowo trwającą zmianę.
 *
 * @return array{hours:float, gross:float, deductions:float, net:float}
 */
function legacyCalculate(PDO $pdo, int $tenantId, int $userId, string $start, string $end): array
{
    $st = $pdo->prepare('SELECT hourly_rate FROM sh_users WHERE id = :id AND tenant_id = :tid AND is_deleted = 0');
    $st->execute([':id' => $userId, ':tid' => $tenantId]);
    $rate = (float)($st->fetchColumn() ?: 0.0);

    $st = $pdo->prepare("
        SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, start_time, end_time)) / 3600.0, 0) AS h
        FROM sh_work_sessions
        WHERE tenant_id = :tid AND user_id = :uid
          AND end_time IS NOT NULL
          AND start_time BETWEEN :s AND :e
    ");
    $st->execute([':tid' => $tenantId, ':uid' => $userId, ':s' => $start, ':e' => $end]);
    $hours = round((float)$st->fetchColumn(), 4);

    // Legacy silnik doliczał też trwającą zmianę (rewrite robi to samo z sh_work_sessions).
    $st = $pdo->prepare("
        SELECT COALESCE(ROUND(TIMESTAMPDIFF(SECOND, start_time, NOW()) / 3600.0, 4), 0)
        FROM sh_work_sessions
        WHERE tenant_id = :tid AND user_id = :uid AND end_time IS NULL
          AND start_time BETWEEN :s AND :e
        ORDER BY start_time DESC
        LIMIT 1
    ");
    $st->execute([':tid' => $tenantId, ':uid' => $userId, ':s' => $start, ':e' => $end]);
    $activeHours = (float)($st->fetchColumn() ?: 0.0);

    $st = $pdo->prepare('
        SELECT COALESCE(SUM(amount), 0) FROM sh_deductions
        WHERE tenant_id = :tid AND user_id = :uid AND created_at BETWEEN :s AND :e
    ');
    $st->execute([':tid' => $tenantId, ':uid' => $userId, ':s' => $start, ':e' => $end]);
    $deductions = (float)$st->fetchColumn();

    $st = $pdo->prepare('
        SELECT COALESCE(SUM(employee_price), 0) FROM sh_meals
        WHERE tenant_id = :tid AND user_id = :uid AND created_at BETWEEN :s AND :e
    ');
    $st->execute([':tid' => $tenantId, ':uid' => $userId, ':s' => $start, ':e' => $end]);
    $deductions += (float)$st->fetchColumn();

    $gross = round(($hours + $activeHours) * $rate, 2);

    return [
        'hours'      => $hours,
        'gross'      => $gross,
        'deductions' => round($deductions, 2),
        'net'        => round($gross - $deductions, 2),
    ];
}

$bounds = parityBounds($periodType, $offset);

$st = $pdo->prepare('
    SELECT e.user_id
    FROM sh_employees e
    WHERE e.tenant_id = :tid AND e.is_deleted = 0
    ORDER BY e.id ASC
');
$st->execute([':tid' => $tenantId]);
$userIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);

$mismatches = 0;
foreach ($userIds as $userId) {
    $legacy = legacyCalculate($pdo, $tenantId, $userId, $bounds['start'], $bounds['end']);
    $new    = PayrollEngine::calculate($pdo, $tenantId, (string)$userId, $periodType, $offset)['payroll'];

    $newHours = (float)($new['hours']['closed'] ?? 0.0);
    $newGross = (float)$new['gross_pay'];
    $newNet   = (float)$new['net_pay'];

    $diffGrossGr = (int)round(($newGross - $legacy['gross']) * 100);
    $diffNetGr   = (int)round(($newNet - $legacy['net']) * 100);
    $diffHours   = round($newHours - $legacy['hours'], 4);

    $ok = ($diffGrossGr === 0 && $diffNetGr === 0 && abs($diffHours) < 0.0001);
    if (!$ok) {
        $mismatches++;
    }

    printf(
        "%s user=%-5d hours %.4f/%.4f gross %.2f/%.2f net %.2f/%.2f (Δ %d gr net)\n",
        $ok ? 'OK  ' : 'DIFF',
        $userId,
        $legacy['hours'], $newHours,
        $legacy['gross'], $newGross,
        $legacy['net'], $newNet,
        $diffNetGr
    );
}

printf(
    "parity %s: tenant=%d period=%s offset=%d employees=%d mismatches=%d\n",
    $mismatches === 0 ? 'PASS' : 'FAIL',
    $tenantId, $periodType, $offset, count($userIds), $mismatches
);

exit($mismatches === 0 ? 0 : 1);
