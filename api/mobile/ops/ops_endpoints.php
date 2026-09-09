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
 * `today`    — dated today and still to be done, and nothing else. A job that
 *              ran past its date is not today's work, so it drops off this
 *              list rather than piling up on it. Finished and cancelled work
 *              drops out too; the person finds it again under `done`.
 * `upcoming` — dated after today and still to be done.
 * `done`     — completed, newest first, whatever the date.
 *
 * @return array{0:string,1:array} SQL fragment and its bound values
 */
function ops_api_tab_filter(string $tab, string $today): array
{
    switch ($tab) {
        case 'upcoming':
            return [" AND j.scheduled_date > ? AND j.status IN ('open','in_progress')", [$today]];
        case 'done':
            return [" AND j.status = 'done'", []];
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
    // Timed work first, in clock order; "any time" jobs after it.
    return ' ORDER BY j.scheduled_date ASC, j.scheduled_time IS NULL, j.scheduled_time ASC, j.id ASC';
}

function ops_api_handle_jobs_list(PDO $conn, array $user): void
{
    $companyIds = $user['company_ids'];
    $companyIn = ops_api_company_in($companyIds);
    $today = date('Y-m-d');

    // Daily repeating jobs are created on the way in, exactly as the supervisor
    // list does it — there is no cron behind this. A cleaner opening the app at
    // six in the morning is often the first thing to touch the module all day,
    // so if this did not run here their Today tab would be empty until somebody
    // in the office logged in.
    foreach ($companyIds as $cid) {
        ops_generate_daily_jobs($conn, (int)$cid, $today);
    }

    // Scope first, filter second. These two conditions are never optional.
    $base = " FROM ops_jobs j
              WHERE j.assigned_to = ?
                AND j.company_id IN (" . $companyIn . ")";
    $baseArgs = array_merge([$user['id']], $companyIds);

    $tab = (string)($_GET['tab'] ?? 'today');
    if (!in_array($tab, ['today', 'upcoming', 'done'], true)) {
        $tab = 'today';
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

    // Tab badges in one round trip. Each badge counts exactly what its tab
    // shows, so the number and the list can never disagree.
    $counts = [];
    foreach (['today', 'upcoming', 'done'] as $name) {
        [$tabSql, $tabArgs] = ops_api_tab_filter($name, $today);
        $countStmt = $conn->prepare('SELECT COUNT(*)' . $base . $tabSql);
        $countStmt->execute(array_merge($baseArgs, $tabArgs));
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

    $stmt = $conn->prepare("
        UPDATE ops_jobs
        SET status = 'in_progress',
            started_at = COALESCE(started_at, NOW())
        WHERE id = ? AND company_id = ? AND assigned_to = ?
    ");
    $stmt->execute([$jobId, (int)$job['company_id'], $user['id']]);

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

    $notes = trim((string)(ops_api_param('completion_notes', '') ?? ''));
    // A job finished without ever being started counts as zero minutes
    // rather than failing.
    $startedAt = $job['started_at'] ?: date('Y-m-d H:i:s');
    $minutes = max(0, (int)round((time() - strtotime((string)$startedAt)) / 60));

    $stmt = $conn->prepare("
        UPDATE ops_jobs
        SET status = 'done',
            started_at = COALESCE(started_at, NOW()),
            finished_at = NOW(),
            duration_minutes = ?,
            completion_notes = COALESCE(NULLIF(?, ''), completion_notes)
        WHERE id = ? AND company_id = ? AND assigned_to = ?
    ");
    $stmt->execute([$minutes, $notes, $jobId, (int)$job['company_id'], $user['id']]);

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
    $companyIds = $user['company_ids'];
    $sql = "
        SELECT p.file_path, p.job_id
        FROM ops_job_photos p
        WHERE p.id = ? AND p.company_id IN (" . ops_api_company_in($companyIds) . ")
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$photoId], $companyIds));
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
    $companyIds = $user['company_ids'];
    $sql = "
        SELECT m.file_path, m.job_id
        FROM ops_job_comment_media m
        WHERE m.id = ? AND m.company_id IN (" . ops_api_company_in($companyIds) . ")
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$mediaId], $companyIds));
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
