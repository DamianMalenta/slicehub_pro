<?php
declare(strict_types=1);

/**
 * SliceHub Enterprise — Panic Mode Engine
 *
 * Shifts promised_time forward on ALL active orders for the tenant.
 * Includes a 2-minute debounce guard to prevent panic stacking from
 * double-clicks or network lag.
 *
 * @planned (Prawo VIII) Outbox publish per-order zamowienia nie dodany celowo:
 *   Panic to bulk admin override (rzadko, 1x/kryzys), nie individual lifecycle.
 *   Publish per-order przy 50+ zamowieniach wydluzyby transakcje znaczaco.
 *   Alternatywa na przyszlosc: nowy event_type 'tenant.panic_triggered' (1 event,
 *   nie per-order) z payload {affected_count, delay_minutes}. Do decyzji wlasciciela.
 *
 * @see api/pos/engine.php#panic_mode (sole consumer)
 */
final class PanicEngine
{
    private const DEBOUNCE_MINUTES = 2;
    private const MIN_DELAY        = 5;
    private const MAX_DELAY        = 60;
    private const DEFAULT_DELAY    = 20;
    private const ACTIVE_STATUSES  = ['new', 'accepted', 'pending', 'preparing', 'ready'];

    /**
     * Execute panic mode: shift promised_time on all active orders.
     *
     * @return array{affected_orders: int, delay_applied_minutes: int, triggered_at: string}
     * @throws RuntimeException If debounce guard triggers or delay is out of range.
     */
    public static function execute(
        \PDO $pdo,
        int $tenantId,
        ?string $userId,
        int $delayMinutes = self::DEFAULT_DELAY
    ): array {
        if ($delayMinutes < self::MIN_DELAY || $delayMinutes > self::MAX_DELAY) {
            throw new \RuntimeException(
                'delay_minutes must be between ' . self::MIN_DELAY . ' and ' . self::MAX_DELAY . '.'
            );
        }

        // — Debounce guard (2-minute cooldown) —
        $stmtDebounce = $pdo->prepare(
            "SELECT COUNT(*) FROM sh_panic_log
             WHERE tenant_id = :tid AND created_at > DATE_SUB(NOW(), INTERVAL " . self::DEBOUNCE_MINUTES . " MINUTE)"
        );
        $stmtDebounce->execute([':tid' => $tenantId]);

        if ((int)$stmtDebounce->fetchColumn() > 0) {
            throw new \RuntimeException(
                'Panic mode was triggered less than ' . self::DEBOUNCE_MINUTES . ' minutes ago. Please wait before retrying.'
            );
        }

        $now = date('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            // Bulk-shift promised_time on all active orders
            $namedPlaceholders = implode(',', array_map(fn($i) => ":s{$i}", array_keys(self::ACTIVE_STATUSES)));
            $stmtShift = $pdo->prepare(
                "UPDATE sh_orders
                 SET promised_time = DATE_ADD(
                         COALESCE(promised_time, created_at),
                         INTERVAL :delay MINUTE
                     ),
                     updated_at = :now
                 WHERE tenant_id = :tid
                   AND status IN ({$namedPlaceholders})"
            );
            $stmtShift->bindValue(':delay', $delayMinutes, \PDO::PARAM_INT);
            $stmtShift->bindValue(':now', $now);
            $stmtShift->bindValue(':tid', $tenantId, \PDO::PARAM_INT);
            foreach (self::ACTIVE_STATUSES as $i => $status) {
                $stmtShift->bindValue(":s{$i}", $status);
            }
            $stmtShift->execute();
            $affectedCount = $stmtShift->rowCount();

            // Audit log
            require_once __DIR__ . '/Uuid.php';
            $stmtLog = $pdo->prepare(
                "INSERT INTO sh_panic_log (id, tenant_id, triggered_by, delay_minutes, affected_count, created_at)
                 VALUES (:id, :tid, :uid, :delay, :affected, :now)"
            );
            $stmtLog->execute([
                ':id'       => Uuid::v4(),
                ':tid'      => $tenantId,
                ':uid'      => $userId,
                ':delay'    => $delayMinutes,
                ':affected' => $affectedCount,
                ':now'      => $now,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $triggeredAt = (new \DateTimeImmutable($now, new \DateTimeZone('Europe/Warsaw')))
            ->format(\DateTimeInterface::ATOM);

        return [
            'affected_orders'       => $affectedCount,
            'delay_applied_minutes' => $delayMinutes,
            'triggered_at'          => $triggeredAt,
        ];
    }
}
