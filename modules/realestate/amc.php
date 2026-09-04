<?php
/**
 * Real Estate Module - AMC (Annual Maintenance Contract) Management
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

// Filters
$buildingId = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : 0;
$categoryId = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$status = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Build WHERE clause
$where = ["ac.company_id = :company_id"];
$params = [':company_id' => $currentCompanyId];

if ($buildingId > 0) {
    $where[] = "ac.building_id = :building_id";
    $params[':building_id'] = $buildingId;
}

if ($categoryId > 0) {
    $where[] = "ac.category_id = :category_id";
    $params[':category_id'] = $categoryId;
}

if ($status !== 'all') {
    $where[] = "ac.status = :status";
    $params[':status'] = $status;
}

if ($search) {
    $where[] = "(ac.contract_number LIKE :search OR ac.contract_title LIKE :search OR b.name LIKE :search OR v.vendor_name LIKE :search)";
    $params[':search'] = "%{$search}%";
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// Count total
$countSql = "
    SELECT COUNT(*) 
    FROM re_amc_contracts ac
    LEFT JOIN re_buildings b ON b.id = ac.building_id
    LEFT JOIN re_vendors v ON v.id = ac.vendor_id
    {$whereSql}
";
$stmt = $conn->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));

// Get contracts
$sql = "
    SELECT 
        ac.*,
        b.name AS building_name,
        cat.name AS category_name,
        cat.code AS category_code,
        v.vendor_name,
        DATEDIFF(ac.end_date, CURDATE()) AS days_until_expiry,
        (SELECT COUNT(*) FROM re_amc_visits av WHERE av.contract_id = ac.id AND av.status = 'completed') AS completed_visits,
        (SELECT COUNT(*) FROM re_amc_visits av WHERE av.contract_id = ac.id AND av.status = 'scheduled') AS scheduled_visits,
        (SELECT COUNT(*) FROM re_amc_certificates cert WHERE cert.contract_id = ac.id AND cert.status = 'expired') AS expired_certificates
    FROM re_amc_contracts ac
    LEFT JOIN re_buildings b ON b.id = ac.building_id
    LEFT JOIN re_amc_categories cat ON cat.id = ac.category_id
    LEFT JOIN re_vendors v ON v.id = ac.vendor_id
    {$whereSql}
    ORDER BY ac.end_date ASC, ac.created_at DESC
    LIMIT {$limit} OFFSET {$offset}
";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get buildings for filter
$buildings = $conn->query("SELECT id, name FROM re_buildings WHERE company_id = {$currentCompanyId} AND is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$categories = $conn->query("SELECT id, name FROM re_amc_categories WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(*) AS total_contracts,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_contracts,
        SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired_contracts,
        SUM(CASE WHEN DATEDIFF(end_date, CURDATE()) <= 30 AND status = 'active' THEN 1 ELSE 0 END) AS expiring_soon,
        SUM(total_amount) AS total_value
    FROM re_amc_contracts
    WHERE company_id = ?
");
$stats->execute([$currentCompanyId]);
$statistics = $stats->fetch(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Include layout header
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Page Header -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="page-header-label mb-1">Annual Maintenance Contracts (AMC)</h1>
            <p class="text-muted mb-0">Manage building maintenance contracts and compliance</p>
        </div>
        <div>
            <a href="amc_add.php" class="btn btn-primary me-2">
                <i class="bi bi-plus-circle"></i> New AMC Contract
            </a>
            <div class="btn-group">
                <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-three-dots"></i> More Options
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><h6 class="dropdown-header">AMC Features</h6></li>
                    <li><a class="dropdown-item" href="amc_alerts.php"><i class="bi bi-bell me-2"></i> View Alerts</a></li>
                    <li><a class="dropdown-item" href="amc_alert_settings.php"><i class="bi bi-gear me-2"></i> Alert Settings</a></li>
                    <li><a class="dropdown-item" href="amc_test_cron.php"><i class="bi bi-play-circle me-2"></i> Test Cron Job</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="amc_set_alert_email.php"><i class="bi bi-envelope me-2"></i> Email Settings (Legacy)</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><h6 class="dropdown-header">Quick Actions</h6></li>
                    <li><a class="dropdown-item" href="amc.php?status=expiring_soon"><i class="bi bi-exclamation-triangle me-2"></i> Expiring Soon</a></li>
                    <li><a class="dropdown-item" href="amc.php?status=active"><i class="bi bi-check-circle me-2"></i> Active Contracts</a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-primary bg-opacity-10 rounded p-3">
                                <i class="bi bi-file-earmark-text text-primary fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="text-muted small">Total Contracts</div>
                            <div class="h4 mb-0"><?= number_format($statistics['total_contracts'] ?? 0) ?></div>
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
                            <div class="bg-success bg-opacity-10 rounded p-3">
                                <i class="bi bi-check-circle text-success fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="text-muted small">Active</div>
                            <div class="h4 mb-0"><?= number_format($statistics['active_contracts'] ?? 0) ?></div>
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
                                <i class="bi bi-exclamation-triangle text-warning fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="text-muted small">Expiring Soon</div>
                            <div class="h4 mb-0"><?= number_format($statistics['expiring_soon'] ?? 0) ?></div>
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
                                <i class="bi bi-currency-dollar text-info fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="text-muted small">Total Value</div>
                            <div class="h4 mb-0"><?= number_format($statistics['total_value'] ?? 0, 2) ?> AED</div>
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
                    <label class="form-label">Building</label>
                    <select name="building_id" class="form-select">
                        <option value="">All Buildings</option>
                        <?php foreach ($buildings as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= $buildingId == $b['id'] ? 'selected' : '' ?>>
                                <?= h($b['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Category</label>
                    <select name="category_id" class="form-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $categoryId == $c['id'] ? 'selected' : '' ?>>
                                <?= h($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="expired" <?= $status === 'expired' ? 'selected' : '' ?>>Expired</option>
                        <option value="terminated" <?= $status === 'terminated' ? 'selected' : '' ?>>Terminated</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Contract #, Title..." value="<?= h($search) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Contracts Table -->
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Contract #</th>
                            <th>Building</th>
                            <th>Category</th>
                            <th>Vendor</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Value</th>
                            <th>Status</th>
                            <th>Visits</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($contracts)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                    No AMC contracts found
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($contracts as $c): 
                                $daysLeft = (int)($c['days_until_expiry'] ?? 0);
                                $statusBadge = [
                                    'draft' => 'secondary',
                                    'active' => 'success',
                                    'expired' => 'danger',
                                    'terminated' => 'dark',
                                    'renewed' => 'info',
                                    'cancelled' => 'secondary'
                                ][$c['status']] ?? 'secondary';
                            ?>
                                <tr>
                                    <td>
                                        <strong><?= h($c['contract_number']) ?></strong>
                                        <?php if ($c['contract_title']): ?>
                                            <br><small class="text-muted"><?= h($c['contract_title']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($c['building_name'] ?? 'N/A') ?></td>
                                    <td>
                                        <span class="badge bg-light text-dark">
                                            <?= h($c['category_name'] ?? 'N/A') ?>
                                        </span>
                                    </td>
                                    <td><?= h($c['vendor_name'] ?? 'N/A') ?></td>
                                    <td><?= h($c['start_date']) ?></td>
                                    <td>
                                        <?= h($c['end_date']) ?>
                                        <?php if ($c['status'] === 'active' && $daysLeft <= 30): ?>
                                            <br><small class="text-<?= $daysLeft <= 7 ? 'danger' : 'warning' ?>">
                                                <?= $daysLeft > 0 ? "{$daysLeft} days left" : "Expired" ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= number_format($c['total_amount'], 2) ?> AED</strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $statusBadge ?>"><?= ucfirst($c['status']) ?></span>
                                    </td>
                                    <td>
                                        <small>
                                            <i class="bi bi-check-circle text-success"></i> <?= $c['completed_visits'] ?>
                                            <i class="bi bi-calendar ms-2 text-primary"></i> <?= $c['scheduled_visits'] ?>
                                        </small>
                                        <?php if ($c['expired_certificates'] > 0): ?>
                                            <br><small class="text-danger">
                                                <i class="bi bi-exclamation-triangle"></i> <?= $c['expired_certificates'] ?> expired certs
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="amc_view.php?id=<?= $c['id'] ?>" class="btn btn-outline-primary" title="View">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="amc_add.php?id=<?= $c['id'] ?>" class="btn btn-outline-secondary" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="amc_visits.php?contract_id=<?= $c['id'] ?>" class="btn btn-outline-info" title="Visits">
                                                <i class="bi bi-calendar-check"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination pagination-sm justify-content-center">
                        <?php
                        $queryParams = $_GET;
                        $queryParams['page'] = 1;
                        ?>
                        <li class="page-item <?= $page == 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query($queryParams) ?>">« First</a>
                        </li>
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        for ($p = $start; $p <= $end; $p++):
                            $queryParams['page'] = $p;
                        ?>
                            <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query($queryParams) ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; 
                        $queryParams['page'] = $totalPages;
                        ?>
                        <li class="page-item <?= $page == $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query($queryParams) ?>">Last »</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
