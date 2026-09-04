<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.match');

if (!re_bank_reco_csrf_ok()) {
    re_bank_reco_json_error('CSRF token invalid or missing.');
}

$line_id = (int) ($_POST['line_id'] ?? 0);
$selectionsJson = (string) ($_POST['selections'] ?? '[]');
$adjustmentJson = trim((string) ($_POST['adjustment'] ?? ''));

if ($line_id <= 0) {
    re_bank_reco_json_error('line_id required');
}

$selections = json_decode($selectionsJson, true);
if (!is_array($selections) || !$selections) {
    re_bank_reco_json_error('At least one ERP transaction must be selected');
}

$adjustment = null;
if ($adjustmentJson !== '' && $adjustmentJson !== 'null') {
    $adjustment = json_decode($adjustmentJson, true);
    if (!is_array($adjustment)) {
        re_bank_reco_json_error('Invalid adjustment payload');
    }
    if (!empty($adjustment['amount']) && (float) $adjustment['amount'] > 0) {
        re_bank_reco_guard($conn, 'realestate.bank_reconciliation.create_transaction');
    }
}

require_once __DIR__ . '/../../includes/re_bank_reco_engine.php';

try {
    $uid = current_user_id();
    $result = re_bank_reco_match_selections($conn, $cid, $line_id, $selections, $adjustment, $uid ?: null);
    echo json_encode([
        'success' => $result['success'],
        'confirmed' => $result['confirmed'],
        'error' => $result['error'],
    ]);
} catch (Throwable $e) {
    re_bank_reco_json_error($e->getMessage());
}
