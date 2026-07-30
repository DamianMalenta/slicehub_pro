<?php
require __DIR__ . '/../core/db_config.php';
$pdo = new PDO('mysql:host=localhost;dbname=slicehub_pro_v2;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "=== combo half-half check ===\n";
$r = $pdo->query("SELECT id, ascii_key, name FROM sh_menu_items WHERE tenant_id=1 AND ascii_key IN ('PIZZA_MARGHERITA_S','PIZZA_BBQ_CHICKEN')")->fetchAll(PDO::FETCH_ASSOC);
foreach ($r as $row) printf("  ascii_key=%s name=%s\n", $row['ascii_key'], $row['name']);

echo "\n=== orphan lines to backfill (MARGHERITA -> PIZZA_MARGHERITA) ===\n";
$cnt = $pdo->query("SELECT COUNT(*) FROM sh_order_lines WHERE item_sku='MARGHERITA'")->fetchColumn();
echo "  count=$cnt\n";

$n = $pdo->exec("UPDATE sh_order_lines SET item_sku='PIZZA_MARGHERITA' WHERE item_sku='MARGHERITA'");
echo "  updated=$n rows\n";

echo "\n=== verify orphans after backfill ===\n";
$rows = $pdo->query("SELECT ol.item_sku, ol.snapshot_name, COUNT(*) cnt
        FROM sh_order_lines ol
        JOIN sh_orders o ON o.id = ol.order_id
        LEFT JOIN sh_menu_items mi ON mi.tenant_id = o.tenant_id AND mi.ascii_key = ol.item_sku
        WHERE o.tenant_id = 1 AND mi.id IS NULL
        GROUP BY ol.item_sku, ol.snapshot_name")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) printf("  sku=%s snapshot=%s cnt=%d\n", $r['item_sku'], $r['snapshot_name'], $r['cnt']);
echo "remaining orphan groups: " . count($rows) . "\n";
