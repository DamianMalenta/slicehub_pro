<?php

declare(strict_types=1);

/**
 * HR Clock Engine — Faza 3A (POS + Kiosk)
 *
 * Nowy silnik clock-in / clock-out oparty o `sh_employees.id` jako autorytatywny
 * identyfikator pracownika (rozwiązanie HR-10). Obsługuje:
 *   - TERMINAL binding (sh_work_sessions.terminal_id)
 *   - CLOCK source tracking (kiosk | pos | mobile | manager_override | system_auto)
 *   - GEO lat/lon przy clock-in/out
 *   - Rate SNAPSHOT (sh_employee_rates @ start_time) w response
 *   - EVENT OUTBOX (sh_event_outbox aggregate_type='shift') — konsumpcja przez
 *     worker_driver_fanout i worker_payroll_accrual (Faza 3B)
 *
 * Jedyny kanoniczny silnik clock-in/out w SliceHub. Stary `core/ClockEngine.php`
 * został usunięty w ramach konsolidacji silosu HR (2026-04-23) — nie istnieje.
 *
 * Wywołania publiczne: clockIn / clockOut / status / resolveEmployeeByPin /
 * resolveEmployeeByUser.
 *
 * INTEGRACJA CROSS-SILO (patrz _docs/18_BACKOFFICE_HR_LOGIC.md §9):
 *   - Inne silosy (POS, Tables, Kiosk UI) NIE `require_once` tej klasy.
 *     Komunikacja wyłącznie przez `api/backoffice/hr/engine.php` (REST)
 *     albo przez subskrypcję eventów z `sh_event_outbox` (aggregate_type='shift').
 *   - HR → Logistyka: `sh_drivers` NIE jest modyfikowany synchronicznie przez ten
 *     silnik. Zamiast tego emitujemy `employee.clocked_in` / `employee.clocked_out`
 *     — konsument `worker_driver_fanout` (Faza 3B, pod FF `HR_USE_EVENT_DRIVER_FANOUT`)
 *     decyduje o `sh_drivers.status` (HR-4).
 *
 * Spec: _docs/18_BACKOFFICE_HR_LOGIC.md §5, §9
 */
final class HrClockEngine
{
    // === ASCII error codes (kontrakt API) =================================
    public const ERR_EMPLOYEE_NOT_FOUND      = 'EMPLOYEE_NOT_FOUND';
    public const ERR_EMPLOYEE_SUSPENDED      = 'EMPLOYEE_SUSPENDED';
    public const ERR_EMPLOYEE_TERMINATED     = 'EMPLOYEE_TERMINATED';
    public const ERR_ALREADY_CLOCKED_IN      = 'ALREADY_CLOCKED_IN';
    public const ERR_NO_OPEN_SESSION         = 'NO_OPEN_SESSION';
    public const ERR_ACTIVE_DELIVERIES       = 'ACTIVE_DELIVERIES';
    public const ERR_SESSION_TOO_LONG        = 'SESSION_TOO_LONG';
    public const ERR_NO_ACTIVE_RATE          = 'NO_ACTIVE_RATE';
    public const ERR_INVALID_PIN_FORMAT      = 'INVALID_PIN_FORMAT';
    public const ERR_PIN_NOT_MATCHED         = 'PIN_NOT_MATCHED';
    public const ERR_TERMINAL_NOT_REGISTERED = 'TERMINAL_NOT_REGISTERED';
    public const ERR_INVALID_SOURCE          = 'INVALID_SOURCE';

    /** Dozwolone wartości `clock_*_source` (odpowiada dictionary z m042). */
    public const SOURCES = ['kiosk', 'pos', 'mobile', 'manager_override', 'system_auto'];

    /** Guard: sesja dłuższa niż 24h wymaga manager_override. */
    private const MAX_SESSION_HOURS = 24;

    // =========================================================================
    // CLOCK-IN
    // =========================================================================

    /**
     * @param  array{
     *     terminal_id?: int|null,
     *     source?: string,
     *     geo_lat?: float|null,
     *     geo_lon?: float|null,
     *     user_id?: int|null          // opcjonalnie: sh_users.id aktora (dla audit)
     * } $opts
     *
     * @return array{
     *     session_uuid: string,
     *     employee_id: int,
     *     employee_code: string,
     *     employee_display_name: string,
     *     primary_role: string,
     *     start_time: string,
     *     hourly_rate: array{amount_minor:int, currency:string}|null,
     *     terminal_id: int|null,
     *     source: string
     * }
     *
     * @throws \InvalidArgumentException na złe argumenty (400-family)
     * @throws \RuntimeException         na naruszenie reguł biznesowych (ERR_*)
     * @throws \Throwable                na błąd DB (transakcja rollback)
     */
    public static function clockIn(PDO $pdo, int $tenantId, int $employeeId, array $opts = []): array
    {
        if ($tenantId <= 0 || $employeeId <= 0) {
            throw new \InvalidArgumentException('tenant_id and employee_id must be positive integers.');
        }

        $source = self::validateSource($opts['source'] ?? 'kiosk');
        $terminalId = isset($opts['terminal_id']) && $opts['terminal_id'] !== null
            ? (int)$opts['terminal_id']
            : null;
        $geoLat = isset($opts['geo_lat']) ? (float)$opts['geo_lat'] : null;
        $geoLon = isset($opts['geo_lon']) ? (float)$opts['geo_lon'] : null;

        if ($terminalId !== null && !self::terminalExists($pdo, $tenantId, $terminalId)) {
            throw new \RuntimeException(self::ERR_TERMINAL_NOT_REGISTERED);
        }

        $pdo->beginTransaction();

        try {
            $employee = self::loadEmployeeOrFail($pdo, $tenantId, $employeeId);

            if (self::hasOpenSession($pdo, $tenantId, $employeeId)) {
                throw new \RuntimeException(self::ERR_ALREADY_CLOCKED_IN);
            }

            $sessionUuid = self::uuidV4();

            $stmtIns = $pdo->prepare("
                INSERT INTO sh_work_sessions
                    (tenant_id, user_id, employee_id, session_uuid, start_time,
                     terminal_id, clock_in_source, geo_lat_in, geo_lon_in)
                VALUES
                    (:tid, :uid, :eid, :sid, NOW(),
                     :term, :src, :lat, :lon)
            ");
            $stmtIns->execute([
                ':tid'  => $tenantId,
                ':uid'  => $employee['user_id'],
                ':eid'  => $employeeId,
                ':sid'  => $sessionUuid,
                ':term' => $terminalId,
                ':src'  => $source,
                ':lat'  => $geoLat,
                ':lon'  => $geoLon,
            ]);

            if ($employee['user_id'] !== null) {
                $pdo->prepare('UPDATE sh_users SET last_seen = NOW() WHERE id = :id AND tenant_id = :tid')
                    ->execute([':id' => (int)$employee['user_id'], ':tid' => $tenantId]);
            }

            // Snapshot stawki ODPOWIADAJĄCEJ momentowi clock-in (snapshot to response,
            // NIE zapisujemy go jeszcze w ledgerze — to zrobi worker_payroll_accrual
            // po clock_out).
            $rate = self::resolveActiveRate($pdo, $tenantId, $employeeId, null);

            self::publishEvent($pdo, $tenantId, 'employee.clocked_in', $sessionUuid, [
                'employee_id'          => $employeeId,
                'employee_code'        => $employee['employee_code'],
                'employee_display_name'=> $employee['display_name'],
                'primary_role'         => $employee['primary_role'],
                'session_uuid'         => $sessionUuid,
                'terminal_id'          => $terminalId,
                'source'               => $source,
            ], $source, $opts['user_id'] ?? null);

            $pdo->commit();

            $startIso = self::fetchSessionStartIso($pdo, $sessionUuid);

            return [
                'session_uuid'          => $sessionUuid,
                'employee_id'           => $employeeId,
                'employee_code'         => $employee['employee_code'],
                'employee_display_name' => $employee['display_name'],
                'primary_role'          => $employee['primary_role'],
                'start_time'            => $startIso,
                'hourly_rate'           => $rate,
                'terminal_id'           => $terminalId,
                'source'                => $source,
            ];

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Zmiana (tenant, employee_id, open_guard=1) duplicate key → ERR_ALREADY_CLOCKED_IN
            if ($e instanceof \PDOException && str_contains($e->getMessage(), 'uq_ws_single_open')) {
                throw new \RuntimeException(self::ERR_ALREADY_CLOCKED_IN);
            }
            throw $e;
        }
    }

    // =========================================================================
    // CLOCK-OUT
    // =========================================================================

    /**
     * @param  array{
     *     terminal_id?: int|null,
     *     source?: string,
     *     geo_lat?: float|null,
     *     geo_lon?: float|null,
     *     user_id?: int|null,
     *     allow_long_session?: bool   // bypass SESSION_TOO_LONG (manager_override)
     * } $opts
     *
     * @return array{
     *     session_uuid: string,
     *     employee_id: int,
     *     employee_code: string,
     *     employee_display_name: string,
     *     start_time: string,
     *     end_time: string,
     *     total_hours: float,
     *     preview_earnings: array{amount_minor:int, currency:string}|null,
     *     source: string
     * }
     */
    public static function clockOut(PDO $pdo, int $tenantId, int $employeeId, array $opts = []): array
    {
        if ($tenantId <= 0 || $employeeId <= 0) {
            throw new \InvalidArgumentException('tenant_id and employee_id must be positive integers.');
        }

        $source = self::validateSource($opts['source'] ?? 'kiosk');
        $geoLat = isset($opts['geo_lat']) ? (float)$opts['geo_lat'] : null;
        $geoLon = isset($opts['geo_lon']) ? (float)$opts['geo_lon'] : null;
        $allowLong = (bool)($opts['allow_long_session'] ?? false);

        $pdo->beginTransaction();

        try {
            $employee = self::loadEmployeeOrFail($pdo, $tenantId, $employeeId);

            $stmtSess = $pdo->prepare("
                SELECT id, session_uuid, start_time
                FROM sh_work_sessions
                WHERE tenant_id = :tid
                  AND employee_id = :eid
                  AND end_time IS NULL
                ORDER BY start_time DESC
                LIMIT 1
            ");
            $stmtSess->execute([':tid' => $tenantId, ':eid' => $employeeId]);
            $session = $stmtSess->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                throw new \RuntimeException(self::ERR_NO_OPEN_SESSION);
            }

            if ($employee['primary_role'] === 'driver' && self::driverBusy($pdo, $tenantId, $employee['user_id'])) {
                throw new \RuntimeException(self::ERR_ACTIVE_DELIVERIES);
            }

            if (!$allowLong) {
                $startTs = strtotime((string)$session['start_time']);
                if ($startTs !== false && (time() - $startTs) > self::MAX_SESSION_HOURS * 3600) {
                    throw new \RuntimeException(self::ERR_SESSION_TOO_LONG);
                }
            }

            $sessionUuid = (string)$session['session_uuid'];
            $sessionId   = (int)$session['id'];

            $stmtUpd = $pdo->prepare("
                UPDATE sh_work_sessions
                SET end_time         = NOW(),
                    total_hours      = ROUND(TIMESTAMPDIFF(SECOND, start_time, NOW()) / 3600.0, 4),
                    clock_out_source = :src,
                    geo_lat_out      = :lat,
                    geo_lon_out      = :lon
                WHERE id = :id AND tenant_id = :tid
            ");
            $stmtUpd->execute([
                ':id'  => $sessionId,
                ':tid' => $tenantId,
                ':src' => $source,
                ':lat' => $geoLat,
                ':lon' => $geoLon,
            ]);

            $stmtFin = $pdo->prepare("
                SELECT start_time, end_time, total_hours
                FROM sh_work_sessions WHERE id = :id LIMIT 1
            ");
            $stmtFin->execute([':id' => $sessionId]);
            $fin = $stmtFin->fetch(PDO::FETCH_ASSOC) ?: [];

            $totalHours = (float)($fin['total_hours'] ?? 0);
            $rate = self::resolveActiveRate($pdo, $tenantId, $employeeId, (string)$session['start_time']);
            $preview = null;
            if ($rate !== null && $totalHours > 0) {
                $preview = [
                    'amount_minor' => (int)round($totalHours * $rate['amount_minor']),
                    'currency'     => $rate['currency'],
                ];
            }

            self::publishEvent($pdo, $tenantId, 'employee.clocked_out', $sessionUuid, [
                'employee_id'          => $employeeId,
                'employee_code'        => $employee['employee_code'],
                'employee_display_name'=> $employee['display_name'],
                'primary_role'         => $employee['primary_role'],
                'session_uuid'         => $sessionUuid,
                'start_time'           => self::formatUtcIso((string)$fin['start_time']),
                'end_time'             => self::formatUtcIso((string)$fin['end_time']),
                'total_hours'          => $totalHours,
                'rate_at_clock_in'     => $rate,
                'source'               => $source,
            ], $source, $opts['user_id'] ?? null);

            $pdo->commit();

            return [
                'session_uuid'          => $sessionUuid,
                'employee_id'           => $employeeId,
                'employee_code'         => $employee['employee_code'],
                'employee_display_name' => $employee['display_name'],
                'start_time'            => self::formatUtcIso((string)$fin['start_time']),
                'end_time'              => self::formatUtcIso((string)$fin['end_time']),
                'total_hours'           => round($totalHours, 4),
                'preview_earnings'      => $preview,
                'source'                => $source,
            ];

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // =========================================================================
    // STATUS — kto jest obecnie na zmianie
    // =========================================================================

    /**
     * @return array{
     *     open_sessions: list<array{
     *         session_uuid: string,
     *         employee_id: int,
     *         employee_code: string,
     *         employee_display_name: string,
     *         primary_role: string,
     *         start_time: string,
     *         elapsed_seconds: int,
     *         terminal_id: int|null,
     *         source: string
     *     }>
     * }
     */
    public static function status(PDO $pdo, int $tenantId, ?int $employeeId = null): array
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('tenant_id must be positive.');
        }

        $sql = "
            SELECT ws.session_uuid, ws.employee_id, ws.start_time,
                   ws.terminal_id, ws.clock_in_source,
                   TIMESTAMPDIFF(SECOND, ws.start_time, NOW()) AS elapsed_seconds,
                   e.employee_code, e.display_name, e.primary_role
            FROM sh_work_sessions ws
            JOIN sh_employees e ON e.id = ws.employee_id AND e.tenant_id = ws.tenant_id
            WHERE ws.tenant_id = :tid
              AND ws.end_time IS NULL
              AND ws.employee_id IS NOT NULL
        ";
        $params = [':tid' => $tenantId];

        if ($employeeId !== null) {
            $sql .= " AND ws.employee_id = :eid";
            $params[':eid'] = $employeeId;
        }

        $sql .= " ORDER BY ws.start_time ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'session_uuid'          => (string)$r['session_uuid'],
                'employee_id'           => (int)$r['employee_id'],
                'employee_code'         => (string)$r['employee_code'],
                'employee_display_name' => (string)$r['display_name'],
                'primary_role'          => (string)$r['primary_role'],
                'start_time'            => self::formatUtcIso((string)$r['start_time']),
                'elapsed_seconds'       => (int)$r['elapsed_seconds'],
                'terminal_id'           => $r['terminal_id'] !== null ? (int)$r['terminal_id'] : null,
                'source'                => (string)$r['clock_in_source'],
            ];
        }

        return ['open_sessions' => $out];
    }

    // =========================================================================
    // AUTH RESOLUTION — employee lookup
    // =========================================================================

    /**
     * Kiosk mode: weryfikacja PIN-u przez bcrypt. Zwraca employee row | null.
     * Nie rzuca wyjątku na brak dopasowania — caller sam decyduje.
     *
     * Iteracja po aktywnych pracownikach w tenancie z auth_pin_hash IS NOT NULL.
     * Liczba pracowników w lokalu pizzy jest mała (<100), więc O(N) jest OK.
     * Ograniczamy constant-time tylko przez password_verify (wewnętrznie CT).
     *
     * @return array{
     *     id:int, user_id:?int, employee_code:string, display_name:string,
     *     primary_role:string, status:string
     * }|null
     */
    public static function resolveEmployeeByPin(PDO $pdo, int $tenantId, string $pin): ?array
    {
        $pin = trim($pin);
        if ($pin === '' || !preg_match('/^\d{4,6}$/', $pin)) {
            throw new \InvalidArgumentException(self::ERR_INVALID_PIN_FORMAT);
        }

        $stmt = $pdo->prepare("
            SELECT id, user_id, employee_code, display_name, primary_role, status, auth_pin_hash
            FROM sh_employees
            WHERE tenant_id = :tid
              AND is_deleted = 0
              AND status = 'active'
              AND auth_pin_hash IS NOT NULL
        ");
        $stmt->execute([':tid' => $tenantId]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $hash = (string)$row['auth_pin_hash'];
            if ($hash !== '' && password_verify($pin, $hash)) {
                unset($row['auth_pin_hash']);
                $row['id']      = (int)$row['id'];
                $row['user_id'] = $row['user_id'] !== null ? (int)$row['user_id'] : null;
                return $row;
            }
        }

        return null;
    }

    /**
     * Session mode: rozwiązanie employee_id z sh_users.id (z sesji auth_guard).
     *
     * @return array{
     *     id:int, user_id:int, employee_code:string, display_name:string,
     *     primary_role:string, status:string
     * }|null
     */
    public static function resolveEmployeeByUser(PDO $pdo, int $tenantId, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $stmt = $pdo->prepare("
            SELECT id, user_id, employee_code, display_name, primary_role, status
            FROM sh_employees
            WHERE tenant_id = :tid AND user_id = :uid AND is_deleted = 0
            LIMIT 1
        ");
        $stmt->execute([':tid' => $tenantId, ':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['id']      = (int)$row['id'];
        $row['user_id'] = (int)$row['user_id'];
        return $row;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * @return array{
     *     id:int, user_id:?int, employee_code:string, display_name:string,
     *     primary_role:string, status:string
     * }
     * @throws \RuntimeException gdy pracownik nie istnieje / zawieszony / zwolniony
     */
    private static function loadEmployeeOrFail(PDO $pdo, int $tenantId, int $employeeId): array
    {
        $stmt = $pdo->prepare("
            SELECT id, user_id, employee_code, display_name, primary_role, status
            FROM sh_employees
            WHERE id = :id AND tenant_id = :tid AND is_deleted = 0
            LIMIT 1
        ");
        $stmt->execute([':id' => $employeeId, ':tid' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new \RuntimeException(self::ERR_EMPLOYEE_NOT_FOUND);
        }
        if ($row['status'] === 'terminated') {
            throw new \RuntimeException(self::ERR_EMPLOYEE_TERMINATED);
        }
        if ($row['status'] !== 'active') {
            throw new \RuntimeException(self::ERR_EMPLOYEE_SUSPENDED);
        }

        $row['id']      = (int)$row['id'];
        $row['user_id'] = $row['user_id'] !== null ? (int)$row['user_id'] : null;
        return $row;
    }

    private static function hasOpenSession(PDO $pdo, int $tenantId, int $employeeId): bool
    {
        $stmt = $pdo->prepare("
            SELECT 1 FROM sh_work_sessions
            WHERE tenant_id = :tid AND employee_id = :eid AND end_time IS NULL
            LIMIT 1
        ");
        $stmt->execute([':tid' => $tenantId, ':eid' => $employeeId]);
        return (bool)$stmt->fetchColumn();
    }

    private static function driverBusy(PDO $pdo, int $tenantId, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT status FROM sh_drivers
                WHERE tenant_id = :tid AND user_id = :uid
                LIMIT 1
            ");
            $stmt->execute([':tid' => $tenantId, ':uid' => $userId]);
            $st = (string)$stmt->fetchColumn();
            return $st === 'busy';
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function terminalExists(PDO $pdo, int $tenantId, int $terminalId): bool
    {
        try {
            $stmt = $pdo->prepare("
                SELECT 1 FROM sh_pos_terminals
                WHERE tenant_id = :tid AND id = :id
                LIMIT 1
            ");
            $stmt->execute([':tid' => $tenantId, ':id' => $terminalId]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Wybiera aktywną stawkę dla pracownika. Jeśli $atMoment=null → NOW().
     *
     * @return array{amount_minor:int, currency:string}|null
     */
    private static function resolveActiveRate(PDO $pdo, int $tenantId, int $employeeId, ?string $atMoment): ?array
    {
        if ($atMoment === null) {
            $sql = "
                SELECT amount_minor, currency
                FROM sh_employee_rates
                WHERE tenant_id = :tid AND employee_id = :eid AND rate_type = 'hourly'
                  AND effective_from <= NOW()
                  AND (effective_to IS NULL OR effective_to > NOW())
                ORDER BY effective_from DESC LIMIT 1
            ";
            $params = [':tid' => $tenantId, ':eid' => $employeeId];
        } else {
            $sql = "
                SELECT amount_minor, currency
                FROM sh_employee_rates
                WHERE tenant_id = :tid AND employee_id = :eid AND rate_type = 'hourly'
                  AND effective_from <= :at
                  AND (effective_to IS NULL OR effective_to > :at)
                ORDER BY effective_from DESC LIMIT 1
            ";
            $params = [':tid' => $tenantId, ':eid' => $employeeId, ':at' => $atMoment];
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'amount_minor' => (int)$row['amount_minor'],
            'currency'     => (string)$row['currency'],
        ];
    }

    /**
     * Publikacja eventu HR do sh_event_outbox. Silent-degrades gdy tabeli nie ma.
     * Idempotencja: idempotency_key = '{session_uuid}:{event_type}'.
     */
    private static function publishEvent(
        PDO $pdo,
        int $tenantId,
        string $eventType,
        string $sessionUuid,
        array $payload,
        string $source,
        ?int $actorUserId
    ): void {
        try {
            $pdo->prepare("SELECT 1 FROM sh_event_outbox LIMIT 0")->execute();
        } catch (\Throwable $e) {
            // outbox nie istnieje — skip (pre-m026 baza)
            return;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO sh_event_outbox
                    (tenant_id, event_type, aggregate_type, aggregate_id,
                     idempotency_key, payload, source, actor_type, actor_id,
                     status, attempts, created_at)
                VALUES
                    (:tid, :etype, 'shift', :aid,
                     :idk, :pl, :src, :actor_t, :actor_i,
                     'pending', 0, NOW())
            ");
            $stmt->execute([
                ':tid'     => $tenantId,
                ':etype'   => $eventType,
                ':aid'     => $sessionUuid,
                ':idk'     => $sessionUuid . ':' . $eventType,
                ':pl'      => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':src'     => $source,
                ':actor_t' => $actorUserId !== null ? 'staff' : 'system',
                ':actor_i' => $actorUserId !== null ? (string)$actorUserId : null,
            ]);
        } catch (\Throwable $e) {
            error_log('[HrClockEngine] publishEvent failed (' . $eventType . '): ' . $e->getMessage());
        }
    }

    private static function validateSource(string $source): string
    {
        $source = strtolower(trim($source));
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException(self::ERR_INVALID_SOURCE);
        }
        return $source;
    }

    private static function fetchSessionStartIso(PDO $pdo, string $sessionUuid): string
    {
        $stmt = $pdo->prepare("SELECT start_time FROM sh_work_sessions WHERE session_uuid = :sid LIMIT 1");
        $stmt->execute([':sid' => $sessionUuid]);
        $start = (string)$stmt->fetchColumn();
        return self::formatUtcIso($start);
    }

    private static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return sprintf('%s-%s-%s-%s-%s',
            substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4),
            substr($h, 16, 4), substr($h, 20, 12));
    }

    private static function formatUtcIso(string $mysqlDatetime): string
    {
        $clean = preg_replace('/\.\d+$/', '', trim($mysqlDatetime));
        if ($clean === '' || $clean === null) {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($clean);
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable $e) {
            return $mysqlDatetime;
        }
    }
}
