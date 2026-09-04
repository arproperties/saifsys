<?php
/**
 * Real Estate Accounting - Journal Entry Attachments
 *
 * Shared upload / list / delete helpers used by journal_entry_add.php (create + edit)
 * and journal_entry_view.php. Files land in uploads/journal_attachments and are only
 * ever linked through journal_attachment_file.php.
 */

if (!defined('RE_JOURNAL_ATTACH_DIR')) {
    define('RE_JOURNAL_ATTACH_DIR', __DIR__ . '/../../../uploads/journal_attachments');
}
if (!defined('RE_JOURNAL_ATTACH_REL')) {
    define('RE_JOURNAL_ATTACH_REL', 'uploads/journal_attachments');
}
if (!defined('RE_JOURNAL_ATTACH_MAX_BYTES')) {
    define('RE_JOURNAL_ATTACH_MAX_BYTES', 10 * 1024 * 1024); // 10 MB per file
}

function journal_attachment_allowed_extensions(): array {
    return ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'xls', 'xlsx', 'doc', 'docx', 'csv', 'txt'];
}

/**
 * Content-Type to serve an attachment with, derived from the extension WE assigned
 * on upload - never from the browser-supplied type, which the uploader controls.
 * Returns null for types that must not be rendered inline (forced to download).
 */
function journal_attachment_inline_mime(string $fileName): ?string {
    static $inlineSafe = [
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'txt'  => 'text/plain; charset=UTF-8',
    ];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return $inlineSafe[$ext] ?? null;
}

/**
 * Make sure the attachments table exists. Self-installing so the feature does not
 * silently swallow uploads on an environment where the migration has not been run.
 */
function journal_attachments_table_ready(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $exists = $conn->query("SHOW TABLES LIKE 're_journal_attachments'")->fetchColumn();
        if ($exists) {
            return $ready = true;
        }
        $conn->exec("
            CREATE TABLE IF NOT EXISTS `re_journal_attachments` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT(11) NOT NULL,
                `journal_id` INT(11) NOT NULL,
                `file_name` VARCHAR(255) NOT NULL,
                `file_path` VARCHAR(500) NOT NULL,
                `mime_type` VARCHAR(100) DEFAULT NULL,
                `file_size` INT(11) DEFAULT NULL,
                `uploaded_by` INT(11) DEFAULT NULL,
                `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_re_journal_attach` (`company_id`, `journal_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Attachments for Real Estate manual journal entries.'
        ");
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function journal_attachments_list(PDO $conn, int $journalId, int $companyId): array {
    if (!$journalId || !journal_attachments_table_ready($conn)) return [];
    try {
        $stmt = $conn->prepare("
            SELECT ja.*, u.username AS uploaded_by_name
            FROM re_journal_attachments ja
            LEFT JOIN user u ON u.id = ja.uploaded_by
            WHERE ja.journal_id = ? AND ja.company_id = ?
            ORDER BY ja.uploaded_at DESC, ja.id DESC
        ");
        $stmt->execute([$journalId, $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Store uploaded files against a journal.
 *
 * @param array $files A single entry of $_FILES (the multi-file "name[]" shape).
 * @return array{saved:int, errors:string[]} Errors are per-file and user-facing.
 */
function journal_attachments_save(PDO $conn, array $files, int $journalId, int $companyId, ?int $userId): array {
    $result = ['saved' => 0, 'errors' => []];

    if (empty($files['name']) || !is_array($files['name'])) {
        return $result;
    }
    // Nothing actually chosen.
    $hasAny = false;
    foreach ($files['name'] as $n) { if ($n !== '' && $n !== null) { $hasAny = true; break; } }
    if (!$hasAny) return $result;

    if (!journal_attachments_table_ready($conn)) {
        $result['errors'][] = 'Attachment storage is unavailable (re_journal_attachments table could not be created).';
        return $result;
    }

    if (!is_dir(RE_JOURNAL_ATTACH_DIR) && !mkdir(RE_JOURNAL_ATTACH_DIR, 0755, true) && !is_dir(RE_JOURNAL_ATTACH_DIR)) {
        $result['errors'][] = 'Could not create the attachments upload folder.';
        return $result;
    }

    // Files are only ever served through journal_attachment_file.php, which checks
    // login and company. uploads/ has no deny rule of its own, so drop one here -
    // this also covers the folder being auto-created on a fresh deployment.
    $denyFile = RE_JOURNAL_ATTACH_DIR . '/.htaccess';
    if (!file_exists($denyFile)) {
        @file_put_contents($denyFile, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }

    $allowed = journal_attachment_allowed_extensions();

    foreach ($files['name'] as $i => $name) {
        if ($name === '' || $name === null) continue;

        $errCode = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($errCode === UPLOAD_ERR_NO_FILE) continue;
        if ($errCode !== UPLOAD_ERR_OK) {
            $result['errors'][] = sprintf('"%s" was not uploaded (PHP upload error %d).', $name, $errCode);
            continue;
        }

        $size = (int)($files['size'][$i] ?? 0);
        if ($size <= 0) {
            $result['errors'][] = sprintf('"%s" is empty and was skipped.', $name);
            continue;
        }
        if ($size > RE_JOURNAL_ATTACH_MAX_BYTES) {
            $result['errors'][] = sprintf('"%s" exceeds the %d MB limit.', $name, (int)(RE_JOURNAL_ATTACH_MAX_BYTES / 1024 / 1024));
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $result['errors'][] = sprintf('"%s" has an unsupported file type (.%s).', $name, $ext);
            continue;
        }

        $safe = 'journal_' . $journalId . '_' . time() . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = RE_JOURNAL_ATTACH_DIR . '/' . $safe;

        if (!move_uploaded_file($files['tmp_name'][$i], $target)) {
            $result['errors'][] = sprintf('"%s" could not be saved to disk.', $name);
            continue;
        }

        // Never store $files['type'][$i]: it is browser-supplied and the uploader
        // controls it, so a .txt declared as text/html would be served inline as
        // HTML on our own origin. Detect from the bytes on disk instead.
        $detectedMime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detectedMime = finfo_file($finfo, $target) ?: null;
                finfo_close($finfo);
            }
        }

        try {
            $stmt = $conn->prepare("
                INSERT INTO re_journal_attachments
                (company_id, journal_id, file_name, file_path, mime_type, file_size, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $companyId,
                $journalId,
                substr($name, 0, 255),
                RE_JOURNAL_ATTACH_REL . '/' . $safe,
                $detectedMime,
                $size,
                $userId,
            ]);
            $result['saved']++;
        } catch (Throwable $e) {
            @unlink($target);
            $result['errors'][] = sprintf('"%s" could not be recorded in the database.', $name);
        }
    }

    return $result;
}

/** Delete one attachment (row + file on disk). */
function journal_attachment_delete(PDO $conn, int $attachmentId, int $companyId): bool {
    if (!$attachmentId || !journal_attachments_table_ready($conn)) return false;
    try {
        $stmt = $conn->prepare("SELECT file_path FROM re_journal_attachments WHERE id = ? AND company_id = ?");
        $stmt->execute([$attachmentId, $companyId]);
        $path = $stmt->fetchColumn();
        if ($path === false) return false;

        $conn->prepare("DELETE FROM re_journal_attachments WHERE id = ? AND company_id = ?")
             ->execute([$attachmentId, $companyId]);

        $projectRoot = realpath(__DIR__ . '/../../../');
        $real = realpath($projectRoot . '/' . ltrim((string)$path, '/'));
        $uploadsRoot = realpath($projectRoot . '/uploads');
        if ($real && $uploadsRoot && strpos($real, $uploadsRoot) === 0 && is_file($real)) {
            @unlink($real);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Bootstrap icon for an attachment, by extension. */
function journal_attachment_icon(string $fileName): string {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext === 'pdf') return 'bi-file-earmark-pdf text-danger';
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) return 'bi-file-earmark-image text-primary';
    if (in_array($ext, ['xls', 'xlsx', 'csv'], true)) return 'bi-file-earmark-spreadsheet text-success';
    if (in_array($ext, ['doc', 'docx'], true)) return 'bi-file-earmark-word text-primary';
    return 'bi-file-earmark text-secondary';
}

/** Human-readable file size. */
function journal_attachment_size(?int $bytes): string {
    $bytes = (int)$bytes;
    if ($bytes <= 0) return '-';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1024 / 1024, 2) . ' MB';
}
