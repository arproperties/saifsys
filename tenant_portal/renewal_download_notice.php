<?php
/**
 * Stream renewal notice PDF for tenant (scoped to current lease).
 */
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';
require_tenant_login($conn);
require_once __DIR__ . '/includes/tenant_lease_loader.php';
require_once __DIR__ . '/includes/renewal_portal_helper.php';

if (!$lease) {
    http_response_code(403);
    exit('Forbidden');
}
$workflowId = (int)($_GET['id'] ?? 0);
$wf = $workflowId ? tenant_renewal_fetch_workflow($conn, $workflowId, (int)$lease['lease_id'], (int)$lease['company_id']) : null;
$rel = trim((string)($wf['renewal_notice_pdf_path'] ?? ''));
if ($rel === '' || !$wf) {
    http_response_code(404);
    exit('Not found');
}
$basePath = dirname(__DIR__);
$abs = realpath($basePath . '/' . ltrim($rel, '/'));
$root = realpath($basePath);
if ($abs === false || $root === false || strpos($abs, $root) !== 0 || !is_readable($abs)) {
    http_response_code(404);
    exit('Not found');
}
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="renewal_notice.pdf"');
header('Content-Length: ' . filesize($abs));
readfile($abs);
exit;
