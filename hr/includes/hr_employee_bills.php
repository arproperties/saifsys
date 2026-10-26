<?php
/**
 * Employee bills — an approved amount per period, and the excess over it
 * (HR > Employee > Bills).
 *
 * An item is set up once: an approved amount and a frequency — daily, weekly
 * (Monday–Sunday), monthly or yearly (January–December) — on an asset marked
 * "has bill" (a company SIM) or on an employee_bill_items row with no asset
 * (fuel). Every period is taken to be within the approved amount, so nothing is
 * stored for it. HR enters an actual only when it goes over; the excess becomes
 * a 'bill_excess' row in employee_deductions — the open balance payroll already
 * offers under Other deductions. Unused allowance is not carried forward.
 *
 * An entry's deduction follows the entry while nothing has been taken from it.
 * Once payroll or a cash settlement has reduced it, the entry is locked: the
 * money already deducted is not rewritten from here.
 *
 * employee_bills.bill_month holds the period's first day and period_end its
 * last (the column kept its name from the monthly-only first version).
 *
 * Evidence files go to uploads/hr_bills (denied to the web) and are served only
 * through hr/bill_file.php.
 *
 * Off until migrations/hr_employee_bills.sql has run.
 */

require_once __DIR__ . '/hr_loans.php';

const HR_BILLS_EVIDENCE_REL = 'uploads/hr_bills';
const HR_BILLS_EVIDENCE_MAX_BYTES = 5 * 1024 * 1024;

/** Whether the bill tables and the period columns exist. */
function hr_bills_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $stmt = $conn->query("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND ((TABLE_NAME = 'employee_assets' AND COLUMN_NAME = 'bill_frequency')
                OR (TABLE_NAME = 'employee_bill_items' AND COLUMN_NAME = 'frequency')
                OR (TABLE_NAME = 'employee_bills' AND COLUMN_NAME = 'period_end')
                OR (TABLE_NAME = 'employee_bills' AND COLUMN_NAME = 'approved_run_id'))
        ");
        $ready = ((int)$stmt->fetchColumn() === 4);
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/** Asset status as HR reads it. 'issued' is stored, "Active" is shown. */
function hr_asset_status_label(string $status): string
{
    $labels = ['issued' => 'Active', 'suspended' => 'Suspended', 'returned' => 'Returned', 'lost' => 'Lost'];
    return $labels[$status] ?? ucfirst($status);
}

/** Frequencies in the order they are offered. */
function hr_bills_frequencies(): array
{
    return ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'];
}

function hr_bills_frequency(string $value): string
{
    return array_key_exists($value, hr_bills_frequencies()) ? $value : 'monthly';
}

/** "per day", "per week"… for amounts. */
function hr_bills_per(string $frequency): string
{
    $per = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'];
    return 'per ' . ($per[$frequency] ?? 'month');
}

/**
 * The period a date falls in: [first day, last day] as Y-m-d.
 * Weekly runs Monday to Sunday, yearly January to December.
 */
function hr_bills_period(string $frequency, string $date): array
{
    $ts = strtotime($date);
    switch ($frequency) {
        case 'daily':
            return [date('Y-m-d', $ts), date('Y-m-d', $ts)];
        case 'weekly':
            $monday = strtotime('-' . ((int)date('N', $ts) - 1) . ' days', $ts);
            return [date('Y-m-d', $monday), date('Y-m-d', strtotime('+6 days', $monday))];
        case 'yearly':
            return [date('Y-01-01', $ts), date('Y-12-31', $ts)];
        default:
            return [date('Y-m-01', $ts), date('Y-m-t', $ts)];
    }
}

/** "24 Oct 2026", "19–25 Oct 2026", "28 Sep – 4 Oct 2026", "Oct 2026", "2026". */
function hr_bills_period_label(string $frequency, string $start, ?string $end = null): string
{
    $s = strtotime($start);
    $e = strtotime($end ?: hr_bills_period($frequency, $start)[1]);
    switch ($frequency) {
        case 'daily':
            return date('j M Y', $s);
        case 'weekly':
            if (date('Y', $s) !== date('Y', $e)) {
                return date('j M Y', $s) . ' – ' . date('j M Y', $e);
            }
            if (date('m', $s) !== date('m', $e)) {
                return date('j M', $s) . ' – ' . date('j M Y', $e);
            }
            return date('j', $s) . '–' . date('j M Y', $e);
        case 'yearly':
            return date('Y', $s);
        default:
            return date('M Y', $s);
    }
}

/** Read a period input: "2026-10-24", "2026-10" or "2026" → a Y-m-d inside it, or null. */
function hr_bills_parse_date(string $value): ?string
{
    $value = trim($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value)) {
        return date('Y-m-d', strtotime($value));
    }
    if (preg_match('/^(\d{4})-(\d{2})$/', $value, $m) && (int)$m[2] >= 1 && (int)$m[2] <= 12) {
        return $m[1] . '-' . $m[2] . '-01';
    }
    if (preg_match('/^\d{4}$/', $value)) {
        return $value . '-01-01';
    }
    return null;
}

/**
 * A month field that reads "Oct 2026" and opens a month/year calendar, the same
 * in every browser (Firefox has no native month picker and shows "2026-10").
 * Posts YYYY-MM under $name through a hidden input; the page's month-picker
 * script draws the calendar.
 */
function hr_bills_month_picker(string $name, string $value, string $max, bool $required = false): string
{
    $label = date('M Y', strtotime($value . '-01'));
    return '<div class="bill-month-picker position-relative" data-max="' . htmlspecialchars($max) . '">'
        . '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '">'
        . '<button type="button" class="form-control text-start d-flex align-items-center justify-content-between bmp-toggle"'
        . ($required ? ' data-required="1"' : '') . '>'
        . '<span class="bmp-label">' . htmlspecialchars($label) . '</span><i class="bi bi-calendar3 text-muted"></i></button>'
        . '<div class="bmp-pop card shadow-sm p-2 d-none" style="position:absolute;z-index:1060;top:100%;left:0;width:240px">'
        . '<div class="d-flex align-items-center justify-content-between mb-2">'
        . '<button type="button" class="btn btn-sm btn-light bmp-prev" aria-label="Previous year">&lsaquo;</button>'
        . '<strong class="bmp-year"></strong>'
        . '<button type="button" class="btn btn-sm btn-light bmp-next" aria-label="Next year">&rsaquo;</button>'
        . '</div><div class="bmp-grid d-grid gap-1" style="grid-template-columns:repeat(3,1fr)"></div></div></div>';
}

/**
 * The period field for an item's frequency, pre-set to the current period:
 * a date for daily/weekly, the month picker for monthly, a year list for yearly.
 * Posts under "period".
 */
function hr_bills_period_input(string $frequency, string $startDate): string
{
    $today = date('Y-m-d');
    if ($frequency === 'monthly') {
        return hr_bills_month_picker('period', date('Y-m'), date('Y-m'), true);
    }
    if ($frequency === 'yearly') {
        $html = '<select name="period" class="form-select form-select-sm" required>';
        $first = (int)date('Y', strtotime($startDate ?: $today));
        for ($y = (int)date('Y'); $y >= min($first, (int)date('Y')); $y--) {
            $html .= '<option value="' . $y . '">' . $y . '</option>';
        }
        return $html . '</select>';
    }
    return '<input type="date" name="period" value="' . $today . '" max="' . $today . '" class="form-control form-control-sm" required'
        . ' title="' . ($frequency === 'weekly' ? 'Any day in the week (Mon–Sun)' : 'The day') . '">';
}

/** What is above the approved amount; never negative (unused allowance is dropped). */
function hr_bills_excess(float $actual, float $approved): float
{
    return round(max(0.0, $actual - $approved), 2);
}

/**
 * The items an actual can be entered against: billable assets and items with
 * no asset, each with its current period and what has been entered for it.
 * Keys are "a:<asset id>" and "i:<item id>".
 */
function hr_bills_items(PDO $conn, int $employeeId): array
{
    $items = [];
    $st = $conn->prepare("
        SELECT id, item_name, serial_no, status, COALESCE(bill_approved, 0) AS approved, bill_frequency, issue_date
        FROM employee_assets
        WHERE employee_id = ? AND has_bill = 1
        ORDER BY item_name
    ");
    $st->execute([$employeeId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $name = (string)$r['item_name'] . ((string)$r['serial_no'] !== '' ? ' (' . $r['serial_no'] . ')' : '');
        $items['a:' . $r['id']] = [
            'key' => 'a:' . $r['id'], 'kind' => 'asset', 'id' => (int)$r['id'], 'name' => $name,
            'approved' => (float)$r['approved'], 'frequency' => hr_bills_frequency((string)$r['bill_frequency']),
            'start_date' => (string)$r['issue_date'],
            'active' => $r['status'] === 'issued', 'status' => hr_asset_status_label((string)$r['status']),
        ];
    }
    $st = $conn->prepare("SELECT id, item_name, approved_amount, frequency, start_date, status, created_at FROM employee_bill_items WHERE employee_id = ? ORDER BY item_name");
    $st->execute([$employeeId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items['i:' . $r['id']] = [
            'key' => 'i:' . $r['id'], 'kind' => 'item', 'id' => (int)$r['id'], 'name' => (string)$r['item_name'],
            'approved' => (float)$r['approved_amount'], 'frequency' => hr_bills_frequency((string)$r['frequency']),
            'start_date' => (string)($r['start_date'] ?: substr((string)$r['created_at'], 0, 10)),
            'active' => $r['status'] === 'active', 'status' => ucfirst((string)$r['status']),
        ];
    }

    // The current period of each item, and the excess entered for it, if any.
    $find = $conn->prepare("SELECT excess_amount FROM employee_bills WHERE employee_id = ? AND " .
        "(CASE WHEN ? = 'a' THEN asset_id ELSE bill_item_id END) = ? AND frequency = ? AND bill_month = ? LIMIT 1");
    foreach ($items as &$it) {
        [$ps, $pe] = hr_bills_period($it['frequency'], date('Y-m-d'));
        $it['period_start'] = $ps;
        $it['period_label'] = hr_bills_period_label($it['frequency'], $ps, $pe);
        $find->execute([$employeeId, $it['kind'] === 'asset' ? 'a' : 'i', $it['id'], $it['frequency'], $ps]);
        $ex = $find->fetchColumn();
        $it['period_excess'] = $ex === false ? null : (float)$ex;
    }
    return $items;
}

/** The entries for an employee, newest period first, with their deduction's state. */
function hr_bills_list(PDO $conn, int $employeeId): array
{
    $remainingSql = hr_bills_deduction_has_remaining($conn)
        ? "COALESCE(d.remaining_balance, d.amount) AS ded_remaining, d.settle_status AS ded_status"
        : "d.amount AS ded_remaining, 'open' AS ded_status";
    $st = $conn->prepare("
        SELECT b.*, d.amount AS ded_amount, {$remainingSql}
        FROM employee_bills b
        LEFT JOIN employee_deductions d ON d.id = b.deduction_id
        WHERE b.employee_id = ?
        ORDER BY b.bill_month DESC, b.item_name, b.id DESC
    ");
    $st->execute([$employeeId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['period_label'] = hr_bills_period_label((string)$r['frequency'], (string)$r['bill_month'], $r['period_end'] ?: null);
        $r['approved'] = ($r['status'] ?? 'pending') === 'approved';
        $r['taken'] = $r['deduction_id'] ? hr_bills_deduction_touched($conn, (int)$r['deduction_id']) : false;
        $r['locked'] = $r['approved'] || $r['taken'];
    }
    return $rows;
}

/** Older databases have no remaining_balance / settle_status on employee_deductions. */
function hr_bills_deduction_has_remaining(PDO $conn): bool
{
    static $has = null;
    if ($has === null) {
        try {
            $chk = $conn->query("SHOW COLUMNS FROM employee_deductions LIKE 'remaining_balance'");
            $has = (bool)($chk && $chk->fetch(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/** Whether payroll or a cash settlement has already taken anything from a deduction. */
function hr_bills_deduction_touched(PDO $conn, int $deductionId): bool
{
    if (hr_bills_deduction_has_remaining($conn)) {
        $st = $conn->prepare("SELECT amount, COALESCE(remaining_balance, amount) AS remaining, settle_status FROM employee_deductions WHERE id = ?");
        $st->execute([$deductionId]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d) {
            return false;
        }
        if (($d['settle_status'] ?? 'open') !== 'open' || (float)$d['remaining'] < (float)$d['amount'] - 0.005) {
            return true;
        }
    }
    if (hr_deduction_settlements_table_ready($conn)) {
        $s = $conn->prepare("SELECT COUNT(*) FROM hr_deduction_settlements WHERE deduction_id = ?");
        $s->execute([$deductionId]);
        if ((int)$s->fetchColumn() > 0) {
            return true;
        }
    }
    return false;
}

/** Note written on the deduction, so it reads on its own in the Deductions tab and payroll. */
function hr_bills_deduction_note(string $itemName, string $periodLabel, float $actual, float $approved): string
{
    return mb_substr('Bill excess: ' . $itemName . ', ' . $periodLabel
        . ' — spend ' . number_format($actual, 2) . ', approved ' . number_format($approved, 2), 0, 255);
}

/**
 * Create the excess deduction; returns its id. Dated the period's last day, or
 * today while the period is still running.
 */
function hr_bills_deduction_create(PDO $conn, int $employeeId, string $periodEnd, float $excess, string $note, ?int $userId): int
{
    $txDate = min($periodEnd, date('Y-m-d'));
    if (hr_bills_deduction_has_remaining($conn)) {
        $conn->prepare("
            INSERT INTO employee_deductions
              (employee_id, tx_date, amount, remaining_balance, preferred_settle_method, settle_status, dtype, notes, created_by)
            VALUES (?, ?, ?, ?, 'payroll', 'open', 'bill_excess', ?, ?)
        ")->execute([$employeeId, $txDate, $excess, $excess, $note, $userId]);
    } else {
        $conn->prepare("
            INSERT INTO employee_deductions (employee_id, tx_date, amount, dtype, notes, created_by)
            VALUES (?, ?, ?, 'bill_excess', ?, ?)
        ")->execute([$employeeId, $txDate, $excess, $note, $userId]);
    }
    return (int)$conn->lastInsertId();
}

/**
 * Save an uploaded evidence file. Returns [path, original name], [null, null]
 * when no file was chosen, or throws with a message HR can act on.
 */
function hr_bills_evidence_save(?array $file, int $employeeId): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || (string)($file['name'] ?? '') === '') {
        return [null, null];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The evidence file was not uploaded (upload error ' . (int)$file['error'] . ').');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('The evidence file is empty.');
    }
    if ($size > HR_BILLS_EVIDENCE_MAX_BYTES) {
        throw new RuntimeException('The evidence file is larger than 5 MB.');
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
        throw new RuntimeException('Evidence must be a PDF or an image (JPG, PNG, WEBP).');
    }

    $dir = dirname(__DIR__, 2) . '/' . HR_BILLS_EVIDENCE_REL;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the evidence folder.');
    }
    if (!file_exists($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    $stored = 'bill_' . $employeeId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $stored)) {
        throw new RuntimeException('The evidence file could not be saved.');
    }
    return [HR_BILLS_EVIDENCE_REL . '/' . $stored, mb_substr((string)$file['name'], 0, 255)];
}

/** Remove a stored evidence file; only ever under uploads/hr_bills. */
function hr_bills_evidence_delete(?string $path): void
{
    if (!$path || strpos($path, HR_BILLS_EVIDENCE_REL . '/') !== 0) {
        return;
    }
    $full = dirname(__DIR__, 2) . '/' . $path;
    if (is_file($full)) {
        @unlink($full);
    }
}

/**
 * Enter the actual for an item's period. Only an amount over the approved one
 * is stored (with its deduction); within it, nothing is kept.
 *
 * @param string $periodInput a date, "YYYY-MM" or "YYYY" inside the period; '' = today
 * @return array{ok:bool, message:string, bill_id?:?int, excess?:float, deduction_id?:?int}
 */
function hr_bills_create(PDO $conn, int $employeeId, string $itemKey, string $periodInput, $actualAmount, string $notes, ?array $file, ?int $userId): array
{
    $items = hr_bills_items($conn, $employeeId);
    $item = $items[$itemKey] ?? null;
    if (!$item) {
        return ['ok' => false, 'message' => 'Choose an item.'];
    }
    if (!$item['active']) {
        return ['ok' => false, 'message' => $item['name'] . ' is ' . strtolower($item['status']) . ' — spend amounts can only be entered for active items.'];
    }
    $date = $periodInput === '' ? date('Y-m-d') : hr_bills_parse_date($periodInput);
    if (!$date) {
        return ['ok' => false, 'message' => 'Choose the period.'];
    }
    [$ps, $pe] = hr_bills_period($item['frequency'], $date);
    $label = hr_bills_period_label($item['frequency'], $ps, $pe);
    if ($ps > date('Y-m-d')) {
        return ['ok' => false, 'message' => 'A spend amount cannot be entered for a future period.'];
    }
    if ($item['start_date'] !== '' && $pe < $item['start_date']) {
        return ['ok' => false, 'message' => $item['name'] . ' starts on ' . date('j M Y', strtotime($item['start_date'])) . ', after ' . $label . '.'];
    }
    if (!is_numeric($actualAmount) || (float)$actualAmount < 0) {
        return ['ok' => false, 'message' => 'Enter the spend amount (0 or more).'];
    }
    $actual = round((float)$actualAmount, 2);
    $approved = round($item['approved'], 2);
    $excess = hr_bills_excess($actual, $approved);

    $col = $item['kind'] === 'asset' ? 'asset_id' : 'bill_item_id';
    $dup = $conn->prepare("SELECT COUNT(*) FROM employee_bills WHERE {$col} = ? AND frequency = ? AND bill_month = ?");
    $dup->execute([$item['id'], $item['frequency'], $ps]);
    if ((int)$dup->fetchColumn() > 0) {
        return ['ok' => false, 'message' => $item['name'] . ' already has an entry for ' . $label . '. Edit that one instead.'];
    }
    if ($excess <= 0) {
        return ['ok' => true, 'message' => $item['name'] . ', ' . $label . ': ' . number_format($actual, 2)
            . ' is within the approved ' . number_format($approved, 2) . ' — nothing deducted, nothing stored.',
            'bill_id' => null, 'excess' => 0.0, 'deduction_id' => null];
    }

    try {
        [$path, $origName] = hr_bills_evidence_save($file, $employeeId);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }

    $conn->beginTransaction();
    try {
        $dedId = hr_bills_deduction_create($conn, $employeeId, $pe, $excess,
            hr_bills_deduction_note($item['name'], $label, $actual, $approved), $userId);
        $conn->prepare("
            INSERT INTO employee_bills
              (employee_id, asset_id, bill_item_id, item_name, frequency, bill_month, period_end, approved_amount, bill_amount,
               excess_amount, deduction_id, evidence_path, evidence_name, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $employeeId,
            $item['kind'] === 'asset' ? $item['id'] : null,
            $item['kind'] === 'item' ? $item['id'] : null,
            mb_substr($item['name'], 0, 120), $item['frequency'], $ps, $pe,
            $approved, $actual, $excess, $dedId,
            $path, $origName, $notes !== '' ? mb_substr($notes, 0, 255) : null, $userId,
        ]);
        $billId = (int)$conn->lastInsertId();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        hr_bills_evidence_delete($path);
        error_log('hr_bills_create failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not save the entry. Please try again.'];
    }

    return ['ok' => true, 'message' => $item['name'] . ', ' . $label . ': ' . number_format($excess, 2)
        . ' over the approved ' . number_format($approved, 2) . ' added to Deductions.',
        'bill_id' => $billId, 'excess' => $excess, 'deduction_id' => $dedId];
}

/**
 * Change an entry's actual, notes or evidence. The deduction follows while it
 * is untouched; an actual brought back within the approved amount removes the
 * entry. Once anything has been taken from the deduction, only notes and
 * evidence may change.
 *
 * @return array{ok:bool, message:string, excess?:float, removed?:bool}
 */
function hr_bills_update(PDO $conn, int $employeeId, int $billId, $actualAmount, string $notes, ?array $file, bool $removeEvidence, ?int $userId): array
{
    $st = $conn->prepare("SELECT * FROM employee_bills WHERE id = ? AND employee_id = ?");
    $st->execute([$billId, $employeeId]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) {
        return ['ok' => false, 'message' => 'That entry was not found.'];
    }
    if (($b['status'] ?? 'pending') === 'approved') {
        return ['ok' => false, 'message' => 'This entry is approved' . ($b['approved_run_id'] ? ' (included in payroll run #' . (int)$b['approved_run_id'] . ')' : '') . ' and can no longer be changed.'];
    }
    if (!is_numeric($actualAmount) || (float)$actualAmount < 0) {
        return ['ok' => false, 'message' => 'Enter the spend amount (0 or more).'];
    }
    $label = hr_bills_period_label((string)$b['frequency'], (string)$b['bill_month'], $b['period_end'] ?: null);
    $actual = round((float)$actualAmount, 2);
    $approved = (float)$b['approved_amount'];
    $excess = hr_bills_excess($actual, $approved);
    $oldDedId = $b['deduction_id'] ? (int)$b['deduction_id'] : null;
    $amountChanged = abs($actual - (float)$b['bill_amount']) > 0.005;

    if ($amountChanged && $oldDedId && hr_bills_deduction_touched($conn, $oldDedId)) {
        return ['ok' => false, 'message' => 'Payroll or a cash settlement has already taken part of this entry\'s deduction, so the amount can no longer change. Notes and evidence can.'];
    }
    if ($amountChanged && $excess <= 0) {
        $del = hr_bills_delete($conn, $employeeId, $billId);
        if (!$del['ok']) {
            return $del;
        }
        return ['ok' => true, 'message' => $b['item_name'] . ', ' . $label . ': ' . number_format($actual, 2)
            . ' is within the approved ' . number_format($approved, 2) . ', so the entry and its deduction were removed.',
            'excess' => 0.0, 'removed' => true];
    }

    try {
        [$newPath, $newName] = hr_bills_evidence_save($file, $employeeId);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }

    $conn->beginTransaction();
    try {
        $dedId = $oldDedId;
        if ($amountChanged) {
            $note = hr_bills_deduction_note((string)$b['item_name'], $label, $actual, $approved);
            if ($oldDedId) {
                if (hr_bills_deduction_has_remaining($conn)) {
                    $conn->prepare("UPDATE employee_deductions SET amount = ?, remaining_balance = ?, notes = ? WHERE id = ?")
                        ->execute([$excess, $excess, $note, $oldDedId]);
                } else {
                    $conn->prepare("UPDATE employee_deductions SET amount = ?, notes = ? WHERE id = ?")
                        ->execute([$excess, $note, $oldDedId]);
                }
            } else {
                $dedId = hr_bills_deduction_create($conn, $employeeId, (string)($b['period_end'] ?: $b['bill_month']), $excess, $note, $userId);
            }
        }
        $path = $b['evidence_path'];
        $name = $b['evidence_name'];
        if ($newPath) {
            [$path, $name] = [$newPath, $newName];
        } elseif ($removeEvidence) {
            [$path, $name] = [null, null];
        }
        $conn->prepare("
            UPDATE employee_bills
            SET bill_amount = ?, excess_amount = ?, deduction_id = ?, notes = ?, evidence_path = ?, evidence_name = ?,
                updated_by = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$actual, $amountChanged ? $excess : (float)$b['excess_amount'], $dedId,
            $notes !== '' ? mb_substr($notes, 0, 255) : null, $path, $name, $userId, $billId]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        hr_bills_evidence_delete($newPath);
        error_log('hr_bills_update failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not update the entry. Please try again.'];
    }
    if ($newPath || $removeEvidence) {
        hr_bills_evidence_delete($b['evidence_path']);
    }

    $msg = 'Entry updated: ' . $b['item_name'] . ', ' . $label . '.';
    if ($amountChanged) {
        $msg .= ' Deduction is now ' . number_format($excess, 2) . '.';
    }
    return ['ok' => true, 'message' => $msg, 'excess' => $excess];
}

/**
 * Delete an entry and its untouched deduction.
 *
 * @return array{ok:bool, message:string, bill?:array}
 */
function hr_bills_delete(PDO $conn, int $employeeId, int $billId): array
{
    $st = $conn->prepare("SELECT * FROM employee_bills WHERE id = ? AND employee_id = ?");
    $st->execute([$billId, $employeeId]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) {
        return ['ok' => false, 'message' => 'That entry was not found.'];
    }
    if (($b['status'] ?? 'pending') === 'approved') {
        return ['ok' => false, 'message' => 'This entry is approved' . ($b['approved_run_id'] ? ' (included in payroll run #' . (int)$b['approved_run_id'] . ')' : '') . ' and cannot be deleted.'];
    }
    $dedId = $b['deduction_id'] ? (int)$b['deduction_id'] : null;
    if ($dedId && hr_bills_deduction_touched($conn, $dedId)) {
        return ['ok' => false, 'message' => 'Payroll or a cash settlement has already taken part of this entry\'s deduction, so it cannot be deleted.'];
    }
    $conn->beginTransaction();
    try {
        $conn->prepare("DELETE FROM employee_bills WHERE id = ?")->execute([$billId]);
        if ($dedId) {
            $conn->prepare("DELETE FROM employee_deductions WHERE id = ?")->execute([$dedId]);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        error_log('hr_bills_delete failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not delete the entry. Please try again.'];
    }
    hr_bills_evidence_delete($b['evidence_path']);
    $b['period_label'] = hr_bills_period_label((string)$b['frequency'], (string)$b['bill_month'], $b['period_end'] ?: null);
    return ['ok' => true, 'message' => 'Entry deleted' . ($dedId ? ', and its deduction removed.' : '.'), 'bill' => $b];
}

/**
 * HR approves an entry. From then on it cannot be edited or deleted.
 *
 * @return array{ok:bool, message:string}
 */
function hr_bills_approve(PDO $conn, int $employeeId, int $billId, ?int $userId): array
{
    $st = $conn->prepare("SELECT * FROM employee_bills WHERE id = ? AND employee_id = ?");
    $st->execute([$billId, $employeeId]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) {
        return ['ok' => false, 'message' => 'That entry was not found.'];
    }
    if (($b['status'] ?? 'pending') === 'approved') {
        return ['ok' => false, 'message' => 'This entry is already approved.'];
    }
    $conn->prepare("UPDATE employee_bills SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'")
        ->execute([$userId, $billId]);
    $label = hr_bills_period_label((string)$b['frequency'], (string)$b['bill_month'], $b['period_end'] ?: null);
    return ['ok' => true, 'message' => 'Approved: ' . $b['item_name'] . ', ' . $label . ' — excess ' . number_format((float)$b['excess_amount'], 2) . '. It can no longer be edited or deleted.'];
}

/**
 * A payroll run includes $billPart of an employee's open bill excess (Save
 * Draft or Post): approve the entries that amount reaches, in the order posting
 * settles them (oldest first), so a bill in a payroll can no longer change
 * under it. Runs inside the payroll transaction; returns the ids approved.
 *
 * @return int[]
 */
function hr_bills_approve_for_payroll(PDO $conn, int $employeeId, float $billPart, string $periodTo, int $runId, ?int $userId): array
{
    if ($billPart <= 0.005) {
        return [];
    }
    $remainingSql = hr_bills_deduction_has_remaining($conn) ? 'COALESCE(d.remaining_balance, d.amount)' : 'd.amount';
    $st = $conn->prepare("
        SELECT b.id, b.status, {$remainingSql} AS open_amount
        FROM employee_bills b
        JOIN employee_deductions d ON d.id = b.deduction_id
        WHERE b.employee_id = ? AND d.dtype = 'bill_excess' AND d.tx_date <= ?
          AND COALESCE(d.settle_status, 'open') = 'open' AND {$remainingSql} > 0
        ORDER BY d.tx_date ASC, d.id ASC
    ");
    $st->execute([$employeeId, $periodTo]);
    $approve = $conn->prepare("UPDATE employee_bills SET status = 'approved', approved_by = ?, approved_at = NOW(), approved_run_id = ? WHERE id = ? AND status = 'pending'");
    $left = $billPart;
    $ids = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($left <= 0.005) {
            break;
        }
        if ($r['status'] !== 'approved') {
            $approve->execute([$userId, $runId, (int)$r['id']]);
            $ids[] = (int)$r['id'];
        }
        $left -= (float)$r['open_amount'];
    }
    return $ids;
}
