<?php


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_role(['Owner','Admin','HR'], $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$me_id = $_SESSION['user']['id'] ?? null;

/* ---------- Helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function safe_time($t){ return $t !== '' ? $t : null; }
function calc_hours($in, $out){
    if (!$in || !$out) return null;
    // both "HH:MM" or "HH:MM:SS" are fine
    $a = strtotime("1970-01-01 $in UTC");
    $b = strtotime("1970-01-01 $out UTC");
    if ($a===false || $b===false || $b <= $a) return null;
    $diff = ($b - $a) / 3600.0;
    return round($diff, 2);
}

/* ---------- Quick Actions (POST) ---------- */
$flash_err = $flash_ok = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();

    // Quick add (single day)
    if (isset($_POST['action']) && $_POST['action']==='quick_add') {
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $work_date   = $_POST['work_date'] ?? '';
        $check_in    = safe_time($_POST['check_in'] ?? '');
        $check_out   = safe_time($_POST['check_out'] ?? '');
        $status      = $_POST['status'] ?? 'approved';
        $notes       = trim($_POST['notes'] ?? '');

        if (!$employee_id || !$work_date) {
            $flash_err = 'Employee and Date are required.';
        } else {
            $hours = calc_hours($check_in, $check_out);
            try {
                $empMeta = $conn->prepare("SELECT full_name, employee_code, company_id FROM employees WHERE id=? LIMIT 1");
                $empMeta->execute([$employee_id]);
                $empMeta = $empMeta->fetch(PDO::FETCH_ASSOC) ?: [];
                $empLabel = trim(($empMeta['full_name'] ?? '') . ' (' . ($empMeta['employee_code'] ?? ('#' . $employee_id)) . ')');
                $empCompanyId = isset($empMeta['company_id']) ? (int)$empMeta['company_id'] : null;

                // Upsert-like: try insert; if duplicate (unique employee_id+date), update
                $ins = $conn->prepare("
                    INSERT INTO attendance (employee_id, work_date, check_in, check_out, hours, status, source, notes, created_by, updated_by)
                    VALUES (?,?,?,?,?,?, 'admin', ?, ?, ?)
                ");
                $ins->execute([$employee_id,$work_date,$check_in,$check_out,$hours,$status,$notes,$me_id,$me_id]);
                $attId = (int)$conn->lastInsertId();
                $flash_ok = 'Attendance added.';
                audit_bridge_hr_ops(
                    'attendance_recorded',
                    'attendance',
                    $attId > 0 ? $attId : $employee_id,
                    'Recorded attendance for ' . $empLabel . ' on ' . $work_date . ' as ' . $status,
                    $empCompanyId,
                    [
                        'employee_id' => $employee_id,
                        'work_date' => $work_date,
                        'status' => $status,
                        'check_in' => $check_in,
                        'check_out' => $check_out,
                        'hours' => $hours,
                    ],
                    $empLabel . ' @ ' . $work_date,
                    $me_id ? (int)$me_id : null
                );
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    $upd = $conn->prepare("
                        UPDATE attendance
                           SET check_in=?, check_out=?, hours=?, status=?, notes=?, updated_by=?, updated_at=NOW()
                         WHERE employee_id=? AND work_date=?
                    ");
                    $upd->execute([$check_in,$check_out,$hours,$status,$notes,$me_id,$employee_id,$work_date]);
                    $flash_ok = 'Existing attendance updated.';
                    $idSt = $conn->prepare("SELECT id FROM attendance WHERE employee_id=? AND work_date=? LIMIT 1");
                    $idSt->execute([$employee_id, $work_date]);
                    $attId = (int)$idSt->fetchColumn();
                    audit_bridge_hr_ops(
                        'attendance_updated',
                        'attendance',
                        $attId > 0 ? $attId : $employee_id,
                        'Updated attendance for ' . $empLabel . ' on ' . $work_date . ' to ' . $status,
                        $empCompanyId,
                        [
                            'employee_id' => $employee_id,
                            'work_date' => $work_date,
                            'status' => $status,
                            'check_in' => $check_in,
                            'check_out' => $check_out,
                            'hours' => $hours,
                        ],
                        $empLabel . ' @ ' . $work_date,
                        $me_id ? (int)$me_id : null
                    );
                } else {
                    $flash_err = 'DB error: '.$e->getMessage();
                }
            }
        }
    }

    // Quick status change
    if (isset($_POST['action']) && $_POST['action']==='status' && isset($_POST['id'])) {
        csrf_verify();
        $id  = (int)$_POST['id'];
        $new = $_POST['to'] ?? 'approved';
        if (!in_array($new,['approved','absent','half','on_leave'], true)) {
            $flash_err = 'Invalid status.';
        } else {
            $prev = $conn->prepare("
                SELECT a.*, e.full_name, e.employee_code, e.company_id
                FROM attendance a
                JOIN employees e ON e.id = a.employee_id
                WHERE a.id = ? LIMIT 1
            ");
            $prev->execute([$id]);
            $prevRow = $prev->fetch(PDO::FETCH_ASSOC);
            $u = $conn->prepare("UPDATE attendance SET status=?, updated_by=?, updated_at=NOW() WHERE id=?");
            $u->execute([$new,$me_id,$id]);
            $flash_ok = "Marked as $new.";
            if ($prevRow) {
                $empLabel = trim(($prevRow['full_name'] ?? '') . ' (' . ($prevRow['employee_code'] ?? '') . ')');
                audit_bridge_hr_ops(
                    'attendance_status_changed',
                    'attendance',
                    $id,
                    'Changed attendance status for ' . $empLabel . ' on ' . ($prevRow['work_date'] ?? '')
                        . ' from ' . ($prevRow['status'] ?? '') . ' to ' . $new,
                    isset($prevRow['company_id']) ? (int)$prevRow['company_id'] : null,
                    [
                        'from_status' => $prevRow['status'] ?? null,
                        'to_status' => $new,
                        'work_date' => $prevRow['work_date'] ?? null,
                        'employee_id' => (int)($prevRow['employee_id'] ?? 0),
                    ],
                    $empLabel . ' @ ' . ($prevRow['work_date'] ?? ''),
                    $me_id ? (int)$me_id : null
                );
            }
        }
    }

    // Delete
    if (isset($_POST['action']) && $_POST['action']==='delete' && isset($_POST['id'])) {
        csrf_verify();
        $id = (int)$_POST['id'];
        $prev = $conn->prepare("
            SELECT a.*, e.full_name, e.employee_code, e.company_id
            FROM attendance a
            JOIN employees e ON e.id = a.employee_id
            WHERE a.id = ? LIMIT 1
        ");
        $prev->execute([$id]);
        $prevRow = $prev->fetch(PDO::FETCH_ASSOC);
        $conn->prepare("DELETE FROM attendance WHERE id=?")->execute([$id]);
        $flash_ok = 'Attendance row deleted.';
        if ($prevRow) {
            $empLabel = trim(($prevRow['full_name'] ?? '') . ' (' . ($prevRow['employee_code'] ?? '') . ')');
            audit_bridge_hr_ops(
                'attendance_deleted',
                'attendance',
                $id,
                'Deleted attendance for ' . $empLabel . ' on ' . ($prevRow['work_date'] ?? '')
                    . ' (was ' . ($prevRow['status'] ?? '') . ')',
                isset($prevRow['company_id']) ? (int)$prevRow['company_id'] : null,
                [
                    'work_date' => $prevRow['work_date'] ?? null,
                    'status' => $prevRow['status'] ?? null,
                    'employee_id' => (int)($prevRow['employee_id'] ?? 0),
                ],
                $empLabel . ' @ ' . ($prevRow['work_date'] ?? ''),
                $me_id ? (int)$me_id : null
            );
        }
    }
}

/* ---------- Filters ---------- */
$df = $_GET['date_from'] ?? date('Y-m-01');
$dt = $_GET['date_to']   ?? date('Y-m-d');
$emp = (int)($_GET['employee_id'] ?? 0);
$st  = $_GET['status'] ?? '';
$companies = hr_active_companies($conn);
$selectedCompanyId = hr_selected_company_id($conn, $companies);

$where = [];
$prms  = [];

if ($df) { $where[] = "a.work_date >= ?"; $prms[] = $df; }
if ($dt) { $where[] = "a.work_date <= ?"; $prms[] = $dt; }
if ($emp){ $where[] = "a.employee_id = ?"; $prms[] = $emp; }
hr_add_company_where($where, $prms, $selectedCompanyId, 'e.company_id');
if ($st !== '' && in_array($st,['approved','absent','half','on_leave'], true)) {
    $where[] = "a.status = ?"; $prms[] = $st;
}
// Who recorded it: HR by hand, the staff member themselves, or an import.
$src = $_GET['source'] ?? '';
if ($src !== '' && in_array($src, ['admin','self','import'], true)) {
    $where[] = "a.source = ?"; $prms[] = $src;
}

$sqlWhere = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* ---------- Lookups ---------- */
$employeeSql = "
    SELECT e.id, CONCAT(e.full_name,' (',e.employee_code,')') label, c.name AS company_name
    FROM employees e
    LEFT JOIN companies c ON c.id = e.company_id
";
$employeeWhere = ["e.status IN (" . hr_employee_status_in_sql(hr_employee_current_statuses()) . ")"];
$employeeParams = hr_employee_current_statuses();
if ($selectedCompanyId > 0) {
    $employeeWhere[] = "e.company_id = ?";
    $employeeParams[] = $selectedCompanyId;
}
$employeeSql .= " WHERE " . implode(' AND ', $employeeWhere);
$employeeSql .= " ORDER BY e.full_name";
$employeeStmt = $conn->prepare($employeeSql);
$employeeStmt->execute($employeeParams);
$employees = $employeeStmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Query rows ---------- */
$stmt = $conn->prepare("
SELECT a.*, e.full_name, e.employee_code, c.name AS company_name
  FROM attendance a
  JOIN employees e ON e.id=a.employee_id
  LEFT JOIN companies c ON c.id = e.company_id
  $sqlWhere
 ORDER BY a.work_date DESC, e.full_name ASC
 LIMIT 500
");
$stmt->execute($prms);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Page settings for shared layout
$pageTitle = 'Attendance';
?>
<?php require_once __DIR__ . '/includes/hr_layout_header.php'; ?>

<?php
echo hr_ui_page_header(
    'Attendance',
    'Daily attendance records, quick entry, and approvals.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Attendance'],
    ],
    '<a class="btn btn-outline-secondary" href="../operation.php">Back</a>'
    . '<a class="btn btn-outline-primary" href="attendance_bulk.php">Bulk Apply</a>'
    . '<a class="btn btn-outline-primary" href="attendance_summary.php">Monthly Summary</a>'
);
?>

  <?php if ($flash_err): ?><div class="alert alert-danger"><?= h($flash_err) ?></div><?php endif; ?>
  <?php if ($flash_ok):  ?><div class="alert alert-success"><?= h($flash_ok)  ?></div><?php endif; ?>

  <div class="hr-filter-bar mb-3">
  <form method="get">
        <?php csrf_field(); ?>
    <div class="row g-2">
      <div class="col-md-3">
        <label class="form-label">From</label>
        <input type="date" name="date_from" class="form-control" value="<?= h($df) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">To</label>
        <input type="date" name="date_to" class="form-control" value="<?= h($dt) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Employee</label>
        <select name="employee_id" class="form-select">
          <option value="0">— All —</option>
          <?php foreach ($employees as $e): ?>
            <option value="<?= (int)$e['id'] ?>" <?= $emp==(int)$e['id']?'selected':''; ?>>
              <?= h($e['label']) ?><?= !empty($e['company_name']) ? ' - ' . h($e['company_name']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
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
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <?php
            $opts = [''=>'— Any —','approved'=>'Approved','absent'=>'Absent','half'=>'Half Day','on_leave'=>'On Leave'];
            foreach ($opts as $k=>$v) {
              $sel = ($st===$k)?'selected':'';
              echo "<option value=\"".h($k)."\" $sel>".h($v)."</option>";
            }
          ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Recorded by</label>
        <select name="source" class="form-select">
          <?php
            $srcOpts = ['' => '— Any —', 'admin' => 'HR', 'self' => 'Staff (self)', 'import' => 'Import'];
            foreach ($srcOpts as $k => $v) {
              $sel = ($src === $k) ? 'selected' : '';
              echo "<option value=\"".h($k)."\" $sel>".h($v)."</option>";
            }
          ?>
        </select>
      </div>
      <div class="col-md-1 d-flex align-items-end">
        <button class="btn btn-primary w-100">Apply</button>
      </div>
    </div>
  </form>
  </div>

  <div class="hr-settings-card mb-3">
    <div class="settings-header">Quick Add (single day)</div>
    <form class="card-body row g-2" method="post">
        <?php csrf_field(); ?>
      <input type="hidden" name="action" value="quick_add">
      <div class="col-lg-4">
        <label class="form-label">Employee *</label>
        <select name="employee_id" class="form-select" required>
          <option value="">-- choose --</option>
          <?php foreach ($employees as $e): ?>
            <option value="<?= (int)$e['id'] ?>"><?= h($e['label']) ?><?= !empty($e['company_name']) ? ' - ' . h($e['company_name']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-lg-2">
        <label class="form-label">Date *</label>
        <input type="date" name="work_date" class="form-control" required value="<?= h($dt) ?>">
      </div>
      <div class="col-lg-2">
        <label class="form-label">In</label>
        <input type="time" name="check_in" class="form-control">
      </div>
      <div class="col-lg-2">
        <label class="form-label">Out</label>
        <input type="time" name="check_out" class="form-control">
      </div>
      <div class="col-lg-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="approved">Approved</option>
          <option value="absent">Absent</option>
          <option value="half">Half Day</option>
          <option value="on_leave">On Leave</option>
        </select>
      </div>
      <div class="col-12">
        <input type="text" name="notes" class="form-control" placeholder="Notes (optional)">
      </div>
      <div class="col-12 d-flex justify-content-end">
        <button class="btn btn-success">Add / Update</button>
      </div>
    </form>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header d-flex justify-content-between align-items-center">
      <span>Results (max 500)</span>
    </div>
    <div class="card-body p-0">
    <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Date</th>
            <th>Employee</th>
            <th>Company</th>
            <th>In</th>
            <th>Out</th>
            <th>Hours</th>
            <th>Status</th>
            <th>Source</th>
            <th>Notes</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="11" class="text-center text-muted py-4">No rows.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= h($r['work_date']) ?></td>
              <td><?= h($r['full_name']).' ('.h($r['employee_code']).')' ?></td>
              <td><?= h($r['company_name'] ?: '—') ?></td>
              <td><?= h($r['check_in'] ?: '—') ?></td>
              <td><?= h($r['check_out']?: '—') ?></td>
              <td><?= $r['hours']!==null ? number_format((float)$r['hours'],2) : '—' ?></td>
              <td>
                <?php
                  $map = ['approved'=>'success','absent'=>'danger','half'=>'warning','on_leave'=>'info'];
                  $cls = $map[$r['status']] ?? 'secondary';
                ?>
                <span class="badge text-bg-<?= $cls ?>"><?= h($r['status']) ?></span>
                <?php if ($r['status'] === 'absent' && strpos((string)($r['notes'] ?? ''), 'Worker Availability absent:') === 0): ?>
                  <span class="badge text-bg-info ms-1">From Operation</span>
                <?php endif; ?>
              </td>
              <td>
                <?php $srcVal = (string)($r['source'] ?? 'admin'); ?>
                <?php if ($srcVal === 'self'): ?>
                  <span class="badge text-bg-primary">Self</span>
                <?php elseif ($srcVal === 'import'): ?>
                  <span class="badge text-bg-secondary">Import</span>
                <?php else: ?>
                  <span class="text-muted small">HR</span>
                <?php endif; ?>
              </td>
              <td><?= h($r['notes'] ?: '') ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-primary" href="attendance_edit.php?id=<?= (int)$r['id'] ?>">Edit</a>

                <form method="post" class="d-inline">
              <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="to" value="approved">
                  <button class="btn btn-sm btn-success">Approve</button>
                </form>

                <div class="btn-group">
                  <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">More</button>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                      <form method="post" class="px-3 py-1">
              <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="to" value="absent">
                        <button class="btn btn-sm btn-outline-danger w-100">Mark Absent</button>
                      </form>
                    </li>
                    <li>
                      <form method="post" class="px-3 py-1">
              <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="to" value="half">
                        <button class="btn btn-sm btn-outline-warning w-100">Half Day</button>
                      </form>
                    </li>
                    <li>
                      <form method="post" class="px-3 py-1">
              <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="to" value="on_leave">
                        <button class="btn btn-sm btn-outline-info w-100">On Leave</button>
                      </form>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                      <form method="post" class="px-3 py-1" onsubmit="return confirm('Delete this row?')">
              <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger w-100">Delete</button>
                      </form>
                    </li>
                  </ul>
                </div>

              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    </div>
  </div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
