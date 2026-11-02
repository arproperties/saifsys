<?php
/**
 * Finance > a file attached to a voucher (?id=), shown in the browser.
 * Files sit in uploads/fin_attachments, which refuses direct links; this page
 * checks the login, the role and the company first. The type it is served as
 * comes from the extension the upload was checked against, never from the file.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_supplier_invoice_helper.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT company_id, file_name, stored_name FROM fin_voucher_attachments WHERE id = ?");
$stmt->execute([$id]);
$f = $stmt->fetch(PDO::FETCH_ASSOC);

// Another company's file only for someone who works in that company.
$allowed = $f && ((int)$f['company_id'] === $companyId || in_array((int)$f['company_id'], array_map('intval', array_column($userCompanies, 'id')), true));
$ext = $f ? strtolower(pathinfo($f['stored_name'], PATHINFO_EXTENSION)) : '';
$path = $f ? realpath(FIN_ATTACH_DIR . '/' . basename($f['stored_name'])) : false;
if (!$allowed || !isset(FIN_ATTACH_TYPES[$ext]) || !$path || !is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
$ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $f['file_name']);
header('Content-Type: ' . FIN_ATTACH_TYPES[$ext]);
header('Content-Disposition: ' . (($_GET['mode'] ?? '') === 'download' ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($f['file_name']));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($path);
exit;
