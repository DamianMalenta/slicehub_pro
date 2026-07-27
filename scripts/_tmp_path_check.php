<?php
// Compare disk vs served for HR module files
$files = [
    'modules/backoffice/hr/index.html',
    'modules/backoffice/hr/js/hr_app.js',
    'modules/backoffice/hr/css/hr.css',
    'modules/ui_shell/sh_mobile_shell.css',
];

$base = 'http://localhost/slicehub/';

foreach ($files as $rel) {
    $disk = file_get_contents(__DIR__ . '/../' . $rel);
    $ch = curl_init($base . $rel);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);
    $served = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $match = ($disk === $served) ? 'MATCH' : 'MISMATCH';
    echo str_pad($rel, 50) . " HTTP=$code disk=" . strlen($disk) . " served=" . strlen($served) . " $match\n";

    if ($match === 'MISMATCH') {
        // Show first difference
        $dlen = min(strlen($disk), strlen($served));
        for ($i = 0; $i < $dlen; $i++) {
            if ($disk[$i] !== $served[$i]) {
                echo "  First diff at byte $i:\n";
                echo "  disk:   " . substr($disk, max(0,$i-20), 60) . "\n";
                echo "  served: " . substr($served, max(0,$i-20), 60) . "\n";
                break;
            }
        }
        if ($i === $dlen) {
            echo "  Length difference only (disk=" . strlen($disk) . " vs served=" . strlen($served) . ")\n";
        }
    }
}

// Also check: is switchTab('employees') in the served JS?
$ch = curl_init($base . 'modules/backoffice/hr/js/hr_app.js');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);
$js = curl_exec($ch);
curl_close($ch);
echo "\nswitchTab('employees') in served JS: " . (strpos($js, "switchTab('employees')") !== false ? 'YES' : 'NO') . "\n";
echo "switchTab('employees') in disk JS:   " . (strpos(file_get_contents(__DIR__ . '/../modules/backoffice/hr/js/hr_app.js'), "switchTab('employees')") !== false ? 'YES' : 'NO') . "\n";

// Check tenant_config.php override
$ch = curl_init($base . 'tenant_config.php');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);
$tc = curl_exec($ch);
curl_close($ch);
echo "\ntenant_config.php served:\n$tc\n";
