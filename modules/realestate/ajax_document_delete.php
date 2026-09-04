<?php
/**
 * Real Estate Document Delete Handler (AJAX)
 */

// Prevent any output before JSON
ob_start();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

// Clear any output
ob_clean();

require_login();
require_module_access($conn, MODULE_REALESTATE);

header('Content-Type: application/json');

$currentCompanyId = current_company_id($conn) ?: 1;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $documentId = (int)($_POST['document_id'] ?? 0);
    
    if ($documentId <= 0) {
        throw new Exception('Invalid document ID');
    }
    
    // Get document info
    $stmt = $conn->prepare("
        SELECT id, file_path, company_id 
        FROM re_documents 
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$documentId, $currentCompanyId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$document) {
        throw new Exception('Document not found or access denied');
    }
    
    // Delete file
    $filePath = __DIR__ . '/../../' . $document['file_path'];
    if (file_exists($filePath)) {
        @unlink($filePath);
    }
    
    // Delete from database
    $stmt = $conn->prepare("DELETE FROM re_documents WHERE id = ?");
    $stmt->execute([$documentId]);

    try {
        require_once __DIR__ . '/../../includes/audit_bridge.php';
        audit_bridge_re_ops(
            $conn,
            (int)$currentCompanyId,
            'document_deleted',
            're_documents',
            (int)$documentId,
            (string)($document['file_name'] ?? $document['document_name'] ?? ('Document #' . $documentId)),
            'Deleted document ' . (string)($document['file_name'] ?? $document['document_name'] ?? ('#' . $documentId)),
            [
                'related_type' => $document['related_type'] ?? null,
                'related_id' => $document['related_id'] ?? null,
            ],
            null,
            (int)($_SESSION['user']['id'] ?? 0) ?: null
        );
    } catch (Throwable $e) {
        error_log('ajax_document_delete audit: ' . $e->getMessage());
    }
    
    ob_clean(); // Clear any output
    echo json_encode([
        'success' => true,
        'message' => 'Document deleted successfully'
    ]);
    ob_end_flush();
    exit;
    
} catch (Exception $e) {
    ob_clean(); // Clear any output
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    ob_end_flush();
    exit;
}

