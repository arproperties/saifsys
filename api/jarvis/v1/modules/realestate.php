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

jarvis_api_error('not_found', 'Unknown Real Estate action.', 404);
