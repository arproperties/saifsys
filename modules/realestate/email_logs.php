<?php
/**
 * Real Estate Module - Email Logs & Debugging
 * View email notification logs and test email configuration
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

// Handle test email
$testResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_email') {
    csrf_verify();
    
    $testEmail = trim($_POST['test_email'] ?? '');
    if (filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        require_once __DIR__ . '/includes/re_email_helper.php';
        require_once __DIR__ . '/../../includes/mailer.php';
        
        // Get email settings
        $emailSettings = $conn->prepare("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1");
        $emailSettings->execute();
        $settings = $emailSettings->fetch(PDO::FETCH_ASSOC);
        
        if ($settings && !empty($settings['smtp_host'])) {
            $subject = "[Test] Real Estate Email Notification";
            $htmlBody = "<html><body><h2>Test Email</h2><p>This is a test email from the Real Estate Management System.</p><p>If you received this, your email configuration is working correctly.</p></body></html>";
            
            $mailResult = send_smtp_mail($settings, $testEmail, $subject, $htmlBody);
            
            $message = $mailResult['ok'] ? 'Test email sent successfully!' : 'Failed to send: ' . ($mailResult['error'] ?? 'Unknown error');
            
            // Add troubleshooting tips for authentication errors
            if (!$mailResult['ok'] && (stripos($mailResult['error'] ?? '', 'authenticate') !== false)) {
                $message .= '<br><br><strong>Troubleshooting Tips:</strong><ul>';
                $message .= '<li>Verify SMTP Username is the <strong>full email address</strong> (e.g., accounts@heroeszone.ae)</li>';
                $message .= '<li>Verify SMTP Password is correct (check for typos, spaces, or special characters)</li>';
                $message .= '<li>For Titan Email: Use Port <strong>587 with TLS</strong> or Port <strong>465 with SSL</strong></li>';
                $message .= '<li>Some email providers require app-specific passwords instead of regular passwords</li>';
                $message .= '<li>Check if your email account has 2FA enabled (may need app password)</li>';
                $message .= '</ul>';
            }
            
            $testResult = [
                'success' => $mailResult['ok'],
                'message' => $message
            ];
        } else {
            $testResult = ['success' => false, 'message' => 'Email settings not configured or disabled'];
        }
    } else {
        $testResult = ['success' => false, 'message' => 'Invalid email address'];
    }
}

// Get email logs
$statusFilter = $_GET['status'] ?? 'all';
$typeFilter = $_GET['type'] ?? 'all';

$where = ["el.company_id = ?"];
$params = [$currentCompanyId];

if ($statusFilter !== 'all') {
    $where[] = "el.status = ?";
    $params[] = $statusFilter;
}

if ($typeFilter !== 'all') {
    $where[] = "el.notification_type = ?";
    $params[] = $typeFilter;
}

$stmt = $conn->prepare("
    SELECT el.*
    FROM re_email_logs el
    WHERE " . implode(' AND ', $where) . "
    ORDER BY el.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get email configuration status
$emailSettings = $conn->query("SELECT * FROM app_email_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$reNotifications = $conn->prepare("
    SELECT notification_type, COUNT(*) as count
    FROM re_email_notifications
    WHERE company_id = ? AND is_enabled = 1
    GROUP BY notification_type
");
$reNotifications->execute([$currentCompanyId]);
$notificationCounts = $reNotifications->fetchAll(PDO::FETCH_KEY_PAIR);

// Statistics
$stats = [
    'total' => count($logs),
    'sent' => count(array_filter($logs, fn($l) => $l['status'] === 'sent')),
    'failed' => count(array_filter($logs, fn($l) => $l['status'] === 'failed')),
    'pending' => count(array_filter($logs, fn($l) => $l['status'] === 'pending'))
];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Email Logs';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="page-header-label mb-4">Email Notification Logs & Debugging</div>

        <!-- Configuration Status -->
        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="bi bi-gear"></i> Email Configuration Status</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>SMTP Settings</h6>
                        <ul class="list-unstyled">
                            <li>
                                <strong>Status:</strong> 
                                <?php if ($emailSettings['is_enabled'] ?? 0): ?>
                                    <span class="badge bg-success">Enabled</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Disabled</span>
                                <?php endif; ?>
                            </li>
                            <li><strong>SMTP Host:</strong> <?= h($emailSettings['smtp_host'] ?? 'Not configured') ?></li>
                            <li><strong>SMTP Port:</strong> <?= h($emailSettings['smtp_port'] ?? 'Not configured') ?></li>
                            <li><strong>SMTP Encryption:</strong> <?= strtoupper($emailSettings['smtp_secure'] ?? 'Not configured') ?></li>
                            <li><strong>SMTP Username:</strong> <?= h($emailSettings['smtp_username'] ? substr($emailSettings['smtp_username'], 0, 20) . '...' : 'Not configured') ?></li>
                            <li><strong>From Email:</strong> <?= h($emailSettings['from_email'] ?? 'Not configured') ?></li>
                            <li><strong>From Name:</strong> <?= h($emailSettings['from_name'] ?? 'Not configured') ?></li>
                        </ul>
                        <a href="/settings.php?tab=email" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil"></i> Configure Email Settings
                        </a>
                    </div>
                    <div class="col-md-6">
                        <h6>Real Estate Notification Recipients</h6>
                        <ul class="list-unstyled">
                            <li><strong>Maintenance Request:</strong> <?= $notificationCounts['maintenance_request'] ?? 0 ?> recipient(s)</li>
                            <li><strong>Extra Service Request:</strong> <?= $notificationCounts['extra_service_request'] ?? 0 ?> recipient(s)</li>
                            <li><strong>Lease Expiry:</strong> <?= $notificationCounts['lease_expiry'] ?? 0 ?> recipient(s)</li>
                            <li><strong>Payment Overdue:</strong> <?= $notificationCounts['payment_overdue'] ?? 0 ?> recipient(s)</li>
                        </ul>
                        <a href="/settings.php?tab=re_email" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil"></i> Configure Recipients
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Test Email -->
        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="bi bi-send"></i> Test Email</h5>
            </div>
            <div class="card-body">
                <?php if ($testResult): ?>
                    <div class="alert alert-<?= $testResult['success'] ? 'success' : 'danger' ?>">
                        <?= $testResult['success'] ? h($testResult['message']) : $testResult['message'] ?>
                    </div>
                <?php endif; ?>
                <form method="POST">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="test_email">
                    <div class="row">
                        <div class="col-md-8">
                            <input type="email" name="test_email" class="form-control" 
                                   placeholder="Enter email address to test" required>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-send"></i> Send Test Email
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3><?= $stats['total'] ?></h3>
                        <small class="text-muted">Total Logs</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-success">
                    <div class="card-body">
                        <h3 class="text-success"><?= $stats['sent'] ?></h3>
                        <small class="text-muted">Sent</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h3 class="text-danger"><?= $stats['failed'] ?></h3>
                        <small class="text-muted">Failed</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-warning">
                    <div class="card-body">
                        <h3 class="text-warning"><?= $stats['pending'] ?></h3>
                        <small class="text-muted">Pending</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Email Logs -->
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-list"></i> Email Logs</h5>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>Sent</option>
                            <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
                            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select" onchange="this.form.submit()">
                            <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="maintenance_request" <?= $typeFilter === 'maintenance_request' ? 'selected' : '' ?>>Maintenance Request</option>
                            <option value="extra_service_request" <?= $typeFilter === 'extra_service_request' ? 'selected' : '' ?>>Extra Service Request</option>
                            <option value="lease_expiry" <?= $typeFilter === 'lease_expiry' ? 'selected' : '' ?>>Lease Expiry</option>
                            <option value="payment_overdue" <?= $typeFilter === 'payment_overdue' ? 'selected' : '' ?>>Payment Overdue</option>
                        </select>
                    </div>
                </form>

                <?php if (empty($logs)): ?>
                    <p class="text-muted">No email logs found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Recipient</th>
                                    <th>Subject</th>
                                    <th>Status</th>
                                    <th>Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?></td>
                                        <td><?= ucwords(str_replace('_', ' ', $log['notification_type'])) ?></td>
                                        <td><?= h($log['recipient_email']) ?></td>
                                        <td><small><?= h(substr($log['subject'] ?? '', 0, 50)) ?><?= strlen($log['subject'] ?? '') > 50 ? '...' : '' ?></small></td>
                                        <td>
                                            <?php
                                            $statusClass = [
                                                'sent' => 'success',
                                                'failed' => 'danger',
                                                'pending' => 'warning',
                                                'skipped' => 'secondary'
                                            ];
                                            $class = $statusClass[$log['status']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $class ?>"><?= ucfirst($log['status']) ?></span>
                                        </td>
                                        <td>
                                            <?php if ($log['error_message']): ?>
                                                <small class="text-danger"><?= h(substr($log['error_message'], 0, 100)) ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
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

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

