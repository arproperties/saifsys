<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);
co_bank_reco_guard($conn, 'construction.bank_reconciliation.import');

if (!co_bank_reco_csrf_ok()) {
    co_bank_reco_json_error('CSRF token invalid or missing.');
}

$bank_id = (int) ($_POST['bank_account_id'] ?? 0);
$preview = $_SESSION['co_bank_import_preview'] ?? null;
if (!$preview || (int) ($preview['bank_account_id'] ?? 0) !== $bank_id) {
    co_bank_reco_json_error('Import preview expired. Upload the file again.');
}
if (!co_bank_verify_account($conn, $bank_id, $cid)) {
    co_bank_reco_json_error('Invalid bank account');
}

$result = co_bank_rec_import_confirm(
    $conn,
    $cid,
    $bank_id,
    (string) ($preview['file_name'] ?? 'import.csv'),
    $preview['rows'] ?? [],
    current_user_id()
);
unset($_SESSION['co_bank_import_preview']);

if (!$result['success']) {
    co_bank_reco_json_error($result['error'] ?? 'Import failed');
}

$autoRec = ['enabled' => false, 'confirmed' => 0];
if ($result['batch_id']) {
    require_once __DIR__ . '/../includes/construction_bank_reco_settings.php';
    $st = $conn->prepare('SELECT MIN(txn_date) AS dfrom, MAX(txn_date) AS dto FROM co_bank_statement_lines WHERE import_batch_id = ? AND company_id = ?');
    $st->execute([(int) $result['batch_id'], $cid]);
    $range = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($range['dfrom']) && !empty($range['dto'])) {
        $autoRec = co_bank_reco_auto_reconcile_with_rules($conn, $cid, $bank_id, $range['dfrom'], $range['dto'], current_user_id());
    }
}

echo json_encode([
    'success' => true,
    'batch_id' => $result['batch_id'],
    'inserted' => $result['inserted'],
    'auto_reconcile_enabled' => $autoRec['enabled'],
    'auto_confirmed_count' => $autoRec['confirmed'],
]);
