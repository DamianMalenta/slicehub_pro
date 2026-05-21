<?php
/**
 * One-off audit: meal packages tables + demo data (tenant_id=1).
 * Usage: php scripts/_audit_meal_packages.php
 */
// db_config.php tworzy $pdo (bez stałych DB_*).
require __DIR__ . '/../core/db_config.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "No PDO from db_config.php\n");
    exit(1);
}

$checks = [
    'sh_meal_packages' => "SHOW TABLES LIKE 'sh_meal_packages'",
    'sh_meal_components' => "SHOW TABLES LIKE 'sh_meal_components'",
    'combo_meta_json'    => "SHOW COLUMNS FROM sh_order_lines LIKE 'combo_meta_json'",
];

echo "=== SliceHub meal packages audit ===\n";
$exists = [];
foreach ($checks as $label => $sql) {
    $r = $pdo->query($sql)->fetchAll();
    $exists[$label] = count($r) > 0;
    echo "{$label}: " . ($exists[$label] ? 'YES' : 'NO') . "\n";
}

if ($exists['sh_meal_packages']) {
    $cnt = (int)$pdo->query(
        "SELECT COUNT(*) FROM sh_meal_packages
          WHERE tenant_id = 1 AND is_deleted = 0 AND is_active = 1
            AND (publication_status IS NULL OR publication_status IN ('Live','published'))"
    )->fetchColumn();
    echo "active_live_meals_t1: {$cnt}\n";

    $all = (int)$pdo->query("SELECT COUNT(*) FROM sh_meal_packages WHERE tenant_id = 1")->fetchColumn();
    echo "all_meals_t1: {$all}\n";
} else {
    echo "active_live_meals_t1: N/A (brak tabel)\n";
    echo "all_meals_t1: N/A\n";
    exit(0);
}

if ($all > 0) {
    $rows = $pdo->query(
        "SELECT id, ascii_key, name, type, publication_status, is_active, category_id
           FROM sh_meal_packages WHERE tenant_id = 1"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
        $comps = $pdo->prepare(
            "SELECT component_type, item_sku, category_id, qty FROM sh_meal_components
              WHERE meal_id = ? AND tenant_id = 1 ORDER BY display_order"
        );
        $comps->execute([$row['id']]);
        foreach ($comps->fetchAll(PDO::FETCH_ASSOC) as $c) {
            echo "  component: " . json_encode($c, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
}

// Sample menu SKUs for seed proposal
$cats = $pdo->query(
    "SELECT id, name FROM sh_categories WHERE tenant_id = 1 AND is_deleted = 0 ORDER BY display_order LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);
echo "\nSample categories:\n";
foreach ($cats as $c) {
    echo "  {$c['id']}: {$c['name']}\n";
}

$items = $pdo->query(
    "SELECT ascii_key, name, category_id FROM sh_menu_items
      WHERE tenant_id = 1 AND is_deleted = 0 AND parent_item_id IS NULL
      ORDER BY category_id, name LIMIT 15"
)->fetchAll(PDO::FETCH_ASSOC);
echo "\nSample menu items (for seed SKUs):\n";
foreach ($items as $i) {
    echo "  {$i['ascii_key']} | cat={$i['category_id']} | {$i['name']}\n";
}
