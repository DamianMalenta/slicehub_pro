<?php
declare(strict_types=1);

require_once __DIR__ . '/AssetResolver.php';

/**
 * SliceHub · Core · SceneResolver
 *
 * Jedno źródło prawdy dla wizualnego kontraktu dań i kategorii. Nadbudowa na
 * AssetResolver — ten odpowiada za surowe URL-e, ten za pełną scenę.
 *
 * Hierarchia priorytetów (top → bottom):
 *   1. sh_atelier_scenes.spec_json                         (kanoniczna scena Director)
 *   2. sh_visual_layers (legacy compositor)                (warstwy historyczne)
 *   3. sh_asset_links (role=hero)                          (zdjęcie z Asset Studio)
 *   4. sh_menu_items.image_url                             (legacy fallback)
 *
 * Kontrakt: NIGDY nie rzuca wyjątku. Gdy tabele m022 nie istnieją (np. świeża
 * baza przed setup_database.php), cicho wraca do danych z wcześniejszych
 * migracji lub pustego kontraktu.
 *
 * Usage:
 *   $contract = SceneResolver::resolveDishVisualContract($pdo, 1, 'PIZZA_MARG');
 *   echo $contract['hero_url'];
 *   echo $contract['scene_meta']['template']['ascii_key'];
 *
 *   $cat = SceneResolver::resolveCategoryScene($pdo, 1, 5);
 *   foreach ($cat['items'] as $dish) { ... }
 *
 * Metody publiczne:
 *   - resolveDishVisualContract(pdo, tenantId, sku, channel?)  → pełny kontrakt dania
 *   - resolveCategoryScene(pdo, tenantId, categoryId)          → scena kategorii + lista dań
 *   - resolveCategoryStyle(pdo, tenantId, categoryId)          → aktywny styl kategorii
 *   - batchResolveForCategory(pdo, tenantId, categoryId)       → batch kontraktów wszystkich dań
 */
final class SceneResolver
{
    // Cache per-request — unikamy ponownych zapytań INFORMATION_SCHEMA i template lookup.
    private static ?bool $hasM022 = null;

    /** @var array<string,array|false> ascii_key → row | false jeśli brak */
    private static array $templateCache = [];

    /** @var array<string,array|false> ascii_key → row | false jeśli brak */
    private static array $styleCache = [];

    /** @var array<int,bool> tenant_id → bool (czy mamy aktywny styl dla jakiejkolwiek kategorii) */
    private static array $tenantStyleCache = [];

    // ── Helper: feature detection ───────────────────────────────────────────

    /**
     * Czy tabele migracji 022 (sh_scene_templates, sh_style_presets, sh_atelier_scenes + ext)
     * istnieją? Cache'owane per-request.
     */
    public static function isReady(PDO $pdo): bool
    {
        if (self::$hasM022 !== null) {
            return self::$hasM022;
        }
        try {
            $stmt = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME IN ('sh_scene_templates','sh_style_presets','sh_atelier_scenes')"
            );
            self::$hasM022 = ((int)$stmt->fetchColumn() === 3);
        } catch (\Throwable $e) {
            self::$hasM022 = false;
        }
        return self::$hasM022;
    }

    // ── Helper: template + style lookup (cache'owane) ───────────────────────

    /**
     * Zwraca template z sh_scene_templates po ascii_key. null jeśli nie istnieje.
     * Zwraca wiersz w wersji "lean" — pola przydatne frontendowi/integracjom.
     *
     * @return array<string,mixed>|null
     */
    public static function getSceneTemplate(PDO $pdo, string $asciiKey): ?array
    {
        if ($asciiKey === '' || !self::isReady($pdo)) return null;
        if (isset(self::$templateCache[$asciiKey])) {
            return self::$templateCache[$asciiKey] ?: null;
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT id, ascii_key, name, kind,
                        stage_preset_json, composition_schema_json,
                        available_cameras_json, available_luts_json, atmospheric_effects_json,
                        photographer_brief_md, pipeline_preset_json,
                        placeholder_asset_id, default_style_id,
                        is_system
                 FROM sh_scene_templates
                 WHERE ascii_key = :k AND is_active = 1
                 ORDER BY (tenant_id = 0) ASC
                 LIMIT 1"
            );
            $stmt->execute([':k' => $asciiKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                self::$templateCache[$asciiKey] = false;
                return null;
            }
            $out = [
                'id'                       => (int)$row['id'],
                'ascii_key'                => (string)$row['ascii_key'],
                'name'                     => (string)$row['name'],
                'kind'                     => (string)$row['kind'],
                'stage_preset'             => self::jsonOrNull($row['stage_preset_json']),
                'composition_schema'       => self::jsonOrNull($row['composition_schema_json']),
                'available_cameras'        => self::jsonOrNull($row['available_cameras_json']) ?? [],
                'available_luts'           => self::jsonOrNull($row['available_luts_json']) ?? [],
                'atmospheric_effects'      => self::jsonOrNull($row['atmospheric_effects_json']) ?? [],
                'photographer_brief_md'    => $row['photographer_brief_md'] ?? null,
                'pipeline_preset'          => self::jsonOrNull($row['pipeline_preset_json']),
                'default_style_id'         => isset($row['default_style_id']) ? (int)$row['default_style_id'] : null,
                'is_system'                => (bool)$row['is_system'],
            ];
            self::$templateCache[$asciiKey] = $out;
            return $out;
        } catch (\Throwable $e) {
            self::$templateCache[$asciiKey] = false;
            return null;
        }
    }

    /**
     * Zwraca style preset z sh_style_presets po ascii_key lub ID.
     *
     * @param  string|int  $keyOrId
     * @return array<string,mixed>|null
     */
    public static function getStylePreset(PDO $pdo, $keyOrId): ?array
    {
        if (!self::isReady($pdo)) return null;
        $cacheKey = is_int($keyOrId) ? ('#' . $keyOrId) : (string)$keyOrId;
        if ($cacheKey === '' || $cacheKey === '#0') return null;
        if (isset(self::$styleCache[$cacheKey])) {
            return self::$styleCache[$cacheKey] ?: null;
        }
        try {
            if (is_int($keyOrId)) {
                $stmt = $pdo->prepare(
                    "SELECT id, ascii_key, name, cinema_reference,
                            color_palette_json, font_family, motion_preset, default_lut,
                            ambient_audio_ascii_key, thumbnail_asset_id
                     FROM sh_style_presets
                     WHERE id = :k AND is_active = 1 LIMIT 1"
                );
                $stmt->execute([':k' => $keyOrId]);
            } else {
                $stmt = $pdo->prepare(
                    "SELECT id, ascii_key, name, cinema_reference,
                            color_palette_json, font_family, motion_preset, default_lut,
                            ambient_audio_ascii_key, thumbnail_asset_id
                     FROM sh_style_presets
                     WHERE ascii_key = :k AND is_active = 1
                     ORDER BY (tenant_id = 0) ASC LIMIT 1"
                );
                $stmt->execute([':k' => $keyOrId]);
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                self::$styleCache[$cacheKey] = false;
                return null;
            }
            $out = [
                'id'                => (int)$row['id'],
                'ascii_key'         => (string)$row['ascii_key'],
                'name'              => (string)$row['name'],
                'cinema_reference'  => $row['cinema_reference'] ?? null,
                'palette'           => self::jsonOrNull($row['color_palette_json']),
                'font_family'       => $row['font_family'] ?? null,
                'motion_preset'     => $row['motion_preset'] ?? 'spring',
                'default_lut'       => $row['default_lut'] ?? null,
                'ambient_audio_key' => $row['ambient_audio_ascii_key'] ?? null,
                'thumbnail_asset_id'=> isset($row['thumbnail_asset_id']) ? (int)$row['thumbnail_asset_id'] : null,
            ];
            self::$styleCache[$cacheKey] = $out;
            // Zapisz też pod drugim kluczem (ID ↔ ascii_key cross-index)
            self::$styleCache[is_int($keyOrId) ? (string)$out['ascii_key'] : '#' . $out['id']] = $out;
            return $out;
        } catch (\Throwable $e) {
            self::$styleCache[$cacheKey] = false;
            return null;
        }
    }

    // ── Helper: Modifier Visual Slots (m024 · Faza 2.9) ─────────────────────

    /**
     * Zwraca mapę wizualnych slotów modyfikatorów dla listy `$modSkus`.
     * Czyta z `sh_asset_links` (entity_type='modifier', role IN layer_top_down/modifier_hero)
     * — źródła ustawianego przez Menu Studio Modifier Editor (sekcja „Surface — wizualne sloty").
     *
     * Dodatkowo odczytuje `has_visual_impact` z sh_modifiers (migracja 024).
     *
     * Format wyjścia:
     * ```
     * {
     *   "OPT_BACON": {
     *     "hasVisualImpact": true,
     *     "layerAsset": { "assetId": 42, "asciiKey": "bacon_scatter", "url": "/..", "zIndex": 45 },
     *     "heroAsset":  { "assetId": 43, "asciiKey": "bacon_hero",    "url": "/..", "zIndex": 90 }
     *   }
     * }
     * ```
     *
     * Zwraca tylko SKU dla których istnieje *jakikolwiek* slot albo flaga hasVisualImpact=0.
     * Nieistniejące / pozbawione slotów SKU są pomijane — frontend traktuje brak slotu
     * jako „modyfikator niewidoczny w wizualizacji" (klient wybiera go z listy, ale nie pojawia
     * się bubble/scatter na scenie).
     *
     * Bezpieczne: nigdy nie rzuca (null/[] przy brakach tabel).
     *
     * @param  PDO   $pdo
     * @param  int   $tenantId
     * @param  array<int,string>  $modSkus  lista ascii_key modyfikatorów
     * @return array<string,array<string,mixed>>
     */
    public static function resolveModifierVisuals(PDO $pdo, int $tenantId, array $modSkus): array
    {
        $modSkus = array_values(array_unique(array_filter(array_map(
            static fn($s) => is_string($s) ? trim($s) : '',
            $modSkus
        ), static fn($s) => $s !== '')));

        if (empty($modSkus) || $tenantId <= 0) {
            return [];
        }

        $out = [];

        // 1. has_visual_impact (m024) — fetch tylko jeśli kolumna istnieje
        $hasVisualImpactMap = [];
        if (self::colExists($pdo, 'sh_modifiers', 'has_visual_impact')) {
            try {
                $ph = implode(',', array_fill(0, count($modSkus), '?'));
                $stmt = $pdo->prepare(
                    "SELECT m.ascii_key, m.has_visual_impact
                     FROM sh_modifiers m
                     INNER JOIN sh_modifier_groups mg ON mg.id = m.group_id AND mg.tenant_id = ?
                     WHERE m.ascii_key IN ($ph) AND m.is_deleted = 0"
                );
                $stmt->execute(array_merge([$tenantId], $modSkus));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $hasVisualImpactMap[(string)$r['ascii_key']] = (bool)((int)($r['has_visual_impact'] ?? 1));
                }
            } catch (\Throwable $e) {
                // cicho
            }
        }

        // 2. sh_asset_links — sloty layer_top_down + modifier_hero
        //    Wymaga by AssetResolver/m021 były gotowe (sh_asset_links + sh_assets).
        if (!AssetResolver::isReady($pdo)) {
            // Brak library m021 — zwracamy tylko flagi hasVisualImpact (dla hookoów frontu).
            foreach ($hasVisualImpactMap as $sku => $flag) {
                $out[$sku] = [
                    'hasVisualImpact' => $flag,
                    'layerAsset'      => null,
                    'heroAsset'       => null,
                ];
            }
            return $out;
        }

        // M031 · cook_state bias — preferuj 'cooked' dla layer_top_down,
        // 'raw'/'either' dla modifier_hero. Kolumna istnieje od m031; fallback
        // na selekcję bez cook_state jeśli kolumny nie ma (świeża instalacja).
        $hasCookState = self::colExists($pdo, 'sh_assets', 'cook_state');
        $cookSelect   = $hasCookState ? ', a.cook_state' : '';
        // ORDER BY: preferuj cooked dla layerów, raw/either dla hero, potem sort_order.
        // W PHP dobieramy "najlepszy" asset per (mod_sku, role) z uwzględnieniem cook_state.
        $orderCooked = $hasCookState
            ? "(a.cook_state = 'cooked') DESC, (a.cook_state = 'either') DESC, (a.cook_state = 'raw') DESC, "
            : '';
        $orderRaw    = $hasCookState
            ? "(a.cook_state = 'raw') DESC, (a.cook_state = 'either') DESC, (a.cook_state = 'cooked') DESC, "
            : '';

        try {
            $ph = implode(',', array_fill(0, count($modSkus), '?'));
            // Dwa zapytania rozdzielone po roli — każde z własnym ORDER BY cook_state.
            // Unia zamiast jednego SELECTu, bo MySQL nie pozwala na CASE ORDER BY zależne od row.role.
            $sql = "(SELECT al.entity_ref AS mod_sku, al.role, al.asset_id,
                            a.ascii_key, a.storage_url, a.width_px, a.height_px, a.z_order_hint{$cookSelect}
                     FROM sh_asset_links al
                     INNER JOIN sh_assets a ON a.id = al.asset_id AND a.is_active = 1 AND a.deleted_at IS NULL
                     WHERE al.tenant_id = ? AND al.entity_type = 'modifier'
                       AND al.entity_ref IN ($ph)
                       AND al.role = 'layer_top_down'
                       AND al.is_active = 1 AND al.deleted_at IS NULL
                     ORDER BY {$orderCooked}al.sort_order ASC, al.id DESC)
                    UNION ALL
                    (SELECT al.entity_ref AS mod_sku, al.role, al.asset_id,
                            a.ascii_key, a.storage_url, a.width_px, a.height_px, a.z_order_hint{$cookSelect}
                     FROM sh_asset_links al
                     INNER JOIN sh_assets a ON a.id = al.asset_id AND a.is_active = 1 AND a.deleted_at IS NULL
                     WHERE al.tenant_id = ? AND al.entity_type = 'modifier'
                       AND al.entity_ref IN ($ph)
                       AND al.role = 'modifier_hero'
                       AND al.is_active = 1 AND al.deleted_at IS NULL
                     ORDER BY {$orderRaw}al.sort_order ASC, al.id DESC)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge(
                [$tenantId], $modSkus,
                [$tenantId], $modSkus
            ));

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $msku = (string)$row['mod_sku'];
                $role = (string)$row['role'];
                $url  = AssetResolver::publicUrl((string)$row['storage_url']);
                if ($url === null) continue;

                if (!isset($out[$msku])) {
                    $out[$msku] = [
                        'hasVisualImpact' => $hasVisualImpactMap[$msku] ?? true,
                        'layerAsset'      => null,
                        'heroAsset'       => null,
                    ];
                }

                $asset = [
                    'assetId'  => (int)$row['asset_id'],
                    'asciiKey' => (string)$row['ascii_key'],
                    'url'      => $url,
                    'width'    => $row['width_px']  !== null ? (int)$row['width_px']  : null,
                    'height'   => $row['height_px'] !== null ? (int)$row['height_px'] : null,
                    'zIndex'   => isset($row['z_order_hint']) ? (int)$row['z_order_hint'] : ($role === 'layer_top_down' ? 45 : 90),
                    'cookState' => $hasCookState ? (string)($row['cook_state'] ?? 'either') : 'either',
                ];

                // Pierwszy slot każdego rodzaju wygrywa (cooked-bias ORDER BY już posortował).
                if ($role === 'layer_top_down' && $out[$msku]['layerAsset'] === null) {
                    $out[$msku]['layerAsset'] = $asset;
                } elseif ($role === 'modifier_hero' && $out[$msku]['heroAsset'] === null) {
                    $out[$msku]['heroAsset'] = $asset;
                }
            }
        } catch (\Throwable $e) {
            // cicho
        }

        // Dociągnij SKU które mają has_visual_impact ale nie mają assetów (np. flaga hasVisualImpact=0)
        foreach ($hasVisualImpactMap as $sku => $flag) {
            if (!isset($out[$sku])) {
                $out[$sku] = [
                    'hasVisualImpact' => $flag,
                    'layerAsset'      => null,
                    'heroAsset'       => null,
                ];
            }
        }

        return $out;
    }

    // ── Helper: Scene Kit assets (m023) ─────────────────────────────────────

    /**
     * Zwraca Scene Kit assety dla danego template. Template przechowuje tablice
     * asset_id w `scene_kit_assets_json` pod kluczami: backgrounds, props, lights, badges.
     * Ta metoda JOIN-uje te ID z sh_assets i zwraca pełne rekordy.
     *
     * Format wyjścia:
     * ```
     * {
     *   backgrounds: [{ id, ascii_key, url, width, height, mime, sub_type }, ...],
     *   props:       [...],
     *   lights:      [...],
     *   badges:      [...]
     * }
     * ```
     *
     * Gdy scene_kit_assets_json jest null lub pusty — zwraca strukturę z pustymi tablicami.
     * Faza 2.2 wypełni rzeczywistymi asset_id.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function getSceneKitAssets(PDO $pdo, string $templateAsciiKey): array
    {
        $empty = ['backgrounds' => [], 'props' => [], 'lights' => [], 'badges' => []];
        if ($templateAsciiKey === '' || !self::isReady($pdo)) return $empty;

        $template = self::getSceneTemplate($pdo, $templateAsciiKey);
        if (!$template) return $empty;

        try {
            $stmt = $pdo->prepare(
                "SELECT scene_kit_assets_json FROM sh_scene_templates
                 WHERE id = :id LIMIT 1"
            );
            $stmt->execute([':id' => $template['id']]);
            $raw = $stmt->fetchColumn();
            $kit = self::jsonOrNull($raw);
            if (!is_array($kit)) return $empty;

            // Zbierz wszystkie asset_id z 4 kategorii
            $allIds = [];
            $byKind = [];
            foreach (['backgrounds', 'props', 'lights', 'badges'] as $kind) {
                $ids = isset($kit[$kind]) && is_array($kit[$kind]) ? $kit[$kind] : [];
                $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
                $byKind[$kind] = $ids;
                $allIds = array_merge($allIds, $ids);
            }
            $allIds = array_values(array_unique($allIds));

            if (!$allIds) return $empty;

            // Batch fetch z sh_assets
            $ph = implode(',', array_fill(0, count($allIds), '?'));
            $stmtA = $pdo->prepare(
                "SELECT id, ascii_key, storage_url, width_px, height_px, mime_type,
                        category, sub_type, z_order_hint
                 FROM sh_assets
                 WHERE id IN ($ph) AND is_active = 1 AND deleted_at IS NULL"
            );
            $stmtA->execute($allIds);

            $assetsById = [];
            foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $url = AssetResolver::publicUrl((string)$a['storage_url']);
                if ($url === null) continue;
                $assetsById[(int)$a['id']] = [
                    'id'         => (int)$a['id'],
                    'ascii_key'  => (string)$a['ascii_key'],
                    'url'        => $url,
                    'width'      => $a['width_px']  !== null ? (int)$a['width_px']  : null,
                    'height'     => $a['height_px'] !== null ? (int)$a['height_px'] : null,
                    'mime'       => $a['mime_type'] ?: null,
                    'category'   => $a['category']  ?: null,
                    'sub_type'   => $a['sub_type']  ?: null,
                    'z_order'    => isset($a['z_order_hint']) ? (int)$a['z_order_hint'] : 50,
                ];
            }

            // Zbuduj wyjście preservując kolejność z scene_kit_assets_json
            $out = ['backgrounds' => [], 'props' => [], 'lights' => [], 'badges' => []];
            foreach ($byKind as $kind => $ids) {
                foreach ($ids as $id) {
                    if (isset($assetsById[$id])) {
                        $out[$kind][] = $assetsById[$id];
                    }
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    // ── Helper: active style for category (sh_category_styles) ──────────────

    /**
     * Zwraca aktywny styl kategorii lub null jeśli brak.
     *
     * @return array<string,mixed>|null  Style preset record (patrz getStylePreset)
     */
    public static function resolveCategoryStyle(PDO $pdo, int $tenantId, int $categoryId): ?array
    {
        if (!self::isReady($pdo)) return null;
        try {
            $stmt = $pdo->prepare(
                "SELECT style_preset_id FROM sh_category_styles
                 WHERE tenant_id = :tid AND category_id = :cid AND is_active = 1
                 ORDER BY applied_at DESC LIMIT 1"
            );
            $stmt->execute([':tid' => $tenantId, ':cid' => $categoryId]);
            $styleId = $stmt->fetchColumn();
            if (!$styleId) return null;
            return self::getStylePreset($pdo, (int)$styleId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── Helper: active scene trigger (runtime check) ────────────────────────

    /**
     * Sprawdza czy jakiś trigger jest aktywny dla tej sceny TERAZ.
     * Obsługuje: date_range, time_range, day_of_week.
     * Weather pomijamy w Fazie 1 (Faza 4 wpina OpenWeather).
     *
     * @return array<string,mixed>|null  { trigger_id, priority, matched_rules }
     */
    public static function checkActiveTrigger(PDO $pdo, int $tenantId, int $sceneId): ?array
    {
        if (!self::isReady($pdo)) return null;
        try {
            $stmt = $pdo->prepare(
                "SELECT id, trigger_rule_json, priority
                 FROM sh_scene_triggers
                 WHERE tenant_id = :tid AND scene_id = :sid AND is_active = 1
                   AND (valid_from IS NULL OR valid_from <= NOW())
                   AND (valid_to   IS NULL OR valid_to   >= NOW())
                 ORDER BY priority DESC"
            );
            $stmt->execute([':tid' => $tenantId, ':sid' => $sceneId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return null;

            $now = new \DateTimeImmutable('now');
            $nowHM = $now->format('H:i');
            $nowDate = $now->format('Y-m-d');
            $nowDow = (int)$now->format('N'); // 1=Mon..7=Sun

            foreach ($rows as $r) {
                $rule = self::jsonOrNull($r['trigger_rule_json']);
                if (!is_array($rule)) continue;

                // date_range: { from: 'YYYY-MM-DD', to: 'YYYY-MM-DD' } albo { from: 'MM-DD', to: 'MM-DD' }
                if (isset($rule['date_range']) && is_array($rule['date_range'])) {
                    $from = (string)($rule['date_range']['from'] ?? '');
                    $to   = (string)($rule['date_range']['to']   ?? '');
                    if ($from !== '' && $to !== '') {
                        // Normalizuj do YYYY-MM-DD dla tego roku jeśli tylko MM-DD
                        if (strlen($from) === 5) $from = $now->format('Y') . '-' . $from;
                        if (strlen($to)   === 5) $to   = $now->format('Y') . '-' . $to;
                        if ($nowDate < $from || $nowDate > $to) continue;
                    }
                }

                // time_range: { start: 'HH:MM', end: 'HH:MM' }
                if (isset($rule['time_range']) && is_array($rule['time_range'])) {
                    $start = (string)($rule['time_range']['start'] ?? '');
                    $end   = (string)($rule['time_range']['end']   ?? '');
                    if ($start !== '' && $end !== '' && ($nowHM < $start || $nowHM > $end)) continue;
                }

                // day_of_week: { days: [1..7] }
                if (isset($rule['day_of_week']) && is_array($rule['day_of_week']['days'] ?? null)) {
                    $days = array_map('intval', $rule['day_of_week']['days']);
                    if (!in_array($nowDow, $days, true)) continue;
                }

                // Trigger matches
                return [
                    'trigger_id' => (int)$r['id'],
                    'priority'   => (int)$r['priority'],
                    'matched_rule' => $rule,
                ];
            }
        } catch (\Throwable $e) {
            // cicho
        }
        return null;
    }

    // ── Core: resolveDishVisualContract ─────────────────────────────────────

    /**
     * Pełny wizualny kontrakt dla jednego dania. Używane przez:
     *   - api/online/engine.php get_dish
     *   - api/backoffice/api_menu_studio.php get_item_details (mini-miniatura)
     *   - api/pos/engine.php (lista)
     *   - przyszły api/public/surface.php
     *
     * @param  PDO     $pdo
     * @param  int     $tenantId
     * @param  string  $sku        sh_menu_items.ascii_key
     * @param  string  $channel    'DINE_IN' / 'TAKEAWAY' / 'DELIVERY' / 'POS' (info, nie wpływa na wizualizację — tylko logowanie)
     * @return array<string,mixed>|null   null jeśli danie nie istnieje
     */
    public static function resolveDishVisualContract(
        PDO $pdo,
        int $tenantId,
        string $sku,
        string $channel = 'DELIVERY'
    ): ?array {
        $sku = trim($sku);
        if ($sku === '' || $tenantId <= 0) return null;

        // 1. Base row z sh_menu_items (musi istnieć)
        try {
            $stmt = $pdo->prepare(
                "SELECT id, ascii_key AS sku, name, description, category_id, image_url,
                        COALESCE(composition_profile, 'static_hero') AS composition_profile
                 FROM sh_menu_items
                 WHERE tenant_id = :tid AND ascii_key = :sku
                   AND is_active = 1 AND is_deleted = 0
                 LIMIT 1"
            );
            $stmt->execute([':tid' => $tenantId, ':sku' => $sku]);
            $dish = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Fallback gdy composition_profile nie istnieje (migracja 022 nie przeszła)
            try {
                $stmt = $pdo->prepare(
                    "SELECT id, ascii_key AS sku, name, description, category_id, image_url
                     FROM sh_menu_items
                     WHERE tenant_id = :tid AND ascii_key = :sku
                       AND is_active = 1 AND is_deleted = 0
                     LIMIT 1"
                );
                $stmt->execute([':tid' => $tenantId, ':sku' => $sku]);
                $dish = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($dish) $dish['composition_profile'] = 'static_hero';
            } catch (\Throwable $e2) {
                return null;
            }
        }
        if (!$dish) return null;

        $dishId = (int)$dish['id'];
        $categoryId = (int)($dish['category_id'] ?? 0);
        $compositionProfile = (string)$dish['composition_profile'];

        // 2. Hero URL — priorytet sh_asset_links > sh_menu_items.image_url
        $heroInfo = AssetResolver::resolveHero($pdo, $tenantId, $sku);
        $heroUrl  = $heroInfo['url'] ?? AssetResolver::publicUrl($dish['image_url'] ?? null);
        $heroMeta = $heroInfo ? [
            'width'  => $heroInfo['width']  ?? null,
            'height' => $heroInfo['height'] ?? null,
            'mime'   => $heroInfo['mime']   ?? null,
            'source' => $heroInfo['source'] ?? 'asset_link',
        ] : ($heroUrl ? ['source' => 'legacy_image_url'] : null);

        // 3. Scene spec z sh_atelier_scenes (jeśli istnieje)
        $sceneSpec = null;
        $sceneMeta = [
            'scene_id'            => null,
            'template'            => null,
            'active_style'        => null,
            'active_camera'       => null,
            'active_lut'          => null,
            'atmospheric_effects' => [],
            'active_trigger'      => null,
        ];

        if (self::isReady($pdo)) {
            try {
                $stmt = $pdo->prepare(
                    "SELECT id, spec_json, version,
                            COALESCE(scene_kind, 'item') AS scene_kind,
                            template_id, active_style_id, active_camera_preset,
                            active_lut, atmospheric_effects_enabled_json
                     FROM sh_atelier_scenes
                     WHERE tenant_id = :tid AND item_sku = :sku
                     LIMIT 1"
                );
                $stmt->execute([':tid' => $tenantId, ':sku' => $sku]);
                $sceneRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($sceneRow) {
                    $sceneMeta['scene_id'] = (int)$sceneRow['id'];
                    $sceneSpec = self::jsonOrNull($sceneRow['spec_json']);

                    // active_camera / active_lut / atmospheric_effects
                    $sceneMeta['active_camera'] = $sceneRow['active_camera_preset'] ?: null;
                    $sceneMeta['active_lut']    = $sceneRow['active_lut'] ?: null;
                    $sceneMeta['atmospheric_effects'] =
                        self::jsonOrNull($sceneRow['atmospheric_effects_enabled_json']) ?? [];

                    // active_style: scene-level > category-level > template default
                    if (!empty($sceneRow['active_style_id'])) {
                        $sceneMeta['active_style'] = self::getStylePreset($pdo, (int)$sceneRow['active_style_id']);
                    }

                    // Check active trigger
                    $sceneMeta['active_trigger'] = self::checkActiveTrigger($pdo, $tenantId, (int)$sceneRow['id']);
                }
            } catch (\Throwable $e) {
                // cicho — brak sceny = brak problemu
            }
        }

        // 4. Template resolution
        $template = self::getSceneTemplate($pdo, $compositionProfile);
        $sceneMeta['template'] = $template ? [
            'id'                    => $template['id'],
            'ascii_key'             => $template['ascii_key'],
            'name'                  => $template['name'],
            'kind'                  => $template['kind'],
            'available_cameras'     => $template['available_cameras'],
            'available_luts'        => $template['available_luts'],
            'atmospheric_effects'   => $template['atmospheric_effects'],
            'photographer_brief_md' => $template['photographer_brief_md'],
        ] : null;

        // 5. Active style cascade: scene > category > template default
        if ($sceneMeta['active_style'] === null) {
            $categoryStyle = $categoryId > 0
                ? self::resolveCategoryStyle($pdo, $tenantId, $categoryId)
                : null;
            if ($categoryStyle !== null) {
                $sceneMeta['active_style'] = $categoryStyle;
                $sceneMeta['active_style_source'] = 'category';
            } elseif ($template && !empty($template['default_style_id'])) {
                $sceneMeta['active_style'] = self::getStylePreset($pdo, (int)$template['default_style_id']);
                $sceneMeta['active_style_source'] = 'template_default';
            } else {
                $sceneMeta['active_style_source'] = null;
            }
        } else {
            $sceneMeta['active_style_source'] = 'scene';
        }

        // 6. Layers — sh_atelier_scenes ma priorytet, fallback na sh_visual_layers
        $layers = self::resolveLayers($pdo, $tenantId, $sku, $sceneSpec);

        // 7. Promotion slots (schema only — Faza 4 wpina logikę liczenia rabatów)
        $promotions = self::resolvePromotionSlots($pdo, $tenantId, $sceneMeta['scene_id']);

        return [
            'sku'                 => (string)$dish['sku'],
            'name'                => (string)$dish['name'],
            'description'         => $dish['description'] ?? null,
            'category_id'         => $categoryId,
            'composition_profile' => $compositionProfile,
            'hero_url'            => $heroUrl,
            'hero_meta'           => $heroMeta,
            'scene_spec'          => $sceneSpec,
            'scene_meta'          => $sceneMeta,
            'layers'              => $layers,
            'promotions'          => $promotions,
            '_meta'               => [
                'resolver' => 'SceneResolver',
                'channel'  => $channel,
                'has_m022' => self::isReady($pdo),
            ],
        ];
    }

    // ── Core: resolveCategoryScene ──────────────────────────────────────────

    /**
     * Scena kategorii (dla layout_mode=grouped/hybrid) + lista dań.
     *
     * @return array<string,mixed>|null
     */
    public static function resolveCategoryScene(PDO $pdo, int $tenantId, int $categoryId): ?array
    {
        if ($categoryId <= 0 || $tenantId <= 0) return null;

        // 1. Category metadata
        try {
            $stmt = $pdo->prepare(
                "SELECT id, name, is_menu,
                        COALESCE(layout_mode, 'legacy_list') AS layout_mode,
                        COALESCE(default_composition_profile, 'static_hero') AS default_composition_profile,
                        category_scene_id
                 FROM sh_categories
                 WHERE tenant_id = :tid AND id = :cid
                 LIMIT 1"
            );
            $stmt->execute([':tid' => $tenantId, ':cid' => $categoryId]);
            $cat = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Fallback bez m022 pól
            try {
                $stmt = $pdo->prepare(
                    "SELECT id, name, is_menu FROM sh_categories
                     WHERE tenant_id = :tid AND id = :cid LIMIT 1"
                );
                $stmt->execute([':tid' => $tenantId, ':cid' => $categoryId]);
                $cat = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($cat) {
                    $cat['layout_mode'] = 'legacy_list';
                    $cat['default_composition_profile'] = 'static_hero';
                    $cat['category_scene_id'] = null;
                }
            } catch (\Throwable $e2) {
                return null;
            }
        }
        if (!$cat) return null;

        // 2. Optional category-level scene (jeśli layout_mode != legacy_list)
        $sceneSpec = null;
        $sceneMeta = [
            'scene_id'      => null,
            'template'      => null,
            'active_style'  => null,
            'active_trigger'=> null,
        ];

        if (!empty($cat['category_scene_id']) && self::isReady($pdo)) {
            try {
                $stmt = $pdo->prepare(
                    "SELECT id, spec_json, template_id, active_style_id
                     FROM sh_atelier_scenes
                     WHERE id = :id AND tenant_id = :tid
                       AND COALESCE(scene_kind, 'item') = 'category'
                     LIMIT 1"
                );
                $stmt->execute([':id' => (int)$cat['category_scene_id'], ':tid' => $tenantId]);
                $sceneRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($sceneRow) {
                    $sceneMeta['scene_id'] = (int)$sceneRow['id'];
                    $sceneSpec = self::jsonOrNull($sceneRow['spec_json']);
                    if (!empty($sceneRow['active_style_id'])) {
                        $sceneMeta['active_style'] = self::getStylePreset($pdo, (int)$sceneRow['active_style_id']);
                    }
                    $sceneMeta['active_trigger'] = self::checkActiveTrigger($pdo, $tenantId, (int)$sceneRow['id']);
                }
            } catch (\Throwable $e) {
                // cicho
            }
        }

        // 3. Jeśli scena jeszcze nie ma stylu, weź z sh_category_styles
        if ($sceneMeta['active_style'] === null) {
            $sceneMeta['active_style'] = self::resolveCategoryStyle($pdo, $tenantId, $categoryId);
        }

        // 4. Items in category (mini-kontrakty)
        $items = self::batchResolveForCategory($pdo, $tenantId, $categoryId);

        return [
            'category_id'                 => (int)$cat['id'],
            'category_name'               => (string)$cat['name'],
            'is_menu'                     => (bool)($cat['is_menu'] ?? 1),
            'layout_mode'                 => (string)$cat['layout_mode'],
            'default_composition_profile' => (string)$cat['default_composition_profile'],
            'scene_spec'                  => $sceneSpec,
            'scene_meta'                  => $sceneMeta,
            'items'                       => $items,
        ];
    }

    // ── Core: batchResolveForCategory ───────────────────────────────────────

    /**
     * Batch resolve kontraktów dla wszystkich dań w kategorii.
     * Dla list (get_menu) — minimalny kontrakt (sku, name, hero_url, composition_profile).
     * Pełne scene_spec + layers tylko w detalu (get_dish).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function batchResolveForCategory(PDO $pdo, int $tenantId, int $categoryId): array
    {
        if ($categoryId <= 0 || $tenantId <= 0) return [];
        try {
            $stmt = $pdo->prepare(
                "SELECT ascii_key AS sku, name, description, image_url, category_id,
                        COALESCE(composition_profile, 'static_hero') AS composition_profile
                 FROM sh_menu_items
                 WHERE tenant_id = :tid AND category_id = :cid
                   AND is_active = 1 AND is_deleted = 0
                 ORDER BY display_order ASC, id ASC"
            );
            $stmt->execute([':tid' => $tenantId, ':cid' => $categoryId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Fallback bez composition_profile
            try {
                $stmt = $pdo->prepare(
                    "SELECT ascii_key AS sku, name, description, image_url, category_id
                     FROM sh_menu_items
                     WHERE tenant_id = :tid AND category_id = :cid
                       AND is_active = 1 AND is_deleted = 0
                     ORDER BY display_order ASC, id ASC"
                );
                $stmt->execute([':tid' => $tenantId, ':cid' => $categoryId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$r) { $r['composition_profile'] = 'static_hero'; }
                unset($r);
            } catch (\Throwable $e2) {
                return [];
            }
        }
        if (!$rows) return [];

        // Batch hero URLs via AssetResolver
        $skus = array_column($rows, 'sku');
        $heroMap = AssetResolver::batchHeroUrls($pdo, $tenantId, $skus);

        // Batch scene_id lookup (żeby wiedzieć czy danie ma dedykowaną scenę)
        $scenesBySku = [];
        if (self::isReady($pdo) && $skus) {
            try {
                $ph = implode(',', array_fill(0, count($skus), '?'));
                $stmt = $pdo->prepare(
                    "SELECT item_sku, id, active_style_id
                     FROM sh_atelier_scenes
                     WHERE tenant_id = ? AND item_sku IN ({$ph})
                       AND COALESCE(scene_kind, 'item') = 'item'"
                );
                $stmt->execute(array_merge([$tenantId], $skus));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $scenesBySku[$r['item_sku']] = [
                        'scene_id'        => (int)$r['id'],
                        'active_style_id' => isset($r['active_style_id']) ? (int)$r['active_style_id'] : null,
                    ];
                }
            } catch (\Throwable $e) {
                // cicho
            }
        }

        // Category-level style (resolve once)
        $categoryStyle = self::resolveCategoryStyle($pdo, $tenantId, $categoryId);

        $out = [];
        foreach ($rows as $r) {
            $sku = (string)$r['sku'];
            $hero = $heroMap[$sku] ?? null;
            $heroUrl = $hero['url'] ?? AssetResolver::publicUrl($r['image_url'] ?? null);

            // Active style: scene > category > null
            $activeStyle = null;
            $scene = $scenesBySku[$sku] ?? null;
            if ($scene && !empty($scene['active_style_id'])) {
                $activeStyle = self::getStylePreset($pdo, (int)$scene['active_style_id']);
            }
            if ($activeStyle === null && $categoryStyle !== null) {
                $activeStyle = $categoryStyle;
            }

            $out[] = [
                'sku'                 => $sku,
                'name'                => (string)$r['name'],
                'description'         => $r['description'] ?? null,
                'category_id'         => (int)$r['category_id'],
                'composition_profile' => (string)$r['composition_profile'],
                'hero_url'            => $heroUrl,
                'has_scene'           => $scene !== null,
                'scene_id'            => $scene['scene_id'] ?? null,
                'active_style'        => $activeStyle,
            ];
        }
        return $out;
    }

    // ── Private: layers resolution ──────────────────────────────────────────

    /**
     * Layers z sh_atelier_scenes (kanoniczne) > sh_visual_layers (legacy).
     *
     * @return array<int,array<string,mixed>>
     */
    private static function resolveLayers(PDO $pdo, int $tenantId, string $sku, ?array $sceneSpec): array
    {
        // Jeśli Director zapisał non-empty pizza.layers, używamy ich
        if (is_array($sceneSpec) && isset($sceneSpec['pizza']['layers']) && is_array($sceneSpec['pizza']['layers'])) {
            $directorLayers = [];
            foreach ($sceneSpec['pizza']['layers'] as $L) {
                if (!is_array($L)) continue;
                if (isset($L['visible']) && $L['visible'] === false) continue;
                $aurl = AssetResolver::publicUrl($L['assetUrl'] ?? null);
                if (!$aurl) continue;
                $directorLayers[] = [
                    'layerSku'   => (string)($L['layerSku']   ?? ''),
                    'assetUrl'   => $aurl,
                    'zIndex'     => (int)($L['zIndex']        ?? 0),
                    'isBase'     => (bool)($L['isBase']       ?? false),
                    'calScale'   => isset($L['calScale'])   ? (float)$L['calScale']   : 1.0,
                    'calRotate'  => isset($L['calRotate'])  ? (int)$L['calRotate']    : 0,
                    'offsetX'    => isset($L['offsetX'])    ? (float)$L['offsetX']    : 0.0,
                    'offsetY'    => isset($L['offsetY'])    ? (float)$L['offsetY']    : 0.0,
                    'source'     => 'director',
                ];
            }
            if ($directorLayers) {
                usort($directorLayers, fn($a, $b) => $a['zIndex'] <=> $b['zIndex']);
                return $directorLayers;
            }
        }

        // Fallback: sh_visual_layers (legacy, działa jak przed m022)
        try {
            // Detect available columns
            $hasGa = self::colExists($pdo, 'sh_global_assets', 'filename');
            $hasHero = self::colExists($pdo, 'sh_visual_layers', 'product_filename');
            $hasCal  = self::colExists($pdo, 'sh_visual_layers', 'cal_scale');
            $hasOff  = self::colExists($pdo, 'sh_visual_layers', 'offset_x');

            $extraCols = '';
            if ($hasHero) $extraCols .= ', vl.product_filename';
            if ($hasCal)  $extraCols .= ', vl.cal_scale, vl.cal_rotate';
            if ($hasOff)  $extraCols .= ', vl.offset_x, vl.offset_y';

            $gaJoin = $hasGa
                ? "LEFT JOIN sh_global_assets ga
                     ON ga.ascii_key = vl.layer_sku
                    AND (ga.tenant_id = 0 OR ga.tenant_id = vl.tenant_id)
                    AND ga.is_active = 1"
                : '';
            $gaCols = $hasGa ? ', ga.filename AS ga_filename' : '';

            $stmt = $pdo->prepare(
                "SELECT vl.layer_sku, vl.asset_filename, vl.z_index, vl.is_base
                        {$extraCols} {$gaCols}
                 FROM sh_visual_layers vl
                 {$gaJoin}
                 WHERE vl.tenant_id = :tid AND vl.item_sku = :sku AND vl.is_active = 1
                 ORDER BY vl.z_index ASC"
            );
            $stmt->execute([':tid' => $tenantId, ':sku' => $sku]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $vl) {
                $gaFile = $vl['ga_filename'] ?? null;
                $assetUrl = $gaFile
                    ? AssetResolver::publicUrl('/uploads/global_assets/' . $gaFile)
                    : AssetResolver::publicUrl('/uploads/visual/' . $tenantId . '/' . ($vl['asset_filename'] ?? ''));
                if (!$assetUrl) continue;
                $out[] = [
                    'layerSku'   => (string)$vl['layer_sku'],
                    'assetUrl'   => $assetUrl,
                    'zIndex'     => (int)$vl['z_index'],
                    'isBase'     => (bool)$vl['is_base'],
                    'calScale'   => isset($vl['cal_scale'])   ? (float)$vl['cal_scale']   : 1.0,
                    'calRotate'  => isset($vl['cal_rotate'])  ? (int)$vl['cal_rotate']    : 0,
                    'offsetX'    => isset($vl['offset_x'])    ? (float)$vl['offset_x']    : 0.0,
                    'offsetY'    => isset($vl['offset_y'])    ? (float)$vl['offset_y']    : 0.0,
                    'source'     => 'visual_layers',
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Private: promotion slots on scene ───────────────────────────────────

    /**
     * Zwraca listę aktywnych promocji przypisanych do sceny (schema only).
     * Logika liczenia rabatu w CartEngine — Faza 4.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function resolvePromotionSlots(PDO $pdo, int $tenantId, ?int $sceneId): array
    {
        if (!$sceneId || !self::isReady($pdo)) return [];
        try {
            $stmt = $pdo->prepare(
                "SELECT sps.id AS slot_id, sps.slot_x, sps.slot_y, sps.slot_z_index, sps.display_order,
                        p.id AS promotion_id, p.ascii_key, p.name, p.rule_kind, p.rule_json,
                        p.badge_text, p.badge_style, p.time_window_json, p.valid_from, p.valid_to
                 FROM sh_scene_promotion_slots sps
                 INNER JOIN sh_promotions p
                   ON p.id = sps.promotion_id
                  AND p.tenant_id = :tid
                  AND p.is_active = 1
                  AND (p.valid_from IS NULL OR p.valid_from <= NOW())
                  AND (p.valid_to   IS NULL OR p.valid_to   >= NOW())
                 WHERE sps.scene_id = :sid AND sps.is_active = 1
                 ORDER BY sps.display_order ASC, sps.slot_z_index ASC"
            );
            $stmt->execute([':tid' => $tenantId, ':sid' => $sceneId]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = [
                    'slot_id'       => (int)$r['slot_id'],
                    'promotion_id'  => (int)$r['promotion_id'],
                    'ascii_key'     => (string)$r['ascii_key'],
                    'name'          => (string)$r['name'],
                    'rule_kind'     => (string)$r['rule_kind'],
                    'rule'          => self::jsonOrNull($r['rule_json']),
                    'badge_text'    => $r['badge_text'] ?? null,
                    'badge_style'   => $r['badge_style'] ?? 'amber',
                    'time_window'   => self::jsonOrNull($r['time_window_json']),
                    'position'      => [
                        'x' => (float)$r['slot_x'],
                        'y' => (float)$r['slot_y'],
                        'z' => (int)$r['slot_z_index'],
                    ],
                    'valid_from'    => $r['valid_from'] ?? null,
                    'valid_to'      => $r['valid_to'] ?? null,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Private: utility helpers ────────────────────────────────────────────

    /**
     * JSON decode z cichym fallbackiem na null.
     * @return mixed|null
     */
    private static function jsonOrNull($raw)
    {
        if ($raw === null || $raw === '' || !is_string($raw)) return null;
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    }

    /** Lekki cache na col exists queries (unikamy spamu INFORMATION_SCHEMA). */
    private static array $colExistsCache = [];

    private static function colExists(PDO $pdo, string $table, string $col): bool
    {
        $key = $table . '.' . $col;
        if (isset(self::$colExistsCache[$key])) return self::$colExistsCache[$key];
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
            );
            $stmt->execute([':t' => $table, ':c' => $col]);
            $exists = ((int)$stmt->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            $exists = false;
        }
        self::$colExistsCache[$key] = $exists;
        return $exists;
    }
}
