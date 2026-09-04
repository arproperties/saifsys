<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.rules');

if (!re_bank_reco_csrf_ok()) {
    re_bank_reco_json_error('CSRF token invalid or missing.');
}

require_once __DIR__ . '/../../includes/re_bank_reco_rules.php';

$data = [
    'id' => (int) ($_POST['id'] ?? 0),
    'rule_name' => trim((string) ($_POST['rule_name'] ?? '')),
    'direction' => trim((string) ($_POST['direction'] ?? 'spent')),
    'bank_account_id' => (int) ($_POST['bank_account_id'] ?? 0) ?: null,
    'priority' => (int) ($_POST['priority'] ?? 100),
    'is_active' => !empty($_POST['is_active']) ? 1 : 0,
    'auto_suggest' => !isset($_POST['auto_suggest']) || !empty($_POST['auto_suggest']) ? 1 : 0,
    'auto_create_draft' => !empty($_POST['auto_create_draft']) ? 1 : 0,
    'conditions' => [
        'description_contains' => trim((string) ($_POST['description_contains'] ?? '')),
        'description_equals' => trim((string) ($_POST['description_equals'] ?? '')),
        'reference_contains' => trim((string) ($_POST['reference_contains'] ?? '')),
        'amount_equals' => $_POST['amount_equals'] !== '' ? (float) $_POST['amount_equals'] : null,
        'amount_min' => $_POST['amount_min'] !== '' ? (float) $_POST['amount_min'] : null,
        'amount_max' => $_POST['amount_max'] !== '' ? (float) $_POST['amount_max'] : null,
    ],
    'action' => [
        'transaction_type' => trim((string) ($_POST['action_transaction_type'] ?? 'quick_expense')),
        'contact_type' => trim((string) ($_POST['action_contact_type'] ?? '')) ?: null,
        'contact_id' => (int) ($_POST['action_contact_id'] ?? 0) ?: null,
        'account_id' => (int) ($_POST['action_account_id'] ?? 0) ?: null,
        'description_template' => trim((string) ($_POST['description_template'] ?? '{description}')),
    ],
];

$result = re_bank_rule_save($conn, $cid, $data, current_user_id() ?: null);
echo json_encode([
    'success' => $result['success'],
    'rule_id' => $result['rule_id'],
    'error' => $result['error'],
]);
