<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);
co_bank_reco_guard($conn, 'construction.bank_reconciliation.rules');

if (!co_bank_reco_csrf_ok()) {
    co_bank_reco_json_error('CSRF token invalid or missing.');
}

require_once __DIR__ . '/../includes/construction_bank_reco_settings.php';

$auto = !empty($_POST['auto_reconcile_rule_matches']) && (string) $_POST['auto_reconcile_rule_matches'] !== '0';
$result = co_bank_reco_save_settings($conn, $cid, [
    'auto_reconcile_rule_matches' => $auto,
], current_user_id() ?: null);

echo json_encode([
    'success' => $result['success'],
    'settings' => co_bank_reco_get_settings($conn, $cid),
    'error' => $result['error'],
]);
