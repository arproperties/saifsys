<?php
/**
 * Optional attachments on an employee bonus or deduction (added 2026-11-27).
 *
 * Several files per row, kept as a JSON list in attachments_json on
 * employee_bonuses / employee_deductions. Files go in
 * uploads/hr_bonus_deduction/{employee id}/ and are served only by
 * hr/adjustment_file.php (the folder itself is closed to direct links).
 * Needs migrations/hr_bonus_deduction_attachment.sql; until it is run, nothing changes.
 */

const HR_ADJ_MAX_FILES = 10;       // per bonus / deduction
const HR_ADJ_MAX_BYTES = 10485760; // 10 MB per file

/** $kind => table. */
function hr_adj_table(string $kind): ?string
{
    return ['bonus' => 'employee_bonuses', 'deduction' => 'employee_deductions'][$kind] ?? null;
}

/** True when the attachments column is there for that kind ('bonus' or 'deduction'). */
function hr_adj_ready(PDO $conn, string $kind): bool
{
    static $ok = [];
    if (!isset($ok[$kind])) {
        $ok[$kind] = false;
        $table = hr_adj_table($kind);
        if ($table) {
            try {
                $ok[$kind] = (bool)$conn->query("SHOW COLUMNS FROM {$table} LIKE 'attachments_json'")->fetchColumn();
            } catch (Throwable $e) {
                $ok[$kind] = false;
            }
        }
    }
    return $ok[$kind];
}

/** The stored list (attachments_json) as an array of relative paths. */
function hr_adj_files($json): array
{
    $list = json_decode((string)$json, true);
    return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
}

/**
 * Save the files posted under $field (one or several). Returns the new relative paths,
 * empty when none was chosen. Checks every file first, so a bad one saves none.
 * $room = how many more files this row may take.
 */
function hr_adj_save_files(string $field, string $kind, int $employeeId, int $room): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f) {
        return [];
    }
    $names = (array)$f['name'];
    $errors = (array)$f['error'];
    $sizes = (array)$f['size'];
    $tmps = (array)$f['tmp_name'];
    $picked = [];
    foreach ($names as $i => $name) {
        if ((int)$errors[$i] === UPLOAD_ERR_NO_FILE || (string)$name === '') {
            continue;
        }
        if ((int)$errors[$i] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('"' . $name . '" did not upload (too big?).');
        }
        $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new RuntimeException('"' . $name . '": only PDF or an image (JPG, PNG, WEBP).');
        }
        if ((int)$sizes[$i] > HR_ADJ_MAX_BYTES) {
            throw new RuntimeException('"' . $name . '" is over 10 MB.');
        }
        $picked[] = [$tmps[$i], $ext];
    }
    if (!$picked) {
        return [];
    }
    if (count($picked) > $room) {
        throw new RuntimeException('At most ' . HR_ADJ_MAX_FILES . ' attachments on one entry.');
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
    $out = [];
    foreach ($picked as [$tmp, $ext]) {
        $fname = ($kind === 'bonus' ? 'bonus_' : 'ded_') . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . '/' . $fname)) {
            hr_adj_delete_files($out); // remove the ones already stored in this save
            throw new RuntimeException('The attachment could not be saved.');
        }
        $out[] = 'uploads/hr_bonus_deduction/' . $employeeId . '/' . $fname;
    }
    return $out;
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

/**
 * Remove stored attachment files: pass the row's attachments_json, or a list of paths.
 * Used when a row is deleted or one attachment is removed.
 */
function hr_adj_delete_files($filesOrJson): void
{
    foreach (is_array($filesOrJson) ? $filesOrJson : hr_adj_files($filesOrJson) as $rel) {
        if ($abs = hr_adj_file_abs($rel)) {
            @unlink($abs);
        }
    }
}
