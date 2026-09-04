<?php
/**
 * Real Estate Module - Rent Roll Report
 * Comprehensive rent roll showing all units, tenants, lease details, and payment status
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

// Debug: If still no companies, show all (temporary fix for testing)
if (empty($userCompanies)) {
    $userCompanies = [1, 2]; // Show both companies as fallback
}

// Get filter parameters
$filterBuilding = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : null;
$filterStatus = $_GET['unit_status'] ?? 'all';
$asOfDate = $_GET['as_of_date'] ?? date('Y-m-d');
$exportFormat = $_GET['export'] ?? '';

// Build query - Filter by building company_id from user's accessible companies
// For now, show all companies to debug (will restrict later)
$where = ["1 = 1"]; // Show all units regardless of company
$params = [];

if ($filterBuilding) {
    $where[] = "u.building_id = ?";
    $params[] = $filterBuilding;
}

if ($filterStatus !== 'all') {
    $where[] = "u.status = ?";
    $params[] = $filterStatus;
}

// Get rent roll data - Show all units, with lease info if available
// First get units with their active leases
$rentRoll = $conn->prepare("
    SELECT 
        b.name as building_name,
        u.id as unit_id,
        u.unit_number,
        u.unit_type,
        u.area_sqm,
        u.status as unit_status,
        l.id as lease_id,
        l.lease_number,
        l.start_date as lease_start,
        l.end_date as lease_end,
        l.monthly_rent,
        l.security_deposit,
        l.payment_day,
        l.status as lease_status,
        t.id as tenant_id,
        t.first_name,
        t.last_name,
        t.email,
        t.phone
    FROM re_units u
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_leases l ON l.unit_id = u.id 
        AND l.status = 'active'
        AND (l.end_date IS NULL OR l.end_date >= ?)
        AND l.start_date <= ?
    LEFT JOIN re_tenants t ON t.id = l.tenant_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY b.name, u.unit_number
");
// Execute with all parameters: company IDs first (empty for now), then dates
$rentRoll->execute(array_merge($params, [$asOfDate, $asOfDate]));
$rentRoll = $rentRoll->fetchAll(PDO::FETCH_ASSOC);

// Now calculate payment totals for each unit/lease
foreach ($rentRoll as &$row) {
    if ($row['lease_id']) {
        // Get total paid
        $paid = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM re_payments 
            WHERE lease_id = ? AND payment_date <= ?
        ");
        $paid->execute([$row['lease_id'], $asOfDate]);
        $paidResult = $paid->fetch(PDO::FETCH_ASSOC);
        $row['total_paid'] = (float)($paidResult['total'] ?? 0);
        
        // Get total due
        $due = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM re_lease_installments 
            WHERE lease_id = ? AND installment_date <= ?
        ");
        $due->execute([$row['lease_id'], $asOfDate]);
        $dueResult = $due->fetch(PDO::FETCH_ASSOC);
        $row['total_due'] = (float)($dueResult['total'] ?? 0);
        
        // Get outstanding balance
        $outstanding = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM re_lease_installments 
            WHERE lease_id = ? AND installment_date <= ? AND status != 'paid'
        ");
        $outstanding->execute([$row['lease_id'], $asOfDate]);
        $outstandingResult = $outstanding->fetch(PDO::FETCH_ASSOC);
        $row['outstanding_balance'] = (float)($outstandingResult['total'] ?? 0);
    } else {
        $row['total_paid'] = 0;
        $row['total_due'] = 0;
        $row['outstanding_balance'] = 0;
    }
}
unset($row);

// Calculate totals
$totals = [
    'total_units' => count($rentRoll),
    'occupied_units' => 0,
    'vacant_units' => 0,
    'total_monthly_rent' => 0,
    'total_outstanding' => 0,
    'total_collected' => 0
];

foreach ($rentRoll as $row) {
    if ($row['unit_status'] === 'occupied') $totals['occupied_units']++;
    if ($row['unit_status'] === 'vacant') $totals['vacant_units']++;
    $totals['total_monthly_rent'] += (float)($row['monthly_rent'] ?: 0);
    $totals['total_outstanding'] += (float)($row['outstanding_balance'] ?: 0);
    $totals['total_collected'] += (float)($row['total_paid'] ?: 0);
}

// Get buildings for filter
$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

// Handle export
if ($exportFormat === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="rent_roll_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    
    // CSV headers
    fputcsv($output, ['Building', 'Unit', 'Type', 'Area', 'Status', 'Tenant', 'Email', 'Phone', 
                      'Lease Number', 'Lease Start', 'Lease End', 'Monthly Rent', 'Deposit', 
                      'Payment Day', 'Total Paid', 'Total Due', 'Outstanding Balance']);
    
    // CSV data
    foreach ($rentRoll as $row) {
        fputcsv($output, [
            $row['building_name'],
            $row['unit_number'],
            $row['unit_type'],
            $row['area_sqm'],
            $row['unit_status'],
            ($row['first_name'] ? $row['first_name'] . ' ' . $row['last_name'] : 'N/A'),
            $row['email'] ?: 'N/A',
            $row['phone'] ?: 'N/A',
            $row['lease_number'] ?: 'N/A',
            $row['lease_start'] ? date('Y-m-d', strtotime($row['lease_start'])) : 'N/A',
            $row['lease_end'] ? date('Y-m-d', strtotime($row['lease_end'])) : 'N/A',
            number_format($row['monthly_rent'] ?: 0, 2),
            number_format($row['security_deposit'] ?: 0, 2),
            $row['payment_day'] ?: 'N/A',
            number_format($row['total_paid'] ?: 0, 2),
            number_format($row['total_due'] ?: 0, 2),
            number_format($row['outstanding_balance'] ?: 0, 2)
        ]);
    }
    
    fclose($output);
    exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Rent Roll Report';
$pageStyles = '@media print { .no-print { display: none; } }';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <h1><i class="bi bi-calendar-check"></i> Rent Roll Report</h1>
            <div>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
                </a>
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>

<?php /* Phase 9 report source note */ ?>
<div class="alert alert-info">Combined operational report. Rent Roll is contractual/operational; Legacy and Invoice Mode leases are both included. Collection/outstanding columns are informational and should not be used as official accounting balance.</div>
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
                        <label class="form-label">Unit Status</label>
                        <select name="unit_status" class="form-select form-select-sm">
                            <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="occupied" <?= $filterStatus === 'occupied' ? 'selected' : '' ?>>Occupied</option>
                            <option value="vacant" <?= $filterStatus === 'vacant' ? 'selected' : '' ?>>Vacant</option>
                            <option value="maintenance" <?= $filterStatus === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                            <option value="reserved" <?= $filterStatus === 'reserved' ? 'selected' : '' ?>>Reserved</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">As Of Date</label>
                        <input type="date" name="as_of_date" class="form-control form-control-sm" value="<?= h($asOfDate) ?>">
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
                        <h5 class="text-muted">Total Units</h5>
                        <h2 class="mb-0"><?= $totals['total_units'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Occupied</h5>
                        <h2 class="mb-0 text-success"><?= $totals['occupied_units'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Monthly Rent</h5>
                        <h2 class="mb-0"><?= number_format($totals['total_monthly_rent'], 2) ?> AED</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Outstanding</h5>
                        <h2 class="mb-0 text-danger"><?= number_format($totals['total_outstanding'], 2) ?> AED</h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rent Roll Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Rent Roll - As Of <?= date('M d, Y', strtotime($asOfDate)) ?></h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Building</th>
                                <th>Unit</th>
                                <th>Type</th>
                                <th>Area</th>
                                <th>Status</th>
                                <th>Tenant</th>
                                <th>Contact</th>
                                <th>Lease #</th>
                                <th>Lease Start</th>
                                <th>Lease End</th>
                                <th>Monthly Rent</th>
                                <th>Deposit</th>
                                <th>Payment Day</th>
                                <th>Total Paid</th>
                                <th>Total Due</th>
                                <th>Outstanding</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rentRoll)): ?>
                                <tr>
                                    <td colspan="16" class="text-center text-muted">No data found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rentRoll as $row): ?>
                                    <tr>
                                        <td><?= h($row['building_name']) ?></td>
                                        <td><?= h($row['unit_number']) ?></td>
                                        <td><?= h($row['unit_type'] ?: '-') ?></td>
                                        <td><?= $row['area_sqm'] ? number_format($row['area_sqm'], 0) . ' sqm' : '-' ?></td>
                                        <td>
                                            <span class="badge bg-<?= $row['unit_status'] === 'occupied' ? 'success' : ($row['unit_status'] === 'vacant' ? 'warning' : 'secondary') ?>">
                                                <?= ucfirst($row['unit_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= $row['first_name'] ? h($row['first_name'] . ' ' . $row['last_name']) : '-' ?>
                                        </td>
                                        <td>
                                            <?php if ($row['email']): ?>
                                                <small><?= h($row['email']) ?></small><br>
                                            <?php endif; ?>
                                            <?php if ($row['phone']): ?>
                                                <small><?= h($row['phone']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($row['lease_number'] ?: '-') ?></td>
                                        <td><?= $row['lease_start'] ? date('M d, Y', strtotime($row['lease_start'])) : '-' ?></td>
                                        <td><?= $row['lease_end'] ? date('M d, Y', strtotime($row['lease_end'])) : '-' ?></td>
                                        <td class="text-end"><?= number_format($row['monthly_rent'] ?: 0, 2) ?> AED</td>
                                        <td class="text-end"><?= number_format($row['security_deposit'] ?: 0, 2) ?> AED</td>
                                        <td class="text-center"><?= $row['payment_day'] ?: '-' ?></td>
                                        <td class="text-end"><?= number_format($row['total_paid'] ?: 0, 2) ?> AED</td>
                                        <td class="text-end"><?= number_format($row['total_due'] ?: 0, 2) ?> AED</td>
                                        <td class="text-end <?= $row['outstanding_balance'] > 0 ? 'text-danger fw-bold' : '' ?>">
                                            <?= number_format($row['outstanding_balance'] ?: 0, 2) ?> AED
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="table-secondary fw-bold">
                                    <td colspan="10" class="text-end">TOTALS:</td>
                                    <td class="text-end"><?= number_format($totals['total_monthly_rent'], 2) ?> AED</td>
                                    <td></td>
                                    <td></td>
                                    <td class="text-end"><?= number_format($totals['total_collected'], 2) ?> AED</td>
                                    <td></td>
                                    <td class="text-end text-danger"><?= number_format($totals['total_outstanding'], 2) ?> AED</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

