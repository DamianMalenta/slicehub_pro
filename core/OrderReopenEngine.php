<?php

declare(strict_types=1);

/**
 * SliceHub — Order Reopen Engine (RFC-001 Faza 3)
 *
 * Bezpieczna procedura ponownego otwarcia zamówienia:
 *   completed → pending (tylko owner/admin).
 *
 * Gwarancje:
 *   1. Walidacja fiskalna — jeśli fiscal_receipt_number jest ustawiony,
 *      loguj ostrzeżenie, ale NIE blokuj reopen (fiskalizacja pozostaje widoczna).
 *   2. Magazyn — jeśli zamówienie było skonsumowane (WZ istnieje), wywołaj
 *      WarehouseReverseHook::onOrderCancelled pełny KOR (analogia do anulowania).
 *   3. Audit — writeLog('reopen', {reason}) + OrderStateMachine::reopenOrder.
 *   4. Outbox — publikuje order.reopened z nowym snapshotem w tej samej transakcji.
 *   5. RBAC — wymaga owner/admin.
 */
class OrderReopenEngine
{
    /**
     * Wykonaj reopen completed → pending.
     *
     * @param PDO    $pdo
     * @param int    $tenantId
     * @param string $orderId   UUID zamówienia
     * @param int    $userId
     * @param string $reason    Powód reopen (min. 10 znaków)
     *
     * @return array{
     *   success: bool, message?: string, old_status?: string,
     *   new_status?: string, wh_kor_document?: string|null, audit_logged?: bool
     * }
     */
    public static function reopen(
        PDO $pdo,
        int $tenantId,
        string $orderId,
        int $userId,
        string $reason
    ): array {
        $reason = trim($reason);
        if (strlen($reason) < 10) {
            return [
                'success' => false,
                'message' => 'Powód jest wymagany (min. 10 znaków).',
            ];
        }

        $stmtOrder = $pdo->prepare(
            "SELECT id, status, fiscal_receipt_number, receipt_printed
               FROM sh_orders
              WHERE id = :oid AND tenant_id = :tid
              LIMIT 1"
        );
        $stmtOrder->execute([':oid' => $orderId, ':tid' => $tenantId]);
        $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return [
                'success' => false,
                'message' => 'Order not found.',
            ];
        }

        if ($order['status'] !== 'completed') {
            return [
                'success' => false,
                'message' => "Reopen dozwolony tylko z 'completed'. Aktualny status: '{$order['status']}'.",
            ];
        }

        // Ostrzeżenie fiskalne — nie blokujemy, ale logujemy do error_log.
        if (!empty($order['fiscal_receipt_number'])) {
            error_log(sprintf(
                '[OrderReopenEngine] reopening fiskalized order %s tenant %d by user %d (fiscal_receipt_number=%s)',
                $orderId, $tenantId, $userId, $order['fiscal_receipt_number']
            ));
        }

        $pdo->beginTransaction();
        try {
            // 1. Wykonaj reopen w OSM (w tym audit trail + sh_order_logs).
            $result = OrderStateMachine::reopenOrder(
                $pdo,
                $orderId,
                $tenantId,
                $userId,
                ['reopen_reason' => $reason]
            );

            if (!$result['success']) {
                $pdo->rollBack();
                return $result;
            }

            // 2. Opcjonalny KOR magazynowy — jeśli zamówienie było skonsumowane.
            $korDoc = null;
            $korResult = WarehouseReverseHook::onOrderCancelled($pdo, $tenantId, $orderId, $userId);
            if ($korResult['success'] && !empty($korResult['doc_number'])) {
                $korDoc = $korResult['doc_number'];
            } elseif (!($korResult['skipped'] ?? false)) {
                // KOR zakończony błędem, ale nie blokuj reopen (wahadło biznesowe).
                error_log(sprintf(
                    '[OrderReopenEngine] WarehouseReverseHook failed: %s',
                    $korResult['error'] ?? 'unknown'
                ));
            }

            // 3. Publikuj event do outboxu z nowym snapshotem.
            OrderEventPublisher::publishOrderLifecycle(
                $pdo,
                $tenantId,
                'order.reopened',
                $orderId,
                [
                    'source'    => 'order_reopen',
                    'user_id'   => $userId,
                    'reason'    => $reason,
                    'warehouse' => $korDoc,
                ],
                ['source' => 'system', 'actorType' => 'staff', 'actorId' => (string)$userId]
            );

            $pdo->commit();

            return [
                'success'       => true,
                'old_status'    => $result['old_status'],
                'new_status'    => $result['new_status'],
                'wh_kor_document' => $korDoc,
                'audit_logged'  => true,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[OrderReopenEngine] reopen failed: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Reopen failed: ' . $e->getMessage(),
            ];
        }
    }
}
