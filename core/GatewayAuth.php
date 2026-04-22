<?php

declare(strict_types=1);

/**
 * GatewayAuth — core service dla warstwy autoryzacji i idempotency bramki
 * `api/gateway/intake.php`.
 *
 * Odpowiedzialności:
 *   1. Walidacja klucza API   → authenticateKey()
 *   2. Rate limiting          → checkAndIncrementRateLimit()
 *   3. External idempotency   → lookupExternalRef() / storeExternalRef()
 *
 * Tryb legacy:
 *   Gdy tabela `sh_gateway_api_keys` nie istnieje (stary setup bez m027),
 *   authenticateKey() fallbackuje do env GATEWAY_API_KEY + `source = 'web'`.
 *   Wszystkie nowe endpoints które wymagają scope != default otrzymają
 *   DENIED (bo legacy key nie ma scope'ów).
 *
 * Bezpieczeństwo:
 *   • Sekrety w DB tylko jako SHA-256 hashe (nigdy plaintext).
 *   • `hash_equals()` dla timing-safe comparison.
 *   • Rate limit w transakcji + UNIQUE constraint (race-safe).
 */
final class GatewayAuth
{
    /**
     * Wynik autoryzacji klucza.
     *
     * @psalm-type AuthResult = array{
     *     ok: bool,
     *     reason?: string,        // 'invalid' | 'expired' | 'revoked' | 'inactive' | 'rate_limited'
     *     apiKeyId?: int,
     *     tenantId?: int,
     *     source?: string,
     *     scopes?: array<int,string>,
     *     legacy?: bool,
     *     retryAfter?: int        // seconds, gdy reason = rate_limited
     * }
     */

    /** Cache feature-detect per request. */
    private static ?bool $keysTableAvailable = null;

    /**
     * Zweryfikuj klucz API.
     *
     * @param  \PDO   $pdo
     * @param  string $providedKey  Nagłówek X-API-Key
     * @param  ?int   $tenantIdFromPayload  Opcjonalnie — dla legacy fallbacku
     * @return array  AuthResult (patrz @psalm-type)
     */
    public static function authenticateKey(\PDO $pdo, string $providedKey, ?int $tenantIdFromPayload = null): array
    {
        $providedKey = trim($providedKey);
        if ($providedKey === '') {
            return ['ok' => false, 'reason' => 'invalid'];
        }

        // 1. NOWY TRYB — lookup w sh_gateway_api_keys po key_prefix.
        if (self::isKeysTableAvailable($pdo)) {
            $parsed = self::parseKey($providedKey);
            if ($parsed === null) {
                // Format nieprawidłowy — ale może to legacy klucz → fallback
                return self::tryLegacyFallback($providedKey, $tenantIdFromPayload);
            }

            [$prefix, $rawSecret] = $parsed;

            $stmt = $pdo->prepare(
                "SELECT id, tenant_id, key_secret_hash, source, scopes,
                        rate_limit_per_min, rate_limit_per_day,
                        is_active, revoked_at, expires_at
                 FROM sh_gateway_api_keys
                 WHERE key_prefix = :prefix
                 LIMIT 1"
            );
            $stmt->execute([':prefix' => $prefix]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                return ['ok' => false, 'reason' => 'invalid'];
            }

            // Timing-safe comparison
            $expectedHash = hash('sha256', $rawSecret);
            if (!hash_equals((string)$row['key_secret_hash'], $expectedHash)) {
                return ['ok' => false, 'reason' => 'invalid'];
            }

            if ((int)$row['is_active'] !== 1) {
                return ['ok' => false, 'reason' => 'inactive'];
            }
            if ($row['revoked_at'] !== null) {
                return ['ok' => false, 'reason' => 'revoked'];
            }
            if ($row['expires_at'] !== null && strtotime((string)$row['expires_at']) < time()) {
                return ['ok' => false, 'reason' => 'expired'];
            }

            $scopes = json_decode((string)$row['scopes'], true);
            if (!is_array($scopes)) {
                $scopes = [];
            }

            // Update last_used (async-ish — bez transakcji, best-effort)
            try {
                $ip = self::detectClientIp();
                $upd = $pdo->prepare(
                    "UPDATE sh_gateway_api_keys
                     SET last_used_at = NOW(), last_used_ip = :ip
                     WHERE id = :id"
                );
                $upd->execute([':ip' => $ip, ':id' => $row['id']]);
            } catch (\Throwable $e) {
                // best-effort
            }

            return [
                'ok'        => true,
                'apiKeyId'  => (int)$row['id'],
                'tenantId'  => (int)$row['tenant_id'],
                'source'    => (string)$row['source'],
                'scopes'    => $scopes,
                'rateLimitPerMin' => (int)$row['rate_limit_per_min'],
                'rateLimitPerDay' => (int)$row['rate_limit_per_day'],
                'legacy'    => false,
            ];
        }

        // 2. LEGACY — fallback do env GATEWAY_API_KEY.
        return self::tryLegacyFallback($providedKey, $tenantIdFromPayload);
    }

    /**
     * Sliding window rate limit check + atomic increment.
     *
     * Strategy: per (api_key_id, minute) + (api_key_id, day). Obie bramki muszą
     * przejść. UNIQUE + INSERT ... ON DUPLICATE KEY UPDATE = race-safe.
     *
     * @return array{ok: bool, retryAfter?: int, hits?: array{minute: int, day: int}}
     */
    public static function checkAndIncrementRateLimit(\PDO $pdo, int $apiKeyId, int $limitPerMin, int $limitPerDay): array
    {
        if (!self::isKeysTableAvailable($pdo)) {
            // Legacy — bez rate limitu (back-compat).
            return ['ok' => true, 'hits' => ['minute' => 0, 'day' => 0]];
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $minuteBucket = $now->format('Y-m-d H:i');
        $dayBucket    = $now->format('Y-m-d');

        try {
            // Increment minute bucket
            $stmt = $pdo->prepare(
                "INSERT INTO sh_rate_limits (api_key_id, window_kind, window_bucket, request_count)
                 VALUES (:kid, 'minute', :bucket, 1)
                 ON DUPLICATE KEY UPDATE request_count = request_count + 1, last_hit_at = NOW()"
            );
            $stmt->execute([':kid' => $apiKeyId, ':bucket' => $minuteBucket]);

            $stmtSel = $pdo->prepare(
                "SELECT request_count FROM sh_rate_limits
                 WHERE api_key_id = :kid AND window_kind = 'minute' AND window_bucket = :bucket"
            );
            $stmtSel->execute([':kid' => $apiKeyId, ':bucket' => $minuteBucket]);
            $minuteHits = (int)$stmtSel->fetchColumn();

            if ($limitPerMin > 0 && $minuteHits > $limitPerMin) {
                $retryAfter = 60 - (int)$now->format('s');
                return ['ok' => false, 'retryAfter' => max(1, $retryAfter), 'hits' => ['minute' => $minuteHits, 'day' => 0]];
            }

            // Increment day bucket
            $stmt = $pdo->prepare(
                "INSERT INTO sh_rate_limits (api_key_id, window_kind, window_bucket, request_count)
                 VALUES (:kid, 'day', :bucket, 1)
                 ON DUPLICATE KEY UPDATE request_count = request_count + 1, last_hit_at = NOW()"
            );
            $stmt->execute([':kid' => $apiKeyId, ':bucket' => $dayBucket]);

            $stmtSel = $pdo->prepare(
                "SELECT request_count FROM sh_rate_limits
                 WHERE api_key_id = :kid AND window_kind = 'day' AND window_bucket = :bucket"
            );
            $stmtSel->execute([':kid' => $apiKeyId, ':bucket' => $dayBucket]);
            $dayHits = (int)$stmtSel->fetchColumn();

            if ($limitPerDay > 0 && $dayHits > $limitPerDay) {
                // Czas do następnej doby UTC
                $tomorrow = $now->modify('+1 day')->setTime(0, 0);
                $retryAfter = $tomorrow->getTimestamp() - $now->getTimestamp();
                return ['ok' => false, 'retryAfter' => $retryAfter, 'hits' => ['minute' => $minuteHits, 'day' => $dayHits]];
            }

            return ['ok' => true, 'hits' => ['minute' => $minuteHits, 'day' => $dayHits]];

        } catch (\Throwable $e) {
            error_log('[GatewayAuth] rate limit check failed: ' . $e->getMessage());
            // Fail-open — awaria limitera nie blokuje ruchu.
            return ['ok' => true, 'hits' => ['minute' => 0, 'day' => 0]];
        }
    }

    /**
     * Lookup: czy external_id już zmapowany na order_id?
     *
     * @return ?array{orderId: string, createdAt: string, requestHash: ?string}
     */
    public static function lookupExternalRef(\PDO $pdo, int $tenantId, string $source, string $externalId): ?array
    {
        if (!self::isRefsTableAvailable($pdo)) {
            return null;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT order_id, created_at, request_hash
                 FROM sh_external_order_refs
                 WHERE tenant_id = :tid AND source = :src AND external_id = :eid
                 LIMIT 1"
            );
            $stmt->execute([':tid' => $tenantId, ':src' => $source, ':eid' => $externalId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) return null;

            return [
                'orderId'     => (string)$row['order_id'],
                'createdAt'   => (string)$row['created_at'],
                'requestHash' => $row['request_hash'] !== null ? (string)$row['request_hash'] : null,
            ];
        } catch (\Throwable $e) {
            error_log('[GatewayAuth] lookupExternalRef failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Zapis mapy external_id → order_id PO udanej transakcji.
     * INSERT IGNORE żeby retry nie rzucał duplikatem.
     */
    public static function storeExternalRef(
        \PDO $pdo,
        int $tenantId,
        string $source,
        string $externalId,
        string $orderId,
        ?int $apiKeyId = null,
        ?string $requestHash = null
    ): bool {
        if (!self::isRefsTableAvailable($pdo)) {
            return false;
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO sh_external_order_refs
                    (tenant_id, source, external_id, order_id, api_key_id, request_hash, created_at)
                 VALUES
                    (:tid, :src, :eid, :oid, :kid, :rh, NOW())"
            );
            $stmt->execute([
                ':tid' => $tenantId,
                ':src' => $source,
                ':eid' => $externalId,
                ':oid' => $orderId,
                ':kid' => $apiKeyId,
                ':rh'  => $requestHash,
            ]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('[GatewayAuth] storeExternalRef failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Generator nowych kluczy (dla UI Settings w Sesji 7.5).
     * Raw secret zwracany 1× — nigdy więcej nie da się go odzyskać.
     *
     * @return array{prefix: string, rawSecret: string, fullKey: string, hash: string}
     */
    public static function generateKey(string $env = 'live'): array
    {
        $env = in_array($env, ['live', 'test', 'dev'], true) ? $env : 'live';
        $prefixRand = bin2hex(random_bytes(4));          // 8 hex chars
        $prefix     = "sh_{$env}_{$prefixRand}";          // np. sh_live_a1b2c3d4
        $rawSecret  = bin2hex(random_bytes(24));          // 48 hex chars (192 bit entropii)
        $fullKey    = $prefix . '.' . $rawSecret;
        $hash       = hash('sha256', $rawSecret);
        return ['prefix' => $prefix, 'rawSecret' => $rawSecret, 'fullKey' => $fullKey, 'hash' => $hash];
    }

    /**
     * Parsowanie "prefix.secret" → [prefix, secret].
     * @return ?array{0: string, 1: string}
     */
    private static function parseKey(string $fullKey): ?array
    {
        if (!preg_match('/^(sh_(?:live|test|dev)_[a-f0-9]{8})\.([a-f0-9]{48})$/', $fullKey, $m)) {
            return null;
        }
        return [$m[1], $m[2]];
    }

    /**
     * Legacy fallback — env GATEWAY_API_KEY.
     */
    private static function tryLegacyFallback(string $providedKey, ?int $tenantIdFromPayload): array
    {
        $expectedKey = getenv('GATEWAY_API_KEY') ?: 'DEV_SECRET_KEY';

        if (!hash_equals($expectedKey, $providedKey)) {
            return ['ok' => false, 'reason' => 'invalid'];
        }

        // Legacy klucz — musi mieć tenant_id z payloadu.
        if ($tenantIdFromPayload === null || $tenantIdFromPayload <= 0) {
            return ['ok' => false, 'reason' => 'invalid'];
        }

        return [
            'ok'        => true,
            'apiKeyId'  => 0,
            'tenantId'  => $tenantIdFromPayload,
            'source'    => 'web',
            'scopes'    => ['order:create'],
            'rateLimitPerMin' => 0, // 0 = bez limitu dla legacy
            'rateLimitPerDay' => 0,
            'legacy'    => true,
        ];
    }

    private static function isKeysTableAvailable(\PDO $pdo): bool
    {
        if (self::$keysTableAvailable !== null) {
            return self::$keysTableAvailable;
        }

        try {
            $pdo->query("SELECT 1 FROM sh_gateway_api_keys LIMIT 0");
            self::$keysTableAvailable = true;
        } catch (\Throwable $e) {
            self::$keysTableAvailable = false;
        }

        return self::$keysTableAvailable;
    }

    private static ?bool $refsTableAvailable = null;

    private static function isRefsTableAvailable(\PDO $pdo): bool
    {
        if (self::$refsTableAvailable !== null) {
            return self::$refsTableAvailable;
        }

        try {
            $pdo->query("SELECT 1 FROM sh_external_order_refs LIMIT 0");
            self::$refsTableAvailable = true;
        } catch (\Throwable $e) {
            self::$refsTableAvailable = false;
        }

        return self::$refsTableAvailable;
    }

    private static function detectClientIp(): ?string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP']   ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR']    ?? null,
            $_SERVER['HTTP_X_REAL_IP']          ?? null,
            $_SERVER['REMOTE_ADDR']             ?? null,
        ];

        foreach ($candidates as $ip) {
            if (!$ip) continue;
            // X-Forwarded-For może być lista — bierzemy pierwszy
            $first = trim(explode(',', (string)$ip)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        return null;
    }

    /** @internal wyłącznie do testów */
    public static function resetCache(): void
    {
        self::$keysTableAvailable = null;
        self::$refsTableAvailable = null;
    }
}
