<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
require_once __DIR__ . '/../../includes/re_bank_reco_posting.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.create_transaction');

if (!re_bank_reco_csrf_ok()) {
    re_bank_reco_json_error('CSRF token invalid or missing.');
}

$line_id = (int) ($_POST['line_id'] ?? 0);
$transaction_type = trim((string) ($_POST['transaction_type'] ?? 'quick_expense'));
$account_id = (int) ($_POST['account_id'] ?? 0);
$description = trim((string) ($_POST['description'] ?? ''));
$reference = trim((string) ($_POST['reference'] ?? ''));
$contactRaw = trim((string) ($_POST['contact'] ?? ''));
$contactType = null;
$contactId = null;
if ($contactRaw !== '' && str_contains($contactRaw, ':')) {
    [$contactType, $contactIdRaw] = explode(':', $contactRaw, 2);
    $contactType = in_array($contactType, ['tenant', 'vendor'], true) ? $contactType : null;
    $contactId = $contactType ? (int) $contactIdRaw : null;
}
$vatTreatment = trim((string) ($_POST['vat_treatment'] ?? 'none'));
if (!in_array($vatTreatment, ['none', 'standard', 'exempt', 'zero_rated', 'out_of_scope'], true)) {
    $vatTreatment = 'none';
}
$vatRate = isset($_POST['vat_rate']) && $_POST['vat_rate'] !== '' ? (float) $_POST['vat_rate'] : null;

$line = re_bank_get_statement_line($conn, $line_id, $cid);
if (!$line) {
    re_bank_reco_json_error('Statement line not found');
}
if ($description === '') {
    $description = (string) ($line['description'] ?? 'Bank reconciliation entry');
}

$result = re_bank_reco_create_transaction(
    $conn,
    $cid,
    $line,
    $transaction_type,
    $account_id,
    $description,
    $reference !== '' ? $reference : null,
    current_user_id(),
    $contactType,
    $contactId,
    'create',
    $vatTreatment,
    $vatRate
);

if (!$result['success']) {
    re_bank_reco_json_error($result['error'] ?? 'Create failed');
}

echo json_encode([
    'success' => true,
    'journal_id' => $result['journal_id'],
    'match_id' => $result['match_id'] ?? null,
]);
