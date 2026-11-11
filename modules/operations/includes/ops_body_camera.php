<?php
/**
 * Operations → Body camera — the rules. A Looki L1 worn by field staff on
 * chosen tasks. The L1 uploads to Looki's cloud while it charges on Wi-Fi;
 * Sync now on the Setup tab asks Looki's API for the device's recordings and
 * keeps them here. There is no automatic sync.
 *
 * Looki's API (https://platform.looki.ai/en/api-reference): base
 * https://open.looki.ai/api/v1, header X-API-Key, 60 requests a minute per key.
 * A "moment" is a stretch of recording Looki has titled and described; its
 * files come from /moments/{id}/files as links that expire after 10 minutes.
 *
 * The photos and videos stay in Looki's cloud. saifsys keeps only what each
 * one is (when, where, which job); body_camera_file.php asks Looki for a fresh
 * link when someone opens one and sends the browser there (bc_media_url).
 *
 * A file is linked to the job its wearer had started at that time (from
 * Start to Finish, 10 minutes either side). Files outside every job stay
 * "Not linked" for the office to link or delete.
 *
 * Events on the daily report:
 *   Store entry     an "out" entry in Operations → Store given to this person
 *   Store camera    the Store camera saw fewer of an item while this person was
 *                   recording (a file within 10 minutes of the change)
 *   Voice           a sentence of Looki's title, description or audio summary
 *                   that names a store item, or a take/return word with the store
 * A camera or voice event with no store entry within an hour is an alert.
 * Looki's audio summary is never kept, only a sentence that matched.
 *
 * Files in uploads/body_camera/ (not web-readable), all small:
 *   settings.json          how many days the report rows are kept
 *   sync.lock              one sync at a time
 *   links/<moment id>.json a moment's fresh Looki links, reused for 8 minutes
 * Tables: migrations/ops_body_camera.sql.
 */

const BC_API_BASE = 'https://open.looki.ai/api/v1';
/** Looki asks custom applications to name themselves; requests without one are refused. */
const BC_USER_AGENT = 'Saifsys-BodyCamera/1.0';
const BC_LINK_GRACE_MINUTES = 10;
const BC_CAMERA_MINUTES = 10;
const BC_STORE_ENTRY_MINUTES = 60;
const BC_KEEP_DAYS_DEFAULT = 90;
const BC_FIRST_SYNC_DAYS = 7;
/** Looki's links last 10 minutes; reuse them for 8 so one never runs out mid-view. */
const BC_LINK_CACHE_SECONDS = 480;

/** Words that make a sentence about taking or returning something. */
/** Looki describes what it saw in the present tense ("picks up two cans"), so every form is here. */
const BC_ACTION_WORDS = ['take', 'takes', 'took', 'taking', 'taken', 'collect', 'collects', 'collected', 'collecting',
    'pick', 'picks', 'picked', 'picking', 'grab', 'grabs', 'grabbed', 'grabbing', 'borrow', 'borrows', 'borrowed',
    'return', 'returns', 'returned', 'returning', 'issue', 'issues', 'issued', 'hand', 'hands', 'handed',
    'remove', 'removes', 'removed', 'removing', 'carry', 'carries', 'carried', 'carrying', 'load', 'loads', 'loaded', 'loading'];
/** Words for the store itself. */
const BC_STORE_WORDS = ['store', 'storeroom', 'store room', 'shelf', 'shelves', 'stock', 'warehouse', 'storage'];

class BcError extends RuntimeException
{
}

function bc_tables_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query('SELECT 1 FROM ops_bodycam_hidden LIMIT 0');
            $ready = true;
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Owner, Admin and HR set up devices and keys, and delete recordings. */
function bc_can_manage(PDO $conn): bool
{
    return ops_can_inactivate($conn);
}

/** uploads/body_camera[/$sub], made when missing; the web server refuses to serve it. */
function bc_dir(string $sub = ''): string
{
    $base = dirname(__DIR__, 3) . '/uploads/body_camera';
    $dir = $sub === '' ? $base : $base . '/' . $sub;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_file($base . '/.htaccess')) {
        @file_put_contents($base . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $dir;
}

function bc_now(): string
{
    return date('Y-m-d H:i:s');
}

// ---------------------------------------------------------------------------
// Settings and devices
// ---------------------------------------------------------------------------

function bc_settings(): array
{
    $f = bc_dir() . '/settings.json';
    $s = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    $keep = (int)($s['keep_days'] ?? BC_KEEP_DAYS_DEFAULT);
    return ['keep_days' => $keep >= 1 ? $keep : BC_KEEP_DAYS_DEFAULT];
}

function bc_settings_save(array $in): void
{
    $keep = (int)($in['keep_days'] ?? 0);
    if ($keep < 7 || $keep > 3650) {
        throw new BcError('Keep files for 7 to 3650 days.');
    }
    file_put_contents(bc_dir() . '/settings.json', json_encode(['keep_days' => $keep]));
}

function bc_devices(PDO $conn, bool $activeOnly = false): array
{
    $sql = "SELECT d.*, COALESCE(NULLIF(u.fullname, ''), u.username) AS person
        FROM ops_bodycam_devices d LEFT JOIN user u ON u.id = d.user_id"
        . ($activeOnly ? ' WHERE d.active = 1' : '') . ' ORDER BY d.active DESC, person, d.id';
    return $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function bc_device(PDO $conn, int $id): ?array
{
    $st = $conn->prepare('SELECT * FROM ops_bodycam_devices WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The start of a key, which is all the page ever shows. */
function bc_key_hint(string $key): string
{
    return substr($key, 0, 8) . '…';
}

/** Add ($id 0) or change a device. An empty key on a change keeps the old one. */
function bc_save_device(PDO $conn, int $id, array $in, ?int $userId): int
{
    $who = (int)($in['user_id'] ?? 0);
    $label = trim((string)($in['label'] ?? ''));
    $key = trim((string)($in['api_key'] ?? ''));
    $active = !empty($in['active']) ? 1 : 0;
    if ($who <= 0) {
        throw new BcError('Choose who wears this L1.');
    }
    if ($label === '' || mb_strlen($label) > 80) {
        throw new BcError('Give the device a name of up to 80 letters, e.g. Looki L1 (51289).');
    }
    if ($key !== '' && !preg_match('/^[A-Za-z0-9_\-]{16,255}$/', $key)) {
        throw new BcError('That does not look like a Looki API key. Copy it again from Looki → API keys.');
    }
    if ($id === 0) {
        if ($key === '') {
            throw new BcError('Paste the API key from Looki → API keys.');
        }
        $conn->prepare('INSERT INTO ops_bodycam_devices (user_id, label, api_key, active, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$who, $label, $key, $active, bc_now(), $userId]);
        return (int)$conn->lastInsertId();
    }
    if (!bc_device($conn, $id)) {
        throw new BcError('That device is gone.');
    }
    $conn->prepare('UPDATE ops_bodycam_devices SET user_id = ?, label = ?, active = ?, updated_at = ?' . ($key !== '' ? ', api_key = ?' : '') . ' WHERE id = ?')
        ->execute(array_merge([$who, $label, $active, bc_now()], $key !== '' ? [$key] : [], [$id]));
    return $id;
}

// ---------------------------------------------------------------------------
// Looki
// ---------------------------------------------------------------------------

/** One GET to Looki's API; answers its `data`. Keeps under 60 requests a minute. */
function bc_api(#[SensitiveParameter] string $key, string $path, array $query = []): array
{
    static $last = 0.0;
    for ($try = 0; $try < 2; $try++) {
        $wait = 1.05 - (microtime(true) - $last);
        if ($wait > 0) {
            usleep((int)($wait * 1000000));
        }
        $last = microtime(true);
        $ch = curl_init(BC_API_BASE . $path . ($query ? '?' . http_build_query($query) : ''));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['X-API-Key: ' . $key, 'Accept: application/json'],
            CURLOPT_USERAGENT => BC_USER_AGENT,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 45,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code === 429 && $try === 0) {
            sleep(61);
            continue;
        }
        if ($body === false) {
            throw new BcError('Could not reach Looki: ' . $err);
        }
        $json = json_decode((string)$body, true);
        if ($code === 401 || $code === 403) {
            throw new BcError('Looki refused the API key. Create a new key in Looki → API keys and paste it here.');
        }
        if ($code !== 200 || !is_array($json) || (int)($json['code'] ?? -1) !== 0) {
            throw new BcError('Looki answered ' . $code . ': ' . mb_substr((string)($json['detail'] ?? $body), 0, 150));
        }
        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }
    throw new BcError('Looki is busy (too many requests). The next sync will try again.');
}

/** Every page of a list endpoint. */
function bc_api_all(#[SensitiveParameter] string $key, string $path, array $query): array
{
    $items = [];
    $cursor = null;
    for ($page = 0; $page < 50; $page++) {
        $data = bc_api($key, $path, $query + ($cursor !== null ? ['cursor' => $cursor] : []));
        foreach ((array)($data['items'] ?? []) as $it) {
            if (is_array($it)) {
                $items[] = $it;
            }
        }
        $cursor = $data['next_cursor'] ?? null;
        if (empty($data['has_more']) || !is_string($cursor) || $cursor === '') {
            break;
        }
    }
    return $items;
}

/** Looki's ISO time as this server's time (the clock ops_jobs.started_at uses). */
function bc_time(?string $iso): ?string
{
    if ($iso === null || $iso === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

/** "Al Barsha South Fourth, Dubai" from Looki's location JSON. */
function bc_place($location): ?string
{
    $l = is_string($location) ? json_decode($location, true) : $location;
    if (!is_array($l)) {
        return null;
    }
    $parts = array_values(array_unique(array_filter([trim((string)($l['subLocality'] ?? '')), trim((string)($l['administrativeArea'] ?? ''))])));
    if (!$parts && !empty($l['street'])) {
        $parts = [trim((string)$l['street'])];
    }
    return $parts ? mb_substr(implode(', ', $parts), 0, 255) : null;
}

/** Looki's description as a short work note: its first sentence, without "The user is". */
function bc_note(?string $description): ?string
{
    $s = trim((string)$description);
    if ($s === '') {
        return null;
    }
    return bc_tidy(trim(bc_sentences($s)[0] ?? $s));
}

/** One of Looki's sentences as a work note: "The user picks up two cans." -> "Picks up two cans". */
function bc_tidy(string $s): string
{
    $s = preg_replace('/^(later|then|next|afterwards|meanwhile),?\s+/i', '', trim($s));
    $s = preg_replace('/^(the user|the wearer|user)\s+(is|was)\s+/i', '', $s);
    $s = preg_replace('/^(the user|the wearer)\s+/i', '', $s);
    $s = preg_replace('/\b(the user|the wearer)\b/i', 'staff', $s);
    $s = rtrim($s, '. ');
    return mb_substr(mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1), 0, 500);
}

function bc_sentences(string $text): array
{
    return array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+/', $text) ?: [])));
}

/** Only a link on Looki's own https file host is ever handed to a browser. */
function bc_is_looki_link(string $url): bool
{
    return parse_url($url, PHP_URL_SCHEME) === 'https'
        && (bool)preg_match('/(^|\.)looki\.ai$/', strtolower((string)parse_url($url, PHP_URL_HOST)));
}

/**
 * A fresh Looki link for one file (its thumbnail with $thumb), or null when
 * Looki no longer has it. One Looki call fetches every file of the moment, and
 * the links are reused for 8 minutes, so a grid of thumbnails costs one call.
 */
function bc_media_url(PDO $conn, array $file, bool $thumb = false): ?string
{
    $st = $conn->prepare('SELECT m.id, m.looki_id, d.api_key FROM ops_bodycam_moments m JOIN ops_bodycam_devices d ON d.id = m.device_id WHERE m.id = ?');
    $st->execute([(int)$file['moment_id']]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        return null;
    }
    $cache = bc_dir('links') . '/' . (int)$m['id'] . '.json';
    $links = is_file($cache) && filemtime($cache) > time() - BC_LINK_CACHE_SECONDS ? json_decode((string)file_get_contents($cache), true) : null;
    if (!is_array($links) || !isset($links[$file['looki_file_id']])) {
        $links = [];
        foreach (bc_api_all((string)$m['api_key'], '/moments/' . rawurlencode((string)$m['looki_id']) . '/files', ['limit' => 100]) as $f) {
            $links[(string)($f['id'] ?? '')] = ['file' => (string)($f['file']['temporary_url'] ?? ''), 'thumb' => (string)($f['thumbnail']['temporary_url'] ?? '')];
        }
        file_put_contents($cache, json_encode($links), LOCK_EX);
    }
    $url = (string)($links[$file['looki_file_id']][$thumb ? 'thumb' : 'file'] ?? '');
    return $url !== '' && bc_is_looki_link($url) ? $url : null;
}

// ---------------------------------------------------------------------------
// Sync
// ---------------------------------------------------------------------------

/**
 * One device: its moments of the last two days (seven on a first sync), every
 * file not listed yet, links to jobs, then the voice and store camera events.
 */
function bc_sync_device(PDO $conn, array $d): string
{
    $id = (int)$d['id'];
    $userId = (int)$d['user_id'];
    $days = empty($d['last_sync_at']) ? BC_FIRST_SYNC_DAYS : 2;
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $to = date('Y-m-d');
    $newFiles = 0;
    try {
        $moments = bc_api_all((string)$d['api_key'], '/moments', ['start_date' => $from, 'end_date' => $to, 'limit' => 100, 'sort' => 'asc']);
        foreach ($moments as $m) {
            $momentId = bc_save_moment($conn, $id, $userId, $m);
            if ($momentId === null) {
                continue;
            }
            $files = bc_api_all((string)$d['api_key'], '/moments/' . rawurlencode((string)$m['id']) . '/files', ['limit' => 100]);
            foreach ($files as $f) {
                $newFiles += bc_save_file($conn, $id, $userId, $momentId, $f) ? 1 : 0;
            }
            bc_voice_events($conn, $userId, $momentId, $m);
        }
        $commands = bc_command_events($conn, (string)$d['api_key'], $userId, $from);
        bc_link_open_files($conn, $userId, $from);
        bc_camera_events($conn, $userId, $from);
        $note = count($moments) . ' moment' . (count($moments) === 1 ? '' : 's') . ', ' . $newFiles . ' new file' . ($newFiles === 1 ? '' : 's')
            . ', ' . $commands . ' voice command' . ($commands === 1 ? '' : 's');
        $ok = 1;
    } catch (BcError $e) {
        $note = $e->getMessage();
        $ok = 0;
    }
    $conn->prepare('UPDATE ops_bodycam_devices SET last_sync_at = ?, last_sync_note = ?, last_sync_ok = ? WHERE id = ?')
        ->execute([bc_now(), mb_substr($note, 0, 255), $ok, $id]);
    return $note;
}

/** Add or update one moment; answers its row id. */
function bc_save_moment(PDO $conn, int $deviceId, int $userId, array $m): ?int
{
    $looki = (string)($m['id'] ?? '');
    $start = bc_time($m['start_time'] ?? null);
    if ($looki === '' || $start === null) {
        return null;
    }
    $place = bc_place($m['cover_file']['location'] ?? null);
    // The whole description, each sentence as a work note; the audio summary is not kept.
    $sentences = array_filter(array_map('bc_tidy', bc_sentences(trim((string)($m['description'] ?? '')))));
    $description = $sentences ? implode('. ', $sentences) . '.' : null;
    // Looki's own keywords for what is in the recording ("office desk, monitor, delivery box").
    $keywords = mb_substr(trim((string)($m['search_context'] ?? '')), 0, 500) ?: null;
    $conn->prepare('INSERT INTO ops_bodycam_moments (device_id, user_id, looki_id, title, note, description, keywords, start_time, end_time, place, synced_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE title = COALESCE(VALUES(title), title), note = COALESCE(VALUES(note), note), description = COALESCE(VALUES(description), description),
            keywords = COALESCE(VALUES(keywords), keywords),
            start_time = VALUES(start_time), end_time = COALESCE(VALUES(end_time), end_time), place = COALESCE(VALUES(place), place), synced_at = VALUES(synced_at)')
        ->execute([$deviceId, $userId, $looki, mb_substr(trim((string)($m['title'] ?? '')), 0, 255) ?: null,
            bc_note($m['description'] ?? null), $description, $keywords, $start, bc_time($m['end_time'] ?? null), $place, bc_now()]);
    $st = $conn->prepare('SELECT id FROM ops_bodycam_moments WHERE device_id = ? AND looki_id = ?');
    $st->execute([$deviceId, $looki]);
    return (int)$st->fetchColumn() ?: null;
}

/** Note one Looki file (not its contents) unless it is listed already. Answers true when it is new. */
function bc_save_file(PDO $conn, int $deviceId, int $userId, int $momentId, array $f): bool
{
    $looki = (string)($f['id'] ?? '');
    $type = strtolower((string)($f['file']['media_type'] ?? ''));
    $at = bc_time($f['created_at'] ?? null);
    if ($looki === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $looki) || $at === null || !in_array($type, ['image', 'video', 'audio'], true)) {
        return false;
    }
    $meta = (array)($f['file']['metadata'] ?? []);
    // A recording whose job the office chose: its later files follow that choice, not the time.
    $st = $conn->prepare('SELECT job_chosen, office_job_id FROM ops_bodycam_moments WHERE id = ?');
    $st->execute([$momentId]);
    $chosen = $st->fetch(PDO::FETCH_ASSOC) ?: ['job_chosen' => 0, 'office_job_id' => null];
    if ($chosen['job_chosen']) {
        $jobId = $chosen['office_job_id'] !== null ? (int)$chosen['office_job_id'] : null;
        $how = 'office';
    } else {
        $jobId = bc_job_at($conn, $userId, $at);
        $how = $jobId ? 'auto' : null;
    }
    $st = $conn->prepare('INSERT IGNORE INTO ops_bodycam_files (device_id, user_id, moment_id, looki_file_id, media_type, recorded_at, duration_ms, width, height,
            place, job_id, linked_how, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$deviceId, $userId, $momentId, $looki, $type, $at,
        isset($meta['duration_ms']) ? (int)$meta['duration_ms'] : null,
        isset($meta['width']) ? (int)$meta['width'] : null, isset($meta['height']) ? (int)$meta['height'] : null,
        bc_place($f['location'] ?? null), $jobId, $how, bc_now()]);
    return $st->rowCount() > 0;
}

/** The job this person had started at $at (10 minutes either side of Start and Finish), or null. */
function bc_job_at(PDO $conn, int $userId, string $at): ?int
{
    $grace = BC_LINK_GRACE_MINUTES;
    $st = $conn->prepare("SELECT id FROM ops_jobs
        WHERE assigned_to = ? AND status <> 'cancelled' AND started_at IS NOT NULL
          AND started_at <= DATE_ADD(?, INTERVAL $grace MINUTE)
          AND (finished_at >= DATE_SUB(?, INTERVAL $grace MINUTE) OR (finished_at IS NULL AND status = 'in_progress'))
        ORDER BY started_at DESC LIMIT 1");
    $st->execute([$userId, $at, $at]);
    return (int)$st->fetchColumn() ?: null;
}

/** Files not linked yet get another try: the job may have been started or finished since. */
function bc_link_open_files($conn, int $userId, string $fromDay): void
{
    $st = $conn->prepare('SELECT id, recorded_at FROM ops_bodycam_files WHERE user_id = ? AND job_id IS NULL AND linked_how IS NULL AND recorded_at >= ?');
    $st->execute([$userId, $fromDay . ' 00:00:00']);
    $up = $conn->prepare("UPDATE ops_bodycam_files SET job_id = ?, linked_how = 'auto' WHERE id = ? AND job_id IS NULL");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $f) {
        $job = bc_job_at($conn, $userId, (string)$f['recorded_at']);
        if ($job) {
            $up->execute([$job, (int)$f['id']]);
        }
    }
}

/** Store item names, lower case, for the voice events: Store items and Store camera items. */
function bc_item_words(PDO $conn): array
{
    static $words = null;
    if ($words === null) {
        $words = [];
        foreach (['SELECT name FROM st_items WHERE active = 1', 'SELECT name FROM st_cam_items WHERE active = 1'] as $sql) {
            try {
                foreach ($conn->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [] as $n) {
                    $n = mb_strtolower(trim((string)$n));
                    if (mb_strlen($n) >= 3) {
                        $words[$n] = true;
                    }
                }
            } catch (PDOException $e) {
                // That table is not set up; the other words still work.
            }
        }
        $words = array_keys($words);
    }
    return $words;
}

/** Does $sentence contain $word as a whole word (or its plural)? */
function bc_has_word(string $sentence, string $word): bool
{
    return (bool)preg_match('/\b' . str_replace('\ ', '\s+', preg_quote($word, '/')) . '(s|es)?\b/iu', $sentence);
}

/**
 * The sentences of a moment's title, description and audio summary that name a
 * store item, or a take/return word together with the store. Only these are kept.
 */
function bc_voice_sentences(PDO $conn, array $m): array
{
    // A title has no full stop; without one it would run into the description's first sentence.
    $parts = array_filter(array_map(static fn($t): string => rtrim(trim((string)$t), '.!? '),
        [$m['title'] ?? '', $m['description'] ?? '', $m['audio_summary'] ?? '']));
    $text = $parts ? implode('. ', $parts) . '.' : '';
    $found = [];
    foreach (bc_sentences($text) as $s) {
        $item = false;
        foreach (bc_item_words($conn) as $w) {
            if (bc_has_word($s, $w)) {
                $item = true;
                break;
            }
        }
        $action = false;
        foreach (BC_ACTION_WORDS as $w) {
            if (preg_match('/\b' . $w . '\b/i', $s)) {
                $action = true;
                break;
            }
        }
        $store = false;
        foreach (BC_STORE_WORDS as $w) {
            if (bc_has_word($s, $w)) {
                $store = true;
                break;
            }
        }
        // "taking the elevator to the storage room" is going there, not taking something from it.
        if ($action && !$item && preg_match('/\b(take|takes|took|taking|taken)\s+(the\s+|a\s+|an\s+)?(elevator|lift|stairs|escalator|break|photo|picture|call|seat|look|walk|route|turn)\b/i', $s)) {
            $action = false;
        }
        if ($item || ($action && $store)) {
            $found[] = mb_substr($s, 0, 500);
        }
    }
    return $found;
}

function bc_voice_events(PDO $conn, int $userId, int $momentId, array $m): void
{
    $at = bc_time($m['start_time'] ?? null);
    if ($at === null) {
        return;
    }
    // Heard = from Looki's audio summary; Seen = from what Looki saw (the title or description).
    $audio = mb_strtolower((string)($m['audio_summary'] ?? ''));
    $ins = $conn->prepare("INSERT IGNORE INTO ops_bodycam_events (user_id, kind, label, at, text, moment_id, ref, created_at) VALUES (?, 'voice', ?, ?, ?, ?, ?, ?)");
    foreach (bc_voice_sentences($conn, $m) as $s) {
        $heard = $audio !== '' && str_contains($audio, mb_strtolower(rtrim($s, '.!? ')));
        $ins->execute([$userId, $heard ? 'Heard' : 'Seen', $at, $heard ? $s : bc_tidy($s), $momentId, 'v' . $momentId . '-' . substr(md5($s), 0, 12), bc_now()]);
    }
}

/**
 * A voice command: what the wearer said to the L1's assistant (hold the
 * touchpad, speak, let go). Answers [label, rest] or null when it is not one.
 * Checked in this order, so "end break" is Back, not Finish work.
 */
function bc_voice_command(string $said): ?array
{
    $s = trim(preg_replace('/^\s*((hey|hi|ok|okay)[,\s]+)?(looki[,\s]+)?/i', '', $said));
    $s = rtrim($s, " .!?\t\n");
    $work = '(\s+(the\s+)?(work|job|cleaning|maintenance|task|shift))?(\s+(at|in|on|for))?';
    $patterns = [
        'Back' => '/^(back from (my |the )?break|end (of )?(my |the )?break|break (is )?over|i\'?m back|back|resum(e|ed|ing))\b\s*(.*)$/i',
        'Break' => '/^((start(ing)? |taking |going on |on )?(a |my )?break)\b\s*(.*)$/i',
        'Taking' => '/^((i am |i\'?m )?(taking|took|take|collecting|collected|picking up|picked up))\b\s*(.*)$/i',
        'Problem' => '/^(problem|issue)\b[\s,:\-]*(.*)$/i',
        'Finish work' => '/^(finish(ed|ing)?|done|complete(d)?|end(ed|ing)?|stop(ped)?)\b' . $work . '\s*(.*)$/i',
        'Start work' => '/^(start(ed|ing)?|begin(ning)?|began)\b' . $work . '\s*(.*)$/i',
    ];
    foreach ($patterns as $label => $re) {
        if (preg_match($re, $s, $m)) {
            $rest = trim((string)end($m), " ,.-:");
            return [$label, $label === 'Taking' ? $s : $rest];
        }
    }
    return null;
}

/**
 * The wearer's voice commands since $fromDay, from Looki's assistant chat
 * history. Only lines that are a command are kept; the rest of the chat is not.
 * "Taking …" becomes a voice event, checked against store entries.
 */
function bc_command_events(PDO $conn, #[SensitiveParameter] string $key, int $userId, string $fromDay): int
{
    $since = (new DateTimeImmutable($fromDay . ' 00:00:00'))->format(DATE_ATOM);
    $ins = $conn->prepare('INSERT IGNORE INTO ops_bodycam_events (user_id, kind, label, at, text, ref, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $n = 0;
    foreach (bc_api_all($key, '/chat/history', ['start_time' => $since, 'limit' => 100]) as $msg) {
        $at = bc_time($msg['created_at'] ?? null);
        $said = trim((string)($msg['text'] ?? ''));
        if (($msg['role'] ?? '') !== 'user' || $at === null || $said === '' || !($cmd = bc_voice_command($said))) {
            continue;
        }
        [$label, $rest] = $cmd;
        $ins->execute([$userId, $label === 'Taking' ? 'voice' : 'command', $label === 'Taking' ? null : $label, $at,
            mb_substr($label === 'Taking' ? $rest : $said, 0, 500), 'vc-' . substr(md5($at . '|' . $said), 0, 16), bc_now()]);
        $n += $ins->rowCount();
    }
    return $n;
}

/** Store camera "missing" changes seen while this person was recording (a file within 10 minutes). */
function bc_camera_events(PDO $conn, int $userId, string $fromDay): void
{
    try {
        $st = $conn->prepare("SELECT c.id, c.seen_at, c.was_count, c.now_count, i.name
            FROM st_cam_changes c LEFT JOIN st_cam_items i ON i.id = c.item_id
            WHERE c.kind = 'less' AND c.seen_at >= ?");
        $st->execute([$fromDay . ' 00:00:00']);
        $changes = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return; // No Store camera on this server.
    }
    $mins = BC_CAMERA_MINUTES;
    $near = $conn->prepare("SELECT id FROM ops_bodycam_files WHERE user_id = ?
        AND recorded_at BETWEEN DATE_SUB(?, INTERVAL $mins MINUTE) AND DATE_ADD(?, INTERVAL $mins MINUTE)
        ORDER BY ABS(TIMESTAMPDIFF(SECOND, recorded_at, ?)) LIMIT 1");
    $ins = $conn->prepare("INSERT IGNORE INTO ops_bodycam_events (user_id, kind, at, text, cam_change_id, file_id, ref, created_at) VALUES (?, 'store_camera', ?, ?, ?, ?, ?, ?)");
    foreach ($changes as $c) {
        $near->execute([$userId, $c['seen_at'], $c['seen_at'], $c['seen_at']]);
        $fileId = (int)$near->fetchColumn();
        if (!$fileId) {
            continue;
        }
        $n = max(1, (int)$c['was_count'] - (int)$c['now_count']);
        $what = $c['name'] !== null ? (string)$c['name'] : 'an item';
        $ins->execute([$userId, $c['seen_at'], 'Store camera saw ' . $n . ' × ' . $what . ' taken from the shelf',
            (int)$c['id'], $fileId, 'c' . (int)$c['id'], bc_now()]);
    }
}

/** Delete the report rows older than the Keep setting, and Looki links past their time. */
function bc_cleanup(PDO $conn): void
{
    $before = date('Y-m-d H:i:s', strtotime('-' . bc_settings()['keep_days'] . ' days'));
    $conn->prepare('DELETE FROM ops_bodycam_files WHERE recorded_at < ?')->execute([$before]);
    foreach (glob(bc_dir('links') . '/*.json') ?: [] as $f) {
        if (filemtime($f) < time() - BC_LINK_CACHE_SECONDS) {
            @unlink($f);
        }
    }
    $conn->prepare('DELETE FROM ops_bodycam_moments WHERE COALESCE(end_time, start_time) < ?')->execute([$before]);
    $conn->prepare('DELETE FROM ops_bodycam_events WHERE at < ?')->execute([$before]);
}

// ---------------------------------------------------------------------------
// Files
// ---------------------------------------------------------------------------

function bc_file_row(PDO $conn, int $id): ?array
{
    $st = $conn->prepare('SELECT * FROM ops_bodycam_files WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function bc_delete_file(PDO $conn, int $id): void
{
    // Only saifsys's record goes; the photo or video stays in Looki's cloud.
    $conn->prepare('UPDATE ops_bodycam_events SET file_id = NULL WHERE file_id = ?')->execute([$id]);
    $conn->prepare('DELETE FROM ops_bodycam_files WHERE id = ?')->execute([$id]);
}

/**
 * The office's note and job for one recording (a moment) on the daily report.
 * $in: note, job ('' = linked by time, 'none' = no job, or a job id of the wearer).
 * A chosen job is set on all the recording's files, and on files that arrive later.
 */
function bc_edit_moment(PDO $conn, int $momentId, array $in, ?int $byUserId): void
{
    $st = $conn->prepare('SELECT * FROM ops_bodycam_moments WHERE id = ?');
    $st->execute([$momentId]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        throw new BcError('That recording is gone.');
    }
    $note = trim((string)($in['note'] ?? ''));
    if (mb_strlen($note) > 500) {
        throw new BcError('Keep the note to 500 letters.');
    }
    $job = (string)($in['job'] ?? '');
    $jobId = null;
    if ($job !== '' && $job !== 'none') {
        $st = $conn->prepare("SELECT id FROM ops_jobs WHERE id = ? AND assigned_to = ? AND status <> 'cancelled'");
        $st->execute([(int)$job, (int)$m['user_id']]);
        $jobId = (int)$st->fetchColumn() ?: null;
        if ($jobId === null) {
            throw new BcError('Choose one of this person\'s jobs.');
        }
    }
    $chosen = $job === '' ? 0 : 1;
    // Its own transaction, unless the caller already has one.
    $own = !$conn->inTransaction();
    if ($own) {
        $conn->beginTransaction();
    }
    try {
        $conn->prepare('UPDATE ops_bodycam_moments SET office_note = ?, job_chosen = ?, office_job_id = ?, edited_by = ?, edited_at = ? WHERE id = ?')
            ->execute([$note !== '' ? $note : null, $chosen, $jobId, $byUserId, bc_now(), $momentId]);
        if ($chosen) {
            $conn->prepare("UPDATE ops_bodycam_files SET job_id = ?, linked_how = 'office', linked_by = ? WHERE moment_id = ?")
                ->execute([$jobId, $byUserId, $momentId]);
        } elseif ((int)$m['job_chosen'] === 1) {
            // Back to linking by time: forget the office's choice and work it out again.
            $conn->prepare('UPDATE ops_bodycam_files SET job_id = NULL, linked_how = NULL, linked_by = NULL WHERE moment_id = ?')->execute([$momentId]);
            bc_link_open_files($conn, (int)$m['user_id'], substr((string)$m['start_time'], 0, 10));
        }
        if ($own) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($own) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/** Change an event the office added. $in as bc_add_event. */
function bc_edit_event(PDO $conn, int $eventId, int $userId, array $in): void
{
    $st = $conn->prepare("SELECT id FROM ops_bodycam_events WHERE id = ? AND user_id = ? AND kind = 'manual'");
    $st->execute([$eventId, $userId]);
    if (!$st->fetchColumn()) {
        throw new BcError('Only events added here can be changed.');
    }
    $e = bc_event_fields($in);
    $conn->prepare('UPDATE ops_bodycam_events SET label = ?, at = ?, end_at = ?, text = ? WHERE id = ?')
        ->execute([$e['label'], $e['at'], $e['end_at'], $e['text'], $eventId]);
}

/** Link a file to a job of its wearer by hand. */
function bc_link_file(PDO $conn, int $fileId, int $jobId, ?int $byUserId): void
{
    $f = bc_file_row($conn, $fileId);
    if (!$f) {
        throw new BcError('That recording is gone.');
    }
    $st = $conn->prepare('SELECT id FROM ops_jobs WHERE id = ? AND assigned_to = ?');
    $st->execute([$jobId, (int)$f['user_id']]);
    if (!$st->fetchColumn()) {
        throw new BcError('Choose one of this person\'s jobs.');
    }
    $conn->prepare("UPDATE ops_bodycam_files SET job_id = ?, linked_how = 'office', linked_by = ? WHERE id = ?")
        ->execute([$jobId, $byUserId, $fileId]);
}

/**
 * Files for the Recordings tab. $f: user_id, day (Y-m-d), linked ('', 'yes', 'no').
 */
function bc_files(PDO $conn, array $f): array
{
    $where = ['f.recorded_at BETWEEN ? AND ?'];
    $args = [$f['day'] . ' 00:00:00', $f['day'] . ' 23:59:59'];
    if (!empty($f['user_id'])) {
        $where[] = 'f.user_id = ?';
        $args[] = (int)$f['user_id'];
    }
    if (($f['linked'] ?? '') === 'yes') {
        $where[] = 'f.job_id IS NOT NULL';
    } elseif (($f['linked'] ?? '') === 'no') {
        $where[] = 'f.job_id IS NULL';
    }
    $st = $conn->prepare('SELECT f.*, COALESCE(NULLIF(u.fullname, \'\'), u.username) AS person, j.title AS job_title, j.location AS job_location
        FROM ops_bodycam_files f
        LEFT JOIN user u ON u.id = f.user_id
        LEFT JOIN ops_jobs j ON j.id = f.job_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY person, f.recorded_at DESC LIMIT 500');
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function bc_unlinked_count(PDO $conn, string $day): int
{
    $st = $conn->prepare('SELECT COUNT(*) FROM ops_bodycam_files WHERE job_id IS NULL AND recorded_at BETWEEN ? AND ?');
    $st->execute([$day . ' 00:00:00', $day . ' 23:59:59']);
    return (int)$st->fetchColumn();
}

/** This person's jobs on that day, for Link to job. */
function bc_jobs_for(PDO $conn, int $userId, string $day): array
{
    $st = $conn->prepare("SELECT id, title, location, started_at, finished_at FROM ops_jobs
        WHERE assigned_to = ? AND status <> 'cancelled' AND (scheduled_date = ? OR DATE(started_at) = ?)
        ORDER BY COALESCE(started_at, scheduled_date), id");
    $st->execute([$userId, $day, $day]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** The people who have an L1, for the filters. */
function bc_people(PDO $conn): array
{
    return $conn->query("SELECT DISTINCT d.user_id AS id, COALESCE(NULLIF(u.fullname, ''), u.username) AS name
        FROM ops_bodycam_devices d LEFT JOIN user u ON u.id = d.user_id ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// ---------------------------------------------------------------------------
// Daily report
// ---------------------------------------------------------------------------

/** Store "out" entries given to this person on that day, with their items. */
function bc_store_entries(PDO $conn, int $userId, string $day): array
{
    try {
        $st = $conn->prepare("SELECT m.id, m.created_at, m.for_note, s.name AS store
            FROM st_moves m
            JOIN employees e ON e.id = m.given_to_employee_id AND e.user_id = ?
            LEFT JOIN st_stores s ON s.id = m.store_id
            WHERE m.kind = 'out' AND m.is_active = 1 AND m.created_at BETWEEN ? AND ?
            ORDER BY m.created_at");
        $st->execute([$userId, $day . ' 00:00:00', $day . ' 23:59:59']);
        $moves = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
    if (!$moves) {
        return [];
    }
    $in = implode(',', array_fill(0, count($moves), '?'));
    $st = $conn->prepare("SELECT l.move_id, l.qty, i.name, i.unit FROM st_move_lines l JOIN st_items i ON i.id = l.item_id WHERE l.move_id IN ($in) ORDER BY l.id");
    $st->execute(array_map(static fn(array $m): int => (int)$m['id'], $moves));
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $l) {
        $lines[(int)$l['move_id']][] = ops_qty($l['qty']) . ' × ' . $l['name'];
    }
    foreach ($moves as &$m) {
        $m['items'] = $lines[(int)$m['id']] ?? [];
    }
    return $moves;
}

/** What an office-added event can be. */
const BC_EVENT_LABELS = ['Note', 'Check in', 'Check out', 'Break', 'Store', 'Location', 'Job', 'Problem', 'Other'];

/**
 * Add an event to someone's day by hand. $in: day, time, end_time (optional),
 * label, text.
 */
function bc_add_event(PDO $conn, int $userId, array $in, ?int $byUserId): void
{
    if ($userId <= 0) {
        throw new BcError('Choose the employee first.');
    }
    $e = bc_event_fields($in);
    $conn->prepare("INSERT INTO ops_bodycam_events (user_id, kind, label, at, end_at, text, ref, created_by, created_at) VALUES (?, 'manual', ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$userId, $e['label'], $e['at'], $e['end_at'], $e['text'], 'm' . bin2hex(random_bytes(8)), $byUserId, bc_now()]);
}

/** An office event's fields, checked: label, at, end_at, text. */
function bc_event_fields(array $in): array
{
    $day = (string)($in['day'] ?? '');
    $time = (string)($in['time'] ?? '');
    $endTime = trim((string)($in['end_time'] ?? ''));
    $label = (string)($in['label'] ?? '');
    $text = trim((string)($in['text'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        throw new BcError('Give the event a time.');
    }
    if ($endTime !== '' && (!preg_match('/^\d{2}:\d{2}$/', $endTime) || $endTime <= $time)) {
        throw new BcError('The end time must be after the start time.');
    }
    if (!in_array($label, BC_EVENT_LABELS, true)) {
        throw new BcError('Choose what kind of event it is.');
    }
    if ($text === '' || mb_strlen($text) > 500) {
        throw new BcError('Say what happened, in up to 500 letters.');
    }
    return ['label' => $label, 'at' => $day . ' ' . $time . ':00', 'end_at' => $endTime !== '' ? $day . ' ' . $endTime . ':00' : null, 'text' => $text];
}

/**
 * Delete one report row by its ref. An office-added event is deleted; any other
 * row (attendance, jobs, trips, store, camera, voice, a recording) is hidden
 * from the report only, so nothing changes where it came from.
 */
function bc_delete_event(PDO $conn, int $userId, string $ref, ?int $byUserId): void
{
    if ($userId <= 0 || !preg_match('/^[a-z0-9-]{2,64}$/', $ref)) {
        throw new BcError('That event is gone.');
    }
    if (preg_match('/^e(\d+)$/', $ref, $m)) {
        $st = $conn->prepare("DELETE FROM ops_bodycam_events WHERE id = ? AND user_id = ? AND kind = 'manual'");
        $st->execute([(int)$m[1], $userId]);
        if ($st->rowCount() > 0) {
            return;
        }
    }
    $conn->prepare('INSERT IGNORE INTO ops_bodycam_hidden (user_id, ref, hidden_by, hidden_at) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $ref, $byUserId, bc_now()]);
}

/** Set (or, with an empty note, remove) the office's note on a report row. */
function bc_note_row(PDO $conn, int $userId, string $ref, string $note, ?int $byUserId): void
{
    $note = trim($note);
    if ($userId <= 0 || !preg_match('/^[a-z0-9-]{2,64}$/', $ref)) {
        throw new BcError('That event is gone.');
    }
    if (mb_strlen($note) > 500) {
        throw new BcError('Keep the note to 500 letters.');
    }
    if ($note === '') {
        $conn->prepare('DELETE FROM ops_bodycam_row_notes WHERE user_id = ? AND ref = ?')->execute([$userId, $ref]);
        return;
    }
    $conn->prepare('INSERT INTO ops_bodycam_row_notes (user_id, ref, note, noted_by, noted_at) VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE note = VALUES(note), noted_by = VALUES(noted_by), noted_at = VALUES(noted_at)')
        ->execute([$userId, $ref, $note, $byUserId, bc_now()]);
}

/** The refs deleted from this person's report. */
function bc_hidden(PDO $conn, int $userId): array
{
    $st = $conn->prepare('SELECT ref FROM ops_bodycam_hidden WHERE user_id = ?');
    $st->execute([$userId]);
    return array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN) ?: [], true);
}

/** "1 h 5 min", "12 min". */
function bc_minutes(int $seconds): string
{
    $min = max(1, (int)round($seconds / 60));
    return $min >= 60 ? intdiv($min, 60) . ' h' . ($min % 60 ? ' ' . ($min % 60) . ' min' : '') : $min . ' min';
}

/**
 * The day's rows from the other modules, read as the report is drawn:
 * HR attendance (check in, check out, break), Operations jobs (started,
 * finished), job pauses, and Fleet trips for a driver.
 */
function bc_other_rows(PDO $conn, int $userId, string $day): array
{
    $rows = [];
    $from = $day . ' 00:00:00';
    $to = $day . ' 23:59:59';
    $at = static fn(?string $t): ?string => $t ? $day . ' ' . substr($t, 0, 8) : null;

    try {
        $st = $conn->prepare("SELECT a.id, a.check_in, a.check_out, a.break_start, a.break_end, a.source
            FROM attendance a JOIN employees e ON e.id = a.employee_id AND e.user_id = ? WHERE a.work_date = ?");
        $st->execute([$userId, $day]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $a) {
            $how = $a['source'] === 'camera' ? ' (attendance camera)' : ($a['source'] === 'self' ? ' (staff app)' : '');
            if ($a['check_in']) {
                $rows[] = ['type' => 'check_in', 'ref' => 'att-in-' . $a['id'], 'at' => $at($a['check_in']), 'text' => 'Checked in' . $how];
            }
            if ($a['break_start']) {
                $rows[] = ['type' => 'break', 'ref' => 'att-br-' . $a['id'], 'at' => $at($a['break_start']), 'end' => $at($a['break_end']),
                    'text' => 'Break' . ($a['break_end'] ? ' (' . bc_minutes(strtotime($at($a['break_end'])) - strtotime($at($a['break_start']))) . ')' : ', not ended')];
            }
            if ($a['check_out']) {
                $rows[] = ['type' => 'check_out', 'ref' => 'att-out-' . $a['id'], 'at' => $at($a['check_out']), 'text' => 'Checked out' . $how];
            }
        }
    } catch (PDOException $e) {
        // No HR attendance on this server.
    }

    $st = $conn->prepare("SELECT id, title, location, started_at, finished_at FROM ops_jobs
        WHERE assigned_to = ? AND status <> 'cancelled' AND (started_at BETWEEN ? AND ? OR finished_at BETWEEN ? AND ?)");
    $st->execute([$userId, $from, $to, $from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $j) {
        $name = '#' . $j['id'] . ' ' . $j['title'] . ($j['location'] ? ' · ' . $j['location'] : '');
        if ($j['started_at'] >= $from && $j['started_at'] <= $to) {
            $rows[] = ['type' => 'job_start', 'ref' => 'job-s-' . $j['id'], 'at' => $j['started_at'], 'text' => 'Started job ' . $name, 'job_id' => (int)$j['id']];
        }
        if ($j['finished_at'] && $j['finished_at'] >= $from && $j['finished_at'] <= $to) {
            $rows[] = ['type' => 'job_finish', 'ref' => 'job-f-' . $j['id'], 'at' => $j['finished_at'], 'job_id' => (int)$j['id'],
                'text' => 'Finished job ' . $name . ($j['started_at'] ? ' (' . bc_minutes(strtotime($j['finished_at']) - strtotime($j['started_at'])) . ')' : '')];
        }
    }

    try {
        $st = $conn->prepare("SELECT p.id, p.job_id, p.reason, p.paused_at, p.resumed_at, j.title FROM ops_job_pauses p JOIN ops_jobs j ON j.id = p.job_id
            WHERE p.user_id = ? AND p.paused_at BETWEEN ? AND ?");
        $st->execute([$userId, $from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            $rows[] = ['type' => 'pause', 'ref' => 'pause-' . $p['id'], 'at' => $p['paused_at'], 'end' => $p['resumed_at'], 'job_id' => (int)$p['job_id'],
                'text' => 'Paused job #' . $p['job_id'] . ' ' . $p['title'] . ': ' . (ops_pause_reasons()[$p['reason']] ?? 'Other')
                    . ($p['resumed_at'] ? ' (' . bc_minutes(strtotime($p['resumed_at']) - strtotime($p['paused_at'])) . ')' : ', not resumed')];
        }
    } catch (PDOException $e) {
        // No job pauses on this server.
    }

    try {
        $st = $conn->prepare("SELECT t.id, t.route_name, t.started_at, t.ended_at, t.distance_m, t.last_lat, t.last_lng, v.plate_no AS plate,
                (SELECT CONCAT(p.lat, ',', p.lng) FROM fleet_trip_points p WHERE p.trip_id = t.id ORDER BY p.recorded_at LIMIT 1) AS first_point
            FROM fleet_trips t LEFT JOIN fleet_vehicles v ON v.id = t.vehicle_id
            WHERE t.driver_user_id = ? AND t.started_at BETWEEN ? AND ?");
        $st->execute([$userId, $from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $t) {
            $rows[] = ['type' => 'trip', 'ref' => 'trip-' . $t['id'], 'at' => $t['started_at'], 'end' => $t['ended_at'],
                'text' => 'Drove' . ($t['plate'] ? ' ' . $t['plate'] : '') . ($t['route_name'] ? ', ' . $t['route_name'] : '')
                    . ($t['distance_m'] ? ', ' . number_format($t['distance_m'] / 1000, 1) . ' km' : ''),
                'map_from' => $t['first_point'] ?: null,
                'map_to' => $t['last_lat'] !== null ? $t['last_lat'] . ',' . $t['last_lng'] : null];
        }
    } catch (PDOException $e) {
        // No Fleet on this server.
    }
    return $rows;
}

/**
 * The daily report for one person: a timeline of recordings (moment) and
 * events, and the numbers at the top. Every row has a ref (for Delete), type,
 * at, end and text; recordings add place, note, jobs and snaps; store camera and
 * voice add ok (a store entry within an hour) and are an alert without one.
 */
function bc_report(PDO $conn, int $userId, string $day): array
{
    $from = $day . ' 00:00:00';
    $to = $day . ' 23:59:59';
    $rows = [];

    $st = $conn->prepare('SELECT * FROM ops_bodycam_moments WHERE user_id = ? AND start_time BETWEEN ? AND ? ORDER BY start_time');
    $st->execute([$userId, $from, $to]);
    $moments = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $jobsByMoment = [];
    $filesByMoment = [];
    if ($moments) {
        $in = implode(',', array_fill(0, count($moments), '?'));
        $st = $conn->prepare("SELECT f.id, f.moment_id, f.media_type, f.recorded_at, f.job_id, j.title, j.location
            FROM ops_bodycam_files f LEFT JOIN ops_jobs j ON j.id = f.job_id
            WHERE f.moment_id IN ($in) AND f.media_type <> 'audio' ORDER BY f.recorded_at");
        $st->execute(array_map(static fn(array $m): int => (int)$m['id'], $moments));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $filesByMoment[(int)$r['moment_id']][] = ['id' => (int)$r['id'], 'video' => $r['media_type'] === 'video', 'at' => $r['recorded_at']];
            if ($r['job_id'] !== null) {
                $jobsByMoment[(int)$r['moment_id']][(int)$r['job_id']] = trim($r['title'] . ($r['location'] ? ' · ' . $r['location'] : ''));
            }
        }
    }
    foreach ($moments as $m) {
        $files = $filesByMoment[(int)$m['id']] ?? [];
        $rows[] = ['type' => 'moment', 'ref' => 'mo' . $m['id'], 'at' => $m['start_time'], 'end' => $m['end_time'], 'place' => $m['place'],
            'text' => (string)($m['title'] ?? 'Recording'), 'note' => $m['note'], 'jobs' => $jobsByMoment[(int)$m['id']] ?? [],
            'files' => count($files), 'snaps' => bc_spread($files, 4), 'moment_id' => (int)$m['id'],
            'description' => $m['description'] ?: $m['note'],
            // What Looki's AI tagged in the recording, and the things its description names (for what changed).
            'keywords' => array_values(array_filter(array_map('trim', explode(',', (string)$m['keywords'])))),
            'objects' => bc_objects($conn, trim((string)($m['description'] ?: $m['note']) . '. ' . (string)$m['keywords'])),
            'office_note' => $m['office_note'], 'job_chosen' => (int)$m['job_chosen'] === 1,
            'office_job_id' => $m['office_job_id'] !== null ? (int)$m['office_job_id'] : null];
    }

    $entries = bc_store_entries($conn, $userId, $day);
    foreach ($entries as $e) {
        $rows[] = ['type' => 'store_entry', 'ref' => 'st-' . $e['id'], 'at' => $e['created_at'], 'text' => 'Took ' . ($e['items'] ? implode(', ', $e['items']) : 'items')
            . ($e['store'] ? ' from ' . $e['store'] : '') . ($e['for_note'] ? ' (for ' . $e['for_note'] . ')' : ''),
            'ok' => true, 'move_id' => (int)$e['id']];
    }
    $entryTimes = array_map(static fn(array $e): int => strtotime((string)$e['created_at']), $entries);
    $hasEntryNear = static function (int $start, int $end) use ($entryTimes): bool {
        foreach ($entryTimes as $t) {
            if ($t >= $start - BC_STORE_ENTRY_MINUTES * 60 && $t <= $end + BC_STORE_ENTRY_MINUTES * 60) {
                return true;
            }
        }
        return false;
    };

    $st = $conn->prepare("SELECT e.*, m.end_time AS moment_end, COALESCE(NULLIF(u.fullname, ''), u.username) AS added_by
        FROM ops_bodycam_events e LEFT JOIN ops_bodycam_moments m ON m.id = e.moment_id LEFT JOIN user u ON u.id = e.created_by
        WHERE e.user_id = ? AND e.at BETWEEN ? AND ? ORDER BY e.at");
    $st->execute([$userId, $from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ev) {
        if ($ev['kind'] === 'manual') {
            $rows[] = ['type' => 'manual', 'ref' => 'e' . $ev['id'], 'at' => $ev['at'], 'end' => $ev['end_at'], 'label' => (string)$ev['label'],
                'text' => (string)$ev['text'], 'added_by' => $ev['added_by']];
            continue;
        }
        if ($ev['kind'] === 'command') {
            $rows[] = ['type' => 'command', 'ref' => 'e' . $ev['id'], 'at' => $ev['at'], 'label' => (string)$ev['label'], 'said' => (string)$ev['text']];
            continue;
        }
        $t = strtotime((string)$ev['at']);
        $end = $ev['moment_end'] ? strtotime((string)$ev['moment_end']) : $t;
        // Proof: the body camera file nearest the change, or for voice the moment's snapshots.
        $snaps = $ev['file_id'] !== null ? [['id' => (int)$ev['file_id'], 'video' => false]]
            : bc_spread($filesByMoment[(int)$ev['moment_id']] ?? [], 2);
        $rows[] = ['type' => $ev['kind'], 'ref' => 'e' . $ev['id'], 'at' => $ev['at'], 'end' => $ev['kind'] === 'voice' ? $ev['moment_end'] : null,
            'text' => (string)$ev['text'], 'label' => (string)$ev['label'], 'ok' => $hasEntryNear($t, $end),
            'cam_change_id' => $ev['cam_change_id'] !== null ? (int)$ev['cam_change_id'] : null, 'snaps' => $snaps];
    }

    $rows = array_merge($rows, bc_other_rows($conn, $userId, $day));
    usort($rows, static fn(array $a, array $b): int => $a['at'] <=> $b['at']);
    $movable = array_merge(BC_MOVABLE_WORDS,
        array_map(static fn(string $n): string => (string)substr((string)strrchr(' ' . $n, ' '), 1), bc_item_words($conn)));
    $rows = array_merge($rows, bc_changes($rows, $movable));
    $hidden = bc_hidden($conn, $userId);
    $rows = array_values(array_filter($rows, static fn(array $r): bool => !isset($hidden[$r['ref']])));
    // The office's notes on rows from other modules (a recording keeps its own note).
    $st = $conn->prepare('SELECT ref, note FROM ops_bodycam_row_notes WHERE user_id = ?');
    $st->execute([$userId]);
    $notes = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    foreach ($rows as &$r) {
        if ($r['type'] !== 'moment' && $r['type'] !== 'manual') {
            $r['office_note'] = $notes[$r['ref']] ?? null;
        }
    }
    unset($r);

    $order = ['check_in' => 0, 'job_start' => 1, 'command' => 2, 'moment' => 3, 'change' => 3.5, 'store_entry' => 4, 'store_camera' => 5, 'voice' => 6,
        'pause' => 7, 'break' => 8, 'trip' => 9, 'manual' => 10, 'job_finish' => 11, 'check_out' => 12];
    usort($rows, static fn(array $a, array $b): int => [$a['at'], $order[$a['type']]] <=> [$b['at'], $order[$b['type']]]);

    // Voice commands read as the work they mark: Finish work and Back say how long since Start work and Break.
    // Staff only need three words: Start after a Break is back from the break.
    $openWork = null;
    $openBreak = null;
    foreach ($rows as &$r) {
        if ($r['type'] !== 'command') {
            continue;
        }
        $since = static fn(?string $from): string => $from ? ' (' . bc_minutes(strtotime($r['at']) - strtotime($from)) . ')' : '';
        if ($r['label'] === 'Start work' && $openBreak !== null) {
            $r['label'] = 'Back';
            $r['text'] = 'Back from break' . $since($openBreak);
            $openBreak = null;
        } elseif ($r['label'] === 'Start work') {
            $place = trim((string)(bc_voice_command($r['said'])[1] ?? ''));
            $r['text'] = 'Started work' . ($place !== '' ? ' · ' . $place : '');
            $openWork = $r['at'];
        } elseif ($r['label'] === 'Finish work') {
            $r['text'] = 'Finished work' . $since($openWork);
            $openWork = null;
            $openBreak = null;
        } elseif ($r['label'] === 'Break') {
            $r['text'] = 'Started a break';
            $openBreak = $r['at'];
        } elseif ($r['label'] === 'Back') {
            $r['text'] = 'Back from break' . $since($openBreak);
            $openBreak = null;
        } else {
            $r['text'] = 'Problem: ' . (trim((string)(bc_voice_command($r['said'])[1] ?? '')) ?: $r['said']);
        }
    }
    unset($r);

    $times = [];
    $jobs = [];
    $noJob = [];
    foreach ($rows as $r) {
        if ($r['type'] === 'moment') {
            $times[] = $r['at'];
            $times[] = $r['end'] ?: $r['at'];
            $jobs += $r['jobs'];
            // "No job" chosen by the office is an answer, not something still to sort out.
            if (!$r['jobs'] && !$r['job_chosen']) {
                $noJob[] = date('g:i A', strtotime($r['at'])) . ($r['end'] ? '–' . date('g:i A', strtotime($r['end'])) : '');
            }
        } elseif (!empty($r['job_id'])) {
            $jobs[$r['job_id']] = true;
        }
    }
    $events = array_filter($rows, static fn(array $r): bool => $r['type'] !== 'moment');
    return [
        'rows' => $rows,
        'first' => $times ? min($times) : null,
        'last' => $times ? max($times) : null,
        'jobs' => count($jobs),
        'events' => count($events),
        'alerts' => count(array_filter($events, static fn(array $r): bool => (in_array($r['type'], ['store_camera', 'voice'], true) && empty($r['ok']))
            || ($r['type'] === 'change' && $r['flag'] === 'gone'))),
        'no_job' => $noJob,
        'story' => bc_day_story($rows),
    ];
}

/** Things worth following through the day when Looki names them. Store item names are added. */
// "computer" is not here: Looki uses it for what something is ("computer mouse", "computer setup").
const BC_OBJECT_WORDS = ['box', 'package', 'parcel', 'carton', 'bag', 'sack', 'laptop', 'monitor', 'screen', 'keyboard',
    'mouse', 'phone', 'printer', 'bottle', 'can', 'bucket', 'mop', 'broom', 'ladder', 'drill', 'toolbox', 'tool', 'key', 'chair',
    'cabinet', 'tray', 'trolley', 'cart', 'helmet', 'vest', 'glove', 'cable', 'folder', 'file', 'cup', 'plant', 'fan', 'heater',
    'spray', 'cleaner', 'detergent', 'towel', 'cloth', 'vacuum', 'paint', 'brush', 'pipe', 'bulb', 'shelf',
    'binder', 'whiteboard', 'scanner', 'locker', 'rack', 'table', 'sofa', 'bed', 'fridge', 'microwave',
    'stove', 'sink', 'toilet', 'shower', 'mirror', 'window', 'curtain', 'purifier', 'dispenser', 'socket'];
/**
 * Things that get moved or taken. Only these are compared between recordings:
 * a desk, a monitor or a chair that Looki forgets to mention has not gone anywhere.
 * Store item names count as movable too.
 */
const BC_MOVABLE_WORDS = ['box', 'package', 'parcel', 'carton', 'bag', 'sack', 'bottle', 'can', 'bucket', 'mop', 'broom', 'ladder',
    'drill', 'toolbox', 'tool', 'key', 'trolley', 'cart', 'helmet', 'vest', 'glove', 'cable', 'spray', 'cleaner', 'detergent',
    'towel', 'cloth', 'vacuum', 'paint', 'brush', 'pipe', 'bulb', 'phone'];
/** Words that may stand before an object and belong in its name: colour, size, kind. */
const BC_OBJECT_ADJECTIVES = ['yellow', 'red', 'blue', 'green', 'black', 'white', 'brown', 'grey', 'gray', 'orange', 'pink',
    'purple', 'silver', 'small', 'medium', 'large', 'big', 'tiny', 'cardboard', 'plastic', 'metal', 'wooden', 'delivery',
    'external', 'computer', 'document', 'paper', 'water', 'cleaning', 'spray', 'open', 'closed', 'empty', 'full'];
const BC_NUMBER_WORDS = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8,
    'nine' => 9, 'ten' => 10, 'a' => 1, 'an' => 1, 'single' => 1, 'several' => null];

/**
 * The objects Looki's text names, as name => count (null when not said):
 * "a bright yellow delivery box" -> ["yellow delivery box" => 1].
 */
function bc_objects(PDO $conn, string $text): array
{
    $nouns = array_values(array_unique(array_merge(BC_OBJECT_WORDS, bc_item_words($conn))));
    $found = [];
    $words = preg_split('/[^a-z0-9\-]+/i', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($words as $i => $w) {
        $noun = null;
        foreach ($nouns as $n) {
            if (strpos($n, ' ') === false && ($w === $n || $w === $n . 's' || $w === $n . 'es' || ($n === 'shelf' && $w === 'shelves'))) {
                $noun = $n;
                break;
            }
        }
        if ($noun === null) {
            continue;
        }
        $adj = [];
        $j = $i - 1;
        // "a bright yellow box": colour and kind go in the name; "bright" is passed over.
        while ($j >= 0 && (in_array($words[$j], BC_OBJECT_ADJECTIVES, true) || in_array($words[$j], ['bright', 'dark', 'light', 'new', 'old', 'another', 'other'], true))
            && $words[$j] !== $noun) {
            if (in_array($words[$j], BC_OBJECT_ADJECTIVES, true)) {
                array_unshift($adj, $words[$j]);
            }
            $j--;
        }
        // "the laptop screen": a screen of the laptop, not another object.
        if ($noun === 'screen' && $j >= 0 && in_array($words[$j], ['laptop', 'computer', 'phone'], true)) {
            continue;
        }
        $count = null;
        if ($j >= 0 && array_key_exists($words[$j], BC_NUMBER_WORDS)) {
            $count = BC_NUMBER_WORDS[$words[$j]];
        } elseif ($j >= 0 && ctype_digit($words[$j])) {
            $count = (int)$words[$j];
        }
        $name = implode(' ', array_merge($adj, [$noun]));
        $found[$name] = $found[$name] ?? $count;
    }
    return $found;
}

/** "the yellow delivery box", "2 cans". */
function bc_object_text(string $name, ?int $count): string
{
    return $count !== null && $count > 1 ? $count . ' ' . $name . (preg_match('/(s|x|sh|ch)$/', $name) ? 'es' : 's') : 'the ' . $name;
}

/**
 * Spots within a place. Looki's location is only the district, so a desk and a
 * storeroom in one building share it; the spot Looki names in a recording's title
 * or tags tells them apart. The first one found, in this order, is the spot.
 */
const BC_SPOT_WORDS = ['storage room', 'store room', 'storeroom', 'utility room', 'warehouse', 'kitchen', 'bathroom', 'bedroom',
    'living room', 'balcony', 'parking', 'lobby', 'reception', 'corridor', 'pantry', 'meeting room', 'desk', 'office'];

/** A recording's spot: its district and the spot Looki names, or '' when Looki names none. */
function bc_spot(array $r): string
{
    $text = mb_strtolower($r['text'] . ' ' . implode(' ', $r['keywords'] ?? []));
    foreach (BC_SPOT_WORDS as $w) {
        if (bc_has_word($text, $w)) {
            return ($r['place'] ?? '') . '|' . str_replace(['store room', 'storeroom'], 'storage room', $w);
        }
    }
    return '';
}

/**
 * What changed between recordings at the same spot, as report rows of type
 * change: a movable thing ($movable: BC_MOVABLE_WORDS and the store items) that
 * Looki named before and not now (flag gone), named now and not before (new),
 * or a count that went down or up. $rows must be in time order.
 */
function bc_changes(array $rows, array $movable): array
{
    $t = static fn(string $dt): string => date('g:i A', strtotime($dt));
    $name = static fn(string $n, ?int $c): string => ucfirst($c !== null && $c > 1 ? bc_object_text($n, $c) : $n);
    $out = [];
    $seenAt = [];
    foreach ($rows as $r) {
        if ($r['type'] !== 'moment') {
            continue;
        }
        $place = bc_spot($r);
        $now = [];
        foreach ($r['objects'] ?? [] as $n => $count) {
            $noun = (string)substr((string)strrchr(' ' . $n, ' '), 1);
            if (in_array($noun, $movable, true)) {
                $now[$noun] = [$n, $count];
            }
        }
        if ($place !== '' && isset($seenAt[$place])) {
            [$before, $beforeAt] = $seenAt[$place];
            $row = static fn(string $noun, string $text, string $flag): array => ['type' => 'change', 'ref' => 'chg-' . $r['moment_id'] . '-' . $noun,
                'at' => $r['at'], 'place' => $r['place'], 'text' => $text, 'flag' => $flag, 'moment_id' => $r['moment_id'], 'snaps' => $r['snaps']];
            // Compared by the thing itself (the last word), so "yellow box" then "box" is the same box.
            foreach ($before as $noun => [$n, $count]) {
                if (!isset($now[$noun])) {
                    $out[] = $row($noun, $name($n, $count) . ' not seen (was there at ' . $t($beforeAt) . ')', 'gone');
                } elseif ($count !== null && $now[$noun][1] !== null && $now[$noun][1] !== $count) {
                    $out[] = $row($noun, ucfirst($n) . ': ' . $count . ' at ' . $t($beforeAt) . ', ' . $now[$noun][1] . ' now', $now[$noun][1] < $count ? 'gone' : 'new');
                }
            }
            foreach (array_diff_key($now, $before) as $noun => [$n, $count]) {
                $out[] = $row($noun, $name($n, $count) . ' new (not there at ' . $t($beforeAt) . ')', 'new');
            }
        }
        if ($place !== '' && $now) {
            $seenAt[$place] = [$now, $r['at']];
        }
    }
    return $out;
}

/**
 * The day in words, from the report rows: per part of the day, one line per
 * thing that happened. Answers [['part' => 'Morning', 'lines' => [[when, text, flag], …]], …].
 */
function bc_day_story(array $rows): array
{
    $t = static fn(string $dt): string => date('g:i A', strtotime($dt));
    $part = static function (string $dt): string {
        $h = (int)date('G', strtotime($dt));
        return $h < 12 ? 'Morning' : ($h < 17 ? 'Afternoon' : 'Evening');
    };
    $lines = [];
    foreach ($rows as $r) {
        $when = $t($r['at']) . (!empty($r['end']) ? '–' . $t($r['end']) : '');
        switch ($r['type']) {
            case 'moment':
                $text = $r['text'] . ($r['place'] ? ' · ' . explode(',', $r['place'])[0] : '')
                    . ($r['jobs'] ? ' · Job ' . implode(', ', array_map(static fn($id): string => '#' . $id, array_keys($r['jobs']))) : '');
                $lines[] = [$r['at'], $when, $text, null];
                break;
            case 'change':
                $lines[] = [$r['at'], $t($r['at']), $r['text'], $r['flag']];
                break;
            case 'store_camera':
            case 'voice':
                $lines[] = [$r['at'], $when, ($r['type'] === 'voice' ? ($r['label'] === 'Seen' ? 'Camera saw: ' : 'Heard: ') : '') . $r['text']
                    . (empty($r['ok']) ? ' No store entry for it.' : ' Store entry found.'), empty($r['ok']) ? 'gone' : null];
                break;
            default:
                $lines[] = [$r['at'], $when, $r['text'] . ($r['type'] === 'manual' && $r['label'] !== '' ? ' (' . $r['label'] . ')' : ''), null];
        }
        if (!empty($r['office_note'])) {
            $lines[] = [$r['at'], '', 'Office note: ' . $r['office_note'], 'note'];
        }
    }
    $story = [];
    foreach ($lines as [$at, $when, $text, $flag]) {
        $p = $part($at);
        if (!$story || end($story)['part'] !== $p) {
            $story[] = ['part' => $p, 'lines' => []];
        }
        $story[count($story) - 1]['lines'][] = ['when' => $when, 'text' => $text, 'flag' => $flag];
    }
    return $story;
}

/** A job's recordings, for its page. */
function bc_job_files(PDO $conn, int $jobId): array
{
    if (!bc_tables_ready($conn)) {
        return [];
    }
    $st = $conn->prepare("SELECT f.*, COALESCE(NULLIF(u.fullname, ''), u.username) AS person FROM ops_bodycam_files f
        LEFT JOIN user u ON u.id = f.user_id WHERE f.job_id = ? ORDER BY f.recorded_at");
    $st->execute([$jobId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Up to $n items spread evenly through the list (first and last included): the report's snapshots. */
function bc_spread(array $items, int $n): array
{
    $count = count($items);
    if ($count <= $n) {
        return $items;
    }
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = $items[(int)round($i * ($count - 1) / ($n - 1))];
    }
    return $out;
}

function bc_duration(?int $ms): string
{
    if (!$ms) {
        return '';
    }
    $s = (int)round($ms / 1000);
    return intdiv($s, 60) . ':' . str_pad((string)($s % 60), 2, '0', STR_PAD_LEFT);
}
