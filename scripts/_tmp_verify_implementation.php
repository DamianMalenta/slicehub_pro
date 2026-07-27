<?php
// Verify implementation status of session 2 items
require_once __DIR__ . '/../core/db_config.php';
/** @var PDO $pdo */

echo "=== 1c. Migration 061 — sh_users.hourly_rate ===\n";
$stmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sh_users' AND COLUMN_NAME = 'hourly_rate'");
$row = $stmt->fetch();
echo $row ? "hourly_rate EXISTS (migration 061 NOT run)\n" : "hourly_rate DROPPED (migration 061 applied)\n";

echo "\n=== 4. Migrations idempotentność — check for information_schema guards ===\n";
$migrations = ['047', '053', '054', '055'];
foreach ($migrations as $mig) {
    $files = glob(__DIR__ . "/../database/migrations/{$mig}_*.sql");
    if (!$files) {
        echo "$mig: FILE NOT FOUND\n";
        continue;
    }
    $content = file_get_contents($files[0]);
    $hasGuard = strpos($content, 'information_schema') !== false;
    $hasPrepared = strpos($content, 'PREPARE stmt') !== false;
    echo "$mig (" . basename($files[0]) . "): " . ($hasGuard && $hasPrepared ? 'IDEMPOTENT' : 'NOT IDEMPOTENT') . "\n";
}

echo "\n=== 2. PayrollEngine fix #2 — OR clause for back-dated entries ===\n";
$peFile = file_get_contents(__DIR__ . '/../core/PayrollEngine.php');
$hasOrFix = strpos($peFile, 'period_year') !== false && strpos($peFile, 'period_month') !== false 
    && strpos($peFile, 'YEAR(l.created_at)') !== false;
echo "Fix #2 (back-dated entries OR clause): " . ($hasOrFix ? 'IMPLEMENTED' : 'NOT IMPLEMENTED') . "\n";

echo "\n=== 2. PayrollLedger — Money::isWithinCap ===\n";
$plFile = file_get_contents(__DIR__ . '/../core/PayrollLedger.php');
$hasCap = strpos($plFile, 'Money::isWithinCap') !== false;
echo "Money::isWithinCap: " . ($hasCap ? 'IMPLEMENTED' : 'NOT IMPLEMENTED') . "\n";

echo "\n=== 2. AdvanceEngine — Uuid::deterministic + Money::MAX_MINOR ===\n";
$aeFile = file_get_contents(__DIR__ . '/../core/AdvanceEngine.php');
$hasUuid = strpos($aeFile, 'Uuid::deterministic') !== false;
$hasMoneyCap = strpos($aeFile, 'Money::MAX_MINOR') !== false;
echo "Uuid::deterministic: " . ($hasUuid ? 'IMPLEMENTED' : 'NOT IMPLEMENTED') . "\n";
echo "Money::MAX_MINOR alias: " . ($hasMoneyCap ? 'IMPLEMENTED' : 'NOT IMPLEMENTED') . "\n";

echo "\n=== 1b. MealEngine — wired into API ===\n";
$apiFile = file_get_contents(__DIR__ . '/../api/backoffice/hr/engine.php');
$hasRequire = strpos($apiFile, 'MealEngine.php') !== false;
$hasCall = strpos($apiFile, 'MealEngine::record') !== false;
echo "require_once MealEngine: " . ($hasRequire ? 'YES' : 'NO') . "\n";
echo "MealEngine::record() called: " . ($hasCall ? 'YES' : 'NO') . "\n";

echo "\n=== 3. worker_payroll_accrual — PayrollAllocator integration ===\n";
$workerFile = file_get_contents(__DIR__ . '/worker_payroll_accrual.php');
$hasAllocator = strpos($workerFile, 'PayrollAllocator::splitByPeriod') !== false;
echo "PayrollAllocator::splitByPeriod: " . ($hasAllocator ? 'IMPLEMENTED' : 'NOT IMPLEMENTED') . "\n";

echo "\n=== DONE ===\n";
