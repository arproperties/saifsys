<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);
co_bank_reco_guard($conn, 'construction.bank_reconciliation.rules');

require_once __DIR__ . '/../includes/construction_bank_reco_rules.php';

try {
    echo json_encode(['success' => true, 'rules' => co_bank_rules_list($conn, $cid)]);
} catch (Throwable $e) {
    co_bank_reco_json_error($e->getMessage());
}
