<?php
/**
 * POST only. Move a receipt's parked tenant credit onto the lease's security deposit, or undo it.
 * Opened from Receipt Allocation; returns there with a message.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/credit_to_deposit_helper.php';

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
// Accounts staff, Owner and Admin only (Owner/Admin pass this check).
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    $flash(false, 'Only accounts staff can move tenant credit.');
}

$companyId = current_company_id($conn) ?: 1;
$userId = current_user_id();
$action = (string)($_POST['action'] ?? '');

if ($action === 'apply') {
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $result = re_ctd_apply($conn, $companyId, $leaseId, $paymentId, $amount, $userId);
    if (!empty($result['success'])) {
        $flash(true, 'AED ' . number_format((float)$result['applied'], 2) . ' moved from tenant credit to the security deposit.');
    }
    $flash(false, 'Not moved: ' . ($result['error'] ?? 'Unknown error'));
}

if ($action === 'undo') {
    $allocationId = (int)($_POST['allocation_id'] ?? 0);
    $result = re_ctd_undo($conn, $companyId, $leaseId, $allocationId, $userId);
    if (!empty($result['success'])) {
        $flash(true, 'Undone: AED ' . number_format((float)$result['restored'], 2) . ' is tenant credit again.');
    }
    $flash(false, 'Not undone: ' . ($result['error'] ?? 'Unknown error'));
}

$flash(false, 'Unknown action.');
