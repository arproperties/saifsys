<?php
/**
 * Stream generated tenancy contract PDF for current tenant (scoped to leases they can access).
 */
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

require_tenant_login($conn);

$allowed = current_tenant_lease_ids($conn);
if (empty($allowed)) {
    http_response_code(403);
    exit('Forbidden');
}

$lid = (int)($_GET['lease_id'] ?? 0);
if (!$lid) {
    $cur = current_tenant_lease_id($conn);
    $lid = $cur ? (int)$cur : 0;
}
if (!$lid || !in_array($lid, $allowed, true)) {
    http_response_code(403);
    exit('Forbidden');
}

$st = $conn->prepare('SELECT generated_contract_path, company_id FROM re_leases WHERE id = ? LIMIT 1');
$st->execute([$lid]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Not found');
}
$rel = trim((string)($row['generated_contract_path'] ?? ''));
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

$fn = 'tenancy_contract_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$lid) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $fn . '"');
header('Content-Length: ' . filesize($abs));
readfile($abs);
exit;
