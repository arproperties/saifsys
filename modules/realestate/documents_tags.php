<?php
/**
 * Real Estate Module - Document Tags Management
 * Manage document tags for flexible categorization
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'edit') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $tagName = trim($_POST['tag_name'] ?? '');
        $tagColor = $_POST['tag_color'] ?? '#007bff';
        
        if ($tagName) {
            if ($id) {
                // Update
                $stmt = $conn->prepare("
                    UPDATE re_document_tags
                    SET tag_name = ?, tag_color = ?
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$tagName, $tagColor, $id, $currentCompanyId]);
            } else {
                // Insert
                $stmt = $conn->prepare("
                    INSERT INTO re_document_tags (company_id, tag_name, tag_color)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$currentCompanyId, $tagName, $tagColor]);
            }
            $_SESSION['success'] = 'Tag saved successfully.';
            header('Location: documents_tags.php');
            exit;
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $conn->prepare("DELETE FROM re_document_tags WHERE id = ? AND company_id = ?")
            ->execute([$id, $currentCompanyId]);
        $_SESSION['success'] = 'Tag deleted successfully.';
        header('Location: documents_tags.php');
        exit;
    }
}

// Get tags
$tags = $conn->prepare("
    SELECT 
        dt.*,
        COUNT(dtr.document_id) as document_count
    FROM re_document_tags dt
    LEFT JOIN re_document_tag_relations dtr ON dtr.tag_id = dt.id
    WHERE dt.company_id = ?
    GROUP BY dt.id
    ORDER BY dt.tag_name
");
$tags->execute([$currentCompanyId]);
$tags = $tags->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Document Tags';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-tags"></i> Document Tags Management</h1>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tagModal" onclick="resetForm()">
                <i class="bi bi-plus-circle"></i> Create Tag
            </button>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= h($_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-list"></i> Tags (<?= count($tags) ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($tags)): ?>
                    <p class="text-muted">No tags created. Create one to get started.</p>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($tags as $tag): ?>
                            <div class="col-md-3 mb-3">
                                <div class="card">
                                    <div class="card-body text-center">
                                        <span class="badge" style="background-color: <?= h($tag['tag_color']) ?>; font-size: 1rem; padding: 8px 16px;">
                                            <?= h($tag['tag_name']) ?>
                                        </span>
                                        <p class="mt-2 mb-0">
                                            <small class="text-muted"><?= $tag['document_count'] ?> documents</small>
                                        </p>
                                        <div class="mt-2">
                                            <button class="btn btn-sm btn-outline-primary" onclick="editTag(<?= htmlspecialchars(json_encode($tag)) ?>)">
                                                <i class="bi bi-pencil"></i> Edit
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteTag(<?= $tag['id'] ?>, '<?= h($tag['tag_name']) ?>')">
                                                <i class="bi bi-trash"></i> Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Tag Modal -->
    <div class="modal fade" id="tagModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Create Tag</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="tagForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create" id="formAction">
                    <input type="hidden" name="id" id="formId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Tag Name *</label>
                            <input type="text" name="tag_name" id="tag_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tag Color *</label>
                            <input type="color" name="tag_color" id="tag_color" class="form-control form-control-color" value="#007bff">
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

    <!-- Delete Form -->
    <form method="POST" id="deleteForm" style="display: none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="deleteId">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function resetForm() {
            document.getElementById('tagForm').reset();
            document.getElementById('formAction').value = 'create';
            document.getElementById('formId').value = '';
            document.getElementById('modalTitle').textContent = 'Create Tag';
            document.getElementById('tag_color').value = '#007bff';
        }
        
        function editTag(tag) {
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formId').value = tag.id;
            document.getElementById('tag_name').value = tag.tag_name;
            document.getElementById('tag_color').value = tag.tag_color;
            document.getElementById('modalTitle').textContent = 'Edit Tag';
            new bootstrap.Modal(document.getElementById('tagModal')).show();
        }
        
        function deleteTag(id, name) {
            if (confirm(`Are you sure you want to delete the tag "${name}"? This will remove it from all documents.`)) {
                document.getElementById('deleteId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

