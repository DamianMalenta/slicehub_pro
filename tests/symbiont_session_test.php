<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/Symbiont/SessionAuthority.php';

use SliceHub\Symbiont\SessionAuthority;

$count = 0;
$failures = 0;
function checkSession(bool $ok, string $label): void
{
    global $count, $failures;
    $count++;
    if (!$ok) $failures++;
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . PHP_EOL;
}

$fixture = tempnam(sys_get_temp_dir(), 'session_fixture_');
$store = tempnam(sys_get_temp_dir(), 'session_store_');
file_put_contents($fixture, json_encode(['operators' => [
    ['tenant_id' => 1, 'actor_id' => 'test-operator-1', 'pin_code' => '1234', 'role' => 'operator'],
    ['tenant_id' => 1, 'actor_id' => 'test-manager-1', 'pin_code' => '5678', 'role' => 'manager']
]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents($store, json_encode(['sessions' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$service = bin2hex(random_bytes(32));
$base = ['enabled' => true, 'serviceToken' => $service, 'hostId' => 'slicehub-test', 'environment' => 'test', 'fixturePath' => $fixture, 'storePath' => $store, 'ttl' => 300];
$call = static function (array $request, ?string $token = null, string $audience = 'service', ?array $cfg = null, bool $secure = true) use ($base): array {
    $auth = $token === null ? '' : 'Bearer ' . $token;
    return (new SessionAuthority($cfg ?? $base))->handle('POST', 'application/json', $auth, json_encode($request, JSON_THROW_ON_ERROR), $audience, $secure);
};
$createRequest = ['protocol' => 'symbiont.operator-session', 'version' => '1.0.0', 'request_id' => 'b003-php-test', 'operation' => 'session.create', 'tenant_id' => 1, 'actor_id' => 'test-operator-1', 'pin_code' => '1234', 'role' => 'operator'];

[$status, $body] = $call($createRequest, null, 'kiosk');
checkSession($status === 200 && $body['ok'] === true && isset($body['data']['session_token']), 'B003-P01 operator login creates session');
$sessionToken = $body['data']['session_token'] ?? '';

[$status, $body] = $call(['protocol' => 'symbiont.operator-session', 'version' => '1.0.0', 'request_id' => 'b003-php-test', 'operation' => 'session.verify', 'session_token' => $sessionToken], $service);
checkSession($status === 200 && $body['data']['role'] === 'operator', 'B003-P02 service verifies active session');

[$status, $body] = $call(['protocol' => 'symbiont.operator-session', 'version' => '1.0.0', 'request_id' => 'b003-php-test', 'operation' => 'session.end', 'session_token' => $sessionToken], $service);
checkSession($status === 200 && isset($body['data']['ended_at']), 'B003-P03 service ends session');

[$status] = $call(array_replace($createRequest, ['pin_code' => '9999']), null, 'kiosk');
checkSession($status === 401, 'B003-P04 invalid pin rejected');

[$status] = $call(array_replace($createRequest, ['role' => 'king']), null, 'kiosk');
checkSession($status === 400, 'B003-P05 invalid role rejected');

[$status] = $call(['protocol' => 'symbiont.operator-session', 'version' => '1.0.0', 'request_id' => 'b003-php-test', 'operation' => 'session.verify', 'session_token' => bin2hex(random_bytes(32))], $service);
checkSession($status === 401, 'B003-P06 unknown token expired');

$expired = ['enabled' => true, 'serviceToken' => $service, 'hostId' => 'slicehub-test', 'environment' => 'test', 'fixturePath' => $fixture, 'storePath' => $store, 'ttl' => -1];
[$status, $body] = $call($createRequest, null, 'kiosk', $expired);
$expiredToken = $body['data']['session_token'] ?? '';
[$status] = $call(['protocol' => 'symbiont.operator-session', 'version' => '1.0.0', 'request_id' => 'b003-php-test', 'operation' => 'session.verify', 'session_token' => $expiredToken], $service);
checkSession($status === 401, 'B003-P07 expired token rejected');

[$status] = $call(['protocol' => 'symbiont.operator-session', 'version' => '1.0.0', 'request_id' => 'b003-php-test', 'operation' => 'session.end', 'session_token' => bin2hex(random_bytes(32))], $service);
checkSession($status === 401, 'B003-P08 ending unknown token rejected');

[$status] = $call(['protocol' => 'symbiont.operator-session', 'version' => '1.0.0', 'request_id' => 'b003-php-test', 'operation' => 'session.verify', 'session_token' => $sessionToken], 'wrong-token');
checkSession($status === 401, 'B003-P09 wrong service token rejected');

[$status, $body] = $call(array_replace($createRequest, ['version' => '2.0.0']), null, 'kiosk');
checkSession($status === 409, 'B003-P10 exact version enforced');

[$status] = $call($createRequest + ['extra' => 'field'], null, 'kiosk');
checkSession($status === 400, 'B003-P11 extra fields rejected');

[$status] = $call($createRequest, null, 'kiosk', array_replace($base, ['enabled' => false]));
checkSession($status === 503, 'B003-P12 disabled configuration rejected');

[$status, $body] = $call(array_replace($createRequest, ['request_id' => '<script>']), null, 'kiosk');
checkSession($body['request_id'] === null, 'B003-P13 invalid request id not echoed');

[$status] = (new SessionAuthority($base))->handle('POST', 'application/json', 'Bearer ' . $service, str_repeat('x', 4097), 'service', true);
checkSession($status === 400, 'B003-P14 bounded input');

[$status, $body] = $call(array_replace($createRequest, ['tenant_id' => 0]), null, 'kiosk');
checkSession($status === 400 && $body['error']['code'] === 'INVALID_TENANT', 'B003-P15 invalid tenant rejected');

unlink($fixture);
unlink($store);

echo "Tests: $count, failures: $failures" . PHP_EOL;
exit($failures ? 1 : 0);
