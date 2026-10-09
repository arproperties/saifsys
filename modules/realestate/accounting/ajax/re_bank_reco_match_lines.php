<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.match');

if (!re_bank_reco_csrf_ok()) {
    re_bank_reco_json_error('CSRF token invalid or missing.');
}

// A bank charge and its VAT line reconciled against one ERP ledger entry.
$lineIds = array_filter(array_map('intval', explode(',', (string) ($_POST['line_ids'] ?? ''))));
$glLineId = (int) ($_POST['system_id'] ?? 0);
if ($glLineId <= 0) {
    re_bank_reco_json_error('ERP transaction required');
}

require_once __DIR__ . '/../../includes/re_bank_reco_engine.php';

try {
    $uid = current_user_id();
    $result = re_bank_reco_match_lines_to_gl($conn, $cid, $lineIds, $glLineId, $uid ?: null);
    echo json_encode([
        'success' => $result['success'],
        'confirmed' => $result['confirmed'],
        'error' => $result['error'],
    ]);
} catch (Throwable $e) {
    re_bank_reco_json_error($e->getMessage());
}
