<?php
/**
 * Invoice candidate preview and issuance.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/invoice_engine.php';
require_once __DIR__ . '/../includes/receipt_allocation_engine.php';
require_once __DIR__ . '/../includes/payment_allocation_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$leaseId = !empty($_GET['lease_id']) ? (int)$_GET['lease_id'] : 0;
$preview = null;
$error = '';
$autoIssueNotice = '';
$creditApplyNotice = '';
$tenantCreditBalance = 0.0;
$generationFlash = $_SESSION['re_invoice_generation_flash'] ?? null;
unset($_SESSION['re_invoice_generation_flash']);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function phase3_money($n): string { return number_format((float)$n, 2); }
function phase3_tenant_name(array $row): string
{
    if (($row['tenant_type'] ?? '') === 'company') {
        return (string)($row['company_name'] ?? 'Company Tenant');
    }
    return trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')) ?: 'Tenant';
}
function phase3_status_badge(string $status): string
{
    $map = [
        'paid' => 'success',
        'cancelled' => 'danger',
        'sent' => 'warning',
        'partial' => 'info',
        'draft' => 'secondary',
        'issued' => 'primary',
        'approved' => 'info',
    ];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') . '</span>';
}

$selectAccountingMode = re_obligation_column_exists($conn, 're_leases', 'accounting_mode')
    ? 'l.accounting_mode'
    : "'legacy' AS accounting_mode";
$leaseOptions = $conn->prepare("
    SELECT l.id, l.lease_number, l.start_date, l.end_date, {$selectAccountingMode},
           t.first_name, t.last_name, t.company_name, t.tenant_type,
           u.unit_number, u.unit_type, b.name AS building_name
    FROM re_leases l
    LEFT JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_units u ON u.id = l.unit_id
    LEFT JOIN re_buildings b ON b.id = u.building_id
    WHERE l.company_id = ?
    ORDER BY l.id DESC
    LIMIT 250
");
$leaseOptions->execute([$currentCompanyId]);
$leases = $leaseOptions->fetchAll(PDO::FETCH_ASSOC);

$leaseContext = null;
$invoiceLedger = [];
$candidateLedger = [];
$voidReversalAudit = [];

if ($leaseId > 0) {
    $autoIssue = re_invoice_engine_issue_eligible_for_lease($conn, $currentCompanyId, $leaseId, current_user_id());
    if (!empty($autoIssue['success'])) {
        $issued = (int)($autoIssue['stats']['issued'] ?? 0);
        if ($issued > 0) {
            $autoIssueNotice = $issued . ' eligible invoice candidate' . ($issued === 1 ? ' was' : 's were') . ' issued automatically.';
            // Issuance already tries full-settle credit per invoice; keep wording accurate.
            $autoIssueNotice .= ' Available tenant credit was applied to each new invoice only when it fully cleared that invoice.';
        }
    } elseif (!empty($autoIssue['error'])) {
        $error = (string)$autoIssue['error'];
    }

    // Also sweep parked credit onto already-open invoices (e.g. August issued earlier,
    // CHQ-6 half parked as credit later — no second bank receipt needed).
    if (function_exists('re_receipt_apply_available_tenant_credit')) {
        $creditSweep = re_receipt_apply_available_tenant_credit(
            $conn,
            $currentCompanyId,
            $leaseId,
            current_user_id()
        );
        if (!empty($creditSweep['applied']) && (float)$creditSweep['applied'] > 0.005) {
            $nums = $creditSweep['invoice_numbers'] ?? [];
            $numLabel = is_array($nums) && $nums !== []
                ? (' (' . implode(', ', array_map('strval', $nums)) . ')')
                : '';
            $creditApplyNotice = sprintf(
                'Applied AED %s from tenant credit to fully clear %d open invoice(s)%s. No bank/cheque receipt was posted.',
                phase3_money($creditSweep['applied']),
                (int)($creditSweep['invoice_count'] ?? 0),
                $numLabel
            );
        } elseif (!empty($creditSweep['error'])) {
            $creditApplyNotice = 'Tenant credit apply note: ' . (string)$creditSweep['error'];
        }
    }

    $preview = re_invoice_engine_candidate_plan_for_lease($conn, $currentCompanyId, $leaseId);
    if (empty($preview['success'])) {
        $error = (string)($preview['error'] ?? 'Could not generate invoice candidate preview.');
    }
    $ctxStmt = $conn->prepare("
        SELECT id, lease_number, status, termination_date, termination_reason, tenant_id,
               COALESCE(accounting_mode, 'legacy') AS accounting_mode
        FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1
    ");
    $ctxStmt->execute([$leaseId, $currentCompanyId]);
    $leaseContext = $ctxStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!empty($leaseContext['tenant_id']) && function_exists('get_tenant_credit_balance')) {
        $tenantCreditBalance = (float)get_tenant_credit_balance(
            $conn,
            (int)$leaseContext['tenant_id'],
            $currentCompanyId
        );
    }
    $invoiceLedger = re_invoice_engine_lease_invoices_all($conn, $currentCompanyId, $leaseId);
    $candidateLedger = re_invoice_engine_lease_candidates_all($conn, $currentCompanyId, $leaseId);
    $voidReversalAudit = re_invoice_engine_void_reversal_audit($conn, $currentCompanyId, $leaseId);
}

$pageTitle = 'Invoice Candidates';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">
        <i class="bi bi-file-earmark-text"></i> Invoice Candidates
    </div>
    <span class="badge bg-secondary">Official numbers only on issuance</span>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($autoIssueNotice): ?>
    <div class="alert alert-success"><?= h($autoIssueNotice) ?></div>
<?php endif; ?>
<?php if ($creditApplyNotice): ?>
    <div class="alert alert-info"><?= h($creditApplyNotice) ?></div>
<?php endif; ?>
<?php if (is_array($generationFlash)): ?>
    <div class="alert alert-<?= !empty($generationFlash['success']) ? 'success' : 'danger' ?>">
        <?= h($generationFlash['message'] ?? '') ?>
    </div>
<?php endif; ?>

<?php if ($leaseId > 0 && ($tenantCreditBalance > 0.005 || $creditApplyNotice !== '')): ?>
    <div class="alert alert-light border mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong>Tenant credit available:</strong> AED <?= phase3_money($tenantCreditBalance) ?>.
                <div class="small text-muted mb-0">
                    Opening this page auto-issues due invoices and applies parked credit only when it fully clears an open invoice
                    (for example August rent/service after CHQ-6 parked half the cheque). No second bank deposit is required.
                </div>
            </div>
            <?php if ($tenantCreditBalance > 0.005): ?>
                <form method="post" action="invoice_generate.php" class="m-0">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="lease_id" value="<?= (int)$leaseId ?>">
                    <input type="hidden" name="action" value="apply_tenant_credit">
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        Apply tenant credit to open invoices
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card card-round mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-9">
                <label class="form-label">Lease</label>
                <select name="lease_id" class="form-select" required>
                    <option value="">-- Select lease --</option>
                    <?php foreach ($leases as $lease): ?>
                        <option value="<?= (int)$lease['id'] ?>" <?= $leaseId === (int)$lease['id'] ? 'selected' : '' ?>>
                            #<?= (int)$lease['id'] ?> - <?= h($lease['lease_number'] ?? '') ?>
                            | <?= h(phase3_tenant_name($lease)) ?>
                            | <?= h(ucfirst((string)($lease['accounting_mode'] ?? 'legacy'))) ?>
                            | <?= h(trim(($lease['building_name'] ?? '') . ' ' . ($lease['unit_number'] ?? ''))) ?>
                            | <?= h($lease['start_date'] ?? '') ?> to <?= h($lease['end_date'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search"></i> Preview Candidates
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($preview && !empty($preview['success'])): ?>
    <?php
    $lease = $preview['lease'];
    $summary = $preview['summary'];
    $diagnostics = $preview['diagnostics'];
    $awaiting = $diagnostics['candidates_awaiting_issuance'];
    $eligible = $diagnostics['eligible_candidates'];
    $cancelledInvoices = array_values(array_filter($invoiceLedger, static fn($row) => ($row['status'] ?? '') === 'cancelled'));
    $cancelledCandidates = array_values(array_filter($candidateLedger, static fn($row) => ($row['status'] ?? '') === 'cancelled'));
    ?>

    <?php if (($leaseContext['status'] ?? '') === 'terminated'): ?>
        <div class="alert alert-info">
            <strong>Terminated lease</strong> — effective <?= h($leaseContext['termination_date'] ?? '') ?>.
            <?php if (!empty($leaseContext['termination_reason'])): ?>
                <br><span class="small"><?= h($leaseContext['termination_reason']) ?></span>
            <?php endif; ?>
            <div class="small mt-2 mb-0">
                Voided invoices: <strong><?= count($cancelledInvoices) ?></strong> |
                Cancelled candidates: <strong><?= count($cancelledCandidates) ?></strong> |
                Reversal journals: <strong><?= count($voidReversalAudit) ?></strong>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($invoiceLedger): ?>
    <div class="card card-round mb-4 border-secondary">
        <div class="card-header bg-white">
            <h6 class="mb-0">Full Invoice Ledger (including voided/cancelled)</h6>
            <small class="text-muted">Use this table to confirm remaining-period revenue was reversed. Cancelled = voided with GL reversal.</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>Obligation</th>
                            <th>Status</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invoiceLedger as $row): ?>
                        <tr class="<?= ($row['status'] ?? '') === 'cancelled' ? 'table-danger' : '' ?>">
                            <td><a href="../billing_invoice_view.php?id=<?= (int)$row['id'] ?>"><?= h($row['invoice_number']) ?></a></td>
                            <td><?= h($row['invoice_date']) ?></td>
                            <td>#<?= (int)($row['obligation_id'] ?? 0) ?> <?= h($row['obligation_label'] ?? ($row['obligation_type'] ?? '')) ?></td>
                            <td><?= phase3_status_badge((string)($row['status'] ?? '')) ?></td>
                            <td class="text-end"><?= phase3_money($row['total_amount']) ?></td>
                            <td class="text-end"><?= phase3_money($row['outstanding_amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($voidReversalAudit): ?>
    <div class="card card-round mb-4 border-danger">
        <div class="card-header bg-danger-subtle">
            <h6 class="mb-0">Void / Revenue Reversal Audit</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>Invoice</th><th>Original Journal</th><th>Reversal Journal</th><th>Reversal Date</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($voidReversalAudit as $row): ?>
                        <tr>
                            <td><?= h($row['invoice_number']) ?></td>
                            <td><?= h($row['original_journal_number'] ?? '—') ?> <?= !empty($row['is_reversed']) ? '<span class="badge bg-success">reversed</span>' : '<span class="badge bg-warning text-dark">check GL</span>' ?></td>
                            <td><?= h($row['reversal_journal_number'] ?? '—') ?></td>
                            <td><?= h($row['reversal_date'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card card-round mb-4">
        <div class="card-header bg-white">
            <h6 class="mb-0">All Invoice Candidates (including cancelled)</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>ID</th><th>Obligation</th><th>Eligible</th><th>Candidate Status</th><th>Invoice</th><th class="text-end">Amount</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($candidateLedger as $row): ?>
                        <tr class="<?= ($row['status'] ?? '') === 'cancelled' ? 'table-danger' : '' ?>">
                            <td>#<?= (int)$row['id'] ?></td>
                            <td>#<?= (int)$row['obligation_id'] ?> <?= h($row['obligation_label'] ?? $row['obligation_type']) ?> (<?= h($row['obligation_status'] ?? '') ?>)</td>
                            <td><?= h($row['eligible_on']) ?></td>
                            <td><?= phase3_status_badge((string)($row['status'] ?? '')) ?></td>
                            <td>
                                <?php if (!empty($row['invoice_number'])): ?>
                                    <?= h($row['invoice_number']) ?> <?= phase3_status_badge((string)($row['invoice_status'] ?? '')) ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= phase3_money($row['total_amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($candidateLedger)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No invoice candidates for this lease.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card card-round h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase">Lease</div>
                    <h5 class="mb-1"><?= h($lease['lease_number'] ?? ('#' . $leaseId)) ?></h5>
                    <div><?= h(phase3_tenant_name($lease)) ?></div>
                    <div class="small text-muted"><?= h($lease['building_name'] ?? '') ?> <?= h($lease['unit_number'] ?? '') ?> / <?= h($lease['unit_type'] ?? '') ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-round h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase">Candidate Plan</div>
                    <h4 class="mb-0"><?= (int)$summary['count'] ?></h4>
                    <small class="text-muted">
                        New candidates not yet prepared |
                        Eligible now: <?= (int)$summary['eligible_now'] ?> |
                        Future: <?= (int)$summary['future'] ?>
                    </small>
                    <?php if ((int)$summary['count'] > 0): ?>
                        <form method="post" action="invoice_generate.php" class="mt-3">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="lease_id" value="<?= (int)$leaseId ?>">
                            <input type="hidden" name="action" value="prepare_candidates">
                            <button type="submit" class="btn btn-sm btn-outline-primary"
                                    onclick="return confirm('Prepare invoice candidates for this lease? No invoice numbers will be assigned.');">
                                <i class="bi bi-list-check"></i> Prepare Candidates
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-round h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase">Issuance Queue</div>
                    <h4 class="mb-0"><?= count($eligible) ?></h4>
                    <small class="text-muted"><?= count($awaiting) ?> approved candidate<?= count($awaiting) === 1 ? '' : 's' ?> awaiting issuance</small>
                    <small class="text-muted d-block mt-2">Eligible approved candidates are issued automatically when this page is opened. Future candidates remain queued until their eligible date.</small>
                </div>
            </div>
        </div>
    </div>

    <div class="card card-round mb-4">
        <div class="card-header bg-white">
            <h6 class="mb-0">Candidate Preview Before Preparation</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Obligation</th>
                            <th>Candidate Key</th>
                            <th>Eligible On</th>
                            <th>Line</th>
                            <th>VAT Treatment</th>
                            <th class="text-end">Subtotal</th>
                            <th class="text-end">VAT</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preview['candidate_plans'] as $plan): ?>
                        <?php $line = $plan['line']; $obligation = $plan['obligation']; ?>
                        <tr>
                            <td>#<?= (int)$obligation['id'] ?> <?= h(re_obligation_display_label(
                                $obligation['obligation_type'] ?? null,
                                $obligation['source_type'] ?? null,
                                $obligation['description'] ?? ($line['item_name'] ?? null),
                                null
                            )) ?></td>
                            <td><small class="text-muted"><?= h($plan['candidate_key']) ?></small></td>
                            <td>
                                <?= h($plan['eligible_on']) ?>
                                <?php if (!empty($plan['is_eligible_now'])): ?>
                                    <span class="badge bg-success">eligible</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">future</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= h($line['item_name']) ?>
                                <br><small class="text-muted"><?= h($line['item_description']) ?></small>
                            </td>
                            <td><?= h($line['tax_treatment']) ?> / <?= h($line['tax_label']) ?></td>
                            <td class="text-end"><?= phase3_money($plan['subtotal']) ?></td>
                            <td class="text-end"><?= phase3_money($plan['tax_amount']) ?></td>
                            <td class="text-end fw-semibold"><?= phase3_money($plan['total_amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($preview['candidate_plans'])): ?>
                        <tr><td colspan="8" class="text-center text-muted py-3">No new candidate source obligations. Existing candidates or issued invoices may already exist below.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card card-round h-100">
                <div class="card-header bg-white">
                    <h6 class="mb-0">Candidates Awaiting Issuance</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr><th>Candidate</th><th>Obligation</th><th>Eligible On</th><th>Status</th><th class="text-end">Total</th><th class="text-end">Action</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($awaiting as $row): ?>
                                <tr>
                                    <td>#<?= (int)$row['id'] ?></td>
                                    <td>#<?= (int)$row['obligation_id'] ?> <?= h($row['obligation_label'] ?? re_obligation_display_label(
                                        $row['obligation_type'] ?? null,
                                        $row['source_type'] ?? null,
                                        $row['description'] ?? null,
                                        null
                                    )) ?></td>
                                    <td><?= h($row['eligible_on']) ?></td>
                                    <td>
                                        <?= h($row['status']) ?>
                                        <?php if ((string)$row['eligible_on'] <= date('Y-m-d')): ?>
                                            <span class="badge bg-success">eligible now</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">future</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= phase3_money($row['total_amount']) ?></td>
                                    <td class="text-end">
                                        <?php if ((string)$row['eligible_on'] <= date('Y-m-d')): ?>
                                            <form method="post" action="invoice_generate.php" class="d-inline">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="lease_id" value="<?= (int)$leaseId ?>">
                                                <input type="hidden" name="action" value="issue_candidate">
                                                <input type="hidden" name="candidate_id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-success"
                                                        onclick="return confirm('Issue this candidate only? One official invoice number will be assigned now.');">
                                                    Issue
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted small">Not yet</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($awaiting)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No candidates awaiting issuance.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card card-round h-100">
                <div class="card-header bg-white">
                    <h6 class="mb-0">Issued Invoices Linked To Candidates</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr><th>Candidate</th><th>Invoice</th><th>Invoice Date</th><th class="text-end">Total</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($diagnostics['linked_invoices'] as $row): ?>
                                <tr class="<?= ($row['status'] ?? '') === 'cancelled' ? 'table-danger' : '' ?>">
                                    <td>#<?= (int)$row['candidate_id'] ?> / Obligation #<?= (int)$row['obligation_id'] ?></td>
                                    <td>
                                        <a href="../billing_invoice_view.php?id=<?= (int)$row['invoice_id'] ?>"><?= h($row['invoice_number']) ?></a>
                                        <?= phase3_status_badge((string)($row['status'] ?? '')) ?>
                                        <br><small class="text-muted"><?= h($row['invoice_key']) ?></small>
                                    </td>
                                    <td><?= h($row['invoice_date']) ?></td>
                                    <td class="text-end"><?= phase3_money($row['total_amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($diagnostics['linked_invoices'])): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3">No candidate-issued invoices yet.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card card-round mt-4">
        <div class="card-header bg-white">
            <h6 class="mb-0">Invoices Issued Too Early</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>Invoice</th><th>Source</th><th>Invoice Date</th><th>Generated At</th><th>Obligation Due</th><th>Reason</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($diagnostics['future_issued_invoices'] as $row): ?>
                        <tr>
                            <td><a href="../billing_invoice_view.php?id=<?= (int)$row['invoice_id'] ?>"><?= h($row['invoice_number']) ?></a></td>
                            <td><?= h($row['invoice_source']) ?></td>
                            <td><?= h($row['invoice_date']) ?></td>
                            <td><?= h($row['generated_at']) ?></td>
                            <td><?= h($row['obligation_due_date']) ?></td>
                            <td><?= h($row['diagnostic_reason']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($diagnostics['future_issued_invoices'])): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No invoices issued too early were detected for this lease.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card card-round mt-4">
        <div class="card-header bg-white">
            <h6 class="mb-0">Diagnostics: Duplicate Prevention</h6>
        </div>
        <div class="card-body">
            <?php $dupKeys = $diagnostics['duplicate_invoice_keys']; $dupLines = $diagnostics['duplicate_obligation_lines']; ?>
            <?php if (empty($dupKeys) && empty($dupLines)): ?>
                <div class="alert alert-success mb-0">
                    No duplicate invoice keys or duplicate obligation invoice lines detected for this lease.
                </div>
            <?php else: ?>
                <div class="alert alert-warning">Duplicate diagnostics found. Review before issuing more invoices.</div>
                <?php foreach ($dupKeys as $row): ?>
                    <div>Duplicate invoice key <?= h($row['invoice_key']) ?>: <?= (int)$row['duplicate_count'] ?> invoices</div>
                <?php endforeach; ?>
                <?php foreach ($dupLines as $row): ?>
                    <div>Duplicate obligation line #<?= (int)$row['obligation_id'] ?>: <?= (int)$row['duplicate_count'] ?> invoice lines</div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>

