<?php

declare(strict_types=1);

/**
 * SliceHub — Geocoder (F6, 2026-05-11)
 *
 * Lekki klient OpenStreetMap Nominatim z cache w `sh_geocode_cache`.
 *
 * Cel: zamiana tekstowego adresu dostawy na lat/lng zapisane w `sh_orders`,
 * żeby dispatcher widział PRAWDZIWĄ pozycję pinów na mapie (Leaflet),
 * a nie losowy fallback. Konstytucja v5 § Prawo II.
 *
 * Polityka rate-limitów Nominatim:
 *   - 1 request / sekunda / IP
 *   - obowiązkowy User-Agent identyfikujący aplikację
 *   - https://operations.osmfoundation.org/policies/nominatim/
 *
 * Strategia ochronna:
 *   - Cache HIT w sh_geocode_cache (SHA1 znormalizowanego adresu) → 0 zewn. zapytań.
 *   - Limit czasu HTTP: 4s (delivery flow nie może czekać).
 *   - Brak retry — failure ⇒ null lat/lng + quality='none' (dispatcher pokaże fallback).
 *   - Self-host Nominatim w przyszłości: override przez `sh_tenant_settings.geocoder_url`.
 */
class Geocoder
{
    private const DEFAULT_BASE_URL = 'https://nominatim.openstreetmap.org/search';
    private const HTTP_TIMEOUT_SEC = 4;
    private const USER_AGENT       = 'SliceHubOS/1.0 (+https://slicehub.net)';

    /**
     * @return array{lat:?float, lng:?float, provider:string, quality:string, cached:bool}
     */
    public static function geocodeOrCache(PDO $pdo, int $tenantId, string $address): array
    {
        $address = trim($address);
        if ($address === '') {
            return ['lat' => null, 'lng' => null, 'provider' => 'none', 'quality' => 'none', 'cached' => false];
        }

        $hash = sha1(self::normalize($address));

        // 1. Cache lookup
        try {
            $stmt = $pdo->prepare(
                "SELECT lat, lng, provider, quality FROM sh_geocode_cache
                  WHERE tenant_id = :tid AND address_hash = :h LIMIT 1"
            );
            $stmt->execute([':tid' => $tenantId, ':h' => $hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                // Bump hit_count
                $pdo->prepare(
                    "UPDATE sh_geocode_cache SET hit_count = hit_count + 1
                      WHERE tenant_id = :tid AND address_hash = :h"
                )->execute([':tid' => $tenantId, ':h' => $hash]);
                return [
                    'lat'      => $row['lat'] !== null ? (float)$row['lat'] : null,
                    'lng'      => $row['lng'] !== null ? (float)$row['lng'] : null,
                    'provider' => (string)$row['provider'],
                    'quality'  => $row['quality'] !== null ? (string)$row['quality'] : 'cached',
                    'cached'   => true,
                ];
            }
        } catch (\Throwable $e) {
            // Cache table missing → migracja 047 nie wykonana. Lećmy dalej bez cache.
            error_log('[Geocoder] cache miss (table likely missing): ' . $e->getMessage());
        }

        // 2. Network call (Nominatim)
        $result = self::callNominatim($address);

        // 3. Save to cache (best-effort)
        try {
            $pdo->prepare(
                "INSERT INTO sh_geocode_cache
                    (tenant_id, address_hash, address_raw, lat, lng, provider, quality)
                 VALUES
                    (:tid, :h, :raw, :lat, :lng, :prov, :q)
                 ON DUPLICATE KEY UPDATE
                    lat = VALUES(lat), lng = VALUES(lng),
                    provider = VALUES(provider), quality = VALUES(quality),
                    hit_count = hit_count + 1"
            )->execute([
                ':tid'  => $tenantId,
                ':h'    => $hash,
                ':raw'  => $address,
                ':lat'  => $result['lat'],
                ':lng'  => $result['lng'],
                ':prov' => $result['provider'],
                ':q'    => $result['quality'],
            ]);
        } catch (\Throwable $e) {
            error_log('[Geocoder] cache write failed: ' . $e->getMessage());
        }

        $result['cached'] = false;
        return $result;
    }

    /**
     * @return array{lat:?float, lng:?float, provider:string, quality:string}
     */
    private static function callNominatim(string $address): array
    {
        $url = self::DEFAULT_BASE_URL . '?' . http_build_query([
            'q'            => $address,
            'format'       => 'json',
            'limit'        => 1,
            'countrycodes' => 'pl',
            'addressdetails' => 0,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT_SEC,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
        ]);
        $body  = curl_exec($ch);
        $err   = curl_error($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $err !== '' || $code !== 200) {
            error_log("[Geocoder] Nominatim http={$code} err={$err}");
            return ['lat' => null, 'lng' => null, 'provider' => 'nominatim', 'quality' => 'none'];
        }

        $data = json_decode((string)$body, true);
        if (!is_array($data) || !isset($data[0]['lat'], $data[0]['lon'])) {
            return ['lat' => null, 'lng' => null, 'provider' => 'nominatim', 'quality' => 'none'];
        }

        $lat = (float)$data[0]['lat'];
        $lng = (float)$data[0]['lon'];

        // Quality heuristic: type/class hint z Nominatim
        $type = (string)($data[0]['type'] ?? '');
        $quality = in_array($type, ['house', 'building', 'residential'], true) ? 'exact' : 'approximate';

        return ['lat' => $lat, 'lng' => $lng, 'provider' => 'nominatim', 'quality' => $quality];
    }

    private static function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        // Polskie znaki diakrytyczne → ASCII (lekki strtr, zgodnie z AutoScanEngine)
        $s = strtr($s, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }
}
