<?php
/**
 * Operations — serve one piece of before/after evidence, photo or video.
 * Direct access to uploads/operations is denied, so every file goes through
 * this endpoint: login, module access, company scope, and — for field staff —
 * the job must be their own.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/ops_helper.php';

require_login(get_application_web_root() . '/login');
ops_require_access($conn);

$companyId = ops_company_id($conn);
$photoId = (int)($_GET['id'] ?? 0);

$stmt = $conn->prepare("
    SELECT p.file_path, p.job_id
    FROM ops_job_photos p
    WHERE p.id = ? AND p.company_id = ?
    LIMIT 1
");
$stmt->execute([$photoId, $companyId]);
$photo = $stmt->fetch(PDO::FETCH_ASSOC);

// ops_load_job re-applies the field-staff "only my jobs" rule.
if (!$photo || !ops_load_job($conn, (int)$photo['job_id'], $companyId)) {
    http_response_code(404);
    exit('Not found');
}

// Keep the resolved path inside the uploads folder no matter what is stored.
$baseDir = realpath(dirname(__DIR__, 2) . '/uploads/operations');
$absPath = realpath(dirname(__DIR__, 2) . '/' . ltrim($photo['file_path'], '/'));
if ($baseDir === false || $absPath === false || strpos($absPath, $baseDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($absPath)) {
    http_response_code(404);
    exit('Not found');
}

// Content-Type from an extension whitelist — never from the file's own sniffed
// type, and never from media_kind in the database. The whitelist is the one in
// ops_comment_media_content_type(), which already covers mp4 and mov.
$ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
$contentType = ops_comment_media_content_type($ext);
// Audio has no business in the before/after grid even though the shared
// whitelist knows how to serve it.
if ($contentType === null || strpos($contentType, 'audio/') === 0) {
    http_response_code(404);
    exit('Not found');
}

header('Content-Disposition: inline; filename="' . basename($absPath) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
// Ranges, because a browser playing a <video> asks for them and Safari will
// not play at all if the server answers with the whole file. Stills go the
// same way so there is one path out of this module, not two.
ops_serve_file_with_ranges($absPath, $contentType);
