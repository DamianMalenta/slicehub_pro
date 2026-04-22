<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$response = ["success" => false, "data" => null, "message" => "Wystąpił błąd serwera."];

try {
    require_once '../../core/db_config.php';
    require_once '../../core/auth_guard.php';
    require_once '../../core/AssetResolver.php';
    if (!isset($pdo)) {
        throw new Exception("Brak połączenia z bazą danych.");
    }

    /**
     * Kasuje poprzednie linki m021 dla dwóch ról i tworzy nowe (warstwa + hero dodatku).
     */
    $syncModifierVisualAssetLinks = static function (
        PDO $pdo,
        int $tenantId,
        string $modifierSku,
        ?int $layerAssetId,
        ?int $heroAssetId
    ): void {
        if (!AssetResolver::isReady($pdo) || $modifierSku === '') {
            return;
        }
        $del = $pdo->prepare(
            "DELETE FROM sh_asset_links WHERE tenant_id = ? AND entity_type = 'modifier'
             AND entity_ref = ? AND role IN ('layer_top_down','modifier_hero')"
        );
        $del->execute([$tenantId, $modifierSku]);

        $slots = ['layer_top_down' => $layerAssetId, 'modifier_hero' => $heroAssetId];
        $ins = $pdo->prepare(
            "INSERT INTO sh_asset_links (tenant_id, asset_id, entity_type, entity_ref, role, sort_order, is_active)
             VALUES (?, ?, 'modifier', ?, ?, 0, 1)"
        );
        foreach ($slots as $role => $aid) {
            if ($aid === null || $aid <= 0) {
                continue;
            }
            $chk = $pdo->prepare(
                "SELECT id FROM sh_assets WHERE id = ? AND is_active = 1 AND deleted_at IS NULL
                 AND (tenant_id = 0 OR tenant_id = ?) LIMIT 1"
            );
            $chk->execute([(int)$aid, $tenantId]);
            if (!$chk->fetch(PDO::FETCH_ASSOC)) {
                continue;
            }
            $ins->execute([$tenantId, (int)$aid, $modifierSku, $role]);
        }
    };

    /** Czy sh_atelier_scenes ma kolumny M022 (scene_kind, parent_category_id). */
    $atelierHasCategoryCols = false;
    try {
        $pdo->query('SELECT scene_kind, parent_category_id FROM sh_atelier_scenes LIMIT 0');
        $atelierHasCategoryCols = true;
    } catch (PDOException $e) {
    }

    /**
     * Tworzy lub podpina scenę kategorii (scene_kind=category) i zwraca jej ID.
     */
    $ensureCategoryAtelierScene = static function (
        PDO $pdo,
        int $tenantId,
        int $categoryId,
        bool $hasCatCols
    ): int {
        if ($categoryId <= 0) {
            throw new Exception('Nieprawidłowe ID kategorii.');
        }
        $sentinel = '__CAT_SCENE_' . $categoryId;
        if (strlen($sentinel) > 64) {
            $sentinel = '__C_' . $categoryId;
        }

        $defaultSpec = [
            'version'       => 1,
            'kind'          => 'category_table',
            'template_key'  => 'category_flat_table',
            'placements'    => [],
        ];

        try {
            $stmtCat = $pdo->prepare(
                'SELECT category_scene_id FROM sh_categories WHERE tenant_id = ? AND id = ? LIMIT 1'
            );
            $stmtCat->execute([$tenantId, $categoryId]);
            $catRow = $stmtCat->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new Exception('Migracja M022 (kolumna category_scene_id) nie jest dostępna.');
        }
        if (!$catRow) {
            throw new Exception('Kategoria nie istnieje.');
        }
        $existingSid = isset($catRow['category_scene_id']) ? (int)$catRow['category_scene_id'] : 0;

        if ($existingSid > 0) {
            $chk = $pdo->prepare('SELECT id FROM sh_atelier_scenes WHERE id = ? AND tenant_id = ? LIMIT 1');
            $chk->execute([$existingSid, $tenantId]);
            if ($chk->fetch(PDO::FETCH_ASSOC)) {
                if ($hasCatCols) {
                    $pdo->prepare(
                        "UPDATE sh_atelier_scenes SET scene_kind = 'category', parent_category_id = ?
                         WHERE id = ? AND tenant_id = ?"
                    )->execute([$categoryId, $existingSid, $tenantId]);
                }

                return $existingSid;
            }
        }

        $stmtSent = $pdo->prepare(
            'SELECT id FROM sh_atelier_scenes WHERE tenant_id = ? AND item_sku = ? LIMIT 1'
        );
        $stmtSent->execute([$tenantId, $sentinel]);
        $sentRow = $stmtSent->fetch(PDO::FETCH_ASSOC);
        if ($sentRow) {
            $sid = (int)$sentRow['id'];
            try {
                $pdo->prepare('UPDATE sh_categories SET category_scene_id = ? WHERE tenant_id = ? AND id = ?')
                    ->execute([$sid, $tenantId, $categoryId]);
            } catch (\PDOException $e) {
                throw new Exception('Nie można powiązać sceny z kategorią (category_scene_id).');
            }
            if ($hasCatCols) {
                $pdo->prepare(
                    "UPDATE sh_atelier_scenes SET scene_kind = 'category', parent_category_id = ?
                     WHERE id = ? AND tenant_id = ?"
                )->execute([$categoryId, $sid, $tenantId]);
            }

            return $sid;
        }

        $specJson = json_encode($defaultSpec, JSON_UNESCAPED_UNICODE);
        if ($hasCatCols) {
            $ins = $pdo->prepare(
                "INSERT INTO sh_atelier_scenes
                    (tenant_id, item_sku, spec_json, version, scene_kind, parent_category_id)
                 VALUES (?, ?, ?, 1, 'category', ?)"
            );
            $ins->execute([$tenantId, $sentinel, $specJson, $categoryId]);
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO sh_atelier_scenes (tenant_id, item_sku, spec_json, version) VALUES (?, ?, ?, 1)'
            );
            $ins->execute([$tenantId, $sentinel, $specJson]);
        }
        $newId = (int)$pdo->lastInsertId();
        try {
            $pdo->prepare('UPDATE sh_categories SET category_scene_id = ? WHERE tenant_id = ? AND id = ?')
                ->execute([$newId, $tenantId, $categoryId]);
        } catch (\PDOException $e) {
            throw new Exception('Nie można powiązać sceny z kategorią (category_scene_id).');
        }

        return $newId;
    };

    // $tenant_id and $user_id are injected by auth_guard.php

    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true);
    if (!is_array($input)) {
        throw new Exception("Nieprawidłowy format danych wejściowych (oczekiwano JSON).");
    }

    $action = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['action'] ?? '');

    // Helper: Puste wartości na NULL dla bazy danych
    $toNull = function($val) { return ($val === '' || $val === null) ? null : $val; };

    // Schema detection: check once if we're on the new (v2) or legacy schema
    $schemaV2 = false;
    try {
        $probe = $pdo->query("SELECT vat_rate_dine_in FROM sh_menu_items LIMIT 0");
        $schemaV2 = true;
    } catch (PDOException $e) {
        $schemaV2 = false;
    }

    $catHasVat = false;
    try {
        $probe = $pdo->query("SELECT default_vat_dine_in FROM sh_categories LIMIT 0");
        $catHasVat = true;
    } catch (PDOException $e) {
        $catHasVat = false;
    }

    $catHasIsDeleted = false;
    try {
        $probe = $pdo->query("SELECT is_deleted FROM sh_categories LIMIT 0");
        $catHasIsDeleted = true;
    } catch (PDOException $e) {
        $catHasIsDeleted = false;
    }

    $hasPriceTiers = false;
    try {
        $probe = $pdo->query("SELECT 1 FROM sh_price_tiers LIMIT 0");
        $hasPriceTiers = true;
    } catch (PDOException $e) {
        $hasPriceTiers = false;
    }

    $hasDriverActionType = false;
    try {
        $pdo->query("SELECT driver_action_type FROM sh_menu_items LIMIT 0");
        $hasDriverActionType = true;
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE sh_menu_items ADD COLUMN driver_action_type ENUM('none','pack_cold','pack_separate','check_id') NOT NULL DEFAULT 'none'");
            $hasDriverActionType = true;
        } catch (PDOException $e2) {}
    }

    // M022 feature detection — Scene Kit & Composition Profile
    $mi022HasCompositionProfile = false;
    try {
        $pdo->query("SELECT composition_profile FROM sh_menu_items LIMIT 0");
        $mi022HasCompositionProfile = true;
    } catch (PDOException $e) {}

    $cat022HasLayout = false;
    try {
        $pdo->query("SELECT layout_mode, default_composition_profile FROM sh_categories LIMIT 0");
        $cat022HasLayout = true;
    } catch (PDOException $e) {}

    $hasSceneTemplates = false;
    try {
        $pdo->query("SELECT 1 FROM sh_scene_templates LIMIT 0");
        $hasSceneTemplates = true;
    } catch (PDOException $e) {}

    $hasModifierVisualImpact = false;
    try {
        $pdo->query("SELECT has_visual_impact FROM sh_modifiers LIMIT 0");
        $hasModifierVisualImpact = true;
    } catch (PDOException $e) {}

    switch ($action) {
        
        // ==============================================================================
        // 0. DODAWANIE KATEGORII (Z łatką na display_order)
        // ==============================================================================
        case 'add_category':
            $catName = trim($input['name'] ?? '');
            if (empty($catName)) {
                throw new Exception("Nazwa kategorii nie może być pusta.");
            }
            $catVatDineIn = floatval($input['defaultVatDineIn'] ?? 8);
            $catVatTakeaway = floatval($input['defaultVatTakeaway'] ?? 5);

            // M022: layout mode + default composition profile (opcjonalne)
            $catLayoutMode = in_array($input['layoutMode'] ?? '', ['grouped','individual','hybrid','legacy_list'], true)
                ? $input['layoutMode'] : 'legacy_list';
            $catDefaultProfile = trim($input['defaultCompositionProfile'] ?? 'static_hero');
            if ($catDefaultProfile === '') $catDefaultProfile = 'static_hero';

            if ($cat022HasLayout && $catHasVat) {
                $stmt = $pdo->prepare("INSERT INTO sh_categories (tenant_id, name, is_menu, display_order, default_vat_dine_in, default_vat_takeaway, layout_mode, default_composition_profile) VALUES (?, ?, 1, 0, ?, ?, ?, ?)");
                $stmt->execute([$tenant_id, $catName, $catVatDineIn, $catVatTakeaway, $catLayoutMode, $catDefaultProfile]);
            } elseif ($catHasVat) {
                $stmt = $pdo->prepare("INSERT INTO sh_categories (tenant_id, name, is_menu, display_order, default_vat_dine_in, default_vat_takeaway) VALUES (?, ?, 1, 0, ?, ?)");
                $stmt->execute([$tenant_id, $catName, $catVatDineIn, $catVatTakeaway]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO sh_categories (tenant_id, name, is_menu, display_order) VALUES (?, ?, 1, 0)");
                $stmt->execute([$tenant_id, $catName]);
            }

            $response['success'] = true;
            $response['data'] = ['categoryId' => $pdo->lastInsertId()];
            $response['message'] = "Dodano nową kategorię.";
            break;

        case 'update_category':
            $catId = intval($input['categoryId'] ?? 0);
            $catName = trim($input['name'] ?? '');
            if ($catId <= 0 || empty($catName)) {
                throw new Exception("ID kategorii i nazwa są wymagane.");
            }
            $catVatDineIn = floatval($input['defaultVatDineIn'] ?? 8);
            $catVatTakeaway = floatval($input['defaultVatTakeaway'] ?? 5);

            // M022: layout mode + default composition profile (opcjonalne)
            $catLayoutMode = in_array($input['layoutMode'] ?? '', ['grouped','individual','hybrid','legacy_list'], true)
                ? $input['layoutMode'] : 'legacy_list';
            $catDefaultProfile = trim($input['defaultCompositionProfile'] ?? 'static_hero');
            if ($catDefaultProfile === '') $catDefaultProfile = 'static_hero';

            $catWhere = $catHasIsDeleted ? "AND is_deleted = 0" : "";
            if ($cat022HasLayout && $catHasVat) {
                $stmt = $pdo->prepare("UPDATE sh_categories SET name = ?, default_vat_dine_in = ?, default_vat_takeaway = ?, layout_mode = ?, default_composition_profile = ? WHERE id = ? AND tenant_id = ? $catWhere");
                $stmt->execute([$catName, $catVatDineIn, $catVatTakeaway, $catLayoutMode, $catDefaultProfile, $catId, $tenant_id]);
            } elseif ($catHasVat) {
                $stmt = $pdo->prepare("UPDATE sh_categories SET name = ?, default_vat_dine_in = ?, default_vat_takeaway = ? WHERE id = ? AND tenant_id = ? $catWhere");
                $stmt->execute([$catName, $catVatDineIn, $catVatTakeaway, $catId, $tenant_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE sh_categories SET name = ? WHERE id = ? AND tenant_id = ? $catWhere");
                $stmt->execute([$catName, $catId, $tenant_id]);
            }

            $response['success'] = true;
            $response['data'] = ['categoryId' => $catId];
            $response['message'] = "Zaktualizowano kategorię.";
            break;

        // ==============================================================================
        // M023: Scene Kit — backgrounds + props + lights + badges per template
        // ==============================================================================
        case 'get_scene_kit':
            if (!$hasSceneTemplates) {
                $response['success'] = true;
                $response['data'] = [
                    'templateKey' => '',
                    'template' => null,
                    'kit' => ['backgrounds' => [], 'props' => [], 'lights' => [], 'badges' => []],
                ];
                break;
            }
            $tplKey = trim($input['templateKey'] ?? '');
            if ($tplKey === '') {
                throw new Exception("templateKey jest wymagany.");
            }

            require_once __DIR__ . '/../../core/SceneResolver.php';
            $tpl = SceneResolver::getSceneTemplate($pdo, $tplKey);
            $kit = SceneResolver::getSceneKitAssets($pdo, $tplKey);

            $response['success'] = true;
            $response['data'] = [
                'templateKey' => $tplKey,
                'template'    => $tpl ? [
                    'asciiKey'           => $tpl['ascii_key'],
                    'name'               => $tpl['name'],
                    'kind'               => $tpl['kind'],
                    'stagePreset'        => $tpl['stage_preset'],
                    'compositionSchema'  => $tpl['composition_schema'],
                    'availableCameras'   => $tpl['available_cameras'],
                    'availableLuts'      => $tpl['available_luts'],
                    'atmosphericEffects' => $tpl['atmospheric_effects'],
                    'photographerBrief'  => $tpl['photographer_brief_md'],
                    'pipelinePreset'     => $tpl['pipeline_preset'],
                ] : null,
                'kit' => $kit,
                'counts' => [
                    'backgrounds' => count($kit['backgrounds']),
                    'props'       => count($kit['props']),
                    'lights'      => count($kit['lights']),
                    'badges'      => count($kit['badges']),
                ],
            ];
            break;

        // ==============================================================================
        // M023.7 · Scene Kit Editor — zapis scene_kit_assets_json per template.
        //
        // Wejście: { templateKey, kit: { backgrounds:int[], props:int[], lights:int[], badges:int[] } }
        // Semantyka:
        //   - Próbuje znaleźć template tenanta (tenant_id = $tenant_id) o tym asciiKey.
        //   - Jeśli nie istnieje, a istnieje system template (tenant_id = 0) — klonuje
        //     go do tenant-specific (copy metadanych + nowy scene_kit_assets_json).
        //   - Odpowiedź: { templateId, cloned }. System template pozostaje nietknięty.
        // ==============================================================================
        case 'save_scene_kit':
            if (!$hasSceneTemplates) {
                throw new Exception('Migracja M022 (sh_scene_templates) nie jest dostępna.');
            }
            $tplKey = trim($input['templateKey'] ?? '');
            if ($tplKey === '') {
                throw new Exception('templateKey jest wymagany.');
            }
            $kitIn = is_array($input['kit'] ?? null) ? $input['kit'] : [];
            $kit = [];
            foreach (['backgrounds', 'props', 'lights', 'badges'] as $k) {
                $raw = isset($kitIn[$k]) && is_array($kitIn[$k]) ? $kitIn[$k] : [];
                $ids = [];
                foreach ($raw as $v) {
                    $id = (int)$v;
                    if ($id > 0 && !in_array($id, $ids, true)) {
                        $ids[] = $id;
                    }
                }
                $kit[$k] = $ids;
            }

            $allIds = array_merge($kit['backgrounds'], $kit['props'], $kit['lights'], $kit['badges']);
            if (!empty($allIds)) {
                $ph = implode(',', array_fill(0, count($allIds), '?'));
                $chk = $pdo->prepare(
                    "SELECT id FROM sh_assets
                     WHERE id IN ($ph) AND (tenant_id = 0 OR tenant_id = ?) AND deleted_at IS NULL AND is_active = 1"
                );
                $chk->execute(array_merge($allIds, [$tenant_id]));
                $validIds = array_map('intval', $chk->fetchAll(PDO::FETCH_COLUMN));
                foreach ($kit as $k => $ids) {
                    $kit[$k] = array_values(array_filter($ids, fn($id) => in_array($id, $validIds, true)));
                }
            }

            $tenantTpl = $pdo->prepare(
                "SELECT id FROM sh_scene_templates
                 WHERE tenant_id = ? AND ascii_key = ? LIMIT 1"
            );
            $tenantTpl->execute([$tenant_id, $tplKey]);
            $tenantTplId = (int)($tenantTpl->fetchColumn() ?: 0);

            $cloned = false;
            if ($tenantTplId === 0) {
                $sysTpl = $pdo->prepare(
                    "SELECT ascii_key, name, kind, stage_preset_json, composition_schema_json,
                            available_cameras_json, available_luts_json, atmospheric_effects_json,
                            photographer_brief_md, pipeline_preset_json,
                            default_style_id, placeholder_asset_id
                     FROM sh_scene_templates
                     WHERE tenant_id = 0 AND ascii_key = ? LIMIT 1"
                );
                $sysTpl->execute([$tplKey]);
                $sys = $sysTpl->fetch(PDO::FETCH_ASSOC);
                if (!$sys) {
                    throw new Exception('Nie znaleziono template o tym asciiKey (ani tenant, ani system).');
                }
                $ins = $pdo->prepare(
                    "INSERT INTO sh_scene_templates
                        (tenant_id, ascii_key, name, kind, stage_preset_json, composition_schema_json,
                         available_cameras_json, available_luts_json, atmospheric_effects_json,
                         scene_kit_assets_json,
                         photographer_brief_md, pipeline_preset_json,
                         default_style_id, placeholder_asset_id,
                         is_system, is_active)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,1)"
                );
                $ins->execute([
                    $tenant_id,
                    $sys['ascii_key'],
                    $sys['name'],
                    $sys['kind'],
                    $sys['stage_preset_json'],
                    $sys['composition_schema_json'],
                    $sys['available_cameras_json'],
                    $sys['available_luts_json'],
                    $sys['atmospheric_effects_json'],
                    json_encode($kit, JSON_UNESCAPED_UNICODE),
                    $sys['photographer_brief_md'],
                    $sys['pipeline_preset_json'],
                    $sys['default_style_id'],
                    $sys['placeholder_asset_id'],
                ]);
                $tenantTplId = (int)$pdo->lastInsertId();
                $cloned = true;
            } else {
                $upd = $pdo->prepare(
                    "UPDATE sh_scene_templates SET scene_kit_assets_json = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ? AND tenant_id = ? LIMIT 1"
                );
                $upd->execute([
                    json_encode($kit, JSON_UNESCAPED_UNICODE),
                    $tenantTplId,
                    $tenant_id,
                ]);
            }

            $response['success'] = true;
            $response['data'] = [
                'templateId' => $tenantTplId,
                'cloned'     => $cloned,
                'kitCounts'  => [
                    'backgrounds' => count($kit['backgrounds']),
                    'props'       => count($kit['props']),
                    'lights'      => count($kit['lights']),
                    'badges'      => count($kit['badges']),
                ],
            ];
            $response['message'] = $cloned
                ? 'Utworzono tenant-specific wersję szablonu i zapisano kit.'
                : 'Zapisano scene kit.';
            break;

        // ==============================================================================
        // M022: Lista scene templates — dla selectów w Menu Studio UI
        // ==============================================================================
        case 'list_scene_templates':
            if (!$hasSceneTemplates) {
                $response['success'] = true;
                $response['data'] = ['templates' => []];
                break;
            }
            $kindFilter = in_array($input['kind'] ?? '', ['item','category'], true) ? $input['kind'] : null;
            if ($kindFilter) {
                $stmt = $pdo->prepare(
                    "SELECT ascii_key, name, kind, photographer_brief_md, is_system
                     FROM sh_scene_templates
                     WHERE (tenant_id = 0 OR tenant_id = ?) AND is_active = 1 AND kind = ?
                     ORDER BY (tenant_id = 0) DESC, name ASC"
                );
                $stmt->execute([$tenant_id, $kindFilter]);
            } else {
                $stmt = $pdo->prepare(
                    "SELECT ascii_key, name, kind, photographer_brief_md, is_system
                     FROM sh_scene_templates
                     WHERE (tenant_id = 0 OR tenant_id = ?) AND is_active = 1
                     ORDER BY kind ASC, (tenant_id = 0) DESC, name ASC"
                );
                $stmt->execute([$tenant_id]);
            }
            $tpls = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $tpls[] = [
                    'asciiKey'           => (string)$t['ascii_key'],
                    'name'               => (string)$t['name'],
                    'kind'               => (string)$t['kind'],
                    'photographerBrief'  => $t['photographer_brief_md'] ?? null,
                    'isSystem'           => (bool)$t['is_system'],
                ];
            }
            $response['success'] = true;
            $response['data'] = ['templates' => $tpls];
            break;

        // ==============================================================================
        // M024: Biblioteka assetów — picker (Menu Studio · modyfikatory)
        // ==============================================================================
        case 'list_assets_compact':
            if (!AssetResolver::isReady($pdo)) {
                $response['success'] = true;
                $response['data'] = ['assets' => []];
                break;
            }
            $lim = (int)($input['limit'] ?? 500);
            $lim = max(50, min(800, $lim));
            $stmt = $pdo->prepare(
                "SELECT id, ascii_key, storage_url, category, role_hint, sub_type
                 FROM sh_assets
                 WHERE (tenant_id = 0 OR tenant_id = ?) AND is_active = 1 AND deleted_at IS NULL
                 ORDER BY tenant_id DESC, category ASC, ascii_key ASC
                 LIMIT {$lim}"
            );
            $stmt->execute([$tenant_id]);
            $assets = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $assets[] = [
                    'id'        => (int)$r['id'],
                    'asciiKey'  => (string)$r['ascii_key'],
                    'previewUrl'=> AssetResolver::publicUrl((string)$r['storage_url']),
                    'category'  => $r['category'],
                    'roleHint'  => $r['role_hint'],
                    'subType'   => $r['sub_type'],
                ];
            }
            $response['success'] = true;
            $response['data'] = ['assets' => $assets];
            break;

        // ==============================================================================
        // M024: Wizualne sloty modyfikatora (layer_top_down + modifier_hero)
        // ==============================================================================
        case 'get_modifier_visual':
            $modSku = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['modifierSku'] ?? '');
            if ($modSku === '') {
                throw new Exception('modifierSku jest wymagany.');
            }
            $modCols = 'm.id, m.name, m.ascii_key, m.group_id';
            if ($hasModifierVisualImpact) {
                $modCols .= ', m.has_visual_impact';
            }
            $stmt = $pdo->prepare(
                "SELECT {$modCols}
                 FROM sh_modifiers m
                 INNER JOIN sh_modifier_groups mg ON mg.id = m.group_id AND mg.tenant_id = ?
                 WHERE m.ascii_key = ? AND m.is_deleted = 0
                 LIMIT 1"
            );
            $stmt->execute([$tenant_id, $modSku]);
            $mod = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$mod) {
                throw new Exception('Nie znaleziono modyfikatora.');
            }
            $hasVi = true;
            if ($hasModifierVisualImpact) {
                $hasVi = (bool)((int)($mod['has_visual_impact'] ?? 1));
            }
            $slots = [
                'layer_top_down' => null,
                'modifier_hero'  => null,
            ];
            if (AssetResolver::isReady($pdo)) {
                $st2 = $pdo->prepare(
                    "SELECT al.role, al.asset_id, a.ascii_key, a.storage_url
                     FROM sh_asset_links al
                     INNER JOIN sh_assets a ON a.id = al.asset_id AND a.is_active = 1 AND a.deleted_at IS NULL
                     WHERE al.tenant_id = ? AND al.entity_type = 'modifier' AND al.entity_ref = ?
                       AND al.role IN ('layer_top_down','modifier_hero') AND al.is_active = 1 AND al.deleted_at IS NULL
                     ORDER BY al.sort_order ASC, al.id DESC"
                );
                $st2->execute([$tenant_id, $modSku]);
                foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $rk = (string)$row['role'];
                    if (!isset($slots[$rk]) || $slots[$rk] !== null) {
                        continue;
                    }
                    $slots[$rk] = [
                        'assetId'    => (int)$row['asset_id'],
                        'asciiKey'   => (string)$row['ascii_key'],
                        'previewUrl' => AssetResolver::publicUrl((string)$row['storage_url']),
                    ];
                }
            }
            $response['success'] = true;
            $response['data'] = [
                'modifierId'      => (int)$mod['id'],
                'modifierSku'     => (string)$mod['ascii_key'],
                'name'            => (string)$mod['name'],
                'hasVisualImpact' => $hasVi,
                'slots'           => $slots,
                'libraryReady'    => AssetResolver::isReady($pdo),
            ];
            break;

        case 'save_modifier_visual':
            $modSku = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['modifierSku'] ?? '');
            if ($modSku === '') {
                throw new Exception('modifierSku jest wymagany.');
            }
            $stmt = $pdo->prepare(
                "SELECT m.id FROM sh_modifiers m
                 INNER JOIN sh_modifier_groups mg ON mg.id = m.group_id AND mg.tenant_id = ?
                 WHERE m.ascii_key = ? AND m.is_deleted = 0 LIMIT 1"
            );
            $stmt->execute([$tenant_id, $modSku]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('Nie znaleziono modyfikatora.');
            }
            $modId = (int)$row['id'];
            $hasVi = array_key_exists('hasVisualImpact', $input)
                ? (!empty($input['hasVisualImpact']) ? 1 : 0)
                : 1;
            $layerId = isset($input['layerTopDownAssetId']) ? (int)$input['layerTopDownAssetId'] : 0;
            $heroId  = isset($input['modifierHeroAssetId']) ? (int)$input['modifierHeroAssetId'] : 0;
            $layerId = $layerId > 0 ? $layerId : null;
            $heroId  = $heroId > 0 ? $heroId : null;

            if ($hasModifierVisualImpact) {
                $pdo->prepare('UPDATE sh_modifiers SET has_visual_impact = ? WHERE id = ?')
                    ->execute([$hasVi, $modId]);
            }
            $syncModifierVisualAssetLinks($pdo, $tenant_id, $modSku, $layerId, $heroId);

            $response['success'] = true;
            $response['message'] = 'Zapisano ustawienia wizualne modyfikatora.';
            $response['data'] = ['modifierId' => $modId];
            break;

        // ==============================================================================
        // M1 · Menu Studio Polish — Przypisanie hero z biblioteki do dania
        // Jedno danie = JEDEN hero (role='hero', entity_type='menu_item'). Akcja
        // najpierw "soft-deletuje" istniejący link hero, potem upsertuje nowy.
        // Akceptuje assetId (globalny sh_assets.tenant_id=0 lub nasz tenant).
        // ==============================================================================
        case 'set_item_hero':
            if (!AssetResolver::isReady($pdo)) {
                throw new Exception('Biblioteka assetów (m021) nie jest jeszcze zainicjalizowana.');
            }
            $itemSku = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['itemSku'] ?? ''));
            $assetId = (int)($input['assetId'] ?? 0);
            if ($itemSku === '') {
                throw new Exception('itemSku jest wymagany.');
            }
            if ($assetId <= 0) {
                throw new Exception('assetId jest wymagany.');
            }

            $chkItem = $pdo->prepare(
                "SELECT id FROM sh_menu_items
                 WHERE tenant_id = ? AND ascii_key = ? AND is_deleted = 0 LIMIT 1"
            );
            $chkItem->execute([$tenant_id, $itemSku]);
            if (!$chkItem->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception('Nie znaleziono dania o SKU: ' . $itemSku);
            }

            $chkAsset = $pdo->prepare(
                "SELECT id, storage_url FROM sh_assets
                 WHERE id = ? AND is_active = 1 AND deleted_at IS NULL
                   AND (tenant_id = 0 OR tenant_id = ?) LIMIT 1"
            );
            $chkAsset->execute([$assetId, $tenant_id]);
            $assetRow = $chkAsset->fetch(PDO::FETCH_ASSOC);
            if (!$assetRow) {
                throw new Exception('Asset nie istnieje lub jest niedostępny.');
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "UPDATE sh_asset_links SET deleted_at = CURRENT_TIMESTAMP, is_active = 0
                     WHERE tenant_id = ? AND entity_type = 'menu_item'
                       AND entity_ref = ? AND role = 'hero'
                       AND is_active = 1 AND deleted_at IS NULL"
                )->execute([$tenant_id, $itemSku]);

                $pdo->prepare(
                    "INSERT INTO sh_asset_links
                        (tenant_id, asset_id, entity_type, entity_ref, role, sort_order, is_active, created_at)
                     VALUES (?, ?, 'menu_item', ?, 'hero', 0, 1, CURRENT_TIMESTAMP)
                     ON DUPLICATE KEY UPDATE
                        sort_order = 0, is_active = 1, deleted_at = NULL,
                        updated_at = CURRENT_TIMESTAMP"
                )->execute([$tenant_id, $assetId, $itemSku]);

                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw new Exception('Nie udało się zapisać linku hero: ' . $e->getMessage());
            }

            $newUrl = AssetResolver::publicUrl((string)$assetRow['storage_url']);
            $response['success'] = true;
            $response['message'] = 'Hero przypisany do dania.';
            $response['data'] = [
                'itemSku'  => $itemSku,
                'assetId'  => $assetId,
                'imageUrl' => $newUrl,
            ];
            break;

        // ==============================================================================
        // M1 · Menu Studio Polish — Odłączenie hero od dania (soft-delete linku)
        // ==============================================================================
        case 'unlink_item_hero':
            if (!AssetResolver::isReady($pdo)) {
                throw new Exception('Biblioteka assetów (m021) nie jest jeszcze zainicjalizowana.');
            }
            $itemSku = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['itemSku'] ?? ''));
            if ($itemSku === '') {
                throw new Exception('itemSku jest wymagany.');
            }
            $upd = $pdo->prepare(
                "UPDATE sh_asset_links SET deleted_at = CURRENT_TIMESTAMP, is_active = 0
                 WHERE tenant_id = ? AND entity_type = 'menu_item'
                   AND entity_ref = ? AND role = 'hero'
                   AND is_active = 1 AND deleted_at IS NULL"
            );
            $upd->execute([$tenant_id, $itemSku]);
            $response['success'] = true;
            $response['message'] = $upd->rowCount() > 0
                ? 'Hero odłączony.'
                : 'Nie było aktywnego linku hero.';
            $response['data'] = ['itemSku' => $itemSku, 'removed' => $upd->rowCount()];
            break;

        // ==============================================================================
        // M1 · Menu Studio Polish — Auto-generator default composition dania
        // Składa scenę z: (1) hero dania jako base layer, (2) modyfikatorów z
        // action_type='NONE' + is_default=1 + has_visual_impact=1 i przypisanymi
        // assetami w roli layer_top_down. Zapis do sh_atelier_scenes.spec_json.
        // Respektuje istniejące sceny (force=true żeby nadpisać).
        // ==============================================================================
        case 'autogenerate_scene':
            require_once __DIR__ . '/../../core/SceneResolver.php';
            require_once __DIR__ . '/../../core/AssetResolver.php';

            $itemSku = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['itemSku'] ?? ''));
            $force = !empty($input['force']);
            if ($itemSku === '') {
                throw new Exception('itemSku jest wymagany.');
            }
            if (!SceneResolver::isReady($pdo)) {
                throw new Exception('Auto-generator wymaga migracji M022 (sh_atelier_scenes / sh_scene_templates).');
            }

            $stmt = $pdo->prepare(
                "SELECT id, name, category_id, ascii_key
                 FROM sh_menu_items
                 WHERE tenant_id = ? AND ascii_key = ? AND is_deleted = 0
                 LIMIT 1"
            );
            $stmt->execute([$tenant_id, $itemSku]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                throw new Exception('Nie znaleziono dania o SKU: ' . $itemSku);
            }

            $stmt = $pdo->prepare(
                "SELECT id, spec_json, version FROM sh_atelier_scenes
                 WHERE tenant_id = ? AND item_sku = ? LIMIT 1"
            );
            $stmt->execute([$tenant_id, $itemSku]);
            $existingScene = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingScene && !$force) {
                $existingSpec = json_decode((string)$existingScene['spec_json'], true);
                $existingLayers = $existingSpec['pizza']['layers'] ?? [];
                if (is_array($existingLayers) && count($existingLayers) > 0) {
                    $response['success'] = false;
                    $response['data'] = [
                        'reason'       => 'scene_exists',
                        'sceneId'      => (int)$existingScene['id'],
                        'layerCount'   => count($existingLayers),
                        'version'      => (int)$existingScene['version'],
                    ];
                    $response['message'] = 'Scena już istnieje (' . count($existingLayers) . ' warstw). Wyślij force=true, aby nadpisać.';
                    break;
                }
            }

            $layers = [];
            $nextZ = 0;

            if (AssetResolver::isReady($pdo)) {
                $stmtH = $pdo->prepare(
                    "SELECT a.ascii_key, a.storage_url
                     FROM sh_asset_links al
                     INNER JOIN sh_assets a ON a.id = al.asset_id AND a.is_active = 1 AND a.deleted_at IS NULL
                     WHERE al.tenant_id = ? AND al.entity_type = 'menu_item'
                       AND al.entity_ref = ? AND al.role = 'hero'
                       AND al.is_active = 1 AND al.deleted_at IS NULL
                     ORDER BY al.sort_order ASC, al.id DESC
                     LIMIT 1"
                );
                $stmtH->execute([$tenant_id, $itemSku]);
                $hero = $stmtH->fetch(PDO::FETCH_ASSOC);
                if ($hero) {
                    $heroUrl = AssetResolver::publicUrl((string)$hero['storage_url']);
                    if ($heroUrl) {
                        $layers[] = [
                            'layerSku'  => 'BASE_' . (string)$hero['ascii_key'],
                            'assetUrl'  => $heroUrl,
                            'zIndex'    => 0,
                            'isBase'    => true,
                            'calScale'  => 1.0,
                            'calRotate' => 0,
                            'offsetX'   => 0.0,
                            'offsetY'   => 0.0,
                            'visible'   => true,
                            'source'    => 'auto_hero',
                        ];
                        $nextZ = 10;
                    }
                }
            }

            $modVisualFilter = $hasModifierVisualImpact ? 'AND m.has_visual_impact = 1' : '';
            $sqlMods = "
                SELECT DISTINCT
                       m.id, m.ascii_key, m.name, m.is_default,
                       mg.id AS group_id, mg.name AS group_name,
                       a.ascii_key AS asset_key, a.storage_url AS asset_url
                FROM sh_item_modifiers im
                INNER JOIN sh_menu_items mi ON mi.id = im.item_id AND mi.tenant_id = :tid
                INNER JOIN sh_modifier_groups mg ON mg.id = im.group_id AND mg.tenant_id = :tid
                INNER JOIN sh_modifiers m ON m.group_id = mg.id AND m.is_deleted = 0
                INNER JOIN sh_asset_links al ON al.tenant_id = :tid
                    AND al.entity_type = 'modifier'
                    AND al.entity_ref = m.ascii_key
                    AND al.role = 'layer_top_down'
                    AND al.is_active = 1 AND al.deleted_at IS NULL
                INNER JOIN sh_assets a ON a.id = al.asset_id
                    AND a.is_active = 1 AND a.deleted_at IS NULL
                WHERE mi.id = :item_id
                  AND m.action_type = 'NONE'
                  AND m.is_default = 1
                  {$modVisualFilter}
                ORDER BY mg.id ASC, m.id ASC
            ";
            try {
                $stmtM = $pdo->prepare($sqlMods);
                $stmtM->execute([':tid' => $tenant_id, ':item_id' => (int)$item['id']]);
                $modRows = $stmtM->fetchAll(PDO::FETCH_ASSOC);
            } catch (\PDOException $e) {
                $modRows = [];
            }

            foreach ($modRows as $row) {
                $url = AssetResolver::publicUrl((string)$row['asset_url']);
                if (!$url) continue;
                $layers[] = [
                    'layerSku'     => (string)$row['ascii_key'],
                    'assetUrl'     => $url,
                    'zIndex'       => $nextZ,
                    'isBase'       => false,
                    'calScale'     => 1.0,
                    'calRotate'    => 0,
                    'offsetX'      => 0.0,
                    'offsetY'      => 0.0,
                    'visible'      => true,
                    'source'       => 'auto_modifier',
                    'fromModifier' => (string)$row['ascii_key'],
                    'fromGroup'    => (string)$row['group_name'],
                ];
                $nextZ += 10;
            }

            if (count($layers) === 0) {
                $hasHero = isset($hero) && $hero ? true : false;

                $diagStmt = $pdo->prepare("
                    SELECT COUNT(*) AS cnt
                    FROM sh_item_modifiers im
                    INNER JOIN sh_modifiers m ON m.group_id = im.group_id
                        AND m.is_deleted = 0
                        AND m.action_type = 'NONE'
                        AND m.is_default = 1
                    WHERE im.item_id = :item_id
                ");
                $diagStmt->execute([':item_id' => (int)$item['id']]);
                $defaultModsCount = (int)($diagStmt->fetchColumn() ?: 0);

                $steps = [];
                if (!$hasHero) {
                    $steps[] = 'Przypisz zdjęcie do tego dania — kliknij „Przypisz Hero" pod miniaturą po lewej (biblioteka assetów otworzy się w pickerze).';
                }
                if ($defaultModsCount === 0) {
                    $steps[] = 'Dodaj do tego dania przynajmniej jeden modyfikator domyślny (np. grupa „Sos podstawowy" → opcja „Pomidorowy" z is_default = 1).';
                } else {
                    $steps[] = 'Masz ' . $defaultModsCount . ' modyfikator(y/ów) domyślnych, ale żaden nie ma przypisanej warstwy wizualnej (layer_top_down). Otwórz „Dodatki i Modyfikatory" i w sekcji „Surface — wizualne sloty" wybierz warstwę.';
                }

                $response['success'] = false;
                $response['data'] = [
                    'reason'            => 'no_source_data',
                    'hasHero'           => $hasHero,
                    'defaultModsCount'  => $defaultModsCount,
                    'modsWithLayerCount'=> 0,
                    'steps'             => $steps,
                ];
                $response['message'] = 'Brak materiału do auto-generacji — uzupełnij hero i/lub modyfikatory domyślne z warstwą wizualną.';
                break;
            }

            $spec = [
                'pizza' => ['layers' => $layers],
                'meta'  => [
                    'generatedAt'      => gmdate('c'),
                    'generatedBy'      => 'menu_studio_autogen',
                    'sourceLayerCount' => count($layers),
                    'hasHero'          => !empty($layers) && !empty($layers[0]['isBase']),
                    'modifierCount'    => count($modRows),
                ],
            ];
            $specJson = json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($specJson === false) {
                throw new Exception('Nie udało się serializować spec_json.');
            }

            $pdo->beginTransaction();
            try {
                if ($existingScene) {
                    $pdo->prepare(
                        "UPDATE sh_atelier_scenes
                         SET spec_json = ?, version = version + 1
                         WHERE id = ? AND tenant_id = ?"
                    )->execute([$specJson, (int)$existingScene['id'], $tenant_id]);
                    $sceneId = (int)$existingScene['id'];
                } else {
                    $pdo->prepare(
                        "INSERT INTO sh_atelier_scenes (tenant_id, item_sku, spec_json, version)
                         VALUES (?, ?, ?, 1)"
                    )->execute([$tenant_id, $itemSku, $specJson]);
                    $sceneId = (int)$pdo->lastInsertId();
                }

                try {
                    $pdo->prepare(
                        "INSERT INTO sh_atelier_scene_history (scene_id, spec_json, snapshot_label)
                         VALUES (?, ?, ?)"
                    )->execute([$sceneId, $specJson, 'autogen_' . gmdate('Ymd_His')]);
                } catch (\PDOException $e) {
                    // history table może nie istnieć w starych instancjach — ignore
                }

                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $response['success'] = true;
            $response['message'] = sprintf(
                'Wygenerowano scenę z %d warstw (%s). %s',
                count($layers),
                count($layers) > 0 && !empty($layers[0]['isBase']) ? 'hero + ' . (count($layers) - 1) . ' modyfikatorów' : (count($layers) . ' modyfikatorów'),
                $existingScene ? 'Nadpisano istniejącą scenę.' : 'Utworzono nową scenę.'
            );
            $response['data'] = [
                'sceneId'     => $sceneId,
                'itemSku'     => $itemSku,
                'layerCount'  => count($layers),
                'modifierCount' => count($modRows),
                'overwritten' => (bool)$existingScene,
                'spec'        => $spec,
            ];
            break;

        // ==============================================================================
        // M025 · Category Table — edytor układu dań na wspólnej scenie kategorii
        // ==============================================================================
        case 'get_category_scene_editor':
            if (!$cat022HasLayout) {
                throw new Exception('Układ stołu kategorii wymaga migracji M022 (layout_mode / category_scene_id).');
            }
            require_once __DIR__ . '/../../core/SceneResolver.php';
            $cid = (int)($input['categoryId'] ?? 0);
            if ($cid <= 0) {
                throw new Exception('categoryId jest wymagane.');
            }
            $resolved = SceneResolver::resolveCategoryScene($pdo, (int)$tenant_id, $cid);
            if (!$resolved) {
                throw new Exception('Nie znaleziono kategorii.');
            }
            $spec = is_array($resolved['scene_spec'] ?? null) ? $resolved['scene_spec'] : [];
            $templateKey = isset($spec['template_key']) ? (string)$spec['template_key'] : 'category_flat_table';
            $placementsBySku = [];
            foreach (($spec['placements'] ?? []) as $row) {
                if (!empty($row['sku'])) {
                    $placementsBySku[(string)$row['sku']] = [
                        'sku'     => (string)$row['sku'],
                        'x'       => isset($row['x']) ? max(0.0, min(1.0, (float)$row['x'])) : 0.5,
                        'y'       => isset($row['y']) ? max(0.0, min(1.0, (float)$row['y'])) : 0.5,
                        'scale'   => isset($row['scale']) ? max(0.3, min(3.0, (float)$row['scale'])) : 1.0,
                        'z_index' => isset($row['z_index']) ? max(0, min(500, (int)$row['z_index'])) : 40,
                    ];
                }
            }
            $editorItems = [];
            foreach (($resolved['items'] ?? []) as $it) {
                $sku = (string)($it['sku'] ?? '');
                if ($sku === '') {
                    continue;
                }
                $editorItems[] = [
                    'sku'        => $sku,
                    'name'       => (string)($it['name'] ?? ''),
                    'placement'  => $placementsBySku[$sku] ?? null,
                    'compositionProfile' => $it['composition_profile'] ?? 'static_hero',
                ];
            }
            $sceneId = isset($resolved['scene_meta']['scene_id']) ? (int)$resolved['scene_meta']['scene_id'] : null;

            $response['success'] = true;
            $response['data'] = [
                'categoryId'   => (int)$resolved['category_id'],
                'categoryName' => (string)$resolved['category_name'],
                'layoutMode'   => (string)$resolved['layout_mode'],
                'sceneId'      => $sceneId > 0 ? $sceneId : null,
                'templateKey'  => $templateKey,
                'specVersion'  => isset($spec['version']) ? (int)$spec['version'] : 1,
                'items'        => $editorItems,
                'hints'        => [
                    'coordinates' => 'x,y ∈ [0..1] — pozycja środka „talerza” na stole (względem szer./wys. sceny).',
                ],
            ];
            break;

        case 'save_category_scene_layout':
            if (!$cat022HasLayout) {
                throw new Exception('Zapis układu wymaga migracji M022.');
            }
            $cid = (int)($input['categoryId'] ?? 0);
            if ($cid <= 0) {
                throw new Exception('categoryId jest wymagane.');
            }
            $tplKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['templateKey'] ?? ''));
            $placementsIn = $input['placements'] ?? [];
            if (!is_array($placementsIn)) {
                throw new Exception('placements musi być tablicą.');
            }

            $stmtSku = $pdo->prepare(
                'SELECT ascii_key FROM sh_menu_items WHERE tenant_id = ? AND category_id = ? AND is_deleted = 0 AND is_active = 1'
            );
            $stmtSku->execute([$tenant_id, $cid]);
            $allowed = [];
            foreach ($stmtSku->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $allowed[(string)$r['ascii_key']] = true;
            }

            $normalized = [];
            foreach ($placementsIn as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $sku = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($row['sku'] ?? ''));
                if ($sku === '' || empty($allowed[$sku])) {
                    continue;
                }
                $normalized[] = [
                    'sku'     => $sku,
                    'x'       => isset($row['x']) ? max(0.0, min(1.0, (float)$row['x'])) : 0.5,
                    'y'       => isset($row['y']) ? max(0.0, min(1.0, (float)$row['y'])) : 0.5,
                    'scale'   => isset($row['scale']) ? max(0.3, min(3.0, (float)$row['scale'])) : 1.0,
                    'z_index' => isset($row['z_index']) ? max(0, min(500, (int)$row['z_index'])) : 40,
                ];
            }

            $sid = $ensureCategoryAtelierScene($pdo, (int)$tenant_id, $cid, $atelierHasCategoryCols);

            $stmtSpec = $pdo->prepare('SELECT spec_json FROM sh_atelier_scenes WHERE id = ? AND tenant_id = ? LIMIT 1');
            $stmtSpec->execute([$sid, $tenant_id]);
            $specRow = $stmtSpec->fetch(PDO::FETCH_ASSOC);
            $spec = [];
            if ($specRow && !empty($specRow['spec_json'])) {
                $decoded = json_decode((string)$specRow['spec_json'], true);
                if (is_array($decoded)) {
                    $spec = $decoded;
                }
            }
            $spec['version']       = isset($spec['version']) ? (int)$spec['version'] + 1 : 2;
            $spec['kind']          = 'category_table';
            $spec['placements']    = $normalized;
            $spec['template_key']  = ($tplKey !== '')
                ? $tplKey
                : (isset($spec['template_key']) ? (string)$spec['template_key'] : 'category_flat_table');
            $spec['updated_via']   = 'menu_studio.category_table_editor';

            $upd = $pdo->prepare('UPDATE sh_atelier_scenes SET spec_json = ?, version = version + 1 WHERE id = ? AND tenant_id = ?');
            $upd->execute([
                json_encode($spec, JSON_UNESCAPED_UNICODE),
                $sid,
                $tenant_id,
            ]);

            $response['success'] = true;
            $response['message'] = 'Zapisano układ stołu kategorii.';
            $response['data'] = ['sceneId' => $sid, 'placementCount' => count($normalized)];
            break;

        // ==============================================================================
        // 1. POBIERANIE DRZEWA MENU (Z uwzględnieniem Macierzy Cenowej)
        // ==============================================================================
        case 'get_menu_tree':
            // -- Categories --
            $catDelWhere = $catHasIsDeleted ? "AND is_deleted = 0" : "";
            $m022CatCols = $cat022HasLayout ? ", layout_mode, default_composition_profile, category_scene_id" : "";
            if ($catHasVat) {
                $stmtCat = $pdo->prepare("SELECT id, name, default_vat_dine_in, default_vat_takeaway, default_vat_delivery {$m022CatCols} FROM sh_categories WHERE tenant_id = ? AND is_menu = 1 $catDelWhere ORDER BY display_order ASC, id ASC");
            } else {
                $stmtCat = $pdo->prepare("SELECT id, name {$m022CatCols} FROM sh_categories WHERE tenant_id = ? AND is_menu = 1 $catDelWhere ORDER BY display_order ASC, id ASC");
            }
            $stmtCat->execute([$tenant_id]);
            $categoriesRaw = $stmtCat->fetchAll(PDO::FETCH_ASSOC);

            // -- Items --
            if ($schemaV2) {
                $stmtItems = $pdo->prepare("SELECT id, category_id, name, ascii_key, is_active, badge_type, is_secret, stock_count, vat_rate_dine_in, vat_rate_takeaway, kds_station_id, is_locked_by_hq, image_url, description FROM sh_menu_items WHERE tenant_id = ? AND is_deleted = 0 ORDER BY display_order ASC, name ASC");
            } else {
                $stmtItems = $pdo->prepare("SELECT id, category_id, name, ascii_key, is_active, badge_type, is_secret, stock_count, vat_rate AS vat_rate_dine_in, vat_rate AS vat_rate_takeaway, printer_group AS kds_station_id, 0 AS is_locked_by_hq, NULL AS image_url, description FROM sh_menu_items WHERE tenant_id = ? AND is_deleted = 0 ORDER BY display_order ASC, name ASC");
            }
            $stmtItems->execute([$tenant_id]);
            $itemsRaw = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            // -- Price tiers (may not exist in legacy) --
            $allTiers = [];
            if ($hasPriceTiers) {
                $stmtTiers = $pdo->prepare("SELECT target_sku, channel, price FROM sh_price_tiers WHERE target_type = 'ITEM' AND (tenant_id = ? OR tenant_id = 0) ORDER BY target_sku, channel, tenant_id DESC");
                $stmtTiers->execute([$tenant_id]);
                $allTiers = $stmtTiers->fetchAll(PDO::FETCH_ASSOC);
            }
            
            $tiersBySku = [];
            foreach ($allTiers as $tier) {
                $key = $tier['target_sku'] . '|' . $tier['channel'];
                if (isset($tiersBySku['_seen'][$key])) continue;
                $tiersBySku['_seen'][$key] = true;
                $tiersBySku[$tier['target_sku']][] = [
                    'channel' => $tier['channel'],
                    'price' => (float)$tier['price']
                ];
            }
            unset($tiersBySku['_seen']);

            // -- Legacy price fallback: if no tiers table, use single `price` column --
            if (!$hasPriceTiers && !$schemaV2) {
                $stmtLegacyPrices = $pdo->prepare("SELECT ascii_key, price FROM sh_menu_items WHERE tenant_id = ? AND is_deleted = 0 AND ascii_key IS NOT NULL");
                $stmtLegacyPrices->execute([$tenant_id]);
                foreach ($stmtLegacyPrices->fetchAll(PDO::FETCH_ASSOC) as $lp) {
                    if ($lp['ascii_key']) {
                        $p = (float)$lp['price'];
                        $tiersBySku[$lp['ascii_key']] = [
                            ['channel' => 'POS', 'price' => $p],
                            ['channel' => 'Takeaway', 'price' => $p],
                            ['channel' => 'Delivery', 'price' => $p]
                        ];
                    }
                }
            }

            $categories = array_map(function($c) {
                return [
                    'id' => (int)$c['id'],
                    'name' => $c['name'],
                    'defaultVatDineIn' => (float)($c['default_vat_dine_in'] ?? 8),
                    'defaultVatTakeaway' => (float)($c['default_vat_takeaway'] ?? 5),
                    'defaultVatDelivery' => (float)($c['default_vat_delivery'] ?? 5),
                    // M022: Scene Kit fields
                    'layoutMode' => $c['layout_mode'] ?? 'legacy_list',
                    'defaultCompositionProfile' => $c['default_composition_profile'] ?? 'static_hero',
                    'categorySceneId' => isset($c['category_scene_id']) ? (int)$c['category_scene_id'] : null,
                ];
            }, $categoriesRaw);

            $items = array_map(function($i) use ($tiersBySku) {
                return [
                    'id' => (int)$i['id'],
                    'categoryId' => (int)$i['category_id'],
                    'name' => $i['name'],
                    'asciiKey' => $i['ascii_key'] ?? '',
                    'isActive' => (bool)$i['is_active'],
                    'badgeType' => $i['badge_type'] ?? 'none',
                    'isSecret' => (bool)($i['is_secret'] ?? 0),
                    'stockCount' => (int)($i['stock_count'] ?? -1),
                    'vatRate' => (float)($i['vat_rate_dine_in'] ?? 8),
                    'kdsStationId' => $i['kds_station_id'] ?? 'NONE',
                    'isLockedByHq' => (bool)($i['is_locked_by_hq'] ?? 0),
                    'imageUrl' => $i['image_url'] ?? '',
                    'description' => $i['description'] ?? '',
                    'priceTiers' => $tiersBySku[$i['ascii_key'] ?? ''] ?? [] 
                ];
            }, $itemsRaw);

            // m021 Asset Studio override — hero z sh_asset_links ma priorytet
            AssetResolver::injectHeros($pdo, (int)$tenant_id, $items, 'asciiKey', 'imageUrl');

            // -- Modifier groups --
            try {
                $stmtMods = $pdo->prepare("SELECT id, name, ascii_key FROM sh_modifier_groups WHERE tenant_id = ? AND is_deleted = 0 ORDER BY id ASC");
                $stmtMods->execute([$tenant_id]);
                $modifierGroupsRaw = $stmtMods->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $stmtMods = $pdo->prepare("SELECT id, name, '' AS ascii_key FROM sh_modifier_groups WHERE tenant_id = ? ORDER BY id ASC");
                $stmtMods->execute([$tenant_id]);
                $modifierGroupsRaw = $stmtMods->fetchAll(PDO::FETCH_ASSOC);
            }
            
            $modifierGroups = array_map(function($g) {
                return [
                    'id' => (int)$g['id'], 
                    'name' => $g['name'], 
                    'asciiKey' => $g['ascii_key'] ?? ''
                ];
            }, $modifierGroupsRaw);

            $response['success'] = true;
            $response['data'] = ['categories' => $categories, 'items' => $items, 'modifierGroups' => $modifierGroups];
            $response['message'] = "Pobrano drzewo menu.";
            break;

        // ==============================================================================
        // 2. POBIERANIE SZCZEGÓŁÓW DANIA (Dla Edytora po prawej stronie)
        // ==============================================================================
        case 'get_item_details':
            $itemId = intval($input['itemId'] ?? 0);
            if ($itemId <= 0) throw new Exception("Nieprawidłowe ID elementu.");

            $datCol = $hasDriverActionType ? ", COALESCE(driver_action_type, 'none') AS driver_action_type" : "";
            $cpCol  = $mi022HasCompositionProfile ? ", COALESCE(composition_profile, 'static_hero') AS composition_profile" : "";
            if ($schemaV2) {
                $stmtItem = $pdo->prepare("SELECT id, category_id, name, ascii_key, `type`, is_active, vat_rate_dine_in, vat_rate_takeaway, kds_station_id, printer_group, is_locked_by_hq, publication_status, valid_from, valid_to, description, image_url, marketing_tags, barcode_ean, parent_sku, allergens_json, badge_type, is_secret, stock_count, display_order, plu_code, available_days, available_start, available_end{$datCol}{$cpCol} FROM sh_menu_items WHERE id = ? AND tenant_id = ? AND is_deleted = 0");
            } else {
                $stmtItem = $pdo->prepare("SELECT id, category_id, name, ascii_key, `type`, is_active, price, vat_rate AS vat_rate_dine_in, vat_rate AS vat_rate_takeaway, printer_group, printer_group AS kds_station_id, 0 AS is_locked_by_hq, 'Draft' AS publication_status, NULL AS valid_from, NULL AS valid_to, description, NULL AS image_url, tags AS marketing_tags, NULL AS barcode_ean, NULL AS parent_sku, NULL AS allergens_json, badge_type, is_secret, stock_count, display_order, plu_code, available_days, available_start, available_end, 'none' AS driver_action_type, 'static_hero' AS composition_profile FROM sh_menu_items WHERE id = ? AND tenant_id = ? AND is_deleted = 0");
            }
            $stmtItem->execute([$itemId, $tenant_id]);
            $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

            if (!$item) throw new Exception("Nie znaleziono dania.");

            $allergensRaw = $item['allergens_json'] ?? '[]';
            $allergens = is_string($allergensRaw) ? json_decode($allergensRaw, true) : [];
            if (!is_array($allergens)) $allergens = [];

            // Price tiers
            $priceMatrix = [];
            $priceTiersOut = [];
            if ($hasPriceTiers) {
                $stmtPrice = $pdo->prepare("SELECT channel, price FROM sh_price_tiers WHERE target_type = 'ITEM' AND target_sku = ? AND (tenant_id = ? OR tenant_id = 0) ORDER BY channel, tenant_id DESC");
                $stmtPrice->execute([$item['ascii_key'] ?? '', $tenant_id]);
                $prices = $stmtPrice->fetchAll(PDO::FETCH_ASSOC);
                foreach ($prices as $p) {
                    if (!isset($priceMatrix[$p['channel']])) {
                        $priceMatrix[$p['channel']] = (float)$p['price'];
                        $priceTiersOut[] = ['channel' => $p['channel'], 'price' => (float)$p['price']];
                    }
                }
            } elseif (isset($item['price'])) {
                $legacyPrice = (float)$item['price'];
                $priceMatrix = ['POS' => $legacyPrice, 'Takeaway' => $legacyPrice, 'Delivery' => $legacyPrice];
                $priceTiersOut = [
                    ['channel' => 'POS', 'price' => $legacyPrice],
                    ['channel' => 'Takeaway', 'price' => $legacyPrice],
                    ['channel' => 'Delivery', 'price' => $legacyPrice]
                ];
            }

            // Modifier assignments
            $modGroupIds = [];
            try {
                $stmtMods = $pdo->prepare("SELECT group_id FROM sh_item_modifiers WHERE item_id = ?");
                $stmtMods->execute([$itemId]);
                $modGroupIds = array_map('intval', $stmtMods->fetchAll(PDO::FETCH_COLUMN) ?: []);
            } catch (PDOException $e) {
                $modGroupIds = [];
            }

            // m021 Asset Studio override — pojedynczy item
            $resolvedHero = AssetResolver::resolveHero($pdo, (int)$tenant_id, (string)($item['ascii_key'] ?? ''));
            $finalImageUrl = $resolvedHero['url'] ?? ($item['image_url'] ?? '');

            // M022: scene meta (has_scene + composition_profile)
            $sceneMeta022 = ['hasScene' => false, 'sceneId' => null];
            if ($hasSceneTemplates && !empty($item['ascii_key'])) {
                try {
                    $chkScene = $pdo->prepare("SELECT id FROM sh_atelier_scenes WHERE tenant_id = ? AND item_sku = ? LIMIT 1");
                    $chkScene->execute([$tenant_id, $item['ascii_key']]);
                    $sceneRow = $chkScene->fetch(PDO::FETCH_ASSOC);
                    if ($sceneRow) {
                        $sceneMeta022 = ['hasScene' => true, 'sceneId' => (int)$sceneRow['id']];
                    }
                } catch (PDOException $e) {}
            }

            $response['success'] = true;
            $response['data'] = [
                'id' => (int)$item['id'],
                'categoryId' => (int)$item['category_id'],
                'name' => $item['name'],
                'asciiKey' => $item['ascii_key'] ?? '',
                'type' => $item['type'] ?? 'standard',
                'isActive' => (bool)$item['is_active'],
                'vatRateDineIn' => (float)($item['vat_rate_dine_in'] ?? 8),
                'vatRateTakeaway' => (float)($item['vat_rate_takeaway'] ?? 5),
                'kdsStationId' => $item['kds_station_id'] ?? 'NONE',
                'printerGroup' => $item['printer_group'] ?? 'KITCHEN_1',
                'isLockedByHq' => (bool)($item['is_locked_by_hq'] ?? 0),
                'publicationStatus' => $item['publication_status'] ?? 'Draft',
                'validFrom' => $item['valid_from'] ?? null,
                'validTo' => $item['valid_to'] ?? null,
                'description' => $item['description'] ?? '',
                'imageUrl' => $finalImageUrl,
                // M022: composition profile + scene meta
                'compositionProfile' => $item['composition_profile'] ?? 'static_hero',
                'hasScene' => $sceneMeta022['hasScene'],
                'sceneId' => $sceneMeta022['sceneId'],
                'marketingTags' => $item['marketing_tags'] ?? '',
                'badgeType' => $item['badge_type'] ?? 'none',
                'isSecret' => (bool)($item['is_secret'] ?? 0),
                'stockCount' => (int)($item['stock_count'] ?? -1),
                'displayOrder' => (int)($item['display_order'] ?? 0),
                'pluCode' => $item['plu_code'] ?? '',
                'availableDays' => $item['available_days'] ?? '1,2,3,4,5,6,7',
                'availableStart' => $item['available_start'] ?? null,
                'availableEnd' => $item['available_end'] ?? null,
                'modifierGroupIds' => $modGroupIds,
                'barcodeEan' => $item['barcode_ean'] ?? '',
                'parentSku' => $item['parent_sku'] ?? '',
                'allergens' => $allergens,
                'driverActionType' => $item['driver_action_type'] ?? 'none',
                'priceMatrix' => $priceMatrix,
                'priceTiers' => $priceTiersOut
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
            
            $vatRateDineIn = floatval($input['vatRateDineIn'] ?? $input['vatRate'] ?? 8);
            $vatRateTakeaway = floatval($input['vatRateTakeaway'] ?? $input['vatRate'] ?? 5);
            $kdsStationId = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['kdsStationId'] ?? 'NONE');
            $isActive = isset($input['isActive']) ? (int)filter_var($input['isActive'], FILTER_VALIDATE_BOOLEAN) : 1;
            
            $itemType = in_array($input['type'] ?? '', ['standard', 'half_half']) ? $input['type'] : 'standard';
            $printerGroup = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['printerGroup'] ?? 'KITCHEN_1');
            $pluCode = $toNull(preg_replace('/[^a-zA-Z0-9_-]/', '', $input['pluCode'] ?? ''));
            $displayOrder = intval($input['displayOrder'] ?? 0);
            $stockCount = intval($input['stockCount'] ?? -1);
            $badgeType = in_array($input['badgeType'] ?? '', ['none','new','promo','bestseller','hot']) ? $input['badgeType'] : 'none';
            $isSecret = !empty($input['isSecret']) ? 1 : 0;
            $availableDays = preg_replace('/[^0-9,]/', '', $input['availableDays'] ?? '1,2,3,4,5,6,7');
            $availableStart = $toNull($input['availableStart'] ?? null);
            $availableEnd = $toNull($input['availableEnd'] ?? null);

            $pubStatus = in_array($input['publicationStatus'] ?? '', ['Draft', 'Live', 'Archived']) ? $input['publicationStatus'] : 'Draft';
            $validFrom = $toNull($input['validFrom'] ?? null);
            $validTo = $toNull($input['validTo'] ?? null);
            $description = trim($input['description'] ?? '');
            $imageUrl = trim($input['imageUrl'] ?? '');
            $marketingTags = trim($input['marketingTags'] ?? '');

            $barcodeEan = trim($input['barcodeEan'] ?? '');
            $barcodeEan = $barcodeEan === '' ? null : $barcodeEan;

            $parentSku = trim($input['parentSku'] ?? '');
            $parentSku = $parentSku === '' ? null : $parentSku;

            $allergensRaw = $input['allergens'] ?? [];
            $allergensJson = is_array($allergensRaw) ? json_encode($allergensRaw) : '[]';

            // M022: composition_profile
            $compositionProfile = trim($input['compositionProfile'] ?? 'static_hero');
            if ($compositionProfile === '') $compositionProfile = 'static_hero';

            $driverActionType = in_array($input['driverActionType'] ?? '', ['none','pack_cold','pack_separate','check_id'], true)
                ? $input['driverActionType']
                : 'none';
            
            $priceTiers = $input['priceTiers'] ?? [];

            if ($categoryId <= 0 || empty($name) || empty($asciiKey)) {
                throw new Exception("Brakujące lub nieprawidłowe dane przedmiotu (Nazwa, SKU, Kategoria).");
            }

            $pdo->beginTransaction();

            try {
                if ($schemaV2) {
                    // ---- V2 schema: all new columns ----
                    if ($action === 'add_item') {
                        $cols = "tenant_id, category_id, name, ascii_key, `type`, is_active, vat_rate_dine_in, vat_rate_takeaway, kds_station_id, printer_group, publication_status, valid_from, valid_to, description, image_url, marketing_tags, badge_type, is_secret, stock_count, display_order, plu_code, available_days, available_start, available_end, barcode_ean, parent_sku, allergens_json";
                        $vals = "?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?";
                        $params = [$tenant_id, $categoryId, $name, $asciiKey, $itemType, $isActive, $vatRateDineIn, $vatRateTakeaway, $kdsStationId, $printerGroup, $pubStatus, $validFrom, $validTo, $description, $imageUrl, $marketingTags, $badgeType, $isSecret, $stockCount, $displayOrder, $pluCode, $availableDays, $availableStart, $availableEnd, $barcodeEan, $parentSku, $allergensJson];
                        if ($hasDriverActionType) {
                            $cols .= ", driver_action_type";
                            $vals .= ", ?";
                            $params[] = $driverActionType;
                        }
                        if ($mi022HasCompositionProfile) {
                            $cols .= ", composition_profile";
                            $vals .= ", ?";
                            $params[] = $compositionProfile;
                        }
                        $stmt = $pdo->prepare("INSERT INTO sh_menu_items ($cols) VALUES ($vals)");
                        $stmt->execute($params);
                        $itemId = $pdo->lastInsertId();
                    } else {
                        $setCols = "name = ?, ascii_key = ?, category_id = ?, `type` = ?, is_active = ?, vat_rate_dine_in = ?, vat_rate_takeaway = ?, kds_station_id = ?, printer_group = ?, publication_status = ?, valid_from = ?, valid_to = ?, description = ?, image_url = ?, marketing_tags = ?, badge_type = ?, is_secret = ?, stock_count = ?, display_order = ?, plu_code = ?, available_days = ?, available_start = ?, available_end = ?, barcode_ean = ?, parent_sku = ?, allergens_json = ?";
                        $params = [$name, $asciiKey, $categoryId, $itemType, $isActive, $vatRateDineIn, $vatRateTakeaway, $kdsStationId, $printerGroup, $pubStatus, $validFrom, $validTo, $description, $imageUrl, $marketingTags, $badgeType, $isSecret, $stockCount, $displayOrder, $pluCode, $availableDays, $availableStart, $availableEnd, $barcodeEan, $parentSku, $allergensJson];
                        if ($hasDriverActionType) {
                            $setCols .= ", driver_action_type = ?";
                            $params[] = $driverActionType;
                        }
                        if ($mi022HasCompositionProfile) {
                            $setCols .= ", composition_profile = ?";
                            $params[] = $compositionProfile;
                        }
                        $setCols .= ", updated_at = NOW()";
                        $params[] = $itemId;
                        $params[] = $tenant_id;
                        $stmt = $pdo->prepare("UPDATE sh_menu_items SET $setCols WHERE id = ? AND tenant_id = ? AND is_deleted = 0");
                        $stmt->execute($params);
                    }
                } else {
                    // ---- Legacy schema: map to old columns ----
                    $legacyPrice = 0;
                    foreach ($priceTiers as $t) {
                        if (($t['channel'] ?? '') === 'POS') { $legacyPrice = floatval($t['price'] ?? 0); break; }
                    }
                    if ($legacyPrice == 0 && !empty($priceTiers)) $legacyPrice = floatval($priceTiers[0]['price'] ?? 0);

                    if ($action === 'add_item') {
                        $stmt = $pdo->prepare("INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active, price, vat_rate, printer_group, plu_code, description, display_order, available_days, available_start, available_end, stock_count, badge_type, is_secret) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$tenant_id, $categoryId, $name, $asciiKey, $itemType, $isActive, $legacyPrice, $vatRateDineIn, $printerGroup, $pluCode, $description, $displayOrder, $availableDays, $availableStart, $availableEnd, $stockCount, $badgeType, $isSecret]);
                        $itemId = $pdo->lastInsertId();
                    } else {
                        $stmt = $pdo->prepare("UPDATE sh_menu_items SET name = ?, ascii_key = ?, category_id = ?, `type` = ?, is_active = ?, price = ?, vat_rate = ?, printer_group = ?, plu_code = ?, description = ?, display_order = ?, available_days = ?, available_start = ?, available_end = ?, stock_count = ?, badge_type = ?, is_secret = ? WHERE id = ? AND tenant_id = ? AND is_deleted = 0");
                        $stmt->execute([$name, $asciiKey, $categoryId, $itemType, $isActive, $legacyPrice, $vatRateDineIn, $printerGroup, $pluCode, $description, $displayOrder, $availableDays, $availableStart, $availableEnd, $stockCount, $badgeType, $isSecret, $itemId, $tenant_id]);
                    }
                }

                // Price tiers (skip if table doesn't exist)
                if ($hasPriceTiers) {
                    $stmtTier = $pdo->prepare("INSERT INTO sh_price_tiers (tenant_id, target_type, target_sku, channel, price) VALUES (?, 'ITEM', ?, ?, ?) ON DUPLICATE KEY UPDATE price = VALUES(price)");
                    foreach ($priceTiers as $tier) {
                        $channel = $tier['channel'] ?? 'POS';
                        $price = floatval($tier['price'] ?? 0);
                        $stmtTier->execute([$tenant_id, $asciiKey, $channel, $price]);
                    }
                }

                // Modifier assignments
                try {
                    $stmtDeleteMods = $pdo->prepare("DELETE FROM sh_item_modifiers WHERE item_id = ?");
                    $stmtDeleteMods->execute([$itemId]);

                    $modifierGroupIds = $input['modifierGroupIds'] ?? [];
                    if (!empty($modifierGroupIds) && is_array($modifierGroupIds)) {
                        $stmtInsertMod = $pdo->prepare("INSERT INTO sh_item_modifiers (item_id, group_id) VALUES (?, ?)");
                        foreach ($modifierGroupIds as $groupId) {
                            $stmtInsertMod->execute([$itemId, intval($groupId)]);
                        }
                    }
                } catch (PDOException $e) {
                    // sh_item_modifiers might not exist — skip gracefully
                }

                $pdo->commit();
                $response['success'] = true;
                $response['data'] = ['id' => $itemId];
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

                $kdsGroup = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['kdsGroup'] ?? '');
                if ($kdsGroup !== '') {
                    $col = $schemaV2 ? "kds_station_id" : "printer_group";
                    $updates[] = "$col = ?"; $params[] = $kdsGroup;
                }
                
                $badgeType = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['badgeType'] ?? '');
                if ($badgeType !== '') { $updates[] = "badge_type = ?"; $params[] = $badgeType; }

                if (isset($input['isSecret']) && $input['isSecret'] !== '') {
                    $updates[] = "is_secret = ?"; $params[] = filter_var($input['isSecret'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                }

                if ($schemaV2 && !empty($input['temporalPublicationPatch']) && !empty($input['temporalPublicationPatch']['apply'])) {
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
                    $setClause = implode(', ', $updates);
                    if ($schemaV2) $setClause .= ", updated_at = NOW()";
                    $sql = "UPDATE sh_menu_items SET $setClause WHERE tenant_id = ? AND is_deleted = 0 AND id IN ($placeholders)";
                    $finalParams = array_merge($params, [$tenant_id], $cleanIds);
                    $stmtUpdate = $pdo->prepare($sql);
                    $stmtUpdate->execute($finalParams);
                }

                if ($hasPriceTiers && !empty($input['omnichannelPricePatch']) && !empty($input['omnichannelPricePatch']['apply'])) {
                    $patch = $input['omnichannelPricePatch'];
                    $targetChannel = $patch['targetChannel'];
                    $opType = $patch['operationType'];
                    $opValue = (float)$patch['operationValue'];

                    $stmtSku = $pdo->prepare("SELECT ascii_key FROM sh_menu_items WHERE tenant_id = ? AND is_deleted = 0 AND id IN ($placeholders)");
                    $stmtSku->execute(array_merge([$tenant_id], $cleanIds));
                    $skus = $stmtSku->fetchAll(PDO::FETCH_COLUMN);

                    $stmtCurrentPrice = $pdo->prepare("SELECT price FROM sh_price_tiers WHERE target_type='ITEM' AND target_sku=? AND channel=? AND (tenant_id = ? OR tenant_id = 0) ORDER BY tenant_id DESC LIMIT 1");
                    $stmtUpsertPrice = $pdo->prepare("INSERT INTO sh_price_tiers (tenant_id, target_type, target_sku, channel, price) VALUES (?, 'ITEM', ?, ?, ?) ON DUPLICATE KEY UPDATE price = VALUES(price)");

                    foreach ($skus as $sku) {
                        $stmtCurrentPrice->execute([$sku, $targetChannel, $tenant_id]);
                        $row = $stmtCurrentPrice->fetch(PDO::FETCH_ASSOC);
                        $currentPrice = $row ? (float)$row['price'] : 0.00;
                        
                        $newPrice = $currentPrice;
                        if ($opType === 'set_amount') $newPrice = $opValue;
                        elseif ($opType === 'increase_percent') $newPrice = $currentPrice * (1 + ($opValue / 100));
                        elseif ($opType === 'increase_pln') $newPrice = $currentPrice + $opValue;

                        if ($newPrice < 0) $newPrice = 0;
                        $stmtUpsertPrice->execute([$tenant_id, $sku, $targetChannel, $newPrice]);
                    }
                }

                $pdo->commit();
                $response['success'] = true;
                $response['message'] = "Zaktualizowano masowo strukturę Enterprise.";
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        // ==============================================================================
        // 4b. SZYBKI ZAPIS POJEDYNCZEGO MODYFIKATORA (draft z Studio — zamiast api_modifiers.php)
        // ==============================================================================
        case 'save_modifier_quick':
            $groupName  = trim($input['groupName'] ?? '');
            $name       = trim($input['name'] ?? '');
            $asciiKey   = strtoupper(preg_replace('/[^a-zA-Z0-9_]/', '', $input['asciiKey'] ?? ''));
            $priceTiers = $input['priceTiers'] ?? [];
            $wh         = $input['warehouseLink'] ?? [];

            if ($name === '') {
                throw new Exception('Nazwa modyfikatora jest wymagana.');
            }
            if ($asciiKey === '') {
                throw new Exception('Klucz systemowy (asciiKey) jest wymagany.');
            }
            if ($groupName === '') {
                throw new Exception('Nazwa grupy jest wymagana.');
            }

            $actionType   = in_array($wh['actionType'] ?? '', ['ADD', 'REMOVE', 'NONE'], true)
                ? $wh['actionType']
                : 'NONE';
            $warehouseSku = ($actionType !== 'NONE' && !empty($wh['warehouseSku']))
                ? trim($wh['warehouseSku'])
                : null;
            $linkedQty   = ($actionType === 'ADD') ? (float)($wh['quantity'] ?? 0) : 0.0;
            $linkedWaste = ($actionType === 'ADD') ? (float)($wh['wastePercent'] ?? 0) : 0.0;

            try {
                $stmtFindGroup = $pdo->prepare("SELECT id FROM sh_modifier_groups WHERE tenant_id = ? AND name = ? AND is_deleted = 0 LIMIT 1");
                $stmtFindGroup->execute([$tenant_id, $groupName]);
            } catch (PDOException $e) {
                $stmtFindGroup = $pdo->prepare("SELECT id FROM sh_modifier_groups WHERE tenant_id = ? AND name = ? LIMIT 1");
                $stmtFindGroup->execute([$tenant_id, $groupName]);
            }
            $existingGroup = $stmtFindGroup->fetch(PDO::FETCH_ASSOC);

            if ($existingGroup) {
                $groupId = (int)$existingGroup['id'];
            } else {
                $stmtNewGroup = $pdo->prepare(
                    "INSERT INTO sh_modifier_groups (tenant_id, name, min_selection, max_selection)
                     VALUES (?, ?, 0, 10)"
                );
                $stmtNewGroup->execute([$tenant_id, $groupName]);
                $groupId = (int)$pdo->lastInsertId();
            }

            $newModifierId = 0;
            try {
                $stmtMod = $pdo->prepare(
                    "INSERT INTO sh_modifiers
                        (group_id, name, ascii_key, action_type,
                         linked_warehouse_sku, linked_quantity, linked_waste_percent)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmtMod->execute([$groupId, $name, $asciiKey, $actionType, $warehouseSku, $linkedQty, $linkedWaste]);
                $newModifierId = (int)$pdo->lastInsertId();
            } catch (PDOException $e) {
                // sh_modifiers table may not exist in legacy
            }

            if ($hasPriceTiers) {
                $stmtTier = $pdo->prepare(
                    "INSERT INTO sh_price_tiers (tenant_id, target_type, target_sku, channel, price)
                     VALUES (?, 'MODIFIER', ?, ?, ?)
                     ON DUPLICATE KEY UPDATE price = VALUES(price)"
                );
                $allowedChannels = ['POS', 'Takeaway', 'Delivery'];
                foreach ($priceTiers as $tier) {
                    $channel = $tier['channel'] ?? '';
                    if (!in_array($channel, $allowedChannels, true)) continue;
                    $priceVal = (float)($tier['price'] ?? 0);
                    $stmtTier->execute([$tenant_id, $asciiKey, $channel, $priceVal]);
                }
            }

            $response['success'] = true;
            $response['message'] = 'Modyfikator zapisany pomyślnie.';
            $response['data'] = ['id' => $newModifierId, 'groupId' => $groupId];
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
                    try {
                        $stmtCheck = $pdo->prepare("SELECT id FROM sh_modifier_groups WHERE id = ? AND tenant_id = ? AND is_deleted = 0");
                        $stmtCheck->execute([$groupId, $tenant_id]);
                    } catch (PDOException $e) {
                        $stmtCheck = $pdo->prepare("SELECT id FROM sh_modifier_groups WHERE id = ? AND tenant_id = ?");
                        $stmtCheck->execute([$groupId, $tenant_id]);
                    }
                    if (!$stmtCheck->fetch()) throw new Exception("Grupa nie istnieje.");

                    try {
                        $stmt = $pdo->prepare("UPDATE sh_modifier_groups SET name = ?, min_selection = ?, max_selection = ?, free_limit = ?, allow_multi_qty = ?, publication_status = ?, valid_from = ?, valid_to = ? WHERE id = ? AND tenant_id = ?");
                        $stmt->execute([$name, $minSel, $maxSel, $freeLimit, $allowMulti, $pubStatus, $validFrom, $validTo, $groupId, $tenant_id]);
                    } catch (PDOException $e) {
                        $stmt = $pdo->prepare("UPDATE sh_modifier_groups SET name = ?, min_selection = ?, max_selection = ? WHERE id = ? AND tenant_id = ?");
                        $stmt->execute([$name, $minSel, $maxSel, $groupId, $tenant_id]);
                    }
                } else {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection, free_limit, allow_multi_qty, publication_status, valid_from, valid_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$tenant_id, $name, $groupAsciiKey, $minSel, $maxSel, $freeLimit, $allowMulti, $pubStatus, $validFrom, $validTo]);
                    } catch (PDOException $e) {
                        $stmt = $pdo->prepare("INSERT INTO sh_modifier_groups (tenant_id, name, min_selection, max_selection) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$tenant_id, $name, $minSel, $maxSel]);
                    }
                    $groupId = $pdo->lastInsertId();
                }

                // sh_modifiers + sh_price_tiers — only available on V2 schema
                $savedOptionIds = [];
                $hasModifiersTable = false;
                try {
                    $pdo->query("SELECT 1 FROM sh_modifiers LIMIT 0");
                    $hasModifiersTable = true;
                } catch (PDOException $e) {}

                if ($hasModifiersTable) {
                    $stmtInsertOpt = $pdo->prepare("INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku, linked_quantity, is_default) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmtUpdateOpt = $pdo->prepare("UPDATE sh_modifiers SET name = ?, action_type = ?, linked_warehouse_sku = ?, linked_quantity = ?, is_default = ?, is_deleted = 0 WHERE id = ? AND group_id = ?");

                    foreach ($options as $opt) {
                        $optId = intval($opt['id'] ?? 0);
                        $optName = trim($opt['name'] ?? '');
                        $optAsciiKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $opt['asciiKey'] ?? '');
                        $actionType = in_array($opt['actionType'] ?? '', ['NONE','ADD','REMOVE']) ? $opt['actionType'] : 'NONE';
                        $linkedSku = $toNull($opt['linkedWarehouseSku'] ?? null);
                        $linkedQty = (float)($opt['linkedQuantity'] ?? 0);
                        $isDefault = !empty($opt['isDefault']) ? 1 : 0;
                        $optPriceTiers = $opt['priceTiers'] ?? [];

                        if (empty($optName) || empty($optAsciiKey)) continue;

                        if ($optId > 0) {
                            $stmtUpdateOpt->execute([$optName, $actionType, $linkedSku, $linkedQty, $isDefault, $optId, $groupId]);
                            $savedOptionIds[] = $optId;
                            $resolvedModId = $optId;
                        } else {
                            $stmtInsertOpt->execute([$groupId, $optName, $optAsciiKey, $actionType, $linkedSku, $linkedQty, $isDefault]);
                            $resolvedModId = (int)$pdo->lastInsertId();
                            $savedOptionIds[] = $resolvedModId;
                        }

                        if ($hasModifierVisualImpact) {
                            $hvi = array_key_exists('hasVisualImpact', $opt)
                                ? (!empty($opt['hasVisualImpact']) ? 1 : 0)
                                : 1;
                            $pdo->prepare('UPDATE sh_modifiers SET has_visual_impact = ? WHERE id = ?')
                                ->execute([$hvi, $resolvedModId]);
                        }

                        $la = isset($opt['layerTopDownAssetId']) ? (int)$opt['layerTopDownAssetId'] : 0;
                        $ha = isset($opt['modifierHeroAssetId']) ? (int)$opt['modifierHeroAssetId'] : 0;
                        try {
                            $syncModifierVisualAssetLinks(
                                $pdo,
                                $tenant_id,
                                $optAsciiKey,
                                $la > 0 ? $la : null,
                                $ha > 0 ? $ha : null
                            );
                        } catch (\Throwable $e) {
                            // Brak sh_asset_links / m021 — reszta grupy zapisuje się normalnie.
                        }

                        if ($hasPriceTiers) {
                            $stmtTier = $pdo->prepare("INSERT INTO sh_price_tiers (tenant_id, target_type, target_sku, channel, price) VALUES (?, 'MODIFIER', ?, ?, ?) ON DUPLICATE KEY UPDATE price = VALUES(price)");
                            foreach ($optPriceTiers as $tier) {
                                $channel = $tier['channel'] ?? 'POS';
                                $price = floatval($tier['price'] ?? 0);
                                $stmtTier->execute([$tenant_id, $optAsciiKey, $channel, $price]);
                            }
                        }
                    }

                    if (!empty($savedOptionIds)) {
                        $placeholdersOpt = implode(',', array_fill(0, count($savedOptionIds), '?'));
                        $stmtDelOpt = $pdo->prepare("UPDATE sh_modifiers SET is_deleted = 1 WHERE group_id = ? AND id NOT IN ($placeholdersOpt)");
                        $stmtDelOpt->execute(array_merge([$groupId], $savedOptionIds));
                    } else {
                        $stmtDelOpt = $pdo->prepare("UPDATE sh_modifiers SET is_deleted = 1 WHERE group_id = ?");
                        $stmtDelOpt->execute([$groupId]);
                    }
                }

                $pdo->commit();
                $response['success'] = true;
                $response['data'] = ['id' => $groupId];
                $response['message'] = "Zapisano grupę modyfikatorów.";
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
        // ==============================================================================
        // 6. POBIERANIE PEŁNEGO DRZEWA MODYFIKATORÓW (Z CZYTANIEM MACIERZY CEN)
        // ==============================================================================
        case 'get_modifiers_full':
            try {
                $stmtG = $pdo->prepare("SELECT * FROM sh_modifier_groups WHERE tenant_id = ? AND is_deleted = 0 ORDER BY id ASC");
                $stmtG->execute([$tenant_id]);
            } catch (PDOException $e) {
                $stmtG = $pdo->prepare("SELECT * FROM sh_modifier_groups WHERE tenant_id = ? ORDER BY id ASC");
                $stmtG->execute([$tenant_id]);
            }
            $groups = $stmtG->fetchAll(PDO::FETCH_ASSOC);

            $options = [];
            try {
                $stmtO = $pdo->prepare(
                    "SELECT m.* FROM sh_modifiers m
                     JOIN sh_modifier_groups mg ON m.group_id = mg.id
                     WHERE mg.tenant_id = ? AND m.is_deleted = 0"
                );
                $stmtO->execute([$tenant_id]);
                $options = $stmtO->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {}

            $prices = [];
            if ($hasPriceTiers) {
                $stmtP = $pdo->prepare("SELECT target_sku, channel, price FROM sh_price_tiers WHERE target_type = 'MODIFIER' AND (tenant_id = ? OR tenant_id = 0) ORDER BY target_sku, channel, tenant_id DESC");
                $stmtP->execute([$tenant_id]);
                $prices = $stmtP->fetchAll(PDO::FETCH_ASSOC);
            }

            $pricesBySku = [];
            $seenModP = [];
            foreach ($prices as $p) {
                $mk = $p['target_sku'] . '|' . $p['channel'];
                if (isset($seenModP[$mk])) continue;
                $seenModP[$mk] = true;
                $pricesBySku[$p['target_sku']][] = ['channel' => $p['channel'], 'price' => (float)$p['price']];
            }

            $modifierLinksBySku = [];
            if (AssetResolver::isReady($pdo) && !empty($options)) {
                $skuList = [];
                foreach ($options as $o) {
                    $ak = (string)($o['ascii_key'] ?? '');
                    if ($ak !== '') {
                        $skuList[$ak] = true;
                    }
                }
                $skuKeys = array_keys($skuList);
                if (!empty($skuKeys)) {
                    $placeholders = implode(',', array_fill(0, count($skuKeys), '?'));
                    $stmtL = $pdo->prepare(
                        "SELECT al.entity_ref AS mod_sku, al.role, al.asset_id, a.ascii_key AS asset_ascii, a.storage_url
                         FROM sh_asset_links al
                         INNER JOIN sh_assets a ON a.id = al.asset_id AND a.is_active = 1 AND a.deleted_at IS NULL
                         WHERE al.tenant_id = ? AND al.entity_type = 'modifier'
                           AND al.entity_ref IN ($placeholders)
                           AND al.role IN ('layer_top_down','modifier_hero')
                           AND al.is_active = 1 AND al.deleted_at IS NULL
                         ORDER BY al.sort_order ASC, al.id DESC"
                    );
                    $stmtL->execute(array_merge([$tenant_id], $skuKeys));
                    foreach ($stmtL->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                        $msku = (string)$lr['mod_sku'];
                        $role = (string)$lr['role'];
                        if (!isset($modifierLinksBySku[$msku])) {
                            $modifierLinksBySku[$msku] = [];
                        }
                        if (isset($modifierLinksBySku[$msku][$role])) {
                            continue;
                        }
                        $modifierLinksBySku[$msku][$role] = [
                            'assetId'    => (int)$lr['asset_id'],
                            'asciiKey'   => (string)$lr['asset_ascii'],
                            'previewUrl' => AssetResolver::publicUrl((string)$lr['storage_url']),
                        ];
                    }
                }
            }

            $optionsByGroup = [];
            foreach ($options as $opt) {
                $ascii = $opt['ascii_key'] ?? '';
                $layerSlot = $modifierLinksBySku[$ascii]['layer_top_down'] ?? null;
                $heroSlot  = $modifierLinksBySku[$ascii]['modifier_hero'] ?? null;
                $optionsByGroup[$opt['group_id']][] = [
                    'id' => (int)$opt['id'],
                    'name' => $opt['name'],
                    'asciiKey' => $ascii,
                    'isDefault' => (bool)($opt['is_default'] ?? 0),
                    'actionType' => $opt['action_type'] ?? 'NONE',
                    'linkedWarehouseSku' => $opt['linked_warehouse_sku'] ?? null,
                    'linkedQuantity' => (float)($opt['linked_quantity'] ?? 0),
                    'priceTiers' => $pricesBySku[$ascii] ?? [],
                    'hasVisualImpact' => $hasModifierVisualImpact
                        ? (bool)((int)($opt['has_visual_impact'] ?? 1))
                        : true,
                    'layerTopDownAssetId' => $layerSlot ? $layerSlot['assetId'] : null,
                    'modifierHeroAssetId' => $heroSlot ? $heroSlot['assetId'] : null,
                    'visualSlots' => [
                        'layer_top_down' => $layerSlot,
                        'modifier_hero'  => $heroSlot,
                    ],
                ];
            }

            $finalGroups = [];
            foreach ($groups as $g) {
                $finalGroups[] = [
                    'id' => (int)$g['id'],
                    'name' => $g['name'],
                    'asciiKey' => $g['ascii_key'] ?? '',
                    'min' => (int)($g['min_selection'] ?? 0),
                    'max' => (int)($g['max_selection'] ?? 10),
                    'freeLimit' => (int)($g['free_limit'] ?? 0),
                    'multiQty' => (bool)($g['allow_multi_qty'] ?? 0),
                    'publicationStatus' => $g['publication_status'] ?? 'Draft',
                    'validFrom' => $g['valid_from'] ?? null,
                    'validTo' => $g['valid_to'] ?? null,
                    'isLockedByHq' => (bool)($g['is_locked_by_hq'] ?? 0),
                    'options' => $optionsByGroup[$g['id']] ?? []
                ];
            }

            $response['success'] = true;
            $response['data'] = $finalGroups;
            break;

        // ==============================================================================
        // 7. SŁOWNIK SUROWCÓW MAGAZYNOWYCH (Dla RecipeMapper i ModifierInspector)
        // ==============================================================================
        case 'get_recipes_init':
            try {
                $stmt = $pdo->prepare("SELECT sku, name, base_unit, search_aliases FROM sys_items WHERE tenant_id = ? AND is_active = 1 AND is_deleted = 0 ORDER BY name ASC");
                $stmt->execute([$tenant_id]);
                $hasAliases = true;
            } catch (PDOException $colEx) {
                $stmt = $pdo->prepare("SELECT sku, name, base_unit FROM sys_items WHERE tenant_id = ? ORDER BY name ASC");
                $stmt->execute([$tenant_id]);
                $hasAliases = false;
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $products = array_map(function($r) use ($hasAliases) {
                return [
                    'sku'      => $r['sku'],
                    'name'     => $r['name'],
                    'baseUnit' => $r['base_unit'],
                    'aliases'  => $hasAliases ? ($r['search_aliases'] ?? '') : ''
                ];
            }, $rows);

            $response['success'] = true;
            $response['data']    = ['products' => $products];
            $response['message'] = 'Słownik surowców pobrany.';
            break;

        // ==============================================================================
        // 8. POBIERANIE RECEPTURY DANIA
        // ==============================================================================
        case 'get_item_recipe':
            $menuItemSku = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['menuItemSku'] ?? '');
            if (empty($menuItemSku)) throw new Exception("Brak SKU dania.");

            $stmt = $pdo->prepare("
                SELECT r.warehouse_sku, s.name, s.base_unit, r.quantity_base, r.waste_percent, r.is_packaging
                FROM sh_recipes r
                JOIN sys_items s ON s.sku = r.warehouse_sku AND s.tenant_id = r.tenant_id
                WHERE r.menu_item_sku = ? AND r.tenant_id = ?
                ORDER BY r.id ASC
            ");
            $stmt->execute([$menuItemSku, $tenant_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $ingredients = array_map(function($r) {
                return [
                    'warehouseSku'  => $r['warehouse_sku'],
                    'name'          => $r['name'],
                    'baseUnit'      => $r['base_unit'],
                    'quantityBase'  => (float)$r['quantity_base'],
                    'wastePercent'  => (float)$r['waste_percent'],
                    'isPackaging'   => (bool)$r['is_packaging']
                ];
            }, $rows);

            $response['success'] = true;
            $response['data']    = ['ingredients' => $ingredients];
            $response['message'] = 'Receptura pobrana.';
            break;

        // ==============================================================================
        // 9. ZAPIS RECEPTURY DANIA
        // ==============================================================================
        case 'save_recipe':
            $menuItemSku  = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['menuItemSku'] ?? '');
            $ingredients  = $input['ingredients'] ?? [];
            if (empty($menuItemSku)) throw new Exception("Brak SKU dania.");

            $pdo->beginTransaction();
            try {
                $stmtDel = $pdo->prepare("DELETE FROM sh_recipes WHERE menu_item_sku = ? AND tenant_id = ?");
                $stmtDel->execute([$menuItemSku, $tenant_id]);

                $stmtIns = $pdo->prepare("INSERT INTO sh_recipes (tenant_id, menu_item_sku, warehouse_sku, quantity_base, waste_percent, is_packaging) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($ingredients as $ing) {
                    $sku     = preg_replace('/[^a-zA-Z0-9_-]/', '', $ing['warehouseSku'] ?? '');
                    $qty     = floatval($ing['quantityBase']  ?? 0);
                    $waste   = floatval($ing['wastePercent']  ?? 0);
                    $isPkg   = !empty($ing['isPackaging']) ? 1 : 0;
                    if (empty($sku) || $qty <= 0) continue;
                    $stmtIns->execute([$tenant_id, $menuItemSku, $sku, $qty, $waste, $isPkg]);
                }
                $pdo->commit();
                $response['success'] = true;
                $response['message'] = 'Receptura zapisana.';
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        // ==============================================================================
        // 10. EXPLODED VIEW — Get layers + available SKUs for an item
        // ==============================================================================
        case 'get_visual_layers':
            $itemSku = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['itemSku'] ?? '');
            if ($itemSku === '') throw new Exception('itemSku is required.');

            // Item itself (for the base layer entry)
            $stmtItem = $pdo->prepare(
                "SELECT ascii_key, name FROM sh_menu_items
                 WHERE tenant_id = :tid AND ascii_key = :sku AND is_deleted = 0 LIMIT 1"
            );
            $stmtItem->execute([':tid' => $tenant_id, ':sku' => $itemSku]);
            $itemRow = $stmtItem->fetch(PDO::FETCH_ASSOC);
            if (!$itemRow) throw new Exception("Item SKU not found: {$itemSku}");

            // Modifier SKUs linked to this item via sh_item_modifiers → sh_modifiers
            $stmtMods = $pdo->prepare(
                "SELECT m.ascii_key AS sku, m.name, mg.id AS group_id,
                        mg.name AS group_name, mg.ascii_key AS group_ascii_key,
                        m.action_type
                 FROM sh_item_modifiers im
                 JOIN sh_modifier_groups mg ON mg.id = im.group_id AND mg.tenant_id = :tid
                 JOIN sh_modifiers m ON m.group_id = mg.id AND m.is_deleted = 0 AND m.is_active = 1
                 JOIN sh_menu_items mi ON mi.id = im.item_id AND mi.tenant_id = :tid2
                 WHERE mi.ascii_key = :sku
                 ORDER BY mg.name, m.name"
            );
            $stmtMods->execute([':tid' => $tenant_id, ':tid2' => $tenant_id, ':sku' => $itemSku]);
            $mods = $stmtMods->fetchAll(PDO::FETCH_ASSOC);

            // Build available SKUs list: base + modifiers
            $availableSkus = [['sku' => $itemRow['ascii_key'], 'name' => $itemRow['name'], 'type' => 'base', 'group' => '']];
            foreach ($mods as $m) {
                $availableSkus[] = [
                    'sku'   => $m['sku'],
                    'name'  => $m['name'],
                    'type'  => 'modifier',
                    'group' => $m['group_name'],
                ];
            }

            // Existing visual layers
            $stmtLayers = $pdo->prepare(
                "SELECT layer_sku, asset_filename, z_index, is_base
                 FROM sh_visual_layers
                 WHERE tenant_id = :tid AND item_sku = :sku AND is_active = 1
                 ORDER BY z_index ASC"
            );
            $stmtLayers->execute([':tid' => $tenant_id, ':sku' => $itemSku]);
            $layers = $stmtLayers->fetchAll(PDO::FETCH_ASSOC);
            foreach ($layers as &$l) {
                $l['z_index'] = (int)$l['z_index'];
                $l['is_base'] = (bool)$l['is_base'];
            }
            unset($l);

            // ---- Upsell groups: modifier groups with prices ----
            $upsellGroups = [];
            $seenGroupIds = [];
            foreach ($mods as $m) {
                if (!isset($seenGroupIds[$m['group_id']])) {
                    $seenGroupIds[$m['group_id']] = [
                        'groupId'  => (int)$m['group_id'],
                        'name'     => $m['group_name'],
                        'asciiKey' => $m['group_ascii_key'] ?? '',
                        'options'  => [],
                    ];
                }
                $opt = [
                    'sku'      => $m['sku'],
                    'name'     => $m['name'],
                    'price'    => null,
                    'imageUrl' => null,
                ];
                $seenGroupIds[$m['group_id']]['options'][] = $opt;
            }

            // Fetch prices from sh_price_tiers for modifier SKUs
            $modSkus = array_column($mods, 'sku');
            if (!empty($modSkus)) {
                $placeholders = implode(',', array_fill(0, count($modSkus), '?'));
                $stmtPrices = $pdo->prepare(
                    "SELECT target_sku, price, channel
                     FROM sh_price_tiers
                     WHERE target_type = 'MODIFIER'
                       AND target_sku IN ({$placeholders})
                       AND (tenant_id = ? OR tenant_id = 0)
                     ORDER BY tenant_id DESC"
                );
                $priceParams = array_merge($modSkus, [$tenant_id]);
                $stmtPrices->execute($priceParams);
                $priceRows = $stmtPrices->fetchAll(PDO::FETCH_ASSOC);

                $priceMap = [];
                foreach ($priceRows as $pr) {
                    if (!isset($priceMap[$pr['target_sku']])) {
                        $priceMap[$pr['target_sku']] = (float)$pr['price'];
                    }
                }

                foreach ($seenGroupIds as &$grp) {
                    foreach ($grp['options'] as &$opt) {
                        if (isset($priceMap[$opt['sku']])) {
                            $opt['price'] = $priceMap[$opt['sku']];
                        }
                    }
                    unset($opt);
                }
                unset($grp);
            }
            $upsellGroups = array_values($seenGroupIds);

            // ---- Explicit companions from sh_board_companions ----
            $companions = [];
            try {
                $stmtComp = $pdo->prepare(
                    "SELECT bc.companion_sku, bc.companion_type, bc.board_slot,
                            bc.asset_filename, bc.display_order,
                            mi.name, mi.image_url
                     FROM sh_board_companions bc
                     JOIN sh_menu_items mi ON mi.ascii_key = bc.companion_sku AND mi.tenant_id = bc.tenant_id
                     WHERE bc.tenant_id = :tid AND bc.item_sku = :sku AND bc.is_active = 1
                     ORDER BY bc.display_order"
                );
                $stmtComp->execute([':tid' => $tenant_id, ':sku' => $itemSku]);
                $compRows = $stmtComp->fetchAll(PDO::FETCH_ASSOC);

                // Fetch companion prices
                $compSkus = array_column($compRows, 'companion_sku');
                $compPriceMap = [];
                if (!empty($compSkus)) {
                    $ph = implode(',', array_fill(0, count($compSkus), '?'));
                    $stmtCP = $pdo->prepare(
                        "SELECT target_sku, price FROM sh_price_tiers
                         WHERE target_type = 'ITEM' AND target_sku IN ({$ph})
                           AND (tenant_id = ? OR tenant_id = 0)
                         ORDER BY tenant_id DESC"
                    );
                    $stmtCP->execute(array_merge($compSkus, [$tenant_id]));
                    foreach ($stmtCP->fetchAll(PDO::FETCH_ASSOC) as $cp) {
                        if (!isset($compPriceMap[$cp['target_sku']])) {
                            $compPriceMap[$cp['target_sku']] = (float)$cp['price'];
                        }
                    }
                }

                foreach ($compRows as $cr) {
                    $companions[] = [
                        'sku'           => $cr['companion_sku'],
                        'name'          => $cr['name'],
                        'type'          => $cr['companion_type'],
                        'slot'          => (int)$cr['board_slot'],
                        'price'         => $compPriceMap[$cr['companion_sku']] ?? null,
                        'assetFilename' => $cr['asset_filename'],
                        'imageUrl'      => $cr['image_url'],
                        'displayOrder'  => (int)$cr['display_order'],
                    ];
                }
            } catch (Exception $eComp) {
                // sh_board_companions may not exist yet — graceful degradation
                error_log('[MenuStudio] board_companions lookup skipped: ' . $eComp->getMessage());
            }

            $response['success'] = true;
            $response['message'] = 'Visual context loaded.';
            $response['data'] = [
                'item_sku'       => $itemSku,
                'available_skus' => $availableSkus,
                'layers'         => $layers,
                'asset_base_url' => '../../uploads/visual/' . $tenant_id . '/',
                'upsell_groups'  => $upsellGroups,
                'companions'     => $companions,
            ];
            break;

        // ==============================================================================
        // 11. EXPLODED VIEW — Upsert a single visual layer
        // ==============================================================================
        case 'save_visual_layer':
            $itemSku  = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['itemSku'] ?? '');
            $layerSku = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['layerSku'] ?? '');
            $assetFn  = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $input['assetFilename'] ?? '');
            $zIndex   = max(0, min(100, (int)($input['zIndex'] ?? 0)));
            $isBase   = !empty($input['isBase']) ? 1 : 0;

            if ($itemSku === '' || $layerSku === '' || $assetFn === '') {
                throw new Exception('itemSku, layerSku, and assetFilename are all required.');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO sh_visual_layers
                    (tenant_id, item_sku, layer_sku, asset_filename, z_index, is_base)
                 VALUES (:tid, :item, :layer, :asset, :z, :base)
                 ON DUPLICATE KEY UPDATE
                    asset_filename = VALUES(asset_filename),
                    z_index = VALUES(z_index),
                    is_base = VALUES(is_base),
                    is_active = 1,
                    updated_at = NOW()"
            );
            $stmt->execute([
                ':tid'   => $tenant_id,
                ':item'  => $itemSku,
                ':layer' => $layerSku,
                ':asset' => $assetFn,
                ':z'     => $zIndex,
                ':base'  => $isBase,
            ]);

            $response['success'] = true;
            $response['message'] = 'Layer saved.';
            break;

        // ==============================================================================
        // 12. EXPLODED VIEW — Delete a visual layer
        // ==============================================================================
        case 'delete_visual_layer':
            $itemSku  = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['itemSku'] ?? '');
            $layerSku = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['layerSku'] ?? '');
            if ($itemSku === '' || $layerSku === '') {
                throw new Exception('itemSku and layerSku are required.');
            }

            // Fetch filename to delete from disk
            $stmtGet = $pdo->prepare(
                "SELECT asset_filename FROM sh_visual_layers
                 WHERE tenant_id = :tid AND item_sku = :item AND layer_sku = :layer LIMIT 1"
            );
            $stmtGet->execute([':tid' => $tenant_id, ':item' => $itemSku, ':layer' => $layerSku]);
            $row = $stmtGet->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $filePath = realpath(__DIR__ . '/../../') . '/uploads/visual/' . $tenant_id . '/' . $row['asset_filename'];
                if (file_exists($filePath) && is_file($filePath)) {
                    @unlink($filePath);
                }
            }

            $stmtDel = $pdo->prepare(
                "DELETE FROM sh_visual_layers
                 WHERE tenant_id = :tid AND item_sku = :item AND layer_sku = :layer"
            );
            $stmtDel->execute([':tid' => $tenant_id, ':item' => $itemSku, ':layer' => $layerSku]);

            $response['success'] = true;
            $response['message'] = 'Layer deleted.';
            break;

        // ==============================================================================
        // 13. BOARD COMPANIONS — Upsert an explicit companion link
        // ==============================================================================
        case 'save_board_companion':
            $itemSku      = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['itemSku'] ?? '');
            $companionSku = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['companionSku'] ?? '');
            $compType     = $input['companionType'] ?? 'extra';
            $boardSlot    = max(0, min(5, (int)($input['boardSlot'] ?? 0)));
            $assetFn      = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $input['assetFilename'] ?? '');
            $displayOrder = max(0, (int)($input['displayOrder'] ?? 0));

            if ($itemSku === '' || $companionSku === '') {
                throw new Exception('itemSku and companionSku are required.');
            }

            $allowedTypes = ['sauce', 'drink', 'side', 'dessert', 'extra'];
            if (!in_array($compType, $allowedTypes, true)) {
                $compType = 'extra';
            }

            $stmt = $pdo->prepare(
                "INSERT INTO sh_board_companions
                    (tenant_id, item_sku, companion_sku, companion_type, board_slot, asset_filename, display_order)
                 VALUES (:tid, :item, :comp, :ctype, :slot, :asset, :dord)
                 ON DUPLICATE KEY UPDATE
                    companion_type = VALUES(companion_type),
                    board_slot     = VALUES(board_slot),
                    asset_filename = VALUES(asset_filename),
                    display_order  = VALUES(display_order),
                    is_active      = 1,
                    updated_at     = NOW()"
            );
            $stmt->execute([
                ':tid'   => $tenant_id,
                ':item'  => $itemSku,
                ':comp'  => $companionSku,
                ':ctype' => $compType,
                ':slot'  => $boardSlot,
                ':asset' => $assetFn ?: null,
                ':dord'  => $displayOrder,
            ]);

            $response['success'] = true;
            $response['message'] = 'Companion saved.';
            break;

        // ==============================================================================
        // 14. BOARD COMPANIONS — Delete a companion link
        // ==============================================================================
        case 'delete_board_companion':
            $itemSku      = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['itemSku'] ?? '');
            $companionSku = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['companionSku'] ?? '');

            if ($itemSku === '' || $companionSku === '') {
                throw new Exception('itemSku and companionSku are required.');
            }

            $stmtDel = $pdo->prepare(
                "DELETE FROM sh_board_companions
                 WHERE tenant_id = :tid AND item_sku = :item AND companion_sku = :comp"
            );
            $stmtDel->execute([':tid' => $tenant_id, ':item' => $itemSku, ':comp' => $companionSku]);

            $response['success'] = true;
            $response['message'] = 'Companion deleted.';
            break;

        // ==============================================================================
        // [REMOVED · M025] 15-17 Ingredient Library (get/save/delete_ingredient_asset)
        //                  + 18 get_board_context
        //
        // Cały blok bazował na `sh_ingredient_assets` (zastąpione przez Asset Studio
        // i Unified Asset Library sh_assets + sh_asset_links w m021). Żadne wywołania
        // z frontendów (studio/menu_studio, online, online_studio) ich już nie używały,
        // dlatego zostały usunięte razem z tabelą `sh_ingredient_assets` (m025).
        //
        // Board context dla online/storefront jest teraz składany przez
        //   • api/online/engine.php#get_dish             (legacy endpoint, wciąż używany)
        //   • api/online/engine.php#get_scene_dish       (nowy kontrakt, Scene Studio)
        // które wczytują warstwy z sh_visual_layers + sh_asset_links (layer_top_down).
        // ==============================================================================
        case 'get_board_context':
            // Krótki defensywny error — gdyby coś legacy jeszcze wołało:
            throw new Exception('get_board_context usunięte w m025 — użyj online/get_scene_dish lub online/get_dish.');

        case 'get_ingredient_assets':
        case 'save_ingredient_asset':
        case 'delete_ingredient_asset':
            throw new Exception('Ingredient Library (sh_ingredient_assets) usunięte w m025 — użyj Asset Studio (sh_assets + sh_asset_links).');

        // ==============================================================================
        // TARCZA DIAGNOSTYCZNA (Wyłapuje zmyślone akcje JS)
        // ==============================================================================
        // ==============================================================================
        // GLOBAL ASSETS — Fetch photorealistic .webp assets from sh_global_assets
        // ==============================================================================
        case 'get_global_assets':
            $category = preg_replace('/[^a-z]/', '', $input['category'] ?? '');

            $sql = "SELECT id, ascii_key, category, sub_type, filename, width, height,
                           has_alpha, filesize_bytes, z_order, target_px
                    FROM sh_global_assets
                    WHERE (tenant_id = 0 OR tenant_id = :tid) AND is_active = 1";
            $params = [':tid' => $tenant_id];

            if ($category !== '') {
                $sql .= " AND category = :cat";
                $params[':cat'] = $category;
            }

            $sql .= " ORDER BY z_order ASC, ascii_key ASC";

            $stmtGA = $pdo->prepare($sql);
            $stmtGA->execute($params);
            $globalAssets = $stmtGA->fetchAll(PDO::FETCH_ASSOC);

            foreach ($globalAssets as &$ga) {
                $ga['id']        = (int)$ga['id'];
                $ga['width']     = (int)$ga['width'];
                $ga['height']    = (int)$ga['height'];
                $ga['hasAlpha']  = (bool)$ga['has_alpha'];
                $ga['zOrder']    = (int)$ga['z_order'];
                $ga['targetPx']  = (int)$ga['target_px'];
                $ga['filesize']  = (int)$ga['filesize_bytes'];
                $ga['url']       = '/slicehub/uploads/global_assets/' . $ga['filename'];
                unset($ga['has_alpha'], $ga['z_order'], $ga['target_px'], $ga['filesize_bytes']);
            }
            unset($ga);

            $response['success'] = true;
            $response['data']    = ['assets' => $globalAssets];
            $response['message'] = count($globalAssets) . ' assets loaded.';
            break;

        default:
            $unknown = $action ?: 'PUSTA_AKCJA';
            throw new Exception("Nieznana akcja API: [{$unknown}] - Prawdopodobnie stara wersja JS!");
    }
} catch (Exception $e) {
    $response['success'] = false;
    $response['data'] = null;
    $msg = $e->getMessage();
    $isBizLogic = !($e instanceof PDOException)
               && !str_contains($msg, 'SQLSTATE')
               && !str_contains($msg, 'Base table');
    $response['message'] = $isBizLogic ? $msg : 'Internal server error.';
    error_log('[MenuStudio] ' . $msg . ' in ' . $e->getFile() . ':' . $e->getLine());
}

echo json_encode($response);
exit;
?>
