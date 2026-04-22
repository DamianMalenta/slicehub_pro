<?php
// =============================================================================
// STATUS: PLANNED (audit 2026-04-19) — not yet wired, waiting for admin_hub.
// Consumer: admin_hub "Edytuj zamówienie" modal (Faza 3). Uses DeltaEngine to
// detect kitchen changes and write structured sh_orders.kitchen_delta JSON.
// Do NOT delete — referenced historically in _docs/ARCHIWUM/06_WIZJA_MODULU_ONLINE.md §541.
// =============================================================================
// SliceHub Enterprise — Order Edit Endpoint (Kitchen Delta Detection)
// POST /api/orders/edit.php
//
// Receives { "order_id": "uuid", "channel": ..., "order_type": ..., "lines": [...] }
// Recalculates via CartEngine, diffs against persisted lines via DeltaEngine,
// and atomically syncs the database: DELETE removed, UPDATE modified, INSERT added.
//
// Stores a structured kitchen_delta JSON on the order header so KDS can
// highlight exactly what changed since the last print.
//
// Schema: sh_orders, sh_order_lines, sh_order_audit
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
    require_once __DIR__ . '/../cart/CartEngine.php';
    require_once __DIR__ . '/DeltaEngine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    // =========================================================================
    // 1. PARSE INPUT
    // =========================================================================
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
        exit;
    }

    $orderId = trim($input['order_id'] ?? '');
    if ($orderId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'order_id is required.']);
        exit;
    }

    // =========================================================================
    // 2. LOAD EXISTING ORDER (status guard + tenant isolation)
    // =========================================================================
    $stmtOrder = $pdo->prepare(
        "SELECT id, status, channel, order_type
         FROM sh_orders
         WHERE id = :id AND tenant_id = :tid
         LIMIT 1"
    );
    $stmtOrder->execute([':id' => $orderId, ':tid' => $tenant_id]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }

    $terminalStatuses = ['completed', 'cancelled'];
    if (in_array($order['status'], $terminalStatuses, true)) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => "Cannot edit order in '{$order['status']}' status.",
        ]);
        exit;
    }

    // =========================================================================
    // 3. LOAD EXISTING ORDER LINES
    // =========================================================================
    $stmtOldLines = $pdo->prepare(
        "SELECT id, item_sku, snapshot_name, unit_price, quantity, line_total,
                vat_rate, vat_amount, modifiers_json, removed_ingredients_json, comment
         FROM sh_order_lines
         WHERE order_id = :oid"
    );
    $stmtOldLines->execute([':oid' => $orderId]);
    $oldLines = $stmtOldLines->fetchAll(PDO::FETCH_ASSOC);

    // =========================================================================
    // 4. SECURE RECALCULATION
    // =========================================================================
    $editInput = $input;
    $editInput['channel']    = $editInput['channel']    ?? $order['channel'];
    $editInput['order_type'] = $editInput['order_type'] ?? $order['order_type'];

    $calc = CartEngine::calculate($pdo, $tenant_id, $editInput);

    // =========================================================================
    // 5. COMPUTE DELTA
    // =========================================================================
    $delta = DeltaEngine::computeDelta($oldLines, $calc['lines_raw']);

    if (empty($delta)) {
        echo json_encode([
            'success' => true,
            'message' => 'No changes detected.',
            'data'    => ['order_id' => $orderId, 'delta' => null],
        ]);
        exit;
    }

    // =========================================================================
    // 6. HELPERS
    // =========================================================================
    $generateUuidV4 = function (): string {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    };

    $deltaJson = json_encode($delta, JSON_UNESCAPED_UNICODE);
    $now       = date('Y-m-d H:i:s');

    // Build lookup maps for the new lines (keyed by line_id)
    $newLinesById = [];
    foreach ($calc['lines_raw'] as $nl) {
        $lid = $nl['line_id'] ?? null;
        if ($lid !== null) {
            $newLinesById[$lid] = $nl;
        }
    }

    // =========================================================================
    // 7. ATOMIC TRANSACTION
    // =========================================================================
    $pdo->beginTransaction();

    try {
        // — 7a. Update order header financials + delta ————————————————————
        $stmtUpdateOrder = $pdo->prepare(
            "UPDATE sh_orders
             SET subtotal          = :subtotal,
                 discount_amount   = :discount,
                 delivery_fee      = :delivery,
                 grand_total       = :grand,
                 loyalty_points_earned = :points,
                 edited_since_print = 1,
                 kitchen_delta     = :delta,
                 updated_at        = :now
             WHERE id = :id AND tenant_id = :tid"
        );
        $stmtUpdateOrder->execute([
            ':subtotal' => $calc['subtotal_grosze'],
            ':discount' => $calc['discount_grosze'],
            ':delivery' => $calc['delivery_fee_grosze'],
            ':grand'    => $calc['grand_total_grosze'],
            ':points'   => $calc['loyalty_points'],
            ':delta'    => $deltaJson,
            ':now'      => $now,
            ':id'       => $orderId,
            ':tid'      => $tenant_id,
        ]);

        // — 7b. DELETE removed lines —————————————————————————————————————
        if (!empty($delta['removed'])) {
            $stmtDel = $pdo->prepare(
                "DELETE FROM sh_order_lines WHERE id = :id AND order_id = :oid"
            );
            foreach ($delta['removed'] as $rem) {
                $stmtDel->execute([':id' => $rem['line_id'], ':oid' => $orderId]);
            }

            // Clean up orphaned KDS tickets (no remaining lines reference them)
            $stmtOrphan = $pdo->prepare(
                "DELETE FROM sh_kds_tickets
                 WHERE order_id = :oid AND tenant_id = :tid
                   AND id NOT IN (
                       SELECT DISTINCT kds_ticket_id FROM sh_order_lines
                       WHERE order_id = :oid2 AND kds_ticket_id IS NOT NULL
                   )"
            );
            $stmtOrphan->execute([':oid' => $orderId, ':tid' => $tenant_id, ':oid2' => $orderId]);
        }

        // — 7c. UPDATE modified lines ————————————————————————————————————
        if (!empty($delta['modified'])) {
            $stmtUpd = $pdo->prepare(
                "UPDATE sh_order_lines
                 SET unit_price              = :unit,
                     quantity                = :qty,
                     line_total              = :total,
                     vat_rate                = :vat_rate,
                     vat_amount              = :vat_amt,
                     modifiers_json          = :mods,
                     removed_ingredients_json = :removed,
                     comment                 = :comment
                 WHERE id = :id AND order_id = :oid"
            );

            foreach ($delta['modified'] as $mod) {
                $lid = $mod['line_id'];
                $nl  = $newLinesById[$lid];

                $stmtUpd->execute([
                    ':unit'     => $nl['unit_price_grosze'],
                    ':qty'      => $nl['quantity'],
                    ':total'    => $nl['line_total_grosze'],
                    ':vat_rate' => $nl['vat_rate'],
                    ':vat_amt'  => $nl['vat_amount_grosze'],
                    ':mods'     => $nl['modifiers_json'],
                    ':removed'  => $nl['removed_ingredients_json'],
                    ':comment'  => $nl['comment'],
                    ':id'       => $lid,
                    ':oid'      => $orderId,
                ]);
            }
        }

        // — 7d. INSERT added lines (WITH KDS BRIDGE) ———————————————————————
        if (!empty($delta['added'])) {
            $isKdsActive = in_array($order['status'], ['accepted', 'preparing'], true);

            $stmtStation = $pdo->prepare(
                "SELECT COALESCE(NULLIF(kds_station_id, ''), 'KITCHEN_MAIN')
                 FROM sh_menu_items WHERE ascii_key = :sku AND tenant_id = :tid"
            );
            $stmtFindTicket = $pdo->prepare(
                "SELECT id FROM sh_kds_tickets
                 WHERE order_id = :oid AND tenant_id = :tid AND station_id = :station AND status != 'done' LIMIT 1"
            );
            $stmtNewTicket = $pdo->prepare(
                "INSERT INTO sh_kds_tickets (id, tenant_id, order_id, station_id, status)
                 VALUES (:id, :tid, :oid, :station, 'pending')"
            );

            $stmtIns = $pdo->prepare(
                "INSERT INTO sh_order_lines
                    (id, order_id, item_sku, snapshot_name, unit_price,
                     quantity, line_total, vat_rate, vat_amount,
                     modifiers_json, removed_ingredients_json, comment, kds_ticket_id)
                 VALUES
                    (:id, :oid, :sku, :name, :unit, :qty, :total, :vat_rate, :vat_amt,
                     :mods, :removed, :comment, :ticket_id)"
            );

            foreach ($calc['lines_raw'] as $nl) {
                $lid = $nl['line_id'] ?? null;
                if ($lid !== null && isset($newLinesById[$lid])) {
                    continue;
                }

                $assignedTicketId = null;

                if ($isKdsActive) {
                    $stmtStation->execute([':sku' => $nl['item_sku'], ':tid' => $tenant_id]);
                    $stationId = $stmtStation->fetchColumn() ?: 'KITCHEN_MAIN';

                    $stmtFindTicket->execute([':oid' => $orderId, ':tid' => $tenant_id, ':station' => $stationId]);
                    $activeTicketId = $stmtFindTicket->fetchColumn();

                    if ($activeTicketId) {
                        $assignedTicketId = $activeTicketId;
                    } else {
                        $assignedTicketId = $generateUuidV4();
                        $stmtNewTicket->execute([
                            ':id'      => $assignedTicketId,
                            ':tid'     => $tenant_id,
                            ':oid'     => $orderId,
                            ':station' => $stationId,
                        ]);
                    }
                }

                $stmtIns->execute([
                    ':id'        => $generateUuidV4(),
                    ':oid'       => $orderId,
                    ':sku'       => $nl['item_sku'],
                    ':name'      => $nl['snapshot_name'],
                    ':unit'      => $nl['unit_price_grosze'],
                    ':qty'       => $nl['quantity'],
                    ':total'     => $nl['line_total_grosze'],
                    ':vat_rate'  => $nl['vat_rate'],
                    ':vat_amt'   => $nl['vat_amount_grosze'],
                    ':mods'      => $nl['modifiers_json'],
                    ':removed'   => $nl['removed_ingredients_json'],
                    ':comment'   => $nl['comment'],
                    ':ticket_id' => $assignedTicketId,
                ]);
            }
        }

        // — 7e. Audit trail (edit = same status → same status) ———————————
        $stmtAudit = $pdo->prepare(
            "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
             VALUES (:oid, :uid, :old, :new, :now)"
        );
        $stmtAudit->execute([
            ':oid' => $orderId,
            ':uid' => $user_id,
            ':old' => $order['status'],
            ':new' => $order['status'],
            ':now' => $now,
        ]);

        // — 7f. COMMIT ———————————————————————————————————————————————————
        $pdo->commit();

    } catch (Throwable $txErr) {
        $pdo->rollBack();
        throw $txErr;
    }

    // =========================================================================
    // 8. SUCCESS RESPONSE
    // =========================================================================
    $fmtMoney = fn(int $g): string => number_format($g / 100, 2, '.', '');

    echo json_encode([
        'success' => true,
        'data'    => [
            'order_id'    => $orderId,
            'grand_total' => $fmtMoney($calc['grand_total_grosze']),
            'delta'       => $delta,
            'cart'        => $calc['response'],
        ],
    ]);

} catch (CartEngineException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
    error_log('[OrderEdit] PDOException: ' . $e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[OrderEdit] ' . $e->getMessage());
}

exit;
