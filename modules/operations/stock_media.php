<?php
/**
 * Operations — serve a photo or clip attached to a stock movement.
 *
 * Direct access to uploads/operations is denied, so every one of these goes
 * through here: login, module access, and company scope. Written the same way
 * as comment_media.php and photo.php beside it, deliberately — three copies
 * that read identically are easier to keep right than one that has to know
 * which of three tables it was called for.
 *
 * Scope is the company alone. A movement is not always on a job (a delivery or
 * a stock take has no job_id), so there is no job to hang a narrower rule on,
 * and the Stock page these belong to is already company-wide.
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
    SELECT m.file_path
    FROM ops_stock_move_media m
    JOIN ops_stock_moves s ON s.id = m.move_id AND s.company_id = m.company_id
    WHERE m.id = ? AND m.company_id = ?
    LIMIT 1
");
$stmt->execute([$mediaId, $companyId]);
$media = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$media) {
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
// Audio cannot be attached to a movement; if one ever lands here it is not
// served either.
if ($contentType === null || strpos($contentType, 'audio/') === 0) {
    http_response_code(404);
    exit('Not found');
}

// Byte ranges matter here: a browser will not play or seek a <video> served
// as one 200 response. It sets the inline, nosniff and cache headers too.
ops_serve_file_with_ranges($absPath, $contentType);
