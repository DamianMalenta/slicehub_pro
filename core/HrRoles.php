<?php

declare(strict_types=1);

/**
 * HrRoles — SSOT ról administracyjnych silosu HR / Payroll.
 *
 * Powód istnienia: `auth_guard.php` robi WYŁĄCZNIE uwierzytelnienie (kto),
 * nie autoryzację (co wolno). Każdy endpoint zwracający dane płacowe musi
 * dołożyć własny gate — inaczej kelner z tokenem kiosku czyta wypłaty całego
 * zespołu.
 *
 * Lista ról jest tu JEDNA, żeby `api/backoffice/hr/engine.php` i cienkie
 * wrappery GET (`api/staff/payroll.php`, `api/dashboard/team_payroll.php`)
 * nie rozjechały się w czasie.
 */
final class HrRoles
{
    /** @var list<string> Role uprawnione do zarządzania HR i czytania cudzych wypłat. */
    public const MANAGER_ROLES = ['owner', 'manager', 'admin'];

    public static function actorRole(PDO $pdo, int $tenantId, int $userId): string
    {
        if ($tenantId <= 0 || $userId <= 0) {
            return '';
        }
        $stmt = $pdo->prepare('
            SELECT role FROM sh_users
            WHERE id = :uid AND tenant_id = :tid
            LIMIT 1
        ');
        $stmt->execute([':uid' => $userId, ':tid' => $tenantId]);

        return (string)$stmt->fetchColumn();
    }

    public static function isManager(PDO $pdo, int $tenantId, int $userId): bool
    {
        return in_array(self::actorRole($pdo, $tenantId, $userId), self::MANAGER_ROLES, true);
    }
}
