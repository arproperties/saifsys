<?php
/**
 * AJAX endpoint for live search - Units
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/includes/unit_public_listing_helper.php';

header('Content-Type: application/json');

require_login();
$currentCompanyId = current_company_id($conn) ?: 1;
re_unit_public_ensure_schema($conn);

// Get filter parameters
$buildingFilter = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : null;
$statusFilter = !empty($_GET['status']) ? $_GET['status'] : '';
$typeFilter = !empty($_GET['unit_type']) ? $_GET['unit_type'] : '';
$floorFilter = !empty($_GET['floor_id']) ? (int)$_GET['floor_id'] : null;
$searchQuery = !empty($_GET['search']) ? trim($_GET['search']) : '';
$mobileFilter = $_GET['mobile_listing'] ?? '';
$marketingFilter = $_GET['marketing_status'] ?? '';

// Get units with filters
$where = ["u.company_id = ?"];
$params = [$currentCompanyId];
$effectiveStatusExpr = "
    CASE
        WHEN l.id IS NOT NULL THEN 'occupied'
        WHEN u.rental_mode IN ('short_term', 'both') AND ab.id IS NOT NULL THEN 'occupied'
        WHEN u.rental_mode IN ('short_term', 'both') AND u.status IN ('maintenance', 'reserved') THEN u.status
        WHEN u.rental_mode IN ('short_term', 'both') THEN 'vacant'
        ELSE u.status
    END
";
if ($buildingFilter) {
    $where[] = "u.building_id = ?";
    $params[] = $buildingFilter;
}
if ($statusFilter) {
    $where[] = "$effectiveStatusExpr = ?";
    $params[] = $statusFilter;
}
if ($typeFilter) {
    $where[] = "u.unit_type = ?";
    $params[] = $typeFilter;
}
if ($floorFilter) {
    $where[] = "u.floor_id = ?";
    $params[] = $floorFilter;
}
if ($mobileFilter === 'listed') {
    $where[] = "u.publish_to_mobile = 1";
} elseif ($mobileFilter === 'unlisted') {
    $where[] = "COALESCE(u.publish_to_mobile, 0) = 0";
}
if ($marketingFilter && in_array($marketingFilter, ['ready_to_move', 'under_maintenance', 'coming_soon', 'reserved'], true)) {
    $where[] = "u.marketing_status = ?";
    $params[] = $marketingFilter;
}
if ($searchQuery) {
    $where[] = "(u.unit_number LIKE ? OR b.name LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ? OR CONCAT(t.first_name, ' ', t.last_name) LIKE ? OR ag.first_name LIKE ? OR ag.last_name LIKE ? OR CONCAT(ag.first_name, ' ', ag.last_name) LIKE ? OR ab.booking_number LIKE ?)";
    $searchParam = '%' . $searchQuery . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$activeLeaseJoin = "
    LEFT JOIN re_leases l ON l.id = (
        SELECT l2.id
        FROM re_leases l2
        LEFT JOIN re_lease_units lu2 ON lu2.lease_id = l2.id
        WHERE l2.company_id = u.company_id
          AND l2.status = 'active'
          AND (l2.unit_id = u.id OR lu2.unit_id = u.id)
        ORDER BY l2.end_date DESC, l2.id DESC
        LIMIT 1
    )
";
$activeBookingJoin = "
    LEFT JOIN ars_bookings ab ON ab.id = (
        SELECT ab2.id
        FROM ars_bookings ab2
        WHERE ab2.unit_id = u.id
          AND ab2.status NOT IN ('cancelled', 'expired')
          AND CURDATE() >= ab2.check_in
          AND CURDATE() < ab2.check_out
        ORDER BY ab2.check_in DESC, ab2.id DESC
        LIMIT 1
    )
";

$statsSql = "
    SELECT
        COUNT(*) as total_units,
        COUNT(CASE WHEN $effectiveStatusExpr = 'occupied' THEN 1 END) as occupied_count,
        COUNT(CASE WHEN $effectiveStatusExpr = 'vacant' THEN 1 END) as vacant_count,
        COUNT(CASE WHEN $effectiveStatusExpr = 'maintenance' THEN 1 END) as maintenance_count,
        COUNT(CASE WHEN $effectiveStatusExpr = 'reserved' THEN 1 END) as reserved_count,
        COALESCE(SUM(CASE WHEN $effectiveStatusExpr = 'occupied' THEN u.annual_rent ELSE 0 END), 0) as total_annual_rent
    FROM re_units u
    JOIN re_buildings b ON b.id = u.building_id
    $activeLeaseJoin
    $activeBookingJoin
    LEFT JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN ars_guests ag ON ag.id = ab.guest_id
    WHERE " . implode(' AND ', $where) . "
";
$statsStmt = $conn->prepare($statsSql);
$statsStmt->execute($params);
$statistics = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$sql = "
    SELECT u.*, b.name as building_name,
           t.first_name, t.last_name,
           l.end_date as lease_end_date,
           ab.id as booking_id, ab.booking_number, ab.check_out as booking_check_out,
           ag.first_name as booking_first_name, ag.last_name as booking_last_name,
           $effectiveStatusExpr as effective_status
    FROM re_units u
    JOIN re_buildings b ON b.id = u.building_id
    $activeLeaseJoin
    $activeBookingJoin
    LEFT JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN ars_guests ag ON ag.id = ab.guest_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY b.name, u.unit_number
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate table HTML
ob_start();
if (empty($units)):
?>
    <tr>
        <td colspan="10" class="text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
            No units found
        </td>
    </tr>
<?php
else:
    $currentBuilding = '';
    foreach ($units as $unit):
        // Group by building if no building filter is applied
        if (!$buildingFilter && $currentBuilding !== $unit['building_name']):
            $currentBuilding = $unit['building_name'];
?>
    <tr class="table-secondary">
        <td colspan="10" class="fw-bold">
            <i class="bi bi-building"></i> <?= htmlspecialchars($currentBuilding, ENT_QUOTES, 'UTF-8') ?>
        </td>
    </tr>
<?php
        endif;
        $statusClass = [
            'vacant' => 'success',
            'occupied' => 'primary',
            'maintenance' => 'warning',
            'reserved' => 'info'
        ];
        $displayStatus = $unit['effective_status'] ?? $unit['status'];
        $class = $statusClass[$displayStatus] ?? 'secondary';
?>
    <tr>
        <td><?= htmlspecialchars($unit['building_name'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><strong class="text-primary"><?= htmlspecialchars($unit['unit_number'], ENT_QUOTES, 'UTF-8') ?></strong></td>
        <td>
            <span class="badge bg-light text-dark"><?= htmlspecialchars($unit['unit_type'] ?: '-', ENT_QUOTES, 'UTF-8') ?></span>
        </td>
        <td>
            <span class="badge bg-<?= $class ?>"><?= ucfirst(htmlspecialchars($displayStatus, ENT_QUOTES, 'UTF-8')) ?></span>
        </td>
        <td>
            <?php if ($unit['annual_rent']): ?>
                <strong><?= number_format($unit['annual_rent'], 2) ?> AED</strong>
            <?php else: ?>
                <span class="text-muted">-</span>
            <?php endif; ?>
        </td>
        <td>
            <?php
            $rm = $unit['rental_mode'] ?? 'long_term';
            $rmLabels = ['long_term' => 'Long Term', 'short_term' => 'Short Term', 'both' => 'Both'];
            $rmBadge  = ['long_term' => 'secondary', 'short_term' => 'info', 'both' => 'primary'];
            ?>
            <span class="badge bg-<?= $rmBadge[$rm] ?? 'secondary' ?>"><?= $rmLabels[$rm] ?? ucfirst($rm) ?></span>
        </td>
        <td>
            <?php if (!empty($unit['publish_to_mobile'])): ?>
                <span class="badge bg-success">Listed</span>
                <br><small class="text-muted"><?= htmlspecialchars(re_unit_public_status_label($unit['marketing_status'] ?? 'ready_to_move'), ENT_QUOTES, 'UTF-8') ?></small>
                <?php if (!empty($unit['featured'])): ?><br><span class="badge bg-warning text-dark">Featured</span><?php endif; ?>
            <?php else: ?>
                <span class="badge bg-light text-dark border">Hidden</span>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($unit['first_name']): ?>
                <i class="bi bi-person-circle"></i> <?= htmlspecialchars($unit['first_name'] . ' ' . $unit['last_name'], ENT_QUOTES, 'UTF-8') ?>
            <?php elseif (!empty($unit['booking_first_name']) || !empty($unit['booking_last_name'])): ?>
                <i class="bi bi-calendar-check"></i> <?= htmlspecialchars(trim(($unit['booking_first_name'] ?? '') . ' ' . ($unit['booking_last_name'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                <?php if (!empty($unit['booking_number'])): ?>
                    <br><small class="text-muted">Booking <?= htmlspecialchars($unit['booking_number'], ENT_QUOTES, 'UTF-8') ?></small>
                <?php endif; ?>
            <?php else: ?>
                <span class="text-muted">-</span>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($unit['lease_end_date']): ?>
                <?= date('Y-m-d', strtotime($unit['lease_end_date'])) ?>
            <?php elseif (!empty($unit['booking_check_out'])): ?>
                <?= date('Y-m-d', strtotime($unit['booking_check_out'])) ?>
            <?php else: ?>
                <span class="text-muted">-</span>
            <?php endif; ?>
        </td>
        <td>
            <div class="btn-group btn-group-sm" role="group">
                <a href="unit_view.php?id=<?= $unit['id'] ?>" class="btn btn-outline-primary" title="View Unit">
                    <i class="bi bi-eye"></i>
                </a>
            </div>
        </td>
    </tr>
<?php
    endforeach;
endif;
$tableHtml = ob_get_clean();

echo json_encode([
    'success' => true,
    'count' => count($units),
    'statistics' => [
        'total_units' => (int)($statistics['total_units'] ?? 0),
        'occupied_count' => (int)($statistics['occupied_count'] ?? 0),
        'vacant_count' => (int)($statistics['vacant_count'] ?? 0),
        'maintenance_count' => (int)($statistics['maintenance_count'] ?? 0),
        'reserved_count' => (int)($statistics['reserved_count'] ?? 0),
        'total_annual_rent' => (float)($statistics['total_annual_rent'] ?? 0),
    ],
    'html' => $tableHtml
]);
