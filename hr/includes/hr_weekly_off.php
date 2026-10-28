<?php
/**
 * Weekly off: one rest day a week, per company, with an optional per-employee
 * override. Sunday until HR sets something else.
 *
 * A change applies from the day it is made: earlier dates keep the day that
 * was in force then. Kept in `settings` as weekly_off.c3 (company 3) and
 * weekly_off.e12 (employee 12), the value a JSON list of [from date, ISO
 * weekday] (1 = Monday … 7 = Sunday; for an employee, null = back to the
 * company's day). A plain number (the first format) means "from always".
 *
 * Attendance and leave day counts never read it. From 1 Nov 2026 the work
 * schedule counts this day as Off (hr_schedule_helper.php), so payroll's
 * scheduled workdays follow it; earlier months are left as they were.
 */

const HR_WEEKLY_OFF_DEFAULT = 7;

/** ISO weekday => name. */
function hr_weekly_off_day_names(): array
{
    return [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
}

/** Every weekly_off.* setting, read once per request. */
function hr_weekly_off_rows(PDO $conn, bool $refresh = false): array
{
    static $rows = null;
    if ($rows === null || $refresh) {
        try {
            $st = $conn->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'weekly\\_off.%'");
            $rows = $st ? $st->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        } catch (Throwable $e) {
            $rows = [];
        }
    }
    return $rows;
}

function hr_weekly_off_valid_day($day): ?int
{
    $d = (int)$day;
    return ($d >= 1 && $d <= 7 && (string)$d === trim((string)$day)) ? $d : null;
}

/**
 * One key's history, oldest first: list of ['from' => 'Y-m-d', 'day' => int|null].
 */
function hr_weekly_off_history(PDO $conn, string $key): array
{
    static $cache = [];
    static $seen = null;
    $rows = hr_weekly_off_rows($conn);
    if ($seen !== $rows) {
        $cache = [];
        $seen = $rows;
    }
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $raw = trim((string)($rows[$key] ?? ''));
    $out = [];
    if ($raw !== '') {
        if (hr_weekly_off_valid_day($raw) !== null) {
            $out[] = ['from' => '0000-00-00', 'day' => (int)$raw];
        } else {
            $list = json_decode($raw, true);
            foreach (is_array($list) ? $list : [] as $item) {
                if (!is_array($item) || !isset($item[0]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$item[0])) {
                    continue;
                }
                $day = $item[1] ?? null;
                $out[] = ['from' => (string)$item[0], 'day' => $day === null ? null : hr_weekly_off_valid_day($day)];
            }
            usort($out, static fn($a, $b) => strcmp($a['from'], $b['from']));
        }
    }
    return $cache[$key] = $out;
}

/** The entry in force on $date, or null when nothing was set by then. */
function hr_weekly_off_entry_on(array $history, string $date): ?array
{
    $found = null;
    foreach ($history as $h) {
        if ($h['from'] <= $date) {
            $found = $h;
        }
    }
    return $found;
}

/** The company's weekly off day on $date (today when not given); Sunday when not set. */
function hr_weekly_off_company(PDO $conn, ?int $companyId, ?string $date = null): int
{
    if (!$companyId) {
        return HR_WEEKLY_OFF_DEFAULT;
    }
    $entry = hr_weekly_off_entry_on(hr_weekly_off_history($conn, 'weekly_off.c' . $companyId), $date ?? date('Y-m-d'));
    return $entry['day'] ?? HR_WEEKLY_OFF_DEFAULT;
}

/** The employee's own weekly off day on $date, or null when they follow their company. */
function hr_weekly_off_override(PDO $conn, int $employeeId, ?string $date = null): ?int
{
    $entry = hr_weekly_off_entry_on(hr_weekly_off_history($conn, 'weekly_off.e' . $employeeId), $date ?? date('Y-m-d'));
    return $entry['day'] ?? null;
}

/** The day that applies to an employee on $date: their own, else their company's. */
function hr_weekly_off_for_employee(PDO $conn, int $employeeId, ?int $companyId, ?string $date = null): int
{
    return hr_weekly_off_override($conn, $employeeId, $date) ?? hr_weekly_off_company($conn, $companyId, $date);
}

/** Add a change to a key's history from $from on (a later change the same day replaces it). */
function hr_weekly_off_append(PDO $conn, string $key, ?int $day, string $from): void
{
    $history = array_values(array_filter(hr_weekly_off_history($conn, $key), static fn($h) => $h['from'] < $from));
    $history[] = ['from' => $from, 'day' => $day];
    // A change to the day already in force adds nothing.
    $kept = [];
    foreach ($history as $h) {
        if (!$kept || end($kept)['day'] !== $h['day']) {
            $kept[] = $h;
        }
    }
    // settings.value holds 255 characters: drop the oldest changes until it fits,
    // and the oldest one left then counts from always.
    $encode = static fn(array $list) => json_encode(array_map(static fn($h) => [$h['from'], $h['day']], $list));
    while (count($kept) > 1 && strlen($encode($kept)) > 255) {
        array_shift($kept);
        $kept[0]['from'] = '0000-00-00';
    }
    $value = $encode($kept);
    $conn->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
         ->execute([$key, $value]);
    hr_weekly_off_rows($conn, true);
}

/** Set a company's day from $from (today when not given). */
function hr_weekly_off_save_company(PDO $conn, int $companyId, int $day, ?string $from = null): void
{
    hr_weekly_off_append($conn, 'weekly_off.c' . $companyId, $day, $from ?? date('Y-m-d'));
}

/** Set an employee's own day from $from (today when not given), or null to follow the company again. */
function hr_weekly_off_save_employee(PDO $conn, int $employeeId, ?int $day, ?string $from = null): void
{
    hr_weekly_off_append($conn, 'weekly_off.e' . $employeeId, $day, $from ?? date('Y-m-d'));
}
