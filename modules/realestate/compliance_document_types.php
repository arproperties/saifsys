<?php
/**
 * Real Estate Module - Document Types Management
 * Configure document types for compliance tracking
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'edit') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $documentTypeName = $_POST['document_type_name'] ?? '';
        $documentTypeCode = $_POST['document_type_code'] ?? '';
        $description = $_POST['description'] ?? '';
        $isRequired = !empty($_POST['is_required']) ? 1 : 0;
        $hasExpiry = !empty($_POST['has_expiry']) ? 1 : 0;
        $defaultExpiryDays = !empty($_POST['default_expiry_days']) ? (int)$_POST['default_expiry_days'] : null;
        $alertDaysBeforeExpiry = !empty($_POST['alert_days_before_expiry']) ? (int)$_POST['alert_days_before_expiry'] : 30;
        $displayOrder = !empty($_POST['display_order']) ? (int)$_POST['display_order'] : 0;
        
        if ($documentTypeName && $documentTypeCode) {
            if ($id) {
                // Update
                $stmt = $conn->prepare("
                    UPDATE re_document_types
                    SET document_type_name = ?,
                        document_type_code = ?,
                        description = ?,
                        is_required = ?,
                        has_expiry = ?,
                        default_expiry_days = ?,
                        alert_days_before_expiry = ?,
                        display_order = ?
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([
                    $documentTypeName, $documentTypeCode, $description, $isRequired,
                    $hasExpiry, $defaultExpiryDays, $alertDaysBeforeExpiry, $displayOrder,
                    $id, $currentCompanyId
                ]);
            } else {
                // Insert
                $stmt = $conn->prepare("
                    INSERT INTO re_document_types
                    (company_id, document_type_name, document_type_code, description, is_required, 
                     has_expiry, default_expiry_days, alert_days_before_expiry, display_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $currentCompanyId, $documentTypeName, $documentTypeCode, $description, $isRequired,
                    $hasExpiry, $defaultExpiryDays, $alertDaysBeforeExpiry, $displayOrder
                ]);
            }
            $_SESSION['success'] = 'Document type saved successfully.';
            header('Location: compliance_document_types.php');
            exit;
        }
    } elseif ($action === 'toggle_active') {
        $id = (int)$_POST['id'];
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        $conn->prepare("UPDATE re_document_types SET is_active = ? WHERE id = ? AND company_id = ?")
            ->execute([$isActive, $id, $currentCompanyId]);
        $_SESSION['success'] = 'Document type updated.';
        header('Location: compliance_document_types.php');
        exit;
    }
}

// Get document types
$documentTypes = $conn->prepare("
    SELECT * FROM re_document_types
    WHERE company_id = ?
    ORDER BY display_order, document_type_name
");
$documentTypes->execute([$currentCompanyId]);
$documentTypes = $documentTypes->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Document Types';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-tags"></i> Document Types Management</h1>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#documentTypeModal" onclick="resetForm()">
                <i class="bi bi-plus-circle"></i> Create Document Type
            </button>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= h($_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-list"></i> Document Types</h5>
            </div>
            <div class="card-body">
                <?php if (empty($documentTypes)): ?>
                    <p class="text-muted">No document types configured. Create one to get started.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Code</th>
                                    <th>Required</th>
                                    <th>Has Expiry</th>
                                    <th>Alert Days</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documentTypes as $type): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($type['document_type_name']) ?></strong>
                                            <?php if ($type['description']): ?>
                                                <br><small class="text-muted"><?= h($type['description']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?= h($type['document_type_code']) ?></code></td>
                                        <td>
                                            <?php if ($type['is_required']): ?>
                                                <span class="badge bg-danger">Required</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Optional</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($type['has_expiry']): ?>
                                                <span class="badge bg-info">Yes</span>
                                                <?php if ($type['default_expiry_days']): ?>
                                                    <br><small class="text-muted"><?= $type['default_expiry_days'] ?> days</small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">No</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $type['alert_days_before_expiry'] ?> days</td>
                                        <td>
                                            <form method="POST" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="id" value="<?= $type['id'] ?>">
                                                <input type="hidden" name="is_active" value="<?= $type['is_active'] ? 0 : 1 ?>">
                                                <button type="submit" class="btn btn-sm btn-<?= $type['is_active'] ? 'success' : 'secondary' ?>">
                                                    <?= $type['is_active'] ? 'Active' : 'Inactive' ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary" onclick="editType(<?= htmlspecialchars(json_encode($type)) ?>)">
                                                <i class="bi bi-pencil"></i> Edit
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Document Type Modal -->
    <div class="modal fade" id="documentTypeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Create Document Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="documentTypeForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create" id="formAction">
                    <input type="hidden" name="id" id="formId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Document Type Name *</label>
                            <input type="text" name="document_type_name" id="document_type_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Document Type Code *</label>
                            <input type="text" name="document_type_code" id="document_type_code" class="form-control" required>
                            <small class="form-text text-muted">Unique code (e.g., EJARI, TENANT_ID, PASSPORT)</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_required" id="is_required">
                                    <label class="form-check-label" for="is_required">Required Document</label>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="has_expiry" id="has_expiry" onchange="toggleExpiryFields()">
                                    <label class="form-check-label" for="has_expiry">Has Expiry Date</label>
                                </div>
                            </div>
                        </div>
                        <div id="expiryFields" style="display: none;">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Default Expiry Days</label>
                                    <input type="number" name="default_expiry_days" id="default_expiry_days" class="form-control" min="1">
                                    <small class="form-text text-muted">Default validity period in days</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Alert Days Before Expiry</label>
                                    <input type="number" name="alert_days_before_expiry" id="alert_days_before_expiry" class="form-control" value="30" min="1" max="365">
                                    <small class="form-text text-muted">Send alert this many days before expiry</small>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Display Order</label>
                            <input type="number" name="display_order" id="display_order" class="form-control" value="0" min="0">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function resetForm() {
            document.getElementById('documentTypeForm').reset();
            document.getElementById('formAction').value = 'create';
            document.getElementById('formId').value = '';
            document.getElementById('modalTitle').textContent = 'Create Document Type';
            document.getElementById('expiryFields').style.display = 'none';
        }
        
        function editType(type) {
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formId').value = type.id;
            document.getElementById('document_type_name').value = type.document_type_name;
            document.getElementById('document_type_code').value = type.document_type_code;
            document.getElementById('description').value = type.description || '';
            document.getElementById('is_required').checked = type.is_required == 1;
            document.getElementById('has_expiry').checked = type.has_expiry == 1;
            document.getElementById('default_expiry_days').value = type.default_expiry_days || '';
            document.getElementById('alert_days_before_expiry').value = type.alert_days_before_expiry || 30;
            document.getElementById('display_order').value = type.display_order || 0;
            document.getElementById('modalTitle').textContent = 'Edit Document Type';
            toggleExpiryFields();
            new bootstrap.Modal(document.getElementById('documentTypeModal')).show();
        }
        
        function toggleExpiryFields() {
            const hasExpiry = document.getElementById('has_expiry').checked;
            document.getElementById('expiryFields').style.display = hasExpiry ? 'block' : 'none';
        }
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

