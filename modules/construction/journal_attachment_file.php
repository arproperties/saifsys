<?php
/**
 * Construction Accounting — Journal Attachment File Server
 * Streams a journal entry attachment inline (view) or as a download.
 * Same files and table as Real Estate journals; access is checked for Construction.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../realestate/accounting/journal_attachments_helper.php';

require_login();
if (!has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_CONSTRUCTION);
}

$currentCompanyId = (int)(current_company_id($conn) ?: 0);
$attachmentId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
$mode = ($_GET['mode'] ?? 'view') === 'download' ? 'download' : 'view';

// Current company only, like the journal pages.
$stmt = $conn->prepare("
    SELECT ja.id, ja.file_path, ja.file_name
    FROM re_journal_attachments ja
    JOIN re_journal_headers jh ON jh.id = ja.journal_id AND jh.company_id = ja.company_id
    WHERE ja.id = ? AND ja.company_id = ?
");
$attachment = false;
if ($attachmentId && $currentCompanyId > 0 && journal_attachments_table_ready($conn)) {
    $stmt->execute([$attachmentId, $currentCompanyId]);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$attachment) {
    http_response_code(404);
    exit('Attachment not found.');
}

// Resolve the file on disk.
$projectRoot = realpath(__DIR__ . '/../../');
$rel = ltrim((string)$attachment['file_path'], '/');

$resolved = null;
foreach ([$projectRoot . '/' . $rel, __DIR__ . '/' . $rel] as $candidate) {
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

// Content-Type comes from the extension this app assigned at upload time, via a
// small inline-safe whitelist. Anything outside it (Office docs, csv) is handed
// over as an opaque download so nothing can be rendered on our own origin.
$inlineMime = journal_attachment_inline_mime($attachment['file_path']);
if ($inlineMime === null) {
    $mode = 'download';
    $mime = 'application/octet-stream';
} else {
    $mime = $inlineMime;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$downloadName = $attachment['file_name'] ?: basename($resolved);
$disposition = $mode === 'download' ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
$asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);
header('Content-Disposition: ' . $disposition . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Length: ' . filesize($resolved));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($resolved);
exit;
