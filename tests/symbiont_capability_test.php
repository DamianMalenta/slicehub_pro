<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/Symbiont/CapabilityRegistry.php';

use SliceHub\Symbiont\CapabilityRegistry;

$count = 0;
$failures = 0;
function checkCapability(bool $ok, string $label): void
{
    global $count, $failures;
    $count++;
    if (!$ok) $failures++;
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . PHP_EOL;
}
$service = bin2hex(random_bytes(32));
$admin = bin2hex(random_bytes(32));
$config = ['enabled' => true, 'serviceToken' => $service, 'adminToken' => $admin, 'hostId' => 'slicehub-test', 'environment' => 'test'];
$input = ['protocol' => 'symbiont.capability-catalog', 'version' => '1.0.0', 'request_id' => 'b002-php-test', 'operation' => 'catalog.list'];
$call = static function (array $request, string $token, string $audience = 'service', ?array $cfg = null, bool $secure = true) use ($config): array {
    return (new CapabilityRegistry($cfg ?? $config))->handle('POST', 'application/json', 'Bearer ' . $token, json_encode($request, JSON_THROW_ON_ERROR), $audience, $secure);
};
[$status, $body] = $call($input, $service);
checkCapability($status === 200 && $body['ok'] === true, 'B002-P01 service lists catalog');
checkCapability($body['data']['execution'] === 'not_permitted', 'B002-P02 execution explicitly denied');
checkCapability(count($body['data']['capabilities']) === 4, 'B002-P03 approved entries only');
checkCapability(!str_contains(json_encode($body), $service) && !str_contains(json_encode($body), $admin), 'B002-P04 credentials absent from DTO');
checkCapability($call($input, $admin, 'admin')[0] === 200, 'B002-P05 administrator lists catalog');
checkCapability($call($input, $admin)[0] === 401, 'B002-P06 admin cannot use service channel');
checkCapability($call($input, $service, 'admin')[0] === 401, 'B002-P07 service cannot use admin channel');
checkCapability($call($input, 'wrong')[0] === 401, 'B002-P08 invalid credential denied');
checkCapability($call($input, $service, 'service', array_replace($config, ['enabled' => false]))[0] === 503, 'B002-P09 disabled');
checkCapability($call($input, $service, 'service', array_replace($config, ['adminToken' => $service]))[0] === 503, 'B002-P10 distinct credentials');
checkCapability($call($input, $service, 'service', $config, false)[0] === 403, 'B002-P11 insecure transport denied');
checkCapability($call(array_replace($input, ['version' => '2.0.0']), $service)[0] === 409, 'B002-P12 exact version');
checkCapability($call(array_replace($input, ['operation' => 'capability.execute']), $service)[0] === 422, 'B002-P13 no execution operation');
checkCapability($call($input + ['params' => ['tenant_id' => 1]], $service)[0] === 400, 'B002-P14 no tenant or business parameters');
checkCapability($call(array_replace($input, ['request_id' => '<script>']), $service)[1]['request_id'] === null, 'B002-P15 invalid request id not echoed');
checkCapability((new CapabilityRegistry($config))->handle('POST', 'application/json', 'Bearer ' . $service, str_repeat('x', 4097), 'service', true)[0] === 400, 'B002-P16 bounded input');
foreach ($body['data']['capabilities'] as $capability) checkCapability($capability['business_effects'] === 'none', 'B002-P17 no business effect: ' . $capability['id']);
echo "Tests: $count, failures: $failures" . PHP_EOL;
exit($failures ? 1 : 0);
