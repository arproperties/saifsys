<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);
co_bank_reco_guard($conn, 'construction.bank_reconciliation.rules');

if (!co_bank_reco_csrf_ok()) {
    co_bank_reco_json_error('CSRF token invalid or missing.');
}

require_once __DIR__ . '/../includes/construction_bank_reco_rules.php';

$ruleId = (int) ($_POST['rule_id'] ?? 0);
if ($ruleId <= 0) {
    co_bank_reco_json_error('rule_id required');
}

$result = co_bank_rule_delete($conn, $ruleId, $cid, current_user_id() ?: null);
echo json_encode(['success' => $result['success'], 'error' => $result['error']]);
