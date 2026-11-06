<?php
/**
 * Real Estate Module - Affection Plan / Site Plan File Server
 * Streams a stored plan file inline (view) or as an attachment (download).
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$currentCompanyId = current_company_id($conn) ?: 1;

$planId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
$mode = ($_GET['mode'] ?? 'view') === 'download' ? 'download' : 'view';

if (!$planId) {
    http_response_code(404);
    exit('Plan not found.');
}

$stmt = $conn->prepare("SELECT id, file_path, file_name, mime_type FROM re_affection_site_plans WHERE id = ? AND company_id = ?");
$stmt->execute([$planId, $currentCompanyId]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan || empty($plan['file_path'])) {
    http_response_code(404);
    exit('File not found.');
}

$projectRoot = realpath(__DIR__ . '/../../');
$rel = ltrim((string)$plan['file_path'], '/');

$candidates = [
    $projectRoot . '/' . $rel,
    __DIR__ . '/' . $rel,
];

$resolved = null;
foreach ($candidates as $candidate) {
    $real = realpath($candidate);
    if ($real && is_file($real)) {
        $uploadsRoot = realpath($projectRoot . '/uploads');
        if ($uploadsRoot && strpos($real, $uploadsRoot) === 0) {
            $resolved = $real;
            break;
        }
    }
}

if (!$resolved) {
    http_response_code(404);
    exit('File not found on server.');
}

$mime = $plan['mime_type'] ?: '';
if (!$mime && function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $resolved) ?: 'application/octet-stream';
    finfo_close($finfo);
}
if (!$mime) {
    $mime = 'application/octet-stream';
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$downloadName = $plan['file_name'] ?: basename($resolved);
$disposition = $mode === 'download' ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($downloadName) . '"');
header('Content-Length: ' . filesize($resolved));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($resolved);
exit;
