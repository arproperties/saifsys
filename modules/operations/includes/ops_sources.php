<?php
/**
 * Operations — jobs that start as a tenant's request.
 *
 * A job begins one of two ways. Staff raise their own on the phone, or somebody
 * asks for something somewhere else in the system:
 *
 *   - a maintenance request, from the tenant portal, the tenant app, the Real
 *     Estate or ARS pages, into re_maintenance_requests;
 *   - a tenant cleaning booking, from the tenant portal or app, into
 *     tenant_cleaning_requests — straight away, no office approval;
 *   - an ARS guest checkout, and a tenant move-out;
 *   - a cleaning booking from the customer app, into online_bookings.
 *
 * None of these waits for the office. Nobody approves, schedules or assigns:
 * the job lands in the pool and the person who takes it is the dispatch.
 *
 * NOTHING IN THE OLD MODULE WAS CHANGED FOR THIS
 * ----------------------------------------------
 * The tenant portal, the tenant API and the Real Estate pages carry on exactly
 * as they were. Instead of hooking every place a request is written, this side
 * reads those tables and makes its own copy on every job list load, from the
 * office's list and the field app's. The unique key on
 * (source_type, source_id) is what makes running it on every page load safe:
 * one request becomes one job, ever. See migrations/ops_job_sources.sql.
 *
 * THE POOL
 * --------
 * A copied request is a job with nobody on it. It sits in the Requests tab of
 * every field phone until one person taps "I'll do it", and from then on it is
 * an ordinary job on their list — Before photo, Start, After photo, Finish.
 * Nobody in the office decides who goes.
 *
 * WHAT GOES BACK
 * --------------
 * The request is still the tenant's record and the office's page, so it is
 * kept true with the same fields those pages write themselves: in progress
 * with a name on it when claimed, completed when finished, and the tenant told
 * through the notification helper the Real Estate pages already use.
 */

/**
 * Nothing raised before this date is copied.
 *
 * The day this went live. Requests older than that were already the office's
 * to handle in the old module, and nine of them sit there pending from August
 * — some fixed and never closed, some not. Pouring them into the phones on the
 * first morning would bury the new ones and send people to doors that were
 * dealt with weeks ago. Move this date back to bring the backlog into the pool.
 */
const OPS_TENANT_SYNC_FROM = '2026-09-16';

/**
 * Checkouts and move-outs before this date are not copied.
 *
 * The day this went live. There are close to a hundred ARS bookings already
 * checked out; their units were cleaned, or not, weeks ago.
 */
const OPS_CHECKOUT_SYNC_FROM = '2026-09-17';

/**
 * The company a customer-app booking's work order and job belong to.
 *
 * online_bookings has no company column. The office converted them under
 * whichever company it was switched to, and that was always the cleaning
 * company — the same one ARS checkouts bill through.
 */
const OPS_CUSTOMER_BOOKING_COMPANY_ID = 1;

/**
 * Whether requests raised elsewhere are copied into the pool at all.
 *
 * Off. A job is made by the person who wants it done — staff on the ops page
 * or in the field app, a cleaner reporting what they found on a checklist —
 * and by nobody else. Copying maintenance requests, checkouts, move-outs and
 * bookings in filled the phones with work the office had never looked at.
 *
 * Everything below still works; turn this back to true to have it run again.
 */
const OPS_SYNC_SOURCES = false;

// ---------------------------------------------------------------------------
// Copy requests in
// ---------------------------------------------------------------------------

/**
 * Bring new tenant requests into the pool, and withdraw ones that went away.
 *
 * Cheap enough to call on every list load: two reads that only return rows not
 * yet copied, and one update that only touches unclaimed pool jobs. Failures
 * are logged and swallowed — a request that did not copy this time copies next
 * time, and a job list that errors because of it would help nobody.
 *
 * @param int[] $companyIds
 */
function ops_sync_tenant_requests(PDO $conn, array $companyIds): void
{
    if (!OPS_SYNC_SOURCES) {
        return;
    }

    $companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds))));
    if (!$companyIds) {
        return;
    }

    try {
        ops_fix_copied_maintenance($conn, $companyIds);
        ops_sync_tenant_maintenance($conn, $companyIds);
        ops_sync_tenant_cleaning($conn, $companyIds);
        ops_sync_ars_checkouts($conn, $companyIds);
        ops_sync_tenant_move_outs($conn, $companyIds);
        ops_sync_customer_bookings($conn, $companyIds);
        ops_withdraw_tenant_requests($conn, $companyIds);
        ops_withdraw_checkouts($conn, $companyIds);
        ops_withdraw_customer_bookings($conn, $companyIds);
        ops_finalize_old_module_checkouts($conn, $companyIds);
        ops_escalate_unclaimed($conn, $companyIds);
    } catch (Throwable $e) {
        // Most often a database without migrations/ops_job_sources.sql yet.
        error_log('ops_sync_tenant_requests failed: ' . $e->getMessage());
    }
}

/**
 * Maintenance requests nobody has picked up — whoever raised them.
 *
 * A tenant from the portal or app, the office on the Real Estate page, ARS for
 * a guest unit: all the same work, so all go to the pool. Only pending and
 * unassigned — a request that already has somebody on it in the old module
 * (a preventive maintenance task with its technician, say) is theirs, and
 * copying it would send a second person.
 */
function ops_sync_tenant_maintenance(PDO $conn, array $companyIds): void
{
    $in = implode(',', array_fill(0, count($companyIds), '?'));
    $stmt = $conn->prepare("
        SELECT m.id, m.company_id, m.location_type, m.unit_id, m.common_area_id,
               m.category, m.description, m.priority, m.created_at
        FROM re_maintenance_requests m
        LEFT JOIN ops_jobs j
               ON j.source_type = 'tenant_maintenance' AND j.source_id = m.id
        WHERE m.company_id IN ($in)
          -- In progress with nobody assigned is how the Real Estate page saves
          -- a request the office typed: still nobody's, so still the pool's.
          AND m.status IN ('pending', 'in_progress')
          AND m.assigned_to IS NULL
          AND m.request_date >= ?
          AND j.id IS NULL
        ORDER BY m.id ASC
        LIMIT 100
    ");
    $stmt->execute(array_merge($companyIds, [OPS_TENANT_SYNC_FROM]));

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $request) {
        $place = $request['location_type'] === 'common_area'
            ? ops_source_place($conn, 'common_area', (int)$request['common_area_id'])
            : ops_source_place($conn, 'unit', (int)$request['unit_id']);

        // A request pointing at a unit that no longer exists cannot become a
        // job, because every job must name a real place. It stays in the old
        // module, where the office will see it.
        if (!$place) {
            continue;
        }

        $title = ops_source_category_title((string)$request['category']);

        ops_insert_source_job($conn, [
            'company_id' => (int)$request['company_id'],
            // A tenant who picked Cleaning asked for a clean: a cleaning job,
            // with the unit checklist. Everything else is maintenance.
            'job_type' => ops_source_job_type((string)$request['category']),
            'title' => $title,
            'description' => trim((string)$request['description']) ?: null,
            'priority' => ops_source_priority((string)$request['priority']),
            // Today, not the request date: in the pool it is work waiting now.
            // Claiming moves it to the day it is claimed — see ops_source_on_claim().
            'scheduled_date' => date('Y-m-d'),
            'scheduled_time' => null,
            'source_type' => 'tenant_maintenance',
            'source_id' => (int)$request['id'],
        ], [$place]);
    }
}

/**
 * Put right maintenance jobs copied before the category rules above: a
 * Cleaning request copied as maintenance, a title with an underscore in it.
 * Only jobs nobody has started, so nothing under anybody's hands changes.
 */
function ops_fix_copied_maintenance(PDO $conn, array $companyIds): void
{
    $in = implode(',', array_fill(0, count($companyIds), '?'));
    $stmt = $conn->prepare("
        SELECT j.id, j.job_type, j.title, m.category
        FROM ops_jobs j
        JOIN re_maintenance_requests m ON m.id = j.source_id
        WHERE j.source_type = 'tenant_maintenance'
          AND j.status = 'open'
          AND j.started_at IS NULL
          AND j.company_id IN ($in)
          AND (LOWER(m.category) = 'cleaning' OR j.title LIKE '%\_%')
    ");
    $stmt->execute($companyIds);
    $update = $conn->prepare("UPDATE ops_jobs SET job_type = ?, title = ?, updated_at = ? WHERE id = ?");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $type = ops_source_job_type((string)$row['category']);
        $title = ops_source_category_title((string)$row['category']);
        if ($type !== $row['job_type'] || $title !== $row['title']) {
            $update->execute([$type, $title, date('Y-m-d H:i:s'), (int)$row['id']]);
        }
    }
}

/**
 * Tenant cleaning bookings, the moment the tenant makes them.
 *
 * No office approval first: a pending booking goes to the pool, and taking it
 * is what approves it — see ops_source_on_claim(). The office can still reject
 * one on modules/realestate/cleaning_requests.php, and a rejected booking
 * leaves the pool. A booking with no date asked for is today's. Only bookings
 * for today or later: one whose day has gone is not a job anybody can still do.
 * Pending bookings raised before the go-live date are the old backlog and stay
 * where they are.
 */
function ops_sync_tenant_cleaning(PDO $conn, array $companyIds): void
{
    $in = implode(',', array_fill(0, count($companyIds), '?'));
    $stmt = $conn->prepare("
        SELECT c.id, c.company_id, c.service_date, c.service_time,
               c.num_cleaners, c.num_hours, c.has_materials, c.admin_notes,
               l.unit_id
        FROM tenant_cleaning_requests c
        LEFT JOIN re_leases l ON l.id = c.lease_id
        LEFT JOIN ops_jobs j
               ON j.source_type = 'tenant_cleaning' AND j.source_id = c.id
        WHERE c.company_id IN ($in)
          AND j.id IS NULL
          AND (
                (c.status = 'approved' AND c.service_date >= ?)
             OR (c.status = 'pending' AND c.created_at >= ?
                 AND (c.service_date IS NULL OR c.service_date >= ?))
          )
        ORDER BY c.id ASC
        LIMIT 100
    ");
    $today = date('Y-m-d');
    $stmt->execute(array_merge($companyIds, [
        max($today, OPS_TENANT_SYNC_FROM), OPS_TENANT_SYNC_FROM, $today,
    ]));

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $booking) {
        $place = ops_source_place($conn, 'unit', (int)$booking['unit_id']);
        if (!$place) {
            continue;
        }

        // What the booking actually bought, in one line a cleaner can read at
        // the door: how many people, how long, whether to bring materials.
        $cleaners = max(1, (int)$booking['num_cleaners']);
        $hours = rtrim(rtrim(number_format((float)$booking['num_hours'], 2, '.', ''), '0'), '.');
        $parts = [
            $cleaners . ($cleaners === 1 ? ' cleaner' : ' cleaners'),
            $hours . ($hours === '1' ? ' hour' : ' hours'),
            (int)$booking['has_materials'] === 1 ? 'bring materials' : 'no materials',
        ];
        $description = implode(' · ', $parts);
        if (trim((string)$booking['admin_notes']) !== '') {
            $description .= "\n" . trim((string)$booking['admin_notes']);
        }

        $time = trim((string)$booking['service_time']);

        ops_insert_source_job($conn, [
            'company_id' => (int)$booking['company_id'],
            'job_type' => 'cleaning',
            'title' => 'Cleaning booking',
            'description' => $description,
            'priority' => 'normal',
            'scheduled_date' => (string)($booking['service_date'] ?: $today),
            'scheduled_time' => $time !== '' ? $time : null,
            'source_type' => 'tenant_cleaning',
            'source_id' => (int)$booking['id'],
        ], [$place]);
    }
}

/**
 * A cleaning job for every ARS guest who has checked out.
 *
 * Pressing Check out on the booking is what sets status 'checked_out' and
 * actual_check_out, so reading the table catches it whichever page or script
 * did it. The job is dated the day the guest actually left. Yesterday's is
 * still copied: a guest who checks out late at night is only picked up when
 * somebody opens a job list the next morning, and the unit still needs doing.
 *
 * ARS checkout still makes its old make_order work order as well. The two are
 * the same clean: finishing this job completes that order, and the invoice
 * comes from that order only — see ops_bill_ars_checkout().
 */
function ops_sync_ars_checkouts(PDO $conn, array $companyIds): void
{
    $in = implode(',', array_fill(0, count($companyIds), '?'));
    $from = max(date('Y-m-d', strtotime('-1 day')), OPS_CHECKOUT_SYNC_FROM);
    $stmt = $conn->prepare("
        SELECT b.id, b.company_id, b.unit_id, b.booking_number, b.check_out,
               COALESCE(b.actual_check_out, b.check_out) AS left_on
        FROM ars_bookings b
        LEFT JOIN ops_jobs j
               ON j.source_type = 'ars_checkout' AND j.source_id = b.id
        WHERE b.company_id IN ($in)
          AND b.status IN ('checked_out', 'completed')
          AND COALESCE(b.actual_check_out, b.check_out) >= ?
          AND j.id IS NULL
        ORDER BY b.id ASC
        LIMIT 100
    ");
    $stmt->execute(array_merge($companyIds, [$from]));

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $booking) {
        $place = ops_source_place($conn, 'unit', (int)$booking['unit_id']);
        if (!$place) {
            continue;
        }

        $description = 'Guest checked out — booking ' . $booking['booking_number'] . '.';
        if ((string)$booking['left_on'] < (string)$booking['check_out']) {
            $description .= ' Early checkout, was due ' . date('j M', strtotime((string)$booking['check_out'])) . '.';
        }

        ops_insert_source_job($conn, [
            'company_id' => (int)$booking['company_id'],
            'job_type' => 'cleaning',
            'title' => 'Checkout cleaning',
            'description' => $description,
            'priority' => 'normal',
            'scheduled_date' => (string)$booking['left_on'],
            'scheduled_time' => null,
            'source_type' => 'ars_checkout',
            'source_id' => (int)$booking['id'],
        ], [$place]);
    }
}

/**
 * A cleaning job for every tenant move-out, on the move-out date.
 *
 * Copied as soon as the move-out is recorded, so it can sit in Upcoming until
 * the day. Waiting for the move-out to be marked Completed would be too late:
 * that only happens once the deposit is settled, often weeks after the keys
 * come back.
 */
function ops_sync_tenant_move_outs(PDO $conn, array $companyIds): void
{
    $in = implode(',', array_fill(0, count($companyIds), '?'));
    $from = max(date('Y-m-d', strtotime('-1 day')), OPS_CHECKOUT_SYNC_FROM);
    $stmt = $conn->prepare("
        SELECT m.id, m.company_id, m.actual_move_out_date, l.unit_id
        FROM re_move_outs m
        JOIN re_leases l ON l.id = m.lease_id
        LEFT JOIN ops_jobs j
               ON j.source_type = 'tenant_move_out' AND j.source_id = m.id
        WHERE m.company_id IN ($in)
          AND m.status <> 'cancelled'
          AND m.actual_move_out_date >= ?
          AND j.id IS NULL
        ORDER BY m.id ASC
        LIMIT 100
    ");
    $stmt->execute(array_merge($companyIds, [$from]));

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $moveOut) {
        $place = ops_source_place($conn, 'unit', (int)$moveOut['unit_id']);
        if (!$place) {
            continue;
        }

        ops_insert_source_job($conn, [
            'company_id' => (int)$moveOut['company_id'],
            'job_type' => 'cleaning',
            'title' => 'Move-out cleaning',
            'description' => 'Tenant moving out — move-out #' . (int)$moveOut['id'] . '.',
            'priority' => 'normal',
            'scheduled_date' => (string)$moveOut['actual_move_out_date'],
            'scheduled_time' => null,
            'source_type' => 'tenant_move_out',
            'source_id' => (int)$moveOut['id'],
        ], [$place]);
    }
}

/**
 * Keep unclaimed checkout jobs true to their booking or move-out.
 *
 * Same rule as ops_withdraw_tenant_requests(): only jobs nobody has claimed.
 * A move-out that is cancelled takes its job back, one whose date moves takes
 * the job with it, and a checkout undone on the booking cancels its job.
 */
function ops_withdraw_checkouts(PDO $conn, array $companyIds): void
{
    $in = implode(',', array_fill(0, count($companyIds), '?'));
    $now = date('Y-m-d H:i:s');

    $conn->prepare("
        UPDATE ops_jobs j
        JOIN re_move_outs m ON m.id = j.source_id
        SET j.status = 'cancelled', j.updated_at = ?
        WHERE j.source_type = 'tenant_move_out'
          AND j.assigned_to IS NULL
          AND j.status = 'open'
          AND j.company_id IN ($in)
          AND m.status = 'cancelled'
    ")->execute(array_merge([$now], $companyIds));

    $conn->prepare("
        UPDATE ops_jobs j
        JOIN re_move_outs m ON m.id = j.source_id
        SET j.scheduled_date = m.actual_move_out_date, j.updated_at = ?
        WHERE j.source_type = 'tenant_move_out'
          AND j.assigned_to IS NULL
          AND j.status = 'open'
          AND j.company_id IN ($in)
          AND j.scheduled_date <> m.actual_move_out_date
    ")->execute(array_merge([$now], $companyIds));

    $conn->prepare("
        UPDATE ops_jobs j
        JOIN ars_bookings b ON b.id = j.source_id
        SET j.status = 'cancelled', j.updated_at = ?
        WHERE j.source_type = 'ars_checkout'
          AND j.assigned_to IS NULL
          AND j.status = 'open'
          AND j.company_id IN ($in)
          AND b.status NOT IN ('checked_out', 'completed')
    ")->execute(array_merge([$now], $companyIds));

    // Already cleaned through the old module: its work order is completed or
    // invoiced. A worker put on the order there does not take the job away —
    // nobody assigns work any more, and the old module's default worker would
    // otherwise pull every checkout out of the pool.
    $conn->prepare("
        UPDATE ops_jobs j
        SET j.status = 'cancelled', j.updated_at = ?
        WHERE j.source_type = 'ars_checkout'
          AND j.assigned_to IS NULL
          AND j.status = 'open'
          AND j.company_id IN ($in)
          AND " . ops_ars_order_handled_sql('j') . "
    ")->execute(array_merge([$now], $companyIds));

    // And back again, for a job only this withdrawal cancelled: nobody on it,
    // nothing started.
    $conn->prepare("
        UPDATE ops_jobs j
        JOIN re_move_outs m ON m.id = j.source_id
        SET j.status = 'open', j.updated_at = ?
        WHERE j.source_type = 'tenant_move_out'
          AND j.assigned_to IS NULL
          AND j.status = 'cancelled'
          AND j.started_at IS NULL
          AND j.company_id IN ($in)
          AND m.status <> 'cancelled'
    ")->execute(array_merge([$now], $companyIds));

    $conn->prepare("
        UPDATE ops_jobs j
        JOIN ars_bookings b ON b.id = j.source_id
        SET j.status = 'open', j.updated_at = ?
        WHERE j.source_type = 'ars_checkout'
          AND j.assigned_to IS NULL
          AND j.status = 'cancelled'
          AND j.started_at IS NULL
          AND j.company_id IN ($in)
          AND b.status IN ('checked_out', 'completed')
          AND NOT " . ops_ars_order_handled_sql('j') . "
    ")->execute(array_merge([$now], $companyIds));
}

/**
 * Cleaning bookings customers make in the customer app.
 *
 * The office used to confirm each one, pick workers and turn it into a work
 * order (operation/ajax_online_bookings.php). Now a booking goes to the pool as
 * it is made. It has no unit — it is somebody's home — so the address is the
 * job's location. Only bookings made since go-live, for today or later, that
 * the office has not already turned into a work order. A repeating booking
 * becomes one job, for its first date: nothing here makes jobs on a schedule.
 */
function ops_sync_customer_bookings(PDO $conn, array $companyIds): void
{
    // Not gated on the caller's companies: these jobs are open to every
    // company (ops_job_open_to_all_sql), so any list load may copy them.
    if (!ops_source_type_ready($conn, 'customer_booking')) {
        return;
    }

    $stmt = $conn->prepare("
        SELECT ob.*, s.name AS service_name
        FROM online_bookings ob
        LEFT JOIN services s ON s.id = ob.service_id
        LEFT JOIN ops_jobs j
               ON j.source_type = 'customer_booking' AND j.source_id = ob.id
        WHERE ob.status IN ('pending', 'confirmed')
          AND ob.work_order_id IS NULL
          AND ob.created_at >= ?
          AND ob.scheduled_date >= ?
          AND j.id IS NULL
        ORDER BY ob.id ASC
        LIMIT 100
    ");
    $stmt->execute([OPS_TENANT_SYNC_FROM, date('Y-m-d')]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $booking) {
        $people = max(1, (int)$booking['professionals']);
        $hours = rtrim(rtrim(number_format((float)$booking['hours'], 2, '.', ''), '0'), '.');
        $lines = [
            implode(' · ', [
                $people . ($people === 1 ? ' cleaner' : ' cleaners'),
                $hours . ($hours === '1' ? ' hour' : ' hours'),
                (int)$booking['materials_included'] === 1 ? 'bring materials' : 'no materials',
            ]),
            trim((string)$booking['customer_name']) . ' · ' . trim((string)$booking['customer_phone']),
        ];
        if (($booking['frequency'] ?? 'one_time') !== 'one_time' && trim((string)$booking['frequency']) !== '') {
            $lines[] = 'Booked as ' . str_replace('_', ' ', (string)$booking['frequency']) . ' — this job is the first visit only.';
        }
        foreach (['instructions', 'notes'] as $field) {
            if (trim((string)$booking[$field]) !== '') {
                $lines[] = trim((string)$booking[$field]);
            }
        }

        ops_insert_source_job($conn, [
            'company_id' => OPS_CUSTOMER_BOOKING_COMPANY_ID,
            'job_type' => 'cleaning',
            'title' => trim((string)($booking['service_name'] ?? '')) ?: 'Customer booking',
            'location' => mb_substr(trim((string)$booking['address']) ?: 'Address not given', 0, 255),
            'description' => implode("\n", $lines),
            'priority' => 'normal',
            'scheduled_date' => (string)$booking['scheduled_date'],
            'scheduled_time' => (string)$booking['scheduled_time'] ?: null,
            'source_type' => 'customer_booking',
            'source_id' => (int)$booking['id'],
        ], []);
    }
}

/**
 * Whether ops_jobs.source_type can hold this value yet.
 *
 * The inserts here are INSERT IGNORE, and IGNORE turns a value the enum does
 * not have into an empty string instead of an error — a job with no source,
 * which billing would then treat as a staff job. So a source whose migration
 * has not run is not copied at all.
 */
function ops_source_type_ready(PDO $conn, string $sourceType): bool
{
    static $types = null;
    if ($types === null) {
        $row = $conn->query("SHOW COLUMNS FROM ops_jobs LIKE 'source_type'")->fetch(PDO::FETCH_ASSOC);
        $types = (string)($row['Type'] ?? '');
    }
    return strpos($types, "'" . $sourceType . "'") !== false;
}

/**
 * Take back unclaimed customer-booking jobs the customer cancelled, or the
 * office turned into a work order in the old module — and put them back if
 * that is undone.
 */
function ops_withdraw_customer_bookings(PDO $conn, array $companyIds): void
{
    $now = date('Y-m-d H:i:s');

    $conn->prepare("
        UPDATE ops_jobs j
        JOIN online_bookings ob ON ob.id = j.source_id
        SET j.status = 'cancelled', j.updated_at = ?
        WHERE j.source_type = 'customer_booking'
          AND j.assigned_to IS NULL
          AND j.status = 'open'
          AND (ob.status IN ('cancelled', 'no_show') OR ob.work_order_id IS NOT NULL)
    ")->execute([$now]);

    $conn->prepare("
        UPDATE ops_jobs j
        JOIN online_bookings ob ON ob.id = j.source_id
        SET j.status = 'open', j.updated_at = ?
        WHERE j.source_type = 'customer_booking'
          AND j.assigned_to IS NULL
          AND j.status = 'cancelled'
          AND j.started_at IS NULL
          AND ob.status IN ('pending', 'confirmed')
          AND ob.work_order_id IS NULL
    ")->execute([$now]);
}

/**
 * Invoice checkouts that were cleaned through the old module instead.
 *
 * Their job left the pool when the old work order was marked completed, and
 * the old module would then wait for somebody to press Finalize. This does it,
 * the same way Finish does. Only checkouts that had a job here — go-live
 * onwards — and a few per page load.
 */
function ops_finalize_old_module_checkouts(PDO $conn, array $companyIds): void
{
    require_once __DIR__ . '/ops_billing.php';

    $in = implode(',', array_fill(0, count($companyIds), '?'));
    $stmt = $conn->prepare("
        SELECT o.id AS order_id, j.id AS job_id
        FROM ops_jobs j
        JOIN make_order o ON o.ars_booking_id = j.source_id
        WHERE j.source_type = 'ars_checkout'
          AND j.status = 'cancelled'
          AND j.company_id IN ($in)
          AND o.status = 'completed'
          AND o.invoice_id IS NULL
          AND COALESCE(j.billing_status, '') <> 'failed'
        ORDER BY o.id ASC
        LIMIT 5
    ");
    $stmt->execute($companyIds);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $orderId = (int)$row['order_id'];
        $jobId = (int)$row['job_id'];
        try {
            $invoiceId = ops_bill_finalize_order(
                $conn, $orderId, null,
                "ARS work order #{$orderId} completed in the old module — finalized and invoiced automatically"
            );
            ops_bill_link($conn, $jobId, $orderId, 'billed', null, $invoiceId);
        } catch (Throwable $e) {
            // Said once, on the job, and not retried on every page load: the
            // query above skips a job already marked failed.
            ops_bill_link($conn, $jobId, $orderId, 'failed',
                mb_substr('Invoice not created from ARS work order #' . $orderId . ': ' . $e->getMessage(), 0, 255), null);
            error_log('ops_finalize_old_module_checkouts #' . $orderId . ' failed: ' . $e->getMessage());
        }
    }
}

/**
 * How long a request may wait in the pool before it is pushed up.
 *
 * Nobody in the office watches the pool, so a request nobody takes has to get
 * louder by itself. After this long it goes to high priority, which puts the
 * Urgent tag on it on every phone and sorts it with the urgent work.
 */
const OPS_UNCLAIMED_ESCALATE_HOURS = 2;

function ops_escalate_unclaimed(PDO $conn, array $companyIds): void
{
    $in = implode(',', array_fill(0, count($companyIds), '?'));
    $cutoff = date('Y-m-d H:i:s', time() - OPS_UNCLAIMED_ESCALATE_HOURS * 3600);
    $conn->prepare("
        UPDATE ops_jobs
        SET priority = 'high', updated_at = ?
        WHERE assigned_to IS NULL
          AND status = 'open'
          AND source_type <> 'staff'
          AND priority <> 'high'
          AND (company_id IN ($in) OR " . ops_job_open_to_all_sql('') . ")
          AND scheduled_date <= ?
          AND GREATEST(created_at, CAST(CONCAT(scheduled_date, ' ', COALESCE(scheduled_time, '00:00:00')) AS DATETIME)) <= ?
    ")->execute(array_merge([date('Y-m-d H:i:s')], $companyIds, [date('Y-m-d'), $cutoff]));
}

/**
 * SQL: this checkout was already cleaned through the old module — its work
 * order is completed or invoiced there.
 */
function ops_ars_order_handled_sql(string $jobAlias): string
{
    return "EXISTS (
              SELECT 1 FROM make_order o
              WHERE o.ars_booking_id = {$jobAlias}.source_id
                AND o.status IN ('completed', 'invoiced')
          )";
}

/**
 * Show on the ARS work order who took the job in the app.
 *
 * Written to make_order.worker_name only, which the old work-order list shows
 * when no worker is assigned there. Not order_workers: that feeds worker
 * availability, HR performance and the per-worker split of hours, and this
 * person may not be on the old workers list at all. The " (app)" suffix is how
 * a later release knows the name is ours to clear. Never touches an order the
 * office has put a worker on.
 */
function ops_ars_order_show_person(PDO $conn, int $bookingId, ?int $userId): void
{
    try {
        $name = '';
        if ($userId) {
            $stmt = $conn->prepare("SELECT COALESCE(NULLIF(fullname, ''), username) FROM user WHERE id = ?");
            $stmt->execute([$userId]);
            $name = mb_substr((string)$stmt->fetchColumn() . ' (app)', 0, 50);
        }
        $conn->prepare("
            UPDATE make_order o
            SET o.worker_name = ?
            WHERE o.ars_booking_id = ?
              AND o.status NOT IN ('cancelled', 'invoiced')
              AND NOT EXISTS (SELECT 1 FROM order_workers ow WHERE ow.order_id = o.id)
              AND (COALESCE(o.worker_name, '') = '' OR o.worker_name LIKE '% (app)')
        ")->execute([$name, $bookingId]);
    } catch (Throwable $e) {
        error_log('ops_ars_order_show_person failed: ' . $e->getMessage());
    }
}

/**
 * Take back pool jobs whose request is no longer waiting.
 *
 * Only jobs nobody has claimed. If the office cancels a request, closes it, or
 * puts its own person on it in the old module, a job still sitting in the pool
 * would send somebody to do work that is already handled. Once a person has
 * claimed it the job is theirs and the office's change is a conversation, not
 * something to undo silently under them.
 */
function ops_withdraw_tenant_requests(PDO $conn, array $companyIds): void
{
    $in = implode(',', array_fill(0, count($companyIds), '?'));
    $now = date('Y-m-d H:i:s');

    $conn->prepare("
        UPDATE ops_jobs j
        JOIN re_maintenance_requests m ON m.id = j.source_id
        SET j.status = 'cancelled', j.updated_at = ?
        WHERE j.source_type = 'tenant_maintenance'
          AND j.assigned_to IS NULL
          AND j.status = 'open'
          AND j.company_id IN ($in)
          AND (m.status NOT IN ('pending', 'in_progress') OR m.assigned_to IS NOT NULL)
    ")->execute(array_merge([$now], $companyIds));

    $conn->prepare("
        UPDATE ops_jobs j
        JOIN tenant_cleaning_requests c ON c.id = j.source_id
        SET j.status = 'cancelled', j.updated_at = ?
        WHERE j.source_type = 'tenant_cleaning'
          AND j.assigned_to IS NULL
          AND j.status = 'open'
          AND j.company_id IN ($in)
          AND c.status = 'rejected'
    ")->execute(array_merge([$now], $companyIds));

    // And the other way. A request is only ever copied once — the unique key
    // sees to that — so a booking rejected and then approved again, or a
    // request the office put back to pending, would otherwise leave its job
    // cancelled for good and never reach anyone. Only jobs this withdrawal
    // cancelled qualify: nobody on them, and nothing started.
    $conn->prepare("
        UPDATE ops_jobs j
        JOIN re_maintenance_requests m ON m.id = j.source_id
        SET j.status = 'open', j.updated_at = ?
        WHERE j.source_type = 'tenant_maintenance'
          AND j.assigned_to IS NULL
          AND j.status = 'cancelled'
          AND j.started_at IS NULL
          AND j.company_id IN ($in)
          AND m.status IN ('pending', 'in_progress') AND m.assigned_to IS NULL
    ")->execute(array_merge([$now], $companyIds));

    $conn->prepare("
        UPDATE ops_jobs j
        JOIN tenant_cleaning_requests c ON c.id = j.source_id
        SET j.status = 'open', j.updated_at = ?
        WHERE j.source_type = 'tenant_cleaning'
          AND j.assigned_to IS NULL
          AND j.status = 'cancelled'
          AND j.started_at IS NULL
          AND j.company_id IN ($in)
          AND c.status IN ('pending', 'approved')
          AND (c.service_date IS NULL OR c.service_date >= ?)
    ")->execute(array_merge([$now], $companyIds, [date('Y-m-d')]));
}

/**
 * Insert one pool job and its place, once.
 *
 * INSERT IGNORE against uniq_ops_jobs_source: if two phones load the list in
 * the same second, both try, one row is written, and the other insert is a
 * no-op rather than a duplicate or an error.
 *
 * @param array<int, array{kind:string,id:int,building_id:int,label:string}> $places
 */
function ops_insert_source_job(PDO $conn, array $job, array $places): void
{
    $now = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("
        INSERT IGNORE INTO ops_jobs
            (company_id, job_type, title, location, description, assigned_to,
             scheduled_date, scheduled_time, priority, status, created_by,
             source_type, source_id, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, 'open', ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $job['company_id'], $job['job_type'], mb_substr($job['title'], 0, 255), $job['location'] ?? null, $job['description'],
        $job['scheduled_date'], $job['scheduled_time'], $job['priority'],
        // A person who raised it (a cleaner's checklist); NULL for a copied request.
        $job['created_by'] ?? null,
        $job['source_type'], $job['source_id'], $now, $now,
    ]);

    // Ignored as a duplicate: the other request already made it, places and all.
    if ($stmt->rowCount() === 0) {
        return;
    }

    $jobId = (int)$conn->lastInsertId();
    $insPlace = $conn->prepare("
        INSERT IGNORE INTO ops_job_places
            (job_id, company_id, place_kind, place_id, building_id, label, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($places as $place) {
        $insPlace->execute([
            $jobId, $job['company_id'], $place['kind'], $place['id'],
            $place['building_id'], $place['label'], $now,
        ]);
    }
}

/**
 * A unit or common area as a job place, labelled the way staff-created jobs
 * are — "PARK PLACE — Unit 704" — or null when it does not exist.
 */
function ops_source_place(PDO $conn, string $kind, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    if ($kind === 'common_area') {
        $stmt = $conn->prepare("
            SELECT a.id, a.building_id, a.area_name AS name, b.name AS building_name
            FROM re_building_common_areas a
            JOIN re_buildings b ON b.id = a.building_id
            WHERE a.id = ?
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT u.id, u.building_id, u.unit_number AS name, b.name AS building_name
            FROM re_units u
            JOIN re_buildings b ON b.id = u.building_id
            WHERE u.id = ?
        ");
    }
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'kind' => $kind === 'common_area' ? 'common_area' : 'unit',
        'id' => (int)$row['id'],
        'building_id' => (int)$row['building_id'],
        'label' => trim((string)$row['building_name'] . ' — ' . (string)$row['name']),
    ];
}

/**
 * A request category as a job name.
 *
 * The categories are typed by people and arrive as "ac", "HVAC", "plumbing",
 * "pest_control", "GENERAL MAITENANCE". Underscores are spaces. Short ones are
 * initialisms and go upper case — "ac" as "Ac" reads like a typo — and the rest
 * become words with capitals: "Pest Control".
 */
function ops_source_category_title(string $category): string
{
    $category = trim(str_replace('_', ' ', $category));
    if ($category === '') {
        return 'Maintenance request';
    }
    return mb_strlen($category) <= 4
        ? mb_strtoupper($category)
        : mb_convert_case(mb_strtolower($category), MB_CASE_TITLE);
}

/** The job type a maintenance request's category asks for. */
function ops_source_job_type(string $category): string
{
    return strtolower(trim($category)) === 'cleaning' ? 'cleaning' : 'maintenance';
}

/** Four request priorities into the three a job has. Urgent and high both mean go first. */
function ops_source_priority(string $priority): string
{
    return [
        'urgent' => 'high',
        'high' => 'high',
        'low' => 'low',
    ][strtolower($priority)] ?? 'normal';
}

// ---------------------------------------------------------------------------
// Write back to the request
// ---------------------------------------------------------------------------

/**
 * Somebody took a pool job. Tell the request.
 *
 *   - Maintenance: the request goes In progress with that person's name in
 *     the old page's Assigned column, and the tenant hears somebody is on it.
 *     `assigned_to` there is an employees.id, not a user id, so the claimer is
 *     looked up through employees.user_id.
 *   - Tenant cleaning: taking it is the approval — the booking goes Approved,
 *     dated today if the tenant gave no date, and the tenant is told.
 *   - Customer booking: confirmed, as the office used to.
 *   - ARS checkout: the name shows on the old work order.
 *
 * Every write is guarded on the state it expects, so a change the office made
 * in the meantime is not overwritten.
 */
function ops_source_on_claim(PDO $conn, array $job, int $userId): void
{
    $sourceType = (string)($job['source_type'] ?? 'staff');
    $sourceId = (int)($job['source_id'] ?? 0);

    if ($sourceType === 'ars_checkout') {
        ops_ars_order_show_person($conn, $sourceId, $userId);
        return;
    }

    if ($sourceType === 'customer_booking') {
        try {
            $conn->prepare("
                UPDATE online_bookings
                SET status = 'confirmed', confirmed_at = COALESCE(confirmed_at, ?), updated_at = ?
                WHERE id = ? AND status = 'pending'
            ")->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $sourceId]);
        } catch (Throwable $e) {
            error_log('ops_source_on_claim customer booking failed: ' . $e->getMessage());
        }
        return;
    }

    if ($sourceType === 'tenant_cleaning') {
        try {
            $stmt = $conn->prepare("
                UPDATE tenant_cleaning_requests
                SET status = 'approved', approved_at = ?, service_date = COALESCE(service_date, ?)
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute([date('Y-m-d H:i:s'), (string)$job['scheduled_date'], $sourceId]);
            if ($stmt->rowCount() > 0) {
                ops_source_notify_tenant($conn, 'tenant_cleaning_requests', $sourceId, [
                    'type' => 'cleaning_status',
                    'entity_type' => 'cleaning',
                    'title' => 'Cleaning request approved',
                    'body' => 'Your cleaning request has been approved.',
                ]);
            }
        } catch (Throwable $e) {
            error_log('ops_source_on_claim tenant cleaning failed: ' . $e->getMessage());
        }
        return;
    }

    if ($sourceType !== 'tenant_maintenance') {
        return;
    }

    try {
        $emp = $conn->prepare("SELECT id FROM employees WHERE user_id = ? ORDER BY id ASC LIMIT 1");
        $emp->execute([$userId]);
        $employeeId = (int)$emp->fetchColumn();

        $conn->prepare("
            UPDATE re_maintenance_requests
            SET status = 'in_progress',
                assigned_to = COALESCE(?, assigned_to),
                responded_at = COALESCE(responded_at, ?),
                updated_at = ?
            WHERE id = ? AND status IN ('pending', 'in_progress') AND assigned_to IS NULL
        ")->execute([
            $employeeId > 0 ? $employeeId : null,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            (int)$job['source_id'],
        ]);
        ops_source_notify_tenant($conn, 're_maintenance_requests', $sourceId, [
            'type' => 'maintenance_status',
            'entity_type' => 'maintenance',
            'title' => 'Maintenance in progress',
            'body' => 'Someone is on the way for your maintenance request.',
        ]);
    } catch (Throwable $e) {
        error_log('ops_source_on_claim failed: ' . $e->getMessage());
    }
}

/**
 * The job is finished. Close the request and tell the tenant.
 *
 * Maintenance requests go Completed. Cleaning bookings have no completed state
 * in their table — pending, approved, rejected — so nothing is written there,
 * but the tenant still hears that it is done. The notification uses the same
 * helper, type and entity the Real Estate pages use, so it lands in the tenant
 * app exactly where their other updates do.
 */
function ops_source_on_finish(PDO $conn, array $job): void
{
    $sourceType = (string)($job['source_type'] ?? 'staff');
    $sourceId = (int)($job['source_id'] ?? 0);
    if ($sourceType === 'staff' || $sourceId <= 0) {
        return;
    }

    require_once dirname(__DIR__, 3) . '/includes/tenant_notifications.php';

    try {
        if ($sourceType === 'tenant_maintenance') {
            $req = $conn->prepare("SELECT company_id, lease_id, tenant_id FROM re_maintenance_requests WHERE id = ?");
            $req->execute([$sourceId]);
            $request = $req->fetch(PDO::FETCH_ASSOC);
            if (!$request) {
                return;
            }

            $now = date('Y-m-d H:i:s');
            $done = $conn->prepare("
                UPDATE re_maintenance_requests
                SET status = 'completed', completed_at = COALESCE(completed_at, ?), updated_at = ?
                WHERE id = ? AND status NOT IN ('completed', 'cancelled')
            ");
            $done->execute([$now, $now, $sourceId]);

            // Only when this finish is what closed it — a replayed finish, or a
            // request the office had already closed, must not notify twice.
            if ($done->rowCount() > 0) {
                ops_source_notify_tenant($conn, 're_maintenance_requests', $sourceId, [
                    'type' => 'maintenance_status',
                    'entity_type' => 'maintenance',
                    'title' => 'Maintenance completed',
                    'body' => 'Your maintenance request is now completed.',
                ]);
            }
            return;
        }

        if ($sourceType === 'tenant_cleaning') {
            $req = $conn->prepare("SELECT company_id, lease_id, tenant_id FROM tenant_cleaning_requests WHERE id = ?");
            $req->execute([$sourceId]);
            $booking = $req->fetch(PDO::FETCH_ASSOC);
            if (!$booking) {
                return;
            }
            tenant_notification_create($conn, [
                'company_id' => (int)$booking['company_id'],
                'lease_id' => (int)($booking['lease_id'] ?? 0),
                'tenant_id' => (int)($booking['tenant_id'] ?? 0),
                'type' => 'cleaning_status',
                'entity_type' => 'cleaning',
                'entity_id' => $sourceId,
                'title' => 'Cleaning completed',
                'body' => 'Your cleaning has been completed.',
            ]);
        }
    } catch (Throwable $e) {
        error_log('ops_source_on_finish failed: ' . $e->getMessage());
    }
}

/**
 * Tell the tenant behind a request, the way the Real Estate pages do. Nothing
 * for a request with no tenant or lease on it — one the office raised for a
 * common area, say.
 */
function ops_source_notify_tenant(PDO $conn, string $table, int $id, array $message): void
{
    if (!in_array($table, ['re_maintenance_requests', 'tenant_cleaning_requests'], true)) {
        return;
    }
    try {
        $stmt = $conn->prepare("SELECT company_id, lease_id, tenant_id FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || ((int)($row['lease_id'] ?? 0) <= 0 && (int)($row['tenant_id'] ?? 0) <= 0)) {
            return;
        }
        require_once dirname(__DIR__, 3) . '/includes/tenant_notifications.php';
        tenant_notification_create($conn, $message + [
            'company_id' => (int)$row['company_id'],
            'lease_id' => (int)($row['lease_id'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'entity_id' => $id,
        ]);
    } catch (Throwable $e) {
        error_log('ops_source_notify_tenant failed: ' . $e->getMessage());
    }
}

/**
 * The photos the tenant attached to the maintenance request a job came from,
 * oldest first. Images only — the tenant app accepts nothing else, and nothing
 * else is shown. Empty for every other kind of job.
 *
 * @return array<int, array{id:int, file_path:string, mime_type:?string, created_at:string}>
 */
function ops_request_photos(PDO $conn, array $job): array
{
    if (($job['source_type'] ?? '') !== 'tenant_maintenance' || (int)($job['source_id'] ?? 0) <= 0) {
        return [];
    }
    try {
        $stmt = $conn->prepare("
            SELECT id, file_path, mime_type, created_at
            FROM re_maintenance_photos
            WHERE maintenance_request_id = ?
              AND (mime_type IS NULL OR mime_type LIKE 'image/%')
            ORDER BY created_at ASC, id ASC
        ");
        $stmt->execute([(int)$job['source_id']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('ops_request_photos failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Send one of those photos, after the caller has decided the viewer may see
 * the job. Only from uploads/realestate/maintenance, and only as an image.
 */
function ops_serve_request_photo(array $photo): bool
{
    $appRoot = dirname(__DIR__, 3);
    $baseDir = realpath($appRoot . '/uploads/realestate/maintenance');
    $absPath = realpath($appRoot . '/' . ltrim((string)$photo['file_path'], '/'));
    if ($baseDir === false || $absPath === false
        || strpos($absPath, $baseDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($absPath)) {
        return false;
    }
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    if (!isset($types[$ext])) {
        return false;
    }
    header('Content-Type: ' . $types[$ext]);
    header('Content-Length: ' . filesize($absPath));
    header('Content-Disposition: inline; filename="' . basename($absPath) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=600');
    readfile($absPath);
    return true;
}

/**
 * Where the office can see the original request, for the web badge. Null for
 * a job staff raised themselves.
 */
function ops_source_link(string $appBase, array $job): ?array
{
    $id = (int)($job['source_id'] ?? 0);
    switch ((string)($job['source_type'] ?? 'staff')) {
        case 'tenant_maintenance':
            return [
                'label' => 'Maintenance request #' . $id,
                'url' => $appBase . '/modules/realestate/maintenance_view.php?id=' . $id,
            ];
        case 'cleaner_report':
            return [
                'label' => 'Found during cleaning job #' . $id,
                'url' => $appBase . '/modules/operations/job_view.php?id=' . $id,
            ];
        case 'tenant_cleaning':
            return [
                'label' => 'Tenant cleaning booking #' . $id,
                'url' => $appBase . '/modules/realestate/cleaning_requests.php',
            ];
        case 'ars_checkout':
            return [
                'label' => 'ARS guest checkout',
                'url' => $appBase . '/modules/ars/booking_view.php?id=' . $id,
            ];
        case 'customer_booking':
            return [
                'label' => 'Customer app booking #' . $id,
                'url' => $appBase . '/operation/online_bookings.php',
            ];
        case 'tenant_move_out':
            return [
                'label' => 'Tenant move-out #' . $id,
                'url' => $appBase . '/modules/realestate/move_out_view.php?id=' . $id,
            ];
        default:
            return null;
    }
}
