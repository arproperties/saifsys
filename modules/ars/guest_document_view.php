<?php
/**
 * Serve a guest profile document (ID scan / passport / visa) to signed-in staff.
 *
 * Files live outside the web root's reach on purpose: they are only ever read
 * through this endpoint, so the session and company scope are checked first.
 * Content-Type comes from an extension whitelist, never from the stored mime,
 * so a file uploaded with a spoofed type cannot be served as HTML.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_guest_attachments.php';

$arsCompanyId = arsPageAuth($conn);
$guestId = (int)($_GET['guest_id'] ?? 0);
$attachmentId = (int)($_GET['attachment_id'] ?? 0);
$forceDownload = ($_GET['disposition'] ?? '') === 'attachment';

if ($guestId <= 0 || $attachmentId <= 0) {
    http_response_code(400);
    echo 'Missing guest_id or attachment_id';
    exit;
}

$stmt = $conn->prepare('SELECT id FROM ars_guests WHERE id = ? AND company_id = ? LIMIT 1');
$stmt->execute([$guestId, $arsCompanyId]);
if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
    http_response_code(404);
    echo 'Guest not found';
    exit;
}

$res = ars_guest_attachment_resolve($conn, $arsCompanyId, $guestId, $attachmentId);
if (!$res['success']) {
    http_response_code(404);
    echo htmlspecialchars((string)($res['error'] ?? 'Not found'), ENT_QUOTES, 'UTF-8');
    exit;
}

$filename = (string)$res['filename'];
$inline = !$forceDownload && ars_guest_attachment_inline_ok($filename);

header('Content-Type: ' . $res['mime']);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . str_replace('"', '', $filename) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . (string)filesize((string)$res['path']));
readfile((string)$res['path']);
exit;
