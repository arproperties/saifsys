<?php
/**
 * Process one batch of pending Bulk Tenant Email recipients (restart-safe).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/bulk_tenant_email_helper.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $companyId = re_bulk_email_require_access($conn);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required']);
        exit;
    }
    csrf_verify(true);

    $campaignId = (int)($_POST['campaign_id'] ?? 0);
    if ($campaignId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'campaign_id required']);
        exit;
    }

    @set_time_limit(120);
    ignore_user_abort(true);

    $result = re_bulk_email_process_batch($conn, $companyId, $campaignId, RE_BULK_EMAIL_BATCH_SIZE);
    $campaign = re_bulk_email_get_campaign($conn, $companyId, $campaignId);
    $result['sent_total'] = (int)($campaign['sent_count'] ?? 0);
    $result['failed_total'] = (int)($campaign['failed_count'] ?? 0);
    $result['pending_remaining'] = (int)($campaign['pending_count'] ?? $result['pending_remaining'] ?? 0);
    $result['campaign_status'] = $campaign['status'] ?? ($result['campaign_status'] ?? null);
    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
