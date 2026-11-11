<?php
/**
 * Legal - Issues: serve one evidence file (inline or download), after checking company access.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/legal_helper.php';
require_once __DIR__ . '/includes/legal_issues_helper.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$currentCompanyId = current_company_id($conn) ?: 1;
$st = $conn->prepare("SELECT company_id, file_name, file_path, mime_type FROM re_legal_issue_files WHERE id = ?");
$st->execute([(int)($_GET['id'] ?? 0)]);
$f = $st->fetch(PDO::FETCH_ASSOC);
if (!$f || !legal_issue_can_company($conn, (int)$f['company_id'], $currentCompanyId)) {
    http_response_code(404);
    exit('File not found.');
}

$base = realpath(LEGAL_ISSUE_DIR);
$path = realpath(dirname(__DIR__, 2) . '/' . $f['file_path']);
if (!$base || !$path || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = legal_issue_allowed()[$ext] ?? 'application/octet-stream';
$download = ($_GET['mode'] ?? '') === 'download';
$name = str_replace(['"', "\r", "\n"], '', (string)$f['file_name']);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"');
readfile($path);
