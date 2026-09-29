<?php
/**
 * Operations — R410 gas report.
 *
 * Every weight the technicians recorded in the field app: unit, before, after,
 * used, with the photo of the scale behind each number. Filter by date, person
 * and building; the total is what left the cylinders in that range.
 * See migrations/ops_job_gas_readings.sql.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/ops_helper.php';
require_once __DIR__ . '/includes/ops_gas.php';

require_login(get_application_web_root() . '/login');
ops_require_access($conn);

$appBase = get_application_web_root();
$opsBase = $appBase . '/modules/operations';
$companyId = ops_company_id($conn);

$validDate = static fn($d): bool => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$from = $validDate($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-01');
$to = $validDate($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-d');
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$staffId = (int)($_GET['staff'] ?? 0);
$buildingId = (int)($_GET['building'] ?? 0);

$where = ['g.company_id = ?', 'g.before_at >= ?', 'g.before_at < ?'];
$params = [$companyId, $from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
if ($staffId > 0) {
    $where[] = 'g.before_by = ?';
    $params[] = $staffId;
}
if ($buildingId > 0) {
    $where[] = 'g.building_id = ?';
    $params[] = $buildingId;
}

$rows = [];
$tableMissing = false;
try {
    $stmt = $conn->prepare("
        SELECT g.*, j.title AS job_title, j.status AS job_status,
               COALESCE(NULLIF(bu.fullname, ''), bu.username) AS before_by_name,
               COALESCE(NULLIF(au.fullname, ''), au.username) AS after_by_name
        FROM ops_job_gas_readings g
        JOIN ops_jobs j ON j.id = g.job_id
        LEFT JOIN user bu ON bu.id = g.before_by
        LEFT JOIN user au ON au.id = g.after_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY g.before_at DESC, g.id DESC
        LIMIT 1000
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log('ops gas report failed: ' . $e->getMessage());
    $tableMissing = true;
}

$totalUsed = 0.0;
$openCount = 0;
$units = [];
foreach ($rows as $r) {
    $used = ops_gas_used_kg($r);
    if ($used === null) {
        $openCount++;
    } else {
        $totalUsed += $used;
    }
    $units[strtolower(trim((string)$r['unit_label']))] = true;
}

// Filter lists: people who have ever weighed gas here, and this company's buildings.
$staffList = [];
$buildingList = [];
try {
    $s = $conn->prepare("
        SELECT DISTINCT u.id, COALESCE(NULLIF(u.fullname, ''), u.username) AS name
        FROM ops_job_gas_readings g JOIN user u ON u.id = g.before_by
        WHERE g.company_id = ? ORDER BY name ASC
    ");
    $s->execute([$companyId]);
    $staffList = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    // Table not there yet; the page already says so.
}
$b = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name ASC");
$b->execute([$companyId]);
$buildingList = $b->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = 'Gas (R410)';
require __DIR__ . '/includes/ops_layout_header.php';
?>

<div class="mb-4">
  <div class="page-header-label">Gas (R410)</div>
  <div class="text-muted small">
    Technicians weigh the gas cylinder before and after every unit in the staff app, with a photo of the scale.
    Click a weight to see its photo.
  </div>
</div>

<?php if ($tableMissing): ?>
  <div class="alert alert-warning">The gas table is not set up yet. Run migrations/ops_job_gas_readings.sql.</div>
<?php endif; ?>

<form method="get" class="card card-round mb-3">
  <div class="card-body row g-2 align-items-end">
    <div class="col-6 col-md-2">
      <label class="form-label small text-muted mb-1">From</label>
      <input type="date" name="from" value="<?= h($from) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small text-muted mb-1">To</label>
      <input type="date" name="to" value="<?= h($to) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small text-muted mb-1">Technician</label>
      <select name="staff" class="form-select form-select-sm">
        <option value="">Everyone</option>
        <?php foreach ($staffList as $st): ?>
          <option value="<?= (int)$st['id'] ?>" <?= $staffId === (int)$st['id'] ? 'selected' : '' ?>><?= h($st['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small text-muted mb-1">Building</label>
      <select name="building" class="form-select form-select-sm">
        <option value="">All buildings</option>
        <?php foreach ($buildingList as $bl): ?>
          <option value="<?= (int)$bl['id'] ?>" <?= $buildingId === (int)$bl['id'] ? 'selected' : '' ?>><?= h($bl['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-2">
      <button class="btn btn-sm btn-primary w-100">Show</button>
    </div>
  </div>
</form>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="stat-tile"><div class="stat-value"><?= h(ops_gas_kg_label($totalUsed)) ?></div><div class="stat-label">Gas used</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-tile"><div class="stat-value"><?= count($rows) ?></div><div class="stat-label">Times weighed</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-tile"><div class="stat-value"><?= count($units) ?></div><div class="stat-label">Units</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-tile"><div class="stat-value <?= $openCount ? 'text-warning' : '' ?>"><?= $openCount ?></div><div class="stat-label">Waiting for after weight</div></div></div>
</div>

<div class="card card-round">
  <div class="table-responsive">
    <table class="table ops-table align-middle">
      <thead>
        <tr>
          <th>Date</th>
          <th>Unit</th>
          <th>Job</th>
          <th>Technician</th>
          <th>Before</th>
          <th>After</th>
          <th>Used</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">No gas recorded in this range.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <?php $used = ops_gas_used_kg($r); ?>
          <tr>
            <td class="text-nowrap"><?= h(date('d M Y', strtotime((string)$r['before_at']))) ?></td>
            <td class="fw-semibold"><?= h($r['unit_label']) ?></td>
            <td><a href="<?= h($opsBase) ?>/job_view.php?id=<?= (int)$r['job_id'] ?>">#<?= (int)$r['job_id'] ?></a> <span class="small text-muted"><?= h($r['job_title']) ?></span></td>
            <td><?= h($r['before_by_name'] ?? '') ?></td>
            <td class="num text-nowrap">
              <a href="<?= h($opsBase) ?>/gas_photo.php?id=<?= (int)$r['id'] ?>&stage=before" target="_blank" rel="noopener"><?= h(ops_gas_kg_label($r['before_kg'])) ?> <i class="bi bi-image"></i></a>
              <div class="small text-muted"><?= h(date('H:i', strtotime((string)$r['before_at']))) ?></div>
            </td>
            <td class="num text-nowrap">
              <?php if ($r['after_kg'] !== null): ?>
                <a href="<?= h($opsBase) ?>/gas_photo.php?id=<?= (int)$r['id'] ?>&stage=after" target="_blank" rel="noopener"><?= h(ops_gas_kg_label($r['after_kg'])) ?> <i class="bi bi-image"></i></a>
                <div class="small text-muted"><?= h(date('H:i', strtotime((string)$r['after_at']))) ?></div>
              <?php else: ?>
                <span class="text-warning-emphasis small"><i class="bi bi-hourglass-split"></i> Not weighed yet</span>
              <?php endif; ?>
            </td>
            <td class="num fw-bold"><?= $used !== null ? h(ops_gas_kg_label($used)) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/ops_layout_footer.php'; ?>
