<?php
declare(strict_types=1);

// =============================================================================
// SliceHub Enterprise — Order State Machine (Central Authority)
// core/OrderStateMachine.php
//
// Single source of truth for ALL order status transitions across the system.
// Every API endpoint that changes order.status or order.delivery_status MUST
// route through this class.
//
// 3-Pillar Model:
//   status:          new | accepted | pending | preparing | ready | completed | cancelled
//   payment_status:  to_pay | online_unpaid | cash | card | online_paid
//   delivery_status: NULL | unassigned | in_delivery | delivered
//
// ╔═══════════════════════════════════════════════════════════════════════════╗
// ║  FEATURE FLAG INTEGRATION POINTS (Future: Global Settings Matrix)       ║
// ║                                                                         ║
// ║  When sh_tenant_settings gains a JSON `feature_flags` column, load it   ║
// ║  via loadTenantFlags() and the transition map will auto-expand.         ║
// ║                                                                         ║
// ║  [FF-001] skip_kitchen     : pending → ready  (bypass preparing)        ║
// ║  [FF-002] skip_dispatch    : ready → completed (bypass in_delivery)     ║
// ║  [FF-003] auto_complete    : pending → completed (fast-food one-click)  ║
// ║  [FF-004] disable_kds      : skip KDS ticket creation on accept         ║
// ║  [FF-005] fast_complete    : create + settle + complete in one TX       ║
// ║  [FF-006] skip_acceptance  : new → pending (bypass manual acceptance)   ║
// ║  [FF-007] skip_payment_lock: deliver without payment gate               ║
// ╚═══════════════════════════════════════════════════════════════════════════╝
// =============================================================================

class OrderStateMachine
{
    // =========================================================================
    // STRICT transition map — the "Full-Feature" default.
    // Each key lists the statuses it may transition TO.
    // =========================================================================
    private const STRICT_TRANSITIONS = [
        // `new` → `preparing`: POS „PRZYGOTUJ” bez osobnego accept (legacy było pending→preparing).
        'new'       => ['accepted', 'pending', 'preparing', 'cancelled'],
        'accepted'  => ['pending', 'preparing', 'cancelled'],
        'pending'   => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready'     => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    private const DELIVERY_TRANSITIONS = [
        'unassigned'  => ['in_delivery'],
        'in_delivery' => ['delivered'],
        'delivered'   => [],
    ];

    // Dine-in orders skip in_delivery entirely.
    // Flow: pending → preparing → ready (served to table) → completed (paid)
    private const DINE_IN_TRANSITIONS = [
        'new'       => ['accepted', 'pending', 'preparing', 'cancelled'],
        'accepted'  => ['pending', 'preparing', 'cancelled'],
        'pending'   => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready'     => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    private const TERMINAL_STATUSES = ['completed', 'cancelled'];

    // =========================================================================
    // [FF-HOOK] Additional edges unlocked by feature flags.
    // Key = flag name, Value = array of [from => [to, to, ...]] edges to ADD.
    // =========================================================================
    private const FLAG_TRANSITION_OVERRIDES = [
        // [FF-001] Kitchen bypassed entirely — order goes straight to ready
        'skip_kitchen' => [
            'new'      => ['ready'],
            'pending'  => ['ready'],
            'accepted' => ['ready'],
        ],
        // [FF-003] One-click fast food — order goes straight to completed
        'auto_complete' => [
            'new'      => ['ready', 'completed'],
            'pending'  => ['ready', 'completed'],
            'accepted' => ['ready', 'completed'],
        ],
        // [FF-002] Delivery orders skip the dispatch/driver step
        'skip_dispatch' => [
            'ready' => ['completed'],
        ],
        // [FF-006] Online orders skip manual acceptance
        'skip_acceptance' => [
            'new' => ['pending', 'preparing'],
        ],
    ];

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Build the effective transition map for a tenant, merging strict rules
     * with any active feature flags.
     *
     * @param array  $flags     Associative array of flag_name => bool.
     *                          [FF-HOOK] In production this will come from
     *                          sh_tenant_settings.feature_flags JSON column.
     * @param string $orderType 'delivery'|'takeaway'|'dine_in' — dine_in uses
     *                          a restricted map that excludes in_delivery.
     */
    public static function buildTransitionMap(array $flags = [], string $orderType = ''): array
    {
        $map = ($orderType === 'dine_in')
            ? self::DINE_IN_TRANSITIONS
            : self::STRICT_TRANSITIONS;

        foreach (self::FLAG_TRANSITION_OVERRIDES as $flag => $edges) {
            if (!empty($flags[$flag])) {
                foreach ($edges as $from => $toList) {
                    if (!isset($map[$from])) continue;
                    $map[$from] = array_values(array_unique(
                        array_merge($map[$from], $toList)
                    ));
                }
            }
        }

        return $map;
    }

    /**
     * Check if a status transition is allowed under current rules.
     *
     * @param string $from      Current order status
     * @param string $to        Desired new status
     * @param array  $flags     Tenant feature flags (empty = strict mode)
     * @param string $orderType Order type for context-aware transitions
     * @return bool
     */
    public static function canTransition(string $from, string $to, array $flags = [], string $orderType = ''): bool
    {
        $map = self::buildTransitionMap($flags, $orderType);

        if (!isset($map[$from])) {
            return false;
        }

        return in_array($to, $map[$from], true);
    }

    /**
     * Check if a delivery_status transition is allowed.
     */
    public static function canTransitionDelivery(string $from, string $to): bool
    {
        if (!isset(self::DELIVERY_TRANSITIONS[$from])) {
            return false;
        }
        return in_array($to, self::DELIVERY_TRANSITIONS[$from], true);
    }

    /**
     * Validate and execute an order status transition inside an existing
     * PDO transaction. Writes audit trail to sh_order_audit AND sh_order_logs.
     *
     * The caller MUST have already called $pdo->beginTransaction().
     *
     * @param \PDO   $pdo
     * @param string $orderId
     * @param int    $tenantId
     * @param int    $userId     Who triggered the transition
     * @param string $newStatus  Target status
     * @param array  $flags      Tenant feature flags
     * @param array  $extraCols  Optional extra columns to SET (col => value)
     *
     * @return array ['success' => bool, 'old_status' => string, 'new_status' => string, 'message' => ?string]
     *
     * @throws \RuntimeException on concurrent modification
     */
    public static function transitionOrder(
        \PDO $pdo,
        string $orderId,
        int $tenantId,
        int $userId,
        string $newStatus,
        array $flags = [],
        array $extraCols = []
    ): array {
        $validTargets = array_keys(self::STRICT_TRANSITIONS);
        if (!in_array($newStatus, $validTargets, true)) {
            return [
                'success'    => false,
                'old_status' => '',
                'new_status' => $newStatus,
                'message'    => "Invalid target status: {$newStatus}",
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT status, order_type FROM sh_orders WHERE id = :oid AND tenant_id = :tid FOR UPDATE"
        );
        $stmt->execute([':oid' => $orderId, ':tid' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$row) {
            return [
                'success'    => false,
                'old_status' => '',
                'new_status' => $newStatus,
                'message'    => 'Order not found.',
            ];
        }

        $oldStatus = (string)$row['status'];
        $orderType = (string)($row['order_type'] ?? '');

        // [FF-HOOK] Central gate. Feature flags widen the map; order_type narrows it for dine_in.
        if (!self::canTransition($oldStatus, $newStatus, $flags, $orderType)) {
            return [
                'success'    => false,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'message'    => "Transition '{$oldStatus}' → '{$newStatus}' is not allowed for order type '{$orderType}'.",
            ];
        }

        $now = date('Y-m-d H:i:s');
        $setClauses = ['status = :ns', 'updated_at = :now'];
        $params = [
            ':ns'  => $newStatus,
            ':now' => $now,
            ':oid' => $orderId,
            ':tid' => $tenantId,
        ];

        $i = 0;
        foreach ($extraCols as $col => $val) {
            $safeCol = preg_replace('/[^a-zA-Z0-9_]/', '', $col);
            $paramKey = ":xc{$i}";
            $setClauses[] = "`{$safeCol}` = {$paramKey}";
            $params[$paramKey] = $val;
            $i++;
        }

        $sql = "UPDATE sh_orders SET " . implode(', ', $setClauses)
             . " WHERE id = :oid AND tenant_id = :tid";
        $upd = $pdo->prepare($sql);
        $upd->execute($params);

        if ($upd->rowCount() === 0) {
            throw new \RuntimeException("Concurrent modification on order {$orderId}.");
        }

        $pdo->prepare(
            "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
             VALUES (:oid, :uid, :os, :ns, :now)"
        )->execute([
            ':oid' => $orderId,
            ':uid' => $userId,
            ':os'  => $oldStatus,
            ':ns'  => $newStatus,
            ':now' => $now,
        ]);

        self::writeLog($pdo, $orderId, $tenantId, $userId, 'state_change', [
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'order_type' => $orderType,
            'extra_cols' => array_keys($extraCols),
        ]);

        return [
            'success'    => true,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'message'    => null,
        ];
    }

    /**
     * Fast-track completion: settle payment + transition to completed in one
     * atomic step. Designed for POS "one-click close" workflows.
     *
     * [FF-005] This method is the hook for the `fast_complete` feature flag.
     * Currently it enforces the transition rules, but when `auto_complete`
     * flag is active, it allows skipping intermediate states.
     *
     * @param \PDO   $pdo
     * @param string $orderId
     * @param int    $tenantId
     * @param int    $userId
     * @param string $paymentMethod  cash | card | online
     * @param array  $flags          Tenant feature flags
     * @param array  $options        Optional: ['print_receipt' => bool]
     *
     * @return array ['success' => bool, 'message' => ?string, 'old_status' => string]
     */
    public static function fastComplete(
        \PDO $pdo,
        string $orderId,
        int $tenantId,
        int $userId,
        string $paymentMethod,
        array $flags = [],
        array $options = []
    ): array {
        $validMethods = ['cash', 'card', 'online'];
        if (!in_array($paymentMethod, $validMethods, true)) {
            return ['success' => false, 'message' => "Invalid payment method: {$paymentMethod}", 'old_status' => ''];
        }

        $stmt = $pdo->prepare(
            "SELECT status, payment_status, receipt_printed, order_type
             FROM sh_orders WHERE id = :oid AND tenant_id = :tid FOR UPDATE"
        );
        $stmt->execute([':oid' => $orderId, ':tid' => $tenantId]);
        $order = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$order) {
            return ['success' => false, 'message' => 'Order not found.', 'old_status' => ''];
        }

        $oldStatus  = $order['status'];
        $orderType  = (string)($order['order_type'] ?? '');

        // [FIX-3] canTransition now receives order_type so dine_in orders
        // are validated against DINE_IN_TRANSITIONS when the maps diverge.
        if (!self::canTransition($oldStatus, 'completed', $flags, $orderType)) {
            // If not directly allowed, check if we can fast-track via
            // intermediate state (e.g., pending → ready → completed)
            if (!in_array($oldStatus, self::TERMINAL_STATUSES, true)) {
                // [FF-HOOK] Future: auto_complete flag will make this path
                // reachable. For now, require strict readiness.
                return [
                    'success'    => false,
                    'message'    => "Cannot fast-complete from status '{$oldStatus}'. Order must be 'ready' first.",
                    'old_status' => $oldStatus,
                ];
            }
            return [
                'success'    => false,
                'message'    => "Order is already '{$oldStatus}'.",
                'old_status' => $oldStatus,
            ];
        }

        $printReceipt = !empty($options['print_receipt']);
        $alreadyPrinted = (int)($order['receipt_printed'] ?? 0);

        if (in_array($paymentMethod, ['card', 'online'], true)
            && !$printReceipt && $alreadyPrinted === 0) {
            return [
                'success'    => false,
                'message'    => 'Card/online payments require receipt printing.',
                'old_status' => $oldStatus,
            ];
        }

        $payStatusMap = ['cash' => 'cash', 'card' => 'card', 'online' => 'online_paid'];
        $newPayStatus = $payStatusMap[$paymentMethod];
        $now = date('Y-m-d H:i:s');

        $extraSets = "payment_status = :ps, payment_method = :pm";
        $params = [
            ':ps'  => $newPayStatus,
            ':pm'  => $paymentMethod,
            ':ns'  => 'completed',
            ':now' => $now,
            ':oid' => $orderId,
            ':tid' => $tenantId,
        ];

        if ($printReceipt) {
            $extraSets .= ", receipt_printed = 1";
        }

        // For delivery orders, also mark delivery as delivered.
        // Dine-in / takeaway orders have no delivery track — never touch delivery_status.
        if ($orderType === 'delivery') {
            $extraSets .= ", delivery_status = 'delivered'";
        }

        $sql = "UPDATE sh_orders SET status = :ns, {$extraSets}, updated_at = :now
                WHERE id = :oid AND tenant_id = :tid";
        $pdo->prepare($sql)->execute($params);

        $pdo->prepare(
            "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
             VALUES (:oid, :uid, :os, 'completed', :now)"
        )->execute([':oid' => $orderId, ':uid' => $userId, ':os' => $oldStatus, ':now' => $now]);

        return [
            'success'    => true,
            'message'    => null,
            'old_status' => $oldStatus,
        ];
    }

    // =========================================================================
    // TENANT SETTINGS LOADER
    //
    // [FF-HOOK] This is where the Global Settings Matrix will be read.
    // Currently returns empty flags (strict mode for all tenants).
    // Future implementation will query sh_tenant_settings for a JSON
    // feature_flags column or multiple KV rows.
    //
    // Example future implementation:
    //
    //   $stmt = $pdo->prepare(
    //       "SELECT setting_value FROM sh_tenant_settings
    //        WHERE tenant_id = :tid AND setting_key = 'feature_flags'"
    //   );
    //   $stmt->execute([':tid' => $tenantId]);
    //   $raw = $stmt->fetchColumn();
    //   return $raw ? (json_decode($raw, true) ?: []) : [];
    //
    // This will return something like:
    //   { "skip_kitchen": true, "auto_complete": false, "disable_kds": true }
    // =========================================================================
    public static function loadTenantFlags(\PDO $pdo, int $tenantId): array
    {
        // [FF-HOOK] Placeholder — returns strict-mode (no flags) for now.
        // When the Settings Matrix UI is built, replace this with:
        //   SELECT setting_value FROM sh_tenant_settings
        //   WHERE tenant_id = ? AND setting_key = 'feature_flags'
        try {
            $stmt = $pdo->prepare(
                "SELECT setting_value FROM sh_tenant_settings
                 WHERE tenant_id = :tid AND setting_key = 'feature_flags'"
            );
            $stmt->execute([':tid' => $tenantId]);
            $raw = $stmt->fetchColumn();
            $stmt->closeCursor();

            if ($raw !== false && $raw !== null && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (\Throwable $e) {
            // Table/column might not exist yet — graceful fallback to strict mode
        }

        return [];
    }

    /**
     * Helper: is the given payment_status considered "paid"?
     */
    public static function isPaid(string $paymentStatus): bool
    {
        return in_array($paymentStatus, ['cash', 'card', 'online_paid'], true);
    }

    /**
     * Check if an order is fully paid.
     *
     * Two paths to "fully paid":
     *   1. sh_order_payments rows sum to >= grand_total (split-tender / dine-in)
     *   2. payment_status is already a "paid" terminal state (online_paid, cash, card)
     *      which covers pre-paid delivery orders that have no sh_order_payments
     *      rows because the driver never collected money.
     *
     * @return array ['fully_paid' => bool, 'total_grosze' => int, 'paid_grosze' => int, 'remaining_grosze' => int]
     */
    public static function isFullyPaid(\PDO $pdo, string $orderId, int $tenantId): array
    {
        $stmt = $pdo->prepare(
            "SELECT grand_total, payment_status FROM sh_orders WHERE id = :oid AND tenant_id = :tid"
        );
        $stmt->execute([':oid' => $orderId, ':tid' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$row) {
            return ['fully_paid' => false, 'total_grosze' => 0, 'paid_grosze' => 0, 'remaining_grosze' => 0];
        }

        $totalGrosze = (int)$row['grand_total'];
        $payStatus   = (string)$row['payment_status'];

        // Pre-paid orders (online_paid, or cash/card settled at POS) are fully paid
        // even without sh_order_payments rows for this specific order.
        if (self::isPaid($payStatus)) {
            return [
                'fully_paid'       => true,
                'total_grosze'     => $totalGrosze,
                'paid_grosze'      => $totalGrosze,
                'remaining_grosze' => 0,
            ];
        }

        // Split-tender path: sum actual payment rows
        $stmtPaid = $pdo->prepare(
            "SELECT COALESCE(SUM(amount_grosze), 0) AS paid
             FROM sh_order_payments
             WHERE order_id = :oid AND tenant_id = :tid"
        );
        $stmtPaid->execute([':oid' => $orderId, ':tid' => $tenantId]);
        $paidGrosze = (int)$stmtPaid->fetchColumn();
        $stmtPaid->closeCursor();

        $remaining = $totalGrosze - $paidGrosze;

        return [
            'fully_paid'       => ($paidGrosze >= $totalGrosze),
            'total_grosze'     => $totalGrosze,
            'paid_grosze'      => $paidGrosze,
            'remaining_grosze' => max(0, $remaining),
        ];
    }

    /**
     * Dine-in completion: verify full payment, then transition to completed.
     * Enforces the rule that dine_in orders can only complete when fully paid.
     * Derives payment_status dynamically from the dominant payment method
     * recorded in sh_order_payments (never hardcoded).
     */
    public static function completeDineIn(
        \PDO $pdo,
        string $orderId,
        int $tenantId,
        int $userId,
        array $flags = []
    ): array {
        $payCheck = self::isFullyPaid($pdo, $orderId, $tenantId);
        if (!$payCheck['fully_paid']) {
            return [
                'success' => false,
                'message' => sprintf(
                    'Order not fully paid. Total: %d, Paid: %d, Remaining: %d grosze.',
                    $payCheck['total_grosze'],
                    $payCheck['paid_grosze'],
                    $payCheck['remaining_grosze']
                ),
                'old_status' => '',
            ];
        }

        // FIX-4: Derive payment_status from actual payment records
        $stmtDominant = $pdo->prepare(
            "SELECT method, SUM(amount_grosze) AS total
             FROM sh_order_payments
             WHERE order_id = :oid AND tenant_id = :tid
             GROUP BY method ORDER BY total DESC LIMIT 1"
        );
        $stmtDominant->execute([':oid' => $orderId, ':tid' => $tenantId]);
        $dominant = $stmtDominant->fetch(\PDO::FETCH_ASSOC);
        $stmtDominant->closeCursor();

        $payStatusMap = ['cash' => 'cash', 'card' => 'card', 'online' => 'online_paid', 'voucher' => 'cash'];
        $dominantMethod = $dominant['method'] ?? 'cash';
        $derivedStatus  = $payStatusMap[$dominantMethod] ?? 'cash';

        return self::transitionOrder(
            $pdo, $orderId, $tenantId, $userId, 'completed', $flags,
            ['payment_status' => $derivedStatus, 'payment_method' => $dominantMethod]
        );
    }

    /**
     * Return the list of valid order statuses.
     */
    public static function validStatuses(): array
    {
        return array_keys(self::STRICT_TRANSITIONS);
    }

    /**
     * Return whether a status is terminal (no further transitions allowed).
     */
    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    // =========================================================================
    // STRUCTURED LOGGING → sh_order_logs
    // =========================================================================

    /**
     * Write a structured log entry to sh_order_logs.
     * Silently no-ops if the table doesn't exist yet (graceful migration).
     */
    public static function writeLog(
        \PDO $pdo,
        string $orderId,
        int $tenantId,
        int $userId,
        string $action,
        array $details = []
    ): void {
        try {
            $oid = ($orderId !== '') ? $orderId : null;
            $pdo->prepare(
                "INSERT INTO sh_order_logs (order_id, tenant_id, user_id, action, detail_json, created_at)
                 VALUES (:oid, :tid, :uid, :act, :det, :now)"
            )->execute([
                ':oid' => $oid,
                ':tid' => $tenantId,
                ':uid' => $userId,
                ':act' => $action,
                ':det' => json_encode($details, JSON_UNESCAPED_UNICODE),
                ':now' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log("[OrderStateMachine::writeLog] {$e->getMessage()}");
        }
    }
}
