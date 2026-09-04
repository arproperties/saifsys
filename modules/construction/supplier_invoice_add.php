<?php
/**
 * Construction Module — Supplier Bill Entry (parity with RE Vendor Bill Entry)
 * GL when posted: Debit Expense/CIP + Input VAT, Credit Supplier Payable.
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
$supplier_id = (int)($_GET['supplier_id'] ?? 0);
$success = '';
$err = '';
$hasExpenseAccountColumn = co_expense_account_column_ready($conn);
co_supplier_invoice_ensure_bill_fields($conn);
$hasBillFields = co_supplier_invoice_bill_fields_ready($conn);

$suppliers = $conn->prepare("SELECT id, supplier_name, vat_number, tax_number FROM co_suppliers WHERE company_id = ? AND is_active = 1 ORDER BY supplier_name");
$suppliers->execute([$cid]);
$suppliers = $suppliers->fetchAll(PDO::FETCH_ASSOC);

$projects = $conn->prepare("SELECT id, project_code, project_name, project_type FROM co_projects WHERE company_id = ? AND status IN ('draft','active') ORDER BY project_name");
$projects->execute([$cid]);
$projects = $projects->fetchAll(PDO::FETCH_ASSOC);

$expenseAccounts = array_values(array_filter(
    co_fetch_expense_accounts($conn, $cid),
    static fn($account) => in_array($account['account_type'] ?? '', ['Expense', 'Asset'], true)
));
$expenseAccountByCode = [];
foreach ($expenseAccounts as $account) {
    $expenseAccountByCode[$account['account_code']] = (int)$account['id'];
}
$defaultExpenseAccountId = $expenseAccountByCode['5125'] ?? ($expenseAccounts[0]['id'] ?? 0);
$defaultVatRate = 5.0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        $action = $_POST['save_action'] ?? 'open';
        $supplier_id = (int)($_POST['supplier_id'] ?? 0);
        $invoice_number = trim($_POST['invoice_number'] ?? '');
        $invoice_date = $_POST['invoice_date'] ?? date('Y-m-d');
        $due_date = $_POST['due_date'] ?? $invoice_date;
        $payment_terms = trim($_POST['payment_terms'] ?? '');
        $place_of_supply = trim($_POST['place_of_supply'] ?? 'Dubai');
        $vat_treatment = $_POST['vat_treatment'] ?? 'vat_registered';
        $order_number = trim($_POST['order_number'] ?? '');
        $permit_number = trim($_POST['permit_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $reference = trim($_POST['reference'] ?? '');
        $default_expense_account_id = (int)($_POST['expense_account_id'] ?? 0) ?: null;

        if (!$supplier_id || $invoice_number === '') {
            throw new RuntimeException('Supplier and invoice number are required.');
        }

        $lineData = co_parse_supplier_invoice_items($_POST['items'] ?? [], null, $default_expense_account_id, $defaultVatRate);
        if (!$lineData['lines']) {
            throw new RuntimeException('Add at least one valid line with expense account, quantity, and rate.');
        }

        $validAccountIds = array_map(static fn($account) => (int)$account['id'], $expenseAccounts);
        foreach ($lineData['lines'] as $line) {
            if (!in_array((int)$line['expense_account_id'], $validAccountIds, true)) {
                throw new RuntimeException('One or more invoice lines use an invalid expense account.');
            }
        }

        $dup = $conn->prepare("SELECT 1 FROM co_supplier_invoices WHERE company_id = ? AND supplier_id = ? AND invoice_number = ?");
        $dup->execute([$cid, $supplier_id, $invoice_number]);
        if ($dup->fetch()) {
            throw new RuntimeException('An invoice with this number already exists for this supplier.');
        }

        $subtotal = $lineData['subtotal'];
        $vat_amount = $lineData['vat_amount'];
        $total = $lineData['total'];
        $vat_pct = $subtotal > 0 ? round($vat_amount / $subtotal * 100, 2) : $defaultVatRate;
        $headerProjectId = !empty($lineData['lines'][0]['project_id']) ? (int)$lineData['lines'][0]['project_id'] : null;
        $headerExpenseAccountId = !empty($lineData['lines'][0]['expense_account_id']) ? (int)$lineData['lines'][0]['expense_account_id'] : $default_expense_account_id;

        // Ensure schema outside the write transaction (DDL implicitly commits in MySQL).
        co_supplier_invoice_full_schema($conn);
        co_supplier_invoice_ensure_bill_fields($conn);

        // Save bill + lines atomically. Do NOT wrap GL posting in this transaction —
        // create_and_post_journal owns its own txn; an outer commit then fails with
        // "There is no active transaction".
        $conn->beginTransaction();
        try {
            if ($hasBillFields) {
                if ($hasExpenseAccountColumn) {
                    $stmt = $conn->prepare("
                        INSERT INTO co_supplier_invoices
                            (company_id, supplier_id, project_id, expense_account_id, invoice_number, invoice_date, due_date,
                             payment_terms, place_of_supply, vat_treatment, order_number, permit_number,
                             subtotal, vat_pct, vat_amount, total, description, reference, notes, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $cid, $supplier_id, $headerProjectId, $headerExpenseAccountId, $invoice_number, $invoice_date, $due_date ?: null,
                        $payment_terms ?: null, $place_of_supply ?: 'Dubai', $vat_treatment,
                        $order_number ?: null, $permit_number ?: null,
                        $subtotal, $vat_pct, $vat_amount, $total,
                        $notes ?: null, $reference ?: null, $notes ?: null, $userId,
                    ]);
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO co_supplier_invoices
                            (company_id, supplier_id, project_id, invoice_number, invoice_date, due_date,
                             payment_terms, place_of_supply, vat_treatment, order_number, permit_number,
                             subtotal, vat_pct, vat_amount, total, description, reference, notes, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $cid, $supplier_id, $headerProjectId, $invoice_number, $invoice_date, $due_date ?: null,
                        $payment_terms ?: null, $place_of_supply ?: 'Dubai', $vat_treatment,
                        $order_number ?: null, $permit_number ?: null,
                        $subtotal, $vat_pct, $vat_amount, $total,
                        $notes ?: null, $reference ?: null, $notes ?: null, $userId,
                    ]);
                }
            } elseif ($hasExpenseAccountColumn) {
                $stmt = $conn->prepare("
                    INSERT INTO co_supplier_invoices (company_id, supplier_id, project_id, expense_account_id, invoice_number, invoice_date, due_date, subtotal, vat_pct, vat_amount, total, description, reference, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$cid, $supplier_id, $headerProjectId, $headerExpenseAccountId, $invoice_number, $invoice_date, $due_date ?: null, $subtotal, $vat_pct, $vat_amount, $total, $notes ?: null, $reference ?: null, $userId]);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO co_supplier_invoices (company_id, supplier_id, project_id, invoice_number, invoice_date, due_date, subtotal, vat_pct, vat_amount, total, description, reference, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$cid, $supplier_id, $headerProjectId, $invoice_number, $invoice_date, $due_date ?: null, $subtotal, $vat_pct, $vat_amount, $total, $notes ?: null, $reference ?: null, $userId]);
            }

            $invoiceId = (int)$conn->lastInsertId();
            co_insert_supplier_invoice_items($conn, $cid, $invoiceId, $lineData['lines']);

            if (!empty($_FILES['attachments']['name'][0])) {
                foreach ($_FILES['attachments']['name'] as $i => $name) {
                    if (($_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                    $file = [
                        'name' => $_FILES['attachments']['name'][$i],
                        'type' => $_FILES['attachments']['type'][$i] ?? '',
                        'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                        'error' => $_FILES['attachments']['error'][$i],
                        'size' => $_FILES['attachments']['size'][$i] ?? 0,
                    ];
                    $_FILES['invoice_attachment_single'] = $file;
                    $upload = co_handle_document_upload('invoice_attachment_single');
                    if (empty($upload['error'])) {
                        $title = $name ?: 'Invoice Attachment';
                        $conn->prepare("INSERT INTO co_supplier_invoice_documents (company_id, supplier_invoice_id, title, file_path, uploaded_by) VALUES (?,?,?,?,?)")
                             ->execute([$cid, $invoiceId, $title, $upload['path'], $userId]);
                    }
                }
                unset($_FILES['invoice_attachment_single']);
            }

            if ($conn->inTransaction()) {
                $conn->commit();
            }
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }

        if ($action === 'open') {
            $postResult = co_post_supplier_invoice_to_accounting($invoiceId, $cid, $userId);
            if (!$postResult['success']) {
                throw new RuntimeException('Invoice saved but GL posting failed: ' . ($postResult['error'] ?? 'Unknown error'));
            }
            if (!empty($postResult['journal_id'])) {
                $conn->prepare("UPDATE co_supplier_invoices SET journal_id = ? WHERE id = ? AND company_id = ?")
                    ->execute([$postResult['journal_id'], $invoiceId, $cid]);
            }
        }

        header('Location: supplier_invoice_view.php?id=' . $invoiceId . ($action === 'draft' ? '&saved=draft' : '&saved=posted'));
        exit;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $err = $e->getMessage();
    }
}

$pageTitle = 'Supplier Bill Entry';
require_once __DIR__ . '/includes/construction_layout_header.php';
$today = date('Y-m-d');
?>

<?php if ($success): ?>
<div class="alert alert-success"><?= h($success) ?> <a href="supplier_invoices.php">View invoices</a></div>
<?php endif; ?>
<?php if ($err): ?>
<div class="alert alert-danger">
    <?= h($err) ?>
    <?php if (strpos($err, 'not found') !== false || strpos($err, '2110') !== false || strpos($err, '2130') !== false): ?>
    <hr class="my-2">
    <a href="setup_construction_coa.php" class="btn btn-sm btn-warning">Setup construction accounts (2110, 2130)</a>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php if (!$hasExpenseAccountColumn): ?>
<div class="alert alert-warning">Run <code>migrations/construction_supplier_expense_account.sql</code> to enable expense account selection on supplier invoices.</div>
<?php endif; ?>
<?php if (!$hasBillFields): ?>
<div class="alert alert-info">Bill fields will be added automatically. You can also run <code>migrations/construction_supplier_invoice_bill_fields.sql</code>.</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div class="page-header-label"><i class="bi bi-file-earmark-plus"></i> Supplier Bill Entry</div>
    <div class="d-flex gap-2">
        <a href="supplier_invoices.php" class="btn btn-outline-secondary">Supplier Invoices</a>
        <a href="reports/supplier_aging.php" class="btn btn-outline-primary">Supplier Aging</a>
    </div>
</div>

<form method="post" enctype="multipart/form-data" class="card card-round" id="invoice-form">
    <div class="card-body">
        <?php csrf_field(); ?>
        <?php if ($hasExpenseAccountColumn): ?>
        <input type="hidden" name="expense_account_id" id="expenseAccountSelect" value="<?= (int)$defaultExpenseAccountId ?>">
        <?php endif; ?>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Supplier *</label>
                <select name="supplier_id" class="form-select" required>
                    <option value="">-- Select Supplier --</option>
                    <?php foreach ($suppliers as $sup):
                        $trn = !empty($sup['vat_number']) ? $sup['vat_number'] : ($sup['tax_number'] ?? '');
                    ?>
                    <option value="<?= (int)$sup['id'] ?>" <?= $supplier_id === (int)$sup['id'] ? 'selected' : '' ?>>
                        <?= h($sup['supplier_name']) ?><?= $trn !== '' ? ' (TRN ' . h($trn) . ')' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Invoice # *</label>
                <input type="text" name="invoice_number" class="form-control" required value="<?= h($_POST['invoice_number'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Invoice Date *</label>
                <input type="date" name="invoice_date" class="form-control" required value="<?= h($_POST['invoice_date'] ?? $today) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Due Date *</label>
                <input type="date" name="due_date" class="form-control" required value="<?= h($_POST['due_date'] ?? $today) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Terms</label>
                <input type="text" name="payment_terms" class="form-control" placeholder="Net 30" value="<?= h($_POST['payment_terms'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Place of Supply</label>
                <input type="text" name="place_of_supply" class="form-control" value="<?= h($_POST['place_of_supply'] ?? 'Dubai') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">VAT Treatment</label>
                <select name="vat_treatment" class="form-select">
                    <?php
                    $vatOptions = [
                        'vat_registered' => 'VAT Registered',
                        'non_vat' => 'Non VAT',
                        'exempt' => 'Exempt',
                        'out_of_scope' => 'Out of Scope',
                    ];
                    $selectedVatTreatment = $_POST['vat_treatment'] ?? 'vat_registered';
                    foreach ($vatOptions as $val => $label):
                    ?>
                    <option value="<?= h($val) ?>" <?= $selectedVatTreatment === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Order/LPO #</label>
                <input type="text" name="order_number" class="form-control" value="<?= h($_POST['order_number'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Permit #</label>
                <input type="text" name="permit_number" class="form-control" value="<?= h($_POST['permit_number'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Reference</label>
                <input type="text" name="reference" class="form-control" value="<?= h($_POST['reference'] ?? '') ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= h($_POST['notes'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label">Attachments</label>
                <input type="file" name="attachments[]" class="form-control" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.xls,.xlsx,.doc,.docx">
            </div>
        </div>

        <hr>
        <h6>Line Items</h6>
        <div class="table-responsive">
            <table class="table table-sm" id="invoiceLines">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Expense Account</th>
                        <th>Qty</th>
                        <th>Rate</th>
                        <th>VAT</th>
                        <th>Project</th>
                        <th class="text-end">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary" id="addLine">Add Line</button>

        <div class="text-end mt-3">
            <div>Subtotal: <strong id="subtotalDisplay">0.00</strong></div>
            <div>VAT: <strong id="vatDisplay">0.00</strong></div>
            <div>Total: <strong id="totalDisplay">0.00</strong> AED</div>
        </div>

        <hr>
        <button type="submit" name="save_action" value="draft" class="btn btn-outline-secondary">Save as Draft</button>
        <button type="submit" name="save_action" value="open" class="btn btn-primary">Save as Open / Post</button>
    </div>
</form>

<script>
(function() {
    var accounts = <?= json_encode($expenseAccounts) ?>;
    var projects = <?= json_encode($projects) ?>;
    var accountByCode = <?= json_encode($expenseAccountByCode) ?>;
    var expenseSelect = document.getElementById('expenseAccountSelect');
    var postedItems = <?= json_encode($_POST['items'] ?? []) ?>;
    var defaultVatRate = <?= json_encode($defaultVatRate) ?>;
    var lineIdx = 0;

    function esc(v) {
        return String(v ?? '').replace(/[&<>"']/g, function(ch) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
        });
    }

    function accountOptions(selected) {
        return '<option value="">-- Account --</option>' + accounts.map(function(a) {
            return '<option value="' + esc(a.id) + '" ' + (String(a.id) === String(selected || '') ? 'selected' : '') + '>' +
                esc(a.account_code + ' ' + a.account_name) + '</option>';
        }).join('');
    }

    function projectOptions(selected) {
        return '<option value="">-</option>' + projects.map(function(p) {
            return '<option value="' + esc(p.id) + '" data-project-type="' + esc(p.project_type || '') + '" ' +
                (String(p.id) === String(selected || '') ? 'selected' : '') + '>' +
                esc(p.project_code + ' — ' + p.project_name) + '</option>';
        }).join('');
    }

    function vatSelectHtml(selected) {
        var opts = [
            ['standard', '5%'],
            ['exempt', 'Exempt'],
            ['zero_rated', 'Zero'],
            ['out_of_scope', 'OOS']
        ];
        return opts.map(function(o) {
            return '<option value="' + o[0] + '" ' + (selected === o[0] ? 'selected' : '') + '>' + o[1] + '</option>';
        }).join('');
    }

    function suggestExpenseAccount(projectSelect) {
        if (!expenseSelect || !projectSelect) return;
        var opt = projectSelect.options[projectSelect.selectedIndex];
        var projectType = opt ? (opt.getAttribute('data-project-type') || '') : '';
        var code = projectType === 'OWNER' ? '1515' : '5125';
        if (accountByCode[code]) {
            expenseSelect.value = String(accountByCode[code]);
        }
    }

    function addLine(data) {
        data = data || {};
        var tr = document.createElement('tr');
        var vatTreatment = data.vat_treatment || 'standard';
        tr.innerHTML =
            '<td><input name="items[' + lineIdx + '][description]" class="form-control form-control-sm" value="' + esc(data.description || '') + '" required></td>' +
            '<td><select name="items[' + lineIdx + '][expense_account_id]" class="form-select form-select-sm line-account" required>' +
                accountOptions(data.expense_account_id || (expenseSelect ? expenseSelect.value : '')) + '</select></td>' +
            '<td><input type="number" step="0.01" name="items[' + lineIdx + '][quantity]" class="form-control form-control-sm qty" value="' + esc(data.quantity ?? 1) + '"></td>' +
            '<td><input type="number" step="0.01" name="items[' + lineIdx + '][unit_price]" class="form-control form-control-sm rate" value="' + esc(data.unit_price ?? 0) + '"></td>' +
            '<td><select name="items[' + lineIdx + '][vat_treatment]" class="form-select form-select-sm vat-treatment">' +
                vatSelectHtml(vatTreatment) + '</select>' +
                '<input type="hidden" name="items[' + lineIdx + '][vat_rate]" class="vat-rate" value="' + (vatTreatment === 'standard' ? defaultVatRate : 0) + '"></td>' +
            '<td><select name="items[' + lineIdx + '][project_id]" class="form-select form-select-sm line-project">' +
                projectOptions(data.project_id || '') + '</select></td>' +
            '<td class="text-end lineTotal">0.00</td>' +
            '<td><button type="button" class="btn btn-sm btn-danger rem">x</button></td>';
        document.querySelector('#invoiceLines tbody').appendChild(tr);
        var projectSelect = tr.querySelector('.line-project');
        projectSelect.addEventListener('change', function() { suggestExpenseAccount(projectSelect); });
        lineIdx++;
        recalcLines();
    }

    function recalcLines() {
        var subtotal = 0, vat = 0;
        document.querySelectorAll('#invoiceLines tbody tr').forEach(function(tr) {
            var q = parseFloat(tr.querySelector('.qty').value) || 0;
            var r = parseFloat(tr.querySelector('.rate').value) || 0;
            var vt = tr.querySelector('.vat-treatment').value;
            var s = q * r;
            var v = vt === 'standard' ? s * (defaultVatRate / 100) : 0;
            tr.querySelector('.vat-rate').value = vt === 'standard' ? defaultVatRate : 0;
            tr.querySelector('.lineTotal').textContent = (s + v).toFixed(2);
            subtotal += s;
            vat += v;
        });
        document.getElementById('subtotalDisplay').textContent = subtotal.toFixed(2);
        document.getElementById('vatDisplay').textContent = vat.toFixed(2);
        document.getElementById('totalDisplay').textContent = (subtotal + vat).toFixed(2);
    }

    document.getElementById('addLine').onclick = function() { addLine({}); };
    document.getElementById('invoiceLines').addEventListener('input', recalcLines);
    document.getElementById('invoiceLines').addEventListener('change', recalcLines);
    document.getElementById('invoiceLines').addEventListener('click', function(e) {
        if (e.target.classList.contains('rem')) {
            e.target.closest('tr').remove();
            recalcLines();
        }
    });

    if (Array.isArray(postedItems) && postedItems.length) {
        postedItems.forEach(addLine);
    } else {
        addLine({});
    }
})();
</script>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
