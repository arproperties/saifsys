<?php
/**
 * Payments Made list — light filter/summary polish + SOA links (Phase 1).
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
require_once __DIR__ . '/../../../includes/csrf.php';
require_once __DIR__ . '/export_excel_helper.php';
require_once __DIR__ . '/../includes/vendor_ap_helper.php';

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

$vendorId = (int)($_GET['vendor_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$dateFrom = !empty($_GET['date_from']) ? (string)$_GET['date_from'] : '';
$dateTo = !empty($_GET['date_to']) ? (string)$_GET['date_to'] : '';
$export = (string)($_GET['export'] ?? '');
$error = '';

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function m($n)
{
    return number_format((float)$n, 2);
}

$refundTableReady = function_exists('re_ap_advance_refund_table_ready') && re_ap_advance_refund_table_ready($conn);
$refundSumSql = $refundTableReady
    ? "COALESCE((
        SELECT SUM(r.amount) FROM re_vendor_advance_refunds r
        WHERE r.company_id = vp.company_id AND r.vendor_payment_id = vp.id AND r.status = 'posted'
      ), 0)"
    : '0';

$vendorsStmt = $conn->prepare("SELECT id, vendor_name FROM re_vendors WHERE company_id = ? ORDER BY vendor_name");
$vendorsStmt->execute([$companyId]);
$vendors = $vendorsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$sql = "
    SELECT vp.*, v.vendor_name, ba.account_name,
      COALESCE((
        SELECT SUM(a.amount_allocated) FROM re_vendor_payment_allocations a
        WHERE a.company_id = vp.company_id AND a.vendor_payment_id = vp.id
      ), 0) AS allocated_to_bills,
      COALESCE(vp.advance_amount, 0) AS original_advance,
      COALESCE((
        SELECT SUM(app.amount) FROM re_vendor_advance_applications app
        WHERE app.company_id = vp.company_id AND app.vendor_payment_id = vp.id AND app.status = 'posted'
      ), 0) AS advance_applied,
      COALESCE((
        SELECT SUM(d.vat_amount) FROM re_vendor_advance_vat_documents d
        WHERE d.company_id = vp.company_id AND d.vendor_payment_id = vp.id AND d.status = 'posted'
      ), 0) AS advance_vat_posted,
      {$refundSumSql} AS advance_refunded
    FROM re_vendor_payments vp
    JOIN re_vendors v ON v.id = vp.vendor_id AND v.company_id = vp.company_id
    LEFT JOIN re_bank_accounts ba ON ba.id = vp.bank_account_id
    WHERE vp.company_id = ?
";
$params = [$companyId];
if ($vendorId > 0) {
    $sql .= " AND vp.vendor_id = ?";
    $params[] = $vendorId;
}
if ($dateFrom !== '') {
    $sql .= " AND vp.payment_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $sql .= " AND vp.payment_date <= ?";
    $params[] = $dateTo;
}
if ($q !== '') {
    $sql .= " AND (vp.reference_number LIKE ? OR v.vendor_name LIKE ? OR CONCAT('PAY-', vp.id) LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$sql .= " ORDER BY vp.payment_date DESC, vp.id DESC LIMIT 500";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$totalPaid = 0.0;
foreach ($rows as &$r) {
    // Remaining = original − applied − Advance VAT − refunded
    $r['advance_remaining'] = max(0, round(
        (float)$r['original_advance']
        - (float)$r['advance_applied']
        - (float)($r['advance_vat_posted'] ?? 0)
        - (float)($r['advance_refunded'] ?? 0),
        2
    ));
    if (($r['status'] ?? '') === 'posted') {
        $totalPaid += (float)$r['amount'];
    }
}
unset($r);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reverse_payment') {
    try {
        csrf_verify();
        $payId = (int)($_POST['payment_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? 'Payment reversed'));
        $res = re_ap_reverse_vendor_payment($conn, $companyId, $payId, $reason, current_user_id());
        if (empty($res['success'])) {
            throw new RuntimeException($res['error'] ?? 'Reverse failed');
        }
        header('Location: vendor_payments.php?' . http_build_query(array_filter([
            'vendor_id' => $vendorId ?: null,
            'date_from' => $dateFrom ?: null,
            'date_to' => $dateTo ?: null,
            'q' => $q ?: null,
            'ok' => 1,
        ])));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($export === 'excel') {
    $exportRows = [];
    foreach ($rows as $r) {
        $exportRows[] = [
            'date' => $r['payment_date'],
            'vendor' => $r['vendor_name'],
            'method' => $r['payment_method'],
            'reference' => $r['reference_number'] ?: ('PAY-' . $r['id']),
            'amount' => round((float)$r['amount'], 2),
            'allocated' => round((float)$r['allocated_to_bills'], 2),
            'original_advance' => round((float)$r['original_advance'], 2),
            'advance_applied' => round((float)$r['advance_applied'], 2),
            'advance_vat_posted' => round((float)($r['advance_vat_posted'] ?? 0), 2),
            'advance_refunded' => round((float)($r['advance_refunded'] ?? 0), 2),
            'advance_remaining' => round((float)$r['advance_remaining'], 2),
            'status' => $r['status'],
            'journal_id' => $r['journal_id'] ?: '',
        ];
    }
    accounting_export_excel_or_csv(
        $exportRows,
        [
            'date' => 'Date',
            'vendor' => 'Vendor',
            'method' => 'Method',
            'reference' => 'Reference',
            'amount' => 'Payment Amount',
            'allocated' => 'Allocated to Bills',
            'original_advance' => 'Original Advance',
            'advance_applied' => 'Advance Applied',
            'advance_vat_posted' => 'Advance VAT',
            'advance_refunded' => 'Refunded',
            'advance_remaining' => 'Advance Remaining',
            'status' => 'Status',
            'journal_id' => 'Journal ID',
        ],
        'vendor_payments',
        'Payments Made'
    );
}

$reApUiEnhanced = true;
$pageTitle = 'Payments Made';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<?php if (!empty($_GET['ok'])): ?><div class="alert alert-success">Payment reversed successfully.</div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4 re-ap-print-hide">
    <div class="page-header-label">
        <i class="bi bi-cash-stack me-1"></i>
        Payments Made
    </div>
    <div class="d-flex gap-2">
        <a href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'excel']))) ?>" class="btn btn-outline-success">Excel</a>
        <a href="vendor_statement.php<?= $vendorId ? ('?vendor_id=' . $vendorId) : '' ?>" class="btn btn-outline-primary">Vendor SOA</a>
        <a href="vendor_payment_add.php<?= $vendorId ? ('?vendor_id=' . $vendorId) : '' ?>" class="btn btn-success">New Payment</a>
    </div>
</div>

<div class="card card-round mb-3 re-ap-print-hide">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Vendor</label>
                <select name="vendor_id" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($vendors as $v): ?>
                        <option value="<?= (int)$v['id'] ?>" <?= $vendorId === (int)$v['id'] ? 'selected' : '' ?>><?= h($v['vendor_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">From</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="Reference / vendor">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card card-round re-ap-card-metric">
            <div class="card-body">
                <div class="metric-label">Posted total (filtered list)</div>
                <div class="metric-value"><?= m($totalPaid) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-round re-ap-card-metric">
            <div class="card-body">
                <div class="metric-label">Rows shown</div>
                <div class="metric-value"><?= count($rows) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card card-round">
    <div class="table-responsive">
        <table class="table table-sm table-hover re-ap-table mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Vendor</th>
                    <th>Reference</th>
                    <th class="text-end">Payment Amount</th>
                    <th class="text-end">Allocated to Bills</th>
                    <th class="text-end">Original Advance</th>
                    <th class="text-end">Advance Applied</th>
                    <th class="text-end">Advance VAT</th>
                    <th class="text-end">Refunded</th>
                    <th class="text-end">Advance Remaining</th>
                    <th>Status</th>
                    <th>Journal</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $canReverse = ($r['status'] ?? '') === 'posted'
                        && (float)$r['allocated_to_bills'] <= 0.005
                        && (float)$r['advance_applied'] <= 0.005
                        && (float)($r['advance_vat_posted'] ?? 0) <= 0.005
                        && (float)($r['advance_refunded'] ?? 0) <= 0.005;
                    $canRefund = ($r['status'] ?? '') === 'posted'
                        && (float)$r['original_advance'] > 0.005
                        && (float)$r['advance_remaining'] > 0.005
                        && (float)($r['advance_vat_posted'] ?? 0) <= 0.005;
                    ?>
                    <tr>
                        <td><?= h($r['payment_date']) ?></td>
                        <td><?= h($r['vendor_name']) ?></td>
                        <td><?= h($r['reference_number'] ?: ('PAY-' . $r['id'])) ?></td>
                        <td class="text-end"><?= m($r['amount']) ?></td>
                        <td class="text-end"><?= m($r['allocated_to_bills']) ?></td>
                        <td class="text-end"><?= m($r['original_advance']) ?></td>
                        <td class="text-end"><?= m($r['advance_applied']) ?></td>
                        <td class="text-end"><?= m($r['advance_vat_posted'] ?? 0) ?></td>
                        <td class="text-end"><?= m($r['advance_refunded'] ?? 0) ?></td>
                        <td class="text-end fw-semibold"><?= m($r['advance_remaining']) ?></td>
                        <td><span class="badge bg-<?= ($r['status'] ?? '') === 'void' ? 'secondary' : 'success' ?>"><?= h($r['status']) ?></span></td>
                        <td>
                            <?php if ($r['journal_id']): ?>
                                <a href="journal_entry_view.php?id=<?= (int)$r['journal_id'] ?>">Journal</a>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="vendor_statement.php?vendor_id=<?= (int)$r['vendor_id'] ?>">SOA</a>
                            <?php if ($canRefund): ?>
                                <a class="btn btn-sm btn-outline-warning" href="vendor_advance_refund_add.php?vendor_id=<?= (int)$r['vendor_id'] ?>&payment_id=<?= (int)$r['id'] ?>">Refund</a>
                            <?php endif; ?>
                            <?php if ($canReverse): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Reverse this payment journal?');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="reverse_payment">
                                    <input type="hidden" name="payment_id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="reason" value="Reversed from payments list">
                                    <button class="btn btn-sm btn-outline-danger">Reverse</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="12" class="text-center text-muted">No vendor payments yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
