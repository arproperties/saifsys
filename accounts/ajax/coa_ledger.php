<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/cleaning_accounting_context.php';
require_once __DIR__.'/../../includes/report_date_helpers.php';
require_once __DIR__.'/../../includes/report_gl_helpers.php';
require_role(['Owner','Admin','Account'], $conn);
header('Content-Type: application/json');

$accountId = (int)($_GET['account_id'] ?? 0);
$limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
$dates = report_date_range($_GET);
$from = $dates['from'];
$to = $dates['to'];
$companyId = cleaning_accounting_company_id($conn);

if ($accountId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing account']);
    exit;
}

$accSt = $conn->prepare("
    SELECT id, account_no, name, type, normal_balance, is_header, is_active
    FROM chart_of_accounts WHERE id = ?
");
$accSt->execute([$accountId]);
$account = $accSt->fetch(PDO::FETCH_ASSOC);
if (!$account) {
    echo json_encode(['success' => false, 'error' => 'Account not found']);
    exit;
}

$st = $conn->prepare("
    SELECT
        j.id AS journal_id,
        j.journal_no,
        j.journal_date,
        j.source,
        j.memo,
        l.description AS line_description,
        l.debit,
        l.credit,
        CASE
            WHEN j.source = 'invoice' AND j.source_id IS NOT NULL THEN
                (SELECT CONCAT('Invoice: ', invoice_no) FROM invoices WHERE id = j.source_id)
            WHEN j.source = 'expense' AND j.source_id IS NOT NULL THEN
                (SELECT CONCAT('Expense: ', reference_no) FROM expenses WHERE id = j.source_id)
            WHEN j.source = 'reversal' AND j.source_id IS NOT NULL THEN
                CONCAT('Reversal of J#', j.source_id)
            ELSE COALESCE(j.memo, j.source)
        END AS source_info
    FROM gl_journal_lines l
    JOIN gl_journals j ON j.id = l.journal_id
      AND j.journal_date BETWEEN ? AND ?
      AND j.company_id = ?
      " . report_gl_bs_journal_sql('j', 'l') . "
    WHERE l.account_id = ?
    ORDER BY j.journal_date DESC, j.id DESC, l.line_no
    LIMIT {$limit}
");
$st->execute([$from, $to, $companyId, $accountId]);
$lines = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totSt = $conn->prepare("
    SELECT COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit,
           COUNT(DISTINCT j.id) AS txn_count
    FROM gl_journal_lines l
    JOIN gl_journals j ON j.id = l.journal_id
      AND j.journal_date BETWEEN ? AND ?
      AND j.company_id = ?
      " . report_gl_bs_journal_sql('j', 'l') . "
    WHERE l.account_id = ?
");
$totSt->execute([$from, $to, $companyId, $accountId]);
$totals = $totSt->fetch(PDO::FETCH_ASSOC) ?: ['debit' => 0, 'credit' => 0, 'txn_count' => 0];

$debit = (float)$totals['debit'];
$credit = (float)$totals['credit'];
$type = (string)$account['type'];
if (in_array($type, ['Asset', 'Expense'], true)) {
    $net = round($debit - $credit, 2);
} else {
    $net = round($credit - $debit, 2);
}

echo json_encode([
    'success' => true,
    'account' => $account,
    'from' => $from,
    'to' => $to,
    'totals' => [
        'debit' => round($debit, 2),
        'credit' => round($credit, 2),
        'net' => $net,
        'txn_count' => (int)$totals['txn_count'],
    ],
    'lines' => array_map(static function (array $row) use ($type) {
        $d = (float)$row['debit'];
        $c = (float)$row['credit'];
        $row['debit'] = round($d, 2);
        $row['credit'] = round($c, 2);
        $row['net'] = in_array($type, ['Asset', 'Expense'], true)
            ? round($d - $c, 2)
            : round($c - $d, 2);
        return $row;
    }, $lines),
]);
