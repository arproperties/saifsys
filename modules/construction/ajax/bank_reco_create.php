<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
require_once __DIR__ . '/../includes/construction_bank_reco_posting.php';
co_bank_reco_require_tables($conn);
co_bank_reco_guard($conn, 'construction.bank_reconciliation.create_transaction');

if (!co_bank_reco_csrf_ok()) {
    co_bank_reco_json_error('CSRF token invalid or missing.');
}

$line_id = (int) ($_POST['line_id'] ?? 0);
$transaction_type = trim((string) ($_POST['transaction_type'] ?? 'quick_expense'));
$account_id = (int) ($_POST['account_id'] ?? 0);
$project_id = (int) ($_POST['project_id'] ?? 0) ?: null;
$description = trim((string) ($_POST['description'] ?? ''));
$reference = trim((string) ($_POST['reference'] ?? ''));
$contactRaw = trim((string) ($_POST['contact'] ?? ''));
$contactType = null;
$contactId = null;
if ($contactRaw !== '' && str_contains($contactRaw, ':')) {
    [$contactType, $contactIdRaw] = explode(':', $contactRaw, 2);
    $contactType = in_array($contactType, ['client', 'supplier', 'contractor'], true) ? $contactType : null;
    $contactId = $contactType ? (int) $contactIdRaw : null;
}
$splitsRaw = $_POST['splits'] ?? '[]';
$splits = is_string($splitsRaw) ? json_decode($splitsRaw, true) : $splitsRaw;
if (!is_array($splits)) {
    $splits = [];
}
$vatTreatment = trim((string) ($_POST['vat_treatment'] ?? 'none'));
if (!in_array($vatTreatment, ['none', 'standard', 'exempt', 'zero_rated', 'out_of_scope'], true)) {
    $vatTreatment = 'none';
}
$vatRate = isset($_POST['vat_rate']) && $_POST['vat_rate'] !== '' ? (float) $_POST['vat_rate'] : null;

$line = co_bank_get_statement_line($conn, $line_id, $cid);
if (!$line) {
    co_bank_reco_json_error('Statement line not found');
}
if ($description === '') {
    $description = (string) ($line['description'] ?? 'Bank reconciliation entry');
}

$result = co_bank_reco_create_transaction(
    $conn,
    $cid,
    $line,
    $transaction_type,
    $account_id,
    $description,
    $reference !== '' ? $reference : null,
    $project_id,
    current_user_id(),
    $splits,
    $contactType,
    $contactId,
    'create',
    $vatTreatment,
    $vatRate
);

if (!$result['success']) {
    co_bank_reco_json_error($result['error'] ?? 'Create failed');
}

echo json_encode([
    'success' => true,
    'journal_id' => $result['journal_id'],
    'match_id' => $result['match_id'] ?? null,
]);
