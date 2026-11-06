<?php
/**
 * Real Estate Module - Delete Affection Plan / Site Plan
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$currentCompanyId = current_company_id($conn) ?: 1;

$buildingId = !empty($_POST['building_id']) ? (int)$_POST['building_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$buildingId) {
    header('Location: buildings.php');
    exit;
}

csrf_verify();

$planId = !empty($_POST['id']) ? (int)$_POST['id'] : 0;

if ($planId) {
    $stmt = $conn->prepare("SELECT id, file_path FROM re_affection_site_plans WHERE id = ? AND company_id = ? AND building_id = ?");
    $stmt->execute([$planId, $currentCompanyId, $buildingId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($plan) {
        $conn->prepare("DELETE FROM re_affection_site_plans WHERE id = ? AND company_id = ?")->execute([$planId, $currentCompanyId]);

        if (!empty($plan['file_path'])) {
            $projectRoot = realpath(__DIR__ . '/../../');
            $rel = ltrim((string)$plan['file_path'], '/');
            $real = realpath($projectRoot . '/' . $rel);
            $uploadsRoot = realpath($projectRoot . '/uploads');
            if ($real && $uploadsRoot && strpos($real, $uploadsRoot) === 0 && is_file($real)) {
                @unlink($real);
            }
        }

        $_SESSION['flash_success'] = 'Deleted.';
    } else {
        $_SESSION['flash_error'] = 'Record not found.';
    }
}

header('Location: building_view.php?id=' . $buildingId);
exit;
