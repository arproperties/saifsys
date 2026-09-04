<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_shop_rental_helpers.php';
require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_shop_require_company_id($conn);
$snap = co_shop_control_center_snapshot_v2($conn, $cid);
$u = $snap['units'];
$c = $snap['contracts'];
$f = $snap['finance'];
$p = $snap['pending'];
$tr = $snap['trends'];
$fc = $snap['forecast'];
$pen = $snap['penalty'];
$leasable = (int)$u['occupied'] + (int)$u['available'];
$occPct = (float)$u['occupancy_pct'];
$coUiV2 = true;
$pageTitle = 'Commercial Leasing Control Center';
require_once __DIR__ . '/includes/construction_layout_header.php';

$incomeTotal = max(0.01,
    (float)($f['invoiced'] ?? 0)
    + (float)($snap['commission']['invoiced'] ?? 0)
    + (float)($pen['invoiced'] ?? 0)
);
$rentShare = round(100 * (float)($f['invoiced'] ?? 0) / $incomeTotal, 1);
$commShare = round(100 * (float)($snap['commission']['invoiced'] ?? 0) / $incomeTotal, 1);
$penShare = round(100 * (float)($pen['invoiced'] ?? 0) / $incomeTotal, 1);
?>
<div class="co-page-header">
    <div>
        <div class="co-crumb">
            <a href="index.php">Construction</a><span>/</span>
            <span>Commercial Leasing</span>
        </div>
        <h1>Construction Control Center</h1>
        <p class="co-page-sub">Madar Al Wadi — occupancy, finance &amp; forecast · contract-level money · as of <?= h($snap['as_of']) ?></p>
    </div>
    <div class="co-page-actions">
        <a class="btn btn-outline-secondary btn-sm" href="reports/shop_lease_maturity.php"><i data-lucide="calendar-range" class="me-1" style="width:14px;height:14px"></i> Maturity</a>
        <a class="btn btn-outline-secondary btn-sm" href="reports/shop_occupancy.php"><i data-lucide="layout-grid" class="me-1" style="width:14px;height:14px"></i> Occupancy</a>
        <a class="btn btn-outline-secondary btn-sm" href="shop_rental_contracts.php"><i data-lucide="file-text" class="me-1" style="width:14px;height:14px"></i> Contracts</a>
        <a class="btn btn-primary btn-sm" href="shop_rental_contract_add.php"><i data-lucide="plus" class="me-1" style="width:14px;height:14px"></i> New Contract</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="co-kpi">
            <?= co_ui_kpi_ring($occPct) ?>
            <div class="co-kpi-icon"><i data-lucide="pie-chart" style="width:18px;height:18px"></i></div>
            <div class="lbl">Occupancy Rate</div>
            <div class="val gold"><?= h(number_format($occPct, 1)) ?>%</div>
            <div class="sub"><?= (int)$u['occupied'] ?> of <?= $leasable ?> leasable shops</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="co-kpi">
            <div class="co-kpi-icon teal"><i data-lucide="store" style="width:18px;height:18px"></i></div>
            <div class="lbl">Occupied Shops</div>
            <div class="val"><?= (int)$u['occupied'] ?></div>
            <div class="sub"><?= (int)$u['available'] ?> vacant · <?= (int)$u['inactive'] ?> inactive</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="co-kpi">
            <div class="co-kpi-icon"><i data-lucide="file-check-2" style="width:18px;height:18px"></i></div>
            <div class="lbl">Active Contracts</div>
            <div class="val"><?= (int)$c['active'] ?></div>
            <div class="sub"><?= (int)$c['draft'] ?> draft · <?= (int)($c['pending_renewal_drafts'] ?? 0) ?> renewals pending</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="co-kpi">
            <div class="co-kpi-icon danger"><i data-lucide="alarm-clock" style="width:18px;height:18px"></i></div>
            <div class="lbl">Expiring Soon</div>
            <div class="val danger"><?= (int)$c['expiring_soon'] ?></div>
            <div class="sub">Within 60 days · <?= (int)$c['past_end_active'] ?> past end</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="co-kpi"><div class="co-kpi-icon"><i data-lucide="banknote" style="width:18px;height:18px"></i></div><div class="lbl">Outstanding Rent</div><div class="val gold" style="font-size:1.15rem"><?= co_format_money($f['outstanding']) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="co-kpi"><div class="co-kpi-icon danger"><i data-lucide="triangle-alert" style="width:18px;height:18px"></i></div><div class="lbl">Overdue Rent</div><div class="val danger" style="font-size:1.15rem"><?= co_format_money($f['overdue']) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="co-kpi"><div class="co-kpi-icon info"><i data-lucide="lock" style="width:18px;height:18px"></i></div><div class="lbl">Deposits Held</div><div class="val" style="font-size:1.15rem"><?= co_format_money($f['deposits_held']) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="co-kpi"><div class="co-kpi-icon teal"><i data-lucide="wallet" style="width:18px;height:18px"></i></div><div class="lbl">Tenant Credit</div><div class="val teal" style="font-size:1.15rem"><?= co_format_money($f['tenant_credit']) ?></div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-2"><div class="co-kpi"><div class="lbl">Deferred</div><div class="val" style="font-size:1rem"><?= co_format_money($f['deferred']) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="co-kpi"><div class="lbl">Recognized MTD</div><div class="val" style="font-size:1rem"><?= co_format_money($f['recognized_mtd']) ?></div><div class="sub">Life <?= co_format_money($f['recognized_lifetime']) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="co-kpi"><div class="lbl">Commission</div><div class="val" style="font-size:1rem"><?= co_format_money($snap['commission']['invoiced'] ?? 0) ?></div><div class="sub">OS <?= co_format_money($snap['commission']['outstanding'] ?? 0) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="co-kpi"><div class="lbl">Penalty (4150)</div><div class="val" style="font-size:1rem"><?= co_format_money($pen['invoiced'] ?? 0) ?></div><div class="sub"><?= (int)($pen['count'] ?? 0) ?> inv</div></div></div>
    <div class="col-6 col-md-2"><div class="co-kpi"><div class="lbl">Collection Rate</div><div class="val teal" style="font-size:1rem"><?= h(number_format((float)$f['collection_rate_pct'], 1)) ?>%</div></div></div>
    <div class="col-6 col-md-2"><div class="co-kpi"><div class="lbl">Renewed / Term / Exp</div><div class="val" style="font-size:1rem"><?= (int)($c['renewed'] ?? 0) ?> / <?= (int)($c['terminated'] ?? 0) ?> / <?= (int)($c['expired'] ?? 0) ?></div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card card-round h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Monthly Revenue vs Collections</strong>
                <span class="text-muted small">Last 12 months</span>
            </div>
            <div class="card-body"><div class="co-chart-wrap"><canvas id="chartRevCol"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card card-round h-100">
            <div class="card-header"><strong>Income Breakdown</strong></div>
            <div class="card-body"><div class="co-chart-wrap"><canvas id="chartIncome"></canvas></div>
                <div class="small text-muted mt-2">Shop rent <?= h((string)$rentShare) ?>% · Commission <?= h((string)$commShare) ?>% · Penalty <?= h((string)$penShare) ?>%</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card card-round h-100">
            <div class="card-header"><strong>Forecast (90 days)</strong></div>
            <div class="card-body">
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-25"><span class="text-muted">Collections 30d</span><strong><?= co_format_money($fc['collections_30']) ?></strong></div>
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-25"><span class="text-muted">Collections 60d</span><strong><?= co_format_money($fc['collections_60']) ?></strong></div>
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-25"><span class="text-muted">Collections 90d</span><strong><?= co_format_money($fc['collections_90']) ?></strong></div>
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-25"><span class="text-muted">Expected revenue</span><strong><?= co_format_money($fc['expected_revenue_90']) ?></strong></div>
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-25"><span class="text-muted">Expected VAT</span><strong><?= co_format_money($fc['expected_vat_90']) ?></strong></div>
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-25"><span class="text-muted">Commission</span><strong><?= co_format_money($fc['expected_commission']) ?></strong></div>
                <div class="d-flex justify-content-between py-1"><span class="text-muted">Expiries 30/60/90</span><strong><?= (int)$fc['expiries']['d30'] ?> / <?= (int)$fc['expiries']['d60'] ?> / <?= (int)$fc['expiries']['d90'] ?></strong></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-round h-100">
            <div class="card-header"><strong>Pending Work</strong></div>
            <div class="card-body">
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-25"><span class="text-muted">Pending invoices</span><strong><?= (int)$p['invoices'] ?></strong></div>
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-25"><span class="text-muted">Pending deposits</span><strong><?= (int)$p['deposits'] ?></strong></div>
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-25"><span class="text-muted">Pending recognition</span><strong><?= (int)$p['recognition'] ?></strong></div>
                <div class="d-flex justify-content-between py-1"><span class="text-muted">Pending commission</span><strong><?= (int)($p['commission'] ?? 0) ?></strong></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-round h-100">
            <div class="card-header"><strong>Reports &amp; Tools</strong></div>
            <div class="card-body d-flex flex-wrap gap-2">
                <a class="cc-link" href="reports/shop_lease_maturity.php">Lease Maturity</a>
                <a class="cc-link" href="reports/shop_occupancy.php">Occupancy</a>
                <a class="cc-link" href="reports/shop_profitability.php">Shop Profitability</a>
                <a class="cc-link" href="reports/shop_tenant_profitability.php">Tenant Profitability</a>
                <a class="cc-link" href="reports/shop_deposit_liability.php">Deposit Liability</a>
                <a class="cc-link" href="reports/shop_deferred_analysis.php">Deferred Analysis</a>
                <a class="cc-link" href="reports/shop_forecast.php">Forecast</a>
                <a class="cc-link" href="reports/shop_termination_penalty.php">Penalty Income</a>
                <a class="cc-link" href="reports/shop_tenant_commission.php">Commission</a>
                <a class="cc-link" href="reports/shop_key_money.php">Key Money</a>
                <a class="cc-link" href="reports/deferred_rent_recognition.php">Recognition</a>
            </div>
        </div>
    </div>
</div>

<div class="card card-round mb-4">
    <div class="card-header"><strong>Quick Actions</strong></div>
    <div class="card-body">
        <div class="co-quick-actions">
            <a class="co-qa" href="shop_rental_contract_add.php"><i data-lucide="file-plus-2" style="width:18px;height:18px"></i> New Contract</a>
            <a class="co-qa" href="client_invoices.php?source_type=shop_rental"><i data-lucide="receipt" style="width:18px;height:18px"></i> Shop Invoices</a>
            <a class="co-qa" href="client_payments.php"><i data-lucide="banknote" style="width:18px;height:18px"></i> Record Payment</a>
            <a class="co-qa" href="reports/deferred_rent_recognition.php"><i data-lucide="refresh-cw" style="width:18px;height:18px"></i> Recognize Rent</a>
            <a class="co-qa" href="shop_units.php"><i data-lucide="store" style="width:18px;height:18px"></i> Shop Units</a>
        </div>
    </div>
</div>

<div class="card card-round co-table-shell">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Active / Expiring Contracts</strong>
        <span class="text-muted small">≤ 60 days</span>
    </div>
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light"><tr><th>Contract</th><th>Tenant</th><th>Shops</th><th>End</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($snap['expiring'] as $row):
                $past = $row['end_date'] < $snap['as_of'];
            ?>
                <tr>
                    <td><strong><?= h($row['contract_number']) ?></strong></td>
                    <td><?= h($row['client_name']) ?></td>
                    <td><?= h($row['shops_label'] ?? '') ?></td>
                    <td class="<?= $past ? 'text-danger fw-semibold' : '' ?>"><?= h($row['end_date']) ?><?= $past ? ' · past end' : '' ?></td>
                    <td><?= co_ui_status_pill((string)$row['status']) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="shop_rental_contract_view.php?id=<?= (int)$row['id'] ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$snap['expiring']): ?>
                <tr><td colspan="6"><div class="co-empty"><div class="ico"><i data-lucide="calendar-check" style="width:32px;height:32px"></i></div>No active contracts ending within 60 days.</div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const T = (window.CoUiV2 && window.CoUiV2.chartTheme) || { gold:'#d4af37', teal:'#10b981', danger:'#f87171', muted:'#94a3b8', grid:'rgba(212,175,55,0.08)' };
  const labels = <?= json_encode($tr['labels'] ?? []) ?>;
  const common = {
    responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{ position:'bottom', labels:{ color:T.muted, boxWidth:12 } } },
    scales:{
      x:{ ticks:{ color:T.muted }, grid:{ color:T.grid } },
      y:{ ticks:{ color:T.muted }, grid:{ color:T.grid } }
    }
  };
  new Chart(document.getElementById('chartRevCol'), {
    type:'bar',
    data:{ labels, datasets:[
      { label:'Rent Invoiced', data:<?= json_encode($tr['revenue'] ?? []) ?>, backgroundColor:T.gold, borderRadius:4 },
      { label:'Collections', data:<?= json_encode($tr['collections'] ?? []) ?>, backgroundColor:T.teal, borderRadius:4 }
    ]},
    options: common
  });
  new Chart(document.getElementById('chartIncome'), {
    type:'doughnut',
    data:{
      labels:['Shop Rent','Commission','Penalty'],
      datasets:[{
        data:[<?= (float)($f['invoiced'] ?? 0) ?>, <?= (float)($snap['commission']['invoiced'] ?? 0) ?>, <?= (float)($pen['invoiced'] ?? 0) ?>],
        backgroundColor:[T.gold, '#38bdf8', '#f59e0b'],
        borderWidth:0
      }]
    },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom', labels:{ color:T.muted } } } }
  });
})();
</script>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
