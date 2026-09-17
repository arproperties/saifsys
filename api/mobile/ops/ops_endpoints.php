<?php
/**
 * Operations field app — route handlers.
 *
 * Every handler below is reached only after ops_api_current_user() has proved
 * who the caller is from their token — see the header of ops_api.php. The one
 * exception is ops_api_handle_pin_login(), which is how they get a token.
 *
 * Business rules are not re-implemented here. Anything that changes a job
 * mirrors modules/operations/job_action.php, and anything that touches a file
 * goes through ops_store_photo() or its sibling ops_store_comment_media().
 * If those change, these must change with them.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// POST ops/auth/pin — four digits in, a token out
// ---------------------------------------------------------------------------

/**
 * Sign in with a PIN and nothing else.
 *
 * The person does not say who they are first; the PIN says it for them. That
 * is the whole point of the screen — a cleaner starting a shift types four
 * digits and is at their job list — and it is also what makes the throttle
 * load-bearing, because a wrong guess is checked against every employee at
 * once. See the header of modules/operations/includes/ops_pin.php.
 *
 * Three deliberate choices about what this tells the caller:
 *
 *   - a wrong PIN and a PIN belonging to somebody with no company both answer
 *     the same "that PIN did not work", so the response cannot be used to map
 *     which of the 10,000 values exist;
 *   - a failed attempt is recorded BEFORE the answer goes out, so a client
 *     that hangs up early still pays for its guess;
 *   - a lockout says how long is left, because the alternative is a person
 *     standing in a corridor tapping at a screen that will not explain itself.
 */
function ops_api_handle_pin_login(PDO $conn): void
{
    if (!ops_pin_configured()) {
        ops_api_log('REFUSED 503 no PIN secret configured on the server');
        customer_api_send_error('not_configured', 'This service is not available.', 503);
    }

    $deviceId = substr(trim((string)($_SERVER['HTTP_X_OPS_DEVICE_ID'] ?? '')), 0, 64);
    $ip = ops_pin_client_ip();

    $throttle = ops_pin_throttle_state($conn, $deviceId, $ip);
    if ($throttle['blocked']) {
        ops_api_log('REFUSED 429 too many wrong PINs');
        customer_api_send_error(
            'too_many_attempts',
            'Too many wrong PINs. Try again later.',
            429,
            ['retry_after' => $throttle['retry_after']]
        );
    }

    $body = customer_api_read_json_body();
    $pin = trim((string)($body['pin'] ?? ''));

    $person = ops_pin_format_ok($pin) ? ops_pin_resolve_user($conn, $pin) : null;

    // Somebody with a PIN but no company cannot be given jobs, so signing them
    // in would only hand them an empty screen with no way to understand it.
    $companyIds = $person ? ops_api_user_company_ids($conn, (int)$person['id']) : [];

    if (!$person || $companyIds === []) {
        ops_pin_record_attempt($conn, $deviceId, $ip, $person['id'] ?? null, false);
        ops_pin_prune_attempts($conn);
        ops_api_log('REFUSED 401 wrong PIN');
        customer_api_send_error('bad_pin', 'That PIN did not work.', 401);
    }

    ops_pin_record_attempt($conn, $deviceId, $ip, (int)$person['id'], true);
    ops_pin_prune_attempts($conn);

    $issued = ops_api_issue_token((int)$person['id'], (int)$person['epoch']);

    customer_api_send_ok([
        'token' => $issued['token'],
        'expires_in' => $issued['expires_in'],
        'user' => [
            'id' => (int)$person['id'],
            'name' => (string)$person['name'],
        ],
    ]);
}

// ---------------------------------------------------------------------------
// GET ops/auth/me — is this token still good?
// ---------------------------------------------------------------------------

/**
 * Answers with the signed-in person, or 401 if the token has died — which it
 * does the moment the office changes their PIN or deactivates the account.
 * The app asks on launch so a revoked phone lands on the PIN screen rather
 * than on a job list that fails one request at a time.
 */
function ops_api_handle_me(PDO $conn, array $user): void
{
    customer_api_send_ok([
        'user' => [
            'id' => (int)$user['id'],
            'name' => (string)$user['name'],
        ],
    ]);
}

// ---------------------------------------------------------------------------
// GET ops/jobs — this person's jobs, nobody else's
// ---------------------------------------------------------------------------

/**
 * Which jobs belong in each tab.
 *
 * `today`    — dated today and still to be done, and nothing else. Work that
 *              ran past its date is not today's work, so it does not pile up
 *              here; it moves to `overdue`. Finished and cancelled work drops
 *              out too; the person finds it again under `done`.
 * `overdue`  — dated before today and still to be done. Nothing may fall out
 *              of the four tabs: an unfinished job that is no longer today's
 *              has to be somewhere the person can still reach it.
 * `upcoming` — dated after today and still to be done.
 * `done`     — completed, newest first, whatever the date.
 *
 * @return array{0:string,1:array} SQL fragment and its bound values
 */
function ops_api_tab_filter(string $tab, string $today): array
{
    switch ($tab) {
        case 'overdue':
            return [" AND j.scheduled_date < ? AND j.status IN ('open','in_progress')", [$today]];
        case 'upcoming':
            return [" AND j.scheduled_date > ? AND j.status IN ('open','in_progress')", [$today]];
        case 'done':
            return [" AND j.status = 'done'", []];
        // The pool. Scoped by its own base query — see ops_api_handle_jobs_list().
        case 'requests':
            return [" AND j.status = 'open'", []];
        case 'today':
        default:
            return [
                " AND j.scheduled_date = ? AND j.status IN ('open','in_progress')",
                [$today],
            ];
    }
}

function ops_api_tab_order(string $tab): string
{
    if ($tab === 'done') {
        return ' ORDER BY COALESCE(j.finished_at, j.updated_at) DESC, j.id DESC';
    }
    // Timed work first, in clock order; "any time" jobs after it. On Overdue
    // that also puts the oldest debt at the top, which is the one the office
    // is being asked about.
    return ' ORDER BY j.scheduled_date ASC, j.scheduled_time IS NULL, j.scheduled_time ASC, j.id ASC';
}

function ops_api_handle_jobs_list(PDO $conn, array $user): void
{
    $today = date('Y-m-d');

    // Daily repeating jobs are created on the way in, exactly as the supervisor
    // list does it — there is no cron behind this. A cleaner opening the app at
    // six in the morning is often the first thing to touch the module all day,
    // so if this did not run here their Today tab would be empty until somebody
    // in the office logged in.
    //
    // Driven by the companies this person actually has series in, not by their
    // user_companies rows: staff are shared across the group, so a cleaner on
    // the Heroes Zone payroll routinely holds an Ain Al Reem series. Keying the
    // generation off their company links skipped exactly those.
    foreach (ops_api_user_series_company_ids($conn, (int)$user['id']) as $cid) {
        ops_generate_daily_jobs($conn, (int)$cid, $today);
    }

    // Tenant requests are copied into the pool the same way, on the way in —
    // see modules/operations/includes/ops_sources.php.
    ops_sync_tenant_requests($conn, $user['company_ids']);

    // Assignment is the scope. `assigned_to` already narrows to one person, so
    // an extra company_id filter protects nothing and only hides a job from the
    // person who has to do it — see ops_assignable_users(), which hands out
    // work group-wide on purpose.
    $base = " FROM ops_jobs j
              WHERE j.assigned_to = ?";
    $baseArgs = [$user['id']];

    // The pool is the one list that is not one person's: tenant jobs nobody
    // has claimed yet. With no assignee to scope by, the person's companies
    // do it instead — that is the only thing that keeps another company's
    // tenants off this phone.
    $companyIn = ops_api_company_in($user['company_ids']);
    $poolBase = " FROM ops_jobs j
                  WHERE j.assigned_to IS NULL
                    AND j.source_type <> 'staff'
                    AND j.company_id IN ($companyIn)";
    $poolArgs = $user['company_ids'];

    $tab = (string)($_GET['tab'] ?? 'today');
    if (!in_array($tab, ['today', 'overdue', 'upcoming', 'done', 'requests'], true)) {
        $tab = 'today';
    }
    if ($tab === 'requests') {
        $base = $poolBase;
        $baseArgs = $poolArgs;
    }

    $where = '';
    $args = [];

    // Explicit filters win over the tab, so `status` / `date` / `from` / `to`
    // stay usable on their own.
    $hasExplicitFilter = false;

    $statusParam = trim((string)($_GET['status'] ?? ''));
    if ($statusParam !== '') {
        $wanted = [];
        foreach (explode(',', $statusParam) as $candidate) {
            $candidate = trim($candidate);
            if (array_key_exists($candidate, ops_statuses())) {
                $wanted[] = $candidate;
            }
        }
        if ($wanted === []) {
            customer_api_send_error('validation_error', 'Unknown status filter.', 400);
        }
        $where .= ' AND j.status IN (' . implode(',', array_fill(0, count($wanted), '?')) . ')';
        $args = array_merge($args, $wanted);
        $hasExplicitFilter = true;
    }

    foreach ([['date', '='], ['from', '>='], ['to', '<=']] as [$key, $op]) {
        $value = trim((string)($_GET[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            customer_api_send_error('validation_error', 'Dates must look like 2026-09-08.', 400);
        }
        $where .= ' AND j.scheduled_date ' . $op . ' ?';
        $args[] = $value;
        $hasExplicitFilter = true;
    }

    if (!$hasExplicitFilter) {
        [$tabSql, $tabArgs] = ops_api_tab_filter($tab, $today);
        $where .= $tabSql;
        $args = array_merge($args, $tabArgs);
    }

    // The count rides along on the list query so the Start button can be
    // greyed out without a detail fetch per card. It stays out of $base,
    // which the tab-badge COUNT(*) queries reuse.
    $select = 'SELECT j.*, ' . ops_api_before_photo_count_sql() . ' AS before_photo_count';
    $stmt = $conn->prepare($select . $base . $where . ops_api_tab_order($tab) . ' LIMIT 200');
    $stmt->execute(array_merge($baseArgs, $args));
    $jobs = array_map('ops_api_job_row', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

    // The places on every card, in one query for the whole list. The card is
    // where somebody decides which job to walk to, so "where" belongs on it.
    $placesByJob = ops_job_places($conn, array_column($jobs, 'id'));
    foreach ($jobs as &$job) {
        $job['places'] = array_map('ops_api_place_row', $placesByJob[(int)$job['id']] ?? []);
    }
    unset($job);

    // Tab badges in one round trip. Each badge counts exactly what its tab
    // shows, so the number and the list can never disagree.
    $counts = [];
    foreach (['today', 'overdue', 'upcoming', 'done', 'requests'] as $name) {
        [$tabSql, $tabArgs] = ops_api_tab_filter($name, $today);
        [$countBase, $countArgs] = $name === 'requests'
            ? [$poolBase, $poolArgs]
            : [" FROM ops_jobs j WHERE j.assigned_to = ?", [$user['id']]];
        $countStmt = $conn->prepare('SELECT COUNT(*)' . $countBase . $tabSql);
        $countStmt->execute(array_merge($countArgs, $tabArgs));
        $counts[$name] = (int)$countStmt->fetchColumn();
    }

    customer_api_send_ok([
        'jobs' => $jobs,
        'counts' => $counts,
        'today' => $today,
    ]);
}

// ---------------------------------------------------------------------------
// GET ops/jobs/{id}
// ---------------------------------------------------------------------------

function ops_api_handle_job_detail(PDO $conn, array $user, int $jobId): void
{
    $job = ops_api_job_or_404($conn, $jobId, $user);
    customer_api_send_ok(['job' => ops_api_job_detail($conn, $job, $user)]);
}

// ---------------------------------------------------------------------------
// POST ops/dev/log — the phone reporting a failure the server cannot see
// ---------------------------------------------------------------------------

/**
 * A line from the app, into the same dev log every request writes to.
 *
 * The failures worth chasing in this app are the ones that never arrive: an
 * upload that dies before the connection opens leaves nothing in the server log
 * at all, which is indistinguishable from nobody having tried. The phone knows
 * exactly what happened; this is how it says so.
 *
 * Development only — the route is not registered unless OPS_MOBILE_DEV_MODE is
 * on, and it writes to a file, never to the database. It carries no
 * authentication of its own beyond the app key the whole API already requires,
 * because a token failure is one of the things it has to be able to report.
 */
function ops_api_handle_dev_log(): void
{
    $message = (string)(ops_api_param('message', '') ?? '');
    // One line, bounded: this is a log, not an inbox.
    $message = preg_replace('/[\r\n]+/', ' ', $message) ?? '';
    $message = trim(mb_substr($message, 0, 400));

    if ($message !== '') {
        ops_api_log('APP ' . $message);
    }

    customer_api_send_ok(['logged' => $message !== '']);
}

// ---------------------------------------------------------------------------
// GET ops/places — everywhere a job can be, for the picker
// ---------------------------------------------------------------------------

/**
 * Every unit and common area, grouped by building.
 *
 * SENT WHOLE, ONCE, ON PURPOSE
 * ----------------------------
 * Around nine hundred rows and well under a tenth of a megabyte. Sending the
 * lot means the app searches its own copy: instant on every keystroke, and it
 * still works in the basement where the search-as-you-type endpoint this
 * replaces would have returned nothing. A cleaner picking a corridor should
 * not need a connection to find out what the corridors are called.
 *
 * Scoped to the same company ops_api_job_company_id() files the job under, so
 * the picker cannot offer a place the create route would then reject.
 */
function ops_api_handle_places(PDO $conn, array $user): void
{
    $companyId = ops_api_job_company_id($conn, $user);

    $buildings = [];
    $stmt = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name ASC");
    $stmt->execute([$companyId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $buildings[(int)$row['id']] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'places' => [],
        ];
    }

    if ($buildings === []) {
        customer_api_send_ok(['buildings' => []]);
    }

    $in = implode(',', array_fill(0, count($buildings), '?'));
    $ids = array_keys($buildings);

    // Common areas first: they are what most jobs are about, and a short list
    // above a long one is the difference between scrolling and searching.
    $stmt = $conn->prepare("
        SELECT id, building_id, area_name, area_type
        FROM re_building_common_areas
        WHERE building_id IN ($in) AND is_active = 1
        ORDER BY area_name ASC
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $buildings[(int)$row['building_id']]['places'][] = [
            'kind' => 'common_area',
            'id' => (int)$row['id'],
            'label' => (string)$row['area_name'],
            // The type reads as a plain word under the name, so two areas with
            // similar names are still tellable apart.
            'note' => ops_api_place_type_label((string)$row['area_type']),
        ];
    }

    $stmt = $conn->prepare("
        SELECT id, building_id, unit_number, unit_type
        FROM re_units
        WHERE building_id IN ($in)
        ORDER BY unit_number ASC
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $buildings[(int)$row['building_id']]['places'][] = [
            'kind' => 'unit',
            'id' => (int)$row['id'],
            'label' => (string)$row['unit_number'],
            'note' => (string)($row['unit_type'] ?? ''),
        ];
    }

    customer_api_send_ok(['buildings' => array_values($buildings)]);
}

/** `pump_room` is not a word. Make the enum readable without a lookup table. */
function ops_api_place_type_label(string $type): string
{
    return ucwords(str_replace('_', ' ', $type));
}

/**
 * Turn what the app sent into place rows, refusing anything that is not real.
 *
 * Every job must name at least one actual unit or common area — no more free
 * text standing in for a location. So this is a gate, not a filter: an id that
 * does not exist, or belongs to another company's building, fails the whole
 * create rather than quietly dropping one place off a job the person believes
 * they described correctly.
 *
 * The label is read from the database here, never from the request. The app
 * could send anything; what goes on the record is what the place is actually
 * called.
 *
 * @return array<int, array{kind:string,id:int,building_id:?int,label:string}>
 */
function ops_api_resolve_places(PDO $conn, int $companyId, $input): array
{
    if (!is_array($input) || $input === []) {
        customer_api_send_error('place_required', 'Choose where the job is.', 422);
    }

    $wanted = ['unit' => [], 'common_area' => []];
    foreach ($input as $entry) {
        $kind = is_array($entry) ? (string)($entry['kind'] ?? '') : '';
        $id = is_array($entry) ? (int)($entry['id'] ?? 0) : 0;
        if (!array_key_exists($kind, $wanted) || $id <= 0) {
            customer_api_send_error('place_unknown', 'That place is not on the system.', 422);
        }
        $wanted[$kind][$id] = $id;
    }

    $resolved = [];

    if ($wanted['unit']) {
        $in = implode(',', array_fill(0, count($wanted['unit']), '?'));
        $stmt = $conn->prepare("
            SELECT u.id, u.building_id, u.unit_number, b.name AS building_name
            FROM re_units u
            JOIN re_buildings b ON b.id = u.building_id
            WHERE u.id IN ($in) AND b.company_id = ?
        ");
        $stmt->execute(array_merge(array_values($wanted['unit']), [$companyId]));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $resolved[] = [
                'kind' => 'unit',
                'id' => (int)$row['id'],
                'building_id' => (int)$row['building_id'],
                // Qualified by its building: "101" on its own is four different
                // flats, and the office reads this on a list covering all of them.
                'label' => trim((string)$row['building_name'] . ' — ' . (string)$row['unit_number']),
            ];
        }
    }

    if ($wanted['common_area']) {
        $in = implode(',', array_fill(0, count($wanted['common_area']), '?'));
        $stmt = $conn->prepare("
            SELECT a.id, a.building_id, a.area_name, b.name AS building_name
            FROM re_building_common_areas a
            JOIN re_buildings b ON b.id = a.building_id
            WHERE a.id IN ($in) AND b.company_id = ?
        ");
        $stmt->execute(array_merge(array_values($wanted['common_area']), [$companyId]));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $resolved[] = [
                'kind' => 'common_area',
                'id' => (int)$row['id'],
                'building_id' => (int)$row['building_id'],
                'label' => trim((string)$row['building_name'] . ' — ' . (string)$row['area_name']),
            ];
        }
    }

    // Fewer came back than went in: at least one id is invented, inactive, or
    // another company's. Say so rather than creating a job that covers less
    // than the person thinks it does.
    if (count($resolved) !== count($wanted['unit']) + count($wanted['common_area'])) {
        customer_api_send_error('place_unknown', 'That place is not on the system.', 422);
    }

    return $resolved;
}

// ---------------------------------------------------------------------------
// GET ops/attendance, POST ops/attendance/check-in and /check-out
// ---------------------------------------------------------------------------

/**
 * The day's attendance, and the two taps that record it — into HR's own
 * attendance table. See modules/operations/includes/ops_attendance.php.
 *
 * Never queued and never timed by the phone: the time written is the server's,
 * at the moment the tap arrives, because it is pay.
 */
function ops_api_handle_attendance(PDO $conn, array $user, string $action): void
{
    $employee = ops_attendance_employee($conn, (int)$user['id']);
    if (!$employee) {
        customer_api_send_error(
            'no_employee',
            'You are not set up in HR yet, so attendance cannot be recorded. Ask HR.',
            409
        );
    }

    if ($action === 'check-in') {
        $result = ops_attendance_check_in($conn, $employee, (int)$user['id']);
    } elseif ($action === 'check-out') {
        $result = ops_attendance_check_out($conn, $employee, (int)$user['id']);
    } else {
        $result = ['ok' => true, 'row' => ops_attendance_current($conn, (int)$employee['id'])];
    }

    if (!$result['ok']) {
        customer_api_send_error($result['error'], $result['message'], 409);
    }

    customer_api_send_ok(['attendance' => ops_attendance_payload($result['row'] ?? null)]);
}

// ---------------------------------------------------------------------------
// POST ops/jobs — the person on site raises their own job
// ---------------------------------------------------------------------------

/**
 * Create a job for the person who is signed in.
 *
 * WHY THIS EXISTS
 * ---------------
 * The office used to enter every job in advance and hand it to somebody. That
 * only works if you know on Monday where each person will be on Thursday, and
 * you do not — staff get moved between sites all week, so the schedule was
 * wrong by Tuesday and somebody had to go and fix it. This turns the order
 * round: whoever is standing at the site raises the job, does it, and submits
 * it. Nothing has to be predicted, so nothing has to be corrected.
 *
 * WHAT THE REQUEST MAY NOT DECIDE
 * -------------------------------
 * `assigned_to` is the entire access rule in this module — ops_api_load_own_job()
 * has no other condition — so it is set from the token here and read from the
 * body nowhere. A staff member can create work for themselves and for nobody
 * else, and no request body can say otherwise.
 *
 * `status` is always 'open' rather than 'in_progress'. Creating a job is not
 * starting it: the Before photos still have to go on first, and the start route
 * still enforces that. The clock belongs to the person on site, and it begins
 * when they say so.
 *
 * Priority and the daily repeat are absent on purpose. Both are the office's to
 * set, and both are the sort of standing decision this change exists to stop
 * making in advance.
 *
 * WHERE, AS A FACT RATHER THAN A SENTENCE
 * ---------------------------------------
 * Every job must name at least one real unit or common area. The old free-text
 * location is still written for anything worth adding on top ("bin store round
 * the back"), but it can no longer BE the answer — see
 * migrations/ops_job_places.sql for what that text cost. If a place is missing
 * from the picker the office adds it once in the real estate module, and it is
 * there for everyone from then on.
 */
function ops_api_handle_job_create(PDO $conn, array $user): void
{
    $title = trim((string)(ops_api_param('title', '') ?? ''));
    if ($title === '') {
        customer_api_send_error('title_required', 'Give the job a short name.', 422);
    }

    $jobType = (string)(ops_api_param('job_type', 'cleaning') ?? 'cleaning');
    if (!array_key_exists($jobType, ops_job_types())) {
        $jobType = 'cleaning';
    }

    $location = trim((string)(ops_api_param('location', '') ?? ''));
    $description = trim((string)(ops_api_param('description', '') ?? ''));

    // Cut to what the columns hold rather than letting MySQL truncate or, in
    // strict mode, refuse the whole insert over a long address.
    $title = mb_substr($title, 0, 255);
    $location = mb_substr($location, 0, 255);

    // The company is settled before the places are, because a place is only
    // valid relative to it — see ops_api_job_company_id().
    $companyId = ops_api_job_company_id($conn, $user);
    if ($companyId <= 0) {
        customer_api_send_error('no_company', 'This account is not set up for jobs.', 409);
    }

    $places = ops_api_resolve_places($conn, $companyId, ops_api_param('places', []));

    // Validation first, replay guard second — same order as start and finish,
    // and for the same reason: a refusal the person can fix must not burn the
    // request id, or their corrected retry comes back "duplicate" and they are
    // told their empty job was created.
    $requestId = ops_api_request_id();
    if (!ops_api_claim_request($conn, $user, 'jobs')) {
        ops_api_log('replay ignored jobs create');

        // The id the first attempt made. This is the whole reason the request
        // row carries one: without it the phone is told nothing useful and the
        // person taps Create again on a job that already exists.
        $priorId = ops_api_request_job_id($conn, $requestId);
        $prior = $priorId > 0 ? ops_api_load_own_job($conn, $priorId, $user) : null;
        if (!$prior) {
            customer_api_send_ok(['duplicate' => true]);
        }
        customer_api_send_ok([
            'job' => ops_api_job_detail($conn, $prior, $user),
            'duplicate' => true,
        ]);
    }

    // PHP's clock, not MySQL's, for the date and the stamp both: the live MySQL
    // runs on UTC while PHP and every page that shows these times run on Dubai
    // time. A job raised late in the evening would otherwise be filed as
    // tomorrow's and drop off today's list on the phone that made it.
    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

    $stmt = $conn->prepare("
        INSERT INTO ops_jobs
            (company_id, job_type, title, location, description, assigned_to,
             scheduled_date, priority, status, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'normal', 'open', ?, ?, ?)
    ");
    $stmt->execute([
        $companyId, $jobType, $title, $location ?: null, $description ?: null,
        $user['id'], $today, $user['id'], $now, $now,
    ]);

    $jobId = (int)$conn->lastInsertId();

    // The places, with the labels read from the database rather than the
    // request. IGNORE covers the one way this can collide — the same place
    // sent twice — which is a picker slip, not an error worth failing a job
    // that is already inserted.
    $insPlace = $conn->prepare("
        INSERT IGNORE INTO ops_job_places
            (job_id, company_id, place_kind, place_id, building_id, label, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($places as $place) {
        $insPlace->execute([
            $jobId, $companyId, $place['kind'], $place['id'],
            $place['building_id'], $place['label'], $now,
        ]);
    }

    ops_api_record_request_job($conn, $requestId, $jobId);

    $job = ops_api_load_own_job($conn, $jobId, $user);
    if (!$job) {
        // Nothing should be able to reach this: it was just inserted with this
        // person's id on it, which is exactly what the loader matches on.
        customer_api_send_error('server_error', 'The job could not be opened.', 500);
    }

    customer_api_send_ok(['job' => ops_api_job_detail($conn, $job, $user)]);
}

// ---------------------------------------------------------------------------
// POST ops/jobs/{id}/claim — "I'll do it" on a tenant request in the pool
// ---------------------------------------------------------------------------

/**
 * Take a job from the pool.
 *
 * Several phones see the same request, so two people can tap at once. The
 * claim is one conditional UPDATE — nobody on it yet, still open, one of this
 * person's companies — and the database decides who was first. The loser is
 * told plainly, rather than both people driving to the same flat.
 *
 * Straight to the server, never queued: whether you got the job is the whole
 * answer, and a queued claim would let somebody walk to a door believing it
 * was theirs while it went to someone else.
 */
function ops_api_handle_job_claim(PDO $conn, array $user, int $jobId): void
{
    $companyIn = ops_api_company_in($user['company_ids']);
    $load = $conn->prepare("
        SELECT * FROM ops_jobs
        WHERE id = ? AND source_type <> 'staff' AND company_id IN ($companyIn)
        LIMIT 1
    ");
    $load->execute(array_merge([$jobId], $user['company_ids']));
    $job = $load->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        customer_api_send_error('not_found', 'That request is no longer available.', 404);
    }

    // A retry of a claim that worked. Answer with the job, as a success.
    if ((int)$job['assigned_to'] === (int)$user['id']) {
        $own = ops_api_job_or_404($conn, $jobId, $user);
        customer_api_send_ok(['job' => ops_api_job_detail($conn, $own, $user)]);
    }

    if ($job['assigned_to'] !== null || $job['status'] !== 'open') {
        customer_api_send_error(
            'already_claimed',
            $job['status'] === 'cancelled'
                ? 'This request was withdrawn.'
                : 'Someone else already took this job.',
            409
        );
    }

    // In the pool a maintenance job is dated the day it was copied. Claimed a
    // day later, that date would put it straight onto the Late tab of the
    // person who just volunteered for it. A cleaning booking keeps its date:
    // that one is the tenant's appointment.
    $today = date('Y-m-d');
    $stmt = $conn->prepare("
        UPDATE ops_jobs
        SET assigned_to = ?,
            scheduled_date = CASE
                WHEN source_type = 'tenant_maintenance' AND scheduled_date < ? THEN ?
                ELSE scheduled_date
            END,
            updated_at = ?
        WHERE id = ? AND assigned_to IS NULL AND status = 'open'
    ");
    $stmt->execute([$user['id'], $today, $today, date('Y-m-d H:i:s'), $jobId]);

    if ($stmt->rowCount() === 0) {
        // Somebody's UPDATE landed between our read and ours.
        customer_api_send_error('already_claimed', 'Someone else already took this job.', 409);
    }

    ops_source_on_claim($conn, $job, (int)$user['id']);

    $own = ops_api_job_or_404($conn, $jobId, $user);
    customer_api_send_ok(['job' => ops_api_job_detail($conn, $own, $user)]);
}

// ---------------------------------------------------------------------------
// POST ops/jobs/{id}/start — the only way a job starts; the web module has
// no start button, the clock belongs to the person on site.
// ---------------------------------------------------------------------------

function ops_api_handle_job_start(PDO $conn, array $user, int $jobId): void
{
    $job = ops_api_job_or_404($conn, $jobId, $user);

    // No job starts on an empty record. The Before photos are the only proof
    // of what the site looked like before anyone touched it, and once work
    // begins that state is gone and cannot be photographed again — which is
    // also why Before photos lock the moment the job starts. Checked here and
    // not only in the app: a start queued offline is replayed later, and by
    // then whatever the screen showed is history.
    //
    // Before the replay guard, not after: this is the one refusal the person
    // can fix and retry. Burning the request id on it would turn the retry
    // into a "duplicate" that answers with an unstarted job and looks like
    // success.
    if ((int)($job['before_photo_count'] ?? 0) === 0) {
        customer_api_send_error(
            'photo_required',
            'Add at least one Before photo or video before starting this job.',
            409
        );
    }

    ops_api_guard_replay($conn, $user, $jobId, "jobs/$jobId/start");

    if ($job['status'] === 'done' || $job['status'] === 'cancelled') {
        customer_api_send_error('job_closed', 'This job is already closed.', 409);
    }

    // PHP's clock, not MySQL's NOW(): the live MySQL runs on UTC while PHP and
    // every page that displays these times run on Dubai time.
    $stmt = $conn->prepare("
        UPDATE ops_jobs
        SET status = 'in_progress',
            started_at = COALESCE(started_at, ?)
        WHERE id = ? AND company_id = ? AND assigned_to = ?
    ");
    $stmt->execute([date('Y-m-d H:i:s'), $jobId, (int)$job['company_id'], $user['id']]);

    $fresh = ops_api_job_or_404($conn, $jobId, $user);
    customer_api_send_ok(['job' => ops_api_job_detail($conn, $fresh, $user)]);
}

// ---------------------------------------------------------------------------
// POST ops/jobs/{id}/pause and /resume — stopping for a while, not for good
// ---------------------------------------------------------------------------

/**
 * Pause a started job, with a reason.
 *
 * The job stays in progress and its clock keeps running — time paused counts
 * in the duration and in what the job bills. What a pause adds is the fact,
 * visible to the office at once: this job has stopped, since when, and why.
 * Every pause is also kept in ops_job_pauses, so a job that stopped three times
 * waiting for materials says so afterwards.
 *
 * Queued like start and finish, so it works with no signal. The reason is
 * checked before the replay guard — a refusal the person can fix must not burn
 * the request id — and the state after it, so a retry of a pause that already
 * landed answers with the paused job rather than an error.
 */
function ops_api_handle_job_pause(PDO $conn, array $user, int $jobId): void
{
    $job = ops_api_job_or_404($conn, $jobId, $user);

    $reason = (string)(ops_api_param('reason', '') ?? '');
    if (!array_key_exists($reason, ops_pause_reasons())) {
        customer_api_send_error('reason_required', 'Choose why you are pausing.', 422);
    }

    ops_api_guard_replay($conn, $user, $jobId, "jobs/$jobId/pause");

    if ($job['status'] !== 'in_progress') {
        customer_api_send_error('not_started', 'Only a started job can be paused.', 409);
    }
    if (!empty($job['paused_at'])) {
        // Already paused: the answer is the job as it is.
        customer_api_send_ok(['job' => ops_api_job_detail($conn, $job, $user)]);
    }

    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("
        UPDATE ops_jobs SET paused_at = ?, pause_reason = ?
        WHERE id = ? AND assigned_to = ? AND status = 'in_progress' AND paused_at IS NULL
    ");
    $stmt->execute([$now, $reason, $jobId, $user['id']]);

    if ($stmt->rowCount() > 0) {
        $conn->prepare("
            INSERT INTO ops_job_pauses (job_id, company_id, user_id, reason, paused_at)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$jobId, (int)$job['company_id'], $user['id'], $reason, $now]);
    }

    $fresh = ops_api_job_or_404($conn, $jobId, $user);
    customer_api_send_ok(['job' => ops_api_job_detail($conn, $fresh, $user)]);
}

function ops_api_handle_job_resume(PDO $conn, array $user, int $jobId): void
{
    $job = ops_api_job_or_404($conn, $jobId, $user);

    ops_api_guard_replay($conn, $user, $jobId, "jobs/$jobId/resume");

    if (empty($job['paused_at'])) {
        // Not paused — most likely a retried resume that already landed.
        customer_api_send_ok(['job' => ops_api_job_detail($conn, $job, $user)]);
    }

    ops_job_close_pause($conn, $jobId, date('Y-m-d H:i:s'));

    $fresh = ops_api_job_or_404($conn, $jobId, $user);
    customer_api_send_ok(['job' => ops_api_job_detail($conn, $fresh, $user)]);
}

// ---------------------------------------------------------------------------
// POST ops/jobs/{id}/finish — the only way a job finishes. The office can
// still force a status with "Change status", but that records no duration.
// ---------------------------------------------------------------------------

function ops_api_handle_job_finish(PDO $conn, array $user, int $jobId): void
{
    $job = ops_api_job_or_404($conn, $jobId, $user);

    // The mirror of the Before rule on start, and for the mirror reason. The
    // After photos are the only proof of what was left behind, and once the
    // person has locked up and walked away that state is gone — nobody drives
    // back to photograph a room they have already finished. A job closed with
    // no record of the result is a job the office cannot answer a complaint
    // about.
    //
    // Before the replay guard, like start's: this is a refusal the person can
    // fix and retry, and burning the request id on it would turn the retry
    // into a "duplicate" that answers with an unfinished job and reads as
    // success.
    //
    // Every finish, not only the ones that were started properly. Saying the
    // work is done is the claim that needs evidence, and a job dragged straight
    // from open to done with nothing attached is exactly the record the office
    // cannot defend. A job that is ALREADY done is left to the replay guard
    // below, so a retry of a legitimate finish still answers "duplicate".
    if ($job['status'] !== 'done' && (int)($job['after_photo_count'] ?? 0) === 0) {
        customer_api_send_error(
            'photo_required',
            'Add at least one After photo or video before finishing this job.',
            409
        );
    }

    ops_api_guard_replay($conn, $user, $jobId, "jobs/$jobId/finish");

    if ($job['status'] === 'done') {
        customer_api_send_error('job_closed', 'This job is already completed.', 409);
    }

    // Finishing a paused job is allowed — people forget to resume — and it
    // closes the pause at the same moment, so the history has no pause that
    // never ended.
    if (!empty($job['paused_at'])) {
        ops_job_close_pause($conn, $jobId, date('Y-m-d H:i:s'));
    }

    $notes = trim((string)(ops_api_param('completion_notes', '') ?? ''));
    // A job finished without ever being started counts as zero minutes
    // rather than failing.
    // PHP's clock for both ends, same reason as start.
    $now = date('Y-m-d H:i:s');
    $startedAt = $job['started_at'] ?: $now;
    $minutes = max(0, (int)round((strtotime($now) - strtotime((string)$startedAt)) / 60));

    $stmt = $conn->prepare("
        UPDATE ops_jobs
        SET status = 'done',
            started_at = COALESCE(started_at, ?),
            finished_at = ?,
            duration_minutes = ?,
            completion_notes = COALESCE(NULLIF(?, ''), completion_notes)
        WHERE id = ? AND company_id = ? AND assigned_to = ?
    ");
    $stmt->execute([$now, $now, $minutes, $notes, $jobId, (int)$job['company_id'], $user['id']]);

    // A tenant's job closes their request too, and tells them. Then the job
    // invoices itself — see modules/operations/includes/ops_billing.php. Both
    // only on the finish that actually closed it, and neither can undo it: the
    // work is done whatever happens to the paperwork.
    if ($stmt->rowCount() > 0) {
        ops_source_on_finish($conn, $job);
        ops_bill_finished_job($conn, $jobId);
    }

    $fresh = ops_api_job_or_404($conn, $jobId, $user);
    customer_api_send_ok([
        'job' => ops_api_job_detail($conn, $fresh, $user),
        'duration_label' => ops_format_duration($minutes),
    ]);
}

// ---------------------------------------------------------------------------
// POST ops/jobs/{id}/photos — multipart, one photo per request
// ---------------------------------------------------------------------------

function ops_api_handle_job_photo(PDO $conn, array $user, int $jobId): void
{
    $job = ops_api_job_or_404($conn, $jobId, $user);

    ops_api_guard_replay($conn, $user, $jobId, "jobs/$jobId/photos");

    $photoType = (string)($_POST['photo_type'] ?? 'before');
    if (!in_array($photoType, ['before', 'after'], true)) {
        customer_api_send_error('validation_error', 'Photo must be marked Before or After.', 400);
    }

    if ($job['status'] === 'done' || $job['status'] === 'cancelled') {
        customer_api_send_error(
            'job_closed',
            'This job is finished, so photos cannot be added to it.',
            409
        );
    }

    // Each phase takes one kind of photo, and only one.
    //
    // An After photo before the job starts would let an untouched room be
    // filed as the finished result. A Before photo after work has begun is
    // just as wrong the other way: the state it claims to record is already
    // gone. The app shows one section at a time, but the app is not what
    // makes this true.
    if ($photoType === 'after' && $job['status'] === 'open') {
        customer_api_send_error(
            'not_started',
            'Start the job before adding After photos.',
            409
        );
    }
    if ($photoType === 'before' && $job['status'] !== 'open') {
        customer_api_send_error(
            'already_started',
            'Before photos can only be added while the job has not started.',
            409
        );
    }

    // Field name stays 'photo' whether it holds a still or a clip: the app's
    // send queue has shipped with it, and a queued item written by an older
    // build must still deliver after an update.
    $file = $_FILES['photo'] ?? null;
    if (!$file || !isset($file['tmp_name']) || is_array($file['tmp_name'])) {
        customer_api_send_error('validation_error', 'No photo was chosen.', 400);
    }

    // File writing, extension checks and the DB row all belong to the helper.
    $result = ops_store_photo(
        $conn,
        (int)$job['company_id'],
        $jobId,
        $file,
        $photoType,
        $user['id']
    );
    if (!$result['ok']) {
        customer_api_send_error('upload_failed', (string)$result['error'], 400);
    }

    $stmt = $conn->prepare("
        SELECT id, photo_type, media_kind, caption, created_at,
               (uploaded_by = ?) AS is_mine
        FROM ops_job_photos
        WHERE job_id = ? AND company_id = ? AND file_path = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$user['id'], $jobId, (int)$job['company_id'], $result['file_path']]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);

    // The job's status has to go in, or can_delete falls back to the locked
    // default and the response contradicts what the delete route will allow.
    //
    // The refreshed job goes back too: this upload may be the one that unlocks
    // Start, and the app should not need a second round trip to find out.
    $fresh = ops_api_job_or_404($conn, $jobId, $user);
    customer_api_send_ok([
        'photo' => $photo ? ops_api_photo_row($photo, (string)$job['status']) : null,
        'job' => ops_api_job_detail($conn, $fresh, $user),
    ], 201);
}

// ---------------------------------------------------------------------------
// GET ops/photos/{id} — copied from modules/operations/photo.php
// ---------------------------------------------------------------------------

/**
 * Serve one piece of evidence, photo or video. The containment check and the
 * extension-whitelist Content-Type are deliberately identical to
 * modules/operations/photo.php: the resolved path must sit inside
 * uploads/operations, and the type is never sniffed from the file's own bytes.
 */
function ops_api_handle_photo_serve(PDO $conn, array $user, int $photoId): void
{
    // No company filter here: the own-job check below is the access rule, and
    // scoping the row by the viewer's user_companies only hid evidence on their
    // own jobs when the job sat under a sister company.
    $stmt = $conn->prepare("
        SELECT p.file_path, p.job_id
        FROM ops_job_photos p
        WHERE p.id = ?
        LIMIT 1
    ");
    $stmt->execute([$photoId]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);

    // Own-job check applied here, not assumed from ops_load_job().
    if (!$photo || !ops_api_load_own_job($conn, (int)$photo['job_id'], $user)) {
        customer_api_send_error('not_found', 'That photo is not on your list.', 404);
    }

    $appRoot = dirname(__DIR__, 3);
    $baseDir = realpath($appRoot . '/uploads/operations');
    $absPath = realpath($appRoot . '/' . ltrim((string)$photo['file_path'], '/'));
    if (
        $baseDir === false || $absPath === false
        || strpos($absPath, $baseDir . DIRECTORY_SEPARATOR) !== 0
        || !is_file($absPath)
    ) {
        customer_api_send_error('not_found', 'That photo could not be found.', 404);
    }

    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    $contentType = ops_comment_media_content_type($ext);
    // Audio is servable by that shared whitelist but is never before/after
    // evidence, so it is not reachable through this route.
    if ($contentType === null || strpos($contentType, 'audio/') === 0) {
        customer_api_send_error('not_found', 'That photo could not be found.', 404);
    }

    header('Content-Disposition: inline; filename="' . basename($absPath) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=600');
    // Ranges: the phone's video player seeks, and will not scrub a clip served
    // as one 200 with the lot.
    ops_serve_file_with_ranges($absPath, $contentType);
    exit;
}

// ---------------------------------------------------------------------------
// POST ops/jobs/{id}/comments — mirrors job_action.php case 'add_comment'
// ---------------------------------------------------------------------------

/**
 * A message is text, a photo, or a voice note — and text is the fallback, not
 * the main path.
 *
 * The people using this app are cleaners and technicians in Dubai, many of whom
 * cannot write English but can photograph a broken tap or say what is wrong out
 * loud. So `comment` being empty is not an error here as long as something is
 * attached; it is the normal case.
 *
 * JSON body for a text-only message, multipart when there is a file. Both are
 * read the same way — ops_api_param() already falls back to $_POST — so the app
 * has one code path for a message whether or not it carries an attachment.
 *
 * SEVERAL FILES, ONE MESSAGE
 * --------------------------
 * One request still carries at most one file. When a message has several, the
 * phone sends one request per file, all naming the same `client_group_id`: the
 * first to arrive creates the comment, the rest find it and attach to it. Each
 * file therefore retries and lands on its own, which is the only way a message
 * containing a 25 MB video survives one bar of 3G — and it is what every
 * messaging app already does, so files appearing one by one surprises nobody.
 * See migrations/ops_comment_media_multi.sql.
 *
 * A message photo is NOT a before/after photo. It goes to
 * ops_job_comment_media, not ops_job_photos, and none of the phase rules that
 * govern the evidence photos apply: you can send a picture of a problem at any
 * point in a job, which is exactly when problems turn up.
 *
 * ASKING FOR MATERIALS
 * --------------------
 * `is_material_request` marks a message as "I need something". That flag is
 * everything the phone says about materials — there is deliberately no name, no
 * quantity and no unit, because asking a cleaner in a stairwell to spell a
 * product and name its unit is what stopped people using the old form. They say
 * it out loud, or photograph the empty bottle.
 *
 * There is no structured record on the other side either. The flag sets
 * ops_jobs.needs_materials, which puts the job on the supervisor's waiting
 * list; the office reads the message, hands the thing over, and takes it off
 * the Stock page. That stock movement is the only record kept.
 */
function ops_api_handle_job_comment(PDO $conn, array $user, int $jobId): void
{
    $job = ops_api_job_or_404($conn, $jobId, $user);
    ops_api_guard_replay($conn, $user, $jobId, "jobs/$jobId/comments");
    $companyId = (int)$job['company_id'];

    $comment = trim((string)(ops_api_param('comment', '') ?? ''));

    $kind = trim((string)(ops_api_param('media_kind', '') ?? ''));
    $file = $_FILES['media'] ?? null;
    $hasFile = is_array($file) && isset($file['tmp_name']) && !is_array($file['tmp_name'])
        && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($hasFile && !in_array($kind, ['photo', 'voice', 'video'], true)) {
        customer_api_send_error('validation_error', 'That kind of attachment is not allowed.', 400);
    }
    if (!$hasFile && $comment === '') {
        customer_api_send_error('validation_error', 'Type a message first.', 400);
    }

    $duration = ops_api_param('duration_seconds', null);
    $duration = $duration === null || $duration === '' ? null : (int)$duration;

    $isMaterialRequest = in_array(
        (string)(ops_api_param('is_material_request', '') ?? ''),
        ['1', 'true', 'yes', 'on'],
        true
    );

    $groupId = trim((string)(ops_api_param('client_group_id', '') ?? ''));
    if (strlen($groupId) > 64) {
        customer_api_send_error('validation_error', 'That message could not be sent.', 400);
    }

    // The comment row and its attachment are one message. A comment saved
    // without the file it was supposed to carry reads as a blank line in the
    // office's thread and there is nothing to retry against, so if the file
    // will not store, no message is written at all and the queue can send the
    // whole thing again.
    $conn->beginTransaction();
    try {
        $commentId = ops_api_find_or_create_comment(
            $conn,
            $jobId,
            $companyId,
            (int)$user['id'],
            $comment,
            $isMaterialRequest,
            $groupId
        );

        // The flag the supervisor's waiting list reads.
        if ($isMaterialRequest) {
            $conn->prepare("UPDATE ops_jobs SET needs_materials = 1 WHERE id = ? AND company_id = ?")
                 ->execute([$jobId, $companyId]);
        }

        if ($hasFile) {
            $stored = ops_store_comment_media(
                $conn,
                $companyId,
                $jobId,
                $commentId,
                $file,
                $kind,
                $user['id'],
                $duration
            );
            if (!$stored['ok']) {
                $conn->rollBack();
                customer_api_send_error('upload_failed', (string)$stored['error'], 400);
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    $fresh = ops_api_job_or_404($conn, $jobId, $user);
    customer_api_send_ok(['job' => ops_api_job_detail($conn, $fresh, $user)], 201);
}

/**
 * The comment this upload belongs to, creating it if this is the first to land.
 *
 * With no group id every request is its own message, exactly as before.
 *
 * With one, the message is whatever `(job_id, client_group_id)` names. A unique
 * index makes that true rather than hoped for: two requests racing to create the
 * same message means one INSERT fails on the duplicate key, and that one then
 * reads the row the winner wrote.
 *
 * The text is carried on every request in a group, not just the first, so a
 * message keeps its words even if the request that happened to arrive first is
 * the one that could never be delivered. It is only ever filled in, never
 * overwritten — the office must not watch a message rewrite itself.
 */
function ops_api_find_or_create_comment(
    PDO $conn,
    int $jobId,
    int $companyId,
    int $userId,
    string $comment,
    bool $isMaterialRequest,
    string $groupId
): int {
    $insert = function () use ($conn, $jobId, $companyId, $userId, $comment, $isMaterialRequest, $groupId): int {
        $stmt = $conn->prepare("
            INSERT INTO ops_job_comments
                (job_id, company_id, user_id, comment, is_material_request, client_group_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $jobId, $companyId, $userId, $comment,
            $isMaterialRequest ? 1 : 0,
            $groupId !== '' ? $groupId : null,
        ]);
        return (int)$conn->lastInsertId();
    };

    if ($groupId === '') {
        return $insert();
    }

    $find = $conn->prepare("
        SELECT id, comment
        FROM ops_job_comments
        WHERE job_id = ? AND company_id = ? AND client_group_id = ?
        LIMIT 1
    ");
    $find->execute([$jobId, $companyId, $groupId]);
    $existing = $find->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        try {
            return $insert();
        } catch (PDOException $e) {
            // 23000 is the duplicate-key class: another request for this same
            // message won the race. Read what it wrote.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            $find->execute([$jobId, $companyId, $groupId]);
            $existing = $find->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                throw $e;
            }
        }
    }

    $commentId = (int)$existing['id'];

    // Fill in words that arrived late; never replace words already there.
    if ($comment !== '' && (string)$existing['comment'] === '') {
        $conn->prepare("UPDATE ops_job_comments SET comment = ? WHERE id = ?")
             ->execute([$comment, $commentId]);
    }
    if ($isMaterialRequest) {
        $conn->prepare("UPDATE ops_job_comments SET is_material_request = 1 WHERE id = ?")
             ->execute([$commentId]);
    }

    return $commentId;
}

// ---------------------------------------------------------------------------
// GET ops/comment-media/{id} — the same protections as ops/photos/{id}
// ---------------------------------------------------------------------------

/**
 * Serve one message attachment.
 *
 * Identical protections to ops_api_handle_photo_serve(), for the same reasons:
 * the resolved path must sit inside uploads/operations, and the Content-Type
 * comes from an extension whitelist and is never sniffed from the bytes. A
 * file that could talk this route into returning text/html or image/svg+xml
 * would be script running on this origin.
 *
 * Audio extensions were added to that whitelist deliberately — see
 * ops_comment_media_content_type() in ops_helper.php.
 */
function ops_api_handle_comment_media_serve(PDO $conn, array $user, int $mediaId): void
{
    // Same as ops_api_handle_photo_serve(): own-job is the check, company is not.
    $stmt = $conn->prepare("
        SELECT m.file_path, m.job_id
        FROM ops_job_comment_media m
        WHERE m.id = ?
        LIMIT 1
    ");
    $stmt->execute([$mediaId]);
    $media = $stmt->fetch(PDO::FETCH_ASSOC);

    // Own-job check applied here, not assumed from ops_load_job().
    if (!$media || !ops_api_load_own_job($conn, (int)$media['job_id'], $user)) {
        customer_api_send_error('not_found', 'That message is not on your list.', 404);
    }

    $appRoot = dirname(__DIR__, 3);
    $baseDir = realpath($appRoot . '/uploads/operations');
    $absPath = realpath($appRoot . '/' . ltrim((string)$media['file_path'], '/'));
    if (
        $baseDir === false || $absPath === false
        || strpos($absPath, $baseDir . DIRECTORY_SEPARATOR) !== 0
        || !is_file($absPath)
    ) {
        customer_api_send_error('not_found', 'That message could not be found.', 404);
    }

    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    $contentType = ops_comment_media_content_type($ext);
    if ($contentType === null) {
        customer_api_send_error('not_found', 'That message could not be found.', 404);
    }

    // Byte ranges and chunked reads live in the helper, shared with the web
    // module's copy of this route so the two cannot drift.
    ops_serve_file_with_ranges($absPath, $contentType);
}

// ---------------------------------------------------------------------------
// DELETE ops/jobs/{id}/photos/{photoId} — the only way a photo is ever removed
// ---------------------------------------------------------------------------

/**
 * Remove one photo, file and row together.
 *
 * The office cannot delete photos at all — the web module only shows them — so
 * this is the single door. The before/after pair is the evidence the work
 * happened, so the rule in ops_api_photo_deletable() applies: you may only
 * remove your own photo, and only of the phase the job is currently in.
 *
 * The practical effect is that a Before photo locks the instant work starts —
 * by then the "before" state is gone and could never be photographed again —
 * and everything locks when the job is finished.
 *
 * Once a photo locks, nobody removes it — not the staff member, not the office.
 */
function ops_api_handle_photo_delete(PDO $conn, array $user, int $jobId, int $photoId): void
{
    $job = ops_api_job_or_404($conn, $jobId, $user);
    ops_api_guard_replay($conn, $user, $jobId, "jobs/$jobId/photos/$photoId/delete");

    $stmt = $conn->prepare("
        SELECT id, photo_type, file_path, uploaded_by
        FROM ops_job_photos
        WHERE id = ? AND job_id = ? AND company_id = ?
        LIMIT 1
    ");
    $stmt->execute([$photoId, $jobId, (int)$job['company_id']]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$photo) {
        customer_api_send_error('not_found', 'That photo could not be found.', 404);
    }

    $isMine = (int)$photo['uploaded_by'] === $user['id'];
    $status = (string)$job['status'];
    $photoType = (string)$photo['photo_type'];

    // The same rule the app used to decide whether to show a Delete button,
    // re-checked here so the app's answer is never what actually permits it.
    if (!ops_api_photo_deletable($status, $photoType, $isMine)) {
        if (!$isMine) {
            customer_api_send_error(
                'not_yours',
                'You can only remove photos you took yourself.',
                403
            );
        }
        if ($status === 'done' || $status === 'cancelled') {
            customer_api_send_error(
                'job_closed',
                'This job is finished, so its photos cannot be changed. Ask the office if one is wrong.',
                409
            );
        }
        // Right person, job still live, wrong phase: a Before photo once the
        // job started, or an After photo before it did. Word it by the rule,
        // not by when the photo was taken — a Before photo added mid-job is
        // still locked, and saying it "was taken before the job started"
        // would be a lie in exactly that case.
        customer_api_send_error(
            'photo_locked',
            $photoType === 'before'
                ? 'Before photos can only be removed while the job has not started.'
                : 'After photos can only be removed once the job has started.',
            409
        );
    }

    // Keep the resolved path inside the uploads folder before unlinking, the
    // same containment check the serving route makes. A file_path that escapes
    // it must never lead to a delete outside uploads/operations.
    $appRoot = dirname(__DIR__, 3);
    $baseDir = realpath($appRoot . '/uploads/operations');
    $absPath = realpath($appRoot . '/' . ltrim((string)$photo['file_path'], '/'));
    if (
        $baseDir !== false && $absPath !== false
        && strpos($absPath, $baseDir . DIRECTORY_SEPARATOR) === 0
        && is_file($absPath)
    ) {
        @unlink($absPath);
    }

    $conn->prepare("DELETE FROM ops_job_photos WHERE id = ?")->execute([$photoId]);

    $fresh = ops_api_job_or_404($conn, $jobId, $user);
    customer_api_send_ok(['job' => ops_api_job_detail($conn, $fresh, $user)]);
}
