<?php
// =============================================================================
// SliceHub Enterprise — Driver Cashbox & End-of-Shift Reconciliation
// POST /api/delivery/reconcile.php
//
// Closes a driver's active shift by comparing counted cash against the
// expected total derived from completed delivery orders.
//
// NIGHT-SHIFT SAFE: Aggregation uses the shift's `created_at` timestamp
// as the lower bound — NOT CURDATE() — so shifts crossing midnight
// capture all their orders correctly.
//
// Schema: sh_driver_shifts, sh_orders, sh_users
// =============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    // =========================================================================
    // 1. PARSE & VALIDATE INPUT
    // =========================================================================
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
        exit;
    }

    $driverUserId = isset($input['driver_user_id']) ? trim($input['driver_user_id']) : '';
    $countedRaw   = $input['counted_cash'] ?? null;

    if ($driverUserId === '' || $countedRaw === null || !is_numeric($countedRaw)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'driver_user_id (string) and counted_cash (numeric) are required.',
        ]);
        exit;
    }

    $countedGrosze = (int)round((float)$countedRaw * 100);

    // =========================================================================
    // 2. STATE RETRIEVAL
    // =========================================================================

    // — 2a. Fetch active shift (strict — no mock fallback) ————————————————————
    $stmtShift = $pdo->prepare(
        "SELECT id, driver_id, initial_cash, created_at
         FROM sh_driver_shifts
         WHERE driver_id = :did AND tenant_id = :tid AND status = 'active'
         LIMIT 1"
    );
    $stmtShift->execute([':did' => $driverUserId, ':tid' => $tenant_id]);
    $activeShift = $stmtShift->fetch(PDO::FETCH_ASSOC);

    if (!$activeShift) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No active shift found to reconcile.',
        ]);
        exit;
    }

    $shiftId          = $activeShift['id'];
    $shiftStart       = $activeShift['created_at'];
    $initialCashGrosze = (int)$activeShift['initial_cash'];

    // =========================================================================
    // 3. NIGHT-SHIFT SAFE AGGREGATION (bound to shift's created_at, not CURDATE)
    //
    // SETTLEMENT-SAFE: Uses sh_order_payments.user_id to determine what
    // the driver ACTUALLY collected, NOT sh_orders.payment_status which
    // cannot distinguish POS-paid from driver-collected.
    // =========================================================================

    // Cash the driver physically collected (authoritative source)
    $stmtCashAgg = $pdo->prepare(
        "SELECT COALESCE(SUM(p.amount_grosze), 0) AS cash_orders_grosze
         FROM sh_order_payments p
         JOIN sh_orders o ON o.id = p.order_id AND o.tenant_id = p.tenant_id
         WHERE p.user_id   = :did
           AND p.tenant_id = :tid
           AND p.method    = 'cash'
           AND o.order_type = 'delivery'
           AND o.status     = 'completed'
           AND p.created_at >= :shift_start"
    );
    $stmtCashAgg->execute([
        ':did'         => $driverUserId,
        ':tid'         => $tenant_id,
        ':shift_start' => $shiftStart,
    ]);
    $cashOrdersGrosze = (int)$stmtCashAgg->fetchColumn();
    $stmtCashAgg->closeCursor();

    // Cash tips (still on sh_orders since tip_amount is on the order header)
    $stmtTips = $pdo->prepare(
        "SELECT COALESCE(SUM(o.tip_amount), 0) AS cash_tips_grosze
         FROM sh_orders o
         JOIN sh_order_payments p ON p.order_id = o.id AND p.tenant_id = o.tenant_id AND p.user_id = :did2
         WHERE o.driver_id  = :did
           AND o.tenant_id  = :tid
           AND o.order_type = 'delivery'
           AND o.status     = 'completed'
           AND p.method     = 'cash'
           AND o.created_at >= :shift_start"
    );
    $stmtTips->execute([
        ':did'         => $driverUserId,
        ':did2'        => $driverUserId,
        ':tid'         => $tenant_id,
        ':shift_start' => $shiftStart,
    ]);
    $cashTipsGrosze = (int)$stmtTips->fetchColumn();
    $stmtTips->closeCursor();

    // Total completed deliveries by this driver during shift
    $stmtCount = $pdo->prepare(
        "SELECT COUNT(*) AS completed_deliveries
         FROM sh_orders
         WHERE driver_id  = :did
           AND tenant_id  = :tid
           AND order_type = 'delivery'
           AND status     = 'completed'
           AND created_at >= :shift_start"
    );
    $stmtCount->execute([
        ':did'         => $driverUserId,
        ':tid'         => $tenant_id,
        ':shift_start' => $shiftStart,
    ]);
    $completedDeliveries = (int)$stmtCount->fetchColumn();
    $stmtCount->closeCursor();

    // Distinct courses completed during this shift
    $stmtCourses = $pdo->prepare(
        "SELECT DISTINCT course_id
         FROM sh_orders
         WHERE driver_id   = :did
           AND tenant_id   = :tid
           AND order_type   = 'delivery'
           AND status       = 'completed'
           AND created_at  >= :shift_start
           AND course_id   IS NOT NULL"
    );
    $stmtCourses->execute([
        ':did'         => $driverUserId,
        ':tid'         => $tenant_id,
        ':shift_start' => $shiftStart,
    ]);
    $coursesCompleted = $stmtCourses->fetchAll(PDO::FETCH_COLUMN, 0);

    // =========================================================================
    // 4. RECONCILIATION MATH (pure grosze — no floats)
    // =========================================================================
    $expectedGrosze = $initialCashGrosze + $cashOrdersGrosze + $cashTipsGrosze;
    $varianceGrosze = $countedGrosze - $expectedGrosze;
    $varianceFlag   = abs($varianceGrosze) > 500 ? 'REVIEW_REQUIRED' : 'OK';

    // =========================================================================
    // 5. ATOMIC STATE UPDATE
    // =========================================================================
    $pdo->beginTransaction();

    try {
        // — 5a. Close the shift ———————————————————————————————————————————————
        $stmtClose = $pdo->prepare(
            "UPDATE sh_driver_shifts
             SET counted_cash = :counted, variance = :var, status = 'closed'
             WHERE id = :sid AND tenant_id = :tid AND status = 'active'"
        );
        $stmtClose->execute([
            ':counted' => $countedGrosze,
            ':var'     => $varianceGrosze,
            ':sid'     => $shiftId,
            ':tid'     => $tenant_id,
        ]);

        if ($stmtClose->rowCount() === 0) {
            throw new RuntimeException(
                'Shift could not be closed — concurrent reconciliation detected.'
            );
        }

        // — 5b. Release driver back to available —————————————————————————————
        $stmtDriverAvail = $pdo->prepare(
            "UPDATE sh_drivers SET status = 'available'
             WHERE user_id = :did AND tenant_id = :tid"
        );
        $stmtDriverAvail->execute([':did' => $driverUserId, ':tid' => $tenant_id]);

        // — 5c. COMMIT ———————————————————————————————————————————————————————
        $pdo->commit();

    } catch (Throwable $txErr) {
        $pdo->rollBack();
        throw $txErr;
    }

    // =========================================================================
    // 6. SUCCESS RESPONSE
    // =========================================================================
    $fmtMoney = fn(int $g): string => number_format($g / 100, 2, '.', '');

    echo json_encode([
        'success' => true,
        'data'    => [
            'shift_id'             => $shiftId,
            'driver_id'            => $driverUserId,
            'initial_cash'         => $fmtMoney($initialCashGrosze),
            'cash_from_orders'     => $fmtMoney($cashOrdersGrosze),
            'cash_from_tips'       => $fmtMoney($cashTipsGrosze),
            'expected_cash'        => $fmtMoney($expectedGrosze),
            'counted_cash'         => $fmtMoney($countedGrosze),
            'variance'             => $fmtMoney($varianceGrosze),
            'variance_flag'        => $varianceFlag,
            'completed_deliveries' => $completedDeliveries,
            'courses_completed'    => $coursesCompleted,
            'driver_status'        => 'available',
        ],
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
    error_log('[Reconcile] PDOException: ' . $e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[Reconcile] ' . $e->getMessage());
}

exit;
