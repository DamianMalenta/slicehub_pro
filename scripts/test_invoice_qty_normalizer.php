<?php

declare(strict_types=1);

/**
 * CLI: testy InvoiceLineQtyNormalizer (E1–E8 z propozycji).
 * Uruchom: php scripts/test_invoice_qty_normalizer.php
 */
require_once __DIR__ . '/../core/InvoiceLineQtyNormalizer.php';

$fail = 0;

function assertNear(string $label, float $got, float $exp, float $eps = 0.01): void
{
    global $fail;
    if (abs($got - $exp) > $eps) {
        echo "FAIL {$label}: got {$got}, expected {$exp}\n";
        $fail++;
    } else {
        echo "OK   {$label}\n";
    }
}

function assertEq(string $label, mixed $got, mixed $exp): void
{
    global $fail;
    if ($got !== $exp) {
        echo "FAIL {$label}: got " . var_export($got, true) . ', expected ' . var_export($exp, true) . "\n";
        $fail++;
    } else {
        echo "OK   {$label}\n";
    }
}

// E1: 1 szt BAZYLIA 20G → 0.02 kg @ 1333.50
$r = InvoiceLineQtyNormalizer::normalize(
    [
        'qty' => 1,
        'unit' => 'SZT.',
        'unit_net' => 26.67,
        'line_net_minor' => 2667,
        'external_name' => 'BAZYLIA CIĘTA 20G',
    ],
    ['base_unit' => 'kg'],
    null
);
assertEq('E1 status ok/warn', in_array($r['status'], ['ok', 'warn'], true), true);
assertNear('E1 qty', (float) $r['qty_normalized'], 0.02, 0.0001);
assertNear('E1 unit_net', (float) $r['unit_net_normalized'], 1333.50, 1.0);

// E2: 10 szt × 200G → 2 kg
$r = InvoiceLineQtyNormalizer::normalize(
    [
        'qty' => 10,
        'unit' => 'szt',
        'unit_net' => 10,
        'line_net_minor' => 10000,
        'external_name' => 'MASŁO 200G',
    ],
    ['base_unit' => 'kg'],
    null
);
assertNear('E2 qty', (float) $r['qty_normalized'], 2.0, 0.001);

// E4: 2.5 kg
$r = InvoiceLineQtyNormalizer::normalize(
    ['qty' => 2.5, 'unit' => 'kg', 'unit_net' => 4, 'line_net_minor' => 1000, 'external_name' => 'MKA'],
    ['base_unit' => 'kg'],
    null
);
assertNear('E4 qty', (float) $r['qty_normalized'], 2.5, 0.001);

// E5: 500 g → 0.5 kg
$r = InvoiceLineQtyNormalizer::normalize(
    ['qty' => 500, 'unit' => 'g', 'unit_net' => 0.01, 'line_net_minor' => 500, 'external_name' => 'CUKIER'],
    ['base_unit' => 'kg'],
    null
);
assertNear('E5 qty', (float) $r['qty_normalized'], 0.5, 0.001);

// E7: 750 ml → 0.75 l
$r = InvoiceLineQtyNormalizer::normalize(
    ['qty' => 750, 'unit' => 'ml', 'unit_net' => 0.02, 'line_net_minor' => 1500, 'external_name' => 'MLEKO'],
    ['base_unit' => 'l'],
    null
);
assertNear('E7 qty', (float) $r['qty_normalized'], 0.75, 0.001);

// E3: szt → szt
$r = InvoiceLineQtyNormalizer::normalize(
    ['qty' => 1, 'unit' => 'szt', 'unit_net' => 1.5, 'line_net_minor' => 150, 'external_name' => 'JAJKO L'],
    ['base_unit' => 'szt'],
    null
);
assertNear('E3 qty', (float) $r['qty_normalized'], 1.0, 0.001);

// E9: brak wagi — blocked
$r = InvoiceLineQtyNormalizer::normalize(
    ['qty' => 1, 'unit' => 'szt', 'unit_net' => 5, 'line_net_minor' => 500, 'external_name' => 'POMIDOR KOKTAIL'],
    ['base_unit' => 'kg'],
    null
);
assertEq('E9 blocked', $r['status'], InvoiceLineQtyNormalizer::STATUS_BLOCKED);

// Mapping pack: 0.02 per szt learned
$r = InvoiceLineQtyNormalizer::normalize(
    ['qty' => 2, 'unit' => 'szt', 'unit_net' => 10, 'line_net_minor' => 2000, 'external_name' => 'BAZYLIA CIĘTA 20G'],
    ['base_unit' => 'kg'],
    ['pack_qty_base' => 0.02, 'pack_invoice_unit' => 'szt']
);
assertNear('mapping qty', (float) $r['qty_normalized'], 0.04, 0.0001);

echo $fail === 0 ? "\nAll tests passed.\n" : "\n{$fail} test(s) failed.\n";
exit($fail === 0 ? 0 : 1);
