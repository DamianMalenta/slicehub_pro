<?php

declare(strict_types=1);

/**
 * bootstrap_vault.php — inicjalizacja CredentialVault.
 *
 * Zadanie:
 *   1. Sprawdzić czy libsodium jest dostępne.
 *   2. Sprawdzić czy SLICEHUB_VAULT_KEY już istnieje (env lub config/vault_key.txt).
 *   3. Wygenerować 32-byte losowy klucz → zapis do `config/vault_key.txt` (0600).
 *   4. Opcjonalnie — uruchomić rotate_credentials_to_vault.php w dry-run.
 *
 * Uruchomienie:
 *   php scripts/bootstrap_vault.php              # normalny flow
 *   php scripts/bootstrap_vault.php --force      # nadpisz istniejący klucz (DANGEROUS)
 *   php scripts/bootstrap_vault.php --print-only # wygeneruj na stdout, nie zapisuj
 *
 * UWAGA:
 *   • Ten skrypt trzyma fundamentalny sekret systemu — pilnuj permissions.
 *   • Po wygenerowaniu ZAWSZE zrób backup klucza POZA serwerem.
 *   • Utrata klucza = utrata dostępu do wszystkich szyfrowanych credentials.
 */

if (PHP_SAPI !== 'cli') {
    echo "ERROR: CLI only. Run: php scripts/bootstrap_vault.php\n";
    exit(1);
}

require_once __DIR__ . '/../core/CredentialVault.php';

$force = in_array('--force', $argv, true);
$printOnly = in_array('--print-only', $argv, true);

echo "==============================================\n";
echo "CredentialVault Bootstrap (SliceHub Faza 7.6)\n";
echo "==============================================\n\n";

// ── 1. Sodium check ────────────────────────────────────────────────────
echo "[1/4] Checking libsodium extension...\n";
if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
    echo "  ✗ FAIL: libsodium not available.\n";
    echo "      On Debian/Ubuntu: sudo apt install php-sodium\n";
    echo "      On Windows (XAMPP): enable `extension=sodium` in php.ini\n";
    exit(2);
}
echo "  ✓ sodium_crypto_aead_xchacha20poly1305_ietf_encrypt available.\n\n";

// ── 2. Existing key check ──────────────────────────────────────────────
echo "[2/4] Checking existing key sources...\n";

$envKey = getenv('SLICEHUB_VAULT_KEY');
$globalKey = $GLOBALS['SLICEHUB_VAULT_KEY'] ?? null;
$fileKey = null;
$keyFile = __DIR__ . '/../config/vault_key.txt';
if (is_readable($keyFile)) {
    $fileKey = trim((string)file_get_contents($keyFile));
}

if ($envKey) echo "  • env SLICEHUB_VAULT_KEY present: " . substr($envKey, 0, 8) . "...\n";
if ($globalKey) echo "  • \$GLOBALS['SLICEHUB_VAULT_KEY'] present: " . substr($globalKey, 0, 8) . "...\n";
if ($fileKey) echo "  • config/vault_key.txt present: " . substr($fileKey, 0, 8) . "...\n";

$alreadyHasKey = ($envKey || $globalKey || $fileKey);
if ($alreadyHasKey && !$force && !$printOnly) {
    echo "\n  ⚠  Vault key already configured. Aborting to prevent data loss.\n";
    echo "      Re-run with --force to overwrite (will render EXISTING encrypted\n";
    echo "      credentials UNRECOVERABLE unless you have the old key backed up).\n";
    exit(3);
}
echo "\n";

// ── 3. Generate + store ────────────────────────────────────────────────
echo "[3/4] Generating 32-byte random key (XChaCha20-Poly1305)...\n";
$hex = bin2hex(random_bytes(32));
echo "  ✓ Generated: " . substr($hex, 0, 16) . "..." . substr($hex, -8) . "\n\n";

if ($printOnly) {
    echo "=== Vault key (add to .env / apache SetEnv) ===\n";
    echo "SLICEHUB_VAULT_KEY={$hex}\n";
    echo "===============================================\n";
    exit(0);
}

echo "[4/4] Writing config/vault_key.txt...\n";
$configDir = __DIR__ . '/../config';
if (!is_dir($configDir)) {
    if (!@mkdir($configDir, 0755, true)) {
        echo "  ✗ FAIL: cannot create {$configDir}\n";
        exit(4);
    }
    echo "  ✓ Created directory {$configDir}\n";
}

if (@file_put_contents($keyFile, $hex, LOCK_EX) === false) {
    echo "  ✗ FAIL: cannot write {$keyFile}\n";
    echo "      Check filesystem permissions (parent dir must be writable).\n";
    exit(5);
}
@chmod($keyFile, 0600);
echo "  ✓ Wrote {$keyFile} (0600).\n\n";

echo "==============================================\n";
echo "  BOOTSTRAP COMPLETE\n";
echo "==============================================\n";
echo "Next steps:\n";
echo "  1) Backup the key (OFF the server!):\n";
echo "       {$hex}\n";
echo "  2) Verify: php -r \"require 'core/CredentialVault.php'; var_dump(CredentialVault::isReady());\"\n";
echo "     → expected: bool(true)\n";
echo "  3) Migrate existing plaintext credentials to vault:\n";
echo "       php scripts/rotate_credentials_to_vault.php --dry-run\n";
echo "       php scripts/rotate_credentials_to_vault.php --live\n";
echo "  4) Restart webserver: service apache2 restart / httpd -k restart\n";
echo "\nDONE.\n";
