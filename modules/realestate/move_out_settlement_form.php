<?php
/**
 * Real Estate Module - Move-Out Deposit Settlement Statement (PDF)
 * GET id=<move-out id> &download=1 (otherwise shown inline for preview)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/move_out_form_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$currentCompanyId = (int)(current_company_id($conn) ?: 1);
$moveOutId = (int)($_GET['id'] ?? 0);

$data = $moveOutId ? rmos_load($conn, $currentCompanyId, $moveOutId) : null;
if (!$data) {
    http_response_code(404);
    exit('Move-out not found.');
}

try {
    $pdf = rmos_pdf($conn, $data);
} catch (Throwable $e) {
    error_log('Deposit settlement PDF failed (move-out ' . $moveOutId . '): ' . $e->getMessage());
    http_response_code(500);
    exit('The settlement statement could not be generated: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$name = rmos_file_name($data);
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
