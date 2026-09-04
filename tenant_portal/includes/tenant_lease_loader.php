<?php
/**
 * Tenant Portal — Load current tenant's lease (and unit) for scoped pages.
 * Include after require_tenant_login($conn). Sets $lease (array or null) and $unit_id.
 */
$lease = null;
$unit_id = null;
if (isset($conn) && $conn instanceof PDO) {
    $lease_id = current_tenant_lease_id($conn);
    if ($lease_id) {
        $stmt = $conn->prepare("
            SELECT l.*, l.id AS lease_id,
                   u.id AS unit_id, u.unit_number, u.unit_type, u.area_sqm,
                   b.name AS building_name, b.address AS building_address,
                   t.first_name, t.last_name, t.email, t.phone
            FROM re_leases l
            JOIN re_units u ON u.id = l.unit_id
            JOIN re_buildings b ON b.id = u.building_id
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE l.id = ?
        ");
        $stmt->execute([$lease_id]);
        $lease = $stmt->fetch(PDO::FETCH_ASSOC);
        $unit_id = $lease ? (int)$lease['unit_id'] : null;
    }
}
