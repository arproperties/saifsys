<?php
/**
 * Receipt allocation diagnostics.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/receipt_allocation_engine.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function p4d_money($n): string { return number_format((float)$n, 2); }

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 1;
$leaseId = (int)($_GET['lease_id'] ?? 0);
$diagnostics = re_receipt_diagnostics($conn, $companyId, $leaseId ?: null);

$pageTitle = 'Receipt Diagnostics';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">
        <i class="bi bi-clipboard-data"></i> Receipt Diagnostics
    </div>
    <span class="badge bg-secondary">Read-only</span>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($diagnostics['receipt_mode_counts'] as $row): ?>
        <div class="col-md-4">
            <div class="card card-round h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase"><?= h(ucfirst((string)$row['mode'])) ?> Receipts</div>
                    <h4 class="mb-0"><?= (int)$row['receipt_count'] ?></h4>
                    <small class="text-muted">AED <?= p4d_money($row['total_amount']) ?></small>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($diagnostics['receipt_mode_counts'])): ?>
        <div class="col-12"><div class="alert alert-secondary">No receipt/payment rows found.</div></div>
    <?php endif; ?>
</div>

<div class="card card-round mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Invoice Mode Receipt Register</h6>
        <span class="badge bg-light text-dark"><?= count($diagnostics['invoice_mode_receipts']) ?> receipt<?= count($diagnostics['invoice_mode_receipts']) === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Receipt</th>
                        <th>Lease</th>
                        <th>Cleared</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th>Reference</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end">Allocated</th>
                        <th class="text-end">Tenant Credit</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($diagnostics['invoice_mode_receipts'] as $row): ?>
                    <tr>
                        <td>
                            <a href="../payment_view.php?id=<?= (int)$row['id'] ?>" class="fw-semibold text-decoration-none">
                                <?= h($row['receipt_number'] ?: ('#' . $row['id'])) ?>
                            </a>
                        </td>
                        <td><?= h($row['lease_number']) ?></td>
                        <td><?= h($row['cleared_date']) ?></td>
                        <td><?= h(ucwords(str_replace('_', ' ', (string)$row['receipt_source']))) ?></td>
                        <td><span class="badge bg-secondary"><?= h($row['allocation_status'] ?: 'unallocated') ?></span></td>
                        <td><?= h($row['reference_number'] ?: '-') ?></td>
                        <td class="text-end"><?= p4d_money($row['amount']) ?></td>
                        <td class="text-end"><?= p4d_money($row['allocated_amount']) ?></td>
                        <td class="text-end"><?= p4d_money($row['tenant_credit_amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($diagnostics['invoice_mode_receipts'])): ?>
                    <tr><td colspan="9" class="text-center text-muted py-3">No Invoice Mode receipts found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card card-round mb-4">
    <div class="card-header bg-white"><h6 class="mb-0">Unallocated / Partially Allocated Invoice Mode Receipts</h6></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Receipt</th><th>Lease</th><th>Cleared</th><th>Source</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($diagnostics['unallocated_receipts'] as $row): ?>
                    <tr>
                        <td><a href="../payment_view.php?id=<?= (int)$row['id'] ?>" class="fw-semibold text-decoration-none"><?= h($row['receipt_number'] ?: ('#' . $row['id'])) ?></a></td>
                        <td><?= h($row['lease_number']) ?></td>
                        <td><?= h($row['cleared_date']) ?></td>
                        <td><?= h($row['receipt_source']) ?></td>
                        <td><?= h($row['allocation_status']) ?></td>
                        <td class="text-end"><?= p4d_money($row['amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($diagnostics['unallocated_receipts'])): ?><tr><td colspan="6" class="text-center text-muted py-3">None.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card card-round h-100">
            <div class="card-header bg-white"><h6 class="mb-0">Receipts Allocated To Invoices</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Receipt</th><th>Invoice</th><th>Cleared</th><th class="text-end">Allocated</th></tr></thead>
                        <tbody>
                        <?php foreach ($diagnostics['invoice_allocations'] as $row): ?>
                            <tr>
                                <td><a href="../payment_view.php?id=<?= (int)$row['payment_id'] ?>" class="fw-semibold text-decoration-none"><?= h($row['receipt_number']) ?></a></td>
                                <td><?= h($row['invoice_number']) ?></td>
                                <td><?= h($row['cleared_date']) ?></td>
                                <td class="text-end"><?= p4d_money($row['amount_allocated']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($diagnostics['invoice_allocations'])): ?><tr><td colspan="4" class="text-center text-muted py-3">None.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card card-round h-100">
            <div class="card-header bg-white"><h6 class="mb-0">Receipts Allocated To Obligations</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Receipt</th><th>Obligation</th><th>Cleared</th><th class="text-end">Allocated</th></tr></thead>
                        <tbody>
                        <?php foreach ($diagnostics['obligation_allocations'] as $row): ?>
                            <tr>
                                <td><a href="../payment_view.php?id=<?= (int)$row['payment_id'] ?>" class="fw-semibold text-decoration-none"><?= h($row['receipt_number']) ?></a></td>
                                <td><?= h($row['obligation_type'] . ' #' . $row['obligation_id']) ?></td>
                                <td><?= h($row['cleared_date']) ?></td>
                                <td class="text-end"><?= p4d_money($row['amount_allocated']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($diagnostics['obligation_allocations'])): ?><tr><td colspan="4" class="text-center text-muted py-3">None.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-6">
        <div class="card card-round h-100">
            <div class="card-header bg-white"><h6 class="mb-0">Overpayments / Tenant Credit</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Receipt</th><th>Cleared</th><th class="text-end">Credit</th></tr></thead>
                        <tbody>
                        <?php foreach ($diagnostics['tenant_credit_allocations'] as $row): ?>
                            <tr>
                                <td><a href="../payment_view.php?id=<?= (int)$row['payment_id'] ?>" class="fw-semibold text-decoration-none"><?= h($row['receipt_number']) ?></a></td>
                                <td><?= h($row['cleared_date']) ?></td>
                                <td class="text-end"><?= p4d_money($row['amount_allocated']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($diagnostics['tenant_credit_allocations'])): ?><tr><td colspan="3" class="text-center text-muted py-3">None.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card card-round h-100">
            <div class="card-header bg-white"><h6 class="mb-0">Bounced Cheque Receipt Flags</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Receipt</th><th>Cheque</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                        <?php foreach ($diagnostics['bounced_cheque_receipt_flags'] as $row): ?>
                            <tr class="table-warning">
                                <td><a href="../payment_view.php?id=<?= (int)$row['id'] ?>" class="fw-semibold text-decoration-none"><?= h($row['receipt_number']) ?></a></td>
                                <td>#<?= (int)$row['cheque_id'] ?></td>
                                <td><?= h($row['cheque_status']) ?></td>
                                <td class="text-end"><?= p4d_money($row['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($diagnostics['bounced_cheque_receipt_flags'])): ?><tr><td colspan="4" class="text-center text-muted py-3">None.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>

