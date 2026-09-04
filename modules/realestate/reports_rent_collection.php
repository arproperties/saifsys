<?php
/** Combined Legacy + Invoice Mode Collection Report */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/reporting_mode_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function m($n) { return number_format((float)$n, 2); }

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$filterBuilding = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : 0;
$filterFloor = !empty($_GET['floor_id']) ? (int)$_GET['floor_id'] : 0;
$filterUnit = !empty($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;
$exportFormat = $_GET['export'] ?? '';

$where = ["p.company_id = ?", "p.payment_date >= ?", "p.payment_date <= ?"];
$params = [$currentCompanyId, $dateFrom, $dateTo];
if ($filterBuilding > 0) { $where[] = "b.id = ?"; $params[] = $filterBuilding; }
if ($filterFloor > 0) { $where[] = "u.floor_id = ?"; $params[] = $filterFloor; }
if ($filterUnit > 0) { $where[] = "u.id = ?"; $params[] = $filterUnit; }

$sql = "
    SELECT p.*, COALESCE(l.accounting_mode, 'legacy') accounting_mode, l.lease_number,
           b.name building_name, u.unit_number, f.floor_number, f.name floor_name,
           t.first_name, t.last_name, t.company_name, t.tenant_type,
           li.installment_date, li.amount installment_amount, li.status installment_status,
           COALESCE(alloc.allocated_amount, 0) allocated_amount
    FROM re_payments p
    JOIN re_leases l ON l.id = p.lease_id AND l.company_id = p.company_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_floors f ON f.id = u.floor_id
    LEFT JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_lease_installments li ON li.id = p.installment_id
    LEFT JOIN (
        SELECT payment_id, COALESCE(SUM(amount_allocated), 0) allocated_amount
        FROM re_receipt_allocations
        GROUP BY payment_id
    ) alloc ON alloc.payment_id = p.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY p.payment_date DESC, p.id DESC
";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$collections = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stats = ['legacy' => 0.0, 'invoice' => 0.0, 'combined' => 0.0, 'count' => count($collections), 'unallocated' => 0.0, 'by_method' => []];
foreach ($collections as $p) {
    $amt = (float)$p['amount'];
    $mode = $p['accounting_mode'] === 'invoice' ? 'invoice' : 'legacy';
    $stats[$mode] += $amt;
    $stats['combined'] += $amt;
    $method = $p['payment_method'] ?: 'Unknown';
    $stats['by_method'][$method] = ($stats['by_method'][$method] ?? 0) + $amt;
    if ($mode === 'invoice') $stats['unallocated'] += max(0, $amt - (float)$p['allocated_amount']);
}

$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

// Floor/unit filter options only when a building is selected (cascading UX)
$floors = [];
$units = [];
if ($filterBuilding > 0) {
    $floorsStmt = $conn->prepare("
        SELECT f.id, f.floor_number, f.name
        FROM re_floors f
        JOIN re_buildings b ON b.id = f.building_id AND b.company_id = ?
        WHERE f.building_id = ?
        ORDER BY f.floor_number ASC, f.name ASC
    ");
    $floorsStmt->execute([$currentCompanyId, $filterBuilding]);
    $floors = $floorsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $unitSql = "
        SELECT u.id, u.unit_number
        FROM re_units u
        WHERE u.company_id = ? AND u.building_id = ?
    ";
    $unitParams = [$currentCompanyId, $filterBuilding];
    if ($filterFloor > 0) {
        $unitSql .= " AND u.floor_id = ?";
        $unitParams[] = $filterFloor;
    }
    $unitSql .= " ORDER BY u.unit_number ASC";
    $unitsStmt = $conn->prepare($unitSql);
    $unitsStmt->execute($unitParams);
    $units = $unitsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$floorOptionLabel = static function (array $f): string {
    $name = trim((string)($f['name'] ?? ''));
    return $name !== '' ? $name : ('Floor ' . (int)($f['floor_number'] ?? 0));
};

if ($exportFormat === 'csv') {
    header('Content-Type:text/csv');
    header('Content-Disposition: attachment; filename="collection_report_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Mode', 'Building', 'Floor', 'Unit', 'Tenant', 'Lease', 'Amount', 'Allocated', 'Unallocated', 'Method', 'Reference', 'Receipt']);
    foreach ($collections as $p) {
        $tn = $p['tenant_type'] === 'company' ? $p['company_name'] : trim($p['first_name'] . ' ' . $p['last_name']);
        $un = max(0, (float)$p['amount'] - (float)$p['allocated_amount']);
        $floorName = trim((string)($p['floor_name'] ?? ''));
        $floorLabel = $floorName !== '' ? $floorName : ($p['floor_number'] !== null ? ('Floor ' . $p['floor_number']) : '');
        fputcsv($out, [$p['payment_date'], $p['accounting_mode'], $p['building_name'], $floorLabel, $p['unit_number'], $tn, $p['lease_number'], number_format($p['amount'], 2), number_format($p['allocated_amount'], 2), number_format($un, 2), $p['payment_method'], $p['reference_number'], $p['receipt_number']]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Collection Report';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h1><i class="bi bi-cash-stack"></i> Collection Report</h1>
    <div>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success">Export CSV</a>
        <button onclick="window.print()" class="btn btn-primary">Print</button>
    </div>
</div>
<div class="alert alert-info">Combined report: Legacy collections use historical payments; Invoice Mode collections use cleared receipts and allocation status.</div>

<div class="card mb-4 no-print">
    <div class="card-body">
        <form method="get" class="row g-3" id="collectionFilterForm">
            <div class="col-md-2"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div>
            <div class="col-md-2"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div>
            <div class="col-md-2">
                <label class="form-label">Building</label>
                <select name="building_id" id="filterBuilding" class="form-select">
                    <option value="0">All buildings</option>
                    <?php foreach ($buildings as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $filterBuilding === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Floor</label>
                <select name="floor_id" id="filterFloor" class="form-select" data-selected="<?= (int)$filterFloor ?>" <?= $filterBuilding > 0 ? '' : 'disabled' ?>>
                    <option value="0"><?= $filterBuilding > 0 ? 'All floors' : 'Select building first' ?></option>
                    <?php foreach ($floors as $f): ?>
                        <option value="<?= (int)$f['id'] ?>" <?= $filterFloor === (int)$f['id'] ? 'selected' : '' ?>><?= h($floorOptionLabel($f)) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text" id="filterFloorHint" <?= $filterBuilding > 0 ? 'style="display:none"' : '' ?>>Select a building to list floors.</div>
            </div>
            <div class="col-md-2">
                <label class="form-label">Unit</label>
                <select name="unit_id" id="filterUnit" class="form-select" data-selected="<?= (int)$filterUnit ?>" <?= $filterBuilding > 0 ? '' : 'disabled' ?>>
                    <option value="0"><?= $filterBuilding > 0 ? 'All units' : 'Select building first' ?></option>
                    <?php foreach ($units as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $filterUnit === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['unit_number']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text" id="filterUnitHint" <?= $filterBuilding > 0 ? 'style="display:none"' : '' ?>>Select a building to list units.</div>
            </div>
            <div class="col-md-2"><label class="form-label">&nbsp;</label><button type="submit" class="btn btn-primary w-100">Generate</button></div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Legacy Collections</div><h4><?= m($stats['legacy']) ?></h4></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Invoice Mode Receipts</div><h4><?= m($stats['invoice']) ?></h4></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Combined</div><h4><?= m($stats['combined']) ?></h4></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Invoice Unallocated</div><h4><?= m($stats['unallocated']) ?></h4></div></div></div>
</div>

<div class="card mb-4">
    <div class="card-header">Collection by Payment Method</div>
    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Method</th><th class="text-end">Amount</th><th class="text-end">%</th></tr></thead><tbody>
    <?php foreach ($stats['by_method'] as $method => $amount): ?><tr><td><?= h($method) ?></td><td class="text-end"><?= m($amount) ?></td><td class="text-end"><?= $stats['combined'] > 0 ? round($amount / $stats['combined'] * 100, 1) : 0 ?>%</td></tr><?php endforeach; ?>
    </tbody></table></div>
</div>

<div class="card">
    <div class="card-header">Collections (<?= count($collections) ?>)</div>
    <div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Date</th><th>Mode</th><th>Tenant</th><th>Lease</th><th>Building / Floor / Unit</th><th class="text-end">Amount</th><th class="text-end">Allocated</th><th class="text-end">Unallocated</th><th>Method</th><th>Reference</th><th>Receipt</th></tr></thead><tbody>
    <?php foreach ($collections as $p): ?>
        <?php
        $tn = $p['tenant_type'] === 'company' ? $p['company_name'] : trim($p['first_name'] . ' ' . $p['last_name']);
        $un = max(0, (float)$p['amount'] - (float)$p['allocated_amount']);
        $floorName = trim((string)($p['floor_name'] ?? ''));
        $floorLabel = $floorName !== '' ? $floorName : ($p['floor_number'] !== null ? ('Floor ' . $p['floor_number']) : 'No floor');
        ?>
        <tr><td><?= h($p['payment_date']) ?></td><td><span class="badge bg-<?= $p['accounting_mode'] === 'invoice' ? 'primary' : 'secondary' ?>"><?= h($p['accounting_mode'] ?: 'legacy') ?></span></td><td><?= h($tn) ?></td><td><a href="lease_view.php?id=<?= (int)$p['lease_id'] ?>"><?= h($p['lease_number']) ?></a></td><td><?= h($p['building_name'] . ' / ' . $floorLabel . ' / ' . $p['unit_number']) ?></td><td class="text-end"><?= m($p['amount']) ?></td><td class="text-end"><?= m($p['allocated_amount']) ?></td><td class="text-end"><?= m($un) ?></td><td><?= h($p['payment_method']) ?></td><td><?= h($p['reference_number'] ?: '-') ?></td><td><a href="payment_view.php?id=<?= (int)$p['id'] ?>"><?= h($p['receipt_number'] ?: ('#' . $p['id'])) ?></a></td></tr>
    <?php endforeach; ?>
    <?php if (!$collections): ?><tr><td colspan="11" class="text-center text-muted">No collections found.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<script>
(function () {
    var buildingSel = document.getElementById('filterBuilding');
    var floorSel = document.getElementById('filterFloor');
    var unitSel = document.getElementById('filterUnit');
    var floorHint = document.getElementById('filterFloorHint');
    var unitHint = document.getElementById('filterUnitHint');
    if (!buildingSel || !floorSel || !unitSel) return;

    function floorLabel(f) {
        var name = (f.name || '').trim();
        return name !== '' ? name : ('Floor ' + (f.floor_number || ''));
    }

    function resetFloorUnitDisabled() {
        floorSel.innerHTML = '<option value="0">Select building first</option>';
        unitSel.innerHTML = '<option value="0">Select building first</option>';
        floorSel.disabled = true;
        unitSel.disabled = true;
        if (floorHint) floorHint.style.display = '';
        if (unitHint) unitHint.style.display = '';
    }

    function loadUnits(buildingId, floorId, selectedUnitId) {
        if (!buildingId) {
            unitSel.innerHTML = '<option value="0">Select building first</option>';
            unitSel.disabled = true;
            if (unitHint) unitHint.style.display = '';
            return;
        }
        unitSel.disabled = true;
        unitSel.innerHTML = '<option value="0">Loading…</option>';
        if (unitHint) unitHint.style.display = 'none';
        var url = 'ajax_get_units.php?building_id=' + encodeURIComponent(buildingId);
        if (floorId) url += '&floor_id=' + encodeURIComponent(floorId);
        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                unitSel.innerHTML = '<option value="0">All units</option>';
                (d.units || []).forEach(function (u) {
                    var opt = document.createElement('option');
                    opt.value = u.id;
                    opt.textContent = u.unit_number;
                    if (selectedUnitId && String(selectedUnitId) === String(u.id)) opt.selected = true;
                    unitSel.appendChild(opt);
                });
                unitSel.disabled = false;
            })
            .catch(function () {
                unitSel.innerHTML = '<option value="0">All units</option>';
                unitSel.disabled = false;
            });
    }

    function loadFloors(buildingId, selectedFloorId, selectedUnitId) {
        if (!buildingId) {
            resetFloorUnitDisabled();
            return;
        }
        floorSel.disabled = true;
        floorSel.innerHTML = '<option value="0">Loading…</option>';
        if (floorHint) floorHint.style.display = 'none';
        fetch('ajax_floors.php?action=list&building_id=' + encodeURIComponent(buildingId))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                floorSel.innerHTML = '<option value="0">All floors</option>';
                if (d && d.success && Array.isArray(d.floors)) {
                    d.floors.forEach(function (f) {
                        var opt = document.createElement('option');
                        opt.value = f.id;
                        opt.textContent = floorLabel(f);
                        if (selectedFloorId && String(selectedFloorId) === String(f.id)) opt.selected = true;
                        floorSel.appendChild(opt);
                    });
                }
                floorSel.disabled = false;
                loadUnits(buildingId, floorSel.value, selectedUnitId || '');
            })
            .catch(function () {
                floorSel.innerHTML = '<option value="0">All floors</option>';
                floorSel.disabled = false;
                loadUnits(buildingId, '', selectedUnitId || '');
            });
    }

    buildingSel.addEventListener('change', function () {
        loadFloors(this.value, '', '');
    });
    floorSel.addEventListener('change', function () {
        loadUnits(buildingSel.value, this.value, '');
    });

    // Ensure disabled selects still post 0 when "All buildings"
    document.getElementById('collectionFilterForm')?.addEventListener('submit', function () {
        if (floorSel.disabled) floorSel.disabled = false;
        if (unitSel.disabled) unitSel.disabled = false;
        if (!buildingSel.value || buildingSel.value === '0') {
            floorSel.value = '0';
            unitSel.value = '0';
        }
    });
})();
</script>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
