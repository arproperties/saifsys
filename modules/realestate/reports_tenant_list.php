<?php
/**
 * Real Estate Module - Tenant List Report
 * Complete list of all tenants with contact information and lease details
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
$hasActiveLease = $_GET['has_active_lease'] ?? 'all';
$exportFormat = $_GET['export'] ?? '';

// Build query
$where = ["t.company_id = ?"];
$params = [$currentCompanyId];

if ($filterBuilding) {
    $where[] = "b.id = ?";
    $params[] = $filterBuilding;
}

if ($hasActiveLease === 'yes') {
    $where[] = "l.status = 'active'";
} elseif ($hasActiveLease === 'no') {
    $where[] = "(l.status IS NULL OR l.status != 'active')";
}

// Get tenant list
$tenants = $conn->prepare("
    SELECT 
        t.*,
        b.name as building_name,
        u.unit_number,
        l.id as lease_id,
        l.lease_number,
        l.start_date as lease_start,
        l.end_date as lease_end,
        l.monthly_rent,
        l.status as lease_status,
        COALESCE(SUM(CASE WHEN li.status != 'paid' AND li.installment_date < CURDATE() THEN li.amount ELSE 0 END), 0) as overdue_amount
    FROM re_tenants t
    LEFT JOIN re_leases l ON l.tenant_id = t.id AND l.status = 'active'
    LEFT JOIN re_units u ON u.id = l.unit_id
    LEFT JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_lease_installments li ON li.lease_id = l.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY t.id
    ORDER BY t.first_name, t.last_name
");
$tenants->execute($params);
$tenants = $tenants->fetchAll(PDO::FETCH_ASSOC);

// Get buildings for filter
$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

// Handle export
if ($exportFormat === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="tenant_list_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['First Name', 'Last Name', 'Email', 'Phone', 'Alt Phone', 'ID Type', 'ID Number', 
                      'Building', 'Unit', 'Lease Number', 'Lease Start', 'Lease End', 'Monthly Rent', 
                      'Lease Status', 'Overdue Amount']);
    
    foreach ($tenants as $row) {
        fputcsv($output, [
            $row['first_name'],
            $row['last_name'],
            $row['email'] ?: 'N/A',
            $row['phone'] ?: 'N/A',
            $row['alt_phone'] ?: 'N/A',
            $row['id_type'] ?: 'N/A',
            $row['id_number'] ?: 'N/A',
            $row['building_name'] ?: 'N/A',
            $row['unit_number'] ?: 'N/A',
            $row['lease_number'] ?: 'N/A',
            $row['lease_start'] ? date('Y-m-d', strtotime($row['lease_start'])) : 'N/A',
            $row['lease_end'] ? date('Y-m-d', strtotime($row['lease_end'])) : 'N/A',
            number_format($row['monthly_rent'] ?: 0, 2),
            $row['lease_status'] ?: 'N/A',
            number_format($row['overdue_amount'] ?: 0, 2)
        ]);
    }
    
    fclose($output);
    exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Tenant List Report';
$pageStyles = '@media print { .no-print { display: none; } }';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <h1><i class="bi bi-people"></i> Tenant List Report</h1>
            <div>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
                </a>
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>
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
                        <label class="form-label">Active Lease</label>
                        <select name="has_active_lease" class="form-select form-select-sm">
                            <option value="all" <?= $hasActiveLease === 'all' ? 'selected' : '' ?>>All Tenants</option>
                            <option value="yes" <?= $hasActiveLease === 'yes' ? 'selected' : '' ?>>With Active Lease</option>
                            <option value="no" <?= $hasActiveLease === 'no' ? 'selected' : '' ?>>Without Active Lease</option>
                        </select>
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

        <!-- Tenant List Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Tenants (<?= count($tenants) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>ID Type</th>
                                <th>ID Number</th>
                                <th>Building</th>
                                <th>Unit</th>
                                <th>Lease #</th>
                                <th>Lease Start</th>
                                <th>Lease End</th>
                                <th>Monthly Rent</th>
                                <th>Status</th>
                                <th>Overdue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tenants)): ?>
                                <tr>
                                    <td colspan="13" class="text-center text-muted">No tenants found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($tenants as $tenant): ?>
                                    <tr>
                                        <td><?= h($tenant['first_name'] . ' ' . $tenant['last_name']) ?></td>
                                        <td><?= h($tenant['email'] ?: '-') ?></td>
                                        <td><?= h($tenant['phone'] ?: '-') ?></td>
                                        <td><?= h($tenant['id_type'] ?: '-') ?></td>
                                        <td><?= h($tenant['id_number'] ?: '-') ?></td>
                                        <td><?= h($tenant['building_name'] ?: '-') ?></td>
                                        <td><?= h($tenant['unit_number'] ?: '-') ?></td>
                                        <td><?= h($tenant['lease_number'] ?: '-') ?></td>
                                        <td><?= $tenant['lease_start'] ? date('M d, Y', strtotime($tenant['lease_start'])) : '-' ?></td>
                                        <td><?= $tenant['lease_end'] ? date('M d, Y', strtotime($tenant['lease_end'])) : '-' ?></td>
                                        <td class="text-end"><?= number_format($tenant['monthly_rent'] ?: 0, 2) ?> AED</td>
                                        <td>
                                            <?php if ($tenant['lease_status']): ?>
                                                <span class="badge bg-<?= $tenant['lease_status'] === 'active' ? 'success' : 'secondary' ?>">
                                                    <?= ucfirst($tenant['lease_status']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end <?= $tenant['overdue_amount'] > 0 ? 'text-danger fw-bold' : '' ?>">
                                            <?= number_format($tenant['overdue_amount'] ?: 0, 2) ?> AED
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

