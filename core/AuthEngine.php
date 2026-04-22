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
     * PIN login scoped to tenant (5 min token). Owner role is not allowed.
     *
     * @return array{token: string, user: array<string, string>, target_module: string, expires_at: string}
     */
    public static function loginKiosk(PDO $pdo, int $tenantId, string $pinCode, string $jwtSecret): array
    {
        $pinCode = trim($pinCode);
        if ($pinCode === '') {
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
            LIMIT 1
        ');
        $stmt->execute([
            ':pin' => $pinCode,
            ':tid' => $tenantId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new AuthFailureException('Invalid credentials');
        }

        $role = strtolower(trim((string)($row['role'] ?? '')));
        if ($role === 'owner') {
            throw new AuthForbiddenException('Forbidden');
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
}
