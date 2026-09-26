<?php
/**
 * Attendance — who is missing.
 *
 * Two lists for a chosen day:
 *   - people with no check-in at all
 *   - people who checked in and never checked out
 *
 * Only covers staff who record their own attendance, that is employees with a
 * user account to log in with. Field staff on the PIN app are not listed here
 * because they never had a check-in button to forget.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_once __DIR__ . '/../includes/attendance_self.php';
require_role(['Owner','Admin','HR'], $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ---------- Filters ---------- */
// Default to today in company time, not the server's UTC day.
$today = attendance_self_now()->format('Y-m-d');
$day = $_GET['day'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$day)) {
    $day = $today;
}

$companies = hr_active_companies($conn);
$selectedCompanyId = hr_selected_company_id($conn, $companies);

/* ---------- Query ----------
 * Everyone currently employed who has a login, with today's attendance row
 * attached if there is one. The join on `user` is what limits this to staff
 * who could have checked themselves in.
 */
$where = ["e.status IN (" . hr_employee_status_in_sql(hr_employee_current_statuses()) . ")"];
$prms  = hr_employee_current_statuses();
array_unshift($prms, $day);
hr_add_company_where($where, $prms, $selectedCompanyId, 'e.company_id');

$sql = "
SELECT e.id, e.full_name, e.employee_code, e.status AS emp_status,
       c.name AS company_name,
       a.id AS att_id, a.check_in, a.check_out, a.hours, a.status AS att_status, a.source
  FROM employees e
  LEFT JOIN companies c ON c.id = e.company_id
  LEFT JOIN attendance a ON a.employee_id = e.id AND a.work_date = ?
 WHERE " . implode(' AND ', $where) . "
   AND EXISTS (
        SELECT 1 FROM `user` u
         WHERE (u.employee_id = e.id OR u.id = e.user_id)
           AND u.is_active = 1
   )
 ORDER BY e.full_name
";

$stmt = $conn->prepare($sql);
$stmt->execute($prms);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$noCheckIn = [];
$noCheckOut = [];
$complete = 0;
$excused = 0;

foreach ($rows as $r) {
    $attStatus = (string)($r['att_status'] ?? '');
    // HR has already said what the day is for this person — not a gap.
    if (in_array($attStatus, ['on_leave', 'absent', 'half', 'excused_absent'], true)) {
        $excused++;
        continue;
    }
    if (empty($r['check_in'])) {
        $noCheckIn[] = $r;
    } elseif (empty($r['check_out'])) {
        $noCheckOut[] = $r;
    } else {
        $complete++;
    }
}

$pageTitle = 'Attendance — Missing';
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Attendance — Missing',
    'Who has not checked in or out on ' . h(date('l, j M Y', strtotime($day))),
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Attendance', 'href' => 'attendance.php'],
        ['label' => 'Missing'],
    ],
    '<a class="btn btn-outline-secondary" href="attendance.php">All attendance</a>'
);
?>

<?php if (!attendance_self_enabled()): ?>
  <div class="alert alert-warning">
    Self check-in is switched off, so nobody can record their own attendance yet.
    This page still shows the gaps against what HR has entered.
  </div>
<?php endif; ?>

<div class="hr-filter-bar mb-3">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-md-3">
      <label class="form-label">Day</label>
      <input type="date" name="day" class="form-control" value="<?= h($day) ?>">
    </div>
    <div class="col-md-3">
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
  <div class="col-md-3">
    <div class="hr-settings-card"><div class="card-body">
      <div class="text-muted small">Not checked in</div>
      <div class="fs-3 fw-semibold text-danger"><?= count($noCheckIn) ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="hr-settings-card"><div class="card-body">
      <div class="text-muted small">No check-out</div>
      <div class="fs-3 fw-semibold text-warning"><?= count($noCheckOut) ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="hr-settings-card"><div class="card-body">
      <div class="text-muted small">Complete</div>
      <div class="fs-3 fw-semibold text-success"><?= (int)$complete ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="hr-settings-card"><div class="card-body">
      <div class="text-muted small">Leave / absent</div>
      <div class="fs-3 fw-semibold text-muted"><?= (int)$excused ?></div>
    </div></div>
  </div>
</div>

<div class="hr-settings-card mb-3">
  <div class="settings-header">No check-out — checked in but never left</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Employee</th><th>Company</th><th>In</th><th>Recorded by</th><th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$noCheckOut): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">Nobody — everyone who came in has checked out.</td></tr>
        <?php else: foreach ($noCheckOut as $r): ?>
          <tr>
            <td><?= h($r['full_name']) ?> (<?= h($r['employee_code']) ?>)</td>
            <td><?= h($r['company_name'] ?: '—') ?></td>
            <td><?= h($r['check_in']) ?></td>
            <td><?= ($r['source'] ?? '') === 'self' ? '<span class="badge text-bg-primary">Self</span>' : '<span class="text-muted small">HR</span>' ?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="attendance_edit.php?id=<?= (int)$r['att_id'] ?>">Set check-out</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="hr-settings-card">
  <div class="settings-header">Not checked in at all</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Employee</th><th>Company</th><th>Employee status</th><th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$noCheckIn): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">Nobody — everyone is accounted for.</td></tr>
        <?php else: foreach ($noCheckIn as $r): ?>
          <tr>
            <td><?= h($r['full_name']) ?> (<?= h($r['employee_code']) ?>)</td>
            <td><?= h($r['company_name'] ?: '—') ?></td>
            <td><span class="badge text-bg-light text-dark"><?= h($r['emp_status']) ?></span></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-secondary"
                 href="attendance.php?employee_id=<?= (int)$r['id'] ?>&amp;date_from=<?= h($day) ?>&amp;date_to=<?= h($day) ?>">Record</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
