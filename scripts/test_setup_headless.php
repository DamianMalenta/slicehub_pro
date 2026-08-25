<?php
declare(strict_types=1);

/**
 * SliceHub Enterprise — Headless Test Setup (CLI)
 * ============================================================
 * Wywoływany przez scripts/run_test_runner_headless.cjs PRZED startem suite'ów.
 * Kanoniczny kanał weryfikacji (headless) wymaga deterministycznego stanu DB:
 *
 *   1. Czyści sh_panic_log — eliminuje flakiness T58 (2-minutowy debounce
 *      PanicEngine::DEBOUNCE_MINUTES powoduje 429/RuntimeException przy
 *      re-runach suite'u w krótkim czasie).
 *   2. Wstawia deterministyczne dokumenty WZ (+) i KOR (−) powiązane z
 *      zamówieniem completed — aby asercja numeryczna T80 mogła zweryfikować,
 *      że KOR z ujemną wartością faktycznie redukuje zagregowany COGS
 *      zwracany przez BiEngine::aggregateCogsMinor().
 *
 * Idempotentny: usuwa tylko własne dokumenty (doc_number LIKE 'TEST-COGS-%').
 * Nie usuwa prawdziwych dokumentów magazynowych.
 *
 * Użycie CLI:  php scripts/test_setup_headless.php
 * HTTP:        zablokowany (wymagany SLICEHUB_SCRIPT_KEY, jak nuclear_reset.php).
 */

if (PHP_SAPI !== 'cli') {
    $localSecrets = __DIR__ . '/../core/local_secrets.php';
    if (is_file($localSecrets)) require_once $localSecrets;
    $expectedKey = defined('SLICEHUB_SCRIPT_KEY') ? (string) constant('SLICEHUB_SCRIPT_KEY') : '';
    $givenKey = (string)($_SERVER['HTTP_X_SCRIPT_KEY'] ?? $_GET['key'] ?? $_POST['key'] ?? '');
    if ($expectedKey === '' || !hash_equals($expectedKey, $givenKey)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['success' => false, 'message' => 'Brak/zły klucz dostępu (SLICEHUB_SCRIPT_KEY).']));
    }
}

$steps = [];

try {
    require_once __DIR__ . '/../core/db_config.php';
    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    // ── 1. Czyść sh_panic_log (debounce PanicEngine) ──────────────────────
    $panicBefore = (int) $pdo->query('SELECT COUNT(*) FROM sh_panic_log')->fetchColumn();
    $pdo->exec('DELETE FROM sh_panic_log');
    $panicAfter = (int) $pdo->query('SELECT COUNT(*) FROM sh_panic_log')->fetchColumn();
    $steps[] = [
        'step' => 'clear_panic_log',
        'ok' => $panicAfter === 0,
        'removed' => $panicBefore,
        'remaining' => $panicAfter,
    ];

    // ── 2. Deterministyczne dane COGS (WZ+ / KOR−) dla T80 ────────────────
    // Najpierw usuń własne dokumenty testowe (idempotentność).
    $pdo->exec(
        "DELETE FROM wh_document_lines
         WHERE document_id IN (
             SELECT id FROM wh_documents WHERE doc_number LIKE 'TEST-COGS-%'
         )"
    );
    $pdo->exec("DELETE FROM wh_documents WHERE doc_number LIKE 'TEST-COGS-%'");

    // Znajdź completed order w tenant 1 (BiEngine COGS filtruje o.status='completed').
    $stmtOrder = $pdo->prepare(
        "SELECT id FROM sh_orders
         WHERE tenant_id = 1 AND status = 'completed'
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $stmtOrder->execute();
    $orderId = $stmtOrder->fetchColumn();

    $cogsStep = ['step' => 'ensure_test_cogs_docs', 'ok' => false, 'order_id' => null,
                 'wz_doc_number' => null, 'kor_doc_number' => null,
                 'wz_line_net' => null, 'kor_line_net' => null];

    if ($orderId === false || $orderId === null) {
        $cogsStep['error'] = 'Brak completed order w tenant 1 — test COGS nie może założyć danych.';
        $steps[] = $cogsStep;
    } else {
        $orderId = (string) $orderId;
        $cogsStep['order_id'] = $orderId;

        // WZ (+50.00) — koszt pozytywny.
        $pdo->prepare(
            "INSERT INTO wh_documents
                (tenant_id, doc_number, type, warehouse_id, order_id, status, notes, created_at)
             VALUES (1, 'TEST-COGS-WZ-001', 'WZ', 'MAIN', :oid, 'completed', 'Headless test setup COGS', NOW())"
        )->execute([':oid' => $orderId]);
        $wzId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO wh_document_lines
                (document_id, sku, quantity, unit_net_cost, line_net_value, vat_rate)
             VALUES (:did, 'TEST-COGS-SKU', 1.0000, 50.0000, 50.00, 0.00)"
        )->execute([':did' => $wzId]);

        // KOR (−20.00) — korekta ujemna; musi redukować COGS.
        $pdo->prepare(
            "INSERT INTO wh_documents
                (tenant_id, doc_number, type, warehouse_id, order_id, references_wz, status, notes, created_at)
             VALUES (1, 'TEST-COGS-KOR-001', 'KOR', 'MAIN', :oid, 'TEST-COGS-WZ-001', 'completed', 'Headless test setup COGS (KOR)', NOW())"
        )->execute([':oid' => $orderId]);
        $korId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO wh_document_lines
                (document_id, sku, quantity, unit_net_cost, line_net_value, vat_rate)
             VALUES (:did, 'TEST-COGS-SKU', 1.0000, 20.0000, -20.00, 0.00)"
        )->execute([':did' => $korId]);

        $cogsStep['ok'] = true;
        $cogsStep['wz_doc_number'] = 'TEST-COGS-WZ-001';
        $cogsStep['kor_doc_number'] = 'TEST-COGS-KOR-001';
        $cogsStep['wz_line_net'] = 50.00;
        $cogsStep['kor_line_net'] = -20.00;
        $steps[] = $cogsStep;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Headless test setup complete.',
        'steps' => $steps,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[test_setup_headless] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Setup failed: ' . $e->getMessage(),
        'steps' => $steps,
    ], JSON_UNESCAPED_UNICODE);
    exit(1);
}
