<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.import');

if (!re_bank_reco_csrf_ok()) {
    re_bank_reco_json_error('CSRF token invalid or missing.');
}

$bank_id = (int) ($_POST['bank_account_id'] ?? 0);
$preview = $_SESSION['re_bank_import_preview'] ?? null;
if (!$preview || (int) $preview['bank_account_id'] !== $bank_id) {
    re_bank_reco_json_error('No import preview found. Upload the statement again.');
}

// Include duplicates so a re-import can backfill running_balance on existing lines.
$importRows = array_map(static function ($row) {
    unset($row['_duplicate'], $row['txn_date'], $row['amount'], $row['source_hash']);
    return $row;
}, array_values($preview['rows'] ?? []));

$result = re_bank_rec_import_lines(
    $conn,
    $cid,
    $bank_id,
    (string) $preview['file_name'],
    $importRows,
    current_user_id(),
    trim((string) ($_POST['notes'] ?? ''))
);

if (empty($result['success'])) {
    re_bank_reco_json_error($result['error'] ?? 'Import failed');
}

unset($_SESSION['re_bank_import_preview']);

echo json_encode([
    'success' => true,
    'batch_id' => (int) ($result['batch_id'] ?? 0),
    'inserted' => (int) ($result['inserted'] ?? 0),
    'duplicates' => (int) ($result['duplicates'] ?? 0),
    'balances_updated' => (int) ($result['balances_updated'] ?? 0),
]);
