<?php
/**
 * Read-only attendance checks (added 2026-11-12, after the Glaiza E00029 review).
 *
 *  - hr_att_check_alerts():     absence streaks (UAE law: absconding after more than
 *                               7 days in a row) and absences in the last 12 months
 *                               (dismissal possible after more than 20 in a year).
 *  - hr_att_check_mismatches(): attendance against the cleaning job schedule.
 *  - hr_att_check_after_exit(): attendance dated after the last working day.
 *
 * Nothing here writes to the database.
 */

require_once __DIR__ . '/hr_employee_lifecycle.php';
require_once __DIR__ . '/hr_attendance_lock.php';

const HR_ATT_STREAK_ALERT = 5;   // days in a row shown on the list
const HR_ATT_STREAK_LAW   = 7;   // law: more than 7 days in a row
const HR_ATT_YEAR_ALERT   = 15;  // absent days in 12 months shown on the list
const HR_ATT_YEAR_LAW     = 20;  // law: more than 20 days in a year

/**
 * Current staff with a run of absent days, or many absent days in the last 12 months.
 *
 * A run: absent days one after another. A day with no record or a weekly / day off
 * in between (a Sunday, say) does not break it, as long as the gap is at most
 * 2 days. Any other record (present, leave, half day…) ends the run. Half day = 0.5
 * in the 12-month count and ends a run.
 *
 * @return array{streak: list<array>, year: list<array>}
 */
function hr_att_check_alerts(PDO $conn, int $companyId = 0): array
{
    $today = hr_attendance_today();
    $yearFrom = (new DateTimeImmutable($today))->modify('-365 days')->format('Y-m-d');
    $streakFrom = (new DateTimeImmutable($today))->modify('-60 days')->format('Y-m-d');

    $cur = hr_employee_current_statuses();
    $sql = "SELECT a.employee_id, a.work_date, a.status, e.full_name, e.employee_code, e.company_id
              FROM attendance a
              JOIN employees e ON e.id = a.employee_id
             WHERE a.work_date BETWEEN ? AND ?
               AND e.status IN (" . hr_employee_status_in_sql($cur) . ")";
    $params = array_merge([$yearFrom, $today], $cur);
    if ($companyId > 0) {
        $sql .= " AND e.company_id = ?";
        $params[] = $companyId;
    }
    $sql .= " ORDER BY a.employee_id, a.work_date";
    $st = $conn->prepare($sql);
    $st->execute($params);

    $byEmp = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int)$r['employee_id'];
        $byEmp[$id]['name'] = $r['full_name'];
        $byEmp[$id]['code'] = $r['employee_code'];
        $byEmp[$id]['rows'][] = [$r['work_date'], $r['status']];
    }

    $streaks = [];
    $years = [];
    foreach ($byEmp as $id => $emp) {
        $yearCount = 0.0;
        $best = ['n' => 0, 'from' => null, 'to' => null];
        $run = ['n' => 0, 'from' => null, 'to' => null];
        foreach ($emp['rows'] as [$d, $s]) {
            if ($s === 'absent') {
                $yearCount += 1;
            } elseif ($s === 'half') {
                $yearCount += 0.5;
            }
            if ($d < $streakFrom) {
                continue;
            }
            if (in_array($s, ['weekly_off', 'day_off'], true)) {
                continue; // neutral: neither breaks nor adds
            }
            if ($s === 'absent') {
                $gap = $run['to'] ? (int)round((strtotime($d) - strtotime($run['to'])) / 86400) : 0;
                if ($run['n'] > 0 && $gap <= 2) {
                    $run['n']++;
                    $run['to'] = $d;
                } else {
                    $run = ['n' => 1, 'from' => $d, 'to' => $d];
                }
                if ($run['n'] > $best['n']) {
                    $best = $run;
                }
            } else {
                $run = ['n' => 0, 'from' => null, 'to' => null];
            }
        }
        if ($best['n'] >= HR_ATT_STREAK_ALERT) {
            $streaks[] = ['employee_id' => $id, 'name' => $emp['name'], 'code' => $emp['code']] + $best;
        }
        if ($yearCount >= HR_ATT_YEAR_ALERT) {
            $years[] = ['employee_id' => $id, 'name' => $emp['name'], 'code' => $emp['code'], 'days' => $yearCount, 'from' => $yearFrom, 'to' => $today];
        }
    }
    usort($streaks, fn($a, $b) => $b['n'] <=> $a['n']);
    usort($years, fn($a, $b) => $b['days'] <=> $a['days']);
    return ['streak' => $streaks, 'year' => $years];
}

/**
 * Days where attendance and the cleaning job schedule disagree.
 *  - absent_but_worked: attendance Absent, her/his job that day completed or billed.
 *  - present_but_absent: attendance Present, but the job was cancelled as absent / sick,
 *    or the supervisor marked the person absent in Worker availability — and no other
 *    job of theirs that day was completed or billed.
 *
 * @return array{absent_but_worked: list<array>, present_but_absent: list<array>}
 */
function hr_att_check_mismatches(PDO $conn, string $from, string $to, int $companyId = 0): array
{
    $co = $companyId > 0 ? " AND e.company_id = " . (int)$companyId : '';

    $a = $conn->prepare("
        SELECT a.id AS att_id, a.employee_id, e.full_name, e.employee_code, a.work_date,
               m.id AS order_id, m.client_name, m.status AS job_status
          FROM attendance a
          JOIN employees e ON e.id = a.employee_id
          JOIN v_worker_employee_map wm ON wm.employee_id = a.employee_id
          JOIN order_workers ow ON ow.worker_id = wm.worker_id
          JOIN make_order m ON m.id = ow.order_id AND m.service_date = a.work_date
         WHERE a.status = 'absent'
           AND m.status IN ('completed', 'invoiced')
           AND a.work_date BETWEEN ? AND ? $co
         ORDER BY a.work_date DESC, e.full_name");
    $a->execute([$from, $to]);

    $b = $conn->prepare("
        SELECT a.id AS att_id, a.employee_id, e.full_name, e.employee_code, a.work_date,
               m.id AS order_id, m.client_name,
               COALESCE(NULLIF(m.cancellation_details, ''), m.cancel_reason) AS reason, 'Job cancelled' AS source
          FROM attendance a
          JOIN employees e ON e.id = a.employee_id
          JOIN v_worker_employee_map wm ON wm.employee_id = a.employee_id
          JOIN order_workers ow ON ow.worker_id = wm.worker_id
          JOIN make_order m ON m.id = ow.order_id AND m.service_date = a.work_date
         WHERE a.status = 'approved'
           AND m.status = 'cancelled'
           AND CONCAT_WS(' ', m.cancel_reason, m.cancellation_details) REGEXP 'absent|sick|did not come|didn.t come'
           AND a.work_date BETWEEN ? AND ? $co
           AND NOT EXISTS (SELECT 1 FROM order_workers ow2 JOIN make_order m2 ON m2.id = ow2.order_id
                            WHERE ow2.worker_id = wm.worker_id AND m2.service_date = a.work_date AND m2.status IN ('completed', 'invoiced'))
        UNION ALL
        SELECT a.id, a.employee_id, e.full_name, e.employee_code, a.work_date,
               NULL, NULL, wu.reason, 'Supervisor note'
          FROM attendance a
          JOIN employees e ON e.id = a.employee_id
          JOIN v_worker_employee_map wm ON wm.employee_id = a.employee_id
          JOIN worker_unavailability wu ON wu.worker_id = wm.worker_id AND wu.date = a.work_date
         WHERE a.status = 'approved'
           AND (wu.absence_type = 'absent' OR wu.reason REGEXP 'absent|sick|did not come|didn.t come')
           AND wu.reason NOT REGEXP 'day off|dayoff|off day|holiday|leave|vacation'
           AND a.work_date BETWEEN ? AND ? $co
           AND NOT EXISTS (SELECT 1 FROM order_workers ow2 JOIN make_order m2 ON m2.id = ow2.order_id
                            WHERE ow2.worker_id = wm.worker_id AND m2.service_date = a.work_date AND m2.status IN ('completed', 'invoiced'))
         ORDER BY work_date DESC, full_name");
    $b->execute([$from, $to, $from, $to]);

    return [
        'absent_but_worked' => $a->fetchAll(PDO::FETCH_ASSOC),
        'present_but_absent' => $b->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/**
 * Staff who have LEFT and still have attendance dated after their last working
 * day (one line each). For review only: an Owner deletes wrong rows from the
 * Attendance list.
 */
function hr_att_check_after_exit(PDO $conn, int $companyId = 0): array
{
    $sql = "SELECT e.id AS employee_id, e.full_name, e.employee_code, e.status AS emp_status,
                   COALESCE(e.last_working_day, e.exit_date) AS last_day,
                   COUNT(*) AS rows_after, SUM(a.status = 'approved') AS present_after,
                   MIN(a.work_date) AS first_after, MAX(a.work_date) AS last_after
              FROM attendance a
              JOIN employees e ON e.id = a.employee_id
             WHERE COALESCE(e.last_working_day, e.exit_date) >= '2000-01-01'
               AND e.status NOT IN (" . hr_employee_status_in_sql(hr_employee_current_statuses()) . ")
               AND a.work_date > COALESCE(e.last_working_day, e.exit_date)";
    $params = hr_employee_current_statuses();
    if ($companyId > 0) {
        $sql .= " AND e.company_id = ?";
        $params[] = $companyId;
    }
    $sql .= " GROUP BY e.id, e.full_name, e.employee_code, e.status, last_day ORDER BY rows_after DESC, e.full_name";
    $st = $conn->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Current staff (active / on leave / notice) whose profile already shows a last
 * working day or exit date in the past, or a junk date. These are profile mistakes:
 * fix the profile (clear the date, or mark the person as left).
 */
function hr_att_check_stale_exit(PDO $conn, int $companyId = 0): array
{
    $cur = hr_employee_current_statuses();
    $sql = "SELECT e.id AS employee_id, e.full_name, e.employee_code, e.status AS emp_status,
                   e.last_working_day, e.exit_date
              FROM employees e
             WHERE e.status IN (" . hr_employee_status_in_sql($cur) . ")
               AND COALESCE(e.last_working_day, e.exit_date) IS NOT NULL
               AND COALESCE(e.last_working_day, e.exit_date) < ?";
    $params = array_merge($cur, [hr_attendance_today()]);
    if ($companyId > 0) {
        $sql .= " AND e.company_id = ?";
        $params[] = $companyId;
    }
    $sql .= " ORDER BY COALESCE(e.last_working_day, e.exit_date), e.full_name";
    $st = $conn->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
