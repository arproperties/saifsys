<?php
/** Combined Legacy + Invoice Mode Outstandings Report */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/reporting_mode_helper.php';
require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) require_module_access($conn, MODULE_REALESTATE);
$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$asOfDate = $_GET['as_of_date'] ?? date('Y-m-d');
$leaseStatus = $_GET['lease_status'] ?? 'active';
$buildingId = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : 0;
$floorId = !empty($_GET['floor_id']) ? (int)$_GET['floor_id'] : 0;
$unitId = !empty($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;
$validStatuses = ['active','all','draft','expired','terminated','renewed','has_legal_case'];
if (!in_array($leaseStatus, $validStatuses, true)) $leaseStatus = 'active';
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function m($n){return number_format((float)$n,2);} 
$whereExtra = '';
$filterParams = [];
if ($leaseStatus === 'active') {
    // Default "active" view also includes Has Legal Case so AR is not hidden after unit rebooking
    $whereExtra .= " AND l.status IN ('active','has_legal_case')";
} elseif ($leaseStatus !== 'all') {
    $whereExtra .= ' AND l.status = ?';
    $filterParams[] = $leaseStatus;
}
if ($buildingId > 0) {
    $whereExtra .= ' AND b.id = ?';
    $filterParams[] = $buildingId;
}
if ($floorId > 0) {
    $whereExtra .= ' AND u.floor_id = ?';
    $filterParams[] = $floorId;
}
if ($unitId > 0) {
    $whereExtra .= ' AND u.id = ?';
    $filterParams[] = $unitId;
}

$buildings=$conn->prepare("SELECT id,name FROM re_buildings WHERE company_id=? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings=$buildings->fetchAll(PDO::FETCH_ASSOC)?:[];

// Floor/unit options only when a building is selected (cascading UX)
$floors=[];
$units=[];
if ($buildingId > 0) {
    $floorsStmt=$conn->prepare("
        SELECT f.id, f.floor_number, f.name
        FROM re_floors f
        JOIN re_buildings b ON b.id = f.building_id AND b.company_id = ?
        WHERE f.building_id = ?
        ORDER BY f.floor_number ASC, f.name ASC
    ");
    $floorsStmt->execute([$currentCompanyId, $buildingId]);
    $floors=$floorsStmt->fetchAll(PDO::FETCH_ASSOC)?:[];

    $unitSql="SELECT u.id, u.unit_number FROM re_units u WHERE u.company_id=? AND u.building_id=?";
    $unitParams=[$currentCompanyId, $buildingId];
    if ($floorId > 0) {
        $unitSql.=" AND u.floor_id=?";
        $unitParams[]=$floorId;
    }
    $unitSql.=" ORDER BY u.unit_number ASC";
    $unitsStmt=$conn->prepare($unitSql);
    $unitsStmt->execute($unitParams);
    $units=$unitsStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}
$floorOptionLabel = static function (array $f): string {
    $name = trim((string)($f['name'] ?? ''));
    return $name !== '' ? $name : ('Floor ' . (int)($f['floor_number'] ?? 0));
};
$rowFloorLabel = static function (array $r): string {
    $name = trim((string)($r['floor_name'] ?? ''));
    if ($name !== '') return $name;
    return $r['floor_number'] !== null ? ('Floor ' . $r['floor_number']) : 'No floor';
};

$invoiceRows=[]; $legacyRows=[]; $invoiceTotal=0.0; $legacyTotal=0.0;
$stmt=$conn->prepare("SELECT i.id,i.invoice_number,i.invoice_date,i.due_date,i.outstanding_amount,l.lease_number,l.id lease_id,l.status lease_status,t.id tenant_id,t.first_name,t.last_name,t.company_name,t.tenant_type,b.name building_name,u.unit_number,f.floor_number,f.name floor_name,DATEDIFF(?,i.due_date) days_overdue FROM re_invoices i JOIN re_leases l ON l.id=i.lease_id AND l.company_id=i.company_id JOIN re_units u ON u.id=l.unit_id JOIN re_buildings b ON b.id=u.building_id LEFT JOIN re_floors f ON f.id=u.floor_id JOIN re_tenants t ON t.id=l.tenant_id WHERE i.company_id=? AND COALESCE(l.accounting_mode,'legacy')='invoice' AND i.outstanding_amount>0 AND i.status<>'cancelled' AND i.due_date<=? {$whereExtra} ORDER BY i.due_date ASC");
$stmt->execute(array_merge([$asOfDate,$currentCompanyId,$asOfDate],$filterParams));
$invoiceRows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
foreach($invoiceRows as $r){$invoiceTotal+=(float)$r['outstanding_amount'];}
try{
    $stmt=$conn->prepare("SELECT li.id installment_id,li.installment_date,li.amount,li.status,l.id lease_id,l.lease_number,l.status lease_status,b.name building_name,u.unit_number,f.floor_number,f.name floor_name,t.id tenant_id,t.first_name,t.last_name,t.company_name,t.tenant_type,COALESCE(paid.total_paid,0) total_paid,GREATEST(li.amount-COALESCE(paid.total_paid,0),0) outstanding,DATEDIFF(?,li.installment_date) days_overdue,COALESCE(pdc.cheque_number,lc.cheque_number,'') cheque_number FROM re_lease_installments li JOIN re_leases l ON l.id=li.lease_id AND l.company_id=li.company_id JOIN re_units u ON u.id=l.unit_id JOIN re_buildings b ON b.id=u.building_id LEFT JOIN re_floors f ON f.id=u.floor_id JOIN re_tenants t ON t.id=l.tenant_id LEFT JOIN (SELECT installment_id,COALESCE(SUM(amount_allocated),0) total_paid FROM re_payment_allocations GROUP BY installment_id) paid ON paid.installment_id=li.id LEFT JOIN re_post_dated_cheques pdc ON pdc.installment_id=li.id AND pdc.lease_id=li.lease_id LEFT JOIN re_lease_cheques lc ON lc.installment_id=li.id AND lc.lease_id=li.lease_id WHERE li.company_id=? AND COALESCE(l.accounting_mode,'legacy')='legacy' AND li.installment_date<=? AND li.status NOT IN('paid','cancelled','waived','returned') {$whereExtra} HAVING outstanding>0.005 ORDER BY li.installment_date ASC");
    $stmt->execute(array_merge([$asOfDate,$currentCompanyId,$asOfDate],$filterParams));
    $legacyRows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}catch(Throwable $e){
    $stmt=$conn->prepare("SELECT li.id installment_id,li.installment_date,li.amount,li.status,l.id lease_id,l.lease_number,l.status lease_status,b.name building_name,u.unit_number,f.floor_number,f.name floor_name,t.id tenant_id,t.first_name,t.last_name,t.company_name,t.tenant_type,0 total_paid,li.amount outstanding,DATEDIFF(?,li.installment_date) days_overdue,COALESCE(pdc.cheque_number,lc.cheque_number,'') cheque_number FROM re_lease_installments li JOIN re_leases l ON l.id=li.lease_id AND l.company_id=li.company_id JOIN re_units u ON u.id=l.unit_id JOIN re_buildings b ON b.id=u.building_id LEFT JOIN re_floors f ON f.id=u.floor_id JOIN re_tenants t ON t.id=l.tenant_id LEFT JOIN re_post_dated_cheques pdc ON pdc.installment_id=li.id AND pdc.lease_id=li.lease_id LEFT JOIN re_lease_cheques lc ON lc.installment_id=li.id AND lc.lease_id=li.lease_id WHERE li.company_id=? AND COALESCE(l.accounting_mode,'legacy')='legacy' AND li.installment_date<=? AND li.status NOT IN('paid','cancelled','waived','returned') {$whereExtra} ORDER BY li.installment_date ASC");
    $stmt->execute(array_merge([$asOfDate,$currentCompanyId,$asOfDate],$filterParams));
    $legacyRows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}
foreach($legacyRows as $r){$legacyTotal+=(float)$r['outstanding'];}
$tenantKeys = [];
foreach (array_merge($legacyRows, $invoiceRows) as $r) {
    $tenantKeys[(string)($r['tenant_id'] ?? (($r['tenant_type'] ?? '') . ':' . ($r['company_name'] ?: trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')))))] = true;
}
$tenantCount = count($tenantKeys);
$pageTitle='Outstandings Report'; require_once __DIR__ . '/../includes/re_layout_header.php'; ?>
<div class="d-flex justify-content-between align-items-center mb-4"><div class="page-header-label"><i class="bi bi-list-ul"></i> Combined Outstandings Report</div><div><a href="tenant_statement.php" class="btn btn-outline-primary">Tenant Statement</a></div></div>
<div class="alert alert-info">Default view shows <strong>active leases only</strong>. Use the filter to review draft/expired/terminated/renewed or all leases. Legacy outstanding uses installment/payment history; Invoice Mode outstanding uses issued invoices and allocations.</div>
<form method="get" class="card card-round mb-4" id="outstandingsFilterForm">
    <div class="card-body row g-3 align-items-end">
        <div class="col-md-2">
            <label class="form-label">As of Date</label>
            <input type="date" name="as_of_date" class="form-control" value="<?=h($asOfDate)?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">Lease Status</label>
            <select name="lease_status" class="form-select">
                <?php foreach(['active'=>'Active + Has Legal Case','all'=>'All statuses','draft'=>'Draft','expired'=>'Expired','terminated'=>'Terminated','renewed'=>'Renewed','has_legal_case'=>'Has Legal Case only'] as $v=>$label): ?>
                    <option value="<?=h($v)?>" <?=$leaseStatus===$v?'selected':''?>><?=h($label)?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Building</label>
            <select name="building_id" id="filterBuilding" class="form-select">
                <option value="0">All buildings</option>
                <?php foreach($buildings as $b): ?>
                    <option value="<?=(int)$b['id']?>" <?=$buildingId===(int)$b['id']?'selected':''?>><?=h($b['name'])?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Floor</label>
            <select name="floor_id" id="filterFloor" class="form-select" data-selected="<?=(int)$floorId?>" <?=$buildingId>0?'':'disabled'?>>
                <option value="0"><?=$buildingId>0?'All floors':'Select building first'?></option>
                <?php foreach($floors as $f): ?>
                    <option value="<?=(int)$f['id']?>" <?=$floorId===(int)$f['id']?'selected':''?>><?=h($floorOptionLabel($f))?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text" id="filterFloorHint" <?=$buildingId>0?'style="display:none"':''?>>Select a building to list floors.</div>
        </div>
        <div class="col-md-2">
            <label class="form-label">Unit</label>
            <select name="unit_id" id="filterUnit" class="form-select" data-selected="<?=(int)$unitId?>" <?=$buildingId>0?'':'disabled'?>>
                <option value="0"><?=$buildingId>0?'All units':'Select building first'?></option>
                <?php foreach($units as $u): ?>
                    <option value="<?=(int)$u['id']?>" <?=$unitId===(int)$u['id']?'selected':''?>><?=h($u['unit_number'])?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text" id="filterUnitHint" <?=$buildingId>0?'style="display:none"':''?>>Select a building to list units.</div>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Load</button>
            <a href="outstandings_report.php" class="btn btn-outline-secondary w-100 mt-2">Reset</a>
        </div>
    </div>
</form>
<div class="row g-3 mb-4"><div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Legacy Outstanding</div><h4><?=m($legacyTotal)?></h4></div></div></div><div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Invoice Mode AR</div><h4><?=m($invoiceTotal)?></h4></div></div></div><div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Combined AR</div><h4><?=m($legacyTotal+$invoiceTotal)?></h4></div></div></div><div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Tenants With Outstanding</div><h4><?=number_format($tenantCount)?></h4><small class="text-muted">Based on current filter</small></div></div></div></div>
<div class="card card-round mb-4"><div class="card-header">Legacy Mode Outstanding Installments</div><div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Lease</th><th>Tenant</th><th>Building / Floor / Unit</th><th>Installment / Cheque #</th><th>Installment Date</th><th>Status</th><th class="text-end">Amount</th><th class="text-end">Paid</th><th class="text-end">Outstanding</th></tr></thead><tbody><?php foreach($legacyRows as $r): ?><?php $tn=$r['tenant_type']==='company'?$r['company_name']:trim($r['first_name'].' '.$r['last_name']); $floorLabel=$rowFloorLabel($r); ?><tr><td><a href="../lease_view.php?id=<?=$r['lease_id']?>"><?=h($r['lease_number'])?></a><br><small class="text-muted"><?=h($r['lease_status'])?></small></td><td><?=h($tn)?></td><td><?=h($r['building_name'].' / '.$floorLabel.' / '.$r['unit_number'])?></td><td><?=h($r['cheque_number'] ?: ('Installment #'.$r['installment_id']))?></td><td><?=h($r['installment_date'])?></td><td><?=h($r['status'])?></td><td class="text-end"><?=m($r['amount'])?></td><td class="text-end"><?=m($r['total_paid'])?></td><td class="text-end text-danger"><?=m($r['outstanding'])?></td></tr><?php endforeach; ?><?php if(!$legacyRows): ?><tr><td colspan="9" class="text-center text-muted">No Legacy installment outstanding for this filter.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card card-round mb-4"><div class="card-header">Invoice Mode Outstanding Invoices</div><div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Invoice</th><th>Tenant</th><th>Building / Floor / Unit</th><th>Due Date</th><th class="text-end">Outstanding</th></tr></thead><tbody><?php foreach($invoiceRows as $r): ?><?php $tn=$r['tenant_type']==='company'?$r['company_name']:trim($r['first_name'].' '.$r['last_name']); $floorLabel=$rowFloorLabel($r); ?><tr><td><a href="../billing_invoice_view.php?id=<?=$r['id']?>"><?=h($r['invoice_number'])?></a><br><small class="text-muted"><?=h($r['lease_status'])?></small></td><td><?=h($tn)?></td><td><?=h($r['building_name'].' / '.$floorLabel.' / '.$r['unit_number'])?></td><td><?=h($r['due_date'])?></td><td class="text-end text-danger"><?=m($r['outstanding_amount'])?></td></tr><?php endforeach; ?><?php if(!$invoiceRows): ?><tr><td colspan="5" class="text-center text-muted">No Invoice Mode AR outstanding for this filter.</td></tr><?php endif; ?></tbody></table></div></div>
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
        var url = '../ajax_get_units.php?building_id=' + encodeURIComponent(buildingId);
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
        fetch('../ajax_floors.php?action=list&building_id=' + encodeURIComponent(buildingId))
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

    document.getElementById('outstandingsFilterForm')?.addEventListener('submit', function () {
        if (floorSel.disabled) floorSel.disabled = false;
        if (unitSel.disabled) unitSel.disabled = false;
        if (!buildingSel.value || buildingSel.value === '0') {
            floorSel.value = '0';
            unitSel.value = '0';
        }
    });
})();
</script>
<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
