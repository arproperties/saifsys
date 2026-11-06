<?php
/**
 * AMC Category Management
 * Add new AMC categories and rename existing ones
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_CORE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

$success = '';
$error = '';

function amc_slugify_code(string $name): string {
    $code = strtolower(trim($name));
    $code = preg_replace('/[^a-z0-9]+/', '_', $code);
    return trim($code, '_');
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
            $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $regulatoryBody = trim($_POST['regulatory_body'] ?? '');
            $frequency = $_POST['default_visit_frequency'] ?? 'monthly';
            $allowedFrequencies = ['daily', 'weekly', 'monthly', 'quarterly', 'bi_annual', 'annual', 'others'];
            if (!in_array($frequency, $allowedFrequencies, true)) {
                $frequency = 'monthly';
            }
            $isMandatory = isset($_POST['is_mandatory']) ? 1 : 0;
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($name === '') {
                $error = "Category name is required";
            } else {
                try {
                    if ($_POST['action'] === 'add') {
                        $code = amc_slugify_code($name);
                        if ($code === '') {
                            $code = 'category_' . time();
                        }
                        // Ensure the generated code is unique
                        $baseCode = $code;
                        $suffix = 1;
                        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM re_amc_categories WHERE code = ?");
                        while (true) {
                            $checkStmt->execute([$code]);
                            if ((int)$checkStmt->fetchColumn() === 0) break;
                            $suffix++;
                            $code = $baseCode . '_' . $suffix;
                        }

                        $stmt = $conn->prepare("
                            INSERT INTO re_amc_categories (name, code, description, is_mandatory, regulatory_body, default_visit_frequency, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$name, $code, $description ?: null, $isMandatory, $regulatoryBody ?: null, $frequency, $isActive]);
                        $success = "Category created successfully";
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE re_amc_categories
                            SET name = ?, description = ?, is_mandatory = ?, regulatory_body = ?, default_visit_frequency = ?, is_active = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$name, $description ?: null, $isMandatory, $regulatoryBody ?: null, $frequency, $isActive, $id]);
                        $success = "Category updated successfully";
                    }
                } catch (Exception $e) {
                    $error = "Error: " . $e->getMessage();
                }
            }
        } elseif ($_POST['action'] === 'delete') {
            $id = (int)$_POST['id'];
            try {
                $checkStmt = $conn->prepare("SELECT COUNT(*) FROM re_amc_contracts WHERE category_id = ?");
                $checkStmt->execute([$id]);
                $usageCount = $checkStmt->fetchColumn();

                if ($usageCount > 0) {
                    $error = "Cannot delete category: it is used by {$usageCount} contract(s). Deactivate it instead.";
                } else {
                    $stmt = $conn->prepare("DELETE FROM re_amc_categories WHERE id = ?");
                    $stmt->execute([$id]);
                    $success = "Category deleted successfully";
                }
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

// Get categories with usage counts
$categories = $conn->query("
    SELECT c.*, COUNT(ac.id) AS contract_count
    FROM re_amc_categories c
    LEFT JOIN re_amc_contracts ac ON ac.category_id = c.id
    GROUP BY c.id
    ORDER BY c.name
")->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$frequencyLabels = [
    'daily' => 'Daily',
    'weekly' => 'Weekly',
    'monthly' => 'Monthly',
    'quarterly' => 'Quarterly',
    'bi_annual' => 'Bi-Annual',
    'annual' => 'Annual',
    'others' => 'Others',
];

// Include layout header
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="page-header-label mb-1"><i class="bi bi-tags"></i> AMC Categories</h1>
            <p class="text-muted mb-0">Manage the categories available for AMC contracts</p>
        </div>
        <div>
            <a href="amc.php" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left"></i> Back to AMC Contracts
            </a>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                <i class="bi bi-plus-circle"></i> New Category
            </button>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle"></i> <?= h($success) ?>
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
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Display Name</th>
                                <th>Code</th>
                                <th>Regulatory Body</th>
                                <th>Default Frequency</th>
                                <th>Mandatory</th>
                                <th>Contracts</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td><strong><?= h($cat['name']) ?></strong></td>
                                    <td><code><?= h($cat['code']) ?></code></td>
                                    <td><?= h($cat['regulatory_body'] ?: '-') ?></td>
                                    <td><?= h($frequencyLabels[$cat['default_visit_frequency']] ?? $cat['default_visit_frequency']) ?></td>
                                    <td>
                                        <?php if ($cat['is_mandatory']): ?>
                                            <span class="badge bg-warning text-dark">Mandatory</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-secondary"><?= (int)$cat['contract_count'] ?></span></td>
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
                                            <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" <?= $cat['contract_count'] > 0 ? 'disabled title="In use by contracts"' : '' ?>>
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
                        <label class="form-label">Display Name *</label>
                        <input type="text" name="name" class="form-control" id="categoryName" required>
                        <div class="form-text" id="codePreview"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2" id="categoryDescription"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Regulatory Body</label>
                        <input type="text" name="regulatory_body" class="form-control" id="categoryRegulatoryBody" placeholder="e.g. Dubai Civil Defense">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Default Visit Frequency</label>
                        <select name="default_visit_frequency" class="form-select" id="categoryFrequency">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly" selected>Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="bi_annual">Bi-Annual</option>
                            <option value="annual">Annual</option>
                            <option value="others">Others</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_mandatory" id="categoryIsMandatory" value="1">
                            <label class="form-check-label" for="categoryIsMandatory">
                                Mandatory (required by regulatory body)
                            </label>
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

<script>
    function editCategory(category) {
        document.getElementById('modalTitle').textContent = 'Edit Category';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('categoryId').value = category.id;
        document.getElementById('categoryName').value = category.name || '';
        document.getElementById('categoryDescription').value = category.description || '';
        document.getElementById('categoryRegulatoryBody').value = category.regulatory_body || '';
        document.getElementById('categoryFrequency').value = category.default_visit_frequency || 'monthly';
        document.getElementById('categoryIsMandatory').checked = category.is_mandatory == 1;
        document.getElementById('categoryIsActive').checked = category.is_active == 1;
        document.getElementById('codePreview').textContent = 'Code: ' + category.code;

        const modal = new bootstrap.Modal(document.getElementById('addCategoryModal'));
        modal.show();
    }

    document.getElementById('categoryName').addEventListener('input', function() {
        if (document.getElementById('formAction').value === 'add') {
            const slug = this.value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
            document.getElementById('codePreview').textContent = slug ? 'Code will be: ' + slug : '';
        }
    });

    // Reset form when modal is closed
    document.getElementById('addCategoryModal').addEventListener('hidden.bs.modal', function() {
        document.getElementById('categoryForm').reset();
        document.getElementById('modalTitle').textContent = 'Add Category';
        document.getElementById('formAction').value = 'add';
        document.getElementById('categoryId').value = '';
        document.getElementById('codePreview').textContent = '';
    });
</script>

<?php
require_once __DIR__ . '/includes/re_layout_footer.php';
?>
