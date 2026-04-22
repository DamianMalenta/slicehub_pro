<?php

declare(strict_types=1);

/**
 * OrderEventPublisher — transactional outbox publisher dla eventów lifecycle.
 *
 * Wywoływany W TEJ SAMEJ TRANSAKCJI co zapis do sh_orders / sh_order_audit —
 * dzięki temu event albo jest zapisany razem z orderem, albo nie ma go wcale
 * (zero eventual inconsistency między local state a outboxem).
 *
 * Konsumpcja (asynchroniczna):
 *   • scripts/worker_webhooks.php  (cron) — push do sh_webhook_endpoints
 *   • scripts/worker_integrations.php (cron) — push do sh_tenant_integrations
 *     (Papu, Dotykacka, GastroSoft, ...) przez adaptery z core/Integrations/
 *
 * Gwarancje:
 *   • Silent degradation — brak tabeli `sh_event_outbox` → error_log + skip.
 *     Żadne publish() nie może złamać transakcji głównej.
 *   • Idempotency — (tenant_id, idempotency_key) UNIQUE. Drugi publish tego samego
 *     lifecycle transition jest no-opem (INSERT IGNORE).
 *   • Snapshot payload — publisher otrzymuje już zserializowane dane. Worker
 *     NIE dociąga danych z DB (zero joinów w czasie dispatchu), dzięki czemu
 *     event_type.payload jest pełnym snapshotem w danym momencie.
 *
 * Kanoniczne eventy (1:1 z _docs/08_ORDER_STATUS_DICTIONARY.md):
 *   order.created     — zamówienie zapisane (new)
 *   order.accepted    — kuchnia przyjęła (new → accepted)
 *   order.preparing   — kuchnia zaczęła (accepted → preparing)
 *   order.ready       — kuchnia skończyła (preparing → ready)
 *   order.dispatched  — driver przypisany (ready → ready, delivery_status=dispatched)
 *   order.in_delivery — driver ruszył (delivery_status=in_delivery)
 *   order.delivered   — driver dostarczył (delivery_status=delivered)
 *   order.completed   — zamówienie zamknięte (status=completed)
 *   order.cancelled   — anulowane (status=cancelled)
 *   order.edited      — edytowane po przyjęciu (partial payload w context)
 *   order.recalled    — KDS rollback (ready → preparing)
 *
 * @see _docs/09_EVENT_SYSTEM.md
 */
final class OrderEventPublisher
{
    /** Kanoniczna lista typów eventów — whitelist dla walidacji publish(). */
    public const EVENT_TYPES = [
        'order.created',
        'order.accepted',
        'order.preparing',
        'order.ready',
        'order.dispatched',
        'order.in_delivery',
        'order.delivered',
        'order.completed',
        'order.cancelled',
        'order.edited',
        'order.recalled',
        'payment.settled',
        'payment.refunded',
    ];

    /** Flaga per-request — true gdy wiemy że tabela outbox istnieje. */
    private static ?bool $outboxAvailable = null;

    /**
     * Publish event do outboxu.
     *
     * @param  \PDO        $pdo           Połączenie w otwartej transakcji (zalecane)
     * @param  int         $tenantId
     * @param  string      $eventType     Jeden z self::EVENT_TYPES
     * @param  string      $aggregateId   UUID zamówienia
     * @param  array       $payload       Snapshot orderu + line items + context
     * @param  array       $opts          {
     *     source?:         string,  // 'online' | 'pos' | 'kds' | 'delivery' | 'courses' | 'gateway' | 'kiosk'
     *     actorType?:      string,  // 'staff' | 'guest' | 'system' | 'external_api'
     *     actorId?:        string,
     *     idempotencyKey?: string,  // default: "{aggregateId}:{eventType}"
     *     aggregateType?:  string,  // default: 'order'
     * }
     * @return int|null  ID z sh_event_outbox albo null (gdy outbox nieaktywny / duplikat).
     */
    public static function publish(
        \PDO $pdo,
        int $tenantId,
        string $eventType,
        string $aggregateId,
        array $payload,
        array $opts = []
    ): ?int {
        if ($tenantId <= 0 || $aggregateId === '') {
            error_log("[OrderEventPublisher] invalid args: tenant={$tenantId}, aggregate='{$aggregateId}'");
            return null;
        }

        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            error_log("[OrderEventPublisher] unknown event type: {$eventType}");
            return null;
        }

        if (!self::ensureOutboxAvailable($pdo)) {
            return null;
        }

        $source         = (string)($opts['source'] ?? 'internal');
        $actorType      = isset($opts['actorType']) ? (string)$opts['actorType'] : null;
        $actorId        = isset($opts['actorId'])   ? (string)$opts['actorId']   : null;
        $aggregateType  = (string)($opts['aggregateType'] ?? 'order');
        $idempotencyKey = (string)($opts['idempotencyKey'] ?? ($aggregateId . ':' . $eventType));

        try {
            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO sh_event_outbox
                    (tenant_id, event_type, aggregate_type, aggregate_id,
                     idempotency_key, payload, source, actor_type, actor_id,
                     status, attempts, created_at)
                 VALUES
                    (:tid, :etype, :atype, :aid,
                     :idk, :pl, :src, :actor_t, :actor_i,
                     'pending', 0, NOW())"
            );

            $stmt->execute([
                ':tid'     => $tenantId,
                ':etype'   => $eventType,
                ':atype'   => $aggregateType,
                ':aid'     => $aggregateId,
                ':idk'     => $idempotencyKey,
                ':pl'      => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':src'     => $source,
                ':actor_t' => $actorType,
                ':actor_i' => $actorId,
            ]);

            $insertedId = (int)$pdo->lastInsertId();

            return $insertedId > 0 ? $insertedId : null;

        } catch (\Throwable $e) {
            error_log("[OrderEventPublisher] publish failed (event={$eventType}, order={$aggregateId}): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Helper: publish całego stanu zamówienia jako eventu lifecycle.
     * Automatycznie dodaje order header + lines do payloadu.
     *
     * @param  array $context  Dodatkowe dane (np. kto zaakceptował, notatka anulacji)
     */
    public static function publishOrderLifecycle(
        \PDO $pdo,
        int $tenantId,
        string $eventType,
        string $orderId,
        array $context = [],
        array $opts = []
    ): ?int {
        if (!self::ensureOutboxAvailable($pdo)) {
            return null;
        }

        try {
            $snap = self::snapshotOrder($pdo, $tenantId, $orderId);
            if ($snap === null) {
                error_log("[OrderEventPublisher] snapshot failed: order {$orderId} not found for tenant {$tenantId}");
                return null;
            }

            $snap['_context'] = $context;
            $snap['_meta'] = [
                'event_type'     => $eventType,
                'published_at'   => date('c'),
                'contract_version' => 1,
            ];

            return self::publish($pdo, $tenantId, $eventType, $orderId, $snap, $opts);

        } catch (\Throwable $e) {
            error_log("[OrderEventPublisher] publishOrderLifecycle failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Wewnętrzny snapshot — ładuje order header + lines dla payloadu.
     * Worker NIE dociąga danych z DB w momencie dispatchu — bierze payload as-is.
     */
    private static function snapshotOrder(\PDO $pdo, int $tenantId, string $orderId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT id, tenant_id, order_number, channel, order_type, source,
                    status, payment_status, payment_method,
                    subtotal, discount_amount, delivery_fee, grand_total,
                    loyalty_points_earned,
                    customer_name, customer_phone, customer_email,
                    sms_consent, marketing_consent,
                    delivery_address,
                    lat, lng, promised_time,
                    tracking_token, created_at, updated_at
             FROM sh_orders
             WHERE id = :oid AND tenant_id = :tid
             LIMIT 1"
        );
        $stmt->execute([':oid' => $orderId, ':tid' => $tenantId]);
        $order = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$order) {
            return null;
        }

        // Opcjonalne kolumny delivery_status (może nie istnieć w starszych bazach)
        try {
            $deliveryProbe = $pdo->prepare(
                "SELECT delivery_status, driver_id
                 FROM sh_orders WHERE id = :oid LIMIT 1"
            );
            $deliveryProbe->execute([':oid' => $orderId]);
            $delivery = $deliveryProbe->fetch(\PDO::FETCH_ASSOC);
            if ($delivery) {
                $order['delivery_status'] = $delivery['delivery_status'] ?? null;
                $order['driver_id']       = $delivery['driver_id']       ?? null;
            }
        } catch (\Throwable $e) {
            // kolumny nie istnieją — skip
        }

        // Line items
        try {
            $lineStmt = $pdo->prepare(
                "SELECT id, item_sku, snapshot_name, unit_price, quantity, line_total,
                        vat_rate, vat_amount, modifiers_json, removed_ingredients_json, comment
                 FROM sh_order_lines
                 WHERE order_id = :oid
                 ORDER BY id ASC"
            );
            $lineStmt->execute([':oid' => $orderId]);
            $lines = $lineStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            foreach ($lines as &$line) {
                if (!empty($line['modifiers_json'])) {
                    $decoded = json_decode($line['modifiers_json'], true);
                    $line['modifiers'] = is_array($decoded) ? $decoded : [];
                }
                if (!empty($line['removed_ingredients_json'])) {
                    $decoded = json_decode($line['removed_ingredients_json'], true);
                    $line['removed_ingredients'] = is_array($decoded) ? $decoded : [];
                }
                unset($line['modifiers_json'], $line['removed_ingredients_json']);
            }
            unset($line);

            $order['lines'] = $lines;
        } catch (\Throwable $e) {
            $order['lines'] = [];
        }

        return $order;
    }

    /**
     * Feature-detect: czy tabela sh_event_outbox istnieje?
     * Cache per-request w static.
     */
    private static function ensureOutboxAvailable(\PDO $pdo): bool
    {
        if (self::$outboxAvailable !== null) {
            return self::$outboxAvailable;
        }

        try {
            $pdo->query("SELECT 1 FROM sh_event_outbox LIMIT 0");
            self::$outboxAvailable = true;
        } catch (\Throwable $e) {
            self::$outboxAvailable = false;
            error_log("[OrderEventPublisher] outbox table missing — events will be silently dropped. Run migration 026.");
        }

        return self::$outboxAvailable;
    }

    /**
     * Reset cache — wyłącznie dla testów.
     * @internal
     */
    public static function resetCache(): void
    {
        self::$outboxAvailable = null;
    }
}
