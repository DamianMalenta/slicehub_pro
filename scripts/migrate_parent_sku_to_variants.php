<?php
/**
 * F-S1 — One-time migracja parent_sku → variant family (2026-05-11).
 *
 * Cel: zamiana legacy nieformalnego mostu `parent_sku` (tekstowy) na nowy model
 * variant scales (`parent_item_id` FK + `variant_option_id` FK).
 *
 * Algorytm:
 *   1. Grupuje sh_menu_items per (tenant_id, parent_sku) gdzie parent_sku NOT NULL.
 *   2. Dla każdej grupy:
 *      - Tworzy/pobiera SKALE „LEGACY_<parent_sku>" z domyślnym multiplier=1.0
 *        per child (bo nie wiemy realnych mnożników z legacy).
 *      - Sprawdza czy istnieje sh_menu_items z ascii_key=<parent_sku> jako parent.
 *        Jeśli nie, tworzy stub parent item (is_variant_parent=1, niesprzedawalny).
 *      - Linkuje children: parent_item_id = parent.id, variant_option_id = wygenerowane.
 *
 * Uwagi:
 *   - DRY-RUN domyślnie. Włącz --apply żeby wykonać.
 *   - Idempotent: pomija children które juz maja parent_item_id ustawione.
 *   - Bezpieczne: ZMIENIA tylko sh_menu_items + tworzy nowe sh_variant_scales/options.
 *
 * Konstytucja v5 § Prawo II — Bliźniak Cyfrowy, § Prawo VI — Snajper (tenant_id bariera).
 *
 * Uruchomienie:
 *   php scripts/migrate_parent_sku_to_variants.php                 # dry-run
 *   php scripts/migrate_parent_sku_to_variants.php --apply         # zapisuje
 *   php scripts/migrate_parent_sku_to_variants.php --apply --tenant=1
 */

declare(strict_types=1);

$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$tenantFilter = null;
foreach ($args as $a) {
    if (preg_match('/^--tenant=(\d+)$/', $a, $m)) $tenantFilter = (int)$m[1];
}

// Bootstrap PDO — wzorzec z install_panel.php
$dbHost = getenv('SLICEHUB_DB_HOST') ?: 'localhost';
$dbName = getenv('SLICEHUB_DB_NAME') ?: 'slicehub_pro_v2';
$dbUser = getenv('SLICEHUB_DB_USER') ?: 'sh';
$dbPass = getenv('SLICEHUB_DB_PASS') ?: 'sh';

$pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

echo "=== migrate_parent_sku_to_variants" . ($apply ? '' : ' [DRY-RUN]') . " ===\n";
echo "DB: {$dbHost}/{$dbName} (user={$dbUser})\n";
if ($tenantFilter) echo "Tenant filter: {$tenantFilter}\n";

// 1. Zbierz wszystkie grupy parent_sku
$sql = "
    SELECT tenant_id, parent_sku,
           GROUP_CONCAT(id ORDER BY id) AS child_ids,
           GROUP_CONCAT(ascii_key ORDER BY id) AS child_keys
      FROM sh_menu_items
     WHERE parent_sku IS NOT NULL
       AND parent_sku <> ''
       AND is_deleted = 0
       AND (parent_item_id IS NULL OR parent_item_id = 0)
";
if ($tenantFilter) $sql .= " AND tenant_id = " . (int)$tenantFilter;
$sql .= " GROUP BY tenant_id, parent_sku
          HAVING COUNT(*) >= 1
          ORDER BY tenant_id, parent_sku";

$groups = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($groups) . " parent_sku group(s)\n\n";

if (!$groups) { echo "Nothing to migrate.\n"; exit(0); }

$totalLinked = 0;
$totalScalesCreated = 0;
$totalParentsCreated = 0;

foreach ($groups as $g) {
    $tid = (int)$g['tenant_id'];
    $pSku = (string)$g['parent_sku'];
    $childIds = array_map('intval', explode(',', (string)$g['child_ids']));
    $childKeys = explode(',', (string)$g['child_keys']);

    echo "--- tenant={$tid} parent_sku='{$pSku}' ({" . count($childIds) . "} children: " . implode(', ', $childKeys) . ")\n";

    if ($apply) $pdo->beginTransaction();
    try {
        // 1. Znajdź lub utwórz parent menu item (ascii_key = parent_sku).
        $stmtP = $pdo->prepare("SELECT id, category_id FROM sh_menu_items WHERE tenant_id = ? AND ascii_key = ? LIMIT 1");
        $stmtP->execute([$tid, $pSku]);
        $parentRow = $stmtP->fetch(PDO::FETCH_ASSOC);

        if ($parentRow) {
            $parentId = (int)$parentRow['id'];
            $categoryId = (int)$parentRow['category_id'];
            echo "    parent already exists: id={$parentId}\n";
        } else {
            // Stub parent — bierze category z pierwszego dziecka.
            $stmtCat = $pdo->prepare("SELECT category_id FROM sh_menu_items WHERE id = ? AND tenant_id = ?");
            $stmtCat->execute([$childIds[0], $tid]);
            $categoryId = (int)$stmtCat->fetchColumn();

            echo "    parent missing — creating stub (is_variant_parent=1)\n";
            if ($apply) {
                $pdo->prepare("
                    INSERT INTO sh_menu_items
                        (tenant_id, category_id, name, ascii_key, type, is_active, is_deleted,
                         is_variant_parent, publication_status)
                    VALUES (?, ?, ?, ?, 'standard', 1, 0, 1, 'Draft')
                ")->execute([$tid, $categoryId, $pSku, $pSku]);
                $parentId = (int)$pdo->lastInsertId();
                $totalParentsCreated++;
            } else {
                $parentId = -1; // dry-run placeholder
            }
        }

        // 2. Stwórz skalę „LEGACY_<parent_sku>".
        $scaleKey = 'LEGACY_' . preg_replace('/[^A-Z0-9_]/', '_', strtoupper($pSku));
        $stmtS = $pdo->prepare("SELECT id FROM sh_variant_scales WHERE tenant_id = ? AND key_ascii = ? LIMIT 1");
        $stmtS->execute([$tid, $scaleKey]);
        $scaleId = (int)$stmtS->fetchColumn();
        if (!$scaleId) {
            echo "    creating scale {$scaleKey}\n";
            if ($apply) {
                $pdo->prepare("INSERT INTO sh_variant_scales (tenant_id, name, key_ascii, description) VALUES (?, ?, ?, ?)")
                    ->execute([$tid, 'Auto: ' . $pSku, $scaleKey, 'Auto-generated from legacy parent_sku migration']);
                $scaleId = (int)$pdo->lastInsertId();
                $totalScalesCreated++;
            } else {
                $scaleId = -1;
            }
        }

        // 3. Dla każdego dziecka — utwórz opcję skali (key = child_ascii_key sufiks po parent_sku),
        //    z multiplier=1.0 (legacy nie zna realnych — admin może później dostroić).
        $stmtUpsertOpt = $pdo->prepare("
            INSERT INTO sh_variant_scale_options
                (scale_id, tenant_id, name, key_ascii, display_order, diameter_cm, multiplier, is_deleted)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0)
            ON DUPLICATE KEY UPDATE
                display_order = VALUES(display_order),
                multiplier = VALUES(multiplier),
                is_deleted = 0
        ");
        $stmtLinkChild = $pdo->prepare("UPDATE sh_menu_items SET parent_item_id = ?, variant_option_id = ? WHERE id = ? AND tenant_id = ?");

        // F-S1.2 (2026-05-11): mapa presetów dla auto-multiplier z sufiksu klucza.
        // Pozwala uniknąć ręcznego dostrajania po legacy migracji.
        $multiplierPresets = [
            'XS'   => 0.55,
            'S'    => 0.70, 'SMALL' => 0.70, 'MINI' => 0.55,
            'M'    => 1.00, 'MEDIUM' => 1.00, 'STD' => 1.00, 'NORMAL' => 1.00, 'REG' => 1.00,
            'L'    => 1.30, 'LARGE' => 1.30, 'BIG' => 1.50,
            'XL'   => 1.60,
            'XXL'  => 2.00,
            // Średnice
            '26'   => 0.70, '30'   => 0.85, '32' => 1.00, '36' => 1.20, '40' => 1.40, '45' => 1.65,
        ];

        foreach ($childIds as $i => $childId) {
            $childKey = $childKeys[$i] ?? '';
            $suffix = (str_starts_with($childKey, $pSku))
                ? trim(substr($childKey, strlen($pSku)), '_-')
                : $childKey;
            $optKey = strtoupper(preg_replace('/[^A-Z0-9_]/', '_', $suffix ?: 'V' . $i));

            $optName = $suffix ?: 'wariant ' . ($i + 1);
            // F-S1.2: auto-multiplier z presetu (fallback 1.0).
            $optMultiplier = $multiplierPresets[$optKey] ?? 1.0;

            echo "      child id={$childId} {$childKey} → option_key={$optKey} (name={$optName}, multiplier={$optMultiplier}" . ($optMultiplier !== 1.0 ? ' from preset' : '') . ")\n";

            if ($apply) {
                $stmtUpsertOpt->execute([$scaleId, $tid, $optName, $optKey, $i, null, $optMultiplier]);
                $optId = (int)$pdo->lastInsertId();
                if (!$optId) {
                    // ON DUPLICATE bez nowego id → pobierz
                    $stmtGetOpt = $pdo->prepare("SELECT id FROM sh_variant_scale_options WHERE scale_id = ? AND key_ascii = ?");
                    $stmtGetOpt->execute([$scaleId, $optKey]);
                    $optId = (int)$stmtGetOpt->fetchColumn();
                }
                $stmtLinkChild->execute([$parentId, $optId, $childId, $tid]);
                $totalLinked++;
            }
        }

        // 4. Ustaw variant_scale_id na parent i flag is_variant_parent.
        if ($apply) {
            $pdo->prepare("UPDATE sh_menu_items SET variant_scale_id = ?, is_variant_parent = 1 WHERE id = ? AND tenant_id = ?")
                ->execute([$scaleId, $parentId, $tid]);
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if ($apply && $pdo->inTransaction()) $pdo->rollBack();
        echo "    ❌ ERROR: " . $e->getMessage() . "\n";
        continue;
    }
}

echo "\n=== SUMMARY ===\n";
echo "Groups processed: " . count($groups) . "\n";
echo "Parents created: {$totalParentsCreated}\n";
echo "Scales created: {$totalScalesCreated}\n";
echo "Children linked: {$totalLinked}\n";
echo ($apply ? "✅ APPLIED" : "📋 DRY-RUN (re-run with --apply to commit)") . "\n";
