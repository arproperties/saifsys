<?php
/**
 * Store Camera — the door for the laptop program (tools/attendance_camera/store_camera.py).
 * Same key as the attendance camera: CAMERA_API_KEY in includes/config.php, sent as `X-Camera-Key`.
 *
 *   GET  ?action=cameras  -> {"ok": true, "cameras": [{"id": 1, "name": "Store", "rtsp_url": "rtsp://..."}]}
 *        The cameras switched on. Asking also marks them as watched (Operations -> Store Camera
 *        shows when the program last asked), so the program asks every few minutes.
 *   POST ?action=event    multipart: camera_id, started_at, ended_at ('Y-m-d H:i:s', the camera's
 *        local time), description (one line), snapshot (JPEG, optional)  -> {"ok": true, "id": 7}
 *   GET  ?action=live     -> {"ok": true, "want": [1], "count": [{"id": 5, "camera_id": 1}]}
 *        the cameras someone has open on the Live tab, and the item counts asked for there
 *   POST ?action=frame    multipart: camera_id, snapshot (JPEG)  -> {"ok": true, "want": [1], "count": [...]}
 *        the newest live picture, one overwritten file per camera; never part of a report.
 *   POST ?action=count    multipart: count_id, ok (1/0), snapshot (JPEG, when ok), note (why not, when 0)
 *        -> {"ok": true}   the picture for one count (now, or read back from the recording for its
 *        "at" time). saifsys then asks Reem to count the items in it, as the person who asked.
 */
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../modules/operations/includes/ops_store_camera.php';

function store_camera_send(array $body, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

$secret = defined('CAMERA_API_KEY') ? (string)CAMERA_API_KEY : '';
if ($secret === '') {
    store_camera_send(['ok' => false, 'error' => 'CAMERA_API_KEY is not set on this server.'], 503);
}
$given = (string)($_SERVER['HTTP_X_CAMERA_KEY'] ?? '');
if ($given === '' || !hash_equals($secret, $given)) {
    store_camera_send(['ok' => false, 'error' => 'Not authorised.'], 401);
}
if (!sc_ready($conn)) {
    store_camera_send(['ok' => false, 'error' => 'Store Camera is not set up: run migrations/store_camera.sql.'], 503);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string)($_GET['action'] ?? '');

if ($method === 'GET' && $action === 'cameras') {
    $cams = sc_cameras($conn, true);
    if ($cams) {
        $conn->exec('UPDATE store_cameras SET last_seen_at = NOW() WHERE active = 1');
    }
    // Answer the program first, then make the daily reports that are due (Reem takes a few seconds each).
    $body = json_encode(['ok' => true, 'cameras' => array_map(function ($c) {
        return ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'rtsp_url' => sc_rtsp_url($c)];
    }, $cams)], JSON_UNESCAPED_SLASHES);
    ignore_user_abort(true);
    @set_time_limit(600);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Connection: close');
    header('Content-Length: ' . strlen($body));
    echo $body;
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
    sc_reem_reports_due($conn);
    exit;
}

if ($method === 'POST' && $action === 'event') {
    $cam = sc_camera($conn, (int)($_POST['camera_id'] ?? 0));
    if (!$cam) {
        store_camera_send(['ok' => false, 'error' => 'No such camera.'], 404);
    }
    $isTime = function ($v) {
        $d = DateTime::createFromFormat('Y-m-d H:i:s', (string)$v);
        return $d && $d->format('Y-m-d H:i:s') === (string)$v;
    };
    $from = (string)($_POST['started_at'] ?? '');
    $to = (string)($_POST['ended_at'] ?? '');
    if (!$isTime($from) || !$isTime($to) || $to < $from) {
        store_camera_send(['ok' => false, 'error' => 'started_at and ended_at must be like 2026-11-23 10:05:00, the end not before the start.'], 422);
    }
    $what = trim(preg_replace('/\s+/', ' ', (string)($_POST['description'] ?? '')));
    if ($what === '') {
        store_camera_send(['ok' => false, 'error' => 'No description.'], 422);
    }
    $what = mb_substr($what, 0, 500);

    // The snapshot: a JPEG only, kept outside what the web server hands out.
    $rel = null;
    $f = $_FILES['snapshot'] ?? null;
    if ($f && (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string)$f['tmp_name'])) {
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if ($mime !== 'image/jpeg' || (int)$f['size'] > 4 * 1024 * 1024) {
            store_camera_send(['ok' => false, 'error' => 'The snapshot must be a JPEG of 4 MB or less.'], 422);
        }
        sc_upload_base();
        $relDir = 'uploads/store_camera_events/' . (int)$cam['id'] . '/' . substr($from, 0, 10);
        if (!is_dir(sc_abs($relDir)) && !@mkdir(sc_abs($relDir), 0775, true) && !is_dir(sc_abs($relDir))) {
            store_camera_send(['ok' => false, 'error' => 'The server could not make the snapshot folder.'], 500);
        }
        $rel = $relDir . '/' . str_replace([' ', ':'], ['_', ''], $from) . '_' . bin2hex(random_bytes(4)) . '.jpg';
        if (!move_uploaded_file($f['tmp_name'], sc_abs($rel))) {
            store_camera_send(['ok' => false, 'error' => 'The server could not save the snapshot.'], 500);
        }
        sc_shrink_jpeg(sc_abs($rel));
        @chmod(sc_abs($rel), 0644);
    }

    // The program did not describe it: Reem says what the snapshot shows ("Movement seen" if Reem cannot).
    if (preg_match('/^Movement seen\b/i', $what)) {
        $what = 'Movement seen';
    }
    if ($rel && $what === 'Movement seen') {
        try {
            $refPhoto = !empty($cam['reference_photo_id']) ? sc_photo($conn, (int)$cam['reference_photo_id']) : null;
            $said = sc_reem_describe($conn, (int)$cam['created_by'], sc_abs($rel), $refPhoto ? sc_abs((string)$refPhoto['path']) : null);
            if ($said['what'] !== '') {
                $what = $said['what'];
                $short = $said['short'];
            }
        } catch (Throwable $e) {
            error_log('Store camera describe: ' . $e->getMessage());
        }
    }
    $st = $conn->prepare('INSERT INTO store_camera_events (camera_id, started_at, ended_at, description, snapshot) VALUES (?, ?, ?, ?, ?)');
    $st->execute([(int)$cam['id'], $from, $to, $what, $rel]);
    $eventId = (int)$conn->lastInsertId();
    // The bell gets one short line; the full description is kept with the movement (and goes into the report).
    sc_alert($conn, $cam, 'movement', $cam['name'] . ': movement at ' . substr($from, 11, 5), $short ?? sc_short_text($what),
        'store_camera.php?camera=' . (int)$cam['id'] . '&day=' . substr($from, 0, 10));
    store_camera_send(['ok' => true, 'id' => $eventId]);
}

if ($method === 'GET' && $action === 'live') {
    store_camera_send(['ok' => true, 'want' => sc_live_wanted($conn), 'count' => sc_counts_wanted($conn)]);
}

if ($method === 'POST' && $action === 'count') {
    $row = sc_count($conn, (int)($_POST['count_id'] ?? 0));
    if (!$row) {
        store_camera_send(['ok' => false, 'error' => 'No such count.'], 404);
    }
    if ($row['status'] !== 'waiting') {
        store_camera_send(['ok' => false, 'error' => 'That count is not waiting for a picture any more.'], 409);
    }
    $id = (int)$row['id'];
    $note = trim(preg_replace('/\s+/', ' ', (string)($_POST['note'] ?? '')));
    if ((string)($_POST['ok'] ?? '') !== '1') {
        // The program could not get the picture (no fresh live picture, nothing recorded at that time ...).
        sc_save_count($conn, $id, false, [], $note !== '' ? $note : 'The laptop program could not get the picture.', null);
        store_camera_send(['ok' => true]);
    }
    // The picture: a JPEG only, kept beside the event snapshots.
    $f = $_FILES['snapshot'] ?? null;
    if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])
        || (string)(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) !== 'image/jpeg' || (int)$f['size'] > 4 * 1024 * 1024) {
        store_camera_send(['ok' => false, 'error' => 'The picture must be a JPEG of 4 MB or less.'], 422);
    }
    sc_upload_base();
    $relDir = 'uploads/store_camera_events/counts/' . (int)$row['camera_id'];
    $rel = $relDir . '/count_' . $id . '_' . bin2hex(random_bytes(4)) . '.jpg';
    if ((!is_dir(sc_abs($relDir)) && !@mkdir(sc_abs($relDir), 0775, true) && !is_dir(sc_abs($relDir)))
        || !move_uploaded_file($f['tmp_name'], sc_abs($rel))) {
        sc_save_count($conn, $id, false, [], 'The server could not save the picture.', null);
        store_camera_send(['ok' => false, 'error' => 'The server could not save the picture.'], 500);
    }
    sc_shrink_jpeg(sc_abs($rel));
    @chmod(sc_abs($rel), 0644);
    sc_mark_counting($conn, $id, $rel);

    // A check is compared with the reference picture here, for free: no Reem, no cost. ("Ask how many"
    // on a changed pin is the only count, and it is asked for by hand.)
    if (!empty($row['is_check'])) {
        $cam = sc_camera($conn, (int)$row['camera_id']);
        $refPhoto = $cam && !empty($cam['reference_photo_id']) ? sc_photo($conn, (int)$cam['reference_photo_id']) : null;
        if (!$refPhoto) {
            sc_save_count($conn, $id, false, [], 'No reference picture yet: press "Use live picture as reference" on the Live tab.', $rel);
            store_camera_send(['ok' => true]);
        }
        $diff = sc_compare_pictures([], sc_abs((string)$refPhoto['path']), sc_abs($rel));
        if ($diff === null) {
            sc_save_count($conn, $id, false, [], 'The pictures could not be compared.', $rel);
            store_camera_send(['ok' => true]);
        }
        sc_save_diff($conn, $cam, $id, $diff, $rel);
        store_camera_send(['ok' => true]);
    }

    // The second picture of a compare uses the names the first count gave, so the two lists line up.
    $names = [];
    if ($row['compare_id'] && $row['side'] === 'to') {
        $st = $conn->prepare("SELECT items FROM store_camera_counts WHERE compare_id = ? AND side = 'from' AND status = 'done' LIMIT 1");
        $st->execute([(int)$row['compare_id']]);
        $names = array_column(json_decode((string)$st->fetchColumn(), true) ?: [], 'item');
    }
    // A check is counted against the camera's reference: its picture, with the numbered pins drawn on it,
    // and its starting list (names, counts and the pins of each item).
    $cam = sc_camera($conn, (int)$row['camera_id']);
    $refPath = null;
    $refMade = false;
    $refItems = [];
    if (!empty($row['is_check']) && $cam) {
        $refItems = sc_reference_for_reem($cam);
        $refPhoto = !empty($cam['reference_photo_id']) ? sc_photo($conn, (int)$cam['reference_photo_id']) : null;
        if ($refPhoto) {
            [$refPath, $refMade] = sc_marked_reference($cam, sc_abs((string)$refPhoto['path']));
        }
        $names = $names ?: array_column($refItems, 'item');
    }
    // Reem counts it, as the person it is for (it is billed to them). This can take a little while.
    @set_time_limit(200);
    ignore_user_abort(true);
    try {
        $got = sc_reem_count($conn, (int)$row['requested_by'], sc_abs($rel), $names, $refPath, $refItems);
        sc_save_count($conn, $id, true, $got['items'], $got['note'], $rel);
        if (!empty($row['is_check']) && $cam) {
            sc_after_check($conn, $cam, $id);
        }
    } catch (BinvError $e) {
        sc_save_count($conn, $id, false, [], 'Reem: ' . $e->getMessage(), $rel);
    } finally {
        if ($refMade) {
            @unlink($refPath); // the copy with the pins drawn on it
        }
    }
    store_camera_send(['ok' => true]);
}

if ($method === 'POST' && $action === 'frame') {
    $id = (int)($_POST['camera_id'] ?? 0);
    $f = $_FILES['snapshot'] ?? null;
    if (!sc_camera($conn, $id) || !$f || (int)($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
        store_camera_send(['ok' => false, 'error' => 'A camera_id and a JPEG are needed.', 'want' => sc_live_wanted($conn)], 422);
    }
    if ((string)(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) !== 'image/jpeg' || (int)$f['size'] > 2 * 1024 * 1024) {
        store_camera_send(['ok' => false, 'error' => 'The picture must be a JPEG of 2 MB or less.', 'want' => sc_live_wanted($conn)], 422);
    }
    store_camera_send(['ok' => sc_live_save($id, (string)$f['tmp_name']), 'want' => sc_live_wanted($conn), 'count' => sc_counts_wanted($conn)]);
}

store_camera_send(['ok' => false, 'error' => 'Unknown action.'], 404);
