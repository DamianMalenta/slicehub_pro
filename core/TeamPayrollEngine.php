<?php

declare(strict_types=1);

require_once __DIR__ . '/PayrollEngine.php';

/**
 * Boss Dashboard — team aggregate payroll (server-side aggregation via PayrollEngine).
 */
class TeamPayrollEngine
{
    /**
     * @return array{team_payroll: array<string, mixed>}
     *
     * @throws \InvalidArgumentException
     */
    public static function getAggregate(
        PDO $pdo,
        int $tenantId,
        string $periodType = 'month',
        int $periodOffset = 0
    ): array {
        $periodType = strtolower(trim($periodType));
        if (!in_array($periodType, ['week', 'month', 'year'], true)) {
            throw new \InvalidArgumentException('period_type must be week, month, or year.');
        }
        if ($periodOffset < 0) {
            throw new \InvalidArgumentException('period_offset must be >= 0.');
        }

        $bounds    = self::resolvePeriodBounds($periodType, $periodOffset);
        $periodStart = $bounds['start'];
        $periodEnd   = $bounds['end'];
        $startSql    = $periodStart->format('Y-m-d H:i:s');
        $endSql      = $periodEnd->format('Y-m-d H:i:s');

        $employeesRows = self::fetchRelevantEmployees($pdo, $tenantId, $startSql, $endSql);

        $totalHours        = 0.0;
        $totalLaborCost    = 0.0;
        $totalDeductions   = 0.0;
        $totalPayout       = 0.0;
        $employees         = [];

        foreach ($employeesRows as $user) {
            $uid = (int)$user['id'];
            $empData = PayrollEngine::calculate(
                $pdo,
                $tenantId,
                (string)$uid,
                $periodType,
                $periodOffset
            );
            $payroll = $empData['payroll'];

            $hours   = (float)($payroll['hours']['total'] ?? 0.0);
            $rate    = (float)($payroll['hourly_rate'] ?? 0.0);
            $gross   = (float)($payroll['gross_pay'] ?? 0.0);
            $net     = (float)($payroll['net_pay'] ?? 0.0);
            $dedAll  = (float)($payroll['total_deductions'] ?? 0.0);

            $mealTotal       = 0.0;
            $nonMealDeduct   = 0.0;
            foreach ($payroll['deductions'] ?? [] as $d) {
                $amt = (float)($d['total'] ?? 0.0);
                if (($d['type'] ?? '') === 'meal') {
                    $mealTotal += $amt;
                } else {
                    $nonMealDeduct += $amt;
                }
            }

            $payout        = max(0.0, $net);
            $carryForward  = min(0.0, $net);

            $employees[] = [
                'user_id'       => (string)$uid,
                'name'          => self::displayName($user),
                'hours'         => round($hours, 2),
                'rate'          => number_format($rate, 2, '.', ''),
                'gross'         => number_format($gross, 2, '.', ''),
                'deductions'    => number_format($nonMealDeduct, 2, '.', ''),
                'meals'         => number_format($mealTotal, 2, '.', ''),
                'net'           => number_format($net, 2, '.', ''),
                'payout'        => number_format($payout, 2, '.', ''),
                'carry_forward' => number_format($carryForward, 2, '.', ''),
            ];

            $totalHours += $hours;
            $totalLaborCost += $gross;
            $totalDeductions += $dedAll;
            $totalPayout += $payout;
        }

        $live = self::computeLiveShiftMetrics($pdo, $tenantId);

        return [
            'team_payroll' => [
                'period'     => self::periodKey($periodStart, $periodType),
                'employees'  => $employees,
                'totals'     => [
                    'total_hours'        => round($totalHours, 2),
                    'total_labor_cost'   => number_format($totalLaborCost, 2, '.', ''),
                    'total_deductions'   => number_format($totalDeductions, 2, '.', ''),
                    'total_payout'       => number_format($totalPayout, 2, '.', ''),
                ],
                'live_shift' => $live,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchRelevantEmployees(
        PDO $pdo,
        int $tenantId,
        string $startSql,
        string $endSql
    ): array {
        $sql = "
            SELECT DISTINCT u.id, u.first_name, u.last_name, u.username
            FROM sh_users u
            WHERE u.tenant_id = :tid
              AND u.is_deleted = 0
              AND (
                u.is_active = 1
                OR EXISTS (
                    SELECT 1
                    FROM sh_work_sessions s
                    WHERE s.tenant_id = u.tenant_id
                      AND s.user_id = u.id
                      AND s.start_time <= :endB
                      AND (s.end_time IS NULL OR s.end_time >= :startB)
                )
              )
            ORDER BY u.id ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tid'    => $tenantId,
            ':startB' => $startSql,
            ':endB'   => $endSql,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }

    /**
     * @return array{active_employees: int, burn_rate_per_hour: string, cost_so_far: string}
     */
    private static function computeLiveShiftMetrics(PDO $pdo, int $tenantId): array
    {
        $sql = "
            SELECT u.hourly_rate,
                   TIMESTAMPDIFF(SECOND, ws.start_time, NOW()) AS elapsed_seconds
            FROM sh_work_sessions ws
            INNER JOIN sh_users u
                ON u.id = ws.user_id
               AND u.tenant_id = ws.tenant_id
               AND u.is_deleted = 0
            WHERE ws.tenant_id = :tid
              AND ws.end_time IS NULL
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':tid' => $tenantId]);

        $burnRatePerHour = 0.0;
        $costSoFar       = 0.0;
        $count           = 0;

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rate = (float)($row['hourly_rate'] ?? 0.0);
            $sec  = (int)($row['elapsed_seconds'] ?? 0);
            if ($sec < 0) {
                $sec = 0;
            }
            $burnRatePerHour += $rate;
            $costSoFar += ($sec / 3600.0) * $rate;
            ++$count;
        }

        return [
            'active_employees'    => $count,
            'burn_rate_per_hour'  => number_format($burnRatePerHour, 2, '.', ''),
            'cost_so_far'         => number_format(round($costSoFar, 2), 2, '.', ''),
        ];
    }

    private static function displayName(array $user): string
    {
        $fn = trim((string)($user['first_name'] ?? ''));
        $ln = trim((string)($user['last_name'] ?? ''));
        $full = trim($fn . ' ' . $ln);
        if ($full !== '') {
            return $full;
        }
        $un = trim((string)($user['username'] ?? ''));
        if ($un !== '') {
            return $un;
        }

        return 'User #' . (int)($user['id'] ?? 0);
    }

    private static function periodKey(\DateTimeImmutable $start, string $periodType): string
    {
        if ($periodType === 'month') {
            return $start->format('Y-m');
        }
        if ($periodType === 'week') {
            return $start->format('o') . '-W' . $start->format('W');
        }

        return $start->format('Y');
    }

    /**
     * Mirrors {@see PayrollEngine} internal window for filtering (start/end as DateTimeImmutable).
     *
     * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable}
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

            return ['start' => $start, 'end' => $end];
        }

        if ($type === 'week') {
            $dow    = (int)$now->format('N');
            $monday = $now->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0)
                ->modify('-' . (7 * $offset) . ' days');
            $start = $monday;
            if ($offset === 0) {
                $end = $now;
            } else {
                $end = $start->modify('+6 days')->setTime(23, 59, 59);
            }

            return ['start' => $start, 'end' => $end];
        }

        $year  = (int)$now->format('Y') - $offset;
        $start = new \DateTimeImmutable("{$year}-01-01 00:00:00");
        if ($offset === 0) {
            $end = $now;
        } else {
            $end = new \DateTimeImmutable("{$year}-12-31 23:59:59");
        }

        return ['start' => $start, 'end' => $end];
    }
}
