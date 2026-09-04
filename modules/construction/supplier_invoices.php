<?php
/**
 * Construction Module — Supplier Invoices List (Expenses Phase 2)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_ap_helpers.php';
require_once __DIR__ . '/includes/construction_accounting_integration.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_supplier_require_company_id($conn);
$userId = current_user_id();
$flashMsg = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'post_gl') {
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            if ($invoiceId <= 0) {
                throw new RuntimeException('Invoice not found.');
            }
            $chk = $conn->prepare('SELECT id, journal_id, invoice_number FROM co_supplier_invoices WHERE id = ? AND company_id = ?');
            $chk->execute([$invoiceId, $cid]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Invoice not found for this company.');
            }
            if (!empty($row['journal_id'])) {
                throw new RuntimeException('Invoice ' . $row['invoice_number'] . ' is already GL Posted.');
            }
            $postResult = co_post_supplier_invoice_to_accounting($invoiceId, $cid, $userId);
            if (empty($postResult['success'])) {
                throw new RuntimeException($postResult['error'] ?? 'GL posting failed.');
            }
            if (co_supplier_invoice_lifecycle_ready($conn)) {
                $conn->prepare("UPDATE co_supplier_invoices SET journal_id = ?, status = 'posted' WHERE id = ? AND company_id = ? AND COALESCE(status,'draft') <> 'voided'")
                    ->execute([(int)$postResult['journal_id'], $invoiceId, $cid]);
            } else {
                $conn->prepare('UPDATE co_supplier_invoices SET journal_id = ? WHERE id = ? AND company_id = ?')
                    ->execute([(int)$postResult['journal_id'], $invoiceId, $cid]);
            }
            $_SESSION['co_supplier_inv_flash'] = [
                'msg' => 'Posted invoice ' . $row['invoice_number'] . ' to GL (journal #' . (int)$postResult['journal_id'] . '). Financial values are now locked.',
            ];
            header('Location: supplier_invoices.php');
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['co_supplier_inv_flash'] = ['err' => $e->getMessage()];
        header('Location: supplier_invoices.php');
        exit;
    }
}

if (!empty($_SESSION['co_supplier_inv_flash']) && is_array($_SESSION['co_supplier_inv_flash'])) {
    $flashMsg = (string)($_SESSION['co_supplier_inv_flash']['msg'] ?? '');
    $flashErr = (string)($_SESSION['co_supplier_inv_flash']['err'] ?? '');
    unset($_SESSION['co_supplier_inv_flash']);
}

co_generate_due_recurring_supplier_invoices($conn, $cid, $userId);
$supplier_id = (int)($_GET['supplier_id'] ?? 0);
$project_id = (int)($_GET['project_id'] ?? 0);
$status = $_GET['status'] ?? 'all';
$q = trim($_GET['q'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$allowedStatuses = ['all', 'open', 'partial', 'paid', 'overdue', 'draft', 'posted', 'partially_paid', 'voided'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}
$lifecycleReady = co_supplier_invoice_lifecycle_ready($conn);

$where = "WHERE si.company_id = ?";
$params = [$cid];
if ($supplier_id) {
    $where .= " AND si.supplier_id = ?";
    $params[] = $supplier_id;
}
if ($project_id) {
    $where .= " AND si.project_id = ?";
    $params[] = $project_id;
}
if ($q !== '') {
    $where .= " AND (s.supplier_name LIKE ? OR si.invoice_number LIKE ? OR si.reference LIKE ? OR si.description LIKE ? OR p.project_code LIKE ? OR p.project_name LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($dateFrom !== '') {
    $where .= " AND si.invoice_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where .= " AND si.invoice_date <= ?";
    $params[] = $dateTo;
}
$allocationSelect = "0 AS paid_amount, si.total AS balance_due";
$allocationJoin = "";
if (co_supplier_allocations_ready($conn)) {
    $allocationSelect = "COALESCE(a.allocated_amount, 0) AS paid_amount,
           GREATEST(si.total - COALESCE(a.allocated_amount, 0), 0) AS balance_due";
    $allocationJoin = "
    LEFT JOIN (
        SELECT invoice_id, SUM(allocated_amount) AS allocated_amount
        FROM co_supplier_payment_allocations
        GROUP BY invoice_id
    ) a ON a.invoice_id = si.id";
}
$stmt = $conn->prepare("
    SELECT si.*, s.supplier_name,
           p.project_code, p.project_name,
           {$allocationSelect}
    FROM co_supplier_invoices si
    JOIN co_suppliers s ON s.id = si.supplier_id
    LEFT JOIN co_projects p ON p.id = si.project_id
    {$allocationJoin}
    $where
    ORDER BY si.invoice_date DESC, si.id DESC
");
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($invoices as &$invRow) {
    $lifecycle = co_supplier_invoice_status($invRow);
    if ($lifecycleReady && $lifecycle !== 'voided' && !empty($invRow['journal_id'])) {
        // Keep list cheap: derive from allocations already selected
        $balance = (float)$invRow['balance_due'];
        $paid = (float)$invRow['paid_amount'];
        if ($balance <= 0.005) {
            $lifecycle = 'paid';
        } elseif ($paid > 0.005) {
            $lifecycle = 'partially_paid';
        } else {
            $lifecycle = 'posted';
        }
    }
    $invRow['_lifecycle'] = $lifecycle;
    $balance = (float)$invRow['balance_due'];
    $paid = (float)$invRow['paid_amount'];
    if ($lifecycle === 'voided') {
        $invRow['_payment_status'] = 'voided';
        $invRow['_is_overdue'] = false;
    } elseif ($balance <= 0.005 && !empty($invRow['journal_id'])) {
        $invRow['_payment_status'] = 'paid';
        $invRow['_is_overdue'] = false;
    } elseif ($paid > 0) {
        $invRow['_payment_status'] = 'partial';
        $invRow['_is_overdue'] = $balance > 0.005 && !empty($invRow['due_date']) && $invRow['due_date'] < date('Y-m-d');
    } else {
        $invRow['_payment_status'] = empty($invRow['journal_id']) ? 'draft' : 'open';
        $invRow['_is_overdue'] = $balance > 0.005 && !empty($invRow['due_date']) && $invRow['due_date'] < date('Y-m-d');
    }
    $invRow['_gl_status'] = !empty($invRow['journal_id']) ? 'posted' : 'draft';
    $invRow['_can_edit'] = $lifecycle === 'draft';
}
unset($invRow);
if ($status !== 'all') {
    $invoices = array_values(array_filter($invoices, function ($row) use ($status) {
        if ($status === 'overdue') return !empty($row['_is_overdue']);
        if ($status === 'draft') return ($row['_lifecycle'] ?? '') === 'draft';
        if ($status === 'posted') return ($row['_lifecycle'] ?? '') === 'posted';
        if ($status === 'partially_paid' || $status === 'partial') {
            return ($row['_lifecycle'] ?? '') === 'partially_paid' || ($row['_payment_status'] ?? '') === 'partial';
        }
        if ($status === 'voided') return ($row['_lifecycle'] ?? '') === 'voided';
        if ($status === 'paid') return ($row['_lifecycle'] ?? '') === 'paid' || ($row['_payment_status'] ?? '') === 'paid';
        if ($status === 'open') return ($row['_payment_status'] ?? '') === 'open';
        return true;
    }));
}
$summaryTotal = array_sum(array_map(fn($row) => (($row['_lifecycle'] ?? '') === 'voided' ? 0.0 : (float)$row['total']), $invoices));
$summaryPaid = array_sum(array_map(fn($row) => (($row['_lifecycle'] ?? '') === 'voided' ? 0.0 : (float)$row['paid_amount']), $invoices));
$summaryBalance = array_sum(array_map(fn($row) => (($row['_lifecycle'] ?? '') === 'voided' ? 0.0 : (float)$row['balance_due']), $invoices));
$summaryOverdue = array_sum(array_map(fn($row) => !empty($row['_is_overdue']) ? (float)$row['balance_due'] : 0.0, $invoices));

$suppliersStmt = $conn->prepare("SELECT id, supplier_name FROM co_suppliers WHERE company_id = ? AND is_active = 1 ORDER BY supplier_name");
$suppliersStmt->execute([$cid]);
$suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);

$filterProject = null;
if ($project_id) {
    $filterProjectStmt = $conn->prepare("SELECT id, project_code, project_name FROM co_projects WHERE id = ? AND company_id = ?");
    $filterProjectStmt->execute([$project_id, $cid]);
    $filterProject = $filterProjectStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$pageTitle = 'Supplier Invoices';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-0">Supplier Invoices</h1>
        <p class="text-muted mb-0">Track supplier bills, project costs, VAT, payment status, GL posting, documents, and balances.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="supplier_recurring_invoices.php" class="btn btn-outline-info"><i class="bi bi-arrow-repeat me-1"></i> Recurring Invoices</a>
        <a href="supplier_invoice_add.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Invoice</a>
    </div>
</div>

<?php if ($flashMsg !== ''): ?><div class="alert alert-success"><?= h($flashMsg) ?></div><?php endif; ?>
<?php if ($flashErr !== ''): ?><div class="alert alert-danger"><?= h($flashErr) ?></div><?php endif; ?>
<?php if ($project_id): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center">
    <span>Filtered to project: <strong><?= $filterProject ? h($filterProject['project_code'] . ' — ' . $filterProject['project_name']) : '#' . $project_id ?></strong></span>
    <a href="supplier_invoices.php" class="btn btn-sm btn-outline-secondary">Clear project filter</a>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card card-round h-100"><div class="card-body"><div class="text-muted small">Invoices</div><h4 class="mb-0"><?= count($invoices) ?></h4><small>visible records</small></div></div></div>
    <div class="col-md-3"><div class="card card-round h-100"><div class="card-body"><div class="text-muted small">Total Value</div><h4 class="mb-0"><?= co_format_money($summaryTotal) ?></h4><small>subtotal + VAT</small></div></div></div>
    <div class="col-md-3"><div class="card card-round h-100"><div class="card-body"><div class="text-muted small">Outstanding</div><h4 class="mb-0"><?= co_format_money($summaryBalance) ?></h4><small>open supplier payable</small></div></div></div>
    <div class="col-md-3"><div class="card card-round h-100"><div class="card-body"><div class="text-muted small">Overdue</div><h4 class="mb-0"><?= co_format_money($summaryOverdue) ?></h4><small>past due balance</small></div></div></div>
</div>

<form method="get" class="card card-round mb-4">
    <div class="card-body row g-3 align-items-end">
        <?php if ($project_id): ?>
        <input type="hidden" name="project_id" value="<?= (int)$project_id ?>">
        <?php endif; ?>
        <div class="col-md-3">
            <label class="form-label">Supplier</label>
            <select name="supplier_id" class="form-select">
            <option value="">All suppliers</option>
            <?php foreach ($suppliers as $sup): ?>
            <option value="<?= (int)$sup['id'] ?>" <?= $supplier_id === (int)$sup['id'] ? 'selected' : '' ?>><?= h($sup['supplier_name']) ?></option>
            <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select">
            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
            <option value="posted" <?= $status === 'posted' ? 'selected' : '' ?>>Posted</option>
            <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Open (posted unpaid)</option>
            <option value="partial" <?= $status === 'partial' ? 'selected' : '' ?>>Partially Paid</option>
            <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
            <option value="voided" <?= $status === 'voided' ? 'selected' : '' ?>>Voided</option>
            <option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>Overdue</option>
        </select></div>
        <div class="col-md-2"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div>
        <div class="col-md-2"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div>
        <div class="col-md-3"><label class="form-label">Search</label><input type="search" name="q" class="form-control" placeholder="Invoice, supplier, project..." value="<?= h($q) ?>"></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Apply Filters</button><a href="supplier_invoices.php" class="btn btn-outline-secondary">Reset</a></div>
    </div>
</form>

<div class="card card-round">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong>Invoice Register</strong>
        <span class="text-muted small">Lifecycle: Draft → Posted → Partially Paid → Paid → Voided. Posted invoices are immutable.</span>
        <span class="text-muted small">Paid <?= co_format_money($summaryPaid) ?> / Balance <?= co_format_money($summaryBalance) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Supplier</th>
                        <th>Invoice #</th>
                        <th>Due</th>
                        <th>Project</th>
                        <th class="text-end">Subtotal</th>
                        <th class="text-end">VAT</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Balance</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                    <tr>
                        <td><?= h($inv['invoice_date']) ?></td>
                        <td><a href="supplier_view.php?id=<?= (int)$inv['supplier_id'] ?>"><?= h($inv['supplier_name']) ?></a></td>
                        <td><strong><?= h($inv['invoice_number']) ?></strong><?php if (!empty($inv['reference'])): ?><br><small class="text-muted">Ref: <?= h($inv['reference']) ?></small><?php endif; ?></td>
                        <td><?= h($inv['due_date'] ?: '-') ?><?= !empty($inv['_is_overdue']) ? '<br><span class="badge bg-danger">Overdue</span>' : '' ?></td>
                        <td><?= $inv['project_code'] ? h($inv['project_code']) . ' — ' . h($inv['project_name']) : '—' ?></td>
                        <td class="text-end"><?= co_format_money($inv['subtotal']) ?></td>
                        <td class="text-end"><?= co_format_money($inv['vat_amount']) ?></td>
                        <td class="text-end"><strong><?= co_format_money($inv['total']) ?></strong></td>
                        <td class="text-end"><?= co_format_money($inv['paid_amount']) ?></td>
                        <td class="text-end"><?= co_format_money($inv['balance_due']) ?></td>
                        <td>
                            <?php
                            $lc = $inv['_lifecycle'] ?? 'draft';
                            echo '<span class="badge bg-' . h(co_supplier_invoice_status_badge_class($lc)) . '">' . h(co_supplier_invoice_status_label($lc)) . '</span>';
                            ?>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a href="supplier_invoice_view.php?id=<?= (int)$inv['id'] ?>" class="btn btn-outline-primary">View</a>
                                <?php if (!empty($inv['_can_edit'])): ?>
                                <a href="supplier_invoice_edit.php?id=<?= (int)$inv['id'] ?>" class="btn btn-outline-secondary">Edit</a>
                                <a href="supplier_invoice_delete.php?id=<?= (int)$inv['id'] ?>" class="btn btn-outline-danger">Delete</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Post this supplier invoice to GL? Financial values will be locked.');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="post_gl">
                                    <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                                    <button type="submit" class="btn btn-warning">Post to GL</button>
                                </form>
                                <?php endif; ?>
                                <?php if ((float)$inv['balance_due'] > 0.005 && !empty($inv['journal_id']) && ($inv['_lifecycle'] ?? '') !== 'voided'): ?>
                                <a href="supplier_payment_add.php?invoice_id=<?= (int)$inv['id'] ?>" class="btn btn-success">Pay</a>
                                <?php endif; ?>
                                <a href="supplier_recurring_invoices.php?action=create&source_invoice_id=<?= (int)$inv['id'] ?>" class="btn btn-outline-info">Recurring</a>
                                <a href="supplier_view.php?id=<?= (int)$inv['supplier_id'] ?>" class="btn btn-outline-secondary">Supplier</a>
                                <a href="supplier_invoice_documents.php?invoice_id=<?= (int)$inv['id'] ?>" class="btn btn-outline-secondary">Docs</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($invoices)): ?>
        <div class="p-5 text-center text-muted"><i class="bi bi-receipt-cutoff fs-1 d-block mb-2"></i>No supplier invoices found for the selected filters. <a href="supplier_invoice_add.php">Add an invoice</a>.</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
