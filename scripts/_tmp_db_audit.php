<?php
require __DIR__ . '/../core/db_config.php';
if (!isset($pdo)) { echo "BRAK PDO\n"; exit(1); }

echo "=== AUDYT BAZY ===\n";
echo "MariaDB: " . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
echo "Baza: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";

$allTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$tableSet = array_fill_keys($allTables, true);

// 1. Tabele oczekiwane z migracji (kluczowe)
$expected = [
    'sh_tenant', 'sh_users', 'sh_categories', 'sh_menu_items', 'sh_orders',
    'sh_order_lines', 'sh_order_payments', 'sh_drivers', 'sh_tables',
    'sh_delivery_zones', 'sh_modifiers', 'sh_modifier_groups', 'sh_recipes',
    'sh_employees', 'sh_employee_rates', 'sh_work_sessions', 'sh_payroll_ledger',
    'sh_advances', 'sh_deductions', 'sh_print_decks', 'sh_print_deck_cards',
    'sh_ksef_invoices', 'sh_ksef_invoice_lines', 'sh_variant_scales',
    'sh_variant_scale_options', 'sh_meal_packages', 'sh_geocode_cache',
    'sh_payroll_ledger', 'sh_kds_tickets', 'sh_pos_terminals',
    'wh_documents', 'wh_document_lines', 'wh_stock', 'wh_stock_logs',
    'sys_items', 'sh_integration_logs', 'sh_webhook_endpoints',
    'sh_notification_templates', 'sh_event_outbox',
];

echo "\n--- 1. KLUCZOWE TABELE ---\n";
$missing = [];
foreach ($expected as $t) {
    $exists = isset($tableSet[$t]);
    if (!$exists) $missing[] = $t;
    $cnt = $exists ? $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() : 'N/A';
    printf("  %-35s %s\n", $t, $exists ? "$cnt rekordow" : "BRAK TABELI");
}
if ($missing) echo "\n  !!! BRAKUJACE: " . implode(', ', $missing) . "\n";

// 2. Kolumny deprecated/usuniete
echo "\n--- 2. KOLUMNY DEPRECATED ---\n";
$deprecatedCols = [
    ['sh_users', 'hourly_rate', 'powinna byc usunieta (migracja 061)'],
];
foreach ($deprecatedCols as [$tbl, $col, $desc]) {
    $c = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$tbl' AND column_name='$col'")->fetchColumn();
    printf("  %-30s %s\n", "$tbl.$col", $c ? "ISTNIEJE ($desc)" : "OK (usunieta)");
}

// 3. Dane demo
echo "\n--- 3. DANE DEMO ---\n";
$dataChecks = [
    'sh_tenant', 'sh_users', 'sh_menu_items', 'sh_categories',
    'sh_orders', 'sh_order_lines', 'sh_drivers', 'sh_employees',
    'sh_employee_rates', 'sh_work_sessions', 'sh_recipes',
    'sh_modifiers', 'sh_tables', 'sh_delivery_zones',
];
foreach ($dataChecks as $t) {
    if (isset($tableSet[$t])) {
        $cnt = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        $flag = ($cnt == 0) ? ' ⚠ PUSTO' : '';
        printf("  %-35s %s%s\n", $t, $cnt, $flag);
    }
}

// 4. Tenants
echo "\n--- 4. TENANCI ---\n";
try {
    $cols = $pdo->query('SHOW COLUMNS FROM sh_tenant')->fetchAll(PDO::FETCH_COLUMN);
    $hasSlug = in_array('slug', $cols);
    $hasActive = in_array('is_active', $cols);
    $colList = $hasActive ? 'id, ' . ($hasSlug ? 'slug, ' : '') . 'name, is_active' : 'id, ' . ($hasSlug ? 'slug, ' : '') . 'name';
    $tenants = $pdo->query("SELECT $colList FROM sh_tenant")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tenants as $t) {
        $tid = $t['id'];
        $u = $pdo->query("SELECT COUNT(*) FROM sh_users WHERE tenant_id=$tid")->fetchColumn();
        $o = $pdo->query("SELECT COUNT(*) FROM sh_orders WHERE tenant_id=$tid")->fetchColumn();
        $m = $pdo->query("SELECT COUNT(*) FROM sh_menu_items WHERE tenant_id=$tid")->fetchColumn();
        $e = $pdo->query("SELECT COUNT(*) FROM sh_employees WHERE tenant_id=$tid")->fetchColumn();
        printf("  T%d: %-20s | userow=%s | zamowien=%s | menu=%s | pracownikow=%s\n",
            $t['id'], $t['name'] ?? '?', $u, $o, $m, $e);
    }
} catch (Throwable $e) {
    echo "  BLAD: " . $e->getMessage() . "\n";
}

// 5. Userzy per tenant
echo "\n--- 5. USERZY ---\n";
try {
    $users = $pdo->query('SELECT tenant_id, id, username, role, pin_code, is_active, is_deleted FROM sh_users ORDER BY tenant_id, id')->fetchAll(PDO::FETCH_ASSOC);
    if (!$users) echo "  (brak userow)\n";
    foreach ($users as $u) {
        printf("  T%d u%d: %-15s role=%-10s pin=%s active=%d deleted=%d\n",
            $u['tenant_id'], $u['id'], $u['username'], $u['role'],
            $u['pin_code'] ? $u['pin_code'] : '(brak)', $u['is_active'], $u['is_deleted']);
    }
} catch (Throwable $e) {
    echo "  BLAD: " . $e->getMessage() . "\n";
}

// 6. Widoki
echo "\n--- 6. WIDOKI ---\n";
$views = $pdo->query("SELECT table_name FROM information_schema.views WHERE table_schema=DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
foreach ($views as $v) echo "  $v\n";
if (!$views) echo "  (brak widokow)\n";

// 7. Klucze obce — sprawdzenie czy nie ma porzuconych
echo "\n--- 7. KLUCZE OBCE (liczba) ---\n";
$fkCount = $pdo->query("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema=DATABASE() AND constraint_type='FOREIGN KEY'")->fetchColumn();
echo "  Lacznie FK: $fkCount\n";

// 8. Engine i charset
echo "\n--- 8. ENGINE / CHARSET ---\n";
$engines = $pdo->query("SELECT engine, COUNT(*) as cnt FROM information_schema.tables WHERE table_schema=DATABASE() GROUP BY engine")->fetchAll(PDO::FETCH_ASSOC);
foreach ($engines as $e) printf("  %-15s %s tabel\n", $e['engine'], $e['cnt']);
$charsets = $pdo->query("SELECT table_collation, COUNT(*) as cnt FROM information_schema.tables WHERE table_schema=DATABASE() GROUP BY table_collation")->fetchAll(PDO::FETCH_ASSOC);
foreach ($charsets as $c) printf("  %-35s %s tabel\n", $c['table_collation'], $c['cnt']);

echo "\n=== KONIEC AUDYTU ===\n";
