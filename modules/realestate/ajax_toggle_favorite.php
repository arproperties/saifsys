<?php
/**
 * AJAX endpoint to toggle document favorite
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
$isFavorite = !empty($input['is_favorite']);

if ($documentId && $currentUserId) {
    if ($isFavorite) {
        // Remove favorite
        $conn->prepare("
            DELETE FROM re_document_favorites
            WHERE document_id = ? AND user_id = ?
        ")->execute([$documentId, $currentUserId]);
    } else {
        // Add favorite
        $conn->prepare("
            INSERT IGNORE INTO re_document_favorites (company_id, document_id, user_id)
            VALUES (?, ?, ?)
        ")->execute([$currentCompanyId, $documentId, $currentUserId]);
    }
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
}

