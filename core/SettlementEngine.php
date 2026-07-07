<?php

declare(strict_types=1);

/**
 * SettlementEngine — canonical payment settlement for SliceHub (Phase 1).
 *
 * Single tender per call (one row in sh_order_payments). Split-tender UI is Phase 2.
 * Callers MUST wrap in an open PDO transaction.
 *
 * @see _docs/sessions/2026-07-07_settlement_engine_phase1.md
 */
final class SettlementEngine
{
    private const VALID_METHODS = ['cash', 'card', 'online'];

    private const PAY_STATUS_MAP = [
        'cash'   => 'cash',
        'card'   => 'card',
        'online' => 'online_paid',
    ];

    /**
     * POS path: settle single tender and complete the order (ready → completed).
     *
     * @param array $payments Exactly one element: method, amount (PLN, optional), tendered?, transaction_id?
     */
    public static function settleAndClose(
        \PDO $pdo,
        string $orderId,
        int $tenantId,
        int $userId,
        array $payments,
        array $options = [],
        array $flags = []
    ): array {
        $options['complete_order'] = true;

        return self::settle($pdo, $orderId, $tenantId, $userId, $payments, $options, $flags);
    }

    /**
     * Core settlement — Phase 1 enforces exactly one payment line.
     *
     * Options:
     *   complete_order  bool   default false — when true, calls OrderStateMachine::fastComplete
     *   print_receipt   bool   default false — fiscal receipt flag (card forces true)
     *   tip_grosze      int    default 0
     *   settled_via     string audit tag for events (pos | payments_settle)
     */
    public static function settle(
        \PDO $pdo,
        string $orderId,
        int $tenantId,
        int $userId,
        array $payments,
        array $options = [],
        array $flags = []
    ): array {
        if (count($payments) !== 1) {
            return self::fail('Phase 1 requires exactly one payment entry.');
        }

        $payment = $payments[0];
        $method  = (string)($payment['method'] ?? '');
        if (!in_array($method, self::VALID_METHODS, true)) {
            return self::fail("Invalid payment method: '{$method}'.");
        }

        if ($method === 'online' && empty($payment['transaction_id'])) {
            return self::fail('Online payment requires transaction_id.');
        }

        $completeOrder = !empty($options['complete_order']);
        $printReceipt  = !empty($options['print_receipt']);
        $tipGrosze     = (int)($options['tip_grosze'] ?? 0);

        if ($method === 'card') {
            $printReceipt = true;
        }

        $stmt = $pdo->prepare(
            "SELECT id, grand_total, payment_status, payment_method, status, receipt_printed,
                    order_type, channel, source
             FROM sh_orders
             WHERE id = :oid AND tenant_id = :tid
             FOR UPDATE"
        );
        $stmt->execute([':oid' => $orderId, ':tid' => $tenantId]);
        $order = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$order) {
            return self::fail('Order not found.');
        }

        $grandTotalGrosze = (int)$order['grand_total'];
        $oldStatus        = (string)$order['status'];
        $payStatus        = (string)$order['payment_status'];

        $paidGrosze = self::sumPaymentRows($pdo, $orderId, $tenantId);

        // Idempotent close: already completed and fully covered.
        if ($oldStatus === 'completed') {
            $fullyPaid = OrderStateMachine::isFullyPaid($pdo, $orderId, $tenantId);
            if ($fullyPaid['fully_paid']) {
                return [
                    'success'          => true,
                    'idempotent'       => true,
                    'old_status'       => $oldStatus,
                    'new_status'       => 'completed',
                    'payment_status'   => $payStatus,
                    'payment_method'   => (string)($order['payment_method'] ?? $method),
                    'receipt_printed'  => ((int)($order['receipt_printed'] ?? 0)) === 1,
                    'change_due_grosze'=> 0,
                    'payment_inserted' => false,
                    'message'          => null,
                ];
            }
        }

        $amountGrosze   = self::toGrosze($payment['amount'] ?? null, $grandTotalGrosze);
        $tenderedGrosze = self::toGrosze($payment['tendered'] ?? $payment['amount'] ?? null, $amountGrosze);

        if ($amountGrosze <= 0) {
            return self::fail('Payment amount must be positive.');
        }

        if (abs($amountGrosze - $grandTotalGrosze) > 1) {
            $fmtExpected = number_format($grandTotalGrosze / 100, 2, '.', '');
            $fmtApplied  = number_format($amountGrosze / 100, 2, '.', '');
            return self::fail("Payment total ({$fmtApplied}) does not match order total ({$fmtExpected}).");
        }

        $paymentInserted = false;

        // Backfill: header already paid (e.g. online_paid at checkout) but no ledger row.
        if ($paidGrosze < $grandTotalGrosze && OrderStateMachine::isPaid($payStatus)) {
            $backfillMethod = self::methodFromPaymentStatus($payStatus) ?? $method;
            self::insertPaymentRow(
                $pdo,
                $orderId,
                $tenantId,
                $userId,
                $backfillMethod,
                $grandTotalGrosze,
                $grandTotalGrosze,
                $payment['transaction_id'] ?? null
            );
            $paidGrosze      = $grandTotalGrosze;
            $paymentInserted = true;
        }

        // Insert cashier payment row when not yet covered by ledger.
        if ($paidGrosze < $grandTotalGrosze) {
            self::insertPaymentRow(
                $pdo,
                $orderId,
                $tenantId,
                $userId,
                $method,
                $amountGrosze,
                $tenderedGrosze,
                $payment['transaction_id'] ?? null
            );
            $paidGrosze      = $grandTotalGrosze;
            $paymentInserted = true;
        }

        $changeDueGrosze = max(0, $tenderedGrosze - $amountGrosze - $tipGrosze);

        if ($completeOrder) {
            if (OrderStateMachine::isPaid($payStatus)) {
                $extraCols = [];
                if ((string)($order['order_type'] ?? '') === 'delivery') {
                    $extraCols['delivery_status'] = 'delivered';
                }
                if ($printReceipt) {
                    $extraCols['receipt_printed'] = 1;
                }

                $tr = OrderStateMachine::transitionOrder(
                    $pdo,
                    $orderId,
                    $tenantId,
                    $userId,
                    'completed',
                    $flags,
                    $extraCols
                );

                if (!$tr['success']) {
                    return [
                        'success'    => false,
                        'message'    => $tr['message'],
                        'old_status' => $tr['old_status'] ?? $oldStatus,
                    ];
                }

                if ($tipGrosze > 0) {
                    $pdo->prepare(
                        "UPDATE sh_orders SET tip_amount = :tip WHERE id = :oid AND tenant_id = :tid"
                    )->execute([':tip' => $tipGrosze, ':oid' => $orderId, ':tid' => $tenantId]);
                }

                return [
                    'success'           => true,
                    'idempotent'        => false,
                    'old_status'        => $tr['old_status'],
                    'new_status'        => 'completed',
                    'payment_status'    => $payStatus,
                    'payment_method'    => (string)($order['payment_method'] ?? $method),
                    'receipt_printed'   => $printReceipt,
                    'change_due_grosze' => $changeDueGrosze,
                    'payment_inserted'  => $paymentInserted,
                    'message'           => null,
                ];
            }

            $fc = OrderStateMachine::fastComplete(
                $pdo,
                $orderId,
                $tenantId,
                $userId,
                $method,
                $flags,
                ['print_receipt' => $printReceipt]
            );

            if (!$fc['success']) {
                return [
                    'success'    => false,
                    'message'    => $fc['message'],
                    'old_status' => $fc['old_status'] ?? $oldStatus,
                ];
            }

            if ($tipGrosze > 0) {
                $pdo->prepare(
                    "UPDATE sh_orders SET tip_amount = :tip WHERE id = :oid AND tenant_id = :tid"
                )->execute([':tip' => $tipGrosze, ':oid' => $orderId, ':tid' => $tenantId]);
            }

            return [
                'success'           => true,
                'idempotent'        => false,
                'old_status'        => $fc['old_status'],
                'new_status'        => 'completed',
                'payment_status'    => self::PAY_STATUS_MAP[$method] ?? $method,
                'payment_method'    => $method,
                'receipt_printed'   => $printReceipt,
                'change_due_grosze' => $changeDueGrosze,
                'payment_inserted'  => $paymentInserted,
                'message'           => null,
            ];
        }

        // Payment-only path (e.g. delivery settle without auto-complete).
        $newPayStatus = self::PAY_STATUS_MAP[$method] ?? $method;
        $now          = date('Y-m-d H:i:s');
        $sets         = [
            'payment_status = :ps',
            'payment_method = :pm',
            'tip_amount = :tip',
            'updated_at = :now',
        ];
        $params = [
            ':ps'  => $newPayStatus,
            ':pm'  => $method,
            ':tip' => $tipGrosze,
            ':now' => $now,
            ':oid' => $orderId,
            ':tid' => $tenantId,
        ];

        if ($printReceipt) {
            $sets[] = 'receipt_printed = 1';
        }

        $pdo->prepare(
            'UPDATE sh_orders SET ' . implode(', ', $sets) . ' WHERE id = :oid AND tenant_id = :tid'
        )->execute($params);

        return [
            'success'           => true,
            'idempotent'        => false,
            'old_status'        => $oldStatus,
            'new_status'        => $oldStatus,
            'payment_status'    => $newPayStatus,
            'payment_method'    => $method,
            'receipt_printed'   => $printReceipt,
            'change_due_grosze' => $changeDueGrosze,
            'payment_inserted'  => $paymentInserted,
            'message'           => null,
        ];
    }

    /**
     * Whether settle.php should auto-complete (legacy orphan rules).
     */
    public static function shouldAutoComplete(array $order): bool
    {
        $status = (string)($order['status'] ?? '');

        return $status === 'ready'
            && !in_array((string)($order['source'] ?? ''), ['AGGREGATOR'], true)
            && !in_array((string)($order['channel'] ?? ''), ['Delivery'], true);
    }

    private static function sumPaymentRows(\PDO $pdo, string $orderId, int $tenantId): int
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount_grosze), 0)
             FROM sh_order_payments
             WHERE order_id = :oid AND tenant_id = :tid"
        );
        $stmt->execute([':oid' => $orderId, ':tid' => $tenantId]);
        $sum = (int)$stmt->fetchColumn();
        $stmt->closeCursor();

        return $sum;
    }

    private static function insertPaymentRow(
        \PDO $pdo,
        string $orderId,
        int $tenantId,
        int $userId,
        string $method,
        int $amountGrosze,
        int $tenderedGrosze,
        ?string $transactionId
    ): void {
        $cols = 'id, order_id, tenant_id, user_id, method, amount_grosze, tendered_grosze';
        $vals = ':id, :oid, :tid, :uid, :method, :amount, :tendered';

        $params = [
            ':id'       => self::uuidV4(),
            ':oid'      => $orderId,
            ':tid'      => $tenantId,
            ':uid'      => $userId,
            ':method'   => $method,
            ':amount'   => $amountGrosze,
            ':tendered' => $tenderedGrosze,
        ];

        if ($transactionId !== null && $transactionId !== '') {
            $cols .= ', transaction_id';
            $vals .= ', :txn';
            $params[':txn'] = $transactionId;
        }

        // created_at exists on upgraded schemas; omit on bare 001_init.
        try {
            $pdo->query('SELECT created_at FROM sh_order_payments LIMIT 0');
            $cols .= ', created_at';
            $vals .= ', :now';
            $params[':now'] = date('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            // graceful — column added by enterprise setup migration
        }

        $pdo->prepare(
            "INSERT INTO sh_order_payments ({$cols}) VALUES ({$vals})"
        )->execute($params);
    }

    private static function toGrosze(mixed $value, int $fallbackGrosze): int
    {
        if ($value === null || $value === '') {
            return $fallbackGrosze;
        }

        return (int)round((float)$value * 100);
    }

    private static function methodFromPaymentStatus(string $paymentStatus): ?string
    {
        return match ($paymentStatus) {
            'cash'        => 'cash',
            'card'        => 'card',
            'online_paid' => 'online',
            default       => null,
        };
    }

    private static function uuidV4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** @return array{success: false, message: string} */
    private static function fail(string $message): array
    {
        return ['success' => false, 'message' => $message, 'old_status' => ''];
    }
}
