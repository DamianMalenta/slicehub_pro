<?php
// =============================================================================
// STATUS: DOMKNIĘTE 2026-07-30 (Faza E, Prawo VIII) + rozszerzenie 2026-08-20.
// Konsumenci: modules/backoffice/order_edit/ (Edytuj zamówienie) oraz
//   modules/hub/ modal "Edytuj zamówienie" (hub_order_edit.js)
//   + api/orders/get_for_edit.php (dane dla modala).
//   POST api/orders/edit.php  { order_id, channel, order_type, delivery_address?, lines:[{line_id,item_sku,quantity}] }
// Od 2026-08-20 przyjmuje też order_type (dine_in/takeaway/delivery) — zmiana typu
// zapisywana w kitchen_delta.order_type {old,new}; delivery wymaga delivery_address.
// Uses DeltaEngine to detect kitchen changes and write structured sh_orders.kitchen_delta JSON.
// KDS consumer: api/kds/engine.php#get_board zwraca kitchen_delta + edited_since_print;
// modules/kds/js/kds_app.js highlightuje linie (zielony=dodane, żółty=zmienione, czerwony=usunięte)
//   + blok ZMIANY z przyciskiem ACK (ack_changes).
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
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use POST.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/../../core/Uuid.php';
    require_once __DIR__ . '/../../core/OrderEventPublisher.php';
    require_once __DIR__ . '/../../core/OrderStateMachine.php';
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
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $orderId = trim($input['order_id'] ?? '');
    if ($orderId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'order_id is required.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // edit_scope: 'lines' (default, Faza E) | 'metadata' (Faza 2) | 'payment_method' (Faza 2)
    $editScope = strtolower(trim((string)($input['edit_scope'] ?? 'lines')));
    if (!in_array($editScope, ['lines', 'metadata', 'payment_method'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid edit_scope. Use: lines | metadata | payment_method.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // =========================================================================
    // 2. LOAD EXISTING ORDER (status guard + tenant isolation)
    // =========================================================================
    $stmtOrder = $pdo->prepare(
        "SELECT id, status, channel, order_type, delivery_address,
                customer_name, customer_phone, notes,
                payment_status, payment_method,
                fiscal_receipt_number, receipt_printed
         FROM sh_orders
         WHERE id = :id AND tenant_id = :tid
         LIMIT 1"
    );
    $stmtOrder->execute([':id' => $orderId, ':tid' => $tenant_id]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // =========================================================================
    // 2b. PHASE 2 BRANCH — metadata / payment_method edit on closed orders
    //     (RFC-001 §4.3 Poziom 2). Lines edit (default) continues below.
    // =========================================================================
    if ($editScope === 'metadata' || $editScope === 'payment_method') {
        // ── RBAC: owner / admin / manager ────────────────────────────────
        $roleStmt = $pdo->prepare(
            'SELECT LOWER(role) FROM sh_users WHERE id = :uid AND tenant_id = :tid AND is_deleted = 0 LIMIT 1'
        );
        $roleStmt->execute([':uid' => $user_id, ':tid' => $tenant_id]);
        $role = strtolower((string)$roleStmt->fetchColumn());
        if (!in_array($role, ['owner', 'admin', 'manager'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden: wymagana rola owner/admin/manager.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Status must be completed or cancelled for metadata/payment edit
        if (!in_array($order['status'], ['completed', 'cancelled'], true)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => "Edycja metadanych dozwolona tylko dla zamówień zamkniętych (completed/cancelled). Aktualny status: '{$order['status']}'."], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($editScope === 'metadata') {
            _handleMetadataEdit($pdo, $tenant_id, $user_id, $orderId, $order, $input);
        } else {
            _handlePaymentMethodChange($pdo, $tenant_id, $user_id, $orderId, $order, $input);
        }
        // _handle* functions exit() — unreachable
    }

    $terminalStatuses = ['completed', 'cancelled'];
    if (in_array($order['status'], $terminalStatuses, true)) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => "Cannot edit order in '{$order['status']}' status.",
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // =========================================================================
    // 3. LOAD EXISTING ORDER LINES
    // =========================================================================
    $stmtOldLines = $pdo->prepare(
        "SELECT id, item_sku, snapshot_name, unit_price, quantity, line_total,
                vat_rate, vat_amount, modifiers_json, removed_ingredients_json, comment
         FROM sh_order_lines
         WHERE order_id = :oid
           AND order_id IN (SELECT id FROM sh_orders WHERE tenant_id = :tid)"
    );
    $stmtOldLines->execute([':oid' => $orderId, ':tid' => $tenant_id]);
    $oldLines = $stmtOldLines->fetchAll(PDO::FETCH_ASSOC);

    // =========================================================================
    // 4. SECURE RECALCULATION
    // =========================================================================
    $editInput = $input;
    $editInput['channel']    = $editInput['channel']    ?? $order['channel'];
    $editInput['order_type'] = $editInput['order_type'] ?? $order['order_type'];
    // Faza 1: backoffice edit = internal (widzi is_secret — edycja istniejącego zamówienia).
    $editInput['is_internal'] = true;

    $newOrderType     = (string)$editInput['order_type'];
    $orderTypeChanged = $newOrderType !== $order['order_type'];

    $deliveryAddress = array_key_exists('delivery_address', $input)
        ? trim((string)$input['delivery_address'])
        : null;

    if ($newOrderType === 'delivery') {
        $effectiveAddress = $deliveryAddress !== null
            ? $deliveryAddress
            : trim((string)($order['delivery_address'] ?? ''));
        if ($effectiveAddress === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Zamówienie z dostawą wymaga adresu (delivery_address).'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    $calc = CartEngine::calculate($pdo, $tenant_id, $editInput);

    // =========================================================================
    // 5. COMPUTE DELTA
    // =========================================================================
    $delta = DeltaEngine::computeDelta($oldLines, $calc['lines_raw']);

    if (empty($delta) && !$orderTypeChanged) {
        echo json_encode([
            'success' => true,
            'message' => 'No changes detected.',
            'data'    => ['order_id' => $orderId, 'delta' => null],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($orderTypeChanged) {
        $delta['order_type'] = ['old' => $order['order_type'], 'new' => $newOrderType];
    }

    $deltaJson = json_encode($delta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
                 order_type        = :otype,
                 delivery_address  = COALESCE(:addr, delivery_address),
                 edited_since_print = 1,
                 kitchen_delta     = :delta,
                 updated_at        = :now
             WHERE id = :id AND tenant_id = :tid"
        );
        $stmtUpdateOrder->execute([
            ':otype'    => $newOrderType,
            ':addr'     => $deliveryAddress,
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
                "DELETE FROM sh_order_lines
                 WHERE id = :id AND order_id = :oid
                   AND order_id IN (SELECT id FROM sh_orders WHERE tenant_id = :tid)"
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
                 WHERE id = :id AND order_id = :oid
                   AND order_id IN (SELECT id FROM sh_orders WHERE tenant_id = :tid)"
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
                        $assignedTicketId = Uuid::v4();
                        $stmtNewTicket->execute([
                            ':id'      => $assignedTicketId,
                            ':tid'     => $tenant_id,
                            ':oid'     => $orderId,
                            ':station' => $stationId,
                        ]);
                    }
                }

                $stmtIns->execute([
                    ':id'        => Uuid::v4(),
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

        // — 7f. Publish outbox event (in transaction) —————————————————————
        OrderEventPublisher::publishOrderLifecycle(
            $pdo,
            $tenant_id,
            'order.edited',
            $orderId,
            [
                'source'             => 'order_edit',
                'user_id'            => $user_id,
                'kitchen_delta'      => $delta,
                'order_type_changed' => $orderTypeChanged,
                'new_order_type'     => $orderTypeChanged ? $newOrderType : null,
            ]
        );

        // — 7g. COMMIT ———————————————————————————————————————————————————
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
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (CartEngineException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log('[OrderEdit] PDOException: ' . $e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log('[OrderEdit] ' . $e->getMessage());
}

exit;

// =============================================================================
// PHASE 2 HANDLERS (RFC-001 §4.3 — Poziom 2: metadata + payment_method edit)
// Wywoływane z gałęzi edit_scope w głównym bloku try. Każda funkcja wykonuje
// własną transakcję, loguje do sh_order_logs, publikuje event do outboxa i
// exit() z odpowiedzią JSON.
// =============================================================================

/**
 * Edycja metadanych niefiskalnych na zamkniętym zamówieniu (completed/cancelled).
 * Aktualizuje: customer_name, customer_phone, delivery_address, notes, order_type.
 * NIE dotyka: pozycji, grand_total, payment_status, fiscal_receipt_number.
 *
 * @param array $order  Wiersz z sh_orders (status, order_type, customer_name, ...)
 * @param array $input  Surowy payload JSON z żądania
 */
function _handleMetadataEdit(PDO $pdo, int $tenantId, int $userId, string $orderId, array $order, array $input): void
{
    $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];

    // Dozwolone pola metadanych (whitelist)
    $allowedFields = ['customer_name', 'customer_phone', 'delivery_address', 'notes', 'order_type'];
    $changes = [];
    $setClauses = [];
    $params = [':id' => $orderId, ':tid' => $tenantId];

    foreach ($allowedFields as $field) {
        if (!array_key_exists($field, $metadata)) {
            continue;
        }
        $newVal = trim((string)$metadata[$field]);

        // Walidacja order_type
        if ($field === 'order_type') {
            if (!in_array($newVal, ['dine_in', 'takeaway', 'delivery'], true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'order_type musi być: dine_in | takeaway | delivery.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            // delivery wymaga adresu
            if ($newVal === 'delivery') {
                $addr = array_key_exists('delivery_address', $metadata)
                    ? trim((string)$metadata['delivery_address'])
                    : trim((string)($order['delivery_address'] ?? ''));
                if ($addr === '') {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Zamówienie z dostawą wymaga adresu (delivery_address).'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    exit;
                }
            }
        }

        $oldVal = trim((string)($order[$field] ?? ''));
        if ($newVal === $oldVal) {
            continue; // brak zmiany
        }

        $paramKey = ':' . $field;
        $setClauses[] = "{$field} = {$paramKey}";
        $params[$paramKey] = $newVal !== '' ? $newVal : null;
        $changes[] = [
            'field' => $field,
            'old'   => $oldVal,
            'new'   => $newVal,
        ];
    }

    if (empty($changes)) {
        echo json_encode([
            'success' => true,
            'message' => 'No metadata changes detected.',
            'data'    => ['order_id' => $orderId, 'edit_scope' => 'metadata', 'changes' => []],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $now = date('Y-m-d H:i:s');
    $setClauses[] = 'updated_at = :now';
    $params[':now'] = $now;

    $setSql = implode(', ', $setClauses);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE sh_orders SET {$setSql} WHERE id = :id AND tenant_id = :tid");
        $stmt->execute($params);

        // Audit trail (old_status = new_status — marker edycji metadanych)
        $stmtAudit = $pdo->prepare(
            "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
             VALUES (:oid, :uid, :old, :new, :now)"
        );
        $stmtAudit->execute([
            ':oid' => $orderId,
            ':uid' => $userId,
            ':old' => $order['status'],
            ':new' => $order['status'],
            ':now' => $now,
        ]);

        // Structured log — action='metadata.edit', detail_json z old/new per field
        OrderStateMachine::writeLog($pdo, $orderId, $tenantId, $userId, 'metadata.edit', [
            'fields'  => $changes,
            'scope'   => 'metadata',
        ]);

        // Publikuj event do outboxa (w tej samej transakcji)
        OrderEventPublisher::publishOrderLifecycle(
            $pdo,
            $tenantId,
            'order.metadata_edited',
            $orderId,
            [
                'source'    => 'order_edit',
                'user_id'   => $userId,
                'changes'   => $changes,
            ],
            ['source' => 'edit', 'actorType' => 'staff', 'actorId' => (string)$userId]
        );

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    echo json_encode([
        'success' => true,
        'data'    => [
            'order_id'    => $orderId,
            'edit_scope'  => 'metadata',
            'changes'     => $changes,
            'audit_logged' => true,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Zmiana formy płatności cash ↔ card na zamkniętym zamówieniu (completed).
 * Aktualizuje: sh_orders.payment_status, sh_orders.payment_method, sh_order_payments.method.
 * Bezpieczniki: tylko cash↔card, nie online_paid, nie sfiskalizowane (fiscal_receipt_number IS NULL).
 *
 * @param array $order  Wiersz z sh_orders (payment_status, payment_method, fiscal_receipt_number, ...)
 * @param array $input  Surowy payload JSON z żądania
 */
function _handlePaymentMethodChange(PDO $pdo, int $tenantId, int $userId, string $orderId, array $order, array $input): void
{
    $newMethod = strtolower(trim((string)($input['payment_status'] ?? '')));
    $reason    = trim((string)($input['reason'] ?? ''));
    $oldMethod = strtolower((string)$order['payment_status']);

    // Walidacja: tylko cash ↔ card
    if (!in_array($newMethod, ['cash', 'card'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Nowa forma płatności musi być: cash lub card. Online_paid wymaga integracji z bramką.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!in_array($oldMethod, ['cash', 'card'], true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => "Zmiana płatności dozwolona tylko z cash/card. Aktualna forma: '{$oldMethod}'."], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($newMethod === $oldMethod) {
        echo json_encode([
            'success' => true,
            'message' => 'No payment change detected.',
            'data'    => ['order_id' => $orderId, 'edit_scope' => 'payment_method', 'old' => $oldMethod, 'new' => $newMethod],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Bezpiecznik fiskalny: fiscal_receipt_number musi być NULL (nie sfiskalizowane)
    if (!empty($order['fiscal_receipt_number'])) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Zmiana formy płatności zablokowana — zamówienie jest sfiskalizowane (fiscal_receipt_number istnieje).'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $now = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        // 1. Aktualizuj nagłówek zamówienia
        $stmtUpd = $pdo->prepare(
            "UPDATE sh_orders
             SET payment_status = :ps, payment_method = :pm, updated_at = :now
             WHERE id = :id AND tenant_id = :tid"
        );
        $stmtUpd->execute([
            ':ps'  => $newMethod,
            ':pm'  => $newMethod,
            ':now' => $now,
            ':id'  => $orderId,
            ':tid' => $tenantId,
        ]);

        // 2. Aktualizuj istniejące wpisy w sh_order_payments (cash→card lub card→cash)
        //    Suma amount_grosze się nie zmienia — tylko method/payment_method.
        $stmtPay = $pdo->prepare(
            "UPDATE sh_order_payments
             SET method = :method, payment_method = :pm
             WHERE order_id = :oid AND tenant_id = :tid"
        );
        $stmtPay->execute([
            ':method' => $newMethod,
            ':pm'     => $newMethod,
            ':oid'    => $orderId,
            ':tid'    => $tenantId,
        ]);

        // 3. Audit trail (marker edycji — status bez zmian)
        $stmtAudit = $pdo->prepare(
            "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
             VALUES (:oid, :uid, :old, :new, :now)"
        );
        $stmtAudit->execute([
            ':oid' => $orderId,
            ':uid' => $userId,
            ':old' => $order['status'],
            ':new' => $order['status'],
            ':now' => $now,
        ]);

        // 4. Structured log — action='payment.change'
        OrderStateMachine::writeLog($pdo, $orderId, $tenantId, $userId, 'payment.change', [
            'old'    => $oldMethod,
            'new'    => $newMethod,
            'reason' => $reason !== '' ? $reason : null,
            'scope'  => 'payment_method',
        ]);

        // 5. Publikuj event do outboxa
        OrderEventPublisher::publishOrderLifecycle(
            $pdo,
            $tenantId,
            'order.payment_changed',
            $orderId,
            [
                'source'       => 'order_edit',
                'user_id'      => $userId,
                'old_payment'  => $oldMethod,
                'new_payment'  => $newMethod,
                'reason'       => $reason !== '' ? $reason : null,
            ],
            ['source' => 'edit', 'actorType' => 'staff', 'actorId' => (string)$userId]
        );

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    echo json_encode([
        'success' => true,
        'data'    => [
            'order_id'       => $orderId,
            'edit_scope'     => 'payment_method',
            'old_payment'    => $oldMethod,
            'new_payment'    => $newMethod,
            'audit_logged'   => true,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
