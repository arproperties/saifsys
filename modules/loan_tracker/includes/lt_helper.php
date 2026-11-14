<?php
/**
 * Loan Tracker — shared helpers.
 * The data is saifsys's own (lt_loans, lt_loan_documents). An employee's Emirates ID,
 * passport and visa are read from HR (employee_documents) and never changed here.
 */

require_once dirname(__DIR__, 3) . '/hr/includes/hr_document_file_helper.php';

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/** Who may use the module: the same people who see employee documents in HR. */
const LT_ROLES = ['Owner', 'Admin', 'HR'];

const LT_MAX_FILE = 10 * 1024 * 1024;
const LT_FILE_TYPES = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
];

/** Borrower types: label, icon and chart colour. */
function lt_types(): array
{
    return [
        'employee' => ['label' => 'Employee',       'icon' => 'bi-person-badge', 'color' => '#2563eb'],
        'person'   => ['label' => 'Outside person', 'icon' => 'bi-person',       'color' => '#c9a227'],
        'company'  => ['label' => 'Company',        'icon' => 'bi-building',     'color' => '#16a34a'],
    ];
}

function lt_statuses(): array
{
    return [
        'draft'     => ['label' => 'Draft',     'class' => 'lt-badge-draft'],
        'active'    => ['label' => 'Active',    'class' => 'lt-badge-active'],
        'closed'    => ['label' => 'Closed',    'class' => 'lt-badge-closed'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'lt-badge-cancelled'],
    ];
}

/** Login, role check, and the company the loans belong to. */
function lt_boot(PDO $conn): int
{
    require_once dirname(__DIR__, 3) . '/includes/company_helper.php';
    require_login(get_application_web_root() . '/login');
    require_role(LT_ROLES, $conn);
    return (int)(current_company_id($conn) ?: 1);
}

function lt_flash(string $message, string $type = 'success'): void
{
    $_SESSION['lt_flash'] = ['message' => $message, 'type' => $type];
}

function lt_take_flash(): ?array
{
    $f = $_SESSION['lt_flash'] ?? null;
    unset($_SESSION['lt_flash']);
    return is_array($f) ? $f : null;
}

function lt_loan_no(int $id): string
{
    return 'LN-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

function lt_date(?string $d): string
{
    return $d ? date('d M Y', strtotime($d)) : '—';
}

function lt_money($amount): string
{
    return number_format((float)$amount, 2);
}

/**
 * Staff loans and salary advances already kept in HR (cash_advances), all companies,
 * open and closed. Read only: HR and payroll own these rows.
 * Pending or rejected requests and voided ones are left out.
 */
function lt_hr_loans(PDO $conn, ?int $employeeId = null): array
{
    $byEmp = $employeeId ? ' AND ca.employee_id = ' . (int)$employeeId : '';
    $sql = "SELECT ca.id, ca.employee_id, ca.product_type, ca.tx_date, ca.status,
                   COALESCE(ca.principal, ca.amount) AS amount,
                   COALESCE(ca.remaining_balance, ca.amount) AS outstanding,
                   ca.installment_count, ca.installment_amount, ca.preferred_settle_method, ca.description,
                   e.full_name, e.employee_code, c.name AS company_name,
                   (SELECT MAX(s.settle_date) FROM hr_loan_settlements s WHERE s.loan_id = ca.id) AS last_paid,
                   (hid.cash_advance_id IS NOT NULL) AS hidden
              FROM cash_advances ca
              JOIN employees e ON e.id = ca.employee_id
              LEFT JOIN companies c ON c.id = e.company_id
              LEFT JOIN lt_hidden_hr_loans hid ON hid.cash_advance_id = ca.id
             WHERE ca.status IN ('open','settled')
               AND (ca.request_status IS NULL OR ca.request_status = 'approved')$byEmp
             ORDER BY (ca.status = 'open') DESC, ca.tx_date DESC, ca.id DESC";
    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['outstanding'] = $r['status'] === 'settled' ? 0.0 : max(0.0, (float)$r['outstanding']);
        $r['recovered'] = max(0.0, (float)$r['amount'] - $r['outstanding']);
    }
    return $rows;
}

/** Recoveries of the HR loans, oldest first, grouped by loan id. Read only. */
function lt_hr_settlements(PDO $conn): array
{
    $sql = "SELECT s.id, s.loan_id, s.settle_date, s.amount, s.method, s.notes, s.created_at, pr.period_from,
                   rf.paid_by, rf.reference, rf.file_path AS proof_path, rf.original_name AS proof_name
              FROM hr_loan_settlements s
              LEFT JOIN payroll_runs pr ON pr.id = s.payroll_run_id
              LEFT JOIN lt_repayment_files rf ON rf.settlement_id = s.id
             ORDER BY s.loan_id, s.settle_date, s.id";
    $out = [];
    foreach ($conn->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['loan_id']][] = $r;
    }
    return $out;
}

/**
 * Timeline of one HR loan: given, each recovery with the balance after it, then closed or still to pay.
 * Each event: kind (given|pay|end), icon, date, title, amount, note.
 */
function lt_hr_loan_events(array $loan, array $settlements): array
{
    $kind = $loan['product_type'] === 'loan' ? 'Loan' : 'Salary advance';
    $plan = (int)$loan['installment_count'] . ' × AED ' . lt_money($loan['installment_amount'] ?: $loan['amount'])
        . ($loan['preferred_settle_method'] === 'cash' ? ' in cash' : ' from salary');
    $ev = [['kind' => 'given', 'icon' => 'bi-cash-coin', 'date' => lt_date($loan['tx_date']), 'title' => $kind . ' given',
        'amount' => 'AED ' . lt_money($loan['amount']),
        'note' => 'Plan: ' . $plan . (trim((string)$loan['description']) !== '' ? ' · ' . $loan['description'] : '')]];
    $bal = (float)$loan['amount'];
    foreach ($settlements as $s) {
        $bal = max(0.0, $bal - (float)$s['amount']);
        $ev[] = ['kind' => 'pay', 'icon' => 'bi-arrow-down', 'date' => lt_date($s['settle_date']),
            'title' => $s['method'] === 'cash' ? lt_paid_by_label($s['paid_by'] ?? null)
                : 'Deducted from salary' . (!empty($s['period_from']) ? ' · ' . date('F Y', strtotime($s['period_from'])) : ''),
            'amount' => '− AED ' . lt_money($s['amount']),
            'note' => 'Balance after: AED ' . lt_money($bal) . (!empty($s['reference']) ? ' · Ref ' . $s['reference'] : ''),
            'file' => !empty($s['proof_path']) ? 'file.php?rep=' . (int)$s['id'] : null];
    }
    if (!$settlements) {
        $ev[] = ['kind' => 'pay', 'icon' => 'bi-dash', 'date' => '—', 'title' => 'Nothing paid back yet', 'amount' => '', 'note' => ''];
    }
    $ev[] = $loan['status'] === 'open'
        ? ['kind' => 'end', 'icon' => 'bi-hourglass-split', 'date' => 'Now', 'title' => 'Still to pay', 'amount' => 'AED ' . lt_money($loan['outstanding']), 'note' => '']
        : ['kind' => 'done', 'icon' => 'bi-check-lg', 'date' => 'Closed', 'title' => 'Fully paid back', 'amount' => '', 'note' => ''];
    return $ev;
}

function lt_paid_by_label(?string $paidBy): string
{
    return ['bank' => 'Paid by bank transfer', 'cheque' => 'Paid by cheque'][$paidBy ?? ''] ?? 'Paid in cash';
}

/** Draw a list of timeline events. An event with a 'file' gets a View attachment link. */
function lt_render_timeline(array $events): void
{
    echo '<ul class="lt-tl">';
    foreach ($events as $e) {
        echo '<li class="lt-tl-' . h($e['kind']) . '">'
            . '<span class="lt-tl-dot"><i class="bi ' . h($e['icon']) . '"></i></span>'
            . '<div class="lt-tl-date">' . h($e['date']) . '</div>'
            . '<div class="d-flex justify-content-between gap-3"><div class="lt-tl-title">' . h($e['title']) . '</div>'
            . ($e['amount'] !== '' ? '<div class="lt-tl-amt">' . h($e['amount']) . '</div>' : '') . '</div>'
            . ($e['note'] !== '' ? '<div class="lt-tl-note">' . h($e['note']) . '</div>' : '')
            . (!empty($e['file']) ? '<a class="lt-tl-file" href="' . h($e['file']) . '" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i> View attachment</a>' : '')
            . '</li>';
    }
    echo '</ul>';
}

/** Timeline of a loan added in Loan Tracker: started, each document, and what comes next. */
function lt_loan_events(array $loan, array $docs, array $repayments = []): array
{
    $ev = [['kind' => 'given', 'icon' => 'bi-plus-lg', 'date' => lt_date($loan['created_at']), 'title' => 'Loan started',
        'amount' => '', 'note' => lt_loan_no((int)$loan['id'])]];
    foreach ($docs as $d) {
        if (!empty($d['skipped'])) {
            $ev[] = ['kind' => 'warn', 'icon' => 'bi-file-earmark-x', 'date' => lt_date($d['created_at']), 'title' => $d['title'] . ' skipped',
                'amount' => '', 'note' => 'Reason: ' . $d['skip_reason']];
        } else {
            $ev[] = ['kind' => 'pay', 'icon' => 'bi-file-earmark-check', 'date' => lt_date($d['created_at']),
                'title' => $d['title'] . (trim((string)$d['file_path']) === '' ? ' noted (no file)' : ($d['source'] === 'hr' ? ' taken from HR' : ' attached')),
                'amount' => '', 'note' => trim(($d['doc_number'] ? 'No. ' . $d['doc_number'] : '') . ($d['expires_at'] ? ' · expires ' . lt_date($d['expires_at']) : ''), ' ·'),
                'file' => trim((string)$d['file_path']) !== '' ? 'file.php?id=' . (int)$d['id'] : null];
        }
    }
    if (!empty($loan['details_saved_at'])) {
        $modes = lt_repayment_modes('employee');
        $ev[] = ['kind' => 'given', 'icon' => 'bi-cash-coin', 'date' => lt_date($loan['issue_date']),
            'title' => 'Loan issued · paid out ' . ($loan['paid_out_by'] === 'bank' ? 'by bank transfer' : 'in cash'),
            'amount' => 'AED ' . lt_money($loan['amount']),
            'note' => (int)$loan['installments'] . ' instalment' . ((int)$loan['installments'] === 1 ? '' : 's')
                . ((float)$loan['interest_rate'] > 0 ? ' · interest ' . rtrim(rtrim((string)$loan['interest_rate'], '0'), '.') . '%' : '')
                . ' · total to pay back AED ' . lt_money($loan['total_payable'])
                . ' · ' . ($modes[$loan['repayment_mode']] ?? '') . ' · first due ' . lt_date($loan['first_due_date']),
            'file' => !empty($loan['proof_path']) ? 'file.php?proof=' . (int)$loan['id'] : null];
    }
    if (!empty($loan['submitted_at'])) {
        $ev[] = ['kind' => 'pay', 'icon' => 'bi-patch-check', 'date' => lt_date($loan['submitted_at']),
            'title' => 'Submitted' . (!empty($loan['issuer_name']) ? ' by ' . $loan['issuer_name'] : ''),
            'amount' => '', 'note' => 'Details confirmed as correct.'];
    }
    $left = (float)$loan['total_payable'];
    foreach ($repayments as $r) {
        $left = max(0.0, $left - (float)$r['amount']);
        $ev[] = ['kind' => 'pay', 'icon' => 'bi-arrow-down', 'date' => lt_date($r['paid_on']),
            'title' => $r['paid_by'] === 'salary' ? 'Deducted from salary' : lt_paid_by_label($r['paid_by']),
            'amount' => '− AED ' . lt_money($r['amount']),
            'note' => 'Balance after: AED ' . lt_money($left) . (!empty($r['reference']) ? ' · Ref ' . $r['reference'] : '') . (!empty($r['notes']) ? ' · ' . $r['notes'] : ''),
            'file' => !empty($r['file_path']) ? 'file.php?ltrep=' . (int)$r['id'] : null];
    }
    if ($loan['status'] === 'draft') {
        $ev[] = empty($loan['details_saved_at'])
            ? ['kind' => 'end', 'icon' => 'bi-hourglass-split', 'date' => 'Next', 'title' => 'Waiting for loan details', 'amount' => '', 'note' => 'Amount and instalments come in the next step.']
            : ['kind' => 'end', 'icon' => 'bi-hourglass-split', 'date' => 'Next', 'title' => 'Waiting for review and submit', 'amount' => '', 'note' => ''];
    } elseif ($loan['status'] === 'active') {
        $ev[] = ['kind' => 'end', 'icon' => 'bi-hourglass-split', 'date' => 'Now', 'title' => 'Still to pay',
            'amount' => 'AED ' . lt_money($left), 'note' => ''];
    } else {
        $closedByHand = $loan['status'] === 'closed' && !empty($loan['close_note']);
        $ev[] = ['kind' => 'done', 'icon' => $closedByHand ? 'bi-lock' : 'bi-check-lg',
            'date' => $closedByHand ? lt_date($loan['closed_at']) : '',
            'title' => $closedByHand ? 'Closed' : ($loan['status'] === 'closed' ? 'Fully paid back' : lt_statuses()[$loan['status']]['label']),
            'amount' => '', 'note' => $closedByHand ? 'Reason: ' . $loan['close_note'] : ''];
    }
    return $ev;
}

/** Paid so far on a Loan Tracker loan. */
function lt_loan_paid(PDO $conn, int $loanId): float
{
    $q = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM lt_loan_repayments WHERE loan_id = ?");
    $q->execute([$loanId]);
    return (float)$q->fetchColumn();
}

/**
 * Record a repayment on a Loan Tracker loan. Call inside a transaction.
 * Fills the oldest unpaid instalments first; the loan closes when nothing is left to pay.
 * Returns [repayment id, amount left].
 */
function lt_record_lt_repayment(PDO $conn, int $loanId, float $amount, string $date, string $paidBy,
                                string $reference, string $notes, ?string $filePath, ?string $fileName, ?int $userId): array
{
    $q = $conn->prepare("SELECT id, status, total_payable FROM lt_loans WHERE id = ? FOR UPDATE");
    $q->execute([$loanId]);
    $loan = $q->fetch(PDO::FETCH_ASSOC);
    if (!$loan || $loan['status'] !== 'active') {
        throw new RuntimeException('This loan is not active, so no repayment can be recorded.');
    }
    $left = round((float)$loan['total_payable'] - lt_loan_paid($conn, $loanId), 2);
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new RuntimeException('Please enter the amount paid.');
    }
    if ($amount > $left + 0.005) {
        throw new RuntimeException('The amount is more than the AED ' . lt_money($left) . ' still to pay.');
    }
    $conn->prepare("INSERT INTO lt_loan_repayments (loan_id, paid_on, amount, paid_by, reference, notes, file_path, original_name, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$loanId, $date, $amount, $paidBy, $reference ?: null, $notes ?: null, $filePath, $fileName, $userId]);
    $repId = (int)$conn->lastInsertId();

    $rest = $amount;
    $inst = $conn->prepare("SELECT id, amount, paid_amount FROM lt_loan_installments WHERE loan_id = ? AND paid_amount < amount ORDER BY seq FOR UPDATE");
    $inst->execute([$loanId]);
    $upd = $conn->prepare("UPDATE lt_loan_installments SET paid_amount = ? WHERE id = ?");
    foreach ($inst->fetchAll(PDO::FETCH_ASSOC) as $i) {
        if ($rest <= 0.005) { break; }
        $take = min($rest, round((float)$i['amount'] - (float)$i['paid_amount'], 2));
        $upd->execute([round((float)$i['paid_amount'] + $take, 2), (int)$i['id']]);
        $rest = round($rest - $take, 2);
    }
    $newLeft = max(0.0, round($left - $amount, 2));
    if ($newLeft <= 0.005) {
        $conn->prepare("UPDATE lt_loans SET status = 'closed' WHERE id = ?")->execute([$loanId]);
    }
    return [$repId, $newLeft];
}

/** How a loan is paid back. Salary deduction only for employees. */
function lt_repayment_modes(string $loanTo): array
{
    $m = ['salary' => 'Salary deduction', 'cash' => 'Cash', 'bank' => 'Bank transfer', 'cheque' => 'Cheque'];
    if ($loanTo !== 'employee') { unset($m['salary']); }
    return $m;
}

/** Same day N months later; a 31st falls back to the month's last day. */
function lt_add_months(string $date, int $months): string
{
    $d = new DateTime($date);
    $day = (int)$d->format('d');
    $d->modify('first day of this month')->modify("+$months month");
    $last = (int)$d->format('t');
    $d->setDate((int)$d->format('Y'), (int)$d->format('m'), min($day, $last));
    return $d->format('Y-m-d');
}

/** Total to pay back: amount plus flat interest %. */
function lt_total_payable(float $amount, float $rate): float
{
    return round($amount * (1 + max(0.0, $rate) / 100), 2);
}

/** Monthly instalments: equal amounts, the last one takes the rounding. */
function lt_schedule(float $total, int $n, string $firstDue): array
{
    $n = max(1, $n);
    $each = floor($total / $n * 100) / 100;
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $amt = $i === $n - 1 ? round($total - $each * ($n - 1), 2) : $each;
        $rows[] = ['seq' => $i + 1, 'due' => lt_add_months($firstDue, $i), 'amount' => $amt];
    }
    return $rows;
}

function lt_user_name(PDO $conn, ?int $userId): string
{
    if (!$userId) { return ''; }
    $q = $conn->prepare("SELECT COALESCE(NULLIF(fullname,''), username) FROM user WHERE id = ?");
    $q->execute([$userId]);
    return (string)($q->fetchColumn() ?: '');
}

/** Employees a loan can be given to: everyone still on the books, with their company. */
function lt_employees(PDO $conn): array
{
    $sql = "SELECT e.id, e.employee_code, e.full_name, c.name AS company_name
              FROM employees e
              LEFT JOIN companies c ON c.id = e.company_id
             WHERE e.status IN ('active','on_leave','notice_period')
             ORDER BY c.name, e.full_name";
    return $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function lt_employee(PDO $conn, int $id): ?array
{
    $q = $conn->prepare("SELECT e.*, c.name AS company_name
                           FROM employees e LEFT JOIN companies c ON c.id = e.company_id
                          WHERE e.id = ? AND e.status IN ('active','on_leave','notice_period')");
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** How each loan document is stored in HR (old rows have only the name, new ones the id). */
function lt_hr_doc_match(): array
{
    return [
        'emirates_id' => ['id' => 3, 'names' => ['National ID', 'Emirates ID']],
        'passport'    => ['id' => 1, 'names' => ['Passport']],
        'visa'        => ['id' => 2, 'names' => ['Visa']],
    ];
}

/**
 * The employee's Emirates ID, passport and visa from HR.
 * File: the newest row that has one. Number and expiry: the newest row that has them,
 * falling back to the employee record (passport number and expiry, visa expiry).
 */
function lt_employee_docs(PDO $conn, array $emp): array
{
    $out = [];
    foreach (lt_hr_doc_match() as $kind => $m) {
        $in = implode(',', array_fill(0, count($m['names']), '?'));
        $q = $conn->prepare("SELECT id, doc_number, expires_at, file_path
                               FROM employee_documents
                              WHERE employee_id = ? AND (doc_type_id = ? OR doc_type IN ($in))
                              ORDER BY (expires_at IS NULL), expires_at DESC, id DESC");
        $q->execute(array_merge([(int)$emp['id'], $m['id']], $m['names']));
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        $doc = ['hr_document_id' => null, 'file_path' => null, 'doc_number' => '', 'expires_at' => null];
        foreach ($rows as $r) {
            if ($doc['hr_document_id'] === null && trim((string)$r['file_path']) !== ''
                && is_file(hr_document_file_absolute_path($r['file_path']))) {
                $doc['hr_document_id'] = (int)$r['id'];
                $doc['file_path'] = $r['file_path'];
            }
            if ($doc['doc_number'] === '' && trim((string)$r['doc_number']) !== '') {
                $doc['doc_number'] = trim((string)$r['doc_number']);
            }
            if ($doc['expires_at'] === null && !empty($r['expires_at'])) {
                $doc['expires_at'] = $r['expires_at'];
            }
        }
        if ($kind === 'passport') {
            if ($doc['doc_number'] === '' && !empty($emp['pass_num'])) { $doc['doc_number'] = (string)$emp['pass_num']; }
            if ($doc['expires_at'] === null && !empty($emp['pass_ex_d'])) { $doc['expires_at'] = $emp['pass_ex_d']; }
        }
        if ($kind === 'visa' && $doc['expires_at'] === null && !empty($emp['visa_ex_d'])) {
            $doc['expires_at'] = $emp['visa_ex_d'];
        }
        $out[$kind] = $doc;
    }
    return $out;
}

/** One HR document row, only if it is an Emirates ID, passport or visa. */
function lt_hr_document(PDO $conn, int $hrDocId): ?array
{
    $ids = []; $names = [];
    foreach (lt_hr_doc_match() as $m) { $ids[] = $m['id']; $names = array_merge($names, $m['names']); }
    $q = $conn->prepare("SELECT id, employee_id, file_path FROM employee_documents
                          WHERE id = ? AND (doc_type_id IN (" . implode(',', $ids) . ")
                                OR doc_type IN (" . implode(',', array_fill(0, count($names), '?')) . "))");
    $q->execute(array_merge([$hrDocId], $names));
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

function lt_upload_root(): string
{
    return dirname(__DIR__, 3) . '/uploads/loan_tracker';
}

/** Files here are only ever served through file.php. */
function lt_protect_upload_root(): void
{
    $root = lt_upload_root();
    if (!is_dir($root)) {
        @mkdir($root, 0775, true);
    }
    $ht = $root . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "# Loan Tracker files are served by modules/loan_tracker/file.php only.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
}

/** Is a file attached in this $_FILES entry? Returns an error sentence, or null when fine/empty. */
function lt_upload_error(?array $f, string $label): ?string
{
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return $label . ': the file did not upload. Please attach it again.';
    }
    if ($f['size'] > LT_MAX_FILE) {
        return $label . ': the file is bigger than 10 MB.';
    }
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!isset(LT_FILE_TYPES[$type])) {
        return $label . ': only PDF, JPG, PNG or WEBP files can be attached.';
    }
    return null;
}

function lt_has_upload(?array $f): bool
{
    return $f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
}

/** Move a checked upload into the loan's folder. Returns the path kept in the table. */
function lt_store_upload(array $f, $loanId): string
{
    lt_protect_upload_root();
    $dir = lt_upload_root() . '/' . $loanId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new RuntimeException('Could not create the folder for the files.');
    }
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $name = bin2hex(random_bytes(12)) . '.' . LT_FILE_TYPES[$type];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not save an attached file.');
    }
    return 'uploads/loan_tracker/' . $loanId . '/' . $name;
}

/** Absolute path of a loan document's file, kept inside its own folder. */
function lt_doc_abs_path(array $doc): string
{
    if (!empty($doc['skipped']) || trim((string)($doc['file_path'] ?? '')) === '') {
        return '';
    }
    if (($doc['source'] ?? '') === 'hr') {
        return hr_document_file_absolute_path($doc['file_path']);
    }
    $root = realpath(lt_upload_root());
    $abs = realpath(dirname(__DIR__, 3) . '/' . ltrim((string)$doc['file_path'], '/'));
    if (!$root || !$abs || strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0) {
        return '';
    }
    return $abs;
}

/** Steps along the top of the New loan pages. */
function lt_steps(int $current): void
{
    $steps = [1 => 'Borrower', 2 => 'Documents', 3 => 'Loan details', 4 => 'Review & submit'];
    echo '<ol class="lt-steps">';
    foreach ($steps as $n => $label) {
        $cls = $n < $current ? 'done' : ($n === $current ? 'current' : '');
        echo '<li class="' . $cls . '"><span class="lt-step-dot">' . ($n < $current ? '<i class="bi bi-check-lg"></i>' : $n) . '</span>'
            . '<span class="lt-step-label">' . h($label) . '</span></li>';
    }
    echo '</ol>';
}
