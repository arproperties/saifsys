<?php
/**
 * Driver app — the daily pre-drive checklist. Used by the Driver API
 * (api/mobile/fleet) and by HR → Fleet → Daily Checks.
 *
 * Once a day per driver per vehicle, before the first trip in it. Each item is
 * OK or Problem (N/A where the item may not exist in every vehicle), plus the
 * starting kilometres. A Problem needs a note and flags the check to the
 * office; it does not stop the trip.
 *
 * Item keys are stored with each check, so a key is never renamed or reused
 * for a different item. Change the wording freely; add new keys for new items.
 * The apps draw whatever list the server sends — no app update needed.
 *
 * Schema: migrations/fleet_daily_checks.sql.
 */

declare(strict_types=1);

/** The checklist, section by section. 'na' => true allows N/A on that item. */
function fleet_daily_checklist(): array
{
    return [
        ['key' => 'personal', 'title' => 'You', 'items' => [
            ['key' => 'licence', 'label' => 'Driving licence with you and not expired'],
            ['key' => 'fit', 'label' => 'Fit to drive — no medicine that makes you sleepy'],
        ]],
        ['key' => 'exterior', 'title' => 'Outside the vehicle', 'items' => [
            ['key' => 'body', 'label' => 'No new damage, no leaks under the vehicle'],
            ['key' => 'lights', 'label' => 'Headlights, brake lights and indicators work'],
        ]],
        ['key' => 'tyres', 'title' => 'Tyres', 'items' => [
            ['key' => 'tyre_pressure', 'label' => 'Tyre pressure OK, spare tyre too'],
            ['key' => 'tyre_tread', 'label' => 'Tread OK — no cuts, bulges or bald tyres'],
        ]],
        ['key' => 'fluids', 'title' => 'Fluids', 'items' => [
            ['key' => 'fluids', 'label' => 'Oil, coolant, brake fluid, washer fluid and power steering fluid OK'],
        ]],
        ['key' => 'glass', 'title' => 'Wipers and windows', 'items' => [
            ['key' => 'wipers', 'label' => 'Wipers work'],
            ['key' => 'windows', 'label' => 'Windows clean, no cracks'],
        ]],
        ['key' => 'interior', 'title' => 'Inside', 'items' => [
            ['key' => 'mirrors', 'label' => 'Mirrors adjusted'],
            ['key' => 'seatbelt', 'label' => 'Seatbelt works — and you are wearing it'],
        ]],
        ['key' => 'emergency', 'title' => 'Emergency equipment', 'items' => [
            ['key' => 'emergency_kit', 'label' => 'First aid kit (stocked), fire extinguisher (charged) and warning triangle in the vehicle'],
        ]],
        ['key' => 'documents', 'title' => 'Documents', 'items' => [
            ['key' => 'documents', 'label' => 'Registration (Mulkiya) and insurance in the vehicle and valid'],
        ]],
        ['key' => 'final', 'title' => 'Before you go', 'items' => [
            ['key' => 'fuel', 'label' => 'Enough fuel for the trip'],
            ['key' => 'rest', 'label' => 'Rested and alert — do not drive tired'],
        ]],
    ];
}

/** item key => ['label', 'section', 'na'] */
function fleet_daily_checklist_items(): array
{
    $items = [];
    foreach (fleet_daily_checklist() as $section) {
        foreach ($section['items'] as $item) {
            $items[$item['key']] = [
                'label' => $item['label'],
                'section' => $section['title'],
                'na' => !empty($item['na']),
            ];
        }
    }
    return $items;
}

function fleet_checks_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query("SELECT 1 FROM fleet_daily_checks LIMIT 1");
            $ready = true;
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Today in Dubai — the clock the API and HR pages already run on. */
function fleet_check_today(): string
{
    return date('Y-m-d');
}

function fleet_daily_check_for(PDO $conn, int $vehicleId, int $driverId, string $date): ?array
{
    $stmt = $conn->prepare("SELECT * FROM fleet_daily_checks WHERE vehicle_id = ? AND driver_user_id = ? AND check_date = ? LIMIT 1");
    $stmt->execute([$vehicleId, $driverId, $date]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The last starting km anyone entered for this vehicle — shown to the driver as a hint. */
function fleet_last_start_km(PDO $conn, int $vehicleId): ?int
{
    $stmt = $conn->prepare("SELECT start_km FROM fleet_daily_checks WHERE vehicle_id = ? ORDER BY check_date DESC, id DESC LIMIT 1");
    $stmt->execute([$vehicleId]);
    $km = $stmt->fetchColumn();
    return $km === false ? null : (int)$km;
}

function fleet_check_media_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query("SELECT 1 FROM fleet_daily_check_media LIMIT 1");
            $ready = true;
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Photos and voice notes per check sent with one save — at most this many. */
const FLEET_CHECK_MAX_MEDIA = 20;

/**
 * Check and save one day's answers.
 *
 * $media: photos and voice notes showing a Problem, from the Staff web app —
 * each ['item_key' => …, 'kind' => 'photo'|'voice', 'file' => one $_FILES
 * entry, 'duration' => ?int]. A Problem may be shown this way instead of
 * written, for drivers who cannot write; the notes are still required and the
 * app fills them with the items' names.
 *
 * @return array{ok:bool,error:string,check:?array} error is written for the driver to read
 */
function fleet_save_daily_check(PDO $conn, array $vehicle, array $driver, $answers, $startKm, string $notes, array $media = []): array
{
    $fail = static fn(string $error): array => ['ok' => false, 'error' => $error, 'check' => null];

    $items = fleet_daily_checklist_items();
    if (!is_array($answers)) {
        return $fail('Answer every item on the checklist.');
    }
    $clean = [];
    foreach ($items as $key => $item) {
        $value = (string)($answers[$key] ?? '');
        $allowed = $item['na'] ? ['ok', 'problem', 'na'] : ['ok', 'problem'];
        if (!in_array($value, $allowed, true)) {
            return $fail('Answer every item on the checklist.');
        }
        $clean[$key] = $value;
    }

    if (!is_numeric($startKm) || (float)$startKm < 0 || (float)$startKm > 9999999 || floor((float)$startKm) != (float)$startKm) {
        return $fail('Enter the starting kilometres as a whole number.');
    }

    $notes = trim($notes);
    $problems = count(array_filter($clean, static fn(string $v): bool => $v === 'problem'));
    if ($problems > 0 && $notes === '') {
        return $fail('Write what the problem is, so the office can fix it.');
    }

    // Every file is checked before anything is written, so a bad one cannot
    // leave a saved check without the rest of its evidence.
    if (count($media) > FLEET_CHECK_MAX_MEDIA) {
        return $fail('Too many photos and voice messages. Send at most ' . FLEET_CHECK_MAX_MEDIA . '.');
    }
    foreach ($media as $i => $m) {
        $key = (string)($m['item_key'] ?? '');
        $kind = (string)($m['kind'] ?? '');
        if (($clean[$key] ?? '') !== 'problem' || !in_array($kind, ['photo', 'voice'], true) || !is_array($m['file'] ?? null)) {
            return $fail('A photo or voice message did not match a problem. Try again.');
        }
        $check = ops_check_media_upload($m['file'], $kind);
        if (!$check['ok']) {
            return $fail($check['error']);
        }
        $media[$i]['ext'] = $check['ext'];
    }

    $date = fleet_check_today();
    $inserted = false;
    $insert = $conn->prepare("
        INSERT INTO fleet_daily_checks
            (company_id, vehicle_id, driver_user_id, driver_name, check_date, start_km, answers, problem_count, notes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    try {
        $insert->execute([
            (int)$vehicle['company_id'],
            (int)$vehicle['id'],
            (int)$driver['id'],
            (string)$driver['name'],
            $date,
            (int)$startKm,
            json_encode($clean),
            $problems,
            $notes !== '' ? mb_substr($notes, 0, 2000) : null,
            fleet_now(),
        ]);
        $inserted = true;
    } catch (PDOException $e) {
        // Already done today (a retried send whose reply got lost) — that one stands.
        if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
            throw $e;
        }
    }

    $saved = fleet_daily_check_for($conn, (int)$vehicle['id'], (int)$driver['id'], $date);
    // Files only with the check they were sent with — a repeat of a check
    // already saved must not pile a second copy onto it.
    if ($inserted && $saved && $media) {
        fleet_store_check_media($conn, (int)$saved['id'], $media);
    }

    return ['ok' => true, 'error' => '', 'check' => $saved];
}

/**
 * Keep the checked files of a new check. Before the migration has run the
 * files are dropped (the check and its notes are saved either way) and it is
 * logged, so a trip is never refused over a missing table.
 */
function fleet_store_check_media(PDO $conn, int $checkId, array $media): void
{
    if (!fleet_check_media_ready($conn)) {
        error_log('fleet_store_check_media: run migrations/fleet_check_media.sql — ' . count($media) . ' file(s) for check ' . $checkId . ' not kept');
        return;
    }
    $root = dirname(__DIR__, 2);
    $base = $root . '/uploads/fleet_checks';
    if (!is_dir($base)) {
        @mkdir($base, 0755, true);
    }
    // Not web-readable: HR reads them through hr/fleet_check_media.php.
    if (!is_file($base . '/.htaccess')) {
        @file_put_contents($base . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    $dirRel = 'uploads/fleet_checks/' . $checkId;
    if (!is_dir($root . '/' . $dirRel)) {
        @mkdir($root . '/' . $dirRel, 0755, true);
    }
    $insert = $conn->prepare("
        INSERT INTO fleet_daily_check_media (check_id, item_key, kind, file_path, duration_seconds, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($media as $m) {
        $rel = $dirRel . '/' . $m['kind'] . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $m['ext'];
        if (!move_uploaded_file((string)$m['file']['tmp_name'], $root . '/' . $rel)) {
            error_log('fleet_store_check_media: could not move a file for check ' . $checkId);
            continue;
        }
        $duration = is_numeric($m['duration'] ?? null) ? max(1, min(3600, (int)$m['duration'])) : null;
        $insert->execute([$checkId, (string)$m['item_key'], (string)$m['kind'], $rel, $duration, fleet_now()]);
    }
}

/** check_id => list of media rows, for the HR list. */
function fleet_check_media_for(PDO $conn, array $checkIds): array
{
    $checkIds = array_values(array_filter(array_map('intval', $checkIds)));
    if (!$checkIds || !fleet_check_media_ready($conn)) {
        return [];
    }
    $stmt = $conn->prepare("
        SELECT id, check_id, item_key, kind, duration_seconds
        FROM fleet_daily_check_media
        WHERE check_id IN (" . implode(',', array_fill(0, count($checkIds), '?')) . ")
        ORDER BY id
    ");
    $stmt->execute($checkIds);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[(int)$row['check_id']][] = $row;
    }
    return $out;
}

/** Checks for the HR list, newest first. */
function fleet_daily_check_rows(PDO $conn, array $f, int $limit = 300): array
{
    $where = ['c.check_date BETWEEN ? AND ?'];
    $params = [$f['date_from'], $f['date_to']];
    if (!empty($f['vehicle_id'])) {
        $where[] = 'c.vehicle_id = ?';
        $params[] = (int)$f['vehicle_id'];
    }
    if (!empty($f['driver_user_id'])) {
        $where[] = 'c.driver_user_id = ?';
        $params[] = (int)$f['driver_user_id'];
    }
    if (!empty($f['company_id'])) {
        $where[] = 'c.company_id = ?';
        $params[] = (int)$f['company_id'];
    }
    if (!empty($f['problems_only'])) {
        $where[] = 'c.problem_count > 0';
    }
    $stmt = $conn->prepare("
        SELECT c.*, v.plate_no, v.name AS vehicle_name, r.fullname AS reviewed_by_name
        FROM fleet_daily_checks c
        JOIN fleet_vehicles v ON v.id = c.vehicle_id
        LEFT JOIN user r ON r.id = c.reviewed_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.check_date DESC, c.created_at DESC
        LIMIT " . (int)$limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Problems the office has not marked as seen yet, any date. */
function fleet_open_problem_count(PDO $conn): int
{
    if (!fleet_checks_ready($conn)) {
        return 0;
    }
    return (int)$conn->query("SELECT COUNT(*) FROM fleet_daily_checks WHERE problem_count > 0 AND reviewed_at IS NULL")->fetchColumn();
}
