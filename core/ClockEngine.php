<?php

declare(strict_types=1);

/**
 * Clock Engine — Work Sessions & Driver Presence
 *
 * Atomic clock-in / clock-out with duplicate-session prevention,
 * second-precision hour storage, and driver delivery safeguards.
 */
class ClockEngine
{
    public const ERR_ALREADY_CLOCKED_IN = 'ALREADY_CLOCKED_IN';
    public const ERR_NO_OPEN_SESSION    = 'NO_OPEN_SESSION';
    public const ERR_ACTIVE_DELIVERIES  = 'ACTIVE_DELIVERIES';

    /**
     * @throws \RuntimeException business-rule violations (see ERR_* constants)
     * @throws \Throwable        on DB errors (transaction rolled back)
     */
    public static function clockIn(PDO $pdo, int $tenantId, string $userId): array
    {
        $uid = self::parseUserId($userId);

        $pdo->beginTransaction();

        try {
            $stmtUser = $pdo->prepare("
                SELECT id, role
                FROM sh_users
                WHERE id = :id AND tenant_id = :tid AND is_deleted = 0
                LIMIT 1
            ");
            $stmtUser->execute([':id' => $uid, ':tid' => $tenantId]);
            $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);

            if (!$userRow) {
                throw new \RuntimeException('USER_NOT_FOUND_OR_TENANT_MISMATCH');
            }

            $role = (string)$userRow['role'];

            $stmtOpen = $pdo->prepare("
                SELECT id FROM sh_work_sessions
                WHERE tenant_id = :tid AND user_id = :uid AND end_time IS NULL
                LIMIT 1
            ");
            $stmtOpen->execute([':tid' => $tenantId, ':uid' => $uid]);
            if ($stmtOpen->fetch()) {
                throw new \RuntimeException(self::ERR_ALREADY_CLOCKED_IN);
            }

            $sessionUuid = self::uuidV4();

            $stmtIns = $pdo->prepare("
                INSERT INTO sh_work_sessions
                    (tenant_id, user_id, start_time, session_uuid)
                VALUES
                    (:tid, :uid, NOW(), :sid)
            ");
            $stmtIns->execute([
                ':tid' => $tenantId,
                ':uid' => $uid,
                ':sid' => $sessionUuid,
            ]);

            $pdo->prepare('UPDATE sh_users SET last_seen = NOW() WHERE id = :id AND tenant_id = :tid')
                ->execute([':id' => $uid, ':tid' => $tenantId]);

            $driverStatusSet = null;
            if ($role === 'driver') {
                $stmtDriver = $pdo->prepare("
                    INSERT INTO sh_drivers (tenant_id, user_id, status)
                    VALUES (:tid, :uid, 'available')
                    ON DUPLICATE KEY UPDATE status = 'available'
                ");
                $stmtDriver->execute([':tid' => $tenantId, ':uid' => $uid]);
                $driverStatusSet = 'available';
            }

            $pdo->commit();

            $stmtStart = $pdo->prepare("
                SELECT start_time FROM sh_work_sessions WHERE session_uuid = :sid LIMIT 1
            ");
            $stmtStart->execute([':sid' => $sessionUuid]);
            $startRow = $stmtStart->fetch(PDO::FETCH_ASSOC);
            $startIso  = self::formatUtcIso((string)$startRow['start_time']);

            return [
                'action'  => 'clock_in',
                'user_id' => (string)$uid,
                'result'  => array_filter([
                    'session_id'         => $sessionUuid,
                    'start_time'         => $startIso,
                    'driver_status_set' => $driverStatusSet,
                ]),
            ];

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @throws \RuntimeException business-rule violations
     * @throws \Throwable        on DB errors (transaction rolled back)
     */
    public static function clockOut(PDO $pdo, int $tenantId, string $userId): array
    {
        $uid = self::parseUserId($userId);

        $pdo->beginTransaction();

        try {
            $stmtUser = $pdo->prepare("
                SELECT id, role FROM sh_users
                WHERE id = :id AND tenant_id = :tid AND is_deleted = 0
                LIMIT 1
            ");
            $stmtUser->execute([':id' => $uid, ':tid' => $tenantId]);
            $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);

            if (!$userRow) {
                throw new \RuntimeException('USER_NOT_FOUND_OR_TENANT_MISMATCH');
            }

            $role = (string)$userRow['role'];

            $stmtSess = $pdo->prepare("
                SELECT id, session_uuid, start_time
                FROM sh_work_sessions
                WHERE tenant_id = :tid AND user_id = :uid AND end_time IS NULL
                ORDER BY start_time DESC
                LIMIT 1
            ");
            $stmtSess->execute([':tid' => $tenantId, ':uid' => $uid]);
            $session = $stmtSess->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                throw new \RuntimeException(self::ERR_NO_OPEN_SESSION);
            }

            if ($role === 'driver') {
                $stmtDrv = $pdo->prepare("
                    SELECT status FROM sh_drivers
                    WHERE tenant_id = :tid AND user_id = :uid
                    LIMIT 1
                ");
                $stmtDrv->execute([':tid' => $tenantId, ':uid' => $uid]);
                $drv = $stmtDrv->fetch(PDO::FETCH_ASSOC);
                if ($drv && ($drv['status'] ?? '') === 'busy') {
                    throw new \RuntimeException(self::ERR_ACTIVE_DELIVERIES);
                }
            }

            $sessionUuid = (string)$session['session_uuid'];

            $stmtUpd = $pdo->prepare("
                UPDATE sh_work_sessions
                SET end_time = NOW(),
                    total_hours = ROUND(TIMESTAMPDIFF(SECOND, start_time, NOW()) / 3600.0, 4)
                WHERE id = :id AND tenant_id = :tid
            ");
            $stmtUpd->execute([
                ':id'  => (int)$session['id'],
                ':tid' => $tenantId,
            ]);

            $driverStatusSet = null;
            if ($role === 'driver') {
                $pdo->prepare("
                    UPDATE sh_drivers SET status = 'offline'
                    WHERE tenant_id = :tid AND user_id = :uid
                ")->execute([':tid' => $tenantId, ':uid' => $uid]);
                $driverStatusSet = 'offline';
            }

            $pdo->commit();

            $stmtFinal = $pdo->prepare("
                SELECT total_hours, end_time FROM sh_work_sessions WHERE id = :id LIMIT 1
            ");
            $stmtFinal->execute([':id' => (int)$session['id']]);
            $fin = $stmtFinal->fetch(PDO::FETCH_ASSOC);

            return array_filter([
                'action'              => 'clock_out',
                'user_id'             => (string)$uid,
                'session_id'          => $sessionUuid,
                'total_hours'         => round((float)($fin['total_hours'] ?? 0), 4),
                'end_time'            => self::formatUtcIso((string)$fin['end_time']),
                'driver_status_set'   => $driverStatusSet,
            ], static fn ($v) => $v !== null);

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function parseUserId(string $userId): int
    {
        $uid = (int)trim($userId);
        if ($uid <= 0) {
            throw new \InvalidArgumentException('Invalid user_id.');
        }
        return $uid;
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    private static function formatUtcIso(string $mysqlDatetime): string
    {
        $clean = preg_replace('/\.\d+$/', '', trim($mysqlDatetime));
        try {
            $dt = new \DateTimeImmutable($clean);
        } catch (\Exception $e) {
            return $mysqlDatetime;
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
