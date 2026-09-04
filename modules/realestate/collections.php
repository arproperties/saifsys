<?php
/**
 * Real Estate Module - Collections & Alerts Dashboard
 * Manage overdue payments, alerts, and collections
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/installment_outstanding.php';

require_login();
// Check department access (backward compatible: fallback to module access)
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

// Get overdue installments
$overdueInstallments = $conn->prepare("
    SELECT 
        li.*,
        l.lease_number,
        l.monthly_rent,
        u.unit_number,
        b.name as building_name,
        t.first_name,
        t.last_name,
        t.phone,
        t.email,
        DATEDIFF(CURDATE(), li.installment_date) as days_overdue
    FROM re_lease_installments li
    JOIN re_leases l ON l.id = li.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE l.company_id = ?
    AND l.status <> 'draft'
    AND li.status = 'pending'
    AND li.installment_date < CURDATE()
    AND NOT EXISTS (
        SELECT 1
        FROM re_post_dated_cheques c
        WHERE c.installment_id = li.id
          AND c.lease_id = li.lease_id
          AND c.status IN ('cleared', 'returned', 'cancelled')
    )
    ORDER BY li.installment_date ASC
");
$overdueInstallments->execute([$currentCompanyId]);
$overdueInstallments = $overdueInstallments->fetchAll(PDO::FETCH_ASSOC);
// The schedule row's own status/amount are not a settlement signal: in Invoice Mode a
// receipt settles the invoices behind the row without ever writing it back to 'paid'.
// Resolve what is actually still owed (same precedence as lease_view.php) and drop rows
// that are already fully collected.
$overdueInstallments = re_apply_installment_outstanding($conn, $currentCompanyId, $overdueInstallments);

// Get overdue billing items
$overdueBillingItems = $conn->prepare("
    SELECT 
        bi.*,
        l.lease_number,
        u.unit_number,
        b.name as building_name,
        t.first_name,
        t.last_name,
        t.phone,
        t.email,
        DATEDIFF(CURDATE(), bi.due_date) as days_overdue
    FROM re_billing_items bi
    JOIN re_leases l ON l.id = bi.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE bi.company_id = ?
    AND l.status <> 'draft'
    AND bi.is_paid = 0
    AND COALESCE(bi.is_waived, 0) = 0
    AND bi.status != 'waived'
    AND bi.due_date < CURDATE()
    ORDER BY bi.due_date ASC
");
$overdueBillingItems->execute([$currentCompanyId]);
$overdueBillingItems = $overdueBillingItems->fetchAll(PDO::FETCH_ASSOC);

// Get overdue invoices
$overdueInvoices = $conn->prepare("
    SELECT 
        i.*,
        l.lease_number,
        u.unit_number,
        b.name as building_name,
        t.first_name,
        t.last_name,
        t.phone,
        t.email,
        DATEDIFF(CURDATE(), i.due_date) as days_overdue
    FROM re_invoices i
    JOIN re_leases l ON l.id = i.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE i.company_id = ?
    AND l.status <> 'draft'
    AND i.status IN ('sent', 'partial')
    AND i.due_date < CURDATE()
    ORDER BY i.due_date ASC
");
$overdueInvoices->execute([$currentCompanyId]);
$overdueInvoices = $overdueInvoices->fetchAll(PDO::FETCH_ASSOC);

// Get bounced cheques
$bouncedCheques = $conn->prepare("
    SELECT 
        c.*,
        l.lease_number,
        u.unit_number,
        b.name as building_name,
        t.first_name,
        t.last_name,
        t.phone,
        t.email
    FROM re_post_dated_cheques c
    JOIN re_leases l ON l.id = c.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE c.company_id = ? 
    AND c.status = 'bounced'
    ORDER BY c.bounced_date DESC
");
$bouncedCheques->execute([$currentCompanyId]);
$bouncedCheques = $bouncedCheques->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$totalOverdue = 0;
foreach ($overdueInstallments as $item) {
    $totalOverdue += (float)$item['outstanding_balance'];
}
foreach ($overdueBillingItems as $item) {
    $totalOverdue += (float)$item['total_amount'];
}
foreach ($overdueInvoices as $inv) {
    $totalOverdue += (float)$inv['outstanding_amount'];
}

$totalBounced = array_sum(array_column($bouncedCheques, 'cheque_amount'));

// Get recent alerts
$recentAlerts = $conn->prepare("
    SELECT 
        oa.*,
        l.lease_number,
        u.unit_number,
        b.name as building_name,
        t.first_name,
        t.last_name
    FROM re_overdue_rent_alerts oa
    JOIN re_leases l ON l.id = oa.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE oa.company_id = ?
    ORDER BY oa.alert_sent_date DESC
    LIMIT 10
");
$recentAlerts->execute([$currentCompanyId]);
$recentAlerts = $recentAlerts->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Collections & Alerts';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label"><i class="bi bi-exclamation-triangle"></i> Collections & Alerts</div>
            <div>
                <a href="collections_alerts_config.php" class="btn btn-primary">
                    <i class="bi bi-gear"></i> Alert Settings
                </a>
                <a href="collections_send_alerts.php" class="btn btn-success">
                    <i class="bi bi-envelope"></i> Send Alerts Now
                </a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h5 class="text-muted">Overdue Installments</h5>
                        <h2 class="mb-0 text-danger"><?= count($overdueInstallments) ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-warning">
                    <div class="card-body">
                        <h5 class="text-muted">Overdue Billing Items</h5>
                        <h2 class="mb-0 text-warning"><?= count($overdueBillingItems) ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h5 class="text-muted">Total Overdue</h5>
                        <h2 class="mb-0 text-danger"><?= number_format($totalOverdue, 2) ?> AED</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h5 class="text-muted">Bounced Cheques</h5>
                        <h2 class="mb-0 text-danger"><?= count($bouncedCheques) ?></h2>
                        <small><?= number_format($totalBounced, 2) ?> AED</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Overdue Installments -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="bi bi-calendar-x"></i> Overdue Rent Installments (<?= count($overdueInstallments) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($overdueInstallments)): ?>
                            <p class="text-muted">No overdue installments.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Lease</th>
                                            <th>Tenant</th>
                                            <th>Due Date</th>
                                            <th>Days Overdue</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($overdueInstallments as $item): ?>
                                            <tr>
                                                <td>
                                                    <?= h($item['building_name']) ?> - <?= h($item['unit_number']) ?><br>
                                                    <small class="text-muted"><?= h($item['lease_number']) ?></small>
                                                </td>
                                                <td>
                                                    <?= h($item['first_name'] . ' ' . $item['last_name']) ?><br>
                                                    <small class="text-muted"><?= h($item['phone']) ?></small>
                                                </td>
                                                <td><?= date('M d, Y', strtotime($item['installment_date'])) ?></td>
                                                <td>
                                                    <span class="badge bg-danger"><?= $item['days_overdue'] ?> days</span>
                                                </td>
                                                <td class="text-end">
                                                    <strong><?= number_format((float)$item['outstanding_balance'], 2) ?> AED</strong>
                                                    <?php if ((float)$item['collected_amount'] > 0.005): ?>
                                                        <br><small class="text-muted">
                                                            of <?= number_format((float)$item['amount'], 2) ?> ·
                                                            collected <?= number_format((float)$item['collected_amount'], 2) ?>
                                                        </small>
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

            <!-- Overdue Billing Items -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0"><i class="bi bi-exclamation-circle"></i> Overdue Billing Items (<?= count($overdueBillingItems) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($overdueBillingItems)): ?>
                            <p class="text-muted">No overdue billing items.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Lease</th>
                                            <th>Due Date</th>
                                            <th>Days Overdue</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($overdueBillingItems as $item): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= h($item['item_name']) ?></strong><br>
                                                    <small class="text-muted"><?= ucfirst(str_replace('_', ' ', $item['item_type'])) ?></small>
                                                </td>
                                                <td>
                                                    <?= h($item['building_name']) ?> - <?= h($item['unit_number']) ?><br>
                                                    <small class="text-muted"><?= h($item['lease_number']) ?></small>
                                                </td>
                                                <td><?= date('M d, Y', strtotime($item['due_date'])) ?></td>
                                                <td>
                                                    <span class="badge bg-warning"><?= $item['days_overdue'] ?> days</span>
                                                </td>
                                                <td class="text-end"><strong><?= number_format($item['total_amount'], 2) ?> AED</strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Overdue Invoices -->
        <?php if (!empty($overdueInvoices)): ?>
        <div class="card mb-4">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="bi bi-file-text"></i> Overdue Invoices (<?= count($overdueInvoices) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Lease</th>
                                <th>Tenant</th>
                                <th>Due Date</th>
                                <th>Days Overdue</th>
                                <th>Outstanding</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($overdueInvoices as $inv): ?>
                                <tr>
                                    <td><strong><?= h($inv['invoice_number']) ?></strong></td>
                                    <td>
                                        <?= h($inv['building_name']) ?> - <?= h($inv['unit_number']) ?><br>
                                        <small class="text-muted"><?= h($inv['lease_number']) ?></small>
                                    </td>
                                    <td><?= h($inv['first_name'] . ' ' . $inv['last_name']) ?></td>
                                    <td><?= date('M d, Y', strtotime($inv['due_date'])) ?></td>
                                    <td>
                                        <span class="badge bg-danger"><?= $inv['days_overdue'] ?> days</span>
                                    </td>
                                    <td class="text-end"><strong><?= number_format($inv['outstanding_amount'], 2) ?> AED</strong></td>
                                    <td>
                                        <a href="billing_invoice_view.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Bounced Cheques -->
        <?php if (!empty($bouncedCheques)): ?>
        <div class="card mb-4">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="bi bi-bank"></i> Bounced Cheques (<?= count($bouncedCheques) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Cheque #</th>
                                <th>Lease</th>
                                <th>Tenant</th>
                                <th>Amount</th>
                                <th>Bounced Date</th>
                                <th>Reason</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bouncedCheques as $cheque): ?>
                                <tr>
                                    <td><strong><?= h($cheque['cheque_number']) ?></strong></td>
                                    <td>
                                        <?= h($cheque['building_name']) ?> - <?= h($cheque['unit_number']) ?><br>
                                        <small class="text-muted"><?= h($cheque['lease_number']) ?></small>
                                    </td>
                                    <td><?= h($cheque['first_name'] . ' ' . $cheque['last_name']) ?></td>
                                    <td class="text-end"><strong><?= number_format($cheque['cheque_amount'], 2) ?> AED</strong></td>
                                    <td><?= $cheque['bounced_date'] ? date('M d, Y', strtotime($cheque['bounced_date'])) : '-' ?></td>
                                    <td><?= h($cheque['bounced_reason'] ?: '-') ?></td>
                                    <td>
                                        <a href="billing_cheque_view.php?id=<?= $cheque['id'] ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Alerts -->
        <?php if (!empty($recentAlerts)): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-bell"></i> Recent Alerts Sent</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Lease</th>
                                <th>Tenant</th>
                                <th>Amount</th>
                                <th>Days Overdue</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentAlerts as $alert): ?>
                                <tr>
                                    <td><?= date('M d, Y', strtotime($alert['alert_sent_date'])) ?></td>
                                    <td>
                                        <?= h($alert['building_name']) ?> - <?= h($alert['unit_number']) ?><br>
                                        <small class="text-muted"><?= h($alert['lease_number']) ?></small>
                                    </td>
                                    <td><?= h($alert['first_name'] . ' ' . $alert['last_name']) ?></td>
                                    <td><?= number_format($alert['amount'], 2) ?> AED</td>
                                    <td><?= $alert['days_overdue'] ?> days</td>
                                    <td>
                                        <span class="badge bg-<?= 
                                            $alert['status'] === 'resolved' ? 'success' : 
                                            ($alert['status'] === 'sent' ? 'info' : 'warning') 
                                        ?>">
                                            <?= ucfirst($alert['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
