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

/**
 * The note on a day HR fixed in the Camera log ("Different person" with times).
 * The camera keeps HR's check-in and only moves the check-out later, as it does
 * for a day the person checked in on themselves (api/attendance_camera.php).
 */
const HR_CAMERA_FIX_NOTE = 'Times set by HR (wrong camera match)';

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
    // The match log keeps HR_CAMERA_LOG_DAYS: the day's CSV and its photo folder go together.
    $logCutoff = date('Y-m-d', time() - HR_CAMERA_LOG_DAYS * 86400);
    foreach (glob(hr_camera_log_dir() . '/*.csv') ?: [] as $csv) {
        $day = basename($csv, '.csv');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && $day < $logCutoff) {
            foreach (glob(hr_camera_log_dir() . '/' . $day . '/*.jpg') ?: [] as $jpg) {
                @unlink($jpg);
            }
            @rmdir(hr_camera_log_dir() . '/' . $day);
            @unlink($csv);
        }
    }
}

/*
 * The camera match log (HR -> Attendance -> Camera log): every match the camera
 * PC sends, with its photo, whether it changed the day or not. One CSV per day
 * (log/2026-11-06.csv) and that day's photos beside it (log/2026-11-06/*.jpg),
 * inside the photo folder that refuses direct requests. No database table.
 */

/** Days the match log is kept. */
const HR_CAMERA_LOG_DAYS = 30;

/** Longest side of a log photo: small enough for a month of matches. */
const HR_CAMERA_LOG_PHOTO_PX = 400;

const HR_CAMERA_LOG_COLUMNS = ['time', 'employee_id', 'employee_code', 'employee_name', 'camera_id', 'camera', 'score', 'result', 'photo'];

function hr_camera_log_dir(): string
{
    return hr_camera_photo_dir() . '/log';
}

/** A log photo's file, or null for a name that is not one. */
function hr_camera_log_photo_path(string $day, string $file): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !preg_match('/^\d{6}_[A-Za-z0-9_-]{1,40}_[0-9a-f]{6}\.jpg$/', $file)) {
        return null;
    }
    return hr_camera_log_dir() . '/' . $day . '/' . $file;
}

/**
 * Add one match to its day's CSV, with the photo made smaller.
 * $entry: seen_at (unix), employee_id, employee_code, employee_name, camera_id, camera, score.
 */
function hr_camera_log_write(array $entry, string $result, ?string $jpeg): void
{
    if (!hr_camera_photo_prepare_dir()) {
        return;
    }
    $seen = (new DateTimeImmutable('@' . (int)$entry['seen_at']))->setTimezone(new DateTimeZone('Asia/Dubai'));
    $day = $seen->format('Y-m-d');
    $dir = hr_camera_log_dir() . '/' . $day;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return;
    }
    $photo = '';
    if ($jpeg !== null) {
        $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$entry['employee_code']) ?: 'x';
        $photo = $seen->format('His') . '_' . substr($code, 0, 40) . '_' . bin2hex(random_bytes(3)) . '.jpg';
        $small = null;
        if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($jpeg))) {
            $w = imagesx($img);
            $h = imagesy($img);
            $scale = min(1, HR_CAMERA_LOG_PHOTO_PX / max($w, $h));
            $out = imagecreatetruecolor(max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale)));
            imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
            ob_start();
            imagejpeg($out, null, 75);
            $small = ob_get_clean();
        }
        if (@file_put_contents($dir . '/' . $photo, $small ?: $jpeg) === false) {
            $photo = '';
        }
    }
    $fh = @fopen(hr_camera_log_dir() . '/' . $day . '.csv', 'a');
    if (!$fh) {
        return;
    }
    flock($fh, LOCK_EX);
    if (fstat($fh)['size'] === 0) {
        fputcsv($fh, HR_CAMERA_LOG_COLUMNS);
    }
    fputcsv($fh, [
        $seen->format('H:i:s'), (int)$entry['employee_id'], (string)$entry['employee_code'], (string)$entry['employee_name'],
        $entry['camera_id'] !== null ? (int)$entry['camera_id'] : '', (string)($entry['camera'] ?? ''),
        $entry['score'] !== null ? number_format((float)$entry['score'], 2, '.', '') : '', $result, $photo,
    ]);
    flock($fh, LOCK_UN);
    fclose($fh);
}

/** One logged match by its photo, or null: what HR picked on the Camera log. */
function hr_camera_log_find(string $day, string $photo): ?array
{
    if (hr_camera_log_photo_path($day, $photo) === null) {
        return null;
    }
    foreach (hr_camera_log_read($day, $day) as $r) {
        if ($r['photo'] === $photo) {
            return $r;
        }
    }
    return null;
}

/** Every logged match from $from to $to (Y-m-d), each row keyed by the columns plus 'date'; newest first. */
function hr_camera_log_read(string $from, string $to): array
{
    $rows = [];
    for ($d = $to; $d >= $from; $d = date('Y-m-d', strtotime($d . ' -1 day'))) {
        $fh = @fopen(hr_camera_log_dir() . '/' . $d . '.csv', 'r');
        if (!$fh) {
            continue;
        }
        $head = fgetcsv($fh);
        $day = [];
        while (($r = fgetcsv($fh)) !== false) {
            if ($head && count($r) === count($head)) {
                $day[] = ['date' => $d] + array_combine($head, $r);
            }
        }
        fclose($fh);
        // By the time seen, newest first: a match sent late (internet was down) comes in out of order.
        usort($day, static fn($a, $b) => strcmp($b['time'], $a['time']));
        $rows = array_merge($rows, $day);
    }
    return $rows;
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

/** Create the face folder, locked: nothing in it (or under it) is ever served directly. */
function hr_camera_face_prepare_dir(): bool
{
    $dir = hr_camera_face_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess',
            "# Staff face photos for the attendance cameras: served only through hr/attendance_camera_face.php.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return true;
}

/** Save an image file as this person's camera photo: re-drawn as a JPEG, at most 1000 px. Null on success, else why not. */
function hr_camera_face_save(int $employeeId, array $file, ?string $to = null): ?string
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
    if (!hr_camera_face_prepare_dir()) {
        return 'The photo folder could not be created.';
    }
    $path = $to ?? hr_camera_face_upload_path($employeeId);
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

/* ---------- Reference faces: clear matches the cameras kept for a person ---------- */

/**
 * When a camera matches someone clearly (0.50 or more against their own photo),
 * the PC sends that face here and every camera PC then compares with it too.
 * Only the closest HR_CAMERA_LEARNED_KEEP are kept. They sit beside the face
 * photo and never replace it: the uploaded or profile photo is not touched.
 */
const HR_CAMERA_LEARNED_KEEP = 5;

function hr_camera_learned_dir(int $employeeId): string
{
    return hr_camera_face_dir() . '/learned/' . $employeeId;
}

/** A file name the PC or HR gave: the match and when, e.g. 0.623_1793500000.jpg. Null when not one. */
function hr_camera_learned_path(int $employeeId, string $name): ?string
{
    return preg_match('/^[01]\.\d{3}_\d{1,12}\.jpg$/', $name) ? hr_camera_learned_dir($employeeId) . '/' . $name : null;
}

/** This person's reference faces, closest first: [['name', 'score', 'at' => unix time]]. */
function hr_camera_learned(int $employeeId): array
{
    $out = [];
    foreach (glob(hr_camera_learned_dir($employeeId) . '/*.jpg') ?: [] as $file) {
        if (preg_match('/^([01]\.\d{3})_(\d{1,12})\.jpg$/', basename($file), $m)) {
            $out[] = ['name' => basename($file), 'score' => (float)$m[1], 'at' => (int)$m[2]];
        }
    }
    usort($out, static fn($a, $b) => [$b['score'], $b['at']] <=> [$a['score'], $a['at']]);
    return $out;
}

/** Keep a face the camera matched, when it is among the person's closest. False when not kept. */
function hr_camera_learned_add(int $employeeId, string $jpeg, float $score, bool $byHr = false): bool
{
    $score = max(0.0, min(1.0, $score));
    $have = hr_camera_learned($employeeId);
    if (count($have) >= HR_CAMERA_LEARNED_KEEP && $score <= $have[count($have) - 1]['score']) {
        // HR chose this one (Camera log): it takes the place of the least alike.
        if (!$byHr) {
            return false;
        }
        @unlink(hr_camera_learned_dir($employeeId) . '/' . $have[count($have) - 1]['name']);
    }
    $dir = hr_camera_learned_dir($employeeId);
    if (!hr_camera_face_prepare_dir() || (!is_dir($dir) && !@mkdir($dir, 0755, true))) {
        return false;
    }
    $path = hr_camera_learned_path($employeeId, sprintf('%.3f_%d.jpg', $score, time()));
    if (@file_put_contents($path . '.tmp', $jpeg) === false || !@rename($path . '.tmp', $path)) {
        return false;
    }
    foreach (array_slice(hr_camera_learned($employeeId), HR_CAMERA_LEARNED_KEEP) as $old) {
        @unlink($dir . '/' . $old['name']);
    }
    return true;
}

function hr_camera_learned_delete(int $employeeId, string $name): bool
{
    $path = hr_camera_learned_path($employeeId, $name);
    return $path !== null && is_file($path) && @unlink($path);
}

/** Ticked people's reference faces for the PC: employee code => [file names]. */
function hr_camera_learned_versions(PDO $conn): array
{
    if (!hr_camera_people_ready($conn)) {
        return [];
    }
    $out = [];
    $rows = $conn->query("SELECT e.id, e.employee_code FROM hr_attendance_camera_people p JOIN employees e ON e.id = p.employee_id
                           WHERE e.employee_code <> '' ORDER BY e.employee_code")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if ($names = array_column(hr_camera_learned((int)$r['id']), 'name')) {
            $out[strtoupper($r['employee_code'])] = $names;
        }
    }
    return $out;
}

/* ---------- Best match so far: the closest any camera has seen each person ---------- */

/**
 * One per person, whatever the match (even 0.40), replaced only by a closer
 * one. For HR to see how well the cameras know someone; never used for matching.
 * File: best/<employee id>_<match>.jpg, ..._ref.jpg once HR used it as a reference.
 */
function hr_camera_best_dir(): string
{
    return hr_camera_face_dir() . '/best';
}

/** ['name', 'score', 'at' => unix time, 'used' => already a reference, 'path'] or null when none yet. */
function hr_camera_best(int $employeeId): ?array
{
    foreach (glob(hr_camera_best_dir() . '/' . $employeeId . '_*.jpg') ?: [] as $file) {
        if (preg_match('/^\d+_([01]\.\d{3})(_ref)?\.jpg$/', basename($file), $m)) {
            return ['name' => basename($file), 'score' => (float)$m[1], 'at' => (int)filemtime($file),
                    'used' => !empty($m[2]), 'path' => $file];
        }
    }
    return null;
}

/** Keep the face when it is closer than the one kept. Returns the best match now kept (0 when none). */
function hr_camera_best_offer(int $employeeId, string $jpeg, float $score): float
{
    $score = max(0.0, min(1.0, round($score, 3)));
    $have = hr_camera_best($employeeId);
    if ($have && $score <= $have['score']) {
        return $have['score'];
    }
    $dir = hr_camera_best_dir();
    if (!hr_camera_face_prepare_dir() || (!is_dir($dir) && !@mkdir($dir, 0755, true))) {
        return $have['score'] ?? 0.0;
    }
    $path = $dir . '/' . $employeeId . '_' . sprintf('%.3f', $score) . '.jpg';
    if (@file_put_contents($path . '.tmp', $jpeg) === false || !@rename($path . '.tmp', $path)) {
        return $have['score'] ?? 0.0;
    }
    if ($have) {
        @unlink($have['path']);
    }
    return $score;
}

/** Ticked people's best match so far, for the PC: employee code => match. */
function hr_camera_best_scores(PDO $conn): array
{
    if (!hr_camera_people_ready($conn)) {
        return [];
    }
    $out = [];
    $rows = $conn->query("SELECT e.id, e.employee_code FROM hr_attendance_camera_people p JOIN employees e ON e.id = p.employee_id
                           WHERE e.employee_code <> '' ORDER BY e.employee_code")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if ($best = hr_camera_best((int)$r['id'])) {
            $out[strtoupper($r['employee_code'])] = $best['score'];
        }
    }
    return $out;
}

/** HR's "Use as reference" from this match up: below it the best match is shown only. */
const HR_CAMERA_BEST_USE_MIN = 0.40;

/**
 * HR's "Use as reference": a copy of the best match becomes one of the
 * reference faces, and the best match stays (marked used). HR chose it, so it
 * stays even when it is the lowest; the lowest other goes when there are more
 * than HR_CAMERA_LEARNED_KEEP.
 */
function hr_camera_best_use(int $employeeId): bool
{
    $best = hr_camera_best($employeeId);
    $dir = hr_camera_learned_dir($employeeId);
    if (!$best || $best['used'] || $best['score'] < HR_CAMERA_BEST_USE_MIN || (!is_dir($dir) && !@mkdir($dir, 0755, true))) {
        return false;
    }
    $name = sprintf('%.3f_%d.jpg', $best['score'], time());
    if (!@copy($best['path'], $dir . '/' . $name)) {
        return false;
    }
    $mtime = (int)filemtime($best['path']);
    $used = preg_replace('/\.jpg$/', '_ref.jpg', $best['path']);
    if (@rename($best['path'], $used)) {
        @touch($used, $mtime);  // still "seen at" the time the camera took it
    }
    $others = array_values(array_filter(hr_camera_learned($employeeId), static fn($l) => $l['name'] !== $name));
    foreach (array_slice($others, HR_CAMERA_LEARNED_KEEP - 1) as $old) {
        @unlink($dir . '/' . $old['name']);
    }
    return true;
}

function hr_camera_best_delete(int $employeeId): bool
{
    $have = hr_camera_best($employeeId);
    return $have !== null && @unlink($have['path']);
}

/* ---------- Not this person: faces HR said are someone else ---------- */

/**
 * HR's "Not this person" on a reference face or the best match moves the face
 * here. The cameras then skip this person for any face that looks more like
 * one of these than like them, so the same wrong match does not come back.
 * Undo deletes it. At most HR_CAMERA_REJECTED_KEEP each, the newest.
 */
const HR_CAMERA_REJECTED_KEEP = 20;

function hr_camera_rejected_dir(int $employeeId): string
{
    return hr_camera_face_dir() . '/rejected/' . $employeeId;
}

function hr_camera_rejected_path(int $employeeId, string $name): ?string
{
    return preg_match('/^\d{1,12}_[0-9a-f]{6}\.jpg$/', $name) ? hr_camera_rejected_dir($employeeId) . '/' . $name : null;
}

/** This person's "Not this person" faces, newest first: [['name', 'at']]. */
function hr_camera_rejected(int $employeeId): array
{
    $out = [];
    foreach (glob(hr_camera_rejected_dir($employeeId) . '/*.jpg') ?: [] as $file) {
        if (preg_match('/^(\d{1,12})_[0-9a-f]{6}\.jpg$/', basename($file), $m)) {
            $out[] = ['name' => basename($file), 'at' => (int)$m[1]];
        }
    }
    usort($out, static fn($a, $b) => [$b['at'], $b['name']] <=> [$a['at'], $a['name']]);
    return $out;
}

/** Move a reference face or the best match here. */
function hr_camera_reject(int $employeeId, string $from): bool
{
    $dir = hr_camera_rejected_dir($employeeId);
    if (!is_file($from) || !hr_camera_face_prepare_dir() || (!is_dir($dir) && !@mkdir($dir, 0755, true))) {
        return false;
    }
    if (!@rename($from, $dir . '/' . time() . '_' . bin2hex(random_bytes(3)) . '.jpg')) {
        return false;
    }
    foreach (array_slice(hr_camera_rejected($employeeId), HR_CAMERA_REJECTED_KEEP) as $old) {
        @unlink($dir . '/' . $old['name']);
    }
    return true;
}

function hr_camera_rejected_delete(int $employeeId, string $name): bool
{
    $path = hr_camera_rejected_path($employeeId, $name);
    return $path !== null && is_file($path) && @unlink($path);
}

/** Ticked people's "Not this person" faces for the PC: employee code => [file names]. */
function hr_camera_rejected_versions(PDO $conn): array
{
    if (!hr_camera_people_ready($conn)) {
        return [];
    }
    $out = [];
    $rows = $conn->query("SELECT e.id, e.employee_code FROM hr_attendance_camera_people p JOIN employees e ON e.id = p.employee_id
                           WHERE e.employee_code <> '' ORDER BY e.employee_code")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if ($names = array_column(hr_camera_rejected((int)$r['id']), 'name')) {
            $out[strtoupper($r['employee_code'])] = $names;
        }
    }
    return $out;
}

/* ---------- More photos: extra face photos HR uploads for a person ---------- */

/**
 * Beside the main face photo, HR can upload more of the same person (another
 * angle, glasses, a different light). The camera PC finds the face in each and
 * treats them like the main photo. At most HR_CAMERA_EXTRA_KEEP.
 */
const HR_CAMERA_EXTRA_KEEP = 10;

function hr_camera_extra_dir(int $employeeId): string
{
    return hr_camera_face_dir() . '/extra/' . $employeeId;
}

function hr_camera_extra_path(int $employeeId, string $name): ?string
{
    return preg_match('/^\d{1,12}_[0-9a-f]{6}\.jpg$/', $name) ? hr_camera_extra_dir($employeeId) . '/' . $name : null;
}

/** This person's extra photos, newest first: [['name', 'at']]. */
function hr_camera_extra(int $employeeId): array
{
    $out = [];
    foreach (glob(hr_camera_extra_dir($employeeId) . '/*.jpg') ?: [] as $file) {
        if (preg_match('/^(\d{1,12})_[0-9a-f]{6}\.jpg$/', basename($file), $m)) {
            $out[] = ['name' => basename($file), 'at' => (int)$m[1]];
        }
    }
    usort($out, static fn($a, $b) => [$b['at'], $b['name']] <=> [$a['at'], $a['name']]);
    return $out;
}

/** Add an uploaded photo. Null on success, else why not. */
function hr_camera_extra_add(int $employeeId, array $file): ?string
{
    if (count(hr_camera_extra($employeeId)) >= HR_CAMERA_EXTRA_KEEP) {
        return 'Up to ' . HR_CAMERA_EXTRA_KEEP . ' more photos. Delete one first.';
    }
    $dir = hr_camera_extra_dir($employeeId);
    if (!hr_camera_face_prepare_dir() || (!is_dir($dir) && !@mkdir($dir, 0755, true))) {
        return 'The photo folder could not be created.';
    }
    return hr_camera_face_save($employeeId, $file, $dir . '/' . time() . '_' . bin2hex(random_bytes(3)) . '.jpg');
}

function hr_camera_extra_delete(int $employeeId, string $name): bool
{
    $path = hr_camera_extra_path($employeeId, $name);
    return $path !== null && is_file($path) && @unlink($path);
}

/** Ticked people's extra photos for the PC: employee code => [file names]. */
function hr_camera_extra_versions(PDO $conn): array
{
    if (!hr_camera_people_ready($conn)) {
        return [];
    }
    $out = [];
    $rows = $conn->query("SELECT e.id, e.employee_code FROM hr_attendance_camera_people p JOIN employees e ON e.id = p.employee_id
                           WHERE e.employee_code <> '' ORDER BY e.employee_code")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if ($names = array_column(hr_camera_extra((int)$r['id']), 'name')) {
            $out[strtoupper($r['employee_code'])] = $names;
        }
    }
    return $out;
}
