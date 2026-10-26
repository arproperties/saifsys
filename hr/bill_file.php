<?php
/**
 * Employee bill evidence file server.
 *
 * Stored evidence_path values are never linked directly (uploads/hr_bills is
 * denied to the web). This re-checks the HR roles, resolves the file under
 * uploads/hr_bills only, and serves PDFs and images inline with a type taken
 * from the extension we assigned on upload.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_employee_bills.php';
require_role(['Owner', 'Admin', 'HR'], $conn);

$billId = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT evidence_path, evidence_name FROM employee_bills WHERE id = ? LIMIT 1");
$stmt->execute([$billId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row || empty($row['evidence_path'])) {
    http_response_code(404);
    exit('Evidence not found.');
}

$projectRoot = realpath(__DIR__ . '/..');
$real = realpath($projectRoot . '/' . ltrim((string)$row['evidence_path'], '/'));
$billsRoot = realpath($projectRoot . '/' . HR_BILLS_EVIDENCE_REL);
if (!$real || !$billsRoot || strpos($real, $billsRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
    http_response_code(404);
    exit('File not found on server.');
}

$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$download = ($_GET['mode'] ?? '') === 'download' || !isset($types[$ext]);

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . ($download ? 'application/octet-stream' : $types[$ext]));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . rawurlencode($row['evidence_name'] ?: basename($real)) . '"');
header('Content-Length: ' . filesize($real));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; style-src \'unsafe-inline\'');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($real);
exit;
