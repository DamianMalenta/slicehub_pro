<?php

declare(strict_types=1);

namespace SliceHub\Integrations;

/**
 * BaseAdapter — abstract kontrakt dla wszystkich 3rd-party POS/delivery adapterów.
 *
 * Zasady projektowe:
 *   1. Każdy provider = osobna klasa dziedziczą z BaseAdapter.
 *   2. Adapter NIE łączy się z siecią samodzielnie — robi to `worker_integrations.php`
 *      poprzez injected HTTP transport (testable + dry-run friendly).
 *   3. Adapter dostarcza TYLKO: mapowanie event→HTTP request oraz interpretację
 *      response (czy sukces / czy transient / co to external_ref).
 *   4. Stan (retry counter, last error, backoff) trzyma `sh_integration_deliveries`
 *      — adapter jest bezstanowy.
 *
 * Flow:
 *   worker_integrations.php
 *     ├─ AdapterRegistry::resolveForTenant($tenantId)  → [PapuAdapter, DotykackaAdapter, ...]
 *     ├─ Dla każdego (event, adapter):
 *     │    ├─ $adapter->supportsEvent($eventType)      → false? skip
 *     │    ├─ $adapter->buildRequest($envelope)        → [method, url, headers, body]
 *     │    ├─ $transport($method, $url, $headers, $body, $timeout)
 *     │    └─ $adapter->parseResponse($httpCode, $body) → [ok, transient, extRef, errorMsg]
 *     └─ Update sh_integration_deliveries + sh_integration_attempts
 */
abstract class BaseAdapter
{
    /**
     * Wiersz z sh_tenant_integrations (provider, api_base_url, credentials,
     * events_bridged, direction, is_active, ...).
     *
     * @var array<string,mixed>
     */
    protected array $integration;

    public function __construct(array $integrationRow)
    {
        $this->integration = $integrationRow;
    }

    /**
     * Public gettery dla dispatchera — nie wymuszamy przekazywania całego wiersza
     * między warstwami.
     */
    public function getIntegrationId(): int
    {
        return (int)($this->integration['id'] ?? 0);
    }

    public function getTenantId(): int
    {
        return (int)($this->integration['tenant_id'] ?? 0);
    }

    public function getTimeoutSeconds(): int
    {
        return max(1, (int)($this->integration['timeout_seconds'] ?? 8));
    }

    public function getMaxRetries(): int
    {
        return max(1, (int)($this->integration['max_retries'] ?? 6));
    }

    /**
     * Unique provider key matching sh_tenant_integrations.provider.
     * Przykłady: 'papu', 'dotykacka', 'gastrosoft'.
     */
    abstract public static function providerKey(): string;

    /**
     * Human-readable display name dla logów / UI.
     */
    abstract public static function displayName(): string;

    /**
     * Zbuduj HTTP request dla danego eventu.
     *
     * @param  array $envelope  Pełny envelope eventu (patrz WebhookDispatcher::buildEnvelope).
     *                          Struktura:
     *                            event_id, event_type, aggregate_id, aggregate_type,
     *                            tenant_id, source, actor_type, actor_id, occurred_at,
     *                            attempt, payload (decoded JSON z sh_event_outbox).
     * @return array{method: string, url: string, headers: array<int,string>, body: string}
     *
     * @throws AdapterException  Gdy event nie może być zmapowany (np. brak SKU, brak credentials).
     */
    abstract public function buildRequest(array $envelope): array;

    /**
     * Interpretuj response 3rd-party. Każdy provider ma własną semantykę
     * (np. Papu zwraca 200 z `{ok: false}` gdy walidacja padła — to permanent error).
     *
     * @param  int         $httpCode
     * @param  string      $responseBody
     * @param  ?string     $transportError  Non-null gdy cURL padł (timeout, DNS, SSL).
     * @return array{
     *     ok: bool,
     *     transient: bool,      // True = retry warto, False = permanent (DLQ)
     *     externalRef?: string, // ID po stronie 3rd-party (provider-specific)
     *     error?: string,       // Human-readable message dla logów
     * }
     */
    public function parseResponse(int $httpCode, string $responseBody, ?string $transportError = null): array
    {
        // Domyślna implementacja — prosty HTTP status-code check.
        // Providerzy z custom semantyką response'u nadpisują.
        if ($transportError !== null) {
            return ['ok' => false, 'transient' => true, 'error' => 'transport: ' . $transportError];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['ok' => true, 'externalRef' => $this->extractExternalRef($responseBody)];
        }

        $isTransient = ($httpCode >= 500) || $httpCode === 408 || $httpCode === 429;
        return [
            'ok' => false,
            'transient' => $isTransient,
            'error' => sprintf('HTTP %d: %s', $httpCode, substr($responseBody, 0, 200)),
        ];
    }

    /**
     * Obsługa INBOUND callbacków od 3rd-party (status updates, cancellations,
     * driver assignments). Adapter verifies signature, parses payload, mapuje
     * na wewnętrzny event type.
     *
     * **Kontrakt:**
     *   • Weryfikacja signature (HMAC / OAuth / IP whitelist) — rzuca AdapterException gdy bad.
     *   • Parsowanie body → standardowa reprezentacja.
     *   • NIE modyfikuje bazy — to robi `api/integrations/inbound.php` po otrzymaniu wyniku.
     *
     * @param  string               $rawBody      Surowy request body (bytes). Adapter może potrzebować raw (nie decoded) dla HMAC.
     * @param  array<string,string> $headers      Case-preserved headery (po `apache_request_headers()`).
     * @param  array<string,mixed>  $credentials  Już odszyfrowane przez CredentialVault.
     *
     * @return array{
     *     ok: bool,
     *     signature_verified: bool,
     *     external_event_id?: ?string,   // ID eventu po stronie 3rd-party (idempotency key)
     *     external_ref?: ?string,        // ID zamówienia po stronie 3rd-party (match z sh_orders.gateway_external_id)
     *     event_type?: ?string,          // 'order.accepted' | 'order.preparing' | 'order.in_delivery' | 'order.delivered' | 'order.cancelled' | 'driver.assigned' | ...
     *     new_status?: ?string,          // Docelowy status w słowniku sh_orders.delivery_status / .status
     *     payload?: array,               // Dodatkowy kontekst dla publisherów (driver name, eta, cancel reason)
     *     error?: string,
     * }
     *
     * Default — rzuca NotImplementedException: adapter nie obsługuje inbound flows.
     * Podklasy dodają własną implementację (patrz PapuAdapter::parseInboundCallback).
     */
    public function parseInboundCallback(string $rawBody, array $headers, array $credentials): array
    {
        return [
            'ok' => false,
            'signature_verified' => false,
            'error' => sprintf(
                'Provider %s does not implement inbound callbacks (parseInboundCallback not overridden).',
                static::providerKey()
            ),
        ];
    }

    /**
     * Czy adapter obsługuje inbound direction? Default: false.
     * Podklasy nadpisują gdy implementują `parseInboundCallback`.
     */
    public static function supportsInbound(): bool
    {
        return false;
    }

    /**
     * Domyślna ekstrakcja external_ref z responsu (JSON → `id` / `order_id` / `external_id`).
     * Override w adapterze providera gdy inny klucz.
     */
    protected function extractExternalRef(string $responseBody): ?string
    {
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) return null;

        foreach (['external_id', 'id', 'order_id', 'uuid'] as $key) {
            if (!empty($decoded[$key])) {
                return (string)$decoded[$key];
            }
        }

        // Nested: {data: {id: ...}}
        if (isset($decoded['data']['id'])) {
            return (string)$decoded['data']['id'];
        }

        return null;
    }

    /**
     * Filtr — czy adapter powinien w ogóle próbować obsłużyć ten event?
     * Default: sprawdza `events_bridged` w sh_tenant_integrations.
     */
    public function supportsEvent(string $eventType): bool
    {
        $bridged = json_decode((string)($this->integration['events_bridged'] ?? '[]'), true);
        if (!is_array($bridged) || empty($bridged)) {
            // Brak whitelisty → domyślnie puszcza order.created (standard handshake).
            return $eventType === 'order.created';
        }
        return in_array('*', $bridged, true) || in_array($eventType, $bridged, true);
    }

    // ── Helpers dla podklas ─────────────────────────────────────────────

    /**
     * Pobranie credentials JSON (decoded) — cache per instance.
     *
     * Transparent decryption przez CredentialVault (m029/7.5):
     *   • Wartości zaczynające się od "vault:v1:" odszyfrowuje
     *   • Plaintext JSON (legacy) wraca as-is
     *   • Gdy decrypt zwróci null (vault misconfigured albo corrupted) → logguje warning + []
     */
    protected function credentials(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $raw = $this->integration['credentials'] ?? null;
        if ($raw === null || $raw === '') {
            return $cache = [];
        }

        $rawStr = (string)$raw;

        if (class_exists('\\CredentialVault') && \CredentialVault::isEncrypted($rawStr)) {
            $decrypted = \CredentialVault::decrypt($rawStr);
            if ($decrypted === null) {
                error_log(sprintf(
                    '[%s] CredentialVault decrypt failed for integration #%d — check SLICEHUB_VAULT_KEY',
                    static::providerKey(),
                    $this->integrationId()
                ));
                return $cache = [];
            }
            $rawStr = $decrypted;
        }

        $decoded = json_decode($rawStr, true);
        return $cache = (is_array($decoded) ? $decoded : []);
    }

    protected function apiBaseUrl(): string
    {
        return rtrim((string)($this->integration['api_base_url'] ?? ''), '/');
    }

    protected function tenantId(): int
    {
        return (int)($this->integration['tenant_id'] ?? 0);
    }

    protected function integrationId(): int
    {
        return (int)($this->integration['id'] ?? 0);
    }

    /**
     * Grosze → PLN jako string "12.34".
     */
    protected static function grToPln(int $grosze): string
    {
        return number_format($grosze / 100, 2, '.', '');
    }

    /**
     * Wymagaj wartości z credentials albo rzuć AdapterException (opuszczony klucz = DLQ).
     */
    protected function requireCredential(string $key): string
    {
        $creds = $this->credentials();
        $val = $creds[$key] ?? null;
        if (!is_string($val) || $val === '') {
            throw new AdapterException(sprintf(
                '[%s] Missing required credential: %s',
                static::providerKey(),
                $key
            ));
        }
        return $val;
    }

    /**
     * Pomocniczo — order payload z envelope.payload.order (snapshot z OrderEventPublisher).
     */
    protected function extractOrderSnapshot(array $envelope): array
    {
        $payload = $envelope['payload'] ?? [];
        if (!is_array($payload)) return [];

        // OrderEventPublisher::snapshotOrder() wrzuca header pod `order` + lines pod `order.lines`.
        if (isset($payload['order']) && is_array($payload['order'])) {
            return $payload['order'];
        }

        // Fallback — może header jest na top-level
        return $payload;
    }

    protected function extractOrderLines(array $envelope): array
    {
        $order = $this->extractOrderSnapshot($envelope);
        $lines = $order['lines'] ?? [];
        return is_array($lines) ? $lines : [];
    }
}

/**
 * Błąd mapowania eventu → request (permanent, nie retry).
 * Łapany przez worker_integrations i oznacza delivery jako 'dead'.
 */
class AdapterException extends \RuntimeException {}
