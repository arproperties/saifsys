<?php
// hr/employees.php


require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_connect.php';
require_once dirname(__DIR__) . '/includes/company_helper.php';
require_once dirname(__DIR__) . '/includes/module_access.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';

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
  $where[] = "(e.full_name LIKE :q OR e.nickname LIKE :q OR e.employee_code LIKE :q OR e.email LIKE :q OR e.phone LIKE :q)";
  $params[':q'] = "%{$q}%";
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

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

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
  c.name AS company_name, c.business_type AS company_type,
  (
    SELECT MIN(expires_at) 
    FROM employee_documents ed 
    WHERE ed.employee_id = e.id AND ed.expires_at IS NOT NULL
  ) AS next_expiry
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

// helpers
function days_to($dateStr) {
  if (!$dateStr) return null;
  $today = new DateTime('today');
  $dt = new DateTime($dateStr);
  return (int)$today->diff($dt)->format('%r%a'); // negative if past
}

$pageTitle = 'Employees';
if ($isHRManager) {
    $hrScopeLabel = hr_company_scope_label($companies, $selectedCompanyId);
}

require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Employees',
    'Search and manage the workforce directory.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Employees'],
    ],
    '<a href="employee_edit.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Employee</a>'
);
?>

  <div class="hr-filter-bar mb-3">
      <form class="row g-2">
        <div class="col-md-4">
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
        <div class="col-md-2">
          <button class="btn btn-primary w-100" type="submit">Filter</button>
        </div>
      </form>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header">Directory</div>
    <div class="card-body p-0">
      <div class="hr-table-shell border-0 shadow-none rounded-0">
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
              <th style="width:160px;">Next Expiry</th>
              <th style="width:120px;">Status</th>
              <th style="width:160px;">Actions</th>
            </tr>
          </thead>
          <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="10" class="text-center text-muted py-4">No employees found.</td></tr>
  <?php else: foreach ($rows as $r):
    $d = days_to($r['next_expiry'] ?? null);
    $expiryBadge = '';
    if ($d !== null) {
      if ($d < 0) {
        $expiryBadge = '<span class="badge text-bg-danger">Expired '.abs($d).'d</span>';
      } elseif ($d <= 30) {
        $expiryBadge = '<span class="badge text-bg-warning text-dark">In '.$d.'d</span>';
      } else {
        $expiryBadge = '<span class="badge text-bg-success">In '.$d.'d</span>';
      }
    }
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
        <?= !empty($r['next_expiry']) ? htmlspecialchars(date('Y-m-d', strtotime($r['next_expiry']))) : '—' ?>
        <?= $expiryBadge ? '<div class="small mt-1">'.$expiryBadge.'</div>' : '' ?>
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
  </div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
