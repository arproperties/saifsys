<?php
require_once __DIR__ . '/construction_report_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];

$projectId = (int)($_GET['project_id'] ?? 0);
$dateFrom = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-01-01');
$dateTo = !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

$projectsStmt = $conn->prepare("SELECT id, project_code, project_name FROM co_projects WHERE company_id = ? ORDER BY project_name");
$projectsStmt->execute([$cid]);
$projects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);

$project = null;
foreach ($projects as $row) if ((int)$row['id'] === $projectId) $project = $row;

$rows = [];
if ($project) {
    $queries = [];
    $params = [];

    $queries[] = "
        SELECT c.cost_date AS txn_date, 'Project Cost' AS source_type, c.cost_type AS category,
               c.description, c.reference, c.amount
        FROM co_project_costs c
        WHERE c.company_id = ? AND c.project_id = ? AND c.cost_date BETWEEN ? AND ?
    ";
    array_push($params, $cid, $projectId, $dateFrom, $dateTo);

    $queries[] = "
        SELECT si.invoice_date AS txn_date, 'Supplier Invoice' AS source_type, s.supplier_name AS category,
               CONCAT('Invoice ', si.invoice_number, IF(si.description IS NULL OR si.description = '', '', CONCAT(' - ', si.description))) AS description,
               si.reference, si.subtotal AS amount
        FROM co_supplier_invoices si
        JOIN co_suppliers s ON s.id = si.supplier_id
        WHERE si.company_id = ? AND si.project_id = ? AND si.invoice_date BETWEEN ? AND ?
    ";
    array_push($params, $cid, $projectId, $dateFrom, $dateTo);

    // Legacy co_contractor_payments excluded — Supplier/AP is sole financial source (BR-CO-BP-003)

    if (co_db_column_exists($conn, 'erp_expense_headers', 'project_id')) {
        $queries[] = "
            SELECT e.expense_date AS txn_date, 'Quick Paid Expense' AS source_type, COALESCE(cs.supplier_name, 'Quick Paid Expense') AS category,
                   COALESCE(e.notes, e.reference_no, e.expense_number) AS description,
                   e.reference_no AS reference, e.subtotal AS amount
            FROM erp_expense_headers e
            LEFT JOIN co_suppliers cs ON cs.id = e.co_supplier_id AND cs.company_id = e.company_id
            WHERE e.company_id = ? AND e.source_module = 'construction' AND e.status <> 'cancelled'
              AND e.project_id = ? AND e.expense_date BETWEEN ? AND ?
        ";
        array_push($params, $cid, $projectId, $dateFrom, $dateTo);
    }

    $sql = implode(" UNION ALL ", $queries) . " ORDER BY txn_date ASC, source_type ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$total = array_sum(array_map(fn($row) => (float)$row['amount'], $rows));

co_report_export($rows, [
    'txn_date' => 'Date',
    'source_type' => 'Source',
    'category' => 'Category',
    'description' => 'Description',
    'reference' => 'Reference',
    'amount' => 'Amount',
], 'construction_project_cost_detail_' . $projectId . '_' . $dateFrom . '_' . $dateTo, 'Construction Project Cost Detail');

$pageTitle = 'Construction Project Cost Detail';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div><h1 class="h4 mb-0">Project Cost Detail</h1><p class="text-muted mb-0"><?= $project ? h($project['project_code'] . ' — ' . $project['project_name']) : 'Choose a project' ?></p></div>
    <div class="d-flex gap-2"><?= $project ? co_report_export_buttons() : '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">Print</button>' ?></div>
</div>
<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end">
    <div class="col-md-4"><label class="form-label">Project</label><select name="project_id" class="form-select"><option value="0">Select project</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $projectId === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['project_code'] . ' — ' . $p['project_name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div>
    <div class="col-md-3"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">Generate</button></div>
</div></form>
<?php if (!$project): ?>
<div class="card card-round"><div class="card-body text-center text-muted py-4">Select a project to generate cost detail.</div></div>
<?php else: ?>
<div class="card card-round"><div class="card-body p-0 table-responsive">
    <table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Date</th><th>Source</th><th>Category</th><th>Description</th><th>Reference</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr><td><?= h($row['txn_date']) ?></td><td><?= h($row['source_type']) ?></td><td><?= h($row['category']) ?></td><td><?= h($row['description']) ?></td><td><?= h($row['reference']) ?></td><td class="text-end"><?= co_format_money($row['amount']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No project costs found in this period.</td></tr><?php endif; ?>
        </tbody>
        <tfoot class="table-light"><tr><th colspan="5" class="text-end">Total</th><th class="text-end"><?= co_format_money($total) ?></th></tr></tfoot>
    </table>
</div></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
