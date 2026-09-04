<?php
/**
 * Real Estate Module - Occupancy Report
 * Occupancy rates by building, unit type, and overall statistics
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

// Get user's accessible companies
$userId = current_user_id();
$userCompanies = [];
if ($userId) {
    $stmt = $conn->prepare("
        SELECT DISTINCT company_id 
        FROM user_companies 
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $userCompanies = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
// If user has no specific companies, use current company
if (empty($userCompanies)) {
    $userCompanies = [$currentCompanyId];
}

// Get filter parameters
$filterBuilding = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : null;
$asOfDate = $_GET['as_of_date'] ?? date('Y-m-d');
$exportFormat = $_GET['export'] ?? '';

// Build query - Show all units regardless of company for now (same as Rent Roll fix)
$where = ["1 = 1"];
$params = [];

if ($filterBuilding) {
    $where[] = "u.building_id = ?";
    $params[] = $filterBuilding;
}

// Overall statistics
$overall = $conn->prepare("
    SELECT 
        COUNT(*) as total_units,
        SUM(CASE WHEN u.status = 'occupied' THEN 1 ELSE 0 END) as occupied_units,
        SUM(CASE WHEN u.status = 'vacant' THEN 1 ELSE 0 END) as vacant_units,
        SUM(CASE WHEN u.status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_units,
        SUM(CASE WHEN u.status = 'reserved' THEN 1 ELSE 0 END) as reserved_units
    FROM re_units u
    WHERE " . implode(' AND ', $where) . "
");
$overall->execute($params);
$overall = $overall->fetch(PDO::FETCH_ASSOC);

$overall['occupancy_rate'] = $overall['total_units'] > 0 
    ? round(($overall['occupied_units'] / $overall['total_units']) * 100, 2) 
    : 0;

// By building
$byBuilding = $conn->prepare("
    SELECT 
        b.id,
        b.name as building_name,
        COUNT(*) as total_units,
        SUM(CASE WHEN u.status = 'occupied' THEN 1 ELSE 0 END) as occupied_units,
        SUM(CASE WHEN u.status = 'vacant' THEN 1 ELSE 0 END) as vacant_units,
        SUM(CASE WHEN u.status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_units,
        SUM(CASE WHEN u.status = 'reserved' THEN 1 ELSE 0 END) as reserved_units
    FROM re_units u
    JOIN re_buildings b ON b.id = u.building_id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY b.id, b.name
    ORDER BY b.name
");
$byBuilding->execute($params);
$byBuilding = $byBuilding->fetchAll(PDO::FETCH_ASSOC);

foreach ($byBuilding as &$row) {
    $row['occupancy_rate'] = $row['total_units'] > 0 
        ? round(($row['occupied_units'] / $row['total_units']) * 100, 2) 
        : 0;
}

// By unit type
$byType = $conn->prepare("
    SELECT 
        COALESCE(u.unit_type, 'unknown') as unit_type,
        COUNT(*) as total_units,
        SUM(CASE WHEN u.status = 'occupied' THEN 1 ELSE 0 END) as occupied_units,
        SUM(CASE WHEN u.status = 'vacant' THEN 1 ELSE 0 END) as vacant_units,
        SUM(CASE WHEN u.status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_units,
        SUM(CASE WHEN u.status = 'reserved' THEN 1 ELSE 0 END) as reserved_units
    FROM re_units u
    WHERE " . implode(' AND ', $where) . "
    GROUP BY COALESCE(u.unit_type, 'unknown')
    ORDER BY COALESCE(u.unit_type, 'unknown')
");
$byType->execute($params);
$byType = $byType->fetchAll(PDO::FETCH_ASSOC);

foreach ($byType as &$row) {
    $row['occupancy_rate'] = $row['total_units'] > 0 
        ? round(($row['occupied_units'] / $row['total_units']) * 100, 2) 
        : 0;
}

// Get buildings for filter
$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

// Handle export
if ($exportFormat === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="occupancy_report_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['Occupancy Report - As Of ' . date('M d, Y', strtotime($asOfDate))]);
    fputcsv($output, []);
    fputcsv($output, ['Overall Statistics']);
    fputcsv($output, ['Total Units', 'Occupied', 'Vacant', 'Maintenance', 'Reserved', 'Occupancy Rate %']);
    fputcsv($output, [
        $overall['total_units'],
        $overall['occupied_units'],
        $overall['vacant_units'],
        $overall['maintenance_units'],
        $overall['reserved_units'],
        $overall['occupancy_rate']
    ]);
    fputcsv($output, []);
    fputcsv($output, ['By Building']);
    fputcsv($output, ['Building', 'Total Units', 'Occupied', 'Vacant', 'Maintenance', 'Reserved', 'Occupancy Rate %']);
    foreach ($byBuilding as $row) {
        fputcsv($output, [
            $row['building_name'],
            $row['total_units'],
            $row['occupied_units'],
            $row['vacant_units'],
            $row['maintenance_units'],
            $row['reserved_units'],
            $row['occupancy_rate']
        ]);
    }
    fputcsv($output, []);
    fputcsv($output, ['By Unit Type']);
    fputcsv($output, ['Unit Type', 'Total Units', 'Occupied', 'Vacant', 'Maintenance', 'Reserved', 'Occupancy Rate %']);
    foreach ($byType as $row) {
        fputcsv($output, [
            (!isset($row['unit_type']) || $row['unit_type'] === 'unknown' || $row['unit_type'] === null) ? 'N/A' : strtoupper((string)$row['unit_type']),
            $row['total_units'],
            $row['occupied_units'],
            $row['vacant_units'],
            $row['maintenance_units'],
            $row['reserved_units'],
            $row['occupancy_rate']
        ]);
    }
    
    fclose($output);
    exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Occupancy Report';
$pageStyles = '@media print { .no-print { display: none; } }';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <h1><i class="bi bi-bar-chart"></i> Occupancy Report</h1>
            <div>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
                </a>
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>

<?php /* Phase 9 report source note */ ?>
<div class="alert alert-info">Operational-only report. Occupancy is sourced from units and leases, not accounting balances.</div>
        </div>

        <!-- Filters -->
        <div class="card mb-4 no-print">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Building</label>
                        <select name="building_id" class="form-select form-select-sm">
                            <option value="">All Buildings</option>
                            <?php foreach ($buildings as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $filterBuilding == $b['id'] ? 'selected' : '' ?>>
                                    <?= h($b['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">As Of Date</label>
                        <input type="date" name="as_of_date" class="form-control form-control-sm" value="<?= h($asOfDate) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-funnel"></i> Generate Report
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Overall Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Units</h5>
                        <h2 class="mb-0"><?= $overall['total_units'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Occupied</h5>
                        <h2 class="mb-0 text-success"><?= $overall['occupied_units'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Vacant</h5>
                        <h2 class="mb-0 text-warning"><?= $overall['vacant_units'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Occupancy Rate</h5>
                        <h2 class="mb-0 text-primary"><?= $overall['occupancy_rate'] ?>%</h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- By Building -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Occupancy by Building</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Building</th>
                                <th class="text-center">Total Units</th>
                                <th class="text-center">Occupied</th>
                                <th class="text-center">Vacant</th>
                                <th class="text-center">Maintenance</th>
                                <th class="text-center">Reserved</th>
                                <th class="text-center">Occupancy Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($byBuilding)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">No data found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($byBuilding as $row): ?>
                                    <tr>
                                        <td><?= h($row['building_name']) ?></td>
                                        <td class="text-center"><?= $row['total_units'] ?></td>
                                        <td class="text-center text-success"><?= $row['occupied_units'] ?></td>
                                        <td class="text-center text-warning"><?= $row['vacant_units'] ?></td>
                                        <td class="text-center"><?= $row['maintenance_units'] ?></td>
                                        <td class="text-center"><?= $row['reserved_units'] ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $row['occupancy_rate'] >= 90 ? 'success' : ($row['occupancy_rate'] >= 70 ? 'warning' : 'danger') ?>">
                                                <?= $row['occupancy_rate'] ?>%
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- By Unit Type -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Occupancy by Unit Type</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Unit Type</th>
                                <th class="text-center">Total Units</th>
                                <th class="text-center">Occupied</th>
                                <th class="text-center">Vacant</th>
                                <th class="text-center">Maintenance</th>
                                <th class="text-center">Reserved</th>
                                <th class="text-center">Occupancy Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($byType)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">No data found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($byType as $row): ?>
                                    <tr>
                                        <td><?= (!isset($row['unit_type']) || $row['unit_type'] === 'unknown' || $row['unit_type'] === null) ? 'N/A' : strtoupper((string)$row['unit_type']) ?></td>
                                        <td class="text-center"><?= $row['total_units'] ?></td>
                                        <td class="text-center text-success"><?= $row['occupied_units'] ?></td>
                                        <td class="text-center text-warning"><?= $row['vacant_units'] ?></td>
                                        <td class="text-center"><?= $row['maintenance_units'] ?></td>
                                        <td class="text-center"><?= $row['reserved_units'] ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $row['occupancy_rate'] >= 90 ? 'success' : ($row['occupancy_rate'] >= 70 ? 'warning' : 'danger') ?>">
                                                <?= $row['occupancy_rate'] ?>%
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

