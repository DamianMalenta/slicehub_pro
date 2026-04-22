<?php

declare(strict_types=1);

/**
 * WebhookDispatcher — konsument sh_event_outbox (m026).
 *
 * Zadania:
 *   1. Claim pending events (atomic UPDATE → status='dispatching')
 *   2. Dla każdego eventu: znajdź matching webhook endpoints tenanta
 *   3. Wyślij podpisany payload (HMAC-SHA256)
 *   4. Zaloguj próbę do sh_webhook_deliveries
 *   5. Sukces → status='delivered'; porażka → exponential backoff lub 'dead'
 *   6. Auto-disable endpoint po N consecutive_failures (>= max_retries)
 *
 * Gwarancje:
 *   • AT LEAST ONCE — każdy subscriber dostanie event (modulo DLQ po exhausted retries)
 *   • FIFO per aggregate_id (order) — claimujemy po `created_at ASC`
 *   • Atomic claim (race-safe przy N workerach) — `UPDATE … WHERE id=? AND status='pending'` + sprawdzenie rowCount
 *   • Izolacja — 1 failed endpoint nie blokuje eventu dla innych subscribentów
 *
 * Sygnatura webhooka (caller musi weryfikować):
 *   X-Slicehub-Signature: t=<unix_ts>,v1=<hex_hmac>
 *   hmac = HMAC-SHA256(secret, "{t}.{body}")
 *
 * Format ciała POSTa:
 *   {
 *     "event_id":       "123",
 *     "event_type":     "order.created",
 *     "aggregate_id":   "uuid",
 *     "aggregate_type": "order",
 *     "tenant_id":      1,
 *     "payload":        { ... snapshot ... },
 *     "occurred_at":    "2026-04-18T14:23:11Z",
 *     "attempt":        2,
 *     "delivery_id":    42
 *   }
 */
final class WebhookDispatcher
{
    /** Exponential backoff schedule (seconds). Index = attempt number (0-based). */
    private const BACKOFF_SCHEDULE = [
        0 => 30,       // 1st retry: 30s
        1 => 120,      // 2nd retry: 2min
        2 => 600,      // 3rd retry: 10min
        3 => 1800,     // 4th retry: 30min
        4 => 7200,     // 5th retry: 2h
        5 => 21600,    // 6th retry: 6h
        6 => 86400,    // 7th retry: 24h
    ];

    /** Absolutne max. prób (poza tym → dead). Override'owane per-endpoint przez `max_retries`. */
    private const MAX_ATTEMPTS_DEFAULT = 6;

    /** Response body limit do logowania (bajty). */
    private const RESPONSE_LOG_LIMIT = 2000;

    /** Error message limit do logowania. */
    private const ERROR_LOG_LIMIT = 1000;

    /**
     * Czy sh_event_outbox istnieje (feature detect, per-proces cache).
     */
    private static ?bool $outboxAvailable = null;

    /**
     * @var callable(string $method, string $url, array $headers, string $body, int $timeout): array
     *     Wstrzykiwalny HTTP transport dla testów.
     */
    private $httpTransport;

    public function __construct(
        private readonly \PDO $pdo,
        ?callable $httpTransport = null,
        private readonly int $batchSize = 50,
        private readonly bool $verbose = false
    ) {
        $this->httpTransport = $httpTransport ?? [self::class, 'curlTransport'];
    }

    /**
     * Jeden przebieg workera — pull batch, dispatch, return statystyki.
     *
     * @return array{processed: int, delivered: int, failed: int, dead: int, no_subscribers: int}
     */
    public function runBatch(): array
    {
        if (!$this->isOutboxAvailable()) {
            $this->log('outbox tables not present — nothing to dispatch');
            return ['processed' => 0, 'delivered' => 0, 'failed' => 0, 'dead' => 0, 'no_subscribers' => 0];
        }

        $stats = ['processed' => 0, 'delivered' => 0, 'failed' => 0, 'dead' => 0, 'no_subscribers' => 0];

        $events = $this->claimPendingEvents($this->batchSize);
        if (empty($events)) {
            return $stats;
        }

        foreach ($events as $event) {
            $result = $this->processEvent($event);
            $stats['processed']++;
            $stats[$result]++;
        }

        return $stats;
    }

    /**
     * Claim batch pending events — atomic UPDATE na każdy.
     *
     * @return list<array>
     */
    private function claimPendingEvents(int $limit): array
    {
        $sel = $this->pdo->prepare(
            "SELECT id, tenant_id, event_type, aggregate_type, aggregate_id,
                    payload, source, actor_type, actor_id,
                    attempts, created_at
             FROM sh_event_outbox
             WHERE status = 'pending'
               AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
             ORDER BY id ASC
             LIMIT {$limit}"
        );
        $sel->execute();
        $candidates = $sel->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($candidates)) {
            return [];
        }

        $upd = $this->pdo->prepare(
            "UPDATE sh_event_outbox
             SET status = 'dispatching', dispatched_at = NOW()
             WHERE id = :id AND status = 'pending'"
        );

        $claimed = [];
        foreach ($candidates as $row) {
            $upd->execute([':id' => $row['id']]);
            // Jeśli inny worker już przejął → rowCount = 0, pomijamy.
            if ($upd->rowCount() === 1) {
                $claimed[] = $row;
            }
        }

        $this->log(sprintf('claimed %d of %d candidates', count($claimed), count($candidates)));
        return $claimed;
    }

    /**
     * Przetwarzanie pojedynczego eventu.
     *
     * @return 'delivered'|'failed'|'dead'|'no_subscribers'
     */
    private function processEvent(array $event): string
    {
        $eventId     = (int)$event['id'];
        $tenantId    = (int)$event['tenant_id'];
        $eventType   = (string)$event['event_type'];
        $attempts    = (int)$event['attempts']; // liczba PRZEPROWADZONYCH prób (0 = first try)

        $subscribers = $this->findSubscribers($tenantId, $eventType);

        if (empty($subscribers)) {
            // Brak subskrybentów — oznacz jako delivered (nic do zrobienia).
            $this->markDelivered($eventId);
            $this->log(sprintf('event %d (%s) → no subscribers, marked delivered', $eventId, $eventType));
            return 'no_subscribers';
        }

        $envelope = $this->buildEnvelope($event, $attempts + 1);

        $allDelivered = true;
        $anyTransientFailure = false;
        $lastError = null;

        foreach ($subscribers as $sub) {
            $outcome = $this->deliverToSubscriber($event, $sub, $envelope, $attempts + 1);
            if (!$outcome['ok']) {
                $allDelivered = false;
                $lastError = $outcome['error'] ?? 'unknown';
                if ($outcome['transient'] ?? false) {
                    $anyTransientFailure = true;
                }
            }
        }

        if ($allDelivered) {
            $this->markDelivered($eventId);
            return 'delivered';
        }

        $newAttempts = $attempts + 1;
        $maxAttempts = self::MAX_ATTEMPTS_DEFAULT;

        if (!$anyTransientFailure || $newAttempts >= $maxAttempts) {
            // Permanent errors OR attempts exhausted → dead letter.
            $this->markDead($eventId, $newAttempts, $lastError);
            return 'dead';
        }

        $backoffSec = self::BACKOFF_SCHEDULE[$attempts] ?? end(self::BACKOFF_SCHEDULE);
        $this->markFailed($eventId, $newAttempts, $backoffSec, $lastError);
        return 'failed';
    }

    /**
     * Znajdź active webhooków tenanta zasubskrybowanych pod event_type.
     * Zwraca także tych z `events_subscribed` = ["*"].
     *
     * @return list<array>
     */
    private function findSubscribers(int $tenantId, string $eventType): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, name, url, secret, events_subscribed,
                        max_retries, timeout_seconds, consecutive_failures
                 FROM sh_webhook_endpoints
                 WHERE tenant_id = :tid AND is_active = 1"
            );
            $stmt->execute([':tid' => $tenantId]);
        } catch (\Throwable $e) {
            // Tabela sh_webhook_endpoints nieobecna — treat jako „brak subskrybentów".
            return [];
        }

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $matched = [];

        foreach ($rows as $row) {
            $subscribed = json_decode((string)$row['events_subscribed'], true);
            if (!is_array($subscribed)) continue;

            if (in_array('*', $subscribed, true) || in_array($eventType, $subscribed, true)) {
                $matched[] = $row;
            }
        }

        return $matched;
    }

    /**
     * Zbuduj envelope JSON wysyłany do każdego subscribera.
     */
    private function buildEnvelope(array $event, int $attempt): array
    {
        $payloadDecoded = json_decode((string)$event['payload'], true);
        if (!is_array($payloadDecoded)) $payloadDecoded = [];

        return [
            'event_id'       => (string)$event['id'],
            'event_type'     => (string)$event['event_type'],
            'aggregate_id'   => (string)$event['aggregate_id'],
            'aggregate_type' => (string)$event['aggregate_type'],
            'tenant_id'      => (int)$event['tenant_id'],
            'source'         => (string)$event['source'],
            'actor_type'     => $event['actor_type'] !== null ? (string)$event['actor_type'] : null,
            'actor_id'       => $event['actor_id']   !== null ? (string)$event['actor_id']   : null,
            'occurred_at'    => $this->toIso8601((string)$event['created_at']),
            'attempt'        => $attempt,
            'payload'        => $payloadDecoded,
        ];
    }

    /**
     * Wyślij event do jednego endpointu. Zaloguj do sh_webhook_deliveries.
     *
     * @return array{ok: bool, transient?: bool, error?: string, httpCode?: int}
     */
    private function deliverToSubscriber(array $event, array $subscriber, array $envelope, int $attemptNumber): array
    {
        $endpointId = (int)$subscriber['id'];
        $url        = (string)$subscriber['url'];
        $secret     = (string)$subscriber['secret'];
        $timeout    = max(1, (int)$subscriber['timeout_seconds']);

        // Transparent decrypt (m029/7.5) — secret w DB może być zaszyfrowany vault:v1:...
        if (class_exists('CredentialVault') && CredentialVault::isEncrypted($secret)) {
            $decrypted = CredentialVault::decrypt($secret);
            if ($decrypted === null) {
                error_log(sprintf(
                    '[WebhookDispatcher] secret decrypt failed for endpoint #%d — check SLICEHUB_VAULT_KEY',
                    $endpointId
                ));
                return ['ok' => false, 'transient' => false, 'error' => 'vault decrypt failed'];
            }
            $secret = $decrypted;
        }

        // Ustaw delivery_id w envelope PO stworzeniu rekordu audytu (FK).
        $deliveryId = $this->beginDelivery((int)$event['id'], $endpointId, $attemptNumber);
        $envelope['delivery_id'] = $deliveryId;

        $body = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            $this->finishDelivery($deliveryId, null, null, 'json_encode failed', 0);
            return ['ok' => false, 'transient' => false, 'error' => 'json_encode failed'];
        }

        $timestamp = time();
        $signingBase = $timestamp . '.' . $body;
        $signature = hash_hmac('sha256', $signingBase, $secret);

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'User-Agent: SliceHub-Webhooks/1.0',
            "X-Slicehub-Event: {$envelope['event_type']}",
            "X-Slicehub-Delivery: {$deliveryId}",
            "X-Slicehub-Signature: t={$timestamp},v1={$signature}",
            'X-Slicehub-Attempt: ' . $attemptNumber,
        ];

        $startedAt = microtime(true);

        try {
            $response = ($this->httpTransport)('POST', $url, $headers, $body, $timeout);
        } catch (\Throwable $e) {
            $duration = (int)((microtime(true) - $startedAt) * 1000);
            $errMsg = 'transport_exception: ' . $e->getMessage();
            $this->finishDelivery($deliveryId, null, null, $errMsg, $duration);
            $this->bumpEndpointFailure($endpointId);
            return ['ok' => false, 'transient' => true, 'error' => $errMsg];
        }

        $duration = (int)((microtime(true) - $startedAt) * 1000);
        $httpCode = (int)($response['code'] ?? 0);
        $respBody = substr((string)($response['body'] ?? ''), 0, self::RESPONSE_LOG_LIMIT);
        $transportError = $response['error'] ?? null;

        if ($transportError) {
            $this->finishDelivery($deliveryId, $httpCode ?: null, $respBody ?: null, substr((string)$transportError, 0, self::ERROR_LOG_LIMIT), $duration);
            $this->bumpEndpointFailure($endpointId);
            return ['ok' => false, 'transient' => true, 'error' => 'transport: ' . $transportError];
        }

        // 2xx → sukces. 4xx inne niż 408/429 → permanent. 5xx/408/429 → transient.
        if ($httpCode >= 200 && $httpCode < 300) {
            $this->finishDelivery($deliveryId, $httpCode, $respBody, null, $duration);
            $this->markEndpointSuccess($endpointId);
            return ['ok' => true, 'httpCode' => $httpCode];
        }

        $isTransient = ($httpCode >= 500) || $httpCode === 408 || $httpCode === 429 || $httpCode === 0;
        $errMsg = sprintf('http %d: %s', $httpCode, substr($respBody, 0, 200));
        $this->finishDelivery($deliveryId, $httpCode, $respBody, substr($errMsg, 0, self::ERROR_LOG_LIMIT), $duration);
        $this->bumpEndpointFailure($endpointId);

        return ['ok' => false, 'transient' => $isTransient, 'error' => $errMsg, 'httpCode' => $httpCode];
    }

    // ── Delivery log ────────────────────────────────────────────────────────

    private function beginDelivery(int $eventId, int $endpointId, int $attempt): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO sh_webhook_deliveries
                (event_id, endpoint_id, attempt_number, attempted_at)
             VALUES (:eid, :epid, :att, NOW())"
        );
        $stmt->execute([':eid' => $eventId, ':epid' => $endpointId, ':att' => $attempt]);
        return (int)$this->pdo->lastInsertId();
    }

    private function finishDelivery(int $deliveryId, ?int $httpCode, ?string $body, ?string $error, int $durationMs): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sh_webhook_deliveries
             SET http_code = :hc, response_body = :rb, error_message = :em, duration_ms = :dur
             WHERE id = :id"
        );
        $stmt->execute([
            ':hc'  => $httpCode,
            ':rb'  => $body,
            ':em'  => $error,
            ':dur' => $durationMs,
            ':id'  => $deliveryId,
        ]);
    }

    // ── Outbox state transitions ────────────────────────────────────────────

    private function markDelivered(int $eventId): void
    {
        $this->pdo->prepare(
            "UPDATE sh_event_outbox
             SET status = 'delivered', completed_at = NOW(), last_error = NULL
             WHERE id = :id"
        )->execute([':id' => $eventId]);
    }

    private function markFailed(int $eventId, int $attempts, int $backoffSec, ?string $lastError): void
    {
        $this->pdo->prepare(
            "UPDATE sh_event_outbox
             SET status = 'pending',
                 attempts = :att,
                 next_attempt_at = DATE_ADD(NOW(), INTERVAL :bo SECOND),
                 last_error = :err,
                 dispatched_at = NULL
             WHERE id = :id"
        )->execute([
            ':att' => $attempts,
            ':bo'  => $backoffSec,
            ':err' => $lastError !== null ? substr($lastError, 0, self::ERROR_LOG_LIMIT) : null,
            ':id'  => $eventId,
        ]);
    }

    private function markDead(int $eventId, int $attempts, ?string $lastError): void
    {
        $this->pdo->prepare(
            "UPDATE sh_event_outbox
             SET status = 'dead',
                 attempts = :att,
                 completed_at = NOW(),
                 last_error = :err
             WHERE id = :id"
        )->execute([
            ':att' => $attempts,
            ':err' => $lastError !== null ? substr($lastError, 0, self::ERROR_LOG_LIMIT) : 'exhausted retries',
            ':id'  => $eventId,
        ]);
    }

    // ── Endpoint health ─────────────────────────────────────────────────────

    private function markEndpointSuccess(int $endpointId): void
    {
        $this->pdo->prepare(
            "UPDATE sh_webhook_endpoints
             SET last_success_at = NOW(), consecutive_failures = 0
             WHERE id = :id"
        )->execute([':id' => $endpointId]);
    }

    /**
     * Increment consecutive_failures. Gdy >= max_retries → auto-pause (is_active=0).
     */
    private function bumpEndpointFailure(int $endpointId): void
    {
        $this->pdo->prepare(
            "UPDATE sh_webhook_endpoints
             SET last_failure_at = NOW(),
                 consecutive_failures = consecutive_failures + 1,
                 is_active = CASE
                     WHEN consecutive_failures + 1 >= max_retries THEN 0
                     ELSE is_active
                 END
             WHERE id = :id"
        )->execute([':id' => $endpointId]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function isOutboxAvailable(): bool
    {
        if (self::$outboxAvailable !== null) return self::$outboxAvailable;
        try {
            $this->pdo->query("SELECT 1 FROM sh_event_outbox LIMIT 0");
            self::$outboxAvailable = true;
        } catch (\Throwable $e) {
            self::$outboxAvailable = false;
        }
        return self::$outboxAvailable;
    }

    private function toIso8601(string $mysqlDt): string
    {
        try {
            $dt = new \DateTimeImmutable($mysqlDt, new \DateTimeZone('UTC'));
            return $dt->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable $e) {
            return $mysqlDt;
        }
    }

    private function log(string $msg): void
    {
        if (!$this->verbose) return;
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] WebhookDispatcher: ' . $msg . PHP_EOL);
    }

    /**
     * Domyślny transport — cURL.
     *
     * @return array{code: int, body: string, error?: ?string}
     */
    public static function curlTransport(string $method, string $url, array $headers, string $body, int $timeout): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 3),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error        = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'code'  => $httpCode,
            'body'  => is_string($responseBody) ? $responseBody : '',
            'error' => $error,
        ];
    }

    /** @internal wyłącznie do testów */
    public static function resetCache(): void
    {
        self::$outboxAvailable = null;
    }
}
