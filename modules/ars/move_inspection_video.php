<?php
/**
 * Serve one move-in / move-out inspection video to signed-in ARS staff.
 * The files sit behind a deny-all .htaccess; this endpoint is the only way to read them.
 * Supports HTTP Range requests, which the video player needs to seek (and iPhone Safari to play).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_move_inspection.php';

$arsCompanyId = arsPageAuth($conn);
$videoId = (int)($_GET['id'] ?? 0);

$res = $videoId > 0
    ? ars_move_inspection_video_resolve($conn, $arsCompanyId, $videoId)
    : ['success' => false, 'error' => 'Missing id'];
if (!$res['success']) {
    http_response_code(404);
    echo htmlspecialchars((string)$res['error'], ENT_QUOTES, 'UTF-8');
    exit;
}
// A long video must not hold the session lock (other tabs would wait)
session_write_close();

$path = (string)$res['path'];
$size = (int)filesize($path);
$start = 0;
$end = $size - 1;

header('Content-Type: ' . $res['mime']);
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . str_replace(['"', "\r", "\n"], '', (string)$res['filename']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Accept-Ranges: bytes');

if (isset($_SERVER['HTTP_RANGE'])) {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim((string)$_SERVER['HTTP_RANGE']), $m) || ($m[1] === '' && $m[2] === '')) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    if ($m[1] === '') {
        // "bytes=-500": the last 500 bytes
        $start = max(0, $size - (int)$m[2]);
    } else {
        $start = (int)$m[1];
        if ($m[2] !== '') {
            $end = min($end, (int)$m[2]);
        }
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
header('Content-Length: ' . (string)($end - $start + 1));

while (ob_get_level() > 0) {
    ob_end_clean();
}
set_time_limit(0);
$fh = fopen($path, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh) && !connection_aborted()) {
    $buf = fread($fh, (int)min(1048576, $left));
    if ($buf === false || $buf === '') {
        break;
    }
    echo $buf;
    flush();
    $left -= strlen($buf);
}
fclose($fh);
exit;
