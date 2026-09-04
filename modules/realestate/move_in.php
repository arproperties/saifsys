<?php
/**
 * Real Estate Module - Move-In Management
 * List and manage tenant move-in processes
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
$where = ["mi.company_id = ?"];
$params = [$currentCompanyId];

if ($statusFilter !== 'all') {
    $where[] = "mi.status = ?";
    $params[] = $statusFilter;
}

if ($dateFrom) {
    $where[] = "mi.move_in_date >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $where[] = "mi.move_in_date <= ?";
    $params[] = $dateTo;
}

// Get move-ins
$moveIns = $conn->prepare("
    SELECT 
        mi.*,
        l.lease_number,
        l.start_date as lease_start,
        l.monthly_rent,
        u.unit_number,
        u.unit_type,
        b.name as building_name,
        t.first_name,
        t.last_name,
        t.phone,
        t.email,
        (SELECT COUNT(*) FROM re_move_in_checklist_items WHERE move_in_id = mi.id AND is_completed = 1) as completed_items,
        (SELECT COUNT(*) FROM re_move_in_checklist_items WHERE move_in_id = mi.id) as total_items
    FROM re_move_ins mi
    JOIN re_leases l ON l.id = mi.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY mi.move_in_date DESC, mi.created_at DESC
");
$moveIns->execute($params);
$moveIns = $moveIns->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
        COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed
    FROM re_move_ins
    WHERE company_id = ?
");
$stats->execute([$currentCompanyId]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Move-In Management';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-box-arrow-in-right"></i> Move-In Management</h1>
            <a href="move_in_add.php" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> New Move-In
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
                <div class="card text-center border-warning">
                    <div class="card-body">
                        <h5 class="text-muted">Pending</h5>
                        <h2 class="mb-0 text-warning"><?= $stats['pending'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-info">
                    <div class="card-body">
                        <h5 class="text-muted">In Progress</h5>
                        <h2 class="mb-0 text-info"><?= $stats['in_progress'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-success">
                    <div class="card-body">
                        <h5 class="text-muted">Completed</h5>
                        <h2 class="mb-0 text-success"><?= $stats['completed'] ?></h2>
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
                            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
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

        <!-- Move-Ins Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Move-In Records (<?= count($moveIns) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Move-In Date</th>
                                <th>Lease #</th>
                                <th>Building</th>
                                <th>Unit</th>
                                <th>Tenant</th>
                                <th>Status</th>
                                <th>Checklist</th>
                                <th>Progress</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($moveIns)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted">No move-in records found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($moveIns as $mi): ?>
                                    <tr>
                                        <td><?= date('M d, Y', strtotime($mi['move_in_date'])) ?></td>
                                        <td><?= h($mi['lease_number']) ?></td>
                                        <td><?= h($mi['building_name']) ?></td>
                                        <td><?= h($mi['unit_number']) ?></td>
                                        <td>
                                            <?= h($mi['first_name'] . ' ' . $mi['last_name']) ?><br>
                                            <small class="text-muted"><?= h($mi['phone']) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $mi['status'] === 'completed' ? 'success' : ($mi['status'] === 'in_progress' ? 'info' : ($mi['status'] === 'pending' ? 'warning' : 'secondary')) ?>">
                                                <?= ucfirst(str_replace('_', ' ', $mi['status'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $total = (int)$mi['total_items'];
                                            $completed = (int)$mi['completed_items'];
                                            $percent = $total > 0 ? round(($completed / $total) * 100) : 0;
                                            ?>
                                            <small><?= $completed ?>/<?= $total ?> items</small>
                                        </td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar <?= $percent == 100 ? 'bg-success' : ($percent >= 50 ? 'bg-info' : 'bg-warning') ?>" 
                                                     role="progressbar" style="width: <?= $percent ?>%">
                                                    <?= $percent ?>%
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <a href="move_in_view.php?id=<?= $mi['id'] ?>" class="btn btn-sm btn-primary">
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
