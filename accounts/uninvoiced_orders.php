<?php
// accounts/uninvoiced_orders.php

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($n) { return number_format((float)$n, 2); }
}

$currentCompanyId = current_company_id($conn) ?: 1;

$from = trim((string)($_GET['from'] ?? date('Y-m-01')));
$to = trim((string)($_GET['to'] ?? date('Y-m-t')));
$search = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['order_status'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$validStatuses = ['', 'draft', 'confirmed', 'scheduled', 'in_progress', 'completed', 'invoiced'];
if (!in_array($status, $validStatuses, true)) {
    $status = '';
}

$where = ["mo.company_id = ?", "COALESCE(mo.status, '') <> 'cancelled'"];
$params = [$currentCompanyId];

if ($from !== '' && $to !== '') {
    $where[] = "(mo.service_date BETWEEN ? AND ? OR (mo.service_date IS NULL AND mo.`date` BETWEEN ? AND ?))";
    array_push($params, $from, $to, $from, $to);
} elseif ($from !== '') {
    $where[] = "(mo.service_date >= ? OR (mo.service_date IS NULL AND mo.`date` >= ?))";
    array_push($params, $from, $from);
} elseif ($to !== '') {
    $where[] = "(mo.service_date <= ? OR (mo.service_date IS NULL AND mo.`date` <= ?))";
    array_push($params, $to, $to);
}

if ($search !== '') {
    $where[] = "(COALESCE(c.client_name, mo.client_name) LIKE ? OR mo.worker_name LIKE ? OR mo.remark LIKE ? OR CAST(mo.id AS CHAR) LIKE ?)";
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}

if ($status !== '') {
    $where[] = "COALESCE(mo.status, '') = ?";
    $params[] = $status;
}

$baseWhereSql = implode(' AND ', $where);
$invoiceExistsSql = "(
    mo.invoice_id IS NOT NULL
    OR EXISTS (
        SELECT 1
        FROM invoices inv_direct
        WHERE inv_direct.order_id = mo.id
          AND COALESCE(inv_direct.status, '') <> 'void'
    )
    OR EXISTS (
        SELECT 1
        FROM invoice_items ii
        INNER JOIN invoices inv_item ON inv_item.id = ii.invoice_id
        WHERE ii.order_id = mo.id
          AND COALESCE(inv_item.status, '') <> 'void'
    )
)";
$uninvoicedWhereSql = $baseWhereSql . " AND NOT {$invoiceExistsSql}";

$summarySql = "
    SELECT
        COUNT(*) AS order_count,
        COALESCE(SUM(mo.hours), 0) AS hours_total,
        COALESCE(SUM(mo.total), 0) AS net_total,
        COALESCE(SUM(COALESCE(mo.vat_amount, 0)), 0) AS vat_total,
        COALESCE(SUM(COALESCE(mo.grand_total, mo.total + COALESCE(mo.vat_amount, 0))), 0) AS gross_total
    FROM make_order mo
    LEFT JOIN client c ON c.id = mo.client_id
    WHERE {$baseWhereSql}
";
$st = $conn->prepare($summarySql);
$st->execute($params);
$periodSummary = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$uninvoicedSummarySql = "
    SELECT
        COUNT(*) AS order_count,
        COALESCE(SUM(mo.hours), 0) AS hours_total,
        COALESCE(SUM(mo.total), 0) AS net_total,
        COALESCE(SUM(COALESCE(mo.vat_amount, 0)), 0) AS vat_total,
        COALESCE(SUM(COALESCE(mo.grand_total, mo.total + COALESCE(mo.vat_amount, 0))), 0) AS gross_total
    FROM make_order mo
    LEFT JOIN client c ON c.id = mo.client_id
    WHERE {$uninvoicedWhereSql}
";
$st = $conn->prepare($uninvoicedSummarySql);
$st->execute($params);
$uninvoicedSummary = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$totalUninvoiced = (int)($uninvoicedSummary['order_count'] ?? 0);
$totalPages = max(1, (int)ceil($totalUninvoiced / $perPage));

$rowsSql = "
    SELECT
        mo.id,
        COALESCE(mo.service_date, mo.`date`) AS service_date_display,
        COALESCE(c.client_name, mo.client_name) AS client_name_display,
        mo.worker_name,
        mo.hours,
        mo.total,
        COALESCE(mo.vat_amount, 0) AS vat_amount,
        COALESCE(mo.grand_total, mo.total + COALESCE(mo.vat_amount, 0)) AS grand_total,
        mo.status,
        mo.payment_status,
        mo.remark,
        mo.created_at
    FROM make_order mo
    LEFT JOIN client c ON c.id = mo.client_id
    WHERE {$uninvoicedWhereSql}
    ORDER BY COALESCE(mo.service_date, mo.`date`) DESC, mo.id DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$st = $conn->prepare($rowsSql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$periodCount = (int)($periodSummary['order_count'] ?? 0);
$periodNet = (float)($periodSummary['net_total'] ?? 0);
$periodVat = (float)($periodSummary['vat_total'] ?? 0);
$periodGross = (float)($periodSummary['gross_total'] ?? 0);
$uninvoicedNet = (float)($uninvoicedSummary['net_total'] ?? 0);
$uninvoicedVat = (float)($uninvoicedSummary['vat_total'] ?? 0);
$uninvoicedGross = (float)($uninvoicedSummary['gross_total'] ?? 0);
$invoicedCount = max(0, $periodCount - $totalUninvoiced);
$invoicedGross = max(0, $periodGross - $uninvoicedGross);
?>

<div class="alert alert-info small">
    <strong>Why Operation and Invoice totals can differ:</strong>
    Operation totals are based on work order service dates and the order net amount. Invoice totals are based on invoice issue dates and invoice gross totals. This page uses service dates and highlights orders in the selected period that are still not linked to a non-void invoice.
</div>

<form method="get" class="row g-3 align-items-end mb-3">
    <input type="hidden" name="tab" value="uninvoiced_orders">
    <div class="col-md-2">
        <label class="form-label">From</label>
        <input type="date" name="from" value="<?= h($from) ?>" class="form-control">
    </div>
    <div class="col-md-2">
        <label class="form-label">To</label>
        <input type="date" name="to" value="<?= h($to) ?>" class="form-control">
    </div>
    <div class="col-md-3">
        <label class="form-label">Search</label>
        <input type="text" name="q" value="<?= h($search) ?>" class="form-control" placeholder="Client, worker, order ID...">
    </div>
    <div class="col-md-2">
        <label class="form-label">Order Status</label>
        <select name="order_status" class="form-select">
            <option value="">All Active Statuses</option>
            <?php foreach (['draft'=>'Draft','confirmed'=>'Confirmed','scheduled'=>'Scheduled','in_progress'=>'In Progress','completed'=>'Completed','invoiced'=>'Marked Invoiced'] as $key => $label): ?>
                <option value="<?= h($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <button class="btn btn-primary">Search</button>
        <a href="?tab=uninvoiced_orders" class="btn btn-secondary">Clear</a>
    </div>
</form>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="border rounded p-3 bg-light h-100">
            <div class="text-muted small">All Orders In Period</div>
            <div class="fs-4 fw-bold"><?= number_format($periodCount) ?></div>
            <div class="small">Gross: AED <?= money($periodGross) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="border rounded p-3 bg-light h-100">
            <div class="text-muted small">Exact Period Sales (Net)</div>
            <div class="fs-4 fw-bold">AED <?= money($periodNet) ?></div>
            <div class="small">VAT: AED <?= money($periodVat) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="border rounded p-3 bg-warning-subtle h-100">
            <div class="text-muted small">Orders Without Invoice</div>
            <div class="fs-4 fw-bold"><?= number_format($totalUninvoiced) ?></div>
            <div class="small">Gross missing: AED <?= money($uninvoicedGross) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="border rounded p-3 bg-success-subtle h-100">
            <div class="text-muted small">Already Linked To Invoice</div>
            <div class="fs-4 fw-bold"><?= number_format($invoicedCount) ?></div>
            <div class="small">Gross linked: AED <?= money($invoicedGross) ?></div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Uninvoiced Orders</strong>
        <span class="badge bg-warning text-dark">Net missing: AED <?= money($uninvoicedNet) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Order ID</th>
                    <th>Service Date</th>
                    <th>Client</th>
                    <th>Worker</th>
                    <th class="text-end">Hours</th>
                    <th class="text-end">Net</th>
                    <th class="text-end">VAT</th>
                    <th class="text-end">Gross</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Remark</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><code>#<?= (int)$row['id'] ?></code></td>
                        <td><?= h($row['service_date_display']) ?></td>
                        <td><?= h($row['client_name_display']) ?></td>
                        <td><?= h($row['worker_name']) ?></td>
                        <td class="text-end"><?= money($row['hours']) ?></td>
                        <td class="text-end"><?= money($row['total']) ?></td>
                        <td class="text-end"><?= money($row['vat_amount']) ?></td>
                        <td class="text-end fw-semibold"><?= money($row['grand_total']) ?></td>
                        <td><span class="badge bg-secondary"><?= h($row['status']) ?></span></td>
                        <td><?= h($row['payment_status']) ?></td>
                        <td><?= h($row['remark']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">No uninvoiced orders found for this period.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if ($rows): ?>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="5" class="text-end">Total Missing From Invoices:</th>
                        <th class="text-end">AED <?= money($uninvoicedNet) ?></th>
                        <th class="text-end">AED <?= money($uninvoicedVat) ?></th>
                        <th class="text-end">AED <?= money($uninvoicedGross) ?></th>
                        <th colspan="3"></th>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
    <nav aria-label="Uninvoiced orders pagination">
        <ul class="pagination pagination-sm mb-0">
            <?php if ($page > 1): ?>
                <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['tab'=>'uninvoiced_orders', 'page'=>$page - 1])) ?>">&laquo; Previous</a></li>
            <?php endif; ?>
            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['tab'=>'uninvoiced_orders', 'page'=>$p])) ?>"><?= $p ?></a></li>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['tab'=>'uninvoiced_orders', 'page'=>$page + 1])) ?>">Next &raquo;</a></li>
            <?php endif; ?>
        </ul>
    </nav>
<?php endif; ?>
