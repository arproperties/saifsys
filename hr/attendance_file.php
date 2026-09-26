<?php
/**
 * Attendance attachment file server.
 *
 * Stored file_path values are never linked directly. This resolves the file on
 * disk, re-checks the same HR roles the attendance pages require, and serves it
 * with a Content-Type taken from the extension we assigned on upload — not from
 * anything the uploader supplied.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/hr_attendance_attachments.php';
require_role(['Owner', 'Admin', 'HR'], $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$attachmentId = (int)($_GET['id'] ?? 0);
$mode = ($_GET['mode'] ?? 'view') === 'download' ? 'download' : 'view';
if ($attachmentId <= 0) {
    http_response_code(404);
    exit('Attachment not found.');
}

$stmt = $conn->prepare("
    SELECT a.file_path, a.file_name
      FROM attendance_attachments a
      JOIN attendance t ON t.id = a.attendance_id
     WHERE a.id = ?
     LIMIT 1
");
$stmt->execute([$attachmentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Attachment not found.');
}

// Resolve on disk, and only ever serve from under uploads/.
$projectRoot = realpath(__DIR__ . '/..');
$real = realpath($projectRoot . '/' . ltrim((string)$row['file_path'], '/'));
$uploadsRoot = realpath($projectRoot . '/uploads');
if (!$real || !$uploadsRoot || strpos($real, $uploadsRoot) !== 0 || !is_file($real)) {
    http_response_code(404);
    exit('File not found on server.');
}

$downloadName = $row['file_name'] ?: basename($real);

$inlineMime = hr_attendance_attach_inline_mime($real);
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
