<?php
/**
 * The attendance cameras HR keeps under Administration -> Cameras.
 *
 * Shared by the HR page (hr/attendance_cameras.php) and the camera API
 * (api/attendance_camera.php?action=cameras), which hands the camera PC the
 * stream address of every active camera.
 */

/** Whether the table is on this database (database/fixes/2026_11_02_hr_attendance_cameras.sql). */
function hr_cameras_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$conn->query("SHOW TABLES LIKE 'hr_attendance_cameras'")->fetchColumn();
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Whether the "who the cameras record" table is on this database. */
function hr_camera_people_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$conn->query("SHOW TABLES LIKE 'hr_attendance_camera_people'")->fetchColumn();
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** How often the same person is recorded while they stay in view: 2 minutes unless HR changed it. */
const HR_CAMERA_RESEND_DEFAULT = 120;

function hr_camera_resend_seconds(PDO $conn): int
{
    try {
        $st = $conn->prepare("SELECT `value` FROM settings WHERE `key` = 'attendance_camera.resend_seconds'");
        $st->execute();
        $v = (int)$st->fetchColumn();
    } catch (PDOException $e) {
        $v = 0;
    }
    return $v >= 60 && $v <= 86400 ? $v : HR_CAMERA_RESEND_DEFAULT;
}

/** Save it from a number and 'minute' or 'hour'. Null when outside 1 minute to 24 hours. */
function hr_camera_resend_save(PDO $conn, $every, string $unit): ?int
{
    $every = filter_var($every, FILTER_VALIDATE_INT);
    $seconds = $every === false ? 0 : $every * ($unit === 'hour' ? 3600 : 60);
    if ($seconds < 60 || $seconds > 86400) {
        return null;
    }
    $conn->prepare("INSERT INTO settings (`key`, `value`) VALUES ('attendance_camera.resend_seconds', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
         ->execute([(string)$seconds]);
    return $seconds;
}

/** 120 -> "2 minutes", 3600 -> "1 hour", 5400 -> "90 minutes". */
function hr_camera_resend_label(int $seconds): string
{
    $n = $seconds % 3600 === 0 ? intdiv($seconds, 3600) : intdiv($seconds, 60);
    $unit = $seconds % 3600 === 0 ? 'hour' : 'minute';
    return $n . ' ' . $unit . ($n === 1 ? '' : 's');
}

/** Whether this employee is ticked to be recorded by camera. */
function hr_camera_person_on(PDO $conn, int $employeeId): bool
{
    if (!hr_camera_people_ready($conn)) {
        return false;
    }
    $st = $conn->prepare("SELECT 1 FROM hr_attendance_camera_people WHERE employee_id = ?");
    $st->execute([$employeeId]);
    return (bool)$st->fetchColumn();
}

/** Every camera, active first, then by name. */
function hr_cameras_all(PDO $conn): array
{
    if (!hr_cameras_ready($conn)) {
        return [];
    }
    return $conn->query(
        "SELECT * FROM hr_attendance_cameras ORDER BY is_active DESC, name, id"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** The RTSP address the camera PC opens for one camera. */
function hr_camera_rtsp_url(array $cam): string
{
    $path = '/' . ltrim((string)($cam['stream_path'] ?: '/H.264'), '/');
    return 'rtsp://' . rawurlencode((string)$cam['username']) . ':' . rawurlencode((string)$cam['verification_code'])
        . '@' . $cam['ip_address'] . ':' . (int)($cam['rtsp_port'] ?: 554) . $path;
}

/**
 * "C8c_BH5929142_EZVIZ" -> "C8c · BH5929142". Other names are shown as they are.
 */
function hr_camera_device_label(?string $device): string
{
    $device = trim((string)$device);
    if (preg_match('/^([A-Za-z0-9]+)_([A-Za-z0-9]+)_EZVIZ$/i', $device, $m)) {
        return $m[1] . ' · ' . $m[2];
    }
    return $device;
}

/**
 * Online / offline from the PC's last report, as [label, badge class, detail].
 *
 * Online means video came through in the last 10 minutes. The PC reports
 * every 5 minutes, so a camera it has not reported on for 15 minutes means the
 * PC itself is off or the program is not running.
 */
function hr_camera_status(array $cam): array
{
    $tz = new DateTimeZone('Asia/Dubai');
    $now = new DateTimeImmutable('now', $tz);
    $ago = static function (?string $at) use ($now, $tz): ?int {
        return $at ? (int)floor(($now->getTimestamp() - (new DateTimeImmutable($at, $tz))->getTimestamp()) / 60) : null;
    };
    $reported = $ago($cam['status_reported_at'] ?? null);
    $seen = $ago($cam['last_seen_at'] ?? null);
    $when = static fn(?string $at) => $at ? date('j M, g:i A', strtotime($at)) : 'never';

    if (!(int)$cam['is_active']) {
        return ['Not in use', 'text-bg-secondary', 'Not read by the camera PC'];
    }
    if ($reported === null) {
        return ['Waiting', 'text-bg-light border', 'The camera PC has not reported yet'];
    }
    if ($reported > 15) {
        return ['PC not reporting', 'text-bg-warning', 'Last report ' . $when($cam['status_reported_at'])];
    }
    if ($seen !== null && $seen <= 10) {
        return ['Online', 'text-bg-success', 'Video ' . ($seen < 1 ? 'just now' : $seen . ' min ago')];
    }
    return ['No video', 'text-bg-danger', 'Last video ' . $when($cam['last_seen_at'] ?? null)];
}

/** An IPv4 address or a network name (EZVIZ names have underscores); nothing that could break out of the URL. */
function hr_camera_valid_host(string $host): bool
{
    return (bool)preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $host);
}

/* ---------------------------------------------------------------------------
 * Arrival and departure photos
 *
 * The camera PC sends a head-and-shoulders picture with each sighting. Each
 * camera row keeps two: <attendance id>_in.jpg from the sighting that set the
 * check-in and <attendance id>_out.jpg from the one that set the check-out,
 * overwritten as the check-out moves on. Only the HR role sees them
 * (hr/attendance_camera_photo.php); the folder itself refuses every request.
 * ------------------------------------------------------------------------- */

/** How long the photos are kept. */
const HR_CAMERA_PHOTO_DAYS = 60;

/** Largest picture accepted from the PC. */
const HR_CAMERA_PHOTO_MAX_BYTES = 300 * 1024;

function hr_camera_photo_dir(): string
{
    return dirname(__DIR__, 2) . '/uploads/attendance_camera';
}

/** Where one row's in or out photo lives. */
function hr_camera_photo_path(int $attendanceId, string $which): string
{
    return hr_camera_photo_dir() . '/' . $attendanceId . '_' . ($which === 'out' ? 'out' : 'in') . '.jpg';
}

/** Which of a row's photos exist: ['in' => bool, 'out' => bool]. */
function hr_camera_photos_for(int $attendanceId): array
{
    return [
        'in'  => is_file(hr_camera_photo_path($attendanceId, 'in')),
        'out' => is_file(hr_camera_photo_path($attendanceId, 'out')),
    ];
}

/** Create the folder, locked: nothing in it is ever served directly. */
function hr_camera_photo_prepare_dir(): bool
{
    $dir = hr_camera_photo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess',
            "# Staff photos from the attendance cameras: served only through hr/attendance_camera_photo.php.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    if (!is_file($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }
    return true;
}

/**
 * The PC's picture as JPEG bytes, or null when it is missing, too large or
 * not a JPEG.
 */
function hr_camera_photo_decode($b64): ?string
{
    if (!is_string($b64) || $b64 === '' || strlen($b64) > HR_CAMERA_PHOTO_MAX_BYTES * 4 / 3 + 8) {
        return null;
    }
    $bytes = base64_decode($b64, true);
    if ($bytes === false || strlen($bytes) > HR_CAMERA_PHOTO_MAX_BYTES) {
        return null;
    }
    $info = @getimagesizefromstring($bytes);
    return ($info && ($info[2] ?? 0) === IMAGETYPE_JPEG) ? $bytes : null;
}

/** Save one row's in or out photo, replacing the one there. */
function hr_camera_photo_save(int $attendanceId, string $which, string $jpeg): void
{
    if ($attendanceId <= 0 || !hr_camera_photo_prepare_dir()) {
        return;
    }
    $path = hr_camera_photo_path($attendanceId, $which);
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $jpeg) !== false) {
        @rename($tmp, $path);
    }
}

/** The old arrival became the departure (an earlier sighting came in): its photo goes with it. */
function hr_camera_photo_in_to_out(int $attendanceId): void
{
    $in = hr_camera_photo_path($attendanceId, 'in');
    if (is_file($in)) {
        @rename($in, hr_camera_photo_path($attendanceId, 'out'));
    }
}

/** HR removed one photo by hand. True when there was a file to remove. */
function hr_camera_photo_delete(int $attendanceId, string $which): bool
{
    $path = hr_camera_photo_path($attendanceId, $which);
    return $attendanceId > 0 && is_file($path) && @unlink($path);
}

/** Remove photos older than HR_CAMERA_PHOTO_DAYS, at most once a day. */
function hr_camera_photo_cleanup(): void
{
    $dir = hr_camera_photo_dir();
    $marker = $dir . '/.last_cleanup';
    if (!is_dir($dir) || (is_file($marker) && date('Y-m-d', (int)filemtime($marker)) === date('Y-m-d'))) {
        return;
    }
    @touch($marker);
    $cutoff = time() - HR_CAMERA_PHOTO_DAYS * 86400;
    foreach (glob($dir . '/*.jpg') ?: [] as $file) {
        if ((int)@filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

/* ---------- Live view on the Cameras page ----------
 * While HR has a camera's Live box open, the page asks for its picture every
 * couple of seconds (hr/attendance_camera_live.php), which marks the camera as
 * watched. The PC asks which cameras are watched and sends only their latest
 * frame, one file per camera, overwritten each time. Nothing is kept: a frame
 * is useless a few seconds later, and the folder refuses direct requests like
 * the photos above.
 * ------------------------------------------------- */

/** A camera counts as watched this long after HR's page last asked for it. */
const HR_CAMERA_LIVE_SECONDS = 15;

function hr_camera_live_dir(): string
{
    return hr_camera_photo_dir() . '/live';
}

function hr_camera_live_frame_path(int $cameraId): string
{
    return hr_camera_live_dir() . '/cam' . $cameraId . '.jpg';
}

/** HR's page is showing this camera now. */
function hr_camera_live_want(int $cameraId): void
{
    if ($cameraId <= 0 || !hr_camera_photo_prepare_dir()) {
        return;
    }
    $dir = hr_camera_live_dir();
    if (is_dir($dir) || @mkdir($dir, 0755, true)) {
        @touch($dir . '/cam' . $cameraId . '.want');
    }
}

/** Ids of the cameras someone is watching. */
function hr_camera_live_wanted(): array
{
    $ids = [];
    foreach (glob(hr_camera_live_dir() . '/cam*.want') ?: [] as $file) {
        if (time() - (int)@filemtime($file) <= HR_CAMERA_LIVE_SECONDS) {
            $ids[] = (int)substr(basename($file, '.want'), 3);
        } else {
            // Box closed: the last frame and any capture not saved go too.
            @unlink($file);
            @unlink(substr($file, 0, -5) . '.jpg');
            @unlink(substr($file, 0, -5) . '_capture.jpg');
            @unlink(substr($file, 0, -5) . '_capture.txt');
        }
    }
    return array_values(array_filter($ids));
}

/** The PC's latest frame for a watched camera; ignored when nobody is watching. */
function hr_camera_live_save(int $cameraId, string $jpeg): bool
{
    if (!in_array($cameraId, hr_camera_live_wanted(), true)) {
        return false;
    }
    $path = hr_camera_live_frame_path($cameraId);
    $tmp = $path . '.tmp';
    return @file_put_contents($tmp, $jpeg) !== false && @rename($tmp, $path);
}

/* Capture: HR clicks Capture in the face photo popup while the person stands in
 * front of the camera. The PC takes a full-quality frame, checks there is one
 * clear front-facing face and sends it back cropped (or why not). HR then saves
 * it as the person's camera photo. Request and answer are files next to the
 * live frame. */

/** A capture request the PC has not answered in this long is dropped. */
const HR_CAMERA_CAPTURE_SECONDS = 30;

function hr_camera_capture_paths(int $cameraId): array
{
    $base = hr_camera_live_dir() . '/cam' . $cameraId;
    return ['ask' => $base . '.capture', 'jpg' => $base . '_capture.jpg', 'err' => $base . '_capture.txt'];
}

/**
 * HR clicked Capture: forget the last answer and ask the PC. $area is the box
 * HR drew around the person on the live picture, as fractions of it
 * [x, y, width, height]; the PC then looks only there, at full quality.
 */
function hr_camera_capture_request(int $cameraId, ?array $area = null): void
{
    hr_camera_live_want($cameraId);
    $p = hr_camera_capture_paths($cameraId);
    @unlink($p['jpg']);
    @unlink($p['err']);
    @file_put_contents($p['ask'], $area ? json_encode(array_values($area)) : '');
}

/** "0.1,0.2,0.3,0.4" from the page as [x, y, w, h] inside the picture, or null for the whole picture. */
function hr_camera_capture_area(string $raw): ?array
{
    $v = array_map('floatval', explode(',', $raw));
    if (count($v) !== 4) {
        return null;
    }
    [$x, $y, $w, $h] = $v;
    $x = max(0.0, min(1.0, $x));
    $y = max(0.0, min(1.0, $y));
    $w = min(1.0 - $x, $w);
    $h = min(1.0 - $y, $h);
    // A box too small to hold a face is a slip of the mouse.
    return ($w >= 0.03 && $h >= 0.03) ? [round($x, 4), round($y, 4), round($w, 4), round($h, 4)] : null;
}

/** Waiting captures with a box: camera id => [x, y, w, h]. */
function hr_camera_capture_areas(): array
{
    $out = [];
    foreach (hr_camera_capture_wanted() as $id) {
        $area = json_decode((string)@file_get_contents(hr_camera_capture_paths($id)['ask']), true);
        if (is_array($area) && count($area) === 4) {
            $out[(string)$id] = array_map('floatval', $area);
        }
    }
    return $out;
}

/** Cameras with a capture waiting for the PC. */
function hr_camera_capture_wanted(): array
{
    $ids = [];
    foreach (glob(hr_camera_live_dir() . '/cam*.capture') ?: [] as $file) {
        if (time() - (int)@filemtime($file) <= HR_CAMERA_CAPTURE_SECONDS) {
            $ids[] = (int)substr(basename($file, '.capture'), 3);
        } else {
            @unlink($file);
        }
    }
    return array_values(array_filter($ids));
}

/** The PC's answer: the cropped face, or the reason there is none. Only for a capture HR asked for. */
function hr_camera_capture_answer(int $cameraId, ?string $jpeg, string $error): bool
{
    if (!in_array($cameraId, hr_camera_capture_wanted(), true)) {
        return false;
    }
    $p = hr_camera_capture_paths($cameraId);
    $ok = $jpeg !== null
        ? @file_put_contents($p['jpg'] . '.tmp', $jpeg) !== false && @rename($p['jpg'] . '.tmp', $p['jpg'])
        : @file_put_contents($p['err'], mb_substr($error !== '' ? $error : 'No face could be taken.', 0, 200)) !== false;
    @unlink($p['ask']);
    return $ok;
}

/* ---------- Face photos: what the camera PC learns each person's face from ---------- */

/** HR uploads one per person on the Cameras page; without one the profile photo is used. */
function hr_camera_face_dir(): string
{
    return dirname(__DIR__, 2) . '/uploads/attendance_faces';
}

function hr_camera_face_upload_path(int $employeeId): string
{
    return hr_camera_face_dir() . '/' . $employeeId . '.jpg';
}

/**
 * The photo the camera uses for this person: ['source' => 'camera'|'file'|'profile', 'path' => file]
 * or null when there is none (or the profile photo's file is missing).
 *
 * 'file' is a photo in the camera program's own faces/ folder
 * (tools/attendance_camera/faces/E00076.jpg), when that folder is on this
 * server (the camera PC runs saifsys itself). The PC already has it.
 */
function hr_camera_face(PDO $conn, int $employeeId): ?array
{
    $own = hr_camera_face_upload_path($employeeId);
    if (is_file($own)) {
        return ['source' => 'camera', 'path' => $own];
    }
    $st = $conn->prepare("SELECT e.employee_code, u.avatar_path FROM employees e LEFT JOIN `user` u ON u.id = e.user_id WHERE e.id = ?");
    $st->execute([$employeeId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $code = strtoupper((string)($row['employee_code'] ?? ''));
    if ($code !== '' && preg_match('/^[A-Z0-9-]+$/', $code)) {
        foreach (['jpg', 'jpeg', 'png'] as $ext) {
            foreach ([$ext, strtoupper($ext)] as $e) {
                $file = dirname(__DIR__, 2) . '/tools/attendance_camera/faces/' . $code . '.' . $e;
                if (is_file($file)) {
                    return ['source' => 'file', 'path' => $file];
                }
            }
        }
    }
    $rel = (string)($row['avatar_path'] ?? '');
    // Only a file inside uploads/avatars counts.
    if ($rel !== '' && preg_match('#^uploads/avatars/[A-Za-z0-9._-]+$#', $rel)) {
        $file = dirname(__DIR__, 2) . '/' . $rel;
        if (is_file($file)) {
            return ['source' => 'profile', 'path' => $file];
        }
    }
    return null;
}

/** Save an image file as this person's camera photo: re-drawn as a JPEG, at most 1000 px. Null on success, else why not. */
function hr_camera_face_save(int $employeeId, array $file): ?string
{
    if (!is_file($file['tmp_name'] ?? '')) {
        return 'Choose a photo to upload.';
    }
    if ((int)filesize($file['tmp_name']) > 8 * 1024 * 1024) {
        return 'The photo is larger than 8 MB.';
    }
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
    if (!$info || !isset($types[$info[2]]) || !function_exists($types[$info[2]])) {
        return 'Upload a JPG, PNG or WebP photo.';
    }
    $img = @$types[$info[2]]($file['tmp_name']);
    if (!$img) {
        return 'That photo could not be read.';
    }
    // Phone photos are often stored sideways with an EXIF turn.
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($file['tmp_name'])['Orientation'] ?? 1);
        $turn = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($turn) $img = imagerotate($img, $turn, 0);
    }
    $w = imagesx($img); $h = imagesy($img);
    $scale = min(1, 1000 / max($w, $h));
    if ($scale < 1) {
        $img = imagescale($img, (int)round($w * $scale), (int)round($h * $scale));
    }
    $dir = hr_camera_face_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return 'The photo folder could not be created.';
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess',
            "# Staff face photos for the attendance cameras: served only through hr/attendance_camera_face.php.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    $path = hr_camera_face_upload_path($employeeId);
    $ok = imagejpeg($img, $path . '.tmp', 88) && @rename($path . '.tmp', $path);
    return $ok ? null : 'The photo could not be saved.';
}

function hr_camera_face_delete(int $employeeId): bool
{
    $path = hr_camera_face_upload_path($employeeId);
    return is_file($path) && @unlink($path);
}

/** Ticked people with a usable photo: employee code => short version that changes with the photo. */
function hr_camera_face_versions(PDO $conn): array
{
    if (!hr_camera_people_ready($conn)) {
        return [];
    }
    $out = [];
    $rows = $conn->query("SELECT e.id, e.employee_code FROM hr_attendance_camera_people p JOIN employees e ON e.id = p.employee_id
                           WHERE e.employee_code <> '' ORDER BY e.employee_code")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        // A faces/ photo is already on the PC: not sent again.
        if (($face = hr_camera_face($conn, (int)$r['id'])) && $face['source'] !== 'file') {
            $out[strtoupper($r['employee_code'])] = substr(md5($face['source'] . '|' . filesize($face['path']) . '|' . filemtime($face['path'])), 0, 12);
        }
    }
    return $out;
}
