<?php

declare(strict_types=1);

/**
 * CredentialVault — transparent encryption-at-rest dla credentials w DB.
 *
 * Problem: `sh_tenant_integrations.credentials`, `sh_webhook_endpoints.secret`
 * i `sh_gateway_api_keys.key_secret_hash` mogą zawierać sekrety (OAuth tokens,
 * HMAC secrets, private API keys). MVP (7.4) trzymał je plaintext w JSON.
 * Sesja 7.5 wprowadza warstwę szyfrowania symmetric AEAD (libsodium XChaCha20-Poly1305)
 * z kluczem w env `SLICEHUB_VAULT_KEY` (32 bytes, hex-encoded).
 *
 * Format szyfrowanego wpisu:
 *   "vault:v1:<base64>"
 * gdzie `<base64>` = base64(nonce[24] || ciphertext || tag[16]).
 *
 * **Backward-compat:** jeśli wartość NIE zaczyna się od "vault:v1:", wracamy
 * ją as-is (plaintext legacy). To pozwala migrować stopniowo — stare rekordy
 * działają, nowe są szyfrowane, migration job może je przepchnąć.
 *
 * **Graceful degradation:** gdy brak libsodium LUB brak klucza w env:
 *   • encrypt() zwraca plaintext z warning do error_log (nie crashuje)
 *   • decrypt() obsługuje plaintext i "vault:v1:" raw (zwraca raw bez dekodowania,
 *     logując warning — pozwala to admin przeczytać dane na innej maszynie).
 *
 * Klucz generuje się raz per instalacja:
 *   php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
 * i wkleja do .env / apache envvars jako `SLICEHUB_VAULT_KEY=...`.
 */
final class CredentialVault
{
    private const PREFIX = 'vault:v1:';
    private const KEY_BYTES = 32;
    private const NONCE_BYTES = 24; // XChaCha20-Poly1305

    private static ?string $cachedKey = null;
    private static ?bool $sodiumAvailable = null;
    private static bool $keyWarningLogged = false;

    /**
     * Zaszyfruj string (przy braku sodium/klucza → zwraca plaintext + warning log).
     */
    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') return '';

        if (!self::isReady()) {
            self::logMissingVault('encrypt');
            return $plaintext;
        }

        $key = self::getKey();
        $nonce = random_bytes(self::NONCE_BYTES);

        try {
            $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                '',
                $nonce,
                $key
            );
        } catch (\Throwable $e) {
            error_log('[CredentialVault] encrypt failed: ' . $e->getMessage());
            return $plaintext;
        }

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /**
     * Odszyfruj string. Plaintext (bez prefixu) zwracany as-is dla legacy compat.
     * Gdy prefix wskazuje na vault ale odszyfrowanie padnie → zwraca null (sygnał że
     * klucz się zmienił / uszkodzone dane).
     */
    public static function decrypt(string $stored): ?string
    {
        if ($stored === '') return '';

        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }

        if (!self::isReady()) {
            self::logMissingVault('decrypt');
            // Nie możemy rozszyfrować — zwróć null, wywołujący musi zdecydować.
            return null;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < self::NONCE_BYTES + 17) {
            error_log('[CredentialVault] decrypt: invalid base64 or too short');
            return null;
        }

        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $cipher = substr($raw, self::NONCE_BYTES);

        try {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $cipher,
                '',
                $nonce,
                self::getKey()
            );
        } catch (\Throwable $e) {
            error_log('[CredentialVault] decrypt failed (bad key or tampered data): ' . $e->getMessage());
            return null;
        }

        return $plain === false ? null : $plain;
    }

    /**
     * Encrypt only if vault ready — inaczej zwraca plaintext BEZ logowania ostrzeżeń
     * (dla sytuacji gdzie encryption jest opcjonalne, np. dev environment).
     */
    public static function encryptSoft(string $plaintext): string
    {
        if (!self::isReady()) return $plaintext;
        return self::encrypt($plaintext);
    }

    /**
     * Pomoce dla JSON credentials — szyfruje CAŁY JSON string.
     * Gdy credentials jest `null`/empty → zwraca as-is.
     */
    public static function encryptJson(?string $credentialsJson): ?string
    {
        if ($credentialsJson === null || $credentialsJson === '') return $credentialsJson;
        return self::encrypt($credentialsJson);
    }

    public static function decryptJson(?string $stored): ?string
    {
        if ($stored === null || $stored === '') return $stored;
        return self::decrypt($stored);
    }

    /**
     * Czy vault jest gotowy? (sodium available + key set)
     */
    public static function isReady(): bool
    {
        return self::isSodiumAvailable() && self::getKey() !== null;
    }

    public static function isSodiumAvailable(): bool
    {
        if (self::$sodiumAvailable !== null) return self::$sodiumAvailable;
        self::$sodiumAvailable = function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
        return self::$sodiumAvailable;
    }

    /**
     * Lookup klucza — kolejność:
     *   1. zmienna globalna `$GLOBALS['SLICEHUB_VAULT_KEY']` (dla testów)
     *   2. env `SLICEHUB_VAULT_KEY`
     *   3. plik `config/vault_key.txt` (64 hex chars, owned by www-data 0600)
     */
    private static function getKey(): ?string
    {
        if (self::$cachedKey !== null) return self::$cachedKey;

        $rawHex = null;

        if (isset($GLOBALS['SLICEHUB_VAULT_KEY']) && is_string($GLOBALS['SLICEHUB_VAULT_KEY'])) {
            $rawHex = $GLOBALS['SLICEHUB_VAULT_KEY'];
        } elseif (($env = getenv('SLICEHUB_VAULT_KEY')) !== false && $env !== '') {
            $rawHex = $env;
        } else {
            $keyFile = __DIR__ . '/../config/vault_key.txt';
            if (is_readable($keyFile)) {
                $rawHex = trim((string)file_get_contents($keyFile));
            }
        }

        if ($rawHex === null || $rawHex === '') return null;

        $rawHex = trim($rawHex);
        if (strlen($rawHex) !== self::KEY_BYTES * 2 || !ctype_xdigit($rawHex)) {
            error_log('[CredentialVault] Key must be 64 hex chars (32 bytes)');
            return null;
        }

        $key = hex2bin($rawHex);
        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            error_log('[CredentialVault] hex2bin failed');
            return null;
        }

        return self::$cachedKey = $key;
    }

    private static function logMissingVault(string $op): void
    {
        if (self::$keyWarningLogged) return;
        self::$keyWarningLogged = true;
        $reason = !self::isSodiumAvailable() ? 'libsodium unavailable' : 'SLICEHUB_VAULT_KEY not set';
        error_log("[CredentialVault] {$op} running in PLAINTEXT mode: {$reason}. Generate key: php -r \"echo bin2hex(random_bytes(32));\"");
    }

    /**
     * Factory helper dla skryptu inicjalizacyjnego.
     * Generuje klucz i próbuje zapisać do config/vault_key.txt (0600).
     *
     * @return array{ok: bool, key: string, file?: string, error?: string}
     */
    public static function bootstrapKey(): array
    {
        if (!self::isSodiumAvailable()) {
            return ['ok' => false, 'key' => '', 'error' => 'libsodium extension not installed'];
        }

        $hex = bin2hex(random_bytes(self::KEY_BYTES));
        $dir = __DIR__ . '/../config';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                return ['ok' => false, 'key' => $hex, 'error' => 'cannot create config/ dir'];
            }
        }

        $file = $dir . '/vault_key.txt';
        if (file_exists($file)) {
            return ['ok' => false, 'key' => $hex, 'error' => 'config/vault_key.txt already exists — refusing to overwrite'];
        }

        $written = @file_put_contents($file, $hex, LOCK_EX);
        if ($written === false) {
            return ['ok' => false, 'key' => $hex, 'error' => 'cannot write config/vault_key.txt'];
        }
        @chmod($file, 0600);

        self::$cachedKey = hex2bin($hex);

        return ['ok' => true, 'key' => $hex, 'file' => $file];
    }

    /**
     * Czy wartość wygląda jak zaszyfrowana (dla UI — pokazujemy "••••" zamiast raw).
     */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /** @internal dla testów */
    public static function resetCache(): void
    {
        self::$cachedKey = null;
        self::$sodiumAvailable = null;
        self::$keyWarningLogged = false;
    }
}
