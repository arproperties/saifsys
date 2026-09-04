<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.undo');

if (!re_bank_reco_csrf_ok()) {
    re_bank_reco_json_error('CSRF token invalid or missing.');
}

$match_id = (int) ($_POST['match_id'] ?? 0);
$reverse_created = !isset($_POST['reverse_created']) || (string) $_POST['reverse_created'] !== '0';

if ($match_id <= 0) {
    re_bank_reco_json_error('match_id required');
}

try {
    $uid = current_user_id();
    $result = re_bank_reco_undo_match($conn, $cid, $match_id, $uid ?: null, $reverse_created);
    echo json_encode([
        'success' => $result['success'],
        'reversed' => $result['reversed'],
        'voided_match_ids' => $result['voided_match_ids'],
        'error' => $result['error'],
    ]);
} catch (Throwable $e) {
    re_bank_reco_json_error($e->getMessage());
}
