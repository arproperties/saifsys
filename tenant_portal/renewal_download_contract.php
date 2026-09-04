<?php
/**
 * Stream draft renewal contract PDF for tenant when workflow is contract_ready/signed.
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
if (!$wf || !in_array($wf['status'], ['contract_ready', 'signed', 'converted', 'completed'], true)) {
    http_response_code(403);
    exit('Forbidden');
}
$nlid = (int)($wf['new_lease_id'] ?? 0);
if (!$nlid) {
    http_response_code(404);
    exit('Not found');
}
$st = $conn->prepare("SELECT generated_contract_path FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1");
$st->execute([$nlid, (int)$lease['company_id']]);
$rel = trim((string)$st->fetchColumn());
if ($rel === '') {
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
header('Content-Disposition: inline; filename="renewal_contract.pdf"');
header('Content-Length: ' . filesize($abs));
readfile($abs);
exit;
