<?php
/**
 * Check-in reminder settings, one row per company: on/off and the send time for
 * the staff app's daily reminder (modules/operations/includes/ops_attendance_reminder.php).
 *
 * Each company is saved on its own — attendance_reminder_enabled.c3 and
 * attendance_reminder_time.c3 in `settings` — so changing one never touches
 * another. A company nobody has set is on at 18:00. Owner, Admin and HR.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_once __DIR__ . '/../modules/operations/includes/ops_attendance_reminder.php';
require_role(['Owner','Admin','HR'], $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$ready = ops_reminder_ready($conn);
$now   = ops_reminder_now();
$today = $now->format('Y-m-d');
$msg = $err = '';
$savedCompany = 0;

$companyNames = $conn->query("SELECT id, name FROM companies")->fetchAll(PDO::FETCH_KEY_PAIR);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $cid     = (int)($_POST['company_id'] ?? 0);
    $enabled = !empty($_POST['enabled']);
    // <input type="time"> sends HH:MM, or HH:MM:SS on some browsers.
    $time = substr(trim((string)($_POST['time'] ?? '')), 0, 5);

    if (!isset($companyNames[$cid])) {
        $err = 'That company was not found.';
    } elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        $err = 'Enter the time as HH:MM, for example 18:00.';
    } else {
        $before = ops_reminder_settings($conn, $cid);
        $save = $conn->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
        $save->execute(['attendance_reminder_enabled.c' . $cid, $enabled ? '1' : '0']);
        $save->execute(['attendance_reminder_time.c' . $cid, $time]);
        ops_reminder_settings_rows($conn, true);

        $name = (string)$companyNames[$cid];
        $uid = $_SESSION['user']['id'] ?? null;
        audit_bridge_hr_ops(
            'attendance_reminder_settings_updated',
            'settings',
            $cid,
            'Check-in reminders for ' . $name . ': ' . ($enabled ? 'on at ' . $time : 'off')
                . ' (was ' . ($before['enabled'] ? 'on at ' . $before['time'] : 'off') . ')',
            $cid,
            ['company_id' => $cid, 'before' => $before, 'after' => ['time' => $time, 'enabled' => $enabled]],
            'Check-in reminders · ' . $name,
            $uid ? (int)$uid : null
        );

        $msg = "Saved for {$name}: " . ($enabled ? "reminders at {$time} (Dubai time)." : 'reminders switched off.');
        $doneToday = $ready && (bool)$conn->query("SELECT 1 FROM staff_push_runs WHERE work_date = " . $conn->quote($today) . " AND company_id = " . (int)$cid)->fetchColumn();
        if ($enabled && $doneToday && $time !== $before['time']) {
            $msg .= " Today's reminder has already gone out, so the new time applies from tomorrow.";
        }
        $savedCompany = $cid;
    }
}

// One row per company with current staff, phones turned on, or its own setting.
$current = hr_employee_current_statuses();
$staffSt = $conn->prepare("SELECT company_id, COUNT(*) FROM employees WHERE status IN (" . hr_employee_status_in_sql($current) . ") GROUP BY company_id");
$staffSt->execute($current);
$staffBy = array_map('intval', $staffSt->fetchAll(PDO::FETCH_KEY_PAIR));

$phonesBy = $peopleBy = $lastRunBy = $todayRunBy = [];
if ($ready) {
    // Same employee record the reminder run uses (the lowest id for the login).
    foreach ($conn->query("
        SELECT x.cid, COUNT(*) AS phones, COUNT(DISTINCT x.user_id) AS people
        FROM (SELECT s.user_id, (SELECT e.company_id FROM employees e WHERE e.user_id = s.user_id ORDER BY e.id ASC LIMIT 1) AS cid
              FROM staff_push_subscriptions s) x
        WHERE x.cid IS NOT NULL
        GROUP BY x.cid
    ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $phonesBy[(int)$r['cid']] = (int)$r['phones'];
        $peopleBy[(int)$r['cid']] = (int)$r['people'];
    }
    foreach ($conn->query("
        SELECT r.company_id, r.work_date, r.sent FROM staff_push_runs r
        JOIN (SELECT company_id, MAX(work_date) AS d FROM staff_push_runs WHERE company_id > 0 GROUP BY company_id) m
          ON m.company_id = r.company_id AND m.d = r.work_date
    ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $lastRunBy[(int)$r['company_id']] = $r;
        if ($r['work_date'] === $today) {
            $todayRunBy[(int)$r['company_id']] = $r;
        }
    }
}
$withSetting = [];
foreach (array_keys(ops_reminder_settings_rows($conn)) as $k) {
    if (preg_match('/\.c(\d+)$/', $k, $m)) {
        $withSetting[(int)$m[1]] = true;
    }
}
$companyIds = array_keys(array_filter($companyNames, function ($name, $id) use ($staffBy, $phonesBy, $withSetting) {
    return !empty($staffBy[$id]) || !empty($phonesBy[$id]) || !empty($withSetting[$id]);
}, ARRAY_FILTER_USE_BOTH));
usort($companyIds, fn($a, $b) => [($staffBy[$b] ?? 0), $companyNames[$a]] <=> [($staffBy[$a] ?? 0), $companyNames[$b]]);

$pageTitle = 'Check-in reminders';
$hrTopbarCrumbs = [
    ['label' => 'HR', 'href' => 'dashboard'],
    ['label' => 'Attendance', 'href' => 'attendance'],
    ['label' => 'Reminders'],
];
$pageStyles = '
  .rem-time { max-width: 8.5rem; }
  tr.rem-saved > td { background: var(--hr-primary-soft) !important; }
';
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Check-in reminders',
    'A notification on staff phones for anyone who has not checked in, or checked in and not out. Each company has its own time.',
    []
);
?>

  <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if (!$ready): ?>
    <div class="alert alert-warning">
      Reminders are not set up on this server yet. Run <code>migrations/ops_staff_push.sql</code>.
      The settings below can still be saved and take effect once it has run.
    </div>
  <?php endif; ?>

  <div class="hr-settings-card">
    <div class="settings-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span>Companies</span>
      <span class="small text-muted">Times are Dubai time. Staff follow the company on their employee record.</span>
    </div>
    <div class="card-body p-0">
    <div class="hr-table-shell hr-table-scroll border-0 shadow-none rounded-0">
      <table class="table align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Company</th>
            <th class="text-end">Staff</th>
            <th class="text-end">Phones on</th>
            <th>Reminders</th>
            <th>Send at</th>
            <th>Today</th>
            <th>Last run</th>
            <th class="text-end"></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$companyIds): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No companies with current staff.</td></tr>
          <?php endif; ?>
          <?php foreach ($companyIds as $cid):
            $s = ops_reminder_settings($conn, $cid);
            $form = 'remForm' . $cid;
            $todayRun = $todayRunBy[$cid] ?? null;
            $lastRun = $lastRunBy[$cid] ?? null;
          ?>
            <tr class="<?= $cid === $savedCompany ? 'rem-saved' : '' ?>">
              <td class="fw-semibold"><?= htmlspecialchars((string)$companyNames[$cid]) ?></td>
              <td class="text-end"><?= (int)($staffBy[$cid] ?? 0) ?></td>
              <td class="text-end">
                <?= (int)($phonesBy[$cid] ?? 0) ?>
                <?php if (!empty($peopleBy[$cid]) && $peopleBy[$cid] !== $phonesBy[$cid]): ?>
                  <span class="small text-muted">(<?= (int)$peopleBy[$cid] ?> staff)</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="form-check form-switch m-0">
                  <input class="form-check-input" type="checkbox" role="switch" form="<?= $form ?>" name="enabled" value="1"
                         id="remOn<?= $cid ?>" <?= $s['enabled'] ? 'checked' : '' ?>>
                  <label class="form-check-label" for="remOn<?= $cid ?>"><?= $s['enabled'] ? 'On' : 'Off' ?></label>
                </div>
              </td>
              <td>
                <input type="time" class="form-control form-control-sm rem-time" form="<?= $form ?>" name="time"
                       value="<?= htmlspecialchars($s['time']) ?>" required aria-label="Send time for <?= htmlspecialchars((string)$companyNames[$cid]) ?>">
              </td>
              <td class="small">
                <?php if (!$s['enabled']): ?>
                  <span class="text-muted">Switched off</span>
                <?php elseif ($todayRun): ?>
                  Done · <?= (int)$todayRun['sent'] ?> reminded
                <?php elseif ($now->format('H:i') < $s['time']): ?>
                  Goes out at <?= htmlspecialchars($s['time']) ?>
                <?php elseif (empty($phonesBy[$cid])): ?>
                  <span class="text-muted">No phones turned on</span>
                <?php else: ?>
                  Due — goes out with the next staff-app use
                <?php endif; ?>
              </td>
              <td class="small">
                <?php if ($lastRun): ?>
                  <?= htmlspecialchars(date('D j M', strtotime($lastRun['work_date']))) ?> · <?= (int)$lastRun['sent'] ?> reminded
                <?php else: ?>
                  <span class="text-muted">Never</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <form method="post" id="<?= $form ?>" class="m-0">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="company_id" value="<?= (int)$cid ?>">
                  <button class="btn btn-sm btn-primary">Save</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    </div>
  </div>

  <div class="form-text mt-3">
    Once a day per company, to staff due to work who have not checked in, or checked in and not out — not on holidays,
    approved leave, days off on their schedule, or days HR has already marked. Staff turn reminders on themselves with
    <strong>Turn on</strong> in the staff app; each employee's profile shows whether they have.
  </div>

<script>
// The On/Off label follows its switch before saving.
document.querySelectorAll('.form-switch input[name="enabled"]').forEach(function (box) {
  box.addEventListener('change', function () {
    var label = document.querySelector('label[for="' + box.id + '"]');
    if (label) label.textContent = box.checked ? 'On' : 'Off';
  });
});
</script>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
