<?php
/**
 * Real Estate Module - AMC Contract View
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

$contractId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$contractId) {
    header('Location: amc.php');
    exit;
}

// Get contract details
$stmt = $conn->prepare("
    SELECT ac.*, 
           b.name as building_name, b.address as building_address,
           cat.name as category_name, cat.code as category_code,
           v.vendor_name, v.contact_person, v.phone, v.email,
           DATEDIFF(ac.end_date, CURDATE()) as days_until_expiry
    FROM re_amc_contracts ac
    LEFT JOIN re_buildings b ON b.id = ac.building_id
    LEFT JOIN re_amc_categories cat ON cat.id = ac.category_id
    LEFT JOIN re_vendors v ON v.id = ac.vendor_id
    WHERE ac.id = ? AND ac.company_id = ?
");
$stmt->execute([$contractId, $currentCompanyId]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contract) {
    header('Location: amc.php');
    exit;
}

// Get visits
$visits = $conn->prepare("SELECT * FROM re_amc_visits WHERE contract_id = ? ORDER BY scheduled_date DESC LIMIT 10");
$visits->execute([$contractId]);
$visits = $visits->fetchAll(PDO::FETCH_ASSOC);

// Get certificates
$certificates = $conn->prepare("SELECT * FROM re_amc_certificates WHERE contract_id = ? ORDER BY expiry_date DESC");
$certificates->execute([$contractId]);
$certificates = $certificates->fetchAll(PDO::FETCH_ASSOC);

// Get alerts
$alerts = $conn->prepare("SELECT * FROM re_amc_alerts WHERE contract_id = ? AND status != 'resolved' ORDER BY alert_date ASC");
$alerts->execute([$contractId]);
$alerts = $alerts->fetchAll(PDO::FETCH_ASSOC);

// Get payments
$payments = $conn->prepare("SELECT * FROM re_amc_payments WHERE contract_id = ? ORDER BY payment_date DESC LIMIT 10");
$payments->execute([$contractId]);
$payments = $payments->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'AMC Contract: ' . h($contract['contract_number']);
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-header-label">AMC Contract: <?= h($contract['contract_number']) ?></h1>
            <?php if ($contract['contract_title']): ?>
                <p class="text-muted mb-0"><?= h($contract['contract_title']) ?></p>
            <?php endif; ?>
        </div>
        <div class="btn-group">
            <a href="amc_add.php?id=<?= $contractId ?>" class="btn btn-primary">
                <i class="bi bi-pencil"></i> Edit Contract
            </a>
            <a href="amc_visits.php?contract_id=<?= $contractId ?>" class="btn btn-info">
                <i class="bi bi-calendar-check"></i> Manage Visits
            </a>
            <a href="amc.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($alerts)): ?>
        <div class="alert alert-warning">
            <strong><i class="bi bi-exclamation-triangle"></i> Active Alerts:</strong>
            <?php foreach ($alerts as $alert): ?>
                <div><?= h($alert['alert_type']) ?> - <?= h($alert['expiry_date']) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Contract Details -->
        <div class="col-md-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Contract Details</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <strong>Building:</strong><br>
                            <?= h($contract['building_name']) ?><br>
                            <small class="text-muted"><?= h($contract['building_address']) ?></small>
                        </div>
                        <div class="col-md-6">
                            <strong>Category:</strong><br>
                            <span class="badge bg-secondary"><?= h($contract['category_name']) ?></span>
                        </div>
                        <div class="col-md-6">
                            <strong>Service Provider:</strong><br>
                            <?= h($contract['vendor_name']) ?><br>
                            <?php if ($contract['contact_person']): ?>
                                <small class="text-muted">Contact: <?= h($contract['contact_person']) ?></small><br>
                            <?php endif; ?>
                            <?php if ($contract['phone']): ?>
                                <small class="text-muted"><?= h($contract['phone']) ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <strong>Status:</strong><br>
                            <span class="badge bg-<?= $contract['status'] == 'active' ? 'success' : ($contract['status'] == 'expired' ? 'danger' : 'secondary') ?>">
                                <?= ucfirst($contract['status']) ?>
                            </span>
                            <?php if ($contract['status'] == 'active' && $contract['days_until_expiry'] <= 30): ?>
                                <br><small class="text-<?= $contract['days_until_expiry'] <= 7 ? 'danger' : 'warning' ?>">
                                    <?= $contract['days_until_expiry'] > 0 ? "{$contract['days_until_expiry']} days until expiry" : "Expired" ?>
                                </small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <strong>Start Date:</strong><br>
                            <?= h($contract['start_date']) ?>
                        </div>
                        <div class="col-md-6">
                            <strong>End Date:</strong><br>
                            <?= h($contract['end_date']) ?>
                        </div>
                        <div class="col-md-4">
                            <strong>Contract Value:</strong><br>
                            <?= number_format($contract['contract_value'], 2) ?> AED
                        </div>
                        <div class="col-md-4">
                            <strong>VAT (<?= $contract['vat_percentage'] ?>%):</strong><br>
                            <?= number_format($contract['vat_amount'], 2) ?> AED
                        </div>
                        <div class="col-md-4">
                            <strong>Total Amount:</strong><br>
                            <span class="h5"><?= number_format($contract['total_amount'], 2) ?> AED</span>
                        </div>
                        <div class="col-md-6">
                            <strong>Payment Schedule:</strong><br>
                            <?= ucfirst(str_replace('_', ' ', $contract['payment_schedule'])) ?>
                        </div>
                        <div class="col-md-6">
                            <strong>Visit Frequency:</strong><br>
                            <?= ucfirst(str_replace('_', ' ', $contract['visit_frequency'])) ?>
                        </div>
                        <?php if ($contract['sla_response_time']): ?>
                            <div class="col-md-6">
                                <strong>SLA Response Time:</strong><br>
                                <?= $contract['sla_response_time'] ?> hours
                            </div>
                        <?php endif; ?>
                        <?php if ($contract['sla_resolution_time']): ?>
                            <div class="col-md-6">
                                <strong>SLA Resolution Time:</strong><br>
                                <?= $contract['sla_resolution_time'] ?> hours
                            </div>
                        <?php endif; ?>
                        <?php if ($contract['notes']): ?>
                            <div class="col-12">
                                <strong>Notes:</strong><br>
                                <?= nl2br(h($contract['notes'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Visits -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Recent Visits</h5>
                    <a href="amc_visits.php?contract_id=<?= $contractId ?>" class="btn btn-light btn-sm">
                        <i class="bi bi-plus"></i> Schedule Visit
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($visits)): ?>
                        <p class="text-muted mb-0">No visits scheduled yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Technician</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($visits as $v): ?>
                                        <tr>
                                            <td><?= h($v['scheduled_date']) ?></td>
                                            <td><?= ucfirst($v['visit_type']) ?></td>
                                            <td>
                                                <span class="badge bg-<?= $v['status'] == 'completed' ? 'success' : ($v['status'] == 'scheduled' ? 'primary' : 'secondary') ?>">
                                                    <?= ucfirst($v['status']) ?>
                                                </span>
                                            </td>
                                            <td><?= h($v['technician_name'] ?? 'N/A') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-md-4">
            <!-- Certificates -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Compliance Certificates</h5>
                    <a href="amc_certificates.php?contract_id=<?= $contractId ?>" class="btn btn-light btn-sm">
                        <i class="bi bi-plus"></i> Add Certificate
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($certificates)): ?>
                        <p class="text-muted mb-0">No certificates recorded.</p>
                        <a href="amc_certificates.php?contract_id=<?= $contractId ?>" class="btn btn-sm btn-outline-warning mt-2">
                            <i class="bi bi-plus-circle"></i> Add First Certificate
                        </a>
                    <?php else: ?>
                        <?php foreach ($certificates as $cert): 
                            $expired = strtotime($cert['expiry_date']) < time();
                            $expiringSoon = strtotime($cert['expiry_date']) < strtotime('+30 days');
                        ?>
                            <div class="mb-3 pb-3 border-bottom">
                                <strong><?= ucfirst(str_replace('_', ' ', $cert['certificate_type'])) ?></strong><br>
                                <small class="text-muted">Issued: <?= h($cert['issue_date']) ?></small><br>
                                <small class="text-<?= $expired ? 'danger' : ($expiringSoon ? 'warning' : 'success') ?>">
                                    Expires: <?= h($cert['expiry_date']) ?>
                                </small>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payments -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Recent Payments</h5>
                    <a href="amc_payments.php?contract_id=<?= $contractId ?>" class="btn btn-light btn-sm">
                        <i class="bi bi-plus"></i> Add Payment
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($payments)): ?>
                        <p class="text-muted mb-0">No payments recorded.</p>
                        <a href="amc_payments.php?contract_id=<?= $contractId ?>" class="btn btn-sm btn-outline-success mt-2">
                            <i class="bi bi-plus-circle"></i> Record First Payment
                        </a>
                    <?php else: ?>
                        <?php foreach ($payments as $p): ?>
                            <div class="mb-2">
                                <strong><?= number_format($p['total_amount'], 2) ?> AED</strong><br>
                                <small class="text-muted"><?= h($p['payment_date']) ?></small><br>
                                <span class="badge bg-<?= $p['payment_status'] == 'paid' ? 'success' : 'warning' ?>">
                                    <?= ucfirst($p['payment_status']) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
