<?php
declare(strict_types=1);

/**
 * SliceHub Online Studio — Library Upload
 * /api/online_studio/library_upload.php
 *
 * Dedicated multipart uploader for the Studio "Library" tab.
 *
 * Multipart fields:
 *   file          (required, single file)
 *   category      (required)  — board|base|sauce|cheese|meat|veg|herb|extra|misc
 *   sub_type      (required)  — free-form tag (64 chars max)
 *   z_order       (optional)  — numeric override
 *   ascii_key     (optional)  — manual key override; auto-generated if empty
 *
 * Writes:
 *   - /uploads/global_assets/<filename>
 *   - sh_global_assets  row (tenant_id = session tenant)
 *
 * Response: { success, message, data: { id, asciiKey, url, ... } }
 */

error_reporting(E_ALL);
@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function out(bool $ok, $data = null, ?string $msg = null): void {
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    /** @var PDO $pdo */
    /** @var int $tenant_id, $user_id */

    $roleStmt = $pdo->prepare(
        'SELECT role FROM sh_users WHERE id = :uid AND tenant_id = :tid AND is_deleted = 0 AND is_active = 1 LIMIT 1'
    );
    $roleStmt->execute([':uid' => $user_id, ':tid' => $tenant_id]);
    $role = strtolower((string)($roleStmt->fetchColumn() ?: ''));
    if (!in_array($role, ['owner', 'admin', 'manager'], true)) {
        out(false, null, 'Brak uprawnien (wymagany owner/admin/manager).');
    }

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        out(false, null, 'Brak pliku (pole "file").');
    }
    $file = $_FILES['file'];
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        out(false, null, 'Blad uploadu. Kod: ' . (int)$file['error']);
    }

    $ALLOWED_CATS = ['board', 'base', 'sauce', 'cheese', 'meat', 'veg', 'herb', 'extra', 'misc'];
    $Z_BY_CAT = [
        'board'  => 0, 'base'   => 10, 'sauce'  => 20, 'cheese' => 30,
        'meat'   => 40, 'veg'    => 50, 'herb'   => 60, 'extra'  => 70, 'misc'   => 99,
    ];

    $category = strtolower(trim((string)($_POST['category'] ?? '')));
    if (!in_array($category, $ALLOWED_CATS, true)) {
        out(false, null, 'Nieprawidlowa category. Dozwolone: ' . implode(', ', $ALLOWED_CATS));
    }
    $subType = strtolower(trim((string)($_POST['sub_type'] ?? '')));
    $subType = preg_replace('/[^a-z0-9_]/', '', $subType) ?: '';
    if ($subType === '') {
        out(false, null, 'Wymagane sub_type (a-z0-9_).');
    }
    if (strlen($subType) > 64) {
        $subType = substr($subType, 0, 64);
    }

    $asciiOverride = trim((string)($_POST['ascii_key'] ?? ''));
    if ($asciiOverride !== '' && !preg_match('/^[A-Za-z0-9_+\-]{3,120}$/', $asciiOverride)) {
        out(false, null, 'ascii_key: dozwolone A-Za-z0-9_+-, 3-120 znakow.');
    }

    $zOrder = null;
    if (isset($_POST['z_order']) && $_POST['z_order'] !== '') {
        $zOrder = (int)$_POST['z_order'];
    }

    // Validate image
    $ALLOWED_EXT = ['webp', 'png'];
    $MAX_BYTES   = 5 * 1024 * 1024;     // 5 MB
    $MIN_SIDE    = 200;                 // relaxed — accepts legacy assets
    $MAX_SIDE    = 4096;

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $ALLOWED_EXT, true)) {
        out(false, null, 'Dozwolone rozszerzenia: .' . implode(', .', $ALLOWED_EXT));
    }
    if ((int)$file['size'] > $MAX_BYTES) {
        out(false, null, 'Plik za duzy (max ' . round($MAX_BYTES / 1048576, 1) . ' MB).');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    if (!in_array($mime, ['image/webp', 'image/png'], true)) {
        out(false, null, 'Nieprawidlowy MIME: ' . $mime);
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info) {
        out(false, null, 'Plik nie jest poprawnym obrazem.');
    }
    [$w, $h] = [$info[0], $info[1]];
    if ($w < $MIN_SIDE || $h < $MIN_SIDE) {
        out(false, null, "Obraz za maly (min {$MIN_SIDE}x{$MIN_SIDE}, masz {$w}x{$h}).");
    }
    if ($w > $MAX_SIDE || $h > $MAX_SIDE) {
        out(false, null, "Obraz za duzy (max {$MAX_SIDE}x{$MAX_SIDE}, masz {$w}x{$h}).");
    }

    // Ensure schema allows full category list
    try {
        $pdo->exec(
            "ALTER TABLE sh_global_assets
               MODIFY COLUMN category ENUM('board','base','sauce','cheese','meat','veg','herb','extra','misc')
               NOT NULL DEFAULT 'misc'"
        );
    } catch (\PDOException $e) {
        // ignore if user has no ALTER; insert may still fail and surface a cleaner message
    }

    // Build filename + ascii_key (unique hash tail)
    $hash = substr(hash_file('sha256', $file['tmp_name']), 0, 6);
    $filename = "{$category}_{$subType}_{$hash}.{$ext}";
    $asciiKey = $asciiOverride !== '' ? $asciiOverride : pathinfo($filename, PATHINFO_FILENAME);

    $destDir = realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'global_assets';
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
        out(false, null, 'Nie mozna utworzyc katalogu docelowego.');
    }
    $destPath = $destDir . DIRECTORY_SEPARATOR . $filename;
    $c = 0;
    while (file_exists($destPath)) {
        $c++;
        $filename = "{$category}_{$subType}_{$hash}_{$c}.{$ext}";
        $destPath = $destDir . DIRECTORY_SEPARATOR . $filename;
        if ($asciiOverride === '') {
            $asciiKey = pathinfo($filename, PATHINFO_FILENAME);
        }
    }

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        out(false, null, 'Nie udalo sie zapisac pliku na dysku.');
    }

    $effectiveZ = $zOrder ?? ($Z_BY_CAT[$category] ?? 50);

    // Ensure `url` column exists (kept in sync with engine.php probe logic)
    $hasUrl = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sh_global_assets' AND COLUMN_NAME = 'url'"
    )->fetchColumn();

    if ($hasUrl === 0) {
        try {
            $pdo->exec("ALTER TABLE sh_global_assets ADD COLUMN url VARCHAR(512) NULL AFTER filename");
            $hasUrl = 1;
        } catch (\PDOException $e) {
            // proceed without url column
        }
    }

    $fullUrl = '/slicehub/uploads/global_assets/' . $filename;
    $params = [
        ':tid' => $tenant_id,
        ':ak'  => $asciiKey,
        ':cat' => $category,
        ':sub' => $subType,
        ':fn'  => $filename,
        ':w'   => (int)$w,
        ':h'   => (int)$h,
        ':sz'  => (int)$file['size'],
        ':zo'  => $effectiveZ,
    ];
    $cols = ['tenant_id', 'ascii_key', 'category', 'sub_type', 'filename',
             'width', 'height', 'has_alpha', 'filesize_bytes', 'z_order', 'is_active'];
    $vals = [':tid', ':ak', ':cat', ':sub', ':fn', ':w', ':h', '1', ':sz', ':zo', '1'];
    if ($hasUrl === 1) {
        array_splice($cols, 5, 0, ['url']);
        array_splice($vals, 5, 0, [':url']);
        $params[':url'] = $fullUrl;
    }

    $sql = 'INSERT INTO sh_global_assets (' . implode(', ', $cols) . ')
            VALUES (' . implode(', ', $vals) . ')
            ON DUPLICATE KEY UPDATE
                filename        = VALUES(filename),
                sub_type        = VALUES(sub_type),
                category        = VALUES(category),
                width           = VALUES(width),
                height          = VALUES(height),
                filesize_bytes  = VALUES(filesize_bytes),
                z_order         = VALUES(z_order),
                is_active       = 1,
                updated_at      = CURRENT_TIMESTAMP';
    try {
        $pdo->prepare($sql)->execute($params);
    } catch (\PDOException $e) {
        @unlink($destPath);
        out(false, null, 'Blad zapisu do bazy: ' . $e->getMessage());
    }

    // Fetch assigned id/row (either newly inserted or updated via ON DUPLICATE)
    $idStmt = $pdo->prepare(
        'SELECT id FROM sh_global_assets
         WHERE tenant_id = :tid AND ascii_key = :ak LIMIT 1'
    );
    $idStmt->execute([':tid' => $tenant_id, ':ak' => $asciiKey]);
    $newId = (int)($idStmt->fetchColumn() ?: 0);

    out(true, [
        'id'            => $newId,
        'asciiKey'      => $asciiKey,
        'category'      => $category,
        'subType'       => $subType,
        'filename'      => $filename,
        'url'           => $fullUrl,
        'width'         => (int)$w,
        'height'        => (int)$h,
        'filesizeBytes' => (int)$file['size'],
        'zOrder'        => $effectiveZ,
    ], 'OK — wgrano do biblioteki.');
} catch (\Throwable $e) {
    out(false, null, 'Blad serwera: ' . $e->getMessage());
}
