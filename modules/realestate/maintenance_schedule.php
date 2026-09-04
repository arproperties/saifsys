<?php
/**
 * Real Estate Module - Maintenance Schedule (Work Order planning board).
 *
 * Part of the existing Real Estate -> Maintenance section. Visual daily/weekly
 * board showing which engineers / technicians / helpers are working on which
 * building, unit and work order, with start/end time and live status.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/tenant_notifications.php';
require_once __DIR__ . '/includes/maintenance_schedule_helper.php';
require_once __DIR__ . '/includes/maintenance_location_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_MAINTENANCE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = re_maint_require_company($conn);
$userId = current_user_id();
$canOverride = re_ms_can_override($conn);

re_ms_ensure_schema($conn);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function re_ms_notify_tenant_request_status(PDO $conn, int $companyId, int $requestId, string $status): void {
    if ($requestId <= 0 || $companyId <= 0) return;
    try {
        $stmt = $conn->prepare('SELECT id, lease_id, tenant_id FROM re_maintenance_requests WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$requestId, $companyId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) return;
        $label = str_replace('_', ' ', $status);
        tenant_notification_create($conn, [
            'company_id' => $companyId,
            'lease_id' => (int)($request['lease_id'] ?? 0),
            'tenant_id' => (int)($request['tenant_id'] ?? 0),
            'type' => 'maintenance_status',
            'entity_type' => 'maintenance',
            'entity_id' => $requestId,
            'title' => 'Maintenance ' . $label,
            'body' => 'Your maintenance request is now ' . $label . '.',
            'dedup_window_minutes' => 10,
        ]);
    } catch (Throwable $e) {
        error_log('maintenance schedule tenant notification failed: ' . $e->getMessage());
    }
}

$flash = '';
$flashType = 'success';

/* --------------------------------------------------------------------------
 * POST actions
 * ------------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();
    $action = $_POST['action'];

    if ($action === 'save_schedule') {
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        $buildingId = !empty($_POST['building_id']) ? (int)$_POST['building_id'] : 0;
        $unitId = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : 0;
        $commonAreaId = !empty($_POST['common_area_id']) ? (int)$_POST['common_area_id'] : 0;
        $locationType = $_POST['location_type'] ?? 'unit';
        $requestId = !empty($_POST['maintenance_request_id']) ? (int)$_POST['maintenance_request_id'] : null;
        $taskType = $_POST['task_type'] ?? 'general';
        $priority = $_POST['priority'] ?? 'normal';
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $scheduleDate = $_POST['schedule_date'] ?? date('Y-m-d');
        $startTime = $_POST['start_time'] ?? '09:00';
        $endTime = $_POST['end_time'] ?? '10:00';
        $status = $_POST['status'] ?? 'scheduled';
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['employee_ids'] ?? [])))));
        $override = !empty($_POST['override_conflict']) && $canOverride;

        // Validation
        $errors = [];
        if (!array_key_exists($taskType, re_ms_task_types())) $taskType = 'general';
        if (!array_key_exists($priority, re_ms_priorities())) $priority = 'normal';
        if (!array_key_exists($status, re_ms_statuses())) $status = 'scheduled';
        $d = DateTime::createFromFormat('Y-m-d', $scheduleDate);
        if (!$d || $d->format('Y-m-d') !== $scheduleDate) $errors[] = 'Invalid schedule date.';
        $startNorm = strlen($startTime) === 5 ? $startTime . ':00' : $startTime;
        $endNorm = strlen($endTime) === 5 ? $endTime . ':00' : $endTime;
        if (strtotime($endNorm) <= strtotime($startNorm)) $errors[] = 'End time must be after start time.';
        if (empty($employeeIds)) $errors[] = 'Select at least one team member.';

        // Allow keeping legacy building-only when editing existing WO without reassignment
        $allowLegacyBuilding = false;
        if ($scheduleId > 0 && $locationType === 'building') {
            $ex = $conn->prepare("SELECT location_type FROM re_maintenance_schedules WHERE id = ? AND company_id = ? LIMIT 1");
            $ex->execute([$scheduleId, $currentCompanyId]);
            $allowLegacyBuilding = (($ex->fetchColumn() ?: '') === 'building');
        }
        $loc = re_maint_validate_location($conn, $currentCompanyId, $locationType, $buildingId, $unitId, $commonAreaId, $allowLegacyBuilding);
        if (!$loc['ok']) {
            $errors[] = $loc['error'] ?? 'Invalid location.';
        } else {
            $locationType = $loc['location_type'];
            $buildingId = $loc['building_id'];
            $unitId = $loc['unit_id'];
            $commonAreaId = $loc['common_area_id'];
        }

        if (empty($errors)) {
            // Conflict detection
            $conflicts = re_ms_detect_conflicts($conn, $currentCompanyId, $employeeIds, $scheduleDate, $startNorm, $endNorm, $scheduleId);
            if (!empty($conflicts) && !$override) {
                $_SESSION['ms_conflicts'] = $conflicts;
                $_SESSION['ms_form_repop'] = $_POST;
                $flash = 'Scheduling conflict detected. Review the warning below.';
                $flashType = 'danger';
            } else {
                try {
                    $conn->beginTransaction();
                    $tenantStatusToNotify = null;
                    if ($scheduleId > 0) {
                        $stmt = $conn->prepare("
                            UPDATE re_maintenance_schedules
                            SET maintenance_request_id = ?, building_id = ?, unit_id = ?, location_type = ?, common_area_id = ?,
                                task_type = ?, title = ?, description = ?, priority = ?, schedule_date = ?,
                                start_time = ?, end_time = ?, status = ?, notes = ?, updated_at = NOW()
                            WHERE id = ? AND company_id = ?
                        ");
                        $stmt->execute([$requestId, $buildingId, $unitId, $locationType, $commonAreaId, $taskType, $title, $description,
                            $priority, $scheduleDate, $startNorm, $endNorm, $status, $notes, $scheduleId, $currentCompanyId]);
                        $conn->prepare("DELETE FROM re_maintenance_schedule_assignees WHERE schedule_id = ?")->execute([$scheduleId]);
                    } else {
                        $stmt = $conn->prepare("
                            INSERT INTO re_maintenance_schedules
                                (company_id, maintenance_request_id, building_id, unit_id, location_type, common_area_id, task_type, title,
                                 description, priority, schedule_date, start_time, end_time, status, notes, created_by)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ");
                        $stmt->execute([$currentCompanyId, $requestId, $buildingId, $unitId, $locationType, $commonAreaId, $taskType, $title,
                            $description, $priority, $scheduleDate, $startNorm, $endNorm, $status, $notes, $userId]);
                        $scheduleId = (int)$conn->lastInsertId();
                        $won = re_ms_generate_wo_number($currentCompanyId, $scheduleId);
                        $conn->prepare("UPDATE re_maintenance_schedules SET work_order_number = ? WHERE id = ?")->execute([$won, $scheduleId]);
                    }

                    // Assignees (honor per-employee role override from the form)
                    $empMeta = re_ms_fetch_employees($conn, $currentCompanyId, false);
                    $postedRoles = (array)($_POST['employee_roles'] ?? []);
                    $ins = $conn->prepare("INSERT INTO re_maintenance_schedule_assignees (company_id, schedule_id, employee_id, team_role) VALUES (?,?,?,?)");
                    foreach ($employeeIds as $eid) {
                        $role = $postedRoles[$eid] ?? ($empMeta[$eid]['team_role'] ?? 'technician');
                        if (!array_key_exists($role, re_ms_team_roles())) $role = 'technician';
                        $ins->execute([$currentCompanyId, $scheduleId, $eid, $role]);
                    }

                    // Keep linked maintenance request status in sync (light touch)
                    if ($requestId) {
                        if ($status === 'completed') {
                            $reqSync = $conn->prepare("UPDATE re_maintenance_requests SET status='completed', completed_at=COALESCE(completed_at, NOW()), updated_at=NOW() WHERE id=? AND company_id=? AND status<>'completed'");
                            $reqSync->execute([$requestId, $currentCompanyId]);
                            if ($reqSync->rowCount() > 0) {
                                $tenantStatusToNotify = 'completed';
                            }
                        } elseif (in_array($status, ['in_progress', 'scheduled'], true)) {
                            $reqSync = $conn->prepare("UPDATE re_maintenance_requests SET status='in_progress', updated_at=NOW() WHERE id=? AND company_id=? AND status='pending'");
                            $reqSync->execute([$requestId, $currentCompanyId]);
                            if ($reqSync->rowCount() > 0) {
                                $tenantStatusToNotify = 'in_progress';
                            }
                        }
                    }

                    $conn->commit();
                    if ($requestId && $tenantStatusToNotify !== null) {
                        re_ms_notify_tenant_request_status($conn, $currentCompanyId, $requestId, $tenantStatusToNotify);
                    }
                    unset($_SESSION['ms_conflicts'], $_SESSION['ms_form_repop']);
                    $flash = 'Work order saved successfully.';
                    if (!empty($_POST['notify_team'])) {
                        $notified = re_ms_notify_assignees($conn, $currentCompanyId, $scheduleId, $employeeIds);
                        if ($notified > 0) $flash .= " {$notified} team email(s) sent.";
                    }
                } catch (Throwable $e) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    $flash = 'Error saving work order: ' . $e->getMessage();
                    $flashType = 'danger';
                }
            }
        } else {
            $flash = implode(' ', $errors);
            $flashType = 'danger';
            $_SESSION['ms_form_repop'] = $_POST;
        }
    } elseif ($action === 'update_status') {
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($scheduleId > 0 && array_key_exists($status, re_ms_statuses())) {
            try {
                $stmt = $conn->prepare("UPDATE re_maintenance_schedules SET status=?, updated_at=NOW() WHERE id=? AND company_id=?");
                $stmt->execute([$status, $scheduleId, $currentCompanyId]);
                // Sync linked request when completed
                $row = $conn->prepare("SELECT maintenance_request_id FROM re_maintenance_schedules WHERE id=? AND company_id=?");
                $row->execute([$scheduleId, $currentCompanyId]);
                $reqId = (int)($row->fetchColumn() ?: 0);
                if ($reqId && $status === 'completed') {
                    $reqSync = $conn->prepare("UPDATE re_maintenance_requests SET status='completed', completed_at=COALESCE(completed_at, NOW()), updated_at=NOW() WHERE id=? AND company_id=? AND status<>'completed'");
                    $reqSync->execute([$reqId, $currentCompanyId]);
                    if ($reqSync->rowCount() > 0) {
                        re_ms_notify_tenant_request_status($conn, $currentCompanyId, $reqId, 'completed');
                    }
                }
                $flash = 'Status updated to ' . re_ms_statuses()[$status] . '.';
            } catch (Throwable $e) {
                $flash = 'Error updating status: ' . $e->getMessage();
                $flashType = 'danger';
            }
        }
    } elseif ($action === 'delete_schedule') {
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        if ($scheduleId > 0 && $canOverride) {
            try {
                $conn->prepare("DELETE FROM re_maintenance_schedule_assignees WHERE schedule_id=?")->execute([$scheduleId]);
                $conn->prepare("DELETE FROM re_maintenance_schedules WHERE id=? AND company_id=?")->execute([$scheduleId, $currentCompanyId]);
                $flash = 'Work order deleted.';
            } catch (Throwable $e) {
                $flash = 'Error deleting work order: ' . $e->getMessage();
                $flashType = 'danger';
            }
        } else {
            $flash = 'You are not allowed to delete work orders.';
            $flashType = 'danger';
        }
    }

    // Redirect (PRG) preserving view context, unless we must keep conflict state
    if (empty($_SESSION['ms_conflicts'])) {
        $qs = $_POST['return_qs'] ?? '';
        $_SESSION['ms_flash'] = $flash;
        $_SESSION['ms_flash_type'] = $flashType;
        header('Location: maintenance_schedule.php' . ($qs ? ('?' . $qs) : ''));
        exit;
    }
}

if (!empty($_SESSION['ms_flash'])) {
    $flash = $_SESSION['ms_flash'];
    $flashType = $_SESSION['ms_flash_type'] ?? 'success';
    unset($_SESSION['ms_flash'], $_SESSION['ms_flash_type']);
}
$conflicts = $_SESSION['ms_conflicts'] ?? [];
$formRepop = $_SESSION['ms_form_repop'] ?? [];
unset($_SESSION['ms_conflicts'], $_SESSION['ms_form_repop']);

/* --------------------------------------------------------------------------
 * GET / view state
 * ------------------------------------------------------------------------ */
$view = (($_GET['view'] ?? 'day') === 'week') ? 'week' : 'day';
$date = $_GET['date'] ?? date('Y-m-d');
$dchk = DateTime::createFromFormat('Y-m-d', $date);
if (!$dchk || $dchk->format('Y-m-d') !== $date) $date = date('Y-m-d');

$dayStartHour = max(0, min(23, (int)($_GET['day_start'] ?? 7)));
$dayEndHour = max($dayStartHour + 1, min(24, (int)($_GET['day_end'] ?? 20)));

$filters = [
    'building_id' => (int)($_GET['building_id'] ?? 0),
    'employee_id' => (int)($_GET['employee_id'] ?? 0),
    'status' => (in_array(($_GET['status'] ?? ''), array_keys(re_ms_statuses()), true)) ? $_GET['status'] : '',
    'task_type' => (in_array(($_GET['task_type'] ?? ''), array_keys(re_ms_task_types()), true)) ? $_GET['task_type'] : '',
];

if ($view === 'week') {
    $monday = (new DateTime($date))->modify('monday this week');
    $dateFrom = $monday->format('Y-m-d');
    $dateTo = (clone $monday)->modify('+6 days')->format('Y-m-d');
    $weekDays = [];
    for ($i = 0; $i < 7; $i++) {
        $weekDays[] = (clone $monday)->modify("+$i days");
    }
} else {
    $dateFrom = $dateTo = $date;
}

$schedules = re_ms_fetch_schedules($conn, $currentCompanyId, $dateFrom, $dateTo, $filters);

// Employees on the board: maintenance staff + anyone already assigned
$boardEmployees = re_ms_fetch_employees($conn, $currentCompanyId, true);
foreach ($schedules as $s) {
    foreach ($s['assignees'] as $a) {
        $eid = (int)$a['employee_id'];
        if (!isset($boardEmployees[$eid])) {
            $boardEmployees[$eid] = [
                'id' => $eid,
                'display_name' => $a['employee_name'],
                'position_title' => $a['position_title'] ?? '',
                'team_role' => $a['team_role'] ?? 'other',
            ];
        }
    }
}
// Fallback: if no employees were classified as maintenance staff (position
// titles differ across installs), show all active employees so the board is
// never empty and staff can still be scheduled.
if (empty($boardEmployees)) {
    $boardEmployees = re_ms_fetch_employees($conn, $currentCompanyId, false);
}
// If an employee filter is set, restrict the board to that person
if ($filters['employee_id'] && isset($boardEmployees[$filters['employee_id']])) {
    $boardEmployees = [$filters['employee_id'] => $boardEmployees[$filters['employee_id']]];
}
// Sort board employees by role then name
uasort($boardEmployees, function ($a, $b) {
    $ra = re_ms_team_role_order($a['team_role'] ?? 'other');
    $rb = re_ms_team_role_order($b['team_role'] ?? 'other');
    if ($ra !== $rb) return $ra <=> $rb;
    return strcasecmp($a['display_name'], $b['display_name']);
});

// Index schedules by employee id
$byEmployee = [];
foreach ($schedules as $s) {
    foreach ($s['assignees'] as $a) {
        $byEmployee[(int)$a['employee_id']][] = $s;
    }
}

// Per-employee scheduled hours in the visible range (excludes cancelled)
$empHours = [];
foreach ($byEmployee as $eid => $items) {
    $h = 0.0;
    foreach ($items as $it) {
        if ($it['status'] === 'cancelled') continue;
        $h += re_ms_duration_hours(substr($it['start_time'], 0, 5), substr($it['end_time'], 0, 5));
    }
    $empHours[(int)$eid] = $h;
}

/* Dashboard stats (over the visible range) */
$statTotal = count($schedules);
$statCompleted = 0;
$statPending = 0;      // scheduled
$statEmergency = 0;
$engSet = [];
$techSet = [];
foreach ($schedules as $s) {
    if ($s['status'] === 'completed') $statCompleted++;
    if ($s['status'] === 'scheduled') $statPending++;
    if ($s['priority'] === 'emergency' || $s['task_type'] === 'emergency') $statEmergency++;
    foreach ($s['assignees'] as $a) {
        if (($a['team_role'] ?? '') === 'engineer') $engSet[(int)$a['employee_id']] = true;
        if (($a['team_role'] ?? '') === 'technician') $techSet[(int)$a['employee_id']] = true;
    }
}
$statEngineers = count($engSet);
$statTechnicians = count($techSet);

/* Lookups for filters and modal */
$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

$allEmployees = re_ms_fetch_employees($conn, $currentCompanyId, false);
// Group employees for the modal team picker
$empGroups = ['engineer' => [], 'supervisor' => [], 'technician' => [], 'helper' => [], 'other' => []];
foreach ($allEmployees as $e) {
    $empGroups[$e['team_role']][] = $e;
}

// Maintenance requests for the "schedule from request" picker (open ones)
$openRequests = $conn->prepare("
    SELECT mr.id, mr.description, mr.category, mr.priority, mr.unit_id, mr.common_area_id, mr.location_type,
           u.unit_number, ca.area_name AS common_area_name,
           COALESCE(mr.building_id, u.building_id) AS building_id,
           COALESCE(b.name, bu.name) AS building_name
    FROM re_maintenance_requests mr
    LEFT JOIN re_units u ON u.id = mr.unit_id
    LEFT JOIN re_buildings bu ON bu.id = u.building_id
    LEFT JOIN re_buildings b ON b.id = mr.building_id
    LEFT JOIN re_building_common_areas ca ON ca.id = mr.common_area_id
    WHERE mr.company_id = ? AND mr.status IN ('pending','in_progress')
    ORDER BY mr.request_date DESC
    LIMIT 300
");
$openRequests->execute([$currentCompanyId]);
$openRequests = $openRequests->fetchAll(PDO::FETCH_ASSOC);
foreach ($openRequests as &$or) {
    $or['location_label'] = re_maint_location_label(
        $or['building_name'] ?? null,
        (string)($or['location_type'] ?? 'unit'),
        $or['unit_number'] ?? null,
        $or['common_area_name'] ?? null
    );
}
unset($or);

// Prefill from a maintenance request (Schedule Work action)
$prefill = null;
$prefillReqId = (int)($_GET['request_id'] ?? 0);
if ($prefillReqId > 0) {
    $pr = $conn->prepare("
        SELECT mr.id, mr.description, mr.category, mr.priority, mr.unit_id, mr.common_area_id, mr.location_type,
               u.unit_number, ca.area_name AS common_area_name,
               COALESCE(mr.building_id, u.building_id) AS building_id,
               COALESCE(b.name, bu.name) AS building_name
        FROM re_maintenance_requests mr
        LEFT JOIN re_units u ON u.id = mr.unit_id
        LEFT JOIN re_buildings bu ON bu.id = u.building_id
        LEFT JOIN re_buildings b ON b.id = mr.building_id
        LEFT JOIN re_building_common_areas ca ON ca.id = mr.common_area_id
        WHERE mr.id = ? AND mr.company_id = ?
    ");
    $pr->execute([$prefillReqId, $currentCompanyId]);
    $prefill = $pr->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Schedules as a JS map for edit modal
$jsSchedules = [];
foreach ($schedules as $s) {
    $jsSchedules[(int)$s['id']] = [
        'id' => (int)$s['id'],
        'work_order_number' => $s['work_order_number'],
        'maintenance_request_id' => $s['maintenance_request_id'] ? (int)$s['maintenance_request_id'] : '',
        'building_id' => $s['building_id'] ? (int)$s['building_id'] : '',
        'unit_id' => $s['unit_id'] ? (int)$s['unit_id'] : '',
        'common_area_id' => !empty($s['common_area_id']) ? (int)$s['common_area_id'] : '',
        'location_type' => $s['location_type'] ?? 'unit',
        'task_type' => $s['task_type'],
        'priority' => $s['priority'],
        'title' => $s['title'],
        'description' => $s['description'],
        'notes' => $s['notes'],
        'schedule_date' => $s['schedule_date'],
        'start_time' => substr($s['start_time'], 0, 5),
        'end_time' => substr($s['end_time'], 0, 5),
        'status' => $s['status'],
        'building_name' => $s['building_name'],
        'unit_number' => $s['unit_number'],
        'common_area_name' => $s['common_area_name'] ?? '',
        'location_label' => $s['location_label'] ?? '',
        'employee_ids' => array_map(fn($a) => (int)$a['employee_id'], $s['assignees']),
        'assignee_names' => array_map(fn($a) => $a['employee_name'], $s['assignees']),
    ];
}

$returnQs = http_build_query(array_filter([
    'view' => $view,
    'date' => $date,
    'building_id' => $filters['building_id'] ?: null,
    'employee_id' => $filters['employee_id'] ?: null,
    'status' => $filters['status'] ?: null,
    'task_type' => $filters['task_type'] ?: null,
    'day_start' => $dayStartHour,
    'day_end' => $dayEndHour,
]));

$statusColors = re_ms_status_colors();
$taskTypes = re_ms_task_types();
$priorities = re_ms_priorities();
$statuses = re_ms_statuses();

$winStartMin = $dayStartHour * 60;
$winEndMin = $dayEndHour * 60;
$winLen = max(1, $winEndMin - $winStartMin);

/** Render a small utilization bar for an employee row. */
function ms_capacity_html(array $emp, float $hours, string $view): string {
    $fmt = function ($n) { return rtrim(rtrim(number_format((float)$n, 2), '0'), '.'); };
    $cap = (float)($view === 'week' ? ($emp['weekly_cap_hours'] ?? 0) : ($emp['daily_cap_hours'] ?? 0));
    if ($cap > 0) {
        $pct = min(100, ($hours / $cap) * 100);
        $col = $pct >= 100 ? '#dc3545' : ($pct >= 80 ? '#fd7e14' : '#198754');
        return '<div class="ms-cap"><div class="ms-cap-bar"><span style="width:' . $pct . '%;background:' . $col . '"></span></div>'
            . '<div class="ms-cap-lbl">' . $fmt($hours) . 'h / ' . $fmt($cap) . 'h</div></div>';
    }
    return '<div class="ms-cap-lbl">' . $fmt($hours) . 'h scheduled</div>';
}

/** Assign non-overlapping lanes to a set of schedules (greedy). */
function re_ms_assign_lanes(array $items): array {
    usort($items, fn($a, $b) => strtotime($a['start_time']) <=> strtotime($b['start_time']));
    $laneEnds = [];
    $out = [];
    foreach ($items as $it) {
        $s = strtotime($it['start_time']);
        $e = strtotime($it['end_time']);
        $placed = null;
        foreach ($laneEnds as $lane => $end) {
            if ($s >= $end) { $placed = $lane; break; }
        }
        if ($placed === null) { $placed = count($laneEnds); }
        $laneEnds[$placed] = $e;
        $it['_lane'] = $placed;
        $out[] = $it;
    }
    $maxLane = empty($laneEnds) ? 0 : count($laneEnds);
    return [$out, max(1, $maxLane)];
}

$pageTitle = 'Maintenance Schedule';
$pageStyles = '
  .ms-toolbar .btn { white-space: nowrap; }
  .ms-stat { border-radius:14px; color:#fff; padding:14px 16px; box-shadow:var(--shadow-sm); }
  .ms-stat .n { font-size:1.6rem; font-weight:800; line-height:1; }
  .ms-stat .l { font-size:.8rem; opacity:.95; }
  .ms-board { background:#fff; border-radius:16px; box-shadow:var(--shadow); overflow:hidden; }
  .ms-scroll { overflow-x:auto; }
  .ms-grid { min-width:900px; }
  .ms-row { display:flex; border-bottom:1px solid #eef2f6; }
  .ms-row:last-child { border-bottom:0; }
  .ms-rowlabel { width:200px; min-width:200px; max-width:200px; padding:8px 12px; border-right:1px solid #eef2f6; background:#fafbfd; }
  .ms-rowlabel .nm { font-weight:600; font-size:.9rem; }
  .ms-rowlabel .ro { font-size:.72rem; color:#6c757d; text-transform:capitalize; }
  .ms-track { position:relative; flex:1; }
  .ms-timehead { display:flex; }
  .ms-timehead .ms-rowlabel { background:#fff; font-weight:700; }
  .ms-hourcell { flex:1; text-align:center; font-size:.72rem; color:#6c757d; padding:6px 0; border-left:1px solid #f1f3f7; }
  .ms-slot { position:absolute; top:0; bottom:0; border-left:1px solid #f4f6f9; }
  .ms-block { position:absolute; border-radius:8px; color:#fff; padding:4px 8px; font-size:11px; line-height:1.25; overflow:hidden; cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,.25); display:flex; flex-direction:column; justify-content:center; }
  .ms-block .wo { font-weight:700; display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .ms-block .meta { font-size:10px; opacity:.95; display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .ms-block.cancelled { opacity:.55; }
  .ms-block.ms-dragging { opacity:.6; outline:2px dashed #fff; }
  .ms-block .ms-resize { position:absolute; top:0; bottom:0; width:7px; cursor:ew-resize; }
  .ms-block .ms-resize.l { left:0; }
  .ms-block .ms-resize.r { right:0; }
  .ms-track.ms-drop-target { background:#eaf3ff; }
  .ms-ghost { position:absolute; top:5px; height:40px; background:#0d6efd33; border:2px dashed #0d6efd; border-radius:8px; pointer-events:none; z-index:5; }
  .ms-empty-cell { position:absolute; inset:0; cursor:cell; }
  .ms-cap { margin-top:4px; }
  .ms-cap-bar { height:5px; background:#e9ecef; border-radius:3px; overflow:hidden; }
  .ms-cap-bar span { display:block; height:100%; border-radius:3px; }
  .ms-cap-lbl { font-size:.66rem; color:#6c757d; margin-top:2px; }
  .ms-legend .sw { display:inline-block; width:13px; height:13px; border-radius:3px; margin-right:5px; vertical-align:middle; }
  /* Weekly */
  .ms-week .ms-daycell { flex:1; border-left:1px solid #f1f3f7; padding:6px; min-height:64px; }
  .ms-week .ms-daycell.is-today { background:#eaf3ff; }
  .ms-weekhead .ms-daycell { font-weight:700; font-size:.78rem; text-align:center; color:#495057; }
  .ms-mini { border-radius:6px; color:#fff; padding:3px 6px; font-size:10.5px; margin-bottom:4px; cursor:pointer; }
  .ms-mini .wo { font-weight:700; }
  @media (max-width: 768px) {
    .ms-rowlabel { width:120px; min-width:120px; max-width:120px; }
    .ms-grid { min-width:680px; }
  }
  /* Add/Edit modal: guarantee the footer (Save button) is always reachable */
  #msModal .modal-dialog { max-height: 92vh; margin-top: 2vh; margin-bottom: 2vh; }
  #msModal .modal-content { max-height: 92vh !important; display: flex; flex-direction: column; overflow: hidden; }
  #msModal .modal-header, #msModal .modal-footer { flex: 0 0 auto; }
  #msModal .modal-body { flex: 1 1 auto; max-height: calc(92vh - 130px) !important; overflow-y: auto !important; }
  #ms_team_box { max-height: 38vh; min-height: 160px; overflow-y: auto; }
';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 no-print">
    <div class="page-header-label"><i class="bi bi-calendar3-week"></i> Maintenance Schedule</div>
    <div class="d-flex gap-2 ms-toolbar flex-wrap">
        <button type="button" class="btn btn-primary" onclick="msOpenAdd()"><i class="bi bi-plus-circle"></i> Add Work Order</button>
        <div class="btn-group">
            <a class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" href="#"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" target="_blank" href="maintenance_schedule_pdf.php?type=daily&date=<?= h($date) ?>&<?= h($returnQs) ?>">Daily schedule</a></li>
                <li><a class="dropdown-item" target="_blank" href="maintenance_schedule_pdf.php?type=weekly&date=<?= h($date) ?>&<?= h($returnQs) ?>">Weekly schedule</a></li>
                <li><a class="dropdown-item" target="_blank" href="maintenance_schedule_pdf.php?type=building&date=<?= h($date) ?>&<?= h($returnQs) ?>">Building schedule</a></li>
                <li><a class="dropdown-item" target="_blank" href="maintenance_schedule_pdf.php?type=employee&date=<?= h($date) ?>&<?= h($returnQs) ?>">Employee schedule</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="maintenance_schedule_ical.php?<?= h($returnQs) ?>"><i class="bi bi-calendar-event"></i> iCal (.ics) export</a></li>
            </ul>
        </div>
    </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= h($flashType) ?> alert-dismissible fade show no-print">
    <i class="bi bi-info-circle"></i> <?= h($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!empty($conflicts)): ?>
<div class="alert alert-warning no-print">
    <h6 class="mb-2"><i class="bi bi-exclamation-triangle-fill"></i> Scheduling conflict</h6>
    <ul class="mb-2">
        <?php foreach ($conflicts as $c): ?>
        <li><strong><?= h($c['employee_name']) ?></strong> is already assigned from <?= h(substr($c['start_time'],0,5)) ?> to <?= h(substr($c['end_time'],0,5)) ?> (<?= h($c['work_order_number'] ?: 'WO') ?>).</li>
        <?php endforeach; ?>
    </ul>
    <button type="button" class="btn btn-sm btn-warning" onclick="msReopenWithConflict()">Review / adjust</button>
    <?php if ($canOverride): ?>
        <button type="button" class="btn btn-sm btn-danger" onclick="msReopenWithConflict(true)">Override &amp; save anyway</button>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Dashboard summary -->
<div class="row g-2 mb-3 no-print">
    <div class="col-6 col-md-2"><div class="ms-stat" style="background:#0d6efd"><div class="n"><?= $statTotal ?></div><div class="l"><?= $view === 'week' ? "Week's" : "Today's" ?> Work Orders</div></div></div>
    <div class="col-6 col-md-2"><div class="ms-stat" style="background:#6610f2"><div class="n"><?= $statEngineers ?></div><div class="l">Engineers Assigned</div></div></div>
    <div class="col-6 col-md-2"><div class="ms-stat" style="background:#0dcaf0"><div class="n"><?= $statTechnicians ?></div><div class="l">Technicians Assigned</div></div></div>
    <div class="col-6 col-md-2"><div class="ms-stat" style="background:#198754"><div class="n"><?= $statCompleted ?></div><div class="l">Completed Jobs</div></div></div>
    <div class="col-6 col-md-2"><div class="ms-stat" style="background:#fd7e14"><div class="n"><?= $statPending ?></div><div class="l">Pending Jobs</div></div></div>
    <div class="col-6 col-md-2"><div class="ms-stat" style="background:#dc3545"><div class="n"><?= $statEmergency ?></div><div class="l">Emergency Jobs</div></div></div>
</div>

<!-- Filters / view switch -->
<div class="card card-round mb-3 no-print">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">View</label>
                <select name="view" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="day" <?= $view === 'day' ? 'selected' : '' ?>>Daily</option>
                    <option value="week" <?= $view === 'week' ? 'selected' : '' ?>>Weekly</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Date</label>
                <input type="date" name="date" value="<?= h($date) ?>" class="form-control form-control-sm" onchange="this.form.submit()">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Building</label>
                <select name="building_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All buildings</option>
                    <?php foreach ($buildings as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $filters['building_id'] == $b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Employee</label>
                <select name="employee_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All employees</option>
                    <?php foreach ($allEmployees as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= $filters['employee_id'] == $e['id'] ? 'selected' : '' ?>><?= h($e['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    <?php foreach ($statuses as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Task type</label>
                <select name="task_type" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All types</option>
                    <?php foreach ($taskTypes as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $filters['task_type'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4 mt-2">
                <div class="ms-legend small text-muted">
                    <?php foreach ($statusColors as $k => $col): ?>
                    <span class="me-2"><span class="sw" style="background:<?= $col ?>"></span><?= h($statuses[$k]) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-12 col-md-8 mt-2 d-flex gap-2 justify-content-md-end">
                <?php
                $prevDate = (new DateTime($date))->modify($view === 'week' ? '-7 days' : '-1 day')->format('Y-m-d');
                $nextDate = (new DateTime($date))->modify($view === 'week' ? '+7 days' : '+1 day')->format('Y-m-d');
                ?>
                <a class="btn btn-sm btn-outline-secondary" href="?view=<?= $view ?>&date=<?= $prevDate ?>"><i class="bi bi-chevron-left"></i> Prev</a>
                <a class="btn btn-sm btn-outline-secondary" href="?view=<?= $view ?>&date=<?= date('Y-m-d') ?>">Today</a>
                <a class="btn btn-sm btn-outline-secondary" href="?view=<?= $view ?>&date=<?= $nextDate ?>">Next <i class="bi bi-chevron-right"></i></a>
            </div>
        </form>
    </div>
</div>

<?php if ($view === 'day'): ?>
<!-- ============================ DAILY BOARD ============================ -->
<div class="ms-board">
    <div class="ms-scroll">
        <div class="ms-grid">
            <div class="ms-row ms-timehead">
                <div class="ms-rowlabel"><?= date('l, M j, Y', strtotime($date)) ?></div>
                <div class="ms-track d-flex">
                    <?php for ($hh = $dayStartHour; $hh < $dayEndHour; $hh++): ?>
                    <div class="ms-hourcell"><?= sprintf('%02d:00', $hh) ?></div>
                    <?php endfor; ?>
                </div>
            </div>
            <?php if (empty($boardEmployees)): ?>
                <div class="p-4 text-center text-muted">No maintenance employees found. Use “Add Work Order” to schedule and assign staff.</div>
            <?php endif; ?>
            <?php foreach ($boardEmployees as $eid => $emp): ?>
                <?php
                $items = $byEmployee[$eid] ?? [];
                [$laidOut, $lanes] = re_ms_assign_lanes($items);
                $lanePitch = 46;
                $rowHeight = max(58, $lanes * $lanePitch + 10);
                ?>
                <div class="ms-row" style="min-height:<?= $rowHeight ?>px">
                    <div class="ms-rowlabel">
                        <div class="nm"><?= h($emp['display_name']) ?></div>
                        <div class="ro"><?= h($emp['team_role'] ?? 'other') ?> · <?= count($items) ?> job(s)<?php if (!empty($emp['is_other_company']) && !empty($emp['company_name'])): ?> · <span title="<?= h($emp['company_name']) ?>"><i class="bi bi-building"></i></span><?php endif; ?></div>
                        <?= ms_capacity_html($emp, $empHours[$eid] ?? 0, 'day') ?>
                    </div>
                    <div class="ms-track" data-employee="<?= $eid ?>" style="height:<?= $rowHeight ?>px">
                        <?php for ($hh = $dayStartHour; $hh < $dayEndHour; $hh++):
                            $left = (($hh * 60 - $winStartMin) / $winLen) * 100; ?>
                        <div class="ms-slot" style="left:<?= $left ?>%"></div>
                        <?php endfor; ?>
                        <?php foreach ($laidOut as $it):
                            $sMin = (int)substr($it['start_time'],0,2)*60 + (int)substr($it['start_time'],3,2);
                            $eMin = (int)substr($it['end_time'],0,2)*60 + (int)substr($it['end_time'],3,2);
                            $sMin = max($winStartMin, min($winEndMin, $sMin));
                            $eMin = max($winStartMin, min($winEndMin, $eMin));
                            if ($eMin <= $sMin) { $eMin = $sMin + 15; }
                            $left = (($sMin - $winStartMin) / $winLen) * 100;
                            $width = (($eMin - $sMin) / $winLen) * 100;
                            $top = 5 + $it['_lane'] * $lanePitch;
                            $col = $statusColors[$it['status']] ?? '#6c757d';
                            $loc = $it['location_label'] ?? trim(($it['building_name'] ?? '') . ($it['unit_number'] ? ' · ' . $it['unit_number'] : ''));
                        ?>
                        <div class="ms-block ms-draggable <?= $it['status'] === 'cancelled' ? 'cancelled' : '' ?>"
                             data-schedule="<?= (int)$it['id'] ?>" data-emp="<?= $eid ?>"
                             data-start="<?= h(substr($it['start_time'],0,5)) ?>" data-end="<?= h(substr($it['end_time'],0,5)) ?>"
                             style="left:<?= $left ?>%; width:calc(<?= $width ?>% - 4px); min-width:64px; top:<?= $top ?>px; height:40px; background:<?= $col ?>"
                             title="<?= h(($it['work_order_number'] ?: 'WO') . ' — ' . $taskTypes[$it['task_type']] . ' — ' . $loc) ?>">
                            <div class="ms-resize l"></div>
                            <span class="wo"><?= h($taskTypes[$it['task_type']]) ?><?= $it['priority']==='emergency' ? ' ⚠' : '' ?></span>
                            <span class="meta"><?= h($loc ?: ($it['work_order_number'] ?: 'WO')) ?> · <?= h(substr($it['start_time'],0,5)) ?>-<?= h(substr($it['end_time'],0,5)) ?></span>
                            <div class="ms-resize r"></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ============================ WEEKLY BOARD ============================ -->
<div class="ms-board ms-week">
    <div class="ms-scroll">
        <div class="ms-grid">
            <div class="ms-row ms-weekhead">
                <div class="ms-rowlabel">Employee</div>
                <?php foreach ($weekDays as $wd): ?>
                <div class="ms-daycell <?= $wd->format('Y-m-d') === date('Y-m-d') ? 'is-today' : '' ?>"><?= $wd->format('D') ?><br><?= $wd->format('M j') ?></div>
                <?php endforeach; ?>
            </div>
            <?php if (empty($boardEmployees)): ?>
                <div class="p-4 text-center text-muted">No maintenance employees found.</div>
            <?php endif; ?>
            <?php foreach ($boardEmployees as $eid => $emp): ?>
                <div class="ms-row">
                    <div class="ms-rowlabel">
                        <div class="nm"><?= h($emp['display_name']) ?></div>
                        <div class="ro"><?= h($emp['team_role'] ?? 'other') ?></div>
                        <?= ms_capacity_html($emp, $empHours[$eid] ?? 0, 'week') ?>
                    </div>
                    <?php foreach ($weekDays as $wd):
                        $dayStr = $wd->format('Y-m-d'); ?>
                    <div class="ms-daycell <?= $dayStr === date('Y-m-d') ? 'is-today' : '' ?>">
                        <?php foreach (($byEmployee[$eid] ?? []) as $it):
                            if ($it['schedule_date'] !== $dayStr) continue;
                            $col = $statusColors[$it['status']] ?? '#6c757d';
                            $loc = $it['location_label'] ?? trim(($it['building_name'] ?? '') . ($it['unit_number'] ? ' ' . $it['unit_number'] : '')); ?>
                        <div class="ms-mini" style="background:<?= $col ?>" onclick="msOpenView(<?= (int)$it['id'] ?>)"
                             title="<?= h($taskTypes[$it['task_type']] . ' — ' . $loc) ?>">
                            <span class="wo"><?= h(substr($it['start_time'],0,5)) ?></span> <?= h($taskTypes[$it['task_type']]) ?><br>
                            <small><?= h($loc) ?></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// ---- Add/Edit modal ----
require __DIR__ . '/includes/maintenance_schedule_modals.php';
?>

<script>
const MS_SCHEDULES = <?= json_encode($jsSchedules, JSON_UNESCAPED_UNICODE) ?>;
const MS_TASK_TYPES = <?= json_encode($taskTypes) ?>;
const MS_STATUSES = <?= json_encode($statuses) ?>;
const MS_RETURN_QS = <?= json_encode($returnQs) ?>;
const MS_DEFAULT_DATE = <?= json_encode($date) ?>;
const MS_CAN_OVERRIDE = <?= $canOverride ? 'true' : 'false' ?>;
const MS_VIEW = <?= json_encode($view) ?>;
const MS_WIN_START = <?= (int)$dayStartHour ?>;
const MS_WIN_END = <?= (int)$dayEndHour ?>;
const MS_CSRF = <?= json_encode(csrf_token()) ?>;
const MS_UNITS_BY_BUILDING = {};

function msEl(id){ return document.getElementById(id); }

// Move modals to be direct children of <body> so no ancestor (transform/overflow/
// stacking context) can break the fixed-position sizing or body scrolling.
['msModal','msViewModal'].forEach(function(id){
    var el = document.getElementById(id);
    if (el && el.parentNode !== document.body) { document.body.appendChild(el); }
});

function msResetForm(){
    msEl('ms_schedule_id').value = '';
    msEl('ms_form_title').textContent = 'Add Work Order';
    msEl('ms_wo_badge').style.display = 'none';
    msEl('msForm').reset();
    msEl('ms_schedule_date').value = MS_DEFAULT_DATE;
    msEl('ms_override_conflict').value = '';
    document.querySelectorAll('#msForm input[name="employee_ids[]"]').forEach(c => c.checked = false);
    if (msEl('ms_location_type')) msEl('ms_location_type').value = 'unit';
    msSyncLocationUi();
}

function msOpenAdd(){
    msResetForm();
    new bootstrap.Modal(msEl('msModal')).show();
}

function msTrackClick(ev, track){
    // Click an empty time slot -> open add prefilled with that employee + approx start hour
    msResetForm();
    const empId = track.getAttribute('data-employee');
    const rect = track.getBoundingClientRect();
    const pct = Math.max(0, Math.min(1, (ev.clientX - rect.left) / rect.width));
    const winStart = <?= $dayStartHour ?>, winEnd = <?= $dayEndHour ?>;
    let hour = Math.floor(winStart + pct * (winEnd - winStart));
    hour = Math.max(winStart, Math.min(winEnd - 1, hour));
    msEl('ms_start_time').value = String(hour).padStart(2,'0') + ':00';
    msEl('ms_end_time').value = String(Math.min(hour+1, winEnd)).padStart(2,'0') + ':00';
    const cb = document.querySelector('#msForm input[name="employee_ids[]"][value="' + empId + '"]');
    if (cb) cb.checked = true;
    new bootstrap.Modal(msEl('msModal')).show();
}

function msOpenView(id){
    const s = MS_SCHEDULES[id];
    if (!s) return;
    // Fill view modal
    msEl('msv_title').textContent = (s.work_order_number || 'Work Order');
    msEl('msv_type').textContent = MS_TASK_TYPES[s.task_type] || s.task_type;
    msEl('msv_priority').textContent = s.priority;
    msEl('msv_status').textContent = MS_STATUSES[s.status] || s.status;
    msEl('msv_location').textContent = s.location_label || s.building_name || '-';
    msEl('msv_request').textContent = s.maintenance_request_id ? ('#' + s.maintenance_request_id) : '-';
    msEl('msv_date').textContent = s.schedule_date;
    msEl('msv_time').textContent = s.start_time + ' - ' + s.end_time;
    msEl('msv_team').textContent = (s.assignee_names || []).join(', ') || '-';
    msEl('msv_desc').textContent = s.description || '-';
    msEl('msv_notes').textContent = s.notes || '-';
    msEl('msv_status_select').value = s.status;
    msEl('msv_status_sched_id').value = s.id;
    msEl('msv_delete_sched_id').value = s.id;
    if (s.maintenance_request_id) {
        msEl('msv_req_link').style.display = '';
        msEl('msv_req_link').href = 'maintenance_view.php?id=' + s.maintenance_request_id;
    } else {
        msEl('msv_req_link').style.display = 'none';
    }
    msEl('msv_edit_btn').setAttribute('data-id', s.id);
    new bootstrap.Modal(msEl('msViewModal')).show();
}

function msEditFromView(){
    const id = msEl('msv_edit_btn').getAttribute('data-id');
    const s = MS_SCHEDULES[id];
    if (!s) return;
    bootstrap.Modal.getInstance(msEl('msViewModal'))?.hide();
    msResetForm();
    msEl('ms_form_title').textContent = 'Edit Work Order';
    msEl('ms_schedule_id').value = s.id;
    if (s.work_order_number){ msEl('ms_wo_badge').style.display = ''; msEl('ms_wo_badge').textContent = s.work_order_number; }
    msEl('ms_maintenance_request_id').value = s.maintenance_request_id || '';
    msEl('ms_location_type').value = s.location_type || 'unit';
    msEl('ms_building_id').value = s.building_id || '';
    msSyncLocationUi();
    msLoadUnits(s.building_id || '', s.unit_id || '');
    msLoadCommonAreas(s.building_id || '', s.common_area_id || '');
    msEl('ms_task_type').value = s.task_type;
    msEl('ms_priority').value = s.priority;
    msEl('ms_title').value = s.title || '';
    msEl('ms_description').value = s.description || '';
    msEl('ms_notes').value = s.notes || '';
    msEl('ms_schedule_date').value = s.schedule_date;
    msEl('ms_start_time').value = s.start_time;
    msEl('ms_end_time').value = s.end_time;
    msEl('ms_status').value = s.status;
    (s.employee_ids || []).forEach(eid => {
        const cb = document.querySelector('#msForm input[name="employee_ids[]"][value="' + eid + '"]');
        if (cb) cb.checked = true;
    });
    new bootstrap.Modal(msEl('msModal')).show();
}

function msSyncLocationUi(){
    const type = msEl('ms_location_type')?.value || 'unit';
    const unitWrap = msEl('ms_unit_wrap');
    const caWrap = msEl('ms_common_area_wrap');
    const hint = msEl('ms_location_hint');
    const legacyOpt = msEl('ms_loc_building_opt') || msEl('ms_location_type')?.querySelector('option[value="building"]');
    if (unitWrap) unitWrap.style.display = (type === 'unit') ? '' : 'none';
    if (caWrap) caWrap.style.display = (type === 'common_area') ? '' : 'none';
    if (hint) hint.style.display = (type === 'building') ? '' : 'none';
    // Only show legacy option when editing an existing building-only WO
    if (legacyOpt) {
        legacyOpt.style.display = (type === 'building') ? '' : 'none';
        legacyOpt.disabled = (type !== 'building');
    }
    if (type !== 'unit' && msEl('ms_unit_id')) msEl('ms_unit_id').value = '';
    if (type !== 'common_area' && msEl('ms_common_area_id')) msEl('ms_common_area_id').value = '';
}

function msLoadUnits(buildingId, selectedUnit){
    const sel = msEl('ms_unit_id');
    if (!sel) return;
    sel.innerHTML = '<option value="">-- Select unit --</option>';
    if (!buildingId) return;
    fetch('ajax_get_units.php?building_id=' + encodeURIComponent(buildingId))
        .then(r => r.json())
        .then(d => {
            (d.units || []).forEach(u => {
                const o = document.createElement('option');
                o.value = u.id; o.textContent = u.unit_number;
                if (String(u.id) === String(selectedUnit)) o.selected = true;
                sel.appendChild(o);
            });
        }).catch(()=>{});
}

function msLoadCommonAreas(buildingId, selectedArea){
    const sel = msEl('ms_common_area_id');
    if (!sel) return;
    sel.innerHTML = '<option value="">-- Select common area --</option>';
    if (!buildingId) return;
    fetch('ajax_get_common_areas.php?building_id=' + encodeURIComponent(buildingId))
        .then(r => r.json())
        .then(d => {
            (d.common_areas || []).forEach(a => {
                const o = document.createElement('option');
                o.value = a.id;
                o.textContent = a.area_name;
                if (String(a.id) === String(selectedArea)) o.selected = true;
                sel.appendChild(o);
            });
        }).catch(()=>{});
}

msEl('ms_building_id')?.addEventListener('change', function(){
    msLoadUnits(this.value, '');
    msLoadCommonAreas(this.value, '');
});
msEl('ms_location_type')?.addEventListener('change', function(){ msSyncLocationUi(); });

// Schedule-from-request picker inside modal
function msApplyRequest(){
    const sel = msEl('ms_request_picker');
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value){ return; }
    msEl('ms_maintenance_request_id').value = opt.value;
    const locType = opt.dataset.locationType || 'unit';
    msEl('ms_location_type').value = (locType === 'building') ? 'unit' : locType;
    if (locType === 'building') {
        // Prefer forcing reassignment path when scheduling from a legacy request (rare)
        msEl('ms_location_type').value = 'unit';
    }
    msEl('ms_building_id').value = opt.dataset.building || '';
    msSyncLocationUi();
    msLoadUnits(opt.dataset.building || '', opt.dataset.unit || '');
    msLoadCommonAreas(opt.dataset.building || '', opt.dataset.commonArea || '');
    if (opt.dataset.tasktype) msEl('ms_task_type').value = opt.dataset.tasktype;
    if (opt.dataset.description) msEl('ms_description').value = opt.dataset.description;
}

function msReopenWithConflict(override){
    msOpenAdd();
    <?php if (!empty($formRepop)): ?>
    const rep = <?= json_encode($formRepop) ?>;
    msEl('ms_schedule_id').value = rep.schedule_id || '';
    msEl('ms_maintenance_request_id').value = rep.maintenance_request_id || '';
    msEl('ms_location_type').value = rep.location_type || 'unit';
    msEl('ms_building_id').value = rep.building_id || '';
    msSyncLocationUi();
    msLoadUnits(rep.building_id || '', rep.unit_id || '');
    msLoadCommonAreas(rep.building_id || '', rep.common_area_id || '');
    msEl('ms_task_type').value = rep.task_type || 'general';
    msEl('ms_priority').value = rep.priority || 'normal';
    msEl('ms_title').value = rep.title || '';
    msEl('ms_description').value = rep.description || '';
    msEl('ms_notes').value = rep.notes || '';
    msEl('ms_schedule_date').value = rep.schedule_date || MS_DEFAULT_DATE;
    msEl('ms_start_time').value = rep.start_time || '09:00';
    msEl('ms_end_time').value = rep.end_time || '10:00';
    msEl('ms_status').value = rep.status || 'scheduled';
    (rep.employee_ids || []).forEach(eid => {
        const cb = document.querySelector('#msForm input[name="employee_ids[]"][value="' + eid + '"]');
        if (cb) cb.checked = true;
    });
    <?php endif; ?>
    if (override) msEl('ms_override_conflict').value = '1';
}

// Open the Add modal prefilled with an employee + time range (drag-create / slot click)
function msCreateAt(empId, startStr, endStr){
    msResetForm();
    if (startStr) msEl('ms_start_time').value = startStr;
    if (endStr) msEl('ms_end_time').value = endStr;
    msEl('ms_schedule_date').value = MS_DEFAULT_DATE;
    if (empId){
        const cb = document.querySelector('#msForm input[name="employee_ids[]"][value="' + empId + '"]');
        if (cb) cb.checked = true;
    }
    new bootstrap.Modal(msEl('msModal')).show();
}

/* ----- Drag-to-create / resize / reassign on the daily board (pointer events) ----- */
(function(){
    if (MS_VIEW !== 'day') return;
    const SNAP = 15;
    const winStartMin = MS_WIN_START * 60, winEndMin = MS_WIN_END * 60, winLen = winEndMin - winStartMin;

    function clamp(v, a, b){ return Math.max(a, Math.min(b, v)); }
    function pxToMin(track, clientX){
        const r = track.getBoundingClientRect();
        const pct = clamp((clientX - r.left) / r.width, 0, 1);
        return Math.round((winStartMin + pct * winLen) / SNAP) * SNAP;
    }
    function minToStr(min){ min = clamp(min, winStartMin, winEndMin); return String(Math.floor(min/60)).padStart(2,'0') + ':' + String(min%60).padStart(2,'0'); }
    function parseMin(str){ const p = (str||'').split(':'); return (parseInt(p[0],10)||0)*60 + (parseInt(p[1],10)||0); }
    function trackUnder(x, y){ const el = document.elementFromPoint(x, y); return el ? el.closest('.ms-track[data-employee]') : null; }

    function post(payload, onConflict){
        const body = new URLSearchParams(Object.assign({ _csrf: MS_CSRF }, payload));
        fetch('ajax_maintenance_schedule_save.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body })
          .then(r => r.json())
          .then(d => {
              if (d.success){ location.reload(); return; }
              if (d.conflicts && d.conflicts.length && MS_CAN_OVERRIDE){
                  const msg = d.conflicts.map(c => c.employee_name + ' ' + (c.start_time||'').slice(0,5) + '-' + (c.end_time||'').slice(0,5)).join('\n');
                  if (confirm('Scheduling conflict:\n' + msg + '\n\nOverride and save anyway?')){
                      post(Object.assign({}, payload, { override: 1 }), onConflict);
                      return;
                  }
                  location.reload();
              } else {
                  alert(d.message || 'Could not save change.');
                  location.reload();
              }
          })
          .catch(() => { alert('Network error.'); location.reload(); });
    }

    let drag = null, ghost = null;

    function clearGhost(){ if (ghost){ ghost.remove(); ghost = null; } }

    // Block drag (move/reassign) + resize
    document.querySelectorAll('.ms-block.ms-draggable').forEach(function(block){
        block.addEventListener('pointerdown', function(e){
            if (e.button && e.button !== 0) return;
            const onResize = e.target.classList.contains('ms-resize');
            const side = onResize ? (e.target.classList.contains('l') ? 'l' : 'r') : null;
            const track = block.closest('.ms-track');
            const rect = block.getBoundingClientRect();
            drag = {
                mode: onResize ? 'resize' : 'move', side, block, track,
                schedule: block.dataset.schedule, fromEmp: block.dataset.emp,
                startMin: parseMin(block.dataset.start), endMin: parseMin(block.dataset.end),
                grabOffset: e.clientX - rect.left, downX: e.clientX, downY: e.clientY, moved: false
            };
            try { block.setPointerCapture(e.pointerId); } catch(_){}
            e.stopPropagation(); e.preventDefault();
        });
    });

    window.addEventListener('pointermove', function(e){
        if (!drag) return;
        if (!drag.moved && Math.abs(e.clientX - drag.downX) < 4 && Math.abs(e.clientY - drag.downY) < 4) return;
        drag.moved = true;
        drag.block.classList.add('ms-dragging');
        document.querySelectorAll('.ms-track.ms-drop-target').forEach(t => t.classList.remove('ms-drop-target'));
        if (drag.mode === 'move'){
            const tgt = trackUnder(e.clientX, e.clientY) || drag.track;
            if (tgt && tgt !== drag.track) tgt.classList.add('ms-drop-target');
        }
    });

    window.addEventListener('pointerup', function(e){
        if (!drag) return;
        const d = drag; drag = null;
        d.block.classList.remove('ms-dragging');
        document.querySelectorAll('.ms-track.ms-drop-target').forEach(t => t.classList.remove('ms-drop-target'));

        if (!d.moved){ msOpenView(parseInt(d.schedule,10)); return; }

        if (d.mode === 'resize'){
            let s = d.startMin, en = d.endMin;
            if (d.side === 'r') en = pxToMin(d.track, e.clientX);
            else s = pxToMin(d.track, e.clientX);
            if (en - s < SNAP){ if (d.side === 'r') en = s + SNAP; else s = en - SNAP; }
            post({ op:'resize', schedule_id:d.schedule, start_time:minToStr(s), end_time:minToStr(en) });
            return;
        }
        // move
        const tgt = trackUnder(e.clientX, e.clientY) || d.track;
        const dur = d.endMin - d.startMin;
        let newStart = pxToMin(tgt, e.clientX - d.grabOffset);
        newStart = clamp(newStart, winStartMin, winEndMin - dur);
        const newEnd = newStart + dur;
        const toEmp = tgt.getAttribute('data-employee');
        if (toEmp && toEmp !== d.fromEmp){
            post({ op:'reassign', schedule_id:d.schedule, from_employee_id:d.fromEmp, to_employee_id:toEmp, start_time:minToStr(newStart), end_time:minToStr(newEnd) });
        } else {
            post({ op:'move_time', schedule_id:d.schedule, start_time:minToStr(newStart), end_time:minToStr(newEnd) });
        }
    });

    // Drag-to-create on empty track area
    document.querySelectorAll('.ms-track[data-employee]').forEach(function(track){
        track.addEventListener('pointerdown', function(e){
            if (e.target.closest('.ms-block')) return; // grabbing a block, not creating
            if (e.button && e.button !== 0) return;
            const empId = track.getAttribute('data-employee');
            const startMin = pxToMin(track, e.clientX);
            let create = { track, empId, startMin, downX: e.clientX, moved: false };
            ghost = document.createElement('div');
            ghost.className = 'ms-ghost';
            track.appendChild(ghost);
            const r0 = track.getBoundingClientRect();
            function place(curMin){
                const a = Math.min(startMin, curMin), b = Math.max(startMin, curMin);
                ghost.style.left = ((a - winStartMin) / winLen * 100) + '%';
                ghost.style.width = (Math.max(SNAP, b - a) / winLen * 100) + '%';
            }
            place(startMin + SNAP * 2);
            function mv(ev){ create.moved = Math.abs(ev.clientX - create.downX) > 4; place(pxToMin(track, ev.clientX)); }
            function up(ev){
                window.removeEventListener('pointermove', mv);
                window.removeEventListener('pointerup', up);
                const endMin = pxToMin(track, ev.clientX);
                clearGhost();
                let a = Math.min(startMin, endMin), b = Math.max(startMin, endMin);
                if (b - a < SNAP) b = a + 60; // simple click -> default 1h
                msCreateAt(empId, minToStr(a), minToStr(b));
            }
            window.addEventListener('pointermove', mv);
            window.addEventListener('pointerup', up);
            e.preventDefault();
        });
    });
})();

<?php if ($prefill): ?>
// Auto-open modal prefilled from a maintenance request (Schedule Work action)
document.addEventListener('DOMContentLoaded', function(){
    msOpenAdd();
    msEl('ms_maintenance_request_id').value = <?= (int)$prefill['id'] ?>;
    msEl('ms_location_type').value = <?= json_encode(($prefill['location_type'] ?? 'unit') === 'common_area' ? 'common_area' : 'unit') ?>;
    msEl('ms_building_id').value = <?= (int)$prefill['building_id'] ?>;
    msSyncLocationUi();
    msLoadUnits(<?= (int)$prefill['building_id'] ?>, <?= (int)($prefill['unit_id'] ?? 0) ?>);
    msLoadCommonAreas(<?= (int)$prefill['building_id'] ?>, <?= (int)($prefill['common_area_id'] ?? 0) ?>);
    msEl('ms_task_type').value = <?= json_encode(re_ms_map_category_to_task_type($prefill['category'])) ?>;
    msEl('ms_description').value = <?= json_encode($prefill['description']) ?>;
    msEl('ms_title').value = <?= json_encode('Request #' . $prefill['id'] . ' - ' . ($prefill['category'] ?: 'Maintenance')) ?>;
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
