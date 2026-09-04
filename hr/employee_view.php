<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/url_helper.php';
require_once __DIR__ . '/../includes/AuditService.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/../lib/Guard.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_once __DIR__ . '/includes/hr_schedule_helper.php';
require_once __DIR__ . '/includes/hr_document_file_helper.php';
require_once __DIR__ . '/includes/hr_loans.php';
require_once __DIR__ . '/includes/employee_view/load_employee.php';
require_once __DIR__ . '/includes/employee_view/load_payroll.php';

$roles = current_user_roles($conn);
$isWorkerSelfService = Guard::isWorker($roles);
$isOwner = in_array('Owner', $roles);
// Issue loans / record cash repayments: same as cash_advances.php (Owner, Admin, HR).
$canManageLoans = !$isWorkerSelfService && (bool) array_intersect($roles, ['Owner', 'Admin', 'HR']);

if (!function_exists('app_get_setting')) {
    function app_get_setting(PDO $conn, string $key, $default = '')
    {
        static $cache = [];
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = $conn->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            $cache[$key] = $default;
            return $default;
        }
        $cache[$key] = $value;
        return $value;
    }
}

if (!$isWorkerSelfService) {
require_role(['Owner','Admin','HR'], $conn);
}

// For workers, get employee ID from session (clean URL) or GET (backward compatibility)
// For admins/HR, get from GET parameter
if ($isWorkerSelfService) {
    $selfEmployeeId = Guard::currentEmployeeId($conn);
    if (!$selfEmployeeId) {
        http_response_code(500);
        echo "Your employee profile is not linked yet. Please contact HR.";
        exit;
    }
    // Workers always use their own ID from session, ignore URL parameter for security
    $employee_id = (string)$selfEmployeeId;
    // Store in session for clean URLs
    $_SESSION['employee_view_id'] = $employee_id;
} else {
    // Admins/HR can view any employee via GET parameter
$employee_id = isset($_GET['id']) ? trim($_GET['id']) : '';
if ($employee_id === '') {
    http_response_code(400);
    echo "Missing employee id.";
    exit;
    }
}

$workerReadOnly = $isWorkerSelfService;

if ($workerReadOnly && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Allow certain actions for workers (logout, profile navigation)
    $allowedActions = ['leave_create', 'cash_advance_request', 'add_expense', 'add_emergency_contact', 'del_emergency_contact', 'update_contact_info'];
    $isAllowedAction = false;
    
    // Check if this is a navigation request to profile or settings (from change password button)
    // The form submits to profile (base path from url_helper), so check the request URI
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($requestUri, '/profile') !== false || strpos($requestUri, '/settings') !== false) {
        // This is navigation to profile/settings page, allow it
        $isAllowedAction = true;
    }
    
    // Check if this is a tab navigation request (from profile/settings pages)
    if (isset($_POST['tab']) && (isset($_SERVER['HTTP_REFERER']) && 
        (strpos($_SERVER['HTTP_REFERER'], '/profile') !== false || 
         strpos($_SERVER['HTTP_REFERER'], '/settings') !== false))) {
        // This is a tab navigation, allow it
        $isAllowedAction = true;
    }
    
    // Check if any allowed action is present
    foreach ($allowedActions as $action) {
        if (isset($_POST[$action])) {
            $isAllowedAction = true;
            break;
        }
    }
    
    if (!$isAllowedAction) {
        require_once __DIR__ . '/../includes/AuditService.php';
        AuditService::log([
            'action' => 'forbidden_mutation',
            'object_type' => 'employee_self_service',
            'object_id' => $employee_id,
            'summary' => 'Worker attempted to modify a restricted tab',
            'success' => false,
        ]);
        http_response_code(403);
        echo "This action is not permitted.";
        exit;
    }
}

/* ------------------------------------
   Helper function to log employee activities
------------------------------------- */
function logEmployeeActivity(PDO $conn, int $employeeId, string $actionType, string $description, ?string $changedField = null, ?string $oldValue = null, ?string $newValue = null, ?int $performedBy = null): void {
    try {
        $performedBy = $performedBy ?? ($_SESSION['user']['id'] ?? null);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        
        $stmt = $conn->prepare("
            INSERT INTO employee_activity_log 
            (employee_id, action_type, action_description, changed_field, old_value, new_value, performed_by, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $employeeId,
            $actionType,
            $description,
            $changedField,
            $oldValue,
            $newValue,
            $performedBy,
            $ipAddress
        ]);
    } catch (Exception $e) {
        // Fail silently - don't break the application if logging fails
        error_log('Failed to log employee activity: ' . $e->getMessage());
    }
}

/* ------------------------------------
   Load employee (by id or employee_code)
------------------------------------- */
$emp = null;
$emp = employee_view_load_employee($conn, $employee_id);
if (!$emp) { http_response_code(404); echo "Employee not found."; exit; }

if (!$workerReadOnly) {
    $_SESSION['hr_employee_profile_view_audit'] = $_SESSION['hr_employee_profile_view_audit'] ?? [];
    $auditKey = (string)$emp['id'];
    if (empty($_SESSION['hr_employee_profile_view_audit'][$auditKey])) {
        $empLabel = trim(($emp['full_name'] ?? '') . ' (' . ($emp['employee_code'] ?? ('#' . $emp['id'])) . ')');
        AuditService::logEvent([
            'action' => 'view',
            'module' => 'hr',
            'company_id' => isset($emp['company_id']) ? (int)$emp['company_id'] : null,
            'object_type' => 'employee_profile',
            'object_id' => (string)$emp['id'],
            'object_ref' => $empLabel !== '' ? $empLabel : ('employee_profile #' . $emp['id']),
            'summary' => 'Viewed employee profile with sensitive HR sections — ' . $empLabel,
            'new_data' => [
                'employee_code' => $emp['employee_code'] ?? null,
                'employee_name' => $emp['full_name'] ?? null,
                'company_id' => $emp['company_id'] ?? null,
                'company_name' => $emp['company_name'] ?? null,
            ],
            'source' => 'user',
            'success' => true,
        ]);
        $_SESSION['hr_employee_profile_view_audit'][$auditKey] = true;
    }
}

$employeeAvatarPath = null;
$avatarMsg = '';
$avatarErr = '';
$canManageAvatar = (!$workerReadOnly && array_intersect($roles, ['Owner','Admin']));
$avatarInitial = strtoupper(substr(trim($emp['full_name'] ?: $emp['employee_code'] ?: 'U'), 0, 1));
if (!empty($emp['user_id'])) {
    $avatarStmt = $conn->prepare("SELECT avatar_path FROM user WHERE id=?");
    $avatarStmt->execute([(int)$emp['user_id']]);
    $employeeAvatarPath = $avatarStmt->fetchColumn() ?: null;

    if ($canManageAvatar && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['employee_avatar_upload'])) {
            csrf_verify();
            if (!isset($_FILES['employee_avatar']) || empty($_FILES['employee_avatar']['name'])) {
                $avatarErr = 'Please choose an image to upload.';
            } else {
                require_once __DIR__ . '/../includes/profile_functions.php';
                $result = uploadUserAvatar($conn, (int)$emp['user_id'], $_FILES['employee_avatar']);
                if (!empty($result['success'])) {
                    $avatarMsg = $result['message'] ?? 'Photo updated successfully.';
                    $employeeAvatarPath = $result['path'] ?? $employeeAvatarPath;
                    $empLabel = trim(($emp['full_name'] ?? '') . ' (' . ($emp['employee_code'] ?? '') . ')');
                    audit_bridge_hr_ops(
                        'avatar_uploaded',
                        'employees',
                        (int)$emp['id'],
                        'Uploaded employee photo for ' . $empLabel,
                        isset($emp['company_id']) ? (int)$emp['company_id'] : null,
                        ['employee_id' => (int)$emp['id'], 'user_id' => (int)$emp['user_id']],
                        $empLabel,
                        isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
                    );
                } else {
                    $avatarErr = $result['error'] ?? 'Unable to upload photo.';
                }
            }
        } elseif (isset($_POST['employee_avatar_delete'])) {
            csrf_verify();
            require_once __DIR__ . '/../includes/profile_functions.php';
            $res = deleteUserAvatar($conn, (int)$emp['user_id']);
            if (!empty($res['success'])) {
                $avatarMsg = $res['message'] ?? 'Photo removed.';
                $employeeAvatarPath = null;
                $empLabel = trim(($emp['full_name'] ?? '') . ' (' . ($emp['employee_code'] ?? '') . ')');
                audit_bridge_hr_ops(
                    'avatar_deleted',
                    'employees',
                    (int)$emp['id'],
                    'Deleted employee photo for ' . $empLabel,
                    isset($emp['company_id']) ? (int)$emp['company_id'] : null,
                    ['employee_id' => (int)$emp['id'], 'user_id' => (int)$emp['user_id']],
                    $empLabel,
                    isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
                );
            } else {
                $avatarErr = $res['error'] ?? 'Unable to remove photo.';
            }
        }
    }
}

$employeeOfMonthData = null;
$employeeOfMonthBanner = null;
$employeeOfMonthInitial = '';
$isEmployeeOfMonthSelf = false;
$employeeOfMonthHeadline = 'Employee of the Month';
$employeeOfMonthTargetHoursSetting = (float)app_get_setting($conn, 'employee_of_month_target_hours', '160');
$employeeOfMonthMessageSetting = app_get_setting($conn, 'employee_of_month_message', "🎉 Congratulations {name}! You're our Employee of the Month! 🌟");
$employeeOfMonthMonthSetting = app_get_setting($conn, 'employee_of_month_month', date('Y-m-01'));
$employeeOfMonthMonthDate = date('Y-m-01', strtotime($employeeOfMonthMonthSetting));
$selectedEmployeeOfMonthId = (int)app_get_setting($conn, 'employee_of_month_id', '0');
$currentAwardRecord = null;

try {
    $awardStmt = $conn->prepare("
        SELECT ea.*, e.full_name, e.employee_code, e.position_title, e.user_id,
               u.avatar_path
        FROM employee_awards ea
        JOIN employees e ON e.id = ea.employee_id
        LEFT JOIN `user` u ON u.id = e.user_id
        WHERE ea.award_type = 'employee_of_month'
          AND ea.award_month = ?
        LIMIT 1
    ");
    $awardStmt->execute([$employeeOfMonthMonthDate]);
    $currentAwardRecord = $awardStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $currentAwardRecord = null;
}

if ($currentAwardRecord) {
    $employeeOfMonthData = $currentAwardRecord;
    $employeeOfMonthHeadline = $currentAwardRecord['headline'] ?: $employeeOfMonthHeadline;
    $employeeOfMonthMessageSetting = $currentAwardRecord['message'] ?: $employeeOfMonthMessageSetting;
} elseif ($selectedEmployeeOfMonthId > 0) {
    $fallbackStmt = $conn->prepare("
        SELECT e.id, e.full_name, e.employee_code, e.position_title,
               u.avatar_path
        FROM employees e
        LEFT JOIN `user` u ON u.id = e.user_id
        WHERE e.id = ?
        LIMIT 1
    ");
    $fallbackStmt->execute([$selectedEmployeeOfMonthId]);
    $employeeOfMonthData = $fallbackStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($employeeOfMonthData) {
    $employeeAwardIdValue = isset($employeeOfMonthData['employee_id'])
        ? (int)$employeeOfMonthData['employee_id']
        : (int)($employeeOfMonthData['id'] ?? 0);
    $name = trim($employeeOfMonthData['full_name'] ?? '');
    if ($name === '') {
        $name = 'Employee #' . $employeeAwardIdValue;
    }
    $employeeOfMonthBanner = str_replace(
        ['{name}', '{Name}', '{NAME}'],
        $name,
        $employeeOfMonthMessageSetting
    );
    $employeeOfMonthInitial = strtoupper(substr($name, 0, 1));
    $isEmployeeOfMonthSelf = ($employeeAwardIdValue === (int)$emp['id']);
}

$profileFeatureFlags = [
    'stats'    => (int)app_get_setting($conn, 'emp_profile_show_stats', '1') === 1,
    'kudos'    => (int)app_get_setting($conn, 'emp_profile_show_kudos', '1') === 1,
    'rewards'  => (int)app_get_setting($conn, 'emp_profile_show_rewards', '1') === 1,
    'progress' => (int)app_get_setting($conn, 'emp_profile_show_progress', '1') === 1,
    'history'  => (int)app_get_setting($conn, 'emp_profile_show_history', '1') === 1,
];

$rewardDefaultItemsSetting = app_get_setting($conn, 'emp_profile_reward_defaults', json_encode([
    'Certificate sent',
    'Bonus processed',
    'Celebration announced'
]));
$rewardDefaultItems = json_decode($rewardDefaultItemsSetting, true);
if (!is_array($rewardDefaultItems)) {
    $rewardDefaultItems = ['Certificate sent', 'Bonus processed', 'Celebration announced'];
}

$latestAwardForEmployee = null;
$latestAwardChecklists = [];
try {
    $latestAwardStmt = $conn->prepare("
        SELECT ea.*, e.full_name, e.employee_code, u.avatar_path
        FROM employee_awards ea
        JOIN employees e ON e.id = ea.employee_id
        LEFT JOIN `user` u ON u.id = e.user_id
        WHERE ea.award_type = 'employee_of_month'
          AND ea.employee_id = ?
        ORDER BY ea.award_month DESC
        LIMIT 1
    ");
    $latestAwardStmt->execute([$emp['id']]);
    $latestAwardForEmployee = $latestAwardStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($latestAwardForEmployee) {
        $latestChecklistStmt = $conn->prepare("
            SELECT id, item_label, is_done
            FROM employee_award_checklists
            WHERE award_id = ?
            ORDER BY id
        ");
        $latestChecklistStmt->execute([$latestAwardForEmployee['id']]);
        $latestAwardChecklists = $latestChecklistStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $latestAwardForEmployee = null;
    $latestAwardChecklists = [];
}

$awardHistory = [];
if ($profileFeatureFlags['history']) {
    try {
        $histStmt = $conn->query("
            SELECT ea.*, e.full_name, e.employee_code
            FROM employee_awards ea
            JOIN employees e ON e.id = ea.employee_id
            WHERE ea.award_type = 'employee_of_month'
            ORDER BY ea.award_month DESC
            LIMIT 8
        ");
        $awardHistory = $histStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $awardHistory = [];
    }
}

$kudosFeed = [];
if ($profileFeatureFlags['kudos']) {
    try {
        $kudosQuery = "
            SELECT k.*, au.fullname AS author_name, au.username AS author_username
            FROM employee_kudos k
            LEFT JOIN `user` au ON au.id = k.author_id
            WHERE k.employee_id = ?
        ";
        if ($workerReadOnly) {
            $kudosQuery .= " AND k.visibility = 'public'";
        }
        $kudosQuery .= " ORDER BY k.created_at DESC LIMIT 6";
        $kudosStmt = $conn->prepare($kudosQuery);
        $kudosStmt->execute([$emp['id']]);
        $kudosFeed = $kudosStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $kudosFeed = [];
    }
}

/* --------------------------
   Helper functions (Leave)
--------------------------- */
function working_days_inclusive($from, $to, ?int $employeeId = null, ?PDO $conn = null): int {
    if ($employeeId && $conn) {
        return hr_schedule_leave_days($conn, $employeeId, $from, $to);
    }
    return hr_schedule_calendar_days($from, $to);
}
function ensure_balance(PDO $conn, int $employee_id, int $type_id, int $year): void {
    $q = $conn->prepare("SELECT id FROM leave_balances WHERE employee_id=? AND leave_type_id=? AND year=?");
    $q->execute([$employee_id,$type_id,$year]);
    if (!$q->fetchColumn()) {
        $quota = $conn->prepare("SELECT annual_quota_days FROM leave_types WHERE id=?");
        $quota->execute([$type_id]);
        $opening = (float)($quota->fetchColumn() ?: 0);
        $ins = $conn->prepare("INSERT INTO leave_balances (employee_id,leave_type_id,year,opening,accrued,taken,carried,closing)
                               VALUES (?,?,?,?,?,?,?,?)");
        $ins->execute([$employee_id,$type_id,$year,$opening,0,0,0,$opening]);
    }
}

/* ------------------------------------
   Handle Document actions (existing)
------------------------------------- */
$errors = [];
$success = null;

// Make sure upload dir exists (documents)
$upload_dir = __DIR__ . '/../uploads/docs';
if (!is_dir($upload_dir)) @mkdir($upload_dir, 0775, true);

// Create/Update document
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['doc_action'])) {
    csrf_verify();
    $doc_action  = $_POST['doc_action'];
    $doc_id      = $_POST['doc_id'] ?? null;
    $doc_type    = trim($_POST['doc_type'] ?? '');
    $doc_number  = trim($_POST['doc_number'] ?? '');
    $issued_at   = trim($_POST['issued_at'] ?? '');
    $expires_at  = trim($_POST['expires_at'] ?? '');
    $notes       = trim($_POST['notes'] ?? '');

    $issued_at   = $issued_at   ? date('Y-m-d', strtotime($issued_at))   : null;
    $expires_at  = $expires_at  ? date('Y-m-d', strtotime($expires_at))  : null;

    $file_name_to_store = null;
    if (!empty($_FILES['doc_file']['name'])) {
        $orig = basename($_FILES['doc_file']['name']);
        $ext  = pathinfo($orig, PATHINFO_EXTENSION);
        $safe = 'doc_' . $emp['id'] . '_' . time() . ($ext ? ".".$ext : "");
        $dest = $upload_dir . '/' . $safe;
        if (!move_uploaded_file($_FILES['doc_file']['tmp_name'], $dest)) {
            $errors[] = "Upload failed: cannot write to folder.";
        } else {
            $file_name_to_store = $safe;
        }
    }

    if (!$errors) {
        if ($doc_action === 'create') {
            $sql = "INSERT INTO employee_documents
                    (employee_id, doc_type, doc_number, issued_at, expires_at, notes, file_path, created_at)
                    VALUES (?,?,?,?,?,?,?,NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$emp['id'], $doc_type, $doc_number, $issued_at, $expires_at, $notes, $file_name_to_store]);
            $newDocId = (int)$conn->lastInsertId();
            $success = "Document added.";
            
            // Log activity
            logEmployeeActivity($conn, $emp['id'], 'document_added', "Added document: {$doc_type}" . ($doc_number ? " (#{$doc_number})" : ''));
            $empLabel = trim(($emp['full_name'] ?? '') . ' (' . ($emp['employee_code'] ?? '') . ')');
            audit_bridge_hr_ops(
                'document_uploaded',
                'employee_documents',
                $newDocId > 0 ? $newDocId : (int)$emp['id'],
                'Uploaded document ' . $doc_type . ($doc_number ? (' #' . $doc_number) : '') . ' for ' . $empLabel,
                isset($emp['company_id']) ? (int)$emp['company_id'] : null,
                [
                    'employee_id' => (int)$emp['id'],
                    'doc_type' => $doc_type,
                    'doc_number' => $doc_number !== '' ? $doc_number : null,
                    'expires_at' => $expires_at,
                    'has_file' => $file_name_to_store ? true : false,
                ],
                'Doc #' . ($newDocId > 0 ? $newDocId : '?') . ' — ' . $empLabel,
                isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
            );
        } elseif ($doc_action === 'update' && $doc_id) {
            if ($file_name_to_store) {
                $sql = "UPDATE employee_documents
                        SET doc_type=?, doc_number=?, issued_at=?, expires_at=?, notes=?, file_path=?, updated_at=NOW()
                        WHERE id=? AND employee_id=?";
                $params = [$doc_type, $doc_number, $issued_at, $expires_at, $notes, $file_name_to_store, $doc_id, $emp['id']];
            } else {
                $sql = "UPDATE employee_documents
                        SET doc_type=?, doc_number=?, issued_at=?, expires_at=?, notes=?, updated_at=NOW()
                        WHERE id=? AND employee_id=?";
                $params = [$doc_type, $doc_number, $issued_at, $expires_at, $notes, $doc_id, $emp['id']];
            }
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $success = "Document updated.";
            
            // Log activity
            logEmployeeActivity($conn, $emp['id'], 'document_updated', "Updated document: {$doc_type}" . ($doc_number ? " (#{$doc_number})" : ''));
            $empLabel = trim(($emp['full_name'] ?? '') . ' (' . ($emp['employee_code'] ?? '') . ')');
            audit_bridge_hr_ops(
                'document_updated',
                'employee_documents',
                (int)$doc_id,
                'Updated document ' . $doc_type . ($doc_number ? (' #' . $doc_number) : '') . ' for ' . $empLabel,
                isset($emp['company_id']) ? (int)$emp['company_id'] : null,
                [
                    'employee_id' => (int)$emp['id'],
                    'doc_type' => $doc_type,
                    'doc_number' => $doc_number !== '' ? $doc_number : null,
                    'expires_at' => $expires_at,
                    'file_replaced' => $file_name_to_store ? true : false,
                ],
                'Doc #' . (int)$doc_id . ' — ' . $empLabel,
                isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
            );
        }
    }
}

// Delete document
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_doc_id'])) {
    csrf_verify();
    $del_id = (int)$_POST['delete_doc_id'];
    $q = $conn->prepare("SELECT file_path, doc_type, doc_number FROM employee_documents WHERE id=? AND employee_id=?");
    $q->execute([$del_id, $emp['id']]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if (!empty($row['file_path'])) {
            $p = hr_document_file_absolute_path($row['file_path']);
            if (is_file($p)) @unlink($p);
        }
    }
    $conn->prepare("DELETE FROM employee_documents WHERE id=? AND employee_id=?")->execute([$del_id, $emp['id']]);
    $success = "Document deleted.";
    
    // Log activity
    logEmployeeActivity($conn, $emp['id'], 'document_deleted', "Deleted document #{$del_id}");
    $empLabel = trim(($emp['full_name'] ?? '') . ' (' . ($emp['employee_code'] ?? '') . ')');
    audit_bridge_hr_ops(
        'document_deleted',
        'employee_documents',
        $del_id,
        'Deleted document'
            . (!empty($row['doc_type']) ? (' ' . $row['doc_type']) : '')
            . (!empty($row['doc_number']) ? (' #' . $row['doc_number']) : '')
            . ' for ' . $empLabel,
        isset($emp['company_id']) ? (int)$emp['company_id'] : null,
        [
            'employee_id' => (int)$emp['id'],
            'doc_type' => $row['doc_type'] ?? null,
            'doc_number' => $row['doc_number'] ?? null,
        ],
        'Doc #' . $del_id . ' — ' . $empLabel,
        isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
    );
}

/* ------------------------------------
   Leave tab actions (new)
------------------------------------- */

// Initialize messages from session (for PRG pattern)
$leave_msg = $_SESSION['leave_msg'] ?? '';
$leave_err = $_SESSION['leave_err'] ?? '';
unset($_SESSION['leave_msg'], $_SESSION['leave_err']);

$year = (int)($_GET['year'] ?? date('Y'));

// New leave request (by HR/Admin/Owner from profile)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['leave_create'])) {
    csrf_verify();
    $leave_type_id = (int)($_POST['leave_type_id'] ?? 0);
    $date_from     = $_POST['date_from'] ?? '';
    $date_to       = $_POST['date_to'] ?? '';
    $reason        = trim($_POST['reason'] ?? '');
    $days          = ($date_from && $date_to) ? working_days_inclusive($date_from, $date_to, (int)$emp['id'], $conn) : 0;

    if (!$leave_type_id || !$date_from || !$date_to) {
        $_SESSION['leave_err'] = 'Please fill all required fields for the leave request.';
    } else {
        // attachment (optional)
        $attach_path = null;
        if (!empty($_FILES['leave_attachment']['name'])) {
            $dir = __DIR__.'/../uploads/leave_attachments';
            if (!is_dir($dir)) @mkdir($dir,0775,true);
            if (!is_writable($dir)) {
                $_SESSION['leave_err'] = 'Upload folder not writable.';
            } else {
                $ext = pathinfo($_FILES['leave_attachment']['name'], PATHINFO_EXTENSION);
                $fname = 'att_'.time().'_'.mt_rand(1000,9999).'.'.$ext;
                if (move_uploaded_file($_FILES['leave_attachment']['tmp_name'], $dir.'/'.$fname)) {
                    $attach_path = 'uploads/leave_attachments/'.$fname;
                }
            }
        }

        if (empty($_SESSION['leave_err'])) {
            $uid = $_SESSION['user']['id'] ?? null;
            $ins = $conn->prepare("INSERT INTO leave_requests
                (employee_id, leave_type_id, date_from, date_to, days, reason, requester_id, attachment_path)
                VALUES (?,?,?,?,?,?,?,?)");
            $ins->execute([$emp['id'],$leave_type_id,$date_from,$date_to,$days,$reason,$uid,$attach_path]);
            $leaveRequestId = (int)$conn->lastInsertId();
            $_SESSION['leave_msg'] = 'Leave request submitted.';
            
            // Log activity
            $leaveTypeName = $conn->query("SELECT name FROM leave_types WHERE id = " . (int)$leave_type_id)->fetchColumn() ?: 'Unknown';
            logEmployeeActivity($conn, $emp['id'], 'leave_request', "Submitted leave request: {$leaveTypeName} from {$date_from} to {$date_to} ({$days} days)", null, null, null, $uid);
            audit_bridge_hr_ops(
                'leave_requested',
                'leave_requests',
                $leaveRequestId > 0 ? $leaveRequestId : (int)$emp['id'],
                'Submitted leave request for ' . ($emp['full_name'] ?: $emp['employee_code'])
                    . ' — ' . $leaveTypeName . ' (' . $date_from . ' → ' . $date_to . ', ' . $days . ' day(s))',
                isset($emp['company_id']) ? (int)$emp['company_id'] : null,
                [
                    'employee_id' => (int)$emp['id'],
                    'leave_type_id' => $leave_type_id,
                    'leave_type' => $leaveTypeName,
                    'date_from' => $date_from,
                    'date_to' => $date_to,
                    'days' => $days,
                    'reason' => $reason !== '' ? $reason : null,
                ],
                'Leave #' . ($leaveRequestId > 0 ? $leaveRequestId : '?'),
                $uid ? (int)$uid : null
            );
            
            // Send email notification to owners (non-blocking)
            require_once __DIR__ . '/../includes/mailer.php';
            $employeeName = htmlspecialchars($emp['full_name'] ?: $emp['employee_code']);
            $employeeCode = htmlspecialchars($emp['employee_code']);
            $subject = "New Leave Request - {$employeeName} ({$employeeCode})";
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
                            <a href='" . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://{$_SERVER['HTTP_HOST']}" . (get_base_path() ? get_base_path() : '') . "/hr/leave_requests.php?status=pending' style='background: #3498db; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>
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
            // Send email in background (non-blocking)
            send_notification_to_owners_async($conn, $subject, $html);
        }
    }
    // Redirect to prevent resubmission and preserve tab (PRG pattern)
    $redirectUrl = ($isWorkerSelfService ? 'employee_view.php' : 'employee_view.php?id=' . urlencode($emp['id'])) . '#tab-leave';
    header('Location: ' . $redirectUrl);
    exit;
}

// --- Overtime (read-only) ---
$otStmt = $conn->prepare("
  SELECT ot.*, r.name AS rule_name
  FROM overtime_entries ot
  LEFT JOIN overtime_rules r ON r.id = ot.rule_id
  WHERE ot.employee_id = ?
  ORDER BY ot.ot_date DESC, ot.id DESC
");
$otStmt->execute([$emp['id']]);
$otRows = $otStmt->fetchAll(PDO::FETCH_ASSOC);

// Small helper for a status badge
function ot_status_badge(string $st): string {
    $st = strtolower($st);
    $map = ['pending'=>'warning','approved'=>'success','rejected'=>'danger'];
    $cls = $map[$st] ?? 'secondary';
    return '<span class="badge text-bg-'.$cls.'">'.htmlspecialchars($st).'</span>';
}


// Approve/Reject from profile
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['leave_change_status'])) {
    csrf_verify();
    $id  = (int)$_POST['id'];
    $new = $_POST['new_status'];
    if (in_array($new,['approved','rejected','cancelled'],true)) {
        $conn->beginTransaction();
        try {
            // Get leave request with employee and leave type info for email
            $q = $conn->prepare("
                SELECT lr.*, e.email, e.full_name, e.employee_code, lt.name AS type_name
                FROM leave_requests lr
                JOIN employees e ON e.id = lr.employee_id
                JOIN leave_types lt ON lt.id = lr.leave_type_id
                WHERE lr.id=? AND lr.employee_id=? FOR UPDATE
            ");
            $q->execute([$id,$emp['id']]);
            $req = $q->fetch(PDO::FETCH_ASSOC);
            if (!$req) throw new Exception('Request not found');
            $u = $conn->prepare("UPDATE leave_requests SET status=?, approver_id=?, decided_at=NOW() WHERE id=?");
            $u->execute([$new, ($_SESSION['user']['id'] ?? null), $id]);

            if ($new==='approved') {
                $yr = (int)date('Y', strtotime($req['date_from']));
                ensure_balance($conn, $emp['id'], (int)$req['leave_type_id'], $yr);
                $b = $conn->prepare("UPDATE leave_balances
                                     SET taken=taken+?, closing=(opening+accrued+carried)-(taken+0)
                                     WHERE employee_id=? AND leave_type_id=? AND year=?");
                $b->execute([$req['days'], $emp['id'], (int)$req['leave_type_id'], $yr]);
            }
            $conn->commit();
            $_SESSION['leave_msg'] = "Request #{$id} {$new}.";

            $leaveAction = $new === 'approved'
                ? 'leave_approved'
                : ($new === 'rejected' ? 'leave_rejected' : 'leave_cancelled');
            audit_bridge_hr_ops(
                $leaveAction,
                'leave_requests',
                $id,
                ucfirst($new) . ' leave request #' . $id . ' for '
                    . ($req['full_name'] ?? $req['employee_code'])
                    . ' — ' . ($req['type_name'] ?? 'Leave')
                    . ' (' . ($req['date_from'] ?? '') . ' → ' . ($req['date_to'] ?? '') . ')',
                isset($emp['company_id']) ? (int)$emp['company_id'] : null,
                [
                    'status' => $new,
                    'employee_id' => (int)$emp['id'],
                    'leave_type' => $req['type_name'] ?? null,
                    'date_from' => $req['date_from'] ?? null,
                    'date_to' => $req['date_to'] ?? null,
                    'days' => $req['days'] ?? null,
                ],
                'Leave #' . $id,
                isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
            );
            
            // Send email notification to employee (only for approved/rejected, not cancelled)
            if (in_array($new, ['approved', 'rejected']) && !empty($req['email'])) {
                require_once __DIR__ . '/../includes/mailer.php';
                $employeeName = htmlspecialchars($req['full_name'] ?: $req['employee_code']);
                $employeeCode = htmlspecialchars($req['employee_code']);
                $leaveTypeName = htmlspecialchars($req['type_name']);
                $statusText = ucfirst($new);
                $statusColor = $new === 'approved' ? '#27ae60' : '#e74c3c';
                
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
                                <a href='" . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://{$_SERVER['HTTP_HOST']}" . (get_base_path() ? get_base_path() : '') . "/hr/employee_view.php" . ($isWorkerSelfService ? '' : '?id=' . urlencode($emp['id'])) . "#tab-leave' style='background: {$statusColor}; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>
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
        } catch(Exception $ex) {
            $conn->rollBack();
            $_SESSION['leave_err'] = $ex->getMessage();
        }
    }
    // Redirect to prevent resubmission and preserve tab (PRG pattern)
    $redirectUrl = ($isWorkerSelfService ? 'employee_view.php' : 'employee_view.php?id=' . urlencode($emp['id'])) . '#tab-leave';
    header('Location: ' . $redirectUrl);
    exit;
}

/* ------------------------------------
   Fetch data for tabs
------------------------------------- */
// Documents
$docs = $conn->prepare("SELECT * FROM employee_documents WHERE employee_id=? ORDER BY
                        CASE doc_type WHEN 'Passport' THEN 1 WHEN 'Visa' THEN 2 WHEN 'LaborCard' THEN 3 ELSE 9 END,
                        expires_at ASC, id DESC");
$docs->execute([$emp['id']]);
$docs = $docs->fetchAll(PDO::FETCH_ASSOC);

// Next document expiry (for overview)
$nx = $conn->prepare("SELECT MIN(expires_at) AS next_exp
                      FROM employee_documents
                      WHERE employee_id=? AND expires_at IS NOT NULL AND expires_at!='0000-00-00'");
$nx->execute([$emp['id']]);
$next_expiry = $nx->fetchColumn();

// Leave tab data
$leave_types = $conn->query("SELECT id,name FROM leave_types ORDER BY name")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);

if ($leave_types) {
    foreach (array_keys($leave_types) as $typeId) {
        ensure_balance($conn, (int)$emp['id'], (int)$typeId, (int)$year);
    }
}

$balStmt = $conn->prepare("
  SELECT lb.*, lt.name AS type_name
  FROM leave_balances lb
  JOIN leave_types lt ON lt.id=lb.leave_type_id
  WHERE lb.employee_id=? AND lb.year=?
  ORDER BY lt.name
");
$balStmt->execute([$emp['id'], $year]);
$balances = $balStmt->fetchAll(PDO::FETCH_ASSOC);
$totalLeaveBalance = 0.0;
foreach ($balances as $balanceRow) {
    $totalLeaveBalance += (float)($balanceRow['closing'] ?? 0);
}

$reqStmt = $conn->prepare("
  SELECT lr.*, lt.name AS type_name
  FROM leave_requests lr
  JOIN leave_types lt ON lt.id=lr.leave_type_id
  WHERE lr.employee_id=?
  ORDER BY lr.created_at DESC
  LIMIT 20
");
$reqStmt->execute([$emp['id']]);
$leave_rows = $reqStmt->fetchAll(PDO::FETCH_ASSOC);

/* ------------------------------------
   Helpers for UI
------------------------------------- */
function badge_status($expires_at) {
    if (!$expires_at || $expires_at === '0000-00-00') return '<span class="badge bg-secondary">—</span>';
    $d = new DateTime($expires_at);
    $today = new DateTime('today');
    $diff = (int)$today->diff($d)->format('%r%a');
    if ($diff < 0) return '<span class="badge bg-danger">Expired '.abs($diff).'d</span>';
    if ($diff <= 30) return '<span class="badge bg-warning text-dark">In '.$diff.'d</span>';
    return '<span class="badge bg-success">Valid</span>';
}

/* ---------------- Update Contact Info (Email & Phone) ---------------- */

// Initialize messages from session (for PRG pattern)
$contact_msg = $_SESSION['contact_info_msg'] ?? '';
$contact_err = $_SESSION['contact_info_err'] ?? '';
unset($_SESSION['contact_info_msg'], $_SESSION['contact_info_err']);

// Update email and phone (self-service only - employees can only update their own)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_contact_info'])) {
    csrf_verify();
    
    // Only allow employees to update their own contact info
    if ($isWorkerSelfService) {
        $newEmail = trim($_POST['email'] ?? '');
        $newPhone = trim($_POST['phone'] ?? '');
        
        // Basic validation
        if (!empty($newEmail) && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['contact_info_err'] = 'Invalid email address format.';
        } else {
            try {
                $conn->beginTransaction();
                $updateStmt = $conn->prepare("UPDATE employees SET email = ?, phone = ? WHERE id = ?");
                $updateStmt->execute([$newEmail ?: null, $newPhone ?: null, $emp['id']]);
                
                // Also update workers table if exists
                if (!empty($emp['employee_code'])) {
                    $workerUpdateStmt = $conn->prepare("UPDATE workers SET email = ?, mobile_num = ? WHERE emp_num = ?");
                    $workerUpdateStmt->execute([$newEmail ?: null, $newPhone ?: null, $emp['employee_code']]);
                }
                
                $conn->commit();
                $_SESSION['contact_info_msg'] = 'Contact information updated successfully.';
                
                // Log activity
                logEmployeeActivity($conn, $emp['id'], 'contact_info_updated', "Updated email and phone number");
                $empLabel = trim(($emp['full_name'] ?? '') . ' (' . ($emp['employee_code'] ?? '') . ')');
                audit_bridge_hr_ops(
                    'contact_updated',
                    'employees',
                    (int)$emp['id'],
                    'Updated contact info for ' . $empLabel,
                    isset($emp['company_id']) ? (int)$emp['company_id'] : null,
                    [
                        'email' => $newEmail !== '' ? $newEmail : null,
                        'phone' => $newPhone !== '' ? $newPhone : null,
                    ],
                    $empLabel,
                    isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
                );
                
                // Refresh employee data
                $stmt = $conn->prepare("SELECT e.*, d.name AS dept_name, l.name AS loc_name
                                        FROM employees e
                                        LEFT JOIN departments d ON d.id=e.department_id
                                        LEFT JOIN locations   l ON l.id=e.location_id
                                        WHERE e.id=?");
                $stmt->execute([$emp['id']]);
                $emp = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $conn->rollBack();
                $_SESSION['contact_info_err'] = 'Failed to update contact information: ' . $e->getMessage();
            }
        }
    } else {
        $_SESSION['contact_info_err'] = 'Access denied.';
    }
    
    // Redirect to prevent resubmission (PRG pattern)
    $redirectUrl = ($isWorkerSelfService ? 'employee_view.php' : 'employee_view.php?id=' . urlencode($emp['id'])) . '#tab-overview';
    header('Location: ' . $redirectUrl);
    exit;
}

/* ---------------- Cash Advance + Deductions (actions + data) ---------------- */

// Initialize messages from session (for PRG pattern)
$cash_msg = $_SESSION['cash_advance_msg'] ?? '';
$cash_err = $_SESSION['cash_advance_err'] ?? '';
$ded_msg = $_SESSION['deduction_msg'] ?? '';
unset($_SESSION['cash_advance_msg'], $_SESSION['cash_advance_err'], $_SESSION['deduction_msg']);

// Fetch cash advance policy
$cashAdvancePolicy = null;
try {
    $policyStmt = $conn->prepare("SELECT * FROM cash_advance_policy WHERE id = 1 AND is_active = 1");
    $policyStmt->execute();
    $cashAdvancePolicy = $policyStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {
    $cashAdvancePolicy = null;
}

// Fetch employee-specific limit if exists
$employeeMaxLimit = null;
if ($cashAdvancePolicy) {
    try {
        $limitStmt = $conn->prepare("SELECT max_advance_amount FROM cash_advance_employee_limits WHERE employee_id = ?");
        $limitStmt->execute([$emp['id']]);
        $employeeLimit = $limitStmt->fetch(PDO::FETCH_ASSOC);
        if ($employeeLimit) {
            $employeeMaxLimit = (float)$employeeLimit['max_advance_amount'];
        }
    } catch (Exception $e) {
        // Ignore
    }
}

// Helper function to get current outstanding balance (issued - applied)
function getCurrentOutstandingBalance(PDO $conn, int $employeeId): float {
    if (function_exists('hr_loans_schema_ready') && hr_loans_schema_ready($conn)) {
        return hr_loan_employee_outstanding($conn, $employeeId);
    }
    // Get total issued (approved/open advances, excluding void)
    $issuedStmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) 
        FROM cash_advances 
        WHERE employee_id = ? AND status <> 'void' 
        AND (request_status IS NULL OR request_status = 'approved')
    ");
    $issuedStmt->execute([$employeeId]);
    $issued = (float)$issuedStmt->fetchColumn();
    
    // Get total applied (from payroll)
    $appliedStmt = $conn->prepare("
        SELECT COALESCE(SUM(pi.adv_applied), 0)
        FROM payroll_items pi 
        JOIN payroll_runs pr ON pr.id = pi.payroll_run_id
        WHERE pi.employee_id = ? AND pr.status IN ('open', 'posted', 'finalized', 'paid')
    ");
    $appliedStmt->execute([$employeeId]);
    $applied = (float)$appliedStmt->fetchColumn();
    
    return max(0, $issued - $applied);
}

// Helper function to check cash advance eligibility
function checkCashAdvanceEligibility(PDO $conn, array $emp, $cashAdvancePolicy, $employeeMaxLimit, float $requestedAmount = 0): array {
    $result = ['eligible' => true, 'reasons' => [], 'remaining_limit' => null];
    
    if (!$cashAdvancePolicy) {
        return $result; // No policy = eligible
    }
    
    // Check minimum service months
    if (!empty($emp['date_joined'])) {
        $joinDate = new DateTime($emp['date_joined']);
        $now = new DateTime();
        $monthsOfService = $joinDate->diff($now)->m + ($joinDate->diff($now)->y * 12);
        
        if ($monthsOfService < (int)$cashAdvancePolicy['min_service_months']) {
            $result['eligible'] = false;
            $result['reasons'][] = "Minimum service period of {$cashAdvancePolicy['min_service_months']} months required. You have {$monthsOfService} months.";
        }
    }
    
    // Check pending advances count
    $pendingCountStmt = $conn->prepare("SELECT COUNT(*) FROM cash_advances WHERE employee_id = ? AND request_status = 'pending'");
    $pendingCountStmt->execute([$emp['id']]);
    $pendingCount = (int)$pendingCountStmt->fetchColumn();
    
    if ($pendingCount >= (int)$cashAdvancePolicy['max_pending_advances']) {
        $result['eligible'] = false;
        $result['reasons'][] = "Maximum of {$cashAdvancePolicy['max_pending_advances']} pending advance(s) allowed. You have {$pendingCount} pending.";
    }
    
    // Check maximum total advance amount limit
    if (!empty($cashAdvancePolicy['max_total_advance_amount'])) {
        $maxTotal = (float)$cashAdvancePolicy['max_total_advance_amount'];
        $currentOutstanding = getCurrentOutstandingBalance($conn, (int)$emp['id']);
        $remainingLimit = max(0, $maxTotal - $currentOutstanding);
        $result['remaining_limit'] = $remainingLimit;
        
        if ($currentOutstanding >= $maxTotal) {
            $result['eligible'] = false;
            $result['reasons'][] = "You have reached the maximum total advance limit of " . number_format($maxTotal, 2) . " AED. Current outstanding: " . number_format($currentOutstanding, 2) . " AED.";
        } elseif ($requestedAmount > 0 && ($currentOutstanding + $requestedAmount) > $maxTotal) {
            $result['eligible'] = false;
            $result['reasons'][] = "This request would exceed the maximum total advance limit. Maximum allowed: " . number_format($maxTotal, 2) . " AED. Current outstanding: " . number_format($currentOutstanding, 2) . " AED. Remaining limit: " . number_format($remainingLimit, 2) . " AED.";
        }
    }
    
    return $result;
}

// Helper function to get maximum allowed advance amount
function getMaxAdvanceAmount(PDO $conn, array $emp, $cashAdvancePolicy, $employeeMaxLimit): ?float {
    if (!$cashAdvancePolicy) {
        return null; // No limit
    }
    
    // Employee-specific limit takes precedence
    if ($employeeMaxLimit !== null) {
        return $employeeMaxLimit;
    }
    
    // Global amount limit
    if (!empty($cashAdvancePolicy['max_advance_amount_global'])) {
        return (float)$cashAdvancePolicy['max_advance_amount_global'];
    }
    
    // Percentage of salary (use allowance as monthly salary)
    if (!empty($cashAdvancePolicy['max_advance_percentage_salary']) && !empty($emp['allowance'])) {
        $percentage = (float)$cashAdvancePolicy['max_advance_percentage_salary'];
        $allowance = (float)$emp['allowance'];
        return ($allowance * $percentage) / 100;
    }
    
    return null; // No limit
}

// ADD cash advance / issue loan (Owner, Admin, HR — matches cash_advances.php)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_cash_adv'])) {
    csrf_verify();
    if (!$canManageLoans) {
        http_response_code(403);
        die('Access denied. Only Owner, Admin, or HR can issue loans / advances.');
    }
  $tx_date = $_POST['tx_date'] ?? date('Y-m-d');
  $amount  = (float)($_POST['amount'] ?? 0);
  $desc    = trim($_POST['description'] ?? '');
  $status  = $_POST['status'] ?? 'open'; // enum: open, settled, void
    
    // Policy max: Owner may override; Admin/HR are blocked above the limit when a policy max exists.
    if ($cashAdvancePolicy && $amount > 0) {
        $maxAmount = getMaxAdvanceAmount($conn, $emp, $cashAdvancePolicy, $employeeMaxLimit);
        if ($maxAmount !== null && $amount > $maxAmount && !$isOwner) {
            $cash_err = 'Amount exceeds the policy maximum of ' . number_format($maxAmount, 2) . ' AED.';
            $amount = 0;
        }
    }
    
  if ($amount > 0) {
    $stmt = $conn->prepare("
      INSERT INTO cash_advances (employee_id, tx_date, amount, description, status, created_by)
      VALUES (?,?,?,?,?,?)
    ");
    $stmt->execute([$emp['id'], $tx_date, $amount, $desc ?: null, $status, (int)($_SESSION['user']['id'] ?? 0)]);
    $newLoanId = (int)$conn->lastInsertId();
    if ($status === 'open') {
      hr_loan_initialize_schedule($conn, $newLoanId, $amount, max(1, (int)($_POST['installment_count'] ?? 1)), $tx_date);
    }
    $cash_msg = 'Loan / salary advance added.';
    audit_bridge_hr_ops(
      'loan_issued',
      'cash_advances',
      $newLoanId > 0 ? $newLoanId : (int)$emp['id'],
      'Issued loan / advance #' . ($newLoanId > 0 ? $newLoanId : '?')
        . ' for ' . ($emp['full_name'] ?? $emp['employee_code'])
        . ' — AED ' . number_format($amount, 2),
      isset($emp['company_id']) ? (int)$emp['company_id'] : null,
      [
        'employee_id' => (int)$emp['id'],
        'amount' => $amount,
        'tx_date' => $tx_date,
        'status' => $status,
        'description' => $desc !== '' ? $desc : null,
      ],
      'Loan #' . ($newLoanId > 0 ? $newLoanId : '?'),
      isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
    );
  }
}

// DEL cash advance (Owner only)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del_cash_adv'])) {
    csrf_verify();
    if (!$isOwner) {
        http_response_code(403);
        die('Access denied. Only Owner can delete cash advances.');
    }
  $delLoanId = (int)$_POST['del_cash_adv'];
  $prevLoan = $conn->prepare("SELECT * FROM cash_advances WHERE id=? AND employee_id=? LIMIT 1");
  $prevLoan->execute([$delLoanId, $emp['id']]);
  $prevLoanRow = $prevLoan->fetch(PDO::FETCH_ASSOC);
  $stmt = $conn->prepare("DELETE FROM cash_advances WHERE id=? AND employee_id=?");
  $stmt->execute([$delLoanId, $emp['id']]);
  $cash_msg = 'Cash advance removed.';
  if ($prevLoanRow) {
    audit_bridge_hr_ops(
      'loan_deleted',
      'cash_advances',
      $delLoanId,
      'Deleted loan / advance #' . $delLoanId
        . ' for ' . ($emp['full_name'] ?? $emp['employee_code'])
        . ' — AED ' . number_format((float)($prevLoanRow['amount'] ?? 0), 2),
      isset($emp['company_id']) ? (int)$emp['company_id'] : null,
      [
        'employee_id' => (int)$emp['id'],
        'amount' => $prevLoanRow['amount'] ?? null,
        'status' => $prevLoanRow['status'] ?? null,
        'tx_date' => $prevLoanRow['tx_date'] ?? null,
      ],
      'Loan #' . $delLoanId,
      isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
    );
  }
}

// Employee request for cash advance
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['cash_advance_request'])) {
    csrf_verify();
    $requested_amount = (float)($_POST['requested_amount'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $tx_date = $_POST['tx_date'] ?? date('Y-m-d');
    
    // Validate eligibility and limits
    if ($cashAdvancePolicy) {
        $eligibility = checkCashAdvanceEligibility($conn, $emp, $cashAdvancePolicy, $employeeMaxLimit, $requested_amount);
        if (!$eligibility['eligible']) {
            $_SESSION['cash_advance_err'] = 'You are not eligible for a cash advance: ' . implode(' ', $eligibility['reasons']);
            $redirectUrl = ($isWorkerSelfService ? 'employee_view.php' : 'employee_view.php?id=' . urlencode($emp['id'])) . '#tab-cashadv';
            header('Location: ' . $redirectUrl);
            exit;
        }
        
        // Check per-request limit
        $maxAmount = getMaxAdvanceAmount($conn, $emp, $cashAdvancePolicy, $employeeMaxLimit);
        if ($maxAmount !== null && $requested_amount > $maxAmount) {
            $allowanceInfo = !empty($emp['allowance']) ? " (" . number_format((float)$cashAdvancePolicy['max_advance_percentage_salary'], 2) . "% of " . number_format((float)$emp['allowance'], 2) . " AED monthly allowance)" : "";
            $_SESSION['cash_advance_err'] = "Maximum advance amount allowed per request is " . number_format($maxAmount, 2) . " AED{$allowanceInfo}. You requested " . number_format($requested_amount, 2) . " AED.";
            $redirectUrl = ($isWorkerSelfService ? 'employee_view.php' : 'employee_view.php?id=' . urlencode($emp['id'])) . '#tab-cashadv';
            header('Location: ' . $redirectUrl);
            exit;
        }
        
        // Check total advance limit (remaining limit)
        if ($eligibility['remaining_limit'] !== null && $requested_amount > $eligibility['remaining_limit']) {
            $_SESSION['cash_advance_err'] = "Remaining total advance limit is " . number_format($eligibility['remaining_limit'], 2) . " AED. You requested " . number_format($requested_amount, 2) . " AED.";
            $redirectUrl = ($isWorkerSelfService ? 'employee_view.php' : 'employee_view.php?id=' . urlencode($emp['id'])) . '#tab-cashadv';
            header('Location: ' . $redirectUrl);
            exit;
        }
    }
    
    if ($requested_amount > 0) {
        $stmt = $conn->prepare("
            INSERT INTO cash_advances (employee_id, tx_date, amount, requested_amount, description, status, request_status, requested_by, created_by)
            VALUES (?,?,?,?,?,'open','pending',?,?)
        ");
        $stmt->execute([
            $emp['id'], 
            $tx_date, 
            $requested_amount, // amount will be updated when approved
            $requested_amount, 
            $reason ?: null, 
            (int)($_SESSION['user']['id'] ?? 0),
            (int)($_SESSION['user']['id'] ?? 0)
        ]);
        $reqLoanId = (int)$conn->lastInsertId();
        $_SESSION['cash_advance_msg'] = 'Cash advance request submitted successfully.';
        
        // Log activity
        logEmployeeActivity($conn, $emp['id'], 'cash_advance_request', "Requested cash advance of " . number_format($requested_amount, 2) . " AED. Reason: " . ($reason ?: 'N/A'));
        audit_bridge_hr_ops(
            'loan_requested',
            'cash_advances',
            $reqLoanId > 0 ? $reqLoanId : (int)$emp['id'],
            'Requested loan / advance for ' . ($emp['full_name'] ?? $emp['employee_code'])
                . ' — AED ' . number_format($requested_amount, 2),
            isset($emp['company_id']) ? (int)$emp['company_id'] : null,
            [
                'employee_id' => (int)$emp['id'],
                'requested_amount' => $requested_amount,
                'tx_date' => $tx_date,
                'reason' => $reason !== '' ? $reason : null,
            ],
            'Loan #' . ($reqLoanId > 0 ? $reqLoanId : '?'),
            isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
        );
        
        // Send email notification to owners (non-blocking)
        require_once __DIR__ . '/../includes/mailer.php';
        $employeeName = htmlspecialchars($emp['full_name'] ?: $emp['employee_code']);
        $employeeCode = htmlspecialchars($emp['employee_code']);
        $subject = "New Cash Advance Request - {$employeeName} ({$employeeCode})";
        $html = "
            <html>
            <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                    <h2 style='color: #2c3e50; border-bottom: 2px solid #f39c12; padding-bottom: 10px;'>New Cash Advance Request</h2>
                    <p>A new cash advance request has been submitted and requires your approval.</p>
                    <div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                        <p><strong>Employee:</strong> {$employeeName} ({$employeeCode})</p>
                        <p><strong>Requested Amount:</strong> " . number_format($requested_amount, 2) . " AED</p>
                        <p><strong>Request Date:</strong> " . date('M d, Y', strtotime($tx_date)) . "</p>
                        " . (!empty($reason) ? "<p><strong>Reason:</strong> " . htmlspecialchars($reason) . "</p>" : "") . "
                    </div>
                    <p style='margin-top: 20px;'>
                        <a href='" . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://{$_SERVER['HTTP_HOST']}" . (get_base_path() ? get_base_path() : '') . "/hr/cash_advances.php?request_status=pending' style='background: #f39c12; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>
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
        // Send email in background (non-blocking)
        send_notification_to_owners_async($conn, $subject, $html);
    } else {
        $_SESSION['cash_advance_msg'] = 'Please enter a valid amount.';
    }
    // Redirect to prevent resubmission and preserve tab (PRG pattern)
    $redirectUrl = ($isWorkerSelfService ? 'employee_view.php' : 'employee_view.php?id=' . urlencode($emp['id'])) . '#tab-cashadv';
    header('Location: ' . $redirectUrl);
    exit;
}

// Approve cash advance request (Owner only)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['approve_cash_adv'])) {
    csrf_verify();
    if (!$isOwner) {
        http_response_code(403);
        die('Access denied. Only Owner can approve cash advance requests.');
    }
    $id = (int)($_POST['approve_cash_adv'] ?? 0);
    $approved_amount = (float)($_POST['approved_amount'] ?? 0);
    
    if ($id > 0 && $approved_amount > 0) {
        // Get cash advance details and employee email before updating
        $getStmt = $conn->prepare("
            SELECT ca.*, e.email, e.full_name, e.employee_code
            FROM cash_advances ca
            JOIN employees e ON e.id = ca.employee_id
            WHERE ca.id = ? AND ca.employee_id = ? AND ca.request_status = 'pending'
        ");
        $getStmt->execute([$id, $emp['id']]);
        $cashAdvance = $getStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($cashAdvance) {
            $stmt = $conn->prepare("
                UPDATE cash_advances 
                SET request_status = 'approved',
                    amount = ?,
                    approved_amount = ?,
                    approved_by = ?,
                    approved_at = NOW(),
                    status = 'open'
                WHERE id = ? AND employee_id = ? AND request_status = 'pending'
            ");
            $stmt->execute([
                $approved_amount,
                $approved_amount,
                (int)($_SESSION['user']['id'] ?? 0),
                $id,
                $emp['id']
            ]);
            hr_loan_initialize_schedule($conn, (int)$id, $approved_amount, max(1, (int)($_POST['installment_count'] ?? 1)), $cashAdvance['tx_date'] ?? null);
            $_SESSION['cash_advance_msg'] = 'Loan / salary advance approved.';

            try {
                require_once __DIR__ . '/../includes/AuditService.php';
                AuditService::logEvent([
                    'action' => 'loan_issued',
                    'module' => 'hr',
                    'company_id' => isset($emp['company_id']) ? (int)$emp['company_id'] : current_company_id($conn),
                    'object_type' => 'cash_advances',
                    'object_id' => (string)$id,
                    'object_ref' => 'Loan #' . $id,
                    'summary' => 'Issued loan / advance #' . $id . ' for ' . ($emp['full_name'] ?? ('employee #' . $emp['id'])) . ' — AED ' . number_format($approved_amount, 2),
                    'new_data' => ['amount' => $approved_amount],
                    'source' => 'user',
                    'success' => true,
                ]);
            } catch (Throwable $ignored) {}
            
            // Log activity
            logEmployeeActivity($conn, $emp['id'], 'cash_advance_approved', "Cash advance approved: " . number_format($approved_amount, 2) . " AED", 'amount', null, (string)$approved_amount);
            
            // Send email notification to employee
            if (!empty($cashAdvance['email'])) {
                require_once __DIR__ . '/../includes/mailer.php';
                $employeeName = htmlspecialchars($cashAdvance['full_name'] ?: $cashAdvance['employee_code']);
                $employeeCode = htmlspecialchars($cashAdvance['employee_code']);
                $requestedAmount = number_format((float)($cashAdvance['requested_amount'] ?? 0), 2);
                $approvedAmountFormatted = number_format($approved_amount, 2);
                
                $subject = "Cash Advance Request Approved - {$employeeName} ({$employeeCode})";
                $html = "
                    <html>
                    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                            <h2 style='color: #2c3e50; border-bottom: 2px solid #27ae60; padding-bottom: 10px;'>
                                Cash Advance Request Approved
                            </h2>
                            <p>Dear {$employeeName},</p>
                            <p>Your cash advance request has been <strong style='color: #27ae60;'>approved</strong>.</p>
                            <div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #27ae60;'>
                                <p><strong>Employee:</strong> {$employeeName} ({$employeeCode})</p>
                                <p><strong>Requested Amount:</strong> {$requestedAmount} AED</p>
                                <p><strong>Approved Amount:</strong> {$approvedAmountFormatted} AED</p>
                                <p><strong>Transaction Date:</strong> " . date('M d, Y', strtotime($cashAdvance['tx_date'])) . "</p>
                                " . (!empty($cashAdvance['description']) ? "<p><strong>Description:</strong> " . htmlspecialchars($cashAdvance['description']) . "</p>" : "") . "
                            </div>
                            <p style='margin-top: 20px;'>
                                <a href='" . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://{$_SERVER['HTTP_HOST']}" . (get_base_path() ? get_base_path() : '') . "/hr/employee_view.php" . ($isWorkerSelfService ? '' : '?id=' . urlencode($emp['id'])) . "#tab-cashadv' style='background: #27ae60; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                                    View Details
                                </a>
                            </p>
                            <p style='color: #7f8c8d; font-size: 12px; margin-top: 30px;'>
                                This is an automated notification from the HR Management System.
                            </p>
                        </div>
                    </body>
                    </html>
                ";
                send_notification_to_employee_async($conn, $cashAdvance['email'], $subject, $html);
            }
        }
    }
    // Redirect to prevent resubmission and preserve tab
    $redirectUrl = 'employee_view.php?id=' . urlencode($employee_id) . '#tab-cashadv';
    header('Location: ' . $redirectUrl);
    exit;
}

// Reject cash advance request (Owner only)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['reject_cash_adv'])) {
    csrf_verify();
    if (!$isOwner) {
        http_response_code(403);
        die('Access denied. Only Owner can reject cash advance requests.');
    }
    $id = (int)($_POST['reject_cash_adv'] ?? 0);
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');
    
    if ($id > 0) {
        // Get cash advance details and employee email before updating
        $getStmt = $conn->prepare("
            SELECT ca.*, e.email, e.full_name, e.employee_code
            FROM cash_advances ca
            JOIN employees e ON e.id = ca.employee_id
            WHERE ca.id = ? AND ca.employee_id = ? AND ca.request_status = 'pending'
        ");
        $getStmt->execute([$id, $emp['id']]);
        $cashAdvance = $getStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($cashAdvance) {
            $stmt = $conn->prepare("
                UPDATE cash_advances 
                SET request_status = 'rejected',
                    approved_by = ?,
                    approved_at = NOW(),
                    rejection_reason = ?,
                    status = 'void'
                WHERE id = ? AND employee_id = ? AND request_status = 'pending'
            ");
            $stmt->execute([
                (int)($_SESSION['user']['id'] ?? 0),
                $rejection_reason ?: null,
                $id,
                $emp['id']
            ]);
            $_SESSION['cash_advance_msg'] = 'Cash advance request rejected.';
            
            // Log activity
            logEmployeeActivity($conn, $emp['id'], 'cash_advance_rejected', "Cash advance rejected. Reason: " . ($rejection_reason ?: 'Not specified'));
            audit_bridge_hr_ops(
                'loan_rejected',
                'cash_advances',
                $id,
                'Rejected loan / advance #' . $id . ' for ' . ($emp['full_name'] ?? $emp['employee_code'])
                    . ' — AED ' . number_format((float)($cashAdvance['requested_amount'] ?? 0), 2),
                isset($emp['company_id']) ? (int)$emp['company_id'] : null,
                [
                    'employee_id' => (int)$emp['id'],
                    'requested_amount' => $cashAdvance['requested_amount'] ?? null,
                    'rejection_reason' => $rejection_reason !== '' ? $rejection_reason : null,
                ],
                'Loan #' . $id,
                isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
            );
            
            // Send email notification to employee
            if (!empty($cashAdvance['email'])) {
                require_once __DIR__ . '/../includes/mailer.php';
                $employeeName = htmlspecialchars($cashAdvance['full_name'] ?: $cashAdvance['employee_code']);
                $employeeCode = htmlspecialchars($cashAdvance['employee_code']);
                $requestedAmount = number_format((float)($cashAdvance['requested_amount'] ?? 0), 2);
                
                $subject = "Cash Advance Request Rejected - {$employeeName} ({$employeeCode})";
                $html = "
                    <html>
                    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                            <h2 style='color: #2c3e50; border-bottom: 2px solid #e74c3c; padding-bottom: 10px;'>
                                Cash Advance Request Rejected
                            </h2>
                            <p>Dear {$employeeName},</p>
                            <p>Your cash advance request has been <strong style='color: #e74c3c;'>rejected</strong>.</p>
                            <div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #e74c3c;'>
                                <p><strong>Employee:</strong> {$employeeName} ({$employeeCode})</p>
                                <p><strong>Requested Amount:</strong> {$requestedAmount} AED</p>
                                <p><strong>Transaction Date:</strong> " . date('M d, Y', strtotime($cashAdvance['tx_date'])) . "</p>
                                " . (!empty($cashAdvance['description']) ? "<p><strong>Description:</strong> " . htmlspecialchars($cashAdvance['description']) . "</p>" : "") . "
                            </div>
                            " . (!empty($rejection_reason) ? 
                                "<div style='background: #fee; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #e74c3c;'>
                                    <p><strong>Rejection Reason:</strong> " . htmlspecialchars($rejection_reason) . "</p>
                                </div>" : "") . "
                            <p style='margin-top: 20px;'>
                                <a href='" . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://{$_SERVER['HTTP_HOST']}" . (get_base_path() ? get_base_path() : '') . "/hr/employee_view.php" . ($isWorkerSelfService ? '' : '?id=' . urlencode($emp['id'])) . "#tab-cashadv' style='background: #e74c3c; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                                    View Details
                                </a>
                            </p>
                            <p style='color: #7f8c8d; font-size: 12px; margin-top: 30px;'>
                                This is an automated notification from the HR Management System.
                            </p>
                        </div>
                    </body>
                    </html>
                ";
                send_notification_to_employee_async($conn, $cashAdvance['email'], $subject, $html);
            }
        }
    }
    // Redirect to prevent resubmission and preserve tab
    $redirectUrl = 'employee_view.php?id=' . urlencode($employee_id) . '#tab-cashadv';
    header('Location: ' . $redirectUrl);
    exit;
}

// ADD other deduction
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_deduction'])) {
    csrf_verify();
  $tx_date = $_POST['tx_date'] ?? date('Y-m-d');
  $amount  = (float)($_POST['amount'] ?? 0);
  $dtype   = $_POST['dtype'] ?? 'other'; // enum: fine, charge_duty, uniform, other
  $notes   = trim($_POST['notes'] ?? '');
  if ($amount > 0) {
    $hasRem = false;
    try {
      $chk = $conn->query("SHOW COLUMNS FROM employee_deductions LIKE 'remaining_balance'");
      $hasRem = $chk && $chk->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
      $hasRem = false;
    }
    if ($hasRem) {
      $stmt = $conn->prepare("
        INSERT INTO employee_deductions (employee_id, tx_date, amount, remaining_balance, preferred_settle_method, settle_status, dtype, notes, created_by)
        VALUES (?,?,?,?, 'payroll', 'open', ?,?,?)
      ");
      $stmt->execute([$emp['id'], $tx_date, $amount, $amount, $dtype, $notes ?: null, (int)($_SESSION['user']['id'] ?? 0)]);
    } else {
      $stmt = $conn->prepare("
        INSERT INTO employee_deductions (employee_id, tx_date, amount, dtype, notes, created_by)
        VALUES (?,?,?,?,?,?)
      ");
      $stmt->execute([$emp['id'],$tx_date,$amount,$dtype,$notes ?: null,(int)($_SESSION['user']['id'] ?? 0)]);
    }
    $dedId = (int)$conn->lastInsertId();
    $ded_msg = 'Deduction added.';
    audit_bridge_hr_ops(
      'deduction_created',
      'employee_deductions',
      $dedId > 0 ? $dedId : (int)$emp['id'],
      'Created ' . $dtype . ' deduction for ' . ($emp['full_name'] ?? $emp['employee_code'])
        . ' — AED ' . number_format($amount, 2) . ' on ' . $tx_date,
      isset($emp['company_id']) ? (int)$emp['company_id'] : null,
      [
        'employee_id' => (int)$emp['id'],
        'tx_date' => $tx_date,
        'amount' => $amount,
        'dtype' => $dtype,
        'notes' => $notes !== '' ? $notes : null,
      ],
      'Deduction #' . ($dedId > 0 ? $dedId : '?'),
      isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
    );
  }
}

// DEL deduction
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del_deduction'])) {
    csrf_verify();
  $delDedId = (int)$_POST['del_deduction'];
  $prevDed = $conn->prepare("SELECT * FROM employee_deductions WHERE id=? AND employee_id=? LIMIT 1");
  $prevDed->execute([$delDedId, $emp['id']]);
  $prevDedRow = $prevDed->fetch(PDO::FETCH_ASSOC);
  $stmt = $conn->prepare("DELETE FROM employee_deductions WHERE id=? AND employee_id=?");
  $stmt->execute([$delDedId, $emp['id']]);
  $ded_msg = 'Deduction removed.';
  if ($prevDedRow) {
    audit_bridge_hr_ops(
      'deduction_deleted',
      'employee_deductions',
      $delDedId,
      'Deleted ' . ($prevDedRow['dtype'] ?? 'deduction') . ' for ' . ($emp['full_name'] ?? $emp['employee_code'])
        . ' — AED ' . number_format((float)($prevDedRow['amount'] ?? 0), 2),
      isset($emp['company_id']) ? (int)$emp['company_id'] : null,
      [
        'employee_id' => (int)$emp['id'],
        'tx_date' => $prevDedRow['tx_date'] ?? null,
        'amount' => $prevDedRow['amount'] ?? null,
        'dtype' => $prevDedRow['dtype'] ?? null,
      ],
      'Deduction #' . $delDedId,
      isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
    );
  }
}

// Lists
$cashRows = $conn->prepare("
  SELECT *
  FROM cash_advances
  WHERE employee_id=?
  ORDER BY tx_date DESC, id DESC
");
$cashRows->execute([$emp['id']]);
$cashRows = $cashRows->fetchAll(PDO::FETCH_ASSOC);

$loanHistoryRows = hr_loan_history_rows($conn, (int)$emp['id']);

$cashAppliedRows = $conn->prepare("
  SELECT pr.id AS run_id,
         pr.period_from,
         pr.period_to,
         pr.status,
         ROUND(pi.adv_applied,2) AS adv_applied
  FROM payroll_runs pr
  JOIN payroll_items pi ON pi.payroll_run_id = pr.id
  WHERE pi.employee_id = ? AND pi.adv_applied > 0
  ORDER BY pr.period_from DESC, pr.id DESC
");
$cashAppliedRows->execute([$emp['id']]);
$cashAppliedRows = $cashAppliedRows->fetchAll(PDO::FETCH_ASSOC);

$dedRowsSql = "SELECT * FROM employee_deductions WHERE employee_id=? ORDER BY tx_date DESC, id DESC";
$dedRows = $conn->prepare($dedRowsSql);
$dedRows->execute([$emp['id']]);
$dedRows = $dedRows->fetchAll(PDO::FETCH_ASSOC);

$dedAppliedRows = $conn->prepare("
  SELECT pr.id AS run_id,
         pr.period_from,
         pr.period_to,
         pr.status,
         ROUND(pi.other_applied,2) AS amount_applied
  FROM payroll_runs pr
  JOIN payroll_items pi ON pi.payroll_run_id = pr.id
  WHERE pi.employee_id = ? AND pi.other_applied > 0
  ORDER BY pr.period_from DESC, pr.id DESC
");
$dedAppliedRows->execute([$emp['id']]);
$dedAppliedRows = $dedAppliedRows->fetchAll(PDO::FETCH_ASSOC);

$dedCashSettlements = [];
if (hr_deduction_settlements_table_ready($conn)) {
  $st = $conn->prepare("SELECT * FROM hr_deduction_settlements WHERE employee_id = ? ORDER BY settle_date DESC, id DESC");
  $st->execute([$emp['id']]);
  $dedCashSettlements = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Balances for banners
if (hr_loans_schema_ready($conn)) {
  $cashAvail = hr_loan_employee_outstanding($conn, (int)$emp['id']);
  $cashIssued = (float)$conn->query("
    SELECT COALESCE(SUM(COALESCE(principal, amount)),0) FROM cash_advances
    WHERE employee_id={$emp['id']} AND status<>'void'
    AND (request_status IS NULL OR request_status = 'approved')
  ")->fetchColumn();
  $cashApplied = max(0, $cashIssued - $cashAvail);
} else {
  $cashIssued = (float)$conn->query("
    SELECT COALESCE(SUM(amount),0) FROM cash_advances
    WHERE employee_id={$emp['id']} AND status<>'void'
    AND (request_status IS NULL OR request_status = 'approved' OR request_status = 'rejected')
  ")->fetchColumn();

  $cashApplied = (float)$conn->query("
    SELECT COALESCE(SUM(pi.adv_applied),0)
    FROM payroll_items pi JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
    WHERE pi.employee_id={$emp['id']} AND pr.status IN ('open','posted','finalized','paid')
  ")->fetchColumn();

  $cashAvail = max(0,$cashIssued-$cashApplied);
}

$hasDedRemainingCol = false;
try {
  $chk = $conn->query("SHOW COLUMNS FROM employee_deductions LIKE 'remaining_balance'");
  $hasDedRemainingCol = $chk && $chk->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $hasDedRemainingCol = false;
}
if ($hasDedRemainingCol) {
  $dedAvail = (float)$conn->query("
    SELECT COALESCE(SUM(remaining_balance),0) FROM employee_deductions
    WHERE employee_id={$emp['id']} AND COALESCE(settle_status,'open')='open'
  ")->fetchColumn();
  $dedIssued = (float)$conn->query("
    SELECT COALESCE(SUM(amount),0) FROM employee_deductions WHERE employee_id={$emp['id']}
  ")->fetchColumn();
  $dedApplied = max(0, $dedIssued - $dedAvail);
} else {
  $dedIssued = (float)$conn->query("
    SELECT COALESCE(SUM(amount),0) FROM employee_deductions
    WHERE employee_id={$emp['id']}
  ")->fetchColumn();

  $dedApplied = (float)$conn->query("
    SELECT COALESCE(SUM(pi.other_applied),0)
    FROM payroll_items pi JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
    WHERE pi.employee_id={$emp['id']} AND pr.status IN ('open','posted','finalized','paid')
  ")->fetchColumn();

  $dedAvail = max(0,$dedIssued-$dedApplied);
}

/* ------------------------------------
   POST Handlers for New Features
------------------------------------- */

// Emergency Contacts
$emergency_msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_emergency_contact'])) {
    csrf_verify();
    $name = trim($_POST['name'] ?? '');
    $relationship = trim($_POST['relationship'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $priority = (int)($_POST['priority'] ?? 1);
    if ($name) {
        $stmt = $conn->prepare("INSERT INTO emergency_contacts (employee_id, name, relationship, phone, email, priority) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$emp['id'], $name, $relationship ?: null, $phone ?: null, $email ?: null, $priority]);
        $contactIdNew = (int)$conn->lastInsertId();
        $_SESSION['emergency_msg'] = 'Emergency contact added.';
        
        // Log activity
        logEmployeeActivity($conn, $emp['id'], 'emergency_contact_added', "Added emergency contact: {$name}" . ($relationship ? " ({$relationship})" : ''));
        audit_bridge_hr_ops(
            'emergency_contact_added',
            'emergency_contacts',
            $contactIdNew > 0 ? $contactIdNew : (int)$emp['id'],
            'Added emergency contact ' . $name
                . ($relationship ? (' (' . $relationship . ')') : '')
                . ' for ' . ($emp['full_name'] ?? $emp['employee_code']),
            isset($emp['company_id']) ? (int)$emp['company_id'] : null,
            [
                'employee_id' => (int)$emp['id'],
                'name' => $name,
                'relationship' => $relationship !== '' ? $relationship : null,
                'phone' => $phone !== '' ? $phone : null,
            ],
            'Contact #' . ($contactIdNew > 0 ? $contactIdNew : '?'),
            isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
        );
        
        // Redirect to prevent resubmission and preserve tab (PRG pattern)
        $redirectUrl = ($isWorkerSelfService ? 'employee_view.php' : 'employee_view.php?id=' . urlencode($emp['id'])) . '#tab-emergency';
        header('Location: ' . $redirectUrl);
        exit;
    }
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del_emergency_contact'])) {
    csrf_verify();
    $contactId = (int)($_POST['del_emergency_contact'] ?? 0);
    
    // Get contact name before deleting for logging
    $contactName = '';
    $nameStmt = $conn->prepare("SELECT name FROM emergency_contacts WHERE id=? AND employee_id=?");
    $nameStmt->execute([$contactId, $emp['id']]);
    $contactRow = $nameStmt->fetch(PDO::FETCH_ASSOC);
    if ($contactRow) {
        $contactName = $contactRow['name'];
    }
    
    $stmt = $conn->prepare("DELETE FROM emergency_contacts WHERE id=? AND employee_id=?");
    $stmt->execute([$contactId, $emp['id']]);
    $_SESSION['emergency_msg'] = 'Emergency contact removed.';
    
    // Log activity
    if ($contactName) {
        logEmployeeActivity($conn, $emp['id'], 'emergency_contact_deleted', "Deleted emergency contact: {$contactName}");
    }
    audit_bridge_hr_ops(
        'emergency_contact_deleted',
        'emergency_contacts',
        $contactId,
        'Deleted emergency contact'
            . ($contactName !== '' ? (' ' . $contactName) : '')
            . ' for ' . ($emp['full_name'] ?? $emp['employee_code']),
        isset($emp['company_id']) ? (int)$emp['company_id'] : null,
        ['employee_id' => (int)$emp['id'], 'name' => $contactName !== '' ? $contactName : null],
        'Contact #' . $contactId,
        isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
    );
    
    // Redirect to prevent resubmission and preserve tab (PRG pattern)
    $redirectUrl = ($isWorkerSelfService ? 'employee_view.php' : 'employee_view.php?id=' . urlencode($emp['id'])) . '#tab-emergency';
    header('Location: ' . $redirectUrl);
    exit;
}
$emergency_msg = $_SESSION['emergency_msg'] ?? '';
unset($_SESSION['emergency_msg']);

// Internal Notes
$note_msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_note']) && !$workerReadOnly) {
    csrf_verify();
    $note_text = trim($_POST['note_text'] ?? '');
    $note_type = $_POST['note_type'] ?? 'general';
    if ($note_text) {
        $stmt = $conn->prepare("INSERT INTO employee_notes (employee_id, note_text, note_type, created_by) VALUES (?,?,?,?)");
        $stmt->execute([$emp['id'], $note_text, $note_type, (int)($_SESSION['user']['id'] ?? 0)]);
        $noteId = (int)$conn->lastInsertId();
        $_SESSION['note_msg'] = 'Note added.';
        audit_bridge_hr_ops(
            'note_added',
            'employee_notes',
            $noteId > 0 ? $noteId : (int)$emp['id'],
            'Added HR note (' . $note_type . ') for ' . ($emp['full_name'] ?? $emp['employee_code']),
            isset($emp['company_id']) ? (int)$emp['company_id'] : null,
            [
                'employee_id' => (int)$emp['id'],
                'note_type' => $note_type,
                'note_preview' => mb_substr($note_text, 0, 120),
            ],
            'Note #' . ($noteId > 0 ? $noteId : '?'),
            isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
        );
        header('Location: employee_view.php?id=' . urlencode($emp['id']) . '#tab-notes');
        exit;
    }
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del_note']) && !$workerReadOnly) {
    csrf_verify();
    $delNoteId = (int)$_POST['del_note'];
    $stmt = $conn->prepare("DELETE FROM employee_notes WHERE id=? AND employee_id=?");
    $stmt->execute([$delNoteId, $emp['id']]);
    $_SESSION['note_msg'] = 'Note removed.';
    audit_bridge_hr_ops(
        'note_deleted',
        'employee_notes',
        $delNoteId,
        'Deleted HR note #' . $delNoteId . ' for ' . ($emp['full_name'] ?? $emp['employee_code']),
        isset($emp['company_id']) ? (int)$emp['company_id'] : null,
        ['employee_id' => (int)$emp['id']],
        'Note #' . $delNoteId,
        isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
    );
    header('Location: employee_view.php?id=' . urlencode($emp['id']) . '#tab-notes');
    exit;
}
$note_msg = $_SESSION['note_msg'] ?? '';
unset($_SESSION['note_msg']);


// Employment History
$history_msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_history']) && !$workerReadOnly) {
    csrf_verify();
    $event_type = $_POST['event_type'] ?? 'other';
    $event_date = $_POST['event_date'] ?: date('Y-m-d');
    $title = trim($_POST['title'] ?? '');
    $dept_id = $_POST['department_id'] ? (int)$_POST['department_id'] : null;
    $loc_id = $_POST['location_id'] ? (int)$_POST['location_id'] : null;
    $salary_before = $_POST['salary_before'] ? (float)$_POST['salary_before'] : null;
    $salary_after = $_POST['salary_after'] ? (float)$_POST['salary_after'] : null;
    $notes = trim($_POST['notes'] ?? '');
    $stmt = $conn->prepare("INSERT INTO employment_history (employee_id, event_type, event_date, title, department_id, location_id, salary_before, salary_after, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$emp['id'], $event_type, $event_date, $title ?: null, $dept_id, $loc_id, $salary_before, $salary_after, $notes ?: null, (int)($_SESSION['user']['id'] ?? 0)]);
    $histId = (int)$conn->lastInsertId();
    $_SESSION['history_msg'] = 'History entry added.';
    audit_bridge_hr_ops(
        'history_added',
        'employment_history',
        $histId > 0 ? $histId : (int)$emp['id'],
        'Added employment history (' . $event_type . ')'
            . ($title !== '' ? (': ' . $title) : '')
            . ' for ' . ($emp['full_name'] ?? $emp['employee_code'])
            . ' on ' . $event_date,
        isset($emp['company_id']) ? (int)$emp['company_id'] : null,
        [
            'employee_id' => (int)$emp['id'],
            'event_type' => $event_type,
            'event_date' => $event_date,
            'title' => $title !== '' ? $title : null,
            'salary_before' => $salary_before,
            'salary_after' => $salary_after,
        ],
        'History #' . ($histId > 0 ? $histId : '?'),
        isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
    );
    header('Location: employee_view.php?id=' . urlencode($emp['id']) . '#tab-history');
    exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del_history']) && !$workerReadOnly) {
    csrf_verify();
    $delHistId = (int)$_POST['del_history'];
    $stmt = $conn->prepare("DELETE FROM employment_history WHERE id=? AND employee_id=?");
    $stmt->execute([$delHistId, $emp['id']]);
    $_SESSION['history_msg'] = 'History entry removed.';
    audit_bridge_hr_ops(
        'history_deleted',
        'employment_history',
        $delHistId,
        'Deleted employment history #' . $delHistId . ' for ' . ($emp['full_name'] ?? $emp['employee_code']),
        isset($emp['company_id']) ? (int)$emp['company_id'] : null,
        ['employee_id' => (int)$emp['id']],
        'History #' . $delHistId,
        isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
    );
    header('Location: employee_view.php?id=' . urlencode($emp['id']) . '#tab-history');
    exit;
}
$history_msg = $_SESSION['history_msg'] ?? '';
unset($_SESSION['history_msg']);


// Work Schedule
$schedule_msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_schedule']) && !$workerReadOnly) {
    csrf_verify();
    $schedule_type = $_POST['schedule_type'] ?? 'regular';
    $monday = trim($_POST['monday_hours'] ?? '');
    $tuesday = trim($_POST['tuesday_hours'] ?? '');
    $wednesday = trim($_POST['wednesday_hours'] ?? '');
    $thursday = trim($_POST['thursday_hours'] ?? '');
    $friday = trim($_POST['friday_hours'] ?? '');
    $saturday = trim($_POST['saturday_hours'] ?? '');
    $sunday = trim($_POST['sunday_hours'] ?? '');
    $effective_from = $_POST['effective_from'] ?: date('Y-m-d');
    $effective_to = $_POST['effective_to'] ?: null;
    $notes = trim($_POST['notes'] ?? '');
    $stmt = $conn->prepare("INSERT INTO employee_work_schedules (employee_id, schedule_type, monday_hours, tuesday_hours, wednesday_hours, thursday_hours, friday_hours, saturday_hours, sunday_hours, effective_from, effective_to, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$emp['id'], $schedule_type, $monday ?: null, $tuesday ?: null, $wednesday ?: null, $thursday ?: null, $friday ?: null, $saturday ?: null, $sunday ?: null, $effective_from, $effective_to ?: null, $notes ?: null]);
    $schedId = (int)$conn->lastInsertId();
    $_SESSION['schedule_msg'] = 'Work schedule saved.';
    audit_bridge_hr_ops(
        'schedule_updated',
        'employee_work_schedules',
        $schedId > 0 ? $schedId : (int)$emp['id'],
        'Updated work schedule for ' . ($emp['full_name'] ?? $emp['employee_code'])
            . ' (' . $schedule_type . ', from ' . $effective_from . ')',
        isset($emp['company_id']) ? (int)$emp['company_id'] : null,
        [
            'employee_id' => (int)$emp['id'],
            'schedule_type' => $schedule_type,
            'effective_from' => $effective_from,
            'effective_to' => $effective_to,
        ],
        'Schedule #' . ($schedId > 0 ? $schedId : '?'),
        isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
    );
    header('Location: employee_view.php?id=' . urlencode($emp['id']) . '#tab-schedule');
    exit;
}
$schedule_msg = $_SESSION['schedule_msg'] ?? '';
unset($_SESSION['schedule_msg']);


/* ------------------------------------
   Fetch data for new profile features
------------------------------------- */

// Date range for Overview tab - support custom date ranges
$overviewDateRange = $_GET['overview_range'] ?? 'current_month';
$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-t');
$lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
$lastMonthEnd = date('Y-m-t', strtotime('last day of last month'));

// Determine date range based on selection
$hoursDateStart = $currentMonthStart;
$hoursDateEnd = $currentMonthEnd;
$hoursDateLabel = date('M Y');

switch ($overviewDateRange) {
    case 'last_month':
        $hoursDateStart = $lastMonthStart;
        $hoursDateEnd = $lastMonthEnd;
        $hoursDateLabel = date('M Y', strtotime('first day of last month'));
        break;
    case 'current_month':
    default:
        $hoursDateStart = $currentMonthStart;
        $hoursDateEnd = $currentMonthEnd;
        $hoursDateLabel = date('M Y');
        break;
}

$currentYear = (int)date('Y');

// Hours worked this month (from performance data)
// Use the same worker matching logic as Performance tab to ensure consistency
$hoursThisMonth = 0.0;
$workerCandidatesForHours = [];
if (!empty($emp['employee_code'])) $workerCandidatesForHours[] = ['field' => 'emp_num',     'value' => $emp['employee_code']];
if (!empty($emp['nickname']))      $workerCandidatesForHours[] = ['field' => 'nickname',    'value' => $emp['nickname']];
if (!empty($emp['full_name']))     $workerCandidatesForHours[] = ['field' => 'worker_name', 'value' => $emp['full_name']];

$workerIdForHours = null;
$dailyCapForHours = 8.0; // Default to 8 hours if no cap set
foreach ($workerCandidatesForHours as $candidate) {
    $stmt = $conn->prepare("
        SELECT id, daily_cap_hours
        FROM workers
        WHERE {$candidate['field']} = ?
        LIMIT 1
    ");
    $stmt->execute([$candidate['value']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $workerIdForHours = (int)$row['id'];
        $dailyCapForHours = isset($row['daily_cap_hours']) && $row['daily_cap_hours'] !== null 
            ? (float)$row['daily_cap_hours'] 
            : 8.0;
        break;
    }
}

if ($workerIdForHours) {
    // Calculate hours with daily cap (exclude overtime)
    // Group by day, sum hours per day, cap at daily_cap_hours (or 8), then sum capped values
    $hoursStmt = $conn->prepare("
        SELECT ROUND(SUM(LEAST(day_hours, ?)), 2)
        FROM (
            SELECT COALESCE(mo.service_date, mo.date) AS work_date,
                   SUM(COALESCE(mo.net_hours, CASE WHEN owc.cnt>0 THEN mo.hours/owc.cnt ELSE mo.hours END)) AS day_hours
            FROM order_workers ow
            JOIN make_order mo ON mo.id = ow.order_id
            JOIN (SELECT order_id, COUNT(*) cnt FROM order_workers GROUP BY order_id) owc ON owc.order_id = mo.id
            WHERE ow.worker_id = ?
              AND mo.status IN ('confirmed','completed')
              AND COALESCE(mo.service_date, mo.date) BETWEEN ? AND ?
            GROUP BY COALESCE(mo.service_date, mo.date)
        ) AS daily_totals
    ");
    $hoursStmt->execute([
        $dailyCapForHours,
        $workerIdForHours,
        $hoursDateStart,
        $hoursDateEnd
    ]);
    $hoursThisMonth = (float)$hoursStmt->fetchColumn();
    
    // Calculate previous period hours for trend comparison
    $previousPeriodStart = date('Y-m-01', strtotime($hoursDateStart . ' -1 month'));
    $previousPeriodEnd = date('Y-m-t', strtotime($hoursDateStart . ' -1 month'));
    $hoursPrevStmt = $conn->prepare("
        SELECT ROUND(SUM(LEAST(day_hours, ?)), 2)
        FROM (
            SELECT COALESCE(mo.service_date, mo.date) AS work_date,
                   SUM(COALESCE(mo.net_hours, CASE WHEN owc.cnt>0 THEN mo.hours/owc.cnt ELSE mo.hours END)) AS day_hours
            FROM order_workers ow
            JOIN make_order mo ON mo.id = ow.order_id
            JOIN (SELECT order_id, COUNT(*) cnt FROM order_workers GROUP BY order_id) owc ON owc.order_id = mo.id
            WHERE ow.worker_id = ?
              AND mo.status IN ('confirmed','completed')
              AND COALESCE(mo.service_date, mo.date) BETWEEN ? AND ?
            GROUP BY COALESCE(mo.service_date, mo.date)
        ) AS daily_totals
    ");
    $hoursPrevStmt->execute([
        $dailyCapForHours,
        $workerIdForHours,
        $previousPeriodStart,
        $previousPeriodEnd
    ]);
    $hoursPreviousPeriod = (float)$hoursPrevStmt->fetchColumn();
    
    // Calculate trend
    $hoursTrend = 0;
    $hoursTrendDirection = '';
    $hoursTrendClass = '';
    if ($hoursPreviousPeriod > 0) {
        $hoursTrend = (($hoursThisMonth - $hoursPreviousPeriod) / $hoursPreviousPeriod) * 100;
        if ($hoursTrend > 0) {
            $hoursTrendDirection = '↑';
            $hoursTrendClass = 'text-success';
        } elseif ($hoursTrend < 0) {
            $hoursTrendDirection = '↓';
            $hoursTrendClass = 'text-danger';
        } else {
            $hoursTrendDirection = '→';
            $hoursTrendClass = 'text-muted';
        }
    }
} else {
    $hoursPreviousPeriod = 0;
    $hoursTrend = 0;
    $hoursTrendDirection = '';
    $hoursTrendClass = '';
}

// Pending requests count (Fixed SQL injection)
$pendingLeaveStmt = $conn->prepare("
  SELECT COUNT(*) FROM leave_requests 
  WHERE employee_id = ? AND status = 'pending'
");
$pendingLeaveStmt->execute([$emp['id']]);
$pendingLeaveRequests = (int)$pendingLeaveStmt->fetchColumn();

$pendingCashAdvanceStmt = $conn->prepare("
  SELECT COUNT(*) FROM cash_advances 
  WHERE employee_id = ? AND request_status = 'pending'
");
$pendingCashAdvanceStmt->execute([$emp['id']]);
$pendingCashAdvanceRequests = (int)$pendingCashAdvanceStmt->fetchColumn();

// Document expiries (next 30 days) - Fixed SQL injection
$expiringDocsStmt = $conn->prepare("
  SELECT COUNT(*) FROM employee_documents 
  WHERE employee_id = ? 
    AND expires_at IS NOT NULL 
    AND expires_at != '0000-00-00'
    AND expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
");
$expiringDocsStmt->execute([$emp['id']]);
$expiringDocsCount = (int)$expiringDocsStmt->fetchColumn();

// Attendance streak (consecutive days present, counting backwards from today)
$attendanceStreak = 0;
$bestAttendanceStreak = 0;
$streakStmt = $conn->prepare("
  SELECT work_date, status 
  FROM attendance 
  WHERE employee_id = ? 
    AND status = 'approved' 
    AND work_date <= CURDATE()
  ORDER BY work_date DESC
  LIMIT 365
");
$streakStmt->execute([$emp['id']]);
$attendanceRecords = $streakStmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($attendanceRecords)) {
  $today = new DateTime('today');
  $consecutiveDays = 0;
  $checkDate = clone $today;
  $bestStreak = 0;
  $currentStreak = 0;
  
  // Calculate current streak
  foreach ($attendanceRecords as $record) {
    $recordDate = new DateTime($record['work_date']);
    if ($recordDate->format('Y-m-d') === $checkDate->format('Y-m-d')) {
      $consecutiveDays++;
      $currentStreak++;
      $checkDate->modify('-1 day');
    } else {
      // If there's a gap, check if this is the best streak
      if ($currentStreak > $bestStreak) {
        $bestStreak = $currentStreak;
      }
      $currentStreak = 0;
      // Continue checking for best streak
      $checkDate = clone $recordDate;
      if ($recordDate->format('Y-m-d') === $checkDate->format('Y-m-d')) {
        $currentStreak = 1;
        $checkDate->modify('-1 day');
      }
    }
  }
  // Check final streak
  if ($currentStreak > $bestStreak) {
    $bestStreak = $currentStreak;
  }
  $attendanceStreak = $consecutiveDays;
  $bestAttendanceStreak = $bestStreak;
}

// Emergency Contacts (needed for profile completion)
$emergencyContacts = [];
try {
    $emergencyContactsStmt = $conn->prepare("
      SELECT * FROM emergency_contacts 
      WHERE employee_id = ? 
      ORDER BY priority ASC, name ASC
    ");
    $emergencyContactsStmt->execute([$emp['id']]);
    $emergencyContacts = $emergencyContactsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $emergencyContacts = [];
}

// Profile completion calculation
$profileFields = [
    'full_name' => !empty($emp['full_name']),
    'employee_code' => !empty($emp['employee_code']),
    'email' => !empty($emp['email']),
    'phone' => !empty($emp['phone']),
    'address' => !empty($emp['address']),
    'position_title' => !empty($emp['position_title']),
    'department_id' => !empty($emp['department_id']),
    'location_id' => !empty($emp['location_id']),
    'date_joined' => !empty($emp['date_joined']),
    'emergency_contacts' => count($emergencyContacts) > 0,
    'documents' => $expiringDocsCount >= 0, // At least checked
];
$profileCompleted = count(array_filter($profileFields));
$profileTotal = count($profileFields);
$profileCompletionPercent = $profileTotal > 0 ? round(($profileCompleted / $profileTotal) * 100) : 0;

// Upcoming events
$upcomingEvents = [];
$today = new DateTime('today');
$next30Days = clone $today;
$next30Days->modify('+30 days');

// Check for document expirations
$expiringDocsStmt = $conn->prepare("
  SELECT doc_type, expires_at, DATEDIFF(expires_at, CURDATE()) AS days_until
  FROM employee_documents 
  WHERE employee_id = ? 
    AND expires_at IS NOT NULL 
    AND expires_at != '0000-00-00'
    AND expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
  ORDER BY expires_at ASC
  LIMIT 5
");
$expiringDocsStmt->execute([$emp['id']]);
$expiringDocs = $expiringDocsStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($expiringDocs as $doc) {
    $upcomingEvents[] = [
        'type' => 'document_expiry',
        'title' => $doc['doc_type'] . ' expires',
        'date' => $doc['expires_at'],
        'days' => (int)$doc['days_until'],
        'icon' => 'bi-file-earmark-text',
        'color' => $doc['days_until'] <= 7 ? 'danger' : 'warning'
    ];
}

// Check for birthday (if date_of_birth exists)
if (!empty($emp['date_of_birth'])) {
    $birthDate = new DateTime($emp['date_of_birth']);
    $thisYearBirthday = new DateTime(date('Y') . '-' . $birthDate->format('m-d'));
    if ($thisYearBirthday < $today) {
        $thisYearBirthday->modify('+1 year');
    }
    $daysUntilBirthday = $today->diff($thisYearBirthday)->days;
    if ($daysUntilBirthday <= 30) {
        $age = (int)$today->diff($birthDate)->y;
        if ($thisYearBirthday->format('Y-m-d') === $today->format('Y-m-d')) {
            $age++; // Today is their birthday, so they're turning this age
        }
        $upcomingEvents[] = [
            'type' => 'birthday',
            'title' => 'Birthday' . ($daysUntilBirthday === 0 ? ' (Today!)' : ''),
            'date' => $thisYearBirthday->format('Y-m-d'),
            'days' => $daysUntilBirthday,
            'icon' => 'bi-balloon-fill',
            'color' => $daysUntilBirthday === 0 ? 'success' : 'primary'
        ];
    }
}

// Check for employment anniversary (if date_joined exists)
if (!empty($emp['date_joined'])) {
    $joinedDate = new DateTime($emp['date_joined']);
    $thisYearAnniversary = new DateTime(date('Y') . '-' . $joinedDate->format('m-d'));
    if ($thisYearAnniversary < $today) {
        $thisYearAnniversary->modify('+1 year');
    }
    $daysUntilAnniversary = $today->diff($thisYearAnniversary)->days;
    if ($daysUntilAnniversary <= 30) {
        $yearsOfService = (int)$today->diff($joinedDate)->y;
        $upcomingEvents[] = [
            'type' => 'anniversary',
            'title' => ($yearsOfService + 1) . ' Year' . ($yearsOfService + 1 > 1 ? 's' : '') . ' Service Anniversary',
            'date' => $thisYearAnniversary->format('Y-m-d'),
            'days' => $daysUntilAnniversary,
            'icon' => 'bi-calendar-heart',
            'color' => 'info'
        ];
    }
}

// Check for upcoming approved leave requests
$upcomingLeaveStmt = $conn->prepare("
  SELECT lr.date_from, lr.date_to, lr.days, lt.name AS leave_type_name
  FROM leave_requests lr
  JOIN leave_types lt ON lt.id = lr.leave_type_id
  WHERE lr.employee_id = ?
    AND lr.status = 'approved'
    AND (
      (lr.date_from BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))
      OR (lr.date_to BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))
      OR (lr.date_from <= CURDATE() AND lr.date_to >= CURDATE())
    )
  ORDER BY lr.date_from ASC
  LIMIT 5
");
$upcomingLeaveStmt->execute([$emp['id']]);
$upcomingLeaves = $upcomingLeaveStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($upcomingLeaves as $leave) {
    $leaveStart = new DateTime($leave['date_from']);
    $leaveEnd = new DateTime($leave['date_to']);
    
    // Skip if leave has already ended
    if ($leaveEnd < $today) {
        continue;
    }
    
    $daysUntilLeave = $today->diff($leaveStart)->days;
    
    // If leave has already started, show days until it ends
    if ($leaveStart <= $today) {
        $daysUntilLeave = $today->diff($leaveEnd)->days;
        $title = $leave['leave_type_name'] . ' Leave (Ongoing)';
        $eventDate = $leaveEnd->format('Y-m-d');
    } else {
        $title = $leave['leave_type_name'] . ' Leave';
        $eventDate = $leaveStart->format('Y-m-d');
    }
    
    // Only add if within 30 days (or currently ongoing)
    if ($daysUntilLeave <= 30 || $leaveStart <= $today) {
        $upcomingEvents[] = [
            'type' => 'leave',
            'title' => $title,
            'date' => $eventDate,
            'days' => $daysUntilLeave,
            'icon' => 'bi-calendar-event',
            'color' => $leaveStart <= $today ? 'info' : 'primary',
            'date_from' => $leave['date_from'],
            'date_to' => $leave['date_to'],
            'days_count' => (int)$leave['days']
        ];
    }
}

// Sort events by days until (closest first)
usort($upcomingEvents, function($a, $b) {
    return $a['days'] <=> $b['days'];
});

// Emergency Contacts (already fetched above for profile completion)

// Internal Notes (HR only)
$employeeNotes = [];
if (!$workerReadOnly) {
  $notesStmt = $conn->prepare("
    SELECT n.*, u.fullname AS created_by_name, u.username AS created_by_username
    FROM employee_notes n
    LEFT JOIN `user` u ON u.id = n.created_by
    WHERE n.employee_id = ?
    ORDER BY n.created_at DESC
  ");
  $notesStmt->execute([$emp['id']]);
  $employeeNotes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);
}


// Employment History
$employmentHistory = $conn->prepare("
  SELECT h.*, d.name AS dept_name, l.name AS loc_name, u.fullname AS created_by_name
  FROM employment_history h
  LEFT JOIN departments d ON d.id = h.department_id
  LEFT JOIN locations l ON l.id = h.location_id
  LEFT JOIN `user` u ON u.id = h.created_by
  WHERE h.employee_id = ?
  ORDER BY h.event_date DESC, h.created_at DESC
");
$employmentHistory->execute([$emp['id']]);
$employmentHistory = $employmentHistory->fetchAll(PDO::FETCH_ASSOC);


// Work Schedule
$workSchedule = $conn->prepare("
  SELECT * FROM employee_work_schedules 
  WHERE employee_id = ? 
    AND (effective_to IS NULL OR effective_to >= CURDATE())
  ORDER BY effective_from DESC
  LIMIT 1
");
$workSchedule->execute([$emp['id']]);
$workSchedule = $workSchedule->fetch(PDO::FETCH_ASSOC);


// Activity Log - combine employee_activity_log and relevant audit_log entries
$activityLog = [];
try {
  // Get from employee_activity_log
  $activityLogStmt = $conn->prepare("
    SELECT a.*, u.fullname AS performed_by_name, u.username AS performed_by_username,
           'employee_activity' AS source
    FROM employee_activity_log a
    LEFT JOIN `user` u ON u.id = a.performed_by
    WHERE a.employee_id = ?
    ORDER BY a.created_at DESC
    LIMIT 100
  ");
  $activityLogStmt->execute([$emp['id']]);
  $employeeActivities = $activityLogStmt->fetchAll(PDO::FETCH_ASSOC);
  
  // Get relevant audit_log entries for this employee (login/logout, profile changes)
  // First, get user_id if employee has a linked user account
  $userId = null;
  if (!empty($emp['user_id'])) {
    $userId = (int)$emp['user_id'];
  }
  
  $auditActivities = [];
  if ($userId) {
    $auditStmt = $conn->prepare("
      SELECT 
        action AS action_type,
        summary AS action_description,
        NULL AS changed_field,
        NULL AS old_value,
        NULL AS new_value,
        user_id AS performed_by,
        user_name AS performed_by_name,
        user_name AS performed_by_username,
        created_at,
        'audit_log' AS source
      FROM audit_log
      WHERE user_id = ? 
        AND action IN ('login', 'logout', 'login_redirect')
        AND object_type = 'auth'
      ORDER BY created_at DESC
      LIMIT 20
    ");
    $auditStmt->execute([$userId]);
    $auditActivities = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
  }
  
  // Merge and sort by date
  $activityLog = array_merge($employeeActivities, $auditActivities);
  usort($activityLog, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
  });
  $activityLog = array_slice($activityLog, 0, 100);
  
} catch (Exception $e) {
  // Table might not exist yet, that's okay
  $activityLog = [];
}

// Reporting Manager
$reportingManager = null;
if (!empty($emp['reporting_manager_id'])) {
  $mgrStmt = $conn->prepare("SELECT id, full_name, employee_code, email, phone FROM employees WHERE id = ?");
  $mgrStmt->execute([$emp['reporting_manager_id']]);
  $reportingManager = $mgrStmt->fetch(PDO::FETCH_ASSOC);
}


// Performance Reviews History (if table exists)
$performanceReviews = [];
try {
  $prStmt = $conn->prepare("
    SELECT pr.*, u.fullname AS reviewed_by_name
    FROM performance_reviews pr
    LEFT JOIN `user` u ON u.id = pr.reviewed_by
    WHERE pr.employee_id = ?
    ORDER BY pr.review_date DESC
  ");
  $prStmt->execute([$emp['id']]);
  $performanceReviews = $prStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  // Table might not exist yet, that's okay
  $performanceReviews = [];
}

/* ---------------- Assets (actions + data) ---------------- */
$asset_msg = '';

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_asset'])) {
    csrf_verify();
  $item_name = trim($_POST['item_name'] ?? '');
  $serial_no = trim($_POST['serial_no'] ?? '');
  $issue_date= $_POST['issue_date'] ?: date('Y-m-d');
  $return_due= $_POST['return_due'] ?: null;
  $notes     = trim($_POST['notes'] ?? '');
  if ($item_name !== '') {
    $stmt = $conn->prepare("INSERT INTO employee_assets
      (employee_id,item_name,serial_no,issue_date,return_due,status,notes,created_by)
      VALUES (?,?,?,?,?,'issued',?,?)");
    $stmt->execute([$emp['id'],$item_name,$serial_no,$issue_date,$return_due,$notes,($_SESSION['user']['id'] ?? null)]);
    $assetId = (int)$conn->lastInsertId();
    $asset_msg = 'Asset issued.';
    audit_bridge_hr_ops(
      'asset_issued',
      'employee_assets',
      $assetId > 0 ? $assetId : (int)$emp['id'],
      'Issued asset ' . $item_name . ' to ' . ($emp['full_name'] ?? $emp['employee_code']),
      isset($emp['company_id']) ? (int)$emp['company_id'] : null,
      [
        'employee_id' => (int)$emp['id'],
        'item_name' => $item_name,
        'serial_no' => $serial_no !== '' ? $serial_no : null,
        'issue_date' => $issue_date,
      ],
      'Asset #' . ($assetId > 0 ? $assetId : '?'),
      isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
    );
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['return_asset'])) {
    csrf_verify();
  $id = (int)$_POST['return_asset'];
  $ret = $_POST['return_date'] ?: date('Y-m-d');
  $prevAsset = $conn->prepare("SELECT item_name FROM employee_assets WHERE id=? AND employee_id=? LIMIT 1");
  $prevAsset->execute([$id, $emp['id']]);
  $assetName = (string)($prevAsset->fetchColumn() ?: '');
  $stmt = $conn->prepare("UPDATE employee_assets SET status='returned', return_date=? WHERE id=? AND employee_id=?");
  $stmt->execute([$ret,$id,$emp['id']]);
  $asset_msg = 'Asset returned.';
  audit_bridge_hr_ops(
    'asset_returned',
    'employee_assets',
    $id,
    'Returned asset' . ($assetName !== '' ? (' ' . $assetName) : '')
      . ' for ' . ($emp['full_name'] ?? $emp['employee_code'])
      . ' on ' . $ret,
    isset($emp['company_id']) ? (int)$emp['company_id'] : null,
    ['employee_id' => (int)$emp['id'], 'item_name' => $assetName !== '' ? $assetName : null, 'return_date' => $ret],
    'Asset #' . $id,
    isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
  );
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['lost_asset'])) {
    csrf_verify();
  $id = (int)$_POST['lost_asset'];
  $prevAsset = $conn->prepare("SELECT item_name FROM employee_assets WHERE id=? AND employee_id=? LIMIT 1");
  $prevAsset->execute([$id, $emp['id']]);
  $assetName = (string)($prevAsset->fetchColumn() ?: '');
  $stmt = $conn->prepare("UPDATE employee_assets SET status='lost' WHERE id=? AND employee_id=?");
  $stmt->execute([$id,$emp['id']]);
  $asset_msg = 'Asset marked lost.';
  audit_bridge_hr_ops(
    'asset_lost',
    'employee_assets',
    $id,
    'Marked asset lost' . ($assetName !== '' ? (' ' . $assetName) : '')
      . ' for ' . ($emp['full_name'] ?? $emp['employee_code']),
    isset($emp['company_id']) ? (int)$emp['company_id'] : null,
    ['employee_id' => (int)$emp['id'], 'item_name' => $assetName !== '' ? $assetName : null],
    'Asset #' . $id,
    isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
  );
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del_asset'])) {
    csrf_verify();
  $id = (int)$_POST['del_asset'];
  $prevAsset = $conn->prepare("SELECT item_name FROM employee_assets WHERE id=? AND employee_id=? LIMIT 1");
  $prevAsset->execute([$id, $emp['id']]);
  $assetName = (string)($prevAsset->fetchColumn() ?: '');
  $stmt = $conn->prepare("DELETE FROM employee_assets WHERE id=? AND employee_id=?");
  $stmt->execute([$id,$emp['id']]);
  $asset_msg = 'Asset entry deleted.';
  audit_bridge_hr_ops(
    'asset_deleted',
    'employee_assets',
    $id,
    'Deleted asset' . ($assetName !== '' ? (' ' . $assetName) : '')
      . ' for ' . ($emp['full_name'] ?? $emp['employee_code']),
    isset($emp['company_id']) ? (int)$emp['company_id'] : null,
    ['employee_id' => (int)$emp['id'], 'item_name' => $assetName !== '' ? $assetName : null],
    'Asset #' . $id,
    isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
  );
}

$assetRows = $conn->prepare("
  SELECT * FROM employee_assets
  WHERE employee_id=?
  ORDER BY issue_date DESC, id DESC
");
$assetRows->execute([$emp['id']]);
$assetRows = $assetRows->fetchAll(PDO::FETCH_ASSOC);

$assetIssuedCount = (int)$conn->query("
  SELECT COUNT(*) FROM employee_assets WHERE employee_id={$emp['id']} AND status='issued'
")->fetchColumn();


/* ---------------- Training (actions + data) ---------------- */
$train_msg = '';
$train_upload_dir = __DIR__ . '/../uploads/training_certs';
if (!is_dir($train_upload_dir)) @mkdir($train_upload_dir,0775,true);

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_training'])) {
    csrf_verify();
  $course   = trim($_POST['course_name'] ?? '');
  $provider = trim($_POST['provider'] ?? '');
  $start    = $_POST['start_date'] ?: null;
  $end      = $_POST['end_date'] ?: null;
  $valid    = $_POST['cert_valid_until'] ?: null;
  $status   = $_POST['status'] ?? 'planned';
  $result   = trim($_POST['result'] ?? '');
  $notes    = trim($_POST['notes'] ?? '');

  $cert_path = null;
  if (!empty($_FILES['certificate']['name'])) {
    $ext = pathinfo($_FILES['certificate']['name'], PATHINFO_EXTENSION);
    $fname = 'cert_'.$emp['id'].'_'.time().($ext?'.'.$ext:'');
    if (move_uploaded_file($_FILES['certificate']['tmp_name'], $train_upload_dir.'/'.$fname)) {
      $cert_path = 'uploads/training_certs/'.$fname;
    }
  }

  if ($course!=='') {
    $stmt = $conn->prepare("INSERT INTO employee_training
      (employee_id,course_name,provider,start_date,end_date,cert_valid_until,status,result,certificate_path,notes,created_by)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$emp['id'],$course,$provider,$start,$end,$valid,$status,$result,$cert_path,$notes,($_SESSION['user']['id'] ?? null)]);
    $trainId = (int)$conn->lastInsertId();
    $train_msg = 'Training added.';
    audit_bridge_hr_ops(
      'training_added',
      'employee_training',
      $trainId > 0 ? $trainId : (int)$emp['id'],
      'Added training ' . $course . ' for ' . ($emp['full_name'] ?? $emp['employee_code']),
      isset($emp['company_id']) ? (int)$emp['company_id'] : null,
      [
        'employee_id' => (int)$emp['id'],
        'course_name' => $course,
        'provider' => $provider !== '' ? $provider : null,
        'status' => $status,
      ],
      'Training #' . ($trainId > 0 ? $trainId : '?'),
      isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
    );
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_training_status'])) {
    csrf_verify();
  $id = (int)$_POST['id']; $status = $_POST['new_status'] ?? 'completed';
  if (in_array($status,['planned','completed','expired','cancelled'],true)) {
    $prevTrain = $conn->prepare("SELECT course_name FROM employee_training WHERE id=? AND employee_id=? LIMIT 1");
    $prevTrain->execute([$id, $emp['id']]);
    $courseName = (string)($prevTrain->fetchColumn() ?: '');
    $u = $conn->prepare("UPDATE employee_training SET status=? WHERE id=? AND employee_id=?");
    $u->execute([$status,$id,$emp['id']]);
    $train_msg = 'Training status updated.';
    audit_bridge_hr_ops(
      'training_status_updated',
      'employee_training',
      $id,
      'Updated training status to ' . $status
        . ($courseName !== '' ? (' — ' . $courseName) : '')
        . ' for ' . ($emp['full_name'] ?? $emp['employee_code']),
      isset($emp['company_id']) ? (int)$emp['company_id'] : null,
      [
        'employee_id' => (int)$emp['id'],
        'course_name' => $courseName !== '' ? $courseName : null,
        'status' => $status,
      ],
      'Training #' . $id,
      isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
    );
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del_training'])) {
    csrf_verify();
  $id = (int)$_POST['del_training'];
  $q = $conn->prepare("SELECT certificate_path, course_name FROM employee_training WHERE id=? AND employee_id=?");
  $q->execute([$id,$emp['id']]);
  $row = $q->fetch(PDO::FETCH_ASSOC);
  if ($row) {
    if (!empty($row['certificate_path'])) {
      $p = __DIR__.'/../'.$row['certificate_path'];
      if (is_file($p)) @unlink($p);
    }
  }
  $conn->prepare("DELETE FROM employee_training WHERE id=? AND employee_id=?")->execute([$id,$emp['id']]);
  $train_msg = 'Training deleted.';
  audit_bridge_hr_ops(
    'training_deleted',
    'employee_training',
    $id,
    'Deleted training'
      . (!empty($row['course_name']) ? (' ' . $row['course_name']) : '')
      . ' for ' . ($emp['full_name'] ?? $emp['employee_code']),
    isset($emp['company_id']) ? (int)$emp['company_id'] : null,
    [
      'employee_id' => (int)$emp['id'],
      'course_name' => $row['course_name'] ?? null,
    ],
    'Training #' . $id,
    isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
  );
}

$trainRows = $conn->prepare("
  SELECT * FROM employee_training
  WHERE employee_id=?
  ORDER BY COALESCE(end_date,start_date) DESC, id DESC
");
$trainRows->execute([$emp['id']]);
$trainRows = $trainRows->fetchAll(PDO::FETCH_ASSOC);

$expiringSoon = (int)$conn->query("
  SELECT COUNT(*) FROM employee_training
  WHERE employee_id={$emp['id']}
    AND cert_valid_until IS NOT NULL
    AND cert_valid_until<> '0000-00-00'
    AND cert_valid_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
")->fetchColumn();

$monthStart = new DateTimeImmutable(date('Y-m-01'));
$monthEnd   = $monthStart->modify('last day of this month');

$workerRow = null;
$workerId  = null;
$workerCandidates = [];
if (!empty($emp['employee_code'])) $workerCandidates[] = ['field' => 'emp_num',     'value' => $emp['employee_code']];
if (!empty($emp['nickname']))      $workerCandidates[] = ['field' => 'nickname',    'value' => $emp['nickname']];
if (!empty($emp['full_name']))     $workerCandidates[] = ['field' => 'worker_name', 'value' => $emp['full_name']];

foreach ($workerCandidates as $candidate) {
    $stmt = $conn->prepare("
        SELECT id, emp_num, worker_name, nickname, daily_cap_hours, weekly_cap_hours, total_salary
        FROM workers
        WHERE {$candidate['field']} = ?
        LIMIT 1
    ");
    $stmt->execute([$candidate['value']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $workerRow = $row;
        $workerId  = (int)$row['id'];
        break;
    }
}

$dailyCapHours  = isset($workerRow['daily_cap_hours']) && $workerRow['daily_cap_hours'] !== null
    ? (float)$workerRow['daily_cap_hours']
    : (isset($emp['daily_cap_hours']) ? (float)$emp['daily_cap_hours'] : 0.0);
$weeklyCapHours = isset($workerRow['weekly_cap_hours']) && $workerRow['weekly_cap_hours'] !== null
    ? (float)$workerRow['weekly_cap_hours']
    : (isset($emp['weekly_cap_hours']) ? (float)$emp['weekly_cap_hours'] : 0.0);
$otThreshold    = $dailyCapHours > 0 ? $dailyCapHours : 8.0;

$performanceData = [
    'period_start'    => $monthStart->format('Y-m-d'),
    'period_end'      => $monthEnd->format('Y-m-d'),
    'worked_hours'    => 0.0,
    'approved_days'   => 0,
    'overtime_hours'  => 0.0,
    'avg_hours'       => 0.0,
    'target_hours'    => null,
    'achievement_pct' => null,
    'jobs'            => 0,
    'revenue'         => 0.0,
    'has_worker'      => (bool)$workerId,
];

$recentJobs = [];
$attendanceStreakDays = 0;
$attendanceStreakSince = null;
$attendanceRows = [];
$scheduleSummary = [
    'has_schedule' => false,
    'expected_days' => 0,
    'expected_hours' => 0.0,
    'days' => [],
];
$missingScheduledDays = [];
$absenceSummary = [
    'absent_days'   => 0,
    'deduction_amt' => 0.0,
];

if (!function_exists('calculate_absence_daily_rate')) {
    function calculate_absence_daily_rate(array $employee, ?array $worker = null): float {
        $monthlySalary = 0.0;
        if ($worker && isset($worker['total_salary']) && $worker['total_salary'] !== null) {
            $monthlySalary = (float)$worker['total_salary'];
        } elseif (isset($employee['total_salary']) && $employee['total_salary'] !== null) {
            $monthlySalary = (float)$employee['total_salary'];
        } else {
            $monthlySalary = (float)($employee['basic_salary'] ?? 0) + (float)($employee['allowance'] ?? 0);
        }
        if ($monthlySalary <= 0) return 0.0;
        return round($monthlySalary / 30, 2);
    }
}

try {
    $scheduleSummary = hr_schedule_summary(
        $conn,
        (int)$emp['id'],
        $monthStart->format('Y-m-d'),
        $monthEnd->format('Y-m-d')
    );

    $absenceStmt = $conn->prepare("
        SELECT work_date, status, hours
        FROM attendance
        WHERE employee_id = :eid
          AND work_date BETWEEN :start AND :end
        ORDER BY work_date DESC
        LIMIT 20
    ");
    $absenceStmt->execute([
        ':eid'   => $emp['id'],
        ':start' => $monthStart->format('Y-m-d'),
        ':end'   => $monthEnd->format('Y-m-d'),
    ]);

    $attendanceRows = $absenceStmt->fetchAll(PDO::FETCH_ASSOC);
    $attendanceByDate = [];
    foreach ($attendanceRows as $attendanceRow) {
        if (!empty($attendanceRow['work_date'])) {
            $attendanceByDate[$attendanceRow['work_date']] = $attendanceRow;
        }
    }

    if (!empty($scheduleSummary['has_schedule'])) {
        $todayYmd = date('Y-m-d');
        foreach ($scheduleSummary['days'] as $date => $scheduleDay) {
            if ($date > $todayYmd) {
                continue;
            }
            if (!empty($scheduleDay['is_workday']) && empty($attendanceByDate[$date])) {
                $missingScheduledDays[] = $scheduleDay;
            }
        }
    }

    $monthlySalaryForAbsence = 0.0;
    if (($workerRow['total_salary'] ?? null) !== null) {
        $monthlySalaryForAbsence = (float)$workerRow['total_salary'];
    } elseif (($emp['total_salary'] ?? null) !== null) {
        $monthlySalaryForAbsence = (float)$emp['total_salary'];
    } else {
        $monthlySalaryForAbsence = (float)($emp['basic_salary'] ?? 0) + (float)($emp['allowance'] ?? 0);
    }
    $dailyAbsenceRate = !empty($scheduleSummary['has_schedule'])
        ? hr_schedule_salary_daily_rate($monthlySalaryForAbsence, $scheduleSummary)
        : calculate_absence_daily_rate($emp, $workerRow ?? []);

    $absenceSummary['absent_days'] = count(array_filter($attendanceRows, function ($row) use ($scheduleSummary) {
        if (strtolower($row['status'] ?? '') !== 'absent') {
            return false;
        }
        if (empty($scheduleSummary['has_schedule'])) {
            return true;
        }
        $date = $row['work_date'] ?? '';
        return !empty($scheduleSummary['days'][$date]['is_workday']);
    }));
    if ($absenceSummary['absent_days'] > 0) {
        $absenceSummary['deduction_amt'] = round($dailyAbsenceRate * $absenceSummary['absent_days'], 2);
    }
} catch (Throwable $e) {
    $attendanceRows = [];
}

if ($workerId) {
    try {
        // Calculate hours with daily cap (exclude overtime) - group by day, cap, then sum
        $dailyCapForPerf = $dailyCapHours > 0 ? $dailyCapHours : 8.0;
        
        // First get daily hours grouped by date
        $dailyHoursStmt = $conn->prepare("
            SELECT COALESCE(mo.service_date, mo.date) AS work_date,
                   SUM(COALESCE(mo.net_hours, CASE WHEN owc.cnt>0 THEN mo.hours/owc.cnt ELSE mo.hours END)) AS day_hours
            FROM order_workers ow
            JOIN make_order mo ON mo.id = ow.order_id
            JOIN (SELECT order_id, COUNT(*) cnt FROM order_workers GROUP BY order_id) owc ON owc.order_id = mo.id
            WHERE ow.worker_id = :wid
              AND mo.status IN ('confirmed','completed')
              AND COALESCE(mo.service_date, mo.date) BETWEEN :start AND :end
            GROUP BY COALESCE(mo.service_date, mo.date)
        ");
        $dailyHoursStmt->execute([
            ':wid'   => $workerId,
            ':start' => $monthStart->format('Y-m-d'),
            ':end'   => $monthEnd->format('Y-m-d'),
        ]);
        $dailyHours = $dailyHoursStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Cap each day and sum
        $cappedHours = 0.0;
        foreach ($dailyHours as $day) {
            $dayHours = (float)($day['day_hours'] ?? 0);
            $cappedHours += min($dayHours, $dailyCapForPerf);
        }
        
        // Get other metrics
        $coreStmt = $conn->prepare("
            SELECT
                COUNT(DISTINCT mo.id) AS jobs,
                ROUND(SUM(CASE WHEN owc.cnt>0 THEN mo.grand_total/owc.cnt ELSE 0 END),2) AS revenue,
                COUNT(DISTINCT COALESCE(mo.service_date, mo.date)) AS working_days
            FROM order_workers ow
            JOIN make_order mo ON mo.id = ow.order_id
            JOIN (SELECT order_id, COUNT(*) cnt FROM order_workers GROUP BY order_id) owc ON owc.order_id = mo.id
            WHERE ow.worker_id = :wid
              AND mo.status IN ('confirmed','completed')
              AND COALESCE(mo.service_date, mo.date) BETWEEN :start AND :end
        ");
        // First get daily hours grouped by date
        $dailyHoursStmt = $conn->prepare("
            SELECT COALESCE(mo.service_date, mo.date) AS work_date,
                   SUM(COALESCE(mo.net_hours, CASE WHEN owc.cnt>0 THEN mo.hours/owc.cnt ELSE mo.hours END)) AS day_hours
            FROM order_workers ow
            JOIN make_order mo ON mo.id = ow.order_id
            JOIN (SELECT order_id, COUNT(*) cnt FROM order_workers GROUP BY order_id) owc ON owc.order_id = mo.id
            WHERE ow.worker_id = :wid
              AND mo.status IN ('confirmed','completed')
              AND COALESCE(mo.service_date, mo.date) BETWEEN :start AND :end
            GROUP BY COALESCE(mo.service_date, mo.date)
        ");
        $dailyHoursStmt->execute([
            ':wid'   => $workerId,
            ':start' => $monthStart->format('Y-m-d'),
            ':end'   => $monthEnd->format('Y-m-d'),
        ]);
        $dailyHours = $dailyHoursStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Cap each day and sum
        $cappedHours = 0.0;
        foreach ($dailyHours as $day) {
            $dayHours = (float)($day['day_hours'] ?? 0);
            $cappedHours += min($dayHours, $dailyCapForPerf);
        }
        
        $coreStmt->execute([
            ':wid'   => $workerId,
            ':start' => $monthStart->format('Y-m-d'),
            ':end'   => $monthEnd->format('Y-m-d'),
        ]);
        $core = $coreStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $performanceData['jobs']          = (int)($core['jobs'] ?? 0);
        $performanceData['worked_hours']  = round($cappedHours, 2); // Use capped hours (overtime excluded)
        $performanceData['revenue']       = round((float)($core['revenue'] ?? 0), 2);
        $performanceData['approved_days'] = (int)($core['working_days'] ?? 0);
        if ($performanceData['approved_days'] > 0) {
            $performanceData['avg_hours'] = round($performanceData['worked_hours'] / $performanceData['approved_days'], 2);
        }

        $dayStmt = $conn->prepare("
            SELECT COALESCE(mo.service_date, mo.date) AS work_date,
                   ROUND(SUM(COALESCE(mo.net_hours, CASE WHEN owc.cnt>0 THEN mo.hours/owc.cnt ELSE mo.hours END)),2) AS day_hours
            FROM order_workers ow
            JOIN make_order mo ON mo.id = ow.order_id
            JOIN (SELECT order_id, COUNT(*) cnt FROM order_workers GROUP BY order_id) owc ON owc.order_id = mo.id
            WHERE ow.worker_id = :wid
              AND mo.status IN ('confirmed','completed')
              AND COALESCE(mo.service_date, mo.date) BETWEEN :start AND :end
            GROUP BY COALESCE(mo.service_date, mo.date)
        ");
        $dayStmt->execute([
            ':wid'   => $workerId,
            ':start' => $monthStart->format('Y-m-d'),
            ':end'   => $monthEnd->format('Y-m-d'),
        ]);
        $days = $dayStmt->fetchAll(PDO::FETCH_ASSOC);
        $overtimeHours = 0.0;
        foreach ($days as $day) {
            $dayHours = (float)($day['day_hours'] ?? 0);
            if ($dayHours > $otThreshold) {
                $overtimeHours += ($dayHours - $otThreshold);
            }
        }
        $performanceData['overtime_hours'] = round($overtimeHours, 2);

        $recentJobsStmt = $conn->prepare("
            SELECT mo.id,
                   COALESCE(mo.service_date, mo.date) AS date,
                   mo.client_name,
                   ROUND(COALESCE(mo.net_hours, CASE WHEN owc.cnt>0 THEN mo.hours/owc.cnt ELSE 0 END),2) AS hours_worked,
                   ROUND(CASE WHEN owc.cnt>0 THEN mo.grand_total/owc.cnt ELSE 0 END,2) AS revenue
            FROM order_workers ow
            JOIN make_order mo ON mo.id = ow.order_id
            JOIN (SELECT order_id, COUNT(*) cnt FROM order_workers GROUP BY order_id) owc ON owc.order_id = mo.id
            WHERE ow.worker_id = :wid
              AND mo.status IN ('confirmed','completed')
              AND COALESCE(mo.service_date, mo.date) BETWEEN :start AND :end
            ORDER BY COALESCE(mo.service_date, mo.date) DESC, mo.id DESC
            LIMIT 5
        ");
        $recentJobsStmt->execute([
            ':wid'   => $workerId,
            ':start' => $monthStart->format('Y-m-d'),
            ':end'   => $monthEnd->format('Y-m-d'),
        ]);
    $recentJobs = $recentJobsStmt->fetchAll(PDO::FETCH_ASSOC);

    $absenceStmt = $conn->prepare("
        SELECT work_date, status, hours
        FROM attendance
        WHERE employee_id = :eid
          AND work_date BETWEEN :start AND :end
        ORDER BY work_date DESC
        LIMIT 20
    ");
    $absenceStmt->execute([
        ':eid'   => $emp['id'],
        ':start' => $monthStart->format('Y-m-d'),
        ':end'   => $monthEnd->format('Y-m-d'),
    ]);

    $attendanceRows = $absenceStmt->fetchAll(PDO::FETCH_ASSOC);
    $absenceSummary = [
        'absent_days'   => 0,
        'deduction_amt' => 0.0,
    ];

    if (!function_exists('calculate_absence_daily_rate')) {
        function calculate_absence_daily_rate(array $employee, ?array $worker = null): float {
            $monthlySalary = 0.0;
            if ($worker && isset($worker['total_salary']) && $worker['total_salary'] !== null) {
                $monthlySalary = (float)$worker['total_salary'];
            } elseif (isset($employee['total_salary']) && $employee['total_salary'] !== null) {
                $monthlySalary = (float)$employee['total_salary'];
            } else {
                $monthlySalary = (float)($employee['basic_salary'] ?? 0) + (float)($employee['allowance'] ?? 0);
            }
            if ($monthlySalary <= 0) return 0.0;
            return round($monthlySalary / 30, 2);
        }
    }

    $dailyAbsenceRate = calculate_absence_daily_rate($emp, $workerRow ?? []);

    $absenceSummary['absent_days'] = count(array_filter($attendanceRows, fn($row) => strtolower($row['status'] ?? '') === 'absent'));
    if ($absenceSummary['absent_days'] > 0) {
        $absenceSummary['deduction_amt'] = round($dailyAbsenceRate * $absenceSummary['absent_days'], 2);
    }

    $streakStmt = $conn->prepare("
        SELECT work_date, status
        FROM attendance
        WHERE employee_id = :eid
          AND work_date <= CURDATE()
        ORDER BY work_date DESC
        LIMIT 60
    ");
    $streakStmt->execute([':eid' => $emp['id']]);
    $streakRows = $streakStmt->fetchAll(PDO::FETCH_ASSOC);
    $presentStatuses = ['present','attendance','worked','late','ontime','on time','halfday','half-day'];
    $neutralStatuses = ['off','holiday','rest','weekend','leave'];
    foreach ($streakRows as $row) {
        $status = strtolower(trim($row['status'] ?? ''));
        if (in_array($status, $neutralStatuses, true)) {
            continue;
        }
        if (in_array($status, $presentStatuses, true)) {
            $attendanceStreakDays++;
            if ($attendanceStreakSince === null) {
                $attendanceStreakSince = $row['work_date'];
            }
        } else {
            break;
        }
    }
    } catch (Throwable $e) {
        // silently fail – cards will remain zero
    }
}

// Get per-worker target from perf_worker_targets table for the current period
// This matches the logic in perf_settings.php and performance.php
$targetHours = null;
if ($workerId) {
    try {
        $targetStmt = $conn->prepare("
            SELECT hours_target
            FROM perf_worker_targets
            WHERE worker_id = :wid
              AND period_from <= :end
              AND period_to >= :start
            ORDER BY period_from DESC
            LIMIT 1
        ");
        $targetStmt->execute([
            ':wid'   => $workerId,
            ':start' => $monthStart->format('Y-m-d'),
            ':end'   => $monthEnd->format('Y-m-d'),
        ]);
        $targetRow = $targetStmt->fetch(PDO::FETCH_ASSOC);
        if ($targetRow && $targetRow['hours_target'] !== null) {
            $targetHours = (float)$targetRow['hours_target'];
        }
    } catch (Throwable $e) {
        // Silently fail, will fall back to capacity calculation
    }
}

// Fall back to capacity calculation if no per-worker target is set
if ($targetHours === null) {
    if ($weeklyCapHours > 0) {
        $targetHours = $weeklyCapHours * 4.33;
    } elseif ($dailyCapHours > 0) {
        $targetHours = $dailyCapHours * 26;
    }
}

if ($targetHours !== null) {
    $performanceData['target_hours'] = round($targetHours, 2);
    if ($targetHours > 0) {
        $performanceData['achievement_pct'] = round(($performanceData['worked_hours'] / $targetHours) * 100, 1);
    }
}

$progressTargetHours = $employeeOfMonthTargetHoursSetting > 0 ? $employeeOfMonthTargetHoursSetting : null;
$progressActualHours = (float)$performanceData['worked_hours'];
$progressPercent = null;
if ($progressTargetHours && $progressTargetHours > 0) {
    $progressPercent = min(100, round(($progressActualHours / $progressTargetHours) * 100, 1));
}

$showProgressCard = $profileFeatureFlags['progress'] && $progressTargetHours;
$showRewardsCard = $profileFeatureFlags['rewards'] && $latestAwardForEmployee;
$showHistoryCard = $profileFeatureFlags['history'] && !empty($awardHistory);
$showKudosCard = $profileFeatureFlags['kudos'];
$showStatsColumn = $profileFeatureFlags['stats'];
$showRecognitionRow = ($employeeOfMonthBanner && $employeeOfMonthData)
    || $showProgressCard
    || $showKudosCard
    || $showRewardsCard
    || $showHistoryCard
    || $showStatsColumn;

$payslipRows = [];
try {
    $payslipRows = employee_view_load_payslips($conn, (int)$emp['id']);
} catch (Throwable $e) {
    $payslipRows = [];
}

$topWorkers = [];
try {
    $topStmt = $conn->prepare("
        SELECT
            w.id,
            COALESCE(NULLIF(w.nickname,''), w.worker_name) AS worker_name,
            COALESCE(e.employee_code, w.emp_num) AS employee_code,
            ROUND(SUM(COALESCE(mo.net_hours,0)),2) AS hours_worked
        FROM order_workers ow
        JOIN workers w ON w.id = ow.worker_id
        JOIN make_order mo ON mo.id = ow.order_id
        LEFT JOIN employees e ON e.employee_code = w.emp_num
        JOIN (SELECT order_id, COUNT(*) cnt FROM order_workers GROUP BY order_id) owc ON owc.order_id = mo.id
        WHERE mo.status IN ('confirmed','completed')
          AND mo.date BETWEEN :start AND :end
        GROUP BY w.id, worker_name, employee_code
        ORDER BY hours_worked DESC, worker_name
        LIMIT 5
    ");
    $topStmt->execute([
        ':start' => $monthStart->format('Y-m-d'),
        ':end'   => $monthEnd->format('Y-m-d'),
    ]);
    $topWorkers = $topStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $topWorkers = [];
}

$pageTitle = 'Employee profile';
$pageStyles = <<<'HR_EMP_VIEW_CSS'
.employee-page{
        --primary:#b8860b;
        --hr-primary:#b8860b;
        --hr-primary-dark:#9a7209;
        --hr-surface:#ffffff;
        --hr-surface-soft:#f8fafc;
        --hr-page:#f6f7f9;
        --hr-border:#e2e8f0;
        --hr-text:#0f172a;
        --hr-muted:#64748b;
        --hr-radius:20px;
        --hr-radius-sm:14px;
        --hr-shadow:0 18px 45px rgba(15,23,42,.08);
        --hr-shadow-sm:0 10px 25px rgba(15,23,42,.06);
    }
    .employee-page{font-size:.94rem;width:100%;max-width:none}
    .card{border:1px solid rgba(226,232,240,.9);border-radius:var(--hr-radius);box-shadow:var(--hr-shadow-sm);overflow:hidden}
    .card-header{background:var(--hr-surface-soft);border-bottom:1px solid var(--hr-border);padding:.95rem 1.1rem}
    .card-body{padding:1.1rem}
    .min-w-0{min-width:0}
    .btn{border-radius:999px;font-weight:650;letter-spacing:-.01em}
    .btn-sm{padding:.35rem .72rem;font-size:.78rem}
    .btn-primary{background:var(--hr-primary);border-color:var(--hr-primary)}
    .btn-primary:hover{background:var(--hr-primary-dark);border-color:var(--hr-primary-dark)}
    .badge{border-radius:999px;font-weight:650;padding:.42em .65em}
    .form-control,.form-select{border-color:#dbe4f0;border-radius:12px}
    .form-control:focus,.form-select:focus{border-color:#d4af37;box-shadow:0 0 0 .2rem rgba(184,134,11,.12)}
    .employee-actionbar{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1rem}
    .employee-actionbar h3{font-weight:800;letter-spacing:-.03em}
    .employee-actions{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;justify-content:flex-end}
    .employee-hero{position:relative;border:0;background:linear-gradient(135deg,#ffffff 0,#fffef9 58%,#eef6ff 100%);box-shadow:var(--hr-shadow)}
    .employee-hero::before{content:"";position:absolute;inset:0 0 auto 0;height:5px;background:linear-gradient(90deg,#b8860b,#d4af37,#059669)}
    .employee-hero-body{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:1.25rem;align-items:center;padding:1.35rem}
    .avatar{width:82px;height:82px;border-radius:24px;background:linear-gradient(135deg,#f5ecd0,#faf3d9);display:inline-flex;align-items:center;justify-content:center;font-size:1.8rem;font-weight:800;color:#9a7209;overflow:hidden;position:relative;box-shadow:inset 0 0 0 1px rgba(184,134,11,.12)}
    .avatar.has-photo{background:#fff;padding:0}
    .avatar img{width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block}
    .avatar-actions{position:absolute;right:-4px;bottom:-4px;display:flex;gap:2px}
    .avatar-action-btn{width:28px;height:28px;border-radius:50%;border:2px solid #fff;background:var(--hr-primary);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:12px;box-shadow:0 6px 14px rgba(184,134,11,.25);transition:all .18s ease}
    .avatar-action-btn:hover{transform:translateY(-1px) scale(1.04)}
    .avatar-action-btn.danger{background:#dc3545}
    .avatar-upload-input{display:none}
    .employee-name{font-size:1.32rem;font-weight:800;letter-spacing:-.035em;margin:0}
    .employee-code-pill{display:inline-flex;align-items:center;gap:.35rem;border-radius:999px;background:rgba(184,134,11,0.12);color:#9a7209;padding:.28rem .62rem;font-size:.78rem;font-weight:750}
    .employee-subtitle{color:var(--hr-muted);font-size:.9rem;margin-top:.28rem}
    .info-chip-row{display:flex;flex-wrap:wrap;gap:.45rem;margin-top:.75rem}
    .info-chip{display:inline-flex;align-items:center;gap:.4rem;border:1px solid var(--hr-border);background:rgba(255,255,255,.78);border-radius:999px;padding:.42rem .68rem;color:#334155;font-size:.82rem;line-height:1.2}
    .info-chip a{color:inherit}
    .employee-hero-side{min-width:190px;text-align:right}
    .joined-card{border-radius:16px;background:rgba(255,255,255,.8);border:1px solid rgba(226,232,240,.9);padding:.85rem}
    .profile-progress-card{margin-top:.75rem}
    .profile-progress-card .progress{height:10px;border-radius:999px;background:#e2e8f0;overflow:hidden}
    .profile-progress-card .progress-bar{font-size:0}
    .inactive-employee-alert{border:1px solid #cbd5e1;background:#f8fafc;border-radius:14px;padding:.65rem .85rem}
    .employee-tabs-wrap{position:sticky;top:0;z-index:20;margin-top:1rem;background:rgba(248,250,252,.92);backdrop-filter:blur(14px);border:1px solid rgba(226,232,240,.9);border-radius:18px;padding:.45rem;box-shadow:var(--hr-shadow-sm);overflow-x:auto;-webkit-overflow-scrolling:touch}
    .employee-tabs-wrap::-webkit-scrollbar{height:6px}
    .employee-tabs-wrap::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:999px}
    .employee-tabs{border:0;display:flex;flex-wrap:nowrap;gap:.4rem;min-width:max-content}
    .employee-tabs .nav-link{border:0;border-radius:999px;color:#475569;font-weight:750;white-space:nowrap;padding:.58rem .92rem}
    .employee-tabs .nav-link:hover{background:rgba(184,134,11,0.10);color:var(--hr-primary)}
    .employee-tabs .nav-link.active{background:var(--hr-primary);color:#fff;box-shadow:0 10px 24px rgba(184,134,11,.22)}
    .tab-pane{padding-top:1rem}
    .section-toolbar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin:1rem 0 .65rem}
    .section-toolbar h6,.card-title{font-weight:800;letter-spacing:-.02em}
    .tab-pane > .d-flex.justify-content-between.align-items-center.mt-3,
    .tab-pane > .d-flex.align-items-center.mt-3{background:#fff;border:1px solid var(--hr-border);border-radius:16px;padding:.8rem 1rem;box-shadow:var(--hr-shadow-sm)}
    .stat-card{height:100%;border:0;background:linear-gradient(180deg,#fff,#fffef9)}
    .stat-card .card-body{display:flex;flex-direction:column;min-height:190px}
    .stat-icon{width:42px;height:42px;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;background:rgba(184,134,11,0.12);color:var(--hr-primary);font-size:1.2rem;margin-bottom:.75rem}
    .stat-label{color:var(--hr-muted);font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em}
    .stat-value{font-size:1.85rem;font-weight:850;letter-spacing:-.05em;line-height:1.1}
    .stat-meta{color:var(--hr-muted);font-size:.82rem}
    .panel-card{height:100%}
    .panel-list{display:flex;flex-direction:column;gap:.6rem}
    .panel-list-item{border:1px solid var(--hr-border);border-radius:14px;background:#fff;padding:.72rem}
    .empty-state{border:1px dashed #cbd5e1;background:#f8fafc;border-radius:16px;padding:1.1rem;text-align:center;color:var(--hr-muted)}
    .empty-state i{display:block;font-size:1.4rem;margin-bottom:.35rem;color:#94a3b8}
    .list-group .text-center.text-muted.py-3,
    .card-body > .text-center.text-muted.py-3{border:1px dashed #cbd5e1;background:#f8fafc;border-radius:16px;padding:1.15rem!important}
    .table-responsive{border:1px solid var(--hr-border);border-radius:16px;background:#fff;box-shadow:0 1px 0 rgba(15,23,42,.03);overflow:auto}
    .table{margin-bottom:0}
    .table td,.table th{vertical-align:middle}
    .table thead th{background:#f8fafc!important;color:#475569;font-size:.76rem;text-transform:uppercase;letter-spacing:.055em;border-bottom:1px solid var(--hr-border);white-space:nowrap}
    .table tbody tr:hover{background:#fffef9}
    .table td{border-color:#eef2f7}
    .table td.text-center.text-muted{padding:1.15rem!important;background:#fbfdff}
    .list-group-item{border-color:#eef2f7}
    .timeline-item{position:relative;padding-left:20px;border-radius:14px}
    .timeline-item::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--primary);border-radius:999px}
    .timeline-item:last-child::before{display:none}
    .celebration-banner{background:linear-gradient(120deg,#fff7ed,#fff1f2);border-radius:var(--hr-radius);padding:1rem 1.15rem;box-shadow:var(--hr-shadow-sm);border:1px solid rgba(251,146,60,.25);display:flex;align-items:center;gap:1rem;margin-bottom:1rem;position:relative;overflow:hidden}
    .celebration-banner::after{content:"";position:absolute;right:-28px;bottom:-42px;width:120px;height:120px;border-radius:50%;background:rgba(251,146,60,.14);pointer-events:none}
    .celebration-avatar{width:60px;height:60px;border-radius:20px;background:#fff;display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:#ea580c;box-shadow:0 8px 18px rgba(15,23,42,.08);flex-shrink:0;overflow:hidden;font-weight:800}
    .celebration-avatar img{width:100%;height:100%;object-fit:cover;border-radius:inherit}
    .celebration-title{font-weight:800;font-size:1.08rem;margin:0;color:#9a3412}
    .celebration-text{margin:.25rem 0 0;font-size:.93rem;color:#7c2d12}
    .celebration-badge{display:inline-flex;align-items:center;gap:.4rem;background:#fff;border-radius:999px;padding:.35rem .65rem;font-size:.78rem;font-weight:800;color:#ea580c;margin-bottom:.35rem;box-shadow:0 6px 14px rgba(234,88,12,.13)}
    .recognition-row{display:flex;flex-direction:column;gap:1rem;margin-bottom:1rem}
    @media (min-width:1200px){.recognition-row{flex-direction:row;align-items:stretch}}
    .recognition-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:.75rem}
    .recognition-side{width:100%;display:flex;flex-direction:row;gap:.75rem;flex-wrap:wrap}
    @media (min-width:1200px){.recognition-side{flex-direction:column;width:270px}}
    .snapshot-card{flex:1 1 150px;border-radius:16px;padding:1rem;background:linear-gradient(135deg,#ffffff,#f5f8ff);box-shadow:var(--hr-shadow-sm);border:1px solid #e7edff}
    .snapshot-label{font-size:.72rem;color:var(--hr-muted);font-weight:800;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.35rem}
    .snapshot-value{font-size:1.65rem;font-weight:850;color:var(--hr-text);line-height:1.15;letter-spacing:-.04em}
    .snapshot-value small{font-size:.86rem;font-weight:600;color:#475569;margin-left:.25rem}
    .snapshot-meta{font-size:.8rem;color:#94a3b8}
    .progress-card,.kudos-card,.reward-card{border-radius:16px;padding:1rem;box-shadow:var(--hr-shadow-sm)}
    .progress-card{background:#fffbea;border:1px solid #fde68a}
    .progress-card .progress{height:8px;border-radius:999px;background:rgba(245,158,11,.22)}
    .kudos-card{background:#f5f7ff;border:1px solid #dbe4ff}
    .kudos-entry{display:flex;flex-direction:column;gap:.25rem;border-radius:12px;padding:.75rem;background:#fff;box-shadow:0 1px 8px rgba(15,23,42,.06);margin-bottom:.5rem}
    .kudos-entry:last-child{margin-bottom:0}
    .kudos-entry small{color:var(--hr-muted);font-size:.8rem}
    .reward-card{background:#ecfdf3;border:1px solid #bbf7d0}
    .reward-list{list-style:none;padding-left:0;margin:0}
    .reward-list li{display:flex;align-items:center;gap:.55rem;padding:.55rem 0;border-bottom:1px solid rgba(15,23,42,.08)}
    .reward-list li:last-child{border-bottom:0}
    .history-strip{display:flex;gap:.65rem;overflow-x:auto;padding-bottom:.25rem}
    .history-item{min-width:170px;border-radius:14px;padding:.8rem;background:#fff;border:1px solid var(--hr-border);box-shadow:var(--hr-shadow-sm)}
    @media (max-width:767.98px){
        body{font-size:.9rem}
        /* gutters come from .hr-content */
        .employee-actionbar{align-items:flex-start;flex-direction:column}
        .employee-actions{width:100%;justify-content:flex-start}
        .employee-actions .btn,.employee-actions form{flex:1 1 auto}
        .employee-actions .btn{width:100%}
        .employee-hero-body{grid-template-columns:1fr;gap:1rem;text-align:center;padding:1rem}
        .avatar{width:76px;height:76px;margin:0 auto;border-radius:22px}
        .employee-hero-side{text-align:center;min-width:0}
        .info-chip-row{justify-content:center}
        .joined-card{display:inline-block;min-width:220px}
        .employee-tabs-wrap{border-radius:16px;margin-left:-.1rem;margin-right:-.1rem}
        .employee-tabs .nav-link{padding:.55rem .82rem}
        .section-toolbar{align-items:flex-start;flex-direction:column}
        .stat-value{font-size:1.55rem}
        .table-responsive{border-radius:14px}
    }
    @media print {
      .employee-tabs-wrap,.btn,.modal,aside,.page-header-label,.employee-actions{display:none!important}
      .tab-content > .tab-pane{display:block!important;opacity:1!important}
      .card{break-inside:avoid;page-break-inside:avoid;box-shadow:none}
      body{background:#fff}
    }
HR_EMP_VIEW_CSS;
require_once __DIR__ . '/includes/hr_layout_header.php';
?>
<div class="employee-page">

    <?php if ($avatarErr): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($avatarErr) ?></div>
    <?php elseif ($avatarMsg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($avatarMsg) ?></div>
    <?php endif; ?>
    <?php if ($showRecognitionRow): ?>
    <div class="recognition-row">
        <div class="recognition-main">
            <?php if ($employeeOfMonthBanner && $employeeOfMonthData): ?>
            <div class="celebration-banner">
                <div class="celebration-avatar">
                    <?php if (!empty($employeeOfMonthData['avatar_path']) && file_exists(__DIR__ . '/../' . $employeeOfMonthData['avatar_path'])): ?>
                        <img src="../<?= htmlspecialchars($employeeOfMonthData['avatar_path']) ?>" alt="<?= htmlspecialchars($employeeOfMonthData['full_name'] ?? '') ?>">
                    <?php else: ?>
                        <?= htmlspecialchars($employeeOfMonthInitial ?: '⭐') ?>
                    <?php endif; ?>
                </div>
                <div class="flex-grow-1">
                    <div class="celebration-badge">
                        <i class="bi bi-trophy-fill"></i>
                        <?= htmlspecialchars($employeeOfMonthHeadline) ?>
                        <?php if ($isEmployeeOfMonthSelf): ?>
                            <span class="ms-1 badge bg-warning text-dark">That's you!</span>
                        <?php endif; ?>
                    </div>
                    <p class="celebration-title mb-1"><?= htmlspecialchars($employeeOfMonthData['full_name'] ?? '') ?></p>
                    <p class="celebration-text mb-0"><?= nl2br(htmlspecialchars($employeeOfMonthBanner)) ?></p>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($showProgressCard): ?>
            <div class="progress-card">
                <div class="d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-graph-up-arrow me-2"></i>Next Award Progress</strong>
                    <?php if ($progressPercent !== null): ?>
                        <span class="fw-semibold"><?= number_format($progressPercent, 1) ?>%</span>
                    <?php endif; ?>
                </div>
                <div class="progress my-2">
                    <div class="progress-bar bg-warning" role="progressbar"
                         style="width: <?= $progressPercent !== null ? $progressPercent : 0 ?>%"
                         aria-valuenow="<?= $progressPercent !== null ? $progressPercent : 0 ?>"
                         aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <div class="small text-muted">
                    Target <?= number_format($progressTargetHours, 2) ?> hrs • Current <?= number_format($progressActualHours, 2) ?> hrs
                </div>
            </div>
            <?php endif; ?>

            <?php if ($showKudosCard): ?>
            <div class="kudos-card">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0"><i class="bi bi-chat-heart-fill me-2"></i>Peer Kudos</h6>
                    <?php if (!$workerReadOnly): ?>
                        <a href="../settings" class="small text-decoration-none" data-tab="emp_profile">Manage</a>
                    <?php endif; ?>
                </div>
                <?php if ($kudosFeed): ?>
                    <?php foreach (array_slice($kudosFeed, 0, 3) as $kudos): ?>
                    <div class="kudos-entry">
                        <div class="fw-semibold"><?= nl2br(htmlspecialchars($kudos['message'])) ?></div>
                        <small>
                            <?= htmlspecialchars(date('M j, g:i A', strtotime($kudos['created_at']))) ?>
                            • <?= htmlspecialchars($kudos['author_name'] ?: $kudos['author_username'] ?: 'Team') ?>
                            <?php if (($kudos['visibility'] ?? 'public') === 'private' && !$workerReadOnly): ?>
                                <span class="badge bg-secondary ms-2">Private</span>
                            <?php endif; ?>
                        </small>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted mb-0">No kudos yet. Celebrate a teammate from the settings page.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($showRewardsCard): ?>
            <div class="reward-card">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0"><i class="bi bi-gift-fill me-2"></i>Reward Tracker</h6>
                    <span class="badge bg-success-subtle text-success"><?= htmlspecialchars(date('M Y', strtotime($latestAwardForEmployee['award_month']))) ?></span>
                </div>
                <?php if ($latestAwardChecklists): ?>
                <ul class="reward-list">
                    <?php foreach ($latestAwardChecklists as $item): ?>
                    <?php $done = ((int)$item['is_done'] === 1); ?>
                    <li>
                        <i class="bi <?= $done ? 'bi-check-circle-fill text-success' : 'bi-hourglass-split text-muted' ?>"></i>
                        <div class="flex-grow-1">
                            <strong><?= htmlspecialchars($item['item_label']) ?></strong>
                            <div class="small text-muted">
                                <?= $done ? 'Completed' : 'Pending' ?>
                            </div>
                        </div>
                        <span class="badge <?= $done ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>">
                            <?= $done ? 'Done' : 'Next up' ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                    <p class="text-muted mb-0">Checklist coming soon. Admins can set it up from Emp Profile Settings.</p>
                <?php endif; ?>
                <?php if (!$workerReadOnly): ?>
                    <div class="text-end mt-2">
                        <a href="../settings" class="small text-decoration-none" data-tab="emp_profile">Update checklist</a>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($showHistoryCard): ?>
            <div class="card border-0 shadow-sm p-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Award History</h6>
                    <?php if (!$workerReadOnly): ?>
                        <a href="../settings" class="small text-decoration-none" data-tab="emp_profile">Manage</a>
                    <?php endif; ?>
                </div>
                <div class="history-strip">
                    <?php foreach ($awardHistory as $history): ?>
                    <div class="history-item">
                        <span class="badge bg-warning-subtle text-warning-emphasis mb-2"><?= htmlspecialchars(date('M Y', strtotime($history['award_month']))) ?></span>
                        <strong><?= htmlspecialchars($history['full_name']) ?></strong>
                        <?php if (!empty($history['headline'])): ?>
                            <div class="small text-muted"><?= htmlspecialchars($history['headline']) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($showStatsColumn): ?>
        <div class="recognition-side">
            <div class="snapshot-card">
                <div class="snapshot-label">Hours Completed</div>
                <div class="snapshot-value">
                    <?= number_format($performanceData['worked_hours'], 2) ?><small>hrs</small>
                </div>
                <div class="snapshot-meta">
                    <?= $progressTargetHours ? 'Target ' . number_format($progressTargetHours, 0) . ' hrs' : 'This month' ?>
                </div>
            </div>
            <div class="snapshot-card">
                <div class="snapshot-label">Leave Balance</div>
                <div class="snapshot-value">
                    <?= number_format($totalLeaveBalance, 1) ?><small>days</small>
                </div>
                <div class="snapshot-meta">Across all leave types</div>
            </div>
            <div class="snapshot-card">
                <div class="snapshot-label">Attendance Streak</div>
                <div class="snapshot-value">
                    <?= $attendanceStreakDays > 0 ? (int)$attendanceStreakDays : 0 ?><small>days</small>
                </div>
                <div class="snapshot-meta">
                    <?= $attendanceStreakDays > 0 && $attendanceStreakSince
                        ? 'Since ' . htmlspecialchars(date('M j', strtotime($attendanceStreakSince)))
                        : 'Start logging shifts' ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="employee-actionbar">
        <div>
            <?php if (!empty($_SESSION['flash_success'])): ?>
              <div class="alert alert-success mb-2"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
              <?php unset($_SESSION['flash_success']); ?>
            <?php endif; ?>
            <div class="text-muted small fw-semibold text-uppercase">HR Module</div>
            <h3 class="mb-0">Employee Profile</h3>
        </div>
        <div class="employee-actions">
            <?php if ($isWorkerSelfService): ?>
            <a class="btn btn-outline-secondary" href="<?= get_base_path() ? get_base_path() . '/' : '/' ?>dashboard.php">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
            <?php else: ?>
            <a class="btn btn-outline-secondary" href="<?= get_base_path() ? get_base_path() . '/' : '/' ?>employees.php">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
            <?php endif; ?>
            <a href="../profile.php?tab=security" class="btn btn-outline-primary">
                <i class="bi bi-shield-lock me-1"></i>Change Password
            </a>
            <a href="../logout.php" class="btn btn-danger">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
            <?php if (!$workerReadOnly): ?>
                <a class="btn btn-primary" href="employee_edit?id=<?= urlencode($emp['id']) ?>">
                    <i class="bi bi-pencil-square me-1"></i>Edit
                </a>
            <?php if (empty($emp['user_id'])): ?>
              <form action="user_create_for_employee" method="post" class="d-inline m-0">
                <?php csrf_field(); ?>
                <input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>">
                <button class="btn btn-outline-primary">
                    <i class="bi bi-person-plus me-1"></i>Create Login
                </button>
              </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($contact_msg): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($contact_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($contact_err): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($contact_err) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card employee-hero mb-4">
        <div class="employee-hero-body">
            <div class="avatar<?= $employeeAvatarPath ? ' has-photo' : '' ?>">
                <?php if ($employeeAvatarPath): ?>
                    <img src="../<?= htmlspecialchars($employeeAvatarPath) ?>" alt="<?= htmlspecialchars($emp['full_name'] ?: $emp['employee_code']) ?>">
                <?php else: ?>
                    <?= htmlspecialchars($avatarInitial) ?>
                <?php endif; ?>
                <?php if ($canManageAvatar && !empty($emp['user_id'])): ?>
                    <div class="avatar-actions">
                        <form method="post" enctype="multipart/form-data" class="d-inline" id="avatarUploadForm" style="display:inline;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="employee_avatar_upload" value="1">
                            <input type="file" name="employee_avatar" accept="image/png,image/jpeg" class="avatar-upload-input" id="avatarFileInput" required>
                            <button type="button" class="avatar-action-btn" onclick="document.getElementById('avatarFileInput').click()" title="Upload/Change Photo">
                                <i class="bi bi-camera-fill"></i>
                            </button>
                        </form>
                        <?php if ($employeeAvatarPath): ?>
                            <form method="post" class="d-inline" id="avatarDeleteForm" style="display:inline;" onsubmit="return confirm('Remove this photo?')">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="employee_avatar_delete" value="1">
                                <button type="submit" class="avatar-action-btn danger" title="Remove Photo">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php elseif ($canManageAvatar && empty($emp['user_id'])): ?>
                    <div class="avatar-actions">
                        <span class="avatar-action-btn" style="background:#6c757d; cursor:default;" title="Create a login to enable photo uploads" data-bs-toggle="tooltip">
                            <i class="bi bi-lock-fill"></i>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="min-w-0">
                <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                    <h5 class="employee-name"><?= htmlspecialchars($emp['full_name'] ?: $emp['employee_code']) ?></h5>
                    <span class="employee-code-pill">
                        <i class="bi bi-person-vcard"></i><?= htmlspecialchars($emp['employee_code']) ?>
                    </span>
                </div>
                <div class="employee-subtitle">
                    <?= htmlspecialchars($emp['position_title'] ?: 'No position') ?>
                    <span class="mx-1">/</span><?= htmlspecialchars($emp['dept_name'] ?: 'No department') ?>
                    <span class="mx-1">/</span><?= htmlspecialchars($emp['company_name'] ?: 'No company') ?>
                </div>

                <?php if (!hr_employee_is_current_status($emp['status'] ?? '')): ?>
                <div class="inactive-employee-alert mt-3 small">
                    This employee is marked as <strong><?= htmlspecialchars(hr_employee_status_label($emp['status'] ?? 'inactive')) ?></strong>.
                    <?php if (!empty($emp['last_working_day']) || !empty($emp['exit_date'])): ?>
                        Last day: <?= htmlspecialchars($emp['last_working_day'] ?: $emp['exit_date']) ?>.
                    <?php endif; ?>
                    <?php if (!empty($emp['final_settlement_status'])): ?>
                        Final settlement: <?= htmlspecialchars(hr_employee_settlement_options()[$emp['final_settlement_status']] ?? $emp['final_settlement_status']) ?>.
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="info-chip-row">
                    <span class="info-chip"><i class="bi bi-building"></i><?= htmlspecialchars($emp['loc_name'] ?: 'No location') ?></span>
                    <?php if (!empty($emp['email'])): ?>
                        <span class="info-chip">
                            <i class="bi bi-envelope"></i>
                            <?php if ($isWorkerSelfService): ?>
                                <?= htmlspecialchars($emp['email']) ?>
                            <?php else: ?>
                                <a href="mailto:<?= htmlspecialchars($emp['email']) ?>" class="text-decoration-none"><?= htmlspecialchars($emp['email']) ?></a>
                            <?php endif; ?>
                        </span>
                    <?php else: ?>
                        <span class="info-chip text-muted"><i class="bi bi-envelope-slash"></i>No email</span>
                    <?php endif; ?>
                    <?php if (!empty($emp['phone'])): ?>
                        <span class="info-chip">
                            <i class="bi bi-phone"></i>
                            <?php if ($isWorkerSelfService): ?>
                                <?= htmlspecialchars($emp['phone']) ?>
                            <?php else: ?>
                                <a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $emp['phone'])) ?>" class="text-decoration-none"><?= htmlspecialchars($emp['phone']) ?></a>
                            <?php endif; ?>
                        </span>
                    <?php else: ?>
                        <span class="info-chip text-muted"><i class="bi bi-telephone-x"></i>No phone</span>
                    <?php endif; ?>
                    <?php if ($isWorkerSelfService): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editContactInfoModal" title="Edit Email & Phone">
                            <i class="bi bi-pencil-square me-1"></i>Edit Contact
                        </button>
                    <?php endif; ?>
                    <?php if (!empty($emp['phone_work'])): ?>
                        <span class="info-chip" title="Work Phone"><i class="bi bi-telephone"></i><?= htmlspecialchars($emp['phone_work']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($emp['phone_personal'])): ?>
                        <span class="info-chip" title="Personal Phone"><i class="bi bi-person-lines-fill"></i><?= htmlspecialchars($emp['phone_personal']) ?></span>
                    <?php endif; ?>
                    <?php if ($reportingManager): ?>
                        <span class="info-chip">
                            <i class="bi bi-person-badge"></i>Reports to:
                            <a href="employee_view.php?id=<?= (int)$reportingManager['id'] ?>" class="text-decoration-none">
                                <?= htmlspecialchars($reportingManager['full_name']) ?>
                            </a>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($emp['address'])): ?>
                        <span class="info-chip"><i class="bi bi-geo-alt"></i><?= nl2br(htmlspecialchars($emp['address'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="employee-hero-side">
                <?php
                  $st = strtolower($emp['status'] ?? 'inactive');
                  $badgeClass = hr_employee_status_badge($st);
                ?>
                <div class="joined-card">
                    <div class="small text-muted">Joined</div>
                    <div class="fw-bold"><?= htmlspecialchars($emp['date_joined'] ?: '—') ?></div>
                    <div class="mt-2">
                      <span class="badge text-bg-<?= $badgeClass ?>"><?= htmlspecialchars(hr_employee_status_label($st)) ?></span>
                    </div>
                    <div class="profile-progress-card">
                        <div class="d-flex justify-content-between align-items-center small text-muted mb-1">
                            <span>Profile Completion</span>
                            <strong><?= $profileCompletionPercent ?>%</strong>
                        </div>
                        <div class="progress">
                            <div class="progress-bar <?= $profileCompletionPercent >= 100 ? 'bg-success' : ($profileCompletionPercent >= 75 ? 'bg-info' : ($profileCompletionPercent >= 50 ? 'bg-warning' : 'bg-danger')) ?>" 
                                 role="progressbar" 
                                 style="width: <?= $profileCompletionPercent ?>%" 
                                 aria-valuenow="<?= $profileCompletionPercent ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100"
                                 data-bs-toggle="tooltip" 
                                 data-bs-placement="left" 
                                 title="<?= $profileCompleted ?>/<?= $profileTotal ?> fields completed"></div>
                        </div>
                        <?php if ($profileCompletionPercent < 100 && !$workerReadOnly): ?>
                        <div class="mt-2">
                            <a href="employee_edit.php?id=<?= (int)$emp['id'] ?>" class="btn btn-sm btn-outline-primary">Complete Profile</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile friendly horizontal tab bar. Tab IDs remain unchanged for existing JS/hash links. -->
    <div class="employee-tabs-wrap">
    <ul class="nav nav-tabs employee-tabs" id="empTabs" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button">Overview</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-docs" type="button">Documents</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-leave" type="button">Leave</button></li>
        <?php if (!$workerReadOnly): ?>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-overtime" type="button">Overtime</button></li>
        <?php endif; ?>
        <li class="nav-item">
          <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-cashadv" type="button">Loans / Advances</button>
        </li>
        <?php if (!$workerReadOnly): ?>
        <li class="nav-item">
          <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-deduct" type="button">Deductions</button>
        </li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-assets" type="button">Assets</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-training" type="button">Training</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-performance" type="button">Performance</button></li>
        <?php endif; ?>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-attendance" type="button">Attendance</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-payslips" type="button">Payslips</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-emergency" type="button">Emergency Contacts</button></li>
        <?php if (!$workerReadOnly): ?>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-notes" type="button">Notes</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-history" type="button">Employment History</button></li>
        <?php endif; ?>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-schedule" type="button">Schedule</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-activity" type="button">Activity Log</button></li>
        <?php if (!$workerReadOnly): ?>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-topworkers" type="button">Top Workers</button></li>
        <?php endif; ?>
    </ul>
    </div>

    <div class="tab-content">
        <!-- Overview -->
        <div class="tab-pane fade show active" id="tab-overview">
            <?php 
            $overviewLeaveBalance = 0;
            if (isset($balances) && is_array($balances)) {
                foreach ($balances as $lb) {
                    $typeName = strtolower(trim($lb['type_name'] ?? ''));
                    if (strpos($typeName, 'annual') !== false && strpos($typeName, 'sick') === false) {
                        $overviewLeaveBalance += (float)($lb['closing'] ?? 0);
                    }
                }
            }
            ?>
            <div class="row g-3 mt-1">
                <div class="col-sm-6 col-xl-3">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Date range selector">
                                    <input type="radio" class="btn-check" name="hoursDateRange" id="hoursCurrentMonth" value="current_month" <?= $overviewDateRange === 'current_month' ? 'checked' : '' ?> onchange="changeOverviewDateRange(this.value)">
                                    <label class="btn btn-outline-primary" for="hoursCurrentMonth">This</label>
                                    <input type="radio" class="btn-check" name="hoursDateRange" id="hoursLastMonth" value="last_month" <?= $overviewDateRange === 'last_month' ? 'checked' : '' ?> onchange="changeOverviewDateRange(this.value)">
                                    <label class="btn btn-outline-primary" for="hoursLastMonth">Last</label>
                                </div>
                            </div>
                            <div class="stat-label">Hours Worked</div>
                            <div class="stat-value text-primary">
                                <?= number_format($hoursThisMonth, 1) ?>
                                <?php if ($hoursTrendDirection && $hoursPreviousPeriod > 0): ?>
                                    <span class="<?= $hoursTrendClass ?> fs-6" title="Compared to previous period: <?= number_format($hoursPreviousPeriod, 1) ?> hours">
                                        <?= $hoursTrendDirection ?> <?= number_format(abs($hoursTrend), 1) ?>%
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="stat-meta">
                                <?= htmlspecialchars($hoursDateLabel) ?>
                                <?php if ($hoursPreviousPeriod > 0): ?>
                                    <br>Previous: <?= number_format($hoursPreviousPeriod, 1) ?> hrs
                                <?php endif; ?>
                            </div>
                            <div class="mt-auto pt-3">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="switchToTab('tab-performance')">View Details</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="stat-icon"><i class="bi bi-calendar2-check"></i></div>
                            <div class="stat-label">
                                Leave Balance
                                <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" data-bs-placement="top" title="Annual Leave balance only (Sick Leave excluded)"></i>
                            </div>
                            <div class="stat-value text-info"><?= number_format($overviewLeaveBalance, 1) ?><span class="fs-6"> days</span></div>
                            <div class="stat-meta">Available annual leave balance</div>
                            <div class="mt-auto pt-3">
                                <button type="button" class="btn btn-sm btn-outline-info" onclick="switchToTab('tab-leave')">View Details</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
                            <div class="stat-label">
                                Pending Requests
                                <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" data-bs-placement="top" title="Total pending leave and cash advance requests awaiting approval"></i>
                            </div>
                            <div class="stat-value text-warning"><?= $pendingLeaveRequests + $pendingCashAdvanceRequests ?></div>
                            <div class="stat-meta">
                                <a href="#" onclick="event.preventDefault(); switchToTab('tab-leave'); return false;" class="text-decoration-none text-warning"><?= $pendingLeaveRequests ?> Leave</a>
                                <span class="text-muted mx-1">/</span>
                                <a href="#" onclick="event.preventDefault(); switchToTab('tab-cashadv'); return false;" class="text-decoration-none text-warning"><?= $pendingCashAdvanceRequests ?> Cash Advance</a>
                            </div>
                            <div class="mt-auto pt-3">
                                <button type="button" class="btn btn-sm btn-outline-warning" onclick="switchToTab('tab-leave')">Review Requests</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="stat-icon"><i class="bi bi-file-earmark-medical"></i></div>
                            <div class="stat-label">
                                Docs Expiring Soon
                                <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" data-bs-placement="top" title="Documents expiring within the next 30 days"></i>
                            </div>
                            <div class="stat-value <?= $expiringDocsCount > 0 ? 'text-danger' : 'text-success' ?>"><?= $expiringDocsCount ?></div>
                            <div class="stat-meta"><?= $expiringDocsCount > 0 ? 'Next 30 days' : 'All documents up to date' ?></div>
                            <div class="mt-auto pt-3">
                                <button type="button" class="btn btn-sm <?= $expiringDocsCount > 0 ? 'btn-outline-danger' : 'btn-outline-secondary' ?>" onclick="switchToTab('tab-docs')">
                                    <?= $expiringDocsCount > 0 ? 'Review' : 'View Docs' ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-lg-4">
                    <div class="card panel-card">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <h6 class="card-title mb-0">Attendance Streak</h6>
                                <span class="stat-icon mb-0"><i class="bi bi-calendar-check"></i></span>
                            </div>
                            <div class="stat-value"><?= $attendanceStreak ?><span class="fs-6"> days</span></div>
                            <div class="stat-meta">Consecutive days present</div>
                            <?php if ($bestAttendanceStreak > $attendanceStreak): ?>
                            <div class="stat-meta mt-2"><i class="bi bi-trophy me-1"></i>Best streak: <?= $bestAttendanceStreak ?> days</div>
                            <?php endif; ?>
                            <div class="mt-auto pt-3">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="switchToTab('tab-attendance')">View Attendance</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card panel-card">
                        <div class="card-body d-flex flex-column">
                            <h6 class="card-title mb-3">Upcoming Events</h6>
                            <?php if (empty($upcomingEvents)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-calendar2"></i>
                                    No upcoming events
                                </div>
                            <?php else: ?>
                            <div class="panel-list">
                                <?php foreach (array_slice($upcomingEvents, 0, 3) as $event): 
                                    $eventDate = new DateTime($event['date']);
                                    $daysText = $event['days'] == 0 ? 'Today' : ($event['days'] == 1 ? 'Tomorrow' : $event['days'] . ' days');
                                ?>
                                <div class="panel-list-item">
                                    <div class="d-flex align-items-start gap-2">
                                        <i class="bi <?= $event['icon'] ?> text-<?= $event['color'] ?> mt-1"></i>
                                        <div class="min-w-0">
                                            <div class="fw-semibold small"><?= htmlspecialchars($event['title']) ?></div>
                                            <div class="text-muted small">
                                                <?php if ($event['type'] === 'leave' && isset($event['date_from']) && isset($event['date_to'])): ?>
                                                    <?php 
                                                    $fromDate = new DateTime($event['date_from']);
                                                    $toDate = new DateTime($event['date_to']);
                                                    echo $fromDate->format('M j') . ' - ' . $toDate->format('M j, Y');
                                                    if (isset($event['days_count']) && $event['days_count'] > 0) {
                                                        echo ' (' . $event['days_count'] . ' day' . ($event['days_count'] > 1 ? 's' : '') . ')';
                                                    }
                                                    ?> &middot; <?= $daysText ?>
                                                <?php else: ?>
                                                    <?= $eventDate->format('M j, Y') ?> &middot; <?= $daysText ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php if (count($upcomingEvents) > 3): ?>
                            <div class="mt-auto pt-3">
                                <button type="button" class="btn btn-sm btn-outline-info" onclick="switchToTab('tab-leave')">View All</button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card panel-card">
                        <div class="card-body d-flex flex-column">
                            <h6 class="card-title mb-3">Recent Activity</h6>
                            <?php 
                            $recentActivities = array_slice($activityLog, 0, 3);
                            if (empty($recentActivities)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-activity"></i>
                                    No recent activity
                                </div>
                            <?php else: ?>
                            <div class="panel-list">
                                <?php foreach ($recentActivities as $act): 
                                    $actTime = new DateTime($act['created_at']);
                                    $now = new DateTime();
                                    $diff = $now->diff($actTime);
                                    $timeAgo = '';
                                    if ($diff->days > 7) {
                                        $timeAgo = $actTime->format('M j');
                                    } elseif ($diff->days > 0) {
                                        $timeAgo = $diff->days . ' day' . ($diff->days > 1 ? 's' : '') . ' ago';
                                    } elseif ($diff->h > 0) {
                                        $timeAgo = $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
                                    } elseif ($diff->i > 0) {
                                        $timeAgo = $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
                                    } else {
                                        $timeAgo = 'Just now';
                                    }

                                    $actionIcon = 'bi-circle';
                                    $actionColor = 'secondary';
                                    $actionTypeLower = strtolower($act['action_type'] ?? '');
                                    if (strpos($actionTypeLower, 'login') !== false) {
                                        $actionIcon = 'bi-box-arrow-in-right';
                                        $actionColor = 'success';
                                    } elseif (strpos($actionTypeLower, 'logout') !== false) {
                                        $actionIcon = 'bi-box-arrow-right';
                                        $actionColor = 'secondary';
                                    } elseif (strpos($actionTypeLower, 'leave') !== false) {
                                        $actionIcon = 'bi-calendar-event';
                                        $actionColor = 'info';
                                    } elseif (strpos($actionTypeLower, 'cash') !== false) {
                                        $actionIcon = 'bi-cash-coin';
                                        $actionColor = 'warning';
                                    } elseif (strpos($actionTypeLower, 'contact') !== false) {
                                        $actionIcon = 'bi-person-plus';
                                        $actionColor = 'primary';
                                    } elseif (strpos($actionTypeLower, 'document') !== false) {
                                        $actionIcon = 'bi-file-earmark';
                                        $actionColor = 'info';
                                    }
                                ?>
                                <div class="panel-list-item">
                                    <div class="d-flex align-items-start gap-2">
                                        <i class="bi <?= $actionIcon ?> text-<?= $actionColor ?> mt-1"></i>
                                        <div class="flex-grow-1 min-w-0">
                                            <div class="fw-semibold small"><?= htmlspecialchars($act['action_type']) ?></div>
                                            <div class="text-muted small"><?= htmlspecialchars($act['action_description'] ?: '—') ?></div>
                                        </div>
                                        <div class="text-muted small text-nowrap"><?= $timeAgo ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <div class="mt-auto pt-3">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="switchToTab('tab-activity')">View Full Log</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <div class="section-toolbar mt-0">
                        <h6 class="mb-0">Quick Actions</h6>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="switchToTab('tab-leave')">
                            <i class="bi bi-calendar-event me-1"></i>Request Leave
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="switchToTab('tab-cashadv')">
                            <i class="bi bi-cash-coin me-1"></i>Request Cash Advance
                        </button>
                        <?php if (!empty($payslipRows)): ?>
                        <button type="button" class="btn btn-outline-success btn-sm" onclick="switchToTab('tab-payslips')">
                            <i class="bi bi-receipt me-1"></i>View Latest Payslip
                        </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-info btn-sm" onclick="switchToTab('tab-schedule')">
                            <i class="bi bi-calendar-week me-1"></i>View Schedule
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="switchToTab('tab-docs')">
                            <i class="bi bi-file-earmark-text me-1"></i>View Documents
                        </button>
                        <?php if (!$workerReadOnly): ?>
                        <a href="employee_edit.php?id=<?= (int)$emp['id'] ?>" class="btn btn-outline-warning btn-sm">
                            <i class="bi bi-pencil me-1"></i>Edit Profile
                        </a>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-dark btn-sm" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i>Print Profile
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Documents -->
        <div class="tab-pane fade" id="tab-docs">
            <div class="d-flex justify-content-between align-items-center mt-3">
                <h6 class="mb-0">Documents</h6>
                <?php if (!$workerReadOnly): ?>
                <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#docModal" onclick="newDoc()">+ Add Document</button>
                <?php else: ?>
                    <span class="text-muted small">View only</span>
                <?php endif; ?>
            </div>

            <div class="table-responsive mt-2">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:140px;">Type</th>
                            <th style="width:160px;">Number</th>
                            <th style="width:120px;">Issued</th>
                            <th style="width:120px;">Expires</th>
                            <th>Notes</th>
                            <th style="width:100px;">File</th>
                            <th style="width:140px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$docs): ?>
                        <tr><td colspan="7" class="text-center text-muted">No documents yet.</td></tr>
                    <?php else: foreach ($docs as $d): ?>
                        <tr>
                            <td><?= htmlspecialchars($d['doc_type']) ?></td>
                            <td><?= htmlspecialchars($d['doc_number'] ?? '') ?></td>
                            <td><?= htmlspecialchars($d['issued_at'] ?: '—') ?></td>
                            <td>
                                <?= htmlspecialchars($d['expires_at'] ?: '—') ?>
                                <div class="small mt-1"><?= badge_status($d['expires_at']) ?></div>
                            </td>
                            <td><?= nl2br(htmlspecialchars($d['notes'] ?? '')) ?></td>
                            <td>
                                <?php if (!empty($d['file_path'])): ?>
                                    <a class="btn btn-outline-secondary btn-sm" target="_blank" href="<?= htmlspecialchars(hr_document_file_url($d['file_path'])) ?>">Open</a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$workerReadOnly): ?>
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#docModal"
                                        onclick='editDoc(<?= json_encode($d, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>Edit</button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete document?')">
                                        <?php csrf_field(); ?>
                                    <input type="hidden" name="delete_doc_id" value="<?= (int)$d['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm">Delete</button>
                                </form>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-danger mt-2"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
            <?php elseif ($success): ?>
                <div class="alert alert-success mt-2"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
        </div>

        <!-- Leave (NEW) -->
        <div class="tab-pane fade" id="tab-leave">
            <div class="d-flex align-items-center mt-3">
                <h6 class="mb-0">Leave</h6>
                <form class="ms-auto d-flex align-items-center" method="get">
        <?php csrf_field(); ?>
                    <input type="hidden" name="id" value="<?= htmlspecialchars($employee_id) ?>">
                    <label class="me-2">Year</label>
                    <input type="number" class="form-control form-control-sm" name="year" value="<?= (int)$year ?>" style="width:120px">
                    <button class="btn btn-sm btn-outline-primary ms-2">Apply</button>
                </form>
            </div>

            <?php if ($leave_err): ?><div class="alert alert-danger mt-2"><?= htmlspecialchars($leave_err) ?></div><?php endif; ?>
            <?php if ($leave_msg): ?><div class="alert alert-success mt-2"><?= htmlspecialchars($leave_msg) ?></div><?php endif; ?>

            <div class="card mt-2">
                <div class="card-header d-flex align-items-center">
                    <span class="fw-semibold">Balances (<?= (int)$year ?>)</span>
                    <form method="post" class="ms-auto d-none"><!-- reserved for future per-row actions -->
        <?php csrf_field(); ?>
</form>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped mb-0 align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>Type</th>
                            <th class="text-end">Opening</th>
                            <th class="text-end">Accrued</th>
                            <th class="text-end">Taken</th>
                            <th class="text-end">Carried</th>
                            <th class="text-end">Closing</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$balances): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">No balances yet for <?= (int)$year ?>.</td></tr>
                        <?php else: foreach ($balances as $b): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($b['type_name']) ?></td>
                                <td class="text-end"><?= number_format((float)$b['opening'],2) ?></td>
                                <td class="text-end"><?= number_format((float)$b['accrued'],2) ?></td>
                                <td class="text-end"><?= number_format((float)$b['taken'],2) ?></td>
                                <td class="text-end"><?= number_format((float)$b['carried'],2) ?></td>
                                <td class="text-end fw-semibold"><?= number_format((float)$b['closing'],2) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        
        

            <div class="d-flex justify-content-between align-items-center mt-3">
                <h6 class="mb-0">Recent Requests</h6>
                <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#leaveModal">+ New Leave Request</button>
            </div>

            <div class="table-responsive mt-2">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>#</th><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Attachment</th><th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$leave_rows): ?>
                        <tr><td colspan="7" class="text-center text-muted py-3">No leave requests.</td></tr>
                    <?php else: foreach ($leave_rows as $r): ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <td><?= htmlspecialchars($r['type_name']) ?></td>
                            <td><?= htmlspecialchars($r['date_from']).' → '.htmlspecialchars($r['date_to']) ?></td>
                            <td><?= number_format((float)$r['days'],2) ?></td>
                            <td>
                              <?php $m=['pending'=>'warning','approved'=>'success','rejected'=>'danger','cancelled'=>'secondary'];
                              $cls=$m[$r['status']]??'secondary'; ?>
                              <span class="badge text-bg-<?= $cls ?>"><?= htmlspecialchars($r['status']) ?></span>
                            </td>
                            <td>
                              <?php if ($r['attachment_path']): ?>
                                <a href="../<?= htmlspecialchars($r['attachment_path']) ?>" target="_blank">File</a>
                              <?php else: ?>—<?php endif; ?>
                            </td>
                            <td class="text-end">
                              <?php if (!$workerReadOnly && in_array($r['status'],['pending'],true)): ?>
                                <form method="post" class="d-inline">
        <?php csrf_field(); ?>
                                  <input type="hidden" name="leave_change_status" value="1">
                                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                  <input type="hidden" name="new_status" value="approved">
                                  <button class="btn btn-sm btn-success">Approve</button>
                                </form>
                                <form method="post" class="d-inline">
        <?php csrf_field(); ?>
                                  <input type="hidden" name="leave_change_status" value="1">
                                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                  <input type="hidden" name="new_status" value="rejected">
                                  <button class="btn btn-sm btn-outline-danger">Reject</button>
                                </form>
                              <?php else: ?>
                                <span class="text-muted">—</span>
                              <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    
        <?php if (!$workerReadOnly): ?>
        <!-- Overtime Tab (read-only) -->
        <div class="tab-pane fade" id="tab-overtime">
          <div class="card mt-3">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0">Overtime</h6>
                <span class="text-muted small">Employee: <?= htmlspecialchars($emp['full_name'] ?: $emp['employee_code']) ?></span>
              </div>

              <div class="table-responsive">
                <table class="table table-bordered align-middle table-sm">
                  <thead class="table-light">
                    <tr>
                      <th style="width:110px;">Date</th>
                      <th style="width:170px;">Time / Hours</th>
                      <th style="width:160px;">Rule</th>
                      <th style="width:180px;">Pay (hrs × rate × mult)</th>
                      <th style="width:110px;">Amount</th>
                      <th style="width:110px;">Status</th>
                      <th style="width:90px;">File</th>
                      <th>Notes</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if (!$otRows): ?>
                    <tr><td colspan="8" class="text-center text-muted py-3">No overtime entries.</td></tr>
                  <?php else: foreach ($otRows as $r):
                      // Build a friendly time/hours cell
                      $timeBits = [];
                      if (!empty($r['start_time']) || !empty($r['end_time'])) {
                          $st = $r['start_time'] ? date('h:i A', strtotime($r['start_time'])) : '—';
                          $et = $r['end_time']   ? date('h:i A', strtotime($r['end_time']))   : '—';
                          $timeBits[] = "$st → $et";
                      }
                      if ($r['manual_hours'] !== null && $r['manual_hours'] !== '') {
                          $timeBits[] = 'Manual: '.number_format((float)$r['manual_hours'],2);
                      }
                      // prefer pay_hours if present
                      $timeBits[] = 'Pay hrs: '.number_format((float)($r['pay_hours'] ?? 0),2);
                      $timeCell = implode('<br>', $timeBits);

                      $rate   = $r['pay_rate'] ?? 0;
                      $mult   = $r['pay_multiplier'] ?? 1;
                      $amount = $r['pay_amount'] ?? 0;
                      $rule   = $r['rule_name'] ?? '—';

                      // attachment path in overtime.php is stored like 'uploads/overtime_attachments/xxx'
                      $fileLink = '';
                      if (!empty($r['attachment_path'])) {
                          $fileLink = '<a class="btn btn-sm btn-outline-secondary" target="_blank" href="../'.htmlspecialchars($r['attachment_path']).'">Open</a>';
                      } else {
                          $fileLink = '<span class="text-muted">—</span>';
                      }
                  ?>
                    <tr>
                      <td><?= htmlspecialchars($r['ot_date']) ?></td>
                      <td><?= $timeCell ?></td>
                      <td><?= htmlspecialchars($rule) ?></td>
                      <td><?= number_format((float)$r['pay_hours'],2).' × '.number_format((float)$rate,2).' × '.number_format((float)$mult,2) ?></td>
                      <td><?= number_format((float)$amount,2) ?></td>
                      <td><?= ot_status_badge((string)$r['status']) ?></td>
                      <td><?= $fileLink ?></td>
                      <td><?= htmlspecialchars($r['notes'] ?? '') ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>

              <?php
                // Optional: quick total for approved in this employee
                $totS = $conn->prepare("
                  SELECT SUM(pay_hours) h, SUM(pay_amount) a
                  FROM overtime_entries
                  WHERE employee_id=? AND status='approved'
                ");
                $totS->execute([$emp['id']]);
                $tot = $totS->fetch(PDO::FETCH_ASSOC) ?: ['h'=>0,'a'=>0];
              ?>
              <div class="alert alert-info mt-3 mb-0 py-2">
                <strong>Approved totals:</strong>
                Hours: <?= number_format((float)$tot['h'],2) ?> &nbsp; | &nbsp;
                Amount: <?= number_format((float)$tot['a'],2) ?>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>
                          
                          
                          <!-- Loans / Salary Advances (evolved from Cash Advance) -->
                          <div class="tab-pane fade" id="tab-cashadv">
                            <style>
                              #tab-cashadv .loan-kpi { border: 1px solid #e5e7eb; border-radius: .75rem; background: #fff; padding: 1rem 1.1rem; height: 100%; }
                              #tab-cashadv .loan-kpi .kpi-label { font-size: .75rem; text-transform: uppercase; letter-spacing: .03em; color: #6b7280; margin-bottom: .25rem; }
                              #tab-cashadv .loan-kpi .kpi-value { font-size: 1.35rem; font-weight: 700; line-height: 1.2; }
                              #tab-cashadv .loan-kpi.outstanding .kpi-value { color: #b45309; }
                              #tab-cashadv .settle-howto { border-radius: .75rem; border: 1px solid #dbeafe; background: linear-gradient(180deg, #eff6ff 0%, #fff 70%); }
                              #tab-cashadv .settle-method { border: 1px solid #e5e7eb; border-radius: .65rem; background: #fff; padding: .9rem 1rem; height: 100%; }
                              #tab-cashadv .settle-method h6 { font-size: .95rem; margin-bottom: .35rem; }
                              #tab-cashadv .loan-add-card, #tab-cashadv .loan-list-card, #tab-cashadv .loan-history-card { border-radius: .75rem; }
                              #tab-cashadv .remaining-strong { font-weight: 700; color: #b45309; }
                              #tab-cashadv .remaining-zero { color: #059669; font-weight: 600; }
                            </style>

                            <?php if($cash_msg): ?><div class="alert alert-success py-2 mt-3"><?= htmlspecialchars($cash_msg) ?></div><?php endif; ?>
                            <?php if($cash_err): ?><div class="alert alert-danger py-2 mt-3"><?= htmlspecialchars($cash_err) ?></div><?php endif; ?>

                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3 mb-3">
                              <div>
                                <h5 class="mb-1">Loans / Salary Advances</h5>
                                <div class="text-muted small">Track issued amounts, payroll deductions, and cash repayments for this employee.</div>
                              </div>
                              <?php if (!$isOwner && !$isWorkerSelfService): ?>
                                <span class="badge text-bg-light border">HR view</span>
                              <?php endif; ?>
                            </div>

                            <div class="row g-3 mb-3">
                              <div class="col-md-4">
                                <div class="loan-kpi">
                                  <div class="kpi-label">Total issued</div>
                                  <div class="kpi-value"><?= number_format($cashIssued, 2) ?> <span class="fs-6 fw-normal text-muted">AED</span></div>
                                </div>
                              </div>
                              <div class="col-md-4">
                                <div class="loan-kpi">
                                  <div class="kpi-label">Already settled</div>
                                  <div class="kpi-value text-success"><?= number_format($cashApplied, 2) ?> <span class="fs-6 fw-normal text-muted">AED</span></div>
                                  <div class="small text-muted mt-1">Payroll deductions + cash repayments</div>
                                </div>
                              </div>
                              <div class="col-md-4">
                                <div class="loan-kpi outstanding">
                                  <div class="kpi-label">Outstanding balance</div>
                                  <div class="kpi-value"><?= number_format($cashAvail, 2) ?> <span class="fs-6 fw-normal text-muted">AED</span></div>
                                  <div class="small text-muted mt-1">Still to recover from employee</div>
                                </div>
                              </div>
                            </div>

                            <?php if (!$isWorkerSelfService): ?>
                            <div class="settle-howto p-3 mb-3">
                              <div class="d-flex align-items-start gap-2 mb-3">
                                <i class="bi bi-info-circle text-primary mt-1"></i>
                                <div>
                                  <strong>How to recover a loan / advance</strong>
                                  <div class="small text-muted">Choose one method per repayment. Both reduce the outstanding balance.</div>
                                </div>
                              </div>
                              <div class="row g-3">
                                <div class="col-md-6">
                                  <div class="settle-method">
                                    <h6><i class="bi bi-calculator text-primary me-1"></i>Via payroll</h6>
                                    <p class="small text-muted mb-2">During <strong>Build Payroll</strong>, enter the amount under <em>Loan / Adv → Apply</em>, then Post the run. The payslip shows the deduction.</p>
                                    <a class="btn btn-sm btn-outline-primary" href="payroll_runs.php">Open payroll runs</a>
                                  </div>
                                </div>
                                <div class="col-md-6">
                                  <div class="settle-method border-success-subtle">
                                    <h6><i class="bi bi-cash-coin text-success me-1"></i>Cash repayment (outside payroll)</h6>
                                    <p class="small text-muted mb-2">Use when the employee <strong>pays cash / transfer</strong> to the company. Click <strong>Record cash repayment</strong> on the loan row, enter the amount received, and save.</p>
                                    <span class="badge bg-light text-success border">Does not change the payslip</span>
                                  </div>
                                </div>
                              </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($cashAdvancePolicy):
                                    $currentOutstanding = getCurrentOutstandingBalance($conn, (int)$emp['id']);
                                    $eligibility = checkCashAdvanceEligibility($conn, $emp, $cashAdvancePolicy, $employeeMaxLimit);
                                    $maxAmount = getMaxAdvanceAmount($conn, $emp, $cashAdvancePolicy, $employeeMaxLimit);
                            ?>
                            <div class="card border-0 shadow-sm mb-3 loan-list-card">
                              <div class="card-header bg-white d-flex align-items-center justify-content-between">
                                <strong><i class="bi bi-shield-check me-1 text-info"></i> Policy limits</strong>
                                <button class="btn btn-sm btn-link" type="button" data-bs-toggle="collapse" data-bs-target="#loanPolicyCollapse">Show / hide</button>
                              </div>
                              <div class="collapse show" id="loanPolicyCollapse">
                                <div class="card-body">
                                  <?php if (!$eligibility['eligible']): ?>
                                  <div class="alert alert-warning mb-3">
                                    <strong><i class="bi bi-exclamation-triangle me-1"></i>Eligibility:</strong>
                                    <ul class="mb-0 mt-2">
                                      <?php foreach ($eligibility['reasons'] as $reason): ?>
                                        <li><?= htmlspecialchars($reason) ?></li>
                                      <?php endforeach; ?>
                                    </ul>
                                  </div>
                                  <?php endif; ?>
                                  <div class="row g-3 small">
                                    <?php if ($maxAmount !== null): ?>
                                    <div class="col-md-4">
                                      <div class="text-muted">Max per request</div>
                                      <strong><?= number_format($maxAmount, 2) ?> AED</strong>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($cashAdvancePolicy['max_total_advance_amount'])):
                                        $maxTotal = (float)$cashAdvancePolicy['max_total_advance_amount'];
                                        $remainingLimit = $eligibility['remaining_limit'] !== null ? $eligibility['remaining_limit'] : max(0, $maxTotal - $currentOutstanding);
                                    ?>
                                    <div class="col-md-4">
                                      <div class="text-muted">Total limit / headroom</div>
                                      <strong><?= number_format($maxTotal, 2) ?> AED</strong>
                                      <div class="text-muted">Headroom: <span class="text-<?= $remainingLimit > 0 ? 'success' : 'danger' ?>"><?= number_format((float)$remainingLimit, 2) ?> AED</span></div>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-md-4">
                                      <div class="text-muted">Service / pending</div>
                                      <strong><?= (int)($cashAdvancePolicy['min_service_months'] ?? 0) ?> mo min</strong>
                                      · max <?= (int)$cashAdvancePolicy['max_pending_advances'] ?> pending
                                    </div>
                                  </div>
                                  <?php if (!empty($cashAdvancePolicy['policy_rules']) || !empty($cashAdvancePolicy['eligibility_criteria']) || !empty($cashAdvancePolicy['terms_and_conditions'])): ?>
                                  <div class="mt-3 pt-3 border-top">
                                    <button class="btn btn-link btn-sm p-0" type="button" data-bs-toggle="collapse" data-bs-target="#policyDetailsCollapse">Full policy text</button>
                                    <div class="collapse mt-2" id="policyDetailsCollapse">
                                      <?php if (!empty($cashAdvancePolicy['policy_rules'])): ?>
                                        <div class="mb-2"><strong>Rules</strong><div class="small"><?= $cashAdvancePolicy['policy_rules'] ?></div></div>
                                      <?php endif; ?>
                                      <?php if (!empty($cashAdvancePolicy['eligibility_criteria'])): ?>
                                        <div class="mb-2"><strong>Eligibility</strong><div class="small"><?= $cashAdvancePolicy['eligibility_criteria'] ?></div></div>
                                      <?php endif; ?>
                                      <?php if (!empty($cashAdvancePolicy['terms_and_conditions'])): ?>
                                        <div class="mb-0"><strong>Terms</strong><div class="small"><?= $cashAdvancePolicy['terms_and_conditions'] ?></div></div>
                                      <?php endif; ?>
                                    </div>
                                  </div>
                                  <?php endif; ?>
                                </div>
                              </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($canManageLoans): ?>
                            <div class="card border-0 shadow-sm mb-3 loan-add-card">
                              <div class="card-header bg-white">
                                <strong><i class="bi bi-plus-circle me-1"></i> Issue new loan / advance</strong>
                                <div class="small text-muted">Creates an open balance recoverable by payroll or cash repayment. Available to Owner, Admin, and HR.</div>
                              </div>
                              <div class="card-body">
                                <form method="post" class="row g-3 align-items-end">
                                  <?php csrf_field(); ?>
                                  <input type="hidden" name="add_cash_adv" value="1">
                                  <input type="hidden" name="status" value="open">
                                  <div class="col-md-2">
                                    <label class="form-label">Issue date</label>
                                    <input type="date" name="tx_date" value="<?= date('Y-m-d') ?>" class="form-control" required>
                                  </div>
                                  <div class="col-md-2">
                                    <label class="form-label">Amount (AED)</label>
                                    <input type="number" name="amount" step="0.01" min="0.01" class="form-control" placeholder="0.00" required>
                                  </div>
                                  <div class="col-md-2">
                                    <label class="form-label">Installments</label>
                                    <input type="number" name="installment_count" min="1" max="60" value="1" class="form-control" title="Planned installment count">
                                  </div>
                                  <div class="col-md-4">
                                    <label class="form-label">Reason / description</label>
                                    <input type="text" name="description" class="form-control" placeholder="Optional note for HR">
                                  </div>
                                  <div class="col-md-2">
                                    <button class="btn btn-primary w-100">Issue loan</button>
                                  </div>
                                </form>
                              </div>
                            </div>
                            <?php elseif ($isWorkerSelfService): ?>
                              <div class="mb-3">
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#requestCashAdvanceModal">
                                  <i class="bi bi-plus-circle me-1"></i>Request cash advance
                                </button>
                              </div>
                            <?php endif; ?>

                            <div class="card border-0 shadow-sm mb-3 loan-list-card">
                              <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div>
                                  <strong>Loan register</strong>
                                  <div class="small text-muted">Open items can be recovered by payroll apply or cash repayment.</div>
                                </div>
                              </div>
                              <div class="card-body p-0">
                                <div class="table-responsive">
                                  <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                      <tr>
                                        <th>Date</th>
                                        <th class="text-end">Issued</th>
                                        <th class="text-end">Outstanding</th>
                                        <th>Description</th>
                                        <th>Request</th>
                                        <th>Status</th>
                                        <th class="text-end" style="min-width:210px;">Recover / actions</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                    <?php if(!$cashRows): ?>
                                      <tr><td colspan="7" class="text-center text-muted py-4">No loans or advances yet.</td></tr>
                                    <?php else: foreach($cashRows as $r):
                                      $requestStatus = $r['request_status'] ?? null;
                                      $isPending = $requestStatus === 'pending';
                                      $isApproved = $requestStatus === 'approved';
                                      $isRejected = $requestStatus === 'rejected';
                                      $remaining = (float)($r['remaining_balance'] ?? $r['principal'] ?? $r['amount']);
                                      if (($r['status'] ?? '') === 'settled') $remaining = 0.0;
                                      $canCashSettle = (($r['status'] ?? '') === 'open') && $remaining > 0.005 && !$isPending && !$isWorkerSelfService;
                                    ?>
                                      <tr>
                                        <td class="text-nowrap"><?= htmlspecialchars($r['tx_date']) ?></td>
                                        <td class="text-end text-nowrap">
                                          <?php if ($isPending && $r['requested_amount']): ?>
                                            <span class="text-muted">Req <?= number_format((float)$r['requested_amount'], 2) ?></span>
                                          <?php elseif ($isApproved && !empty($r['approved_amount']) && (float)$r['approved_amount'] != (float)($r['requested_amount'] ?? $r['amount'])): ?>
                                            <div class="text-decoration-line-through text-muted small"><?= number_format((float)($r['requested_amount'] ?? $r['amount']), 2) ?></div>
                                            <div><?= number_format((float)$r['approved_amount'], 2) ?></div>
                                          <?php else: ?>
                                            <?= number_format((float)$r['amount'], 2) ?>
                                          <?php endif; ?>
                                        </td>
                                        <td class="text-end text-nowrap">
                                          <span class="<?= $remaining > 0.005 ? 'remaining-strong' : 'remaining-zero' ?>"><?= number_format($remaining, 2) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($r['description'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                                        <td>
                                          <?php if ($isPending): ?>
                                            <span class="badge text-bg-warning">Pending</span>
                                          <?php elseif ($isApproved): ?>
                                            <span class="badge text-bg-success">Approved</span>
                                          <?php elseif ($isRejected): ?>
                                            <span class="badge text-bg-danger">Rejected</span>
                                            <?php if (!empty($r['rejection_reason'])): ?>
                                              <div class="small text-muted"><?= htmlspecialchars($r['rejection_reason']) ?></div>
                                            <?php endif; ?>
                                          <?php else: ?>
                                            <span class="text-muted">—</span>
                                          <?php endif; ?>
                                        </td>
                                        <td>
                                          <span class="badge text-bg-<?= $r['status']==='open'?'primary':($r['status']==='settled'?'success':'secondary') ?>">
                                            <?= htmlspecialchars($r['status'] === 'open' ? 'Open' : ($r['status'] === 'settled' ? 'Settled' : (string)$r['status'])) ?>
                                          </span>
                                        </td>
                                        <td class="text-end">
                                          <div class="d-flex flex-wrap justify-content-end gap-1">
                                            <?php if ($canCashSettle): ?>
                                              <button type="button"
                                                class="btn btn-sm btn-success btn-loan-cash-settle"
                                                data-bs-toggle="modal"
                                                data-bs-target="#loanCashSettleModal"
                                                data-loan-id="<?= (int)$r['id'] ?>"
                                                data-remaining="<?= number_format($remaining, 2, '.', '') ?>"
                                                data-loan-date="<?= htmlspecialchars($r['tx_date'], ENT_QUOTES) ?>"
                                                data-loan-desc="<?= htmlspecialchars((string)($r['description'] ?? ''), ENT_QUOTES) ?>"
                                                title="Employee paid cash / transfer — record repayment">
                                                <i class="bi bi-cash-coin me-1"></i>Record cash repayment
                                              </button>
                                            <?php elseif (($r['status'] ?? '') === 'settled' || $remaining <= 0.005): ?>
                                              <span class="small text-success"><i class="bi bi-check-circle me-1"></i>Fully recovered</span>
                                            <?php endif; ?>
                                            <?php if ($isOwner): ?>
                                              <?php if ($isPending): ?>
                                                <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#approveCashAdvanceModal<?= (int)$r['id'] ?>">Approve</button>
                                                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectCashAdvanceModal<?= (int)$r['id'] ?>">Reject</button>
                                              <?php endif; ?>
                                              <form method="post" class="d-inline" onsubmit="return confirm('Delete this loan / advance entry?')">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="del_cash_adv" value="<?= (int)$r['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger">Delete</button>
                                              </form>
                                            <?php elseif ($isWorkerSelfService && !$canCashSettle): ?>
                                              <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                          </div>
                                        </td>
                                      </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                  </table>
                                </div>
                              </div>
                            </div>

                            <?php if (!$isWorkerSelfService): ?>
                            <!-- Cash repayment modal (shared) -->
                            <div class="modal fade" id="loanCashSettleModal" tabindex="-1" aria-labelledby="loanCashSettleModalLabel" aria-hidden="true">
                              <div class="modal-dialog">
                                <form method="post" action="loan_settle_cash.php" class="modal-content">
                                  <?php csrf_field(); ?>
                                  <input type="hidden" name="type" value="loan">
                                  <input type="hidden" name="id" id="loanCashSettleId" value="">
                                  <input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>">
                                  <input type="hidden" name="return" value="employee_view.php?id=<?= (int)$emp['id'] ?>#tab-cashadv">
                                  <div class="modal-header">
                                    <h5 class="modal-title" id="loanCashSettleModalLabel"><i class="bi bi-cash-coin me-2 text-success"></i>Record cash repayment</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                  </div>
                                  <div class="modal-body">
                                    <div class="alert alert-light border small mb-3">
                                      Use this only when the employee <strong>paid cash or bank transfer</strong> to the company.
                                      This is <strong>not</strong> a payroll deduction — it will not appear on the payslip.
                                    </div>
                                    <div class="mb-2 small text-muted" id="loanCashSettleMeta"></div>
                                    <div class="mb-3">
                                      <label class="form-label">Outstanding balance</label>
                                      <div class="form-control-plaintext fw-semibold" id="loanCashSettleRemainingLabel">—</div>
                                    </div>
                                    <div class="mb-3">
                                      <label class="form-label" for="loanCashSettleAmount">Amount received (AED)</label>
                                      <input type="number" step="0.01" min="0.01" class="form-control" name="amount" id="loanCashSettleAmount" required>
                                      <div class="form-text">Partial repayments are allowed. Cannot exceed outstanding.</div>
                                    </div>
                                    <div class="mb-3">
                                      <label class="form-label" for="loanCashSettleDate">Payment date</label>
                                      <input type="date" class="form-control" name="settle_date" id="loanCashSettleDate" value="<?= date('Y-m-d') ?>" required>
                                    </div>
                                    <div class="mb-0">
                                      <label class="form-label" for="loanCashSettleNotes">Notes (optional)</label>
                                      <input type="text" class="form-control" name="notes" id="loanCashSettleNotes" placeholder="e.g. Cash received by reception / bank transfer ref">
                                    </div>
                                  </div>
                                  <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-success">Save cash repayment</button>
                                  </div>
                                </form>
                              </div>
                            </div>
                            <script>
                            (function () {
                              var modal = document.getElementById('loanCashSettleModal');
                              if (!modal) return;
                              modal.addEventListener('show.bs.modal', function (event) {
                                var btn = event.relatedTarget;
                                if (!btn) return;
                                var id = btn.getAttribute('data-loan-id') || '';
                                var rem = btn.getAttribute('data-remaining') || '0';
                                var date = btn.getAttribute('data-loan-date') || '';
                                var desc = btn.getAttribute('data-loan-desc') || '';
                                document.getElementById('loanCashSettleId').value = id;
                                document.getElementById('loanCashSettleRemainingLabel').textContent = Number(rem).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' AED';
                                var amount = document.getElementById('loanCashSettleAmount');
                                amount.max = rem;
                                amount.value = rem;
                                var meta = 'Loan #' + id + (date ? ' · issued ' + date : '');
                                if (desc) meta += ' · ' + desc;
                                document.getElementById('loanCashSettleMeta').textContent = meta;
                              });
                            })();
                            </script>
                            <?php endif; ?>

                            <div class="card border-0 shadow-sm mb-3 loan-history-card">
                              <div class="card-header bg-white">
                                <strong>Activity history</strong>
                                <div class="small text-muted">Issues, payroll applications, and cash repayments.</div>
                              </div>
                              <div class="card-body p-0">
                                <div class="table-responsive">
                                  <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                      <tr><th>Date</th><th>Event</th><th class="text-end">Amount (AED)</th></tr>
                                    </thead>
                                    <tbody>
                                    <?php if (!$loanHistoryRows): ?>
                                      <tr><td colspan="3" class="text-muted text-center py-3">No history yet.</td></tr>
                                    <?php else: foreach (array_slice($loanHistoryRows, 0, 40) as $hRow): ?>
                                      <tr>
                                        <td class="text-nowrap"><?= htmlspecialchars((string)$hRow['date']) ?></td>
                                        <td><?= htmlspecialchars((string)$hRow['label']) ?></td>
                                        <td class="text-end"><?= number_format((float)$hRow['amount'], 2) ?></td>
                                      </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                  </table>
                                </div>
                              </div>
                            </div>

                            <div class="card border-0 shadow-sm mb-3 loan-history-card">
                              <div class="card-header bg-white">
                                <strong>Recovered via payroll</strong>
                                <div class="small text-muted">Amounts deducted on posted / saved payroll runs for this employee.</div>
                              </div>
                              <div class="card-body p-0">
                                <div class="table-responsive">
                                  <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                      <tr>
                                        <th>Period</th>
                                        <th>Run status</th>
                                        <th class="text-end">Applied (AED)</th>
                                        <th class="text-end">Payslip</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                      <?php if (!$cashAppliedRows): ?>
                                        <tr><td colspan="4" class="text-center text-muted py-3">No payroll deductions yet.</td></tr>
                                      <?php else: foreach ($cashAppliedRows as $row): ?>
                                        <?php
                                          $status = strtolower($row['status'] ?? '');
                                          $badgeMap = ['draft'=>'warning','open'=>'primary','finalized'=>'success','paid'=>'success','cancelled'=>'dark'];
                                          $badgeClass = $badgeMap[$status] ?? 'secondary';
                                        ?>
                                        <tr>
                                          <td class="text-nowrap"><?= htmlspecialchars($row['period_from']) ?> → <?= htmlspecialchars($row['period_to']) ?></td>
                                          <td><span class="badge text-bg-<?= $badgeClass ?>"><?= htmlspecialchars(ucfirst($row['status'])) ?></span></td>
                                          <td class="text-end"><?= number_format((float)$row['adv_applied'], 2) ?></td>
                                          <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary"
                                               href="payslip?run_id=<?= (int)$row['run_id'] ?>&employee_id=<?= (int)$emp['id'] ?>"
                                               target="_blank">View</a>
                                          </td>
                                        </tr>
                                      <?php endforeach; endif; ?>
                                    </tbody>
                                  </table>
                                </div>
                              </div>
                            </div>
                          </div>
                          
                          
                          
                          <?php if (!$workerReadOnly): ?>
                          <!-- Deductions -->
                          <div class="tab-pane fade" id="tab-deduct">
                            <div class="card mt-3">
                              <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                  <h6 class="mb-0">Fines / Deductions</h6>
                                  <div class="ms-auto small text-muted">
                                    Issued: <strong><?= number_format($dedIssued,2) ?></strong> ·
                                    Applied: <strong><?= number_format($dedApplied,2) ?></strong> ·
                                    Available: <strong><?= number_format($dedAvail,2) ?></strong>
                                  </div>
                                </div>

                                <?php if($ded_msg): ?><div class="alert alert-success py-2"><?= htmlspecialchars($ded_msg) ?></div><?php endif; ?>

                                <?php if (!$workerReadOnly): ?>
                                <form method="post" class="row g-2 mb-3">
                          <?php csrf_field(); ?>
                                  <input type="hidden" name="add_deduction" value="1">
                                  <div class="col-md-3">
                                    <label class="form-label">Date</label>
                                    <input type="date" name="tx_date" value="<?= date('Y-m-d') ?>" class="form-control" required>
                                  </div>
                                  <div class="col-md-3">
                                    <label class="form-label">Amount</label>
                                    <input type="number" name="amount" step="0.01" min="0" class="form-control" required>
                                  </div>
                                  <div class="col-md-3">
                                    <label class="form-label">Type</label>
                                    <select name="dtype" class="form-select">
                                      <option value="fine">fine</option>
                                      <option value="charge_duty">charge duty</option>
                                      <option value="uniform">uniform</option>
                                      <option value="other" selected>other</option>
                                    </select>
                                  </div>
                                  <div class="col-md-3">
                                    <label class="form-label">Notes</label>
                                    <input type="text" name="notes" class="form-control" placeholder="Optional">
                                  </div>
                                  <div class="col-12">
                                    <button class="btn btn-primary">Add</button>
                                  </div>
                                </form>
                                <?php else: ?>
                                  <p class="text-muted small mb-3">Viewing only. Deductions updates are managed by HR.</p>
                                <?php endif; ?>

                                <div class="table-responsive">
                                  <table class="table table-bordered align-middle table-sm">
                                    <thead class="table-light">
                                      <tr>
                                        <th style="width:110px;">Date</th>
                                        <th style="width:110px;">Amount</th>
                                        <th style="width:100px;">Remaining</th>
                                        <th style="width:120px;">Type</th>
                                        <th>Notes</th>
                                        <th style="width:200px;" class="text-end">Actions</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                    <?php if(!$dedRows): ?>
                                      <tr><td colspan="6" class="text-center text-muted py-3">No entries.</td></tr>
                                    <?php else: foreach($dedRows as $r):
                                      $dedRem = isset($r['remaining_balance']) ? (float)$r['remaining_balance'] : (float)$r['amount'];
                                      if (($r['settle_status'] ?? '') === 'settled') $dedRem = 0;
                                    ?>
                                      <tr>
                                        <td><?= htmlspecialchars($r['tx_date']) ?></td>
                                        <td><?= number_format($r['amount'],2) ?></td>
                                        <td><?= number_format($dedRem, 2) ?></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($r['dtype']) ?></span></td>
                                        <td><?= htmlspecialchars($r['notes'] ?? '') ?></td>
                                        <td class="text-end">
                                          <?php if (!$workerReadOnly && $dedRem > 0.005): ?>
                                            <form method="post" action="loan_settle_cash.php" class="d-inline-flex gap-1 align-items-center me-1">
                                              <?php csrf_field(); ?>
                                              <input type="hidden" name="type" value="deduction">
                                              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                              <input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>">
                                              <input type="hidden" name="settle_date" value="<?= date('Y-m-d') ?>">
                                              <input type="number" step="0.01" min="0.01" max="<?= number_format($dedRem,2,'.','') ?>" name="amount" class="form-control form-control-sm" style="width:90px" placeholder="Cash" required>
                                              <button class="btn btn-sm btn-outline-success">Cash</button>
                                            </form>
                                          <?php endif; ?>
                                          <?php if (!$workerReadOnly): ?>
                                          <form method="post" class="d-inline" onsubmit="return confirm('Delete this entry?')">
                                        <?php csrf_field(); ?>
                                            <input type="hidden" name="del_deduction" value="<?= (int)$r['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                                          </form>
                                          <?php else: ?>
                                            <span class="text-muted">—</span>
                                          <?php endif; ?>
                                        </td>
                                      </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                  </table>
                                </div>

                                <hr class="my-4">
                                <h6 class="fw-semibold mb-2">Settlement history</h6>
                                <p class="text-muted small">Payroll applications and cash settlements. Remaining balance is reduced by either method.</p>
                                <?php if ($dedCashSettlements): ?>
                                <div class="table-responsive mb-3">
                                  <table class="table table-sm table-bordered mb-0">
                                    <thead class="table-light"><tr><th>Date</th><th>Method</th><th class="text-end">Amount</th><th>Notes</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($dedCashSettlements as $s): ?>
                                      <tr>
                                        <td><?= htmlspecialchars($s['settle_date']) ?></td>
                                        <td><?= htmlspecialchars($s['method']) ?></td>
                                        <td class="text-end"><?= number_format((float)$s['amount'], 2) ?></td>
                                        <td><?= htmlspecialchars($s['notes'] ?? '') ?></td>
                                      </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                  </table>
                                </div>
                                <?php endif; ?>

                                <h6 class="fw-semibold mb-2">Applied in Payroll</h6>
                                <p class="text-muted small mb-3">These deductions have already been taken from payslips.</p>
                                <div class="table-responsive">
                                  <table class="table table-bordered align-middle table-sm mb-0">
                                    <thead class="table-light">
                                      <tr>
                                        <th style="width:110px;">Period</th>
                                        <th style="width:110px;">Status</th>
                                        <th style="width:140px;">Applied (AED)</th>
                                        <th class="text-end" style="width:120px;">Payslip</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                      <?php if (!$dedAppliedRows): ?>
                                        <tr><td colspan="4" class="text-center text-muted py-3">No applied deductions yet.</td></tr>
                                      <?php else: foreach ($dedAppliedRows as $row): ?>
                                        <?php
                                          $status = strtolower($row['status'] ?? '');
                                          $badgeMap = ['draft'=>'secondary','open'=>'primary','finalized'=>'info','paid'=>'success','cancelled'=>'dark'];
                                          $badgeClass = $badgeMap[$status] ?? 'secondary';
                                        ?>
                                        <tr>
                                          <td><?= htmlspecialchars($row['period_from']) ?> → <?= htmlspecialchars($row['period_to']) ?></td>
                                          <td><span class="badge text-bg-<?= $badgeClass ?>"><?= htmlspecialchars(ucfirst($row['status'])) ?></span></td>
                                          <td><?= number_format((float)$row['amount_applied'], 2) ?></td>
                                          <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary"
                                               href="payslip?run_id=<?= (int)$row['run_id'] ?>&employee_id=<?= (int)$emp['id'] ?>"
                                               target="_blank">
                                              View
                                            </a>
                                        </td>
                                      </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                  </table>
                                </div>
                              </div>
                            </div>
                          </div>
               
                          
                          
                          <!-- Assets -->
                          <div class="tab-pane fade" id="tab-assets">
                            <div class="card mt-3">
                              <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                  <h6 class="mb-0">Assets</h6>
                                  <div class="ms-auto small text-muted">
                                    Currently issued: <strong><?= (int)$assetIssuedCount ?></strong>
                                  </div>
                                </div>

                                <?php if($asset_msg): ?><div class="alert alert-success py-2"><?= htmlspecialchars($asset_msg) ?></div><?php endif; ?>

                                <?php if (!$workerReadOnly): ?>
                                <form method="post" class="row g-2 mb-3">
                          <?php csrf_field(); ?>
                                  <input type="hidden" name="add_asset" value="1">
                                  <div class="col-md-3">
                                    <label class="form-label">Item *</label>
                                    <input name="item_name" class="form-control" required placeholder="e.g. Laptop, Uniform">
                                  </div>
                                  <div class="col-md-2">
                                    <label class="form-label">Serial/No.</label>
                                    <input name="serial_no" class="form-control">
                                  </div>
                                  <div class="col-md-2">
                                    <label class="form-label">Issue date</label>
                                    <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" class="form-control">
                                  </div>
                                  <div class="col-md-2">
                                    <label class="form-label">Return due</label>
                                    <input type="date" name="return_due" class="form-control">
                                  </div>
                                  <div class="col-md-3">
                                    <label class="form-label">Notes</label>
                                    <input name="notes" class="form-control" placeholder="Optional">
                                  </div>
                                  <div class="col-12">
                                    <button class="btn btn-primary">Add</button>
                                  </div>
                                </form>
                                <?php else: ?>
                                  <p class="text-muted small mb-3">Assets are managed by HR. Displaying recorded items only.</p>
                                <?php endif; ?>

                                <div class="table-responsive">
                                  <table class="table table-bordered align-middle table-sm">
                                    <thead class="table-light">
                                      <tr>
                                        <th style="width:120px;">Issue</th>
                                        <th>Item</th>
                                        <th style="width:160px;">Serial</th>
                                        <th style="width:120px;">Due</th>
                                        <th style="width:120px;">Returned</th>
                                        <th style="width:110px;">Status</th>
                                        <th>Notes</th>
                                        <th class="text-end" style="width:160px;">Actions</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                    <?php if(!$assetRows): ?>
                                      <tr><td colspan="8" class="text-center text-muted py-3">No assets.</td></tr>
                                    <?php else: foreach($assetRows as $r): ?>
                                      <tr>
                                        <td><?= htmlspecialchars($r['issue_date']) ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars($r['item_name']) ?></td>
                                        <td><?= htmlspecialchars($r['serial_no'] ?: '—') ?></td>
                                        <td><?= htmlspecialchars($r['return_due'] ?: '—') ?></td>
                                        <td><?= htmlspecialchars($r['return_date'] ?: '—') ?></td>
                                        <td>
                                          <?php
                                            $m=['issued'=>'primary','returned'=>'success','lost'=>'danger'];
                                            $cls=$m[$r['status']]??'secondary';
                                          ?>
                                          <span class="badge text-bg-<?= $cls ?>"><?= htmlspecialchars($r['status']) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($r['notes'] ?: '') ?></td>
                                        <td class="text-end">
                                          <?php if (!$workerReadOnly): ?>
                                          <?php if ($r['status']==='issued'): ?>
                                            <form method="post" class="d-inline">
                                              <?php csrf_field(); ?>
                                              <input type="hidden" name="return_asset" value="<?= (int)$r['id'] ?>">
                                              <input type="date" name="return_date" value="<?= date('Y-m-d') ?>" class="form-control form-control-sm d-inline-block" style="width: 140px;">
                                              <button class="btn btn-sm btn-success">Return</button>
                                            </form>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Mark lost?')">
                          <?php csrf_field(); ?>
                                              <input type="hidden" name="lost_asset" value="<?= (int)$r['id'] ?>">
                                              <button class="btn btn-sm btn-outline-danger">Lost</button>
                                            </form>
                                          <?php else: ?>
                                            <span class="text-muted">—</span>
                                          <?php endif; ?>
                                          <form method="post" class="d-inline" onsubmit="return confirm('Delete entry?')">
                          <?php csrf_field(); ?>
                                            <input type="hidden" name="del_asset" value="<?= (int)$r['id'] ?>">
                                            <button class="btn btn-sm btn-outline-secondary">Delete</button>
                                          </form>
                                          <?php else: ?>
                                            <span class="text-muted">—</span>
                                          <?php endif; ?>
                                        </td>
                                      </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                  </table>
                                </div>
                              </div>
                            </div>
                          </div>
                          
                          <!-- Training -->
                          <div class="tab-pane fade" id="tab-training">
                            <div class="card mt-3">
                              <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                  <h6 class="mb-0">Training & Certifications</h6>
                                  <div class="ms-auto small text-muted">
                                    Expiring in 60d: <strong><?= (int)$expiringSoon ?></strong>
                                  </div>
                                </div>

                                <?php if($train_msg): ?><div class="alert alert-success py-2"><?= htmlspecialchars($train_msg) ?></div><?php endif; ?>

                                <?php if (!$workerReadOnly): ?>
                                <form method="post" enctype="multipart/form-data" class="row g-2 mb-3">
                          <?php csrf_field(); ?>
                                  <input type="hidden" name="add_training" value="1">
                                  <div class="col-md-3">
                                    <label class="form-label">Course *</label>
                                    <input name="course_name" class="form-control" required placeholder="e.g. First Aid">
                                  </div>
                                  <div class="col-md-3">
                                    <label class="form-label">Provider</label>
                                    <input name="provider" class="form-control">
                                  </div>
                                  <div class="col-md-2">
                                    <label class="form-label">Start</label>
                                    <input type="date" name="start_date" class="form-control">
                                  </div>
                                  <div class="col-md-2">
                                    <label class="form-label">End</label>
                                    <input type="date" name="end_date" class="form-control">
                                  </div>
                                  <div class="col-md-2">
                                    <label class="form-label">Valid until</label>
                                    <input type="date" name="cert_valid_until" class="form-control">
                                  </div>
                                  <div class="col-md-2">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                      <option value="planned">planned</option>
                                      <option value="completed">completed</option>
                                      <option value="expired">expired</option>
                                      <option value="cancelled">cancelled</option>
                                    </select>
                                  </div>
                                  <div class="col-md-2">
                                    <label class="form-label">Result</label>
                                    <input name="result" class="form-control" placeholder="Pass/Score">
                                  </div>
                                  <div class="col-md-3">
                                    <label class="form-label">Certificate (PDF/JPG/PNG)</label>
                                    <input type="file" name="certificate" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                  </div>
                                  <div class="col-md-5">
                                    <label class="form-label">Notes</label>
                                    <input name="notes" class="form-control" placeholder="Optional">
                                  </div>
                                  <div class="col-12">
                                    <button class="btn btn-primary">Add</button>
                                  </div>
                                </form>
                                <?php else: ?>
                                  <p class="text-muted small mb-3">Training records are read-only for worker accounts.</p>
                                <?php endif; ?>

                                <div class="table-responsive">
                                  <table class="table table-bordered align-middle table-sm">
                                    <thead class="table-light">
                                      <tr>
                                        <th style="width:120px;">Dates</th>
                                        <th>Course</th>
                                        <th style="width:180px;">Provider</th>
                                        <th style="width:130px;">Valid until</th>
                                        <th style="width:110px;">Status</th>
                                        <th style="width:120px;">Result</th>
                                        <th style="width:90px;">Cert</th>
                                        <th>Notes</th>
                                        <th class="text-end" style="width:170px;">Actions</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                    <?php if(!$trainRows): ?>
                                      <tr><td colspan="9" class="text-center text-muted py-3">No training records.</td></tr>
                                    <?php else: foreach($trainRows as $r): ?>
                                      <tr>
                                        <td>
                                          <?php
                                            $sd = $r['start_date'] ?: '—';
                                            $ed = $r['end_date'] ?: '—';
                                          ?>
                                          <?= htmlspecialchars($sd) ?> → <?= htmlspecialchars($ed) ?>
                                        </td>
                                        <td class="fw-semibold"><?= htmlspecialchars($r['course_name']) ?></td>
                                        <td><?= htmlspecialchars($r['provider'] ?: '—') ?></td>
                                        <td><?= htmlspecialchars($r['cert_valid_until'] ?: '—') ?></td>
                                        <td>
                                          <?php $m=['planned'=>'secondary','completed'=>'success','expired'=>'warning','cancelled'=>'dark']; $cls=$m[$r['status']]??'secondary'; ?>
                                          <span class="badge text-bg-<?= $cls ?>"><?= htmlspecialchars($r['status']) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($r['result'] ?: '—') ?></td>
                                        <td>
                                          <?php if($r['certificate_path']): ?>
                                            <a class="btn btn-sm btn-outline-secondary" target="_blank" href="../<?= htmlspecialchars($r['certificate_path']) ?>">Open</a>
                                          <?php else: ?>—<?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($r['notes'] ?: '') ?></td>
                                        <td class="text-end">
                                          <?php if (!$workerReadOnly): ?>
                                          <form method="post" class="d-inline">
                                              <?php csrf_field(); ?>
                                            <input type="hidden" name="update_training_status" value="1">
                                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                            <input type="hidden" name="new_status" value="<?= $r['status']==='completed' ? 'expired' : 'completed' ?>">
                                            <button class="btn btn-sm btn-outline-primary">
                                              <?= $r['status']==='completed' ? 'Mark expired' : 'Mark completed' ?>
                                            </button>
                                          </form>
                                          <form method="post" class="d-inline" onsubmit="return confirm('Delete this training record?')">
                          <?php csrf_field(); ?>
                                            <input type="hidden" name="del_training" value="<?= (int)$r['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                                          </form>
                                          <?php else: ?>
                                            <span class="text-muted">—</span>
                                          <?php endif; ?>
                                        </td>
                                      </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                  </table>
                                </div>
                              </div>
                            </div>
                          </div>

                          <!-- Performance -->
                          <div class="tab-pane fade" id="tab-performance">
                            <?php if (!$performanceData['has_worker']): ?>
                              <div class="alert alert-warning">
                                We couldn’t locate a linked worker profile for this employee. The summary below shows zeroes. Please ask HR to map this employee in <strong>Workers</strong> if discrepancies remain.
                              </div>
                            <?php endif; ?>
                            <div class="card mt-3">
                              <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                  <h6 class="mb-0">Monthly Performance</h6>
                                  <span class="ms-auto small text-muted">
                                    <?= htmlspecialchars($performanceData['period_start']) ?> → <?= htmlspecialchars($performanceData['period_end']) ?>
                                  </span>
                                </div>
                                <div class="row g-3">
                                  <div class="col-sm-6 col-xl-3">
                                    <div class="card border-0 shadow-sm h-100">
                                      <div class="card-body">
                                        <div class="text-muted small">Hours Worked</div>
                                        <div class="display-6 fw-semibold"><?= number_format($performanceData['worked_hours'], 2) ?></div>
                                        <div class="small text-muted">Completed job hours this month</div>
                                      </div>
                                    </div>
                                  </div>
                                  <div class="col-sm-6 col-xl-3">
                                    <div class="card border-0 shadow-sm h-100">
                                      <div class="card-body">
                                        <div class="text-muted small">Target Hours</div>
                                        <div class="display-6 fw-semibold">
                                          <?= $performanceData['target_hours'] !== null ? number_format($performanceData['target_hours'], 2) : '—' ?>
                                        </div>
                                        <div class="small text-muted">
                                          <?= $performanceData['achievement_pct'] !== null
                                              ? number_format($performanceData['achievement_pct'], 1) . '% achieved'
                                              : 'Targets not configured' ?>
                                        </div>
                                      </div>
                                    </div>
                                  </div>
                                  <div class="col-sm-6 col-xl-3">
                                    <div class="card border-0 shadow-sm h-100">
                                      <div class="card-body">
                                        <div class="text-muted small">Average Hours / Day</div>
                                        <div class="display-6 fw-semibold"><?= number_format($performanceData['avg_hours'], 2) ?></div>
                                        <div class="small text-muted"><?= (int)$performanceData['approved_days'] ?> work days with jobs</div>
                                      </div>
                                    </div>
                                  </div>
                                  <div class="col-sm-6 col-xl-3">
                                    <div class="card border-0 shadow-sm h-100">
                                      <div class="card-body">
                                        <div class="text-muted small">Overtime Hours</div>
                                        <div class="display-6 fw-semibold text-primary"><?= number_format($performanceData['overtime_hours'], 2) ?></div>
                                        <div class="small text-muted">Above <?= number_format($otThreshold, 1) ?> hrs / day from job totals</div>
                                      </div>
                                    </div>
                                  </div>
                                </div>
                              </div>
                            </div>

                            <div class="card mt-3">
                              <div class="card-header d-flex align-items-center">
                                <span class="fw-semibold">Recent Jobs (current month)</span>
                              </div>
                              <div class="card-body p-0">
                                <div class="table-responsive">
                                  <table class="table table-hover mb-0 align-middle">
                                    <thead class="table-light">
                                      <tr>
                                        <th style="width:80px;">Job #</th>
                                        <th style="width:140px;">Date</th>
                                        <th>Client</th>
                                        <th style="width:140px;">Hours</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                      <?php if (!$recentJobs): ?>
                                        <tr><td colspan="4" class="text-center text-muted py-3">No completed jobs recorded yet this month.</td></tr>
                                      <?php else: foreach ($recentJobs as $row): ?>
                                        <tr>
                                          <td>#<?= (int)$row['id'] ?></td>
                                          <td><?= htmlspecialchars($row['date']) ?></td>
                                          <td><?= htmlspecialchars($row['client_name'] ?: '—') ?></td>
                                          <td><?= number_format((float)($row['hours_worked'] ?? 0), 2) ?></td>
                                        </tr>
                                      <?php endforeach; endif; ?>
                                    </tbody>
                                  </table>
                                </div>
                              </div>
                            </div>

                            <!-- Performance Reviews History -->
                            <div class="card mt-3">
                              <div class="card-header d-flex align-items-center">
                                <span class="fw-semibold">Performance Reviews History</span>
                              </div>
                              <div class="card-body">
                                <?php if(empty($performanceReviews)): ?>
                                  <div class="text-center text-muted py-3">No performance reviews recorded yet.</div>
                                <?php else: ?>
                                  <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                      <thead class="table-light">
                                        <tr>
                                          <th>Review Date</th>
                                          <th>Period</th>
                                          <th>Rating</th>
                                          <th>Reviewer</th>
                                          <th>Comments</th>
                                        </tr>
                                      </thead>
                                      <tbody>
                                        <?php foreach($performanceReviews as $review): ?>
                                          <tr>
                                            <td><?= htmlspecialchars($review['review_date'] ?? '—') ?></td>
                                            <td>
                                              <?php if (!empty($review['period_from']) && !empty($review['period_to'])): ?>
                                                <?= htmlspecialchars($review['period_from']) ?> → <?= htmlspecialchars($review['period_to']) ?>
                                              <?php else: ?>
                                                —
                                              <?php endif; ?>
                                            </td>
                                            <td>
                                              <?php if (!empty($review['rating'])): ?>
                                                <span class="badge text-bg-<?= $review['rating'] >= 4 ? 'success' : ($review['rating'] >= 3 ? 'warning' : 'danger') ?>">
                                                  <?= number_format($review['rating'], 1) ?>/5.0
                                                </span>
                                              <?php else: ?>
                                                —
                                              <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($review['reviewed_by_name'] ?? '—') ?></td>
                                            <td><?= htmlspecialchars(substr($review['comments'] ?? '', 0, 100)) ?><?= strlen($review['comments'] ?? '') > 100 ? '...' : '' ?></td>
                                          </tr>
                                        <?php endforeach; ?>
                                      </tbody>
                                    </table>
                                  </div>
                                <?php endif; ?>
                              </div>
                            </div>
                          </div>

                          <?php endif; ?>

                          <!-- Attendance -->
                          <div class="tab-pane fade" id="tab-attendance">
                            <div class="card mt-3">
                              <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                  <h6 class="mb-0">Attendance (current month)</h6>
                                  <span class="ms-auto small text-muted">
                                    <?= htmlspecialchars($performanceData['period_start']) ?> → <?= htmlspecialchars($performanceData['period_end']) ?>
                                  </span>
                                </div>
                                <div class="row g-3">
                                  <div class="col-sm-6 col-md-4 col-lg-3">
                                    <div class="card border-0 shadow-sm h-100">
                                      <div class="card-body">
                                        <div class="text-muted small">Absent Days</div>
                                        <div class="display-6 fw-semibold"><?= (int)$absenceSummary['absent_days'] ?></div>
                                        <div class="small text-muted">Marked as “absent” this month</div>
                                      </div>
                                    </div>
                                  </div>
                                  <div class="col-sm-6 col-md-4 col-lg-3">
                                    <div class="card border-0 shadow-sm h-100">
                                      <div class="card-body">
                                        <div class="text-muted small">Scheduled Workdays</div>
                                        <div class="display-6 fw-semibold"><?= (int)$scheduleSummary['expected_days'] ?></div>
                                        <div class="small text-muted">
                                          <?= !empty($scheduleSummary['has_schedule'])
                                              ? number_format((float)$scheduleSummary['expected_hours'], 2) . ' expected hours'
                                              : 'No schedule set' ?>
                                        </div>
                                      </div>
                                    </div>
                                  </div>
                                  <div class="col-sm-6 col-md-4 col-lg-3">
                                    <div class="card border-0 shadow-sm h-100">
                                      <div class="card-body">
                                        <div class="text-muted small">Missing Scheduled Days</div>
                                        <div class="display-6 fw-semibold <?= count($missingScheduledDays) ? 'text-warning' : '' ?>"><?= count($missingScheduledDays) ?></div>
                                        <div class="small text-muted">Scheduled days without attendance</div>
                                      </div>
                                    </div>
                                  </div>
                                  <div class="col-sm-6 col-md-4 col-lg-3">
                                    <div class="card border-0 shadow-sm h-100">
                                      <div class="card-body">
                                        <div class="text-muted small">Projected Deduction</div>
                                        <div class="display-6 fw-semibold text-danger">
                                          <?= $absenceSummary['deduction_amt'] > 0 ? number_format($absenceSummary['deduction_amt'], 2) . ' AED' : '0.00 AED' ?>
                                        </div>
                                        <div class="small text-muted">
                                          <?= !empty($scheduleSummary['has_schedule'])
                                              ? 'Based on scheduled workdays × absent days'
                                              : 'Based on calendar daily rate × absent days' ?>
                                        </div>
                                      </div>
                                    </div>
                                  </div>
                                </div>
                                <?php if (!empty($missingScheduledDays)): ?>
                                  <div class="alert alert-warning mt-3 mb-0 small">
                                    Missing scheduled attendance:
                                    <?= htmlspecialchars(implode(', ', array_slice(array_keys($missingScheduledDays), 0, 8))) ?>
                                    <?= count($missingScheduledDays) > 8 ? ' +' . (count($missingScheduledDays) - 8) . ' more' : '' ?>
                                  </div>
                                <?php endif; ?>
                              </div>
                            </div>

                            <div class="card mt-3">
                              <div class="card-body p-0">
                                <div class="table-responsive">
                                  <table class="table table-hover mb-0 align-middle">
                                    <thead class="table-light">
                                      <tr>
                                        <th style="width:160px;">Date</th>
                                        <th style="width:140px;">Status</th>
                                        <th style="width:130px;">Hours</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                      <?php if (!$attendanceRows): ?>
                                        <tr><td colspan="3" class="text-center text-muted py-3">No attendance activity recorded this month.</td></tr>
                                      <?php else: foreach ($attendanceRows as $row): ?>
                                        <?php
                                          $status = strtolower($row['status'] ?? '');
                                          $statusLabel = ucfirst(str_replace('_',' ', $status));
                                          $badgeMap = ['approved'=>'success','pending'=>'warning','absent'=>'danger','on_leave'=>'info','half'=>'primary'];
                                          $badgeClass = $badgeMap[$status] ?? 'secondary';
                                        ?>
                                        <tr>
                                          <td><?= htmlspecialchars($row['work_date']) ?></td>
                                          <td><span class="badge text-bg-<?= $badgeClass ?>"><?= htmlspecialchars($statusLabel ?: 'Unknown') ?></span></td>
                                          <td><?= number_format((float)($row['hours'] ?? 0), 2) ?></td>
                                        </tr>
                                      <?php endforeach; endif; ?>
                                    </tbody>
                                  </table>
                                </div>
                              </div>
                            </div>
                          </div>

                          <!-- Payslips -->
                          <div class="tab-pane fade" id="tab-payslips">
                            <div class="card mt-3">
                              <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                  <h6 class="mb-0">Payslips</h6>
                                  <span class="ms-auto small text-muted">Sorted by most recent period</span>
                                </div>
                                <div class="table-responsive">
                                  <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                      <tr>
                                        <th style="width:80px;">Run #</th>
                                        <th style="width:200px;">Period</th>
                                        <th style="width:120px;">Status</th>
                                        <th style="width:140px;">Net Pay (AED)</th>
                                        <th class="text-end" style="width:140px;">Actions</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                      <?php if (!$payslipRows): ?>
                                        <tr><td colspan="5" class="text-center text-muted py-3">No payslips published yet.</td></tr>
                                      <?php else: foreach ($payslipRows as $slip): ?>
                                        <?php
                                          $badgeMap = [
                                            'draft'     => 'secondary',
                                            'open'      => 'primary',
                                            'finalized' => 'info',
                                            'paid'      => 'success',
                                            'cancelled' => 'dark'
                                          ];
                                          $badgeClass = $badgeMap[strtolower($slip['status'] ?? '')] ?? 'secondary';
                                        ?>
                                        <tr>
                                          <td><?= (int)$slip['run_id'] ?></td>
                                          <td><?= htmlspecialchars($slip['period_from']) ?> → <?= htmlspecialchars($slip['period_to']) ?></td>
                                          <td><span class="badge text-bg-<?= $badgeClass ?>"><?= htmlspecialchars(ucfirst($slip['status'])) ?></span></td>
                                          <td><?= number_format((float)($slip['net_pay'] ?? 0), 2) ?></td>
                                          <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary"
                                               href="payslip.php?run_id=<?= (int)$slip['run_id'] ?>&employee_id=<?= (int)$emp['id'] ?>"
                                               target="_blank">
                                              View
                                            </a>
                                          </td>
                                        </tr>
                                      <?php endforeach; endif; ?>
                                    </tbody>
                                  </table>
                                </div>
                              </div>
                            </div>
                          </div>

                          <?php if (!$workerReadOnly): ?>
                          <!-- Top Workers -->
                          <div class="tab-pane fade" id="tab-topworkers">
                            <div class="card mt-3">
                              <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                  <h6 class="mb-0">Top 5 Workers This Month</h6>
                                  <span class="ms-auto small text-muted">
                                    Based on completed job hours (<?= htmlspecialchars($performanceData['period_start']) ?> → <?= htmlspecialchars($performanceData['period_end']) ?>)
                                  </span>
                                </div>
                                <div class="table-responsive">
                                  <table class="table table-bordered align-middle">
                                    <thead class="table-light">
                                      <tr>
                                        <th style="width:70px;">Rank</th>
                                        <th>Name</th>
                                        <th style="width:130px;">Employee #</th>
                                        <th style="width:140px;">Hours Worked</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                      <?php if (!$topWorkers): ?>
                                        <tr><td colspan="4" class="text-center text-muted py-3">No job hours recorded yet this month.</td></tr>
                                      <?php else: foreach ($topWorkers as $idx => $worker): ?>
                                        <?php $isSelf = $workerId && (int)$worker['id'] === $workerId; ?>
                                        <tr<?= $isSelf ? ' class="table-primary"' : '' ?>>
                                          <td>#<?= $idx + 1 ?></td>
                                          <td><?= htmlspecialchars($worker['worker_name']) ?></td>
                                          <td><?= htmlspecialchars($worker['employee_code'] ?? '—') ?></td>
                                          <td><?= number_format((float)$worker['hours_worked'], 2) ?></td>
                                        </tr>
                                      <?php endforeach; endif; ?>
                                    </tbody>
                                  </table>
                                </div>
                                <?php if ($workerId && !empty($topWorkers) && !array_filter($topWorkers, fn($w) => (int)$w['id'] === $workerId)): ?>
                                  <div class="alert alert-info mt-3 mb-0">
                                    Keep going! Your current hours: <strong><?= number_format($performanceData['worked_hours'], 2) ?></strong>.
                                  </div>
                                <?php endif; ?>
                              </div>
                            </div>
                          </div>

                          <?php endif; ?>

                          <!-- Emergency Contacts -->
                          <div class="tab-pane fade" id="tab-emergency">
                            <div class="card mt-3">
                              <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                  <h6 class="mb-0">Emergency Contacts</h6>
                                  <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#emergencyContactModal" onclick="newEmergencyContact()">+ Add Contact</button>
                                </div>
                                <?php if($emergency_msg): ?><div class="alert alert-success py-2"><?= htmlspecialchars($emergency_msg) ?></div><?php endif; ?>
                                <div class="table-responsive">
                                  <table class="table table-bordered align-middle table-sm">
                                    <thead class="table-light">
                                      <tr>
                                        <th>Name</th>
                                        <th>Relationship</th>
                                        <th>Phone</th>
                                        <th>Email</th>
                                        <th>Priority</th>
                                        <th class="text-end">Actions</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                      <?php if(!$emergencyContacts): ?>
                                        <tr><td colspan="6" class="text-center text-muted py-3">No emergency contacts added.</td></tr>
                                      <?php else: foreach($emergencyContacts as $ec): ?>
                                        <tr>
                                          <td><?= htmlspecialchars($ec['name']) ?></td>
                                          <td><?= htmlspecialchars($ec['relationship'] ?? '—') ?></td>
                                          <td><?= htmlspecialchars($ec['phone'] ?? '—') ?></td>
                                          <td><?= htmlspecialchars($ec['email'] ?? '—') ?></td>
                                          <td><span class="badge text-bg-<?= $ec['priority']==1?'primary':($ec['priority']==2?'info':'secondary') ?>"><?= $ec['priority']==1?'Primary':($ec['priority']==2?'Secondary':'Other') ?></span></td>
                                          <td class="text-end">
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this contact?')">
                                              <?php csrf_field(); ?>
                                              <input type="hidden" name="del_emergency_contact" value="<?= (int)$ec['id'] ?>">
                                              <button class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                          </td>
                                        </tr>
                                      <?php endforeach; endif; ?>
                                    </tbody>
                                  </table>
                                </div>
                              </div>
                            </div>
                          </div>

                          <!-- Internal Notes (HR Only) -->
                          <?php if (!$workerReadOnly): ?>
                          <div class="tab-pane fade" id="tab-notes">
                            <div class="card mt-3">
                              <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                  <h6 class="mb-0">Internal Notes</h6>
                                  <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#noteModal" onclick="newNote()">+ Add Note</button>
                                </div>
                                <?php if($note_msg): ?><div class="alert alert-success py-2"><?= htmlspecialchars($note_msg) ?></div><?php endif; ?>
                                <div class="list-group">
                                  <?php if(!$employeeNotes): ?>
                                    <div class="text-center text-muted py-3">No notes added.</div>
                                  <?php else: foreach($employeeNotes as $note): ?>
                                    <div class="list-group-item">
                                      <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                          <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="badge text-bg-<?= $note['note_type']==='disciplinary'?'danger':($note['note_type']==='performance'?'warning':'secondary') ?>"><?= htmlspecialchars(ucfirst($note['note_type'])) ?></span>
                                            <small class="text-muted"><?= htmlspecialchars($note['created_by_name'] ?? 'Unknown') ?> • <?= date('M j, Y g:i A', strtotime($note['created_at'])) ?></small>
                                          </div>
                                          <div><?= nl2br(htmlspecialchars($note['note_text'])) ?></div>
                                        </div>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this note?')">
                                          <?php csrf_field(); ?>
                                          <input type="hidden" name="del_note" value="<?= (int)$note['id'] ?>">
                                          <button class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                      </div>
                                    </div>
                                  <?php endforeach; endif; ?>
                                </div>
                              </div>
                            </div>
                          </div>
                          <?php endif; ?>

                          <?php if (!$workerReadOnly): ?>
                          <!-- Employment History -->
                          <div class="tab-pane fade" id="tab-history">
                            <div class="card mt-3">
                              <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                  <h6 class="mb-0">Employment History Timeline</h6>
                                  <?php if (!$workerReadOnly): ?>
                                  <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#historyModal" onclick="newHistory()">+ Add Entry</button>
                                  <?php endif; ?>
                                </div>
                                <?php if($history_msg): ?><div class="alert alert-success py-2"><?= htmlspecialchars($history_msg) ?></div><?php endif; ?>
                                <?php if(!$employmentHistory): ?>
                                  <div class="text-center text-muted py-3">No history entries.</div>
                                <?php else: ?>
                                  <div class="timeline">
                                    <?php foreach($employmentHistory as $hist): ?>
                                      <div class="timeline-item mb-3 p-3 border-start border-3 border-primary">
                                        <div class="d-flex justify-content-between align-items-start">
                                          <div class="flex-grow-1">
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                              <span class="badge text-bg-primary"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $hist['event_type']))) ?></span>
                                              <span class="fw-semibold"><?= htmlspecialchars($hist['event_date']) ?></span>
                                            </div>
                                            <?php if ($hist['title']): ?>
                                              <div class="fw-semibold mb-1"><?= htmlspecialchars($hist['title']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($hist['dept_name'] || $hist['loc_name']): ?>
                                              <div class="text-muted small mb-1">
                                                <?= htmlspecialchars($hist['dept_name'] ?? '') ?>
                                                <?= $hist['dept_name'] && $hist['loc_name'] ? ' • ' : '' ?>
                                                <?= htmlspecialchars($hist['loc_name'] ?? '') ?>
                                              </div>
                                            <?php endif; ?>
                                            <?php if ($hist['salary_before'] || $hist['salary_after']): ?>
                                              <div class="text-muted small mb-1">
                                                <?php if ($hist['salary_before']): ?>Before: <?= number_format($hist['salary_before'], 2) ?><?php endif; ?>
                                                <?php if ($hist['salary_before'] && $hist['salary_after']): ?> → <?php endif; ?>
                                                <?php if ($hist['salary_after']): ?>After: <?= number_format($hist['salary_after'], 2) ?><?php endif; ?>
                                              </div>
                                            <?php endif; ?>
                                            <?php if ($hist['notes']): ?>
                                              <div class="mt-1"><?= nl2br(htmlspecialchars($hist['notes'])) ?></div>
                                            <?php endif; ?>
                                            <div class="text-muted small mt-1">Added by <?= htmlspecialchars($hist['created_by_name'] ?? 'System') ?></div>
                                          </div>
                                          <?php if (!$workerReadOnly): ?>
                                          <form method="post" class="d-inline" onsubmit="return confirm('Delete this entry?')">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="del_history" value="<?= (int)$hist['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                                          </form>
                                          <?php endif; ?>
                                        </div>
                                      </div>
                                    <?php endforeach; ?>
                                  </div>
                                <?php endif; ?>
                              </div>
                            </div>
                          </div>

                          <?php endif; ?>

                          <!-- Work Schedule -->
                          <div class="tab-pane fade" id="tab-schedule">
                            <div class="card mt-3">
                              <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                  <h6 class="mb-0">Work Schedule</h6>
                                  <?php if (!$workerReadOnly): ?>
                                  <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#scheduleModal">+ Set Schedule</button>
                                  <?php endif; ?>
                                </div>
                                <?php if($schedule_msg): ?><div class="alert alert-success py-2"><?= htmlspecialchars($schedule_msg) ?></div><?php endif; ?>
                                <?php if($workSchedule): ?>
                                  <div class="row g-3">
                                    <div class="col-md-12">
                                      <div class="card bg-light">
                                        <div class="card-body">
                                          <h6 class="card-title">Current Schedule</h6>
                                          <div class="row">
                                            <div class="col-md-3 mb-2"><strong>Monday:</strong> <?= htmlspecialchars($workSchedule['monday_hours'] ?: '—') ?></div>
                                            <div class="col-md-3 mb-2"><strong>Tuesday:</strong> <?= htmlspecialchars($workSchedule['tuesday_hours'] ?: '—') ?></div>
                                            <div class="col-md-3 mb-2"><strong>Wednesday:</strong> <?= htmlspecialchars($workSchedule['wednesday_hours'] ?: '—') ?></div>
                                            <div class="col-md-3 mb-2"><strong>Thursday:</strong> <?= htmlspecialchars($workSchedule['thursday_hours'] ?: '—') ?></div>
                                            <div class="col-md-3 mb-2"><strong>Friday:</strong> <?= htmlspecialchars($workSchedule['friday_hours'] ?: '—') ?></div>
                                            <div class="col-md-3 mb-2"><strong>Saturday:</strong> <?= htmlspecialchars($workSchedule['saturday_hours'] ?: '—') ?></div>
                                            <div class="col-md-3 mb-2"><strong>Sunday:</strong> <?= htmlspecialchars($workSchedule['sunday_hours'] ?: '—') ?></div>
                                          </div>
                                          <?php if ($workSchedule['notes']): ?>
                                            <div class="mt-2"><strong>Notes:</strong> <?= nl2br(htmlspecialchars($workSchedule['notes'])) ?></div>
                                          <?php endif; ?>
                                          <div class="small text-muted mt-2">
                                            Effective from: <?= htmlspecialchars($workSchedule['effective_from']) ?>
                                            <?php if ($workSchedule['effective_to']): ?>
                                              → <?= htmlspecialchars($workSchedule['effective_to']) ?>
                                            <?php else: ?>
                                              (ongoing)
                                            <?php endif; ?>
                                          </div>
                                        </div>
                                      </div>
                                    </div>
                                  </div>
                                <?php else: ?>
                                  <div class="text-center text-muted py-3">No work schedule set.</div>
                                <?php endif; ?>
                              </div>
                            </div>
                          </div>

                          <!-- Activity Log -->
                          <div class="tab-pane fade" id="tab-activity">
                            <div class="card mt-3">
                              <div class="card-body">
                                <h6 class="mb-3">Activity Log</h6>
                                <div class="table-responsive">
                                  <table class="table table-sm table-hover">
                                    <thead class="table-light">
                                      <tr>
                                        <th>Date & Time</th>
                                        <th>Action</th>
                                        <th>Description</th>
                                        <th>Changed Field</th>
                                        <th>Performed By</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                      <?php if(!$activityLog): ?>
                                        <tr><td colspan="5" class="text-center text-muted py-3">No activity recorded.</td></tr>
                                      <?php else: foreach($activityLog as $act): ?>
                                        <tr>
                                          <td><?= date('M j, Y g:i A', strtotime($act['created_at'])) ?></td>
                                          <td><span class="badge text-bg-secondary"><?= htmlspecialchars($act['action_type']) ?></span></td>
                                          <td><?= htmlspecialchars($act['action_description'] ?? '—') ?></td>
                                          <td><?= htmlspecialchars($act['changed_field'] ?? '—') ?></td>
                                          <td><?= htmlspecialchars($act['performed_by_name'] ?? 'System') ?></td>
                                      </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                  </table>
                                </div>
                              </div>
                            </div>
                          </div>
                          
</div>

<!-- Add/Edit Document Modal (existing) -->
<?php if (!$workerReadOnly): ?>
<div class="modal fade" id="docModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" enctype="multipart/form-data">
                          <?php csrf_field(); ?>
      <div class="modal-header">
        <h5 class="modal-title" id="docModalTitle">Add Document</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="doc_action" id="doc_action" value="create">
        <input type="hidden" name="doc_id" id="doc_id">

        <div class="mb-2">
            <label class="form-label">Type</label>
            <select name="doc_type" id="doc_type" class="form-select" required>
                <option value="">-- choose --</option>
                <option>Passport</option>
                <option>Visa</option>
                <option>LaborCard</option>
                <option>National ID</option>
                <option>Contract</option>
                <option>Other</option>
            </select>
        </div>
        <div class="mb-2">
            <label class="form-label">Number</label>
            <input type="text" name="doc_number" id="doc_number" class="form-control">
        </div>
        <div class="row">
            <div class="col-md-6 mb-2">
                <label class="form-label">Issued at</label>
                <input type="date" name="issued_at" id="issued_at" class="form-control">
            </div>
            <div class="col-md-6 mb-2">
                <label class="form-label">Expires at</label>
                <input type="date" name="expires_at" id="expires_at" class="form-control">
            </div>
        </div>
        <div class="mb-2">
            <label class="form-label">File (PDF/JPG/PNG)</label>
            <input type="file" name="doc_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
        </div>
        <div>
            <label class="form-label">Notes</label>
            <textarea name="notes" id="notes" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-success">Save</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Edit Contact Info Modal (for employees - self-service) -->
<?php if ($isWorkerSelfService): ?>
<div class="modal fade" id="editContactInfoModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <?php csrf_field(); ?>
      <div class="modal-header">
        <h5 class="modal-title">Update Contact Information</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="update_contact_info" value="1">
        <div class="mb-3">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($emp['email'] ?? '') ?>" placeholder="Enter email address">
          <small class="text-muted">Leave empty to remove email</small>
        </div>
        <div class="mb-3">
          <label class="form-label">Mobile Number</label>
          <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($emp['phone'] ?? '') ?>" placeholder="Enter mobile number">
          <small class="text-muted">Leave empty to remove phone number</small>
        </div>
        <div class="alert alert-info small mb-0">
          <i class="bi bi-info-circle me-1"></i>You can only update your own contact information.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- New Leave Request Modal -->
<div class="modal fade" id="leaveModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" enctype="multipart/form-data">
                          <?php csrf_field(); ?>
      <div class="modal-header">
        <h5 class="modal-title">New Leave Request</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="leave_create" value="1">
        <div class="mb-2">
          <label class="form-label">Type *</label>
          <select name="leave_type_id" class="form-select" required>
            <option value="">-- Select --</option>
            <?php foreach ($leave_types as $tid=>$tn): ?>
              <option value="<?= (int)$tid ?>"><?= htmlspecialchars($tn) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row">
          <div class="col-md-6 mb-2">
            <label class="form-label">From *</label>
            <input type="date" name="date_from" class="form-control" required>
          </div>
          <div class="col-md-6 mb-2">
            <label class="form-label">To *</label>
            <input type="date" name="date_to" class="form-control" required>
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label">Reason</label>
          <input name="reason" class="form-control">
        </div>
        <div class="mb-2">
          <label class="form-label">Attachment</label>
          <input type="file" name="leave_attachment" class="form-control">
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-success" id="leaveSubmitBtn">
          <span class="spinner-border spinner-border-sm d-none me-2" role="status" aria-hidden="true"></span>
          <span class="btn-text">Submit</span>
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Request Cash Advance Modal (for employees) -->
<?php if ($workerReadOnly): 
    $eligibility = $cashAdvancePolicy ? checkCashAdvanceEligibility($conn, $emp, $cashAdvancePolicy, $employeeMaxLimit) : ['eligible' => true, 'reasons' => [], 'remaining_limit' => null];
    $maxAmount = $cashAdvancePolicy ? getMaxAdvanceAmount($conn, $emp, $cashAdvancePolicy, $employeeMaxLimit) : null;
    $currentOutstanding = $cashAdvancePolicy ? getCurrentOutstandingBalance($conn, (int)$emp['id']) : 0;
    // Determine the effective maximum (per-request limit or remaining total limit, whichever is lower)
    $effectiveMax = $maxAmount;
    if ($eligibility['remaining_limit'] !== null) {
        $effectiveMax = $effectiveMax === null ? $eligibility['remaining_limit'] : min($effectiveMax, $eligibility['remaining_limit']);
    }
?>
<div class="modal fade" id="requestCashAdvanceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" id="cashAdvanceRequestForm">
      <?php csrf_field(); ?>
      <div class="modal-header">
        <h5 class="modal-title">Request Cash Advance</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="cash_advance_request" value="1">
        
        <?php if (!$eligibility['eligible']): ?>
        <div class="alert alert-warning">
          <strong><i class="bi bi-exclamation-triangle me-2"></i>Not Eligible:</strong>
          <ul class="mb-0 mt-2">
            <?php foreach ($eligibility['reasons'] as $reason): ?>
              <li><?= htmlspecialchars($reason) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
        
        <?php 
        $effectiveMax = $maxAmount;
        if ($eligibility['remaining_limit'] !== null) {
            $effectiveMax = $effectiveMax === null ? $eligibility['remaining_limit'] : min($effectiveMax, $eligibility['remaining_limit']);
        }
        if ($maxAmount !== null || $eligibility['remaining_limit'] !== null): ?>
        <div class="alert alert-info">
          <?php if ($maxAmount !== null): ?>
            <div><strong>Maximum Per Request:</strong> <?= number_format($maxAmount, 2) ?> AED</div>
          <?php endif; ?>
          <?php if ($eligibility['remaining_limit'] !== null): ?>
            <div class="mt-1">
              <strong>Current Outstanding:</strong> <?= number_format($currentOutstanding, 2) ?> AED · 
              <strong>Remaining Limit:</strong> <span class="text-<?= $eligibility['remaining_limit'] > 0 ? 'success' : 'danger' ?>"><?= number_format($eligibility['remaining_limit'], 2) ?> AED</span>
            </div>
          <?php endif; ?>
          <?php if ($effectiveMax !== null && $effectiveMax < ($maxAmount ?? PHP_INT_MAX)): ?>
            <div class="mt-1"><strong>Effective Maximum:</strong> <?= number_format($effectiveMax, 2) ?> AED (limited by total advance limit)</div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="mb-3">
          <label class="form-label">Amount (AED) *</label>
          <input type="number" name="requested_amount" step="0.01" min="0.01" 
                 <?= $effectiveMax !== null ? 'max="' . number_format($effectiveMax, 2, '.', '') . '"' : '' ?>
                 class="form-control" required placeholder="Enter amount" id="requestedAmountInput"
                 <?= !$eligibility['eligible'] ? 'disabled' : '' ?>>
          <?php if ($effectiveMax !== null): ?>
            <div class="form-text">Maximum allowed: <?= number_format($effectiveMax, 2) ?> AED</div>
          <?php endif; ?>
        </div>
        <div class="mb-3">
          <label class="form-label">Reason *</label>
          <textarea name="reason" class="form-control" rows="3" required placeholder="Please provide a reason for this cash advance request"></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label">Date</label>
          <input type="date" name="tx_date" value="<?= date('Y-m-d') ?>" class="form-control" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary" id="cashAdvanceSubmitBtn">
          <span class="spinner-border spinner-border-sm d-none me-2" role="status" aria-hidden="true"></span>
          <span class="btn-text">Submit Request</span>
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Approve/Reject Cash Advance Modals (for Owner only) -->
<?php if ($isOwner && !empty($cashRows)): ?>
  <?php foreach($cashRows as $r): 
    if (($r['request_status'] ?? null) !== 'pending') continue;
    $reqId = (int)$r['id'];
    $reqAmount = (float)($r['requested_amount'] ?? $r['amount']);
  ?>
  <!-- Approve Modal for Request #<?= $reqId ?> -->
  <div class="modal fade" id="approveCashAdvanceModal<?= $reqId ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content" method="post">
        <?php csrf_field(); ?>
        <div class="modal-header">
          <h5 class="modal-title">Approve Cash Advance Request</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="approve_cash_adv" value="<?= $reqId ?>">
          <div class="mb-3">
            <label class="form-label">Requested Amount</label>
            <input type="text" class="form-control" value="<?= number_format($reqAmount, 2) ?> AED" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Approved Amount (AED) *</label>
            <input type="number" name="approved_amount" step="0.01" min="0.01" class="form-control" value="<?= number_format($reqAmount, 2, '.', '') ?>" required>
            <small class="text-muted">You can approve a different amount than requested.</small>
          </div>
          <div class="mb-3">
            <label class="form-label">Reason</label>
            <textarea name="description" class="form-control" rows="2" readonly><?= htmlspecialchars($r['description'] ?? '') ?></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success">Approve</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Reject Modal for Request #<?= $reqId ?> -->
  <div class="modal fade" id="rejectCashAdvanceModal<?= $reqId ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content" method="post">
        <?php csrf_field(); ?>
        <div class="modal-header">
          <h5 class="modal-title">Reject Cash Advance Request</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="reject_cash_adv" value="<?= $reqId ?>">
          <div class="mb-3">
            <label class="form-label">Requested Amount</label>
            <input type="text" class="form-control" value="<?= number_format($reqAmount, 2) ?> AED" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Reason</label>
            <textarea name="description" class="form-control" rows="2" readonly><?= htmlspecialchars($r['description'] ?? '') ?></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Rejection Reason *</label>
            <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="Please provide a reason for rejecting this request"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-danger">Reject</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<!-- Emergency Contact Modal -->
<div class="modal fade" id="emergencyContactModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <?php csrf_field(); ?>
      <div class="modal-header">
        <h5 class="modal-title">Add Emergency Contact</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="add_emergency_contact" value="1">
        <div class="mb-3">
          <label class="form-label">Name *</label>
          <input type="text" name="name" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Relationship</label>
          <input type="text" name="relationship" class="form-control" placeholder="e.g. Spouse, Parent, Sibling">
        </div>
        <div class="mb-3">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-control">
        </div>
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control">
        </div>
        <div class="mb-3">
          <label class="form-label">Priority</label>
          <select name="priority" class="form-select">
            <option value="1">Primary</option>
            <option value="2">Secondary</option>
            <option value="3">Other</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">Add Contact</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Internal Note Modal -->
<?php if (!$workerReadOnly): ?>
<div class="modal fade" id="noteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <?php csrf_field(); ?>
      <div class="modal-header">
        <h5 class="modal-title">Add Internal Note</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="add_note" value="1">
        <div class="mb-3">
          <label class="form-label">Note Type</label>
          <select name="note_type" class="form-select">
            <option value="general">General</option>
            <option value="disciplinary">Disciplinary</option>
            <option value="performance">Performance</option>
            <option value="reminder">Reminder</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Note *</label>
          <textarea name="note_text" class="form-control" rows="5" required></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">Add Note</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Employment History Modal -->
<?php if (!$workerReadOnly): ?>
<div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post">
      <?php csrf_field(); ?>
      <div class="modal-header">
        <h5 class="modal-title">Add Employment History Entry</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="add_history" value="1">
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">Event Type *</label>
            <select name="event_type" class="form-select" required>
              <option value="hire">Hire</option>
              <option value="promotion">Promotion</option>
              <option value="transfer">Transfer</option>
              <option value="salary_change">Salary Change</option>
              <option value="position_change">Position Change</option>
              <option value="termination">Termination</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Event Date *</label>
            <input type="date" name="event_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Title/Position</label>
          <input type="text" name="title" class="form-control">
        </div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">Department</label>
            <select name="department_id" class="form-select">
              <option value="">—</option>
              <?php 
              $depts = $conn->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
              foreach($depts as $did => $dname): ?>
                <option value="<?= $did ?>"><?= htmlspecialchars($dname) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Location</label>
            <select name="location_id" class="form-select">
              <option value="">—</option>
              <?php 
              $locs = $conn->query("SELECT id, name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
              foreach($locs as $lid => $lname): ?>
                <option value="<?= $lid ?>"><?= htmlspecialchars($lname) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">Salary Before</label>
            <input type="number" step="0.01" name="salary_before" class="form-control">
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Salary After</label>
            <input type="number" step="0.01" name="salary_after" class="form-control">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="3"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">Add Entry</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Work Schedule Modal -->
<?php if (!$workerReadOnly): ?>
<div class="modal fade" id="scheduleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post">
      <?php csrf_field(); ?>
      <div class="modal-header">
        <h5 class="modal-title">Set Work Schedule</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="save_schedule" value="1">
        <div class="mb-3">
          <label class="form-label">Schedule Type</label>
          <select name="schedule_type" class="form-select">
            <option value="regular">Regular</option>
            <option value="shift">Shift</option>
            <option value="flexible">Flexible</option>
            <option value="part_time">Part Time</option>
          </select>
        </div>
        <div class="row">
          <div class="col-md-6 mb-2">
            <label class="form-label">Monday</label>
            <input type="text" name="monday_hours" class="form-control" placeholder="e.g. 9:00 AM - 5:00 PM">
          </div>
          <div class="col-md-6 mb-2">
            <label class="form-label">Tuesday</label>
            <input type="text" name="tuesday_hours" class="form-control" placeholder="e.g. 9:00 AM - 5:00 PM">
          </div>
          <div class="col-md-6 mb-2">
            <label class="form-label">Wednesday</label>
            <input type="text" name="wednesday_hours" class="form-control" placeholder="e.g. 9:00 AM - 5:00 PM">
          </div>
          <div class="col-md-6 mb-2">
            <label class="form-label">Thursday</label>
            <input type="text" name="thursday_hours" class="form-control" placeholder="e.g. 9:00 AM - 5:00 PM">
          </div>
          <div class="col-md-6 mb-2">
            <label class="form-label">Friday</label>
            <input type="text" name="friday_hours" class="form-control" placeholder="e.g. 9:00 AM - 5:00 PM">
          </div>
          <div class="col-md-6 mb-2">
            <label class="form-label">Saturday</label>
            <input type="text" name="saturday_hours" class="form-control" placeholder="e.g. Off or 9:00 AM - 1:00 PM">
          </div>
          <div class="col-md-6 mb-2">
            <label class="form-label">Sunday</label>
            <input type="text" name="sunday_hours" class="form-control" placeholder="e.g. Off or 9:00 AM - 1:00 PM">
          </div>
        </div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">Effective From</label>
            <input type="date" name="effective_from" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Effective To (optional)</label>
            <input type="date" name="effective_to" class="form-control">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">Save Schedule</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

</div>
<?php
$pageScripts = <<<'HR_EMP_VIEW_JS'
<script>
function newDoc() {
    document.getElementById('docModalTitle').innerText = 'Add Document';
    document.getElementById('doc_action').value = 'create';
    document.getElementById('doc_id').value = '';
    document.getElementById('doc_type').value = '';
    document.getElementById('doc_number').value = '';
    document.getElementById('issued_at').value = '';
    document.getElementById('expires_at').value = '';
    document.getElementById('notes').value = '';
}
function editDoc(d) {
    document.getElementById('docModalTitle').innerText = 'Edit Document';
    document.getElementById('doc_action').value = 'update';
    document.getElementById('doc_id').value = d.id;
    document.getElementById('doc_type').value = d.doc_type || '';
    document.getElementById('doc_number').value = d.doc_number || '';
    document.getElementById('issued_at').value = (d.issued_at && d.issued_at !== '0000-00-00') ? d.issued_at : '';
    document.getElementById('expires_at').value = (d.expires_at && d.expires_at !== '0000-00-00') ? d.expires_at : '';
    document.getElementById('notes').value = d.notes || '';
}

function newEmergencyContact() {
    const form = document.querySelector('#emergencyContactModal form');
    if (form) form.reset();
}

function newNote() {
    const form = document.querySelector('#noteModal form');
    if (form) form.reset();
    const select = form.querySelector('select[name="note_type"]');
    if (select) select.value = 'general';
}

function newHistory() {
    const form = document.querySelector('#historyModal form');
    if (form) form.reset();
    const dateInput = form.querySelector('input[name="event_date"]');
    if (dateInput) dateInput.value = new Date().toISOString().split('T')[0];
}

// Debug logging function
window.debugEmployeeView = function(msg, data) {
    console.log('[Employee View Debug]', msg, data || '');
};

// Function to switch tabs from buttons
function changeOverviewDateRange(range) {
    const url = new URL(window.location);
    url.searchParams.set('overview_range', range);
    window.location.href = url.toString();
}

function switchToTab(tabId) {
    const trigger = document.querySelector(`[data-bs-target="#${tabId}"]`);
    if (trigger) {
        const tab = new bootstrap.Tab(trigger);
        tab.show();
        // Update URL hash
        window.location.hash = tabId;
    }
}

// Avatar upload auto-submit with loading indicator
document.addEventListener('DOMContentLoaded', function() {
    const avatarFileInput = document.getElementById('avatarFileInput');
    const avatarUploadBtn = document.querySelector('.avatar-action-btn[onclick*="avatarFileInput"]');
    
    if (avatarFileInput && avatarUploadBtn) {
        avatarFileInput.addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                // Show loading state
                const originalHTML = avatarUploadBtn.innerHTML;
                avatarUploadBtn.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:10px;height:10px;border-width:2px;"></span>';
                avatarUploadBtn.style.pointerEvents = 'none';
                avatarUploadBtn.style.opacity = '0.6';
                
                const form = document.getElementById('avatarUploadForm');
                if (form) {
                    form.submit();
                } else {
                    // Restore button if form not found
                    setTimeout(() => {
                        avatarUploadBtn.innerHTML = originalHTML;
                        avatarUploadBtn.style.pointerEvents = '';
                        avatarUploadBtn.style.opacity = '';
                    }, 2000);
                }
            }
        });
    }
});

// Simple, direct approach - no complex handlers needed
// The form will submit directly, and the link will navigate directly
// Just ensure the form submission works correctly
document.addEventListener('DOMContentLoaded', function() {
    console.log('[Employee View Debug] DOMContentLoaded fired');
    
    // Initialize Bootstrap tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Handle hash navigation for tabs
    const hash = window.location.hash;
    if (hash) {
        const trigger = document.querySelector(`[data-bs-target="${hash}"]`);
        if (trigger) {
            // Use setTimeout to ensure Bootstrap is ready
            setTimeout(() => {
                const tab = new bootstrap.Tab(trigger);
                tab.show();
                // Scroll to top of tab content if needed
                const targetEl = document.querySelector(hash);
                if (targetEl) {
                    targetEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }, 100);
        }
    }
});

// Policy Information Collapse Icon Toggle
document.addEventListener('DOMContentLoaded', function() {
    const policyCollapse = document.getElementById('policyDetailsCollapse');
    const policyIcon = document.getElementById('policyDetailsIcon');
    
    if (policyCollapse && policyIcon) {
        policyCollapse.addEventListener('show.bs.collapse', function() {
            policyIcon.classList.remove('bi-chevron-down');
            policyIcon.classList.add('bi-chevron-up');
        });
        
        policyCollapse.addEventListener('hide.bs.collapse', function() {
            policyIcon.classList.remove('bi-chevron-up');
            policyIcon.classList.add('bi-chevron-down');
        });
    }
});

// Loading indicators for forms
document.addEventListener('DOMContentLoaded', function() {
    // Leave request form
    const leaveForm = document.querySelector('#leaveModal form');
    if (leaveForm) {
        leaveForm.addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('leaveSubmitBtn');
            if (submitBtn) {
                const spinner = submitBtn.querySelector('.spinner-border');
                const btnText = submitBtn.querySelector('.btn-text');
                if (spinner && btnText) {
                    spinner.classList.remove('d-none');
                    btnText.textContent = 'Submitting...';
                    submitBtn.disabled = true;
                }
            }
        });
    }
    
    // Cash advance request form
    const cashAdvanceForm = document.querySelector('#cashAdvanceRequestForm');
    if (cashAdvanceForm) {
        const amountInput = document.getElementById('requestedAmountInput');
        const maxAmount = amountInput ? parseFloat(amountInput.getAttribute('max')) : null;
        
        // Validate amount on input
        if (amountInput && maxAmount) {
            amountInput.addEventListener('input', function() {
                const value = parseFloat(this.value);
                if (value > maxAmount) {
                    this.setCustomValidity('Amount cannot exceed ' + maxAmount.toFixed(2) + ' AED');
                    this.classList.add('is-invalid');
                } else {
                    this.setCustomValidity('');
                    this.classList.remove('is-invalid');
                }
            });
        }
        
        cashAdvanceForm.addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('cashAdvanceSubmitBtn');
            if (submitBtn) {
                const spinner = submitBtn.querySelector('.spinner-border');
                const btnText = submitBtn.querySelector('.btn-text');
                if (spinner && btnText) {
                    spinner.classList.remove('d-none');
                    btnText.textContent = 'Submitting...';
                    submitBtn.disabled = true;
                }
            }
        });
    }
});
</script>
HR_EMP_VIEW_JS;
require_once __DIR__ . '/includes/hr_layout_footer.php';
