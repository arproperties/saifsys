<?php
/**
 * HR attendance: status vocabulary, the note/attachment trail, and file storage.
 *
 * Every status change made from hr/attendance.php or hr/attendance_edit.php has
 * to carry a typed reason and at least one supporting file. The reason lands in
 * attendance.notes (the value it replaces is kept in attendance_status_changes)
 * and the files go to uploads/hr_attendance, reachable only through
 * hr/attendance_file.php — a stored path is never linked directly.
 *
 * Requires migrations/hr_attendance_excused_absent.sql to have been run: the
 * 'excused_absent' enum value cannot be created from PHP.
 */

if (!defined('HR_ATTENDANCE_ATTACH_DIR')) {
    define('HR_ATTENDANCE_ATTACH_DIR', dirname(__DIR__) . '/uploads/hr_attendance');
}
if (!defined('HR_ATTENDANCE_ATTACH_REL')) {
    define('HR_ATTENDANCE_ATTACH_REL', 'uploads/hr_attendance');
}
if (!defined('HR_ATTENDANCE_ATTACH_MAX_BYTES')) {
    define('HR_ATTENDANCE_ATTACH_MAX_BYTES', 10 * 1024 * 1024); // 10 MB per file
}
if (!defined('HR_ATTENDANCE_ATTACH_MAX_FILES')) {
    define('HR_ATTENDANCE_ATTACH_MAX_FILES', 5);
}
if (!defined('HR_ATTENDANCE_NOTE_MAX')) {
    define('HR_ATTENDANCE_NOTE_MAX', 255); // attendance.notes is varchar(255)
}

/* ------------------------------------------------------------------ status */

/**
 * The full status vocabulary, in the order it is offered. 'pending' comes first
 * because it is the column default — a row starts there and HR moves it on.
 * This order is display only; the enum's own order is fixed by the migration.
 */
function hr_attendance_statuses(): array {
    return [
        'pending'        => 'Pending',
        'approved'       => 'Approved',
        'absent'         => 'Absent',
        'half'           => 'Half Day',
        'on_leave'       => 'On Leave',
        'excused_absent' => 'Excused Absent',
    ];
}

function hr_attendance_status_keys(): array {
    return array_keys(hr_attendance_statuses());
}

function hr_attendance_status_label(?string $status): string {
    $all = hr_attendance_statuses();
    $status = (string)$status;
    return $all[$status] ?? ($status !== '' ? $status : '—');
}

/**
 * Excused Absent is orange, which Bootstrap has no text-bg utility for, so it
 * gets its own class — see hr_attendance_status_css().
 */
function hr_attendance_status_badge_html(?string $status): string {
    $status = (string)$status;
    $label  = htmlspecialchars(hr_attendance_status_label($status), ENT_QUOTES, 'UTF-8');
    if ($status === 'excused_absent') {
        return '<span class="badge hr-badge-excused">' . $label . '</span>';
    }
    $map = ['pending' => 'secondary', 'approved' => 'success', 'absent' => 'danger', 'half' => 'warning', 'on_leave' => 'info'];
    $cls = $map[$status] ?? 'secondary';
    return '<span class="badge text-bg-' . $cls . '">' . $label . '</span>';
}

/** Page CSS for the orange badge. Pages append this to $pageStyles. */
function hr_attendance_status_css(): string {
    return '
.hr-badge-excused { background-color:#fd7e14; color:#fff; }
.btn-hr-excused { background-color:#fd7e14; border-color:#fd7e14; color:#fff; }
.btn-hr-excused:hover, .btn-hr-excused:focus { background-color:#e06c0c; border-color:#e06c0c; color:#fff; }
.btn-outline-hr-excused { border-color:#fd7e14; color:#d9660b; }
.btn-outline-hr-excused:hover, .btn-outline-hr-excused:focus { background-color:#fd7e14; border-color:#fd7e14; color:#fff; }
';
}

/* ------------------------------------------------------------- file basics */

function hr_attendance_attach_allowed_extensions(): array {
    return ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
}

/**
 * Content-Type for serving, derived from the extension WE assigned on upload —
 * never from the browser-supplied type, which the uploader controls. Anything
 * not listed is forced to download so it can never render on our origin.
 */
function hr_attendance_attach_inline_mime(string $fileName): ?string {
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

function hr_attendance_attach_icon(string $fileName): string {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext === 'pdf') return 'bi-file-earmark-pdf text-danger';
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) return 'bi-file-earmark-image text-primary';
    if (in_array($ext, ['xls', 'xlsx'], true)) return 'bi-file-earmark-spreadsheet text-success';
    if (in_array($ext, ['doc', 'docx'], true)) return 'bi-file-earmark-word text-primary';
    return 'bi-file-earmark text-secondary';
}

function hr_attendance_attach_size(?int $bytes): string {
    $bytes = (int)$bytes;
    if ($bytes <= 0) return '-';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1024 / 1024, 2) . ' MB';
}

/** Where the browser reaches the file server from inside hr/. */
function hr_attendance_attach_href(int $attachmentId, string $mode = 'view'): string {
    return 'attendance_file.php?id=' . $attachmentId . '&mode=' . ($mode === 'download' ? 'download' : 'view');
}

/**
 * A POST bigger than php.ini's post_max_size arrives with $_POST and $_FILES
 * both empty, so CSRF verification would fail first and blame the wrong thing.
 * Pages that accept files check this before anything else.
 */
function hr_attendance_post_too_large(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return false;
    if (!empty($_POST) || !empty($_FILES)) return false;
    return (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

function hr_attendance_post_max_label(): string {
    $v = ini_get('post_max_size');
    return ($v !== false && $v !== '') ? (string)$v : 'the server limit';
}

/** True when the multi-file input actually carries a file. */
function hr_attendance_files_chosen(?array $files): bool {
    if (!$files || empty($files['name'])) return false;
    foreach ((array)$files['name'] as $i => $n) {
        if ($n === '' || $n === null) continue;
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        return true;
    }
    return false;
}

/* --------------------------------------------------------------- storage */

/** Create the folder and keep a deny rule in it (uploads/ has none of its own). */
function hr_attendance_attach_dir_ready(): bool {
    if (!is_dir(HR_ATTENDANCE_ATTACH_DIR)
        && !mkdir(HR_ATTENDANCE_ATTACH_DIR, 0755, true)
        && !is_dir(HR_ATTENDANCE_ATTACH_DIR)) {
        return false;
    }
    $denyFile = HR_ATTENDANCE_ATTACH_DIR . '/.htaccess';
    if (!file_exists($denyFile)) {
        @file_put_contents($denyFile, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return true;
}

/**
 * The two tables ship in migrations/hr_attendance_excused_absent.sql. Create them
 * if that file was not run, rather than swallowing an upload silently. The enum
 * value in step 1 of that migration still has to be applied by hand.
 */
function hr_attendance_tables_ready(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS `attendance_status_changes` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `attendance_id` INT(11) NOT NULL,
                `from_status` VARCHAR(32) DEFAULT NULL,
                `to_status` VARCHAR(32) NOT NULL,
                `note` VARCHAR(255) NOT NULL,
                `prev_notes` VARCHAR(255) DEFAULT NULL,
                `changed_by` INT(11) DEFAULT NULL,
                `changed_at` DATETIME NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_asc_attendance` (`attendance_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $conn->exec("
            CREATE TABLE IF NOT EXISTS `attendance_attachments` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `attendance_id` INT(11) NOT NULL,
                `change_id` INT(11) DEFAULT NULL,
                `file_name` VARCHAR(255) NOT NULL,
                `file_path` VARCHAR(500) NOT NULL,
                `mime_type` VARCHAR(100) DEFAULT NULL,
                `file_size` INT(11) DEFAULT NULL,
                `uploaded_by` INT(11) DEFAULT NULL,
                `uploaded_at` DATETIME NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_aa_attendance` (`attendance_id`),
                KEY `idx_aa_change` (`change_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * The status values this database actually accepts, read out of the enum. A
 * database that predates migrations/hr_attendance_excused_absent.sql has
 * neither 'pending' nor 'excused_absent', and writing one would either error or
 * truncate to '' depending on strict mode — so the pages warn instead.
 *
 * @return string[] empty when the column could not be read
 */
function hr_attendance_db_statuses(PDO $conn): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $type = $conn->query("
            SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'attendance'
               AND COLUMN_NAME = 'status'
             LIMIT 1
        ")->fetchColumn();
        if ($type === false || !preg_match_all("/'((?:[^']|'')*)'/", (string)$type, $m)) {
            return $cached = [];
        }
        return $cached = array_map(static fn($v) => str_replace("''", "'", $v), $m[1]);
    } catch (Throwable $e) {
        return $cached = [];
    }
}

/** Can this database store this status? False also when the enum is unreadable. */
function hr_attendance_status_supported(PDO $conn, string $status): bool {
    return in_array($status, hr_attendance_db_statuses($conn), true);
}

/** The statuses added by the migration, and whether each one is live yet. */
function hr_attendance_missing_statuses(PDO $conn): array {
    $missing = [];
    foreach (['pending', 'excused_absent'] as $s) {
        if (!hr_attendance_status_supported($conn, $s)) {
            $missing[] = hr_attendance_status_label($s);
        }
    }
    return $missing;
}

/** Kept for callers that only ask about Excused Absent. */
function hr_attendance_excused_supported(PDO $conn): bool {
    return hr_attendance_status_supported($conn, 'excused_absent');
}

/* ----------------------------------------------------------- write / read */

/**
 * Record one status change. Returns the new change id, or 0 if the log could
 * not be written (the status change itself is not rolled back for that).
 */
function hr_attendance_log_status_change(
    PDO $conn,
    int $attendanceId,
    ?string $fromStatus,
    string $toStatus,
    string $note,
    ?string $prevNotes,
    ?int $userId
): int {
    if ($attendanceId <= 0 || !hr_attendance_tables_ready($conn)) return 0;
    try {
        $ins = $conn->prepare("
            INSERT INTO attendance_status_changes
            (attendance_id, from_status, to_status, note, prev_notes, changed_by)
            VALUES (?,?,?,?,?,?)
        ");
        $ins->execute([
            $attendanceId,
            $fromStatus,
            $toStatus,
            mb_substr($note, 0, HR_ATTENDANCE_NOTE_MAX),
            $prevNotes !== null ? mb_substr($prevNotes, 0, HR_ATTENDANCE_NOTE_MAX) : null,
            $userId,
        ]);
        return (int)$conn->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Validate and store one $_FILES entry (the multi-file "name[]" shape) against
 * an attendance row that already exists. Per-file problems go into $errors and
 * do not stop the other files.
 *
 * @return int number of files saved
 */
function hr_attendance_attachments_save(
    PDO $conn,
    int $attendanceId,
    int $changeId,
    ?int $userId,
    array $files,
    array &$errors
): int {
    if ($attendanceId <= 0 || empty($files['name']) || !is_array($files['name'])) {
        return 0;
    }
    if (!hr_attendance_tables_ready($conn)) {
        $errors[] = 'Attachment storage is unavailable (attendance_attachments could not be created).';
        return 0;
    }
    if (!hr_attendance_attach_dir_ready()) {
        $errors[] = 'Could not create the attendance attachments folder.';
        return 0;
    }

    $allowed = hr_attendance_attach_allowed_extensions();
    $ins = $conn->prepare("
        INSERT INTO attendance_attachments
        (attendance_id, change_id, file_name, file_path, mime_type, file_size, uploaded_by)
        VALUES (?,?,?,?,?,?,?)
    ");
    $saved = 0;

    foreach ($files['name'] as $i => $name) {
        if ($name === '' || $name === null) continue;

        $errCode = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($errCode === UPLOAD_ERR_NO_FILE) continue;
        if ($errCode !== UPLOAD_ERR_OK) {
            $errors[] = sprintf('"%s" was not uploaded (PHP upload error %d).', $name, $errCode);
            continue;
        }
        if ($saved >= HR_ATTENDANCE_ATTACH_MAX_FILES) {
            $errors[] = sprintf('"%s" was skipped — at most %d files per change.', $name, HR_ATTENDANCE_ATTACH_MAX_FILES);
            continue;
        }

        $size = (int)($files['size'][$i] ?? 0);
        if ($size <= 0) {
            $errors[] = sprintf('"%s" is empty and was skipped.', $name);
            continue;
        }
        if ($size > HR_ATTENDANCE_ATTACH_MAX_BYTES) {
            $errors[] = sprintf('"%s" exceeds the %d MB limit.', $name, (int)(HR_ATTENDANCE_ATTACH_MAX_BYTES / 1024 / 1024));
            continue;
        }

        $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $errors[] = sprintf('"%s" has an unsupported file type (.%s).', $name, $ext);
            continue;
        }

        $stored = 'att_' . $attendanceId . '_' . time() . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = HR_ATTENDANCE_ATTACH_DIR . '/' . $stored;
        if (!move_uploaded_file($files['tmp_name'][$i], $target)) {
            $errors[] = sprintf('"%s" could not be saved to disk.', $name);
            continue;
        }

        // Never trust $files['type'][$i]: the uploader controls it. Read the bytes.
        $detectedMime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detectedMime = finfo_file($finfo, $target) ?: null;
                finfo_close($finfo);
            }
        }

        try {
            $ins->execute([
                $attendanceId,
                $changeId > 0 ? $changeId : null,
                mb_substr((string)$name, 0, 255),
                HR_ATTENDANCE_ATTACH_REL . '/' . $stored,
                $detectedMime,
                $size,
                $userId,
            ]);
            $saved++;
        } catch (Throwable $e) {
            @unlink($target);
            $errors[] = sprintf('"%s" could not be recorded in the database.', $name);
        }
    }

    return $saved;
}

/** Attachments for one attendance row, newest first. */
function hr_attendance_attachments_for(PDO $conn, int $attendanceId): array {
    if ($attendanceId <= 0 || !hr_attendance_tables_ready($conn)) return [];
    try {
        $stmt = $conn->prepare("
            SELECT * FROM attendance_attachments
             WHERE attendance_id = ?
             ORDER BY uploaded_at DESC, id DESC
        ");
        $stmt->execute([$attendanceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Attachments for a page of rows in one query, keyed by attendance_id. The list
 * page renders up to 500 rows, so this must not be a query per row.
 *
 * @return array<int, array<int, array>>
 */
function hr_attendance_attachments_map(PDO $conn, array $attendanceIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $attendanceIds))));
    if (!$ids || !hr_attendance_tables_ready($conn)) return [];
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("
            SELECT id, attendance_id, file_name, file_size
              FROM attendance_attachments
             WHERE attendance_id IN ($in)
             ORDER BY uploaded_at DESC, id DESC
        ");
        $stmt->execute($ids);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int)$r['attendance_id']][] = $r;
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

/** Status history for one attendance row, newest first. */
function hr_attendance_status_history(PDO $conn, int $attendanceId): array {
    if ($attendanceId <= 0 || !hr_attendance_tables_ready($conn)) return [];
    try {
        $stmt = $conn->prepare("
            SELECT c.*, u.username AS changed_by_name
              FROM attendance_status_changes c
              LEFT JOIN user u ON u.id = c.changed_by
             WHERE c.attendance_id = ?
             ORDER BY c.changed_at DESC, c.id DESC
        ");
        $stmt->execute([$attendanceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Remove one attachment (row + file on disk). */
function hr_attendance_attachment_delete(PDO $conn, int $attachmentId): bool {
    if ($attachmentId <= 0 || !hr_attendance_tables_ready($conn)) return false;
    try {
        $stmt = $conn->prepare("SELECT file_path FROM attendance_attachments WHERE id = ?");
        $stmt->execute([$attachmentId]);
        $path = $stmt->fetchColumn();
        if ($path === false) return false;
        $conn->prepare("DELETE FROM attendance_attachments WHERE id = ?")->execute([$attachmentId]);
        hr_attendance_unlink_stored((string)$path);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Delete the files behind an attendance row. The rows themselves go with the
 * FK cascade (or are removed here when the migration's FK is absent).
 */
function hr_attendance_attachments_purge(PDO $conn, int $attendanceId): void {
    if ($attendanceId <= 0 || !hr_attendance_tables_ready($conn)) return;
    try {
        $stmt = $conn->prepare("SELECT file_path FROM attendance_attachments WHERE attendance_id = ?");
        $stmt->execute([$attendanceId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $p) {
            hr_attendance_unlink_stored((string)$p);
        }
        $conn->prepare("DELETE FROM attendance_attachments WHERE attendance_id = ?")->execute([$attendanceId]);
        $conn->prepare("DELETE FROM attendance_status_changes WHERE attendance_id = ?")->execute([$attendanceId]);
    } catch (Throwable $e) {
        // Leave the files; a stale file is better than a half-deleted record.
    }
}

/** Unlink a stored path, but only ever from under uploads/. */
function hr_attendance_unlink_stored(string $relPath): void {
    $projectRoot = realpath(dirname(__DIR__));
    $real = realpath($projectRoot . '/' . ltrim($relPath, '/'));
    $uploadsRoot = realpath($projectRoot . '/uploads');
    if ($real && $uploadsRoot && strpos($real, $uploadsRoot) === 0 && is_file($real)) {
        @unlink($real);
    }
}
