<?php
/**
 * Real Estate Module - Payments against one rent installment (cheque)
 * Shows all payments that were allocated to a single installment (e.g. 3 partial payments for one cheque).
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/payment_allocation_helper.php';
require_once __DIR__ . '/includes/receipt_allocation_engine.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

$installmentId = !empty($_GET['installment_id']) ? (int)$_GET['installment_id'] : 0;
$chequeId = !empty($_GET['cheque_id']) ? (int)$_GET['cheque_id'] : 0;

if (!$installmentId && !$chequeId) {
    header('Location: payments.php');
    exit;
}

// Load installment with lease, unit, tenant, cheque #
if ($installmentId > 0) {
    $stmt = $conn->prepare("
        SELECT li.*,
               l.lease_number, l.tenant_id, COALESCE(l.accounting_mode, 'legacy') AS accounting_mode,
               u.unit_number,
               b.name as building_name,
               t.first_name, t.last_name,
               c.id AS cheque_id,
               c.cheque_number,
               c.payment_method AS schedule_payment_method,
               c.cheque_amount
        FROM re_lease_installments li
        JOIN re_leases l ON l.id = li.lease_id
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        JOIN re_tenants t ON t.id = l.tenant_id
        LEFT JOIN re_post_dated_cheques c ON c.installment_id = li.id AND c.lease_id = li.lease_id
        WHERE li.id = ? AND li.lease_id IN (SELECT id FROM re_leases WHERE company_id = ?)
    ");
    $stmt->execute([$installmentId, $currentCompanyId]);
    $installment = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $stmt = $conn->prepare("
        SELECT li.*,
               l.lease_number, l.tenant_id, COALESCE(l.accounting_mode, 'legacy') AS accounting_mode,
               u.unit_number,
               b.name as building_name,
               t.first_name, t.last_name,
               c.id AS cheque_id,
               c.cheque_number,
               c.payment_method AS schedule_payment_method,
               c.cheque_amount
        FROM re_post_dated_cheques c
        JOIN re_leases l ON l.id = c.lease_id
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        JOIN re_tenants t ON t.id = l.tenant_id
        LEFT JOIN re_lease_installments li ON li.id = c.installment_id AND li.lease_id = c.lease_id
        WHERE c.id = ? AND c.company_id = ?
    ");
    $stmt->execute([$chequeId, $currentCompanyId]);
    $installment = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($installment) {
        $installmentId = (int)($installment['id'] ?? 0);
    }
}

if (!$installment) {
    header('Location: payments.php');
    exit;
}

$leaseId = (int)$installment['lease_id'];
$chequeId = $chequeId > 0 ? $chequeId : (int)($installment['cheque_id'] ?? 0);
$installmentAmount = (float)($installment['cheque_amount'] ?? $installment['amount'] ?? 0);
$accountingMode = (string)($installment['accounting_mode'] ?? 'legacy');

$chequeSummary = null;
$payments = [];
$seenPaymentIds = [];

if ($accountingMode === 'invoice' && $chequeId > 0) {
    $chequeSummary = re_cheque_receipt_summary($conn, $currentCompanyId, $chequeId);
    $totalPaid = (float)($chequeSummary['collected_total'] ?? 0);
    // Prefer operational summary receipts (per-cheque allocation share), not full receipt faces.
    foreach ($chequeSummary['receipts'] ?? [] as $row) {
        $pid = (int)($row['id'] ?? 0);
        if ($pid <= 0 || in_array($pid, $seenPaymentIds, true)) {
            continue;
        }
        $payments[] = [
            'payment_id'       => $pid,
            'payment_date'     => $row['cleared_date'] ?: ($row['payment_date'] ?? ''),
            'receipt_number'   => $row['receipt_number'] ?: ('#' . $pid),
            'amount_allocated' => (float)($row['display_amount'] ?? $row['allocated_to_targets'] ?? $row['amount'] ?? 0),
            'payment_method'   => $row['payment_method'] ?? '',
            'reference_number' => $row['reference_number'] ?? '',
            'receipt_source'   => $row['receipt_source'] ?? '',
            'allocation_status'=> $row['allocation_status'] ?? '',
            'is_coverage_inferred' => !empty($row['is_coverage_inferred']),
            'receipt_face_amount' => (float)($row['receipt_face_amount'] ?? $row['amount'] ?? 0),
        ];
        $seenPaymentIds[] = $pid;
    }
} else {
    $totalPaid = get_installment_total_paid($conn, $installmentId);
}
$outstanding = max(0, $installmentAmount - $totalPaid);

// Legacy / fallback payment list when IM summary had no receipt rows.
if ($payments === []) {
// Build the payment list without double-counting.
// A payment can be linked via BOTH re_payment_allocations AND re_payments.installment_id
// on servers where both old and new recording flows were used.
// Strategy: allocations are authoritative (correct allocated amount per installment);
// add direct-linked payments only if they have NO allocation record here.

// ── Step 1: allocation records (most accurate, supports partial splits) ──────
try {
    $stmt = $conn->prepare("
        SELECT p.id, p.payment_date, p.receipt_number, p.payment_method, p.reference_number,
               pa.amount_allocated
        FROM re_payment_allocations pa
        JOIN re_payments p ON p.id = pa.payment_id
        WHERE pa.installment_id = ? AND p.company_id = ?
        ORDER BY p.payment_date, p.id
    ");
    $stmt->execute([$installmentId, $currentCompanyId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['id'];
        $payments[] = [
            'payment_id'       => $pid,
            'payment_date'     => $row['payment_date'],
            'receipt_number'   => $row['receipt_number'] ?: '#' . $pid,
            'amount_allocated' => (float)$row['amount_allocated'],
            'payment_method'   => $row['payment_method'],
            'reference_number' => $row['reference_number'],
        ];
        $seenPaymentIds[] = $pid;
    }
} catch (Throwable $e) {
    // Allocation table absent — continue to direct-link fallback
}

// ── Step 2: direct installment_id link — skip if already added via allocations ─
$stmt = $conn->prepare("
    SELECT id, payment_date, receipt_number, amount, payment_method, reference_number
    FROM re_payments
    WHERE installment_id = ? AND company_id = ?
");
$stmt->execute([$installmentId, $currentCompanyId]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $pid = (int)$row['id'];
    if (in_array($pid, $seenPaymentIds, true)) {
        continue; // already counted via allocation — skip to avoid duplicate
    }
    $payments[] = [
        'payment_id'       => $pid,
        'payment_date'     => $row['payment_date'],
        'receipt_number'   => $row['receipt_number'] ?: '#' . $pid,
        'amount_allocated' => (float)$row['amount'],
        'payment_method'   => $row['payment_method'],
        'reference_number' => $row['reference_number'],
    ];
    $seenPaymentIds[] = $pid;
}

// ── Step 3: Invoice Mode receipts linked by cheque_id (explicit instrument link only) ─
// Uses display share when available from coverage; otherwise still avoid treating as
// the sole collected total (header already uses corrected collected_total).
if ($chequeId > 0 && re_obligation_column_exists($conn, 're_payments', 'cheque_id')) {
    $stmt = $conn->prepare("
        SELECT id, payment_date, cleared_date, receipt_number, amount, payment_method, reference_number, receipt_source, allocation_status
        FROM re_payments
        WHERE company_id = ? AND cheque_id = ? AND accounting_mode = 'invoice' AND receipt_status = 'cleared'
        ORDER BY COALESCE(cleared_date, payment_date), id
    ");
    $stmt->execute([$currentCompanyId, $chequeId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['id'];
        if (in_array($pid, $seenPaymentIds, true)) {
            continue;
        }
        $share = null;
        if ($chequeSummary && !empty($chequeSummary['financial_coverage']['receipt_shares'][$pid])) {
            $share = (float)$chequeSummary['financial_coverage']['receipt_shares'][$pid];
        }
        $payments[] = [
            'payment_id'       => $pid,
            'payment_date'     => $row['cleared_date'] ?: $row['payment_date'],
            'receipt_number'   => $row['receipt_number'] ?: '#' . $pid,
            'amount_allocated' => $share !== null ? $share : (float)$row['amount'],
            'payment_method'   => $row['payment_method'],
            'reference_number' => $row['reference_number'],
            'receipt_source'   => $row['receipt_source'] ?? '',
            'allocation_status'=> $row['allocation_status'] ?? '',
        ];
        $seenPaymentIds[] = $pid;
    }
}
} // end fallback when IM summary empty

// Sort by payment date then payment id
usort($payments, function ($a, $b) {
    $t = strcmp($a['payment_date'], $b['payment_date']);
    return $t !== 0 ? $t : ($a['payment_id'] - $b['payment_id']);
});

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'Linked receipts';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h1><i class="bi bi-cash-stack"></i> Linked receipts for this schedule row</h1>
            <a href="lease_view.php?id=<?= $leaseId ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Lease
            </a>
        </div>

        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Installment / Cheque</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless mb-0">
                            <tr>
                                <th width="45%">Installment date</th>
                                <td><?= date('Y-m-d', strtotime($installment['installment_date'])) ?></td>
                            </tr>
                            <tr>
                                <th>Cheque / Reference</th>
                                <td><strong><?= h($installment['cheque_number'] ?: '-') ?></strong>
                                    <?php if (!empty($installment['schedule_payment_method'])): ?>
                                        <br><small class="text-muted"><?= h(ucwords(str_replace('_', ' ', (string)$installment['schedule_payment_method']))) ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Installment total</th>
                                <td><strong><?= number_format($installmentAmount, 2) ?> AED</strong></td>
                            </tr>
                            <tr>
                                <th>Total collected</th>
                                <td class="text-success"><strong><?= number_format($totalPaid, 2) ?> AED</strong></td>
                            </tr>
                            <tr>
                                <th>Outstanding</th>
                                <td class="<?= $outstanding > 0 ? 'text-danger fw-bold' : 'text-muted' ?>">
                                    <?= number_format($outstanding, 2) ?> AED
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Lease</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless mb-0">
                            <tr>
                                <th width="45%">Lease</th>
                                <td><?= h($installment['lease_number'] ?: 'L-' . $leaseId) ?></td>
                            </tr>
                            <tr>
                                <th>Unit</th>
                                <td><?= h($installment['building_name'] . ' - ' . $installment['unit_number']) ?></td>
                            </tr>
                            <tr>
                                <th>Tenant</th>
                                <td><?= h($installment['first_name'] . ' ' . $installment['last_name']) ?></td>
                            </tr>
                        </table>
                        <a href="lease_view.php?id=<?= $leaseId ?>" class="btn btn-sm btn-outline-primary mt-2">View Lease Details</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">All linked receipts (<?= count($payments) ?> receipt<?= count($payments) !== 1 ? 's' : '' ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($payments)): ?>
                    <p class="text-muted mb-0">No receipt records found for this schedule row.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Receipt #</th>
                                    <th>Payment date</th>
                                    <th>Amount allocated (AED)</th>
                                    <th>Method</th>
                                    <th>Reference</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $p): ?>
                                    <tr>
                                        <td><strong><?= h($p['receipt_number']) ?></strong></td>
                                        <td><?= date('Y-m-d', strtotime($p['payment_date'])) ?></td>
                                        <td class="text-end"><?= number_format($p['amount_allocated'], 2) ?></td>
                                        <td><?= ucfirst(str_replace('_', ' ', $p['payment_method'])) ?></td>
                                        <td><?= h($p['reference_number'] ?: '-') ?></td>
                                        <td>
                                            <a href="payment_view.php?id=<?= $p['payment_id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-receipt"></i> View receipt
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
