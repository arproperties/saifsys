<?php
/**
 * Real Estate Module - Mark as Moved In, in bulk
 * Active leases that need Mark as Moved In (existing tenancies and renewals
 * without a move-in): select one, several or all and record them at once.
 * Same rules as Mark as Moved In on the lease page. Expired and terminated
 * leases are never listed. Owner/Admin only.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/lease_lifecycle_guard.php';
require_once __DIR__ . '/includes/move_in_historical_helper.php';
require_once __DIR__ . '/includes/move_in_chain_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$currentCompanyId = (int)(current_company_id($conn) ?: 1);
$userId = (int)(current_user_id() ?: 0);
$canManage = has_role('Owner', $conn) || has_role('Admin', $conn);
$ready = re_move_in_historical_ready($conn);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * Running leases (not draft, expired or terminated) that need Mark as Moved In: no move-in of their own, none
 * carried over from an earlier lease, none open, and either a renewal (the
 * move-in is recorded on the first lease), an existing tenancy (started before
 * the move-in process went live) or renewed already. New leases use the normal
 * Move-In and are not listed.
 */
function re_mark_bulk_candidates(PDO $conn, int $companyId): array
{
    $st = $conn->prepare("
        SELECT l.id, l.lease_number, l.status, l.start_date, l.end_date, l.move_in_date, l.unit_id,
               u.unit_number, b.name AS building_name,
               TRIM(CONCAT(COALESCE(t.first_name, ''), ' ', COALESCE(t.last_name, ''))) AS tenant_name
        FROM re_leases l
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        JOIN re_tenants t ON t.id = l.tenant_id
        WHERE l.company_id = ? AND l.status NOT IN ('draft', 'expired', 'terminated') AND " . re_lease_not_deleted_sql($conn, 'l') . "
          AND NOT EXISTS (SELECT 1 FROM re_move_ins mi WHERE mi.lease_id = l.id AND mi.status <> 'cancelled')
        ORDER BY b.name, u.unit_number, l.start_date
    ");
    $st->execute([$companyId]);
    $carried = re_move_in_carried_over_map($conn, $companyId);
    $firstMap = re_move_in_first_lease_map($conn, $companyId);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $l) {
        $id = (int)$l['id'];
        if (isset($carried[$id])) {
            continue;
        }
        $first = $firstMap[$id] ?? null;
        if ($first && re_move_in_open_record($conn, (int)$first['id'])) {
            continue; // the first lease has a move-in being done
        }
        $isOld = re_lease_is_old($conn, $l);
        if (!$first && !$isOld && !re_move_out_superseded_by($conn, $companyId, $id)) {
            continue; // new lease: normal Move-In
        }
        $target = $first ?: $l;
        $date = re_move_in_historical_default_date($target);
        if ($date > date('Y-m-d')) {
            $date = date('Y-m-d');
        }
        $l['kind'] = $first ? 'renewal' : 'existing';
        $l['target_id'] = (int)$target['id'];
        $l['target_number'] = (string)($target['lease_number'] ?: ('L-' . $target['id']));
        $l['default_date'] = $date;
        $out[$id] = $l;
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!$canManage) {
        $_SESSION['error'] = 'Only Owner or Admin can mark leases as moved in.';
        header('Location: move_in_mark_bulk.php');
        exit;
    }
    $ids = array_values(array_unique(array_map('intval', (array)($_POST['lease_ids'] ?? []))));
    $reason = trim((string)($_POST['reason'] ?? ''));
    $dates = (array)($_POST['date'] ?? []);
    if (!$ids) {
        $_SESSION['error'] = 'Select at least one lease.';
    } elseif ($reason === '') {
        $_SESSION['error'] = 'Please enter a reason.';
    } else {
        // Checked again: only leases that are still eligible now
        $cands = re_mark_bulk_candidates($conn, $currentCompanyId);
        $done = [];
        $failed = [];
        $doneTargets = [];
        foreach ($ids as $id) {
            $c = $cands[$id] ?? null;
            $label = $c ? ($c['lease_number'] ?: ('L-' . $id)) : ('#' . $id);
            if (!$c) {
                $failed[] = $label . ': no longer needs Mark as Moved In';
                continue;
            }
            $tid = (int)$c['target_id'];
            if (!isset($doneTargets[$tid])) {
                $res = re_move_in_mark_historical($conn, $currentCompanyId, $userId, $tid, trim((string)($dates[$id] ?? $c['default_date'])), $reason);
                if (empty($res['ok'])) {
                    $failed[] = $label . ': ' . ($res['error'] ?? 'could not be marked');
                    continue;
                }
                $doneTargets[$tid] = true;
            }
            if ($c['kind'] === 'renewal') {
                re_lease_units_set_occupied($conn, $currentCompanyId, $id); // the renewal is the running lease
            }
            $done[] = $label;
        }
        if ($done) {
            $_SESSION['success'] = count($done) . ' lease' . (count($done) === 1 ? '' : 's') . ' marked as moved in: ' . implode(', ', $done) . '. Move-Out is now available for them.';
        }
        if ($failed) {
            $_SESSION['error'] = 'Not marked: ' . implode('; ', $failed);
        }
    }
    header('Location: move_in_mark_bulk.php');
    exit;
}

$cands = $ready ? re_mark_bulk_candidates($conn, $currentCompanyId) : [];
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
$today = date('Y-m-d');
$fStatuses = array_count_values(array_column($cands, 'status'));
$fBuildings = array_count_values(array_column($cands, 'building_name'));
ksort($fBuildings, SORT_NATURAL | SORT_FLAG_CASE);
$statusTone = static fn(string $st): string => $st === 'active' ? 'success' : ($st === 'has_legal_case' ? 'danger' : 'secondary');

$pageTitle = 'Mark as Moved In';
require_once __DIR__ . '/includes/re_move_ui.php';
$pageStyles = ($pageStyles ?? '') . re_move_ui_css() . '
<style>
.mmb-table td, .mmb-table th { vertical-align: middle; }
.mmb-table tr.is-sel td { background: rgba(13,110,253,.05); }
.mmb-table input[type=date] { max-width: 160px; }
#mmbForm .table-responsive { margin: 0; }
.mmb-bar { background: #fff; border-bottom: 1px solid var(--bs-border-color); padding: .75rem 1rem; }
</style>';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<div class="rm-page">
    <a href="move_in.php" class="rm-back"><i class="bi bi-arrow-left"></i> Move-In Management</a>
    <div class="rm-hero">
        <div>
            <h1><i class="bi bi-check2-square"></i>Mark as Moved In</h1>
            <div class="rm-sub">Running leases whose tenant already lives in the unit but has no move-in record: existing tenancies and renewals. Select them and record the move-in without the checklist.</div>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
    <?php if (!$ready): ?>
        <div class="alert alert-warning">Run <code>migrations/add_re_move_ins_is_historical.sql</code> first.</div>
    <?php elseif (!$canManage): ?>
        <div class="alert alert-info">View only. Owner or Admin can mark leases as moved in.</div>
    <?php endif; ?>

    <form method="post" id="mmbForm" class="card">
        <?= csrf_field() ?>
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h5 class="mb-0"><i class="bi bi-list-check me-1"></i> Leases to mark <span class="rm-pill rm-pill-secondary ms-1"><?= count($cands) ?></span></h5>
            <div class="d-flex flex-wrap flex-lg-nowrap gap-2 ms-auto">
                <select id="mmbStatus" class="form-select form-select-sm" style="width:auto" aria-label="Filter by lease status">
                    <option value="">All statuses (<?= count($cands) ?>)</option>
                    <?php foreach ($fStatuses as $st => $cn): ?><option value="<?= h($st) ?>"><?= h(re_lease_status_label((string)$st)) ?> (<?= $cn ?>)</option><?php endforeach; ?>
                </select>
                <select id="mmbBuilding" class="form-select form-select-sm" style="width:auto;max-width:240px" aria-label="Filter by building">
                    <option value="">All buildings</option>
                    <?php foreach ($fBuildings as $bn => $cn): ?><option value="<?= h($bn) ?>"><?= h($bn) ?> (<?= $cn ?>)</option><?php endforeach; ?>
                </select>
                <input type="search" id="mmbFind" class="form-control form-control-sm" style="width:240px" placeholder="Find lease, tenant, unit or building">
            </div>
        </div>
            <?php if ($canManage && $cands): ?>
            <div class="mmb-bar d-flex flex-wrap align-items-end gap-2">
                <div class="flex-grow-1" style="min-width:260px">
                    <label class="form-label small fw-semibold mb-1" for="mmbReason">Reason (saved on every selected lease) *</label>
                    <input type="text" id="mmbReason" name="reason" class="form-control form-control-sm" maxlength="500" placeholder="e.g. Tenant moved in before the Move-In module was used" value="Tenant moved in before the Move-In module was used">
                </div>
                <button type="submit" class="btn btn-primary" id="mmbSubmit" disabled><i class="bi bi-check2-square me-1"></i> Mark <span id="mmbCount">0</span> as Moved In</button>
            </div>
            <?php endif; ?>
        <div class="card-body p-0">
            <?php if (!$cands): ?>
                <div class="text-center text-muted py-5"><i class="bi bi-check2-circle fs-3 d-block mb-2"></i>Every active lease has its move-in recorded.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table mmb-table mb-0">
                    <thead><tr>
                        <th style="width:42px"><input type="checkbox" class="form-check-input" id="mmbAll" title="Select all" <?= $canManage ? '' : 'disabled' ?>></th>
                        <th>Lease</th><th>Status</th><th>Tenant</th><th>Unit</th><th>Lease period</th><th>Type</th><th>Move-in date</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($cands as $c): ?>
                        <tr data-status="<?= h($c['status']) ?>" data-building="<?= h($c['building_name']) ?>" data-find="<?= h(mb_strtolower(($c['lease_number'] ?? '') . ' ' . $c['tenant_name'] . ' ' . $c['unit_number'] . ' ' . $c['building_name'])) ?>">
                            <td><input type="checkbox" class="form-check-input mmb-cb" name="lease_ids[]" value="<?= (int)$c['id'] ?>" <?= $canManage ? '' : 'disabled' ?>></td>
                            <td><a href="lease_view.php?id=<?= (int)$c['id'] ?>" class="fw-semibold text-decoration-none"><?= h($c['lease_number'] ?: ('L-' . $c['id'])) ?></a></td>
                            <td><span class="rm-pill rm-pill-<?= $statusTone((string)$c['status']) ?>"><?= h(re_lease_status_label((string)$c['status'])) ?></span></td>
                            <td><?= h($c['tenant_name'] ?: '—') ?></td>
                            <td><span class="fw-semibold"><?= h($c['unit_number']) ?></span> <span class="small text-muted">· <?= h($c['building_name']) ?></span></td>
                            <td class="text-nowrap small"><?= h($c['start_date'] ? date('d M Y', strtotime($c['start_date'])) : '—') ?> → <?= h($c['end_date'] ? date('d M Y', strtotime($c['end_date'])) : '—') ?></td>
                            <td class="small">
                                <?php if ($c['kind'] === 'renewal'): ?>
                                    <span class="rm-pill rm-pill-info">Renewal</span><div class="text-muted">Recorded on first lease <?= h($c['target_number']) ?></div>
                                <?php else: ?>
                                    <span class="rm-pill rm-pill-secondary">Existing tenancy</span>
                                <?php endif; ?>
                            </td>
                            <td><input type="date" class="form-control form-control-sm" name="date[<?= (int)$c['id'] ?>]" value="<?= h($c['default_date']) ?>" max="<?= $today ?>" <?= $canManage ? '' : 'disabled' ?>></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </form>
    <p class="small text-muted mt-2">The move-in date is taken from the lease's move-in date, or its start date (for a renewal, from the first lease). Change it per lease if needed. Expired and terminated leases are not listed.</p>
</div>
<script>
(function () {
    var all = document.getElementById('mmbAll'), find = document.getElementById('mmbFind'), btn = document.getElementById('mmbSubmit'), cnt = document.getElementById('mmbCount');
    var boxes = function (visibleOnly) { return [].slice.call(document.querySelectorAll('.mmb-cb')).filter(function (b) { return !visibleOnly || b.closest('tr').style.display !== 'none'; }); };
    function sync() {
        var sel = boxes(false).filter(function (b) { return b.checked; });
        boxes(false).forEach(function (b) { b.closest('tr').classList.toggle('is-sel', b.checked); });
        if (cnt) cnt.textContent = sel.length;
        if (btn) btn.disabled = sel.length === 0;
        var vis = boxes(true);
        if (all) { all.checked = vis.length > 0 && vis.every(function (b) { return b.checked; }); all.indeterminate = !all.checked && vis.some(function (b) { return b.checked; }); }
    }
    document.addEventListener('change', function (e) { if (e.target.classList && e.target.classList.contains('mmb-cb')) sync(); });
    if (all) all.addEventListener('change', function () { boxes(true).forEach(function (b) { b.checked = all.checked; }); sync(); });
    // Search, status and building filters; a row that gets hidden is unselected
    var fSt = document.getElementById('mmbStatus'), fBl = document.getElementById('mmbBuilding');
    function applyFilters() {
        var q = find ? find.value.trim().toLowerCase() : '', st = fSt ? fSt.value : '', bl = fBl ? fBl.value : '';
        document.querySelectorAll('.mmb-table tbody tr').forEach(function (r) {
            var show = (!q || r.dataset.find.indexOf(q) !== -1) && (!st || r.dataset.status === st) && (!bl || r.dataset.building === bl);
            r.style.display = show ? '' : 'none';
            if (!show) { var cb = r.querySelector('.mmb-cb'); if (cb) cb.checked = false; }
        });
        sync();
    }
    if (find) find.addEventListener('input', applyFilters);
    if (fSt) fSt.addEventListener('change', applyFilters);
    if (fBl) fBl.addEventListener('change', applyFilters);
    var form = document.getElementById('mmbForm');
    if (form) form.addEventListener('submit', function (e) {
        var n = boxes(false).filter(function (b) { return b.checked; }).length;
        if (!document.getElementById('mmbReason').value.trim()) { e.preventDefault(); alert('Please enter a reason.'); return; }
        if (!confirm('Mark ' + n + ' lease' + (n === 1 ? '' : 's') + ' as moved in? Each gets a completed move-in record (no checklist) and Move-Out becomes available.')) { e.preventDefault(); return; }
        btn.disabled = true;
    });
    sync();
})();
</script>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
