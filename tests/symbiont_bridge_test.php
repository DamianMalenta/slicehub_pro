<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/Symbiont/Bridge.php';

use SliceHub\Symbiont\Bridge;

$cfg = ['enabled' => true, 'serviceToken' => str_repeat('s', 40), 'adminToken' => str_repeat('a', 40), 'hostId' => 'slicehub-test', 'environment' => 'test'];
$request = ['protocol' => 'symbiont.bridge', 'version' => '1.0.0', 'request_id' => 'test-1', 'operation' => 'describe'];
$count = 0;
function check(bool $condition, string $name): void {
    global $count;
    if (!$condition) throw new RuntimeException('FAIL: ' . $name);
    $count++;
    echo 'PASS: ' . $name . PHP_EOL;
}
function callBridge(array $config, array $request, string $token, string $audience = 'service'): array {
    return (new Bridge($config))->handle('POST', 'application/json', 'Bearer ' . $token, json_encode($request, JSON_THROW_ON_ERROR), $audience, true);
}

[$status, $body] = callBridge($cfg, $request, $cfg['serviceToken']);
check($status === 200 && $body['ok'] && $body['request_id'] === 'test-1', 'A-T03 describe');
check(!str_contains(json_encode($body), $cfg['serviceToken']), 'A-T10 no token in DTO');
check(callBridge($cfg, $request, 'wrong')[0] === 401, 'A-T02 bad token');
check(callBridge($cfg, $request, $cfg['adminToken'])[0] === 401, 'A-T02 admin cannot use service channel');
check(callBridge($cfg, $request, $cfg['serviceToken'], 'admin')[0] === 401, 'A-T02 service cannot use admin channel');
check(callBridge($cfg, $request, $cfg['adminToken'], 'admin')[0] === 200, 'A-T07 admin diagnostic API');
check(callBridge(array_replace($cfg, ['enabled' => false]), $request, $cfg['serviceToken'])[0] === 503, 'A-T05 disabled');
check(callBridge(array_replace($cfg, ['serviceToken' => '']), $request, '')[0] === 503, 'A-T02 missing configuration');
check(callBridge(array_replace($cfg, ['adminToken' => $cfg['serviceToken']]), $request, $cfg['serviceToken'])[0] === 503, 'A-T02 distinct credentials');
check(callBridge($cfg, array_replace($request, ['version' => '2.0.0']), $cfg['serviceToken'])[0] === 409, 'A-T04 version mismatch');
check(callBridge($cfg, $request + ['shell' => 'not allowed'], $cfg['serviceToken'])[0] === 400, 'A-T04 unknown field');
check(callBridge($cfg, array_replace($request, ['operation' => 'business.write']), $cfg['serviceToken'])[0] === 422, 'A-T04 no business operation');
check(callBridge($cfg, array_replace($request, ['request_id' => '<script>']), $cfg['serviceToken'])[0] === 400, 'A-T04 invalid correlation');
[$status, $body] = callBridge($cfg, array_replace($request, ['operation' => 'diagnose']), $cfg['serviceToken']);
check($status === 200 && $body['data']['checks']['business_access'] === 'not_requested', 'A-T08 no business claim');
check((new Bridge($cfg))->handle('POST', 'application/json', 'Bearer ' . $cfg['serviceToken'], str_repeat('x', 4097), 'service', true)[0] === 413, 'A-T10 bounded input');
check((new Bridge($cfg))->handle('POST', 'application/json', 'Bearer ' . $cfg['serviceToken'], '{}', 'service', false)[0] === 403, 'A-T02 TLS outside loopback');
$lock = json_decode(file_get_contents(__DIR__ . '/../core/Symbiont/contracts/core-baseline-v1.json'), true, 32, JSON_THROW_ON_ERROR);
foreach ($lock['artifacts'] as $artifact) {
    $data = file_get_contents(__DIR__ . '/../' . $artifact['target']);
    check(sha1('blob ' . strlen($data) . "\0" . $data) === $artifact['hash'], 'Pinned artifact: ' . $artifact['target']);
}
echo 'Tests: ' . $count . ', failures: 0' . PHP_EOL;
