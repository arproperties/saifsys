<?php
/**
 * Real Estate Module - Task Categories Management
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/includes/re_task_access.php';

require_login();
if (defined('TASKS_SHARED_MODE') && TASKS_SHARED_MODE) {
    if (!re_tasks_user_can_view_shared($conn, (int)current_user_id())) {
        http_response_code(403);
        exit('Forbidden');
    }
} else {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
            $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
            $categoryName = trim($_POST['category_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $color = trim($_POST['color'] ?? '#007bff');
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($categoryName)) {
                $error = "Category name is required";
            } else {
                try {
                    if ($_POST['action'] === 'add') {
                        $stmt = $conn->prepare("
                            INSERT INTO re_task_categories (company_id, category_name, description, color, is_active)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$currentCompanyId, $categoryName, $description ?: null, $color, $isActive]);
                        $success = "Category created successfully";
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE re_task_categories 
                            SET category_name = ?, description = ?, color = ?, is_active = ?
                            WHERE id = ? AND company_id = ?
                        ");
                        $stmt->execute([$categoryName, $description ?: null, $color, $isActive, $id, $currentCompanyId]);
                        $success = "Category updated successfully";
                    }
                } catch (Exception $e) {
                    $error = "Error: " . $e->getMessage();
                }
            }
        } elseif ($_POST['action'] === 'delete') {
            $id = (int)$_POST['id'];
            try {
                // Check if category is used
                $checkStmt = $conn->prepare("SELECT COUNT(*) FROM re_tasks WHERE category_id = ? AND company_id = ?");
                $checkStmt->execute([$id, $currentCompanyId]);
                $usageCount = $checkStmt->fetchColumn();
                
                if ($usageCount > 0) {
                    $error = "Cannot delete category: it is used by {$usageCount} task(s)";
                } else {
                    $stmt = $conn->prepare("DELETE FROM re_task_categories WHERE id = ? AND company_id = ?");
                    $stmt->execute([$id, $currentCompanyId]);
                    $success = "Category deleted successfully";
                }
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

// Get categories
$categories = $conn->prepare("
    SELECT c.*, COUNT(t.id) as task_count
    FROM re_task_categories c
    LEFT JOIN re_tasks t ON t.category_id = c.id AND t.company_id = c.company_id
    WHERE c.company_id = ?
    GROUP BY c.id
    ORDER BY c.category_name
");
$categories->execute([$currentCompanyId]);
$categories = $categories->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Task Categories';
if (defined('TASKS_SHARED_MODE') && TASKS_SHARED_MODE) {
    require_once __DIR__ . '/../tasks/includes/tasks_layout_header.php';
} else {
    require_once __DIR__ . '/includes/re_layout_header.php';
}
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label"><i class="bi bi-tags"></i> Task Categories</div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                <i class="bi bi-plus-circle"></i> New Category
            </button>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Categories (<?= count($categories) ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($categories)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No categories found. 
                        <a href="#" data-bs-toggle="modal" data-bs-target="#addCategoryModal">Create your first category</a>.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Color</th>
                                    <th>Description</th>
                                    <th>Tasks</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $cat): ?>
                                    <tr>
                                        <td><strong><?= h($cat['category_name']) ?></strong></td>
                                        <td>
                                            <span class="badge" style="background-color: <?= h($cat['color']) ?>; width: 30px; height: 20px; display: inline-block;"></span>
                                            <small class="text-muted"><?= h($cat['color']) ?></small>
                                        </td>
                                        <td><?= h($cat['description'] ?: '-') ?></td>
                                        <td><span class="badge bg-secondary"><?= $cat['task_count'] ?></span></td>
                                        <td>
                                            <?php if ($cat['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" onclick="editCategory(<?= htmlspecialchars(json_encode($cat)) ?>)">
                                                <i class="bi bi-pencil"></i> Edit
                                            </button>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this category?');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </form>
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

    <!-- Add/Edit Category Modal -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="categoryForm">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="categoryId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Category Name *</label>
                            <input type="text" name="category_name" class="form-control" id="categoryName" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2" id="categoryDescription"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Color</label>
                            <div class="input-group">
                                <input type="color" name="color" class="form-control form-control-color" id="categoryColor" value="#007bff">
                                <input type="text" class="form-control" id="colorHex" value="#007bff" readonly>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="categoryIsActive" value="1" checked>
                                <label class="form-check-label" for="categoryIsActive">
                                    Active
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editCategory(category) {
            document.getElementById('modalTitle').textContent = 'Edit Category';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('categoryId').value = category.id;
            document.getElementById('categoryName').value = category.category_name || '';
            document.getElementById('categoryDescription').value = category.description || '';
            document.getElementById('categoryColor').value = category.color || '#007bff';
            document.getElementById('colorHex').value = category.color || '#007bff';
            document.getElementById('categoryIsActive').checked = category.is_active == 1;
            
            const modal = new bootstrap.Modal(document.getElementById('addCategoryModal'));
            modal.show();
        }

        document.getElementById('categoryColor').addEventListener('input', function() {
            document.getElementById('colorHex').value = this.value;
        });

        // Reset form when modal is closed
        document.getElementById('addCategoryModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('categoryForm').reset();
            document.getElementById('modalTitle').textContent = 'Add Category';
            document.getElementById('formAction').value = 'add';
            document.getElementById('categoryId').value = '';
            document.getElementById('categoryColor').value = '#007bff';
            document.getElementById('colorHex').value = '#007bff';
            document.getElementById('categoryIsActive').checked = true;
        });
    </script>

<?php
if (defined('TASKS_SHARED_MODE') && TASKS_SHARED_MODE) {
    require_once __DIR__ . '/../tasks/includes/tasks_layout_footer.php';
} else {
    require_once __DIR__ . '/includes/re_layout_footer.php';
}
?>

