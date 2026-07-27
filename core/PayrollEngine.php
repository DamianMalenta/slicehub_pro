<?php

declare(strict_types=1);

require_once __DIR__ . '/PayrollLedger.php';

/**
 * Payroll Engine — Hours, Gross, Deductions, Net, Period Comparison.
 *
 * Faza 4 (rewrite IN-PLACE, _docs/18_BACKOFFICE_HR_LOGIC.md §13): jedynym
 * źródłem prawdy jest `sh_payroll_ledger`. Silnik nie czyta już
 * `sh_users.hourly_rate`, `sh_deductions` ani `sh_meals` — dane historyczne
 * przenosi `scripts/migrate_deductions_to_ledger.php`.
 *
 * Wyjątek: godziny TRWAJĄCEJ zmiany pochodzą z `sh_work_sessions`, bo accrual
 * do ledgera powstaje dopiero przy clock-out (worker_payroll_accrual).
 *
 * Zaliczki (`advance_payment` / `advance_repayment`) NIE wchodzą do gross/net —
 * raportowane są osobno, żeby konsument (np. akcja `payroll_report`) mógł
 * odjąć spłaty raz, a nie dwa razy.
 *
 * Read-only + aggregate queries (no transaction required for consistency
 * beyond individual SELECT snapshots).
 */
class PayrollEngine
{
    /** Typy ledgera zwiększające wynagrodzenie brutto. */
    private const EARNING_TYPES = [
        PayrollLedger::TYPE_WORK_EARNINGS,
        PayrollLedger::TYPE_BONUS,
    ];

    /** Etykiety `deductions[].type` w response (kontrakt UI). */
    private const DEDUCTION_LABELS = [
        PayrollLedger::TYPE_MEAL_DEDUCTION => 'meal',
    ];

    /**
     * @return array{payroll: array}
     *
     * @throws \InvalidArgumentException on bad period type / user id
     * @throws \RuntimeException         when user not found for tenant
     */
    public static function calculate(
        PDO    $pdo,
        int    $tenantId,
        string $userId,
        string $periodType = 'month',
        int    $periodOffset = 0
    ): array {

        $uid = (int)trim($userId);
        if ($uid <= 0) {
            throw new \InvalidArgumentException('Invalid user_id.');
        }

        $periodType = strtolower(trim($periodType));
        if (!in_array($periodType, ['week', 'month', 'year'], true)) {
            throw new \InvalidArgumentException('period_type must be week, month, or year.');
        }
        if ($periodOffset < 0) {
            throw new \InvalidArgumentException('period_offset must be >= 0.');
        }

        $employeeId = self::resolveEmployeeId($pdo, $tenantId, $uid);

        $bounds       = self::resolvePeriodBounds($periodType, $periodOffset);
        $periodStart  = $bounds['start'];
        $periodEnd    = $bounds['end'];
        $periodLabel  = $bounds['label'];

        $startSql = $periodStart->format('Y-m-d H:i:s');
        $endSql   = $periodEnd->format('Y-m-d H:i:s');

        $ledger = self::aggregateLedger($pdo, $tenantId, $employeeId, $periodStart, $periodEnd);

        $hourlyRate = self::resolveHourlyRate($pdo, $tenantId, $employeeId, $periodEnd, $ledger['rate_minor']);

        $closedHours = round($ledger['hours'], 4);
        $activeHours = self::sumActiveHours($pdo, $tenantId, $uid, $startSql, $endSql);
        $totalHours  = round($closedHours + $activeHours, 4);

        // Trwająca zmiana nie ma jeszcze accrualu w ledgerze — doliczamy szacunek.
        $activeEarnings = round($activeHours * $hourlyRate, 2);
        $grossPay       = round(($ledger['earnings_minor'] / 100.0) + $activeEarnings, 2);

        $deductionsList = [];
        foreach ($ledger['deductions_by_type'] as $type => $minor) {
            $deductionsList[] = [
                'type'  => self::DEDUCTION_LABELS[$type] ?? $type,
                'total' => number_format(abs($minor) / 100.0, 2, '.', ''),
            ];
        }

        $totalDeductions = round($ledger['deductions_minor'] / 100.0, 2);
        $netPay          = round($grossPay - $totalDeductions, 2);

        $payroll = [
            'period' => [
                'type'  => $periodType,
                'start' => $periodStart->format('Y-m-d'),
                'end'   => $periodEnd->format('Y-m-d'),
                'label' => $periodLabel,
            ],
            'hours' => [
                'closed' => $closedHours,
                'active' => round($activeHours, 4),
                'total'  => $totalHours,
            ],
            'hourly_rate'      => number_format($hourlyRate, 2, '.', ''),
            'gross_pay'        => number_format($grossPay, 2, '.', ''),
            'deductions'       => $deductionsList,
            'total_deductions' => number_format($totalDeductions, 2, '.', ''),
            'net_pay'          => number_format($netPay, 2, '.', ''),
            'advances'         => [
                'paid_out' => number_format($ledger['advance_paid_minor'] / 100.0, 2, '.', ''),
                'repaid'   => number_format(abs($ledger['advance_repaid_minor']) / 100.0, 2, '.', ''),
            ],
        ];

        if ($periodOffset === 0) {
            $cmp = self::buildComparison(
                $pdo,
                $tenantId,
                $employeeId,
                $periodType,
                new \DateTimeImmutable('now'),
                $totalHours,
                $netPay
            );
            if ($cmp !== null) {
                $payroll['comparison'] = $cmp;
            }
        }

        return ['payroll' => $payroll];
    }

    /**
     * Aktualna stawka godzinowa (PLN) pracownika z temporalnej `sh_employee_rates`.
     * Publiczne, bo korzysta z niej {@see TeamPayrollEngine} (live shift burn rate).
     */
    public static function hourlyRateForEmployee(
        PDO $pdo,
        int $tenantId,
        int $employeeId,
        ?\DateTimeImmutable $at = null
    ): float {
        $atSql = ($at ?? new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

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
        $stmt->execute([':tid' => $tenantId, ':eid' => $employeeId, ':at' => $atSql]);
        $minor = $stmt->fetchColumn();

        return $minor === false ? 0.0 : ((int)$minor) / 100.0;
    }

    /**
     * @throws \RuntimeException gdy user nie istnieje w tenancie lub nie ma profilu HR
     */
    private static function resolveEmployeeId(PDO $pdo, int $tenantId, int $userId): int
    {
        $stmt = $pdo->prepare('
            SELECT id FROM sh_employees
            WHERE tenant_id = :tid AND user_id = :uid AND is_deleted = 0
            ORDER BY id ASC
            LIMIT 1
        ');
        $stmt->execute([':tid' => $tenantId, ':uid' => $userId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException('USER_NOT_FOUND_OR_TENANT_MISMATCH');
        }

        return (int)$id;
    }

    /**
     * Agregat ledgera w oknie czasowym.
     *
     * Ledger jest kluczowany miesięcznie (`period_year`/`period_month`), ale okno
     * tygodniowe wymaga dokładniejszego cięcia — dlatego dodatkowo filtrujemy po
     * dacie zdarzenia: `sh_work_sessions.start_time` dla accrualu godzin,
     * `created_at` dla pozostałych wpisów.
     *
     * WYJĄTEK (wpisy księgowane wstecz): rata zaliczki spłacana przy zamknięciu
     * miesiąca ma `period = M`, ale `created_at` już w M+1 (zamknąć można tylko
     * miesiąc zakończony). Filtr po dacie wycinałby ją z raportu M, a klucz
     * miesięczny z raportu M+1 — czyli znikałaby zupełnie i zawyżała `payout`.
     * Dlatego wpisy, których `period` != miesiąc `created_at`, kwalifikuje sam
     * klucz okresu (dla okna tygodniowego trafiają do każdego tygodnia tego
     * miesiąca — nie mają dnia zdarzenia, więc precyzyjniej się nie da).
     *
     * `reversal` dziedziczy semantykę wpisu, który odwraca (`reverses_entry_id`) —
     * inaczej odwrócenie voidowanej zaliczki (kwota ujemna) trafiłoby do potrąceń
     * i zaniżyło netto. Grupowanie po typie efektywnym powoduje, że reversal
     * po prostu nettuje się z oryginałem w tym samym koszyku.
     *
     * @return array{
     *   hours: float, earnings_minor: int, deductions_minor: int,
     *   deductions_by_type: array<string,int>, advance_paid_minor: int,
     *   advance_repaid_minor: int, rate_minor: int|null
     * }
     */
    private static function aggregateLedger(
        PDO $pdo,
        int $tenantId,
        int $employeeId,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end
    ): array {
        $out = [
            'hours'                => 0.0,
            'earnings_minor'       => 0,
            'deductions_minor'     => 0,
            'deductions_by_type'   => [],
            'advance_paid_minor'   => 0,
            'advance_repaid_minor' => 0,
            'rate_minor'           => null,
        ];

        $months = self::periodMonths($start, $end);
        if ($months === []) {
            return $out;
        }

        $monthPh = implode(',', array_fill(0, count($months), '(?, ?)'));
        $params  = [$tenantId, $employeeId];
        foreach ($months as $ym) {
            $params[] = $ym[0];
            $params[] = $ym[1];
        }
        $params[] = $start->format('Y-m-d H:i:s');
        $params[] = $end->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            SELECT COALESCE(orig.entry_type, l.entry_type) AS entry_type,
                   SUM(l.amount_minor)             AS amount_minor,
                   SUM(COALESCE(l.hours_qty, 0))   AS hours_qty,
                   MAX(l.rate_applied_minor)       AS rate_applied_minor
            FROM sh_payroll_ledger l
            LEFT JOIN sh_work_sessions ws
                   ON ws.id = l.ref_work_session_id
                  AND ws.tenant_id = l.tenant_id
            LEFT JOIN sh_payroll_ledger orig
                   ON orig.id = l.reverses_entry_id
                  AND orig.tenant_id = l.tenant_id
            WHERE l.tenant_id = ?
              AND l.employee_id = ?
              AND (l.period_year, l.period_month) IN ({$monthPh})
              AND (
                    COALESCE(ws.start_time, l.created_at) BETWEEN ? AND ?
                 OR l.period_year  <> YEAR(l.created_at)
                 OR l.period_month <> MONTH(l.created_at)
              )
            GROUP BY COALESCE(orig.entry_type, l.entry_type)
        ");
        $stmt->execute($params);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $type  = (string)$row['entry_type'];
            $minor = (int)$row['amount_minor'];

            if ($type === PayrollLedger::TYPE_WORK_EARNINGS) {
                $out['hours'] += (float)$row['hours_qty'];
                if ($row['rate_applied_minor'] !== null) {
                    $out['rate_minor'] = (int)$row['rate_applied_minor'];
                }
            }

            if ($type === PayrollLedger::TYPE_ADVANCE_PAYMENT) {
                $out['advance_paid_minor'] += $minor;
                continue;
            }
            if ($type === PayrollLedger::TYPE_ADVANCE_REPAYMENT) {
                $out['advance_repaid_minor'] += $minor;
                continue;
            }

            if (in_array($type, self::EARNING_TYPES, true) || $minor > 0) {
                $out['earnings_minor'] += $minor;
                continue;
            }

            $out['deductions_minor'] += abs($minor);
            $out['deductions_by_type'][$type] = ($out['deductions_by_type'][$type] ?? 0) + $minor;
        }

        return $out;
    }

    /**
     * Lista par [rok, miesiąc] objętych oknem (ledger jest kluczowany miesięcznie).
     *
     * @return list<array{0:int,1:int}>
     */
    private static function periodMonths(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        if ($end < $start) {
            return [];
        }

        $months = [];
        $cursor = $start->modify('first day of this month')->setTime(0, 0, 0);
        $last   = $end->modify('first day of this month')->setTime(0, 0, 0);

        while ($cursor <= $last) {
            $months[] = [(int)$cursor->format('Y'), (int)$cursor->format('n')];
            $cursor   = $cursor->modify('+1 month');
        }

        return $months;
    }

    /**
     * Stawka na koniec okresu; fallback — ostatnia stawka zaksięgowana w ledgerze.
     */
    private static function resolveHourlyRate(
        PDO $pdo,
        int $tenantId,
        int $employeeId,
        \DateTimeImmutable $at,
        ?int $ledgerRateMinor
    ): float {
        $rate = self::hourlyRateForEmployee($pdo, $tenantId, $employeeId, $at);
        if ($rate > 0.0) {
            return $rate;
        }

        return $ledgerRateMinor !== null ? $ledgerRateMinor / 100.0 : 0.0;
    }

    /**
     * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable, label: string}
     */
    private static function resolvePeriodBounds(string $type, int $offset): array
    {
        $now = new \DateTimeImmutable('now');

        if ($type === 'month') {
            $anchor = $now->modify('first day of this month')->setTime(0, 0, 0)
                ->modify("-{$offset} months");
            $start = $anchor;
            if ($offset === 0) {
                $end = $now;
            } else {
                $end = $anchor->modify('last day of this month')->setTime(23, 59, 59);
            }
            $label = self::formatMonthLabel($start)
                . ($offset === 0 ? ' (MTD)' : '');

            return ['start' => $start, 'end' => $end, 'label' => $label];
        }

        if ($type === 'week') {
            $dow   = (int)$now->format('N');
            $monday = $now->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0)
                ->modify('-' . (7 * $offset) . ' days');
            $start = $monday;
            if ($offset === 0) {
                $end = $now;
            } else {
                $end = $start->modify('+6 days')->setTime(23, 59, 59);
            }
            $label = 'Week of ' . $start->format('Y-m-d')
                . ($offset === 0 ? ' (WTD)' : '');

            return ['start' => $start, 'end' => $end, 'label' => $label];
        }

        // year
        $year  = (int)$now->format('Y') - $offset;
        $start = new \DateTimeImmutable("{$year}-01-01 00:00:00");
        if ($offset === 0) {
            $end = $now;
        } else {
            $end = new \DateTimeImmutable("{$year}-12-31 23:59:59");
        }
        $label = (string)$year . ($offset === 0 ? ' (YTD)' : '');

        return ['start' => $start, 'end' => $end, 'label' => $label];
    }

    private static function sumActiveHours(
        PDO $pdo,
        int $tenantId,
        int $userId,
        string $startSql,
        string $endSql
    ): float {
        $stmt = $pdo->prepare("
            SELECT start_time
            FROM sh_work_sessions
            WHERE tenant_id = :tid
              AND user_id = :uid
              AND end_time IS NULL
            ORDER BY start_time DESC
            LIMIT 1
        ");
        $stmt->execute([':tid' => $tenantId, ':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 0.0;
        }

        $startStr = (string)$row['start_time'];
        if ($startStr < $startSql || $startStr > $endSql) {
            return 0.0;
        }

        $stmtSec = $pdo->prepare("
            SELECT ROUND(TIMESTAMPDIFF(SECOND, start_time, NOW()) / 3600.0, 4) AS active_h
            FROM sh_work_sessions
            WHERE tenant_id = :tid AND user_id = :uid AND end_time IS NULL
            ORDER BY start_time DESC
            LIMIT 1
        ");
        $stmtSec->execute([':tid' => $tenantId, ':uid' => $userId]);

        return (float)$stmtSec->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function buildComparison(
        PDO $pdo,
        int $tenantId,
        int $employeeId,
        string $periodType,
        \DateTimeImmutable $now,
        float $currentTotalHours,
        float $currentNet
    ): ?array {

        if ($periodType === 'month') {
            $prevStart = $now->modify('first day of last month')->setTime(0, 0, 0);
            $py         = (int)$prevStart->format('Y');
            $pm         = (int)$prevStart->format('m');
            $daysInPrev = (int)$prevStart->format('t');
            $dom        = min((int)$now->format('j'), $daysInPrev);
            $prevEnd    = new \DateTimeImmutable(
                sprintf('%04d-%02d-%02d %s', $py, $pm, $dom, $now->format('H:i:s')),
                $now->getTimezone()
            );
        } elseif ($periodType === 'week') {
            $dow     = (int)$now->format('N');
            $thisMon = $now->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0);
            $prevStart = $thisMon->modify('-7 days');
            $prevEnd   = $now->modify('-7 days');
        } else {
            $prevStart = new \DateTimeImmutable(((int)$now->format('Y') - 1) . '-01-01 00:00:00');
            $prevEnd   = $now->modify('-1 year');
        }

        // Historical window: ledger only (no point-in-time active reconstruction).
        $prev      = self::aggregateLedger($pdo, $tenantId, $employeeId, $prevStart, $prevEnd);
        $prevHours = round($prev['hours'], 4);
        $prevGross = round($prev['earnings_minor'] / 100.0, 2);
        $prevNet   = round($prevGross - ($prev['deductions_minor'] / 100.0), 2);

        $label = self::formatMonthLabel($prevStart);
        if ($periodType === 'week') {
            $label = 'Prior week (same elapsed window)';
        } elseif ($periodType === 'year') {
            $label = 'Prior year (same calendar point −1y)';
        } else {
            $label .= ' (through ' . $prevEnd->format('d.m H:i') . ')';
        }

        $deltaHours = round($currentTotalHours - $prevHours, 2);
        $deltaNet   = round($currentNet - $prevNet, 2);

        return [
            'prev_period_label' => $label,
            'prev_hours'        => $prevHours,
            'prev_net'          => number_format($prevNet, 2, '.', ''),
            'delta_hours'       => self::fmtSignedDelta($deltaHours),
            'delta_net'         => self::fmtSignedMoneyDelta($deltaNet),
        ];
    }

    private static function fmtSignedDelta(float $v): string
    {
        if ($v > 0) {
            return '+' . number_format($v, 2, '.', '');
        }
        if ($v < 0) {
            return '-' . number_format(abs($v), 2, '.', '');
        }

        return '+0.00';
    }

    private static function fmtSignedMoneyDelta(float $v): string
    {
        if ($v > 0) {
            return '+' . number_format($v, 2, '.', '');
        }
        if ($v < 0) {
            return '-' . number_format(abs($v), 2, '.', '');
        }

        return '+0.00';
    }

    private static function formatMonthLabel(\DateTimeImmutable $firstOfMonth): string
    {
        if (class_exists(\IntlDateFormatter::class)) {
            try {
                $fmt = new \IntlDateFormatter(
                    'pl_PL',
                    \IntlDateFormatter::NONE,
                    \IntlDateFormatter::NONE,
                    null,
                    null,
                    'LLLL yyyy'
                );
                if ($fmt !== false) {
                    $t = $fmt->format($firstOfMonth);
                    if ($t !== false && $t !== '') {
                        return ucfirst($t);
                    }
                }
            } catch (\Throwable $e) {
                // fall through to English month
            }
        }

        return $firstOfMonth->format('F Y');
    }
}
