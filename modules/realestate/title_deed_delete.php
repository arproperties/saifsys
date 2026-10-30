<?php
/**
 * Real Estate Module - Delete Title Deed
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

$titleDeedId = !empty($_POST['id']) ? (int)$_POST['id'] : 0;

if ($titleDeedId) {
    $stmt = $conn->prepare("SELECT id, file_path FROM re_title_deeds WHERE id = ? AND company_id = ? AND building_id = ?");
    $stmt->execute([$titleDeedId, $currentCompanyId, $buildingId]);
    $titleDeed = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($titleDeed) {
        $conn->prepare("DELETE FROM re_title_deeds WHERE id = ? AND company_id = ?")->execute([$titleDeedId, $currentCompanyId]);

        if (!empty($titleDeed['file_path'])) {
            $projectRoot = realpath(__DIR__ . '/../../');
            $rel = ltrim((string)$titleDeed['file_path'], '/');
            $real = realpath($projectRoot . '/' . $rel);
            $uploadsRoot = realpath($projectRoot . '/uploads');
            if ($real && $uploadsRoot && strpos($real, $uploadsRoot) === 0 && is_file($real)) {
                @unlink($real);
            }
        }

        $_SESSION['flash_success'] = 'Title Deed deleted.';
    } else {
        $_SESSION['flash_error'] = 'Title deed not found.';
    }
}

header('Location: building_view.php?id=' . $buildingId);
exit;
