<?php
/**
 * Real Estate Module - Move-In Inspection Form (PDF)
 * GET id=<move-in id> &photos=1|0 &download=1 (otherwise shown inline for preview)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/move_in_form_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$currentCompanyId = (int)(current_company_id($conn) ?: 1);
$moveInId = (int)($_GET['id'] ?? 0);

$data = $moveInId && rms_ready($conn) ? rmf_load($conn, $currentCompanyId, $moveInId) : null;
if (!$data) {
    http_response_code(404);
    exit('Move-in not found.');
}

try {
    $pdf = rmf_pdf($conn, $data, ($_GET['photos'] ?? '1') !== '0', [
        'meters' => !empty($_GET['meters']),
        'services' => !empty($_GET['services']),
        'item_remarks' => !empty($_GET['item_remarks']),
        'insp_remarks' => !empty($_GET['insp_remarks']),
    ]);
} catch (Throwable $e) {
    error_log('Move-in form PDF failed (move-in ' . $moveInId . '): ' . $e->getMessage());
    http_response_code(500);
    exit('The inspection form could not be generated: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$name = rmf_file_name($data);
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
