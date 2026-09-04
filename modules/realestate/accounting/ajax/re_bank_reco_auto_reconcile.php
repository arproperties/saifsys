<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.match');

if (!re_bank_reco_csrf_ok()) {
    re_bank_reco_json_error('CSRF token invalid or missing.');
}

$bank_id = (int) ($_POST['bank_account_id'] ?? 0);
$from = trim($_POST['date_from'] ?? '');
$to = trim($_POST['date_to'] ?? '');

if ($bank_id <= 0 || $from === '' || $to === '') {
    re_bank_reco_json_error('bank_account_id, date_from, date_to required');
}

if (!re_bank_verify_account($conn, $bank_id, $cid)) {
    re_bank_reco_json_error('Invalid bank account');
}

require_once __DIR__ . '/../../includes/re_bank_reco_settings.php';

try {
    $uid = current_user_id();
    $result = re_bank_reco_auto_reconcile_with_rules($conn, $cid, $bank_id, $from, $to, $uid ?: null);
    echo json_encode([
        'success' => true,
        'enabled' => $result['enabled'],
        'confirmed_count' => $result['confirmed'],
        'skipped' => $result['skipped'],
    ]);
} catch (Throwable $e) {
    re_bank_reco_json_error($e->getMessage());
}
