<?php
/**
 * The Live box on the Cameras page: ?id=<camera id> returns that camera's
 * latest frame. Each request also tells the camera PC someone is watching, so
 * it sends frames only while the box is open.
 *
 * The face photo popup uses it too:
 *   POST action=capture, id     ask the PC for a full-quality face now; area=x,y,w,h
 *                                (fractions) when HR drew a box around the person
 *   GET  ?id=&capture=1          the cropped face (200), the reason there is
 *                                none (422, text), or 204 while waiting
 *
 * HR role only, like the camera photos. 204 until the PC's first frame comes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/attendance_self.php';
require_once __DIR__ . '/includes/hr_attendance_cameras.php';
require_role(['HR'], $conn);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0 && ($_POST['action'] ?? '') === 'capture') {
        hr_camera_capture_request($id, hr_camera_capture_area((string)($_POST['area'] ?? '')));
    }
    http_response_code(204);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit;
}
hr_camera_live_want($id);

if (!empty($_GET['capture'])) {
    $p = hr_camera_capture_paths($id);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    if (is_file($p['jpg'])) {
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($p['jpg']));
        readfile($p['jpg']);
    } elseif (is_file($p['err'])) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        readfile($p['err']);
    } elseif (!is_file($p['ask']) || time() - (int)filemtime($p['ask']) > HR_CAMERA_CAPTURE_SECONDS) {
        // Asked too long ago: the PC is not running or not watching this camera.
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'The camera PC did not answer. Check it is running and the camera is in use.';
    } else {
        http_response_code(204);
    }
    exit;
}

$path = hr_camera_live_frame_path($id);
// A frame older than this means the PC stopped sending (closed, or the camera dropped).
if (!is_file($path) || time() - (int)filemtime($path) > HR_CAMERA_LIVE_SECONDS) {
    http_response_code(204);
    exit;
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Time: ' . (new DateTimeImmutable('@' . (int)filemtime($path)))->setTimezone(attendance_self_tz())->format('H:i:s'));
readfile($path);
