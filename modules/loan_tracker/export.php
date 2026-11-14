<?php
/**
 * Loan Tracker — CSV downloads (open in Excel).
 *   export.php?what=hr_loans                 staff loans & advances from HR (all companies)
 *   export.php?what=loans                    loans made in Loan Tracker
 *   export.php?what=repayments&month=2026-08 repayment records (month or all)
 *   export.php?what=schedule&id=12           one loan's instalment schedule
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/lt_helper.php';

$companyId = lt_boot($conn);
$what = (string)($_GET['what'] ?? '');
$types = lt_types();

/** Send rows as a CSV file. A BOM so Excel reads Arabic and other names correctly. */
function lt_send_csv(string $name, array $head, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: private, no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $head);
    foreach ($rows as $r) {
        // A cell starting with = + - @ would run as a formula in Excel
        fputcsv($out, array_map(fn($c) => is_string($c) && $c !== '' && strpos('=+-@', $c[0]) !== false && !is_numeric($c) ? "'" . $c : $c, $r));
    }
    fclose($out);
    exit;
}

if ($what === 'hr_loans') {
    $rows = [];
    foreach (lt_hr_loans($conn) as $r) {
        $rows[] = [$r['employee_code'], $r['full_name'], $r['company_name'], $r['product_type'] === 'loan' ? 'Loan' : 'Salary advance',
            $r['tx_date'], number_format((float)$r['amount'], 2, '.', ''), number_format($r['recovered'], 2, '.', ''),
            number_format($r['outstanding'], 2, '.', ''), (int)$r['installment_count'],
            $r['preferred_settle_method'] === 'cash' ? 'Cash' : 'Salary', $r['last_paid'],
            $r['status'] === 'open' ? 'Open' : 'Closed', $r['hidden'] ? 'Yes' : 'No', $r['description']];
    }
    lt_send_csv('staff-loans', ['Employee code', 'Employee', 'Company', 'Type', 'Given on', 'Amount (AED)', 'Paid back (AED)',
        'Still to pay (AED)', 'Instalments', 'Repaid by', 'Last repayment', 'Status', 'Deleted from dashboard', 'Description'], $rows);
}

if ($what === 'loans') {
    $q = $conn->prepare("SELECT l.*, e.employee_code, COALESCE(NULLIF(u.fullname,''), u.username) AS issuer_name,
                                COALESCE((SELECT SUM(r.amount) FROM lt_loan_repayments r WHERE r.loan_id = l.id), 0) AS paid
                           FROM lt_loans l LEFT JOIN employees e ON e.id = l.employee_id LEFT JOIN user u ON u.id = l.issued_by
                          WHERE l.company_id = ? ORDER BY l.id");
    $q->execute([$companyId]);
    $modes = lt_repayment_modes('employee');
    $rows = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $has = !empty($l['details_saved_at']);
        $rows[] = [lt_loan_no((int)$l['id']), $types[$l['loan_to']]['label'], $l['borrower_name'], $l['employee_code'], $l['phone'], $l['email'],
            $l['issue_date'], $has ? number_format((float)$l['amount'], 2, '.', '') : '', $has ? (float)$l['interest_rate'] : '',
            $has ? number_format((float)$l['total_payable'], 2, '.', '') : '', $has ? (int)$l['installments'] : '',
            $has ? number_format((float)$l['paid'], 2, '.', '') : '', $has ? number_format(max(0, (float)$l['total_payable'] - (float)$l['paid']), 2, '.', '') : '',
            $modes[$l['repayment_mode']] ?? '', $l['paid_out_by'] === 'bank' ? 'Bank transfer' : ($l['paid_out_by'] ? 'Cash' : ''),
            $l['first_due_date'], lt_statuses()[$l['status']]['label'], $l['issuer_name'], $l['submitted_at'], $l['close_note']];
    }
    lt_send_csv('loans', ['Loan', 'Loan to', 'Borrower', 'Employee code', 'Phone', 'Email', 'Date of issue', 'Amount (AED)', 'Interest %',
        'Total to pay back (AED)', 'Instalments', 'Paid back (AED)', 'Still to pay (AED)', 'Mode of repayment', 'Paid in',
        'First due date', 'Status', 'Issued by', 'Submitted', 'Close reason'], $rows);
}

if ($what === 'repayments') {
    $month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : '';
    $rows = [];
    $hr = $conn->query("SELECT s.settle_date, s.amount, s.method, rf.paid_by, rf.reference, s.notes, e.full_name, e.employee_code, c.name AS company
                          FROM hr_loan_settlements s JOIN employees e ON e.id = s.employee_id
                          LEFT JOIN companies c ON c.id = e.company_id LEFT JOIN lt_repayment_files rf ON rf.settlement_id = s.id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($hr as $r) {
        $rows[] = [$r['settle_date'], $r['full_name'], $r['employee_code'], $r['company'], 'Staff loan (HR)',
            $r['method'] === 'cash' ? lt_paid_by_label($r['paid_by']) : 'Deducted from salary', $r['reference'],
            number_format((float)$r['amount'], 2, '.', ''), $r['notes']];
    }
    $q = $conn->prepare("SELECT r.*, l.borrower_name, l.loan_to, e.employee_code FROM lt_loan_repayments r JOIN lt_loans l ON l.id = r.loan_id
                           LEFT JOIN employees e ON e.id = l.employee_id WHERE l.company_id = ?");
    $q->execute([$companyId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [$r['paid_on'], $r['borrower_name'], $r['employee_code'], $types[$r['loan_to']]['label'], lt_loan_no((int)$r['loan_id']),
            $r['paid_by'] === 'salary' ? 'Deducted from salary' : lt_paid_by_label($r['paid_by']), $r['reference'],
            number_format((float)$r['amount'], 2, '.', ''), $r['notes']];
    }
    if ($month !== '') { $rows = array_values(array_filter($rows, fn($r) => substr((string)$r[0], 0, 7) === $month)); }
    usort($rows, fn($a, $b) => strcmp((string)$b[0], (string)$a[0]));
    lt_send_csv('repayments' . ($month ? '-' . $month : ''), ['Date', 'Borrower', 'Employee code', 'Company / type', 'Loan', 'How',
        'Reference', 'Amount (AED)', 'Notes'], $rows);
}

if ($what === 'schedule') {
    $q = $conn->prepare("SELECT l.id FROM lt_loans l WHERE l.id = ? AND l.company_id = ?");
    $q->execute([(int)($_GET['id'] ?? 0), $companyId]);
    $id = (int)$q->fetchColumn();
    if ($id) {
        $q = $conn->prepare("SELECT seq, due_date, amount, paid_amount FROM lt_loan_installments WHERE loan_id = ? ORDER BY seq");
        $q->execute([$id]);
        $rows = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [(int)$r['seq'], $r['due_date'], number_format((float)$r['amount'], 2, '.', ''), number_format((float)$r['paid_amount'], 2, '.', ''),
                number_format(max(0, (float)$r['amount'] - (float)$r['paid_amount']), 2, '.', '')];
        }
        lt_send_csv(strtolower(lt_loan_no($id)) . '-schedule', ['#', 'Due date', 'Amount (AED)', 'Paid (AED)', 'Still to pay (AED)'], $rows);
    }
}

http_response_code(404);
exit('Not found');
