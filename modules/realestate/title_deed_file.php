<?php
/**
 * Real Estate Module - Title Deed File Server
 * Streams a stored Title Deed file inline (view) or as an attachment (download).
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$currentCompanyId = current_company_id($conn) ?: 1;

$titleDeedId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
$mode = ($_GET['mode'] ?? 'view') === 'download' ? 'download' : 'view';

if (!$titleDeedId) {
    http_response_code(404);
    exit('Title deed not found.');
}

$stmt = $conn->prepare("SELECT id, file_path, file_name, mime_type FROM re_title_deeds WHERE id = ? AND company_id = ?");
$stmt->execute([$titleDeedId, $currentCompanyId]);
$titleDeed = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$titleDeed || empty($titleDeed['file_path'])) {
    http_response_code(404);
    exit('File not found.');
}

// Resolve the file on disk. Try the project root first, then this module dir.
$projectRoot = realpath(__DIR__ . '/../../');
$rel = ltrim((string)$titleDeed['file_path'], '/');

$candidates = [
    $projectRoot . '/' . $rel,
    __DIR__ . '/' . $rel,
];

$resolved = null;
foreach ($candidates as $candidate) {
    $real = realpath($candidate);
    if ($real && is_file($real)) {
        // Security: only serve files that live under the project uploads folder.
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

$mime = $titleDeed['mime_type'] ?: '';
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

$downloadName = $titleDeed['file_name'] ?: basename($resolved);
$disposition = $mode === 'download' ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($downloadName) . '"');
header('Content-Length: ' . filesize($resolved));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($resolved);
exit;
