<?php
/**
 * Real Estate Module - Payments Management
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

// Get payments
$dateFrom    = $_GET['date_from']   ?? date('Y-m-01');
$dateTo      = $_GET['date_to']     ?? date('Y-m-t');
$leaseSearch = trim($_GET['lease_search'] ?? '');

$sqlWhere = 'p.company_id = ?';
$sqlParams = [$currentCompanyId];

if ($leaseSearch !== '') {
    // Search by lease number, tenant name, unit number or building name
    $sqlWhere  .= ' AND (l.lease_number LIKE ? OR CONCAT(t.first_name," ",t.last_name) LIKE ? OR u.unit_number LIKE ? OR b.name LIKE ?)';
    $like = '%' . $leaseSearch . '%';
    $sqlParams = array_merge($sqlParams, [$like, $like, $like, $like]);
} else {
    // Only apply date filter when no search term (so date range still works normally)
    $sqlWhere  .= ' AND p.payment_date BETWEEN ? AND ?';
    $sqlParams  = array_merge($sqlParams, [$dateFrom, $dateTo]);
}

$payments = $conn->prepare("
    SELECT p.*, 
           l.lease_number, l.monthly_rent,
           u.unit_number,
           b.name as building_name,
           t.first_name, t.last_name
    FROM re_payments p
    JOIN re_leases l ON l.id = p.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE $sqlWhere
    ORDER BY p.payment_date DESC, p.id DESC
");
$payments->execute($sqlParams);
$payments = $payments->fetchAll(PDO::FETCH_ASSOC);

$totalAmount = array_sum(array_column($payments, 'amount'));

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Payments';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label">Rent Payments</div>
            <a href="payment_add.php" class="btn btn-primary" style="background-color: var(--primary); border-color: var(--primary);">
                <i class="bi bi-plus-circle"></i> Record Payment
            </a>
        </div>

        <!-- Filter -->
        <div class="card card-round mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Search by Lease / Tenant / Unit</label>
                        <input type="text" name="lease_search" class="form-control" placeholder="e.g. 120-0001, Farrukh, 209…" value="<?= h($leaseSearch) ?>">
                        <small class="text-muted">Searches all dates when filled in</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>" <?= $leaseSearch ? 'disabled' : '' ?>>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>" <?= $leaseSearch ? 'disabled' : '' ?>>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Search / Filter</button>
                    </div>
                    <?php if ($leaseSearch): ?>
                    <div class="col-md-1">
                        <a href="payments.php" class="btn btn-outline-secondary w-100" title="Clear search">✕ Clear</a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Summary -->
        <div class="alert alert-info mb-4">
            <strong>Total Payments:</strong> <?= number_format($totalAmount, 2) ?> AED 
            (<?= count($payments) ?> transactions)
        </div>

        <!-- Payments Table -->
        <div class="card card-round">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Lease</th>
                                <th>Unit</th>
                                <th>Tenant</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?= date('Y-m-d', strtotime($payment['payment_date'])) ?></td>
                                    <td><?= h($payment['lease_number'] ?: 'L-' . $payment['lease_id']) ?></td>
                                    <td><?= h($payment['building_name'] . ' - ' . $payment['unit_number']) ?></td>
                                    <td><?= h($payment['first_name'] . ' ' . $payment['last_name']) ?></td>
                                    <td><strong><?= number_format($payment['amount'], 2) ?> AED</strong></td>
                                    <td><?= h($payment['payment_method']) ?></td>
                                    <td><?= h($payment['reference_number'] ?: '-') ?></td>
                                    <td>
                                        <a href="payment_view.php?id=<?= $payment['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            View
                                        </a>
                                        <a href="payment_edit.php?id=<?= $payment['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                            Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

