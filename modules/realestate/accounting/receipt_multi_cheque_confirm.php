<?php
/**
 * Confirm multi-cheque Invoice Mode receipt.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/receipt_allocation_engine.php';
require_once __DIR__ . '/../includes/receipt_multi_cheque_helper.php';
require_once __DIR__ . '/../accounting/accounting_integration.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$leaseId = (int)($_POST['lease_id'] ?? 0);
$redirect = 'receipt_multi_cheque.php' . ($leaseId > 0 ? '?lease_id=' . $leaseId : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['re_multi_cheque_flash'] = ['success' => false, 'message' => 'Multi-cheque confirmation requires POST.'];
    header('Location: ' . $redirect);
    exit;
}

if (!csrf_verify(false)) {
    $_SESSION['re_multi_cheque_flash'] = ['success' => false, 'message' => 'Invalid CSRF token. No receipt was created.'];
    header('Location: ' . $redirect);
    exit;
}

$companyId = current_company_id($conn) ?: 1;
$amount = (float)($_POST['amount'] ?? 0);
$receiptAccountRaw = (string)($_POST['receipt_account'] ?? '');
$receiptAccountType = '';
$receiptAccountId = null;
if (preg_match('/^(bank|cash|card_clearing):(\d+)$/', $receiptAccountRaw, $accountMatch)) {
    $receiptAccountType = $accountMatch[1];
    $receiptAccountId = (int)$accountMatch[2];
}

$chequeIds = [];
if (!empty($_POST['cheque_ids']) && is_array($_POST['cheque_ids'])) {
    foreach ($_POST['cheque_ids'] as $cid) {
        $cid = (int)$cid;
        if ($cid > 0) {
            $chequeIds[$cid] = true;
        }
    }
}
$chequeIds = array_keys($chequeIds);

$data = [
    'amount' => $amount,
    'receipt_source' => (string)($_POST['receipt_source'] ?? 'bank_transfer'),
    'cleared_date' => (string)($_POST['cleared_date'] ?? date('Y-m-d')),
    'receipt_account_type' => $receiptAccountType,
    'receipt_account_id' => $receiptAccountId,
    'reference_number' => (string)($_POST['reference_number'] ?? ''),
    'notes' => (string)($_POST['notes'] ?? ''),
];
$allocations = is_array($_POST['allocation'] ?? null) ? $_POST['allocation'] : [];

$query = http_build_query([
    'lease_id' => $leaseId,
    'amount' => $amount,
    'receipt_source' => $data['receipt_source'],
    'cleared_date' => $data['cleared_date'],
    'receipt_account' => $receiptAccountRaw,
    'reference_number' => $data['reference_number'],
    'notes' => $data['notes'],
]);
// Re-attach selected cheques for failed redirect preview
foreach ($chequeIds as $cid) {
    $query .= '&cheque_ids[]=' . (int)$cid;
}
$redirect = 'receipt_multi_cheque.php?' . $query;

$result = re_receipt_confirm_multi_cheque(
    $conn,
    $companyId,
    $leaseId,
    $chequeIds,
    $data,
    $allocations,
    current_user_id()
);

if (!empty($result['success'])) {
    $creditApply = re_receipt_apply_available_tenant_credit($conn, $companyId, $leaseId, current_user_id());
    $shareLabel = [];
    foreach (($result['cheque_shares'] ?? []) as $cid => $shareAmt) {
        $shareLabel[] = '#' . (int)$cid . '=' . number_format((float)$shareAmt, 2);
    }
    $message = sprintf(
        'Multi-cheque receipt %s created for AED %.2f. Allocated AED %.2f; tenant credit AED %.2f. Linked cheques: %s.',
        (string)$result['receipt_number'],
        $amount,
        (float)$result['allocated'],
        (float)$result['tenant_credit'],
        $shareLabel !== [] ? implode(', ', $shareLabel) : 'n/a'
    );
    if (!empty($creditApply['applied']) && (float)$creditApply['applied'] > 0.005) {
        $message .= sprintf(
            ' Auto-applied AED %.2f credit to %d invoice(s).',
            (float)$creditApply['applied'],
            (int)($creditApply['invoice_count'] ?? 0)
        );
    }
    $_SESSION['success'] = $message;
    $_SESSION['re_multi_cheque_flash'] = ['success' => true, 'message' => $message];
    header('Location: ../lease_view.php?id=' . $leaseId);
    exit;
}

$_SESSION['re_multi_cheque_flash'] = [
    'success' => false,
    'message' => (string)($result['error'] ?? 'Could not confirm multi-cheque receipt.'),
];
header('Location: ' . $redirect);
exit;
