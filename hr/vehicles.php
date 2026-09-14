<?php
// hr/vehicles.php — register the company's vehicles.
// A vehicle keeps its trips whoever drives it, so vehicles are deactivated,
// never deleted.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_fleet.php';
require_once __DIR__ . '/includes/hr_fleet_ui.php';
require_role(HR_FLEET_ROLES, $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$uid = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
$companies = hr_active_companies($conn);
$ready = fleet_tables_ready($conn);
$types = fleet_vehicle_types();
$msg = $err = '';

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);

    if (isset($_POST['toggle_status'])) {
        $stmt = $conn->prepare("SELECT plate_no, status, company_id FROM fleet_vehicles WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $newStatus = ($row['status'] ?? '') === 'active' ? 'inactive' : 'active';
        if (!$row) {
            $err = 'Vehicle not found.';
        } elseif ($newStatus === 'inactive' && fleet_open_trip_for_vehicle($conn, $id)) {
            $err = $row['plate_no'] . ' is on a trip right now. End the trip before deactivating it.';
        } else {
            $conn->prepare("UPDATE fleet_vehicles SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            audit_bridge_hr_ops(
                'fleet_vehicle_' . ($newStatus === 'active' ? 'activated' : 'deactivated'),
                'fleet_vehicles', $id,
                ($newStatus === 'active' ? 'Activated' : 'Deactivated') . ' vehicle ' . $row['plate_no'],
                (int)$row['company_id'], ['status' => $newStatus], 'Vehicle #' . $id, $uid
            );
            $msg = $row['plate_no'] . ($newStatus === 'active' ? ' is active again.' : ' is deactivated. Drivers no longer see it.');
        }
    } else {
        $plate = strtoupper(trim((string)preg_replace('/\s+/', ' ', (string)($_POST['plate_no'] ?? ''))));
        $data = [
            'company_id' => (int)($_POST['company_id'] ?? 0),
            'plate_no' => $plate,
            'name' => trim((string)($_POST['name'] ?? '')) ?: null,
            'vehicle_type' => isset($types[$_POST['vehicle_type'] ?? '']) ? $_POST['vehicle_type'] : null,
            'color' => trim((string)($_POST['color'] ?? '')) ?: null,
            'notes' => trim((string)($_POST['notes'] ?? '')) ?: null,
        ];

        if ($plate === '' || $data['company_id'] <= 0) {
            $err = 'Plate number and company are required.';
        } else {
            try {
                if ($id > 0) {
                    $conn->prepare("
                        UPDATE fleet_vehicles SET company_id = ?, plate_no = ?, name = ?, vehicle_type = ?, color = ?, notes = ?
                        WHERE id = ?
                    ")->execute([...array_values($data), $id]);
                    $action = 'updated';
                } else {
                    $conn->prepare("
                        INSERT INTO fleet_vehicles (company_id, plate_no, name, vehicle_type, color, notes, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ")->execute([...array_values($data), $uid]);
                    $id = (int)$conn->lastInsertId();
                    $action = 'created';
                }
                audit_bridge_hr_ops(
                    'fleet_vehicle_' . $action, 'fleet_vehicles', $id,
                    ucfirst($action) . ' vehicle ' . $plate,
                    $data['company_id'], $data, 'Vehicle #' . $id, $uid
                );
                $msg = 'Vehicle ' . $plate . ' ' . ($action === 'created' ? 'registered.' : 'saved.');
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
                $err = 'Another vehicle already has plate ' . $plate . '.';
            }
        }
    }
}

// The form: blank, or the vehicle being edited.
$edit = null;
if ($ready && isset($_GET['edit']) && !$msg) {
    $stmt = $conn->prepare("SELECT * FROM fleet_vehicles WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$form = $err && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['toggle_status']) ? $_POST : ($edit ?? []);

$companyId = hr_selected_company_id($conn, $companies);
$rows = [];
if ($ready) {
    $stmt = $conn->prepare("
        SELECT v.*, c.name AS company_name, t.driver_name AS on_trip_driver, t.started_at AS on_trip_since,
               (SELECT MAX(x.started_at) FROM fleet_trips x WHERE x.vehicle_id = v.id) AS last_trip_at
        FROM fleet_vehicles v
        LEFT JOIN companies c ON c.id = v.company_id
        LEFT JOIN fleet_trips t ON t.open_vehicle_id = v.id
        " . ($companyId > 0 ? "WHERE v.company_id = ?" : "") . "
        ORDER BY v.status = 'inactive', v.plate_no
    ");
    $stmt->execute($companyId > 0 ? [$companyId] : []);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Vehicles';
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Vehicles',
    'Register vehicles here. Drivers pick one in the Driver app and press Start.',
    [['label' => 'HR', 'href' => $hrBase . '/dashboard'], ['label' => 'Vehicles']],
    '<a class="btn btn-outline-primary" href="fleet_live"><i class="bi bi-geo-alt me-1"></i>Live map</a>'
);
?>

<?php if (!$ready): ?>
  <div class="alert alert-warning">Vehicle tracking is not set up on this server yet. Run <code>migrations/fleet_tracking.sql</code>.</div>
<?php else: ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <div class="hr-settings-card mb-4">
    <div class="settings-header"><?= !empty($form['id']) ? 'Edit vehicle' : 'Register a vehicle' ?></div>
    <div class="card-body">
      <form method="post" action="vehicles" class="row g-3">
        <?php csrf_field(); ?>
        <input type="hidden" name="id" value="<?= (int)($form['id'] ?? 0) ?>">
        <div class="col-md-3">
          <label class="form-label">Plate number *</label>
          <input class="form-control text-uppercase" name="plate_no" required maxlength="30"
                 value="<?= htmlspecialchars((string)($form['plate_no'] ?? '')) ?>" placeholder="e.g. DXB A 12345">
        </div>
        <div class="col-md-3">
          <label class="form-label">Make / model</label>
          <input class="form-control" name="name" maxlength="100"
                 value="<?= htmlspecialchars((string)($form['name'] ?? '')) ?>" placeholder="e.g. Toyota Hiace">
        </div>
        <div class="col-md-2">
          <label class="form-label">Type</label>
          <select class="form-select" name="vehicle_type">
            <option value="">—</option>
            <?php foreach ($types as $key => $label): ?>
              <option value="<?= $key ?>" <?= ($form['vehicle_type'] ?? '') === $key ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Colour</label>
          <input class="form-control" name="color" maxlength="30" value="<?= htmlspecialchars((string)($form['color'] ?? '')) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Company *</label>
          <select class="form-select" name="company_id" required>
            <option value="">Choose…</option>
            <?php foreach ($companies as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)($form['company_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-9">
          <label class="form-label">Notes</label>
          <input class="form-control" name="notes" maxlength="255" value="<?= htmlspecialchars((string)($form['notes'] ?? '')) ?>">
        </div>
        <div class="col-md-3 d-flex align-items-end gap-2">
          <button class="btn btn-primary flex-grow-1"><?= !empty($form['id']) ? 'Save changes' : 'Register vehicle' ?></button>
          <?php if (!empty($form['id'])): ?><a class="btn btn-outline-secondary" href="vehicles">Cancel</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <span><?= count($rows) ?> vehicle<?= count($rows) === 1 ? '' : 's' ?></span>
      <form class="d-flex gap-2">
        <select name="company_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All companies</option>
          <?php foreach ($companies as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $companyId === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <div class="card-body p-0">
      <div class="hr-table-shell border-0 shadow-none rounded-0">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr><th>Plate</th><th>Vehicle</th><th>Company</th><th>Now</th><th>Status</th><th class="text-end">Actions</th></tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No vehicles yet. Register one above.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $v): ?>
            <tr>
              <td><a class="fw-semibold" href="vehicle_view?id=<?= (int)$v['id'] ?>"><?= htmlspecialchars($v['plate_no']) ?></a></td>
              <td>
                <?= htmlspecialchars((string)($v['name'] ?? '')) ?>
                <div class="small text-muted"><?= htmlspecialchars(trim(($types[$v['vehicle_type']] ?? '') . ' ' . ($v['color'] ?? ''))) ?></div>
              </td>
              <td><?= htmlspecialchars((string)($v['company_name'] ?? '')) ?></td>
              <td class="small">
                <?php if ($v['on_trip_driver'] !== null): ?>
                  <span class="badge text-bg-success">On trip</span>
                  <?= htmlspecialchars($v['on_trip_driver']) ?> · since <?= date('H:i', strtotime($v['on_trip_since'])) ?>
                <?php elseif ($v['last_trip_at']): ?>
                  <span class="text-muted">Idle · last trip <?= date('d M Y', strtotime($v['last_trip_at'])) ?></span>
                <?php else: ?>
                  <span class="text-muted">No trips yet</span>
                <?php endif; ?>
              </td>
              <td><?= hr_ui_status_pill($v['status'] === 'active' ? 'active' : 'inactive', ucfirst($v['status'])) ?></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-primary" href="vehicle_view?id=<?= (int)$v['id'] ?>">History</a>
                <a class="btn btn-sm btn-outline-secondary" href="vehicles?edit=<?= (int)$v['id'] ?>">Edit</a>
                <form method="post" action="vehicles" class="d-inline"
                      onsubmit="return confirm('<?= $v['status'] === 'active' ? 'Deactivate this vehicle? Drivers will no longer see it. Its history is kept.' : 'Make this vehicle available to drivers again?' ?>')">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                  <button class="btn btn-sm <?= $v['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>" name="toggle_status" value="1">
                    <?= $v['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
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

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
