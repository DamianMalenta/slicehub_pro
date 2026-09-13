<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/Symbiont/EngineeringRead.php';

use SliceHub\Symbiont\EngineeringRead;

$count = 0;
$failures = 0;
function check(bool $ok, string $label): void
{
    global $count, $failures;
    $count++;
    if (!$ok) $failures++;
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . PHP_EOL;
}
$service = bin2hex(random_bytes(32));
$admin = bin2hex(random_bytes(32));
$bridgeService = bin2hex(random_bytes(32));
$bridgeAdmin = bin2hex(random_bytes(32));
$config = ['enabled' => true, 'serviceToken' => $service, 'adminToken' => $admin, 'bridgeServiceToken' => $bridgeService, 'bridgeAdminToken' => $bridgeAdmin, 'packageFile' => $argv[1] ?? '', 'hostId' => 'slicehub-test', 'environment' => 'test'];
$policy = json_decode(file_get_contents(__DIR__ . '/../core/Symbiont/contracts/b001-package.json'), true, 32, JSON_THROW_ON_ERROR);
$input = ['protocol' => 'symbiont.engineering-read', 'version' => '1.0.0', 'request_id' => 'b001-php-test', 'operation' => 'snapshot.describe', 'params' => ['package_id' => $policy['package_id']]];
$call = static function (array $in, string $token, string $audience = 'service', ?array $cfg = null, bool $secure = true) use ($config): array {
    return (new EngineeringRead($cfg ?? $config))->handle('POST', 'application/json', 'Bearer ' . $token, json_encode($in, JSON_THROW_ON_ERROR), $audience, $secure);
};
check($call($input, $bridgeService)[0] === 401, 'B001-P01 diagnostic service cannot describe sources');
check($call($input, $bridgeAdmin)[0] === 401, 'B001-P02 diagnostic admin cannot describe sources');
check($call($input, $service, 'admin')[0] === 401, 'B001-P03 service cannot use metadata admin');
check($call($input, $admin)[0] === 401, 'B001-P04 metadata admin cannot use service');
check($call($input, $service, 'service', array_replace($config, ['enabled' => false]))[0] === 503, 'B001-P05 disabled');
check($call($input, $service, 'service', array_replace($config, ['environment' => 'production']))[0] === 503, 'B001-P06 production denied');
check($call($input, $service, 'service', array_replace($config, ['adminToken' => $service]))[0] === 503, 'B001-P07 equal credentials denied');
check($call($input, $service, 'service', $config, false)[0] === 403, 'B001-P08 insecure transport denied');
check($call(array_replace($input, ['version' => '2.0.0']), $service)[0] === 409, 'B001-P09 exact version');
check($call(array_replace($input, ['operation' => 'execute']), $service)[0] === 400, 'B001-P10 no execution operation');
check($call(array_replace($input, ['path' => '../outside']), $service)[0] === 400, 'B001-P11 unknown fields');
check($call(array_replace($input, ['params' => ['package_id' => $policy['package_id'], 'path' => 'C:/blocked']]), $service)[0] === 400, 'B001-P12 no path parameter');
check($call(array_replace($input, ['params' => ['package_id' => 'other-package']]), $service)[0] === 404, 'B001-P13 package scope');
check($call(array_replace($input, ['request_id' => "\n"]), $service)[1]['request_id'] === null, 'B001-P14 invalid correlation not echoed');
$read = array_replace($input, ['operation' => 'snapshot.read', 'params' => ['package_id' => $policy['package_id'], 'snapshot_id' => 'bad', 'manifest_sha256' => 'bad']]);
check($call($read, $admin, 'admin')[0] === 403, 'B001-P15 admin metadata cannot read content');
check($call($input, $service, 'service', array_replace($config, ['packageFile' => '']))[0] === 503, 'B001-P16 missing package');
if (!isset($argv[1])) {
    echo 'Source package checks NOT_RUN: pass an explicitly prepared isolated snapshot file.' . PHP_EOL;
} else {
    [$status, $described] = $call($input, $service);
    check($status === 200 && !isset($described['data']['files']), 'B001-P17 describe metadata only');
    check($call($input, $admin, 'admin')[0] === 200, 'B001-P18 metadata administrator');
    check($call($read, $service)[0] === 409, 'B001-P19 stale snapshot rejected');
    $manifest = $described['data']['manifest'];
    $read['params']['snapshot_id'] = $manifest['snapshot_id'];
    $read['params']['manifest_sha256'] = $manifest['manifest_sha256'];
    [$status, $result] = $call($read, $service);
    check($status === 200 && count($result['data']['files']) === 3, 'B001-P20 approved original source package');
    check(!str_contains(json_encode($result), $service) && !str_contains(json_encode($result), $admin), 'B001-P21 no credential in DTO');
    $bundle = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
    $bundle['files'][0]['content_base64'] = base64_encode('tampered');
    $temporary = tempnam(sys_get_temp_dir(), 'b001-negative-');
    file_put_contents($temporary, json_encode($bundle, JSON_THROW_ON_ERROR));
    check($call($read, $service, 'service', array_replace($config, ['packageFile' => $temporary]))[1]['error']['code'] === 'INTEGRITY_ERROR', 'B001-P22 tampered content rejected');
    unlink($temporary);
}
echo "Tests: $count, failures: $failures" . PHP_EOL;
exit($failures ? 1 : 0);
