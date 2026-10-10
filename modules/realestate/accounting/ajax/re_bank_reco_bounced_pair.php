<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.match');

if (!re_bank_reco_csrf_ok()) {
    re_bank_reco_json_error('CSRF token invalid or missing.');
}

require_once __DIR__ . '/../../includes/re_bank_reco_engine.php';

$line_id = (int) ($_POST['line_id'] ?? 0);
if ($line_id <= 0) {
    re_bank_reco_json_error('line_id required');
}

try {
    $uid = current_user_id();
    $result = re_bank_reco_confirm_bounced_pair($conn, $cid, $line_id, $uid ?: null);
    if (!$result['success']) {
        re_bank_reco_json_error($result['error'] ?? 'Reconcile failed');
    }
    echo json_encode(['success' => true, 'match_ids' => $result['match_ids']]);
} catch (Throwable $e) {
    error_log('re_bank_reco_bounced_pair: ' . $e->getMessage());
    re_bank_reco_json_error($e->getMessage());
}
