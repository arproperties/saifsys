<?php
/**
 * Real Estate Module - Add Tenant Issue/Violation
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $tenantId = (int)$_POST['tenant_id'];
    $leaseId = !empty($_POST['lease_id']) ? (int)$_POST['lease_id'] : null;
    $unitId = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;
    $issueType = $_POST['issue_type'] ?? 'other';
    $severity = $_POST['severity'] ?? 'medium';
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $reportedDate = $_POST['reported_date'] ?? date('Y-m-d');
    $status = $_POST['status'] ?? 'open';
    
    if ($tenantId && $title && $description) {
        $stmt = $conn->prepare("
            INSERT INTO re_tenant_issues_violations 
            (tenant_id, lease_id, unit_id, issue_type, severity, title, description, reported_date, status, reported_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tenantId, $leaseId, $unitId, $issueType, $severity, $title, $description, $reportedDate, $status, $userId]);
        
        header('Location: tenant_view.php?id=' . $tenantId);
        exit;
    }
}

header('Location: tenants.php');
exit;

