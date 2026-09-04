<?php
/**
 * Construction Module — Contractor Payment (RETIRED)
 * Redirects to Supplier Payment Workspace. No new co_contractor_payments.
 * BR-CO-BP-003
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_contractor_supplier_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
require_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_FINANCIAL, $conn);

$cid = current_company_id($conn);
if (!$cid) {
    http_response_code(403);
    die('Company context required.');
}

// Deny any POST — old payment system is retired
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(410);
    die('Contractor Payments are retired. Use Supplier Payments.');
}

$pcId = (int)($_GET['project_contractor_id'] ?? 0);
$contractorId = (int)($_GET['contractor_id'] ?? 0);
$projectId = (int)($_GET['project_id'] ?? 0);

if ($pcId > 0) {
    $redir = co_project_contractor_payment_redirect($conn, $cid, $pcId);
    if (!empty($redir['url'])) {
        header('Location: ' . $redir['url']);
        exit;
    }
    if (!empty($redir['contractor_id'])) {
        header('Location: contractor_view.php?id=' . (int)$redir['contractor_id'] . '&need_supplier_link=1');
        exit;
    }
}

if ($contractorId > 0) {
    $url = co_contractor_payment_workspace_url($conn, $cid, $contractorId, $projectId > 0 ? $projectId : null);
    if ($url) {
        header('Location: ' . $url);
        exit;
    }
    header('Location: contractor_view.php?id=' . $contractorId . '&need_supplier_link=1');
    exit;
}

header('Location: supplier_payments.php');
exit;
