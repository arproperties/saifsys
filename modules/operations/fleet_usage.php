<?php
// modules/operations/fleet_usage.php — how the Driver app is being used: trips, km and daily
// checks per driver and per vehicle, plus the things that need someone to act. Read-only.
// The numbers come from includes/fleet_usage_lib.php, which the Jarvis API uses too.

require_once __DIR__ . '/includes/ops_fleet.php';
require_once __DIR__ . '/includes/fleet_usage_lib.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$companies = hr_active_companies($conn);
$ready = fleet_tables_ready($conn);
$checksReady = false;

$companyId = (int)hr_selected_company_id($conn, $companies);
$from = hr_fleet_date_param('from', date('Y-m-d', strtotime('-30 days')));
$to = hr_fleet_date_param('to', date('Y-m-d'));
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$drivers = [];
$vehicleRows = [];
$weeks = [];
$longTrips = [];
$openProblems = [];
$totals = ['trips' => 0, 'meters' => 0, 'seconds' => 0, 'ended' => 0, 'zero' => 0, 'days' => 0, 'days_checked' => 0];
$activeDrivers = $vehiclesUsed = 0;
$checkRate = $zeroRate = null;
$maxWeekTrips = $maxWeekMeters = 1;
$checkItems = [];
if ($ready) {
    // The numbers live in includes/fleet_usage_lib.php, shared with the PDF and the Jarvis API.
    extract(fleet_usage_report($conn, $from, $to, $companyId));
}

$query = fn(array $extra = []) => http_build_query(array_merge([
    'company_id' => $companyId ?: null,
    'from' => $from,
    'to' => $to,
], $extra));
$back = 'fleet_usage?' . $query();

if ($ready && !empty($_GET['pdf'])) {
    fleet_usage_pdf(compact(
        'from', 'to', 'companyId', 'companies', 'totals', 'drivers', 'vehicleRows', 'weeks', 'longTrips',
        'openProblems', 'activeDrivers', 'vehiclesUsed', 'checkRate', 'zeroRate', 'checksReady', 'maxWeekTrips'
    ));
}

/** Sends the report as a PDF download and stops. Same numbers as the page, laid out for A4. */
function fleet_usage_pdf(array $r): void
{
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $companyName = 'All companies';
    foreach ($r['companies'] as $c) {
        if ((int)$c['id'] === $r['companyId']) {
            $companyName = (string)$c['name'];
        }
    }
    $period = date('d M Y', strtotime($r['from'])) . ' – ' . date('d M Y', strtotime($r['to']));
    $red = 'color:#dc2626;font-weight:bold';

    ob_start(); ?>
<style>
body{font-family:dejavusans;font-size:9pt;color:#1f2933}
h1{font-size:18pt;color:#0f4c81;margin:0}
h2{font-size:11.5pt;color:#0f4c81;margin:14px 0 5px;border-bottom:1.5px solid #d9e6f2;padding-bottom:2px}
.sub{color:#6b7280;font-size:8.5pt}
table.t{width:100%;border-collapse:collapse}
table.t th{background:#0f4c81;color:#fff;text-align:left;padding:4px 5px;font-size:8pt}
table.t td{padding:4px 5px;border-bottom:0.5px solid #e6ebf0;font-size:8.5pt}
.r{text-align:right}
.k td{border:0.5px solid #dde3ea;background:#f8fafc;padding:6px 8px;width:25%}
.kv{font-size:14pt;font-weight:bold;color:#0f4c81}
.kl{font-size:7.5pt;color:#6b7280}
.muted{color:#7b8794}
</style>
<h1>Driver App — Usage Report</h1>
<div class="sub"><?= $e($period) ?> · <?= $e($companyName) ?> · made <?= date('d M Y H:i') ?></div>

<table class="k" style="width:100%;border-collapse:separate;border-spacing:4px;margin-top:8px">
  <tr>
    <td><div class="kv"><?= number_format($r['totals']['trips']) ?></div><div class="kl">trips</div></td>
    <td><div class="kv"><?= number_format($r['totals']['meters'] / 1000) ?> km</div><div class="kl">distance tracked</div></td>
    <td><div class="kv"><?= number_format($r['totals']['seconds'] / 3600) ?> h</div><div class="kl">time on trips</div></td>
    <td><div class="kv"><?= $r['activeDrivers'] ?> of <?= count($r['drivers']) ?></div><div class="kl">drivers active (trip in the last <?= FLEET_USAGE_STOPPED_DAYS ?> days)</div></td>
  </tr>
  <tr>
    <td><div class="kv"><?= $r['vehiclesUsed'] ?> of <?= count($r['vehicleRows']) ?></div><div class="kl">vehicles used</div></td>
    <td><div class="kv"><?= $r['checkRate'] === null ? '—' : $r['checkRate'] . '%' ?></div><div class="kl">driving days with a daily check</div></td>
    <td><div class="kv" style="<?= $r['zeroRate'] !== null && $r['zeroRate'] > FLEET_USAGE_ZERO_KM_RED ? $red : '' ?>"><?= $r['zeroRate'] === null ? '—' : $r['zeroRate'] . '%' ?></div><div class="kl">trips that saved 0 km</div></td>
    <td><div class="kv" style="<?= $r['openProblems'] ? $red : '' ?>"><?= count($r['openProblems']) ?></div><div class="kl">check problems not seen yet</div></td>
  </tr>
</table>

<?php if ($r['weeks']): ?>
<h2>By week</h2>
<table class="t">
  <tr><th style="width:16%">Week starting</th><th class="r" style="width:10%">Trips</th><th style="width:56%"></th><th class="r" style="width:18%">km</th></tr>
  <?php foreach ($r['weeks'] as $week => $w): ?>
  <tr>
    <td><?= date('d M Y', strtotime($week)) ?></td>
    <td class="r"><?= number_format($w['trips']) ?></td>
    <?php // mPDF ignores a sized div in a cell; a nested table with a width is what it draws as a bar. ?>
    <td><table style="border-collapse:collapse" width="<?= max(1, round(100 * $w['trips'] / $r['maxWeekTrips'])) ?>%"><tr><td style="background:#2563eb;height:8px;padding:0;border:0;font-size:1pt">&nbsp;</td></tr></table></td>
    <td class="r"><?= number_format($w['meters'] / 1000) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<h2>By driver</h2>
<table class="t">
  <tr><th>Driver</th><th class="r">Trips</th><th class="r">km</th><th class="r">Hours</th><th class="r">Days</th><th class="r">Last 7 days</th><th class="r">0 km trips</th><th>Daily check</th><th>Status</th></tr>
  <?php if (!$r['drivers']): ?><tr><td colspan="9" class="muted">No trips in this period.</td></tr><?php endif; ?>
  <?php foreach ($r['drivers'] as $d):
      $zeroPct = $d['ended'] > 0 ? (int)round(100 * $d['zero'] / $d['ended']) : null; ?>
  <tr>
    <td><b><?= $e($d['name']) ?></b></td>
    <td class="r"><?= number_format($d['trips']) ?></td>
    <td class="r"><?= number_format($d['meters'] / 1000) ?></td>
    <td class="r"><?= number_format($d['seconds'] / 3600, 1) ?></td>
    <td class="r"><?= count($d['days']) ?></td>
    <td class="r"><?= $d['recent'] ?></td>
    <td class="r" style="<?= $zeroPct !== null && $zeroPct > FLEET_USAGE_ZERO_KM_RED ? $red : '' ?>"><?= $zeroPct === null ? '—' : $zeroPct . '%' ?></td>
    <td><?= $d['check_days'] ? $d['checked_days'] . ' of ' . $d['check_days'] . ' days' : '—' ?></td>
    <td style="<?= $d['stopped'] ? $red : 'color:#059669' ?>"><?= $d['stopped'] ? 'Stopped · last trip ' . date('d M', strtotime((string)$d['last_at'])) : 'Active' ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<h2>By vehicle</h2>
<table class="t">
  <tr><th>Vehicle</th><th>Company</th><th class="r">Trips</th><th class="r">km</th><th>Main driver(s)</th><th>Last used</th></tr>
  <?php foreach ($r['vehicleRows'] as $v): ?>
  <tr>
    <td><b><?= $e($v['plate_no']) ?></b> <span class="muted"><?= $e($v['name']) ?></span></td>
    <td><?= $e($v['company_name']) ?></td>
    <td class="r"><?= number_format($v['trips']) ?></td>
    <td class="r"><?= $v['trips'] ? number_format($v['meters'] / 1000) : '—' ?></td>
    <td><?= $e(implode(', ', array_filter($v['main_drivers']))) ?: '—' ?></td>
    <td><?php
        if ($v['status'] === 'inactive') echo '<span class="muted">Inactive</span>';
        elseif ($v['last_at'] === null) echo '<span class="muted">Never used</span>';
        elseif (!$v['trips']) echo '<span style="color:#d97706">Not used in this period</span> <span class="muted">(last ' . date('d M Y', strtotime((string)$v['last_at'])) . ')</span>';
        else echo date('d M Y', strtotime((string)$v['last_at']));
    ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<?php if ($r['checksReady']): ?>
<h2>Check problems not seen yet (any date)</h2>
<table class="t">
  <tr><th style="width:14%">Date</th><th style="width:20%">Driver</th><th style="width:12%">Vehicle</th><th>Problem</th></tr>
  <?php if (!$r['openProblems']): ?><tr><td colspan="4" class="muted">Nothing waiting. Every reported problem has been seen.</td></tr><?php endif; ?>
  <?php foreach ($r['openProblems'] as $p): ?>
  <tr>
    <td><?= date('d M Y', strtotime((string)$p['check_date'])) ?></td>
    <td><?= $e($p['driver_name']) ?></td>
    <td><?= $e($p['plate_no']) ?></td>
    <td><?= nl2br($e($p['notes'])) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<h2>Trips longer than <?= FLEET_USAGE_LONG_TRIP_HOURS ?> hours</h2>
<table class="t">
  <tr><th>Started</th><th>Driver</th><th>Vehicle</th><th>Time</th><th class="r">Distance</th></tr>
  <?php if (!$r['longTrips']): ?><tr><td colspan="5" class="muted">None in this period.</td></tr><?php endif; ?>
  <?php $plates = array_column($r['vehicleRows'], 'plate_no', 'id');
  foreach ($r['longTrips'] as $t): ?>
  <tr>
    <td><?= date('d M Y H:i', strtotime((string)$t['started_at'])) ?></td>
    <td><?= $e($t['driver_name']) ?></td>
    <td><?= $e($plates[(int)$t['vehicle_id']] ?? '') ?></td>
    <td><?= $e(fleet_format_seconds((int)$t['seconds'])) ?><?= $t['ended_at'] === null ? ' (still running)' : '' ?></td>
    <td class="r"><?= $e(fleet_format_km((int)$t['distance_m'])) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<p class="muted" style="font-size:7.5pt;margin-top:10px">"0 km" means the trip ran but no GPS distance was saved — usually the phone was locked or the app was closed during the trip.</p>
<?php
    $html = ob_get_clean();

    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
    $tmp = dirname(__DIR__, 2) . '/uploads/temp/mpdf';
    if (!is_dir($tmp)) {
        @mkdir($tmp, 0777, true);
    }
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 12, 'margin_bottom' => 14,
        'tempDir' => is_writable($tmp) ? $tmp : sys_get_temp_dir(),
    ]);
    $mpdf->SetTitle('Driver App Usage Report');
    $mpdf->SetFooter('Driver App Usage Report · ' . $period . '||Page {PAGENO} of {nbpg}');
    $mpdf->WriteHTML($html);
    $mpdf->Output('Driver_App_Usage_' . $r['from'] . '_to_' . $r['to'] . '.pdf', 'D');
    exit;
}

$pageTitle = 'Usage Report';
$pageStyles = '
.usage-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem}
@media (max-width:991px){.usage-kpis{grid-template-columns:repeat(2,1fr)}}
.usage-kpi{border:1px solid #e5e7eb;border-radius:12px;padding:.75rem 1rem;background:#fafafa}
.usage-kpi .v{font-size:1.5rem;font-weight:700;line-height:1.2;color:var(--primary)}
.usage-kpi .l{font-size:.78rem;color:#6b7280}
.usage-kpi.alert-kpi .v{color:#dc2626}
.usage-bars{display:flex;align-items:flex-end;gap:6px;height:150px;padding-top:18px}
.usage-bar{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;min-width:0}
.usage-bar .b{width:100%;max-width:38px;background:#2563eb;border-radius:4px 4px 0 0;min-height:2px}
.usage-bar .n{font-size:.72rem;font-weight:600;color:#374151;margin-bottom:2px;white-space:nowrap}
.usage-bar-labels{display:flex;gap:6px;border-top:1px solid #e5e7eb;padding-top:4px}
.usage-bar-labels span{flex:1;text-align:center;font-size:.7rem;color:#6b7280;white-space:nowrap;overflow:hidden;min-width:0}
.usage-zero-red{color:#dc2626;font-weight:700}
';
require_once __DIR__ . '/includes/ops_layout_header.php';

echo hr_ui_page_header(
    'Usage Report',
    'How the Driver app is used: trips, km and daily checks per driver and vehicle, and what needs someone to act.',
    [['label' => 'Operations', 'href' => $opsBase . '/index.php'], ['label' => 'Usage Report']],
    ($ready ? '<a class="btn btn-primary me-2" href="fleet_usage?' . htmlspecialchars($query(['pdf' => 1])) . '"><i class="bi bi-file-earmark-pdf me-1"></i>Download PDF</a>' : '')
    . '<a class="btn btn-outline-primary" href="fleet_history?' . htmlspecialchars($query()) . '"><i class="bi bi-clock-history me-1"></i>Trip History</a>'
);
?>

<?php hr_fleet_flash(); ?>

<?php if (!$ready): ?>
  <div class="alert alert-warning">Vehicle tracking is not set up on this server yet. Run <code>migrations/fleet_tracking.sql</code>.</div>
<?php else: ?>
  <div class="hr-settings-card">
    <div class="settings-header">
      <form class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label small mb-1">Company</label>
          <select name="company_id" class="form-select form-select-sm">
            <option value="">All companies</option>
            <?php foreach ($companies as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $companyId === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">From</label>
          <input type="date" name="from" class="form-control form-control-sm" value="<?= $from ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">To</label>
          <input type="date" name="to" class="form-control form-control-sm" value="<?= $to ?>">
        </div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Show report</button></div>
      </form>
    </div>
    <div class="card-body">
      <div class="usage-kpis">
        <div class="usage-kpi"><div class="v"><?= number_format($totals['trips']) ?></div><div class="l">trips</div></div>
        <div class="usage-kpi"><div class="v"><?= number_format($totals['meters'] / 1000) ?> km</div><div class="l">distance tracked</div></div>
        <div class="usage-kpi"><div class="v"><?= number_format($totals['seconds'] / 3600) ?> h</div><div class="l">time on trips</div></div>
        <div class="usage-kpi"><div class="v"><?= $activeDrivers ?> <span class="fs-6 text-muted">of <?= count($drivers) ?></span></div><div class="l">drivers active (trip in the last <?= FLEET_USAGE_STOPPED_DAYS ?> days)</div></div>
        <div class="usage-kpi"><div class="v"><?= $vehiclesUsed ?> <span class="fs-6 text-muted">of <?= count($vehicleRows) ?></span></div><div class="l">vehicles used</div></div>
        <div class="usage-kpi"><div class="v"><?= $checkRate === null ? '—' : $checkRate . '%' ?></div><div class="l">driving days with a daily check</div></div>
        <div class="usage-kpi <?= $zeroRate !== null && $zeroRate > FLEET_USAGE_ZERO_KM_RED ? 'alert-kpi' : '' ?>"><div class="v"><?= $zeroRate === null ? '—' : $zeroRate . '%' ?></div><div class="l">trips that saved 0 km</div></div>
        <div class="usage-kpi <?= $openProblems ? 'alert-kpi' : '' ?>"><div class="v"><?= count($openProblems) ?></div><div class="l">check problems not seen yet (any date)</div></div>
      </div>
    </div>
  </div>

  <?php if (count($weeks) > 1): ?>
  <div class="row g-3 mb-3">
    <?php foreach ([['Trips per week', 'trips', $maxWeekTrips], ['Kilometres per week', 'meters', $maxWeekMeters]] as [$chartTitle, $key, $max]): ?>
    <div class="col-lg-6">
      <div class="hr-settings-card mb-0 h-100">
        <div class="settings-header"><?= $chartTitle ?> <span class="text-muted fw-normal small">· week starting Monday</span></div>
        <div class="card-body">
          <div class="usage-bars">
            <?php foreach ($weeks as $week => $w):
                $value = $key === 'meters' ? (int)round($w['meters'] / 1000) : $w['trips'];
                $tip = 'Week of ' . date('d M', strtotime($week)) . ': ' . $w['trips'] . ' trips, ' . number_format($w['meters'] / 1000) . ' km'; ?>
              <div class="usage-bar" title="<?= htmlspecialchars($tip) ?>">
                <span class="n"><?= number_format($value) ?></span>
                <div class="b" style="height:<?= round(100 * $w[$key] / $max, 1) ?>%"></div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="usage-bar-labels">
            <?php foreach (array_keys($weeks) as $week): ?><span><?= date('d M', strtotime($week)) ?></span><?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="hr-settings-card">
    <div class="settings-header">By driver</div>
    <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table align-middle mb-0 small">
        <thead class="table-light">
          <tr>
            <th>Driver</th>
            <th class="text-end">Trips</th>
            <th class="text-end">km</th>
            <th class="text-end">Hours</th>
            <th class="text-end">Days driven</th>
            <th class="text-end">Trips last 7 days</th>
            <th class="text-end" title="The trip ran but no GPS distance was saved — usually the phone was locked or the app closed">0 km trips</th>
            <th>Daily check</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$drivers): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No trips in this period.</td></tr>
        <?php endif; ?>
        <?php foreach ($drivers as $uid => $d):
            $zeroPct = $d['ended'] > 0 ? (int)round(100 * $d['zero'] / $d['ended']) : null; ?>
          <tr>
            <td><a class="fw-semibold" href="fleet_history?<?= htmlspecialchars($query(['driver_user_id' => $uid])) ?>"><?= htmlspecialchars($d['name']) ?></a></td>
            <td class="text-end"><?= number_format($d['trips']) ?></td>
            <td class="text-end"><?= number_format($d['meters'] / 1000) ?></td>
            <td class="text-end"><?= number_format($d['seconds'] / 3600, 1) ?></td>
            <td class="text-end"><?= count($d['days']) ?></td>
            <td class="text-end"><?= $d['recent'] ?></td>
            <td class="text-end <?= $zeroPct !== null && $zeroPct > FLEET_USAGE_ZERO_KM_RED ? 'usage-zero-red' : '' ?>"><?= $zeroPct === null ? '—' : $zeroPct . '%' ?></td>
            <td>
              <?php if ($d['check_days'] === 0): ?>
                <span class="text-muted">—</span>
              <?php else:
                  $full = $d['checked_days'] === $d['check_days']; ?>
                <span class="hr-pill <?= $full ? 'hr-pill-ok' : ($d['checked_days'] ? 'hr-pill-warn' : 'hr-pill-fail') ?>"><?= $d['checked_days'] ?> of <?= $d['check_days'] ?> days</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($d['stopped']): ?>
                <span class="hr-pill hr-pill-fail" title="No trip for <?= FLEET_USAGE_STOPPED_DAYS ?>+ days">Stopped · last trip <?= date('d M', strtotime((string)$d['last_at'])) ?></span>
              <?php else: ?>
                <span class="hr-pill hr-pill-ok">Active</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header">By vehicle</div>
    <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table align-middle mb-0 small">
        <thead class="table-light">
          <tr>
            <th>Vehicle</th>
            <th>Company</th>
            <th class="text-end">Trips</th>
            <th class="text-end">km</th>
            <th>Main driver(s)</th>
            <th>Last used</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$vehicleRows): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No vehicles registered.</td></tr>
        <?php endif; ?>
        <?php foreach ($vehicleRows as $v): ?>
          <tr>
            <td>
              <a href="vehicle_view?<?= htmlspecialchars(http_build_query(['id' => (int)$v['id'], 'from' => $from, 'to' => $to])) ?>" class="fw-semibold"><?= htmlspecialchars((string)$v['plate_no']) ?></a>
              <?php if (!empty($v['name'])): ?><div class="text-muted"><?= htmlspecialchars((string)$v['name']) ?></div><?php endif; ?>
            </td>
            <td><?= htmlspecialchars((string)$v['company_name']) ?></td>
            <td class="text-end"><?= number_format($v['trips']) ?></td>
            <td class="text-end"><?= $v['trips'] ? number_format($v['meters'] / 1000) : '—' ?></td>
            <td><?= htmlspecialchars(implode(', ', array_filter($v['main_drivers']))) ?: '<span class="text-muted">—</span>' ?></td>
            <td>
              <?php if ($v['status'] === 'inactive'): ?>
                <span class="hr-pill hr-pill-muted">Inactive</span>
              <?php elseif ($v['last_at'] === null): ?>
                <span class="hr-pill hr-pill-muted">Never used</span>
              <?php elseif (!$v['trips']): ?>
                <span class="hr-pill hr-pill-warn">Not used in this period</span>
                <div class="text-muted">last <?= date('d M Y', strtotime((string)$v['last_at'])) ?></div>
              <?php else: ?>
                <?= date('d M Y', strtotime((string)$v['last_at'])) ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($checksReady): ?>
  <div class="hr-settings-card">
    <div class="settings-header d-flex justify-content-between align-items-center">
      <span>Check problems not seen yet <span class="text-muted fw-normal small">· any date</span></span>
      <?php if ($openProblems): ?>
        <a class="btn btn-sm btn-outline-danger" href="fleet_checks?problems=1&from=<?= date('Y-m-d', strtotime('-90 days')) ?>">Open Daily Checks</a>
      <?php endif; ?>
    </div>
    <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table align-middle mb-0 small">
        <thead class="table-light">
          <tr><th>Date</th><th>Driver</th><th>Vehicle</th><th>Problem</th></tr>
        </thead>
        <tbody>
        <?php if (!$openProblems): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">Nothing waiting. Every reported problem has been seen.</td></tr>
        <?php endif; ?>
        <?php foreach ($openProblems as $p):
            $answers = json_decode((string)$p['answers'], true) ?: [];
            $what = [];
            foreach ($answers as $key => $answer) {
                if ($answer === 'problem') {
                    $what[] = $checkItems[$key]['label'] ?? str_replace('_', ' ', (string)$key);
                }
            } ?>
          <tr>
            <td class="text-nowrap"><?= date('d M Y', strtotime((string)$p['check_date'])) ?></td>
            <td><?= htmlspecialchars((string)$p['driver_name']) ?></td>
            <td><a href="vehicle_view?id=<?= (int)$p['vehicle_id'] ?>" class="fw-semibold"><?= htmlspecialchars((string)$p['plate_no']) ?></a></td>
            <td><?php // The app writes each problem item into the notes, so show those; the item list is the fallback. ?>
              <?= trim((string)$p['notes']) !== '' ? nl2br(htmlspecialchars((string)$p['notes'])) : htmlspecialchars(implode(' · ', $what)) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="hr-settings-card">
    <div class="settings-header">Trips longer than <?= FLEET_USAGE_LONG_TRIP_HOURS ?> hours <span class="text-muted fw-normal small">· usually the driver forgot to press Stop</span></div>
    <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table align-middle mb-0 small">
        <thead class="table-light">
          <tr><th>Started</th><th>Driver</th><th>Vehicle</th><th>Time</th><th class="text-end">Distance</th><th class="text-end"></th></tr>
        </thead>
        <tbody>
        <?php if (!$longTrips): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">None in this period.</td></tr>
        <?php endif; ?>
        <?php
        $plates = array_column($vehicleRows, 'plate_no', 'id');
        foreach ($longTrips as $t): ?>
          <tr>
            <td class="text-nowrap"><?= date('d M Y H:i', strtotime((string)$t['started_at'])) ?></td>
            <td><?= htmlspecialchars((string)$t['driver_name']) ?></td>
            <td><?= htmlspecialchars((string)($plates[(int)$t['vehicle_id']] ?? '')) ?></td>
            <td>
              <?= htmlspecialchars(fleet_format_seconds((int)$t['seconds'])) ?>
              <?php if ($t['ended_at'] === null): ?><span class="badge text-bg-success ms-1">Still running</span><?php endif; ?>
            </td>
            <td class="text-end"><?= htmlspecialchars(fleet_format_km((int)$t['distance_m'])) ?></td>
            <td class="text-end">
              <a href="fleet_trip?<?= htmlspecialchars(http_build_query(['id' => (int)$t['id'], 'back' => $back])) ?>"
                 class="btn btn-sm btn-outline-secondary py-0 px-1" title="Investigate this trip" aria-label="Investigate this trip"><i class="bi bi-search"></i></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/ops_layout_footer.php';
