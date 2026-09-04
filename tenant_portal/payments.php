<?php
/**
 * Tenant Portal — Installments & payments (read-only)
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';
require_once __DIR__ . '/../modules/realestate/includes/payment_allocation_helper.php';

require_tenant_login($conn);
require_once __DIR__ . '/includes/tenant_lease_loader.php';

if (!$lease) {
    header('Location: login.php');
    exit;
}

$lease_id = (int)$lease['lease_id'];

$installments = $conn->prepare("
    SELECT id, installment_date, amount, status, paid_at, payment_id, notes
    FROM re_lease_installments
    WHERE lease_id = ?
    ORDER BY installment_date DESC
");
$installments->execute([$lease_id]);
$installments = $installments->fetchAll(PDO::FETCH_ASSOC);

$payments = $conn->prepare("
    SELECT id, payment_date, amount, payment_method, reference_number, receipt_number, notes, created_at
    FROM re_payments
    WHERE lease_id = ?
    ORDER BY payment_date DESC
");
$payments->execute([$lease_id]);
$payments = $payments->fetchAll(PDO::FETCH_ASSOC);

// Outstanding rent installments
$outstandingStmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) FROM re_lease_installments
    WHERE lease_id = ? AND status IN ('pending', 'overdue')
");
$outstandingStmt->execute([$lease_id]);
$outstanding = (float) $outstandingStmt->fetchColumn();

// Penalties / late fees for this lease (re_billing_items)
$penalties = [];
$penaltyOutstanding = 0.0;
try {
    $pStmt = $conn->prepare("
        SELECT billing_date, due_date, item_name, total_amount, status
        FROM re_billing_items
        WHERE lease_id = ? AND item_type = 'penalty'
        ORDER BY due_date DESC, billing_date DESC, id DESC
    ");
    $pStmt->execute([$lease_id]);
    $penalties = $pStmt->fetchAll(PDO::FETCH_ASSOC);

    $pOut = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0)
        FROM re_billing_items
        WHERE lease_id = ? AND item_type = 'penalty' AND status IN ('pending', 'overdue')
    ");
    $pOut->execute([$lease_id]);
    $penaltyOutstanding = (float)$pOut->fetchColumn();
} catch (Throwable $e) {
    $penalties = [];
    $penaltyOutstanding = 0.0;
}

// Service charge schedule for this lease (EV charging, parking services, etc.)
$serviceCharges = [];
$serviceOutstanding = 0.0;
try {
    $sStmt = $conn->prepare("
        SELECT bi.id, bi.billing_date, bi.due_date, bi.item_name, bi.item_description,
               bi.total_amount, bi.status, bi.billing_period_start, bi.billing_period_end,
               sc.charge_name AS service_name, sc.is_active AS service_active,
               sc.is_recurring, sc.recurrence_type
        FROM re_billing_items bi
        LEFT JOIN re_service_charges sc ON sc.id = bi.service_charge_id
        WHERE bi.lease_id = ?
          AND bi.item_type = 'service_charge'
        ORDER BY bi.due_date DESC, bi.id DESC
    ");
    $sStmt->execute([$lease_id]);
    $serviceCharges = $sStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($serviceCharges as &$row) {
        $paid = get_billing_item_total_paid($conn, (int)$row['id']);
        $row['paid_amount_display'] = $paid;
        $row['outstanding_amount'] = (($row['status'] ?? '') === 'waived')
            ? 0.0
            : max(0, (float)$row['total_amount'] - $paid);
        if (($row['status'] ?? '') === 'waived') {
            $row['display_status'] = 'waived';
        } elseif ($row['outstanding_amount'] <= 0.005 && (float)$row['total_amount'] > 0) {
            $row['display_status'] = 'paid';
        } elseif ($paid > 0) {
            $row['display_status'] = 'partial';
        } elseif (!empty($row['due_date']) && $row['due_date'] < date('Y-m-d')) {
            $row['display_status'] = 'overdue';
        } else {
            $row['display_status'] = $row['status'] ?: 'pending';
        }
        $serviceOutstanding += (float)$row['outstanding_amount'];
    }
    unset($row);
} catch (Throwable $e) {
    $serviceCharges = [];
    $serviceOutstanding = 0.0;
}

$pageTitle = 'Payments';
require_once __DIR__ . '/includes/tenant_layout_header.php';
?>

<h4 class="page-title">Payments & installments</h4>
<?php if ($outstanding > 0 || $penaltyOutstanding > 0 || $serviceOutstanding > 0): ?>
    <div class="alert alert-warning mb-4">
        <strong>Outstanding balance</strong><br>
        <?php if ($outstanding > 0): ?>
            <span>Rent installments: <?= number_format($outstanding, 2) ?> AED</span><br>
        <?php endif; ?>
        <?php if ($serviceOutstanding > 0): ?>
            <span>Service charges: <?= number_format($serviceOutstanding, 2) ?> AED</span><br>
        <?php endif; ?>
        <?php if ($penaltyOutstanding > 0): ?>
            <span>Penalties / late fees: <?= number_format($penaltyOutstanding, 2) ?> AED</span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<h5 class="section-title">Rent installments</h5>
<div class="portal-table-wrap">
    <table class="table table-hover">
        <thead>
            <tr>
                <th>Due date</th>
                <th>Amount (AED)</th>
                <th>Status</th>
                <th>Paid at</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($installments as $row): ?>
                <tr>
                    <td data-label="Due date"><?= htmlspecialchars(date('M j, Y', strtotime($row['installment_date']))) ?></td>
                    <td data-label="Amount (AED)"><?= number_format((float)$row['amount'], 2) ?></td>
                    <td data-label="Status">
                        <span class="badge bg-<?= $row['status'] === 'paid' ? 'success' : ($row['status'] === 'overdue' ? 'danger' : 'warning') ?>">
                            <?= htmlspecialchars($row['status']) ?>
                        </span>
                    </td>
                    <td data-label="Paid at"><?= $row['paid_at'] ? date('M j, Y H:i', strtotime($row['paid_at'])) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if (empty($installments)): ?>
    <p class="portal-empty">No installments on record.</p>
<?php endif; ?>

<?php if (!empty($serviceCharges)): ?>
    <h5 class="section-title mt-4" id="service-charges">Service charges</h5>
    <div class="portal-table-wrap">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Due date</th>
                    <th>Service</th>
                    <th>Period</th>
                    <th>Amount (AED)</th>
                    <th>Paid (AED)</th>
                    <th>Outstanding (AED)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($serviceCharges as $row):
                    $displayStatus = $row['display_status'] ?? ($row['status'] ?: 'pending');
                    $badgeClass = [
                        'paid' => 'success',
                        'partial' => 'info',
                        'overdue' => 'danger',
                        'waived' => 'secondary',
                        'pending' => 'warning',
                    ][$displayStatus] ?? 'secondary';
                ?>
                    <tr>
                        <td data-label="Due date"><?= htmlspecialchars(date('M j, Y', strtotime($row['due_date']))) ?></td>
                        <td data-label="Service">
                            <?= htmlspecialchars($row['service_name'] ?: $row['item_name']) ?>
                            <?php if (!empty($row['item_description'])): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($row['item_description']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td data-label="Period">
                            <?php if (!empty($row['billing_period_start'])): ?>
                                <?= htmlspecialchars(date('M j, Y', strtotime($row['billing_period_start']))) ?>
                                <?php if (!empty($row['billing_period_end'])): ?>
                                    to <?= htmlspecialchars(date('M j, Y', strtotime($row['billing_period_end']))) ?>
                                <?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td data-label="Amount (AED)"><?= number_format((float)$row['total_amount'], 2) ?></td>
                        <td data-label="Paid (AED)"><?= number_format((float)$row['paid_amount_display'], 2) ?></td>
                        <td data-label="Outstanding (AED)">
                            <?= (float)$row['outstanding_amount'] > 0 ? number_format((float)$row['outstanding_amount'], 2) : '—' ?>
                        </td>
                        <td data-label="Status">
                            <span class="badge bg-<?= $badgeClass ?>">
                                <?= htmlspecialchars($displayStatus) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<h5 class="section-title">Payment history</h5>
<div class="portal-table-wrap">
    <table class="table table-hover">
        <thead>
            <tr>
                <th>Date</th>
                <th>Amount (AED)</th>
                <th>Method</th>
                <th>Reference</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($payments as $row): ?>
                <tr>
                    <td data-label="Date"><?= htmlspecialchars(date('M j, Y', strtotime($row['payment_date']))) ?></td>
                    <td data-label="Amount (AED)"><?= number_format((float)$row['amount'], 2) ?></td>
                    <td data-label="Method"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $row['payment_method']))) ?></td>
                    <td data-label="Reference"><?= htmlspecialchars($row['reference_number'] ?: $row['receipt_number'] ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if (empty($payments)): ?>
    <p class="portal-empty">No payments on record.</p>
<?php endif; ?>

<?php if (!empty($penalties)): ?>
    <h5 class="section-title mt-4">Penalties & late fees</h5>
    <div class="portal-table-wrap">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Billing date</th>
                    <th>Due date</th>
                    <th>Description</th>
                    <th>Amount (AED)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($penalties as $row): ?>
                    <tr>
                        <td data-label="Billing date"><?= htmlspecialchars(date('M j, Y', strtotime($row['billing_date']))) ?></td>
                        <td data-label="Due date"><?= htmlspecialchars(date('M j, Y', strtotime($row['due_date']))) ?></td>
                        <td data-label="Description"><?= htmlspecialchars($row['item_name']) ?></td>
                        <td data-label="Amount (AED)"><?= number_format((float)$row['total_amount'], 2) ?></td>
                        <td data-label="Status">
                            <span class="badge bg-<?= $row['status'] === 'paid' ? 'success' : ($row['status'] === 'overdue' ? 'danger' : ($row['status'] === 'waived' ? 'secondary' : 'warning')) ?>">
                                <?= htmlspecialchars($row['status']) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/tenant_layout_footer.php'; ?>
