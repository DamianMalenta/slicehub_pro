<?php

declare(strict_types=1);

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

/**
 * SliceHub Enterprise — Tenant Legal Profile Engine
 *
 * /api/backoffice/profile/engine.php
 *
 * Akcje (pole `action` w JSON body):
 *   - legal_profile_get    — wszystkie dane statutowe + finansowe (1 response)
 *   - legal_profile_save   — partial save sekcjami (statutory | financial | branding)
 *
 * AUTH:
 *   - Każda akcja wymaga zalogowanego usera (auth_guard.php).
 *   - Save wymaga roli `owner` (twardo). Read dostępny dla owner/admin.
 *     Manager / cook / waiter / driver — 403.
 *
 * AUDIT:
 *   - Każdy save zapisuje wpis do `sh_settings_audit` z action='legal_profile_save',
 *     entity_type='legal_profile', before_json, after_json.
 *
 * SCHEMA:
 *   - `sh_tenant.nip` + `sh_tenant.slug` (kolumny — używane w WHERE/UNIQUE)
 *   - `sh_tenant_settings` z prefiksem `legal_*` (KV — dane dokumentowe)
 *   - Klient widzi WSZYSTKO w jednym JSON-ie z `legal_profile_get`.
 *
 * Walidacja:
 *   - NIP — 10 cyfr po normalizacji + checksum (algorytm GUS)
 *   - REGON — 9 lub 14 cyfr + checksum
 *   - KRS — 10 cyfr (bez checksumy)
 *   - Slug — ^[a-z][a-z0-9_-]{1,63}$
 *   - IBAN PL — PL + 26 cyfr + MOD 97 == 1
 *   - Email — filter_var FILTER_VALIDATE_EMAIL
 *   - Kod pocztowy — XX-XXX
 *
 * Spec: wizja Architekta z 2026-05-11.
 */

function profileResponse(bool $ok, $data = null, ?string $msg = null, ?string $code = null): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $out = ['success' => $ok, 'data' => $data, 'message' => $msg];
    if ($code !== null) {
        $out['code'] = $code;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

function profileFail(int $httpCode, string $code, ?string $msg = null): void
{
    http_response_code($httpCode);
    profileResponse(false, null, $msg ?? $code, $code);
}

// =============================================================================
// Walidatory
// =============================================================================

/** Normalizuj NIP — usuń wszystkie znaki niebędące cyframi. */
function profileNormalizeNip(string $nip): string
{
    return preg_replace('/\D+/', '', $nip) ?? '';
}

/** Walidacja NIP (10 cyfr + checksum GUS). */
function profileValidateNip(string $nip): bool
{
    $nip = profileNormalizeNip($nip);
    if (!preg_match('/^\d{10}$/', $nip)) {
        return false;
    }
    $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += (int)$nip[$i] * $weights[$i];
    }
    $check = $sum % 11;
    if ($check === 10) {
        return false;
    }
    return $check === (int)$nip[9];
}

/** Walidacja REGON (9 lub 14 cyfr + checksum GUS). */
function profileValidateRegon(string $regon): bool
{
    $regon = preg_replace('/\D+/', '', $regon) ?? '';
    if (!in_array(strlen($regon), [9, 14], true)) {
        return false;
    }
    if (strlen($regon) === 9) {
        $weights = [8, 9, 2, 3, 4, 5, 6, 7];
        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += (int)$regon[$i] * $weights[$i];
        }
        $check = $sum % 11;
        if ($check === 10) {
            $check = 0;
        }
        return $check === (int)$regon[8];
    }
    // 14-cyfrowy: najpierw walidacja pierwszych 9, potem dodatkowy check na 14
    if (!profileValidateRegon(substr($regon, 0, 9))) {
        return false;
    }
    $weights14 = [2, 4, 8, 5, 0, 9, 7, 3, 6, 1, 2, 4, 8];
    $sum = 0;
    for ($i = 0; $i < 13; $i++) {
        $sum += (int)$regon[$i] * $weights14[$i];
    }
    $check = $sum % 11;
    if ($check === 10) {
        $check = 0;
    }
    return $check === (int)$regon[13];
}

/** Walidacja KRS (10 cyfr, bez checksumy). */
function profileValidateKrs(string $krs): bool
{
    return (bool) preg_match('/^\d{10}$/', preg_replace('/\D+/', '', $krs) ?? '');
}

/** Walidacja IBAN PL (PL + 26 cyfr + MOD 97 == 1). */
function profileValidateIbanPL(string $iban): bool
{
    $iban = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
    if (!preg_match('/^PL\d{26}$/', $iban)) {
        return false;
    }
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric = '';
    for ($i = 0, $n = strlen($rearranged); $i < $n; $i++) {
        $c = $rearranged[$i];
        if (ctype_digit($c)) {
            $numeric .= $c;
        } else {
            $numeric .= (string)(ord($c) - 55);
        }
    }
    $remainder = 0;
    for ($i = 0, $n = strlen($numeric); $i < $n; $i++) {
        $remainder = ($remainder * 10 + (int)$numeric[$i]) % 97;
    }
    return $remainder === 1;
}

/** Walidacja slug org-poziom: małe litery, cyfry, '-' '_', start z litery. */
function profileValidateSlug(string $slug): bool
{
    return (bool) preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $slug);
}

/** Walidacja kodu pocztowego PL: XX-XXX. */
function profileValidatePostalPL(string $code): bool
{
    return (bool) preg_match('/^\d{2}-\d{3}$/', $code);
}

// =============================================================================
// Audit helper
// =============================================================================

function profileAuditLog(
    PDO $pdo,
    int $tenantId,
    int $userId,
    array $beforeData,
    array $afterData
): void {
    try {
        $st = $pdo->prepare(
            "INSERT INTO sh_settings_audit
                (tenant_id, user_id, actor_ip, action, entity_type, entity_id, before_json, after_json)
             VALUES
                (:tid, :uid, :ip, 'legal_profile_save', 'legal_profile', :eid, :before, :after)"
        );
        $st->execute([
            ':tid'    => $tenantId,
            ':uid'    => $userId,
            ':ip'     => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ':eid'    => $tenantId,
            ':before' => json_encode($beforeData, JSON_UNESCAPED_UNICODE),
            ':after'  => json_encode($afterData, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (\Throwable $e) {
        error_log('[profile/engine] audit failed: ' . $e->getMessage());
    }
}

// =============================================================================
// Role check
// =============================================================================

function profileLoadActorRole(PDO $pdo, int $tenantId, int $userId): string
{
    $st = $pdo->prepare(
        'SELECT LOWER(role) FROM sh_users WHERE id = :uid AND tenant_id = :tid AND is_deleted = 0 LIMIT 1'
    );
    $st->execute([':uid' => $userId, ':tid' => $tenantId]);
    $r = $st->fetchColumn();
    return is_string($r) ? $r : '';
}

// =============================================================================
// Settings KV helpers (legal_* prefix)
// =============================================================================

const PROFILE_LEGAL_KEYS = [
    'legal_company_name',
    'legal_legal_form',
    'legal_regon',
    'legal_krs',
    'legal_address_street',
    'legal_address_postal',
    'legal_address_city',
    'legal_address_country',
    'legal_invoice_email',
    'legal_bank_name',
    'legal_bank_iban',
    'legal_bank_swift',
    'legal_vat_payer',
    'legal_fiscal_no',
];

function profileLoadAllLegalKv(PDO $pdo, int $tenantId): array
{
    $placeholders = implode(',', array_fill(0, count(PROFILE_LEGAL_KEYS), '?'));
    $st = $pdo->prepare(
        "SELECT setting_key, setting_value
           FROM sh_tenant_settings
          WHERE tenant_id = ? AND setting_key IN ($placeholders)"
    );
    $st->execute(array_merge([$tenantId], PROFILE_LEGAL_KEYS));
    $out = array_fill_keys(PROFILE_LEGAL_KEYS, '');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['setting_key']] = (string)($row['setting_value'] ?? '');
    }
    return $out;
}

function profileSaveLegalKv(PDO $pdo, int $tenantId, string $key, string $value): void
{
    if (!in_array($key, PROFILE_LEGAL_KEYS, true)) {
        throw new \InvalidArgumentException("Klucz '{$key}' nie jest dozwolony.");
    }
    $st = $pdo->prepare(
        "INSERT INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
         VALUES (:tid, :k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $st->execute([':tid' => $tenantId, ':k' => $key, ':v' => $value]);
}

// =============================================================================
// Main
// =============================================================================

try {
    require_once __DIR__ . '/../../../core/db_config.php';
    require_once __DIR__ . '/../../../core/auth_guard.php';

    /** @var PDO $pdo */
    /** @var int $tenant_id */
    /** @var int $user_id */

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        profileFail(405, 'METHOD_NOT_ALLOWED', 'Only POST is allowed.');
    }

    $raw = file_get_contents('php://input') ?: '{}';
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        profileFail(400, 'INVALID_JSON', 'Request body must be a JSON object.');
    }

    $action = trim((string)($input['action'] ?? ''));
    if ($action === '') {
        profileFail(400, 'ACTION_REQUIRED', 'Missing "action" field.');
    }

    $actorRole = profileLoadActorRole($pdo, $tenant_id, $user_id);

    switch ($action) {
        // -----------------------------------------------------------------
        case 'legal_profile_get': {
            if (!in_array($actorRole, ['owner', 'admin'], true)) {
                profileFail(403, 'FORBIDDEN', 'Tylko owner / admin może odczytać Profil Firmy.');
            }

            $st = $pdo->prepare('SELECT id, name, nip, slug, created_at FROM sh_tenant WHERE id = :tid LIMIT 1');
            $st->execute([':tid' => $tenant_id]);
            $tenant = $st->fetch(PDO::FETCH_ASSOC);
            if (!$tenant) {
                profileFail(404, 'TENANT_NOT_FOUND', 'Tenant nie istnieje.');
            }

            $kv = profileLoadAllLegalKv($pdo, $tenant_id);

            profileResponse(true, [
                'tenant' => [
                    'id'         => (int)$tenant['id'],
                    'name'       => (string)$tenant['name'],
                    'nip'        => (string)($tenant['nip'] ?? ''),
                    'slug'       => (string)($tenant['slug'] ?? ''),
                    'created_at' => (string)$tenant['created_at'],
                ],
                'statutory' => [
                    'company_name'    => $kv['legal_company_name'],
                    'legal_form'      => $kv['legal_legal_form'],
                    'regon'           => $kv['legal_regon'],
                    'krs'             => $kv['legal_krs'],
                    'address_street'  => $kv['legal_address_street'],
                    'address_postal'  => $kv['legal_address_postal'],
                    'address_city'    => $kv['legal_address_city'],
                    'address_country' => $kv['legal_address_country'] !== '' ? $kv['legal_address_country'] : 'PL',
                    'vat_payer'       => $kv['legal_vat_payer'] === '1',
                ],
                'financial' => [
                    'invoice_email' => $kv['legal_invoice_email'],
                    'bank_name'     => $kv['legal_bank_name'],
                    'bank_iban'     => $kv['legal_bank_iban'],
                    'bank_swift'    => $kv['legal_bank_swift'],
                    'fiscal_no'     => $kv['legal_fiscal_no'],
                ],
                'meta' => [
                    'editable'     => $actorRole === 'owner',
                    'actor_role'   => $actorRole,
                    'storefront_hint' => 'Dane klient-facing (tagline, godziny, telefon kontaktowy klientów) edytuj w Online Studio → Storefront.',
                ],
            ], 'OK');
            break;
        }

        // -----------------------------------------------------------------
        case 'legal_profile_save': {
            if ($actorRole !== 'owner') {
                profileFail(403, 'FORBIDDEN_OWNER_ONLY',
                    'Tylko owner może edytować dane statutowe firmy. Aktualna rola: ' . ($actorRole ?: 'unknown') . '.');
            }

            // Wczytanie before-state dla audit
            $stBefore = $pdo->prepare('SELECT name, nip, slug FROM sh_tenant WHERE id = :tid LIMIT 1');
            $stBefore->execute([':tid' => $tenant_id]);
            $beforeTenant = $stBefore->fetch(PDO::FETCH_ASSOC) ?: [];
            $beforeKv = profileLoadAllLegalKv($pdo, $tenant_id);

            $errors = [];
            $changes = []; // co realnie się zmieniło — dla after_json + walidacji

            // --- BRAND: name + slug + nip (kolumny sh_tenant)
            if (isset($input['brand']) && is_array($input['brand'])) {
                $b = $input['brand'];

                if (array_key_exists('name', $b)) {
                    $name = trim((string)$b['name']);
                    if ($name === '') {
                        $errors[] = 'Nazwa marketingowa nie może być pusta.';
                    } elseif (mb_strlen($name) > 255) {
                        $errors[] = 'Nazwa marketingowa: max 255 znaków.';
                    } else {
                        $changes['tenant.name'] = $name;
                    }
                }

                if (array_key_exists('slug', $b)) {
                    $slug = trim(strtolower((string)$b['slug']));
                    if ($slug === '') {
                        $changes['tenant.slug'] = null;
                    } elseif (!profileValidateSlug($slug)) {
                        $errors[] = 'Slug: dozwolone małe litery, cyfry, "-", "_". Musi zaczynać się literą. Max 64 znaki.';
                    } else {
                        $changes['tenant.slug'] = $slug;
                    }
                }

                if (array_key_exists('nip', $b)) {
                    $nip = trim((string)$b['nip']);
                    if ($nip === '') {
                        $changes['tenant.nip'] = null;
                    } elseif (!profileValidateNip($nip)) {
                        $errors[] = 'NIP: nieprawidłowa checksuma. Wpisz 10 cyfr.';
                    } else {
                        $changes['tenant.nip'] = profileNormalizeNip($nip);
                    }
                }
            }

            // --- STATUTORY: KV legal_*
            if (isset($input['statutory']) && is_array($input['statutory'])) {
                $s = $input['statutory'];

                if (array_key_exists('company_name', $s)) {
                    $v = trim((string)$s['company_name']);
                    if (mb_strlen($v) > 255) $errors[] = 'Nazwa rejestrowa: max 255 znaków.';
                    else $changes['legal_company_name'] = $v;
                }

                if (array_key_exists('legal_form', $s)) {
                    $v = trim((string)$s['legal_form']);
                    $allowed = ['', 'jdg', 'sp_zoo', 'sa', 'sk', 'sj', 'sp_komandytowa', 'fundacja', 'stowarzyszenie', 'inne'];
                    if (!in_array($v, $allowed, true)) {
                        $errors[] = 'Forma prawna: dozwolone: ' . implode(', ', array_filter($allowed)) . '.';
                    } else {
                        $changes['legal_legal_form'] = $v;
                    }
                }

                if (array_key_exists('regon', $s)) {
                    $v = preg_replace('/\D+/', '', trim((string)$s['regon'])) ?? '';
                    if ($v !== '' && !profileValidateRegon($v)) {
                        $errors[] = 'REGON: nieprawidłowy (9 lub 14 cyfr + checksum).';
                    } else {
                        $changes['legal_regon'] = $v;
                    }
                }

                if (array_key_exists('krs', $s)) {
                    $v = preg_replace('/\D+/', '', trim((string)$s['krs'])) ?? '';
                    if ($v !== '' && !profileValidateKrs($v)) {
                        $errors[] = 'KRS: musi mieć dokładnie 10 cyfr.';
                    } else {
                        $changes['legal_krs'] = $v;
                    }
                }

                if (array_key_exists('address_street', $s)) {
                    $v = trim((string)$s['address_street']);
                    if (mb_strlen($v) > 255) $errors[] = 'Ulica: max 255 znaków.';
                    else $changes['legal_address_street'] = $v;
                }

                if (array_key_exists('address_postal', $s)) {
                    $v = trim((string)$s['address_postal']);
                    if ($v !== '' && !profileValidatePostalPL($v)) {
                        $errors[] = 'Kod pocztowy: format XX-XXX (np. 00-001).';
                    } else {
                        $changes['legal_address_postal'] = $v;
                    }
                }

                if (array_key_exists('address_city', $s)) {
                    $v = trim((string)$s['address_city']);
                    if (mb_strlen($v) > 120) $errors[] = 'Miasto: max 120 znaków.';
                    else $changes['legal_address_city'] = $v;
                }

                if (array_key_exists('address_country', $s)) {
                    $v = strtoupper(trim((string)$s['address_country']));
                    if ($v !== '' && !preg_match('/^[A-Z]{2}$/', $v)) {
                        $errors[] = 'Kraj: kod ISO 2-literowy (np. PL).';
                    } else {
                        $changes['legal_address_country'] = $v !== '' ? $v : 'PL';
                    }
                }

                if (array_key_exists('vat_payer', $s)) {
                    $changes['legal_vat_payer'] = !empty($s['vat_payer']) ? '1' : '0';
                }
            }

            // --- FINANCIAL: KV legal_*
            if (isset($input['financial']) && is_array($input['financial'])) {
                $f = $input['financial'];

                if (array_key_exists('invoice_email', $f)) {
                    $v = trim((string)$f['invoice_email']);
                    if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = 'E-mail do faktur: nieprawidłowy format.';
                    } else {
                        $changes['legal_invoice_email'] = $v;
                    }
                }

                if (array_key_exists('bank_name', $f)) {
                    $v = trim((string)$f['bank_name']);
                    if (mb_strlen($v) > 120) $errors[] = 'Nazwa banku: max 120 znaków.';
                    else $changes['legal_bank_name'] = $v;
                }

                if (array_key_exists('bank_iban', $f)) {
                    $v = strtoupper(preg_replace('/\s+/', '', trim((string)$f['bank_iban'])) ?? '');
                    if ($v !== '' && !profileValidateIbanPL($v)) {
                        $errors[] = 'IBAN: nieprawidłowy (oczekiwany PL + 26 cyfr, MOD 97 == 1).';
                    } else {
                        $changes['legal_bank_iban'] = $v;
                    }
                }

                if (array_key_exists('bank_swift', $f)) {
                    $v = strtoupper(trim((string)$f['bank_swift']));
                    if ($v !== '' && !preg_match('/^[A-Z0-9]{8,11}$/', $v)) {
                        $errors[] = 'SWIFT/BIC: 8 lub 11 znaków alfanumerycznych.';
                    } else {
                        $changes['legal_bank_swift'] = $v;
                    }
                }

                if (array_key_exists('fiscal_no', $f)) {
                    $v = trim((string)$f['fiscal_no']);
                    if (mb_strlen($v) > 64) $errors[] = 'Numer fiskalny: max 64 znaki.';
                    else $changes['legal_fiscal_no'] = $v;
                }
            }

            if ($errors !== []) {
                profileFail(400, 'VALIDATION_ERROR', implode(' ', $errors));
            }

            if ($changes === []) {
                profileResponse(true, ['updated' => 0], 'Brak zmian do zapisania.');
            }

            // Atomowy save
            $pdo->beginTransaction();
            try {
                // Kolumny sh_tenant
                $tenantUpdates = [];
                $tenantParams = [':tid' => $tenant_id];
                foreach (['tenant.name' => 'name', 'tenant.nip' => 'nip', 'tenant.slug' => 'slug'] as $changeKey => $col) {
                    if (array_key_exists($changeKey, $changes)) {
                        $tenantUpdates[] = "$col = :$col";
                        $tenantParams[":$col"] = $changes[$changeKey];
                    }
                }
                if ($tenantUpdates !== []) {
                    $sql = 'UPDATE sh_tenant SET ' . implode(', ', $tenantUpdates) . ' WHERE id = :tid';
                    $pdo->prepare($sql)->execute($tenantParams);
                }

                // KV legal_*
                foreach ($changes as $changeKey => $value) {
                    if (str_starts_with($changeKey, 'legal_')) {
                        profileSaveLegalKv($pdo, $tenant_id, $changeKey, (string)$value);
                    }
                }

                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (str_contains($e->getMessage(), '1062') && str_contains($e->getMessage(), 'uq_tenant_slug')) {
                    profileFail(409, 'SLUG_TAKEN', 'Slug jest już zajęty przez innego tenanta.');
                }
                profileFail(500, 'SAVE_FAILED', 'Zapis nie powiódł się: ' . $e->getMessage());
            }

            // Audit (nie blokuje na błędzie — log_error)
            profileAuditLog($pdo, $tenant_id, $user_id, [
                'tenant'    => $beforeTenant,
                'legal_kv'  => $beforeKv,
            ], [
                'changes' => $changes,
            ]);

            profileResponse(true, ['updated' => count($changes), 'changes' => array_keys($changes)],
                'Zapisano (' . count($changes) . ' pól).');
            break;
        }

        // -----------------------------------------------------------------
        default:
            profileFail(400, 'UNKNOWN_ACTION', "Nieznana akcja: {$action}");
    }
} catch (\Throwable $e) {
    error_log('[profile/engine] FATAL: ' . $e->getMessage());
    profileFail(500, 'INTERNAL_ERROR', 'Błąd serwera: ' . $e->getMessage());
}
