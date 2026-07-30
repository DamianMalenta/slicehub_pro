<?php
declare(strict_types=1);

/**
 * SliceHub Enterprise — KDS Ticket State Machine
 *
 * Advances a single KDS ticket through: pending → preparing → done.
 * On the LAST ticket reaching 'done', auto-transitions the parent order
 * to 'ready' (the universal readiness rule).
 *
 * @see api/kds/engine.php#bump_ticket (sole consumer)
 */
final class KdsTicketEngine
{
    private const VALID_TRANSITIONS = [
        'pending'   => ['preparing', 'done'],
        'preparing' => ['done'],
        'done'      => [],
    ];

    /**
     * Advance a single KDS ticket to a new status.
     *
     * @return array{ticket_id: string, station_id: string, ticket_status: string, order_id: string, order_ready?: bool}
     * @throws RuntimeException If ticket not found, transition invalid, or concurrent issue.
     */
    public static function bump(
        \PDO $pdo,
        int $tenantId,
        ?string $userId,
        string $ticketId,
        string $newStatus
    ): array {
        $allowedStatuses = ['preparing', 'done'];
        if (!in_array($newStatus, $allowedStatuses, true)) {
            throw new \InvalidArgumentException(
                'Invalid new_status. Allowed values: ' . implode(', ', $allowedStatuses) . '.'
            );
        }

        // Fetch ticket (multi-tenant isolation)
        $stmtTicket = $pdo->prepare(
            "SELECT id, order_id, station_id, status
             FROM sh_kds_tickets
             WHERE id = :ticket_id AND tenant_id = :tid
             LIMIT 1"
        );
        $stmtTicket->execute([':ticket_id' => $ticketId, ':tid' => $tenantId]);
        $ticket = $stmtTicket->fetch(\PDO::FETCH_ASSOC);

        if (!$ticket) {
            throw new \RuntimeException('Ticket not found.');
        }

        // Validate state transition
        $currentStatus = $ticket['status'];
        $allowed = self::VALID_TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            $msg = "Cannot transition ticket from '{$currentStatus}' to '{$newStatus}'.";
            if (empty($allowed)) {
                $msg .= " Status '{$currentStatus}' is terminal.";
            } else {
                $msg .= " Allowed: ['" . implode("', '", $allowed) . "'].";
            }
            throw new \RuntimeException($msg);
        }

        $orderId = $ticket['order_id'];
        $now = date('Y-m-d H:i:s');
        $orderBecameReady = false;

        $pdo->beginTransaction();
        try {
            // Advance ticket status
            $pdo->prepare(
                "UPDATE sh_kds_tickets SET status = :new_status WHERE id = :ticket_id AND tenant_id = :tid"
            )->execute([
                ':new_status' => $newStatus,
                ':ticket_id'  => $ticketId,
                ':tid'        => $tenantId,
            ]);

            // Auto-ready check (only when marking 'done')
            if ($newStatus === 'done') {
                $stmtPending = $pdo->prepare(
                    "SELECT COUNT(*) AS remaining
                     FROM sh_kds_tickets
                     WHERE order_id = :oid AND tenant_id = :tid AND status != 'done'
                     FOR UPDATE"
                );
                $stmtPending->execute([':oid' => $orderId, ':tid' => $tenantId]);
                $remaining = (int)$stmtPending->fetchColumn();

                if ($remaining === 0) {
                    // Fetch actual old status before overwriting
                    $stmtOldStatus = $pdo->prepare(
                        "SELECT status FROM sh_orders WHERE id = :oid AND tenant_id = :tid LIMIT 1"
                    );
                    $stmtOldStatus->execute([':oid' => $orderId, ':tid' => $tenantId]);
                    $oldOrderStatus = $stmtOldStatus->fetchColumn() ?: 'accepted';

                    // All stations done → order is ready.
                    // R2 (2026-07-30): reset flagi edycji i kitchen_delta — kuchnia
                    // potwierdziła zakończeniem, banner edycji nie ma już sensu.
                    $stmtOrderStatus = $pdo->prepare(
                        "UPDATE sh_orders
                         SET status = 'ready', updated_at = :now,
                             edited_since_print = 0, kitchen_delta = NULL
                         WHERE id = :oid AND tenant_id = :tid AND status IN ('accepted', 'preparing')"
                    );
                    $stmtOrderStatus->execute([':oid' => $orderId, ':tid' => $tenantId, ':now' => $now]);

                    if ($stmtOrderStatus->rowCount() > 0) {
                        $orderBecameReady = true;

                        $pdo->prepare(
                            "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
                             VALUES (:oid, :uid, :old_status, 'ready', :now)"
                        )->execute([
                            ':oid'        => $orderId,
                            ':uid'        => $userId,
                            ':old_status' => $oldOrderStatus,
                            ':now'        => $now,
                        ]);
                    }
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $responseData = [
            'ticket_id'     => $ticketId,
            'station_id'    => $ticket['station_id'],
            'ticket_status' => $newStatus,
            'order_id'      => $orderId,
        ];

        if ($orderBecameReady) {
            $responseData['order_status'] = 'ready';
            $responseData['order_ready']  = true;
        }

        return $responseData;
    }
}
