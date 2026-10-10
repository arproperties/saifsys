<?php
/**
 * Serve one move-in / move-out inspection photo to signed-in ARS staff.
 * The files sit behind a deny-all .htaccess; this endpoint is the only way to read them.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_move_inspection.php';

$arsCompanyId = arsPageAuth($conn);
$photoId = (int)($_GET['id'] ?? 0);

$res = $photoId > 0
    ? ars_move_inspection_photo_resolve($conn, $arsCompanyId, $photoId)
    : ['success' => false, 'error' => 'Missing id'];
if (!$res['success']) {
    http_response_code(404);
    echo htmlspecialchars((string)$res['error'], ENT_QUOTES, 'UTF-8');
    exit;
}

header('Content-Type: ' . $res['mime']);
header('Content-Disposition: inline; filename="' . str_replace('"', '', (string)$res['filename']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . (string)filesize((string)$res['path']));
readfile((string)$res['path']);
exit;
