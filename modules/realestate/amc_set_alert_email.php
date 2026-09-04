<?php
/**
 * AMC Alert Email Configuration
 * Simple page to set the email address for AMC alert notifications
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = "Email address is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address";
    } else {
        // Use settings table (key-value store)
        // Check if setting exists
        $stmt = $conn->prepare("SELECT `key` FROM settings WHERE `key` = ? LIMIT 1");
        $stmt->execute(['amc_alert_email_' . $currentCompanyId]);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($exists) {
            // Update existing
            $stmt = $conn->prepare("UPDATE settings SET `value` = ?, updated_at = NOW() WHERE `key` = ?");
            $stmt->execute([$email, 'amc_alert_email_' . $currentCompanyId]);
        } else {
            // Insert new
            $stmt = $conn->prepare("INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, NOW())");
            $stmt->execute(['amc_alert_email_' . $currentCompanyId, $email]);
        }
        
        $success = "Email address saved successfully! AMC alert notifications will be sent to: {$email}";
    }
}

// Get current email from settings table
$currentEmail = '';
$stmt = $conn->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
$stmt->execute(['amc_alert_email_' . $currentCompanyId]);
$emailRow = $stmt->fetch(PDO::FETCH_ASSOC);
if ($emailRow) {
    $currentEmail = $emailRow['value'];
}

// Use Real Estate layout
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'AMC Alert Email Configuration';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-header-label">AMC Alert Email Configuration</h1>
            <p class="text-muted mb-0">Configure email address for AMC alert notifications</p>
        </div>
        <a href="amc.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back to AMC
        </a>
    </div>

    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-envelope"></i> Email Settings</h5>
                </div>
                <div class="card-body">
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
                    
                    <p class="text-muted">
                        Enter the email address where you want to receive AMC alert notifications.
                        You will receive an email when the cron job generates new alerts.
                    </p>
                    
                    <form method="POST">
                        <?php csrf_field(); ?>
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

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
