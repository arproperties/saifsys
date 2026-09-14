<?php
// hr/fleet_points.php — pickup points (Mall of Emirates, a manager's home…)
// and the employees picked up at each. Routes are built from these.

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
$radii = [100 => '100 m', 150 => '150 m', 250 => '250 m', 400 => '400 m'];
$msg = $err = '';

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);

    if (isset($_POST['toggle_status'])) {
        $stmt = $conn->prepare("SELECT name, status FROM fleet_pickup_points WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $newStatus = $row['status'] === 'active' ? 'inactive' : 'active';
            $conn->prepare("UPDATE fleet_pickup_points SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            audit_bridge_hr_ops(
                'fleet_point_' . ($newStatus === 'active' ? 'activated' : 'deactivated'), 'fleet_pickup_points', $id,
                ($newStatus === 'active' ? 'Activated' : 'Deactivated') . ' pickup point ' . $row['name'],
                null, ['status' => $newStatus], 'Pickup point #' . $id, $uid
            );
            $msg = $row['name'] . ($newStatus === 'active' ? ' is active again.' : ' is deactivated. Routes skip it from the next trip.');
        }
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $lat = $_POST['lat'] ?? '';
        $lng = $_POST['lng'] ?? '';
        $radius = (int)($_POST['radius_m'] ?? 150);
        $radius = isset($radii[$radius]) ? $radius : 150;
        $notes = trim((string)($_POST['notes'] ?? '')) ?: null;
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['employee_ids'] ?? [])))));

        if ($name === '' || !is_numeric($lat) || !is_numeric($lng) || abs((float)$lat) > 90 || abs((float)$lng) > 180
            || ((float)$lat == 0.0 && (float)$lng == 0.0)) {
            $err = 'A name and a place on the map are required.';
        } else {
            $conn->beginTransaction();
            try {
                if ($id > 0) {
                    $conn->prepare("UPDATE fleet_pickup_points SET name = ?, lat = ?, lng = ?, radius_m = ?, notes = ? WHERE id = ?")
                        ->execute([$name, $lat, $lng, $radius, $notes, $id]);
                } else {
                    $conn->prepare("INSERT INTO fleet_pickup_points (name, lat, lng, radius_m, notes, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$name, $lat, $lng, $radius, $notes, $uid]);
                    $id = (int)$conn->lastInsertId();
                }

                // Exactly the ticked employees belong here. Ticking someone who
                // was at another point moves them — one point per employee.
                $params = [$id];
                $keep = '';
                if ($employeeIds) {
                    $keep = ' AND employee_id NOT IN (' . implode(',', array_fill(0, count($employeeIds), '?')) . ')';
                    $params = array_merge($params, $employeeIds);
                }
                $conn->prepare("DELETE FROM fleet_point_employees WHERE point_id = ?" . $keep)->execute($params);

                $assign = $conn->prepare("
                    INSERT INTO fleet_point_employees (employee_id, point_id, assigned_by, assigned_at)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        assigned_by = IF(point_id = VALUES(point_id), assigned_by, VALUES(assigned_by)),
                        assigned_at = IF(point_id = VALUES(point_id), assigned_at, VALUES(assigned_at)),
                        point_id = VALUES(point_id)
                ");
                foreach ($employeeIds as $empId) {
                    $assign->execute([$empId, $id, $uid, fleet_now()]);
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollBack();
                throw $e;
            }

            audit_bridge_hr_ops(
                'fleet_point_saved', 'fleet_pickup_points', $id, 'Saved pickup point ' . $name . ' with ' . count($employeeIds) . ' employees',
                null, ['name' => $name, 'lat' => $lat, 'lng' => $lng, 'radius_m' => $radius, 'employee_ids' => $employeeIds],
                'Pickup point #' . $id, $uid
            );
            $msg = 'Pickup point ' . $name . ' saved with ' . count($employeeIds) . ' employee' . (count($employeeIds) === 1 ? '' : 's') . '.';
        }
    }
}

$edit = null;
$checked = [];
if ($ready && isset($_GET['edit']) && !$msg) {
    $stmt = $conn->prepare("SELECT * FROM fleet_pickup_points WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($edit) {
        $stmt = $conn->prepare("SELECT employee_id FROM fleet_point_employees WHERE point_id = ?");
        $stmt->execute([(int)$edit['id']]);
        $checked = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
$failedSave = $err && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['toggle_status']);
$form = $failedSave ? $_POST : ($edit ?? []);
if ($failedSave) {
    $checked = array_map('intval', (array)($_POST['employee_ids'] ?? []));
}
$formId = (int)($form['id'] ?? 0);

$points = [];
$employees = [];
if ($ready) {
    $points = $conn->query("
        SELECT p.*,
               (SELECT COUNT(*) FROM fleet_point_employees x JOIN employees e ON e.id = x.employee_id AND e.status = 'active'
                WHERE x.point_id = p.id) AS employee_count,
               (SELECT GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ')
                FROM fleet_route_stops s JOIN fleet_routes r ON r.id = s.route_id
                WHERE s.point_id = p.id AND r.status = 'active') AS route_names
        FROM fleet_pickup_points p
        ORDER BY p.status = 'inactive', p.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $employees = $conn->query("
        SELECT e.id, e.full_name, e.employee_code, c.name AS company_name, x.point_id, p.name AS point_name
        FROM employees e
        LEFT JOIN companies c ON c.id = e.company_id
        LEFT JOIN fleet_point_employees x ON x.employee_id = e.id
        LEFT JOIN fleet_pickup_points p ON p.id = x.point_id
        WHERE e.status = 'active'
        ORDER BY e.full_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Pickup Points';
$pageHead = hr_fleet_map_head();
$pageStyles = hr_fleet_map_styles() . '.emp-list{max-height:300px;overflow:auto} #point-map{height:560px}';
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Pickup Points',
    'Places where the van picks staff up and drops them off. Tick who is picked up at each.',
    [['label' => 'HR', 'href' => $hrBase . '/dashboard'], ['label' => 'Pickup Points']],
    '<a class="btn btn-outline-secondary" href="fleet_routes">Routes</a>'
);
?>

<?php if (!$ready): ?>
  <div class="alert alert-warning">Pickup points are not set up on this server yet. Run <code>migrations/fleet_tracking.sql</code>, then <code>migrations/fleet_pickup_routes.sql</code>.</div>
<?php else: ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <div class="hr-settings-card mb-4">
    <div class="settings-header"><?= $formId ? 'Edit pickup point' : 'Add a pickup point' ?></div>
    <div class="card-body">
      <form method="post" action="fleet_points" class="row g-3">
        <?php csrf_field(); ?>
        <input type="hidden" name="id" value="<?= $formId ?>">
        <div class="col-lg-5">
          <div class="mb-3">
            <label class="form-label">Name *</label>
            <input class="form-control" name="name" required maxlength="100" placeholder="e.g. Mall of Emirates – Gate 3"
                   value="<?= htmlspecialchars((string)($form['name'] ?? '')) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Place *</label>
            <input class="form-control" id="p_paste" placeholder="Click the map, or paste coordinates like 25.1181, 55.2006">
            <div class="row g-2 mt-1">
              <div class="col"><input class="form-control form-control-sm" name="lat" id="p_lat" readonly placeholder="Latitude" value="<?= htmlspecialchars((string)($form['lat'] ?? '')) ?>"></div>
              <div class="col"><input class="form-control form-control-sm" name="lng" id="p_lng" readonly placeholder="Longitude" value="<?= htmlspecialchars((string)($form['lng'] ?? '')) ?>"></div>
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-5">
              <label class="form-label">Reached within</label>
              <select class="form-select" name="radius_m" id="p_radius">
                <?php foreach ($radii as $value => $label): ?>
                  <option value="<?= $value ?>" <?= (int)($form['radius_m'] ?? 150) === $value ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-7">
              <label class="form-label">Notes</label>
              <input class="form-control" name="notes" maxlength="255" value="<?= htmlspecialchars((string)($form['notes'] ?? '')) ?>">
            </div>
          </div>

          <label class="form-label">Employees picked up here · <span id="emp_count">0</span> ticked</label>
          <input class="form-control form-control-sm mb-2" id="emp_filter" placeholder="Search name, code or company">
          <div class="emp-list border rounded p-2">
            <?php foreach ($employees as $e):
                $search = mb_strtolower($e['full_name'] . ' ' . $e['employee_code'] . ' ' . $e['company_name']);
                $elsewhere = $e['point_id'] !== null && (int)$e['point_id'] !== $formId;
                ?>
              <label class="form-check emp-row d-block mb-1" data-search="<?= htmlspecialchars($search) ?>">
                <input class="form-check-input" type="checkbox" name="employee_ids[]" value="<?= (int)$e['id'] ?>"
                       <?= in_array((int)$e['id'], $checked, true) ? 'checked' : '' ?>>
                <span class="form-check-label">
                  <?= htmlspecialchars($e['full_name']) ?>
                  <span class="text-muted small">· <?= htmlspecialchars(trim($e['employee_code'] . ' · ' . $e['company_name'], ' ·')) ?></span>
                  <?php if ($elsewhere): ?>
                    <span class="badge text-bg-light border" title="Ticking moves them here">now at <?= htmlspecialchars($e['point_name']) ?></span>
                  <?php endif; ?>
                </span>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary"><?= $formId ? 'Save changes' : 'Add pickup point' ?></button>
            <?php if ($formId): ?><a class="btn btn-outline-secondary" href="fleet_points">Cancel</a><?php endif; ?>
          </div>
        </div>
        <div class="col-lg-7">
          <div id="point-map" class="fleet-map"></div>
          <div class="small text-muted mt-1">Click to place the point. Drag the pin to adjust. The circle is the area that counts as "reached".</div>
        </div>
      </form>
    </div>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header"><?= count($points) ?> pickup point<?= count($points) === 1 ? '' : 's' ?></div>
    <div class="card-body p-0">
      <div class="hr-table-shell border-0 shadow-none rounded-0">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr><th>Name</th><th>Employees</th><th>Routes</th><th>Reached within</th><th>Status</th><th class="text-end">Actions</th></tr>
          </thead>
          <tbody>
          <?php if (!$points): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No pickup points yet. Add one above.</td></tr>
          <?php endif; ?>
          <?php foreach ($points as $p): ?>
            <tr>
              <td class="fw-semibold"><?= htmlspecialchars($p['name']) ?>
                <?php if (!empty($p['notes'])): ?><div class="small text-muted fw-normal"><?= htmlspecialchars($p['notes']) ?></div><?php endif; ?>
              </td>
              <td><?= (int)$p['employee_count'] ?></td>
              <td class="small"><?= htmlspecialchars((string)($p['route_names'] ?? '—')) ?></td>
              <td><?= (int)$p['radius_m'] ?> m</td>
              <td><?= hr_ui_status_pill($p['status'], ucfirst($p['status'])) ?></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-secondary" href="fleet_points?edit=<?= (int)$p['id'] ?>">Edit</a>
                <form method="post" action="fleet_points" class="d-inline"
                      onsubmit="return confirm('<?= $p['status'] === 'active' ? 'Deactivate this point? Routes skip it from the next trip.' : 'Make this point active again?' ?>')">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-sm <?= $p['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>" name="toggle_status" value="1">
                    <?= $p['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
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
if ($ready) {
    $pointsJson = json_encode(array_map(static fn(array $p): array => [
        'id' => (int)$p['id'], 'name' => $p['name'], 'lat' => (float)$p['lat'], 'lng' => (float)$p['lng'],
    ], $points), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $pageScripts = hr_fleet_map_scripts($hrAssetBase) . '<script>
(function () {
  var points = ' . $pointsJson . ';
  var editId = ' . $formId . ';
  var lat = document.getElementById("p_lat");
  var lng = document.getElementById("p_lng");
  var radius = document.getElementById("p_radius");
  var map = L.map("point-map").setView([25.2, 55.3], 10);
  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19, attribution: "&copy; OpenStreetMap contributors" }).addTo(map);

  points.forEach(function (p) {
    if (p.id === editId) return;
    L.circleMarker([p.lat, p.lng], { radius: 6, color: "#6b7280", fillOpacity: 0.6 }).bindTooltip(p.name).addTo(map);
  });

  var marker = null;
  var circle = null;
  function place(la, ln, zoom) {
    lat.value = la.toFixed(7);
    lng.value = ln.toFixed(7);
    if (!marker) {
      marker = L.marker([la, ln], { draggable: true }).addTo(map);
      marker.on("dragend", function () { var p = marker.getLatLng(); place(p.lat, p.lng, false); });
      circle = L.circle([la, ln], { radius: Number(radius.value), color: "#2563eb" }).addTo(map);
    }
    marker.setLatLng([la, ln]);
    circle.setLatLng([la, ln]).setRadius(Number(radius.value));
    if (zoom) map.setView([la, ln], 16);
  }

  map.on("click", function (e) { place(e.latlng.lat, e.latlng.lng, false); });
  radius.addEventListener("change", function () { if (circle) circle.setRadius(Number(radius.value)); });
  document.getElementById("p_paste").addEventListener("input", function () {
    var m = this.value.match(/(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/);
    if (m) place(parseFloat(m[1]), parseFloat(m[2]), true);
  });

  if (lat.value && lng.value) {
    place(parseFloat(lat.value), parseFloat(lng.value), true);
  } else if (points.length) {
    map.fitBounds(L.latLngBounds(points.map(function (p) { return [p.lat, p.lng]; })).pad(0.3), { maxZoom: 14 });
  }

  var count = document.getElementById("emp_count");
  var boxes = document.querySelectorAll(".emp-row input");
  function recount() { count.textContent = document.querySelectorAll(".emp-row input:checked").length; }
  boxes.forEach(function (b) { b.addEventListener("change", recount); });
  recount();

  document.getElementById("emp_filter").addEventListener("input", function () {
    var q = this.value.trim().toLowerCase();
    document.querySelectorAll(".emp-row").forEach(function (row) {
      row.hidden = q !== "" && row.getAttribute("data-search").indexOf(q) === -1;
    });
  });
})();
</script>';
}
require_once __DIR__ . '/includes/hr_layout_footer.php';
