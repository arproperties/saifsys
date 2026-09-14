<?php
// hr/fleet_pickup_report.php — one day of route trips: when the van reached
// each stop, how long it waited, and which stops were late or missed.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_fleet.php';
require_once __DIR__ . '/includes/hr_fleet_ui.php';
require_role(HR_FLEET_ROLES, $conn);

// Hidden for now, not removed — see fleet_routes_enabled().
if (!fleet_routes_enabled()) {
    header('Location: fleet_live');
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$ready = fleet_tables_ready($conn) && fleet_routes_ready($conn);
$date = hr_fleet_date_param('date', date('Y-m-d'));
$vehicleId = (int)($_GET['vehicle_id'] ?? 0);

$trips = [];
$people = [];
$vehicles = [];
$totals = ['stops' => 0, 'reached' => 0, 'late' => 0, 'missed' => 0];

if ($ready) {
    $params = [$date . ' 00:00:00', $date . ' 23:59:59'];
    if ($vehicleId > 0) {
        $params[] = $vehicleId;
    }
    $stmt = $conn->prepare(
        fleet_trip_select_sql()
        . " WHERE t.route_id IS NOT NULL AND t.started_at BETWEEN ? AND ?"
        . ($vehicleId > 0 ? " AND t.vehicle_id = ?" : "")
        . " ORDER BY t.started_at"
    );
    $stmt->execute($params);
    $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($trips as &$t) {
        $t['stops'] = fleet_trip_stops($conn, (int)$t['id']);
        foreach ($t['stops'] as $s) {
            $totals['stops']++;
            if ($s['arrived_at'] !== null) {
                $totals['reached']++;
                $late = fleet_stop_late_minutes($t, $s);
                if ($late !== null && $late > fleet_late_grace_minutes()) {
                    $totals['late']++;
                }
            } elseif ($t['ended_at'] !== null) {
                $totals['missed']++;
            }
        }
    }
    unset($t);

    foreach ($conn->query("
        SELECT x.point_id, e.full_name FROM fleet_point_employees x
        JOIN employees e ON e.id = x.employee_id
        WHERE e.status = 'active' ORDER BY e.full_name
    ")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $people[(int)$row['point_id']][] = $row['full_name'];
    }
    $vehicles = fleet_vehicle_options($conn);
}

$pageTitle = 'Pickup Report';
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Pickup Report',
    'When the van reached each stop, from GPS. Late = more than ' . fleet_late_grace_minutes() . ' minutes after the stop\'s time.',
    [['label' => 'HR', 'href' => $hrBase . '/dashboard'], ['label' => 'Pickup Report']],
    '<a class="btn btn-outline-secondary" href="fleet_routes">Routes</a>'
);
?>

<?php if (!$ready): ?>
  <div class="alert alert-warning">Routes are not set up on this server yet. Run <code>migrations/fleet_tracking.sql</code>, then <code>migrations/fleet_pickup_routes.sql</code>.</div>
<?php else: ?>
  <form class="row g-2 align-items-end mb-3">
    <div class="col-auto">
      <label class="form-label small mb-1">Day</label>
      <input type="date" name="date" class="form-control" value="<?= $date ?>">
    </div>
    <div class="col-auto">
      <label class="form-label small mb-1">Vehicle</label>
      <select name="vehicle_id" class="form-select">
        <option value="">All vehicles</option>
        <?php foreach ($vehicles as $v): ?>
          <option value="<?= (int)$v['id'] ?>" <?= $vehicleId === (int)$v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['plate_no']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto"><button class="btn btn-primary">Show</button></div>
  </form>

  <div class="row g-3 mb-3">
    <?php foreach ([
        ['Route trips', count($trips), ''],
        ['Stops reached', $totals['reached'] . ' / ' . $totals['stops'], ''],
        ['Late stops', $totals['late'], $totals['late'] ? 'text-warning' : ''],
        ['Missed stops', $totals['missed'], $totals['missed'] ? 'text-danger' : ''],
    ] as [$label, $value, $class]): ?>
      <div class="col-6 col-md-3">
        <div class="hr-settings-card h-100"><div class="card-body">
          <div class="small text-muted"><?= $label ?></div>
          <div class="fs-5 fw-semibold <?= $class ?>"><?= $value ?></div>
        </div></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (!$trips): ?>
    <div class="alert alert-light border">No route trips on this day. A trip shows here when its vehicle has a route (HR → Fleet → Routes).</div>
  <?php endif; ?>

  <?php foreach ($trips as $t):
      $open = $t['ended_at'] === null;
      $history = 'vehicle_view?' . http_build_query(['id' => (int)$t['vehicle_id'], 'from' => $date, 'to' => $date]);
      ?>
    <div class="hr-settings-card mb-3">
      <div class="settings-header d-flex flex-wrap align-items-center gap-2">
        <a class="fw-semibold" href="<?= htmlspecialchars($history) ?>"><?= htmlspecialchars($t['plate_no']) ?></a>
        <span><?= htmlspecialchars(fleet_trip_route_label($t)) ?></span>
        <span class="text-muted small">
          · <?= htmlspecialchars($t['driver_name']) ?>
          · <?= date('H:i', strtotime($t['started_at'])) ?>–<?= $open ? 'now' : date('H:i', strtotime($t['ended_at'])) ?>
        </span>
        <?php if ($open): ?><span class="badge text-bg-success">On trip</span><?php endif; ?>
        <a class="btn btn-sm btn-outline-primary ms-auto" href="<?= htmlspecialchars($history) ?>"><i class="bi bi-play-fill"></i> Play on map</a>
      </div>
      <div class="card-body p-0">
        <div class="hr-table-shell border-0 shadow-none rounded-0">
          <table class="table align-middle mb-0 small">
            <thead class="table-light">
              <tr><th style="width:40px">#</th><th>Stop</th><th>Time</th><th>Reached</th><th>Left</th><th>Status</th><th>Staff at this point</th></tr>
            </thead>
            <tbody>
            <?php foreach ($t['stops'] as $s):
                $late = fleet_stop_late_minutes($t, $s);
                $names = $people[(int)$s['point_id']] ?? [];
                if ($s['arrived_at'] === null) {
                    $badge = $open ? '<span class="badge text-bg-secondary">Not yet</span>' : '<span class="badge text-bg-danger">Missed</span>';
                } elseif ($late !== null && $late > fleet_late_grace_minutes()) {
                    $badge = '<span class="badge text-bg-warning">' . $late . ' min late</span>';
                } else {
                    $badge = '<span class="badge text-bg-success">' . ($late !== null ? 'On time' : 'Reached') . '</span>';
                }
                ?>
              <tr>
                <td><?= (int)$s['stop_order'] ?></td>
                <td class="fw-semibold"><?= htmlspecialchars($s['name']) ?></td>
                <td><?= htmlspecialchars((string)(fleet_hm($s['planned_time']) ?? '—')) ?></td>
                <td><?= $s['arrived_at'] ? date('H:i', strtotime($s['arrived_at'])) : '—' ?></td>
                <td>
                  <?php if ($s['left_at']): ?>
                    <?= date('H:i', strtotime($s['left_at'])) ?>
                    <span class="text-muted">· waited <?= max(0, (int)round((strtotime($s['left_at']) - strtotime($s['arrived_at'])) / 60)) ?> min</span>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= $badge ?></td>
                <td title="<?= htmlspecialchars(implode(', ', $names)) ?>">
                  <?= count($names) ?><?php if ($names): ?> <span class="text-muted">· <?= htmlspecialchars(implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? '…' : '')) ?></span><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$t['stops']): ?>
              <tr><td colspan="7" class="text-center text-muted py-3">This route had no active stops when the trip started.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
