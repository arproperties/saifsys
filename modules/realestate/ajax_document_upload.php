<?php
/**
 * Real Estate Document Upload Handler (AJAX)
 */

// Prevent any output before JSON
ob_start();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/document_manager.php';

// Clear any output
ob_clean();

require_login();
require_module_access($conn, MODULE_REALESTATE);

header('Content-Type: application/json');

$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }
    
    // Get form data
    $documentTypeId = (int)($_POST['document_type_id'] ?? 0);
    $relatedType = $_POST['related_type'] ?? '';
    $relatedId = (int)($_POST['related_id'] ?? 0);
    $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null; // legacy form field name
    $notes = trim($_POST['notes'] ?? '');
    
    // Validation
    if ($documentTypeId <= 0) {
        throw new Exception('Document type is required');
    }

    $typeStmt = $conn->prepare("
        SELECT id, document_type_name, document_type_code, has_expiry, default_expiry_days
        FROM re_document_types
        WHERE id = ? AND company_id = ? AND is_active = 1
        LIMIT 1
    ");
    $typeStmt->execute([$documentTypeId, $currentCompanyId]);
    $documentTypeRow = $typeStmt->fetch(PDO::FETCH_ASSOC);
    if (!$documentTypeRow) {
        throw new Exception('Selected document type is not valid. Configure types in Document Types Management.');
    }
    $documentType = re_legacy_document_type_from_code((string)$documentTypeRow['document_type_code']);
    $documentName = (string)$documentTypeRow['document_type_name'];
    
    if (empty($relatedType) || $relatedId <= 0) {
        throw new Exception('Related entity is required');
    }
    
    // Validate related entity exists and belongs to company
    $validRelatedTypes = ['lease', 'tenant', 'unit', 'building', 'maintenance'];
    if (!in_array($relatedType, $validRelatedTypes)) {
        throw new Exception('Invalid related type');
    }
    
    // Verify related entity exists
    $tableMap = [
        'lease' => 're_leases',
        'tenant' => 're_tenants',
        'unit' => 're_units',
        'building' => 're_buildings',
        'maintenance' => 're_maintenance_requests'
    ];
    
    $table = $tableMap[$relatedType];
    $stmt = $conn->prepare("SELECT id FROM {$table} WHERE id = ? AND company_id = ?");
    $stmt->execute([$relatedId, $currentCompanyId]);
    if (!$stmt->fetch()) {
        throw new Exception('Related entity not found or access denied');
    }
    
    // File validation
    $file = $_FILES['file'];
    $allowedTypes = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($fileExtension, $allowedTypes)) {
        throw new Exception('File type not allowed. Allowed: ' . implode(', ', $allowedTypes));
    }
    
    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($file['size'] > $maxSize) {
        throw new Exception('File size exceeds 10MB limit');
    }
    
    // Create upload directory structure
    $baseUploadDir = __DIR__ . '/../../uploads/';
    $realestateDir = $baseUploadDir . 'realestate/';
    $typeDir = $realestateDir . $relatedType . '/';
    $uploadDir = $typeDir . $relatedId . '/';
    
    // Create directories step by step with better error handling
    // Use 0777 for maximum compatibility (web server can write)
    $dirs = [
        'base' => $baseUploadDir,
        'realestate' => $realestateDir,
        'type' => $typeDir,
        'upload' => $uploadDir
    ];
    
    foreach ($dirs as $name => $dir) {
        if (!is_dir($dir)) {
            // Try to create with 0777 permissions
            if (!@mkdir($dir, 0777, true)) {
                // If that fails, try 0755
                if (!@mkdir($dir, 0755, true)) {
                    $parentDir = dirname($dir);
                    $parentWritable = is_writable($parentDir);
                    $parentExists = is_dir($parentDir);
                    
                    $errorMsg = "Failed to create {$name} directory: " . basename($dir);
                    if (!$parentExists) {
                        $errorMsg .= " (Parent directory does not exist: " . basename($parentDir) . ")";
                    } elseif (!$parentWritable) {
                        $errorMsg .= " (Parent directory is not writable: " . basename($parentDir) . ")";
                    } else {
                        $errorMsg .= " (Check file permissions)";
                    }
                    throw new Exception($errorMsg);
                }
            }
        }
        
        // Ensure directory is writable (Apache/XAMPP often runs as a different user than the folder owner).
        if (!is_writable($dir)) {
            @chmod($dir, 0777);
            clearstatcache(true, $dir);
            if (!is_writable($dir)) {
                throw new Exception(
                    "Directory exists but is not writable: " . basename($dir)
                    . ". On this server, give the web user write access to uploads/realestate/"
                    . basename($dir)
                    . " (e.g. chmod -R a+rwX uploads/realestate/" . basename($dir) . ")."
                );
            }
        }
    }
    
    // Generate unique filename
    $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $filename = $safeName . '_' . uniqid() . '_' . time() . '.' . $fileExtension;
    $filepath = $uploadDir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to save file');
    }
    
    // Relative path for database
    $relativePath = 'uploads/realestate/' . $relatedType . '/' . $relatedId . '/' . $filename;
    
    // Get MIME type
    $mimeType = mime_content_type($filepath) ?: $file['type'];
    
    // Normalise expiry date to match re_documents.expiry_date (Y-m-d) if provided
    $expiryDateSql = null;
    if ($expiresAt) {
        try {
            $dt = new DateTime($expiresAt);
            $expiryDateSql = $dt->format('Y-m-d');
        } catch (Throwable $e) {
            $expiryDateSql = null;
        }
    }
    if (!$expiryDateSql && !empty($documentTypeRow['has_expiry']) && !empty($documentTypeRow['default_expiry_days'])) {
        $expiryDateSql = (new DateTime('today'))->modify('+' . (int)$documentTypeRow['default_expiry_days'] . ' days')->format('Y-m-d');
    }

    // Save to database
    $stmt = $conn->prepare("
        INSERT INTO re_documents 
        (company_id, document_type_id, document_name, document_type, related_type, related_id, file_name, file_path, 
         file_size, mime_type, expiry_date, notes, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $currentCompanyId,
        $documentTypeId,
        $documentName,
        $documentType,
        $relatedType,
        $relatedId,
        $file['name'],
        $relativePath,
        $file['size'],
        $mimeType,
        $expiryDateSql,
        $notes,
        $userId
    ]);
    
    $documentId = (int)$conn->lastInsertId();

    try {
        require_once __DIR__ . '/../../includes/audit_bridge.php';
        audit_bridge_re_ops(
            $conn,
            (int)$currentCompanyId,
            'document_uploaded',
            're_documents',
            $documentId,
            (string)($file['name'] ?? ('Document #' . $documentId)),
            'Uploaded document ' . (string)($file['name'] ?? ('#' . $documentId))
                . ($relatedType ? (' for ' . $relatedType . ' #' . (int)$relatedId) : ''),
            null,
            [
                'related_type' => $relatedType,
                'related_id' => (int)$relatedId,
                'document_type' => $documentType,
            ],
            (int)$userId
        );
    } catch (Throwable $e) {
        error_log('ajax_document_upload audit: ' . $e->getMessage());
    }

    // Tenant in-app notification: document available (lease- or unit-scoped only)
    require_once __DIR__ . '/../../includes/tenant_notifications.php';
    try {
        $docLeaseId = 0;
        if ($relatedType === 'lease') {
            $docLeaseId = (int)$relatedId;
        } elseif ($relatedType === 'unit') {
            $uq = $conn->prepare("SELECT id FROM re_leases WHERE unit_id = ? AND company_id = ? AND status IN ('active','renewed') ORDER BY end_date DESC LIMIT 1");
            $uq->execute([(int)$relatedId, $currentCompanyId]);
            $docLeaseId = (int)($uq->fetchColumn() ?: 0);
        }
        if ($docLeaseId > 0) {
            tenant_notification_create($conn, [
                'company_id' => $currentCompanyId,
                'lease_id' => $docLeaseId,
                'type' => 'document_available',
                'entity_type' => 'document',
                'entity_id' => $documentId,
                'title' => 'New document available',
                'body' => trim((string)($file['name'] ?? 'A document')) . ' has been added to your documents.',
            ]);
        }
    } catch (Throwable $e) {
        error_log('document notification failed: ' . $e->getMessage());
    }
    
    // Check if document has expiry and mark if expired
    if ($expiryDateSql) {
        $isExpired = (strtotime($expiryDateSql) < strtotime('today'));
        if ($isExpired) {
            $conn->prepare("UPDATE re_documents SET is_expired = 1 WHERE id = ?")->execute([$documentId]);
        }
    }
    
    // Get document info for response
    $stmt = $conn->prepare("
        SELECT d.*, u.username as uploaded_by_name, dt.document_type_name
        FROM re_documents d
        LEFT JOIN user u ON u.id = d.uploaded_by
        LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
        WHERE d.id = ?
    ");
    $stmt->execute([$documentId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    
    ob_clean(); // Clear any output
    echo json_encode([
        'success' => true,
        'message' => 'Document uploaded successfully',
        'document' => $document
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

