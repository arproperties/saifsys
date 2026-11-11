<?php
/**
 * Legal - Issues: log a new issue against a unit (tenant and contract filled from the unit).
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/legal_helper.php';
require_once __DIR__ . '/includes/legal_issues_helper.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = (int)current_user_id();
$companies = legal_issue_companies($conn, $userId, $currentCompanyId);
$ready = legal_issues_ready($conn);
$error = '';

$pf = fn(string $k, $d = '') => $_POST[$k] ?? $_GET[$k] ?? $d;

// Pre-fill from a contract link (?lease_id=) - resolves company, building and unit.
$pre = ['company_id' => (int)$pf('company_id', $currentCompanyId), 'building_id' => (int)$pf('building_id', 0),
        'unit_id' => (int)$pf('unit_id', 0), 'lease_id' => (int)$pf('lease_id', 0)];
if ($pre['lease_id'] && !$pre['unit_id']) {
    $st = $conn->prepare("SELECT l.company_id, l.unit_id, u.building_id FROM re_leases l JOIN re_units u ON u.id = l.unit_id WHERE l.id = ?");
    $st->execute([$pre['lease_id']]);
    if ($x = $st->fetch(PDO::FETCH_ASSOC)) {
        $pre['company_id'] = (int)$x['company_id'];
        $pre['unit_id'] = (int)$x['unit_id'];
        $pre['building_id'] = (int)$x['building_id'];
    }
}
if (!isset($companies[$pre['company_id']])) $pre['company_id'] = $currentCompanyId;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    csrf_verify();
    $title    = trim((string)($_POST['title'] ?? ''));
    $type     = (string)($_POST['issue_type'] ?? 'other');
    $priority = (string)($_POST['priority'] ?? 'medium');
    $date     = (string)($_POST['issue_date'] ?? date('Y-m-d'));
    $company  = (int)($_POST['company_id'] ?? 0);
    $unitId   = (int)($_POST['unit_id'] ?? 0);
    $leaseId  = (int)($_POST['lease_id'] ?? 0);
    $desc     = trim((string)($_POST['description'] ?? ''));

    if (!array_key_exists($type, legal_issue_types())) $type = 'other';
    if (!array_key_exists($priority, legal_issue_priorities())) $priority = 'medium';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

    $unit = null;
    if ($unitId) {
        $st = $conn->prepare("SELECT u.id, u.building_id FROM re_units u JOIN re_buildings b ON b.id = u.building_id WHERE u.id = ? AND b.company_id = ?");
        $st->execute([$unitId, $company]);
        $unit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $tenantId = null;
    if ($leaseId) {
        $st = $conn->prepare("SELECT tenant_id FROM re_leases WHERE id = ? AND unit_id = ? AND company_id = ? AND deleted_at IS NULL");
        $st->execute([$leaseId, $unitId, $company]);
        $tenantId = $st->fetchColumn();
        if ($tenantId === false) { $leaseId = 0; $tenantId = null; }
    }

    if ($title === '') $error = 'Please enter a title.';
    elseif (!isset($companies[$company]) || !legal_issue_can_company($conn, $company, $currentCompanyId)) $error = 'Please choose a company you have access to.';
    elseif (!$unit) $error = 'Please choose the building and unit.';

    if ($error === '') {
        try {
            $conn->beginTransaction();
            $num = legal_issue_next_number($conn, $company);
            $conn->prepare("INSERT INTO re_legal_issues
                    (company_id, issue_number, title, issue_type, priority, status, issue_date, building_id, unit_id, lease_id, tenant_id, description, created_by)
                    VALUES (?, ?, ?, ?, ?, 'open', ?, ?, ?, ?, ?, ?, ?)")
                 ->execute([$company, $num, mb_substr($title, 0, 255), $type, $priority, $date, (int)$unit['building_id'], $unitId,
                            $leaseId ?: null, $tenantId ? (int)$tenantId : null, $desc !== '' ? $desc : null, $userId ?: null]);
            $issueId = (int)$conn->lastInsertId();
            legal_issue_log($conn, $company, $issueId, 'created', 'Issue logged');
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $error = 'The issue could not be saved. Please try again.';
        }
        if ($error === '') {
            [$saved, $fileErrors] = legal_issue_save_files($conn, $company, $issueId);
            if ($saved) legal_issue_log($conn, $company, $issueId, 'evidence', $saved . ' evidence file' . ($saved === 1 ? '' : 's') . ' added');
            $_SESSION['legal_issue_flash'] = 'Issue ' . $num . ' logged' . ($saved ? ' with ' . $saved . ' evidence file' . ($saved === 1 ? '' : 's') : '') . '.'
                . ($fileErrors ? ' Not uploaded: ' . implode(' ', $fileErrors) : '');
            header('Location: legal_issue_view.php?id=' . $issueId);
            exit;
        }
    }
}

$pageTitle = 'New Issue';
$pageHead = '
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<link href="assets/legal-issues.css?v=' . (@filemtime(__DIR__ . '/assets/legal-issues.css') ?: 1) . '" rel="stylesheet">';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
<div class="lw-page li-page">
    <div class="li-head">
        <div>
            <h1><i class="bi bi-flag"></i> New Issue</h1>
            <span class="lw-muted">Pick the unit — the tenant and contract fill in automatically.</span>
        </div>
        <a href="legal_issues.php" class="btn lw-btn-soft"><i class="bi bi-arrow-left"></i> Issues</a>
    </div>

    <?php if (!$ready): ?>
        <div class="alert alert-danger">The Issues tables are missing. Run <code>migrations/re_legal_issues.sql</code> in phpMyAdmin.</div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="liForm" novalidate>
        <?php csrf_field(); ?>
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="lw-panel mb-3">
                    <div class="lw-panel-head"><h2><span class="li-step">1</span> What happened</h2></div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Title *</label>
                            <input type="text" name="title" class="form-control" maxlength="255" required value="<?= h($pf('title')) ?>" placeholder="e.g. Tenant still occupying after contract expiry">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Issue type</label>
                            <select name="issue_type" class="form-select">
                                <?php foreach (legal_issue_types() as $k => $v): ?>
                                    <option value="<?= h($k) ?>" <?= $pf('issue_type', 'other') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Priority</label>
                            <select name="priority" class="form-select">
                                <?php foreach (legal_issue_priorities() as $k => $v): ?>
                                    <option value="<?= h($k) ?>" <?= $pf('priority', 'medium') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Date</label>
                            <input type="date" name="issue_date" class="form-control" value="<?= h($pf('issue_date', date('Y-m-d'))) ?>" max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="4" placeholder="What happened, when, who reported it…"><?= h($pf('description')) ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="lw-panel mb-3">
                    <div class="lw-panel-head"><h2><span class="li-step">2</span> Where</h2></div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Company *</label>
                            <select name="company_id" id="liCompany" class="form-select">
                                <?php foreach ($companies as $cid => $cname): ?>
                                    <option value="<?= (int)$cid ?>" <?= $cid === $pre['company_id'] ? 'selected' : '' ?>><?= h($cname) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Building *</label>
                            <select id="liBuilding" class="form-select" data-selected="<?= (int)$pre['building_id'] ?>"><option value="">Select building</option></select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unit *</label>
                            <select name="unit_id" id="liUnit" class="form-select" data-selected="<?= (int)$pre['unit_id'] ?>"><option value="">Select unit</option></select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Contract</label>
                            <select name="lease_id" id="liLease" class="form-select" data-selected="<?= (int)$pre['lease_id'] ?>"><option value="">Choose a unit first</option></select>
                        </div>
                    </div>
                </div>

                <div class="lw-panel mb-3">
                    <div class="lw-panel-head">
                        <h2><span class="li-step">3</span> Evidence</h2>
                        <span class="lw-muted">Photos or PDF · up to <?= LEGAL_ISSUE_MAX_FILES ?> files, 10 MB each</span>
                    </div>
                    <label class="li-drop" id="liDrop">
                        <input type="file" name="evidence[]" id="liFiles" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf" multiple>
                        <i class="bi bi-cloud-arrow-up"></i>
                        <strong>Drop photos here or click to choose</strong>
                        <span class="lw-muted">On a phone you can take photos with the camera</span>
                    </label>
                    <div class="li-thumbs" id="liThumbs"></div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="li-side">
                    <div class="lw-panel mb-3" id="liTenantCard">
                        <div class="lw-panel-head"><h2>Tenant &amp; contract</h2></div>
                        <div id="liTenant" class="li-tenant-empty"><i class="bi bi-person-badge"></i>Choose a unit to see who is on the contract.</div>
                    </div>
                    <button type="submit" class="btn lw-btn-grad w-100 py-2" <?= $ready ? '' : 'disabled' ?>><i class="bi bi-check2-circle"></i> Save issue</button>
                    <a href="legal_issues.php" class="btn lw-btn-soft w-100 mt-2">Cancel</a>
                </div>
            </div>
        </div>
    </form>
</div>

<?php
$pageScripts = <<<'HTML'
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function () {
    const $ = s => document.querySelector(s);
    const hasS2 = !!(window.jQuery && jQuery.fn.select2);
    const company = $('#liCompany'), building = $('#liBuilding'), unit = $('#liUnit'), lease = $('#liLease');
    const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const dshow = d => d ? new Date(d + 'T00:00:00').toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric'}) : '';
    let leases = [];

    if (hasS2) jQuery('#liForm select').select2({theme: 'bootstrap-5', width: '100%'});
    const refresh = el => { if (hasS2) jQuery(el).trigger('change.select2'); };
    const on = (el, fn) => hasS2 ? jQuery(el).on('change', fn) : el.addEventListener('change', fn);
    const api = q => fetch('legal_issue_lookup.php?' + new URLSearchParams(Object.assign({company_id: company.value}, q)), {credentials: 'same-origin'}).then(r => r.json());
    function fill(el, items, first, label, sel) {
        el.innerHTML = '<option value="">' + first + '</option>' + items.map(i => '<option value="' + i.id + '"' + (String(i.id) === String(sel) ? ' selected' : '') + '>' + esc(label(i)) + '</option>').join('');
        refresh(el);
    }

    function loadBuildings(sel) {
        fill(unit, [], 'Select unit', () => '', ''); fill(lease, [], 'Choose a unit first', () => '', ''); showTenant();
        return api({action: 'buildings'}).then(d => { fill(building, d.items || [], 'Select building', i => i.name, sel); if (sel) return loadUnits(unit.dataset.selected); });
    }
    function loadUnits(sel) {
        fill(lease, [], 'Choose a unit first', () => '', ''); showTenant();
        if (!building.value) { fill(unit, [], 'Select unit', () => '', ''); return Promise.resolve(); }
        return api({action: 'units', building_id: building.value}).then(d => { fill(unit, d.items || [], 'Select unit', i => i.unit_number, sel); if (sel) return loadUnit(lease.dataset.selected); });
    }
    function loadUnit(sel) {
        if (!unit.value) { leases = []; fill(lease, [], 'Choose a unit first', () => '', ''); showTenant(); return Promise.resolve(); }
        return api({action: 'unit', unit_id: unit.value}).then(d => {
            leases = d.leases || [];
            const label = l => (l.lease_number || '#' + l.id) + ' · ' + l.tenant_label + ' · ' + l.status.replace(/_/g, ' ') + ' · ' + dshow(l.start_date) + ' – ' + dshow(l.end_date);
            fill(lease, leases, leases.length ? 'No contract (unit only)' : 'No contract on this unit', label, sel || (leases[0] ? leases[0].id : ''));
            showTenant();
        });
    }
    function showTenant() {
        const box = $('#liTenant');
        const l = leases.find(x => String(x.id) === lease.value);
        if (!l) {
            box.className = 'li-tenant-empty';
            box.innerHTML = '<i class="bi bi-person-badge"></i>' + (unit.value ? (leases.length ? 'No contract selected.' : 'No contract found on this unit.') : 'Choose a unit to see who is on the contract.');
            return;
        }
        const ended = l.end_date && l.end_date < new Date().toISOString().slice(0, 10);
        box.className = 'li-tenant';
        box.innerHTML = '<div class="li-tname">' + esc(l.tenant_label) + '</div>'
            + '<div class="li-tlinks">'
            + (l.wa ? '<a class="li-wa" target="_blank" rel="noopener" href="https://wa.me/' + l.wa + '"><i class="bi bi-whatsapp"></i>' + esc(l.phone) + '</a>' : '')
            + (l.email ? '<a class="li-mail" href="mailto:' + esc(l.email) + '"><i class="bi bi-envelope"></i>' + esc(l.email) + '</a>' : '') + '</div>'
            + '<dl class="li-dl">'
            + '<dt>Contract</dt><dd><a target="_blank" href="../realestate/lease_view.php?id=' + l.id + '">' + esc(l.lease_number || '#' + l.id) + '</a> <span class="lw-status ' + esc(l.status) + '">' + esc(l.status.replace(/_/g, ' ')) + '</span></dd>'
            + '<dt>Period</dt><dd>' + esc(dshow(l.start_date)) + ' – ' + esc(dshow(l.end_date)) + (ended ? ' <span class="li-ended">ended</span>' : '') + '</dd>'
            + (+l.annual_rent > 0 ? '<dt>Annual rent</dt><dd>AED ' + Math.round(+l.annual_rent).toLocaleString('en-US') + '</dd>' : '')
            + '</dl>';
    }
    on(company, () => loadBuildings(''));
    on(building, () => loadUnits(''));
    on(unit, () => loadUnit(''));
    on(lease, showTenant);
    loadBuildings(building.dataset.selected);

    // Evidence previews; keeps a running list so files can be added in several picks and removed.
    const input = $('#liFiles'), thumbs = $('#liThumbs'), drop = $('#liDrop');
    let picked = [];
    const sync = () => { const dt = new DataTransfer(); picked.forEach(f => dt.items.add(f)); input.files = dt.files; };
    function render() {
        thumbs.innerHTML = '';
        picked.forEach((f, i) => {
            const d = document.createElement('div');
            d.className = 'li-thumb';
            if (f.type.startsWith('image/')) { const img = document.createElement('img'); img.src = URL.createObjectURL(f); d.append(img); }
            else d.innerHTML = '<span class="li-pdf"><i class="bi bi-file-earmark-pdf"></i>PDF</span>';
            const cap = document.createElement('span'); cap.className = 'li-cap'; cap.textContent = f.name; d.append(cap);
            const x = document.createElement('button'); x.type = 'button'; x.className = 'li-x'; x.title = 'Remove'; x.innerHTML = '<i class="bi bi-x"></i>';
            x.addEventListener('click', () => { picked.splice(i, 1); sync(); render(); });
            d.append(x);
            thumbs.append(d);
        });
    }
    function add(list) {
        Array.from(list).forEach(f => { if (picked.length < 20 && !picked.some(p => p.name === f.name && p.size === f.size)) picked.push(f); });
        sync(); render();
    }
    input.addEventListener('change', () => { const fresh = Array.from(input.files); picked = picked.filter(p => !fresh.includes(p)); add(fresh); });
    ['dragenter', 'dragover'].forEach(e => drop.addEventListener(e, ev => { ev.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach(e => drop.addEventListener(e, ev => { ev.preventDefault(); drop.classList.remove('is-over'); }));
    drop.addEventListener('drop', ev => add(ev.dataTransfer.files));

    $('#liForm').addEventListener('submit', e => {
        const t = document.querySelector('[name=title]');
        if (!t.value.trim()) { e.preventDefault(); t.focus(); t.classList.add('is-invalid'); return; }
        if (!unit.value) { e.preventDefault(); alert('Please choose the building and unit.'); }
    });
})();
</script>
HTML;
require_once __DIR__ . '/includes/legal_layout_footer.php';
?>
