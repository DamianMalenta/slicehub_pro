<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/Symbiont/SessionAuthority.php';
require_once __DIR__ . '/../core/Symbiont/ExecutionGateway.php';

use SliceHub\Symbiont\SessionAuthority;
use SliceHub\Symbiont\ExecutionGateway;

$tmp = sys_get_temp_dir() . '/symbiont-execute-' . uniqid();
mkdir($tmp, 0700, true);
$operators = $tmp . '/operators.json';
$store = $tmp . '/sessions.json';
$orders = $tmp . '/orders.json';

$executeToken = bin2hex(random_bytes(32));
$sessionToken = bin2hex(random_bytes(32));
$hostId = 'slicehub-test';

putenv('SYMBIONT_SESSION_ENABLED=1');
putenv('SYMBIONT_SESSION_SERVICE_TOKEN=' . $sessionToken);
putenv('SYMBIONT_BRIDGE_HOST_ID=' . $hostId);
putenv('SYMBIONT_BRIDGE_ENVIRONMENT=test');
putenv('SYMBIONT_SESSION_FIXTURE=' . $operators);
putenv('SYMBIONT_SESSION_STORE=' . $store);
putenv('SYMBIONT_SESSION_TTL=300');
putenv('SYMBIONT_EXECUTE_ENABLED=1');
putenv('SYMBIONT_EXECUTE_SERVICE_TOKEN=' . $executeToken);
putenv('SYMBIONT_BUSINESS_CATALOG_FILE=' . __DIR__ . '/../core/Symbiont/contracts/business-catalog-v1.json');
putenv('SYMBIONT_ORDERS_FIXTURE_FILE=' . $orders);

file_put_contents($operators, json_encode(['operators' => [
    ['tenant_id' => 1, 'actor_id' => 'op-1', 'pin_code' => '1234', 'role' => 'operator'],
    ['tenant_id' => 1, 'actor_id' => 'mgr-1', 'pin_code' => '5678', 'role' => 'manager'],
]], JSON_THROW_ON_ERROR));
file_put_contents($store, json_encode(['sessions' => []], JSON_THROW_ON_ERROR));
file_put_contents($orders, json_encode(['orders' => [
    ['order_id' => 'O-1', 'tenant_id' => 1, 'status' => 'open', 'items' => ['pizza']],
    ['order_id' => 'O-2', 'tenant_id' => 1, 'status' => 'open', 'items' => ['pasta']],
    ['order_id' => 'O-3', 'tenant_id' => 1, 'status' => 'closed', 'items' => ['salad']],
    ['order_id' => 'O-4', 'tenant_id' => 2, 'status' => 'open', 'items' => ['soup']],
]], JSON_THROW_ON_ERROR));

$failures = 0;
$pass = function ($id, $cond) use (&$failures) {
    if ($cond) { echo "PASS: B004-P$id\n"; } else { echo "FAIL: B004-P$id\n"; $failures++; }
};

$sessionAuth = new SessionAuthority(SessionAuthority::environmentConfig());
[$code, $resp] = $sessionAuth->handle('POST', 'application/json', 'Bearer ' . $sessionToken, json_encode(['protocol' => 'symbiont.operator-session', 'version' => '1.0.0', 'request_id' => 'b004-create', 'operation' => 'session.create', 'tenant_id' => 1, 'actor_id' => 'op-1', 'pin_code' => '1234', 'role' => 'operator'], JSON_THROW_ON_ERROR), 'service', true);
$operatorSession = $resp['data']['session_token'] ?? '';
$pass('01', $code === 200 && $operatorSession !== '');

$gateway = new ExecutionGateway(ExecutionGateway::environmentConfig());

$request = function ($cap, $tenant, $session) {
    return json_encode(['protocol' => 'symbiont.capability-execute', 'version' => '1.0.0', 'request_id' => 'b004-r', 'operation' => 'capability.execute', 'capability_id' => $cap, 'session_token' => $session, 'tenant_id' => $tenant], JSON_THROW_ON_ERROR);
};

[$c, $r] = $gateway->handle('POST', 'application/json', 'Bearer ' . $executeToken, $request('orders.open.query', 1, $operatorSession), true);
$pass('02', $c === 200 && $r['ok'] === true && ($r['data']['business_effects'] ?? '') === 'read' && count($r['data']['result']['orders'] ?? []) === 2);

[$c, $r] = $gateway->handle('POST', 'application/json', 'Bearer ' . bin2hex(random_bytes(32)), $request('orders.open.query', 1, $operatorSession), true);
$pass('03', $c === 401 && $r['ok'] === false);

[$c, $r] = $gateway->handle('POST', 'application/json', 'Bearer ' . $executeToken, $request('orders.open.query', 2, $operatorSession), true);
$pass('04', $c === 403 && ($r['error'] ?? '') === 'INVALID_TENANT');

[$c, $r] = $gateway->handle('POST', 'application/json', 'Bearer ' . $executeToken, $request('kitchen.prep.start', 1, $operatorSession), true);
$pass('05', $c === 422 && ($r['error'] ?? '') === 'INVALID_CAPABILITY');

// manager session
try {
    @file_put_contents($store, json_encode(['sessions' => []], JSON_THROW_ON_ERROR));
} catch (\Throwable) {}
[$c, $resp] = $sessionAuth->handle('POST', 'application/json', 'Bearer ' . $sessionToken, json_encode(['protocol' => 'symbiont.operator-session', 'version' => '1.0.0', 'request_id' => 'b004-mgr', 'operation' => 'session.create', 'tenant_id' => 1, 'actor_id' => 'mgr-1', 'pin_code' => '5678', 'role' => 'manager'], JSON_THROW_ON_ERROR), 'service', true);
$managerSession = $resp['data']['session_token'] ?? '';
[$c, $r] = $gateway->handle('POST', 'application/json', 'Bearer ' . $executeToken, $request('orders.open.query', 1, $managerSession), true);
$pass('06', $c === 403 && ($r['error'] ?? '') === 'INVALID_ROLE');

// expired session
$expired = bin2hex(random_bytes(32));
$storeData = json_decode(file_get_contents($store), true, 16, JSON_THROW_ON_ERROR);
$storeData['sessions'][$expired] = ['tenant_id' => 1, 'actor_id' => 'op-1', 'role' => 'operator', 'expires_at' => gmdate('c', time() - 10)];
file_put_contents($store, json_encode($storeData, JSON_THROW_ON_ERROR));
[$c, $r] = $gateway->handle('POST', 'application/json', 'Bearer ' . $executeToken, $request('orders.open.query', 1, $expired), true);
$pass('07', $c === 401 && $r['ok'] === false);

// exact version enforcement
[$c, $r] = $gateway->handle('POST', 'application/json', 'Bearer ' . $executeToken, json_encode(['protocol' => 'symbiont.capability-execute', 'version' => '2.0.0', 'request_id' => 'b004-v', 'operation' => 'capability.execute', 'capability_id' => 'orders.open.query', 'session_token' => $operatorSession, 'tenant_id' => 1], JSON_THROW_ON_ERROR), true);
$pass('08', $c === 409 && ($r['error'] ?? '') === 'UNSUPPORTED_VERSION');

// extra field rejected
[$c, $r] = $gateway->handle('POST', 'application/json', 'Bearer ' . $executeToken, json_encode(['protocol' => 'symbiont.capability-execute', 'version' => '1.0.0', 'request_id' => 'b004-x', 'operation' => 'capability.execute', 'capability_id' => 'orders.open.query', 'session_token' => $operatorSession, 'tenant_id' => 1, 'malicious' => true], JSON_THROW_ON_ERROR), true);
$pass('09', $c === 400 && ($r['error'] ?? '') === 'INVALID_REQUEST');

// protocol mismatch
[$c, $r] = $gateway->handle('POST', 'application/json', 'Bearer ' . $executeToken, json_encode(['protocol' => 'symbiont.other', 'version' => '1.0.0', 'request_id' => 'b004-p', 'operation' => 'capability.execute', 'capability_id' => 'orders.open.query', 'session_token' => $operatorSession, 'tenant_id' => 1], JSON_THROW_ON_ERROR), true);
$pass('10', $c === 409 && ($r['error'] ?? '') === 'UNSUPPORTED_VERSION');

echo "Tests: 10, failures: $failures\n";
exit($failures > 0 ? 1 : 0);
