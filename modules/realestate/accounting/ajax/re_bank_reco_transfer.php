<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.transfer');

if (!re_bank_reco_csrf_ok()) {
    re_bank_reco_json_error('CSRF token invalid or missing.');
}

$line_id = (int) ($_POST['line_id'] ?? 0);
$to_bank_id = (int) ($_POST['to_bank_account_id'] ?? 0);
$reference = trim((string) ($_POST['reference'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));

if ($line_id <= 0 || $to_bank_id <= 0) {
    re_bank_reco_json_error('line_id and to_bank_account_id required');
}

require_once __DIR__ . '/../../includes/re_bank_reco_posting.php';

try {
    $line = re_bank_get_statement_line($conn, $line_id, $cid);
    if (!$line) {
        re_bank_reco_json_error('Statement line not found');
    }
    if (re_bank_line_remaining($conn, $line) <= 0.009) {
        re_bank_reco_json_error('Statement line already reconciled');
    }

    $uid = current_user_id();
    $result = re_bank_reco_transfer($conn, $cid, $line, $to_bank_id, $reference, $description, $uid ?: null);
    echo json_encode([
        'success' => $result['success'],
        'journal_id' => $result['journal_id'],
        'match_ids' => $result['match_ids'],
        'paired_line_id' => $result['paired_line_id'],
        'error' => $result['error'],
    ]);
} catch (Throwable $e) {
    re_bank_reco_json_error($e->getMessage());
}
