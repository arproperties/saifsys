<?php
/**
 * Construction Module — Edit Income Invoice
 * Reverses the invoice's posted journal and re-posts it with the edited figures.
 * Invoices generated from a contract schedule (source_id set) are edited at their source.
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
$userId = (int)(current_user_id() ?: 0);
$id = (int)($_GET['id'] ?? 0);
$err = '';
$hasIncomeColumns = co_client_invoice_columns_ready($conn);
co_client_invoice_full_schema($conn);
$defaultVatPct = 5.0;

if (!$id) { header('Location: client_invoices.php'); exit; }

$stmt = $conn->prepare("SELECT * FROM co_client_invoices WHERE id = ? AND company_id = ?");
$stmt->execute([$id, $cid]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$invoice) { header('Location: client_invoices.php'); exit; }

$paidAmount = 0.0;
if (co_client_allocations_ready($conn)) {
    $paidStmt = $conn->prepare("SELECT COALESCE(SUM(allocated_amount), 0) FROM co_client_payment_allocations WHERE company_id = ? AND invoice_id = ?");
    $paidStmt->execute([$cid, $id]);
    $paidAmount = (float)$paidStmt->fetchColumn();
}

$lockReason = '';
if (!$hasIncomeColumns) {
    $lockReason = 'Run migrations/construction_income_workflow.sql before editing income invoices.';
} elseif (($invoice['status'] ?? '') === 'cancelled') {
    $lockReason = 'Cancelled invoices cannot be edited.';
} elseif (!empty($invoice['source_id'])) {
    $lockReason = 'This invoice was generated from a contract schedule. Change it from its contract, not here.';
}

// Existing lines; legacy invoices without lines get one line from the header.
$existingLines = co_client_invoice_lines($conn, $cid, $id);
$storedVatMode = 'exclusive';
foreach ($existingLines as $l) {
    if ((float)$l['vat_amount'] > 0.005 && abs(round((float)$l['quantity'] * (float)$l['unit_price'], 2) - (float)$l['line_total']) < 0.01) {
        $storedVatMode = 'inclusive';
        break;
    }
}
if (!$existingLines) {
    $sub = (float)($invoice['subtotal'] ?? 0) ?: max(0, (float)$invoice['total_amount'] - (float)$invoice['vat_amount']);
    $existingLines = [[
        'description' => $invoice['description'] ?: 'Income invoice line',
        'income_account_id' => $invoice['income_account_id'],
        'quantity' => 1,
        'unit_price' => $sub,
        'vat_pct' => $sub > 0 ? round((float)$invoice['vat_amount'] / $sub * 100, 2) : 0,
    ]];
}

$selectedVatMode = $storedVatMode;
$selectedVatPct = $existingLines ? (float)$existingLines[0]['vat_pct'] : $defaultVatPct;
$formItems = array_map(static function ($l) {
    return [
        'description' => $l['description'],
        'income_account_id' => $l['income_account_id'],
        'quantity' => (float)$l['quantity'],
        'unit_price' => (float)$l['unit_price'],
        'vat_pct' => (float)$l['vat_pct'],
    ];
}, $existingLines);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$lockReason) {
    csrf_verify();
    $project_id = (int)($_POST['project_id'] ?? 0);
    $client_id = (int)($_POST['client_id'] ?? 0);
    $source_type = $_POST['source_type'] ?? 'manual';
    $invoice_date = $_POST['invoice_date'] ?? $invoice['invoice_date'];
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

    $selectedVatMode = $vat_mode;
    $selectedVatPct = $vat_pct;
    $formItems = $_POST['items'] ?? [];

    if (!array_key_exists($source_type, co_income_source_options())) {
        $err = 'Choose a valid income category.';
    } elseif (!$client_id) {
        $err = 'Client is required.';
    } elseif ($paidAmount > 0.005 && $client_id !== (int)$invoice['client_id']) {
        $err = 'Receipts are already allocated to this invoice, so the client cannot be changed.';
    } elseif ($source_type === 'construction_project' && !$project_id) {
        $err = 'Choose a project for construction project invoices.';
    } elseif (!$lineData['lines']) {
        $err = 'Add at least one valid invoice line.';
    } elseif ($lineData['total'] + 0.005 < $paidAmount) {
        $err = 'New total (' . number_format($lineData['total'], 2) . ') is below the amount already received (' . number_format($paidAmount, 2) . ').';
    } elseif ($invoice_number === '') {
        $err = 'Invoice number is required.';
    } elseif (!$income_account_id) {
        $err = 'Choose an income account or run Setup Accounts to create Construction income accounts.';
    }
    if (!$err) {
        $chk = $conn->prepare("SELECT 1 FROM co_client_invoices WHERE company_id = ? AND invoice_number = ? AND id <> ?");
        $chk->execute([$cid, $invoice_number, $id]);
        if ($chk->fetch()) $err = 'Invoice number already exists.';
    }
    if (!$err) {
        $conn->beginTransaction();
        try {
            $oldJournalId = (int)($invoice['journal_id'] ?? 0);
            if ($oldJournalId > 0) {
                $reverseResult = reverse_journal($oldJournalId, 'Income invoice edited', $userId);
                if (!$reverseResult['success']) {
                    throw new RuntimeException('Could not reverse the existing journal: ' . ($reverseResult['error'] ?? 'unknown error'));
                }
            }

            $conn->prepare("
                UPDATE co_client_invoices
                SET project_id = ?, client_id = ?, source_type = ?, invoice_number = ?, invoice_date = ?, due_date = ?,
                    subtotal = ?, vat_amount = ?, total_amount = ?, description = ?, income_account_id = ?, journal_id = NULL
                WHERE id = ? AND company_id = ?
            ")->execute([
                $project_id ?: null, $client_id, $source_type, $invoice_number, $invoice_date, $due_date ?: $invoice_date,
                $lineData['subtotal'], $lineData['vat_amount'], $lineData['total'], $description ?: null, $income_account_id ?: null,
                $id, $cid,
            ]);

            $conn->prepare("DELETE FROM co_client_invoice_lines WHERE company_id = ? AND invoice_id = ?")->execute([$cid, $id]);
            co_insert_client_invoice_lines($conn, $cid, $id, $lineData['lines']);

            $postResult = co_post_client_invoice_to_accounting($id, $cid, $userId);
            if (!$postResult['success'] || empty($postResult['journal_id'])) {
                throw new RuntimeException($postResult['error'] ?? 'Invoice posting failed.');
            }
            $conn->prepare("UPDATE co_client_invoices SET journal_id = ? WHERE id = ? AND company_id = ?")
                ->execute([$postResult['journal_id'], $id, $cid]);
            co_update_client_invoice_status($conn, $cid, $id);

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
    WHERE company_id = ? AND (status IN ('draft', 'active', 'on_hold') OR id = ?)
    ORDER BY project_name
");
$invoiceProjects->execute([$cid, (int)($invoice['project_id'] ?? 0)]);
$invoiceProjects = $invoiceProjects->fetchAll(PDO::FETCH_ASSOC);
$clients = $conn->prepare("SELECT id, client_name FROM co_clients WHERE company_id = ? ORDER BY client_name");
$clients->execute([$cid]);
$clients = $clients->fetchAll(PDO::FETCH_ASSOC);
$incomeAccounts = co_fetch_income_accounts($conn, $cid);

$val = static function (string $key, $fallback) {
    return $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST[$key] ?? $fallback) : $fallback;
};
$selectedSource = $val('source_type', $invoice['source_type'] ?? 'manual');
$selectedProjectId = (int)$val('project_id', $invoice['project_id'] ?? 0);
$selectedClientId = (int)$val('client_id', $invoice['client_id']);
$selectedHeaderIncomeAccountId = (int)$val('income_account_id', $invoice['income_account_id'] ?? 0);
if (!$selectedHeaderIncomeAccountId) {
    $selectedHeaderIncomeAccountId = co_default_income_account_id($conn, $cid, $selectedSource);
}
$clientLocked = $paidAmount > 0.005;

$pageTitle = 'Edit Income Invoice';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4"><a href="client_invoices.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a><h1 class="h4 mb-0">Edit Income Invoice <?= h($invoice['invoice_number']) ?></h1><p class="text-muted mb-0">Saving reverses the current journal and posts a new one with the edited figures.</p></div>
<?php if ($lockReason): ?><div class="alert alert-warning"><?= h($lockReason) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if ($paidAmount > 0.005): ?><div class="alert alert-info">AED <?= h(number_format($paidAmount, 2)) ?> has already been received against this invoice. The client cannot be changed and the total cannot go below that amount.</div><?php endif; ?>

<form method="post" class="card card-round">
    <?php csrf_field(); ?>
    <fieldset class="card-body" <?= $lockReason ? 'disabled' : '' ?>>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Income Category *</label><select name="source_type" class="form-select" required><?php foreach (co_income_source_options() as $key => $label): ?><option value="<?= h($key) ?>" <?= $selectedSource === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Project (for construction/project income)</label><select name="project_id" id="project_id" class="form-select"><option value="0">— none —</option><?php foreach ($invoiceProjects as $p): ?><option value="<?= (int)$p['id'] ?>" data-client="<?= (int)($p['client_id'] ?? 0) ?>" <?= $selectedProjectId === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['project_code']) ?> — <?= h($p['project_name']) ?> (<?= h($p['project_type'] ?? 'OWNER') ?>)</option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Client / Tenant *</label>
                <select name="client_id" class="form-select" required <?= $clientLocked ? 'disabled' : '' ?>><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $selectedClientId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['client_name']) ?></option><?php endforeach; ?></select>
                <?php if ($clientLocked): ?><input type="hidden" name="client_id" value="<?= (int)$invoice['client_id'] ?>"><?php endif; ?>
            </div>
            <div class="col-md-4"><label class="form-label">Invoice Number *</label><input type="text" name="invoice_number" class="form-control" required value="<?= h($val('invoice_number', $invoice['invoice_number'])) ?>"></div>
            <div class="col-md-4"><label class="form-label">Invoice Date *</label><input type="date" name="invoice_date" class="form-control" required value="<?= h($val('invoice_date', $invoice['invoice_date'])) ?>"></div>
            <div class="col-md-4"><label class="form-label">Due Date</label><input type="date" name="due_date" class="form-control" value="<?= h($val('due_date', $invoice['due_date'] ?? $invoice['invoice_date'])) ?>"></div>
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
            <div class="col-12"><label class="form-label">Description</label><input type="text" name="description" class="form-control" value="<?= h($val('description', $invoice['description'] ?? '')) ?>"></div>
            <div class="col-12 form-text">Attachments are managed on the invoice's <a href="client_invoice_documents.php?invoice_id=<?= (int)$id ?>">Docs</a> page.</div>
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
        <div class="mt-3"><button type="submit" class="btn btn-primary">Update &amp; Re-post Invoice</button></div>
    </fieldset>
</form>

<script>
(function () {
    var vatPct = document.getElementById('vat_pct');
    var modeExclusive = document.getElementById('vat_exclusive');
    var modeInclusive = document.getElementById('vat_inclusive');
    var incomeAccounts = <?= json_encode($incomeAccounts) ?>;
    var existingItems = <?= json_encode(array_values($formItems)) ?>;
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
    if (Array.isArray(existingItems) && existingItems.length) existingItems.forEach(addLine); else addLine({});
})();
</script>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
