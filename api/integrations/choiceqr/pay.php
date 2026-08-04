<?php
// =============================================================================
// SliceHub Enterprise — ChoiceQR Pay Table Order (Pay Table Order URL)
// POST /api/integrations/choiceqr/pay.php?t=SECRET_TOKEN
//
// ChoiceQR wysyła tu potwierdzenie płatności QR przy stoliku (split/close).
// Payload (tablePayOrderSchema, /content/pos/index.md):
//   { order: { _id, items, total, tips, customer, paymentCustomerDetails }, varSymbol }
//
// QR płatność przy stoliku jest ZAWSZE online, więc payment_status -> 'online_paid',
// payment_method -> 'online', tip_amount <- order.tips. Publikuje event
// order.payment_completed z paymentCustomerDetails w kontekście.
//
// Response: 200 OK empty body (wymóg ChoiceQR — brak 200 = anulowanie po 3 próbie).
// =============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

function cqr_pay_ok(): never
{
    http_response_code(200);
    echo '';
    exit;
}

function cqr_pay_fail(int $code, string $msg): never
{
    // ChoiceQR wymaga 200 OK empty body nawet przy błędach (inaczej retry/anulowanie)
    http_response_code(200);
    error_log('[ChoiceQR Pay] ' . $code . ' — ' . $msg);
    echo '';
    exit;
}

function cqr_pay_update_callback(PDO $pdo, ?int $callbackLogId, array $fields): void
{
    if ($callbackLogId === null || empty($fields)) {
        return;
    }
    $sets = [];
    $params = [':id' => $callbackLogId];
    foreach ($fields as $k => $v) {
        $sets[] = $k . ' = :' . $k;
        $params[':' . $k] = $v;
    }
    try {
        $pdo->prepare("UPDATE sh_inbound_callbacks SET " . implode(', ', $sets) . " WHERE id = :id")
            ->execute($params);
    } catch (PDOException $e) {
        // ignore
    }
}

try {
    require_once __DIR__ . '/../../../core/db_config.php';
    require_once __DIR__ . '/../../../core/GatewayAuth.php';
    require_once __DIR__ . '/../../../core/CredentialVault.php';

    if (!isset($pdo)) {
        cqr_pay_fail(500, 'Database connection unavailable');
    }

    // -------------------------------------------------------------------------
    // 1. AUTH — token w query param
    // -------------------------------------------------------------------------
    $providedToken = trim((string)($_GET['t'] ?? ''));
    if ($providedToken === '') {
        cqr_pay_fail(401, 'Missing token');
    }

    // -------------------------------------------------------------------------
    // 2. PARSE PAYLOAD
    // -------------------------------------------------------------------------
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        cqr_pay_fail(400, 'Invalid JSON payload');
    }

    $order = $input['order'] ?? null;
    $varSymbol = trim((string)($input['varSymbol'] ?? ''));

    if (!is_array($order) || $varSymbol === '') {
        cqr_pay_fail(400, 'Missing order or varSymbol');
    }

    // tablePayOrderSchema: order._id = internal POS order ID (do matchowania
    // z sh_orders.id lub gateway_external_id). order.total / order.tips w groszach.
    $orderId = trim((string)($order['_id'] ?? ''));
    if ($orderId === '') {
        cqr_pay_fail(400, 'Missing order._id');
    }

    $payTotal = (int)($order['total'] ?? 0);
    $payTips  = (int)($order['tips'] ?? 0);
    $paymentCustomerDetails = is_array($order['paymentCustomerDetails'] ?? null)
        ? $order['paymentCustomerDetails']
        : null;

    // -------------------------------------------------------------------------
    // 3. TENANT MAPPING
    // -------------------------------------------------------------------------
    $stmtTi = $pdo->prepare(
        "SELECT id, tenant_id, credentials, is_active
         FROM sh_tenant_integrations
         WHERE provider = 'choiceqr' AND is_active = 1"
    );
    $stmtTi->execute();
    $integrations = $stmtTi->fetchAll(PDO::FETCH_ASSOC);

    $tenantId = 0;
    $webhookToken = null;

    foreach ($integrations as $ti) {
        $credRaw = (string)$ti['credentials'];
        $credJson = CredentialVault::isEncrypted($credRaw) ? (CredentialVault::decrypt($credRaw) ?? '') : $credRaw;
        $creds = json_decode($credJson, true);
        if (!is_array($creds)) {
            $creds = [];
        }
        if ((string)($creds['var_symbol'] ?? '') === $varSymbol) {
            $tenantId = (int)$ti['tenant_id'];
            $webhookToken = (string)($creds['webhook_token'] ?? '');
            break;
        }
    }

    if ($tenantId <= 0) {
        cqr_pay_fail(403, "No tenant integration for varSymbol='{$varSymbol}'");
    }
    if ($webhookToken === '' || !hash_equals($webhookToken, $providedToken)) {
        cqr_pay_fail(401, 'Invalid webhook token');
    }

    // -------------------------------------------------------------------------
    // 4. FEATURE-DETECT sh_inbound_callbacks + log callback
    // -------------------------------------------------------------------------
    $hasInboundTable = true;
    try {
        $pdo->query("SELECT 1 FROM sh_inbound_callbacks LIMIT 0")->closeCursor();
    } catch (PDOException $e) {
        $hasInboundTable = false;
    }

    $callbackLogId = null;
    if ($hasInboundTable) {
        try {
            $stmtLog = $pdo->prepare(
                "INSERT INTO sh_inbound_callbacks
                    (provider, integration_id, raw_headers, raw_body, remote_ip, status, received_at)
                 VALUES
                    ('choiceqr', 0, :hdrs, :body, :ip, 'pending', NOW())"
            );
            $stmtLog->execute([
                ':hdrs' => json_encode(['Content-Type' => $_SERVER['CONTENT_TYPE'] ?? ''], JSON_UNESCAPED_UNICODE),
                ':body' => strlen($raw) > 65000 ? substr($raw, 0, 65000) . '...[TRUNCATED]' : $raw,
                ':ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            $callbackLogId = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            // ignore
        }
    }

    // -------------------------------------------------------------------------
    // 5. IDEMPOTENCY — check if payment already processed
    // Klucz (wg tablePayOrderSchema): rro.transactionId > paymentOrderNum >
    // fallback order._id + order.total (jedno zamówienie przy stoliku może mieć
    // wiele płatności częściowych, więc sama kombinacja zamówienie + kwota daje
    // sensowną unikalność gdy brak identyfikatora transakcji).
    // -------------------------------------------------------------------------
    $rro = is_array($paymentCustomerDetails['rro'] ?? null) ? $paymentCustomerDetails['rro'] : [];
    $txnId = trim((string)($rro['transactionId'] ?? ''));
    $paymentOrderNum = trim((string)($paymentCustomerDetails['paymentOrderNum'] ?? ''));

    if ($txnId !== '') {
        $idempotencyKey = 'txn:' . $txnId;
    } elseif ($paymentOrderNum !== '') {
        $idempotencyKey = 'pon:' . $paymentOrderNum;
    } else {
        $idempotencyKey = 'pay:' . $orderId . ':' . $payTotal;
    }

    $existingRef = GatewayAuth::lookupExternalRef($pdo, $tenantId, 'choiceqr_pay', $idempotencyKey);
    if ($existingRef !== null) {
        cqr_pay_update_callback($pdo, $callbackLogId, [
            'status' => 'ignored',
            'error_message' => 'duplicate payment',
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
        cqr_pay_ok();
    }

    // -------------------------------------------------------------------------
    // 6. FIND ORDER + UPDATE PAYMENT STATUS (all inside transaction)
    // -------------------------------------------------------------------------
    $tz = new DateTimeZone('Europe/Warsaw');
    $nowDb = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');

    $pdo->beginTransaction();

    try {
        // Find order inside transaction (with FOR UPDATE to prevent race)
        $stmtOrder = $pdo->prepare(
            "SELECT id, status, payment_status, grand_total, order_type
             FROM sh_orders
             WHERE tenant_id = :tid AND id = :oid
             LIMIT 1 FOR UPDATE"
        );
        $stmtOrder->execute([':tid' => $tenantId, ':oid' => $orderId]);
        $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            // Also try by gateway_external_id
            try {
                $pdo->query("SELECT gateway_source FROM sh_orders LIMIT 0");
                $stmtGw = $pdo->prepare(
                    "SELECT id, status, payment_status, grand_total, order_type
                     FROM sh_orders
                     WHERE tenant_id = :tid
                       AND gateway_source = 'choiceqr' AND gateway_external_id = :eid
                     LIMIT 1 FOR UPDATE"
                );
                $stmtGw->execute([':tid' => $tenantId, ':eid' => $orderId]);
                $order = $stmtGw->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // gateway columns not available
            }
        }

        if (!$order) {
            $pdo->rollBack();
            error_log("[ChoiceQR Pay] Order not found: _id={$orderId} varSymbol={$varSymbol}");
            cqr_pay_update_callback($pdo, $callbackLogId, [
                'status' => 'rejected',
                'error_message' => 'order not found: ' . $orderId,
                'processed_at' => date('Y-m-d H:i:s'),
            ]);
            cqr_pay_ok();
        }

        $internalOrderId = (string)$order['id'];
        $currentPayStatus = (string)$order['payment_status'];

        // QR płatność przy stoliku jest ZAWSZE online (wg /content/pos/index.md).
        $newPaymentStatus = 'online_paid';
        $paymentMethod = 'online';

        // Validate amount vs grand_total (log warning on mismatch, don't reject).
        // tablePayOrderSchema: order.total = kwota opłacona (z discount, tips).
        $grandTotal = (int)($order['grand_total'] ?? 0);
        if ($payTotal > 0 && $grandTotal > 0 && $payTotal !== $grandTotal) {
            error_log(sprintf(
                '[ChoiceQR Pay] Amount mismatch: order=%s expected=%d got=%d (key=%s)',
                $internalOrderId, $grandTotal, $payTotal, $idempotencyKey
            ));
        }

        // Skip if already paid online (idempotent fast-path inside transaction).
        if ($currentPayStatus === $newPaymentStatus) {
            $pdo->rollBack();
            GatewayAuth::storeExternalRef(
                $pdo, $tenantId, 'choiceqr_pay', $idempotencyKey,
                $internalOrderId, null, hash('sha256', $raw)
            );
            cqr_pay_update_callback($pdo, $callbackLogId, [
                'status' => 'processed',
                'mapped_order_id' => $internalOrderId,
                'processed_at' => date('Y-m-d H:i:s'),
            ]);
            cqr_pay_ok();
        }

        // Update payment status + tip + method (tenant_id in WHERE for multi-tenant safety).
        $stmtUpd = $pdo->prepare(
            "UPDATE sh_orders
             SET payment_status = :ps,
                 payment_method = :pm,
                 tip_amount     = :tip,
                 updated_at     = :now
             WHERE id = :oid AND tenant_id = :tid"
        );
        $stmtUpd->execute([
            ':ps'  => $newPaymentStatus,
            ':pm'  => $paymentMethod,
            ':tip' => $payTips,
            ':now' => $nowDb,
            ':oid' => $internalOrderId,
            ':tid' => $tenantId,
        ]);

        // Publish event — przekazujemy paymentCustomerDetails w kontekście.
        require_once __DIR__ . '/../../../core/OrderEventPublisher.php';
        OrderEventPublisher::publishOrderLifecycle(
            $pdo, $tenantId, 'order.payment_completed', $internalOrderId,
            [
                'source'              => 'choiceqr_pay',
                'gateway_external_id' => $orderId,
                'payment_method'      => $paymentMethod,
                'paid_amount'         => $payTotal,
                'tips'                => $payTips,
                'transaction_id'      => $txnId,
                'payment_order_num'   => $paymentOrderNum,
                'qr_payment'          => true,
                'paymentCustomerDetails' => $paymentCustomerDetails,
            ],
            [
                'source'         => 'gateway',
                'actorType'      => 'external_api',
                'actorId'        => 'choiceqr:' . $varSymbol,
                'idempotencyKey' => $idempotencyKey . ':order.payment_completed',
            ]
        );

        // Store idempotency ref
        GatewayAuth::storeExternalRef(
            $pdo, $tenantId, 'choiceqr_pay', $idempotencyKey,
            $internalOrderId, null, hash('sha256', $raw)
        );

        $pdo->commit();

    } catch (Throwable $txErr) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $txErr;
    }

    cqr_pay_update_callback($pdo, $callbackLogId, [
        'status'          => 'processed',
        'mapped_order_id' => $internalOrderId,
        'processed_at'    => date('Y-m-d H:i:s'),
    ]);

    // -------------------------------------------------------------------------
    // 7. SUCCESS — 200 OK empty body
    // -------------------------------------------------------------------------
    cqr_pay_ok();

} catch (Throwable $e) {
    error_log('[ChoiceQR Pay] FATAL: ' . $e->getMessage());
    cqr_pay_fail(500, 'Internal server error: ' . $e->getMessage());
}