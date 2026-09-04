<?php
/**
 * Construction Module — Record Supplier Payment
 * Allocated → Dr 2110; remainder → Dr Supplier Advances (configurable, default 1410); Cr bank.
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
require_once __DIR__ . '/includes/construction_accounting_integration.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_supplier_require_company_id($conn);
$userId = current_user_id();
$supplier_id = (int)($_GET['supplier_id'] ?? 0);
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$project_id = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$err = '';

$suppliersStmt = $conn->prepare("SELECT id, supplier_name FROM co_suppliers WHERE company_id = ? AND is_active = 1 ORDER BY supplier_name");
$suppliersStmt->execute([$cid]);
$suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);
$paymentAccounts = co_fetch_payment_accounts($conn, $cid);
$hasPayAccountColumn = co_payment_account_column_ready($conn);
$hasAllocations = co_supplier_allocations_ready($conn);
$hasAdvances = co_supplier_advance_schema_ready($conn);

if ($invoice_id > 0) {
    $invoiceSupplierStmt = $conn->prepare("SELECT supplier_id FROM co_supplier_invoices WHERE id = ? AND company_id = ?");
    $invoiceSupplierStmt->execute([$invoice_id, $cid]);
    $invoiceSupplierId = (int)$invoiceSupplierStmt->fetchColumn();
    if ($invoiceSupplierId > 0) {
        $supplier_id = $invoiceSupplierId;
    }
}

function co_supplier_open_invoices_for_payment(PDO $conn, int $companyId, int $supplierId, ?int $projectId = null): array {
    if ($supplierId <= 0) {
        return [];
    }
    $statusFilter = function_exists('co_supplier_invoice_open_status_sql')
        ? co_supplier_invoice_open_status_sql($conn, 'si')
        : '';
    $projectSql = '';
    $params = [$companyId, $supplierId];
    if ($projectId !== null && $projectId > 0) {
        $projectSql = ' AND si.project_id = ? ';
        $params[] = $projectId;
    }
    $stmt = $conn->prepare("
        SELECT si.id, si.invoice_number, si.invoice_date, si.due_date, si.total, si.project_id,
               p.project_code, p.project_name
        FROM co_supplier_invoices si
        LEFT JOIN co_projects p ON p.id = si.project_id
        WHERE si.company_id = ?
          AND si.supplier_id = ?
          AND si.journal_id IS NOT NULL
          {$statusFilter}
          {$projectSql}
        ORDER BY COALESCE(si.due_date, si.invoice_date), si.invoice_date, si.id
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $paid = co_supplier_invoice_paid_amount($conn, $companyId, (int)$row['id']);
        $balance = round((float)$row['total'] - $paid, 2);
        if ($balance <= 0.005) {
            continue;
        }
        $row['paid_amount'] = $paid;
        $row['balance_due'] = $balance;
        $out[] = $row;
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $manualAllocations = $_POST['allocations'] ?? [];
    $selectedAllocations = [];
    foreach ($manualAllocations as $invoiceIdKey => $amountValue) {
        $allocationAmount = round((float)$amountValue, 2);
        if ($allocationAmount > 0) {
            $selectedAllocations[(int)$invoiceIdKey] = $allocationAmount;
        }
    }
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $pay_account_id = (int)($_POST['pay_account_id'] ?? 0);
    $reference = trim($_POST['reference'] ?? '');
    $allocatedSum = round(array_sum($selectedAllocations), 2);

    if (!$supplier_id || $amount <= 0) {
        $err = 'Supplier and amount are required.';
    }
    if (!$err && !$hasPayAccountColumn) {
        $err = 'Run migrations/construction_finance_consolidation.sql before recording supplier payments.';
    }
    if (!$err && !$hasAllocations) {
        $err = 'Run migrations/construction_finance_consolidation.sql to enable invoice allocation for supplier payments.';
    }
    if (!$err && $pay_account_id <= 0) {
        $err = 'Please choose the chart of account used to pay this supplier.';
    }
    if (!$err && $selectedAllocations && $allocatedSum > $amount + 0.005) {
        $err = 'Invoice allocations cannot exceed the payment amount.';
    }
    if (!$err) {
        $accountStmt = $conn->prepare("
            SELECT id FROM re_chart_of_accounts
            WHERE id = ? AND company_id = ? AND is_active = 1 AND is_header = 0 LIMIT 1
        ");
        $accountStmt->execute([$pay_account_id, $cid]);
        if (!$accountStmt->fetchColumn()) {
            $err = 'Selected payment account is not valid for this company.';
        }
    }
    if (!$err && $selectedAllocations) {
        $openInvoices = co_supplier_open_invoices_for_payment($conn, $cid, $supplier_id, $project_id > 0 ? $project_id : null);
        $openById = [];
        foreach ($openInvoices as $invoice) {
            $openById[(int)$invoice['id']] = (float)$invoice['balance_due'];
        }
        foreach ($selectedAllocations as $allocInvoiceId => $allocationAmount) {
            if (!isset($openById[$allocInvoiceId])) {
                $err = 'One selected invoice is not open for this supplier.';
                break;
            }
            if ($allocationAmount > $openById[$allocInvoiceId] + 0.005) {
                $err = 'Allocation cannot exceed invoice balance.';
                break;
            }
        }
    }
    if (!$err) {
        try {
            $advanceAmount = 0.0;
            if ($selectedAllocations) {
                $advanceAmount = $hasAdvances ? round(max(0, $amount - $allocatedSum), 2) : 0.0;
                if (!$hasAdvances && abs($amount - $allocatedSum) > 0.005) {
                    throw new RuntimeException('Run Phase 2 advances migration to post unallocated payment remainders as supplier advances.');
                }
            }

            if ($hasAdvances) {
                $stmt = $conn->prepare("INSERT INTO co_supplier_payments (company_id, supplier_id, payment_date, amount, advance_amount, pay_account_id, reference, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$cid, $supplier_id, $payment_date, $amount, $advanceAmount, $pay_account_id, $reference ?: null, $userId]);
            } else {
                $stmt = $conn->prepare("INSERT INTO co_supplier_payments (company_id, supplier_id, payment_date, amount, pay_account_id, reference, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$cid, $supplier_id, $payment_date, $amount, $pay_account_id, $reference ?: null, $userId]);
            }
            $paymentId = (int)$conn->lastInsertId();

            if ($selectedAllocations) {
                $allocStmt = $conn->prepare("
                    INSERT INTO co_supplier_payment_allocations
                        (company_id, payment_id, invoice_id, allocated_amount)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE allocated_amount = VALUES(allocated_amount)
                ");
                foreach ($selectedAllocations as $allocInvoiceId => $allocationAmount) {
                    $allocStmt->execute([$cid, $paymentId, $allocInvoiceId, $allocationAmount]);
                }
                foreach (array_keys($selectedAllocations) as $allocInvoiceId) {
                    co_supplier_invoice_refresh_status($conn, $cid, (int)$allocInvoiceId);
                }
            } else {
                co_auto_allocate_supplier_payment($conn, $cid, $supplier_id, $paymentId, $amount);
                if ($hasAdvances) {
                    $allocSumStmt = $conn->prepare("SELECT COALESCE(SUM(allocated_amount),0) FROM co_supplier_payment_allocations WHERE company_id=? AND payment_id=?");
                    $allocSumStmt->execute([$cid, $paymentId]);
                    $autoAllocated = round((float)$allocSumStmt->fetchColumn(), 2);
                    $advanceAmount = round(max(0, $amount - $autoAllocated), 2);
                    $conn->prepare("UPDATE co_supplier_payments SET advance_amount = ? WHERE id = ? AND company_id = ?")
                        ->execute([$advanceAmount, $paymentId, $cid]);
                }
            }

            $postResult = co_post_supplier_payment_to_accounting($paymentId, $cid, $userId);
            if (!$postResult['success']) {
                throw new RuntimeException($postResult['error'] ?? 'Payment posting failed.');
            }
            $conn->prepare("UPDATE co_supplier_payments SET journal_id = ? WHERE id = ? AND company_id = ?")
                ->execute([$postResult['journal_id'], $paymentId, $cid]);

            header('Location: supplier_view.php?id=' . $supplier_id);
            exit;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $err = $e->getMessage();
        }
    }
}

$openInvoices = co_supplier_open_invoices_for_payment($conn, $cid, $supplier_id, $project_id > 0 ? $project_id : null);
$openBalance = array_sum(array_map(fn($row) => (float)$row['balance_due'], $openInvoices));
$advanceAcctCode = $hasAdvances ? co_supplier_advance_account_code($conn) : '';

$pageTitle = 'Record Supplier Payment';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="<?= $supplier_id ? 'supplier_view.php?id=' . $supplier_id : 'supplier_invoices.php' ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Record Supplier Payment</h1>
    <p class="text-muted small mb-0">
        Allocated portion Debits Supplier Payable (2110).
        <?php if ($hasAdvances): ?>
        Unallocated remainder Debits Supplier Advances (<?= h($advanceAcctCode) ?>). Credit selected cash/bank.
        <?php else: ?>
        Credit selected cash/bank.
        <?php endif; ?>
    </p>
    <?php if ($project_id > 0): ?>
    <div class="alert alert-info py-2 mt-2 mb-0 small">
        Showing open invoices for project #<?= (int)$project_id ?>
        · <a href="supplier_payment_add.php?supplier_id=<?= (int)$supplier_id ?>">Show all open invoices</a>
    </div>
    <?php endif; ?>
</div>
<?php if ($err): ?>
<div class="alert alert-danger">
    <?= h($err) ?>
    <?php if (strpos($err, '2110') !== false || strpos($err, '1410') !== false || strpos($err, 'not found') !== false || strpos($err, 'Advances') !== false): ?>
    <hr class="my-2">
    <a href="setup_construction_coa.php" class="btn btn-sm btn-warning">Setup construction accounts</a>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php if (!$hasAdvances): ?>
<div class="alert alert-warning">Run <code>migrations/construction_supplier_ap_phase2_advances.sql</code> to enable supplier advances for unallocated payment remainders.</div>
<?php endif; ?>

<form method="post" class="card card-round">
    <?php csrf_field(); ?>
    <?php if ($project_id > 0): ?><input type="hidden" name="project_id" value="<?= (int)$project_id ?>"><?php endif; ?>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Supplier *</label>
                <select name="supplier_id" id="supplierSelect" class="form-select" required>
                    <option value="">— Select —</option>
                    <?php foreach ($suppliers as $sup): ?>
                    <option value="<?= (int)$sup['id'] ?>" <?= $supplier_id === (int)$sup['id'] ? 'selected' : '' ?>><?= h($sup['supplier_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Payment Date *</label>
                <input type="date" name="payment_date" class="form-control" required value="<?= h($_POST['payment_date'] ?? date('Y-m-d')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Amount (AED) *</label>
                <input type="number" step="0.01" min="0.01" name="amount" id="paymentAmount" class="form-control" required value="<?= h($_POST['amount'] ?? '') ?>">
                <div class="form-text">Bank payment total. Remainder after allocations<?= $hasAdvances ? ' becomes a supplier advance' : '' ?>.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Paid From Account *</label>
                <select name="pay_account_id" class="form-select" required>
                    <option value="">— Select cash/bank account —</option>
                    <?php foreach ($paymentAccounts as $account): ?>
                    <option value="<?= (int)$account['id'] ?>" <?= (int)($_POST['pay_account_id'] ?? 0) === (int)$account['id'] ? 'selected' : '' ?>>
                        <?= h($account['account_code'] . ' — ' . $account['account_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Reference</label>
                <input type="text" name="reference" class="form-control" value="<?= h($_POST['reference'] ?? '') ?>" placeholder="e.g. cheque no., transfer ref">
            </div>
            <?php if ($hasAdvances): ?>
            <div class="col-12">
                <div class="alert alert-light border mb-0 py-2">
                    Allocated to invoices: <strong id="allocatedPreview">0.00</strong>
                    · Advance remainder: <strong id="advancePreview">0.00</strong>
                    (asset <?= h($advanceAcctCode) ?>)
                </div>
            </div>
            <?php endif; ?>
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">
                    <div>
                        <h6 class="mb-0">Invoice Allocation</h6>
                        <small class="text-muted">Optional. Leave under the payment amount to create a supplier advance.</small>
                    </div>
                    <div class="text-muted small">Open AP: <?= co_format_money($openBalance) ?></div>
                </div>
                <div class="table-responsive mt-2 border rounded">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light"><tr><th>Invoice</th><th>Date</th><th>Due</th><th>Project</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th style="width:180px">Pay Amount</th></tr></thead>
                        <tbody>
                        <?php foreach ($openInvoices as $invoice): ?>
                            <?php
                            $defaultAllocation = '';
                            if ($invoice_id === (int)$invoice['id']) {
                                $defaultAllocation = number_format((float)$invoice['balance_due'], 2, '.', '');
                            } elseif (isset($_POST['allocations'][(int)$invoice['id']])) {
                                $defaultAllocation = h($_POST['allocations'][(int)$invoice['id']]);
                            }
                            ?>
                            <tr>
                                <td><strong><?= h($invoice['invoice_number']) ?></strong></td>
                                <td><?= h($invoice['invoice_date']) ?></td>
                                <td><?= h($invoice['due_date'] ?: '-') ?></td>
                                <td><?= $invoice['project_code'] ? h($invoice['project_code'] . ' — ' . $invoice['project_name']) : '-' ?></td>
                                <td class="text-end"><?= co_format_money($invoice['total']) ?></td>
                                <td class="text-end"><?= co_format_money($invoice['paid_amount']) ?></td>
                                <td class="text-end"><?= co_format_money($invoice['balance_due']) ?></td>
                                <td><input type="number" step="0.01" min="0" max="<?= h($invoice['balance_due']) ?>" name="allocations[<?= (int)$invoice['id'] ?>]" class="form-control form-control-sm allocation-input" data-balance="<?= h($invoice['balance_due']) ?>" value="<?= $defaultAllocation ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$openInvoices): ?><tr><td colspan="8" class="text-center text-muted py-4"><?= $supplier_id ? ($hasAdvances ? 'No open posted invoices — full amount will create a supplier advance.' : 'No open posted supplier invoices for this supplier.') : 'Select a supplier to see open invoices.' ?></td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex gap-2 justify-content-end mt-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="clearAllocations">Clear</button>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="payAllOpen">Allocate All Open</button>
                </div>
            </div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Save & Post to GL</button></div>
    </div>
</form>
<script>
document.getElementById('supplierSelect')?.addEventListener('change', function () {
    const supplierId = this.value;
    if (supplierId) window.location.href = 'supplier_payment_add.php?supplier_id=' + encodeURIComponent(supplierId);
});
function updateAllocationPreview() {
    let allocated = 0;
    document.querySelectorAll('.allocation-input').forEach((input) => {
        const balance = parseFloat(input.dataset.balance || '0');
        let amount = parseFloat(input.value || '0');
        if (amount < 0) amount = 0;
        if (amount > balance) {
            amount = balance;
            input.value = balance.toFixed(2);
        }
        allocated += amount;
    });
    const paymentAmountEl = document.getElementById('paymentAmount');
    let paymentAmount = parseFloat(paymentAmountEl?.value || '0');
    if (paymentAmountEl && !paymentAmountEl.value && allocated > 0) {
        paymentAmount = allocated;
        paymentAmountEl.value = allocated.toFixed(2);
    }
    const advance = Math.max(0, (paymentAmount || 0) - allocated);
    const allocPrev = document.getElementById('allocatedPreview');
    const advPrev = document.getElementById('advancePreview');
    if (allocPrev) allocPrev.textContent = allocated.toFixed(2);
    if (advPrev) advPrev.textContent = advance.toFixed(2);
}
document.querySelectorAll('.allocation-input').forEach((input) => input.addEventListener('input', updateAllocationPreview));
document.getElementById('paymentAmount')?.addEventListener('input', updateAllocationPreview);
document.getElementById('payAllOpen')?.addEventListener('click', function () {
    let total = 0;
    document.querySelectorAll('.allocation-input').forEach((input) => {
        const bal = parseFloat(input.dataset.balance || '0');
        input.value = bal.toFixed(2);
        total += bal;
    });
    const paymentAmount = document.getElementById('paymentAmount');
    if (paymentAmount && (!paymentAmount.value || parseFloat(paymentAmount.value) < total)) {
        paymentAmount.value = total.toFixed(2);
    }
    updateAllocationPreview();
});
document.getElementById('clearAllocations')?.addEventListener('click', function () {
    document.querySelectorAll('.allocation-input').forEach((input) => { input.value = ''; });
    updateAllocationPreview();
});
updateAllocationPreview();
</script>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
