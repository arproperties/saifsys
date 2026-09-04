<?php
/**
 * AP Aging Report — vendor-grouped bill balances (Phase 1 enhancement).
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

$asOf = !empty($_GET['as_of_date']) ? (string)$_GET['as_of_date'] : date('Y-m-d');
$vendorId = (int)($_GET['vendor_id'] ?? 0);
$buildingId = (int)($_GET['building_id'] ?? 0);
$sort = (string)($_GET['sort'] ?? 'due');
$view = (string)($_GET['view'] ?? 'detail'); // detail | summary
$export = (string)($_GET['export'] ?? '');

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function m($n)
{
    return number_format((float)$n, 2);
}

$vendorsStmt = $conn->prepare("SELECT id, vendor_name FROM re_vendors WHERE company_id = ? ORDER BY vendor_name");
$vendorsStmt->execute([$companyId]);
$vendors = $vendorsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$buildingsStmt = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildingsStmt->execute([$companyId]);
$buildings = $buildingsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$sql = "
    SELECT vi.*, v.vendor_name, DATEDIFF(?, vi.due_date) AS overdue_days
    FROM re_vendor_invoices vi
    JOIN re_vendors v ON v.id = vi.vendor_id AND v.company_id = vi.company_id
    WHERE vi.company_id = ?
      AND vi.posting_status = 'posted'
      AND vi.status NOT IN ('paid', 'void', 'cancelled', 'draft')
      AND COALESCE(vi.balance_due, vi.total_amount - vi.paid_amount) > 0.005
      AND vi.invoice_date <= ?
";
$params = [$asOf, $companyId, $asOf];
if ($vendorId > 0) {
    $sql .= " AND vi.vendor_id = ?";
    $params[] = $vendorId;
}
if ($buildingId > 0) {
    $sql .= " AND EXISTS (
        SELECT 1 FROM re_vendor_invoice_items vii
        WHERE vii.company_id = vi.company_id AND vii.invoice_id = vi.id AND vii.building_id = ?
    )";
    $params[] = $buildingId;
}
if ($sort === 'vendor') {
    $sql .= " ORDER BY v.vendor_name ASC, vi.due_date ASC, vi.id ASC";
} elseif ($sort === 'balance') {
    $sql .= " ORDER BY COALESCE(vi.balance_due, vi.total_amount - vi.paid_amount) DESC, vi.due_date ASC";
} else {
    $sql .= " ORDER BY vi.due_date ASC, v.vendor_name ASC, vi.id ASC";
}

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$bills = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$aging = ['current' => 0.0, '30' => 0.0, '60' => 0.0, '90' => 0.0, 'over90' => 0.0];
$byVendor = [];
foreach ($bills as &$b) {
    $bal = (float)($b['balance_due'] ?? max(0, $b['total_amount'] - $b['paid_amount']));
    $days = (int)$b['overdue_days'];
    if ($days <= 0) {
        $bucket = 'current';
    } elseif ($days <= 30) {
        $bucket = '30';
    } elseif ($days <= 60) {
        $bucket = '60';
    } elseif ($days <= 90) {
        $bucket = '90';
    } else {
        $bucket = 'over90';
    }
    $b['bucket'] = $bucket;
    $b['balance_calc'] = $bal;
    $aging[$bucket] += $bal;

    $vid = (int)$b['vendor_id'];
    if (!isset($byVendor[$vid])) {
        $byVendor[$vid] = [
            'vendor_id' => $vid,
            'vendor_name' => $b['vendor_name'],
            'bills' => 0,
            'balance' => 0.0,
            'current' => 0.0,
            '30' => 0.0,
            '60' => 0.0,
            '90' => 0.0,
            'over90' => 0.0,
        ];
    }
    $byVendor[$vid]['bills']++;
    $byVendor[$vid]['balance'] += $bal;
    $byVendor[$vid][$bucket] += $bal;
}
unset($b);
$totalOutstanding = array_sum($aging);
$availableAdvances = 0.0;
try {
    $ab = $conn->prepare("SELECT COALESCE(SUM(balance_aed), 0) FROM re_vendor_advance_balances WHERE company_id = ?");
    $ab->execute([$companyId]);
    $availableAdvances = (float)$ab->fetchColumn();
    if ($vendorId > 0) {
        $ab = $conn->prepare("SELECT COALESCE(balance_aed, 0) FROM re_vendor_advance_balances WHERE company_id = ? AND vendor_id = ?");
        $ab->execute([$companyId, $vendorId]);
        $availableAdvances = (float)$ab->fetchColumn();
    }
} catch (Throwable $e) {
    $availableAdvances = 0.0;
}
$netVendorPosition = $totalOutstanding - $availableAdvances;
uasort($byVendor, static fn($a, $b) => $b['balance'] <=> $a['balance']);

if ($export === 'excel') {
    if ($view === 'summary') {
        $rows = [];
        foreach ($byVendor as $v) {
            $rows[] = [
                'vendor' => $v['vendor_name'],
                'bills' => $v['bills'],
                'current' => round($v['current'], 2),
                'd30' => round($v['30'], 2),
                'd60' => round($v['60'], 2),
                'd90' => round($v['90'], 2),
                'over90' => round($v['over90'], 2),
                'total' => round($v['balance'], 2),
            ];
        }
        accounting_export_excel_or_csv(
            $rows,
            [
                'vendor' => 'Vendor',
                'bills' => 'Bills',
                'current' => 'Current',
                'd30' => '1-30',
                'd60' => '31-60',
                'd90' => '61-90',
                'over90' => '90+',
                'total' => 'Total',
            ],
            'ap_aging_summary_' . $asOf,
            'AP Aging Summary as of ' . $asOf
        );
    }
    $rows = [];
    foreach ($bills as $b) {
        $rows[] = [
            'vendor' => $b['vendor_name'],
            'bill' => $b['invoice_number'],
            'bill_date' => $b['invoice_date'],
            'due_date' => $b['due_date'],
            'total' => round((float)$b['total_amount'], 2),
            'paid' => round((float)$b['paid_amount'], 2),
            'balance' => round($b['balance_calc'], 2),
            'bucket' => $b['bucket'],
            'days' => max(0, (int)$b['overdue_days']),
        ];
    }
    accounting_export_excel_or_csv(
        $rows,
        [
            'vendor' => 'Vendor',
            'bill' => 'Bill #',
            'bill_date' => 'Bill Date',
            'due_date' => 'Due Date',
            'total' => 'Total',
            'paid' => 'Paid',
            'balance' => 'Balance',
            'bucket' => 'Bucket',
            'days' => 'Days Overdue',
        ],
        'ap_aging_detail_' . $asOf,
        'AP Aging Detail as of ' . $asOf
    );
}

$reApUiEnhanced = true;
$pageTitle = 'AP Aging';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 re-ap-print-hide">
    <div class="page-header-label">
        <i class="bi bi-clock-history me-1"></i>
        AP Aging Report
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'excel']))) ?>" class="btn btn-outline-success">Excel</a>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
        <a href="vendor_statement.php<?= $vendorId ? ('?vendor_id=' . $vendorId) : '' ?>" class="btn btn-outline-primary">Vendor SOA</a>
        <a href="vendor_ledger.php" class="btn btn-outline-primary">Vendor Ledger</a>
    </div>
</div>

<div class="alert alert-info re-ap-print-hide">
    Bill-balance aging: posted vendor bills minus payment allocations. As of <?= h($asOf) ?>.
</div>

<div class="card card-round mb-4 re-ap-print-hide">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label">As of</label>
                <input type="date" name="as_of_date" class="form-control" value="<?= h($asOf) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Vendor</label>
                <select name="vendor_id" class="form-select">
                    <option value="">All vendors</option>
                    <?php foreach ($vendors as $v): ?>
                        <option value="<?= (int)$v['id'] ?>" <?= $vendorId === (int)$v['id'] ? 'selected' : '' ?>><?= h($v['vendor_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Building</label>
                <select name="building_id" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($buildings as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $buildingId === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Sort</label>
                <select name="sort" class="form-select">
                    <option value="due" <?= $sort === 'due' ? 'selected' : '' ?>>Due date</option>
                    <option value="vendor" <?= $sort === 'vendor' ? 'selected' : '' ?>>Vendor</option>
                    <option value="balance" <?= $sort === 'balance' ? 'selected' : '' ?>>Balance</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">View</label>
                <select name="view" class="form-select">
                    <option value="detail" <?= $view === 'detail' ? 'selected' : '' ?>>Bill detail</option>
                    <option value="summary" <?= $view === 'summary' ? 'selected' : '' ?>>Vendor summary</option>
                </select>
            </div>
            <div class="col-md-1">
                <button class="btn btn-primary w-100">Go</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach (['current' => 'Current', '30' => '1-30', '60' => '31-60', '90' => '61-90', 'over90' => '90+'] as $k => $label): ?>
        <div class="col">
            <div class="card card-round re-ap-card-metric">
                <div class="card-body text-center">
                    <div class="metric-label"><?= h($label) ?></div>
                    <div class="metric-value"><?= m($aging[$k]) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="col">
        <div class="card card-round re-ap-card-metric border-primary">
            <div class="card-body text-center">
                <div class="metric-label">Gross AP</div>
                <div class="metric-value text-primary"><?= m($totalOutstanding) ?></div>
            </div>
        </div>
    </div>
</div>
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card card-round re-ap-card-metric">
            <div class="card-body">
                <div class="metric-label">Available Vendor Advances</div>
                <div class="metric-value text-success"><?= m($availableAdvances) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-round re-ap-card-metric">
            <div class="card-body">
                <div class="metric-label">Net Vendor Position</div>
                <div class="metric-value"><?= m($netVendorPosition) ?></div>
                <div class="small text-muted">Gross AP − Available Advances</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="alert alert-light border mb-0 small">
            Aging buckets below are based on <strong>gross outstanding bills</strong> only. Advances are not netted into buckets until applied to specific bills.
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-5">
        <div class="card card-round">
            <div class="card-header">Aging Buckets</div>
            <div class="card-body">
                <?php
                $bucketRows = [
                    'Current' => $aging['current'],
                    '1-30' => $aging['30'],
                    '31-60' => $aging['60'],
                    '61-90' => $aging['90'],
                    '90+' => $aging['over90'],
                ];
                $bucketMax = max(1.0, ...array_map('floatval', array_values($bucketRows)));
                foreach ($bucketRows as $label => $amt):
                    $pct = min(100, round(((float)$amt / $bucketMax) * 100, 1));
                ?>
                    <div class="re-ap-bar-row">
                        <div class="re-ap-bar-label"><?= h($label) ?></div>
                        <div class="re-ap-bar-track"><div class="re-ap-bar-fill" style="width:<?= $pct ?>%"></div></div>
                        <div class="re-ap-bar-amt"><?= m($amt) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <?php if ($view === 'summary'): ?>
            <div class="card card-round">
                <div class="card-header">Vendor Summary</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover re-ap-table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Vendor</th>
                                <th class="text-end">Bills</th>
                                <th class="text-end">Current</th>
                                <th class="text-end">1-30</th>
                                <th class="text-end">31-60</th>
                                <th class="text-end">61-90</th>
                                <th class="text-end">90+</th>
                                <th class="text-end">Total</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($byVendor as $v): ?>
                                <tr>
                                    <td><?= h($v['vendor_name']) ?></td>
                                    <td class="text-end"><?= (int)$v['bills'] ?></td>
                                    <td class="text-end"><?= m($v['current']) ?></td>
                                    <td class="text-end"><?= m($v['30']) ?></td>
                                    <td class="text-end"><?= m($v['60']) ?></td>
                                    <td class="text-end"><?= m($v['90']) ?></td>
                                    <td class="text-end"><?= m($v['over90']) ?></td>
                                    <td class="text-end fw-semibold"><?= m($v['balance']) ?></td>
                                    <td class="re-ap-print-hide">
                                        <a class="btn btn-sm btn-outline-primary" href="vendor_statement.php?vendor_id=<?= (int)$v['vendor_id'] ?>&date_to=<?= h(urlencode($asOf)) ?>">SOA</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$byVendor): ?>
                                <tr><td colspan="9" class="text-center text-muted">No outstanding AP</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="card card-round">
                <div class="card-header">Bill Detail</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover re-ap-table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Vendor</th>
                                <th>Bill #</th>
                                <th>Bill Date</th>
                                <th>Due Date</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">Paid</th>
                                <th class="text-end">Balance</th>
                                <th>Bucket</th>
                                <th>Days</th>
                                <th class="re-ap-print-hide"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bills as $b): ?>
                                <tr>
                                    <td><?= h($b['vendor_name']) ?></td>
                                    <td><a href="vendor_bill_view.php?id=<?= (int)$b['id'] ?>"><?= h($b['invoice_number']) ?></a></td>
                                    <td><?= h($b['invoice_date']) ?></td>
                                    <td><?= h($b['due_date']) ?></td>
                                    <td class="text-end"><?= m($b['total_amount']) ?></td>
                                    <td class="text-end"><?= m($b['paid_amount']) ?></td>
                                    <td class="text-end text-danger"><?= m($b['balance_calc']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= h($b['bucket']) ?></span></td>
                                    <td><?= max(0, (int)$b['overdue_days']) ?></td>
                                    <td class="re-ap-print-hide">
                                        <a href="vendor_payment_add.php?vendor_id=<?= (int)$b['vendor_id'] ?>&bill_id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-success">Pay</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$bills): ?>
                                <tr><td colspan="10" class="text-center text-muted">No outstanding AP as of <?= h($asOf) ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
