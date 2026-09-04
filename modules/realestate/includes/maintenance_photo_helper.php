<?php
/**
 * Store / validate maintenance request photos (shared by create form + AJAX).
 */

function re_maint_photo_allowed_extensions(): array
{
    return ['jpg', 'jpeg', 'png', 'gif', 'webp'];
}

function re_maint_photo_allowed_mimes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
}

/**
 * Persist one uploaded photo for a maintenance request.
 *
 * @param array $file Single $_FILES[...] entry
 * @return array{ok:bool,error?:string,photo_id?:int,file_path?:string}
 */
function re_maint_store_photo(
    PDO $conn,
    int $companyId,
    int $requestId,
    array $file,
    string $photoType = 'before',
    ?string $description = null,
    ?int $uploadedBy = null,
    int $maxBytes = 10485760
): array {
    if ($companyId <= 0 || $requestId <= 0) {
        return ['ok' => false, 'error' => 'Company and request context are required.'];
    }

    $validTypes = ['before', 'during', 'after', 'completion'];
    if (!in_array($photoType, $validTypes, true)) {
        return ['ok' => false, 'error' => 'Invalid photo type.'];
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
        return ['ok' => false, 'error' => 'Each photo must be 10 MB or smaller.'];
    }

    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, re_maint_photo_allowed_extensions(), true)) {
        return ['ok' => false, 'error' => 'Only JPG, PNG, GIF, or WEBP images are allowed.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    $allowedMimes = re_maint_photo_allowed_mimes();
    if (!isset($allowedMimes[$mime]) || @getimagesize($file['tmp_name']) === false) {
        return ['ok' => false, 'error' => 'Uploaded file is not a valid image.'];
    }
    $ext = $allowedMimes[$mime];

    $chk = $conn->prepare('SELECT id FROM re_maintenance_requests WHERE id = ? AND company_id = ? LIMIT 1');
    $chk->execute([$requestId, $companyId]);
    if (!$chk->fetchColumn()) {
        return ['ok' => false, 'error' => 'Maintenance request not found.'];
    }

    $uploadBaseDir = dirname(__DIR__, 3) . '/uploads/realestate/maintenance';
    $uploadDir = $uploadBaseDir . '/' . $requestId;
    if (!is_dir($uploadBaseDir) && !@mkdir($uploadBaseDir, 0777, true)) {
        return ['ok' => false, 'error' => 'Cannot create photo upload directory.'];
    }
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0777, true)) {
        return ['ok' => false, 'error' => 'Cannot create request photo folder.'];
    }
    if (!is_writable($uploadDir)) {
        @chmod($uploadDir, 0777);
    }
    if (!is_writable($uploadDir)) {
        return ['ok' => false, 'error' => 'Photo upload folder is not writable.'];
    }

    $safeBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo((string)$file['name'], PATHINFO_FILENAME)) ?: 'photo';
    $newFileName = $safeBase . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $uploadDir . '/' . $newFileName;
    $relativePath = 'uploads/realestate/maintenance/' . $requestId . '/' . $newFileName;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Failed to save uploaded photo.'];
    }

    $stmt = $conn->prepare("
        INSERT INTO re_maintenance_photos
            (company_id, maintenance_request_id, photo_type, file_name, file_path, file_size, mime_type, description, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $companyId,
        $requestId,
        $photoType,
        (string)$file['name'],
        $relativePath,
        (int)$file['size'],
        $mime,
        $description !== null && $description !== '' ? $description : null,
        $uploadedBy,
    ]);

    return [
        'ok' => true,
        'photo_id' => (int)$conn->lastInsertId(),
        'file_path' => $relativePath,
    ];
}

/**
 * Store multiple photos from a multi-file input (e.g. name="photos[]").
 *
 * @return array{saved:int,errors:list<string>}
 */
function re_maint_store_photos_from_files(
    PDO $conn,
    int $companyId,
    int $requestId,
    array $filesField,
    string $photoType = 'before',
    ?int $uploadedBy = null,
    int $maxFiles = 8
): array {
    $saved = 0;
    $errors = [];

    if (empty($filesField['name']) || !is_array($filesField['name'])) {
        return ['saved' => 0, 'errors' => []];
    }

    $count = count($filesField['name']);
    if ($count > $maxFiles) {
        $errors[] = 'Maximum ' . $maxFiles . ' photos can be attached at once.';
        $count = $maxFiles;
    }

    for ($i = 0; $i < $count; $i++) {
        if (($filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $one = [
            'name' => $filesField['name'][$i] ?? '',
            'type' => $filesField['type'][$i] ?? '',
            'tmp_name' => $filesField['tmp_name'][$i] ?? '',
            'error' => $filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $filesField['size'][$i] ?? 0,
        ];
        $res = re_maint_store_photo($conn, $companyId, $requestId, $one, $photoType, null, $uploadedBy);
        if (!empty($res['ok'])) {
            $saved++;
        } else {
            $errors[] = ($one['name'] ?: 'Photo') . ': ' . ($res['error'] ?? 'Upload failed');
        }
    }

    return ['saved' => $saved, 'errors' => $errors];
}
