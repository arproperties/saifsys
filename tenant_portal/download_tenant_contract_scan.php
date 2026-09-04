<?php
/**
 * Download tenant-uploaded signed tenancy contract scan (scoped to tenant's lease).
 */
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';
require_once __DIR__ . '/includes/tenancy_contract_scan_helper.php';

require_tenant_login($conn);
require_once __DIR__ . '/includes/tenant_lease_loader.php';

if (!$lease || !tenancy_contract_scan_table_exists($conn)) {
    http_response_code(403);
    exit('Access denied.');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    exit('Invalid request.');
}

$leaseId = (int)$lease['lease_id'];
$companyId = (int)$lease['company_id'];

$st = $conn->prepare("
    SELECT stored_path, original_filename
    FROM re_tenancy_contract_tenant_uploads
    WHERE id = ? AND company_id = ? AND lease_id = ? AND superseded_at IS NULL
    LIMIT 1
");
$st->execute([$id, $companyId, $leaseId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row || empty($row['stored_path'])) {
    http_response_code(404);
    exit('Not found.');
}

$basePath = dirname(__DIR__);
$rel = ltrim((string)$row['stored_path'], '/');
$abs = realpath($basePath . '/' . $rel);
$root = realpath($basePath);
if ($abs === false || $root === false || strpos($abs, $root) !== 0 || !is_readable($abs)) {
    http_response_code(404);
    exit('File not found.');
}

$name = $row['original_filename'] ?: basename($rel);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $name) . '"');
header('Content-Length: ' . filesize($abs));
readfile($abs);
exit;
