<?php
/**
 * Operations — Store Camera: the cameras, the events the laptop program sends, and
 * the daily report built from them. Tables: migrations/store_camera.sql.
 * The program: tools/attendance_camera/store_camera.py. Its door: api/store_camera.php.
 */

class StoreCamError extends RuntimeException {}

// One clock for everything Store Camera: Dubai, the time the camera and the laptop program use.
// PHP is set here, and the database connection in sc_ready(), so times written by PHP, by the
// database (NOW()) and by the program all compare the same, whatever the server's own zone is.
const SC_TZ = 'Asia/Dubai';
const SC_TZ_OFFSET = '+04:00'; // Dubai keeps no summer time
date_default_timezone_set(SC_TZ);

const SC_MAX_HOURS = 24;
const SC_QUIET_MINUTES = 60; // a stretch this long with nothing in it shows as "No events"

/** Are the tables there (the migration run)? */
function sc_ready(PDO $conn): bool {
    static $clockSet = false;
    if (!$clockSet) {
        $conn->exec("SET time_zone = '" . SC_TZ_OFFSET . "'"); // this request's database clock: Dubai (see SC_TZ)
        $clockSet = true;
    }
    try {
        $conn->query('SELECT 1 FROM store_cameras LIMIT 1');
        $conn->query('SELECT 1 FROM store_camera_events LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/** Every camera, oldest first. */
function sc_cameras(PDO $conn, bool $activeOnly = false): array {
    $sql = 'SELECT * FROM store_cameras' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY id';
    return $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sc_camera(PDO $conn, int $id): ?array {
    $st = $conn->prepare('SELECT * FROM store_cameras WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The live address the laptop program opens, built as on HR -> Attendance Cameras. */
function sc_rtsp_url(array $cam): string {
    $path = '/' . ltrim((string)($cam['stream_path'] ?: '/H.264'), '/');
    return 'rtsp://' . rawurlencode((string)($cam['username'] ?: 'admin')) . ':' . rawurlencode((string)$cam['verification_code'])
        . '@' . $cam['ip_address'] . ':' . (int)($cam['rtsp_port'] ?: 554) . $path;
}

/** "16:00" -> "16:00:00", or null when it is not a time. */
function sc_time($raw): ?string {
    $t = trim((string)$raw);
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $t, $m)) {
        return null;
    }
    return sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
}

/**
 * Add or change a camera, entered as on HR -> Attendance Cameras. An empty verification
 * code on a change keeps the one saved (the page never shows it). Returns the id.
 */
function sc_save_camera(PDO $conn, ?int $id, array $in, int $userId): int {
    $name = trim(preg_replace('/\s+/', ' ', (string)($in['name'] ?? '')));
    $ip = trim((string)($in['ip_address'] ?? ''));
    $code = trim((string)($in['verification_code'] ?? ''));
    $port = (int)($in['rtsp_port'] ?? 554);
    $path = '/' . ltrim(trim((string)($in['stream_path'] ?? '')) ?: '/H.264', '/');
    $time = sc_time($in['report_time'] ?? '');
    $hours = (int)($in['hours_back'] ?? 0);
    if ($name === '' || mb_strlen($name) > 100) {
        throw new StoreCamError('Give the camera a room / name.');
    }
    if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $ip)) {
        throw new StoreCamError('Type the camera\'s IP address or network name, like 192.168.70.124 (EZVIZ app -> camera -> Settings -> Device Information).');
    }
    if ($id === null && $code === '') {
        throw new StoreCamError('Type the verification code: the 6 capital letters on the camera\'s sticker.');
    }
    if (mb_strlen($code) > 64 || mb_strlen($path) > 100 || preg_match('/\s/', $path)) {
        throw new StoreCamError('The verification code or stream path is too long or has spaces.');
    }
    if ($port < 1 || $port > 65535) {
        throw new StoreCamError('The RTSP port is a number from 1 to 65535 (554 on EZVIZ).');
    }
    if ($time === null) {
        throw new StoreCamError('Pick the report time, like 16:00.');
    }
    if ($hours < 1 || $hours > SC_MAX_HOURS) {
        throw new StoreCamError('The report covers 1 to ' . SC_MAX_HOURS . ' hours.');
    }
    $active = !empty($in['active']) ? 1 : 0;
    if ($id === null) {
        $st = $conn->prepare('INSERT INTO store_cameras (name, ip_address, verification_code, rtsp_port, stream_path, report_time, hours_back, active, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$name, $ip, $code, $port, $path, $time, $hours, $active, $userId]);
        $id = (int)$conn->lastInsertId();
    } else {
        if (!sc_camera($conn, $id)) {
            throw new StoreCamError('That camera is not there any more.');
        }
        $st = $conn->prepare('UPDATE store_cameras SET name = ?, ip_address = ?, verification_code = COALESCE(NULLIF(?, \'\'), verification_code),
            rtsp_port = ?, stream_path = ?, report_time = ?, hours_back = ?, active = ? WHERE id = ?');
        $st->execute([$name, $ip, $code, $port, $path, $time, $hours, $active, $id]);
    }
    if (sc_checks_ready($conn) && isset($in['check_every_hours'])) {
        sc_save_check_settings($conn, $id, $in);
    }
    return $id;
}

/** A camera and everything it saw, snapshots included. */
function sc_delete_camera(PDO $conn, int $id): void {
    foreach (sc_snapshot_paths($conn, $id) as $path) {
        @unlink(sc_abs($path));
    }
    if (sc_counts_ready($conn)) {
        $st = $conn->prepare('SELECT snapshot FROM store_camera_counts WHERE camera_id = ? AND snapshot IS NOT NULL');
        $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $path) {
            @unlink(sc_abs($path));
        }
    }
    if (sc_photos_ready($conn)) {
        $st = $conn->prepare('SELECT path FROM store_camera_photos WHERE camera_id = ?');
        $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $path) {
            @unlink(sc_abs($path));
        }
    }
    @unlink(sc_live_path($id));
    $conn->prepare('DELETE FROM store_cameras WHERE id = ?')->execute([$id]);
}

function sc_snapshot_paths(PDO $conn, int $cameraId): array {
    $st = $conn->prepare('SELECT snapshot FROM store_camera_events WHERE camera_id = ? AND snapshot IS NOT NULL');
    $st->execute([$cameraId]);
    return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/** The folder for snapshots: outside what the web server hands out. */
function sc_abs(string $rel): string {
    return dirname(__DIR__, 3) . '/' . ltrim($rel, '/');
}

/** uploads/store_camera_events, made on first use with a deny-all .htaccess: files go out only through store_camera_file.php. */
function sc_upload_base(): string {
    $base = sc_abs('uploads/store_camera_events');
    if (!is_dir($base)) {
        @mkdir($base, 0775, true);
    }
    if (!is_file($base . '/.htaccess')) {
        @file_put_contents($base . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $base;
}

// ---------- live view ----------

const SC_LIVE_WANT_SECONDS = 30; // the program keeps sending this long after the Live tab last asked
const SC_LIVE_STALE_SECONDS = 20; // an older picture is not shown as live

/** The newest live picture of a camera, one overwritten file. */
function sc_live_path(int $cameraId): string {
    return sc_abs('uploads/store_camera_events/live/' . $cameraId . '.jpg');
}

/** Someone has the Live tab open on this camera. */
function sc_want_live(PDO $conn, int $cameraId): void {
    $conn->prepare('UPDATE store_cameras SET live_wanted_at = NOW() WHERE id = ?')->execute([$cameraId]);
}

/** The cameras someone is watching now: the program sends their pictures. */
function sc_live_wanted(PDO $conn): array {
    $st = $conn->prepare('SELECT id FROM store_cameras WHERE active = 1 AND live_wanted_at > NOW() - INTERVAL ? SECOND');
    $st->execute([SC_LIVE_WANT_SECONDS]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/** Keep the newest picture, replacing the last in one step so the page never reads half a file. */
function sc_live_save(int $cameraId, string $tmp): bool {
    sc_upload_base();
    $dir = dirname(sc_live_path($cameraId));
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $part = $dir . '/' . $cameraId . '.part';
    if (!move_uploaded_file($tmp, $part)) {
        return false;
    }
    return @rename($part, sc_live_path($cameraId)) || (@copy($part, sc_live_path($cameraId)) && @unlink($part));
}

/**
 * The report window for a day: it ends at the camera's report time that day and
 * starts hours_back before. [start, end] as 'Y-m-d H:i:s'.
 */
function sc_window(array $cam, string $day): array {
    $end = new DateTimeImmutable($day . ' ' . $cam['report_time']);
    $start = $end->modify('-' . (int)$cam['hours_back'] . ' hours');
    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

/** The latest report that is ready: today's once its time has passed, else yesterday's. */
function sc_latest_day(array $cam): string {
    $now = new DateTimeImmutable();
    return $now->format('H:i:s') >= $cam['report_time'] ? $now->format('Y-m-d') : $now->modify('-1 day')->format('Y-m-d');
}

/**
 * The report: each event in the window, oldest first, with the quiet stretches of an
 * hour or more between them as "No events".
 * Rows: ['from' => 'H:i', 'to' => 'H:i'|null, 'what' => string, 'quiet' => bool, 'event' => id|null, 'snapshot' => bool]
 */
/** A movement's line as shown: the laptop program's "Movement seen (not described: ...)" becomes just "Movement seen". */
function sc_event_text(string $what): string {
    return preg_match('/^Movement seen\b/i', $what) ? 'Movement seen' : $what;
}

function sc_report(PDO $conn, array $cam, string $day): array {
    [$start, $end] = sc_window($cam, $day);
    $st = $conn->prepare('SELECT id, started_at, ended_at, description, snapshot FROM store_camera_events
        WHERE camera_id = ? AND started_at >= ? AND started_at < ? ORDER BY started_at, id');
    $st->execute([(int)$cam['id'], $start, $end]);
    $events = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rows = [];
    $hm = function (string $dt): string { return substr($dt, 11, 5); };
    $quiet = function (string $from, string $to) use (&$rows, $hm) {
        if ((strtotime($to) - strtotime($from)) >= SC_QUIET_MINUTES * 60) {
            $rows[] = ['from' => $hm($from), 'to' => $hm($to), 'what' => 'No events', 'quiet' => true, 'event' => null, 'snapshot' => false];
        }
    };
    $cursor = $start;
    foreach ($events as $e) {
        $quiet($cursor, $e['started_at']);
        $sameMinute = $hm($e['started_at']) === $hm($e['ended_at']);
        $rows[] = [
            'from' => $hm($e['started_at']),
            'to' => $sameMinute ? null : $hm($e['ended_at']),
            'what' => sc_event_text((string)$e['description']),
            'quiet' => false,
            'event' => (int)$e['id'],
            'snapshot' => !empty($e['snapshot']),
        ];
        $cursor = max($cursor, (string)$e['ended_at']);
    }
    $quiet($cursor, min($end, date('Y-m-d H:i:s')));
    return ['start' => $start, 'end' => $end, 'rows' => $rows, 'events' => count($events)];
}

// ---------- item counts: "Compare" on the Live tab ----------
//
// Each side of a compare is a count of the camera's picture at a time (at_time; empty = now).
// The laptop program sends that picture (a past time is read back from the camera's
// recording); saifsys then asks Reem to count it, as the person who asked, and keeps the list.
// Statuses: waiting (for the picture) -> counting (Reem is counting) -> done / failed.

const SC_COUNT_WAIT_MINUTES = 5; // a count not finished by then is given up
const SC_COUNT_MAX_ITEMS = 40;
const SC_REEM_STORE_CAMERA = '/api/from-saifsys/store-camera';

/** Are the count tables there (the later parts of migrations/store_camera.sql run)? */
function sc_counts_ready(PDO $conn): bool {
    try {
        $conn->query('SELECT at_time, compare_id, side FROM store_camera_counts LIMIT 1');
        $conn->query('SELECT 1 FROM store_camera_compares LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Ask to compare the camera's picture at two times ('Y-m-d H:i:s', null = now). With both
 * empty it is one count of the picture now. A past time already counted is used again, so it
 * is not paid for twice. Returns the compare's id.
 */
function sc_request_compare(PDO $conn, int $cameraId, int $userId, ?string $fromAt, ?string $toAt): int {
    $conn->prepare('INSERT INTO store_camera_compares (camera_id, requested_by) VALUES (?, ?)')->execute([$cameraId, $userId ?: null]);
    $compareId = (int)$conn->lastInsertId(); // read before any other query, or it is lost
    $sides = ($fromAt === null && $toAt === null) ? ['to' => null] : ['from' => $fromAt, 'to' => $toAt];
    foreach ($sides as $side => $at) {
        if ($at !== null) {
            $st = $conn->prepare("SELECT * FROM store_camera_counts WHERE camera_id = ? AND at_time = ? AND status = 'done' ORDER BY id DESC LIMIT 1");
            $st->execute([$cameraId, $at]);
            $old = $st->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                $conn->prepare("INSERT INTO store_camera_counts (camera_id, status, requested_by, at_time, compare_id, side, counted_at, items, note, snapshot)
                    VALUES (?, 'done', ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$cameraId, $userId ?: null, $at, $compareId, $side, $old['counted_at'], $old['items'], $old['note'], $old['snapshot']]);
                continue;
            }
        }
        $conn->prepare('INSERT INTO store_camera_counts (camera_id, requested_by, at_time, compare_id, side) VALUES (?, ?, ?, ?, ?)')
            ->execute([$cameraId, $userId ?: null, $at, $compareId, $side]);
    }
    sc_want_live($conn, $cameraId); // the program keeps a fresh picture of a camera someone is looking at
    return $compareId;
}

/** The pictures the program should send now: [['id' => 5, 'camera_id' => 1, 'at' => '2026-11-24 08:00:00'|null], ...]. */
function sc_counts_wanted(PDO $conn): array {
    if (!sc_counts_ready($conn)) {
        return [];
    }
    if (sc_checks_ready($conn)) {
        sc_schedule_checks($conn); // the program asks every few seconds, so this is the clock for the checks
    }
    $conn->prepare("UPDATE store_camera_counts SET status = 'failed', counted_at = NOW(),
        note = CASE WHEN status = 'waiting' THEN 'The laptop program did not send the picture. Is store_camera.bat running?'
                    ELSE 'Reem did not finish the count. Try again.' END
        WHERE status IN ('waiting', 'counting') AND requested_at <= NOW() - INTERVAL ? MINUTE")->execute([SC_COUNT_WAIT_MINUTES]);
    $rows = $conn->query("SELECT c.id, c.camera_id, c.at_time, " . (sc_checks_ready($conn) ? 'c.is_check' : '0 AS is_check') . "
        FROM store_camera_counts c JOIN store_cameras k ON k.id = c.camera_id
        WHERE c.status = 'waiting' AND k.active = 1 ORDER BY c.id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(function ($r) {
        // A check is always the live picture (its at_time is only the slot it belongs to); "at" asks for a past time.
        $at = (!empty($r['is_check']) || $r['at_time'] === null) ? null : (string)$r['at_time'];
        return ['id' => (int)$r['id'], 'camera_id' => (int)$r['camera_id'], 'at' => $at];
    }, $rows);
}

function sc_count(PDO $conn, int $id): ?array {
    $st = $conn->prepare('SELECT * FROM store_camera_counts WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The newest compare of a camera: ['id', 'created_at', 'from' => count|null, 'to' => count|null], items decoded; or null. */
function sc_latest_compare(PDO $conn, int $cameraId): ?array {
    if (!sc_counts_ready($conn)) {
        return null;
    }
    $st = $conn->prepare('SELECT * FROM store_camera_compares WHERE camera_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$cameraId]);
    $cmp = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cmp) {
        return null;
    }
    $cmp['from'] = $cmp['to'] = null;
    $st = $conn->prepare('SELECT * FROM store_camera_counts WHERE compare_id = ? ORDER BY id');
    $st->execute([(int)$cmp['id']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
        $c['items'] = json_decode((string)$c['items'], true) ?: [];
        $cmp[$c['side'] === 'from' ? 'from' : 'to'] = $c;
    }
    return $cmp;
}

/** One row per kind of item over both counts: [['item', 'from' => n|null, 'to' => n|null, 'diff' => n|null]], names matched ignoring case. */
function sc_compare_rows(?array $from, ?array $to): array {
    $rows = [];
    $key = function (string $s): string { return mb_strtolower(trim(preg_replace('/\s+/', ' ', $s))); };
    foreach ([['from', $from], ['to', $to]] as [$side, $count]) {
        foreach (($count['items'] ?? []) as $it) {
            $k = $key((string)$it['item']);
            $rows[$k] = $rows[$k] ?? ['item' => (string)$it['item'], 'from' => null, 'to' => null];
            $rows[$k][$side] = (int)($rows[$k][$side] ?? 0) + (int)$it['count'];
        }
    }
    foreach ($rows as &$r) {
        // Both sides counted: a kind not seen on one side is 0 there.
        if ($from && $to && $from['status'] === 'done' && $to['status'] === 'done') {
            $r['from'] = (int)$r['from'];
            $r['to'] = (int)$r['to'];
        }
        $r['diff'] = ($r['from'] !== null && $r['to'] !== null) ? $r['to'] - $r['from'] : null;
    }
    unset($r);
    return array_values($rows);
}

/** Picture in, Reem counting it. */
function sc_mark_counting(PDO $conn, int $id, ?string $snapshot): void {
    $conn->prepare("UPDATE store_camera_counts SET status = 'counting', snapshot = ? WHERE id = ? AND status = 'waiting'")->execute([$snapshot, $id]);
}

/**
 * Ask Reem to count the stored items in a picture, as the person who asked (their HR employee
 * code). names: the kinds the other side of the compare used, so the two lists line up.
 * Returns ['items' => [...], 'note' => '']; throws BinvError when Reem refuses or cannot be reached.
 */
function sc_reem_count(PDO $conn, int $userId, string $jpegPath, array $names, ?string $refPath = null, array $refItems = [], bool $small = false): array {
    // names: kinds to reuse; with a reference, its list (names and counts) goes too, and its picture as a second file.
    $query = array_filter([
        'names' => $names ? json_encode(array_values($names), JSON_UNESCAPED_UNICODE) : null,
        'reference_items' => $refItems ? json_encode(array_values($refItems), JSON_UNESCAPED_UNICODE) : null,
        'model' => $small ? 'small' : null, // Reem's smallest model: for a cut-out round one pin
    ]);
    $files = ['photo' => $jpegPath];
    if ($refPath !== null && is_file($refPath)) {
        $files['reference'] = $refPath;
    }
    $r = sc_reem_post($conn, $userId, '/count', $files, $query);
    // Where each kind is in the picture: item name => [[x0, y0, x1, y1] in %].
    $boxes = [];
    foreach (($r['items'] ?? []) as $it) {
        $name = trim(preg_replace('/\s+/', ' ', (string)($it['item'] ?? '')));
        foreach ((is_array($it['boxes'] ?? null) ? $it['boxes'] : []) as $b) {
            if (is_array($b) && count($b) === 4) {
                $boxes[mb_substr($name, 0, 80)][] = array_map(function ($v) { return round(max(0, min(100, (float)$v)), 1); }, array_values($b));
            }
        }
    }
    return ['items' => sc_clean_items($r['items'] ?? []), 'note' => trim((string)($r['note'] ?? '')), 'boxes' => $boxes];
}

/**
 * Compare now: Reem says what changed between the reference picture and the picture now
 * ['summary' => '...', 'changes' => [['what', 'change' => missing|fewer|added|more|moved|other, 'where', 'detail']]].
 * Both go 1280 px wide, to keep it cheap. Billed to $userId.
 */
function sc_reem_compare(PDO $conn, int $userId, string $nowPath, string $refPath): array {
    $now = sc_small_jpeg($nowPath, 1280);
    $ref = sc_small_jpeg($refPath, 1280);
    try {
        if (!$now || !$ref) {
            throw new BinvError('The pictures could not be read.', 422);
        }
        $r = sc_reem_post($conn, $userId, '/compare', ['photo' => $now, 'reference' => $ref], []);
    } finally {
        foreach ([$now, $ref] as $f) {
            if ($f && $f !== $nowPath && $f !== $refPath) {
                @unlink($f);
            }
        }
    }
    $changes = [];
    foreach (($r['changes'] ?? []) as $c) {
        $what = trim((string)($c['what'] ?? ''));
        if ($what !== '') {
            $changes[] = ['what' => mb_substr($what, 0, 80), 'change' => mb_substr((string)($c['change'] ?? 'other'), 0, 10),
                'where' => mb_substr(trim((string)($c['where'] ?? '')), 0, 120), 'detail' => mb_substr(trim((string)($c['detail'] ?? '')), 0, 200)];
        }
    }
    return ['summary' => mb_substr(trim((string)($r['summary'] ?? '')), 0, 500), 'changes' => array_slice($changes, 0, 30)];
}

// ---------- "Generate report now": Reem writes the day's report ----------

/** Where Reem's report for a camera and day is kept (a JSON file beside the pictures). */
function sc_reem_report_file(int $cameraId, string $day): string {
    return sc_abs('uploads/store_camera_events/reports/' . $cameraId . '/' . $day . '.json');
}

/** Reem's report for a camera and day, as kept: ['summary', 'rows' => [['time', 'what']], 'made_at', 'made_by'] or null. */
function sc_reem_report_get(int $cameraId, string $day): ?array {
    $f = sc_reem_report_file($cameraId, $day);
    $r = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($r) && isset($r['summary']) ? $r : null;
}

/** The day's log in words, for Reem: the movements, the bell (movement, store changed, program stopped) and the checks. */
function sc_reem_report_log(PDO $conn, array $cam, string $day): array {
    $rep = sc_report($conn, $cam, $day);
    $lines = ['Camera: ' . $cam['name'], 'Report hours: ' . $rep['start'] . ' to ' . $rep['end'] . ' (Dubai time)', '',
        'Movements and events (' . $rep['events'] . '):'];
    foreach ($rep['rows'] as $r) {
        $what = stripos($r['what'], 'not described') !== false ? 'Movement seen' : $r['what'];
        $lines[] = '- ' . $r['from'] . ($r['to'] ? '-' . $r['to'] : '') . ' ' . $what;
    }
    if (!$rep['rows']) {
        $lines[] = '- none recorded';
    }
    $refStock = sc_ref_stock_get($cam);
    if ($refStock && $refStock['items']) {
        $lines[] = '';
        $lines[] = 'Initial stock, counted from the reference picture (use these names in the stock count): '
            . implode('; ', array_map(function ($i) { return $i['item'] . ' ' . $i['count'] . (!empty($i['manual']) ? ' (added by hand)' : ''); }, $refStock['items']));
        if (array_filter($refStock['items'], function ($i) { return !empty($i['manual']); })) {
            $lines[] = 'Items "added by hand" were counted by a person and may not be visible in the pictures: keep their count, '
                . 'and change it only when a picture clearly shows one taken or brought in.';
        }
    }
    $lines[] = '';
    $lines[] = 'Bell alerts (movement / store changed / laptop program stopped):';
    $n = 0;
    try {
        $st = $conn->prepare('SELECT kind, title, detail, created_at FROM store_camera_alerts WHERE camera_id = ? AND created_at >= ? AND created_at < ? ORDER BY created_at, id');
        $st->execute([(int)$cam['id'], $rep['start'], $rep['end']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $lines[] = '- ' . substr((string)$a['created_at'], 11, 5) . ' [' . $a['kind'] . '] ' . $a['title'] . ($a['detail'] ? ': ' . sc_event_text((string)$a['detail']) : '');
            $n++;
        }
    } catch (PDOException $e) {
        // no alerts table yet
    }
    if (!$n) {
        $lines[] = '- none';
    }
    $lines[] = '';
    $lines[] = 'Checks against the reference picture (a pixel comparison: it shows where the picture changed and how much, not what):';
    $last = null;
    $lastPic = null;
    $checks = sc_day_checks($conn, (int)$cam['id'], $rep['start'], $rep['end']);
    foreach ($checks as $dc) {
        $t = date('H:i', strtotime((string)$dc['at_time']));
        $d = !empty($dc['diff']) ? (json_decode((string)$dc['diff'], true) ?: null) : null;
        if ($dc['status'] === 'done' && $d) {
            $areas = array_map('sc_area_where', array_slice($d['boxes'] ?? [], 0, 5));
            $lines[] = '- ' . $t . ' about ' . $d['pct'] . '% of the picture changed' . ($areas && $d['pct'] >= 1 ? ' (biggest areas: ' . implode(', ', $areas) . ')' : '');
        } elseif ($dc['status'] === 'skipped') {
            $lines[] = '- ' . $t . ' not checked: nothing moved';
        } elseif ($dc['status'] === 'failed') {
            $lines[] = '- ' . $t . ' failed: ' . $dc['note'];
        }
        if ($dc['status'] === 'done' && !empty($dc['snapshot']) && is_file(sc_abs((string)$dc['snapshot']))) {
            $last = sc_abs((string)$dc['snapshot']);
            $lastPic = ['src' => 'count=' . (int)$dc['id'], 'at' => substr((string)$dc['at_time'], 11, 5)];
        }
    }
    if (!$checks) {
        $lines[] = '- none';
    }
    // No check picture that day: the last movement snapshot.
    if (!$last) {
        $st = $conn->prepare("SELECT id, started_at, snapshot FROM store_camera_events WHERE camera_id = ? AND started_at >= ? AND started_at < ?
            AND snapshot IS NOT NULL AND snapshot <> '' ORDER BY started_at DESC, id DESC LIMIT 1");
        $st->execute([(int)$cam['id'], $rep['start'], $rep['end']]);
        $e = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $s = (string)($e['snapshot'] ?? '');
        $last = $s !== '' && is_file(sc_abs($s)) ? sc_abs($s) : null;
        $lastPic = $last ? ['src' => 'id=' . (int)$e['id'], 'at' => substr((string)$e['started_at'], 11, 5)] : null;
    }
    $refPhoto = !empty($cam['reference_photo_id']) ? sc_photo($conn, (int)$cam['reference_photo_id']) : null;
    $ref = $refPhoto && is_file(sc_abs((string)$refPhoto['path'])) ? sc_abs((string)$refPhoto['path']) : null;
    // The first picture of the day (the first check or movement picture), for "Start of day".
    $first = null;
    $st = $conn->prepare("SELECT at, snapshot, src FROM (
            SELECT at_time AS at, snapshot, CONCAT('count=', id) AS src FROM store_camera_counts WHERE camera_id = ? AND is_check = 1 AND status = 'done'
                AND at_time >= ? AND at_time < ? AND snapshot IS NOT NULL AND snapshot <> ''
            UNION ALL
            SELECT started_at AS at, snapshot, CONCAT('id=', id) AS src FROM store_camera_events WHERE camera_id = ? AND started_at >= ? AND started_at < ?
                AND snapshot IS NOT NULL AND snapshot <> '') p ORDER BY at LIMIT 5");
    $st->execute([(int)$cam['id'], $rep['start'], $rep['end'], (int)$cam['id'], $rep['start'], $rep['end']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
        if (is_file(sc_abs((string)$p['snapshot']))) {
            $first = ['path' => sc_abs((string)$p['snapshot']), 'label' => 'First picture of the day (' . substr((string)$p['at'], 11, 5) . ')',
                'src' => (string)$p['src'], 'at' => substr((string)$p['at'], 11, 5)];
            break;
        }
    }
    if ($first && $first['path'] === $last) {
        $first = null; // only one picture that day: it is the last picture
    }
    $lines[] = '';
    // Each movement's snapshot (at most SC_REPORT_MOVES, spread over the day), so Reem can say what happened.
    $st = $conn->prepare("SELECT id, started_at, ended_at, snapshot FROM store_camera_events WHERE camera_id = ? AND started_at >= ? AND started_at < ?
        AND snapshot IS NOT NULL AND snapshot <> '' ORDER BY started_at, id");
    $st->execute([(int)$cam['id'], $rep['start'], $rep['end']]);
    $moves = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC) ?: [], function ($e) { return is_file(sc_abs((string)$e['snapshot'])); }));
    if (count($moves) > SC_REPORT_MOVES) {
        $step = count($moves) / SC_REPORT_MOVES;
        $moves = array_map(function ($i) use ($moves, $step) { return $moves[(int)floor($i * $step)]; }, range(0, SC_REPORT_MOVES - 1));
    }
    $movePics = [];
    foreach ($moves as $e) {
        $t = substr((string)$e['started_at'], 11, 5);
        $t2 = substr((string)$e['ended_at'], 11, 5);
        $movePics[] = ['label' => 'Movement at ' . $t . ($t2 && $t2 !== $t ? '-' . $t2 : ''), 'path' => sc_abs((string)$e['snapshot']), 'event' => (int)$e['id']];
    }
    $lines[] = '';
    $lines[] = 'Pictures (each is labelled): ' . ($ref ? 'the reference picture; ' : '') . ($first ? 'the first picture of the day; ' : '') . ($last ? 'the last picture of the day; ' : '')
        . ($movePics ? count($movePics) . ' movement snapshot' . (count($movePics) === 1 ? '' : 's') . ', taken when the movement was seen.' : 'no movement snapshots.');
    // The start and end pictures shown in the report (links to store_camera_file.php), with the changed items boxed.
    $pics = [
        'start' => $first ? ['src' => $first['src'], 'at' => $first['at']] : ($refPhoto ? ['src' => 'photo=' . (int)$refPhoto['id'], 'at' => 'reference'] : null),
        'end' => $lastPic ?? null,
    ];
    return ['text' => mb_substr(implode("\n", $lines), 0, 20000), 'reference' => $ref, 'photo' => $last, 'first' => $first, 'moves' => $movePics, 'pics' => $pics,
        'movements' => (int)$rep['events']];
}

/**
 * What a movement's snapshot shows, in two or three short sentences, from Reem (compared with the reference picture).
 * Billed to $userId. The laptop program waits for it, so Reem gets 40 seconds.
 */
function sc_reem_describe(PDO $conn, int $userId, string $snapPath, ?string $refPath): array {
    $files = [];
    try {
        foreach (['photo' => $snapPath, 'reference' => $refPath] as $k => $p) {
            if ($p && ($small = sc_small_jpeg($p, 1024))) {
                $files[$k] = $small;
            }
        }
        if (!isset($files['photo'])) {
            throw new BinvError('The snapshot could not be read.', 422);
        }
        $r = sc_reem_post($conn, $userId, '/describe', $files, [], [], 40);
    } finally {
        foreach ($files as $f) {
            if ($f !== $snapPath && $f !== $refPath) {
                @unlink($f);
            }
        }
    }
    $what = mb_substr(trim((string)($r['what'] ?? '')), 0, 500);
    return ['what' => $what, 'short' => mb_substr(trim((string)($r['short'] ?? '')), 0, 100) ?: sc_short_text($what)];
}

/** A line for the bell: the first sentence, at most 90 letters. */
function sc_short_text(string $what): string {
    $what = trim($what);
    if (preg_match('/^(.+?[.!?])(\s|$)/u', $what, $m)) {
        $what = $m[1];
    }
    return mb_strlen($what) > 90 ? rtrim(mb_substr($what, 0, 88)) . '…' : $what;
}

/**
 * The daily reports that are due: today's (and yesterday's, if it was missed) once the report hours are over.
 * Run after the laptop program's camera list is answered (every 5 minutes). A try is not repeated within 30 minutes.
 */
function sc_reem_reports_due(PDO $conn): void {
    foreach (sc_cameras($conn, true) as $cam) {
        sc_prune_check_pictures($conn, $cam); // old pictures go, even with checks off
        foreach ([date('Y-m-d', strtotime('-1 day')), date('Y-m-d')] as $day) {
            [, $end] = sc_window($cam, $day);
            $f = sc_reem_report_file((int)$cam['id'], $day);
            $trying = $f . '.trying';
            if (strtotime($end) > time() || is_file($f) || (is_file($trying) && filemtime($trying) > time() - 1800)) {
                continue;
            }
            if (!is_dir(dirname($f))) {
                sc_upload_base();
                @mkdir(dirname($f), 0775, true);
            }
            @touch($trying);
            try {
                sc_reem_report_make($conn, $cam, $day, (int)$cam['created_by']);
                @unlink($trying);
            } catch (Throwable $e) {
                error_log('Store camera report ' . $cam['id'] . ' ' . $day . ': ' . $e->getMessage());
            }
        }
    }
}

// ---------- the initial stock: Reem counts the reference picture ----------

/** Where the initial stock counted from a reference picture is kept (a JSON file, one per picture). */
function sc_ref_stock_file(int $cameraId, int $photoId): string {
    return sc_abs('uploads/store_camera_events/reports/' . $cameraId . '/reference_' . $photoId . '.json');
}

/** The initial stock of the camera's reference picture: ['items' => [['item', 'count']], 'note', 'made_at'] or null. */
function sc_ref_stock_get(array $cam): ?array {
    if (empty($cam['reference_photo_id'])) {
        return null;
    }
    $f = sc_ref_stock_file((int)$cam['id'], (int)$cam['reference_photo_id']);
    $r = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($r) && isset($r['items']) ? $r : null;
}

/** Reem counts the stock in the reference picture; kept with that picture. Billed to $userId. */
function sc_ref_stock_make(PDO $conn, array $cam, int $userId): array {
    $p = !empty($cam['reference_photo_id']) ? sc_photo($conn, (int)$cam['reference_photo_id']) : null;
    if (!$p || !is_file(sc_abs((string)$p['path']))) {
        throw new StoreCamError('Set the reference picture first.');
    }
    @set_time_limit(200);
    $got = sc_reem_count($conn, $userId, sc_abs((string)$p['path']), []);
    $items = array_map(function ($i) use ($got) { return $i + ['boxes' => $got['boxes'][$i['item']] ?? []]; }, $got['items']);
    // Items added or corrected by hand stay (the camera may not see them); items removed by hand stay out.
    $old = sc_ref_stock_get($cam);
    $removed = array_map('mb_strtolower', $old['removed'] ?? []);
    $items = array_values(array_filter($items, function ($x) use ($removed) { return !in_array(mb_strtolower($x['item']), $removed, true); }));
    foreach (($old['items'] ?? []) as $i) {
        if (!empty($i['manual']) && !empty($i['was'])) {
            // Corrected by hand: Reem's row under its old name goes too.
            $items = array_values(array_filter($items, function ($x) use ($i) { return mb_strtolower($x['item']) !== mb_strtolower($i['was']); }));
        }
        if (!empty($i['manual'])) {
            $items = array_values(array_filter($items, function ($x) use ($i) { return mb_strtolower($x['item']) !== mb_strtolower($i['item']); }));
            $items[] = $i;
        }
    }
    $out = ['items' => $items, 'note' => $got['note'], 'made_at' => date('Y-m-d H:i:s'), 'photo' => (int)$p['id'], 'removed' => $old['removed'] ?? []];
    sc_ref_stock_save($cam, $out);
    return $out;
}

/** Keep the initial stock of the camera's reference picture. */
function sc_ref_stock_save(array $cam, array $data): void {
    $f = sc_ref_stock_file((int)$cam['id'], (int)$cam['reference_photo_id']);
    sc_upload_base();
    if (!is_dir(dirname($f))) {
        @mkdir(dirname($f), 0775, true);
    }
    file_put_contents($f, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/**
 * Add an item to the initial stock by hand (or set the count of one already there, by the same name), or remove a
 * hand-added one (count null). Items added by hand are kept when Reem counts again.
 */
function sc_ref_stock_set_item(array $cam, string $name, ?int $count, ?string $old = null): void {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '') {
        throw new StoreCamError('Type the item.');
    }
    if (empty($cam['reference_photo_id'])) {
        throw new StoreCamError('Set the reference picture first.');
    }
    $data = sc_ref_stock_get($cam) ?? ['items' => [], 'note' => '', 'made_at' => date('Y-m-d H:i:s'), 'photo' => (int)$cam['reference_photo_id']];
    $name = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    $key = mb_strtolower($old !== null && trim($old) !== '' ? trim($old) : $name);
    if ($old !== null && mb_strtolower($name) !== $key) {
        foreach ($data['items'] as $i) {
            if (mb_strtolower($i['item']) === mb_strtolower($name)) {
                throw new StoreCamError($name . ' is already in the list: change that row instead.');
            }
        }
    }
    $items = [];
    $found = false;
    foreach ($data['items'] as $i) {
        if (mb_strtolower($i['item']) === $key) {
            $found = true;
            if ($count === null) {
                if (empty($i['manual']) || !empty($i['was'])) {
                    $data['removed'][] = $i['was'] ?? $i['item']; // a row Reem counted: kept out when Reem counts again
                }
                continue;
            }
            // Reem's row corrected by hand keeps where it is on the picture and remembers Reem's name.
            $was = $i['was'] ?? (empty($i['manual']) ? $i['item'] : null);
            $i = ['item' => mb_substr($name, 0, 80), 'count' => $count, 'boxes' => $i['boxes'] ?? [], 'manual' => true] + ($was !== null ? ['was' => $was] : []);
        }
        $items[] = $i;
    }
    if (!$found && $count !== null) {
        $items[] = ['item' => mb_substr(mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1), 0, 80), 'count' => $count, 'boxes' => [], 'manual' => true];
    }
    $data['items'] = array_slice($items, 0, 50);
    sc_ref_stock_save($cam, $data);
}

/** At most this many movement snapshots go with a report (spread over the day). */
const SC_REPORT_MOVES = 12;

/** Reem writes the report of a day from its log and pictures: ['summary', 'rows' => [['time', 'what']]]. Kept, and billed to $userId. */
function sc_reem_report_make(PDO $conn, array $cam, string $day, int $userId): array {
    $log = sc_reem_report_log($conn, $cam, $day);
    @set_time_limit(200);
    $files = [];
    try {
        foreach (['reference' => $log['reference'], 'photo' => $log['photo']] as $k => $path) {
            if ($path && ($small = sc_small_jpeg($path, 1280))) {
                $files[$k] = $small;
            }
        }
        $labels = $events = [];
        if ($log['first'] && ($small = sc_small_jpeg($log['first']['path'], 1280))) {
            $files['first'] = $small;
            $labels['first'] = $log['first']['label'];
        }
        foreach ($log['moves'] as $i => $m) {
            if ($small = sc_small_jpeg($m['path'], 1024)) {
                $files['m' . ($i + 1)] = $small;
                $labels['m' . ($i + 1)] = $m['label'];
                $events['m' . ($i + 1)] = $m['event'];
            }
        }
        $r = sc_reem_post($conn, $userId, '/report', $files, [], ['log' => $log['text'], 'labels' => json_encode($labels)]);
    } finally {
        $keep = array_merge([$log['reference'], $log['photo'], $log['first']['path'] ?? null], array_column($log['moves'], 'path'));
        foreach ($files as $f) {
            if (!in_array($f, $keep, true)) {
                @unlink($f);
            }
        }
    }
    $rows = [];
    foreach (($r['rows'] ?? []) as $row) {
        $what = trim((string)($row['what'] ?? ''));
        if ($what !== '') {
            // The movement snapshot the row is about, shown beside it.
            $rows[] = ['time' => mb_substr(trim((string)($row['time'] ?? '')), 0, 20), 'what' => mb_substr($what, 0, 600),
                'stock' => mb_substr(trim((string)($row['stock'] ?? '')), 0, 200),
                'event' => $events[(string)($row['picture'] ?? '')] ?? null];
        }
    }
    $out = ['summary' => mb_substr(trim((string)($r['summary'] ?? '')), 0, 1000), 'rows' => array_slice($rows, 0, 40),
        'movements' => $log['movements'], 'stock_handled' => max(0, (int)($r['stock_handled'] ?? 0)),
        'pics' => $log['pics'],
        'stock' => array_slice(array_values(array_filter(array_map(function ($s) {
            $bx = function ($list) {
                return array_values(array_filter(array_map(function ($b) {
                    return is_array($b) && count($b) === 4 ? array_map(function ($v) { return round(max(0, min(100, (float)$v)), 1); }, array_values($b)) : null;
                }, is_array($list) ? array_slice($list, 0, 30) : [])));
            };
            return ['item' => mb_substr(trim((string)($s['item'] ?? '')), 0, 80), 'start' => max(0, (int)($s['start'] ?? 0)), 'end' => max(0, (int)($s['end'] ?? 0)),
                'start_boxes' => $bx($s['start_boxes'] ?? []), 'end_boxes' => $bx($s['end_boxes'] ?? [])];
        }, is_array($r['stock'] ?? null) ? $r['stock'] : []), function ($s) { return $s['item'] !== ''; })), 0, 25),
        'closing' => mb_substr(trim((string)($r['closing'] ?? '')), 0, 120),
        'made_at' => date('Y-m-d H:i:s'), 'made_by' => $userId];
    [, $end] = sc_window($cam, $day);
    $over = strtotime($end) <= time();
    if ($over) {
        $out['pictures_deleted'] = true;
    }
    sc_upload_base();
    $f = sc_reem_report_file((int)$cam['id'], $day);
    if (!is_dir(dirname($f))) {
        @mkdir(dirname($f), 0775, true);
    }
    if (file_put_contents($f, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) && $over) {
        sc_drop_pictures_before($conn, (int)$cam['id'], $end);
    }
    return $out;
}

/**
 * Only the reports are kept: once a finished day's report is saved, the camera's movement and check pictures up to
 * the end of its hours are deleted (the rows stay, without a picture). The reference picture and the live picture stay.
 */
function sc_drop_pictures_before(PDO $conn, int $cameraId, string $end): void {
    foreach ([['store_camera_events', 'started_at'], ['store_camera_counts', 'requested_at']] as [$table, $col]) {
        $st = $conn->prepare("SELECT id, snapshot FROM $table WHERE camera_id = ? AND $col < ? AND snapshot IS NOT NULL AND snapshot <> ''");
        $st->execute([$cameraId, $end]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            // A picture another row still points at after this (a compare re-used it) is left for that row.
            @unlink(sc_abs((string)$r['snapshot']));
            $conn->prepare("UPDATE $table SET snapshot = NULL WHERE id = ?")->execute([(int)$r['id']]);
        }
    }
}

/** Kept pictures: at most this wide, at this JPEG quality (about 105 KB a picture instead of about 230 KB). */
const SC_KEEP_WIDTH = 1280;
const SC_KEEP_QUALITY = 70;

/** Make a kept picture smaller, in place: at most SC_KEEP_WIDTH wide, at SC_KEEP_QUALITY. Left as it is if that fails or saves nothing. */
function sc_shrink_jpeg(string $path): void {
    $size = is_file($path) ? @getimagesize($path) : false;
    $src = $size ? @imagecreatefromjpeg($path) : false;
    if (!$src) {
        return;
    }
    $w = min(SC_KEEP_WIDTH, $size[0]);
    $h = (int)round($size[1] * $w / $size[0]);
    $out = $src;
    if ($w < $size[0]) {
        $out = imagecreatetruecolor($w, $h);
        imagecopyresampled($out, $src, 0, 0, 0, 0, $w, $h, $size[0], $size[1]);
        imagedestroy($src);
    }
    $tmp = $path . '.small';
    $ok = imagejpeg($out, $tmp, SC_KEEP_QUALITY);
    imagedestroy($out);
    if ($ok && filesize($tmp) > 0 && filesize($tmp) < filesize($path)) {
        @rename($tmp, $path) || (@copy($tmp, $path) && @unlink($tmp));
    }
    @unlink($tmp);
}

/** A picture made at most $width px wide as a temporary JPEG (the caller deletes it); the picture itself when it is already small. */
function sc_small_jpeg(string $path, int $width): ?string {
    $size = is_file($path) ? @getimagesize($path) : false;
    if (!$size) {
        return null;
    }
    if ($size[0] <= $width) {
        return $path;
    }
    $src = @imagecreatefromjpeg($path);
    if (!$src) {
        return null;
    }
    $h = (int)round($size[1] * $width / $size[0]);
    $out = imagecreatetruecolor($width, $h);
    imagecopyresampled($out, $src, 0, 0, 0, 0, $width, $h, $size[0], $size[1]);
    imagedestroy($src);
    $tmp = sys_get_temp_dir() . '/scsmall_' . bin2hex(random_bytes(6)) . '.jpg';
    $ok = imagejpeg($out, $tmp, 85);
    imagedestroy($out);
    return $ok ? $tmp : null;
}

/**
 * One call to Reem's store camera door, as the person it is for (their HR employee code): files are field => JPEG path.
 * STORE_CAMERA_REEM_URL / _KEY in config.php send it to another Reem (e.g. one on this PC to try it); without them,
 * the Reem saifsys always talks to. It writes nothing in Reem, so it goes even from a read-only copy (see binv_reem).
 */
function sc_reem_post(PDO $conn, int $userId, string $route, array $files, array $query, array $text = [], int $timeout = 150): array {
    require_once dirname(__DIR__, 2) . '/building_inventory/includes/binv_helper.php';
    $reemUrl = defined('STORE_CAMERA_REEM_URL') && STORE_CAMERA_REEM_URL !== '' ? STORE_CAMERA_REEM_URL : (defined('REEM_URL') ? REEM_URL : '');
    $reemKey = defined('STORE_CAMERA_REEM_KEY') && STORE_CAMERA_REEM_KEY !== '' ? STORE_CAMERA_REEM_KEY : (defined('REEM_DOOR_KEY') ? REEM_DOOR_KEY : '');
    if ($reemUrl === '' || $reemKey === '') {
        throw new BinvError('saifsys is not connected to Reem: REEM_URL and REEM_DOOR_KEY are missing from config.php.', 503);
    }
    $code = binv_employee_code($conn, $userId);
    if ($code === '') {
        throw new BinvError('The person this is for has no employee record, so Reem cannot tell who it is for.', 403);
    }
    $fields = $text;
    foreach ($files as $name => $path) {
        $fields[$name] = new CURLFile($path, 'image/jpeg', $name);
    }
    $ch = curl_init(rtrim($reemUrl, '/') . SC_REEM_STORE_CAMERA . $route . ($query ? '?' . http_build_query($query) : ''));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['X-Saifsys-Key: ' . $reemKey, 'X-Saifsys-Employee: ' . $code, 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false) {
        throw new BinvError('Reem did not answer. Try again in a moment.', 502);
    }
    $r = json_decode((string)$body, true);
    if ($status < 200 || $status >= 300 || !is_array($r)) {
        $msg = is_array($r) && is_string($r['error'] ?? null) && $r['error'] !== '' ? $r['error'] : 'Reem answered with an error (HTTP ' . $status . ').';
        // A Reem without the store camera route answers 404, or passes it on to its own login check
        // ("Please sign in"): say that the update is missing, not that someone must sign in.
        if ($status === 404 || ($status === 401 && stripos($msg, 'sign in') !== false)) {
            throw new BinvError('This Reem cannot do this yet: its Store Camera update is not deployed.', 404);
        }
        throw new BinvError($msg, $status ?: 502);
    }
    return $r;
}

/**
 * Clean a list the program sent: [{"item": "...", "count": n}, ...] -> the same, trimmed,
 * counts 0-100000, at most SC_COUNT_MAX_ITEMS. Throws when it is not such a list.
 */
function sc_clean_items($raw): array {
    $list = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($list)) {
        throw new StoreCamError('items must be a JSON list of {"item": "...", "count": n}.');
    }
    $out = [];
    foreach ($list as $it) {
        $name = trim(preg_replace('/\s+/', ' ', (string)($it['item'] ?? '')));
        $n = $it['count'] ?? null;
        if ($name === '' || !is_numeric($n)) {
            continue;
        }
        $out[] = ['item' => mb_substr($name, 0, 80), 'count' => max(0, min(100000, (int)$n))];
        if (count($out) >= SC_COUNT_MAX_ITEMS) {
            break;
        }
    }
    return $out;
}

/** Keep what the program counted (or why it could not), on a count still waiting. */
function sc_save_count(PDO $conn, int $id, bool $ok, array $items, string $note, ?string $snapshot): bool {
    $st = $conn->prepare("UPDATE store_camera_counts SET status = ?, counted_at = NOW(), items = ?, note = ?, snapshot = ?
        WHERE id = ? AND status IN ('waiting', 'counting')");
    $st->execute([$ok ? 'done' : 'failed', $ok ? json_encode($items, JSON_UNESCAPED_UNICODE) : null,
        $note !== '' ? mb_substr($note, 0, 500) : null, $snapshot, $id]);
    return $st->rowCount() > 0;
}

// ---------- the reference picture: the live picture kept when someone presses the button ----------

/** Is the reference-picture part of migrations/store_camera.sql run? */
function sc_photos_ready(PDO $conn): bool {
    try {
        $conn->query('SELECT reference_photo_id FROM store_cameras LIMIT 1');
        $conn->query('SELECT 1 FROM store_camera_photos LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function sc_photo(PDO $conn, int $id): ?array {
    $st = $conn->prepare('SELECT * FROM store_camera_photos WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Keep the live picture on screen now as a past picture and make it the reference picture.
 * Returns its id. Needs a live picture of SC_LIVE_STALE_SECONDS or newer (the Live tab must be open).
 */
function sc_keep_live_as_reference(PDO $conn, int $cameraId): int {
    $live = sc_live_path($cameraId);
    clearstatcache(true, $live);
    if (!is_file($live) || time() - (int)filemtime($live) > SC_LIVE_STALE_SECONDS) {
        throw new StoreCamError('There is no fresh live picture. Is the laptop program running?');
    }
    return sc_make_reference($conn, $cameraId, $live, date('Y-m-d H:i:s', (int)filemtime($live)));
}

/**
 * A picture saifsys already keeps, from the Report tab, becomes the reference: a check's picture
 * ($kind 'check') or a movement snapshot ($kind 'event'), so a past time can be the starting picture.
 */
function sc_reference_from_past(PDO $conn, int $cameraId, string $kind, int $id): int {
    $sql = $kind === 'check'
        ? 'SELECT snapshot, COALESCE(at_time, requested_at) AS at FROM store_camera_counts WHERE id = ? AND camera_id = ?'
        : 'SELECT snapshot, started_at AS at FROM store_camera_events WHERE id = ? AND camera_id = ?';
    $st = $conn->prepare($sql);
    $st->execute([$id, $cameraId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['snapshot']) || !is_file(sc_abs((string)$row['snapshot']))) {
        throw new StoreCamError('That picture is not there any more (check pictures are deleted after the days set on the Cameras tab).');
    }
    return sc_make_reference($conn, $cameraId, sc_abs((string)$row['snapshot']), (string)$row['at']);
}

const SC_UPLOAD_MAX_BYTES = 8 * 1024 * 1024;

/**
 * A picture chosen on the computer becomes the reference (e.g. one saved from the EZVIZ app). JPEG, PNG
 * or WebP up to 8 MB; kept as JPEG. $takenAt: when it was taken ('Y-m-d H:i:s'), or null for now.
 * Returns [photo id, warning or ''] - the warning when it is not shaped like the camera's picture.
 */
function sc_reference_from_upload(PDO $conn, int $cameraId, ?array $file, ?string $takenAt): array {
    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new StoreCamError('Choose the picture first.');
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        throw new StoreCamError(in_array((int)$file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'The picture is bigger than the server takes.' : 'The picture did not upload. Please try again.');
    }
    if ((int)$file['size'] > SC_UPLOAD_MAX_BYTES) {
        throw new StoreCamError('The picture must be 8 MB or smaller.');
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new StoreCamError('The picture must be a JPEG, PNG or WebP.');
    }
    $im = @imagecreatefromstring((string)file_get_contents($file['tmp_name']));
    if (!$im) {
        throw new StoreCamError('The picture could not be read.');
    }
    // The comparison lines the reference up with the camera's picture, so it should be the camera's own view.
    $warn = '';
    $live = sc_live_path($cameraId);
    $liveSize = is_file($live) ? @getimagesize($live) : false;
    $ratio = imagesx($im) / max(1, imagesy($im));
    $want = $liveSize ? $liveSize[0] / max(1, $liveSize[1]) : 16 / 9;
    if (abs($ratio - $want) > 0.05) {
        $warn = 'This picture is not shaped like the camera\'s picture, so it may not line up: use a picture of this camera\'s own view.';
    }
    $tmp = sys_get_temp_dir() . '/scup_' . bin2hex(random_bytes(6)) . '.jpg';
    $made = imagejpeg($im, $tmp, 90);
    imagedestroy($im);
    if (!$made) {
        throw new StoreCamError('The picture could not be kept.');
    }
    try {
        return [sc_make_reference($conn, $cameraId, $tmp, $takenAt ?? date('Y-m-d H:i:s')), $warn];
    } finally {
        @unlink($tmp);
    }
}

/**
 * The camera's pictures saifsys keeps (check pictures and movement snapshots), as one list:
 * the one nearest a time ($near), or the one just before / after a picture ($step -1 / +1 from $from).
 * Each: ['kind' => 'check'|'event', 'id', 'at' => 'Y-m-d H:i:s', 'src' => URL query part]. Null when there is none.
 */
function sc_kept_picture(PDO $conn, int $cameraId, ?string $near, ?array $from = null, int $step = 0): ?array {
    $all = "SELECT 'check' AS kind, id, COALESCE(at_time, requested_at) AS at FROM store_camera_counts
              WHERE camera_id = ? AND is_check = 1 AND snapshot IS NOT NULL
            UNION ALL
            SELECT 'event' AS kind, id, started_at AS at FROM store_camera_events WHERE camera_id = ? AND snapshot IS NOT NULL";
    if ($from !== null && $step !== 0) {
        $st = $conn->prepare("SELECT * FROM ($all) p WHERE (p.at, p.kind, p.id) " . ($step < 0 ? '<' : '>') . " (?, ?, ?)
            ORDER BY p.at " . ($step < 0 ? 'DESC' : 'ASC') . ", p.kind " . ($step < 0 ? 'DESC' : 'ASC') . ", p.id " . ($step < 0 ? 'DESC' : 'ASC') . ' LIMIT 1');
        $st->execute([$cameraId, $cameraId, $from['at'], $from['kind'], $from['id']]);
    } else {
        $st = $conn->prepare("SELECT * FROM ($all) p ORDER BY ABS(TIMESTAMPDIFF(SECOND, p.at, ?)), p.kind LIMIT 1");
        $st->execute([$cameraId, $cameraId, $near ?? date('Y-m-d H:i:s')]);
    }
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return null;
    }
    return ['kind' => $r['kind'], 'id' => (int)$r['id'], 'at' => (string)$r['at'], 'src' => ($r['kind'] === 'check' ? 'count=' : 'id=') . (int)$r['id']];
}

/** One kept picture by kind and id (for the arrows), or null. */
function sc_kept_picture_get(PDO $conn, int $cameraId, string $kind, int $id): ?array {
    $st = $conn->prepare($kind === 'check'
        ? 'SELECT id, COALESCE(at_time, requested_at) AS at FROM store_camera_counts WHERE id = ? AND camera_id = ? AND is_check = 1 AND snapshot IS NOT NULL'
        : 'SELECT id, started_at AS at FROM store_camera_events WHERE id = ? AND camera_id = ? AND snapshot IS NOT NULL');
    $st->execute([$id, $cameraId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? ['kind' => $kind, 'id' => (int)$r['id'], 'at' => (string)$r['at'], 'src' => ($kind === 'check' ? 'count=' : 'id=') . (int)$r['id']] : null;
}

/** Copy a picture in as the camera's reference ($takenAt: when it was taken); the reference it replaces goes, file and row. */
function sc_make_reference(PDO $conn, int $cameraId, string $srcPath, string $takenAt): int {
    sc_upload_base();
    $relDir = 'uploads/store_camera_events/photos/' . $cameraId . '/' . substr($takenAt, 0, 10);
    if (!is_dir(sc_abs($relDir)) && !@mkdir(sc_abs($relDir), 0775, true) && !is_dir(sc_abs($relDir))) {
        throw new StoreCamError('The server could not make the picture folder.');
    }
    $rel = $relDir . '/' . str_replace(':', '', substr($takenAt, 11, 8)) . '_ref_' . bin2hex(random_bytes(3)) . '.jpg';
    if (!@copy($srcPath, sc_abs($rel))) {
        throw new StoreCamError('The server could not keep the picture.');
    }
    $conn->prepare('INSERT INTO store_camera_photos (camera_id, taken_at, path) VALUES (?, ?, ?)')->execute([$cameraId, $takenAt, $rel]);
    $id = (int)$conn->lastInsertId();
    $old = sc_photo($conn, (int)(sc_camera($conn, $cameraId)['reference_photo_id'] ?? 0));
    sc_set_reference($conn, $cameraId, $id);
    // Only one reference picture is kept: the one it replaces goes, file and row.
    if ($old && (int)$old['id'] !== $id) {
        @unlink(sc_abs((string)$old['path']));
        $conn->prepare('DELETE FROM store_camera_photos WHERE id = ?')->execute([(int)$old['id']]);
    }
    return $id;
}

/** Make a kept picture the camera's reference picture (the starting picture for the stock count). */
function sc_set_reference(PDO $conn, int $cameraId, int $photoId): void {
    $p = sc_photo($conn, $photoId);
    if (!$p || (int)$p['camera_id'] !== $cameraId) {
        throw new StoreCamError('That picture is not there any more.');
    }
    $conn->prepare('UPDATE store_cameras SET reference_photo_id = ? WHERE id = ?')->execute([$photoId, $cameraId]);
}

// ---------- checks: a count every N hours against the reference, and the bell ----------
//
// The schedule runs each time the laptop program asks for work (every few seconds), so there is
// no cron. A check is a row in store_camera_counts (is_check = 1): the program sends the picture
// (the same flow as "Count now"), Reem counts it against the reference picture and its list.

const SC_CHECK_MAX_HOURS = 24;
const SC_KEEP_MAX_DAYS = 365;

/** Is the checks part of migrations/store_camera.sql run? */
function sc_checks_ready(PDO $conn): bool {
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query('SELECT check_every_hours, reference_items, alert_stopped, stopped_alert_at FROM store_cameras LIMIT 1');
            $conn->query('SELECT is_check, manual FROM store_camera_counts LIMIT 1');
            $conn->query('SELECT 1 FROM store_camera_alerts LIMIT 1');
            $ready = true;
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The check and alert settings from the Cameras form, checked. */
function sc_save_check_settings(PDO $conn, int $id, array $in): void {
    $every = (int)($in['check_every_hours'] ?? 2);
    $from = trim((string)($in['check_from'] ?? '')) === '' ? null : sc_time($in['check_from']);
    $to = trim((string)($in['check_to'] ?? '')) === '' ? null : sc_time($in['check_to']);
    $keep = (int)($in['check_keep_days'] ?? 30);
    $after = (int)($in['stopped_after_minutes'] ?? 15);
    if ($every < 0 || $every > SC_CHECK_MAX_HOURS) {
        throw new StoreCamError('Check every 0 (off) to ' . SC_CHECK_MAX_HOURS . ' hours.');
    }
    if ((trim((string)($in['check_from'] ?? '')) !== '' && $from === null) || (trim((string)($in['check_to'] ?? '')) !== '' && $to === null)) {
        throw new StoreCamError('Type the check hours like 07:00 and 22:00, or leave both empty for all day.');
    }
    if (($from === null) !== ($to === null)) {
        throw new StoreCamError('Give both check hours (from and to), or leave both empty for all day.');
    }
    if ($keep < 1 || $keep > SC_KEEP_MAX_DAYS) {
        throw new StoreCamError('Keep check pictures 1 to ' . SC_KEEP_MAX_DAYS . ' days.');
    }
    if ($after < 5 || $after > 240) {
        throw new StoreCamError('"Program not running" after 5 to 240 minutes.');
    }
    $conn->prepare('UPDATE store_cameras SET check_every_hours = ?, check_from = ?, check_to = ?, check_only_on_movement = ?, check_keep_days = ?,
        alert_movement = ?, alert_count = ?, alert_stopped = ?, stopped_after_minutes = ? WHERE id = ?')
        ->execute([$every, $from, $to, !empty($in['check_only_on_movement']) ? 1 : 0, $keep,
            !empty($in['alert_movement']) ? 1 : 0, !empty($in['alert_count']) ? 1 : 0, !empty($in['alert_stopped']) ? 1 : 0, $after, $id]);
}

/**
 * The check due now for a camera: the start of the current N-hour slot, counted from check_from
 * (or midnight), or null when checks are off or it is outside the check hours.
 */
function sc_check_slot(array $cam, int $now): ?int {
    $step = (int)$cam['check_every_hours'] * 3600;
    if ($step <= 0) {
        return null;
    }
    $day = strtotime(date('Y-m-d', $now));
    $from = $cam['check_from'] !== null ? $day + (strtotime('1970-01-01 ' . $cam['check_from'] . ' UTC') ?: 0) : $day;
    if ($cam['check_from'] !== null && $cam['check_to'] !== null) {
        $t = date('H:i:s', $now);
        $inside = $cam['check_from'] <= $cam['check_to']
            ? ($t >= $cam['check_from'] && $t < $cam['check_to'])
            : ($t >= $cam['check_from'] || $t < $cam['check_to']); // over midnight, e.g. 20:00-06:00
        if (!$inside) {
            return null;
        }
        if ($now < $from) {
            $from -= 86400; // an overnight window that began yesterday
        }
    }
    return $from + intdiv($now - $from, $step) * $step;
}

/** Make the checks that are due: a waiting count, or "skipped" when nothing moved since the last check. */
function sc_schedule_checks(PDO $conn): void {
    $now = time();
    foreach (sc_cameras($conn, true) as $cam) {
        $slot = sc_check_slot($cam, $now);
        if ($slot === null || ($cam['last_check_at'] !== null && strtotime((string)$cam['last_check_at']) >= $slot)) {
            continue;
        }
        $slotAt = date('Y-m-d H:i:s', $slot);
        // Claim the slot first, so two requests at once cannot both make it.
        $claim = $conn->prepare('UPDATE store_cameras SET last_check_at = ? WHERE id = ? AND (last_check_at IS NULL OR last_check_at < ?)');
        $claim->execute([$slotAt, (int)$cam['id'], $slotAt]);
        if ($claim->rowCount() === 0) {
            continue;
        }
        $since = $cam['last_check_at'] ?? date('Y-m-d H:i:s', $slot - (int)$cam['check_every_hours'] * 3600);
        $moved = $conn->prepare('SELECT 1 FROM store_camera_events WHERE camera_id = ? AND started_at >= ? LIMIT 1');
        $moved->execute([(int)$cam['id'], $since]);
        $billTo = (int)($cam['created_by'] ?? 0) ?: null; // scheduled checks are billed to whoever added the camera
        if (!empty($cam['check_only_on_movement']) && !$moved->fetchColumn()) {
            $conn->prepare("INSERT INTO store_camera_counts (camera_id, status, requested_by, at_time, is_check, counted_at, note)
                VALUES (?, 'skipped', ?, ?, 1, NOW(), 'Nothing moved since the last check, so it was not counted.')")
                ->execute([(int)$cam['id'], $billTo, $slotAt]);
        } else {
            $conn->prepare('INSERT INTO store_camera_counts (camera_id, requested_by, at_time, is_check) VALUES (?, ?, ?, 1)')
                ->execute([(int)$cam['id'], $billTo, $slotAt]);
        }
        sc_prune_check_pictures($conn, $cam);
    }
}

/** When the next check is due (a timestamp), or null when checks are off. */
function sc_next_check_at(array $cam, ?int $now = null): ?int {
    $now = $now ?? time();
    $step = (int)$cam['check_every_hours'] * 3600;
    if ($step <= 0) {
        return null;
    }
    // From the next whole step on, the first time that starts a check (inside the check hours).
    $t = (intdiv($now, 3600) + 1) * 3600;
    for ($i = 0; $i < 48 * 4; $i++, $t += 900) {
        if (sc_check_slot($cam, $t) === $t) {
            return $t;
        }
    }
    return null;
}

/**
 * What is going on at a camera, for the line under the live picture:
 * ['running' => bool, 'seen' => 'Y-m-d H:i:s'|null, 'event' => [time, description]|null, 'next' => timestamp|null, 'check' => count row|null].
 */
function sc_live_status(PDO $conn, array $cam): array {
    $st = $conn->prepare('SELECT started_at, description FROM store_camera_events WHERE camera_id = ? ORDER BY started_at DESC, id DESC LIMIT 1');
    $st->execute([(int)$cam['id']]);
    $event = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    return [
        'running' => sc_program_running($cam),
        'seen' => $cam['last_seen_at'] ?? null,
        'event' => $event,
        'next' => sc_checks_ready($conn) ? sc_next_check_at($cam) : null,
        'check' => sc_checks_ready($conn) ? sc_latest_check($conn, (int)$cam['id']) : null,
    ];
}

/** "Count now": a check made by hand, billed to the person who pressed it. Returns its id. */
function sc_request_check(PDO $conn, int $cameraId, int $userId): int {
    $conn->prepare('INSERT INTO store_camera_counts (camera_id, requested_by, at_time, is_check, manual) VALUES (?, ?, NOW(), 1, 1)')
        ->execute([$cameraId, $userId ?: null]);
    $id = (int)$conn->lastInsertId();
    sc_want_live($conn, $cameraId);
    return $id;
}

/**
 * Pictures older than the camera keeps them (check_keep_days, "Keep pictures" on Camera settings): check pictures and
 * movement snapshots. The file goes; the result, the time, the description and the daily report stay.
 */
function sc_prune_check_pictures(PDO $conn, array $cam): void {
    $days = max(1, (int)$cam['check_keep_days']);
    $st = $conn->prepare('SELECT id, snapshot FROM store_camera_counts WHERE camera_id = ? AND is_check = 1 AND snapshot IS NOT NULL
        AND requested_at < NOW() - INTERVAL ? DAY LIMIT 100');
    $st->execute([(int)$cam['id'], $days]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        @unlink(sc_abs((string)$r['snapshot']));
        $conn->prepare('UPDATE store_camera_counts SET snapshot = NULL WHERE id = ?')->execute([(int)$r['id']]);
    }
    $st = $conn->prepare("SELECT id, snapshot FROM store_camera_events WHERE camera_id = ? AND snapshot IS NOT NULL AND snapshot <> ''
        AND started_at < NOW() - INTERVAL ? DAY LIMIT 200");
    $st->execute([(int)$cam['id'], $days]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        @unlink(sc_abs((string)$r['snapshot']));
        $conn->prepare('UPDATE store_camera_events SET snapshot = NULL WHERE id = ?')->execute([(int)$r['id']]);
    }
}

/** The camera's starting list: [['item' => 'Jerry can', 'count' => 30], ...]. */
function sc_reference_items(array $cam): array {
    return json_decode((string)($cam['reference_items'] ?? ''), true) ?: [];
}

/** Save the starting list typed or corrected on the Live tab (names and counts in two lists). */
function sc_save_reference_items(PDO $conn, int $cameraId, array $names, array $counts): int {
    $items = [];
    foreach ($names as $i => $n) {
        $items[] = ['item' => $n, 'count' => $counts[$i] ?? 0];
    }
    $items = sc_clean_items($items);
    $conn->prepare('UPDATE store_cameras SET reference_items = ? WHERE id = ?')->execute([json_encode($items, JSON_UNESCAPED_UNICODE), $cameraId]);
    return count($items);
}

/** The pins on the reference picture, in list order: [['item', 'count', 'x', 'y'], ...] (x, y in %; absent on a row with no pin). */
function sc_reference_points(array $cam): array {
    return json_decode((string)($cam['reference_points'] ?? ''), true) ?: [];
}

/**
 * Save the starting list from the Live tab: each row an item and its count, with a pin (x, y in % of
 * the picture) when it was pointed on the reference picture. The starting list kept for the checks
 * is the total per item. Returns how many rows were kept.
 */
function sc_save_reference_rows(PDO $conn, int $cameraId, array $items, array $qtys, array $xs, array $ys): int {
    $rows = [];
    $totals = [];
    foreach ($items as $i => $name) {
        $name = mb_substr(trim(preg_replace('/\s+/', ' ', (string)$name)), 0, 80);
        if ($name === '') {
            continue;
        }
        $row = ['item' => $name, 'count' => max(0, min(100000, (int)($qtys[$i] ?? 0)))];
        $x = $xs[$i] ?? '';
        $y = $ys[$i] ?? '';
        if (is_numeric($x) && is_numeric($y)) {
            $row['x'] = round(max(0, min(100, (float)$x)), 2);
            $row['y'] = round(max(0, min(100, (float)$y)), 2);
        }
        $rows[] = $row;
        $k = mb_strtolower($name);
        $totals[$k] = $totals[$k] ?? ['item' => $name, 'count' => 0];
        $totals[$k]['count'] += $row['count'];
        if (count($rows) >= 2 * SC_COUNT_MAX_ITEMS) {
            break;
        }
    }
    $conn->prepare('UPDATE store_cameras SET reference_points = ?, reference_items = ? WHERE id = ?')
        ->execute([json_encode($rows, JSON_UNESCAPED_UNICODE), json_encode(array_values($totals), JSON_UNESCAPED_UNICODE), $cameraId]);
    return count($rows);
}

/** The starting list for Reem, each item with the numbers of its pins: [['item', 'count', 'marks' => [1, 3]], ...]. */
function sc_reference_for_reem(array $cam): array {
    $points = sc_reference_points($cam);
    if (!$points) {
        return sc_reference_items($cam);
    }
    $out = [];
    foreach ($points as $i => $p) {
        $k = mb_strtolower((string)$p['item']);
        $out[$k] = $out[$k] ?? ['item' => (string)$p['item'], 'count' => 0, 'marks' => []];
        $out[$k]['count'] += (int)$p['count'];
        if (isset($p['x'], $p['y'])) {
            $out[$k]['marks'][] = $i + 1;
        }
    }
    return array_values($out);
}

/**
 * The reference picture with its numbered pins drawn on it, for Reem: [path, made] - made = a
 * temporary file the caller deletes. With no pins (or no image library), the picture itself.
 */
function sc_marked_reference(array $cam, string $path): array {
    $pins = [];
    foreach (sc_reference_points($cam) as $i => $p) {
        if (isset($p['x'], $p['y'])) {
            $pins[$i + 1] = $p;
        }
    }
    $im = ($pins && function_exists('imagecreatefromjpeg') && is_file($path)) ? @imagecreatefromjpeg($path) : false;
    if (!$im) {
        return [$path, false];
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $r = max(12, (int)round($w / 70));
    $red = imagecolorallocate($im, 220, 20, 20);
    $white = imagecolorallocate($im, 255, 255, 255);
    $font = null;
    foreach (['C:/Windows/Fonts/arialbd.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf'] as $f) {
        if (is_file($f) && function_exists('imagettftext')) {
            $font = $f;
            break;
        }
    }
    foreach ($pins as $n => $p) {
        $cx = (int)round($p['x'] / 100 * $w);
        $cy = (int)round($p['y'] / 100 * $h);
        imagefilledellipse($im, $cx, $cy, 2 * $r, 2 * $r, $red);
        imagesetthickness($im, 2);
        imageellipse($im, $cx, $cy, 2 * $r, 2 * $r, $white);
        $label = (string)$n;
        if ($font) {
            $size = $r * 0.95;
            $box = imagettfbbox($size, 0, $font, $label);
            imagettftext($im, $size, 0, (int)($cx - ($box[2] - $box[0]) / 2), (int)($cy + ($box[1] - $box[7]) / 2), $white, $font, $label);
        } else {
            imagestring($im, 5, $cx - 4 * strlen($label), $cy - 7, $label, $white);
        }
    }
    $tmp = sys_get_temp_dir() . '/scref_' . bin2hex(random_bytes(6)) . '.jpg';
    $made = imagejpeg($im, $tmp, 88);
    imagedestroy($im);
    return $made ? [$tmp, true] : [$path, false];
}

// ---------- the free comparison: the picture now against the reference picture ----------
//
// The camera does not move, so the two pictures line up. Both are made small and grey, smoothed,
// brought to the same brightness, and compared point by point; what is left are the spots that
// changed. Nothing is sent anywhere and nothing is paid. It says where and how much, not how many.

const SC_DIFF_W = 160;          // the pictures are compared at this size (16:9)
const SC_DIFF_H = 90;
const SC_DIFF_LEVEL = 30;       // a point this much lighter or darker (0-255) than in the reference has changed
const SC_PIN_CHANGED_PCT = 20;  // a pin's spot with this much of it changed is "changed"
const SC_STORE_CHANGED_PCT = 5; // with no pins: this much of the whole picture changed rings the bell
// Never compared (in % of the picture): the camera's own date and time (bottom left) and its logo (top left).
const SC_DIFF_IGNORE = [[0, 86, 50, 100], [0, 0, 22, 12]];

/** A picture as SC_DIFF_W x SC_DIFF_H grey values (0-255), smoothed; null when it cannot be read. */
function sc_gray_small(string $path): ?array {
    $src = is_file($path) ? @imagecreatefromjpeg($path) : false;
    if (!$src) {
        return null;
    }
    $im = imagecreatetruecolor(SC_DIFF_W, SC_DIFF_H);
    imagecopyresampled($im, $src, 0, 0, 0, 0, SC_DIFF_W, SC_DIFF_H, imagesx($src), imagesy($src));
    imagedestroy($src);
    imagefilter($im, IMG_FILTER_GRAYSCALE);
    imagefilter($im, IMG_FILTER_GAUSSIAN_BLUR);
    imagefilter($im, IMG_FILTER_GAUSSIAN_BLUR);
    $px = [];
    for ($y = 0; $y < SC_DIFF_H; $y++) {
        for ($x = 0; $x < SC_DIFF_W; $x++) {
            $px[] = imagecolorat($im, $x, $y) & 0xFF;
        }
    }
    imagedestroy($im);
    return $px;
}

/**
 * Compare the picture now with the reference: ['pct' => % of the picture changed, 'boxes' => [[x0,y0,x1,y1] in %],
 * 'pins' => [['no', 'item', 'count', 'pct', 'changed']], 'light' => how much brighter the reference is]; null when
 * a picture cannot be read. $points: the reference's pins (sc_reference_points).
 */
function sc_compare_pictures(array $points, string $refPath, string $nowPath): ?array {
    $a = sc_gray_small($refPath);
    $b = sc_gray_small($nowPath);
    if (!$a || !$b) {
        return null;
    }
    $W = SC_DIFF_W;
    $H = SC_DIFF_H;
    $use = [];
    for ($i = 0; $i < $W * $H; $i++) {
        $px = ($i % $W) / $W * 100;
        $py = intdiv($i, $W) / $H * 100;
        $skip = false;
        foreach (SC_DIFF_IGNORE as [$x0, $y0, $x1, $y1]) {
            $skip = $skip || ($px >= $x0 && $px < $x1 && $py >= $y0 && $py < $y1);
        }
        $use[$i] = !$skip;
    }
    // Even out the light: the picture now is brought to the reference's average brightness.
    $sa = $sb = $n = 0;
    foreach ($use as $i => $u) {
        if ($u) {
            $sa += $a[$i];
            $sb += $b[$i];
            $n++;
        }
    }
    $k = $sb > 0 ? $sa / $sb : 1.0;
    $mask = [];
    foreach ($use as $i => $u) {
        $mask[$i] = ($u && abs($a[$i] - min(255, $b[$i] * $k)) > SC_DIFF_LEVEL) ? 1 : 0;
    }
    // A changed point with fewer than 3 changed neighbours is noise.
    $clean = $mask;
    for ($i = 0; $i < $W * $H; $i++) {
        if (!$mask[$i]) {
            continue;
        }
        $x = $i % $W;
        $y = intdiv($i, $W);
        $near = 0;
        for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                if (($dx || $dy) && $x + $dx >= 0 && $x + $dx < $W && $y + $dy >= 0 && $y + $dy < $H) {
                    $near += $mask[($y + $dy) * $W + $x + $dx];
                }
            }
        }
        if ($near < 3) {
            $clean[$i] = 0;
        }
    }
    // Boxes round each group of changed points big enough to matter.
    $seen = [];
    $boxes = [];
    $minSize = max(15, (int)($W * $H * 0.002));
    for ($i = 0; $i < $W * $H; $i++) {
        if (!$clean[$i] || isset($seen[$i])) {
            continue;
        }
        $stack = [$i];
        $seen[$i] = true;
        $size = 0;
        $x0 = $W; $y0 = $H; $x1 = 0; $y1 = 0;
        while ($stack) {
            $j = array_pop($stack);
            $size++;
            $x = $j % $W;
            $y = intdiv($j, $W);
            $x0 = min($x0, $x); $x1 = max($x1, $x); $y0 = min($y0, $y); $y1 = max($y1, $y);
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                $nj = $ny * $W + $nx;
                if ($nx >= 0 && $nx < $W && $ny >= 0 && $ny < $H && $clean[$nj] && !isset($seen[$nj])) {
                    $seen[$nj] = true;
                    $stack[] = $nj;
                }
            }
        }
        if ($size >= $minSize) {
            $boxes[] = [$size, [round(max(0, $x0 - 1) / $W * 100, 1), round(max(0, $y0 - 1) / $H * 100, 1),
                round(min($W, $x1 + 2) / $W * 100, 1), round(min($H, $y1 + 2) / $H * 100, 1)]];
        }
    }
    usort($boxes, function ($p, $q) { return $q[0] <=> $p[0]; });
    $changed = array_sum($clean);
    // Each pin's spot: a box round it, about a seventh of the picture wide.
    $pins = [];
    foreach ($points as $i => $p) {
        if (!isset($p['x'], $p['y'])) {
            continue;
        }
        $cx = $p['x'] / 100 * $W;
        $cy = $p['y'] / 100 * $H;
        $tot = $chg = 0;
        for ($y = max(0, (int)($cy - 0.1 * $H)); $y <= min($H - 1, (int)($cy + 0.1 * $H)); $y++) {
            for ($x = max(0, (int)($cx - 0.07 * $W)); $x <= min($W - 1, (int)($cx + 0.07 * $W)); $x++) {
                if ($use[$y * $W + $x]) {
                    $tot++;
                    $chg += $clean[$y * $W + $x];
                }
            }
        }
        $pct = $tot ? round($chg / $tot * 100, 1) : 0.0;
        $pins[] = ['no' => $i + 1, 'item' => (string)$p['item'], 'count' => (int)$p['count'], 'x' => (float)$p['x'], 'y' => (float)$p['y'],
            'pct' => $pct, 'changed' => $pct >= SC_PIN_CHANGED_PCT];
    }
    return [
        'pct' => $n ? round($changed / $n * 100, 1) : 0.0,
        'boxes' => array_slice(array_column($boxes, 1), 0, 30),
        'pins' => $pins,
        'light' => round($k, 2),
    ];
}

/** How many changed areas the page lists (and numbers on the picture). */
const SC_AREAS_LISTED = 10;

/** Where a changed area [x0, y0, x1, y1] (in %) is on the picture, in words: "top left", "middle", "bottom right". */
function sc_area_where(array $b): string {
    $cx = ($b[0] + $b[2]) / 2;
    $cy = ($b[1] + $b[3]) / 2;
    $v = $cy < 33.3 ? 'top' : ($cy > 66.6 ? 'bottom' : 'middle');
    $hz = $cx < 33.3 ? 'left' : ($cx > 66.6 ? 'right' : 'middle');
    return $v === $hz ? 'middle' : $v . ' ' . $hz;
}

/** A check compared for free: keep the result; ring the bell for what changed since the check before. */
function sc_save_diff(PDO $conn, array $cam, int $countId, array $diff, string $snapshot): void {
    $conn->prepare("UPDATE store_camera_counts SET status = 'done', counted_at = NOW(), diff = ?, snapshot = ?, note = NULL WHERE id = ?")
        ->execute([json_encode($diff, JSON_UNESCAPED_UNICODE), $snapshot, $countId]);
    $st = $conn->prepare("SELECT diff FROM store_camera_counts WHERE camera_id = ? AND is_check = 1 AND status = 'done' AND diff IS NOT NULL AND id < ? ORDER BY id DESC LIMIT 1");
    $st->execute([(int)$cam['id'], $countId]);
    $before = json_decode((string)$st->fetchColumn(), true) ?: null;
    $url = 'store_camera.php?tab=live&camera=' . (int)$cam['id'] . '#sc-count';
    if ($diff['pins']) {
        // A pin that changed now and had not changed at the check before.
        $was = [];
        foreach (($before['pins'] ?? []) as $p) {
            $was[$p['no']] = !empty($p['changed']);
        }
        $new = array_filter($diff['pins'], function ($p) use ($was) { return $p['changed'] && empty($was[$p['no']]); });
        if ($new) {
            sc_alert($conn, $cam, 'count', $cam['name'] . ': ' . count($new) . ' pinned spot' . (count($new) === 1 ? '' : 's') . ' changed',
                implode(', ', array_map(function ($p) { return 'pin ' . $p['no'] . ' ' . $p['item'] . ' (' . $p['pct'] . '% different)'; }, $new)), $url);
        }
    } elseif ($diff['pct'] >= SC_STORE_CHANGED_PCT && ($before === null || $before['pct'] < SC_STORE_CHANGED_PCT)) {
        sc_alert($conn, $cam, 'count', $cam['name'] . ': store looks different', 'About ' . $diff['pct'] . '% of the picture changed since the reference.', $url);
    }
}

/** The font for numbers drawn on pictures, or null (then the built-in one is used). */
function sc_pin_font(): ?string {
    foreach (['C:/Windows/Fonts/arialbd.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf'] as $f) {
        if (is_file($f) && function_exists('imagettftext')) {
            return $f;
        }
    }
    return null;
}

/** A numbered round pin on a picture. */
function sc_draw_pin($im, int $cx, int $cy, int $r, string $label, int $fill, int $white, ?string $font): void {
    imagefilledellipse($im, $cx, $cy, 2 * $r, 2 * $r, $fill);
    imagesetthickness($im, 2);
    imageellipse($im, $cx, $cy, 2 * $r, 2 * $r, $white);
    if ($font) {
        $box = imagettfbbox($r * 0.95, 0, $font, $label);
        imagettftext($im, $r * 0.95, 0, (int)($cx - ($box[2] - $box[0]) / 2), (int)($cy + ($box[1] - $box[7]) / 2), $white, $font, $label);
    } else {
        imagestring($im, 5, $cx - 4 * strlen($label), $cy - 7, $label, $white);
    }
}

/** A check's picture with what changed boxed in red and each pin drawn (red = changed, green = no change), as JPEG bytes. */
function sc_overlay_jpeg(string $picPath, array $diff): ?string {
    $im = is_file($picPath) ? @imagecreatefromjpeg($picPath) : false;
    if (!$im) {
        return null;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $red = imagecolorallocate($im, 230, 20, 20);
    $green = imagecolorallocate($im, 25, 160, 70);
    $white = imagecolorallocate($im, 255, 255, 255);
    imagesetthickness($im, max(2, (int)round($w / 400)));
    foreach ($diff['boxes'] ?? [] as [$x0, $y0, $x1, $y1]) {
        imagerectangle($im, (int)($x0 / 100 * $w), (int)($y0 / 100 * $h), (int)($x1 / 100 * $w), (int)($y1 / 100 * $h), $red);
    }
    $font = sc_pin_font();
    $r = max(12, (int)round($w / 70));
    // The numbers of the changed areas listed on the page (the biggest first).
    foreach (array_slice($diff['boxes'] ?? [], 0, SC_AREAS_LISTED) as $i => [$x0, $y0]) {
        sc_draw_pin($im, (int)($x0 / 100 * $w) + $r, (int)($y0 / 100 * $h) + $r, $r, (string)($i + 1), $red, $white, $font);
    }
    foreach ($diff['pins'] ?? [] as $p) {
        $cx = (int)round(($p['x'] ?? 0) / 100 * $w);
        $cy = (int)round(($p['y'] ?? 0) / 100 * $h);
        if (isset($p['x'], $p['y'])) {
            sc_draw_pin($im, $cx, $cy, $r, (string)$p['no'], !empty($p['changed']) ? $red : $green, $white, $font);
        }
    }
    ob_start();
    imagejpeg($im, null, 85);
    imagedestroy($im);
    return (string)ob_get_clean();
}

/** A part of a picture round a pin (x, y in %), about a third of it wide, made at most 512 px wide: a temporary JPEG the caller deletes. */
function sc_crop_round(string $path, float $x, float $y, ?string $pinLabel = null): ?string {
    $src = is_file($path) ? @imagecreatefromjpeg($path) : false;
    if (!$src) {
        return null;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $cw = (int)($w * 0.34);
    $ch = (int)($h * 0.46);
    $sx = max(0, min($w - $cw, (int)($x / 100 * $w - $cw / 2)));
    $sy = max(0, min($h - $ch, (int)($y / 100 * $h - $ch / 2)));
    $ow = min(512, $cw);
    $oh = (int)round($ch * $ow / $cw);
    $out = imagecreatetruecolor($ow, $oh);
    imagecopyresampled($out, $src, 0, 0, $sx, $sy, $ow, $oh, $cw, $ch);
    imagedestroy($src);
    if ($pinLabel !== null) {
        $red = imagecolorallocate($out, 220, 20, 20);
        $white = imagecolorallocate($out, 255, 255, 255);
        sc_draw_pin($out, (int)(($x / 100 * $w - $sx) * $ow / $cw), (int)(($y / 100 * $h - $sy) * $oh / $ch), 13, $pinLabel, $red, $white, sc_pin_font());
    }
    $tmp = sys_get_temp_dir() . '/sccrop_' . bin2hex(random_bytes(6)) . '.jpg';
    $ok = imagejpeg($out, $tmp, 85);
    imagedestroy($out);
    return $ok ? $tmp : null;
}

/**
 * "Ask how many" on one pin of a check: Reem counts that item in the cut-out round the pin, now against
 * the reference cut-out, with its smallest model (a fraction of a cent). Keeps it on the check; returns the count.
 */
function sc_ask_pin(PDO $conn, int $userId, array $check, int $pinNo): int {
    $cam = sc_camera($conn, (int)$check['camera_id']);
    $diff = json_decode((string)$check['diff'], true) ?: [];
    $pin = null;
    foreach (sc_reference_points($cam ?: []) as $i => $p) {
        if ($i + 1 === $pinNo && isset($p['x'], $p['y'])) {
            $pin = $p;
        }
    }
    $ref = $cam && !empty($cam['reference_photo_id']) ? sc_photo($conn, (int)$cam['reference_photo_id']) : null;
    if (!$pin || !$ref || empty($check['snapshot'])) {
        throw new StoreCamError('That pin, the reference picture or the check picture is not there any more.');
    }
    $refCut = sc_crop_round(sc_abs((string)$ref['path']), (float)$pin['x'], (float)$pin['y'], (string)$pinNo);
    $nowCut = sc_crop_round(sc_abs((string)$check['snapshot']), (float)$pin['x'], (float)$pin['y']);
    try {
        if (!$refCut || !$nowCut) {
            throw new StoreCamError('The pictures could not be cut.');
        }
        try {
            $got = sc_reem_count($conn, $userId, $nowCut, [$pin['item']], $refCut,
                [['item' => $pin['item'], 'count' => (int)$pin['count'], 'marks' => [$pinNo]]], true);
        } catch (BinvError $e) {
            throw new StoreCamError('Reem could not count it: ' . $e->getMessage());
        }
    } finally {
        foreach ([$refCut, $nowCut] as $f) {
            if ($f) {
                @unlink($f);
            }
        }
    }
    $now = 0;
    foreach ($got['items'] as $it) {
        if (mb_strtolower($it['item']) === mb_strtolower((string)$pin['item'])) {
            $now += (int)$it['count'];
        }
    }
    foreach ($diff['pins'] as &$p) {
        if ((int)$p['no'] === $pinNo) {
            $p['now'] = $now;
            $p['asked_at'] = date('Y-m-d H:i:s');
        }
    }
    unset($p);
    $conn->prepare('UPDATE store_camera_counts SET diff = ? WHERE id = ?')->execute([json_encode($diff, JSON_UNESCAPED_UNICODE), (int)$check['id']]);
    return $now;
}

/** A check's rows against the starting list: [['item', 'start' => n|null, 'now' => n, 'diff' => n|null]]. */
function sc_check_rows(array $refItems, array $items): array {
    $key = function (string $s): string { return mb_strtolower(trim(preg_replace('/\s+/', ' ', $s))); };
    $rows = [];
    foreach ($refItems as $it) {
        $rows[$key((string)$it['item'])] = ['item' => (string)$it['item'], 'start' => (int)$it['count'], 'now' => 0];
    }
    foreach ($items as $it) {
        $k = $key((string)$it['item']);
        $rows[$k] = $rows[$k] ?? ['item' => (string)$it['item'], 'start' => null, 'now' => 0];
        $rows[$k]['now'] += (int)$it['count'];
    }
    foreach ($rows as &$r) {
        $r['diff'] = $r['start'] === null ? null : $r['now'] - $r['start'];
    }
    unset($r);
    return array_values($rows);
}

/** The newest check of a camera (items decoded), or null. */
function sc_latest_check(PDO $conn, int $cameraId): ?array {
    if (!sc_checks_ready($conn)) {
        return null;
    }
    $st = $conn->prepare("SELECT * FROM store_camera_counts WHERE camera_id = ? AND is_check = 1 AND status <> 'skipped' ORDER BY id DESC LIMIT 1");
    $st->execute([$cameraId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $row['items'] = json_decode((string)$row['items'], true) ?: [];
    }
    return $row ?: null;
}

/** The checks of a day for the report, oldest first (items decoded). */
function sc_day_checks(PDO $conn, int $cameraId, string $start, string $end): array {
    if (!sc_checks_ready($conn)) {
        return [];
    }
    $st = $conn->prepare('SELECT * FROM store_camera_counts WHERE camera_id = ? AND is_check = 1 AND at_time >= ? AND at_time < ? ORDER BY at_time, id');
    $st->execute([$cameraId, $start, $end]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['items'] = json_decode((string)$r['items'], true) ?: [];
    }
    unset($r);
    return $rows;
}

/** After a check is counted: ring the bell when the counts differ from the check before it. */
function sc_after_check(PDO $conn, array $cam, int $countId): void {
    $now = sc_count($conn, $countId);
    if (!$now || $now['status'] !== 'done' || empty($cam['alert_count'])) {
        return;
    }
    $st = $conn->prepare("SELECT items FROM store_camera_counts WHERE camera_id = ? AND is_check = 1 AND status = 'done' AND id < ? ORDER BY id DESC LIMIT 1");
    $st->execute([(int)$cam['id'], $countId]);
    $before = $st->fetchColumn();
    // Against the check before it; the first check is against the starting list.
    $was = $before === false ? sc_reference_items($cam) : (json_decode((string)$before, true) ?: []);
    if ($before === false && !$was) {
        return; // no earlier check and no starting list: nothing to compare with yet
    }
    $changes = [];
    foreach (sc_check_rows($was, json_decode((string)$now['items'], true) ?: []) as $r) {
        if ($r['diff'] !== null && $r['diff'] !== 0) {
            $changes[] = $r['item'] . ' ' . $r['start'] . ' → ' . $r['now'] . ' (' . ($r['diff'] > 0 ? '+' : '') . $r['diff'] . ')';
        } elseif ($r['start'] === null && $r['now'] > 0) {
            $changes[] = $r['item'] . ' new: ' . $r['now'];
        }
    }
    if ($changes) {
        sc_alert($conn, $cam, 'count', $cam['name'] . ': count changed', implode(', ', $changes), 'store_camera.php?tab=live&camera=' . (int)$cam['id'] . '#sc-count');
    }
}

/** One line in the Operations bell, when the camera has that alert switched on. */
function sc_alert(PDO $conn, array $cam, string $kind, string $title, string $detail = '', string $url = ''): void {
    $on = ['movement' => 'alert_movement', 'count' => 'alert_count', 'stopped' => 'alert_stopped'][$kind] ?? null;
    if (!sc_checks_ready($conn) || ($on && empty($cam[$on]))) {
        return;
    }
    $conn->prepare('INSERT INTO store_camera_alerts (camera_id, kind, title, detail, url) VALUES (?, ?, ?, ?, ?)')
        ->execute([(int)$cam['id'], $kind, mb_substr($title, 0, 200), $detail !== '' ? mb_substr($detail, 0, 500) : null, $url !== '' ? $url : null]);
}

/** Ring "program not running" once per stop, for each camera the program has not asked about for too long. */
function sc_check_stopped(PDO $conn): void {
    if (!sc_checks_ready($conn)) {
        return;
    }
    foreach (sc_cameras($conn, true) as $cam) {
        if (empty($cam['alert_stopped']) || empty($cam['last_seen_at'])) {
            continue;
        }
        $silent = time() - strtotime((string)$cam['last_seen_at']);
        $rang = $cam['stopped_alert_at'] !== null && strtotime((string)$cam['stopped_alert_at']) >= strtotime((string)$cam['last_seen_at']);
        if ($silent > (int)$cam['stopped_after_minutes'] * 60 && !$rang) {
            $conn->prepare('UPDATE store_cameras SET stopped_alert_at = NOW() WHERE id = ?')->execute([(int)$cam['id']]);
            sc_alert($conn, $cam, 'stopped', $cam['name'] . ': store camera program not running',
                'Last seen ' . date('d M H:i', strtotime((string)$cam['last_seen_at'])) . '. Start store_camera.bat on the laptop.',
                'store_camera.php?tab=cameras');
        }
    }
}

/** The newest alerts for the bell: [['id', 'kind', 'title', 'detail', 'url', 'at']]. */
function sc_alerts(PDO $conn, int $limit = 20): array {
    if (!sc_checks_ready($conn)) {
        return [];
    }
    $rows = $conn->query('SELECT a.id, a.kind, a.title, a.detail, a.url, a.created_at FROM store_camera_alerts a ORDER BY a.id DESC LIMIT ' . max(1, $limit))
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(function ($r) {
        return ['id' => (int)$r['id'], 'kind' => $r['kind'], 'title' => $r['title'], 'detail' => $r['kind'] === 'movement' ? sc_short_text(sc_event_text((string)$r['detail'])) : (string)$r['detail'], 'url' => (string)$r['url'], 'at' => $r['created_at']];
    }, $rows);
}

/** Is the laptop program watching: it asks for its cameras every few minutes. */
function sc_program_seen(array $cam): ?string {
    if (empty($cam['last_seen_at'])) {
        return null;
    }
    return (string)$cam['last_seen_at'];
}

function sc_program_running(array $cam): bool {
    return !empty($cam['last_seen_at']) && strtotime((string)$cam['last_seen_at']) > time() - 15 * 60;
}
