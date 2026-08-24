<?php

declare(strict_types=1);

/**
 * Flota POS / lista kelnerów — pokazuj użytkowników z aktywną sesją w aplikacji mobilnej
 * (heartbeat przez last_seen). Wyjątek: kierowca w trasie (sh_drivers.status = busy) zawsze widoczny.
 */
function slicehubFleetPresenceTtlSeconds(): int
{
    return 120;
}

/** Wywołuj przy pollach mobilnych (kelner, kierowca) i udanym loginie hasłem. */
function slicehubTouchStaffPresence(PDO $pdo, int $tenantId, int $userId): void
{
    if ($tenantId <= 0 || $userId <= 0) {
        return;
    }
    $pdo->prepare(
        'UPDATE sh_users SET last_seen = NOW() WHERE id = :id AND tenant_id = :tid AND is_active = 1 AND is_deleted = 0'
    )->execute([':id' => $userId, ':tid' => $tenantId]);
}

/** Po wylogowaniu z aplikacji kierowcy — natychmiast znika z floty (bez czekania na TTL). */
function slicehubClearStaffPresence(PDO $pdo, int $tenantId, int $userId): void
{
    if ($tenantId <= 0 || $userId <= 0) {
        return;
    }
    $pdo->prepare(
        'UPDATE sh_users SET last_seen = NULL WHERE id = :id AND tenant_id = :tid AND is_deleted = 0'
    )->execute([':id' => $userId, ':tid' => $tenantId]);
}
