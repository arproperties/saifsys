<?php
/**
 * Construction Module — VAT Report (Expenses Phase 4)
 * Shows Input VAT (and optionally Output VAT) from GL for the construction company.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_ap_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_advance_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = function_exists('co_supplier_require_company_id')
    ? co_supplier_require_company_id($conn)
    : (current_company_id($conn) ?: 0);
if ($cid <= 0) {
    http_response_code(400);
    die('Company context is required.');
}

$dateFrom = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$dateTo = !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

// Construction VAT is stored in source documents. GL-only reporting missed ERP
// expenses because they can post to account 2320, while supplier invoices may
// post to a different VAT account depending on chart setup.
$inputVatTransactions = [];
$outputVatTransactions = [];

// Supplier invoices: net of linked advance VAT (avoid double-count when advance VAT docs exist).
$linkedVatJoin = '';
$vatExpr = 'si.vat_amount';
$taxableExpr = 'si.subtotal';
$statusExclude = '';
if (function_exists('co_supplier_advance_vat_table_ready') && co_supplier_advance_vat_table_ready($conn)) {
    $linkedVatJoin = "
        LEFT JOIN (
            SELECT supplier_invoice_id, SUM(vat_amount_linked) AS linked_vat, SUM(taxable_amount_linked) AS linked_taxable
            FROM co_supplier_advance_vat_invoice_links
            WHERE company_id = {$cid} AND status = 'posted'
            GROUP BY supplier_invoice_id
        ) vlink ON vlink.supplier_invoice_id = si.id
    ";
    $vatExpr = 'GREATEST(COALESCE(si.vat_amount,0) - COALESCE(vlink.linked_vat,0), 0)';
    $taxableExpr = 'GREATEST(COALESCE(si.subtotal,0) - COALESCE(vlink.linked_taxable,0), 0)';
}
if (function_exists('co_supplier_invoice_lifecycle_ready') && co_supplier_invoice_lifecycle_ready($conn)) {
    $statusExclude = " AND COALESCE(si.status,'') <> 'voided' ";
}
$stmt = $conn->prepare("
    SELECT
        si.invoice_date AS txn_date,
        'Supplier Invoice' AS source_type,
        si.invoice_number AS document_no,
        si.reference,
        COALESCE(s.supplier_name, 'Supplier') AS party_name,
        COALESCE(p.project_code, '') AS project_code,
        COALESCE(p.project_name, '') AS project_name,
        {$taxableExpr} AS subtotal,
        {$vatExpr} AS vat_amount,
        si.total,
        CASE WHEN si.journal_id IS NULL THEN 'unposted' ELSE 'posted' END AS status,
        jh.journal_number
    FROM co_supplier_invoices si
    LEFT JOIN co_suppliers s ON s.id = si.supplier_id
    LEFT JOIN co_projects p ON p.id = si.project_id
    LEFT JOIN re_journal_headers jh ON jh.id = si.journal_id
    {$linkedVatJoin}
    WHERE si.company_id = ?
      AND si.invoice_date BETWEEN ? AND ?
      {$statusExclude}
      AND ({$vatExpr}) <> 0
");
$stmt->execute([$cid, $dateFrom, $dateTo]);
$inputVatTransactions = array_merge($inputVatTransactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

// Posted Advance VAT documents (Dr Input VAT / Cr Advances).
if (function_exists('co_supplier_advance_vat_table_ready') && co_supplier_advance_vat_table_ready($conn)) {
    $advVat = $conn->prepare("
        SELECT
            d.supplier_invoice_date AS txn_date,
            'Advance VAT' AS source_type,
            d.supplier_invoice_number AS document_no,
            CONCAT('PAY-', d.supplier_payment_id) AS reference,
            COALESCE(s.supplier_name, 'Supplier') AS party_name,
            '' AS project_code,
            '' AS project_name,
            d.taxable_amount AS subtotal,
            d.vat_amount,
            d.gross_amount AS total,
            d.status,
            jh.journal_number
        FROM co_supplier_advance_vat_documents d
        LEFT JOIN co_suppliers s ON s.id = d.supplier_id AND s.company_id = d.company_id
        LEFT JOIN re_journal_headers jh ON jh.id = d.journal_id
        WHERE d.company_id = ?
          AND d.status = 'posted'
          AND d.supplier_invoice_date BETWEEN ? AND ?
          AND COALESCE(d.vat_amount, 0) <> 0
    ");
    $advVat->execute([$cid, $dateFrom, $dateTo]);
    $inputVatTransactions = array_merge($inputVatTransactions, $advVat->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

// ERP expenses entered from Construction module.
try {
    $stmt = $conn->prepare("
        SELECT
            e.expense_date AS txn_date,
            'ERP Expense' AS source_type,
            COALESCE(e.expense_number, e.reference_no, CONCAT('EXP-', e.id)) AS document_no,
            e.reference_no AS reference,
            COALESCE(cs.supplier_name, v.vendor_name, 'Expense') AS party_name,
            COALESCE(p.project_code, '') AS project_code,
            COALESCE(p.project_name, '') AS project_name,
            e.subtotal,
            e.vat_amount,
            e.total,
            e.status,
            jh.journal_number
        FROM erp_expense_headers e
        LEFT JOIN co_suppliers cs ON cs.id = e.co_supplier_id AND cs.company_id = e.company_id
        LEFT JOIN re_vendors v ON v.id = e.vendor_id AND v.company_id = e.company_id
        LEFT JOIN co_projects p ON p.id = e.project_id AND p.company_id = e.company_id
        LEFT JOIN re_journal_headers jh ON jh.id = e.journal_id
        WHERE e.company_id = ?
          AND e.source_module = 'construction'
          AND e.status <> 'cancelled'
          AND e.expense_date BETWEEN ? AND ?
          AND COALESCE(e.vat_amount, 0) <> 0
    ");
    $stmt->execute([$cid, $dateFrom, $dateTo]);
    $inputVatTransactions = array_merge($inputVatTransactions, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    // ERP expense tables may not exist on older installs; supplier invoice VAT still works.
}

usort($inputVatTransactions, function ($a, $b) {
    return [$a['txn_date'], $a['source_type'], $a['document_no']] <=> [$b['txn_date'], $b['source_type'], $b['document_no']];
});
$inputVatTotal = array_sum(array_map(fn($row) => (float)($row['vat_amount'] ?? 0), $inputVatTransactions));
$inputTaxableTotal = array_sum(array_map(fn($row) => (float)($row['subtotal'] ?? 0), $inputVatTransactions));

// Client invoices: output VAT source for Construction sales/client invoices.
// Monthly rent Tax Invoices carry Output VAT. Legacy VAT-only collection invoices
// (retired Separate VAT workflow) are excluded so VAT is never double-counted.
try {
    $stmt = $conn->prepare("
        SELECT
            ci.invoice_date AS txn_date,
            'Client Invoice' AS source_type,
            ci.invoice_number AS document_no,
            NULL AS reference,
            COALESCE(c.client_name, 'Client') AS party_name,
            COALESCE(p.project_code, '') AS project_code,
            COALESCE(p.project_name, '') AS project_name,
            (ci.total_amount - ci.vat_amount) AS subtotal,
            ci.vat_amount,
            ci.total_amount AS total,
            ci.status,
            jh.journal_number
        FROM co_client_invoices ci
        LEFT JOIN co_clients c ON c.id = ci.client_id
        LEFT JOIN co_projects p ON p.id = ci.project_id
        LEFT JOIN re_journal_headers jh ON jh.id = ci.journal_id
        LEFT JOIN co_shop_rent_schedules s
               ON ci.source_type = 'shop_rental'
              AND s.id = ci.source_id
              AND s.company_id = ci.company_id
        WHERE ci.company_id = ?
          AND ci.status <> 'cancelled'
          AND ci.invoice_date BETWEEN ? AND ?
          AND COALESCE(ci.vat_amount, 0) <> 0
          AND COALESCE(s.schedule_type, 'rent') <> 'vat'
        ORDER BY ci.invoice_date ASC, ci.id ASC
    ");
    $stmt->execute([$cid, $dateFrom, $dateTo]);
    $outputVatTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $outputVatTransactions = [];
}
$outputVatTotal = array_sum(array_map(fn($row) => (float)($row['vat_amount'] ?? 0), $outputVatTransactions));
$outputTaxableTotal = array_sum(array_map(fn($row) => (float)($row['subtotal'] ?? 0), $outputVatTransactions));
$netVatPayable = $outputVatTotal - $inputVatTotal;

require_once __DIR__ . '/../realestate/accounting/export_excel_helper.php';
$exportRows = [];
foreach ($inputVatTransactions as $row) {
    $row['vat_direction'] = 'Input VAT';
    $exportRows[] = $row;
}
foreach ($outputVatTransactions as $row) {
    $row['vat_direction'] = 'Output VAT';
    $exportRows[] = $row;
}
if (($_GET['export'] ?? '') === 'excel') {
    accounting_export_excel_or_csv($exportRows, ['vat_direction' => 'VAT Type', 'txn_date' => 'Date', 'source_type' => 'Source', 'document_no' => 'Document', 'party_name' => 'Party', 'project_code' => 'Project Code', 'project_name' => 'Project', 'subtotal' => 'Subtotal', 'vat_amount' => 'VAT', 'total' => 'Total', 'status' => 'Status', 'journal_number' => 'Journal'], 'construction_vat_report_' . $dateFrom . '_' . $dateTo, 'Construction VAT Report');
} elseif (($_GET['export'] ?? '') === 'csv') {
    accounting_export_csv($exportRows, ['vat_direction' => 'VAT Type', 'txn_date' => 'Date', 'source_type' => 'Source', 'document_no' => 'Document', 'party_name' => 'Party', 'project_code' => 'Project Code', 'project_name' => 'Project', 'subtotal' => 'Subtotal', 'vat_amount' => 'VAT', 'total' => 'Total', 'status' => 'Status', 'journal_number' => 'Journal'], 'construction_vat_report_' . $dateFrom . '_' . $dateTo);
}

$pageTitle = 'VAT Report';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <h1 class="h4 mb-0">VAT Report</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary btn-sm" href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"><i class="bi bi-download"></i> CSV</a>
        <a class="btn btn-outline-success btn-sm" href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'excel']))) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    </div>
</div>
<p class="text-muted small">Input VAT: supplier invoices (net of linked advance VAT), posted Advance VAT documents, and Construction ERP expenses. Output VAT: client invoices.</p>

<form method="get" class="card card-round mb-4 no-print">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>" required>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary">Generate Report</button>
            </div>
        </div>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card card-round h-100 border-info">
            <div class="card-body text-center">
                <h6 class="text-muted small text-uppercase">Input VAT (Recoverable)</h6>
                <div class="h4 text-info mb-0"><?= co_format_money($inputVatTotal) ?></div>
                <small class="text-muted"><?= count($inputVatTransactions) ?> input VAT document<?= count($inputVatTransactions) === 1 ? '' : 's' ?></small>
                <div class="small text-muted mt-2">Original taxable amount</div>
                <div class="fw-semibold"><?= co_format_money($inputTaxableTotal) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-round h-100 border-primary">
            <div class="card-body text-center">
                <h6 class="text-muted small text-uppercase">Output VAT (Collected)</h6>
                <div class="h4 text-primary mb-0"><?= co_format_money($outputVatTotal) ?></div>
                <small class="text-muted"><?= count($outputVatTransactions) ?> output VAT document<?= count($outputVatTransactions) === 1 ? '' : 's' ?></small>
                <div class="small text-muted mt-2">Original taxable amount</div>
                <div class="fw-semibold"><?= co_format_money($outputTaxableTotal) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-round h-100 border-<?= $netVatPayable >= 0 ? 'danger' : 'success' ?>">
            <div class="card-body text-center">
                <h6 class="text-muted small text-uppercase">Net VAT Payable</h6>
                <div class="h4 mb-0 text-<?= $netVatPayable >= 0 ? 'danger' : 'success' ?>"><?= co_format_money($netVatPayable) ?></div>
                <small class="text-muted"><?= $netVatPayable >= 0 ? 'Payable to FTA' : 'Refundable' ?></small>
            </div>
        </div>
    </div>
</div>

<div class="card card-round">
    <div class="card-header bg-white">
        <h6 class="mb-0">Input VAT transactions (<?= h($dateFrom) ?> to <?= h($dateTo) ?>)</h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($inputVatTransactions)): ?>
        <div class="p-4 text-muted text-center">No Input VAT transactions in this period.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Source</th>
                        <th>Document</th>
                        <th>Party / Project</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th class="text-end">VAT</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inputVatTransactions as $row): ?>
                    <tr>
                        <td><?= h($row['txn_date']) ?></td>
                        <td><?= h($row['source_type']) ?></td>
                        <td>
                            <strong><?= h($row['document_no'] ?? '') ?></strong>
                            <?php if (!empty($row['journal_number'])): ?><br><small class="text-muted"><?= h($row['journal_number']) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?= h($row['party_name'] ?? '') ?>
                            <?php if (!empty($row['project_code']) || !empty($row['project_name'])): ?>
                                <br><small class="text-muted"><?= h(trim(($row['project_code'] ?? '') . ' ' . ($row['project_name'] ?? ''))) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= h($row['reference'] ?? '') ?></td>
                        <td><span class="badge bg-<?= ($row['status'] ?? '') === 'posted' ? 'success' : 'secondary' ?>"><?= h(ucfirst($row['status'] ?? '')) ?></span></td>
                        <td class="text-end"><?= co_format_money($row['vat_amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="6" class="text-end">Input VAT Total</th>
                        <th class="text-end"><?= co_format_money($inputVatTotal) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card card-round mt-4">
    <div class="card-header bg-white">
        <h6 class="mb-0">Output VAT transactions (<?= h($dateFrom) ?> to <?= h($dateTo) ?>)</h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($outputVatTransactions)): ?>
        <div class="p-4 text-muted text-center">No Output VAT transactions in this period.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Source</th>
                        <th>Document</th>
                        <th>Client / Project</th>
                        <th>Status</th>
                        <th class="text-end">VAT</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($outputVatTransactions as $row): ?>
                    <tr>
                        <td><?= h($row['txn_date']) ?></td>
                        <td><?= h($row['source_type']) ?></td>
                        <td>
                            <strong><?= h($row['document_no'] ?? '') ?></strong>
                            <?php if (!empty($row['journal_number'])): ?><br><small class="text-muted"><?= h($row['journal_number']) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?= h($row['party_name'] ?? '') ?>
                            <?php if (!empty($row['project_code']) || !empty($row['project_name'])): ?>
                                <br><small class="text-muted"><?= h(trim(($row['project_code'] ?? '') . ' ' . ($row['project_name'] ?? ''))) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-<?= ($row['status'] ?? '') === 'paid' ? 'success' : 'secondary' ?>"><?= h(ucfirst($row['status'] ?? '')) ?></span></td>
                        <td class="text-end"><?= co_format_money($row['vat_amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="5" class="text-end">Output VAT Total</th>
                        <th class="text-end"><?= co_format_money($outputVatTotal) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
