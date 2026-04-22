<?php
declare(strict_types=1);

/**
 * InAppChannel — broadcast statusu do otwartego trackera klienta przez SSE.
 *
 * Zamiast Redis pub/sub (zero dependency), zapisuje krótkotrwały rekord
 * do tabeli sh_sse_broadcast. Endpoint api/online/sse.php polluje tę tabelę
 * i streamuje nowe eventy do podłączonego klienta EventSource.
 *
 * Credentials JSON: (brak — channel nie wymaga zewnętrznej konfiguracji)
 * {
 *   "ttl_minutes": 5   // jak długo rekord żyje w tabeli (default 5 min)
 * }
 */
class InAppChannel implements ChannelInterface
{
    public static function getChannelType(): string
    {
        return 'in_app';
    }

    public function send(
        string $recipient,
        string $subject,
        string $body,
        array  $channelConfig,
        array  $ctx = []
    ): DeliveryResult {
        // Recipient dla in_app = tracking_token (zamiast telefonu/emaila)
        $trackingToken = (string)($ctx['tracking_token'] ?? $recipient);
        if ($trackingToken === '') {
            return DeliveryResult::fail('InApp: no tracking_token in ctx.');
        }

        $tenantId  = (int)($ctx['tenant_id'] ?? 0);
        $eventType = (string)($ctx['event_type'] ?? 'order.status_update');

        // Musimy mieć PDO — przekazane w ctx przez dispatcher
        $pdo = $ctx['pdo'] ?? null;
        if (!($pdo instanceof \PDO)) {
            return DeliveryResult::fail('InApp: PDO not available in ctx.');
        }

        $payload = [
            'event_type' => $eventType,
            'body'       => $body,
            'order_id'   => $ctx['order_id'] ?? null,
            'ts'         => time(),
        ];

        try {
            $pdo->prepare(
                "INSERT INTO sh_sse_broadcast
                    (tenant_id, tracking_token, event_type, payload_json, created_at)
                 VALUES (:tid, :tok, :et, :pl, NOW())"
            )->execute([
                ':tid' => $tenantId,
                ':tok' => $trackingToken,
                ':et'  => $eventType,
                ':pl'  => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            return DeliveryResult::fail('InApp DB error: ' . $e->getMessage());
        }

        return DeliveryResult::ok();
    }
}
