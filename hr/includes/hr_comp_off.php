<?php
/**
 * Comp off: a day off earned by working on a public holiday, the weekly off or
 * a day off on the work schedule.
 *
 * A credit is requested for one worked date and is always 'pending' first, HR
 * included. Only approval touches the leave balance: the "Comp Off" leave type's
 * accrued goes up by the credit (1 or 0.5) for the year of the worked date.
 * Cancelling an approved credit takes it back off, unless it has been used.
 * Taking comp off is an ordinary leave request of that type, held to the
 * balance that is left. Comp off does not lapse at year end: what is left is
 * the total over all years, and taking it uses the oldest days first.
 *
 * Tables ship in migrations/hr_comp_off.sql.
 */

require_once __DIR__ . '/hr_schedule_helper.php';
require_once __DIR__ . '/hr_weekly_off.php';

const HR_COMP_OFF_CODE = 'COMPOFF';

/** The Comp Off leave type id, or 0 before the migration has run. */
function hr_comp_off_type_id(PDO $conn): int
{
    static $id = null;
    if ($id === null) {
        try {
            $st = $conn->prepare("SELECT id FROM leave_types WHERE code = ? LIMIT 1");
            $st->execute([HR_COMP_OFF_CODE]);
            $id = (int)($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $id = 0;
        }
    }
    return $id;
}

/** Whether the comp off table and leave type are there. */
function hr_comp_off_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query("SELECT 1 FROM hr_comp_off_credits LIMIT 0");
            $ready = hr_comp_off_type_id($conn) > 0;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Today in company time, whatever the server's clock is. */
function hr_comp_off_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d');
}

/**
 * Hours from a start and end time (HH:MM). An end before the start is the next
 * morning, as everywhere else. Null when either is missing or they are equal.
 */
function hr_comp_off_hours(string $from, string $to): ?float
{
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $from, $a) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $to, $b)) {
        return null;
    }
    $mins = ((int)$b[1] * 60 + (int)$b[2]) - ((int)$a[1] * 60 + (int)$a[2]);
    if ($mins === 0) {
        return null;
    }
    if ($mins < 0) {
        $mins += 1440;
    }
    return round($mins / 60, 2);
}

/** Reasons offered when requesting comp off; Others needs its own words. */
function hr_comp_off_reasons(): array
{
    return ['holiday' => 'Worked on a holiday', 'off_day' => 'Worked on an off day', 'other' => 'Others'];
}

/**
 * The reason as stored, from the picked option and the Others text.
 *
 * @return array{0:?string, 1:?string} [reason, error]
 */
function hr_comp_off_reason_text(string $type, string $other): array
{
    $reasons = hr_comp_off_reasons();
    $type = isset($reasons[$type]) ? $type : 'holiday';
    if ($type !== 'other') {
        return [$reasons[$type], null];
    }
    $other = trim($other);
    if ($other === '') {
        return [null, 'Write the reason for Others.'];
    }
    return ['Other: ' . mb_substr($other, 0, 490), null];
}

function hr_comp_off_eligible_label(string $as): string
{
    return ['holiday' => 'Public holiday', 'weekly_off' => 'Weekly off', 'day_off' => 'Day off on schedule'][$as] ?? $as;
}

/**
 * Can $employeeId earn comp off for $date? Each rule comes back as a line the
 * popup shows with a tick or a cross.
 *
 * @return array{ok:bool, lines:list<array{ok:bool,text:string}>, eligible_as:?string, hours:?float, warning:?string}
 */
function hr_comp_off_check(PDO $conn, int $employeeId, string $date): array
{
    $out = ['ok' => false, 'lines' => [], 'eligible_as' => null, 'hours' => null, 'warning' => null];
    $fail = function (string $text) use (&$out) {
        $out['lines'][] = ['ok' => false, 'text' => $text];
        return $out;
    };
    if ($employeeId <= 0) {
        return $fail('Choose the employee.');
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        return $fail('Choose the date worked.');
    }
    $label = $d->format('D j M Y');
    if ($date > hr_comp_off_today()) {
        return $fail($label . ' is in the future. Request comp off after the day is worked.');
    }

    $st = $conn->prepare("SELECT id, company_id, location_id, date_joined, last_working_day, exit_date FROM employees WHERE id = ?");
    $st->execute([$employeeId]);
    $emp = $st->fetch(PDO::FETCH_ASSOC);
    if (!$emp) {
        return $fail('Employee not found.');
    }
    // Only days they were employed.
    $joined = substr((string)($emp['date_joined'] ?? ''), 0, 10);
    if ($joined !== '' && $joined !== '0000-00-00' && $date < $joined) {
        return $fail($label . ' is before the joining date (' . date('j M Y', strtotime($joined)) . ').');
    }
    foreach (['last_working_day', 'exit_date'] as $endCol) {
        $end = substr((string)($emp[$endCol] ?? ''), 0, 10);
        if ($end !== '' && $end !== '0000-00-00' && $date > $end) {
            return $fail($label . ' is after the last working day (' . date('j M Y', strtotime($end)) . ').');
        }
    }

    // Why the day is off: a holiday first, then the weekly off, then the schedule.
    $hol = $conn->prepare("
        SELECT name FROM holidays
        WHERE (location_id IS NULL OR location_id = ?)
          AND (holiday_date = ? OR (repeats_annually = 1 AND DATE_FORMAT(holiday_date, '%m-%d') = DATE_FORMAT(?, '%m-%d')))
        LIMIT 1
    ");
    $hol->execute([$emp['location_id'] ?? null, $date, $date]);
    $holiday = $hol->fetchColumn();
    if ($holiday !== false) {
        $out['eligible_as'] = 'holiday';
        $out['lines'][] = ['ok' => true, 'text' => 'Public holiday: ' . $holiday];
    } elseif ((int)$d->format('N') === hr_weekly_off_for_employee($conn, $employeeId, isset($emp['company_id']) ? (int)$emp['company_id'] : null, $date)) {
        $out['eligible_as'] = 'weekly_off';
        $out['lines'][] = ['ok' => true, 'text' => 'Weekly off (' . $d->format('l') . ')'];
    } else {
        $sched = hr_schedule_summary($conn, $employeeId, $date, $date);
        if (!empty($sched['has_schedule']) && empty($sched['days'][$date]['is_workday'])) {
            $out['eligible_as'] = 'day_off';
            $out['lines'][] = ['ok' => true, 'text' => 'Day off on the work schedule'];
        }
    }
    if ($out['eligible_as'] === null) {
        return $fail($label . ' is a working day. Comp off is only for holidays and off days.');
    }

    // Attendance is not required: the time worked is entered in the request.
    // When the day has a check in, it is shown and fills in the times.
    $att = $conn->prepare("SELECT check_in, check_out, hours FROM attendance WHERE employee_id = ? AND work_date = ? AND check_in IS NOT NULL LIMIT 1");
    $att->execute([$employeeId, $date]);
    $a = $att->fetch(PDO::FETCH_ASSOC);
    if ($a) {
        $out['hours'] = $a['hours'] !== null ? (float)$a['hours'] : null;
        $out['check_in'] = substr((string)$a['check_in'], 0, 5);
        $out['check_out'] = !empty($a['check_out']) ? substr((string)$a['check_out'], 0, 5) : null;
        $out['lines'][] = ['ok' => true, 'text' => 'Attendance ' . $out['check_in']
            . ($out['check_out'] !== null ? '–' . $out['check_out'] : ', no check out')
            . ($out['hours'] !== null ? ' (' . rtrim(rtrim(number_format($out['hours'], 2, '.', ''), '0'), '.') . ' h)' : '')];
    }

    // One credit per date.
    $dup = $conn->prepare("SELECT status FROM hr_comp_off_credits WHERE employee_id = ? AND work_date = ? AND status IN ('pending','approved') LIMIT 1");
    $dup->execute([$employeeId, $date]);
    $dupStatus = $dup->fetchColumn();
    if ($dupStatus !== false) {
        return $fail('Comp off for ' . $label . ' is already ' . $dupStatus . '.');
    }

    // Paid twice? Overtime for the same day is HR's call, so only a warning.
    $ot = $conn->prepare("SELECT COUNT(*) FROM overtime_entries WHERE employee_id = ? AND ot_date = ? AND status <> 'rejected'");
    $ot->execute([$employeeId, $date]);
    if ((int)$ot->fetchColumn() > 0) {
        $out['warning'] = 'Overtime is also recorded for this day. Make sure it is not paid twice.';
    }
    $out['ok'] = true;
    return $out;
}

/** Comp off credits waiting for HR, for one company or all (0). */
function hr_comp_off_pending_count(PDO $conn, int $companyId = 0): int
{
    if (!hr_comp_off_ready($conn)) {
        return 0;
    }
    $sql = "SELECT COUNT(*) FROM hr_comp_off_credits co JOIN employees e ON e.id = co.employee_id WHERE co.status = 'pending'";
    $params = [];
    if ($companyId > 0) {
        $sql .= " AND e.company_id = ?";
        $params[] = $companyId;
    }
    $st = $conn->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/**
 * "N comp off requests waiting for approval" with a Review link to the
 * pending Comp Off list, or '' when there are none.
 */
function hr_comp_off_pending_notice(PDO $conn, int $companyId = 0): string
{
    $n = hr_comp_off_pending_count($conn, $companyId);
    if ($n <= 0) {
        return '';
    }
    $href = 'leave_requests.php?status=pending&leave_type_id=' . hr_comp_off_type_id($conn)
        . ($companyId > 0 ? '&company_id=' . $companyId : '');
    return '<div class="hr-settings-card mb-3"><div class="card-body d-flex flex-wrap align-items-center gap-3">'
        . '<div style="width:44px;height:44px;border-radius:12px;background:var(--hr-primary-soft);color:#7e22ce;display:grid;place-items:center">'
        . '<i data-lucide="calendar-plus" style="width:22px;height:22px"></i></div>'
        . '<div class="flex-grow-1"><h6 class="mb-1"><strong>' . $n . '</strong> comp off request' . ($n > 1 ? 's' : '') . ' waiting for approval</h6>'
        . '<div class="small text-muted mb-0">Approve to add the days to the Comp Off leave balance</div></div>'
        . '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="btn btn-primary btn-sm">Review</a>'
        . '</div></div>';
}

/** Comp Off left over all years, less comp off leave still pending. */
function hr_comp_off_available(PDO $conn, int $employeeId, int $excludeRequestId = 0): float
{
    $typeId = hr_comp_off_type_id($conn);
    if ($typeId <= 0) {
        return 0.0;
    }
    $st = $conn->prepare("SELECT COALESCE(SUM(closing), 0) FROM leave_balances WHERE employee_id = ? AND leave_type_id = ?");
    $st->execute([$employeeId, $typeId]);
    $closing = (float)$st->fetchColumn();
    $pend = $conn->prepare("SELECT COALESCE(SUM(days), 0) FROM leave_requests
        WHERE employee_id = ? AND leave_type_id = ? AND status = 'pending' AND id <> ?");
    $pend->execute([$employeeId, $typeId, $excludeRequestId]);
    return round($closing - (float)$pend->fetchColumn(), 2);
}

/**
 * Approving comp off leave: take $days off the oldest years that still have
 * some left; anything over goes on $year, the year the leave starts.
 */
function hr_comp_off_take(PDO $conn, int $employeeId, float $days, int $year): void
{
    $typeId = hr_comp_off_type_id($conn);
    $st = $conn->prepare("SELECT year, closing FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND closing > 0 ORDER BY year");
    $st->execute([$employeeId, $typeId]);
    $upd = $conn->prepare("UPDATE leave_balances SET taken = taken + ?, closing = (opening + accrued + carried) - taken
                           WHERE employee_id = ? AND leave_type_id = ? AND year = ?");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
        if ($days <= 0) {
            break;
        }
        $use = min($days, (float)$b['closing']);
        $upd->execute([$use, $employeeId, $typeId, (int)$b['year']]);
        $days = round($days - $use, 2);
    }
    if ($days > 0) {
        hr_comp_off_balance_add($conn, $employeeId, $year, 0);
        $upd->execute([$days, $employeeId, $typeId, $year]);
    }
}

/**
 * For a leave request of any type: null when fine, else why not. Only Comp Off
 * is held to its balance; other types are left as they were.
 * $excludeRequestId leaves out the request being approved from "pending".
 */
function hr_comp_off_leave_error(PDO $conn, int $employeeId, int $leaveTypeId, string $dateFrom, float $days, int $excludeRequestId = 0): ?string
{
    if ($leaveTypeId <= 0 || $leaveTypeId !== hr_comp_off_type_id($conn)) {
        return null;
    }
    $left = hr_comp_off_available($conn, $employeeId, $excludeRequestId);
    if ($days > $left + 0.001) {
        return 'Not enough comp off: ' . rtrim(rtrim(number_format(max(0, $left), 2, '.', ''), '0'), '.')
            . ' day(s) available, ' . rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.') . ' requested.';
    }
    return null;
}

/** Add (or with a negative $days, take back) comp off on the balance for $year. */
function hr_comp_off_balance_add(PDO $conn, int $employeeId, int $year, float $days): void
{
    $typeId = hr_comp_off_type_id($conn);
    $st = $conn->prepare("SELECT id FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ? LIMIT 1");
    $st->execute([$employeeId, $typeId, $year]);
    if (!$st->fetchColumn()) {
        $conn->prepare("INSERT INTO leave_balances (employee_id, leave_type_id, year, opening, accrued, taken, carried, closing) VALUES (?, ?, ?, 0, 0, 0, 0, 0)")
             ->execute([$employeeId, $typeId, $year]);
    }
    $conn->prepare("UPDATE leave_balances SET accrued = accrued + ?, closing = (opening + accrued + carried) - taken
                    WHERE employee_id = ? AND leave_type_id = ? AND year = ?")
         ->execute([$days, $employeeId, $typeId, $year]);
}

/**
 * Approve, reject or cancel a credit. Only approval adds to the balance, and
 * only cancelling an approved credit takes it off again.
 *
 * @return array{ok:bool, message:string, row?:array}
 */
function hr_comp_off_decide(PDO $conn, int $id, string $new, ?int $userId): array
{
    if (!in_array($new, ['approved', 'rejected', 'cancelled'], true)) {
        return ['ok' => false, 'message' => 'Invalid status.'];
    }
    $conn->beginTransaction();
    try {
        $q = $conn->prepare("SELECT * FROM hr_comp_off_credits WHERE id = ? FOR UPDATE");
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Comp off request not found.');
        }
        $was = (string)$row['status'];
        $year = (int)substr((string)$row['work_date'], 0, 4);
        $days = (float)$row['days'];
        if ($new === 'approved' || $new === 'rejected') {
            if ($was !== 'pending') {
                throw new RuntimeException('This request is already ' . $was . '.');
            }
        } elseif (!in_array($was, ['pending', 'approved'], true)) {
            throw new RuntimeException('This request is already ' . $was . '.');
        }
        if ($new === 'approved') {
            hr_comp_off_balance_add($conn, (int)$row['employee_id'], $year, $days);
        } elseif ($new === 'cancelled' && $was === 'approved') {
            if (hr_comp_off_available($conn, (int)$row['employee_id']) + 0.001 < $days) {
                throw new RuntimeException('This comp off has already been used or requested as leave, so it cannot be cancelled.');
            }
            hr_comp_off_balance_add($conn, (int)$row['employee_id'], $year, -$days);
        }
        $conn->prepare("UPDATE hr_comp_off_credits SET status = ?, approver_id = ?, decided_at = NOW() WHERE id = ?")
             ->execute([$new, $userId, $id]);
        $conn->commit();
        $row['previous_status'] = $was;
        $row['status'] = $new;
        return ['ok' => true, 'message' => 'Comp off #' . $id . ' ' . $new . '.', 'row' => $row];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['ok' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Could not update the comp off request.'];
    }
}
