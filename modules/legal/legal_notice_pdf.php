<?php
/**
 * Generate (if needed) and stream a legal notice PDF.
 * ?id=<notice> &mode=view|download
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/legal_helper.php';
require_once __DIR__ . '/includes/legal_notice_pdf_generator.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$currentCompanyId = current_company_id($conn) ?: 1;
$noticeId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
$mode = ($_GET['mode'] ?? 'view') === 'download' ? 'download' : 'view';

$stmt = $conn->prepare("SELECT * FROM re_legal_notices WHERE id = ? AND company_id = ?");
$stmt->execute([$noticeId, $currentCompanyId]);
$notice = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$notice) {
    http_response_code(404);
    exit('Notice not found.');
}

$basePath = dirname(__DIR__, 2);

try {
    // Always regenerate to reflect latest edits.
    $generator = new LegalNoticePDFGenerator($conn, $currentCompanyId);
    $relPath = $generator->generate($noticeId);
    $absPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relPath);

    if (!is_file($absPath)) {
        throw new Exception('Generated PDF not found.');
    }

    $filename = 'Legal_Notice_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $notice['reference_number']) . '.pdf';
    $disposition = $mode === 'download' ? 'attachment' : 'inline';

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($absPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    readfile($absPath);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error generating PDF: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}
