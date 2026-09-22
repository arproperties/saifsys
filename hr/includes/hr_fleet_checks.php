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
            ['key' => 'first_aid', 'label' => 'First aid kit in the vehicle and stocked'],
            ['key' => 'extinguisher', 'label' => 'Fire extinguisher charged and within reach'],
            ['key' => 'triangle', 'label' => 'Warning triangle in the vehicle'],
        ]],
        ['key' => 'documents', 'title' => 'Documents', 'items' => [
            ['key' => 'documents', 'label' => 'Registration (Mulkiya) and insurance in the vehicle and valid'],
        ]],
        ['key' => 'communication', 'title' => 'Communication', 'items' => [
            ['key' => 'phone', 'label' => 'Phone charged and working'],
            ['key' => 'radio', 'label' => 'Company radio works', 'na' => true],
        ]],
        ['key' => 'route', 'title' => 'Route', 'items' => [
            ['key' => 'gps', 'label' => 'GPS / maps working'],
            ['key' => 'traffic', 'label' => 'Checked traffic and road closures'],
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

/**
 * Check and save one day's answers.
 *
 * @return array{ok:bool,error:string,check:?array} error is written for the driver to read
 */
function fleet_save_daily_check(PDO $conn, array $vehicle, array $driver, $answers, $startKm, string $notes): array
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

    $date = fleet_check_today();
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
    } catch (PDOException $e) {
        // Already done today (a retried send whose reply got lost) — that one stands.
        if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
            throw $e;
        }
    }

    return ['ok' => true, 'error' => '', 'check' => fleet_daily_check_for($conn, (int)$vehicle['id'], (int)$driver['id'], $date)];
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
