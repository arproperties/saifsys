<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/cleaning_bank_reconciliation.php';

require_role(['Owner', 'Admin', 'Account'], $conn);

header('Content-Type: application/json; charset=utf-8');

$bank_id = (int) ($_GET['bank_account_id'] ?? 0);
$from = trim($_GET['date_from'] ?? '');
$to = trim($_GET['date_to'] ?? '');

if ($bank_id <= 0 || $from === '' || $to === '') {
    echo json_encode(['success' => false, 'error' => 'bank_account_id, date_from, date_to required']);
    exit;
}

$acct = cleaning_bank_account_no($conn, $bank_id);
if (!$acct) {
    echo json_encode(['success' => false, 'error' => 'Invalid bank account']);
    exit;
}

try {
    $st = $conn->prepare("
        SELECT COALESCE(SUM(l.amount), 0) AS statement_total
        FROM cleaning_bank_statement_lines l
        WHERE l.bank_account_id = ? AND l.txn_date BETWEEN ? AND ?
    ");
    $st->execute([$bank_id, $from, $to]);
    $statement_total = round((float) $st->fetchColumn(), 2);

    $st = $conn->prepare("
        SELECT COALESCE(SUM(m.amount_matched), 0)
        FROM cleaning_reconciliation_matches m
        INNER JOIN cleaning_bank_statement_lines l ON l.id = m.bank_statement_line_id
        WHERE l.bank_account_id = ? AND l.txn_date BETWEEN ? AND ?
          AND m.status IN ('proposed','confirmed')
    ");
    $st->execute([$bank_id, $from, $to]);
    $matched_total = round((float) $st->fetchColumn(), 2);

    $st = $conn->prepare("
        SELECT l.id, l.amount,
          (SELECT COALESCE(SUM(m2.amount_matched),0) FROM cleaning_reconciliation_matches m2
           WHERE m2.bank_statement_line_id = l.id AND m2.status IN ('proposed','confirmed')) AS ms
        FROM cleaning_bank_statement_lines l
        WHERE l.bank_account_id = ? AND l.txn_date BETWEEN ? AND ?
    ");
    $st->execute([$bank_id, $from, $to]);
    $unmatched = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $unmatched += max(0, round(abs((float) $row['amount']) - (float) $row['ms'], 2));
    }
    $unmatched_total = round($unmatched, 2);

    $st = $conn->prepare("
        SELECT COALESCE(SUM(r.amount), 0) FROM receipts r
        WHERE r.deposit_account_no = ? AND r.receipt_date BETWEEN ? AND ?
    ");
    $st->execute([$acct, $from, $to]);
    $book_inflows = round((float) $st->fetchColumn(), 2);

    $st = $conn->prepare("
        SELECT COALESCE(SUM(e.total), 0) FROM expenses e
        WHERE e.paid_via IN ('cash','bank') AND e.pay_account_no = ?
          AND e.expense_date BETWEEN ? AND ?
    ");
    $st->execute([$acct, $from, $to]);
    $book_outflows = round((float) $st->fetchColumn(), 2);

    $book_net = round($book_inflows - $book_outflows, 2);

    echo json_encode([
        'success' => true,
        'statement_total' => $statement_total,
        'matched_total' => $matched_total,
        'unmatched_total' => $unmatched_total,
        'book_inflows_total' => $book_inflows,
        'book_outflows_total' => $book_outflows,
        'book_net_total' => $book_net,
        'account_no' => $acct,
    ]);
} catch (Throwable $e) {
    error_log('bank_reco_summary: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
