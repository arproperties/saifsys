<?php
/**
 * Real Estate Module - Maintenance Requests
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/maintenance_location_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_MAINTENANCE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = re_maint_require_company($conn);

$isMaintenanceManager = false;
$userRoles = current_user_roles($conn);
foreach ($userRoles as $role) {
    if (stripos($role, 'maintenance') !== false && stripos($role, 'manager') !== false) {
        $isMaintenanceManager = true;
        break;
    }
}
$isOwnerOrAdmin = in_array('Owner', $userRoles, true) || in_array('Admin', $userRoles, true);
$canAccessQueue = $isMaintenanceManager || $isOwnerOrAdmin;

$statusFilter = (string)($_GET['status'] ?? 'all');
$buildingFilter = (int)($_GET['building_id'] ?? 0);
$unitFilter = (int)($_GET['unit_id'] ?? 0);
$priorityFilter = (string)($_GET['priority'] ?? 'all');
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

$allowedStatuses = ['all', 'pending', 'in_progress', 'completed', 'cancelled'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}
$allowedPriorities = ['all', 'low', 'medium', 'high', 'urgent'];
if (!in_array($priorityFilter, $allowedPriorities, true)) {
    $priorityFilter = 'all';
}
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$buildings = re_maint_load_buildings($conn, $currentCompanyId);
$unitsForFilter = [];
if ($buildingFilter > 0) {
    $ust = $conn->prepare("
        SELECT id, unit_number
        FROM re_units
        WHERE company_id = ? AND building_id = ?
        ORDER BY unit_number
    ");
    $ust->execute([$currentCompanyId, $buildingFilter]);
    $unitsForFilter = $ust->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$where = ['mr.company_id = ?'];
$params = [$currentCompanyId];

if ($statusFilter !== 'all') {
    $where[] = 'mr.status = ?';
    $params[] = $statusFilter;
}
if ($priorityFilter !== 'all') {
    $where[] = 'mr.priority = ?';
    $params[] = $priorityFilter;
}
if ($buildingFilter > 0) {
    $where[] = 'COALESCE(mr.building_id, u.building_id) = ?';
    $params[] = $buildingFilter;
}
if ($unitFilter > 0) {
    $where[] = 'mr.unit_id = ?';
    $params[] = $unitFilter;
}
if ($dateFrom !== '') {
    $where[] = 'mr.request_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'mr.request_date <= ?';
    $params[] = $dateTo;
}
if ($q !== '') {
    $where[] = '(mr.description LIKE ? OR mr.category LIKE ? OR mr.notes LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ? OR u.unit_number LIKE ? OR ca.area_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}

$requests = $conn->prepare("
    SELECT mr.*, mr.source,
           u.unit_number,
           ca.area_name AS common_area_name,
           COALESCE(b.name, bu.name) AS building_name,
           t.first_name, t.last_name,
           e.full_name as assigned_employee,
           (SELECT COUNT(*) FROM re_maintenance_photos mp
             WHERE mp.maintenance_request_id = mr.id AND mp.company_id = mr.company_id) AS photo_count
    FROM re_maintenance_requests mr
    LEFT JOIN re_units u ON u.id = mr.unit_id
    LEFT JOIN re_buildings bu ON bu.id = u.building_id
    LEFT JOIN re_buildings b ON b.id = mr.building_id
    LEFT JOIN re_building_common_areas ca ON ca.id = mr.common_area_id
    LEFT JOIN re_tenants t ON t.id = mr.tenant_id
    LEFT JOIN employees e ON e.id = mr.assigned_to
    WHERE " . implode(' AND ', $where) . "
    ORDER BY mr.request_date DESC, mr.priority DESC, mr.id DESC
");
$requests->execute($params);
$requests = $requests->fetchAll(PDO::FETCH_ASSOC);

// Summary counts for current company (unfiltered by list filters except company)
$summary = ['pending' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0];
try {
    $ss = $conn->prepare("
        SELECT status, COUNT(*) AS c
        FROM re_maintenance_requests
        WHERE company_id = ?
        GROUP BY status
    ");
    $ss->execute([$currentCompanyId]);
    foreach ($ss->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $st = (string)$row['status'];
        if (isset($summary[$st])) {
            $summary[$st] = (int)$row['c'];
        }
    }
} catch (Throwable $e) {
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'Maintenance';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <div class="page-header-label mb-0">Maintenance Requests</div>
        <div class="text-muted small">Track unit and common-area work orders for the current company.</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canAccessQueue): ?>
            <a href="maintenance_queue.php" class="btn btn-warning">
                <i class="bi bi-list-check"></i> Queue
            </a>
        <?php endif; ?>
        <a href="maintenance_add.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> New Request
        </a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card card-round h-100 border-0 shadow-sm">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase">Pending</div>
                <div class="fs-4 fw-semibold text-warning"><?= (int)$summary['pending'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-round h-100 border-0 shadow-sm">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase">In Progress</div>
                <div class="fs-4 fw-semibold text-primary"><?= (int)$summary['in_progress'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-round h-100 border-0 shadow-sm">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase">Completed</div>
                <div class="fs-4 fw-semibold text-success"><?= (int)$summary['completed'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-round h-100 border-0 shadow-sm">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase">Showing</div>
                <div class="fs-4 fw-semibold"><?= count($requests) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card card-round mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end" id="maintFilterForm">
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In progress</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Priority</label>
                <select name="priority" class="form-select">
                    <option value="all" <?= $priorityFilter === 'all' ? 'selected' : '' ?>>All priorities</option>
                    <option value="urgent" <?= $priorityFilter === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                    <option value="high" <?= $priorityFilter === 'high' ? 'selected' : '' ?>>High</option>
                    <option value="medium" <?= $priorityFilter === 'medium' ? 'selected' : '' ?>>Medium</option>
                    <option value="low" <?= $priorityFilter === 'low' ? 'selected' : '' ?>>Low</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Building</label>
                <select name="building_id" id="filterBuilding" class="form-select">
                    <option value="0">All buildings</option>
                    <?php foreach ($buildings as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $buildingFilter === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Unit</label>
                <select name="unit_id" id="filterUnit" class="form-select" <?= $buildingFilter <= 0 ? 'disabled' : '' ?>>
                    <option value="0">All units</option>
                    <?php foreach ($unitsForFilter as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $unitFilter === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['unit_number']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">From</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="Description, category, tenant, unit…">
            </div>
            <div class="col-md-8 d-flex gap-2 justify-content-md-end">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Apply filters</button>
                <a href="maintenance.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card card-round">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Location</th>
                        <th>Tenant</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Assigned</th>
                        <th>Cost</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$requests): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-5">
                                No maintenance requests match these filters.
                                <div class="mt-2"><a href="maintenance_add.php" class="btn btn-sm btn-primary">Create request</a></div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($requests as $req): ?>
                        <?php
                        $priorityClass = [
                            'low' => 'secondary',
                            'medium' => 'info',
                            'high' => 'warning',
                            'urgent' => 'danger',
                        ];
                        $statusClass = [
                            'pending' => 'warning',
                            'in_progress' => 'primary',
                            'completed' => 'success',
                            'cancelled' => 'secondary',
                        ];
                        $pClass = $priorityClass[$req['priority']] ?? 'secondary';
                        $sClass = $statusClass[$req['status']] ?? 'secondary';
                        ?>
                        <tr>
                            <td class="text-nowrap"><?= h(date('d M Y', strtotime($req['request_date']))) ?></td>
                            <td>
                                <div class="fw-semibold"><?= h(re_maint_location_label(
                                    $req['building_name'] ?? null,
                                    (string)($req['location_type'] ?? 'unit'),
                                    $req['unit_number'] ?? null,
                                    $req['common_area_name'] ?? null
                                )) ?></div>
                                <?php if (($req['source'] ?? '') === 'ars'): ?>
                                    <span class="badge bg-dark">ARS</span>
                                <?php endif; ?>
                                <?php if ((int)($req['photo_count'] ?? 0) > 0): ?>
                                    <span class="badge bg-light text-dark border"><i class="bi bi-image"></i> <?= (int)$req['photo_count'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= $req['first_name'] ? h($req['first_name'] . ' ' . $req['last_name']) : '<span class="text-muted">—</span>' ?></td>
                            <td><?= h($req['category'] ?: '—') ?></td>
                            <td style="max-width:220px;">
                                <span class="d-inline-block text-truncate" style="max-width:220px;" title="<?= h($req['description']) ?>">
                                    <?= h($req['description']) ?>
                                </span>
                            </td>
                            <td><span class="badge bg-<?= $pClass ?>"><?= h(ucfirst((string)$req['priority'])) ?></span></td>
                            <td><span class="badge bg-<?= $sClass ?>"><?= h(ucfirst(str_replace('_', ' ', (string)$req['status']))) ?></span></td>
                            <td><?= h($req['assigned_employee'] ?: '—') ?></td>
                            <td class="text-nowrap"><?= (float)$req['cost'] > 0 ? number_format((float)$req['cost'], 2) . ' AED' : '—' ?></td>
                            <td class="text-end text-nowrap">
                                <a href="maintenance_view.php?id=<?= (int)$req['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                                <?php if (!in_array($req['status'], ['completed', 'cancelled'], true)): ?>
                                    <a href="maintenance_schedule.php?request_id=<?= (int)$req['id'] ?>" class="btn btn-sm btn-outline-success" title="Schedule work order">
                                        <i class="bi bi-calendar-plus"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function() {
    const building = document.getElementById('filterBuilding');
    const unit = document.getElementById('filterUnit');
    if (!building || !unit) return;

    function loadUnits(selected) {
        const bid = building.value;
        unit.innerHTML = '<option value="0">All units</option>';
        if (!bid || bid === '0') {
            unit.disabled = true;
            unit.value = '0';
            return;
        }
        unit.disabled = true;
        fetch('ajax_get_units.php?building_id=' + encodeURIComponent(bid))
            .then(r => r.json())
            .then(d => {
                (d.units || []).forEach(u => {
                    const o = document.createElement('option');
                    o.value = u.id;
                    o.textContent = u.unit_number;
                    if (String(u.id) === String(selected)) o.selected = true;
                    unit.appendChild(o);
                });
                unit.disabled = false;
            })
            .catch(() => { unit.disabled = false; });
    }

    building.addEventListener('change', function() {
        loadUnits('0');
    });
})();
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
