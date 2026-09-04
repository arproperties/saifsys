<?php
/**
 * Security deposit review.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/security_deposit_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function sd_money($n): string { return number_format((float)$n, 2); }
function sd_issue_amount(array $row): string {
    foreach (['amount', 'expected', 'total_amount', 'amount_allocated', 'received', 'liability_posted', 'refundable'] as $key) {
        if (isset($row[$key]) && is_numeric($row[$key])) {
            return sd_money($row[$key]) . ' AED';
        }
    }
    return '-';
}
function sd_issue_details(string $key, array $row): string {
    switch ($key) {
        case 'missing_obligation':
            return 'This lease has a security deposit amount, but no deposit obligation was created.';
        case 'obligation_no_receipt':
            return 'Deposit obligation exists, but no receipt has been allocated to it yet.';
        case 'received_no_liability_journal':
            return 'Deposit receipt allocation exists, but the liability journal was not posted.';
        case 'liability_without_receipt':
            return 'A security deposit liability journal exists, but the related receipt record is missing.';
        case 'refund_without_settlement':
            return 'A deposit refund exists without an approved deposit settlement record.';
        case 'deduction_without_approval':
            return 'A deposit deduction is still in draft and needs review or approval.';
        case 'mismatches':
            return 'Expected, received, and liability-posted amounts do not match. Expected ' . sd_money($row['expected'] ?? 0) . ' AED, received ' . sd_money($row['received'] ?? 0) . ' AED, liability posted ' . sd_money($row['liability_posted'] ?? 0) . ' AED.';
        default:
            return 'Review this deposit record.';
    }
}
function sd_issue_action_text(string $key): string {
    $actions = [
        'missing_obligation' => 'Open the lease and regenerate/refresh obligations if the deposit should be collected.',
        'obligation_no_receipt' => 'Allocate a cleared receipt to the deposit obligation when payment is received.',
        'received_no_liability_journal' => 'Review receipt allocation and repost the security deposit liability if needed.',
        'liability_without_receipt' => 'Review the journal and receipt history before making corrections.',
        'refund_without_settlement' => 'Open the lease or move-out workflow and complete the deposit settlement.',
        'deduction_without_approval' => 'Review the deduction and approve, reject, or update it.',
        'mismatches' => 'Open the lease and compare receipt allocation, liability posting, and refund/deduction history.',
    ];
    return $actions[$key] ?? 'Open the lease and review the deposit activity.';
}

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 1;
$leaseId = !empty($_GET['lease_id']) ? (int)$_GET['lease_id'] : 0;
$diagnostics = re_sd_diagnostics($conn, $companyId);
$summary = $leaseId > 0 ? re_sd_summary($conn, $companyId, $leaseId) : null;

$pageTitle = 'Security Deposit Review';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label"><i class="bi bi-shield-check"></i> Security Deposit Review</div>
    <?php if ($leaseId): ?><a href="../lease_view.php?id=<?= (int)$leaseId ?>" class="btn btn-outline-secondary">Back to Lease</a><?php endif; ?>
</div>

<?php if ($summary): ?>
<div class="card card-round mb-4 border-info">
    <div class="card-header"><strong>Lease <?= h($summary['lease']['lease_number'] ?? ('#' . $leaseId)) ?></strong></div>
    <div class="card-body">
        <div class="row g-3 text-center">
            <div class="col-md-2"><div class="border rounded p-2"><div class="small text-muted">Expected</div><strong><?= sd_money($summary['expected']) ?></strong></div></div>
            <div class="col-md-2"><div class="border rounded p-2"><div class="small text-muted">Obligation</div><strong><?= sd_money($summary['obligation_amount']) ?></strong></div></div>
            <div class="col-md-2"><div class="border rounded p-2"><div class="small text-muted">Received</div><strong><?= sd_money($summary['received']) ?></strong></div></div>
            <div class="col-md-2"><div class="border rounded p-2"><div class="small text-muted">Liability Posted</div><strong><?= sd_money($summary['liability_posted']) ?></strong></div></div>
            <div class="col-md-2"><div class="border rounded p-2"><div class="small text-muted">Refundable</div><strong><?= sd_money($summary['refundable']) ?></strong></div></div>
            <div class="col-md-2"><div class="border rounded p-2"><div class="small text-muted">Status</div><strong><?= h(ucwords(str_replace('_', ' ', $summary['status']))) ?></strong></div></div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$sections = [
    'missing_obligation' => 'Deposit Expected, But No Obligation',
    'obligation_no_receipt' => 'Deposit Waiting For Receipt Allocation',
    'received_no_liability_journal' => 'Receipt Allocated, Liability Not Posted',
    'liability_without_receipt' => 'Liability Posted, Receipt Missing',
    'refund_without_settlement' => 'Refund Missing Settlement Approval',
    'deduction_without_approval' => 'Deductions Waiting For Approval',
    'mismatches' => 'Amount Mismatches',
];
$totalIssues = array_sum(array_map(static fn($rows) => is_array($rows) ? count($rows) : 0, $diagnostics));
?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card card-round h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Items Needing Attention</div>
                <div class="h3 mb-0 <?= $totalIssues > 0 ? 'text-warning' : 'text-success' ?>"><?= (int)$totalIssues ?></div>
                <small class="text-muted"><?= $totalIssues > 0 ? 'Review the sections below.' : 'No deposit issues found.' ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card card-round h-100">
            <div class="card-body">
                <div class="fw-semibold mb-1">What This Page Shows</div>
                <div class="text-muted">This page highlights security deposit records that may need operational review, such as missing receipt allocation, missing liability posting, pending deductions, or amount mismatches.</div>
            </div>
        </div>
    </div>
</div>

<?php foreach ($sections as $key => $title): ?>
<div class="card card-round mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><?= h($title) ?></h6>
        <span class="badge bg-<?= empty($diagnostics[$key]) ? 'success' : 'warning text-dark' ?>"><?= count($diagnostics[$key] ?? []) ?></span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($diagnostics[$key])): ?>
            <div class="text-center text-muted py-3">No items found.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>Lease</th><th>What It Means</th><th>Suggested Action</th><th class="text-end">Amount</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($diagnostics[$key] as $row): ?>
                        <?php $rowLeaseId = (int)($row['lease_id'] ?? $row['id'] ?? 0); ?>
                        <tr>
                            <td><?= h($row['lease_number'] ?? ('#' . $rowLeaseId)) ?></td>
                            <td><?= h(sd_issue_details($key, $row)) ?></td>
                            <td><?= h(sd_issue_action_text($key)) ?></td>
                            <td class="text-end"><?= h(sd_issue_amount($row)) ?></td>
                            <td><?php if ($rowLeaseId): ?><a href="../lease_view.php?id=<?= $rowLeaseId ?>" class="btn btn-sm btn-outline-primary">Open Lease</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
