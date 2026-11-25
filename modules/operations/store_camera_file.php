<?php
// modules/operations/store_camera_file.php?id= — the snapshot of one Store Camera event.
// Same access as Operations → Store Camera (Owner, Admin). The files sit in
// uploads/store_camera_events/, which the web server refuses to serve.

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/ops_helper.php';
require_once __DIR__ . '/includes/ops_store_camera.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_login(get_application_web_root() . '/login');
ops_require_access($conn);
if (!array_intersect(current_user_roles($conn), ['Owner', 'Admin'])) {
    http_response_code(403);
    exit('Forbidden');
}
session_write_close();

// ?live=ID: the newest live picture (the Live tab asks for it every second). Asking also
// tells the laptop program someone is watching. 204: no fresh picture yet.
if (isset($_GET['live'])) {
    $camId = (int)$_GET['live'];
    if (!sc_ready($conn) || !sc_camera($conn, $camId)) {
        http_response_code(404);
        exit;
    }
    sc_want_live($conn, $camId);
    $live = sc_live_path($camId);
    clearstatcache(true, $live);
    $age = is_file($live) ? time() - (int)filemtime($live) : null;
    header('Cache-Control: no-store');
    if ($age === null || $age > SC_LIVE_STALE_SECONDS) {
        header('X-Live-Age: ' . ($age === null ? 'none' : $age));
        http_response_code(204);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($live));
    header('X-Live-Age: ' . $age);
    header('X-Content-Type-Options: nosniff');
    readfile($live);
    exit;
}

// ?overlay=ID: a check's picture with what changed since the reference boxed in red and the pins drawn.
if (isset($_GET['overlay']) && sc_ready($conn) && sc_checks_ready($conn)) {
    $chk = sc_count($conn, (int)$_GET['overlay']);
    $bytes = ($chk && $chk['snapshot'] && $chk['diff']) ? sc_overlay_jpeg(sc_abs((string)$chk['snapshot']), json_decode((string)$chk['diff'], true) ?: []) : null;
    if ($bytes === null) {
        http_response_code(404);
        exit('That picture is not there any more.');
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, max-age=600');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

$path = null;
if (isset($_GET['report'])) {
    // ?report=CAMERA&day=Y-m-d&pic=start|end|e{event}: a snapshot kept with a daily report.
    $path = sc_report_pic_path((int)$_GET['report'], (string)($_GET['day'] ?? ''), (string)($_GET['pic'] ?? ''));
} elseif (sc_ready($conn)) {
    // ?count=ID: the picture an item count was made from; ?photo=ID: a kept past picture; ?id=ID: an event's snapshot.
    if (isset($_GET['photo']) && sc_photos_ready($conn)) {
        $st = $conn->prepare('SELECT path FROM store_camera_photos WHERE id = ?');
        $st->execute([(int)$_GET['photo']]);
    } else {
        $isCount = isset($_GET['count']) && sc_counts_ready($conn);
        $st = $conn->prepare($isCount ? 'SELECT snapshot FROM store_camera_counts WHERE id = ?' : 'SELECT snapshot FROM store_camera_events WHERE id = ?');
        $st->execute([(int)($isCount ? $_GET['count'] : ($_GET['id'] ?? 0))]);
    }
    $rel = (string)($st->fetchColumn() ?: '');
    // Only a file inside uploads/store_camera_events.
    $base = realpath(sc_abs('uploads/store_camera_events'));
    $abs = $rel !== '' ? realpath(sc_abs($rel)) : false;
    if ($base !== false && $abs !== false && strpos($abs, $base . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) {
        $path = $abs;
    }
}
if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('That snapshot is not there any more.');
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
