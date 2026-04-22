<?php

declare(strict_types=1);

namespace SliceHub\Integrations;

/**
 * IntegrationDispatcher — konsument sh_event_outbox dla adapterów 3rd-party.
 *
 * Różnica vs WebhookDispatcher (m026/7.3):
 *   • Webhooks to GENERIC HTTP push do `sh_webhook_endpoints` (Slack, Zapier…).
 *   • Integrations to CONKRETNY PROVIDER (Papu/Dotykacka/GastroSoft) z
 *     per-adapter mapowaniem eventu na request + per-provider semantyką
 *     response'u.
 *
 * Oba workery są NIEZALEŻNE — nie koordynują się ze sobą. Webhook worker
 * zarządza `sh_event_outbox.status`. Integration worker trzyma własny stan
 * w `sh_integration_deliveries` i nie tyka outboxa (oprócz polling query).
 *
 * Query workera:
 *   SELECT eo.* FROM sh_event_outbox eo
 *   LEFT JOIN sh_integration_deliveries d
 *     ON d.event_id = eo.id AND d.integration_id = <per-adapter>
 *   WHERE eo.tenant_id = ? AND eo.event_type IN (...)
 *     AND (d.id IS NULL OR (d.status='pending' AND d.next_attempt_at <= NOW()))
 *
 * W rzeczywistości robimy to 2-etapowo: najpierw znajdź pending eventy
 * (brak delivery record) + retry candidates (delivery record w stanie pending
 * z upłynionym backoff), potem dla każdego utwórz/update delivery row.
 */
final class IntegrationDispatcher
{
    /** Exponential backoff — w sekundach. Index = attempt (0-based). */
    private const BACKOFF_SCHEDULE = [
        0 => 30,
        1 => 120,
        2 => 600,
        3 => 1800,
        4 => 7200,
        5 => 21600,
        6 => 86400,
    ];

    private const MAX_ATTEMPTS_DEFAULT = 6;
    private const RESPONSE_LOG_LIMIT = 2000;
    private const ERROR_LOG_LIMIT    = 1000;

    private static ?bool $tablesAvailable = null;

    /** @var callable(string, string, array, string, int): array */
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
     * Single batch run.
     *
     * @return array{processed: int, delivered: int, failed: int, dead: int, no_adapters: int, skipped: int}
     */
    public function runBatch(): array
    {
        if (!$this->areTablesAvailable()) {
            $this->log('integration tables not present — nothing to dispatch');
            return $this->emptyStats();
        }

        $stats = $this->emptyStats();

        // 1. Znajdź kandydatów: niedawne eventy + stare pending delivery rows z expired backoff
        $candidates = $this->collectCandidates($this->batchSize);
        if (empty($candidates)) {
            return $stats;
        }

        foreach ($candidates as $row) {
            $this->processCandidate($row, $stats);
        }

        return $stats;
    }

    /**
     * Kandydaci to pary (event_id, integration_id) do próby dostawy.
     * Zwraca max batchSize rekordów.
     *
     * @return list<array{
     *   event_id: int, tenant_id: int, event_type: string, aggregate_type: string,
     *   aggregate_id: string, payload: string, source: string, actor_type: ?string,
     *   actor_id: ?string, created_at: string,
     *   delivery_id: ?int, delivery_status: ?string, delivery_attempts: int
     * }>
     */
    private function collectCandidates(int $limit): array
    {
        // Bierzemy eventy z max 24h (starsze to "bagno"; dead letter by się
        // pojawił wcześniej jeśli adapter bridżuje event_type).
        // Podzapytanie łączy outbox z istniejącymi delivery rows;
        // dla każdego tenanta worker iteruje adapterami — to sprzęta OUTSIDE
        // tej query (w processCandidate).
        $stmt = $this->pdo->prepare(
            "SELECT eo.id            AS event_id,
                    eo.tenant_id,
                    eo.event_type,
                    eo.aggregate_type,
                    eo.aggregate_id,
                    eo.payload,
                    eo.source,
                    eo.actor_type,
                    eo.actor_id,
                    eo.created_at
             FROM sh_event_outbox eo
             WHERE eo.created_at > NOW() - INTERVAL 24 HOUR
               AND eo.tenant_id IN (
                   SELECT DISTINCT tenant_id FROM sh_tenant_integrations
                   WHERE is_active = 1 AND direction IN ('push','bidirectional')
               )
             ORDER BY eo.id ASC
             LIMIT {$limit}"
        );
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Dla pojedynczego eventu: resolve adaptery tenanta i dla każdego wykonaj
     * próbę dostawy (jeśli jeszcze nie ma delivery row LUB jest pending z
     * expired backoffem).
     */
    private function processCandidate(array $event, array &$stats): void
    {
        $tenantId  = (int)$event['tenant_id'];
        $eventId   = (int)$event['event_id'];
        $eventType = (string)$event['event_type'];

        $adapters = AdapterRegistry::resolveForTenant($this->pdo, $tenantId);
        if (empty($adapters)) {
            $stats['no_adapters']++;
            return;
        }

        $envelope = $this->buildEnvelope($event);

        foreach ($adapters as $adapter) {
            if (!$adapter->supportsEvent($eventType)) {
                continue;
            }

            $integrationId = (int)$adapter->getIntegrationId();
            $delivery      = $this->findDelivery($eventId, $integrationId);

            if ($delivery !== null) {
                $status = (string)$delivery['status'];
                // delivered / dead → skip
                if ($status === 'delivered' || $status === 'dead') {
                    continue;
                }
                // pending z wciąż trwającym backoffem → skip
                if ($status === 'pending' && $delivery['next_attempt_at'] !== null) {
                    if (strtotime((string)$delivery['next_attempt_at']) > time()) {
                        $stats['skipped']++;
                        continue;
                    }
                }
                // delivering od dawna → "stuck" (zrestartowaliśmy worker mid-delivery) — retry
            }

            $result = $this->deliverOnce($adapter, $envelope, $delivery, $event);
            $stats['processed']++;
            $stats[$result]++;
        }
    }

    /**
     * Jedna próba dostawy dla (event, adapter). Zarządza stanem
     * sh_integration_deliveries + wkleja audit row do sh_integration_attempts.
     *
     * @return 'delivered'|'failed'|'dead'|'skipped'
     */
    private function deliverOnce(BaseAdapter $adapter, array $envelope, ?array $deliveryRow, array $event): string
    {
        $integrationId = $adapter->getIntegrationId();
        $eventId       = (int)$event['event_id'];
        $tenantId      = (int)$event['tenant_id'];
        $provider      = $adapter::providerKey();

        // Upsert delivery row
        if ($deliveryRow === null) {
            $deliveryId = $this->createDeliveryRow(
                $tenantId, $eventId, $integrationId, $provider,
                (string)$event['aggregate_id'], (string)$event['event_type']
            );
            $attempts = 0;
        } else {
            $deliveryId = (int)$deliveryRow['id'];
            $attempts   = (int)$deliveryRow['attempts'];
            $this->markDeliveryDelivering($deliveryId);
        }

        // Build request
        try {
            $req = $adapter->buildRequest($envelope);
        } catch (AdapterException $e) {
            // Permanent — straight to DLQ
            $this->logAttempt($deliveryId, $attempts + 1, null, null, null, 'build: ' . $e->getMessage(), 0);
            $this->markDeliveryDead($deliveryId, $attempts + 1, 'build failed: ' . $e->getMessage(), null);
            return 'dead';
        } catch (\Throwable $e) {
            // Nieoczekiwany — traktuj jako transient i retry
            $this->logAttempt($deliveryId, $attempts + 1, null, null, null, 'build exception: ' . $e->getMessage(), 0);
            $this->scheduleRetry($deliveryId, $attempts, 'build exception: ' . $e->getMessage(), null);
            return 'failed';
        }

        $timeout = (int)($adapter->getTimeoutSeconds() ?: 8);
        $startedAt = microtime(true);

        try {
            $response = ($this->httpTransport)(
                (string)$req['method'],
                (string)$req['url'],
                (array)$req['headers'],
                (string)$req['body'],
                $timeout
            );
        } catch (\Throwable $e) {
            $duration = (int)((microtime(true) - $startedAt) * 1000);
            $this->logAttempt($deliveryId, $attempts + 1, null, null, $req['body'], 'transport: ' . $e->getMessage(), $duration);
            $this->scheduleRetry($deliveryId, $attempts, 'transport: ' . $e->getMessage(), null);
            $this->bumpIntegrationFailure($integrationId);
            return 'failed';
        }

        $duration = (int)((microtime(true) - $startedAt) * 1000);
        $httpCode = (int)($response['code']  ?? 0);
        $respBody = (string)($response['body'] ?? '');
        $xpError  = $response['error'] ?? null;

        $verdict = $adapter->parseResponse($httpCode, $respBody, $xpError);

        $this->logAttempt(
            $deliveryId, $attempts + 1, $httpCode, $respBody, $req['body'],
            $verdict['error'] ?? null, $duration
        );

        if (($verdict['ok'] ?? false) === true) {
            $this->markDeliveryDelivered(
                $deliveryId,
                $attempts + 1,
                $httpCode,
                $respBody,
                $req['body'],
                $duration,
                $verdict['externalRef'] ?? null
            );
            $this->markIntegrationSuccess($integrationId);
            return 'delivered';
        }

        $isTransient = (bool)($verdict['transient'] ?? false);
        $newAttempts = $attempts + 1;
        $maxAttempts = $adapter->getMaxRetries() ?: self::MAX_ATTEMPTS_DEFAULT;

        if (!$isTransient || $newAttempts >= $maxAttempts) {
            $this->markDeliveryDead(
                $deliveryId, $newAttempts,
                $verdict['error'] ?? 'unknown',
                $httpCode
            );
            $this->bumpIntegrationFailure($integrationId);
            return 'dead';
        }

        $this->scheduleRetry($deliveryId, $attempts, $verdict['error'] ?? 'transient', $httpCode);
        $this->bumpIntegrationFailure($integrationId);
        return 'failed';
    }

    // ── Delivery row CRUD ───────────────────────────────────────────────

    private function findDelivery(int $eventId, int $integrationId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, status, attempts, next_attempt_at
             FROM sh_integration_deliveries
             WHERE event_id = :eid AND integration_id = :iid
             LIMIT 1"
        );
        $stmt->execute([':eid' => $eventId, ':iid' => $integrationId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function createDeliveryRow(
        int $tenantId, int $eventId, int $integrationId, string $provider,
        string $aggregateId, string $eventType
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO sh_integration_deliveries
                (tenant_id, event_id, integration_id, provider, aggregate_id, event_type,
                 status, attempts, created_at)
             VALUES (:tid, :eid, :iid, :prov, :agg, :etype, 'delivering', 0, NOW())"
        );
        $stmt->execute([
            ':tid'   => $tenantId,
            ':eid'   => $eventId,
            ':iid'   => $integrationId,
            ':prov'  => $provider,
            ':agg'   => $aggregateId,
            ':etype' => $eventType,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function markDeliveryDelivering(int $deliveryId): void
    {
        $this->pdo->prepare(
            "UPDATE sh_integration_deliveries SET status = 'delivering', last_attempted_at = NOW() WHERE id = :id"
        )->execute([':id' => $deliveryId]);
    }

    private function markDeliveryDelivered(
        int $deliveryId, int $attempts, int $httpCode, string $body,
        string $requestBody, int $durationMs, ?string $externalRef
    ): void {
        $this->pdo->prepare(
            "UPDATE sh_integration_deliveries
             SET status            = 'delivered',
                 attempts          = :att,
                 http_code         = :hc,
                 response_body     = :rb,
                 request_payload   = :req,
                 duration_ms       = :dur,
                 external_ref      = :ext,
                 last_attempted_at = NOW(),
                 completed_at      = NOW(),
                 last_error        = NULL,
                 next_attempt_at   = NULL
             WHERE id = :id"
        )->execute([
            ':att' => $attempts,
            ':hc'  => $httpCode,
            ':rb'  => substr($body, 0, self::RESPONSE_LOG_LIMIT),
            ':req' => substr($requestBody, 0, 10000),
            ':dur' => $durationMs,
            ':ext' => $externalRef,
            ':id'  => $deliveryId,
        ]);
    }

    private function scheduleRetry(int $deliveryId, int $currentAttempts, string $error, ?int $httpCode): void
    {
        $backoff = self::BACKOFF_SCHEDULE[$currentAttempts] ?? end(self::BACKOFF_SCHEDULE);
        $this->pdo->prepare(
            "UPDATE sh_integration_deliveries
             SET status            = 'pending',
                 attempts          = :att,
                 next_attempt_at   = DATE_ADD(NOW(), INTERVAL :bo SECOND),
                 last_error        = :err,
                 http_code         = :hc,
                 last_attempted_at = NOW()
             WHERE id = :id"
        )->execute([
            ':att' => $currentAttempts + 1,
            ':bo'  => $backoff,
            ':err' => substr($error, 0, self::ERROR_LOG_LIMIT),
            ':hc'  => $httpCode,
            ':id'  => $deliveryId,
        ]);
    }

    private function markDeliveryDead(int $deliveryId, int $attempts, string $error, ?int $httpCode): void
    {
        $this->pdo->prepare(
            "UPDATE sh_integration_deliveries
             SET status            = 'dead',
                 attempts          = :att,
                 last_error        = :err,
                 http_code         = :hc,
                 last_attempted_at = NOW(),
                 completed_at      = NOW(),
                 next_attempt_at   = NULL
             WHERE id = :id"
        )->execute([
            ':att' => $attempts,
            ':err' => substr($error, 0, self::ERROR_LOG_LIMIT),
            ':hc'  => $httpCode,
            ':id'  => $deliveryId,
        ]);
    }

    private function logAttempt(
        int $deliveryId, int $attemptNumber, ?int $httpCode,
        ?string $responseBody, ?string $requestBody, ?string $error, int $durationMs
    ): void {
        try {
            $this->pdo->prepare(
                "INSERT INTO sh_integration_attempts
                    (delivery_id, attempt_number, http_code, duration_ms,
                     request_snippet, response_body, error_message, attempted_at)
                 VALUES (:did, :att, :hc, :dur, :req, :rb, :err, NOW())"
            )->execute([
                ':did' => $deliveryId,
                ':att' => $attemptNumber,
                ':hc'  => $httpCode,
                ':dur' => $durationMs,
                ':req' => $requestBody !== null ? substr($requestBody, 0, 500) : null,
                ':rb'  => $responseBody !== null ? substr($responseBody, 0, self::RESPONSE_LOG_LIMIT) : null,
                ':err' => $error !== null ? substr($error, 0, self::ERROR_LOG_LIMIT) : null,
            ]);
        } catch (\Throwable $e) {
            // Attempts log = best-effort; never break primary flow.
            error_log('[IntegrationDispatcher] attempt log failed: ' . $e->getMessage());
        }
    }

    // ── Integration health ──────────────────────────────────────────────

    private function markIntegrationSuccess(int $integrationId): void
    {
        try {
            $this->pdo->prepare(
                "UPDATE sh_tenant_integrations
                 SET last_sync_at = NOW(), consecutive_failures = 0
                 WHERE id = :id"
            )->execute([':id' => $integrationId]);
        } catch (\Throwable $e) {
            // Schema przed m028 mogła nie mieć consecutive_failures.
            try {
                $this->pdo->prepare(
                    "UPDATE sh_tenant_integrations SET last_sync_at = NOW() WHERE id = :id"
                )->execute([':id' => $integrationId]);
            } catch (\Throwable $e2) { /* ignore */ }
        }
    }

    private function bumpIntegrationFailure(int $integrationId): void
    {
        try {
            $this->pdo->prepare(
                "UPDATE sh_tenant_integrations
                 SET last_failure_at      = NOW(),
                     consecutive_failures = consecutive_failures + 1,
                     is_active            = CASE
                         WHEN consecutive_failures + 1 >= max_retries THEN 0
                         ELSE is_active
                     END
                 WHERE id = :id"
            )->execute([':id' => $integrationId]);
        } catch (\Throwable $e) {
            // Legacy schema — silent skip.
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function buildEnvelope(array $event): array
    {
        $payload = json_decode((string)$event['payload'], true);
        if (!is_array($payload)) $payload = [];

        return [
            'event_id'       => (string)$event['event_id'],
            'event_type'     => (string)$event['event_type'],
            'aggregate_id'   => (string)$event['aggregate_id'],
            'aggregate_type' => (string)$event['aggregate_type'],
            'tenant_id'      => (int)$event['tenant_id'],
            'source'         => (string)$event['source'],
            'actor_type'     => $event['actor_type'] !== null ? (string)$event['actor_type'] : null,
            'actor_id'       => $event['actor_id']   !== null ? (string)$event['actor_id']   : null,
            'occurred_at'    => $this->toIso8601((string)$event['created_at']),
            'attempt'        => 1,
            'payload'        => $payload,
        ];
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

    private function emptyStats(): array
    {
        return [
            'processed'   => 0,
            'delivered'   => 0,
            'failed'      => 0,
            'dead'        => 0,
            'no_adapters' => 0,
            'skipped'     => 0,
        ];
    }

    private function areTablesAvailable(): bool
    {
        if (self::$tablesAvailable !== null) return self::$tablesAvailable;
        try {
            $this->pdo->query("SELECT 1 FROM sh_integration_deliveries LIMIT 0");
            $this->pdo->query("SELECT 1 FROM sh_event_outbox LIMIT 0");
            $this->pdo->query("SELECT 1 FROM sh_tenant_integrations LIMIT 0");
            self::$tablesAvailable = true;
        } catch (\Throwable $e) {
            self::$tablesAvailable = false;
        }
        return self::$tablesAvailable;
    }

    private function log(string $msg): void
    {
        if (!$this->verbose) return;
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] IntegrationDispatcher: ' . $msg . PHP_EOL);
    }

    public static function curlTransport(string $method, string $url, array $headers, string $body, int $timeout): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 3),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== '' && $method !== 'GET' && $method !== 'DELETE') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $respBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'code'  => $httpCode,
            'body'  => is_string($respBody) ? $respBody : '',
            'error' => $err,
        ];
    }

    public static function resetCache(): void
    {
        self::$tablesAvailable = null;
    }
}
