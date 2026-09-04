<?php
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_shop_rental_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = co_shop_require_company_id($conn);
$fc = co_shop_management_forecast($conn, $cid);
$tr = co_shop_financial_trends($conn, $cid, 6);

$rows = [
    ['metric' => 'Expected collections 30 days', 'amount' => $fc['collections_30']],
    ['metric' => 'Expected collections 60 days', 'amount' => $fc['collections_60']],
    ['metric' => 'Expected collections 90 days', 'amount' => $fc['collections_90']],
    ['metric' => 'Expected revenue (pending schedules 90d)', 'amount' => $fc['expected_revenue_90']],
    ['metric' => 'Expected VAT (pending schedules 90d)', 'amount' => $fc['expected_vat_90']],
    ['metric' => 'Expected commission (uninvoiced)', 'amount' => $fc['expected_commission']],
    ['metric' => 'Expected deposit refunds (expiring 90d)', 'amount' => $fc['expected_deposit_refunds_90']],
    ['metric' => 'Expiries in 30 days', 'amount' => $fc['expiries']['d30']],
    ['metric' => 'Expiries in 60 days', 'amount' => $fc['expiries']['d60']],
    ['metric' => 'Expiries in 90 days', 'amount' => $fc['expiries']['d90']],
    ['metric' => 'Renewal drafts pending', 'amount' => $fc['renewals']['drafts']],
];
co_report_export($rows, ['metric' => 'Metric', 'amount' => 'Value'], 'shop_forecast', 'Shop Revenue / Collection Forecast');
$pageTitle = 'Revenue & Collection Forecast';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div><h1 class="h4 mb-0">Revenue &amp; Collection Forecast</h1>
        <p class="text-muted mb-0">Operational forward view · as of <?= h($fc['as_of']) ?> · not a GL forecast</p></div>
    <div class="d-flex gap-2"><?= co_report_export_buttons() ?></div>
</div>
<div class="row g-3 mb-4">
<?php foreach (array_slice($rows, 0, 7) as $row): ?>
    <div class="col-md-4"><div class="card card-round"><div class="card-body">
        <div class="text-muted small"><?= h($row['metric']) ?></div>
        <div class="fs-5 fw-semibold"><?= is_numeric($row['amount']) && $row['amount'] > 100 ? co_format_money($row['amount']) : h((string)$row['amount']) ?></div>
    </div></div></div>
<?php endforeach; ?>
</div>
<div class="card card-round mb-4"><div class="card-header bg-white"><strong>Recent 6-month trend (invoiced / collected / recognized)</strong></div>
<div class="card-body"><canvas id="fcChart" height="120"></canvas></div></div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('fcChart'), {
  type:'line',
  data:{ labels: <?= json_encode($tr['labels']) ?>, datasets:[
    {label:'Invoiced', data:<?= json_encode($tr['revenue']) ?>, borderColor:'#1e40af', tension:.25, fill:false},
    {label:'Collections', data:<?= json_encode($tr['collections']) ?>, borderColor:'#059669', tension:.25, fill:false},
    {label:'Recognized', data:<?= json_encode($tr['recognized']) ?>, borderColor:'#6366f1', tension:.25, fill:false}
  ]},
  options:{ responsive:true, plugins:{ legend:{ position:'bottom' } } }
});
</script>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
