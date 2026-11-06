<?php
/**
 * Real Estate Module - Outstanding Balances Report
 * List of all outstanding balances and overdue payments
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
$daysOverdue = !empty($_GET['days_overdue']) ? (int)$_GET['days_overdue'] : 0;
$exportFormat = $_GET['export'] ?? '';

// Build query for outstanding installments
$where = ["li.company_id = ?", "li.paid = 0", "li.due_date < CURDATE()"];
$params = [$currentCompanyId];

if ($filterBuilding) {
    $where[] = "b.id = ?";
    $params[] = $filterBuilding;
}

if ($daysOverdue > 0) {
    $where[] = "DATEDIFF(CURDATE(), li.due_date) >= ?";
    $params[] = $daysOverdue;
}

// Get outstanding balances
$outstanding = $conn->prepare("
    SELECT 
        li.*,
        l.lease_number,
        l.monthly_rent,
        b.name as building_name,
        u.unit_number,
        t.first_name,
        t.last_name,
        t.email,
        t.phone,
        DATEDIFF(CURDATE(), li.due_date) as days_overdue
    FROM re_lease_installments li
    JOIN re_leases l ON l.id = li.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_tenants t ON t.id = l.tenant_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY li.due_date ASC, b.name, u.unit_number
");
$outstanding->execute($params);
$outstanding = $outstanding->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totals = [
    'total_outstanding' => 0,
    'total_installments' => count($outstanding),
    'avg_days_overdue' => 0
];

$totalDays = 0;
foreach ($outstanding as $row) {
    $totals['total_outstanding'] += (float)($row['amount'] ?: 0);
    $totalDays += (int)$row['days_overdue'];
}

$totals['avg_days_overdue'] = count($outstanding) > 0 ? round($totalDays / count($outstanding), 0) : 0;

// Get buildings for filter
$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

// Handle export
if ($exportFormat === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="outstanding_balances_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['Building', 'Unit', 'Tenant', 'Email', 'Phone', 'Lease Number', 
                      'Due Date', 'Amount', 'Days Overdue', 'Payment Method']);
    
    foreach ($outstanding as $row) {
        fputcsv($output, [
            $row['building_name'],
            $row['unit_number'],
            ($row['first_name'] ? $row['first_name'] . ' ' . $row['last_name'] : 'N/A'),
            $row['email'] ?: 'N/A',
            $row['phone'] ?: 'N/A',
            $row['lease_number'],
            date('Y-m-d', strtotime($row['due_date'])),
            number_format($row['amount'], 2),
            $row['days_overdue'],
            $row['payment_method'] ?: 'N/A'
        ]);
    }
    
    fclose($output);
    exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Outstanding Balances Report';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<style>
@media print {
    .no-print { display: none; }
}
</style>
<?php
?>
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <h1><i class="bi bi-exclamation-triangle"></i> Outstanding Balances Report</h1>
            <div>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
                </a>
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>

<?php /* Phase 9 report source note */ ?>
<div class="alert alert-warning">Legacy-Compatible report. This page uses old installment-based outstanding logic. Use Accounting &gt; Outstandings for Invoice Mode AR aging.</div>
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
                        <label class="form-label">Minimum Days Overdue</label>
                        <input type="number" name="days_overdue" class="form-control form-control-sm" 
                               value="<?= h($daysOverdue) ?>" min="0" placeholder="0 = All overdue">
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

        <!-- Summary Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h5 class="text-muted">Total Outstanding</h5>
                        <h2 class="mb-0 text-danger"><?= number_format($totals['total_outstanding'], 2) ?> AED</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Overdue Installments</h5>
                        <h2 class="mb-0"><?= $totals['total_installments'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Avg Days Overdue</h5>
                        <h2 class="mb-0"><?= $totals['avg_days_overdue'] ?> days</h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Outstanding Balances Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Outstanding Balances (<?= count($outstanding) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Building</th>
                                <th>Unit</th>
                                <th>Tenant</th>
                                <th>Contact</th>
                                <th>Lease #</th>
                                <th>Due Date</th>
                                <th>Amount</th>
                                <th>Days Overdue</th>
                                <th>Payment Method</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($outstanding)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted">No outstanding balances found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($outstanding as $row): ?>
                                    <tr class="<?= $row['days_overdue'] > 30 ? 'table-danger' : ($row['days_overdue'] > 15 ? 'table-warning' : '') ?>">
                                        <td><?= h($row['building_name']) ?></td>
                                        <td><?= h($row['unit_number']) ?></td>
                                        <td><?= $row['first_name'] ? h($row['first_name'] . ' ' . $row['last_name']) : '-' ?></td>
                                        <td>
                                            <?php if ($row['email']): ?>
                                                <small><?= h($row['email']) ?></small><br>
                                            <?php endif; ?>
                                            <?php if ($row['phone']): ?>
                                                <small><?= h($row['phone']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($row['lease_number']) ?></td>
                                        <td><?= date('M d, Y', strtotime($row['due_date'])) ?></td>
                                        <td class="text-end fw-bold"><?= number_format($row['amount'], 2) ?> AED</td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $row['days_overdue'] > 30 ? 'danger' : ($row['days_overdue'] > 15 ? 'warning' : 'secondary') ?>">
                                                <?= $row['days_overdue'] ?> days
                                            </span>
                                        </td>
                                        <td><?= h($row['payment_method'] ?: '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="table-secondary fw-bold">
                                    <td colspan="6" class="text-end">TOTAL OUTSTANDING:</td>
                                    <td class="text-end text-danger"><?= number_format($totals['total_outstanding'], 2) ?> AED</td>
                                    <td colspan="2"></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

