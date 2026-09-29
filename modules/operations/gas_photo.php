<?php
/**
 * Operations — serve the photo of the scale behind one gas weight.
 * Same gate as photo.php: login, module access, and the job must be one the
 * office can open. ?id={reading id}&stage=before|after
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/ops_helper.php';
require_once __DIR__ . '/includes/ops_gas.php';

require_login(get_application_web_root() . '/login');
ops_require_access($conn);

$companyId = ops_company_id($conn);
$readingId = (int)($_GET['id'] ?? 0);
$stage = ($_GET['stage'] ?? '') === 'after' ? 'after' : 'before';

$stmt = $conn->prepare("SELECT job_id, before_photo, after_photo FROM ops_job_gas_readings WHERE id = ? LIMIT 1");
$stmt->execute([$readingId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$file = $row && ops_load_job($conn, (int)$row['job_id'], $companyId)
    ? ops_gas_photo_file($stage === 'after' ? $row['after_photo'] : $row['before_photo'])
    : null;
if ($file === null) {
    http_response_code(404);
    exit('Not found');
}

header('Content-Disposition: inline; filename="' . basename($file['path']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
ops_serve_file_with_ranges($file['path'], $file['type']);
