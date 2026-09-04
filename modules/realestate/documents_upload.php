<?php
/**
 * Real Estate Module - Upload Document
 * Upload and manage documents
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../legal/includes/legal_helper.php';

require_login();
if (!legal_can_manage($conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$currentUserId = $_SESSION['user_id'] ?? null;

$success = '';
$error = '';

$prefillType = $_GET['related_type'] ?? '';
$prefillId = !empty($_GET['related_id']) ? (int)$_GET['related_id'] : 0;
$returnTo = $_GET['return'] ?? '';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $relatedType = $_POST['related_type'] ?? '';
    $relatedId = !empty($_POST['related_id']) ? (int)$_POST['related_id'] : null;
    $documentTypeId = !empty($_POST['document_type_id']) ? (int)$_POST['document_type_id'] : null;
    $documentName = trim($_POST['document_name'] ?? '');
    $documentNumber = trim($_POST['document_number'] ?? '');
    $issueDate = $_POST['issue_date'] ?? null;
    $expiryDate = $_POST['expiry_date'] ?? null;
    $notes = trim($_POST['notes'] ?? '');
    $tags = trim($_POST['tags'] ?? '');
    
    if ($relatedType && $relatedId && isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['document_file'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 
                        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/plain'];
        
        if (!in_array($file['type'], $allowedTypes)) {
            $error = 'Invalid file type. Allowed: PDF, Images, Word, Excel, Text';
        } else {
            // Create upload directory
            $uploadDir = __DIR__ . '/../../uploads/realestate/documents/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Generate unique filename
            $fileExt = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fileName = uniqid('doc_') . '_' . time() . '.' . $fileExt;
            $filePath = $uploadDir . $fileName;
            $relativePath = '/uploads/realestate/documents/' . $fileName;
            
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                try {
                    $conn->beginTransaction();
                    $documentNotificationArgs = null;
                    
                    // Insert document
                    $stmt = $conn->prepare("
                        INSERT INTO re_documents
                        (company_id, document_type_id, related_type, related_id, document_name, document_number,
                         file_name, file_path, file_size, mime_type, issue_date, expiry_date, notes, tags, uploaded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $currentCompanyId, $documentTypeId, $relatedType, $relatedId,
                        $documentName ?: $file['name'], $documentNumber,
                        $file['name'], $relativePath, $file['size'], $file['type'],
                        $issueDate, $expiryDate, $notes, $tags, $currentUserId
                    ]);
                    $documentId = $conn->lastInsertId();

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
                            $documentNotificationArgs = [
                                'company_id' => $currentCompanyId,
                                'lease_id' => $docLeaseId,
                                'type' => 'document_available',
                                'entity_type' => 'document',
                                'entity_id' => (int)$documentId,
                                'title' => 'New document available',
                                'body' => trim((string)($documentName ?: $file['name'])) . ' has been added to your documents.',
                            ];
                        }
                    } catch (Throwable $e) {
                        error_log('document notification failed: ' . $e->getMessage());
                    }
                    
                    // Process tags
                    if ($tags) {
                        $tagNames = array_map('trim', explode(',', $tags));
                        foreach ($tagNames as $tagName) {
                            if ($tagName) {
                                // Get or create tag
                                $tagStmt = $conn->prepare("
                                    SELECT id FROM re_document_tags 
                                    WHERE company_id = ? AND tag_name = ?
                                ");
                                $tagStmt->execute([$currentCompanyId, $tagName]);
                                $tag = $tagStmt->fetch();
                                
                                if (!$tag) {
                                    $tagStmt = $conn->prepare("
                                        INSERT INTO re_document_tags (company_id, tag_name)
                                        VALUES (?, ?)
                                    ");
                                    $tagStmt->execute([$currentCompanyId, $tagName]);
                                    $tagId = $conn->lastInsertId();
                                } else {
                                    $tagId = $tag['id'];
                                }
                                
                                // Link tag to document
                                $linkStmt = $conn->prepare("
                                    INSERT IGNORE INTO re_document_tag_relations (document_id, tag_id)
                                    VALUES (?, ?)
                                ");
                                $linkStmt->execute([$documentId, $tagId]);
                            }
                        }
                    }
                    
                    // Log access
                    $logStmt = $conn->prepare("
                        INSERT INTO re_document_access_log
                        (company_id, document_id, user_id, action, ip_address, user_agent)
                        VALUES (?, ?, ?, 'uploaded', ?, ?)
                    ");
                    $logStmt->execute([
                        $currentCompanyId, $documentId, $currentUserId,
                        $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ]);
                    
                    $conn->commit();
                    try {
                        require_once __DIR__ . '/../../includes/audit_bridge.php';
                        audit_bridge_re_ops(
                            $conn,
                            (int)$currentCompanyId,
                            'document_uploaded',
                            're_documents',
                            (int)$documentId,
                            (string)($file['name'] ?? ('Document #' . $documentId)),
                            'Uploaded document ' . (string)($file['name'] ?? ('#' . $documentId))
                                . ($relatedType ? (' for ' . $relatedType . ' #' . (int)$relatedId) : ''),
                            null,
                            [
                                'related_type' => $relatedType,
                                'related_id' => (int)$relatedId,
                            ],
                            (int)$currentUserId
                        );
                    } catch (Throwable $e) {
                        error_log('documents_upload audit: ' . $e->getMessage());
                    }
                    if ($documentNotificationArgs) {
                        try {
                            tenant_notification_create($conn, $documentNotificationArgs);
                        } catch (Throwable $e) {
                            error_log('document notification failed: ' . $e->getMessage());
                        }
                    }
                    $success = 'Document uploaded successfully!';
                    $returnTo = $_POST['return_to'] ?? ($_GET['return'] ?? '');
                    if ($returnTo === 'legal') {
                        $legalRedirect = '../legal/legal_documents.php?uploaded=1';
                        if ($relatedType === 'legal_case' && $relatedId) {
                            $legalRedirect .= '&case_id=' . (int)$relatedId;
                        }
                        header('Location: ' . $legalRedirect);
                    } else {
                        header('Location: documents_view.php?id=' . $documentId);
                    }
                    exit;
                } catch (Exception $e) {
                    $conn->rollBack();
                    unlink($filePath); // Delete uploaded file on error
                    $error = 'Error: ' . $e->getMessage();
                }
            } else {
                $error = 'Failed to upload file';
            }
        }
    } else {
        $error = 'Please fill in all required fields and select a file';
    }
}

// Get document types
$documentTypes = $conn->prepare("
    SELECT id, document_type_name FROM re_document_types
    WHERE company_id = ? AND is_active = 1
    ORDER BY document_type_name
");
$documentTypes->execute([$currentCompanyId]);
$documentTypes = $documentTypes->fetchAll(PDO::FETCH_ASSOC);

// Get existing tags
$existingTags = $conn->prepare("
    SELECT tag_name FROM re_document_tags
    WHERE company_id = ?
    ORDER BY tag_name
");
$existingTags->execute([$currentCompanyId]);
$existingTags = $existingTags->fetchAll(PDO::FETCH_COLUMN);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Upload Document';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-upload"></i> Upload Document</h1>
            <a href="documents.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= h($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <?php if ($returnTo === 'legal'): ?>
                        <input type="hidden" name="return_to" value="legal">
                    <?php endif; ?>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Related To *</label>
                            <select name="related_type" id="related_type" class="form-select" required onchange="loadRelatedOptions()">
                                <option value="">-- Select Type --</option>
                                <option value="lease" <?= $prefillType === 'lease' ? 'selected' : '' ?>>Lease</option>
                                <option value="tenant" <?= $prefillType === 'tenant' ? 'selected' : '' ?>>Tenant</option>
                                <option value="unit" <?= $prefillType === 'unit' ? 'selected' : '' ?>>Unit</option>
                                <option value="building" <?= $prefillType === 'building' ? 'selected' : '' ?>>Building</option>
                                <option value="maintenance" <?= $prefillType === 'maintenance' ? 'selected' : '' ?>>Maintenance Request</option>
                                <option value="legal_case" <?= $prefillType === 'legal_case' ? 'selected' : '' ?>>Legal Case</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Select Item *</label>
                            <select name="related_id" id="related_id" class="form-select" required data-prefill="<?= (int)$prefillId ?>">
                                <option value="">-- Select --</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Document Type</label>
                            <select name="document_type_id" class="form-select">
                                <option value="">-- Select Type --</option>
                                <?php foreach ($documentTypes as $type): ?>
                                    <option value="<?= $type['id'] ?>"><?= h($type['document_type_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($documentTypes)): ?>
                                <small class="form-text text-danger">
                                    No document types found.
                                    <a href="compliance_document_types.php" target="_blank">Create document types</a> first.
                                </small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Document Name</label>
                            <input type="text" name="document_name" class="form-control" placeholder="Leave empty to use filename">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Document Number</label>
                            <input type="text" name="document_number" class="form-control" placeholder="e.g., Ejari number, ID number">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">File *</label>
                            <input type="file" name="document_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.txt" required>
                            <small class="form-text text-muted">Max size: 10MB. Allowed: PDF, Images, Word, Excel, Text</small>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Issue Date</label>
                            <input type="date" name="issue_date" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Expiry Date</label>
                            <input type="date" name="expiry_date" class="form-control">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Tags</label>
                        <input type="text" name="tags" id="tags" class="form-control" 
                               placeholder="Comma-separated tags (e.g., important, contract, legal)">
                        <small class="form-text text-muted">Type to see suggestions</small>
                        <div id="tagSuggestions" class="mt-2"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload"></i> Upload Document
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const existingTags = <?= json_encode($existingTags) ?>;
        
        function loadRelatedOptions() {
            const relatedType = document.getElementById('related_type').value;
            const select = document.getElementById('related_id');
            select.innerHTML = '<option value="">Loading...</option>';
            
            if (!relatedType) {
                select.innerHTML = '<option value="">-- Select --</option>';
                return;
            }
            
            const prefill = select.getAttribute('data-prefill');
            fetch(`ajax_get_related_items.php?type=${relatedType}`)
                .then(response => response.json())
                .then(data => {
                    select.innerHTML = '<option value="">-- Select --</option>';
                    data.forEach(item => {
                        const option = document.createElement('option');
                        option.value = item.id;
                        option.textContent = item.name;
                        if (prefill && String(prefill) === String(item.id)) option.selected = true;
                        select.appendChild(option);
                    });
                    select.removeAttribute('data-prefill');
                });
        }

        document.addEventListener('DOMContentLoaded', function () {
            if (document.getElementById('related_type').value) {
                loadRelatedOptions();
            }
        });
        
        document.getElementById('tags').addEventListener('input', function(e) {
            const value = e.target.value.toLowerCase();
            const suggestions = existingTags.filter(tag => 
                tag.toLowerCase().includes(value) && tag.toLowerCase() !== value
            ).slice(0, 5);
            
            const suggestionsDiv = document.getElementById('tagSuggestions');
            if (suggestions.length > 0 && value) {
                suggestionsDiv.innerHTML = suggestions.map(tag => 
                    `<span class="badge bg-secondary me-1" style="cursor: pointer;" onclick="addTag('${tag}')">${tag}</span>`
                ).join('');
            } else {
                suggestionsDiv.innerHTML = '';
            }
        });
        
        function addTag(tag) {
            const tagsInput = document.getElementById('tags');
            const currentTags = tagsInput.value.split(',').map(t => t.trim()).filter(t => t);
            if (!currentTags.includes(tag)) {
                tagsInput.value = currentTags.length > 0 ? tagsInput.value + ', ' + tag : tag;
            }
            document.getElementById('tagSuggestions').innerHTML = '';
        }
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

