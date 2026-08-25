<?php
declare(strict_types=1);

// =============================================================================
// SliceHub Enterprise — Order Revert (RFC-001 Faza 3)
// POST /api/orders/revert.php
//
// Cofnięcie zamówienia do stanu ze snapshotu w sh_event_outbox.
// RBAC: owner | admin.
//
// Request:  { action: "revert", order_id, snapshot_event_id, reason }
// Response: { order_id, reverted_to_ts, reverted_to_event, new_snapshot_event_id }
// =============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method Not Allowed. Use POST.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function revertOut(bool $ok, $data = null, ?string $msg = null, int $http = 200): void
{
    http_response_code($http);
    echo json_encode(
        ['success' => $ok, 'data' => $data, 'message' => $msg],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function revertLoadRole(PDO $pdo, int $tid, int $uid): string
{
    $st = $pdo->prepare(
        'SELECT LOWER(role) FROM sh_users WHERE id = :uid AND tenant_id = :tid AND is_deleted = 0 LIMIT 1'
    );
    $st->execute([':uid' => $uid, ':tid' => $tid]);
    $r = $st->fetchColumn();
    return is_string($r) ? $r : '';
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/../../core/Uuid.php';
    require_once __DIR__ . '/../../core/OrderEventPublisher.php';
    require_once __DIR__ . '/../../core/OrderStateMachine.php';
    require_once __DIR__ . '/../../core/WarehouseReverseHook.php';
    require_once __DIR__ . '/DeltaEngine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    // ── RBAC: owner / admin ──────────────────────────────────────────
    $role = revertLoadRole($pdo, $tenant_id, $user_id);
    if (!in_array($role, ['owner', 'admin'], true)) {
        revertOut(false, null, 'Forbidden: wymagana rola owner/admin.', 403);
    }

    // ── Parse input ───────────────────────────────────────────────────
    $raw   = file_get_contents('php://input') ?: '{}';
    $input = json_decode($raw, true) ?? [];
    $action = trim((string)($input['action'] ?? ''));

    if ($action !== 'revert') {
        revertOut(false, null, 'Nieznana akcja. Obsługiwana: "revert".', 400);
    }

    $orderId = trim((string)($input['order_id'] ?? ''));
    if ($orderId === '') {
        revertOut(false, null, 'order_id jest wymagane.', 400);
    }

    $snapshotEventId = (int)($input['snapshot_event_id'] ?? 0);
    if ($snapshotEventId <= 0) {
        revertOut(false, null, 'snapshot_event_id jest wymagane.', 400);
    }

    $reason = trim((string)($input['reason'] ?? ''));
    if (strlen($reason) < 10) {
        revertOut(false, null, 'Powód jest wymagany (min. 10 znaków).', 400);
    }

    // ── Load order + verify tenant ────────────────────────────────────
    $stmtOrder = $pdo->prepare(
        "SELECT id, status, order_type, payment_status, grand_total,
                fiscal_receipt_number, receipt_printed
         FROM sh_orders
         WHERE id = :id AND tenant_id = :tid
         LIMIT 1"
    );
    $stmtOrder->execute([':id' => $orderId, ':tid' => $tenant_id]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        revertOut(false, null, 'Zamówienie nie istnieje.', 404);
    }

    // ── Load snapshot from outbox ─────────────────────────────────────
    $stmtOut = $pdo->prepare(
        "SELECT id, event_type, payload, created_at
         FROM sh_event_outbox
         WHERE id = :eid AND tenant_id = :tid AND aggregate_id = :oid"
    );
    $stmtOut->execute([':eid' => $snapshotEventId, ':tid' => $tenant_id, ':oid' => $orderId]);
    $event = $stmtOut->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        revertOut(false, null, 'Snapshot nie istnieje lub nie należy do tego zamówienia.', 404);
    }

    $payload = json_decode((string)$event['payload'], true);
    if (!is_array($payload) || empty($payload['lines']) || !is_array($payload['lines'])) {
        revertOut(false, null, 'Snapshot nie zawiera poprawnych danych (brak linii).', 400);
    }

    $targetHeader = $payload;
    unset($targetHeader['_context'], $targetHeader['_meta'], $targetHeader['lines']);
    $targetLines = $payload['lines'];

    // ── Build current snapshot for sh_order_logs ──────────────────────
    $stmtSnap = $pdo->prepare(
        "SELECT id, tenant_id, order_number, channel, order_type, source,
                status, payment_status, payment_method,
                subtotal, discount_amount, delivery_fee, grand_total,
                customer_name, customer_phone, customer_email, delivery_address, notes,
                fiscal_receipt_number, receipt_printed,
                created_at, updated_at
         FROM sh_orders
         WHERE id = :id AND tenant_id = :tid
         LIMIT 1"
    );
    $stmtSnap->execute([':id' => $orderId, ':tid' => $tenant_id]);
    $currentHeader = $stmtSnap->fetch(PDO::FETCH_ASSOC);

    $stmtLinesSnap = $pdo->prepare(
        "SELECT id, item_sku, snapshot_name, unit_price, quantity, line_total,
                vat_rate, vat_amount, modifiers_json, removed_ingredients_json, comment
         FROM sh_order_lines
         WHERE order_id = :oid"
    );
    $stmtLinesSnap->execute([':oid' => $orderId]);
    $currentLines = $stmtLinesSnap->fetchAll(PDO::FETCH_ASSOC);

    // ── Compute delta between current and target for warehouse correction ─
    $delta = DeltaEngine::computeDelta($currentLines, $targetLines);

    // ── Transaction ───────────────────────────────────────────────────
    $pdo->beginTransaction();
    try {
        // 1. Save current state as new snapshot in sh_order_logs
        OrderStateMachine::writeLog($pdo, $orderId, $tenant_id, $user_id, 'revert', [
            'reason'          => $reason,
            'reverted_from'   => $currentHeader,
            'reverted_from_lines' => $currentLines,
            'reverted_to'     => $targetHeader,
            'reverted_to_event_id' => $snapshotEventId,
            'reverted_to_event_type' => $event['event_type'],
        ]);

        // 2. Delete current lines
        $pdo->prepare(
            "DELETE FROM sh_order_lines
             WHERE order_id = :oid AND order_id IN (SELECT id FROM sh_orders WHERE tenant_id = :tid)"
        )->execute([':oid' => $orderId, ':tid' => $tenant_id]);

        // 3. Insert target lines (new UUIDs to avoid collisions)
        $stmtIns = $pdo->prepare(
            "INSERT INTO sh_order_lines
                (id, order_id, item_sku, snapshot_name, unit_price,
                 quantity, line_total, vat_rate, vat_amount,
                 modifiers_json, removed_ingredients_json, comment)
             VALUES
                (:id, :oid, :sku, :name, :unit, :qty, :total, :vat_rate, :vat_amt,
                 :mods, :removed, :comment)"
        );
        foreach ($targetLines as $l) {
            $stmtIns->execute([
                ':id'      => Uuid::v4(),
                ':oid'     => $orderId,
                ':sku'     => $l['item_sku'],
                ':name'    => $l['snapshot_name'],
                ':unit'    => (int)($l['unit_price'] ?? 0),
                ':qty'     => (int)($l['quantity'] ?? 1),
                ':total'   => (int)($l['line_total'] ?? 0),
                ':vat_rate' => (float)($l['vat_rate'] ?? 0),
                ':vat_amt'  => (int)($l['vat_amount'] ?? 0),
                ':mods'    => $l['modifiers_json']          ?? $l['modifiers']          ?? null,
                ':removed' => $l['removed_ingredients_json'] ?? $l['removed_ingredients'] ?? null,
                ':comment' => $l['comment'] ?? null,
            ]);
        }

        // 4. Restore header (status, payment_status, grand_total, etc.)
        //    Nie przywracamy pól fiskalnych (fiscal_receipt_number, receipt_printed)
        //    oraz created_at (nie przesuwamy P&L do innego okresu).
        $newStatus    = (string)($targetHeader['status']    ?? $order['status']);
        $newPayStatus = (string)($targetHeader['payment_status'] ?? $order['payment_status']);
        $newPaymentMethod = (string)($targetHeader['payment_method'] ?? $newPayStatus);

        $stmtUpd = $pdo->prepare(
            "UPDATE sh_orders
             SET status          = :status,
                 payment_status  = :ps,
                 payment_method  = :pm,
                 subtotal        = :subtotal,
                 discount_amount = :discount,
                 delivery_fee    = :delivery,
                 grand_total     = :grand,
                 customer_name   = :cname,
                 customer_phone  = :cphone,
                 delivery_address = :addr,
                 notes           = :notes,
                 order_type      = :otype,
                 updated_at      = :now,
                 is_corrected    = 0
             WHERE id = :id AND tenant_id = :tid"
        );
        $stmtUpd->execute([
            ':status'   => $newStatus,
            ':ps'       => $newPayStatus,
            ':pm'       => $newPaymentMethod,
            ':subtotal' => (int)($targetHeader['subtotal'] ?? 0),
            ':discount' => (int)($targetHeader['discount_amount'] ?? 0),
            ':delivery' => (int)($targetHeader['delivery_fee'] ?? 0),
            ':grand'    => (int)($targetHeader['grand_total'] ?? 0),
            ':cname'    => $targetHeader['customer_name']   ?? null,
            ':cphone'   => $targetHeader['customer_phone']  ?? null,
            ':addr'     => $targetHeader['delivery_address'] ?? null,
            ':notes'    => $targetHeader['notes'] ?? null,
            ':otype'    => (string)($targetHeader['order_type'] ?? $order['order_type']),
            ':now'      => date('Y-m-d H:i:s'),
            ':id'       => $orderId,
            ':tid'      => $tenant_id,
        ]);

        // 5. Audit marker (status change if target status != current status)
        $stmtAudit = $pdo->prepare(
            "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
             VALUES (:oid, :uid, :old, :new, :now)"
        );
        $stmtAudit->execute([
            ':oid' => $orderId,
            ':uid' => $user_id,
            ':old' => $order['status'],
            ':new' => $newStatus,
            ':now' => date('Y-m-d H:i:s'),
        ]);

        // 6. Warehouse correction for changed lines if current order was closed
        $korDoc = null;
        $currentClosed = in_array($order['status'], ['completed', 'cancelled'], true);
        $targetClosed  = in_array($newStatus, ['completed', 'cancelled'], true);
        if ($currentClosed && !empty($delta)) {
            $korResult = WarehouseReverseHook::onOrderCorrected($pdo, $tenant_id, $orderId, $user_id, $delta);
            if ($korResult['success'] && !empty($korResult['doc_number'])) {
                $korDoc = $korResult['doc_number'];
            } elseif (empty($korResult['skipped'])) {
                error_log('[revert.php] WarehouseReverseHook::onOrderCorrected failed: ' . ($korResult['error'] ?? 'unknown'));
            }
        }

        // 7. Publish order.reverted with new snapshot
        $outboxId = OrderEventPublisher::publishOrderLifecycle(
            $pdo,
            $tenant_id,
            'order.reverted',
            $orderId,
            [
                'source'             => 'order_revert',
                'user_id'            => $user_id,
                'reason'             => $reason,
                'reverted_to_event'  => $event['event_type'],
                'reverted_to_event_id' => $snapshotEventId,
                'reverted_to_ts'     => $event['created_at'],
                'wh_kor_document'    => $korDoc,
            ],
            ['source' => 'system', 'actorType' => 'staff', 'actorId' => (string)$user_id]
        );

        $pdo->commit();

        revertOut(true, [
            'order_id'              => $orderId,
            'reverted_to_ts'        => $event['created_at'],
            'reverted_to_event'     => $event['event_type'],
            'reverted_to_status'    => $newStatus,
            'new_snapshot_event_id' => $outboxId,
            'wh_kor_document'       => $korDoc,
            'audit_logged'          => true,
        ]);
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
} catch (PDOException $e) {
    error_log('[orders/revert] PDOException: ' . $e->getMessage());
    revertOut(false, null, 'Błąd bazy danych.', 500);
} catch (Throwable $e) {
    error_log('[orders/revert] ' . $e->getMessage());
    revertOut(false, null, 'Błąd serwera.', 500);
}
