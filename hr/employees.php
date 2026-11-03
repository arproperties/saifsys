<?php
// hr/employees.php


require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_connect.php';
require_once dirname(__DIR__) . '/includes/company_helper.php';
require_once dirname(__DIR__) . '/includes/module_access.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_once __DIR__ . '/includes/hr_export.php';
require_once __DIR__ . '/includes/hr_required_docs.php';

//rbac_bootstrap($conn);
require_role(['Owner','Admin','HR'], $conn);

// Get current company context
$currentCompanyId = current_company_id($conn) ?: 1;
$userRoles = current_user_roles($conn);
$isHRManager = in_array('HR', $userRoles, true) || in_array('Owner', $userRoles, true) || in_array('Admin', $userRoles, true);
$companies = hr_active_companies($conn);
$selectedCompanyId = $isHRManager ? hr_selected_company_id($conn, $companies) : (int)$currentCompanyId;
// filters
$q = trim($_GET['q'] ?? '');
$group = $_GET['group'] ?? 'current';
$status = $_GET['status'] ?? 'all';
// Documents filter: one choice at a time, from the "Show" row above the table.
$docsFilter = (string)($_GET['docs'] ?? '');
if (!isset(HR_REQUIRED_DOC_FILTERS[$docsFilter]) && !isset(HR_REQUIRED_DOC_EXPIRY_FILTERS[$docsFilter])) {
  $docsFilter = '';
}
// One document type picked in the Documents column header: the cell shows only
// that document and the status choices apply to it alone.
$docType = (string)($_GET['doctype'] ?? '');
if (!isset(HR_REQUIRED_DOC_TYPES[$docType])) {
  $docType = '';
}
$view = ($_GET['view'] ?? '') === 'company' ? 'company' : 'list';
// By company view only has the company filter, so the list-only filters must not apply unseen.
if ($view === 'company') {
  $q = '';
  $group = 'current';
  $status = 'all';
  $docsFilter = '';
  $docType = '';
}
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];

// Company filter: HR Manager sees all, others see only their company
if (!$isHRManager) {
    $where[] = "e.company_id = :company_id";
    $params[':company_id'] = $currentCompanyId;
} elseif ($selectedCompanyId > 0) {
    $where[] = "e.company_id = :selected_company_id";
    $params[':selected_company_id'] = $selectedCompanyId;
}

if ($q !== '') {
  // One placeholder per field: with real prepared statements a name cannot repeat.
  $where[] = "(e.full_name LIKE :q1 OR e.nickname LIKE :q2 OR e.employee_code LIKE :q3 OR e.email LIKE :q4 OR e.phone LIKE :q5)";
  foreach ([':q1', ':q2', ':q3', ':q4', ':q5'] as $qp) {
    $params[$qp] = "%{$q}%";
  }
}
if ($status !== 'all') {
  $where[] = "e.status = :status";
  $params[':status'] = $status;
} elseif ($group === 'current') {
  $currentStatuses = hr_employee_current_statuses();
  $where[] = "e.status IN (" . implode(',', array_map(fn($idx) => ':current_status_' . $idx, array_keys($currentStatuses))) . ")";
  foreach ($currentStatuses as $idx => $value) {
    $params[':current_status_' . $idx] = $value;
  }
} elseif ($group === 'left') {
  $leftStatuses = hr_employee_left_statuses();
  $where[] = "e.status IN (" . implode(',', array_map(fn($idx) => ':left_status_' . $idx, array_keys($leftStatuses))) . ")";
  foreach ($leftStatuses as $idx => $value) {
    $params[':left_status_' . $idx] = $value;
  }
}

// The "Show" row counts every choice over the other filters (search, company,
// group, status), so the numbers stay put while you switch between them.
$docsCondFor = static function (string $value) use ($conn, $docType): string {
  $only = $docType !== '' ? $docType : null;
  return isset(HR_REQUIRED_DOC_EXPIRY_FILTERS[$value])
    ? hr_required_docs_expiry_filter_sql($conn, $value, 'e', $only)
    : hr_required_docs_filter_sql($conn, $value, 'e', $only);
};
$docsChoices = array_merge(array_keys(HR_REQUIRED_DOC_FILTERS), array_keys(HR_REQUIRED_DOC_EXPIRY_FILTERS));
$docsCounts = ['' => 0] + array_fill_keys($docsChoices, 0);
if ($view === 'list' && ($_GET['export'] ?? '') !== 'employees') {
  $cols = ['COUNT(*) AS c_all'];
  foreach ($docsChoices as $fv) {
    $cols[] = 'COALESCE(SUM(' . $docsCondFor($fv) . '), 0) AS c_' . $fv;
  }
  $stmt = $conn->prepare("SELECT " . implode(', ', $cols) . " FROM employees e " . ($where ? 'WHERE ' . implode(' AND ', $where) : ''));
  $stmt->execute($params);
  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
  foreach ($docsCounts as $fv => $_) {
    $docsCounts[$fv] = (int)($row['c_' . ($fv === '' ? 'all' : $fv)] ?? 0);
  }
}
if ($docsFilter !== '') {
  $where[] = $docsCondFor($docsFilter);
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Export uses the filters above but ignores pagination, so the file matches
// exactly what the current view is scoped to. Must run before any layout output.
if (($_GET['export'] ?? '') === 'employees') {
  $sql_export = "
  SELECT
    e.employee_code, e.full_name, e.nickname, e.email, e.phone, e.address,
    e.position_title, e.date_joined, e.date_of_birth, e.payment_type,
    e.basic_salary, e.allowance, e.bonus, e.total_salary,
    e.exit_date, e.last_working_day, e.exit_type, e.exit_reason,
    e.status,
    d.name AS department, l.name AS location,
    c.name AS company_name,
    m.full_name AS manager_name,
    (
      SELECT MIN(expires_at)
      FROM employee_documents ed
      WHERE ed.employee_id = e.id AND ed.expires_at IS NOT NULL
    ) AS next_expiry
  FROM employees e
  LEFT JOIN departments d ON d.id = e.department_id
  LEFT JOIN locations l  ON l.id = e.location_id
  LEFT JOIN companies c ON c.id = e.company_id
  LEFT JOIN employees m ON m.id = e.manager_id
  $where_sql
  ORDER BY e.full_name IS NULL, e.full_name ASC, e.id ASC";
  $stmt = $conn->prepare($sql_export);
  $stmt->execute($params);
  $exportRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($exportRows as &$exportRow) {
    $exportRow['status'] = hr_employee_status_label($exportRow['status']);
  }
  unset($exportRow);

  hr_export_csv('hr_employees_' . date('Y-m-d') . '.csv', [
    'employee_code'    => 'Employee Code',
    'full_name'        => 'Full Name',
    'nickname'         => 'Nickname',
    'email'            => 'Email',
    'phone'            => 'Phone',
    'address'          => 'Address',
    'company_name'     => 'Company',
    'department'       => 'Department',
    'location'         => 'Location',
    'position_title'   => 'Position',
    'manager_name'     => 'Manager',
    'status'           => 'Status',
    'date_joined'      => 'Date Joined',
    'date_of_birth'    => 'Date of Birth',
    'exit_date'        => 'Exit Date',
    'last_working_day' => 'Last Working Day',
    'exit_type'        => 'Exit Type',
    'exit_reason'      => 'Exit Reason',
    'payment_type'     => 'Payment Type',
    'basic_salary'     => 'Basic Salary',
    'allowance'        => 'Allowance',
    'bonus'            => 'Bonus',
    'total_salary'     => 'Total Salary',
    'next_expiry'      => 'Next Document Expiry',
  ], $exportRows);
}

$total = 0;
$total_pages = 1;
$rows = [];
$companyCards = [];

if ($view === 'company') {
  // By company view: one card per company, counted over the same filters as the list.
  $stmt = $conn->prepare("
  SELECT e.company_id, c.name AS company_name, c.business_type AS company_type, e.status, COUNT(*) AS n
  FROM employees e
  LEFT JOIN companies c ON c.id = e.company_id
  $where_sql
  GROUP BY e.company_id, c.name, c.business_type, e.status
  ORDER BY c.name IS NULL, c.name ASC");
  $stmt->execute($params);
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
    $cid = (int)$cr['company_id'];
    if (!isset($companyCards[$cid])) {
      $companyCards[$cid] = ['id' => $cid, 'name' => $cr['company_name'], 'type' => $cr['company_type'], 'total' => 0, 'statuses' => []];
    }
    $crStatus = $cr['status'] ?: 'inactive';
    $companyCards[$cid]['total'] += (int)$cr['n'];
    $companyCards[$cid]['statuses'][$crStatus] = ($companyCards[$cid]['statuses'][$crStatus] ?? 0) + (int)$cr['n'];
    $total += (int)$cr['n'];
  }
} else {

// count
$sql_count = "SELECT COUNT(*) FROM employees e $where_sql";
$stmt = $conn->prepare($sql_count);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total / $limit));

// data
$sql = "
SELECT 
  e.id, e.employee_code, e.full_name, e.nickname, e.email, e.phone,
  e.status, e.position_title, e.date_joined, e.exit_date, e.last_working_day,
  d.name AS department, l.name AS location,
  c.name AS company_name, c.business_type AS company_type
FROM employees e
LEFT JOIN departments d ON d.id = e.department_id
LEFT JOIN locations l  ON l.id = e.location_id
LEFT JOIN companies c ON c.id = e.company_id
$where_sql
ORDER BY e.full_name IS NULL, e.full_name ASC, e.id ASC
LIMIT $limit OFFSET $offset";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

}

// Carry the active filters into the export link so the file matches the view.
$exportQuery = $_GET;
unset($exportQuery['page']);
$exportQuery['export'] = 'employees';

// View toggle and company cards keep the active filters.
$viewQuery = $_GET;
unset($viewQuery['page'], $viewQuery['view'], $viewQuery['export']);

$pageTitle = 'Employees';
// Documents column: one line per document, in words. Uploaded documents are
// filled (green valid, amber expiring soon, red expired); a document that is not
// uploaded is a white box with a dashed border (red, or orange with a reason).
$pageHead = '<style>
  .hr-doc-chips { display: flex; flex-direction: column; gap: 2px; color: inherit; white-space: nowrap; }
  .hr-doc-chip { display: inline-block; font-size: .78rem; line-height: 1.35; padding: 1px 8px; border-radius: 6px; border: 1px solid transparent; }
  .hr-doc-chip-valid { background: #e8f5ee; color: #146c43; border-color: #b6dfc8; }
  .hr-doc-chip-expiring { background: #fff3cd; color: #7a5a00; border-color: #ffd970; }
  .hr-doc-chip-expired { background: #dc3545; color: #fff; }
  .hr-doc-chip-missing { background: #fff; color: #b02a37; border: 1px dashed #dc3545; }
  .hr-doc-chip-explained { background: #fff; color: #a14e00; border: 1px dashed #fd7e14; max-width: 300px; white-space: normal; }
  /* Document status choices: square buttons that lift on hover and look pressed when picked. */
  .hr-doc-pill { --c: #6c757d; display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 4px; flex: 0 0 auto;
    font-size: .82rem; font-weight: 500; white-space: nowrap; text-decoration: none; color: var(--c); border: 1px solid var(--c); background: #fff;
    box-shadow: 0 1px 2px rgba(0,0,0,.12); cursor: pointer; transition: transform .08s, box-shadow .08s, background .08s; }
  .hr-doc-pill:hover { background: color-mix(in srgb, var(--c) 10%, #fff); color: var(--c); transform: translateY(-1px);
    box-shadow: 0 3px 6px rgba(0,0,0,.15); }
  .hr-doc-pill:active { transform: translateY(0); box-shadow: inset 0 2px 3px rgba(0,0,0,.2); }
  .hr-doc-pill.is-on, .hr-doc-pill.is-on:hover { background: var(--c); color: #fff; transform: none;
    box-shadow: inset 0 2px 4px rgba(0,0,0,.25); }
  .hr-doc-pill-n { font-weight: 700; font-size: .85rem; min-width: 26px; text-align: center; padding: 0 6px;
    border-radius: 3px; background: var(--c); color: #fff; }
  .hr-doc-pill.is-on .hr-doc-pill-n { background: #fff; color: var(--c); }
  .hr-doc-pill-success { --c: #198754; }
  .hr-doc-pill-orange { --c: #d9670b; }
  .hr-doc-pill-danger { --c: #dc3545; }
  .hr-doc-pill-warning { --c: #b8860b; }
  .hr-doc-pills { overflow-x: auto; }
  .hr-doc-pills-label { font-size: .85rem; font-weight: 600; color: #495057; white-space: nowrap; flex: 0 0 auto; }
  .hr-doc-reset { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px;
    border-radius: 4px; border: 1px solid #adb5bd; color: #495057; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.12); text-decoration: none; }
  .hr-doc-reset:hover { background: #f1f3f5; color: #212529; }
  .hr-emp-count { font-size: .95rem; font-weight: 700; color: #fff; background: #495057; border-radius: 4px; padding: 3px 10px; }
  .hr-doc-type-select { width: auto; font-size: .78rem; padding-top: 1px; padding-bottom: 1px; text-transform: none; font-weight: 500; }
</style>';
// Breadcrumb lives in the top bar; the page header below keeps only the title.
$hrTopbarCrumbs = [
    ['label' => 'HR', 'href' => 'dashboard'],
    ['label' => 'Employees'],
];
$pageStyles = '
.emp-grid { background:#f2f4f7; border-top:1px solid #e4e7ec; padding:1.25rem; }
.emp-ccard { display:flex; flex-direction:column; height:100%; background:#fff; border:1px solid #cfd4dc; border-top:4px solid var(--hr-primary);
             border-radius:14px; box-shadow:0 1px 2px rgba(16,24,40,.06), 0 4px 12px rgba(16,24,40,.06); overflow:hidden;
             text-decoration:none; color:inherit; transition:box-shadow .18s, transform .18s; }
a.emp-ccard:hover { color:inherit; transform:translateY(-3px); box-shadow:0 2px 4px rgba(16,24,40,.08), 0 14px 28px rgba(16,24,40,.12); }
.emp-ccard-head { padding:.85rem 1rem .75rem; background:#f9fafb; border-bottom:1px solid #e4e7ec; }
.emp-ccard-name { font-size:.95rem; font-weight:600; line-height:1.25; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.emp-ccard-body { display:flex; align-items:baseline; gap:.5rem; padding:1rem; flex:1; }
.emp-ccard-num { font-size:2rem; font-weight:700; line-height:1; color:var(--hr-primary); }
.emp-ccard-unit { font-size:.85rem; color:var(--hr-text-muted); }
.emp-ccard-foot { display:flex; justify-content:flex-end; padding:.55rem 1rem; border-top:1px solid #e4e7ec; background:#fcfcfd; font-size:.8rem; }
.emp-go { color:#2563eb; font-weight:500; white-space:nowrap; }
.emp-go .bi { display:inline-block; transition:transform .18s; }
a.emp-ccard:hover .emp-go .bi { transform:translateX(3px); }
';
if ($isHRManager) {
    $hrScopeLabel = hr_company_scope_label($companies, $selectedCompanyId);
}

require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Employees',
    'Search and manage the workforce directory.',
    [],
    '<a href="employees.php?' . htmlspecialchars(http_build_query($exportQuery), ENT_QUOTES, 'UTF-8') . '" class="btn btn-outline-primary"><i class="bi bi-download"></i> Export CSV</a>'
    . ' <a href="employee_edit.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Employee</a>'
);
?>

  <div class="hr-filter-bar mb-3">
      <form class="row g-2">
        <?php if ($view === 'company'): ?>
        <input type="hidden" name="view" value="company">
        <div class="col-md-6">
          <select class="form-select" name="company_id">
            <option value="0">All companies</option>
            <?php foreach ($companies as $company): ?>
              <option value="<?= (int)$company['id'] ?>" <?= $selectedCompanyId === (int)$company['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($company['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php else: ?>
        <?php if ($docsFilter !== ''): ?>
        <input type="hidden" name="docs" value="<?= htmlspecialchars($docsFilter) ?>">
        <?php endif; ?>
        <?php if ($docType !== ''): ?>
        <input type="hidden" name="doctype" value="<?= htmlspecialchars($docType) ?>">
        <?php endif; ?>
        <div class="col-md">
          <input type="text" class="form-control" name="q" placeholder="Search name, code, email, phone…" value="<?= htmlspecialchars($q) ?>">
        </div>
        <?php if ($isHRManager && count($companies) > 1): ?>
        <div class="col-md-3">
          <select class="form-select" name="company_id">
            <option value="0">All companies</option>
            <?php foreach ($companies as $company): ?>
              <option value="<?= (int)$company['id'] ?>" <?= $selectedCompanyId === (int)$company['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($company['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="col-md-2">
          <select class="form-select" name="group">
            <option value="current" <?= $group==='current'?'selected':'' ?>>Current workforce</option>
            <option value="left" <?= $group==='left'?'selected':'' ?>>Left employees</option>
            <option value="all" <?= $group==='all'?'selected':'' ?>>All records</option>
          </select>
        </div>
        <div class="col-md-2">
          <select class="form-select" name="status">
            <option value="all" <?= $status==='all'?'selected':'' ?>>All statuses</option>
            <?php foreach (hr_employee_status_options() as $statusKey => $statusLabel): ?>
              <option value="<?= htmlspecialchars($statusKey) ?>" <?= $status===$statusKey?'selected':'' ?>>
                <?= htmlspecialchars($statusLabel) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="col-md-auto">
          <button class="btn btn-primary px-3" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
        </div>
      </form>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span><?= $view === 'company' ? 'Employees by company' : 'Directory' ?></span>
      <div class="d-flex align-items-center gap-3">
        <?php if ($view === 'company'): ?>
          <span class="small text-muted"><?= number_format(count($companyCards)) ?> compan<?= count($companyCards) === 1 ? 'y' : 'ies' ?> · <?= number_format($total) ?> employee<?= $total === 1 ? '' : 's' ?></span>
        <?php else: ?>
          <?php // With a document filter on: shown out of everyone the other filters leave. ?>
          <span class="hr-emp-count"<?= $docsFilter !== '' ? ' title="Shown / total before the document filter"' : '' ?>>
            <?= number_format($total) ?><?= $docsFilter !== '' ? ' / ' . number_format($docsCounts['']) : '' ?>
            Employee<?= ($docsFilter !== '' ? $docsCounts[''] : $total) === 1 ? '' : 's' ?>
          </span>
        <?php endif; ?>
        <div class="btn-group btn-group-sm" role="group" aria-label="View">
          <a class="btn <?= $view === 'list' ? 'btn-secondary' : 'btn-outline-secondary' ?>"
             href="?<?= htmlspecialchars(http_build_query($viewQuery)) ?>"><i class="bi bi-list-ul me-1"></i>List</a>
          <a class="btn <?= $view === 'company' ? 'btn-secondary' : 'btn-outline-secondary' ?>"
             href="?view=company"><i class="bi bi-grid me-1"></i>By company</a>
        </div>
      </div>
    </div>

    <?php if ($view === 'list'):
      // Document status row: pick one. A link keeps every other filter (and the
      // document picked in the column header) and goes back to page 1.
      $pillLink = static function (string $value): string {
        $qs = $_GET;
        unset($qs['page'], $qs['docs'], $qs['expiry']);
        if ($value !== '') { $qs['docs'] = $value; }
        return '?' . http_build_query($qs);
      };
      $days = HR_REQUIRED_DOC_EXPIRING_DAYS;
      if ($docType === '') {
        $docsPills = [
          'complete'  => ['All Documents Uploaded', 'bi-check-circle-fill', 'success', 'Passport, Visa, Labor Card, Contract and National ID are all uploaded'],
          'missing'   => ['Documents Missing', 'bi-x-circle', 'danger', 'A document is not uploaded, and no reason is given'],
          'explained' => ['Missing With Reason', 'bi-chat-left-text', 'orange', 'Not uploaded, but a reason is given for each one'],
          'expiring'  => ['Expiring In ' . $days . ' Days', 'bi-clock-history', 'warning', 'A document expires in the next ' . $days . ' days'],
          'expired'   => ['Expired Documents', 'bi-exclamation-triangle-fill', 'danger', 'A document has already expired'],
        ];
      } else {
        // The same choices, worded for the one document picked.
        $dl = HR_REQUIRED_DOC_LABELS[$docType];
        $docsPills = [
          'complete'  => [$dl . ' Uploaded', 'bi-check-circle-fill', 'success', ''],
          'missing'   => [$dl . ' Missing', 'bi-x-circle', 'danger', 'Not uploaded, and no reason given'],
          'explained' => [$dl . ' Missing With Reason', 'bi-chat-left-text', 'orange', ''],
          'expiring'  => [$dl . ' Expiring In ' . $days . ' Days', 'bi-clock-history', 'warning', ''],
          'expired'   => [$dl . ' Expired', 'bi-exclamation-triangle-fill', 'danger', ''],
        ];
      }
    ?>
    <div class="hr-doc-pills d-flex align-items-center gap-2 px-3 py-2 border-bottom">
      <span class="hr-doc-pills-label"><i class="bi bi-file-earmark-text me-1"></i><?= $docType !== '' ? htmlspecialchars(HR_REQUIRED_DOC_LABELS[$docType]) . ' Status' : 'Document Status' ?>:</span>
      <?php foreach ($docsPills as $fv => [$fl, $fi, $fc, $ft]): $on = $docsFilter === $fv; ?>
        <a href="<?= htmlspecialchars($pillLink($on ? '' : $fv)) ?>" class="hr-doc-pill hr-doc-pill-<?= $fc ?><?= $on ? ' is-on' : '' ?>"<?= $ft !== '' ? ' title="' . htmlspecialchars($ft) . '"' : '' ?>>
          <i class="bi <?= $fi ?>"></i> <?= htmlspecialchars($fl) ?> <span class="hr-doc-pill-n"><?= number_format($docsCounts[$fv]) ?></span>
        </a>
      <?php endforeach; ?>
      <?php if ($docsFilter !== '' || $docType !== ''):
        $resetQs = $_GET;
        unset($resetQs['page'], $resetQs['docs'], $resetQs['expiry'], $resetQs['doctype']); ?>
        <a href="?<?= htmlspecialchars(http_build_query($resetQs)) ?>" class="hr-doc-reset" title="Clear the document filter">
          <i class="bi bi-arrow-counterclockwise"></i>
        </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($view === 'company'): ?>
    <div class="card-body emp-grid">
      <?php if (!$companyCards): ?>
        <div class="text-center text-muted py-4">No employees found.</div>
      <?php else: ?>
      <div class="row g-3">
        <?php foreach ($companyCards as $card):
          $cardName = $card['name'] ?? 'No company';
          $cardTag = $card['id'] > 0 ? 'a' : 'div'; // employees with no company have no company filter to open
        ?>
          <div class="col-md-6 col-xl-4">
            <<?= $cardTag ?> class="emp-ccard"<?= $card['id'] > 0 ? ' href="?company_id=' . (int)$card['id'] . '"' : '' ?>>
              <div class="emp-ccard-head">
                <div class="emp-ccard-name" title="<?= htmlspecialchars($cardName) ?>"><?= htmlspecialchars($cardName) ?></div>
              </div>
              <div class="emp-ccard-body">
                <span class="emp-ccard-num"><?= number_format($card['total']) ?></span>
                <span class="emp-ccard-unit">employee<?= $card['total'] === 1 ? '' : 's' ?></span>
              </div>
              <?php if ($card['id'] > 0): ?>
              <div class="emp-ccard-foot">
                <span class="emp-go">View employees <i class="bi bi-arrow-right"></i></span>
              </div>
              <?php endif; ?>
            </<?= $cardTag ?>>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="card-body p-0">
      <div class="hr-table-shell hr-table-scroll hr-table-nowrap border-0 shadow-none rounded-0">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:110px;">Code</th>
              <th>Name</th>
              <th style="width:160px;">Department</th>
              <th style="width:160px;">Location</th>
              <th style="width:180px;">Position</th>
              <th style="width:180px;">Company</th>
              <th style="width:170px;">Lifecycle</th>
              <th style="width:280px;">
                <?php
                  // Pick one document: the cells and the Document status row then cover only it.
                  $docTypeLink = static function (string $value): string {
                    $qs = $_GET;
                    unset($qs['page'], $qs['doctype']);
                    if ($value !== '') { $qs['doctype'] = $value; }
                    return '?' . http_build_query($qs);
                  };
                ?>
                <div class="d-flex align-items-center gap-2">
                  <span>Documents</span>
                  <select class="form-select form-select-sm hr-doc-type-select" aria-label="Which document to show"
                          onchange="window.location.href = this.value;">
                    <option value="<?= htmlspecialchars($docTypeLink('')) ?>"<?= $docType === '' ? ' selected' : '' ?>>All Documents</option>
                    <?php foreach (HR_REQUIRED_DOC_LABELS as $tv => $tl): ?>
                      <option value="<?= htmlspecialchars($docTypeLink($tv)) ?>"<?= $docType === $tv ? ' selected' : '' ?>><?= htmlspecialchars($tl) ?> Status</option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </th>
              <th style="width:120px;">Status</th>
              <th style="width:160px;">Actions</th>
            </tr>
          </thead>
          <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="10" class="text-center text-muted py-4">No employees found.</td></tr>
  <?php else:
    $requiredDocs = hr_required_docs_details_map($conn, array_column($rows, 'id'));
    foreach ($rows as $r):
    $displayName = ($r['full_name'] ?? '') !== '' ? $r['full_name'] : ($r['employee_code'] ?? '');
  ?>
    <tr>
      <td class="fw-semibold"><?= htmlspecialchars($r['employee_code'] ?? '') ?></td>

      <td>
        <div class="fw-semibold">
          <a href="employee_view.php?id=<?= (int)($r['id'] ?? 0) ?>" class="text-decoration-none">
            <?= htmlspecialchars($displayName) ?>
          </a>
        </div>
        <?php if (!empty($r['nickname'])): ?>
          <div class="text-muted small"><?= htmlspecialchars($r['nickname']) ?></div>
        <?php endif; ?>
        <?php if (!empty($r['email']) || !empty($r['phone'])): ?>
          <div class="text-muted small">
            <?= htmlspecialchars($r['email'] ?? '') ?>
            <?= (!empty($r['email']) && !empty($r['phone'])) ? ' · ' : '' ?>
            <?= htmlspecialchars($r['phone'] ?? '') ?>
          </div>
        <?php endif; ?>
      </td>

      <td><?= htmlspecialchars($r['department'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['location'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['position_title'] ?? '') ?></td>

      <td>
        <?php if (!empty($r['company_name'])): ?>
          <span class="badge bg-info text-dark">
            <i class="bi bi-building me-1"></i><?= htmlspecialchars($r['company_name']) ?>
          </span>
          <?php if (!empty($r['company_type'])): ?>
            <div class="small text-muted mt-1"><?= htmlspecialchars(ucfirst($r['company_type'])) ?></div>
          <?php endif; ?>
        <?php else: ?>
          <span class="text-muted">—</span>
        <?php endif; ?>
      </td>

      <td>
        <div>Joined: <?= !empty($r['date_joined']) ? htmlspecialchars(date('Y-m-d', strtotime($r['date_joined']))) : '—' ?></div>
        <?php $exitDisplay = $r['last_working_day'] ?: $r['exit_date']; ?>
        <?php if (!empty($exitDisplay)): ?>
          <div class="small text-muted">Left: <?= htmlspecialchars(date('Y-m-d', strtotime($exitDisplay))) ?></div>
        <?php endif; ?>
      </td>

      <td>
        <a href="employee_view.php?id=<?= (int)$r['id'] ?>#tab-overview" class="hr-doc-chips text-decoration-none" title="Open this employee's required documents">
          <?php
            // Not uploaded with a reason: one line per reason, naming every document
            // that shares it, shown where the first of them would be.
            $empDocs = $requiredDocs[(int)$r['id']] ?? [];
            if ($docType !== '') {
              $empDocs = array_intersect_key($empDocs, [$docType => true]);
            }
            $reasonGroups = [];
            foreach ($empDocs as $type => $doc) {
              if ($doc['state'] === 'explained') {
                $reasonGroups[$doc['reason']][] = $type;
              }
            }
          ?>
          <?php foreach ($empDocs as $type => $doc):
            if ($doc['state'] === 'explained') {
              $group = $reasonGroups[$doc['reason']];
              if ($group[0] !== $type) {
                continue;
              }
              $groupLabel = count($group) > 1 && count($group) === count($empDocs)
                ? 'All ' . count($group) . ' Not Uploaded'
                : implode(', ', array_map(static fn($t) => HR_REQUIRED_DOC_LABELS[$t], $group));
          ?>
            <span class="hr-doc-chip hr-doc-chip-explained"
                  title="<?= htmlspecialchars(implode(', ', array_map(static fn($t) => HR_REQUIRED_DOC_LABELS[$t], $group)) . ': not uploaded. Reason: ' . $doc['reason']) ?>">
              <i class="bi bi-chat-left-text"></i> <?= htmlspecialchars($groupLabel) ?>: <b><?= htmlspecialchars($doc['reason']) ?></b>
            </span>
          <?php
              continue;
            }
            // Written out in words so the chip reads the same to anyone; colour only backs it up.
            $exp = $doc['expires_at'];
            $expText = $exp ? date('d M Y', strtotime($exp)) : '';
            switch ($doc['state']) {
              case 'missing':
                [$icon, $chipText] = ['bi-x-circle', 'NOT UPLOADED'];
                $chipTitle = 'No ' . HR_REQUIRED_DOC_LABELS[$type] . ' in Documents, and no reason given.';
                break;
              case 'expired':
                [$icon, $chipText] = ['bi-exclamation-triangle-fill', 'EXPIRED ' . $expText];
                $chipTitle = '';
                break;
              case 'expiring':
                $daysLeft = (int)(new DateTime('today'))->diff(new DateTime($exp))->format('%r%a');
                $daysText = $daysLeft === 0 ? 'Expires Today' : ($daysLeft === 1 ? 'Expires In 1 Day' : 'Expires In ' . $daysLeft . ' Days');
                [$icon, $chipText] = ['bi-clock-history', $expText . ' (' . $daysText . ')'];
                $chipTitle = '';
                break;
              default:
                [$icon, $chipText] = ['bi-check-circle-fill', $exp ? 'Valid Till ' . $expText : 'Uploaded'];
                $chipTitle = '';
            }
            if ($doc['present'] && $doc['doc_number'] !== '') {
              $chipTitle = 'No. ' . $doc['doc_number'];
            }
          ?>
            <span class="hr-doc-chip hr-doc-chip-<?= $doc['state'] ?>"<?= $chipTitle !== '' ? ' title="' . htmlspecialchars($chipTitle) . '"' : '' ?>>
              <i class="bi <?= $icon ?>"></i> <?= HR_REQUIRED_DOC_LABELS[$type] ?>: <b><?= htmlspecialchars($chipText) ?></b>
            </span>
          <?php endforeach; ?>
        </a>
      </td>

      <td>
        <?php
          $st = $r['status'] ?: 'inactive';
          $badge = hr_employee_status_badge($st);
        ?>
        <span class="badge text-bg-<?= $badge ?>"><?= htmlspecialchars(hr_employee_status_label($st)) ?></span>
      </td>

      <td>
        <a class="btn btn-sm btn-outline-primary" href="employee_edit.php?id=<?= (int)$r['id'] ?>">Edit</a>
      </td>
    </tr>
  <?php endforeach; endif; ?>
</tbody>

        </table>
      </div>

      <?php if ($total_pages > 1): ?>
        <nav>
          <ul class="pagination pagination-sm justify-content-center">
            <?php
            $qs = $_GET; 
            $qs['page'] = 1;
            ?>
            <li class="page-item <?= $page==1?'disabled':'' ?>">
              <a class="page-link" href="?<?= http_build_query($qs) ?>">« First</a>
            </li>
            <?php
            $start = max(1, $page-2);
            $end   = min($total_pages, $page+2);
            for ($p=$start; $p<=$end; $p++):
              $qs['page'] = $p; ?>
              <li class="page-item <?= $p==$page?'active':'' ?>">
                <a class="page-link" href="?<?= http_build_query($qs) ?>"><?= $p ?></a>
              </li>
            <?php endfor; 
            $qs['page'] = $total_pages; ?>
            <li class="page-item <?= $page==$total_pages?'disabled':'' ?>">
              <a class="page-link" href="?<?= http_build_query($qs) ?>">Last »</a>
            </li>
          </ul>
        </nav>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
