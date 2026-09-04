<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/vendor_ap_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 1;
$userId = current_user_id();
$success = '';
$error = '';
$missing = false;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function m($n) { return number_format((float)$n, 2); }

function re_recurring_bill_table_exists(PDO $conn): bool {
    $stmt = $conn->prepare("SHOW TABLES LIKE 're_vendor_recurring_bills'");
    $stmt->execute();
    return (bool)$stmt->fetchColumn();
}

function re_recurring_bill_column_exists(PDO $conn, string $column): bool {
    $stmt = $conn->prepare("SHOW COLUMNS FROM re_vendor_recurring_bills LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function re_recurring_bill_add_column(PDO $conn, string $column, string $definition): void {
    if (!re_recurring_bill_column_exists($conn, $column)) {
        $conn->exec("ALTER TABLE re_vendor_recurring_bills ADD COLUMN `$column` $definition");
    }
}

function re_recurring_bill_frequency_supports_weekly(PDO $conn): bool {
    $stmt = $conn->prepare("SHOW COLUMNS FROM re_vendor_recurring_bills LIKE 'frequency'");
    $stmt->execute();
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    return $column && strpos((string)$column['Type'], "'weekly'") !== false;
}

function re_recurring_bill_ensure_schema(PDO $conn): void {
    if (!re_recurring_bill_table_exists($conn)) return;
    if (!re_recurring_bill_frequency_supports_weekly($conn)) {
        $conn->exec("ALTER TABLE re_vendor_recurring_bills MODIFY COLUMN frequency ENUM('weekly','monthly','quarterly','semi_annual','annual') NOT NULL DEFAULT 'monthly'");
    }
    re_recurring_bill_add_column($conn, 'invoice_number_prefix', "VARCHAR(100) DEFAULT NULL AFTER `template_name`");
    re_recurring_bill_add_column($conn, 'due_days', "INT(11) NOT NULL DEFAULT 0 AFTER `end_date`");
    re_recurring_bill_add_column($conn, 'payment_terms', "VARCHAR(100) DEFAULT NULL AFTER `due_days`");
    re_recurring_bill_add_column($conn, 'place_of_supply', "VARCHAR(100) DEFAULT 'Dubai' AFTER `payment_terms`");
    re_recurring_bill_add_column($conn, 'vat_treatment', "ENUM('vat_registered','non_vat','exempt','out_of_scope') NOT NULL DEFAULT 'vat_registered' AFTER `place_of_supply`");
    re_recurring_bill_add_column($conn, 'order_number', "VARCHAR(100) DEFAULT NULL AFTER `vat_treatment`");
    re_recurring_bill_add_column($conn, 'permit_number', "VARCHAR(100) DEFAULT NULL AFTER `order_number`");
    re_recurring_bill_add_column($conn, 'notes', "TEXT DEFAULT NULL AFTER `permit_number`");
    re_recurring_bill_add_column($conn, 'subtotal', "DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `notes`");
    re_recurring_bill_add_column($conn, 'tax_amount', "DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `subtotal`");
    re_recurring_bill_add_column($conn, 'total_amount', "DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `tax_amount`");
    re_recurring_bill_add_column($conn, 'lines_json', "LONGTEXT DEFAULT NULL AFTER `total_amount`");
}

function re_recurring_bill_parse_lines(array $items): array {
    $valid = [];
    $subtotal = 0.0;
    $vat = 0.0;
    $total = 0.0;
    foreach ($items as $it) {
        $acct = (int)($it['expense_account_id'] ?? 0);
        $desc = trim((string)($it['description'] ?? ''));
        $qty = (float)($it['quantity'] ?? 1);
        $rate = (float)($it['unit_price'] ?? 0);
        $vatRate = (float)($it['vat_rate'] ?? 0);
        $vatTreatment = (string)($it['vat_treatment'] ?? 'standard');
        if (!in_array($vatTreatment, ['standard', 'exempt', 'zero_rated', 'out_of_scope'], true)) {
            $vatTreatment = 'standard';
        }
        if ($acct <= 0 || $qty <= 0 || $rate < 0) continue;
        if ($vatTreatment !== 'standard') $vatRate = 0;
        $lineSub = round($qty * $rate, 2);
        $lineVat = ($vatTreatment === 'standard' && $vatRate > 0) ? round($lineSub * $vatRate / 100, 2) : 0.0;
        $lineTotal = $lineSub + $lineVat;
        $valid[] = [
            'expense_account_id' => $acct,
            'description' => $desc,
            'quantity' => $qty,
            'unit_price' => $rate,
            'subtotal' => $lineSub,
            'vat_treatment' => $vatTreatment,
            'vat_rate' => $vatRate,
            'vat_amount' => $lineVat,
            'line_total' => $lineTotal,
            'building_id' => (int)($it['building_id'] ?? 0),
            'unit_id' => (int)($it['unit_id'] ?? 0),
            'lease_id' => (int)($it['lease_id'] ?? 0),
        ];
        $subtotal += $lineSub;
        $vat += $lineVat;
        $total += $lineTotal;
    }
    return ['lines' => $valid, 'subtotal' => round($subtotal, 2), 'tax_amount' => round($vat, 2), 'total_amount' => round($total, 2)];
}

try {
    re_recurring_bill_ensure_schema($conn);
} catch (Throwable $e) {
    $missing = true;
}

$action = $_GET['action'] ?? '';
$sourceBillId = (int)($_GET['source_bill_id'] ?? $_POST['source_bill_id'] ?? 0);
$sourceBill = $sourceBillId ? re_ap_load_bill($conn, $companyId, $sourceBillId) : null;
if ($sourceBillId && !$sourceBill) $sourceBillId = 0;
$sourceLines = [];
if ($sourceBill) {
    try {
        $linesStmt = $conn->prepare("SELECT * FROM re_vendor_invoice_items WHERE company_id=? AND invoice_id=? ORDER BY id");
        $linesStmt->execute([$companyId, $sourceBillId]);
        $sourceLines = $linesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $sourceLines = [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        $formAction = $_POST['form_action'] ?? 'create_template';
        $templateId = (int)($_POST['template_id'] ?? 0);

        if (in_array($formAction, ['stop', 'resume', 'delete'], true)) {
            if ($templateId <= 0) throw new RuntimeException('Recurring template was not found.');
            if ($formAction === 'delete') {
                $stmt = $conn->prepare("DELETE FROM re_vendor_recurring_bills WHERE id=? AND company_id=?");
                $stmt->execute([$templateId, $companyId]);
                $success = 'Recurring bill template deleted.';
            } else {
                $newStatus = $formAction === 'stop' ? 'paused' : 'active';
                $stmt = $conn->prepare("UPDATE re_vendor_recurring_bills SET status=? WHERE id=? AND company_id=?");
                $stmt->execute([$newStatus, $templateId, $companyId]);
                $success = $formAction === 'stop' ? 'Recurring bill template stopped.' : 'Recurring bill template resumed.';
            }
            $action = '';
        } else {
            $vendorId = (int)($_POST['vendor_id'] ?? 0);
            $template = trim((string)($_POST['template_name'] ?? ''));
            $frequency = (string)($_POST['frequency'] ?? 'monthly');
            $next = (string)($_POST['next_bill_date'] ?? '');
            $end = ($_POST['end_date'] ?? '') !== '' ? (string)$_POST['end_date'] : null;
            $status = (string)($_POST['status'] ?? 'active');
            $invoicePrefix = trim((string)($_POST['invoice_number_prefix'] ?? ''));
            $dueDays = max(0, (int)($_POST['due_days'] ?? 0));
            $paymentTerms = trim((string)($_POST['payment_terms'] ?? ''));
            $place = trim((string)($_POST['place_of_supply'] ?? 'Dubai'));
            $vatTreatment = (string)($_POST['vat_treatment'] ?? 'vat_registered');
            $orderNo = trim((string)($_POST['order_number'] ?? ''));
            $permitNo = trim((string)($_POST['permit_number'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $lineData = re_recurring_bill_parse_lines($_POST['items'] ?? []);

            if (!$vendorId || $template === '' || $next === '') throw new RuntimeException('Vendor, template name and next bill date are required.');
            if (!$lineData['lines']) throw new RuntimeException('Add at least one valid line with an expense account.');
            if (!in_array($frequency, ['weekly', 'monthly', 'quarterly', 'semi_annual', 'annual'], true)) $frequency = 'monthly';
            if (!in_array($status, ['active', 'paused'], true)) $status = 'active';
            if (!in_array($vatTreatment, ['vat_registered', 'non_vat', 'exempt', 'out_of_scope'], true)) $vatTreatment = 'vat_registered';

            $dup = $conn->prepare("SELECT id FROM re_vendor_recurring_bills WHERE company_id=? AND status<>'cancelled' AND ((vendor_invoice_id IS NOT NULL AND vendor_invoice_id=?) OR (vendor_id=? AND template_name=?)) LIMIT 1");
            $dup->execute([$companyId, $sourceBillId ?: 0, $vendorId, $template]);
            if ($existing = $dup->fetchColumn()) throw new RuntimeException('A recurring template already exists for this bill/vendor template (#' . $existing . ').');

            $stmt = $conn->prepare("
                INSERT INTO re_vendor_recurring_bills (
                    company_id, vendor_invoice_id, vendor_id, template_name, invoice_number_prefix, frequency,
                    next_bill_date, end_date, due_days, payment_terms, place_of_supply, vat_treatment,
                    order_number, permit_number, notes, subtotal, tax_amount, total_amount, lines_json,
                    status, created_by
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $companyId,
                $sourceBillId ?: null,
                $vendorId,
                $template,
                $invoicePrefix ?: null,
                $frequency,
                $next,
                $end,
                $dueDays,
                $paymentTerms ?: null,
                $place ?: 'Dubai',
                $vatTreatment,
                $orderNo ?: null,
                $permitNo ?: null,
                $notes ?: null,
                $lineData['subtotal'],
                $lineData['tax_amount'],
                $lineData['total_amount'],
                json_encode($lineData['lines'], JSON_UNESCAPED_UNICODE),
                $status,
                $userId
            ]);
            re_ap_audit($conn, $companyId, $vendorId, $sourceBillId ?: null, null, 'recurring_template_created', null, $frequency, (float)$lineData['total_amount'], 'Recurring bill template created', $userId, 'vendor_recurring_bills');
            $success = 'Recurring bill template created.';
            $action = '';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if (($_POST['form_action'] ?? 'create_template') === 'create_template') $action = 'create';
    }
}

$recurringRun = re_ap_generate_due_recurring_bills($conn, $companyId, $userId);
if (!empty($recurringRun['errors'])) {
    error_log('Recurring vendor bill generation warning: ' . implode(' | ', array_slice($recurringRun['errors'], 0, 3)));
}

$vendors = [];
$accounts = [];
$buildings = [];
$leases = [];
try {
    $v = $conn->prepare("SELECT id,vendor_name,tax_id FROM re_vendors WHERE company_id=? AND status='active' ORDER BY vendor_name");
    $v->execute([$companyId]);
    $vendors = $v->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $accountsStmt = $conn->prepare("SELECT id,account_code,account_name,account_type FROM re_chart_of_accounts WHERE company_id=? AND is_active=1 AND is_header=0 AND account_type IN('Expense','Asset') ORDER BY account_code");
    $accountsStmt->execute([$companyId]);
    $accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $buildingsStmt = $conn->prepare("SELECT id,name FROM re_buildings WHERE company_id=? AND is_active=1 ORDER BY name");
    $buildingsStmt->execute([$companyId]);
    $buildings = $buildingsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $leasesStmt = $conn->prepare("SELECT l.id,l.lease_number,u.unit_number,b.name building_name FROM re_leases l JOIN re_units u ON u.id=l.unit_id JOIN re_buildings b ON b.id=u.building_id WHERE l.company_id=? ORDER BY l.id DESC LIMIT 250");
    $leasesStmt->execute([$companyId]);
    $leases = $leasesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$rows = [];
try {
    $stmt = $conn->prepare("
        SELECT rb.*, v.vendor_name, vi.invoice_number AS source_invoice_number,
               CASE WHEN COALESCE(rb.total_amount,0) > 0 THEN rb.total_amount ELSE COALESCE(vi.total_amount,0) END AS display_amount
        FROM re_vendor_recurring_bills rb
        JOIN re_vendors v ON v.id=rb.vendor_id AND v.company_id=rb.company_id
        LEFT JOIN re_vendor_invoices vi ON vi.id=rb.vendor_invoice_id AND vi.company_id=rb.company_id
        WHERE rb.company_id=?
        ORDER BY rb.next_bill_date ASC, rb.id DESC
    ");
    $stmt->execute([$companyId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $missing = true;
}

$defaultDueDays = 0;
if ($sourceBill && !empty($sourceBill['invoice_date']) && !empty($sourceBill['due_date'])) {
    $defaultDueDays = max(0, (int)round((strtotime($sourceBill['due_date']) - strtotime($sourceBill['invoice_date'])) / 86400));
}
$defaultNextDate = $sourceBill ? date('Y-m-d', strtotime('+1 month', strtotime($sourceBill['invoice_date']))) : date('Y-m-d');
$pageTitle = 'Recurring Vendor Bills';
require_once __DIR__ . '/../includes/re_layout_header.php'; ?>
<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label"><i class="bi bi-arrow-repeat"></i> Recurring Vendor Bills</div>
    <div>
        <a href="vendor_bills.php" class="btn btn-outline-secondary">Vendor Bills</a>
        <a href="vendor_recurring_bills.php?action=create" class="btn btn-primary">New Recurring Bill</a>
    </div>
</div>
<?php if ($missing): ?><div class="alert alert-warning">Run migration <code>migrations/re_accounting_phase95_vendor_recurring_bills.sql</code> to enable full recurring bill templates.</div><?php endif; ?>
<?php if ($action === 'create'): ?>
<form method="post" class="card card-round mb-4">
    <div class="card-header">Create Recurring Bill Template</div>
    <div class="card-body">
        <?php csrf_field(); ?>
        <input type="hidden" name="form_action" value="create_template">
        <input type="hidden" name="source_bill_id" value="<?= (int)$sourceBillId ?>">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Vendor *</label>
                <select name="vendor_id" class="form-select" required>
                    <option value="">-- Select Vendor --</option>
                    <?php foreach ($vendors as $v): ?>
                        <option value="<?= (int)$v['id'] ?>" <?= ($sourceBill && (int)$sourceBill['vendor_id'] === (int)$v['id']) ? 'selected' : '' ?>><?= h($v['vendor_name']) ?><?= !empty($v['tax_id']) ? ' (TRN ' . h($v['tax_id']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Template Name *</label>
                <input name="template_name" class="form-control" value="<?= h($sourceBill ? 'Recurring - ' . $sourceBill['invoice_number'] : '') ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Frequency *</label>
                <select name="frequency" class="form-select">
                    <option value="weekly">Weekly</option>
                    <option value="monthly" selected>Monthly</option>
                    <option value="quarterly">Quarterly</option>
                    <option value="semi_annual">Semi Annual</option>
                    <option value="annual">Annual</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="active">Active</option>
                    <option value="paused">Paused</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Start / Next Bill Date *</label>
                <input type="date" name="next_bill_date" class="form-control" value="<?= h($defaultNextDate) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">End Date</label>
                <input type="date" name="end_date" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label">Bill # Prefix</label>
                <input name="invoice_number_prefix" class="form-control" value="<?= h($sourceBill['invoice_number'] ?? '') ?>" placeholder="e.g. RENT-AUTO">
            </div>
            <div class="col-md-3">
                <label class="form-label">Due After Days</label>
                <input type="number" min="0" name="due_days" class="form-control" value="<?= (int)$defaultDueDays ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Terms</label>
                <input name="payment_terms" class="form-control" value="<?= h($sourceBill['payment_terms'] ?? '') ?>" placeholder="Net 30">
            </div>
            <div class="col-md-3">
                <label class="form-label">Place of Supply</label>
                <input name="place_of_supply" class="form-control" value="<?= h($sourceBill['place_of_supply'] ?? 'Dubai') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">VAT Treatment</label>
                <?php $sourceVat = $sourceBill['vat_treatment'] ?? 'vat_registered'; ?>
                <select name="vat_treatment" class="form-select">
                    <?php foreach (['vat_registered' => 'VAT Registered', 'non_vat' => 'Non VAT', 'exempt' => 'Exempt', 'out_of_scope' => 'Out of Scope'] as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= $sourceVat === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Order/LPO #</label>
                <input name="order_number" class="form-control" value="<?= h($sourceBill['order_number'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Permit #</label>
                <input name="permit_number" class="form-control" value="<?= h($sourceBill['permit_number'] ?? '') ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= h($sourceBill['notes'] ?? '') ?></textarea>
            </div>
        </div>
        <?php if ($sourceBill): ?>
            <div class="alert alert-info mt-3 mb-0">Loaded source bill <strong><?= h($sourceBill['invoice_number']) ?></strong>. You can change any details before saving the recurring template.</div>
        <?php endif; ?>
        <hr>
        <h6>Line Items</h6>
        <div class="table-responsive">
            <table class="table table-sm" id="billLines">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Expense Account</th>
                        <th>Qty</th>
                        <th>Rate</th>
                        <th>VAT</th>
                        <th>Building</th>
                        <th>Lease</th>
                        <th class="text-end">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary" id="addLine">Add Line</button>
        <div class="text-end mt-3">
            <div>Subtotal: <strong id="subtotal">0.00</strong></div>
            <div>VAT: <strong id="vatTotal">0.00</strong></div>
            <div>Total: <strong id="grandTotal">0.00</strong> AED</div>
        </div>
        <hr>
        <button class="btn btn-success">Create Recurring Bill</button>
        <a href="vendor_recurring_bills.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
<script>
const accounts = <?= json_encode($accounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const buildings = <?= json_encode($buildings, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const leases = <?= json_encode($leases, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const existing = <?= json_encode($sourceLines, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let idx = 0;
function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
}
function options(list, val, label, selected) {
    return list.map(x => `<option value="${esc(x[val])}" ${String(x[val]) === String(selected || '') ? 'selected' : ''}>${esc(x[label])}</option>`).join('');
}
function addLine(data = {}) {
    const desc = data.service_name || data.line_description || data.description || '';
    const tr = document.createElement('tr');
    tr.innerHTML = `<td><input name="items[${idx}][description]" class="form-control form-control-sm" value="${esc(desc)}" required></td>
<td><select name="items[${idx}][expense_account_id]" class="form-select form-select-sm" required><option value="">-- Account --</option>${accounts.map(a => `<option value="${esc(a.id)}" ${String(a.id) === String(data.expense_account_id || '') ? 'selected' : ''}>${esc(a.account_code)} ${esc(a.account_name)}</option>`).join('')}</select></td>
<td><input type="number" step="0.01" name="items[${idx}][quantity]" class="form-control form-control-sm qty" value="${esc(data.quantity || 1)}"></td>
<td><input type="number" step="0.01" name="items[${idx}][unit_price]" class="form-control form-control-sm rate" value="${esc(data.unit_price || 0)}"></td>
<td><select name="items[${idx}][vat_treatment]" class="form-select form-select-sm"><option value="standard" ${(data.vat_treatment || 'standard') === 'standard' ? 'selected' : ''}>5%</option><option value="exempt" ${data.vat_treatment === 'exempt' ? 'selected' : ''}>Exempt</option><option value="zero_rated" ${data.vat_treatment === 'zero_rated' ? 'selected' : ''}>Zero</option><option value="out_of_scope" ${data.vat_treatment === 'out_of_scope' ? 'selected' : ''}>OOS</option></select><input type="hidden" name="items[${idx}][vat_rate]" value="${esc(data.vat_rate || 5)}"></td>
<td><select name="items[${idx}][building_id]" class="form-select form-select-sm"><option value="">-</option>${options(buildings, 'id', 'name', data.building_id)}</select></td>
<td><select name="items[${idx}][lease_id]" class="form-select form-select-sm"><option value="">-</option>${leases.map(l => `<option value="${esc(l.id)}" ${String(l.id) === String(data.lease_id || '') ? 'selected' : ''}>${esc(l.lease_number)} - ${esc(l.building_name)} ${esc(l.unit_number)}</option>`).join('')}</select></td>
<td class="text-end lineTotal">0.00</td><td><button type="button" class="btn btn-sm btn-danger rem">x</button></td>`;
    document.querySelector('#billLines tbody').appendChild(tr);
    idx++;
    calc();
}
function calc() {
    let sub = 0, vat = 0;
    document.querySelectorAll('#billLines tbody tr').forEach(tr => {
        const q = parseFloat(tr.querySelector('.qty').value) || 0;
        const r = parseFloat(tr.querySelector('.rate').value) || 0;
        const vt = tr.querySelector('select[name*="vat_treatment"]').value;
        const s = q * r;
        const v = vt === 'standard' ? s * .05 : 0;
        tr.querySelector('input[name*="vat_rate"]').value = vt === 'standard' ? 5 : 0;
        tr.querySelector('.lineTotal').textContent = (s + v).toFixed(2);
        sub += s;
        vat += v;
    });
    document.getElementById('subtotal').textContent = sub.toFixed(2);
    document.getElementById('vatTotal').textContent = vat.toFixed(2);
    document.getElementById('grandTotal').textContent = (sub + vat).toFixed(2);
}
document.getElementById('addLine').onclick = () => addLine();
document.getElementById('billLines').addEventListener('input', calc);
document.getElementById('billLines').addEventListener('change', calc);
document.getElementById('billLines').addEventListener('click', e => {
    if (e.target.classList.contains('rem')) {
        e.target.closest('tr').remove();
        calc();
    }
});
if (existing.length) existing.forEach(addLine); else addLine();
</script>
<?php endif; ?>
<div class="alert alert-info">Recurring templates now store the bill header and line details. Use Stop to pause future recurrence or Delete to remove a template.</div>
<div class="card card-round">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Template</th>
                    <th>Vendor</th>
                    <th>Source Bill</th>
                    <th>Frequency</th>
                    <th>Next Bill Date</th>
                    <th>Status</th>
                    <th class="text-end">Amount</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h($r['template_name']) ?></td>
                        <td><?= h($r['vendor_name']) ?></td>
                        <td>
                            <?php if (!empty($r['vendor_invoice_id'])): ?>
                                <a href="vendor_bill_view.php?id=<?= (int)$r['vendor_invoice_id'] ?>"><?= h($r['source_invoice_number']) ?></a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?= h(ucwords(str_replace('_', ' ', (string)$r['frequency']))) ?></td>
                        <td><?= h($r['next_bill_date'] ?: '-') ?></td>
                        <td><span class="badge bg-<?= $r['status'] === 'active' ? 'success' : 'secondary' ?>"><?= h($r['status']) ?></span></td>
                        <td class="text-end"><?= m($r['display_amount'] ?? 0) ?></td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <?php if (($r['status'] ?? '') === 'active'): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Stop this recurring bill?');">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="form_action" value="stop">
                                        <input type="hidden" name="template_id" value="<?= (int)$r['id'] ?>">
                                        <button class="btn btn-sm btn-outline-warning">Stop</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" class="d-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="form_action" value="resume">
                                        <input type="hidden" name="template_id" value="<?= (int)$r['id'] ?>">
                                        <button class="btn btn-sm btn-outline-success">Resume</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this recurring bill template?');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="form_action" value="delete">
                                    <input type="hidden" name="template_id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No recurring vendor bill templates yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
