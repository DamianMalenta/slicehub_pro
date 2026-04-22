<?php

declare(strict_types=1);

/**
 * PapuClient — fire-and-forget webhook adapter for Papu.io legacy POS.
 *
 * Transforms SliceHub order data into a generic 3rd-party payload and
 * pushes it via cURL with strict timeouts. Every call (success or failure)
 * is logged to sh_integration_logs. A Papu outage must never affect the
 * SliceHub POS response.
 */
class PapuClient
{
    private const CONNECT_TIMEOUT = 3;
    private const EXEC_TIMEOUT    = 5;

    // TODO: Replace with the real Papu.io endpoint when API docs arrive
    private const PAPU_BASE_URL = 'https://api.papu.io/v1/orders';

    private \PDO $pdo;
    private int  $tenantId;

    public function __construct(\PDO $pdo, int $tenantId)
    {
        $this->pdo      = $pdo;
        $this->tenantId = $tenantId;
    }

    /**
     * Main entry point — called from engine.php AFTER the local commit.
     * Never throws; all errors are caught and logged internally.
     */
    public function pushOrder(array $orderData, array $orderItems, string $apiKey): void
    {
        $orderId = (string)($orderData['id'] ?? '');

        try {
            $payload  = $this->transformPayload($orderData, $orderItems);
            $result   = $this->sendRequest(self::PAPU_BASE_URL, $payload, $apiKey);

            $this->logResult(
                $orderId,
                'papu',
                (int)($result['http_code'] ?? 0),
                (string)($result['body'] ?? ''),
                $result['error'] ?? null,
                $payload
            );
        } catch (\Throwable $e) {
            error_log("[PapuClient] pushOrder failed for order {$orderId}: {$e->getMessage()}");
            $this->logResult($orderId, 'papu', 0, '', $e->getMessage(), null);
        }
    }

    // ---------------------------------------------------------------
    // Payload transformation
    // ---------------------------------------------------------------

    /**
     * Maps SliceHub internal data → generic 3rd-party JSON structure.
     *
     * TODO: Replace the return schema with the exact Papu.io JSON contract
     *       once their API documentation is available. The current shape is
     *       a reasonable ChoiceQR-style integration placeholder.
     */
    private function transformPayload(array $orderData, array $orderItems): array
    {
        // TODO: Papu may expect amounts in PLN (float) instead of grosze (int).
        //       Adjust the divisor here once confirmed.
        $totalGrosze = (int)($orderData['grand_total'] ?? 0);

        return [
            'external_order_id' => $orderData['id'] ?? '',
            'order_number'      => $orderData['order_number'] ?? '',
            'source'            => 'slicehub',

            // TODO: Map to Papu's customer object shape
            'customer' => [
                'name'    => $orderData['customer_name'] ?? null,
                'phone'   => $orderData['customer_phone'] ?? null,
                'address' => $orderData['delivery_address'] ?? null,
            ],

            'order_type'     => $orderData['order_type'] ?? '',
            'payment_method' => $orderData['payment_method'] ?? null,
            'payment_status' => $orderData['payment_status'] ?? '',
            'total_amount'   => $totalGrosze,
            'promised_time'  => $orderData['promised_time'] ?? null,

            // TODO: Papu may use a different item schema (e.g. PLU codes, tax groups)
            'items' => array_map(function (array $item): array {
                return [
                    'sku'        => (string)($item['ascii_key'] ?? $item['id'] ?? ''),
                    'name'       => (string)($item['name'] ?? ''),
                    'quantity'   => (int)($item['qty'] ?? $item['quantity'] ?? 1),
                    'unit_price' => (int)round(((float)($item['price'] ?? 0)) * 100),
                    'modifiers'  => $item['added'] ?? [],
                    'removed'    => $item['removed'] ?? [],
                    'comment'    => $item['comment'] ?? null,
                ];
            }, $orderItems),
        ];
    }

    // ---------------------------------------------------------------
    // HTTP transport
    // ---------------------------------------------------------------

    /**
     * Fires a cURL POST with strict timeouts.
     * Returns ['http_code' => int, 'body' => string, 'error' => ?string].
     * Never throws.
     */
    private function sendRequest(string $url, array $payload, string $apiKey): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['http_code' => 0, 'body' => '', 'error' => 'curl_init() failed'];
            }

            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => self::EXEC_TIMEOUT,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    // TODO: Confirm Papu's auth header format (Bearer vs X-Api-Key)
                    'Authorization: Bearer ' . $apiKey,
                ],
            ]);

            $body     = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($body === false || $curlErr !== '') {
                return ['http_code' => $httpCode, 'body' => '', 'error' => 'cURL: ' . $curlErr];
            }

            $error = ($httpCode >= 400) ? "HTTP {$httpCode}" : null;

            return ['http_code' => $httpCode, 'body' => (string)$body, 'error' => $error];
        } catch (\Throwable $e) {
            return ['http_code' => 0, 'body' => '', 'error' => $e->getMessage()];
        }
    }

    // ---------------------------------------------------------------
    // Logging
    // ---------------------------------------------------------------

    /**
     * Persists the integration attempt to sh_integration_logs.
     * Auto-creates the table on first use (probe-then-create pattern
     * already established in this codebase for KDS columns).
     */
    private function logResult(
        string  $orderId,
        string  $provider,
        int     $httpCode,
        string  $responseBody,
        ?string $error,
        ?array  $requestPayload = null
    ): void {
        try {
            $this->ensureLogTable();

            $this->pdo->prepare(
                "INSERT INTO sh_integration_logs
                    (tenant_id, order_id, provider, http_code, request_payload, response_body, error_message, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
            )->execute([
                $this->tenantId,
                $orderId !== '' ? $orderId : null,
                $provider,
                $httpCode,
                $requestPayload !== null ? json_encode($requestPayload, JSON_UNESCAPED_UNICODE) : null,
                $responseBody !== '' ? mb_substr($responseBody, 0, 10000) : null,
                $error,
            ]);
        } catch (\Throwable $e) {
            error_log("[PapuClient] logResult failed: {$e->getMessage()}");
        }
    }

    private bool $tableChecked = false;

    private function ensureLogTable(): void
    {
        if ($this->tableChecked) {
            return;
        }

        try {
            $this->pdo->query("SELECT 1 FROM sh_integration_logs LIMIT 0");
            $this->tableChecked = true;
        } catch (\Throwable $e) {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sh_integration_logs (
                    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    tenant_id       INT UNSIGNED    NOT NULL,
                    order_id        CHAR(36)        NULL,
                    provider        VARCHAR(32)     NOT NULL DEFAULT 'papu',
                    http_code       SMALLINT UNSIGNED NULL,
                    request_payload JSON            NULL,
                    response_body   TEXT            NULL,
                    error_message   TEXT            NULL,
                    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_intlog_tenant_order (tenant_id, order_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $this->tableChecked = true;
        }
    }
}
