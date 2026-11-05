<?php
/**
 * One person's face photo for the attendance cameras (HR -> Cameras -> People).
 *
 * GET ?id=<employee id>  the photo the camera uses: the one uploaded here, or
 *                         else the profile photo.
 * POST upload / delete   the uploaded one, then back to the Cameras page. The
 *                         profile photo itself is never changed from here.
 * POST capture           save the face just taken from a camera in the popup
 *                         (hr/attendance_camera_live.php), HR only.
 * GET ?id=&learned=<name> one of the person's reference faces (clear camera
 *                         matches the PC kept); POST learned_delete removes one.
 * GET ?id=&best=1         the closest camera match so far; POST best_delete removes it,
 *                         best_use makes it a reference face (from 0.40).
 * POST best_reject / learned_reject  "Different person": the face is kept as
 *                         someone else's, so the cameras stop matching it to
 *                         this person. GET ?rejected=<name> shows one;
 *                         POST rejected_delete is the Undo.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/includes/hr_attendance_cameras.php';
require_role(['Owner', 'Admin', 'HR'], $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    $id = (int)($_POST['employee_id'] ?? 0);
    $st = $conn->prepare("SELECT id, full_name, employee_code, company_id FROM employees WHERE id = ?");
    $st->execute([$id]);
    $emp = $st->fetch(PDO::FETCH_ASSOC);
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    if (!$emp) {
        $_SESSION['cam_face_flash'] = ['err', 'That employee was not found.'];
    } else {
        $label = $emp['full_name'] . ' (' . $emp['employee_code'] . ')';
        if (($_POST['action'] ?? '') === 'upload') {
            $f = $_FILES['photo'] ?? [];
            $err = (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name'] ?? ''))
                ? hr_camera_face_save($id, $f)
                : (in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The photo is too large.' : 'Choose a photo to upload.');
            if ($err === null) {
                audit_bridge_hr_ops('attendance_camera_face_uploaded', 'employees', $id, 'Camera face photo uploaded for ' . $label,
                    isset($emp['company_id']) ? (int)$emp['company_id'] : null, ['employee_id' => $id], $label, $userId);
            }
            $_SESSION['cam_face_flash'] = $err === null
                ? ['ok', 'Photo saved for ' . $label . '. The camera PC picks it up within 15 seconds.']
                : ['err', $err];
        } elseif (($_POST['action'] ?? '') === 'capture') {
            // The face taken from the camera in this popup (HR only, like the Live view).
            $capture = hr_camera_capture_paths((int)($_POST['camera_id'] ?? 0))['jpg'];
            $err = !has_role('HR', $conn) ? 'Only HR can take a photo from the camera.'
                : (is_file($capture) ? hr_camera_face_save($id, ['tmp_name' => $capture]) : 'Capture again: that picture is no longer there.');
            if ($err === null) {
                @unlink($capture);
                audit_bridge_hr_ops('attendance_camera_face_uploaded', 'employees', $id, 'Camera face photo taken from the camera for ' . $label,
                    isset($emp['company_id']) ? (int)$emp['company_id'] : null, ['employee_id' => $id, 'from_camera' => (int)($_POST['camera_id'] ?? 0)], $label, $userId);
            }
            $_SESSION['cam_face_flash'] = $err === null
                ? ['ok', 'Photo taken from the camera and saved for ' . $label . '. The camera PC picks it up within 15 seconds.']
                : ['err', $err];
        } elseif (($_POST['action'] ?? '') === 'learned_delete') {
            // A reference face that is not this person: the cameras stop comparing with it.
            if (hr_camera_learned_delete($id, (string)($_POST['name'] ?? ''))) {
                audit_bridge_hr_ops('attendance_camera_reference_deleted', 'employees', $id, 'Camera reference face deleted for ' . $label,
                    isset($emp['company_id']) ? (int)$emp['company_id'] : null, ['employee_id' => $id, 'name' => (string)$_POST['name']], $label, $userId);
                $_SESSION['cam_face_flash'] = ['ok', 'Reference photo deleted for ' . $label . '. The camera PC drops it within 15 seconds.'];
            }
        } elseif (($_POST['action'] ?? '') === 'best_delete') {
            if (hr_camera_best_delete($id)) {
                $_SESSION['cam_face_flash'] = ['ok', 'Best match deleted for ' . $label . '. The next camera match takes its place.'];
            }
        } elseif (($_POST['action'] ?? '') === 'best_use') {
            $score = hr_camera_best($id)['score'] ?? 0;
            if (hr_camera_best_use($id)) {
                audit_bridge_hr_ops('attendance_camera_reference_added', 'employees', $id, 'Best camera match (' . number_format($score, 2) . ') used as a reference face for ' . $label,
                    isset($emp['company_id']) ? (int)$emp['company_id'] : null, ['employee_id' => $id, 'score' => $score], $label, $userId);
                $_SESSION['cam_face_flash'] = ['ok', 'Best match added to the reference photos for ' . $label . '. The cameras use it within 15 seconds.'];
            } else {
                $_SESSION['cam_face_flash'] = ['err', 'Only a best match of 0.40 or more can be used as a reference.'];
            }
        } elseif (in_array($_POST['action'] ?? '', ['best_reject', 'learned_reject'], true)) {
            $from = $_POST['action'] === 'best_reject' ? (hr_camera_best($id)['path'] ?? '')
                : (string)hr_camera_learned_path($id, (string)($_POST['name'] ?? ''));
            if (hr_camera_reject($id, $from)) {
                audit_bridge_hr_ops('attendance_camera_face_rejected', 'employees', $id, 'Camera face marked "Different person" for ' . $label,
                    isset($emp['company_id']) ? (int)$emp['company_id'] : null, ['employee_id' => $id, 'from' => $_POST['action'] === 'best_reject' ? 'best match' : 'reference'], $label, $userId);
                $_SESSION['cam_face_flash'] = ['ok', 'Marked "Different person" for ' . $label . '. The cameras stop matching that face to them within 15 seconds.'];
            }
        } elseif (($_POST['action'] ?? '') === 'rejected_delete') {
            if (hr_camera_rejected_delete($id, (string)($_POST['name'] ?? ''))) {
                audit_bridge_hr_ops('attendance_camera_face_unrejected', 'employees', $id, '"Different person" undone for ' . $label,
                    isset($emp['company_id']) ? (int)$emp['company_id'] : null, ['employee_id' => $id], $label, $userId);
                $_SESSION['cam_face_flash'] = ['ok', 'Undone for ' . $label . '. The cameras may match that face to them again.'];
            }
        } elseif (($_POST['action'] ?? '') === 'delete') {
            if (hr_camera_face_delete($id)) {
                audit_bridge_hr_ops('attendance_camera_face_deleted', 'employees', $id, 'Camera face photo deleted for ' . $label,
                    isset($emp['company_id']) ? (int)$emp['company_id'] : null, ['employee_id' => $id], $label, $userId);
                $_SESSION['cam_face_flash'] = ['ok', 'Photo deleted for ' . $label . '.'];
            }
        }
    }
    $company = (int)($_POST['company_id'] ?? 0);
    header('Location: attendance_cameras.php' . ($company > 0 ? '?company_id=' . $company : '') . '#people');
    exit;
}

if (!empty($_GET['best'])) {
    $face = hr_camera_best((int)($_GET['id'] ?? 0));
} elseif (isset($_GET['rejected'])) {
    $path = hr_camera_rejected_path((int)($_GET['id'] ?? 0), (string)$_GET['rejected']);
    $face = $path !== null && is_file($path) ? ['path' => $path] : null;
} elseif (isset($_GET['learned'])) {
    $path = hr_camera_learned_path((int)($_GET['id'] ?? 0), (string)$_GET['learned']);
    if ($path === null || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    $face = ['path' => $path];
} else {
    $face = hr_camera_face($conn, (int)($_GET['id'] ?? 0));
}
if (!$face) {
    http_response_code(404);
    exit;
}
$info = @getimagesize($face['path']);
header('Content-Type: ' . ($info['mime'] ?? 'image/jpeg'));
header('Content-Length: ' . filesize($face['path']));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($face['path']);
