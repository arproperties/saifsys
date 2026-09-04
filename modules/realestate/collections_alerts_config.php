<?php
/**
 * Real Estate Module - Collections Alerts Configuration
 * Configure automated alerts for overdue payments, bounced cheques, etc.
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $alertType = $_POST['alert_type'] ?? '';
    $isEnabled = !empty($_POST['is_enabled']) ? 1 : 0;
    $daysBeforeAlert = (int)($_POST['days_before_alert'] ?? 0);
    $daysOverdueThreshold = (int)($_POST['days_overdue_threshold'] ?? 0);
    $alertFrequency = $_POST['alert_frequency'] ?? 'daily';
    $recipientEmails = $_POST['recipient_emails'] ?? '';
    
    if ($alertType) {
        // Check if config exists
        $check = $conn->prepare("
            SELECT id FROM re_collections_alerts_config 
            WHERE company_id = ? AND alert_type = ?
        ");
        $check->execute([$currentCompanyId, $alertType]);
        $existing = $check->fetch();
        
        if ($existing) {
            // Update
            $stmt = $conn->prepare("
                UPDATE re_collections_alerts_config
                SET is_enabled = ?,
                    days_before_alert = ?,
                    days_overdue_threshold = ?,
                    alert_frequency = ?,
                    recipient_emails = ?
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([
                $isEnabled, $daysBeforeAlert, $daysOverdueThreshold, 
                $alertFrequency, $recipientEmails, $existing['id'], $currentCompanyId
            ]);
        } else {
            // Insert
            $stmt = $conn->prepare("
                INSERT INTO re_collections_alerts_config
                (company_id, alert_type, is_enabled, days_before_alert, days_overdue_threshold, alert_frequency, recipient_emails)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $currentCompanyId, $alertType, $isEnabled, $daysBeforeAlert, 
                $daysOverdueThreshold, $alertFrequency, $recipientEmails
            ]);
        }
        
        $_SESSION['success'] = 'Alert configuration saved successfully.';
        header('Location: collections_alerts_config.php');
        exit;
    }
}

// Get all alert configurations
$alertConfigs = $conn->prepare("
    SELECT * FROM re_collections_alerts_config
    WHERE company_id = ?
    ORDER BY alert_type
");
$alertConfigs->execute([$currentCompanyId]);
$alertConfigs = $alertConfigs->fetchAll(PDO::FETCH_ASSOC);

// Create default configs if none exist
$defaultConfigs = [
    'overdue_rent' => ['days_overdue_threshold' => 0, 'alert_frequency' => 'daily'],
    'payment_received' => ['days_overdue_threshold' => 0, 'alert_frequency' => 'once'],
    'bounced_cheque' => ['days_overdue_threshold' => 0, 'alert_frequency' => 'once'],
    'upcoming_due' => ['days_before_alert' => 3, 'alert_frequency' => 'once'],
    'invoice_overdue' => ['days_overdue_threshold' => 0, 'alert_frequency' => 'daily']
];

$existingTypes = array_column($alertConfigs, 'alert_type');
foreach ($defaultConfigs as $type => $defaults) {
    if (!in_array($type, $existingTypes)) {
        $stmt = $conn->prepare("
            INSERT INTO re_collections_alerts_config
            (company_id, alert_type, is_enabled, days_before_alert, days_overdue_threshold, alert_frequency)
            VALUES (?, ?, 1, ?, ?, ?)
        ");
        $stmt->execute([
            $currentCompanyId, 
            $type, 
            $defaults['days_before_alert'] ?? 0,
            $defaults['days_overdue_threshold'] ?? 0,
            $defaults['alert_frequency']
        ]);
    }
}

// Re-fetch after creating defaults
$alertConfigs = $conn->prepare("
    SELECT * FROM re_collections_alerts_config
    WHERE company_id = ?
    ORDER BY alert_type
");
$alertConfigs->execute([$currentCompanyId]);
$alertConfigs = $alertConfigs->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Alert Configuration';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-gear"></i> Collections Alerts Configuration</h1>
            <a href="collections.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Collections
            </a>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= h($_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> 
            <strong>Note:</strong> Configure automated email alerts for collections. Recipient emails can be comma-separated.
        </div>

        <div class="row">
            <?php 
            $alertTypeLabels = [
                'overdue_rent' => 'Overdue Rent Alerts',
                'payment_received' => 'Payment Received Notifications',
                'bounced_cheque' => 'Bounced Cheque Alerts',
                'upcoming_due' => 'Upcoming Due Date Alerts',
                'invoice_overdue' => 'Overdue Invoice Alerts'
            ];
            
            foreach ($alertConfigs as $config): 
            ?>
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-<?= 
                                    $config['alert_type'] === 'overdue_rent' ? 'calendar-x' : 
                                    ($config['alert_type'] === 'payment_received' ? 'check-circle' : 
                                    ($config['alert_type'] === 'bounced_cheque' ? 'bank' : 
                                    ($config['alert_type'] === 'upcoming_due' ? 'calendar-event' : 'file-text'))) 
                                ?>"></i> 
                                <?= h($alertTypeLabels[$config['alert_type']] ?? ucfirst(str_replace('_', ' ', $config['alert_type']))) ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="alert_type" value="<?= h($config['alert_type']) ?>">
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="is_enabled" id="enabled_<?= $config['id'] ?>" 
                                               <?= $config['is_enabled'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="enabled_<?= $config['id'] ?>">
                                            Enable this alert
                                        </label>
                                    </div>
                                </div>
                                
                                <?php if (in_array($config['alert_type'], ['upcoming_due'])): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Days Before Due Date</label>
                                        <input type="number" name="days_before_alert" class="form-control" 
                                               value="<?= $config['days_before_alert'] ?>" min="0" max="30">
                                        <small class="form-text text-muted">Send alert this many days before due date</small>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (in_array($config['alert_type'], ['overdue_rent', 'invoice_overdue'])): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Days Overdue Threshold</label>
                                        <input type="number" name="days_overdue_threshold" class="form-control" 
                                               value="<?= $config['days_overdue_threshold'] ?>" min="0">
                                        <small class="form-text text-muted">Send alert after this many days overdue (0 = immediate)</small>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="mb-3">
                                    <label class="form-label">Alert Frequency</label>
                                    <select name="alert_frequency" class="form-select">
                                        <option value="once" <?= $config['alert_frequency'] === 'once' ? 'selected' : '' ?>>Once</option>
                                        <option value="daily" <?= $config['alert_frequency'] === 'daily' ? 'selected' : '' ?>>Daily</option>
                                        <option value="weekly" <?= $config['alert_frequency'] === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Recipient Emails</label>
                                    <textarea name="recipient_emails" class="form-control" rows="2" 
                                              placeholder="email1@example.com, email2@example.com"><?= h($config['recipient_emails'] ?? '') ?></textarea>
                                    <small class="form-text text-muted">Comma-separated email addresses</small>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Save Configuration
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

