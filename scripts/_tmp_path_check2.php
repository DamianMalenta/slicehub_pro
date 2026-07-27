<?php
$paths = [
    'slicehub (active)'   => 'c:/xampp/htdocs/slicehub/modules/backoffice/hr/js/hr_app.js',
    'slicehub2'           => 'c:/xampp/htdocs/slicehub2/modules/backoffice/hr/js/hr_app.js',
    'BACKUP_2026-05-21'   => 'c:/xampp/htdocs/slicehub_BACKUP_2026-05-21/slicehub/modules/backoffice/hr/js/hr_app.js',
    'kopia 14.05'         => 'c:/xampp/htdocs/kopia calego systemu 14.05/slicehub/modules/backoffice/hr/js/hr_app.js',
];

$active = file_get_contents($paths['slicehub (active)']);

foreach ($paths as $label => $path) {
    if (!file_exists($path)) {
        echo str_pad($label, 25) . " FILE NOT FOUND\n";
        continue;
    }
    $content = file_get_contents($path);
    $size = strlen($content);
    $same = ($content === $active) ? 'IDENTICAL' : 'DIFFERENT';
    $hasSwitch = strpos($content, "switchTab('employees')") !== false ? 'YES' : 'NO';
    $hasAdvances = strpos($content, 'advance') !== false ? 'YES' : 'NO';
    $hasMeal = strpos($content, 'meal_record') !== false ? 'YES' : 'NO';
    $hasClosePeriod = strpos($content, 'payroll_close_period') !== false ? 'YES' : 'NO';
    $hasIdempotency = strpos($content, 'newIdempotencyKey') !== false ? 'YES' : 'NO';

    echo str_pad($label, 25) . " size=$size $same\n";
    echo str_pad('', 25) . " switchTab=$hasSwitch advances=$hasAdvances meal=$hasMeal closePeriod=$hasClosePeriod idempotency=$hasIdempotency\n";

    // Also check index.html
    $htmlPath = dirname($path, 2) . '/index.html';
    if (file_exists($htmlPath)) {
        $html = file_get_contents($htmlPath);
        $htmlSize = strlen($html);
        $activeHtml = file_get_contents('c:/xampp/htdocs/slicehub/modules/backoffice/hr/index.html');
        $htmlSame = ($html === $activeHtml) ? 'IDENTICAL' : 'DIFFERENT';
        echo str_pad('', 25) . " index.html size=$htmlSize $htmlSame\n";
    }
}

// Check Apache config for aliases/symlinks
echo "\n=== Apache config ===\n";
$apacheConf = 'c:/xampp/apache/conf/httpd.conf';
if (file_exists($apacheConf)) {
    $conf = file_get_contents($apacheConf);
    // Look for DocumentRoot and aliases
    if (preg_match('/DocumentRoot\s+"([^"]+)"/i', $conf, $m)) {
        echo "DocumentRoot: {$m[1]}\n";
    }
    if (preg_match_all('/Alias\s+(\S+)\s+"([^"]+)"/i', $conf, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            echo "Alias: {$m[1]} -> {$m[2]}\n";
        }
    } else {
        echo "No Alias directives found in httpd.conf\n";
    }
    // Check for symlinks in htdocs
    echo "\n=== htdocs listing ===\n";
    foreach (scandir('c:/xampp/htdocs') as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = "c:/xampp/htdocs/$entry";
        $type = is_link($full) ? 'SYMLINK -> ' . readlink($full) : (is_dir($full) ? 'DIR' : 'FILE');
        $size = is_dir($full) ? '' : ' (' . filesize($full) . ' bytes)';
        echo "  $entry: $type$size\n";
    }
}

// Check if slicehub2 has its own tenant_config
echo "\n=== slicehub2 tenant_config ===\n";
$tc2 = 'c:/xampp/htdocs/slicehub2/tenant_config.php';
if (file_exists($tc2)) {
    $content = file_get_contents($tc2);
    // Check what tenant_id it resolves to
    if (preg_match('/\$tid\s*=\s*(\d+)/', $content, $m)) {
        echo "slicehub2 default tid: {$m[1]}\n";
    }
    echo "slicehub2 tenant_config size: " . strlen($content) . "\n";
    $activeTc = file_get_contents('c:/xampp/htdocs/slicehub/tenant_config.php');
    echo "Same as active: " . ($content === $activeTc ? 'YES' : 'NO') . "\n";
} else {
    echo "slicehub2/tenant_config.php NOT FOUND\n";
}
