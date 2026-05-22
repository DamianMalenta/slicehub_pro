<?php

declare(strict_types=1);

/**
 * Audyt dopasowań KSeF: mapping, AutoScan, pack, normalizacja.
 * php scripts/audit_ksef_matching.php [tenant_id]
 */
require_once __DIR__ . '/../core/db_config.php';
require_once __DIR__ . '/../core/AutoScanEngine.php';
require_once __DIR__ . '/../core/InvoiceLineQtyNormalizer.php';
require_once __DIR__ . '/../core/Ksef/InboxQtyNormalize.php';

$tenantId = max(1, (int) ($argv[1] ?? 1));
$fail = 0;

echo "=== SliceHub — audyt dopasowań KSeF (tenant_id={$tenantId}) ===\n\n";

// Schema
$cols058 = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sh_ksef_invoice_lines' AND COLUMN_NAME = 'qty_normalized'"
)->fetchColumn();
$idx059 = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sh_product_mapping' AND INDEX_NAME = 'uq_mapping_supplier'"
)->fetchColumn();
echo ($cols058 > 0 ? 'OK' : 'FAIL') . "  migracja 058 (qty_normalized)\n";
echo ($idx059 > 0 ? 'OK' : 'FAIL') . "  migracja 059 (uq_mapping_supplier)\n";
if ($cols058 === 0 || $idx059 === 0) {
    $fail++;
}

$items = (int) $pdo->query(
    "SELECT COUNT(*) FROM sys_items WHERE tenant_id = {$tenantId} AND is_deleted = 0 AND is_active = 1"
)->fetchColumn();
$mappings = (int) $pdo->query(
    "SELECT COUNT(*) FROM sh_product_mapping WHERE tenant_id = {$tenantId}"
)->fetchColumn();
$packLearned = (int) $pdo->query(
    "SELECT COUNT(*) FROM sh_product_mapping WHERE tenant_id = {$tenantId} AND pack_qty_base IS NOT NULL AND pack_qty_base > 0"
)->fetchColumn();
echo "\nSłownik sys_items (aktywne): {$items}\n";
echo "sh_product_mapping (aliasy): {$mappings}\n";
echo "z uczeniem pack_qty_base: {$packLearned}\n";
if ($items === 0) {
    echo "WARN brak pozycji w sys_items — AutoScan da SAME NONE\n";
    $fail++;
}

$th = AutoScanEngine::DEFAULT_AUTO_ACCEPT_THRESHOLD;
$st = $pdo->prepare(
    "SELECT setting_value FROM sh_tenant_settings WHERE tenant_id = :tid AND setting_key = 'autoscan_auto_accept_threshold' LIMIT 1"
);
$st->execute([':tid' => $tenantId]);
$tv = $st->fetchColumn();
if ($tv !== false && $tv !== '') {
    $th = (int) $tv;
}
echo "Próg auto-accept: {$th}\n";

echo "\n--- Linie faktur (stan w bazie) ---\n";
$lines = $pdo->prepare(
    "SELECT l.id, i.invoice_number, i.status, i.supplier_nip, l.external_name, l.external_description,
            l.resolved_sku, l.match_type, l.match_confidence, l.normalization_status, l.qty, l.unit
       FROM sh_ksef_invoice_lines l
       JOIN sh_ksef_invoices i ON i.id = l.ksef_invoice_id AND i.tenant_id = :tid
      ORDER BY i.id, l.line_no"
);
$lines->execute([':tid' => $tenantId]);
$rows = $lines->fetchAll(PDO::FETCH_ASSOC);
if ($rows === []) {
    echo "(brak faktur w inbox — wrzuć XML lub Pobierz z KSeF)\n";
} else {
    foreach ($rows as $r) {
        $sku = $r['resolved_sku'] ?: '—';
        $norm = $r['normalization_status'] ?: '—';
        echo sprintf(
            "  [%s] L%s %s → SKU=%s %s %d%% norm=%s\n",
            $r['invoice_number'],
            $r['line_no'] ?? '?',
            mb_substr((string) $r['external_name'], 0, 40),
            $sku,
            $r['match_type'] ?? 'NONE',
            (int) ($r['match_confidence'] ?? 0),
            $norm
        );
    }
}

echo "\n--- Test AutoScan (symulacja nowych nazw) ---\n";
$probes = [
    'BAZYLIA CIĘTA 20G',
    'BAZYLIA CIĘTA',
    'MOZZARELLA 200G',
    'POMIDOR KOKTAIL',
    'NIEPOWIADAJACA NAZWA XYZ 999',
];
foreach ($probes as $name) {
    $r = AutoScanEngine::match($pdo, $tenantId, $name, $th, '5260001246');
    $sku = $r['sku'] ?? '—';
    echo sprintf(
        "  %-28s → %s %s %d%% auto=%s\n",
        $name,
        $r['match_type'],
        $sku,
        (int) $r['confidence'],
        !empty($r['should_auto_accept']) ? 'tak' : 'nie'
    );
}

echo "\n--- Podsumowanie: czego NIE robi system automatycznie ---\n";
echo "  • NONE (<{$th}%) — wybierz SKU ręcznie lub Smart-create (+ Nowy)\n";
echo "  • normalization blocked — ustaw pack (Zapisz opak.) lub gramaturę w nazwie/P_7A\n";
echo "  • status=error — Ponów ocenę / odrzuć (pusta FA, KOR bez linii)\n";
echo "  • reparse — nie nadpisuje linii z ręcznym SKU (resolved_by_user_id)\n";
echo "  • Pierwsza faktura od dostawcy — alias uczy się po accept / update_line / set_line_pack\n";

exit($fail > 0 ? 1 : 0);
