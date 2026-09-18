<?php
/**
 * Operations — serve a photo the tenant attached to a maintenance request, on
 * the job that request became.
 *
 * The job decides access, exactly as photo.php: ops_load_job() with the
 * office's company scope. The photo must belong to that job's request, and is
 * only ever sent as an image from uploads/realestate/maintenance — see
 * ops_serve_request_photo().
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/ops_helper.php';
require_once __DIR__ . '/includes/ops_sources.php';

require_login(get_application_web_root() . '/login');
ops_require_access($conn);

$companyId = ops_company_id($conn);
$job = ops_load_job($conn, (int)($_GET['job'] ?? 0), $companyId);
$photoId = (int)($_GET['id'] ?? 0);

$photo = null;
foreach ($job ? ops_request_photos($conn, $job) : [] as $candidate) {
    if ((int)$candidate['id'] === $photoId) {
        $photo = $candidate;
        break;
    }
}

if (!$photo || !ops_serve_request_photo($photo)) {
    http_response_code(404);
    exit('Not found');
}
