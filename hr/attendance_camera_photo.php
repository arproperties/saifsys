<?php
/**
 * One camera photo of an attendance row: ?id=<attendance id>&which=in|out.
 *
 * Staff photos, so the HR role only — not Owner or Admin on their own. The
 * files sit in a folder that refuses direct requests; this page is the only
 * way to them.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_attendance_cameras.php';
require_role(['HR'], $conn);

// POST: delete the photo (the trash icon on the Attendance list), then back to the list.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../includes/audit_bridge.php';
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $which = ($_POST['which'] ?? '') === 'out' ? 'out' : 'in';
    if (hr_camera_photo_delete($id, $which)) {
        audit_bridge_hr_ops('attendance_camera_photo_deleted', 'attendance', $id,
            'Camera ' . ($which === 'out' ? 'check-out' : 'check-in') . ' photo deleted for attendance #' . $id,
            null, ['attendance_id' => $id, 'which' => $which], 'Attendance #' . $id,
            isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null);
    }
    // Only back to the Attendance list, with its filters.
    $back = (string)($_POST['back'] ?? '');
    header('Location: ' . (preg_match('#^attendance\.php(\?[^\r\n]*)?$#', $back) ? $back : 'attendance.php'));
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$which = ($_GET['which'] ?? '') === 'out' ? 'out' : 'in';
$path = hr_camera_photo_path($id, $which);

if ($id <= 0 || !is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
