<?php
/**
 * Jarvis API v1 — the Operations module: the cleaning and maintenance jobs the field
 * staff do on the staff app.
 *
 * Loaded only by ../index.php, after the key has been checked; opened directly it
 * answers nothing. $conn, $action and the jarvis_api_* helpers come from there.
 *
 * View only: every action reads, none writes. Tenant requests become jobs when the
 * office board or a staff phone loads its list (ops_sources.php), not here.
 *
 * WHO SEES WHICH JOBS is decided in Jarvis, where each building has an administrator
 * and the cleaners and technicians given to them. Jarvis sends that as the scope, and
 * every action except `buildings` refuses to answer without one:
 *
 *   buildings=3,7        re_buildings ids: every job with a place in those buildings
 *   codes=E00012,E00031  employee codes: also those people's jobs that have no
 *                        building on them (the older free-text jobs)
 *
 *   ?module=operations&action=buildings
 *        every building, for the picker.
 *   ?module=operations&action=jobs&buildings=&codes=&from=&to=&late=1&status=&type=&code=
 *        the jobs scheduled from..to (default today, 31 days at most). late=1 adds
 *        the unfinished ones from before `from`. code = one person's jobs only.
 *   ?module=operations&action=job&id=&buildings=&codes=
 *        one job in full: places, photos, checklist, messages.
 *   ?module=operations&action=photo&id=&buildings=&codes=
 *   ?module=operations&action=media&id=&buildings=&codes=
 *        the bytes of one before/after photo or video, or one message attachment.
 */

declare(strict_types=1);

if (!defined('JARVIS_API')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../../../modules/operations/includes/ops_helper.php';
require_once __DIR__ . '/../../../../modules/operations/includes/ops_checklist.php';

const JARVIS_OPS_LIST_MAX = 300;
const JARVIS_OPS_MAX_DAYS = 31;

function jarvis_ops_date(string $name, string $default): string
{
    $v = trim((string)($_GET[$name] ?? ''));
    if ($v === '') {
        return $default;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        jarvis_api_error('bad_request', "$name must be YYYY-MM-DD.", 400);
    }
    return $v;
}

/** A comma-separated list from the query string, trimmed, no blanks, no repeats. */
function jarvis_ops_list(string $name): array
{
    $out = [];
    foreach (explode(',', (string)($_GET[$name] ?? '')) as $v) {
        $v = trim($v);
        if ($v !== '') {
            $out[$v] = true;
        }
    }
    return array_slice(array_keys($out), 0, 200);
}

/**
 * The scope Jarvis sent, as SQL on ops_jobs `j`.
 *
 * @return array{0:string,1:array,2:array} the condition, its arguments, and the people
 *         behind the codes (code, name, user_id — null when they have no login)
 */
function jarvis_ops_scope(PDO $conn): array
{
    $buildings = array_values(array_filter(array_map('intval', jarvis_ops_list('buildings'))));
    $codes = jarvis_ops_list('codes');
    if (!$buildings && !$codes) {
        jarvis_api_error('bad_request', 'A scope is required: buildings and/or codes.', 400);
    }

    $staff = [];
    if ($codes) {
        $in = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $conn->prepare("SELECT employee_code, full_name, user_id FROM employees WHERE employee_code IN ($in)");
        $stmt->execute($codes);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $staff[] = [
                'code' => (string)$e['employee_code'],
                'name' => (string)$e['full_name'],
                'user_id' => $e['user_id'] !== null && (int)$e['user_id'] > 0 ? (int)$e['user_id'] : null,
            ];
        }
    }
    $userIds = array_values(array_filter(array_column($staff, 'user_id')));

    $parts = [];
    $args = [];
    if ($buildings) {
        $in = implode(',', array_fill(0, count($buildings), '?'));
        $parts[] = "EXISTS (SELECT 1 FROM ops_job_places sp WHERE sp.job_id = j.id AND sp.building_id IN ($in))";
        $args = array_merge($args, $buildings);
    }
    if ($userIds) {
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $parts[] = "(j.assigned_to IN ($in) AND NOT EXISTS
            (SELECT 1 FROM ops_job_places sn WHERE sn.job_id = j.id AND sn.building_id IS NOT NULL))";
        $args = array_merge($args, $userIds);
    }
    // Codes that match nobody with a login, and no building: nothing can be in scope.
    return [$parts ? '(' . implode(' OR ', $parts) . ')' : '0', $args, $staff];
}

const JARVIS_OPS_SELECT = "SELECT j.*,
    COALESCE(NULLIF(u.fullname, ''), u.username) AS assignee_name,
    (SELECT e.employee_code FROM employees e WHERE e.user_id = j.assigned_to LIMIT 1) AS assignee_code,
    (SELECT COUNT(*) FROM ops_job_photos bp WHERE bp.job_id = j.id AND bp.photo_type = 'before') AS before_count,
    (SELECT COUNT(*) FROM ops_job_photos ap WHERE ap.job_id = j.id AND ap.photo_type = 'after') AS after_count,
    (SELECT COUNT(*) FROM ops_job_comments cc WHERE cc.job_id = j.id) AS comment_count,
    UNIX_TIMESTAMP(j.created_at) AS created_ts
    FROM ops_jobs j
    LEFT JOIN user u ON u.id = j.assigned_to";

/** Where a job came from, in words. */
function jarvis_ops_source(string $type): string
{
    return [
        'staff' => 'Raised by staff',
        'work_order' => 'Work order',
        'tenant_maintenance' => 'Tenant maintenance request',
        'tenant_cleaning' => 'Tenant cleaning booking',
        'cleaner_report' => 'Problem a cleaner reported',
        'ars_checkout' => 'ARS guest checkout',
        'tenant_move_out' => 'Tenant move-out',
        'customer_booking' => 'Customer app booking',
    ][$type] ?? ucfirst(str_replace('_', ' ', $type));
}

/** The line every list shows. Times are Dubai wall-clock, as the office board prints them. */
function jarvis_ops_row(array $j): array
{
    $status = (string)$j['status'];
    $paused = $status === 'in_progress' && !empty($j['paused_at']);
    $source = (string)($j['source_type'] ?? 'staff');
    return [
        'id' => (int)$j['id'],
        'type' => (string)$j['job_type'],
        'title' => (string)$j['title'],
        'location' => $j['location'] !== null && $j['location'] !== '' ? (string)$j['location'] : null,
        'assignee' => $j['assigned_to'] !== null
            ? ['name' => (string)($j['assignee_name'] ?? ''), 'code' => $j['assignee_code'] ?: null]
            : null,
        'date' => (string)$j['scheduled_date'],
        'time' => !empty($j['scheduled_time']) ? substr((string)$j['scheduled_time'], 0, 5) : null,
        'priority' => (string)$j['priority'],
        'status' => $status,
        'status_label' => $paused ? 'Paused' : ops_status_label($status),
        'paused' => $paused,
        'pause_reason' => $paused ? (ops_pause_reasons()[(string)$j['pause_reason']] ?? null) : null,
        'late' => ops_job_is_late($j),
        'late_note' => ops_late_note($j) ?: null,
        'started_at' => !empty($j['started_at']) ? (string)$j['started_at'] : null,
        'finished_at' => !empty($j['finished_at']) ? (string)$j['finished_at'] : null,
        'duration' => $j['duration_minutes'] !== null ? ops_format_duration((int)$j['duration_minutes']) : null,
        'needs_materials' => (bool)(int)$j['needs_materials'],
        'source' => $source,
        'source_label' => jarvis_ops_source($source),
        'photos' => ['before' => (int)$j['before_count'], 'after' => (int)$j['after_count']],
        'messages' => (int)$j['comment_count'],
        'created_at' => $j['created_ts'] !== null ? (int)$j['created_ts'] : null,
    ];
}

/** One message, without its attachments. `from_staff`: written by the person doing the job. */
function jarvis_ops_message(array $c): array
{
    return [
        'id' => (int)$c['id'],
        'by' => $c['author_name'] !== null && $c['author_name'] !== '' ? (string)$c['author_name'] : null,
        'from_staff' => $c['user_id'] !== null && $c['assigned_to'] !== null && (int)$c['user_id'] === (int)$c['assigned_to'],
        'text' => (string)$c['comment'],
        'material_request' => (bool)(int)($c['is_material_request'] ?? 0),
        'at' => $c['created_ts'] !== null ? (int)$c['created_ts'] : null,
    ];
}

const JARVIS_OPS_MESSAGE_SELECT = "SELECT c.id, c.job_id, c.user_id, c.comment, c.is_material_request,
    UNIX_TIMESTAMP(c.created_at) AS created_ts, j.assigned_to,
    COALESCE(NULLIF(cu.fullname, ''), cu.username) AS author_name
    FROM ops_job_comments c
    JOIN ops_jobs j ON j.id = c.job_id
    LEFT JOIN user cu ON cu.id = c.user_id";

/** The problems a cleaner ticked on Finish, as words. */
function jarvis_ops_problem_labels(array $keys): array
{
    $labels = ops_cleaning_problems();
    return array_values(array_map(fn($k) => $labels[$k] ?? (string)$k, $keys));
}

/** The job, if it is inside the scope. */
function jarvis_ops_job_in_scope(PDO $conn, int $jobId, string $scope, array $scopeArgs): ?array
{
    $stmt = $conn->prepare(JARVIS_OPS_SELECT . " WHERE j.id = ? AND $scope LIMIT 1");
    $stmt->execute(array_merge([$jobId], $scopeArgs));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Send one stored file, or 404. Same checks as modules/operations/photo.php. */
function jarvis_ops_serve(?string $relPath, bool $allowAudio): void
{
    $appRoot = dirname(__DIR__, 4);
    $baseDir = realpath($appRoot . '/uploads/operations');
    $absPath = $relPath !== null ? realpath($appRoot . '/' . ltrim($relPath, '/')) : false;
    if ($baseDir === false || $absPath === false || strpos($absPath, $baseDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($absPath)) {
        jarvis_api_error('not_found', 'That file could not be found.', 404);
    }
    $contentType = ops_comment_media_content_type(strtolower(pathinfo($absPath, PATHINFO_EXTENSION)));
    if ($contentType === null || (!$allowAudio && strpos($contentType, 'audio/') === 0)) {
        jarvis_api_error('not_found', 'That file could not be found.', 404);
    }
    ops_serve_file_with_ranges($absPath, $contentType);
}

// ---------------------------------------------------------------------------

if ($action === 'buildings') {
    $rows = $conn->query("SELECT id, name FROM re_buildings ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    jarvis_api_send([
        'ok' => true,
        'buildings' => array_map(fn($b) => ['id' => (int)$b['id'], 'name' => (string)$b['name']], $rows),
    ]);
}

if ($action === 'jobs') {
    [$scope, $scopeArgs, $staff] = jarvis_ops_scope($conn);

    $today = date('Y-m-d');
    $from = jarvis_ops_date('from', $today);
    $to = jarvis_ops_date('to', $from);
    if ($to < $from) {
        jarvis_api_error('bad_request', 'to is before from.', 400);
    }
    if ((strtotime($to) - strtotime($from)) / 86400 > JARVIS_OPS_MAX_DAYS) {
        jarvis_api_error('bad_request', 'At most ' . JARVIS_OPS_MAX_DAYS . ' days at a time.', 400);
    }

    $where = [$scope];
    $args = $scopeArgs;

    if (!empty($_GET['late'])) {
        $where[] = "(j.scheduled_date BETWEEN ? AND ? OR (j.scheduled_date < ? AND j.status IN ('open','in_progress')))";
        array_push($args, $from, $to, $from);
    } else {
        $where[] = 'j.scheduled_date BETWEEN ? AND ?';
        array_push($args, $from, $to);
    }

    $status = trim((string)($_GET['status'] ?? ''));
    if ($status !== '') {
        if (!array_key_exists($status, ops_statuses())) {
            jarvis_api_error('bad_request', 'status must be one of: ' . implode(', ', array_keys(ops_statuses())) . '.', 400);
        }
        $where[] = 'j.status = ?';
        $args[] = $status;
    } else {
        $where[] = "j.status <> 'cancelled'";
    }

    $type = trim((string)($_GET['type'] ?? ''));
    if ($type !== '') {
        if (!array_key_exists($type, ops_job_types())) {
            jarvis_api_error('bad_request', 'type must be cleaning or maintenance.', 400);
        }
        $where[] = 'j.job_type = ?';
        $args[] = $type;
    }

    $code = trim((string)($_GET['code'] ?? ''));
    if ($code !== '') {
        $where[] = 'j.assigned_to = (SELECT e2.user_id FROM employees e2 WHERE e2.employee_code = ? LIMIT 1)';
        $args[] = $code;
    }

    $stmt = $conn->prepare(JARVIS_OPS_SELECT . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY j.scheduled_date DESC, j.scheduled_time IS NULL, j.scheduled_time, j.id LIMIT ' . (JARVIS_OPS_LIST_MAX + 1));
    $stmt->execute($args);
    $found = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $more = count($found) > JARVIS_OPS_LIST_MAX;
    $found = array_slice($found, 0, JARVIS_OPS_LIST_MAX);
    $ids = array_map(fn($j) => (int)$j['id'], $found);

    $places = ops_job_places($conn, $ids);
    $lastMessage = [];
    $problems = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare(JARVIS_OPS_MESSAGE_SELECT . "
            JOIN (SELECT MAX(id) AS id FROM ops_job_comments WHERE job_id IN ($in) GROUP BY job_id) last ON last.id = c.id");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $lastMessage[(int)$c['job_id']] = jarvis_ops_message($c);
        }
        try {
            $stmt = $conn->prepare("SELECT job_id, problems, problem_note, maintenance_job_id FROM ops_job_checklists
                WHERE job_id IN ($in) AND problems IS NOT NULL AND problems <> ''");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $problems[(int)$p['job_id']] = [
                    'found' => jarvis_ops_problem_labels(explode(',', (string)$p['problems'])),
                    'note' => $p['problem_note'] !== null && $p['problem_note'] !== '' ? (string)$p['problem_note'] : null,
                    'maintenance_job_id' => $p['maintenance_job_id'] !== null ? (int)$p['maintenance_job_id'] : null,
                ];
            }
        } catch (PDOException $e) {
            // A database without the checklist migration: jobs still list.
            error_log('jarvis operations problems failed: ' . $e->getMessage());
        }
    }

    $jobs = [];
    $counts = ['open' => 0, 'in_progress' => 0, 'done' => 0, 'cancelled' => 0, 'late' => 0, 'needs_materials' => 0];
    foreach ($found as $j) {
        $row = jarvis_ops_row($j);
        $id = $row['id'];
        $row['places'] = array_map(fn($p) => (string)$p['label'], $places[$id] ?? []);
        $row['building_ids'] = array_values(array_unique(array_filter(array_map(
            fn($p) => $p['building_id'] !== null ? (int)$p['building_id'] : 0, $places[$id] ?? []))));
        $row['last_message'] = $lastMessage[$id] ?? null;
        $row['problems'] = $problems[$id] ?? null;
        $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
        $counts['late'] += $row['late'] ? 1 : 0;
        $counts['needs_materials'] += $row['needs_materials'] ? 1 : 0;
        $jobs[] = $row;
    }

    jarvis_api_send([
        'ok' => true,
        'today' => $today,
        'from' => $from,
        'to' => $to,
        'total' => count($jobs),
        'more' => $more,
        'counts' => $counts,
        'staff' => array_map(fn($s) => ['code' => $s['code'], 'name' => $s['name'], 'has_login' => $s['user_id'] !== null], $staff),
        'jobs' => $jobs,
    ]);
}

if ($action === 'job') {
    [$scope, $scopeArgs] = jarvis_ops_scope($conn);
    $jobId = (int)($_GET['id'] ?? 0);
    $j = jarvis_ops_job_in_scope($conn, $jobId, $scope, $scopeArgs);
    if (!$j) {
        jarvis_api_error('not_found', 'No such job in these buildings.', 404);
    }

    $job = jarvis_ops_row($j);
    $placeRows = ops_job_places($conn, [$jobId])[$jobId] ?? [];
    $job['places'] = array_map(fn($p) => (string)$p['label'], $placeRows);
    $job['building_ids'] = array_values(array_unique(array_filter(array_map(
        fn($p) => $p['building_id'] !== null ? (int)$p['building_id'] : 0, $placeRows))));
    $job['description'] = $j['description'] !== null && $j['description'] !== '' ? (string)$j['description'] : null;
    $job['completion_notes'] = $j['completion_notes'] !== null && $j['completion_notes'] !== '' ? (string)$j['completion_notes'] : null;

    $stmt = $conn->prepare("SELECT id, photo_type, media_kind FROM ops_job_photos WHERE job_id = ? ORDER BY created_at, id");
    $stmt->execute([$jobId]);
    $job['photo_list'] = array_map(fn($p) => [
        'id' => (int)$p['id'],
        'when' => (string)$p['photo_type'],
        'kind' => ($p['media_kind'] ?? 'photo') === 'video' ? 'video' : 'photo',
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    // The checklist as the cleaner answered it, section by section. null on jobs without one.
    $job['checklist'] = null;
    $job['problems'] = null;
    $saved = ops_job_needs_checklist($conn, $j) ? ops_checklist_load($conn, $jobId) : null;
    if ($saved) {
        $job['checklist'] = array_map(fn($section) => [
            'title' => $section['title'],
            'items' => array_map(
                fn($key, $label) => ['label' => $label, 'answer' => $saved['items'][$key] ?? null],
                array_keys($section['items']), array_values($section['items'])
            ),
        ], ops_cleaning_checklist());
        if ($saved['problems']) {
            $job['problems'] = [
                'found' => jarvis_ops_problem_labels($saved['problems']),
                'note' => $saved['problem_note'],
                'maintenance_job_id' => $saved['maintenance_job_id'],
            ];
        }
    }

    $stmt = $conn->prepare(JARVIS_OPS_MESSAGE_SELECT . ' WHERE c.job_id = ? ORDER BY c.id');
    $stmt->execute([$jobId]);
    $messages = array_map('jarvis_ops_message', $stmt->fetchAll(PDO::FETCH_ASSOC));
    $media = [];
    try {
        $stmt = $conn->prepare("SELECT id, comment_id, kind, duration_seconds FROM ops_job_comment_media WHERE job_id = ? ORDER BY id");
        $stmt->execute([$jobId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $media[(int)$m['comment_id']][] = [
                'id' => (int)$m['id'],
                'kind' => (string)$m['kind'],
                'seconds' => $m['duration_seconds'] !== null ? (int)$m['duration_seconds'] : null,
            ];
        }
    } catch (PDOException $e) {
        error_log('jarvis operations media failed: ' . $e->getMessage());
    }
    foreach ($messages as &$m) {
        $m['media'] = $media[$m['id']] ?? [];
    }
    unset($m);
    $job['message_list'] = $messages;

    jarvis_api_send(['ok' => true, 'job' => $job]);
}

if ($action === 'photo' || $action === 'media') {
    [$scope, $scopeArgs] = jarvis_ops_scope($conn);
    $table = $action === 'photo' ? 'ops_job_photos' : 'ops_job_comment_media';
    $stmt = $conn->prepare("SELECT file_path, job_id FROM $table WHERE id = ? LIMIT 1");
    $stmt->execute([(int)($_GET['id'] ?? 0)]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$file || !jarvis_ops_job_in_scope($conn, (int)$file['job_id'], $scope, $scopeArgs)) {
        jarvis_api_error('not_found', 'That file could not be found.', 404);
    }
    jarvis_ops_serve((string)$file['file_path'], $action === 'media');
}

jarvis_api_error('not_found', 'Unknown Operations action.', 404);
