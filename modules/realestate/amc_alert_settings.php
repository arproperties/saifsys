<?php
/**
 * AMC Alert Settings - Comprehensive Alert Configuration
 * Manage email address and alert thresholds for AMC alerts
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
$activeTab = $_GET['tab'] ?? 'email';

// Handle email form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_email') {
    csrf_verify();
    
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = "Email address is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address";
    } else {
        // Use settings table (key-value store)
        $stmt = $conn->prepare("SELECT `key` FROM settings WHERE `key` = ? LIMIT 1");
        $stmt->execute(['amc_alert_email_' . $currentCompanyId]);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($exists) {
            $stmt = $conn->prepare("UPDATE settings SET `value` = ?, updated_at = NOW() WHERE `key` = ?");
            $stmt->execute([$email, 'amc_alert_email_' . $currentCompanyId]);
        } else {
            $stmt = $conn->prepare("INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, NOW())");
            $stmt->execute(['amc_alert_email_' . $currentCompanyId, $email]);
        }
        
        $success = "Email address saved successfully!";
        $activeTab = 'email';
    }
}

// Handle threshold form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_threshold') {
    csrf_verify();
    
    $alertType = $_POST['alert_type'] ?? '';
    $daysBefore = (int)($_POST['days_before_expiry'] ?? 0);
    $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
    $configId = !empty($_POST['config_id']) ? (int)$_POST['config_id'] : 0;
    
    if (empty($alertType)) {
        $error = "Alert type is required";
    } elseif ($daysBefore < 0 || $daysBefore > 365) {
        $error = "Days before expiry must be between 0 and 365";
    } else {
        if ($configId > 0) {
            // Update existing
            $stmt = $conn->prepare("UPDATE re_amc_alert_config SET days_before_expiry = ?, is_enabled = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$daysBefore, $isEnabled, $configId, $currentCompanyId]);
            $success = "Alert threshold updated successfully!";
        } else {
            // Insert new
            $stmt = $conn->prepare("INSERT INTO re_amc_alert_config (company_id, alert_type, days_before_expiry, is_enabled) VALUES (?, ?, ?, ?)");
            $stmt->execute([$currentCompanyId, $alertType, $daysBefore, $isEnabled]);
            $success = "Alert threshold added successfully!";
        }
        $activeTab = 'thresholds';
    }
}

// Handle threshold deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_threshold') {
    csrf_verify();
    
    $configId = (int)($_POST['config_id'] ?? 0);
    if ($configId > 0) {
        $stmt = $conn->prepare("DELETE FROM re_amc_alert_config WHERE id = ? AND company_id = ?");
        $stmt->execute([$configId, $currentCompanyId]);
        $success = "Alert threshold deleted successfully!";
        $activeTab = 'thresholds';
    }
}

// Get current email
$currentEmail = '';
$stmt = $conn->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
$stmt->execute(['amc_alert_email_' . $currentCompanyId]);
$emailRow = $stmt->fetch(PDO::FETCH_ASSOC);
if ($emailRow) {
    $currentEmail = $emailRow['value'];
}

// Get all alert configurations
$configs = $conn->prepare("
    SELECT id, alert_type, days_before_expiry, is_enabled 
    FROM re_amc_alert_config 
    WHERE company_id = ? 
    ORDER BY alert_type, days_before_expiry DESC
");
$configs->execute([$currentCompanyId]);
$alertConfigs = $configs->fetchAll(PDO::FETCH_ASSOC);

// Group by alert type
$configsByType = [];
foreach ($alertConfigs as $config) {
    $type = $config['alert_type'];
    if (!isset($configsByType[$type])) {
        $configsByType[$type] = [];
    }
    $configsByType[$type][] = $config;
}

// Get config for editing
$editConfig = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM re_amc_alert_config WHERE id = ? AND company_id = ?");
    $stmt->execute([$editId, $currentCompanyId]);
    $editConfig = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editConfig) {
        $activeTab = 'thresholds';
    }
}

// Use Real Estate layout
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'AMC Alert Settings';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-header-label">AMC Alert Settings</h1>
            <p class="text-muted mb-0">Configure email notifications and alert thresholds</p>
        </div>
        <a href="amc.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back to AMC
        </a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= h($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= h($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeTab === 'email' ? 'active' : '' ?>" id="email-tab" data-bs-toggle="tab" data-bs-target="#email" type="button" role="tab">
                <i class="bi bi-envelope"></i> Email Address
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeTab === 'thresholds' ? 'active' : '' ?>" id="thresholds-tab" data-bs-toggle="tab" data-bs-target="#thresholds" type="button" role="tab">
                <i class="bi bi-bell"></i> Alert Thresholds
            </button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content" id="settingsTabContent">
        <!-- Email Tab -->
        <div class="tab-pane fade <?= $activeTab === 'email' ? 'show active' : '' ?>" id="email" role="tabpanel">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-envelope"></i> Email Settings</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">
                                Enter the email address where you want to receive AMC alert notifications.
                                You will receive an email when the cron job generates new alerts.
                            </p>
                            
                            <form method="POST">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="save_email">
                                <div class="mb-3">
                                    <label class="form-label">Email Address <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control" 
                                        value="<?= h($currentEmail) ?>" 
                                        placeholder="your-email@example.com" required>
                                    <small class="text-muted">
                                        This email will receive notifications when AMC alerts are generated.
                                    </small>
                                </div>
                                
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i> <strong>Note:</strong>
                                    <ul class="mb-0 mt-2">
                                        <li>Emails are sent when the cron job generates new alerts</li>
                                        <li>You will only receive emails if alerts are generated</li>
                                        <li>The cron job runs daily at the scheduled time</li>
                                        <li>You can change this email address anytime</li>
                                    </ul>
                                </div>
                                
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-save"></i> Save Email Address
                                    </button>
                                </div>
                            </form>
                            
                            <?php if ($currentEmail): ?>
                                <div class="mt-3 pt-3 border-top">
                                    <small class="text-muted">
                                        <i class="bi bi-check-circle text-success"></i> 
                                        Currently configured: <strong><?= h($currentEmail) ?></strong>
                                    </small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Thresholds Tab -->
        <div class="tab-pane fade <?= $activeTab === 'thresholds' ? 'show active' : '' ?>" id="thresholds" role="tabpanel">
            <div class="row">
                <div class="col-md-<?= $editConfig ? '7' : '12' ?>">
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-bell"></i> Alert Thresholds</h5>
                            <?php if (!$editConfig): ?>
                                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addThresholdModal">
                                    <i class="bi bi-plus-circle"></i> Add Threshold
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">
                                Configure when alerts should be generated before contracts, certificates, or visits expire.
                                You can set multiple thresholds (e.g., 30 days and 7 days before expiry).
                            </p>

                            <?php
                            $alertTypeLabels = [
                                'contract_expiry' => 'Contract Expiry',
                                'certificate_expiry' => 'Certificate Expiry',
                                'visit_due' => 'Visit Due'
                            ];
                            
                            if (empty($alertConfigs)): ?>
                                <div class="text-center text-muted py-4">
                                    <i class="bi bi-bell-slash fs-1 d-block mb-2"></i>
                                    No alert thresholds configured yet. Add one to get started.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Alert Type</th>
                                                <th>Days Before Expiry</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($alertConfigs as $config): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= h($alertTypeLabels[$config['alert_type']] ?? $config['alert_type']) ?></strong>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-secondary"><?= $config['days_before_expiry'] ?> days</span>
                                                    </td>
                                                    <td>
                                                        <?php if ($config['is_enabled']): ?>
                                                            <span class="badge bg-success">Enabled</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">Disabled</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <a href="?tab=thresholds&edit=<?= $config['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="bi bi-pencil"></i> Edit
                                                        </a>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this alert threshold?');">
                                                            <?php csrf_field(); ?>
                                                            <input type="hidden" name="action" value="delete_threshold">
                                                            <input type="hidden" name="config_id" value="<?= $config['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">
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

                <?php if ($editConfig): ?>
                    <div class="col-md-5">
                        <div class="card shadow-sm">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0"><i class="bi bi-pencil"></i> Edit Threshold</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="save_threshold">
                                    <input type="hidden" name="config_id" value="<?= $editConfig['id'] ?>">
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Alert Type</label>
                                        <input type="text" class="form-control" value="<?= h($alertTypeLabels[$editConfig['alert_type']] ?? $editConfig['alert_type']) ?>" readonly>
                                        <small class="text-muted">Alert type cannot be changed</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Days Before Expiry <span class="text-danger">*</span></label>
                                        <input type="number" name="days_before_expiry" class="form-control" 
                                            value="<?= $editConfig['days_before_expiry'] ?>" 
                                            min="0" max="365" required>
                                        <small class="text-muted">Number of days before expiry to send the alert (0-365)</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="is_enabled" id="is_enabled" 
                                                <?= $editConfig['is_enabled'] ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="is_enabled">
                                                Enable this alert threshold
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-save"></i> Update Threshold
                                        </button>
                                        <a href="?tab=thresholds" class="btn btn-secondary">
                                            <i class="bi bi-x"></i> Cancel
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add Threshold Modal -->
<div class="modal fade" id="addThresholdModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add Alert Threshold</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="save_threshold">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Alert Type <span class="text-danger">*</span></label>
                        <select name="alert_type" class="form-select" required>
                            <option value="">Select alert type...</option>
                            <option value="contract_expiry">Contract Expiry</option>
                            <option value="certificate_expiry">Certificate Expiry</option>
                            <option value="visit_due">Visit Due</option>
                        </select>
                        <small class="text-muted">Type of alert to configure</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Days Before Expiry <span class="text-danger">*</span></label>
                        <input type="number" name="days_before_expiry" class="form-control" 
                            value="30" min="0" max="365" required>
                        <small class="text-muted">Number of days before expiry to send the alert (0-365)</small>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_enabled" id="is_enabled_new" checked>
                            <label class="form-check-label" for="is_enabled_new">
                                Enable this alert threshold
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Add Threshold
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Maintain active tab on page reload
document.addEventListener('DOMContentLoaded', function() {
    const activeTab = '<?= $activeTab ?>';
    if (activeTab) {
        const tabElement = document.getElementById(activeTab + '-tab');
        if (tabElement) {
            const tab = new bootstrap.Tab(tabElement);
            tab.show();
        }
    }
});
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
