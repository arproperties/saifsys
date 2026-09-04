<?php
// operation/ajax_client_documents.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';

header('Content-Type: application/json');

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : (isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0);
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

if ($client_id <= 0 && $action !== 'upload') {
    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
    exit;
}

try {
    switch ($action) {
        case 'list':
            $result = listClientDocuments($conn, $client_id);
            break;
            
        case 'upload':
            $result = uploadDocument($conn, $_POST, $_FILES);
            break;
            
        case 'delete':
            $result = deleteDocument($conn, (int)($_POST['doc_id'] ?? 0));
            break;
            
        case 'update':
            $result = updateDocument($conn, $_POST);
            break;
            
        case 'get':
            $result = getDocument($conn, (int)($_GET['doc_id'] ?? 0));
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            exit;
    }
    
    echo json_encode($result);
    
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Action failed: ' . $e->getMessage()]);
}

function listClientDocuments(PDO $conn, int $client_id): array {
    $sql = "
        SELECT 
            cd.*,
            u.fullname as uploaded_by_name
        FROM client_documents cd
        LEFT JOIN user u ON u.id = cd.uploaded_by
        WHERE cd.client_id = ?
        ORDER BY cd.created_at DESC
    ";
    
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $documents = $st->fetchAll(PDO::FETCH_ASSOC);
    
    // Add expiry warnings
    foreach ($documents as &$doc) {
        if ($doc['expires_at']) {
            $expiry_date = new DateTime($doc['expires_at']);
            $today = new DateTime();
            $days_until_expiry = $today->diff($expiry_date)->days;
            
            $doc['expiry_status'] = match(true) {
                $expiry_date < $today => 'expired',
                $days_until_expiry <= 30 => 'expiring_soon',
                $days_until_expiry <= 90 => 'expiring_warning',
                default => 'valid'
            };
            
            $doc['days_until_expiry'] = $days_until_expiry;
        } else {
            $doc['expiry_status'] = 'no_expiry';
        }
        
        // Format file size
        $doc['formatted_size'] = formatFileSize($doc['file_size']);
    }
    
    return [
        'success' => true,
        'documents' => $documents
    ];
}

function uploadDocument(PDO $conn, array $post, array $files): array {
    $client_id = (int)($post['client_id'] ?? 0);
    $doc_type = trim($post['doc_type'] ?? '');
    $expires_at = $post['expires_at'] ?: null;
    $user_id = current_user_id();
    
    if ($client_id <= 0) {
        return ['success' => false, 'error' => 'Invalid client ID'];
    }
    
    if (empty($doc_type)) {
        return ['success' => false, 'error' => 'Document type is required'];
    }
    
    if (!isset($files['file']) || $files['file']['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'No file uploaded or upload error'];
    }
    
    $file = $files['file'];
    $allowed_types = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'txt'];
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_extension, $allowed_types)) {
        return ['success' => false, 'error' => 'File type not allowed'];
    }
    
    // Create uploads directory if it doesn't exist
    $upload_dir = __DIR__ . '/../uploads/client_documents/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Generate unique filename
    $filename = uniqid() . '_' . time() . '.' . $file_extension;
    $filepath = $upload_dir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => false, 'error' => 'Failed to save file'];
    }
    
    // Save to database
    $sql = "
        INSERT INTO client_documents 
        (client_id, doc_type, file_name, file_path, file_size, expires_at, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ";
    
    $st = $conn->prepare($sql);
    $ok = $st->execute([
        $client_id,
        $doc_type,
        $file['name'],
        $filepath,
        $file['size'],
        $expires_at,
        $user_id
    ]);
    
    if (!$ok) {
        unlink($filepath); // Clean up file if DB insert failed
        return ['success' => false, 'error' => 'Failed to save document record'];
    }
    
    $doc_id = (int)$conn->lastInsertId();
    
    return [
        'success' => true,
        'message' => 'Document uploaded successfully',
        'document' => [
            'id' => $doc_id,
            'file_name' => $file['name'],
            'doc_type' => $doc_type,
            'file_size' => $file['size'],
            'expires_at' => $expires_at
        ]
    ];
}

function deleteDocument(PDO $conn, int $doc_id): array {
    if ($doc_id <= 0) {
        return ['success' => false, 'error' => 'Invalid document ID'];
    }
    
    // Get document info first
    $st = $conn->prepare("SELECT file_path FROM client_documents WHERE id = ?");
    $st->execute([$doc_id]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    
    if (!$doc) {
        return ['success' => false, 'error' => 'Document not found'];
    }
    
    // Delete from database
    $st = $conn->prepare("DELETE FROM client_documents WHERE id = ?");
    $ok = $st->execute([$doc_id]);
    
    if (!$ok) {
        return ['success' => false, 'error' => 'Failed to delete document record'];
    }
    
    // Delete physical file
    if (file_exists($doc['file_path'])) {
        unlink($doc['file_path']);
    }
    
    return [
        'success' => true,
        'message' => 'Document deleted successfully'
    ];
}

function updateDocument(PDO $conn, array $post): array {
    $doc_id = (int)($post['doc_id'] ?? 0);
    $doc_type = trim($post['doc_type'] ?? '');
    $expires_at = $post['expires_at'] ?: null;
    
    if ($doc_id <= 0) {
        return ['success' => false, 'error' => 'Invalid document ID'];
    }
    
    if (empty($doc_type)) {
        return ['success' => false, 'error' => 'Document type is required'];
    }
    
    $sql = "
        UPDATE client_documents 
        SET doc_type = ?, expires_at = ?, updated_at = NOW()
        WHERE id = ?
    ";
    
    $st = $conn->prepare($sql);
    $ok = $st->execute([$doc_type, $expires_at, $doc_id]);
    
    if (!$ok) {
        return ['success' => false, 'error' => 'Failed to update document'];
    }
    
    return [
        'success' => true,
        'message' => 'Document updated successfully'
    ];
}

function getDocument(PDO $conn, int $doc_id): array {
    if ($doc_id <= 0) {
        return ['success' => false, 'error' => 'Invalid document ID'];
    }
    
    $sql = "
        SELECT * FROM client_documents 
        WHERE id = ?
    ";
    
    $st = $conn->prepare($sql);
    $st->execute([$doc_id]);
    $document = $st->fetch(PDO::FETCH_ASSOC);
    
    if (!$document) {
        return ['success' => false, 'error' => 'Document not found'];
    }
    
    return [
        'success' => true,
        'document' => $document
    ];
}

function formatFileSize(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $unit_index = 0;
    
    while ($bytes >= 1024 && $unit_index < count($units) - 1) {
        $bytes /= 1024;
        $unit_index++;
    }
    
    return round($bytes, 2) . ' ' . $units[$unit_index];
}
