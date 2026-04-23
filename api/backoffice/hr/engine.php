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
 * AUTH (wymagane dla każdej akcji):
 *   Endpoint jest za auth_guard.php → ma $tenant_id i $user_id z sesji/JWT.
 *   POS/Kiosk są autoryzowane jako managera/terminal; PIN pracownika to
 *   DRUGI factor w obrębie zaufanej sesji terminala.
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
                hrFail(400, $e->getMessage(), 'PIN must be 4-6 digits.');
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
            $result = HrClockEngine::status($pdo, $tenant_id, $filterEmployeeId);
            hrResponse(true, $result, 'Status OK.');
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
