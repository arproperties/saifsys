<?php
/**
 * Attendance monthly summary + exports — shared HR (all companies).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_once __DIR__ . '/includes/hr_weekly_off.php';
require_once __DIR__ . '/includes/hr_attendance_daily.php';
require_once __DIR__ . '/../includes/hr_attendance_attachments.php';
require_role(['Owner', 'Admin', 'HR'], $conn);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Send a tab as a download, laid out like the screen: a title line, an optional
 * second line, then the same columns. $rows are lists of cell text; $styles
 * (Excel only) holds per-row ['row' => css, 'cells' => [index => css]].
 */
function att_send_download(string $format, string $filename, string $title, string $subtitle, array $cols, array $rows, array $styles = [], array $right = []): void
{
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // lets Excel read the CSV as UTF-8; not shown
        fputcsv($out, [$title]);
        if ($subtitle !== '') {
            fputcsv($out, [$subtitle]);
        }
        fputcsv($out, $cols);
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $cell = 'border:1px solid #d0d5dd;padding:4px 8px;font-family:Calibri,Arial;font-size:11pt;vertical-align:middle;';
    echo '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>';
    echo "<table style='border-collapse:collapse'>";
    echo "<tr><td colspan='" . count($cols) . "' style='font-family:Calibri,Arial;font-size:15pt;font-weight:bold;padding:6px'>" . h($title) . '</td></tr>';
    if ($subtitle !== '') {
        echo "<tr><td colspan='" . count($cols) . "' style='font-family:Calibri,Arial;font-size:11pt;color:#4b5563;padding:2px 6px 8px'>" . h($subtitle) . '</td></tr>';
    }
    echo '<tr>';
    foreach ($cols as $i => $c) {
        $align = in_array($i, $right, true) ? 'right' : 'left';
        echo "<th style='{$cell}background:#f3f4f6;color:#374151;font-weight:bold;text-align:{$align}'>" . h($c) . '</th>';
    }
    echo '</tr>';
    foreach ($rows as $n => $r) {
        $rowCss = $styles[$n]['row'] ?? '';
        echo '<tr>';
        foreach ($r as $i => $v) {
            $align = in_array($i, $right, true) ? 'text-align:right;' : '';
            echo "<td style='{$cell}{$rowCss}{$align}" . ($styles[$n]['cells'][$i] ?? '') . "'>" . h($v) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit;
}

$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$dept = isset($_GET['department_id']) && $_GET['department_id'] !== '' ? (int)$_GET['department_id'] : null;
$loc = isset($_GET['location_id']) && $_GET['location_id'] !== '' ? (int)$_GET['location_id'] : null;
$supervisorId = isset($_GET['supervisor_id']) && $_GET['supervisor_id'] !== '' ? (int)$_GET['supervisor_id'] : null;
$employeeId = isset($_GET['employee_id']) && $_GET['employee_id'] !== '' ? (int)$_GET['employee_id'] : null;
$only_with_records = !empty($_GET['only_with_records']);
$currentOnly = !isset($_GET['include_left']) || empty($_GET['include_left']);

$companies = hr_active_companies($conn);
$selectedCompanyId = hr_selected_company_id($conn, $companies);

$departments = $conn->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$locations = $conn->query("SELECT id, name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$supervisorSql = "
    SELECT DISTINCT m.id, CONCAT(m.full_name, ' (', m.employee_code, ')') AS label
    FROM employees e
    JOIN employees m ON m.id = e.manager_id
    WHERE e.manager_id IS NOT NULL
";
$supervisorParams = [];
if ($selectedCompanyId > 0) {
    $supervisorSql .= " AND m.company_id = ?";
    $supervisorParams[] = $selectedCompanyId;
}
$supervisorSql .= " ORDER BY m.full_name";
$supervisorStmt = $conn->prepare($supervisorSql);
$supervisorStmt->execute($supervisorParams);
$supervisors = $supervisorStmt->fetchAll(PDO::FETCH_ASSOC);

$empListWhere = [];
$empListParams = [];
if ($currentOnly) {
    $empListWhere[] = "e.status IN (" . hr_employee_status_in_sql(hr_employee_current_statuses()) . ")";
    $empListParams = array_merge($empListParams, hr_employee_current_statuses());
}
if ($selectedCompanyId > 0) {
    $empListWhere[] = "e.company_id = ?";
    $empListParams[] = $selectedCompanyId;
}
$empListSql = "SELECT e.id, CONCAT(e.full_name, ' (', e.employee_code, ')') AS label FROM employees e";
if ($empListWhere) {
    $empListSql .= " WHERE " . implode(' AND ', $empListWhere);
}
$empListSql .= " ORDER BY e.full_name";
$empListStmt = $conn->prepare($empListSql);
$empListStmt->execute($empListParams);
$employees = $empListStmt->fetchAll(PDO::FETCH_ASSOC);

$params = [':m' => $month, ':y' => $year];
$where = [];
if ($currentOnly) {
    $ph = [];
    foreach (hr_employee_current_statuses() as $i => $st) {
        $key = ':curst' . $i;
        $ph[] = $key;
        $params[$key] = $st;
    }
    $where[] = "e.status IN (" . implode(',', $ph) . ")";
}
if ($selectedCompanyId > 0) {
    $where[] = "e.company_id = :company_id";
    $params[':company_id'] = $selectedCompanyId;
}
if (!is_null($dept)) {
    $where[] = "e.department_id = :dept";
    $params[':dept'] = $dept;
}
if (!is_null($loc)) {
    $where[] = "e.location_id = :loc";
    $params[':loc'] = $loc;
}
if (!is_null($supervisorId)) {
    $where[] = "e.manager_id = :supervisor_id";
    $params[':supervisor_id'] = $supervisorId;
}
if (!is_null($employeeId)) {
    $where[] = "e.id = :employee_id";
    $params[':employee_id'] = $employeeId;
}
$sqlWhere = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$sql = "
SELECT
  e.id,
  e.employee_code,
  e.full_name,
  COALESCE(c.name,'') AS company_name,
  COALESCE(d.name,'') AS department,
  COALESCE(l.name,'') AS location,
  COALESCE(SUM(CASE WHEN a.status='approved' THEN 1 ELSE 0 END),0) AS present_days,
  COALESCE(SUM(CASE WHEN a.status='absent'   THEN 1 ELSE 0 END),0) AS absent_days,
  COALESCE(SUM(CASE WHEN a.status='half'     THEN 1 ELSE 0 END),0) AS half_days,
  COALESCE(SUM(CASE WHEN a.status='on_leave' THEN 1 ELSE 0 END),0) AS leave_days,
  COALESCE(SUM(CASE WHEN a.status='excused_absent' THEN 1 ELSE 0 END),0) AS excused_days,
  COALESCE(SUM(a.hours),0) AS total_hours
FROM employees e
LEFT JOIN companies c ON c.id = e.company_id
LEFT JOIN departments d ON d.id = e.department_id
LEFT JOIN locations   l ON l.id = e.location_id
LEFT JOIN attendance  a
  ON a.employee_id = e.id
  AND MONTH(a.work_date) = :m
  AND YEAR(a.work_date)  = :y
$sqlWhere
GROUP BY e.id, e.employee_code, e.full_name, c.name, d.name, l.name
";

if ($only_with_records) {
    $sql .= " HAVING (present_days + absent_days + half_days + leave_days + excused_days) > 0";
}
$sql .= " ORDER BY e.full_name";

$qsBase = http_build_query([
    'month' => $month,
    'year' => $year,
    'company_id' => $selectedCompanyId ?: '',
    'department_id' => $dept ?? '',
    'location_id' => $loc ?? '',
    'supervisor_id' => $supervisorId ?? '',
    'employee_id' => $employeeId ?? '',
    'only_with_records' => $only_with_records ? 1 : 0,
    'include_left' => $currentOnly ? 0 : 1,
]);

$export = $_GET['export'] ?? '';
// Monthly detail: the whole company (no employee picked) or one employee, as a page or Excel.
$view = $_GET['view'] ?? '';
$tab = (($_GET['tab'] ?? '') === 'daily' || $view === 'detail') ? 'daily' : 'monthly';
$detailEmployeeId = !empty($_GET['detail_employee']) ? (int)$_GET['detail_employee'] : null;

// Day by day: every date of the month, for the company (everyone in the filters) or one employee.
// The tab, the Excel and the CSV all show the same rows.
if ($export === 'detail_xls' || $export === 'detail_csv' || $tab === 'daily') {
    $detailEmp = $detailEmployeeId ?? $employeeId;
    $scopeStmt = $conn->prepare($sql);
    $scopeStmt->execute($params);
    $scopeEmps = $scopeStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_null($detailEmp)) {
        $scopeEmps = array_values(array_filter($scopeEmps, static fn($se) => (int)$se['id'] === $detailEmp));
    }
    $dailyRows = hr_attendance_daily_rows($conn, $scopeEmps, $year, $month);
    $monthLabel = date('F Y', mktime(0, 0, 0, $month, 1, $year));
    $companyName = '';
    foreach ($companies as $co) {
        if ((int)$co['id'] === $selectedCompanyId) { $companyName = $co['name']; }
    }
    $filename = sprintf("attendance_daily_%s_%04d-%02d." . ($export === 'detail_csv' ? 'csv' : 'xls'),
        ($detailEmp && $scopeEmps) ? preg_replace('/[^A-Za-z0-9]+/', '', (string)$scopeEmps[0]['employee_code'])
            : ($companyName !== '' ? preg_replace('/[^A-Za-z0-9]+/', '_', $companyName) : 'all_companies'),
        $year, $month);
    $dailyTotals = hr_attendance_daily_totals($dailyRows);
    $dailyOne = $detailEmp && $scopeEmps;
    $dailyTitle = 'Day by day · ' . $monthLabel . ' · ' . ($dailyOne ? $scopeEmps[0]['full_name'] . ' (' . $scopeEmps[0]['employee_code'] . ')' : ($companyName !== '' ? $companyName : 'All companies'));

    if ($export === 'detail_xls' || $export === 'detail_csv') {
        $parts = [];
        foreach ($dailyTotals['by'] as $k => $n) {
            $parts[] = $n . ' ' . $k;
        }
        foreach ($dailyTotals['flags'] as $k => $n) {
            $parts[] = $n . ' ' . $k;
        }
        $parts[] = number_format($dailyTotals['hours'], 2) . ' hours';
        $out = [];
        $styles = [];
        $lastEmp = null;
        foreach ($dailyRows as $i => $r) {
            $status = $r['status'] . (($r['weekly_off'] === 'Yes' && $r['kind'] !== 'weekly_off') ? ' · weekly off' : '');
            $out[] = [$r['dow'] . ' ' . date('j M', strtotime($r['date'])), $r['full_name'] . ' (' . $r['employee_code'] . ')', $r['scheduled'],
                $r['check_in'], $r['check_out'], $r['hours'], $status, implode(' · ', $r['flags']), $r['notes']];
            [$bg, $fg] = hr_attendance_daily_colors($r['kind']);
            $rest = in_array($r['kind'], ['weekly_off', 'holiday'], true) ? 'background:#f6f7f9;' : ($r['kind'] === 'future' ? 'color:#b0b5bc;' : '');
            $top = ($lastEmp !== null && $lastEmp !== $r['employee_id']) ? 'border-top:3px solid #cbd5e1;' : '';
            $lastEmp = $r['employee_id'];
            $styles[$i] = ['row' => $rest . $top, 'cells' => [
                1 => 'font-weight:bold;',
                2 => 'color:#4b5563;',
                5 => 'font-weight:bold;',
                6 => $r['status'] !== '' ? "background:{$bg};color:{$fg};font-weight:bold;" : '',
                7 => $r['flags'] ? 'background:#fff1e6;color:#b45309;font-weight:bold;' : '',
                8 => 'color:#6b7280;',
            ]];
        }
        att_send_download($export === 'detail_csv' ? 'csv' : 'xls', $filename, $dailyTitle, $dailyOne ? implode(' · ', $parts) : '',
            ['Date', 'Employee', 'Scheduled', 'In', 'Out', 'Hours', 'Status', 'Against schedule', 'Notes'], $out, $styles, [5]);
    }
}
if ($export === 'csv' || $export === 'xls') {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $cmp = hr_attendance_compare_by_employee(hr_attendance_daily_rows($conn, $rows, $year, $month));
    $filename = sprintf("attendance_summary_%04d-%02d.%s", $year, $month, $export);
    $out = [];
    $styles = [];
    foreach ($rows as $i => $r) {
        $out[] = [$r['employee_code'], $r['full_name'], $r['company_name'], $r['department'], $r['location'],
            (string)(int)$r['present_days'], (string)(int)$r['absent_days'], (string)(int)$r['half_days'],
            (string)(int)$r['leave_days'], (string)(int)$r['excused_days'],
            number_format($cmp[(int)$r['id']]['scheduled'] ?? 0, 2), number_format((float)$r['total_hours'], 2),
            (string)($cmp[(int)$r['id']]['late'] ?? 0), (string)($cmp[(int)$r['id']]['early'] ?? 0), (string)($cmp[(int)$r['id']]['short'] ?? 0)];
        $styles[$i] = ['cells' => [0 => 'font-weight:bold;', 11 => 'font-weight:bold;']];
    }
    att_send_download($export, $filename, 'Monthly totals · ' . date('F Y', mktime(0, 0, 0, $month, 1, $year)), '',
        ['Employee Code', 'Employee', 'Company', 'Department', 'Location', 'Present', 'Absent', 'Half', 'On Leave', 'Excused Absent', 'Scheduled Hours', 'Total Hours', 'Late In', 'Early Out', 'Short Hours'],
        $out, $styles, [5, 6, 7, 8, 9, 10, 11, 12, 13, 14]);
}

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$cmp = $tab === 'monthly' ? hr_attendance_compare_by_employee(hr_attendance_daily_rows($conn, $rows, $year, $month)) : [];

function month_options($sel)
{
    for ($m = 1; $m <= 12; $m++) {
        $name = date('F', mktime(0, 0, 0, $m, 1));
        $s = ($m == $sel) ? ' selected' : '';
        echo "<option value=\"$m\"$s>$name</option>";
    }
}
function year_options($sel)
{
    $yNow = (int)date('Y');
    for ($y = $yNow - 3; $y <= $yNow + 2; $y++) {
        $s = ($y == $sel) ? ' selected' : '';
        echo "<option value=\"$y\"$s>$y</option>";
    }
}

$pageTitle = 'Attendance Summary';
$pageStyles = '
.att-tabs .nav-link { font-weight: 600; color: #4b5563; }
.att-tabs .nav-link.active { color: #111827; }
.att-day td { vertical-align: middle; }
.att-day .att-dow { display: inline-block; width: 2.6rem; color: #6b7280; font-weight: 600; }
.att-day .att-pill { display: inline-block; padding: 3px 10px; border-radius: 6px; font-weight: 600; font-size: .8rem; }
.att-day tr.att-rest td { background: #f6f7f9; }
.att-day tr.att-future td { color: #b0b5bc; }
.att-day tr.att-newemp td { border-top: 3px solid #cbd5e1; }
.att-day-chip { display: inline-flex; align-items: baseline; gap: 6px; padding: 6px 12px; border-radius: 8px; border: 1px solid #e5e7eb; background: #fff; font-size: .85rem; }
.att-day-chip strong { font-size: 1.1rem; }
.att-day-chip.att-flag { background: #fff1e6; border-color: #fed7aa; color: #b45309; }
.att-flag-pill { display: inline-block; padding: 2px 8px; border-radius: 6px; font-weight: 600; font-size: .72rem; background: #fff1e6; color: #b45309; margin-left: 4px; }
.att-sched { color: #4b5563; font-size: .85rem; } 
.att-day td:last-child { min-width: 240px; }
.att-cnt-warn { color: #b45309; font-weight: 700; }
';

$hrScopeLabel = hr_company_scope_label($companies, $selectedCompanyId);
?>
<?php require_once __DIR__ . '/includes/hr_layout_header.php'; ?>

<?php
echo hr_ui_page_header(
    'Attendance — Monthly Summary',
    ($currentOnly ? 'Current workforce only.' : 'Includes left employees.'),
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Attendance', 'href' => $hrBase . '/attendance'],
        ['label' => 'Summary'],
    ],
    '<a href="attendance.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>'
    . '<a class="btn btn-outline-primary" href="attendance_bulk.php">Bulk Apply</a>'
);
?>

  <div class="hr-filter-bar mb-3">
  <form method="get">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <div class="row g-2 align-items-end">
        <div class="col-md-2">
          <label class="form-label">Month</label>
          <select name="month" class="form-select"><?php month_options($month); ?></select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Year</label>
          <select name="year" class="form-select"><?php year_options($year); ?></select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Company</label>
          <select name="company_id" class="form-select">
            <option value="0">All companies</option>
            <?php foreach ($companies as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $selectedCompanyId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Employee</label>
          <select name="employee_id" class="form-select">
            <option value="">— All —</option>
            <?php foreach ($employees as $e): ?>
              <option value="<?= (int)$e['id'] ?>" <?= $employeeId === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Supervisor</label>
          <select name="supervisor_id" class="form-select">
            <option value="">— All —</option>
            <?php foreach ($supervisors as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= $supervisorId === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Department</label>
          <select name="department_id" class="form-select">
            <option value="">— All —</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= (int)$d['id'] ?>" <?= ($dept === (int)$d['id'] ? 'selected' : '') ?>><?= h($d['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Location</label>
          <select name="location_id" class="form-select">
            <option value="">— All —</option>
            <?php foreach ($locations as $l): ?>
              <option value="<?= (int)$l['id'] ?>" <?= ($loc === (int)$l['id'] ? 'selected' : '') ?>><?= h($l['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3 d-flex flex-column gap-1 pb-1">
          <div class="form-check m-0">
            <input class="form-check-input" type="checkbox" id="only_with_records" name="only_with_records" value="1" <?= $only_with_records ? 'checked' : '' ?>>
            <label for="only_with_records" class="form-check-label">Only with records</label>
          </div>
          <div class="form-check m-0">
            <input class="form-check-input" type="checkbox" id="include_left" name="include_left" value="1" <?= !$currentOnly ? 'checked' : '' ?>>
            <label for="include_left" class="form-check-label">Include left staff</label>
          </div>
        </div>
        <div class="col-12 d-flex flex-wrap align-items-center gap-2 mt-2">
          <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
      </div>
  </form>
  </div>

  <ul class="nav nav-tabs att-tabs mb-0">
    <li class="nav-item"><a class="nav-link <?= $tab === 'monthly' ? 'active' : '' ?>" href="?<?= h($qsBase) ?>"><i class="bi bi-table me-1"></i>Monthly totals</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'daily' ? 'active' : '' ?>" href="?<?= h($qsBase) ?>&amp;tab=daily"><i class="bi bi-calendar3 me-1"></i>Day by day</a></li>
  </ul>
  <?php if ($tab === 'daily'): ?>
  <div class="hr-settings-card" style="border-top-left-radius:0">
    <div class="settings-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <span>Day by day · <?= h($monthLabel) ?> · <?= h($dailyOne ? $scopeEmps[0]['full_name'] . ' (' . $scopeEmps[0]['employee_code'] . ')' : ($companyName !== '' ? $companyName : 'All companies')) ?></span>
      <span class="d-flex gap-2">
        <a class="btn btn-sm btn-success" href="?<?= h($qsBase) ?>&amp;export=detail_xls"><i class="bi bi-download me-1"></i>Download day by day (Excel)</a>
        <a class="btn btn-sm btn-outline-secondary" href="?<?= h($qsBase) ?>&amp;export=detail_csv"><i class="bi bi-download me-1"></i>Download day by day (CSV)</a>
      </span>
    </div>
    <?php if ($dailyOne): ?>
      <div class="d-flex flex-wrap gap-2 px-3 pt-3">
        <?php foreach ($dailyTotals['by'] as $k => $n): ?>
          <span class="att-day-chip"><strong><?= (int)$n ?></strong> <?= h($k) ?></span>
        <?php endforeach; ?>
        <?php foreach ($dailyTotals['flags'] as $k => $n): ?>
          <span class="att-day-chip att-flag"><strong><?= (int)$n ?></strong> <?= h($k) ?></span>
        <?php endforeach; ?>
        <span class="att-day-chip ms-md-auto"><strong><?= number_format($dailyTotals['hours'], 2) ?></strong> hours</span>
      </div>
    <?php endif; ?>
    <div class="card-body p-0 pt-2">
    <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table align-middle mb-0 att-day">
        <thead class="table-light">
          <tr>
            <th style="width:150px">Date</th>
            <th>Employee</th>
            <th style="width:170px">Scheduled</th>
            <th style="width:90px">In</th>
            <th style="width:90px">Out</th>
            <th class="text-end" style="width:90px">Hours</th>
            <th style="width:220px">Status</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$dailyRows): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No employees for these filters.</td></tr>
        <?php else:
          $lastEmp = null;
          $todayYmd = date('Y-m-d');
          foreach ($dailyRows as $r):
            // A thicker line where the next employee starts.
            $newEmp = $lastEmp !== null && $lastEmp !== $r['employee_id'];
            $lastEmp = $r['employee_id'];
            [$bg, $fg] = hr_attendance_daily_colors($r['kind']);
            $cls = in_array($r['kind'], ['weekly_off', 'holiday'], true) ? 'att-rest' : ($r['kind'] === 'future' ? 'att-future' : ''); ?>
          <tr class="<?= trim($cls . ($newEmp ? ' att-newemp' : '')) ?>">
            <td class="text-nowrap"><span class="att-dow"><?= h($r['dow']) ?></span><?= h(date('j M', strtotime($r['date']))) ?><?= $r['date'] === $todayYmd ? ' <span class="badge text-bg-warning ms-1">Today</span>' : '' ?></td>
            <td class="fw-semibold text-nowrap"><?= h($r['full_name']) ?> <span class="fw-normal text-muted small">(<?= h($r['employee_code']) ?>)</span></td>
            <td class="att-sched"><?= h($r['scheduled']) ?></td>
            <td><?= h($r['check_in']) ?></td>
            <td><?= h($r['check_out']) ?></td>
            <td class="text-end fw-bold"><?= h($r['hours']) ?></td>
            <td>
              <?php if ($r['status'] !== ''): ?><span class="att-pill" style="background:<?= h($bg) ?>;color:<?= h($fg) ?>"><?= h($r['status']) ?></span><?php endif; ?>
              <?php if ($r['weekly_off'] === 'Yes' && $r['kind'] !== 'weekly_off'): ?><span class="small text-muted ms-1">· weekly off</span><?php endif; ?>
              <?php foreach ($r['flags'] as $fl): ?><span class="att-flag-pill"><?= h($fl) ?></span><?php endforeach; ?>
            </td>
            <td class="small text-muted"><?= h($r['notes']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    </div>
  </div>
  <?php else: ?>
  <div class="hr-settings-card" style="border-top-left-radius:0">
    <div class="settings-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <span>Monthly totals · <?= h(date('F Y', mktime(0, 0, 0, $month, 1, $year))) ?></span>
      <span class="d-flex gap-2">
        <a class="btn btn-sm btn-success" href="?<?= h($qsBase) ?>&amp;export=xls"><i class="bi bi-download me-1"></i>Download monthly totals (Excel)</a>
        <a class="btn btn-sm btn-outline-secondary" href="?<?= h($qsBase) ?>&amp;export=csv"><i class="bi bi-download me-1"></i>Download monthly totals (CSV)</a>
      </span>
    </div>
    <div class="card-body p-0">
    <div class="hr-table-shell hr-table-scroll border-0 shadow-none rounded-0">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Employee Code</th>
            <th>Employee</th>
            <th>Company</th>
            <th>Department</th>
            <th>Location</th>
            <th class="text-end">Present</th>
            <th class="text-end">Absent</th>
            <th class="text-end">Half</th>
            <th class="text-end">On Leave</th>
            <th class="text-end">Excused Absent</th>
            <th class="text-end">Scheduled Hours</th>
            <th class="text-end">Total Hours</th>
            <th class="text-end">Late In</th>
            <th class="text-end">Early Out</th>
            <th class="text-end">Short Hours</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="15" class="text-center text-muted py-4">No data.</td></tr>
        <?php else: foreach ($rows as $r): $c = $cmp[(int)$r['id']] ?? ['scheduled' => 0, 'late' => 0, 'early' => 0, 'short' => 0]; ?>
          <tr>
            <td class="fw-semibold"><?= h($r['employee_code']) ?></td>
            <td><?= h($r['full_name']) ?></td>
            <td><?= h($r['company_name']) ?></td>
            <td><?= h($r['department']) ?></td>
            <td><?= h($r['location']) ?></td>
            <td class="text-end"><?= (int)$r['present_days'] ?></td>
            <td class="text-end"><?= (int)$r['absent_days'] ?></td>
            <td class="text-end"><?= (int)$r['half_days'] ?></td>
            <td class="text-end"><?= (int)$r['leave_days'] ?></td>
            <td class="text-end"><?= (int)$r['excused_days'] ?></td>
            <td class="text-end text-muted"><?= number_format($c['scheduled'], 2) ?></td>
            <td class="text-end fw-bold"><?= number_format((float)$r['total_hours'], 2) ?></td>
            <td class="text-end <?= $c['late'] ? 'att-cnt-warn' : '' ?>"><?= (int)$c['late'] ?></td>
            <td class="text-end <?= $c['early'] ? 'att-cnt-warn' : '' ?>"><?= (int)$c['early'] ?></td>
            <td class="text-end <?= $c['short'] ? 'att-cnt-warn' : '' ?>"><?= (int)$c['short'] ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    </div>
  </div>
  <?php endif; ?>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
