<?php
/**
 * Construction Module — Add Client Payment (Phase 1: basic form)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_shop_rental_helpers.php';
require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_shop_require_company_id($conn);
$userId = current_user_id();
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$prefillAmount = isset($_GET['amount']) ? (float)$_GET['amount'] : null;
$err = '';
$hasPaymentColumns = co_client_payment_columns_ready($conn);
$hasAllocations = co_client_allocations_ready($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $amount = (float)($_POST['amount'] ?? 0);
    $pay_account_id = (int)($_POST['pay_account_id'] ?? 0);
    $reference = trim($_POST['reference'] ?? '');

    if (!$hasPaymentColumns || !$hasAllocations) {
        $err = 'Run migrations/construction_income_workflow.sql before recording income receipts.';
    } elseif (!$invoice_id || $amount <= 0) {
        $err = 'Invoice and amount are required.';
    } elseif ($pay_account_id <= 0) {
        $err = 'Choose the bank/cash account that received this payment.';
    }
    if (!$err) {
        $conn->beginTransaction();
        try {
            co_shop_record_invoice_payment($conn, $cid, $invoice_id, $amount, $pay_account_id, $payment_date, $reference ?: null, $userId ? (int)$userId : null);
            $conn->commit();
            header('Location: client_invoices.php');
            exit;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $err = $e->getMessage();
        }
    }
}

$allocSelect = "0 AS paid_amount, i.total_amount AS balance_due";
$allocJoin = "";
if ($hasAllocations) {
    $allocSelect = "COALESCE(a.paid_amount, 0) AS paid_amount,
           GREATEST(i.total_amount - COALESCE(a.paid_amount, 0), 0) AS balance_due";
    $allocJoin = "
    LEFT JOIN (
        SELECT invoice_id, SUM(allocated_amount) AS paid_amount
        FROM co_client_payment_allocations
        GROUP BY invoice_id
    ) a ON a.invoice_id = i.id";
}
$invoices = $conn->prepare("
    SELECT i.id, i.invoice_number, i.total_amount, i.status, p.project_code, c.client_name,
           {$allocSelect}
    FROM co_client_invoices i
    LEFT JOIN co_projects p ON p.id = i.project_id
    JOIN co_clients c ON c.id = i.client_id
    {$allocJoin}
    WHERE i.company_id = ?
      AND i.status IN ('draft','sent','partial')
    HAVING balance_due > 0.005
    ORDER BY i.invoice_date DESC
");
$invoices->execute([$cid]);
$invoices = $invoices->fetchAll(PDO::FETCH_ASSOC);
$paymentAccounts = co_fetch_payment_accounts($conn, $cid);

$defaultAmount = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $defaultAmount = (string)($_POST['amount'] ?? '');
} elseif ($prefillAmount !== null && $prefillAmount > 0) {
    $defaultAmount = number_format($prefillAmount, 2, '.', '');
} else {
    foreach ($invoices as $i) {
        if ((int)$i['id'] === $invoice_id) {
            $defaultAmount = number_format((float)$i['balance_due'], 2, '.', '');
            break;
        }
    }
    if ($defaultAmount === '' && $invoices) {
        $defaultAmount = number_format((float)$invoices[0]['balance_due'], 2, '.', '');
    }
}

$pageTitle = 'Add Client Payment';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4"><a href="client_invoices.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a><h1 class="h4 mb-0">Record Client Payment / Allocation</h1>
<p class="text-muted small mb-0">Amount defaults to the selected invoice open balance. Partial and overpayment (tenant credit) are supported (BR-CO-SHOP-005).</p>
</div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if (!$hasPaymentColumns || !$hasAllocations): ?><div class="alert alert-warning">Run <code>migrations/construction_income_workflow.sql</code> to enable receipt account selection and allocations.</div><?php endif; ?>

<form method="post" class="card card-round" id="clientPayForm">
    <div class="card-body">
        <?php csrf_field(); ?>
        <div class="row g-3">
            <div class="col-md-12"><label class="form-label">Invoice *</label>
                <select name="invoice_id" id="payInvoiceSelect" class="form-select" required>
                    <?php foreach ($invoices as $i): ?>
                        <option value="<?= (int)$i['id'] ?>"
                            data-balance="<?= h(number_format((float)$i['balance_due'], 2, '.', '')) ?>"
                            <?= $invoice_id === (int)$i['id'] ? 'selected' : '' ?>>
                            <?= h($i['invoice_number']) ?> — <?= h($i['client_name']) ?> (Balance <?= co_format_money($i['balance_due']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4"><label class="form-label">Payment Date *</label><input type="date" name="payment_date" class="form-control" required value="<?= h($_POST['payment_date'] ?? date('Y-m-d')) ?>"></div>
            <div class="col-md-4"><label class="form-label">Amount (AED) *</label><input type="number" step="0.01" name="amount" id="payAmount" class="form-control" required value="<?= h($defaultAmount) ?>"><div class="form-text">Auto-fills from invoice balance; edit for partial/overpay.</div></div>
            <div class="col-md-4"><label class="form-label">Received To Account *</label><select name="pay_account_id" class="form-select" required><option value="">— Select bank/cash account —</option><?php foreach ($paymentAccounts as $account): ?><option value="<?= (int)$account['id'] ?>" <?= (int)($_POST['pay_account_id'] ?? 0) === (int)$account['id'] ? 'selected' : '' ?>><?= h($account['account_code'] . ' — ' . $account['account_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-12"><label class="form-label">Reference</label><input type="text" name="reference" class="form-control" value="<?= h($_POST['reference'] ?? '') ?>" placeholder="Cheque number / bank ref (optional)"></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Record Payment</button></div>
    </div>
</form>

<?php if (empty($invoices)): ?>
<div class="alert alert-info mt-3">No open invoices found. <a href="client_invoice_create.php">Create an invoice</a> first.</div>
<?php endif; ?>
<script>
(function(){
  var sel=document.getElementById('payInvoiceSelect');
  var amt=document.getElementById('payAmount');
  if(!sel||!amt) return;
  sel.addEventListener('change', function(){
    var opt=sel.options[sel.selectedIndex];
    if(opt && opt.getAttribute('data-balance')) amt.value=opt.getAttribute('data-balance');
  });
})();
</script>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
