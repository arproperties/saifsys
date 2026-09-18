<?php
/**
 * ===========================================================================
 *  HOW SOMEONE IS IDENTIFIED HERE
 * ===========================================================================
 *  Two independent things must be true for any request to be answered:
 *
 *    1. `X-Ops-App-Key` — the shared app secret (OPS_MOBILE_APP_KEY). Empty
 *       key on the server = the whole API answers 503. It ships inside the
 *       app binary, so it says "this is our app", never "this is Rashid".
 *    2. `Authorization: Bearer <token>` — an HS256 JWT this API issued when
 *       someone typed their 4-digit PIN into the phone. The user id comes
 *       from a verified claim and from nowhere else; nothing a caller sends
 *       in a header or a body can name a different person.
 *
 *  Every token carries the epoch of the PIN it was issued from, and that is
 *  checked against the database on every single request. Change or remove a
 *  person's PIN in HR and their phone is signed out on its next call — no
 *  token blocklist, no waiting for an expiry.
 *
 *  The PIN itself — storage, uniqueness, the lockout that makes four digits
 *  defensible — lives in modules/operations/includes/ops_pin.php. Read the
 *  header of that file before changing anything about sign-in.
 * ===========================================================================
 *
 * Shared helpers for api/mobile/ops/index.php: the gate, routing, company +
 * assignment scoping, and the JSON shapes the app consumes.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/db_connect.php';
require_once dirname(__DIR__, 3) . '/includes/url_helper.php';
require_once dirname(__DIR__, 3) . '/includes/customer_api.php';
require_once dirname(__DIR__, 3) . '/modules/operations/includes/ops_helper.php';
require_once dirname(__DIR__, 3) . '/modules/operations/includes/ops_pin.php';
require_once dirname(__DIR__, 3) . '/modules/operations/includes/ops_sources.php';
require_once dirname(__DIR__, 3) . '/modules/operations/includes/ops_billing.php';
require_once dirname(__DIR__, 3) . '/modules/operations/includes/ops_attendance.php';
require_once dirname(__DIR__, 3) . '/modules/operations/includes/ops_checklist.php';

// ops_helper -> auth.php opens a session at include time. This API is
// stateless, so drop it straight away: no session file is written and no
// lock is held for the length of the request.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_abort();
}

// ---------------------------------------------------------------------------
// The gate
// ---------------------------------------------------------------------------

/** The shared app key, or '' when the deployment has not configured one. */
function ops_api_app_key(): string
{
    $env = getenv('OPS_MOBILE_APP_KEY');
    if ($env !== false && $env !== '') {
        return (string)$env;
    }
    return defined('OPS_MOBILE_APP_KEY') ? (string)OPS_MOBILE_APP_KEY : '';
}

/** Request logging while testing. Off unless a deployment turns it on. */
function ops_api_dev_mode(): bool
{
    $env = getenv('OPS_MOBILE_DEV_MODE');
    if ($env !== false && $env !== '') {
        return in_array(strtolower((string)$env), ['1', 'true', 'yes', 'on'], true);
    }
    return defined('OPS_MOBILE_DEV_MODE') ? (bool)OPS_MOBILE_DEV_MODE : false;
}

/**
 * No key, no response. Fails closed when the deployment has no key set, so
 * uploading these files without configuring one cannot expose anything.
 */
function ops_api_require_app_key(): void
{
    $configured = ops_api_app_key();
    if ($configured === '') {
        customer_api_send_error(
            'not_configured',
            'This service is not available.',
            503
        );
    }
    $sent = (string)($_SERVER['HTTP_X_OPS_APP_KEY'] ?? '');
    if ($sent === '' || !hash_equals($configured, $sent)) {
        customer_api_send_error('unauthorized', 'Not authorised.', 401);
    }
}

/**
 * Dev-mode request log.
 *
 * The built-in PHP server logs connections but not requests, which makes "the
 * phone sent something and nothing happened" impossible to diagnose. This
 * writes one line per request to ops_api_log_path() so a failure can be read
 * rather than guessed at.
 *
 * Dev mode only, and it never records the key itself — only whether one
 * arrived and whether it matched.
 */
function ops_api_log_path(): string
{
    return dirname(__DIR__, 3) . '/uploads/ops_api_dev.log';
}

function ops_api_log(string $outcome): void
{
    if (!ops_api_dev_mode()) {
        return;
    }
    $sentKey = (string)($_SERVER['HTTP_X_OPS_APP_KEY'] ?? '');
    $line = sprintf(
        "[%s] %-6s %-28s key=%-7s token=%-7s %s\n",
        date('H:i:s'),
        $_SERVER['REQUEST_METHOD'] ?? '?',
        ops_api_parse_route() ?: '(none)',
        $sentKey === '' ? 'MISSING' : (hash_equals(ops_api_app_key(), $sentKey) ? 'ok' : 'WRONG'),
        ops_api_bearer_token() === '' ? 'MISSING' : 'sent',
        $outcome
    );
    @file_put_contents(ops_api_log_path(), $line, FILE_APPEND | LOCK_EX);
}

function ops_api_boot(): void
{
    customer_api_json_headers();
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Ops-App-Key, X-Ops-Request-Id, X-Ops-Device-Id');
    customer_api_handle_options();

    $configured = ops_api_app_key();
    $sent = (string)($_SERVER['HTTP_X_OPS_APP_KEY'] ?? '');
    if ($configured === '') {
        ops_api_log('REFUSED 503 no key configured on the server');
    } elseif ($sent === '' || !hash_equals($configured, $sent)) {
        ops_api_log('REFUSED 401 app key missing or wrong');
    } else {
        ops_api_log('accepted');
    }

    ops_api_require_app_key();
}

// ---------------------------------------------------------------------------
// Routing — same shape as customer_api_parse_route(), different prefix
// ---------------------------------------------------------------------------

function ops_api_route_prefix(): string
{
    $root = get_application_web_root();
    return ($root === '' ? '' : $root) . '/api/mobile/ops';
}

function ops_api_parse_route(): string
{
    $fromQuery = trim((string)($_GET['route'] ?? ''), '/');
    if ($fromQuery !== '') {
        if (str_contains($fromQuery, '?')) {
            [$path, $qs] = explode('?', $fromQuery, 2);
            parse_str($qs, $parsed);
            if (is_array($parsed)) {
                foreach ($parsed as $key => $value) {
                    if (!array_key_exists((string)$key, $_GET)) {
                        $_GET[(string)$key] = $value;
                    }
                }
            }
            return trim($path, '/');
        }
        return $fromQuery;
    }

    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $uriPath = $uriPath === false || $uriPath === null ? '' : (string)$uriPath;
    $prefix = ops_api_route_prefix();
    if ($prefix !== '' && strpos($uriPath, $prefix) === 0) {
        $rest = trim(substr($uriPath, strlen($prefix)), '/');
        if ($rest === 'index.php' || str_starts_with($rest, 'index.php/')) {
            $rest = trim(substr($rest, strlen('index.php')), '/');
        }
        return $rest;
    }
    return '';
}

/** Body values for POSTs, whether the client sent JSON or a form/multipart. */
function ops_api_input(): array
{
    $json = customer_api_read_json_body();
    if ($json !== []) {
        return $json;
    }
    return $_POST;
}

function ops_api_param(string $key, $default = null)
{
    $body = ops_api_input();
    if (array_key_exists($key, $body)) {
        return $body[$key];
    }
    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }
    return $default;
}

// ---------------------------------------------------------------------------
// Who is calling — THE one place real auth replaces
// ---------------------------------------------------------------------------

/**
 * Companies this person actually belongs to. Every query is scoped to this
 * set, so a job in a company they are not a member of is invisible even if
 * it is somehow assigned to them.
 *
 * @return int[]
 */
function ops_api_user_company_ids(PDO $conn, int $userId): array
{
    $stmt = $conn->prepare("SELECT company_id FROM user_companies WHERE user_id = ?");
    $stmt->execute([$userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/**
 * Which company a job this person raises belongs to.
 *
 * This matters more than it looks. Reading a job back does not care which
 * company it is under — `assigned_to` is the whole access rule, see
 * ops_api_load_own_job() — but modules/operations/index.php lists one company
 * at a time, so a job filed under the wrong one is invisible to the office
 * that has to act on it. It would not error. It would just never appear.
 *
 * `is_primary` is the obvious answer and it is the wrong one. Staff here are a
 * single pool shared across nine companies, and their primary flag records who
 * pays them, not where the work is: on this data every existing job belongs to
 * one company, while two of the seven staff with a PIN are primary to two
 * others. Those two would have filed straight into a list nobody reads.
 *
 * So ask the question that is actually being asked — where does this person
 * get their work — and answer it from the work itself. Their most recent job
 * says it exactly, and it keeps saying it as the group changes, with nothing
 * to maintain.
 *
 * Only somebody who has never had a job falls through to the payroll answer.
 */
function ops_api_job_company_id(PDO $conn, array $user): int
{
    try {
        // Where their work actually comes from.
        $stmt = $conn->prepare("
            SELECT company_id
            FROM ops_jobs
            WHERE assigned_to = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$user['id']]);
        $companyId = (int)$stmt->fetchColumn();
        if ($companyId > 0) {
            return $companyId;
        }

        // Nobody has ever given them a job. Fall back to who they work for.
        $stmt = $conn->prepare("
            SELECT company_id
            FROM user_companies
            WHERE user_id = ?
            ORDER BY is_primary DESC, company_id ASC
            LIMIT 1
        ");
        $stmt->execute([$user['id']]);
        $companyId = (int)$stmt->fetchColumn();
        if ($companyId > 0) {
            return $companyId;
        }
    } catch (Throwable $e) {
        error_log('ops_api_job_company_id failed: ' . $e->getMessage());
    }

    // ops_api_current_user() refuses anyone with no companies at all, so this
    // list is never empty by the time we are here.
    return (int)($user['company_ids'][0] ?? 0);
}

/**
 * The bearer token as sent, or '' — no validation, just the string.
 */
function ops_api_bearer_token(): string
{
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('apache_request_headers')) {
        // Some Apache builds strip Authorization from $_SERVER but not from here.
        foreach ((array)apache_request_headers() as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) {
                $header = (string)$value;
                break;
            }
        }
    }
    if (stripos($header, 'Bearer ') !== 0) {
        return '';
    }
    return trim(substr($header, 7));
}

/**
 * The key ops tokens are signed with.
 *
 * Deliberately NOT customer_api_jwt_secret(): that one falls back to a known
 * placeholder string when the environment does not set it, and anything that
 * knows the placeholder could mint a token for any employee. It is also shared
 * with the customer apps, so changing it to fix that would sign every tenant
 * and guest out.
 *
 * Derived instead from OPS_MOBILE_PIN_SECRET, which this feature already
 * requires and which fails closed when unset. The label keeps it a different
 * key from the PIN index built out of the same secret, so neither can be used
 * to attack the other.
 */
function ops_api_jwt_key(): string
{
    return hash_hmac('sha256', 'ops-staff-token-v1', ops_pin_secret());
}

/** Sign an ops token. Same HS256 shape as includes/customer_api.php. */
function ops_api_jwt_sign(array $payload, int $ttlSeconds): string
{
    $now = time();
    $payload['iat'] = $now;
    $payload['exp'] = $now + $ttlSeconds;
    $h = customer_api_base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_UNESCAPED_SLASHES));
    $p = customer_api_base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig = hash_hmac('sha256', $h . '.' . $p, ops_api_jwt_key(), true);
    return $h . '.' . $p . '.' . customer_api_base64url_encode($sig);
}

/** @return array<string,mixed>|null null for anything not currently valid */
function ops_api_jwt_verify(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$h, $p, $sig] = $parts;
    $expected = customer_api_base64url_encode(
        hash_hmac('sha256', $h . '.' . $p, ops_api_jwt_key(), true)
    );
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    $claims = json_decode(customer_api_base64url_decode($p), true);
    if (!is_array($claims) || ($claims['exp'] ?? 0) < time()) {
        return null;
    }
    return $claims;
}

/** How long a phone stays signed in, in seconds. */
function ops_api_token_ttl(): int
{
    $days = defined('OPS_MOBILE_TOKEN_DAYS') ? (int)OPS_MOBILE_TOKEN_DAYS : 30;
    return max(1, $days) * 86400;
}

/**
 * Mint the token a phone gets when a PIN is accepted.
 *
 * `pin` is the epoch of the PIN row it was issued from. Every later request
 * re-reads that epoch from the database and refuses if it has moved, so
 * changing or clearing a PIN in HR is a revoke.
 *
 * @return array{token:string,expires_in:int}
 */
function ops_api_issue_token(int $userId, int $pinEpoch): array
{
    $ttl = ops_api_token_ttl();
    $token = ops_api_jwt_sign([
        'typ' => 'ops_staff',
        'sub' => $userId,
        'pin' => $pinEpoch,
    ], $ttl);

    return ['token' => $token, 'expires_in' => $ttl];
}

/**
 * Resolve the caller from their token, or answer 401 and stop.
 *
 * Nothing here reads an id from the request. The claim names the person; the
 * database decides whether that person may still work: the account has to be
 * active, the PIN has to still be the one the token was minted from, and they
 * have to belong to at least one company. Each of those is re-checked on every
 * request rather than baked into the token, because a token lives for weeks
 * and a dismissal takes effect the same afternoon.
 *
 * @return array{id:int,name:string,company_ids:int[]}
 */
function ops_api_current_user(PDO $conn): array
{
    $token = ops_api_bearer_token();
    if ($token === '') {
        ops_api_log('REFUSED 401 no bearer token');
        customer_api_send_error('unauthorized', 'Not authorised.', 401);
    }

    $claims = ops_api_jwt_verify($token);
    if (!$claims || ($claims['typ'] ?? '') !== 'ops_staff') {
        ops_api_log('REFUSED 401 token invalid or expired');
        customer_api_send_error('unauthorized', 'Not authorised.', 401);
    }

    $userId = (int)($claims['sub'] ?? 0);
    if ($userId <= 0) {
        customer_api_send_error('unauthorized', 'Not authorised.', 401);
    }

    // The PIN this token came from must still be the current one.
    $epoch = ops_pin_current_epoch($conn, $userId);
    if ($epoch === null || $epoch !== (int)($claims['pin'] ?? -1)) {
        ops_api_log('REFUSED 401 PIN changed or removed since sign-in');
        customer_api_send_error('unauthorized', 'Not authorised.', 401);
    }

    $stmt = $conn->prepare("
        SELECT id, COALESCE(NULLIF(fullname, ''), username) AS name
        FROM user
        WHERE id = ? AND status = 1
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        customer_api_send_error('unauthorized', 'Not authorised.', 401);
    }

    $companyIds = ops_api_user_company_ids($conn, $userId);
    if ($companyIds === []) {
        customer_api_send_error('unauthorized', 'Not authorised.', 401);
    }

    return [
        'id' => (int)$user['id'],
        'name' => (string)$user['name'],
        'company_ids' => $companyIds,
    ];
}

// ---------------------------------------------------------------------------
// Replay protection
// ---------------------------------------------------------------------------

/**
 * Has this exact write already been applied?
 *
 * The app queues every write and retries until the server confirms. If a
 * request succeeds but its response is lost on the way back — a phone leaving
 * a basement mid-reply — the queue has no way to know, so it sends the same
 * thing again. Without this the job quietly collects two Finishes, or five
 * identical material requests.
 *
 * Each queued item carries one id for its whole life, so a repeat is
 * recognisable. Recording it and applying the change are NOT one transaction:
 * that is deliberate. Recording first means a crash between the two loses a
 * write rather than duplicating it, and a lost write is the failure the person
 * can see and redo.
 *
 * @return bool true the first time, false when this is a repeat
 */
function ops_api_claim_request(PDO $conn, array $user, string $route): bool
{
    $requestId = ops_api_request_id();
    if ($requestId === '') {
        // No usable key: behave exactly as before rather than refusing work.
        return true;
    }

    try {
        $stmt = $conn->prepare("
            INSERT INTO ops_api_requests (request_id, user_id, route)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$requestId, $user['id'], substr($route, 0, 191)]);
    } catch (PDOException $e) {
        // 23000 is the duplicate-key class: we have applied this already.
        if ($e->getCode() === '23000') {
            return false;
        }
        // The table may not exist yet on a server that has not run the
        // migration. Losing replay protection is better than losing the write.
        error_log('ops_api_claim_request failed: ' . $e->getMessage());
        return true;
    }

    ops_api_prune_requests($conn);
    return true;
}

/**
 * Keep the table from growing forever. Only needs to outlive the retry window,
 * so a week is generous. Runs rarely — this is housekeeping, not per-request
 * work.
 */
function ops_api_prune_requests(PDO $conn): void
{
    if (random_int(1, 200) !== 1) {
        return;
    }
    try {
        $conn->exec("DELETE FROM ops_api_requests WHERE created_at < (NOW() - INTERVAL 7 DAY)");
    } catch (Throwable $e) {
        error_log('ops_api_prune_requests failed: ' . $e->getMessage());
    }
}

/**
 * The client's id for this write, or '' if it did not send a usable one.
 *
 * One id for the whole life of a queued item, so a retry is recognisable as
 * the same attempt rather than a second one.
 */
/**
 * When the person actually tapped, for work queued with no signal.
 *
 * The app keeps every tap on the phone until it can send it, and each one
 * carries `client_at` — the phone's clock at the tap, in epoch milliseconds. A
 * Finish tapped in a basement and delivered forty minutes later must record the
 * finish, not the delivery: the duration is what an invoice is built from.
 *
 * The phone's clock is only believed inside a window: no more than five minutes
 * ahead of the server, and no further back than $maxAgeSeconds. Outside it, or
 * when nothing is sent (an older app), the answer is the server's own now —
 * exactly what every route did before this existed.
 *
 * Returns 'Y-m-d H:i:s' in PHP's timezone, like date() everywhere else here.
 */
function ops_api_client_time(int $maxAgeSeconds = 7 * 86400): string
{
    $now = time();
    $raw = ops_api_param('client_at');
    if (is_numeric($raw)) {
        $at = (int)floor((float)$raw / 1000);
        if ($at <= $now + 300 && $at >= $now - $maxAgeSeconds) {
            return date('Y-m-d H:i:s', min($at, $now));
        }
    }
    return date('Y-m-d H:i:s', $now);
}

function ops_api_request_id(): string
{
    $requestId = trim((string)($_SERVER['HTTP_X_OPS_REQUEST_ID'] ?? ''));
    return strlen($requestId) > 64 ? '' : $requestId;
}

/**
 * Remember which job a create produced.
 *
 * Only creates call this. Start and finish do not need it: they name a job
 * that already exists, so a replay can simply reload it. A create's whole
 * output is the new id, and if nothing keeps it, the replay has nothing to
 * answer with — see migrations/ops_job_created_in_app.sql.
 */
function ops_api_record_request_job(PDO $conn, string $requestId, int $jobId): void
{
    if ($requestId === '') {
        return;
    }
    try {
        $stmt = $conn->prepare("UPDATE ops_api_requests SET job_id = ? WHERE request_id = ?");
        $stmt->execute([$jobId, $requestId]);
    } catch (Throwable $e) {
        // The column is missing on a server that has not run the migration.
        // The job is already made; losing the replay answer is the smaller loss.
        error_log('ops_api_record_request_job failed: ' . $e->getMessage());
    }
}

/**
 * The job an earlier attempt at this same request created, if any.
 */
function ops_api_request_job_id(PDO $conn, string $requestId): int
{
    if ($requestId === '') {
        return 0;
    }
    try {
        $stmt = $conn->prepare("SELECT job_id FROM ops_api_requests WHERE request_id = ?");
        $stmt->execute([$requestId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('ops_api_request_job_id failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Short-circuit a repeated write: answer with the job as it stands now,
 * without applying anything a second time.
 */
function ops_api_guard_replay(PDO $conn, array $user, int $jobId, string $route): void
{
    if (ops_api_claim_request($conn, $user, $route)) {
        return;
    }

    ops_api_log('replay ignored ' . $route);

    $job = ops_api_load_own_job($conn, $jobId, $user);
    if (!$job) {
        customer_api_send_ok(['duplicate' => true]);
    }
    customer_api_send_ok([
        'job' => ops_api_job_detail($conn, $job, $user),
        'duplicate' => true,
    ]);
}

// ---------------------------------------------------------------------------
// Scoping — company_id AND assigned_to, on every single query
// ---------------------------------------------------------------------------

/**
 * `IN (?, ?, ?)` placeholder list for a company id set.
 */
function ops_api_company_in(array $companyIds): string
{
    return implode(',', array_fill(0, count($companyIds), '?'));
}

/**
 * Load one job assigned to this user.
 *
 * NOTE: ops_load_job() in modules/operations/includes/ops_helper.php scopes by
 * company only — despite the comment in modules/operations/photo.php claiming
 * it re-applies a field-staff "own jobs only" rule, it does not. The API layer
 * therefore applies `assigned_to` itself, here, and nowhere else. Every route
 * in this module goes through this function.
 *
 * `assigned_to` is the whole access rule, and it is enough: it narrows to one
 * person, and a job only reaches it because a supervisor put that person on it.
 * There is deliberately no company_id condition — ops_assignable_users() hands
 * out work across the whole group (one pool of staff, nine companies), so a
 * company check here rejected the assignment the office had just made and the
 * job vanished from the phone with no error anywhere.
 */
function ops_api_load_own_job(PDO $conn, int $jobId, array $user): ?array
{
    $sql = "
        SELECT j.*,
               COALESCE(NULLIF(u.fullname, ''), u.username) AS assignee_name,
               " . ops_api_photo_count_sql('before') . " AS before_photo_count,
               " . ops_api_photo_count_sql('after') . " AS after_photo_count
        FROM ops_jobs j
        LEFT JOIN user u ON u.id = j.assigned_to
        WHERE j.id = ?
          AND j.assigned_to = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$jobId, $user['id']]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    return $job ?: null;
}

/**
 * The Before-photo count, as a subquery on `j` rather than a second round trip.
 *
 * A job cannot be started until at least one Before photo or clip is on it, so
 * every place that loads a job for the app has to know this number: the list
 * and detail screens to decide whether Start is offered, the start route to
 * enforce it. Stills and clips both count — the rule is that the state of the
 * site was recorded, not which button recorded it.
 */
function ops_api_photo_count_sql(string $phase): string
{
    // Interpolated, so it may only ever be one of two literals. The caller
    // passes a phase, never anything a request could reach.
    $type = $phase === 'after' ? 'after' : 'before';
    $alias = $type . 'p';
    return "(SELECT COUNT(*)
             FROM ops_job_photos $alias
             WHERE $alias.job_id = j.id
               AND $alias.company_id = j.company_id
               AND $alias.photo_type = '$type')";
}

function ops_api_before_photo_count_sql(): string
{
    return ops_api_photo_count_sql('before');
}

/** 404 in the app's words, not the database's. */
function ops_api_job_or_404(PDO $conn, int $jobId, array $user): array
{
    $job = ops_api_load_own_job($conn, $jobId, $user);
    if (!$job) {
        customer_api_send_error('not_found', 'That job is not on your list.', 404);
    }
    return $job;
}

// ---------------------------------------------------------------------------
// JSON shapes
// ---------------------------------------------------------------------------

/**
 * A job as the list screen needs it.
 *
 * Both the raw enum and the label go out: the enum drives which button the
 * app shows, the label is the exact wording ops_statuses() uses on the web,
 * so the two surfaces can never drift apart. The app never prints the enum.
 */
function ops_api_job_row(array $job): array
{
    $time = $job['scheduled_time'] !== null && $job['scheduled_time'] !== ''
        ? substr((string)$job['scheduled_time'], 0, 5)
        : null;

    $beforePhotos = (int)($job['before_photo_count'] ?? 0);
    $afterPhotos = (int)($job['after_photo_count'] ?? 0);

    return [
        'id' => (int)$job['id'],
        'title' => (string)$job['title'],
        'location' => $job['location'] !== null && $job['location'] !== '' ? (string)$job['location'] : null,
        'job_type' => (string)$job['job_type'],
        'job_type_label' => ops_job_types()[$job['job_type']] ?? ucfirst((string)$job['job_type']),
        'status' => (string)$job['status'],
        'status_label' => ops_status_label((string)$job['status']),
        'priority' => (string)$job['priority'],
        'is_urgent' => $job['priority'] === 'high',
        // Same rule the office board uses, decided on the server so the two
        // never disagree about which jobs are running late.
        'is_late' => ops_job_is_late($job),
        'scheduled_date' => (string)$job['scheduled_date'],
        'scheduled_time' => $time,
        'duration_minutes' => $job['duration_minutes'] !== null ? (int)$job['duration_minutes'] : null,
        'duration_label' => $job['duration_minutes'] !== null
            ? ops_format_duration((int)$job['duration_minutes'])
            : null,
        'needs_materials' => (bool)(int)$job['needs_materials'],
        // Paused is not a status of its own: the job is still in progress and
        // its clock still runs. See migrations/ops_job_pauses.sql.
        'is_paused' => $job['status'] === 'in_progress' && !empty($job['paused_at']),
        'paused_at' => !empty($job['paused_at']) ? (string)$job['paused_at'] : null,
        'pause_reason' => !empty($job['pause_reason']) ? (string)$job['pause_reason'] : null,
        // 'staff', 'tenant_maintenance', 'tenant_cleaning', 'cleaner_report', 'ars_checkout', 'tenant_move_out' or 'customer_booking'. The card marks a
        // tenant's job, and the Requests tab needs the tenant's own words on
        // it: "AC not cooling" is how somebody decides whether it is theirs.
        'source_type' => (string)($job['source_type'] ?? 'staff'),
        'request_note' => ($job['source_type'] ?? 'staff') !== 'staff' && !empty($job['description'])
            ? mb_substr((string)$job['description'], 0, 160)
            : null,
        'before_photo_count' => $beforePhotos,
        // The app greys out Start on this flag; the start route enforces the
        // same rule again, because a queued start written offline arrives
        // long after the screen that allowed it.
        'can_start' => $job['status'] === 'open' && $beforePhotos > 0,
        'start_blocked_reason' => $job['status'] === 'open' && $beforePhotos === 0
            ? 'Add at least one Before photo or video before starting.'
            : null,
        // The mirror of the Before rule. A job closes on the record of what was
        // left behind, and after the person has walked away nobody can go back
        // and take it. Same shape as can_start so the app greys the one button
        // it is about, and the finish route enforces it again.
        'can_finish' => $job['status'] === 'in_progress' && $afterPhotos > 0,
        'finish_blocked_reason' => $job['status'] === 'in_progress' && $afterPhotos === 0
            ? 'Add at least one After photo or video before finishing.'
            : null,
    ];
}

/**
 * May this person remove this photo?
 *
 * One rule: you can only delete a photo of the phase the job is currently in,
 * and only one you took yourself.
 *
 *   Not started  -> Before photos    (the mess is still there; retake it)
 *   In progress  -> After photos     (you just took it; fix a blurry one)
 *   Completed    -> nothing          (the photos are now the record)
 *
 * A Before photo locks the moment work starts, because by then the "before"
 * state is gone and cannot be photographed again. Anything else has to go
 * through a supervisor in the web module, where someone accountable decides.
 */
function ops_api_photo_deletable(string $jobStatus, string $photoType, bool $isMine): bool
{
    if (!$isMine) {
        return false;
    }
    if ($jobStatus === 'open') {
        return $photoType === 'before';
    }
    if ($jobStatus === 'in_progress') {
        return $photoType === 'after';
    }
    return false;
}

function ops_api_photo_row(array $photo, string $jobStatus = 'done'): array
{
    $isMine = (bool)($photo['is_mine'] ?? false);
    $photoType = (string)$photo['photo_type'];

    return [
        'id' => (int)$photo['id'],
        'photo_type' => $photoType,
        // Whether this row is a still or a clip. Rows written before video
        // existed have no column value to report only if the migration has not
        // been run, so fall back to the kind they all were.
        'media_kind' => ($photo['media_kind'] ?? 'photo') === 'video' ? 'video' : 'photo',
        'caption' => $photo['caption'] !== null && $photo['caption'] !== '' ? (string)$photo['caption'] : null,
        // Path only — the app prepends its own base URL and adds the app key
        // header. Photo bytes never leave through a public href.
        'url_path' => 'photos/' . (int)$photo['id'],
        'created_at' => (string)$photo['created_at'],
        'is_mine' => $isMine,
        // The app hides Delete when this is false. The server re-checks the
        // same rule on the delete route, so the flag is a courtesy, not a gate.
        'can_delete' => ops_api_photo_deletable($jobStatus, $photoType, $isMine),
    ];
}

/**
 * One attachment on a message.
 *
 * Same rule as photos: a path, never a URL. The bytes come back through
 * ops/comment-media/{id}, which is behind the app key and the same own-job
 * check as everything else, so there is no public href to leak.
 */
function ops_api_comment_media_row(array $media): array
{
    $kind = (string)$media['kind'];

    return [
        'id' => (int)$media['id'],
        'kind' => $kind,
        'url_path' => 'comment-media/' . (int)$media['id'],
        // The app caches a voice note to a file before playing it, and the
        // player picks its decoder from the file's name. Sending the extension
        // means it never has to guess one from a route that has none.
        'file_ext' => strtolower(pathinfo((string)$media['file_path'], PATHINFO_EXTENSION)),
        // Voice and video both carry a length, so a player can show one before
        // the file itself has been fetched.
        'duration_seconds' => in_array($kind, ['voice', 'video'], true)
            && $media['duration_seconds'] !== null
            ? (int)$media['duration_seconds']
            : null,
        'created_at' => (string)$media['created_at'],
    ];
}

/**
 * @param array $media Attachments already fetched for this comment, in order
 */
function ops_api_comment_row(array $comment, array $media = []): array
{
    // '' is a real value here, not a missing one: it means the message IS its
    // attachment. See migrations/ops_comment_media.sql for why it is an empty
    // string rather than NULL.
    $text = (string)$comment['comment'];

    return [
        'id' => (int)$comment['id'],
        'comment' => $text,
        'has_text' => $text !== '',
        'author_name' => $comment['author_name'] !== null && $comment['author_name'] !== ''
            ? (string)$comment['author_name']
            : null,
        'is_mine' => (bool)$comment['is_mine'],
        'created_at' => (string)$comment['created_at'],
        // "I need something." The whole of what the phone says about materials,
        // and the whole of what is recorded anywhere: the office reads the
        // message, hands the thing over and takes it off Stock.
        'is_material_request' => (bool)(int)($comment['is_material_request'] ?? 0),
        'media' => array_map('ops_api_comment_media_row', $media),
    ];
}

/** Photos and comments for one job, already company-scoped. */
function ops_api_job_detail(PDO $conn, array $job, array $user): array
{
    $jobId = (int)$job['id'];
    $companyId = (int)$job['company_id'];

    $photos = $conn->prepare("
        SELECT id, photo_type, media_kind, caption, created_at,
               (uploaded_by = ?) AS is_mine
        FROM ops_job_photos
        WHERE job_id = ? AND company_id = ?
        ORDER BY created_at ASC, id ASC
    ");
    $photos->execute([$user['id'], $jobId, $companyId]);

    $comments = $conn->prepare("
        SELECT c.id, c.comment, c.is_material_request, c.created_at, c.user_id,
               COALESCE(NULLIF(u.fullname, ''), u.username) AS author_name,
               (c.user_id = ?) AS is_mine
        FROM ops_job_comments c
        LEFT JOIN user u ON u.id = c.user_id
        WHERE c.job_id = ? AND c.company_id = ?
        ORDER BY c.created_at ASC, c.id ASC
    ");
    $comments->execute([$user['id'], $jobId, $companyId]);

    $detail = ops_api_job_row($job);
    $detail['description'] = $job['description'] !== null && $job['description'] !== ''
        ? (string)$job['description']
        : null;
    $detail['completion_notes'] = $job['completion_notes'] !== null && $job['completion_notes'] !== ''
        ? (string)$job['completion_notes']
        : null;
    $detail['started_at'] = $job['started_at'] !== null ? (string)$job['started_at'] : null;
    $detail['finished_at'] = $job['finished_at'] !== null ? (string)$job['finished_at'] : null;

    $jobStatus = (string)$job['status'];
    $detail['photos'] = array_map(
        static fn(array $row): array => ops_api_photo_row($row, $jobStatus),
        $photos->fetchAll(PDO::FETCH_ASSOC) ?: []
    );
    // Materials are not tracked per job any more: asking happens in the
    // conversation and the answer is a stock movement in the office. Still sent
    // as an empty list so an older build of the app has something to render.
    $detail['materials'] = [];

    $detail['places'] = ops_api_job_places($conn, $jobId);

    // Unit cleaning jobs only; null everywhere else. See ops_checklist.php.
    $detail['checklist'] = ops_checklist_for_app($conn, $job);

    // What the tenant photographed when they raised the request — the leak,
    // the broken socket. Read, never edited; served by request-photos/{id}.
    $detail['request_photos'] = array_map(
        static fn(array $row): array => [
            'id' => (int)$row['id'],
            'url_path' => 'request-photos/' . (int)$row['id'],
            'created_at' => (string)$row['created_at'],
        ],
        ops_request_photos($conn, $job)
    );

    $commentRows = $comments->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $mediaByComment = ops_api_comment_media(
        $conn,
        $jobId,
        $companyId,
        array_map(static fn(array $row): int => (int)$row['id'], $commentRows)
    );
    $detail['comments'] = array_map(
        static fn(array $row): array => ops_api_comment_row(
            $row,
            $mediaByComment[(int)$row['id']] ?? []
        ),
        $commentRows
    );

    return $detail;
}

/**
 * The real places a job covers, in the shape the app reads.
 *
 * The query itself is ops_job_places() in the web module's helper, shared so
 * the office and the phone cannot disagree about where a job was.
 */
function ops_api_job_places(PDO $conn, int $jobId): array
{
    return array_map('ops_api_place_row', ops_job_places($conn, [$jobId])[$jobId] ?? []);
}

/** One ops_job_places row, as the app reads it. */
function ops_api_place_row(array $row): array
{
    return [
        'kind' => (string)$row['place_kind'],
        'id' => (int)$row['place_id'],
        'building_id' => $row['building_id'] === null ? null : (int)$row['building_id'],
        'label' => (string)$row['label'],
    ];
}

/**
 * Every attachment on a job's messages, grouped by message.
 *
 * One query for the whole thread rather than one per message: a chatty
 * conversation is the normal case, and the job detail response is fetched
 * after every single write the queue delivers.
 *
 * @param int[] $commentIds
 * @return array<int, array<int, array>> comment id => its media rows, in order
 */
function ops_api_comment_media(PDO $conn, int $jobId, int $companyId, array $commentIds): array
{
    if ($commentIds === []) {
        return [];
    }

    $sql = "
        SELECT id, comment_id, kind, file_path, duration_seconds, created_at
        FROM ops_job_comment_media
        WHERE job_id = ? AND company_id = ?
          AND comment_id IN (" . implode(',', array_fill(0, count($commentIds), '?')) . ")
        ORDER BY id ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$jobId, $companyId], $commentIds));

    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $grouped[(int)$row['comment_id']][] = $row;
    }
    return $grouped;
}
