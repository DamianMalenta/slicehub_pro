<?php

declare(strict_types=1);

namespace SliceHub\Integrations;

/**
 * AdapterRegistry — mapuje provider key (z sh_tenant_integrations.provider)
 * na konkretną klasę adaptera.
 *
 * Nowy adapter = nowa klasa extends BaseAdapter + wpis w `self::PROVIDER_MAP`.
 *
 * Registry cachuje loaded instances per tenant_id, by worker w pętli nie
 * reinstantiował tych samych obiektów 50× na batch.
 */
final class AdapterRegistry
{
    /**
     * Provider key → fully qualified class name.
     * Aby dodać nowy: stwórz klasę w core/Integrations, zaimportuj tu.
     */
    private const PROVIDER_MAP = [
        'papu'        => PapuAdapter::class,
        'dotykacka'   => DotykackaAdapter::class,
        'gastrosoft'  => GastroSoftAdapter::class,
    ];

    /** @var array<int, list<BaseAdapter>> tenant_id → [adapter, ...] */
    private static array $cache = [];

    /** @var bool Feature-detect: czy sh_tenant_integrations istnieje? */
    private static ?bool $tableAvailable = null;

    /**
     * Zwróć aktywne adaptery dla tenanta. Ładuje z sh_tenant_integrations
     * i filtruje `is_active=1`. Rzuca wyjątek gdy provider nieznany.
     *
     * @return list<BaseAdapter>
     */
    public static function resolveForTenant(\PDO $pdo, int $tenantId): array
    {
        if (isset(self::$cache[$tenantId])) {
            return self::$cache[$tenantId];
        }

        if (!self::isTableAvailable($pdo)) {
            return self::$cache[$tenantId] = [];
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT id, tenant_id, provider, display_name, api_base_url,
                        credentials, direction, events_bridged,
                        is_active, last_sync_at,
                        COALESCE(consecutive_failures, 0)  AS consecutive_failures,
                        COALESCE(max_retries, 6)           AS max_retries,
                        COALESCE(timeout_seconds, 8)       AS timeout_seconds
                 FROM sh_tenant_integrations
                 WHERE tenant_id = :tid
                   AND is_active = 1
                   AND direction IN ('push', 'bidirectional')"
            );
            $stmt->execute([':tid' => $tenantId]);
        } catch (\Throwable $e) {
            // Tabela bez kolumn health (brak m028) — spróbuj prostszą query
            try {
                $stmt = $pdo->prepare(
                    "SELECT id, tenant_id, provider, display_name, api_base_url,
                            credentials, direction, events_bridged, is_active, last_sync_at
                     FROM sh_tenant_integrations
                     WHERE tenant_id = :tid
                       AND is_active = 1
                       AND direction IN ('push', 'bidirectional')"
                );
                $stmt->execute([':tid' => $tenantId]);
            } catch (\Throwable $e2) {
                return self::$cache[$tenantId] = [];
            }
        }

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $adapters = [];

        foreach ($rows as $row) {
            $provider = (string)$row['provider'];
            $class    = self::PROVIDER_MAP[$provider] ?? null;

            if ($class === null) {
                error_log(sprintf(
                    '[AdapterRegistry] Unknown provider "%s" for tenant %d integration #%d — skipping',
                    $provider, $tenantId, (int)$row['id']
                ));
                continue;
            }

            if (!class_exists($class)) {
                error_log(sprintf(
                    '[AdapterRegistry] Class %s not found — skipping integration #%d',
                    $class, (int)$row['id']
                ));
                continue;
            }

            try {
                /** @var BaseAdapter $instance */
                $instance = new $class($row);
                $adapters[] = $instance;
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[AdapterRegistry] Failed to instantiate %s for integration #%d: %s',
                    $class, (int)$row['id'], $e->getMessage()
                ));
            }
        }

        return self::$cache[$tenantId] = $adapters;
    }

    /**
     * Lista wszystkich znanych providerów (dla UI dropdown).
     *
     * @return array<string,string> provider_key => display_name
     */
    public static function availableProviders(): array
    {
        $out = [];
        foreach (self::PROVIDER_MAP as $key => $class) {
            if (!class_exists($class)) continue;
            $out[$key] = $class::displayName();
        }
        return $out;
    }

    /**
     * Rejestr runtime — testy / custom providers bez modyfikacji stałej.
     *
     * @param  class-string<BaseAdapter> $class
     */
    public static function registerProvider(string $providerKey, string $class): void
    {
        if (!is_subclass_of($class, BaseAdapter::class)) {
            throw new \InvalidArgumentException(sprintf(
                'Class %s must extend BaseAdapter', $class
            ));
        }
        $ref = new \ReflectionClass(self::class);
        $props = $ref->getConstants();
        // PHP nie pozwala modyfikować stałej w runtime — trzymamy extra map.
        self::$runtimeProviders[$providerKey] = $class;
    }

    /** @var array<string, class-string<BaseAdapter>> */
    private static array $runtimeProviders = [];

    /** @internal testy */
    public static function resetCache(): void
    {
        self::$cache = [];
        self::$tableAvailable = null;
    }

    private static function isTableAvailable(\PDO $pdo): bool
    {
        if (self::$tableAvailable !== null) return self::$tableAvailable;
        try {
            $pdo->query("SELECT 1 FROM sh_tenant_integrations LIMIT 0");
            self::$tableAvailable = true;
        } catch (\Throwable $e) {
            self::$tableAvailable = false;
        }
        return self::$tableAvailable;
    }
}
