<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);

$bank_id = (int) ($_GET['bank_account_id'] ?? 0);
$from = trim($_GET['date_from'] ?? '');
$to = trim($_GET['date_to'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

if ($bank_id <= 0 || $from === '' || $to === '') {
    re_bank_reco_json_error('Missing parameters');
}

if (!re_bank_verify_account($conn, $bank_id, $cid)) {
    re_bank_reco_json_error('Invalid bank account');
}

// Cap result size, but apply status/open filter in SQL first.
// Previously LIMIT ran before filtering matched lines, so early unmatched
// dates disappeared on large date ranges (while short ranges still showed them).
$limit = 2000;

$sql = "
    SELECT x.*
    FROM (
        SELECT l.*,
          (SELECT COALESCE(SUM(m.matched_amount), 0)
             FROM re_bank_reconciliation_matches m
            WHERE m.statement_line_id = l.id
              AND m.company_id = l.company_id
              AND m.status = 'confirmed') AS matched_sum
        FROM re_bank_statement_lines l
        WHERE l.bank_account_id = ?
          AND l.company_id = ?
          AND l.statement_date BETWEEN ? AND ?
    ) x
";
if ($statusFilter === 'open') {
    // Keep lines with remaining amount to match (same rule as PHP remaining check).
    $sql .= ' WHERE ABS(ABS(x.net_amount) - x.matched_sum) > 0.009 ';
}
$sql .= ' ORDER BY x.statement_date DESC, x.id DESC LIMIT ' . (int) $limit;

$st = $conn->prepare($sql);
$st->execute([$bank_id, $cid, $from, $to]);
$rows = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $r['amount'] = (float) $r['net_amount'];
    $r['txn_date'] = (string) $r['statement_date'];
    $r['matched_sum'] = round((float) $r['matched_sum'], 2);
    $r['remaining'] = round(max(0, abs((float) $r['net_amount']) - $r['matched_sum']), 2);
    $r['is_fully_matched'] = $r['remaining'] <= 0.009;
    $r['status'] = re_bank_reco_line_display_status($conn, $r);
    if ($statusFilter === 'open' && $r['is_fully_matched']) {
        continue;
    }
    $rows[] = $r;
}

echo json_encode([
    'success' => true,
    'lines' => $rows,
    'truncated' => count($rows) >= $limit,
    'limit' => $limit,
]);
