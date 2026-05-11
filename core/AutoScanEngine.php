<?php

declare(strict_types=1);

/**
 * SliceHub — Shared AutoScan Engine
 *
 * Centralna logika dopasowania nazw zewnętrznych (z faktur dostawców, KSeF inbox,
 * importów PZ) na wewnętrzne SKU z `sys_items`. Plus self-learning mapping
 * memory poprzez `sh_product_mapping`.
 *
 * Wcześniej: ta logika żyła w `modules/studio/js/studio_recipe.js::autoScan()`,
 * tylko po stronie frontu i tylko dla recept w Studio. F2 (2026-05-11) wyciąga
 * ją do shared serwerowej z 4-stopniowym confidence scoringiem.
 *
 * KONTRAKT (Konstytucja v5 § Prawo IV — Zero Zaufania):
 *   Frontend wysyła tylko `external_name` + opcjonalnie qty/unit.
 *   Cała logika matchingu / confidence / decyzji o auto-accept = SERWER.
 *
 * ARCHITEKTURA (Konstytucja v5 § Prawo II — Bliźniak Cyfrowy):
 *   Mapowanie cross-silo: external_name (string) → internal_sku (string,
 *   klucz znakowy). Nigdy po numerycznym id. Most do silosu wh_/sys_/sh_
 *   wyłącznie przez SKU.
 *
 * CONFIDENCE SCORING (4 poziomy):
 *
 *   100 EXACT    — `LOWER(external_name) == sh_product_mapping.external_name`
 *                  (pełen match po normalizacji). Auto-resolve, brak learn-u
 *                  (już jest mapping).
 *
 *   85  ALIAS    — token z external_name pasuje do `sys_items.search_aliases`
 *                  (PL deklinacje, synonimy). Auto-resolve + AUTO-LEARN
 *                  (dopisanie do sh_product_mapping żeby przyszłe matche były EXACT).
 *
 *   60-80 NAME   — full external_name normalized vs `sys_items.name`:
 *                  - exact = 80
 *                  - startsWith = 70
 *                  - includes = 60
 *                  Sugestia (manual confirm).
 *
 *   40-59 FUZZY  — tokenization + token-level matching per sys_items.
 *                  Sugestia z propozycją (TOP-3 candidates).
 *
 *   <40 NONE     — brak sensownego matcha. Smart-create propozycja
 *                  (utworzenie nowego sys_items).
 *
 * THRESHOLD AUTO-ACCEPT:
 *   Default 70 (per tenant, w `sh_tenant_settings.autoscan_auto_accept_threshold`).
 *   confidence >= threshold → auto-accept w UI procurement.
 *   confidence < threshold → manager musi potwierdzić.
 *
 * NETWORK EFFECT (Self-Learning):
 *   Każda akceptacja matchu (manualna lub ALIAS auto) zapisuje mapping
 *   przez `learnMapping()`. Po pierwszej dostawie od „Eurocash" 80% linii NONE,
 *   manager mapuje. Po drugiej — 90% EXACT (auto). Po piątej — 100% auto.
 *
 * DLA AI:
 *   Engine NIE modyfikuje innych systemów. Tylko READ z sys_items,
 *   sh_product_mapping. Pisze do sh_product_mapping (tylko learnMapping).
 *   Każde query z `tenant_id = :tid` (Konstytucja § Prawo VI Snajper).
 *
 * Sesja F2 · 2026-05-11. Test E2E w
 * `_docs/sessions/2026-05-11_phase_f2_autoscan.md`.
 */
class AutoScanEngine
{
    public const MATCH_EXACT  = 'EXACT';
    public const MATCH_ALIAS  = 'ALIAS';
    public const MATCH_NAME   = 'NAME';
    public const MATCH_FUZZY  = 'FUZZY';
    public const MATCH_NONE   = 'NONE';

    public const CONFIDENCE_EXACT      = 100;
    public const CONFIDENCE_ALIAS      = 85;
    public const CONFIDENCE_NAME_EXACT = 80;
    public const CONFIDENCE_NAME_START = 70;
    public const CONFIDENCE_NAME_PART  = 60;
    public const CONFIDENCE_FUZZY_MAX  = 59;

    public const DEFAULT_AUTO_ACCEPT_THRESHOLD = 70;

    private const MIN_TOKEN_LEN = 3;
    private const MAX_CANDIDATES = 3;

    /** @var array<int, array<int, array<string, mixed>>> Cache index per tenant */
    private static array $indexCache = [];

    /**
     * Match jednej linii z faktury (external_name) na SKU.
     *
     * @return array{
     *   external_name: string,
     *   match_type: string,
     *   confidence: int,
     *   sku: ?string,
     *   sku_name: ?string,
     *   sku_unit: ?string,
     *   should_auto_accept: bool,
     *   should_auto_learn: bool,
     *   candidates: list<array{sku:string,name:string,confidence:int,match_type:string}>
     * }
     */
    public static function match(PDO $pdo, int $tenantId, string $externalName, ?int $autoAcceptThreshold = null): array
    {
        $threshold = $autoAcceptThreshold ?? self::resolveAutoAcceptThreshold($pdo, $tenantId);
        $name = trim($externalName);

        if ($name === '') {
            return self::buildResult($externalName, self::MATCH_NONE, 0, null, null, null, [], $threshold);
        }

        // 1. EXACT — sprawdź sh_product_mapping (poprzednie akceptacje)
        $exactMatch = self::lookupExactMapping($pdo, $tenantId, $name);
        if ($exactMatch !== null) {
            return self::buildResult(
                $externalName, self::MATCH_EXACT, self::CONFIDENCE_EXACT,
                $exactMatch['sku'], $exactMatch['name'], $exactMatch['unit'],
                [], $threshold
            );
        }

        // 2-4. Iterate sys_items index, score per item, return best + TOP-3 candidates
        $index = self::buildProductIndex($pdo, $tenantId);
        if ($index === []) {
            return self::buildResult($externalName, self::MATCH_NONE, 0, null, null, null, [], $threshold);
        }

        $normExt = self::normalizeText($name);
        $tokensExt = self::tokenize($name);

        $scored = [];
        foreach ($index as $item) {
            $score = self::scoreItem($normExt, $tokensExt, $item);
            if ($score['confidence'] > 0) {
                $scored[] = [
                    'sku'        => $item['sku'],
                    'name'       => $item['name'],
                    'unit'       => $item['unit'],
                    'confidence' => $score['confidence'],
                    'match_type' => $score['match_type'],
                ];
            }
        }

        if ($scored === []) {
            return self::buildResult($externalName, self::MATCH_NONE, 0, null, null, null, [], $threshold);
        }

        // Sort desc by confidence
        usort($scored, static fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        $best = $scored[0];
        $candidates = array_slice($scored, 0, self::MAX_CANDIDATES);

        return self::buildResult(
            $externalName, $best['match_type'], $best['confidence'],
            $best['sku'], $best['name'], $best['unit'],
            $candidates, $threshold
        );
    }

    /**
     * Match wielu linii w jednym wywołaniu (np. cała faktura).
     * Wydajne — buduje index 1× per call.
     *
     * @param list<array{external_name:string, qty?:float, unit?:string}> $lines
     * @return list<array<string,mixed>>
     */
    public static function matchBulk(PDO $pdo, int $tenantId, array $lines, ?int $autoAcceptThreshold = null): array
    {
        $threshold = $autoAcceptThreshold ?? self::resolveAutoAcceptThreshold($pdo, $tenantId);
        $out = [];
        foreach ($lines as $idx => $line) {
            $name = (string) ($line['external_name'] ?? '');
            $result = self::match($pdo, $tenantId, $name, $threshold);
            if (isset($line['qty'])) {
                $result['qty'] = (float) $line['qty'];
            }
            if (isset($line['unit'])) {
                $result['input_unit'] = (string) $line['unit'];
            }
            $result['line_index'] = (int) $idx;
            $out[] = $result;
        }
        return $out;
    }

    /**
     * Self-learning: zapisuje mapping external_name → sku do sh_product_mapping.
     * Idempotentne (UNIQUE constraint na tenant+external_name).
     *
     * @return array{success:bool, learned:bool, error?:string}
     */
    public static function learnMapping(PDO $pdo, int $tenantId, string $externalName, string $sku): array
    {
        $name = trim($externalName);
        $skuTrim = trim($sku);
        if ($name === '' || $skuTrim === '') {
            return ['success' => false, 'learned' => false, 'error' => 'external_name i sku są wymagane.'];
        }

        try {
            // Walidacja: SKU musi istnieć w sys_items dla tego tenanta (Prawo VI + cross-silo SKU)
            $stmtCheck = $pdo->prepare(
                "SELECT 1 FROM sys_items
                  WHERE tenant_id = :tid AND sku = :sku
                    AND is_deleted = 0 AND is_active = 1
                  LIMIT 1"
            );
            $stmtCheck->execute([':tid' => $tenantId, ':sku' => $skuTrim]);
            if (!$stmtCheck->fetchColumn()) {
                return ['success' => false, 'learned' => false, 'error' => "SKU '{$skuTrim}' nie istnieje w słowniku surowców (sys_items)."];
            }

            // INSERT IGNORE — idempotentne
            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO sh_product_mapping (tenant_id, external_name, internal_sku)
                 VALUES (:tid, :ext, :sku)"
            );
            $stmt->execute([':tid' => $tenantId, ':ext' => $name, ':sku' => $skuTrim]);
            $learned = $stmt->rowCount() > 0;

            // Invalidate cache (next match() rebuild index, mapping check też się zaktualizuje)
            unset(self::$indexCache[$tenantId]);

            return ['success' => true, 'learned' => $learned];
        } catch (\Throwable $e) {
            error_log('[AutoScanEngine.learnMapping] ' . $e->getMessage());
            return ['success' => false, 'learned' => false, 'error' => 'DB error: ' . $e->getMessage()];
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /** Lookup exact match in sh_product_mapping (case-insensitive). */
    private static function lookupExactMapping(PDO $pdo, int $tenantId, string $externalName): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT m.internal_sku AS sku, s.name, s.base_unit AS unit
               FROM sh_product_mapping m
               JOIN sys_items s
                 ON s.sku = m.internal_sku
                AND s.tenant_id = m.tenant_id
              WHERE m.tenant_id = :tid
                AND LOWER(m.external_name) = LOWER(:ext)
                AND s.is_deleted = 0
                AND s.is_active = 1
              LIMIT 1"
        );
        $stmt->execute([':tid' => $tenantId, ':ext' => $externalName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return [
            'sku'  => (string) $row['sku'],
            'name' => (string) $row['name'],
            'unit' => (string) ($row['unit'] ?? ''),
        ];
    }

    /**
     * Build per-tenant index of active sys_items with normalized name + alias terms.
     * Cached for the request lifecycle.
     */
    private static function buildProductIndex(PDO $pdo, int $tenantId): array
    {
        if (isset(self::$indexCache[$tenantId])) {
            return self::$indexCache[$tenantId];
        }

        $stmt = $pdo->prepare(
            "SELECT sku, name, base_unit, search_aliases
               FROM sys_items
              WHERE tenant_id = :tid
                AND is_deleted = 0
                AND is_active = 1
              ORDER BY sku"
        );
        $stmt->execute([':tid' => $tenantId]);

        $index = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['name'];
            $aliasesRaw = (string) ($row['search_aliases'] ?? '');
            $aliases = $aliasesRaw === ''
                ? []
                : array_filter(array_map('trim', explode(',', $aliasesRaw)), static fn ($a) => $a !== '');

            $index[] = [
                'sku'         => (string) $row['sku'],
                'name'        => $name,
                'unit'        => (string) ($row['base_unit'] ?? ''),
                'name_norm'   => self::normalizeText($name),
                'name_tokens' => self::tokenize($name),
                'aliases'     => array_values($aliases),
                'aliases_norm' => array_map([self::class, 'normalizeText'], array_values($aliases)),
            ];
        }

        self::$indexCache[$tenantId] = $index;
        return $index;
    }

    /**
     * Score one sys_items entry against external name + tokens.
     *
     * @return array{confidence:int, match_type:string}
     */
    private static function scoreItem(string $normExt, array $tokensExt, array $item): array
    {
        $best = 0;
        $type = self::MATCH_NONE;

        // Alias exact match (token === alias) → high confidence
        foreach ($item['aliases_norm'] as $aNorm) {
            if ($aNorm === '') continue;
            if ($aNorm === $normExt) {
                return ['confidence' => self::CONFIDENCE_ALIAS, 'match_type' => self::MATCH_ALIAS];
            }
            foreach ($tokensExt as $tok) {
                if ($aNorm === $tok) {
                    if (self::CONFIDENCE_ALIAS > $best) {
                        $best = self::CONFIDENCE_ALIAS;
                        $type = self::MATCH_ALIAS;
                    }
                }
            }
        }

        // Name-level matching
        $nameNorm = $item['name_norm'];
        if ($nameNorm !== '') {
            if ($nameNorm === $normExt) {
                if (self::CONFIDENCE_NAME_EXACT > $best) {
                    $best = self::CONFIDENCE_NAME_EXACT;
                    $type = self::MATCH_NAME;
                }
            } elseif (str_starts_with($nameNorm, $normExt) || str_starts_with($normExt, $nameNorm)) {
                if (self::CONFIDENCE_NAME_START > $best) {
                    $best = self::CONFIDENCE_NAME_START;
                    $type = self::MATCH_NAME;
                }
            } elseif (str_contains($nameNorm, $normExt) || str_contains($normExt, $nameNorm)) {
                if (self::CONFIDENCE_NAME_PART > $best) {
                    $best = self::CONFIDENCE_NAME_PART;
                    $type = self::MATCH_NAME;
                }
            }
        }

        // Token-level fuzzy: ile tokenów z external_name pasuje do name lub aliasów?
        if ($tokensExt !== [] && $best < self::CONFIDENCE_NAME_PART) {
            $matches = 0;
            $total = count($tokensExt);
            foreach ($tokensExt as $tok) {
                if (strlen($tok) < self::MIN_TOKEN_LEN) continue;
                $hit = false;
                if ($nameNorm !== '' && (str_contains($nameNorm, $tok) || str_contains($tok, $nameNorm))) {
                    $hit = true;
                }
                if (!$hit) {
                    foreach ($item['aliases_norm'] as $aNorm) {
                        if ($aNorm === '') continue;
                        if (str_contains($aNorm, $tok) || str_contains($tok, $aNorm)) {
                            $hit = true;
                            break;
                        }
                    }
                }
                if ($hit) $matches++;
            }
            if ($matches > 0 && $total > 0) {
                $ratio = $matches / $total;
                $fuzzy = (int) round(self::CONFIDENCE_FUZZY_MAX * $ratio);
                if ($fuzzy > $best) {
                    $best = $fuzzy;
                    $type = self::MATCH_FUZZY;
                }
            }
        }

        return ['confidence' => $best, 'match_type' => $type];
    }

    /** Lower + NFD normalize + strip accents (zachowanie zgodne ze studio_recipe.js::_normalizeToken). */
    private static function normalizeText(string $str): string
    {
        $s = mb_strtolower(trim($str), 'UTF-8');
        if (class_exists('Normalizer')) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_D);
            // Strip combining diacritical marks (Unicode category Mn)
            $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s;
        }
        // Fallback strtr — działa też gdy intl extension nie jest dostępna (np. CLI bez ext-intl).
        // Pokrywa najczęstsze diakrytyki PL + popularne UE (DE/FR/ES/CZ/SK/HU/RO).
        $s = strtr($s, [
            // Polish
            'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
            // German
            'ä'=>'a','ö'=>'o','ü'=>'u','ß'=>'ss',
            // French / Spanish / Italian / Portuguese
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','å'=>'a','ā'=>'a',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ē'=>'e',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ī'=>'i',
            'ò'=>'o','ô'=>'o','õ'=>'o','ø'=>'o','ō'=>'o',
            'ù'=>'u','ú'=>'u','û'=>'u','ū'=>'u',
            'ñ'=>'n','ç'=>'c',
            // Czech / Slovak
            'č'=>'c','ď'=>'d','ě'=>'e','ň'=>'n','ř'=>'r','š'=>'s','ť'=>'t','ů'=>'u','ý'=>'y','ž'=>'z',
            // Hungarian
            'ő'=>'o','ű'=>'u',
            // Romanian
            'ă'=>'a','ș'=>'s','ț'=>'t','ş'=>'s','ţ'=>'t',
        ]);
        return $s;
    }

    /** Tokenize on whitespace + punctuation, MIN_TOKEN_LEN. */
    private static function tokenize(string $text): array
    {
        $norm = self::normalizeText($text);
        $parts = preg_split('/[\s,.:;!?()\\/\\-]+/u', $norm) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $t = trim($p);
            if (strlen($t) >= self::MIN_TOKEN_LEN) {
                $out[] = $t;
            }
        }
        return $out;
    }

    private static function resolveAutoAcceptThreshold(PDO $pdo, int $tenantId): int
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT setting_value FROM sh_tenant_settings
                  WHERE tenant_id = :tid AND setting_key = 'autoscan_auto_accept_threshold'
                  LIMIT 1"
            );
            $stmt->execute([':tid' => $tenantId]);
            $value = $stmt->fetchColumn();
            if (is_string($value) && ctype_digit(trim($value))) {
                $n = (int) trim($value);
                if ($n >= 0 && $n <= 100) return $n;
            }
        } catch (\Throwable $e) {
            error_log('[AutoScanEngine.threshold] ' . $e->getMessage());
        }
        return self::DEFAULT_AUTO_ACCEPT_THRESHOLD;
    }

    /**
     * Buduje finalne `array` zgodne z kontraktem zwrotnym.
     */
    private static function buildResult(
        string $externalName,
        string $matchType,
        int $confidence,
        ?string $sku,
        ?string $skuName,
        ?string $skuUnit,
        array $candidates,
        int $threshold
    ): array {
        $shouldAutoAccept = $confidence >= $threshold && $sku !== null;
        $shouldAutoLearn  = $matchType === self::MATCH_ALIAS;

        return [
            'external_name'      => $externalName,
            'match_type'         => $matchType,
            'confidence'         => $confidence,
            'sku'                => $sku,
            'sku_name'           => $skuName,
            'sku_unit'           => $skuUnit,
            'should_auto_accept' => $shouldAutoAccept,
            'should_auto_learn'  => $shouldAutoLearn,
            'threshold'          => $threshold,
            'candidates'         => array_values(array_map(
                static fn (array $c): array => [
                    'sku'        => $c['sku'],
                    'name'       => $c['name'],
                    'unit'       => $c['unit'] ?? '',
                    'confidence' => $c['confidence'],
                    'match_type' => $c['match_type'],
                ],
                $candidates
            )),
        ];
    }
}
