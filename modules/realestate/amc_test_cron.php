<?php
/**
 * AMC Alert Cron Test Page
 * Use this page to manually test the AMC alert cron job
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

$output = '';
$error = '';
$success = '';

// Run cron if requested
if (isset($_GET['run_cron'])) {
    ob_start();
    
    // Capture output from cron script
    $_GET['run_cron'] = 1; // Set the parameter for the cron script
    
    try {
        // Include and run the cron script
        require_once __DIR__ . '/amc_alert_cron.php';
        $output = ob_get_clean();
        $success = "Cron job executed successfully!";
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
        $output = ob_get_clean();
    }
}

// Get alert configurations
$configs = $conn->query("
    SELECT company_id, alert_type, days_before_expiry, is_enabled 
    FROM re_amc_alert_config 
    WHERE company_id = {$currentCompanyId} AND is_enabled = 1
    ORDER BY alert_type, days_before_expiry
")->fetchAll(PDO::FETCH_ASSOC);

// Get email configuration
$emailConfig = $conn->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
$emailConfig->execute(['amc_alert_email_' . $currentCompanyId]);
$emailRow = $emailConfig->fetch(PDO::FETCH_ASSOC);
$configuredEmail = $emailRow['value'] ?? 'Not configured';

// Get recent alerts
$recentAlerts = $conn->prepare("
    SELECT a.*, ac.contract_number, b.name as building_name
    FROM re_amc_alerts a
    LEFT JOIN re_amc_contracts ac ON ac.id = a.contract_id
    LEFT JOIN re_buildings b ON b.id = ac.building_id
    WHERE ac.company_id = ?
    ORDER BY a.alert_date DESC, a.created_at DESC
    LIMIT 10
");
$recentAlerts->execute([$currentCompanyId]);
$recentAlerts = $recentAlerts->fetchAll(PDO::FETCH_ASSOC);

// Get contracts that will expire soon (for testing)
$expiringContracts = $conn->prepare("
    SELECT id, contract_number, contract_title, end_date, 
           DATEDIFF(end_date, CURDATE()) as days_left
    FROM re_amc_contracts
    WHERE company_id = ? AND status = 'active'
    AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
    ORDER BY end_date ASC
    LIMIT 10
");
$expiringContracts->execute([$currentCompanyId]);
$expiringContracts = $expiringContracts->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'Test AMC Alert Cron';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-header-label">Test AMC Alert Cron Job</h1>
            <p class="text-muted mb-0">Manually trigger the AMC alert generation and test email notifications</p>
        </div>
        <a href="amc.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back to AMC
        </a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <strong>Success!</strong> <?= h($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <strong>Error!</strong> <?= h($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Configuration Status -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-gear"></i> Alert Configuration</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($configs)): ?>
                        <p class="text-muted mb-0">No alert thresholds configured. <a href="amc_alert_settings.php">Configure alerts</a></p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Alert Type</th>
                                        <th>Days Before</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $typeLabels = [
                                        'contract_expiry' => 'Contract Expiry',
                                        'certificate_expiry' => 'Certificate Expiry',
                                        'visit_due' => 'Visit Due'
                                    ];
                                    foreach ($configs as $config): ?>
                                        <tr>
                                            <td><?= h($typeLabels[$config['alert_type']] ?? $config['alert_type']) ?></td>
                                            <td><?= $config['days_before_expiry'] ?> days</td>
                                            <td>
                                                <span class="badge bg-<?= $config['is_enabled'] ? 'success' : 'secondary' ?>">
                                                    <?= $config['is_enabled'] ? 'Enabled' : 'Disabled' ?>
                                                </span>
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
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-envelope"></i> Email Configuration</h5>
                </div>
                <div class="card-body">
                    <p><strong>Recipient Email:</strong></p>
                    <?php if ($configuredEmail && $configuredEmail !== 'Not configured'): ?>
                        <p class="mb-2">
                            <span class="badge bg-success"><?= h($configuredEmail) ?></span>
                        </p>
                        <small class="text-muted">Emails will be sent to this address when alerts are generated.</small>
                    <?php else: ?>
                        <p class="text-warning mb-2">
                            <i class="bi bi-exclamation-triangle"></i> Email not configured
                        </p>
                        <a href="amc_alert_settings.php" class="btn btn-sm btn-primary">
                            <i class="bi bi-gear"></i> Configure Email
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Test Contracts -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-warning text-dark">
            <h5 class="mb-0"><i class="bi bi-calendar-check"></i> Contracts Expiring Soon (Test Data)</h5>
        </div>
        <div class="card-body">
            <?php if (empty($expiringContracts)): ?>
                <p class="text-muted mb-0">No contracts expiring in the next 60 days. Create a test contract with an expiry date within your alert threshold to test.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Contract #</th>
                                <th>Title</th>
                                <th>End Date</th>
                                <th>Days Left</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expiringContracts as $contract): ?>
                                <tr>
                                    <td><?= h($contract['contract_number']) ?></td>
                                    <td><?= h($contract['contract_title'] ?: 'N/A') ?></td>
                                    <td><?= h($contract['end_date']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $contract['days_left'] <= 7 ? 'danger' : ($contract['days_left'] <= 30 ? 'warning' : 'info') ?>">
                                            <?= $contract['days_left'] ?> days
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td colspan="4" class="text-muted small">
                                    <i class="bi bi-info-circle"></i> 
                                    Alerts will be generated if a contract's end date matches your configured threshold (e.g., 30 days or 7 days before expiry).
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Run Cron Job -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-play-circle"></i> Run Cron Job</h5>
        </div>
        <div class="card-body">
            <p>Click the button below to manually trigger the AMC alert cron job. This will:</p>
            <ul>
                <li>Check for contracts/certificates expiring based on your configured thresholds</li>
                <li>Generate alerts for matching items</li>
                <li>Send email notification if alerts are generated and email is configured</li>
            </ul>
            
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> <strong>Note:</strong>
                <ul class="mb-0 mt-2">
                    <li>Alerts are only generated if a contract/certificate expiry date matches your configured threshold</li>
                    <li>For example, if you have a "30 days before expiry" threshold, alerts will only generate for contracts expiring exactly 30 days from today</li>
                    <li>To test, create a contract with an end date that matches your alert threshold</li>
                </ul>
            </div>
            
            <a href="?run_cron=1" class="btn btn-primary btn-lg" onclick="return confirm('Are you sure you want to run the cron job now?');">
                <i class="bi bi-play-fill"></i> Run Cron Job Now
            </a>
        </div>
    </div>

    <!-- Cron Output -->
    <?php if ($output): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="bi bi-terminal"></i> Cron Job Output</h5>
            </div>
            <div class="card-body">
                <pre class="bg-dark text-light p-3 rounded" style="max-height: 300px; overflow-y: auto;"><?= h($output) ?></pre>
            </div>
        </div>
    <?php endif; ?>

    <!-- Recent Alerts -->
    <div class="card shadow-sm">
        <div class="card-header bg-warning text-dark">
            <h5 class="mb-0"><i class="bi bi-bell"></i> Recent Alerts (Last 10)</h5>
        </div>
        <div class="card-body">
            <?php if (empty($recentAlerts)): ?>
                <p class="text-muted mb-0">No alerts generated yet. Run the cron job to generate alerts.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Contract</th>
                                <th>Building</th>
                                <th>Expiry Date</th>
                                <th>Days Until</th>
                                <th>Severity</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentAlerts as $alert): ?>
                                <tr>
                                    <td><?= h($alert['alert_date']) ?></td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?= ucfirst(str_replace('_', ' ', $alert['alert_type'])) ?>
                                        </span>
                                    </td>
                                    <td><?= h($alert['contract_number'] ?: 'N/A') ?></td>
                                    <td><?= h($alert['building_name'] ?: 'N/A') ?></td>
                                    <td><?= h($alert['expiry_date']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $alert['days_until_expiry'] <= 7 ? 'danger' : ($alert['days_until_expiry'] <= 30 ? 'warning' : 'info') ?>">
                                            <?= $alert['days_until_expiry'] ?> days
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $alert['severity'] === 'critical' ? 'danger' : ($alert['severity'] === 'warning' ? 'warning' : 'info') ?>">
                                            <?= ucfirst($alert['severity']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $alert['status'] === 'pending' ? 'warning' : ($alert['status'] === 'resolved' ? 'success' : 'secondary') ?>">
                                            <?= ucfirst($alert['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">
                    <a href="amc_alerts.php" class="btn btn-outline-primary">
                        <i class="bi bi-list"></i> View All Alerts
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
