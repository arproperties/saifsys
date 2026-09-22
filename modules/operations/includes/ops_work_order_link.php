<?php
/**
 * Operations — orders scheduled in the old cleaning module show in the app.
 *
 * NOTHING IN THE OLD MODULE IS CHANGED FOR THIS
 * ---------------------------------------------
 * The user's rule: operation/ stays exactly as it is. So this side reads
 * make_order and order_workers when a job list loads — the office's Jobs page
 * and the field app's list, both through ops_sync_tenant_requests() — and
 * keeps its own copy. It never writes to the old tables while linking.
 *
 * WHAT
 * ----
 * One order, one job (source_type 'work_order', source_id = order id, and
 * order_id the same, so billing and the invoice link find it). A cleaning job
 * at the order's address, on its date and start time, for orders from today
 * to OPS_WO_LINK_DAYS_AHEAD days out.
 *
 * WHO — AND NEVER THE POOL
 * ------------------------
 * The first worker on the order who can sign in to the app: a worker whose
 * employee record has a user with a PIN. If nobody on the order can, there is
 * no job at all and they work from the old schedule as before. A scheduled
 * order is never put in the pool: the office already told someone to go, and
 * a second person claiming it would send two to one door.
 *
 * KEPT TRUE, ON THE NEXT REFRESH
 * ------------------------------
 * A changed date, time, address or worker moves an unstarted job; cancelling,
 * deleting, completing or invoicing the order in the old module, or moving it
 * to someone without the app, takes an unstarted job back. A started job is
 * left alone. Changes land when a list next loads, not the second the office
 * saves — the app refreshes when it opens.
 *
 * ARS checkout orders are left out: the checkout trigger puts a default worker
 * on them by itself, and nothing the system does on its own makes a job.
 *
 * MONEY
 * -----
 * None here. Finish marks the order completed and the office finalizes it at
 * the order's own price — see ops_bill_work_order() in ops_billing.php.
 *
 * Never throws: a failure is logged and the job list loads as normal.
 */

/** Order statuses that are on the schedule. Draft is not scheduled yet. */
const OPS_WO_LINK_ACTIVE_STATUSES = ['scheduled', 'confirmed', 'in_progress'];

/**
 * Orders dated before this are never linked. The day after go-live: today's
 * orders were already worked from the old schedule, and linking them now
 * would leave a day of open jobs on everyone's Late tab.
 */
const OPS_WO_LINK_FROM = '2026-09-23';

/** How far ahead scheduled orders are brought into the app. */
const OPS_WO_LINK_DAYS_AHEAD = 14;

/**
 * Link new scheduled orders and bring changed ones up to date. Two reads that
 * only return rows needing work, then one small update per such row.
 */
function ops_sync_work_orders(PDO $conn): void
{
    try {
        if (!ops_wo_link_ready($conn)) {
            return;
        }
        $today = max(date('Y-m-d'), OPS_WO_LINK_FROM);
        $until = date('Y-m-d', strtotime('+' . OPS_WO_LINK_DAYS_AHEAD . ' days'));
        $active = "'" . implode("','", OPS_WO_LINK_ACTIVE_STATUSES) . "'";
        $appUser = ops_wo_link_app_user_sql('o.id');

        // New: scheduled, someone on it can use the app, no job yet.
        $new = $conn->prepare("
            SELECT o.id
            FROM make_order o
            LEFT JOIN ops_jobs j ON j.source_type = 'work_order' AND j.source_id = o.id
            WHERE j.id IS NULL
              AND o.status IN ($active)
              AND o.invoice_id IS NULL
              AND o.ars_booking_id IS NULL
              AND COALESCE(o.service_date, o.date) BETWEEN ? AND ?
              AND ($appUser) IS NOT NULL
            ORDER BY o.id ASC
            LIMIT 100
        ");
        $new->execute([$today, $until]);
        $ids = $new->fetchAll(PDO::FETCH_COLUMN) ?: [];

        // Changed: an unstarted job whose order moved, changed hands, or went.
        $changed = $conn->query("
            SELECT j.source_id
            FROM ops_jobs j
            LEFT JOIN make_order o ON o.id = j.source_id
            WHERE j.source_type = 'work_order'
              AND j.started_at IS NULL
              AND j.status IN ('open', 'cancelled')
              AND (
                    (j.status = 'open' AND (
                          o.id IS NULL
                       OR o.status NOT IN ($active)
                       OR o.invoice_id IS NOT NULL
                       OR COALESCE(o.service_date, o.date) <> j.scheduled_date
                       OR NOT (NULLIF(o.start_time, '00:00:00') <=> j.scheduled_time)
                       OR NOT (($appUser) <=> j.assigned_to)
                       OR j.location <> LEFT(COALESCE(NULLIF(TRIM(o.address_o), ''), NULLIF(TRIM(o.client_name), ''), 'Address not given'), 255)
                    ))
                 OR (j.status = 'cancelled' AND o.status IN ($active) AND o.invoice_id IS NULL
                     AND COALESCE(o.service_date, o.date) >= '$today'
                     AND ($appUser) IS NOT NULL)
              )
            LIMIT 100
        ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

        foreach (array_unique(array_merge($ids, $changed)) as $orderId) {
            ops_link_work_order($conn, (int)$orderId);
        }
    } catch (Throwable $e) {
        error_log('ops_sync_work_orders failed: ' . $e->getMessage());
    }
}

/** Create, update or take back the job for one order. */
function ops_link_work_order(PDO $conn, int $orderId): void
{
    if ($orderId <= 0) {
        return;
    }
    try {
        $stmt = $conn->prepare("SELECT * FROM make_order WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmt = $conn->prepare("SELECT * FROM ops_jobs WHERE source_type = 'work_order' AND source_id = ?");
        $stmt->execute([$orderId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $workers = $order ? ops_wo_link_workers($conn, $orderId) : [];
        $assignee = ops_wo_link_assignee($workers);
        $active = $order
            && in_array(strtolower((string)$order['status']), OPS_WO_LINK_ACTIVE_STATUSES, true)
            && empty($order['invoice_id'])
            && empty($order['ars_booking_id'])
            && $assignee !== null;

        $now = date('Y-m-d H:i:s');

        if (!$job) {
            if (!$active || ops_wo_link_date($order) < max(date('Y-m-d'), OPS_WO_LINK_FROM)) {
                return;
            }
            // Already a job for this order another way — an ARS checkout job
            // linked to it, or the customer booking it was made from.
            $stmt = $conn->prepare("
                SELECT j.id FROM ops_jobs j
                WHERE j.order_id = ?
                   OR (j.source_type = 'customer_booking' AND j.status <> 'cancelled'
                       AND j.source_id IN (SELECT ob.id FROM online_bookings ob WHERE ob.work_order_id = ?))
                LIMIT 1
            ");
            $stmt->execute([$orderId, $orderId]);
            if ($stmt->fetchColumn()) {
                return;
            }

            $fields = ops_wo_link_fields($order, $workers);
            $conn->prepare("
                INSERT IGNORE INTO ops_jobs
                    (company_id, job_type, title, location, description, assigned_to,
                     scheduled_date, scheduled_time, priority, status, created_by,
                     source_type, source_id, order_id, created_at, updated_at)
                VALUES (?, 'cleaning', ?, ?, ?, ?, ?, ?, 'normal', 'open', NULL, 'work_order', ?, ?, ?, ?)
            ")->execute([
                (int)$order['company_id'], $fields['title'], $fields['location'], $fields['description'],
                $assignee, $fields['scheduled_date'], $fields['scheduled_time'],
                $orderId, $orderId, $now, $now,
            ]);
            return;
        }

        // Under way or finished: the person on site owns it now.
        if ($job['status'] === 'done' || $job['status'] === 'in_progress' || !empty($job['started_at'])) {
            return;
        }

        if (!$active) {
            if ($job['status'] !== 'cancelled') {
                $conn->prepare("UPDATE ops_jobs SET status = 'cancelled', updated_at = ? WHERE id = ? AND started_at IS NULL")
                     ->execute([$now, (int)$job['id']]);
            }
            return;
        }

        $fields = ops_wo_link_fields($order, $workers);
        $conn->prepare("
            UPDATE ops_jobs
            SET company_id = ?, title = ?, location = ?, description = ?, assigned_to = ?,
                scheduled_date = ?, scheduled_time = ?, status = 'open', updated_at = ?
            WHERE id = ? AND started_at IS NULL AND status IN ('open', 'cancelled')
        ")->execute([
            (int)$order['company_id'], $fields['title'], $fields['location'], $fields['description'], $assignee,
            $fields['scheduled_date'], $fields['scheduled_time'], $now, (int)$job['id'],
        ]);
    } catch (Throwable $e) {
        error_log('ops_link_work_order #' . $orderId . ' failed: ' . $e->getMessage());
    }
}

/** Whether ops_jobs.source_type has 'work_order' yet — migrations/ops_work_order_jobs.sql. */
function ops_wo_link_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        $row = $conn->query("SHOW COLUMNS FROM ops_jobs LIKE 'source_type'")->fetch(PDO::FETCH_ASSOC);
        $ready = strpos((string)($row['Type'] ?? ''), "'work_order'") !== false;
    }
    return $ready;
}

/**
 * SQL: the app user of the first worker on this order who can sign in, or
 * NULL. The same rule as ops_wo_link_assignee(), for the change check.
 */
function ops_wo_link_app_user_sql(string $orderIdSql): string
{
    return "SELECT e.user_id
              FROM order_workers ow
              JOIN workers w ON w.id = ow.worker_id AND w.emp_num <> ''
              JOIN employees e ON e.employee_code = w.emp_num AND e.user_id IS NOT NULL
              JOIN ops_staff_pins p ON p.user_id = e.user_id
             WHERE ow.order_id = {$orderIdSql}
             ORDER BY ow.id ASC, e.id ASC
             LIMIT 1";
}

function ops_wo_link_date(array $order): string
{
    return (string)($order['service_date'] ?: $order['date']);
}

/**
 * The order's workers in the order they were added, each with the app user
 * behind them (null when they cannot sign in to the app).
 *
 * @return array<int, array{worker_id:int, name:string, user_id:?int}>
 */
function ops_wo_link_workers(PDO $conn, int $orderId): array
{
    $stmt = $conn->prepare("
        SELECT w.id AS worker_id, COALESCE(NULLIF(w.nickname, ''), w.worker_name) AS name,
               (SELECT e.user_id
                  FROM employees e
                  JOIN ops_staff_pins p ON p.user_id = e.user_id
                 WHERE e.employee_code = w.emp_num AND w.emp_num <> '' AND e.user_id IS NOT NULL
                 ORDER BY e.id ASC
                 LIMIT 1) AS user_id
        FROM order_workers ow
        JOIN workers w ON w.id = ow.worker_id
        WHERE ow.order_id = ?
        ORDER BY ow.id ASC
    ");
    $stmt->execute([$orderId]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $rows[] = [
            'worker_id' => (int)$r['worker_id'],
            'name' => (string)$r['name'],
            'user_id' => $r['user_id'] !== null ? (int)$r['user_id'] : null,
        ];
    }
    return $rows;
}

/** The first worker who can use the app, or null — then there is no job. */
function ops_wo_link_assignee(array $workers): ?int
{
    foreach ($workers as $w) {
        if ($w['user_id']) {
            return $w['user_id'];
        }
    }
    return null;
}

/** Title, place, notes and time for the job, from the order. */
function ops_wo_link_fields(array $order, array $workers): array
{
    $client = trim((string)$order['client_name']);
    $address = trim((string)$order['address_o']);

    $hours = rtrim(rtrim(number_format((float)$order['hours'], 2, '.', ''), '0'), '.');
    $head = ['Work order #' . (int)$order['id']];
    if ((float)$order['hours'] > 0) {
        $head[] = $hours . ($hours === '1' ? ' hour' : ' hours');
    }
    $names = array_values(array_filter(array_map(static fn(array $w): string => $w['name'], $workers)));
    if ($names) {
        $head[] = 'Scheduled: ' . implode(', ', $names);
    }
    $lines = [implode(' · ', $head)];
    foreach (['remark', 'notes'] as $field) {
        $text = trim((string)($order[$field] ?? ''));
        if ($text !== '') {
            $lines[] = $text;
        }
    }

    $time = trim((string)($order['start_time'] ?? ''));

    return [
        'title' => mb_substr('Cleaning — ' . ($client ?: 'Work order #' . (int)$order['id']), 0, 255),
        'location' => mb_substr($address ?: ($client ?: 'Address not given'), 0, 255),
        'description' => implode("\n", $lines),
        'scheduled_date' => ops_wo_link_date($order),
        'scheduled_time' => $time !== '' && $time !== '00:00:00' ? $time : null,
    ];
}
