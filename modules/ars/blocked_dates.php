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
$filterUnit = $_GET['unit_id'] ?? '';
$filterStatus = $_GET['status'] ?? 'active';

// Load ARS-enabled units
$units = ars_fetch_short_term_units($conn, $arsCompanyId);

// Build blocked dates query
$where = ["bd.company_id = ?"];
$params = [$arsCompanyId];

if ($filterUnit) {
    $where[] = "bd.unit_id = ?";
    $params[] = (int)$filterUnit;
}

if ($filterStatus === 'active') {
    $where[] = "bd.end_date >= CURDATE()";
} elseif ($filterStatus === 'past') {
    $where[] = "bd.end_date < CURDATE()";
}

$sql = "
    SELECT bd.*, u.unit_number, b.name AS building_name,
           mr.id AS maint_id, mr.status AS maint_status, mr.priority AS maint_priority, mr.category AS maint_category
    FROM ars_blocked_dates bd
    LEFT JOIN re_units u ON u.id = bd.unit_id
    LEFT JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_maintenance_requests mr ON mr.id = bd.maintenance_request_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY bd.start_date DESC, bd.end_date DESC
";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$blockedDates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Blocked Dates';
ars_shell_begin([
    'title' => 'Blocked Dates',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Calendar', 'href' => 'calendar.php'],
        ['label' => 'Blocked Dates'],
    ],
    'actions_html' => ars_ui_button('Block dates', [
        'icon' => 'calendar-x',
        'size' => 'sm',
        'attrs' => 'data-bs-toggle="modal" data-bs-target="#addBlockModal"',
    ]),
    'legacy_bootstrap' => true,
]);
?>


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
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold small">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active / Upcoming</option>
                    <option value="past" <?= $filterStatus === 'past' ? 'selected' : '' ?>>Past</option>
                    <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All</option>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-ars btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
            </div>
        </form>
    </div>
</div>

<div id="blockAlert"></div>

<!-- Blocked Dates Table -->
<div class="ars-card">
    <div class="table-responsive">
        <table class="table ars-table ars-mobile-cards mb-0">
            <thead>
                <tr>
                    <th>Unit</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Days</th>
                    <th>Type</th>
                    <th>Reason</th>
                    <th>Maintenance</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($blockedDates)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4"><i class="bi bi-calendar-check fs-3 d-block mb-2"></i>No blocked dates found.</td></tr>
            <?php endif; ?>
            <?php foreach ($blockedDates as $bd):
                $days = max(1, (int)((strtotime($bd['end_date']) - strtotime($bd['start_date'])) / 86400) + 1);
                $isPast = strtotime($bd['end_date']) < strtotime('today');
            ?>
                <tr class="<?= $isPast ? 'text-muted' : '' ?>">
                    <td data-label="Unit"><strong><?= h($bd['unit_number']) ?></strong><br><small><?= h($bd['building_name']) ?></small></td>
                    <td data-label="Start"><?= h($bd['start_date']) ?></td>
                    <td data-label="End"><?= h($bd['end_date']) ?></td>
                    <td data-label="Days"><?= $days ?></td>
                    <td data-label="Type"><?= arsBlockTypeBadge($bd['block_type'] ?? 'manual') ?></td>
                    <td data-label="Reason"><?= h($bd['reason'] ?: '—') ?></td>
                    <td data-label="Maintenance">
                        <?php if ($bd['maint_id']): ?>
                        <a href="maintenance.php?highlight=<?= $bd['maint_id'] ?>" class="text-decoration-none">
                            #<?= $bd['maint_id'] ?> <?= arsMaintenanceStatusBadge($bd['maint_status']) ?>
                        </a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td data-label="Actions">
                        <?php if (!$isPast): ?>
                        <button class="btn btn-sm btn-outline-primary me-1" onclick="openEditBlock(<?= htmlspecialchars(json_encode($bd), ENT_QUOTES) ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteBlock(<?= $bd['id'] ?>)" title="Delete"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Block Modal -->
<div class="modal fade" id="addBlockModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--ars-primary);color:#fff">
                <h5 class="modal-title"><i class="bi bi-calendar-x me-2"></i>Block Dates</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Unit *</label>
                    <select id="blockUnit" class="form-select" required>
                        <option value="">Select unit…</option>
                        <?php foreach ($units as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= h($u['unit_number']) ?> — <?= h($u['building_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-3">
                    <div class="col-6"><label class="form-label fw-semibold">Start Date *</label><input type="date" id="blockStart" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="col-6"><label class="form-label fw-semibold">End Date *</label><input type="date" id="blockEnd" class="form-control" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" required></div>
                </div>
                <div class="mb-3 mt-3">
                    <label class="form-label fw-semibold">Reason</label>
                    <input type="text" id="blockReason" class="form-control" placeholder="e.g., Owner personal use, renovation…">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-ars" onclick="addBlock()"><i class="bi bi-check-lg me-1"></i>Block</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Block Modal -->
<div class="modal fade" id="editBlockModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--ars-primary);color:#fff">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Blocked Dates</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editBlockId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Unit</label>
                    <select id="editBlockUnit" class="form-select" disabled>
                        <?php foreach ($units as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= h($u['unit_number']) ?> — <?= h($u['building_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-3">
                    <div class="col-6"><label class="form-label fw-semibold">Start Date *</label><input type="date" id="editBlockStart" class="form-control" required></div>
                    <div class="col-6"><label class="form-label fw-semibold">End Date *</label><input type="date" id="editBlockEnd" class="form-control" required></div>
                </div>
                <div class="mb-3 mt-3">
                    <label class="form-label fw-semibold">Reason</label>
                    <input type="text" id="editBlockReason" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-ars" onclick="saveEditBlock()"><i class="bi bi-check-lg me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>

<?php
$prefillUnit  = (int)($_GET['prefill_unit'] ?? 0);
$prefillStart = $_GET['prefill_start'] ?? '';
$pageScripts = <<<JS
<script>
var prefillUnit  = {$prefillUnit};
var prefillStart = '{$prefillStart}';
function showBlockAlert(msg, type) {
    document.getElementById('blockAlert').innerHTML = '<div class="alert alert-'+type+' alert-dismissible fade show"><i class="bi bi-'+(type==='success'?'check-circle':'exclamation-triangle')+' me-2"></i>'+msg+'<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

function ajaxBlock(fd) {
    fd.append('_csrf', window.ARS_CSRF || '');
    return fetch('ajax_blocked_dates_actions.php', { method: 'POST', body: fd }).then(r => r.json());
}

function addBlock() {
    const unit = document.getElementById('blockUnit').value;
    const start = document.getElementById('blockStart').value;
    const end = document.getElementById('blockEnd').value;
    if (!unit || !start || !end) { showBlockAlert('Unit, start, and end date are required.', 'danger'); return; }
    if (end < start) { showBlockAlert('End date must be on or after start date.', 'danger'); return; }
    const fd = new FormData();
    fd.append('action', 'add');
    fd.append('unit_id', unit);
    fd.append('start_date', start);
    fd.append('end_date', end);
    fd.append('reason', document.getElementById('blockReason').value);
    ajaxBlock(fd).then(d => { if (d.success) location.reload(); else showBlockAlert(d.error || 'Failed', 'danger'); }).catch(() => showBlockAlert('Network error', 'danger'));
}

function openEditBlock(bd) {
    document.getElementById('editBlockId').value = bd.id;
    document.getElementById('editBlockUnit').value = bd.unit_id;
    document.getElementById('editBlockStart').value = bd.start_date;
    document.getElementById('editBlockEnd').value = bd.end_date;
    document.getElementById('editBlockReason').value = bd.reason || '';
    new bootstrap.Modal(document.getElementById('editBlockModal')).show();
}

function saveEditBlock() {
    const fd = new FormData();
    fd.append('action', 'edit');
    fd.append('id', document.getElementById('editBlockId').value);
    fd.append('start_date', document.getElementById('editBlockStart').value);
    fd.append('end_date', document.getElementById('editBlockEnd').value);
    fd.append('reason', document.getElementById('editBlockReason').value);
    ajaxBlock(fd).then(d => { if (d.success) location.reload(); else showBlockAlert(d.error || 'Failed', 'danger'); }).catch(() => showBlockAlert('Network error', 'danger'));
}

function deleteBlock(id) {
    if (!confirm('Remove this blocked date range? Bookings will become available for these dates.')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    ajaxBlock(fd).then(d => { if (d.success) location.reload(); else showBlockAlert(d.error || 'Failed', 'danger'); }).catch(() => showBlockAlert('Network error', 'danger'));
}

if (prefillUnit) {
    document.getElementById('blockUnit').value = prefillUnit;
    if (prefillStart) {
        document.getElementById('blockStart').value = prefillStart;
        document.getElementById('blockEnd').value = prefillStart;
    }
    new bootstrap.Modal(document.getElementById('addBlockModal')).show();
}
</script>
JS;
if (!empty($pageScripts) && !empty($GLOBALS['ars_shell_state'])) {
    $GLOBALS['ars_shell_state']['pageScripts'] = $pageScripts;
}
ars_shell_end();
?>
