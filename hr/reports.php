<?php
// hr/reports.php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';

require_login();
require_role(['Owner','Admin','HR'], $conn);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function hr_report_fetch(PDO $conn, string $sql, array $params = []): array
{
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function hr_report_csv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        $line = [];
        foreach ($headers as $key => $label) {
            $line[] = $row[$key] ?? '';
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

function hr_report_excel(string $filename, array $headers, array $rows): void
{
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    echo "<table border=\"1\"><thead><tr>";
    foreach ($headers as $label) {
        echo '<th>' . h($label) . '</th>';
    }
    echo "</tr></thead><tbody>";
    foreach ($rows as $row) {
        echo "<tr>";
        foreach ($headers as $key => $label) {
            echo '<td>' . h($row[$key] ?? '') . '</td>';
        }
        echo "</tr>";
    }
    echo "</tbody></table>";
    exit;
}

$companies = hr_active_companies($conn);
$selectedCompanyId = hr_selected_company_id($conn, $companies);
$scopeLabel = hr_company_scope_label($companies, $selectedCompanyId);

$today = date('Y-m-d');
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$expiryTo = $_GET['expiry_to'] ?? date('Y-m-d', strtotime('+30 days'));

$companyWhere = $selectedCompanyId > 0 ? ' AND e.company_id = ?' : '';
$companyParams = $selectedCompanyId > 0 ? [$selectedCompanyId] : [];
$queryBase = [
    'company_id' => $selectedCompanyId,
    'from' => $from,
    'to' => $to,
    'expiry_to' => $expiryTo,
];

$headcountRows = hr_report_fetch($conn, "
    SELECT COALESCE(c.name, 'No company') AS company_name,
           e.status,
           COUNT(*) AS employee_count
    FROM employees e
    LEFT JOIN companies c ON c.id = e.company_id
    WHERE 1=1 {$companyWhere}
    GROUP BY company_name, e.status
    ORDER BY company_name, e.status
", $companyParams);

$leaveRows = hr_report_fetch($conn, "
    SELECT COALESCE(c.name, 'No company') AS company_name,
           COUNT(*) AS pending_count,
           COALESCE(SUM(lr.days), 0) AS pending_days
    FROM leave_requests lr
    JOIN employees e ON e.id = lr.employee_id
    LEFT JOIN companies c ON c.id = e.company_id
    WHERE lr.status = 'pending' {$companyWhere}
    GROUP BY company_name
    ORDER BY pending_count DESC, company_name
", $companyParams);

$documentRows = hr_report_fetch($conn, "
    SELECT COALESCE(c.name, 'No company') AS company_name,
           COUNT(*) AS expiring_count,
           MIN(d.expires_at) AS next_expiry
    FROM employee_documents d
    JOIN employees e ON e.id = d.employee_id
    LEFT JOIN companies c ON c.id = e.company_id
    WHERE d.expires_at IS NOT NULL
      AND d.expires_at != '0000-00-00'
      AND d.expires_at BETWEEN ? AND ?
      {$companyWhere}
    GROUP BY company_name
    ORDER BY next_expiry ASC, company_name
", array_merge([$today, $expiryTo], $companyParams));

$currentStatuses = hr_employee_current_statuses();
$currentStatusSql = hr_employee_status_in_sql($currentStatuses);
$visaRows = hr_report_fetch($conn, "
    SELECT COALESCE(c.name, 'No company') AS company_name,
           e.employee_code,
           COALESCE(NULLIF(e.full_name, ''), NULLIF(TRIM(CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, ''))), ''), CONCAT('Employee #', e.id)) AS employee_name,
           e.status,
           v.visa_expiry_date,
           DATEDIFF(v.visa_expiry_date, CURDATE()) AS days_remaining
    FROM employees e
    LEFT JOIN companies c ON c.id = e.company_id
    JOIN (
        SELECT employee_id,
               COALESCE(
                   MIN(CASE WHEN visa_expiry_date >= CURDATE() THEN visa_expiry_date END),
                   MAX(visa_expiry_date)
               ) AS visa_expiry_date
        FROM (
            SELECT id AS employee_id, visa_ex_d AS visa_expiry_date
            FROM employees
            WHERE visa_ex_d IS NOT NULL
              AND visa_ex_d != '0000-00-00'
            UNION ALL
            SELECT d.employee_id, d.expires_at AS visa_expiry_date
            FROM employee_documents d
            LEFT JOIN document_types dt ON dt.id = d.doc_type_id
            WHERE d.expires_at IS NOT NULL
              AND d.expires_at != '0000-00-00'
              AND LOWER(COALESCE(NULLIF(dt.name, ''), d.doc_type, '')) LIKE '%visa%'
        ) visa_sources
        GROUP BY employee_id
    ) v ON v.employee_id = e.id
    WHERE e.status IN ({$currentStatusSql})
      {$companyWhere}
    ORDER BY v.visa_expiry_date ASC, company_name ASC, employee_name ASC
", array_merge($currentStatuses, $companyParams));

$payrollRows = hr_report_fetch($conn, "
    SELECT COALESCE(c.name, 'No company') AS company_name,
           COUNT(DISTINCT pr.id) AS run_count,
           COUNT(DISTINCT pi.employee_id) AS employee_count,
           COALESCE(SUM(pi.net_pay), 0) AS total_net_pay
    FROM payroll_runs pr
    JOIN payroll_items pi ON pi.payroll_run_id = pr.id
    JOIN employees e ON e.id = pi.employee_id
    LEFT JOIN companies c ON c.id = pr.company_id
    WHERE pr.period_from <= ?
      AND pr.period_to >= ?
      AND pr.status IN ('posted', 'finalized', 'paid')
      {$companyWhere}
    GROUP BY company_name
    ORDER BY total_net_pay DESC, company_name
", array_merge([$to, $from], $companyParams));

$attendanceRows = hr_report_fetch($conn, "
    SELECT COALESCE(c.name, 'No company') AS company_name,
           a.status,
           COUNT(*) AS exception_count
    FROM attendance a
    JOIN employees e ON e.id = a.employee_id
    LEFT JOIN companies c ON c.id = e.company_id
    WHERE a.work_date BETWEEN ? AND ?
      AND a.status IN ('absent', 'half', 'on_leave')
      {$companyWhere}
    GROUP BY company_name, a.status
    ORDER BY company_name, a.status
", array_merge([$from, $to], $companyParams));

$export = $_GET['export'] ?? '';
if ($export === 'headcount') {
    hr_report_csv('hr_headcount_by_company.csv', [
        'company_name' => 'Company',
        'status' => 'Status',
        'employee_count' => 'Employees',
    ], $headcountRows);
}
if ($export === 'leave') {
    hr_report_csv('hr_pending_leave_by_company.csv', [
        'company_name' => 'Company',
        'pending_count' => 'Pending Requests',
        'pending_days' => 'Pending Days',
    ], $leaveRows);
}
if ($export === 'documents') {
    hr_report_csv('hr_document_expiry_by_company.csv', [
        'company_name' => 'Company',
        'expiring_count' => 'Expiring Documents',
        'next_expiry' => 'Next Expiry',
    ], $documentRows);
}
if ($export === 'visa_expiry') {
    hr_report_excel('hr_visa_expiry_by_company.xls', [
        'company_name' => 'Company',
        'employee_code' => 'Employee Code',
        'employee_name' => 'Employee Name',
        'status' => 'Status',
        'visa_expiry_date' => 'Visa Expiry Date',
        'days_remaining' => 'Days Remaining',
    ], $visaRows);
}
if ($export === 'payroll') {
    hr_report_csv('hr_payroll_totals_by_company.csv', [
        'company_name' => 'Company',
        'run_count' => 'Payroll Runs',
        'employee_count' => 'Employees Paid',
        'total_net_pay' => 'Total Net Pay',
    ], $payrollRows);
}
if ($export === 'attendance') {
    hr_report_csv('hr_attendance_exceptions_by_company.csv', [
        'company_name' => 'Company',
        'status' => 'Status',
        'exception_count' => 'Exceptions',
    ], $attendanceRows);
}

$pageTitle = 'HR Reports';
$hrScopeLabel = $scopeLabel;
$pageStyles = '
  .num{text-align:right; font-variant-numeric:tabular-nums}
';

require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'HR Reports',
    'Headcount, leave, documents, visa, payroll, and attendance snapshots.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Reports'],
    ]
);
?>

<div class="hr-filter-bar mb-3">
<form method="get">
  <div class="row g-2 align-items-end">
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
    <div class="col-md-2">
      <label class="form-label">From</label>
      <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">To</label>
      <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">Expiry To</label>
      <input type="date" name="expiry_to" class="form-control" value="<?= h($expiryTo) ?>">
    </div>
    <div class="col-md-2">
      <button class="btn btn-primary w-100">Apply</button>
    </div>
  </div>
</form>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="hr-settings-card h-100">
      <div class="settings-header d-flex align-items-center">
        <strong>Headcount By Company / Status</strong>
        <a class="btn btn-sm btn-outline-primary ms-auto" href="reports.php?<?= h(http_build_query($queryBase + ['export' => 'headcount'])) ?>">Export CSV</a>
      </div>
      <div class="card-body p-0">
      <div class="hr-table-shell border-0 shadow-none rounded-0">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Company</th><th>Status</th><th class="num">Employees</th></tr></thead>
          <tbody>
          <?php if (!$headcountRows): ?>
            <tr><td colspan="3" class="text-center text-muted py-3">No data.</td></tr>
          <?php else: foreach ($headcountRows as $row): ?>
            <tr><td><?= h($row['company_name']) ?></td><td><?= h($row['status']) ?></td><td class="num"><?= (int)$row['employee_count'] ?></td></tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="hr-settings-card h-100">
      <div class="settings-header d-flex align-items-center">
        <strong>Pending Leave By Company</strong>
        <a class="btn btn-sm btn-outline-primary ms-auto" href="reports.php?<?= h(http_build_query($queryBase + ['export' => 'leave'])) ?>">Export CSV</a>
      </div>
      <div class="card-body p-0">
      <div class="hr-table-shell border-0 shadow-none rounded-0">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Company</th><th class="num">Requests</th><th class="num">Days</th></tr></thead>
          <tbody>
          <?php if (!$leaveRows): ?>
            <tr><td colspan="3" class="text-center text-muted py-3">No pending leave.</td></tr>
          <?php else: foreach ($leaveRows as $row): ?>
            <tr><td><?= h($row['company_name']) ?></td><td class="num"><?= (int)$row['pending_count'] ?></td><td class="num"><?= number_format((float)$row['pending_days'], 2) ?></td></tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="hr-settings-card h-100">
      <div class="settings-header d-flex align-items-center">
        <strong>Documents Expiring By Company</strong>
        <a class="btn btn-sm btn-outline-primary ms-auto" href="reports.php?<?= h(http_build_query($queryBase + ['export' => 'documents'])) ?>">Export CSV</a>
      </div>
      <div class="card-body p-0">
      <div class="hr-table-shell border-0 shadow-none rounded-0">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Company</th><th class="num">Documents</th><th>Next Expiry</th></tr></thead>
          <tbody>
          <?php if (!$documentRows): ?>
            <tr><td colspan="3" class="text-center text-muted py-3">No documents expiring in this window.</td></tr>
          <?php else: foreach ($documentRows as $row): ?>
            <tr><td><?= h($row['company_name']) ?></td><td class="num"><?= (int)$row['expiring_count'] ?></td><td><?= h($row['next_expiry']) ?></td></tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="hr-settings-card h-100">
      <div class="settings-header d-flex align-items-center">
        <strong>Payroll Totals By Company</strong>
        <a class="btn btn-sm btn-outline-primary ms-auto" href="reports.php?<?= h(http_build_query($queryBase + ['export' => 'payroll'])) ?>">Export CSV</a>
      </div>
      <div class="card-body p-0">
      <div class="hr-table-shell border-0 shadow-none rounded-0">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Company</th><th class="num">Runs</th><th class="num">Employees</th><th class="num">Net Pay</th></tr></thead>
          <tbody>
          <?php if (!$payrollRows): ?>
            <tr><td colspan="4" class="text-center text-muted py-3">No finalized payroll in this window.</td></tr>
          <?php else: foreach ($payrollRows as $row): ?>
            <tr><td><?= h($row['company_name']) ?></td><td class="num"><?= (int)$row['run_count'] ?></td><td class="num"><?= (int)$row['employee_count'] ?></td><td class="num"><?= number_format((float)$row['total_net_pay'], 2) ?></td></tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="hr-settings-card h-100">
      <div class="settings-header d-flex align-items-center">
        <div>
          <strong>Visa Expiry By Company</strong>
          <div class="text-muted small">All current employees with a visa expiry date, sorted by the earliest expiry first.</div>
        </div>
        <a class="btn btn-sm btn-outline-success ms-auto" href="reports.php?<?= h(http_build_query($queryBase + ['export' => 'visa_expiry'])) ?>">Export Excel</a>
      </div>
      <div class="card-body p-0">
      <div class="hr-table-shell border-0 shadow-none rounded-0">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Company</th>
              <th>Employee Code</th>
              <th>Employee Name</th>
              <th>Status</th>
              <th>Visa Expiry Date</th>
              <th class="num">Days Remaining</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$visaRows): ?>
            <tr><td colspan="6" class="text-center text-muted py-3">No employee visa expiry dates found.</td></tr>
          <?php else: foreach ($visaRows as $row): ?>
            <tr>
              <td><?= h($row['company_name']) ?></td>
              <td><?= h($row['employee_code']) ?></td>
              <td><?= h($row['employee_name']) ?></td>
              <td><?= h(ucfirst(str_replace('_', ' ', (string)$row['status']))) ?></td>
              <td><?= h($row['visa_expiry_date']) ?></td>
              <td class="num"><?= (int)$row['days_remaining'] ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="hr-settings-card h-100">
      <div class="settings-header d-flex align-items-center">
        <strong>Attendance Exceptions By Company</strong>
        <a class="btn btn-sm btn-outline-primary ms-auto" href="reports.php?<?= h(http_build_query($queryBase + ['export' => 'attendance'])) ?>">Export CSV</a>
      </div>
      <div class="card-body p-0">
      <div class="hr-table-shell border-0 shadow-none rounded-0">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Company</th><th>Status</th><th class="num">Exceptions</th></tr></thead>
          <tbody>
          <?php if (!$attendanceRows): ?>
            <tr><td colspan="3" class="text-center text-muted py-3">No attendance exceptions in this window.</td></tr>
          <?php else: foreach ($attendanceRows as $row): ?>
            <tr><td><?= h($row['company_name']) ?></td><td><?= h($row['status']) ?></td><td class="num"><?= (int)$row['exception_count'] ?></td></tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
