<?php
/**
 * ARS guest profile documents — company-scoped, extension + MIME checked.
 *
 * Mirrors ars_booking_attachments.php, but files hang off the guest instead of a
 * single booking: identity paperwork (Emirates ID, passport, visa) belongs to the
 * person and stays valid across every stay they make.
 */

declare(strict_types=1);

function ars_guest_attachments_storage_root(): string {
    return dirname(__DIR__, 3) . '/uploads/ars_guest_attachments';
}

function ars_guest_attachments_ensure_writable_dir(string $dir): bool {
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

function ars_guest_attachments_ensure_schema(PDO $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $conn->exec("
        CREATE TABLE IF NOT EXISTS ars_guest_attachments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            guest_id INT NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            relative_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            file_size INT UNSIGNED NOT NULL DEFAULT 0,
            doc_category VARCHAR(32) NOT NULL DEFAULT 'other',
            doc_number VARCHAR(100) NULL,
            expiry_date DATE NULL,
            uploaded_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ars_gatt_guest (company_id, guest_id),
            INDEX idx_ars_gatt_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $root = ars_guest_attachments_storage_root();
    if (!ars_guest_attachments_ensure_writable_dir($root)) {
        error_log('ars_guest_attachments: storage root not writable: ' . $root);
    }
    // ID scans are personal data and are only ever read through
    // guest_document_view.php, so deny direct web access to the whole tree.
    $ht = $root . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, implode("\n", [
            'Options -Indexes',
            '<IfModule mod_authz_core.c>',
            '  Require all denied',
            '</IfModule>',
            '<IfModule !mod_authz_core.c>',
            '  Order allow,deny',
            '  Deny from all',
            '</IfModule>',
            '',
        ]));
    }
}

/**
 * Document types held on a guest profile.
 *
 * @return array<string,array{label:string,icon:string,hint:string}>
 */
function ars_guest_doc_categories(): array {
    return [
        'emirates_id' => [
            'label' => 'Emirates ID',
            'icon' => 'bi-person-vcard',
            'hint' => 'Front and back of the Emirates ID card.',
        ],
        'passport' => [
            'label' => 'Passport',
            'icon' => 'bi-passport',
            'hint' => 'Passport photo page.',
        ],
        'visa' => [
            'label' => 'Visa Copy',
            'icon' => 'bi-stamp',
            'hint' => 'Residence or entry visa page.',
        ],
        'driving_license' => [
            'label' => 'Driving License',
            'icon' => 'bi-car-front',
            'hint' => 'Driving license, when it is the ID on file.',
        ],
        'other' => [
            'label' => 'Other',
            'icon' => 'bi-paperclip',
            'hint' => 'Signed forms, correspondence, anything else about this guest.',
        ],
    ];
}

/** Unknown / legacy values fall back to 'other'. */
function ars_guest_doc_category_normalize(?string $value): string {
    $value = strtolower(trim((string)$value));
    return array_key_exists($value, ars_guest_doc_categories()) ? $value : 'other';
}

function ars_guest_doc_category_label(?string $value): string {
    $key = ars_guest_doc_category_normalize($value);
    return ars_guest_doc_categories()[$key]['label'];
}

/** @return list<string> */
function ars_guest_attachment_allowed_extensions(): array {
    return ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
}

/** @return array<string,string> ext => expected mime */
function ars_guest_attachment_mime_map(): array {
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
 * Content-Type used when serving a stored file. Derived from the extension, never
 * from the stored mime, so a mislabelled upload cannot be served as HTML.
 */
function ars_guest_attachment_serve_mime(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return ars_guest_attachment_mime_map()[$ext] ?? 'application/octet-stream';
}

/** Only these render inline; everything else downloads. */
function ars_guest_attachment_inline_ok(string $filename): bool {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

/**
 * @return list<array<string,mixed>>
 */
function ars_guest_attachments_list(PDO $conn, int $companyId, int $guestId): array {
    ars_guest_attachments_ensure_schema($conn);
    $stmt = $conn->prepare("
        SELECT a.id, a.original_name, a.stored_name, a.mime_type, a.file_size, a.doc_category,
               a.doc_number, a.expiry_date, a.uploaded_by, a.created_at,
               u.fullname AS uploaded_by_name
        FROM ars_guest_attachments a
        LEFT JOIN `user` u ON u.id = a.uploaded_by
        WHERE a.company_id = ? AND a.guest_id = ?
        ORDER BY a.id DESC
    ");
    $stmt->execute([$companyId, $guestId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{success:bool,error:?string,attachment:?array}
 */
function ars_guest_attachment_upload(
    PDO $conn,
    int $companyId,
    int $guestId,
    array $file,
    ?int $userId,
    ?string $docCategory = 'other',
    ?string $docNumber = null,
    ?string $expiryDate = null
): array {
    ars_guest_attachments_ensure_schema($conn);
    $docCategory = ars_guest_doc_category_normalize($docCategory);

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed (error code ' . (int)($file['error'] ?? 0) . ').', 'attachment' => null];
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        return ['success' => false, 'error' => 'File must be between 1 byte and 10 MB.', 'attachment' => null];
    }

    $orig = (string)($file['name'] ?? 'file');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ars_guest_attachment_allowed_extensions(), true)) {
        return ['success' => false, 'error' => 'File type not allowed.', 'attachment' => null];
    }

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file((string)$file['tmp_name']);
    } elseif (function_exists('mime_content_type')) {
        $mime = (string)@mime_content_type((string)$file['tmp_name']);
    }
    $expected = ars_guest_attachment_mime_map()[$ext] ?? '';
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

    $dir = ars_guest_attachments_storage_root() . '/' . $companyId . '/' . $guestId;
    if (!ars_guest_attachments_ensure_writable_dir($dir)) {
        return [
            'success' => false,
            'error' => 'Could not create upload directory. Ensure uploads/ars_guest_attachments is writable by the web server.',
            'attachment' => null,
        ];
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . '/' . $stored;
    if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
        return ['success' => false, 'error' => 'Could not save uploaded file.', 'attachment' => null];
    }

    $rel = 'uploads/ars_guest_attachments/' . $companyId . '/' . $guestId . '/' . $stored;
    $safeOrig = substr(preg_replace('/[^\w.\- ()]+/u', '_', $orig) ?: 'file.' . $ext, 0, 240);
    $docNumber = ($docNumber !== null && trim($docNumber) !== '') ? substr(trim($docNumber), 0, 100) : null;
    $expiryDate = ($expiryDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($expiryDate)) === 1)
        ? trim($expiryDate)
        : null;

    $conn->prepare("
        INSERT INTO ars_guest_attachments
            (company_id, guest_id, original_name, stored_name, relative_path, mime_type, file_size,
             doc_category, doc_number, expiry_date, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([$companyId, $guestId, $safeOrig, $stored, $rel, $mime, $size, $docCategory, $docNumber, $expiryDate, $userId]);

    $id = (int)$conn->lastInsertId();
    return [
        'success' => true,
        'error' => null,
        'attachment' => [
            'id' => $id,
            'original_name' => $safeOrig,
            'mime_type' => $mime,
            'file_size' => $size,
            'doc_category' => $docCategory,
            'doc_number' => $docNumber,
            'expiry_date' => $expiryDate,
        ],
    ];
}

/**
 * Re-file an existing document under a different type.
 *
 * @return array{success:bool,error:?string,doc_category:?string}
 */
function ars_guest_attachment_set_category(
    PDO $conn,
    int $companyId,
    int $guestId,
    int $attachmentId,
    ?string $docCategory
): array {
    ars_guest_attachments_ensure_schema($conn);
    $docCategory = ars_guest_doc_category_normalize($docCategory);
    $stmt = $conn->prepare("
        UPDATE ars_guest_attachments
        SET doc_category = ?
        WHERE id = ? AND company_id = ? AND guest_id = ?
    ");
    $stmt->execute([$docCategory, $attachmentId, $companyId, $guestId]);
    if ($stmt->rowCount() === 0) {
        $check = $conn->prepare("SELECT id FROM ars_guest_attachments WHERE id = ? AND company_id = ? AND guest_id = ? LIMIT 1");
        $check->execute([$attachmentId, $companyId, $guestId]);
        if (!$check->fetch(PDO::FETCH_ASSOC)) {
            return ['success' => false, 'error' => 'Document not found.', 'doc_category' => null];
        }
    }
    return ['success' => true, 'error' => null, 'doc_category' => $docCategory];
}

/**
 * @return array{success:bool,error:?string,path:?string,filename:?string,mime:?string}
 */
function ars_guest_attachment_resolve(PDO $conn, int $companyId, int $guestId, int $attachmentId): array {
    ars_guest_attachments_ensure_schema($conn);
    $stmt = $conn->prepare("
        SELECT * FROM ars_guest_attachments
        WHERE id = ? AND company_id = ? AND guest_id = ?
        LIMIT 1
    ");
    $stmt->execute([$attachmentId, $companyId, $guestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['success' => false, 'error' => 'Document not found.', 'path' => null, 'filename' => null, 'mime' => null];
    }
    $abs = dirname(__DIR__, 3) . '/' . ltrim((string)$row['relative_path'], '/');
    if (!is_file($abs)) {
        return ['success' => false, 'error' => 'Document file missing on disk.', 'path' => null, 'filename' => null, 'mime' => null];
    }
    return [
        'success' => true,
        'error' => null,
        'path' => $abs,
        'filename' => (string)$row['original_name'],
        // Serve type comes from the extension, not the stored mime (see BR note above).
        'mime' => ars_guest_attachment_serve_mime((string)$row['original_name']),
    ];
}

/**
 * Delete a document row and its file. Used for mis-filed ID scans, which are
 * personal data and should not linger on the profile.
 *
 * @return array{success:bool,error:?string,original_name:?string,doc_category:?string}
 */
function ars_guest_attachment_delete(PDO $conn, int $companyId, int $guestId, int $attachmentId): array {
    ars_guest_attachments_ensure_schema($conn);
    $stmt = $conn->prepare("
        SELECT * FROM ars_guest_attachments
        WHERE id = ? AND company_id = ? AND guest_id = ?
        LIMIT 1
    ");
    $stmt->execute([$attachmentId, $companyId, $guestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['success' => false, 'error' => 'Document not found.', 'original_name' => null, 'doc_category' => null];
    }
    $conn->prepare("DELETE FROM ars_guest_attachments WHERE id = ? AND company_id = ? AND guest_id = ?")
        ->execute([$attachmentId, $companyId, $guestId]);

    $abs = dirname(__DIR__, 3) . '/' . ltrim((string)$row['relative_path'], '/');
    if (is_file($abs)) {
        @unlink($abs);
    }
    return [
        'success' => true,
        'error' => null,
        'original_name' => (string)$row['original_name'],
        'doc_category' => (string)$row['doc_category'],
    ];
}
