<?php
/**
 * Construction Module — Suppliers List (Expenses Phase 1)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_ap_helpers.php';
require_once __DIR__ . '/includes/supplier_delete_helper.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_supplier_require_company_id($conn);
$userId = current_user_id();

$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();
    $supplierId = (int)($_POST['id'] ?? 0);
    $res = co_supplier_delete($conn, $cid, $supplierId, $userId);
    if (!empty($res['success'])) {
        $flashSuccess = (string)($res['message'] ?? 'Supplier deleted.');
    } else {
        $flashError = (string)($res['error'] ?? 'Could not delete supplier.');
    }
}

$status = $_GET['status'] ?? 'active';
$q = trim($_GET['q'] ?? '');
if (!in_array($status, ['active', 'inactive', 'all'], true)) {
    $status = 'active';
}

$where = "WHERE s.company_id = ?";
$params = [$cid];
if ($status === 'active') {
    $where .= " AND s.is_active = 1";
} elseif ($status === 'inactive') {
    $where .= " AND s.is_active = 0";
}
if ($q !== '') {
    $where .= " AND (s.supplier_name LIKE ? OR s.contact_person LIKE ? OR s.phone LIKE ? OR s.email LIKE ? OR s.vat_number LIKE ? OR s.tax_number LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

$allocationJoin = "";
$balanceExpr = "COALESCE(SUM(si.total), 0)";
if (co_supplier_allocations_ready($conn)) {
    $allocationJoin = "
        LEFT JOIN (
            SELECT invoice_id, SUM(allocated_amount) AS allocated_amount
            FROM co_supplier_payment_allocations
            GROUP BY invoice_id
        ) a ON a.invoice_id = si.id
    ";
    $balanceExpr = "COALESCE(SUM(GREATEST(si.total - COALESCE(a.allocated_amount, 0), 0)), 0)";
}

$stmt = $conn->prepare("
    SELECT s.*,
           COUNT(DISTINCT si.id) AS invoice_count,
           COALESCE(SUM(si.total), 0) AS total_invoiced,
           {$balanceExpr} AS balance_due
    FROM co_suppliers s
    LEFT JOIN co_supplier_invoices si ON si.supplier_id = s.id AND si.company_id = s.company_id
    {$allocationJoin}
    {$where}
    GROUP BY s.id
    ORDER BY s.supplier_name
");
$stmt->execute($params);
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statsStmt = $conn->prepare("
    SELECT COUNT(*) AS total_suppliers,
           SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_suppliers,
           SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive_suppliers
    FROM co_suppliers
    WHERE company_id = ?
");
$statsStmt->execute([$cid]);
$supplierStats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$visibleOutstanding = array_sum(array_map(fn($row) => (float)$row['balance_due'], $suppliers));
$visibleWithBalance = count(array_filter($suppliers, fn($row) => (float)$row['balance_due'] > 0.005));

$pageTitle = 'Suppliers';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<?= co_ui_page_header(
    'Suppliers',
    'Manage suppliers for project expenses, invoices, documents, and payments.',
    [['label' => 'Construction', 'href' => 'index.php'], ['label' => 'Suppliers']],
    '<a href="supplier_add.php" class="btn btn-primary btn-sm"><i data-lucide="plus" style="width:14px;height:14px"></i> Add Supplier</a>'
) ?>

<?php if ($flashSuccess !== ''): ?>
<div class="alert alert-success alert-dismissible fade show"><?= h($flashSuccess) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($flashError !== ''): ?>
<div class="alert alert-danger alert-dismissible fade show"><?= h($flashError) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3"><?= co_ui_kpi(['label' => 'Total Suppliers', 'value' => (string)(int)($supplierStats['total_suppliers'] ?? 0), 'sub' => (int)($supplierStats['active_suppliers'] ?? 0) . ' active', 'icon' => 'truck']) ?></div>
    <div class="col-md-3"><?= co_ui_kpi(['label' => 'Visible Results', 'value' => (string)count($suppliers), 'sub' => 'after filters', 'icon' => 'filter', 'tone' => 'info']) ?></div>
    <div class="col-md-3"><?= co_ui_kpi(['label' => 'With Balance', 'value' => (string)(int)$visibleWithBalance, 'sub' => 'open payables', 'icon' => 'wallet', 'tone' => $visibleWithBalance > 0 ? 'danger' : 'teal']) ?></div>
    <div class="col-md-3"><?= co_ui_kpi(['label' => 'Visible Outstanding', 'value' => co_format_money($visibleOutstanding), 'sub' => 'invoice balance', 'icon' => 'banknote']) ?></div>
</div>

<form method="get" class="card card-round mb-4 co-filter-bar">
    <div class="card-body row g-3 align-items-end">
        <div class="col-md-5"><label class="form-label">Search</label><input type="search" name="q" class="form-control" placeholder="Supplier, contact, phone, email, TRN..." value="<?= h($q) ?>"></div>
        <div class="col-md-3"><label class="form-label">Status</label><select name="status" class="form-select"><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active only</option><option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive only</option><option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All suppliers</option></select></div>
        <div class="col-md-4 d-flex gap-2"><button class="btn btn-primary flex-fill">Apply Filters</button><a href="suppliers.php" class="btn btn-outline-secondary">Reset</a></div>
    </div>
</form>

<div class="card card-round">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong>Supplier Directory</strong>
        <span class="text-muted small"><?= count($suppliers) ?> result(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Tax/VAT (TRN)</th>
                        <th class="text-end">Invoices</th>
                        <th class="text-end">Balance</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($suppliers as $s): ?>
                    <?php
                        $deletePre = co_supplier_delete_precheck($conn, $cid, (int)$s['id']);
                        $canDelete = !empty($deletePre['can_delete']);
                        $qpeCount = (int)($deletePre['qpe_count'] ?? 0);
                        $deleteBlockReason = !empty($deletePre['blockers'])
                            ? ('Cannot delete: ' . implode(', ', $deletePre['blockers']) . '. Set inactive instead.')
                            : 'Cannot delete this supplier.';
                        $modalId = 'deleteSupplierModal' . (int)$s['id'];
                    ?>
                    <tr>
                        <td><strong><?= h($s['supplier_name']) ?></strong><?php if (!empty($s['address'])): ?><br><small class="text-muted"><?= h(strlen((string)$s['address']) > 70 ? substr((string)$s['address'], 0, 70) . '...' : (string)$s['address']) ?></small><?php endif; ?></td>
                        <td><?= h($s['contact_person'] ?? '-') ?></td>
                        <td><?= h($s['phone'] ?? '-') ?></td>
                        <td><?= !empty($s['email']) ? '<a href="mailto:' . h($s['email']) . '">' . h($s['email']) . '</a>' : '-' ?></td>
                        <td><?= h($s['vat_number'] ?? $s['tax_number'] ?? '-') ?></td>
                        <td class="text-end"><?= number_format((int)$s['invoice_count']) ?></td>
                        <td class="text-end"><?= co_format_money($s['balance_due']) ?></td>
                        <td><span class="badge bg-<?= $s['is_active'] ? 'success' : 'secondary' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                        <td class="text-end text-nowrap">
                            <a href="supplier_view.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                            <?php if ($canDelete): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger" title="Delete supplier" data-bs-toggle="modal" data-bs-target="#<?= h($modalId) ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" title="<?= h($deleteBlockReason) ?>" disabled>
                                    <i class="bi bi-trash"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($suppliers)): ?>
        <div class="p-5 text-center text-muted">
            <i class="bi bi-truck fs-1 d-block mb-2"></i>
            No suppliers found for the selected filters. <a href="supplier_add.php">Add a supplier</a> to get started.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php foreach ($suppliers as $s):
    $deletePre = co_supplier_delete_precheck($conn, $cid, (int)$s['id']);
    if (empty($deletePre['can_delete'])) {
        continue;
    }
    $qpeCount = (int)($deletePre['qpe_count'] ?? 0);
    $modalId = 'deleteSupplierModal' . (int)$s['id'];
?>
<div class="modal fade" id="<?= h($modalId) ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title text-danger"><i class="bi bi-trash"></i> Delete supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Delete <strong><?= h($s['supplier_name']) ?></strong>?</p>
                    <ul class="small text-muted mb-0">
                        <li>Allowed because this supplier has <strong>no invoices</strong> and <strong>no payments</strong>.</li>
                        <?php if ($qpeCount > 0): ?>
                            <li><strong><?= $qpeCount ?></strong> Quick Paid Expense record(s) will be reversed (if posted) and deleted with the supplier.</li>
                        <?php else: ?>
                            <li>Related documents / recurring templates for this supplier will also be removed.</li>
                        <?php endif; ?>
                        <li>This cannot be undone.</li>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep supplier</button>
                    <button type="submit" class="btn btn-danger">Delete supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
