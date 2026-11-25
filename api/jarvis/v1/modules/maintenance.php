<?php
/**
 * Jarvis API v1 — Real Estate → Maintenance: the tenant / office maintenance requests and
 * the work orders (scheduled jobs, WO-x-xxxxx) the maintenance team works from.
 *
 * Loaded only by ../index.php, after the key has been checked; opened directly it
 * answers nothing. $conn, $action and the jarvis_api_* helpers come from there.
 *
 * View only: every action reads, none writes. Who may ask is decided in Jarvis: people
 * with the Real Estate module ticked (the master always).
 *
 *   ?module=maintenance&action=requests&building=&unit=&status=&category=&q=&from=&to=&open=1&company=
 *        maintenance requests, newest first (200 at most), with counts by status,
 *        category and building over everything that matched, and the total cost.
 *        from/to are on the request date; no dates = any date.
 *   ?module=maintenance&action=work_orders&building=&unit=&status=&type=&person=&from=&to=&open=1&overdue=1&company=
 *        work orders, by schedule date, newest first (200 at most), with the team on
 *        each and counts by status, type and building.
 *   ?module=maintenance&action=item&id=  |  &wo=WO-2-02187
 *        one request in full (its photos counted, its work orders listed), or one work
 *        order in full (its team and the request it came from).
 *
 * building = id or part of the name. company = id or part of the name.
 * Left out on purpose: tenant phones, emails and ID numbers.
 */

declare(strict_types=1);

if (!defined('JARVIS_API')) {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Asia/Dubai');

const JARVIS_MAINT_MAX = 200;

function jarvis_maint_str(string $name): string
{
    return trim((string)($_GET[$name] ?? ''));
}

function jarvis_maint_date(string $name): string
{
    $v = jarvis_maint_str($name);
    if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        jarvis_api_error('bad_request', "$name must be YYYY-MM-DD.", 400);
    }
    return $v;
}

/** One row of a named table by id or part of the name; [id, name] or [0, null] when not asked. */
function jarvis_maint_pick(PDO $conn, string $param, string $table, string $what): array
{
    $q = jarvis_maint_str($param);
    if ($q === '') {
        return [0, null];
    }
    $stmt = ctype_digit($q)
        ? $conn->prepare("SELECT id, name FROM $table WHERE id = ?")
        : $conn->prepare("SELECT id, name FROM $table WHERE name LIKE ? ORDER BY name");
    $stmt->execute([ctype_digit($q) ? (int)$q : '%' . $q . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // An exact name wins over the ones that merely contain it.
    $exact = array_values(array_filter($rows, fn($r) => strcasecmp((string)$r['name'], $q) === 0));
    if (count($exact) === 1) {
        $rows = $exact;
    }
    if (count($rows) !== 1) {
        jarvis_api_error('bad_request', $rows
            ? "More than one $what matches \"$q\": " . implode(', ', array_column($rows, 'name')) . '.'
            : "No $what matches \"$q\".", 400);
    }
    return [(int)$rows[0]['id'], (string)$rows[0]['name']];
}

/** "Park Place · Unit 205" / "Park Place · Lobby" / "Park Place (whole building)". */
function jarvis_maint_place(?string $building, string $type, ?string $unit, ?string $area): string
{
    $b = trim((string)$building) !== '' ? trim((string)$building) : 'No building';
    if ($type === 'common_area') {
        return $b . ' · ' . (trim((string)$area) !== '' ? trim((string)$area) : 'Common area');
    }
    if ($type === 'building' || trim((string)$unit) === '') {
        return $b . ' (whole building)';
    }
    return $b . ' · Unit ' . trim((string)$unit);
}

function jarvis_maint_count(array $rows, string $key): array
{
    $out = [];
    foreach ($rows as $r) {
        $k = trim((string)($r[$key] ?? ''));
        $k = $k === '' ? 'not set' : $k;
        $out[$k] = ($out[$k] ?? 0) + 1;
    }
    arsort($out);
    return $out;
}

/** The filters both lists share, as SQL. $b = building expression, $u = units alias, $d = date column. */
function jarvis_maint_common(PDO $conn, string $b, string $d, string $co, array &$where, array &$params): array
{
    $asked = [];
    [$companyId, $companyName] = jarvis_maint_pick($conn, 'company', 'companies', 'company');
    if ($companyId) {
        $where[] = "$co = ?";
        $params[] = $companyId;
        $asked['company'] = $companyName;
    }
    $bq = jarvis_maint_str('building');
    if ($bq !== '') {
        $stmt = ctype_digit($bq)
            ? $conn->prepare('SELECT id, name FROM re_buildings WHERE id = ?')
            : $conn->prepare('SELECT id, name FROM re_buildings WHERE name LIKE ? ORDER BY name');
        $stmt->execute([ctype_digit($bq) ? (int)$bq : '%' . $bq . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            jarvis_api_error('bad_request', "No building matches \"$bq\".", 400);
        }
        $exact = array_values(array_filter($rows, fn($r) => strcasecmp((string)$r['name'], $bq) === 0));
        $rows = $exact ?: $rows;
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $where[] = "$b IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($params, ...$ids);
        $asked['building'] = implode(', ', array_column($rows, 'name'));
    }
    $unit = jarvis_maint_str('unit');
    if ($unit !== '') {
        $where[] = 'u.unit_number = ?';
        $params[] = $unit;
        $asked['unit'] = $unit;
    }
    $from = jarvis_maint_date('from');
    $to = jarvis_maint_date('to');
    if ($from !== '' && $to !== '' && $from > $to) {
        [$from, $to] = [$to, $from];
    }
    if ($from !== '') {
        $where[] = "$d >= ?";
        $params[] = $from;
        $asked['from'] = $from;
    }
    if ($to !== '') {
        $where[] = "$d <= ?";
        $params[] = $to;
        $asked['to'] = $to;
    }
    return $asked;
}

// ---------- requests ----------

const JARVIS_MAINT_REQ_SQL = "
    SELECT mr.id, mr.request_date, mr.priority, mr.category, mr.description, mr.status, mr.location_type,
           mr.cost, mr.notes, mr.source, mr.created_at, mr.responded_at, mr.completed_at,
           mr.response_time_minutes, mr.resolution_time_hours, mr.lease_id,
           u.unit_number, ca.area_name AS common_area_name,
           COALESCE(b.name, bu.name) AS building_name,
           TRIM(CONCAT(COALESCE(t.first_name, ''), ' ', COALESCE(t.last_name, ''))) AS tenant_name,
           t.company_name AS tenant_company,
           e.full_name AS assigned_to,
           v.vendor_name AS vendor,
           c.name AS company,
           (SELECT COUNT(*) FROM re_maintenance_photos mp WHERE mp.maintenance_request_id = mr.id) AS photos
    FROM re_maintenance_requests mr
    LEFT JOIN re_units u ON u.id = mr.unit_id
    LEFT JOIN re_buildings bu ON bu.id = u.building_id
    LEFT JOIN re_buildings b ON b.id = mr.building_id
    LEFT JOIN re_building_common_areas ca ON ca.id = mr.common_area_id
    LEFT JOIN re_tenants t ON t.id = mr.tenant_id
    LEFT JOIN employees e ON e.id = mr.assigned_to
    LEFT JOIN re_vendors v ON v.id = mr.vendor_id
    LEFT JOIN companies c ON c.id = mr.company_id
";

function jarvis_maint_request_row(array $r, bool $full = false): array
{
    $tenant = trim((string)$r['tenant_name']);
    if (trim((string)$r['tenant_company']) !== '') {
        $tenant = trim((string)$r['tenant_company']) . ($tenant !== '' ? " ($tenant)" : '');
    }
    $row = [
        'id' => (int)$r['id'],
        'date' => $r['request_date'],
        'place' => jarvis_maint_place($r['building_name'], (string)$r['location_type'], $r['unit_number'], $r['common_area_name']),
        'category' => $r['category'] ?: null,
        'priority' => $r['priority'],
        'status' => $r['status'],
        'description' => $full ? (string)$r['description'] : mb_strimwidth((string)$r['description'], 0, 220, '…'),
        'tenant' => $tenant !== '' ? $tenant : null,
        'assigned_to' => $r['assigned_to'] ?: null,
        'vendor' => $r['vendor'] ?: null,
        'cost' => (float)$r['cost'] ?: null,
        'completed_at' => $r['completed_at'],
        'from_tenant_app' => $r['source'] === 'tenant_mobile' ?: null,
        'photos' => (int)$r['photos'] ?: null,
    ];
    if ($full) {
        $row += [
            'company' => $r['company'],
            'notes' => $r['notes'] ?: null,
            'created_at' => $r['created_at'],
            'responded_at' => $r['responded_at'],
            'response_time_minutes' => $r['response_time_minutes'] !== null ? (int)$r['response_time_minutes'] : null,
            'resolution_time_hours' => $r['resolution_time_hours'] !== null ? (float)$r['resolution_time_hours'] : null,
            'lease_id' => $r['lease_id'] ? (int)$r['lease_id'] : null,
        ];
    }
    return array_filter($row, fn($v) => $v !== null);
}

if ($action === 'requests') {
    $where = ['1 = 1'];
    $params = [];
    $asked = jarvis_maint_common($conn, 'COALESCE(mr.building_id, u.building_id)', 'mr.request_date', 'mr.company_id', $where, $params);
    $status = jarvis_maint_str('status');
    if ($status !== '') {
        if (!in_array($status, ['pending', 'in_progress', 'completed', 'cancelled'], true)) {
            jarvis_api_error('bad_request', 'status is pending, in_progress, completed or cancelled.', 400);
        }
        $where[] = 'mr.status = ?';
        $params[] = $status;
        $asked['status'] = $status;
    } elseif (jarvis_maint_str('open') === '1') {
        $where[] = "mr.status IN ('pending', 'in_progress')";
        $asked['open'] = 'pending or in progress';
    }
    $category = jarvis_maint_str('category');
    if ($category !== '') {
        $where[] = 'mr.category LIKE ?';
        $params[] = '%' . $category . '%';
        $asked['category'] = $category;
    }
    $q = jarvis_maint_str('q');
    if ($q !== '') {
        $where[] = '(mr.description LIKE ? OR mr.notes LIKE ? OR mr.category LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ? OR t.company_name LIKE ?)';
        array_push($params, ...array_fill(0, 6, '%' . $q . '%'));
        $asked['q'] = $q;
    }

    $stmt = $conn->prepare(JARVIS_MAINT_REQ_SQL . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY mr.request_date DESC, mr.id DESC');
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $today = date('Y-m-d');
    $openOld = array_filter($rows, fn($r) => in_array($r['status'], ['pending', 'in_progress'], true)
        && $r['request_date'] <= date('Y-m-d', strtotime('-7 days')));
    $resolution = array_filter(array_map(fn($r) => $r['resolution_time_hours'], $rows), fn($v) => $v !== null && $v > 0);

    jarvis_api_send([
        'ok' => true,
        'asked' => $asked ?: ['all' => 'every request, any date'],
        'total' => count($rows),
        'shown' => min(count($rows), JARVIS_MAINT_MAX),
        'by_status' => jarvis_maint_count($rows, 'status'),
        'by_category' => array_slice(jarvis_maint_count($rows, 'category'), 0, 20, true),
        'by_building' => jarvis_maint_count($rows, 'building_name'),
        'by_priority' => jarvis_maint_count($rows, 'priority'),
        'open_over_7_days' => count($openOld),
        'total_cost' => round(array_sum(array_map(fn($r) => (float)$r['cost'], $rows)), 2),
        'avg_resolution_hours' => $resolution ? round(array_sum($resolution) / count($resolution), 1) : null,
        'today' => $today,
        'requests' => array_map('jarvis_maint_request_row', array_slice($rows, 0, JARVIS_MAINT_MAX)),
        'page' => 'Real Estate → Maintenance in saifsys shows the same requests.',
    ]);
}

// ---------- work orders ----------

const JARVIS_MAINT_WO_SQL = "
    SELECT s.id, s.work_order_number, s.maintenance_request_id, s.location_type, s.task_type, s.title,
           s.description, s.priority, s.schedule_date, s.start_time, s.end_time, s.status, s.notes,
           s.created_at, s.updated_at,
           u.unit_number, ca.area_name AS common_area_name,
           COALESCE(b.name, bu.name) AS building_name,
           c.name AS company
    FROM re_maintenance_schedules s
    LEFT JOIN re_units u ON u.id = s.unit_id
    LEFT JOIN re_buildings bu ON bu.id = u.building_id
    LEFT JOIN re_buildings b ON b.id = s.building_id
    LEFT JOIN re_building_common_areas ca ON ca.id = s.common_area_id
    LEFT JOIN companies c ON c.id = s.company_id
";

/** schedule id => list of "Name (role)". */
function jarvis_maint_teams(PDO $conn, array $ids): array
{
    if (!$ids) {
        return [];
    }
    $stmt = $conn->prepare("
        SELECT a.schedule_id, a.team_role, COALESCE(NULLIF(e.full_name, ''), CONCAT('Employee #', a.employee_id)) AS name
        FROM re_maintenance_schedule_assignees a
        LEFT JOIN employees e ON e.id = a.employee_id
        WHERE a.schedule_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
        ORDER BY a.team_role, name");
    $stmt->execute(array_values($ids));
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $out[(int)$a['schedule_id']][] = $a['name'] . ' (' . $a['team_role'] . ')';
    }
    return $out;
}

function jarvis_maint_wo_row(array $s, array $teams, bool $full = false): array
{
    $open = in_array($s['status'], ['scheduled', 'in_progress', 'delayed'], true);
    $row = [
        'work_order' => $s['work_order_number'] ?: ('#' . $s['id']),
        'id' => (int)$s['id'],
        'date' => $s['schedule_date'],
        'time' => substr((string)$s['start_time'], 0, 5) . '–' . substr((string)$s['end_time'], 0, 5),
        'place' => jarvis_maint_place($s['building_name'], (string)$s['location_type'], $s['unit_number'], $s['common_area_name']),
        'type' => $s['task_type'],
        'title' => $s['title'] ?: null,
        'priority' => $s['priority'],
        'status' => $s['status'],
        'overdue' => ($open && $s['schedule_date'] < date('Y-m-d')) ?: null,
        'team' => $teams[(int)$s['id']] ?? null,
        'request_id' => $s['maintenance_request_id'] ? (int)$s['maintenance_request_id'] : null,
    ];
    if ($full) {
        $row += [
            'company' => $s['company'],
            'description' => $s['description'] ?: null,
            'notes' => $s['notes'] ?: null,
            'created_at' => $s['created_at'],
            'updated_at' => $s['updated_at'],
        ];
    } elseif (trim((string)$s['description']) !== '' && trim((string)$s['description']) !== trim((string)$s['title'])) {
        $row['description'] = mb_strimwidth((string)$s['description'], 0, 160, '…');
    }
    return array_filter($row, fn($v) => $v !== null);
}

if ($action === 'work_orders') {
    $where = ['1 = 1'];
    $params = [];
    $asked = jarvis_maint_common($conn, 'COALESCE(s.building_id, u.building_id)', 's.schedule_date', 's.company_id', $where, $params);
    $status = jarvis_maint_str('status');
    if ($status !== '') {
        if (!in_array($status, ['scheduled', 'in_progress', 'completed', 'delayed', 'cancelled'], true)) {
            jarvis_api_error('bad_request', 'status is scheduled, in_progress, completed, delayed or cancelled.', 400);
        }
        $where[] = 's.status = ?';
        $params[] = $status;
        $asked['status'] = $status;
    } elseif (jarvis_maint_str('open') === '1' || jarvis_maint_str('overdue') === '1') {
        $where[] = "s.status IN ('scheduled', 'in_progress', 'delayed')";
        $asked['open'] = 'scheduled, in progress or delayed';
    }
    if (jarvis_maint_str('overdue') === '1') {
        $where[] = 's.schedule_date < ?';
        $params[] = date('Y-m-d');
        $asked['overdue'] = 'date passed, not completed';
    }
    $type = jarvis_maint_str('type');
    if ($type !== '') {
        $where[] = 's.task_type = ?';
        $params[] = $type;
        $asked['type'] = $type;
    }
    $person = jarvis_maint_str('person');
    if ($person !== '') {
        $where[] = 's.id IN (SELECT a.schedule_id FROM re_maintenance_schedule_assignees a JOIN employees e ON e.id = a.employee_id WHERE e.full_name LIKE ?)';
        $params[] = '%' . $person . '%';
        $asked['person'] = $person;
    }
    $q = jarvis_maint_str('q');
    if ($q !== '') {
        $where[] = '(s.title LIKE ? OR s.description LIKE ? OR s.notes LIKE ? OR s.work_order_number LIKE ?)';
        array_push($params, ...array_fill(0, 4, '%' . $q . '%'));
        $asked['q'] = $q;
    }

    $stmt = $conn->prepare(JARVIS_MAINT_WO_SQL . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY s.schedule_date DESC, s.start_time DESC, s.id DESC');
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $shown = array_slice($rows, 0, JARVIS_MAINT_MAX);
    $teams = jarvis_maint_teams($conn, array_map(fn($s) => (int)$s['id'], $shown));
    $today = date('Y-m-d');

    jarvis_api_send([
        'ok' => true,
        'asked' => $asked ?: ['all' => 'every work order, any date'],
        'total' => count($rows),
        'shown' => count($shown),
        'by_status' => jarvis_maint_count($rows, 'status'),
        'by_type' => jarvis_maint_count($rows, 'task_type'),
        'by_building' => jarvis_maint_count($rows, 'building_name'),
        'overdue' => count(array_filter($rows, fn($s) => in_array($s['status'], ['scheduled', 'in_progress', 'delayed'], true)
            && $s['schedule_date'] < $today)),
        'today' => $today,
        'work_orders' => array_map(fn($s) => jarvis_maint_wo_row($s, $teams), $shown),
        'meaning' => [
            'overdue' => 'The scheduled date has passed and the work order is not marked completed or cancelled. Often the work was done but nobody closed it in saifsys.',
            'page' => 'Real Estate → Maintenance Schedule in saifsys shows the same work orders.',
        ],
    ]);
}

// ---------- one item ----------

if ($action === 'item') {
    $wo = jarvis_maint_str('wo');
    $id = (int)jarvis_maint_str('id');
    if ($wo !== '') {
        $stmt = $conn->prepare(JARVIS_MAINT_WO_SQL . ' WHERE s.work_order_number = ? OR s.id = ? LIMIT 1');
        $stmt->execute([$wo, ctype_digit(ltrim($wo, '#')) ? (int)ltrim($wo, '#') : 0]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$s) {
            jarvis_api_error('not_found', "No work order $wo.", 404);
        }
        $out = jarvis_maint_wo_row($s, jarvis_maint_teams($conn, [(int)$s['id']]), true);
        if ($s['maintenance_request_id']) {
            $r = $conn->prepare(JARVIS_MAINT_REQ_SQL . ' WHERE mr.id = ?');
            $r->execute([(int)$s['maintenance_request_id']]);
            if ($req = $r->fetch(PDO::FETCH_ASSOC)) {
                $out['request'] = jarvis_maint_request_row($req, true);
            }
        }
        jarvis_api_send(['ok' => true, 'work_order' => $out]);
    }
    if ($id <= 0) {
        jarvis_api_error('bad_request', 'Give id (a request id) or wo (a work order number).', 400);
    }
    $r = $conn->prepare(JARVIS_MAINT_REQ_SQL . ' WHERE mr.id = ?');
    $r->execute([$id]);
    $req = $r->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        jarvis_api_error('not_found', "No maintenance request $id.", 404);
    }
    $out = jarvis_maint_request_row($req, true);
    $p = $conn->prepare('SELECT photo_type, COUNT(*) AS n FROM re_maintenance_photos WHERE maintenance_request_id = ? GROUP BY photo_type');
    $p->execute([$id]);
    $photos = array_column($p->fetchAll(PDO::FETCH_ASSOC) ?: [], 'n', 'photo_type');
    if ($photos) {
        $out['photos'] = array_map('intval', $photos);
    }
    $w = $conn->prepare(JARVIS_MAINT_WO_SQL . ' WHERE s.maintenance_request_id = ? ORDER BY s.schedule_date, s.start_time');
    $w->execute([$id]);
    $wos = $w->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $teams = jarvis_maint_teams($conn, array_map(fn($s) => (int)$s['id'], $wos));
    $out['work_orders'] = array_map(fn($s) => jarvis_maint_wo_row($s, $teams), $wos);
    jarvis_api_send(['ok' => true, 'request' => $out]);
}

jarvis_api_error('not_found', 'Unknown action. Try requests, work_orders or item.', 404);
