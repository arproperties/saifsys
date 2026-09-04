<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.view');

$bank_id = (int) ($_GET['bank_account_id'] ?? 0);
$as_of = trim($_GET['as_of'] ?? date('Y-m-d'));
if ($bank_id <= 0) {
    re_bank_reco_json_error('bank_account_id required');
}

$bank = re_bank_verify_account($conn, $bank_id, $cid);
if (!$bank) {
    re_bank_reco_json_error('Invalid bank account');
}

$glId = (int) $bank['gl_account_id'];
$erpBalance = re_bank_erp_balance($conn, $glId, $cid, $as_of);
$stmtBalance = re_bank_statement_balance($conn, $bank_id, $cid, $as_of);

$st = $conn->prepare('
    SELECT l.id, l.net_amount,
      (SELECT COALESCE(SUM(m2.matched_amount),0) FROM re_bank_reconciliation_matches m2
       WHERE m2.statement_line_id = l.id AND m2.status = \'confirmed\') AS matched_sum
    FROM re_bank_statement_lines l
    WHERE l.bank_account_id = ? AND l.company_id = ? AND l.statement_date <= ?
');
$st->execute([$bank_id, $cid, $as_of]);
$unreconciled = 0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $rem = max(0, round(abs((float) $row['net_amount']) - (float) $row['matched_sum'], 2));
    if ($rem > 0.009) {
        $unreconciled++;
    }
}

echo json_encode([
    'success' => true,
    'statement_balance' => $stmtBalance,
    'erp_balance' => $erpBalance,
    'difference' => $stmtBalance !== null ? re_bank_rec_money($erpBalance - $stmtBalance) : null,
    'unreconciled_count' => $unreconciled,
    'suggested_count' => 0,
    'account_code' => $bank['account_code'],
    'bank_name' => $bank['account_name'] ?? $bank['coa_name'] ?? '',
]);
