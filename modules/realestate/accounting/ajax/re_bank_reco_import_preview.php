<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.import');

if (!re_bank_reco_csrf_ok()) {
    re_bank_reco_json_error('CSRF token invalid or missing.');
}

$bank_id = (int) ($_POST['bank_account_id'] ?? 0);
if ($bank_id <= 0 || empty($_FILES['file']['tmp_name'])) {
    re_bank_reco_json_error('bank_account_id and file required');
}
if (!re_bank_verify_account($conn, $bank_id, $cid)) {
    re_bank_reco_json_error('Invalid bank account');
}

$path = $_FILES['file']['tmp_name'];
$name = (string) ($_FILES['file']['name'] ?? 'import.csv');
if (!is_uploaded_file($path)) {
    re_bank_reco_json_error('Upload failed');
}

$parsed = re_bank_parse_statement_upload($path, $name);
if ($parsed['error']) {
    re_bank_reco_json_error($parsed['error']);
}

$preview = re_bank_rec_import_preview($conn, $cid, $bank_id, $parsed['rows'], $name);
$_SESSION['re_bank_import_preview'] = [
    'bank_account_id' => $bank_id,
    'file_name' => $name,
    'rows' => $preview['rows'],
    'summary' => $preview['summary'],
    'created_at' => time(),
];

echo json_encode([
    'success' => true,
    'summary' => $preview['summary'],
    'preview_rows' => array_slice($preview['rows'], 0, 50),
]);
