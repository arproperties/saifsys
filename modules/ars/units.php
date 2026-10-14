<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_unit_history_helper.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);

// Bulk price update: blank field = leave that price as it is.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_price_update'])) {
    csrf_verify();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['unit_ids'] ?? [])))));
    $nightlyRaw = trim((string)($_POST['bulk_nightly_rate'] ?? ''));
    $monthlyRaw = trim((string)($_POST['bulk_monthly_rate'] ?? ''));
    $sets = [];
    $setParams = [];
    if ($nightlyRaw !== '' && is_numeric($nightlyRaw) && (float)$nightlyRaw >= 0) {
        $sets[] = 'nightly_rate = ?';
        $setParams[] = round((float)$nightlyRaw, 2);
    }
    if ($monthlyRaw !== '' && is_numeric($monthlyRaw) && (float)$monthlyRaw >= 0) {
        $sets[] = 'monthly_rate = ?';
        $setParams[] = round((float)$monthlyRaw, 2);
    }
    if (empty($ids)) {
        $_SESSION['ars_units_flash'] = ['type' => 'danger', 'msg' => 'Select at least one unit.'];
    } elseif (empty($sets)) {
        $_SESSION['ars_units_flash'] = ['type' => 'danger', 'msg' => 'Enter a nightly or monthly price.'];
    } else {
        [$unitWhere, $unitParams] = ars_short_term_units_where($arsCompanyId, 'u');
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $upd = $conn->prepare("UPDATE re_units u SET " . implode(', ', $sets) . " WHERE u.id IN ({$in}) AND {$unitWhere}");
            $upd->execute(array_merge($setParams, $ids, $unitParams));
            $_SESSION['ars_units_flash'] = ['type' => 'success', 'msg' => 'Prices updated for ' . count($ids) . ' unit' . (count($ids) !== 1 ? 's' : '') . '.'];
        } catch (PDOException $e) {
            $_SESSION['ars_units_flash'] = ['type' => 'danger', 'msg' => 'Failed to update prices: ' . $e->getMessage()];
        }
    }
    header('Location: units.php' . (!empty($_POST['return_qs']) ? '?' . preg_replace('/[^A-Za-z0-9_=&%.-]/', '', (string)$_POST['return_qs']) : ''));
    exit;
}
$flash = $_SESSION['ars_units_flash'] ?? null;
unset($_SESSION['ars_units_flash']);

$unitTypeLabels = [
    'studio' => 'Studio', '1br' => '1 Bedroom', '2br' => '2 Bedroom', '3br' => '3 Bedroom',
    '4br' => '4 Bedroom', 'penthouse' => 'Penthouse',
];
$unitTypeLabel = static function (?string $t) use ($unitTypeLabels): string {
    $t = (string)$t;
    return $unitTypeLabels[$t] ?? ucwords(str_replace('_', ' ', $t));
};

// Fetch units available for short-term rental (scoped helper; RE inventory sharing supported)
$historyJoin = '';
$historySelect = "NULL AS current_tenant_name, NULL AS current_contract_number";
if (arsHistoryTablesReady($conn)) {
    $historySelect = "CONCAT(o.tenant_first_name, ' ', COALESCE(o.tenant_last_name, '')) AS current_tenant_name, o.contract_number AS current_contract_number";
    $historyJoin = "LEFT JOIN ars_unit_occupancies o ON o.unit_id = u.id AND o.company_id = {$arsCompanyId} AND o.status = 'active'";
}
[$unitWhere, $unitParams] = ars_short_term_units_where($arsCompanyId, 'u');
$stmt = $conn->prepare("
    SELECT u.*, b.name AS building_name,
        (SELECT file_path FROM ars_unit_photos WHERE unit_id = u.id AND is_primary = 1 LIMIT 1) AS primary_photo,
        {$historySelect}
    FROM re_units u
    LEFT JOIN re_buildings b ON b.id = u.building_id
    {$historyJoin}
    WHERE {$unitWhere}
    ORDER BY b.name, u.unit_number
");
$stmt->execute($unitParams);
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);

$typeOptions = [];
$buildingOptions = [];
foreach ($units as $u) {
    if (!empty($u['unit_type'])) $typeOptions[$u['unit_type']] = $unitTypeLabel($u['unit_type']);
    if (!empty($u['building_name'])) $buildingOptions[$u['building_name']] = $u['building_name'];
}
ksort($buildingOptions);
$selType = (string)($_GET['type'] ?? '');
$selBuilding = (string)($_GET['building'] ?? '');

$pageTitle = 'Units';
ars_shell_begin([
    'title' => 'Units',
    'subtitle' => count($units) . ' unit' . (count($units) !== 1 ? 's' : ''),
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Units'],
    ],
    'actions_html' => ars_ui_button('Tenant history search', ['href' => 'unit_history_search.php', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'search']),
    'legacy_bootstrap' => true,
]);
?>


<?php if (empty($units)): ?>
<div class="ars-card">
    <div class="card-body text-center py-5">
        <i class="bi bi-door-closed text-muted" style="font-size:3rem"></i>
        <p class="mt-3 text-muted">No units configured for short-term rental yet.<br>
        Set a unit's <strong>rental_mode</strong> to "short_term" or "both" in the Real Estate module.</p>
    </div>
</div>
<?php else: ?>
<?php if ($flash): ?>
<div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show"><?= h($flash['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<style>
.unit-sel.form-check-input { width:18px; height:18px; margin:0; float:none; position:static; background-color:#fff; border:1.5px solid #9ca3af; cursor:pointer; }
.unit-sel.form-check-input:checked { background-color:var(--ars-primary, #b8860b); border-color:var(--ars-primary, #b8860b); }
</style>
<form method="post" id="bulkPriceForm">
<?php csrf_field(); ?>
<input type="hidden" name="bulk_price_update" value="1">
<input type="hidden" name="return_qs" id="bulkReturnQs" value="">

<div class="ars-card mb-3">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">Unit type</label>
                <select class="form-select form-select-sm" id="fltType">
                    <option value="">All types</option>
                    <?php foreach ($typeOptions as $val => $label): ?>
                    <option value="<?= h($val) ?>" <?= $selType === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">Building</label>
                <select class="form-select form-select-sm" id="fltBuilding">
                    <option value="">All buildings</option>
                    <?php foreach ($buildingOptions as $val => $label): ?>
                    <option value="<?= h($val) ?>" <?= $selBuilding === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small mb-1">Search</label>
                <input type="search" class="form-control form-control-sm" id="fltSearch" placeholder="Unit no. or title" value="<?= h($_GET['q'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <?= ars_ui_button('Select all shown', ['id' => 'btnSelectShown', 'size' => 'sm', 'icon' => 'check-check', 'class' => 'flex-fill']) ?>
                <?= ars_ui_button('Clear', ['id' => 'btnClearSel', 'variant' => 'outline', 'size' => 'sm', 'icon' => 'x', 'class' => 'flex-fill']) ?>
            </div>
        </div>
        <div class="small text-muted mt-2"><span id="shownCount"><?= count($units) ?></span> shown &middot; <span id="selCount">0</span> selected</div>
    </div>
</div>

<div class="ars-card mb-3 d-none" id="bulkBar" style="position:sticky;top:8px;z-index:20">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-3 fw-semibold"><span id="bulkSelCount">0</span> unit(s) selected</div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">New nightly price (AED)</label>
                <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="bulk_nightly_rate" id="bulkNightly" placeholder="Leave blank = no change">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">New monthly price (AED)</label>
                <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="bulk_monthly_rate" id="bulkMonthly" placeholder="Leave blank = no change">
            </div>
            <div class="col-12 col-md-3">
                <?= ars_ui_button('Update prices', ['type' => 'submit', 'size' => 'sm', 'icon' => 'save', 'class' => 'w-full']) ?>
            </div>
        </div>
        <div class="small text-muted mt-2">Existing bookings keep their own price. Only new bookings use the new price.</div>
    </div>
</div>

<div class="row g-3" id="unitGrid">
    <?php foreach ($units as $u): ?>
    <div class="col-6 col-sm-6 col-lg-4 col-xl-3 ars-unit-col"
         data-type="<?= h($u['unit_type'] ?? '') ?>"
         data-building="<?= h($u['building_name'] ?? '') ?>"
         data-search="<?= h(strtolower(($u['unit_number'] ?? '') . ' ' . ($u['listing_title'] ?? ''))) ?>">
        <div class="ars-card h-100">
            <div class="ars-unit-photo-thumb">
                <label style="position:absolute;top:8px;left:8px;z-index:2;width:30px;height:30px;display:flex;align-items:center;justify-content:center;margin:0;padding:0;background:#fff;border-radius:6px;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.2)">
                    <input type="checkbox" class="form-check-input unit-sel" name="unit_ids[]" value="<?= (int)$u['id'] ?>">
                </label>
                <?php if ($u['primary_photo']): ?>
                <img src="../../<?= h($u['primary_photo']) ?>" alt="<?= h($u['listing_title'] ?: $u['unit_number']) ?>">
                <?php else: ?>
                <div class="ars-unit-photo-placeholder"><i class="bi bi-image"></i></div>
                <?php endif; ?>
                <?php if ($u['is_listed']): ?>
                <span class="ars-badge confirmed" style="position:absolute;top:8px;right:8px"><i class="bi bi-eye-fill"></i> Listed</span>
                <?php endif; ?>
            </div>
            <div class="card-body" style="overflow-wrap:break-word;word-break:break-word">
                <h6 class="fw-bold mb-1"><?= h($u['listing_title'] ?: $u['unit_number']) ?></h6>
                <small class="text-muted d-block mb-2"><?= h($u['building_name']) ?> &middot; <?= h($unitTypeLabel($u['unit_type'] ?? '')) ?></small>
                <?php if ($u['nightly_rate']): ?>
                <div class="fw-semibold" style="color:var(--ars-primary)">AED <?= number_format((float)$u['nightly_rate'], 2) ?> <small class="text-muted fw-normal">/ night</small></div>
                <?php else: ?>
                <div class="text-muted small">No rate set</div>
                <?php endif; ?>
                <?php if ((float)($u['monthly_rate'] ?? 0) > 0): ?>
                <div class="small fw-semibold" style="color:var(--ars-primary)">AED <?= number_format((float)$u['monthly_rate'], 2) ?> <small class="text-muted fw-normal">/ month</small></div>
                <?php endif; ?>
                <div class="small text-muted mt-1"><i class="bi bi-people-fill me-1"></i>Max <?= (int)$u['max_guests'] ?> guests</div>
                <div class="small mt-2">
                    <strong>Current Tenant:</strong>
                    <?php if (!empty($u['current_tenant_name'])): ?>
                        <?= h($u['current_tenant_name']) ?>
                        <?php if (!empty($u['current_contract_number'])): ?><br><span class="text-muted"><?= h($u['current_contract_number']) ?></span><?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">No manual active tenant</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-footer bg-transparent border-top-0 pt-0">
                <div class="d-grid gap-2">
                    <?= ars_ui_button('Profile / history', ['href' => 'unit_profile.php?id=' . (int)$u['id'], 'size' => 'sm', 'icon' => 'history', 'class' => 'w-full justify-center']) ?>
                    <?= ars_ui_button('Edit unit', ['href' => 'unit_edit.php?id=' . (int)$u['id'], 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'pencil', 'class' => 'w-full justify-center']) ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<div class="text-center text-muted py-5 d-none" id="noMatch">No units match this filter.</div>
</form>

<script>
(function () {
    var form = document.getElementById('bulkPriceForm');
    var type = document.getElementById('fltType');
    var bld = document.getElementById('fltBuilding');
    var q = document.getElementById('fltSearch');
    var cols = Array.prototype.slice.call(document.querySelectorAll('.ars-unit-col'));
    var bar = document.getElementById('bulkBar');

    function visible(c) { return !c.classList.contains('d-none'); }

    function refreshCounts() {
        var sel = cols.filter(function (c) { return c.querySelector('.unit-sel').checked; }).length;
        document.getElementById('selCount').textContent = sel;
        document.getElementById('bulkSelCount').textContent = sel;
        document.getElementById('shownCount').textContent = cols.filter(visible).length;
        bar.classList.toggle('d-none', sel === 0);
    }

    function applyFilter() {
        var t = type.value, b = bld.value, s = q.value.trim().toLowerCase();
        var shown = 0;
        cols.forEach(function (c) {
            var ok = (!t || c.dataset.type === t) && (!b || c.dataset.building === b) && (!s || c.dataset.search.indexOf(s) !== -1);
            c.classList.toggle('d-none', !ok);
            // Hidden units never stay selected, so the update only touches what is on screen.
            if (!ok) c.querySelector('.unit-sel').checked = false;
            if (ok) shown++;
        });
        document.getElementById('noMatch').classList.toggle('d-none', shown > 0);
        var p = new URLSearchParams();
        if (t) p.set('type', t);
        if (b) p.set('building', b);
        if (s) p.set('q', s);
        var qs = p.toString();
        document.getElementById('bulkReturnQs').value = qs;
        history.replaceState(null, '', 'units.php' + (qs ? '?' + qs : ''));
        refreshCounts();
    }

    type.addEventListener('change', applyFilter);
    bld.addEventListener('change', applyFilter);
    q.addEventListener('input', applyFilter);
    q.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
    document.getElementById('btnSelectShown').addEventListener('click', function () {
        cols.filter(visible).forEach(function (c) { c.querySelector('.unit-sel').checked = true; });
        refreshCounts();
    });
    document.getElementById('btnClearSel').addEventListener('click', function () {
        cols.forEach(function (c) { c.querySelector('.unit-sel').checked = false; });
        refreshCounts();
    });
    cols.forEach(function (c) { c.querySelector('.unit-sel').addEventListener('change', refreshCounts); });

    form.addEventListener('submit', function (e) {
        var n = document.getElementById('bulkNightly').value.trim();
        var m = document.getElementById('bulkMonthly').value.trim();
        var sel = cols.filter(function (c) { return c.querySelector('.unit-sel').checked; }).length;
        if (!n && !m) { e.preventDefault(); alert('Enter a nightly or monthly price.'); return; }
        var parts = [];
        if (n) parts.push('nightly AED ' + n);
        if (m) parts.push('monthly AED ' + m);
        if (!confirm('Set ' + parts.join(' and ') + ' for ' + sel + ' unit(s)?')) e.preventDefault();
    });

    applyFilter();
})();
</script>
<?php endif; ?>

<?php ars_shell_end(); ?>
