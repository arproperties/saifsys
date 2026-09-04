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
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$acct = cleaning_bank_account_no($conn, $bank_id);
if (!$acct) {
    echo json_encode(['success' => false, 'error' => 'Invalid bank account']);
    exit;
}

$receipts = [];
$st = $conn->prepare("
    SELECT r.id, r.receipt_no, r.receipt_date, r.amount, r.method, r.notes,
           c.client_name AS counterparty
    FROM receipts r
    LEFT JOIN client c ON c.id = r.client_id
    WHERE r.deposit_account_no = ? AND r.receipt_date BETWEEN ? AND ?
    ORDER BY r.receipt_date DESC, r.id DESC
    LIMIT 500
");
$st->execute([$acct, $from, $to]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rid = (int) $r['id'];
    $matched = cleaning_receipt_matched_sum($conn, $rid);
    $amt = (float) $r['amount'];
    $receipts[] = [
        'system_type' => 'receipt',
        'system_id' => $rid,
        'txn_date' => $r['receipt_date'],
        'amount' => $amt,
        'remaining' => round(max(0, $amt - $matched), 2),
        'reference' => $r['receipt_no'],
        'counterparty' => $r['counterparty'] ?? '',
        'detail' => $r['method'] . ($r['notes'] ? ' — ' . $r['notes'] : ''),
    ];
}

$expenses = [];
$st = $conn->prepare("
    SELECT e.id, e.expense_date, e.total, e.reference_no, e.paid_via, e.notes,
           v.name AS vendor_name
    FROM expenses e
    LEFT JOIN vendors v ON v.id = e.vendor_id
    WHERE e.paid_via IN ('cash','bank') AND e.pay_account_no = ?
      AND e.expense_date BETWEEN ? AND ?
    ORDER BY e.expense_date DESC, e.id DESC
    LIMIT 500
");
$st->execute([$acct, $from, $to]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
    $eid = (int) $e['id'];
    $matched = cleaning_expense_matched_sum($conn, $eid);
    $tot = (float) $e['total'];
    $expenses[] = [
        'system_type' => 'expense',
        'system_id' => $eid,
        'txn_date' => $e['expense_date'],
        'amount' => $tot,
        'remaining' => round(max(0, $tot - $matched), 2),
        'reference' => $e['reference_no'] ?? '',
        'counterparty' => $e['vendor_name'] ?? '',
        'detail' => $e['paid_via'] . ($e['notes'] ? ' — ' . $e['notes'] : ''),
    ];
}

echo json_encode(['success' => true, 'receipts' => $receipts, 'expenses' => $expenses, 'account_no' => $acct]);
