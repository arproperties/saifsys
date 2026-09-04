<?php
/**
 * HR - Company Document File Server
 * Streams a company document (or an archived renewal version) inline or as a download.
 *
 * Company documents are never linked by their stored file_path: uploads/company_docs
 * carries a deny rule, so this endpoint is the only way to read one. It enforces the
 * HR role, checks company access, keeps the file inside uploads/, and serves it with
 * a Content-Type taken from our own extension whitelist rather than the stored bytes.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/company_helper.php';
require_once __DIR__ . '/includes/hr_company_documents.php';

require_role(['Owner', 'Admin', 'HR'], $conn);

$documentId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
$versionId  = !empty($_GET['version']) ? (int)$_GET['version'] : 0;
$mode       = ($_GET['mode'] ?? 'view') === 'download' ? 'download' : 'view';

if (!$documentId) {
    http_response_code(404);
    exit('Document not found.');
}

if ($versionId > 0) {
    // The version must belong to the requested document - an id from another
    // document must not be readable by pairing it with a document you can see.
    $stmt = $conn->prepare("
        SELECT d.company_id, v.file_path, v.file_name
        FROM hr_company_document_versions v
        JOIN hr_company_documents d ON d.id = v.document_id
        WHERE v.id = ? AND v.document_id = ?
    ");
    $stmt->execute([$versionId, $documentId]);
} else {
    $stmt = $conn->prepare("
        SELECT d.company_id, d.file_path, d.file_name
        FROM hr_company_documents d
        WHERE d.id = ?
    ");
    $stmt->execute([$documentId]);
}
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc || empty($doc['file_path'])) {
    http_response_code(404);
    exit('Document not found.');
}

// Company scoping. HR is a shared module, so Owner/Admin see every company; anyone
// else must be assigned to the company that owns the document.
$docCompanyId = (int)$doc['company_id'];
$userId = current_user_id();
$allowed = has_role('Owner', $conn) || has_role('Admin', $conn);
if (!$allowed && $userId) {
    $allowed = user_has_company_access($conn, (int)$userId, $docCompanyId);
}
if (!$allowed) {
    http_response_code(403);
    exit('Access denied.');
}

// Only ever serve files that live under the project uploads folder.
$projectRoot = realpath(__DIR__ . '/..');
$uploadsRoot = $projectRoot ? realpath($projectRoot . '/uploads') : false;
$real = realpath(hr_company_document_absolute_path($doc['file_path']));

if (!$real || !$uploadsRoot || !is_file($real) || strpos($real, $uploadsRoot . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404);
    exit('File not found on server.');
}

// Content-Type comes from the extension this app assigned at upload time. Anything
// outside the inline-safe whitelist is handed over as an opaque download so nothing
// can be rendered on our own origin.
$inlineMime = hr_company_document_inline_mime($doc['file_path']);
if ($inlineMime === null) {
    $mode = 'download';
    $mime = 'application/octet-stream';
} else {
    $mime = $inlineMime;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$downloadName = $doc['file_name'] ?: basename($real);
$disposition = $mode === 'download' ? 'attachment' : 'inline';
$asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Length: ' . filesize($real));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($real);
exit;
