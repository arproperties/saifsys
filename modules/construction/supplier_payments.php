<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_ap_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_supplier_require_company_id($conn);
$supplierId = (int)($_GET['supplier_id'] ?? 0);
$q = trim($_GET['q'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$glStatus = $_GET['gl_status'] ?? 'all';
if (!in_array($glStatus, ['all', 'posted', 'unposted'], true)) {
    $glStatus = 'all';
}
$hasPayAccountColumn = co_payment_account_column_ready($conn);

$where = "WHERE sp.company_id = ?";
$params = [$cid];
if ($supplierId) {
    $where .= " AND sp.supplier_id = ?";
    $params[] = $supplierId;
}
if ($q !== '') {
    $where .= $hasPayAccountColumn
        ? " AND (s.supplier_name LIKE ? OR sp.reference LIKE ? OR coa.account_code LIKE ? OR coa.account_name LIKE ?)"
        : " AND (s.supplier_name LIKE ? OR sp.reference LIKE ?)";
    $like = '%' . $q . '%';
    $hasPayAccountColumn ? array_push($params, $like, $like, $like, $like) : array_push($params, $like, $like);
}
if ($dateFrom !== '') {
    $where .= " AND sp.payment_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where .= " AND sp.payment_date <= ?";
    $params[] = $dateTo;
}
if ($glStatus === 'posted') {
    $where .= " AND sp.journal_id IS NOT NULL";
} elseif ($glStatus === 'unposted') {
    $where .= " AND sp.journal_id IS NULL";
}
$accountSelect = $hasPayAccountColumn ? "coa.account_code, coa.account_name," : "NULL AS account_code, NULL AS account_name,";
$accountJoin = $hasPayAccountColumn ? "LEFT JOIN re_chart_of_accounts coa ON coa.id = sp.pay_account_id AND coa.company_id = sp.company_id" : "";
$stmt = $conn->prepare("
    SELECT sp.*, s.supplier_name, {$accountSelect}
           jh.journal_number
    FROM co_supplier_payments sp
    JOIN co_suppliers s ON s.id = sp.supplier_id
    {$accountJoin}
    LEFT JOIN re_journal_headers jh ON jh.id = sp.journal_id
    {$where}
    ORDER BY sp.payment_date DESC, sp.id DESC
");
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
$summaryTotal = array_sum(array_map(fn($row) => (float)$row['amount'], $payments));
$summaryPosted = count(array_filter($payments, fn($row) => !empty($row['journal_id'])));
$summaryUnposted = count($payments) - $summaryPosted;
$summaryAccounts = count(array_unique(array_filter(array_map(fn($row) => $row['account_code'] ?? '', $payments))));

$suppliersStmt = $conn->prepare("SELECT id, supplier_name FROM co_suppliers WHERE company_id = ? AND is_active = 1 ORDER BY supplier_name");
$suppliersStmt->execute([$cid]);
$suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Supplier Payments';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-0">Supplier Payments</h1>
        <p class="text-muted mb-0">Review payments made to suppliers and confirm bank/cash and GL posting.</p>
    </div>
    <a href="supplier_payment_add.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Record Payment</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card card-round h-100"><div class="card-body"><div class="text-muted small">Payments</div><h4 class="mb-0"><?= count($payments) ?></h4><small>visible records</small></div></div></div>
    <div class="col-md-3"><div class="card card-round h-100"><div class="card-body"><div class="text-muted small">Total Paid</div><h4 class="mb-0"><?= co_format_money($summaryTotal) ?></h4><small>filtered amount</small></div></div></div>
    <div class="col-md-3"><div class="card card-round h-100"><div class="card-body"><div class="text-muted small">GL Posted</div><h4 class="mb-0"><?= (int)$summaryPosted ?></h4><small><?= (int)$summaryUnposted ?> unposted</small></div></div></div>
    <div class="col-md-3"><div class="card card-round h-100"><div class="card-body"><div class="text-muted small">Paid From Accounts</div><h4 class="mb-0"><?= (int)$summaryAccounts ?></h4><small>cash/bank accounts used</small></div></div></div>
</div>

<form method="get" class="card card-round mb-4">
    <div class="card-body row g-3 align-items-end">
    <div class="col-md-3">
        <label class="form-label">Supplier</label>
        <select name="supplier_id" class="form-select">
            <option value="0">All suppliers</option>
            <?php foreach ($suppliers as $supplier): ?>
            <option value="<?= (int)$supplier['id'] ?>" <?= $supplierId === (int)$supplier['id'] ? 'selected' : '' ?>><?= h($supplier['supplier_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2"><label class="form-label">GL Status</label><select name="gl_status" class="form-select"><option value="all" <?= $glStatus === 'all' ? 'selected' : '' ?>>All</option><option value="posted" <?= $glStatus === 'posted' ? 'selected' : '' ?>>Posted</option><option value="unposted" <?= $glStatus === 'unposted' ? 'selected' : '' ?>>Unposted</option></select></div>
    <div class="col-md-2"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div>
    <div class="col-md-2"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div>
    <div class="col-md-3"><label class="form-label">Search</label><input type="search" name="q" class="form-control" placeholder="Supplier, reference, paid account..." value="<?= h($q) ?>"></div>
    <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Apply Filters</button><a href="supplier_payments.php" class="btn btn-outline-secondary">Reset</a></div>
    </div>
</form>
<div class="card card-round"><div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2"><strong>Payment Register</strong><span class="text-muted small"><?= co_format_money($summaryTotal) ?> shown</span></div><div class="card-body p-0 table-responsive">
    <table class="table table-hover mb-0">
        <thead class="table-light"><tr><th>Date</th><th>Supplier</th><th class="text-end">Amount</th><th>Paid From</th><th>Reference</th><th>GL</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($payments as $payment): ?>
            <tr>
                <td><?= h($payment['payment_date']) ?></td>
                <td><a href="supplier_view.php?id=<?= (int)$payment['supplier_id'] ?>"><?= h($payment['supplier_name']) ?></a></td>
                <td class="text-end"><strong><?= co_format_money($payment['amount']) ?></strong></td>
                <td><?= !empty($payment['account_code']) ? h($payment['account_code'] . ' — ' . $payment['account_name']) : '—' ?></td>
                <td><?= h($payment['reference'] ?: '-') ?></td>
                <td><?= $payment['journal_id'] ? '<span class="badge bg-success">' . h($payment['journal_number'] ?: 'Posted') . '</span>' : '<span class="badge bg-secondary">Unposted</span>' ?></td>
                <td class="text-end">
                    <div class="btn-group btn-group-sm">
                        <a href="supplier_payment_view.php?id=<?= (int)$payment['id'] ?>" class="btn btn-outline-primary">View</a>
                        <a href="supplier_payment_edit.php?id=<?= (int)$payment['id'] ?>" class="btn btn-outline-secondary">Edit</a>
                        <a href="supplier_payment_delete.php?id=<?= (int)$payment['id'] ?>" class="btn btn-outline-danger">Reverse</a>
                        <a href="supplier_view.php?id=<?= (int)$payment['supplier_id'] ?>" class="btn btn-outline-secondary">Supplier</a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$payments): ?><tr><td colspan="7" class="text-center text-muted py-5"><i class="bi bi-cash-stack fs-1 d-block mb-2"></i>No supplier payments found for the selected filters.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
