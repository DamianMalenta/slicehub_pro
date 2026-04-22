<?php

declare(strict_types=1);

namespace SliceHub\Integrations;

/**
 * PapuAdapter — async push dla Papu.io POS (rozszerzenie legacy PapuClient).
 *
 * Różnice vs legacy PapuClient.php:
 *   • PapuClient jest fire-and-forget z auto-creating tabelą — nadal w POS
 *     finalize jako synchroniczny push (back-compat).
 *   • PapuAdapter jest async — konsumuje event z outboxa, loguje do
 *     sh_integration_deliveries z retrami + DLQ.
 *
 * Credentials (sh_tenant_integrations.credentials JSON):
 *   {
 *     "api_key":     "pk_live_xxx",          // wymagane
 *     "api_secret":  "...",                   // opcjonalne (signed requests)
 *     "tenant_ext":  "restaurant_42"          // opcjonalne (multi-tenant na Papu)
 *   }
 *
 * api_base_url: https://api.papu.io/v1 (lub sandbox)
 *
 * Events bridged: typowo ['order.created', 'order.edited', 'order.cancelled'].
 */
final class PapuAdapter extends BaseAdapter
{
    public static function providerKey(): string { return 'papu'; }
    public static function displayName(): string { return 'Papu.io POS'; }

    public function buildRequest(array $envelope): array
    {
        $eventType = (string)($envelope['event_type'] ?? '');
        $order     = $this->extractOrderSnapshot($envelope);
        $lines     = $this->extractOrderLines($envelope);
        $apiKey    = $this->requireCredential('api_key');
        $tenantExt = $this->credentials()['tenant_ext'] ?? null;

        // Route per event type — Papu ma różne endpointy.
        [$path, $method, $payload] = match ($eventType) {
            'order.created' => [
                '/orders',
                'POST',
                $this->buildOrderPayload($envelope, $order, $lines),
            ],
            'order.edited' => [
                '/orders/' . $order['id'],
                'PATCH',
                $this->buildOrderPayload($envelope, $order, $lines, isEdit: true),
            ],
            'order.cancelled' => [
                '/orders/' . $order['id'] . '/cancel',
                'POST',
                ['reason' => $envelope['payload']['_context']['cancellation_reason'] ?? 'customer_requested'],
            ],
            'order.ready', 'order.delivered', 'order.completed' => [
                '/orders/' . $order['id'] . '/status',
                'PATCH',
                ['status' => $this->mapStatusToPapu($eventType)],
            ],
            default => throw new AdapterException("Papu does not handle event '{$eventType}'"),
        };

        $url = $this->apiBaseUrl() . $path;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new AdapterException('Papu payload json_encode failed');
        }

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey,
            'User-Agent: SliceHub-PapuAdapter/1.0',
            'X-Slicehub-Event: ' . $eventType,
            'X-Slicehub-Order-Id: ' . ($order['id'] ?? ''),
        ];

        if ($tenantExt) {
            $headers[] = 'X-Papu-Tenant: ' . $tenantExt;
        }

        // Optional HMAC signing (jeśli secret skonfigurowany)
        $apiSecret = $this->credentials()['api_secret'] ?? null;
        if ($apiSecret !== null && $apiSecret !== '') {
            $ts = time();
            $sig = hash_hmac('sha256', $ts . '.' . $body, (string)$apiSecret);
            $headers[] = "X-Papu-Signature: t={$ts},v1={$sig}";
        }

        return [
            'method'  => $method,
            'url'     => $url,
            'headers' => $headers,
            'body'    => $body,
        ];
    }

    /**
     * Papu response parse — 200 z `{ok: false}` traktuj jako permanent error.
     */
    public function parseResponse(int $httpCode, string $responseBody, ?string $transportError = null): array
    {
        if ($transportError !== null) {
            return ['ok' => false, 'transient' => true, 'error' => 'transport: ' . $transportError];
        }

        $decoded = json_decode($responseBody, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            // Papu semantyka: HTTP 200 + ok:false = validation fail (permanent)
            if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false) {
                return [
                    'ok'        => false,
                    'transient' => false,
                    'error'     => 'Papu rejected: ' . ($decoded['error'] ?? 'unknown'),
                ];
            }

            $extRef = null;
            if (is_array($decoded)) {
                $extRef = $decoded['order_id']
                       ?? $decoded['id']
                       ?? ($decoded['data']['id'] ?? null);
            }

            return [
                'ok' => true,
                'externalRef' => $extRef !== null ? (string)$extRef : null,
            ];
        }

        $isTransient = ($httpCode >= 500) || $httpCode === 408 || $httpCode === 429;
        $errorMsg = sprintf('HTTP %d', $httpCode);
        if (is_array($decoded) && isset($decoded['error'])) {
            $errorMsg .= ': ' . $decoded['error'];
        } elseif ($responseBody !== '') {
            $errorMsg .= ': ' . substr($responseBody, 0, 200);
        }

        return ['ok' => false, 'transient' => $isTransient, 'error' => $errorMsg];
    }

    // ─────────────────────────────────────────────────────────────────

    private function buildOrderPayload(array $envelope, array $order, array $lines, bool $isEdit = false): array
    {
        $context = $envelope['payload']['_context'] ?? [];

        return [
            'external_order_id' => (string)($order['id']           ?? ''),
            'order_number'      => (string)($order['order_number'] ?? ''),
            'source'            => 'slicehub',
            'source_origin'     => (string)($envelope['source']    ?? 'internal'),

            'customer' => [
                'name'    => $order['customer_name']    ?? null,
                'phone'   => $order['customer_phone']   ?? null,
                'address' => $order['delivery_address'] ?? null,
            ],

            'order_type'     => (string)($order['order_type']     ?? ''),
            'channel'        => (string)($order['channel']        ?? ''),
            'payment_method' => $order['payment_method'] ?? null,
            'payment_status' => (string)($order['payment_status'] ?? ''),
            'currency'       => 'PLN',
            'total_amount'   => self::grToPln((int)($order['grand_total_grosze'] ?? $order['grand_total'] ?? 0)),
            'subtotal'       => self::grToPln((int)($order['subtotal_grosze']    ?? $order['subtotal']    ?? 0)),
            'discount'       => self::grToPln((int)($order['discount_grosze']    ?? $order['discount_amount'] ?? 0)),
            'delivery_fee'   => self::grToPln((int)($order['delivery_fee_grosze'] ?? $order['delivery_fee'] ?? 0)),
            'promised_time'  => $order['promised_time']  ?? null,
            'occurred_at'    => $envelope['occurred_at'] ?? null,

            'items' => array_map(function (array $line): array {
                $unitGr = (int)($line['unit_price_grosze'] ?? $line['unit_price'] ?? 0);
                $lineGr = (int)($line['line_total_grosze'] ?? $line['line_total'] ?? 0);

                return [
                    'sku'        => (string)($line['item_sku']      ?? ''),
                    'name'       => (string)($line['snapshot_name'] ?? ''),
                    'quantity'   => (int)($line['quantity']         ?? 1),
                    'unit_price' => self::grToPln($unitGr),
                    'line_total' => self::grToPln($lineGr),
                    'vat_rate'   => (float)($line['vat_rate']       ?? 0),
                    'modifiers'  => json_decode((string)($line['modifiers_json'] ?? '[]'), true) ?: [],
                    'removed'    => json_decode((string)($line['removed_ingredients_json'] ?? '[]'), true) ?: [],
                    'comment'    => $line['comment'] ?? null,
                ];
            }, array_values($lines)),

            'context' => [
                'event_type'     => $envelope['event_type'] ?? null,
                'attempt'        => $envelope['attempt']    ?? 1,
                'gateway_source' => $context['gateway_source'] ?? null,
                'is_edit'        => $isEdit,
            ],
        ];
    }

    private function mapStatusToPapu(string $eventType): string
    {
        return match ($eventType) {
            'order.ready'     => 'ready_for_pickup',
            'order.delivered' => 'delivered',
            'order.completed' => 'completed',
            default           => 'unknown',
        };
    }

    public static function supportsInbound(): bool { return true; }

    /**
     * Obsługa INBOUND callbacków od Papu — typowo status updates (kurier odebrał, dostarczone).
     *
     * Format payloadu Papu (według dokumentacji Papu Webhooks API):
     *   {
     *     "event_id":   "evt_12345",
     *     "event_type": "order.status_changed",
     *     "order_id":   "papu_order_42",
     *     "status":     "ready_for_pickup" | "picked_up" | "delivered" | "cancelled",
     *     "occurred_at": "2026-04-18T10:30:00Z",
     *     "metadata": { "driver_name": "...", "eta_minutes": 15 }
     *   }
     *
     * Sygnatura: `X-Papu-Signature: t={ts},v1={hmac_sha256(api_secret, "{ts}.{body}")}`.
     * Replay-window: 5 minut.
     */
    public function parseInboundCallback(string $rawBody, array $headers, array $credentials): array
    {
        $secret = (string)($credentials['api_secret'] ?? '');
        if ($secret === '') {
            return ['ok' => false, 'signature_verified' => false, 'error' => 'api_secret missing — signature verification impossible'];
        }

        $sigHeader = $headers['X-Papu-Signature'] ?? $headers['x-papu-signature'] ?? '';
        if ($sigHeader === '') {
            return ['ok' => false, 'signature_verified' => false, 'error' => 'X-Papu-Signature header missing'];
        }

        if (!preg_match('/^t=(\d+),v1=([a-f0-9]+)$/i', $sigHeader, $m)) {
            return ['ok' => false, 'signature_verified' => false, 'error' => 'malformed X-Papu-Signature'];
        }

        $ts = (int)$m[1];
        $providedHmac = $m[2];

        if (abs(time() - $ts) > 300) {
            return ['ok' => false, 'signature_verified' => false, 'error' => 'timestamp outside 5-minute replay window'];
        }

        $expectedHmac = hash_hmac('sha256', $ts . '.' . $rawBody, $secret);
        if (!hash_equals($expectedHmac, $providedHmac)) {
            return ['ok' => false, 'signature_verified' => false, 'error' => 'invalid HMAC signature'];
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return ['ok' => false, 'signature_verified' => true, 'error' => 'body is not valid JSON'];
        }

        $externalEventId = (string)($payload['event_id']   ?? '');
        $externalRef     = (string)($payload['order_id']   ?? '');
        $papuStatus      = (string)($payload['status']     ?? '');

        $mapping = [
            'accepted'          => ['event_type' => 'order.accepted',   'new_status' => 'accepted'],
            'preparing'         => ['event_type' => 'order.preparing',  'new_status' => 'preparing'],
            'ready_for_pickup'  => ['event_type' => 'order.ready',      'new_status' => 'ready'],
            'picked_up'         => ['event_type' => 'order.dispatched', 'new_status' => 'dispatched'],
            'in_delivery'       => ['event_type' => 'order.in_delivery','new_status' => 'in_delivery'],
            'delivered'         => ['event_type' => 'order.delivered',  'new_status' => 'delivered'],
            'completed'         => ['event_type' => 'order.completed',  'new_status' => 'completed'],
            'cancelled'         => ['event_type' => 'order.cancelled',  'new_status' => 'cancelled'],
        ];

        if (!isset($mapping[$papuStatus])) {
            return [
                'ok' => false, 'signature_verified' => true,
                'external_event_id' => $externalEventId,
                'external_ref'      => $externalRef,
                'error' => "unknown Papu status: {$papuStatus}",
            ];
        }

        return [
            'ok' => true,
            'signature_verified' => true,
            'external_event_id' => $externalEventId,
            'external_ref'      => $externalRef,
            'event_type'        => $mapping[$papuStatus]['event_type'],
            'new_status'        => $mapping[$papuStatus]['new_status'],
            'payload' => [
                'provider_status' => $papuStatus,
                'occurred_at'     => $payload['occurred_at'] ?? null,
                'metadata'        => $payload['metadata']    ?? [],
            ],
        ];
    }
}
