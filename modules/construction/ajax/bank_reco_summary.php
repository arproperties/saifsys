<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);

$bank_id = (int) ($_GET['bank_account_id'] ?? 0);
$from = trim($_GET['date_from'] ?? '');
$to = trim($_GET['date_to'] ?? '');

if ($bank_id <= 0 || $from === '' || $to === '') {
    co_bank_reco_json_error('bank_account_id, date_from, date_to required');
}

$bank = co_bank_verify_account($conn, $bank_id, $cid);
if (!$bank) {
    co_bank_reco_json_error('Invalid bank account');
}

try {
    $st = $conn->prepare("
        SELECT COALESCE(SUM(l.amount), 0) AS statement_total
        FROM co_bank_statement_lines l
        WHERE l.bank_account_id = ? AND l.company_id = ? AND l.txn_date BETWEEN ? AND ?
    ");
    $st->execute([$bank_id, $cid, $from, $to]);
    $statement_total = round((float) $st->fetchColumn(), 2);

    $st = $conn->prepare("
        SELECT COALESCE(SUM(m.amount_matched), 0)
        FROM co_reconciliation_matches m
        INNER JOIN co_bank_statement_lines l ON l.id = m.bank_statement_line_id
        WHERE l.bank_account_id = ? AND l.company_id = ? AND l.txn_date BETWEEN ? AND ?
          AND m.status IN ('proposed','confirmed')
    ");
    $st->execute([$bank_id, $cid, $from, $to]);
    $matched_total = round((float) $st->fetchColumn(), 2);

    $st = $conn->prepare("
        SELECT l.id, l.amount,
          (SELECT COALESCE(SUM(m2.amount_matched),0) FROM co_reconciliation_matches m2
           WHERE m2.bank_statement_line_id = l.id AND m2.status IN ('proposed','confirmed')) AS ms
        FROM co_bank_statement_lines l
        WHERE l.bank_account_id = ? AND l.company_id = ? AND l.txn_date BETWEEN ? AND ?
    ");
    $st->execute([$bank_id, $cid, $from, $to]);
    $unmatched = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $unmatched += max(0, round(abs((float) $row['amount']) - (float) $row['ms'], 2));
    }
    $unmatched_total = round($unmatched, 2);

    $gl_account_id = (int) $bank['gl_account_id'];
    $st = $conn->prepare("
        SELECT COALESCE(SUM(debit_amount), 0) FROM re_general_ledger
        WHERE account_id = ? AND company_id = ? AND entry_date BETWEEN ? AND ?
    ");
    $st->execute([$gl_account_id, $cid, $from, $to]);
    $book_inflows = round((float) $st->fetchColumn(), 2);

    $st = $conn->prepare("
        SELECT COALESCE(SUM(credit_amount), 0) FROM re_general_ledger
        WHERE account_id = ? AND company_id = ? AND entry_date BETWEEN ? AND ?
    ");
    $st->execute([$gl_account_id, $cid, $from, $to]);
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
        'account_code' => $bank['account_code'],
    ]);
} catch (Throwable $e) {
    error_log('co bank_reco_summary: ' . $e->getMessage());
    co_bank_reco_json_error($e->getMessage());
}
