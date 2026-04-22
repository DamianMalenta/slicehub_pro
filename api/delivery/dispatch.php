<?php
// =============================================================================
// SliceHub Enterprise — Dispatch & Route Assignment (K/L System)
// POST /api/delivery/dispatch.php
//
// Groups one or more ready-for-delivery orders into an atomic "course" (Kn),
// assigns sequential stop numbers (L1, L2…), and dispatches them to a driver.
//
// Writes a SINGLE grouped record to sh_dispatch_log — the source of truth
// for driver payout calculations.
//
// Schema: sh_orders, sh_users, sh_course_sequences, sh_order_audit,
//         sh_dispatch_log
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

    $driverId = isset($input['driver_id']) ? trim($input['driver_id']) : '';
    $orderIds = $input['order_ids'] ?? [];

    if ($driverId === '' || !is_array($orderIds) || count($orderIds) === 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'driver_id (string) and order_ids (non-empty array) are required.',
        ]);
        exit;
    }

    $orderIds = array_values(array_unique($orderIds));

    // =========================================================================
    // 2. STATE PRE-CHECKS (before touching any data)
    // =========================================================================

    // — 2a. Driver must exist, belong to tenant, be a driver, and be available —
    $stmtDriver = $pdo->prepare(
        "SELECT u.id FROM sh_users u
         JOIN sh_drivers d ON d.user_id = u.id AND d.tenant_id = u.tenant_id
         WHERE u.id = :did AND u.tenant_id = :tid AND u.role = 'driver' AND d.status = 'available'
         LIMIT 1"
    );
    $stmtDriver->execute([':did' => $driverId, ':tid' => $tenant_id]);

    if (!$stmtDriver->fetch()) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Driver not found, not available, or does not belong to this tenant.',
        ]);
        exit;
    }

    // — 2b. All orders must exist, belong to tenant, be ready & delivery type —
    $placeholders = [];
    $params       = [':tid' => $tenant_id];
    foreach ($orderIds as $i => $oid) {
        $key = ":oid{$i}";
        $placeholders[] = $key;
        $params[$key]   = $oid;
    }
    $inClause = implode(',', $placeholders);

    $stmtOrders = $pdo->prepare(
        "SELECT id, status, order_type, delivery_address
         FROM sh_orders
         WHERE id IN ({$inClause}) AND tenant_id = :tid"
    );
    $stmtOrders->execute($params);
    $fetchedOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

    if (count($fetchedOrders) !== count($orderIds)) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'One or more orders not found or do not belong to this tenant.',
        ]);
        exit;
    }

    $addressMap = [];
    foreach ($fetchedOrders as $fo) {
        if ($fo['status'] !== 'ready') {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => "Order {$fo['id']} is not in 'ready' status (current: {$fo['status']}).",
            ]);
            exit;
        }
        if ($fo['order_type'] !== 'delivery') {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => "Order {$fo['id']} is not a delivery order (type: {$fo['order_type']}).",
            ]);
            exit;
        }
        $addressMap[$fo['id']] = $fo['delivery_address'];
    }

    // =========================================================================
    // 3. HELPERS
    // =========================================================================
    $generateUuidV4 = function (): string {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    };

    // =========================================================================
    // 4. ATOMIC TRANSACTION
    // =========================================================================
    $pdo->beginTransaction();

    try {
        $now = date('Y-m-d H:i:s');

        // — 4a. Atomic course sequence (K-number) ————————————————————————————
        $stmtSeq = $pdo->prepare(
            "INSERT INTO sh_course_sequences (tenant_id, date, seq)
             VALUES (:tid, CURDATE(), 1)
             ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)"
        );
        $stmtSeq->execute([':tid' => $tenant_id]);
        $seq      = (int)$pdo->lastInsertId();
        $courseId  = 'K' . $seq;

        // — 4b. Update each order + per-order audit ——————————————————————————
        $stmtUpdateOrder = $pdo->prepare(
            "UPDATE sh_orders
             SET delivery_status = 'in_delivery', driver_id = :did, course_id = :cid, stop_number = :stop,
                 out_for_delivery_at = NOW()
             WHERE id = :oid AND tenant_id = :tid AND status = 'ready'"
        );

        $stmtAudit = $pdo->prepare(
            "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
             VALUES (:oid, :uid, 'unassigned', 'in_delivery', :now)"
        );

        $stops = [];

        // [m026] Prepare publisher (once per dispatch)
        $publisherPath = __DIR__ . '/../../core/OrderEventPublisher.php';
        $publisherReady = false;
        if (file_exists($publisherPath)) {
            require_once $publisherPath;
            $publisherReady = class_exists('OrderEventPublisher');
        }

        foreach ($orderIds as $index => $oid) {
            $stopNumber = 'L' . ($index + 1);

            $stmtUpdateOrder->execute([
                ':did'  => $driverId,
                ':cid'  => $courseId,
                ':stop' => $stopNumber,
                ':oid'  => $oid,
                ':tid'  => $tenant_id,
            ]);

            if ($stmtUpdateOrder->rowCount() === 0) {
                throw new RuntimeException(
                    "Order {$oid} could not be updated — concurrent status change detected."
                );
            }

            $stmtAudit->execute([
                ':oid' => $oid,
                ':uid' => $user_id,
                ':now' => $now,
            ]);

            if ($publisherReady) {
                OrderEventPublisher::publishOrderLifecycle(
                    $pdo, $tenant_id, 'order.dispatched', $oid,
                    [
                        'driver_id'   => $driverId,
                        'course_id'   => $courseId,
                        'stop_number' => $stopNumber,
                        'address'     => $addressMap[$oid] ?? null,
                    ],
                    [
                        'source'         => 'delivery',
                        'actorType'      => 'staff',
                        'actorId'        => (string)$user_id,
                        'idempotencyKey' => $oid . ':order.dispatched:' . $courseId,
                    ]
                );
            }

            $stops[] = [
                'order_id' => $oid,
                'stop'     => $stopNumber,
                'address'  => $addressMap[$oid] ?? null,
            ];
        }

        // — 4c. Mark driver as busy ——————————————————————————————————————————
        $stmtDriverBusy = $pdo->prepare(
            "UPDATE sh_drivers SET status = 'busy' WHERE user_id = :did AND tenant_id = :tid"
        );
        $stmtDriverBusy->execute([':did' => $driverId, ':tid' => $tenant_id]);

        // — 4d. GROUPED DISPATCH AUDIT (single record for payout math) ———————
        $stmtDispatch = $pdo->prepare(
            "INSERT INTO sh_dispatch_log
                (id, tenant_id, course_id, driver_id, order_ids_json, dispatched_by, dispatched_at)
             VALUES
                (:id, :tid, :cid, :did, :orders_json, :uid, NOW())"
        );
        $stmtDispatch->execute([
            ':id'          => $generateUuidV4(),
            ':tid'         => $tenant_id,
            ':cid'         => $courseId,
            ':did'         => $driverId,
            ':orders_json' => json_encode($orderIds),
            ':uid'         => $user_id,
        ]);

        // — 4e. COMMIT ———————————————————————————————————————————————————————
        $pdo->commit();

    } catch (Throwable $txErr) {
        $pdo->rollBack();
        throw $txErr;
    }

    // =========================================================================
    // 5. SUCCESS RESPONSE
    // =========================================================================
    echo json_encode([
        'success' => true,
        'data'    => [
            'course_id'     => $courseId,
            'stops'         => $stops,
            'driver_status' => 'busy',
        ],
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
    error_log('[Dispatch] PDOException: ' . $e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[Dispatch] ' . $e->getMessage());
}

exit;
