<?php
/**
 * Construction Module — Supplier Dashboard / Profile (Phase 4)
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
require_once __DIR__ . '/includes/construction_supplier_reporting_helpers.php';
require_once __DIR__ . '/includes/construction_contractor_supplier_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_supplier_require_company_id($conn);
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: suppliers.php'); exit; }

$stmt = $conn->prepare("SELECT * FROM co_suppliers WHERE id = ? AND company_id = ?");
$stmt->execute([$id, $cid]);
$s = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$s) { header('Location: suppliers.php'); exit; }

$kpis = co_supplier_dashboard_kpis($conn, $cid, $id);
$timeline = co_supplier_financial_timeline($conn, $cid, $id, 40, true);
$projectSpend = co_supplier_project_spend($conn, $cid, $id);
$linkedContractor = co_supplier_linked_contractor($conn, $cid, $id);
$linkedAssignments = $linkedContractor
    ? co_contractor_project_assignments($conn, $cid, (int)$linkedContractor['id'])
    : [];
$linkedContractValue = 0.0;
foreach ($linkedAssignments as $la) {
    $linkedContractValue += (float)$la['contract_value'];
}

$allocationSelect = "0 AS cash_paid";
$allocationJoin = "";
if (co_supplier_allocations_ready($conn)) {
    $allocationSelect = "COALESCE(a.allocated_amount, 0) AS cash_paid";
    $allocationJoin = "
    LEFT JOIN (
        SELECT invoice_id, SUM(allocated_amount) AS allocated_amount
        FROM co_supplier_payment_allocations
        GROUP BY invoice_id
    ) a ON a.invoice_id = si.id";
}

$invoices = $conn->prepare("
    SELECT si.*, p.project_code, p.project_name, {$allocationSelect}
    FROM co_supplier_invoices si
    LEFT JOIN co_projects p ON p.id = si.project_id
    {$allocationJoin}
    WHERE si.supplier_id = ? AND si.company_id = ?
    ORDER BY si.invoice_date DESC, si.id DESC
    LIMIT 50
");
$invoices->execute([$id, $cid]);
$invoices = $invoices->fetchAll(PDO::FETCH_ASSOC);
foreach ($invoices as &$invRow) {
    $paid = co_supplier_invoice_paid_amount($conn, $cid, (int)$invRow['id']);
    $invRow['paid_amount'] = $paid;
    $invRow['balance_due'] = function_exists('co_supplier_invoice_outstanding_amount')
        ? co_supplier_invoice_outstanding_amount($conn, $cid, (int)$invRow['id'])
        : max(round((float)$invRow['total'] - $paid, 2), 0);
    $invRow['lifecycle'] = co_supplier_invoice_status($invRow);
}
unset($invRow);

$paymentAccountSelect = co_payment_account_column_ready($conn)
    ? "sp.*, coa.account_code, coa.account_name"
    : "sp.*, NULL AS account_code, NULL AS account_name";
$paymentAccountJoin = co_payment_account_column_ready($conn)
    ? "LEFT JOIN re_chart_of_accounts coa ON coa.id = sp.pay_account_id AND coa.company_id = sp.company_id"
    : "";
$payments = $conn->prepare("
    SELECT {$paymentAccountSelect}
    FROM co_supplier_payments sp
    {$paymentAccountJoin}
    WHERE sp.supplier_id = ? AND sp.company_id = ?
    ORDER BY sp.payment_date DESC, sp.id DESC
    LIMIT 30
");
$payments->execute([$id, $cid]);
$payments = $payments->fetchAll(PDO::FETCH_ASSOC);

// Informational Quick Paid Expenses (never affect AP / advances).
$quickPaidExpenses = [];
$quickPaidTotal = 0.0;
try {
    $qp = $conn->prepare("
        SELECT e.id, e.expense_date, e.expense_number, e.reference_no, e.total, e.status, e.paid_via,
               COALESCE(e.legacy_archive, 0) AS legacy_archive, e.project_id,
               p.project_code, p.project_name
        FROM erp_expense_headers e
        LEFT JOIN co_projects p ON p.id = e.project_id AND p.company_id = e.company_id
        WHERE e.company_id = ? AND e.source_module = 'construction' AND e.co_supplier_id = ?
          AND e.status <> 'cancelled'
        ORDER BY e.expense_date DESC, e.id DESC
        LIMIT 25
    ");
    $qp->execute([$cid, $id]);
    $quickPaidExpenses = $qp->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($quickPaidExpenses as $qe) {
        if (($qe['status'] ?? '') === 'posted') {
            $quickPaidTotal += (float)$qe['total'];
        }
    }
} catch (Throwable $e) {
    $quickPaidExpenses = [];
}

$eventBadge = static function (string $event): string {
    $map = [
        'supplier_created' => 'secondary',
        'invoice_created' => 'primary',
        'invoice_posted' => 'success',
        'payment_made' => 'success',
        'advance_created' => 'info',
        'advance_vat_posted' => 'warning',
        'advance_applied' => 'info',
        'refund' => 'dark',
        'void' => 'danger',
        'amend' => 'warning',
    ];
    return $map[$event] ?? 'light text-dark';
};

$pageTitle = $s['supplier_name'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="suppliers.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-0"><?= h($s['supplier_name']) ?></h1>
            <span class="badge bg-<?= $s['is_active'] ? 'success' : 'secondary' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="supplier_payment_add.php?supplier_id=<?= $id ?>" class="btn btn-success">Pay Open Invoices</a>
            <a href="supplier_invoice_add.php?supplier_id=<?= $id ?>" class="btn btn-primary">Add Invoice</a>
            <a href="reports/supplier_statement.php?supplier_id=<?= $id ?>" class="btn btn-outline-secondary">Statement</a>
            <a href="reports/supplier_project_spend.php?supplier_id=<?= $id ?>" class="btn btn-outline-secondary">Project Spend</a>
            <a href="expenses.php?supplier_id=<?= $id ?>" class="btn btn-outline-secondary">Quick Paid</a>
            <a href="supplier_documents.php?supplier_id=<?= $id ?>" class="btn btn-outline-secondary">Documents</a>
            <a href="supplier_edit.php?id=<?= $id ?>" class="btn btn-outline-primary">Edit Supplier</a>
            <?php if ($linkedContractor): ?>
            <a href="contractor_view.php?id=<?= (int)$linkedContractor['id'] ?>" class="btn btn-outline-info">Open Contractor Profile</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($linkedContractor): ?>
<div class="card card-round mb-3 border-info">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Business Partner — Linked Contractor</h6>
        <a href="contractor_view.php?id=<?= (int)$linkedContractor['id'] ?>" class="btn btn-sm btn-outline-info">Contractor Profile</a>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-2">
            <div class="col-md-4">
                <div class="text-muted small text-uppercase">Linked Contractor</div>
                <div class="fw-semibold"><a href="contractor_view.php?id=<?= (int)$linkedContractor['id'] ?>"><?= h($linkedContractor['contractor_name']) ?></a></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small text-uppercase">Contract Value</div>
                <div class="fw-semibold"><?= co_format_money($linkedContractValue) ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small text-uppercase">Projects</div>
                <div class="fw-semibold"><?= count($linkedAssignments) ?></div>
            </div>
        </div>
        <?php if (!empty($linkedAssignments)): ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Project</th><th>Contract Value</th><th>Retention %</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($linkedAssignments as $la): ?>
                    <tr>
                        <td><a href="project_view.php?id=<?= (int)$la['project_id'] ?>"><?= h($la['project_code']) ?> — <?= h($la['project_name']) ?></a></td>
                        <td><?= co_format_money($la['contract_value']) ?></td>
                        <td><?= h($la['retention_pct']) ?>%</td>
                        <td><?= h($la['status'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <p class="text-muted mb-0 small">No project assignments on the linked contractor yet.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-4 col-lg-2">
        <div class="card card-round h-100"><div class="card-body">
            <h6 class="text-muted small text-uppercase mb-1">Outstanding AP</h6>
            <div class="h5 mb-0"><?= co_format_money($kpis['outstanding_ap']) ?></div>
        </div></div>
    </div>
    <div class="col-md-4 col-lg-2">
        <div class="card card-round h-100"><div class="card-body">
            <h6 class="text-muted small text-uppercase mb-1">Supplier Advance</h6>
            <div class="h5 mb-0"><?= co_format_money($kpis['advance_balance']) ?></div>
        </div></div>
    </div>
    <div class="col-md-4 col-lg-2">
        <div class="card card-round h-100"><div class="card-body">
            <h6 class="text-muted small text-uppercase mb-1">Net Payable</h6>
            <div class="h5 mb-0"><?= co_format_money($kpis['net_payable']) ?></div>
            <small class="text-muted">AP − Advances</small>
        </div></div>
    </div>
    <div class="col-md-4 col-lg-2">
        <div class="card card-round h-100"><div class="card-body">
            <h6 class="text-muted small text-uppercase mb-1">VAT Pending</h6>
            <div class="h5 mb-0"><?= co_format_money($kpis['vat_pending']) ?></div>
            <small class="text-muted">Draft VAT + unlinked Adv VAT</small>
        </div></div>
    </div>
    <div class="col-md-4 col-lg-2">
        <div class="card card-round h-100"><div class="card-body">
            <h6 class="text-muted small text-uppercase mb-1">Open Invoices</h6>
            <div class="h5 mb-0"><?= (int)$kpis['open_invoices'] ?></div>
            <small class="text-muted">Posted unpaid</small>
        </div></div>
    </div>
    <div class="col-md-4 col-lg-2">
        <div class="card card-round h-100"><div class="card-body">
            <h6 class="text-muted small text-uppercase mb-1">Partially Paid</h6>
            <div class="h5 mb-0"><?= (int)$kpis['partially_paid_invoices'] ?></div>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card card-round h-100"><div class="card-body">
            <h6 class="text-muted small text-uppercase mb-1">Total Purchased</h6>
            <div class="h5 mb-0"><?= co_format_money($kpis['total_purchased']) ?></div>
            <small class="text-muted">Non-voided</small>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card card-round h-100"><div class="card-body">
            <h6 class="text-muted small text-uppercase mb-1">Total Paid (Bank)</h6>
            <div class="h5 mb-0"><?= co_format_money($kpis['total_paid']) ?></div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card card-round h-100"><div class="card-body">
            <h6 class="text-muted small text-uppercase mb-1">Last Invoice</h6>
            <?php if (!empty($kpis['last_invoice'])): ?>
            <div class="fw-semibold"><a href="supplier_invoice_view.php?id=<?= (int)$kpis['last_invoice']['id'] ?>"><?= h($kpis['last_invoice']['invoice_number']) ?></a></div>
            <small class="text-muted"><?= h($kpis['last_invoice']['invoice_date']) ?> · <?= co_format_money($kpis['last_invoice']['total']) ?></small>
            <?php else: ?><div class="text-muted">—</div><?php endif; ?>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card card-round h-100"><div class="card-body">
            <h6 class="text-muted small text-uppercase mb-1">Last Payment</h6>
            <?php if (!empty($kpis['last_payment'])): ?>
            <div class="fw-semibold"><a href="supplier_payment_view.php?id=<?= (int)$kpis['last_payment']['id'] ?>"><?= co_format_money($kpis['last_payment']['amount']) ?></a></div>
            <small class="text-muted"><?= h($kpis['last_payment']['payment_date']) ?></small>
            <?php else: ?><div class="text-muted">—</div><?php endif; ?>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="card card-round h-100">
            <div class="card-header bg-white"><h6 class="mb-0">Supplier Timeline</h6></div>
            <div class="card-body p-0" style="max-height:420px;overflow:auto">
                <?php if (!$timeline): ?>
                <p class="text-muted p-3 mb-0">No timeline events yet.</p>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($timeline as $ev): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between gap-2">
                            <div>
                                <span class="badge bg-<?= h($eventBadge((string)$ev['event'])) ?> me-1"><?= h($ev['label']) ?></span>
                                <?php if (!empty($ev['link'])): ?>
                                <a href="<?= h($ev['link']) ?>" class="fw-semibold"><?= h($ev['ref'] ?: $ev['label']) ?></a>
                                <?php else: ?>
                                <span class="fw-semibold"><?= h($ev['ref'] ?: $ev['label']) ?></span>
                                <?php endif; ?>
                                <div class="small text-muted"><?= h($ev['date']) ?></div>
                            </div>
                            <div class="text-end">
                                <?php if ($ev['amount'] !== null): ?>
                                <div class="fw-semibold"><?= co_format_money($ev['amount']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card card-round h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Spend by Project</h6>
                <a href="reports/supplier_project_spend.php?supplier_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">Full report</a>
            </div>
            <div class="card-body p-0 table-responsive">
                <?php if (!$projectSpend): ?>
                <p class="text-muted p-3 mb-0">No project-linked invoices yet.</p>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Project</th><th class="text-end">Invoiced</th><th class="text-end">Outstanding</th><th class="text-end">#</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($projectSpend, 0, 8) as $ps): ?>
                    <tr>
                        <td><?= h($ps['project_code']) ?> — <?= h($ps['project_name']) ?></td>
                        <td class="text-end"><?= co_format_money($ps['invoiced']) ?></td>
                        <td class="text-end"><?= co_format_money($ps['outstanding']) ?></td>
                        <td class="text-end"><?= (int)$ps['invoice_count'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card card-round mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <div>
            <h6 class="mb-0">Quick Paid Expenses</h6>
            <small class="text-muted">Informational only — does not affect Outstanding AP, Net Payable, or Supplier Advances.</small>
        </div>
        <a href="expenses.php?supplier_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">View all</a>
    </div>
    <div class="card-body p-0">
        <?php if (!$quickPaidExpenses): ?>
        <p class="text-muted p-3 mb-0">No Quick Paid / historical ERP expenses linked to this supplier.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Date</th><th>Expense #</th><th>Project</th><th>Method</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($quickPaidExpenses as $qe): ?>
                <tr>
                    <td><?= h($qe['expense_date']) ?></td>
                    <td><?= h($qe['expense_number'] ?: ('#' . $qe['id'])) ?><?php if ((int)($qe['legacy_archive'] ?? 0) === 1): ?> <span class="badge bg-secondary">Historical</span><?php endif; ?></td>
                    <td><?= !empty($qe['project_id']) ? h(trim(($qe['project_code'] ?? '') . ' — ' . ($qe['project_name'] ?? ''), " —")) : '<span class="text-muted">Overhead</span>' ?></td>
                    <td><?php
                        $pvl = ['cash'=>'Cash','bank'=>'Bank','credit'=>'Credit Card','accounts_payable'=>'AP (legacy)'];
                        echo h($pvl[$qe['paid_via'] ?? ''] ?? ($qe['paid_via'] ?? '—'));
                    ?></td>
                    <td class="text-end"><?= co_format_money($qe['total']) ?></td>
                    <td><span class="badge bg-<?= ($qe['status'] ?? '') === 'posted' ? 'success' : 'warning text-dark' ?>"><?= h($qe['status'] ?? '') ?></span></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="expense_edit.php?id=<?= (int)$qe['id'] ?>">View</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2 border-top small text-muted">Posted Quick Paid total (informational): <?= co_format_money($quickPaidTotal) ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="card card-round">
    <div class="card-header bg-white"><h6 class="mb-0">Details</h6></div>
    <div class="card-body">
        <div class="table-responsive">
        <table class="table table-sm mb-0">
            <tr><td class="text-muted" style="width:30%">Contact Person</td><td><?= h($s['contact_person'] ?? '-') ?></td></tr>
            <tr><td class="text-muted">Phone</td><td><?= h($s['phone'] ?? '-') ?></td></tr>
            <tr><td class="text-muted">Email</td><td><?= h($s['email'] ?? '-') ?></td></tr>
            <tr><td class="text-muted">Tax / VAT registration (TRN)</td><td><?= h($s['vat_number'] ?? $s['tax_number'] ?? '-') ?></td></tr>
            <tr><td class="text-muted">Address</td><td><?= nl2br(h($s['address'] ?? '-')) ?></td></tr>
            <?php
            $hasStructuredBank = !empty($s['bank_name']) || !empty($s['account_number']) || !empty($s['iban']) || !empty($s['swift_code']);
            if ($hasStructuredBank):
                $bankParts = array_filter([
                    $s['bank_name'] ?? null,
                    !empty($s['account_number']) ? 'Account: ' . ($s['account_number'] ?? '') : null,
                    $s['iban'] ?? null,
                    !empty($s['swift_code']) ? 'SWIFT: ' . ($s['swift_code'] ?? '') : null
                ]);
            ?>
            <tr><td class="text-muted">Bank Details</td><td><?= implode(' · ', array_map('h', $bankParts)) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($s['bank_details'])): ?>
            <tr><td class="text-muted"><?= $hasStructuredBank ? 'Bank Notes' : 'Bank Details' ?></td><td><?= nl2br(h($s['bank_details'])) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($s['notes'])): ?>
            <tr><td class="text-muted">Notes</td><td><?= nl2br(h($s['notes'])) ?></td></tr>
            <?php endif; ?>
        </table>
        </div>
    </div>
</div>

<div class="card card-round mt-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <div>
            <h6 class="mb-0">Invoices</h6>
            <small class="text-muted">Use Pay Open Invoices for one payment across multiple supplier invoices.</small>
        </div>
        <a href="supplier_invoice_add.php?supplier_id=<?= $id ?>" class="btn btn-sm btn-primary">Add Invoice</a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($invoices)): ?>
        <div class="p-4 text-muted">No invoices yet. <a href="supplier_invoice_add.php?supplier_id=<?= $id ?>">Add an invoice</a>.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr><th>Date</th><th>Invoice #</th><th>Project</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td><?= h($inv['invoice_date']) ?></td>
                    <td><?= h($inv['invoice_number']) ?></td>
                    <td><?= $inv['project_code'] ? h($inv['project_code']) : '—' ?></td>
                    <td class="text-end"><strong><?= co_format_money($inv['total']) ?></strong></td>
                    <td class="text-end"><?= co_format_money($inv['paid_amount']) ?></td>
                    <td class="text-end"><?= co_format_money($inv['balance_due']) ?></td>
                    <td>
                        <span class="badge bg-<?= h(co_supplier_invoice_status_badge_class($inv['lifecycle'])) ?>"><?= h(co_supplier_invoice_status_label($inv['lifecycle'])) ?></span>
                    </td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <a href="supplier_invoice_view.php?id=<?= (int)$inv['id'] ?>" class="btn btn-outline-primary">View</a>
                            <?php if (co_supplier_invoice_can_edit($inv)): ?>
                            <a href="supplier_invoice_edit.php?id=<?= (int)$inv['id'] ?>" class="btn btn-outline-secondary">Edit</a>
                            <?php endif; ?>
                            <?php if ((float)$inv['balance_due'] > 0.005 && !empty($inv['journal_id']) && $inv['lifecycle'] !== 'voided'): ?>
                            <a href="supplier_payment_add.php?invoice_id=<?= (int)$inv['id'] ?>" class="btn btn-success">Pay</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card card-round mt-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Payments</h6>
        <a href="supplier_payment_add.php?supplier_id=<?= $id ?>" class="btn btn-sm btn-success">Pay Open Invoices</a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($payments)): ?>
        <div class="p-4 text-muted">No payments yet. <a href="supplier_payment_add.php?supplier_id=<?= $id ?>">Record a payment</a>.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr><th>Date</th><th class="text-end">Amount</th><th>Paid From</th><th>Reference</th><th>GL</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($payments as $pay): ?>
                <tr>
                    <td><?= h($pay['payment_date']) ?></td>
                    <td class="text-end"><strong><?= co_format_money($pay['amount']) ?></strong></td>
                    <td><?= !empty($pay['account_code']) ? h($pay['account_code'] . ' — ' . $pay['account_name']) : '—' ?></td>
                    <td><?= h($pay['reference'] ?? '—') ?></td>
                    <td><?= $pay['journal_id'] ? '<span class="badge bg-success">Posted</span>' : '—' ?></td>
                    <td class="text-end">
                        <a href="supplier_payment_view.php?id=<?= (int)$pay['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
