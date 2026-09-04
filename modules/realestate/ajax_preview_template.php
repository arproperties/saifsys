<?php
/**
 * AJAX endpoint to preview template content in bilingual format
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

$content = $_POST['content'] ?? '';

if (empty($content)) {
    echo json_encode(['error' => 'No content provided']);
    exit;
}

// Format the content using the bilingual formatter
require_once __DIR__ . '/includes/contract_generator.php';
$formattedContent = format_contract_bilingual($content);

echo json_encode(['content' => $formattedContent]);

