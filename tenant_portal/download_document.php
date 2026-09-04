<?php
/**
 * Tenant Portal — Secure document download (scope to tenant's lease/unit)
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

require_tenant_login($conn);
require_once __DIR__ . '/includes/tenant_lease_loader.php';

if (!$lease) {
    http_response_code(403);
    exit('Access denied.');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    exit('Invalid request.');
}

$lease_id = (int)$lease['lease_id'];
$unit_id = (int)$lease['unit_id'];
$company_id = (int)$lease['company_id'];

$stmt = $conn->prepare("
    SELECT id, file_name, file_path
    FROM re_documents
    WHERE id = ? AND company_id = ? AND (
        (related_type = 'lease' AND related_id = ?) OR
        (related_type = 'unit' AND related_id = ?)
    )
");
$stmt->execute([$id, $company_id, $lease_id, $unit_id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc || empty($doc['file_path'])) {
    http_response_code(404);
    exit('Document not found.');
}

$basePath = dirname(__DIR__) . '/';
$fullPath = $basePath . $doc['file_path'];
$fullPath = preg_replace('#/+#', '/', $fullPath);

if (!is_file($fullPath) || !is_readable($fullPath)) {
    http_response_code(404);
    exit('File not found.');
}

$name = $doc['file_name'] ?: basename($doc['file_path']);
$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $mime = finfo_file($finfo, $fullPath) ?: $mime;
        finfo_close($finfo);
    }
}
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . addslashes($name) . '"');
header('Content-Length: ' . filesize($fullPath));
readfile($fullPath);
exit;
