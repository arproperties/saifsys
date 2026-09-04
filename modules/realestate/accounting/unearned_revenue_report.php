<?php
/**
 * Unearned Revenue (Tenant Credit Balance) Report
 * Lists tenants with remaining advance/credit posted to GL 2410 – Deferred Revenue.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/payment_allocation_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function m($n) { return number_format((float)$n, 2); }
function tenant_display_name(array $row): string {
    return ($row['tenant_type'] ?? '') === 'company'
        ? trim((string)($row['company_name'] ?? ''))
        : trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
}

$tenantSearch = trim((string)($_GET['tenant_search'] ?? ''));
$buildingId = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : 0;
$floorId = !empty($_GET['floor_id']) ? (int)$_GET['floor_id'] : 0;
$unitId = !empty($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;
$leaseId = !empty($_GET['lease_id']) ? (int)$_GET['lease_id'] : 0;
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$minBalance = ($_GET['min_balance'] ?? '') !== '' ? (float)$_GET['min_balance'] : null;
$maxBalance = ($_GET['max_balance'] ?? '') !== '' ? (float)$_GET['max_balance'] : null;
$sort = (string)($_GET['sort'] ?? 'credit_balance');
$dir = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(10, min(100, (int)($_GET['per_page'] ?? 25)));
$exportFormat = (string)($_GET['export'] ?? '');

$allowedSort = [
    'tenant_name' => 'tenant_name',
    'lease_number' => 'lease_number',
    'property' => 'building_name',
    'credit_balance' => 'credit_balance',
    'last_payment_date' => 'last_payment_date',
    'receipt_number' => 'receipt_number',
];
$sortCol = $allowedSort[$sort] ?? 'credit_balance';

$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC) ?: [];

$floorParams = [$currentCompanyId];
$floorSql = "
    SELECT f.id, f.floor_number, f.name, b.name AS building_name
    FROM re_floors f
    JOIN re_buildings b ON b.id = f.building_id
    WHERE b.company_id = ?
";
if ($buildingId > 0) {
    $floorSql .= " AND b.id = ?";
    $floorParams[] = $buildingId;
}
$floorSql .= " ORDER BY b.name, f.floor_number, f.name";
$floorsStmt = $conn->prepare($floorSql);
$floorsStmt->execute($floorParams);
$floors = $floorsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$unitParams = [$currentCompanyId];
$unitSql = "
    SELECT u.id, u.unit_number, b.name AS building_name, f.floor_number, f.name AS floor_name
    FROM re_units u
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_floors f ON f.id = u.floor_id
    WHERE u.company_id = ?
";
if ($buildingId > 0) {
    $unitSql .= " AND b.id = ?";
    $unitParams[] = $buildingId;
}
if ($floorId > 0) {
    $unitSql .= " AND u.floor_id = ?";
    $unitParams[] = $floorId;
}
$unitSql .= " ORDER BY b.name, f.floor_number, u.unit_number";
$unitsStmt = $conn->prepare($unitSql);
$unitsStmt->execute($unitParams);
$units = $unitsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$leaseOptions = $conn->prepare("
    SELECT l.id, l.lease_number, t.first_name, t.last_name, t.company_name, t.tenant_type
    FROM re_leases l
    JOIN re_tenants t ON t.id = l.tenant_id AND t.company_id = l.company_id
    JOIN re_tenant_credit_balances tcb ON tcb.tenant_id = t.id AND tcb.company_id = l.company_id
    WHERE l.company_id = ? AND tcb.balance_aed > 0.005
    ORDER BY l.lease_number
");
$leaseOptions->execute([$currentCompanyId]);
$leaseOptions = $leaseOptions->fetchAll(PDO::FETCH_ASSOC) ?: [];

$rows = [];
$totalCredit = 0.0;
$gl2410Balance = 0.0;
$gl2410AccountId = 0;
$tableExists = payment_allocation_tables_exist($conn);

if ($tableExists) {
    $where = ['tcb.company_id = ?', 'tcb.balance_aed > 0.005'];
    $params = [$currentCompanyId, $currentCompanyId, $currentCompanyId];

    if ($tenantSearch !== '') {
        $where[] = "(t.company_name LIKE ? OR CONCAT(t.first_name, ' ', t.last_name) LIKE ? OR t.phone LIKE ? OR t.email LIKE ?)";
        $like = '%' . $tenantSearch . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($buildingId > 0) {
        $where[] = 'b.id = ?';
        $params[] = $buildingId;
    }
    if ($floorId > 0) {
        $where[] = 'u.floor_id = ?';
        $params[] = $floorId;
    }
    if ($unitId > 0) {
        $where[] = 'u.id = ?';
        $params[] = $unitId;
    }
    if ($leaseId > 0) {
        $where[] = 'l.id = ?';
        $params[] = $leaseId;
    }
    if ($minBalance !== null) {
        $where[] = 'tcb.balance_aed >= ?';
        $params[] = $minBalance;
    }
    if ($maxBalance !== null) {
        $where[] = 'tcb.balance_aed <= ?';
        $params[] = $maxBalance;
    }
    if ($dateFrom !== '') {
        $where[] = '(lp.payment_date >= ? OR EXISTS (
            SELECT 1 FROM re_tenant_credit_transactions tct
            JOIN re_payments px ON px.id = tct.payment_id
            WHERE tct.tenant_id = tcb.tenant_id AND tct.company_id = tcb.company_id
              AND tct.type = \'credit\' AND px.payment_date >= ?
        ))';
        array_push($params, $dateFrom, $dateFrom);
    }
    if ($dateTo !== '') {
        $where[] = '(lp.payment_date <= ? OR EXISTS (
            SELECT 1 FROM re_tenant_credit_transactions tct
            JOIN re_payments px ON px.id = tct.payment_id
            WHERE tct.tenant_id = tcb.tenant_id AND tct.company_id = tcb.company_id
              AND tct.type = \'credit\' AND px.payment_date <= ?
        ))';
        array_push($params, $dateTo, $dateTo);
    }

    $orderSql = match ($sortCol) {
        'tenant_name' => "COALESCE(NULLIF(t.company_name,''), CONCAT(t.last_name, ' ', t.first_name))",
        'lease_number' => 'l.lease_number',
        'building_name' => 'b.name',
        'last_payment_date' => 'lp.payment_date',
        'receipt_number' => 'lp.receipt_number',
        default => 'tcb.balance_aed',
    };

    $sql = "
        SELECT
            tcb.tenant_id,
            tcb.balance_aed AS credit_balance,
            t.first_name, t.last_name, t.company_name, t.tenant_type,
            l.id AS lease_id,
            l.lease_number,
            l.status AS lease_status,
            b.id AS building_id,
            b.name AS building_name,
            u.unit_number,
            f.floor_number,
            f.name AS floor_name,
            lp.payment_id,
            lp.receipt_number,
            lp.payment_date AS last_payment_date,
            COALESCE(NULLIF(t.company_name,''), CONCAT(t.last_name, ' ', t.first_name)) AS tenant_name
        FROM re_tenant_credit_balances tcb
        JOIN re_tenants t ON t.id = tcb.tenant_id AND t.company_id = tcb.company_id
        LEFT JOIN (
            SELECT l1.id, l1.tenant_id, l1.company_id, l1.lease_number, l1.status, l1.unit_id
            FROM re_leases l1
            INNER JOIN (
                SELECT tenant_id, company_id, MAX(
                    (CASE
                        WHEN status = 'active' THEN 3
                        WHEN status = 'draft' THEN 2
                        ELSE 1
                    END) * 1000000000 + id
                ) AS pick_key
                FROM re_leases
                WHERE company_id = ?
                GROUP BY tenant_id, company_id
            ) pick ON pick.tenant_id = l1.tenant_id
                AND pick.company_id = l1.company_id
                AND ((CASE
                    WHEN l1.status = 'active' THEN 3
                    WHEN l1.status = 'draft' THEN 2
                    ELSE 1
                END) * 1000000000 + l1.id) = pick.pick_key
        ) l ON l.tenant_id = tcb.tenant_id AND l.company_id = tcb.company_id
        LEFT JOIN re_units u ON u.id = l.unit_id
        LEFT JOIN re_buildings b ON b.id = u.building_id
        LEFT JOIN re_floors f ON f.id = u.floor_id
        LEFT JOIN (
            SELECT p.id AS payment_id, p.receipt_number, p.payment_date, p.lease_id, l2.tenant_id
            FROM re_payments p
            JOIN re_leases l2 ON l2.id = p.lease_id AND l2.company_id = p.company_id
            JOIN (
                SELECT l3.tenant_id, MAX(p2.id) AS max_payment_id
                FROM re_payments p2
                JOIN re_leases l3 ON l3.id = p2.lease_id AND l3.company_id = p2.company_id
                WHERE p2.company_id = ?
                  AND (
                    EXISTS (
                        SELECT 1 FROM re_receipt_allocations ra
                        WHERE ra.payment_id = p2.id AND ra.company_id = p2.company_id
                          AND ra.target_type = 'tenant_credit'
                    )
                    OR EXISTS (
                        SELECT 1 FROM re_tenant_credit_transactions tct
                        WHERE tct.payment_id = p2.id AND tct.company_id = p2.company_id
                          AND tct.type = 'credit'
                    )
                  )
                GROUP BY l3.tenant_id
            ) latest ON latest.max_payment_id = p.id
        ) lp ON lp.tenant_id = tcb.tenant_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY {$orderSql} {$dir}, tcb.tenant_id ASC
    ";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    foreach ($rows as $row) {
        $totalCredit += (float)$row['credit_balance'];
    }
}

try {
    $glStmt = $conn->prepare("
        SELECT coa.id,
               COALESCE(SUM(gl.credit_amount) - SUM(gl.debit_amount), 0) AS balance
        FROM re_chart_of_accounts coa
        LEFT JOIN re_general_ledger gl ON gl.account_id = coa.id AND gl.company_id = coa.company_id
        WHERE coa.company_id = ? AND coa.account_code = '2410'
        GROUP BY coa.id
        LIMIT 1
    ");
    $glStmt->execute([$currentCompanyId]);
    $glRow = $glStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $gl2410Balance = (float)($glRow['balance'] ?? 0);
    $gl2410AccountId = (int)($glRow['id'] ?? 0);
} catch (Throwable $e) {
    $gl2410Balance = 0.0;
    $gl2410AccountId = 0;
}

$totalRows = count($rows);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$pagedRows = $exportFormat !== '' ? $rows : array_slice($rows, $offset, $perPage);

$queryBase = static function (array $overrides = []) use ($tenantSearch, $buildingId, $floorId, $unitId, $leaseId, $dateFrom, $dateTo, $minBalance, $maxBalance, $sort, $dir, $perPage) {
    $q = array_filter([
        'tenant_search' => $tenantSearch,
        'building_id' => $buildingId ?: null,
        'floor_id' => $floorId ?: null,
        'unit_id' => $unitId ?: null,
        'lease_id' => $leaseId ?: null,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'min_balance' => $minBalance !== null ? $minBalance : null,
        'max_balance' => $maxBalance !== null ? $maxBalance : null,
        'sort' => $sort,
        'dir' => $dir,
        'per_page' => $perPage,
    ], static fn($v) => $v !== null && $v !== '');
    return http_build_query(array_merge($q, $overrides));
};

$sortLink = static function (string $column) use ($queryBase, $sort, $dir) {
    $nextDir = ($sort === $column && $dir === 'desc') ? 'asc' : 'desc';
    return '?' . $queryBase(['sort' => $column, 'dir' => $nextDir, 'page' => 1]);
};

if ($exportFormat === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="unearned_revenue_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Tenant Name', 'Lease No.', 'Property / Unit', 'Receipt No.', 'Credit Balance (Unearned Revenue)', 'Last Payment Date', 'Lease Status',
    ]);
    foreach ($rows as $row) {
        $floorLabel = $row['floor_number'] !== null
            ? ('Floor ' . $row['floor_number'] . ($row['floor_name'] ? ' / ' . $row['floor_name'] : ''))
            : '';
        $property = trim(($row['building_name'] ?? '') . ($floorLabel ? ' / ' . $floorLabel : '') . ' / ' . ($row['unit_number'] ?? ''));
        fputcsv($out, [
            tenant_display_name($row),
            $row['lease_number'] ?? '',
            $property,
            $row['receipt_number'] ?: ($row['payment_id'] ? ('#' . $row['payment_id']) : ''),
            number_format((float)$row['credit_balance'], 2, '.', ''),
            $row['last_payment_date'] ?? '',
            $row['lease_status'] ?? '',
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Total Unearned Revenue', '', '', '', number_format($totalCredit, 2, '.', ''), '', '']);
    fputcsv($out, ['GL 2410 Deferred Revenue Balance', '', '', '', number_format($gl2410Balance, 2, '.', ''), '', '']);
    fclose($out);
    exit;
}

if ($exportFormat === 'pdf') {
    $reportTitle = 'Unearned Revenue (Tenant Credit Balance)';
    ob_start();
    ?>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .meta { color: #666; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
        th { background: #f3f4f6; }
        .text-end { text-align: right; }
        tfoot td { font-weight: bold; background: #f9fafb; }
    </style>
    <h1><?= h($reportTitle) ?></h1>
    <div class="meta">Generated <?= h(date('Y-m-d H:i')) ?> · GL Account 2410 – Deferred Revenue</div>
    <table>
        <thead>
            <tr>
                <th>Tenant</th><th>Lease</th><th>Property / Unit</th><th>Receipt</th>
                <th class="text-end">Credit Balance</th><th>Last Payment</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <?php
            $floorLabel = $row['floor_number'] !== null
                ? ('Floor ' . $row['floor_number'] . ($row['floor_name'] ? ' / ' . $row['floor_name'] : ''))
                : '';
            $property = trim(($row['building_name'] ?? '') . ($floorLabel ? ' / ' . $floorLabel : '') . ' / ' . ($row['unit_number'] ?? ''));
            ?>
            <tr>
                <td><?= h(tenant_display_name($row)) ?></td>
                <td><?= h($row['lease_number'] ?? '-') ?></td>
                <td><?= h($property ?: '-') ?></td>
                <td><?= h($row['receipt_number'] ?: ($row['payment_id'] ? ('#' . $row['payment_id']) : '-')) ?></td>
                <td class="text-end"><?= m($row['credit_balance']) ?></td>
                <td><?= h($row['last_payment_date'] ?? '-') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="6">No tenant credit balances found.</td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" class="text-end">Total Unearned Revenue (filtered)</td>
                <td class="text-end"><?= m($totalCredit) ?></td>
                <td></td>
            </tr>
            <tr>
                <td colspan="4" class="text-end">GL 2410 Deferred Revenue Balance</td>
                <td class="text-end"><?= m($gl2410Balance) ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    <?php
    $html = ob_get_clean();
    $vendorAutoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
    if (is_file($vendorAutoload)) {
        require_once $vendorAutoload;
    }
    if (class_exists('\Mpdf\Mpdf')) {
        $projectRoot = dirname(__DIR__, 3);
        $tmpDir = '';
        foreach ([
            $projectRoot . '/uploads/temp/mpdf',
            $projectRoot . '/uploads/mpdf_tmp',
            rtrim(sys_get_temp_dir(), '/\\') . '/herosysgro_mpdf',
        ] as $cand) {
            if (!is_dir($cand)) {
                @mkdir($cand, 0777, true);
            }
            if (is_dir($cand) && is_writable($cand)) {
                $tmpDir = $cand;
                break;
            }
        }
        try {
            $cfg = ['mode' => 'utf-8', 'format' => 'A4-L', 'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 12, 'margin_bottom' => 12];
            if ($tmpDir !== '') {
                $cfg['tempDir'] = $tmpDir;
            }
            $mpdf = new \Mpdf\Mpdf($cfg);
            $mpdf->SetTitle($reportTitle);
            $mpdf->WriteHTML($html);
            $mpdf->Output('unearned_revenue_' . date('Y-m-d') . '.pdf', 'I');
            exit;
        } catch (Throwable $e) {
            // fall through to printable HTML
        }
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>' . h($reportTitle) . '</title>'
        . '<script>window.onload=function(){window.print();}</script></head><body>' . $html . '</body></html>';
    exit;
}

$pageTitle = 'Unearned Revenue (Tenant Credit Balance)';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
}
.sort-link { color: inherit; text-decoration: none; }
.sort-link:hover { text-decoration: underline; }
.sort-link .bi { font-size: .75rem; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 no-print gap-2">
    <div>
        <div class="page-header-label"><i class="bi bi-piggy-bank"></i> Unearned Revenue (Tenant Credit Balance)</div>
        <p class="text-muted mb-0 small">Tenants with advance payments posted to GL <strong>2410 – Deferred Revenue</strong>. Operational label: <strong>Credit Balance (Unearned Revenue)</strong>.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="?<?= h($queryBase(['export' => 'csv'])) ?>" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-spreadsheet"></i> Export Excel (CSV)</a>
        <a href="?<?= h($queryBase(['export' => 'pdf'])) ?>" class="btn btn-danger btn-sm" target="_blank"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        <a href="../reports.php" class="btn btn-outline-primary btn-sm">Reports Center</a>
    </div>
</div>

<?php if (!$tableExists): ?>
    <div class="alert alert-warning">Tenant credit tables are not available. Run the payment allocation migration first.</div>
<?php else: ?>

<div class="alert alert-info no-print">
    This report lists tenant credit balances that represent unearned/deferred rent liability in the general ledger.
    Click a tenant name to review receipts and allocations.
</div>

<div class="card card-round mb-4 no-print">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Tenant Name</label>
                <input type="text" name="tenant_search" class="form-control" value="<?= h($tenantSearch) ?>" placeholder="Search name, phone, email">
            </div>
            <div class="col-md-2">
                <label class="form-label">Building</label>
                <select name="building_id" class="form-select">
                    <option value="0">All buildings</option>
                    <?php foreach ($buildings as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $buildingId === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Floor</label>
                <select name="floor_id" class="form-select">
                    <option value="0">All floors</option>
                    <?php foreach ($floors as $f): ?>
                        <?php $fl = $f['building_name'] . ' - Floor ' . $f['floor_number'] . ($f['name'] ? ' / ' . $f['name'] : ''); ?>
                        <option value="<?= (int)$f['id'] ?>" <?= $floorId === (int)$f['id'] ? 'selected' : '' ?>><?= h($fl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Unit</label>
                <select name="unit_id" class="form-select">
                    <option value="0">All units</option>
                    <?php foreach ($units as $u): ?>
                        <?php $ul = $u['building_name'] . ' - ' . $u['unit_number'] . ($u['floor_number'] !== null ? ' (Floor ' . $u['floor_number'] . ')' : ''); ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $unitId === (int)$u['id'] ? 'selected' : '' ?>><?= h($ul) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Lease</label>
                <select name="lease_id" class="form-select">
                    <option value="0">All leases with credit</option>
                    <?php foreach ($leaseOptions as $lo): ?>
                        <?php $ln = $lo['lease_number'] . ' — ' . tenant_display_name($lo); ?>
                        <option value="<?= (int)$lo['id'] ?>" <?= $leaseId === (int)$lo['id'] ? 'selected' : '' ?>><?= h($ln) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Payment Date From</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Payment Date To</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Min Balance (AED)</label>
                <input type="number" step="0.01" min="0" name="min_balance" class="form-control" value="<?= $minBalance !== null ? h((string)$minBalance) : '' ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Max Balance (AED)</label>
                <input type="number" step="0.01" min="0" name="max_balance" class="form-control" value="<?= $maxBalance !== null ? h((string)$maxBalance) : '' ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Rows / Page</label>
                <select name="per_page" class="form-select">
                    <?php foreach ([25, 50, 100] as $n): ?>
                        <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100">Apply Filters</button>
                <a href="unearned_revenue_report.php" class="btn btn-outline-secondary w-100 mt-2">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Tenants With Credit</div>
            <h4 class="mb-0"><?= number_format($totalRows) ?></h4>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card border-success"><div class="card-body">
            <div class="text-muted small">Total Credit Balance (Unearned Revenue)</div>
            <h4 class="mb-0 text-success"><?= m($totalCredit) ?> AED</h4>
            <small class="text-muted">Filtered tenant credits</small>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card"><div class="card-body">
            <div class="text-muted small">GL 2410 Deferred Revenue Balance</div>
            <h4 class="mb-0"><?= m($gl2410Balance) ?> AED</h4>
            <small class="text-muted"><?php if ($gl2410AccountId): ?><a href="general_ledger.php?account_id=<?= $gl2410AccountId ?>">View GL account</a><?php else: ?>GL account 2410 not found<?php endif; ?></small>
        </div></div>
    </div>
</div>

<div class="card card-round">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Tenant Credit Balances</span>
        <span class="badge bg-light text-dark"><?= number_format($totalRows) ?> tenant<?= $totalRows === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th><a class="sort-link" href="<?= h($sortLink('tenant_name')) ?>">Tenant Name <?php if ($sort === 'tenant_name'): ?><i class="bi bi-caret-<?= $dir === 'asc' ? 'up' : 'down' ?>-fill"></i><?php endif; ?></a></th>
                    <th><a class="sort-link" href="<?= h($sortLink('lease_number')) ?>">Lease No. <?php if ($sort === 'lease_number'): ?><i class="bi bi-caret-<?= $dir === 'asc' ? 'up' : 'down' ?>-fill"></i><?php endif; ?></a></th>
                    <th><a class="sort-link" href="<?= h($sortLink('property')) ?>">Property / Unit <?php if ($sort === 'property'): ?><i class="bi bi-caret-<?= $dir === 'asc' ? 'up' : 'down' ?>-fill"></i><?php endif; ?></a></th>
                    <th><a class="sort-link" href="<?= h($sortLink('receipt_number')) ?>">Receipt No. <?php if ($sort === 'receipt_number'): ?><i class="bi bi-caret-<?= $dir === 'asc' ? 'up' : 'down' ?>-fill"></i><?php endif; ?></a></th>
                    <th class="text-end"><a class="sort-link" href="<?= h($sortLink('credit_balance')) ?>">Credit Balance (Unearned Revenue) <?php if ($sort === 'credit_balance'): ?><i class="bi bi-caret-<?= $dir === 'asc' ? 'up' : 'down' ?>-fill"></i><?php endif; ?></a></th>
                    <th><a class="sort-link" href="<?= h($sortLink('last_payment_date')) ?>">Last Payment Date <?php if ($sort === 'last_payment_date'): ?><i class="bi bi-caret-<?= $dir === 'asc' ? 'up' : 'down' ?>-fill"></i><?php endif; ?></a></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pagedRows as $row): ?>
                <?php
                $tenantName = tenant_display_name($row);
                $floorLabel = $row['floor_number'] !== null
                    ? ('Floor ' . $row['floor_number'] . ($row['floor_name'] ? ' / ' . $row['floor_name'] : ''))
                    : 'No floor';
                $property = trim(($row['building_name'] ?? '-') . ' / ' . $floorLabel . ' / ' . ($row['unit_number'] ?? '-'));
                $drillHref = !empty($row['lease_id'])
                    ? 'receipt_allocation.php?lease_id=' . (int)$row['lease_id']
                    : '../tenant_view.php?id=' . (int)$row['tenant_id'];
                $receiptLabel = $row['receipt_number'] ?: ($row['payment_id'] ? ('#' . $row['payment_id']) : '-');
                ?>
                <tr>
                    <td>
                        <a href="<?= h($drillHref) ?>" title="Review receipts and allocations"><?= h($tenantName) ?></a>
                        <a href="../tenant_view.php?id=<?= (int)$row['tenant_id'] ?>" class="ms-1 text-muted no-print" title="Tenant profile"><i class="bi bi-person-lines-fill"></i></a>
                    </td>
                    <td>
                        <?php if (!empty($row['lease_id'])): ?>
                            <a href="../lease_view.php?id=<?= (int)$row['lease_id'] ?>"><?= h($row['lease_number']) ?></a>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h($property) ?></td>
                    <td>
                        <?php if (!empty($row['payment_id'])): ?>
                            <a href="../payment_view.php?id=<?= (int)$row['payment_id'] ?>"><?= h($receiptLabel) ?></a>
                        <?php else: ?>
                            <?= h($receiptLabel) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end fw-bold text-success"><?= m($row['credit_balance']) ?></td>
                    <td><?= h($row['last_payment_date'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$pagedRows): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No tenants with credit balance match the current filters.</td></tr>
            <?php endif; ?>
            </tbody>
            <tfoot class="table-light">
                <tr>
                    <td colspan="4" class="text-end fw-bold">Total Unearned Revenue (filtered)</td>
                    <td class="text-end fw-bold text-success"><?= m($totalCredit) ?> AED</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
        <div class="card-footer no-print">
            <nav aria-label="Report pagination">
                <ul class="pagination pagination-sm justify-content-center mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= h($queryBase(['page' => max(1, $page - 1)])) ?>">Previous</a>
                    </li>
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= h($queryBase(['page' => $i])) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= h($queryBase(['page' => min($totalPages, $page + 1)])) ?>">Next</a>
                    </li>
                </ul>
            </nav>
            <div class="text-center text-muted small mt-2">
                Showing <?= $totalRows ? ($offset + 1) : 0 ?>–<?= min($offset + $perPage, $totalRows) ?> of <?= number_format($totalRows) ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
