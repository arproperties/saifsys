<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);

$bank_id = (int) ($_GET['bank_account_id'] ?? 0);
$from = trim($_GET['date_from'] ?? '');
$to = trim($_GET['date_to'] ?? '');

if ($bank_id <= 0 || $from === '' || $to === '') {
    co_bank_reco_json_error('Missing parameters');
}

$bank = co_bank_verify_account($conn, $bank_id, $cid);
if (!$bank) {
    co_bank_reco_json_error('Invalid bank account');
}

$gl_account_id = (int) $bank['gl_account_id'];
$inflows = [];
$outflows = [];

$st = $conn->prepare("
    SELECT gl.id, gl.entry_date, gl.debit_amount, gl.credit_amount, gl.description, gl.reference
    FROM re_general_ledger gl
    WHERE gl.account_id = ? AND gl.company_id = ? AND gl.entry_date BETWEEN ? AND ?
      AND gl.debit_amount > 0
    ORDER BY gl.entry_date DESC, gl.id DESC
    LIMIT 500
");
$st->execute([$gl_account_id, $cid, $from, $to]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $gid = (int) $r['id'];
    $amt = round((float) $r['debit_amount'], 2);
    $matched = co_gl_entry_matched_sum($conn, $gid);
    $remaining = round(max(0, $amt - $matched), 2);
    if ($remaining <= 0.009) {
        continue;
    }
    $inflows[] = [
        'system_type' => 'gl_inflow',
        'system_id' => $gid,
        'txn_date' => $r['entry_date'],
        'amount' => $amt,
        'remaining' => $remaining,
        'reference' => $r['reference'] ?? '',
        'counterparty' => $r['description'] ?? '',
        'detail' => 'GL debit #' . $gid,
    ];
}

$st = $conn->prepare("
    SELECT gl.id, gl.entry_date, gl.debit_amount, gl.credit_amount, gl.description, gl.reference
    FROM re_general_ledger gl
    WHERE gl.account_id = ? AND gl.company_id = ? AND gl.entry_date BETWEEN ? AND ?
      AND gl.credit_amount > 0
    ORDER BY gl.entry_date DESC, gl.id DESC
    LIMIT 500
");
$st->execute([$gl_account_id, $cid, $from, $to]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $gid = (int) $r['id'];
    $amt = round((float) $r['credit_amount'], 2);
    $matched = co_gl_entry_matched_sum($conn, $gid);
    $remaining = round(max(0, $amt - $matched), 2);
    if ($remaining <= 0.009) {
        continue;
    }
    $outflows[] = [
        'system_type' => 'gl_outflow',
        'system_id' => $gid,
        'txn_date' => $r['entry_date'],
        'amount' => $amt,
        'remaining' => $remaining,
        'reference' => $r['reference'] ?? '',
        'counterparty' => $r['description'] ?? '',
        'detail' => 'GL credit #' . $gid,
    ];
}

echo json_encode([
    'success' => true,
    'inflows' => $inflows,
    'outflows' => $outflows,
    'account_code' => $bank['account_code'],
]);
