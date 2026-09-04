<?php
/**
 * Download a tenant-uploaded renewal document (own upload only).
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
$workflowId = (int)($_GET['w'] ?? 0);
$uploadId = (int)($_GET['u'] ?? 0);
$wf = $workflowId ? tenant_renewal_fetch_workflow($conn, $workflowId, (int)$lease['lease_id'], (int)$lease['company_id']) : null;
if (!$wf) {
    http_response_code(404);
    exit('Not found');
}
$st = $conn->prepare("SELECT * FROM re_renewal_tenant_uploads WHERE id = ? AND workflow_id = ? AND company_id = ? LIMIT 1");
$st->execute([$uploadId, $workflowId, (int)$lease['company_id']]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Not found');
}
$basePath = dirname(__DIR__);
$rel = ltrim((string)$row['stored_path'], '/');
$abs = realpath($basePath . '/' . $rel);
$root = realpath($basePath);
if ($abs === false || $root === false || strpos($abs, $root) !== 0 || !is_readable($abs)) {
    http_response_code(404);
    exit('Not found');
}
$mime = (string)($row['mime_type'] ?: 'application/octet-stream');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($row['original_filename'] ?: 'document') . '"');
header('Content-Length: ' . filesize($abs));
readfile($abs);
exit;
