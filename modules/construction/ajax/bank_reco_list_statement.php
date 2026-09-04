<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);

$bank_id = (int) ($_GET['bank_account_id'] ?? 0);
$from = trim($_GET['date_from'] ?? '');
$to = trim($_GET['date_to'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

if ($bank_id <= 0 || $from === '' || $to === '') {
    co_bank_reco_json_error('Missing parameters');
}

if (!co_bank_verify_account($conn, $bank_id, $cid)) {
    co_bank_reco_json_error('Invalid bank account');
}

$st = $conn->prepare("
    SELECT l.*,
      (SELECT COALESCE(SUM(m.amount_matched),0) FROM co_reconciliation_matches m
       WHERE m.bank_statement_line_id = l.id AND m.status IN ('proposed','confirmed')) AS matched_sum
    FROM co_bank_statement_lines l
    WHERE l.bank_account_id = ? AND l.company_id = ? AND l.txn_date BETWEEN ? AND ?
    ORDER BY l.txn_date DESC, l.id DESC
    LIMIT 500
");
$st->execute([$bank_id, $cid, $from, $to]);
$rows = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $r['matched_sum'] = round((float) $r['matched_sum'], 2);
    $r['remaining'] = round(max(0, abs((float) $r['amount']) - $r['matched_sum']), 2);
    $r['is_fully_matched'] = $r['remaining'] <= 0.009;
    if (co_db_column_exists($conn, 'co_bank_statement_lines', 'status')) {
        if ($r['is_fully_matched']) {
            $r['status'] = 'reconciled';
        }
    } else {
        $r['status'] = $r['is_fully_matched'] ? 'reconciled' : 'unreconciled';
    }
    if ($statusFilter === 'open' && $r['is_fully_matched']) {
        continue;
    }
    $rows[] = $r;
}

echo json_encode(['success' => true, 'lines' => $rows]);
