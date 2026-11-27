<?php
/**
 * Optional attachment on an employee bonus or deduction (added 2026-11-27).
 *
 * One file per row, kept in attachment_path on employee_bonuses / employee_deductions.
 * Files go in uploads/hr_bonus_deduction/{employee id}/ and are served only by
 * hr/adjustment_file.php (the folder itself is closed to direct links).
 * Needs migrations/hr_bonus_deduction_attachment.sql; until it is run, nothing changes.
 */

const HR_ADJ_MAX_BYTES = 10485760; // 10 MB

/** $kind => table. */
function hr_adj_table(string $kind): ?string
{
    return ['bonus' => 'employee_bonuses', 'deduction' => 'employee_deductions'][$kind] ?? null;
}

/** True when the attachment column is there for that kind ('bonus' or 'deduction'). */
function hr_adj_ready(PDO $conn, string $kind): bool
{
    static $ok = [];
    if (!isset($ok[$kind])) {
        $ok[$kind] = false;
        $table = hr_adj_table($kind);
        if ($table) {
            try {
                $ok[$kind] = (bool)$conn->query("SHOW COLUMNS FROM {$table} LIKE 'attachment_path'")->fetchColumn();
            } catch (Throwable $e) {
                $ok[$kind] = false;
            }
        }
    }
    return $ok[$kind];
}

/**
 * Save the file posted under $field, if there is one. Returns its relative path,
 * or null when no file was chosen. Throws RuntimeException for a file that is not allowed.
 */
function hr_adj_save_file(string $field, string $kind, int $employeeId): ?string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || is_array($f['name']) || (int)$f['error'] === UPLOAD_ERR_NO_FILE || (string)$f['name'] === '') {
        return null;
    }
    if ((int)$f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The attachment did not upload (too big?).');
    }
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
        throw new RuntimeException('Attachment: only PDF or an image (JPG, PNG, WEBP).');
    }
    if ((int)$f['size'] > HR_ADJ_MAX_BYTES) {
        throw new RuntimeException('The attachment is over 10 MB.');
    }
    $base = dirname(__DIR__, 2) . '/uploads/hr_bonus_deduction';
    if (!is_dir($base)) {
        @mkdir($base, 0775, true);
    }
    if (!file_exists($base . '/.htaccess')) {
        @file_put_contents($base . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    $dir = $base . '/' . $employeeId;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $fname = ($kind === 'bonus' ? 'bonus_' : 'ded_') . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) {
        throw new RuntimeException('The attachment could not be saved.');
    }
    return 'uploads/hr_bonus_deduction/' . $employeeId . '/' . $fname;
}

/** Absolute path of a stored attachment, only when it is inside uploads/hr_bonus_deduction. */
function hr_adj_file_abs(?string $rel): ?string
{
    $rel = trim((string)$rel);
    if ($rel === '') {
        return null;
    }
    $base = realpath(dirname(__DIR__, 2) . '/uploads/hr_bonus_deduction');
    $abs = realpath(dirname(__DIR__, 2) . '/' . ltrim($rel, '/'));
    return ($base && $abs && strpos($abs, $base . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) ? $abs : null;
}

/** Remove a stored attachment file (used when its row is deleted or the file is replaced). */
function hr_adj_delete_file(?string $rel): void
{
    if ($abs = hr_adj_file_abs($rel)) {
        @unlink($abs);
    }
}
