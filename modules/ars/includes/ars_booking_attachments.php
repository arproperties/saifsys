<?php
/**
 * ARS booking staff attachments (Wave A) — company-scoped, extension+MIME checked.
 */

declare(strict_types=1);

function ars_booking_attachments_storage_root(): string {
    return dirname(__DIR__, 3) . '/uploads/ars_booking_attachments';
}

function ars_booking_attachments_ensure_writable_dir(string $dir): bool {
    if (is_dir($dir)) {
        if (!is_writable($dir)) {
            @chmod($dir, 0777);
        }
        return is_writable($dir);
    }
    if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }
    @chmod($dir, 0777);
    return is_dir($dir) && is_writable($dir);
}

function ars_booking_attachments_ensure_schema(PDO $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $conn->exec("
        CREATE TABLE IF NOT EXISTS ars_booking_attachments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            booking_id INT NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            relative_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            file_size INT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ars_att_booking (company_id, booking_id),
            INDEX idx_ars_att_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $root = ars_booking_attachments_storage_root();
    if (!ars_booking_attachments_ensure_writable_dir($root)) {
        error_log('ars_booking_attachments: storage root not writable: ' . $root);
    }
    $ht = $root . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Options -Indexes\n<FilesMatch \"\\.(?i:php|phtml|phar|cgi|pl)$\">\n  Require all denied\n</FilesMatch>\n");
    }
}

/** @return list<string> */
function ars_booking_attachment_allowed_extensions(): array {
    return ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
}

/** @return array<string,string> ext => mime prefix allow */
function ars_booking_attachment_mime_map(): array {
    return [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'txt' => 'text/plain',
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function ars_booking_attachments_list(PDO $conn, int $companyId, int $bookingId): array {
    ars_booking_attachments_ensure_schema($conn);
    $stmt = $conn->prepare("
        SELECT id, original_name, mime_type, file_size, uploaded_by, created_at
        FROM ars_booking_attachments
        WHERE company_id = ? AND booking_id = ?
        ORDER BY id DESC
    ");
    $stmt->execute([$companyId, $bookingId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{success:bool,error:?string,attachment:?array}
 */
function ars_booking_attachment_upload(
    PDO $conn,
    int $companyId,
    int $bookingId,
    array $file,
    ?int $userId
): array {
    ars_booking_attachments_ensure_schema($conn);

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed (error code ' . (int)($file['error'] ?? 0) . ').', 'attachment' => null];
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        return ['success' => false, 'error' => 'File must be between 1 byte and 10 MB.', 'attachment' => null];
    }

    $orig = (string)($file['name'] ?? 'file');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ars_booking_attachment_allowed_extensions(), true)) {
        return ['success' => false, 'error' => 'File type not allowed.', 'attachment' => null];
    }

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file((string)$file['tmp_name']);
    } elseif (function_exists('mime_content_type')) {
        $mime = (string)@mime_content_type((string)$file['tmp_name']);
    }
    $expected = ars_booking_attachment_mime_map()[$ext] ?? '';
    if ($mime === '') {
        $mime = $expected !== '' ? $expected : 'application/octet-stream';
    } else {
        $mimeOk = $mime === $expected
            || ($ext === 'txt' && str_starts_with($mime, 'text/'))
            || (str_starts_with($ext, 'jp') && $mime === 'image/jpeg')
            || (str_starts_with($mime, 'image/') && in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true));
        if (!$mimeOk) {
            return ['success' => false, 'error' => 'MIME type does not match extension (' . $mime . ').', 'attachment' => null];
        }
    }

    $dir = ars_booking_attachments_storage_root() . '/' . $companyId . '/' . $bookingId;
    if (!ars_booking_attachments_ensure_writable_dir($dir)) {
        return [
            'success' => false,
            'error' => 'Could not create upload directory. Ensure uploads/ars_booking_attachments is writable by the web server.',
            'attachment' => null,
        ];
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . '/' . $stored;
    if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
        return ['success' => false, 'error' => 'Could not save uploaded file.', 'attachment' => null];
    }

    $rel = 'uploads/ars_booking_attachments/' . $companyId . '/' . $bookingId . '/' . $stored;
    $safeOrig = substr(preg_replace('/[^\w.\- ()]+/u', '_', $orig) ?: 'file.' . $ext, 0, 240);

    $conn->prepare("
        INSERT INTO ars_booking_attachments
            (company_id, booking_id, original_name, stored_name, relative_path, mime_type, file_size, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([$companyId, $bookingId, $safeOrig, $stored, $rel, $mime, $size, $userId]);

    $id = (int)$conn->lastInsertId();
    return [
        'success' => true,
        'error' => null,
        'attachment' => [
            'id' => $id,
            'original_name' => $safeOrig,
            'mime_type' => $mime,
            'file_size' => $size,
        ],
    ];
}

/**
 * @return array{success:bool,error:?string,path:?string,filename:?string,mime:?string}
 */
function ars_booking_attachment_resolve(PDO $conn, int $companyId, int $bookingId, int $attachmentId): array {
    ars_booking_attachments_ensure_schema($conn);
    $stmt = $conn->prepare("
        SELECT * FROM ars_booking_attachments
        WHERE id = ? AND company_id = ? AND booking_id = ?
        LIMIT 1
    ");
    $stmt->execute([$attachmentId, $companyId, $bookingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['success' => false, 'error' => 'Attachment not found.', 'path' => null, 'filename' => null, 'mime' => null];
    }
    $abs = dirname(__DIR__, 3) . '/' . ltrim((string)$row['relative_path'], '/');
    if (!is_file($abs)) {
        return ['success' => false, 'error' => 'Attachment file missing on disk.', 'path' => null, 'filename' => null, 'mime' => null];
    }
    return [
        'success' => true,
        'error' => null,
        'path' => $abs,
        'filename' => (string)$row['original_name'],
        'mime' => (string)$row['mime_type'],
    ];
}
