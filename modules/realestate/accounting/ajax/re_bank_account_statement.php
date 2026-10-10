<?php
/**
 * Bank Accounts page - statement popup.
 * Lists the GL entries of one bank account with a running balance
 * (same source as the Current Balance column on bank_accounts.php).
 */
require_once __DIR__ . '/re_bank_reco_bootstrap.php';

$bank_id = (int) ($_GET['bank_account_id'] ?? 0);
$from = trim($_GET['date_from'] ?? '');
$to = trim($_GET['date_to'] ?? '');

$isDate = static fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if ($bank_id <= 0 || !$isDate($from) || !$isDate($to)) {
    re_bank_reco_json_error('Missing parameters');
}

$st = $conn->prepare('SELECT id, gl_account_id FROM re_bank_accounts WHERE id = ? AND company_id = ? LIMIT 1');
$st->execute([$bank_id, $cid]);
$bankAccount = $st->fetch(PDO::FETCH_ASSOC);
if (!$bankAccount) {
    re_bank_reco_json_error('Invalid bank account');
}
$glId = (int) $bankAccount['gl_account_id'];

// Balance brought forward = everything before the From date.
$opening = re_bank_erp_balance($conn, $glId, $cid, date('Y-m-d', strtotime($from . ' -1 day')));

$limit = 2000;
$st = $conn->prepare('
    SELECT gl.id, gl.journal_id, gl.entry_date, gl.debit_amount, gl.credit_amount,
           gl.description, gl.reference,
           jh.journal_number, jh.journal_type, jh.description AS journal_description
    FROM re_general_ledger gl
    JOIN re_journal_headers jh ON jh.id = gl.journal_id
    WHERE gl.account_id = ? AND gl.company_id = ?
      AND gl.entry_date BETWEEN ? AND ?
    ORDER BY gl.entry_date ASC, gl.id ASC
    LIMIT ' . ($limit + 1)
);
$st->execute([$glId, $cid, $from, $to]);
$all = $st->fetchAll(PDO::FETCH_ASSOC);
$truncated = count($all) > $limit;
if ($truncated) {
    $all = array_slice($all, 0, $limit);
}

$rows = [];
$balance = $opening;
$totalIn = 0.0;
$totalOut = 0.0;
foreach ($all as $r) {
    $in = (float) $r['debit_amount'];
    $out = (float) $r['credit_amount'];
    $balance = round($balance + $in - $out, 2);
    $totalIn += $in;
    $totalOut += $out;
    $rows[] = [
        'date' => (string) $r['entry_date'],
        'journal_id' => (int) $r['journal_id'],
        'journal_number' => (string) $r['journal_number'],
        'journal_type' => (string) $r['journal_type'],
        'description' => (string) ($r['description'] ?: $r['journal_description']),
        'reference' => (string) ($r['reference'] ?? ''),
        'money_in' => $in,
        'money_out' => $out,
        'balance' => $balance,
    ];
}

echo json_encode([
    'success' => true,
    'opening' => $opening,
    'closing' => $balance,
    'total_in' => round($totalIn, 2),
    'total_out' => round($totalOut, 2),
    'lines' => $rows,
    'truncated' => $truncated,
    'limit' => $limit,
]);
