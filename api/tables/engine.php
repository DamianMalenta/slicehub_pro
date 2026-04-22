<?php
declare(strict_types=1);

// =============================================================================
// SliceHub Enterprise — Dine-In Table Management Engine
// api/tables/engine.php
//
// Unified REST API (POST, action-based) for advanced table operations:
//   - get_zones           : List all zones with tables
//   - get_tables          : List tables (optionally by zone)
//   - update_table_status : Change physical_status
//   - merge_tables        : Link child table → parent via parent_table_id
//   - unmerge_tables      : Reverse a merge
//   - split_payment       : Insert multiple partial payments into sh_order_payments
//   - fire_course         : Set fired_at on order lines to trigger KDS
//   - check_payment       : Check isFullyPaid status for an order
//   - complete_dine_in    : Verify full payment + transition to completed
//
// All multi-table writes use PDO transactions.
// All queries include tenant_id barrier.
// =============================================================================

@ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function tableResponse(bool $ok, $data = null, ?string $msg = null): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function inputStr(array $input, string $key, string $default = ''): string
{
    $v = $input[$key] ?? $default;
    return trim((string)$v);
}

function generateUuid(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/../../core/OrderStateMachine.php';

    $raw    = file_get_contents('php://input');
    $input  = json_decode($raw ?: '{}', true) ?? [];
    $action = inputStr($input, 'action');

    $tenantFlags = OrderStateMachine::loadTenantFlags($pdo, $tenant_id);

    // =========================================================================
    // GET_ZONES — All zones with their tables
    // =========================================================================
    if ($action === 'get_zones') {
        $stmtZ = $pdo->prepare(
            "SELECT id, name, display_order, is_active
             FROM sh_zones
             WHERE tenant_id = :tid
             ORDER BY display_order ASC, name ASC"
        );
        $stmtZ->execute([':tid' => $tenant_id]);
        $zones = $stmtZ->fetchAll(\PDO::FETCH_ASSOC);

        $stmtT = $pdo->prepare(
            "SELECT id, zone_id, table_number, seats, shape, pos_x, pos_y,
                    qr_hash, parent_table_id, physical_status, is_active
             FROM sh_tables
             WHERE tenant_id = :tid
             ORDER BY table_number ASC"
        );
        $stmtT->execute([':tid' => $tenant_id]);
        $allTables = $stmtT->fetchAll(\PDO::FETCH_ASSOC);

        $tablesByZone = [];
        $unzoned = [];
        foreach ($allTables as $t) {
            if ($t['zone_id'] !== null) {
                $tablesByZone[(int)$t['zone_id']][] = $t;
            } else {
                $unzoned[] = $t;
            }
        }

        foreach ($zones as &$z) {
            $z['tables'] = $tablesByZone[(int)$z['id']] ?? [];
        }
        unset($z);

        tableResponse(true, ['zones' => $zones, 'unzoned_tables' => $unzoned]);
    }

    // =========================================================================
    // GET_TABLES — Flat table list (optionally filtered by zone_id)
    // =========================================================================
    if ($action === 'get_tables') {
        $zoneId = isset($input['zone_id']) ? (int)$input['zone_id'] : null;

        $sql = "SELECT t.id, t.zone_id, t.table_number, t.seats, t.shape,
                       t.pos_x, t.pos_y, t.qr_hash, t.parent_table_id,
                       t.physical_status, t.is_active,
                       z.name AS zone_name
                FROM sh_tables t
                LEFT JOIN sh_zones z ON z.id = t.zone_id AND z.tenant_id = t.tenant_id
                WHERE t.tenant_id = :tid";
        $params = [':tid' => $tenant_id];

        if ($zoneId !== null) {
            $sql .= " AND t.zone_id = :zid";
            $params[':zid'] = $zoneId;
        }
        $sql .= " ORDER BY t.table_number ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        tableResponse(true, ['tables' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    // =========================================================================
    // UPDATE_TABLE_STATUS — Change physical_status of a single table
    // =========================================================================
    if ($action === 'update_table_status') {
        $tableId   = (int)($input['table_id'] ?? 0);
        $newStatus = inputStr($input, 'physical_status');

        $validStatuses = ['free', 'occupied', 'reserved', 'dirty', 'merged'];
        if (!in_array($newStatus, $validStatuses, true)) {
            tableResponse(false, null, "Invalid physical_status: {$newStatus}");
        }

        $stmt = $pdo->prepare(
            "UPDATE sh_tables SET physical_status = :st, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tid"
        );
        $stmt->execute([':st' => $newStatus, ':id' => $tableId, ':tid' => $tenant_id]);

        if ($stmt->rowCount() === 0) {
            tableResponse(false, null, 'Table not found or no change.');
        }

        tableResponse(true, ['table_id' => $tableId, 'physical_status' => $newStatus]);
    }

    // =========================================================================
    // MERGE_TABLES — Link table_id_2 as child of table_id_1
    //
    // Business rules:
    //   - Both tables must belong to the same tenant
    //   - Neither table can already be a child (no chain merges)
    //   - If BOTH tables have active orders, require consolidate_orders=true
    //   - Child's physical_status becomes 'merged'
    //   - Parent's seats += child's seats
    //   - All active orders on child table are moved to parent
    // =========================================================================
    if ($action === 'merge_tables') {
        $parentId     = (int)($input['table_id_1'] ?? 0);
        $childId      = (int)($input['table_id_2'] ?? 0);
        $consolidate  = (bool)($input['consolidate_orders'] ?? false);

        if ($parentId === 0 || $childId === 0 || $parentId === $childId) {
            tableResponse(false, null, 'Two distinct table_id_1 and table_id_2 required.');
        }

        $pdo->beginTransaction();
        try {
            // FIX-7: Explicit keying instead of fragile FETCH_UNIQUE
            $stmtLock = $pdo->prepare(
                "SELECT id, table_number, seats, parent_table_id, physical_status
                 FROM sh_tables
                 WHERE id IN (:p, :c) AND tenant_id = :tid
                 FOR UPDATE"
            );
            $stmtLock->execute([':p' => $parentId, ':c' => $childId, ':tid' => $tenant_id]);
            $rows = $stmtLock->fetchAll(\PDO::FETCH_ASSOC);
            $stmtLock->closeCursor();

            $locked = [];
            foreach ($rows as $r) {
                $locked[(int)$r['id']] = $r;
            }

            if (!isset($locked[$parentId]) || !isset($locked[$childId])) {
                $pdo->rollBack();
                tableResponse(false, null, 'One or both tables not found for this tenant.');
            }

            $parent = $locked[$parentId];
            $child  = $locked[$childId];

            if ($parent['parent_table_id'] !== null) {
                $pdo->rollBack();
                tableResponse(false, null, "Table {$parent['table_number']} is already a child — cannot be parent.");
            }
            if ($child['parent_table_id'] !== null) {
                $pdo->rollBack();
                tableResponse(false, null, "Table {$child['table_number']} is already merged into another table.");
            }

            // FIX-1: Check for active orders on both tables before merging
            $stmtActiveOrders = $pdo->prepare(
                "SELECT id, table_id, status, order_number FROM sh_orders
                 WHERE table_id IN (:p, :c) AND tenant_id = :tid
                   AND status NOT IN ('completed', 'cancelled')
                 FOR UPDATE"
            );
            $stmtActiveOrders->execute([':p' => $parentId, ':c' => $childId, ':tid' => $tenant_id]);
            $activeOrders = $stmtActiveOrders->fetchAll(\PDO::FETCH_ASSOC);
            $stmtActiveOrders->closeCursor();

            $parentHasOrders = false;
            $childHasOrders  = false;
            foreach ($activeOrders as $ao) {
                if ((int)$ao['table_id'] === $parentId) $parentHasOrders = true;
                if ((int)$ao['table_id'] === $childId)  $childHasOrders  = true;
            }

            if ($parentHasOrders && $childHasOrders && !$consolidate) {
                $pdo->rollBack();
                tableResponse(false, null,
                    'Both tables have active orders. Send consolidate_orders=true to move '
                    . "child table's orders to the parent, or close one order first."
                );
            }

            $newSeats = (int)$parent['seats'] + (int)$child['seats'];

            $pdo->prepare(
                "UPDATE sh_tables SET seats = :s, physical_status = 'occupied', updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tid"
            )->execute([':s' => $newSeats, ':id' => $parentId, ':tid' => $tenant_id]);

            $pdo->prepare(
                "UPDATE sh_tables SET parent_table_id = :pid, physical_status = 'merged', updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tid"
            )->execute([':pid' => $parentId, ':id' => $childId, ':tid' => $tenant_id]);

            $movedOrders = 0;
            if ($childHasOrders) {
                $stmtMove = $pdo->prepare(
                    "UPDATE sh_orders SET table_id = :pid, updated_at = NOW()
                     WHERE table_id = :cid AND tenant_id = :tid
                       AND status NOT IN ('completed', 'cancelled')"
                );
                $stmtMove->execute([':pid' => $parentId, ':cid' => $childId, ':tid' => $tenant_id]);
                $movedOrders = $stmtMove->rowCount();
            }

            // FIX-6b: Pass NULL for order_id — table-level ops are not order-scoped
            OrderStateMachine::writeLog($pdo, '', $tenant_id, $user_id, 'merge_tables', [
                'parent_table_id'   => $parentId,
                'child_table_id'    => $childId,
                'new_seat_count'    => $newSeats,
                'orders_moved'      => $movedOrders,
                'consolidate_flag'  => $consolidate,
            ]);

            $pdo->commit();
            tableResponse(true, [
                'parent_table_id' => $parentId,
                'child_table_id'  => $childId,
                'combined_seats'  => $newSeats,
                'orders_moved'    => $movedOrders,
            ]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[Tables Engine] merge_tables: ' . $e->getMessage());
            tableResponse(false, null, 'Merge failed. Please try again.');
        }
    }

    // =========================================================================
    // UNMERGE_TABLES — Reverse a merge: clear parent_table_id, restore seats
    // =========================================================================
    if ($action === 'unmerge_tables') {
        $childId = (int)($input['table_id'] ?? 0);

        if ($childId === 0) {
            tableResponse(false, null, 'table_id (the child to unmerge) is required.');
        }

        $pdo->beginTransaction();
        try {
            $stmtChild = $pdo->prepare(
                "SELECT id, parent_table_id, seats FROM sh_tables
                 WHERE id = :id AND tenant_id = :tid FOR UPDATE"
            );
            $stmtChild->execute([':id' => $childId, ':tid' => $tenant_id]);
            $child = $stmtChild->fetch(\PDO::FETCH_ASSOC);
            $stmtChild->closeCursor();

            if (!$child || $child['parent_table_id'] === null) {
                $pdo->rollBack();
                tableResponse(false, null, 'Table is not currently merged.');
            }

            $parentId = (int)$child['parent_table_id'];

            $stmtParent = $pdo->prepare(
                "SELECT id, seats FROM sh_tables WHERE id = :id AND tenant_id = :tid FOR UPDATE"
            );
            $stmtParent->execute([':id' => $parentId, ':tid' => $tenant_id]);
            $parent = $stmtParent->fetch(\PDO::FETCH_ASSOC);
            $stmtParent->closeCursor();

            if ($parent) {
                $restoredSeats = max(1, (int)$parent['seats'] - (int)$child['seats']);
                $pdo->prepare(
                    "UPDATE sh_tables SET seats = :s, updated_at = NOW()
                     WHERE id = :id AND tenant_id = :tid"
                )->execute([':s' => $restoredSeats, ':id' => $parentId, ':tid' => $tenant_id]);
            }

            $pdo->prepare(
                "UPDATE sh_tables SET parent_table_id = NULL, physical_status = 'free', updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tid"
            )->execute([':id' => $childId, ':tid' => $tenant_id]);

            OrderStateMachine::writeLog($pdo, '', $tenant_id, $user_id, 'unmerge_tables', [
                'parent_table_id' => $parentId,
                'child_table_id'  => $childId,
            ]);

            $pdo->commit();
            tableResponse(true, ['unmerged_table_id' => $childId, 'parent_table_id' => $parentId]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[Tables Engine] unmerge_tables: ' . $e->getMessage());
            tableResponse(false, null, 'Unmerge failed. Please try again.');
        }
    }

    // =========================================================================
    // SPLIT_PAYMENT — Insert multiple partial payments for a dine-in order
    //
    // Payload: {
    //   action: "split_payment",
    //   order_id: "uuid",
    //   payments: [
    //     { amount: 25.50, payment_method: "cash" },
    //     { amount: 14.50, payment_method: "card", transaction_id: "TXN-..." }
    //   ]
    // }
    //
    // Each entry creates a row in sh_order_payments.
    // After all inserts, checks isFullyPaid and returns the balance.
    // =========================================================================
    if ($action === 'split_payment') {
        $orderId  = inputStr($input, 'order_id');
        $payments = $input['payments'] ?? [];

        if ($orderId === '' || !is_array($payments) || count($payments) === 0) {
            tableResponse(false, null, 'order_id and payments[] array required.');
        }

        $validMethods = ['cash', 'card', 'online', 'voucher'];

        $pdo->beginTransaction();
        try {
            $stmtOrder = $pdo->prepare(
                "SELECT id, grand_total, status, order_type
                 FROM sh_orders WHERE id = :oid AND tenant_id = :tid FOR UPDATE"
            );
            $stmtOrder->execute([':oid' => $orderId, ':tid' => $tenant_id]);
            $order = $stmtOrder->fetch(\PDO::FETCH_ASSOC);
            $stmtOrder->closeCursor();

            if (!$order) {
                $pdo->rollBack();
                tableResponse(false, null, 'Order not found.');
            }

            if (in_array($order['status'], ['completed', 'cancelled'], true)) {
                $pdo->rollBack();
                tableResponse(false, null, "Order is already {$order['status']}.");
            }

            $stmtInsert = $pdo->prepare(
                "INSERT INTO sh_order_payments
                    (id, order_id, tenant_id, method, amount_grosze, tendered_grosze, transaction_id, created_at)
                 VALUES (:id, :oid, :tid, :mth, :amt, :tend, :txn, NOW())"
            );

            $insertedPayments = [];
            foreach ($payments as $p) {
                $method = trim((string)($p['payment_method'] ?? ''));
                if (!in_array($method, $validMethods, true)) {
                    $pdo->rollBack();
                    tableResponse(false, null, "Invalid payment_method: {$method}");
                }

                $amountGrosze   = (int)round(((float)($p['amount'] ?? 0)) * 100);
                $tenderedGrosze = (int)round(((float)($p['tendered'] ?? $p['amount'] ?? 0)) * 100);
                $txnId          = isset($p['transaction_id']) ? trim((string)$p['transaction_id']) : null;
                $paymentId      = generateUuid();

                if ($amountGrosze <= 0) {
                    $pdo->rollBack();
                    tableResponse(false, null, 'Each payment amount must be > 0.');
                }

                $stmtInsert->execute([
                    ':id'   => $paymentId,
                    ':oid'  => $orderId,
                    ':tid'  => $tenant_id,
                    ':mth'  => $method,
                    ':amt'  => $amountGrosze,
                    ':tend' => $tenderedGrosze,
                    ':txn'  => $txnId,
                ]);

                $insertedPayments[] = [
                    'id'             => $paymentId,
                    'method'         => $method,
                    'amount_grosze'  => $amountGrosze,
                    'transaction_id' => $txnId,
                ];
            }

            // FIX-5: Post-insert overpayment guard.
            // SUM includes the just-inserted rows (same TX), so no double-counting.
            $stmtCumulative = $pdo->prepare(
                "SELECT COALESCE(SUM(amount_grosze), 0) FROM sh_order_payments
                 WHERE order_id = :oid AND tenant_id = :tid"
            );
            $stmtCumulative->execute([':oid' => $orderId, ':tid' => $tenant_id]);
            $cumulativePaid = (int)$stmtCumulative->fetchColumn();
            $stmtCumulative->closeCursor();

            $orderTotal = (int)$order['grand_total'];

            if ($cumulativePaid > $orderTotal) {
                $over = $cumulativePaid - $orderTotal;
                $pdo->rollBack();
                tableResponse(false, null,
                    "Overpayment blocked: cumulative payments exceed the order total by "
                    . number_format($over / 100, 2) . " PLN. Adjust amounts or record a tip separately."
                );
            }

            OrderStateMachine::writeLog($pdo, $orderId, $tenant_id, $user_id, 'split_payment', [
                'payment_count' => count($insertedPayments),
                'payments'      => $insertedPayments,
            ]);

            $payCheck = OrderStateMachine::isFullyPaid($pdo, $orderId, $tenant_id);

            if ($payCheck['fully_paid']) {
                // Derive payment_status from dominant method (by amount)
                $stmtDominant = $pdo->prepare(
                    "SELECT method, SUM(amount_grosze) AS total
                     FROM sh_order_payments
                     WHERE order_id = :oid AND tenant_id = :tid
                     GROUP BY method ORDER BY total DESC LIMIT 1"
                );
                $stmtDominant->execute([':oid' => $orderId, ':tid' => $tenant_id]);
                $dominant = $stmtDominant->fetch(\PDO::FETCH_ASSOC);
                $stmtDominant->closeCursor();

                $payStatusMap = ['cash' => 'cash', 'card' => 'card', 'online' => 'online_paid', 'voucher' => 'cash'];
                $derivedStatus = $payStatusMap[$dominant['method'] ?? 'cash'] ?? 'cash';

                $pdo->prepare(
                    "UPDATE sh_orders SET payment_status = :ps, payment_method = :pm,
                            split_type = 'custom', updated_at = NOW()
                     WHERE id = :oid AND tenant_id = :tid"
                )->execute([
                    ':ps' => $derivedStatus,
                    ':pm' => $dominant['method'] ?? 'cash',
                    ':oid' => $orderId,
                    ':tid' => $tenant_id,
                ]);
            } else {
                $pdo->prepare(
                    "UPDATE sh_orders SET split_type = 'custom', updated_at = NOW()
                     WHERE id = :oid AND tenant_id = :tid"
                )->execute([':oid' => $orderId, ':tid' => $tenant_id]);
            }

            $pdo->commit();

            tableResponse(true, [
                'order_id'         => $orderId,
                'payments_created' => count($insertedPayments),
                'fully_paid'       => $payCheck['fully_paid'],
                'total_grosze'     => $payCheck['total_grosze'],
                'paid_grosze'      => $payCheck['paid_grosze'],
                'remaining_grosze' => $payCheck['remaining_grosze'],
            ]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[Tables Engine] split_payment: ' . $e->getMessage());
            tableResponse(false, null, 'Split payment failed. Please try again.');
        }
    }

    // =========================================================================
    // FIRE_COURSE — Set fired_at on order_lines for a given course_number
    //
    // When fired, the KDS should display these items as a new batch.
    // Only unfired items (fired_at IS NULL) are updated.
    // =========================================================================
    if ($action === 'fire_course') {
        $orderId      = inputStr($input, 'order_id');
        $courseNumber  = (int)($input['course_number'] ?? 0);

        if ($orderId === '' || $courseNumber <= 0) {
            tableResponse(false, null, 'order_id and course_number (>0) required.');
        }

        $pdo->beginTransaction();
        try {
            $stmtOrder = $pdo->prepare(
                "SELECT id, status, order_type FROM sh_orders
                 WHERE id = :oid AND tenant_id = :tid FOR UPDATE"
            );
            $stmtOrder->execute([':oid' => $orderId, ':tid' => $tenant_id]);
            $order = $stmtOrder->fetch(\PDO::FETCH_ASSOC);
            $stmtOrder->closeCursor();

            if (!$order) {
                $pdo->rollBack();
                tableResponse(false, null, 'Order not found.');
            }

            if (in_array($order['status'], ['completed', 'cancelled'], true)) {
                $pdo->rollBack();
                tableResponse(false, null, "Cannot fire course on {$order['status']} order.");
            }

            $now = date('Y-m-d H:i:s');
            $stmtFire = $pdo->prepare(
                "UPDATE sh_order_lines SET fired_at = :now
                 WHERE order_id = :oid AND course_number = :cn AND fired_at IS NULL"
            );
            $stmtFire->execute([':now' => $now, ':oid' => $orderId, ':cn' => $courseNumber]);
            $firedCount = $stmtFire->rowCount();

            if ($firedCount === 0) {
                $pdo->rollBack();
                tableResponse(false, null, "No unfired items found for course #{$courseNumber}.");
            }

            if (in_array($order['status'], ['pending', 'new', 'accepted'], true)) {
                OrderStateMachine::transitionOrder(
                    $pdo, $orderId, $tenant_id, $user_id, 'preparing', $tenantFlags
                );
            }

            OrderStateMachine::writeLog($pdo, $orderId, $tenant_id, $user_id, 'fire_course', [
                'course_number' => $courseNumber,
                'fired_count'   => $firedCount,
                'fired_at'      => $now,
            ]);

            $pdo->commit();

            tableResponse(true, [
                'order_id'      => $orderId,
                'course_number' => $courseNumber,
                'fired_count'   => $firedCount,
                'fired_at'      => $now,
            ]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[Tables Engine] fire_course: ' . $e->getMessage());
            tableResponse(false, null, 'Fire course failed. Please try again.');
        }
    }

    // =========================================================================
    // CHECK_PAYMENT — Read-only check of split payment balance
    // =========================================================================
    if ($action === 'check_payment') {
        $orderId = inputStr($input, 'order_id');
        if ($orderId === '') {
            tableResponse(false, null, 'order_id required.');
        }

        $payCheck = OrderStateMachine::isFullyPaid($pdo, $orderId, $tenant_id);

        $stmtPayments = $pdo->prepare(
            "SELECT id, method, amount_grosze, tendered_grosze, transaction_id, created_at
             FROM sh_order_payments
             WHERE order_id = :oid AND tenant_id = :tid
             ORDER BY created_at ASC"
        );
        $stmtPayments->execute([':oid' => $orderId, ':tid' => $tenant_id]);

        tableResponse(true, array_merge($payCheck, [
            'payments' => $stmtPayments->fetchAll(\PDO::FETCH_ASSOC),
        ]));
    }

    // =========================================================================
    // COMPLETE_DINE_IN — Full-payment gate → completed transition
    // =========================================================================
    if ($action === 'complete_dine_in') {
        $orderId = inputStr($input, 'order_id');
        if ($orderId === '') {
            tableResponse(false, null, 'order_id required.');
        }

        $pdo->beginTransaction();
        try {
            $result = OrderStateMachine::completeDineIn(
                $pdo, $orderId, $tenant_id, $user_id, $tenantFlags
            );

            if (!$result['success']) {
                $pdo->rollBack();
                tableResponse(false, null, $result['message']);
            }

            $pdo->prepare(
                "UPDATE sh_tables SET physical_status = 'dirty', updated_at = NOW()
                 WHERE id = (SELECT table_id FROM sh_orders WHERE id = :oid AND tenant_id = :tid)
                   AND tenant_id = :tid2"
            )->execute([':oid' => $orderId, ':tid' => $tenant_id, ':tid2' => $tenant_id]);

            $pdo->commit();
            tableResponse(true, [
                'order_id'   => $orderId,
                'old_status' => $result['old_status'],
                'new_status' => $result['new_status'],
            ]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[Tables Engine] complete_dine_in: ' . $e->getMessage());
            tableResponse(false, null, 'Completion failed. Please try again.');
        }
    }

    // =========================================================================
    // GET_FLOOR_STATUS — Tables enriched with active order timing data
    // =========================================================================
    if ($action === 'get_floor_status') {
        $zoneId = isset($input['zone_id']) ? (int)$input['zone_id'] : null;

        $sql = "SELECT t.id, t.zone_id, t.table_number, t.seats, t.shape,
                       t.pos_x, t.pos_y, t.parent_table_id,
                       t.physical_status, t.is_active,
                       z.name AS zone_name,
                       o.id AS order_id, o.order_number, o.status AS order_status,
                       o.grand_total, o.guest_count, o.created_at AS order_created_at,
                       o.updated_at AS order_updated_at,
                       o.waiter_id AS waiter_id,
                       u.name AS waiter_name
                FROM sh_tables t
                LEFT JOIN sh_zones z ON z.id = t.zone_id AND z.tenant_id = t.tenant_id
                LEFT JOIN sh_orders o ON o.table_id = t.id
                    AND o.tenant_id = t.tenant_id
                    AND o.status NOT IN ('completed','cancelled')
                LEFT JOIN sh_users u ON u.id = o.waiter_id
                WHERE t.tenant_id = :tid AND t.is_active = 1";
        $params = [':tid' => $tenant_id];

        if ($zoneId !== null) {
            $sql .= " AND t.zone_id = :zid";
            $params[':zid'] = $zoneId;
        }
        $sql .= " ORDER BY t.table_number ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmtZ = $pdo->prepare(
            "SELECT id, name, display_order, is_active
             FROM sh_zones WHERE tenant_id = :tid ORDER BY display_order ASC"
        );
        $stmtZ->execute([':tid' => $tenant_id]);
        $zones = $stmtZ->fetchAll(\PDO::FETCH_ASSOC);

        // Enrich each table with course info if there's an active order
        $tables = [];
        foreach ($rows as $r) {
            $t = [
                'id'              => $r['id'],
                'zone_id'         => $r['zone_id'],
                'table_number'    => $r['table_number'],
                'seats'           => $r['seats'],
                'shape'           => $r['shape'],
                'pos_x'           => $r['pos_x'],
                'pos_y'           => $r['pos_y'],
                'parent_table_id' => $r['parent_table_id'],
                'physical_status' => $r['physical_status'],
                'zone_name'       => $r['zone_name'],
                'order'           => null,
            ];

            if ($r['order_id'] !== null) {
                // Get course summary
                $stmtCourses = $pdo->prepare(
                    "SELECT course_number,
                            COUNT(*) AS item_count,
                            MIN(fired_at) AS fired_at
                     FROM sh_order_lines
                     WHERE order_id = :oid
                     GROUP BY course_number
                     ORDER BY course_number ASC"
                );
                $stmtCourses->execute([':oid' => $r['order_id']]);
                $courses = $stmtCourses->fetchAll(\PDO::FETCH_ASSOC);

                $nextUnfired = null;
                foreach ($courses as $c) {
                    if ($c['fired_at'] === null) {
                        $nextUnfired = (int)$c['course_number'];
                        break;
                    }
                }

                $t['order'] = [
                    'id'            => $r['order_id'],
                    'order_number'  => $r['order_number'],
                    'status'        => $r['order_status'],
                    'grand_total'   => $r['grand_total'],
                    'guest_count'   => $r['guest_count'],
                    'created_at'    => $r['order_created_at'],
                    'updated_at'    => $r['order_updated_at'],
                    'waiter_id'     => $r['waiter_id'],
                    'waiter_name'   => $r['waiter_name'],
                    'courses'       => $courses,
                    'next_unfired_course' => $nextUnfired,
                ];
            }
            $tables[] = $t;
        }

        tableResponse(true, ['tables' => $tables, 'zones' => $zones]);
    }

    // =========================================================================
    // SAVE_TABLE_POSITION — Persist floor-plan coordinates after drag
    // =========================================================================
    if ($action === 'save_table_position') {
        $tableId = (int)($input['table_id'] ?? 0);
        $posX    = (int)($input['pos_x'] ?? 0);
        $posY    = (int)($input['pos_y'] ?? 0);

        if ($tableId === 0) {
            tableResponse(false, null, 'table_id required.');
        }

        $stmt = $pdo->prepare(
            "UPDATE sh_tables SET pos_x = :x, pos_y = :y, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tid"
        );
        $stmt->execute([':x' => $posX, ':y' => $posY, ':id' => $tableId, ':tid' => $tenant_id]);

        tableResponse(true, ['table_id' => $tableId, 'pos_x' => $posX, 'pos_y' => $posY]);
    }

    // =========================================================================
    // OPEN_TABLE — Mark a free table as occupied + create shell dine-in order
    // =========================================================================
    if ($action === 'open_table') {
        $tableId    = (int)($input['table_id'] ?? 0);
        $guestCount = max(1, (int)($input['guest_count'] ?? 1));

        if ($tableId === 0) {
            tableResponse(false, null, 'table_id required.');
        }

        $pdo->beginTransaction();
        try {
            $stmtT = $pdo->prepare(
                "SELECT id, table_number, physical_status FROM sh_tables
                 WHERE id = :id AND tenant_id = :tid FOR UPDATE"
            );
            $stmtT->execute([':id' => $tableId, ':tid' => $tenant_id]);
            $table = $stmtT->fetch(\PDO::FETCH_ASSOC);
            $stmtT->closeCursor();

            if (!$table) {
                $pdo->rollBack();
                tableResponse(false, null, 'Table not found.');
            }
            if ($table['physical_status'] !== 'free') {
                $pdo->rollBack();
                tableResponse(false, null, "Table {$table['table_number']} is not free (current: {$table['physical_status']}).");
            }

            $orderId = generateUuid();

            // Atomic order-number sequence (same pattern as POS/checkout)
            $stmtSeq = $pdo->prepare(
                "INSERT INTO sh_order_sequences (tenant_id, `date`, seq)
                 VALUES (:tid, CURDATE(), LAST_INSERT_ID(1))
                 ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)"
            );
            $stmtSeq->execute([':tid' => $tenant_id]);
            $seq = (int)$pdo->lastInsertId();
            $orderNumber = sprintf('D/%s/%04d', date('Ymd'), $seq);

            $stmtO = $pdo->prepare(
                "INSERT INTO sh_orders
                    (id, tenant_id, order_number, channel, order_type, source,
                     table_id, waiter_id, guest_count,
                     status, payment_status, created_at)
                 VALUES
                    (:id, :tid, :onum, 'dine_in', 'dine_in', 'pos',
                     :tbl, :uid, :gc,
                     'pending', 'unpaid', NOW())"
            );
            $stmtO->execute([
                ':id'   => $orderId,
                ':tid'  => $tenant_id,
                ':onum' => $orderNumber,
                ':tbl'  => $tableId,
                ':uid'  => $user_id,
                ':gc'   => $guestCount,
            ]);

            $pdo->prepare(
                "UPDATE sh_tables SET physical_status = 'occupied', updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tid"
            )->execute([':id' => $tableId, ':tid' => $tenant_id]);

            OrderStateMachine::writeLog($pdo, $orderId, $tenant_id, $user_id, 'open_table', [
                'table_id'     => $tableId,
                'table_number' => $table['table_number'],
                'guest_count'  => $guestCount,
            ]);

            $pdo->commit();
            tableResponse(true, [
                'table_id'     => $tableId,
                'order_id'     => $orderId,
                'order_number' => $orderNumber,
                'guest_count'  => $guestCount,
            ]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[Tables Engine] open_table: ' . $e->getMessage());
            tableResponse(false, null, 'Failed to open table. ' . $e->getMessage());
        }
    }

    // =========================================================================
    // CREATE_TABLE — Add a new table to the floor plan (Edit Mode)
    // =========================================================================
    if ($action === 'create_table') {
        $tableNumber = inputStr($input, 'table_number');
        $seats       = max(1, (int)($input['seats'] ?? 4));
        $shape       = inputStr($input, 'shape') ?: 'square';
        $posX        = (int)($input['pos_x'] ?? 50);
        $posY        = (int)($input['pos_y'] ?? 50);
        $zoneId      = isset($input['zone_id']) ? (int)$input['zone_id'] : null;

        if ($tableNumber === '') {
            tableResponse(false, null, 'table_number required.');
        }

        $validShapes = ['square', 'round', 'rectangle', 'bar', 'wall', 'counter', 'pillar'];
        if (!in_array($shape, $validShapes, true)) {
            $shape = 'square';
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO sh_tables
                    (tenant_id, zone_id, table_number, seats, shape, pos_x, pos_y,
                     physical_status, is_active, created_at)
                 VALUES (:tid, :zid, :num, :s, :sh, :x, :y, 'free', 1, NOW())"
            );
            $stmt->execute([
                ':tid' => $tenant_id,
                ':zid' => $zoneId,
                ':num' => $tableNumber,
                ':s'   => $seats,
                ':sh'  => $shape,
                ':x'   => $posX,
                ':y'   => $posY,
            ]);
            $newId = (int)$pdo->lastInsertId();

            tableResponse(true, [
                'id'           => $newId,
                'table_number' => $tableNumber,
                'seats'        => $seats,
                'shape'        => $shape,
                'pos_x'        => $posX,
                'pos_y'        => $posY,
            ]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                tableResponse(false, null, "Table '{$tableNumber}' already exists.");
            }
            error_log('[Tables Engine] create_table: ' . $e->getMessage());
            tableResponse(false, null, 'Failed to create table.');
        }
    }

    // =========================================================================
    // SAVE_LAYOUT — Batch-save multiple table positions in one transaction
    // =========================================================================
    if ($action === 'save_layout') {
        $positions = $input['positions'] ?? [];
        if (!is_array($positions) || count($positions) === 0) {
            tableResponse(false, null, 'positions[] array required.');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "UPDATE sh_tables SET pos_x = :x, pos_y = :y, updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tid"
            );
            $updated = 0;
            foreach ($positions as $p) {
                $tid = (int)($p['table_id'] ?? 0);
                if ($tid === 0) continue;
                $stmt->execute([
                    ':x'   => (int)($p['pos_x'] ?? 0),
                    ':y'   => (int)($p['pos_y'] ?? 0),
                    ':id'  => $tid,
                    ':tid' => $tenant_id,
                ]);
                $updated += $stmt->rowCount();
            }
            $pdo->commit();
            tableResponse(true, ['updated' => $updated]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[Tables Engine] save_layout: ' . $e->getMessage());
            tableResponse(false, null, 'Save layout failed.');
        }
    }

    // =========================================================================
    // DELETE_TABLE — Remove a table from the floor plan (Edit Mode)
    // =========================================================================
    if ($action === 'delete_table') {
        $tableId = (int)($input['table_id'] ?? 0);
        if ($tableId === 0) {
            tableResponse(false, null, 'table_id required.');
        }

        $stmtCheck = $pdo->prepare(
            "SELECT COUNT(*) FROM sh_orders
             WHERE table_id = :tid2 AND tenant_id = :tid
               AND status NOT IN ('completed','cancelled')"
        );
        $stmtCheck->execute([':tid2' => $tableId, ':tid' => $tenant_id]);
        if ((int)$stmtCheck->fetchColumn() > 0) {
            tableResponse(false, null, 'Cannot delete table with active orders.');
        }

        $stmt = $pdo->prepare(
            "DELETE FROM sh_tables WHERE id = :id AND tenant_id = :tid"
        );
        $stmt->execute([':id' => $tableId, ':tid' => $tenant_id]);

        tableResponse(true, ['deleted_table_id' => $tableId]);
    }

    tableResponse(false, null, 'Unknown action: ' . $action);

} catch (\Throwable $e) {
    http_response_code(500);
    error_log('[Tables Engine] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    tableResponse(false, null, 'Internal server error.');
}
