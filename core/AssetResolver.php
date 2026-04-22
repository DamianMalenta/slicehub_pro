<?php
declare(strict_types=1);

/**
 * SliceHub · Core · AssetResolver
 *
 * Jedno źródło prawdy dla URL-i obrazków przy READ-path. Dwustopniowy resolver:
 *
 *   1. sh_asset_links (canonical od m021) — jeśli istnieje aktywny link, zwracamy go
 *   2. sh_menu_items.image_url / sh_visual_layers.asset_filename / etc. (legacy)
 *
 * Kontrakt: nigdy nie rzuca wyjątku; jeśli tabele m021 nie istnieją (świeża
 * instalacja przed uruchomieniem setup_database.php), cicho wraca do legacy.
 *
 * Usage (post-processor pattern):
 *
 *   $items = [...from SELECT...];
 *   AssetResolver::injectHeros($pdo, $tenantId, $items, 'sku', 'imageUrl');
 *   // imageUrl w $items jest teraz nadpisany wartością z sh_asset_links (jeśli istnieje)
 *
 * Dzięki temu stare SELECT-y zostają niezmienione — zmiana w 1 miejscu
 * (endpoint) daje globalny efekt "wgrany asset pojawia się na storefront".
 */

final class AssetResolver
{
    private static ?bool $hasM021 = null;

    /**
     * Czy tabele z migracji 021 (sh_assets, sh_asset_links) istnieją?
     * Cache'owane per-request, żeby nie zaśmiecać INFORMATION_SCHEMA.
     */
    public static function isReady(PDO $pdo): bool
    {
        if (self::$hasM021 !== null) {
            return self::$hasM021;
        }
        try {
            $stmt = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME IN ('sh_assets', 'sh_asset_links')"
            );
            self::$hasM021 = ((int)$stmt->fetchColumn() === 2);
        } catch (\Throwable $e) {
            self::$hasM021 = false;
        }
        return self::$hasM021;
    }

    /**
     * Normalizuje URL do postaci akceptowalnej przez przeglądarkę od DOCROOT /slicehub/.
     * Obsługuje:
     *   - pełne URL http(s)://    → zwraca bez zmian
     *   - /slicehub/...           → zwraca bez zmian
     *   - /path/...               → prepend /slicehub
     *   - path/...                → prepend /slicehub/
     *   - null / ''               → null
     */
    public static function publicUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $u = trim($url);
        if ($u === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $u)) {
            return $u;
        }
        if (strpos($u, '/slicehub/') === 0 || $u === '/slicehub') {
            return $u;
        }
        if ($u[0] === '/') {
            return '/slicehub' . $u;
        }
        return '/slicehub/' . ltrim($u, '/');
    }

    /**
     * Batch-fetch URL-i hero dla listy dań.
     *
     * @param int[]|string[] $itemSkus  ascii_key[]
     * @return array<string,array{url:string,width:?int,height:?int,mime:?string,source:string}>
     */
    public static function batchHeroUrls(PDO $pdo, int $tenantId, array $itemSkus): array
    {
        $result = [];
        $itemSkus = array_values(array_unique(array_filter(
            array_map(fn($x) => is_string($x) ? trim($x) : (string)$x, $itemSkus),
            fn($x) => $x !== ''
        )));
        if (!$itemSkus || !self::isReady($pdo)) {
            return $result;
        }

        $placeholders = implode(',', array_fill(0, count($itemSkus), '?'));
        $sql = "SELECT al.entity_ref AS sku,
                       a.storage_url, a.width_px, a.height_px, a.mime_type
                FROM sh_asset_links al
                INNER JOIN sh_assets a
                  ON a.id = al.asset_id
                 AND a.is_active = 1 AND a.deleted_at IS NULL
                WHERE al.tenant_id   = ?
                  AND al.entity_type = 'menu_item'
                  AND al.role        = 'hero'
                  AND al.is_active   = 1
                  AND al.deleted_at IS NULL
                  AND al.entity_ref IN ($placeholders)
                ORDER BY al.sort_order ASC, al.updated_at DESC, al.id DESC";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$tenantId], $itemSkus));
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $sku = (string)$row['sku'];
                if (isset($result[$sku])) {
                    continue; // pierwszy po sort_order ASC wygrywa
                }
                $url = self::publicUrl((string)$row['storage_url']);
                if ($url === null) continue;
                $result[$sku] = [
                    'url'    => $url,
                    'width'  => $row['width_px']  !== null ? (int)$row['width_px']  : null,
                    'height' => $row['height_px'] !== null ? (int)$row['height_px'] : null,
                    'mime'   => $row['mime_type'] ?: null,
                    'source' => 'asset_link',
                ];
            }
        } catch (\Throwable $e) {
            // Cicho. Endpoint dalej działa na legacy.
        }
        return $result;
    }

    /**
     * Post-processor: iteruje po liście $items i dla każdego elementu próbuje
     * podmienić $item[$urlField] wartością z sh_asset_links (jeśli aktywny link
     * istnieje). Starą wartość zachowuje jeśli nie ma linku.
     *
     * @param array  &$items     reference — modyfikowane in-place
     * @param string  $skuField  np. 'sku' lub 'ascii_key'
     * @param string  $urlField  np. 'imageUrl' lub 'image_url'
     */
    public static function injectHeros(
        PDO $pdo,
        int $tenantId,
        array &$items,
        string $skuField = 'sku',
        string $urlField = 'imageUrl'
    ): void {
        if (!$items) return;
        $skus = [];
        foreach ($items as $it) {
            if (is_array($it) && isset($it[$skuField]) && $it[$skuField] !== '') {
                $skus[] = (string)$it[$skuField];
            }
        }
        if (!$skus) return;

        $map = self::batchHeroUrls($pdo, $tenantId, $skus);
        if (!$map) return;

        foreach ($items as $k => &$it) {
            if (!is_array($it)) continue;
            $sku = $it[$skuField] ?? null;
            if ($sku !== null && isset($map[(string)$sku])) {
                $it[$urlField] = $map[(string)$sku]['url'];
            } else if (isset($it[$urlField])) {
                // Legacy też normalizujemy przez publicUrl, żeby front dostawał
                // jedną konwencję (z prefixem /slicehub/).
                $normalized = self::publicUrl((string)$it[$urlField]);
                if ($normalized !== null) {
                    $it[$urlField] = $normalized;
                }
            }
        }
    }

    /**
     * Single-item resolver. Użyj gdy zwracasz pojedyncze danie (endpoint /item).
     */
    public static function resolveHero(PDO $pdo, int $tenantId, string $itemSku): ?array
    {
        if ($itemSku === '') return null;
        $map = self::batchHeroUrls($pdo, $tenantId, [$itemSku]);
        return $map[$itemSku] ?? null;
    }
}
