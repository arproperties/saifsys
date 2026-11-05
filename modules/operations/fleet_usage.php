<?php
// modules/operations/fleet_usage.php — how the Driver app is being used: trips, km and daily
// checks per driver and per vehicle, plus the things that need someone to act. Read-only.
// The queries live here on purpose: hr/includes/hr_fleet.php is shared with the Driver app's API.

require_once __DIR__ . '/includes/ops_fleet.php';
require_once dirname(__DIR__, 2) . '/hr/includes/hr_fleet_checks.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/** No trip for this many days = "Stopped". */
const FLEET_USAGE_STOPPED_DAYS = 7;
/** Above this share of 0 km trips the cell goes red. */
const FLEET_USAGE_ZERO_KM_RED = 30;
/** A trip this long usually means the driver forgot to press Stop. */
const FLEET_USAGE_LONG_TRIP_HOURS = 10;
/** Less than this counts as "0 km": the trip ran but no GPS distance was saved. */
const FLEET_USAGE_ZERO_KM_METERS = 100;

/** Trips that started in the period, oldest first. */
function fleet_usage_trips(PDO $conn, string $from, string $to, int $companyId): array
{
    $sql = "
        SELECT t.id, t.vehicle_id, t.driver_user_id, t.driver_name, t.started_at, t.ended_at, t.distance_m
        FROM fleet_trips t
        WHERE t.started_at BETWEEN ? AND ?" . ($companyId ? ' AND t.company_id = ?' : '') . "
        ORDER BY t.started_at";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $companyId ? [$companyId] : []));
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** driver_user_id => latest trip start, any date. Decides Active / Stopped. */
function fleet_usage_driver_last_trip(PDO $conn): array
{
    $rows = $conn->query("SELECT driver_user_id, MAX(started_at) AS last_at FROM fleet_trips GROUP BY driver_user_id")
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_column($rows, 'last_at', 'driver_user_id');
}

/** Every vehicle (in the company, if one is picked) with its latest trip, any date. */
function fleet_usage_vehicles(PDO $conn, int $companyId): array
{
    $sql = "
        SELECT v.id, v.plate_no, v.name, v.status, c.name AS company_name,
               (SELECT MAX(t.started_at) FROM fleet_trips t WHERE t.vehicle_id = v.id) AS last_at
        FROM fleet_vehicles v
        LEFT JOIN companies c ON c.id = v.company_id" . ($companyId ? ' WHERE v.company_id = ?' : '') . "
        ORDER BY v.plate_no";
    $stmt = $conn->prepare($sql);
    $stmt->execute($companyId ? [$companyId] : []);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Daily checks done in the period. */
function fleet_usage_checks(PDO $conn, string $from, string $to, int $companyId): array
{
    $sql = "
        SELECT driver_user_id, vehicle_id, check_date, problem_count
        FROM fleet_daily_checks
        WHERE check_date BETWEEN ? AND ?" . ($companyId ? ' AND company_id = ?' : '');
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$from, $to], $companyId ? [$companyId] : []));
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Problem checks nobody has marked as seen, any date, newest first. */
function fleet_usage_open_problems(PDO $conn, int $companyId): array
{
    $sql = "
        SELECT c.id, c.check_date, c.driver_name, c.answers, c.notes, c.vehicle_id, v.plate_no
        FROM fleet_daily_checks c
        JOIN fleet_vehicles v ON v.id = c.vehicle_id
        WHERE c.problem_count > 0 AND c.reviewed_at IS NULL" . ($companyId ? ' AND c.company_id = ?' : '') . "
        ORDER BY c.check_date DESC, c.id DESC
        LIMIT 50";
    $stmt = $conn->prepare($sql);
    $stmt->execute($companyId ? [$companyId] : []);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** The first day any check was saved: driving days before it can't be held against anyone. */
function fleet_usage_checks_since(PDO $conn): ?string
{
    $first = $conn->query("SELECT MIN(check_date) FROM fleet_daily_checks")->fetchColumn();
    return $first ? (string)$first : null;
}

$companies = hr_active_companies($conn);
$ready = fleet_tables_ready($conn);
$checksReady = $ready && fleet_checks_ready($conn);

$companyId = (int)hr_selected_company_id($conn, $companies);
$from = hr_fleet_date_param('from', date('Y-m-d', strtotime('-30 days')));
$to = hr_fleet_date_param('to', date('Y-m-d'));
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$today = date('Y-m-d');

$drivers = [];
$vehicleRows = [];
$weeks = [];
$longTrips = [];
$openProblems = [];
$totals = ['trips' => 0, 'meters' => 0, 'seconds' => 0, 'ended' => 0, 'zero' => 0, 'days' => 0, 'days_checked' => 0];

if ($ready) {
    $trips = fleet_usage_trips($conn, $from, $to, $companyId);
    $lastTrip = fleet_usage_driver_last_trip($conn);
    $checks = $checksReady ? fleet_usage_checks($conn, $from, $to, $companyId) : [];
    $checksSince = $checksReady ? fleet_usage_checks_since($conn) : null;
    $openProblems = $checksReady ? fleet_usage_open_problems($conn, $companyId) : [];
    $checkItems = $checksReady ? fleet_daily_checklist_items() : [];

    // Which driver did a check on which day (any vehicle).
    $checkedDay = [];
    $checkCount = [];
    foreach ($checks as $c) {
        $checkedDay[(int)$c['driver_user_id']][(string)$c['check_date']] = true;
        $checkCount[(int)$c['driver_user_id']] = ($checkCount[(int)$c['driver_user_id']] ?? 0) + 1;
    }

    // Monday of every week in the period, so empty weeks still show as a gap.
    for ($w = strtotime('monday this week', strtotime($from)); $w <= strtotime($to); $w = strtotime('+1 week', $w)) {
        $weeks[date('Y-m-d', $w)] = ['trips' => 0, 'meters' => 0];
    }

    $byVehicle = [];
    $now = time();
    foreach ($trips as $t) {
        $uid = (int)$t['driver_user_id'];
        $vid = (int)$t['vehicle_id'];
        $start = strtotime((string)$t['started_at']);
        $ended = $t['ended_at'] !== null;
        $seconds = max(0, ($ended ? strtotime((string)$t['ended_at']) : $now) - $start);
        $meters = (int)$t['distance_m'];
        $day = date('Y-m-d', $start);
        $zero = $ended && $meters < FLEET_USAGE_ZERO_KM_METERS;

        $d = &$drivers[$uid];
        $d ??= ['name' => '', 'trips' => 0, 'meters' => 0, 'seconds' => 0, 'ended' => 0, 'zero' => 0, 'days' => [], 'recent' => 0, 'vehicles' => []];
        $d['name'] = (string)$t['driver_name']; // trips are oldest first, so this ends on the latest name
        $d['trips']++;
        $d['meters'] += $meters;
        $d['seconds'] += $seconds;
        $d['ended'] += $ended ? 1 : 0;
        $d['zero'] += $zero ? 1 : 0;
        $d['days'][$day] = true;
        $d['recent'] += $start >= strtotime('-7 days', strtotime($today)) ? 1 : 0;
        $d['vehicles'][$vid] = true;
        unset($d);

        $v = &$byVehicle[$vid];
        $v ??= ['trips' => 0, 'meters' => 0, 'drivers' => []];
        $v['trips']++;
        $v['meters'] += $meters;
        $v['drivers'][$uid] = ($v['drivers'][$uid] ?? 0) + 1;
        unset($v);

        $week = date('Y-m-d', strtotime('monday this week', $start));
        if (isset($weeks[$week])) {
            $weeks[$week]['trips']++;
            $weeks[$week]['meters'] += $meters;
        }

        if ($seconds >= FLEET_USAGE_LONG_TRIP_HOURS * 3600) {
            $longTrips[] = $t + ['seconds' => $seconds];
        }

        $totals['trips']++;
        $totals['meters'] += $meters;
        $totals['seconds'] += $seconds;
        $totals['ended'] += $ended ? 1 : 0;
        $totals['zero'] += $zero ? 1 : 0;
    }

    foreach ($drivers as $uid => &$d) {
        // Daily check rate: driving days since checks began, and how many had a check.
        $countable = array_filter(array_keys($d['days']), fn($day) => $checksSince !== null && $day >= $checksSince);
        $d['check_days'] = count($countable);
        $d['checked_days'] = count(array_filter($countable, fn($day) => isset($checkedDay[$uid][$day])));
        $d['checks'] = $checkCount[$uid] ?? 0;
        $d['last_at'] = $lastTrip[$uid] ?? null;
        $d['stopped'] = $d['last_at'] !== null
            && strtotime((string)$d['last_at']) < strtotime('-' . FLEET_USAGE_STOPPED_DAYS . ' days', $now);
        $totals['days'] += $d['check_days'];
        $totals['days_checked'] += $d['checked_days'];
    }
    unset($d);
    uasort($drivers, fn($a, $b) => $b['trips'] <=> $a['trips']);

    $driverNames = array_map(fn($d) => $d['name'], $drivers);
    foreach (fleet_usage_vehicles($conn, $companyId) as $v) {
        $used = $byVehicle[(int)$v['id']] ?? ['trips' => 0, 'meters' => 0, 'drivers' => []];
        arsort($used['drivers']);
        $v['trips'] = $used['trips'];
        $v['meters'] = $used['meters'];
        $v['main_drivers'] = array_slice(array_map(fn($uid) => $driverNames[$uid] ?? '', array_keys($used['drivers'])), 0, 2);
        $vehicleRows[] = $v;
    }
    usort($vehicleRows, fn($a, $b) => [$b['trips'], $b['meters']] <=> [$a['trips'], $a['meters']]);

    usort($longTrips, fn($a, $b) => $b['seconds'] <=> $a['seconds']);
}

$activeDrivers = count(array_filter($drivers, fn($d) => !$d['stopped']));
$vehiclesUsed = count(array_filter($vehicleRows, fn($v) => $v['trips'] > 0));
$checkRate = $totals['days'] > 0 ? (int)round(100 * $totals['days_checked'] / $totals['days']) : null;
$zeroRate = $totals['ended'] > 0 ? (int)round(100 * $totals['zero'] / $totals['ended']) : null;
$maxWeekTrips = max([1, ...array_column($weeks, 'trips')]);
$maxWeekMeters = max([1, ...array_column($weeks, 'meters')]);

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
