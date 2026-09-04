<?php
/**
 * Real Estate Module - Move-Out Management
 * List and manage tenant move-out processes
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
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_OPERATIONS, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Build query
$where = ["mo.company_id = ?"];
$params = [$currentCompanyId];

if ($statusFilter !== 'all') {
    $where[] = "mo.status = ?";
    $params[] = $statusFilter;
}

if ($dateFrom) {
    $where[] = "mo.actual_move_out_date >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $where[] = "mo.actual_move_out_date <= ?";
    $params[] = $dateTo;
}

// Get move-outs
$moveOuts = $conn->prepare("
    SELECT 
        mo.*,
        mon.notice_date,
        mon.intended_move_out_date,
        l.lease_number,
        l.end_date as lease_end,
        u.unit_number,
        u.unit_type,
        b.name as building_name,
        t.first_name,
        t.last_name,
        t.phone,
        t.email
    FROM re_move_outs mo
    JOIN re_leases l ON l.id = mo.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_move_out_notices mon ON mon.id = mo.move_out_notice_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY mo.actual_move_out_date DESC, mo.created_at DESC
");
$moveOuts->execute($params);
$moveOuts = $moveOuts->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
        COUNT(CASE WHEN status = 'inspection_scheduled' THEN 1 END) as inspection_scheduled,
        COUNT(CASE WHEN status = 'inspection_completed' THEN 1 END) as inspection_completed,
        COUNT(CASE WHEN status = 'deposit_processing' THEN 1 END) as deposit_processing,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed
    FROM re_move_outs
    WHERE company_id = ?
");
$stats->execute([$currentCompanyId]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Move-Out Management';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label"><i class="bi bi-box-arrow-right"></i> Move-Out Management</div>
            <a href="move_out_add.php" class="btn btn-primary" style="background-color: var(--primary); border-color: var(--primary);">
                <i class="bi bi-plus-circle"></i> New Move-Out Notice
            </a>
        </div>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total</h5>
                        <h2 class="mb-0"><?= $stats['total'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center border-warning">
                    <div class="card-body">
                        <h5 class="text-muted">Pending</h5>
                        <h2 class="mb-0 text-warning"><?= $stats['pending'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center border-info">
                    <div class="card-body">
                        <h5 class="text-muted">Inspection Scheduled</h5>
                        <h2 class="mb-0 text-info"><?= $stats['inspection_scheduled'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center border-primary">
                    <div class="card-body">
                        <h5 class="text-muted">Inspection Done</h5>
                        <h2 class="mb-0 text-primary"><?= $stats['inspection_completed'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center border-secondary">
                    <div class="card-body">
                        <h5 class="text-muted">Deposit Processing</h5>
                        <h2 class="mb-0"><?= $stats['deposit_processing'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center border-success">
                    <div class="card-body">
                        <h5 class="text-muted">Completed</h5>
                        <h2 class="mb-0 text-success"><?= $stats['completed'] ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card card-round mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="inspection_scheduled" <?= $statusFilter === 'inspection_scheduled' ? 'selected' : '' ?>>Inspection Scheduled</option>
                            <option value="inspection_completed" <?= $statusFilter === 'inspection_completed' ? 'selected' : '' ?>>Inspection Completed</option>
                            <option value="deposit_processing" <?= $statusFilter === 'deposit_processing' ? 'selected' : '' ?>>Deposit Processing</option>
                            <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
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

        <!-- Move-Outs Table -->
        <div class="card card-round">
            <div class="card-header">
                <h5 class="mb-0">Move-Out Records (<?= count($moveOuts) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Move-Out Date</th>
                                <th>Lease #</th>
                                <th>Building</th>
                                <th>Unit</th>
                                <th>Tenant</th>
                                <th>Status</th>
                                <th>Deposit Status</th>
                                <th>Deposit Refund</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($moveOuts)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted">No move-out records found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($moveOuts as $mo): ?>
                                    <tr>
                                        <td><?= date('M d, Y', strtotime($mo['actual_move_out_date'])) ?></td>
                                        <td><?= h($mo['lease_number']) ?></td>
                                        <td><?= h($mo['building_name']) ?></td>
                                        <td><?= h($mo['unit_number']) ?></td>
                                        <td>
                                            <?= h($mo['first_name'] . ' ' . $mo['last_name']) ?><br>
                                            <small class="text-muted"><?= h($mo['phone']) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $mo['status'] === 'completed' ? 'success' : 
                                                ($mo['status'] === 'inspection_completed' ? 'primary' : 
                                                ($mo['status'] === 'deposit_processing' ? 'secondary' : 
                                                ($mo['status'] === 'inspection_scheduled' ? 'info' : 'warning'))) 
                                            ?>">
                                                <?= ucfirst(str_replace('_', ' ', $mo['status'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($mo['deposit_status']): ?>
                                                <span class="badge bg-<?= 
                                                    $mo['deposit_status'] === 'refunded' ? 'success' : 
                                                    ($mo['deposit_status'] === 'forfeited' ? 'danger' : 
                                                    ($mo['deposit_status'] === 'processing' ? 'info' : 'warning')) 
                                                ?>">
                                                    <?= ucfirst($mo['deposit_status']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($mo['deposit_refund_amount']): ?>
                                                <strong><?= number_format($mo['deposit_refund_amount'], 2) ?> AED</strong>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="move_out_view.php?id=<?= $mo['id'] ?>" class="btn btn-sm btn-primary">
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
