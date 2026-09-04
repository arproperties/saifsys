<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.match');

if (!re_bank_reco_csrf_ok()) {
    re_bank_reco_json_error('CSRF token invalid or missing.');
}

$line_id = (int) ($_POST['line_id'] ?? 0);
$system_type = trim((string) ($_POST['system_type'] ?? ''));
$system_id = (int) ($_POST['system_id'] ?? 0);
$match_type = trim((string) ($_POST['match_type'] ?? ''));
$source_table = trim((string) ($_POST['source_table'] ?? ''));
$source_id = (int) ($_POST['source_id'] ?? 0);
$gl_line_id = (int) ($_POST['gl_line_id'] ?? 0);

$uid = current_user_id();

if ($line_id <= 0) {
    re_bank_reco_json_error('line_id required');
}

$line = re_bank_get_statement_line($conn, $line_id, $cid);
if (!$line) {
    re_bank_reco_json_error('Statement line not found');
}

if ($source_table === '' && $system_type !== '') {
    $source_table = $system_type;
}
if ($source_id <= 0 && $system_id > 0) {
    $source_id = $system_id;
}
if ($source_table === 're_general_ledger' && $gl_line_id <= 0) {
    $gl_line_id = $source_id;
}

$suggestions = re_bank_reco_suggestions_for_workbench($conn, $cid, $line_id);

if ($match_type === '') {
    foreach ($suggestions as $s) {
        if ($s['system_type'] === $source_table && (int) $s['system_id'] === ($source_table === 're_general_ledger' ? $gl_line_id : $source_id)) {
            $match_type = (string) ($s['match_type'] ?? '');
            break;
        }
    }
    if ($match_type === '') {
        $match_type = match ($source_table) {
            're_payments' => 'receipt',
            're_vendor_payments' => 'payment',
            're_general_ledger' => 'journal_line',
            default => 'payment',
        };
    }
}

$score = null;
$confidence = null;
foreach ($suggestions as $s) {
    if ($s['system_type'] === $source_table && (int) $s['system_id'] === ($source_table === 're_general_ledger' ? $gl_line_id : $source_id)) {
        $score = $s['score'];
        $confidence = $s['confidence'];
        break;
    }
}

$amount = re_bank_line_remaining($conn, $line);
$result = re_bank_rec_confirm_match(
    $conn,
    $cid,
    (int) $line['bank_account_id'],
    $line_id,
    $match_type,
    $source_table ?: null,
    $source_id > 0 ? $source_id : null,
    $gl_line_id > 0 ? $gl_line_id : null,
    $amount,
    $uid,
    '',
    $score,
    $confidence,
    'match'
);

if (empty($result['success'])) {
    re_bank_reco_json_error($result['error'] ?? 'Could not confirm match');
}

echo json_encode([
    'success' => true,
    'match_id' => (int) ($result['match_id'] ?? 0),
    'confirmed' => 1,
]);
