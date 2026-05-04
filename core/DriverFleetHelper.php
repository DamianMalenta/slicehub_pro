<?php

declare(strict_types=1);

/**
 * Flota POS / logistics: wiersz w sh_drivers jest wymagany, żeby kierowca
 * pojawił się na liście w module POS. Konta sh_users (rola driver) z Kadry
 * często go nie mają — dopinamy INSERT idempotentny.
 */
function slicehubEnsureDriverFleetRow(PDO $pdo, int $tenantId, int $userId, string $initialStatus = 'offline'): void
{
    if ($tenantId <= 0 || $userId <= 0) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT LOWER(TRIM(role)) FROM sh_users WHERE id = :id AND tenant_id = :tid AND is_deleted = 0 LIMIT 1'
    );
    $st->execute([':id' => $userId, ':tid' => $tenantId]);
    $role = strtolower((string)$st->fetchColumn());
    if ($role !== 'driver') {
        return;
    }
    $chk = $pdo->prepare('SELECT 1 FROM sh_drivers WHERE user_id = :uid AND tenant_id = :tid LIMIT 1');
    $chk->execute([':uid' => $userId, ':tid' => $tenantId]);
    if ($chk->fetchColumn()) {
        return;
    }
    $st = in_array($initialStatus, ['offline', 'available', 'busy'], true) ? $initialStatus : 'offline';
    $pdo->prepare(
        'INSERT INTO sh_drivers (user_id, tenant_id, status) VALUES (:uid, :tid, :st)'
    )->execute([':uid' => $userId, ':tid' => $tenantId, ':st' => $st]);
}

/**
 * Jednorazowo dopina brakujące wiersze floty dla wszystkich kont driver w tenancie (np. Kadry).
 */
function slicehubSyncMissingDriverFleetRows(PDO $pdo, int $tenantId): void
{
    if ($tenantId <= 0) {
        return;
    }
    $pdo->prepare(
        "INSERT INTO sh_drivers (user_id, tenant_id, status)
         SELECT u.id, u.tenant_id, 'offline'
         FROM sh_users u
         WHERE u.tenant_id = :tid AND u.is_deleted = 0 AND LOWER(TRIM(u.role)) = 'driver'
           AND NOT EXISTS (
               SELECT 1 FROM sh_drivers d WHERE d.user_id = u.id AND d.tenant_id = u.tenant_id
           )"
    )->execute([':tid' => $tenantId]);
}
