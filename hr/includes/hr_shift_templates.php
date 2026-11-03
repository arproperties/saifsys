<?php
/**
 * Saved shift templates ("Day 9:00 – 18:00", "Night 18:00 – 03:00", ...) that
 * fill a day in the schedule editor with one pick.
 *
 * One `settings` row each: shift_template.4 = {"name":"Night","duties":[["18:00","03:00"]]}.
 * A flexible shift has hours and no times: {"name":"Flexible","duties":[],"flex":8},
 * stored on the schedule day as the plain number ("8"), which payroll already reads.
 * Until HR changes anything the starter shifts are shown; the first change saves
 * them for real (and shift_templates.custom = 1), so deleting every template
 * does not bring them back. A schedule keeps its own times, never a link to a
 * template, so editing or deleting one changes no saved schedule.
 */

require_once __DIR__ . '/hr_schedule_helper.php';

const HR_SHIFT_TEMPLATES_MAX = 20;
const HR_SHIFT_TEMPLATE_MAX_DUTIES = 3;

function hr_shift_template_defaults(): array
{
    return [
        1 => ['name' => 'Day', 'duties' => [['09:00', '18:00']]],
        2 => ['name' => 'Night', 'duties' => [['18:00', '03:00']]],
        3 => ['name' => 'Double', 'duties' => [['09:00', '13:00'], ['18:00', '03:00']]],
        4 => ['name' => 'Part-time', 'duties' => [['09:00', '13:00']]],
        5 => ['name' => 'Flexible', 'duties' => [], 'flex' => 8.0],
    ];
}

/** id => ['name', 'duties' => [[start, end], ...], 'flex' => hours|null], by name. */
function hr_shift_templates(PDO $conn): array
{
    $rows = $conn->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'shift\\_template%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $list = [];
    if (!isset($rows['shift_templates.custom'])) {
        $list = hr_shift_template_defaults();
    } else {
        foreach ($rows as $key => $value) {
            if (!preg_match('/^shift_template\.(\d+)$/', $key, $m)) {
                continue;
            }
            $t = json_decode((string)$value, true);
            if (is_array($t) && isset($t['name'], $t['duties']) && is_array($t['duties'])) {
                $list[(int)$m[1]] = $t;
            }
        }
    }
    foreach ($list as &$t) {
        $t = ['name' => (string)$t['name'], 'duties' => $t['duties'], 'flex' => isset($t['flex']) ? (float)$t['flex'] : null];
    }
    unset($t);
    uasort($list, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $list;
}

function hr_flex_hours_label(float $hours): string
{
    return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
}

/** How a template is stored on a schedule day: "9:00 AM - 1:00 PM, 6:00 PM - 3:00 AM", or "8" for flexible. */
function hr_shift_template_label(array $t): string
{
    if (!empty($t['flex'])) {
        return hr_flex_hours_label((float)$t['flex']);
    }
    return implode(', ', array_map(static fn($d) => hr_schedule_time_label($d[0]) . ' - ' . hr_schedule_time_label($d[1]), $t['duties']));
}

/** The list for the editor's script: [{id, name, duties, flex, label, hours}]. */
function hr_shift_templates_for_js(PDO $conn): array
{
    $out = [];
    foreach (hr_shift_templates($conn) as $id => $t) {
        $stored = hr_shift_template_label($t);
        $out[] = ['id' => $id, 'name' => $t['name'], 'duties' => $t['duties'], 'flex' => $t['flex'],
            'label' => $t['flex'] ? 'any time, ' . $stored . ' h' : $stored, 'hours' => hr_schedule_parse_hours($stored)];
    }
    return $out;
}

/** Flexible hours from a form: 0.5 to 24, in half hours; null when not valid. */
function hr_flex_hours($value): ?float
{
    $v = trim((string)$value);
    if (!is_numeric($v)) {
        return null;
    }
    $h = round((float)$v * 2) / 2;
    return ($h >= 0.5 && $h <= 24) ? $h : null;
}

/**
 * Checks a name and the duties (or flexible hours) from a form. Returns
 * [name, duties, flex] or an error string.
 */
function hr_shift_template_validate($name, $starts, $ends, $flex = null)
{
    $name = trim(preg_replace('/\s+/', ' ', (string)$name));
    if ($name === '' || mb_strlen($name) > 40) {
        return 'Give the shift a name (up to 40 characters).';
    }
    if ($flex !== null && $flex !== '') {
        $h = hr_flex_hours($flex);
        return $h === null ? 'Give the flexible hours, from 0.5 to 24.' : [$name, [], $h];
    }
    $duties = [];
    foreach ((array)$starts as $i => $st) {
        $st = trim((string)$st);
        $en = trim((string)(((array)$ends)[$i] ?? ''));
        if ($st === '' && $en === '') {
            continue;
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $st) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $en) || $st === $en) {
            return 'Give each duty a start and an end time.';
        }
        $duties[] = [$st, $en];
    }
    if (!$duties) {
        return 'Add at least one duty.';
    }
    if (count($duties) > HR_SHIFT_TEMPLATE_MAX_DUTIES) {
        return 'A shift can have up to ' . HR_SHIFT_TEMPLATE_MAX_DUTIES . ' duties.';
    }
    return [$name, $duties, null];
}

/** The starter shifts become real rows before the first change. */
function hr_shift_templates_make_custom(PDO $conn): void
{
    $has = $conn->query("SELECT COUNT(*) FROM settings WHERE `key` = 'shift_templates.custom'")->fetchColumn();
    if ($has) {
        return;
    }
    $ins = $conn->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    foreach (hr_shift_template_defaults() as $id => $t) {
        $ins->execute(['shift_template.' . $id, json_encode($t)]);
    }
    $ins->execute(['shift_templates.custom', '1']);
}

/**
 * Adds ($id null) or changes a template. Returns the id, or an error string.
 */
function hr_shift_template_save(PDO $conn, ?int $id, string $name, array $duties, ?float $flex = null)
{
    hr_shift_templates_make_custom($conn);
    $list = hr_shift_templates($conn);
    if ($id !== null && !isset($list[$id])) {
        return 'That shift no longer exists.';
    }
    foreach ($list as $otherId => $t) {
        if ($otherId !== $id && strcasecmp($t['name'], $name) === 0) {
            return 'There is already a shift called "' . $t['name'] . '".';
        }
    }
    if ($id === null) {
        if (count($list) >= HR_SHIFT_TEMPLATES_MAX) {
            return 'Up to ' . HR_SHIFT_TEMPLATES_MAX . ' shifts can be saved.';
        }
        $id = $list ? max(array_keys($list)) + 1 : 1;
    }
    $value = ['name' => $name, 'duties' => $flex ? [] : $duties];
    if ($flex) {
        $value['flex'] = $flex;
    }
    $conn->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
         ->execute(['shift_template.' . $id, json_encode($value)]);
    return $id;
}

function hr_shift_template_delete(PDO $conn, int $id): bool
{
    hr_shift_templates_make_custom($conn);
    $st = $conn->prepare("DELETE FROM settings WHERE `key` = ?");
    $st->execute(['shift_template.' . $id]);
    return $st->rowCount() > 0;
}

/**
 * The schedule's type from its seven day labels: flexible when any day is hours
 * only, shift when any day has a night or second duty, part time when every
 * working day is 5 hours or less, else regular.
 */
function hr_schedule_type_for(array $dayLabels): string
{
    $workHours = [];
    $shift = false;
    foreach ($dayLabels as $label) {
        $label = trim((string)$label);
        if (is_numeric($label) && (float)$label > 0) {
            return 'flexible';
        }
        $ranges = hr_schedule_ranges($label);
        if (count($ranges) > 1 || array_filter($ranges, static fn($r) => $r['overnight'])) {
            $shift = true;
        }
        if (($h = hr_schedule_parse_hours($label)) > 0) {
            $workHours[] = $h;
        }
    }
    if ($shift) {
        return 'shift';
    }
    return ($workHours && max($workHours) <= 5) ? 'part_time' : 'regular';
}
