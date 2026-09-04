<?php
/**
 * Real Estate Module - Tenant View
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

$tenantId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$tenantId) {
    header('Location: tenants.php');
    exit;
}

// Get tenant details
$stmt = $conn->prepare("SELECT * FROM re_tenants WHERE id = ? AND company_id = ?");
$stmt->execute([$tenantId, $currentCompanyId]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    header('Location: tenants.php');
    exit;
}

// Get all leases (past and current)
$stmt = $conn->prepare("
    SELECT l.*, 
           u.unit_number, u.unit_type, u.premises_number,
           b.name as building_name
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    WHERE l.tenant_id = ? AND l.company_id = ?
    ORDER BY l.start_date DESC
");
$stmt->execute([$tenantId, $currentCompanyId]);
$leases = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get previous units history
$stmt = $conn->prepare("
    SELECT tuh.*,
           u.unit_number, u.unit_type, u.premises_number,
           b.name as building_name,
           l.lease_number
    FROM re_tenant_unit_history tuh
    JOIN re_units u ON u.id = tuh.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_leases l ON l.id = tuh.lease_id
    WHERE tuh.tenant_id = ?
    ORDER BY tuh.move_in_date DESC
");
$stmt->execute([$tenantId]);
$unitHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Legacy Mode installments for this tenant using allocation-based payment tracking.
// Invoice Mode leases use invoices/receipts for accounting; installments are operational schedules only.
$stmt = $conn->prepare("
    SELECT 
        li.id,
        li.lease_id,
        li.installment_date,
        li.amount AS due_amount,
        li.status,
        pdc.cheque_number,
        l.lease_number,
        l.id AS lease_id_val,
        COALESCE(SUM(pa.amount_allocated), 0) AS allocated_amount,
        MIN(p.payment_date) AS first_payment_date,
        MAX(p.payment_date) AS last_payment_date,
        MIN(DATEDIFF(p.payment_date, li.installment_date)) AS days_diff_first
    FROM re_lease_installments li
    JOIN re_leases l ON l.id = li.lease_id
    LEFT JOIN re_post_dated_cheques pdc ON pdc.installment_id = li.id
    LEFT JOIN re_payment_allocations pa ON pa.installment_id = li.id
    LEFT JOIN re_payments p ON p.id = pa.payment_id
    WHERE l.tenant_id = ? AND l.company_id = ? AND COALESCE(l.accounting_mode, 'legacy') = 'legacy'
    GROUP BY li.id
    ORDER BY li.installment_date DESC
");
$stmt->execute([$tenantId, $currentCompanyId]);
$allInstallments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get penalty billing items across all leases
$stmt = $conn->prepare("
    SELECT bi.*, l.lease_number, l.id AS lease_id_val,
           li.installment_date AS linked_installment_date,
           pdc.cheque_number AS linked_cheque_number,
           p.payment_date AS paid_payment_date,
           p.receipt_number AS paid_receipt
    FROM re_billing_items bi
    JOIN re_leases l ON l.id = bi.lease_id
    LEFT JOIN re_lease_installments li ON li.id = bi.installment_id
    LEFT JOIN re_post_dated_cheques pdc ON pdc.installment_id = bi.installment_id
    LEFT JOIN re_payments p ON p.id = bi.payment_id
    WHERE l.tenant_id = ? AND bi.company_id = ? AND bi.item_type = 'penalty'
    ORDER BY bi.due_date DESC
");
$stmt->execute([$tenantId, $currentCompanyId]);
$allPenalties = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Aggregate penalty stats
$penaltySummary = [
    'total_count'  => count($allPenalties),
    'unpaid_count' => 0,
    'waived_count' => 0,
    'paid_count'   => 0,
    'total_amount' => 0,
    'outstanding'  => 0,
    'collected'    => 0,
];
foreach ($allPenalties as $pen) {
    $penaltySummary['total_amount'] += (float)$pen['total_amount'];
    if (!empty($pen['is_waived'])) {
        $penaltySummary['waived_count']++;
    } elseif (!empty($pen['is_paid'])) {
        $penaltySummary['paid_count']++;
        $penaltySummary['collected'] += (float)$pen['paid_amount'];
    } else {
        $penaltySummary['unpaid_count']++;
        $penaltySummary['outstanding'] += (float)$pen['total_amount'];
    }
}

// Get tenant credit balance (across all leases)
$tenantCreditBal = 0.0;
try {
    $stmtCr = $conn->prepare("SELECT balance_aed FROM re_tenant_credit_balances WHERE tenant_id = ? AND company_id = ?");
    $stmtCr->execute([$tenantId, $currentCompanyId]);
    $crRow = $stmtCr->fetch(PDO::FETCH_ASSOC);
    $tenantCreditBal = $crRow ? (float)$crRow['balance_aed'] : 0.0;
} catch (Exception $e) { /* table may not exist */ }

// Invoice Mode accounting summary. These are the official AR balances for invoice-mode leases.
$invoiceModeSummary = [
    'invoice_count' => 0,
    'invoice_total' => 0.0,
    'invoice_paid' => 0.0,
    'invoice_outstanding' => 0.0,
    'open_obligations' => 0,
    'open_obligation_amount' => 0.0,
    'receipt_count' => 0,
    'receipt_total' => 0.0,
    'allocated_total' => 0.0,
];
$recentInvoices = [];
$recentReceipts = [];
try {
    $stmt = $conn->prepare("
        SELECT COUNT(*) invoice_count,
               COALESCE(SUM(CASE WHEN i.status <> 'cancelled' THEN i.total_amount ELSE 0 END),0) invoice_total,
               COALESCE(SUM(CASE WHEN i.status <> 'cancelled' THEN COALESCE(i.paid_amount,0) ELSE 0 END),0) invoice_paid,
               COALESCE(SUM(CASE WHEN i.status <> 'cancelled' THEN COALESCE(i.outstanding_amount,0) ELSE 0 END),0) invoice_outstanding
        FROM re_invoices i
        JOIN re_leases l ON l.id = i.lease_id AND l.company_id = i.company_id
        WHERE i.company_id = ? AND l.tenant_id = ? AND COALESCE(l.accounting_mode, 'legacy') = 'invoice'
    ");
    $stmt->execute([$currentCompanyId, $tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $invoiceModeSummary['invoice_count'] = (int)($row['invoice_count'] ?? 0);
    $invoiceModeSummary['invoice_total'] = (float)($row['invoice_total'] ?? 0);
    $invoiceModeSummary['invoice_paid'] = (float)($row['invoice_paid'] ?? 0);
    $invoiceModeSummary['invoice_outstanding'] = (float)($row['invoice_outstanding'] ?? 0);
} catch (Throwable $e) {}
try {
    $stmt = $conn->prepare("
        SELECT COUNT(*) open_obligations,
               COALESCE(SUM(CASE WHEN o.status IN ('draft','open','partially_allocated') THEN GREATEST(o.total_amount - COALESCE(o.allocated_amount,0),0) ELSE 0 END),0) open_obligation_amount
        FROM re_obligations o
        JOIN re_leases l ON l.id = o.lease_id AND l.company_id = o.company_id
        WHERE o.company_id = ? AND l.tenant_id = ? AND COALESCE(l.accounting_mode, 'legacy') = 'invoice'
    ");
    $stmt->execute([$currentCompanyId, $tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $invoiceModeSummary['open_obligations'] = (int)($row['open_obligations'] ?? 0);
    $invoiceModeSummary['open_obligation_amount'] = (float)($row['open_obligation_amount'] ?? 0);
} catch (Throwable $e) {}
try {
    $stmt = $conn->prepare("
        SELECT COUNT(*) receipt_count,
               COALESCE(SUM(p.amount),0) receipt_total,
               COALESCE(SUM(alloc.allocated_amount),0) allocated_total
        FROM re_payments p
        JOIN re_leases l ON l.id = p.lease_id AND l.company_id = p.company_id
        LEFT JOIN (
            SELECT company_id, payment_id, SUM(amount_allocated) allocated_amount
            FROM re_receipt_allocations
            GROUP BY company_id, payment_id
        ) alloc ON alloc.company_id = p.company_id AND alloc.payment_id = p.id
        WHERE p.company_id = ? AND l.tenant_id = ? AND p.accounting_mode = 'invoice'
          AND COALESCE(p.receipt_status, '') = 'cleared'
    ");
    $stmt->execute([$currentCompanyId, $tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $invoiceModeSummary['receipt_count'] = (int)($row['receipt_count'] ?? 0);
    $invoiceModeSummary['receipt_total'] = (float)($row['receipt_total'] ?? 0);
    $invoiceModeSummary['allocated_total'] = (float)($row['allocated_total'] ?? 0);
} catch (Throwable $e) {}
try {
    $stmt = $conn->prepare("
        SELECT i.id, i.invoice_number, i.invoice_date, i.due_date, i.total_amount, i.paid_amount, i.outstanding_amount, i.status,
               l.lease_number
        FROM re_invoices i
        JOIN re_leases l ON l.id = i.lease_id AND l.company_id = i.company_id
        WHERE i.company_id = ? AND l.tenant_id = ? AND COALESCE(l.accounting_mode, 'legacy') = 'invoice'
        ORDER BY i.invoice_date DESC, i.id DESC
        LIMIT 8
    ");
    $stmt->execute([$currentCompanyId, $tenantId]);
    $recentInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
try {
    $stmt = $conn->prepare("
        SELECT p.id, p.payment_date, p.cleared_date, p.receipt_number, p.amount, p.receipt_source, p.allocation_status,
               COALESCE(alloc.allocated_amount,0) allocated_amount, l.lease_number
        FROM re_payments p
        JOIN re_leases l ON l.id = p.lease_id AND l.company_id = p.company_id
        LEFT JOIN (
            SELECT company_id, payment_id, SUM(amount_allocated) allocated_amount
            FROM re_receipt_allocations
            GROUP BY company_id, payment_id
        ) alloc ON alloc.company_id = p.company_id AND alloc.payment_id = p.id
        WHERE p.company_id = ? AND l.tenant_id = ? AND p.accounting_mode = 'invoice'
          AND COALESCE(p.receipt_status, '') = 'cleared'
        ORDER BY COALESCE(p.cleared_date, p.payment_date) DESC, p.id DESC
        LIMIT 8
    ");
    $stmt->execute([$currentCompanyId, $tenantId]);
    $recentReceipts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// Calculate payment behavior summary from allocations
$paymentSummary = [
    'total_periods'   => 0,
    'total_on_time'   => 0,
    'total_late'      => 0,
    'total_missed'    => 0,
    'total_bounced'   => 0,
    'avg_days_late'   => 0,
    'total_due_amount'=> 0,
    'total_allocated' => 0,
    'total_outstanding'=> 0,
];

$paymentBehavior = [];
$lateDays = [];

if (!empty($allInstallments)) {
    $paymentSummary['total_periods'] = count($allInstallments);

    foreach ($allInstallments as $inst) {
        $due      = (float)$inst['due_amount'];
        $alloc    = (float)$inst['allocated_amount'];
        $status   = $inst['status'];
        $isBounced = ($status === 'bounced');

        $paymentSummary['total_due_amount'] += $due;
        $paymentSummary['total_allocated']  += $alloc;

        if ($isBounced) {
            $paymentSummary['total_bounced']++;
        } elseif ($status === 'paid' || $alloc >= $due) {
            $daysDiff = $inst['days_diff_first'];
            if ($daysDiff !== null && $daysDiff <= 0) {
                $paymentSummary['total_on_time']++;
            } else {
                $paymentSummary['total_late']++;
                if ($daysDiff > 0) $lateDays[] = $daysDiff;
            }
        } elseif ($status === 'overdue' || ($status === 'pending' && strtotime($inst['installment_date']) < time())) {
            $paymentSummary['total_missed']++;
            $paymentSummary['total_outstanding'] += ($due - $alloc);
        } else {
            $paymentSummary['total_outstanding'] += ($due - $alloc);
        }

        // Group by month
        $monthKey = date('Y-m', strtotime($inst['installment_date']));
        if (!isset($paymentBehavior[$monthKey])) {
            $paymentBehavior[$monthKey] = [
                'period_start'    => date('Y-m-01', strtotime($inst['installment_date'])),
                'lease_number'    => $inst['lease_number'],
                'total_due'       => 0,
                'total_allocated' => 0,
                'on_time_payments'=> 0,
                'late_payments'   => 0,
                'missed_payments' => 0,
                'bounced_payments'=> 0,
            ];
        }
        $paymentBehavior[$monthKey]['total_due']       += $due;
        $paymentBehavior[$monthKey]['total_allocated']  += $alloc;

        if ($isBounced) {
            $paymentBehavior[$monthKey]['bounced_payments']++;
        } elseif ($status === 'paid' || $alloc >= $due) {
            $daysDiff = $inst['days_diff_first'];
            if ($daysDiff !== null && $daysDiff <= 0) {
                $paymentBehavior[$monthKey]['on_time_payments']++;
            } else {
                $paymentBehavior[$monthKey]['late_payments']++;
            }
        } elseif ($status === 'overdue' || ($status === 'pending' && strtotime($inst['installment_date']) < time())) {
            $paymentBehavior[$monthKey]['missed_payments']++;
        }
    }

    if (!empty($lateDays)) {
        $paymentSummary['avg_days_late'] = round(array_sum($lateDays) / count($lateDays), 1);
    }

    $paymentBehavior = array_values($paymentBehavior);
    usort($paymentBehavior, function($a, $b) {
        return strcmp($b['period_start'], $a['period_start']);
    });
    $paymentBehavior = array_slice($paymentBehavior, 0, 24);
}

// Per-lease financial summary
$leaseFinancials = [];
foreach ($leases as $ls) {
    $leaseFinancials[$ls['id']] = ['total_due'=>0,'total_allocated'=>0,'outstanding'=>0,'penalties_outstanding'=>0,'mode'=>($ls['accounting_mode'] ?? 'legacy')];
}
foreach ($allInstallments as $inst) {
    $lid = $inst['lease_id'];
    if (isset($leaseFinancials[$lid])) {
        $leaseFinancials[$lid]['total_due']       += (float)$inst['due_amount'];
        $leaseFinancials[$lid]['total_allocated']  += (float)$inst['allocated_amount'];
        $leaseFinancials[$lid]['outstanding']      += max(0, (float)$inst['due_amount'] - (float)$inst['allocated_amount']);
    }
}
try {
    $stmt = $conn->prepare("
        SELECT i.lease_id,
               COALESCE(SUM(CASE WHEN i.status <> 'cancelled' THEN i.total_amount ELSE 0 END),0) total_due,
               COALESCE(SUM(CASE WHEN i.status <> 'cancelled' THEN COALESCE(i.paid_amount,0) ELSE 0 END),0) total_allocated,
               COALESCE(SUM(CASE WHEN i.status <> 'cancelled' THEN COALESCE(i.outstanding_amount,0) ELSE 0 END),0) outstanding
        FROM re_invoices i
        JOIN re_leases l ON l.id = i.lease_id AND l.company_id = i.company_id
        WHERE i.company_id = ? AND l.tenant_id = ? AND COALESCE(l.accounting_mode, 'legacy') = 'invoice'
        GROUP BY i.lease_id
    ");
    $stmt->execute([$currentCompanyId, $tenantId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $lid = (int)$row['lease_id'];
        if (isset($leaseFinancials[$lid])) {
            $leaseFinancials[$lid]['total_due'] = (float)$row['total_due'];
            $leaseFinancials[$lid]['total_allocated'] = (float)$row['total_allocated'];
            $leaseFinancials[$lid]['outstanding'] = (float)$row['outstanding'];
            $leaseFinancials[$lid]['mode'] = 'invoice';
        }
    }
} catch (Throwable $e) {}
foreach ($allPenalties as $pen) {
    $lid = $pen['lease_id'];
    if (isset($leaseFinancials[$lid]) && empty($pen['is_waived']) && empty($pen['is_paid'])) {
        $leaseFinancials[$lid]['penalties_outstanding'] += (float)$pen['total_amount'];
    }
}

// Get issues/violations
$stmt = $conn->prepare("
    SELECT iv.*,
           u.unit_number, b.name as building_name,
           l.lease_number,
           u1.username as reported_by_name,
           u2.username as resolved_by_name
    FROM re_tenant_issues_violations iv
    LEFT JOIN re_units u ON u.id = iv.unit_id
    LEFT JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_leases l ON l.id = iv.lease_id
    LEFT JOIN user u1 ON u1.id = iv.reported_by
    LEFT JOIN user u2 ON u2.id = iv.resolved_by
    WHERE iv.tenant_id = ?
    ORDER BY iv.reported_date DESC, iv.created_at DESC
");
$stmt->execute([$tenantId]);
$issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$legacyOutstandingTotal = (float)$paymentSummary['total_outstanding'] + (float)$penaltySummary['outstanding'];
$invoiceOutstandingTotal = (float)$invoiceModeSummary['invoice_outstanding'];
$combinedOutstandingTotal = $legacyOutstandingTotal + $invoiceOutstandingTotal;

// Set page title and include layout
$pageTitle = 'Tenant Details';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="mb-0 fw-bold">
                    <?php
                    if ($tenant['tenant_type'] === 'company') {
                        echo h($tenant['company_name'] ?? 'Company Tenant');
                    } else {
                        echo h($tenant['first_name'] . ' ' . $tenant['last_name']);
                    }
                    ?>
                    <span class="badge bg-<?= $tenant['tenant_type'] === 'company' ? 'info' : 'primary' ?> ms-2 fs-6">
                        <?= ucfirst($tenant['tenant_type']) ?>
                    </span>
                    <?php if (!$tenant['is_active']): ?>
                        <span class="badge bg-secondary ms-1 fs-6">Inactive</span>
                    <?php endif; ?>
                </h4>
                <?php if (!empty($tenant['phone'])): ?>
                    <small class="text-muted"><i class="bi bi-telephone"></i> <?= h($tenant['phone']) ?></small>
                <?php endif; ?>
                <?php if (!empty($tenant['email'])): ?>
                    <small class="text-muted ms-3"><i class="bi bi-envelope"></i> <?= h($tenant['email']) ?></small>
                <?php endif; ?>
            </div>
            <a href="tenant_add.php?id=<?= $tenantId ?>" class="btn btn-primary">
                <i class="bi bi-pencil"></i> Edit Tenant
            </a>
        </div>

        <!-- Quick Stats Bar -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card border-0 bg-primary bg-opacity-10 text-center p-3">
                    <div class="fs-4 fw-bold text-primary"><?= count($leases) ?></div>
                    <div class="small text-muted">Total Leases</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 bg-danger bg-opacity-10 text-center p-3">
                    <div class="fs-5 fw-bold text-danger"><?= number_format($combinedOutstandingTotal, 2) ?> AED</div>
                    <div class="small text-muted">Total Outstanding</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 bg-warning bg-opacity-10 text-center p-3">
                    <div class="fs-4 fw-bold text-warning"><?= $penaltySummary['unpaid_count'] ?></div>
                    <div class="small text-muted">Unpaid Penalties</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 bg-success bg-opacity-10 text-center p-3">
                    <div class="fs-5 fw-bold text-success"><?= number_format($tenantCreditBal, 2) ?> AED</div>
                    <div class="small text-muted">Credit Balance</div>
                </div>
            </div>
        </div>

        <!-- Accounting Overview -->
        <div class="card mb-4 border-info">
            <div class="card-header bg-info text-dark d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-calculator"></i> Accounting Overview</h5>
                <a href="accounting/tenant_statement.php?tenant_id=<?= (int)$tenantId ?>" class="btn btn-sm btn-outline-dark">Tenant Statement</a>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="border rounded p-3 text-center">
                            <div class="small text-muted">Invoice Mode Invoices</div>
                            <div class="fw-bold fs-5"><?= (int)$invoiceModeSummary['invoice_count'] ?></div>
                            <small class="text-muted">Total <?= number_format($invoiceModeSummary['invoice_total'], 2) ?> AED</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3 text-center <?= $invoiceModeSummary['invoice_outstanding'] > 0.005 ? 'bg-danger bg-opacity-10' : '' ?>">
                            <div class="small text-muted">Invoice Mode Outstanding</div>
                            <div class="fw-bold fs-5 <?= $invoiceModeSummary['invoice_outstanding'] > 0.005 ? 'text-danger' : 'text-success' ?>"><?= number_format($invoiceModeSummary['invoice_outstanding'], 2) ?> AED</div>
                            <small class="text-muted">Paid <?= number_format($invoiceModeSummary['invoice_paid'], 2) ?> AED</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3 text-center">
                            <div class="small text-muted">Receipts</div>
                            <div class="fw-bold fs-5"><?= (int)$invoiceModeSummary['receipt_count'] ?></div>
                            <small class="text-muted">Collected <?= number_format($invoiceModeSummary['receipt_total'], 2) ?> AED</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3 text-center">
                            <div class="small text-muted">Open Obligations</div>
                            <div class="fw-bold fs-5"><?= number_format($invoiceModeSummary['open_obligation_amount'], 2) ?> AED</div>
                            <small class="text-muted"><?= (int)$invoiceModeSummary['open_obligations'] ?> obligation rows</small>
                        </div>
                    </div>
                </div>
                <div class="row g-4">
                    <div class="col-lg-6">
                        <h6 class="mb-2">Latest Invoices</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="table-light"><tr><th>Invoice</th><th>Lease</th><th>Due</th><th>Status</th><th class="text-end">Outstanding</th></tr></thead>
                                <tbody>
                                    <?php foreach ($recentInvoices as $inv): ?>
                                        <tr>
                                            <td><a href="billing_invoice_view.php?id=<?= (int)$inv['id'] ?>"><?= h($inv['invoice_number']) ?></a></td>
                                            <td><?= h($inv['lease_number']) ?></td>
                                            <td><?= h($inv['due_date']) ?></td>
                                            <td><span class="badge bg-<?= $inv['status'] === 'paid' ? 'success' : ($inv['status'] === 'cancelled' ? 'secondary' : 'primary') ?>"><?= h($inv['status']) ?></span></td>
                                            <td class="text-end <?= (float)$inv['outstanding_amount'] > 0.005 ? 'text-danger fw-bold' : 'text-muted' ?>"><?= number_format((float)$inv['outstanding_amount'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$recentInvoices): ?><tr><td colspan="5" class="text-center text-muted">No Invoice Mode invoices found.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <h6 class="mb-2">Latest Receipts</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="table-light"><tr><th>Receipt</th><th>Lease</th><th>Date</th><th class="text-end">Amount</th><th class="text-end">Allocated</th></tr></thead>
                                <tbody>
                                    <?php foreach ($recentReceipts as $rec): ?>
                                        <tr>
                                            <td><a href="payment_view.php?id=<?= (int)$rec['id'] ?>"><?= h($rec['receipt_number'] ?: ('#' . $rec['id'])) ?></a></td>
                                            <td><?= h($rec['lease_number']) ?></td>
                                            <td><?= h($rec['cleared_date'] ?: $rec['payment_date']) ?></td>
                                            <td class="text-end"><?= number_format((float)$rec['amount'], 2) ?></td>
                                            <td class="text-end"><?= number_format((float)$rec['allocated_amount'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$recentReceipts): ?><tr><td colspan="5" class="text-center text-muted">No Invoice Mode receipts found.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Tenant Information -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Contact Information</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <?php if ($tenant['tenant_type'] === 'company'): ?>
                            <tr>
                                <th width="40%">Company Name:</th>
                                <td><strong><?= h($tenant['company_name']) ?></strong></td>
                            </tr>
                            <?php if ($tenant['first_name'] || $tenant['last_name']): ?>
                            <tr>
                                <th>Contact Person:</th>
                                <td><?= h(trim($tenant['first_name'] . ' ' . $tenant['last_name'])) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php else: ?>
                            <tr>
                                <th width="40%">Full Name:</th>
                                <td><?= h($tenant['first_name'] . ' ' . $tenant['last_name']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Email:</th>
                                <td><?= h($tenant['email'] ?: '-') ?></td>
                            </tr>
                            <tr>
                                <th>Phone:</th>
                                <td><?= h($tenant['phone'] ?: '-') ?></td>
                            </tr>
                            <tr>
                                <th>Alternate Phone:</th>
                                <td><?= h($tenant['phone_alt'] ?: '-') ?></td>
                            </tr>
                            <tr>
                                <th>Address:</th>
                                <td><?= h($tenant['address'] ?: '-') ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Identification</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <th width="40%">ID Type:</th>
                                <td><?= ucfirst(str_replace('_', ' ', $tenant['id_type'])) ?></td>
                            </tr>
                            <tr>
                                <th>ID Number:</th>
                                <td><?= h($tenant['id_number']) ?></td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td>
                                    <?php if ($tenant['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Emergency Contact -->
        <?php if ($tenant['emergency_contact_name']): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5>Emergency Contact</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="20%">Name:</th>
                        <td><?= h($tenant['emergency_contact_name']) ?></td>
                        <th width="20%">Phone:</th>
                        <td><?= h($tenant['emergency_contact_phone'] ?: '-') ?></td>
                    </tr>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Notes -->
        <?php if ($tenant['notes']): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5>Notes</h5>
            </div>
            <div class="card-body">
                <p><?= nl2br(h($tenant['notes'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Documents Section -->
        <?php
        require_once __DIR__ . '/includes/document_manager.php';
        render_document_manager('tenant', $tenantId, $currentCompanyId);
        ?>

        <!-- Previous Units History -->
        <?php if (!empty($unitHistory)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="bi bi-building"></i> Previous Units History</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Unit</th>
                                <th>Lease #</th>
                                <th>Move-In Date</th>
                                <th>Move-Out Date</th>
                                <th>Duration</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unitHistory as $history): ?>
                                <tr>
                                    <td>
                                        <strong><?= h($history['building_name']) ?></strong><br>
                                        <small class="text-muted">Unit <?= h($history['unit_number']) ?>
                                        <?php if (!empty($history['premises_number'])): ?>
                                            (Premises: <?= h($history['premises_number']) ?>)
                                        <?php endif; ?>
                                        </small>
                                    </td>
                                    <td><?= h($history['lease_number'] ?: '-') ?></td>
                                    <td><?= date('Y-m-d', strtotime($history['move_in_date'])) ?></td>
                                    <td><?= $history['move_out_date'] ? date('Y-m-d', strtotime($history['move_out_date'])) : '<span class="text-muted">Current</span>' ?></td>
                                    <td>
                                        <?php
                                        $moveIn = new DateTime($history['move_in_date']);
                                        $moveOut = $history['move_out_date'] ? new DateTime($history['move_out_date']) : new DateTime();
                                        $diff = $moveIn->diff($moveOut);
                                        echo $diff->y > 0 ? $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ' : '';
                                        echo $diff->m . ' month' . ($diff->m != 1 ? 's' : '');
                                        ?>
                                    </td>
                                    <td><?= h($history['notes'] ?: '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Lease / Contracts History -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-file-text"></i> Lease / Contracts History</h5>
                <a href="lease_add.php?tenant_id=<?= $tenantId ?>" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-circle"></i> New Lease
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($leases)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Lease #</th>
                                    <th>Unit / Building</th>
                                    <th>Period</th>
                                    <th class="text-end">Rent</th>
                                    <th class="text-end">Collected</th>
                                    <th class="text-end">Outstanding</th>
                                    <th class="text-end">Penalties</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($leases as $lease):
                                    $statusClass = [
                                        'draft'      => 'secondary',
                                        'active'     => 'success',
                                        'expired'    => 'warning',
                                        'terminated' => 'danger',
                                        'renewed'    => 'info'
                                    ];
                                    $sc   = $statusClass[$lease['status']] ?? 'secondary';
                                    $fin  = $leaseFinancials[$lease['id']] ?? ['total_due'=>0,'total_allocated'=>0,'outstanding'=>0,'penalties_outstanding'=>0];
                                    $hasOutstanding = $fin['outstanding'] > 0 || $fin['penalties_outstanding'] > 0;
                                ?>
                                    <tr class="<?= $hasOutstanding && $lease['status'] === 'active' ? 'table-warning' : '' ?>">
                                        <td>
                                            <strong><?= h($lease['lease_number'] ?: 'L-' . $lease['id']) ?></strong>
                                            <br><span class="badge bg-<?= (($fin['mode'] ?? ($lease['accounting_mode'] ?? 'legacy')) === 'invoice') ? 'primary' : 'secondary' ?>"><?= (($fin['mode'] ?? ($lease['accounting_mode'] ?? 'legacy')) === 'invoice') ? 'Invoice Mode' : 'Legacy Mode' ?></span>
                                        </td>
                                        <td>
                                            <strong><?= h($lease['building_name']) ?></strong><br>
                                            <small class="text-muted">
                                                Unit <?= h($lease['unit_number']) ?>
                                                <?php if (!empty($lease['premises_number'])): ?>
                                                    (<?= h($lease['premises_number']) ?>)
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td class="text-nowrap">
                                            <small>
                                                <?= date('d M Y', strtotime($lease['start_date'])) ?><br>
                                                <?= date('d M Y', strtotime($lease['end_date'])) ?>
                                            </small>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <?= number_format($lease['annual_rent'] ?? ($lease['monthly_rent'] * 12), 2) ?> AED
                                            <br><small class="text-muted">annual</small>
                                        </td>
                                        <td class="text-end text-nowrap text-success">
                                            <?= number_format($fin['total_allocated'], 2) ?> AED
                                        </td>
                                        <td class="text-end text-nowrap <?= $fin['outstanding'] > 0 ? 'text-danger fw-bold' : 'text-muted' ?>">
                                            <?= number_format($fin['outstanding'], 2) ?> AED
                                        </td>
                                        <td class="text-end text-nowrap <?= $fin['penalties_outstanding'] > 0 ? 'text-warning fw-bold' : 'text-muted' ?>">
                                            <?= number_format($fin['penalties_outstanding'], 2) ?> AED
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $sc ?>"><?= ucfirst($lease['status']) ?></span>
                                        </td>
                                        <td>
                                            <a href="lease_view.php?id=<?= $lease['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="p-4 text-center text-muted">No leases found for this tenant.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Behavior Summary -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-cash-stack"></i> Payment Behavior Summary</h5>
            </div>
            <div class="card-body">
                <?php if ($paymentSummary['total_periods'] > 0): ?>

                <!-- Behaviour KPIs -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md">
                        <div class="text-center p-3 border rounded bg-success bg-opacity-10">
                            <div class="fs-3 fw-bold text-success"><?= $paymentSummary['total_on_time'] ?></div>
                            <div class="small text-muted">On-Time</div>
                        </div>
                    </div>
                    <div class="col-6 col-md">
                        <div class="text-center p-3 border rounded bg-warning bg-opacity-10">
                            <div class="fs-3 fw-bold text-warning"><?= $paymentSummary['total_late'] ?></div>
                            <div class="small text-muted">Late</div>
                        </div>
                    </div>
                    <div class="col-6 col-md">
                        <div class="text-center p-3 border rounded bg-danger bg-opacity-10">
                            <div class="fs-3 fw-bold text-danger"><?= $paymentSummary['total_missed'] ?></div>
                            <div class="small text-muted">Missed / Overdue</div>
                        </div>
                    </div>
                    <div class="col-6 col-md">
                        <div class="text-center p-3 border rounded bg-secondary bg-opacity-10">
                            <div class="fs-3 fw-bold text-secondary"><?= $paymentSummary['total_bounced'] ?></div>
                            <div class="small text-muted">Bounced Cheques</div>
                        </div>
                    </div>
                    <div class="col-6 col-md">
                        <div class="text-center p-3 border rounded">
                            <div class="fs-4 fw-bold"><?= $paymentSummary['avg_days_late'] > 0 ? number_format($paymentSummary['avg_days_late'], 1) : '0' ?></div>
                            <div class="small text-muted">Avg Days Late</div>
                        </div>
                    </div>
                </div>

                <!-- Financial Totals -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="p-3 border rounded text-center">
                            <div class="fw-bold fs-6"><?= number_format($paymentSummary['total_due_amount'], 2) ?> AED</div>
                            <div class="small text-muted">Legacy Rent Due</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 border rounded text-center bg-success bg-opacity-10">
                            <div class="fw-bold fs-6 text-success"><?= number_format($paymentSummary['total_allocated'], 2) ?> AED</div>
                            <div class="small text-muted">Legacy Rent Collected</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 border rounded text-center <?= $paymentSummary['total_outstanding'] > 0 ? 'bg-danger bg-opacity-10' : '' ?>">
                            <div class="fw-bold fs-6 <?= $paymentSummary['total_outstanding'] > 0 ? 'text-danger' : 'text-muted' ?>"><?= number_format($paymentSummary['total_outstanding'], 2) ?> AED</div>
                            <div class="small text-muted">Legacy Rent Outstanding</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 border rounded text-center <?= $penaltySummary['outstanding'] > 0 ? 'bg-warning bg-opacity-10' : '' ?>">
                            <div class="fw-bold fs-6 <?= $penaltySummary['outstanding'] > 0 ? 'text-warning' : 'text-muted' ?>"><?= number_format($penaltySummary['outstanding'], 2) ?> AED</div>
                            <div class="small text-muted">Penalties Outstanding</div>
                        </div>
                    </div>
                </div>

                <!-- Penalty Charges Summary -->
                <?php if ($penaltySummary['total_count'] > 0): ?>
                <div class="mb-4">
                    <h6 class="mb-2"><i class="bi bi-exclamation-triangle text-warning"></i> Penalty Charges Summary</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Charge</th>
                                    <th>Lease</th>
                                    <th>Related Installment</th>
                                    <th>Due Date</th>
                                    <th class="text-end">Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allPenalties as $pen):
                                    $isWaived = !empty($pen['is_waived']);
                                    $isPaid   = !empty($pen['is_paid']);
                                ?>
                                <tr class="<?= !$isPaid && !$isWaived ? 'table-warning' : '' ?>">
                                    <td><?= h($pen['item_name']) ?></td>
                                    <td>
                                        <a href="lease_view.php?id=<?= (int)$pen['lease_id_val'] ?>" class="text-decoration-none">
                                            <?= h($pen['lease_number'] ?: 'L-' . $pen['lease_id_val']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if (!empty($pen['linked_installment_date'])): ?>
                                            <?= date('d M Y', strtotime($pen['linked_installment_date'])) ?>
                                            <?php if (!empty($pen['linked_cheque_number'])): ?>
                                                <small class="text-muted">· Chq <?= h($pen['linked_cheque_number']) ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('d M Y', strtotime($pen['due_date'])) ?></td>
                                    <td class="text-end"><?= number_format((float)$pen['total_amount'], 2) ?> AED</td>
                                    <td>
                                        <?php if ($isWaived): ?>
                                            <span class="badge bg-secondary">Waived</span>
                                        <?php elseif ($isPaid): ?>
                                            <span class="badge bg-success">Paid</span>
                                            <?php if (!empty($pen['paid_payment_date'])): ?>
                                                <br><small class="text-muted"><?= date('d M Y', strtotime($pen['paid_payment_date'])) ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Unpaid</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="4"><strong>Total</strong></td>
                                    <td class="text-end"><strong><?= number_format($penaltySummary['total_amount'], 2) ?> AED</strong></td>
                                    <td>
                                        <span class="badge bg-warning text-dark"><?= $penaltySummary['unpaid_count'] ?> Unpaid</span>
                                        <span class="badge bg-success ms-1"><?= $penaltySummary['paid_count'] ?> Paid</span>
                                        <?php if ($penaltySummary['waived_count']): ?>
                                            <span class="badge bg-secondary ms-1"><?= $penaltySummary['waived_count'] ?> Waived</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Monthly Installment Detail -->
                <?php if (!empty($paymentBehavior)): ?>
                <h6 class="mb-2"><i class="bi bi-calendar3"></i> Monthly Installment Detail</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Period</th>
                                <th>Lease</th>
                                <th class="text-end">Due</th>
                                <th class="text-end">Collected</th>
                                <th class="text-end">Outstanding</th>
                                <th class="text-center">On-Time</th>
                                <th class="text-center">Late</th>
                                <th class="text-center">Missed</th>
                                <th class="text-center">Bounced</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paymentBehavior as $pb):
                                $pbOutstanding = $pb['total_due'] - $pb['total_allocated'];
                            ?>
                                <tr class="<?= $pb['missed_payments'] > 0 ? 'table-danger table-sm' : ($pb['late_payments'] > 0 ? 'table-warning table-sm' : '') ?>">
                                    <td class="text-nowrap"><?= date('M Y', strtotime($pb['period_start'])) ?></td>
                                    <td><?= h($pb['lease_number'] ?: '—') ?></td>
                                    <td class="text-end text-nowrap"><?= number_format($pb['total_due'], 2) ?> AED</td>
                                    <td class="text-end text-nowrap text-success"><?= number_format($pb['total_allocated'], 2) ?> AED</td>
                                    <td class="text-end text-nowrap <?= $pbOutstanding > 0 ? 'text-danger fw-bold' : 'text-muted' ?>"><?= number_format(max(0, $pbOutstanding), 2) ?> AED</td>
                                    <td class="text-center"><?= $pb['on_time_payments'] > 0 ? '<span class="badge bg-success">' . $pb['on_time_payments'] . '</span>' : '<span class="text-muted">—</span>' ?></td>
                                    <td class="text-center"><?= $pb['late_payments'] > 0 ? '<span class="badge bg-warning text-dark">' . $pb['late_payments'] . '</span>' : '<span class="text-muted">—</span>' ?></td>
                                    <td class="text-center"><?= $pb['missed_payments'] > 0 ? '<span class="badge bg-danger">' . $pb['missed_payments'] . '</span>' : '<span class="text-muted">—</span>' ?></td>
                                    <td class="text-center"><?= $pb['bounced_payments'] > 0 ? '<span class="badge bg-secondary">' . $pb['bounced_payments'] . '</span>' : '<span class="text-muted">—</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-calendar-x text-muted" style="font-size: 3rem;"></i>
                    <p class="text-muted mt-3">No payment history available for this tenant.</p>
                    <p class="text-muted small">Payment behavior will be tracked once payments are recorded.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Issues & Violations Log -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-exclamation-triangle"></i> Issues & Violations Log</h5>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addIssueModal">
                    <i class="bi bi-plus-circle"></i> Add Issue
                </button>
            </div>
            <div class="card-body">
                <?php if (!empty($issues)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Severity</th>
                                    <th>Title</th>
                                    <th>Unit</th>
                                    <th>Status</th>
                                    <th>Reported By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($issues as $issue): ?>
                                    <tr>
                                        <td><?= date('Y-m-d', strtotime($issue['reported_date'])) ?></td>
                                        <td>
                                            <span class="badge bg-secondary"><?= ucfirst($issue['issue_type']) ?></span>
                                        </td>
                                        <td>
                                            <?php
                                            $severityClass = [
                                                'low' => 'info',
                                                'medium' => 'warning',
                                                'high' => 'danger',
                                                'critical' => 'dark'
                                            ];
                                            $severityBadge = $severityClass[$issue['severity']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $severityBadge ?>"><?= ucfirst($issue['severity']) ?></span>
                                        </td>
                                        <td><?= h($issue['title']) ?></td>
                                        <td>
                                            <?php if ($issue['unit_number']): ?>
                                                <?= h($issue['building_name']) ?> - <?= h($issue['unit_number']) ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $statusClass = [
                                                'open' => 'danger',
                                                'in_progress' => 'warning',
                                                'resolved' => 'success',
                                                'closed' => 'secondary'
                                            ];
                                            $statusBadge = $statusClass[$issue['status']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $statusBadge ?>"><?= ucfirst(str_replace('_', ' ', $issue['status'])) ?></span>
                                        </td>
                                        <td><?= h($issue['reported_by_name'] ?: 'System') ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-info" onclick="viewIssue(<?= htmlspecialchars(json_encode($issue)) ?>)">
                                                View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No issues or violations recorded</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Add Issue Modal -->
        <div class="modal fade" id="addIssueModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="tenant_issue_add.php">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">Add Issue/Violation</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Issue Type *</label>
                                <select name="issue_type" class="form-select" required>
                                    <option value="violation">Violation</option>
                                    <option value="complaint">Complaint</option>
                                    <option value="warning">Warning</option>
                                    <option value="notice">Notice</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Severity *</label>
                                <select name="severity" class="form-select" required>
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Title *</label>
                                <input type="text" class="form-control" name="title" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Description *</label>
                                <textarea class="form-control" name="description" rows="3" required></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Reported Date *</label>
                                <input type="date" class="form-control" name="reported_date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Add Issue</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- View Issue Modal -->
        <div class="modal fade" id="viewIssueModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="issueModalTitle">Issue Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" id="issueModalBody">
                        <!-- Content will be populated by JavaScript -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            function viewIssue(issue) {
                document.getElementById('issueModalTitle').textContent = issue.title;
                const body = document.getElementById('issueModalBody');
                body.innerHTML = `
                    <table class="table table-borderless">
                        <tr><th width="30%">Type:</th><td><span class="badge bg-secondary">${issue.issue_type}</span></td></tr>
                        <tr><th>Severity:</th><td><span class="badge bg-${issue.severity === 'low' ? 'info' : issue.severity === 'high' ? 'danger' : 'warning'}">${issue.severity}</span></td></tr>
                        <tr><th>Status:</th><td><span class="badge bg-${issue.status === 'open' ? 'danger' : issue.status === 'resolved' ? 'success' : 'warning'}">${issue.status}</span></td></tr>
                        <tr><th>Reported Date:</th><td>${issue.reported_date}</td></tr>
                        ${issue.resolved_date ? `<tr><th>Resolved Date:</th><td>${issue.resolved_date}</td></tr>` : ''}
                        <tr><th>Description:</th><td>${issue.description}</td></tr>
                        ${issue.resolution_notes ? `<tr><th>Resolution Notes:</th><td>${issue.resolution_notes}</td></tr>` : ''}
                        <tr><th>Reported By:</th><td>${issue.reported_by_name || 'System'}</td></tr>
                        ${issue.resolved_by_name ? `<tr><th>Resolved By:</th><td>${issue.resolved_by_name}</td></tr>` : ''}
                    </table>
                `;
                new bootstrap.Modal(document.getElementById('viewIssueModal')).show();
            }
        </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

