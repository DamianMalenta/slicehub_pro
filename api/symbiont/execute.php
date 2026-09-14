<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/Symbiont/ExecutionGateway.php';

$secure = ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
    || ($_SERVER['REMOTE_ADDR'] ?? '') === '::1'
    || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

$gateway = new \SliceHub\Symbiont\ExecutionGateway(\SliceHub\Symbiont\ExecutionGateway::environmentConfig());
[$code, $response] = $gateway->handle(
    $_SERVER['REQUEST_METHOD'] ?? '',
    $_SERVER['CONTENT_TYPE'] ?? '',
    $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    file_get_contents('php://input'),
    $secure
);

http_response_code($code);
header('Content-Type: application/json');
echo json_encode($response, JSON_THROW_ON_ERROR);
