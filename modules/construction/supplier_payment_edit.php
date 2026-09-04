<?php
/**
 * Construction Module — Edit Supplier Payment
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
$hasPayAccountColumn = co_payment_account_column_ready($conn);
$hasAllocations = co_supplier_allocations_ready($conn);

if (!$id) { header('Location: supplier_payments.php'); exit; }

$stmt = $conn->prepare("SELECT sp.*, s.supplier_name FROM co_supplier_payments sp JOIN co_suppliers s ON s.id = sp.supplier_id WHERE sp.id = ? AND sp.company_id = ?");
$stmt->execute([$id, $cid]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$payment) { header('Location: supplier_payments.php'); exit; }

$supplier_id = (int)$payment['supplier_id'];
$paymentAccounts = co_fetch_payment_accounts($conn, $cid);

$existingAllocations = [];
if ($hasAllocations) {
    $allocStmt = $conn->prepare("SELECT invoice_id, allocated_amount FROM co_supplier_payment_allocations WHERE company_id = ? AND payment_id = ?");
    $allocStmt->execute([$cid, $id]);
    foreach ($allocStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existingAllocations[(int)$row['invoice_id']] = (float)$row['allocated_amount'];
    }
}

function co_supplier_invoices_for_payment_edit(PDO $conn, int $companyId, int $supplierId, int $paymentId): array {
    if (!co_supplier_allocations_ready($conn)) {
        return [];
    }
    $statusFilter = function_exists('co_supplier_invoice_open_status_sql')
        ? co_supplier_invoice_open_status_sql($conn, 'si')
        : '';
    $stmt = $conn->prepare("
        SELECT si.id, si.invoice_number, si.invoice_date, si.due_date, si.total,
               p.project_code, p.project_name,
               COALESCE(a.allocated_amount, 0) AS paid_amount,
               GREATEST(si.total - COALESCE(a.allocated_amount, 0), 0) AS balance_due,
               COALESCE(cur.allocated_amount, 0) AS current_allocation
        FROM co_supplier_invoices si
        LEFT JOIN co_projects p ON p.id = si.project_id
        LEFT JOIN (
            SELECT invoice_id, SUM(allocated_amount) AS allocated_amount
            FROM co_supplier_payment_allocations
            GROUP BY invoice_id
        ) a ON a.invoice_id = si.id
        LEFT JOIN co_supplier_payment_allocations cur
               ON cur.invoice_id = si.id AND cur.payment_id = ? AND cur.company_id = si.company_id
        WHERE si.company_id = ?
          AND si.supplier_id = ?
          AND si.journal_id IS NOT NULL
          {$statusFilter}
        HAVING balance_due > 0.005 OR current_allocation > 0.005
        ORDER BY COALESCE(si.due_date, si.invoice_date), si.invoice_date, si.id
    ");
    $stmt->execute([$paymentId, $companyId, $supplierId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $manualAllocations = $_POST['allocations'] ?? [];
    $selectedAllocations = [];
    foreach ($manualAllocations as $invoiceId => $amountValue) {
        $allocationAmount = round((float)$amountValue, 2);
        if ($allocationAmount > 0) {
            $selectedAllocations[(int)$invoiceId] = $allocationAmount;
        }
    }
    $amount = $selectedAllocations ? array_sum($selectedAllocations) : (float)($_POST['amount'] ?? 0);
    $pay_account_id = (int)($_POST['pay_account_id'] ?? 0);
    $reference = trim($_POST['reference'] ?? '');

    if ($amount <= 0) {
        $err = 'Amount is required.';
    }
    if (!$err && !$hasPayAccountColumn) {
        $err = 'Run migrations/construction_finance_consolidation.sql before editing supplier payments.';
    }
    if (!$err && $pay_account_id <= 0) {
        $err = 'Please choose the chart of account used to pay this supplier.';
    }
    if (!$err) {
        $accountStmt = $conn->prepare("SELECT id FROM re_chart_of_accounts WHERE id = ? AND company_id = ? AND is_active = 1 AND is_header = 0 LIMIT 1");
        $accountStmt->execute([$pay_account_id, $cid]);
        if (!$accountStmt->fetchColumn()) {
            $err = 'Selected payment account is not valid for this company.';
        }
    }
    if (!$err && $hasAllocations) {
        $openInvoices = co_supplier_invoices_for_payment_edit($conn, $cid, $supplier_id, $id);
        $openById = [];
        foreach ($openInvoices as $invoice) {
            $invoiceId = (int)$invoice['id'];
            $maxAllowed = (float)$invoice['balance_due'] + (float)$invoice['current_allocation'];
            $openById[$invoiceId] = $maxAllowed;
        }
        foreach ($selectedAllocations as $allocInvoiceId => $allocationAmount) {
            if (!isset($openById[$allocInvoiceId])) {
                $err = 'One selected invoice is not available for this supplier.';
                break;
            }
            if ($allocationAmount > $openById[$allocInvoiceId] + 0.005) {
                $err = 'Allocation cannot exceed invoice balance.';
                break;
            }
        }
    }
    if (!$err) {
        $conn->beginTransaction();
        try {
            $oldJournalId = (int)($payment['journal_id'] ?? 0);
            if ($oldJournalId > 0) {
                $reverseResult = reverse_journal($oldJournalId, 'Supplier payment edited', $userId, $payment_date);
                if (!$reverseResult['success']) {
                    throw new RuntimeException($reverseResult['error'] ?? 'Could not reverse existing journal.');
                }
            }

            $conn->prepare("
                UPDATE co_supplier_payments
                SET payment_date = ?, amount = ?, pay_account_id = ?, reference = ?, journal_id = NULL
                WHERE id = ? AND company_id = ?
            ")->execute([$payment_date, $amount, $pay_account_id, $reference ?: null, $id, $cid]);

            if ($hasAllocations) {
                $prevInvoiceIds = array_keys($existingAllocations);
                $conn->prepare("DELETE FROM co_supplier_payment_allocations WHERE company_id = ? AND payment_id = ?")->execute([$cid, $id]);
                if ($selectedAllocations) {
                    $allocStmt = $conn->prepare("INSERT INTO co_supplier_payment_allocations (company_id, payment_id, invoice_id, allocated_amount) VALUES (?, ?, ?, ?)");
                    foreach ($selectedAllocations as $allocInvoiceId => $allocationAmount) {
                        $allocStmt->execute([$cid, $id, $allocInvoiceId, $allocationAmount]);
                    }
                }
                if (function_exists('co_supplier_invoice_refresh_status')) {
                    foreach (array_unique(array_merge($prevInvoiceIds, array_keys($selectedAllocations))) as $invRefreshId) {
                        co_supplier_invoice_refresh_status($conn, $cid, (int)$invRefreshId);
                    }
                }
            }

            $postResult = co_post_supplier_payment_to_accounting($id, $cid, $userId);
            if (!$postResult['success']) {
                throw new RuntimeException($postResult['error'] ?? 'Payment posting failed.');
            }
            $conn->prepare("UPDATE co_supplier_payments SET journal_id = ? WHERE id = ? AND company_id = ?")->execute([$postResult['journal_id'], $id, $cid]);

            $conn->commit();
            header('Location: supplier_payment_view.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $err = $e->getMessage();
        }
    }
}

$openInvoices = co_supplier_invoices_for_payment_edit($conn, $cid, $supplier_id, $id);
$form = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $payment;
$postAllocations = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['allocations'] ?? []) : $existingAllocations;

$pageTitle = 'Edit Supplier Payment';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="supplier_payment_view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Edit Supplier Payment</h1>
    <p class="text-muted small mb-0">Changes reverse the old GL entry and post a new journal.</p>
</div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if (!$hasPayAccountColumn): ?><div class="alert alert-warning">Run <code>migrations/construction_finance_consolidation.sql</code> to enable payment account selection.</div><?php endif; ?>

<form method="post" class="card card-round">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Supplier</label>
                <input type="text" class="form-control" readonly value="<?= h($payment['supplier_name']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Payment Date *</label>
                <input type="date" name="payment_date" class="form-control" required value="<?= h($form['payment_date'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Amount (AED) *</label>
                <input type="number" step="0.01" name="amount" id="paymentAmount" class="form-control" required value="<?= h($form['amount'] ?? '') ?>" <?= $hasAllocations ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-6">
                <label class="form-label">Paid From Account *</label>
                <select name="pay_account_id" class="form-select" required>
                    <option value="">— Select cash/bank account —</option>
                    <?php foreach ($paymentAccounts as $account): ?>
                    <option value="<?= (int)$account['id'] ?>" <?= (int)($form['pay_account_id'] ?? 0) === (int)$account['id'] ? 'selected' : '' ?>>
                        <?= h($account['account_code'] . ' — ' . $account['account_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Reference</label>
                <input type="text" name="reference" class="form-control" value="<?= h($form['reference'] ?? '') ?>">
            </div>
            <?php if ($hasAllocations): ?>
            <div class="col-12">
                <h6 class="mb-2">Invoice Allocation</h6>
                <div class="table-responsive border rounded">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light"><tr><th>Invoice</th><th>Date</th><th class="text-end">Total</th><th class="text-end">Open</th><th style="width:180px">Pay Amount</th></tr></thead>
                        <tbody>
                        <?php foreach ($openInvoices as $invoice): ?>
                            <?php
                            $invoiceId = (int)$invoice['id'];
                            $maxAlloc = (float)$invoice['balance_due'] + (float)$invoice['current_allocation'];
                            $defaultAllocation = isset($postAllocations[$invoiceId]) ? number_format((float)$postAllocations[$invoiceId], 2, '.', '') : '';
                            ?>
                            <tr>
                                <td><strong><?= h($invoice['invoice_number']) ?></strong></td>
                                <td><?= h($invoice['invoice_date']) ?></td>
                                <td class="text-end"><?= co_format_money($invoice['total']) ?></td>
                                <td class="text-end"><?= co_format_money($maxAlloc) ?></td>
                                <td><input type="number" step="0.01" min="0" max="<?= h($maxAlloc) ?>" name="allocations[<?= $invoiceId ?>]" class="form-control form-control-sm allocation-input" data-balance="<?= h($maxAlloc) ?>" value="<?= h($defaultAllocation) ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$openInvoices): ?><tr><td colspan="5" class="text-center text-muted py-4">No posted invoices available for allocation.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Save & Repost to GL</button></div>
    </div>
</form>
<?php if ($hasAllocations): ?>
<script>
function updatePaymentTotal() {
    let total = 0;
    document.querySelectorAll('.allocation-input').forEach((input) => {
        const balance = parseFloat(input.dataset.balance || '0');
        let amount = parseFloat(input.value || '0');
        if (amount < 0) amount = 0;
        if (amount > balance) { amount = balance; input.value = balance.toFixed(2); }
        total += amount;
    });
    const paymentAmount = document.getElementById('paymentAmount');
    if (paymentAmount) paymentAmount.value = total > 0 ? total.toFixed(2) : '';
}
document.querySelectorAll('.allocation-input').forEach((input) => input.addEventListener('input', updatePaymentTotal));
updatePaymentTotal();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
