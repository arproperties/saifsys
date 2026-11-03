<?php
/**
 * API: attendance from the entrance camera.
 *
 * The office PC watches the EZVIZ camera's video, recognises faces and sends
 * each match here (tools/attendance_camera/). The first sighting of the day
 * becomes the check-in and the latest becomes the check-out, written to the
 * same `attendance` table as everything else with source='camera' — so HR's
 * pages, the summary and payroll read it like any other row.
 *
 * Its own key: CAMERA_API_KEY in includes/config.php, sent as `X-Camera-Key`.
 * Empty or missing = 503, so uploading this file without configuring it
 * changes nothing.
 *
 *   POST JSON {"employee_code": "E00076", "seen_at": 1793500000, "camera_id": 2}
 *        seen_at is a Unix timestamp; the server works out the Dubai date and
 *        time from it. camera_id (optional, from HR -> Cameras) puts the room
 *        and device in the notes. "snapshot" (optional, base64 JPEG up to 300 KB)
 *        is kept as the row's in or out photo, for HR only.
 *        Answers {"ok": true, "check_in": "08:02", "check_out": ...}.
 *
 *   POST JSON {"camera_status": [{"id": 1, "device_name": "C8c_BH5929142_EZVIZ", "online": true,
 *              "network": "CameraTest (this PC's hotspot)", "band": "2.4 GHz", "camera_ip": "192.168.137.172"}]}
 *        the PC's report on each camera, shown on the Cameras page.
 *
 *   GET ?action=cameras
 *        the cameras switched on in HR -> Administration -> Cameras, each with
 *        its stream address, and resend_seconds (how often the same person is
 *        recorded, set on that page). With &since=<version> from the last
 *        answer it only says {"changed": false} when nothing is different.
 *        "faces" lists the ticked people with a face photo in HR.
 *
 *   GET ?action=face&code=E00076
 *        that person's face photo (uploaded on the Cameras page, else the
 *        profile photo); only for people ticked for the camera.
 *        "managed": false means the table is not on this
 *        server yet, and the PC keeps using its config.ini.
 *
 * The camera only fills an empty day or its own Pending row. A day HR has
 * marked or approved, or one recorded on the staff app or typed in by HR, is
 * left alone. Camera rows stay Pending for HR to approve.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/attendance_self.php';

function attendance_camera_send(array $body, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

$secret = defined('CAMERA_API_KEY') ? (string)CAMERA_API_KEY : '';
if ($secret === '') {
    attendance_camera_send(['ok' => false, 'error' => 'CAMERA_API_KEY is not set on this server.'], 503);
}
$given = (string)($_SERVER['HTTP_X_CAMERA_KEY'] ?? '');
if ($given === '' || !hash_equals($secret, $given)) {
    attendance_camera_send(['ok' => false, 'error' => 'Not authorised.'], 401);
}
// The PC fetches one face photo listed under "faces" (only people ticked for the camera).
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'GET' && ($_GET['action'] ?? '') === 'face') {
    require_once __DIR__ . '/../hr/includes/hr_attendance_cameras.php';
    $st = $conn->prepare("SELECT e.id FROM employees e JOIN hr_attendance_camera_people p ON p.employee_id = e.id WHERE e.employee_code = ?");
    $face = null;
    try {
        $st->execute([(string)($_GET['code'] ?? '')]);
        $empId = (int)$st->fetchColumn();
        $face = $empId > 0 ? hr_camera_face($conn, $empId) : null;
    } catch (PDOException $e) {
    }
    if (!$face) {
        attendance_camera_send(['ok' => false, 'error' => 'No photo.'], 404);
    }
    header('Content-Type: application/octet-stream');
    header('Cache-Control: no-store');
    readfile($face['path']);
    exit;
}

// The PC asks for the cameras HR has switched on (HR -> Administration -> Cameras).
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'GET' && ($_GET['action'] ?? '') === 'cameras') {
    require_once __DIR__ . '/../hr/includes/hr_attendance_cameras.php';
    if (!hr_cameras_ready($conn)) {
        attendance_camera_send(['ok' => true, 'managed' => false, 'cameras' => []]);
    }
    $list = [];
    foreach (hr_cameras_all($conn) as $cam) {
        if ((int)$cam['is_active']) {
            $list[] = ['id' => (int)$cam['id'], 'name' => (string)$cam['name'], 'rtsp_url' => hr_camera_rtsp_url($cam)];
        }
    }
    // The employee codes ticked under Cameras -> People: the PC only matches faces against these.
    $people = null;
    if (hr_camera_people_ready($conn)) {
        $people = $conn->query(
            "SELECT e.employee_code FROM hr_attendance_camera_people p JOIN employees e ON e.id = p.employee_id
              WHERE e.employee_code <> '' ORDER BY e.employee_code"
        )->fetchAll(PDO::FETCH_COLUMN);
    }
    // faces: employee code => version of the face photo HR keeps for them (Cameras -> People -> Photo).
    $answer = ['cameras' => $list, 'people' => $people, 'resend_seconds' => hr_camera_resend_seconds($conn),
               'faces' => (object)hr_camera_face_versions($conn)];
    // The PC asks often with the version it has; the whole list goes back only when something changed.
    $version = substr(hash('sha256', json_encode($answer)), 0, 16);
    if (($_GET['since'] ?? '') === $version) {
        attendance_camera_send(['ok' => true, 'managed' => true, 'changed' => false, 'version' => $version]);
    }
    attendance_camera_send(['ok' => true, 'managed' => true, 'changed' => true, 'version' => $version] + $answer);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    attendance_camera_send(['ok' => false, 'error' => 'POST only.'], 405);
}

$in = json_decode((string)file_get_contents('php://input'), true);

// The PC's report on each camera: the name it gives itself on the network and
// whether video is coming through. Shown on HR -> Administration -> Cameras.
if (isset($in['camera_status']) && is_array($in['camera_status'])) {
    require_once __DIR__ . '/../hr/includes/hr_attendance_cameras.php';
    if (!hr_cameras_ready($conn)) {
        attendance_camera_send(['ok' => true, 'updated' => 0]);
    }
    $now = attendance_self_now()->format('Y-m-d H:i:s');
    $st = $conn->prepare(
        "UPDATE hr_attendance_cameras
            SET device_name = COALESCE(device_name, ?), -- a name HR typed is kept; empty is filled in
                last_seen_at = IF(?, ?, last_seen_at),
                status_reported_at = ?,
                updated_at = updated_at -- \"Last changed\" on the page means an HR edit, not this report
          WHERE id = ?"
    );
    // Which Wi-Fi the camera is on, as the PC sees it. Its own statement: a
    // server that has not run the network columns yet still takes the rest.
    $net = $conn->prepare(
        "UPDATE hr_attendance_cameras
            SET network_name = ?, network_band = ?, current_ip = ?, updated_at = updated_at
          WHERE id = ?"
    );
    $text = static function ($v, int $max): ?string {
        $v = trim((string)$v);
        return $v !== '' ? mb_substr($v, 0, $max) : null;
    };
    $updated = 0;
    foreach ($in['camera_status'] as $s) {
        $device = $text($s['device_name'] ?? '', 100);
        $online = !empty($s['online']) ? 1 : 0;
        $st->execute([$device, $online, $now, $now, (int)($s['id'] ?? 0)]);
        $updated += $st->rowCount();
        if (array_key_exists('network', $s)) {
            try {
                $net->execute([$text($s['network'] ?? '', 100), $text($s['band'] ?? '', 60), $text($s['camera_ip'] ?? '', 45), (int)($s['id'] ?? 0)]);
            } catch (PDOException $e) {
                // Columns not added on this server yet.
            }
        }
    }
    attendance_camera_send(['ok' => true, 'updated' => $updated]);
}

$code = trim((string)($in['employee_code'] ?? ''));
$seenAt = (int)($in['seen_at'] ?? 0);
if ($code === '' || $seenAt <= 0) {
    attendance_camera_send(['ok' => false, 'error' => 'employee_code and seen_at are required.'], 400);
}

// A sighting from the future or more than a day old is a clock problem on the PC, not attendance.
$now = time();
if ($seenAt > $now + 300 || $seenAt < $now - 86400) {
    attendance_camera_send(['ok' => false, 'error' => 'seen_at is out of range; check the PC clock.'], 422);
}

$seen = (new DateTimeImmutable('@' . $seenAt))->setTimezone(attendance_self_tz());
$workDate = $seen->format('Y-m-d');
$time = $seen->format('H:i');

$st = $conn->prepare(
    "SELECT id, full_name, employee_code, company_id, status
       FROM employees
      WHERE employee_code = ?
      LIMIT 1"
);
$st->execute([$code]);
$employee = $st->fetch(PDO::FETCH_ASSOC);
if (!$employee || !in_array((string)$employee['status'], ['active', 'on_leave', 'notice_period'], true)) {
    attendance_camera_send(['ok' => false, 'error' => 'No current employee with code ' . $code . '.'], 404);
}
$employeeId = (int)$employee['id'];

// Only people HR ticked under Cameras -> People. Everyone else is on the staff
// app, and a camera passing them in the corridor must not touch their day.
require_once __DIR__ . '/../hr/includes/hr_attendance_cameras.php';
if (hr_camera_people_ready($conn) && !hr_camera_person_on($conn, $employeeId)) {
    attendance_camera_send(['ok' => true, 'skipped' => $code . ' is not set to camera attendance (HR -> Cameras -> People).']);
}
// "Last detected" on the Cameras page: every recognition, also one that leaves the day as it is.
if (hr_camera_people_ready($conn)) {
    try {
        $conn->prepare("UPDATE hr_attendance_camera_people SET last_detected_at = ?, last_camera_id = ?
                         WHERE employee_id = ? AND (last_detected_at IS NULL OR last_detected_at < ?)")
             ->execute([$seen->format('Y-m-d H:i:s'), ((int)($in['camera_id'] ?? 0)) ?: null, $employeeId, $seen->format('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
        // Columns not added on this server yet (database/fixes/2026_11_02_hr_attendance_cameras.sql).
    }
}

$row = attendance_self_today_row($conn, $employeeId, $workDate);

// Checked in on the staff app, not out yet (or out only by the camera): the
// camera keeps the departure up to date. The source shows who recorded last,
// so it becomes Camera; their check-in and the Pending status stay. A check-out
// on the staff app makes it Self again and approves the row, and from then on
// the camera leaves it alone (below). Such a day stays recognisable after the
// switch: the camera never sets created_by, the staff app always does.
$staffCheckedIn = false;
if ($row && (string)$row['status'] === 'pending' && !empty($row['check_in'])) {
    if ((string)$row['source'] === 'self') {
        $staffCheckedIn = true;
    } elseif ((string)$row['source'] === 'camera') {
        $cb = $conn->prepare("SELECT created_by FROM attendance WHERE id = ?");
        $cb->execute([(int)$row['id']]);
        $staffCheckedIn = (bool)$cb->fetchColumn();
    }
}
if ($staffCheckedIn) {
    $selfIn = substr((string)$row['check_in'], 0, 5);
    $selfOut = $row['check_out'] ? substr((string)$row['check_out'], 0, 5) : null;
    if ($time <= $selfIn || ($selfOut !== null && $time <= $selfOut)) {
        attendance_camera_send(['ok' => true, 'check_in' => $selfIn, 'check_out' => $selfOut, 'changed' => false]);
    }
    $oldNotes = trim((string)($row['notes'] ?? ''));
    $camLabel = 'camera';
    if (($camId = (int)($in['camera_id'] ?? 0)) > 0 && hr_cameras_ready($conn)) {
        $cs = $conn->prepare("SELECT name, device_name FROM hr_attendance_cameras WHERE id = ?");
        $cs->execute([$camId]);
        if ($cam = $cs->fetch(PDO::FETCH_ASSOC)) {
            $dev = hr_camera_device_label($cam['device_name'] ?? '');
            $camLabel = $cam['name'] . ($dev !== '' ? ' (' . $dev . ')' : '');
        }
    }
    // A note the person or HR wrote is kept.
    $notes = ($oldNotes === '' || strpos($oldNotes, 'Camera ') === 0) ? mb_substr('Camera out: ' . $camLabel, 0, 255) : $oldNotes;
    try {
        $conn->prepare("UPDATE attendance SET check_out = ?, hours = ?, notes = ?, source = 'camera', updated_at = NOW()
                         WHERE id = ? AND source IN ('self', 'camera') AND status = 'pending'")
             ->execute([$time, attendance_self_hours($selfIn, $time), $notes, (int)$row['id']]);
    } catch (PDOException $e) {
        attendance_camera_send(['ok' => false, 'error' => 'Could not save the attendance.'], 500);
    }
    $jpeg = hr_camera_photo_decode($in['snapshot'] ?? null);
    if ($jpeg !== null) {
        hr_camera_photo_save((int)$row['id'], 'out', $jpeg);
    }
    hr_camera_photo_cleanup();
    attendance_camera_send(['ok' => true, 'check_in' => $selfIn, 'check_out' => $time, 'changed' => true]);
}

// The camera only fills an empty day or keeps its own Pending row up to date.
// A day HR has marked (leave, absent, half day) or approved, or one the person
// recorded on the staff app or HR typed in, is theirs: leave it alone, so a
// camera time never slips into a row that is already approved.
if ($row && ((string)$row['source'] !== 'camera' || (string)$row['status'] !== 'pending')) {
    attendance_camera_send(['ok' => true, 'skipped' => 'This day is already recorded (' . $row['source'] . ', ' . $row['status'] . ').']);
}
// HR's edit forms and the staff app keep the source as it is but always set
// updated_by; the camera never does. So a camera row someone has corrected or
// checked in / out on is theirs from then on.
if ($row) {
    $by = $conn->prepare("SELECT updated_by FROM attendance WHERE id = ?");
    $by->execute([(int)$row['id']]);
    if ($by->fetchColumn()) {
        attendance_camera_send(['ok' => true, 'skipped' => 'This day was changed by HR or on the staff app.']);
    }
}

$checkIn = $row && $row['check_in'] ? substr((string)$row['check_in'], 0, 5) : null;
$checkOut = $row && $row['check_out'] ? substr((string)$row['check_out'], 0, 5) : null;
$arrival = false;
$inMovedToOut = false;

// Which camera saw them, for the notes: "Camera in: Room 2 (C8c · BH5929142) · out: Room 1 (...)".
// The notes are only written while they are the camera's own; a note HR or
// the staff app left is kept as it is.
$camLabel = 'camera';
$camId = (int)($in['camera_id'] ?? 0);
if ($camId > 0) {
    require_once __DIR__ . '/../hr/includes/hr_attendance_cameras.php';
    if (hr_cameras_ready($conn)) {
        $cs = $conn->prepare("SELECT name, device_name FROM hr_attendance_cameras WHERE id = ?");
        $cs->execute([$camId]);
        if ($cam = $cs->fetch(PDO::FETCH_ASSOC)) {
            $dev = hr_camera_device_label($cam['device_name'] ?? '');
            $camLabel = $cam['name'] . ($dev !== '' ? ' (' . $dev . ')' : '');
        }
    }
}
$oldNotes = trim((string)($row['notes'] ?? ''));
$notesOurs = $oldNotes === '' || $oldNotes === 'Entrance camera' || strpos($oldNotes, 'Camera ') === 0;
$inCam = $outCam = null;
if ($notesOurs && preg_match('/^Camera (?:in: (.*?))?(?:(?: · )?out: (.*))?$/u', $oldNotes, $m)) {
    $inCam = ($m[1] ?? '') !== '' ? $m[1] : null;
    $outCam = ($m[2] ?? '') !== '' ? $m[2] : null;
}

if ($checkIn === null || $time < $checkIn) {
    $arrival = true;
    // First sighting of the day (or earlier than what is there): that is the arrival.
    if ($checkIn !== null && ($checkOut === null || $checkIn > $checkOut)) {
        $checkOut = $checkIn;
        $outCam = $inCam;
        $inMovedToOut = true;
    }
    $checkIn = $time;
    $inCam = $camLabel;
} elseif ($time > $checkIn && ($checkOut === null || $time > $checkOut)) {
    // Any later sighting moves the departure on; the last one of the day stays.
    $checkOut = $time;
    $outCam = $camLabel;
} else {
    attendance_camera_send(['ok' => true, 'check_in' => $checkIn, 'check_out' => $checkOut, 'changed' => false]);
}

$hours = attendance_self_hours($checkIn, $checkOut);

$notes = $oldNotes;
if ($notesOurs) {
    $parts = [];
    if ($inCam !== null) $parts[] = 'in: ' . $inCam;
    if ($outCam !== null) $parts[] = 'out: ' . $outCam;
    $notes = mb_substr('Camera ' . implode(' · ', $parts), 0, 255);
}

try {
    $up = $conn->prepare(
        "INSERT INTO attendance
            (employee_id, work_date, check_in, check_out, hours, status, source, notes, company_id)
         VALUES (?, ?, ?, ?, ?, 'pending', 'camera', ?, ?)
         ON DUPLICATE KEY UPDATE
            check_in = VALUES(check_in),
            check_out = VALUES(check_out),
            hours = VALUES(hours),
            source = 'camera',
            notes = VALUES(notes),
            updated_at = NOW()"
    );
    $up->execute([$employeeId, $workDate, $checkIn, $checkOut, $hours, $notes, (int)($employee['company_id'] ?: 1)]);
} catch (PDOException $e) {
    attendance_camera_send(['ok' => false, 'error' => 'Could not save the attendance.'], 500);
}

// The photos follow the times: the sighting that set the check-in is the in
// photo, the one that set the check-out the out photo (replaced as it moves on).
$rowId = (int)($row['id'] ?? 0);
if ($rowId === 0) {
    $idSt = $conn->prepare("SELECT id FROM attendance WHERE employee_id = ? AND work_date = ?");
    $idSt->execute([$employeeId, $workDate]);
    $rowId = (int)$idSt->fetchColumn();
}
if ($rowId > 0) {
    if ($inMovedToOut) {
        hr_camera_photo_in_to_out($rowId);
    }
    $jpeg = hr_camera_photo_decode($in['snapshot'] ?? null);
    if ($jpeg !== null) {
        hr_camera_photo_save($rowId, $arrival ? 'in' : 'out', $jpeg);
    }
    hr_camera_photo_cleanup();
}

// Only the arrival goes in the audit log. The check-out moves on with every
// sighting while someone is in the room, so logging it would be a line every
// few minutes all day; the attendance row itself always holds the latest.
if ($arrival) {
    $saved = attendance_self_today_row($conn, $employeeId, $workDate);
    attendance_self_audit(
        $conn,
        'attendance_camera_check_in',
        (int)($saved['id'] ?? 0),
        $employee,
        'Camera check-in at ' . $time . ' on ' . $workDate,
        ['employee_id' => $employeeId, 'work_date' => $workDate, 'check_in' => $checkIn, 'source' => 'camera']
    );
}

attendance_camera_send(['ok' => true, 'check_in' => $checkIn, 'check_out' => $checkOut, 'changed' => true]);
