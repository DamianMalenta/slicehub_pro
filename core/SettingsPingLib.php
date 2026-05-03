<?php

declare(strict_types=1);

/**
 * Shared synthetic ping transport for Settings panel + CLI health workers.
 * Używane przez api/settings/engine.php oraz scripts/worker_integration_health_ping.php.
 */
final class SettingsPingLib
{
    /**
     * Test ping dla integration adaptera — syntetyczny order.created → adapter → HTTP.
     */
    public static function integrationPing(array $integrationRow): array
    {
        $t0 = microtime(true);

        $provider = (string)($integrationRow['provider'] ?? '');
        $known = \SliceHub\Integrations\AdapterRegistry::availableProviders();
        if (!isset($known[$provider])) {
            return [
                'ok'       => false,
                'stage'    => 'resolve',
                'message'  => "No adapter class for provider '{$provider}'",
                'duration_ms' => (int)((microtime(true) - $t0) * 1000),
            ];
        }

        $row = $integrationRow + [
            'timeout_seconds' => 8,
            'max_retries' => 6,
            'consecutive_failures' => 0,
        ];

        $class = null;
        foreach ([
            'SliceHub\\Integrations\\PapuAdapter',
            'SliceHub\\Integrations\\DotykackaAdapter',
            'SliceHub\\Integrations\\GastroSoftAdapter',
        ] as $candidate) {
            if (class_exists($candidate) && $candidate::providerKey() === $provider) {
                $class = $candidate;
                break;
            }
        }
        if ($class === null) {
            return ['ok' => false, 'stage' => 'resolve', 'message' => "No adapter class matched '{$provider}'",
                    'duration_ms' => (int)((microtime(true) - $t0) * 1000)];
        }

        $adapter = new $class($row);

        $envelope = self::buildSyntheticEnvelope((int)$row['tenant_id']);

        try {
            $req = $adapter->buildRequest($envelope);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'stage' => 'buildRequest',
                'message' => $e->getMessage(),
                'duration_ms' => (int)((microtime(true) - $t0) * 1000),
            ];
        }

        $transportT0 = microtime(true);
        $http = self::curlRequest(
            (string)$req['method'], (string)$req['url'], (array)$req['headers'], (string)$req['body'],
            max(1, (int)($row['timeout_seconds'] ?? 8))
        );
        $transportMs = (int)((microtime(true) - $transportT0) * 1000);

        $verdict = $adapter->parseResponse((int)$http['code'], (string)$http['body'], $http['error']);

        return [
            'ok'          => (bool)($verdict['ok'] ?? false),
            'stage'       => ($verdict['ok'] ?? false) ? 'delivered' : 'rejected',
            'http_code'   => (int)$http['code'],
            'transport_error' => $http['error'],
            'transient'   => (bool)($verdict['transient'] ?? false),
            'external_ref' => $verdict['externalRef'] ?? null,
            'error'       => $verdict['error'] ?? null,
            'request_preview' => [
                'method'  => $req['method'],
                'url'     => $req['url'],
                'headers_count' => count($req['headers']),
                'body_bytes' => strlen($req['body']),
                'body_preview' => substr($req['body'], 0, 300),
            ],
            'response_preview' => substr((string)$http['body'], 0, 500),
            'duration_ms' => (int)((microtime(true) - $t0) * 1000),
            'transport_ms' => $transportMs,
        ];
    }

    /**
     * Test ping dla webhook endpointu — signed HMAC POST z synthetic envelope.
     */
    public static function webhookPing(array $endpointRow, int $tenantId): array
    {
        $t0 = microtime(true);

        $secret = (string)$endpointRow['secret'];
        if (class_exists('CredentialVault') && CredentialVault::isEncrypted($secret)) {
            $decrypted = CredentialVault::decrypt($secret);
            if ($decrypted === null) {
                return [
                    'ok'      => false,
                    'stage'   => 'decrypt',
                    'message' => 'secret decrypt failed — check SLICEHUB_VAULT_KEY',
                    'duration_ms' => (int)((microtime(true) - $t0) * 1000),
                ];
            }
            $secret = $decrypted;
        }

        $envelope = self::buildSyntheticEnvelope($tenantId, true);
        $body = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return ['ok' => false, 'stage' => 'encode', 'message' => 'json_encode failed',
                    'duration_ms' => (int)((microtime(true) - $t0) * 1000)];
        }

        $ts = time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'User-Agent: SliceHub-Webhooks/1.0 (test-ping)',
            "X-Slicehub-Event: {$envelope['event_type']}",
            "X-Slicehub-Test: 1",
            "X-Slicehub-Timestamp: {$ts}",
            "X-Slicehub-Signature: t={$ts},v1={$sig}",
        ];

        $transportT0 = microtime(true);
        $http = self::curlRequest('POST', (string)$endpointRow['url'], $headers, $body,
            max(1, (int)$endpointRow['timeout_seconds']));
        $transportMs = (int)((microtime(true) - $transportT0) * 1000);

        $isOk = ($http['code'] >= 200 && $http['code'] < 300);

        return [
            'ok'          => $isOk,
            'stage'       => $isOk ? 'delivered' : ($http['error'] ? 'transport' : 'http_error'),
            'http_code'   => (int)$http['code'],
            'transport_error' => $http['error'],
            'response_preview' => substr((string)$http['body'], 0, 500),
            'signature'   => "t={$ts},v1={$sig}",
            'duration_ms' => (int)((microtime(true) - $t0) * 1000),
            'transport_ms' => $transportMs,
        ];
    }

    public static function buildSyntheticEnvelope(int $tenantId, bool $forWebhook = false): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $fakeOrderId = 'test-' . bin2hex(random_bytes(6));

        $envelope = [
            'event_id'       => 'test-' . bin2hex(random_bytes(8)),
            'event_type'     => 'order.created',
            'aggregate_id'   => $fakeOrderId,
            'aggregate_type' => 'order',
            'tenant_id'      => $tenantId,
            'source'         => 'test_ping',
            'actor_type'     => 'system',
            'actor_id'       => null,
            'occurred_at'    => $now,
            'attempt'        => 1,
            'payload' => [
                '_test_ping' => true,
                'order' => [
                    'id'              => $fakeOrderId,
                    'order_number'    => 'TST/' . date('Ymd') . '/0001',
                    'tenant_id'       => $tenantId,
                    'order_type'      => 'takeaway',
                    'channel'         => 'Takeaway',
                    'payment_method'  => 'cash',
                    'payment_status'  => 'unpaid',
                    'customer_name'   => 'Test Ping',
                    'customer_phone'  => '+48 600 000 000',
                    'delivery_address' => null,
                    'subtotal_grosze'     => 3000,
                    'grand_total_grosze'  => 3000,
                    'discount_grosze'     => 0,
                    'delivery_fee_grosze' => 0,
                    'promised_time'   => null,
                    'gateway_source'  => 'test_ping',
                    'gateway_external_id' => null,
                    'lines' => [
                        [
                            'item_sku'              => 'TEST_ITEM',
                            'snapshot_name'         => 'Test Pizza Margherita',
                            'quantity'              => 1,
                            'unit_price_grosze'     => 3000,
                            'line_total_grosze'     => 3000,
                            'vat_rate'              => 23.0,
                            'modifiers_json'        => '[]',
                            'removed_ingredients_json' => '[]',
                            'comment'               => null,
                        ],
                    ],
                ],
                '_context' => [
                    'test_ping' => true,
                    'gateway_source' => 'test_ping',
                ],
            ],
        ];

        if ($forWebhook) {
            $envelope['delivery_id'] = 0;
        }
        return $envelope;
    }

    /**
     * @return array{code:int, body:string, error:?string}
     */
    public static function curlRequest(string $method, string $url, array $headers, string $body, int $timeout): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 3),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== '' && $method !== 'GET' && $method !== 'DELETE') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $respBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'code'  => $httpCode,
            'body'  => is_string($respBody) ? $respBody : '',
            'error' => $err,
        ];
    }
}
