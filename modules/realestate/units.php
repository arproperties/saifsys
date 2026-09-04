<?php
/**
 * Real Estate Module - Units Management
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/unit_public_listing_helper.php';

require_login();
// Check department access (backward compatible: fallback to module access)
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_CORE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
re_unit_public_ensure_schema($conn);
$success = '';
$error = '';

function re_units_table_exists(PDO $conn, string $table): bool {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function re_units_count(PDO $conn, string $sql, array $params): int {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_unit') {
    try {
        csrf_verify();
        $deleteUnitId = (int)($_POST['unit_id'] ?? 0);
        if ($deleteUnitId <= 0) throw new RuntimeException('Invalid unit.');

        $unitStmt = $conn->prepare("SELECT id, unit_number FROM re_units WHERE id = ? AND company_id = ? LIMIT 1");
        $unitStmt->execute([$deleteUnitId, $currentCompanyId]);
        $deleteUnit = $unitStmt->fetch(PDO::FETCH_ASSOC);
        if (!$deleteUnit) throw new RuntimeException('Unit not found.');

        $leaseLinks = re_units_count($conn, "SELECT COUNT(*) FROM re_leases WHERE company_id = ? AND unit_id = ?", [$currentCompanyId, $deleteUnitId])
            + re_units_count($conn, "SELECT COUNT(*) FROM re_lease_units WHERE company_id = ? AND unit_id = ?", [$currentCompanyId, $deleteUnitId]);
        if ($leaseLinks > 0) throw new RuntimeException('Cannot delete this unit because it has lease history. Archive or correct the related lease first.');

        if (re_units_table_exists($conn, 'ars_bookings')) {
            $bookingLinks = re_units_count($conn, "SELECT COUNT(*) FROM ars_bookings WHERE unit_id = ?", [$deleteUnitId]);
            if ($bookingLinks > 0) throw new RuntimeException('Cannot delete this unit because it has ARS booking history.');
        }
        if (re_units_table_exists($conn, 're_maintenance_requests')) {
            $maintenanceLinks = re_units_count($conn, "SELECT COUNT(*) FROM re_maintenance_requests WHERE company_id = ? AND unit_id = ?", [$currentCompanyId, $deleteUnitId]);
            if ($maintenanceLinks > 0) throw new RuntimeException('Cannot delete this unit because it has maintenance history.');
        }

        $conn->beginTransaction();
        foreach ([
            're_unit_public_media',
            're_unit_viewing_requests',
            're_unit_lease_applications',
            're_unit_status_history',
            're_unit_legal_status',
        ] as $table) {
            if (re_units_table_exists($conn, $table)) {
                $conn->prepare("DELETE FROM `$table` WHERE unit_id = ?")->execute([$deleteUnitId]);
            }
        }
        if (re_units_table_exists($conn, 're_documents')) {
            $conn->prepare("DELETE FROM re_documents WHERE related_type = 'unit' AND related_id = ?")->execute([$deleteUnitId]);
        }
        if (re_units_table_exists($conn, 're_missing_documents')) {
            $conn->prepare("DELETE FROM re_missing_documents WHERE related_type = 'unit' AND related_id = ?")->execute([$deleteUnitId]);
        }
        if (re_units_table_exists($conn, 're_tasks')) {
            $conn->prepare("DELETE FROM re_tasks WHERE related_type = 'unit' AND related_id = ?")->execute([$deleteUnitId]);
        }
        $conn->prepare("DELETE FROM re_units WHERE id = ? AND company_id = ?")->execute([$deleteUnitId, $currentCompanyId]);
        $conn->commit();
        $success = 'Unit ' . $deleteUnit['unit_number'] . ' deleted successfully.';
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $error = $e->getMessage();
    }
}

// Get filter parameters
$buildingFilter = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : null;
$statusFilter = !empty($_GET['status']) ? $_GET['status'] : '';
$typeFilter = !empty($_GET['unit_type']) ? $_GET['unit_type'] : '';
$floorFilter = !empty($_GET['floor_id']) ? (int)$_GET['floor_id'] : null;
$searchQuery = !empty($_GET['search']) ? trim($_GET['search']) : '';
$mobileFilter = $_GET['mobile_listing'] ?? '';
$marketingFilter = $_GET['marketing_status'] ?? '';

// Get buildings for filter
$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

// Get floors for filter (based on selected building or all)
$floors = [];
if ($buildingFilter) {
    $floorsStmt = $conn->prepare("
        SELECT id, floor_number, name,
               CONCAT(COALESCE(name, CONCAT('Floor ', floor_number)), ' (', floor_number, ')') as display_name
        FROM re_floors 
        WHERE building_id = ? 
        ORDER BY floor_number
    ");
    $floorsStmt->execute([$buildingFilter]);
    $floors = $floorsStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Get all floors across all buildings
    $floorsStmt = $conn->prepare("
        SELECT f.id, f.floor_number, f.name, b.name as building_name,
               CONCAT(b.name, ' - ', COALESCE(f.name, CONCAT('Floor ', f.floor_number)), ' (', f.floor_number, ')') as display_name
        FROM re_floors f
        JOIN re_buildings b ON b.id = f.building_id
        WHERE b.company_id = ?
        ORDER BY b.name, f.floor_number
    ");
    $floorsStmt->execute([$currentCompanyId]);
    $floors = $floorsStmt->fetchAll(PDO::FETCH_ASSOC);
}

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
    $where[] = "(u.unit_number LIKE ? OR b.name LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ? OR t.company_name LIKE ? OR CONCAT(t.first_name, ' ', t.last_name) LIKE ? OR ag.first_name LIKE ? OR ag.last_name LIKE ? OR CONCAT(ag.first_name, ' ', ag.last_name) LIKE ? OR ab.booking_number LIKE ?)";
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
    $params[] = $searchParam;
}

$activeLeaseJoin = "
    LEFT JOIN re_leases l ON l.id = (
        SELECT l2.id
        FROM re_leases l2
        WHERE l2.company_id = u.company_id
          AND l2.status = 'active'
          AND (
              l2.unit_id = u.id
              OR EXISTS (
                  SELECT 1
                  FROM re_lease_units lu2
                  WHERE lu2.lease_id = l2.id
                    AND lu2.company_id = l2.company_id
                    AND lu2.unit_id = u.id
              )
          )
        ORDER BY CASE WHEN CURDATE() BETWEEN l2.start_date AND l2.end_date THEN 0 ELSE 1 END, l2.end_date DESC, l2.id DESC
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

// Get statistics using the same filters and the same effective occupancy logic as the table.
$stats = $conn->prepare("
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
");
$stats->execute($params);
$statistics = $stats->fetch(PDO::FETCH_ASSOC);

$units = $conn->prepare("
    SELECT u.*, b.name as building_name, f.floor_number,
           l.id as lease_id, l.status as lease_status, l.end_date as lease_end_date,
           t.first_name, t.last_name, t.company_name, t.tenant_type,
           ab.id as booking_id, ab.booking_number, ab.check_out as booking_check_out,
           ag.first_name as booking_first_name, ag.last_name as booking_last_name,
           $effectiveStatusExpr as effective_status
    FROM re_units u
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_floors f ON f.id = u.floor_id
    $activeLeaseJoin
    $activeBookingJoin
    LEFT JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN ars_guests ag ON ag.id = ab.guest_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY b.name, u.unit_number
");
$units->execute($params);
$units = $units->fetchAll(PDO::FETCH_ASSOC);

// Get unique unit types for filter
$unitTypes = $conn->prepare("SELECT DISTINCT unit_type FROM re_units WHERE company_id = ? AND unit_type IS NOT NULL AND unit_type != '' ORDER BY unit_type");
$unitTypes->execute([$currentCompanyId]);
$unitTypes = $unitTypes->fetchAll(PDO::FETCH_COLUMN);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Units';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label">Units</div>
            <a href="units_add.php" class="btn btn-primary" style="background-color: var(--primary); border-color: var(--primary);">
                <i class="bi bi-plus-circle"></i> Add Unit
            </a>
        </div>
        <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card card-round border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                    <i class="bi bi-building text-primary fs-4"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <div class="text-muted small">Total Units</div>
                                <div class="h4 mb-0" id="statTotalUnits"><?= number_format($statistics['total_units']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card card-round border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-success bg-opacity-10 rounded-circle p-3">
                                    <i class="bi bi-check-circle text-success fs-4"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <div class="text-muted small">Occupied</div>
                                <div class="h4 mb-0" id="statOccupiedUnits"><?= number_format($statistics['occupied_count']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card card-round border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                                    <i class="bi bi-door-open text-warning fs-4"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <div class="text-muted small">Vacant</div>
                                <div class="h4 mb-0" id="statVacantUnits"><?= number_format($statistics['vacant_count']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card card-round border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-info bg-opacity-10 rounded-circle p-3">
                                    <i class="bi bi-currency-exchange text-info fs-4"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <div class="text-muted small">Annual Rent (Occupied)</div>
                                <div class="h5 mb-0" id="statAnnualRent"><?= number_format($statistics['total_annual_rent'], 0) ?> AED</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Advanced Filters -->
        <div class="card card-round mb-4">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters & Search</h6>
            </div>
            <div class="card-body">
                <form method="GET" id="filterForm" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><i class="bi bi-building"></i> Building</label>
                        <select name="building_id" class="form-select">
                            <option value="">All Buildings</option>
                            <?php foreach ($buildings as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $buildingFilter == $b['id'] ? 'selected' : '' ?>>
                                    <?= h($b['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><i class="bi bi-tag"></i> Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="occupied" <?= $statusFilter === 'occupied' ? 'selected' : '' ?>>Occupied</option>
                            <option value="vacant" <?= $statusFilter === 'vacant' ? 'selected' : '' ?>>Vacant</option>
                            <option value="maintenance" <?= $statusFilter === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                            <option value="reserved" <?= $statusFilter === 'reserved' ? 'selected' : '' ?>>Reserved</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><i class="bi bi-grid"></i> Type</label>
                        <select name="unit_type" class="form-select">
                            <option value="">All Types</option>
                            <?php foreach ($unitTypes as $type): ?>
                                <option value="<?= h($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>>
                                    <?= h($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><i class="bi bi-layers"></i> Floor</label>
                        <select name="floor_id" class="form-select" id="floorSelect">
                            <option value="">All Floors</option>
                            <?php foreach ($floors as $floor): ?>
                                <option value="<?= $floor['id'] ?>" <?= $floorFilter == $floor['id'] ? 'selected' : '' ?>>
                                    <?= h($floor['display_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><i class="bi bi-search"></i> Search</label>
                        <input type="text" name="search" id="searchInput" class="form-control" placeholder="Unit #, Building, Tenant..." value="<?= h($searchQuery) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><i class="bi bi-phone"></i> Mobile</label>
                        <select name="mobile_listing" class="form-select">
                            <option value="">All</option>
                            <option value="listed" <?= $mobileFilter === 'listed' ? 'selected' : '' ?>>Listed</option>
                            <option value="unlisted" <?= $mobileFilter === 'unlisted' ? 'selected' : '' ?>>Unlisted</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><i class="bi bi-stars"></i> Marketing</label>
                        <select name="marketing_status" class="form-select">
                            <option value="">All</option>
                            <option value="ready_to_move" <?= $marketingFilter === 'ready_to_move' ? 'selected' : '' ?>>Ready</option>
                            <option value="under_maintenance" <?= $marketingFilter === 'under_maintenance' ? 'selected' : '' ?>>Maintenance</option>
                            <option value="coming_soon" <?= $marketingFilter === 'coming_soon' ? 'selected' : '' ?>>Coming Soon</option>
                            <option value="reserved" <?= $marketingFilter === 'reserved' ? 'selected' : '' ?>>Reserved</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-funnel-fill"></i> Apply
                        </button>
                    </div>
                    <?php if ($buildingFilter || $statusFilter || $typeFilter || $floorFilter || $searchQuery || $mobileFilter || $marketingFilter): ?>
                    <div class="col-12">
                        <a href="units.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Clear Filters
                        </a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

<script>
// Live search with debouncing (AJAX - no page reload). Run after DOM ready so #unitsTableBody exists.
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const filterForm = document.getElementById('filterForm');
    const unitsTableBody = document.getElementById('unitsTableBody');
    const unitsCount = document.getElementById('unitsCount');
    let searchTimeout;
    
    function updateTable() {
        const formData = new FormData(filterForm);
        const params = new URLSearchParams();
        for (const [key, value] of formData.entries()) {
            if (value) params.append(key, value);
        }
        
        if (unitsTableBody) {
            unitsTableBody.innerHTML = '<tr><td colspan="10" class="text-center py-3"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading...</td></tr>';
        }
        
        fetch('ajax_search_units.php?' + params.toString())
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (unitsTableBody) unitsTableBody.innerHTML = data.html;
                    if (unitsCount) unitsCount.textContent = data.count;
                    if (data.statistics) {
                        const fmt = new Intl.NumberFormat('en-US');
                        const totalEl = document.getElementById('statTotalUnits');
                        const occupiedEl = document.getElementById('statOccupiedUnits');
                        const vacantEl = document.getElementById('statVacantUnits');
                        const rentEl = document.getElementById('statAnnualRent');
                        if (totalEl) totalEl.textContent = fmt.format(parseInt(data.statistics.total_units || 0, 10));
                        if (occupiedEl) occupiedEl.textContent = fmt.format(parseInt(data.statistics.occupied_count || 0, 10));
                        if (vacantEl) vacantEl.textContent = fmt.format(parseInt(data.statistics.vacant_count || 0, 10));
                        if (rentEl) rentEl.textContent = fmt.format(parseFloat(data.statistics.total_annual_rent || 0)) + ' AED';
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (unitsTableBody) {
                    unitsTableBody.innerHTML = '<tr><td colspan="10" class="text-center text-danger py-3">Error loading data. Please refresh the page.</td></tr>';
                }
            });
    }
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(updateTable, 500);
        });
    }

    // Update floors when building changes
    const buildingSelect = document.querySelector('select[name="building_id"]');
    const floorSelect = document.getElementById('floorSelect');
    if (buildingSelect && floorSelect) {
        buildingSelect.addEventListener('change', function() {
            const buildingId = this.value;
            floorSelect.innerHTML = '<option value="">Loading...</option>';
            
            if (buildingId) {
                fetch(`ajax_get_floors.php?building_id=${buildingId}`)
                    .then(response => response.json())
                    .then(data => {
                        floorSelect.innerHTML = '<option value="">All Floors</option>';
                        data.forEach(floor => {
                            const option = document.createElement('option');
                            option.value = floor.id;
                            option.textContent = floor.display_name || (floor.name || `Floor ${floor.floor_number}`);
                            floorSelect.appendChild(option);
                        });
                    })
                    .catch(error => {
                        console.error('Error loading floors:', error);
                        floorSelect.innerHTML = '<option value="">All Floors</option>';
                    });
            } else {
                // Load all floors
                fetch(`ajax_get_floors.php`)
                    .then(response => response.json())
                    .then(data => {
                        floorSelect.innerHTML = '<option value="">All Floors</option>';
                        data.forEach(floor => {
                            const option = document.createElement('option');
                            option.value = floor.id;
                            option.textContent = floor.display_name || (floor.name || `Floor ${floor.floor_number}`);
                            floorSelect.appendChild(option);
                        });
                    })
                    .catch(error => {
                        console.error('Error loading floors:', error);
                        floorSelect.innerHTML = '<option value="">All Floors</option>';
                    });
            }
        });
    }
});
</script>

        <!-- Units Table -->
        <div class="card card-round">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-list-ul"></i> Units (<span id="unitsCount"><?= count($units) ?></span>)</h6>
                <?php if (count($units) > 0): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="exportTable()">
                    <i class="bi bi-download"></i> Export
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="unitsTable">
                        <thead class="table-light">
                            <tr>
                                <th><i class="bi bi-building"></i> Building</th>
                                <th><i class="bi bi-hash"></i> Unit Number</th>
                                <th><i class="bi bi-grid"></i> Type</th>
                                <th><i class="bi bi-tag"></i> Status</th>
                                <th><i class="bi bi-currency-exchange"></i> Annual Rent</th>
                                <th><i class="bi bi-house-door"></i> Mode</th>
                                <th><i class="bi bi-phone"></i> Mobile</th>
                                <th><i class="bi bi-person"></i> Tenant</th>
                                <th><i class="bi bi-calendar-event"></i> Lease End</th>
                                <th><i class="bi bi-gear"></i> Actions</th>
                            </tr>
                        </thead>
                        <tbody id="unitsTableBody">
                            <?php if (empty($units)): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-5">
                                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                        No units found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php 
                                $currentBuilding = '';
                                foreach ($units as $unit): 
                                    // Group by building if no building filter is applied
                                    if (!$buildingFilter && $currentBuilding !== $unit['building_name']):
                                        $currentBuilding = $unit['building_name'];
                                ?>
                                    <tr class="table-secondary">
                                        <td colspan="10" class="fw-bold">
                                            <i class="bi bi-building"></i> <?= h($currentBuilding) ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <tr>
                                    <td><?= h($unit['building_name']) ?></td>
                                    <td><strong class="text-primary"><?= h($unit['unit_number']) ?></strong></td>
                                    <td>
                                        <span class="badge bg-light text-dark"><?= h($unit['unit_type'] ?: '-') ?></span>
                                    </td>
                                    <td>
                                        <?php
                                        $statusClass = [
                                            'vacant' => 'success',
                                            'occupied' => 'primary',
                                            'maintenance' => 'warning',
                                            'reserved' => 'info'
                                        ];
                                        $displayStatus = $unit['effective_status'] ?? $unit['status'];
                                        $class = $statusClass[$displayStatus] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?= $class ?>"><?= ucfirst(h($displayStatus)) ?></span>
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
                                            <br><small class="text-muted"><?= h(re_unit_public_status_label($unit['marketing_status'] ?? 'ready_to_move')) ?></small>
                                            <?php if (!empty($unit['featured'])): ?><br><span class="badge bg-warning text-dark">Featured</span><?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark border">Hidden</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $tenantName = (($unit['tenant_type'] ?? '') === 'company' && !empty($unit['company_name']))
                                                ? (string)$unit['company_name']
                                                : trim((string)($unit['first_name'] ?? '') . ' ' . (string)($unit['last_name'] ?? ''));
                                        ?>
                                        <?php if ($tenantName !== ''): ?>
                                            <i class="bi bi-person-circle"></i> <?= h($tenantName) ?>
                                        <?php elseif (!empty($unit['booking_first_name']) || !empty($unit['booking_last_name'])): ?>
                                            <i class="bi bi-calendar-check"></i> <?= h(trim(($unit['booking_first_name'] ?? '') . ' ' . ($unit['booking_last_name'] ?? ''))) ?>
                                            <?php if (!empty($unit['booking_number'])): ?>
                                                <br><small class="text-muted">Booking <?= h($unit['booking_number']) ?></small>
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
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete unit <?= h($unit['unit_number']) ?>? This is allowed only when the unit has no lease, booking, or maintenance history.');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete_unit">
                                                <input type="hidden" name="unit_id" value="<?= (int)$unit['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger" title="Delete Unit">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<script>
function exportTable() {
    const table = document.getElementById('unitsTable');
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const row = [], cols = rows[i].querySelectorAll('td, th');
        
        for (let j = 0; j < cols.length; j++) {
            let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, '').replace(/"/g, '""');
            row.push('"' + data + '"');
        }
        csv.push(row.join(','));
    }
    
    const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
    const downloadLink = document.createElement('a');
    downloadLink.download = 'units_<?= date('Y-m-d') ?>.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

