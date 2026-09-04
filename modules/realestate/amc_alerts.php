<?php
/**
 * Real Estate Module - AMC Alerts Management
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
$userId = current_user_id();

$status = $_GET['status'] ?? 'all';
$severity = $_GET['severity'] ?? 'all';
$alertType = $_GET['alert_type'] ?? 'all';
$success = '';

// Handle alert acknowledgment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'acknowledge') {
    csrf_verify();
    $alertId = (int)$_POST['alert_id'];
    $stmt = $conn->prepare("
        UPDATE re_amc_alerts 
        SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = NOW()
        WHERE id = ? AND status = 'pending'
    ");
    $stmt->execute([$userId, $alertId]);
    $success = "Alert acknowledged successfully!";
}

// Handle dismiss
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dismiss') {
    csrf_verify();
    $alertId = (int)$_POST['alert_id'];
    $stmt = $conn->prepare("UPDATE re_amc_alerts SET status = 'dismissed' WHERE id = ?");
    $stmt->execute([$alertId]);
    $success = "Alert dismissed!";
}

// Build filters
$where = [];
$params = [];

if ($status !== 'all') {
    $where[] = "a.status = ?";
    $params[] = $status;
}

if ($severity !== 'all') {
    $where[] = "a.severity = ?";
    $params[] = $severity;
}

if ($alertType !== 'all') {
    $where[] = "a.alert_type = ?";
    $params[] = $alertType;
}

$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

// Get alerts
$stmt = $conn->prepare("
    SELECT a.*, 
           ac.contract_number, b.name as building_name, cat.name as category_name,
           cert.certificate_number
    FROM re_amc_alerts a
    LEFT JOIN re_amc_contracts ac ON ac.id = a.contract_id
    LEFT JOIN re_buildings b ON b.id = ac.building_id
    LEFT JOIN re_amc_categories cat ON cat.id = ac.category_id
    LEFT JOIN re_amc_certificates cert ON cert.id = a.certificate_id
    {$whereSql}
    ORDER BY a.alert_date ASC, a.severity DESC, a.created_at DESC
");
$stmt->execute($params);
$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical,
        SUM(CASE WHEN severity = 'warning' THEN 1 ELSE 0 END) as warning
    FROM re_amc_alerts
    WHERE contract_id IN (SELECT id FROM re_amc_contracts WHERE company_id = ?)
");
$stats->execute([$currentCompanyId]);
$statistics = $stats->fetch(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'AMC Alerts';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-header-label">AMC Alerts & Expiry Notifications</h1>
            <p class="text-muted mb-0">Track contract and certificate expiry alerts</p>
        </div>
        <div class="btn-group">
            <a href="amc_set_alert_email.php" class="btn btn-outline-primary" title="Configure Email Notifications">
                <i class="bi bi-envelope"></i> Email Settings
            </a>
            <a href="amc.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to AMC
            </a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= h($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-primary bg-opacity-10 rounded p-3">
                                <i class="bi bi-bell text-primary fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="text-muted small">Total Alerts</div>
                            <div class="h4 mb-0"><?= number_format($statistics['total'] ?? 0) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-warning bg-opacity-10 rounded p-3">
                                <i class="bi bi-clock-history text-warning fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="text-muted small">Pending</div>
                            <div class="h4 mb-0"><?= number_format($statistics['pending'] ?? 0) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-danger bg-opacity-10 rounded p-3">
                                <i class="bi bi-exclamation-triangle text-danger fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="text-muted small">Critical</div>
                            <div class="h4 mb-0"><?= number_format($statistics['critical'] ?? 0) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-info bg-opacity-10 rounded p-3">
                                <i class="bi bi-info-circle text-info fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="text-muted small">Warning</div>
                            <div class="h4 mb-0"><?= number_format($statistics['warning'] ?? 0) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="acknowledged" <?= $status === 'acknowledged' ? 'selected' : '' ?>>Acknowledged</option>
                        <option value="resolved" <?= $status === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Severity</label>
                    <select name="severity" class="form-select">
                        <option value="all" <?= $severity === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="critical" <?= $severity === 'critical' ? 'selected' : '' ?>>Critical</option>
                        <option value="warning" <?= $severity === 'warning' ? 'selected' : '' ?>>Warning</option>
                        <option value="info" <?= $severity === 'info' ? 'selected' : '' ?>>Info</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Alert Type</label>
                    <select name="alert_type" class="form-select">
                        <option value="all" <?= $alertType === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="contract_expiry" <?= $alertType === 'contract_expiry' ? 'selected' : '' ?>>Contract Expiry</option>
                        <option value="certificate_expiry" <?= $alertType === 'certificate_expiry' ? 'selected' : '' ?>>Certificate Expiry</option>
                        <option value="visit_due" <?= $alertType === 'visit_due' ? 'selected' : '' ?>>Visit Due</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Alerts Table -->
    <div class="card shadow-sm">
        <div class="card-body">
            <?php if (empty($alerts)): ?>
                <p class="text-center text-muted py-4">No alerts found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Type</th>
                                <th>Contract/Certificate</th>
                                <th>Building</th>
                                <th>Alert Date</th>
                                <th>Expiry Date</th>
                                <th>Days Until Expiry</th>
                                <th>Severity</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alerts as $a): ?>
                                <tr class="<?= $a['severity'] === 'critical' ? 'table-danger' : ($a['severity'] === 'warning' ? 'table-warning' : '') ?>">
                                    <td>
                                        <span class="badge bg-info"><?= ucfirst(str_replace('_', ' ', $a['alert_type'])) ?></span>
                                    </td>
                                    <td>
                                        <?= h($a['contract_number'] ?: $a['certificate_number'] ?: 'N/A') ?><br>
                                        <small class="text-muted"><?= h($a['category_name'] ?: '') ?></small>
                                    </td>
                                    <td><?= h($a['building_name'] ?: 'N/A') ?></td>
                                    <td><?= h($a['alert_date']) ?></td>
                                    <td><?= h($a['expiry_date']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $a['days_until_expiry'] <= 0 ? 'danger' : ($a['days_until_expiry'] <= 7 ? 'warning' : 'info') ?>">
                                            <?= $a['days_until_expiry'] ?> days
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $a['severity'] === 'critical' ? 'danger' : ($a['severity'] === 'warning' ? 'warning' : 'info') ?>">
                                            <?= ucfirst($a['severity']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $a['status'] === 'pending' ? 'warning' : ($a['status'] === 'acknowledged' ? 'info' : 'success') ?>">
                                            <?= ucfirst($a['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($a['status'] === 'pending'): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Acknowledge this alert?')">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="acknowledge">
                                                <input type="hidden" name="alert_id" value="<?= $a['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-success">
                                                    <i class="bi bi-check"></i> Acknowledge
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($a['contract_id']): ?>
                                            <a href="amc_view.php?id=<?= $a['contract_id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i>
                                            </a>
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
