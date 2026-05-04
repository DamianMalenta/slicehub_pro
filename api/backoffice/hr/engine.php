<?php

declare(strict_types=1);

@ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * SliceHub Enterprise — HR Engine API (Faza 3A)
 *
 * /api/backoffice/hr/engine.php
 *
 * Akcje (pole `action` w JSON body):
 *   - clock_in        — rozpoczęcie zmiany
 *   - clock_out       — zakończenie zmiany
 *   - clock_status    — lista aktualnie otwartych sesji w tenancie
 *
 * Backoffice (owner/manager/admin — hrRequireManager):
 *   - employees_list       — lista profili HR (+ opcjonalnie join do konta)
 *   - employee_get         — jeden rekord + aktualna stawka godzinowa
 *   - employee_upsert      — create/update profilu; opcjonalnie create_login (nowe konto sh_users)
 *   - employee_pin_set     — ustawia bcrypt PIN kioskowy (sh_employees.auth_pin_hash)
 *   - employee_rate_set    — nowa stawka godzinowa (zamyka poprzednią linię w sh_employee_rates)
 *   - hr_users_unlinked    — sh_users w tenancie bez aktywnego powiązania do sh_employees (wybór konta)
 *
 * AUTH (wymagane dla każdej akcji):
 *   Endpoint jest za auth_guard.php → ma $tenant_id i $user_id z sesji/JWT.
 *   POS/Kiosk są autoryzowane jako managera/terminal; PIN pracownika to
 *   DRUGI factor w obrębie zaufanej sesji terminala.
 *
 * PIN vs POS: AuthEngine::loginKiosk (PIN na kasę) czyta sh_users.pin_code.
 *   employee_pin_set / create_login.pos_pin ustawiają jednocześnie auth_pin_hash
 *   (HR clock) oraz pin_code powiązanego konta (POS). PIN = dokładnie 4 cyfry.
 *
 * IDENTYFIKACJA PRACOWNIKA (kto clock-inuje):
 *   - auth.pin         : "1234" — tryb KIOSK (PIN bcrypt w sh_employees)
 *   - auth.self        : true   — tryb SESSION (employee = sh_employees.user_id = $user_id)
 *   - auth.employee_id : 42     — tryb MANAGER_OVERRIDE (tylko dla managera/ownera)
 *
 * Spec:
 *   _docs/18_BACKOFFICE_HR_LOGIC.md §5
 */

function hrResponse(bool $ok, $data = null, ?string $msg = null, ?string $code = null): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $out = ['success' => $ok, 'data' => $data, 'message' => $msg];
    if ($code !== null) {
        $out['code'] = $code;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

function hrFail(int $httpCode, string $code, ?string $msg = null): void
{
    http_response_code($httpCode);
    hrResponse(false, null, $msg ?? $code, $code);
}

try {
    require_once __DIR__ . '/../../../core/db_config.php';
    require_once __DIR__ . '/../../../core/auth_guard.php';
    require_once __DIR__ . '/../../../core/HrClockEngine.php';

    /** @var PDO $pdo */
    /** @var int $tenant_id */
    /** @var int $user_id */

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        hrFail(405, 'METHOD_NOT_ALLOWED', 'Only POST is allowed.');
    }

    $raw   = file_get_contents('php://input') ?: '{}';
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        hrFail(400, 'INVALID_JSON', 'Request body must be a JSON object.');
    }

    $action = trim((string)($input['action'] ?? ''));
    if ($action === '') {
        hrFail(400, 'ACTION_REQUIRED', 'Missing "action" field.');
    }

    // Rozwiązanie employee_id z bloku `auth`.
    $resolveEmployeeId = static function (array $input) use ($pdo, $tenant_id, $user_id): array {
        $auth = is_array($input['auth'] ?? null) ? $input['auth'] : [];

        // Tryb SESSION — pracownik = aktor sesji
        if (!empty($auth['self'])) {
            $emp = HrClockEngine::resolveEmployeeByUser($pdo, $tenant_id, $user_id);
            if ($emp === null) {
                hrFail(404, HrClockEngine::ERR_EMPLOYEE_NOT_FOUND,
                    'Current session user is not registered as an employee.');
            }
            return [$emp['id'], 'session'];
        }

        // Tryb KIOSK — PIN bcrypt
        if (isset($auth['pin']) && $auth['pin'] !== '') {
            try {
                $emp = HrClockEngine::resolveEmployeeByPin($pdo, $tenant_id, (string)$auth['pin']);
            } catch (\InvalidArgumentException $e) {
                hrFail(400, $e->getMessage(), 'PIN must be exactly 4 digits.');
            }
            if ($emp === null) {
                hrFail(401, HrClockEngine::ERR_PIN_NOT_MATCHED, 'PIN did not match any active employee.');
            }
            return [(int)$emp['id'], 'kiosk'];
        }

        // Tryb MANAGER_OVERRIDE — wprost employee_id (wymaga managera/ownera)
        if (isset($auth['employee_id'])) {
            $actor = hrLoadActorRole($pdo, $tenant_id, $user_id);
            if (!in_array($actor, ['owner', 'manager'], true)) {
                hrFail(403, 'FORBIDDEN_OVERRIDE',
                    'Only owner/manager can clock another employee.');
            }
            return [(int)$auth['employee_id'], 'manager_override'];
        }

        hrFail(400, 'AUTH_MODE_REQUIRED',
            'Provide one of: auth.self=true, auth.pin, auth.employee_id.');
    };

    $terminalId = isset($input['terminal_id']) && $input['terminal_id'] !== null
        ? (int)$input['terminal_id']
        : null;
    $source = trim((string)($input['source'] ?? 'pos'));
    $geo = is_array($input['geo'] ?? null) ? $input['geo'] : [];
    $geoLat = isset($geo['lat']) ? (float)$geo['lat'] : null;
    $geoLon = isset($geo['lon']) ? (float)$geo['lon'] : null;

    switch ($action) {
        // -----------------------------------------------------------------
        case 'clock_in': {
            [$employeeId] = $resolveEmployeeId($input);

            try {
                $result = HrClockEngine::clockIn($pdo, $tenant_id, $employeeId, [
                    'terminal_id' => $terminalId,
                    'source'      => $source,
                    'geo_lat'     => $geoLat,
                    'geo_lon'     => $geoLon,
                    'user_id'     => $user_id,
                ]);
            } catch (\InvalidArgumentException $e) {
                hrFail(400, $e->getMessage());
            } catch (\RuntimeException $e) {
                hrFail(409, $e->getMessage());
            }

            hrResponse(true, $result, 'Clock-in OK.');
            break;
        }

        // -----------------------------------------------------------------
        case 'clock_out': {
            [$employeeId] = $resolveEmployeeId($input);

            $allowLong = (bool)($input['manager_override'] ?? false);
            if ($allowLong) {
                $actor = hrLoadActorRole($pdo, $tenant_id, $user_id);
                if (!in_array($actor, ['owner', 'manager'], true)) {
                    hrFail(403, 'FORBIDDEN_OVERRIDE',
                        'Only owner/manager can force clock-out with manager_override.');
                }
            }

            try {
                $result = HrClockEngine::clockOut($pdo, $tenant_id, $employeeId, [
                    'source'             => $source,
                    'geo_lat'            => $geoLat,
                    'geo_lon'            => $geoLon,
                    'user_id'            => $user_id,
                    'allow_long_session' => $allowLong,
                ]);
            } catch (\InvalidArgumentException $e) {
                hrFail(400, $e->getMessage());
            } catch (\RuntimeException $e) {
                hrFail(409, $e->getMessage());
            }

            hrResponse(true, $result, 'Clock-out OK.');
            break;
        }

        // -----------------------------------------------------------------
        case 'clock_status': {
            $filterEmployeeId = isset($input['employee_id']) ? (int)$input['employee_id'] : null;
            $auth = is_array($input['auth'] ?? null) ? $input['auth'] : [];
            $empSnapshot = null;
            if (isset($auth['pin']) && (string)$auth['pin'] !== '') {
                try {
                    $emp = HrClockEngine::resolveEmployeeByPin($pdo, $tenant_id, (string)$auth['pin']);
                } catch (\InvalidArgumentException $e) {
                    hrFail(400, HrClockEngine::ERR_INVALID_PIN_FORMAT, $e->getMessage());
                }
                if ($emp === null) {
                    hrFail(401, HrClockEngine::ERR_PIN_NOT_MATCHED, 'PIN nie pasuje do profilu HR.');
                }
                $filterEmployeeId = (int)$emp['id'];
                $empSnapshot = [
                    'id'             => (int)$emp['id'],
                    'display_name'   => (string)($emp['display_name'] ?? ''),
                    'employee_code'  => (string)($emp['employee_code'] ?? ''),
                    'primary_role'   => (string)($emp['primary_role'] ?? ''),
                ];
            }
            $result = HrClockEngine::status($pdo, $tenant_id, $filterEmployeeId);
            if ($empSnapshot !== null) {
                $result['employee_snapshot'] = $empSnapshot;
            }
            hrResponse(true, $result, 'Status OK.');
            break;
        }

        // -----------------------------------------------------------------
        // Backoffice — kadry (wymaga owner / manager / admin)
        // -----------------------------------------------------------------
        case 'employees_list': {
            hrRequireManager($pdo, $tenant_id, $user_id);
            $includeDeleted = !empty($input['include_deleted']);
            $whereDel = $includeDeleted ? '1=1' : 'e.is_deleted = 0';
            $st = $pdo->prepare("
                SELECT
                    e.id, e.tenant_id, e.user_id, e.employee_code, e.display_name,
                    e.first_name, e.last_name, e.email, e.phone, e.birth_date, e.hire_date, e.termination_date,
                    e.primary_role, e.status, e.default_currency, e.notes,
                    e.auth_pin_updated_at, e.created_at, e.updated_at, e.is_deleted,
                    (e.auth_pin_hash IS NOT NULL AND e.auth_pin_hash != '') AS has_kiosk_pin,
                    u.username AS account_username, u.role AS account_role, u.is_active AS account_is_active
                FROM sh_employees e
                LEFT JOIN sh_users u
                    ON u.id = e.user_id AND u.tenant_id = e.tenant_id
                WHERE e.tenant_id = :tid AND {$whereDel}
                ORDER BY e.display_name ASC, e.id ASC
            ");
            $st->execute([':tid' => $tenant_id]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['user_id'] = $r['user_id'] !== null ? (int)$r['user_id'] : null;
                $r['has_kiosk_pin'] = (bool)($r['has_kiosk_pin'] ?? 0);
                $r['account_is_active'] = isset($r['account_is_active']) ? (int)$r['account_is_active'] : null;
            }
            unset($r);
            hrResponse(true, ['employees' => $rows], 'OK');
            break;
        }

        case 'employee_get': {
            hrRequireManager($pdo, $tenant_id, $user_id);
            $eid = (int)($input['employee_id'] ?? 0);
            if ($eid <= 0) {
                hrFail(400, 'EMPLOYEE_ID_REQUIRED', 'employee_id is required.');
            }
            $row = hrFetchEmployeeRow($pdo, $tenant_id, $eid);
            if ($row === null) {
                hrFail(404, 'NOT_FOUND', 'Employee not found.');
            }
            $rate = hrFetchCurrentHourlyRate($pdo, $tenant_id, $eid);
            hrResponse(true, ['employee' => $row, 'current_hourly_rate' => $rate], 'OK');
            break;
        }

        case 'employee_upsert': {
            hrRequireManager($pdo, $tenant_id, $user_id);
            $payload = $input['employee'] ?? $input;
            if (!is_array($payload)) {
                hrFail(400, 'INVALID_PAYLOAD', 'Provide employee object.');
            }
            try {
                $out = hrUpsertEmployee($pdo, $tenant_id, $user_id, $payload);
            } catch (\InvalidArgumentException $e) {
                hrFail(400, 'VALIDATION', $e->getMessage());
            } catch (\RuntimeException $e) {
                hrFail(409, 'CONFLICT', $e->getMessage());
            }
            hrResponse(true, $out, 'Saved.');
            break;
        }

        case 'employee_pin_set': {
            hrRequireManager($pdo, $tenant_id, $user_id);
            $eid = (int)($input['employee_id'] ?? 0);
            $pin = trim((string)($input['pin'] ?? ''));
            if ($eid <= 0) {
                hrFail(400, 'EMPLOYEE_ID_REQUIRED', 'employee_id is required.');
            }
            $row = hrFetchEmployeeRow($pdo, $tenant_id, $eid);
            if ($row === null) {
                hrFail(404, 'NOT_FOUND', 'Employee not found.');
            }
            try {
                hrApplyKioskPin($pdo, $tenant_id, $eid, $pin);
            } catch (\InvalidArgumentException $e) {
                hrFail(400, HrClockEngine::ERR_INVALID_PIN_FORMAT, $e->getMessage());
            }
            hrResponse(true, ['employee_id' => $eid], 'PIN updated (HR + POS kiosk).');
            break;
        }

        case 'employee_rate_set': {
            hrRequireManager($pdo, $tenant_id, $user_id);
            $eid = (int)($input['employee_id'] ?? 0);
            if ($eid <= 0) {
                hrFail(400, 'EMPLOYEE_ID_REQUIRED', 'employee_id is required.');
            }
            if (hrFetchEmployeeRow($pdo, $tenant_id, $eid) === null) {
                hrFail(404, 'NOT_FOUND', 'Employee not found.');
            }
            $amountMinor = hrResolveAmountMinor($input);
            $currency = strtoupper(substr(trim((string)($input['currency'] ?? 'PLN')), 0, 3));
            if (strlen($currency) !== 3) {
                hrFail(400, 'INVALID_CURRENCY', 'currency must be ISO 4217 (3 chars).');
            }
            $reason = trim((string)($input['reason'] ?? 'correction'));
            if (strlen($reason) > 32) {
                $reason = substr($reason, 0, 32);
            }
            $note = isset($input['note']) ? trim((string)$input['note']) : null;
            if ($note === '') {
                $note = null;
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare('
                    UPDATE sh_employee_rates
                    SET effective_to = NOW()
                    WHERE tenant_id = :tid
                      AND employee_id = :eid
                      AND rate_type = \'hourly\'
                      AND effective_to IS NULL
                ')->execute([':tid' => $tenant_id, ':eid' => $eid]);

                $pdo->prepare('
                    INSERT INTO sh_employee_rates
                        (tenant_id, employee_id, rate_type, amount_minor, currency,
                         effective_from, effective_to, reason, note, created_by_user_id, created_at)
                    VALUES
                        (:tid, :eid, \'hourly\', :amt, :cur, NOW(), NULL, :reason, :note, :uid, NOW())
                ')->execute([
                    ':tid'    => $tenant_id,
                    ':eid'    => $eid,
                    ':amt'    => $amountMinor,
                    ':cur'    => $currency,
                    ':reason' => $reason !== '' ? $reason : 'correction',
                    ':note'   => $note,
                    ':uid'    => $user_id,
                ]);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            hrResponse(true, ['employee_id' => $eid, 'amount_minor' => $amountMinor, 'currency' => $currency], 'Rate updated.');
            break;
        }

        case 'hr_users_unlinked': {
            hrRequireManager($pdo, $tenant_id, $user_id);
            $st = $pdo->prepare('
                SELECT u.id, u.username,
                       COALESCE(NULLIF(TRIM(u.name), \'\'),
                                TRIM(CONCAT(COALESCE(u.first_name,\'\'), \' \', COALESCE(u.last_name,\'\'))),
                                u.username) AS display_label,
                       u.role, u.status, u.is_active
                FROM sh_users u
                WHERE u.tenant_id = :tid
                  AND u.is_deleted = 0
                  AND NOT EXISTS (
                      SELECT 1 FROM sh_employees e
                      WHERE e.tenant_id = u.tenant_id
                        AND e.user_id = u.id
                        AND e.is_deleted = 0
                  )
                ORDER BY u.username ASC
            ');
            $st->execute([':tid' => $tenant_id]);
            $users = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($users as &$u) {
                $u['id'] = (int)$u['id'];
            }
            unset($u);
            hrResponse(true, ['users' => $users], 'OK');
            break;
        }

        default:
            hrFail(400, 'UNKNOWN_ACTION', "Unknown action: {$action}");
    }

} catch (\Throwable $e) {
    if (isset($pdo) && $pdo instanceof \PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (\Throwable $ignore) {}
    }
    error_log('[api/backoffice/hr/engine] FATAL: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    hrFail(500, 'SERVER_ERROR', 'Internal error. See server logs.');
}

/**
 * Zwraca rolę aktora ($user_id) w tenancie.
 * Używane do gate-owania akcji wymagających owner/manager.
 */
function hrLoadActorRole(PDO $pdo, int $tenantId, int $userId): string
{
    $stmt = $pdo->prepare("
        SELECT role FROM sh_users
        WHERE id = :uid AND tenant_id = :tid
        LIMIT 1
    ");
    $stmt->execute([':uid' => $userId, ':tid' => $tenantId]);
    return (string)$stmt->fetchColumn();
}

/**
 * @return list<string>
 */
function hrPrimaryRoles(): array
{
    return ['cook', 'waiter', 'driver', 'manager', 'cashier', 'cleaner', 'runner', 'shift_lead', 'owner', 'team'];
}

/**
 * @return list<string>
 */
function hrAccountRoles(): array
{
    return ['cook', 'waiter', 'driver', 'manager', 'cashier', 'cleaner', 'runner', 'shift_lead', 'team', 'admin', 'owner'];
}

function hrRequireManager(PDO $pdo, int $tenantId, int $actorUserId): void
{
    $role = hrLoadActorRole($pdo, $tenantId, $actorUserId);
    if (!in_array($role, ['owner', 'manager', 'admin'], true)) {
        hrFail(403, 'FORBIDDEN', 'Only owner, manager, or admin can manage HR.');
    }
}

/**
 * @return array<string, mixed>|null
 */
function hrFetchEmployeeRow(PDO $pdo, int $tenantId, int $employeeId): ?array
{
    $st = $pdo->prepare('
        SELECT
            e.*,
            (e.auth_pin_hash IS NOT NULL AND e.auth_pin_hash != \'\') AS has_kiosk_pin,
            u.username AS account_username, u.role AS account_role
        FROM sh_employees e
        LEFT JOIN sh_users u ON u.id = e.user_id AND u.tenant_id = e.tenant_id
        WHERE e.id = :eid AND e.tenant_id = :tid
        LIMIT 1
    ');
    $st->execute([':eid' => $employeeId, ':tid' => $tenantId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    unset($row['auth_pin_hash']);
    $row['id'] = (int)$row['id'];
    $row['user_id'] = $row['user_id'] !== null ? (int)$row['user_id'] : null;
    $row['has_kiosk_pin'] = (bool)($row['has_kiosk_pin'] ?? 0);
    return $row;
}

/**
 * @return array{amount_minor:int,currency:string,effective_from:string}|null
 */
function hrFetchCurrentHourlyRate(PDO $pdo, int $tenantId, int $employeeId): ?array
{
    $st = $pdo->prepare('
        SELECT amount_minor, currency, effective_from
        FROM sh_employee_rates
        WHERE tenant_id = :tid
          AND employee_id = :eid
          AND rate_type = \'hourly\'
          AND effective_to IS NULL
        ORDER BY id DESC
        LIMIT 1
    ');
    $st->execute([':tid' => $tenantId, ':eid' => $employeeId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return null;
    }
    return [
        'amount_minor'   => (int)$r['amount_minor'],
        'currency'       => (string)$r['currency'],
        'effective_from' => (string)$r['effective_from'],
    ];
}

/**
 * PIN 4 cyfry: bcrypt w sh_employees + plaintext sh_users.pin_code
 * dla powiązanego konta (login kiosk POS — AuthEngine::loginKiosk).
 */
function hrApplyKioskPin(PDO $pdo, int $tenantId, int $employeeId, string $pin): void
{
    $pin = trim($pin);
    if ($pin === '' || !preg_match('/^\d{4}$/', $pin)) {
        throw new InvalidArgumentException('PIN must be exactly 4 digits.');
    }

    $hash = password_hash($pin, PASSWORD_DEFAULT);
    $pdo->prepare('
        UPDATE sh_employees
        SET auth_pin_hash = :h,
            auth_pin_updated_at = NOW(),
            updated_at = NOW()
        WHERE id = :eid AND tenant_id = :tid
    ')->execute([':h' => $hash, ':eid' => $employeeId, ':tid' => $tenantId]);

    $st = $pdo->prepare('SELECT user_id FROM sh_employees WHERE id = :eid AND tenant_id = :tid LIMIT 1');
    $st->execute([':eid' => $employeeId, ':tid' => $tenantId]);
    $uid = $st->fetchColumn();
    if ($uid !== false && $uid !== null && (int)$uid > 0) {
        $pdo->prepare('
            UPDATE sh_users SET pin_code = :pin WHERE id = :uid AND tenant_id = :tid
        ')->execute([':pin' => $pin, ':uid' => (int)$uid, ':tid' => $tenantId]);
    }
}

function hrResolveAmountMinor(array $input): int
{
    if (isset($input['amount_minor'])) {
        return max(0, (int)$input['amount_minor']);
    }
    if (isset($input['hourly_amount_pln'])) {
        $pln = (float)$input['hourly_amount_pln'];
        if ($pln < 0) {
            throw new InvalidArgumentException('hourly_amount_pln must be >= 0.');
        }

        return (int)round($pln * 100);
    }
    throw new InvalidArgumentException('Provide amount_minor (grosze) or hourly_amount_pln.');
}

/**
 * Upewnij się, że user należy do tenanta i nie jest już przypięty do innego profilu HR.
 */
function hrAssertUserLinkable(PDO $pdo, int $tenantId, int $userId, ?int $excludeEmployeeId): void
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user_id.');
    }
    $st = $pdo->prepare('SELECT id FROM sh_users WHERE id = :uid AND tenant_id = :tid AND is_deleted = 0 LIMIT 1');
    $st->execute([':uid' => $userId, ':tid' => $tenantId]);
    if (!$st->fetchColumn()) {
        throw new RuntimeException('User does not belong to this tenant.');
    }
    $sql = '
        SELECT id FROM sh_employees
        WHERE tenant_id = :tid AND user_id = :uid AND is_deleted = 0
    ';
    $params = [':tid' => $tenantId, ':uid' => $userId];
    if ($excludeEmployeeId !== null && $excludeEmployeeId > 0) {
        $sql .= ' AND id != :ex';
        $params[':ex'] = $excludeEmployeeId;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    if ($st->fetchColumn()) {
        throw new RuntimeException('This user is already linked to another employee profile.');
    }
}

/**
 * @param array{username?:string,password?:string,role?:string,pos_pin?:string} $login
 * @param array<string,mixed> $nameSource first_name, last_name, display_name from employee payload
 */
function hrCreateUserForEmployee(PDO $pdo, int $tenantId, array $login, array $nameSource): int
{
    $username = trim((string)($login['username'] ?? ''));
    $password = (string)($login['password'] ?? '');
    $role     = strtolower(trim((string)($login['role'] ?? '')));
    $posPin   = trim((string)($login['pos_pin'] ?? ''));
    if ($username === '') {
        throw new InvalidArgumentException('create_login.username is required.');
    }
    if (strlen($password) < 6) {
        throw new InvalidArgumentException('create_login.password must be at least 6 characters.');
    }
    if ($role === '' || !in_array($role, hrAccountRoles(), true)) {
        throw new InvalidArgumentException('Invalid create_login.role.');
    }
    if ($posPin === '' || !preg_match('/^\d{4}$/', $posPin)) {
        throw new InvalidArgumentException('create_login.pos_pin must be exactly 4 digits (required for POS PIN login).');
    }

    $fn = trim((string)($nameSource['first_name'] ?? ''));
    $ln = trim((string)($nameSource['last_name'] ?? ''));
    $display = trim((string)($nameSource['display_name'] ?? ''));
    if ($display === '' && ($fn !== '' || $ln !== '')) {
        $display = trim($fn . ' ' . $ln);
    }
    if ($display === '') {
        $display = $username;
    }
    if ($fn === '') {
        $fn = $display;
    }
    if ($ln === '') {
        $ln = '-';
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    try {
        $ins = $pdo->prepare('
            INSERT INTO sh_users
                (tenant_id, username, password_hash, pin_code, name, first_name, last_name, role, status, is_active, is_deleted)
            VALUES
                (:tid, :user, :ph, :pinc, :name, :fn, :ln, :role, \'active\', 1, 0)
        ');
        $ins->execute([
            ':tid'  => $tenantId,
            ':user' => $username,
            ':ph'   => $hash,
            ':pinc' => $posPin,
            ':name' => $display,
            ':fn'   => $fn,
            ':ln'   => $ln,
            ':role' => $role,
        ]);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            throw new RuntimeException('Username already taken (global unique).');
        }
        throw $e;
    }

    return (int)$pdo->lastInsertId();
}

/**
 * @param array<string, mixed> $p
 * @return array{employee: array<string, mixed>, created?: bool}
 */
function hrUpsertEmployee(PDO $pdo, int $tenantId, int $actorUserId, array $p): array
{
    $id = isset($p['id']) ? (int)$p['id'] : 0;

    $primaryRole = strtolower(trim((string)($p['primary_role'] ?? '')));
    if ($primaryRole === '' || !in_array($primaryRole, hrPrimaryRoles(), true)) {
        throw new InvalidArgumentException('primary_role is invalid or missing.');
    }

    if (!empty($p['create_login']) && is_array($p['create_login'])) {
        $p['create_login']['role'] = $primaryRole;
    }

    $status = strtolower(trim((string)($p['status'] ?? 'active')));
    $allowedStatus = ['active', 'suspended', 'on_leave', 'terminated'];
    if (!in_array($status, $allowedStatus, true)) {
        throw new InvalidArgumentException('status is invalid.');
    }

    $defaultCurrency = strtoupper(substr(trim((string)($p['default_currency'] ?? 'PLN')), 0, 3));
    if (strlen($defaultCurrency) !== 3) {
        throw new InvalidArgumentException('default_currency must be 3 letters (ISO 4217).');
    }

    $firstName = trim((string)($p['first_name'] ?? ''));
    $lastName  = trim((string)($p['last_name'] ?? ''));
    $display   = trim((string)($p['display_name'] ?? ''));
    $hireRaw   = trim((string)($p['hire_date'] ?? ''));
    $email     = isset($p['email']) ? trim((string)$p['email']) : null;
    $phone     = isset($p['phone']) ? trim((string)$p['phone']) : null;
    $notes     = isset($p['notes']) ? (string)$p['notes'] : null;
    if ($notes === '') {
        $notes = null;
    }
    $birthRaw = trim((string)($p['birth_date'] ?? ''));
    $birthDate = $birthRaw !== '' ? $birthRaw : null;
    $termRaw = trim((string)($p['termination_date'] ?? ''));
    $termDate = $termRaw !== '' ? $termRaw : null;

    if ($id > 0) {
        $existing = hrFetchEmployeeRow($pdo, $tenantId, $id);
        if ($existing === null) {
            throw new RuntimeException('Employee not found.');
        }

        if ($display === '') {
            $display = (string)($existing['display_name'] ?? '');
        }
        if ($firstName === '') {
            $firstName = (string)($existing['first_name'] ?? '');
        }
        if ($lastName === '') {
            $lastName = (string)($existing['last_name'] ?? '');
        }
        if ($hireRaw === '') {
            $hireRaw = (string)($existing['hire_date'] ?? '');
        }

        $newUserId = array_key_exists('user_id', $p)
            ? ($p['user_id'] === null || $p['user_id'] === '' ? null : (int)$p['user_id'])
            : $existing['user_id'];

        if (!empty($p['create_login']) && is_array($p['create_login'])) {
            if ($newUserId !== null) {
                throw new InvalidArgumentException('Remove user_id or omit create_login — cannot create account when user_id is set.');
            }
            $newUserId = hrCreateUserForEmployee($pdo, $tenantId, $p['create_login'], $p);
        }

        if ($newUserId !== null) {
            hrAssertUserLinkable($pdo, $tenantId, $newUserId, $id);
        }

        $isDeleted = array_key_exists('is_deleted', $p) ? ((int)$p['is_deleted'] ? 1 : 0) : (int)($existing['is_deleted'] ?? 0);

        $pdo->prepare('
            UPDATE sh_employees SET
                user_id = :uid,
                display_name = :dn,
                first_name = :fn,
                last_name = :ln,
                email = :em,
                phone = :ph,
                birth_date = :bd,
                hire_date = :hd,
                termination_date = :td,
                primary_role = :pr,
                status = :st,
                default_currency = :dc,
                notes = :no,
                is_deleted = :del,
                updated_at = NOW()
            WHERE id = :eid AND tenant_id = :tid
        ')->execute([
            ':uid' => $newUserId,
            ':dn'  => $display,
            ':fn'  => $firstName,
            ':ln'  => $lastName,
            ':em'  => $email !== '' ? $email : null,
            ':ph'  => $phone !== '' ? $phone : null,
            ':bd'  => $birthDate,
            ':hd'  => $hireRaw,
            ':td'  => $termDate,
            ':pr'  => $primaryRole,
            ':st'  => $status,
            ':dc'  => $defaultCurrency,
            ':no'  => $notes,
            ':del' => $isDeleted,
            ':eid' => $id,
            ':tid' => $tenantId,
        ]);

        $row = hrFetchEmployeeRow($pdo, $tenantId, $id);

        return ['employee' => $row ?? [], 'created' => false];
    }

    // --- create ---
    if ($firstName === '' || $lastName === '') {
        throw new InvalidArgumentException('first_name and last_name are required for new employee.');
    }
    if ($display === '') {
        $display = trim($firstName . ' ' . $lastName);
    }
    if ($hireRaw === '') {
        throw new InvalidArgumentException('hire_date is required (YYYY-MM-DD).');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hireRaw)) {
        throw new InvalidArgumentException('hire_date must be YYYY-MM-DD.');
    }

    $userId = null;
    if (!empty($p['create_login']) && is_array($p['create_login'])) {
        $userId = hrCreateUserForEmployee($pdo, $tenantId, $p['create_login'], $p);
    } elseif (!empty($p['user_id'])) {
        $userId = (int)$p['user_id'];
        hrAssertUserLinkable($pdo, $tenantId, $userId, null);
    }

    $employeeCodeIn = trim((string)($p['employee_code'] ?? ''));
    $tmpCode = $employeeCodeIn !== '' ? $employeeCodeIn : ('EMP-NEW-' . bin2hex(random_bytes(4)));

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('
            INSERT INTO sh_employees (
                tenant_id, user_id, employee_code,
                display_name, first_name, last_name,
                email, phone, birth_date, hire_date, termination_date,
                primary_role, status, default_currency,
                notes, created_at, updated_at, is_deleted
            ) VALUES (
                :tid, :uid, :ecode,
                :dn, :fn, :ln,
                :em, :ph, :bd, :hd, :td,
                :pr, :st, :dc,
                :no, NOW(), NOW(), 0
            )
        ');
        $ins->execute([
            ':tid'   => $tenantId,
            ':uid'   => $userId,
            ':ecode' => $tmpCode,
            ':dn'    => $display,
            ':fn'    => $firstName,
            ':ln'    => $lastName,
            ':em'    => $email !== '' ? $email : null,
            ':ph'    => $phone !== '' ? $phone : null,
            ':bd'    => $birthDate,
            ':hd'    => $hireRaw,
            ':td'    => $termDate,
            ':pr'    => $primaryRole,
            ':st'    => $status,
            ':dc'    => $defaultCurrency,
            ':no'    => $notes,
        ]);
        $newId = (int)$pdo->lastInsertId();

        if ($employeeCodeIn === '') {
            $finalCode = 'EMP-' . str_pad((string)$newId, 6, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE sh_employees SET employee_code = :c WHERE id = :id AND tenant_id = :tid')
                ->execute([':c' => $finalCode, ':id' => $newId, ':tid' => $tenantId]);
        }

        $initialRate = null;
        try {
            $initialRate = hrResolveAmountMinor($p);
        } catch (\InvalidArgumentException $ignore) {
            $initialRate = null;
        }
        if ($initialRate !== null && $initialRate > 0) {
            $pdo->prepare('
                INSERT INTO sh_employee_rates
                    (tenant_id, employee_id, rate_type, amount_minor, currency,
                     effective_from, effective_to, reason, note, created_by_user_id, created_at)
                VALUES
                    (:tid, :eid, \'hourly\', :amt, :cur,
                     TIMESTAMP(:hd), NULL, \'hiring\', \'Initial rate from employee_upsert\', :uid, NOW())
            ')->execute([
                ':tid' => $tenantId,
                ':eid' => $newId,
                ':amt' => $initialRate,
                ':cur' => $defaultCurrency,
                ':hd'  => $hireRaw,
                ':uid' => $actorUserId,
            ]);
        }

        $posPinCreate = '';
        if (!empty($p['create_login']) && is_array($p['create_login'])) {
            $posPinCreate = trim((string)($p['create_login']['pos_pin'] ?? ''));
        }
        if ($posPinCreate !== '') {
            hrApplyKioskPin($pdo, $tenantId, $newId, $posPinCreate);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $row = hrFetchEmployeeRow($pdo, $tenantId, $newId);

    return ['employee' => $row ?? [], 'created' => true];
}
