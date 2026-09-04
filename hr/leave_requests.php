<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/url_helper.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_once __DIR__ . '/includes/hr_schedule_helper.php';

require_login();

// start session (belt-and-suspenders)
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// who am I?
$uid = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

// verify this user id actually exists; if not, fall back to NULL
$reqUserId = null;
if (!is_null($uid)) {
    $chk = $conn->prepare("SELECT 1 FROM `user` WHERE id=?");
    $chk->execute([$uid]);
    if ($chk->fetchColumn()) {
        $reqUserId = $uid;
    }
}


// what roles do I have?
$roles = function_exists('current_user_roles')
    ? current_user_roles()
    : (isset($_SESSION['roles']) ? (array)$_SESSION['roles'] : []);

// can I manage everyone?
$can_manage_all = !empty(array_intersect($roles, ['Owner','Admin','HR']));
$companies = hr_active_companies($conn);
$selectedCompanyId = $can_manage_all ? hr_selected_company_id($conn, $companies) : 0;

/* Helpers */
function working_days_inclusive(PDO $conn, int $employeeId, string $from, string $to): int {
    if ($employeeId > 0) {
        return hr_schedule_leave_days($conn, $employeeId, $from, $to);
    }
    return hr_schedule_calendar_days($from, $to);
}
function ensure_balance($conn, $emp_id, $type_id, $year) {
    $stmt = $conn->prepare("SELECT id FROM leave_balances WHERE employee_id=? AND leave_type_id=? AND year=?");
    $stmt->execute([$emp_id,$type_id,$year]);
    if (!$stmt->fetchColumn()) {
        // opening = quota if exists
        $quota = $conn->prepare("SELECT annual_quota_days FROM leave_types WHERE id=?");
        $quota->execute([$type_id]);
        $opening = (float)($quota->fetchColumn() ?: 0);
        $ins = $conn->prepare("INSERT INTO leave_balances (employee_id,leave_type_id,year,opening,accrued,taken,carried,closing)
                               VALUES (?,?,?,?,?,?,?,?)");
        $ins->execute([$emp_id,$type_id,$year,$opening,0,0,0,$opening]);
    }
}

/* Create/Update status */
$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (isset($_POST['create'])) {
        $employee_id  = (int)($_POST['employee_id'] ?? 0);
        $leave_type_id= (int)($_POST['leave_type_id'] ?? 0);
        $date_from    = $_POST['date_from'] ?? '';
        $date_to      = $_POST['date_to'] ?? '';
        $reason       = trim($_POST['reason'] ?? '');

        // self‑service: if not manager/hr, lock employee_id to current employee (by user_id)
        if (!$can_manage_all) {
            $stmt = $conn->prepare("SELECT id FROM employees WHERE user_id=?");
            $stmt->execute([$uid]);
            $employee_id = (int)$stmt->fetchColumn();
        }

        $days = working_days_inclusive($conn, $employee_id, $date_from, $date_to);

        if (!$employee_id || !$leave_type_id || !$date_from || !$date_to) {
            $err = 'Please fill all required fields.';
        } else {
            // attachment
            $attach_path = null;
            if (!empty($_FILES['attachment']['name'])) {
                $dir = __DIR__.'/../uploads/leave_attachments';
                if (!is_dir($dir)) mkdir($dir,0775,true);
                if (!is_writable($dir)) { $err = 'Upload folder not writable.'; }
                else {
                    $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
                    $fname = 'att_'.time().'_'.mt_rand(1000,9999).'.'.$ext;
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dir.'/'.$fname)) {
                        $attach_path = 'uploads/leave_attachments/'.$fname;
                    }
                }
            }

            if (!$err) {
                // Get employee info for email
                $empStmt = $conn->prepare("SELECT full_name, employee_code FROM employees WHERE id = ?");
                $empStmt->execute([$employee_id]);
                $empInfo = $empStmt->fetch(PDO::FETCH_ASSOC);
                
                $stmt = $conn->prepare("INSERT INTO leave_requests
                  (employee_id, leave_type_id, date_from, date_to, days, reason, requester_id, attachment_path)
                  VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute([$employee_id,$leave_type_id,$date_from,$date_to,$days,$reason,$uid,$attach_path]);
                $leaveId = (int)$conn->lastInsertId();
                $msg = 'Leave request submitted.';

                $empCompanyId = null;
                try {
                    $cSt = $conn->prepare("SELECT company_id FROM employees WHERE id = ? LIMIT 1");
                    $cSt->execute([$employee_id]);
                    $empCompanyId = (int)($cSt->fetchColumn() ?: 0) ?: null;
                } catch (Throwable $e) {
                    $empCompanyId = null;
                }
                audit_bridge_hr_ops(
                    'leave_requested',
                    'leave_requests',
                    $leaveId > 0 ? $leaveId : $employee_id,
                    'Submitted leave request'
                        . ($empInfo ? (' for ' . ($empInfo['full_name'] ?: $empInfo['employee_code'])) : '')
                        . ' (' . $date_from . ' → ' . $date_to . ', ' . $days . ' day(s))',
                    $empCompanyId,
                    [
                        'employee_id' => $employee_id,
                        'leave_type_id' => $leave_type_id,
                        'date_from' => $date_from,
                        'date_to' => $date_to,
                        'days' => $days,
                        'reason' => $reason !== '' ? $reason : null,
                    ],
                    'Leave #' . ($leaveId > 0 ? $leaveId : '?'),
                    $uid ? (int)$uid : null
                );
                
                // Send email notification to owners
                if ($empInfo) {
                    require_once __DIR__ . '/../includes/mailer.php';
                    $employeeName = htmlspecialchars($empInfo['full_name'] ?: $empInfo['employee_code']);
                    $employeeCode = htmlspecialchars($empInfo['employee_code']);
                    $typeStmt = $conn->prepare("SELECT name FROM leave_types WHERE id = ? LIMIT 1");
                    $typeStmt->execute([$leave_type_id]);
                    $leaveTypeName = htmlspecialchars($typeStmt->fetchColumn() ?: 'Unknown');
                    $subject = "New Leave Request - {$employeeName} ({$employeeCode})";
                    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                        . "://{$_SERVER['HTTP_HOST']}" . (get_base_path() ? get_base_path() : '');
                    $reviewUrl = $baseUrl . "/hr/leave_requests.php?status=pending"
                        . ($selectedCompanyId > 0 ? '&company_id=' . (int)$selectedCompanyId : '');
                    $html = "
                        <html>
                        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                                <h2 style='color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px;'>New Leave Request</h2>
                                <p>A new leave request has been submitted and requires your approval.</p>
                                <div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                                    <p><strong>Employee:</strong> {$employeeName} ({$employeeCode})</p>
                                    <p><strong>Leave Type:</strong> {$leaveTypeName}</p>
                                    <p><strong>Date From:</strong> " . date('M d, Y', strtotime($date_from)) . "</p>
                                    <p><strong>Date To:</strong> " . date('M d, Y', strtotime($date_to)) . "</p>
                                    <p><strong>Days:</strong> {$days}</p>
                                    " . (!empty($reason) ? "<p><strong>Reason:</strong> " . htmlspecialchars($reason) . "</p>" : "") . "
                                </div>
                                <p style='margin-top: 20px;'>
                                    <a href='{$reviewUrl}' style='background: #3498db; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                                        Review Request
                                    </a>
                                </p>
                                <p style='color: #7f8c8d; font-size: 12px; margin-top: 30px;'>
                                    This is an automated notification from the HR Management System.
                                </p>
                            </div>
                        </body>
                        </html>
                    ";
                    send_notification_to_owners_async($conn, $subject, $html);
                }
            }
        }
    }

    if ($can_manage_all && isset($_POST['change_status'])) {
        $id = (int)$_POST['id'];
        $new = $_POST['new_status'];
        if (in_array($new, ['approved','rejected','cancelled'])) {
            $conn->beginTransaction();
            try {
                // get req
                $q = $conn->prepare("SELECT lr.*, e.full_name, e.employee_code, e.email, lt.name AS type_name
                                     FROM leave_requests lr
                                     JOIN employees e ON e.id = lr.employee_id
                                     JOIN leave_types lt ON lt.id = lr.leave_type_id
                                     WHERE lr.id=? FOR UPDATE");
                $q->execute([$id]);
                $req = $q->fetch(PDO::FETCH_ASSOC);
                if (!$req) throw new Exception('Not found');
                if ($req['status'] !== 'pending' && $new==='approved') throw new Exception('Already decided');

                // update
                $u = $conn->prepare("UPDATE leave_requests SET status=?, approver_id=?, decided_at=NOW() WHERE id=?");
                $u->execute([$new, $uid, $id]);

                // update balance on approval
                if ($new === 'approved') {
                    $year = (int)date('Y', strtotime($req['date_from']));
                    ensure_balance($conn, $req['employee_id'], $req['leave_type_id'], $year);
                    $b = $conn->prepare("UPDATE leave_balances SET taken=taken+?, closing=(opening+accrued+carried)-(taken+0)
                                         WHERE employee_id=? AND leave_type_id=? AND year=?");
                    $b->execute([$req['days'], $req['employee_id'], $req['leave_type_id'], $year]);
                }
                $conn->commit();
                $msg = "Request #{$id} {$new}.";

                if (in_array($new, ['approved', 'rejected', 'cancelled'], true)) {
                    $leaveAction = $new === 'approved'
                        ? 'leave_approved'
                        : ($new === 'rejected' ? 'leave_rejected' : 'leave_cancelled');
                    $leaveCompanyId = null;
                    try {
                        $cSt = $conn->prepare("SELECT company_id FROM employees WHERE id = ? LIMIT 1");
                        $cSt->execute([(int)$req['employee_id']]);
                        $leaveCompanyId = (int)($cSt->fetchColumn() ?: 0) ?: null;
                    } catch (Throwable $e) {
                        $leaveCompanyId = function_exists('current_company_id') ? (current_company_id($conn) ?: null) : null;
                    }
                    audit_bridge_hr_ops(
                        $leaveAction,
                        'leave_requests',
                        $id,
                        ucfirst($new) . ' leave request #' . $id . ' for '
                            . ($req['full_name'] ?? $req['employee_code'])
                            . ' (' . ($req['date_from'] ?? '') . ' → ' . ($req['date_to'] ?? '') . ')',
                        $leaveCompanyId ? (int)$leaveCompanyId : null,
                        [
                            'status' => $new,
                            'employee_id' => (int)$req['employee_id'],
                            'leave_type' => $req['type_name'] ?? null,
                            'date_from' => $req['date_from'] ?? null,
                            'date_to' => $req['date_to'] ?? null,
                            'days' => $req['days'] ?? null,
                        ],
                        'Leave #' . $id,
                        $uid ? (int)$uid : null
                    );
                }
                
                // Send email notification to employee (only for approved/rejected, not cancelled)
                if (in_array($new, ['approved', 'rejected']) && !empty($req['email'])) {
                    require_once __DIR__ . '/../includes/mailer.php';
                    $employeeName = htmlspecialchars($req['full_name'] ?: $req['employee_code']);
                    $employeeCode = htmlspecialchars($req['employee_code']);
                    $leaveTypeName = htmlspecialchars($req['type_name']);
                    $statusText = ucfirst($new);
                    $statusColor = $new === 'approved' ? '#27ae60' : '#e74c3c';
                    $statusIcon = $new === 'approved' ? '✓' : '✗';
                    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                        . "://{$_SERVER['HTTP_HOST']}" . (get_base_path() ? get_base_path() : '');
                    $requestUrl = $baseUrl . "/hr/leave_requests.php";
                    
                    $subject = "Leave Request {$statusText} - {$employeeName} ({$employeeCode})";
                    $html = "
                        <html>
                        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                                <h2 style='color: #2c3e50; border-bottom: 2px solid {$statusColor}; padding-bottom: 10px;'>
                                    Leave Request {$statusText}
                                </h2>
                                <p>Dear {$employeeName},</p>
                                <p>Your leave request has been <strong style='color: {$statusColor};'>{$statusText}</strong>.</p>
                                <div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid {$statusColor};'>
                                    <p><strong>Employee:</strong> {$employeeName} ({$employeeCode})</p>
                                    <p><strong>Leave Type:</strong> {$leaveTypeName}</p>
                                    <p><strong>Date From:</strong> " . date('M d, Y', strtotime($req['date_from'])) . "</p>
                                    <p><strong>Date To:</strong> " . date('M d, Y', strtotime($req['date_to'])) . "</p>
                                    <p><strong>Days:</strong> {$req['days']}</p>
                                    " . (!empty($req['reason']) ? "<p><strong>Reason:</strong> " . htmlspecialchars($req['reason']) . "</p>" : "") . "
                                </div>
                                <p style='margin-top: 20px;'>
                                    <a href='{$requestUrl}' style='background: {$statusColor}; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                                        View Request
                                    </a>
                                </p>
                                <p style='color: #7f8c8d; font-size: 12px; margin-top: 30px;'>
                                    This is an automated notification from the HR Management System.
                                </p>
                            </div>
                        </body>
                        </html>
                    ";
                    send_notification_to_employee_async($conn, $req['email'], $subject, $html);
                }
            } catch(Exception $e) {
                $conn->rollBack();
                $err = $e->getMessage();
            }
        }
    }
}

/* Filters */
$emp_filter  = (int)($_GET['employee_id'] ?? 0);
$type_filter = (int)($_GET['leave_type_id'] ?? 0);
$status_f    = $_GET['status'] ?? '';
$where = [];
$prms  = [];

if (!$can_manage_all) {
    // restrict to own requests (via employee linked to user)
    $stmt = $conn->prepare("SELECT id FROM employees WHERE user_id=?");
    $stmt->execute([$uid]);
    $self_emp = (int)($stmt->fetchColumn() ?: 0);
    $where[] = "lr.employee_id=?";
    $prms[]  = $self_emp;
} else {
    if ($emp_filter) { $where[]="lr.employee_id=?"; $prms[]=$emp_filter; }
    hr_add_company_where($where, $prms, $selectedCompanyId, 'e.company_id');
}
if ($type_filter) { $where[]="lr.leave_type_id=?"; $prms[]=$type_filter; }
if ($status_f!=='') { $where[]="lr.status=?"; $prms[]=$status_f; }
$sqlWhere = $where ? ('WHERE '.implode(' AND ',$where)) : '';

/* Lookups */
$types = $conn->query("SELECT id,name FROM leave_types ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$employees = $can_manage_all
  ? (function () use ($conn, $selectedCompanyId) {
      $sql = "SELECT e.id, CONCAT(e.full_name,' (',e.employee_code,')') label, c.name AS company_name
              FROM employees e
              LEFT JOIN companies c ON c.id = e.company_id";
      $where = ["e.status IN (" . hr_employee_status_in_sql(hr_employee_current_statuses()) . ")"];
      $params = hr_employee_current_statuses();
      if ($selectedCompanyId > 0) {
          $where[] = "e.company_id = ?";
          $params[] = $selectedCompanyId;
      }
      $sql .= " WHERE " . implode(' AND ', $where);
      $sql .= " ORDER BY e.full_name";
      $stmt = $conn->prepare($sql);
      $stmt->execute($params);
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    })()
  : [];

/* Rows */
$stmt = $conn->prepare("
SELECT lr.*, e.full_name, e.employee_code, c.name AS company_name, lt.name AS type_name
FROM leave_requests lr
JOIN employees e ON e.id=lr.employee_id
LEFT JOIN companies c ON c.id = e.company_id
JOIN leave_types lt ON lt.id=lr.leave_type_id
$sqlWhere
ORDER BY lr.created_at DESC
");
$stmt->execute($prms);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Page settings for shared layout
$pageTitle = 'Leave';
?>
<?php require_once __DIR__ . '/includes/hr_layout_header.php'; ?>

<?php
echo hr_ui_page_header(
    'Leave Requests',
    'Submit, review, and approve employee leave.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Leave'],
    ],
    '<a href="leave_types.php" class="btn btn-outline-secondary">Leave Types</a>'
    . '<a href="leave_balances.php" class="btn btn-outline-secondary">Balances</a>'
);
?>

  <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <div class="hr-settings-card mb-4">
    <div class="settings-header">New Request</div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="row g-3">
        <?php csrf_field(); ?>
        <input type="hidden" name="create" value="1">
        <?php if ($can_manage_all): ?>
        <div class="col-md-4">
          <label class="form-label">Employee *</label>
          <select name="employee_id" class="form-select" required>
            <option value="">-- Select --</option>
            <?php foreach ($employees as $e): ?>
              <option value="<?= (int)$e['id'] ?>">
                <?= htmlspecialchars($e['label']) ?><?= !empty($e['company_name']) ? ' - ' . htmlspecialchars($e['company_name']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="col-md-3">
          <label class="form-label">Type *</label>
          <select name="leave_type_id" class="form-select" required>
            <option value="">-- Select --</option>
            <?php foreach ($types as $tid=>$tn): ?>
              <option value="<?= (int)$tid ?>"><?= htmlspecialchars($tn) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">From *</label>
          <input type="date" name="date_from" class="form-control" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">To *</label>
          <input type="date" name="date_to" class="form-control" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Reason</label>
          <input name="reason" class="form-control">
        </div>
        <div class="col-md-4">
          <label class="form-label">Attachment</label>
          <input type="file" name="attachment" class="form-control">
        </div>
        <div class="col-12">
          <button class="btn btn-primary">Submit</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($can_manage_all): ?>
  <div class="hr-filter-bar mb-3">
  <form method="get" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label">Company</label>
        <select name="company_id" class="form-select">
          <option value="0">All companies</option>
          <?php foreach ($companies as $company): ?>
            <option value="<?= (int)$company['id'] ?>" <?= $selectedCompanyId === (int)$company['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($company['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Employee</label>
        <select name="employee_id" class="form-select">
          <option value="0">All employees</option>
          <?php foreach ($employees as $e): ?>
            <option value="<?= (int)$e['id'] ?>" <?= $emp_filter === (int)$e['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($e['label']) ?><?= !empty($e['company_name']) ? ' - ' . htmlspecialchars($e['company_name']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Type</label>
        <select name="leave_type_id" class="form-select">
          <option value="0">All types</option>
          <?php foreach ($types as $tid=>$tn): ?>
            <option value="<?= (int)$tid ?>" <?= $type_filter === (int)$tid ? 'selected' : '' ?>>
              <?= htmlspecialchars($tn) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="">All statuses</option>
          <?php foreach (['pending','approved','rejected','cancelled'] as $statusOption): ?>
            <option value="<?= htmlspecialchars($statusOption) ?>" <?= $status_f === $statusOption ? 'selected' : '' ?>>
              <?= htmlspecialchars(ucfirst($statusOption)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary w-100">Filter</button>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <div class="hr-settings-card">
    <div class="settings-header">Requests</div>
    <div class="card-body p-0">
    <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th><th>Employee</th><th>Company</th><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Attachment</th><th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= htmlspecialchars($r['full_name']).' ('.htmlspecialchars($r['employee_code']).')' ?></td>
              <td><?= htmlspecialchars($r['company_name'] ?: '—') ?></td>
              <td><?= htmlspecialchars($r['type_name']) ?></td>
              <td><?= htmlspecialchars($r['date_from']).' → '.htmlspecialchars($r['date_to']) ?></td>
              <td><?= number_format((float)$r['days'],2) ?></td>
              <td>
                <?php
                  $map = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','cancelled'=>'secondary'];
                  $cls = $map[$r['status']] ?? 'secondary';
                ?>
                <span class="badge text-bg-<?= $cls ?>"><?= htmlspecialchars($r['status']) ?></span>
              </td>
              <td>
                <?php if ($r['attachment_path']): ?>
                  <a href="../<?= htmlspecialchars($r['attachment_path']) ?>" target="_blank">File</a>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="text-end">
                <?php if ($can_manage_all && $r['status']==='pending'): ?>
                  <form method="post" class="d-inline">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="change_status" value="1">
                    <input type="hidden" name="new_status" value="approved">
                    <button class="btn btn-sm btn-success">Approve</button>
                  </form>
                  <form method="post" class="d-inline">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="change_status" value="1">
                    <input type="hidden" name="new_status" value="rejected">
                    <button class="btn btn-sm btn-outline-danger">Reject</button>
                  </form>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; if (!$rows): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No requests found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    </div>
  </div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
