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
 *   - meal_record     — posiłek pracowniczy → sh_meals + meal_deduction w ledgerze (atomowo)
 *
 * Backoffice (owner/manager/admin — hrRequireManager):
 *   - employees_list       — lista profili HR (+ opcjonalnie join do konta)
 *   - employee_get         — jeden rekord + aktualna stawka godzinowa
 *   - employee_upsert      — create/update profilu; opcjonalnie create_login (nowe konto sh_users)
 *   - employee_pin_set     — ustawia bcrypt PIN kioskowy (sh_employees.auth_pin_hash)
 *   - employee_rate_set    — nowa stawka godzinowa (zamyka poprzednią linię w sh_employee_rates)
 *   - hr_users_unlinked    — sh_users w tenancie bez aktywnego powiązania do sh_employees (wybór konta)
 *   - payroll_report       — raport wypłat całego zespołu za okres (per pracownik + sumy)
 *   - payroll_period_status — stan okresu (is_locked + liczba wpisów ledgera)
 *   - payroll_close_period  — JEDNOKIERUNKOWE zamknięcie miesiąca (PayrollLedger::lockPeriod)
 *   - advances_list        — zaliczki tenanta (opcjonalnie filtr employee_id / status)
 *   - advance_request      — nowy wniosek o zaliczkę (AdvanceEngine::request)
 *   - advance_approve      — requested → approved
 *   - advance_reject       — requested → rejected (wymagany reason)
 *   - advance_mark_paid    — approved → paid (+ ledger advance_payment + harmonogram rat)
 *   - advance_void         — paid → void (reverse w ledgerze; blokada przy spłaconych ratach)
 *   - bonus_add            — premia → ledger entry_type='bonus' (kwota > 0)
 *   - adjustment_add       — korekta → ledger entry_type='adjustment' (kwota SIGNED, wymagany opis)
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

require_once __DIR__ . '/../../../core/DomainError.php';

/**
 * Adapter domena → HTTP.
 *
 * Rozbicie `ERR_CODE (detal)` na `code` + `message` robi {@see DomainError}
 * (czysta, testowalna) — tutaj zostaje tylko wysłanie odpowiedzi.
 */
function hrFailFromDomain(int $httpCode, \Throwable $e): void
{
    [$code, $detail] = DomainError::split($e->getMessage());
    hrFail($httpCode, $code, $detail);
}

try {
    require_once __DIR__ . '/../../../core/db_config.php';
    require_once __DIR__ . '/../../../core/auth_guard.php';
    require_once __DIR__ . '/../../../core/HrClockEngine.php';
    require_once __DIR__ . '/../../../core/TeamPayrollEngine.php';
    require_once __DIR__ . '/../../../core/MealEngine.php';
    require_once __DIR__ . '/../../../core/HrRoles.php';
    require_once __DIR__ . '/../../../core/Money.php';
    require_once __DIR__ . '/../../../core/PayrollLedger.php';
    require_once __DIR__ . '/../../../core/AdvanceEngine.php';

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
            if (!in_array($actor, HrRoles::MANAGER_ROLES, true)) {
                hrFail(403, 'FORBIDDEN_OVERRIDE',
                    'Only owner/manager/admin can act for another employee.');
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
                hrFailFromDomain(400, $e);
            } catch (\RuntimeException $e) {
                hrFailFromDomain(409, $e);
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
                if (!in_array($actor, HrRoles::MANAGER_ROLES, true)) {
                    hrFail(403, 'FORBIDDEN_OVERRIDE',
                        'Only owner/manager/admin can force clock-out with manager_override.');
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
                hrFailFromDomain(400, $e);
            } catch (\RuntimeException $e) {
                hrFailFromDomain(409, $e);
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
        // Posiłek pracowniczy — Faza 4 (§6.1): zapis sh_meals + wpis
        // 'meal_deduction' w sh_payroll_ledger w JEDNEJ transakcji.
        // Identyfikacja jak przy clock_in: auth.self / auth.pin / auth.employee_id.
        case 'meal_record': {
            [$employeeId] = $resolveEmployeeId($input);

            $priceMinor = hrMoneyMinor($input, [
                'keys'       => ['price_minor', 'price_pln'],
                'allow_zero' => false,
                'error_code' => MealEngine::ERR_INVALID_PRICE,
            ]);

            $description = isset($input['description']) ? trim((string)$input['description']) : null;

            try {
                $result = MealEngine::record($pdo, $tenant_id, [
                    'employee_id'        => $employeeId,
                    'price_minor'        => $priceMinor,
                    'description'        => $description !== '' ? $description : null,
                    'created_by_user_id' => $user_id,
                    'idempotency_key'    => isset($input['idempotency_key'])
                        ? (string)$input['idempotency_key']
                        : null,
                ]);
            } catch (\InvalidArgumentException $e) {
                hrFailFromDomain(400, $e);
            } catch (\RuntimeException $e) {
                // m.in. PERIOD_LOCKED, EMPLOYEE_NOT_FOUND, EMPLOYEE_NO_ACCOUNT
                hrFailFromDomain(409, $e);
            }

            hrResponse(
                true,
                $result,
                !empty($result['duplicate'])
                    ? 'Meal already recorded (idempotent replay).'
                    : 'Meal recorded (ledger meal_deduction).'
            );
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
                    u.username AS account_username, u.role AS account_role, u.is_active AS account_is_active,
                    COALESCE((
                        SELECT SUM(pl.hours_qty)
                        FROM sh_payroll_ledger pl
                        WHERE pl.tenant_id = e.tenant_id AND pl.employee_id = e.id
                          AND pl.entry_type = 'work_earnings'
                          AND pl.period_year = YEAR(CURDATE())
                          AND pl.period_month = MONTH(CURDATE())
                    ), 0) AS current_month_hours,
                    COALESCE((
                        SELECT SUM(pl.amount_minor)
                        FROM sh_payroll_ledger pl
                        WHERE pl.tenant_id = e.tenant_id AND pl.employee_id = e.id
                          AND pl.entry_type = 'work_earnings'
                          AND pl.period_year = YEAR(CURDATE())
                          AND pl.period_month = MONTH(CURDATE())
                    ), 0) AS current_month_earnings,
                    (
                        SELECT er.amount_minor
                        FROM sh_employee_rates er
                        WHERE er.tenant_id = e.tenant_id AND er.employee_id = e.id
                          AND er.rate_type = 'hourly'
                          AND er.effective_from <= NOW()
                          AND (er.effective_to IS NULL OR er.effective_to > NOW())
                        ORDER BY er.effective_from DESC
                        LIMIT 1
                    ) AS current_rate_minor
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
                $r['current_month_hours'] = (float)($r['current_month_hours'] ?? 0);
                $r['current_month_earnings'] = (int)($r['current_month_earnings'] ?? 0);
                $r['current_rate_minor'] = isset($r['current_rate_minor']) ? (int)$r['current_rate_minor'] : null;
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
            $amountMinor = hrMoneyMinor($input, ['keys' => ['amount_minor', 'hourly_amount_pln']]);
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

        case 'employee_rate_history': {
            hrRequireManager($pdo, $tenant_id, $user_id);
            $eid = (int)($input['employee_id'] ?? 0);
            if ($eid <= 0) {
                hrFail(400, 'EMPLOYEE_ID_REQUIRED', 'employee_id is required.');
            }
            if (hrFetchEmployeeRow($pdo, $tenant_id, $eid) === null) {
                hrFail(404, 'NOT_FOUND', 'Employee not found.');
            }
            $st = $pdo->prepare('
                SELECT id, rate_type, amount_minor, currency,
                       effective_from, effective_to, reason, note,
                       created_by_user_id, created_at
                FROM sh_employee_rates
                WHERE tenant_id = :tid
                  AND employee_id = :eid
                  AND rate_type = \'hourly\'
                ORDER BY effective_from DESC
            ');
            $st->execute([':tid' => $tenant_id, ':eid' => $eid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['amount_minor'] = (int)$r['amount_minor'];
                $r['created_by_user_id'] = $r['created_by_user_id'] !== null ? (int)$r['created_by_user_id'] : null;
            }
            unset($r);
            hrResponse(true, ['rates' => $rows], 'OK');
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

        // -----------------------------------------------------------------
        // Raport wypłat zespołu. Po Fazie 4 wszystkie kwoty (w tym spłaty
        // zaliczek) pochodzą z sh_payroll_ledger przez TeamPayrollEngine —
        // router tylko przekazuje wynik, zero liczenia po stronie API/UI.
        case 'payroll_report': {
            hrRequireManager($pdo, $tenant_id, $user_id);

            $periodType = strtolower(trim((string)($input['period_type'] ?? 'month')));
            if (!in_array($periodType, ['week', 'month', 'year'], true)) {
                hrFail(400, 'INVALID_PERIOD', 'period_type must be week, month, or year.');
            }
            $rawOffset = $input['period_offset'] ?? 0;
            if (!is_numeric($rawOffset) || (int)$rawOffset < 0) {
                hrFail(400, 'INVALID_PERIOD', 'period_offset must be an integer >= 0.');
            }
            $periodOffset = (int)$rawOffset;

            try {
                $aggregate = TeamPayrollEngine::getAggregate($pdo, $tenant_id, $periodType, $periodOffset);
            } catch (\InvalidArgumentException $e) {
                hrFail(400, 'INVALID_PERIOD', $e->getMessage());
            }

            $result = $aggregate['team_payroll'];
            $result['period_type']   = $periodType;
            $result['period_offset'] = $periodOffset;

            hrResponse(true, ['payroll_report' => $result], 'OK');
            break;
        }

        // -----------------------------------------------------------------
        // Stan okresu rozliczeniowego — czy zamknięty + ile wpisów w ledgerze.
        case 'payroll_period_status': {
            hrRequireManager($pdo, $tenant_id, $user_id);
            [$pYear, $pMonth] = hrResolvePeriodYearMonth($input);

            $st = $pdo->prepare('
                SELECT COUNT(*) AS cnt, COALESCE(SUM(is_locked = 0), 0) AS open_cnt
                FROM sh_payroll_ledger
                WHERE tenant_id = :tid AND period_year = :py AND period_month = :pm
            ');
            $st->execute([':tid' => $tenant_id, ':py' => $pYear, ':pm' => $pMonth]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'open_cnt' => 0];

            hrResponse(true, [
                'period_year'    => $pYear,
                'period_month'   => $pMonth,
                'is_locked'      => PayrollLedger::isPeriodLocked($pdo, $tenant_id, $pYear, $pMonth),
                'entries_total'  => (int)$row['cnt'],
                'entries_open'   => (int)$row['open_cnt'],
            ], 'OK');
            break;
        }

        // -----------------------------------------------------------------
        // Zamknięcie miesiąca — JEDNOKIERUNKOWE (standard księgowy: brak unlock,
        // korekty idą jako 'adjustment' do następnego otwartego okresu).
        // Guard: nie można zamknąć bieżącego ani przyszłego miesiąca — accrual
        // (worker_payroll_accrual) i meal_record wciąż do niego piszą.
        case 'payroll_close_period': {
            hrRequireManager($pdo, $tenant_id, $user_id);
            [$pYear, $pMonth] = hrResolvePeriodYearMonth($input);

            $nowIndex    = (int)date('Y') * 12 + (int)date('n');
            $periodIndex = $pYear * 12 + $pMonth;
            if ($periodIndex >= $nowIndex) {
                hrFail(409, 'PERIOD_NOT_ENDED',
                    'Można zamknąć tylko zakończony miesiąc (nie bieżący/przyszły).');
            }

            if (PayrollLedger::isPeriodLocked($pdo, $tenant_id, $pYear, $pMonth)) {
                hrFail(409, 'PERIOD_ALREADY_LOCKED',
                    sprintf('Okres %04d-%02d jest już zamknięty.', $pYear, $pMonth));
            }

            // --- Auto-spłata rat zaliczek zaplanowanych na ten okres ---
            // §413 18_BACKOFFICE_HR_LOGIC.md: "W momencie zamknięcia okresu:
            //   każda rata applied → wpis ledger advance_repayment (ujemny)".
            // Musi być PRZED lockPeriod — PayrollLedger::record rzuca
            // ERR_PERIOD_LOCKED gdy okres jest już zamknięty.
            //
            // ATOMOWO: raty + lock w JEDNEJ transakcji. Lock jest nieodwracalny
            // (brak unlockPeriod), więc rata, która nie przeszła, zostaje na zawsze
            // 'pending' w zamkniętym okresie — PayrollLedger::record rzuci
            // ERR_PERIOD_LOCKED przy każdej późniejszej próbie. Dlatego JAKAKOLWIEK
            // awaria spinaczy = rollback całego zamknięcia.
            $repaidIds = [];
            $locked    = 0;
            $failCode  = null;
            $failMsg   = null;

            $pdo->beginTransaction();
            try {
                // FOR UPDATE — dwa równoległe zamknięcia tego samego okresu
                // nie mogą spłacić tej samej raty dwa razy.
                $installmentsStmt = $pdo->prepare("
                    SELECT i.id
                    FROM sh_advance_installments i
                    INNER JOIN sh_advances a ON a.id = i.advance_id AND a.tenant_id = i.tenant_id
                    WHERE i.tenant_id = :tid
                      AND i.scheduled_period_year = :py
                      AND i.scheduled_period_month = :pm
                      AND i.status = 'pending'
                      AND a.status = 'paid'
                    FOR UPDATE
                ");
                $installmentsStmt->execute([
                    ':tid' => $tenant_id,
                    ':py'  => $pYear,
                    ':pm'  => $pMonth,
                ]);

                foreach ($installmentsStmt->fetchAll(PDO::FETCH_COLUMN) as $instId) {
                    // recordRepayment dołącza do naszej transakcji (ownTx=false).
                    AdvanceEngine::recordRepayment($pdo, $tenant_id, (int)$instId, $user_id);
                    $repaidIds[] = (int)$instId;
                }

                $locked = PayrollLedger::lockPeriod($pdo, $tenant_id, $pYear, $pMonth);
                if ($locked === 0) {
                    $failCode = 'PERIOD_EMPTY';
                    $failMsg  = sprintf('Brak wpisów ledgera w %04d-%02d — nie ma czego zamykać.', $pYear, $pMonth);
                    throw new \RuntimeException($failCode);
                }

                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($failCode !== null) {
                    hrFail(409, $failCode, $failMsg);
                }
                error_log("[HR] close_period {$pYear}-{$pMonth} rolled back: " . $e->getMessage());
                hrFail(409, 'CLOSE_PERIOD_FAILED', $e->getMessage());
            }

            hrResponse(true, [
                'period_year'       => $pYear,
                'period_month'      => $pMonth,
                'locked_entries'    => $locked,
                'is_locked'         => true,
                'installments_repaid' => $repaidIds,
            ], sprintf(
                'Okres %04d-%02d zamknięty (%d wpisów, %d rat zaliczek spłaconych).',
                $pYear, $pMonth, $locked, count($repaidIds)
            ));
            break;
        }

        // -----------------------------------------------------------------
        // Zaliczki — lifecycle sh_advances (AdvanceEngine). Kwoty w ledgerze.
        case 'advances_list': {
            hrRequireManager($pdo, $tenant_id, $user_id);
            $where  = 'a.tenant_id = :tid';
            $params = [':tid' => $tenant_id];
            if (!empty($input['employee_id'])) {
                $where .= ' AND a.employee_id = :eid';
                $params[':eid'] = (int)$input['employee_id'];
            }
            if (!empty($input['status'])) {
                $where .= ' AND a.status = :st';
                $params[':st'] = (string)$input['status'];
            }
            $st = $pdo->prepare("
                SELECT a.id, a.employee_id, e.display_name AS employee_name,
                       a.amount_minor, a.currency, a.status,
                       a.repayment_plan, a.installments_count, a.reason,
                       a.requested_at, a.approved_at, a.paid_at, a.paid_method,
                       a.settled_at, a.rejected_at, a.rejection_reason,
                       (SELECT COUNT(*) FROM sh_advance_installments i
                         WHERE i.advance_id = a.id AND i.tenant_id = a.tenant_id AND i.status = 'paid') AS installments_paid
                FROM sh_advances a
                INNER JOIN sh_employees e ON e.id = a.employee_id AND e.tenant_id = a.tenant_id
                WHERE {$where}
                ORDER BY a.id DESC
                LIMIT 200
            ");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['employee_id'] = (int)$r['employee_id'];
                $r['amount_minor'] = (int)$r['amount_minor'];
                $r['installments_count'] = (int)$r['installments_count'];
                $r['installments_paid'] = (int)$r['installments_paid'];
            }
            unset($r);
            hrResponse(true, ['advances' => $rows], 'OK');
            break;
        }

        case 'advance_installments': {
            hrRequireManager($pdo, $tenant_id, $user_id);
            $advId = (int)($input['advance_id'] ?? 0);
            if ($advId <= 0) {
                hrFail(400, 'ADVANCE_ID_REQUIRED', 'advance_id is required.');
            }
            $check = $pdo->prepare('SELECT 1 FROM sh_advances WHERE id = :aid AND tenant_id = :tid');
            $check->execute([':aid' => $advId, ':tid' => $tenant_id]);
            if (!$check->fetchColumn()) {
                hrFail(404, 'NOT_FOUND', 'Advance not found.');
            }
            $st = $pdo->prepare('
                SELECT id, seq_no, amount_minor, currency,
                       scheduled_period_year, scheduled_period_month,
                       status, applied_at, created_at
                FROM sh_advance_installments
                WHERE tenant_id = :tid AND advance_id = :aid
                ORDER BY seq_no ASC
            ');
            $st->execute([':tid' => $tenant_id, ':aid' => $advId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['seq_no'] = (int)$r['seq_no'];
                $r['amount_minor'] = (int)$r['amount_minor'];
                $r['scheduled_period_year'] = (int)$r['scheduled_period_year'];
                $r['scheduled_period_month'] = (int)$r['scheduled_period_month'];
            }
            unset($r);
            hrResponse(true, ['advance_id' => $advId, 'installments' => $rows], 'OK');
            break;
        }

        case 'advance_installment_repay': {
            hrRequireManager($pdo, $tenant_id, $user_id);
            $instId = (int)($input['installment_id'] ?? 0);
            if ($instId <= 0) {
                hrFail(400, 'INSTALLMENT_ID_REQUIRED', 'installment_id is required.');
            }
            try {
                $ledgerId = AdvanceEngine::recordRepayment($pdo, $tenant_id, $instId, $user_id);
                hrResponse(true, ['installment_id' => $instId, 'ledger_entry_id' => $ledgerId], 'Installment repaid.');
            } catch (\RuntimeException $e) {
                hrFailFromDomain(409, $e);
            }
            break;
        }

        case 'advance_request': {
            hrRequireManager($pdo, $tenant_id, $user_id);
            $plan = trim((string)($input['repayment_plan'] ?? 'single'));
            try {
                $advanceId = AdvanceEngine::request($pdo, $tenant_id, [
                    'employee_id'          => (int)($input['employee_id'] ?? 0),
                    'amount_minor'         => hrMoneyMinor($input, ['allow_zero' => false]),
                    'repayment_plan'       => $plan,
                    'installments_count'   => (int)($input['installments_count'] ?? 0),
                    'reason'               => isset($input['reason']) ? (string)$input['reason'] : null,
                    'requested_by_user_id' => $user_id,
                ]);
            } catch (\InvalidArgumentException $e) {
                hrFailFromDomain(400, $e);
            } catch (\RuntimeException $e) {
                hrFailFromDomain(409, $e);
            }
            hrResponse(true, ['advance_id' => $advanceId], 'Advance requested.');
            break;
        }

        case 'advance_approve':
        case 'advance_reject':
        case 'advance_mark_paid':
        case 'advance_void': {
            hrRequireManager($pdo, $tenant_id, $user_id);
            $advanceId = (int)($input['advance_id'] ?? 0);
            if ($advanceId <= 0) {
                hrFail(400, 'ADVANCE_ID_REQUIRED', 'advance_id is required.');
            }
            try {
                switch ($action) {
                    case 'advance_approve':
                        AdvanceEngine::approve($pdo, $tenant_id, $advanceId, $user_id);
                        hrResponse(true, ['advance_id' => $advanceId], 'Advance approved.');
                        break;
                    case 'advance_reject':
                        AdvanceEngine::reject($pdo, $tenant_id, $advanceId, $user_id, (string)($input['reason'] ?? ''));
                        hrResponse(true, ['advance_id' => $advanceId], 'Advance rejected.');
                        break;
                    case 'advance_mark_paid':
                        $out = AdvanceEngine::markPaid($pdo, $tenant_id, $advanceId, $user_id, (string)($input['method'] ?? 'cash'));
                        hrResponse(true, ['advance_id' => $advanceId] + $out, 'Advance marked paid (ledger + installments).');
                        break;
                    case 'advance_void':
                        $reversalId = AdvanceEngine::voidAdvance($pdo, $tenant_id, $advanceId, $user_id, (string)($input['reason'] ?? ''));
                        hrResponse(true, ['advance_id' => $advanceId, 'reversal_entry_id' => $reversalId], 'Advance voided (reversal in ledger).');
                        break;
                }
            } catch (\InvalidArgumentException $e) {
                hrFailFromDomain(400, $e);
            } catch (\RuntimeException $e) {
                hrFailFromDomain(409, $e);
            }
            break;
        }

        // -----------------------------------------------------------------
        // Premia (bonus, > 0) i korekta (adjustment, SIGNED) — wpisy ledgera.
        case 'bonus_add':
        case 'adjustment_add': {
            hrRequireManager($pdo, $tenant_id, $user_id);
            $employeeId = (int)($input['employee_id'] ?? 0);
            if ($employeeId <= 0) {
                hrFail(400, 'EMPLOYEE_ID_REQUIRED', 'employee_id is required.');
            }
            if (hrFetchEmployeeRow($pdo, $tenant_id, $employeeId) === null) {
                hrFail(404, 'NOT_FOUND', 'Employee not found.');
            }

            $isBonus = $action === 'bonus_add';
            $amountMinor = hrMoneyMinor($input, [
                'allow_negative' => !$isBonus,
                'allow_zero'     => false,
            ]);

            $description = trim((string)($input['description'] ?? ''));
            if (!$isBonus && $description === '') {
                hrFail(400, 'DESCRIPTION_REQUIRED', 'Adjustment requires description (audit trail).');
            }

            // Okres księgowania: domyślnie bieżący miesiąc, opcjonalnie wskazany
            // wprost. Zamknięty okres odrzuci PayrollLedger::record (PERIOD_LOCKED)
            // — korekta idzie wtedy do następnego otwartego okresu.
            [$pYear, $pMonth] = hrResolvePeriodYearMonth($input, true);

            try {
                $ledgerId = PayrollLedger::record($pdo, $tenant_id, [
                    'employee_id'        => $employeeId,
                    'period_year'        => $pYear,
                    'period_month'       => $pMonth,
                    'entry_type'         => $isBonus ? PayrollLedger::TYPE_BONUS : PayrollLedger::TYPE_ADJUSTMENT,
                    'amount_minor'       => $amountMinor,
                    'currency'           => 'PLN',
                    'description'        => $description !== '' ? $description : null,
                    'created_by_user_id' => $user_id,
                ]);
            } catch (\InvalidArgumentException $e) {
                hrFailFromDomain(400, $e);
            } catch (\RuntimeException $e) {
                hrFailFromDomain(409, $e);
            }
            hrResponse(true, [
                'ledger_entry_id' => $ledgerId,
                'amount_minor'    => $amountMinor,
                'period_year'     => $pYear,
                'period_month'    => $pMonth,
            ], sprintf(
                '%s zaksięgowano w okresie %04d-%02d.',
                $isBonus ? 'Premię' : 'Korektę',
                $pYear,
                $pMonth
            ));
            break;
        }

        // -----------------------------------------------------------------
        // Historia okresów — przegląd wszystkich miesięcy z danymi płacowymi.
        // Zwraca listę okresów (year, month) z liczbą wpisów, sumą godzin,
        // sumą kosztów i statusem is_locked.
        // -----------------------------------------------------------------
        case 'periods_overview': {
            hrRequireManager($pdo, $tenant_id, $user_id);

            $st = $pdo->prepare("
                SELECT
                    period_year,
                    period_month,
                    COUNT(*)                          AS entries_total,
                    SUM(CASE WHEN is_locked = 0 THEN 1 ELSE 0 END) AS entries_open,
                    COALESCE(SUM(hours_qty), 0)       AS total_hours,
                    COALESCE(SUM(amount_minor), 0)    AS total_minor
                FROM sh_payroll_ledger
                WHERE tenant_id = :tid
                GROUP BY period_year, period_month
                ORDER BY period_year DESC, period_month DESC
            ");
            $st->execute([':tid' => $tenant_id]);
            $periods = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $periods[] = [
                    'period_year'   => (int)$row['period_year'],
                    'period_month'  => (int)$row['period_month'],
                    'period_label'  => sprintf('%04d-%02d', $row['period_year'], $row['period_month']),
                    'entries_total' => (int)$row['entries_total'],
                    'entries_open'  => (int)$row['entries_open'],
                    'is_locked'     => (int)$row['entries_open'] === 0,
                    'total_hours'   => (float)$row['total_hours'],
                    'total_pln'     => number_format((int)$row['total_minor'] / 100, 2, '.', ''),
                ];
            }
            hrResponse(true, ['periods' => $periods], 'OK');
            break;
        }

        // -----------------------------------------------------------------
        // Sesje pracownika — lista sesji pracy (sh_work_sessions) dla danego
        // pracownika, opcjonalnie filtrowana po okresie (year + month).
        // Zwraca: id, start_time, end_time, total_hours, source, adjusted info.
        // -----------------------------------------------------------------
        case 'employee_sessions': {
            hrRequireManager($pdo, $tenant_id, $user_id);
            $employeeId = (int)($input['employee_id'] ?? 0);
            if ($employeeId <= 0) {
                hrFail(400, 'EMPLOYEE_ID_REQUIRED', 'employee_id is required.');
            }
            if (hrFetchEmployeeRow($pdo, $tenant_id, $employeeId) === null) {
                hrFail(404, 'NOT_FOUND', 'Employee not found.');
            }

            $where = "ws.tenant_id = :tid AND ws.employee_id = :eid";
            $params = [':tid' => $tenant_id, ':eid' => $employeeId];

            $pYear = (int)($input['period_year'] ?? 0);
            $pMonth = (int)($input['period_month'] ?? 0);
            if ($pYear > 0 && $pMonth > 0) {
                $where .= " AND YEAR(ws.start_time) = :py AND MONTH(ws.start_time) = :pm";
                $params[':py'] = $pYear;
                $params[':pm'] = $pMonth;
            }

            $st = $pdo->prepare("
                SELECT
                    ws.id, ws.session_uuid, ws.start_time, ws.end_time,
                    ws.total_hours, ws.clock_in_source, ws.clock_out_source,
                    ws.adjusted_by_user_id, ws.adjustment_reason,
                    u.username AS adjusted_by_username
                FROM sh_work_sessions ws
                LEFT JOIN sh_users u ON u.id = ws.adjusted_by_user_id AND u.tenant_id = ws.tenant_id
                WHERE {$where}
                ORDER BY ws.start_time DESC
                LIMIT 500
            ");
            $st->execute($params);
            $sessions = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sessions[] = [
                    'id'             => (int)$row['id'],
                    'start_time'     => $row['start_time'],
                    'end_time'       => $row['end_time'],
                    'total_hours'    => $row['total_hours'] !== null ? (float)$row['total_hours'] : null,
                    'is_open'        => $row['end_time'] === null,
                    'clock_in_source'  => $row['clock_in_source'],
                    'clock_out_source' => $row['clock_out_source'],
                    'adjusted'       => $row['adjusted_by_user_id'] !== null,
                    'adjusted_by'    => $row['adjusted_by_username'],
                    'adjustment_reason' => $row['adjustment_reason'],
                ];
            }
            hrResponse(true, ['sessions' => $sessions], 'OK');
            break;
        }

        // -----------------------------------------------------------------
        // Wpisy ledgera pracownika — lista wpisów w sh_payroll_ledger
        // dla danego pracownika, opcjonalnie filtrowana po okresie.
        // -----------------------------------------------------------------
        case 'employee_ledger': {
            hrRequireManager($pdo, $tenant_id, $user_id);
            $employeeId = (int)($input['employee_id'] ?? 0);
            if ($employeeId <= 0) {
                hrFail(400, 'EMPLOYEE_ID_REQUIRED', 'employee_id is required.');
            }
            if (hrFetchEmployeeRow($pdo, $tenant_id, $employeeId) === null) {
                hrFail(404, 'NOT_FOUND', 'Employee not found.');
            }

            $where = "pl.tenant_id = :tid AND pl.employee_id = :eid";
            $params = [':tid' => $tenant_id, ':eid' => $employeeId];

            $pYear = (int)($input['period_year'] ?? 0);
            $pMonth = (int)($input['period_month'] ?? 0);
            if ($pYear > 0 && $pMonth > 0) {
                $where .= " AND pl.period_year = :py AND pl.period_month = :pm";
                $params[':py'] = $pYear;
                $params[':pm'] = $pMonth;
            }

            $st = $pdo->prepare("
                SELECT
                    pl.id, pl.entry_uuid, pl.period_year, pl.period_month,
                    pl.entry_type, pl.amount_minor, pl.currency,
                    pl.hours_qty, pl.rate_applied_minor,
                    pl.ref_work_session_id, pl.description,
                    pl.created_at, pl.is_locked, pl.locked_at
                FROM sh_payroll_ledger pl
                WHERE {$where}
                ORDER BY pl.period_year DESC, pl.period_month DESC, pl.created_at DESC
                LIMIT 500
            ");
            $st->execute($params);
            $entries = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $entries[] = [
                    'id'                 => (int)$row['id'],
                    'period'             => sprintf('%04d-%02d', $row['period_year'], $row['period_month']),
                    'entry_type'         => $row['entry_type'],
                    'amount_minor'       => (int)$row['amount_minor'],
                    'amount_pln'         => number_format((int)$row['amount_minor'] / 100, 2, '.', ''),
                    'hours'              => $row['hours_qty'] !== null ? (float)$row['hours_qty'] : null,
                    'rate_minor'         => $row['rate_applied_minor'] !== null ? (int)$row['rate_applied_minor'] : null,
                    'description'        => $row['description'],
                    'created_at'         => $row['created_at'],
                    'is_locked'          => (bool)$row['is_locked'],
                    'ref_work_session_id' => $row['ref_work_session_id'] !== null ? (int)$row['ref_work_session_id'] : null,
                ];
            }
            hrResponse(true, ['entries' => $entries], 'OK');
            break;
        }

        // -----------------------------------------------------------------
        // Edycja sesji pracy (manager override) — zmiana start_time / end_time.
        // Zapisuje adjusted_by_user_id + adjustment_reason (audit trail).
        // Blokuje edycję sesji w zamkniętym okresie (is_locked=1 w ledgerze).
        // -----------------------------------------------------------------
        case 'session_edit': {
            hrRequireManager($pdo, $tenant_id, $user_id);
            $sessionId = (int)($input['session_id'] ?? 0);
            if ($sessionId <= 0) {
                hrFail(400, 'SESSION_ID_REQUIRED', 'session_id is required.');
            }

            $st = $pdo->prepare("
                SELECT ws.*, pl.is_locked AS period_locked
                FROM sh_work_sessions ws
                LEFT JOIN sh_payroll_ledger pl
                    ON pl.ref_work_session_id = ws.id
                   AND pl.tenant_id = ws.tenant_id
                WHERE ws.id = :sid AND ws.tenant_id = :tid
                LIMIT 1
            ");
            $st->execute([':sid' => $sessionId, ':tid' => $tenant_id]);
            $session = $st->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                hrFail(404, 'NOT_FOUND', 'Work session not found.');
            }
            if ((bool)($session['period_locked'] ?? false)) {
                hrFail(409, 'PERIOD_LOCKED',
                    'Okres tej sesji jest zamknięty. Edycja niedostępna — korektę zaksięguj jako adjustment w otwartym okresie.');
            }

            $newStart = trim((string)($input['start_time'] ?? ''));
            $newEnd   = trim((string)($input['end_time'] ?? ''));
            $reason   = trim((string)($input['reason'] ?? ''));

            if ($newStart === '') {
                hrFail(400, 'START_TIME_REQUIRED', 'start_time is required.');
            }
            if ($reason === '') {
                hrFail(400, 'REASON_REQUIRED', 'Powód korekty jest wymagany (audit trail).');
            }

            // Validate datetime format
            $startTs = strtotime($newStart);
            if ($startTs === false) {
                hrFail(400, 'INVALID_DATETIME', 'Invalid start_time format.');
            }
            $newStartSql = date('Y-m-d H:i:s', $startTs);

            $newEndSql = null;
            $totalHours = null;
            if ($newEnd !== '') {
                $endTs = strtotime($newEnd);
                if ($endTs === false) {
                    hrFail(400, 'INVALID_DATETIME', 'Invalid end_time format.');
                }
                if ($endTs <= $startTs) {
                    hrFail(400, 'END_BEFORE_START', 'end_time must be after start_time.');
                }
                $newEndSql = date('Y-m-d H:i:s', $endTs);
                $totalHours = round(($endTs - $startTs) / 3600, 4);
            }

            $pdo->prepare("
                UPDATE sh_work_sessions
                SET start_time = :st,
                    end_time = :et,
                    total_hours = :th,
                    adjusted_by_user_id = :uid,
                    adjustment_reason = :reason,
                    clock_out_source = COALESCE(clock_out_source, 'manager_override')
                WHERE id = :sid AND tenant_id = :tid
            ")->execute([
                ':st'     => $newStartSql,
                ':et'     => $newEndSql,
                ':th'     => $totalHours,
                ':uid'    => $user_id,
                ':reason' => $reason,
                ':sid'    => $sessionId,
                ':tid'    => $tenant_id,
            ]);

            // Update the corresponding ledger entry if exists (work_earnings)
            if ($newEndSql !== null && $totalHours !== null) {
                $rateRow = hrFetchCurrentHourlyRate($pdo, $tenant_id, (int)$session['employee_id']);
                $rateMinor = $rateRow ? $rateRow['amount_minor'] : 0;
                if ($rateMinor > 0) {
                    $newAmount = (int)round($totalHours * $rateMinor);
                    $pdo->prepare("
                        UPDATE sh_payroll_ledger
                        SET amount_minor = :amt,
                            hours_qty = :hrs,
                            rate_applied_minor = :rate,
                            description = CONCAT('Edytowana sesja (manager override): ', :reason)
                        WHERE ref_work_session_id = :sid
                          AND tenant_id = :tid
                          AND entry_type = 'work_earnings'
                          AND is_locked = 0
                    ")->execute([
                        ':amt'   => $newAmount,
                        ':hrs'   => $totalHours,
                        ':rate'  => $rateMinor,
                        ':reason'=> $reason,
                        ':sid'   => $sessionId,
                        ':tid'   => $tenant_id,
                    ]);
                }
            }

            hrResponse(true, [
                'session_id'   => $sessionId,
                'start_time'   => $newStartSql,
                'end_time'     => $newEndSql,
                'total_hours'  => $totalHours,
            ], 'Sesja zaktualizowana (manager override).');
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
    return HrRoles::actorRole($pdo, $tenantId, $userId);
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
    if (!in_array($role, HrRoles::MANAGER_ROLES, true)) {
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

/**
 * Waliduje i zwraca [period_year, period_month] z inputu.
 *
 * @param bool $defaultToCurrent gdy true i brak obu pól → bieżący miesiąc
 *                               (używane przez bonus_add / adjustment_add).
 *
 * @return array{0:int,1:int}
 */
function hrResolvePeriodYearMonth(array $input, bool $defaultToCurrent = false): array
{
    $hasYear  = isset($input['period_year']);
    $hasMonth = isset($input['period_month']);

    if ($defaultToCurrent && !$hasYear && !$hasMonth) {
        return [(int)date('Y'), (int)date('n')];
    }

    $year  = (int)($input['period_year'] ?? 0);
    $month = (int)($input['period_month'] ?? 0);
    if ($year < 2000 || $year > 2099 || $month < 1 || $month > 12) {
        hrFail(400, 'INVALID_PERIOD', 'Provide period_year (2000–2099) and period_month (1–12).');
    }
    return [$year, $month];
}

/**
 * JEDYNY parser kwot w tym routerze (zastępuje dawne hrResolveMoneyMinor /
 * hrResolveAmountMinor / inline'y `price_pln`).
 *
 * Warstwa transportowa: konwersję robi {@see Money}, a błędy kończą request
 * przez `hrFail` — domena dostaje już tylko czysty `int` grosze.
 *
 * @param array{
 *   keys?: array{0:string,1:string},  // [klucz_minor, klucz_pln]; default amount_minor/amount_pln
 *   required?: bool,                  // default true; false → brak kluczy zwraca null
 *   allow_negative?: bool,            // default false
 *   allow_zero?: bool,                // default true
 *   error_code?: string               // kod błędu walidacji; default INVALID_AMOUNT
 * } $opts
 */
function hrMoneyMinor(array $input, array $opts = []): ?int
{
    [$minorKey, $plnKey] = $opts['keys'] ?? ['amount_minor', 'amount_pln'];
    $required      = $opts['required']       ?? true;
    $allowNegative = $opts['allow_negative'] ?? false;
    $allowZero     = $opts['allow_zero']     ?? true;
    $errCode       = $opts['error_code']     ?? 'INVALID_AMOUNT';

    $minor = null;
    if (isset($input[$minorKey])) {
        if (!is_int($input[$minorKey])) {
            hrFail(400, $errCode, $minorKey . ' must be a strict int (grosze).');
        }
        $minor = (int)$input[$minorKey];
    } elseif (isset($input[$plnKey])) {
        try {
            $minor = Money::fromPln($input[$plnKey]);
        } catch (\InvalidArgumentException $e) {
            hrFail(400, $errCode, $plnKey . ': ' . $e->getMessage());
        }
    }

    if ($minor === null) {
        if (!$required) {
            return null;
        }
        hrFail(400, 'AMOUNT_REQUIRED', sprintf('Provide %s (int grosze) or %s.', $minorKey, $plnKey));
    }
    if (!$allowNegative && $minor < 0) {
        hrFail(400, $errCode, 'Amount must be >= 0.');
    }
    if (!$allowZero && $minor === 0) {
        hrFail(400, $errCode, 'Amount must not be 0.');
    }
    if (!Money::isWithinCap($minor)) {
        hrFail(400, $errCode, 'Amount exceeds cap of ' . Money::MAX_MINOR . ' minor units.');
    }

    return $minor;
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

    // Stawka startowa — walidacja PRZED transakcją: hrMoneyMinor kończy request
    // przez hrFail, więc nie może wypaść w środku otwartego BEGIN.
    $initialRate = hrMoneyMinor($p, [
        'keys'     => ['amount_minor', 'hourly_amount_pln'],
        'required' => false,
    ]);

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
