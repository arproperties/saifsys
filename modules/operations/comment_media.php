<?php
/**
 * Operations — serve a photo or voice note attached to a job message.
 *
 * Direct access to uploads/operations is denied, so every one of these goes
 * through this endpoint: login, module access, and company scope.
 *
 * The field app has its own copy of this at api/mobile/ops/ops_endpoints.php
 * (ops_api_handle_comment_media_serve), which additionally requires the job to
 * be assigned to the caller. This one deliberately matches the scope the rest
 * of the web module uses — the office needs to read messages on jobs it did not
 * do — and is written the same way as photo.php beside it.
 *
 * NOTE, so it is not repeated by accident: ops_load_job() scopes by company_id
 * only. It does not re-apply a field-staff "only my jobs" rule, whatever the
 * comment in photo.php says. Nothing here relies on it doing so.
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
$mediaId = (int)($_GET['id'] ?? 0);

$stmt = $conn->prepare("
    SELECT m.file_path, m.job_id
    FROM ops_job_comment_media m
    WHERE m.id = ? AND m.company_id = ?
    LIMIT 1
");
$stmt->execute([$mediaId, $companyId]);
$media = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$media || !ops_load_job($conn, (int)$media['job_id'], $companyId)) {
    http_response_code(404);
    exit('Not found');
}

// Keep the resolved path inside the uploads folder no matter what is stored.
$baseDir = realpath(dirname(__DIR__, 2) . '/uploads/operations');
$absPath = realpath(dirname(__DIR__, 2) . '/' . ltrim($media['file_path'], '/'));
if ($baseDir === false || $absPath === false || strpos($absPath, $baseDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($absPath)) {
    http_response_code(404);
    exit('Not found');
}

// Content-Type from an extension whitelist — never from the file's own sniffed
// type, and never from anything stored in the database.
$ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
$contentType = ops_comment_media_content_type($ext);
if ($contentType === null) {
    http_response_code(404);
    exit('Not found');
}

// Byte ranges matter here: a browser will not play or seek a <video> served
// as one 200 response. Shared with the API's copy of this route.
ops_serve_file_with_ranges($absPath, $contentType);
