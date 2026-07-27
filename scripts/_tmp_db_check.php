<?php
require __DIR__ . '/../core/db_config.php';
if (!isset($pdo)) { echo "BRAK PDO\n"; exit(1); }

echo "MariaDB: " . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
echo "Baza: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo "Tabele: " . $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() . "\n";

$checks = [
    ['sh_users.hourly_rate', "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sh_users' AND column_name='hourly_rate'"],
    ['sh_tenant', 'SELECT COUNT(*) FROM sh_tenant'],
    ['sh_employees', 'SELECT COUNT(*) FROM sh_employees'],
    ['sh_employee_rates', 'SELECT COUNT(*) FROM sh_employee_rates'],
    ['sh_print_decks', 'SELECT COUNT(*) FROM sh_print_decks'],
    ['sh_print_deck_cards', 'SELECT COUNT(*) FROM sh_print_deck_cards'],
    ['sh_payroll_ledger', 'SELECT COUNT(*) FROM sh_payroll_ledger'],
    ['sh_work_sessions', 'SELECT COUNT(*) FROM sh_work_sessions'],
    ['sh_users', 'SELECT COUNT(*) FROM sh_users'],
    ['sh_orders', 'SELECT COUNT(*) FROM sh_orders'],
];

foreach ($checks as [$label, $sql]) {
    try {
        $val = $pdo->query($sql)->fetchColumn();
        echo "$label: $val\n";
    } catch (Throwable $e) {
        echo "$label: BLAD — " . $e->getMessage() . "\n";
    }
}

echo "\nMigracje wykonane (sh_schema_migrations):\n";
try {
    $rows = $pdo->query('SELECT migration_file, applied_at, status FROM sh_schema_migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo "  {$r['migration_file']} — {$r['status']} — {$r['applied_at']}\n";
    }
} catch (Throwable $e) {
    echo "  (brak tabeli sh_schema_migrations lub blad: " . $e->getMessage() . ")\n";
}

echo "\nWszystkie tabele:\n";
$rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($rows as $t) {
    echo "  $t\n";
}
