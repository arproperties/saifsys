<?php
/**
 * User Profile Page
 * 
 * Comprehensive user profile management with:
 * - Profile overview and statistics
 * - Account settings (edit basic info)
 * - Security (password change, login history)
 * - Activity history (from audit_log)
 * - User preferences (theme, notifications, etc.)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/url_helper.php';
require_once __DIR__ . '/includes/profile_functions.php';
require_once __DIR__ . '/includes/branding.php';
require_once __DIR__ . '/lib/Guard.php';

// Get branding settings
$brand = getBrandSettings($conn);

// Determine roles and worker self-service mode
$currentRoles = current_user_roles($conn);
$isWorkerSelfService = Guard::isWorker($currentRoles);

/* ---------- LOGIN GUARD ---------- */
$hasUserObject = !empty($_SESSION['user']) && is_array($_SESSION['user']);
$hasLegacy     = !empty($_SESSION['username']) || !empty($_SESSION['fullname']);
if (!$hasUserObject && !$hasLegacy) {
  // Store next URL in session for clean URLs
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $_SESSION['login_next'] = preg_replace('/\.php$/', '', $_SERVER['REQUEST_URI'] ?? '/');
  header('Location: login');
  exit;
}

/* ---------- CURRENT USER ---------- */
$U = $hasUserObject ? $_SESSION['user'] : [];
$userId = (int)($U['id'] ?? $_SESSION['user_id'] ?? 0);

if (!$userId) {
    header('Location: login');
    exit;
}

// Get complete user profile
$profile = getUserProfile($conn, $userId);
if (!$profile) {
    die('Error loading profile');
}

// Get user statistics
$stats = getUserStats($conn, $userId);

// Get login history
$loginHistory = getUserLoginHistory($conn, $userId, 10);

// Helper function
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Get avatar or initial
$avatarPath = $profile['avatar_path'] ?? null;
$fullName = $profile['fullname'] ?? $profile['full_name'] ?? 'User';
$userName = $profile['username'] ?? '';
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));
$userRole = $profile['roles'] ?? 'User';
$viewerEmployeeId = (int)($profile['employee_id'] ?? ($_SESSION['user']['employee_id'] ?? 0));
if (!$viewerEmployeeId) {
    $stmtEmployeeLink = $conn->prepare("SELECT employee_id FROM `user` WHERE id = ? LIMIT 1");
    $stmtEmployeeLink->execute([$userId]);
    $viewerEmployeeId = (int)($stmtEmployeeLink->fetchColumn() ?: 0);
}

if (!function_exists('app_get_setting')) {
    function app_get_setting(PDO $conn, string $key, $default = '')
    {
        static $appSettingCache = [];
        if (array_key_exists($key, $appSettingCache)) {
            return $appSettingCache[$key];
        }
        $stmt = $conn->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            $appSettingCache[$key] = $default;
            return $default;
        }
        $appSettingCache[$key] = $value;
        return $value;
    }
}

$employeeOfMonthData = null;
$employeeOfMonthBanner = null;
$employeeOfMonthHeadline = 'Employee of the Month';
$employeeOfMonthInitial = '';
$employeeOfMonthIsViewer = false;
$employeeOfMonthTargetHoursSetting = (float)app_get_setting($conn, 'employee_of_month_target_hours', '160');
$employeeOfMonthMessageSetting = app_get_setting($conn, 'employee_of_month_message', "🎉 Congratulations {name}! You're our Employee of the Month! 🌟");
$employeeOfMonthMonthSetting = app_get_setting($conn, 'employee_of_month_month', date('Y-m-01'));
$employeeOfMonthMonthDate = date('Y-m-01', strtotime($employeeOfMonthMonthSetting));
$selectedEmployeeOfMonthId = (int)app_get_setting($conn, 'employee_of_month_id', '0');
$currentAwardRecord = null;

try {
    $awardStmt = $conn->prepare("
        SELECT ea.*, e.full_name, e.employee_code, e.position_title, u.avatar_path
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
    $employeeOfMonthIsViewer = ($employeeAwardIdValue > 0 && $viewerEmployeeId === $employeeAwardIdValue);
}

$profileFeatureFlags = [
    'stats'    => (int)app_get_setting($conn, 'emp_profile_show_stats', '1') === 1,
    'kudos'    => (int)app_get_setting($conn, 'emp_profile_show_kudos', '1') === 1,
    'rewards'  => (int)app_get_setting($conn, 'emp_profile_show_rewards', '1') === 1,
    'progress' => (int)app_get_setting($conn, 'emp_profile_show_progress', '1') === 1,
    'history'  => (int)app_get_setting($conn, 'emp_profile_show_history', '1') === 1,
];

$employeeRecord = null;
$performanceData = [
    'period_start'    => date('Y-m-01'),
    'period_end'      => date('Y-m-t'),
    'worked_hours'    => 0.0,
    'approved_days'   => 0,
    'overtime_hours'  => 0.0,
    'avg_hours'       => 0.0,
    'target_hours'    => null,
    'achievement_pct' => null,
    'jobs'            => 0,
    'has_worker'      => false,
];
$attendanceStreakDays = 0;
$attendanceStreakSince = null;
$totalLeaveBalance = 0.0;
$progressTargetHours = $employeeOfMonthTargetHoursSetting > 0 ? $employeeOfMonthTargetHoursSetting : null;
$progressActualHours = 0.0;
$progressPercent = null;
$latestAwardForEmployee = null;
$latestAwardChecklists = [];
$awardHistory = [];
$kudosFeed = [];

if ($viewerEmployeeId > 0) {
    $empStmt = $conn->prepare("SELECT * FROM employees WHERE id = ? LIMIT 1");
    $empStmt->execute([$viewerEmployeeId]);
    $employeeRecord = $empStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($employeeRecord) {
        $monthStart = new DateTimeImmutable(date('Y-m-01'));
        $monthEnd   = $monthStart->modify('last day of this month');

        $workerRow = null;
        $workerId  = null;
        $workerCandidates = [];
        if (!empty($employeeRecord['employee_code'])) $workerCandidates[] = ['field' => 'emp_num',     'value' => $employeeRecord['employee_code']];
        if (!empty($employeeRecord['nickname']))      $workerCandidates[] = ['field' => 'nickname',    'value' => $employeeRecord['nickname']];
        if (!empty($employeeRecord['full_name']))     $workerCandidates[] = ['field' => 'worker_name', 'value' => $employeeRecord['full_name']];

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
            : (isset($employeeRecord['daily_cap_hours']) ? (float)$employeeRecord['daily_cap_hours'] : 0.0);
        $weeklyCapHours = isset($workerRow['weekly_cap_hours']) && $workerRow['weekly_cap_hours'] !== null
            ? (float)$workerRow['weekly_cap_hours']
            : (isset($employeeRecord['weekly_cap_hours']) ? (float)$employeeRecord['weekly_cap_hours'] : 0.0);
        $otThreshold    = $dailyCapHours > 0 ? $dailyCapHours : 8.0;

        $performanceData['period_start'] = $monthStart->format('Y-m-d');
        $performanceData['period_end']   = $monthEnd->format('Y-m-d');
        $performanceData['has_worker']   = (bool)$workerId;

        if ($workerId) {
            try {
                // Calculate hours with daily cap (exclude overtime) - group by day, cap, then sum
                $dailyCapForProfile = $dailyCapHours > 0 ? $dailyCapHours : 8.0;
                
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
                    $cappedHours += min($dayHours, $dailyCapForProfile);
                }
                
                // Get other metrics
                $coreStmt = $conn->prepare("
                    SELECT
                        COUNT(DISTINCT mo.id) AS jobs,
                        COUNT(DISTINCT COALESCE(mo.service_date, mo.date)) AS working_days
                    FROM order_workers ow
                    JOIN make_order mo ON mo.id = ow.order_id
                    WHERE ow.worker_id = :wid
                      AND mo.status IN ('confirmed','completed')
                      AND COALESCE(mo.service_date, mo.date) BETWEEN :start AND :end
                ");
                $coreStmt->execute([
                    ':wid'   => $workerId,
                    ':start' => $monthStart->format('Y-m-d'),
                    ':end'   => $monthEnd->format('Y-m-d'),
                ]);
                $core = $coreStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $performanceData['jobs']          = (int)($core['jobs'] ?? 0);
                $performanceData['worked_hours']  = round($cappedHours, 2); // Use capped hours (overtime excluded)
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
            } catch (Throwable $e) {
                // ignore performance errors
            }
        }

        if ($weeklyCapHours > 0) {
            $performanceData['target_hours'] = round($weeklyCapHours * 4.33, 2);
        } elseif ($dailyCapHours > 0) {
            $performanceData['target_hours'] = round($dailyCapHours * 26, 2);
        }
        if ($performanceData['target_hours'] && $performanceData['target_hours'] > 0) {
            $performanceData['achievement_pct'] = round(($performanceData['worked_hours'] / $performanceData['target_hours']) * 100, 1);
        }

        $progressActualHours = $performanceData['worked_hours'];
        if ($progressTargetHours && $progressTargetHours > 0) {
            $progressPercent = min(100, round(($progressActualHours / $progressTargetHours) * 100, 1));
        }

        try {
            $balStmt = $conn->prepare("
              SELECT closing
              FROM leave_balances
              WHERE employee_id = ? AND year = ?
            ");
            $balStmt->execute([$viewerEmployeeId, (int)date('Y')]);
            $balances = $balStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($balances as $closing) {
                $totalLeaveBalance += (float)$closing;
            }
        } catch (Throwable $e) {
            $totalLeaveBalance = 0.0;
        }

        try {
            $streakStmt = $conn->prepare("
                SELECT work_date, status
                FROM attendance
                WHERE employee_id = :eid
                  AND work_date <= CURDATE()
                ORDER BY work_date DESC
                LIMIT 60
            ");
            $streakStmt->execute([':eid' => $viewerEmployeeId]);
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
            $attendanceStreakDays = 0;
            $attendanceStreakSince = null;
        }

        try {
            $awardStmt = $conn->prepare("
                SELECT ea.*, e.full_name, e.employee_code, u.avatar_path
                FROM employee_awards ea
                JOIN employees e ON e.id = ea.employee_id
                LEFT JOIN `user` u ON u.id = e.user_id
                WHERE ea.award_type = 'employee_of_month'
                  AND ea.employee_id = ?
                ORDER BY ea.award_month DESC
                LIMIT 1
            ");
            $awardStmt->execute([$viewerEmployeeId]);
            $latestAwardForEmployee = $awardStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($latestAwardForEmployee) {
                $checkStmt = $conn->prepare("
                    SELECT id, item_label, is_done
                    FROM employee_award_checklists
                    WHERE award_id = ?
                    ORDER BY id
                ");
                $checkStmt->execute([$latestAwardForEmployee['id']]);
                $latestAwardChecklists = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) {
            $latestAwardForEmployee = null;
            $latestAwardChecklists = [];
        }

        if ($profileFeatureFlags['history']) {
            try {
                $historyStmt = $conn->query("
                    SELECT ea.*, e.full_name, e.employee_code
                    FROM employee_awards ea
                    JOIN employees e ON e.id = ea.employee_id
                    WHERE ea.award_type = 'employee_of_month'
                    ORDER BY ea.award_month DESC
                    LIMIT 8
                ");
                $awardHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $awardHistory = [];
            }
        }

        if ($profileFeatureFlags['kudos']) {
            try {
                $kudosQuery = "
                    SELECT k.*, au.fullname AS author_name, au.username AS author_username
                    FROM employee_kudos k
                    LEFT JOIN `user` au ON au.id = k.author_id
                    WHERE k.employee_id = ?
                ";
                if ($isWorkerSelfService) {
                    $kudosQuery .= " AND k.visibility = 'public'";
                }
                $kudosQuery .= " ORDER BY k.created_at DESC LIMIT 6";
                $kudosStmt = $conn->prepare($kudosQuery);
                $kudosStmt->execute([$viewerEmployeeId]);
                $kudosFeed = $kudosStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $kudosFeed = [];
            }
        }
    }
}

$showProgressCard = $profileFeatureFlags['progress'] && $progressTargetHours;
$showRewardsCard = $profileFeatureFlags['rewards'] && $latestAwardForEmployee;
$showHistoryCard = $profileFeatureFlags['history'] && !empty($awardHistory);
$showKudosCard = $profileFeatureFlags['kudos'];
$showStatsColumn = $profileFeatureFlags['stats'] && $viewerEmployeeId > 0;
$showRecognitionRow = ($employeeOfMonthBanner && $employeeOfMonthData)
    || $showProgressCard
    || $showKudosCard
    || $showRewardsCard
    || $showHistoryCard
    || $showStatsColumn;

// Active tab (from URL parameter)
// Handle tab selection via POST (for clean URLs) or GET (backward compatibility)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tab'])) {
    $_SESSION['profile_tab'] = $_POST['tab'];
    $activeTab = $_POST['tab'];
    // Redirect to clean URL with hash for the tab (so it opens correctly)
    header('Location: ' . (get_base_path() ? get_base_path() . '/' : '/') . 'profile#' . urlencode($_POST['tab']));
    exit;
} else {
    $activeTab = $_GET['tab'] ?? $_SESSION['profile_tab'] ?? 'overview';
    if (isset($_GET['tab'])) {
        $_SESSION['profile_tab'] = $activeTab;
    }
}
$validTabs = ['overview', 'settings', 'security', 'activity', 'preferences'];
if ($isWorkerSelfService) {
    $validTabs = ['security'];
    if ($activeTab !== 'security') {
        // Workers can only access security tab
        $_SESSION['profile_tab'] = 'security';
        header('Location: ' . (get_base_path() ? get_base_path() . '/' : '/') . 'profile');
        exit;
    }
}
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'overview';
    if ($isWorkerSelfService) {
        $activeTab = 'security';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>My Profile | <?= h($brand['system_name']) ?></title>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root {
      --primary: <?= $brand['primary_color'] ?>;
      --primary-light: <?= $brand['primary_light'] ?>;
      --primary-dark: <?= $brand['primary_dark'] ?>;
      --accent: <?= $brand['accent_color'] ?>;
      --success: #28a745;
      --warning: #ffc107;
      --danger: #dc3545;
      --info: #17a2b8;
      --light: #f8f9fa;
      --dark: #212529;
      --shadow-sm: 0 2px 4px rgba(0,0,0,0.08);
      --shadow: 0 4px 12px rgba(0,0,0,0.1);
      --shadow-lg: 0 8px 24px rgba(0,0,0,0.12);
    }
    
    body { 
      background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
      min-height: 100vh;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    .celebration-banner {
      background: linear-gradient(120deg,#fff4db,#ffe3f1);
      border-radius: 16px;
      padding: 14px 18px;
      box-shadow: 0 8px 18px rgba(255,143,0,0.16);
      border: 1px solid rgba(255,196,53,0.35);
      display: flex;
      align-items: center;
      gap: 16px;
      margin-bottom: 18px;
      position: relative;
      overflow: hidden;
    }
    .celebration-banner::after {
      content: "🎊";
      font-size: 64px;
      opacity: 0.15;
      position: absolute;
      right: 20px;
      bottom: -10px;
      pointer-events: none;
    }
    .celebration-avatar {
      width: 60px;
      height: 60px;
      border-radius: 50%;
      background: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 26px;
      color: #ff7a59;
      box-shadow: 0 4px 10px rgba(0,0,0,0.08);
      flex-shrink: 0;
      overflow: hidden;
    }
    .celebration-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: inherit;
    }
    .celebration-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #fff;
      border-radius: 999px;
      padding: 5px 10px;
      font-size: 0.8rem;
      font-weight: 600;
      color: #ff7a59;
      margin-bottom: 6px;
      box-shadow: 0 3px 8px rgba(255,122,89,0.25);
    }
    .celebration-title {
      font-weight: 700;
      font-size: 1.1rem;
      margin: 0;
      color: #d9480f;
    }
    .celebration-text {
      margin: 4px 0 0;
      font-size: 0.95rem;
      color: #864c25;
    }
    .celebration-self {
      margin-left: 8px;
      font-size: 0.8rem;
      border-radius: 8px;
      background: #ffe066;
      color: #7f4f24;
      padding: 2px 8px;
    }
    .recognition-row{display:flex;flex-direction:column;gap:16px;margin-bottom:20px}
    @media (min-width:1200px){.recognition-row{flex-direction:row;align-items:stretch}}
    .recognition-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:12px}
    .recognition-side{width:100%;display:flex;flex-direction:row;gap:12px;flex-wrap:wrap}
    @media (min-width:1200px){.recognition-side{flex-direction:column;width:260px}}
    .snapshot-card{flex:1 1 130px;border-radius:14px;padding:12px 14px;background:linear-gradient(135deg,#ffffff,#f5f7ff);box-shadow:0 6px 18px rgba(15,23,42,0.08);border:1px solid #e7edff}
    .snapshot-label{font-size:0.78rem;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px}
    .snapshot-value{font-size:1.6rem;font-weight:700;color:#0f172a;line-height:1.2}
    .snapshot-value small{font-size:0.9rem;font-weight:500;color:#475569;margin-left:4px}
    .snapshot-meta{font-size:0.8rem;color:#94a3b8}
    .progress-card{border-radius:14px;padding:14px;background:#fffbe6;border:1px solid #ffe7a3;box-shadow:0 8px 20px rgba(255,193,7,0.18)}
    .progress-card .progress{height:8px;border-radius:999px;background:rgba(255,193,7,0.3)}
    .kudos-card{border-radius:14px;padding:14px;background:#f3f6ff;border:1px solid #dbe4ff;box-shadow:0 8px 18px rgba(99,102,241,0.12)}
    .kudos-entry{display:flex;flex-direction:column;gap:4px;border-radius:10px;padding:10px 12px;background:#fff;box-shadow:0 1px 6px rgba(15,23,42,0.08);margin-bottom:8px}
    .kudos-entry:last-child{margin-bottom:0}
    .kudos-entry small{color:#64748b;font-size:0.8rem}
    .reward-card{border-radius:14px;padding:14px;background:#ecfdf3;border:1px solid #bbf7d0;box-shadow:0 8px 18px rgba(34,197,94,0.12)}
    .reward-list{list-style:none;padding-left:0;margin:0}
    .reward-list li{display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid rgba(15,23,42,0.08)}
    .reward-list li:last-child{border-bottom:0}
    .history-strip{display:flex;gap:10px;overflow-x:auto;padding-bottom:4px}
    .history-item{min-width:170px;border-radius:12px;padding:12px;background:#ffffff;border:1px solid #e2e8f0;box-shadow:0 4px 12px rgba(15,23,42,0.08)}
    
    /* Sidebar (same as index.php) */
    .sidebar {
      min-height: 100vh;
      width: 240px;
      background: linear-gradient(180deg, var(--primary), var(--primary-light));
      color: #fff;
      position: sticky;
      top: 0;
      z-index: 1030;
      transition: width 0.2s ease;
    }
    .sidebar.collapsed { width: 84px; }
    .brand { 
      font-weight: 800;
      letter-spacing: 0.3px;
      color: var(--accent);
      opacity: 0.95;
    }
    .nav-sect {
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #ffefc4;
      opacity: 0.7;
      margin: 0.5rem 0 0.25rem 0.75rem;
    }
    .slink {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.6rem 0.9rem;
      margin: 0.15rem 0.5rem;
      border-radius: 10px;
      color: #fff;
      text-decoration: none;
      transition: all 0.2s;
    }
    .slink:hover {
      background: rgba(255,255,255,0.08);
      transform: translateX(2px);
    }
    .slink.active {
      background: rgba(255,255,255,0.16);
    }
    .sicon {
      width: 28px;
      height: 28px;
      display: grid;
      place-items: center;
      background: rgba(255,255,255,0.15);
      border-radius: 8px;
    }
    .slabel { white-space: nowrap; }
    .sidebar.collapsed .slabel { display: none; }
    .sidebar.collapsed .nav-sect { display: none; }
    .collapse-btn {
      position: absolute;
      right: -12px;
      top: 12px;
      width: 24px;
      height: 24px;
      border-radius: 50%;
      background: #fff;
      color: var(--primary);
      box-shadow: var(--shadow);
      display: grid;
      place-items: center;
      cursor: pointer;
      transition: all 0.2s;
    }
    .collapse-btn:hover { transform: scale(1.1); }
    
    /* Top Navigation */
    .top-nav {
      background: rgba(255,255,255,0.95);
      backdrop-filter: blur(10px);
      box-shadow: var(--shadow-sm);
      padding: 0.75rem 0;
      position: relative;
      z-index: 1040;
    }
    
    /* Profile Header */
    .profile-header {
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
      color: white;
      border-radius: 20px;
      padding: 2rem;
      margin-bottom: 2rem;
      box-shadow: var(--shadow-lg);
    }
    
    /* Profile Avatar */
    .profile-avatar {
      width: 120px;
      height: 120px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      font-size: 3rem;
      font-weight: 700;
      border: 4px solid rgba(255,255,255,0.3);
      background: rgba(255,255,255,0.2);
      backdrop-filter: blur(10px);
    }
    .profile-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 50%;
    }
    
    /* Cards */
    .card-modern {
      border: 0;
      border-radius: 16px;
      box-shadow: var(--shadow);
      background: white;
      transition: all 0.3s;
    }
    .card-modern:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-lg);
    }
    
    /* Tabs */
    .nav-tabs-modern {
      border-bottom: 2px solid #dee2e6;
      margin-bottom: 2rem;
    }
    .nav-tabs-modern .nav-link {
      border: none;
      color: #6c757d;
      padding: 1rem 1.5rem;
      font-weight: 500;
      transition: all 0.2s;
    }
    .nav-tabs-modern .nav-link:hover {
      color: var(--primary);
      border-color: transparent;
    }
    .nav-tabs-modern .nav-link.active {
      color: var(--primary);
      background: transparent;
      border-bottom: 3px solid var(--primary);
      margin-bottom: -2px;
    }
    
    /* Stats Cards */
    .stat-card {
      text-align: center;
      padding: 1.5rem;
      border-radius: 12px;
      background: white;
      box-shadow: var(--shadow-sm);
    }
    .stat-value {
      font-size: 2rem;
      font-weight: 700;
      color: var(--primary);
    }
    .stat-label {
      color: #6c757d;
      font-size: 0.875rem;
      margin-top: 0.5rem;
    }
    
    /* Avatar */
    .avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: var(--light);
      display: grid;
      place-items: center;
      font-weight: 700;
      color: var(--primary);
      border: 2px solid white;
      box-shadow: var(--shadow-sm);
    }
    
    /* Badge */
    .badge-role {
      padding: 0.5rem 1rem;
      border-radius: 8px;
      font-weight: 500;
    }
    
    /* Form */
    .form-label {
      font-weight: 600;
      color: #495057;
      margin-bottom: 0.5rem;
    }
    .form-control, .form-select {
      border-radius: 8px;
      border: 1px solid #dee2e6;
      padding: 0.625rem 1rem;
    }
    .form-control:focus, .form-select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 0.2rem rgba(122, 0, 0, 0.1);
    }
    
    /* Buttons */
    .btn-primary {
      background: var(--primary);
      border-color: var(--primary);
      padding: 0.625rem 1.5rem;
      border-radius: 8px;
      font-weight: 600;
    }
    .btn-primary:hover {
      background: var(--primary-dark);
      border-color: var(--primary-dark);
    }
    
    /* Password strength */
    .password-strength {
      height: 4px;
      border-radius: 2px;
      background: #e9ecef;
      margin-top: 0.5rem;
      overflow: hidden;
    }
    .password-strength-bar {
      height: 100%;
      width: 0%;
      transition: all 0.3s;
    }
    .password-strength-bar.weak { background: #dc3545; width: 33%; }
    .password-strength-bar.medium { background: #ffc107; width: 66%; }
    .password-strength-bar.strong { background: #28a745; width: 100%; }
    
    /* Activity timeline */
    .activity-item {
      padding: 1rem;
      border-left: 3px solid #e9ecef;
      margin-bottom: 1rem;
      position: relative;
    }
    .activity-item::before {
      content: '';
      position: absolute;
      left: -7px;
      top: 1.5rem;
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: var(--primary);
    }
    .activity-item.success::before { background: var(--success); }
    .activity-item.danger::before { background: var(--danger); }
    
    /* Avatar upload */
    .avatar-upload {
      position: relative;
      display: inline-block;
    }
    .avatar-upload-btn {
      position: absolute;
      bottom: 0;
      right: 0;
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: var(--primary);
      color: white;
      display: grid;
      place-items: center;
      cursor: pointer;
      border: 3px solid white;
      transition: all 0.2s;
    }
    .avatar-upload-btn:hover {
      background: var(--primary-dark);
      transform: scale(1.1);
    }
    
    /* Responsive */
    @media (max-width: 768px) {
      .sidebar {
        width: 84px !important;
      }
      .sidebar .slabel,
      .sidebar .nav-sect {
        display: none !important;
      }
      .profile-avatar {
        width: 80px;
        height: 80px;
        font-size: 2rem;
      }
    }
    
    /* Toast notifications */
    .toast-container {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 9999;
    }
    
    /* Dropdown z-index */
    .dropdown-menu {
      z-index: 1050 !important;
    }
    
    /* Dark Mode Styles */
    body.dark-mode {
      background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
      color: #e4e4e7;
    }
    
    body.dark-mode .profile-card,
    body.dark-mode .card {
      background: #1e293b;
      color: #e4e4e7;
      border-color: #334155;
    }
    
    body.dark-mode .top-bar {
      background: rgba(30, 41, 59, 0.95);
      border-bottom: 1px solid #334155;
    }
    
    body.dark-mode .sidebar {
      background: linear-gradient(180deg, var(--primary-dark), var(--primary));
    }
    
    body.dark-mode .nav-pills .nav-link {
      color: #94a3b8;
    }
    
    body.dark-mode .nav-pills .nav-link.active {
      background: var(--primary);
      color: white;
    }
    
    body.dark-mode .form-control,
    body.dark-mode .form-select {
      background: #334155;
      border-color: #475569;
      color: #e4e4e7;
    }
    
    body.dark-mode .form-control:focus,
    body.dark-mode .form-select:focus {
      background: #475569;
      border-color: var(--primary);
      color: #e4e4e7;
    }
    
    body.dark-mode .text-muted {
      color: #94a3b8 !important;
    }
    
    body.dark-mode .table {
      color: #e4e4e7;
    }
    
    body.dark-mode .table-striped tbody tr {
      background: #1e293b;
    }
    
    body.dark-mode .table-striped tbody tr:nth-of-type(odd) {
      background: #334155;
    }
    
    body.dark-mode .badge {
      background: #334155;
    }
  </style>
</head>
<body<?= $brand['dark_mode_enabled'] ? ' class="dark-mode"' : '' ?>>
<div class="d-flex">
  <!-- Sidebar -->
  <aside id="sb" class="sidebar d-flex flex-column p-3">
    <div class="d-flex align-items-center gap-2 mb-2">
      <?php if (!empty($brand['logo_path']) && file_exists(__DIR__ . '/' . $brand['logo_path'])): ?>
        <img src="<?= h($brand['logo_path']) ?>" alt="<?= h($brand['system_name']) ?>" style="width: 40px; height: 40px; object-fit: contain; border-radius: 8px;">
      <?php endif; ?>
      <span class="brand ms-1"><?= h($brand['system_name']) ?></span>
    </div>
    <div class="collapse-btn" id="sbToggle" title="Collapse/Expand">
      <i class="bi bi-chevron-left"></i>
    </div>

    <?php if (!$isWorkerSelfService): ?>
    <div class="nav-sect mt-3">Main</div>
    <a href="<?= get_base_path() ? get_base_path() . '/' : '/' ?>index" class="slink">
      <span class="sicon"><i class="bi bi-house"></i></span>
      <span class="slabel">Home</span>
    </a>
    <a href="<?= get_base_path() ? get_base_path() . '/' : '/' ?>operation" class="slink">
      <span class="sicon"><i class="bi bi-gear-wide-connected"></i></span>
      <span class="slabel">Operation</span>
    </a>
    <a href="<?= get_base_path() ? get_base_path() . '/' : '/' ?>account" class="slink">
      <span class="sicon"><i class="bi bi-wallet2"></i></span>
      <span class="slabel">Accounts</span>
    </a>

    <div class="nav-sect">Other</div>
    <a href="<?= get_base_path() ? get_base_path() . '/' : '/' ?>settings" class="slink">
      <span class="sicon"><i class="bi bi-sliders"></i></span>
      <span class="slabel">Settings</span>
    </a>
    <?php 
    if (has_role('Owner', $conn) || has_role('Admin', $conn)): 
    ?>
    <a href="<?= get_base_path() ? get_base_path() . '/' : '/' ?>settings?tab=companies" class="slink">
      <span class="sicon"><i class="bi bi-building-add"></i></span>
      <span class="slabel">Companies Setup</span>
    </a>
    <?php endif; ?>
    <?php endif; ?>
    <a href="profile.php" class="slink active">
      <span class="sicon"><i class="bi bi-person-circle"></i></span>
      <span class="slabel">Profile</span>
    </a>
  </aside>

  <!-- Main Content -->
  <div class="flex-grow-1">
    <!-- Top Navigation -->
    <nav class="top-nav">
      <div class="container-fluid px-4">
        <div class="d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-3">
            <?php if ($isWorkerSelfService && $viewerEmployeeId > 0): ?>
            <a href="hr/employee_view.php?id=<?= (int)$viewerEmployeeId ?>" class="btn btn-outline-secondary btn-sm">
              <i class="bi bi-arrow-left me-1"></i>Back
            </a>
            <?php endif; ?>
            <span class="navbar-brand fw-bold mb-0" style="color: var(--primary)">My Profile</span>
          </div>
          <div class="dropdown">
            <a href="#" class="d-flex align-items-center text-dark text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
              <div class="avatar me-2"><?= h($avatarInitial) ?></div>
              <span class="me-2"><?= h($fullName) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow">
              <li><span class="dropdown-item-text"><strong><?= h($userName) ?></strong></span></li>
              <li><span class="dropdown-item-text text-muted small"><?= h($userRole) ?></span></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person-circle me-2"></i>Profile</a></li>
              <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
          </div>
        </div>
      </div>
    </nav>

    <!-- Content -->
    <div class="container-fluid px-4 py-4">
      <!-- Profile Header -->
      <div class="profile-header">
        <div class="row align-items-center">
          <div class="col-md-3 text-center text-md-start mb-3 mb-md-0">
            <div class="avatar-upload">
              <div class="profile-avatar d-inline-grid">
                <?php if ($avatarPath && file_exists(__DIR__ . '/' . $avatarPath)): ?>
                  <img src="<?= h($avatarPath) ?>?t=<?= time() ?>" alt="Profile">
                <?php else: ?>
                  <?= h($avatarInitial) ?>
                <?php endif; ?>
              </div>
              <label for="avatarUpload" class="avatar-upload-btn" title="Change photo">
                <i class="bi bi-camera"></i>
              </label>
              <input type="file" id="avatarUpload" accept="image/jpeg,image/png,image/jpg" style="display: none;">
            </div>
          </div>
          <div class="col-md-6">
            <h1 class="h2 mb-2"><?= h($fullName) ?></h1>
            <p class="mb-2"><i class="bi bi-at"></i> <?= h($userName) ?></p>
            <?php if ($profile['email']): ?>
              <p class="mb-2"><i class="bi bi-envelope"></i> <?= h($profile['email']) ?></p>
            <?php endif; ?>
            <?php if ($profile['job_title']): ?>
              <p class="mb-2"><i class="bi bi-briefcase"></i> <?= h($profile['job_title']) ?></p>
            <?php endif; ?>
            <div class="mt-3">
              <?php 
              $roles = explode(', ', $userRole);
              foreach ($roles as $role): 
              ?>
                <span class="badge badge-role bg-light text-dark me-2"><?= h($role) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="col-md-3">
            <div class="text-center text-md-end">
              <p class="mb-1"><small>Member since</small></p>
              <p class="h6"><?= h($profile['date_joined'] ? date('M j, Y', strtotime($profile['date_joined'])) : 'N/A') ?></p>
              <?php if ($profile['last_login']): ?>
                <p class="mb-1 mt-3"><small>Last login</small></p>
                <p class="small text-white-50"><?= h(date('M j, Y g:i A', strtotime($profile['last_login']))) ?></p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Tabs Navigation -->
      <ul class="nav nav-tabs nav-tabs-modern" role="tablist">
        <?php if (!$isWorkerSelfService): ?>
        <li class="nav-item">
          <a class="nav-link <?= $activeTab === 'overview' ? 'active' : '' ?>" href="?tab=overview">
            <i class="bi bi-person me-2"></i>Overview
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $activeTab === 'settings' ? 'active' : '' ?>" href="?tab=settings">
            <i class="bi bi-gear me-2"></i>Account Settings
          </a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
          <a class="nav-link <?= $activeTab === 'security' ? 'active' : '' ?>" href="?tab=security">
            <i class="bi bi-shield-lock me-2"></i>Security
          </a>
        </li>
        <?php if (!$isWorkerSelfService): ?>
        <li class="nav-item">
          <a class="nav-link <?= $activeTab === 'activity' ? 'active' : '' ?>" href="?tab=activity">
            <i class="bi bi-clock-history me-2"></i>Activity
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $activeTab === 'preferences' ? 'active' : '' ?>" href="?tab=preferences">
            <i class="bi bi-sliders me-2"></i>Preferences
          </a>
        </li>
        <?php endif; ?>
      </ul>

      <!-- Tab Content -->
      <div class="tab-content">
        
        <!-- OVERVIEW TAB -->
        <?php if ($activeTab === 'overview'): ?>
        <div class="tab-pane active">
          <div class="row g-4">
            <!-- Stats Cards -->
            <div class="col-md-4">
              <div class="stat-card">
                <div class="stat-value"><?= number_format($stats['total_logins']) ?></div>
                <div class="stat-label">Total Logins</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="stat-card">
                <div class="stat-value"><?= number_format($stats['profile_updates']) ?></div>
                <div class="stat-label">Profile Updates</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="stat-card">
                <div class="stat-value"><?= number_format($stats['total_actions']) ?></div>
                <div class="stat-label">Total Actions</div>
              </div>
            </div>
            
            <!-- Profile Information -->
            <div class="col-md-6">
              <div class="card-modern">
                <div class="card-body p-4">
                  <h5 class="card-title mb-4"><i class="bi bi-info-circle me-2"></i>Profile Information</h5>
                  <div class="mb-3 d-flex">
                    <div class="text-muted" style="min-width: 120px;">Full Name:</div>
                    <div class="fw-semibold flex-grow-1"><?= h($fullName) ?></div>
                  </div>
                  <div class="mb-3 d-flex">
                    <div class="text-muted" style="min-width: 120px;">Username:</div>
                    <div class="fw-semibold flex-grow-1"><?= h($userName) ?></div>
                  </div>
                  <?php if ($profile['email']): ?>
                  <div class="mb-3 d-flex">
                    <div class="text-muted" style="min-width: 120px;">Email:</div>
                    <div class="fw-semibold flex-grow-1" style="word-break: break-word;"><?= h($profile['email']) ?></div>
                  </div>
                  <?php endif; ?>
                  <?php if ($profile['phone']): ?>
                  <div class="mb-3 d-flex">
                    <div class="text-muted" style="min-width: 120px;">Phone:</div>
                    <div class="fw-semibold flex-grow-1"><?= h($profile['phone']) ?></div>
                  </div>
                  <?php endif; ?>
                  <?php if ($profile['job_title']): ?>
                  <div class="mb-3 d-flex">
                    <div class="text-muted" style="min-width: 120px;">Job Title:</div>
                    <div class="fw-semibold flex-grow-1"><?= h($profile['job_title']) ?></div>
                  </div>
                  <?php endif; ?>
                  <?php if ($profile['department']): ?>
                  <div class="mb-3 d-flex">
                    <div class="text-muted" style="min-width: 120px;">Department:</div>
                    <div class="fw-semibold flex-grow-1"><?= h($profile['department']) ?></div>
                  </div>
                  <?php endif; ?>
                  <div class="d-flex">
                    <div class="text-muted" style="min-width: 120px;">Roles:</div>
                    <div class="fw-semibold flex-grow-1"><?= h($userRole) ?></div>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- Recent Login History -->
            <div class="col-md-6">
              <div class="card-modern">
                <div class="card-body">
                  <h5 class="card-title mb-4"><i class="bi bi-clock-history me-2"></i>Recent Logins</h5>
                  <?php if ($loginHistory): ?>
                    <div class="table-responsive">
                      <table class="table table-sm">
                        <tbody>
                        <?php foreach (array_slice($loginHistory, 0, 5) as $login): ?>
                          <tr>
                            <td>
                              <i class="bi bi-<?= $login['success'] ? 'check-circle text-success' : 'x-circle text-danger' ?>"></i>
                            </td>
                            <td class="small"><?= h(date('M j, g:i A', strtotime($login['created_at']))) ?></td>
                            <td class="small text-muted"><?= h($login['ip_address']) ?></td>
                          </tr>
                        <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                    <a href="?tab=security" class="btn btn-sm btn-outline-primary w-100 mt-2">View All</a>
                  <?php else: ?>
                    <p class="text-muted text-center py-3">No login history available</p>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- SETTINGS TAB -->
        <?php if ($activeTab === 'settings'): ?>
        <div class="tab-pane active">
          <div class="row">
            <div class="col-lg-8 mx-auto">
              <div class="card-modern">
                <div class="card-body p-4">
                  <h5 class="card-title mb-4"><i class="bi bi-gear me-2"></i>Account Settings</h5>
                  
                  <form id="profileForm">
                    <div class="mb-3">
                      <label class="form-label">Full Name *</label>
                      <input type="text" class="form-control" name="fullname" value="<?= h($fullName) ?>" required>
                    </div>
                    
                    <div class="mb-3">
                      <label class="form-label">Email</label>
                      <input type="email" class="form-control" name="email" value="<?= h($profile['email'] ?? '') ?>">
                      <small class="text-muted">Used for notifications and account recovery</small>
                    </div>
                    
                    <div class="mb-3">
                      <label class="form-label">Phone</label>
                      <input type="tel" class="form-control" name="phone" value="<?= h($profile['phone'] ?? '') ?>">
                      <small class="text-muted">Format: +971-XX-XXXXXXX or local number</small>
                    </div>
                    
                    <div class="mb-3">
                      <label class="form-label">Job Title</label>
                      <input type="text" class="form-control" name="job_title" value="<?= h($profile['job_title'] ?? '') ?>">
                    </div>
                    
                    <div class="mb-3">
                      <label class="form-label">Department</label>
                      <input type="text" class="form-control" name="department" value="<?= h($profile['department'] ?? '') ?>">
                    </div>
                    
                    <div class="alert alert-info">
                      <i class="bi bi-info-circle me-2"></i>
                      <small>Your username and roles can only be changed by an administrator.</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" id="saveProfileBtn">
                      <i class="bi bi-save me-2"></i>Save Changes
                    </button>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- SECURITY TAB -->
        <?php if ($activeTab === 'security'): ?>
        <div class="tab-pane active">
          <?php if ($showRecognitionRow): ?>
          <div class="recognition-row">
            <div class="recognition-main">
              <?php if ($employeeOfMonthBanner && $employeeOfMonthData): ?>
              <div class="celebration-banner">
                <div class="celebration-avatar">
                  <?php if (!empty($employeeOfMonthData['avatar_path']) && file_exists(__DIR__ . '/' . $employeeOfMonthData['avatar_path'])): ?>
                    <img src="<?= h($employeeOfMonthData['avatar_path']) ?>" alt="<?= h($employeeOfMonthData['full_name'] ?? '') ?>">
                  <?php else: ?>
                    <?= h($employeeOfMonthInitial ?: '⭐') ?>
                  <?php endif; ?>
                </div>
                <div class="flex-grow-1">
                  <div class="celebration-badge">
                    <i class="bi bi-trophy-fill"></i>
                    <?= h($employeeOfMonthHeadline) ?>
                    <?php if ($employeeOfMonthIsViewer): ?>
                      <span class="celebration-self">That's you!</span>
                    <?php endif; ?>
                  </div>
                  <p class="celebration-title mb-1"><?= h($employeeOfMonthData['full_name'] ?? '') ?></p>
                  <p class="celebration-text mb-0"><?= nl2br(h($employeeOfMonthBanner)) ?></p>
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
                  Target <?= $progressTargetHours ? number_format($progressTargetHours, 2) : '—' ?> hrs • Current <?= number_format($progressActualHours, 2) ?> hrs
                </div>
              </div>
              <?php endif; ?>

              <?php if ($showKudosCard): ?>
              <div class="kudos-card">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h6 class="mb-0"><i class="bi bi-chat-heart-fill me-2"></i>Peer Kudos</h6>
                  <?php if (!$isWorkerSelfService): ?>
                    <a class="small text-decoration-none" href="settings" data-tab="emp_profile">Manage</a>
                  <?php endif; ?>
                </div>
                <?php if ($kudosFeed): ?>
                  <?php foreach ($kudosFeed as $kudos): ?>
                    <div class="kudos-entry">
                      <div class="fw-semibold"><?= nl2br(h($kudos['message'])) ?></div>
                      <small>
                        <?= h(date('M j, g:i A', strtotime($kudos['created_at']))) ?>
                        • <?= h($kudos['author_name'] ?? $kudos['author_username'] ?? 'Team') ?>
                        <?php if (($kudos['visibility'] ?? 'public') === 'private' && !$isWorkerSelfService): ?>
                          <span class="badge bg-secondary ms-2">Private</span>
                        <?php endif; ?>
                      </small>
                    </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <p class="text-muted mb-0">No kudos yet. Your supervisors can add one from Emp Profile Settings.</p>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <?php if ($showRewardsCard): ?>
              <div class="reward-card">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h6 class="mb-0"><i class="bi bi-gift-fill me-2"></i>Reward Tracker</h6>
                  <span class="badge bg-success-subtle text-success"><?= h(date('M Y', strtotime($latestAwardForEmployee['award_month']))) ?></span>
                </div>
                <?php if ($latestAwardChecklists): ?>
                  <ul class="reward-list">
                    <?php foreach ($latestAwardChecklists as $item): ?>
                      <?php $done = ((int)$item['is_done'] === 1); ?>
                      <li>
                        <i class="bi <?= $done ? 'bi-check-circle-fill text-success' : 'bi-hourglass-split text-muted' ?>"></i>
                        <div class="flex-grow-1">
                          <strong><?= h($item['item_label']) ?></strong>
                          <div class="small text-muted"><?= $done ? 'Completed' : 'Pending' ?></div>
                        </div>
                        <span class="badge <?= $done ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>">
                          <?= $done ? 'Done' : 'Next up' ?>
                        </span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <p class="text-muted mb-0">Checklist coming soon. Admins can configure this from Emp Profile Settings.</p>
                <?php endif; ?>
                <?php if (!$isWorkerSelfService): ?>
                  <div class="text-end mt-2">
                    <a class="small text-decoration-none" href="settings" data-tab="emp_profile">Update checklist</a>
                  </div>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <?php if ($showHistoryCard): ?>
              <div class="card border-0 shadow-sm p-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Award History</h6>
                  <?php if (!$isWorkerSelfService): ?>
                    <a class="small text-decoration-none" href="settings" data-tab="emp_profile">Manage</a>
                  <?php endif; ?>
                </div>
                <div class="history-strip">
                  <?php foreach ($awardHistory as $history): ?>
                  <div class="history-item">
                    <span class="badge bg-warning-subtle text-warning-emphasis mb-2"><?= h(date('M Y', strtotime($history['award_month']))) ?></span>
                    <strong><?= h($history['full_name']) ?></strong>
                    <?php if (!empty($history['headline'])): ?>
                      <div class="small text-muted"><?= h($history['headline']) ?></div>
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
                <div class="snapshot-value"><?= number_format($performanceData['worked_hours'], 2) ?><small>hrs</small></div>
                <div class="snapshot-meta">
                  <?= $progressTargetHours ? 'Target ' . number_format($progressTargetHours, 0) . ' hrs' : 'This month' ?>
                </div>
              </div>
              <div class="snapshot-card">
                <div class="snapshot-label">Leave Balance</div>
                <div class="snapshot-value"><?= number_format($totalLeaveBalance, 1) ?><small>days</small></div>
                <div class="snapshot-meta">Across all leave types</div>
              </div>
              <div class="snapshot-card">
                <div class="snapshot-label">Attendance Streak</div>
                <div class="snapshot-value"><?= $attendanceStreakDays > 0 ? (int)$attendanceStreakDays : 0 ?><small>days</small></div>
                <div class="snapshot-meta">
                  <?= ($attendanceStreakDays > 0 && $attendanceStreakSince) ? 'Since ' . h(date('M j', strtotime($attendanceStreakSince))) : 'Start logging shifts' ?>
                </div>
              </div>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <div class="row g-4">
            <!-- Change Password -->
            <div class="col-lg-6">
              <div class="card-modern">
                <div class="card-body p-4">
                  <h5 class="card-title mb-4"><i class="bi bi-key me-2"></i>Change Password</h5>
                  
                  <form id="passwordForm">
                    <div class="mb-3">
                      <label class="form-label">Current Password *</label>
                      <input type="password" class="form-control" name="current_password" required>
                    </div>
                    
                    <div class="mb-3">
                      <label class="form-label">New Password *</label>
                      <input type="password" class="form-control" name="new_password" id="newPassword" required>
                      <div class="password-strength">
                        <div class="password-strength-bar" id="strengthBar"></div>
                      </div>
                      <small class="text-muted" id="strengthText">Enter a password</small>
                    </div>
                    
                    <div class="mb-3">
                      <label class="form-label">Confirm New Password *</label>
                      <input type="password" class="form-control" name="confirm_password" id="confirmPassword" required>
                      <small class="text-danger" id="passwordMatchError" style="display: none;">Passwords do not match</small>
                    </div>
                    
                    <div class="alert alert-light">
                      <small>
                        <strong>Password requirements:</strong><br>
                        • At least 8 characters<br>
                        • At least one uppercase letter<br>
                        • At least one lowercase letter<br>
                        • At least one number
                      </small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" id="changePasswordBtn">
                      <i class="bi bi-shield-check me-2"></i>Change Password
                    </button>
                  </form>
                </div>
              </div>
            </div>
            
            <!-- Login History -->
            <div class="col-lg-6">
              <div class="card-modern">
                <div class="card-body p-4">
                  <h5 class="card-title mb-4"><i class="bi bi-clock-history me-2"></i>Login History</h5>
                  
                  <?php if ($loginHistory): ?>
                    <div style="max-height: 400px; overflow-y: auto;">
                      <?php foreach ($loginHistory as $login): ?>
                        <div class="activity-item <?= $login['success'] ? 'success' : 'danger' ?>">
                          <div class="d-flex justify-content-between align-items-start">
                            <div>
                              <p class="mb-1 fw-semibold">
                                <?= $login['success'] ? 'Successful Login' : 'Failed Login Attempt' ?>
                              </p>
                              <p class="mb-0 small text-muted">
                                <i class="bi bi-geo-alt"></i> <?= h($login['ip_address']) ?>
                              </p>
                              <?php if ($login['user_agent']): ?>
                                <p class="mb-0 small text-muted">
                                  <i class="bi bi-display"></i> <?= h(substr($login['user_agent'], 0, 50)) ?>...
                                </p>
                              <?php endif; ?>
                            </div>
                            <small class="text-muted"><?= h(date('M j, g:i A', strtotime($login['created_at']))) ?></small>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <p class="text-muted text-center py-4">No login history available</p>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- ACTIVITY TAB -->
        <?php if ($activeTab === 'activity'): ?>
        <div class="tab-pane active">
          <div class="card-modern">
            <div class="card-body p-4">
              <h5 class="card-title mb-4"><i class="bi bi-activity me-2"></i>Activity History</h5>
              
              <div id="activityContainer">
                <div class="text-center py-4">
                  <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- PREFERENCES TAB -->
        <?php if ($activeTab === 'preferences'): ?>
        <div class="tab-pane active">
          <div class="row">
            <div class="col-lg-8 mx-auto">
              <div class="card-modern">
                <div class="card-body p-4">
                  <h5 class="card-title mb-4"><i class="bi bi-sliders me-2"></i>Preferences</h5>
                  
                  <form id="preferencesForm">
                    <div class="mb-4">
                      <label class="form-label">Theme</label>
                      <select class="form-select" name="theme">
                        <option value="light" <?= ($profile['theme_preference'] ?? 'light') === 'light' ? 'selected' : '' ?>>Light</option>
                        <option value="dark" <?= ($profile['theme_preference'] ?? 'light') === 'dark' ? 'selected' : '' ?>>Dark</option>
                        <option value="auto" <?= ($profile['theme_preference'] ?? 'light') === 'auto' ? 'selected' : '' ?>>Auto (System)</option>
                      </select>
                      <small class="text-muted">Choose your preferred interface theme</small>
                    </div>
                    
                    <div class="mb-4">
                      <label class="form-label">Language</label>
                      <select class="form-select" name="language">
                        <option value="en" <?= ($profile['language_preference'] ?? 'en') === 'en' ? 'selected' : '' ?>>English</option>
                        <option value="ar" <?= ($profile['language_preference'] ?? 'en') === 'ar' ? 'selected' : '' ?>>Arabic (العربية)</option>
                      </select>
                      <small class="text-muted">Select your preferred language</small>
                    </div>
                    
                    <div class="mb-4">
                      <label class="form-label">Timezone</label>
                      <select class="form-select" name="timezone">
                        <option value="Asia/Dubai" <?= ($profile['timezone'] ?? 'Asia/Dubai') === 'Asia/Dubai' ? 'selected' : '' ?>>UAE (Dubai)</option>
                        <option value="Asia/Riyadh" <?= ($profile['timezone'] ?? 'Asia/Dubai') === 'Asia/Riyadh' ? 'selected' : '' ?>>Saudi Arabia (Riyadh)</option>
                        <option value="Asia/Kuwait" <?= ($profile['timezone'] ?? 'Asia/Dubai') === 'Asia/Kuwait' ? 'selected' : '' ?>>Kuwait</option>
                        <option value="Asia/Qatar" <?= ($profile['timezone'] ?? 'Asia/Dubai') === 'Asia/Qatar' ? 'selected' : '' ?>>Qatar</option>
                        <option value="UTC" <?= ($profile['timezone'] ?? 'Asia/Dubai') === 'UTC' ? 'selected' : '' ?>>UTC</option>
                      </select>
                      <small class="text-muted">Your local timezone for date and time display</small>
                    </div>
                    
                    <div class="mb-4">
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="email_notifications" id="emailNotif" <?= ($profile['email_notifications'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="emailNotif">
                          Email Notifications
                        </label>
                      </div>
                      <small class="text-muted">Receive email notifications for important events</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" id="savePreferencesBtn">
                      <i class="bi bi-save me-2"></i>Save Preferences
                    </button>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

      </div><!-- /tab-content -->
    </div><!-- /container -->
  </div><!-- /main -->
</div>

<!-- Toast Container for Notifications -->
<div class="toast-container"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Sidebar collapse toggle
  const sb = document.getElementById('sb');
  const t = document.getElementById('sbToggle');
  t.addEventListener('click', () => {
    sb.classList.toggle('collapsed');
    t.querySelector('i').classList.toggle('bi-chevron-right');
    t.querySelector('i').classList.toggle('bi-chevron-left');
  });

  // Toast notification function
  function showToast(message, type = 'success') {
    const toastContainer = document.querySelector('.toast-container');
    const toastId = 'toast-' + Date.now();
    const iconClass = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill';
    const bgClass = type === 'success' ? 'bg-success' : 'bg-danger';
    
    const toastHTML = `
      <div class="toast align-items-center text-white ${bgClass} border-0" role="alert" id="${toastId}">
        <div class="d-flex">
          <div class="toast-body">
            <i class="bi ${iconClass} me-2"></i>${message}
          </div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
      </div>
    `;
    
    toastContainer.insertAdjacentHTML('beforeend', toastHTML);
    const toastEl = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastEl, { delay: 3000 });
    toast.show();
    
    toastEl.addEventListener('hidden.bs.toast', () => {
      toastEl.remove();
    });
  }

  // Profile form submission
  const profileForm = document.getElementById('profileForm');
  if (profileForm) {
    profileForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      
      const btn = document.getElementById('saveProfileBtn');
      const originalText = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Saving...';
      
      const formData = new FormData(profileForm);
      
      try {
        const response = await fetch('api/profile_update.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
          showToast(result.message || 'Profile updated successfully', 'success');
          // Reload after 1 second to show updated data
          setTimeout(() => window.location.reload(), 1000);
        } else {
          showToast(result.error || 'Failed to update profile', 'error');
        }
      } catch (error) {
        console.error('Error:', error);
        showToast('An error occurred. Please try again.', 'error');
      } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
      }
    });
  }

  // Password form submission
  const passwordForm = document.getElementById('passwordForm');
  if (passwordForm) {
    const newPassword = document.getElementById('newPassword');
    const confirmPassword = document.getElementById('confirmPassword');
    const strengthBar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');
    const matchError = document.getElementById('passwordMatchError');
    
    // Password strength checker
    newPassword.addEventListener('input', () => {
      const password = newPassword.value;
      let strength = 0;
      
      if (password.length >= 8) strength++;
      if (/[a-z]/.test(password)) strength++;
      if (/[A-Z]/.test(password)) strength++;
      if (/[0-9]/.test(password)) strength++;
      if (/[^a-zA-Z0-9]/.test(password)) strength++;
      
      strengthBar.className = 'password-strength-bar';
      if (strength <= 2) {
        strengthBar.classList.add('weak');
        strengthText.textContent = 'Weak password';
        strengthText.className = 'text-danger';
      } else if (strength <= 3) {
        strengthBar.classList.add('medium');
        strengthText.textContent = 'Medium strength';
        strengthText.className = 'text-warning';
      } else {
        strengthBar.classList.add('strong');
        strengthText.textContent = 'Strong password';
        strengthText.className = 'text-success';
      }
    });
    
    // Password match checker
    confirmPassword.addEventListener('input', () => {
      if (confirmPassword.value && confirmPassword.value !== newPassword.value) {
        matchError.style.display = 'block';
      } else {
        matchError.style.display = 'none';
      }
    });
    
    passwordForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      
      if (newPassword.value !== confirmPassword.value) {
        showToast('Passwords do not match', 'error');
        return;
      }
      
      const btn = document.getElementById('changePasswordBtn');
      const originalText = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Changing...';
      
      const formData = new FormData(passwordForm);
      
      try {
        const response = await fetch('api/profile_password.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
          showToast(result.message || 'Password changed successfully', 'success');
          passwordForm.reset();
          strengthBar.className = 'password-strength-bar';
          strengthText.textContent = 'Enter a password';
          strengthText.className = 'text-muted';
        } else {
          showToast(result.error || 'Failed to change password', 'error');
        }
      } catch (error) {
        console.error('Error:', error);
        showToast('An error occurred. Please try again.', 'error');
      } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
      }
    });
  }

  // Preferences form submission
  const preferencesForm = document.getElementById('preferencesForm');
  if (preferencesForm) {
    preferencesForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      
      const btn = document.getElementById('savePreferencesBtn');
      const originalText = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Saving...';
      
      const formData = new FormData(preferencesForm);
      
      try {
        const response = await fetch('api/profile_preferences.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
          showToast(result.message || 'Preferences saved successfully', 'success');
        } else {
          showToast(result.error || 'Failed to save preferences', 'error');
        }
      } catch (error) {
        console.error('Error:', error);
        showToast('An error occurred. Please try again.', 'error');
      } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
      }
    });
  }

  // Avatar upload
  const avatarUpload = document.getElementById('avatarUpload');
  if (avatarUpload) {
    avatarUpload.addEventListener('change', async (e) => {
      const file = e.target.files[0];
      if (!file) return;
      
      // Validate file type
      if (!['image/jpeg', 'image/png', 'image/jpg'].includes(file.type)) {
        showToast('Please select a JPEG or PNG image', 'error');
        return;
      }
      
      // Validate file size (2MB)
      if (file.size > 2 * 1024 * 1024) {
        showToast('Image size must be less than 2MB', 'error');
        return;
      }
      
      const formData = new FormData();
      formData.append('avatar', file);
      
      try {
        const response = await fetch('api/profile_avatar.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
          showToast(result.message || 'Avatar uploaded successfully', 'success');
          // Reload after 1 second to show new avatar
          setTimeout(() => window.location.reload(), 1000);
        } else {
          showToast(result.error || 'Failed to upload avatar', 'error');
        }
      } catch (error) {
        console.error('Error:', error);
        showToast('An error occurred. Please try again.', 'error');
      }
    });
  }

  // Load activity history (for activity tab)
  <?php if ($activeTab === 'activity'): ?>
  async function loadActivity(page = 1) {
    try {
      const response = await fetch(`api/profile_activity.php?page=${page}`);
      const result = await response.json();
      
      if (result.success) {
        renderActivity(result.activities, result.total, result.page, result.per_page);
      } else {
        document.getElementById('activityContainer').innerHTML = 
          '<p class="text-center text-muted py-4">Failed to load activity history</p>';
      }
    } catch (error) {
      console.error('Error:', error);
      document.getElementById('activityContainer').innerHTML = 
        '<p class="text-center text-muted py-4">Error loading activity history</p>';
    }
  }
  
  function renderActivity(activities, total, page, perPage) {
    const container = document.getElementById('activityContainer');
    
    if (!activities || activities.length === 0) {
      container.innerHTML = '<p class="text-center text-muted py-4">No activity history available</p>';
      return;
    }
    
    let html = '<div style="max-height: 600px; overflow-y: auto;">';
    
    activities.forEach(activity => {
      const date = new Date(activity.created_at);
      const statusClass = activity.success ? 'success' : 'danger';
      const icon = activity.success ? 'check-circle' : 'x-circle';
      
      html += `
        <div class="activity-item ${statusClass}">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <p class="mb-1 fw-semibold">
                <i class="bi bi-${icon} me-2"></i>${escapeHtml(activity.summary)}
              </p>
              <p class="mb-0 small text-muted">
                <i class="bi bi-tag"></i> ${escapeHtml(activity.action)} • ${escapeHtml(activity.object_type)}
              </p>
              <p class="mb-0 small text-muted">
                <i class="bi bi-geo-alt"></i> ${escapeHtml(activity.ip_address || 'N/A')}
              </p>
            </div>
            <small class="text-muted">${date.toLocaleString()}</small>
          </div>
        </div>
      `;
    });
    
    html += '</div>';
    
    // Pagination
    const totalPages = Math.ceil(total / perPage);
    if (totalPages > 1) {
      html += '<nav class="mt-4"><ul class="pagination justify-content-center">';
      for (let i = 1; i <= totalPages; i++) {
        html += `<li class="page-item ${i === page ? 'active' : ''}">
          <a class="page-link" href="#" onclick="loadActivity(${i}); return false;">${i}</a>
        </li>`;
      }
      html += '</ul></nav>';
    }
    
    container.innerHTML = html;
  }
  
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
  
  // Load activity on page load
  loadActivity();
  <?php endif; ?>
</script>
</body>
</html>

