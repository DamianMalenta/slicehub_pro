<?php

declare(strict_types=1);

/**
 * Payroll Engine — Hours, Gross, Deductions, Net, Period Comparison
 *
 * Read-only + aggregate queries (no transaction required for consistency
 * beyond individual SELECT snapshots).
 */
class PayrollEngine
{
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

        $stmtUser = $pdo->prepare("
            SELECT hourly_rate FROM sh_users
            WHERE id = :id AND tenant_id = :tid AND is_deleted = 0
            LIMIT 1
        ");
        $stmtUser->execute([':id' => $uid, ':tid' => $tenantId]);
        $uRow = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if (!$uRow) {
            throw new \RuntimeException('USER_NOT_FOUND_OR_TENANT_MISMATCH');
        }

        $hourlyRate = (float)($uRow['hourly_rate'] ?? 0.0);

        $bounds       = self::resolvePeriodBounds($periodType, $periodOffset);
        $periodStart  = $bounds['start'];
        $periodEnd    = $bounds['end'];
        $periodLabel  = $bounds['label'];

        $startSql = $periodStart->format('Y-m-d H:i:s');
        $endSql   = $periodEnd->format('Y-m-d H:i:s');

        $closedHours = self::sumClosedHours($pdo, $tenantId, $uid, $startSql, $endSql);
        $activeHours = self::sumActiveHours($pdo, $tenantId, $uid, $startSql, $endSql);
        $totalHours  = round($closedHours + $activeHours, 4);

        $deductionRows = self::fetchDeductionsGrouped($pdo, $tenantId, $uid, $startSql, $endSql);
        $mealTotal     = self::sumMeals($pdo, $tenantId, $uid, $startSql, $endSql);

        $deductionsList = [];
        foreach ($deductionRows as $dr) {
            $deductionsList[] = [
                'type'  => (string)$dr['type'],
                'total' => number_format((float)$dr['total'], 2, '.', ''),
            ];
        }
        if ($mealTotal > 0.0) {
            $deductionsList[] = [
                'type'  => 'meal',
                'total' => number_format($mealTotal, 2, '.', ''),
            ];
        }

        $totalDeductions = round(
            array_sum(array_column($deductionRows, 'total')) + $mealTotal,
            2
        );

        $grossPay = round($totalHours * $hourlyRate, 2);
        $netPay   = round($grossPay - $totalDeductions, 2);

        $payroll = [
            'period' => [
                'type'  => $periodType,
                'start' => $periodStart->format('Y-m-d'),
                'end'   => $periodEnd->format('Y-m-d'),
                'label' => $periodLabel,
            ],
            'hours' => [
                'closed' => round($closedHours, 4),
                'active' => round($activeHours, 4),
                'total'  => $totalHours,
            ],
            'hourly_rate'      => number_format($hourlyRate, 2, '.', ''),
            'gross_pay'        => number_format($grossPay, 2, '.', ''),
            'deductions'       => $deductionsList,
            'total_deductions' => number_format($totalDeductions, 2, '.', ''),
            'net_pay'          => number_format($netPay, 2, '.', ''),
        ];

        if ($periodOffset === 0) {
            $cmp = self::buildComparison(
                $pdo,
                $tenantId,
                $uid,
                $hourlyRate,
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

    private static function sumClosedHours(
        PDO $pdo,
        int $tenantId,
        int $userId,
        string $startSql,
        string $endSql
    ): float {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_hours), 0) AS hrs
            FROM sh_work_sessions
            WHERE tenant_id = :tid
              AND user_id = :uid
              AND end_time IS NOT NULL
              AND start_time >= :st
              AND start_time <= :en
        ");
        $stmt->execute([
            ':tid' => $tenantId,
            ':uid' => $userId,
            ':st'  => $startSql,
            ':en'  => $endSql,
        ]);

        return (float)$stmt->fetchColumn();
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
     * @return list<array{type: string, total: float}>
     */
    private static function fetchDeductionsGrouped(
        PDO $pdo,
        int $tenantId,
        int $userId,
        string $startSql,
        string $endSql
    ): array {
        $stmt = $pdo->prepare("
            SELECT type, SUM(amount) AS total
            FROM sh_deductions
            WHERE tenant_id = :tid
              AND user_id = :uid
              AND created_at >= :st
              AND created_at <= :en
            GROUP BY type
        ");
        $stmt->execute([
            ':tid' => $tenantId,
            ':uid' => $userId,
            ':st'  => $startSql,
            ':en'  => $endSql,
        ]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'type'  => (string)$r['type'],
                'total' => (float)$r['total'],
            ];
        }

        return $out;
    }

    private static function sumMeals(
        PDO $pdo,
        int $tenantId,
        int $userId,
        string $startSql,
        string $endSql
    ): float {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(employee_price), 0)
            FROM sh_meals
            WHERE tenant_id = :tid
              AND user_id = :uid
              AND created_at >= :st
              AND created_at <= :en
        ");
        $stmt->execute([
            ':tid' => $tenantId,
            ':uid' => $userId,
            ':st'  => $startSql,
            ':en'  => $endSql,
        ]);

        return (float)$stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function buildComparison(
        PDO $pdo,
        int $tenantId,
        int $userId,
        float $hourlyRate,
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

        $st = $prevStart->format('Y-m-d H:i:s');
        $en = $prevEnd->format('Y-m-d H:i:s');

        // Historical window: closed sessions only (no point-in-time active reconstruction).
        $prevHours = round(self::sumClosedHours($pdo, $tenantId, $userId, $st, $en), 4);

        $prevDeductions = array_sum(array_column(
            self::fetchDeductionsGrouped($pdo, $tenantId, $userId, $st, $en),
            'total'
        ));
        $prevMeals = self::sumMeals($pdo, $tenantId, $userId, $st, $en);
        $prevTotalDed = round($prevDeductions + $prevMeals, 2);
        $prevGross    = round($prevHours * $hourlyRate, 2);
        $prevNet      = round($prevGross - $prevTotalDed, 2);

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
