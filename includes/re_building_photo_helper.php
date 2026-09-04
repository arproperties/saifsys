<?php
/**
 * Building primary photo — permanent ERP asset for apps, website, AI.
 * ONE primary image per building. Path is relative under uploads/.
 */

declare(strict_types=1);

function re_building_photo_allowed_extensions(): array
{
    return ['jpg', 'jpeg', 'png', 'gif', 'webp'];
}

function re_building_photo_allowed_mimes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
}

function re_building_photo_ensure_schema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $cols = $conn->query("SHOW COLUMNS FROM re_buildings")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $cols = array_map('strtolower', $cols);
        if (!in_array('primary_photo_path', $cols, true)) {
            $conn->exec("ALTER TABLE re_buildings ADD COLUMN primary_photo_path VARCHAR(500) NULL DEFAULT NULL AFTER facilities_notes");
        }
        if (!in_array('primary_photo_mime', $cols, true)) {
            $conn->exec("ALTER TABLE re_buildings ADD COLUMN primary_photo_mime VARCHAR(100) NULL DEFAULT NULL AFTER primary_photo_path");
        }
        if (!in_array('primary_photo_updated_at', $cols, true)) {
            $conn->exec("ALTER TABLE re_buildings ADD COLUMN primary_photo_updated_at DATETIME NULL DEFAULT NULL AFTER primary_photo_mime");
        }
    } catch (Throwable $e) {
        // Schema may already exist or lack ALTER privilege — callers handle missing columns gracefully.
    }
}

function re_building_photo_project_root(): string
{
    return dirname(__DIR__);
}

function re_building_photo_upload_base(): string
{
    return re_building_photo_project_root() . '/uploads/realestate/buildings';
}

function re_building_photo_ensure_htaccess(): void
{
    $base = re_building_photo_upload_base();
    if (!is_dir($base)) {
        @mkdir($base, 0777, true);
    }
    @chmod($base, 0777);
    $ht = $base . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "# Deny PHP execution in building photo uploads\n"
            . "<FilesMatch \"\\.(?i:php|phtml|php3|php4|php5|phar)$\">\n"
            . "    Require all denied\n"
            . "</FilesMatch>\n"
            . "Options -ExecCGI\n"
            . "RemoveHandler .php .phtml .php3 .php4 .php5 .phar\n");
    }
}

/**
 * Absolute filesystem path for a stored relative path, or null if unsafe/missing.
 */
function re_building_photo_absolute_path(?string $relativePath): ?string
{
    if ($relativePath === null || $relativePath === '') {
        return null;
    }
    $relativePath = str_replace('\\', '/', $relativePath);
    if (strpos($relativePath, '..') !== false) {
        return null;
    }
    if (strpos($relativePath, 'uploads/realestate/buildings/') !== 0) {
        return null;
    }
    $abs = re_building_photo_project_root() . '/' . ltrim($relativePath, '/');
    return is_file($abs) ? $abs : null;
}

/**
 * URL relative to modules/realestate/*.php pages.
 */
function re_building_photo_url(?string $relativePath): ?string
{
    if ($relativePath === null || $relativePath === '') {
        return null;
    }
    if (strpos($relativePath, '..') !== false) {
        return null;
    }
    return '../../' . ltrim(str_replace('\\', '/', $relativePath), '/');
}

/**
 * Canonical relative path for API / mobile / website consumers.
 */
function re_building_photo_public_path(?string $relativePath): ?string
{
    if ($relativePath === null || $relativePath === '') {
        return null;
    }
    if (strpos($relativePath, '..') !== false) {
        return null;
    }
    return ltrim(str_replace('\\', '/', $relativePath), '/');
}

/**
 * @param array $file Single $_FILES entry
 * @return array{ok:bool,error?:string,path?:string,mime?:string,url?:string}
 */
function re_building_photo_store(PDO $conn, int $companyId, int $buildingId, array $file, ?int $uploadedBy = null, int $maxBytes = 10485760): array
{
    re_building_photo_ensure_schema($conn);
    re_building_photo_ensure_htaccess();

    if ($companyId <= 0 || $buildingId <= 0) {
        return ['ok' => false, 'error' => 'Company and building context are required.'];
    }

    $chk = $conn->prepare('SELECT id, primary_photo_path FROM re_buildings WHERE id = ? AND company_id = ? LIMIT 1');
    $chk->execute([$buildingId, $companyId]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'Building not found.'];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'Photo exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'Photo is too large.',
            UPLOAD_ERR_PARTIAL => 'Photo upload was incomplete.',
            UPLOAD_ERR_NO_FILE => 'No photo uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload temp folder is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not save the uploaded photo.',
            UPLOAD_ERR_EXTENSION => 'Photo upload was blocked by a server extension.',
        ];
        return ['ok' => false, 'error' => $messages[$file['error'] ?? 0] ?? 'Photo upload failed.'];
    }

    if ((int)($file['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => 'Building photo must be 10 MB or smaller.'];
    }

    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, re_building_photo_allowed_extensions(), true)) {
        return ['ok' => false, 'error' => 'Only JPG, PNG, GIF, or WEBP images are allowed.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    $allowedMimes = re_building_photo_allowed_mimes();
    if (!isset($allowedMimes[$mime]) || @getimagesize($file['tmp_name']) === false) {
        return ['ok' => false, 'error' => 'Uploaded file is not a valid image.'];
    }
    $ext = $allowedMimes[$mime];

    $uploadBase = re_building_photo_upload_base();
    $uploadDir = $uploadBase . '/' . $buildingId;
    if (!is_dir($uploadBase) && !@mkdir($uploadBase, 0777, true)) {
        return ['ok' => false, 'error' => 'Cannot create building photo upload directory.'];
    }
    @chmod($uploadBase, 0777);
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0777, true)) {
        $err = error_get_last();
        $detail = is_array($err) && !empty($err['message']) ? $err['message'] : 'permission denied';
        return ['ok' => false, 'error' => 'Cannot create building photo folder (' . $detail . ').'];
    }
    @chmod($uploadDir, 0777);
    if (!is_writable($uploadDir)) {
        @chmod($uploadDir, 0777);
    }
    if (!is_writable($uploadDir)) {
        return ['ok' => false, 'error' => 'Building photo folder is not writable. Check uploads/realestate/buildings permissions.'];
    }

    $newFileName = 'primary_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $uploadDir . '/' . $newFileName;
    $relativePath = 'uploads/realestate/buildings/' . $buildingId . '/' . $newFileName;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Failed to save uploaded photo.'];
    }

    $oldPath = (string)($row['primary_photo_path'] ?? '');
    $upd = $conn->prepare('
        UPDATE re_buildings
        SET primary_photo_path = ?, primary_photo_mime = ?, primary_photo_updated_at = NOW()
        WHERE id = ? AND company_id = ?
    ');
    $upd->execute([$relativePath, $mime, $buildingId, $companyId]);

    if ($oldPath !== '' && $oldPath !== $relativePath) {
        $oldAbs = re_building_photo_absolute_path($oldPath);
        if ($oldAbs) {
            @unlink($oldAbs);
        }
    }

    return [
        'ok' => true,
        'path' => $relativePath,
        'mime' => $mime,
        'url' => re_building_photo_url($relativePath),
        'public_path' => re_building_photo_public_path($relativePath),
        'uploaded_by' => $uploadedBy,
    ];
}

/**
 * @return array{ok:bool,error?:string}
 */
function re_building_photo_remove(PDO $conn, int $companyId, int $buildingId): array
{
    re_building_photo_ensure_schema($conn);

    if ($companyId <= 0 || $buildingId <= 0) {
        return ['ok' => false, 'error' => 'Company and building context are required.'];
    }

    $chk = $conn->prepare('SELECT primary_photo_path FROM re_buildings WHERE id = ? AND company_id = ? LIMIT 1');
    $chk->execute([$buildingId, $companyId]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'Building not found.'];
    }

    $oldPath = (string)($row['primary_photo_path'] ?? '');
    $upd = $conn->prepare('
        UPDATE re_buildings
        SET primary_photo_path = NULL, primary_photo_mime = NULL, primary_photo_updated_at = NOW()
        WHERE id = ? AND company_id = ?
    ');
    $upd->execute([$buildingId, $companyId]);

    if ($oldPath !== '') {
        $oldAbs = re_building_photo_absolute_path($oldPath);
        if ($oldAbs) {
            @unlink($oldAbs);
        }
    }

    return ['ok' => true];
}
