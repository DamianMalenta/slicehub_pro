<?php
declare(strict_types=1);

/**
 * SliceHub Enterprise — Unified Asset Library Engine
 * api/assets/engine.php
 *
 * Jedno okienko (Drop Zone Wizard) do całego asset flow:
 *   sh_assets       — kanoniczny rejestr plików (m021)
 *   sh_asset_links  — n:m encja ⇄ asset (rola, sort_order, display_params_json)
 *
 * Kontrakty:
 *   - JSON body:       { "action": "...", ... }                 (domyślnie)
 *   - Multipart body:  action + plik (dla action=upload)
 *   - Auth: core/auth_guard.php (session lub JWT) + role owner/admin/manager
 *   - Response envelope: { success: bool, data: mixed, message: string }
 *
 * Akcje:
 *   list             — paginacja assetów (filtry: bucket, role_hint, category, sub_type, search,
 *                      cook_state, orphans_only, duplicates_only, missing_category,
 *                      missing_cook_state, large_files)
 *   upload           — multipart upload; dedup SHA-256; zapisuje do uploads/assets/{tid}/{bucket}/
 *   update           — edycja metadata (ascii_key, display_name, tags, role_hint, category,
 *                      sub_type, cook_state, z_order_hint, storage_bucket, regenerate_ascii_key)
 *   soft_delete      — deleted_at = NOW() (Prawo Czwartego Wymiaru)
 *   restore          — deleted_at = NULL
 *   link             — stwórz sh_asset_links (entity_type, entity_ref, role, sort_order, display_params_json)
 *   unlink           — soft-delete linka
 *   list_usage       — dla asset_id: wszystkie aktywne linki z human-friendly nazwami
 *   list_entities    — dict encji do wizarda: menu_items, modifiers, board_companions
 *   bulk_update      — M032 · patch kilku assetów naraz (ids[] + patch)
 *   bulk_soft_delete — M032 · soft-delete kilku assetów naraz (ids[])
 *   duplicate        — M032 · sklonuj asset (ten sam plik, nowy ascii_key, zero linków)
 *   scan_health      — M032 · statystyki porządku (sieroty, duble, braki) + listy id
 *   rename_smart     — M032 · zmień display_name + opcjonalnie regen ascii_key ze sluga
 */

@ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function ae_out(bool $ok, $data = null, ?string $msg = null): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function ae_str(array $input, string $key, string $default = ''): string {
    $v = $input[$key] ?? $default;
    return trim((string)$v);
}

function ae_int(array $input, string $key, int $default = 0): int {
    if (!isset($input[$key])) {
        return $default;
    }
    return (int)$input[$key];
}

function ae_bool(array $input, string $key, bool $default = false): bool {
    if (!isset($input[$key])) {
        return $default;
    }
    $v = $input[$key];
    if (is_bool($v)) {
        return $v;
    }
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1', 'true', 'yes', 'on'], true);
}

/** Sanityzacja ascii_key (pattern zgodny z sh_assets / sh_global_assets). */
function ae_safe_ascii_key(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    if (!preg_match('/^[A-Za-z0-9_+\-]{3,191}$/', $s)) return '';
    return $s;
}

function ae_safe_sub_type(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9_]/', '', $s) ?: '';
    return substr($s, 0, 64);
}

/** Slug [a-z0-9_] do budowy nazwy pliku z user-provided sub_type/category. */
function ae_slug(string $s): string {
    $s = strtolower($s);
    // Normalizacja polskich znaków (bez Intl/iconv deps)
    $s = strtr($s, [
        'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
        'Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ź'=>'z','Ż'=>'z',
    ]);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?: '';
    $s = trim($s, '_');
    return $s === '' ? 'asset' : substr($s, 0, 48);
}

/** Sanityzacja display_name (1..128 znaków, trim, usuń kontrolne). */
function ae_safe_display_name(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s) ?? '';
    return mb_substr($s, 0, 128);
}

/**
 * Sanityzacja tagów — tablica stringów [a-zA-Z0-9ąćęłńóśźż _-], max 12 tagów po 32 znaki.
 * Zwraca JSON string albo null gdy pusta.
 */
function ae_sanitize_tags_json($input): ?string {
    if ($input === null) return null;
    if (is_string($input)) {
        $tmp = json_decode($input, true);
        if (is_array($tmp)) $input = $tmp;
        else $input = array_filter(array_map('trim', explode(',', $input)));
    }
    if (!is_array($input)) return null;
    $out = [];
    foreach ($input as $t) {
        $t = trim((string)$t);
        if ($t === '') continue;
        $t = preg_replace('/[\x00-\x1F\x7F]/u', '', $t) ?? '';
        $t = mb_substr($t, 0, 32);
        if ($t !== '') $out[] = $t;
        if (count($out) >= 12) break;
    }
    if (!$out) return null;
    $out = array_values(array_unique($out));
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

/**
 * Buduje "ładny" ascii_key z kategorii, sub_type i display_name.
 * Format: {category}_{sub_type|slug_of_display_name}[_N] — N = numer gdy kolizja.
 * Unikalny per tenant_id.
 */
function ae_build_pretty_ascii_key(PDO $pdo, int $tenantId, string $category, string $subType, string $displayName, int $excludeId = 0): string {
    $catSlug = ae_slug($category !== '' ? $category : 'misc');
    $nameSlug = '';
    if ($subType !== '') $nameSlug = ae_slug($subType);
    if ($nameSlug === '' && $displayName !== '') $nameSlug = ae_slug($displayName);
    if ($nameSlug === '') $nameSlug = 'item';
    $base = $catSlug . '_' . $nameSlug;
    // Ucinamy do bezpiecznej długości
    $base = substr($base, 0, 180);
    // Znajdź wolny wariant base, base_2, base_3...
    $stmt = $pdo->prepare('SELECT id FROM sh_assets WHERE tenant_id = :tid AND ascii_key = :ak AND id <> :ex LIMIT 1');
    for ($i = 1; $i <= 999; $i++) {
        $candidate = $i === 1 ? $base : ($base . '_' . $i);
        $stmt->execute([':tid' => $tenantId, ':ak' => $candidate, ':ex' => $excludeId]);
        if (!$stmt->fetchColumn()) return $candidate;
    }
    return '';
}

// =============================================================================
// CONSTANTS (whitelist)
// =============================================================================
const AE_BUCKETS = ['library', 'hero', 'surface', 'companion', 'brand', 'variant', 'legacy'];
const AE_ROLE_HINTS = ['layer', 'hero', 'surface', 'companion', 'icon', 'logo', 'thumbnail', 'poster', 'og'];
// M031 · Baked Variants — cook_state whitelist
const AE_COOK_STATES = ['either', 'raw', 'cooked', 'charred'];
const AE_CATEGORIES = [
    'board', 'base', 'sauce', 'cheese', 'meat', 'veg', 'herb',
    'drink', 'surface', 'brand', 'extra', 'misc', 'hero',
];
const AE_ENTITY_TYPES = [
    'menu_item', 'modifier', 'visual_layer', 'board_companion',
    'atelier_scene', 'scene_layer', 'tenant_brand', 'surface_library',
];
// Role link'ów (m021 Unified Asset Library).
// `modifier_icon` usunięte w m025 — modyfikatory nie mają dedykowanych ikon w nowym modelu;
// wizualizacja idzie przez `layer_top_down` + `modifier_hero` (entity_type='modifier').
const AE_LINK_ROLES = [
    'hero', 'layer_top_down', 'product_shot', 'surface_bg', 'modifier_hero',
    'companion_icon', 'tenant_logo', 'tenant_favicon',
    'thumbnail', 'poster', 'og_image', 'ambient_texture',
];
const AE_MAX_BYTES = 5 * 1024 * 1024;
const AE_MIN_SIDE  = 200;
const AE_MAX_SIDE  = 4096;
const AE_ALLOWED_EXT  = ['webp', 'png', 'jpg', 'jpeg'];
const AE_ALLOWED_MIME = ['image/webp', 'image/png', 'image/jpeg'];

// Domyślny z_order_hint per kategoria (kontrola stacka w kompozytorze)
const AE_Z_BY_CAT = [
    'board' => 0, 'base' => 10, 'sauce' => 20, 'cheese' => 30,
    'meat'  => 40, 'veg'  => 50, 'herb'  => 60, 'drink' => 80,
    'extra' => 70, 'surface' => 5, 'brand' => 95, 'hero' => 90,
    'misc'  => 99,
];

// =============================================================================
// BOOT
// =============================================================================
try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    /** @var PDO $pdo */
    /** @var int $tenant_id, $user_id */

    // Role check
    $roleStmt = $pdo->prepare(
        'SELECT role FROM sh_users
         WHERE id = :uid AND tenant_id = :tid AND is_deleted = 0 AND is_active = 1
         LIMIT 1'
    );
    $roleStmt->execute([':uid' => $user_id, ':tid' => $tenant_id]);
    $role = strtolower((string)($roleStmt->fetchColumn() ?: ''));
    if (!in_array($role, ['owner', 'admin', 'manager'], true)) {
        ae_out(false, null, 'Brak uprawnien (wymagany owner/admin/manager).');
    }

    // Detect JSON vs multipart
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $isMultipart = str_contains($contentType, 'multipart/form-data');

    if ($isMultipart) {
        $action = ae_str($_POST, 'action');
        $input  = $_POST;
    } else {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw ?: '{}', true) ?? [];
        if (!is_array($input)) {
            ae_out(false, null, 'Nieprawidlowy JSON.');
        }
        $action = ae_str($input, 'action');
    }

    if ($action === '') {
        ae_out(false, null, 'Brak parametru action.');
    }

    // Schema probe — czy migracja 021 wykonana?
    $hasAssetsTbl = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sh_assets'"
    )->fetchColumn();
    if ($hasAssetsTbl === 0) {
        ae_out(false, null, 'Migracja 021 (sh_assets) nie wykonana. Uruchom scripts/setup_database.php.');
    }

    // =========================================================================
    // ROUTER
    // =========================================================================
    switch ($action) {
        case 'list':             ae_handle_list($pdo, $tenant_id, $input);            break;
        case 'upload':           ae_handle_upload($pdo, $tenant_id, $user_id);        break;
        case 'update':           ae_handle_update($pdo, $tenant_id, $input);          break;
        case 'soft_delete':      ae_handle_soft_delete($pdo, $tenant_id, $input);     break;
        case 'restore':          ae_handle_restore($pdo, $tenant_id, $input);         break;
        case 'link':             ae_handle_link($pdo, $tenant_id, $user_id, $input);  break;
        case 'unlink':           ae_handle_unlink($pdo, $tenant_id, $input);          break;
        case 'list_usage':       ae_handle_list_usage($pdo, $tenant_id, $input);      break;
        case 'list_entities':    ae_handle_list_entities($pdo, $tenant_id);           break;
        // M032 · Asset Library Organizer
        case 'bulk_update':      ae_handle_bulk_update($pdo, $tenant_id, $input);     break;
        case 'bulk_soft_delete': ae_handle_bulk_soft_delete($pdo, $tenant_id, $input);break;
        case 'duplicate':        ae_handle_duplicate($pdo, $tenant_id, $user_id, $input); break;
        case 'merge_duplicates': ae_handle_merge_duplicates($pdo, $tenant_id, $input);break;
        case 'scan_health':      ae_handle_scan_health($pdo, $tenant_id);             break;
        case 'rename_smart':     ae_handle_rename_smart($pdo, $tenant_id, $input);    break;
        case 'whoami':           ae_out(true, ['tenantId' => $tenant_id, 'userId' => $user_id, 'role' => $role]); break;
        default:
            ae_out(false, null, 'Nieznana akcja: ' . $action);
    }
} catch (\Throwable $e) {
    ae_out(false, null, 'Blad serwera: ' . $e->getMessage());
}

// =============================================================================
// HANDLERS
// =============================================================================

/**
 * list — paginowana lista assetów tenanta + globalnych.
 * Parametry: page, per_page, bucket, role_hint, category, sub_type, search,
 *            include_deleted (bool), include_globals (bool, default true).
 */
function ae_handle_list(PDO $pdo, int $tenantId, array $in): void {
    $page    = max(1, ae_int($in, 'page', 1));
    $perPage = min(500, max(1, ae_int($in, 'per_page', 100)));
    $offset  = ($page - 1) * $perPage;

    $bucket     = ae_str($in, 'bucket');
    $roleHint   = ae_str($in, 'role_hint');
    $category   = ae_str($in, 'category');
    $subType    = ae_safe_sub_type(ae_str($in, 'sub_type'));
    $search     = ae_str($in, 'search');
    $cookState  = ae_str($in, 'cook_state');
    $inclDeleted = ae_bool($in, 'include_deleted', false);
    $inclGlobals = ae_bool($in, 'include_globals', true);
    // M032 · Health filters
    $orphansOnly       = ae_bool($in, 'orphans_only', false);
    $duplicatesOnly    = ae_bool($in, 'duplicates_only', false);
    $missingCategory   = ae_bool($in, 'missing_category', false);
    $missingCookState  = ae_bool($in, 'missing_cook_state', false);
    $largeFilesOnly    = ae_bool($in, 'large_files', false);
    $largeThresholdMB  = (float)(ae_int($in, 'large_threshold_mb', 2));

    $where = [];
    $params = [':tid' => $tenantId];

    if ($inclGlobals) {
        $where[] = '(a.tenant_id = :tid OR a.tenant_id = 0)';
    } else {
        $where[] = 'a.tenant_id = :tid';
    }
    if (!$inclDeleted) {
        $where[] = 'a.deleted_at IS NULL';
        $where[] = 'a.is_active = 1';
    }
    if ($bucket !== '' && in_array($bucket, AE_BUCKETS, true)) {
        $where[] = 'a.storage_bucket = :bucket';
        $params[':bucket'] = $bucket;
    }
    if ($roleHint !== '' && in_array($roleHint, AE_ROLE_HINTS, true)) {
        $where[] = 'a.role_hint = :rh';
        $params[':rh'] = $roleHint;
    }
    if ($category !== '' && in_array($category, AE_CATEGORIES, true)) {
        $where[] = 'a.category = :cat';
        $params[':cat'] = $category;
    }
    if ($subType !== '') {
        $where[] = 'a.sub_type = :sub';
        $params[':sub'] = $subType;
    }
    if ($search !== '') {
        $where[] = '(a.ascii_key LIKE :q OR a.sub_type LIKE :q OR a.storage_url LIKE :q OR a.display_name LIKE :q)';
        $params[':q'] = '%' . $search . '%';
    }
    // M031 · Baked Variants — filtruj po cook_state (opcjonalnie)
    if ($cookState !== '' && in_array($cookState, AE_COOK_STATES, true)) {
        $where[] = 'a.cook_state = :ck';
        $params[':ck'] = $cookState;
    }
    // M032 · Health filters
    if ($orphansOnly) {
        $where[] = 'NOT EXISTS (SELECT 1 FROM sh_asset_links al
                                WHERE al.asset_id = a.id
                                  AND al.is_active = 1
                                  AND al.deleted_at IS NULL)';
    }
    if ($duplicatesOnly) {
        $where[] = 'a.checksum_sha256 IS NOT NULL';
        $where[] = 'a.checksum_sha256 IN (
                        SELECT checksum_sha256 FROM (
                            SELECT checksum_sha256, COUNT(*) AS cnt
                            FROM sh_assets
                            WHERE (tenant_id = :tid OR tenant_id = 0)
                              AND checksum_sha256 IS NOT NULL
                              AND deleted_at IS NULL
                              AND is_active = 1
                            GROUP BY checksum_sha256
                            HAVING cnt > 1
                        ) dup
                    )';
    }
    if ($missingCategory) {
        $where[] = "(a.category IS NULL OR a.category = '' OR a.category = 'misc')";
    }
    if ($missingCookState) {
        // "Bez stanu pieczenia" — tylko dla kategorii składników, które mają sens (cheese/meat/veg)
        $where[] = "a.cook_state = 'either'";
        $where[] = "a.category IN ('cheese','meat','veg','sauce','herb','base')";
    }
    if ($largeFilesOnly) {
        $bytes = (int)max(100_000, $largeThresholdMB * 1024 * 1024);
        $where[] = 'a.filesize_bytes >= :large_bytes';
        $params[':large_bytes'] = $bytes;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Count total
    $cntSql = "SELECT COUNT(*) FROM sh_assets a $whereSql";
    $cntStmt = $pdo->prepare($cntSql);
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();

    // Fetch rows + link_count + duplicate_count (po SHA-256)
    $sql = "SELECT
                a.id, a.tenant_id, a.ascii_key, a.display_name, a.tags_json,
                a.storage_url, a.storage_bucket,
                a.mime_type, a.width_px, a.height_px, a.filesize_bytes, a.has_alpha,
                a.checksum_sha256,
                a.role_hint, a.category, a.sub_type, a.cook_state, a.z_order_hint,
                a.variant_of, a.variant_kind,
                a.metadata_json,
                a.is_active, a.deleted_at,
                a.created_at, a.updated_at, a.created_by_user,
                (SELECT COUNT(*) FROM sh_asset_links al
                    WHERE al.asset_id = a.id
                      AND al.is_active = 1
                      AND al.deleted_at IS NULL) AS link_count,
                CASE WHEN a.checksum_sha256 IS NULL THEN 0 ELSE
                    (SELECT COUNT(*) FROM sh_assets d
                        WHERE d.checksum_sha256 = a.checksum_sha256
                          AND d.is_active = 1
                          AND d.deleted_at IS NULL
                          AND (d.tenant_id = a.tenant_id OR d.tenant_id = 0))
                END AS duplicate_count
            FROM sh_assets a
            $whereSql
            ORDER BY a.created_at DESC
            LIMIT $offset, $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = array_map(fn($r) => ae_asset_row_to_api($r), $rows);

    ae_out(true, [
        'items'   => $items,
        'total'   => $total,
        'page'    => $page,
        'perPage' => $perPage,
    ]);
}

/**
 * upload — multipart: file + bucket + role_hint + category + sub_type + [ascii_key] + [metadata_json].
 * Dedup: jeśli (tenant_id, checksum_sha256) istnieje → zwróć istniejący rekord (bez duplikatu pliku).
 */
function ae_handle_upload(PDO $pdo, int $tenantId, int $userId): void {
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        ae_out(false, null, 'Brak pliku (pole "file").');
    }
    $file = $_FILES['file'];
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        ae_out(false, null, 'Blad uploadu. Kod: ' . (int)$file['error']);
    }

    // Metadane z $_POST
    $bucket   = ae_str($_POST, 'bucket', 'library');
    if (!in_array($bucket, AE_BUCKETS, true)) {
        ae_out(false, null, 'Nieprawidlowy bucket. Dozwolone: ' . implode(', ', AE_BUCKETS));
    }
    $roleHint = ae_str($_POST, 'role_hint', 'layer');
    if (!in_array($roleHint, AE_ROLE_HINTS, true)) {
        ae_out(false, null, 'Nieprawidlowy role_hint. Dozwolone: ' . implode(', ', AE_ROLE_HINTS));
    }
    $category = ae_str($_POST, 'category', 'misc');
    if (!in_array($category, AE_CATEGORIES, true)) {
        ae_out(false, null, 'Nieprawidlowa category. Dozwolone: ' . implode(', ', AE_CATEGORIES));
    }
    $subType = ae_safe_sub_type(ae_str($_POST, 'sub_type'));
    if ($subType === '' && in_array($bucket, ['library', 'variant'], true)) {
        ae_out(false, null, 'Dla bucket=library/variant wymagane sub_type (a-z0-9_).');
    }
    // M031 · Baked Variants — cook_state (opcjonalny, default 'either')
    $cookState = ae_str($_POST, 'cook_state', 'either');
    if (!in_array($cookState, AE_COOK_STATES, true)) {
        ae_out(false, null, 'Nieprawidlowy cook_state. Dozwolone: ' . implode(', ', AE_COOK_STATES));
    }
    $asciiOverride = ae_safe_ascii_key(ae_str($_POST, 'ascii_key'));
    if (ae_str($_POST, 'ascii_key') !== '' && $asciiOverride === '') {
        ae_out(false, null, 'ascii_key: dozwolone A-Za-z0-9_+-, 3-191 znakow.');
    }
    // M032 · Ludzka nazwa + tagi + auto-zgadywanie z nazwy pliku
    $displayName = ae_safe_display_name(ae_str($_POST, 'display_name'));
    if ($displayName === '') {
        // Spróbuj wyciągnąć z nazwy oryginalnego pliku (np. "Pieczarki plasterki.webp" → "Pieczarki plasterki")
        $origName = (string)($_FILES['file']['name'] ?? '');
        $guess = trim(pathinfo($origName, PATHINFO_FILENAME));
        $guess = str_replace(['_', '-'], ' ', $guess);
        $guess = preg_replace('/\s+/u', ' ', $guess) ?? '';
        $guess = ae_safe_display_name(ucfirst(mb_strtolower($guess)));
        if ($guess !== '') $displayName = $guess;
    }
    $tagsJson = ae_sanitize_tags_json($_POST['tags'] ?? null);

    $zOrderHint = isset($_POST['z_order_hint']) && $_POST['z_order_hint'] !== ''
        ? (int)$_POST['z_order_hint']
        : (AE_Z_BY_CAT[$category] ?? 50);

    $metadataJson = ae_str($_POST, 'metadata_json', '');
    $metadataArr = null;
    if ($metadataJson !== '') {
        $tmp = json_decode($metadataJson, true);
        if (!is_array($tmp)) {
            ae_out(false, null, 'metadata_json musi byc JSON objectem.');
        }
        $metadataArr = $tmp;
    }

    // Walidacja pliku
    $origName = (string)($file['name'] ?? 'file');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, AE_ALLOWED_EXT, true)) {
        ae_out(false, null, 'Dozwolone: .' . implode(', .', AE_ALLOWED_EXT));
    }
    if ((int)$file['size'] > AE_MAX_BYTES) {
        ae_out(false, null, 'Plik za duzy (max ' . round(AE_MAX_BYTES / 1048576, 1) . ' MB).');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    if (!in_array($mime, AE_ALLOWED_MIME, true)) {
        ae_out(false, null, 'Nieprawidlowy MIME: ' . $mime);
    }

    $info = @getimagesize($file['tmp_name']);
    if (!$info) {
        ae_out(false, null, 'Plik nie jest poprawnym obrazem.');
    }
    [$width, $height] = [$info[0], $info[1]];
    if ($width < AE_MIN_SIDE || $height < AE_MIN_SIDE) {
        ae_out(false, null, "Obraz za maly (min " . AE_MIN_SIDE . "x" . AE_MIN_SIDE . ", masz {$width}x{$height}).");
    }
    if ($width > AE_MAX_SIDE || $height > AE_MAX_SIDE) {
        ae_out(false, null, "Obraz za duzy (max " . AE_MAX_SIDE . "x" . AE_MAX_SIDE . ", masz {$width}x{$height}).");
    }

    // SHA-256 + dedup
    $sha256 = hash_file('sha256', $file['tmp_name']);
    if ($sha256) {
        $dup = $pdo->prepare(
            'SELECT id, ascii_key, storage_url, storage_bucket
             FROM sh_assets
             WHERE tenant_id = :tid AND checksum_sha256 = :sha
               AND is_active = 1 AND deleted_at IS NULL
             LIMIT 1'
        );
        $dup->execute([':tid' => $tenantId, ':sha' => $sha256]);
        $dupRow = $dup->fetch(PDO::FETCH_ASSOC);
        if ($dupRow) {
            ae_out(true, [
                'deduplicated' => true,
                'id'           => (int)$dupRow['id'],
                'asciiKey'     => $dupRow['ascii_key'],
                'storageUrl'   => $dupRow['storage_url'],
                'bucket'       => $dupRow['storage_bucket'],
            ], 'Plik o tej samej zawartosci juz istnieje w bibliotece — zwrocono istniejacy wpis.');
        }
    }

    // Build storage path
    $bucketDir = $bucket === '' ? 'library' : $bucket;
    $destDir = realpath(__DIR__ . '/../../');
    if ($destDir === false) {
        ae_out(false, null, 'Nie mozna okreslic katalogu DOCROOT.');
    }
    $destDir .= DIRECTORY_SEPARATOR . 'uploads'
              . DIRECTORY_SEPARATOR . 'assets'
              . DIRECTORY_SEPARATOR . $tenantId
              . DIRECTORY_SEPARATOR . $bucketDir;
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        ae_out(false, null, 'Nie mozna utworzyc katalogu docelowego.');
    }

    // Filename: {category}_{sub_type}_{sha6}.{ext}
    $shaTail  = substr($sha256, 0, 8);
    $baseSlug = ae_slug($subType !== '' ? $subType : $category);
    $filename = sprintf('%s_%s_%s.%s', ae_slug($category), $baseSlug, $shaTail, $ext);
    $destPath = $destDir . DIRECTORY_SEPARATOR . $filename;

    // Conflict safety (rzadkie — SHA-8 collision)
    $collisionCounter = 0;
    while (file_exists($destPath)) {
        $collisionCounter++;
        $filename = sprintf('%s_%s_%s_%d.%s', ae_slug($category), $baseSlug, $shaTail, $collisionCounter, $ext);
        $destPath = $destDir . DIRECTORY_SEPARATOR . $filename;
        if ($collisionCounter > 20) {
            ae_out(false, null, 'Za duzo kolizji nazw pliku. Zglos administratorowi.');
        }
    }

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        ae_out(false, null, 'Nie udalo sie zapisac pliku na dysku.');
    }

    // storage_url: relatywne od DOCROOT (zgodne z konwencją m021)
    $storageUrl = 'uploads/assets/' . $tenantId . '/' . $bucketDir . '/' . $filename;

    // ascii_key: auto-gen jeśli nie podany
    $asciiKey = $asciiOverride !== '' ? $asciiOverride : pathinfo($filename, PATHINFO_FILENAME);
    // Upewnij się, że ascii_key jest unikalny per tenant
    $conflict = 0;
    $baseKey = $asciiKey;
    while (true) {
        $q = $pdo->prepare('SELECT id FROM sh_assets WHERE tenant_id = :tid AND ascii_key = :ak LIMIT 1');
        $q->execute([':tid' => $tenantId, ':ak' => $asciiKey]);
        if (!$q->fetchColumn()) break;
        $conflict++;
        $asciiKey = $baseKey . '_' . $conflict;
        if ($conflict > 50) {
            @unlink($destPath);
            ae_out(false, null, 'Nie mozna wygenerowac unikalnego ascii_key.');
        }
    }

    // INSERT sh_assets
    $hasAlpha = ($mime === 'image/png' || $mime === 'image/webp') ? 1 : 0;
    $meta = array_merge([
        'uploaded_via' => 'asset_studio_ui',
        'orig_name'    => $origName,
    ], is_array($metadataArr) ? $metadataArr : []);

    try {
        $ins = $pdo->prepare(
            'INSERT INTO sh_assets
               (tenant_id, ascii_key, display_name, tags_json,
                storage_url, storage_bucket, mime_type,
                width_px, height_px, filesize_bytes, has_alpha, checksum_sha256,
                role_hint, category, sub_type, cook_state, z_order_hint,
                metadata_json, is_active, created_at, created_by_user)
             VALUES
               (:tid, :ak, :dn, :tags,
                :url, :buc, :mime,
                :w, :h, :sz, :alpha, :sha,
                :rh, :cat, :sub, :ck, :z,
                :meta, 1, CURRENT_TIMESTAMP, :cub)'
        );
        $ins->execute([
            ':tid' => $tenantId,
            ':ak' => $asciiKey,
            ':dn' => $displayName !== '' ? $displayName : null,
            ':tags' => $tagsJson,
            ':url' => $storageUrl,
            ':buc' => $bucket,
            ':mime' => $mime,
            ':w' => (int)$width,
            ':h' => (int)$height,
            ':sz' => (int)$file['size'],
            ':alpha' => $hasAlpha,
            ':sha' => $sha256,
            ':rh' => $roleHint,
            ':cat' => $category,
            ':sub' => $subType !== '' ? $subType : null,
            ':ck' => $cookState,
            ':z' => $zOrderHint,
            ':meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            ':cub' => (string)$userId,
        ]);
        $newId = (int)$pdo->lastInsertId();
    } catch (\PDOException $e) {
        @unlink($destPath);
        ae_out(false, null, 'Blad zapisu do bazy: ' . $e->getMessage());
    }

    ae_out(true, [
        'deduplicated'  => false,
        'id'            => $newId,
        'asciiKey'      => $asciiKey,
        'storageUrl'    => $storageUrl,
        'publicUrl'     => '/slicehub/' . $storageUrl,
        'bucket'        => $bucket,
        'roleHint'      => $roleHint,
        'category'      => $category,
        'subType'       => $subType,
        'mimeType'      => $mime,
        'width'         => (int)$width,
        'height'        => (int)$height,
        'filesizeBytes' => (int)$file['size'],
        'zOrderHint'    => $zOrderHint,
        'checksum'      => $sha256,
    ], 'OK — wgrano do Unified Asset Library.');
}

/**
 * update — zmiana metadata assetu (tylko dla własnych tenant_id).
 */
function ae_handle_update(PDO $pdo, int $tenantId, array $in): void {
    $id = ae_int($in, 'id');
    if ($id <= 0) ae_out(false, null, 'Brak id.');

    $asset = ae_fetch_asset_owned($pdo, $tenantId, $id);
    if (!$asset) ae_out(false, null, 'Asset nie znaleziony lub nalezy do innego tenanta.');

    $sets = [];
    $params = [':id' => $id, ':tid' => $tenantId];

    if (isset($in['ascii_key'])) {
        $ak = ae_safe_ascii_key(ae_str($in, 'ascii_key'));
        if ($ak === '') ae_out(false, null, 'Nieprawidlowy ascii_key.');
        // check unique per tenant
        $q = $pdo->prepare('SELECT id FROM sh_assets WHERE tenant_id = :tid AND ascii_key = :ak AND id <> :id LIMIT 1');
        $q->execute([':tid' => $tenantId, ':ak' => $ak, ':id' => $id]);
        if ($q->fetchColumn()) ae_out(false, null, 'ascii_key juz istnieje dla tego tenanta.');
        $sets[] = 'ascii_key = :ak';
        $params[':ak'] = $ak;
    }
    // M032 · display_name (opcjonalne — NULL resetuje do ascii_key w UI)
    if (array_key_exists('display_name', $in)) {
        $dn = ae_safe_display_name(ae_str($in, 'display_name'));
        $sets[] = 'display_name = :dn';
        $params[':dn'] = $dn !== '' ? $dn : null;
    }
    // M032 · tags (array stringów)
    if (array_key_exists('tags', $in)) {
        $tagsJson = ae_sanitize_tags_json($in['tags']);
        $sets[] = 'tags_json = :tags';
        $params[':tags'] = $tagsJson;
    }
    // M032 · regenerate_ascii_key — zbuduj z display_name lub category_sub_type
    if (ae_bool($in, 'regenerate_ascii_key')) {
        $dnForSlug = isset($in['display_name']) ? ae_str($in, 'display_name') : (string)($asset['display_name'] ?? '');
        $catForSlug = isset($in['category']) ? ae_str($in, 'category') : (string)($asset['category'] ?? 'misc');
        $subForSlug = isset($in['sub_type']) ? ae_safe_sub_type(ae_str($in, 'sub_type')) : (string)($asset['sub_type'] ?? '');
        $newAk = ae_build_pretty_ascii_key($pdo, $tenantId, $catForSlug, $subForSlug, $dnForSlug, $id);
        if ($newAk !== '') {
            $sets[] = 'ascii_key = :ak_regen';
            $params[':ak_regen'] = $newAk;
        }
    }
    if (isset($in['role_hint'])) {
        $rh = ae_str($in, 'role_hint');
        if (!in_array($rh, AE_ROLE_HINTS, true)) ae_out(false, null, 'Nieprawidlowy role_hint.');
        $sets[] = 'role_hint = :rh';
        $params[':rh'] = $rh;
    }
    if (isset($in['category'])) {
        $cat = ae_str($in, 'category');
        if (!in_array($cat, AE_CATEGORIES, true)) ae_out(false, null, 'Nieprawidlowa category.');
        $sets[] = 'category = :cat';
        $params[':cat'] = $cat;
    }
    if (isset($in['sub_type'])) {
        $sub = ae_safe_sub_type(ae_str($in, 'sub_type'));
        $sets[] = 'sub_type = :sub';
        $params[':sub'] = $sub !== '' ? $sub : null;
    }
    // M031 · Baked Variants — edycja stanu pieczenia
    if (isset($in['cook_state'])) {
        $ck = ae_str($in, 'cook_state');
        if (!in_array($ck, AE_COOK_STATES, true)) {
            ae_out(false, null, 'Nieprawidlowy cook_state. Dozwolone: ' . implode(', ', AE_COOK_STATES));
        }
        $sets[] = 'cook_state = :ck';
        $params[':ck'] = $ck;
    }
    if (isset($in['z_order_hint'])) {
        $sets[] = 'z_order_hint = :z';
        $params[':z'] = ae_int($in, 'z_order_hint');
    }
    if (isset($in['storage_bucket'])) {
        $buc = ae_str($in, 'storage_bucket');
        if (!in_array($buc, AE_BUCKETS, true)) ae_out(false, null, 'Nieprawidlowy bucket.');
        $sets[] = 'storage_bucket = :buc';
        $params[':buc'] = $buc;
    }
    if (isset($in['is_active'])) {
        $sets[] = 'is_active = :act';
        $params[':act'] = ae_bool($in, 'is_active') ? 1 : 0;
    }

    if (!$sets) ae_out(false, null, 'Brak pol do aktualizacji.');

    $sql = 'UPDATE sh_assets SET ' . implode(', ', $sets) . '
            WHERE id = :id AND tenant_id = :tid LIMIT 1';
    $pdo->prepare($sql)->execute($params);

    ae_out(true, ['id' => $id], 'OK — zapisano.');
}

/**
 * soft_delete — ustaw deleted_at + is_active=0. Pozostawia pliki na dysku (Prawo Czwartego Wymiaru).
 * Opcjonalnie cascadeLinks (default true) — oznacza wszystkie linki jako deleted.
 */
function ae_handle_soft_delete(PDO $pdo, int $tenantId, array $in): void {
    $id = ae_int($in, 'id');
    if ($id <= 0) ae_out(false, null, 'Brak id.');

    $asset = ae_fetch_asset_owned($pdo, $tenantId, $id);
    if (!$asset) ae_out(false, null, 'Asset nie znaleziony lub nalezy do innego tenanta.');

    $cascade = ae_bool($in, 'cascade_links', true);
    $force   = ae_bool($in, 'force', false);

    // Zablokuj delete jeśli są aktywne linki i nie force
    if (!$force && !$cascade) {
        $q = $pdo->prepare('SELECT COUNT(*) FROM sh_asset_links WHERE asset_id = :id AND is_active = 1 AND deleted_at IS NULL');
        $q->execute([':id' => $id]);
        if ((int)$q->fetchColumn() > 0) {
            ae_out(false, null, 'Asset ma aktywne linki. Uzyj cascade_links=true lub force=true.');
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE sh_assets SET deleted_at = CURRENT_TIMESTAMP, is_active = 0
             WHERE id = :id AND tenant_id = :tid LIMIT 1'
        )->execute([':id' => $id, ':tid' => $tenantId]);

        if ($cascade) {
            $pdo->prepare(
                'UPDATE sh_asset_links SET deleted_at = CURRENT_TIMESTAMP, is_active = 0
                 WHERE asset_id = :id AND tenant_id = :tid AND deleted_at IS NULL'
            )->execute([':id' => $id, ':tid' => $tenantId]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        ae_out(false, null, 'Blad soft-delete: ' . $e->getMessage());
    }

    ae_out(true, ['id' => $id], 'OK — oznaczono jako usuniety.');
}

function ae_handle_restore(PDO $pdo, int $tenantId, array $in): void {
    $id = ae_int($in, 'id');
    if ($id <= 0) ae_out(false, null, 'Brak id.');

    $pdo->prepare(
        'UPDATE sh_assets SET deleted_at = NULL, is_active = 1
         WHERE id = :id AND tenant_id = :tid LIMIT 1'
    )->execute([':id' => $id, ':tid' => $tenantId]);

    ae_out(true, ['id' => $id], 'OK — przywrocono.');
}

/**
 * link — stwórz sh_asset_links. Waliduje whitelist entity_type + role.
 * Jeśli link już istnieje (unique key), zwraca istniejący id.
 */
function ae_handle_link(PDO $pdo, int $tenantId, int $userId, array $in): void {
    $assetId    = ae_int($in, 'asset_id');
    $entityType = ae_str($in, 'entity_type');
    $entityRef  = ae_str($in, 'entity_ref');
    $role       = ae_str($in, 'role');
    $sortOrder  = ae_int($in, 'sort_order', 0);
    $params     = $in['display_params'] ?? null;

    if ($assetId <= 0) ae_out(false, null, 'Brak asset_id.');
    if (!in_array($entityType, AE_ENTITY_TYPES, true)) {
        ae_out(false, null, 'Nieprawidlowy entity_type. Dozwolone: ' . implode(', ', AE_ENTITY_TYPES));
    }
    if ($entityRef === '' || strlen($entityRef) > 255) {
        ae_out(false, null, 'Nieprawidlowy entity_ref.');
    }
    if (!in_array($role, AE_LINK_ROLES, true)) {
        ae_out(false, null, 'Nieprawidlowa rola. Dozwolone: ' . implode(', ', AE_LINK_ROLES));
    }

    // Verify asset ownership (tenant albo 0=globalny)
    $a = $pdo->prepare('SELECT id, tenant_id FROM sh_assets WHERE id = :id AND is_active = 1 AND deleted_at IS NULL LIMIT 1');
    $a->execute([':id' => $assetId]);
    $aRow = $a->fetch(PDO::FETCH_ASSOC);
    if (!$aRow) ae_out(false, null, 'Asset nie znaleziony lub nieaktywny.');
    $assetTenant = (int)$aRow['tenant_id'];
    if ($assetTenant !== 0 && $assetTenant !== $tenantId) {
        ae_out(false, null, 'Brak dostepu do assetu (inny tenant).');
    }

    // Verify entity_ref istnieje (cross-check żeby nie tworzyć zombie-linków)
    $verifyMsg = ae_verify_entity_ref($pdo, $tenantId, $entityType, $entityRef);
    if ($verifyMsg !== null) {
        ae_out(false, null, $verifyMsg);
    }

    // display_params może być obiektem lub JSON stringiem
    $displayJson = null;
    if ($params !== null) {
        if (is_array($params)) {
            $displayJson = json_encode($params, JSON_UNESCAPED_UNICODE);
        } else if (is_string($params) && $params !== '') {
            $tmp = json_decode($params, true);
            if (!is_array($tmp)) ae_out(false, null, 'display_params musi byc JSONem.');
            $displayJson = $params;
        }
    }

    try {
        $sql = 'INSERT INTO sh_asset_links
                  (tenant_id, asset_id, entity_type, entity_ref, role, sort_order,
                   display_params_json, is_active, created_at, created_by_user)
                VALUES
                  (:tid, :aid, :etype, :eref, :role, :so,
                   :dp, 1, CURRENT_TIMESTAMP, :cub)
                ON DUPLICATE KEY UPDATE
                  sort_order          = VALUES(sort_order),
                  display_params_json = VALUES(display_params_json),
                  is_active           = 1,
                  deleted_at          = NULL,
                  updated_at          = CURRENT_TIMESTAMP';
        $pdo->prepare($sql)->execute([
            ':tid' => $tenantId,
            ':aid' => $assetId,
            ':etype' => $entityType,
            ':eref' => $entityRef,
            ':role' => $role,
            ':so' => $sortOrder,
            ':dp' => $displayJson,
            ':cub' => (string)$userId,
        ]);

        $q = $pdo->prepare(
            'SELECT id FROM sh_asset_links
             WHERE tenant_id=:tid AND entity_type=:et AND entity_ref=:er
               AND role=:role AND asset_id=:aid LIMIT 1'
        );
        $q->execute([':tid' => $tenantId, ':et' => $entityType, ':er' => $entityRef, ':role' => $role, ':aid' => $assetId]);
        $linkId = (int)($q->fetchColumn() ?: 0);
    } catch (\PDOException $e) {
        ae_out(false, null, 'Blad zapisu linka: ' . $e->getMessage());
    }

    ae_out(true, ['id' => $linkId], 'OK — asset polaczony.');
}

function ae_handle_unlink(PDO $pdo, int $tenantId, array $in): void {
    $id = ae_int($in, 'id');
    if ($id <= 0) ae_out(false, null, 'Brak id.');

    $stmt = $pdo->prepare(
        'UPDATE sh_asset_links SET deleted_at = CURRENT_TIMESTAMP, is_active = 0
         WHERE id = :id AND tenant_id = :tid LIMIT 1'
    );
    $stmt->execute([':id' => $id, ':tid' => $tenantId]);
    if ($stmt->rowCount() === 0) {
        ae_out(false, null, 'Link nie znaleziony lub nalezy do innego tenanta.');
    }

    ae_out(true, ['id' => $id], 'OK — rozlaczono.');
}

/**
 * list_usage — dla asset_id zwróć wszystkie aktywne linki + human-friendly nazwy encji.
 */
function ae_handle_list_usage(PDO $pdo, int $tenantId, array $in): void {
    $assetId = ae_int($in, 'asset_id');
    if ($assetId <= 0) ae_out(false, null, 'Brak asset_id.');

    $sql = 'SELECT id, tenant_id, asset_id, entity_type, entity_ref, role,
                   sort_order, display_params_json, is_active, created_at, updated_at
            FROM sh_asset_links
            WHERE asset_id = :aid
              AND (tenant_id = :tid OR tenant_id = 0)
              AND is_active = 1
              AND deleted_at IS NULL
            ORDER BY entity_type, sort_order ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':aid' => $assetId, ':tid' => $tenantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'           => (int)$r['id'],
            'entityType'   => $r['entity_type'],
            'entityRef'    => $r['entity_ref'],
            'role'         => $r['role'],
            'sortOrder'    => (int)$r['sort_order'],
            'displayParams'=> $r['display_params_json'] ? json_decode($r['display_params_json'], true) : null,
            'entityLabel'  => ae_resolve_entity_label($pdo, $tenantId, (string)$r['entity_type'], (string)$r['entity_ref']),
            'createdAt'    => $r['created_at'],
            'updatedAt'    => $r['updated_at'],
        ];
    }

    ae_out(true, ['links' => $out, 'total' => count($out)]);
}

/**
 * list_entities — zwraca dict encji, do których można linkować:
 *   menu_items:       [{sku, name, isActive}]
 *   modifiers:        [{sku, name, groupName}]
 *   board_companions: [{itemSku, companionSku, label}]
 */
function ae_handle_list_entities(PDO $pdo, int $tenantId): void {
    // menu_items
    $stmt = $pdo->prepare(
        'SELECT ascii_key, name, is_active
         FROM sh_menu_items
         WHERE tenant_id = :tid
         ORDER BY name ASC'
    );
    $stmt->execute([':tid' => $tenantId]);
    $menuItems = array_map(
        fn($r) => ['sku' => $r['ascii_key'], 'name' => $r['name'], 'isActive' => (int)$r['is_active'] === 1],
        $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
    );

    // modifiers (via group → tenant_id)
    $modStmt = $pdo->prepare(
        'SELECT m.ascii_key AS sku, m.name, mg.name AS group_name
         FROM sh_modifiers m
         INNER JOIN sh_modifier_groups mg ON mg.id = m.group_id
         WHERE mg.tenant_id = :tid
         ORDER BY mg.name, m.name ASC'
    );
    $modStmt->execute([':tid' => $tenantId]);
    $modifiers = array_map(
        fn($r) => ['sku' => $r['sku'], 'name' => $r['name'], 'groupName' => $r['group_name']],
        $modStmt->fetchAll(PDO::FETCH_ASSOC) ?: []
    );

    // board_companions — tylko jeśli tabela istnieje
    $hasBc = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sh_board_companions'"
    )->fetchColumn();
    $companions = [];
    if ($hasBc) {
        $bcStmt = $pdo->prepare(
            'SELECT DISTINCT bc.item_sku, bc.companion_sku, mi.name AS item_name
             FROM sh_board_companions bc
             LEFT JOIN sh_menu_items mi
               ON mi.tenant_id = bc.tenant_id AND mi.ascii_key = bc.item_sku
             WHERE bc.tenant_id = :tid
             ORDER BY bc.item_sku, bc.companion_sku ASC'
        );
        $bcStmt->execute([':tid' => $tenantId]);
        $companions = array_map(
            fn($r) => [
                'itemSku'      => $r['item_sku'],
                'companionSku' => $r['companion_sku'],
                'label'        => ($r['item_name'] ?? $r['item_sku']) . ' · ' . $r['companion_sku'],
                'ref'          => $r['item_sku'] . '::' . $r['companion_sku'],
            ],
            $bcStmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    ae_out(true, [
        'menuItems'       => $menuItems,
        'modifiers'       => $modifiers,
        'boardCompanions' => $companions,
    ]);
}

// =============================================================================
// M032 · BULK / ORGANIZER HANDLERS
// =============================================================================

/**
 * bulk_update — patch wielu assetów naraz.
 * Body: { ids: [1,2,3], patch: { category?, sub_type?, cook_state?, bucket?, role_hint?, tags_append?, tags_replace? } }
 */
function ae_handle_bulk_update(PDO $pdo, int $tenantId, array $in): void {
    $ids = ae_normalize_id_list($in['ids'] ?? null);
    if (!$ids) ae_out(false, null, 'Brak ids[].');
    if (count($ids) > 500) ae_out(false, null, 'Za duzo assetow naraz (max 500).');

    $patch = is_array($in['patch'] ?? null) ? $in['patch'] : [];
    $sets = [];
    $params = [':tid' => $tenantId];

    if (isset($patch['category'])) {
        $cat = (string)$patch['category'];
        if (!in_array($cat, AE_CATEGORIES, true)) ae_out(false, null, 'Nieprawidlowa category.');
        $sets[] = 'category = :cat';
        $params[':cat'] = $cat;
    }
    if (array_key_exists('sub_type', $patch)) {
        $sub = ae_safe_sub_type((string)$patch['sub_type']);
        $sets[] = 'sub_type = :sub';
        $params[':sub'] = $sub !== '' ? $sub : null;
    }
    if (isset($patch['cook_state'])) {
        $ck = (string)$patch['cook_state'];
        if (!in_array($ck, AE_COOK_STATES, true)) ae_out(false, null, 'Nieprawidlowy cook_state.');
        $sets[] = 'cook_state = :ck';
        $params[':ck'] = $ck;
    }
    if (isset($patch['bucket'])) {
        $buc = (string)$patch['bucket'];
        if (!in_array($buc, AE_BUCKETS, true)) ae_out(false, null, 'Nieprawidlowy bucket.');
        $sets[] = 'storage_bucket = :buc';
        $params[':buc'] = $buc;
    }
    if (isset($patch['role_hint'])) {
        $rh = (string)$patch['role_hint'];
        if (!in_array($rh, AE_ROLE_HINTS, true)) ae_out(false, null, 'Nieprawidlowy role_hint.');
        $sets[] = 'role_hint = :rh';
        $params[':rh'] = $rh;
    }
    // tags_replace — nadpisz
    if (array_key_exists('tags_replace', $patch)) {
        $tagsJson = ae_sanitize_tags_json($patch['tags_replace']);
        $sets[] = 'tags_json = :tags';
        $params[':tags'] = $tagsJson;
    }

    if (!$sets) ae_out(false, null, 'Brak pol w patchu.');

    $placeholders = [];
    foreach ($ids as $i => $id) {
        $ph = ':id_' . $i;
        $placeholders[] = $ph;
        $params[$ph] = $id;
    }
    $sql = 'UPDATE sh_assets SET ' . implode(', ', $sets) . '
            WHERE tenant_id = :tid AND id IN (' . implode(',', $placeholders) . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // tags_append — dopisz tagi (dla każdego osobno bo JSON merge per wiersz)
    $appended = 0;
    if (array_key_exists('tags_append', $patch)) {
        $newTags = ae_sanitize_tags_json($patch['tags_append']);
        if ($newTags !== null) {
            $newArr = json_decode($newTags, true) ?: [];
            $sel = $pdo->prepare('SELECT id, tags_json FROM sh_assets WHERE tenant_id = :tid AND id = :id LIMIT 1');
            $upd = $pdo->prepare('UPDATE sh_assets SET tags_json = :tags WHERE tenant_id = :tid AND id = :id LIMIT 1');
            foreach ($ids as $id) {
                $sel->execute([':tid' => $tenantId, ':id' => $id]);
                $row = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$row) continue;
                $existing = [];
                if (!empty($row['tags_json'])) {
                    $dec = json_decode((string)$row['tags_json'], true);
                    if (is_array($dec)) $existing = $dec;
                }
                $merged = array_values(array_unique(array_merge($existing, $newArr)));
                $merged = array_slice($merged, 0, 12);
                $upd->execute([
                    ':tid' => $tenantId,
                    ':id' => $id,
                    ':tags' => json_encode($merged, JSON_UNESCAPED_UNICODE),
                ]);
                $appended++;
            }
        }
    }

    ae_out(true, [
        'updated'  => $stmt->rowCount(),
        'appended' => $appended,
        'ids'      => $ids,
    ], 'OK — zaktualizowano ' . $stmt->rowCount() . ' assetow.');
}

/**
 * bulk_soft_delete — oznacz wiele assetów jako usunięte (cascade linki).
 */
function ae_handle_bulk_soft_delete(PDO $pdo, int $tenantId, array $in): void {
    $ids = ae_normalize_id_list($in['ids'] ?? null);
    if (!$ids) ae_out(false, null, 'Brak ids[].');
    if (count($ids) > 500) ae_out(false, null, 'Za duzo assetow naraz (max 500).');

    $placeholders = [];
    $params = [':tid' => $tenantId];
    foreach ($ids as $i => $id) {
        $ph = ':id_' . $i;
        $placeholders[] = $ph;
        $params[$ph] = $id;
    }
    $in_sql = 'id IN (' . implode(',', $placeholders) . ')';

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE sh_assets SET deleted_at = CURRENT_TIMESTAMP, is_active = 0
             WHERE tenant_id = :tid AND $in_sql"
        )->execute($params);

        $pdo->prepare(
            "UPDATE sh_asset_links SET deleted_at = CURRENT_TIMESTAMP, is_active = 0
             WHERE tenant_id = :tid AND asset_id IN ($in_sql) AND deleted_at IS NULL"
            // Re-use params by aliasing the list in the subquery via same placeholders
        )->execute($params);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        ae_out(false, null, 'Blad bulk_soft_delete: ' . $e->getMessage());
    }

    ae_out(true, ['deleted' => count($ids), 'ids' => $ids], 'OK — oznaczono jako usuniete: ' . count($ids));
}

/**
 * duplicate — sklonuj asset (ten sam storage_url, nowy ascii_key, zero linków).
 * Body: { id: int, [display_name], [category_override], [cook_state_override] }
 */
function ae_handle_duplicate(PDO $pdo, int $tenantId, int $userId, array $in): void {
    $id = ae_int($in, 'id');
    if ($id <= 0) ae_out(false, null, 'Brak id.');

    $asset = ae_fetch_asset_owned($pdo, $tenantId, $id);
    if (!$asset) ae_out(false, null, 'Asset nie znaleziony.');

    $newDisplay = ae_safe_display_name(ae_str($in, 'display_name'));
    if ($newDisplay === '') {
        $base = (string)($asset['display_name'] ?? $asset['ascii_key']);
        $newDisplay = ae_safe_display_name($base . ' (kopia)');
    }
    $newCat = isset($in['category']) ? ae_str($in, 'category') : (string)$asset['category'];
    if (!in_array($newCat, AE_CATEGORIES, true)) $newCat = (string)$asset['category'];
    $newCook = isset($in['cook_state']) ? ae_str($in, 'cook_state') : (string)$asset['cook_state'];
    if (!in_array($newCook, AE_COOK_STATES, true)) $newCook = (string)$asset['cook_state'];

    $newAk = ae_build_pretty_ascii_key($pdo, $tenantId, $newCat, (string)($asset['sub_type'] ?? ''), $newDisplay);
    if ($newAk === '') ae_out(false, null, 'Nie udalo sie wygenerowac unikalnego ascii_key.');

    try {
        $ins = $pdo->prepare(
            'INSERT INTO sh_assets
               (tenant_id, ascii_key, display_name, tags_json,
                storage_url, storage_bucket, mime_type,
                width_px, height_px, filesize_bytes, has_alpha, checksum_sha256,
                role_hint, category, sub_type, cook_state, z_order_hint,
                variant_of, variant_kind,
                metadata_json, is_active, created_at, created_by_user)
             VALUES
               (:tid, :ak, :dn, :tags,
                :url, :buc, :mime,
                :w, :h, :sz, :alpha, :sha,
                :rh, :cat, :sub, :ck, :z,
                :vof, :vkind,
                :meta, 1, CURRENT_TIMESTAMP, :cub)'
        );
        $ins->execute([
            ':tid'   => $tenantId,
            ':ak'    => $newAk,
            ':dn'    => $newDisplay,
            ':tags'  => $asset['tags_json'] ?: null,
            ':url'   => $asset['storage_url'],
            ':buc'   => $asset['storage_bucket'],
            ':mime'  => $asset['mime_type'],
            ':w'     => $asset['width_px'],
            ':h'     => $asset['height_px'],
            ':sz'    => $asset['filesize_bytes'],
            ':alpha' => $asset['has_alpha'],
            ':sha'   => $asset['checksum_sha256'],
            ':rh'    => $asset['role_hint'],
            ':cat'   => $newCat,
            ':sub'   => $asset['sub_type'],
            ':ck'    => $newCook,
            ':z'     => $asset['z_order_hint'],
            ':vof'   => (int)$asset['id'],
            ':vkind' => 'duplicate',
            ':meta'  => $asset['metadata_json'],
            ':cub'   => (string)$userId,
        ]);
        $newId = (int)$pdo->lastInsertId();
    } catch (\PDOException $e) {
        ae_out(false, null, 'Blad duplikacji: ' . $e->getMessage());
    }

    ae_out(true, [
        'id'          => $newId,
        'asciiKey'    => $newAk,
        'displayName' => $newDisplay,
        'sourceId'    => $id,
    ], 'OK — sklonowano asset.');
}

/**
 * merge_duplicates — scala grupę dubli: wybiera "keepera", przenosi jego linki
 * i linki pozostałych assetów (o tym samym SHA) na keepera (ON DUPLICATE merge),
 * a pozostałe assety soft-deletuje.
 * Body: { keeper_id: int, merge_ids: [int,...] }
 */
function ae_handle_merge_duplicates(PDO $pdo, int $tenantId, array $in): void {
    $keeper = ae_int($in, 'keeper_id');
    $mergeIds = ae_normalize_id_list($in['merge_ids'] ?? null);
    if ($keeper <= 0 || !$mergeIds) ae_out(false, null, 'Brak keeper_id lub merge_ids[].');
    $mergeIds = array_values(array_filter($mergeIds, fn($x) => $x !== $keeper));
    if (!$mergeIds) ae_out(false, null, 'merge_ids musi zawierac inne ID niz keeper.');

    $keeperAsset = ae_fetch_asset_owned($pdo, $tenantId, $keeper);
    if (!$keeperAsset) ae_out(false, null, 'Keeper nie znaleziony.');

    $pdo->beginTransaction();
    try {
        // Przenieś linki z każdego duplikatu na keepera (ON DUPLICATE = zachowaj istniejący)
        $selLinks = $pdo->prepare(
            'SELECT id, entity_type, entity_ref, role FROM sh_asset_links
             WHERE tenant_id = :tid AND asset_id = :src
               AND is_active = 1 AND deleted_at IS NULL'
        );
        $updAssetId = $pdo->prepare(
            'UPDATE IGNORE sh_asset_links SET asset_id = :keeper
             WHERE tenant_id = :tid AND id = :link_id'
        );
        $deleteLink = $pdo->prepare(
            'UPDATE sh_asset_links SET deleted_at = CURRENT_TIMESTAMP, is_active = 0
             WHERE tenant_id = :tid AND id = :link_id'
        );

        $relinked = 0;
        foreach ($mergeIds as $src) {
            $selLinks->execute([':tid' => $tenantId, ':src' => $src]);
            $links = $selLinks->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($links as $l) {
                $updAssetId->execute([':tid' => $tenantId, ':keeper' => $keeper, ':link_id' => (int)$l['id']]);
                if ($updAssetId->rowCount() > 0) {
                    $relinked++;
                } else {
                    // UNIQUE collision (keeper juz ma ten link) — soft-delete stary
                    $deleteLink->execute([':tid' => $tenantId, ':link_id' => (int)$l['id']]);
                }
            }
        }

        // Soft-delete asset duplikatów
        $placeholders = [];
        $params = [':tid' => $tenantId];
        foreach ($mergeIds as $i => $id) {
            $ph = ':id_' . $i;
            $placeholders[] = $ph;
            $params[$ph] = $id;
        }
        $pdo->prepare(
            'UPDATE sh_assets SET deleted_at = CURRENT_TIMESTAMP, is_active = 0,
                                  variant_of = :keeper_for_trace, variant_kind = ' . "'merged_into'" . '
             WHERE tenant_id = :tid AND id IN (' . implode(',', $placeholders) . ')'
        )->execute(array_merge($params, [':keeper_for_trace' => $keeper]));

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        ae_out(false, null, 'Blad merge_duplicates: ' . $e->getMessage());
    }

    ae_out(true, [
        'keeperId' => $keeper,
        'merged'   => count($mergeIds),
        'relinked' => $relinked,
    ], 'OK — scalono ' . count($mergeIds) . ' dubli na keepera ' . $keeper . '.');
}

/**
 * scan_health — szybkie statystyki porządku dla całej biblioteki tenanta.
 * Zwraca liczby + próbki id (do 50) dla każdej kategorii problemu.
 */
function ae_handle_scan_health(PDO $pdo, int $tenantId): void {
    $base = "(tenant_id = :tid OR tenant_id = 0) AND deleted_at IS NULL AND is_active = 1";

    // Orphans
    $q = $pdo->prepare(
        "SELECT COUNT(*) FROM sh_assets a
         WHERE $base
           AND NOT EXISTS (SELECT 1 FROM sh_asset_links al
                           WHERE al.asset_id = a.id
                             AND al.is_active = 1
                             AND al.deleted_at IS NULL)"
    );
    $q->execute([':tid' => $tenantId]);
    $orphans = (int)$q->fetchColumn();

    // Duplicates — ile assetów jest w grupach >1 po SHA
    $q = $pdo->prepare(
        "SELECT COUNT(*) FROM sh_assets a
         WHERE $base AND a.checksum_sha256 IS NOT NULL
           AND a.checksum_sha256 IN (
               SELECT checksum_sha256 FROM (
                   SELECT checksum_sha256, COUNT(*) c FROM sh_assets
                   WHERE $base AND checksum_sha256 IS NOT NULL
                   GROUP BY checksum_sha256 HAVING c > 1
               ) x
           )"
    );
    $q->execute([':tid' => $tenantId]);
    $duplicates = (int)$q->fetchColumn();

    // Missing category / cook_state
    $q = $pdo->prepare(
        "SELECT COUNT(*) FROM sh_assets a
         WHERE $base AND (a.category IS NULL OR a.category = '' OR a.category = 'misc')"
    );
    $q->execute([':tid' => $tenantId]);
    $missingCategory = (int)$q->fetchColumn();

    $q = $pdo->prepare(
        "SELECT COUNT(*) FROM sh_assets a
         WHERE $base AND a.cook_state = 'either'
           AND a.category IN ('cheese','meat','veg','sauce','herb','base')"
    );
    $q->execute([':tid' => $tenantId]);
    $missingCookState = (int)$q->fetchColumn();

    // Duże pliki (>2MB)
    $q = $pdo->prepare(
        "SELECT COUNT(*) FROM sh_assets a
         WHERE $base AND a.filesize_bytes >= 2097152"
    );
    $q->execute([':tid' => $tenantId]);
    $largeFiles = (int)$q->fetchColumn();

    // Bez display_name
    $q = $pdo->prepare(
        "SELECT COUNT(*) FROM sh_assets a
         WHERE $base AND (a.display_name IS NULL OR a.display_name = '')"
    );
    $q->execute([':tid' => $tenantId]);
    $missingDisplayName = (int)$q->fetchColumn();

    // Rozkład kategorii (dla statystyk)
    $q = $pdo->prepare(
        "SELECT COALESCE(NULLIF(a.category, ''), 'misc') AS cat, COUNT(*) AS cnt
         FROM sh_assets a
         WHERE $base
         GROUP BY cat
         ORDER BY cnt DESC"
    );
    $q->execute([':tid' => $tenantId]);
    $byCategory = array_map(
        fn($r) => ['category' => (string)$r['cat'], 'count' => (int)$r['cnt']],
        $q->fetchAll(PDO::FETCH_ASSOC) ?: []
    );

    // Łącznie
    $q = $pdo->prepare("SELECT COUNT(*) FROM sh_assets a WHERE $base");
    $q->execute([':tid' => $tenantId]);
    $total = (int)$q->fetchColumn();

    // Grupy duplikatów (dla widoku "Scal duble")
    $q = $pdo->prepare(
        "SELECT checksum_sha256, COUNT(*) c, GROUP_CONCAT(id ORDER BY created_at ASC) ids
         FROM sh_assets
         WHERE $base AND checksum_sha256 IS NOT NULL
         GROUP BY checksum_sha256 HAVING c > 1
         ORDER BY c DESC
         LIMIT 30"
    );
    $q->execute([':tid' => $tenantId]);
    $dupGroups = array_map(function ($r) {
        return [
            'checksum' => (string)$r['checksum_sha256'],
            'count'    => (int)$r['c'],
            'ids'      => array_map('intval', explode(',', (string)$r['ids'])),
        ];
    }, $q->fetchAll(PDO::FETCH_ASSOC) ?: []);

    ae_out(true, [
        'total'              => $total,
        'orphans'            => $orphans,
        'duplicates'         => $duplicates,
        'duplicateGroups'    => $dupGroups,
        'missingCategory'    => $missingCategory,
        'missingCookState'   => $missingCookState,
        'missingDisplayName' => $missingDisplayName,
        'largeFiles'         => $largeFiles,
        'byCategory'         => $byCategory,
    ]);
}

/**
 * rename_smart — zmień display_name + (opcjonalnie) regeneruj ascii_key ze sluga.
 * Body: { id, display_name, regenerate_ascii_key?: bool }
 */
function ae_handle_rename_smart(PDO $pdo, int $tenantId, array $in): void {
    $id = ae_int($in, 'id');
    if ($id <= 0) ae_out(false, null, 'Brak id.');
    $dn = ae_safe_display_name(ae_str($in, 'display_name'));
    if ($dn === '') ae_out(false, null, 'Pusta display_name.');

    $asset = ae_fetch_asset_owned($pdo, $tenantId, $id);
    if (!$asset) ae_out(false, null, 'Asset nie znaleziony.');

    $sets = ['display_name = :dn'];
    $params = [':tid' => $tenantId, ':id' => $id, ':dn' => $dn];

    if (ae_bool($in, 'regenerate_ascii_key', false)) {
        $newAk = ae_build_pretty_ascii_key(
            $pdo, $tenantId,
            (string)($asset['category'] ?? 'misc'),
            (string)($asset['sub_type'] ?? ''),
            $dn, $id
        );
        if ($newAk !== '') {
            $sets[] = 'ascii_key = :ak';
            $params[':ak'] = $newAk;
        }
    }

    $pdo->prepare('UPDATE sh_assets SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tid AND id = :id LIMIT 1')
        ->execute($params);

    ae_out(true, [
        'id'          => $id,
        'displayName' => $dn,
        'asciiKey'    => isset($params[':ak']) ? $params[':ak'] : $asset['ascii_key'],
    ], 'OK — przemianowano.');
}

/** Normalizacja ids[] — akceptuje array int|string, deduplikuje, odrzuca <=0. */
function ae_normalize_id_list($raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $v) {
        $i = (int)$v;
        if ($i > 0) $out[$i] = true;
    }
    return array_keys($out);
}

// =============================================================================
// HELPERS
// =============================================================================

function ae_fetch_asset_owned(PDO $pdo, int $tenantId, int $id): ?array {
    $q = $pdo->prepare('SELECT * FROM sh_assets WHERE id = :id AND tenant_id = :tid LIMIT 1');
    $q->execute([':id' => $id, ':tid' => $tenantId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ae_asset_row_to_api(array $r): array {
    $storageUrl = (string)($r['storage_url'] ?? '');
    $publicUrl  = ae_to_public_url($storageUrl);
    $tags = [];
    if (!empty($r['tags_json'])) {
        $decoded = json_decode((string)$r['tags_json'], true);
        if (is_array($decoded)) {
            $tags = array_values(array_filter(array_map('strval', $decoded), fn($t) => $t !== ''));
        }
    }
    return [
        'id'             => (int)$r['id'],
        'tenantId'       => (int)$r['tenant_id'],
        'asciiKey'       => (string)$r['ascii_key'],
        'displayName'    => $r['display_name'] ?: null,
        'tags'           => $tags,
        'storageUrl'     => $storageUrl,
        'publicUrl'      => $publicUrl,
        'bucket'         => (string)$r['storage_bucket'],
        'mimeType'       => (string)($r['mime_type'] ?? ''),
        'width'          => $r['width_px']  !== null ? (int)$r['width_px']  : null,
        'height'         => $r['height_px'] !== null ? (int)$r['height_px'] : null,
        'filesizeBytes'  => $r['filesize_bytes'] !== null ? (int)$r['filesize_bytes'] : null,
        'hasAlpha'       => (int)$r['has_alpha'] === 1,
        'checksum'       => $r['checksum_sha256'] ?: null,
        'roleHint'       => $r['role_hint'] ?: null,
        'category'       => $r['category']  ?: null,
        'subType'        => $r['sub_type']  ?: null,
        'cookState'      => isset($r['cook_state']) ? (string)$r['cook_state'] : 'either',
        'zOrderHint'     => (int)$r['z_order_hint'],
        'variantOf'      => $r['variant_of'] !== null ? (int)$r['variant_of'] : null,
        'variantKind'    => $r['variant_kind'] ?: null,
        'metadata'       => $r['metadata_json'] ? json_decode((string)$r['metadata_json'], true) : null,
        'isActive'       => (int)$r['is_active'] === 1,
        'deletedAt'      => $r['deleted_at'] ?: null,
        'linkCount'      => isset($r['link_count']) ? (int)$r['link_count'] : 0,
        'duplicateCount' => isset($r['duplicate_count']) ? (int)$r['duplicate_count'] : 0,
        'createdAt'      => $r['created_at'] ?: null,
        'updatedAt'      => $r['updated_at'] ?: null,
        'createdByUser'  => $r['created_by_user'] ?: null,
    ];
}

/** Buduje pełny URL dla klienta (z prefixem /slicehub/) albo zwraca URL external. */
function ae_to_public_url(string $storageUrl): string {
    if ($storageUrl === '') return '';
    if (preg_match('#^https?://#i', $storageUrl)) return $storageUrl;
    // Usuń ewentualny leading slash żeby nie dublować
    $clean = ltrim($storageUrl, '/');
    return '/slicehub/' . $clean;
}

/**
 * Waliduj entity_ref przed utworzeniem linka. Zwraca null = OK, string = błąd.
 */
function ae_verify_entity_ref(PDO $pdo, int $tenantId, string $type, string $ref): ?string {
    switch ($type) {
        case 'menu_item': {
            $q = $pdo->prepare('SELECT id FROM sh_menu_items WHERE tenant_id = :tid AND ascii_key = :ref LIMIT 1');
            $q->execute([':tid' => $tenantId, ':ref' => $ref]);
            return $q->fetchColumn() ? null : 'menu_item o ascii_key="' . $ref . '" nie istnieje.';
        }
        case 'modifier': {
            $q = $pdo->prepare(
                'SELECT m.id FROM sh_modifiers m
                 INNER JOIN sh_modifier_groups mg ON mg.id = m.group_id
                 WHERE mg.tenant_id = :tid AND m.ascii_key = :ref LIMIT 1'
            );
            $q->execute([':tid' => $tenantId, ':ref' => $ref]);
            return $q->fetchColumn() ? null : 'modifier o ascii_key="' . $ref . '" nie istnieje.';
        }
        case 'visual_layer':
        case 'board_companion':
        case 'atelier_scene':
        case 'scene_layer':
        case 'tenant_brand':
        case 'surface_library':
            // Luźny typ — akceptuj bez walidacji (composite ref lub zewnętrzny)
            return null;
        default:
            return 'Nieznany entity_type: ' . $type;
    }
}

/** Human-friendly label encji dla panelu "Gdzie używane?". */
function ae_resolve_entity_label(PDO $pdo, int $tenantId, string $type, string $ref): string {
    try {
        switch ($type) {
            case 'menu_item': {
                $q = $pdo->prepare('SELECT name FROM sh_menu_items WHERE tenant_id = :tid AND ascii_key = :ref LIMIT 1');
                $q->execute([':tid' => $tenantId, ':ref' => $ref]);
                $name = $q->fetchColumn();
                return $name ? "🍕 {$name}" : "🍕 {$ref}";
            }
            case 'modifier': {
                $q = $pdo->prepare(
                    'SELECT m.name, mg.name AS group_name FROM sh_modifiers m
                     INNER JOIN sh_modifier_groups mg ON mg.id = m.group_id
                     WHERE mg.tenant_id = :tid AND m.ascii_key = :ref LIMIT 1'
                );
                $q->execute([':tid' => $tenantId, ':ref' => $ref]);
                $row = $q->fetch(PDO::FETCH_ASSOC);
                return $row ? "🧂 {$row['group_name']} / {$row['name']}" : "🧂 {$ref}";
            }
            case 'visual_layer':    return "🎬 {$ref}";
            case 'board_companion': return "🥤 {$ref}";
            case 'atelier_scene':   return "🎭 scena:{$ref}";
            case 'scene_layer':     return "🎞 {$ref}";
            case 'tenant_brand':    return "🏷 brand:{$ref}";
            case 'surface_library': return "🧱 surface:{$ref}";
            default:                return "📎 {$type}:{$ref}";
        }
    } catch (\Throwable $e) {
        return "{$type}:{$ref}";
    }
}
