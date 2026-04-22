<?php

declare(strict_types=1);

/**
 * rotate_credentials_to_vault.php — migracja plaintext → vault:v1:
 *
 * Identyfikuje rekordy w:
 *   • sh_tenant_integrations.credentials  (JSON plaintext)
 *   • sh_webhook_endpoints.secret         (raw string)
 * i przepuszcza przez CredentialVault::encrypt().
 *
 * SAFE flow:
 *   1. Domyślnie --dry-run — wyświetla co by zrobił, nic nie zapisuje.
 *   2. --live — faktyczny UPDATE w DB.
 *   3. Przed każdym UPDATE robi self-test: encrypt → decrypt → porównuje z raw.
 *      Jeśli roundtrip nie pasuje, rekord jest SKIPPED (log do stderr).
 *   4. Rekordy już zaczynające się od "vault:v1:" są ignorowane (idempotency).
 *
 * Użycie:
 *   php scripts/rotate_credentials_to_vault.php --dry-run
 *   php scripts/rotate_credentials_to_vault.php --live
 *   php scripts/rotate_credentials_to_vault.php --live --only=webhooks
 */

if (PHP_SAPI !== 'cli') {
    echo "ERROR: CLI only. Run: php scripts/rotate_credentials_to_vault.php --dry-run\n";
    exit(1);
}

require_once __DIR__ . '/../core/db_config.php';
require_once __DIR__ . '/../core/CredentialVault.php';

$live = in_array('--live', $argv, true);
$dryRun = !$live;
$only = 'all';
foreach ($argv as $arg) {
    if (preg_match('/^--only=(integrations|webhooks)$/', $arg, $m)) {
        $only = $m[1];
    }
}

echo "==============================================\n";
echo "Credential Rotation to Vault (Faza 7.6)\n";
echo "==============================================\n";
echo "Mode: " . ($live ? "LIVE (will UPDATE db)" : "DRY-RUN (read-only)") . "\n";
echo "Scope: {$only}\n\n";

if (!CredentialVault::isReady()) {
    echo "✗ FAIL: CredentialVault NOT READY.\n";
    echo "   Run first: php scripts/bootstrap_vault.php\n";
    exit(2);
}
echo "✓ CredentialVault is ready.\n\n";

$totalProcessed = 0;
$totalUpdated   = 0;
$totalSkipped   = 0;
$totalErrors    = 0;

// ── 1. sh_tenant_integrations.credentials ─────────────────────────────
if ($only === 'all' || $only === 'integrations') {
    echo "[1] Scanning sh_tenant_integrations.credentials...\n";
    try {
        $rows = $pdo->query("SELECT id, tenant_id, provider, credentials FROM sh_tenant_integrations")
                    ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $totalProcessed++;
            $id    = (int)$row['id'];
            $raw   = (string)($row['credentials'] ?? '');
            $tenant = (int)$row['tenant_id'];
            $prov  = (string)$row['provider'];

            if ($raw === '') {
                echo "  [#{$id} {$prov}] skip: empty credentials\n";
                $totalSkipped++;
                continue;
            }

            if (CredentialVault::isEncrypted($raw)) {
                echo "  [#{$id} {$prov}] skip: already vault:v1:\n";
                $totalSkipped++;
                continue;
            }

            $encrypted = CredentialVault::encrypt($raw);
            $decrypted = CredentialVault::decrypt($encrypted);
            if ($decrypted !== $raw) {
                echo "  [#{$id} {$prov}] ✗ ERROR: roundtrip mismatch — SKIPPED\n";
                $totalErrors++;
                continue;
            }

            if ($dryRun) {
                echo "  [#{$id} {$prov}] would encrypt " . strlen($raw) . " bytes → " . strlen($encrypted) . " bytes vault\n";
            } else {
                $pdo->prepare("UPDATE sh_tenant_integrations SET credentials = :c WHERE id = :id")
                    ->execute([':c' => $encrypted, ':id' => $id]);
                echo "  [#{$id} {$prov}] ✓ encrypted + stored\n";
                $totalUpdated++;
            }
        }
    } catch (PDOException $e) {
        echo "  ✗ Table sh_tenant_integrations unavailable: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

// ── 2. sh_webhook_endpoints.secret ─────────────────────────────────────
if ($only === 'all' || $only === 'webhooks') {
    echo "[2] Scanning sh_webhook_endpoints.secret...\n";
    try {
        $rows = $pdo->query("SELECT id, tenant_id, name, secret FROM sh_webhook_endpoints")
                    ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $totalProcessed++;
            $id     = (int)$row['id'];
            $secret = (string)($row['secret'] ?? '');
            $name   = (string)$row['name'];

            if ($secret === '') {
                echo "  [#{$id} {$name}] skip: empty secret\n";
                $totalSkipped++;
                continue;
            }

            if (CredentialVault::isEncrypted($secret)) {
                echo "  [#{$id} {$name}] skip: already vault:v1:\n";
                $totalSkipped++;
                continue;
            }

            $encrypted = CredentialVault::encrypt($secret);
            $decrypted = CredentialVault::decrypt($encrypted);
            if ($decrypted !== $secret) {
                echo "  [#{$id} {$name}] ✗ ERROR: roundtrip mismatch — SKIPPED\n";
                $totalErrors++;
                continue;
            }

            if ($dryRun) {
                echo "  [#{$id} {$name}] would encrypt " . strlen($secret) . " bytes → " . strlen($encrypted) . " bytes vault\n";
            } else {
                $pdo->prepare("UPDATE sh_webhook_endpoints SET secret = :s WHERE id = :id")
                    ->execute([':s' => $encrypted, ':id' => $id]);
                echo "  [#{$id} {$name}] ✓ encrypted + stored\n";
                $totalUpdated++;
            }
        }
    } catch (PDOException $e) {
        echo "  ✗ Table sh_webhook_endpoints unavailable: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

echo "==============================================\n";
echo "Summary:\n";
echo "  Processed:   {$totalProcessed}\n";
echo "  Updated:     {$totalUpdated}" . ($dryRun ? " (DRY-RUN — not persisted)" : "") . "\n";
echo "  Skipped:     {$totalSkipped} (empty / already vault)\n";
echo "  Errors:      {$totalErrors}\n";
echo "==============================================\n";

if ($dryRun) {
    echo "\nDry-run complete. Re-run with --live to persist changes.\n";
} elseif ($totalErrors > 0) {
    echo "\n⚠  {$totalErrors} rows errored — inspect stderr above, fix and re-run.\n";
    exit(3);
} else {
    echo "\n✓ Rotation complete.\n";
}
