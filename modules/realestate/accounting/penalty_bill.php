<?php
/**
 * POST only. Make the invoice for a bounce / late fee that was added before fees were
 * billed automatically, so Receipt Allocation can collect it.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/penalty_invoice_helper.php';

require_login();

$leaseId = (int)($_POST['lease_id'] ?? 0);
$redirect = 'receipt_allocation.php' . ($leaseId > 0 ? '?lease_id=' . $leaseId : '');
$flash = static function (bool $ok, string $message) use ($redirect): void {
    $_SESSION['re_receipt_allocation_flash'] = ['success' => $ok, 'message' => $message];
    header('Location: ' . $redirect);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $flash(false, 'This action needs POST.');
}
if (!csrf_verify(false)) {
    $flash(false, 'Invalid CSRF token. Nothing was changed.');
}
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    $flash(false, 'Only accounts staff can bill fees.');
}

$companyId = current_company_id($conn) ?: 1;
$fees = re_penalty_unbilled_for_lease($conn, $companyId, $leaseId);
if ($fees === []) {
    $flash(false, 'No unbilled fees on this lease.');
}

$billed = [];
$pending = 0;
$errors = [];
foreach ($fees as $fee) {
    $result = re_penalty_make_payable($conn, $companyId, $leaseId, (int)$fee['id'], current_user_id());
    if (empty($result['success'])) {
        $errors[] = (string)$fee['item_name'] . ' (' . (string)$fee['due_date'] . '): ' . ($result['error'] ?? 'Unknown error');
    } elseif (!empty($result['skipped'])) {
        $flash(false, 'This lease is not in Invoice Mode.');
    } elseif (!empty($result['pending'])) {
        $pending++;
    } else {
        $billed[] = (string)($result['invoice_number'] ?? '');
    }
}

$message = $billed ? 'Billed: ' . implode(', ', $billed) . '. Allocate the receipt to them below.' : '';
if ($pending) {
    $message .= ' ' . $pending . ' fee(s) will be invoiced on their due date.';
}
if ($errors) {
    $message .= ' Not billed: ' . implode(' | ', $errors);
}
$flash($errors === [], trim($message));
