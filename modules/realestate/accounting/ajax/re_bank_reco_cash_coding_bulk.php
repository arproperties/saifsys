<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
require_once __DIR__ . '/../../includes/re_bank_reco_posting.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.cash_coding');

if (!re_bank_reco_csrf_ok()) {
    re_bank_reco_json_error('CSRF token invalid or missing.');
}

$rowsJson = (string) ($_POST['rows'] ?? '[]');
$rows = json_decode($rowsJson, true);
if (!is_array($rows) || !$rows) {
    re_bank_reco_json_error('No rows selected');
}

$result = re_bank_reco_cash_coding_bulk($conn, $cid, $rows, current_user_id() ?: null);
echo json_encode([
    'success' => $result['success'],
    'processed' => $result['processed'],
    'failed' => $result['failed'],
    'error' => $result['error'],
]);
