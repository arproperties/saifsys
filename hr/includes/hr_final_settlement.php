<?php
/**
 * Final settlement sheets (added 2026-11-13).
 *
 * HR types every value; the system only adds up the totals. Saving keeps the
 * sheet in hr_final_settlements so it can be edited and printed again. It does
 * NOT touch payroll, accounts, employee documents, fines or the employee's
 * final_settlement_status: the user records those by hand.
 */

function hr_fs_table_ready(PDO $conn): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool)$conn->query("SHOW TABLES LIKE 'hr_final_settlements'")->fetchColumn();
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** Header fields, in print order: key => label. */
function hr_fs_header_fields(): array
{
    return [
        'full_name'        => 'Employee',
        'employee_code'    => 'Employee code',
        'position'         => 'Position',
        'passport_no'      => 'Passport no.',
        'joining_date'     => 'Joining date',
        'emirates_id'      => 'Emirates ID',
        'last_working_day' => 'Last working day',
        'monthly_salary'   => 'Monthly salary',
    ];
}

function hr_fs_payment_methods(): array
{
    return ['' => 'Not decided (print tick boxes)', 'wps' => 'WPS / bank transfer', 'cheque' => 'Cheque', 'cash' => 'Cash'];
}

function hr_fs_fmt_date(?string $d): string
{
    $d = trim((string)$d);
    if ($d === '' || $d < '1900-01-01') {
        return '';
    }
    $t = strtotime($d);
    return $t ? date('j M Y', $t) : $d;
}

/** Header values suggested from the employee profile for a new sheet. */
function hr_fs_header_from_employee(array $emp): array
{
    $basic = (float)($emp['basic_salary'] ?? 0);
    $allow = (float)($emp['allowance'] ?? 0);
    $total = (float)($emp['total_salary'] ?? 0) ?: $basic + $allow;
    $salary = '';
    if ($total > 0) {
        $salary = 'AED ' . number_format($total, 2);
        if ($basic > 0 && $allow > 0) {
            $salary .= ' (basic ' . number_format($basic, 2) . ' + allowance ' . number_format($allow, 2) . ')';
        }
    }
    return [
        'full_name'        => (string)($emp['full_name'] ?? ''),
        'employee_code'    => (string)($emp['employee_code'] ?? ''),
        'position'         => (string)($emp['position_title'] ?? ''),
        'passport_no'      => (string)($emp['pass_num'] ?? ''),
        'joining_date'     => hr_fs_fmt_date($emp['date_joined'] ?? ''),
        'emirates_id'      => '',
        'last_working_day' => hr_fs_fmt_date(($emp['last_working_day'] ?? '') ?: ($emp['exit_date'] ?? '')),
        'monthly_salary'   => $salary,
    ];
}

/** Clean posted rows: [{item, details, amount}], blank rows dropped. */
function hr_fs_rows_from_post(string $prefix): array
{
    $items = (array)($_POST[$prefix . '_item'] ?? []);
    $details = (array)($_POST[$prefix . '_details'] ?? []);
    $amounts = (array)($_POST[$prefix . '_amount'] ?? []);
    $rows = [];
    foreach ($items as $i => $item) {
        $item = trim((string)$item);
        $det = trim((string)($details[$i] ?? ''));
        $raw = str_replace([',', ' '], '', (string)($amounts[$i] ?? ''));
        if ($item === '' && $det === '' && $raw === '') {
            continue;
        }
        if ($raw !== '' && !is_numeric($raw)) {
            throw new RuntimeException('Amount "' . ($amounts[$i] ?? '') . '" is not a number.');
        }
        if ($item === '') {
            throw new RuntimeException('Every row with an amount needs an item name.');
        }
        $rows[] = [
            'item' => mb_substr($item, 0, 200),
            'details' => mb_substr($det, 0, 400),
            'amount' => round((float)$raw, 2),
        ];
    }
    return $rows;
}

function hr_fs_sum(array $rows): float
{
    return round(array_sum(array_map(static fn($r) => (float)$r['amount'], $rows)), 2);
}

function hr_fs_load(PDO $conn, int $id): ?array
{
    $st = $conn->prepare("SELECT * FROM hr_final_settlements WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return null;
    }
    $r['header'] = json_decode((string)$r['header_json'], true) ?: [];
    $r['due'] = json_decode((string)$r['due_json'], true) ?: [];
    $r['deductions'] = json_decode((string)$r['deduction_json'], true) ?: [];
    $r['files'] = json_decode((string)($r['files_json'] ?? ''), true) ?: [];
    return $r;
}

/** Company name / address / TRN / logo for the letter heading. */
function hr_fs_company(PDO $conn, int $companyId): array
{
    $out = ['name' => '', 'address' => '', 'phone' => '', 'email' => '', 'trn' => '', 'logo' => ''];
    if ($companyId <= 0) {
        return $out;
    }
    $st = $conn->prepare("SELECT name FROM companies WHERE id = ?");
    $st->execute([$companyId]);
    $out['name'] = (string)$st->fetchColumn();
    try {
        $st = $conn->prepare("SELECT * FROM company_settings WHERE company_id = ? ORDER BY (address_line1 IS NOT NULL) DESC, id LIMIT 1");
        $st->execute([$companyId]);
        if ($cs = $st->fetch(PDO::FETCH_ASSOC)) {
            $out['name'] = trim((string)$cs['legal_name']) ?: $out['name'];
            $out['address'] = trim((string)($cs['address_line1'] ?? ''));
            $out['phone'] = trim((string)($cs['phone'] ?? ''));
            $out['email'] = trim((string)($cs['email'] ?? ''));
            $out['trn'] = trim((string)($cs['trn'] ?? ''));
            $logo = trim((string)($cs['logo_path'] ?? ''));
            $abs = dirname(__DIR__, 2) . '/' . ltrim($logo, '/');
            if ($logo !== '' && is_file($abs)) {
                $out['logo'] = $abs;
            }
        }
    } catch (Throwable $e) {
        // company_settings missing: name only
    }
    return $out;
}

// ---------------------------------------------------------------- final settlement paid (added 2026-11-25)
// Amount paid, date received (paid_date) and the attached documents, saved with the sheet.
// Files go in uploads/hr_final_settlement/{employee id}/, served only by final_settlement_file.php.

function hr_fs_paid_ready(PDO $conn): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool)$conn->query("SHOW COLUMNS FROM hr_final_settlements LIKE 'completed_at'")->fetchColumn();
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

const HR_FS_MAX_FILES = 10;      // per sheet
const HR_FS_MAX_BYTES = 10485760; // 10 MB per file

/** Folder for an employee's settlement documents. Not served directly: final_settlement_file.php serves them. */
function hr_fs_upload_dir(int $employeeId): string
{
    $base = dirname(__DIR__, 2) . '/uploads/hr_final_settlement';
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
    return $dir;
}

/**
 * Save the files posted under $field (multiple). Returns the new relative paths.
 * Checks every file first, so a bad one saves none.
 */
function hr_fs_save_files(string $field, int $employeeId, int $room): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f || !is_array($f['name'])) {
        return [];
    }
    $picked = [];
    foreach ($f['name'] as $i => $name) {
        if ((int)$f['error'][$i] === UPLOAD_ERR_NO_FILE || $name === '') {
            continue;
        }
        if ((int)$f['error'][$i] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('"' . $name . '" did not upload (too big?).');
        }
        $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new RuntimeException('"' . $name . '": only PDF or an image (JPG, PNG, WEBP).');
        }
        if ((int)$f['size'][$i] > HR_FS_MAX_BYTES) {
            throw new RuntimeException('"' . $name . '" is over 10 MB.');
        }
        $picked[] = [$f['tmp_name'][$i], $ext];
    }
    if (count($picked) > $room) {
        throw new RuntimeException('At most ' . HR_FS_MAX_FILES . ' files per sheet.');
    }
    $dir = hr_fs_upload_dir($employeeId);
    $out = [];
    foreach ($picked as [$tmp, $ext]) {
        $fname = 'fsp_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . '/' . $fname)) {
            throw new RuntimeException('Upload failed.');
        }
        $out[] = 'uploads/hr_final_settlement/' . $employeeId . '/' . $fname;
    }
    return $out;
}

/** Absolute path of a stored file, only when it is inside uploads/hr_final_settlement. */
function hr_fs_file_abs(string $rel): ?string
{
    $base = realpath(dirname(__DIR__, 2) . '/uploads/hr_final_settlement');
    $abs = realpath(dirname(__DIR__, 2) . '/' . ltrim($rel, '/'));
    return ($base && $abs && strpos($abs, $base . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) ? $abs : null;
}

/** Letterhead PDF for a company, if we have one (only Heroes Zone so far). */
function hr_fs_letterhead(int $companyId): ?string
{
    $f = __DIR__ . '/final_settlement/letterhead_company_' . $companyId . '.pdf';
    return is_file($f) ? $f : null;
}
