<?php
declare(strict_types=1);
require_once __DIR__ . '/Bridge.php';

function symbiontHttp(string $audience): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'');
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    $secure = $local || ($_SERVER['HTTPS'] ?? '') === 'on';
    try {
        $bridge = new \SliceHub\Symbiont\Bridge(\SliceHub\Symbiont\Bridge::environmentConfig());
        $raw = file_get_contents('php://input', false, null, 0, 4097);
        [$status, $body] = $bridge->handle($_SERVER['REQUEST_METHOD'] ?? '', $_SERVER['CONTENT_TYPE'] ?? '', $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '', $raw === false ? '' : $raw, $audience, $secure);
    } catch (\Throwable) {
        $status = 500;
        $body = ['protocol' => 'symbiont.bridge', 'version' => '1.0.0', 'request_id' => null, 'ok' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Bridge unavailable.']];
    }
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
