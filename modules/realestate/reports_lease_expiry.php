<?php
/**
 * Real Estate Module - Lease Expiry Report
 * Leases expiring in the next 30, 60, 90 days or custom period
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

// Get filter parameters
$filterBuilding = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : null;
$daysAhead = !empty($_GET['days_ahead']) ? (int)$_GET['days_ahead'] : 90;
$includeExpired = isset($_GET['include_expired']) && $_GET['include_expired'] == '1';
$exportFormat = $_GET['export'] ?? '';

// Calculate date range
$today = date('Y-m-d');
$endDate = date('Y-m-d', strtotime("+{$daysAhead} days"));

// Build query - Show all leases regardless of company (same fix as Rent Roll)
$where = ["l.status = 'active'"];
$params = [];

if ($filterBuilding) {
    $where[] = "b.id = ?";
    $params[] = $filterBuilding;
}

if ($includeExpired) {
    $where[] = "l.end_date <= ?";
    $params[] = $endDate;
} else {
    $where[] = "l.end_date >= ? AND l.end_date <= ?";
    $params[] = $today;
    $params[] = $endDate;
}

// Get expiring leases
$leases = $conn->prepare("
    SELECT 
        l.id,
        l.lease_number,
        l.start_date,
        l.end_date,
        l.monthly_rent,
        l.security_deposit,
        l.status,
        DATEDIFF(l.end_date, CURDATE()) as days_remaining,
        b.name as building_name,
        u.unit_number,
        u.unit_type,
        t.id as tenant_id,
        t.first_name,
        t.last_name,
        t.email,
        t.phone,
        CASE 
            WHEN l.end_date < CURDATE() THEN 'expired'
            WHEN DATEDIFF(l.end_date, CURDATE()) <= 30 THEN 'expiring_soon'
            WHEN DATEDIFF(l.end_date, CURDATE()) <= 60 THEN 'expiring_60'
            ELSE 'expiring_90'
        END as expiry_category
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY l.end_date ASC
");
$leases->execute($params);
$leases = $leases->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$stats = [
    'total' => count($leases),
    'expired' => 0,
    'expiring_soon' => 0,
    'expiring_60' => 0,
    'expiring_90' => 0,
    'total_monthly_rent' => 0
];

foreach ($leases as $lease) {
    if ($lease['expiry_category'] === 'expired') $stats['expired']++;
    elseif ($lease['expiry_category'] === 'expiring_soon') $stats['expiring_soon']++;
    elseif ($lease['expiry_category'] === 'expiring_60') $stats['expiring_60']++;
    else $stats['expiring_90']++;
    $stats['total_monthly_rent'] += (float)($lease['monthly_rent'] ?: 0);
}

// Get buildings for filter
$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

// Handle export
if ($exportFormat === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="lease_expiry_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['Lease Expiry Report - Next ' . $daysAhead . ' Days']);
    fputcsv($output, []);
    fputcsv($output, ['Lease #', 'Building', 'Unit', 'Tenant', 'Email', 'Phone', 'Lease Start', 'Lease End', 'Days Remaining', 'Monthly Rent', 'Status']);
    
    foreach ($leases as $row) {
        fputcsv($output, [
            $row['lease_number'],
            $row['building_name'],
            $row['unit_number'],
            $row['first_name'] . ' ' . $row['last_name'],
            $row['email'] ?: 'N/A',
            $row['phone'] ?: 'N/A',
            date('Y-m-d', strtotime($row['start_date'])),
            date('Y-m-d', strtotime($row['end_date'])),
            $row['days_remaining'],
            number_format($row['monthly_rent'] ?: 0, 2),
            $row['expiry_category']
        ]);
    }
    
    fclose($output);
    exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Lease Expiry Report';
$pageStyles = '@media print { .no-print { display: none; } }';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <h1><i class="bi bi-calendar-x"></i> Lease Expiry Report</h1>
            <div>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
                </a>
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>

<?php /* Phase 9 report source note */ ?>
<div class="alert alert-info">Combined operational report. Includes both Legacy and Invoice Mode leases; source is lease dates and renewal workflow status.</div>
        </div>

        <!-- Filters -->
        <div class="card mb-4 no-print">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
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
                    <div class="col-md-3">
                        <label class="form-label">Days Ahead</label>
                        <select name="days_ahead" class="form-select form-select-sm">
                            <option value="30" <?= $daysAhead == 30 ? 'selected' : '' ?>>Next 30 Days</option>
                            <option value="60" <?= $daysAhead == 60 ? 'selected' : '' ?>>Next 60 Days</option>
                            <option value="90" <?= $daysAhead == 90 ? 'selected' : '' ?>>Next 90 Days</option>
                            <option value="180" <?= $daysAhead == 180 ? 'selected' : '' ?>>Next 180 Days</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Options</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="include_expired" value="1" id="include_expired" <?= $includeExpired ? 'checked' : '' ?>>
                            <label class="form-check-label" for="include_expired">
                                Include Expired Leases
                            </label>
                        </div>
                    </div>
                    <div class="col-md-3">
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

        <!-- Summary Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Expiring</h5>
                        <h2 class="mb-0"><?= $stats['total'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Expired</h5>
                        <h2 class="mb-0 text-danger"><?= $stats['expired'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Expiring Soon (≤30 days)</h5>
                        <h2 class="mb-0 text-warning"><?= $stats['expiring_soon'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Monthly Rent</h5>
                        <h2 class="mb-0"><?= number_format($stats['total_monthly_rent'], 2) ?> AED</h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Expiring Leases Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Expiring Leases (<?= count($leases) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Lease #</th>
                                <th>Building</th>
                                <th>Unit</th>
                                <th>Tenant</th>
                                <th>Contact</th>
                                <th>Lease Start</th>
                                <th>Lease End</th>
                                <th>Days Remaining</th>
                                <th>Monthly Rent</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($leases)): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted">No expiring leases found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($leases as $lease): ?>
                                    <tr class="<?= $lease['days_remaining'] < 0 ? 'table-danger' : ($lease['days_remaining'] <= 30 ? 'table-warning' : '') ?>">
                                        <td><?= h($lease['lease_number']) ?></td>
                                        <td><?= h($lease['building_name']) ?></td>
                                        <td><?= h($lease['unit_number']) ?></td>
                                        <td><?= h($lease['first_name'] . ' ' . $lease['last_name']) ?></td>
                                        <td>
                                            <?php if ($lease['email']): ?>
                                                <small><?= h($lease['email']) ?></small><br>
                                            <?php endif; ?>
                                            <?php if ($lease['phone']): ?>
                                                <small><?= h($lease['phone']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= date('M d, Y', strtotime($lease['start_date'])) ?></td>
                                        <td><?= date('M d, Y', strtotime($lease['end_date'])) ?></td>
                                        <td class="text-center">
                                            <?php if ($lease['days_remaining'] < 0): ?>
                                                <span class="badge bg-danger">Expired <?= abs($lease['days_remaining']) ?> days ago</span>
                                            <?php elseif ($lease['days_remaining'] <= 30): ?>
                                                <span class="badge bg-warning"><?= $lease['days_remaining'] ?> days</span>
                                            <?php elseif ($lease['days_remaining'] <= 60): ?>
                                                <span class="badge bg-info"><?= $lease['days_remaining'] ?> days</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><?= $lease['days_remaining'] ?> days</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?= number_format($lease['monthly_rent'] ?: 0, 2) ?> AED</td>
                                        <td>
                                            <span class="badge bg-<?= $lease['expiry_category'] === 'expired' ? 'danger' : ($lease['expiry_category'] === 'expiring_soon' ? 'warning' : 'secondary') ?>">
                                                <?= ucfirst(str_replace('_', ' ', $lease['expiry_category'])) ?>
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

