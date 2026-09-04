<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.rules');

require_once __DIR__ . '/../../includes/re_bank_reco_settings.php';

echo json_encode([
    'success' => true,
    'settings' => re_bank_reco_get_settings($conn, $cid),
]);
