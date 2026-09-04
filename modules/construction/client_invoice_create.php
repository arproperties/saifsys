<?php
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
$userId = (int)(current_user_id() ?: 0);
$project_id = (int)($_GET['project_id'] ?? 0);
$err = '';
$hasIncomeColumns = co_client_invoice_columns_ready($conn);
co_client_invoice_full_schema($conn);
$defaultVatPct = 5.0;
$selectedVatMode = ($_POST['vat_mode'] ?? 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive';
$selectedVatPct = isset($_POST['vat_pct']) ? (float) $_POST['vat_pct'] : $defaultVatPct;
$selectedEnteredAmount = isset($_POST['entered_amount']) ? (string) $_POST['entered_amount'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = (int)($_POST['project_id'] ?? 0);
    $client_id = (int)($_POST['client_id'] ?? 0);
    $source_type = $_POST['source_type'] ?? 'manual';
    $invoice_date = $_POST['invoice_date'] ?? date('Y-m-d');
    $due_date = $_POST['due_date'] ?? $invoice_date;
    $vat_mode = ($_POST['vat_mode'] ?? 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive';
    $vat_pct = (float)($_POST['vat_pct'] ?? $defaultVatPct);
    $description = trim($_POST['description'] ?? '');
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $income_account_id = (int)($_POST['income_account_id'] ?? 0);
    if (!$income_account_id) {
        $income_account_id = co_default_income_account_id($conn, $cid, $source_type);
    }
    $lineData = co_parse_client_invoice_items($_POST['items'] ?? [], $income_account_id, $vat_pct, $vat_mode);
    $subtotal = $lineData['subtotal'];
    $vat_amount = $lineData['vat_amount'];
    $total = $lineData['total'];

    if (!$hasIncomeColumns) {
        $err = 'Run migrations/construction_income_workflow.sql before creating income invoices.';
    } elseif (!$client_id) {
        $err = 'Client is required.';
    } elseif ($source_type === 'construction_project' && !$project_id) {
        $err = 'Choose a project for construction project invoices.';
    } elseif (!$lineData['lines']) {
        $err = 'Add at least one valid invoice line.';
    }
    if (!$invoice_number) {
        $invoice_number = co_next_document_number($conn, $cid, 'CINV', 'co_client_invoices', 'invoice_number');
    }
    if (!$income_account_id && !$err) {
        $err = 'Choose an income account or run Setup Accounts to create Construction income accounts.';
    }
    if (!$err) {
        $chk = $conn->prepare("SELECT 1 FROM co_client_invoices WHERE company_id = ? AND invoice_number = ?");
        $chk->execute([$cid, $invoice_number]);
        if ($chk->fetch()) $err = 'Invoice number already exists.';
    }
    if (!$err) {
        $conn->beginTransaction();
        try {
            $invId = co_create_income_invoice($conn, $cid, $client_id, $project_id, $source_type, 0, $invoice_number, $invoice_date, $due_date, $subtotal, $vat_amount, $description, $income_account_id, $userId, $lineData['lines']);
            $postResult = co_post_client_invoice_to_accounting($invId, $cid, $userId);
            if ($postResult['success'] && !empty($postResult['journal_id'])) {
                $conn->prepare("UPDATE co_client_invoices SET journal_id = ?, status = 'sent' WHERE id = ? AND company_id = ?")
                    ->execute([$postResult['journal_id'], $invId, $cid]);
            } else {
                throw new RuntimeException($postResult['error'] ?? 'Invoice posting failed.');
            }
            if (!empty($_FILES['attachments']['name'][0])) {
                foreach ($_FILES['attachments']['name'] as $i => $name) {
                    if (($_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                    $_FILES['client_invoice_attachment_single'] = [
                        'name' => $_FILES['attachments']['name'][$i],
                        'type' => $_FILES['attachments']['type'][$i] ?? '',
                        'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                        'error' => $_FILES['attachments']['error'][$i],
                        'size' => $_FILES['attachments']['size'][$i] ?? 0,
                    ];
                    $upload = co_handle_document_upload('client_invoice_attachment_single');
                    if (empty($upload['error'])) {
                        $conn->prepare("INSERT INTO co_client_invoice_documents (company_id, client_invoice_id, title, file_path, uploaded_by) VALUES (?,?,?,?,?)")
                            ->execute([$cid, $invId, $name ?: 'Invoice Attachment', $upload['path'], $userId]);
                    }
                }
                unset($_FILES['client_invoice_attachment_single']);
            }
            $conn->commit();
            header('Location: client_invoices.php');
            exit;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $err = $e->getMessage();
        }
    }
}

$invoiceProjects = $conn->prepare("
    SELECT id, project_code, project_name, project_type, client_id
    FROM co_projects
    WHERE company_id = ? AND status IN ('draft', 'active', 'on_hold')
    ORDER BY project_name
");
$invoiceProjects->execute([$cid]);
$invoiceProjects = $invoiceProjects->fetchAll(PDO::FETCH_ASSOC);
$clients = $conn->prepare("SELECT id, client_name FROM co_clients WHERE company_id = ? ORDER BY client_name");
$clients->execute([$cid]);
$clients = $clients->fetchAll(PDO::FETCH_ASSOC);
$incomeAccounts = co_fetch_income_accounts($conn, $cid);
$selectedSource = $_POST['source_type'] ?? 'manual';
$selectedHeaderIncomeAccountId = (int)($_POST['income_account_id'] ?? 0);
if (!$selectedHeaderIncomeAccountId) {
    $selectedHeaderIncomeAccountId = co_default_income_account_id($conn, $cid, $selectedSource);
}

$pageTitle = 'Create Income Invoice';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4"><a href="client_invoices.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a><h1 class="h4 mb-0">Create Income Invoice</h1><p class="text-muted mb-0">Posts automatically to Accounts Receivable, income, and Output VAT.</p></div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if (!$hasIncomeColumns): ?><div class="alert alert-warning">Run <code>migrations/construction_income_workflow.sql</code> to enable income invoices.</div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card card-round">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Income Category *</label><select name="source_type" class="form-select" required><?php foreach (co_income_source_options() as $key => $label): ?><option value="<?= h($key) ?>" <?= $selectedSource === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Project (for construction/project income)</label><select name="project_id" id="project_id" class="form-select"><option value="0">— none —</option><?php foreach ($invoiceProjects as $p): ?><option value="<?= (int)$p['id'] ?>" data-client="<?= (int)($p['client_id'] ?? 0) ?>" <?= $project_id === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['project_code']) ?> — <?= h($p['project_name']) ?> (<?= h($p['project_type'] ?? 'OWNER') ?>)</option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Client / Tenant *</label><select name="client_id" class="form-select" required><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)($_POST['client_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['client_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Invoice Number</label><input type="text" name="invoice_number" class="form-control" value="<?= h($_POST['invoice_number'] ?? '') ?>" placeholder="Auto-generated if empty"></div>
            <div class="col-md-4"><label class="form-label">Invoice Date *</label><input type="date" name="invoice_date" class="form-control" required value="<?= h($_POST['invoice_date'] ?? date('Y-m-d')) ?>"></div>
            <div class="col-md-4"><label class="form-label">Due Date</label><input type="date" name="due_date" class="form-control" value="<?= h($_POST['due_date'] ?? date('Y-m-d')) ?>"></div>
            <div class="col-md-4">
                <label class="form-label d-block">Line Amount Mode *</label>
                <div class="d-flex gap-3 pt-1">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="vat_mode" id="vat_exclusive" value="exclusive" <?= $selectedVatMode === 'exclusive' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="vat_exclusive">VAT Exclusive</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="vat_mode" id="vat_inclusive" value="inclusive" <?= $selectedVatMode === 'inclusive' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="vat_inclusive">VAT Inclusive</label>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Default VAT %</label>
                <input type="number" step="0.01" min="0" name="vat_pct" id="vat_pct" class="form-control" value="<?= h((string) $selectedVatPct) ?>">
            </div>
            <input type="hidden" name="income_account_id" value="<?= (int)$selectedHeaderIncomeAccountId ?>">
            <div class="col-12"><label class="form-label">Description</label><input type="text" name="description" class="form-control" value="<?= h($_POST['description'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Attachments</label><input type="file" name="attachments[]" class="form-control" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.webp"></div>
        </div>
        <hr>
        <h6>Invoice Line Items</h6>
        <div class="table-responsive">
            <table class="table table-sm" id="invoiceLines">
                <thead class="table-light"><tr><th>Description</th><th>Income Account</th><th>Qty</th><th>Unit Amount</th><th>VAT %</th><th class="text-end">Line Total</th><th></th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary" id="addLine">Add Line</button>
        <div class="text-end mt-3">
            <div>Subtotal: <strong id="subtotalDisplay">0.00</strong> AED</div>
            <div>VAT: <strong id="vatDisplay">0.00</strong> AED</div>
            <div>Total: <strong id="totalDisplay">0.00</strong> AED</div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Create &amp; Post Invoice</button></div>
    </div>
</form>

<script>
(function () {
    var vatPct = document.getElementById('vat_pct');
    var modeExclusive = document.getElementById('vat_exclusive');
    var modeInclusive = document.getElementById('vat_inclusive');
    var incomeAccounts = <?= json_encode($incomeAccounts) ?>;
    var postedItems = <?= json_encode($_POST['items'] ?? []) ?>;
    var lineIdx = 0;

    function isInclusive() {
        return modeInclusive && modeInclusive.checked;
    }

    function round2(n) {
        return Math.round(n * 100) / 100;
    }
    function esc(v) {
        return String(v ?? '').replace(/[&<>"']/g, function(ch) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]; });
    }
    function defaultIncomeAccount() {
        var sel = document.querySelector('input[name="income_account_id"]');
        return sel ? sel.value : '';
    }
    function accountOptions(selected) {
        return '<option value="">-- Account --</option>' + incomeAccounts.map(function(a) {
            return '<option value="' + esc(a.id) + '" ' + (String(a.id) === String(selected || '') ? 'selected' : '') + '>' + esc(a.account_code + ' — ' + a.account_name) + '</option>';
        }).join('');
    }
    function addLine(data) {
        data = data || {};
        var tr = document.createElement('tr');
        var vatVal = data.vat_pct ?? (vatPct.value || 5);
        tr.innerHTML = '<td><input name="items[' + lineIdx + '][description]" class="form-control form-control-sm" value="' + esc(data.description || '') + '" required></td>'
            + '<td><select name="items[' + lineIdx + '][income_account_id]" class="form-select form-select-sm" required>' + accountOptions(data.income_account_id || defaultIncomeAccount()) + '</select></td>'
            + '<td><input type="number" step="0.01" name="items[' + lineIdx + '][quantity]" class="form-control form-control-sm qty" value="' + esc(data.quantity || 1) + '"></td>'
            + '<td><input type="number" step="0.01" name="items[' + lineIdx + '][unit_price]" class="form-control form-control-sm rate" value="' + esc(data.unit_price || 0) + '"></td>'
            + '<td><input type="number" step="0.01" name="items[' + lineIdx + '][vat_pct]" class="form-control form-control-sm vat" value="' + esc(vatVal) + '"></td>'
            + '<td class="text-end lineTotal">0.00</td><td><button type="button" class="btn btn-sm btn-danger rem">x</button></td>';
        document.querySelector('#invoiceLines tbody').appendChild(tr);
        lineIdx++;
        recalc();
    }

    function recalc() {
        var sub = 0, vat = 0, tot = 0;
        document.querySelectorAll('#invoiceLines tbody tr').forEach(function(tr) {
            var qty = parseFloat(tr.querySelector('.qty').value) || 0;
            var entered = parseFloat(tr.querySelector('.rate').value) || 0;
            var rate = parseFloat(tr.querySelector('.vat').value) || 0;
            var lineSubtotal = 0, lineVat = 0, lineTotal = 0;
            if (isInclusive()) {
                lineTotal = round2(qty * entered);
                lineVat = rate > 0 ? round2(lineTotal * rate / (100 + rate)) : 0;
                lineSubtotal = round2(lineTotal - lineVat);
            } else {
                lineSubtotal = round2(qty * entered);
                lineVat = rate > 0 ? round2(lineSubtotal * rate / 100) : 0;
                lineTotal = round2(lineSubtotal + lineVat);
            }
            tr.querySelector('.lineTotal').textContent = lineTotal.toFixed(2);
            sub += lineSubtotal;
            vat += lineVat;
            tot += lineTotal;
        });
        document.getElementById('subtotalDisplay').textContent = sub.toFixed(2);
        document.getElementById('vatDisplay').textContent = vat.toFixed(2);
        document.getElementById('totalDisplay').textContent = tot.toFixed(2);
    }

    vatPct.addEventListener('input', recalc);
    modeExclusive.addEventListener('change', recalc);
    modeInclusive.addEventListener('change', recalc);
    document.getElementById('addLine').onclick = function() { addLine({}); };
    document.getElementById('invoiceLines').addEventListener('input', recalc);
    document.getElementById('invoiceLines').addEventListener('change', recalc);
    document.getElementById('invoiceLines').addEventListener('click', function(e) { if (e.target.classList.contains('rem')) { e.target.closest('tr').remove(); recalc(); } });
    if (Array.isArray(postedItems) && postedItems.length) postedItems.forEach(addLine); else addLine({});
})();
</script>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
