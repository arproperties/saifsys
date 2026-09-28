<?php
// hr/fleet_check_media.php?id= — one photo or voice note a driver attached to
// a Problem on their daily check. Same access as HR → Fleet → Daily Checks.
// The files sit in uploads/fleet_checks/, which the web server refuses to serve.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_fleet.php';
require_once __DIR__ . '/includes/hr_fleet_ui.php';
require_once __DIR__ . '/includes/hr_fleet_checks.php';
require_role(HR_FLEET_ROLES, $conn);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close(); // an audio player's range requests must not queue on the session lock
}

$notFound = static function (): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
};

if (!fleet_check_media_ready($conn)) {
    $notFound();
}
$stmt = $conn->prepare("SELECT file_path FROM fleet_daily_check_media WHERE id = ? LIMIT 1");
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$rel = $stmt->fetchColumn();
if ($rel === false) {
    $notFound();
}

$root = dirname(__DIR__);
$baseDir = realpath($root . '/uploads/fleet_checks');
$absPath = realpath($root . '/' . ltrim((string)$rel, '/'));
if ($baseDir === false || $absPath === false || strpos($absPath, $baseDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($absPath)) {
    $notFound();
}

// Content-Type from the extension, never from the file's bytes.
$types = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
];
$type = $types[strtolower(pathinfo($absPath, PATHINFO_EXTENSION))] ?? null;
if ($type === null) {
    $notFound();
}

$size = filesize($absPath);
$start = 0;
$end = $size - 1;
// Ranges, so an audio player can seek (and Safari will play at all).
if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') {
        $start = max(0, $size - (int)$m[2]);
    } else {
        $start = (int)$m[1];
        $end = $m[2] !== '' ? min((int)$m[2], $size - 1) : $size - 1;
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

header('Content-Type: ' . $type);
header('Content-Length: ' . ($end - $start + 1));
header('Accept-Ranges: bytes');
header('Content-Disposition: inline; filename="' . basename($absPath) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');

$fh = fopen($absPath, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) {
    $chunk = fread($fh, (int)min(65536, $left));
    echo $chunk;
    $left -= strlen($chunk);
}
fclose($fh);
