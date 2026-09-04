<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);
co_bank_reco_guard($conn, 'construction.bank_reconciliation.rules');

require_once __DIR__ . '/../includes/construction_bank_reco_settings.php';

try {
    echo json_encode(['success' => true, 'settings' => co_bank_reco_get_settings($conn, $cid)]);
} catch (Throwable $e) {
    co_bank_reco_json_error($e->getMessage());
}
