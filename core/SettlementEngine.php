<?php

declare(strict_types=1);

/**
 * SettlementEngine — canonical payment settlement for SliceHub.
 *
 * Phase 1: single tender per close.
 * Phase 2: split-tender (multiple payments summing to order total).
 * Partial payments: applyPartialPayments() for dine-in tables (F3).
 *
 * Callers MUST wrap in an open PDO transaction.
 *
 * @see _docs/sessions/2026-07-07_settlement_engine_phase1.md
 * @see _docs/sessions/2026-07-07_settlement_engine_phase2.md
 */
final class SettlementEngine
{
    private const VALID_METHODS = ['cash', 'card', 'online'];

    private const VALID_METHODS_DINEIN = ['cash', 'card', 'online', 'voucher'];

    private const PAY_STATUS_MAP = [
        'cash'    => 'cash',
        'card'    => 'card',
        'online'  => 'online_paid',
        'voucher' => 'cash',
    ];

    /**
     * POS path: settle and complete the order (ready → completed).
     *
     * @param array $payments One or more: method, amount (PLN), tendered?, transaction_id?
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
     * Insert partial payment lines without completing the order (dine-in tables).
     *
     * @param array $payments Each: method|payment_method, amount, tendered?, transaction_id?
     */
    public static function applyPartialPayments(
        \PDO $pdo,
        string $orderId,
        int $tenantId,
        int $userId,
        array $payments,
        array $options = []
    ): array {
        if (count($payments) === 0) {
            return self::fail('payments array is required.');
        }

        $stmt = $pdo->prepare(
            "SELECT id, grand_total, payment_status, payment_method, status, order_type
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

        if (in_array((string)$order['status'], ['completed', 'cancelled'], true)) {
            return self::fail('Order is already ' . $order['status'] . '.');
        }

        $grandTotalGrosze = (int)$order['grand_total'];
        $paidGrosze       = self::sumPaymentRows($pdo, $orderId, $tenantId);
        $normalized       = self::normalizePayments($payments, self::VALID_METHODS_DINEIN);
        if ($normalized === null) {
            return self::fail('Invalid payment entry in payments array.');
        }

        $batchGrosze = 0;
        foreach ($normalized as $line) {
            if ($line['amount_grosze'] <= 0) {
                return self::fail('Each payment amount must be > 0.');
            }
            $batchGrosze += $line['amount_grosze'];
        }

        if ($paidGrosze + $batchGrosze > $grandTotalGrosze) {
            $over = $paidGrosze + $batchGrosze - $grandTotalGrosze;
            return self::fail(
                'Overpayment blocked: cumulative payments exceed the order total by '
                . number_format($over / 100, 2) . ' PLN.'
            );
        }

        $inserted = [];
        foreach ($normalized as $line) {
            self::insertPaymentRow(
                $pdo,
                $orderId,
                $tenantId,
                $userId,
                $line['method'],
                $line['amount_grosze'],
                $line['tendered_grosze'],
                $line['transaction_id']
            );
            $inserted[] = $line;
        }

        $payCheck = OrderStateMachine::isFullyPaid($pdo, $orderId, $tenantId);
        $header   = self::deriveHeaderFromPayments($pdo, $orderId, $tenantId);

        $sets   = ['split_type = :st', 'updated_at = NOW()'];
        $params = [':st' => 'custom', ':oid' => $orderId, ':tid' => $tenantId];

        if ($payCheck['fully_paid'] && $header !== null) {
            $sets[]           = 'payment_status = :ps';
            $sets[]           = 'payment_method = :pm';
            $params[':ps']    = $header['payment_status'];
            $params[':pm']    = $header['payment_method'];
        }

        $pdo->prepare(
            'UPDATE sh_orders SET ' . implode(', ', $sets) . ' WHERE id = :oid AND tenant_id = :tid'
        )->execute($params);

        OrderStateMachine::writeLog($pdo, $orderId, $tenantId, $userId, 'split_payment', [
            'payment_count' => count($inserted),
            'payments'      => $inserted,
        ]);

        return [
            'success'          => true,
            'fully_paid'       => $payCheck['fully_paid'],
            'total_grosze'     => $payCheck['total_grosze'],
            'paid_grosze'      => $payCheck['paid_grosze'],
            'remaining_grosze' => $payCheck['remaining_grosze'],
            'payments_created' => count($inserted),
            'message'          => null,
        ];
    }

    /**
     * Core settlement — single or split tender on close.
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
        if (count($payments) < 1) {
            return self::fail('At least one payment entry is required.');
        }

        $completeOrder = !empty($options['complete_order']);
        $printReceipt  = !empty($options['print_receipt']);
        $tipGrosze     = (int)($options['tip_grosze'] ?? 0);

        $normalized = self::normalizePayments($payments, self::VALID_METHODS);
        if ($normalized === null) {
            return self::fail('Invalid payment entry in payments array.');
        }

        if ($completeOrder && count($normalized) > 1) {
            return self::settleSplitAndComplete(
                $pdo, $orderId, $tenantId, $userId, $normalized, $printReceipt, $tipGrosze, $flags
            );
        }

        return self::settleSingle(
            $pdo,
            $orderId,
            $tenantId,
            $userId,
            $normalized[0],
            $completeOrder,
            $printReceipt,
            $tipGrosze,
            $flags
        );
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

  // ---------------------------------------------------------------------------
  // Single tender (F1)
  // ---------------------------------------------------------------------------

    private static function settleSingle(
        \PDO $pdo,
        string $orderId,
        int $tenantId,
        int $userId,
        array $payment,
        bool $completeOrder,
        bool $printReceipt,
        int $tipGrosze,
        array $flags
    ): array {
        $method = $payment['method'];
        if ($method === 'online' && empty($payment['transaction_id'])) {
            return self::fail('Online payment requires transaction_id.');
        }
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
        $paidGrosze       = self::sumPaymentRows($pdo, $orderId, $tenantId);

        if ($oldStatus === 'completed') {
            $fullyPaid = OrderStateMachine::isFullyPaid($pdo, $orderId, $tenantId);
            if ($fullyPaid['fully_paid']) {
                return self::idempotentCloseResult($order, $payStatus, $method);
            }
        }

        $amountGrosze   = $payment['amount_grosze'] > 0
            ? $payment['amount_grosze']
            : max(0, $grandTotalGrosze - $paidGrosze);
        $tenderedGrosze = $payment['tendered_grosze'] > 0 ? $payment['tendered_grosze'] : $amountGrosze;

        if ($amountGrosze <= 0) {
            return self::fail('Payment amount must be positive.');
        }

        $remainingGrosze = max(0, $grandTotalGrosze - $paidGrosze);
        if (abs($amountGrosze - $remainingGrosze) > 1) {
            $fmtExpected = number_format($remainingGrosze / 100, 2, '.', '');
            $fmtApplied  = number_format($amountGrosze / 100, 2, '.', '');
            return self::fail("Payment total ({$fmtApplied}) does not match remaining ({$fmtExpected}).");
        }

        $paymentInserted = false;

        if ($paidGrosze < $grandTotalGrosze && OrderStateMachine::isPaid($payStatus)) {
            $backfillMethod = self::methodFromPaymentStatus($payStatus) ?? $method;
            self::insertPaymentRow(
                $pdo, $orderId, $tenantId, $userId,
                $backfillMethod, $grandTotalGrosze, $grandTotalGrosze,
                $payment['transaction_id']
            );
            $paidGrosze      = $grandTotalGrosze;
            $paymentInserted = true;
        }

        if ($paidGrosze < $grandTotalGrosze) {
            self::insertPaymentRow(
                $pdo, $orderId, $tenantId, $userId,
                $method, $amountGrosze, $tenderedGrosze, $payment['transaction_id']
            );
            $paidGrosze      = $grandTotalGrosze;
            $paymentInserted = true;
        }

        $changeDueGrosze = max(0, $tenderedGrosze - $amountGrosze - $tipGrosze);

        if ($completeOrder) {
            return self::completeAfterPayments(
                $pdo, $orderId, $tenantId, $userId, $order, $oldStatus, $payStatus,
                $method, $printReceipt, $tipGrosze, $changeDueGrosze, $paymentInserted, $flags,
                false
            );
        }

        return self::paymentOnlyResult(
            $pdo, $orderId, $tenantId, $method, $printReceipt, $tipGrosze,
            $oldStatus, $changeDueGrosze, $paymentInserted
        );
    }

  // ---------------------------------------------------------------------------
  // Split tender (F2)
  // ---------------------------------------------------------------------------

    private static function settleSplitAndComplete(
        \PDO $pdo,
        string $orderId,
        int $tenantId,
        int $userId,
        array $normalized,
        bool $printReceipt,
        int $tipGrosze,
        array $flags
    ): array {
        foreach ($normalized as $line) {
            if ($line['method'] === 'online' && empty($line['transaction_id'])) {
                return self::fail('Online payment requires transaction_id.');
            }
            if ($line['method'] === 'card') {
                $printReceipt = true;
            }
        }

        $stmt = $pdo->prepare(
            "SELECT id, grand_total, payment_status, payment_method, status, receipt_printed, order_type
             FROM sh_orders WHERE id = :oid AND tenant_id = :tid FOR UPDATE"
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
        $paidGrosze       = self::sumPaymentRows($pdo, $orderId, $tenantId);

        if ($oldStatus === 'completed') {
            $fullyPaid = OrderStateMachine::isFullyPaid($pdo, $orderId, $tenantId);
            if ($fullyPaid['fully_paid']) {
                return self::idempotentCloseResult($order, $payStatus, 'mixed');
            }
        }

        $batchGrosze = array_sum(array_column($normalized, 'amount_grosze'));
        $remaining   = max(0, $grandTotalGrosze - $paidGrosze);

        if ($batchGrosze <= 0) {
            return self::fail('Payment amount must be positive.');
        }

        if (abs($batchGrosze - $remaining) > 1) {
            $fmtExpected = number_format($remaining / 100, 2, '.', '');
            $fmtApplied  = number_format($batchGrosze / 100, 2, '.', '');
            return self::fail("Split total ({$fmtApplied}) does not match remaining ({$fmtExpected}).");
        }

        $paymentInserted = false;
        $totalTendered   = 0;

        if ($paidGrosze < $grandTotalGrosze && OrderStateMachine::isPaid($payStatus)) {
            $backfillMethod = self::methodFromPaymentStatus($payStatus) ?? $normalized[0]['method'];
            self::insertPaymentRow(
                $pdo, $orderId, $tenantId, $userId,
                $backfillMethod, $grandTotalGrosze, $grandTotalGrosze, null
            );
            $paidGrosze      = $grandTotalGrosze;
            $paymentInserted = true;
        }

        if ($paidGrosze < $grandTotalGrosze) {
            foreach ($normalized as $line) {
                self::insertPaymentRow(
                    $pdo, $orderId, $tenantId, $userId,
                    $line['method'], $line['amount_grosze'], $line['tendered_grosze'],
                    $line['transaction_id']
                );
                $totalTendered += $line['tendered_grosze'];
            }
            $paymentInserted = true;
        }

        $changeDueGrosze = max(0, $totalTendered - $batchGrosze - $tipGrosze);

        return self::completeAfterPayments(
            $pdo, $orderId, $tenantId, $userId, $order, $oldStatus, $payStatus,
            'mixed', $printReceipt, $tipGrosze, $changeDueGrosze, $paymentInserted, $flags,
            true
        );
    }

  // ---------------------------------------------------------------------------
  // Shared completion helpers
  // ---------------------------------------------------------------------------

    private static function completeAfterPayments(
        \PDO $pdo,
        string $orderId,
        int $tenantId,
        int $userId,
        array $order,
        string $oldStatus,
        string $payStatus,
        string $fallbackMethod,
        bool $printReceipt,
        int $tipGrosze,
        int $changeDueGrosze,
        bool $paymentInserted,
        array $flags,
        bool $isSplit
    ): array {
        if (OrderStateMachine::isPaid($payStatus) && !$isSplit) {
            $extraCols = [];
            if ((string)($order['order_type'] ?? '') === 'delivery') {
                $extraCols['delivery_status'] = 'delivered';
            }
            if ($printReceipt) {
                $extraCols['receipt_printed'] = 1;
            }

            $tr = OrderStateMachine::transitionOrder(
                $pdo, $orderId, $tenantId, $userId, 'completed', $flags, $extraCols
            );

            if (!$tr['success']) {
                return ['success' => false, 'message' => $tr['message'], 'old_status' => $tr['old_status'] ?? $oldStatus];
            }

            self::applyTip($pdo, $orderId, $tenantId, $tipGrosze);

            return [
                'success'           => true,
                'idempotent'        => false,
                'old_status'        => $tr['old_status'],
                'new_status'        => 'completed',
                'payment_status'    => $payStatus,
                'payment_method'    => (string)($order['payment_method'] ?? $fallbackMethod),
                'receipt_printed'   => $printReceipt,
                'change_due_grosze' => $changeDueGrosze,
                'payment_inserted'  => $paymentInserted,
                'split_tender'      => $isSplit,
                'message'           => null,
            ];
        }

        if ($isSplit || $fallbackMethod === 'mixed') {
            $header = self::deriveHeaderFromPayments($pdo, $orderId, $tenantId);
            if ($header === null) {
                return self::fail('Unable to derive payment status after split settlement.');
            }

            $extraCols = [
                'payment_status' => $header['payment_status'],
                'payment_method' => $header['payment_method'],
                'split_type'     => 'custom',
            ];
            if ((string)($order['order_type'] ?? '') === 'delivery') {
                $extraCols['delivery_status'] = 'delivered';
            }
            if ($printReceipt) {
                $extraCols['receipt_printed'] = 1;
            }

            $tr = OrderStateMachine::transitionOrder(
                $pdo, $orderId, $tenantId, $userId, 'completed', $flags, $extraCols
            );

            if (!$tr['success']) {
                return ['success' => false, 'message' => $tr['message'], 'old_status' => $tr['old_status'] ?? $oldStatus];
            }

            self::applyTip($pdo, $orderId, $tenantId, $tipGrosze);

            return [
                'success'           => true,
                'idempotent'        => false,
                'old_status'        => $tr['old_status'],
                'new_status'        => 'completed',
                'payment_status'    => $header['payment_status'],
                'payment_method'    => $header['payment_method'],
                'receipt_printed'   => $printReceipt,
                'change_due_grosze' => $changeDueGrosze,
                'payment_inserted'  => $paymentInserted,
                'split_tender'      => true,
                'message'           => null,
            ];
        }

        $fc = OrderStateMachine::fastComplete(
            $pdo, $orderId, $tenantId, $userId, $fallbackMethod, $flags,
            ['print_receipt' => $printReceipt]
        );

        if (!$fc['success']) {
            return ['success' => false, 'message' => $fc['message'], 'old_status' => $fc['old_status'] ?? $oldStatus];
        }

        self::applyTip($pdo, $orderId, $tenantId, $tipGrosze);

        return [
            'success'           => true,
            'idempotent'        => false,
            'old_status'        => $fc['old_status'],
            'new_status'        => 'completed',
            'payment_status'    => self::PAY_STATUS_MAP[$fallbackMethod] ?? $fallbackMethod,
            'payment_method'    => $fallbackMethod,
            'receipt_printed'   => $printReceipt,
            'change_due_grosze' => $changeDueGrosze,
            'payment_inserted'  => $paymentInserted,
            'split_tender'      => false,
            'message'           => null,
        ];
    }

    private static function paymentOnlyResult(
        \PDO $pdo,
        string $orderId,
        int $tenantId,
        string $method,
        bool $printReceipt,
        int $tipGrosze,
        string $oldStatus,
        int $changeDueGrosze,
        bool $paymentInserted
    ): array {
        $newPayStatus = self::PAY_STATUS_MAP[$method] ?? $method;
        $now          = date('Y-m-d H:i:s');
        $sets         = ['payment_status = :ps', 'payment_method = :pm', 'tip_amount = :tip', 'updated_at = :now'];
        $params       = [
            ':ps' => $newPayStatus, ':pm' => $method, ':tip' => $tipGrosze,
            ':now' => $now, ':oid' => $orderId, ':tid' => $tenantId,
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
            'split_tender'      => false,
            'message'           => null,
        ];
    }

    private static function idempotentCloseResult(array $order, string $payStatus, string $method): array
    {
        return [
            'success'          => true,
            'idempotent'       => true,
            'old_status'       => (string)$order['status'],
            'new_status'       => 'completed',
            'payment_status'   => $payStatus,
            'payment_method'   => (string)($order['payment_method'] ?? $method),
            'receipt_printed'  => ((int)($order['receipt_printed'] ?? 0)) === 1,
            'change_due_grosze'=> 0,
            'payment_inserted' => false,
            'split_tender'     => false,
            'message'          => null,
        ];
    }

    private static function deriveHeaderFromPayments(\PDO $pdo, string $orderId, int $tenantId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT method, SUM(amount_grosze) AS total
             FROM sh_order_payments
             WHERE order_id = :oid AND tenant_id = :tid
             GROUP BY method ORDER BY total DESC"
        );
        $stmt->execute([':oid' => $orderId, ':tid' => $tenantId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (count($rows) === 0) {
            return null;
        }

        $dominant = $rows[0];
        $methods  = array_column($rows, 'method');

        return [
            'payment_method' => count($methods) > 1 ? 'mixed' : (string)$dominant['method'],
            'payment_status' => self::PAY_STATUS_MAP[(string)$dominant['method']] ?? 'cash',
        ];
    }

    private static function applyTip(\PDO $pdo, string $orderId, int $tenantId, int $tipGrosze): void
    {
        if ($tipGrosze <= 0) {
            return;
        }
        $pdo->prepare(
            'UPDATE sh_orders SET tip_amount = :tip WHERE id = :oid AND tenant_id = :tid'
        )->execute([':tip' => $tipGrosze, ':oid' => $orderId, ':tid' => $tenantId]);
    }

  // ---------------------------------------------------------------------------
  // Low-level helpers
  // ---------------------------------------------------------------------------

    /**
     * @return list<array{method: string, amount_grosze: int, tendered_grosze: int, transaction_id: ?string}>|null
     */
    private static function normalizePayments(array $payments, array $allowedMethods): ?array
    {
        $out = [];
        foreach ($payments as $p) {
            if (!is_array($p)) {
                return null;
            }
            $method = (string)($p['method'] ?? $p['payment_method'] ?? '');
            if (!in_array($method, $allowedMethods, true)) {
                return null;
            }
            $amountGrosze = isset($p['amount']) ? (int)round((float)$p['amount'] * 100) : 0;
            $tenderedRaw  = $p['tendered'] ?? $p['amount'] ?? null;
            $tenderedGrosze = $tenderedRaw !== null ? (int)round((float)$tenderedRaw * 100) : $amountGrosze;
            $txn          = isset($p['transaction_id']) ? trim((string)$p['transaction_id']) : null;

            $out[] = [
                'method'          => $method,
                'amount_grosze'   => $amountGrosze,
                'tendered_grosze' => $tenderedGrosze,
                'transaction_id'  => ($txn !== '') ? $txn : null,
            ];
        }

        return $out;
    }

    private static function sumPaymentRows(\PDO $pdo, string $orderId, int $tenantId): int
    {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount_grosze), 0) FROM sh_order_payments
             WHERE order_id = :oid AND tenant_id = :tid'
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
        $cols   = 'id, order_id, tenant_id, user_id, method, amount_grosze, tendered_grosze';
        $vals   = ':id, :oid, :tid, :uid, :method, :amount, :tendered';
        $params = [
            ':id' => self::uuidV4(), ':oid' => $orderId, ':tid' => $tenantId, ':uid' => $userId,
            ':method' => $method, ':amount' => $amountGrosze, ':tendered' => $tenderedGrosze,
        ];

        if ($transactionId !== null && $transactionId !== '') {
            $cols .= ', transaction_id';
            $vals .= ', :txn';
            $params[':txn'] = $transactionId;
        }

        try {
            $pdo->query('SELECT created_at FROM sh_order_payments LIMIT 0');
            $cols .= ', created_at';
            $vals .= ', :now';
            $params[':now'] = date('Y-m-d H:i:s');
        } catch (\Throwable $e) {
        }

        $pdo->prepare("INSERT INTO sh_order_payments ({$cols}) VALUES ({$vals})")->execute($params);
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

    /** @return array{success: false, message: string, old_status: string} */
    private static function fail(string $message): array
    {
        return ['success' => false, 'message' => $message, 'old_status' => ''];
    }
}
