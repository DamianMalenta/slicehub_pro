<?php

declare(strict_types=1);

namespace SliceHub\Integrations;

/**
 * ElzabAdapter — integracja z drukarką fiskalną Elzab Zeta Online.
 *
 * UWAGA: Ten adapter jest zarejestrowany w AdapterRegistry dla widoczności
 * w UI integracji i zarządzania konfiguracją (sh_tenant_integrations).
 *
 * Faktyczna fiskalizacja odbywa się SYNCHRONICZNIE przez ElzabFiscalEngine,
 * NIE przez asynchroniczny IntegrationDispatcher (cURL). Powód: drukarka
 * używa surowego TCP (protokół Thermal), a POS musi natychmiast otrzymać
 * numer paragonu fiskalnego.
 *
 * Ten adapter obsługuje tylko zdarzenie 'order.completed' i buduje
 * pseudo-request (URL tcp://host:port) dla celów logowania/audytu.
 * Rzeczywista komunikacja TCP odbywa się w ElzabFiscalEngine.
 *
 * Credentials (sh_tenant_integrations.credentials JSON):
 *   {
 *     "cashbox": "POS1",
 *     "host": "192.168.1.50",
 *     "port": 1001
 *   }
 *
 * api_base_url: tcp://192.168.1.50:1001
 *
 * Events bridged: ['order.completed']
 */
final class ElzabAdapter extends BaseAdapter
{
    public static function providerKey(): string { return 'elzab'; }
    public static function displayName(): string { return 'Elzab Zeta Online (Drukarka Fiskalna)'; }

    public function buildRequest(array $envelope): array
    {
        $order = $this->extractOrderSnapshot($envelope);
        $orderNumber = (string)($order['order_number'] ?? $envelope['aggregate_id'] ?? '');

        return [
            'method'  => 'PRINT',
            'url'     => $this->apiBaseUrl() ?: 'tcp://localhost:1001',
            'headers' => [
                'Content-Type: application/json',
                'X-Slicehub-Event: ' . (string)($envelope['event_type'] ?? ''),
                'X-Slicehub-Fiscal: elzab-thermal',
            ],
            'body'    => json_encode([
                'order_id'     => $envelope['aggregate_id'] ?? '',
                'order_number' => $orderNumber,
                'tenant_id'    => $this->getTenantId(),
                'protocol'     => 'thermal',
                'note'         => 'Fiscal printing handled synchronously by ElzabFiscalEngine',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * parseResponse — dla Elzab adapter jest no-op bo fiskalizacja
     * odbywa się przez ElzabFiscalEngine, nie przez dispatcher.
     * Zwracamy success=true żeby dispatcher nie próbował retry.
     */
    public function parseResponse(int $httpCode, string $responseBody, ?string $transportError = null): array
    {
        // Jeśli transportError — drukarka offline, transient
        if ($transportError !== null) {
            return ['ok' => false, 'transient' => true, 'error' => 'transport: ' . $transportError];
        }

        // Sukces — fiscal printing odbywa się poza dispatcher
        return ['ok' => true, 'externalRef' => null];
    }

    public function supportsEvent(string $eventType): bool
    {
        // Elzab obsługuje tylko completed orders (fiskalizacja po rozliczeniu)
        $bridged = json_decode((string)($this->integration['events_bridged'] ?? '[]'), true);
        if (!is_array($bridged) || empty($bridged)) {
            return $eventType === 'order.completed';
        }
        return in_array('*', $bridged, true) || in_array($eventType, $bridged, true);
    }
}
