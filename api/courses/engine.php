<?php
declare(strict_types=1);

// =============================================================================
// SliceHub Enterprise — Courses (Delivery Logistics) Engine v3
// POST /api/courses/engine.php   { action: '...', ... }
//
// 3-Pillar State Machine (enforced by core/OrderStateMachine.php):
//   status:          new | accepted | pending | preparing | ready | completed | cancelled
//   payment_status:  to_pay | online_unpaid | cash | card | online_paid
//   delivery_status: unassigned | in_delivery | delivered
//
// All money in integer grosze. All IDs tenant-scoped.
//
// Feature Flag Integration Points (see OrderStateMachine.php for map):
//   [FF-001] skip_kitchen      → pending→ready permitted in update_order_status
//   [FF-002] skip_dispatch     → dispatch action bypassed, use fast_complete
//   [FF-003] auto_complete     → pending→completed in update_order_status
//   [FF-007] skip_payment_lock → deliver_order skips isPaid() check
// =============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function coursesResponse(bool $ok, $data = null, ?string $msg = null): void
{
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/** @return bool  True if payment_status means "paid" */
function isPaid(string $ps): bool
{
    return in_array($ps, ['cash', 'card', 'online_paid'], true);
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/../../core/OrderStateMachine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $raw    = file_get_contents('php://input');
    $input  = json_decode($raw, true);
    $action = $input['action'] ?? '';

    // [FF-HOOK] Load tenant feature flags once per request.
    $tenantFlags = OrderStateMachine::loadTenantFlags($pdo, $tenant_id);

    // =========================================================================
    // UUID v4 helper
    // =========================================================================
    $uuid4 = function (): string {
        $d    = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    };

    // =========================================================================
    // Auto-migration: ensure delivery_status + cancellation_reason columns exist
    // =========================================================================
    $hasDeliveryStatus = false;
    try {
        $pdo->query("SELECT delivery_status FROM sh_orders LIMIT 0");
        $hasDeliveryStatus = true;
    } catch (\Throwable $e) {
        $pdo->exec("ALTER TABLE sh_orders ADD COLUMN delivery_status VARCHAR(32) NULL DEFAULT NULL");
        try { $pdo->exec("ALTER TABLE sh_orders ADD COLUMN cancellation_reason TEXT NULL"); } catch (\Throwable $ignore) {}

        // Data migration — one-time, strictly tenant-scoped.
        // UWAGA: NIE zmieniamy kanonicznych statusów new/accepted. Canonical status flow
        // (Faza 6.2 repair): new → accepted → preparing → ready → completed (+ cancelled).
        // delivery_status jest ORTOGONALNY: unassigned | queued | in_delivery | delivered.
        $migTid = (int)$tenant_id;
        $pdo->exec("UPDATE sh_orders SET delivery_status='in_delivery', status='ready' WHERE status='in_delivery' AND tenant_id = {$migTid}");
        $pdo->exec("UPDATE sh_orders SET delivery_status='delivered' WHERE status='completed' AND order_type='delivery' AND driver_id IS NOT NULL AND delivery_status IS NULL AND tenant_id = {$migTid}");
        $pdo->exec("UPDATE sh_orders SET delivery_status='unassigned' WHERE order_type='delivery' AND status NOT IN ('completed','cancelled') AND delivery_status IS NULL AND tenant_id = {$migTid}");
        // [REMOVED 2026-04-18] destrukcyjny UPDATE status='pending' WHERE status IN ('new','accepted') —
        // konflikt z kanonicznym słownikiem (zjadał nowe zamówienia z guest_checkout/KDS).

        // Payment status migration — tenant-scoped
        $pdo->exec("UPDATE sh_orders SET payment_status='to_pay' WHERE payment_status='unpaid' AND (payment_method IS NULL OR payment_method NOT IN ('online')) AND tenant_id = {$migTid}");
        $pdo->exec("UPDATE sh_orders SET payment_status='online_unpaid' WHERE payment_status='unpaid' AND payment_method='online' AND tenant_id = {$migTid}");
        $pdo->exec("UPDATE sh_orders SET payment_status='cash' WHERE payment_status='paid' AND payment_method='cash' AND tenant_id = {$migTid}");
        $pdo->exec("UPDATE sh_orders SET payment_status='card' WHERE payment_status='paid' AND payment_method='card' AND tenant_id = {$migTid}");
        $pdo->exec("UPDATE sh_orders SET payment_status='online_paid' WHERE payment_status='paid' AND payment_method='online' AND tenant_id = {$migTid}");
        $pdo->exec("UPDATE sh_orders SET payment_status='cash' WHERE payment_status='paid' AND tenant_id = {$migTid}");

        try { $pdo->exec("CREATE INDEX idx_orders_delivery_status ON sh_orders (tenant_id, delivery_status)"); } catch (\Throwable $ignore) {}
        $hasDeliveryStatus = true;
    }

    // Backfill: ensure delivery orders have delivery_status set (handles seed data / manual inserts)
    if ($hasDeliveryStatus) {
        try {
            $pdo->exec("UPDATE sh_orders SET delivery_status='unassigned' WHERE order_type='delivery' AND status NOT IN ('completed','cancelled') AND delivery_status IS NULL AND tenant_id = " . (int)$tenant_id);
        } catch (\Throwable $ignore) {}
    }

    // Ensure sh_driver_locations exists
    try {
        $pdo->query("SELECT 1 FROM sh_driver_locations LIMIT 0")->closeCursor();
    } catch (\Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sh_driver_locations (
            driver_id   BIGINT UNSIGNED NOT NULL,
            tenant_id   INT UNSIGNED NOT NULL,
            lat         DECIMAL(10,7) NOT NULL,
            lng         DECIMAL(10,7) NOT NULL,
            heading     SMALLINT NULL,
            speed_kmh   DECIMAL(5,1) NULL,
            accuracy_m  DECIMAL(6,1) NULL,
            updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (tenant_id, driver_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // Auto-migration: driver_action_type on sh_order_lines
    try {
        $pdo->query("SELECT driver_action_type FROM sh_order_lines LIMIT 0");
    } catch (\Throwable $e) {
        try {
            $pdo->exec("ALTER TABLE sh_order_lines ADD COLUMN driver_action_type ENUM('none','pack_cold','pack_separate','check_id') NOT NULL DEFAULT 'none' AFTER comment");
        } catch (\Throwable $ignore) {}
    }

    // Auto-migration: allow NULL driver_id in sh_dispatch_log (for unassigned courses)
    try {
        $colCheck = $pdo->query("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sh_dispatch_log' AND COLUMN_NAME = 'driver_id'")->fetchColumn();
        if ($colCheck === 'NO') {
            $pdo->exec("ALTER TABLE sh_dispatch_log MODIFY COLUMN driver_id VARCHAR(64) NULL DEFAULT NULL");
        }
    } catch (\Throwable $ignore) {}

    // Auto-migration: sh_order_payments.user_id — tracks WHO collected the payment
    try {
        $pdo->query("SELECT user_id FROM sh_order_payments LIMIT 0");
    } catch (\Throwable $e) {
        try {
            $pdo->exec("ALTER TABLE sh_order_payments ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER tenant_id");
            $pdo->exec("CREATE INDEX idx_pay_user ON sh_order_payments (tenant_id, user_id, method)");
        } catch (\Throwable $ignore) {}
    }

    // =========================================================================
    // ACTION: get_dashboard
    // =========================================================================
    if ($action === 'get_dashboard') {

        // Auto-heal: reset "busy" drivers with zero active delivery orders
        $pdo->exec(
            "UPDATE sh_drivers d SET d.status = 'available'
             WHERE d.tenant_id = {$tenant_id} AND d.status = 'busy'
               AND NOT EXISTS (
                   SELECT 1 FROM sh_orders o
                   WHERE o.driver_id = d.user_id AND o.tenant_id = d.tenant_id
                     AND o.delivery_status = 'in_delivery'
               )"
        );

        $stmtOrders = $pdo->prepare(
            "SELECT o.id, o.order_number, o.status, o.order_type, o.channel, o.source,
                    o.grand_total, o.subtotal, o.delivery_fee, o.discount_amount,
                    o.payment_status, o.payment_method, o.tip_amount,
                    o.customer_name, o.customer_phone, o.delivery_address,
                    o.lat, o.lng, o.promised_time,
                    o.driver_id, o.course_id, o.stop_number,
                    o.delivery_status,
                    o.created_at, o.updated_at
             FROM sh_orders o
             WHERE o.tenant_id = :tid
               AND o.order_type = 'delivery'
               AND o.status NOT IN ('completed','cancelled')
             ORDER BY o.created_at ASC"
        );
        $stmtOrders->execute([':tid' => $tenant_id]);
        $orders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

        // Also include in_delivery orders (status=ready but delivery_status=in_delivery)
        // The query above already covers them since status is NOT completed/cancelled

        $orderIds = array_column($orders, 'id');
        $linesMap = [];
        if (count($orderIds) > 0) {
            $ph = []; $lp = [];
            foreach ($orderIds as $i => $oid) { $k = ":oid{$i}"; $ph[] = $k; $lp[$k] = $oid; }
            $stmtLines = $pdo->prepare(
                "SELECT order_id, snapshot_name, quantity, unit_price, line_total, comment, modifiers_json
                 FROM sh_order_lines WHERE order_id IN (" . implode(',', $ph) . ")"
            );
            $stmtLines->execute($lp);
            foreach ($stmtLines->fetchAll(PDO::FETCH_ASSOC) as $line) {
                $linesMap[$line['order_id']][] = $line;
            }
        }
        foreach ($orders as &$o) { $o['lines'] = $linesMap[$o['id']] ?? []; }
        unset($o);

        $stmtDrivers = $pdo->prepare(
            "SELECT u.id, u.first_name, u.last_name, u.name, d.status AS driver_status,
                    dl.lat AS loc_lat, dl.lng AS loc_lng, dl.updated_at AS loc_updated,
                    ds.shift_id, ds.initial_cash, ds.shift_status,
                    COALESCE(cash_agg.cash_collected, 0) AS cash_collected_today
             FROM sh_users u
             JOIN sh_drivers d ON d.user_id = u.id AND d.tenant_id = u.tenant_id
             LEFT JOIN sh_driver_locations dl ON dl.driver_id = u.id AND dl.tenant_id = u.tenant_id
             LEFT JOIN (
                 SELECT driver_id,
                        MAX(id)           AS shift_id,
                        MAX(initial_cash) AS initial_cash,
                        'active'          AS shift_status
                 FROM sh_driver_shifts
                 WHERE tenant_id = :tid3 AND status = 'active'
                 GROUP BY driver_id
             ) ds ON ds.driver_id = u.id
             LEFT JOIN (
                 SELECT p.user_id AS driver_id, SUM(p.amount_grosze) AS cash_collected
                 FROM sh_order_payments p
                 JOIN sh_orders o ON o.id = p.order_id AND o.tenant_id = p.tenant_id
                 WHERE p.tenant_id = :tid2
                   AND p.method = 'cash'
                   AND o.order_type = 'delivery' AND o.status = 'completed'
                   AND DATE(p.created_at) = CURDATE()
                 GROUP BY p.user_id
             ) cash_agg ON cash_agg.driver_id = u.id
             WHERE u.tenant_id = :tid AND u.role = 'driver' AND u.is_deleted = 0"
        );
        $stmtDrivers->execute([':tid' => $tenant_id, ':tid2' => $tenant_id, ':tid3' => $tenant_id]);
        $drivers = $stmtDrivers->fetchAll(PDO::FETCH_ASSOC);

        $stmtCourses = $pdo->prepare(
            "SELECT dl.course_id, dl.driver_id, dl.order_ids_json, dl.dispatched_at,
                    u.first_name, u.last_name
             FROM sh_dispatch_log dl
             LEFT JOIN sh_users u ON u.id = dl.driver_id
             WHERE dl.tenant_id = :tid
               AND dl.course_id IN (
                   SELECT DISTINCT course_id FROM sh_orders
                   WHERE tenant_id = :tid2 AND delivery_status IN ('in_delivery','queued') AND course_id IS NOT NULL
               )
             ORDER BY dl.dispatched_at DESC"
        );
        $stmtCourses->execute([':tid' => $tenant_id, ':tid2' => $tenant_id]);
        $courses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);

        coursesResponse(true, [
            'orders'  => $orders,
            'drivers' => $drivers,
            'courses' => $courses,
        ]);
    }

    // =========================================================================
    // ACTION: dispatch — Create a course (K-number) and assign orders to driver
    //
    // READY LOCK: Only status='ready' orders can be dispatched.
    //
    // [FF-HOOK] HARD BLOCKER for state skipping:
    // The WHERE clause below enforces status='ready'. When 'skip_kitchen'
    // is active, the POS/KDS must transition orders to 'ready' (which the
    // state machine now permits from 'pending' directly). The dispatch
    // itself does NOT need to change — the gate is in the status transition.
    //
    // [FF-002] When 'skip_dispatch' is active, this entire action is
    // bypassed. The frontend calls fast_complete directly on ready orders
    // instead of routing them through dispatch → deliver_order.
    // =========================================================================
    if ($action === 'dispatch') {
        $driverId = isset($input['driver_id']) ? (string)$input['driver_id'] : '';
        $orderIds = $input['order_ids'] ?? [];

        if ($driverId === '' || !is_array($orderIds) || count($orderIds) === 0) {
            http_response_code(400);
            coursesResponse(false, null, 'driver_id and order_ids[] are required.');
        }
        $orderIds = array_values(array_unique($orderIds));
        $forceNew = !empty($input['force_new']);

        $stmtD = $pdo->prepare(
            "SELECT u.id, u.first_name, u.name, d.status AS driver_status
             FROM sh_users u
             JOIN sh_drivers d ON d.user_id = u.id AND d.tenant_id = u.tenant_id
             WHERE u.id = :did AND u.tenant_id = :tid AND u.role = 'driver'"
        );
        $stmtD->execute([':did' => $driverId, ':tid' => $tenant_id]);
        $driverRow = $stmtD->fetch(PDO::FETCH_ASSOC);
        $stmtD->closeCursor();

        if (!$driverRow) {
            http_response_code(404);
            coursesResponse(false, null, 'Driver not found.');
        }

        if ($driverRow['driver_status'] !== 'available' && !$forceNew) {
            $stmtActive = $pdo->prepare(
                "SELECT DISTINCT course_id FROM sh_orders
                 WHERE driver_id = :did AND tenant_id = :tid AND delivery_status = 'in_delivery' AND course_id IS NOT NULL
                 ORDER BY course_id DESC LIMIT 1"
            );
            $stmtActive->execute([':did' => $driverId, ':tid' => $tenant_id]);
            $activeRow = $stmtActive->fetch(PDO::FETCH_ASSOC);
            $stmtActive->closeCursor();

            http_response_code(409);
            coursesResponse(false, [
                'reason'           => 'driver_busy',
                'driver_name'      => $driverRow['first_name'] ?: $driverRow['name'] ?: 'Kierowca',
                'active_course_id' => $activeRow ? $activeRow['course_id'] : null,
            ], 'Kierowca jest w trasie.');
        }

        // READY LOCK — validate orders
        $ph = []; $prm = [':tid' => $tenant_id];
        foreach ($orderIds as $i => $oid) { $k = ":o{$i}"; $ph[] = $k; $prm[$k] = $oid; }
        $stmtO = $pdo->prepare(
            "SELECT id, status, order_type, delivery_address, delivery_status, lat, lng
             FROM sh_orders WHERE id IN (" . implode(',', $ph) . ") AND tenant_id = :tid"
        );
        $stmtO->execute($prm);
        $fetched = $stmtO->fetchAll(PDO::FETCH_ASSOC);

        if (count($fetched) !== count($orderIds)) {
            http_response_code(404);
            coursesResponse(false, null, 'One or more orders not found.');
        }
        foreach ($fetched as $fo) {
            if ($fo['order_type'] !== 'delivery') {
                http_response_code(409);
                coursesResponse(false, null, "Zamówienie {$fo['id']} nie jest dostawą.");
            }
            if ($fo['status'] !== 'ready') {
                http_response_code(409);
                coursesResponse(false, null, "Zamówienie {$fo['id']} nie jest gotowe (status: {$fo['status']}). Tylko gotowe zamówienia mogą być wysłane.");
            }
            if ($fo['delivery_status'] === 'in_delivery') {
                http_response_code(409);
                coursesResponse(false, null, "Zamówienie {$fo['id']} jest już w dostawie.");
            }
        }
        $addrMap = array_column($fetched, 'delivery_address', 'id');

        $pdo->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');

            $pdo->prepare(
                "INSERT INTO sh_course_sequences (tenant_id, date, seq)
                 VALUES (:tid, CURDATE(), 1)
                 ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)"
            )->execute([':tid' => $tenant_id]);
            $seq = (int)$pdo->lastInsertId();
            $courseId = 'K' . $seq;

            $stmtUp = $pdo->prepare(
                "UPDATE sh_orders SET delivery_status='in_delivery', driver_id=:did, course_id=:cid, stop_number=:stop, updated_at=:now
                 WHERE id=:oid AND tenant_id=:tid AND status='ready' AND delivery_status='unassigned' AND order_type='delivery'"
            );
            $stmtAudit = $pdo->prepare(
                "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
                 VALUES (:oid, :uid, 'unassigned', 'in_delivery', :now)"
            );

            $stops = [];
            foreach ($orderIds as $idx => $oid) {
                $stop = 'L' . ($idx + 1);
                $stmtUp->execute([':did'=>$driverId, ':cid'=>$courseId, ':stop'=>$stop, ':oid'=>$oid, ':tid'=>$tenant_id, ':now'=>$now]);
                if ($stmtUp->rowCount() === 0) {
                    throw new RuntimeException("Concurrent status change on order {$oid}.");
                }
                $stmtAudit->execute([':oid'=>$oid, ':uid'=>$user_id, ':now'=>$now]);
                $stops[] = ['order_id'=>$oid, 'stop'=>$stop, 'address'=>$addrMap[$oid] ?? null];
            }

            $pdo->prepare("UPDATE sh_drivers SET status='busy' WHERE user_id=:did AND tenant_id=:tid")
                ->execute([':did'=>$driverId, ':tid'=>$tenant_id]);

            $pdo->prepare(
                "INSERT INTO sh_dispatch_log (id, tenant_id, course_id, driver_id, order_ids_json, dispatched_by, dispatched_at)
                 VALUES (:id, :tid, :cid, :did, :oj, :uid, NOW())"
            )->execute([':id'=>$uuid4(), ':tid'=>$tenant_id, ':cid'=>$courseId, ':did'=>$driverId, ':oj'=>json_encode($orderIds), ':uid'=>$user_id]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        coursesResponse(true, ['course_id'=>$courseId, 'stops'=>$stops, 'driver_status'=>'busy']);
    }

    // =========================================================================
    // ACTION: create_course — Create a K-number course WITHOUT a driver
    // Orders get delivery_status='queued', driver_id remains NULL.
    // =========================================================================
    if ($action === 'create_course') {
        $orderIds = $input['order_ids'] ?? [];

        if (!is_array($orderIds) || count($orderIds) === 0) {
            http_response_code(400);
            coursesResponse(false, null, 'order_ids[] required.');
        }
        $orderIds = array_values(array_unique($orderIds));

        // READY LOCK — validate orders
        $ph = []; $prm = [':tid' => $tenant_id];
        foreach ($orderIds as $i => $oid) { $k = ":o{$i}"; $ph[] = $k; $prm[$k] = $oid; }
        $stmtO = $pdo->prepare(
            "SELECT id, status, order_type, delivery_address, delivery_status, lat, lng
             FROM sh_orders WHERE id IN (" . implode(',', $ph) . ") AND tenant_id = :tid"
        );
        $stmtO->execute($prm);
        $fetched = $stmtO->fetchAll(PDO::FETCH_ASSOC);

        if (count($fetched) !== count($orderIds)) {
            http_response_code(404);
            coursesResponse(false, null, 'One or more orders not found.');
        }
        foreach ($fetched as $fo) {
            if ($fo['order_type'] !== 'delivery') {
                http_response_code(409);
                coursesResponse(false, null, "Zamówienie {$fo['id']} nie jest dostawą.");
            }
            if ($fo['status'] !== 'ready') {
                http_response_code(409);
                coursesResponse(false, null, "Zamówienie {$fo['id']} nie jest gotowe (status: {$fo['status']}). Tylko gotowe zamówienia mogą być wysłane.");
            }
            if ($fo['delivery_status'] === 'in_delivery') {
                http_response_code(409);
                coursesResponse(false, null, "Zamówienie {$fo['id']} jest już w dostawie.");
            }
            if ($fo['delivery_status'] === 'queued') {
                http_response_code(409);
                coursesResponse(false, null, "Zamówienie {$fo['id']} jest już przypisane do kursu.");
            }
        }
        $addrMap = array_column($fetched, 'delivery_address', 'id');

        $pdo->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');

            $pdo->prepare(
                "INSERT INTO sh_course_sequences (tenant_id, date, seq)
                 VALUES (:tid, CURDATE(), 1)
                 ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)"
            )->execute([':tid' => $tenant_id]);
            $seq = (int)$pdo->lastInsertId();
            $courseId = 'K' . $seq;

            $stmtUp = $pdo->prepare(
                "UPDATE sh_orders SET delivery_status='queued', course_id=:cid, stop_number=:stop, updated_at=:now
                 WHERE id=:oid AND tenant_id=:tid AND status='ready' AND delivery_status='unassigned' AND order_type='delivery'"
            );
            $stmtAudit = $pdo->prepare(
                "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
                 VALUES (:oid, :uid, 'unassigned', 'queued', :now)"
            );

            $stops = [];
            foreach ($orderIds as $idx => $oid) {
                $stop = 'L' . ($idx + 1);
                $stmtUp->execute([':cid'=>$courseId, ':stop'=>$stop, ':oid'=>$oid, ':tid'=>$tenant_id, ':now'=>$now]);
                if ($stmtUp->rowCount() === 0) {
                    throw new RuntimeException("Concurrent status change on order {$oid}.");
                }
                $stmtAudit->execute([':oid'=>$oid, ':uid'=>$user_id, ':now'=>$now]);
                $stops[] = ['order_id'=>$oid, 'stop'=>$stop, 'address'=>$addrMap[$oid] ?? null];
            }

            $pdo->prepare(
                "INSERT INTO sh_dispatch_log (id, tenant_id, course_id, driver_id, order_ids_json, dispatched_by, dispatched_at)
                 VALUES (:id, :tid, :cid, NULL, :oj, :uid, NOW())"
            )->execute([':id'=>$uuid4(), ':tid'=>$tenant_id, ':cid'=>$courseId, ':oj'=>json_encode($orderIds), ':uid'=>$user_id]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        coursesResponse(true, ['course_id'=>$courseId, 'stops'=>$stops, 'driver_id'=>null]);
    }

    // =========================================================================
    // ACTION: assign_driver_to_course — Assign a driver to an existing
    // unassigned (queued) course. Transitions orders queued → in_delivery.
    // =========================================================================
    if ($action === 'assign_driver_to_course') {
        $courseId  = (string)($input['course_id'] ?? '');
        $driverId  = isset($input['driver_id']) ? (string)$input['driver_id'] : '';

        if ($courseId === '' || $driverId === '') {
            http_response_code(400);
            coursesResponse(false, null, 'course_id and driver_id required.');
        }

        // Validate driver
        $stmtD = $pdo->prepare(
            "SELECT u.id, u.first_name, u.name, d.status AS driver_status
             FROM sh_users u
             JOIN sh_drivers d ON d.user_id = u.id AND d.tenant_id = u.tenant_id
             WHERE u.id = :did AND u.tenant_id = :tid AND u.role = 'driver'"
        );
        $stmtD->execute([':did' => $driverId, ':tid' => $tenant_id]);
        $driverRow = $stmtD->fetch(PDO::FETCH_ASSOC);
        $stmtD->closeCursor();

        if (!$driverRow) {
            http_response_code(404);
            coursesResponse(false, null, 'Driver not found.');
        }
        if ($driverRow['driver_status'] !== 'available') {
            http_response_code(409);
            coursesResponse(false, null, 'Kierowca nie jest dostępny (status: ' . $driverRow['driver_status'] . ').');
        }

        // Fetch queued orders for this course
        $stmtOrders = $pdo->prepare(
            "SELECT id FROM sh_orders
             WHERE course_id = :cid AND tenant_id = :tid AND delivery_status = 'queued'"
        );
        $stmtOrders->execute([':cid' => $courseId, ':tid' => $tenant_id]);
        $queuedOrders = $stmtOrders->fetchAll(PDO::FETCH_COLUMN);

        if (count($queuedOrders) === 0) {
            http_response_code(404);
            coursesResponse(false, null, 'Brak zamówień w kolejce dla kursu ' . $courseId);
        }

        $pdo->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');

            $stmtUp = $pdo->prepare(
                "UPDATE sh_orders SET delivery_status='in_delivery', driver_id=:did, updated_at=:now
                 WHERE course_id=:cid AND tenant_id=:tid AND delivery_status='queued'"
            );
            $stmtUp->execute([':did'=>$driverId, ':cid'=>$courseId, ':tid'=>$tenant_id, ':now'=>$now]);

            $stmtAudit = $pdo->prepare(
                "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
                 VALUES (:oid, :uid, 'queued', 'in_delivery', :now)"
            );
            foreach ($queuedOrders as $oid) {
                $stmtAudit->execute([':oid'=>$oid, ':uid'=>$user_id, ':now'=>$now]);
            }

            $pdo->prepare("UPDATE sh_drivers SET status='busy' WHERE user_id=:did AND tenant_id=:tid")
                ->execute([':did'=>$driverId, ':tid'=>$tenant_id]);

            // Update dispatch_log with assigned driver
            $pdo->prepare(
                "UPDATE sh_dispatch_log SET driver_id=:did WHERE course_id=:cid AND tenant_id=:tid AND driver_id IS NULL"
            )->execute([':did'=>$driverId, ':cid'=>$courseId, ':tid'=>$tenant_id]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        coursesResponse(true, [
            'course_id' => $courseId,
            'driver_id' => $driverId,
            'orders_assigned' => count($queuedOrders),
            'driver_status' => 'busy',
        ]);
    }

    // =========================================================================
    // ACTION: append_to_course — Add orders to an existing active course
    // =========================================================================
    if ($action === 'append_to_course') {
        $courseId = (string)($input['course_id'] ?? '');
        $orderIds = $input['order_ids'] ?? [];

        if ($courseId === '' || !is_array($orderIds) || count($orderIds) === 0) {
            http_response_code(400);
            coursesResponse(false, null, 'course_id and order_ids[] are required.');
        }
        $orderIds = array_values(array_unique($orderIds));

        $stmtCourse = $pdo->prepare(
            "SELECT driver_id, MAX(CAST(SUBSTRING(stop_number, 2) AS UNSIGNED)) AS max_stop
             FROM sh_orders
             WHERE course_id = :cid AND tenant_id = :tid AND delivery_status = 'in_delivery'
             GROUP BY driver_id"
        );
        $stmtCourse->execute([':cid' => $courseId, ':tid' => $tenant_id]);
        $courseInfo = $stmtCourse->fetch(PDO::FETCH_ASSOC);
        $stmtCourse->closeCursor();

        if (!$courseInfo) {
            http_response_code(404);
            coursesResponse(false, null, 'Active course not found.');
        }
        $driverId = $courseInfo['driver_id'];
        $maxStop  = (int)$courseInfo['max_stop'];

        // READY LOCK for append too
        $ph = []; $prm = [':tid' => $tenant_id];
        foreach ($orderIds as $i => $oid) { $k = ":o{$i}"; $ph[] = $k; $prm[$k] = $oid; }
        $stmtO = $pdo->prepare(
            "SELECT id, status, order_type, delivery_address, delivery_status
             FROM sh_orders WHERE id IN (" . implode(',', $ph) . ") AND tenant_id = :tid"
        );
        $stmtO->execute($prm);
        $fetched = $stmtO->fetchAll(PDO::FETCH_ASSOC);

        if (count($fetched) !== count($orderIds)) {
            http_response_code(404);
            coursesResponse(false, null, 'One or more orders not found.');
        }
        foreach ($fetched as $fo) {
            if (($fo['order_type'] ?? '') !== 'delivery') {
                http_response_code(409);
                coursesResponse(false, null, "Zamówienie {$fo['id']} nie jest dostawą.");
            }
            if ($fo['status'] !== 'ready') {
                http_response_code(409);
                coursesResponse(false, null, "Zamówienie {$fo['id']} nie jest gotowe (status: {$fo['status']}).");
            }
        }
        $addrMap = array_column($fetched, 'delivery_address', 'id');

        $pdo->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');
            $stmtUp = $pdo->prepare(
                "UPDATE sh_orders SET delivery_status='in_delivery', driver_id=:did, course_id=:cid, stop_number=:stop, updated_at=:now
                 WHERE id=:oid AND tenant_id=:tid AND order_type='delivery'"
            );
            $stmtAudit = $pdo->prepare(
                "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
                 VALUES (:oid, :uid, 'unassigned', 'in_delivery', :now)"
            );

            $stops = [];
            foreach ($orderIds as $idx => $oid) {
                $stopNum = 'L' . ($maxStop + $idx + 1);
                $stmtUp->execute([':did'=>$driverId, ':cid'=>$courseId, ':stop'=>$stopNum, ':oid'=>$oid, ':tid'=>$tenant_id, ':now'=>$now]);
                $stmtAudit->execute([':oid'=>$oid, ':uid'=>$user_id, ':now'=>$now]);
                $stops[] = ['order_id'=>$oid, 'stop'=>$stopNum, 'address'=>$addrMap[$oid] ?? null];
            }

            $stmtLog = $pdo->prepare(
                "SELECT id, order_ids_json FROM sh_dispatch_log
                 WHERE course_id = :cid AND tenant_id = :tid ORDER BY dispatched_at DESC LIMIT 1"
            );
            $stmtLog->execute([':cid' => $courseId, ':tid' => $tenant_id]);
            $logRow = $stmtLog->fetch(PDO::FETCH_ASSOC);
            $stmtLog->closeCursor();

            if ($logRow) {
                $existingIds = json_decode($logRow['order_ids_json'], true) ?: [];
                $mergedIds = array_values(array_unique(array_merge($existingIds, $orderIds)));
                $pdo->prepare("UPDATE sh_dispatch_log SET order_ids_json = :oj WHERE id = :lid")
                    ->execute([':oj' => json_encode($mergedIds), ':lid' => $logRow['id']]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        coursesResponse(true, ['course_id' => $courseId, 'appended_stops' => $stops, 'total_stops' => $maxStop + count($orderIds)]);
    }

    // =========================================================================
    // ACTION: update_order_status — Kitchen status transitions via State Machine
    //
    // [FF-HOOK] Transition validation delegated to OrderStateMachine.
    // When 'skip_kitchen' flag is active:
    //   - pending → ready is permitted (bypass 'preparing')
    //   - KDS/kitchen display can be hidden by frontend
    // When 'auto_complete' flag is active:
    //   - pending → completed is permitted (fast-food one-click)
    //
    // The delivery-status side-effects (marking delivered, releasing driver)
    // are preserved here because they depend on course context.
    // =========================================================================
    if ($action === 'update_order_status') {
        $orderId   = (string)($input['order_id'] ?? '');
        $newStatus = (string)($input['new_status'] ?? '');

        if ($orderId === '' || !in_array($newStatus, OrderStateMachine::validStatuses(), true)) {
            http_response_code(400);
            coursesResponse(false, null, 'order_id and valid new_status required.');
        }

        $stmtO = $pdo->prepare(
            "SELECT id, status, driver_id, course_id, delivery_status FROM sh_orders
             WHERE id = :oid AND tenant_id = :tid"
        );
        $stmtO->execute([':oid' => $orderId, ':tid' => $tenant_id]);
        $order = $stmtO->fetch(PDO::FETCH_ASSOC);
        $stmtO->closeCursor();

        if (!$order) {
            http_response_code(404);
            coursesResponse(false, null, 'Order not found.');
        }

        // [FF-HOOK] Central gate — flags widen permitted transitions
        if (!OrderStateMachine::canTransition($order['status'], $newStatus, $tenantFlags)) {
            http_response_code(409);
            coursesResponse(false, null,
                "Transition '{$order['status']}' → '{$newStatus}' is not allowed."
            );
        }

        $pdo->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');

            $result = OrderStateMachine::transitionOrder(
                $pdo, $orderId, $tenant_id, $user_id, $newStatus, $tenantFlags
            );

            if (!$result['success']) {
                $pdo->rollBack();
                http_response_code(409);
                coursesResponse(false, null, $result['message']);
            }

            // Delivery side-effects: when completing a delivery order,
            // mark it as delivered and potentially release the driver.
            if ($newStatus === 'completed' && $order['course_id']) {
                $pdo->prepare(
                    "UPDATE sh_orders SET delivery_status = 'delivered' WHERE id = :oid AND tenant_id = :tid"
                )->execute([':oid' => $orderId, ':tid' => $tenant_id]);

                $stmtPending = $pdo->prepare(
                    "SELECT COUNT(*) FROM sh_orders
                     WHERE course_id = :cid AND tenant_id = :tid AND delivery_status = 'in_delivery'"
                );
                $stmtPending->execute([':cid' => $order['course_id'], ':tid' => $tenant_id]);
                $remaining = (int)$stmtPending->fetchColumn();

                if ($remaining === 0 && $order['driver_id']) {
                    $pdo->prepare("UPDATE sh_drivers SET status='available' WHERE user_id=:did AND tenant_id=:tid")
                        ->execute([':did' => $order['driver_id'], ':tid' => $tenant_id]);
                }
            }

            // [m026] Publish lifecycle events dla zmiany statusu.
            $publisherPath = __DIR__ . '/../../core/OrderEventPublisher.php';
            if (file_exists($publisherPath)) {
                require_once $publisherPath;
                if (class_exists('OrderEventPublisher')) {
                    // Główny event per status transition
                    $statusEventMap = [
                        'completed' => 'order.completed',
                        'cancelled' => 'order.cancelled',
                    ];
                    $mainEvent = $statusEventMap[$newStatus] ?? null;
                    if ($mainEvent) {
                        OrderEventPublisher::publishOrderLifecycle(
                            $pdo, $tenant_id, $mainEvent, $orderId,
                            [
                                'from_status' => $result['old_status'] ?? null,
                                'to_status'   => $newStatus,
                                'course_id'   => $order['course_id'],
                                'driver_id'   => $order['driver_id'],
                            ],
                            [
                                'source'         => 'courses',
                                'actorType'      => 'staff',
                                'actorId'        => (string)$user_id,
                                'idempotencyKey' => $orderId . ':' . $mainEvent,
                            ]
                        );
                    }

                    // Dodatkowy event order.delivered dla delivery orders
                    if ($newStatus === 'completed' && $order['course_id']) {
                        OrderEventPublisher::publishOrderLifecycle(
                            $pdo, $tenant_id, 'order.delivered', $orderId,
                            [
                                'course_id' => $order['course_id'],
                                'driver_id' => $order['driver_id'],
                            ],
                            [
                                'source'         => 'courses',
                                'actorType'      => 'staff',
                                'actorId'        => (string)$user_id,
                                'idempotencyKey' => $orderId . ':order.delivered',
                            ]
                        );
                    }
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        coursesResponse(true, [
            'order_id'   => $orderId,
            'old_status' => $result['old_status'],
            'new_status' => $newStatus,
        ]);
    }

    // =========================================================================
    // ACTION: cancel_stop — Remove a single order from a course
    // =========================================================================
    if ($action === 'cancel_stop') {
        $orderId = (string)($input['order_id'] ?? '');
        if ($orderId === '') {
            http_response_code(400);
            coursesResponse(false, null, 'order_id required.');
        }

        $pdo->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');
            $stmtO = $pdo->prepare("SELECT status, delivery_status, driver_id, course_id FROM sh_orders WHERE id=:oid AND tenant_id=:tid");
            $stmtO->execute([':oid'=>$orderId, ':tid'=>$tenant_id]);
            $order = $stmtO->fetch(PDO::FETCH_ASSOC);
            $stmtO->closeCursor();
            if (!$order) { throw new RuntimeException('Order not found.'); }

            $pdo->prepare(
                "UPDATE sh_orders SET delivery_status='unassigned', driver_id=NULL, course_id=NULL, stop_number=NULL, updated_at=:now
                 WHERE id=:oid AND tenant_id=:tid"
            )->execute([':now'=>$now, ':oid'=>$orderId, ':tid'=>$tenant_id]);

            $pdo->prepare(
                "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
                 VALUES (:oid, :uid, :os, 'unassigned', :now)"
            )->execute([':oid'=>$orderId, ':uid'=>$user_id, ':os'=>$order['delivery_status'] ?? 'in_delivery', ':now'=>$now]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        coursesResponse(true, ['order_id'=>$orderId, 'delivery_status'=>'unassigned']);
    }

    // =========================================================================
    // ACTION: cancel_order — Driver or dispatcher cancels an order
    // Uses State Machine for status transition, clears delivery assignments.
    // =========================================================================
    if ($action === 'cancel_order') {
        $orderId = (string)($input['order_id'] ?? '');
        $reason  = trim((string)($input['reason'] ?? ''));

        if ($orderId === '') {
            http_response_code(400);
            coursesResponse(false, null, 'order_id required.');
        }
        if ($reason === '') {
            http_response_code(400);
            coursesResponse(false, null, 'Podaj powód anulowania.');
        }

        $stmtO = $pdo->prepare(
            "SELECT id, status, delivery_status, driver_id, course_id FROM sh_orders WHERE id=:oid AND tenant_id=:tid"
        );
        $stmtO->execute([':oid'=>$orderId, ':tid'=>$tenant_id]);
        $order = $stmtO->fetch(PDO::FETCH_ASSOC);
        $stmtO->closeCursor();

        if (!$order) {
            http_response_code(404);
            coursesResponse(false, null, 'Order not found.');
        }

        // State machine handles terminal-state rejection
        if (!OrderStateMachine::canTransition($order['status'], 'cancelled', $tenantFlags)) {
            http_response_code(409);
            coursesResponse(false, null, "Cannot cancel order in status '{$order['status']}'.");
        }

        $pdo->beginTransaction();
        try {
            $result = OrderStateMachine::transitionOrder(
                $pdo, $orderId, $tenant_id, $user_id, 'cancelled', $tenantFlags,
                [
                    'delivery_status'     => 'unassigned',
                    'driver_id'           => null,
                    'course_id'           => null,
                    'stop_number'         => null,
                    'cancellation_reason' => $reason,
                ]
            );

            if (!$result['success']) {
                $pdo->rollBack();
                http_response_code(409);
                coursesResponse(false, null, $result['message']);
            }

            // Auto-finish: release driver if last order in course
            if ($order['course_id'] && $order['driver_id']) {
                $stmtRemaining = $pdo->prepare(
                    "SELECT COUNT(*) FROM sh_orders
                     WHERE course_id = :cid AND tenant_id = :tid AND delivery_status = 'in_delivery'"
                );
                $stmtRemaining->execute([':cid' => $order['course_id'], ':tid' => $tenant_id]);
                $remaining = (int)$stmtRemaining->fetchColumn();

                if ($remaining === 0) {
                    $pdo->prepare("UPDATE sh_drivers SET status='available' WHERE user_id=:did AND tenant_id=:tid")
                        ->execute([':did' => $order['driver_id'], ':tid' => $tenant_id]);
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        coursesResponse(true, ['order_id'=>$orderId, 'new_status'=>'cancelled']);
    }

    // =========================================================================
    // ACTION: start_shift
    // =========================================================================
    if ($action === 'start_shift') {
        $driverUserId = (string)($input['driver_user_id'] ?? $user_id);
        $initialCash  = isset($input['initial_cash']) ? (int)round((float)$input['initial_cash'] * 100) : 0;

        $stmtCheck = $pdo->prepare(
            "SELECT id FROM sh_driver_shifts WHERE driver_id=:did AND tenant_id=:tid AND status='active' LIMIT 1"
        );
        $stmtCheck->execute([':did'=>$driverUserId, ':tid'=>$tenant_id]);
        $hasShift = (bool)$stmtCheck->fetch();
        $stmtCheck->closeCursor();
        if ($hasShift) {
            http_response_code(409);
            coursesResponse(false, null, 'Driver already has an active shift.');
        }

        $pdo->prepare(
            "INSERT INTO sh_driver_shifts (tenant_id, driver_id, initial_cash, status, created_at)
             VALUES (:tid, :did, :ic, 'active', NOW())"
        )->execute([':tid'=>$tenant_id, ':did'=>$driverUserId, ':ic'=>$initialCash]);

        $pdo->prepare("UPDATE sh_drivers SET status='available' WHERE user_id=:did AND tenant_id=:tid")
            ->execute([':did'=>$driverUserId, ':tid'=>$tenant_id]);

        coursesResponse(true, ['shift_started' => true, 'initial_cash_grosze' => $initialCash]);
    }

    // =========================================================================
    // ACTION: update_location
    // =========================================================================
    if ($action === 'update_location') {
        $lat = isset($input['lat']) ? (float)$input['lat'] : null;
        $lng = isset($input['lng']) ? (float)$input['lng'] : null;
        if ($lat === null || $lng === null) {
            http_response_code(400);
            coursesResponse(false, null, 'lat and lng required.');
        }

        $pdo->prepare(
            "INSERT INTO sh_driver_locations (driver_id, tenant_id, lat, lng, heading, speed_kmh, accuracy_m, updated_at)
             VALUES (:did, :tid, :lat, :lng, :h, :s, :a, NOW())
             ON DUPLICATE KEY UPDATE lat=VALUES(lat), lng=VALUES(lng), heading=VALUES(heading), speed_kmh=VALUES(speed_kmh), accuracy_m=VALUES(accuracy_m), updated_at=NOW()"
        )->execute([
            ':did'=>$user_id, ':tid'=>$tenant_id,
            ':lat'=>$lat, ':lng'=>$lng,
            ':h'=>$input['heading'] ?? null, ':s'=>$input['speed'] ?? null, ':a'=>$input['accuracy'] ?? null,
        ]);

        coursesResponse(true, ['location_updated' => true]);
    }

    // =========================================================================
    // ACTION: get_driver_runs — Driver's assigned active orders
    // =========================================================================
    if ($action === 'get_driver_runs') {
        $driverId = (string)($input['driver_id'] ?? $user_id);

        $stmtOrders = $pdo->prepare(
            "SELECT o.id, o.order_number, o.status, o.grand_total, o.payment_status, o.payment_method,
                    o.customer_name, o.customer_phone, o.delivery_address, o.lat, o.lng,
                    o.promised_time, o.course_id, o.stop_number, o.tip_amount,
                    o.delivery_status, o.created_at
             FROM sh_orders o
             WHERE o.driver_id = :did
               AND o.tenant_id = :tid
               AND o.status NOT IN ('completed', 'cancelled')
             ORDER BY o.created_at ASC"
        );
        $stmtOrders->execute([':did' => $driverId, ':tid' => $tenant_id]);
        $orders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

        $oids = array_column($orders, 'id');
        $lmap = [];
        if (count($oids) > 0) {
            $p2 = []; $prm2 = [];
            foreach ($oids as $i => $oid) { $k=":x{$i}"; $p2[]=$k; $prm2[$k]=$oid; }
            $s2 = $pdo->prepare("SELECT order_id, snapshot_name, quantity, comment, modifiers_json, COALESCE(driver_action_type,'none') AS driver_action_type FROM sh_order_lines WHERE order_id IN (" . implode(',',$p2) . ")");
            $s2->execute($prm2);
            foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $l) { $lmap[$l['order_id']][] = $l; }
        }
        foreach ($orders as &$o) { $o['lines'] = $lmap[$o['id']] ?? []; }
        unset($o);

        // Wallet: shift initial cash + cash the driver ACTUALLY collected (from sh_order_payments)
        $stmtShiftW = $pdo->prepare(
            "SELECT initial_cash, created_at FROM sh_driver_shifts
             WHERE driver_id = :did AND tenant_id = :tid AND status = 'active' LIMIT 1"
        );
        $stmtShiftW->execute([':did' => $driverId, ':tid' => $tenant_id]);
        $shiftRow = $stmtShiftW->fetch(PDO::FETCH_ASSOC);
        $stmtShiftW->closeCursor();
        $initialCash = (int)($shiftRow['initial_cash'] ?? 0);
        $shiftBound  = $shiftRow ? $shiftRow['created_at'] : date('Y-m-d 00:00:00');

        $stmtCashW = $pdo->prepare(
            "SELECT COALESCE(SUM(p.amount_grosze), 0) AS cash_total
             FROM sh_order_payments p
             JOIN sh_orders o ON o.id = p.order_id AND o.tenant_id = p.tenant_id
             WHERE p.user_id = :did AND p.tenant_id = :tid AND p.method = 'cash'
               AND o.order_type = 'delivery' AND o.status = 'completed'
               AND p.created_at >= :ss"
        );
        $stmtCashW->execute([':did' => $driverId, ':tid' => $tenant_id, ':ss' => $shiftBound]);
        $cashCollected = (int)$stmtCashW->fetchColumn();
        $stmtCashW->closeCursor();

        coursesResponse(true, [
            'orders' => $orders,
            'wallet' => [
                'initial_cash'  => $initialCash,
                'cash_collected'=> $cashCollected,
                'total_in_hand' => $initialCash + $cashCollected,
            ],
        ]);
    }

    // =========================================================================
    // ACTION: set_initial_cash
    // =========================================================================
    if ($action === 'set_initial_cash') {
        $driverUserId = (string)($input['driver_user_id'] ?? '');
        $amount       = isset($input['amount']) ? (int)round((float)$input['amount'] * 100) : 0;

        if ($driverUserId === '') {
            http_response_code(400);
            coursesResponse(false, null, 'driver_user_id required.');
        }

        $stmtShift = $pdo->prepare(
            "SELECT id FROM sh_driver_shifts WHERE driver_id=:did AND tenant_id=:tid AND status='active' LIMIT 1"
        );
        $stmtShift->execute([':did'=>$driverUserId, ':tid'=>$tenant_id]);
        $shift = $stmtShift->fetch(PDO::FETCH_ASSOC);
        $stmtShift->closeCursor();

        if ($shift) {
            $pdo->prepare("UPDATE sh_driver_shifts SET initial_cash=:ic WHERE id=:sid")
                ->execute([':ic'=>$amount, ':sid'=>$shift['id']]);
        } else {
            $pdo->prepare(
                "INSERT INTO sh_driver_shifts (tenant_id, driver_id, initial_cash, status) VALUES (:tid, :did, :ic, 'active')"
            )->execute([':tid'=>$tenant_id, ':did'=>$driverUserId, ':ic'=>$amount]);
            $pdo->prepare("UPDATE sh_drivers SET status='available' WHERE user_id=:did AND tenant_id=:tid")
                ->execute([':did'=>$driverUserId, ':tid'=>$tenant_id]);
        }

        coursesResponse(true, ['initial_cash_grosze'=>$amount]);
    }

    // =========================================================================
    // ACTION: reconcile
    // =========================================================================
    if ($action === 'reconcile') {
        $driverUserId = (string)($input['driver_user_id'] ?? '');
        $countedRaw   = $input['counted_cash'] ?? null;

        if ($driverUserId === '' || !is_numeric($countedRaw)) {
            http_response_code(400);
            coursesResponse(false, null, 'driver_user_id and counted_cash required.');
        }
        $countedGrosze = (int)round((float)$countedRaw * 100);

        $stmtShift = $pdo->prepare(
            "SELECT id, initial_cash, created_at FROM sh_driver_shifts
             WHERE driver_id=:did AND tenant_id=:tid AND status='active' LIMIT 1"
        );
        $stmtShift->execute([':did'=>$driverUserId, ':tid'=>$tenant_id]);
        $shift = $stmtShift->fetch(PDO::FETCH_ASSOC);
        $stmtShift->closeCursor();

        if (!$shift) {
            http_response_code(400);
            coursesResponse(false, null, 'No active shift found.');
        }

        // Authoritative cash collected from sh_order_payments (not sh_orders.payment_status)
        $stmtAgg = $pdo->prepare(
            "SELECT COALESCE(SUM(p.amount_grosze), 0) AS cash_grosze
             FROM sh_order_payments p
             JOIN sh_orders o ON o.id = p.order_id AND o.tenant_id = p.tenant_id
             WHERE p.user_id = :did AND p.tenant_id = :tid AND p.method = 'cash'
               AND o.order_type = 'delivery' AND o.status = 'completed'
               AND p.created_at >= :ss"
        );
        $stmtAgg->execute([':did'=>$driverUserId, ':tid'=>$tenant_id, ':ss'=>$shift['created_at']]);
        $cashGrosze = (int)$stmtAgg->fetchColumn();
        $stmtAgg->closeCursor();

        $expected = (int)$shift['initial_cash'] + $cashGrosze;
        $variance = $countedGrosze - $expected;
        $flag     = abs($variance) > 500 ? 'REVIEW_REQUIRED' : 'OK';

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE sh_driver_shifts SET counted_cash=:cc, variance=:v, status='closed' WHERE id=:sid AND status='active'"
            )->execute([':cc'=>$countedGrosze, ':v'=>$variance, ':sid'=>$shift['id']]);

            $pdo->prepare("UPDATE sh_drivers SET status='offline' WHERE user_id=:did AND tenant_id=:tid")
                ->execute([':did'=>$driverUserId, ':tid'=>$tenant_id]);

            $pdo->commit();
        } catch (\Throwable $e) { $pdo->rollBack(); throw $e; }

        $fmt = fn(int $g): string => number_format($g / 100, 2, '.', '');
        coursesResponse(true, [
            'expected'  => $fmt($expected),
            'counted'   => $fmt($countedGrosze),
            'variance'  => $fmt($variance),
            'flag'      => $flag,
        ]);
    }

    // =========================================================================
    // ACTION: set_driver_status
    // =========================================================================
    if ($action === 'set_driver_status') {
        $driverUserId = (string)($input['driver_user_id'] ?? '');
        $newStatus    = (string)($input['status'] ?? '');
        $validStatuses = ['available','offline','busy'];

        if ($driverUserId === '' || !in_array($newStatus, $validStatuses, true)) {
            http_response_code(400);
            coursesResponse(false, null, 'driver_user_id and valid status required.');
        }

        $pdo->prepare("UPDATE sh_drivers SET status=:s WHERE user_id=:did AND tenant_id=:tid")
            ->execute([':s'=>$newStatus, ':did'=>$driverUserId, ':tid'=>$tenant_id]);

        coursesResponse(true, ['driver_status'=>$newStatus]);
    }

    // =========================================================================
    // ACTION: collect_payment — Payment Safety Lock
    // Sets payment_status to 'cash' or 'card' AND inserts into
    // sh_order_payments with user_id = driver so settlement math
    // knows THIS DRIVER physically collected the money.
    // =========================================================================
    if ($action === 'collect_payment') {
        $orderId        = (string)($input['order_id'] ?? '');
        $collectionType = (string)($input['collection_type'] ?? '');
        $allowed        = ['cash', 'card'];

        if ($orderId === '' || !in_array($collectionType, $allowed, true)) {
            http_response_code(400);
            coursesResponse(false, null, 'order_id and collection_type (cash|card) required.');
        }

        $stmtO = $pdo->prepare(
            "SELECT id, status, payment_status, driver_id, grand_total
             FROM sh_orders WHERE id = :oid AND tenant_id = :tid"
        );
        $stmtO->execute([':oid' => $orderId, ':tid' => $tenant_id]);
        $order = $stmtO->fetch(PDO::FETCH_ASSOC);
        $stmtO->closeCursor();

        if (!$order) {
            http_response_code(404);
            coursesResponse(false, null, 'Order not found.');
        }
        if (isPaid($order['payment_status'])) {
            coursesResponse(false, null, 'Zamówienie jest już opłacone.');
        }

        $now = date('Y-m-d H:i:s');
        $driverIdForPayment = (string)($order['driver_id'] ?: $user_id);

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE sh_orders SET payment_status = :ps, payment_method = :pm, updated_at = :now
                 WHERE id = :oid AND tenant_id = :tid"
            )->execute([':ps' => $collectionType, ':pm' => $collectionType, ':now' => $now, ':oid' => $orderId, ':tid' => $tenant_id]);

            $pdo->prepare(
                "INSERT INTO sh_order_payments (id, order_id, tenant_id, user_id, method, amount_grosze, tendered_grosze, created_at)
                 VALUES (:id, :oid, :tid, :uid, :method, :amount, :amount2, :now)"
            )->execute([
                ':id'      => $uuid4(),
                ':oid'     => $orderId,
                ':tid'     => $tenant_id,
                ':uid'     => $driverIdForPayment,
                ':method'  => $collectionType,
                ':amount'  => (int)$order['grand_total'],
                ':amount2' => (int)$order['grand_total'],
                ':now'     => $now,
            ]);

            $pdo->prepare(
                "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
                 VALUES (:oid, :uid, :os, :ns, :now)"
            )->execute([':oid' => $orderId, ':uid' => $user_id, ':os' => $order['payment_status'], ':ns' => "payment_{$collectionType}", ':now' => $now]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        coursesResponse(true, ['order_id' => $orderId, 'payment_status' => $collectionType]);
    }

    // =========================================================================
    // ACTION: deliver_order — Payment Safety Lock enforced via State Machine
    //
    // SETTLEMENT RULE (the core financial fix):
    //   - payment_status = 'to_pay'       → REJECTED (driver must collect first
    //                                        via collect_payment, which inserts
    //                                        the sh_order_payments record).
    //   - payment_status = 'cash' | 'card' (set by driver via collect_payment)
    //                                      → Payment record already exists in
    //                                        sh_order_payments with user_id =
    //                                        driver. Just transition status.
    //   - payment_status = 'online_paid'   → Pre-paid. Driver is NOT collecting
    //                                        money. NO sh_order_payments insert.
    //   - payment_status = 'cash' | 'card' (set at POS before dispatch)
    //                                      → Already paid at POS. NO payment
    //                                        record for driver. Just transition.
    //
    // The wallet/reconcile queries use sh_order_payments.user_id to segregate
    // who physically handled the cash, making this mathematically flawless.
    //
    // [FF-007] When 'skip_payment_lock' flag is active, the isPaid() check
    // is bypassed for aggregator/auto-settled scenarios.
    //
    // [FF-002] When 'skip_dispatch' is active, this action is never called.
    // =========================================================================
    if ($action === 'deliver_order') {
        $orderId = (string)($input['order_id'] ?? '');
        if ($orderId === '') {
            http_response_code(400);
            coursesResponse(false, null, 'order_id required.');
        }

        $stmtO = $pdo->prepare(
            "SELECT id, status, payment_status, payment_method, driver_id, course_id, delivery_status, grand_total
             FROM sh_orders WHERE id = :oid AND tenant_id = :tid"
        );
        $stmtO->execute([':oid' => $orderId, ':tid' => $tenant_id]);
        $order = $stmtO->fetch(PDO::FETCH_ASSOC);
        $stmtO->closeCursor();

        if (!$order) {
            http_response_code(404);
            coursesResponse(false, null, 'Order not found.');
        }
        if ($order['delivery_status'] !== 'in_delivery') {
            http_response_code(409);
            coursesResponse(false, null, "Zamówienie nie jest w dostawie (delivery_status: {$order['delivery_status']}).");
        }

        // [FF-007] Payment lock — bypassed when skip_payment_lock is active
        $requirePayment = empty($tenantFlags['skip_payment_lock']);
        if ($requirePayment && !OrderStateMachine::isPaid($order['payment_status'])) {
            http_response_code(409);
            coursesResponse(false, null, 'PAYMENT_LOCK: Musisz najpierw pobrać płatność (gotówka/karta) przed oznaczeniem dostawy.');
        }

        $pdo->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');

            $result = OrderStateMachine::transitionOrder(
                $pdo, $orderId, $tenant_id, $user_id, 'completed', $tenantFlags,
                ['delivery_status' => 'delivered', 'delivered_at' => $now]
            );

            if (!$result['success']) {
                $pdo->rollBack();
                http_response_code(409);
                coursesResponse(false, null, $result['message']);
            }

            // Publish order.delivered + order.completed events
            require_once __DIR__ . '/../../core/OrderEventPublisher.php';
            OrderEventPublisher::publishOrderLifecycle(
                $pdo, $tenant_id, 'order.delivered', $orderId,
                ['driver_id' => $order['driver_id'], 'course_id' => $order['course_id'], 'delivered_at' => $now],
                ['source' => 'courses', 'actorType' => 'staff', 'actorId' => (string)$user_id, 'idempotencyKey' => $orderId . ':order.delivered']
            );
            OrderEventPublisher::publishOrderLifecycle(
                $pdo, $tenant_id, 'order.completed', $orderId,
                ['delivery_status' => 'delivered', 'completed_at' => $now],
                ['source' => 'courses', 'actorType' => 'staff', 'actorId' => (string)$user_id]
            );

            // Auto-finish: release driver when all stops in course are done
            if ($order['course_id']) {
                $stmtPending = $pdo->prepare(
                    "SELECT COUNT(*) FROM sh_orders
                     WHERE course_id = :cid AND tenant_id = :tid AND delivery_status = 'in_delivery'"
                );
                $stmtPending->execute([':cid' => $order['course_id'], ':tid' => $tenant_id]);
                $remaining = (int)$stmtPending->fetchColumn();

                if ($remaining === 0 && $order['driver_id']) {
                    $pdo->prepare("UPDATE sh_drivers SET status='available' WHERE user_id=:did AND tenant_id=:tid")
                        ->execute([':did' => $order['driver_id'], ':tid' => $tenant_id]);
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        coursesResponse(true, ['order_id' => $orderId, 'new_status' => 'completed', 'delivery_status' => 'delivered']);
    }

    // =========================================================================
    // ACTION: emergency_recall
    // =========================================================================
    if ($action === 'emergency_recall') {
        $driverUserId = (string)($input['driver_user_id'] ?? '');
        if ($driverUserId === '') {
            http_response_code(400);
            coursesResponse(false, null, 'driver_user_id required.');
        }

        $pdo->prepare(
            "INSERT INTO sh_driver_locations (driver_id, tenant_id, lat, lng, heading, speed_kmh, accuracy_m, updated_at)
             VALUES (:did, :tid, 0, 0, NULL, NULL, NULL, NOW())
             ON DUPLICATE KEY UPDATE heading = -999, updated_at = NOW()"
        )->execute([':did' => $driverUserId, ':tid' => $tenant_id]);

        coursesResponse(true, ['recalled' => true, 'driver_user_id' => $driverUserId]);
    }

    // =========================================================================
    // ACTION: check_recall
    // =========================================================================
    if ($action === 'check_recall') {
        $stmtR = $pdo->prepare(
            "SELECT heading FROM sh_driver_locations WHERE driver_id = :did AND tenant_id = :tid"
        );
        $stmtR->execute([':did' => $user_id, ':tid' => $tenant_id]);
        $row = $stmtR->fetch(PDO::FETCH_ASSOC);
        $stmtR->closeCursor();
        $recalled = ($row && (int)$row['heading'] === -999);

        coursesResponse(true, ['recalled' => $recalled]);
    }

    // =========================================================================
    // ACTION: clear_recall
    // =========================================================================
    if ($action === 'clear_recall') {
        $pdo->prepare(
            "UPDATE sh_driver_locations SET heading = NULL WHERE driver_id = :did AND tenant_id = :tid AND heading = -999"
        )->execute([':did' => $user_id, ':tid' => $tenant_id]);

        coursesResponse(true, ['cleared' => true]);
    }

    // =========================================================================
    // ACTION: get_driver_wallet
    //
    // SETTLEMENT-SAFE: Financial breakdown sourced from sh_order_payments
    // with user_id = :driver_id, NOT from sh_orders.payment_status.
    // This guarantees that only money the driver PHYSICALLY collected
    // appears in "cash to return" / "card on terminal".
    // =========================================================================
    if ($action === 'get_driver_wallet') {
        $driverId = (string)($input['driver_id'] ?? $user_id);

        $stmtShift = $pdo->prepare(
            "SELECT id, initial_cash, created_at FROM sh_driver_shifts
             WHERE driver_id = :did AND tenant_id = :tid AND status = 'active' LIMIT 1"
        );
        $stmtShift->execute([':did' => $driverId, ':tid' => $tenant_id]);
        $shift = $stmtShift->fetch(PDO::FETCH_ASSOC);
        $stmtShift->closeCursor();

        $initialCash = $shift ? (int)$shift['initial_cash'] : 0;
        $shiftBound  = $shift ? $shift['created_at'] : date('Y-m-d 00:00:00');

        // Authoritative: driver-collected amounts from sh_order_payments
        $stmtPayAgg = $pdo->prepare(
            "SELECT
                 COALESCE(SUM(CASE WHEN p.method = 'cash' THEN p.amount_grosze ELSE 0 END), 0) AS cash_collected,
                 COALESCE(SUM(CASE WHEN p.method = 'card' THEN p.amount_grosze ELSE 0 END), 0) AS card_collected
             FROM sh_order_payments p
             JOIN sh_orders o ON o.id = p.order_id AND o.tenant_id = p.tenant_id
             WHERE p.user_id = :did
               AND p.tenant_id = :tid
               AND o.order_type = 'delivery'
               AND o.status = 'completed'
               AND p.created_at >= :ss"
        );
        $stmtPayAgg->execute([':did' => $driverId, ':tid' => $tenant_id, ':ss' => $shiftBound]);
        $payAgg = $stmtPayAgg->fetch(PDO::FETCH_ASSOC);
        $stmtPayAgg->closeCursor();

        $cashTotal = (int)($payAgg['cash_collected'] ?? 0);
        $cardTotal = (int)($payAgg['card_collected'] ?? 0);

        // Pre-paid: orders delivered by this driver with NO payment collected by them
        $stmtPrepaid = $pdo->prepare(
            "SELECT COALESCE(SUM(o.grand_total), 0) AS prepaid_total
             FROM sh_orders o
             WHERE o.driver_id = :did
               AND o.tenant_id = :tid
               AND o.order_type = 'delivery'
               AND o.status = 'completed'
               AND o.created_at >= :ss
               AND NOT EXISTS (
                   SELECT 1 FROM sh_order_payments p
                   WHERE p.order_id = o.id AND p.user_id = :did2
               )"
        );
        $stmtPrepaid->execute([':did' => $driverId, ':tid' => $tenant_id, ':ss' => $shiftBound, ':did2' => $driverId]);
        $prepaidTotal = (int)$stmtPrepaid->fetchColumn();
        $stmtPrepaid->closeCursor();

        // Delivery list for history display
        $stmtDelivered = $pdo->prepare(
            "SELECT o.id, o.order_number, o.grand_total, o.payment_method, o.payment_status,
                    o.delivery_address, o.course_id, o.stop_number
             FROM sh_orders o
             WHERE o.driver_id = :did AND o.tenant_id = :tid AND o.order_type = 'delivery'
               AND o.status = 'completed' AND o.created_at >= :ss
             ORDER BY o.updated_at DESC"
        );
        $stmtDelivered->execute([':did' => $driverId, ':tid' => $tenant_id, ':ss' => $shiftBound]);
        $deliveries = $stmtDelivered->fetchAll(PDO::FETCH_ASSOC);

        $fmt = fn(int $g): string => number_format($g / 100, 2, '.', '');
        coursesResponse(true, [
            'initial_cash'   => $fmt($initialCash),
            'cash_collected' => $fmt($cashTotal),
            'card_collected' => $fmt($cardTotal),
            'prepaid_total'  => $fmt($prepaidTotal),
            'total_in_hand'  => $fmt($initialCash + $cashTotal),
            'deliveries'     => $deliveries,
            'delivery_count' => count($deliveries),
        ]);
    }

    // Unknown action
    http_response_code(400);
    coursesResponse(false, null, "Unknown action: {$action}");

} catch (PDOException $e) {
    http_response_code(500);
    error_log('[Courses Engine] PDOException: ' . $e->getMessage());
    coursesResponse(false, null, 'Database error.');
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('[Courses Engine] ' . $e->getMessage());
    coursesResponse(false, null, 'Internal server error.');
}

exit;
