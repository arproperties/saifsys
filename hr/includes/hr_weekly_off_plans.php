<?php
/**
 * Weekly off plans: HR picks employees, a weekday and a date range (Attendance >
 * Add Weekly Off). One save is one group, listed and deleted on
 * attendance_weekly_off.php.
 *
 * The Attendance list shows those days as Weekly Off when nothing is recorded
 * (hr_attendance_off_days). Nothing is written to attendance, so payroll is
 * unchanged. Tables: migrations/hr_attendance_weekly_off.sql.
 */

require_once __DIR__ . '/hr_weekly_off.php';
require_once __DIR__ . '/hr_employee_lifecycle.php';
require_once __DIR__ . '/../../includes/searchable_select.php';

/** Whether the two tables exist yet (the migration has run). */
function hr_weekly_off_plans_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query("SELECT 1 FROM hr_weekly_off_plan_employees p JOIN hr_weekly_off_plans w ON w.id = p.plan_id LIMIT 1");
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Save one group. Returns ['ok' => bool, 'message' => string, 'plan_id' => ?int].
 * $employeeIds must be current employees; the range is at most two years;
 * $repeatWeeks 1 = every week, 2 = every other week (up to 4).
 */
function hr_weekly_off_plan_add(PDO $conn, array $employeeIds, $weekday, string $from, string $to, ?int $userId, $repeatWeeks = 1): array
{
    $day = hr_weekly_off_valid_day($weekday);
    $repeat = (string)(int)$repeatWeeks === trim((string)$repeatWeeks) ? (int)$repeatWeeks : 0;
    $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
    $isDate = static fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
    if (!$ids) {
        return ['ok' => false, 'message' => 'Choose at least one employee.', 'plan_id' => null];
    }
    if ($day === null) {
        return ['ok' => false, 'message' => 'Choose the weekly off day.', 'plan_id' => null];
    }
    if ($repeat < 1 || $repeat > 4) {
        return ['ok' => false, 'message' => 'Repeats every: give a number of weeks from 1 to 4.', 'plan_id' => null];
    }
    if (!$isDate($from) || !$isDate($to)) {
        return ['ok' => false, 'message' => 'Give both dates, From and To.', 'plan_id' => null];
    }
    if ($to < $from) {
        return ['ok' => false, 'message' => 'To cannot be before From.', 'plan_id' => null];
    }
    if ($to > date('Y-m-d', strtotime($from . ' +2 years'))) {
        return ['ok' => false, 'message' => 'The range can be at most two years.', 'plan_id' => null];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $current = hr_employee_current_statuses();
    $st = $conn->prepare("SELECT id FROM employees WHERE id IN ($ph) AND status IN (" . implode(',', array_fill(0, count($current), '?')) . ")");
    $st->execute(array_merge($ids, $current));
    $valid = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (count($valid) !== count($ids)) {
        return ['ok' => false, 'message' => 'Only current employees can be chosen.', 'plan_id' => null];
    }

    $conn->beginTransaction();
    try {
        $conn->prepare("INSERT INTO hr_weekly_off_plans (weekday, repeat_weeks, date_from, date_to, created_by) VALUES (?, ?, ?, ?, ?)")
             ->execute([$day, $repeat, $from, $to, $userId]);
        $planId = (int)$conn->lastInsertId();
        $ins = $conn->prepare("INSERT INTO hr_weekly_off_plan_employees (plan_id, employee_id) VALUES (?, ?)");
        foreach ($valid as $eid) {
            $ins->execute([$planId, $eid]);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }
    return ['ok' => true, 'message' => '', 'plan_id' => $planId];
}

/**
 * Every group, newest first, each with its employees:
 * list of ['id', 'weekday', 'date_from', 'date_to', 'created_at', 'created_by_name', 'employees' => [...]].
 */
function hr_weekly_off_plan_list(PDO $conn, int $companyId = 0): array
{
    $plans = [];
    foreach ($conn->query("SELECT w.id, w.weekday, w.repeat_weeks, w.date_from, w.date_to, w.created_at, COALESCE(NULLIF(u.fullname, ''), u.username) AS created_by_name
        FROM hr_weekly_off_plans w LEFT JOIN `user` u ON u.id = w.created_by
        ORDER BY w.date_from DESC, w.id DESC") as $p) {
        $plans[(int)$p['id']] = $p + ['employees' => []];
    }
    if (!$plans) {
        return [];
    }
    $sql = "SELECT pe.plan_id, e.id, e.full_name, e.employee_code, e.status, c.name AS company_name, e.company_id
        FROM hr_weekly_off_plan_employees pe
        JOIN employees e ON e.id = pe.employee_id
        LEFT JOIN companies c ON c.id = e.company_id
        ORDER BY e.full_name";
    foreach ($conn->query($sql) as $e) {
        if (isset($plans[(int)$e['plan_id']])) {
            $plans[(int)$e['plan_id']]['employees'][] = $e;
        }
    }
    // A company chosen at the top: only groups with someone from that company.
    if ($companyId > 0) {
        $plans = array_filter($plans, static fn($p) => (bool)array_filter($p['employees'], static fn($e) => (int)$e['company_id'] === $companyId));
    }
    return array_values($plans);
}

/** Delete a whole group. Returns the deleted group (for the audit log), or null. */
function hr_weekly_off_plan_delete(PDO $conn, int $planId): ?array
{
    $st = $conn->prepare("SELECT id, weekday, date_from, date_to FROM hr_weekly_off_plans WHERE id = ?");
    $st->execute([$planId]);
    $plan = $st->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        return null;
    }
    $st = $conn->prepare("SELECT employee_id FROM hr_weekly_off_plan_employees WHERE plan_id = ?");
    $st->execute([$planId]);
    $plan['employee_ids'] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $conn->prepare("DELETE FROM hr_weekly_off_plans WHERE id = ?")->execute([$planId]); // employees go with it (cascade)
    return $plan;
}

/** Take one employee out of a group; the group goes when nobody is left. */
function hr_weekly_off_plan_remove_employee(PDO $conn, int $planId, int $employeeId): bool
{
    $st = $conn->prepare("DELETE FROM hr_weekly_off_plan_employees WHERE plan_id = ? AND employee_id = ?");
    $st->execute([$planId, $employeeId]);
    if ($st->rowCount() === 0) {
        return false;
    }
    $conn->prepare("DELETE FROM hr_weekly_off_plans WHERE id = ? AND NOT EXISTS (SELECT 1 FROM hr_weekly_off_plan_employees WHERE plan_id = ?)")
         ->execute([$planId, $planId]);
    return true;
}

/**
 * The weekdays planned for each employee, for a date range:
 * [employee_id => list of ['weekday', 'date_from', 'date_to']].
 */
function hr_weekly_off_plan_map(PDO $conn, array $employeeIds, string $from, string $to): array
{
    $ids = array_values(array_filter(array_map('intval', $employeeIds)));
    if (!$ids || !hr_weekly_off_plans_ready($conn)) {
        return [];
    }
    $st = $conn->prepare("SELECT pe.employee_id, w.weekday, w.repeat_weeks, w.date_from, w.date_to
        FROM hr_weekly_off_plans w JOIN hr_weekly_off_plan_employees pe ON pe.plan_id = w.id
        WHERE pe.employee_id IN (" . implode(',', $ids) . ") AND w.date_from <= ? AND w.date_to >= ?");
    $st->execute([$to, $from]);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[(int)$r['employee_id']][] = ['weekday' => (int)$r['weekday'], 'repeat_weeks' => max(1, (int)$r['repeat_weeks']), 'date_from' => $r['date_from'], 'date_to' => $r['date_to']];
    }
    return $map;
}

/**
 * Is $ymd (a day on the plan's weekday) one of the plan's days? Every week, or
 * every N weeks counted from the first such day on or after date_from.
 */
function hr_weekly_off_plan_hits(array $plan, string $ymd): bool
{
    if ($ymd < $plan['date_from'] || $ymd > $plan['date_to']) {
        return false;
    }
    $n = max(1, (int)($plan['repeat_weeks'] ?? 1));
    if ($n === 1) {
        return true;
    }
    // The first such day is within 6 days of date_from, so whole weeks since date_from decide.
    $days = (int)(new DateTimeImmutable($plan['date_from']))->diff(new DateTimeImmutable($ymd))->days;
    return intdiv($days, 7) % $n === 0;
}

/**
 * The Add Weekly Off popup (Bootstrap modal #woPlanModal): the form, and below
 * it every group saved so far with Delete / remove one person. Every form posts
 * to attendance_weekly_off.php, which comes back to the Attendance page with
 * the popup open. $employees: list of ['id', 'label', 'company_name'].
 */
function hr_weekly_off_plan_modal(PDO $conn, array $employees, int $companyId = 0): void
{
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $days = hr_weekly_off_day_names();
    $plans = hr_weekly_off_plan_list($conn, $companyId);
    $flash = $_SESSION['wo_plan_flash'] ?? null;
    unset($_SESSION['wo_plan_flash']);
    $today = date('Y-m-d');
    $fmt = static fn($d) => date('j M Y', strtotime($d));
    searchable_select_assets();
    ?>
  <style>
    #woPlanModal .wo-emp{display:inline-flex;align-items:center;gap:.2rem;margin:.1rem .2rem .1rem 0;padding:.1rem .2rem .1rem .55rem;border:1px solid var(--hr-border, #dee2e6);border-radius:999px;background:#f8fafc;font-size:.8rem}
    #woPlanModal .wo-emp form{display:inline}
    #woPlanModal .wo-emp .btn-close{font-size:.5rem;padding:.3rem}
    #woPlanModal .wo-list td{font-size:.875rem}
  </style>
  <div class="modal fade" id="woPlanModal" tabindex="-1" aria-labelledby="woPlanTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="woPlanTitle">Weekly off</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'success' : 'danger' ?> py-2"><?= $e($flash[1]) ?></div><?php endif; ?>
          <form method="post" action="attendance_weekly_off.php" class="row g-2 align-items-end">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="add">
            <div class="col-12">
              <label class="form-label" for="woPlanEmployees">Employees *</label>
              <select name="employee_ids[]" id="woPlanEmployees" class="form-select" multiple required>
                <?php foreach ($employees as $emp): ?>
                  <option value="<?= (int)$emp['id'] ?>"><?= $e($emp['label'] . (!empty($emp['company_name']) ? ' - ' . $emp['company_name'] : '')) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label" for="woPlanDay">Weekly off *</label>
              <select name="weekday" id="woPlanDay" class="form-select" required>
                <option value="">-- choose --</option>
                <?php foreach ([7, 1, 2, 3, 4, 5, 6] as $d): ?>
                  <option value="<?= $d ?>"><?= $e($days[$d]) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label" for="woPlanRepeat">Repeats every</label>
              <div class="input-group">
                <input type="number" name="repeat_weeks" id="woPlanRepeat" class="form-control" min="1" max="4" step="1" value="1" required>
                <span class="input-group-text">wk</span>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label" for="woPlanFrom">Effective from *</label>
              <input type="date" name="date_from" id="woPlanFrom" class="form-control" required value="<?= $e(date('Y-m-d')) ?>">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label" for="woPlanTo">To *</label>
              <input type="date" name="date_to" id="woPlanTo" class="form-control" required>
            </div>
            <div class="col-md-1 d-grid">
              <button class="btn btn-primary">Add</button>
            </div>
            <div class="col-12 small text-muted">Repeats every 1 week = every week, 2 = every other week, counted from the first such day. Shown as Weekly Off on the Attendance list on days with nothing recorded. Payroll is not affected.</div>
          </form>

          <hr>
          <h6 class="mb-2">Added (<?= count($plans) ?>)</h6>
          <?php if (!$plans): ?>
            <p class="text-muted small mb-0">No weekly off added yet.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0 wo-list">
                <thead class="table-light">
                  <tr><th>Day</th><th>From – To</th><th>Employees</th><th></th></tr>
                </thead>
                <tbody>
                  <?php foreach ($plans as $p): ?>
                    <?php $ended = $p['date_to'] < $today; ?>
                    <tr class="<?= $ended ? 'text-muted' : '' ?>">
                      <?php $rw = max(1, (int)($p['repeat_weeks'] ?? 1)); ?>
                      <td class="fw-semibold"><?= $e($days[(int)$p['weekday']] ?? '') ?><div class="small text-muted fw-normal"><?= $rw === 1 ? 'every week' : 'every ' . $rw . ' weeks' ?></div></td>
                      <td class="text-nowrap"><?= $e($fmt($p['date_from'])) ?> – <?= $e($fmt($p['date_to'])) ?><?php if ($ended): ?> <span class="badge text-bg-light border">Ended</span><?php endif; ?></td>
                      <td>
                        <?php foreach ($p['employees'] as $pe): ?>
                          <span class="wo-emp" title="<?= $e($pe['company_name'] ?? '') ?>">
                            <?= $e($pe['full_name'] . ' (' . $pe['employee_code'] . ')') ?>
                            <form method="post" action="attendance_weekly_off.php" onsubmit="return confirm(this.dataset.ask)" data-ask="<?= $e('Remove ' . $pe['full_name'] . ' from this weekly off?') ?>">
                              <?php csrf_field(); ?>
                              <input type="hidden" name="action" value="remove">
                              <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                              <input type="hidden" name="employee_id" value="<?= (int)$pe['id'] ?>">
                              <button class="btn-close" aria-label="<?= $e('Remove ' . $pe['full_name']) ?>"></button>
                            </form>
                          </span>
                        <?php endforeach; ?>
                      </td>
                      <td class="text-end">
                        <form method="post" action="attendance_weekly_off.php" onsubmit="return confirm(this.dataset.ask)" data-ask="<?= $e('Delete this weekly off for all ' . count($p['employees']) . ' employee(s)?') ?>">
                          <?php csrf_field(); ?>
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                          <button class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    var from = document.getElementById('woPlanFrom'), to = document.getElementById('woPlanTo');
    from.addEventListener('change', function () { to.min = from.value; });
    to.min = from.value;
    // Select2 inside a modal needs the modal as its parent, or its search box cannot be typed in.
    if (window.jQuery && jQuery.fn.select2) {
      jQuery('#woPlanEmployees').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Choose one or more employees', closeOnSelect: false, dropdownParent: jQuery('#woPlanModal') });
    }
  });
  <?php if ($flash !== null): ?>
  // Back from adding or deleting: show the popup again with the updated list.
  window.addEventListener('load', function () {
    if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(document.getElementById('woPlanModal')).show();
  });
  <?php endif; ?>
  </script>
    <?php
}
