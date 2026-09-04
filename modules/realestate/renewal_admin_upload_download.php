<?php
/**
 * Download tenant-uploaded renewal document (admin, Real Estate module).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$uploadId = (int)($_GET['id'] ?? 0);
$companyId = current_company_id($conn) ?: 1;

$st = $conn->prepare("
    SELECT u.* FROM re_renewal_tenant_uploads u
    INNER JOIN re_lease_renewal_workflows rw ON rw.id = u.workflow_id
    INNER JOIN re_leases l ON l.id = rw.lease_id AND l.company_id = ?
    WHERE u.id = ?
    LIMIT 1
");
$st->execute([$companyId, $uploadId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Not found');
}

$basePath = dirname(__DIR__, 2);
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
