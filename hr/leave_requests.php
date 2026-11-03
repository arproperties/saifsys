<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/url_helper.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_once __DIR__ . '/includes/hr_schedule_helper.php';
require_once __DIR__ . '/includes/hr_upload_guard.php';
require_once __DIR__ . '/includes/hr_comp_off.php';

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
    ? current_user_roles($conn)
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

// The employee behind this login, for self-service.
$selfEmpId = 0;
if ($uid) {
    $stmt = $conn->prepare("SELECT id FROM employees WHERE user_id=? ORDER BY id LIMIT 1");
    $stmt->execute([$uid]);
    $selfEmpId = (int)($stmt->fetchColumn() ?: 0);
}
$compOffReady = hr_comp_off_ready($conn);

// The comp off popup checks the date as it is picked.
if (isset($_GET['comp_off_check'])) {
    header('Content-Type: application/json');
    $checkEmp = $can_manage_all ? (int)($_GET['employee_id'] ?? 0) : $selfEmpId;
    echo json_encode($compOffReady
        ? hr_comp_off_check($conn, $checkEmp, (string)($_GET['date'] ?? ''))
        : ['ok' => false, 'lines' => [['ok' => false, 'text' => 'Comp off is not set up yet (run migrations/hr_comp_off.sql).']]]);
    exit;
}

/* Create/Update status */
$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Comp off: request a credit for a worked holiday or off day. Always pending;
    // only approval below touches the leave balance.
    if (isset($_POST['comp_off_create']) && $compOffReady) {
        $coEmp = $can_manage_all ? (int)($_POST['employee_id'] ?? 0) : $selfEmpId;
        $coDate = (string)($_POST['work_date'] ?? '');
        $coDays = ($_POST['days'] ?? '1') === '0.5' ? 0.5 : 1.0;
        [$coReason, $coReasonErr] = hr_comp_off_reason_text((string)($_POST['reason_type'] ?? 'holiday'), (string)($_POST['reason'] ?? ''));
        $coReason = (string)$coReason;
        $check = hr_comp_off_check($conn, $coEmp, $coDate);
        // Total is worked out here from the two times, not taken from the form.
        $coFrom = substr(trim((string)($_POST['time_from'] ?? '')), 0, 5);
        $coTo = substr(trim((string)($_POST['time_to'] ?? '')), 0, 5);
        $coHours = hr_comp_off_hours($coFrom, $coTo);
        if ($coReasonErr !== null) {
            $err = $coReasonErr;
        } elseif ($coHours === null) {
            $err = 'Give the time worked, from and to.';
        } elseif (!$check['ok']) {
            $last = end($check['lines']);
            $err = $last ? $last['text'] : 'This day cannot earn comp off.';
        } else {
            $coCompany = $conn->prepare("SELECT company_id, full_name, employee_code FROM employees WHERE id=?");
            $coCompany->execute([$coEmp]);
            $coEmpRow = $coCompany->fetch(PDO::FETCH_ASSOC) ?: [];
            $conn->prepare("INSERT INTO hr_comp_off_credits (employee_id, work_date, days, eligible_as, time_from, time_to, hours_worked, reason, status, requester_id, company_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)")
                 ->execute([$coEmp, $coDate, $coDays, $check['eligible_as'], $coFrom, $coTo, $coHours, $coReason !== '' ? $coReason : null, $reqUserId, (int)($coEmpRow['company_id'] ?? 1)]);
            $coId = (int)$conn->lastInsertId();
            $msg = 'Comp off requested for ' . date('D j M Y', strtotime($coDate)) . '. It is added to the leave balance once approved.';
            audit_bridge_hr_ops(
                'comp_off_requested',
                'hr_comp_off_credits',
                $coId,
                'Requested comp off for ' . ($coEmpRow['full_name'] ?? ('#' . $coEmp)) . ' (' . $coDate . ', ' . $coDays . ' day)',
                isset($coEmpRow['company_id']) ? (int)$coEmpRow['company_id'] : null,
                ['employee_id' => $coEmp, 'work_date' => $coDate, 'days' => $coDays, 'eligible_as' => $check['eligible_as'], 'time_from' => $coFrom, 'time_to' => $coTo, 'hours_worked' => $coHours, 'attendance_hours' => $check['hours'], 'reason' => $coReason !== '' ? $coReason : null],
                'Comp off #' . $coId,
                $uid ? (int)$uid : null
            );
            if (!empty($coEmpRow)) {
                require_once __DIR__ . '/../includes/mailer.php';
                $coName = htmlspecialchars($coEmpRow['full_name'] ?: $coEmpRow['employee_code']);
                $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                    . "://{$_SERVER['HTTP_HOST']}" . (get_base_path() ? get_base_path() : '');
                send_notification_to_owners_async($conn, "Comp Off Request - {$coName} (" . htmlspecialchars($coEmpRow['employee_code']) . ")",
                    "<html><body style='font-family: Arial, sans-serif; color: #333;'>"
                    . "<h2 style='color: #2c3e50;'>Comp Off Request</h2>"
                    . "<p><strong>Employee:</strong> {$coName}</p>"
                    . "<p><strong>Date worked:</strong> " . date('M d, Y', strtotime($coDate)) . " (" . htmlspecialchars(hr_comp_off_eligible_label($check['eligible_as'])) . ")</p>"
                    . "<p><strong>Time worked:</strong> {$coFrom} – {$coTo} ({$coHours} h)</p>"
                    . "<p><strong>Credit:</strong> {$coDays} day</p>"
                    . ($coReason !== '' ? "<p><strong>Reason:</strong> " . htmlspecialchars($coReason) . "</p>" : '')
                    . "<p><a href='{$baseUrl}/hr/leave_requests.php?status=pending'>Review request</a></p></body></html>");
            }
        }
    }

    if ($can_manage_all && isset($_POST['comp_off_decide']) && $compOffReady) {
        $res = hr_comp_off_decide($conn, (int)($_POST['id'] ?? 0), (string)($_POST['new_status'] ?? ''), $reqUserId);
        if ($res['ok']) {
            $msg = $res['message'];
            $r = $res['row'];
            $coName = $conn->prepare("SELECT full_name, employee_code, company_id FROM employees WHERE id=?");
            $coName->execute([(int)$r['employee_id']]);
            $coEmpRow = $coName->fetch(PDO::FETCH_ASSOC) ?: [];
            audit_bridge_hr_ops(
                'comp_off_' . $r['status'],
                'hr_comp_off_credits',
                (int)$r['id'],
                ucfirst($r['status']) . ' comp off #' . $r['id'] . ' for ' . ($coEmpRow['full_name'] ?? ('#' . $r['employee_id']))
                    . ' (' . $r['work_date'] . ', ' . (float)$r['days'] . ' day)',
                isset($coEmpRow['company_id']) ? (int)$coEmpRow['company_id'] : null,
                ['status' => $r['status'], 'previous_status' => $r['previous_status'], 'employee_id' => (int)$r['employee_id'], 'work_date' => $r['work_date'], 'days' => (float)$r['days']],
                'Comp off #' . $r['id'],
                $uid ? (int)$uid : null
            );
        } else {
            $err = $res['message'];
        }
    }
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
        } elseif (($dateErr = hr_leave_dates_error($date_from, $date_to)) !== null) {
            $err = $dateErr;
        } elseif ($days <= 0) {
            $err = 'The leave must be at least one day.';
        } elseif (($coErr = hr_comp_off_leave_error($conn, $employee_id, $leave_type_id, $date_from, (float)$days)) !== null) {
            $err = $coErr;
        } else {
            // attachment
            $attach_path = null;
            if (!empty($_FILES['attachment']['name'])) {
                $dir = __DIR__.'/../uploads/leave_attachments';
                if (!is_dir($dir)) mkdir($dir,0775,true);
                $ext = hr_upload_safe_ext((string)$_FILES['attachment']['name']);
                if (!is_writable($dir)) { $err = 'Upload folder not writable.'; }
                elseif ($ext === null) { $err = 'Attachment must be a PDF or an image (JPG, PNG, WEBP).'; }
                else {
                    hr_upload_protect_dir($dir);
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

    // Raised from the Leave Calendar (double-click on a day): go back there,
    // to the request's month, with the result as a message.
    if (isset($_POST['create']) && ($_POST['return_to'] ?? '') === 'calendar') {
        $_SESSION['lc_flash'] = $err !== '' ? ['danger', $err] : ['success', $msg !== '' ? $msg : 'Leave request submitted.'];
        $lcMonth = preg_match('/^(\d{4}-\d{2})-\d{2}$/', (string)($_POST['date_from'] ?? ''), $lcM) ? $lcM[1] : date('Y-m');
        header('Location: leave_calendar.php?month=' . $lcMonth . '&show=pending');
        exit;
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
                if ($new === 'approved' && (hr_leave_dates_error((string)$req['date_from'], (string)$req['date_to']) !== null || (float)$req['days'] <= 0)) {
                    throw new Exception('Request #' . $id . ' has wrong dates (' . $req['date_from'] . ' → ' . $req['date_to'] . ', ' . (float)$req['days'] . ' days). Reject it and request again.');
                }
                if ($new === 'approved'
                    && ($coErr = hr_comp_off_leave_error($conn, (int)$req['employee_id'], (int)$req['leave_type_id'], (string)$req['date_from'], (float)$req['days'], $id)) !== null) {
                    throw new Exception($coErr);
                }

                // update
                $u = $conn->prepare("UPDATE leave_requests SET status=?, approver_id=?, decided_at=NOW() WHERE id=?");
                $u->execute([$new, $uid, $id]);

                // update balance on approval
                if ($new === 'approved' && (int)$req['leave_type_id'] === hr_comp_off_type_id($conn)) {
                    // Comp off does not lapse: use the oldest days first.
                    hr_comp_off_take($conn, (int)$req['employee_id'], (float)$req['days'], (int)date('Y', strtotime($req['date_from'])));
                } elseif ($new === 'approved') {
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

/* Comp off credits, same scope as the requests */
$compOffRows = [];
if ($compOffReady) {
    $coWhere = [];
    $coPrms = [];
    if (!$can_manage_all) {
        $coWhere[] = "co.employee_id=?"; $coPrms[] = $selfEmpId;
    } else {
        if ($emp_filter) { $coWhere[] = "co.employee_id=?"; $coPrms[] = $emp_filter; }
        hr_add_company_where($coWhere, $coPrms, $selectedCompanyId, 'e.company_id');
    }
    if ($status_f !== '') { $coWhere[] = "co.status=?"; $coPrms[] = $status_f; }
    $coStmt = $conn->prepare("
        SELECT co.*, e.full_name, e.employee_code, c.name AS company_name
        FROM hr_comp_off_credits co
        JOIN employees e ON e.id = co.employee_id
        LEFT JOIN companies c ON c.id = e.company_id
        " . ($coWhere ? 'WHERE ' . implode(' AND ', $coWhere) : '') . "
        ORDER BY co.created_at DESC
        LIMIT 500
    ");
    $coStmt->execute($coPrms);
    $compOffRows = $coStmt->fetchAll(PDO::FETCH_ASSOC);
}

// One list: leave requests and comp off credits, newest first. Credits show
// unless another leave type is filtered.
$listRows = array_map(fn($r) => ['kind' => 'leave'] + $r, $rows);
if (!$type_filter || $type_filter === hr_comp_off_type_id($conn)) {
    foreach ($compOffRows as $coRow) {
        $listRows[] = ['kind' => 'credit'] + $coRow;
    }
    usort($listRows, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
}

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
    ($compOffReady && ($can_manage_all || $selfEmpId)
        ? '<button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#compOffModal"><i class="bi bi-plus-lg me-1"></i>Comp off</button>'
        : '')
    . '<a href="leave_types.php" class="btn btn-primary"><i class="bi bi-tags me-1"></i>Leave Types</a>'
    . '<a href="leave_balances.php" class="btn btn-success"><i class="bi bi-calculator me-1"></i>Balances</a>'
);
?>

  <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php
    // HR: credits waiting, unless the list below is already showing exactly those.
    $showingPendingCompOff = $status_f === 'pending' && $type_filter === hr_comp_off_type_id($conn);
    if ($can_manage_all && !$showingPendingCompOff) echo hr_comp_off_pending_notice($conn, (int)$selectedCompanyId);
  ?>

  <?php
    // Opened from the Leave Calendar by double-clicking a day: that day is
    // filled in, and Submit goes back to the calendar.
    $fromCalendar = ($_GET['from'] ?? '') === 'calendar';
    $prefillDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) && strtotime($_GET['date'])
        ? (string)$_GET['date'] : '';
  ?>
  <div class="hr-settings-card mb-4" id="new-request">
    <div class="settings-header">New Request</div>
    <div class="card-body">
      <?php if ($fromCalendar && $prefillDate !== ''): ?>
        <div class="alert alert-info py-2 small d-flex justify-content-between align-items-center">
          <span><i class="bi bi-calendar-plus me-1"></i>Requesting leave for <strong><?= htmlspecialchars(date('D j M Y', strtotime($prefillDate))) ?></strong>, from the Leave calendar. Change the dates if needed.</span>
          <a href="leave_calendar.php?month=<?= htmlspecialchars(substr($prefillDate, 0, 7)) ?>" class="ms-2">Back to calendar</a>
        </div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="row g-3">
        <?php csrf_field(); ?>
        <input type="hidden" name="create" value="1">
        <?php if ($fromCalendar): ?><input type="hidden" name="return_to" value="calendar"><?php endif; ?>
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
          <input type="date" name="date_from" class="form-control" required value="<?= htmlspecialchars($prefillDate) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">To *</label>
          <input type="date" name="date_to" class="form-control" required value="<?= htmlspecialchars($prefillDate) ?>">
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
      <script>
      // To can't be before From.
      (function () {
        var f = document.querySelector('#new-request form');
        var from = f && f.querySelector('input[name="date_from"]'), to = f && f.querySelector('input[name="date_to"]');
        if (!from || !to) return;
        function sync() { to.min = from.value; if (to.value && to.value < from.value) to.value = from.value; }
        from.addEventListener('change', sync);
        sync();
      })();
      </script>
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
    <div class="hr-table-shell hr-table-scroll border-0 shadow-none rounded-0">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th><th>Employee</th><th>Company</th><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Attachment</th><th class="hr-row-actions-cell">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($listRows as $r): ?>
          <?php if ($r['kind'] === 'credit'): $co = $r; ?>
            <tr>
              <td class="text-nowrap" title="Comp off credit #<?= (int)$co['id'] ?>">CO-<?= (int)$co['id'] ?></td>
              <td><?= htmlspecialchars($co['full_name']) . ' (' . htmlspecialchars($co['employee_code']) . ')' ?></td>
              <td><?= htmlspecialchars($co['company_name'] ?: '—') ?></td>
              <td>Comp Off</td>
              <td><?= htmlspecialchars($co['work_date']) ?></td>
              <td><?= number_format((float)$co['days'], 2) ?></td>
              <td>
                <?php $coCls = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','cancelled'=>'secondary'][$co['status']] ?? 'secondary'; ?>
                <span class="badge text-bg-<?= $coCls ?>"><?= htmlspecialchars($co['status']) ?></span>
              </td>
              <td>—</td>
              <td class="hr-row-actions-cell">
                <?php if ($can_manage_all && in_array($co['status'], ['pending', 'approved'], true)): ?>
                <div class="hr-row-actions">
                  <?php foreach ($co['status'] === 'pending' ? ['approved' => ['Approve', 'btn-success'], 'rejected' => ['Reject', 'btn-outline-danger']] : ['cancelled' => ['Cancel', 'btn-outline-secondary']] as $to => [$btnLabel, $btnCls]): ?>
                  <form method="post" class="d-inline"<?= $to === 'cancelled' ? ' onsubmit="return confirm(\'Cancel this approved comp off? It is taken off the leave balance.\')"' : '' ?>>
                    <?php csrf_field(); ?>
                    <input type="hidden" name="comp_off_decide" value="1">
                    <input type="hidden" name="id" value="<?= (int)$co['id'] ?>">
                    <input type="hidden" name="new_status" value="<?= $to ?>">
                    <button class="btn btn-sm <?= $btnCls ?>"><?= $btnLabel ?></button>
                  </form>
                  <?php endforeach; ?>
                </div>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php else: ?>
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
              <td class="hr-row-actions-cell">
                <?php if ($can_manage_all && $r['status']==='pending'): ?>
                <div class="hr-row-actions">
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
                </div>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endif; ?>
          <?php endforeach; if (!$listRows): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No requests found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    </div>
  </div>

  <?php if ($compOffReady && ($can_manage_all || $selfEmpId)): ?>
  <div class="modal fade" id="compOffModal" tabindex="-1" aria-labelledby="compOffTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form method="post" class="modal-content" id="compOffForm">
        <?php csrf_field(); ?>
        <input type="hidden" name="comp_off_create" value="1">
        <div class="modal-header">
          <h5 class="modal-title" id="compOffTitle">Request comp off</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted">For a public holiday or an off day that was worked. HR approves it, then it is added to the Comp Off leave balance.</p>
          <?php if ($can_manage_all): ?>
          <div class="mb-3">
            <label class="form-label">Employee *</label>
            <select name="employee_id" class="form-select" data-co-emp required>
              <option value="">-- Select --</option>
              <?php foreach ($employees as $e): ?>
                <option value="<?= (int)$e['id'] ?>"><?= htmlspecialchars($e['label']) ?><?= !empty($e['company_name']) ? ' - ' . htmlspecialchars($e['company_name']) : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="mb-2">
            <label class="form-label">Date worked *</label>
            <input type="date" name="work_date" class="form-control" data-co-date max="<?= htmlspecialchars(hr_comp_off_today()) ?>" required>
          </div>
          <ul class="list-unstyled small mb-3" data-co-result aria-live="polite"></ul>
          <div class="row g-2 mb-3">
            <div class="col-4">
              <label class="form-label" for="coFrom">From *</label>
              <input type="time" name="time_from" id="coFrom" class="form-control" required data-co-from>
            </div>
            <div class="col-4">
              <label class="form-label" for="coTo">To *</label>
              <input type="time" name="time_to" id="coTo" class="form-control" required data-co-to>
            </div>
            <div class="col-4">
              <label class="form-label" for="coTotal">Total</label>
              <input type="text" id="coTotal" class="form-control bg-light" readonly tabindex="-1" data-co-total>
            </div>
            <div class="col-12 form-text mt-1">Filled in from attendance when there is one. An end before the start is the next morning.</div>
          </div>
          <div class="mb-3">
            <div>
              <label class="form-label d-block">Credit</label>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="days" id="coFull" value="1" checked>
                <label class="form-check-label" for="coFull">Full day</label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="days" id="coHalf" value="0.5">
                <label class="form-check-label" for="coHalf">Half day</label>
              </div>
            </div>
          </div>
          <div>
            <label class="form-label">Reason *</label>
            <select name="reason_type" class="form-select" data-co-reason>
              <?php foreach (hr_comp_off_reasons() as $rk => $rl): ?>
                <option value="<?= htmlspecialchars($rk) ?>" <?= $rk === 'holiday' ? 'selected' : '' ?>><?= htmlspecialchars($rl) ?></option>
              <?php endforeach; ?>
            </select>
            <input name="reason" class="form-control mt-2 d-none" maxlength="490" placeholder="Write the reason" data-co-other>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" data-co-submit disabled>Submit</button>
        </div>
      </form>
    </div>
  </div>
  <script>
  (function () {
    var form = document.getElementById('compOffForm');
    if (!form) return;
    var emp = form.querySelector('[data-co-emp]'), date = form.querySelector('[data-co-date]');
    var out = form.querySelector('[data-co-result]'), submit = form.querySelector('[data-co-submit]');
    var reason = form.querySelector('[data-co-reason]'), other = form.querySelector('[data-co-other]');
    var tFrom = form.querySelector('[data-co-from]'), tTo = form.querySelector('[data-co-to]'), total = form.querySelector('[data-co-total]');
    var seq = 0, dateOk = false, reasonPicked = false;
    // Total from the two times; an end before the start is the next morning.
    function minutes() {
      if (!tFrom.value || !tTo.value) return 0;
      var a = tFrom.value.split(':'), b = tTo.value.split(':');
      var m = (+b[0] * 60 + +b[1]) - (+a[0] * 60 + +a[1]);
      return m < 0 ? m + 1440 : m;
    }
    function hoursOk() {
      var m = minutes();
      total.value = m ? (Math.floor(m / 60) + ' h' + (m % 60 ? ' ' + (m % 60) + ' min' : '')) + (tTo.value < tFrom.value ? ' (+1)' : '') : '';
      return m > 0;
    }
    // Others needs its own words; the other two speak for themselves.
    function syncReason() {
      var isOther = reason.value === 'other';
      other.classList.toggle('d-none', !isOther);
      other.required = isOther;
      submit.disabled = !dateOk || !hoursOk() || (isOther && !other.value.trim());
    }
    reason.addEventListener('change', function () { reasonPicked = true; syncReason(); if (reason.value === 'other') other.focus(); });
    other.addEventListener('input', syncReason);
    tFrom.addEventListener('input', syncReason);
    tTo.addEventListener('input', syncReason);
    function line(ok, text, cls) {
      var li = document.createElement('li');
      li.className = 'mb-1 ' + (cls || (ok ? 'text-success' : 'text-danger'));
      var i = document.createElement('i');
      i.className = 'bi me-1 ' + (cls ? 'bi-exclamation-triangle' : (ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill'));
      li.appendChild(i);
      li.appendChild(document.createTextNode(text));
      out.appendChild(li);
    }
    function check() {
      dateOk = false;
      submit.disabled = true;
      out.textContent = '';
      if ((emp && !emp.value) || !date.value) return;
      var my = ++seq;
      var url = 'leave_requests.php?comp_off_check=1&date=' + encodeURIComponent(date.value) + (emp ? '&employee_id=' + encodeURIComponent(emp.value) : '');
      fetch(url, {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (my !== seq) return;
          out.textContent = '';
          (res.lines || []).forEach(function (l) { line(l.ok, l.text); });
          if (res.warning) line(false, res.warning, 'text-warning-emphasis');
          // Match the reason to the day unless one was picked by hand.
          if (res.ok && !reasonPicked) reason.value = res.eligible_as === 'holiday' ? 'holiday' : 'off_day';
          // The day's times from attendance, for a new date.
          tFrom.value = res.ok && res.check_in ? res.check_in : '';
          tTo.value = res.ok && res.check_out ? res.check_out : '';
          dateOk = !!res.ok;
          syncReason();
        })
        .catch(function () { if (my === seq) { out.textContent = ''; line(false, 'Could not check the date. Try again.'); } });
    }
    if (emp) emp.addEventListener('change', check);
    date.addEventListener('change', check);
    form.addEventListener('submit', function () { submit.disabled = true; });
    document.getElementById('compOffModal').addEventListener('show.bs.modal', function () { check(); });
  })();
  </script>
  <?php endif; ?>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
