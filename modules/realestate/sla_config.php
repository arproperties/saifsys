<?php
/**
 * Real Estate Module - SLA Configuration
 * Manage SLA rules for maintenance requests
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

// Check if user has permission (Owner, Admin, or Maintenance Manager)
$userRoles = current_user_roles($conn);
$isMaintenanceManager = false;
foreach ($userRoles as $role) {
    if (stripos($role, 'maintenance') !== false && stripos($role, 'manager') !== false) {
        $isMaintenanceManager = true;
        break;
    }
}
$isOwnerOrAdmin = in_array('Owner', $userRoles, true) || in_array('Admin', $userRoles, true);

if (!$isOwnerOrAdmin && !$isMaintenanceManager) {
    http_response_code(403);
    die('Access denied. Only Owners, Admins, and Maintenance Managers can configure SLA rules.');
}

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
            $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
            $priority = $_POST['priority'] ?? '';
            $category = !empty($_POST['category']) ? trim($_POST['category']) : null;
            $responseTimeMinutes = (int)($_POST['response_time_minutes'] ?? 0);
            $resolutionTimeHours = (int)($_POST['resolution_time_hours'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            
            if (!in_array($priority, ['low', 'medium', 'high', 'urgent'])) {
                $error = "Invalid priority level";
            } elseif ($responseTimeMinutes <= 0) {
                $error = "Response time must be greater than 0";
            } elseif ($resolutionTimeHours <= 0) {
                $error = "Resolution time must be greater than 0";
            } else {
                try {
                    if ($_POST['action'] === 'add') {
                        // Check for duplicate
                        $checkStmt = $conn->prepare("
                            SELECT id FROM re_sla_rules 
                            WHERE company_id = ? AND priority = ? AND 
                                  (category = ? OR (category IS NULL AND ? IS NULL))
                        ");
                        $checkStmt->execute([$currentCompanyId, $priority, $category, $category]);
                        if ($checkStmt->fetch()) {
                            $error = "SLA rule already exists for this priority and category";
                        } else {
                            $stmt = $conn->prepare("
                                INSERT INTO re_sla_rules 
                                (company_id, priority, category, response_time_minutes, resolution_time_hours, is_active)
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$currentCompanyId, $priority, $category, $responseTimeMinutes, $resolutionTimeHours, $isActive]);
                            $success = "SLA rule added successfully";
                        }
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE re_sla_rules 
                            SET priority = ?, category = ?, response_time_minutes = ?, 
                                resolution_time_hours = ?, is_active = ?
                            WHERE id = ? AND company_id = ?
                        ");
                        $stmt->execute([$priority, $category, $responseTimeMinutes, $resolutionTimeHours, $isActive, $id, $currentCompanyId]);
                        $success = "SLA rule updated successfully";
                    }
                } catch (Exception $e) {
                    $error = "Error: " . $e->getMessage();
                }
            }
        } elseif ($_POST['action'] === 'delete') {
            $id = (int)$_POST['id'];
            try {
                $stmt = $conn->prepare("DELETE FROM re_sla_rules WHERE id = ? AND company_id = ?");
                $stmt->execute([$id, $currentCompanyId]);
                $success = "SLA rule deleted successfully";
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'initialize_defaults') {
            require_once __DIR__ . '/includes/sla_helper.php';
            if (initialize_default_sla_rules($conn, $currentCompanyId)) {
                $success = "Default SLA rules initialized successfully";
            } else {
                $error = "Failed to initialize default rules (they may already exist)";
            }
        }
    }
}

// Get existing SLA rules
$rules = $conn->prepare("
    SELECT * FROM re_sla_rules 
    WHERE company_id = ? 
    ORDER BY 
        FIELD(priority, 'urgent', 'high', 'medium', 'low'),
        category IS NULL,
        category ASC
");
$rules->execute([$currentCompanyId]);
$rules = $rules->fetchAll(PDO::FETCH_ASSOC);

// Get categories from existing maintenance requests
$categories = $conn->prepare("
    SELECT DISTINCT category 
    FROM re_maintenance_requests 
    WHERE company_id = ? AND category IS NOT NULL AND category != ''
    ORDER BY category
");
$categories->execute([$currentCompanyId]);
$categories = $categories->fetchAll(PDO::FETCH_COLUMN);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'SLA Configuration';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label"><i class="bi bi-clock-history"></i> SLA Configuration</div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRuleModal">
                    <i class="bi bi-plus-circle"></i> Add SLA Rule
                </button>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Initialize default SLA rules? This will only add rules that don\'t already exist.');">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="initialize_defaults">
                    <button type="submit" class="btn btn-secondary">
                        <i class="bi bi-arrow-clockwise"></i> Initialize Defaults
                    </button>
                </form>
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
                <h5 class="mb-0">SLA Rules</h5>
            </div>
            <div class="card-body">
                <?php if (empty($rules)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No SLA rules configured. 
                        <a href="#" data-bs-toggle="modal" data-bs-target="#addRuleModal">Add your first rule</a> or 
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Initialize default SLA rules?');">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="initialize_defaults">
                            <button type="submit" class="btn btn-link p-0" style="text-decoration: underline;">initialize defaults</button>.
                        </form>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Priority</th>
                                    <th>Category</th>
                                    <th>Response Time</th>
                                    <th>Resolution Time</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rules as $rule): ?>
                                    <tr>
                                        <td>
                                            <?php
                                            $priorityClass = [
                                                'low' => 'secondary',
                                                'medium' => 'info',
                                                'high' => 'warning',
                                                'urgent' => 'danger'
                                            ];
                                            $class = $priorityClass[$rule['priority']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $class ?>"><?= ucfirst($rule['priority']) ?></span>
                                        </td>
                                        <td><?= h($rule['category'] ?: 'All Categories') ?></td>
                                        <td><?= h($rule['response_time_minutes']) ?> minutes</td>
                                        <td><?= h($rule['resolution_time_hours']) ?> hours</td>
                                        <td>
                                            <?php if ($rule['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" onclick="editRule(<?= htmlspecialchars(json_encode($rule)) ?>)">
                                                <i class="bi bi-pencil"></i> Edit
                                            </button>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this SLA rule?');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $rule['id'] ?>">
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

        <div class="alert alert-info mt-4">
            <h6><i class="bi bi-info-circle"></i> How SLA Rules Work</h6>
            <ul class="mb-0">
                <li><strong>Priority-based:</strong> Rules are matched by priority level (Urgent, High, Medium, Low)</li>
                <li><strong>Category-specific:</strong> You can create rules for specific categories (e.g., "Plumbing", "Electrical")</li>
                <li><strong>General rules:</strong> Rules without a category apply to all categories for that priority</li>
                <li><strong>Response Time:</strong> Target time to assign/acknowledge the request (in minutes)</li>
                <li><strong>Resolution Time:</strong> Target time to complete the request (in hours)</li>
                <li><strong>Matching:</strong> System first looks for category-specific rule, then falls back to general rule</li>
            </ul>
        </div>
    </div>

    <!-- Add/Edit Rule Modal -->
    <div class="modal fade" id="addRuleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add SLA Rule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="ruleForm">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="ruleId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Priority *</label>
                            <select name="priority" class="form-select" required id="rulePriority">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select" id="ruleCategory">
                                <option value="">All Categories (General Rule)</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= h($cat) ?>"><?= h($cat) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Leave as "All Categories" for a general rule, or select a specific category</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Response Time (minutes) *</label>
                            <input type="number" name="response_time_minutes" class="form-control" 
                                   id="ruleResponseTime" required min="1" 
                                   placeholder="e.g., 15, 30, 120">
                            <small class="text-muted">Target time to assign/acknowledge the request</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Resolution Time (hours) *</label>
                            <input type="number" name="resolution_time_hours" class="form-control" 
                                   id="ruleResolutionTime" required min="1" 
                                   placeholder="e.g., 4, 8, 24">
                            <small class="text-muted">Target time to complete the request</small>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" 
                                       id="ruleIsActive" value="1" checked>
                                <label class="form-check-label" for="ruleIsActive">
                                    Active
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Rule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editRule(rule) {
            document.getElementById('modalTitle').textContent = 'Edit SLA Rule';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('ruleId').value = rule.id;
            document.getElementById('rulePriority').value = rule.priority;
            document.getElementById('ruleCategory').value = rule.category || '';
            document.getElementById('ruleResponseTime').value = rule.response_time_minutes;
            document.getElementById('ruleResolutionTime').value = rule.resolution_time_hours;
            document.getElementById('ruleIsActive').checked = rule.is_active == 1;
            
            const modal = new bootstrap.Modal(document.getElementById('addRuleModal'));
            modal.show();
        }

        // Reset form when modal is closed
        document.getElementById('addRuleModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('ruleForm').reset();
            document.getElementById('modalTitle').textContent = 'Add SLA Rule';
            document.getElementById('formAction').value = 'add';
            document.getElementById('ruleId').value = '';
            document.getElementById('ruleIsActive').checked = true;
        });
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

