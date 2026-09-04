<?php
/**
 * Real Estate Module - Billing Items Management
 * View and manage all billing items (service charges, penalties, etc.)
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

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$itemTypeFilter = $_GET['item_type'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Build query
$where = ["bi.company_id = ?"];
$params = [$currentCompanyId];

if ($statusFilter === 'paid') {
    $where[] = "bi.is_paid = 1";
} elseif ($statusFilter === 'unpaid') {
    $where[] = "bi.is_paid = 0 AND COALESCE(bi.is_waived, 0) = 0 AND bi.status != 'waived'";
} elseif ($statusFilter === 'overdue') {
    $where[] = "bi.is_paid = 0 AND COALESCE(bi.is_waived, 0) = 0 AND bi.status != 'waived' AND bi.due_date < CURDATE()";
} elseif ($statusFilter === 'waived') {
    $where[] = "COALESCE(bi.is_waived, 0) = 1 OR bi.status = 'waived'";
}

if ($itemTypeFilter !== 'all') {
    $where[] = "bi.item_type = ?";
    $params[] = $itemTypeFilter;
}

if ($dateFrom) {
    $where[] = "bi.due_date >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $where[] = "bi.due_date <= ?";
    $params[] = $dateTo;
}

// Get billing items
$billingItems = $conn->prepare("
    SELECT 
        bi.*,
        l.lease_number,
        u.unit_number,
        b.name as building_name,
        t.first_name,
        t.last_name
    FROM re_billing_items bi
    JOIN re_leases l ON l.id = bi.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY bi.due_date ASC, bi.created_at DESC
");
$billingItems->execute($params);
$billingItems = $billingItems->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN is_paid = 0 AND COALESCE(is_waived, 0) = 0 AND status != 'waived' THEN 1 END) as unpaid,
        COUNT(CASE WHEN is_paid = 0 AND COALESCE(is_waived, 0) = 0 AND status != 'waived' AND due_date < CURDATE() THEN 1 END) as overdue,
        COUNT(CASE WHEN COALESCE(is_waived, 0) = 1 OR status = 'waived' THEN 1 END) as waived,
        COALESCE(SUM(CASE WHEN is_paid = 0 AND COALESCE(is_waived, 0) = 0 AND status != 'waived' THEN total_amount ELSE 0 END), 0) as total_outstanding,
        COALESCE(SUM(CASE WHEN is_paid = 1 THEN total_amount ELSE 0 END), 0) as total_paid
    FROM re_billing_items
    WHERE company_id = ?
");
$stats->execute([$currentCompanyId]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Billing Items';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-list-ul"></i> Billing Items</h1>
            <a href="billing_item_add.php" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Create Billing Item
            </a>
        </div>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Items</h5>
                        <h2 class="mb-0"><?= $stats['total'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center border-warning">
                    <div class="card-body">
                        <h5 class="text-muted">Unpaid</h5>
                        <h2 class="mb-0 text-warning"><?= $stats['unpaid'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h5 class="text-muted">Overdue</h5>
                        <h2 class="mb-0 text-danger"><?= $stats['overdue'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center border-secondary">
                    <div class="card-body">
                        <h5 class="text-muted">Waived</h5>
                        <h2 class="mb-0 text-secondary"><?= $stats['waived'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center border-info">
                    <div class="card-body">
                        <h5 class="text-muted">Outstanding</h5>
                        <h2 class="mb-0 text-info"><?= number_format($stats['total_outstanding'], 2) ?> AED</h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                            <option value="unpaid" <?= $statusFilter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                            <option value="overdue" <?= $statusFilter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                            <option value="waived" <?= $statusFilter === 'waived' ? 'selected' : '' ?>>Waived</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Item Type</label>
                        <select name="item_type" class="form-select form-select-sm">
                            <option value="all" <?= $itemTypeFilter === 'all' ? 'selected' : '' ?>>All Types</option>
                            <option value="rent" <?= $itemTypeFilter === 'rent' ? 'selected' : '' ?>>Rent</option>
                            <option value="service_charge" <?= $itemTypeFilter === 'service_charge' ? 'selected' : '' ?>>Service Charge</option>
                            <option value="penalty" <?= $itemTypeFilter === 'penalty' ? 'selected' : '' ?>>Penalty</option>
                            <option value="other" <?= $itemTypeFilter === 'other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($dateFrom) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($dateTo) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-funnel"></i> Filter
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Billing Items Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Billing Items (<?= count($billingItems) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Type</th>
                                <th>Lease</th>
                                <th>Tenant</th>
                                <th>Due Date</th>
                                <th>Amount</th>
                                <th>Paid</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($billingItems)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted">No billing items found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($billingItems as $item): ?>
                                    <?php $isWaived = !empty($item['is_waived']) || ($item['status'] ?? '') === 'waived'; ?>
                                    <tr class="<?= 
                                        $isWaived ? 'table-secondary' : (!$item['is_paid'] && strtotime($item['due_date']) < time() ? 'table-danger' : '') 
                                    ?>">
                                        <td>
                                            <strong><?= h($item['item_name']) ?></strong>
                                            <?php if ($item['item_description']): ?>
                                                <br><small class="text-muted"><?= h(substr($item['item_description'], 0, 50)) ?>...</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $item['item_type'] === 'rent' ? 'primary' : 
                                                ($item['item_type'] === 'service_charge' ? 'info' : 
                                                ($item['item_type'] === 'penalty' ? 'danger' : 'secondary')) 
                                            ?>">
                                                <?= ucfirst(str_replace('_', ' ', $item['item_type'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= h($item['building_name']) ?> - <?= h($item['unit_number']) ?><br>
                                            <small class="text-muted"><?= h($item['lease_number']) ?></small>
                                        </td>
                                        <td><?= h($item['first_name'] . ' ' . $item['last_name']) ?></td>
                                        <td class="<?= !$isWaived && !$item['is_paid'] && strtotime($item['due_date']) < time() ? 'text-danger fw-bold' : '' ?>">
                                            <?= date('M d, Y', strtotime($item['due_date'])) ?>
                                        </td>
                                        <td class="text-end"><?= number_format($item['total_amount'], 2) ?> AED</td>
                                        <td class="text-end text-success"><?= number_format($item['paid_amount'], 2) ?> AED</td>
                                        <td>
                                            <?php if ($isWaived): ?>
                                                <span class="badge bg-secondary">Waived</span>
                                            <?php elseif ($item['is_paid']): ?>
                                                <span class="badge bg-success">Paid</span>
                                            <?php elseif (strtotime($item['due_date']) < time()): ?>
                                                <span class="badge bg-danger">Overdue</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

