<?php
// =============================================================================
// SliceHub Enterprise — Order Detail (Read-Only) Endpoint
// GET /api/orders/get.php?order_id=...
//
// Returns a single order with its lines for the "Edytuj zamówienie" form
// (Faza E). Read-only — no mutation. Used by modules/backoffice/order_edit/.
//
// Lines expose `line_id` (= sh_order_lines.id) so the edit form can preserve
// existing lines and let DeltaEngine match by line_id (added/removed/modified).
//
// Schema: sh_orders, sh_order_lines
// =============================================================================
// STATUS: DOMKNIĘTE 2026-07-30 (Faza E, Prawo VIII).
// Consumer: modules/backoffice/order_edit/ (Edytuj zamówienie).
// =============================================================================

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use GET.']);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $orderId = trim($_GET['order_id'] ?? '');
    if ($orderId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required query parameter: order_id.']);
        exit;
    }

    // -------------------------------------------------------------------------
    // 1. ORDER HEADER (tenant isolation — Prawo VI)
    // -------------------------------------------------------------------------
    $stmtOrder = $pdo->prepare(
        "SELECT id, order_number, channel, order_type, source, status,
                payment_status, payment_method, grand_total, subtotal,
                discount_amount, delivery_fee, customer_name, customer_phone,
                delivery_address, promised_time, edited_since_print,
                kitchen_delta, created_at, updated_at
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

    // -------------------------------------------------------------------------
    // 2. ORDER LINES (line_id = id, for DeltaEngine matching)
    // -------------------------------------------------------------------------
    $stmtLines = $pdo->prepare(
        "SELECT id AS line_id, item_sku, snapshot_name, unit_price, quantity,
                line_total, vat_rate, vat_amount, modifiers_json,
                removed_ingredients_json, comment
         FROM sh_order_lines
         WHERE order_id = :oid
           AND order_id IN (SELECT id FROM sh_orders WHERE tenant_id = :tid)
         ORDER BY id ASC"
    );
    $stmtLines->execute([':oid' => $orderId, ':tid' => $tenant_id]);
    $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

    // Decode JSON fields to native structures for the frontend.
    foreach ($lines as &$l) {
        $l['unit_price']   = (int)$l['unit_price'];
        $l['quantity']     = (int)$l['quantity'];
        $l['line_total']   = (int)$l['line_total'];
        $l['vat_rate']     = (float)$l['vat_rate'];
        $l['vat_amount']   = (int)$l['vat_amount'];
        $l['modifiers']           = $l['modifiers_json']           ? json_decode($l['modifiers_json'],           true) : [];
        $l['removed_ingredients'] = $l['removed_ingredients_json'] ? json_decode($l['removed_ingredients_json'], true) : [];
        // Keep raw JSON too for transparency.
    }
    unset($l);

    // kitchen_delta → decoded (may be null)
    $kitchenDelta = null;
    if (!empty($order['kitchen_delta'])) {
        $kitchenDelta = json_decode($order['kitchen_delta'], true);
    }

    $fmtMoney = fn(int $g): string => number_format($g / 100, 2, '.', '');

    echo json_encode([
        'success' => true,
        'data'    => [
            'order' => [
                'id'                 => $order['id'],
                'order_number'       => $order['order_number'],
                'channel'            => $order['channel'],
                'order_type'         => $order['order_type'],
                'source'             => $order['source'],
                'status'             => $order['status'],
                'payment_status'     => $order['payment_status'],
                'payment_method'     => $order['payment_method'],
                'grand_total'        => $fmtMoney((int)$order['grand_total']),
                'subtotal'           => $fmtMoney((int)$order['subtotal']),
                'discount_amount'    => $fmtMoney((int)$order['discount_amount']),
                'delivery_fee'       => $fmtMoney((int)$order['delivery_fee']),
                'customer_name'      => $order['customer_name'],
                'customer_phone'     => $order['customer_phone'],
                'delivery_address'   => $order['delivery_address'],
                'promised_time'      => $order['promised_time'],
                'edited_since_print' => (bool)(int)$order['edited_since_print'],
                'kitchen_delta'      => $kitchenDelta,
                'created_at'         => $order['created_at'],
                'updated_at'         => $order['updated_at'],
            ],
            'lines' => $lines,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
    error_log('[OrderGet] PDOException: ' . $e->getMessage());

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[OrderGet] ' . $e->getMessage());
}

exit;
