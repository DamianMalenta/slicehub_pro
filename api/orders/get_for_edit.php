<?php
declare(strict_types=1);

// =============================================================================
// SliceHub Enterprise — Order Edit: dane dla modala "Edytuj zamówienie"
// POST /api/orders/get_for_edit.php
//
// Consumer: modules/hub/ (admin_hub — modal edycji zamówień).
// Akcje:
//   list_orders — aktywne zamówienia tenanta (bez completed/cancelled)
//   get_order   — nagłówek + linie (z id linii) + katalog dań i modyfikatorów
//                 do dodawania pozycji w modalu.
//
// Zapis edycji: POST /api/orders/edit.php (DeltaEngine + kitchen_delta).
// =============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function editDataResponse(bool $ok, $data = null, ?string $msg = null): void {
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';

    $raw    = file_get_contents('php://input');
    $input  = json_decode($raw ?: '{}', true) ?? [];
    $action = trim((string)($input['action'] ?? ''));

    if ($action === 'list_orders') {
        $stmt = $pdo->prepare(
            "SELECT id, order_number, status, order_type, channel, grand_total,
                    customer_name, created_at, edited_since_print
             FROM sh_orders
             WHERE tenant_id = :tid AND status NOT IN ('completed', 'cancelled')
             ORDER BY created_at DESC
             LIMIT 100"
        );
        $stmt->execute([':tid' => $tenant_id]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orders as &$o) {
            $o['grand_total_formatted'] = number_format(((int)$o['grand_total']) / 100, 2, '.', '');
        }
        unset($o);
        editDataResponse(true, ['orders' => $orders]);
    }

    if ($action === 'get_order') {
        $orderId = trim((string)($input['order_id'] ?? ''));
        if ($orderId === '') {
            editDataResponse(false, null, 'order_id jest wymagane.');
        }

        $stmtOrder = $pdo->prepare(
            "SELECT id, order_number, status, order_type, channel, grand_total,
                    subtotal, discount_amount, delivery_fee, customer_name, delivery_address
             FROM sh_orders
             WHERE id = :id AND tenant_id = :tid
             LIMIT 1"
        );
        $stmtOrder->execute([':id' => $orderId, ':tid' => $tenant_id]);
        $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            editDataResponse(false, null, 'Zamówienie nie istnieje.');
        }

        $stmtLines = $pdo->prepare(
            "SELECT id, item_sku, snapshot_name, unit_price, quantity, line_total,
                    modifiers_json, removed_ingredients_json, comment
             FROM sh_order_lines
             WHERE order_id = :oid
             ORDER BY id ASC"
        );
        $stmtLines->execute([':oid' => $orderId]);
        $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

        // Katalog dań do "dodaj pozycję"
        $stmtItems = $pdo->prepare(
            "SELECT ascii_key AS sku, name
             FROM sh_menu_items
             WHERE tenant_id = :tid AND is_deleted = 0 AND is_active = 1
             ORDER BY name ASC"
        );
        $stmtItems->execute([':tid' => $tenant_id]);
        $menuItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        // Katalog modyfikatorów (grupy tenant-scoped)
        $stmtMods = $pdo->prepare(
            "SELECT m.ascii_key AS sku, m.name
             FROM sh_modifiers m
             JOIN sh_modifier_groups mg ON mg.id = m.group_id
             WHERE mg.tenant_id = :tid AND m.is_deleted = 0
             ORDER BY m.name ASC"
        );
        $stmtMods->execute([':tid' => $tenant_id]);
        $modifiers = $stmtMods->fetchAll(PDO::FETCH_ASSOC);

        editDataResponse(true, [
            'order'      => $order,
            'lines'      => $lines,
            'menu_items' => $menuItems,
            'modifiers'  => $modifiers,
        ]);
    }

    editDataResponse(false, null, 'Nieznana akcja.');

} catch (\PDOException $e) {
    http_response_code(500);
    error_log('[OrderEditData] PDOException: ' . $e->getMessage());
    editDataResponse(false, null, 'Błąd bazy danych.');
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('[OrderEditData] ' . $e->getMessage());
    editDataResponse(false, null, 'Błąd serwera.');
}
