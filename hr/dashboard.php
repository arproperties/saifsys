<?php
// hr/dashboard.php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/branding.php';
require_once __DIR__ . '/../includes/company_helper.php';
require_once __DIR__ . '/../includes/module_access.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_once __DIR__ . '/includes/hr_company_documents.php';

// Get branding settings
$brand = getBrandSettings($conn);

require_once __DIR__ . '/../includes/rbac_department.php';

require_login();
// Check department access (HR is shared - check in either module)
// Backward compatible: fallback to role check
if (!has_department_access(MODULE_CLEANING, DEPT_HR, $conn) && 
    !has_department_access(MODULE_REALESTATE, DEPT_HR, $conn)) {
    require_role(['Owner','Admin','HR'], $conn);
}

// Get current company context
$currentCompanyId = current_company_id($conn) ?: 1;
$userRoles = current_user_roles($conn);
$isHRManager = in_array('HR', $userRoles, true) || in_array('Owner', $userRoles, true) || in_array('Admin', $userRoles, true);

$companies = hr_active_companies($conn);
$selectedCompanyId = $isHRManager ? hr_selected_company_id($conn, $companies) : (int)$currentCompanyId;
$scopeLabel = hr_company_scope_label($companies, $selectedCompanyId);
$companyParams = $selectedCompanyId > 0 ? [$selectedCompanyId] : [];
$companyClause = $selectedCompanyId > 0 ? ' AND e.company_id = ?' : '';
$currentStatuses = hr_employee_current_statuses();
$leftStatuses = hr_employee_left_statuses();
$currentStatusClause = ' AND e.status IN (' . hr_employee_status_in_sql($currentStatuses) . ')';
$leftStatusClause = ' AND e.status IN (' . hr_employee_status_in_sql($leftStatuses) . ')';
$currentStatusParams = $currentStatuses;
$leftStatusParams = $leftStatuses;

$companyQuery = $selectedCompanyId > 0 ? 'company_id=' . (int)$selectedCompanyId : '';
$appendCompanyQuery = $companyQuery !== '' ? '&' . $companyQuery : '';
$employeesHref = 'employees.php?group=current' . $appendCompanyQuery;
$leftEmployeesHref = 'employees.php?group=left' . $appendCompanyQuery;
$leaveHref = 'leave_requests.php?status=pending' . $appendCompanyQuery;
$cashAdvanceHref = 'cash_advances.php?request_status=pending' . $appendCompanyQuery;

// Dates
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');
$attendanceHref = 'attendance.php?date_from=' . urlencode($today) . '&date_to=' . urlencode($today) . $appendCompanyQuery;
$overtimeHref = 'overtime.php?from=' . urlencode($monthStart) . '&to=' . urlencode($monthEnd) . '&status=approved' . $appendCompanyQuery;
$documentsHref = 'documents.php?exp_from=' . urlencode($today) . '&exp_to=' . urlencode(date('Y-m-d', strtotime('+30 days'))) . $appendCompanyQuery;

// Helpers
function scalar(PDO $conn, string $sql, array $p = []) {
  $st = $conn->prepare($sql); $st->execute($p);
  $v = $st->fetchColumn();
  return $v === false ? null : $v;
}
function fetchAllAssoc(PDO $conn, string $sql, array $p = []) {
  $st = $conn->prepare($sql); $st->execute($p);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

// KPIs
$totalEmp = scalar($conn, "SELECT COUNT(*) FROM employees e WHERE 1=1" . $companyClause, $companyParams);
$currentWorkforce = scalar($conn, "SELECT COUNT(*) FROM employees e WHERE 1=1" . $companyClause . $currentStatusClause, array_merge($companyParams, $currentStatusParams));
$leftEmp = scalar($conn, "SELECT COUNT(*) FROM employees e WHERE 1=1" . $companyClause . $leftStatusClause, array_merge($companyParams, $leftStatusParams));

// Overtime (approved) this month
$otHours    = scalar($conn, "SELECT COALESCE(SUM(ot.pay_hours),0)
                             FROM overtime_entries ot
                             JOIN employees e ON e.id = ot.employee_id
                             WHERE ot.status='approved' AND ot.ot_date BETWEEN ? AND ?" . $companyClause . $currentStatusClause, array_merge([$monthStart,$monthEnd], $companyParams, $currentStatusParams));
$otAmount   = scalar($conn, "SELECT COALESCE(SUM(ot.pay_amount),0)
                             FROM overtime_entries ot
                             JOIN employees e ON e.id = ot.employee_id
                             WHERE ot.status='approved' AND ot.ot_date BETWEEN ? AND ?" . $companyClause . $currentStatusClause, array_merge([$monthStart,$monthEnd], $companyParams, $currentStatusParams));

// Documents expiring in next 30 days
$expSoonCnt = scalar($conn, "SELECT COUNT(*)
                             FROM employee_documents d
                             JOIN employees e ON e.id = d.employee_id
                             WHERE d.expires_at IS NOT NULL
                               AND d.expires_at!='0000-00-00'
                               AND d.expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)" . $companyClause . $currentStatusClause, array_merge($companyParams, $currentStatusParams));

// Company documents (trade licence, MOA, Ejari, establishment card, POA) expiring / expired.
// Scoped on hr_company_documents.company_id - there is no employee join here.
// Guarded so the dashboard still renders where the migration has not been applied.
$companyDocParams = $selectedCompanyId > 0 ? [$selectedCompanyId] : [];
$companyDocClause = $selectedCompanyId > 0 ? ' AND hcd.company_id = ?' : '';
$companyDocSoon = 0;
$companyDocExpired = 0;
$companyDocList = [];
try {
    $companyDocSoon = (int)scalar($conn, "SELECT COUNT(*)
                                 FROM hr_company_documents hcd
                                 WHERE hcd.status = 'active'
                                   AND hcd.expiry_date IS NOT NULL
                                   AND hcd.expiry_date != '0000-00-00'
                                   AND hcd.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)" . $companyDocClause, $companyDocParams);
    $companyDocExpired = (int)scalar($conn, "SELECT COUNT(*)
                                 FROM hr_company_documents hcd
                                 WHERE hcd.status = 'active'
                                   AND hcd.expiry_date IS NOT NULL
                                   AND hcd.expiry_date != '0000-00-00'
                                   AND hcd.expiry_date < CURDATE()" . $companyDocClause, $companyDocParams);
    $companyDocList = fetchAllAssoc($conn, "
      SELECT hcd.id, hcd.doc_type, hcd.title, hcd.doc_number, hcd.expiry_date, c.name AS company_name
      FROM hr_company_documents hcd
      LEFT JOIN companies c ON c.id = hcd.company_id
      WHERE hcd.status = 'active'
        AND hcd.expiry_date IS NOT NULL
        AND hcd.expiry_date != '0000-00-00'
        AND hcd.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        " . $companyDocClause . "
      ORDER BY hcd.expiry_date ASC
      LIMIT 8
    ", $companyDocParams);
} catch (Throwable $e) {
    $companyDocSoon = 0;
    $companyDocExpired = 0;
    $companyDocList = [];
}
$companyDocsHref = 'company_documents.php?status=soon' . $appendCompanyQuery;

// Leave: pending + on leave today
$leavePending = scalar($conn, "SELECT COUNT(*)
                               FROM leave_requests lr
                               JOIN employees e ON e.id = lr.employee_id
                               WHERE lr.status='pending'" . $companyClause . $currentStatusClause, array_merge($companyParams, $currentStatusParams));
$onLeaveToday = scalar($conn, "SELECT COUNT(*)
                               FROM leave_requests lr
                               JOIN employees e ON e.id = lr.employee_id
                               WHERE lr.status='approved' AND lr.date_from<=CURDATE() AND lr.date_to>=CURDATE()" . $companyClause . $currentStatusClause, array_merge($companyParams, $currentStatusParams));

// Cash advance requests: pending
$cashAdvPending = scalar($conn, "SELECT COUNT(*)
                                 FROM cash_advances ca
                                 JOIN employees e ON e.id = ca.employee_id
                                 WHERE ca.request_status='pending'" . $companyClause . $currentStatusClause, array_merge($companyParams, $currentStatusParams));

// Attendance today (if you’ve started using `attendance` table)
$attPresent = scalar($conn, "SELECT COUNT(*)
                             FROM attendance a
                             JOIN employees e ON e.id = a.employee_id
                             WHERE a.work_date=? AND a.status='approved'" . $companyClause . $currentStatusClause, array_merge([$today], $companyParams, $currentStatusParams));
$attLeave   = scalar($conn, "SELECT COUNT(*)
                             FROM attendance a
                             JOIN employees e ON e.id = a.employee_id
                             WHERE a.work_date=? AND a.status='on_leave'" . $companyClause . $currentStatusClause, array_merge([$today], $companyParams, $currentStatusParams));
$attHalf    = scalar($conn, "SELECT COUNT(*)
                             FROM attendance a
                             JOIN employees e ON e.id = a.employee_id
                             WHERE a.work_date=? AND a.status='half'" . $companyClause . $currentStatusClause, array_merge([$today], $companyParams, $currentStatusParams));
$attAbsent  = scalar($conn, "SELECT COUNT(*)
                             FROM attendance a
                             JOIN employees e ON e.id = a.employee_id
                             WHERE a.work_date=? AND a.status='absent'" . $companyClause . $currentStatusClause, array_merge([$today], $companyParams, $currentStatusParams));
// Checked in but not yet approved. Present stays 'approved' only, so without
// this tile a day's check-ins would be counted nowhere until HR acts on them.
$attPending = scalar($conn, "SELECT COUNT(*)
                             FROM attendance a
                             JOIN employees e ON e.id = a.employee_id
                             WHERE a.work_date=? AND a.status='pending'" . $companyClause . $currentStatusClause, array_merge([$today], $companyParams, $currentStatusParams));

// Top OT this month
$topOT = fetchAllAssoc($conn, "
  SELECT e.full_name, e.employee_code, c.name AS company_name,
         COALESCE(SUM(ot.pay_hours),0) AS hrs, COALESCE(SUM(ot.pay_amount),0) AS amt
  FROM overtime_entries ot
  JOIN employees e ON e.id=ot.employee_id
  LEFT JOIN companies c ON c.id = e.company_id
  WHERE ot.status='approved' AND ot.ot_date BETWEEN ? AND ?
  " . $companyClause . "
  " . $currentStatusClause . "
  GROUP BY ot.employee_id, e.full_name, e.employee_code, c.name
  ORDER BY hrs DESC, amt DESC
  LIMIT 5
", array_merge([$monthStart,$monthEnd], $companyParams, $currentStatusParams));

// Upcoming expiries (next 30 days)
$expList = fetchAllAssoc($conn, "
  SELECT d.id, d.doc_type, d.expires_at, e.full_name, e.employee_code, c.name AS company_name
  FROM employee_documents d
  JOIN employees e ON e.id=d.employee_id
  LEFT JOIN companies c ON c.id = e.company_id
  WHERE d.expires_at IS NOT NULL 
    AND d.expires_at!='0000-00-00'
    AND d.expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    " . $companyClause . "
    " . $currentStatusClause . "
  ORDER BY d.expires_at ASC
  LIMIT 8
", array_merge($companyParams, $currentStatusParams));

$pageTitle = 'HR Dashboard';
$hrScopeLabel = $scopeLabel;
require_once __DIR__ . '/includes/hr_layout_header.php';

$companyFilterHtml = '';
if ($isHRManager && count($companies) > 1) {
    ob_start();
    ?>
    <form method="get" class="d-flex align-items-end gap-2">
      <div>
        <label class="form-label small mb-1">Company</label>
        <select name="company_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="0">All companies</option>
          <?php foreach ($companies as $company): ?>
            <option value="<?= (int)$company['id'] ?>" <?= $selectedCompanyId === (int)$company['id'] ? 'selected' : '' ?>>
              <?= h($company['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-sm btn-primary">Apply</button>
    </form>
    <?php
    $companyFilterHtml = ob_get_clean();
}

echo hr_ui_page_header(
    'HR Dashboard',
    'Workforce, attendance, leave, and document signals for the selected company scope.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Dashboard'],
    ],
    $companyFilterHtml
);
?>

  <div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
      <?= hr_ui_kpi([
          'label' => 'Workforce status',
          'value' => ((int)$currentWorkforce) . ' / ' . ((int)$totalEmp),
          'sub' => 'Current / all · Left: ' . (int)$leftEmp,
          'icon' => 'users',
          'href' => $employeesHref,
      ]) ?>
    </div>
    <div class="col-6 col-lg-3">
      <?= hr_ui_kpi([
          'label' => 'Overtime',
          'value' => number_format((float)$otHours, 2) . ' h',
          'sub' => 'OT this month · AED ' . number_format((float)$otAmount, 2),
          'icon' => 'clock',
          'href' => $overtimeHref,
      ]) ?>
    </div>
    <div class="col-6 col-lg-3">
      <?= hr_ui_kpi([
          'label' => 'Document expiries',
          'value' => (string)(int)$expSoonCnt,
          'sub' => 'Expiring in 30 days',
          'icon' => 'file-warning',
          'href' => $documentsHref,
      ]) ?>
    </div>
    <div class="col-6 col-lg-3">
      <?= hr_ui_kpi([
          'label' => 'Leave status',
          'value' => (string)(int)$leavePending,
          'sub' => 'Pending · On leave today: ' . (int)$onLeaveToday,
          'icon' => 'calendar-x',
          'href' => $leaveHref,
      ]) ?>
    </div>
  </div>

  <?php if ($companyDocExpired > 0 || $companyDocSoon > 0): ?>
  <div class="hr-settings-card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
      <div class="kpi-icon" style="width:44px;height:44px;border-radius:12px;background:var(--hr-primary-soft);color:var(--hr-<?= $companyDocExpired > 0 ? 'danger' : 'warning' ?>);display:grid;place-items:center">
        <i data-lucide="building-2" style="width:22px;height:22px"></i>
      </div>
      <div class="flex-grow-1">
        <h6 class="mb-1">
          <?php if ($companyDocExpired > 0): ?>
            <strong><?= (int)$companyDocExpired ?></strong> company document<?= $companyDocExpired > 1 ? 's have' : ' has' ?> expired
            <?php if ($companyDocSoon > 0): ?>· <strong><?= (int)$companyDocSoon ?></strong> expiring within 30 days<?php endif; ?>
          <?php else: ?>
            <strong><?= (int)$companyDocSoon ?></strong> company document<?= $companyDocSoon > 1 ? 's expire' : ' expires' ?> within 30 days
          <?php endif; ?>
        </h6>
        <div class="small text-muted mb-0">Trade licence, MOA, Ejari, establishment card or power of attorney</div>
      </div>
      <a class="btn btn-outline-primary" href="<?= h($companyDocsHref) ?>">Review</a>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($cashAdvPending > 0): ?>
  <div class="hr-settings-card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
      <div class="kpi-icon" style="width:44px;height:44px;border-radius:12px;background:var(--hr-primary-soft);color:var(--hr-warning);display:grid;place-items:center">
        <i data-lucide="alert-triangle" style="width:22px;height:22px"></i>
      </div>
      <div class="flex-grow-1">
        <h6 class="mb-1"><strong><?= (int)$cashAdvPending ?></strong> cash advance request<?= $cashAdvPending > 1 ? 's' : '' ?> pending</h6>
        <div class="small text-muted mb-0">Review and approve or reject requests</div>
      </div>
      <a href="<?= h($cashAdvanceHref) ?>" class="btn btn-primary btn-sm">Review</a>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($leavePending > 0): ?>
  <div class="hr-settings-card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
      <div class="kpi-icon" style="width:44px;height:44px;border-radius:12px;background:var(--hr-primary-soft);color:var(--hr-info);display:grid;place-items:center">
        <i data-lucide="calendar-clock" style="width:22px;height:22px"></i>
      </div>
      <div class="flex-grow-1">
        <h6 class="mb-1"><strong><?= (int)$leavePending ?></strong> leave request<?= $leavePending > 1 ? 's' : '' ?> pending</h6>
        <div class="small text-muted mb-0">Review and approve or reject leave</div>
      </div>
      <a href="<?= h($leaveHref) ?>" class="btn btn-outline-secondary btn-sm">Review</a>
    </div>
  </div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="hr-settings-card h-100">
        <div class="settings-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0">Attendance today</h6>
          <a class="small" href="<?= h($attendanceHref) ?>">Manage</a>
        </div>
        <div class="card-body">
          <div class="row text-center g-2">
            <div class="col-6"><div class="fw-semibold fs-5"><?= (int)$attPresent ?></div><div class="small text-muted">Present</div></div>
            <div class="col-6"><div class="fw-semibold fs-5"><?= (int)$attLeave ?></div><div class="small text-muted">On leave</div></div>
            <div class="col-6"><div class="fw-semibold fs-5"><?= (int)$attHalf ?></div><div class="small text-muted">Half</div></div>
            <div class="col-6"><div class="fw-semibold fs-5"><?= (int)$attAbsent ?></div><div class="small text-muted">Absent</div></div>
            <div class="col-6"><div class="fw-semibold fs-5"><?= (int)$attPending ?></div><div class="small text-muted">Pending approval</div></div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="hr-settings-card h-100">
        <div class="settings-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0">Top overtime (<?= date('M Y') ?>)</h6>
          <a class="small" href="<?= h($overtimeHref) ?>">View all</a>
        </div>
        <div class="card-body">
          <?php if (!$topOT): ?>
            <?= hr_ui_empty('No approved overtime yet.', 'clock') ?>
          <?php else: ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($topOT as $row): ?>
                <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                  <div>
                    <div class="fw-semibold"><?= h($row['full_name']) ?></div>
                    <div class="small text-muted">
                      <?= h($row['employee_code']) ?>
                      <?php if (!$selectedCompanyId && !empty($row['company_name'])): ?>
                        · <?= h($row['company_name']) ?>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="text-end">
                    <div class="fw-semibold"><?= number_format((float)$row['hrs'], 2) ?> h</div>
                    <div class="small text-muted">AED <?= number_format((float)$row['amt'], 2) ?></div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="hr-settings-card h-100">
        <div class="settings-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0">Upcoming expiries (30 days)</h6>
          <a class="small" href="<?= h($documentsHref) ?>">Documents</a>
        </div>
        <div class="card-body <?= $expList ? 'p-0' : '' ?>">
          <?php if (!$expList): ?>
            <?= hr_ui_empty('Nothing expiring soon.', 'file-check') ?>
          <?php else: ?>
            <div class="hr-table-shell border-0 shadow-none rounded-0">
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead><tr><th>Employee</th><th>Type</th><th class="text-end">Expires</th></tr></thead>
                  <tbody>
                    <?php foreach ($expList as $d): ?>
                      <tr>
                        <td>
                          <?= h($d['full_name']) ?> <span class="text-muted">(<?= h($d['employee_code']) ?>)</span>
                          <?php if (!$selectedCompanyId && !empty($d['company_name'])): ?>
                            <div class="small text-muted"><?= h($d['company_name']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td><?= h($d['doc_type']) ?></td>
                        <td class="text-end"><?= h($d['expires_at']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if ($companyDocList): ?>
  <div class="row g-3 mt-0">
    <div class="col-12">
      <div class="hr-settings-card">
        <div class="settings-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0">Company documents expiring (30 days)</h6>
          <a class="small" href="<?= h($companyDocsHref) ?>">Company documents</a>
        </div>
        <div class="card-body p-0">
          <div class="hr-table-shell border-0 shadow-none rounded-0">
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Company</th><th>Document</th><th>Number</th><th class="text-end">Expires</th></tr></thead>
                <tbody>
                  <?php foreach ($companyDocList as $cd): ?>
                    <tr>
                      <td><?= h($cd['company_name'] ?: '—') ?></td>
                      <td>
                        <a href="company_documents.php?edit=<?= (int)$cd['id'] ?>"><?= h(hr_company_document_type_label($cd['doc_type'])) ?></a>
                        <?php if (!empty($cd['title']) && $cd['title'] !== hr_company_document_type_label($cd['doc_type'])): ?>
                          <div class="small text-muted"><?= h($cd['title']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td><?= h($cd['doc_number'] ?: '—') ?></td>
                      <td class="text-end text-nowrap">
                        <?= h($cd['expiry_date']) ?>
                        <?= hr_company_document_expiry_badge($cd['expiry_date']) ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
