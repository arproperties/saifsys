<?php
/**
 * Real Estate Module - Invoices Listing
 * List all invoices with filtering
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
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Build query
$where = ["i.company_id = ?"];
$params = [$currentCompanyId];

if ($statusFilter !== 'all') {
    $where[] = "i.status = ?";
    $params[] = $statusFilter;
}

if ($dateFrom) {
    $where[] = "i.invoice_date >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $where[] = "i.invoice_date <= ?";
    $params[] = $dateTo;
}

// Get invoices
$invoices = $conn->prepare("
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
    WHERE " . implode(' AND ', $where) . "
    ORDER BY i.invoice_date DESC, i.created_at DESC
");
$invoices->execute($params);
$invoices = $invoices->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN status = 'sent' THEN 1 END) as sent,
        COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid,
        COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue,
        COALESCE(SUM(CASE WHEN status IN ('sent', 'partial', 'overdue') THEN outstanding_amount ELSE 0 END), 0) as total_outstanding
    FROM re_invoices
    WHERE company_id = ?
");
$stats->execute([$currentCompanyId]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Invoices';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-file-text"></i> Invoices</h1>
            <a href="billing_invoice_create.php" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Create Invoice
            </a>
        </div>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total</h5>
                        <h2 class="mb-0"><?= $stats['total'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-info">
                    <div class="card-body">
                        <h5 class="text-muted">Sent</h5>
                        <h2 class="mb-0 text-info"><?= $stats['sent'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-success">
                    <div class="card-body">
                        <h5 class="text-muted">Paid</h5>
                        <h2 class="mb-0 text-success"><?= $stats['paid'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h5 class="text-muted">Outstanding</h5>
                        <h2 class="mb-0 text-danger"><?= number_format($stats['total_outstanding'], 2) ?> AED</h2>
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
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>Sent</option>
                            <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                            <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partial</option>
                            <option value="overdue" <?= $statusFilter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($dateFrom) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($dateTo) ?>">
                    </div>
                    <div class="col-md-3">
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

        <!-- Invoices Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Invoices (<?= count($invoices) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Date</th>
                                <th>Due Date</th>
                                <th>Lease</th>
                                <th>Tenant</th>
                                <th>Amount</th>
                                <th>Paid</th>
                                <th>Outstanding</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($invoices)): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted">No invoices found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($invoices as $inv): ?>
                                    <tr>
                                        <td><strong><?= h($inv['invoice_number']) ?></strong></td>
                                        <td><?= date('M d, Y', strtotime($inv['invoice_date'])) ?></td>
                                        <td class="<?= strtotime($inv['due_date']) < time() && $inv['status'] !== 'paid' ? 'text-danger' : '' ?>">
                                            <?= date('M d, Y', strtotime($inv['due_date'])) ?>
                                        </td>
                                        <td>
                                            <?= h($inv['building_name']) ?> - <?= h($inv['unit_number']) ?><br>
                                            <small class="text-muted"><?= h($inv['lease_number']) ?></small>
                                        </td>
                                        <td><?= h($inv['first_name'] . ' ' . $inv['last_name']) ?></td>
                                        <td class="text-end"><?= number_format($inv['total_amount'], 2) ?> AED</td>
                                        <td class="text-end text-success"><?= number_format($inv['paid_amount'], 2) ?> AED</td>
                                        <td class="text-end text-danger"><?= number_format($inv['outstanding_amount'], 2) ?> AED</td>
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
                                                <i class="bi bi-eye"></i> View
                                            </a>
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

