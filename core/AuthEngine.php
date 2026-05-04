<?php

declare(strict_types=1);

require_once __DIR__ . '/JwtProvider.php';

class AuthFailureException extends RuntimeException
{
}

class AuthForbiddenException extends RuntimeException
{
}

/**
 * Login flows (system + kiosk) and post-login routing hints.
 *
 * Model jak w typowych POS (Toast / Square / lokalne kasy): **jeden PIN na kasę** = rekord
 * `sh_users.pin_code` w tenancie; działa dla każdej roli, która ma ustawiony PIN — także owner,
 * jeśli nadamy PIN (backoffice nadal może logować się hasłem przez `loginSystem`).
 *
 * **Zmiana / HR:** odbicie zmiany używa `sh_employees.auth_pin_hash` (API Kadry); przy powiązaniu
 * z kontem `hrApplyKioskPin` ustawia też `sh_users.pin_code`, żeby **ten sam PIN** = kasa + zmiana.
 */
class AuthEngine
{
    public static function getTargetModule(string $role): string
    {
        $r = strtolower(trim($role));
        if ($r === 'owner' || $r === 'admin') {
            return 'dashboard';
        }
        if ($r === 'manager') {
            return 'pos';
        }
        if ($r === 'cook' || $r === 'kitchen') {
            return 'kds';
        }
        if ($r === 'waiter') {
            return 'floor';
        }
        if ($r === 'driver') {
            return 'driver_app';
        }

        return 'team_app';
    }

    /**
     * Password login (24h token).
     *
     * @return array{token: string, user: array<string, string>, target_module: string, expires_at: string}
     */
    public static function loginSystem(PDO $pdo, string $username, string $password, string $jwtSecret): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            throw new AuthFailureException('Invalid credentials');
        }

        $stmt = $pdo->prepare('
            SELECT id, tenant_id, username, password_hash, role,
                   COALESCE(NULLIF(TRIM(name), \'\'), username) AS display_name
            FROM sh_users
            WHERE username = :username
              AND status = \'active\'
            LIMIT 1
        ');
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new AuthFailureException('Invalid credentials');
        }

        $hash = (string)($row['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            throw new AuthFailureException('Invalid credentials');
        }

        $id   = (int)$row['id'];
        $tid  = $row['tenant_id'];
        if ($tid === null || $tid === '' || (int)$tid <= 0) {
            throw new AuthFailureException('Invalid credentials');
        }
        $tid = (int)$tid;

        $role = (string)($row['role'] ?? '');
        $exp  = time() + 86400;

        $payload = [
            'user_id'   => $id,
            'tenant_id' => $tid,
            'role'      => $role,
            'exp'       => $exp,
        ];

        $token = JwtProvider::encode($payload, $jwtSecret);

        return [
            'token'          => $token,
            'user'           => [
                'id'        => (string)$id,
                'name'      => (string)($row['display_name'] ?? $row['username'] ?? ''),
                'role'      => $role,
                'tenant_id' => (string)$tid,
            ],
            'target_module'  => self::getTargetModule($role),
            'expires_at'     => gmdate('Y-m-d\TH:i:s\Z', $exp),
        ];
    }

    /**
     * PIN na kasę / kiosk — dopasowanie `pin_code` w tenancie (sesja ~8h), jak w standardowym POS.
     *
     * @return array{token: string, user: array<string, string>, target_module: string, expires_at: string}
     */
    public static function loginKiosk(PDO $pdo, int $tenantId, string $pinCode, string $jwtSecret): array
    {
        $pinCode = trim($pinCode);
        if ($pinCode === '' || !preg_match('/^\d{4}$/', $pinCode)) {
            throw new AuthFailureException('Invalid credentials');
        }

        if ($tenantId <= 0) {
            throw new AuthFailureException('Invalid credentials');
        }

        $stmt = $pdo->prepare('
            SELECT id, tenant_id, username, pin_code, role,
                   COALESCE(NULLIF(TRIM(name), \'\'), username) AS display_name
            FROM sh_users
            WHERE pin_code = :pin
              AND tenant_id = :tid
              AND status = \'active\'
              AND is_deleted = 0
            LIMIT 1
        ');
        $stmt->execute([
            ':pin' => $pinCode,
            ':tid' => $tenantId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $row = self::loginKioskResolveByEmployeePin($pdo, $tenantId, $pinCode);
        }
        if (!$row) {
            throw new AuthFailureException('Invalid credentials');
        }

        $id  = (int)$row['id'];
        $tid = (int)$row['tenant_id'];
        if ($tid !== $tenantId) {
            throw new AuthFailureException('Invalid credentials');
        }

        $roleRaw = (string)($row['role'] ?? '');
        $exp     = time() + 28800;

        $payload = [
            'user_id'   => $id,
            'tenant_id' => $tid,
            'role'      => $roleRaw,
            'exp'       => $exp,
        ];

        $token = JwtProvider::encode($payload, $jwtSecret);

        return [
            'token'          => $token,
            'user'           => [
                'id'        => (string)$id,
                'name'      => (string)($row['display_name'] ?? $row['username'] ?? ''),
                'role'      => $roleRaw,
                'tenant_id' => (string)$tid,
            ],
            'target_module'  => self::getTargetModule($roleRaw),
            'expires_at'     => gmdate('Y-m-d\TH:i:s\Z', $exp),
        ];
    }

    /**
     * Gdy sh_users.pin_code jest puste, ale Kadry zapisaly bcrypt w sh_employees.auth_pin_hash
     * (np. PIN ustawiony przed synchronizacją albo błąd zapisu) — zweryfikuj PIN i zwróć wiersz użytkownika.
     */
    private static function loginKioskResolveByEmployeePin(PDO $pdo, int $tenantId, string $plainPin): ?array
    {
        $st = $pdo->prepare('
            SELECT e.auth_pin_hash, e.user_id,
                   u.id, u.tenant_id, u.username, u.role,
                   COALESCE(NULLIF(TRIM(u.name), \'\'), u.username) AS display_name
            FROM sh_employees e
            INNER JOIN sh_users u ON u.id = e.user_id AND u.tenant_id = e.tenant_id
            WHERE e.tenant_id = :tid
              AND e.is_deleted = 0
              AND e.status = \'active\'
              AND e.user_id IS NOT NULL
              AND e.auth_pin_hash IS NOT NULL
              AND u.status = \'active\'
              AND u.is_deleted = 0
        ');
        $st->execute([':tid' => $tenantId]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $hash = (string)($r['auth_pin_hash'] ?? '');
            if ($hash !== '' && password_verify($plainPin, $hash)) {
                $uid = (int)$r['id'];
                $pdo->prepare('UPDATE sh_users SET pin_code = :p WHERE id = :id AND tenant_id = :tid')
                    ->execute([':p' => $plainPin, ':id' => $uid, ':tid' => $tenantId]);

                return [
                    'id'            => $uid,
                    'tenant_id'     => (int)$r['tenant_id'],
                    'username'      => (string)$r['username'],
                    'role'          => (string)$r['role'],
                    'display_name'  => (string)$r['display_name'],
                ];
            }
        }

        return null;
    }
}
