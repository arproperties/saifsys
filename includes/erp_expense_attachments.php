<?php
/**
 * ERP expense attachments (Quick Paid Expenses — Real Estate / Construction / ARS).
 *
 * Rows live in erp_expense_attachments, files in uploads/erp_expense_attachments,
 * and a file is only ever linked through modules/realestate/expense_file.php, which
 * re-checks login and company. Nothing links a stored path directly.
 *
 * Staging: expense_add.php can reject a POST after files were chosen (the
 * possible-duplicate confirmation re-posts the whole form) and a browser never
 * resends a file input on reload. So uploads are moved into a per-form pending
 * folder on the first POST and only committed once the expense row exists.
 */

if (!defined('ERP_EXPENSE_ATTACH_DIR')) {
    define('ERP_EXPENSE_ATTACH_DIR', dirname(__DIR__) . '/uploads/erp_expense_attachments');
}
if (!defined('ERP_EXPENSE_ATTACH_REL')) {
    define('ERP_EXPENSE_ATTACH_REL', 'uploads/erp_expense_attachments');
}
if (!defined('ERP_EXPENSE_ATTACH_MAX_BYTES')) {
    define('ERP_EXPENSE_ATTACH_MAX_BYTES', 10 * 1024 * 1024); // 10 MB per file
}
if (!defined('ERP_EXPENSE_ATTACH_MAX_FILES')) {
    define('ERP_EXPENSE_ATTACH_MAX_FILES', 10);
}

function erp_expense_attachment_allowed_extensions(): array {
    return ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'xls', 'xlsx', 'doc', 'docx', 'csv', 'txt'];
}

/**
 * Content-Type to serve an attachment with, derived from the extension WE assigned
 * on upload — never from the browser-supplied type, which the uploader controls.
 * Returns null for types that must not render inline (those are forced to download).
 */
function erp_expense_attachment_inline_mime(string $fileName): ?string {
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
 * erp_expense_attachments ships in migrations/erp_expenses.sql but older databases
 * may predate it. Create it rather than swallowing uploads silently.
 */
function erp_expense_attachments_table_ready(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $exists = $conn->query("SHOW TABLES LIKE 'erp_expense_attachments'")->fetchColumn();
        if ($exists) {
            return $ready = true;
        }
        $conn->exec("
            CREATE TABLE IF NOT EXISTS `erp_expense_attachments` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `expense_id` int(11) NOT NULL,
                `file_name` varchar(255) NOT NULL,
                `file_path` varchar(500) NOT NULL,
                `mime_type` varchar(100) DEFAULT NULL,
                `file_size` int(11) DEFAULT NULL,
                `uploaded_by` int(11) DEFAULT NULL,
                `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_expense` (`expense_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/** Create the upload folder and keep a deny rule in it (uploads/ has none of its own). */
function erp_expense_attachments_dir_ready(): bool {
    if (!is_dir(ERP_EXPENSE_ATTACH_DIR)
        && !mkdir(ERP_EXPENSE_ATTACH_DIR, 0755, true)
        && !is_dir(ERP_EXPENSE_ATTACH_DIR)) {
        return false;
    }
    $denyFile = ERP_EXPENSE_ATTACH_DIR . '/.htaccess';
    if (!file_exists($denyFile)) {
        @file_put_contents($denyFile, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return true;
}

/**
 * A POST bigger than php.ini's post_max_size arrives with $_POST and $_FILES both
 * empty, so CSRF verification would fail first and blame the wrong thing. Callers
 * check this before anything else on a form that accepts files.
 */
function erp_expense_attachments_post_too_large(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return false;
    if (!empty($_POST) || !empty($_FILES)) return false;
    return (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

/** php.ini's post_max_size, for the message shown when a submission overshoots it. */
function erp_expense_attachments_post_max_label(): string {
    $v = ini_get('post_max_size');
    return ($v !== false && $v !== '') ? (string)$v : 'the server limit';
}

/** Token identifying one in-progress form's staged uploads. */
function erp_expense_attachments_new_token(): string {
    return bin2hex(random_bytes(16));
}

function erp_expense_attachments_valid_token(?string $token): string {
    $token = (string)$token;
    return preg_match('/^[a-f0-9]{32}$/', $token) ? $token : '';
}

function erp_expense_attachments_pending_dir(string $token): string {
    return ERP_EXPENSE_ATTACH_DIR . '/_pending/' . $token;
}

/** Files staged against a token, in the order they were chosen. */
function erp_expense_attachments_pending(string $token): array {
    $token = erp_expense_attachments_valid_token($token);
    if ($token === '') return [];
    return $_SESSION['erp_expense_pending_attachments'][$token] ?? [];
}

/** Drop staged files for a token (session entry + files on disk). */
function erp_expense_attachments_pending_clear(string $token): void {
    $token = erp_expense_attachments_valid_token($token);
    if ($token === '') return;
    $dir = erp_expense_attachments_pending_dir($token);
    foreach (erp_expense_attachments_pending($token) as $p) {
        $path = $dir . '/' . ($p['stored'] ?? '');
        if (!empty($p['stored']) && is_file($path)) {
            @unlink($path);
        }
    }
    @rmdir($dir);
    unset($_SESSION['erp_expense_pending_attachments'][$token]);
}

/** Best-effort sweep of pending folders left behind by abandoned forms. */
function erp_expense_attachments_sweep_pending(int $olderThanSeconds = 86400): void {
    $root = ERP_EXPENSE_ATTACH_DIR . '/_pending';
    if (!is_dir($root)) return;
    $cutoff = time() - $olderThanSeconds;
    foreach ((array)@scandir($root) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $dir = $root . '/' . $entry;
        if (!is_dir($dir) || (int)@filemtime($dir) > $cutoff) continue;
        foreach ((array)@scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            @unlink($dir . '/' . $f);
        }
        @rmdir($dir);
    }
}

/**
 * Validate one $_FILES entry (the multi-file "name[]" shape) and move the files
 * into the token's pending folder. Per-file problems come back in $errors and do
 * not stop the other files.
 *
 * @return int number of files staged by this call
 */
function erp_expense_attachments_stage(array $files, string $token, array &$errors): int {
    $token = erp_expense_attachments_valid_token($token);
    if ($token === '') {
        $errors[] = 'Attachment upload token was missing or invalid — please choose the files again.';
        return 0;
    }
    if (empty($files['name']) || !is_array($files['name'])) {
        return 0;
    }
    $hasAny = false;
    foreach ($files['name'] as $n) {
        if ($n !== '' && $n !== null) { $hasAny = true; break; }
    }
    if (!$hasAny) return 0;

    if (!erp_expense_attachments_dir_ready()) {
        $errors[] = 'Could not create the attachments upload folder.';
        return 0;
    }
    erp_expense_attachments_sweep_pending();

    $dir = erp_expense_attachments_pending_dir($token);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        $errors[] = 'Could not create the attachments staging folder.';
        return 0;
    }

    $pending = erp_expense_attachments_pending($token);
    $allowed = erp_expense_attachment_allowed_extensions();
    $staged = 0;

    foreach ($files['name'] as $i => $name) {
        if ($name === '' || $name === null) continue;

        $errCode = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($errCode === UPLOAD_ERR_NO_FILE) continue;
        if ($errCode !== UPLOAD_ERR_OK) {
            $errors[] = sprintf('"%s" was not uploaded (PHP upload error %d).', $name, $errCode);
            continue;
        }
        if (count($pending) >= ERP_EXPENSE_ATTACH_MAX_FILES) {
            $errors[] = sprintf('"%s" was skipped — at most %d files per expense.', $name, ERP_EXPENSE_ATTACH_MAX_FILES);
            continue;
        }

        $size = (int)($files['size'][$i] ?? 0);
        if ($size <= 0) {
            $errors[] = sprintf('"%s" is empty and was skipped.', $name);
            continue;
        }
        if ($size > ERP_EXPENSE_ATTACH_MAX_BYTES) {
            $errors[] = sprintf('"%s" exceeds the %d MB limit.', $name, (int)(ERP_EXPENSE_ATTACH_MAX_BYTES / 1024 / 1024));
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $errors[] = sprintf('"%s" has an unsupported file type (.%s).', $name, $ext);
            continue;
        }

        $stored = bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($files['tmp_name'][$i], $dir . '/' . $stored)) {
            $errors[] = sprintf('"%s" could not be saved to disk.', $name);
            continue;
        }

        // Never trust $files['type'][$i]: the uploader controls it, so a .txt declared
        // as text/html would be served inline as HTML on our own origin. Read the bytes.
        $detectedMime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detectedMime = finfo_file($finfo, $dir . '/' . $stored) ?: null;
                finfo_close($finfo);
            }
        }

        $pending[] = [
            'stored' => $stored,
            'name'   => substr((string)$name, 0, 255),
            'size'   => $size,
            'mime'   => $detectedMime,
        ];
        $staged++;
    }

    $_SESSION['erp_expense_pending_attachments'][$token] = $pending;
    @touch($dir);
    return $staged;
}

/**
 * Move a token's staged files onto a saved expense. Called after the expense row
 * exists and outside its transaction — a file that fails here must not roll the
 * expense back, it is reported instead.
 *
 * @return array{saved:int, errors:string[]}
 */
function erp_expense_attachments_commit(PDO $conn, int $expenseId, ?int $userId, string $token): array {
    $result = ['saved' => 0, 'errors' => []];
    $pending = erp_expense_attachments_pending($token);
    if ($expenseId <= 0 || !$pending) {
        return $result;
    }
    if (!erp_expense_attachments_table_ready($conn)) {
        $result['errors'][] = 'Attachment storage is unavailable (erp_expense_attachments table could not be created) — the expense saved without its files.';
        return $result;
    }
    if (!erp_expense_attachments_dir_ready()) {
        $result['errors'][] = 'Could not create the attachments upload folder — the expense saved without its files.';
        return $result;
    }

    $dir = erp_expense_attachments_pending_dir($token);
    $ins = $conn->prepare("
        INSERT INTO erp_expense_attachments
        (expense_id, file_name, file_path, mime_type, file_size, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($pending as $i => $p) {
        $src = $dir . '/' . ($p['stored'] ?? '');
        if (empty($p['stored']) || !is_file($src)) {
            $result['errors'][] = sprintf('"%s" was no longer on the server and could not be attached.', $p['name'] ?? 'File');
            continue;
        }
        $ext = strtolower(pathinfo((string)$p['stored'], PATHINFO_EXTENSION));
        $safe = 'expense_' . $expenseId . '_' . time() . '_' . $i . '_' . bin2hex(random_bytes(4)) . ($ext ? '.' . $ext : '');
        $target = ERP_EXPENSE_ATTACH_DIR . '/' . $safe;

        if (!@rename($src, $target)) {
            $result['errors'][] = sprintf('"%s" could not be moved into the attachments folder.', $p['name'] ?? 'File');
            continue;
        }
        try {
            $ins->execute([
                $expenseId,
                $p['name'] ?? $safe,
                ERP_EXPENSE_ATTACH_REL . '/' . $safe,
                $p['mime'] ?? null,
                (int)($p['size'] ?? 0),
                $userId,
            ]);
            $result['saved']++;
        } catch (Throwable $e) {
            @unlink($target);
            $result['errors'][] = sprintf('"%s" could not be recorded in the database.', $p['name'] ?? 'File');
        }
    }

    @rmdir($dir);
    unset($_SESSION['erp_expense_pending_attachments'][$token]);
    return $result;
}

function erp_expense_attachments_list(PDO $conn, int $expenseId): array {
    if ($expenseId <= 0 || !erp_expense_attachments_table_ready($conn)) return [];
    try {
        $stmt = $conn->prepare("
            SELECT a.*, u.username AS uploaded_by_name
            FROM erp_expense_attachments a
            LEFT JOIN user u ON u.id = a.uploaded_by
            WHERE a.expense_id = ?
            ORDER BY a.uploaded_at DESC, a.id DESC
        ");
        $stmt->execute([$expenseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Delete one attachment (row + file), scoped to the company that owns the expense. */
function erp_expense_attachment_delete(PDO $conn, int $attachmentId, int $companyId): bool {
    if ($attachmentId <= 0 || !erp_expense_attachments_table_ready($conn)) return false;
    try {
        $stmt = $conn->prepare("
            SELECT a.file_path
            FROM erp_expense_attachments a
            JOIN erp_expense_headers h ON h.id = a.expense_id
            WHERE a.id = ? AND h.company_id = ?
        ");
        $stmt->execute([$attachmentId, $companyId]);
        $path = $stmt->fetchColumn();
        if ($path === false) return false;

        $conn->prepare("DELETE FROM erp_expense_attachments WHERE id = ?")->execute([$attachmentId]);

        $projectRoot = realpath(dirname(__DIR__));
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
function erp_expense_attachment_icon(string $fileName): string {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext === 'pdf') return 'bi-file-earmark-pdf text-danger';
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) return 'bi-file-earmark-image text-primary';
    if (in_array($ext, ['xls', 'xlsx', 'csv'], true)) return 'bi-file-earmark-spreadsheet text-success';
    if (in_array($ext, ['doc', 'docx'], true)) return 'bi-file-earmark-word text-primary';
    return 'bi-file-earmark text-secondary';
}

/** Human-readable file size. */
function erp_expense_attachment_size(?int $bytes): string {
    $bytes = (int)$bytes;
    if ($bytes <= 0) return '-';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1024 / 1024, 2) . ' MB';
}

/** Where the browser reaches the file server from any of the three module folders. */
function erp_expense_attachment_href(int $attachmentId, string $mode = 'view'): string {
    return '../realestate/expense_file.php?id=' . $attachmentId . '&mode=' . ($mode === 'download' ? 'download' : 'view');
}

/**
 * One-shot banner for attachment problems raised after the expense itself saved
 * (the save redirects, so the message has to survive one request). Rendered by the
 * three expense list pages.
 */
function erp_expense_attachment_flash_html(): string {
    $errors = $_SESSION['erp_expense_attach_errors'] ?? [];
    unset($_SESSION['erp_expense_attach_errors']);
    if (!$errors) return '';
    $items = '';
    foreach ((array)$errors as $e) {
        $items .= '<li>' . htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    return '<div class="alert alert-warning alert-dismissible">'
        . '<strong>The expense saved, but some attachments did not:</strong>'
        . '<ul class="mb-0 mt-1 small">' . $items . '</ul>'
        . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}
