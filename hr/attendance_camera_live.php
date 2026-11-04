<?php
/**
 * The Live box on the Cameras page: ?id=<camera id> returns that camera's
 * latest frame. Each request also tells the camera PC someone is watching, so
 * it sends frames only while the box is open.
 *
 * HR role only, like the camera photos. 204 until the PC's first frame comes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/attendance_self.php';
require_once __DIR__ . '/includes/hr_attendance_cameras.php';
require_role(['HR'], $conn);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit;
}
hr_camera_live_want($id);

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
