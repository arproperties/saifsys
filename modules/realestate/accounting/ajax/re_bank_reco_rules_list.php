<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.rules');

require_once __DIR__ . '/../../includes/re_bank_reco_rules.php';

try {
    echo json_encode(['success' => true, 'rules' => re_bank_rules_list($conn, $cid)]);
} catch (Throwable $e) {
    re_bank_reco_json_error($e->getMessage());
}
