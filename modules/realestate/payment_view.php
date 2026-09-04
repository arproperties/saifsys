<?php
/**
 * Real Estate Module - Payment View
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/payment_allocation_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

$paymentId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$paymentId) {
    header('Location: payments.php');
    exit;
}

// Get payment details (include tenant_id for allocation and bank account if set)
$stmt = $conn->prepare("
    SELECT p.*, 
           l.lease_number, l.monthly_rent, l.start_date, l.end_date, l.tenant_id,
           u.unit_number, u.unit_type,
           b.name as building_name,
           t.first_name, t.last_name, t.company_name, t.tenant_type, t.phone, t.email,
           li.installment_date, li.amount as installment_amount, li.status as installment_status,
           u2.username as created_by_name,
           ba.account_name as bank_account_name, ba.bank_name as bank_name, coa.account_code as bank_account_code, coa.account_name as bank_account_gl_name
    FROM re_payments p
    JOIN re_leases l ON l.id = p.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_lease_installments li ON li.id = p.installment_id
    LEFT JOIN user u2 ON u2.id = p.created_by
    LEFT JOIN re_bank_accounts ba ON ba.id = p.bank_account_id AND ba.company_id = p.company_id
    LEFT JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id
    WHERE p.id = ? AND p.company_id = ?
");
$stmt->execute([$paymentId, $currentCompanyId]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

// Calculate outstanding balance if linked to single installment (legacy)
$outstandingBalance = 0;
if ($payment && $payment['installment_id']) {
    $installmentAmount = (float)$payment['installment_amount'];
    $totalPaid = get_installment_total_paid($conn, (int)$payment['installment_id']);
    $outstandingBalance = max(0, $installmentAmount - $totalPaid);
}

// Allocation details: what this payment was for (installments + credit applied/advance)
$allocations = [];
$appliedCredit = 0.0;
$advanceToCredit = 0.0;
if ($payment && payment_allocation_tables_exist($conn)) {
    $stmt = $conn->prepare("
        SELECT pa.installment_id, pa.amount_allocated,
               li.installment_date, li.amount as installment_amount, li.status as inst_status, li.lease_id
        FROM re_payment_allocations pa
        JOIN re_lease_installments li ON li.id = pa.installment_id
        WHERE pa.payment_id = ?
        ORDER BY li.installment_date
    ");
    $stmt->execute([$paymentId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $instId = (int)$row['installment_id'];
        $leaseId = (int)$row['lease_id'];
        $totalPaidInst = get_installment_total_paid($conn, $instId);
        $outstanding = max(0, (float)$row['installment_amount'] - $totalPaidInst);
        $chequeNumber = '';
        $chq = $conn->prepare("
            SELECT COALESCE(c.cheque_number, lc.cheque_number, '') as chq
            FROM re_post_dated_cheques c
            LEFT JOIN re_lease_cheques lc ON lc.installment_id = c.installment_id AND lc.lease_id = c.lease_id
            WHERE c.installment_id = ? AND c.lease_id = ?
            LIMIT 1
        ");
        $chq->execute([$instId, $leaseId]);
        if ($r = $chq->fetch(PDO::FETCH_ASSOC)) {
            $chequeNumber = trim($r['chq'] ?? '');
        }
        if ($chequeNumber === '' && $leaseId) {
            $chq2 = $conn->prepare("SELECT cheque_number FROM re_lease_cheques WHERE installment_id = ? AND lease_id = ? LIMIT 1");
            $chq2->execute([$instId, $leaseId]);
            if ($r2 = $chq2->fetch(PDO::FETCH_ASSOC)) {
                $chequeNumber = trim($r2['cheque_number'] ?? '');
            }
        }
        $allocations[] = [
            'installment_date' => $row['installment_date'],
            'cheque_number' => $chequeNumber,
            'amount_allocated' => (float)$row['amount_allocated'],
            'installment_amount' => (float)$row['installment_amount'],
            'status' => $row['inst_status'],
            'outstanding' => $outstanding,
        ];
    }
    $stmt = $conn->prepare("
        SELECT type, COALESCE(SUM(amount_aed), 0) as total
        FROM re_tenant_credit_transactions WHERE payment_id = ? GROUP BY type
    ");
    $stmt->execute([$paymentId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['type'] === 'debit') {
            $appliedCredit = (float)$row['total'];
        } else {
            $advanceToCredit = (float)$row['total'];
        }
    }
}

$receiptAllocations = [];
$receiptAllocatedTotal = 0.0;
$receiptTenantCreditTotal = 0.0;
try {
    $stmt = $conn->prepare("
        SELECT ra.*,
               i.invoice_number,
               i.due_date AS invoice_due_date,
               i.total_amount AS invoice_total,
               i.paid_amount AS invoice_paid,
               i.outstanding_amount AS invoice_outstanding,
               inv_items.item_names AS invoice_items,
               o.obligation_type,
               o.description AS obligation_description,
               o.due_date AS obligation_due_date,
               o.total_amount AS obligation_total,
               o.allocated_amount AS obligation_allocated
        FROM re_receipt_allocations ra
        LEFT JOIN re_invoices i ON i.id = ra.invoice_id AND i.company_id = ra.company_id
        LEFT JOIN (
            SELECT invoice_id, company_id, GROUP_CONCAT(item_name ORDER BY display_order, id SEPARATOR ', ') AS item_names
            FROM re_invoice_items
            GROUP BY company_id, invoice_id
        ) inv_items ON inv_items.invoice_id = i.id AND inv_items.company_id = i.company_id
        LEFT JOIN re_obligations o ON o.id = ra.obligation_id AND o.company_id = ra.company_id
        WHERE ra.company_id = ? AND ra.payment_id = ?
        ORDER BY COALESCE(i.due_date, o.due_date, ra.created_at), ra.id
    ");
    $stmt->execute([$currentCompanyId, $paymentId]);
    $receiptAllocations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($receiptAllocations as $allocation) {
        $receiptAllocatedTotal += (float)($allocation['amount_allocated'] ?? 0);
        if (($allocation['target_type'] ?? '') === 'tenant_credit') {
            $receiptTenantCreditTotal += (float)($allocation['amount_allocated'] ?? 0);
        }
    }
} catch (Throwable $e) {
    $receiptAllocations = [];
}

$multiChequeLinks = [];
try {
    require_once __DIR__ . '/includes/receipt_multi_cheque_helper.php';
    $multiChequeLinks = re_receipt_load_cheque_links_for_payment($conn, $currentCompanyId, $paymentId);
} catch (Throwable $e) {
    $multiChequeLinks = [];
}

if (!$payment) {
    header('Location: payments.php');
    exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Payment Details';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h1>Payment Receipt <?= h($payment['receipt_number'] ?: '#' . $payment['id']) ?></h1>
            <div class="d-flex gap-2 flex-wrap">
                <a href="payment_receipt.php?id=<?= (int)$payment['id'] ?>" target="_blank" class="btn btn-outline-primary">
                    <i class="bi bi-download"></i> Download receipt
                </a>
                <a href="payment_edit.php?id=<?= (int)$payment['id'] ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-pencil"></i> Edit Payment
                </a>
                <?php if ($payment['installment_id'] && $outstandingBalance > 0): ?>
                    <a href="payment_add.php?lease_id=<?= $payment['lease_id'] ?>&installment_id=<?= $payment['installment_id'] ?>&collect_balance=1" class="btn btn-warning">
                        <i class="bi bi-cash-coin"></i> Collect Outstanding Balance
                    </a>
                <?php endif; ?>
                <a href="lease_view.php?id=<?= (int)$payment['lease_id'] ?>" class="btn btn-outline-dark">
                    <i class="bi bi-arrow-left"></i> Back to Lease
                </a>
            </div>
        </div>

        <div class="row">
            <!-- Payment Information -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Payment Information</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <th width="40%">Receipt Number:</th>
                                <td><?= h($payment['receipt_number'] ?: '#' . $payment['id']) ?></td>
                            </tr>
                            <tr>
                                <th>Payment Date:</th>
                                <td><?= date('Y-m-d', strtotime($payment['payment_date'])) ?></td>
                            </tr>
                            <?php if (!empty($payment['cleared_date'])): ?>
                            <tr>
                                <th>Cleared Date:</th>
                                <td><?= date('Y-m-d', strtotime($payment['cleared_date'])) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Amount:</th>
                                <td><strong class="fs-4"><?= number_format($payment['amount'], 2) ?> AED</strong></td>
                            </tr>
                            <?php if ($payment['installment_id'] && $outstandingBalance > 0): ?>
                            <tr>
                                <th>Outstanding Balance:</th>
                                <td><strong class="fs-5 text-danger"><?= number_format($outstandingBalance, 2) ?> AED</strong></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Payment Method:</th>
                                <td><?= ucfirst(str_replace('_', ' ', $payment['payment_method'])) ?></td>
                            </tr>
                            <?php if (!empty($payment['accounting_mode'])): ?>
                            <tr>
                                <th>Accounting Mode:</th>
                                <td><span class="badge bg-<?= $payment['accounting_mode'] === 'invoice' ? 'primary' : 'secondary' ?>"><?= h(ucfirst($payment['accounting_mode'])) ?></span></td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($payment['receipt_source'])): ?>
                            <tr>
                                <th>Receipt Source:</th>
                                <td><?= h(ucwords(str_replace('_', ' ', $payment['receipt_source']))) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($payment['receipt_status'])): ?>
                            <tr>
                                <th>Receipt Status:</th>
                                <td><span class="badge bg-<?= $payment['receipt_status'] === 'cleared' ? 'success' : 'secondary' ?>"><?= h(ucfirst($payment['receipt_status'])) ?></span></td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($payment['allocation_status'])):
                                $allocBadge = re_payment_allocation_status_badge($conn, $currentCompanyId, $payment);
                            ?>
                            <tr>
                                <th>Allocation Status:</th>
                                <td>
                                    <span class="badge bg-<?= h($allocBadge['class']) ?>"<?= $allocBadge['title'] !== '' ? ' title="' . h($allocBadge['title']) . '"' : '' ?>>
                                        <?= h($allocBadge['label']) ?>
                                    </span>
                                    <?php if ($allocBadge['title'] !== ''): ?>
                                        <div class="small text-muted mt-1"><?= h($allocBadge['title']) ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($multiChequeLinks)): ?>
                            <tr>
                                <th>Linked Cheques:</th>
                                <td>
                                    <?php foreach ($multiChequeLinks as $link): ?>
                                        <div>
                                            <a href="billing_cheque_view.php?id=<?= (int)$link['cheque_id'] ?>">
                                                <?= h($link['cheque_number'] ?: ('#' . $link['cheque_id'])) ?>
                                            </a>
                                            <span class="text-muted">· <?= number_format((float)$link['amount_applied'], 2) ?> AED</span>
                                            <span class="badge bg-light text-dark border"><?= h(ucfirst((string)($link['status'] ?? ''))) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="small text-muted mt-1">Multi-cheque receipt (one payment linked to multiple schedule instruments).</div>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Reference Number:</th>
                                <td><?= h($payment['reference_number'] ?: '-') ?></td>
                            </tr>
                            <?php
                            $bankLabel = '—';
                            $bankAccountId = isset($payment['bank_account_id']) ? (int)$payment['bank_account_id'] : 0;
                            if ($bankAccountId > 0) {
                                $code = trim($payment['bank_account_code'] ?? '');
                                $name = trim($payment['bank_account_gl_name'] ?? $payment['bank_account_name'] ?? '');
                                $bank = trim($payment['bank_name'] ?? '');
                                if ($code !== '' || $name !== '' || $bank !== '') {
                                    $bankLabel = $code . ($code && ($name || $bank) ? ' - ' : '') . $name . ($bank ? ' (' . $bank . ')' : '');
                                } else {
                                    $bankLabel = 'Bank account #' . $bankAccountId;
                                }
                            }
                            ?>
                            <tr>
                                <th>Bank Account:</th>
                                <td><?= h($bankLabel) ?></td>
                            </tr>
                            <?php if (!$allocations && $payment['installment_date']): ?>
                            <tr>
                                <th>For Installment:</th>
                                <td><?= date('Y-m-d', strtotime($payment['installment_date'])) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Recorded By:</th>
                                <td><?= h($payment['created_by_name'] ?: 'System') ?></td>
                            </tr>
                            <tr>
                                <th>Recorded At:</th>
                                <td><?= date('Y-m-d H:i', strtotime($payment['created_at'])) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Lease Information -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Lease Information</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <th width="40%">Lease Number:</th>
                                <td><?= h($payment['lease_number'] ?: 'L-' . $payment['lease_id']) ?></td>
                            </tr>
                            <tr>
                                <th>Unit:</th>
                                <td><?= h($payment['building_name'] . ' - ' . $payment['unit_number']) ?></td>
                            </tr>
                            <tr>
                                <th>Monthly Rent:</th>
                                <td><?= number_format($payment['monthly_rent'], 2) ?> AED</td>
                            </tr>
                            <tr>
                                <th>Lease Period:</th>
                                <td><?= date('Y-m-d', strtotime($payment['start_date'])) ?> to <?= date('Y-m-d', strtotime($payment['end_date'])) ?></td>
                            </tr>
                        </table>
                        <a href="lease_view.php?id=<?= $payment['lease_id'] ?>" class="btn btn-sm btn-outline-primary">
                            View Lease Details
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($receiptAllocations)): ?>
        <div class="card mb-4 border-primary">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-diagram-3"></i> Invoice Mode Allocation Details</h5>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-3 text-center">
                    <div class="col-md-4">
                        <div class="border rounded p-2">
                            <div class="small text-muted">Receipt Amount</div>
                            <strong><?= number_format((float)$payment['amount'], 2) ?> AED</strong>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-2">
                            <div class="small text-muted">Allocated</div>
                            <strong class="text-success"><?= number_format($receiptAllocatedTotal, 2) ?> AED</strong>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-2">
                            <div class="small text-muted">Tenant Credit</div>
                            <strong class="<?= $receiptTenantCreditTotal > 0.005 ? 'text-info' : '' ?>"><?= number_format($receiptTenantCreditTotal, 2) ?> AED</strong>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Target</th>
                                <th>Due Date</th>
                                <th>Description</th>
                                <th class="text-end">Allocated</th>
                                <th class="text-end">Target Total</th>
                                <th class="text-end">Remaining</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($receiptAllocations as $allocation): ?>
                                <?php
                                    $targetType = (string)($allocation['target_type'] ?? '');
                                    $targetLabel = 'Tenant Credit';
                                    $targetUrl = '';
                                    $dueDate = '-';
                                    $desc = 'Unallocated balance credited to tenant';
                                    $targetTotal = (float)$payment['amount'];
                                    $remaining = 0.0;
                                    if ($targetType === 'invoice') {
                                        $targetLabel = 'Invoice ' . (string)($allocation['invoice_number'] ?? ('#' . $allocation['invoice_id']));
                                        $targetUrl = !empty($allocation['invoice_id']) ? 'billing_invoice_view.php?id=' . (int)$allocation['invoice_id'] : '';
                                        $dueDate = $allocation['invoice_due_date'] ?: '-';
                                        $desc = $allocation['invoice_items'] ?: 'Issued invoice';
                                        $targetTotal = (float)($allocation['invoice_total'] ?? 0);
                                        $remaining = (float)($allocation['invoice_outstanding'] ?? 0);
                                    } elseif ($targetType === 'obligation') {
                                        $targetLabel = 'Obligation #' . (int)$allocation['obligation_id'];
                                        $dueDate = $allocation['obligation_due_date'] ?: '-';
                                        $desc = trim(ucwords(str_replace('_', ' ', (string)$allocation['obligation_type'])) . ' - ' . (string)$allocation['obligation_description'], ' -');
                                        $targetTotal = (float)($allocation['obligation_total'] ?? 0);
                                        $remaining = max(0, $targetTotal - (float)($allocation['obligation_allocated'] ?? 0));
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-<?= $targetType === 'invoice' ? 'primary' : ($targetType === 'obligation' ? 'warning text-dark' : 'info') ?>"><?= h(ucwords(str_replace('_', ' ', $targetType))) ?></span>
                                        <?php if ($targetUrl): ?>
                                            <a href="<?= h($targetUrl) ?>" class="ms-1"><?= h($targetLabel) ?></a>
                                        <?php else: ?>
                                            <span class="ms-1"><?= h($targetLabel) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($dueDate) ?></td>
                                    <td><?= h($desc ?: '-') ?></td>
                                    <td class="text-end text-success fw-semibold"><?= number_format((float)$allocation['amount_allocated'], 2) ?> AED</td>
                                    <td class="text-end"><?= number_format($targetTotal, 2) ?> AED</td>
                                    <td class="text-end"><?= number_format($remaining, 2) ?> AED</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($allocations) || $appliedCredit > 0 || $advanceToCredit > 0): ?>
        <!-- Payment allocation: what this payment was for (installments, cheque, balance, advance) -->
        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="bi bi-wallet2"></i> Payment allocation & purpose</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($allocations)): ?>
                <p class="text-muted small mb-3">This payment was allocated to the following installments (cheques):</p>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Installment date</th>
                                <th>Cheque #</th>
                                <th>Amount allocated (AED)</th>
                                <th>Installment total (AED)</th>
                                <th>Status</th>
                                <th>Outstanding after (AED)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allocations as $a): ?>
                            <tr>
                                <td><?= date('Y-m-d', strtotime($a['installment_date'])) ?></td>
                                <td><?= $a['cheque_number'] !== '' ? h($a['cheque_number']) : '—' ?></td>
                                <td><?= number_format($a['amount_allocated'], 2) ?></td>
                                <td><?= number_format($a['installment_amount'], 2) ?></td>
                                <td><span class="badge bg-<?= $a['status'] === 'paid' ? 'success' : ($a['status'] === 'partial' ? 'warning' : 'secondary') ?>"><?= h($a['status']) ?></span></td>
                                <td><?= number_format($a['outstanding'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                <?php if ($appliedCredit > 0): ?>
                <p class="mb-0 mt-2"><strong>Tenant credit applied:</strong> <?= number_format($appliedCredit, 2) ?> AED (used from tenant advance balance)</p>
                <?php endif; ?>
                <?php if ($advanceToCredit > 0): ?>
                <p class="mb-0 mt-1"><strong>Amount to tenant credit (advance):</strong> <?= number_format($advanceToCredit, 2) ?> AED (overpayment held for future use)</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tenant Information -->
        <div class="card mb-4">
            <div class="card-header">
                <h5>Tenant Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="20%">Name:</th>
                        <td><?= h($payment['first_name'] . ' ' . $payment['last_name']) ?></td>
                        <th width="20%">Phone:</th>
                        <td><?= h($payment['phone'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <th>Email:</th>
                        <td><?= h($payment['email'] ?: '-') ?></td>
                        <th></th>
                        <td></td>
                    </tr>
                </table>
                <a href="tenant_view.php?id=<?= $payment['tenant_id'] ?? '' ?>" class="btn btn-sm btn-outline-primary">
                    View Tenant Details
                </a>
            </div>
        </div>

        <!-- Notes -->
        <?php if ($payment['notes']): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5>Notes</h5>
            </div>
            <div class="card-body">
                <p><?= nl2br(h($payment['notes'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

