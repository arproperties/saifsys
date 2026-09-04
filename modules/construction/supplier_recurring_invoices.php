<?php
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
$action = $_GET['action'] ?? '';
$sourceInvoiceId = (int)($_GET['source_invoice_id'] ?? $_POST['source_invoice_id'] ?? 0);
$success = '';
$error = '';
co_supplier_invoice_full_schema($conn);
co_generate_due_recurring_supplier_invoices($conn, $cid, $userId);

$suppliers = $conn->prepare("SELECT id, supplier_name FROM co_suppliers WHERE company_id = ? AND is_active = 1 ORDER BY supplier_name");
$suppliers->execute([$cid]);
$suppliers = $suppliers->fetchAll(PDO::FETCH_ASSOC) ?: [];
$projects = $conn->prepare("SELECT id, project_code, project_name, project_type FROM co_projects WHERE company_id = ? AND status IN ('draft','active') ORDER BY project_name");
$projects->execute([$cid]);
$projects = $projects->fetchAll(PDO::FETCH_ASSOC) ?: [];
$expenseAccounts = co_fetch_expense_accounts($conn, $cid);
$expenseAccountByCode = [];
foreach ($expenseAccounts as $account) $expenseAccountByCode[$account['account_code']] = (int)$account['id'];
$defaultExpenseAccountId = $expenseAccountByCode['5125'] ?? ($expenseAccounts[0]['id'] ?? 0);

$sourceInvoice = null;
$sourceLines = [];
if ($sourceInvoiceId > 0) {
    $src = $conn->prepare("SELECT * FROM co_supplier_invoices WHERE id = ? AND company_id = ? LIMIT 1");
    $src->execute([$sourceInvoiceId, $cid]);
    $sourceInvoice = $src->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($sourceInvoice) $sourceLines = co_supplier_invoice_lines($conn, $cid, $sourceInvoiceId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        $formAction = $_POST['form_action'] ?? 'create';
        $templateId = (int)($_POST['template_id'] ?? 0);
        if (in_array($formAction, ['stop','resume','delete'], true)) {
            if ($templateId <= 0) throw new RuntimeException('Recurring template not found.');
            if ($formAction === 'delete') {
                $conn->prepare("DELETE FROM co_supplier_recurring_invoices WHERE id = ? AND company_id = ?")->execute([$templateId, $cid]);
                $success = 'Recurring invoice deleted.';
            } else {
                $newStatus = $formAction === 'stop' ? 'paused' : 'active';
                $conn->prepare("UPDATE co_supplier_recurring_invoices SET status = ? WHERE id = ? AND company_id = ?")->execute([$newStatus, $templateId, $cid]);
                $success = $formAction === 'stop' ? 'Recurring invoice stopped.' : 'Recurring invoice resumed.';
            }
        } else {
            $supplierId = (int)($_POST['supplier_id'] ?? 0);
            $templateName = trim((string)($_POST['template_name'] ?? ''));
            $frequency = (string)($_POST['frequency'] ?? 'monthly');
            $nextDate = (string)($_POST['next_invoice_date'] ?? '');
            $endDate = ($_POST['end_date'] ?? '') !== '' ? (string)$_POST['end_date'] : null;
            $dueDays = max(0, (int)($_POST['due_days'] ?? 0));
            $invoicePrefix = trim((string)($_POST['invoice_number_prefix'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $reference = trim((string)($_POST['reference'] ?? ''));
            $defaultProjectId = (int)($_POST['project_id'] ?? 0) ?: null;
            $defaultAccountId = (int)($_POST['expense_account_id'] ?? 0) ?: $defaultExpenseAccountId;
            $defaultVatPct = (float)($_POST['vat_pct'] ?? 5);
            $lineData = co_parse_supplier_invoice_items($_POST['items'] ?? [], $defaultProjectId, $defaultAccountId, $defaultVatPct);
            if (!$supplierId || $templateName === '' || $nextDate === '') throw new RuntimeException('Supplier, template name and next invoice date are required.');
            if (!in_array($frequency, ['weekly','monthly','quarterly','semi_annual','annual'], true)) $frequency = 'monthly';
            if (!$lineData['lines']) throw new RuntimeException('Add at least one valid line.');
            $dup = $conn->prepare("SELECT id FROM co_supplier_recurring_invoices WHERE company_id = ? AND status <> 'cancelled' AND supplier_id = ? AND template_name = ? LIMIT 1");
            $dup->execute([$cid, $supplierId, $templateName]);
            if ($existing = $dup->fetchColumn()) throw new RuntimeException('A recurring template already exists for this supplier/template (#' . $existing . ').');
            $ins = $conn->prepare("
                INSERT INTO co_supplier_recurring_invoices
                    (company_id, supplier_id, source_invoice_id, template_name, invoice_number_prefix, frequency, next_invoice_date, end_date, due_days, description, reference, subtotal, vat_amount, total, lines_json, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)
            ");
            $ins->execute([$cid, $supplierId, $sourceInvoiceId ?: null, $templateName, $invoicePrefix ?: null, $frequency, $nextDate, $endDate, $dueDays, $description ?: null, $reference ?: null, $lineData['subtotal'], $lineData['vat_amount'], $lineData['total'], json_encode($lineData['lines'], JSON_UNESCAPED_UNICODE), $userId]);
            $success = 'Recurring supplier invoice created.';
            $action = '';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if (($_POST['form_action'] ?? 'create') === 'create') $action = 'create';
    }
}

$rows = $conn->prepare("
    SELECT r.*, s.supplier_name, src.invoice_number AS source_invoice_number
    FROM co_supplier_recurring_invoices r
    JOIN co_suppliers s ON s.id = r.supplier_id AND s.company_id = r.company_id
    LEFT JOIN co_supplier_invoices src ON src.id = r.source_invoice_id AND src.company_id = r.company_id
    WHERE r.company_id = ?
    ORDER BY r.next_invoice_date ASC, r.id DESC
");
$rows->execute([$cid]);
$rows = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = 'Recurring Supplier Invoices';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div><a href="supplier_invoices.php" class="btn btn-outline-secondary btn-sm mb-2">Back</a><h1 class="h4 mb-0">Recurring Supplier Invoices</h1></div>
    <a href="supplier_recurring_invoices.php?action=create" class="btn btn-primary">New Recurring Invoice</a>
</div>
<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<?php if ($action === 'create'): ?>
<form method="post" class="card card-round mb-4">
    <div class="card-header bg-white">Create Recurring Supplier Invoice</div>
    <div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="form_action" value="create"><input type="hidden" name="source_invoice_id" value="<?= (int)$sourceInvoiceId ?>">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Supplier *</label><select name="supplier_id" class="form-select" required><option value="">-- Select --</option><?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>" <?= ($sourceInvoice && (int)$sourceInvoice['supplier_id'] === (int)$s['id']) ? 'selected' : '' ?>><?= h($s['supplier_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Template Name *</label><input name="template_name" class="form-control" value="<?= h($sourceInvoice ? 'Recurring - ' . $sourceInvoice['invoice_number'] : '') ?>" required></div>
            <div class="col-md-2"><label class="form-label">Frequency</label><select name="frequency" class="form-select"><option value="weekly">Weekly</option><option value="monthly" selected>Monthly</option><option value="quarterly">Quarterly</option><option value="semi_annual">Semi Annual</option><option value="annual">Annual</option></select></div>
            <div class="col-md-2"><label class="form-label">Due After Days</label><input type="number" min="0" name="due_days" class="form-control" value="0"></div>
            <div class="col-md-3"><label class="form-label">Next Invoice Date *</label><input type="date" name="next_invoice_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-3"><label class="form-label">End Date</label><input type="date" name="end_date" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Invoice # Prefix</label><input name="invoice_number_prefix" class="form-control" value="<?= h($sourceInvoice['invoice_number'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Default VAT %</label><input type="number" step="0.01" name="vat_pct" id="vat_pct" class="form-control" value="5"></div>
            <div class="col-md-6"><label class="form-label">Default Project</label><select name="project_id" id="projectSelect" class="form-select"><option value="">-</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['project_code'].' — '.$p['project_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">Default Expense Account</label><select name="expense_account_id" id="expenseAccountSelect" class="form-select"><?php foreach ($expenseAccounts as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $defaultExpenseAccountId === (int)$a['id'] ? 'selected' : '' ?>><?= h($a['account_code'].' — '.$a['account_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">Description</label><input name="description" class="form-control" value="<?= h($sourceInvoice['description'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Reference</label><input name="reference" class="form-control" value="<?= h($sourceInvoice['reference'] ?? '') ?>"></div>
        </div>
        <hr><h6>Line Items</h6>
        <div class="table-responsive"><table class="table table-sm" id="invoiceLines"><thead class="table-light"><tr><th>Description</th><th>Project</th><th>Expense Account</th><th>Qty</th><th>Rate</th><th>VAT %</th><th class="text-end">Total</th><th></th></tr></thead><tbody></tbody></table></div>
        <button type="button" id="addLine" class="btn btn-sm btn-outline-primary">Add Line</button>
        <div class="text-end mt-3"><div>Subtotal: <strong id="subtotalDisplay">0.00</strong></div><div>VAT: <strong id="vatDisplay">0.00</strong></div><div>Total: <strong id="totalDisplay">0.00</strong></div></div>
        <hr><button class="btn btn-success">Create Recurring Invoice</button>
    </div>
</form>
<script>
const projects=<?=json_encode($projects)?>, accounts=<?=json_encode($expenseAccounts)?>, sourceLines=<?=json_encode($sourceLines)?>;let idx=0;
function esc(v){return String(v??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));}
function opts(list,sel,label){return '<option value="">-</option>'+list.map(x=>`<option value="${esc(x.id)}" ${String(x.id)===String(sel||'')?'selected':''}>${esc(label(x))}</option>`).join('')}
function addLine(d={}){const tr=document.createElement('tr');let vat=d.vat_pct??(document.getElementById('vat_pct').value||5);tr.innerHTML=`<td><input name="items[${idx}][description]" class="form-control form-control-sm" value="${esc(d.description||'')}" required></td><td><select name="items[${idx}][project_id]" class="form-select form-select-sm">${opts(projects,d.project_id,p=>p.project_code+' — '+p.project_name)}</select></td><td><select name="items[${idx}][expense_account_id]" class="form-select form-select-sm" required>${opts(accounts,d.expense_account_id||document.getElementById('expenseAccountSelect').value,a=>a.account_code+' — '+a.account_name)}</select></td><td><input type="number" step="0.01" name="items[${idx}][quantity]" class="form-control form-control-sm qty" value="${esc(d.quantity||1)}"></td><td><input type="number" step="0.01" name="items[${idx}][unit_price]" class="form-control form-control-sm rate" value="${esc(d.unit_price||0)}"></td><td><input type="number" step="0.01" name="items[${idx}][vat_pct]" class="form-control form-control-sm vat" value="${esc(vat)}"></td><td class="text-end lineTotal">0.00</td><td><button type="button" class="btn btn-sm btn-danger rem">x</button></td>`;document.querySelector('#invoiceLines tbody').appendChild(tr);idx++;calc();}
function calc(){let sub=0,vat=0;document.querySelectorAll('#invoiceLines tbody tr').forEach(tr=>{let q=parseFloat(tr.querySelector('.qty').value)||0,r=parseFloat(tr.querySelector('.rate').value)||0,p=parseFloat(tr.querySelector('.vat').value)||0,s=q*r,v=s*p/100;tr.querySelector('.lineTotal').textContent=(s+v).toFixed(2);sub+=s;vat+=v;});document.getElementById('subtotalDisplay').textContent=sub.toFixed(2);document.getElementById('vatDisplay').textContent=vat.toFixed(2);document.getElementById('totalDisplay').textContent=(sub+vat).toFixed(2)}
document.getElementById('addLine').onclick=()=>addLine();document.getElementById('invoiceLines').addEventListener('input',calc);document.getElementById('invoiceLines').addEventListener('change',calc);document.getElementById('invoiceLines').addEventListener('click',e=>{if(e.target.classList.contains('rem')){e.target.closest('tr').remove();calc();}});if(sourceLines.length)sourceLines.forEach(addLine);else addLine();
</script>
<?php endif; ?>

<div class="card card-round"><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead class="table-light"><tr><th>Template</th><th>Supplier</th><th>Source</th><th>Frequency</th><th>Next Date</th><th>Status</th><th class="text-end">Amount</th><th>Actions</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td><?= h($r['template_name']) ?></td><td><?= h($r['supplier_name']) ?></td><td><?= !empty($r['source_invoice_id']) ? '<a href="supplier_invoice_view.php?id='.(int)$r['source_invoice_id'].'">'.h($r['source_invoice_number']).'</a>' : '-' ?></td><td><?= h(ucwords(str_replace('_',' ', $r['frequency']))) ?></td><td><?= h($r['next_invoice_date'] ?: '-') ?></td><td><span class="badge bg-<?= $r['status']==='active'?'success':'secondary' ?>"><?= h($r['status']) ?></span></td><td class="text-end"><?= co_format_money($r['total']) ?></td><td><div class="d-flex gap-1"><?php if($r['status']==='active'): ?><form method="post"><?php csrf_field(); ?><input type="hidden" name="form_action" value="stop"><input type="hidden" name="template_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-warning">Stop</button></form><?php else: ?><form method="post"><?php csrf_field(); ?><input type="hidden" name="form_action" value="resume"><input type="hidden" name="template_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-success">Resume</button></form><?php endif; ?><form method="post" onsubmit="return confirm('Delete this recurring invoice?');"><?php csrf_field(); ?><input type="hidden" name="form_action" value="delete"><input type="hidden" name="template_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></div></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No recurring supplier invoices yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
