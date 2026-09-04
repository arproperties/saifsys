<?php
/**
 * Phase 2 obligation generation endpoint.
 *
 * POST only. The engine writes only to re_obligations and only for Invoice Mode
 * leases when the invoice-mode feature flag and Phase 2 schema are present.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/obligation_engine.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$leaseId = (int)($_POST['lease_id'] ?? 0);
$redirect = 'obligation_preview.php' . ($leaseId > 0 ? '?lease_id=' . $leaseId : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['re_obligation_generation_flash'] = [
        'success' => false,
        'message' => 'Obligation generation requires a POST request.',
    ];
    header('Location: ' . $redirect);
    exit;
}

if (!csrf_verify(false)) {
    $_SESSION['re_obligation_generation_flash'] = [
        'success' => false,
        'message' => 'Invalid CSRF token. No obligations were generated.',
    ];
    header('Location: ' . $redirect);
    exit;
}

$companyId = current_company_id($conn) ?: 1;
if ($leaseId <= 0) {
    $_SESSION['re_obligation_generation_flash'] = [
        'success' => false,
        'message' => 'Please select a valid lease before generating obligations.',
    ];
    header('Location: ' . $redirect);
    exit;
}

$result = re_obligation_engine_generate_for_lease($conn, $companyId, $leaseId, current_user_id());
if (!empty($result['success'])) {
    $stats = $result['stats'] ?? [];
    $_SESSION['re_obligation_generation_flash'] = [
        'success' => true,
        'message' => sprintf(
            'Obligations generated safely. Created: %d, updated: %d, unchanged: %d, protected skipped: %d. Obsolete removed: %d obligation(s), %d invoice(s).',
            (int)($stats['created'] ?? 0),
            (int)($stats['updated'] ?? 0),
            (int)($stats['unchanged'] ?? 0),
            (int)(($stats['protected'] ?? 0) + ($stats['obsolete_protected'] ?? 0)),
            (int)($stats['obsolete_deleted'] ?? 0),
            (int)($stats['obsolete_invoices_deleted'] ?? 0)
        ),
    ];
} else {
    $_SESSION['re_obligation_generation_flash'] = [
        'success' => false,
        'message' => (string)($result['error'] ?? 'Could not generate obligations.'),
    ];
}

header('Location: ' . $redirect);
exit;

