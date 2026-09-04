<?php
/**
 * Attendance monthly summary + exports — shared HR (all companies).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
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
    $sql .= " HAVING (present_days + absent_days + half_days + leave_days) > 0";
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

// Detail export: one row per attendance day
if ($export === 'detail_csv' || $export === 'detail_xls') {
    $detailSql = "
      SELECT a.work_date, e.employee_code, e.full_name, COALESCE(c.name,'') AS company_name,
             COALESCE(d.name,'') AS department, COALESCE(l.name,'') AS location,
             a.check_in, a.check_out, a.hours, a.status, a.notes
      FROM attendance a
      JOIN employees e ON e.id = a.employee_id
      LEFT JOIN companies c ON c.id = e.company_id
      LEFT JOIN departments d ON d.id = e.department_id
      LEFT JOIN locations l ON l.id = e.location_id
      WHERE MONTH(a.work_date) = :m AND YEAR(a.work_date) = :y
    ";
    $detailParams = [':m' => $month, ':y' => $year];
    if ($currentOnly) {
        $ph = [];
        foreach (hr_employee_current_statuses() as $i => $st) {
            $key = ':curst' . $i;
            $ph[] = $key;
            $detailParams[$key] = $st;
        }
        $detailSql .= " AND e.status IN (" . implode(',', $ph) . ")";
    }
    if ($selectedCompanyId > 0) {
        $detailSql .= " AND e.company_id = :company_id";
        $detailParams[':company_id'] = $selectedCompanyId;
    }
    if (!is_null($dept)) {
        $detailSql .= " AND e.department_id = :dept";
        $detailParams[':dept'] = $dept;
    }
    if (!is_null($loc)) {
        $detailSql .= " AND e.location_id = :loc";
        $detailParams[':loc'] = $loc;
    }
    if (!is_null($supervisorId)) {
        $detailSql .= " AND e.manager_id = :supervisor_id";
        $detailParams[':supervisor_id'] = $supervisorId;
    }
    if (!is_null($employeeId)) {
        $detailSql .= " AND e.id = :employee_id";
        $detailParams[':employee_id'] = $employeeId;
    }
    $detailSql .= " ORDER BY e.full_name, a.work_date";

    $stmt = $conn->prepare($detailSql);
    $stmt->execute($detailParams);
    $detailRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $filename = sprintf("attendance_detail_%04d-%02d.%s", $year, $month, $export === 'detail_csv' ? 'csv' : 'xls');

    if ($export === 'detail_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date', 'Employee Code', 'Employee', 'Company', 'Department', 'Location', 'In', 'Out', 'Hours', 'Status', 'Notes']);
        foreach ($detailRows as $r) {
            fputcsv($out, [
                $r['work_date'], $r['employee_code'], $r['full_name'], $r['company_name'],
                $r['department'], $r['location'], $r['check_in'], $r['check_out'],
                number_format((float)$r['hours'], 2), $r['status'], $r['notes'],
            ]);
        }
        fclose($out);
        exit;
    }

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "<table border='1'><tr>
            <th>Date</th><th>Employee Code</th><th>Employee</th><th>Company</th><th>Department</th>
            <th>Location</th><th>In</th><th>Out</th><th>Hours</th><th>Status</th><th>Notes</th>
          </tr>";
    foreach ($detailRows as $r) {
        echo "<tr>";
        foreach (['work_date', 'employee_code', 'full_name', 'company_name', 'department', 'location', 'check_in', 'check_out'] as $k) {
            echo "<td>" . h($r[$k] ?? '') . "</td>";
        }
        echo "<td>" . number_format((float)$r['hours'], 2) . "</td>";
        echo "<td>" . h($r['status']) . "</td>";
        echo "<td>" . h($r['notes'] ?? '') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    exit;
}

if ($export === 'csv' || $export === 'xls') {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $filename = sprintf("attendance_summary_%04d-%02d.%s", $year, $month, $export);

    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Employee Code', 'Employee Name', 'Company', 'Department', 'Location', 'Present', 'Absent', 'Half', 'On Leave', 'Total Hours']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['employee_code'], $r['full_name'], $r['company_name'], $r['department'], $r['location'],
                $r['present_days'], $r['absent_days'], $r['half_days'], $r['leave_days'],
                number_format((float)$r['total_hours'], 2),
            ]);
        }
        fclose($out);
        exit;
    }

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "<table border='1'><tr>
            <th>Employee Code</th><th>Employee Name</th><th>Company</th><th>Department</th><th>Location</th>
            <th>Present</th><th>Absent</th><th>Half</th><th>On Leave</th><th>Total Hours</th>
          </tr>";
    foreach ($rows as $r) {
        echo "<tr>";
        echo "<td>" . h($r['employee_code']) . "</td>";
        echo "<td>" . h($r['full_name']) . "</td>";
        echo "<td>" . h($r['company_name']) . "</td>";
        echo "<td>" . h($r['department']) . "</td>";
        echo "<td>" . h($r['location']) . "</td>";
        echo "<td>" . (int)$r['present_days'] . "</td>";
        echo "<td>" . (int)$r['absent_days'] . "</td>";
        echo "<td>" . (int)$r['half_days'] . "</td>";
        echo "<td>" . (int)$r['leave_days'] . "</td>";
        echo "<td>" . number_format((float)$r['total_hours'], 2) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    exit;
}

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    '<a href="attendance.php" class="btn btn-outline-secondary">Back to List</a>'
    . '<a class="btn btn-outline-primary" href="attendance_bulk.php">Bulk Apply</a>'
);
?>

  <div class="hr-filter-bar mb-3">
  <form method="get">
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
        <div class="col-md-4">
          <label class="form-label">Employee (for per-person detail export)</label>
          <select name="employee_id" class="form-select">
            <option value="">— All / whole report —</option>
            <?php foreach ($employees as $e): ?>
              <option value="<?= (int)$e['id'] ?>" <?= $employeeId === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" id="only_with_records" name="only_with_records" value="1" <?= $only_with_records ? 'checked' : '' ?>>
            <label for="only_with_records" class="form-check-label">Only with records</label>
          </div>
        </div>
        <div class="col-md-2">
          <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" id="include_left" name="include_left" value="1" <?= !$currentOnly ? 'checked' : '' ?>>
            <label for="include_left" class="form-check-label">Include left staff</label>
          </div>
        </div>
        <div class="col-12 d-flex flex-wrap gap-2 mt-2">
          <button class="btn btn-primary">Apply</button>
          <a class="btn btn-outline-secondary" href="?<?= h($qsBase) ?>&export=csv">Summary CSV</a>
          <a class="btn btn-outline-secondary" href="?<?= h($qsBase) ?>&export=xls">Summary Excel</a>
          <a class="btn btn-outline-primary" href="?<?= h($qsBase) ?>&export=detail_csv">Detail CSV (daily)</a>
          <a class="btn btn-outline-primary" href="?<?= h($qsBase) ?>&export=detail_xls">Detail Excel (daily)</a>
        </div>
      </div>
  </form>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header">Monthly summary</div>
    <div class="card-body p-0">
    <div class="hr-table-shell border-0 shadow-none rounded-0">
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
            <th class="text-end">Total Hours</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">No data.</td></tr>
        <?php else: foreach ($rows as $r): ?>
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
            <td class="text-end"><?= number_format((float)$r['total_hours'], 2) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    </div>
  </div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
