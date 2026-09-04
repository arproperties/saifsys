<?php
/**
 * Real Estate Module - Billing Management
 * Central hub for billing, service charges, penalties, and invoices
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

require_login();
// Check department access (backward compatible: fallback to module access)
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

// Get statistics
$stats = $conn->prepare("
    SELECT 
        (SELECT COUNT(*) FROM re_invoices WHERE company_id = ? AND status = 'sent') as pending_invoices,
        (SELECT COUNT(*) FROM re_invoices WHERE company_id = ? AND status = 'overdue') as overdue_invoices,
        (SELECT COUNT(*) FROM re_billing_items WHERE company_id = ? AND is_paid = 0 AND COALESCE(is_waived, 0) = 0 AND status != 'waived' AND due_date < CURDATE()) as overdue_items,
        (SELECT COALESCE(SUM(outstanding_amount), 0) FROM re_invoices WHERE company_id = ? AND status IN ('sent', 'partial', 'overdue')) as total_outstanding,
        (SELECT COUNT(*) FROM re_post_dated_cheques WHERE company_id = ? AND status = 'pending' AND cheque_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) as upcoming_cheques
");
$stats->execute([$currentCompanyId, $currentCompanyId, $currentCompanyId, $currentCompanyId, $currentCompanyId]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

// Get recent invoices
$recentInvoices = $conn->prepare("
    SELECT 
        i.*,
        l.lease_number,
        u.unit_number,
        b.name as building_name,
        t.first_name,
        t.last_name
    FROM re_invoices i
    JOIN re_leases l ON l.id = i.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE i.company_id = ?
    ORDER BY i.created_at DESC
    LIMIT 10
");
$recentInvoices->execute([$currentCompanyId]);
$recentInvoices = $recentInvoices->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming cheques
$upcomingCheques = $conn->prepare("
    SELECT 
        c.*,
        l.lease_number,
        u.unit_number,
        b.name as building_name,
        t.first_name,
        t.last_name
    FROM re_post_dated_cheques c
    JOIN re_leases l ON l.id = c.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE c.company_id = ? 
    AND c.status = 'pending'
    AND c.cheque_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY c.cheque_date ASC
    LIMIT 10
");
$upcomingCheques->execute([$currentCompanyId]);
$upcomingCheques = $upcomingCheques->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Billing Management';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label"><i class="bi bi-receipt"></i> Billing Management</div>
            <div>
                <a href="billing_invoice_create.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Create Invoice
                </a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center border-primary">
                    <div class="card-body">
                        <h5 class="text-muted">Pending Invoices</h5>
                        <h2 class="mb-0 text-primary"><?= $stats['pending_invoices'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h5 class="text-muted">Overdue Invoices</h5>
                        <h2 class="mb-0 text-danger"><?= $stats['overdue_invoices'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-warning">
                    <div class="card-body">
                        <h5 class="text-muted">Overdue Items</h5>
                        <h2 class="mb-0 text-warning"><?= $stats['overdue_items'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-info">
                    <div class="card-body">
                        <h5 class="text-muted">Total Outstanding</h5>
                        <h2 class="mb-0 text-info"><?= number_format($stats['total_outstanding'], 2) ?> AED</h2>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Quick Actions -->
            <div class="col-md-4 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-lightning"></i> Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="billing_service_charges.php" class="btn btn-outline-primary">
                                <i class="bi bi-tag"></i> Service Charges
                            </a>
                            <a href="billing_penalties.php" class="btn btn-outline-warning">
                                <i class="bi bi-exclamation-triangle"></i> Penalty Rules
                            </a>
                            <a href="billing_invoices.php" class="btn btn-outline-info">
                                <i class="bi bi-file-text"></i> All Invoices
                            </a>
                            <a href="billing_cheques.php" class="btn btn-outline-secondary">
                                <i class="bi bi-bank"></i> Post-Dated Cheques
                            </a>
                            <a href="billing_items.php" class="btn btn-outline-success">
                                <i class="bi bi-list-ul"></i> Billing Items
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Invoices -->
            <div class="col-md-8 mb-4">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-file-text"></i> Recent Invoices</h5>
                        <a href="billing_invoices.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recentInvoices)): ?>
                            <p class="text-muted">No invoices found.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Invoice #</th>
                                            <th>Lease</th>
                                            <th>Tenant</th>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentInvoices as $inv): ?>
                                            <tr>
                                                <td><?= h($inv['invoice_number']) ?></td>
                                                <td>
                                                    <?= h($inv['building_name']) ?> - <?= h($inv['unit_number']) ?><br>
                                                    <small class="text-muted"><?= h($inv['lease_number']) ?></small>
                                                </td>
                                                <td><?= h($inv['first_name'] . ' ' . $inv['last_name']) ?></td>
                                                <td><?= date('M d, Y', strtotime($inv['invoice_date'])) ?></td>
                                                <td><?= number_format($inv['total_amount'], 2) ?> AED</td>
                                                <td>
                                                    <span class="badge bg-<?= 
                                                        $inv['status'] === 'paid' ? 'success' : 
                                                        ($inv['status'] === 'overdue' ? 'danger' : 
                                                        ($inv['status'] === 'partial' ? 'warning' : 'info')) 
                                                    ?>">
                                                        <?= ucfirst($inv['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="billing_invoice_view.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
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
        </div>

        <!-- Upcoming Cheques -->
        <?php if (!empty($upcomingCheques)): ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-bank"></i> Upcoming Cheques (Next 30 Days)</h5>
                <a href="billing_cheques.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Cheque #</th>
                                <th>Lease</th>
                                <th>Tenant</th>
                                <th>Amount</th>
                                <th>Cheque Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcomingCheques as $cheque): ?>
                                <tr class="<?= strtotime($cheque['cheque_date']) <= time() ? 'table-warning' : '' ?>">
                                    <td><?= h($cheque['cheque_number']) ?></td>
                                    <td>
                                        <?= h($cheque['building_name']) ?> - <?= h($cheque['unit_number']) ?><br>
                                        <small class="text-muted"><?= h($cheque['lease_number']) ?></small>
                                    </td>
                                    <td><?= h($cheque['first_name'] . ' ' . $cheque['last_name']) ?></td>
                                    <td><?= number_format($cheque['cheque_amount'], 2) ?> AED</td>
                                    <td><?= date('M d, Y', strtotime($cheque['cheque_date'])) ?></td>
                                    <td>
                                        <span class="badge bg-<?= 
                                            $cheque['status'] === 'cleared' ? 'success' : 
                                            ($cheque['status'] === 'bounced' ? 'danger' : 
                                            ($cheque['status'] === 'deposited' ? 'info' : 'warning')) 
                                        ?>">
                                            <?= ucfirst($cheque['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="billing_cheque_view.php?id=<?= $cheque['id'] ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-eye"></i>
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

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
