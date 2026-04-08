<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$response = ["status" => "error", "payload" => null, "message" => "Wystąpił błąd serwera."];

try {
    require_once '../../core/db_config.php';
    
    if (!isset($pdo)) {
        throw new Exception("Brak połączenia z bazą danych.");
    }

    $headers = apache_request_headers();
    $authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $tenantId = null;
    
    if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches) && !empty($matches[1])) {
        $tenantId = 1; 
    }

    if (!$tenantId) {
        throw new Exception("Odmowa dostępu. Brak autoryzacji.");
    }

    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true);
    if (!is_array($input)) {
        throw new Exception("Nieprawidłowy format danych wejściowych (oczekiwano JSON).");
    }

    $action = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['action'] ?? '');

    switch ($action) {
        case 'get_recipes_init':
            // DODANO: pobieranie kolumny search_aliases
            $stmt = $pdo->query("SELECT sku, name, base_unit, search_aliases FROM sys_items");
            $productsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $products = array_map(function($p) {
                return [
                    'sku' => $p['sku'],
                    'name' => $p['name'],
                    'baseUnit' => $p['base_unit'],
                    'aliases' => $p['search_aliases'] // DODANO: przekazanie aliasów
                ];
            }, $productsRaw);

            $response['status'] = 'success';
            $response['payload'] = ['products' => $products];
            $response['message'] = "Pobrano inicjalną listę surowców.";
            break;

        case 'get_item_recipe':
            $menuItemSku = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['menuItemSku'] ?? '');
            if (empty($menuItemSku)) {
                throw new Exception("Brak identyfikatora (SKU) dania.");
            }

            $stmt = $pdo->prepare("
                SELECT r.id, r.warehouse_sku, r.quantity_base, r.waste_percent, r.is_packaging, 
                       s.name, s.base_unit 
                FROM sh_recipes r 
                JOIN sys_items s ON r.warehouse_sku = s.sku 
                WHERE r.menu_item_sku = ? AND r.tenant_id = ?
            ");
            $stmt->execute([$menuItemSku, $tenantId]);
            $recipesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $ingredients = array_map(function($r) {
                return [
                    'id' => (int)$r['id'],
                    'warehouseSku' => $r['warehouse_sku'],
                    'quantityBase' => (float)$r['quantity_base'],
                    'wastePercent' => (float)$r['waste_percent'],
                    'isPackaging' => (bool)$r['is_packaging'],
                    'name' => $r['name'],
                    'baseUnit' => $r['base_unit']
                ];
            }, $recipesRaw);

            $response['status'] = 'success';
            $response['payload'] = ['ingredients' => $ingredients];
            $response['message'] = "Pobrano recepturę dania.";
            break;

        case 'save_recipe':
            $menuItemSku = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['menuItemSku'] ?? '');
            $ingredients = $input['ingredients'] ?? [];

            if (empty($menuItemSku)) {
                throw new Exception("Brak identyfikatora (SKU) dania.");
            }
            if (!is_array($ingredients)) {
                throw new Exception("Nieprawidłowy format przesyłanej receptury.");
            }

            $pdo->beginTransaction();

            try {
                $stmtDelete = $pdo->prepare("DELETE FROM sh_recipes WHERE menu_item_sku = ? AND tenant_id = ?");
                $stmtDelete->execute([$menuItemSku, $tenantId]);

                if (!empty($ingredients)) {
                    $stmtInsert = $pdo->prepare("
                        INSERT INTO sh_recipes (tenant_id, menu_item_sku, warehouse_sku, quantity_base, waste_percent, is_packaging) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");

                    foreach ($ingredients as $ing) {
                        $warehouseSku = preg_replace('/[^a-zA-Z0-9_-]/', '', $ing['warehouseSku'] ?? '');
                        $quantityBase = floatval($ing['quantityBase'] ?? 0);
                        $wastePercent = floatval($ing['wastePercent'] ?? 0);
                        $isPackaging = filter_var($ing['isPackaging'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

                        if (empty($warehouseSku) || $quantityBase <= 0) {
                            continue;
                        }

                        $stmtInsert->execute([
                            $tenantId, 
                            $menuItemSku, 
                            $warehouseSku, 
                            $quantityBase, 
                            $wastePercent, 
                            $isPackaging
                        ]);
                    }
                }

                $pdo->commit();
                $response['status'] = 'success';
                $response['message'] = "Receptura została bezpiecznie zapisana.";
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        default:
            throw new Exception("Nieznana akcja API.");
    }
} catch (Exception $e) {
    $response['status'] = 'error';
    $response['payload'] = null;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
?>