<?php
/**
 * Construction Module — Edit Supplier Invoice (Draft only)
 * Posted financial documents are immutable; use Void + Amend.
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
$id = (int)($_GET['id'] ?? 0);
$err = '';
$hasExpenseAccountColumn = co_expense_account_column_ready($conn);

if (!$id) { header('Location: supplier_invoices.php'); exit; }

$stmt = $conn->prepare("SELECT si.*, p.project_type, s.supplier_name FROM co_supplier_invoices si JOIN co_suppliers s ON s.id = si.supplier_id LEFT JOIN co_projects p ON p.id = si.project_id WHERE si.id = ? AND si.company_id = ?");
$stmt->execute([$id, $cid]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$invoice) { header('Location: supplier_invoices.php'); exit; }

if (!co_supplier_invoice_can_edit($invoice)) {
    $status = co_supplier_invoice_status($invoice);
    $_SESSION['co_supplier_inv_flash'] = [
        'err' => 'Invoice is ' . co_supplier_invoice_status_label($status) . ' and cannot be edited. Use Void + Amend to correct a posted unpaid invoice.',
    ];
    header('Location: supplier_invoice_view.php?id=' . $id);
    exit;
}

$supplier_id = (int)$invoice['supplier_id'];

$projects = $conn->prepare("SELECT id, project_code, project_name, project_type FROM co_projects WHERE company_id = ? AND status IN ('draft','active','on_hold','completed') ORDER BY project_name");
$projects->execute([$cid]);
$projects = $projects->fetchAll(PDO::FETCH_ASSOC);

$expenseAccounts = co_fetch_expense_accounts($conn, $cid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    // Re-check immutability on POST
    $reload = co_supplier_invoice_load($conn, $cid, $id);
    if (!$reload || !co_supplier_invoice_can_edit($reload)) {
        header('Location: supplier_invoice_view.php?id=' . $id);
        exit;
    }

    $project_id = (int)($_POST['project_id'] ?? 0) ?: null;
    $expense_account_id = (int)($_POST['expense_account_id'] ?? 0) ?: null;
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $invoice_date = $_POST['invoice_date'] ?? date('Y-m-d');
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $subtotal = (float)($_POST['subtotal'] ?? 0);
    $vat_pct = (float)($_POST['vat_pct'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $reference = trim($_POST['reference'] ?? '');

    if (!$invoice_number || $subtotal <= 0) {
        $err = 'Invoice number and subtotal are required.';
    }
    $vat_amount = round($subtotal * ($vat_pct / 100), 2);
    $total = $subtotal + $vat_amount;

    if (!$err && $hasExpenseAccountColumn) {
        if ($expense_account_id <= 0) {
            $err = 'Please choose the expense account for this invoice.';
        } else {
            $validAccount = false;
            foreach ($expenseAccounts as $account) {
                if ((int)$account['id'] === $expense_account_id) {
                    $validAccount = true;
                    break;
                }
            }
            if (!$validAccount) {
                $err = 'Selected expense account is not valid for this company.';
            }
        }
    }
    if (!$err) {
        $dupStmt = $conn->prepare("SELECT 1 FROM co_supplier_invoices WHERE company_id = ? AND supplier_id = ? AND invoice_number = ? AND id <> ?");
        $dupStmt->execute([$cid, $supplier_id, $invoice_number, $id]);
        if ($dupStmt->fetch()) {
            $err = 'Another invoice with this number already exists for this supplier.';
        }
    }
    if (!$err) {
        try {
            if ($hasExpenseAccountColumn) {
                $updateStmt = $conn->prepare("
                    UPDATE co_supplier_invoices
                    SET project_id = ?, expense_account_id = ?, invoice_number = ?, invoice_date = ?, due_date = ?,
                        subtotal = ?, vat_pct = ?, vat_amount = ?, total = ?, description = ?, reference = ?,
                        updated_at = NOW()
                    WHERE id = ? AND company_id = ? AND journal_id IS NULL
                ");
                $updateStmt->execute([$project_id, $expense_account_id, $invoice_number, $invoice_date, $due_date, $subtotal, $vat_pct, $vat_amount, $total, $description ?: null, $reference ?: null, $id, $cid]);
            } else {
                $updateStmt = $conn->prepare("
                    UPDATE co_supplier_invoices
                    SET project_id = ?, invoice_number = ?, invoice_date = ?, due_date = ?,
                        subtotal = ?, vat_pct = ?, vat_amount = ?, total = ?, description = ?, reference = ?,
                        updated_at = NOW()
                    WHERE id = ? AND company_id = ? AND journal_id IS NULL
                ");
                $updateStmt->execute([$project_id, $invoice_number, $invoice_date, $due_date, $subtotal, $vat_pct, $vat_amount, $total, $description ?: null, $reference ?: null, $id, $cid]);
            }
            if ($updateStmt->rowCount() < 1) {
                throw new RuntimeException('Invoice could not be updated (it may no longer be a draft).');
            }
            if (co_supplier_invoice_lifecycle_ready($conn)) {
                $conn->prepare("UPDATE co_supplier_invoices SET status = 'draft' WHERE id = ? AND company_id = ? AND journal_id IS NULL")
                    ->execute([$id, $cid]);
            }
            co_supplier_ap_audit(
                $conn,
                $cid,
                $supplier_id,
                $id,
                null,
                'invoice_draft_updated',
                null,
                $invoice_number,
                $total,
                'Draft supplier invoice updated',
                $userId ? (int)$userId : null,
                'supplier_invoice',
                null
            );
            header('Location: supplier_invoice_view.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$form = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $invoice;
$selectedExpenseAccountId = (int)($form['expense_account_id'] ?? 0);

$pageTitle = 'Edit Supplier Invoice';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="supplier_invoice_view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Edit Supplier Invoice</h1>
    <p class="text-muted small mb-0">Draft only. Financial values lock after posting; use Void + Amend to correct a posted invoice.</p>
</div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if (!$hasExpenseAccountColumn): ?><div class="alert alert-warning">Run <code>migrations/construction_supplier_expense_account.sql</code> to enable expense account selection.</div><?php endif; ?>

<form method="post" class="card card-round">
    <?php csrf_field(); ?>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Supplier</label>
                <input type="text" class="form-control" readonly value="<?= h($invoice['supplier_name'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Project (optional)</label>
                <select name="project_id" class="form-select">
                    <option value="">— None —</option>
                    <?php foreach ($projects as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= (int)($form['project_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['project_code']) ?> — <?= h($p['project_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($hasExpenseAccountColumn): ?>
            <div class="col-md-12">
                <label class="form-label">Expense Account *</label>
                <select name="expense_account_id" class="form-select" required>
                    <option value="">— Select expense account —</option>
                    <?php foreach ($expenseAccounts as $account): ?>
                    <option value="<?= (int)$account['id'] ?>" <?= $selectedExpenseAccountId === (int)$account['id'] ? 'selected' : '' ?>>
                        <?= h($account['account_code'] . ' — ' . $account['account_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-4">
                <label class="form-label">Invoice Number *</label>
                <input type="text" name="invoice_number" class="form-control" required value="<?= h($form['invoice_number'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Invoice Date *</label>
                <input type="date" name="invoice_date" class="form-control" required value="<?= h($form['invoice_date'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Due Date</label>
                <input type="date" name="due_date" class="form-control" value="<?= h($form['due_date'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Subtotal (AED) *</label>
                <input type="number" step="0.01" name="subtotal" id="subtotal" class="form-control" required value="<?= h($form['subtotal'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">VAT %</label>
                <input type="number" step="0.01" name="vat_pct" id="vat_pct" class="form-control" value="<?= h($form['vat_pct'] ?? '5') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">VAT Amount (AED)</label>
                <input type="number" step="0.01" id="vat_amount" class="form-control" readonly>
            </div>
            <div class="col-md-4">
                <label class="form-label">Total (AED)</label>
                <input type="number" step="0.01" id="total" class="form-control" readonly>
            </div>
            <div class="col-md-8">
                <label class="form-label">Description</label>
                <input type="text" name="description" class="form-control" value="<?= h($form['description'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Reference</label>
                <input type="text" name="reference" class="form-control" value="<?= h($form['reference'] ?? '') ?>">
            </div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Save Draft</button></div>
    </div>
</form>
<script>
(function() {
    var subtotal = document.getElementById('subtotal');
    var vatPct = document.getElementById('vat_pct');
    var vatAmount = document.getElementById('vat_amount');
    var total = document.getElementById('total');
    function recalc() {
        var s = parseFloat(subtotal.value) || 0;
        var p = parseFloat(vatPct.value) || 0;
        var v = Math.round(s * (p / 100) * 100) / 100;
        vatAmount.value = v.toFixed(2);
        total.value = (s + v).toFixed(2);
    }
    subtotal.addEventListener('input', recalc);
    vatPct.addEventListener('input', recalc);
    recalc();
})();
</script>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
