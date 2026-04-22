<?php
// =============================================================================
// SliceHub Enterprise — Visual Asset Upload (multipart/form-data only)
// api/backoffice/api_visual_studio.php
//
// Accepts .webp and .png photorealistic ingredient images for the Digital Twin
// pizza builder. Validates extension, MIME type, image integrity, dimensions,
// and per-type size limits (see _docs/05_INSTRUKCJA_FOTO_UPLOAD.md).
//
// Required POST fields:
//   asset       — the image file (multipart field)
//   asset_type  — one of: layer, hero, thumbnail, surface, companion
//   category    — e.g. meat, veg, herb, sauce, cheese, base, board, misc
//   sub_type    — e.g. salami, tomato, basil (used in filename generation)
//
// All CRUD (save/get/delete_visual_layer) lives in api_menu_studio.php.
// =============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$response = ['success' => false, 'data' => null, 'message' => 'Server error.'];

try {
    require_once '../../core/db_config.php';
    require_once '../../core/auth_guard.php';

    if (!isset($_FILES['asset'])) {
        throw new Exception('Brak pliku. Wyślij multipart/form-data z polem "asset".');
    }

    $file      = $_FILES['asset'];
    $assetType = trim($_POST['asset_type'] ?? 'layer');
    $category  = trim($_POST['category'] ?? 'misc');
    $subType   = trim($_POST['sub_type'] ?? 'unknown');

    $LIMITS = [
        'layer'     => ['max_bytes' => 3 * 1024 * 1024,   'min_w' => 1000, 'min_h' => 1000, 'max_w' => 3000, 'max_h' => 3000],
        'hero'      => ['max_bytes' => 1536 * 1024,       'min_w' => 400,  'min_h' => 400,  'max_w' => 1200, 'max_h' => 1200],
        'thumbnail' => ['max_bytes' => 800 * 1024,        'min_w' => 300,  'min_h' => 300,  'max_w' => 800,  'max_h' => 800],
        'surface'   => ['max_bytes' => 5 * 1024 * 1024,   'min_w' => 1920, 'min_h' => 1080, 'max_w' => 3840, 'max_h' => 2400],
        'companion' => ['max_bytes' => 1536 * 1024,       'min_w' => 400,  'min_h' => 400,  'max_w' => 1200, 'max_h' => 1200],
    ];

    $validTypes = array_keys($LIMITS);
    if (!in_array($assetType, $validTypes, true)) {
        throw new Exception("Nieznany typ assetu: {$assetType}. Dozwolone: " . implode(', ', $validTypes));
    }

    $limits = $LIMITS[$assetType];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Błąd uploadu. Kod: ' . $file['error']);
    }

    if ($file['size'] > $limits['max_bytes']) {
        $limitMB = round($limits['max_bytes'] / (1024 * 1024), 1);
        throw new Exception("Plik jest za duży. Maksymalny rozmiar dla typu \"{$assetType}\" to {$limitMB} MB.");
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    $allowedExt = ['webp', 'png'];
    if ($assetType === 'surface') {
        $allowedExt[] = 'jpg';
        $allowedExt[] = 'jpeg';
    }
    if (!in_array($ext, $allowedExt, true)) {
        throw new Exception('Dozwolone formaty: .' . implode(', .', $allowedExt) . '. Przesłano: .' . $ext);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowedMime = ['image/webp', 'image/png'];
    if ($assetType === 'surface') {
        $allowedMime[] = 'image/jpeg';
    }
    if (!in_array($mime, $allowedMime, true)) {
        throw new Exception("Nieprawidłowy typ MIME: {$mime}. Oczekiwano: " . implode(', ', $allowedMime));
    }

    $imageInfo = @getimagesize($file['tmp_name']);
    if (!$imageInfo) {
        throw new Exception('Plik nie jest prawidłowym obrazem (błąd parsowania nagłówka).');
    }
    $allowedImageTypes = [IMAGETYPE_WEBP, IMAGETYPE_PNG];
    if ($assetType === 'surface') {
        $allowedImageTypes[] = IMAGETYPE_JPEG;
    }
    if (!in_array($imageInfo[2], $allowedImageTypes, true)) {
        throw new Exception('Niezgodność typu obrazu. Eksportuj ponownie jako .webp lub .png.');
    }

    $imgW = $imageInfo[0];
    $imgH = $imageInfo[1];

    if ($imgW < $limits['min_w'] || $imgH < $limits['min_h']) {
        throw new Exception("Zdjęcie jest za małe. Minimalne wymiary dla \"{$assetType}\": {$limits['min_w']}×{$limits['min_h']} px. Przesłano: {$imgW}×{$imgH} px.");
    }

    if ($imgW > $limits['max_w'] || $imgH > $limits['max_h']) {
        throw new Exception("Zdjęcie jest za duże. Maksymalne wymiary dla \"{$assetType}\": {$limits['max_w']}×{$limits['max_h']} px. Przesłano: {$imgW}×{$imgH} px.");
    }

    $safeCat = preg_replace('/[^a-z0-9]/', '', strtolower($category));
    $safeSub = preg_replace('/[^a-z0-9_]/', '', strtolower($subType));
    if ($safeCat === '') $safeCat = 'misc';
    if ($safeSub === '') $safeSub = 'asset';

    $contentHash = substr(hash_file('sha256', $file['tmp_name']), 0, 6);
    $uniqueName = "{$safeCat}_{$safeSub}_{$contentHash}.{$ext}";

    $targetDir = $assetType === 'surface' ? 'global_assets' : ('visual' . DIRECTORY_SEPARATOR . $tenant_id);
    $assetDir  = realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $targetDir;

    if (!is_dir($assetDir) && !mkdir($assetDir, 0755, true)) {
        throw new Exception('Nie można utworzyć katalogu assetów.');
    }

    $targetPath = $assetDir . DIRECTORY_SEPARATOR . $uniqueName;

    $counter = 0;
    while (file_exists($targetPath)) {
        $counter++;
        $uniqueName = "{$safeCat}_{$safeSub}_{$contentHash}_{$counter}.{$ext}";
        $targetPath = $assetDir . DIRECTORY_SEPARATOR . $uniqueName;
    }

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception('Nie udało się zapisać pliku.');
    }

    $relUrl = 'uploads/' . str_replace(DIRECTORY_SEPARATOR, '/', $targetDir) . '/' . $uniqueName;

    $response['success'] = true;
    $response['message'] = 'Asset przesłany pomyślnie.';
    $response['data'] = [
        'filename'   => $uniqueName,
        'asset_url'  => $relUrl,
        'asset_type' => $assetType,
        'dimensions' => ['w' => $imgW, 'h' => $imgH],
        'size_bytes' => $file['size'],
    ];

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
