<?php
/**
 * Jarvis API v1 — the Real Estate module.
 *
 * Loaded only by ../index.php, after the key has been checked; opened directly it
 * answers nothing. $conn, $action and the jarvis_api_* helpers come from there.
 *
 * View only: every action reads, none writes.
 *
 *   ?module=realestate&action=directory
 *        every active building, its unit numbers, and the email of each tenant on an
 *        active lease with the building and unit they rent. Jarvis's Tenant care inbox
 *        uses it to tell whether an email says which building and unit it is about —
 *        and, for a tenant it knows, to not have to ask.
 *
 * Left out on purpose: tenant names, phones, ID numbers, rents.
 *
 *   ?module=realestate&action=renewals_due&days=120
 *        every active lease that ends within `days` (1-365), with what Reem's Lease renewals
 *        needs to run its renewal by email: the tenant's name and email, the unit, the rent,
 *        the number of cheques, and whether saifsys already has a renewal workflow for it
 *        (Reem leaves those to the staff). The one action here that gives names and rents,
 *        because a renewal cannot be offered without them.
 *
 *   ?module=realestate&action=renewals_expired[&from=YYYY-MM-DD][&to=YYYY-MM-DD]
 *        every lease whose end date has passed and that was not renewed: no converted
 *        renewal workflow, and no later lease for the same tenant on the same unit. Without
 *        from/to it gives all of them; with them, only those that ended between the two.
 *        Same fields as renewals_due, plus the move-out date and saifsys's last workflow.
 *
 *   ?module=realestate&action=renewal_history&lease_id=N
 *        what the staff did on a lease's renewal in saifsys, newest renewal first: its status,
 *        who started it, who sent the notice, who rejected it, the rents, what the tenant
 *        answered, the staff's notes, the messages with the tenant and the log of every step
 *        with the name of who did it. Reem shows it when a person taps a renewal the staff
 *        ran themselves, so nobody has to open saifsys to learn why it stopped.
 *
 *   ?module=realestate&action=buildings_3d
 *        every active building with how many floors and units saifsys has for it: the
 *        list Reem's 3D Buildings screen picks from.
 *
 *   ?module=realestate&action=building_3d&id=N
 *        one building as Reem draws it in 3D: its floors, every unit on them (number, type,
 *        size, status, asking rent a year), and the active lease on each unit (tenant, end date,
 *        yearly rent). Tenant names and rents are given because tapping a unit shows them;
 *        Reem shows this screen only to the people the master grants it to.
 */

declare(strict_types=1);

if (!defined('JARVIS_API')) {
    http_response_code(404);
    exit;
}

if ($action === 'directory') {
    $buildings = $conn->query("SELECT id, name FROM re_buildings WHERE is_active = 1 ORDER BY name")
        ->fetchAll(PDO::FETCH_ASSOC);

    $units = [];
    foreach ($conn->query("SELECT u.building_id, u.unit_number FROM re_units u
            JOIN re_buildings b ON b.id = u.building_id AND b.is_active = 1
            ORDER BY u.building_id, u.unit_number")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $units[(int)$u['building_id']][] = (string)$u['unit_number'];
    }

    $tenants = $conn->query("SELECT DISTINCT LOWER(TRIM(t.email)) AS email, b.name AS building, u.unit_number AS unit
            FROM re_leases l
            JOIN re_tenants t ON t.id = l.tenant_id
            JOIN re_units u ON u.id = l.unit_id
            JOIN re_buildings b ON b.id = u.building_id
            WHERE l.status = 'active' AND t.email IS NOT NULL AND TRIM(t.email) <> ''
            ORDER BY email")->fetchAll(PDO::FETCH_ASSOC);

    jarvis_api_send([
        'ok' => true,
        'buildings' => array_map(fn($b) => [
            'name' => (string)$b['name'],
            'units' => $units[(int)$b['id']] ?? [],
        ], $buildings),
        'tenants' => $tenants,
    ]);
}

if ($action === 'renewals_due') {
    $days = max(1, min(365, (int)($_GET['days'] ?? 120)));
    $has = static function (string $table, string $column) use ($conn): bool {
        $st = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $st->execute([$table, $column]);
        return (bool)$st->fetchColumn();
    };
    $bedrooms = $has('re_units', 'bedrooms') ? 'u.bedrooms' : 'NULL';

    $st = $conn->prepare("
        SELECT l.id AS lease_id, l.company_id, l.lease_number, l.start_date, l.end_date,
               COALESCE(NULLIF(l.annual_rent, 0), l.monthly_rent * 12) AS annual_rent,
               l.number_of_installments, l.payment_method, l.ejari_registration_number,
               u.unit_number AS unit, u.unit_type, $bedrooms AS bedrooms, u.premises_number,
               b.name AS building, b.dewa_premises,
               t.first_name, t.last_name, t.company_name, LOWER(TRIM(t.email)) AS email,
               (SELECT rw.status FROM re_lease_renewal_workflows rw
                 WHERE rw.lease_id = l.id AND rw.status NOT IN ('rejected', 'cancelled')
                 ORDER BY rw.id DESC LIMIT 1) AS saifsys_renewal,
               (SELECT rw.status FROM re_lease_renewal_workflows rw
                 WHERE rw.lease_id = l.id ORDER BY rw.id DESC LIMIT 1) AS saifsys_last
        FROM re_leases l
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        JOIN re_tenants t ON t.id = l.tenant_id
        WHERE l.status = 'active'
          AND l.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
        ORDER BY l.end_date, b.name, u.unit_number
    ");
    $st->execute([$days]);

    jarvis_api_send([
        'ok' => true,
        'days' => $days,
        'leases' => array_map(static fn($r) => [
            'lease_id' => (int)$r['lease_id'],
            'company_id' => (int)$r['company_id'],
            'lease_number' => (string)$r['lease_number'],
            'building' => (string)$r['building'],
            'unit' => (string)$r['unit'],
            'unit_type' => (string)($r['unit_type'] ?? ''),
            'bedrooms' => $r['bedrooms'] !== null ? (int)$r['bedrooms'] : null,
            'premises_number' => (string)($r['premises_number'] ?: ($r['dewa_premises'] ?? '')),
            'tenant' => trim((string)($r['company_name'] ?? '')) !== '' ? trim((string)$r['company_name']) : trim($r['first_name'] . ' ' . $r['last_name']),
            'email' => (string)($r['email'] ?? ''),
            'start_date' => (string)$r['start_date'],
            'end_date' => (string)$r['end_date'],
            'annual_rent' => round((float)$r['annual_rent'], 2),
            'cheques' => (int)($r['number_of_installments'] ?: 4),
            'payment_method' => (string)($r['payment_method'] ?? ''),
            'ejari' => (string)($r['ejari_registration_number'] ?? ''),
            'saifsys_renewal' => $r['saifsys_renewal'] !== null ? (string)$r['saifsys_renewal'] : null,
            // Staff rejected the renewal (the tenant is leaving): Reem lists it apart, not as not started.
            'saifsys_rejected' => $r['saifsys_renewal'] === null && $r['saifsys_last'] === 'rejected',
        ], $st->fetchAll(PDO::FETCH_ASSOC)),
    ]);
}

if ($action === 'renewals_expired') {
    $date = static function (string $k): ?string {
        $v = trim((string)($_GET[$k] ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    };
    $from = $date('from');
    $to = $date('to');
    $has = static function (string $table, string $column) use ($conn): bool {
        $st = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $st->execute([$table, $column]);
        return (bool)$st->fetchColumn();
    };
    $bedrooms = $has('re_units', 'bedrooms') ? 'u.bedrooms' : 'NULL';

    $where = '';
    $params = [];
    if ($from) { $where .= ' AND l.end_date >= ?'; $params[] = $from; }
    if ($to) { $where .= ' AND l.end_date <= ?'; $params[] = $to; }

    // Renewed leases are marked expired too, so "not renewed" means: no converted workflow
    // and no newer lease for the same tenant on the same unit (staff may renew by hand).
    $st = $conn->prepare("
        SELECT l.id AS lease_id, l.company_id, l.lease_number, l.start_date, l.end_date, l.status, l.move_out_date,
               COALESCE(NULLIF(l.annual_rent, 0), l.monthly_rent * 12) AS annual_rent,
               l.number_of_installments, l.payment_method, l.ejari_registration_number,
               u.unit_number AS unit, u.unit_type, $bedrooms AS bedrooms, u.premises_number,
               b.name AS building, b.dewa_premises,
               t.first_name, t.last_name, t.company_name, LOWER(TRIM(t.email)) AS email,
               (SELECT rw.status FROM re_lease_renewal_workflows rw
                 WHERE rw.lease_id = l.id ORDER BY rw.id DESC LIMIT 1) AS saifsys_last
        FROM re_leases l
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        JOIN re_tenants t ON t.id = l.tenant_id
        WHERE l.status IN ('active', 'expired')
          AND l.end_date < CURDATE() $where
          AND NOT EXISTS (SELECT 1 FROM re_lease_renewal_workflows rw WHERE rw.lease_id = l.id AND rw.status = 'converted')
          AND NOT EXISTS (SELECT 1 FROM re_leases n
                          WHERE n.unit_id = l.unit_id AND n.tenant_id = l.tenant_id AND n.id <> l.id
                            AND n.start_date > l.start_date AND n.status NOT IN ('cancelled', 'terminated'))
        ORDER BY l.end_date DESC, b.name, u.unit_number
    ");
    $st->execute($params);

    jarvis_api_send([
        'ok' => true,
        'leases' => array_map(static fn($r) => [
            'lease_id' => (int)$r['lease_id'],
            'company_id' => (int)$r['company_id'],
            'lease_number' => (string)$r['lease_number'],
            'building' => (string)$r['building'],
            'unit' => (string)$r['unit'],
            'unit_type' => (string)($r['unit_type'] ?? ''),
            'bedrooms' => $r['bedrooms'] !== null ? (int)$r['bedrooms'] : null,
            'premises_number' => (string)($r['premises_number'] ?: ($r['dewa_premises'] ?? '')),
            'tenant' => trim((string)($r['company_name'] ?? '')) !== '' ? trim((string)$r['company_name']) : trim($r['first_name'] . ' ' . $r['last_name']),
            'email' => (string)($r['email'] ?? ''),
            'start_date' => (string)$r['start_date'],
            'end_date' => (string)$r['end_date'],
            'status' => (string)$r['status'],
            'move_out_date' => $r['move_out_date'] ? (string)$r['move_out_date'] : null,
            'annual_rent' => round((float)$r['annual_rent'], 2),
            'cheques' => (int)($r['number_of_installments'] ?: 4),
            'payment_method' => (string)($r['payment_method'] ?? ''),
            'ejari' => (string)($r['ejari_registration_number'] ?? ''),
            'saifsys_last' => $r['saifsys_last'] !== null ? (string)$r['saifsys_last'] : null,
        ], $st->fetchAll(PDO::FETCH_ASSOC)),
    ]);
}

if ($action === 'renewal_history') {
    $leaseId = (int)($_GET['lease_id'] ?? 0);
    if ($leaseId <= 0) {
        jarvis_api_error('bad_request', 'lease_id is required.', 400);
    }
    $name = static fn(string $a): string => "COALESCE(NULLIF(TRIM($a.fullname), ''), $a.username)";

    $st = $conn->prepare("
        SELECT rw.*,
               {$name('uc')} AS created_by_name, {$name('ua')} AS assigned_to_name,
               {$name('un')} AS notice_sent_by_name, {$name('ur')} AS rejected_by_name,
               {$name('up')} AS approved_by_name, {$name('uv')} AS converted_by_name
        FROM re_lease_renewal_workflows rw
        LEFT JOIN user uc ON uc.id = rw.created_by
        LEFT JOIN user ua ON ua.id = rw.assigned_to
        LEFT JOIN user un ON un.id = rw.notice_sent_by
        LEFT JOIN user ur ON ur.id = rw.rejected_by
        LEFT JOIN user up ON up.id = rw.approved_by
        LEFT JOIN user uv ON uv.id = rw.converted_by
        WHERE rw.lease_id = ?
        ORDER BY rw.id DESC LIMIT 5
    ");
    $st->execute([$leaseId]);
    $workflows = $st->fetchAll(PDO::FETCH_ASSOC);

    // The log and the messages live in tables added later; a server without them still answers.
    $rows = static function (string $sql, int $workflowId) use ($conn): array {
        try {
            $q = $conn->prepare($sql);
            $q->execute([$workflowId]);
            return $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    };
    $str = static fn($v): ?string => $v !== null && trim((string)$v) !== '' ? trim((string)$v) : null;
    $num = static fn($v): ?float => $v !== null ? round((float)$v, 2) : null;

    jarvis_api_send([
        'ok' => true,
        'lease_id' => $leaseId,
        'renewals' => array_map(static function (array $w) use ($rows, $name, $str, $num): array {
            $id = (int)$w['id'];
            $events = $rows("
                SELECT e.event_type, e.message, e.created_at, e.tenant_portal_user_id, {$name('u')} AS by_name
                FROM re_renewal_portal_events e LEFT JOIN user u ON u.id = e.legacy_user_id
                WHERE e.workflow_id = ? ORDER BY e.created_at, e.id LIMIT 300", $id);
            $messages = $rows("
                SELECT m.author_role, m.body, m.created_at, {$name('u')} AS by_name
                FROM re_renewal_negotiation_messages m LEFT JOIN user u ON u.id = m.admin_user_id
                WHERE m.workflow_id = ? ORDER BY m.created_at, m.id LIMIT 200", $id);
            return [
                'workflow_id' => $id,
                'status' => (string)$w['status'],
                'started_at' => $str($w['created_at'] ?? null),
                'started_by' => $str($w['created_by_name'] ?? null),
                'assigned_to' => $str($w['assigned_to_name'] ?? null),
                'notice_sent_at' => $str($w['notice_sent_at'] ?? null),
                'notice_sent_by' => $str($w['notice_sent_by_name'] ?? null),
                'rejected_by' => $str($w['rejected_by_name'] ?? null),
                'approved_by' => $str($w['approved_by_name'] ?? null),
                'converted_by' => $str($w['converted_by_name'] ?? null),
                'updated_at' => $str($w['updated_at'] ?? null),
                'current_rent' => $num($w['current_rent'] ?? null),
                'proposed_rent' => $num($w['proposed_rent'] ?? null),
                'cheques' => isset($w['number_of_cheques']) ? (int)$w['number_of_cheques'] : null,
                // What the tenant chose on the notice: accept, negotiate or reject.
                'tenant_decision' => $str($w['tenant_portal_decision'] ?? null),
                'tenant_decision_at' => $str($w['tenant_portal_decision_at'] ?? ($w['tenant_response_date'] ?? null)),
                'tenant_opened_at' => $str($w['tenant_first_viewed_at'] ?? null),
                'tenant_response' => $str($w['tenant_response'] ?? null),
                'staff_notes' => $str($w['negotiation_notes'] ?? null),
                'messages' => array_map(static fn($m) => [
                    'from' => $m['author_role'] === 'tenant' ? 'tenant' : 'staff',
                    'by' => $m['author_role'] === 'tenant' ? null : $str($m['by_name']),
                    'text' => (string)$m['body'],
                    'at' => (string)$m['created_at'],
                ], $messages),
                'events' => array_map(static fn($e) => [
                    'type' => (string)$e['event_type'],
                    'text' => (string)($e['message'] ?? ''),
                    // A step with a portal user on it is the tenant's own; the others are staff.
                    'by' => $e['tenant_portal_user_id'] !== null ? 'Tenant' : $str($e['by_name']),
                    'tenant' => $e['tenant_portal_user_id'] !== null,
                    'at' => (string)$e['created_at'],
                ], $events),
            ];
        }, $workflows),
    ]);
}

if ($action === 'buildings_3d') {
    $rows = $conn->query("
        SELECT b.id, b.name, b.address, b.total_floors,
               (SELECT COUNT(*) FROM re_floors f WHERE f.building_id = b.id) AS floors,
               (SELECT COUNT(*) FROM re_units u WHERE u.building_id = b.id) AS units
        FROM re_buildings b
        WHERE b.is_active = 1
        ORDER BY b.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    jarvis_api_send([
        'ok' => true,
        'buildings' => array_map(static fn($b) => [
            'id' => (int)$b['id'],
            'name' => (string)$b['name'],
            'address' => (string)($b['address'] ?? ''),
            // The floors saifsys has set up; with none, the number typed on the building.
            'floors' => (int)$b['floors'] ?: (int)($b['total_floors'] ?? 0),
            'units' => (int)$b['units'],
        ], $rows),
    ]);
}

if ($action === 'building_3d') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("SELECT id, name, address, total_floors FROM re_buildings WHERE id = ? AND is_active = 1");
    $st->execute([$id]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) {
        jarvis_api_error('not_found', 'No such building.', 404);
    }

    $st = $conn->prepare("SELECT floor_number, name FROM re_floors WHERE building_id = ? ORDER BY floor_number");
    $st->execute([$id]);
    $floors = $st->fetchAll(PDO::FETCH_ASSOC);

    // The asking rent is a year's on the live table; older copies of saifsys may not have it.
    $has = static function (string $column) use ($conn): bool {
        $st = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_units' AND COLUMN_NAME = ? LIMIT 1");
        $st->execute([$column]);
        return (bool)$st->fetchColumn();
    };
    $rent = $has('annual_rent') ? 'u.annual_rent' : 'NULL';

    $st = $conn->prepare("
        SELECT u.id, u.unit_number, u.unit_type, u.area_sqm, u.status, $rent AS annual_rent,
               f.floor_number, f.name AS floor_name
        FROM re_units u
        LEFT JOIN re_floors f ON f.id = u.floor_id
        WHERE u.building_id = ?
        ORDER BY f.floor_number, u.unit_number
    ");
    $st->execute([$id]);
    $units = $st->fetchAll(PDO::FETCH_ASSOC);

    // The lease running on each unit today: the latest active one, if a unit has two.
    $st = $conn->prepare("
        SELECT l.unit_id, l.start_date, l.end_date,
               COALESCE(NULLIF(l.annual_rent, 0), l.monthly_rent * 12) AS annual_rent,
               t.first_name, t.last_name, t.company_name
        FROM re_leases l
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_tenants t ON t.id = l.tenant_id
        WHERE u.building_id = ? AND l.status = 'active'
        ORDER BY l.start_date
    ");
    $st->execute([$id]);
    $leases = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $leases[(int)$l['unit_id']] = [
            'tenant' => trim((string)($l['company_name'] ?? '')) !== '' ? trim((string)$l['company_name']) : trim($l['first_name'] . ' ' . $l['last_name']),
            'start_date' => (string)$l['start_date'],
            'end_date' => (string)$l['end_date'],
            'annual_rent' => round((float)$l['annual_rent'], 2),
        ];
    }

    jarvis_api_send([
        'ok' => true,
        'building' => [
            'id' => (int)$b['id'],
            'name' => (string)$b['name'],
            'address' => (string)($b['address'] ?? ''),
            'total_floors' => $b['total_floors'] !== null ? (int)$b['total_floors'] : null,
        ],
        'floors' => array_map(static fn($f) => [
            'number' => (int)$f['floor_number'],
            'name' => (string)($f['name'] ?? ''),
        ], $floors),
        'units' => array_map(static fn($u) => [
            'id' => (int)$u['id'],
            'unit' => (string)$u['unit_number'],
            'type' => (string)($u['unit_type'] ?? ''),
            'area_sqm' => $u['area_sqm'] !== null ? (float)$u['area_sqm'] : null,
            'status' => (string)$u['status'],
            'annual_rent' => $u['annual_rent'] !== null ? round((float)$u['annual_rent'], 2) : null,
            'floor' => $u['floor_number'] !== null ? (int)$u['floor_number'] : null,
            'floor_name' => (string)($u['floor_name'] ?? ''),
            'lease' => $leases[(int)$u['id']] ?? null,
        ], $units),
    ]);
}

jarvis_api_error('not_found', 'Unknown Real Estate action.', 404);
