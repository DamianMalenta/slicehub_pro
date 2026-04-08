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
    
    // Symulacja weryfikacji tokena JWT
    if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches) && !empty($matches[1])) {
        $tenantId = 1; 
    }

    if (!$tenantId) {
        throw new Exception("Odmowa dostępu. Brak poprawnego tokena autoryzacji.");
    }

    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true);
    if (!is_array($input)) {
        throw new Exception("Nieprawidłowy format danych wejściowych (oczekiwano JSON).");
    }

    $action = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['action'] ?? '');

    // Helper: Puste wartości na NULL dla bazy danych
    $toNull = function($val) { return ($val === '' || $val === null) ? null : $val; };

    switch ($action) {
        
        // ==============================================================================
        // 0. DODAWANIE KATEGORII (Z łatką na display_order)
        // ==============================================================================
        case 'add_category':
            $catName = trim($input['name'] ?? '');
            if (empty($catName)) {
                throw new Exception("Nazwa kategorii nie może być pusta.");
            }
            
            // Wymuszamy domyślne 0 dla display_order
            $stmt = $pdo->prepare("INSERT INTO sh_categories (tenant_id, name, is_menu, display_order) VALUES (?, ?, 1, 0)");
            $stmt->execute([$tenantId, $catName]);
            
            $response['status'] = 'success';
            $response['payload'] = ['categoryId' => $pdo->lastInsertId()];
            $response['message'] = "Dodano nową kategorię.";
            break;

        // ==============================================================================
        // 1. POBIERANIE DRZEWA MENU (Z uwzględnieniem Macierzy Cenowej)
        // ==============================================================================
        case 'get_menu_tree':
            $stmtCat = $pdo->prepare("SELECT id, name FROM sh_categories WHERE tenant_id = ? AND is_menu = 1 AND is_deleted = 0 ORDER BY display_order ASC, id ASC");
            $stmtCat->execute([$tenantId]);
            $categoriesRaw = $stmtCat->fetchAll(PDO::FETCH_ASSOC);

            // Pobieramy dania bez płaskiej ceny (zgodnie z nową architekturą)
            $stmtItems = $pdo->prepare("SELECT id, category_id, name, ascii_key, is_active, badge_type, is_secret, stock_count, vat_rate_dine_in, kds_station_id, is_locked_by_hq FROM sh_menu_items WHERE tenant_id = ? AND is_deleted = 0 ORDER BY display_order ASC, name ASC");
            $stmtItems->execute([$tenantId]);
            $itemsRaw = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            // Pobieramy całą Macierz Cenową dla dań (żeby wkleić ją do drzewka)
            $stmtTiers = $pdo->prepare("SELECT target_sku, channel, price FROM sh_price_tiers WHERE target_type = 'ITEM'");
            $stmtTiers->execute();
            $allTiers = $stmtTiers->fetchAll(PDO::FETCH_ASSOC);
            
            $tiersBySku = [];
            foreach ($allTiers as $tier) {
                $tiersBySku[$tier['target_sku']][] = [
                    'channel' => $tier['channel'],
                    'price' => (float)$tier['price']
                ];
            }

            $categories = array_map(function($c) {
                return ['id' => (int)$c['id'], 'name' => $c['name']];
            }, $categoriesRaw);

            $categories = array_map(function($c) {
                return ['id' => (int)$c['id'], 'name' => $c['name']];
            }, $categoriesRaw);

            $items = array_map(function($i) use ($tiersBySku) {
                return [
                    'id' => (int)$i['id'],
                    'categoryId' => (int)$i['category_id'],
                    'name' => $i['name'],
                    'asciiKey' => $i['ascii_key'],
                    'isActive' => (bool)$i['is_active'],
                    'badgeType' => $i['badge_type'],
                    'isSecret' => (bool)$i['is_secret'],
                    'stockCount' => (int)$i['stock_count'],
                    'vatRate' => (float)$i['vat_rate_dine_in'],
                    'kdsStationId' => $i['kds_station_id'],
                    'isLockedByHq' => (bool)$i['is_locked_by_hq'],
                    'priceTiers' => $tiersBySku[$i['ascii_key']] ?? [] 
                ];
            }, $itemsRaw);

            // --- ŁATKA: POBIERANIE MODYFIKATORÓW ---
            $stmtMods = $pdo->prepare("SELECT id, name, ascii_key FROM sh_modifier_groups WHERE tenant_id = ? AND is_deleted = 0 ORDER BY id ASC");
            $stmtMods->execute([$tenantId]);
            $modifierGroupsRaw = $stmtMods->fetchAll(PDO::FETCH_ASSOC);
            
            $modifierGroups = array_map(function($g) {
                return [
                    'id' => (int)$g['id'], 
                    'name' => $g['name'], 
                    'asciiKey' => $g['ascii_key']
                ];
            }, $modifierGroupsRaw);
            // ---------------------------------------

            $response['status'] = 'success';
            // Zwracamy kategorie, dania ORAZ grupy modyfikatorów!
            $response['payload'] = ['categories' => $categories, 'items' => $items, 'modifierGroups' => $modifierGroups];
            $response['message'] = "Pobrano drzewo menu.";
            break;

        // ==============================================================================
        // 2. POBIERANIE SZCZEGÓŁÓW DANIA (Dla Edytora po prawej stronie)
        // ==============================================================================
        case 'get_item_details':
            $itemId = intval($input['itemId'] ?? 0);
            if ($itemId <= 0) throw new Exception("Nieprawidłowe ID elementu.");

            $stmt = $pdo->prepare("SELECT id, category_id, name, ascii_key, is_active, vat_rate_dine_in as vat_rate, kds_station_id, is_locked_by_hq, publication_status, valid_from, valid_to, description, image_url, marketing_tags FROM sh_menu_items WHERE id = ? AND tenant_id = ? AND is_deleted = 0");
            $stmt->execute([$itemId, $tenantId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) throw new Exception("Nie znaleziono dania.");

            // Pobieranie cen z Macierzy dla tego konkretnego dania
            $stmtPrice = $pdo->prepare("SELECT channel, price FROM sh_price_tiers WHERE target_type = 'ITEM' AND target_sku = ?");
            $stmtPrice->execute([$item['ascii_key']]);
            $prices = $stmtPrice->fetchAll(PDO::FETCH_ASSOC);

            $priceMatrix = [];
            $priceTiers = [];
            foreach ($prices as $p) {
                $priceMatrix[$p['channel']] = (float)$p['price'];
                $priceTiers[] = ['channel' => $p['channel'], 'price' => (float)$p['price']];
            }

            // Pobranie przypisanych modyfikatorów
            $stmtMods = $pdo->prepare("SELECT group_id FROM sh_item_modifiers WHERE item_id = ?");
            $stmtMods->execute([$itemId]);
            $modifierGroupIds = $stmtMods->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $item['modifierGroupIds'] = array_map('intval', $modifierGroupIds);

            $response['status'] = 'success';
            $response['payload'] = [
                'id' => (int)$item['id'],
                'categoryId' => (int)$item['category_id'],
                'name' => $item['name'],
                'asciiKey' => $item['ascii_key'],
                'isActive' => (bool)$item['is_active'],
                'vatRate' => (float)$item['vat_rate'],
                'kdsStationId' => $item['kds_station_id'],
                'isLockedByHq' => (bool)$item['is_locked_by_hq'],
                'publicationStatus' => $item['publication_status'],
                'validFrom' => $item['valid_from'],
                'validTo' => $item['valid_to'],
                'description' => $item['description'],
                'imageUrl' => $item['image_url'],
                'marketingTags' => $item['marketing_tags'],
                'modifierGroupIds' => $item['modifierGroupIds'],
                'priceMatrix' => $priceMatrix, // Kompatybilność z formularzem
                'priceTiers' => $priceTiers // Czysta architektura
            ];
            $response['message'] = "Pobrano szczegóły dania.";
            break;

        // ==============================================================================
        // 3. DODAWANIE / AKTUALIZACJA DANIA
        // ==============================================================================
        case 'add_item':
        case 'update_item_full':
            $itemId = intval($input['itemId'] ?? 0);
            $categoryId = intval($input['categoryId'] ?? 0);
            $name = trim($input['name'] ?? ''); 
            $asciiKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['asciiKey'] ?? '');
            
            $vatRate = floatval($input['vatRate'] ?? 0);
            $kdsStationId = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['kdsStationId'] ?? 'NONE');
            $isActive = isset($input['isActive']) ? (int)filter_var($input['isActive'], FILTER_VALIDATE_BOOLEAN) : 1;
            
            // Nowe pola temporal i marketing
            $pubStatus = in_array($input['publicationStatus'] ?? '', ['Draft', 'Live', 'Archived']) ? $input['publicationStatus'] : 'Draft';
            $validFrom = $toNull($input['validFrom'] ?? null);
            $validTo = $toNull($input['validTo'] ?? null);
            $description = trim($input['description'] ?? '');
            $imageUrl = trim($input['imageUrl'] ?? '');
            $marketingTags = trim($input['marketingTags'] ?? '');
            
            $priceTiers = $input['priceTiers'] ?? [];

            if ($categoryId <= 0 || empty($name) || empty($asciiKey)) {
                throw new Exception("Brakujące lub nieprawidłowe dane przedmiotu (Nazwa, SKU, Kategoria).");
            }

            $pdo->beginTransaction();

            try {
                if ($action === 'add_item') {
                    $stmt = $pdo->prepare("INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, is_active, vat_rate_dine_in, vat_rate_takeaway, kds_station_id, publication_status, valid_from, valid_to, description, image_url, marketing_tags) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$tenantId, $categoryId, $name, $asciiKey, $isActive, $vatRate, $vatRate, $kdsStationId, $pubStatus, $validFrom, $validTo, $description, $imageUrl, $marketingTags]);
                    $itemId = $pdo->lastInsertId();
                } else {
                    $stmt = $pdo->prepare("UPDATE sh_menu_items SET name = ?, ascii_key = ?, category_id = ?, is_active = ?, vat_rate_dine_in = ?, vat_rate_takeaway = ?, kds_station_id = ?, publication_status = ?, valid_from = ?, valid_to = ?, description = ?, image_url = ?, marketing_tags = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ? AND is_deleted = 0");
                    $stmt->execute([$name, $asciiKey, $categoryId, $isActive, $vatRate, $vatRate, $kdsStationId, $pubStatus, $validFrom, $validTo, $description, $imageUrl, $marketingTags, $itemId, $tenantId]);
                }

                // Bezpieczny Upsert do Macierzy Cenowej (sh_price_tiers)
                $stmtTier = $pdo->prepare("INSERT INTO sh_price_tiers (target_type, target_sku, channel, price) VALUES ('ITEM', ?, ?, ?) ON DUPLICATE KEY UPDATE price = ?");
                foreach ($priceTiers as $tier) {
                    $channel = $tier['channel'] ?? 'POS';
                    $price = floatval($tier['price'] ?? 0);
                    $stmtTier->execute([$asciiKey, $channel, $price, $price]);
                }

                // Wyczyść stare przypisania
                $stmtDeleteMods = $pdo->prepare("DELETE FROM sh_item_modifiers WHERE item_id = ?");
                $stmtDeleteMods->execute([$itemId]);

                // Zapisz nowe przypisania z payloadu
                $modifierGroupIds = $input['modifierGroupIds'] ?? [];
                if (!empty($modifierGroupIds) && is_array($modifierGroupIds)) {
                    $stmtInsertMod = $pdo->prepare("INSERT INTO sh_item_modifiers (item_id, group_id) VALUES (?, ?)");
                    foreach ($modifierGroupIds as $groupId) {
                        $stmtInsertMod->execute([$itemId, intval($groupId)]);
                    }
                }

                $pdo->commit();
                $response['status'] = 'success';
                $response['payload'] = ['id' => $itemId];
                $response['message'] = $action === 'add_item' ? "Dodano nowe danie do bazy." : "Zaktualizowano danie (Omnichannel).";
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        // ==============================================================================
        // 4. EDYTOR MASOWY (Temporal Tables + Macierz)
        // ==============================================================================
        case 'save_bulk':
            $itemIds = $input['itemIds'] ?? [];
            if (!is_array($itemIds) || empty($itemIds)) throw new Exception("Brak ID do edycji masowej.");
            
            $cleanIds = array_filter(array_map('intval', $itemIds));
            if (empty($cleanIds)) throw new Exception("Nieprawidłowe ID przedmiotów.");
            
            $pdo->beginTransaction();

            try {
                $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
                $params = [];
                $updates = [];

                // Standardowe pola z Bulk
                $kdsGroup = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['kdsGroup'] ?? '');
                if ($kdsGroup !== '') { $updates[] = "kds_station_id = ?"; $params[] = $kdsGroup; }
                
                $badgeType = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['badgeType'] ?? '');
                if ($badgeType !== '') { $updates[] = "badge_type = ?"; $params[] = $badgeType; }

                if (isset($input['isSecret']) && $input['isSecret'] !== '') {
                    $updates[] = "is_secret = ?"; $params[] = filter_var($input['isSecret'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                }

                // Obsługa Temporal Tables (Harmonogram Masowy)
                if (!empty($input['temporalPublicationPatch']) && !empty($input['temporalPublicationPatch']['apply'])) {
                    $patch = $input['temporalPublicationPatch'];
                    if ($patch['status'] !== 'NO_CHANGE' && in_array($patch['status'], ['Draft', 'Live', 'Archived'])) {
                        $updates[] = "publication_status = ?"; $params[] = $patch['status'];
                    }
                    if (array_key_exists('validFrom', $patch)) {
                        $updates[] = "valid_from = ?"; $params[] = $toNull($patch['validFrom']);
                    }
                    if (array_key_exists('validTo', $patch)) {
                        $updates[] = "valid_to = ?"; $params[] = $toNull($patch['validTo']);
                    }
                }

                if (!empty($updates)) {
                    $sql = "UPDATE sh_menu_items SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE tenant_id = ? AND is_deleted = 0 AND id IN ($placeholders)";
                    $finalParams = array_merge($params, [$tenantId], $cleanIds);
                    $stmtUpdate = $pdo->prepare($sql);
                    $stmtUpdate->execute($finalParams);
                }

                // Obsługa Omnichannel (Zarządzanie wybranym kanałem)
                if (!empty($input['omnichannelPricePatch']) && !empty($input['omnichannelPricePatch']['apply'])) {
                    $patch = $input['omnichannelPricePatch'];
                    $targetChannel = $patch['targetChannel'];
                    $opType = $patch['operationType'];
                    $opValue = (float)$patch['operationValue'];

                    // Wyciągamy SKU zaznaczonych dań, żeby wiedzieć w co uderzyć w Macierzy
                    $stmtSku = $pdo->prepare("SELECT ascii_key FROM sh_menu_items WHERE tenant_id = ? AND is_deleted = 0 AND id IN ($placeholders)");
                    $stmtSku->execute(array_merge([$tenantId], $cleanIds));
                    $skus = $stmtSku->fetchAll(PDO::FETCH_COLUMN);

                    $stmtCurrentPrice = $pdo->prepare("SELECT price FROM sh_price_tiers WHERE target_type='ITEM' AND target_sku=? AND channel=?");
                    $stmtUpsertPrice = $pdo->prepare("INSERT INTO sh_price_tiers (target_type, target_sku, channel, price) VALUES ('ITEM', ?, ?, ?) ON DUPLICATE KEY UPDATE price = ?");

                    foreach ($skus as $sku) {
                        $stmtCurrentPrice->execute([$sku, $targetChannel]);
                        $row = $stmtCurrentPrice->fetch(PDO::FETCH_ASSOC);
                        $currentPrice = $row ? (float)$row['price'] : 0.00;
                        
                        $newPrice = $currentPrice;
                        if ($opType === 'set_amount') $newPrice = $opValue;
                        elseif ($opType === 'increase_percent') $newPrice = $currentPrice * (1 + ($opValue / 100));
                        elseif ($opType === 'increase_pln') $newPrice = $currentPrice + $opValue;

                        // Tarcza poniżej zera
                        if ($newPrice < 0) $newPrice = 0;

                        $stmtUpsertPrice->execute([$sku, $targetChannel, $newPrice, $newPrice]);
                    }
                }

                $pdo->commit();
                $response['status'] = 'success';
                $response['message'] = "Zaktualizowano masowo strukturę Enterprise.";
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        // ==============================================================================
        // 5. ZAPIS GRUPY MODYFIKATORÓW I OPCJI (KSeF + Macierz)
        // ==============================================================================
        case 'save_modifier_group':
            $groupId = intval($input['groupId'] ?? 0);
            $groupAsciiKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['groupAsciiKey'] ?? '');
            $name = trim($input['name'] ?? ''); 
            $minSel = intval($input['minSelection'] ?? 0);
            $maxSel = intval($input['maxSelection'] ?? 1);
            $freeLimit = intval($input['freeLimit'] ?? 0);
            $allowMulti = !empty($input['allowMultiQty']) ? 1 : 0;
            $pubStatus = in_array($input['publicationStatus'] ?? '', ['Draft', 'Live', 'Archived']) ? $input['publicationStatus'] : 'Draft';
            $validFrom = $toNull($input['validFrom'] ?? null);
            $validTo = $toNull($input['validTo'] ?? null);
            
            $options = $input['options'] ?? [];

            if (empty($name) || empty($groupAsciiKey)) throw new Exception("Nazwa grupy oraz SKU Grupy są wymagane.");

            $pdo->beginTransaction();

            try {
                if ($groupId > 0) {
                    $stmtCheck = $pdo->prepare("SELECT id FROM sh_modifier_groups WHERE id = ? AND tenant_id = ? AND is_deleted = 0");
                    $stmtCheck->execute([$groupId, $tenantId]);
                    if (!$stmtCheck->fetch()) throw new Exception("Grupa nie istnieje.");

                    $stmt = $pdo->prepare("UPDATE sh_modifier_groups SET name = ?, min_selection = ?, max_selection = ?, free_limit = ?, allow_multi_qty = ?, publication_status = ?, valid_from = ?, valid_to = ? WHERE id = ? AND tenant_id = ?");
                    $stmt->execute([$name, $minSel, $maxSel, $freeLimit, $allowMulti, $pubStatus, $validFrom, $validTo, $groupId, $tenantId]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection, free_limit, allow_multi_qty, publication_status, valid_from, valid_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$tenantId, $name, $groupAsciiKey, $minSel, $maxSel, $freeLimit, $allowMulti, $pubStatus, $validFrom, $validTo]);
                    $groupId = $pdo->lastInsertId();
                }

                $savedOptionIds = [];
                $stmtInsertOpt = $pdo->prepare("INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku, linked_quantity, is_default) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmtUpdateOpt = $pdo->prepare("UPDATE sh_modifiers SET name = ?, action_type = ?, linked_warehouse_sku = ?, linked_quantity = ?, is_default = ?, is_deleted = 0 WHERE id = ? AND group_id = ?");
                $stmtTier = $pdo->prepare("INSERT INTO sh_price_tiers (target_type, target_sku, channel, price) VALUES ('MODIFIER', ?, ?, ?) ON DUPLICATE KEY UPDATE price = ?");

                foreach ($options as $opt) {
                    $optId = intval($opt['id'] ?? 0);
                    $optName = trim($opt['name'] ?? '');
                    $optAsciiKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $opt['asciiKey'] ?? '');
                    $actionType = in_array($opt['actionType'] ?? '', ['NONE','ADD','REMOVE']) ? $opt['actionType'] : 'NONE';
                    $linkedSku = $toNull($opt['linkedWarehouseSku'] ?? null);
                    $linkedQty = (float)($opt['linkedQuantity'] ?? 0);
                    $isDefault = !empty($opt['isDefault']) ? 1 : 0;
                    $priceTiers = $opt['priceTiers'] ?? [];

                    if (empty($optName) || empty($optAsciiKey)) continue;

                    if ($optId > 0) {
                        $stmtUpdateOpt->execute([$optName, $actionType, $linkedSku, $linkedQty, $isDefault, $optId, $groupId]);
                        $savedOptionIds[] = $optId;
                    } else {
                        $stmtInsertOpt->execute([$groupId, $optName, $optAsciiKey, $actionType, $linkedSku, $linkedQty, $isDefault]);
                        $savedOptionIds[] = $pdo->lastInsertId();
                    }

                    // Zapis cen do Macierzy
                    foreach ($priceTiers as $tier) {
                        $channel = $tier['channel'] ?? 'POS';
                        $price = floatval($tier['price'] ?? 0);
                        $stmtTier->execute([$optAsciiKey, $channel, $price, $price]);
                    }
                }

                // Usuwanie opcji, które zostały skasowane z interfejsu
                if (!empty($savedOptionIds)) {
                    $placeholdersOpt = implode(',', array_fill(0, count($savedOptionIds), '?'));
                    $stmtDelOpt = $pdo->prepare("UPDATE sh_modifiers SET is_deleted = 1 WHERE group_id = ? AND id NOT IN ($placeholdersOpt)");
                    $stmtDelOpt->execute(array_merge([$groupId], $savedOptionIds));
                } else {
                    $stmtDelOpt = $pdo->prepare("UPDATE sh_modifiers SET is_deleted = 1 WHERE group_id = ?");
                    $stmtDelOpt->execute([$groupId]);
                }

                $pdo->commit();
                $response['status'] = 'success';
                $response['payload'] = ['groupId' => $groupId];
                $response['message'] = "Zapisano grupę modyfikatorów i połączono z KSeF.";
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
        // ==============================================================================
        // 6. POBIERANIE PEŁNEGO DRZEWA MODYFIKATORÓW (Z CZYTANIEM MACIERZY CEN)
        // ==============================================================================
        case 'get_modifiers_full':
            $stmtG = $pdo->prepare("SELECT * FROM sh_modifier_groups WHERE tenant_id = ? AND is_deleted = 0 ORDER BY id ASC");
            $stmtG->execute([$tenantId]);
            $groups = $stmtG->fetchAll(PDO::FETCH_ASSOC);

            $stmtO = $pdo->prepare("SELECT * FROM sh_modifiers WHERE is_deleted = 0");
            $stmtO->execute();
            $options = $stmtO->fetchAll(PDO::FETCH_ASSOC);

            $stmtP = $pdo->prepare("SELECT target_sku, channel, price FROM sh_price_tiers WHERE target_type = 'MODIFIER'");
            $stmtP->execute();
            $prices = $stmtP->fetchAll(PDO::FETCH_ASSOC);

            $pricesBySku = [];
            foreach ($prices as $p) {
                $pricesBySku[$p['target_sku']][] = ['channel' => $p['channel'], 'price' => (float)$p['price']];
            }

            $optionsByGroup = [];
            foreach ($options as $opt) {
                $optionsByGroup[$opt['group_id']][] = [
                    'id' => (int)$opt['id'],
                    'name' => $opt['name'],
                    'asciiKey' => $opt['ascii_key'],
                    'isDefault' => (bool)$opt['is_default'],
                    'actionType' => $opt['action_type'],
                    'linkedWarehouseSku' => $opt['linked_warehouse_sku'],
                    'linkedQuantity' => (float)$opt['linked_quantity'],
                    'priceTiers' => $pricesBySku[$opt['ascii_key']] ?? []
                ];
            }

            $finalGroups = [];
            foreach ($groups as $g) {
                $finalGroups[] = [
                    'id' => (int)$g['id'],
                    'name' => $g['name'],
                    'asciiKey' => $g['ascii_key'],
                    'min' => (int)$g['min_selection'],
                    'max' => (int)$g['max_selection'],
                    'freeLimit' => (int)$g['free_limit'],
                    'multiQty' => (bool)$g['allow_multi_qty'],
                    'publicationStatus' => $g['publication_status'],
                    'validFrom' => $g['valid_from'],
                    'validTo' => $g['valid_to'],
                    'isLockedByHq' => (bool)$g['is_locked_by_hq'],
                    'options' => $optionsByGroup[$g['id']] ?? []
                ];
            }

            $response['status'] = 'success';
            $response['payload'] = $finalGroups;
            break;

        // ==============================================================================
        // TARCZA DIAGNOSTYCZNA (Wyłapuje zmyślone akcje JS)
        // ==============================================================================
        default:
            $unknown = $action ?: 'PUSTA_AKCJA';
            throw new Exception("Nieznana akcja API: [{$unknown}] - Prawdopodobnie stara wersja JS!");
    }
} catch (Exception $e) {
    $response['status'] = 'error';
    $response['payload'] = null;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
?>