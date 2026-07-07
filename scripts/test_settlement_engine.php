<?php

declare(strict_types=1);

/**
 * CLI: smoke tests for SettlementEngine (Phase 1).
 * Uruchom: php scripts/test_settlement_engine.php
 */
require_once __DIR__ . '/../core/db_config.php';
require_once __DIR__ . '/../core/OrderStateMachine.php';
require_once __DIR__ . '/../core/SettlementEngine.php';

$fail = 0;
$tenantId = 1;
$cashierId = 3;

function assertTrue(string $label, bool $cond): void
{
    global $fail;
    if (!$cond) {
        echo "FAIL {$label}\n";
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

function uuidV4(): string
{
    $data    = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function createReadyOrder(PDO $pdo, int $tenantId, int $grandTotalGrosze, string $paymentStatus = 'to_pay'): string
{
    $oid = uuidV4();
    $pdo->prepare(
        "INSERT INTO sh_orders
            (id, tenant_id, order_number, channel, order_type, source,
             subtotal, delivery_fee, grand_total, status, payment_status, payment_method, created_at)
         VALUES
            (:id, :tid, :num, 'pos', 'takeaway', 'pos',
             :sub, 0, :gt, 'ready', :ps, 'cash', NOW())"
    )->execute([
        ':id'  => $oid,
        ':tid' => $tenantId,
        ':num' => 'TST-' . substr($oid, 0, 8),
        ':sub' => $grandTotalGrosze,
        ':gt'  => $grandTotalGrosze,
        ':ps'  => $paymentStatus,
    ]);

    return $oid;
}

function paymentRowCount(PDO $pdo, string $orderId, int $tenantId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM sh_order_payments WHERE order_id = :oid AND tenant_id = :tid"
    );
    $stmt->execute([':oid' => $orderId, ':tid' => $tenantId]);
    $n = (int)$stmt->fetchColumn();
    $stmt->closeCursor();

    return $n;
}

function cleanupOrder(PDO $pdo, string $orderId, int $tenantId): void
{
    $pdo->prepare('DELETE FROM sh_order_payments WHERE order_id = :oid AND tenant_id = :tid')
        ->execute([':oid' => $orderId, ':tid' => $tenantId]);
    $pdo->prepare('DELETE FROM sh_order_audit WHERE order_id = :oid')->execute([':oid' => $orderId]);
    $pdo->prepare('DELETE FROM sh_orders WHERE id = :oid AND tenant_id = :tid')
        ->execute([':oid' => $orderId, ':tid' => $tenantId]);
}

// --- Test 1: settle + payment row + completed ---
$oid1 = createReadyOrder($pdo, $tenantId, 5000, 'to_pay');
$pdo->beginTransaction();
$r1 = SettlementEngine::settleAndClose(
    $pdo,
    $oid1,
    $tenantId,
    $cashierId,
    [['method' => 'cash', 'amount' => 50.00, 'tendered' => 60.00]],
    ['print_receipt' => false]
);
$pdo->commit();

assertTrue('T1 success', $r1['success'] === true);
assertEq('T1 status completed', $r1['new_status'] ?? '', 'completed');
assertEq('T1 payment rows', paymentRowCount($pdo, $oid1, $tenantId), 1);
assertEq('T1 change due', $r1['change_due_grosze'] ?? -1, 1000);

// --- Test 2: idempotent retry on completed order ---
$pdo->beginTransaction();
$r2 = SettlementEngine::settleAndClose(
    $pdo,
    $oid1,
    $tenantId,
    $cashierId,
    [['method' => 'cash', 'amount' => 50.00]],
    []
);
$pdo->commit();

assertTrue('T2 idempotent success', $r2['success'] === true);
assertTrue('T2 idempotent flag', !empty($r2['idempotent']));
assertEq('T2 still one payment row', paymentRowCount($pdo, $oid1, $tenantId), 1);

cleanupOrder($pdo, $oid1, $tenantId);

// --- Test 3: prepaid header backfill (online_paid, no rows) ---
$oid3 = createReadyOrder($pdo, $tenantId, 3200, 'online_paid');
$pdo->prepare(
    "UPDATE sh_orders SET payment_method = 'online' WHERE id = :oid AND tenant_id = :tid"
)->execute([':oid' => $oid3, ':tid' => $tenantId]);

$pdo->beginTransaction();
$r3 = SettlementEngine::settleAndClose(
    $pdo,
    $oid3,
    $tenantId,
    $cashierId,
    [['method' => 'online', 'amount' => 32.00, 'transaction_id' => 'TXN-TEST-001']],
    []
);
$pdo->commit();

assertTrue('T3 success', $r3['success'] === true);
assertEq('T3 backfill row', paymentRowCount($pdo, $oid3, $tenantId), 1);

$stmtUid = $pdo->prepare(
    "SELECT user_id FROM sh_order_payments WHERE order_id = :oid AND tenant_id = :tid LIMIT 1"
);
$stmtUid->execute([':oid' => $oid3, ':tid' => $tenantId]);
assertEq('T3 cashier user_id', (int)$stmtUid->fetchColumn(), $cashierId);
$stmtUid->closeCursor();

cleanupOrder($pdo, $oid3, $tenantId);

// --- Test 4: split tender (30 cash + 20 card = 50) ---
$oid4 = createReadyOrder($pdo, $tenantId, 5000, 'to_pay');
$pdo->beginTransaction();
$r4 = SettlementEngine::settleAndClose(
    $pdo,
    $oid4,
    $tenantId,
    $cashierId,
    [
        ['method' => 'cash', 'amount' => 30.00, 'tendered' => 30.00],
        ['method' => 'card', 'amount' => 20.00, 'tendered' => 20.00],
    ],
    ['print_receipt' => true]
);
$pdo->commit();

assertTrue('T4 split success', $r4['success'] === true);
assertEq('T4 split rows', paymentRowCount($pdo, $oid4, $tenantId), 2);
assertTrue('T4 split_tender flag', !empty($r4['split_tender']));
$stmtM = $pdo->prepare("SELECT payment_method FROM sh_orders WHERE id = ?");
$stmtM->execute([$oid4]);
assertEq('T4 header mixed', $stmtM->fetchColumn(), 'mixed');
$stmtM->closeCursor();
cleanupOrder($pdo, $oid4, $tenantId);

// --- Test 5: partial payments (tables path) ---
$oid5 = createReadyOrder($pdo, $tenantId, 4000, 'to_pay');
$pdo->beginTransaction();
$r5 = SettlementEngine::applyPartialPayments(
    $pdo,
    $oid5,
    $tenantId,
    $cashierId,
    [['payment_method' => 'cash', 'amount' => 15.00]]
);
$pdo->commit();

assertTrue('T5 partial success', $r5['success'] === true);
assertEq('T5 partial rows', paymentRowCount($pdo, $oid5, $tenantId), 1);
assertTrue('T5 not fully paid', $r5['fully_paid'] === false);
assertEq('T5 remaining', $r5['remaining_grosze'], 2500);
cleanupOrder($pdo, $oid5, $tenantId);

// --- Test 6: reject empty payments ---
$oid6 = createReadyOrder($pdo, $tenantId, 1000, 'to_pay');
$pdo->beginTransaction();
$r6 = SettlementEngine::settle($pdo, $oid6, $tenantId, $cashierId, [], ['complete_order' => true]);
$pdo->rollBack();
assertTrue('T6 rejects empty', $r6['success'] === false);
cleanupOrder($pdo, $oid6, $tenantId);

echo $fail === 0 ? "\nALL SETTLEMENT ENGINE TESTS PASSED\n" : "\n{$fail} TEST(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
