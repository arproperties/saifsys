<?php
/**
 * Attendance checks — read only (added 2026-11-12).
 *   Absence alerts · Attendance vs jobs · Attendance after leaving
 * Nothing on this page changes data. Wrong rows are fixed from the Attendance list.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_once __DIR__ . '/includes/hr_attendance_checks.php';
require_role(['Owner', 'Admin', 'HR'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$companies = hr_active_companies($conn);
$selectedCompanyId = hr_selected_company_id($conn, $companies);
$tab = in_array($_GET['tab'] ?? '', ['alerts', 'jobs', 'exit'], true) ? $_GET['tab'] : 'alerts';

$validDay = static function ($v): ?string {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
};
$today = hr_attendance_today();
$jFrom = $validDay($_GET['from'] ?? '') ?? (new DateTimeImmutable($today))->modify('-60 days')->format('Y-m-d');
$jTo = $validDay($_GET['to'] ?? '') ?? $today;
if ($jFrom > $jTo) { [$jFrom, $jTo] = [$jTo, $jFrom]; }
if ((new DateTimeImmutable($jFrom))->diff(new DateTimeImmutable($jTo))->days > 366) {
    $jFrom = (new DateTimeImmutable($jTo))->modify('-366 days')->format('Y-m-d');
}

$alerts = hr_att_check_alerts($conn, $selectedCompanyId);
$jobs = $tab === 'jobs' ? hr_att_check_mismatches($conn, $jFrom, $jTo, $selectedCompanyId) : null;
$afterExit = hr_att_check_after_exit($conn, $selectedCompanyId);
$staleExit = hr_att_check_stale_exit($conn, $selectedCompanyId);

$attLink = static function (int $empId, string $from, string $to): string {
    return 'attendance.php?' . http_build_query(['employee_id' => $empId, 'date_from' => $from, 'date_to' => $to]);
};
$fd = static fn($d) => $d ? date('j M Y', strtotime($d)) : '—';
$qs = static function (array $extra) use ($selectedCompanyId, $tab): string {
    return '?' . http_build_query(array_merge(['tab' => $tab, 'company_id' => $selectedCompanyId ?: ''], $extra));
};

$pageTitle = 'Attendance checks';
$hrScopeLabel = hr_company_scope_label($companies, $selectedCompanyId);
$pageStyles = '
.chk-tabs .nav-link { font-weight: 600; color: #4b5563; }
.chk-tabs .nav-link.active { color: #111827; }
.chk-badge { display:inline-block; min-width:1.6rem; padding:1px 7px; border-radius:999px; font-size:.75rem; font-weight:700; background:#eef1f4; color:#374151; margin-left:4px; text-align:center; }
.chk-badge.is-red { background:#fde2e1; color:#b42318; }
.chk-law { color:#b42318; font-weight:700; }
.chk-near { color:#b45309; font-weight:700; }
';
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Attendance checks',
    'Read only. Problems to review — fix wrong days from the Attendance list.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Attendance', 'href' => $hrBase . '/attendance'],
        ['label' => 'Checks'],
    ],
    '<a class="btn btn-outline-secondary" href="attendance.php"><i class="bi bi-arrow-left me-1"></i>Attendance</a>'
);

$nAlerts = count($alerts['streak']) + count($alerts['year']);
$nExit = count($afterExit) + count($staleExit);
?>

  <div class="hr-filter-bar mb-3">
    <form method="get" class="d-flex flex-wrap align-items-end gap-2">
      <input type="hidden" name="tab" value="<?= h($tab) ?>">
      <div>
        <label class="form-label small mb-1">Company</label>
        <select name="company_id" class="form-select" onchange="this.form.submit()">
          <option value="0">All companies</option>
          <?php foreach ($companies as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $selectedCompanyId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($tab === 'jobs'): ?>
        <div><label class="form-label small mb-1">From</label><input type="date" name="from" class="form-control" value="<?= h($jFrom) ?>"></div>
        <div><label class="form-label small mb-1">To</label><input type="date" name="to" class="form-control" value="<?= h($jTo) ?>"></div>
        <button class="btn btn-primary">Show</button>
      <?php endif; ?>
    </form>
  </div>

  <ul class="nav nav-tabs chk-tabs mb-0">
    <li class="nav-item"><a class="nav-link <?= $tab === 'alerts' ? 'active' : '' ?>" href="<?= h($qs(['tab' => 'alerts'])) ?>">Absence alerts<span class="chk-badge <?= $nAlerts ? 'is-red' : '' ?>"><?= $nAlerts ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'jobs' ? 'active' : '' ?>" href="<?= h($qs(['tab' => 'jobs'])) ?>">Attendance vs jobs</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'exit' ? 'active' : '' ?>" href="<?= h($qs(['tab' => 'exit'])) ?>">After leaving<span class="chk-badge <?= $nExit ? 'is-red' : '' ?>"><?= $nExit ?></span></a></li>
  </ul>

  <div class="hr-settings-card" style="border-top-left-radius:0">
  <?php if ($tab === 'alerts'): ?>
    <div class="card-body">
      <div class="alert alert-light border small">
        <strong>UAE Labour Law, Article 44:</strong> absent without a valid reason for <b>more than <?= HR_ATT_STREAK_LAW ?> days in a row</b>,
        or <b>more than <?= HR_ATT_YEAR_LAW ?> separate days in one year</b>. Only days without a valid reason count —
        check each day before acting. A day with no record or a weekly off (e.g. Sunday) does not break a run. Current staff only.
      </div>

      <h6 class="mt-3">Absent days in a row (last 60 days) — <?= HR_ATT_STREAK_ALERT ?> or more</h6>
      <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table table-hover align-middle mb-3">
        <thead class="table-light"><tr><th>Employee</th><th class="text-end">Days in a row</th><th>From</th><th>To</th><th></th></tr></thead>
        <tbody>
        <?php if (!$alerts['streak']): ?>
          <tr><td colspan="5" class="text-center text-muted py-3">Nobody has <?= HR_ATT_STREAK_ALERT ?> or more absent days in a row.</td></tr>
        <?php else: foreach ($alerts['streak'] as $r): ?>
          <tr>
            <td class="fw-semibold"><?= h($r['name']) ?> <span class="text-muted small">(<?= h($r['code']) ?>)</span></td>
            <td class="text-end <?= $r['n'] > HR_ATT_STREAK_LAW ? 'chk-law' : 'chk-near' ?>"><?= (int)$r['n'] ?><?= $r['n'] > HR_ATT_STREAK_LAW ? ' — over the limit' : '' ?></td>
            <td><?= h($fd($r['from'])) ?></td><td><?= h($fd($r['to'])) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= h($attLink((int)$r['employee_id'], $r['from'], $r['to'])) ?>">See days</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      </div>

      <h6>Absent days in the last 12 months — <?= HR_ATT_YEAR_ALERT ?> or more</h6>
      <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Employee</th><th class="text-end">Absent days</th><th>Period</th><th></th></tr></thead>
        <tbody>
        <?php if (!$alerts['year']): ?>
          <tr><td colspan="4" class="text-center text-muted py-3">Nobody has <?= HR_ATT_YEAR_ALERT ?> or more absent days in the last 12 months.</td></tr>
        <?php else: foreach ($alerts['year'] as $r): ?>
          <tr>
            <td class="fw-semibold"><?= h($r['name']) ?> <span class="text-muted small">(<?= h($r['code']) ?>)</span></td>
            <td class="text-end <?= $r['days'] > HR_ATT_YEAR_LAW ? 'chk-law' : 'chk-near' ?>"><?= h(rtrim(rtrim(number_format($r['days'], 1), '0'), '.')) ?><?= $r['days'] > HR_ATT_YEAR_LAW ? ' — over the limit' : '' ?></td>
            <td><?= h($fd($r['from'])) ?> – <?= h($fd($r['to'])) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= h($attLink((int)$r['employee_id'], $r['from'], $r['to']) . '&status=absent') ?>">See days</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      </div>
    </div>

  <?php elseif ($tab === 'jobs'): ?>
    <div class="card-body">
      <div class="alert alert-light border small">
        Attendance compared with the cleaning job schedule, <?= h($fd($jFrom)) ?> – <?= h($fd($jTo)) ?>.
        Each line is a day where the two records disagree — one of them is wrong. Open the day and fix it if needed.
      </div>

      <h6 class="mt-3">Marked Absent, but the job was completed or billed <span class="chk-badge <?= $jobs['absent_but_worked'] ? 'is-red' : '' ?>"><?= count($jobs['absent_but_worked']) ?></span></h6>
      <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table table-hover align-middle mb-3">
        <thead class="table-light"><tr><th>Date</th><th>Employee</th><th>Job</th><th>Job status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$jobs['absent_but_worked']): ?>
          <tr><td colspan="5" class="text-center text-muted py-3">None in these dates.</td></tr>
        <?php else: foreach ($jobs['absent_but_worked'] as $r): ?>
          <tr>
            <td class="text-nowrap"><?= h($fd($r['work_date'])) ?></td>
            <td class="fw-semibold"><?= h($r['full_name']) ?> <span class="text-muted small">(<?= h($r['employee_code']) ?>)</span></td>
            <td>#<?= (int)$r['order_id'] ?> <?= h($r['client_name']) ?></td>
            <td><span class="badge text-bg-<?= $r['job_status'] === 'invoiced' ? 'danger' : 'warning' ?>"><?= $r['job_status'] === 'invoiced' ? 'Billed to client' : 'Completed' ?></span></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="attendance_edit.php?id=<?= (int)$r['att_id'] ?>">Open day</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      </div>

      <h6>Marked Present, but the job or supervisor says absent / sick <span class="chk-badge <?= $jobs['present_but_absent'] ? 'is-red' : '' ?>"><?= count($jobs['present_but_absent']) ?></span></h6>
      <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Date</th><th>Employee</th><th>Where</th><th>What it says</th><th></th></tr></thead>
        <tbody>
        <?php if (!$jobs['present_but_absent']): ?>
          <tr><td colspan="5" class="text-center text-muted py-3">None in these dates.</td></tr>
        <?php else: foreach ($jobs['present_but_absent'] as $r): ?>
          <tr>
            <td class="text-nowrap"><?= h($fd($r['work_date'])) ?></td>
            <td class="fw-semibold"><?= h($r['full_name']) ?> <span class="text-muted small">(<?= h($r['employee_code']) ?>)</span></td>
            <td><?= h($r['source']) ?><?= $r['order_id'] ? ' #' . (int)$r['order_id'] . ' ' . h($r['client_name']) : '' ?></td>
            <td class="small"><?= h(preg_replace('/^Cleaner:\s*/', '', (string)$r['reason'])) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="attendance_edit.php?id=<?= (int)$r['att_id'] ?>">Open day</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      </div>
    </div>

  <?php else: ?>
    <div class="card-body">
      <div class="alert alert-light border small">
        Attendance dated <b>after</b> the employee's last working day. These rows are usually wrong (for example a one-click entry for all staff).
        Nothing is deleted automatically: open the days, check them, and an Owner can delete wrong rows from the Attendance list.
        New rows after the last working day can no longer be added.
      </div>
      <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Employee</th><th>Status</th><th>Last working day</th><th class="text-end">Rows after</th><th class="text-end">of them Present</th><th>Dates</th><th></th></tr></thead>
        <tbody>
        <?php if (!$afterExit): ?>
          <tr><td colspan="7" class="text-center text-muted py-3">No attendance after anyone's last working day.</td></tr>
        <?php else: foreach ($afterExit as $r): ?>
          <tr>
            <td class="fw-semibold"><?= h($r['full_name']) ?> <span class="text-muted small">(<?= h($r['employee_code']) ?>)</span></td>
            <td class="small"><?= h(ucwords(str_replace('_', ' ', (string)$r['emp_status']))) ?></td>
            <td><?= h($fd($r['last_day'])) ?></td>
            <td class="text-end fw-bold"><?= (int)$r['rows_after'] ?></td>
            <td class="text-end"><?= (int)$r['present_after'] ?></td>
            <td class="small text-nowrap"><?= h($fd($r['first_after'])) ?> – <?= h($fd($r['last_after'])) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= h($attLink((int)$r['employee_id'], date('Y-m-d', strtotime($r['last_day'] . ' +1 day')), $r['last_after'])) ?>">See rows</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      </div>

      <h6 class="mt-4">Current staff with an old exit date on the profile <span class="chk-badge <?= $staleExit ? 'is-red' : '' ?>"><?= count($staleExit) ?></span></h6>
      <div class="small text-muted mb-2">These people are still Active / On leave / Notice, but the profile shows a last working day or exit date that has passed (or a wrong date). They are <b>not</b> locked. Fix the profile: clear the date, or mark the person as left.</div>
      <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Employee</th><th>Status</th><th>Last working day</th><th>Exit date</th><th></th></tr></thead>
        <tbody>
        <?php if (!$staleExit): ?>
          <tr><td colspan="5" class="text-center text-muted py-3">None.</td></tr>
        <?php else: foreach ($staleExit as $r):
          $badDate = static fn($d) => $d && $d < '2000-01-01'; ?>
          <tr>
            <td class="fw-semibold"><?= h($r['full_name']) ?> <span class="text-muted small">(<?= h($r['employee_code']) ?>)</span></td>
            <td class="small"><?= h(ucwords(str_replace('_', ' ', (string)$r['emp_status']))) ?></td>
            <td><?= $badDate($r['last_working_day']) ? '<span class="chk-law">wrong date</span>' : h($fd($r['last_working_day'])) ?></td>
            <td><?= $badDate($r['exit_date']) ? '<span class="chk-law">wrong date</span>' : h($fd($r['exit_date'])) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="employee_edit.php?id=<?= (int)$r['employee_id'] ?>">Open profile</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  <?php endif; ?>
  </div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
