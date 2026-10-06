<?php
/**
 * Staff app PINs — generate for many employees at once.
 *
 * Tick the people, press one button, print the list. See
 * hr/includes/hr_pin_bulk.php for what is written and why the PINs are random.
 *
 * The result is drawn in the answer to the POST and nowhere else: no redirect,
 * no session, no cache. The PINs exist in readable form only on that one
 * screen, which is the same promise the profile page makes for a single PIN.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_once __DIR__ . '/includes/hr_pin_bulk.php';
/* Same gate as setting one PIN: this hands people access to the staff app. */
require_role(['Owner', 'Admin', 'HR'], $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$ready = ops_pin_table_ready($conn) && ops_pin_configured();

$companies = hr_active_companies($conn);
$selectedCompanyId = hr_selected_company_id($conn, $companies);

/* ---------- Who can be given a PIN ----------
 * Everyone currently employed, in the chosen company. `has_login` is a login
 * that really exists — some employee rows point at a user that was deleted.
 */
$where = ["e.status IN (" . hr_employee_status_in_sql(hr_employee_current_statuses()) . ")"];
$prms  = hr_employee_current_statuses();
hr_add_company_where($where, $prms, $selectedCompanyId, 'e.company_id');

$sql = "
SELECT e.id, e.full_name, e.employee_code, e.email, e.phone, e.address, e.user_id,
       e.position_title, e.department_id, e.company_id,
       d.name AS dept_name, c.name AS company_name,
       (u.id IS NOT NULL) AS has_login, u.status AS login_status
  FROM employees e
  LEFT JOIN departments d ON d.id = e.department_id
  LEFT JOIN companies c ON c.id = e.company_id
  LEFT JOIN `user` u ON u.id = e.user_id
 WHERE " . implode(' AND ', $where) . "
 ORDER BY e.full_name
";
$stmt = $conn->prepare($sql);
$stmt->execute($prms);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hasPin = [];
if ($ready) {
    foreach ($conn->query("SELECT user_id FROM ops_staff_pins") as $row) {
        $hasPin[(int)$row['user_id']] = true;
    }
}

/* ---------- Generate ---------- */
$results = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $results = [];
    if ($ready) {
        // Only people on the list this user was shown — never an id from the
        // form that the company filter would not have offered.
        $wanted = array_flip(array_map('intval', (array)($_POST['employee_ids'] ?? [])));
        $chosen = array_values(array_filter($employees, static fn($e) => isset($wanted[(int)$e['id']])));
        $actorId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
        $results = hr_pin_bulk_generate($conn, $chosen, $actorId);
    }
}

$pageTitle = 'Staff app PINs';
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Staff app PINs',
    'Generate the PIN staff type to sign in to the mobile apps — for many people at once',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Employees', 'href' => 'employees.php'],
        ['label' => 'Staff app PINs'],
    ],
    '<a class="btn btn-outline-secondary d-print-none" href="employees.php">Employees</a>'
);
?>

<?php if (!$ready): ?>
  <div class="alert alert-warning">
    Staff app PINs are not set up on this server yet
    (<?= ops_pin_table_ready($conn) ? 'OPS_MOBILE_PIN_SECRET is not set' : 'run <code>migrations/ops_staff_pin.sql</code>' ?>).
    Nothing can be generated until that is done.
  </div>
<?php elseif ($results !== null): ?>

  <?php
    $made   = array_values(array_filter($results, static fn($r) => $r['pin'] !== null));
    $failed = array_values(array_filter($results, static fn($r) => $r['pin'] === null));
    $logins = array_values(array_filter($made, static fn($r) => $r['login'] !== null));
  ?>
  <style>
    .pin-code{font-size:1.35rem;font-weight:700;letter-spacing:.18em;font-variant-numeric:tabular-nums;}
    @media print {
      /* The sheet only: no menu, no top bar, no pop-up bar. */
      .hr-sidebar, .hr-sidebar-overlay, .hr-topbar, .as-bar, .breadcrumb { display: none !important; }
      .hr-sidebar ~ *, .hr-content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
      .pin-sheet table { font-size: 12pt; }
      .pin-sheet tr { page-break-inside: avoid; }
    }
  </style>

  <?php if (!$results): ?>
    <div class="alert alert-warning d-print-none">Nobody was ticked, so nothing was generated.</div>
  <?php else: ?>
    <div class="alert alert-warning d-print-none">
      <strong>Print or copy this list now.</strong> These PINs are shown this once and cannot be looked up again.
      If the page is closed first, generate new ones for the people you missed.
      <div class="mt-2">
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">Print</button>
        <a class="btn btn-outline-secondary btn-sm" href="employee_pins_bulk.php<?= $selectedCompanyId ? '?company_id=' . (int)$selectedCompanyId : '' ?>">Back to the list</a>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($made): ?>
  <div class="hr-settings-card mb-3 pin-sheet">
    <div class="settings-header"><?= count($made) ?> PIN<?= count($made) === 1 ? '' : 's' ?> generated &middot; <?= h(date('j M Y')) ?></div>
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead class="table-light">
          <tr><th>Employee</th><th>Code</th><th>PIN</th><th class="d-print-none">Note</th></tr>
        </thead>
        <tbody>
          <?php foreach ($made as $r): ?>
            <tr>
              <td><?= h($r['name']) ?></td>
              <td><?= h($r['code']) ?></td>
              <td><span class="pin-code"><?= h($r['pin']) ?></span></td>
              <td class="d-print-none text-muted small">
                <?= $r['replaced'] ? 'Replaced their old PIN — their phone is signed out' : ($r['login'] ? 'New login created' : '') ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($logins): ?>
  <div class="hr-settings-card mb-3 d-print-none">
    <div class="settings-header">Website logins created (<?= count($logins) ?>)</div>
    <div class="card-body pb-0 text-muted small">
      These employees had no login, so one was made for each. They do not need it for the app, only for the website.
      The temporary passwords are also shown this once.
    </div>
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>Employee</th><th>Username</th><th>Temporary password</th></tr></thead>
        <tbody>
          <?php foreach ($logins as $r): ?>
            <tr><td><?= h($r['name']) ?> (<?= h($r['code']) ?>)</td><td><code><?= h($r['login']['username']) ?></code></td><td><code><?= h($r['login']['password']) ?></code></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($failed): ?>
  <div class="hr-settings-card mb-3 d-print-none">
    <div class="settings-header text-danger">Not generated (<?= count($failed) ?>)</div>
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>Employee</th><th>Why</th><th class="text-end">Action</th></tr></thead>
        <tbody>
          <?php foreach ($failed as $r): ?>
            <tr>
              <td><?= h($r['name']) ?> (<?= h($r['code']) ?>)</td>
              <td>
                <?= h($r['error']) ?>
                <?php if ($r['login']): ?>
                  <div class="small text-muted">A login was created: <code><?= h($r['login']['username']) ?></code> / <code><?= h($r['login']['password']) ?></code></div>
                <?php endif; ?>
              </td>
              <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="employee_view.php?id=<?= (int)$r['employee_id'] ?>#tab-overview">Open profile</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

<?php else: ?>

  <?php
    $withPin = 0; $noLogin = 0;
    foreach ($employees as $e) {
        if (!$e['has_login']) { $noLogin++; }
        elseif (isset($hasPin[(int)$e['user_id']])) { $withPin++; }
    }
    $withoutPin = count($employees) - $withPin;
  ?>

  <div class="hr-filter-bar mb-3">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-4">
        <label class="form-label">Company</label>
        <select name="company_id" class="form-select">
          <option value="0">All companies</option>
          <?php foreach ($companies as $company): ?>
            <option value="<?= (int)$company['id'] ?>" <?= $selectedCompanyId === (int)$company['id'] ? 'selected' : '' ?>>
              <?= h($company['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-primary w-100">Apply</button>
      </div>
    </form>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="hr-settings-card"><div class="card-body">
        <div class="text-muted small">No PIN yet</div>
        <div class="fs-3 fw-semibold text-danger"><?= (int)$withoutPin ?></div>
      </div></div>
    </div>
    <div class="col-md-4">
      <div class="hr-settings-card"><div class="card-body">
        <div class="text-muted small">Already have a PIN</div>
        <div class="fs-3 fw-semibold text-success"><?= (int)$withPin ?></div>
      </div></div>
    </div>
    <div class="col-md-4">
      <div class="hr-settings-card"><div class="card-body">
        <div class="text-muted small">No login yet (one is created with the PIN)</div>
        <div class="fs-3 fw-semibold text-muted"><?= (int)$noLogin ?></div>
      </div></div>
    </div>
  </div>

  <form method="post" id="pinBulkForm" action="employee_pins_bulk.php<?= $selectedCompanyId ? '?company_id=' . (int)$selectedCompanyId : '' ?>">
    <?php csrf_field(); ?>
    <div class="hr-settings-card mb-3">
      <div class="settings-header d-flex flex-wrap align-items-center gap-2">
        <span class="me-auto">Choose who gets a new PIN</span>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-pin-pick="nopin">Everyone without a PIN</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-pin-pick="all">Everyone</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-pin-pick="none">Nobody</button>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr><th style="width:44px;"></th><th>Employee</th><th>Company</th><th>Department</th><th>Login</th><th>PIN</th></tr>
          </thead>
          <tbody>
            <?php if (!$employees): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">No current employees here.</td></tr>
            <?php else: foreach ($employees as $e):
                $pinNow = $e['has_login'] && isset($hasPin[(int)$e['user_id']]);
                $blocked = $e['has_login'] && (int)$e['login_status'] !== 1;
            ?>
              <tr>
                <td>
                  <input class="form-check-input" type="checkbox" name="employee_ids[]" value="<?= (int)$e['id'] ?>"
                         data-has-pin="<?= $pinNow ? '1' : '0' ?>"
                         <?= $blocked ? 'disabled' : (!$pinNow ? 'checked' : '') ?>>
                </td>
                <td><?= h($e['full_name']) ?> (<?= h($e['employee_code']) ?>)</td>
                <td><?= h($e['company_name'] ?: '—') ?></td>
                <td><?= h($e['dept_name'] ?: '—') ?></td>
                <td>
                  <?php if ($blocked): ?>
                    <span class="badge text-bg-secondary">Deactivated</span>
                  <?php elseif ($e['has_login']): ?>
                    <span class="text-muted small">Yes</span>
                  <?php else: ?>
                    <span class="badge text-bg-warning">Will be created</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($pinNow): ?>
                    <span class="badge text-bg-success">Has a PIN</span>
                  <?php else: ?>
                    <span class="badge text-bg-danger">No PIN</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
      <button type="submit" class="btn btn-primary" id="pinBulkBtn">Generate PINs</button>
      <span class="text-muted small" id="pinBulkCount"></span>
    </div>
    <p class="text-muted small">
      Each person ticked gets a new random <?= (int)ops_pin_length() ?>-digit PIN, shown once on the next screen to print.
      Ticking someone who already has a PIN replaces it and signs their phone out.
      Someone with a deactivated login cannot be given a PIN until the login is reactivated.
    </p>
  </form>

  <script>
  (function () {
    var form = document.getElementById('pinBulkForm');
    if (!form) return;
    var boxes = Array.prototype.slice.call(form.querySelectorAll('input[name="employee_ids[]"]:not([disabled])'));
    var count = document.getElementById('pinBulkCount');
    var btn = document.getElementById('pinBulkBtn');

    function picked() { return boxes.filter(function (b) { return b.checked; }); }
    function paint() {
      var p = picked();
      var replacing = p.filter(function (b) { return b.getAttribute('data-has-pin') === '1'; }).length;
      count.textContent = p.length + ' selected' + (replacing ? ' · ' + replacing + ' will have their PIN replaced' : '');
      btn.disabled = p.length === 0;
    }
    form.addEventListener('change', paint);
    form.querySelectorAll('[data-pin-pick]').forEach(function (b) {
      b.addEventListener('click', function () {
        var how = b.getAttribute('data-pin-pick');
        boxes.forEach(function (box) {
          box.checked = how === 'all' || (how === 'nopin' && box.getAttribute('data-has-pin') === '0');
        });
        paint();
      });
    });
    form.addEventListener('submit', function (e) {
      var p = picked();
      var replacing = p.filter(function (b) { return b.getAttribute('data-has-pin') === '1'; }).length;
      var msg = 'Generate new PINs for ' + p.length + ' employee' + (p.length === 1 ? '' : 's') + '?';
      if (replacing) msg += '\n\n' + replacing + ' already have a PIN. It will be replaced and their phone signed out.';
      msg += '\n\nThe PINs are shown once on the next screen. Print it before closing.';
      if (!window.confirm(msg)) { e.preventDefault(); return; }
      btn.disabled = true;
      btn.textContent = 'Generating…';
    });
    paint();
  }());
  </script>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
