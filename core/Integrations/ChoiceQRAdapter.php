<?php

declare(strict_types=1);

namespace SliceHub\Integrations;

/**
 * ChoiceQRAdapter — push statusów SliceHub → ChoiceQR API (P1).
 *
 * ChoiceQR ma odwrócony model: oni pushują zamówienia do nas (webhook.php, P0).
 * Ten adapter obsługuje kierunek zwrotny: my informujemy ChoiceQR o zmianach
 * statusu zamówienia (anulacja, zamknięcie, status dostawy).
 *
 * P2: Adapter obsługuje też inbound — ChoiceQR wysyła eventy (status changes,
 * QR payments) do events.php, który deleguje do parseInboundCallback().
 *
 * Credentials (sh_tenant_integrations.credentials JSON):
 *   {
 *     "token":         "JWT_BEARER_TOKEN",   // wymagane — JWT z ChoiceQR (ważny 5 lat)
 *     "webhook_token": "SECRET_TOKEN",       // do auth webhooków (P0, nie używane tu)
 *     "var_symbol":    "10102"               // identyfikator firmy w ChoiceQR
 *   }
 *
 * api_base_url: https://open-api.choiceqr.com
 *
 * Event mapping (SliceHub → ChoiceQR):
 *   order.cancelled  → PUT /orders/:_id/cancel   { reason }
 *   order.ready      → PUT /orders/:_id/close
 *   order.delivered  → PUT /orders/:_id/close
 *   order.completed  → PUT /orders/:_id/close
 *   order.dispatched → PUT /orders/:_id/delivery { deliveryStatus: "waitingForPickUp" }
 *   order.in_delivery→ PUT /orders/:_id/delivery { deliveryStatus: "progress" }
 *
 * :_id = gateway_external_id z sh_orders (MongoDB ObjectID z ChoiceQR).
 *
 * @see _docs/integrations/choiceqr_integration.md
 */
final class ChoiceQRAdapter extends BaseAdapter
{
    public static function providerKey(): string { return 'choiceqr'; }
    public static function displayName(): string { return 'ChoiceQR POS'; }

    /**
     * Eventy które ten adapter potrafi zmapować na ChoiceQR API calls.
     * order.created / order.accepted są inbound (ChoiceQR → my), nie push.
     */
    private const PUSH_EVENTS = [
        'order.cancelled',
        'order.ready',
        'order.delivered',
        'order.completed',
        'order.dispatched',
        'order.in_delivery',
    ];

    /**
     * Eventy inbound od ChoiceQR (parseInboundCallback).
     * Wykonywane przez events.php — aktualizują status zamówienia w SliceHub.
     */
    private const INBOUND_EVENTS = [
        'order.cancelled',
        'order.closed',
        'order.delivery.update',
        'order.qrPayment.completed',
        'order.qrPayment.error',
    ];

    /**
     * Mapowanie ChoiceQR delivery status → SliceHub delivery_status.
     * ChoiceQR: idle → created → processing → waitingForPickUp → arrivedForPickUp → progress → done / cancelled / error
     * SliceHub: NULL | unassigned | in_delivery | delivered
     */
    private const DELIVERY_STATUS_MAP = [
        'created'          => 'unassigned',
        'processing'       => 'unassigned',
        'waitingForPickUp' => 'unassigned',
        'arrivedForPickUp' => 'unassigned',
        'progress'         => 'in_delivery',
        'done'             => 'delivered',
        // idle, cancelled, error → null (no change)
    ];

    /**
     * Mapowanie eventów SliceHub → (ChoiceQR endpoint, HTTP method, body).
     *
     * @return array{method: string, url: string, headers: array<int,string>, body: string}
     */
    public function buildRequest(array $envelope): array
    {
        $eventType = (string)($envelope['event_type'] ?? '');
        $order     = $this->extractOrderSnapshot($envelope);
        $token     = $this->requireCredential('token');

        // Pobierz ChoiceQR order _id (MongoDB ObjectID)
        $externalId = $this->resolveExternalId($envelope, $order);
        if ($externalId === '' || $externalId === null) {
            throw new AdapterException(sprintf(
                '[choiceqr] Cannot resolve gateway_external_id for order %s — not a ChoiceQR order or missing gateway columns',
                $order['id'] ?? $envelope['aggregate_id'] ?? '?'
            ));
        }

        [$path, $method, $payload] = $this->mapEventToChoiceQR($eventType, $envelope, $order);

        $url  = $this->apiBaseUrl() . '/orders/' . urlencode($externalId) . $path;
        $body = $payload !== null
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';
        if ($body === false) {
            $body = '';
        }

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'User-Agent: SliceHub-ChoiceQRAdapter/1.0',
            'X-Slicehub-Event: ' . $eventType,
        ];

        return [
            'method'  => $method,
            'url'     => $url,
            'headers' => $headers,
            'body'    => $body,
        ];
    }

    /**
     * ChoiceQR używa standardowych HTTP status codes.
     * 200/204 = sukces. 4xx (poza 408/429) = permanent. 5xx/408/429 = transient.
     *
     * Dziedziczymy domyślną implementację z BaseAdapter — wystarczy.
     */

    /**
     * Override: filtrujemy tylko eventy które adapter potrafi zmapować (push).
     * events_bridged z DB może zawierać inbound eventy (order.created, order.accepted)
     * — te nie powinny być pushowane do ChoiceQR.
     */
    public function supportsEvent(string $eventType): bool
    {
        return in_array($eventType, self::PUSH_EVENTS, true);
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * Mapuj event SliceHub na (path, method, body) ChoiceQR API.
     *
     * @return array{0: string, 1: string, 2: ?array}
     */
    private function mapEventToChoiceQR(string $eventType, array $envelope, array $order): array
    {
        $context = $order['_context'] ?? ($envelope['payload']['_context'] ?? []);

        return match ($eventType) {
            'order.cancelled' => [
                '/cancel',
                'PUT',
                ['reason' => (string)($context['cancellation_reason'] ?? 'cancelled_by_restaurant')],
            ],
            'order.ready', 'order.delivered', 'order.completed' => [
                '/close',
                'PUT',
                null,
            ],
            'order.dispatched' => [
                '/delivery',
                'PUT',
                ['deliveryStatus' => 'waitingForPickUp'],
            ],
            'order.in_delivery' => [
                '/delivery',
                'PUT',
                ['deliveryStatus' => 'progress'],
            ],
            default => throw new AdapterException(sprintf(
                '[choiceqr] Event type "%s" is not supported by ChoiceQRAdapter',
                $eventType
            )),
        };
    }

    /**
     * Resolve ChoiceQR order _id (gateway_external_id) z envelope.
     *
     * Kolejność:
     *   1. order.gateway_external_id (z snapshotOrder — dodane w P1)
     *   2. _context.gateway_external_id (z webhook.php publish)
     *   3. null → AdapterException (nie jest zamówieniem ChoiceQR)
     */
    private function resolveExternalId(array $envelope, array $order): ?string
    {
        // 1. Snapshot kolumna (jeśli OrderEventPublisher ją dociągnął)
        $extId = $order['gateway_external_id'] ?? null;
        if (is_string($extId) && $extId !== '') {
            return $extId;
        }

        // 2. Context z webhook publish
        $context = $order['_context'] ?? ($envelope['payload']['_context'] ?? []);
        $extId = $context['gateway_external_id'] ?? null;
        if (is_string($extId) && $extId !== '') {
            return $extId;
        }

        // 3. Sprawdź gateway_source — jeśli nie 'choiceqr', to nie nasze zamówienie
        $gwSource = $order['gateway_source'] ?? ($context['gateway_source'] ?? null);
        if ($gwSource !== null && $gwSource !== 'choiceqr') {
            return null;
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────
    // INBOUND (P2)
    // ─────────────────────────────────────────────────────────────────

    public static function supportsInbound(): bool
    {
        return true;
    }

    /**
     * Obsługa INBOUND callbacków od ChoiceQR — eventy webhook (status changes, QR payments).
     *
     * Format payloadu ChoiceQR (webhook events, webhooks.md):
     *   {
     *     "id":         "unique_event_id",
     *     "type":       "order.delivery.update",
     *     "langCode":   "en",
     *     "data":       Order schema (pełny, /content/order/schema.md),
     *     "varSymbol":  "10102",
     *     "timestramp": "1690234567"
     *   }
     *
     * UWAGA (NC1/NC12 fix): dla eventów order.* pole `data` = pełny Order schema, NIE
     * uproszczony {_id, deliveryStatus}. Status dostawy = data.delivery.status (zagnieżdżone),
     * dane płatności = data.paymentCustomerDetails / data.qrPayment (nie data.payment).
     *
     * Auth: ChoiceQR nie wspiera HMAC dla POS webhook — token w URL path (?t=SECRET).
     * events.php weryfikuje token przed delegacją do adaptera.
     *
     * @return array{
     *     ok: bool,
     *     signature_verified: bool,
     *     external_event_id?: ?string,
     *     external_ref?: ?string,
     *     event_type?: ?string,
     *     new_status?: ?string,
     *     new_delivery_status?: ?string,
     *     new_payment_status?: ?string,
     *     payload?: array,
     *     error?: string,
     * }
     */
    public function parseInboundCallback(string $rawBody, array $headers, array $credentials): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return ['ok' => false, 'signature_verified' => true, 'error' => 'body is not valid JSON'];
        }

        $externalEventId = (string)($payload['id'] ?? '');
        $eventType       = (string)($payload['type'] ?? '');
        $varSymbol       = (string)($payload['varSymbol'] ?? '');
        $data            = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if ($externalEventId === '' || $eventType === '') {
            return ['ok' => false, 'signature_verified' => true, 'error' => 'missing id or type in event payload'];
        }

        // Extract order _id from event data
        $externalRef = $this->extractOrderIdFromEventData($data);
        if ($externalRef === null) {
            return [
                'ok' => false,
                'signature_verified' => true,
                'external_event_id' => $externalEventId,
                'error' => 'cannot extract order _id from event data',
            ];
        }

        // Map event type to internal status changes
        $newStatus = null;
        $newDeliveryStatus = null;
        $newPaymentStatus = null;
        $internalEventType = null;
        $extraPayload = [
            'provider'    => 'choiceqr',
            'var_symbol'  => $varSymbol,
            'event_id'    => $externalEventId,
            'timestamp'   => (string)($payload['timestramp'] ?? ''),
        ];

        switch ($eventType) {
            case 'order.cancelled':
                $newStatus = 'cancelled';
                $internalEventType = 'order.cancelled';
                $extraPayload['cancellation_reason'] = (string)($data['reason'] ?? 'cancelled_by_choiceqr');
                break;

            case 'order.closed':
                $newStatus = 'completed';
                $internalEventType = 'order.completed';
                break;

            case 'order.delivery.update':
                // NC1 fix: webhooks.md mówi że data = pełny Order schema, gdzie
                // status dostawy jest zagnieżdżony w delivery.status (Order takeaway/delivery
                // schema). Fallback na płaskie deliveryStatus dla zgodności wstecznej.
                $cqrDeliveryStatus = '';
                if (is_array($data['delivery'] ?? null) && isset($data['delivery']['status'])) {
                    $cqrDeliveryStatus = (string)$data['delivery']['status'];
                } elseif (isset($data['deliveryStatus'])) {
                    $cqrDeliveryStatus = (string)$data['deliveryStatus'];
                }
                $newDeliveryStatus = self::DELIVERY_STATUS_MAP[$cqrDeliveryStatus] ?? null;
                $extraPayload['choiceqr_delivery_status'] = $cqrDeliveryStatus;
                $internalEventType = match ($newDeliveryStatus) {
                    'in_delivery' => 'order.in_delivery',
                    'delivered'   => 'order.delivered',
                    default       => null,
                };
                break;

            case 'order.qrPayment.completed':
                // NC12 fix: webhooks.md mówi że data = pełny Order schema, gdzie
                // dane płatności są w paymentCustomerDetails (Customer payment information)
                // i qrPayment (QR Payment schema). Pole "payment" nie istnieje w Order schema.
                // Fallback na płaskie "payment" dla zgodności wstecznej.
                $newPaymentStatus = 'online_paid';
                $internalEventType = 'order.payment_completed';
                $extraPayload['qr_payment'] = true;
                $paymentData = $data['paymentCustomerDetails'] ?? $data['qrPayment'] ?? $data['payment'] ?? [];
                $extraPayload['payment_data'] = is_array($paymentData) ? $paymentData : [];
                break;

            case 'order.qrPayment.error':
                $internalEventType = null; // log only, no status change
                $extraPayload['qr_payment_error'] = (string)($data['error'] ?? 'unknown');
                break;

            default:
                // Unknown event — log only
                return [
                    'ok' => true,
                    'signature_verified' => true,
                    'external_event_id' => $externalEventId,
                    'external_ref' => $externalRef,
                    'event_type' => null,
                    'payload' => $extraPayload,
                ];
        }

        return [
            'ok' => true,
            'signature_verified' => true,
            'external_event_id' => $externalEventId,
            'external_ref' => $externalRef,
            'event_type' => $internalEventType,
            'new_status' => $newStatus,
            'new_delivery_status' => $newDeliveryStatus,
            'new_payment_status' => $newPaymentStatus,
            'payload' => $extraPayload,
        ];
    }

    /**
     * Extract ChoiceQR order _id from event data (flexible — format varies per event type).
     */
    private function extractOrderIdFromEventData(array $data): ?string
    {
        // Direct _id
        $id = trim((string)($data['_id'] ?? ''));
        if ($id !== '') return $id;

        // Nested order._id
        $id = trim((string)($data['order']['_id'] ?? ''));
        if ($id !== '') return $id;

        // orderId field
        $id = trim((string)($data['orderId'] ?? ''));
        if ($id !== '') return $id;

        return null;
    }
}
