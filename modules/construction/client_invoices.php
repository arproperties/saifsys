<?php
/**
 * Construction Module — Client Invoices (CLIENT projects only)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_income_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$project_id = (int)($_GET['project_id'] ?? 0);
$filterClientId = (int)($_GET['client_id'] ?? 0);
$filterSourceType = trim((string)($_GET['source_type'] ?? ''));
$filterOpen = (string)($_GET['open'] ?? '') === '1';

$where = "WHERE i.company_id = ?";
$params = [$cid];
if ($project_id) { $where .= " AND i.project_id = ?"; $params[] = $project_id; }
if ($filterClientId > 0) { $where .= " AND i.client_id = ?"; $params[] = $filterClientId; }
if ($filterSourceType !== '' && co_client_invoice_columns_ready($conn)) {
    $where .= " AND i.source_type = ?";
    $params[] = $filterSourceType;
}
$hasIncomeColumns = co_client_invoice_columns_ready($conn);
$allocSelect = "0 AS paid_amount, i.total_amount AS balance_due";
$allocJoin = "";
if (co_client_allocations_ready($conn)) {
    $allocSelect = "COALESCE(a.paid_amount, 0) AS paid_amount,
           GREATEST(i.total_amount - COALESCE(a.paid_amount, 0), 0) AS balance_due";
    $allocJoin = "
    LEFT JOIN (
        SELECT invoice_id, SUM(allocated_amount) AS paid_amount
        FROM co_client_payment_allocations
        GROUP BY invoice_id
    ) a ON a.invoice_id = i.id";
}
$sourceSelect = $hasIncomeColumns ? "i.source_type, i.due_date, i.subtotal, i.description," : "'construction_project' AS source_type, NULL AS due_date, (i.total_amount - i.vat_amount) AS subtotal, NULL AS description,";

$having = $filterOpen ? " HAVING balance_due > 0.005" : "";

$stmt = $conn->prepare("
    SELECT i.*, {$sourceSelect}
           p.project_code, p.project_name, c.client_name,
           {$allocSelect},
           (SELECT COUNT(*) FROM co_client_invoice_documents d WHERE d.company_id = i.company_id AND d.client_invoice_id = i.id) AS document_count
    FROM co_client_invoices i
    LEFT JOIN co_projects p ON p.id = i.project_id
    JOIN co_clients c ON c.id = i.client_id
    {$allocJoin}
    $where
    $having
    ORDER BY i.invoice_date DESC
");
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
require_once __DIR__ . '/includes/construction_shop_rental_helpers.php';
if ($invoices) {
    foreach ($invoices as &$invRow) {
        if (($invRow['source_type'] ?? '') !== 'shop_rental') {
            continue;
        }
        $collectible = co_shop_invoice_collectible_amount($conn, $cid, $invRow);
        $paidAmt = (float)($invRow['paid_amount'] ?? 0);
        $invRow['balance_due'] = max(0, round($collectible - $paidAmt, 2));
    }
    unset($invRow);
    if ($filterOpen) {
        $invoices = array_values(array_filter($invoices, static function ($r) {
            return (float)($r['balance_due'] ?? 0) > 0.005;
        }));
    }
}

$sourceFilters = [
    '' => 'All categories',
    'shop_rental' => 'Shop Rental',
    'shop_commission' => 'Shop Commission',
    'construction_project' => 'Projects',
];

$pageTitle = 'Client Invoices';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-0">Income Invoices</h1>
        <p class="text-muted mb-0">Construction AR invoices for projects, shop rent, camp management, and maintenance services.</p>
    </div>
    <a href="client_invoice_create.php<?= $project_id ? '?project_id='.$project_id : '' ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Create Invoice</a>
</div>
<?php if (!$hasIncomeColumns): ?><div class="alert alert-warning">Run <code>migrations/construction_income_workflow.sql</code> to enable income categories, balances, and GL posting.</div><?php endif; ?>

<form method="get" class="card card-round mb-3">
    <div class="card-body row g-2 align-items-end">
        <?php if ($project_id): ?><input type="hidden" name="project_id" value="<?= (int)$project_id ?>"><?php endif; ?>
        <div class="col-md-3">
            <label class="form-label">Category</label>
            <select name="source_type" class="form-select form-select-sm">
                <?php foreach ($sourceFilters as $k => $lab): ?>
                    <option value="<?= h($k) ?>" <?= $filterSourceType === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Client ID (optional)</label>
            <input type="number" name="client_id" class="form-control form-control-sm" value="<?= $filterClientId ?: '' ?>" placeholder="Filter by client_id">
        </div>
        <div class="col-md-3">
            <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" name="open" value="1" id="fltOpen" <?= $filterOpen ? 'checked' : '' ?>>
                <label class="form-check-label" for="fltOpen">Open balance only</label>
            </div>
        </div>
        <div class="col-md-3"><button class="btn btn-sm btn-outline-primary w-100">Apply filters</button></div>
    </div>
</form>

<div class="card card-round">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Invoice #</th><th>Date</th><th>Due</th><th>Category</th><th>Project</th><th>Client/Tenant</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th><th>GL</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $i):
                        $bal = (float)($i['balance_due'] ?? 0);
                    ?>
                    <tr>
                        <td><strong><?= h($i['invoice_number']) ?></strong></td>
                        <td><?= $i['invoice_date'] ? date('Y-m-d', strtotime($i['invoice_date'])) : '-' ?></td>
                        <td><?= !empty($i['due_date']) ? h($i['due_date']) : '-' ?></td>
                        <td><span class="badge bg-info text-dark"><?= h(co_income_source_label($i['source_type'] ?? 'construction_project')) ?></span></td>
                        <td><?= !empty($i['project_id']) ? '<a href="project_view.php?id=' . (int)$i['project_id'] . '">' . h($i['project_code']) . '</a>' : '-' ?></td>
                        <td><?= h($i['client_name']) ?></td>
                        <td class="text-end"><?= co_format_money($i['total_amount']) ?></td>
                        <td class="text-end"><?= co_format_money($i['paid_amount']) ?></td>
                        <td class="text-end"><?= co_format_money($bal) ?></td>
                        <td><span class="badge bg-<?= co_invoice_status_badge($i['status']) ?>"><?= h($i['status']) ?></span></td>
                        <td><?= !empty($i['journal_id']) ? '<span class="badge bg-success" title="Journal #' . (int)$i['journal_id'] . '">Posted</span>' : '<span class="badge bg-secondary">Unposted</span>' ?></td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a href="client_invoice_pdf.php?id=<?= (int)$i['id'] ?>" target="_blank" class="btn btn-outline-secondary">PDF</a>
                                <?php if ($bal > 0.005): ?>
                                <a href="client_payment_add.php?invoice_id=<?= (int)$i['id'] ?>&amp;amount=<?= h(number_format($bal, 2, '.', '')) ?>" class="btn btn-outline-primary">Allocate / Receipt</a>
                                <?php else: ?>
                                <span class="btn btn-outline-secondary disabled">Paid</span>
                                <?php endif; ?>
                                <a href="client_invoice_documents.php?invoice_id=<?= (int)$i['id'] ?>" class="btn btn-outline-secondary">Docs<?= !empty($i['document_count']) ? ' ('.(int)$i['document_count'].')' : '' ?></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($invoices)): ?>
        <div class="p-4 text-center text-muted">No income invoices found.</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
