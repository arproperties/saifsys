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

$st = $conn->prepare("
    SELECT l.*,
      (SELECT COALESCE(SUM(m.amount_matched),0) FROM cleaning_reconciliation_matches m
       WHERE m.bank_statement_line_id = l.id AND m.status IN ('proposed','confirmed')) AS matched_sum
    FROM cleaning_bank_statement_lines l
    WHERE l.bank_account_id = ? AND l.txn_date BETWEEN ? AND ?
    ORDER BY l.txn_date DESC, l.id DESC
    LIMIT 500
");
$st->execute([$bank_id, $from, $to]);
$rows = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $r['matched_sum'] = round((float) $r['matched_sum'], 2);
    $r['remaining'] = round(max(0, abs((float) $r['amount']) - $r['matched_sum']), 2);
    $r['is_fully_matched'] = $r['remaining'] <= 0.009;
    $rows[] = $r;
}

echo json_encode(['success' => true, 'lines' => $rows]);
