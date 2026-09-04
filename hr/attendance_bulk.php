<?php
/**
 * Attendance bulk apply — shared HR (all companies).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_role(['Owner', 'Admin', 'HR'], $conn);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$me_id = $_SESSION['user']['id'] ?? null;
$companies = hr_active_companies($conn);

$postCompanyId = isset($_POST['company_id']) ? (int)$_POST['company_id'] : (isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0);
$selectedCompanyId = 0;
foreach ($companies as $c) {
    if ((int)$c['id'] === $postCompanyId) {
        $selectedCompanyId = $postCompanyId;
        break;
    }
}

$departments = $conn->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$locations = $conn->query("SELECT id, name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Supervisors = current employees who manage at least one current employee (shared HR).
$supervisorSql = "
    SELECT DISTINCT m.id, CONCAT(m.full_name, ' (', m.employee_code, ')') AS label, m.company_id
    FROM employees e
    JOIN employees m ON m.id = e.manager_id
    WHERE e.status IN (" . hr_employee_status_in_sql(hr_employee_current_statuses()) . ")
      AND m.status IN (" . hr_employee_status_in_sql(hr_employee_current_statuses()) . ")
";
$supervisorParams = array_merge(hr_employee_current_statuses(), hr_employee_current_statuses());
if ($selectedCompanyId > 0) {
    $supervisorSql .= " AND e.company_id = ? AND m.company_id = ?";
    $supervisorParams[] = $selectedCompanyId;
    $supervisorParams[] = $selectedCompanyId;
}
$supervisorSql .= " ORDER BY m.full_name";
$supervisorStmt = $conn->prepare($supervisorSql);
$supervisorStmt->execute($supervisorParams);
$supervisors = $supervisorStmt->fetchAll(PDO::FETCH_ASSOC);

$employeeWhere = ["e.status IN (" . hr_employee_status_in_sql(hr_employee_current_statuses()) . ")"];
$employeeParams = hr_employee_current_statuses();
if ($selectedCompanyId > 0) {
    $employeeWhere[] = "e.company_id = ?";
    $employeeParams[] = $selectedCompanyId;
}
$employeeSql = "
    SELECT e.id,
           CONCAT(e.full_name, ' (', e.employee_code, ')') AS label,
           e.company_id,
           e.department_id,
           e.location_id,
           e.manager_id,
           c.name AS company_name
    FROM employees e
    LEFT JOIN companies c ON c.id = e.company_id
    WHERE " . implode(' AND ', $employeeWhere) . "
    ORDER BY e.full_name
";
$employeeStmt = $conn->prepare($employeeSql);
$employeeStmt->execute($employeeParams);
$employees_all = $employeeStmt->fetchAll(PDO::FETCH_ASSOC);

$flash_err = $flash_ok = '';
$report = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_apply'])) {
    csrf_verify();

    $date_from = $_POST['date_from'] ?? '';
    $date_to = $_POST['date_to'] ?? '';
    if (!$date_from || !$date_to) {
        $flash_err = 'Please select date range.';
    } else {
        $start = strtotime($date_from);
        $end = strtotime($date_to);
        if (!$start || !$end || $end < $start) {
            $flash_err = 'Invalid date range.';
        }
    }

    $employee_ids = array_values(array_unique(array_map('intval', $_POST['employee_ids'] ?? [])));
    $company_id = (int)($_POST['company_id'] ?? 0);
    $dept_id = (int)($_POST['department_id'] ?? 0);
    $loc_id = (int)($_POST['location_id'] ?? 0);
    $supervisor_id = (int)($_POST['supervisor_id'] ?? 0);

    $days_flags = [
        'Mon' => !empty($_POST['day_mon']),
        'Tue' => !empty($_POST['day_tue']),
        'Wed' => !empty($_POST['day_wed']),
        'Thu' => !empty($_POST['day_thu']),
        'Fri' => !empty($_POST['day_fri']),
        'Sat' => !empty($_POST['day_sat']),
        'Sun' => !empty($_POST['day_sun']),
    ];

    $status = $_POST['status'] ?? 'approved';
    $mode = $_POST['hours_mode'] ?? 'clock';
    $check_in = ($_POST['check_in'] ?? '') !== '' ? $_POST['check_in'] : null;
    $check_out = ($_POST['check_out'] ?? '') !== '' ? $_POST['check_out'] : null;
    $fixed_hours = ($_POST['fixed_hours'] ?? '') !== '' ? (float)$_POST['fixed_hours'] : null;
    $notes = trim($_POST['notes'] ?? '');
    $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === '1';

    // Resolve targets: explicit selection OR filters. Always current workforce only.
    if (!$flash_err) {
        $where = ["status IN (" . hr_employee_status_in_sql(hr_employee_current_statuses()) . ")"];
        $prms = hr_employee_current_statuses();

        if ($company_id > 0) {
            $where[] = "company_id = ?";
            $prms[] = $company_id;
        }
        if ($dept_id > 0) {
            $where[] = "department_id = ?";
            $prms[] = $dept_id;
        }
        if ($loc_id > 0) {
            $where[] = "location_id = ?";
            $prms[] = $loc_id;
        }
        if ($supervisor_id > 0) {
            $where[] = "manager_id = ?";
            $prms[] = $supervisor_id;
        }

        if ($employee_ids) {
            // Intersect explicit picks with current-workforce + filters (drops terminated).
            $ph = implode(',', array_fill(0, count($employee_ids), '?'));
            $where[] = "id IN ($ph)";
            $prms = array_merge($prms, $employee_ids);
        }

        $stmt = $conn->prepare("SELECT id FROM employees WHERE " . implode(' AND ', $where));
        $stmt->execute($prms);
        $employee_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $employee_ids = array_values(array_unique($employee_ids));
    }

    if (!$flash_err && !$employee_ids) {
        $flash_err = 'No current employees matched your Company / Department / Location / Supervisor filters (left staff are excluded).';
    }

    if (!$flash_err) {
        if ($mode === 'clock') {
            if ($status === 'approved' && (!$check_in || !$check_out)) {
                $flash_err = 'For Approved status with clock mode, please set Check-in and Check-out (or switch to Fixed Hours).';
            }
        } elseif ($status === 'approved' && ($fixed_hours === null || $fixed_hours <= 0)) {
            $flash_err = 'Please enter positive Fixed Hours.';
        }
    }

    $calc_hours = static function ($in, $out) {
        if (!$in || !$out) {
            return null;
        }
        $a = strtotime("1970-01-01 $in UTC");
        $b = strtotime("1970-01-01 $out UTC");
        if ($a === false || $b === false || $b <= $a) {
            return null;
        }
        return round(($b - $a) / 3600, 2);
    };

    if (!$flash_err) {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $rows = 0;

        $ins = $conn->prepare("
            INSERT INTO attendance
              (employee_id, work_date, check_in, check_out, hours, status, source, notes, created_by, updated_by)
            VALUES (?,?,?,?,?,?, 'admin', ?, ?, ?)
        ");
        $upd = $conn->prepare("
            UPDATE attendance
               SET check_in=?, check_out=?, hours=?, status=?, notes=?, updated_by=?, updated_at=NOW()
             WHERE employee_id=? AND work_date=?
        ");
        $chk = $conn->prepare("SELECT id FROM attendance WHERE employee_id=? AND work_date=?");

        for ($d = $start; $d <= $end; $d += 86400) {
            $w = date('D', $d);
            if (empty($days_flags[$w])) {
                continue;
            }
            $work_date = date('Y-m-d', $d);

            foreach ($employee_ids as $eid) {
                $rows++;
                if ($mode === 'clock') {
                    $in = $check_in;
                    $out = $check_out;
                    $hrs = ($status === 'approved') ? $calc_hours($in, $out) : null;
                } else {
                    $in = $out = null;
                    $hrs = ($status === 'approved') ? $fixed_hours : null;
                }

                if (!$overwrite) {
                    $chk->execute([$eid, $work_date]);
                    if ($chk->fetchColumn()) {
                        $skipped++;
                        continue;
                    }
                    $ins->execute([$eid, $work_date, $in, $out, $hrs, $status, $notes, $me_id, $me_id]);
                    $created++;
                } else {
                    $chk->execute([$eid, $work_date]);
                    if ($chk->fetchColumn()) {
                        $upd->execute([$in, $out, $hrs, $status, $notes, $me_id, $eid, $work_date]);
                        $updated++;
                    } else {
                        $ins->execute([$eid, $work_date, $in, $out, $hrs, $status, $notes, $me_id, $me_id]);
                        $created++;
                    }
                }
            }
        }

        $flash_ok = "Done. Target rows: $rows — Created: $created, Updated: $updated, Skipped: $skipped. Employees: " . count($employee_ids) . " (current workforce only).";
        $report = compact('rows', 'created', 'updated', 'skipped');
        $report['employee_count'] = count($employee_ids);

        $dateFrom = date('Y-m-d', $start);
        $dateTo = date('Y-m-d', $end);
        audit_bridge_hr_ops(
            'attendance_bulk_applied',
            'attendance_bulk',
            $company_id > 0 ? $company_id : (int)count($employee_ids),
            'Bulk attendance apply: status=' . $status
                . ', ' . count($employee_ids) . ' employee(s), ' . $dateFrom . ' → ' . $dateTo
                . ' (created ' . $created . ', updated ' . $updated . ', skipped ' . $skipped . ')',
            $company_id > 0 ? $company_id : null,
            [
                'status' => $status,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'employee_count' => count($employee_ids),
                'employee_ids_sample' => array_slice($employee_ids, 0, 25),
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'overwrite' => $overwrite,
                'hours_mode' => $mode,
            ],
            'Bulk ' . $dateFrom . ' → ' . $dateTo,
            $me_id ? (int)$me_id : null
        );
    }
}

$chosen = array_map('intval', $_POST['employee_ids'] ?? []);
$currentEmpCount = count($employees_all);
$defaultsDays = [
    'day_mon' => true, 'day_tue' => true, 'day_wed' => true, 'day_thu' => true, 'day_fri' => true,
    'day_sat' => false, 'day_sun' => false,
];
$dayVals = array_merge($defaultsDays, $_POST ?: []);
$daysMeta = [
    'day_mon' => 'Mon', 'day_tue' => 'Tue', 'day_wed' => 'Wed',
    'day_thu' => 'Thu', 'day_fri' => 'Fri', 'day_sat' => 'Sat', 'day_sun' => 'Sun',
];
$hoursMode = $_POST['hours_mode'] ?? 'clock';
$statusCur = $_POST['status'] ?? 'approved';
$overwriteCur = ($_POST['overwrite'] ?? '0') === '1' ? '1' : '0';

$pageTitle = 'Attendance Bulk';
$hrScopeLabel = $selectedCompanyId > 0
    ? (hr_company_scope_label($companies, $selectedCompanyId) ?: ('Company #' . $selectedCompanyId))
    : 'All companies';
$pageStyles = <<<'CSS'
.bulk-step-num{width:28px;height:28px;border-radius:8px;background:var(--hr-primary-soft);color:var(--hr-primary);display:inline-grid;place-items:center;font-weight:700;font-size:.8rem;flex-shrink:0}
.bulk-day-chip{position:relative}
.bulk-day-chip input{position:absolute;opacity:0;pointer-events:none}
.bulk-day-chip label{display:inline-flex;align-items:center;justify-content:center;min-width:3rem;padding:.45rem .7rem;border:1px solid var(--hr-border);border-radius:999px;background:#fff;cursor:pointer;font-weight:600;font-size:.85rem;color:var(--hr-text-muted);transition:all .15s ease;user-select:none}
.bulk-day-chip input:checked + label{background:var(--hr-primary-soft);border-color:#f0d78c;color:var(--hr-primary)}
.bulk-day-chip label:hover{border-color:color-mix(in srgb, var(--hr-primary) 40%, var(--hr-border))}
.bulk-mode-card{border:1px solid var(--hr-border);border-radius:12px;padding:1rem 1.15rem;background:#fff;height:100%;transition:border-color .15s,box-shadow .15s}
.bulk-mode-card.is-active{border-color:#f0d78c;box-shadow:0 0 0 3px var(--hr-primary-soft);background:linear-gradient(180deg,#fffef9,#fff)}
.bulk-emp-panel{border:1px solid var(--hr-border);border-radius:12px;overflow:hidden;background:#fff}
.bulk-emp-toolbar{padding:.75rem 1rem;background:#fafafa;border-bottom:1px solid var(--hr-border)}
.bulk-emp-list{max-height:280px;overflow:auto}
.bulk-emp-row{display:flex;align-items:flex-start;gap:.65rem;padding:.55rem 1rem;border-bottom:1px solid #f3f4f6;margin:0}
.bulk-emp-row:hover{background:#fffef5}
.bulk-emp-row.d-none{display:none!important}
.bulk-apply-bar{position:sticky;bottom:0;z-index:5;background:rgba(255,255,255,.92);backdrop-filter:blur(8px);border-top:1px solid var(--hr-border);padding:1rem 1.5rem;margin:1.25rem -1.5rem -1.25rem;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem}
CSS;

require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Attendance — Bulk Apply',
    'Apply one attendance pattern across a date range for the current workforce (' . (int)$currentEmpCount . ' employee' . ($currentEmpCount === 1 ? '' : 's') . ' in scope).',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Attendance', 'href' => $hrBase . '/attendance'],
        ['label' => 'Bulk Apply'],
    ],
    '<a class="btn btn-outline-secondary btn-sm" href="attendance.php"><i data-lucide="arrow-left" style="width:14px;height:14px" class="me-1"></i>Back to list</a>'
    . '<a class="btn btn-outline-secondary btn-sm" href="attendance_summary.php"><i data-lucide="calendar-range" style="width:14px;height:14px" class="me-1"></i>Monthly summary</a>'
);
?>

  <?php if ($flash_err): ?><div class="alert alert-danger"><?= h($flash_err) ?></div><?php endif; ?>
  <?php if ($flash_ok): ?><div class="alert alert-success"><?= h($flash_ok) ?></div><?php endif; ?>

  <?php if ($report): ?>
  <div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><?= hr_ui_kpi(['label' => 'Employees', 'value' => (string)(int)($report['employee_count'] ?? 0), 'icon' => 'users']) ?></div>
    <div class="col-6 col-lg-3"><?= hr_ui_kpi(['label' => 'Created', 'value' => (string)(int)$report['created'], 'icon' => 'plus-circle']) ?></div>
    <div class="col-6 col-lg-3"><?= hr_ui_kpi(['label' => 'Updated', 'value' => (string)(int)$report['updated'], 'icon' => 'refresh-cw']) ?></div>
    <div class="col-6 col-lg-3"><?= hr_ui_kpi(['label' => 'Skipped', 'value' => (string)(int)$report['skipped'], 'sub' => 'of ' . (int)$report['rows'] . ' candidate rows', 'icon' => 'skip-forward']) ?></div>
  </div>
  <?php endif; ?>

  <div class="hr-settings-card mb-3">
    <div class="card-body py-3 d-flex flex-wrap gap-3 align-items-start">
      <div class="kpi-icon" style="width:40px;height:40px;border-radius:10px;background:var(--hr-primary-soft);color:var(--hr-primary);display:grid;place-items:center;flex-shrink:0">
        <i data-lucide="info" style="width:20px;height:20px"></i>
      </div>
      <div class="small text-muted mb-0">
        Shared HR — works across companies. Only <strong>current workforce</strong> (active / on leave / notice) is included; left staff are never listed.
        Leave the employee list empty to apply to everyone matching the filters below. Changing <strong>Company</strong> reloads this page.
      </div>
    </div>
  </div>

  <form method="post" id="bulkAttendanceForm">
    <?php csrf_field(); ?>
    <input type="hidden" name="bulk_apply" value="1">

    <div class="hr-settings-card mb-3">
      <div class="settings-header d-flex align-items-center gap-2">
        <span class="bulk-step-num">1</span>
        <div>
          <div class="fw-semibold">Period &amp; scope</div>
          <small class="text-muted">Date range and org filters</small>
        </div>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Date from *</label>
            <input type="date" name="date_from" class="form-control" value="<?= h($_POST['date_from'] ?? date('Y-m-01')) ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Date to *</label>
            <input type="date" name="date_to" class="form-control" value="<?= h($_POST['date_to'] ?? date('Y-m-d')) ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Company</label>
            <select name="company_id" id="filter_company_id" class="form-select">
              <option value="0">All companies</option>
              <?php foreach ($companies as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $selectedCompanyId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Supervisor</label>
            <select name="supervisor_id" id="filter_supervisor_id" class="form-select">
              <option value="0">Any</option>
              <?php $curSup = (int)($_POST['supervisor_id'] ?? 0); foreach ($supervisors as $s): ?>
                <option value="<?= (int)$s['id'] ?>" data-company="<?= (int)$s['company_id'] ?>" <?= $curSup === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Department</label>
            <select name="department_id" id="filter_department_id" class="form-select">
              <option value="0">Any</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= (int)$d['id'] ?>" <?= ((int)$d['id'] === (int)($_POST['department_id'] ?? 0)) ? 'selected' : '' ?>><?= h($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Location</label>
            <select name="location_id" id="filter_location_id" class="form-select">
              <option value="0">Any</option>
              <?php foreach ($locations as $l): ?>
                <option value="<?= (int)$l['id'] ?>" <?= ((int)$l['id'] === (int)($_POST['location_id'] ?? 0)) ? 'selected' : '' ?>><?= h($l['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="hr-settings-card mb-3">
      <div class="settings-header d-flex align-items-center gap-2">
        <span class="bulk-step-num">2</span>
        <div class="flex-grow-1">
          <div class="fw-semibold">Who to include</div>
          <small class="text-muted">Optional picks — empty means all matching filters</small>
        </div>
        <span class="hr-pill hr-pill-muted" id="bulkEmpVisibleCount"><?= (int)$currentEmpCount ?> listed</span>
      </div>
      <div class="card-body">
        <div class="bulk-emp-panel">
          <div class="bulk-emp-toolbar d-flex flex-wrap gap-2 align-items-center">
            <div class="flex-grow-1" style="min-width:200px">
              <input type="search" id="bulkEmpSearch" class="form-control form-control-sm" placeholder="Search name or code…" autocomplete="off">
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="bulkEmpSelectVisible">Select visible</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="bulkEmpClear">Clear</button>
            <span class="small text-muted" id="bulkEmpSelectedCount">0 selected</span>
          </div>
          <div class="bulk-emp-list" id="bulkEmpList">
            <?php if (!$employees_all): ?>
              <div class="p-4 text-center text-muted small">No current employees in this company scope.</div>
            <?php else: foreach ($employees_all as $e):
              $eid = (int)$e['id'];
              $label = $e['label'] . ($selectedCompanyId ? '' : ' — ' . ($e['company_name'] ?? '—'));
              $checked = in_array($eid, $chosen, true);
            ?>
              <label class="bulk-emp-row"
                     data-company="<?= (int)$e['company_id'] ?>"
                     data-department="<?= (int)($e['department_id'] ?? 0) ?>"
                     data-location="<?= (int)($e['location_id'] ?? 0) ?>"
                     data-supervisor="<?= (int)($e['manager_id'] ?? 0) ?>"
                     data-label="<?= h(strtolower($label)) ?>">
                <input class="form-check-input mt-1 bulk-emp-check" type="checkbox" name="employee_ids[]" value="<?= $eid ?>" <?= $checked ? 'checked' : '' ?>>
                <span>
                  <span class="fw-semibold d-block"><?= h($e['label']) ?></span>
                  <?php if (!$selectedCompanyId && !empty($e['company_name'])): ?>
                    <span class="small text-muted"><?= h($e['company_name']) ?></span>
                  <?php endif; ?>
                </span>
              </label>
            <?php endforeach; endif; ?>
          </div>
        </div>
        <p class="form-text mb-0 mt-2">Filters above hide people from this list. Left / terminated staff are never shown.</p>
      </div>
    </div>

    <div class="hr-settings-card mb-3">
      <div class="settings-header d-flex align-items-center gap-2">
        <span class="bulk-step-num">3</span>
        <div>
          <div class="fw-semibold">Days of week</div>
          <small class="text-muted">Only checked weekdays inside the date range are applied</small>
        </div>
      </div>
      <div class="card-body">
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($daysMeta as $name => $label): ?>
            <div class="bulk-day-chip">
              <input type="checkbox" name="<?= $name ?>" id="<?= $name ?>" value="1" <?= !empty($dayVals[$name]) ? 'checked' : '' ?>>
              <label for="<?= $name ?>"><?= $label ?></label>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="mt-2">
          <button type="button" class="btn btn-link btn-sm px-0" id="bulkDaysWeekdays">Weekdays</button>
          <span class="text-muted">·</span>
          <button type="button" class="btn btn-link btn-sm px-0" id="bulkDaysAll">All days</button>
          <span class="text-muted">·</span>
          <button type="button" class="btn btn-link btn-sm px-0" id="bulkDaysClear">Clear</button>
        </div>
      </div>
    </div>

    <div class="hr-settings-card mb-3">
      <div class="settings-header d-flex align-items-center gap-2">
        <span class="bulk-step-num">4</span>
        <div>
          <div class="fw-semibold">Status &amp; hours</div>
          <small class="text-muted">Same pattern applied to every selected employee / day</small>
        </div>
      </div>
      <div class="card-body">
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label">Status to apply</label>
            <select name="status" class="form-select">
              <?php
                $sts = ['approved' => 'Approved (present)', 'absent' => 'Absent', 'half' => 'Half day', 'on_leave' => 'On leave'];
              foreach ($sts as $k => $v): ?>
                <option value="<?= h($k) ?>" <?= $statusCur === $k ? 'selected' : '' ?>><?= h($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-8">
            <label class="form-label">Notes (optional)</label>
            <input type="text" name="notes" class="form-control" value="<?= h($_POST['notes'] ?? '') ?>" placeholder="Shown on created / updated attendance rows">
          </div>
        </div>

        <label class="form-label">Hours mode</label>
        <div class="row g-3">
          <div class="col-lg-6">
            <div class="bulk-mode-card <?= $hoursMode === 'clock' ? 'is-active' : '' ?>" data-mode-card="clock">
              <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="hours_mode" id="mode_clock" value="clock" <?= $hoursMode === 'clock' ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="mode_clock">Clock in / out</label>
              </div>
              <p class="small text-muted mb-2">Same times for everyone. Hours are calculated from check-in → check-out when status is Approved.</p>
              <div class="row g-2">
                <div class="col-6">
                  <label class="form-label">Check-in</label>
                  <input type="time" name="check_in" class="form-control" value="<?= h($_POST['check_in'] ?? '') ?>">
                </div>
                <div class="col-6">
                  <label class="form-label">Check-out</label>
                  <input type="time" name="check_out" class="form-control" value="<?= h($_POST['check_out'] ?? '') ?>">
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="bulk-mode-card <?= $hoursMode === 'fixed' ? 'is-active' : '' ?>" data-mode-card="fixed">
              <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="hours_mode" id="mode_fixed" value="fixed" <?= $hoursMode === 'fixed' ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="mode_fixed">Fixed hours</label>
              </div>
              <p class="small text-muted mb-2">Store a fixed hour total with no clock times (useful for approved present days).</p>
              <label class="form-label">Hours</label>
              <input type="number" step="0.25" min="0" name="fixed_hours" class="form-control" placeholder="e.g. 8.0" value="<?= h($_POST['fixed_hours'] ?? '') ?>">
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="hr-settings-card mb-3">
      <div class="settings-header d-flex align-items-center gap-2">
        <span class="bulk-step-num">5</span>
        <div>
          <div class="fw-semibold">Conflicts &amp; apply</div>
          <small class="text-muted">What to do when a day already has attendance</small>
        </div>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <div class="form-check p-3 border rounded-3 mb-2">
              <input class="form-check-input" type="radio" name="overwrite" id="ovw_no" value="0" <?= $overwriteCur === '0' ? 'checked' : '' ?>>
              <label class="form-check-label" for="ovw_no">
                <strong>Skip existing</strong>
                <div class="small text-muted">Safer default — leave rows that already exist untouched</div>
              </label>
            </div>
            <div class="form-check p-3 border rounded-3">
              <input class="form-check-input" type="radio" name="overwrite" id="ovw_yes" value="1" <?= $overwriteCur === '1' ? 'checked' : '' ?>>
              <label class="form-check-label" for="ovw_yes">
                <strong>Overwrite existing</strong>
                <div class="small text-muted">Replace check-in/out, hours, status, and notes on matching days</div>
              </label>
            </div>
          </div>
          <div class="col-md-6">
            <div class="alert alert-light border small mb-0">
              Review the date range, days, and filters before applying. Large ranges × many employees can create hundreds of rows.
            </div>
          </div>
        </div>

        <div class="bulk-apply-bar">
          <div class="small text-muted" id="bulkApplyHint">Ready when date range and at least one weekday are set.</div>
          <button type="submit" class="btn btn-primary px-4">
            <i data-lucide="check" style="width:16px;height:16px" class="me-1"></i>Apply bulk attendance
          </button>
        </div>
      </div>
    </div>
  </form>

<?php
$pageScripts = <<<'JS'
<script>
(function () {
  var companySel = document.getElementById('filter_company_id');
  if (companySel) {
    companySel.addEventListener('change', function () {
      var url = new URL(window.location.href);
      url.searchParams.set('company_id', companySel.value || '0');
      window.location.href = url.toString();
    });
  }

  function syncModeCards() {
    var mode = (document.querySelector('input[name="hours_mode"]:checked') || {}).value || 'clock';
    document.querySelectorAll('[data-mode-card]').forEach(function (card) {
      card.classList.toggle('is-active', card.getAttribute('data-mode-card') === mode);
    });
  }
  document.querySelectorAll('input[name="hours_mode"]').forEach(function (r) {
    r.addEventListener('change', syncModeCards);
  });
  document.querySelectorAll('[data-mode-card]').forEach(function (card) {
    card.addEventListener('click', function (e) {
      if (e.target.closest('input,label,button,a')) return;
      var radio = card.querySelector('input[type="radio"]');
      if (radio) { radio.checked = true; syncModeCards(); }
    });
  });
  syncModeCards();

  var rows = Array.prototype.slice.call(document.querySelectorAll('#bulkEmpList .bulk-emp-row'));
  var searchEl = document.getElementById('bulkEmpSearch');
  var visibleCountEl = document.getElementById('bulkEmpVisibleCount');
  var selectedCountEl = document.getElementById('bulkEmpSelectedCount');

  function filterEmployees() {
    var company = parseInt((document.getElementById('filter_company_id') || {}).value || '0', 10);
    var dept = parseInt((document.getElementById('filter_department_id') || {}).value || '0', 10);
    var loc = parseInt((document.getElementById('filter_location_id') || {}).value || '0', 10);
    var sup = parseInt((document.getElementById('filter_supervisor_id') || {}).value || '0', 10);
    var q = ((searchEl && searchEl.value) || '').toLowerCase().trim();
    var visible = 0;
    rows.forEach(function (row) {
      var ok = true;
      if (company && parseInt(row.getAttribute('data-company') || '0', 10) !== company) ok = false;
      if (dept && parseInt(row.getAttribute('data-department') || '0', 10) !== dept) ok = false;
      if (loc && parseInt(row.getAttribute('data-location') || '0', 10) !== loc) ok = false;
      if (sup && parseInt(row.getAttribute('data-supervisor') || '0', 10) !== sup) ok = false;
      if (q && (row.getAttribute('data-label') || '').indexOf(q) === -1) ok = false;
      row.classList.toggle('d-none', !ok);
      if (!ok) {
        var cb = row.querySelector('.bulk-emp-check');
        if (cb) cb.checked = false;
      } else {
        visible++;
      }
    });
    if (visibleCountEl) visibleCountEl.textContent = visible + ' listed';
    updateSelectedCount();
  }

  function updateSelectedCount() {
    var n = document.querySelectorAll('#bulkEmpList .bulk-emp-check:checked').length;
    if (selectedCountEl) selectedCountEl.textContent = n + ' selected';
  }

  ['filter_department_id', 'filter_location_id', 'filter_supervisor_id'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('change', filterEmployees);
  });
  if (searchEl) searchEl.addEventListener('input', filterEmployees);
  document.getElementById('bulkEmpList')?.addEventListener('change', function (e) {
    if (e.target && e.target.classList.contains('bulk-emp-check')) updateSelectedCount();
  });
  document.getElementById('bulkEmpSelectVisible')?.addEventListener('click', function () {
    rows.forEach(function (row) {
      if (row.classList.contains('d-none')) return;
      var cb = row.querySelector('.bulk-emp-check');
      if (cb) cb.checked = true;
    });
    updateSelectedCount();
  });
  document.getElementById('bulkEmpClear')?.addEventListener('click', function () {
    document.querySelectorAll('#bulkEmpList .bulk-emp-check').forEach(function (cb) { cb.checked = false; });
    updateSelectedCount();
  });

  function setDays(map) {
    Object.keys(map).forEach(function (name) {
      var el = document.getElementById(name);
      if (el) el.checked = !!map[name];
    });
  }
  document.getElementById('bulkDaysWeekdays')?.addEventListener('click', function () {
    setDays({day_mon:1,day_tue:1,day_wed:1,day_thu:1,day_fri:1,day_sat:0,day_sun:0});
  });
  document.getElementById('bulkDaysAll')?.addEventListener('click', function () {
    setDays({day_mon:1,day_tue:1,day_wed:1,day_thu:1,day_fri:1,day_sat:1,day_sun:1});
  });
  document.getElementById('bulkDaysClear')?.addEventListener('click', function () {
    setDays({day_mon:0,day_tue:0,day_wed:0,day_thu:0,day_fri:0,day_sat:0,day_sun:0});
  });

  filterEmployees();
  if (window.HrUiV2 && typeof window.HrUiV2.initLucide === 'function') window.HrUiV2.initLucide();
})();
</script>
JS;
require_once __DIR__ . '/includes/hr_layout_footer.php';
?>
