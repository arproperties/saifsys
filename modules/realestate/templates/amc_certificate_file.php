<?php
/**
 * Real Estate Module - AMC / Compliance Certificate File Server
 * Streams a stored certificate inline (view) or as an attachment (download).
 *
 * Certificate file_path values are stored page-relative (e.g.
 * "uploads/amc_certificates/xxx.pdf"), so linking to them directly from
 * /modules/realestate/ resolves to /modules/realestate/uploads/... and 404s.
 * This endpoint resolves the file on disk, enforces company access, and
 * serves it with the right headers.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_CORE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$currentCompanyId = current_company_id($conn) ?: 1;

$certId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
$mode = ($_GET['mode'] ?? 'view') === 'download' ? 'download' : 'view';

if (!$certId) {
    http_response_code(404);
    exit('Certificate not found.');
}

// Certificates carry no company_id of their own - scope through the contract.
$stmt = $conn->prepare("
    SELECT c.id, c.file_path, c.file_name
    FROM re_amc_certificates c
    INNER JOIN re_amc_contracts ac ON ac.id = c.contract_id
    WHERE c.id = ? AND ac.company_id = ?
");
$stmt->execute([$certId, $currentCompanyId]);
$certificate = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$certificate || empty($certificate['file_path'])) {
    http_response_code(404);
    exit('Certificate not found.');
}

// Resolve the file on disk. Try the project root first, then this module dir.
$projectRoot = realpath(__DIR__ . '/../../');
$rel = ltrim((string)$certificate['file_path'], '/');

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

// Determine MIME type from the file itself (nothing is stored for certificates).
$mime = '';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $resolved) ?: '';
    finfo_close($finfo);
}
if (!$mime) {
    $byExt = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
    ];
    $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
    $mime = $byExt[$ext] ?? 'application/octet-stream';
}

// Clean any buffered output before streaming
while (ob_get_level() > 0) {
    ob_end_clean();
}

$downloadName = $certificate['file_name'] ?: basename($resolved);
$disposition = $mode === 'download' ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($downloadName) . '"');
header('Content-Length: ' . filesize($resolved));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($resolved);
exit;
