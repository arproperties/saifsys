<?php
/**
 * Phase 4 Receipt Allocation Confirmation.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/receipt_allocation_engine.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$leaseId = (int)($_POST['lease_id'] ?? 0);
$redirect = 'receipt_allocation.php' . ($leaseId > 0 ? '?lease_id=' . $leaseId : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['re_receipt_allocation_flash'] = ['success' => false, 'message' => 'Receipt confirmation requires POST.'];
    header('Location: ' . $redirect);
    exit;
}

if (!csrf_verify(false)) {
    $_SESSION['re_receipt_allocation_flash'] = ['success' => false, 'message' => 'Invalid CSRF token. No receipt was created.'];
    header('Location: ' . $redirect);
    exit;
}

$companyId = current_company_id($conn) ?: 1;
$amount = (float)($_POST['amount'] ?? 0);
$receiptAccountRaw = (string)($_POST['receipt_account'] ?? '');
$receiptAccountType = (string)($_POST['receipt_account_type'] ?? '');
$receiptAccountId = !empty($_POST['receipt_account_id']) ? (int)$_POST['receipt_account_id'] : null;
if (preg_match('/^(bank|cash|card_clearing):(\d+)$/', $receiptAccountRaw, $accountMatch)) {
    $receiptAccountType = $accountMatch[1];
    $receiptAccountId = (int)$accountMatch[2];
}
$data = [
    'amount' => $amount,
    'receipt_source' => (string)($_POST['receipt_source'] ?? ''),
    'cleared_date' => (string)($_POST['cleared_date'] ?? date('Y-m-d')),
    'receipt_account_type' => $receiptAccountType,
    'receipt_account_id' => $receiptAccountId,
    'reference_number' => (string)($_POST['reference_number'] ?? ''),
    'notes' => (string)($_POST['notes'] ?? ''),
    'cheque_id' => !empty($_POST['cheque_id']) ? (int)$_POST['cheque_id'] : null,
];
$allocations = is_array($_POST['allocation'] ?? null) ? $_POST['allocation'] : [];

$query = http_build_query([
    'lease_id' => $leaseId,
    'amount' => $amount,
    'receipt_source' => $data['receipt_source'],
    'cleared_date' => $data['cleared_date'],
    'receipt_account' => (string)($_POST['receipt_account'] ?? ''),
    'reference_number' => $data['reference_number'],
    'notes' => $data['notes'],
    'cheque_id' => $data['cheque_id'],
]);
$redirect = 'receipt_allocation.php?' . $query;

$result = re_receipt_confirm($conn, $companyId, $leaseId, $data, $allocations, current_user_id());
if (!empty($result['success'])) {
    $creditApply = re_receipt_apply_available_tenant_credit(
        $conn,
        $companyId,
        $leaseId,
        current_user_id()
    );
    $message = sprintf(
        'Receipt %s created. Allocated AED %.2f; tenant credit from this receipt AED %.2f.',
        (string)$result['receipt_number'],
        (float)$result['allocated'],
        (float)$result['tenant_credit']
    );
    if (!empty($creditApply['applied']) && (float)$creditApply['applied'] > 0.005) {
        $nums = $creditApply['invoice_numbers'] ?? [];
        $numLabel = is_array($nums) && $nums !== []
            ? (' (' . implode(', ', array_map('strval', $nums)) . ')')
            : '';
        $message .= sprintf(
            ' Automatically applied AED %.2f from tenant credit to fully clear %d invoice(s)%s (AR adjustment only — receipt/cheque/bank unchanged).',
            (float)$creditApply['applied'],
            (int)($creditApply['invoice_count'] ?? 0),
            $numLabel
        );
    }
    if (!empty($creditApply['remaining_credit']) && (float)$creditApply['remaining_credit'] > 0.005) {
        $message .= sprintf(
            ' Tenant credit AED %.2f remains parked for a future full invoice clear (not chipped into open months).',
            (float)$creditApply['remaining_credit']
        );
    }
    if (!empty($creditApply['error'])) {
        $message .= ' Tenant credit auto-apply note: ' . (string)$creditApply['error'];
    }
    $_SESSION['success'] = $message;
    $_SESSION['re_receipt_allocation_flash'] = [
        'success' => true,
        'message' => $message,
    ];
    try {
        require_once __DIR__ . '/../../../includes/audit_bridge.php';
        $receiptId = (int)($result['receipt_id'] ?? 0);
        $receiptNo = (string)($result['receipt_number'] ?? ('Receipt #' . $receiptId));
        audit_bridge_re_ops(
            $conn,
            (int)$companyId,
            'allocate',
            're_receipts',
            $receiptId > 0 ? $receiptId : $leaseId,
            $receiptNo,
            $message,
            null,
            [
                'lease_id' => (int)$leaseId,
                'allocated' => (float)($result['allocated'] ?? 0),
                'tenant_credit' => (float)($result['tenant_credit'] ?? 0),
            ],
            current_user_id()
        );
    } catch (Throwable $e) {
        error_log('receipt_allocation_confirm audit: ' . $e->getMessage());
    }
    header('Location: ../lease_view.php?id=' . $leaseId);
    exit;
} else {
    $_SESSION['re_receipt_allocation_flash'] = [
        'success' => false,
        'message' => (string)($result['error'] ?? 'Could not confirm receipt allocation.'),
    ];
}

header('Location: ' . $redirect);
exit;

