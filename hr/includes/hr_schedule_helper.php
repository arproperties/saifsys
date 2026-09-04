<?php
/**
 * HR schedule helpers.
 *
 * Schedules are stored as day labels like "9:00 AM - 5:00 PM". These helpers
 * turn that into expected workdays/hours for attendance, leave, and payroll.
 */

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

    $parts = preg_split('/\s*(?:-|–|—|\bto\b)\s*/i', $value);
    if (!$parts || count($parts) < 2) {
        return 0.0;
    }

    $start = strtotime($parts[0]);
    $end = strtotime($parts[1]);
    if ($start === false || $end === false) {
        return 0.0;
    }

    if ($end <= $start) {
        $end += 86400;
    }

    return max(0.0, round(($end - $start) / 3600, 2));
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

function hr_schedule_summary(PDO $conn, int $employeeId, string $from, string $to): array
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
    for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
        $ymd = $date->format('Y-m-d');
        $row = hr_schedule_row_for_date($rows, $ymd);
        $column = $columns[(int)$date->format('N')];
        $label = $row ? (string)($row[$column] ?? '') : '';
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
        ];
    }

    return [
        'has_schedule' => !empty($rows),
        'expected_days' => $expectedDays,
        'expected_hours' => round($expectedHours, 2),
        'days' => $days,
    ];
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
