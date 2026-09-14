<?php
/**
 * Real Estate Module - Edit (correct) an issued invoice
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/invoice_edit_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = (int)(current_company_id($conn) ?: 0);
if ($currentCompanyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}

$invoiceId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$loaded = re_invoice_edit_load($conn, $currentCompanyId, $invoiceId);
$invoice = $loaded['invoice'];
$items = $loaded['items'];
if (!$invoice) {
    header('Location: billing_invoices.php');
    exit;
}

$error = '';
$blocker = re_invoice_edit_blocker($conn, $currentCompanyId, $invoice, $items);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $blocker === null) {
    csrf_verify();
    $result = re_invoice_edit_apply(
        $conn,
        $currentCompanyId,
        $invoiceId,
        is_array($_POST['items'] ?? null) ? $_POST['items'] : [],
        (string)($_POST['notes'] ?? ''),
        (string)($_POST['reason'] ?? ''),
        current_user_id()
    );
    if (!empty($result['success'])) {
        if (!empty($result['no_change'])) {
            $msg = 'Nothing was changed.';
        } elseif (!empty($result['amounts_changed'])) {
            $msg = 'Invoice updated: total ' . number_format($result['old_total'], 2) . ' → ' . number_format($result['new_total'], 2) . ' AED. Accounting entry reposted.';
            if ($result['credited'] > 0.005) {
                $msg .= ' ' . number_format($result['credited'], 2) . ' AED already paid moved to tenant credit.';
            }
        } else {
            $msg = 'Invoice details updated.';
        }
        $_SESSION['re_invoice_edit_flash'] = $msg;
        header('Location: billing_invoice_view.php?id=' . $invoiceId);
        exit;
    }
    $error = (string)($result['error'] ?? 'Could not update the invoice.');
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$formItems = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
$paid = (float)($invoice['paid_amount'] ?? 0);

$pageTitle = 'Edit Invoice #' . h($invoice['invoice_number']);
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">Edit Invoice #<?= h($invoice['invoice_number']) ?></div>
    <a href="billing_invoice_view.php?id=<?= $invoiceId ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back to Invoice
    </a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($blocker !== null): ?>
    <div class="alert alert-warning"><?= h($blocker) ?></div>
<?php else: ?>

<div class="card mb-4">
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <strong><?= h(trim($invoice['first_name'] . ' ' . $invoice['last_name'])) ?></strong><br>
                <?= h($invoice['building_name']) ?>, Unit <?= h($invoice['unit_number']) ?><br>
                Lease <?= h($invoice['lease_number']) ?>
            </div>
            <div class="col-md-6 text-md-end">
                Date: <?= date('M d, Y', strtotime($invoice['invoice_date'])) ?><br>
                Status: <?= h(ucfirst($invoice['status'])) ?><br>
                Paid so far: <strong><?= number_format($paid, 2) ?> AED</strong>
            </div>
        </div>
    </div>
</div>

<form method="POST" id="invoiceEditForm">
    <?php csrf_field(); ?>
    <input type="hidden" name="id" value="<?= $invoiceId ?>">

    <div class="card mb-4">
        <div class="card-body">
            <h5 class="mb-3">Invoice Items</h5>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th style="min-width:260px;">Description</th>
                            <th class="text-end" style="width:100px;">Quantity</th>
                            <th class="text-end" style="width:140px;">Unit Price</th>
                            <th class="text-end" style="width:100px;">VAT %</th>
                            <th class="text-end" style="width:130px;">VAT Amount</th>
                            <th class="text-end" style="width:140px;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item):
                        $id = (int)$item['id'];
                        $v = $formItems[$id] ?? $item;
                    ?>
                        <tr class="edit-line">
                            <td>
                                <input type="text" name="items[<?= $id ?>][item_name]" class="form-control mb-1" value="<?= h($v['item_name'] ?? '') ?>" required>
                                <input type="text" name="items[<?= $id ?>][item_description]" class="form-control form-control-sm" value="<?= h($v['item_description'] ?? '') ?>" placeholder="Description">
                            </td>
                            <td><input type="number" step="0.01" min="0.01" name="items[<?= $id ?>][quantity]" class="form-control text-end js-qty" value="<?= h(number_format((float)($v['quantity'] ?? 0), 2, '.', '')) ?>" required></td>
                            <td><input type="number" step="0.01" min="0" name="items[<?= $id ?>][unit_price]" class="form-control text-end js-price" value="<?= h(number_format((float)($v['unit_price'] ?? 0), 2, '.', '')) ?>" required></td>
                            <td><input type="number" step="0.01" min="0" max="100" name="items[<?= $id ?>][tax_rate]" class="form-control text-end js-rate" value="<?= h(number_format((float)($v['tax_rate'] ?? 0), 2, '.', '')) ?>"></td>
                            <td><input type="number" step="0.01" min="0" name="items[<?= $id ?>][tax_amount]" class="form-control text-end js-tax" value="<?= h(number_format((float)($v['tax_amount'] ?? 0), 2, '.', '')) ?>"></td>
                            <td class="text-end fw-semibold js-line-total"><?= number_format((float)$item['line_total'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5" class="text-end">Subtotal:</td>
                            <td class="text-end js-subtotal"><?= number_format((float)$invoice['subtotal'], 2) ?></td>
                        </tr>
                        <tr>
                            <td colspan="5" class="text-end">VAT:</td>
                            <td class="text-end js-vat"><?= number_format((float)$invoice['tax_amount'], 2) ?></td>
                        </tr>
                        <?php if ((float)$invoice['discount_amount'] > 0): ?>
                        <tr>
                            <td colspan="5" class="text-end">Discount:</td>
                            <td class="text-end">-<?= number_format((float)$invoice['discount_amount'], 2) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="table-primary">
                            <td colspan="5" class="text-end"><strong>New Total (was <?= number_format((float)$invoice['total_amount'], 2) ?>):</strong></td>
                            <td class="text-end"><strong class="js-total"><?= number_format((float)$invoice['total_amount'], 2) ?></strong> AED</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="small text-muted js-balance-hint"></div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="3"><?= h($_POST['notes'] ?? $invoice['notes'] ?? '') ?></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Reason for correction <span class="text-danger">*</span></label>
                <input type="text" name="reason" class="form-control" maxlength="255" required value="<?= h($_POST['reason'] ?? '') ?>" placeholder="e.g. Wrong monthly rent calculation">
            </div>
            <div class="alert alert-info small mb-3">
                If the amount changes, the original accounting entry is reversed and a corrected one is posted on the same invoice date.
                If the new total is lower than what was already paid, the extra goes to the tenant's credit.
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Changes</button>
            <a href="billing_invoice_view.php?id=<?= $invoiceId ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </div>
</form>

<script>
(function () {
    const form = document.getElementById('invoiceEditForm');
    const paid = <?= json_encode(round($paid, 2)) ?>;
    const discount = <?= json_encode(round((float)$invoice['discount_amount'], 2)) ?>;
    const fmt = n => n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const num = el => parseFloat(el.value) || 0;
    const r2 = n => Math.round(n * 100) / 100;

    function recalc() {
        let subtotal = 0, vat = 0;
        form.querySelectorAll('.edit-line').forEach(row => {
            const base = r2(num(row.querySelector('.js-qty')) * num(row.querySelector('.js-price')));
            const tax = r2(num(row.querySelector('.js-tax')));
            row.querySelector('.js-line-total').textContent = fmt(base + tax);
            subtotal += base;
            vat += tax;
        });
        const total = r2(subtotal + vat - discount);
        form.querySelector('.js-subtotal').textContent = fmt(subtotal);
        form.querySelector('.js-vat').textContent = fmt(vat);
        form.querySelector('.js-total').textContent = fmt(total);
        const hint = form.querySelector('.js-balance-hint');
        if (paid > 0.005 && total < paid - 0.005) {
            hint.textContent = fmt(paid - total) + ' AED already paid will move to tenant credit.';
        } else if (total > paid + 0.005) {
            hint.textContent = 'Outstanding after save: ' + fmt(total - paid) + ' AED.';
        } else {
            hint.textContent = '';
        }
    }

    form.querySelectorAll('.edit-line').forEach(row => {
        const syncTax = () => {
            const base = num(row.querySelector('.js-qty')) * num(row.querySelector('.js-price'));
            row.querySelector('.js-tax').value = r2(base * num(row.querySelector('.js-rate')) / 100).toFixed(2);
            recalc();
        };
        row.querySelector('.js-qty').addEventListener('input', syncTax);
        row.querySelector('.js-price').addEventListener('input', syncTax);
        row.querySelector('.js-rate').addEventListener('input', syncTax);
        row.querySelector('.js-tax').addEventListener('input', recalc);
    });
    recalc();
})();
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
