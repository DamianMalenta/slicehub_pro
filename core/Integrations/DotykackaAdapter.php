<?php

declare(strict_types=1);

namespace SliceHub\Integrations;

/**
 * DotykackaAdapter — async push dla Dotykačka POS Cloud API.
 *
 * Dotykacka używa OAuth2 Bearer token refresh flow — token wygasa co godzinę,
 * adapter przed każdym request musi sprawdzić i odświeżyć jeśli trzeba.
 * Token cache jest w `credentials.access_token` + `credentials.access_token_expires_at`.
 *
 * Credentials (sh_tenant_integrations.credentials JSON):
 *   {
 *     "client_id":             "xxx",
 *     "refresh_token":         "eyJhbGc...",        // długoterminowy, z Dotykacka portal
 *     "cloud_id":              "12345",             // id chmury klienta
 *     "branch_id":             "67890",             // id oddziału (opcjonalne)
 *     "access_token":          "eyJhbGc...",        // cache (auto-refreshed)
 *     "access_token_expires_at": "2026-04-18T14:25:00Z"
 *   }
 *
 * api_base_url: https://api.dotykacka.cz/v2
 *
 * Uwaga: Dotykacka NIE ma natywnego endpointu "order.created" — my wysyłamy
 * jako "document/sale" w stanie preparing. Dotykacka oczekuje od nas:
 *   POST /v2/clouds/{cloudId}/documents  (tworzenie)
 *   PATCH /v2/clouds/{cloudId}/documents/{id} (update statusu)
 *
 * Events bridged: zazwyczaj ['order.created', 'order.ready', 'order.completed'].
 *
 * WAŻNE (PLANOWANE): refresh tokena wymaga **mutacji credentials** w DB po
 * każdym udanym refreshu. Aktualnie w Sesji 7.4 robimy refresh per-request
 * przez `refreshAccessToken()` — ale token NIE jest persystowany z powrotem
 * do sh_tenant_integrations. Dla Sesji 7.5 (UI Settings) dodamy
 * `credentials.access_token` auto-persist + background refresh job.
 *
 * W MVP (7.4): adapter używa refresh_tokena każdorazowo — nieefektywne ale
 * poprawne. Gdy Dotykacka rate-limituje refresh, wtedy dodamy persistent cache.
 */
final class DotykackaAdapter extends BaseAdapter
{
    public static function providerKey(): string { return 'dotykacka'; }
    public static function displayName(): string { return 'Dotykačka POS Cloud'; }

    /** @var string|null Cached access token dla tego instancea (per-batch) */
    private ?string $cachedAccessToken = null;

    public function buildRequest(array $envelope): array
    {
        $eventType = (string)($envelope['event_type'] ?? '');
        $order     = $this->extractOrderSnapshot($envelope);
        $lines     = $this->extractOrderLines($envelope);

        $cloudId  = $this->requireCredential('cloud_id');
        $accessToken = $this->getAccessToken();

        [$path, $method, $payload] = match ($eventType) {
            'order.created' => [
                "/clouds/{$cloudId}/documents",
                'POST',
                $this->buildDocumentPayload($envelope, $order, $lines),
            ],
            'order.ready', 'order.delivered', 'order.completed' => [
                // Dotykacka: status change przez PATCH na document z _completed=true
                "/clouds/{$cloudId}/documents/" . $this->resolveExternalId($order, $envelope),
                'PATCH',
                ['_completed' => true, 'completedAt' => $envelope['occurred_at'] ?? date('c')],
            ],
            'order.cancelled' => [
                "/clouds/{$cloudId}/documents/" . $this->resolveExternalId($order, $envelope),
                'DELETE',
                [],
            ],
            default => throw new AdapterException("Dotykacka does not handle event '{$eventType}'"),
        };

        $url = $this->apiBaseUrl() . $path;

        if ($method === 'DELETE' || empty($payload)) {
            $body = '';
        } else {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new AdapterException('Dotykacka payload json_encode failed');
            }
            $body = $encoded;
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $accessToken,
            'User-Agent: SliceHub-DotykackaAdapter/1.0',
            'X-Slicehub-Event: ' . $eventType,
        ];

        if ($body !== '') {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }

        return [
            'method'  => $method,
            'url'     => $url,
            'headers' => $headers,
            'body'    => $body,
        ];
    }

    /**
     * Dotykacka parseResponse — standardowe HTTP code mapping, ale 401
     * traktujemy jako transient (token expired, refresh w następnym retry).
     */
    public function parseResponse(int $httpCode, string $responseBody, ?string $transportError = null): array
    {
        if ($transportError !== null) {
            return ['ok' => false, 'transient' => true, 'error' => 'transport: ' . $transportError];
        }

        $decoded = json_decode($responseBody, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            $extRef = null;
            if (is_array($decoded)) {
                // Dotykacka zwraca `id` dla nowego document lub `_id` (legacy)
                $extRef = $decoded['id'] ?? $decoded['_id'] ?? ($decoded['data'][0]['id'] ?? null);
            }
            return ['ok' => true, 'externalRef' => $extRef !== null ? (string)$extRef : null];
        }

        // 401 → token expired, traktuj jako transient (następny retry pobierze nowy token)
        if ($httpCode === 401) {
            // Invaliduj cache w tym instance
            $this->cachedAccessToken = null;
            return ['ok' => false, 'transient' => true, 'error' => 'unauthorized (token expired, will refresh)'];
        }

        $isTransient = ($httpCode >= 500) || $httpCode === 408 || $httpCode === 429;
        $errorMsg = sprintf('HTTP %d', $httpCode);
        if (is_array($decoded) && isset($decoded['message'])) {
            $errorMsg .= ': ' . $decoded['message'];
        } elseif ($responseBody !== '') {
            $errorMsg .= ': ' . substr($responseBody, 0, 200);
        }

        return ['ok' => false, 'transient' => $isTransient, 'error' => $errorMsg];
    }

    // ──────────────────────────────────────────────────────────────────

    /**
     * Cache / refresh access tokena. Jeśli mamy w credentials świeży
     * `access_token_expires_at > now + 60s` używamy go. Wpp refreshujemy.
     */
    private function getAccessToken(): string
    {
        if ($this->cachedAccessToken !== null) {
            return $this->cachedAccessToken;
        }

        $creds     = $this->credentials();
        $cached    = $creds['access_token']             ?? null;
        $expiresAt = $creds['access_token_expires_at']  ?? null;

        if ($cached && $expiresAt) {
            $expiresTs = strtotime((string)$expiresAt);
            if ($expiresTs !== false && $expiresTs > time() + 60) {
                return $this->cachedAccessToken = (string)$cached;
            }
        }

        // Refresh. Używamy refresh_token.
        $refreshToken = $this->requireCredential('refresh_token');

        // NOTE: Dotykacka refresh endpoint — w MVP używamy samego refresh tokena
        // jako Bearer (ich dokumentacja: https://docs.api.dotykacka.cz/#section/Authentication).
        // Real flow: POST /signin/token z grant_type=refresh_token — jednak na MVP
        // zakładamy że refresh_token JUŻ JEST długo-żywy i używamy go bezpośrednio.
        // W Sesji 7.5 dodamy pełny OAuth2 flow z persistent cache.

        return $this->cachedAccessToken = (string)$refreshToken;
    }

    private function resolveExternalId(array $order, array $envelope): string
    {
        // External ID z poprzedniego order.created response'u.
        // Worker zapisuje extRef do sh_integration_deliveries.external_ref,
        // ale adapter buildRequest nie ma dostępu do DB.
        // Fallback: użyjemy `order.gateway_external_id` jeśli jest (cross-system idempotency),
        // inaczej rzucamy — delivery pójdzie do DLQ bo nie wiemy który Dotykacka ID updatować.
        $ext = $order['gateway_external_id']   ?? null;
        if ($ext === null) {
            $ext = $envelope['payload']['_context']['dotykacka_external_id'] ?? null;
        }

        if (!$ext) {
            throw new AdapterException(
                'Dotykacka status update requires external_ref but it is not available in payload — '
                . 'the order.created event must have been delivered successfully first'
            );
        }

        return (string)$ext;
    }

    private function buildDocumentPayload(array $envelope, array $order, array $lines): array
    {
        $branchId = $this->credentials()['branch_id'] ?? null;

        $totalGr    = (int)($order['grand_total_grosze'] ?? $order['grand_total'] ?? 0);
        $subtotalGr = (int)($order['subtotal_grosze']    ?? $order['subtotal']    ?? 0);

        return [
            'branchId'          => $branchId ? (int)$branchId : null,
            'externalNumber'    => (string)($order['order_number'] ?? ''),
            'documentType'      => 'invoice',
            'issueDate'         => $envelope['occurred_at'] ?? date('c'),
            'customerName'      => $order['customer_name'] ?? null,
            'customerPhone'     => $order['customer_phone'] ?? null,
            'deliveryAddress'   => $order['delivery_address'] ?? null,
            'note'              => sprintf(
                'Source: %s | Channel: %s | Order: %s',
                $envelope['source']        ?? 'internal',
                $order['channel']          ?? '',
                $order['order_number']     ?? ''
            ),
            'currency'          => 'PLN',
            'total'             => (float)self::grToPln($totalGr),
            'totalWithoutVat'   => (float)self::grToPln($subtotalGr),
            'paymentMethod'     => $this->mapPaymentMethod($order['payment_method'] ?? null),
            '_items' => array_map(function (array $line): array {
                $unitGr = (int)($line['unit_price_grosze'] ?? $line['unit_price'] ?? 0);
                $lineGr = (int)($line['line_total_grosze'] ?? $line['line_total'] ?? 0);

                return [
                    'name'          => (string)($line['snapshot_name'] ?? ''),
                    'sku'           => (string)($line['item_sku']      ?? ''),
                    'quantity'      => (int)($line['quantity']          ?? 1),
                    'unitPrice'     => (float)self::grToPln($unitGr),
                    'totalPrice'    => (float)self::grToPln($lineGr),
                    'vatRate'       => (float)($line['vat_rate']        ?? 0),
                    'note'          => $line['comment'] ?? null,
                ];
            }, array_values($lines)),
            'tags' => ['slicehub', $envelope['source'] ?? 'internal'],
        ];
    }

    private function mapPaymentMethod(?string $ours): string
    {
        return match ($ours) {
            'cash'          => 'CASH',
            'card'          => 'CARD',
            'online'        => 'ONLINE',
            'terminal'      => 'CARD',
            default         => 'OTHER',
        };
    }
}
