<?php
/**
 * Item image storage: original + thumbnail on disk; paths in inv_item_images.
 */

require_once dirname(__DIR__) . '/url_helper.php';

function inv_item_images_project_root(): string {
    $root = realpath(dirname(__DIR__, 2));
    return $root !== false ? $root : dirname(__DIR__, 2);
}

/**
 * Prefer uploads/inventory/items/...; if mkdir is denied (e.g. Apache user vs folder owner),
 * fall back to uploads/temp/inventory_items/... which is often writable on XAMPP.
 *
 * @return array{ok:bool, fs_dir?:string, rel_prefix?:string, error?:string}
 */
function inv_item_images_ensure_storage(int $companyId, int $itemId): array {
    $root = inv_item_images_project_root();
    $candidates = [
        ['fs' => $root . '/uploads/inventory/items/' . $companyId . '/' . $itemId, 'rel' => 'uploads/inventory/items/' . $companyId . '/' . $itemId],
        ['fs' => $root . '/uploads/temp/inventory_items/' . $companyId . '/' . $itemId, 'rel' => 'uploads/temp/inventory_items/' . $companyId . '/' . $itemId],
    ];
    $tried = [];
    foreach ($candidates as $c) {
        $tried[] = $c['fs'];
        if (!is_dir($c['fs'])) {
            $umask = umask(0);
            $made = @mkdir($c['fs'], 0777, true);
            umask($umask);
            if ($made) {
                return ['ok' => true, 'fs_dir' => $c['fs'], 'rel_prefix' => $c['rel']];
            }
            continue;
        }
        if (is_writable($c['fs'])) {
            return ['ok' => true, 'fs_dir' => $c['fs'], 'rel_prefix' => $c['rel']];
        }
    }
    $hint = 'Grant the web server write access to uploads/inventory/items (e.g. chgrp/chmod), or ensure uploads/temp is writable.';
    return [
        'ok' => false,
        'error' => 'Could not create or use upload directory. Tried: ' . implode('; ', $tried) . '. ' . $hint,
    ];
}

/** Web-relative path (for DB): {rel_prefix}/file.ext */
function inv_item_images_web_relative(string $filename, string $relPrefix): string {
    return rtrim(str_replace('\\', '/', $relPrefix), '/') . '/' . $filename;
}

function inv_item_image_public_url(string $relativePath): string {
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $base = get_application_web_root();
    return ($base !== '' ? $base : '') . '/' . $relativePath;
}

/**
 * Create thumbnail (JPEG). Falls back to copying original path if GD unavailable.
 */
function inv_item_image_make_thumb(string $srcPath, string $destPath, int $maxWidth = 320): bool {
    if (!is_readable($srcPath)) {
        return false;
    }
    if (!extension_loaded('gd')) {
        return @copy($srcPath, $destPath);
    }
    $info = @getimagesize($srcPath);
    if (!$info) {
        return @copy($srcPath, $destPath);
    }
    [$w, $h] = $info;
    $mime = $info['mime'] ?? '';
    if ($w <= 0 || $h <= 0) {
        return @copy($srcPath, $destPath);
    }
    $nw = min($maxWidth, $w);
    $nh = (int)round($h * ($nw / $w));
    $dst = imagecreatetruecolor($nw, $nh);
    if (!$dst) {
        return @copy($srcPath, $destPath);
    }
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $srcImg = null;
    switch ($mime) {
        case 'image/jpeg':
            $srcImg = @imagecreatefromjpeg($srcPath);
            break;
        case 'image/png':
            $srcImg = @imagecreatefrompng($srcPath);
            break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) {
                $srcImg = @imagecreatefromwebp($srcPath);
            }
            break;
        case 'image/gif':
            $srcImg = @imagecreatefromgif($srcPath);
            break;
        default:
            imagedestroy($dst);
            return @copy($srcPath, $destPath);
    }
    if (!$srcImg) {
        imagedestroy($dst);
        return @copy($srcPath, $destPath);
    }
    imagecopyresampled($dst, $srcImg, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($srcImg);
    $ok = imagejpeg($dst, $destPath, 82);
    imagedestroy($dst);
    return (bool)$ok;
}

/**
 * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file $_FILES['x']
 * @return array{ok:bool, error?:string, paths?:array{original:string, thumb:string, mime:string}}
 */
function inv_item_image_process_upload(PDO $conn, int $companyId, int $itemId, array $file): array {
    if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'No file uploaded'];
    }
    $maxBytes = 8 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => 'File too large (max 8MB)'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime, $allowed, true)) {
        return ['ok' => false, 'error' => 'Invalid image type'];
    }

    $storage = inv_item_images_ensure_storage($companyId, $itemId);
    if (empty($storage['ok'])) {
        return ['ok' => false, 'error' => $storage['error'] ?? 'Could not create upload directory'];
    }
    $dir = $storage['fs_dir'];
    $relPrefix = $storage['rel_prefix'];

    $uid = bin2hex(random_bytes(8));
    $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : ($mime === 'image/gif' ? 'gif' : 'jpg'));
    $origName = $uid . '_orig.' . $ext;
    $thumbName = $uid . '_thumb.jpg';
    $origFs = $dir . '/' . $origName;
    $thumbFs = $dir . '/' . $thumbName;

    if (!@move_uploaded_file($file['tmp_name'], $origFs)) {
        return ['ok' => false, 'error' => 'Could not save file'];
    }

    if (!inv_item_image_make_thumb($origFs, $thumbFs)) {
        @unlink($origFs);
        return ['ok' => false, 'error' => 'Could not create thumbnail'];
    }

    $relOrig = inv_item_images_web_relative($origName, $relPrefix);
    $relThumb = inv_item_images_web_relative($thumbName, $relPrefix);

    return [
        'ok' => true,
        'paths' => [
            'original' => $relOrig,
            'thumb' => $relThumb,
            'mime' => $mime,
        ],
    ];
}

function inv_item_image_delete_files(?string $relOriginal, ?string $relThumb): void {
    $root = inv_item_images_project_root();
    foreach ([$relOriginal, $relThumb] as $rel) {
        if (!$rel) {
            continue;
        }
        $p = $root . '/' . ltrim($rel, '/');
        if (is_file($p)) {
            @unlink($p);
        }
    }
}

/**
 * Insert DB row; optionally set as primary (clears other primaries for that item).
 */
function inv_item_image_save_row(PDO $conn, int $companyId, int $itemId, string $pathOriginal, string $pathThumb, string $mime, bool $setPrimary): int {
    if ($setPrimary) {
        $conn->prepare("UPDATE inv_item_images SET is_primary = 0 WHERE company_id = ? AND item_id = ?")
            ->execute([$companyId, $itemId]);
    }

    $stmt = $conn->prepare("
        INSERT INTO inv_item_images (company_id, item_id, path_original, path_thumb, mime_type, sort_order, is_primary)
        VALUES (?,?,?,?,?,0,?)
    ");
    $stmt->execute([$companyId, $itemId, $pathOriginal, $pathThumb, $mime, $setPrimary ? 1 : 0]);
    $id = (int)$conn->lastInsertId();

    if ($setPrimary) {
        $conn->prepare("UPDATE inv_items SET primary_image_id = ? WHERE id = ? AND company_id = ?")
            ->execute([$id, $itemId, $companyId]);
    }

    return $id;
}

function inv_item_image_set_primary(PDO $conn, int $companyId, int $itemId, int $imageId): bool {
    $st = $conn->prepare("SELECT id FROM inv_item_images WHERE id = ? AND company_id = ? AND item_id = ? LIMIT 1");
    $st->execute([$imageId, $companyId, $itemId]);
    if (!$st->fetchColumn()) {
        return false;
    }
    $conn->prepare("UPDATE inv_item_images SET is_primary = 0 WHERE company_id = ? AND item_id = ?")
        ->execute([$companyId, $itemId]);
    $conn->prepare("UPDATE inv_item_images SET is_primary = 1 WHERE id = ? AND company_id = ?")
        ->execute([$imageId, $companyId]);
    $conn->prepare("UPDATE inv_items SET primary_image_id = ? WHERE id = ? AND company_id = ?")
        ->execute([$imageId, $itemId, $companyId]);
    return true;
}

function inv_item_image_delete(PDO $conn, int $companyId, int $imageId): bool {
    $st = $conn->prepare("SELECT * FROM inv_item_images WHERE id = ? AND company_id = ? LIMIT 1");
    $st->execute([$imageId, $companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    inv_item_image_delete_files($row['path_original'] ?? null, $row['path_thumb'] ?? null);
    $conn->prepare("DELETE FROM inv_item_images WHERE id = ? AND company_id = ?")->execute([$imageId, $companyId]);

    $itemId = (int)$row['item_id'];
    $st2 = $conn->prepare("SELECT id FROM inv_item_images WHERE item_id = ? AND company_id = ? ORDER BY id ASC LIMIT 1");
    $st2->execute([$itemId, $companyId]);
    $next = $st2->fetchColumn();
    $newPrimary = $next ? (int)$next : null;
    $conn->prepare("UPDATE inv_items SET primary_image_id = ? WHERE id = ? AND company_id = ?")
        ->execute([$newPrimary, $itemId, $companyId]);
    if ($newPrimary) {
        $conn->prepare("UPDATE inv_item_images SET is_primary = IF(id = ?,1,0) WHERE item_id = ? AND company_id = ?")
            ->execute([$newPrimary, $itemId, $companyId]);
    }
    return true;
}
