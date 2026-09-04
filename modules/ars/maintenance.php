<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);

// Filters
$filterUnit     = $_GET['unit_id'] ?? '';
$filterStatus   = $_GET['status'] ?? '';
$filterPriority = $_GET['priority'] ?? '';
$highlightId    = (int)($_GET['highlight'] ?? 0);

// Load ARS-enabled units (scoped)
[$unitWhere, $unitParams] = ars_short_term_units_where($arsCompanyId, 'u');
$unitsStmt = $conn->prepare("
    SELECT u.id, u.unit_number, u.listing_title, b.name AS building_name
    FROM re_units u
    LEFT JOIN re_buildings b ON b.id = u.building_id
    WHERE {$unitWhere}
    ORDER BY b.name, u.unit_number
");
$unitsStmt->execute($unitParams);
$units = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);
$unitIds = array_column($units, 'id');

if (empty($unitIds)) {
    $requests = [];
} else {
    $where = ["mr.unit_id IN (" . implode(',', array_map('intval', $unitIds)) . ")"];
    $params = [];

    if ($filterUnit) {
        $where[] = "mr.unit_id = ?";
        $params[] = (int)$filterUnit;
    }
    if ($filterStatus) {
        $where[] = "mr.status = ?";
        $params[] = $filterStatus;
    }
    if ($filterPriority) {
        $where[] = "mr.priority = ?";
        $params[] = $filterPriority;
    }

    $sql = "
        SELECT mr.*, u.unit_number, b.name AS building_name,
               e.full_name AS assigned_name
        FROM re_maintenance_requests mr
        LEFT JOIN re_units u ON u.id = mr.unit_id
        LEFT JOIN re_buildings b ON b.id = u.building_id
        LEFT JOIN employees e ON e.id = mr.assigned_to
        WHERE " . implode(' AND ', $where) . "
        ORDER BY mr.request_date DESC, mr.id DESC
        LIMIT 200
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// KPI counts
$kpiPending = 0; $kpiInProgress = 0; $kpiCompleted = 0; $kpiArsCreated = 0;
foreach ($requests as $r) {
    if ($r['status'] === 'pending') $kpiPending++;
    elseif ($r['status'] === 'in_progress') $kpiInProgress++;
    elseif ($r['status'] === 'completed') $kpiCompleted++;
    if (($r['source'] ?? '') === 'ars') $kpiArsCreated++;
}

// RE company ID (for creating maintenance requests under RE)
$reCompanyId = 0;
try {
    $stmt = $conn->query("SELECT id FROM companies WHERE business_type IN ('realestate','real_estate') AND is_active = 1 LIMIT 1");
    $reCompanyId = (int)($stmt->fetchColumn() ?: 0);
} catch (PDOException $e) {}
if (!$reCompanyId) {
    $reCompanyId = (int)($conn->query("SELECT MIN(id) FROM companies WHERE is_active = 1")->fetchColumn() ?: 1);
}

$pageTitle = 'Maintenance';
ars_shell_begin([
    'title' => 'Maintenance',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Operations', 'href' => 'housekeeping.php'],
        ['label' => 'Maintenance'],
    ],
    'actions_html' => ars_ui_button('Report issue', [
        'icon' => 'plus',
        'size' => 'sm',
        'attrs' => 'data-bs-toggle="modal" data-bs-target="#reportIssueModal"',
    ]),
    'legacy_bootstrap' => true,
]);
?>


<!-- KPI -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><?= ars_ds_stat_tile('Pending', (string)$kpiPending, ['tone' => 'warn', 'icon' => 'clock']) ?></div>
    <div class="col-6 col-md-3"><?= ars_ds_stat_tile('In progress', (string)$kpiInProgress, ['icon' => 'refresh-cw']) ?></div>
    <div class="col-6 col-md-3"><?= ars_ds_stat_tile('Completed', (string)$kpiCompleted, ['tone' => 'ok', 'icon' => 'check-circle']) ?></div>
    <div class="col-6 col-md-3"><?= ars_ds_stat_tile('ARS reported', (string)$kpiArsCreated, ['icon' => 'wrench']) ?></div>
</div>

<!-- Filters -->
<div class="ars-card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold small">Unit</label>
                <select name="unit_id" class="form-select form-select-sm">
                    <option value="">All Units</option>
                    <?php foreach ($units as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $filterUnit == $u['id'] ? 'selected' : '' ?>><?= h($u['unit_number']) ?> — <?= h($u['building_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold small">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="in_progress" <?= $filterStatus === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold small">Priority</label>
                <select name="priority" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="urgent" <?= $filterPriority === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                    <option value="high" <?= $filterPriority === 'high' ? 'selected' : '' ?>>High</option>
                    <option value="medium" <?= $filterPriority === 'medium' ? 'selected' : '' ?>>Medium</option>
                    <option value="low" <?= $filterPriority === 'low' ? 'selected' : '' ?>>Low</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <button type="submit" class="btn btn-ars btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
            </div>
        </form>
    </div>
</div>

<div id="maintAlert"></div>

<!-- Requests Table -->
<div class="ars-card">
    <div class="table-responsive">
        <table class="table ars-table ars-mobile-cards mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Unit</th>
                    <th>Date</th>
                    <th>Priority</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Assigned</th>
                    <th>Source</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($requests)): ?>
                <tr><td colspan="10" class="text-center text-muted py-4"><i class="bi bi-tools fs-3 d-block mb-2"></i>No maintenance requests found for ARS units.</td></tr>
            <?php endif; ?>
            <?php foreach ($requests as $r): ?>
                <tr class="<?= $highlightId === (int)$r['id'] ? 'table-warning' : '' ?>">
                    <td data-label="#"><?= $r['id'] ?></td>
                    <td data-label="Unit"><strong><?= h($r['unit_number']) ?></strong><br><small><?= h($r['building_name']) ?></small></td>
                    <td data-label="Date"><?= h($r['request_date']) ?></td>
                    <td data-label="Priority"><?= arsMaintenancePriorityBadge($r['priority']) ?></td>
                    <td data-label="Category"><?= h(ucfirst($r['category'] ?: '—')) ?></td>
                    <td data-label="Description"><span class="text-truncate d-inline-block" style="max-width:200px" title="<?= h($r['description']) ?>"><?= h($r['description']) ?></span></td>
                    <td data-label="Status"><?= arsMaintenanceStatusBadge($r['status']) ?></td>
                    <td data-label="Assigned"><?= h($r['assigned_name'] ?: '—') ?></td>
                    <td data-label="Source">
                        <?php if (($r['source'] ?? '') === 'ars'): ?>
                        <span class="badge bg-dark">ARS</span>
                        <?php else: ?>
                        <span class="badge bg-light text-dark">RE</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Actions">
                        <button class="btn btn-sm btn-outline-primary" onclick="viewRequest(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)" title="View Details"><i class="bi bi-eye"></i></button>
                        <?php
                        $appBase = (strpos($_SERVER['PHP_SELF'] ?? '', '/herosysgro') !== false) ? '/herosysgro' : '';
                        ?>
                        <a href="<?= h($appBase) ?>/modules/realestate/maintenance_view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Open in RE"><i class="bi bi-box-arrow-up-right"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- View Detail Modal -->
<div class="modal fade" id="viewRequestModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--ars-primary);color:#fff">
                <h5 class="modal-title"><i class="bi bi-wrench me-2"></i>Maintenance Request <span id="viewReqId"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewReqBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Report Issue Modal -->
<div class="modal fade" id="reportIssueModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--ars-primary);color:#fff">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Report Maintenance Issue</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Unit *</label>
                    <select id="issueUnit" class="form-select" required>
                        <option value="">Select unit…</option>
                        <?php foreach ($units as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= h($u['unit_number']) ?> — <?= h($u['building_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Priority</label>
                        <select id="issuePriority" class="form-select">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Category</label>
                        <select id="issueCategory" class="form-select">
                            <option value="">General</option>
                            <option value="plumbing">Plumbing</option>
                            <option value="electrical">Electrical</option>
                            <option value="ac">AC / HVAC</option>
                            <option value="pest_control">Pest Control</option>
                            <option value="cleaning">Cleaning</option>
                            <option value="appliance">Appliance</option>
                            <option value="structural">Structural</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3 mt-3">
                    <label class="form-label fw-semibold">Description *</label>
                    <textarea id="issueDesc" class="form-control" rows="3" placeholder="Describe the issue…" required></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Cost Estimate (AED)</label>
                    <input type="number" step="0.01" min="0" id="issueCost" class="form-control" value="0">
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="issueBlockUnit">
                    <label class="form-check-label" for="issueBlockUnit">Block unit dates until resolved</label>
                </div>
                <div id="issueBlockDates" class="row g-3" style="display:none">
                    <div class="col-6"><label class="form-label fw-semibold">Block From</label><input type="date" id="issueBlockStart" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-6"><label class="form-label fw-semibold">Block Until</label><input type="date" id="issueBlockEnd" class="form-control" value="<?= date('Y-m-d', strtotime('+7 days')) ?>"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-ars" onclick="reportIssue()"><i class="bi bi-check-lg me-1"></i>Submit</button>
            </div>
        </div>
    </div>
</div>

<?php
$reCompanyIdJs = (int)$reCompanyId;
$pageScripts = <<<JS
<script>
document.getElementById('issueBlockUnit').addEventListener('change', function() {
    document.getElementById('issueBlockDates').style.display = this.checked ? 'flex' : 'none';
});

// Auto-prefill unit if coming from booking view
const prefillUnit = sessionStorage.getItem('prefillMaintUnit');
const prefillBooking = sessionStorage.getItem('prefillMaintBooking');
if (prefillUnit) {
    sessionStorage.removeItem('prefillMaintUnit');
    document.getElementById('issueUnit').value = prefillUnit;
    new bootstrap.Modal(document.getElementById('reportIssueModal')).show();
}
if (prefillBooking) {
    sessionStorage.removeItem('prefillMaintBooking');
    window.__arsPrefillBookingId = prefillBooking;
}

function showMaintAlert(msg, type) {
    document.getElementById('maintAlert').innerHTML = '<div class="alert alert-'+type+' alert-dismissible fade show"><i class="bi bi-'+(type==='success'?'check-circle':'exclamation-triangle')+' me-2"></i>'+msg+'<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

function viewRequest(r) {
    document.getElementById('viewReqId').textContent = '#' + r.id;
    let html = '<div class="row g-3">';
    html += '<div class="col-6"><strong class="text-muted d-block small">Unit</strong>' + (r.unit_number || '') + ' — ' + (r.building_name || '') + '</div>';
    html += '<div class="col-6"><strong class="text-muted d-block small">Date</strong>' + (r.request_date || '') + '</div>';
    html += '<div class="col-6"><strong class="text-muted d-block small">Priority</strong>' + (r.priority || '') + '</div>';
    html += '<div class="col-6"><strong class="text-muted d-block small">Category</strong>' + (r.category || 'General') + '</div>';
    html += '<div class="col-6"><strong class="text-muted d-block small">Status</strong>' + (r.status || '') + '</div>';
    html += '<div class="col-6"><strong class="text-muted d-block small">Assigned To</strong>' + (r.assigned_name || '—') + '</div>';
    html += '<div class="col-6"><strong class="text-muted d-block small">Cost</strong>AED ' + parseFloat(r.cost || 0).toFixed(2) + '</div>';
    html += '<div class="col-6"><strong class="text-muted d-block small">Source</strong>' + (r.source === 'ars' ? '<span class="badge bg-dark">ARS</span>' : '<span class="badge bg-light text-dark">RE</span>') + '</div>';
    html += '<div class="col-12"><strong class="text-muted d-block small">Description</strong><p>' + (r.description || '') + '</p></div>';
    if (r.notes) html += '<div class="col-12"><strong class="text-muted d-block small">Notes</strong><p>' + r.notes + '</p></div>';
    if (r.completed_at) html += '<div class="col-6"><strong class="text-muted d-block small">Completed</strong>' + r.completed_at + '</div>';
    html += '</div>';
    document.getElementById('viewReqBody').innerHTML = html;
    new bootstrap.Modal(document.getElementById('viewRequestModal')).show();
}

function reportIssue() {
    const unitId = document.getElementById('issueUnit').value;
    const desc   = document.getElementById('issueDesc').value.trim();
    if (!unitId || !desc) { showMaintAlert('Unit and description are required.', 'danger'); return; }

    const fd = new FormData();
    fd.append('action', 'create_request');
    fd.append('_csrf', window.ARS_CSRF || '');
    fd.append('unit_id', unitId);
    fd.append('priority', document.getElementById('issuePriority').value);
    fd.append('category', document.getElementById('issueCategory').value);
    fd.append('description', desc);
    fd.append('cost', document.getElementById('issueCost').value);
    if (window.__arsPrefillBookingId) {
        fd.append('booking_id', window.__arsPrefillBookingId);
    }
    if (document.getElementById('issueBlockUnit').checked) {
        fd.append('block_unit', '1');
        fd.append('block_start', document.getElementById('issueBlockStart').value);
        fd.append('block_end', document.getElementById('issueBlockEnd').value);
    }

    fetch('ajax_maintenance_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else showMaintAlert(d.error || 'Failed', 'danger'); })
        .catch(() => showMaintAlert('Network error', 'danger'));
}
</script>
JS;
if (!empty($pageScripts) && !empty($GLOBALS['ars_shell_state'])) {
    $GLOBALS['ars_shell_state']['pageScripts'] = $pageScripts;
}
ars_shell_end();
?>
