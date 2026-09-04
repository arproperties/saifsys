<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);
co_bank_reco_guard($conn, 'construction.bank_reconciliation.match');

if (!co_bank_reco_csrf_ok()) {
    co_bank_reco_json_error('CSRF token invalid or missing.');
}

$bank_id = (int) ($_POST['bank_account_id'] ?? 0);
$from = trim($_POST['date_from'] ?? '');
$to = trim($_POST['date_to'] ?? '');

if ($bank_id <= 0 || $from === '' || $to === '') {
    co_bank_reco_json_error('bank_account_id, date_from, date_to required');
}

if (!co_bank_verify_account($conn, $bank_id, $cid)) {
    co_bank_reco_json_error('Invalid bank account');
}

require_once __DIR__ . '/../includes/construction_bank_reco_engine.php';
require_once __DIR__ . '/../includes/construction_bank_reco_settings.php';

try {
    $uid = current_user_id();
    $result = co_bank_reco_auto_propose($conn, $cid, $bank_id, $from, $to, $uid ?: null);
    $autoRec = co_bank_reco_auto_reconcile_with_rules($conn, $cid, $bank_id, $from, $to, $uid ?: null);
    echo json_encode([
        'success' => true,
        'proposed_count' => $result['proposed'],
        'skipped' => $result['skipped'],
        'auto_reconcile_enabled' => $autoRec['enabled'],
        'auto_confirmed_count' => $autoRec['confirmed'],
        'auto_skipped' => $autoRec['skipped'],
    ]);
} catch (Throwable $e) {
    error_log('co bank_reco_auto_match: ' . $e->getMessage());
    co_bank_reco_json_error($e->getMessage());
}
