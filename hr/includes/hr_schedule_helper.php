<?php
/**
 * HR schedule helpers.
 *
 * Schedules are stored as day labels like "9:00 AM - 5:00 PM". These helpers
 * turn that into expected workdays/hours for attendance, leave, and payroll.
 *
 * From HR_SCHEDULE_WEEKLY_OFF_FROM on, screens and reminders can show the
 * weekly off (hr_weekly_off.php) as Off whatever hours the schedule holds
 * (hr_schedule_summary(..., true)). Payroll does not: by default the summary is
 * the schedule as saved, so pay is worked out exactly as before.
 */

require_once __DIR__ . '/hr_weekly_off.php';

const HR_SCHEDULE_WEEKLY_OFF_FROM = '2026-11-01';

function hr_schedule_day_columns(): array
{
    return [
        1 => 'monday_hours',
        2 => 'tuesday_hours',
        3 => 'wednesday_hours',
        4 => 'thursday_hours',
        5 => 'friday_hours',
        6 => 'saturday_hours',
        7 => 'sunday_hours',
    ];
}

function hr_schedule_calendar_days(string $from, string $to): int
{
    try {
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
    } catch (Throwable $e) {
        return 0;
    }

    if ($end < $start) {
        return 0;
    }

    return (int)$start->diff($end)->days + 1;
}

function hr_schedule_parse_hours(?string $value): float
{
    $value = trim((string)$value);
    if ($value === '' || preg_match('/^(off|holiday|rest|none|n\/a)$/i', $value)) {
        return 0.0;
    }

    if (is_numeric($value)) {
        return max(0.0, round((float)$value, 2));
    }

    // One or more duties a day ("9:00 AM - 1:00 PM, 5:00 PM - 9:00 PM"): the hours add up.
    $total = 0.0;
    foreach (hr_schedule_ranges($value) as $r) {
        $total += $r['hours'];
    }
    return max(0.0, round($total, 2));
}

/**
 * One day's label split into its duties, in order. A duty that ends at or
 * before it starts runs past midnight (17:00 - 02:00 is 9 hours).
 *
 * @return list<array{start:string, end:string, hours:float, overnight:bool}> times as H:i
 */
function hr_schedule_ranges(?string $value): array
{
    $value = trim((string)$value);
    if ($value === '' || preg_match('/^(off|holiday|rest|none|n\/a)$/i', $value) || is_numeric($value)) {
        return [];
    }
    $out = [];
    foreach (preg_split('/\s*[,;]\s*/', $value) as $piece) {
        $parts = preg_split('/\s*(?:-|–|—|\bto\b)\s*/i', $piece);
        if (!$parts || count($parts) < 2) {
            continue;
        }
        $start = strtotime($parts[0]);
        $end = strtotime($parts[1]);
        if ($start === false || $end === false) {
            continue;
        }
        $overnight = $end <= $start;
        $out[] = [
            'start' => date('H:i', $start),
            'end' => date('H:i', $end),
            'hours' => round((($overnight ? $end + 86400 : $end) - $start) / 3600, 2),
            'overnight' => $overnight,
        ];
    }
    return $out;
}

/** "17:00" as "5:00 PM" (how schedules are stored). */
function hr_schedule_time_label(string $hm): string
{
    $t = strtotime('1970-01-01 ' . $hm);
    return $t === false ? $hm : date('g:i A', $t);
}

function hr_schedule_rows(PDO $conn, int $employeeId, string $from, string $to): array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM employee_work_schedules
        WHERE employee_id = ?
          AND effective_from <= ?
          AND (effective_to IS NULL OR effective_to = '0000-00-00' OR effective_to >= ?)
        ORDER BY effective_from DESC, id DESC
    ");
    $stmt->execute([$employeeId, $to, $from]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function hr_schedule_row_for_date(array $rows, string $date): ?array
{
    foreach ($rows as $row) {
        $from = (string)($row['effective_from'] ?? '');
        $to = (string)($row['effective_to'] ?? '');
        if ($from !== '' && $from > $date) {
            continue;
        }
        if ($to !== '' && $to !== '0000-00-00' && $to < $date) {
            continue;
        }
        return $row;
    }
    return null;
}

/**
 * Expected days and hours per date, from the schedule as saved; payroll,
 * payslips and the absence deduction use this as it is. Screens and reminders
 * pass $applyWeeklyOff = true to also show the weekly off as Off.
 */
function hr_schedule_summary(PDO $conn, int $employeeId, string $from, string $to, bool $applyWeeklyOff = false): array
{
    $rows = hr_schedule_rows($conn, $employeeId, $from, $to);
    $days = [];
    $expectedDays = 0;
    $expectedHours = 0.0;

    try {
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
    } catch (Throwable $e) {
        return [
            'has_schedule' => !empty($rows),
            'expected_days' => 0,
            'expected_hours' => 0.0,
            'days' => [],
        ];
    }

    if ($end < $start) {
        return [
            'has_schedule' => !empty($rows),
            'expected_days' => 0,
            'expected_hours' => 0.0,
            'days' => [],
        ];
    }

    $columns = hr_schedule_day_columns();
    $companyId = null;
    if ($applyWeeklyOff && $rows && $to >= HR_SCHEDULE_WEEKLY_OFF_FROM) {
        $st = $conn->prepare("SELECT company_id FROM employees WHERE id = ?");
        $st->execute([$employeeId]);
        $companyId = ($c = $st->fetchColumn()) ? (int)$c : null;
    }
    for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
        $ymd = $date->format('Y-m-d');
        $row = hr_schedule_row_for_date($rows, $ymd);
        $column = $columns[(int)$date->format('N')];
        $label = $row ? (string)($row[$column] ?? '') : '';
        $weeklyOff = $applyWeeklyOff && $row && $ymd >= HR_SCHEDULE_WEEKLY_OFF_FROM
            && (int)$date->format('N') === hr_weekly_off_for_employee($conn, $employeeId, $companyId, $ymd);
        if ($weeklyOff) {
            $label = 'Off';
        }
        $hours = hr_schedule_parse_hours($label);
        $isWorkday = $hours > 0;

        if ($isWorkday) {
            $expectedDays++;
            $expectedHours += $hours;
        }

        $days[$ymd] = [
            'date' => $ymd,
            'column' => $column,
            'label' => $label,
            'expected_hours' => $hours,
            'is_workday' => $isWorkday,
            'weekly_off' => $weeklyOff,
        ];
    }

    return [
        'has_schedule' => !empty($rows),
        'expected_days' => $expectedDays,
        'expected_hours' => round($expectedHours, 2),
        'days' => $days,
    ];
}

/** Null when a leave request's dates are fine, else why not. */
function hr_leave_dates_error(string $from, string $to): ?string
{
    $valid = static function (string $d): bool {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        return $dt !== false && $dt->format('Y-m-d') === $d;
    };
    if (!$valid($from) || !$valid($to)) {
        return 'Choose valid From and To dates.';
    }
    if ($to < $from) {
        return 'The To date cannot be before the From date.';
    }
    return null;
}

function hr_schedule_leave_days(PDO $conn, int $employeeId, string $from, string $to): int
{
    // $summary = hr_schedule_summary($conn, $employeeId, $from, $to);
    // if ($summary['has_schedule'] && $summary['expected_days'] > 0) {
    //     return (int)$summary['expected_days'];
    // }
    // Commented due to Leave requests should count total Calender days inclusive of start and end,
    // even when the employee schedule defines sundays or other non-working days.
    //Updated By - Jacob. Developer; Business requirement from: Mr. Jherry; Approved by: Ms. Rona
    return hr_schedule_calendar_days($from, $to);
}

function hr_schedule_salary_daily_rate(float $monthlySalary, array $summary): float
{
    if ($monthlySalary <= 0) {
        return 0.0;
    }
    $workdays = (int)($summary['expected_days'] ?? 0);
    if (!empty($summary['has_schedule']) && $workdays > 0) {
        return round($monthlySalary / $workdays, 2);
    }
    return round($monthlySalary / 30, 2);
}
