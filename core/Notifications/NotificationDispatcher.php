<?php
declare(strict_types=1);

/**
 * NotificationDispatcher — konsument sh_event_outbox dla powiadomień do klientów.
 *
 * Wzorzec identyczny jak WebhookDispatcher, ale zamiast wysyłać do webhook endpoints
 * rozwiązuje routing (sh_notification_routes) i woła kanały (Email/SMS/InApp).
 *
 * Flow (per event z outboxa):
 *   1. Claim pending events z sh_event_outbox (pessimistic lock)
 *   2. Wyciągnij dane kontaktowe klienta z payload (customer_phone, customer_email)
 *   3. Znajdź aktywne routes dla event_type (sh_notification_routes ORDER BY fallback_order)
 *   4. Sprawdź consent (sms_consent / marketing_consent z payload orderu)
 *   5. Renderuj szablon (TemplateRenderer) per channel_type
 *   6. Wywołaj ChannelInterface::send()
 *   7. Zaloguj do sh_notification_deliveries
 *   8. Jeśli sukces → done. Jeśli fail → retry z backoffem (jak WebhookDispatcher)
 *   9. Upsert sh_customer_contacts (mini-CRM)
 *
 * Uruchamiany przez: scripts/worker_notifications.php (cron co minutę lub long-polling).
 */
final class NotificationDispatcher
{
    private const MAX_ATTEMPTS   = 4;
    private const BACKOFF_SCHED  = [0, 60, 300, 900]; // sekundy po każdej próbie
    private const BATCH_SIZE     = 20;

    /** Eventy które mogą generować powiadomienia klientów */
    private const NOTIFICATION_EVENTS = [
        'order.created',
        'order.accepted',
        'order.preparing',
        'order.ready',
        'order.dispatched',
        'order.in_delivery',
        'order.delivered',
        'order.completed',
        'order.cancelled',
        'marketing.campaign',
    ];

    private \PDO $pdo;
    private string $baseUrl;
    private bool $debug;

    public function __construct(\PDO $pdo, string $baseUrl = '', bool $debug = false)
    {
        $this->pdo     = $pdo;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->debug   = $debug;

        require_once __DIR__ . '/DeliveryResult.php';
        require_once __DIR__ . '/ChannelInterface.php';
        require_once __DIR__ . '/ChannelRegistry.php';
        require_once __DIR__ . '/TemplateRenderer.php';
    }

    /**
     * Główna pętla — przetwarza jeden batch eventów.
     * Wywoływana przez workera w pętli.
     *
     * @return int Liczba przetworzonych eventów.
     */
    public function processBatch(): int
    {
        $eventTypes = implode(',', array_fill(0, count(self::NOTIFICATION_EVENTS), '?'));

        // Claim: bez FOR UPDATE SKIP LOCKED (wymaga MariaDB 10.6+ / MySQL 8+).
        // Ten sam wzorzec co WebhookDispatcher::claimPendingEvents — działa na MariaDB 10.4.
        $stmt = $this->pdo->prepare(
            "SELECT id, tenant_id, event_type, aggregate_id, payload, attempts
             FROM sh_event_outbox
             WHERE status = 'pending'
               AND event_type IN ({$eventTypes})
               AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
             ORDER BY id ASC
             LIMIT " . self::BATCH_SIZE
        );
        $stmt->execute(self::NOTIFICATION_EVENTS);
        $candidates = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($candidates)) {
            return 0;
        }

        $upd = $this->pdo->prepare(
            "UPDATE sh_event_outbox SET status = 'dispatching' WHERE id = :id AND status = 'pending'"
        );

        $events = [];
        foreach ($candidates as $row) {
            $upd->execute([':id' => $row['id']]);
            if ($upd->rowCount() === 1) {
                $events[] = $row;
            }
        }

        if (empty($events)) {
            return 0;
        }

        foreach ($events as $event) {
            $this->processEvent($event);
        }

        return count($events);
    }

    // ─── Event processing ────────────────────────────────────────────────────

    private function processEvent(array $event): void
    {
        $eventId   = (int)$event['id'];
        $tenantId  = (int)$event['tenant_id'];
        $eventType = (string)$event['event_type'];
        $orderId   = (string)$event['aggregate_id'];
        $attempts  = (int)$event['attempts'];

        try {
            $payload = json_decode((string)$event['payload'], true) ?? [];
        } catch (\Throwable $e) {
            $this->markOutboxFailed($eventId, 'Invalid payload JSON: ' . $e->getMessage(), $attempts);
            return;
        }

        // Wyciągnij dane kontaktowe z payload
        $customerPhone = (string)($payload['customer_phone'] ?? '');
        $customerEmail = (string)($payload['customer_email'] ?? '');
        $smsConsent    = !empty($payload['sms_consent']);
        $mktConsent    = !empty($payload['marketing_consent']);

        // Pobierz aktywne routes dla tego event_type i tenanta
        $routes = $this->getRoutes($tenantId, $eventType);
        if (empty($routes)) {
            // Brak routingu — oznacz jako delivered (nie ma co wysyłać)
            $this->markOutboxDone($eventId);
            return;
        }

        // Pobierz kontekst do szablonu
        $templateCtx = $this->buildTemplateContext($payload, $tenantId, $orderId, $eventType);

        $anySuccess = false;
        $allErrors  = [];

        foreach ($routes as $route) {
            $channel = $this->getChannel((int)$route['channel_id']);
            if (!$channel) {
                $allErrors[] = "Channel {$route['channel_id']} not found";
                continue;
            }

            // Sprawdź consent
            if ($route['requires_sms_consent'] && !$smsConsent) {
                $this->debugLog("Skipping route {$route['id']} — no sms_consent");
                continue;
            }
            if ($route['requires_marketing_consent'] && !$mktConsent) {
                $this->debugLog("Skipping route {$route['id']} — no marketing_consent");
                continue;
            }

            // Wybierz recipient
            $channelType = (string)$channel['channel_type'];
            $recipient   = $this->resolveRecipient($channelType, $customerPhone, $customerEmail);
            if ($recipient === '') {
                $this->debugLog("Skipping route {$route['id']} — no recipient for channel_type={$channelType}");
                continue;
            }

            // Renderuj szablon
            [$subject, $body] = $this->renderTemplate($tenantId, $eventType, $channelType, $templateCtx);
            if ($body === '') {
                $this->debugLog("Skipping route {$route['id']} — no template for event={$eventType} channel={$channelType}");
                continue;
            }

            // Sprawdź rate limit kanału
            if (!$this->checkRateLimit($channel)) {
                $allErrors[] = "Channel {$route['channel_id']} rate limited";
                continue;
            }

            // Pobierz implementację kanału
            $channelImpl = ChannelRegistry::get($channelType);
            if (!$channelImpl) {
                $allErrors[] = "No implementation for channel_type={$channelType}";
                continue;
            }

            $credentials = json_decode((string)($channel['credentials_json'] ?? '{}'), true) ?? [];
            $channelConfig = array_merge($channel, ['credentials' => $credentials]);

            // Wyślij
            try {
                $result = $channelImpl->send($recipient, $subject, $body, $channelConfig, [
                    'event_id'       => $eventId,
                    'event_type'     => $eventType,
                    'order_id'       => $orderId,
                    'tenant_id'      => $tenantId,
                    'tracking_token' => $payload['tracking_token'] ?? null,
                ]);
            } catch (\Throwable $e) {
                $result = DeliveryResult::fail($e->getMessage());
            }

            $this->logDelivery($eventId, (int)$route['channel_id'], $eventType, $tenantId, $recipient, $result);

            if ($result->success) {
                $anySuccess = true;
                // Resetuj consecutive_failures na kanale
                $this->resetChannelFailures((int)$route['channel_id']);
                // Increment rate-limit bucket
                $this->incrementRateBucket(array_merge($channel, ['tenant_id' => $tenantId]));

                // Jeśli primary sukces i nie chcemy dalszych fallbacków → break
                if ((int)$route['fallback_order'] === 0) {
                    break;
                }
            } else {
                $allErrors[] = "Channel {$channelType}: " . $result->errorMessage;
                $this->incrementChannelFailures((int)$route['channel_id']);
            }
        }

        // Upsert customer_contacts (CRM)
        if ($customerPhone !== '' && in_array($eventType, ['order.created', 'order.accepted'], true)) {
            $this->upsertContact($tenantId, $customerPhone, $payload);
        }

        if ($anySuccess) {
            $this->markOutboxDone($eventId);
        } else {
            $nextAttempts = $attempts + 1;
            if ($nextAttempts >= self::MAX_ATTEMPTS) {
                $this->markOutboxFailed($eventId, implode('; ', $allErrors), $attempts, dead: true);
            } else {
                $delaySec = self::BACKOFF_SCHED[$nextAttempts] ?? 900;
                $this->markOutboxRetry($eventId, implode('; ', $allErrors), $nextAttempts, $delaySec);
            }
        }
    }

    // ─── DB helpers ──────────────────────────────────────────────────────────

    private function getRoutes(int $tenantId, string $eventType): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT r.id, r.channel_id, r.fallback_order,
                        r.requires_sms_consent, r.requires_marketing_consent,
                        c.channel_type, c.provider, c.credentials_json,
                        c.is_active, c.priority, c.rate_limit_per_hour, c.rate_limit_per_day,
                        c.paused_until, c.consecutive_failures
                 FROM sh_notification_routes r
                 JOIN sh_notification_channels c ON c.id = r.channel_id
                 WHERE r.tenant_id = :tid
                   AND r.event_type = :et
                   AND r.is_active = 1
                   AND c.is_active = 1
                   AND (c.paused_until IS NULL OR c.paused_until < NOW())
                 ORDER BY r.fallback_order ASC, c.priority ASC"
            );
            $stmt->execute([':tid' => $tenantId, ':et' => $eventType]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log("[NotificationDispatcher::getRoutes] {$e->getMessage()}");
            return [];
        }
    }

    private function getChannel(int $channelId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM sh_notification_channels WHERE id = :id"
            );
            $stmt->execute([':id' => $channelId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function renderTemplate(int $tenantId, string $eventType, string $channelType, array $ctx): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT subject, body FROM sh_notification_templates
                 WHERE tenant_id = :tid AND event_type = :et AND channel_type = :ct AND is_active = 1
                 ORDER BY lang = 'pl' DESC LIMIT 1"
            );
            $stmt->execute([':tid' => $tenantId, ':et' => $eventType, ':ct' => $channelType]);
            $tpl = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$tpl || $tpl['body'] === '') {
                return ['', ''];
            }

            $htmlSafe = ($channelType === 'email');
            $subject  = TemplateRenderer::render((string)($tpl['subject'] ?? ''), $ctx, $htmlSafe);
            $body     = TemplateRenderer::render((string)$tpl['body'], $ctx, $htmlSafe);

            return [$subject, $body];
        } catch (\Throwable $e) {
            error_log("[NotificationDispatcher::renderTemplate] {$e->getMessage()}");
            return ['', ''];
        }
    }

    private function buildTemplateContext(array $payload, int $tenantId, string $orderId, string $eventType): array
    {
        // Pobierz dane tenanta (storefront settings)
        $storeName  = '';
        $storePhone = '';
        $storeEmail = '';
        try {
            $stmtT = $this->pdo->prepare(
                "SELECT setting_key, setting_value FROM sh_tenant_settings
                 WHERE tenant_id = :tid AND setting_key IN ('storefront_name','storefront_phone','storefront_email','storefront_address')"
            );
            $stmtT->execute([':tid' => $tenantId]);
            $settings = [];
            while ($row = $stmtT->fetch(\PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            $storeName  = $settings['storefront_name']    ?? ($payload['tenant_name'] ?? '');
            $storePhone = $settings['storefront_phone']   ?? '';
            $storeEmail = $settings['storefront_email']   ?? '';
            $storeAddr  = $settings['storefront_address'] ?? '';
        } catch (\Throwable $e) {
            $storeAddr = '';
        }

        // ETA w sekundach (z promised_time)
        $etaSeconds = null;
        if (!empty($payload['promised_time'])) {
            try {
                $promised = new \DateTime((string)$payload['promised_time']);
                $etaSeconds = max(0, $promised->getTimestamp() - time());
            } catch (\Throwable $e) {}
        }

        // Tracking URL
        $trackingToken = $payload['tracking_token'] ?? null;
        $phone         = $payload['customer_phone']  ?? '';
        $trackingUrl   = '';
        if ($trackingToken && $phone) {
            $tenantParam = urlencode((string)$tenantId);
            $trackingUrl = $this->baseUrl
                . "/modules/online/track.html?tenant={$tenantParam}&token="
                . urlencode($trackingToken) . '&phone=' . urlencode($phone);
        }

        return [
            'customer_name'    => $payload['customer_name']    ?? '',
            'order_number'     => $payload['order_number']     ?? $orderId,
            'order_type'       => $payload['order_type']       ?? '',
            'eta_seconds'      => $etaSeconds,
            'tracking_url'     => $trackingUrl,
            'store_name'       => $storeName,
            'store_phone'      => $storePhone,
            'store_email'      => $storeEmail,
            'store_address'    => $storeAddr ?? '',
            'total_grosze'     => $payload['grand_total']      ?? 0,
            'payment_method'   => $payload['payment_method']   ?? '',
            'delivery_address' => $payload['delivery_address'] ?? '',
            'promised_time'    => $payload['promised_time']    ?? '',
            'channel'          => $payload['channel']          ?? '',
            'status'           => $payload['status']           ?? '',
            'delivery_status'  => $payload['delivery_status']  ?? '',
        ];
    }

    private function resolveRecipient(string $channelType, string $phone, string $email): string
    {
        return match ($channelType) {
            'email'          => $email,
            'personal_phone',
            'sms_gateway'    => $phone,
            'in_app'         => '', // InApp używa tracking_token z ctx
            default          => '',
        };
    }

    /**
     * Token-bucket rate limit check using sh_rate_limit_buckets (fast O(1) lookup).
     * Falls back to deliveries count if buckets table unavailable.
     */
    private function checkRateLimit(array $channel): bool
    {
        $limitHour = $channel['rate_limit_per_hour'] ?? null;
        $limitDay  = $channel['rate_limit_per_day']  ?? null;
        if ($limitHour === null && $limitDay === null) {
            return true;
        }

        $cid = (int)$channel['id'];
        $tid = (int)$channel['tenant_id'];

        try {
            if ($limitHour !== null) {
                $windowStart = date('Y-m-d H:i:00', (int)(time() / 3600) * 3600); // trunc to hour
                $this->pdo->prepare(
                    "INSERT INTO sh_rate_limit_buckets (tenant_id, channel_id, window_type, window_start, tokens_used)
                     VALUES (:tid, :cid, 'hour', :ws, 0)
                     ON DUPLICATE KEY UPDATE window_start = window_start"
                )->execute([':tid' => $tid, ':cid' => $cid, ':ws' => $windowStart]);

                $stmt = $this->pdo->prepare(
                    "SELECT tokens_used FROM sh_rate_limit_buckets
                     WHERE tenant_id=:tid AND channel_id=:cid AND window_type='hour' AND window_start=:ws"
                );
                $stmt->execute([':tid' => $tid, ':cid' => $cid, ':ws' => $windowStart]);
                if ((int)$stmt->fetchColumn() >= (int)$limitHour) {
                    return false;
                }
            }

            if ($limitDay !== null) {
                $windowStart = date('Y-m-d 00:00:00');
                $this->pdo->prepare(
                    "INSERT INTO sh_rate_limit_buckets (tenant_id, channel_id, window_type, window_start, tokens_used)
                     VALUES (:tid, :cid, 'day', :ws, 0)
                     ON DUPLICATE KEY UPDATE window_start = window_start"
                )->execute([':tid' => $tid, ':cid' => $cid, ':ws' => $windowStart]);

                $stmt = $this->pdo->prepare(
                    "SELECT tokens_used FROM sh_rate_limit_buckets
                     WHERE tenant_id=:tid AND channel_id=:cid AND window_type='day' AND window_start=:ws"
                );
                $stmt->execute([':tid' => $tid, ':cid' => $cid, ':ws' => $windowStart]);
                if ((int)$stmt->fetchColumn() >= (int)$limitDay) {
                    return false;
                }
            }
        } catch (\Throwable $e) {
            // Graceful fallback to deliveries count if buckets table not yet migrated
            error_log("[NotificationDispatcher::checkRateLimit] bucket table unavailable, falling back: {$e->getMessage()}");
            return $this->checkRateLimitFallback($channel);
        }
        return true;
    }

    /** Increment rate-limit bucket after successful send. */
    private function incrementRateBucket(array $channel): void
    {
        $cid = (int)$channel['id'];
        $tid = (int)$channel['tenant_id'];
        try {
            foreach (['hour' => date('Y-m-d H:i:00', (int)(time()/3600)*3600), 'day' => date('Y-m-d 00:00:00')] as $wt => $ws) {
                $this->pdo->prepare(
                    "UPDATE sh_rate_limit_buckets SET tokens_used = tokens_used + 1
                     WHERE tenant_id=:tid AND channel_id=:cid AND window_type=:wt AND window_start=:ws"
                )->execute([':tid' => $tid, ':cid' => $cid, ':wt' => $wt, ':ws' => $ws]);
            }
        } catch (\Throwable $e) { /* non-critical */ }
    }

    private function checkRateLimitFallback(array $channel): bool
    {
        $cid = (int)$channel['id'];
        $limitHour = $channel['rate_limit_per_hour'] ?? null;
        $limitDay  = $channel['rate_limit_per_day']  ?? null;
        if ($limitHour !== null) {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM sh_notification_deliveries
                 WHERE channel_id = :cid AND status = 'sent' AND attempted_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
            $stmt->execute([':cid' => $cid]);
            if ((int)$stmt->fetchColumn() >= (int)$limitHour) return false;
        }
        if ($limitDay !== null) {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM sh_notification_deliveries
                 WHERE channel_id = :cid AND status = 'sent' AND attempted_at >= CURDATE()"
            );
            $stmt->execute([':cid' => $cid]);
            if ((int)$stmt->fetchColumn() >= (int)$limitDay) return false;
        }
        return true;
    }

    private function logDelivery(
        int $eventId, int $channelId, string $eventType, int $tenantId,
        string $recipient, DeliveryResult $result
    ): void {
        try {
            // Hash recipient dla audytu (nie przechowujemy raw PII jeśli failed)
            $recipientHash = hash('sha256', $recipient);
            // Dla sukcesu: zachowaj recipient. Dla fail: zamazuj
            $recipientStore = $result->success ? $recipient : 'REDACTED';

            $this->pdo->prepare(
                "INSERT INTO sh_notification_deliveries
                    (tenant_id, event_id, channel_id, event_type, recipient, recipient_hash,
                     status, attempt_number, provider_message_id, error_message, cost_grosze, attempted_at)
                 VALUES (:tid, :eid, :cid, :et, :rec, :rhash,
                         :status, 1, :mid, :err, :cost, NOW())"
            )->execute([
                ':tid'    => $tenantId,
                ':eid'    => $eventId,
                ':cid'    => $channelId,
                ':et'     => $eventType,
                ':rec'    => $recipientStore,
                ':rhash'  => $recipientHash,
                ':status' => $result->success ? 'sent' : 'failed',
                ':mid'    => $result->messageId,
                ':err'    => $result->errorMessage,
                ':cost'   => $result->costGrosze,
            ]);
        } catch (\Throwable $e) {
            error_log("[NotificationDispatcher::logDelivery] {$e->getMessage()}");
        }
    }

    private function upsertContact(int $tenantId, string $phone, array $payload): void
    {
        try {
            require_once __DIR__ . '/../CustomerContactRepository.php';
            CustomerContactRepository::upsert($this->pdo, $tenantId, $phone, [
                'name'              => $payload['customer_name']    ?? null,
                'email'             => $payload['customer_email']   ?? null,
                'sms_consent'       => !empty($payload['sms_consent']),
                'marketing_consent' => !empty($payload['marketing_consent']),
            ]);
        } catch (\Throwable $e) {
            error_log("[NotificationDispatcher::upsertContact] {$e->getMessage()}");
        }
    }

    private function incrementChannelFailures(int $channelId): void
    {
        try {
            $this->pdo->prepare(
                "UPDATE sh_notification_channels
                 SET consecutive_failures = consecutive_failures + 1,
                     paused_until = CASE WHEN consecutive_failures + 1 >= 5
                                         THEN DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                                         ELSE paused_until END
                 WHERE id = :id"
            )->execute([':id' => $channelId]);
        } catch (\Throwable $e) {}
    }

    private function resetChannelFailures(int $channelId): void
    {
        try {
            $this->pdo->prepare(
                "UPDATE sh_notification_channels SET consecutive_failures = 0, paused_until = NULL WHERE id = :id"
            )->execute([':id' => $channelId]);
        } catch (\Throwable $e) {}
    }

    private function markOutboxDone(int $eventId): void
    {
        try {
            $this->pdo->prepare(
                "UPDATE sh_event_outbox SET status = 'delivered', completed_at = NOW(), dispatched_at = NOW()
                 WHERE id = :id"
            )->execute([':id' => $eventId]);
        } catch (\Throwable $e) {
            error_log("[NotificationDispatcher::markOutboxDone] {$e->getMessage()}");
        }
    }

    private function markOutboxRetry(int $eventId, string $error, int $attempts, int $delaySec): void
    {
        try {
            $this->pdo->prepare(
                "UPDATE sh_event_outbox
                 SET status = 'pending', attempts = :att, last_error = :err,
                     next_attempt_at = DATE_ADD(NOW(), INTERVAL :delay SECOND)
                 WHERE id = :id"
            )->execute([':att' => $attempts, ':err' => substr($error, 0, 500), ':delay' => $delaySec, ':id' => $eventId]);
        } catch (\Throwable $e) {
            error_log("[NotificationDispatcher::markOutboxRetry] {$e->getMessage()}");
        }
    }

    private function markOutboxFailed(int $eventId, string $error, int $attempts, bool $dead = false): void
    {
        try {
            $status = $dead ? 'dead' : 'failed';
            $this->pdo->prepare(
                "UPDATE sh_event_outbox
                 SET status = :st, attempts = :att, last_error = :err, completed_at = NOW()
                 WHERE id = :id"
            )->execute([':st' => $status, ':att' => $attempts + 1, ':err' => substr($error, 0, 500), ':id' => $eventId]);
        } catch (\Throwable $e) {
            error_log("[NotificationDispatcher::markOutboxFailed] {$e->getMessage()}");
        }
    }

    private function debugLog(string $msg): void
    {
        if ($this->debug) {
            error_log("[NotificationDispatcher] {$msg}");
        }
    }
}
