<?php
/**
 * ERP Expense Attachment File Server (Real Estate / Construction / ARS).
 *
 * Stored file_path values are never linked directly. This endpoint resolves the
 * file on disk, re-checks login plus the same department access the expense page
 * enforces for the owning module, and serves it with a Content-Type taken from
 * the extension we assigned on upload — not from anything the uploader supplied.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/erp_expense_attachments.php';

require_login();

$currentCompanyId = (int)(current_company_id($conn) ?: 0);
if ($currentCompanyId <= 0) {
    http_response_code(400);
    exit('Company context is required.');
}

$attachmentId = (int)($_GET['id'] ?? 0);
$mode = ($_GET['mode'] ?? 'view') === 'download' ? 'download' : 'view';
if ($attachmentId <= 0) {
    http_response_code(404);
    exit('Attachment not found.');
}

$stmt = $conn->prepare("
    SELECT a.file_path, a.file_name, h.source_module
    FROM erp_expense_attachments a
    JOIN erp_expense_headers h ON h.id = a.expense_id
    WHERE a.id = ? AND h.company_id = ?
");
$stmt->execute([$attachmentId, $currentCompanyId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Attachment not found.');
}

// Same gate as the expense pages for the module the expense belongs to.
$sourceModule = $row['source_module'] ?? 'realestate';
if ($sourceModule === 'construction') {
    if (!has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_FINANCIAL, $conn)) {
        require_module_access($conn, MODULE_CONSTRUCTION);
    }
} elseif ($sourceModule === 'ars') {
    if (!has_department_access(MODULE_ARS, DEPT_ARS_CORE, $conn) && !has_department_access(MODULE_ARS, DEPT_ARS_OPERATIONS, $conn)) {
        require_module_access($conn, MODULE_ARS);
    }
} else {
    if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
        require_module_access($conn, MODULE_REALESTATE);
    }
}

// Resolve on disk, and only ever serve from under uploads/.
$projectRoot = realpath(__DIR__ . '/../../');
$real = realpath($projectRoot . '/' . ltrim((string)$row['file_path'], '/'));
$uploadsRoot = realpath($projectRoot . '/uploads');
if (!$real || !$uploadsRoot || strpos($real, $uploadsRoot) !== 0 || !is_file($real)) {
    http_response_code(404);
    exit('File not found on server.');
}

$downloadName = $row['file_name'] ?: basename($real);

// Content-Type comes from the stored extension via a whitelist; anything not on it
// is forced to download so it can never render on our origin.
$inlineMime = erp_expense_attachment_inline_mime($real);
if ($mode === 'view' && $inlineMime !== null) {
    $contentType = $inlineMime;
    $disposition = 'inline';
} else {
    $contentType = 'application/octet-stream';
    $disposition = 'attachment';
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $contentType);
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($downloadName) . '"');
header('Content-Length: ' . filesize($real));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; style-src \'unsafe-inline\'');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($real);
exit;
