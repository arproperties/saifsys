<?php
/**
 * AJAX endpoint to log document access
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';

header('Content-Type: application/json');

require_login();
$currentCompanyId = current_company_id($conn) ?: 1;
$currentUserId = $_SESSION['user_id'] ?? null;

$input = json_decode(file_get_contents('php://input'), true);
$documentId = (int)($input['document_id'] ?? 0);
$action = $input['action'] ?? 'viewed';

if ($documentId && $currentUserId) {
    // Log access
    $conn->prepare("
        INSERT INTO re_document_access_log
        (company_id, document_id, user_id, action, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([
        $currentCompanyId, $documentId, $currentUserId, $action,
        $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    // Update view/download count
    if ($action === 'viewed') {
        $conn->prepare("
            UPDATE re_documents SET view_count = view_count + 1 WHERE id = ?
        ")->execute([$documentId]);
    } elseif ($action === 'downloaded') {
        $conn->prepare("
            UPDATE re_documents SET download_count = download_count + 1 WHERE id = ?
        ")->execute([$documentId]);
    }
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
}

