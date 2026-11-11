<?php
// modules/operations/body_camera_file.php?id= | ?id=&thumb=1 — one body camera photo or video,
// or a video's thumbnail. Same access as Operations → Body camera. The file stays in Looki's
// cloud: this asks Looki for a fresh link (valid 10 minutes) and sends the browser there.

require_once __DIR__ . '/includes/ops_store_page.php';
require_once __DIR__ . '/includes/ops_body_camera.php';
session_write_close();

$f = bc_tables_ready($conn) ? bc_file_row($conn, (int)($_GET['id'] ?? 0)) : null;
$thumb = !empty($_GET['thumb']);
try {
    // A photo is its own thumbnail.
    $url = $f ? bc_media_url($conn, $f, $thumb && $f['media_type'] === 'video') : null;
} catch (BcError $e) {
    $url = null;
}
if ($url === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found in Looki';
    exit;
}
header('Cache-Control: private, no-store');
header('Location: ' . $url, true, 302);
