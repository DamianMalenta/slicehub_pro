<?php
// CLI test script for ChoiceQRAdapter — functional verification (P1)
// Usage: php scripts/test_choiceqr_adapter.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../core/db_config.php';
require_once __DIR__ . '/../core/Integrations/BaseAdapter.php';
require_once __DIR__ . '/../core/Integrations/ChoiceQRAdapter.php';

use SliceHub\Integrations\ChoiceQRAdapter;
use SliceHub\Integrations\AdapterException;

$pass = 0;
$fail = 0;

function assert_true(bool $cond, string $msg): void
{
    global $pass, $fail;
    if ($cond) {
        echo "  PASS: {$msg}\n";
        $pass++;
    } else {
        echo "  FAIL: {$msg}\n";
        $fail++;
    }
}

function assert_eq(mixed $expected, mixed $actual, string $msg): void
{
    global $pass, $fail;
    if ($expected === $actual) {
        echo "  PASS: {$msg}\n";
        $pass++;
    } else {
        echo "  FAIL: {$msg} (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")\n";
        $fail++;
    }
}

echo "=== ChoiceQRAdapter Functional Tests ===\n\n";

// ── Test 1: providerKey & displayName ──────────────────────────────
echo "Test 1: Static identity\n";
assert_eq('choiceqr', ChoiceQRAdapter::providerKey(), 'providerKey() returns "choiceqr"');
assert_eq('ChoiceQR POS', ChoiceQRAdapter::displayName(), 'displayName() returns "ChoiceQR POS"');

// ── Test 2: supportsEvent ──────────────────────────────────────────
echo "\nTest 2: supportsEvent filtering\n";
$mockIntegration = [
    'id' => 1,
    'tenant_id' => 1,
    'api_base_url' => 'https://open-api.choiceqr.com',
    'credentials' => json_encode(['token' => 'test_jwt', 'webhook_token' => 'test_secret', 'var_symbol' => '10102']),
    'events_bridged' => json_encode(['order.created', 'order.cancelled', 'order.ready', 'order.delivered', 'order.completed', 'order.dispatched', 'order.in_delivery']),
    'is_active' => 1,
    'timeout_seconds' => 8,
    'max_retries' => 6,
];
$adapter = new ChoiceQRAdapter($mockIntegration);

assert_true($adapter->supportsEvent('order.cancelled'), 'supports order.cancelled');
assert_true($adapter->supportsEvent('order.ready'), 'supports order.ready');
assert_true($adapter->supportsEvent('order.delivered'), 'supports order.delivered');
assert_true($adapter->supportsEvent('order.completed'), 'supports order.completed');
assert_true($adapter->supportsEvent('order.dispatched'), 'supports order.dispatched');
assert_true($adapter->supportsEvent('order.in_delivery'), 'supports order.in_delivery');
assert_true(!$adapter->supportsEvent('order.created'), 'does NOT support order.created (inbound)');
assert_true(!$adapter->supportsEvent('order.accepted'), 'does NOT support order.accepted (inbound)');
assert_true(!$adapter->supportsEvent('order.preparing'), 'does NOT support order.preparing (no ChoiceQR endpoint)');
assert_true(!$adapter->supportsEvent('order.edited'), 'does NOT support order.edited');

// ── Test 3: buildRequest — order.cancelled ────────────────────────
echo "\nTest 3: buildRequest order.cancelled\n";
$envelopeCancelled = [
    'event_id' => '999',
    'event_type' => 'order.cancelled',
    'aggregate_id' => 'order-uuid-123',
    'tenant_id' => 1,
    'source' => 'pos',
    'payload' => [
        'order' => [
            'id' => 'order-uuid-123',
            'order_number' => 'CQR/20260729/0001',
            'gateway_source' => 'choiceqr',
            'gateway_external_id' => '507f1f77bcf86cd799439011',
            '_context' => ['cancellation_reason' => 'customer_requested'],
        ],
    ],
];

try {
    $req = $adapter->buildRequest($envelopeCancelled);
    assert_eq('PUT', $req['method'], 'method is PUT');
    assert_true(str_ends_with($req['url'], '/orders/507f1f77bcf86cd799439011/cancel'), 'URL ends with /orders/{_id}/cancel');
    assert_true(str_contains($req['url'], 'https://open-api.choiceqr.com'), 'URL uses api_base_url');
    assert_true(in_array('Authorization: Bearer test_jwt', $req['headers']), 'Authorization header present');

    $body = json_decode($req['body'], true);
    assert_eq('customer_requested', $body['reason'], 'body contains cancellation reason');
} catch (Throwable $e) {
    assert_true(false, 'buildRequest threw: ' . $e->getMessage());
}

// ── Test 4: buildRequest — order.ready → close ────────────────────
echo "\nTest 4: buildRequest order.ready → close\n";
$envelopeReady = [
    'event_id' => '998',
    'event_type' => 'order.ready',
    'aggregate_id' => 'order-uuid-456',
    'tenant_id' => 1,
    'source' => 'kds',
    'payload' => [
        'order' => [
            'id' => 'order-uuid-456',
            'gateway_external_id' => '507f1f77bcf86cd799439022',
        ],
    ],
];

try {
    $req = $adapter->buildRequest($envelopeReady);
    assert_eq('PUT', $req['method'], 'method is PUT');
    assert_true(str_ends_with($req['url'], '/orders/507f1f77bcf86cd799439022/close'), 'URL ends with /orders/{_id}/close');
    assert_eq('', $req['body'], 'body is empty for close (no payload)');
} catch (Throwable $e) {
    assert_true(false, 'buildRequest threw: ' . $e->getMessage());
}

// ── Test 5: buildRequest — order.in_delivery → delivery progress ──
echo "\nTest 5: buildRequest order.in_delivery → delivery progress\n";
$envelopeDelivery = [
    'event_id' => '997',
    'event_type' => 'order.in_delivery',
    'aggregate_id' => 'order-uuid-789',
    'tenant_id' => 1,
    'source' => 'courses',
    'payload' => [
        'order' => [
            'id' => 'order-uuid-789',
            'gateway_external_id' => '507f1f77bcf86cd799439033',
        ],
    ],
];

try {
    $req = $adapter->buildRequest($envelopeDelivery);
    assert_eq('PUT', $req['method'], 'method is PUT');
    assert_true(str_ends_with($req['url'], '/orders/507f1f77bcf86cd799439033/delivery'), 'URL ends with /orders/{_id}/delivery');
    $body = json_decode($req['body'], true);
    assert_eq('progress', $body['deliveryStatus'], 'deliveryStatus is "progress"');
} catch (Throwable $e) {
    assert_true(false, 'buildRequest threw: ' . $e->getMessage());
}

// ── Test 6: buildRequest — order.dispatched → delivery waitingForPickUp
echo "\nTest 6: buildRequest order.dispatched → delivery waitingForPickUp\n";
$envelopeDispatched = [
    'event_id' => '996',
    'event_type' => 'order.dispatched',
    'aggregate_id' => 'order-uuid-disp',
    'tenant_id' => 1,
    'source' => 'courses',
    'payload' => [
        'order' => [
            'id' => 'order-uuid-disp',
            'gateway_external_id' => '507f1f77bcf86cd799439044',
        ],
    ],
];

try {
    $req = $adapter->buildRequest($envelopeDispatched);
    assert_eq('PUT', $req['method'], 'method is PUT');
    assert_true(str_ends_with($req['url'], '/orders/507f1f77bcf86cd799439044/delivery'), 'URL ends with /orders/{_id}/delivery');
    $body = json_decode($req['body'], true);
    assert_eq('waitingForPickUp', $body['deliveryStatus'], 'deliveryStatus is "waitingForPickUp"');
} catch (Throwable $e) {
    assert_true(false, 'buildRequest threw: ' . $e->getMessage());
}

// ── Test 7: buildRequest — missing gateway_external_id → AdapterException
echo "\nTest 7: buildRequest missing gateway_external_id → AdapterException\n";
$envelopeNoExtId = [
    'event_id' => '995',
    'event_type' => 'order.cancelled',
    'aggregate_id' => 'order-uuid-noext',
    'tenant_id' => 1,
    'source' => 'pos',
    'payload' => [
        'order' => [
            'id' => 'order-uuid-noext',
            // No gateway_external_id, no gateway_source
        ],
    ],
];

try {
    $adapter->buildRequest($envelopeNoExtId);
    assert_true(false, 'Should have thrown AdapterException');
} catch (AdapterException $e) {
    assert_true(true, 'Throws AdapterException when gateway_external_id missing');
} catch (Throwable $e) {
    assert_true(false, 'Wrong exception type: ' . get_class($e));
}

// ── Test 8: buildRequest — non-choiceqr order → AdapterException ──
echo "\nTest 8: buildRequest non-choiceqr order → AdapterException\n";
$envelopeNotCqr = [
    'event_id' => '994',
    'event_type' => 'order.cancelled',
    'aggregate_id' => 'order-uuid-notcqr',
    'tenant_id' => 1,
    'source' => 'pos',
    'payload' => [
        'order' => [
            'id' => 'order-uuid-notcqr',
            'gateway_source' => 'papu',
            // No gateway_external_id
        ],
    ],
];

try {
    $adapter->buildRequest($envelopeNotCqr);
    assert_true(false, 'Should have thrown AdapterException for non-choiceqr order');
} catch (AdapterException $e) {
    assert_true(true, 'Throws AdapterException for non-choiceqr order');
} catch (Throwable $e) {
    assert_true(false, 'Wrong exception type: ' . get_class($e));
}

// ── Test 9: buildRequest — missing token credential → AdapterException
// NOTE: BaseAdapter::credentials() uses static $cache shared across all instances.
// To test missing-token, we must run this in a separate PHP process.
echo "\nTest 9: buildRequest missing token → AdapterException (subprocess)\n";
$baseDir = realpath(__DIR__ . '/..');
$testCode = <<<'PHP'
require_once '__BASEDIR__/core/Integrations/BaseAdapter.php';
require_once '__BASEDIR__/core/Integrations/ChoiceQRAdapter.php';
$bad = new SliceHub\Integrations\ChoiceQRAdapter([
    'id' => 2, 'tenant_id' => 1,
    'api_base_url' => 'https://open-api.choiceqr.com',
    'credentials' => json_encode(['webhook_token' => 'x', 'var_symbol' => '10102']),
    'events_bridged' => '[]', 'is_active' => 1, 'timeout_seconds' => 8, 'max_retries' => 6,
]);
$env = [
    'event_type' => 'order.cancelled',
    'aggregate_id' => 'x',
    'payload' => ['order' => ['id' => 'x', 'gateway_external_id' => '507f1f77bcf86cd799439011']],
];
try {
    $bad->buildRequest($env);
    echo "NO_THROW";
} catch (SliceHub\Integrations\AdapterException $e) {
    echo "ADAPTER_EXCEPTION";
} catch (Throwable $e) {
    echo "OTHER:" . get_class($e);
}
PHP;
$testCode = str_replace('__BASEDIR__', $baseDir, $testCode);
$tmpFile = tempnam(sys_get_temp_dir(), 'cqr_test_');
file_put_contents($tmpFile, $testCode);
$subOutput = shell_exec("php {$tmpFile} 2>&1");
unlink($tmpFile);
assert_true(str_contains($subOutput, 'ADAPTER_EXCEPTION'), 'Throws AdapterException when token missing (subprocess)');

// ── Test 10: resolveExternalId from _context (webhook publish path) ──
echo "\nTest 10: resolveExternalId from _context fallback\n";
$envelopeContextOnly = [
    'event_id' => '993',
    'event_type' => 'order.ready',
    'aggregate_id' => 'order-uuid-ctx',
    'tenant_id' => 1,
    'source' => 'pos',
    'payload' => [
        'order' => [
            'id' => 'order-uuid-ctx',
            // No gateway_external_id in order snapshot
            '_context' => [
                'gateway_external_id' => '507f1f77bcf86cd799439055',
                'gateway_source' => 'choiceqr',
            ],
        ],
    ],
];

try {
    $req = $adapter->buildRequest($envelopeContextOnly);
    assert_true(str_ends_with($req['url'], '/orders/507f1f77bcf86cd799439055/close'), 'URL uses _context.gateway_external_id');
} catch (Throwable $e) {
    assert_true(false, 'buildRequest threw: ' . $e->getMessage());
}

// ── Test 11: parseResponse — inherited from BaseAdapter ───────────
echo "\nTest 11: parseResponse (inherited from BaseAdapter)\n";
$result = $adapter->parseResponse(200, '{}', null);
assert_true($result['ok'] === true, '200 → ok=true');

$result = $adapter->parseResponse(204, '', null);
assert_true($result['ok'] === true, '204 → ok=true');

$result = $adapter->parseResponse(400, '{"error":"bad"}', null);
assert_true($result['ok'] === false, '400 → ok=false');
assert_true($result['transient'] === false, '400 → transient=false (permanent)');

$result = $adapter->parseResponse(500, 'Server Error', null);
assert_true($result['ok'] === false, '500 → ok=false');
assert_true($result['transient'] === true, '500 → transient=true (retry)');

$result = $adapter->parseResponse(429, 'Rate limited', null);
assert_true($result['ok'] === false, '429 → ok=false');
assert_true($result['transient'] === true, '429 → transient=true (retry)');

$result = $adapter->parseResponse(0, '', 'Connection timed out');
assert_true($result['ok'] === false, 'transport error → ok=false');
assert_true($result['transient'] === true, 'transport error → transient=true');

// ── Test 12: AdapterRegistry integration ──────────────────────────
echo "\nTest 12: AdapterRegistry recognizes choiceqr\n";
require_once __DIR__ . '/../core/Integrations/AdapterRegistry.php';
$providers = SliceHub\Integrations\AdapterRegistry::availableProviders();
assert_true(isset($providers['choiceqr']), 'choiceqr in availableProviders()');
assert_eq('ChoiceQR POS', $providers['choiceqr'], 'choiceqr display name correct');

// ── Summary ────────────────────────────────────────────────────────
echo "\n=== Summary: {$pass} pass / {$fail} fail ===\n";
exit($fail > 0 ? 1 : 0);
