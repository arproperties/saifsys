<?php
/**
 * Operations module — shared helpers.
 * Kept deliberately small: labels, guards, photo storage.
 */

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__, 3) . '/includes/module_access.php';
require_once dirname(__DIR__, 3) . '/includes/rbac_department.php';
require_once dirname(__DIR__, 3) . '/includes/company_helper.php';

if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// ---------------------------------------------------------------------------
// Labels — one place, so wording stays identical on every screen
// ---------------------------------------------------------------------------

function ops_job_types(): array {
    return ['cleaning' => 'Cleaning', 'maintenance' => 'Maintenance'];
}

function ops_statuses(): array {
    return [
        'open' => 'Not started',
        'in_progress' => 'In progress',
        'done' => 'Completed',
        'cancelled' => 'Cancelled',
    ];
}

function ops_priorities(): array {
    return ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'];
}

function ops_status_label(string $status): string {
    return ops_statuses()[$status] ?? ucfirst($status);
}

/** Bootstrap colour suffix for a status badge. */
function ops_status_color(string $status): string {
    return [
        'open' => 'secondary',
        'in_progress' => 'warning',
        'done' => 'success',
        'cancelled' => 'dark',
    ][$status] ?? 'secondary';
}

function ops_priority_color(string $priority): string {
    return ['low' => 'info', 'normal' => 'secondary', 'high' => 'danger'][$priority] ?? 'secondary';
}

/** "1h 25m" from a minute count. */
function ops_format_duration(?int $minutes): string {
    if ($minutes === null || $minutes < 0) {
        return '—';
    }
    if ($minutes < 60) {
        return $minutes . 'm';
    }
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $m === 0 ? $h . 'h' : $h . 'h ' . $m . 'm';
}

// ---------------------------------------------------------------------------
// Late work
// ---------------------------------------------------------------------------

/** Minutes a timed job may run past its start time before it reads as late. */
const OPS_LATE_GRACE_MINUTES = 30;

/**
 * Is this job late?
 *
 * Two rules, because a job carries two kinds of deadline.
 *
 * A job with a start time is late when nobody has started it half an hour
 * past that time. The grace is deliberate: traffic and a job that ran long
 * before it are normal, and a board that turns red the minute the clock does
 * gets ignored. Once someone taps Start the clock stops mattering — they are
 * on it, and how long it takes is the duration, not lateness.
 *
 * A job with no time only owes the day. It is late once that day has passed
 * and it is still unfinished, whether or not it was ever started.
 */
function ops_job_is_late(array $job, ?int $now = null): bool
{
    $status = (string)($job['status'] ?? '');
    if (!in_array($status, ['open', 'in_progress'], true)) {
        return false;
    }

    $date = (string)($job['scheduled_date'] ?? '');
    if ($date === '') {
        return false;
    }

    $now = $now ?? time();
    if ($date < date('Y-m-d', $now)) {
        return true;
    }

    $time = (string)($job['scheduled_time'] ?? '');
    if ($time === '' || $status !== 'open') {
        return false;
    }

    $due = strtotime($date . ' ' . $time);
    return $due !== false && $now > $due + (OPS_LATE_GRACE_MINUTES * 60);
}

/**
 * The same rule as ops_job_is_late(), for counting in SQL.
 *
 * Kept beside it on purpose: the tile on the dashboard and the badge on the
 * row have to agree, and they only do while these two say the same thing.
 *
 * @param string $alias Table alias used in the query, e.g. 'j'. '' for none.
 */
function ops_late_sql(string $alias = ''): string
{
    $p = $alias !== '' ? $alias . '.' : '';
    $grace = (int)OPS_LATE_GRACE_MINUTES;

    return "({$p}status IN ('open','in_progress') AND ("
         . "{$p}scheduled_date < CURDATE()"
         . " OR ({$p}status = 'open'"
         . " AND {$p}scheduled_time IS NOT NULL"
         . " AND TIMESTAMP({$p}scheduled_date, {$p}scheduled_time)"
         . " < DATE_SUB(NOW(), INTERVAL {$grace} MINUTE))"
         . "))";
}

/**
 * How late, in words. Empty string when the job is not late.
 *
 * "Late" on its own makes someone open the job to find out whether that means
 * ten minutes or last Tuesday, so the amount is said where the flag is.
 */
function ops_late_note(array $job, ?int $now = null): string
{
    if (!ops_job_is_late($job, $now)) {
        return '';
    }

    $now = $now ?? time();
    $date = (string)$job['scheduled_date'];
    $time = (string)($job['scheduled_time'] ?? '');

    if ($time !== '' && (string)$job['status'] === 'open') {
        $due = strtotime($date . ' ' . $time);
        if ($due !== false && $due > strtotime(date('Y-m-d', $now))) {
            $mins = (int)floor(($now - $due) / 60);
            return 'Not started — ' . ops_format_duration($mins)
                 . ' past its ' . date('g:i A', $due) . ' start.';
        }
    }

    $days = (int)floor(($now - strtotime($date)) / 86400);
    if ($days <= 1) {
        return 'Still not finished from yesterday.';
    }
    return 'Still not finished, ' . $days . ' days past its date.';
}

// ---------------------------------------------------------------------------
// Access
// ---------------------------------------------------------------------------

/**
 * Gate every Operations page: the module plus the Operations department.
 */
function ops_require_access(PDO $conn): void {
    require_module_access($conn, MODULE_OPERATIONS);
    require_operations_supervisor_department($conn);
}

/** Company scope for every query on this module. */
function ops_company_id(PDO $conn): int {
    $cid = (int)(current_company_id($conn) ?: 0);
    if ($cid > 0) {
        return $cid;
    }
    $companies = get_user_companies($conn, (int)current_user_id());
    if (!empty($companies)) {
        $cid = (int)$companies[0]['id'];
        set_current_company($cid);
        return $cid;
    }
    return 0;
}

/**
 * Load one job scoped to the current company. Null when it does not exist there.
 */
function ops_load_job(PDO $conn, int $jobId, int $companyId): ?array {
    $stmt = $conn->prepare("
        SELECT j.*,
               u.fullname AS assignee_name, u.username AS assignee_username,
               COALESCE(NULLIF(cu.fullname, ''), cu.username) AS creator_name
        FROM ops_jobs j
        LEFT JOIN user u  ON u.id  = j.assigned_to
        LEFT JOIN user cu ON cu.id = j.created_by
        WHERE j.id = ? AND j.company_id = ?
        LIMIT 1
    ");
    $stmt->execute([$jobId, $companyId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    return $job ?: null;
}

/**
 * People a job can be assigned to: every active member of staff in the group.
 *
 * Deliberately not scoped to one company. The nine companies in `companies` are
 * one group of companies sharing one pool of staff — a cleaner on the Heroes
 * Zone payroll works Ain Al Reem sites all week — so scoping the list to the
 * job's company hid most of the workforce from the supervisor filling the job
 * in, with nothing on screen to say why. $companyId is still taken so the four
 * call sites read the same and scoping can come back if the group ever splits.
 *
 * Who is left out: anyone whose HR record says they have gone. An employee
 * marked resigned, terminated or inactive stops appearing, so the dropdown is
 * today's staff rather than everyone ever hired. A user with no employee record
 * at all — the owner account — stays in.
 */
function ops_assignable_users(PDO $conn, int $companyId = 0): array {
    $stmt = $conn->query("
        SELECT u.id,
               u.fullname,
               u.username,
               COALESCE(c.name, '') AS company_name
        FROM user u
        LEFT JOIN employees e ON e.user_id = u.id
        LEFT JOIN companies c ON c.id = COALESCE(e.company_id, u.company_id)
        WHERE u.status = 1
          AND u.user_type = 'internal'
          AND (e.id IS NULL OR e.status = 'active')
        ORDER BY COALESCE(NULLIF(c.name, ''), 'zzz'),
                 COALESCE(NULLIF(u.fullname, ''), u.username)
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * ops_assignable_users() output regrouped as company name => people, for
 * rendering <optgroup>s. Forty-odd names in one flat list is a scroll; grouped
 * by employer they are findable.
 */
function ops_people_by_company(array $people): array {
    $grouped = [];
    foreach ($people as $p) {
        $grouped[$p['company_name'] ?: 'Other'][] = $p;
    }
    return $grouped;
}

// ---------------------------------------------------------------------------
// Daily repeating jobs
// ---------------------------------------------------------------------------

/**
 * How many missed days the generator will still fill in.
 *
 * A gap means nobody opened the module — a long weekend, a week of leave. Up to
 * a week of missed days is worth creating: they show up late, in red, which is
 * the honest record that the lobby went uncleaned. Beyond that it is not a
 * record, it is a month of noise nobody will ever tick off, so the run picks up
 * from a week ago and carries on.
 */
const OPS_REPEAT_BACKFILL_DAYS = 7;

/** True when this row is the job the supervisor ticked, not one the server made. */
function ops_job_is_repeat_head(array $job): bool {
    return (int)($job['series_id'] ?? 0) === (int)($job['id'] ?? 0) && (int)($job['id'] ?? 0) > 0;
}

/** True when the job belongs to a series at all — head or generated copy. */
function ops_job_in_series(array $job): bool {
    return (int)($job['series_id'] ?? 0) > 0;
}

/** The job that carries the repeat rule for this series. */
function ops_repeat_head_id(array $job): int {
    return (int)($job['series_id'] ?? 0) ?: (int)($job['id'] ?? 0);
}

/**
 * Create whatever days a repeating job still owes, up to today.
 *
 * Called on the way into the supervisor list and the field app's job list, so
 * there is no cron to install and no cron to quietly stop working: whoever
 * opens the module first that morning is what makes the day's jobs appear.
 *
 * The unique key on (series_id, scheduled_date) — not a check-then-insert — is
 * what stops two people opening the page at once from creating the same day
 * twice. A duplicate is the expected outcome of losing that race, so it is
 * swallowed; anything else is a real fault and is left to blow up.
 *
 * @return int how many jobs were created
 */
function ops_generate_daily_jobs(PDO $conn, int $companyId, ?string $today = null): int {
    if ($companyId <= 0) {
        return 0;
    }
    $today = $today !== null && $today !== '' ? $today : date('Y-m-d');

    // Only heads still switched on, and only ones whose end date has not passed.
    // A cancelled head stops the series: cancelling tomorrow's cleaning off the
    // job itself is the obvious way to say "stop", so it has to mean that.
    $stmt = $conn->prepare("
        SELECT h.id, h.job_type, h.title, h.location, h.description, h.assigned_to,
               h.scheduled_date, h.scheduled_time, h.priority, h.created_by,
               (SELECT MAX(c.scheduled_date) FROM ops_jobs c WHERE c.series_id = h.id) AS last_date
        FROM ops_jobs h
        WHERE h.company_id = ?
          AND h.repeat_daily = 1
          AND h.series_id = h.id
          AND h.status <> 'cancelled'
    ");
    $stmt->execute([$companyId]);
    $heads = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$heads) {
        return 0;
    }

    $insert = $conn->prepare("
        INSERT INTO ops_jobs
            (company_id, job_type, title, location, description, assigned_to,
             scheduled_date, scheduled_time, priority, status, created_by, series_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?)
    ");

    $stopAt = strtotime($today);
    $earliest = strtotime($today . ' -' . OPS_REPEAT_BACKFILL_DAYS . ' days');
    $created = 0;

    foreach ($heads as $head) {
        $last = (string)($head['last_date'] ?? '');
        if ($last === '') {
            // Only possible if series_id was set by hand; treat the head's own
            // date as the last day covered so we carry on from there.
            $last = (string)$head['scheduled_date'];
        }
        $cursor = strtotime($last . ' +1 day');
        if ($cursor < $earliest) {
            $cursor = $earliest;
        }
        // Each day copies the head as it stands now, so editing the head — a
        // new cleaner, a different time — changes tomorrow without touching
        // what has already been done.
        while ($cursor !== false && $cursor <= $stopAt) {
            $date = date('Y-m-d', $cursor);
            try {
                $insert->execute([
                    $companyId, $head['job_type'], $head['title'], $head['location'],
                    $head['description'], $head['assigned_to'], $date,
                    $head['scheduled_time'], $head['priority'], $head['created_by'],
                    (int)$head['id'],
                ]);
                $created++;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
            $cursor = strtotime($date . ' +1 day');
        }
    }

    return $created;
}

// ---------------------------------------------------------------------------
// Photos
// ---------------------------------------------------------------------------

/**
 * What a piece of before/after evidence may be.
 *
 * Two kinds now, not one. A still answers "what did it look like"; a clip
 * answers "what was it doing" — a tap that only drips when it is running, a
 * lift that judders, a fan that rattles. Those are the jobs where a photo has
 * always come back and told the office nothing.
 *
 * The tables themselves are borrowed from the message-attachment code rather
 * than copied: the list of MIME types libmagic may return for an iPhone .mov is
 * fiddly, was got right once, and two copies of it would drift. What stays
 * separate is everything around them — the phase rules, the table, the folder —
 * which is what the sibling comment further down is about.
 *
 * @return array<string, string[]> extension => acceptable finfo MIME types
 */
function ops_photo_media_types(string $kind): array {
    return ops_comment_media_types($kind === 'video' ? 'video' : 'photo');
}

/**
 * Kept because it reads well at the call site and because "what may a photo
 * be" is still a question worth being able to ask on its own.
 *
 * @return string[]
 */
function ops_photo_allowed_extensions(): array {
    return array_keys(ops_photo_media_types('photo'));
}

/**
 * Which kind an upload is, decided by its extension and nothing else.
 *
 * The extension is then checked against the whitelist for that kind, so a
 * wrong guess here cannot smuggle anything through — it only picks which list
 * the file is measured against, and an .mp4 full of HTML fails the MIME check
 * and the ftyp check regardless.
 */
function ops_photo_kind_for_upload(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return isset(ops_comment_media_types('video')[$ext]) ? 'video' : 'photo';
}

/**
 * Store one uploaded before/after photo or video for a job.
 *
 * @param array $file One $_FILES entry
 * @param int|null $maxBytes Overrides the per-kind default. Rarely wanted.
 * @return array{ok:bool,error?:string,file_path?:string,media_kind?:string}
 */
function ops_store_photo(
    PDO $conn,
    int $companyId,
    int $jobId,
    array $file,
    string $photoType,
    ?int $uploadedBy,
    ?int $maxBytes = null
): array {
    if (!in_array($photoType, ['before', 'after'], true)) {
        return ['ok' => false, 'error' => 'Photo must be marked Before or After.'];
    }

    $kind = ops_photo_kind_for_upload((string)($file['name'] ?? ''));
    $isVideo = $kind === 'video';
    $wording = $isVideo ? 'video' : 'photo';
    // A minute of 720p is 15-30 MB, and telling someone their clip is too big
    // after they have filmed the fault is the worst moment to say no.
    $maxBytes = $maxBytes ?? ops_comment_media_max_bytes($kind);

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => "That $wording is bigger than the server allows.",
            UPLOAD_ERR_FORM_SIZE  => "That $wording is too large.",
            UPLOAD_ERR_PARTIAL    => "The $wording did not upload completely. Please try again.",
            UPLOAD_ERR_NO_FILE    => "No $wording was chosen.",
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload folder is missing.',
            UPLOAD_ERR_CANT_WRITE => "Server could not save the $wording.",
            UPLOAD_ERR_EXTENSION  => 'The upload was blocked by the server.',
        ];
        return ['ok' => false, 'error' => $messages[$file['error'] ?? 0] ?? 'Upload failed.'];
    }
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        return [
            'ok' => false,
            'error' => sprintf(
                'Each %s must be %d MB or smaller.',
                $wording,
                (int)round($maxBytes / 1048576)
            ),
        ];
    }
    if (!is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        return ['ok' => false, 'error' => 'Upload failed.'];
    }

    $allowed = ops_photo_media_types($kind);
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        return [
            'ok' => false,
            'error' => 'Only JPG, PNG, GIF or WEBP photos, or MP4 or MOV videos, are allowed.',
        ];
    }

    // The name said what it is. Now check the bytes agree.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed[$ext], true)) {
        return ['ok' => false, 'error' => "That file is not a $wording."];
    }
    if ($isVideo) {
        // mp4 and mov are the same ISO base media container and libmagic names
        // them by brand, so the container is confirmed directly.
        if (!ops_media_is_iso_bmff($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'That file is not a video.'];
        }
    } else {
        // Stills keep the old behaviour of naming the file after what the bytes
        // turned out to be, so a .jpeg is stored as .jpg and there is one
        // spelling of each type on disk.
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];
        if (!isset($mimeMap[$mime])) {
            return ['ok' => false, 'error' => 'That file is not a photo.'];
        }
        $ext = $mimeMap[$mime];
    }

    $appRoot = dirname(__DIR__, 3);
    $relDir = 'uploads/operations/jobs/' . $jobId;
    $absDir = $appRoot . '/' . $relDir;
    if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
        return ['ok' => false, 'error' => "Server could not create the $wording folder."];
    }

    $name = $photoType . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $absPath = $absDir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $absPath)) {
        return ['ok' => false, 'error' => "Server could not save the $wording."];
    }
    @chmod($absPath, 0644);

    $relPath = $relDir . '/' . $name;
    $stmt = $conn->prepare("
        INSERT INTO ops_job_photos (job_id, company_id, photo_type, media_kind, file_path, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$jobId, $companyId, $photoType, $kind, $relPath, $uploadedBy ?: null]);

    return ['ok' => true, 'file_path' => $relPath, 'media_kind' => $kind];
}

// ---------------------------------------------------------------------------
//
// A sibling of ops_store_photo(), not an extension of it. That function stores
// the before/after evidence: it is hard-wired to a photo_type, to
// ops_job_photos, and to the phase rules that table carries. A message
// attachment answers a different question — "look at this", "listen to this" —
// and bending the photo helper to also mean that would leave one function
// enforcing two sets of rules.

/**
 * What a message attachment of each kind may be, and what its bytes must
 * actually look like.
 *
 * The extension decides where the file can be stored and how it will later be
 * served; the MIME list decides whether the bytes are really that thing. Both
 * are checked, because a name is not evidence and a browser will happily run a
 * .jpg full of HTML if something ever serves it as text/html.
 *
 * m4a is what both iOS and Android record to. Depending on how the container
 * was written, libmagic calls the same file audio/x-m4a, audio/mp4 or
 * video/mp4 — all three are the ISO base media container, so all three are
 * accepted and the ftyp box is checked separately in ops_media_is_iso_bmff().
 *
 * @return array<string, string[]> extension => acceptable finfo MIME types
 */
function ops_comment_media_types(string $kind): array {
    if ($kind === 'voice') {
        return [
            'm4a' => ['audio/x-m4a', 'audio/mp4', 'video/mp4'],
            'aac' => ['audio/aac', 'audio/x-hx-aac-adts', 'audio/x-aac'],
            'mp3' => ['audio/mpeg'],
            // What the office records in a browser. MediaRecorder gives WebM
            // on Chrome and Firefox and M4A on Safari, and a WebM/Opus note is
            // silent on an iPhone — so the web composer decodes whatever it
            // recorded and uploads 16-bit PCM WAV, which every browser and both
            // phones play. libmagic names the same file three ways depending on
            // its version, hence the list.
            'wav' => ['audio/x-wav', 'audio/wav', 'audio/wave', 'audio/vnd.wave'],
        ];
    }
    if ($kind === 'video') {
        // What the two phone cameras produce. Both extensions are the same ISO
        // base media container with different brands, and libmagic names them
        // by brand rather than by extension — an Android .mp4 reads as
        // video/mp4, an iPhone .mov as video/quicktime, and a re-encoded file
        // can read as either or as video/x-m4v. Listing the family on both
        // sides avoids rejecting real footage over a brand byte.
        //
        // This is deliberately the loosest of the three lists, and it is safe
        // because it is not what protects us. The extension whitelist decides
        // where the file is stored and what Content-Type it is served with,
        // that type is fixed and never read from the file, and
        // ops_media_is_iso_bmff() confirms the bytes really are this container.
        // A file passing all three cannot be HTML, SVG or PHP whatever libmagic
        // decides to call it.
        $family = ['video/mp4', 'video/quicktime', 'video/x-m4v'];
        return ['mp4' => $family, 'mov' => $family];
    }
    return [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
    ];
}

/**
 * Content-Type for serving one of these files, from its extension and nothing
 * else.
 *
 * Never sniff, and never store the browser's or libmagic's opinion and replay
 * it later: a file that can talk a server into returning text/html or
 * image/svg+xml is a script running on this origin. Anything not on this list
 * is not served at all.
 */
function ops_comment_media_content_type(string $ext): ?string {
    $types = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        // Audio was added to this whitelist deliberately, for voice notes.
        // audio/mp4 rather than audio/x-m4a: it is the registered type and the
        // one both iOS and Android players accept without argument.
        'm4a'  => 'audio/mp4',
        'aac'  => 'audio/aac',
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        // Video, added deliberately for the same reason.
        'mp4'  => 'video/mp4',
        'mov'  => 'video/quicktime',
    ];
    return $types[strtolower($ext)] ?? null;
}

/**
 * A php.ini size ("8M", "512K", "1G") in bytes.
 */
function ops_ini_bytes(string $value): int {
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (int)$value;
    if ($unit === 'g') {
        return $number * 1073741824;
    }
    if ($unit === 'm') {
        return $number * 1048576;
    }
    if ($unit === 'k') {
        return $number * 1024;
    }
    return $number;
}

/**
 * The largest message this server will actually accept, attachments included.
 *
 * The office sends everything in one request, so post_max_size is the real
 * ceiling and it is usually smaller than the 50 MB a video is allowed to be.
 * Worth knowing before someone attaches a clip rather than after: PHP discards
 * an over-sized POST entirely, so what comes back is not "too big" but an empty
 * request that fails its CSRF check for no visible reason.
 *
 * Zero means unlimited, which php.ini allows and which nothing here should
 * present as a limit.
 */
function ops_max_request_bytes(): int {
    $post = ops_ini_bytes((string)ini_get('post_max_size'));
    $file = ops_ini_bytes((string)ini_get('upload_max_filesize'));
    if ($post <= 0) {
        return $file;
    }
    if ($file <= 0) {
        return $post;
    }
    return min($post, $file);
}

/**
 * How many attachments one message may carry. The same number the field app
 * uses, so neither side can send something the other would refuse.
 */
const OPS_COMMENT_MAX_ATTACHMENTS = 10;

/**
 * Turn one multi-file form field into a plain list of $_FILES entries.
 *
 * PHP inverts `name="media[]"` into an array per property — names in one array,
 * tmp_names in another — which is the wrong shape for every function that takes
 * "a file". This puts it back, keeping the original index as the key so a
 * parallel field (the recorded length of each voice note) still lines up after
 * the empty slots are dropped.
 *
 * @param mixed $field $_FILES['media'], in either the single or multiple shape
 * @return array<int, array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function ops_collect_comment_uploads($field): array {
    if (!is_array($field) || !isset($field['name'])) {
        return [];
    }
    // A stray single-file input, so the caller does not have to care which.
    if (!is_array($field['name'])) {
        $field = array_map(static fn($v) => [$v], $field);
    }

    $files = [];
    foreach (array_keys($field['name']) as $i) {
        $error = (int)($field['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        // An empty slot is not a failure: browsers post one for a file input
        // that was never filled in.
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $files[(int)$i] = [
            'name'     => (string)($field['name'][$i] ?? ''),
            'type'     => (string)($field['type'][$i] ?? ''),
            'tmp_name' => (string)($field['tmp_name'][$i] ?? ''),
            'error'    => $error,
            'size'     => (int)($field['size'][$i] ?? 0),
        ];
    }
    return $files;
}

/**
 * Which kind of attachment a chosen file is, from its extension alone.
 *
 * The office picks files from a computer, so nothing upstream can be asked
 * "is this a video or a voice note?" the way the phone's picker answers it.
 * The extension decides, and it decides only which whitelist the file is then
 * measured against — a guess that lands on the wrong list simply fails, and a
 * name matching no list at all is not accepted.
 *
 * @return string|null 'photo', 'video', 'voice', or null when it is none of them
 */
function ops_comment_media_kind_for_extension(string $ext): ?string {
    $ext = strtolower($ext);
    foreach (['photo', 'video', 'voice'] as $kind) {
        if (isset(ops_comment_media_types($kind)[$ext])) {
            return $kind;
        }
    }
    return null;
}

/**
 * How large one attachment of each kind may be.
 *
 * Video gets a bigger allowance because 60 seconds at 720p — the cap the app
 * records at — is 15-30 MB, and refusing it after someone has filmed the
 * problem is the worst possible moment to say no. Everything else stays at the
 * 10 MB the photo helper has always used.
 *
 * Whatever this says, PHP will not accept more than upload_max_filesize, so the
 * limit is the smaller of the two and the caller is told which one it hit.
 */
function ops_comment_media_max_bytes(string $kind): int {
    return $kind === 'video' ? 52428800 : 10485760;
}

/**
 * Is this really an ISO base media file (MP4/M4A)?
 *
 * libmagic's answer for these varies by writer, so the container is confirmed
 * directly: bytes 4-8 of the file are the literal string "ftyp". A .m4a whose
 * contents are a shell script or an HTML page fails here regardless of what
 * finfo made of it.
 */
function ops_media_is_iso_bmff(string $absPath): bool {
    $handle = @fopen($absPath, 'rb');
    if ($handle === false) {
        return false;
    }
    $head = (string)fread($handle, 12);
    fclose($handle);
    return strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp';
}

/**
 * Send a file, honouring a byte-range request.
 *
 * Video is why this exists. A browser asked to play a <video> does not download
 * it and start — it asks for a range, and if the server answers 200 with the
 * whole file, Safari in particular will refuse to play at all and no browser
 * will let the viewer seek. An office that cannot scrub to the part of the clip
 * that matters is an office that will not watch it.
 *
 * Photos and audio go through here too, so there is one way files leave this
 * module rather than two that drift apart.
 *
 * The Content-Type is passed in, already decided by an extension whitelist. It
 * is never sniffed and never read from the database — see
 * ops_comment_media_content_type().
 */
function ops_serve_file_with_ranges(string $absPath, string $contentType): void {
    $size = (int)filesize($absPath);
    $start = 0;
    $end = $size - 1;
    $partial = false;

    $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
        $hasFrom = $m[1] !== '';
        $hasTo = $m[2] !== '';

        if (!$hasFrom && !$hasTo) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }

        if ($hasFrom) {
            $start = (int)$m[1];
            if ($hasTo) {
                $end = (int)$m[2];
            }
        } else {
            // "bytes=-500" means the LAST 500 bytes, not the first 500.
            $start = max(0, $size - (int)$m[2]);
        }

        $end = min($end, $size - 1);
        if ($start > $end || $start >= $size) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        $partial = true;
    }

    $length = $end - $start + 1;

    header('Content-Type: ' . $contentType);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $length);
    header('Content-Disposition: inline; filename="' . basename($absPath) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=600');
    if ($partial) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        http_response_code(206);
    }

    $handle = fopen($absPath, 'rb');
    if ($handle === false) {
        http_response_code(500);
        exit;
    }
    fseek($handle, $start);

    // Streamed in chunks: a 25 MB video read into memory in one go is 25 MB of
    // the process's memory limit spent for no reason, per concurrent viewer.
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, (int)min(262144, $remaining));
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($handle);
    exit;
}

/**
 * Store one attachment against an existing comment.
 *
 * Files live under uploads/operations/jobs/{jobId}/messages/, inside the same
 * folder tree the photo serving route already contains itself to, so the
 * realpath check that protects before/after photos protects these unchanged.
 *
 * The caller must have inserted the comment first — an attachment with no
 * message to belong to is not a thing this app has.
 *
 * @param array $file      One entry from $_FILES
 * @param string $kind     'photo', 'voice' or 'video'
 * @param ?int $durationSeconds  Voice and video; what the phone measured
 * @param ?int $maxBytes   Defaults to ops_comment_media_max_bytes($kind)
 * @return array{ok:bool, error?:string, id?:int, file_path?:string}
 */
function ops_store_comment_media(
    PDO $conn,
    int $companyId,
    int $jobId,
    int $commentId,
    array $file,
    string $kind,
    ?int $uploadedBy,
    ?int $durationSeconds = null,
    ?int $maxBytes = null
): array {
    if (!in_array($kind, ['photo', 'voice', 'video'], true)) {
        return ['ok' => false, 'error' => 'That kind of attachment is not allowed.'];
    }

    $isVoice = $kind === 'voice';
    $isVideo = $kind === 'video';
    $wording = $isVoice ? 'voice message' : ($isVideo ? 'video' : 'photo');
    $maxBytes = $maxBytes ?? ops_comment_media_max_bytes($kind);

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => "That $wording is bigger than the server allows.",
            UPLOAD_ERR_FORM_SIZE  => "That $wording is too large.",
            UPLOAD_ERR_PARTIAL    => "The $wording did not upload completely. Please try again.",
            UPLOAD_ERR_NO_FILE    => "No $wording was attached.",
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload folder is missing.',
            UPLOAD_ERR_CANT_WRITE => "Server could not save the $wording.",
            UPLOAD_ERR_EXTENSION  => 'The upload was blocked by the server.',
        ];
        return ['ok' => false, 'error' => $messages[$file['error'] ?? 0] ?? 'Upload failed.'];
    }
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        return [
            'ok' => false,
            'error' => sprintf(
                'Each %s must be %d MB or smaller.',
                $wording,
                (int)round($maxBytes / 1048576)
            ),
        ];
    }
    if (!is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        return ['ok' => false, 'error' => 'Upload failed.'];
    }

    $allowed = ops_comment_media_types($kind);
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        $allowedWording = $isVoice
            ? 'Only M4A, AAC or MP3 voice messages are allowed.'
            : ($isVideo
                ? 'Only MP4 or MOV videos are allowed.'
                : 'Only JPG, PNG, GIF or WEBP photos are allowed.');
        return ['ok' => false, 'error' => $allowedWording];
    }

    // The name said what it is. Now check the bytes agree.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed[$ext], true)) {
        return ['ok' => false, 'error' => "That file is not a $wording."];
    }
    // m4a, mp4 and mov are all the ISO base media container, and libmagic's
    // answer for them varies by writer. Confirm the container directly.
    if (in_array($ext, ['m4a', 'mp4', 'mov'], true) && !ops_media_is_iso_bmff($file['tmp_name'])) {
        return ['ok' => false, 'error' => "That file is not a $wording."];
    }

    $appRoot = dirname(__DIR__, 3);
    $relDir = 'uploads/operations/jobs/' . $jobId . '/messages';
    $absDir = $appRoot . '/' . $relDir;
    if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
        return ['ok' => false, 'error' => "Server could not create the $wording folder."];
    }

    $name = $kind . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $absPath = $absDir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $absPath)) {
        return ['ok' => false, 'error' => "Server could not save the $wording."];
    }
    @chmod($absPath, 0644);

    // A length is only meaningful on voice, and only within reason: the phone
    // reports it, so it is not to be trusted as a number to allocate against.
    $duration = null;
    if (($isVoice || $isVideo) && $durationSeconds !== null) {
        $duration = max(0, min(3600, $durationSeconds));
    }

    $relPath = $relDir . '/' . $name;
    try {
        $stmt = $conn->prepare("
            INSERT INTO ops_job_comment_media
                (comment_id, job_id, company_id, kind, file_path, duration_seconds, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $commentId, $jobId, $companyId, $kind, $relPath, $duration, $uploadedBy ?: null,
        ]);
    } catch (PDOException $e) {
        // The row is what makes the file reachable. Without it the file is
        // litter nothing will ever point at, so take it back out.
        @unlink($absPath);
        error_log('ops_store_comment_media insert failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => "Server could not save the $wording."];
    }

    return ['ok' => true, 'id' => (int)$conn->lastInsertId(), 'file_path' => $relPath];
}

// ---------------------------------------------------------------------------
// Stock
// ---------------------------------------------------------------------------

/**
 * Active stock items for the company, low stock first so shortages surface.
 */
function ops_stock_items(PDO $conn, int $companyId, bool $activeOnly = true): array {
    $sql = "SELECT * FROM ops_items WHERE company_id = ?";
    if ($activeOnly) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY (current_qty <= min_qty) DESC, name ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** How many active items are at or below their warning level. */
function ops_low_stock_count(PDO $conn, int $companyId): int {
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM ops_items
        WHERE company_id = ? AND is_active = 1 AND current_qty <= min_qty
    ");
    $stmt->execute([$companyId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Move stock and record why, in one transaction so the quantity and its
 * history can never disagree.
 *
 * @param float $qtyChange Positive to add, negative to take out
 * @return array{ok:bool,error?:string,new_qty?:float}
 */
function ops_move_stock(
    PDO $conn,
    int $companyId,
    int $itemId,
    float $qtyChange,
    string $reason,
    ?int $jobId = null,
    ?string $note = null,
    ?int $userId = null,
    bool $allowNegative = false
): array {
    if (!in_array($reason, ['in', 'out', 'adjust'], true)) {
        return ['ok' => false, 'error' => 'Unknown stock reason.'];
    }
    if (abs($qtyChange) < 0.0005) {
        return ['ok' => false, 'error' => 'Enter a quantity.'];
    }

    try {
        $conn->beginTransaction();

        $stmt = $conn->prepare("SELECT * FROM ops_items WHERE id = ? AND company_id = ? FOR UPDATE");
        $stmt->execute([$itemId, $companyId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            $conn->rollBack();
            return ['ok' => false, 'error' => 'That stock item was not found.'];
        }

        $newQty = (float)$item['current_qty'] + $qtyChange;
        if ($newQty < 0 && !$allowNegative) {
            $conn->rollBack();
            return [
                'ok' => false,
                'error' => 'Not enough ' . $item['name'] . ' in stock — only '
                    . ops_qty($item['current_qty']) . ' ' . ($item['unit'] ?: '') . ' left.',
            ];
        }

        $conn->prepare("UPDATE ops_items SET current_qty = ? WHERE id = ?")
             ->execute([$newQty, $itemId]);
        $conn->prepare("
            INSERT INTO ops_stock_moves (company_id, item_id, qty_change, reason, job_id, note, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$companyId, $itemId, $qtyChange, $reason, $jobId, $note, $userId]);

        $conn->commit();
        return ['ok' => true, 'new_qty' => $newQty];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('ops_move_stock failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not update stock. Please try again.'];
    }
}

/** Trim trailing zeros so 2.000 shows as 2 and 1.500 as 1.5. */
function ops_qty($qty): string {
    return rtrim(rtrim(number_format((float)$qty, 3, '.', ''), '0'), '.') ?: '0';
}

/**
 * Flash message helper — survives the redirect after every POST.
 */
function ops_flash(string $message, string $type = 'success'): void {
    $_SESSION['ops_flash'] = ['message' => $message, 'type' => $type];
}

function ops_take_flash(): ?array {
    if (empty($_SESSION['ops_flash'])) {
        return null;
    }
    $flash = $_SESSION['ops_flash'];
    unset($_SESSION['ops_flash']);
    return $flash;
}
