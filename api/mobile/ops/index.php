<?php
/**
 * Operations field app API — front controller.
 * Same shape as api/customer/v1/index.php: one file, one route table,
 * the {"ok":true,"data":…} / {"ok":false,"error":…} envelope from
 * includes/customer_api.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/ops_api.php';
require_once __DIR__ . '/ops_endpoints.php';

ops_api_boot();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = ops_api_parse_route();

if ($route === 'health' && $method === 'GET') {
    customer_api_send_ok(['version' => 'ops-v1', 'dev_mode' => ops_api_dev_mode()]);
}

// Sign-in. The only route that answers without a token, because it is where
// tokens come from: four digits in, a token out. See ops_api_handle_pin_login().
if ($route === 'auth/pin' && $method === 'POST') {
    ops_api_handle_pin_login($conn);
}

// The app reporting something the server could not have seen — an upload that
// never opened a connection, say. Dev builds only, and deliberately above the
// token check: "my token was refused" is one of the things it reports.
if ($route === 'dev/log' && $method === 'POST' && ops_api_dev_mode()) {
    ops_api_handle_dev_log();
}

// Everything past this point is scoped to one person, always — and that person
// is named by a verified token claim, never by anything the caller sends.
$user = ops_api_current_user($conn);

if ($route === 'auth/me' && $method === 'GET') {
    ops_api_handle_me($conn, $user);
}

if ($route === 'jobs' && $method === 'GET') {
    ops_api_handle_jobs_list($conn, $user);
}

if ($method === 'GET' && preg_match('#^jobs/(\d+)$#', $route, $m)) {
    ops_api_handle_job_detail($conn, $user, (int)$m[1]);
}

if ($method === 'POST' && preg_match('#^jobs/(\d+)/start$#', $route, $m)) {
    ops_api_handle_job_start($conn, $user, (int)$m[1]);
}

if ($method === 'POST' && preg_match('#^jobs/(\d+)/finish$#', $route, $m)) {
    ops_api_handle_job_finish($conn, $user, (int)$m[1]);
}

if ($method === 'POST' && preg_match('#^jobs/(\d+)/photos$#', $route, $m)) {
    ops_api_handle_job_photo($conn, $user, (int)$m[1]);
}

if ($method === 'DELETE' && preg_match('#^jobs/(\d+)/photos/(\d+)$#', $route, $m)) {
    ops_api_handle_photo_delete($conn, $user, (int)$m[1], (int)$m[2]);
}

if ($method === 'GET' && preg_match('#^photos/(\d+)$#', $route, $m)) {
    ops_api_handle_photo_serve($conn, $user, (int)$m[1]);
}

// A photo or voice note attached to a message. Separate from photos/{id}
// because it reads a different table with different rules — see
// migrations/ops_comment_media.sql.
if ($method === 'GET' && preg_match('#^comment-media/(\d+)$#', $route, $m)) {
    ops_api_handle_comment_media_serve($conn, $user, (int)$m[1]);
}

// Messages, including "I need something" — there is no materials route, and no
// per-job material record on either side. The office reads the message and
// takes what it gave out off the Stock page. See the note above
// ops_api_handle_job_comment().
if ($method === 'POST' && preg_match('#^jobs/(\d+)/comments$#', $route, $m)) {
    ops_api_handle_job_comment($conn, $user, (int)$m[1]);
}

customer_api_send_error('not_found', 'Not found', 404);
