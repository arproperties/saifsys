<?php
/**
 * Real Estate Module - Document File Server
 * Streams a stored document inline (view) or as an attachment (download).
 *
 * Stored file_path values are inconsistent (some root-relative like
 * "/uploads/...", some page-relative like "uploads/realestate/lease/219/..."),
 * so linking to them directly from a page breaks. This endpoint resolves the
 * file on disk, enforces company access, and serves it with the right headers.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../legal/includes/legal_helper.php';

require_login();
if (!legal_can_manage($conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$currentCompanyId = current_company_id($conn) ?: 1;
$currentUserId = $_SESSION['user_id'] ?? null;

$documentId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
$mode = ($_GET['mode'] ?? 'view') === 'download' ? 'download' : 'view';

if (!$documentId) {
    http_response_code(404);
    exit('Document not found.');
}

$stmt = $conn->prepare("SELECT id, file_path, file_name, mime_type FROM re_documents WHERE id = ? AND company_id = ?");
$stmt->execute([$documentId, $currentCompanyId]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    http_response_code(404);
    exit('Document not found.');
}

// Resolve the file on disk. Try the project root first, then this module dir.
$projectRoot = realpath(__DIR__ . '/../../');
$rel = ltrim((string)$document['file_path'], '/');

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

// Determine MIME type
$mime = $document['mime_type'] ?: '';
if (!$mime && function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $resolved) ?: 'application/octet-stream';
    finfo_close($finfo);
}
if (!$mime) {
    $mime = 'application/octet-stream';
}

// Log access + bump counter (best effort)
try {
    $conn->prepare("
        INSERT INTO re_document_access_log
        (company_id, document_id, user_id, action, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([
        $currentCompanyId, $documentId, $currentUserId,
        $mode === 'download' ? 'downloaded' : 'viewed',
        $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    if ($mode === 'download') {
        $conn->prepare("UPDATE re_documents SET download_count = download_count + 1 WHERE id = ?")->execute([$documentId]);
    } else {
        $conn->prepare("UPDATE re_documents SET view_count = view_count + 1 WHERE id = ?")->execute([$documentId]);
    }
} catch (Throwable $e) {
    // Don't block file serving on logging errors.
}

// Clean any buffered output before streaming
while (ob_get_level() > 0) {
    ob_end_clean();
}

$downloadName = $document['file_name'] ?: basename($resolved);
$disposition = $mode === 'download' ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($downloadName) . '"');
header('Content-Length: ' . filesize($resolved));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($resolved);
exit;
