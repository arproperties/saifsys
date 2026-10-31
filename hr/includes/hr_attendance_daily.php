<?php
/**
 * Every day of a month for a list of employees: what the day was and the hours.
 * Used by the Monthly summary's daily detail (page, CSV and Excel).
 *
 * An attendance row wins (that is what payroll reads); days without one show
 * approved leave, a public holiday, the weekly off, "Not employed" (before
 * joining or after leaving), "No record", or nothing for days still to come.
 * Each day also carries its schedule and, for a worked day, Late in / Early
 * out / Short hours against it.
 * Display only: nothing here changes attendance or payroll.
 */

require_once __DIR__ . '/../../includes/hr_attendance_attachments.php';
require_once __DIR__ . '/hr_weekly_off.php';
require_once __DIR__ . '/hr_schedule_helper.php';

/** Minutes of grace before a check in counts as late or a check out as early. */
const HR_ATT_GRACE_MINUTES = 15;
/** Worked this many minutes less than scheduled counts as short hours. */
const HR_ATT_SHORT_MINUTES = 30;

/**
 * The day's schedule as text: "09:00–13:00, 18:00–03:00 (+1)", "Flexible 8 h",
 * "Off", or '' when the employee has no schedule.
 */
function hr_attendance_schedule_text(bool $hasSchedule, string $label, float $hours): string
{
    if (!$hasSchedule) {
        return '';
    }
    $ranges = hr_schedule_ranges($label);
    if ($ranges) {
        return implode(', ', array_map(static fn($r) => $r['start'] . '–' . $r['end'] . ($r['overnight'] ? ' (+1)' : ''), $ranges));
    }
    return $hours > 0 ? 'Flexible ' . rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.') . ' h' : 'Off';
}

/**
 * Late in / Early out / Short hours for a worked day against its schedule.
 * Only for approved or pending days with a check in on a scheduled working day
 * (a half day is short on purpose). A flexible day (hours, no times) can only
 * be short. Out earlier than In is the next morning, as everywhere else.
 *
 * @return list<string>
 */
function hr_attendance_day_flags(?array $a, string $label, float $schedHours): array
{
    if (!$a || $schedHours <= 0 || !in_array((string)$a['status'], ['approved', 'pending'], true) || empty($a['check_in'])) {
        return [];
    }
    $min = static function (string $t): int {
        [$h, $m] = array_map('intval', explode(':', $t) + [1 => 0]);
        return $h * 60 + $m;
    };
    $flags = [];
    $in = $min((string)$a['check_in']);
    $ranges = hr_schedule_ranges($label);
    if ($ranges) {
        $first = $ranges[0];
        $last = $ranges[count($ranges) - 1];
        $start = $min($first['start']);
        // The last duty's end on the work day's clock (past midnight adds a day).
        $end = $min($last['end']);
        $lastStart = $min($last['start']);
        if ($last['overnight'] || $lastStart < $start) {
            $end += 1440;
        }
        if ($in > $start + HR_ATT_GRACE_MINUTES) {
            $flags[] = 'Late in';
        }
        if (!empty($a['check_out'])) {
            $out = $min((string)$a['check_out']);
            if ($out <= $in) {
                $out += 1440;
            }
            if ($out < $end - HR_ATT_GRACE_MINUTES) {
                $flags[] = 'Early out';
            }
        }
    }
    if ($a['hours'] !== null && $a['hours'] !== '' && (float)$a['hours'] * 60 < $schedHours * 60 - HR_ATT_SHORT_MINUTES) {
        $flags[] = 'Short hours';
    }
    return $flags;
}

/**
 * Per employee, from the day-by-day rows: scheduled hours up to today (not on
 * leave, holidays or days outside employment) and how many days were late in,
 * early out or short.
 *
 * @return array<int, array{scheduled: float, late: int, early: int, short: int}>
 */
function hr_attendance_compare_by_employee(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $o = &$out[$r['employee_id']];
        $o ??= ['scheduled' => 0.0, 'late' => 0, 'early' => 0, 'short' => 0];
        if (!in_array($r['kind'], ['future', 'not_employed', 'leave', 'holiday', 'on_leave'], true)) {
            $o['scheduled'] += $r['sched_hours'];
        }
        $o['late'] += in_array('Late in', $r['flags'], true) ? 1 : 0;
        $o['early'] += in_array('Early out', $r['flags'], true) ? 1 : 0;
        $o['short'] += in_array('Short hours', $r['flags'], true) ? 1 : 0;
        unset($o);
    }
    return $out;
}

/** Colours per kind of day: [background, text]. */
function hr_attendance_daily_colors(string $kind): array
{
    return [
        'approved' => ['#e8f5ee', '#146c43'],
        'pending' => ['#eef0f3', '#495057'],
        'absent' => ['#fdecee', '#b02a37'],
        'half' => ['#fff4d6', '#7a5a00'],
        'on_leave' => ['#e0f4fb', '#0b6b86'],
        'excused_absent' => ['#ffeedd', '#a14a00'],
        'leave' => ['#e0f4fb', '#0b6b86'],
        'holiday' => ['#fff7e6', '#9a6700'],
        'weekly_off' => ['#eceef1', '#4b5563'],
        'not_employed' => ['#f8f9fa', '#9ca3af'],
        'none' => ['#ffffff', '#9ca3af'],
        'future' => ['#ffffff', '#c0c4ca'],
    ][$kind] ?? ['#ffffff', '#374151'];
}

/**
 * @param list<array> $employees rows with id, employee_code, full_name, company_name, department, location
 * @return list<array> one row per employee per day
 */
function hr_attendance_daily_rows(PDO $conn, array $employees, int $year, int $month): array
{
    if (!$employees || $month < 1 || $month > 12) {
        return [];
    }
    $from = sprintf('%04d-%02d-01', $year, $month);
    $to = date('Y-m-t', strtotime($from));
    $today = date('Y-m-d');
    $ids = implode(',', array_map('intval', array_column($employees, 'id')));

    $meta = [];
    foreach ($conn->query("SELECT id, company_id, location_id, date_joined, exit_date, last_working_day FROM employees WHERE id IN ($ids)") as $m) {
        $meta[(int)$m['id']] = $m;
    }
    $att = [];
    $st = $conn->prepare("SELECT employee_id, work_date, status, check_in, check_out, hours, notes FROM attendance
        WHERE employee_id IN ($ids) AND work_date BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $att[(int)$r['employee_id']][$r['work_date']] = $r;
    }
    $leaves = [];
    $st = $conn->prepare("SELECT lr.employee_id, lr.date_from, lr.date_to, lt.name AS type_name FROM leave_requests lr
        LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
        WHERE lr.employee_id IN ($ids) AND lr.status = 'approved' AND lr.date_from <= ? AND lr.date_to >= ?");
    $st->execute([$to, $from]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $leaves[(int)$l['employee_id']][] = $l;
    }
    $holidays = $conn->query("SELECT name, holiday_date, repeats_annually, location_id FROM holidays WHERE holiday_date IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    $valid = static fn($v) => ($v && $v !== '0000-00-00') ? (string)$v : null;

    $rows = [];
    foreach ($employees as $e) {
        $eid = (int)$e['id'];
        $m = $meta[$eid] ?? [];
        $joined = $valid($m['date_joined'] ?? null);
        $left = $valid($m['last_working_day'] ?? null) ?? $valid($m['exit_date'] ?? null);
        $sched = hr_schedule_summary($conn, $eid, $from, $to, true);
        $schedSaved = null; // the schedule as saved, for a weekly off that was worked
        for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            $ymd = $d->format('Y-m-d');
            $a = $att[$eid][$ymd] ?? null;
            // The weekly off in force on that date (a change applies from the day it was made).
            // Only a day with no attendance is the weekly off: a day with attendance shows it.
            $isOff = !$a && (int)$d->format('N') === hr_weekly_off_for_employee($conn, $eid, isset($m['company_id']) ? (int)$m['company_id'] : null, $ymd);
            $leave = null;
            foreach ($leaves[$eid] ?? [] as $l) {
                if ($l['date_from'] <= $ymd && $l['date_to'] >= $ymd) { $leave = $l; break; }
            }
            $holiday = null;
            foreach ($holidays as $h) {
                if (($h['location_id'] === null || (int)$h['location_id'] === (int)($m['location_id'] ?? 0))
                    && ($h['holiday_date'] === $ymd || ((int)$h['repeats_annually'] === 1 && substr($h['holiday_date'], 5) === $d->format('m-d')))) {
                    $holiday = $h['name']; break;
                }
            }
            if ($a) {
                $kind = (string)$a['status'];
                $label = hr_attendance_status_label($kind);
            } elseif (($joined && $ymd < $joined) || ($left && $ymd > $left)) {
                $kind = 'not_employed';
                $label = 'Not employed';
            } elseif ($leave) {
                $kind = 'leave';
                $label = 'Leave' . ($leave['type_name'] ? ' (' . $leave['type_name'] . ')' : '');
            } elseif ($holiday !== null) {
                $kind = 'holiday';
                $label = 'Holiday: ' . $holiday;
            } elseif ($isOff) {
                $kind = 'weekly_off';
                $label = 'Weekly off';
            } elseif ($ymd > $today) {
                $kind = 'future';
                $label = '';
            } else {
                $kind = 'none';
                $label = 'No record';
            }
            $sDay = $sched['days'][$ymd] ?? ['label' => '', 'expected_hours' => 0.0];
            if ($a && !empty($sDay['weekly_off'])) {
                $schedSaved ??= hr_schedule_summary($conn, $eid, $from, $to);
                $sDay = $schedSaved['days'][$ymd] ?? $sDay;
                // On screen every duty counts (the saved summary counts hours as payroll does).
                $sDay['expected_hours'] = hr_schedule_parse_hours((string)($sDay['label'] ?? ''));
            }
            $sHours = (float)$sDay['expected_hours'];
            $rows[] = [
                'employee_id' => $eid,
                'employee_code' => $e['employee_code'],
                'full_name' => $e['full_name'],
                'company_name' => $e['company_name'] ?? '',
                'department' => $e['department'] ?? '',
                'location' => $e['location'] ?? '',
                'date' => $ymd,
                'dow' => $d->format('D'),
                'check_in' => $a && $a['check_in'] ? substr((string)$a['check_in'], 0, 5) : '',
                'check_out' => $a && $a['check_out'] ? substr((string)$a['check_out'], 0, 5) . (hr_attendance_is_overnight($a['check_in'], $a['check_out']) ? ' (+1)' : '') : '',
                'hours' => $a && $a['hours'] !== null ? number_format((float)$a['hours'], 2) : '',
                'kind' => $kind,
                'status' => $label,
                'notes' => $a ? (string)($a['notes'] ?? '') : '',
                'weekly_off' => $isOff ? 'Yes' : '',
                'scheduled' => hr_attendance_schedule_text(!empty($sched['has_schedule']), (string)$sDay['label'], $sHours),
                'sched_hours' => $sHours,
                'flags' => hr_attendance_day_flags($a, (string)$sDay['label'], $sHours),
            ];
        }
    }
    return $rows;
}

/** Count of days per status label, and total hours, for one employee's rows. */
function hr_attendance_daily_totals(array $rows): array
{
    $by = [];
    $hours = 0.0;
    $flags = [];
    foreach ($rows as $r) {
        if ($r['kind'] === 'future') {
            continue;
        }
        foreach ($r['flags'] ?? [] as $fl) {
            $flags[$fl] = ($flags[$fl] ?? 0) + 1;
        }
        $key = in_array($r['kind'], ['leave', 'holiday'], true) ? ($r['kind'] === 'leave' ? 'Leave' : 'Holiday') : $r['status'];
        $by[$key] = ($by[$key] ?? 0) + 1;
        $hours += $r['hours'] !== '' ? (float)str_replace(',', '', $r['hours']) : 0;
    }
    $flags = array_filter(array_merge(['Late in' => 0, 'Early out' => 0, 'Short hours' => 0], $flags));
    return ['by' => $by, 'hours' => round($hours, 2), 'flags' => $flags];
}
