<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.rules');

if (!re_bank_reco_csrf_ok()) {
    re_bank_reco_json_error('CSRF token invalid or missing.');
}

require_once __DIR__ . '/../../includes/re_bank_reco_rules.php';

$ruleId = (int) ($_POST['rule_id'] ?? 0);
if ($ruleId <= 0) {
    re_bank_reco_json_error('rule_id required');
}

$result = re_bank_rule_delete($conn, $ruleId, $cid, current_user_id() ?: null);
echo json_encode([
    'success' => $result['success'],
    'error' => $result['error'],
]);
