<?php
/**
 * Building-wise Expense Report (Phase 1)
 * Document-line analysis from posted vendor bill lines and Quick Paid Expenses (building_id).
 * Not a new GL dimension — no posting-engine changes.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/vendor_ap_helper.php';
require_once __DIR__ . '/export_excel_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$companyId = (int)(current_company_id($conn) ?: 0);
if ($companyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}

$dateFrom = !empty($_GET['date_from']) ? (string)$_GET['date_from'] : date('Y-m-01');
$dateTo = !empty($_GET['date_to']) ? (string)$_GET['date_to'] : date('Y-m-d');
$buildingId = (int)($_GET['building_id'] ?? 0);
$vendorId = (int)($_GET['vendor_id'] ?? 0);
$accountId = (int)($_GET['account_id'] ?? 0);
$export = (string)($_GET['export'] ?? '');

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function m($n)
{
    return number_format((float)$n, 2);
}

$buildingsStmt = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildingsStmt->execute([$companyId]);
$buildings = $buildingsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$vendorsStmt = $conn->prepare("SELECT id, vendor_name FROM re_vendors WHERE company_id = ? ORDER BY vendor_name");
$vendorsStmt->execute([$companyId]);
$vendors = $vendorsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$accountsStmt = $conn->prepare("
    SELECT id, account_code, account_name
    FROM re_chart_of_accounts
    WHERE company_id = ? AND is_active = 1 AND is_header = 0 AND account_type IN ('Expense', 'Asset')
    ORDER BY account_code
");
$accountsStmt->execute([$companyId]);
$accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$sql = "
    SELECT
        vii.id AS line_id,
        vii.invoice_id,
        vii.building_id,
        vii.expense_account_id,
        COALESCE(vii.line_total, vii.total_price, 0) AS line_total,
        COALESCE(vii.subtotal, 0) AS line_net,
        COALESCE(vii.vat_amount, 0) AS line_vat,
        COALESCE(vii.service_name, vii.line_description, vii.description, '') AS line_desc,
        vi.invoice_number,
        vi.invoice_date,
        vi.vendor_id,
        v.vendor_name,
        b.name AS building_name,
        coa.account_code,
        coa.account_name,
        DATE_FORMAT(vi.invoice_date, '%Y-%m') AS ym
    FROM re_vendor_invoice_items vii
    JOIN re_vendor_invoices vi ON vi.id = vii.invoice_id AND vi.company_id = vii.company_id
    JOIN re_vendors v ON v.id = vi.vendor_id AND v.company_id = vi.company_id
    LEFT JOIN re_buildings b ON b.id = vii.building_id AND b.company_id = vii.company_id
    LEFT JOIN re_chart_of_accounts coa ON coa.id = vii.expense_account_id AND coa.company_id = vii.company_id
    WHERE vii.company_id = ?
      AND vi.posting_status = 'posted'
      AND vi.status NOT IN ('void', 'cancelled')
      AND vi.invoice_date BETWEEN ? AND ?
";
$params = [$companyId, $dateFrom, $dateTo];
if ($buildingId > 0) {
    $sql .= " AND vii.building_id = ?";
    $params[] = $buildingId;
}
if ($vendorId > 0) {
    $sql .= " AND vi.vendor_id = ?";
    $params[] = $vendorId;
}
if ($accountId > 0) {
    $sql .= " AND vii.expense_account_id = ?";
    $params[] = $accountId;
}
$sql .= " ORDER BY vi.invoice_date ASC, vii.id ASC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$lines = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($lines as &$ln) {
    $ln['doc_type'] = 'Vendor Bill';
    $ln['source_kind'] = 'vendor_bill';
}
unset($ln);

// Quick Paid Expenses (erp_expense_*) with optional building_id — not AP bills.
require_once __DIR__ . '/../../../includes/erp_expense_posting.php';
if (erp_expense_lines_has_building_column($conn)) {
    $qpeSql = "
        SELECT
            el.id AS line_id,
            eh.id AS invoice_id,
            el.building_id,
            el.account_id AS expense_account_id,
            COALESCE(el.line_total, 0) AS line_total,
            COALESCE(el.line_subtotal, 0) AS line_net,
            COALESCE(el.line_vat, 0) AS line_vat,
            COALESCE(el.description, '') AS line_desc,
            eh.expense_number AS invoice_number,
            eh.expense_date AS invoice_date,
            eh.vendor_id,
            v.vendor_name,
            b.name AS building_name,
            coa.account_code,
            coa.account_name,
            DATE_FORMAT(eh.expense_date, '%Y-%m') AS ym,
            'Quick Paid Expense' AS doc_type,
            'quick_paid' AS source_kind
        FROM erp_expense_lines el
        JOIN erp_expense_headers eh ON eh.id = el.expense_id
        LEFT JOIN re_vendors v ON v.id = eh.vendor_id AND v.company_id = eh.company_id
        LEFT JOIN re_buildings b ON b.id = el.building_id AND b.company_id = eh.company_id
        LEFT JOIN re_chart_of_accounts coa ON coa.id = el.account_id AND coa.company_id = eh.company_id
        WHERE eh.company_id = ?
          AND eh.source_module = 'realestate'
          AND eh.status = 'posted'
          AND eh.expense_date BETWEEN ? AND ?
    ";
    $qpeParams = [$companyId, $dateFrom, $dateTo];
    if ($buildingId > 0) {
        $qpeSql .= " AND el.building_id = ?";
        $qpeParams[] = $buildingId;
    }
    if ($vendorId > 0) {
        $qpeSql .= " AND eh.vendor_id = ?";
        $qpeParams[] = $vendorId;
    }
    if ($accountId > 0) {
        $qpeSql .= " AND el.account_id = ?";
        $qpeParams[] = $accountId;
    }
    $qpeSql .= " ORDER BY eh.expense_date ASC, el.id ASC";
    try {
        $qpeStmt = $conn->prepare($qpeSql);
        $qpeStmt->execute($qpeParams);
        $qpeLines = $qpeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $lines = array_merge($lines, $qpeLines);
        usort($lines, static function ($a, $b) {
            $da = (string)($a['invoice_date'] ?? '');
            $db = (string)($b['invoice_date'] ?? '');
            if ($da === $db) {
                return ((int)($a['line_id'] ?? 0)) <=> ((int)($b['line_id'] ?? 0));
            }
            return $da <=> $db;
        });
    } catch (Throwable $e) {
        // Keep vendor bill lines if Quick Paid query fails on partial schema.
    }
}

$byBuilding = [];
$byMonth = [];
$totalExpense = 0.0;
$billIds = [];
foreach ($lines as $ln) {
    $amt = (float)$ln['line_total'];
    $totalExpense += $amt;
    $billIds[(int)$ln['invoice_id'] . ':' . (string)($ln['source_kind'] ?? 'vendor_bill')] = true;
    $bid = (int)($ln['building_id'] ?? 0);
    $bkey = $bid > 0 ? (string)$bid : '0';
    $bname = $bid > 0 ? (string)($ln['building_name'] ?: ('Building #' . $bid)) : 'Unassigned';
    if (!isset($byBuilding[$bkey])) {
        $byBuilding[$bkey] = [
            'building_id' => $bid,
            'building_name' => $bname,
            'amount' => 0.0,
            'net' => 0.0,
            'vat' => 0.0,
            'lines' => 0,
            'bills' => [],
        ];
    }
    $byBuilding[$bkey]['amount'] += $amt;
    $byBuilding[$bkey]['net'] += (float)$ln['line_net'];
    $byBuilding[$bkey]['vat'] += (float)$ln['line_vat'];
    $byBuilding[$bkey]['lines']++;
    $byBuilding[$bkey]['bills'][(int)$ln['invoice_id'] . ':' . (string)($ln['source_kind'] ?? 'vendor_bill')] = true;

    $ym = (string)$ln['ym'];
    if (!isset($byMonth[$ym])) {
        $byMonth[$ym] = 0.0;
    }
    $byMonth[$ym] += $amt;
}
ksort($byMonth);
uasort($byBuilding, static fn($a, $b) => $b['amount'] <=> $a['amount']);
$billCount = count($billIds);

if ($export === 'excel') {
    $rows = [];
    foreach ($lines as $ln) {
        $bid = (int)($ln['building_id'] ?? 0);
        $rows[] = [
            'date' => $ln['invoice_date'],
            'building' => $bid > 0 ? ($ln['building_name'] ?: ('#' . $bid)) : 'Unassigned',
            'vendor' => $ln['vendor_name'],
            'bill' => $ln['invoice_number'],
            'type' => $ln['doc_type'] ?? 'Vendor Bill',
            'account' => trim(($ln['account_code'] ?? '') . ' ' . ($ln['account_name'] ?? '')),
            'description' => $ln['line_desc'],
            'net' => round((float)$ln['line_net'], 2),
            'vat' => round((float)$ln['line_vat'], 2),
            'total' => round((float)$ln['line_total'], 2),
        ];
    }
    accounting_export_excel_or_csv(
        $rows,
        [
            'date' => 'Date',
            'building' => 'Building',
            'vendor' => 'Vendor',
            'bill' => 'Doc #',
            'type' => 'Type',
            'account' => 'Expense Account',
            'description' => 'Description',
            'net' => 'Net',
            'vat' => 'VAT',
            'total' => 'Line Total',
        ],
        'building_expense_' . $dateFrom . '_' . $dateTo,
        'Building Expense Report'
    );
}

$reApUiEnhanced = true;
$pageTitle = 'Building Expenses';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 re-ap-print-hide">
    <div class="page-header-label">
        <i class="bi bi-building me-1"></i>
        Building-wise Expense Report
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'excel']))) ?>" class="btn btn-outline-success">Excel</a>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
        <a href="vendor_bills.php" class="btn btn-outline-secondary">Vendor Bills</a>
    </div>
</div>

<div class="alert alert-info re-ap-print-hide">
    Expense analysis from posted vendor bill lines (net + VAT as on the line). Lines without a building are grouped as <strong>Unassigned</strong>.
    This is not a GL dimensional report.
</div>

<div class="card card-round mb-4 re-ap-print-hide">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label">From</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Building</label>
                <select name="building_id" class="form-select">
                    <option value="">All buildings</option>
                    <?php foreach ($buildings as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $buildingId === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Vendor</label>
                <select name="vendor_id" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($vendors as $v): ?>
                        <option value="<?= (int)$v['id'] ?>" <?= $vendorId === (int)$v['id'] ? 'selected' : '' ?>><?= h($v['vendor_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Account</label>
                <select name="account_id" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= (int)$a['id'] ?>" <?= $accountId === (int)$a['id'] ? 'selected' : '' ?>>
                            <?= h($a['account_code'] . ' ' . $a['account_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <button class="btn btn-primary w-100">Go</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card card-round re-ap-card-metric">
            <div class="card-body">
                <div class="metric-label">Total Expense</div>
                <div class="metric-value"><?= m($totalExpense) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-round re-ap-card-metric">
            <div class="card-body">
                <div class="metric-label">Bills</div>
                <div class="metric-value"><?= (int)$billCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-round re-ap-card-metric">
            <div class="card-body">
                <div class="metric-label">Line Items</div>
                <div class="metric-value"><?= count($lines) ?></div>
            </div>
        </div>
    </div>
</div>

<?php
$buildingAmounts = array_map(static fn($r) => (float)$r['amount'], array_values($byBuilding));
$monthAmounts = array_map(static fn($v) => (float)$v, array_values($byMonth));
$chartMaxBuilding = $buildingAmounts ? max($buildingAmounts) : 1.0;
$chartMaxMonth = $monthAmounts ? max($monthAmounts) : 1.0;
if ($chartMaxBuilding <= 0) {
    $chartMaxBuilding = 1.0;
}
if ($chartMaxMonth <= 0) {
    $chartMaxMonth = 1.0;
}
?>
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card card-round">
            <div class="card-header">By Building</div>
            <div class="card-body">
                <?php if ($byBuilding): ?>
                    <?php foreach ($byBuilding as $row): ?>
                        <?php $pct = min(100, round(((float)$row['amount'] / $chartMaxBuilding) * 100, 1)); ?>
                        <div class="re-ap-bar-row">
                            <div class="re-ap-bar-label" title="<?= h($row['building_name']) ?>"><?= h($row['building_name']) ?></div>
                            <div class="re-ap-bar-track"><div class="re-ap-bar-fill" style="width:<?= $pct ?>%"></div></div>
                            <div class="re-ap-bar-amt"><?= m($row['amount']) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted">No data</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card card-round">
            <div class="card-header">Monthly Trend</div>
            <div class="card-body">
                <?php if ($byMonth): ?>
                    <?php foreach ($byMonth as $ym => $amt): ?>
                        <?php $pct = min(100, round(((float)$amt / $chartMaxMonth) * 100, 1)); ?>
                        <div class="re-ap-bar-row">
                            <div class="re-ap-bar-label"><?= h($ym) ?></div>
                            <div class="re-ap-bar-track"><div class="re-ap-bar-fill green" style="width:<?= $pct ?>%"></div></div>
                            <div class="re-ap-bar-amt"><?= m($amt) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted">No data</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card card-round mb-4">
    <div class="card-header">Building Summary</div>
    <div class="table-responsive">
        <table class="table table-sm table-hover re-ap-table mb-0">
            <thead class="table-light">
                <tr>
                    <th>Building</th>
                    <th class="text-end">Lines</th>
                    <th class="text-end">Bills</th>
                    <th class="text-end">Net</th>
                    <th class="text-end">VAT</th>
                    <th class="text-end">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($byBuilding as $row): ?>
                    <tr>
                        <td><?= h($row['building_name']) ?></td>
                        <td class="text-end"><?= (int)$row['lines'] ?></td>
                        <td class="text-end"><?= count($row['bills']) ?></td>
                        <td class="text-end"><?= m($row['net']) ?></td>
                        <td class="text-end"><?= m($row['vat']) ?></td>
                        <td class="text-end fw-semibold"><?= m($row['amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$byBuilding): ?>
                    <tr><td colspan="6" class="text-center text-muted">No posted bill lines in range</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if ($byBuilding): ?>
                <tfoot>
                    <tr class="table-light">
                        <th>Total</th>
                        <th class="text-end"><?= count($lines) ?></th>
                        <th class="text-end"><?= (int)$billCount ?></th>
                        <th class="text-end"><?= m(array_sum(array_column($byBuilding, 'net'))) ?></th>
                        <th class="text-end"><?= m(array_sum(array_column($byBuilding, 'vat'))) ?></th>
                        <th class="text-end"><?= m($totalExpense) ?></th>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<div class="card card-round">
    <div class="card-header">Line Detail</div>
    <div class="table-responsive">
        <table class="table table-sm table-hover re-ap-table mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Building</th>
                    <th>Vendor</th>
                    <th>Type</th>
                    <th>Doc #</th>
                    <th>Account</th>
                    <th>Description</th>
                    <th class="text-end">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $ln): ?>
                    <?php
                    $bid = (int)($ln['building_id'] ?? 0);
                    $bname = $bid > 0 ? ($ln['building_name'] ?: ('#' . $bid)) : 'Unassigned';
                    $isQpe = (($ln['source_kind'] ?? '') === 'quick_paid');
                    ?>
                    <tr>
                        <td><?= h($ln['invoice_date']) ?></td>
                        <td><?= h($bname) ?></td>
                        <td><?= h($ln['vendor_name'] ?? '') ?></td>
                        <td><span class="badge bg-<?= $isQpe ? 'info' : 'secondary' ?>"><?= h($ln['doc_type'] ?? 'Vendor Bill') ?></span></td>
                        <td>
                            <?php if ($isQpe): ?>
                                <a href="../expense_edit.php?id=<?= (int)$ln['invoice_id'] ?>"><?= h($ln['invoice_number']) ?></a>
                            <?php else: ?>
                                <a href="vendor_bill_view.php?id=<?= (int)$ln['invoice_id'] ?>"><?= h($ln['invoice_number']) ?></a>
                            <?php endif; ?>
                        </td>
                        <td><?= h(trim(($ln['account_code'] ?? '') . ' ' . ($ln['account_name'] ?? ''))) ?></td>
                        <td><?= h($ln['line_desc']) ?></td>
                        <td class="text-end"><?= m($ln['line_total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$lines): ?>
                    <tr><td colspan="7" class="text-center text-muted">No lines found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>