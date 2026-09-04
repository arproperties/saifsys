<?php
/**
 * Real Estate Module - Legal Cases (list)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/legal_helper.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Filters
$statusFilter   = $_GET['status'] ?? 'all';
$typeFilter     = $_GET['case_type'] ?? 'all';
$sourceFilter   = $_GET['case_source'] ?? 'all';
$priorityFilter = $_GET['priority'] ?? 'all';
$buildingFilter = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : 0;
$assignedFilter = !empty($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : 0;
$deadlineFilter = $_GET['deadline'] ?? 'all'; // all | overdue | due_soon
$search         = trim($_GET['search'] ?? '');

$where = ["c.company_id = ?", "c.deleted_at IS NULL"];
$params = [$currentCompanyId];

if ($statusFilter !== 'all' && array_key_exists($statusFilter, legal_case_statuses())) {
    if ($statusFilter === 'active') {
        // not used; placeholder
    }
    $where[] = "c.status = ?";
    $params[] = $statusFilter;
}
if ($typeFilter !== 'all' && array_key_exists($typeFilter, legal_case_types())) {
    $where[] = "c.case_type = ?";
    $params[] = $typeFilter;
}
if ($sourceFilter !== 'all' && array_key_exists($sourceFilter, legal_case_sources())) {
    $where[] = "c.case_source = ?";
    $params[] = $sourceFilter;
}
if ($priorityFilter !== 'all' && array_key_exists($priorityFilter, legal_priorities())) {
    $where[] = "c.priority = ?";
    $params[] = $priorityFilter;
}
if ($buildingFilter > 0) {
    $where[] = "c.building_id = ?";
    $params[] = $buildingFilter;
}
if ($assignedFilter > 0) {
    $where[] = "c.assigned_to = ?";
    $params[] = $assignedFilter;
}
if ($deadlineFilter === 'overdue') {
    $where[] = "c.deadline_date IS NOT NULL AND c.deadline_date < CURDATE() AND c.status NOT IN ('settled','closed','withdrawn')";
} elseif ($deadlineFilter === 'due_soon') {
    $where[] = "c.deadline_date IS NOT NULL AND c.deadline_date >= CURDATE() AND c.deadline_date <= DATE_ADD(CURDATE(), INTERVAL c.reminder_days_before DAY) AND c.status NOT IN ('settled','closed','withdrawn')";
}
if ($search !== '') {
    $where[] = "(c.case_number LIKE ? OR c.title LIKE ? OR l.lease_number LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ? OR t.company_name LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

$sql = "
    SELECT c.*,
           b.name AS building_name,
           u.unit_number,
           l.lease_number,
           t.first_name, t.last_name, t.company_name, t.tenant_type,
           o.name AS owner_name,
           COALESCE(NULLIF(usr.fullname,''), usr.username) AS assigned_name
    FROM re_legal_cases c
    LEFT JOIN re_buildings b ON b.id = c.building_id
    LEFT JOIN re_units u ON u.id = c.unit_id
    LEFT JOIN re_leases l ON l.id = c.lease_id
    LEFT JOIN re_tenants t ON t.id = c.tenant_id
    LEFT JOIN re_property_owners o ON o.id = c.owner_id
    LEFT JOIN user usr ON usr.id = c.assigned_to
    WHERE " . implode(' AND ', $where) . "
    ORDER BY
        (c.deadline_date IS NOT NULL AND c.deadline_date < CURDATE() AND c.status NOT IN ('settled','closed','withdrawn')) DESC,
        FIELD(c.priority,'urgent','high','medium','low'),
        c.created_at DESC
";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary stats (company-wide, not filtered, except deleted)
$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status NOT IN ('settled','closed','withdrawn') THEN 1 ELSE 0 END) AS open_cases,
        SUM(CASE WHEN deadline_date IS NOT NULL AND deadline_date < CURDATE() AND status NOT IN ('settled','closed','withdrawn') THEN 1 ELSE 0 END) AS overdue,
        SUM(CASE WHEN deadline_date IS NOT NULL AND deadline_date >= CURDATE() AND deadline_date <= DATE_ADD(CURDATE(), INTERVAL reminder_days_before DAY) AND status NOT IN ('settled','closed','withdrawn') THEN 1 ELSE 0 END) AS due_soon,
        COALESCE(SUM(CASE WHEN status NOT IN ('settled','closed','withdrawn') THEN claim_amount ELSE 0 END),0) AS open_claim,
        COALESCE(SUM(recovered_amount),0) AS recovered
    FROM re_legal_cases
    WHERE company_id = ? AND deleted_at IS NULL
");
$statsStmt->execute([$currentCompanyId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$buildings = legal_fetch_buildings($conn, $currentCompanyId);
$assignableUsers = legal_fetch_assignable_users($conn, $currentCompanyId);

$pageTitle = 'Legal Cases';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-briefcase"></i> Legal Cases</h1>
            <div class="d-flex gap-2">
                <a href="legal_dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a href="legal_case_add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> New Case</a>
            </div>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

        <!-- Summary -->
        <div class="row g-3 mb-4">
            <div class="col-md-2 col-sm-6">
                <div class="card text-center"><div class="card-body">
                    <h6 class="text-muted">Total</h6><h3 class="mb-0"><?= (int)($stats['total'] ?? 0) ?></h3>
                </div></div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card text-center border-primary"><div class="card-body">
                    <h6 class="text-muted">Open</h6><h3 class="mb-0 text-primary"><?= (int)($stats['open_cases'] ?? 0) ?></h3>
                </div></div>
            </div>
            <a class="col-md-2 col-sm-6 text-decoration-none" href="legal_cases.php?deadline=overdue">
                <div class="card text-center border-danger h-100"><div class="card-body">
                    <h6 class="text-muted">Overdue</h6><h3 class="mb-0 text-danger"><?= (int)($stats['overdue'] ?? 0) ?></h3>
                </div></div>
            </a>
            <a class="col-md-2 col-sm-6 text-decoration-none" href="legal_cases.php?deadline=due_soon">
                <div class="card text-center border-warning h-100"><div class="card-body">
                    <h6 class="text-muted">Due Soon</h6><h3 class="mb-0 text-warning"><?= (int)($stats['due_soon'] ?? 0) ?></h3>
                </div></div>
            </a>
            <div class="col-md-2 col-sm-6">
                <div class="card text-center"><div class="card-body">
                    <h6 class="text-muted">Open Claim</h6><h5 class="mb-0"><?= number_format((float)($stats['open_claim'] ?? 0), 0) ?></h5>
                </div></div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card text-center border-success"><div class="card-body">
                    <h6 class="text-muted">Recovered</h6><h5 class="mb-0 text-success"><?= number_format((float)($stats['recovered'] ?? 0), 0) ?></h5>
                </div></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4"><div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" value="<?= h($search) ?>" placeholder="Case #, title, tenant, lease">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="all">All</option>
                        <?php foreach (legal_case_statuses() as $k => $v): ?>
                            <option value="<?= h($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Type</label>
                    <select name="case_type" class="form-select form-select-sm">
                        <option value="all">All</option>
                        <?php foreach (legal_case_types() as $k => $v): ?>
                            <option value="<?= h($k) ?>" <?= $typeFilter === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Source</label>
                    <select name="case_source" class="form-select form-select-sm">
                        <option value="all">All</option>
                        <?php foreach (legal_case_sources() as $k => $v): ?>
                            <option value="<?= h($k) ?>" <?= $sourceFilter === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label">Priority</label>
                    <select name="priority" class="form-select form-select-sm">
                        <option value="all">All</option>
                        <?php foreach (legal_priorities() as $k => $v): ?>
                            <option value="<?= h($k) ?>" <?= $priorityFilter === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Deadline</label>
                    <select name="deadline" class="form-select form-select-sm">
                        <option value="all" <?= $deadlineFilter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="overdue" <?= $deadlineFilter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                        <option value="due_soon" <?= $deadlineFilter === 'due_soon' ? 'selected' : '' ?>>Due soon</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Building</label>
                    <select name="building_id" class="form-select form-select-sm">
                        <option value="">All Buildings</option>
                        <?php foreach ($buildings as $b): ?>
                            <option value="<?= (int)$b['id'] ?>" <?= $buildingFilter === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Assigned To</label>
                    <select name="assigned_to" class="form-select form-select-sm">
                        <option value="">Anyone</option>
                        <?php foreach ($assignableUsers as $usr): ?>
                            <option value="<?= (int)$usr['id'] ?>" <?= $assignedFilter === (int)$usr['id'] ? 'selected' : '' ?>><?= h($usr['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Filter</button>
                    <a href="legal_cases.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div></div>

        <!-- Table -->
        <div class="card"><div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Case #</th>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Property / Tenant</th>
                            <th class="text-end">Claim</th>
                            <th>Deadline</th>
                            <th>Assigned</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cases)): ?>
                            <tr><td colspan="10" class="text-center text-muted py-4">No legal cases found.</td></tr>
                        <?php else: foreach ($cases as $c):
                            $tName = legal_tenant_name($c);
                            $deadlineBadge = legal_deadline_badge($c['deadline_date'], (int)$c['reminder_days_before'], $c['status']);
                        ?>
                            <tr>
                                <td><strong><?= h($c['case_number']) ?></strong></td>
                                <td><?= h($c['title']) ?><br><small class="text-muted"><?= h(legal_case_sources()[$c['case_source']] ?? $c['case_source']) ?></small></td>
                                <td><span class="badge bg-light text-dark border"><?= h(legal_case_types()[$c['case_type']] ?? $c['case_type']) ?></span></td>
                                <td><span class="badge bg-<?= legal_status_color($c['status']) ?>"><?= h(legal_case_statuses()[$c['status']] ?? $c['status']) ?></span></td>
                                <td><span class="badge bg-<?= legal_priority_color($c['priority']) ?>"><?= h(legal_priorities()[$c['priority']] ?? $c['priority']) ?></span></td>
                                <td>
                                    <?php if ($c['building_name']): ?>
                                        <small><?= h($c['building_name']) ?><?= $c['unit_number'] ? ' - ' . h($c['unit_number']) : '' ?></small><br>
                                    <?php endif; ?>
                                    <?php if ($tName): ?><small class="text-muted"><i class="bi bi-person"></i> <?= h($tName) ?></small><?php endif; ?>
                                </td>
                                <td class="text-end"><?= number_format((float)$c['claim_amount'], 2) ?></td>
                                <td>
                                    <?= $c['deadline_date'] ? h(date('M d, Y', strtotime($c['deadline_date']))) : '<span class="text-muted">-</span>' ?>
                                    <?= $deadlineBadge ? '<br>' . $deadlineBadge : '' ?>
                                </td>
                                <td><small><?= h($c['assigned_name'] ?: '-') ?></small></td>
                                <td>
                                    <a href="legal_case_view.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-primary"><i class="bi bi-eye"></i> View</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div></div>

<?php require_once __DIR__ . '/includes/legal_layout_footer.php'; ?>
