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
                 ORDER BY rw.id DESC LIMIT 1) AS saifsys_renewal
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
        ], $st->fetchAll(PDO::FETCH_ASSOC)),
    ]);
}

jarvis_api_error('not_found', 'Unknown Real Estate action.', 404);
