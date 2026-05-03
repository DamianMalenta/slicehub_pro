<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function kdsResponse(bool $ok, $data = null, ?string $msg = null): void {
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';

    $raw   = file_get_contents('php://input');
    $input = json_decode($raw ?: '{}', true) ?? [];
    $action = trim((string)($input['action'] ?? ''));

    // Auto-migration: driver_action_type on sh_order_lines
    try { $pdo->query("SELECT driver_action_type FROM sh_order_lines LIMIT 0"); }
    catch (\Throwable $e) {
        try { $pdo->exec("ALTER TABLE sh_order_lines ADD COLUMN driver_action_type ENUM('none','pack_cold','pack_separate','check_id') NOT NULL DEFAULT 'none' AFTER comment"); }
        catch (\Throwable $ignore) {}
    }

    // =========================================================================
    // ACTION: get_board — Returns active orders with lines for KDS display
    //
    // Canonical status dictionary (single source of truth across system):
    //   new → accepted → preparing → ready → in_delivery → completed
    //   (+ cancelled)
    //
    // KDS shows statuses that still need kitchen action:
    //   new       — just received, awaiting acceptance
    //   accepted  — accepted, queued for prep
    //   preparing — currently being prepared
    // Ready tickets leave the board (handed off to driver/pickup flow).
    // =========================================================================
    if ($action === 'get_board') {
        $stationFilter = trim((string)($input['station'] ?? ''));

        // Distinct stacji (menu + bilety KDS + domyślna), żeby UI mogło zawęzić tablicę.
        $stations = [];
        try {
            $seen = [];
            $st1 = $pdo->prepare(
                "SELECT DISTINCT station_id FROM sh_kds_tickets
                 WHERE tenant_id = ? AND station_id IS NOT NULL AND TRIM(station_id) <> ''"
            );
            $st1->execute([$tenant_id]);
            foreach ($st1->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                $sid = (string)$sid;
                $seen[$sid] = true;
            }
            $st2 = $pdo->prepare(
                "SELECT DISTINCT kds_station_id FROM sh_menu_items
                 WHERE tenant_id = ? AND kds_station_id IS NOT NULL AND TRIM(kds_station_id) <> ''"
            );
            $st2->execute([$tenant_id]);
            foreach ($st2->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                $sid = (string)$sid;
                $seen[$sid] = true;
            }
            $seen['KITCHEN_MAIN'] = true;
            $stations = array_keys($seen);
            sort($stations, SORT_STRING);
        } catch (\Throwable $e) {
            $stations = ['KITCHEN_MAIN'];
        }

        $orderSql =
            "SELECT o.id, o.order_number, o.order_type, o.status, o.source,
                    o.delivery_address, o.customer_name, o.customer_phone,
                    o.payment_method, o.payment_status, o.grand_total,
                    o.promised_time, o.created_at,
                    COALESCE(o.kitchen_ticket_printed, 0) AS kitchen_ticket_printed
             FROM sh_orders o
             WHERE o.tenant_id = :tid
               AND o.status IN ('new','accepted','preparing')";

        $orderParams = [':tid' => $tenant_id];

        if ($stationFilter !== '') {
            $orderSql .=
                " AND o.id IN (
                    SELECT ol.order_id
                    FROM sh_order_lines ol
                    LEFT JOIN sh_kds_tickets kt ON kt.id = ol.kds_ticket_id AND kt.tenant_id = :tid_kt
                    LEFT JOIN sh_menu_items mi ON mi.ascii_key = ol.item_sku AND mi.tenant_id = :tid_mi
                    WHERE COALESCE(kt.station_id, NULLIF(mi.kds_station_id, ''), 'KITCHEN_MAIN') = :st_f
                  )";
            $orderParams[':tid_kt'] = $tenant_id;
            $orderParams[':tid_mi'] = $tenant_id;
            $orderParams[':st_f'] = $stationFilter;
        }

        $orderSql .=
            " ORDER BY
               CASE o.status
                 WHEN 'new'       THEN 0
                 WHEN 'accepted'  THEN 1
                 WHEN 'preparing' THEN 2
                 ELSE 9
               END,
               o.created_at ASC";

        $orders = $pdo->prepare($orderSql);
        $orders->execute($orderParams);
        $rows = $orders->fetchAll(PDO::FETCH_ASSOC);

        $oids = array_column($rows, 'id');
        $lmap = [];
        if (count($oids) > 0) {
            $ph = [];
            $prm = [':tid_l1' => $tenant_id, ':tid_l2' => $tenant_id];
            foreach ($oids as $i => $oid) {
                $k = ":o{$i}";
                $ph[] = $k;
                $prm[$k] = $oid;
            }
            $ls = $pdo->prepare(
                "SELECT ol.order_id,
                        ol.id AS line_id,
                        ol.snapshot_name,
                        ol.quantity,
                        ol.comment,
                        ol.modifiers_json,
                        ol.removed_ingredients_json,
                        COALESCE(ol.driver_action_type,'none') AS driver_action_type,
                        COALESCE(kt.station_id, NULLIF(mi.kds_station_id, ''), 'KITCHEN_MAIN') AS _station_res
                 FROM sh_order_lines ol
                 LEFT JOIN sh_kds_tickets kt ON kt.id = ol.kds_ticket_id AND kt.tenant_id = :tid_l1
                 LEFT JOIN sh_menu_items mi ON mi.ascii_key = ol.item_sku AND mi.tenant_id = :tid_l2
                 WHERE ol.order_id IN (" . implode(',', $ph) . ")
                 ORDER BY ol.id ASC"
            );
            $ls->execute($prm);
            foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $res = (string)($l['_station_res'] ?? 'KITCHEN_MAIN');
                unset($l['_station_res']);
                if ($stationFilter !== '' && $res !== $stationFilter) {
                    continue;
                }
                $lmap[$l['order_id']][] = $l;
            }
        }

        if ($stationFilter !== '') {
            $rows = array_values(array_filter($rows, static function (array $r) use ($lmap): bool {
                return !empty($lmap[$r['id'] ?? '']);
            }));
        }

        foreach ($rows as &$r) {
            $r['lines'] = $lmap[$r['id']] ?? [];
        }
        unset($r);

        kdsResponse(true, ['orders' => $rows, 'stations' => $stations, 'station_filter' => $stationFilter]);
    }

    // =========================================================================
    // ACTION: bump_order — Advance order status along canonical pipeline.
    // Allowed transitions (kitchen scope):
    //   new       → accepted
    //   accepted  → preparing
    //   preparing → ready
    // Also writes sh_order_audit row (old/new status, user_id).
    // =========================================================================
    if ($action === 'bump_order') {
        $orderId   = trim((string)($input['order_id'] ?? ''));
        $newStatus = trim((string)($input['new_status'] ?? ''));

        $validTransitions = [
            'new'       => ['accepted'],
            'accepted'  => ['preparing'],
            'preparing' => ['ready'],
        ];

        if (!$orderId || !in_array($newStatus, ['accepted','preparing','ready'], true)) {
            kdsResponse(false, null, 'Invalid order_id or new_status (expected: accepted|preparing|ready)');
        }

        $cur = $pdo->prepare("SELECT status FROM sh_orders WHERE id = :oid AND tenant_id = :tid");
        $cur->execute([':oid' => $orderId, ':tid' => $tenant_id]);
        $currentStatus = $cur->fetchColumn();

        if (!$currentStatus) {
            kdsResponse(false, null, 'Order not found');
        }

        if (!in_array($newStatus, $validTransitions[$currentStatus] ?? [], true)) {
            kdsResponse(false, null, "Cannot transition from '{$currentStatus}' to '{$newStatus}'");
        }

        try {
            $pdo->beginTransaction();

            // Ustaw timestamp lifecycle dla danego przejścia
            $tsExtra = '';
            if ($newStatus === 'accepted') $tsExtra = ', accepted_at = NOW()';
            elseif ($newStatus === 'ready')    $tsExtra = ', ready_at = NOW()';

            $pdo->prepare(
                "UPDATE sh_orders SET status = :ns, updated_at = NOW() {$tsExtra}
                 WHERE id = :oid AND tenant_id = :tid"
            )->execute([':ns' => $newStatus, ':oid' => $orderId, ':tid' => $tenant_id]);

            $pdo->prepare(
                "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
                 VALUES (:oid, :uid, :os, :ns, NOW())"
            )->execute([
                ':oid' => $orderId,
                ':uid' => $user_id ?? null,
                ':os'  => $currentStatus,
                ':ns'  => $newStatus,
            ]);

            // [m026] Publish lifecycle event przed commitem.
            $publisherPath = __DIR__ . '/../../core/OrderEventPublisher.php';
            if (file_exists($publisherPath)) {
                require_once $publisherPath;
                if (class_exists('OrderEventPublisher')) {
                    $eventMap = [
                        'accepted'  => 'order.accepted',
                        'preparing' => 'order.preparing',
                        'ready'     => 'order.ready',
                    ];
                    $eventType = $eventMap[$newStatus] ?? null;
                    if ($eventType) {
                        OrderEventPublisher::publishOrderLifecycle(
                            $pdo, $tenant_id, $eventType, $orderId,
                            ['from_status' => $currentStatus, 'to_status' => $newStatus],
                            [
                                'source'         => 'kds',
                                'actorType'      => 'staff',
                                'actorId'        => (string)($user_id ?? ''),
                                'idempotencyKey' => $orderId . ':' . $eventType . ':' . $currentStatus,
                            ]
                        );
                    }
                }
            }

            $pdo->commit();
        } catch (\Throwable $tx) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[KDS bump] ' . $tx->getMessage());
            kdsResponse(false, null, 'Transition failed');
        }

        kdsResponse(true, ['order_id' => $orderId, 'status' => $newStatus, 'previous' => $currentStatus]);
    }

    // =========================================================================
    // ACTION: recall_order — rollback ready→preparing (mistake recovery).
    // Used e.g. gdy kucharz kliknął "GOTOWE" przedwcześnie.
    // =========================================================================
    if ($action === 'recall_order') {
        $orderId = trim((string)($input['order_id'] ?? ''));
        if (!$orderId) kdsResponse(false, null, 'Invalid order_id');

        $cur = $pdo->prepare("SELECT status FROM sh_orders WHERE id = :oid AND tenant_id = :tid");
        $cur->execute([':oid' => $orderId, ':tid' => $tenant_id]);
        $currentStatus = $cur->fetchColumn();
        if ($currentStatus !== 'ready') {
            kdsResponse(false, null, "Cannot recall from '{$currentStatus}'. Only 'ready' may be recalled.");
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE sh_orders SET status='preparing', updated_at=NOW() WHERE id=:oid AND tenant_id=:tid")
                ->execute([':oid'=>$orderId,':tid'=>$tenant_id]);
            $pdo->prepare("INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp) VALUES (:oid,:uid,'ready','preparing',NOW())")
                ->execute([':oid'=>$orderId,':uid'=>$user_id ?? null]);

            $publisherPath = __DIR__ . '/../../core/OrderEventPublisher.php';
            if (file_exists($publisherPath)) {
                require_once $publisherPath;
                if (class_exists('OrderEventPublisher')) {
                    OrderEventPublisher::publishOrderLifecycle(
                        $pdo, $tenant_id, 'order.recalled', $orderId,
                        ['from_status' => 'ready', 'to_status' => 'preparing'],
                        [
                            'source'         => 'kds',
                            'actorType'      => 'staff',
                            'actorId'        => (string)($user_id ?? ''),
                            'idempotencyKey' => $orderId . ':order.recalled:' . date('YmdHis'),
                        ]
                    );
                }
            }

            $pdo->commit();
        } catch (\Throwable $tx) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            kdsResponse(false, null, 'Recall failed');
        }

        kdsResponse(true, ['order_id' => $orderId, 'status' => 'preparing']);
    }

    kdsResponse(false, null, "Unknown action: {$action}");

} catch (\Throwable $e) {
    error_log('[KDS Engine] ' . $e->getMessage());
    http_response_code(500);
    kdsResponse(false, null, 'Internal server error');
}
