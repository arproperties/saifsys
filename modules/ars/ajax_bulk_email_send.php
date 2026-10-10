<?php
/**
 * Bulk Email — send the next batch of one email. Called repeatedly by
 * bulk_email_view.php until nothing is pending.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_bulk_email.php';

header('Content-Type: application/json; charset=utf-8');
$arsCompanyId = arsPageAuth($conn);
ars_ajax_csrf_verify();

$campaignId = (int)($_POST['campaign_id'] ?? 0);
if ($campaignId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'campaign_id required']);
    exit;
}

// Let the page poll and the user browse while this request is sending.
session_write_close();
@set_time_limit(180);
ignore_user_abort(true);

try {
    ars_bulk_email_ensure_schema($conn);
    $result = ars_bulk_email_process_batch($conn, $arsCompanyId, $campaignId);
    $campaign = ars_bulk_email_get_campaign($conn, $arsCompanyId, $campaignId);
    $result['sent_total'] = (int)($campaign['sent_count'] ?? 0);
    $result['failed_total'] = (int)($campaign['failed_count'] ?? 0);
    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
