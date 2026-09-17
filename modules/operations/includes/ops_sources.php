<?php
/**
 * Operations — jobs that start as a tenant's request.
 *
 * A job begins one of two ways. Staff raise their own on the phone, or a tenant
 * asks for something:
 *
 *   - a maintenance request, from tenant_portal/maintenance.php or the tenant
 *     app (api/customer/v1/tenant_services_endpoints.php), into
 *     re_maintenance_requests;
 *   - a cleaning booking, from tenant_portal/cleaning.php, into
 *     tenant_cleaning_requests — which the office still approves first.
 *
 * NOTHING IN THE OLD MODULE WAS CHANGED FOR THIS
 * ----------------------------------------------
 * The tenant portal, the tenant API and the Real Estate pages carry on exactly
 * as they were. Instead of hooking every place a request is written, this side
 * reads those tables and makes its own copy — the same pull the daily repeat
 * generator already does, run from the same places. The unique key on
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
    $companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds))));
    if (!$companyIds) {
        return;
    }

    try {
        ops_sync_tenant_maintenance($conn, $companyIds);
        ops_sync_tenant_cleaning($conn, $companyIds);
        ops_withdraw_tenant_requests($conn, $companyIds);
    } catch (Throwable $e) {
        // Most often a database without migrations/ops_job_sources.sql yet.
        error_log('ops_sync_tenant_requests failed: ' . $e->getMessage());
    }
}

/**
 * Maintenance requests a tenant raised that nobody has picked up.
 *
 * "A tenant raised it" is `tenant_id` with no `created_by`: both tenant routes
 * write exactly that, and the office page always records who typed it. Only
 * pending and unassigned — once the office has put someone on a request in the
 * old module, it is theirs, and copying it would send a second person.
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
          AND m.status = 'pending'
          AND m.assigned_to IS NULL
          AND m.tenant_id IS NOT NULL
          AND m.created_by IS NULL
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
            'job_type' => 'maintenance',
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
 * Cleaning bookings the office has approved.
 *
 * Approval stays in modules/realestate/cleaning_requests.php, because that is
 * where the date is confirmed and the payment is dealt with — a booking still
 * pending may never happen, and one rejected must never reach a cleaner. Only
 * bookings for today or later: an approved booking whose day has already gone
 * is not a job anybody can still do.
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
          AND c.status = 'approved'
          AND c.service_date >= ?
          AND j.id IS NULL
        ORDER BY c.id ASC
        LIMIT 100
    ");
    $stmt->execute(array_merge($companyIds, [max(date('Y-m-d'), OPS_TENANT_SYNC_FROM)]));

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
            'scheduled_date' => (string)$booking['service_date'],
            'scheduled_time' => $time !== '' ? $time : null,
            'source_type' => 'tenant_cleaning',
            'source_id' => (int)$booking['id'],
        ], [$place]);
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
          AND (m.status <> 'pending' OR m.assigned_to IS NOT NULL)
    ")->execute(array_merge([$now], $companyIds));

    $conn->prepare("
        UPDATE ops_jobs j
        JOIN tenant_cleaning_requests c ON c.id = j.source_id
        SET j.status = 'cancelled', j.updated_at = ?
        WHERE j.source_type = 'tenant_cleaning'
          AND j.assigned_to IS NULL
          AND j.status = 'open'
          AND j.company_id IN ($in)
          AND c.status <> 'approved'
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
          AND m.status = 'pending' AND m.assigned_to IS NULL
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
          AND c.status = 'approved'
          AND c.service_date >= ?
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
            (company_id, job_type, title, description, assigned_to,
             scheduled_date, scheduled_time, priority, status, created_by,
             source_type, source_id, created_at, updated_at)
        VALUES (?, ?, ?, ?, NULL, ?, ?, ?, 'open', NULL, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $job['company_id'], $job['job_type'], mb_substr($job['title'], 0, 255), $job['description'],
        $job['scheduled_date'], $job['scheduled_time'], $job['priority'],
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
 * "GENERAL MAITENANCE". Short ones are initialisms and go upper case — "ac" as
 * "Ac" reads like a typo — and the rest become one capital letter.
 */
function ops_source_category_title(string $category): string
{
    $category = trim($category);
    if ($category === '') {
        return 'Maintenance request';
    }
    return mb_strlen($category) <= 4
        ? mb_strtoupper($category)
        : ucfirst(mb_strtolower($category));
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
 * Maintenance only: the request goes In progress with that person's name in
 * the old page's Assigned column. `assigned_to` there is an employees.id, not
 * a user id, so the claimer is looked up through employees.user_id. Guarded on
 * status = 'pending' so a change the office made in the meantime is not
 * overwritten.
 */
function ops_source_on_claim(PDO $conn, array $job, int $userId): void
{
    if (($job['source_type'] ?? 'staff') !== 'tenant_maintenance') {
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
            WHERE id = ? AND status = 'pending'
        ")->execute([
            $employeeId > 0 ? $employeeId : null,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            (int)$job['source_id'],
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
                tenant_notification_create($conn, [
                    'company_id' => (int)$request['company_id'],
                    'lease_id' => (int)($request['lease_id'] ?? 0),
                    'tenant_id' => (int)($request['tenant_id'] ?? 0),
                    'type' => 'maintenance_status',
                    'entity_type' => 'maintenance',
                    'entity_id' => $sourceId,
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
 * Where the office can see the original request, for the web badge. Null for
 * a job staff raised themselves.
 */
function ops_source_link(string $appBase, array $job): ?array
{
    $id = (int)($job['source_id'] ?? 0);
    switch ((string)($job['source_type'] ?? 'staff')) {
        case 'tenant_maintenance':
            return [
                'label' => 'Tenant maintenance request #' . $id,
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
        default:
            return null;
    }
}
