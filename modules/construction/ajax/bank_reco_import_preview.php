<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);
co_bank_reco_guard($conn, 'construction.bank_reconciliation.import');

if (!co_bank_reco_csrf_ok()) {
    co_bank_reco_json_error('CSRF token invalid or missing.');
}

$bank_id = (int) ($_POST['bank_account_id'] ?? 0);
if ($bank_id <= 0 || empty($_FILES['file']['tmp_name'])) {
    co_bank_reco_json_error('bank_account_id and file required');
}
if (!co_bank_verify_account($conn, $bank_id, $cid)) {
    co_bank_reco_json_error('Invalid bank account');
}

$path = $_FILES['file']['tmp_name'];
$name = (string) ($_FILES['file']['name'] ?? 'import.csv');
if (!is_uploaded_file($path)) {
    co_bank_reco_json_error('Upload failed');
}

$parsed = co_bank_parse_statement_upload($path, $name);
if ($parsed['error']) {
    co_bank_reco_json_error($parsed['error']);
}

$preview = co_bank_rec_import_preview($conn, $cid, $bank_id, $parsed['rows'], $name);
$_SESSION['co_bank_import_preview'] = [
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
