<?php
// hr/fleet_checks.php — the drivers' daily pre-drive checklists. Problems show
// in red until someone here marks them as seen. Rules: includes/hr_fleet_checks.php.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_fleet.php';
require_once __DIR__ . '/includes/hr_fleet_ui.php';
require_once __DIR__ . '/includes/hr_fleet_checks.php';
require_role(HR_FLEET_ROLES, $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$ready = fleet_tables_ready($conn) && fleet_checks_ready($conn);

// Mark a check's problems as seen.
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $uid = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    $stmt = $conn->prepare("UPDATE fleet_daily_checks SET reviewed_at = ?, reviewed_by = ? WHERE id = ? AND problem_count > 0 AND reviewed_at IS NULL");
    $stmt->execute([fleet_now(), $uid, (int)($_POST['check_id'] ?? 0)]);
    $_SESSION[$stmt->rowCount() ? 'flash_success' : 'flash_error'] = $stmt->rowCount() ? 'Marked as seen.' : 'That check was already marked.';
    $back = (string)($_POST['back'] ?? '');
    header('Location: ' . (preg_match('#^fleet_checks(\?[A-Za-z0-9_=&%.\-]*)?$#', $back) ? $back : 'fleet_checks'));
    exit;
}

$companies = hr_active_companies($conn);
$filters = [
    'vehicle_id' => (int)($_GET['vehicle_id'] ?? 0),
    'driver_user_id' => (int)($_GET['driver_user_id'] ?? 0),
    'company_id' => hr_selected_company_id($conn, $companies),
    'date_from' => hr_fleet_date_param('from', date('Y-m-d', strtotime('-7 days'))),
    'date_to' => hr_fleet_date_param('to', date('Y-m-d')),
    'problems_only' => !empty($_GET['problems']),
];
$checks = $ready ? fleet_daily_check_rows($conn, $filters) : [];
$vehicles = $ready ? fleet_vehicle_options($conn) : [];
$drivers = $ready ? fleet_driver_options($conn) : [];
$items = fleet_daily_checklist_items();
$openProblems = $ready ? fleet_open_problem_count($conn) : 0;

$back = 'fleet_checks?' . http_build_query([
    'vehicle_id' => $filters['vehicle_id'] ?: null,
    'driver_user_id' => $filters['driver_user_id'] ?: null,
    'company_id' => $filters['company_id'] ?: null,
    'from' => $filters['date_from'],
    'to' => $filters['date_to'],
    'problems' => $filters['problems_only'] ? 1 : null,
]);

$pageTitle = 'Daily Checks';
$pageStyles = '
.check-row-problem{background:#fef2f2}
.check-row-problem.check-seen{background:transparent}
.check-answers{columns:2;column-gap:2rem}
@media (max-width:767px){.check-answers{columns:1}}
.check-answers li{break-inside:avoid;padding:2px 0}
';
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Daily Checks',
    'The checklist each driver fills before their first trip of the day in a vehicle. Problems stay red until marked as seen.',
    [['label' => 'HR', 'href' => $hrBase . '/dashboard'], ['label' => 'Daily Checks']],
    $openProblems > 0
        ? '<a class="btn btn-outline-danger" href="fleet_checks?problems=1&from=' . date('Y-m-d', strtotime('-90 days')) . '"><i class="bi bi-exclamation-triangle me-1"></i>' . $openProblems . ' not seen yet</a>'
        : ''
);
?>

<?php hr_fleet_flash(); ?>

<?php if (!$ready): ?>
  <div class="alert alert-warning">Daily checks are not set up on this server yet. Run <code>migrations/fleet_daily_checks.sql</code>.</div>
<?php else: ?>
  <div class="hr-settings-card">
    <div class="settings-header">
      <form class="row g-2 align-items-end">
        <div class="col-md-2">
          <label class="form-label small mb-1">Vehicle</label>
          <select name="vehicle_id" class="form-select form-select-sm">
            <option value="">All vehicles</option>
            <?php foreach ($vehicles as $v): ?>
              <option value="<?= (int)$v['id'] ?>" <?= $filters['vehicle_id'] === (int)$v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['plate_no']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">Driver</label>
          <select name="driver_user_id" class="form-select form-select-sm">
            <option value="">All drivers</option>
            <?php foreach ($drivers as $d): ?>
              <option value="<?= (int)$d['driver_user_id'] ?>" <?= $filters['driver_user_id'] === (int)$d['driver_user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['driver_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">Company</label>
          <select name="company_id" class="form-select form-select-sm">
            <option value="">All companies</option>
            <?php foreach ($companies as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $filters['company_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">From</label>
          <input type="date" name="from" class="form-control form-control-sm" value="<?= $filters['date_from'] ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">To</label>
          <input type="date" name="to" class="form-control form-control-sm" value="<?= $filters['date_to'] ?>">
        </div>
        <div class="col-md-2">
          <div class="form-check small mb-1">
            <input class="form-check-input" type="checkbox" name="problems" value="1" id="problemsOnly" <?= $filters['problems_only'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="problemsOnly">Problems only</label>
          </div>
          <button class="btn btn-sm btn-primary w-100">Show checks</button>
        </div>
      </form>
    </div>
    <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table align-middle mb-0 small">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Vehicle</th>
            <th>Driver</th>
            <th class="text-end">Start km</th>
            <th>Result</th>
            <th class="text-end"></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$checks): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No checks in this period.</td></tr>
        <?php endif; ?>
        <?php foreach ($checks as $c):
            $answers = json_decode((string)$c['answers'], true) ?: [];
            $problem = (int)$c['problem_count'] > 0;
            $seen = $c['reviewed_at'] !== null;
            $rowId = 'check-' . (int)$c['id'];
            ?>
          <tr class="<?= $problem ? 'check-row-problem' . ($seen ? ' check-seen' : '') : '' ?>">
            <td>
              <div class="fw-semibold"><?= date('d M Y', strtotime((string)$c['check_date'])) ?></div>
              <div class="text-muted"><?= date('H:i', strtotime((string)$c['created_at'])) ?></div>
            </td>
            <td>
              <a href="vehicle_view?id=<?= (int)$c['vehicle_id'] ?>" class="fw-semibold"><?= htmlspecialchars((string)$c['plate_no']) ?></a>
              <?php if (!empty($c['vehicle_name'])): ?><div class="text-muted"><?= htmlspecialchars((string)$c['vehicle_name']) ?></div><?php endif; ?>
            </td>
            <td><?= htmlspecialchars((string)$c['driver_name']) ?></td>
            <td class="text-end"><?= number_format((int)$c['start_km']) ?></td>
            <td>
              <?php if (!$problem): ?>
                <span class="badge text-bg-success">All OK</span>
              <?php else: ?>
                <span class="badge text-bg-danger"><?= (int)$c['problem_count'] ?> problem<?= (int)$c['problem_count'] === 1 ? '' : 's' ?></span>
                <?php if ($seen): ?>
                  <span class="badge text-bg-light text-muted" title="<?= htmlspecialchars(date('d M Y H:i', strtotime((string)$c['reviewed_at']))) ?>">Seen<?= $c['reviewed_by_name'] ? ' by ' . htmlspecialchars((string)$c['reviewed_by_name']) : '' ?></span>
                <?php endif; ?>
                <div class="mt-1"><?= nl2br(htmlspecialchars((string)$c['notes'])) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <?php if ($problem && !$seen): ?>
                <form method="post" class="d-inline">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="check_id" value="<?= (int)$c['id'] ?>">
                  <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">
                  <button class="btn btn-sm btn-outline-danger">Mark as seen</button>
                </form>
              <?php endif; ?>
              <button type="button" class="btn btn-sm btn-outline-secondary ms-1" data-bs-toggle="collapse" data-bs-target="#<?= $rowId ?>">Details</button>
            </td>
          </tr>
          <tr class="collapse" id="<?= $rowId ?>">
            <td colspan="6" class="bg-light">
              <ul class="list-unstyled check-answers mb-0">
                <?php foreach ($items as $key => $item):
                    $a = $answers[$key] ?? null; ?>
                  <li>
                    <?php if ($a === 'ok'): ?><i class="bi bi-check-circle-fill text-success"></i>
                    <?php elseif ($a === 'problem'): ?><i class="bi bi-x-circle-fill text-danger"></i>
                    <?php elseif ($a === 'na'): ?><i class="bi bi-dash-circle text-muted"></i>
                    <?php else: ?><i class="bi bi-question-circle text-muted" title="Not on the list when this check was done"></i>
                    <?php endif; ?>
                    <span class="<?= $a === 'problem' ? 'text-danger fw-semibold' : '' ?>"><?= htmlspecialchars($item['label']) ?></span>
                    <?= $a === 'na' ? '<span class="text-muted">(N/A)</span>' : '' ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php';
