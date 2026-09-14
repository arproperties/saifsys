<?php
// hr/fleet_routes.php — the stops a vehicle runs, in order, with times.
// When that vehicle's driver presses Start the route starts too: before 12:00
// as the morning pickup, after 12:00 as the evening drop-off in reverse.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/includes/hr_fleet.php';
require_once __DIR__ . '/includes/hr_fleet_ui.php';
require_role(HR_FLEET_ROLES, $conn);

// Hidden for now, not removed — see fleet_routes_enabled().
if (!fleet_routes_enabled()) {
    header('Location: fleet_live');
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$uid = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
$ready = fleet_tables_ready($conn) && fleet_routes_ready($conn);
$msg = $err = '';

$timeOrNull = static fn($v): ?string => preg_match('/^\d{2}:\d{2}$/', (string)$v) ? $v . ':00' : null;

/** Another active route already on this vehicle, if any. */
$routeOnVehicle = static function (int $vehicleId, int $exceptRouteId) use ($conn): ?array {
    $stmt = $conn->prepare("
        SELECT r.name, v.plate_no FROM fleet_routes r JOIN fleet_vehicles v ON v.id = r.vehicle_id
        WHERE r.vehicle_id = ? AND r.status = 'active' AND r.id <> ? LIMIT 1
    ");
    $stmt->execute([$vehicleId, $exceptRouteId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);

    if (isset($_POST['toggle_status'])) {
        $stmt = $conn->prepare("SELECT name, status, vehicle_id FROM fleet_routes WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $newStatus = $row['status'] === 'active' ? 'inactive' : 'active';
            $clash = $newStatus === 'active' && $row['vehicle_id'] ? $routeOnVehicle((int)$row['vehicle_id'], $id) : null;
            if ($clash) {
                $err = $clash['plate_no'] . ' already runs the route "' . $clash['name'] . '". Deactivate that one first.';
            } else {
                $conn->prepare("UPDATE fleet_routes SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
                audit_bridge_hr_ops(
                    'fleet_route_' . ($newStatus === 'active' ? 'activated' : 'deactivated'), 'fleet_routes', $id,
                    ($newStatus === 'active' ? 'Activated' : 'Deactivated') . ' route ' . $row['name'],
                    null, ['status' => $newStatus], 'Route #' . $id, $uid
                );
                $msg = 'Route ' . $row['name'] . ($newStatus === 'active' ? ' is active again.' : ' is deactivated.');
            }
        }
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
        $pickups = (array)($_POST['stop_pickup'] ?? []);
        $dropoffs = (array)($_POST['stop_dropoff'] ?? []);
        $stops = [];
        foreach ((array)($_POST['stop_point'] ?? []) as $i => $pointId) {
            if ((int)$pointId > 0) {
                $stops[] = [(int)$pointId, $timeOrNull($pickups[$i] ?? ''), $timeOrNull($dropoffs[$i] ?? '')];
            }
        }
        $clash = $vehicleId > 0 ? $routeOnVehicle($vehicleId, $id) : null;

        if ($name === '') {
            $err = 'Give the route a name.';
        } elseif (!$stops) {
            $err = 'Add at least one stop.';
        } elseif (count(array_unique(array_column($stops, 0))) !== count($stops)) {
            $err = 'Each pickup point can only be on a route once.';
        } elseif ($clash) {
            $err = $clash['plate_no'] . ' already runs the route "' . $clash['name'] . '". A vehicle has one route — edit that one, or deactivate it first.';
        } else {
            $conn->beginTransaction();
            try {
                if ($id > 0) {
                    $conn->prepare("UPDATE fleet_routes SET name = ?, vehicle_id = ? WHERE id = ?")
                        ->execute([$name, $vehicleId ?: null, $id]);
                } else {
                    $conn->prepare("INSERT INTO fleet_routes (name, vehicle_id, created_by) VALUES (?, ?, ?)")
                        ->execute([$name, $vehicleId ?: null, $uid]);
                    $id = (int)$conn->lastInsertId();
                }
                $conn->prepare("DELETE FROM fleet_route_stops WHERE route_id = ?")->execute([$id]);
                $insert = $conn->prepare("
                    INSERT INTO fleet_route_stops (route_id, stop_order, point_id, pickup_time, dropoff_time) VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($stops as $i => [$pointId, $pickup, $dropoff]) {
                    $insert->execute([$id, $i + 1, $pointId, $pickup, $dropoff]);
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollBack();
                throw $e;
            }
            audit_bridge_hr_ops(
                'fleet_route_saved', 'fleet_routes', $id, 'Saved route ' . $name . ' with ' . count($stops) . ' stops',
                null, ['name' => $name, 'vehicle_id' => $vehicleId ?: null, 'stops' => $stops], 'Route #' . $id, $uid
            );
            $msg = 'Route ' . $name . ' saved with ' . count($stops) . ' stop' . (count($stops) === 1 ? '' : 's') . '.';
        }
    }
}

// The form: blank, the route being edited, or what was just posted.
$form = [];
$formStops = [];
if ($ready && $err && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['toggle_status'])) {
    $form = $_POST;
    foreach ((array)($_POST['stop_point'] ?? []) as $i => $pointId) {
        $formStops[] = [
            'point_id' => (int)$pointId,
            'pickup' => (string)(((array)($_POST['stop_pickup'] ?? []))[$i] ?? ''),
            'dropoff' => (string)(((array)($_POST['stop_dropoff'] ?? []))[$i] ?? ''),
        ];
    }
} elseif ($ready && isset($_GET['edit']) && !$msg) {
    $stmt = $conn->prepare("SELECT * FROM fleet_routes WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_GET['edit']]);
    if ($form = $stmt->fetch(PDO::FETCH_ASSOC) ?: []) {
        foreach (fleet_route_stop_rows($conn, (int)$form['id']) as $s) {
            $formStops[] = [
                'point_id' => (int)$s['point_id'],
                'pickup' => (string)fleet_hm($s['pickup_time']),
                'dropoff' => (string)fleet_hm($s['dropoff_time']),
            ];
        }
    }
}
if (!$formStops) {
    $formStops[] = ['point_id' => 0, 'pickup' => '', 'dropoff' => ''];
}
$formId = (int)($form['id'] ?? 0);

$routes = [];
$pointOptions = [];
$vehicles = [];
if ($ready) {
    $routes = $conn->query("
        SELECT r.*, v.plate_no FROM fleet_routes r
        LEFT JOIN fleet_vehicles v ON v.id = r.vehicle_id
        ORDER BY r.status = 'inactive', r.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $staff = $conn->prepare("
        SELECT COUNT(*) FROM fleet_route_stops s
        JOIN fleet_point_employees x ON x.point_id = s.point_id
        JOIN employees e ON e.id = x.employee_id AND e.status = 'active'
        WHERE s.route_id = ?
    ");
    foreach ($routes as &$r) {
        $r['stops'] = fleet_route_stop_rows($conn, (int)$r['id']);
        $staff->execute([(int)$r['id']]);
        $r['staff'] = (int)$staff->fetchColumn();
    }
    unset($r);
    $pointOptions = $conn->query("SELECT id, name, status FROM fleet_pickup_points ORDER BY status = 'inactive', name")->fetchAll(PDO::FETCH_ASSOC);
    $vehicles = fleet_vehicle_options($conn);
}

$stopRow = static function (array $s) use ($pointOptions): void {
    ?>
    <tr class="stop-row">
      <td class="stop-no fw-semibold"></td>
      <td>
        <select name="stop_point[]" class="form-select form-select-sm">
          <option value="">Choose a pickup point…</option>
          <?php foreach ($pointOptions as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= (int)$s['point_id'] === (int)$p['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($p['name'] . ($p['status'] === 'inactive' ? ' (inactive)' : '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </td>
      <td><input type="time" name="stop_pickup[]" class="form-control form-control-sm" value="<?= htmlspecialchars($s['pickup']) ?>"></td>
      <td><input type="time" name="stop_dropoff[]" class="form-control form-control-sm" value="<?= htmlspecialchars($s['dropoff']) ?>"></td>
      <td class="text-end text-nowrap">
        <button type="button" class="btn btn-sm btn-light" data-move="-1" aria-label="Move up"><i class="bi bi-arrow-up"></i></button>
        <button type="button" class="btn btn-sm btn-light" data-move="1" aria-label="Move down"><i class="bi bi-arrow-down"></i></button>
        <button type="button" class="btn btn-sm btn-light text-danger" data-remove aria-label="Remove stop"><i class="bi bi-x-lg"></i></button>
      </td>
    </tr>
    <?php
};

$pageTitle = 'Routes';
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Routes',
    'The stops a vehicle runs, in order. When its driver presses Start the route starts too — before 12:00 as the morning pickup, after 12:00 as the evening drop-off in reverse order.',
    [['label' => 'HR', 'href' => $hrBase . '/dashboard'], ['label' => 'Routes']],
    '<a class="btn btn-outline-secondary" href="fleet_points">Pickup points</a>'
);
?>

<?php if (!$ready): ?>
  <div class="alert alert-warning">Routes are not set up on this server yet. Run <code>migrations/fleet_tracking.sql</code>, then <code>migrations/fleet_pickup_routes.sql</code>.</div>
<?php elseif (!$pointOptions): ?>
  <div class="alert alert-info">Add pickup points first — a route is made of them. <a href="fleet_points">Add a pickup point</a></div>
<?php else: ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <div class="hr-settings-card mb-4">
    <div class="settings-header"><?= $formId ? 'Edit route' : 'Add a route' ?></div>
    <div class="card-body">
      <form method="post" action="fleet_routes">
        <?php csrf_field(); ?>
        <input type="hidden" name="id" value="<?= $formId ?>">
        <div class="row g-3 mb-3">
          <div class="col-md-5">
            <label class="form-label">Route name *</label>
            <input class="form-control" name="name" required maxlength="100" placeholder="e.g. MOE run"
                   value="<?= htmlspecialchars((string)($form['name'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Vehicle</label>
            <select class="form-select" name="vehicle_id">
              <option value="">Not on a vehicle yet</option>
              <?php foreach ($vehicles as $v): ?>
                <option value="<?= (int)$v['id'] ?>" <?= (int)($form['vehicle_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($v['plate_no'] . ($v['name'] ? ' · ' . $v['name'] : '') . ($v['status'] === 'inactive' ? ' (inactive)' : '')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle mb-2">
            <thead class="table-light">
              <tr>
                <th style="width:40px">#</th>
                <th>Pickup point</th>
                <th style="width:140px">Morning pickup</th>
                <th style="width:140px">Evening drop-off</th>
                <th style="width:130px"></th>
              </tr>
            </thead>
            <tbody id="stops-body">
              <?php foreach ($formStops as $s) { $stopRow($s); } ?>
            </tbody>
          </table>
        </div>
        <template id="stop-template"><?php $stopRow(['point_id' => 0, 'pickup' => '', 'dropoff' => '']); ?></template>
        <button type="button" class="btn btn-sm btn-outline-primary" id="add-stop"><i class="bi bi-plus-lg me-1"></i>Add stop</button>
        <div class="form-text">List stops in morning order. The evening drop-off runs them backwards. Times are optional — they are only used to show late stops.</div>

        <div class="mt-3 d-flex gap-2">
          <button class="btn btn-primary"><?= $formId ? 'Save changes' : 'Add route' ?></button>
          <?php if ($formId): ?><a class="btn btn-outline-secondary" href="fleet_routes">Cancel</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header"><?= count($routes) ?> route<?= count($routes) === 1 ? '' : 's' ?></div>
    <div class="card-body p-0">
      <div class="hr-table-shell border-0 shadow-none rounded-0">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr><th>Route</th><th>Vehicle</th><th>Stops (morning order)</th><th>Staff</th><th>Status</th><th class="text-end">Actions</th></tr>
          </thead>
          <tbody>
          <?php if (!$routes): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No routes yet. Add one above.</td></tr>
          <?php endif; ?>
          <?php foreach ($routes as $r): ?>
            <tr>
              <td class="fw-semibold"><?= htmlspecialchars($r['name']) ?></td>
              <td><?= $r['plate_no'] ? '<a href="vehicle_view?id=' . (int)$r['vehicle_id'] . '">' . htmlspecialchars($r['plate_no']) . '</a>' : '<span class="text-muted">—</span>' ?></td>
              <td class="small">
                <?php foreach ($r['stops'] as $s): ?>
                  <div>
                    <?= (int)$s['stop_order'] ?>. <?= htmlspecialchars($s['name']) ?>
                    <span class="text-muted"><?= htmlspecialchars(implode(' / ', array_filter([fleet_hm($s['pickup_time']), fleet_hm($s['dropoff_time'])]))) ?></span>
                    <?php if ($s['status'] === 'inactive'): ?><span class="badge text-bg-secondary">inactive</span><?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </td>
              <td><?= (int)$r['staff'] ?></td>
              <td><?= hr_ui_status_pill($r['status'], ucfirst($r['status'])) ?></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-secondary" href="fleet_routes?edit=<?= (int)$r['id'] ?>">Edit</a>
                <form method="post" action="fleet_routes" class="d-inline"
                      onsubmit="return confirm('<?= $r['status'] === 'active' ? 'Deactivate this route? Its vehicle\'s trips will have no stops.' : 'Make this route active again?' ?>')">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-sm <?= $r['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>" name="toggle_status" value="1">
                    <?= $r['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php
$pageScripts = '<script>
(function () {
  var body = document.getElementById("stops-body");
  var tpl = document.getElementById("stop-template");
  if (!body || !tpl) return;
  function renumber() {
    body.querySelectorAll(".stop-row").forEach(function (row, i) { row.querySelector(".stop-no").textContent = i + 1; });
  }
  document.getElementById("add-stop").addEventListener("click", function () {
    body.appendChild(tpl.content.cloneNode(true));
    renumber();
  });
  body.addEventListener("click", function (e) {
    var button = e.target.closest("button");
    if (!button) return;
    var row = button.closest(".stop-row");
    if (button.hasAttribute("data-remove")) {
      if (body.querySelectorAll(".stop-row").length > 1) row.remove();
      else row.querySelectorAll("select, input").forEach(function (f) { f.value = ""; });
    } else if (button.getAttribute("data-move") === "-1" && row.previousElementSibling) {
      body.insertBefore(row, row.previousElementSibling);
    } else if (button.getAttribute("data-move") === "1" && row.nextElementSibling) {
      body.insertBefore(row.nextElementSibling, row);
    }
    renumber();
  });
  renumber();
})();
</script>';
require_once __DIR__ . '/includes/hr_layout_footer.php';
