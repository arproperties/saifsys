<?php
/**
 * Real Estate Module - Maintenance Schedule iCal (.ics) export.
 * Exports the visible/filtered work orders as calendar events. Optional
 * ?employee_id= restricts to a single employee's calendar.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/maintenance_schedule_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_MAINTENANCE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$currentCompanyId = current_company_id($conn) ?: 1;
$brand = getBrandSettings($conn);

$date = $_GET['date'] ?? date('Y-m-d');
$dchk = DateTime::createFromFormat('Y-m-d', $date);
if (!$dchk || $dchk->format('Y-m-d') !== $date) $date = date('Y-m-d');

$view = (($_GET['view'] ?? 'day') === 'week') ? 'week' : 'day';
if ($view === 'week') {
    $monday = (new DateTime($date))->modify('monday this week');
    $dateFrom = $monday->format('Y-m-d');
    $dateTo = (clone $monday)->modify('+6 days')->format('Y-m-d');
} else {
    $dateFrom = $dateTo = $date;
}

$filters = [
    'building_id' => (int)($_GET['building_id'] ?? 0),
    'employee_id' => (int)($_GET['employee_id'] ?? 0),
    'status' => (in_array(($_GET['status'] ?? ''), array_keys(re_ms_statuses()), true)) ? $_GET['status'] : '',
    'task_type' => (in_array(($_GET['task_type'] ?? ''), array_keys(re_ms_task_types()), true)) ? $_GET['task_type'] : '',
];

$schedules = re_ms_fetch_schedules($conn, $currentCompanyId, $dateFrom, $dateTo, $filters);
$taskTypes = re_ms_task_types();
$statuses = re_ms_statuses();

function ics_escape($s) {
    return preg_replace('/([,;\\\\])/', '\\\\$1', str_replace(["\r\n", "\n", "\r"], '\\n', (string)$s));
}

$domain = $_SERVER['HTTP_HOST'] ?? 'herosysgro.local';
$lines = [];
$lines[] = 'BEGIN:VCALENDAR';
$lines[] = 'VERSION:2.0';
$lines[] = 'PRODID:-//' . ics_escape($brand['system_name'] ?? 'HZ System') . '//Maintenance Schedule//EN';
$lines[] = 'CALSCALE:GREGORIAN';
$lines[] = 'METHOD:PUBLISH';
$lines[] = 'X-WR-CALNAME:' . ics_escape('Maintenance Schedule ' . $dateFrom . ($dateFrom !== $dateTo ? (' to ' . $dateTo) : ''));

$stamp = gmdate('Ymd\THis\Z');
foreach ($schedules as $s) {
    $start = str_replace([':', '-'], '', $s['schedule_date'] . 'T' . substr($s['start_time'], 0, 8));
    $end = str_replace([':', '-'], '', $s['schedule_date'] . 'T' . substr($s['end_time'], 0, 8));
    $loc = trim(($s['building_name'] ?? '') . ($s['unit_number'] ? ' - Unit ' . $s['unit_number'] : ''));
    $team = implode(', ', array_map(fn($a) => $a['employee_name'], $s['assignees']));
    $summary = ($s['work_order_number'] ?: ('WO#' . $s['id'])) . ' - ' . ($taskTypes[$s['task_type']] ?? $s['task_type']);
    $desc = 'Status: ' . ($statuses[$s['status']] ?? $s['status'])
        . '\\nTeam: ' . $team
        . ($s['description'] ? '\\n' . ics_escape($s['description']) : '')
        . ($s['notes'] ? '\\nNotes: ' . ics_escape($s['notes']) : '');

    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:re-ms-' . (int)$s['id'] . '@' . $domain;
    $lines[] = 'DTSTAMP:' . $stamp;
    $lines[] = 'DTSTART:' . $start;
    $lines[] = 'DTEND:' . $end;
    $lines[] = 'SUMMARY:' . ics_escape($summary);
    if ($loc !== '') $lines[] = 'LOCATION:' . ics_escape($loc);
    $lines[] = 'DESCRIPTION:' . $desc;
    if ($s['status'] === 'cancelled') $lines[] = 'STATUS:CANCELLED';
    elseif ($s['status'] === 'completed') $lines[] = 'STATUS:CONFIRMED';
    $lines[] = 'END:VEVENT';
}
$lines[] = 'END:VCALENDAR';

$filename = 'maintenance_schedule_' . $dateFrom . ($dateFrom !== $dateTo ? ('_' . $dateTo) : '') . '.ics';
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo implode("\r\n", $lines) . "\r\n";
exit;
