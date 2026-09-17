<?php
/**
 * Operations — the cleaning checklist.
 *
 * Shown on cleaning jobs that include a unit. The cleaner sees it before
 * starting, ticks each item Done or N/A while working, and cannot finish until
 * every item has an answer. See migrations/ops_cleaning_checklist.sql.
 *
 * Item keys are stored with each finished job, so a key is never renamed or
 * reused for a different item. Change the wording freely; add new keys for new
 * items.
 */

/** The checklist, section by section. */
function ops_cleaning_checklist(): array {
    return [
        ['key' => 'general', 'title' => 'Whole unit', 'items' => [
            'floors'       => 'Floors swept and mopped',
            'walls'        => 'Walls, doors and switches wiped, marks removed',
            'rubbish'      => 'Rubbish and left-behind items removed',
            'aired'        => 'Unit aired, no bad smell',
        ]],
        ['key' => 'kitchen', 'title' => 'Kitchen', 'items' => [
            'counters'     => 'Countertops and sink cleaned',
            'cabinets'     => 'Cabinets and drawers cleaned inside and out',
            'stove'        => 'Stove and burners cleaned',
            'hood'         => 'Hood and filter cleaned',
            'fridge'       => 'Fridge cleaned inside and out',
            'oven'         => 'Microwave and oven cleaned',
        ]],
        ['key' => 'rooms', 'title' => 'Rooms', 'items' => [
            'wardrobes'    => 'Wardrobes and drawers cleaned inside',
            'furniture'    => 'Furniture dusted and wiped',
            'bed'          => 'Bed frame and mattress cleaned',
            'curtains'     => 'Curtains and blinds dusted',
            'windows'      => 'Windows, glass and sliding doors cleaned',
            'lights_ac'    => 'Light fittings and AC vents dusted',
        ]],
        ['key' => 'bathroom', 'title' => 'Bathroom', 'items' => [
            'toilet'       => 'Toilet and bidet cleaned',
            'shower'       => 'Sink, shower and taps cleaned, limescale removed',
            'mirror'       => 'Mirror and shelves cleaned',
            'tiles'        => 'Tiles and grout cleaned, no mould',
            'drains'       => 'Drains cleared',
        ]],
        ['key' => 'other', 'title' => 'Other areas', 'items' => [
            'balcony'      => 'Balcony cleaned, no debris',
            'storage'      => "Storage and maid's room cleaned",
            'parking'      => 'Parking slot cleaned',
        ]],
    ];
}

/** Things a cleaner may notice but does not fix. Any of them raises a maintenance job. */
function ops_cleaning_problems(): array {
    return [
        'leak'      => 'Water leak',
        'ac'        => 'AC not cooling',
        'appliance' => 'Appliance not working',
        'electric'  => 'Light or socket not working',
        'damage'    => 'Wall or furniture damage',
        'other'     => 'Other problem',
    ];
}

/** Every item key, flat. */
function ops_cleaning_checklist_keys(): array {
    $keys = [];
    foreach (ops_cleaning_checklist() as $section) {
        foreach ($section['items'] as $key => $_) {
            $keys[] = $key;
        }
    }
    return $keys;
}

/** Does this job carry the checklist: a cleaning job with at least one unit on it. */
function ops_job_needs_checklist(PDO $conn, array $job): bool {
    if (($job['job_type'] ?? '') !== 'cleaning') {
        return false;
    }
    $places = ops_job_places($conn, [(int)$job['id']])[(int)$job['id']] ?? [];
    foreach ($places as $place) {
        if ($place['place_kind'] === 'unit') {
            return true;
        }
    }
    return false;
}

/** The saved checklist of a finished job, or null. */
function ops_checklist_load(PDO $conn, int $jobId): ?array {
    try {
        $stmt = $conn->prepare("SELECT * FROM ops_job_checklists WHERE job_id = ? LIMIT 1");
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // A database without the migration yet.
        error_log('ops_checklist_load failed: ' . $e->getMessage());
        return null;
    }
    if (!$row) {
        return null;
    }
    $items = json_decode((string)$row['items'], true);
    return [
        'items' => is_array($items) ? $items : [],
        'problems' => $row['problems'] !== null && $row['problems'] !== ''
            ? explode(',', (string)$row['problems'])
            : [],
        'problem_note' => $row['problem_note'] !== null && $row['problem_note'] !== ''
            ? (string)$row['problem_note']
            : null,
        'maintenance_job_id' => $row['maintenance_job_id'] !== null ? (int)$row['maintenance_job_id'] : null,
        'completed_by' => $row['completed_by'] !== null ? (int)$row['completed_by'] : null,
        'created_at' => (string)$row['created_at'],
    ];
}

/**
 * Read a checklist sent with Finish. Null when any item is missing or unknown —
 * the finish is refused and the cleaner is told to answer every item.
 *
 * @return array{items: array<string,string>, problems: string[], problem_note: string}|null
 */
function ops_checklist_parse($input): ?array {
    if (!is_array($input) || !is_array($input['items'] ?? null)) {
        return null;
    }

    $items = [];
    foreach (ops_cleaning_checklist_keys() as $key) {
        $state = $input['items'][$key] ?? null;
        if ($state !== 'done' && $state !== 'na') {
            return null;
        }
        $items[$key] = $state;
    }

    $problems = [];
    foreach ((array)($input['problems'] ?? []) as $problem) {
        if (is_string($problem) && array_key_exists($problem, ops_cleaning_problems())) {
            $problems[$problem] = true;
        }
    }

    return [
        'items' => $items,
        'problems' => array_keys($problems),
        'problem_note' => mb_substr(trim((string)($input['problem_note'] ?? '')), 0, 2000),
    ];
}

/**
 * Save the checklist of a job that has just finished, and raise a maintenance
 * job when the cleaner flagged a problem. Never throws: the clean is done
 * whatever happens to its paperwork.
 */
function ops_checklist_save(PDO $conn, array $job, int $userId, array $checklist): void {
    try {
        $jobId = (int)$job['id'];
        $companyId = (int)$job['company_id'];

        $maintenanceJobId = null;
        if ($checklist['problems'] || $checklist['problem_note'] !== '') {
            $maintenanceJobId = ops_checklist_raise_maintenance($conn, $job, $checklist);
        }

        $conn->prepare("
            INSERT IGNORE INTO ops_job_checklists
                (job_id, company_id, items, problems, problem_note, maintenance_job_id, completed_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $jobId,
            $companyId,
            json_encode($checklist['items']),
            $checklist['problems'] ? implode(',', $checklist['problems']) : null,
            $checklist['problem_note'] !== '' ? $checklist['problem_note'] : null,
            $maintenanceJobId,
            $userId,
            date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        error_log('ops_checklist_save failed: ' . $e->getMessage());
    }
}

/**
 * One maintenance job in the Requests pool for what the cleaner found, at the
 * same units. Returns its id.
 */
function ops_checklist_raise_maintenance(PDO $conn, array $job, array $checklist): ?int {
    $labels = ops_cleaning_problems();
    $lines = array_map(static fn(string $key): string => '- ' . $labels[$key], $checklist['problems']);
    if ($checklist['problem_note'] !== '') {
        $lines[] = $checklist['problem_note'];
    }

    $places = [];
    foreach (ops_job_places($conn, [(int)$job['id']])[(int)$job['id']] ?? [] as $place) {
        if ($place['place_kind'] === 'unit') {
            $places[] = [
                'kind' => 'unit',
                'id' => (int)$place['place_id'],
                'building_id' => $place['building_id'] !== null ? (int)$place['building_id'] : null,
                'label' => (string)$place['label'],
            ];
        }
    }

    $title = count($checklist['problems']) === 1
        ? $labels[$checklist['problems'][0]]
        : 'Problem found during cleaning';

    ops_insert_source_job($conn, [
        'company_id' => (int)$job['company_id'],
        'job_type' => 'maintenance',
        'title' => $title,
        'description' => "Found by the cleaner on cleaning job #{$job['id']}:\n" . implode("\n", $lines),
        'scheduled_date' => date('Y-m-d'),
        'scheduled_time' => null,
        'priority' => 'normal',
        'source_type' => 'cleaner_report',
        'source_id' => (int)$job['id'],
    ], $places);

    $stmt = $conn->prepare("SELECT id FROM ops_jobs WHERE source_type = 'cleaner_report' AND source_id = ? LIMIT 1");
    $stmt->execute([(int)$job['id']]);
    $id = (int)$stmt->fetchColumn();
    return $id > 0 ? $id : null;
}

/**
 * The checklist and the problem list, as the app reads them, with no job and
 * nothing saved.
 */
function ops_checklist_definition_for_app(): array {
    $sections = [];
    foreach (ops_cleaning_checklist() as $section) {
        $items = [];
        foreach ($section['items'] as $key => $label) {
            $items[] = ['key' => $key, 'label' => $label];
        }
        $sections[] = ['key' => $section['key'], 'title' => $section['title'], 'items' => $items];
    }
    $problems = [];
    foreach (ops_cleaning_problems() as $key => $label) {
        $problems[] = ['key' => $key, 'label' => $label];
    }
    return ['sections' => $sections, 'problems' => $problems];
}

/**
 * The checklist as the app reads it, or null when the job has none.
 */
function ops_checklist_for_app(PDO $conn, array $job): ?array {
    if (!ops_job_needs_checklist($conn, $job)) {
        return null;
    }

    $sections = [];
    foreach (ops_cleaning_checklist() as $section) {
        $items = [];
        foreach ($section['items'] as $key => $label) {
            $items[] = ['key' => $key, 'label' => $label];
        }
        $sections[] = ['key' => $section['key'], 'title' => $section['title'], 'items' => $items];
    }

    $problems = [];
    foreach (ops_cleaning_problems() as $key => $label) {
        $problems[] = ['key' => $key, 'label' => $label];
    }

    $saved = ops_checklist_load($conn, (int)$job['id']);

    return [
        'sections' => $sections,
        'problems' => $problems,
        'saved' => $saved ? [
            'items' => (object)$saved['items'],
            'problems' => $saved['problems'],
            'problem_note' => $saved['problem_note'],
            'maintenance_job_id' => $saved['maintenance_job_id'],
        ] : null,
    ];
}
