<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);
co_bank_reco_guard($conn, 'construction.bank_reconciliation.view');

$bank_id = (int) ($_GET['bank_account_id'] ?? 0);
$as_of = trim($_GET['as_of'] ?? date('Y-m-d'));
if ($bank_id <= 0) {
    co_bank_reco_json_error('bank_account_id required');
}

$bank = co_bank_verify_account($conn, $bank_id, $cid);
if (!$bank) {
    co_bank_reco_json_error('Invalid bank account');
}

$glId = (int) $bank['gl_account_id'];
$erpBalance = co_bank_erp_balance($conn, $glId, $cid, $as_of);
$stmtBalance = co_bank_statement_balance($conn, $bank_id, $cid, $as_of);

$st = $conn->prepare("
    SELECT COUNT(*) FROM co_bank_statement_lines l
    WHERE l.bank_account_id = ? AND l.company_id = ?
      AND l.txn_date <= ?
      AND NOT EXISTS (
        SELECT 1 FROM co_reconciliation_matches m
        WHERE m.bank_statement_line_id = l.id AND m.status = 'confirmed'
        HAVING COALESCE(SUM(m.amount_matched),0) >= ABS(l.amount) - 0.01
      )
");
// simpler unreconciled count
$st = $conn->prepare('
    SELECT l.id, l.amount,
      (SELECT COALESCE(SUM(m2.amount_matched),0) FROM co_reconciliation_matches m2
       WHERE m2.bank_statement_line_id = l.id AND m2.status IN (\'proposed\',\'confirmed\')) AS matched_sum
    FROM co_bank_statement_lines l
    WHERE l.bank_account_id = ? AND l.company_id = ? AND l.txn_date <= ?
');
$st->execute([$bank_id, $cid, $as_of]);
$unreconciled = 0;
$suggested = 0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $rem = max(0, round(abs((float) $row['amount']) - (float) $row['matched_sum'], 2));
    if ($rem > 0.009) {
        $unreconciled++;
    }
}
$prop = $conn->prepare("
    SELECT COUNT(DISTINCT l.id) FROM co_bank_statement_lines l
    JOIN co_reconciliation_matches m ON m.bank_statement_line_id = l.id AND m.status = 'proposed'
    WHERE l.bank_account_id = ? AND l.company_id = ?
");
$prop->execute([$bank_id, $cid]);
$suggested = (int) $prop->fetchColumn();

echo json_encode([
    'success' => true,
    'statement_balance' => $stmtBalance,
    'erp_balance' => $erpBalance,
    'difference' => $stmtBalance !== null ? co_bank_reco_money($erpBalance - $stmtBalance) : null,
    'unreconciled_count' => $unreconciled,
    'suggested_count' => $suggested,
    'account_code' => $bank['account_code'],
    'bank_name' => $bank['account_name'] ?? $bank['coa_name'] ?? '',
]);
