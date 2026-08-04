<?php
// =============================================================================
// SliceHub Enterprise — ChoiceQR Table Orders (Get Table Orders URL)
// GET /api/integrations/choiceqr/table_orders.php?t=SECRET_TOKEN&varSymbol=ID&area=TABLE_ID
//
// ChoiceQR wywołuje ten endpoint GET aby pobrać zamówienia przy stoliku.
// Używane dla QR płatności przy stoliku (opcjonalny URL w panelu ChoiceQR).
//
// Zwraca array zamówień zgodny z tableOrderSchema (pos/index.md):
//   [
//     {
//       "_id": "internal_order_id",
//       "items": [
//         { "_id": "line_id", "posID": "sku", "name": "Dish", "count": 1,
//           "price": 5000, "type": "dish", "parent": null },
//         { "_id": "line_id-opt-0", "posID": "mod_posID", "name": "Option",
//           "count": 1, "price": 300, "type": "option", "parent": "line_id" }
//       ],
//       "table": { "_id": "table_5", "name": "Stolik 12" },
//       "waiter": null,
//       "allowUseDiscount": false
//     }
//   ]
//
// P2.3 compliance:
//   - K3: area param "table_N" → N = sh_tables.id (PK), JOIN sh_tables by id
//         (areas.php eksportuje posID='table_{sh_tables.id}')
//   - NC6: item schema zgodna z tableOrderSchema (_id, posID, name, count, price,
//          type, parent). Modyfikatory z modifiers_json → osobne itemy type="option".
//          Order-level: tylko _id, items, table, waiter, allowUseDiscount.
// =============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

function cqr_to_error(int $code, string $msg): never
{
    http_response_code($code);
    error_log('[ChoiceQR TableOrders] ' . $code . ' — ' . $msg);
    echo json_encode(['error' => $msg]);
    exit;
}

try {
    require_once __DIR__ . '/../../../core/db_config.php';
    require_once __DIR__ . '/../../../core/CredentialVault.php';

    if (!isset($pdo)) {
        cqr_to_error(500, 'Database connection unavailable');
    }

    // -------------------------------------------------------------------------
    // 1. AUTH + TENANT MAPPING (shared pattern)
    // -------------------------------------------------------------------------
    $providedToken = trim((string)($_GET['t'] ?? ''));
    $varSymbol = trim((string)($_GET['varSymbol'] ?? ''));
    $areaId = trim((string)($_GET['area'] ?? ''));

    if ($providedToken === '') {
        cqr_to_error(401, 'Missing token');
    }
    if ($varSymbol === '') {
        cqr_to_error(400, 'Missing varSymbol');
    }

    $stmtTi = $pdo->prepare(
        "SELECT id, tenant_id, credentials, is_active
         FROM sh_tenant_integrations
         WHERE provider = 'choiceqr' AND is_active = 1"
    );
    $stmtTi->execute();
    $integrations = $stmtTi->fetchAll(PDO::FETCH_ASSOC);

    $tenantId = 0;
    $webhookToken = null;

    foreach ($integrations as $ti) {
        $credRaw = (string)$ti['credentials'];
        $credJson = CredentialVault::isEncrypted($credRaw) ? (CredentialVault::decrypt($credRaw) ?? '') : $credRaw;
        $creds = json_decode($credJson, true);
        if (!is_array($creds)) {
            $creds = [];
        }
        if ((string)($creds['var_symbol'] ?? '') === $varSymbol) {
            $tenantId = (int)$ti['tenant_id'];
            $webhookToken = (string)($creds['webhook_token'] ?? '');
            break;
        }
    }

    if ($tenantId <= 0) {
        cqr_to_error(403, "No tenant integration for varSymbol='{$varSymbol}'");
    }
    if ($webhookToken === '' || !hash_equals($webhookToken, $providedToken)) {
        cqr_to_error(401, 'Invalid webhook token');
    }

    // -------------------------------------------------------------------------
    // 2. QUERY TABLE ORDERS
    // -------------------------------------------------------------------------
    // area param (K3 — Opcja A): "table_N" → N = sh_tables.id (PK).
    // areas.php eksportuje posID='table_{sh_tables.id}', więc N = PK stolika.
    // sh_orders łączy się ze stolikiem przez kolumnę table_id (FK → sh_tables.id)
    // lub (legacy) table_number. "zone_N" / "takeaway" / "delivery" → brak filtru.
    $tablePkId = null;
    if (preg_match('/^table_(\d+)$/', $areaId, $m)) {
        $tablePkId = (int)$m[1];
    }

    // Feature-detect: sh_orders.table_id (FK → sh_tables.id) — preferowana ścieżka
    $hasTableId = false;
    try {
        $pdo->query("SELECT table_id FROM sh_orders LIMIT 0");
        $hasTableId = true;
    } catch (PDOException $e) {
        $hasTableId = false;
    }

    // Feature-detect: sh_orders.table_number (legacy, bez FK)
    $hasTableNumber = false;
    try {
        $pdo->query("SELECT table_number FROM sh_orders LIMIT 0");
        $hasTableNumber = true;
    } catch (PDOException $e) {
        $hasTableNumber = false;
    }

    // Feature-detect: sh_tables table (do JOIN w ścieżce legacy table_number)
    $hasTablesTable = false;
    try {
        $pdo->query("SELECT 1 FROM sh_tables LIMIT 0")->closeCursor();
        $hasTablesTable = true;
    } catch (PDOException $e) {
        $hasTablesTable = false;
    }

    $params = [':tid' => $tenantId];

    // Ścieżka 1 (preferowana): sh_orders.table_id = N (bezpośredni FK do sh_tables.id).
    // areas.php wysyła table_{sh_tables.id}, sh_orders.table_id = sh_tables.id → match bezpośredni.
    if ($tablePkId !== null && $hasTableId) {
        $sql = "SELECT o.id, o.order_number, o.subtotal, o.grand_total, o.tip_amount,
                       o.status, o.payment_status, o.payment_method, o.order_type,
                       o.created_at, o.updated_at, o.table_id,
                       t.id AS table_pk_id, t.table_number AS table_label
                FROM sh_orders o
                LEFT JOIN sh_tables t
                  ON t.id = o.table_id AND t.tenant_id = o.tenant_id
                WHERE o.tenant_id = :tid
                  AND o.order_type = 'dine_in'
                  AND o.status NOT IN ('completed', 'cancelled')
                  AND o.table_id = :table_pk_id
                ORDER BY o.created_at DESC LIMIT 50";
        $params[':table_pk_id'] = $tablePkId;
    }
    // Ścieżka 2 (legacy): sh_orders.table_number + JOIN sh_tables po table_number.
    // Dla instalacji ze starszą schema (table_number zamiast table_id).
    elseif ($tablePkId !== null && $hasTableNumber && $hasTablesTable) {
        $sql = "SELECT o.id, o.order_number, o.subtotal, o.grand_total, o.tip_amount,
                       o.status, o.payment_status, o.payment_method, o.order_type,
                       o.created_at, o.updated_at, o.table_number,
                       t.id AS table_pk_id, t.table_number AS table_label
                FROM sh_orders o
                JOIN sh_tables t
                  ON t.table_number = o.table_number AND t.tenant_id = o.tenant_id
                WHERE o.tenant_id = :tid
                  AND o.order_type = 'dine_in'
                  AND o.status NOT IN ('completed', 'cancelled')
                  AND t.id = :table_pk_id
                ORDER BY o.created_at DESC LIMIT 50";
        $params[':table_pk_id'] = $tablePkId;
    }
    // Ścieżka 3 (legacy bez sh_tables): filtr po table_number = N (zachowanie pre-P2.3).
    elseif ($tablePkId !== null && $hasTableNumber) {
        $sql = "SELECT id, order_number, subtotal, grand_total, tip_amount,
                       status, payment_status, payment_method, order_type,
                       created_at, updated_at, table_number
                FROM sh_orders
                WHERE tenant_id = :tid
                  AND order_type = 'dine_in'
                  AND status NOT IN ('completed', 'cancelled')
                  AND table_number = :tn
                ORDER BY created_at DESC LIMIT 50";
        $params[':tn'] = (string)$tablePkId;
    }
    // Ścieżka 4: brak filtru tabeli (zone_N / takeaway / delivery / brak area).
    else {
        $sql = "SELECT id, order_number, subtotal, grand_total, tip_amount,
                       status, payment_status, payment_method, order_type,
                       created_at, updated_at";
        if ($hasTableId) {
            $sql .= ", table_id";
        }
        if ($hasTableNumber) {
            $sql .= ", table_number";
        }
        $sql .= " FROM sh_orders
                  WHERE tenant_id = :tid
                    AND order_type = 'dine_in'
                    AND status NOT IN ('completed', 'cancelled')
                  ORDER BY created_at DESC LIMIT 50";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // -------------------------------------------------------------------------
    // 3. FORMAT OUTPUT (tableOrderSchema — pos/index.md)
    // -------------------------------------------------------------------------
    $result = [];

    // Batch fetch all order lines (avoid N+1). Pobieramy id + modifiers_json
    // bo tableOrderSchema wymaga _id per item, a modyfikatory rozwijamy do
    // osobnych itemów type="option" z parent=line.id.
    $orderIds = array_map(fn($o) => (string)$o['id'], $orders);
    $linesByOrder = [];
    if (!empty($orderIds)) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $stmtLines = $pdo->prepare(
            "SELECT id, order_id, item_sku, snapshot_name, unit_price,
                    quantity, line_total, vat_rate, modifiers_json
             FROM sh_order_lines
             WHERE order_id IN ({$placeholders})"
        );
        $stmtLines->execute($orderIds);
        foreach ($stmtLines->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $linesByOrder[(string)$l['order_id']][] = $l;
        }
    }

    foreach ($orders as $o) {
        $orderId = (string)$o['id'];
        $lines = $linesByOrder[$orderId] ?? [];

        $items = [];
        foreach ($lines as $l) {
            $lineId = (string)$l['id'];
            $lineName = (string)($l['snapshot_name'] !== '' ? $l['snapshot_name'] : $l['item_sku']);

            // Dish item (tableOrderSchema: _id, posID, name, count, price, type, parent)
            $items[] = [
                '_id'    => $lineId,
                'posID'  => (string)$l['item_sku'],
                'name'   => $lineName,
                'count'  => (int)$l['quantity'],
                'price'  => (int)$l['unit_price'],
                'type'   => 'dish',
                'parent' => null,
            ];

            // Modifiers (modifiers_json) → osobne itemy type="option", parent=line.id
            // Format modifiers_json (z webhook.php): surowy menuOptions z ChoiceQR:
            //   [{ posID, item, optionName.en.name, itemName.en.name, count, price, total }]
            $modsJson = $l['modifiers_json'] ?? null;
            $mods = is_string($modsJson) ? json_decode($modsJson, true) : null;
            if (is_array($mods) && count($mods) > 0) {
                foreach ($mods as $idx => $mod) {
                    if (!is_array($mod)) {
                        continue;
                    }
                    $modPosId = trim((string)($mod['posID'] ?? ''));
                    if ($modPosId === '') {
                        $modPosId = trim((string)($mod['item'] ?? '')) ?: 'mod';
                    }
                    // Display name: itemName.en.name > optionName.en.name > posID
                    $modName = '';
                    if (is_array($mod['itemName'] ?? null)) {
                        $modName = trim((string)($mod['itemName']['en']['name'] ?? ''));
                    }
                    if ($modName === '' && is_array($mod['optionName'] ?? null)) {
                        $modName = trim((string)($mod['optionName']['en']['name'] ?? ''));
                    }
                    if ($modName === '') {
                        $modName = $modPosId;
                    }

                    $items[] = [
                        '_id'    => $lineId . '-opt-' . $idx,
                        'posID'  => $modPosId,
                        'name'   => $modName,
                        'count'  => (int)($mod['count'] ?? 1),
                        'price'  => (int)($mod['price'] ?? 0),
                        'type'   => 'option',
                        'parent' => $lineId,
                    ];
                }
            }
        }

        // Table object (tableOrderSchema: table._id = posID stolika, table.name).
        // K3 Opcja A: posID stolika = 'table_{sh_tables.id}' — spójne z areas.php.
        // Preferujemy table_pk_id z JOIN (ścieżka 1/2). Fallback: table_id lub
        // table_number z sh_orders (gdy JOIN nie został wykonany — ścieżka 3/4).
        $tableObj = null;
        $tablePkForPosId = null;
        $tableLabel = null;

        if (!empty($o['table_pk_id'])) {
            $tablePkForPosId = (string)$o['table_pk_id'];
            $tableLabel = (string)($o['table_label'] ?? '');
        } elseif (!empty($o['table_id'])) {
            $tablePkForPosId = (string)$o['table_id'];
        } elseif (!empty($o['table_number'])) {
            // Legacy: table_number jako posID (mniej precyzyjne, ale działa gdy
            // sh_tables nie istnieje). table_number służy też jako label.
            $tablePkForPosId = (string)$o['table_number'];
            $tableLabel = (string)$o['table_number'];
        }

        if ($tablePkForPosId !== null && $tablePkForPosId !== '') {
            $tableObj = [
                '_id'  => 'table_' . $tablePkForPosId,
                'name' => 'Stolik ' . ($tableLabel !== '' ? $tableLabel : $tablePkForPosId),
            ];
        }

        // tableOrderSchema order-level: _id, items, table, waiter, allowUseDiscount.
        // Usunięto num, type, subTotal, total, tips, status, payBy, currency
        // (nie są w tableOrderSchema — schema validation może je odrzucić).
        $result[] = [
            '_id'              => $orderId,
            'items'            => $items,
            'table'            => $tableObj,
            'waiter'           => null,
            'allowUseDiscount' => false,
        ];
    }

    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;

} catch (Throwable $e) {
    error_log('[ChoiceQR TableOrders] FATAL: ' . $e->getMessage());
    cqr_to_error(500, 'Internal server error');
}
