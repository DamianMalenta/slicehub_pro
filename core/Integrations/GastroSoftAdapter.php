<?php

declare(strict_types=1);

namespace SliceHub\Integrations;

/**
 * GastroSoftAdapter — async push dla polskiego POS-a GastroSoft.
 *
 * GastroSoft (gastrosoft.pl) używa prostego API-key auth i przyjmuje zamówienia
 * w formacie XML albo JSON (my wybieramy JSON). Endpoint jest per-lokal
 * (restaurant_code), nie per-tenant globalny.
 *
 * Credentials (sh_tenant_integrations.credentials JSON):
 *   {
 *     "api_key":          "gs_live_xxx",         // wymagane
 *     "restaurant_code":  "ABC123",              // wymagane (kod lokalu)
 *     "terminal_id":      "POS-01"               // opcjonalne (mapowanie na fizyczny POS)
 *   }
 *
 * api_base_url: https://api.gastrosoft.pl/v1 (przykład — podmień na realny gdy dokumentacja)
 *
 * Events bridged: typowo ['order.created', 'order.cancelled'] — GastroSoft
 * nie updatuje statusów zewnętrznie (lokal sam zarządza cyklem życia w POSie).
 */
final class GastroSoftAdapter extends BaseAdapter
{
    public static function providerKey(): string { return 'gastrosoft'; }
    public static function displayName(): string { return 'GastroSoft POS'; }

    public function buildRequest(array $envelope): array
    {
        $eventType       = (string)($envelope['event_type'] ?? '');
        $order           = $this->extractOrderSnapshot($envelope);
        $lines           = $this->extractOrderLines($envelope);
        $apiKey          = $this->requireCredential('api_key');
        $restaurantCode  = $this->requireCredential('restaurant_code');

        [$path, $method, $payload] = match ($eventType) {
            'order.created' => [
                "/restaurants/{$restaurantCode}/orders",
                'POST',
                $this->buildOrderPayload($envelope, $order, $lines),
            ],
            'order.cancelled' => [
                "/restaurants/{$restaurantCode}/orders/" . ($order['order_number'] ?? ''),
                'DELETE',
                [],
            ],
            default => throw new AdapterException("GastroSoft does not handle event '{$eventType}'"),
        };

        $url = $this->apiBaseUrl() . $path;

        $body = '';
        if ($method !== 'DELETE' && !empty($payload)) {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new AdapterException('GastroSoft payload json_encode failed');
            }
            $body = $encoded;
        }

        $headers = [
            'Accept: application/json',
            'X-Api-Key: ' . $apiKey,
            'User-Agent: SliceHub-GastroSoftAdapter/1.0',
            'X-Slicehub-Event: ' . $eventType,
        ];
        if ($body !== '') {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }

        $terminalId = $this->credentials()['terminal_id'] ?? null;
        if ($terminalId) {
            $headers[] = 'X-Terminal-Id: ' . $terminalId;
        }

        return [
            'method'  => $method,
            'url'     => $url,
            'headers' => $headers,
            'body'    => $body,
        ];
    }

    /**
     * GastroSoft parseResponse — standardowe, 409 (Conflict = duplikat) to
     * idempotency success (order już był dodany w poprzedniej próbie).
     */
    public function parseResponse(int $httpCode, string $responseBody, ?string $transportError = null): array
    {
        if ($transportError !== null) {
            return ['ok' => false, 'transient' => true, 'error' => 'transport: ' . $transportError];
        }

        $decoded = json_decode($responseBody, true);

        // 2xx → sukces
        if ($httpCode >= 200 && $httpCode < 300) {
            $extRef = null;
            if (is_array($decoded)) {
                $extRef = $decoded['order_id'] ?? $decoded['gs_order_id'] ?? $decoded['id'] ?? null;
            }
            return ['ok' => true, 'externalRef' => $extRef !== null ? (string)$extRef : null];
        }

        // 409 Conflict — duplikat (retry po restarcie = idempotency success)
        if ($httpCode === 409) {
            $extRef = null;
            if (is_array($decoded) && isset($decoded['existing_order_id'])) {
                $extRef = (string)$decoded['existing_order_id'];
            }
            return [
                'ok'          => true,
                'externalRef' => $extRef,
                'error'       => 'duplicate (409 conflict, treating as idempotent success)',
            ];
        }

        $isTransient = ($httpCode >= 500) || $httpCode === 408 || $httpCode === 429;
        $errorMsg = sprintf('HTTP %d', $httpCode);
        if (is_array($decoded) && isset($decoded['error_message'])) {
            $errorMsg .= ': ' . $decoded['error_message'];
        } elseif (is_array($decoded) && isset($decoded['error'])) {
            $errorMsg .= ': ' . $decoded['error'];
        } elseif ($responseBody !== '') {
            $errorMsg .= ': ' . substr($responseBody, 0, 200);
        }

        return ['ok' => false, 'transient' => $isTransient, 'error' => $errorMsg];
    }

    // ──────────────────────────────────────────────────────────────────

    private function buildOrderPayload(array $envelope, array $order, array $lines): array
    {
        $terminalId = $this->credentials()['terminal_id'] ?? null;

        $totalGr    = (int)($order['grand_total_grosze'] ?? $order['grand_total'] ?? 0);

        return [
            'external_id'       => (string)($order['id'] ?? ''),
            'order_number'      => (string)($order['order_number'] ?? ''),
            'source'            => 'slicehub',
            'terminal_id'       => $terminalId,
            'channel'           => $this->mapChannel((string)($order['channel'] ?? '')),
            'order_type'        => $this->mapOrderType((string)($order['order_type'] ?? '')),
            'created_at'        => $envelope['occurred_at'] ?? date('c'),
            'promised_time'     => $order['promised_time']  ?? null,
            'customer' => [
                'name'          => $order['customer_name']    ?? null,
                'phone'         => $order['customer_phone']   ?? null,
                'address'       => $order['delivery_address'] ?? null,
            ],
            'payment' => [
                'method'        => $this->mapPaymentMethod($order['payment_method'] ?? null),
                'status'        => (string)($order['payment_status'] ?? 'unpaid'),
                'amount_pln'    => (float)self::grToPln($totalGr),
            ],
            'items' => array_map(function (array $line): array {
                $unitGr = (int)($line['unit_price_grosze'] ?? $line['unit_price'] ?? 0);
                $lineGr = (int)($line['line_total_grosze'] ?? $line['line_total'] ?? 0);

                return [
                    'code'        => (string)($line['item_sku']      ?? ''),
                    'name'        => (string)($line['snapshot_name'] ?? ''),
                    'quantity'    => (int)($line['quantity']          ?? 1),
                    'unit_price'  => (float)self::grToPln($unitGr),
                    'total_price' => (float)self::grToPln($lineGr),
                    'vat'         => (float)($line['vat_rate']        ?? 23.0),
                    'modifiers'   => $this->flattenModifiers($line),
                    'note'        => $line['comment'] ?? null,
                ];
            }, array_values($lines)),
        ];
    }

    private function flattenModifiers(array $line): string
    {
        $added = json_decode((string)($line['modifiers_json'] ?? '[]'), true) ?: [];
        $removed = json_decode((string)($line['removed_ingredients_json'] ?? '[]'), true) ?: [];

        $parts = [];
        foreach ($added as $mod) {
            $name = is_array($mod) ? ($mod['name'] ?? $mod['sku'] ?? '') : (string)$mod;
            if ($name !== '') $parts[] = '+ ' . $name;
        }
        foreach ($removed as $ing) {
            $name = is_array($ing) ? ($ing['name'] ?? '') : (string)$ing;
            if ($name !== '') $parts[] = '- ' . $name;
        }

        return implode(', ', $parts);
    }

    private function mapChannel(string $ours): string
    {
        return match ($ours) {
            'POS'      => 'ONSITE',
            'Takeaway' => 'TAKEAWAY',
            'Delivery' => 'DELIVERY',
            default    => strtoupper($ours) ?: 'UNKNOWN',
        };
    }

    private function mapOrderType(string $ours): string
    {
        return match ($ours) {
            'dine_in'  => 'DINE_IN',
            'takeaway' => 'TAKEAWAY',
            'delivery' => 'DELIVERY',
            default    => 'OTHER',
        };
    }

    private function mapPaymentMethod(?string $ours): string
    {
        return match ($ours) {
            'cash'     => 'GOTOWKA',
            'card'     => 'KARTA',
            'online'   => 'ONLINE',
            'terminal' => 'KARTA',
            default    => 'INNA',
        };
    }
}
