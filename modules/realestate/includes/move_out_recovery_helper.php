<?php
/**
 * Move-Out Final Recovery Invoice (migrations/re_move_out_recovery.sql).
 *
 * Damages + standard move-out charges above the security deposit are owed by
 * the tenant. From the move-out the shortfall is raised as a one-off service
 * charge with one billing item on the lease (allowed on expired / terminated
 * leases, like Extra Service), dated the move-out day (or today while that
 * date is still ahead) so a later lease termination does not cancel it and it
 * can be collected at once. In Invoice Mode the obligation and invoice engines turn it into a receivable; payments are recorded on the lease as usual.
 */

require_once __DIR__ . '/security_deposit_helper.php';

function rmor_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$conn->query("SHOW COLUMNS FROM re_move_outs LIKE 'recovery_billing_item_id'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * What the approved settlement leaves for the tenant to pay:
 * ['final' => bool, 'excess' => float, 'received' => float, 'requested' => float, 'lines' => [[label, amount], ...]]
 */
function rmor_shortfall(PDO $conn, int $companyId, int $moveOutId): array
{
    $out = ['final' => false, 'excess' => 0.0, 'received' => 0.0, 'requested' => 0.0, 'lines' => []];
    $set = re_sd_settlement_for_move_out($conn, $companyId, $moveOutId);
    if (!$set) {
        return $out;
    }
    $final = in_array($set['status'], ['approved', 'deducted', 'refunded', 'partially_refunded', 'forfeited'], true)
        || ($set['status'] === 'cancelled' && !empty($set['approved_at']));
    $st = $conn->prepare("
        SELECT deduction_type, amount, reason FROM re_security_deposit_deductions
        WHERE company_id = ? AND move_out_id = ? AND status IN ('approved', 'applied')
        ORDER BY id
    ");
    $st->execute([$companyId, $moveOutId]);
    $requested = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $d) {
        $label = $d['deduction_type'] === 'damage' ? 'Damages'
            : preg_replace('/^Standard move-out charge:\s*/', '', (string)$d['reason']);
        $out['lines'][] = [$label, round((float)$d['amount'], 2)];
        $requested += (float)$d['amount'];
    }
    $received = (float)$set['received_amount'];
    $out['final'] = $final;
    $out['received'] = round($received, 2);
    $out['requested'] = round($requested, 2);
    $out['excess'] = $final ? max(0, round($requested - (float)$set['deduction_amount'], 2)) : 0.0;
    return $out;
}

/** "Damages 600.00; Cleaning 200.00; ... less security deposit 3,500.00 = 1,300.00 AED" */
function rmor_description(array $sf): string
{
    $parts = array_map(static fn($l) => $l[0] . ' ' . number_format($l[1], 2), $sf['lines']);
    return 'Move-out recovery: damages and charges not covered by the security deposit. '
        . implode('; ', $parts) . '; less security deposit ' . number_format($sf['received'], 2)
        . ' = ' . number_format($sf['excess'], 2) . ' AED.';
}

/**
 * The raised recovery invoice: ['billing_item_id', 'amount', 'paid', 'status' due|partly_paid|paid|cancelled,
 * 'due_date', 'raised_at'] or null when none was raised.
 */
function rmor_status(PDO $conn, int $companyId, array $mo): ?array
{
    if (!rmor_ready($conn) || empty($mo['recovery_billing_item_id'])) {
        return null;
    }
    $st = $conn->prepare("SELECT * FROM re_billing_items WHERE id = ? AND company_id = ?");
    $st->execute([(int)$mo['recovery_billing_item_id'], $companyId]);
    $bi = $st->fetch(PDO::FETCH_ASSOC);
    if (!$bi) {
        return null;
    }
    $total = round((float)$bi['total_amount'], 2);
    $paid = round((float)($bi['paid_amount'] ?? 0), 2);
    // Invoice Mode: payments are allocated to the obligation(s) made from the billing item
    try {
        $ob = $conn->prepare("SELECT COALESCE(SUM(allocated_amount), 0) FROM re_obligations WHERE company_id = ? AND source_type = 'billing_item' AND source_id = ?");
        $ob->execute([$companyId, (int)$bi['id']]);
        $paid = max($paid, round((float)$ob->fetchColumn(), 2));
    } catch (Throwable $e) {
    }
    if ($paid <= 0.005 && !empty($bi['is_paid'])) {
        $paid = $total;
    }
    $status = $bi['status'] === 'waived' || !empty($bi['is_waived']) ? 'cancelled'
        : ($paid >= $total - 0.005 ? 'paid' : ($paid > 0.005 ? 'partly_paid' : 'due'));
    return ['billing_item_id' => (int)$bi['id'], 'amount' => $total, 'paid' => $paid, 'status' => $status,
        'due_date' => $bi['due_date'], 'raised_at' => $mo['recovery_raised_at'] ?? null, 'description' => $bi['item_description']];
}

/** Raise the final recovery invoice for the approved shortfall. Returns ['ok', 'error', 'amount']. */
function rmor_raise(PDO $conn, int $companyId, int $moveOutId, ?int $userId): array
{
    if (!rmor_ready($conn)) {
        return ['ok' => false, 'error' => 'Run migrations/re_move_out_recovery.sql first.'];
    }
    $mst = $conn->prepare("SELECT mo.*, l.status AS lease_status, l.lease_number FROM re_move_outs mo JOIN re_leases l ON l.id = mo.lease_id WHERE mo.id = ? AND mo.company_id = ?");
    $mst->execute([$moveOutId, $companyId]);
    $mo = $mst->fetch(PDO::FETCH_ASSOC);
    if (!$mo) {
        return ['ok' => false, 'error' => 'Move-out not found.'];
    }
    if (($cur = rmor_status($conn, $companyId, $mo)) && $cur['status'] !== 'cancelled') {
        return ['ok' => false, 'error' => 'A recovery invoice is already raised for this move-out.'];
    }
    $sf = rmor_shortfall($conn, $companyId, $moveOutId);
    if (!$sf['final']) {
        return ['ok' => false, 'error' => 'Approve the deposit settlement first.'];
    }
    if ($sf['excess'] <= 0.005) {
        return ['ok' => false, 'error' => 'Nothing to invoice: the deposit covers the damages and charges.'];
    }
    // Charged to the move-out lease (the tenancy's current lease); allowed when expired / terminated
    $leaseId = (int)$mo['lease_id'];
    if (!in_array($mo['lease_status'], ['active', 'expired', 'terminated'], true)) {
        return ['ok' => false, 'error' => 'The lease is ' . $mo['lease_status'] . ' and cannot be charged.'];
    }
    // Due on the move-out day (so a later termination at that date keeps it), but never in the
    // future: the invoice is issued only once due, and the tenant owes it as soon as it is raised
    $due = min((string)($mo['actual_move_out_date'] ?: date('Y-m-d')), date('Y-m-d'));
    $amount = $sf['excess'];
    $name = 'Move-out recovery (not covered by deposit)';
    $desc = rmor_description($sf);
    $own = !$conn->inTransaction();
    if ($own) {
        $conn->beginTransaction();
    }
    try {
        $conn->prepare("
            INSERT INTO re_service_charges
                (company_id, lease_id, charge_name, charge_description, amount, contracted_amount, charge_type,
                 is_recurring, recurrence_type, apply_mode, start_date, end_date, is_active, lifecycle_status,
                 vat_treatment, vat_rate, amount_basis)
            VALUES (?, ?, ?, ?, ?, ?, 'fixed', 0, 'one_time', 'standalone', ?, ?, 1, 'active', 'out_of_scope', 0, 'vat_exclusive')
        ")->execute([$companyId, $leaseId, $name, $desc, $amount, $amount, $due, $due]);
        $scId = (int)$conn->lastInsertId();
        $conn->prepare("
            INSERT INTO re_billing_items
                (company_id, lease_id, item_type, item_name, item_description, amount, quantity, unit_price, tax_rate, tax_amount, total_amount,
                 billing_period_start, billing_period_end, billing_date, due_date, service_charge_id, status, created_by)
            VALUES (?, ?, 'service_charge', ?, ?, ?, 1, ?, 0, 0, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ")->execute([$companyId, $leaseId, $name, $desc, $amount, $amount, $amount, $due, $due, $due, $due, $scId, $userId]);
        $biId = (int)$conn->lastInsertId();
        $conn->prepare("
            UPDATE re_move_outs SET recovery_service_charge_id = ?, recovery_billing_item_id = ?, recovery_amount = ?, recovery_raised_at = NOW(), recovery_raised_by = ?
            WHERE id = ? AND company_id = ?
        ")->execute([$scId, $biId, $amount, $userId, $moveOutId, $companyId]);
        // Invoice Mode: make the receivable (obligation + invoice candidate) now
        require_once __DIR__ . '/service_charge_schedule_helper.php';
        if (function_exists('re_service_charge_sync_invoice_mode_obligations')) {
            re_service_charge_sync_invoice_mode_obligations($conn, $companyId, $leaseId, $userId);
        }
        re_sd_log($conn, $companyId, $leaseId, null, 'recovery_invoice_raised', null, null, $amount, $desc, $userId, 'move_out_view', null, null, $moveOutId, null, null);
        if ($own) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($own && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not raise the recovery invoice: ' . $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'amount' => $amount];
}

/**
 * Cancel an unpaid recovery invoice (e.g. raised for the wrong amount): the
 * billing item is waived and the charge cancelled, so a new one can be raised.
 */
function rmor_cancel(PDO $conn, int $companyId, int $moveOutId, ?int $userId, string $reason): array
{
    $mst = $conn->prepare("SELECT * FROM re_move_outs WHERE id = ? AND company_id = ?");
    $mst->execute([$moveOutId, $companyId]);
    $mo = $mst->fetch(PDO::FETCH_ASSOC);
    $cur = $mo ? rmor_status($conn, $companyId, $mo) : null;
    if (!$cur || $cur['status'] === 'cancelled') {
        return ['ok' => false, 'error' => 'There is no recovery invoice to cancel.'];
    }
    if ($cur['paid'] > 0.005) {
        return ['ok' => false, 'error' => 'Payments are already recorded against this invoice; it cannot be cancelled.'];
    }
    if (trim($reason) === '') {
        return ['ok' => false, 'error' => 'Enter the reason for cancelling.'];
    }
    $own = !$conn->inTransaction();
    if ($own) {
        $conn->beginTransaction();
    }
    try {
        $conn->prepare("UPDATE re_billing_items SET status = 'waived', is_waived = 1, notes = TRIM(CONCAT(COALESCE(notes, ''), ' Cancelled: ', ?)) WHERE id = ? AND company_id = ?")
            ->execute([mb_substr($reason, 0, 300), $cur['billing_item_id'], $companyId]);
        if (!empty($mo['recovery_service_charge_id'])) {
            $conn->prepare("UPDATE re_service_charges SET is_active = 0, lifecycle_status = 'cancelled', cancelled_effective_date = CURDATE() WHERE id = ? AND company_id = ?")
                ->execute([(int)$mo['recovery_service_charge_id'], $companyId]);
        }
        require_once __DIR__ . '/service_charge_schedule_helper.php';
        if (function_exists('re_service_charge_sync_invoice_mode_obligations')) {
            re_service_charge_sync_invoice_mode_obligations($conn, $companyId, (int)$mo['lease_id'], $userId);
        }
        re_sd_log($conn, $companyId, (int)$mo['lease_id'], null, 'recovery_invoice_cancelled', null, null, $cur['amount'], $reason, $userId, 'move_out_view', null, null, $moveOutId, null, null);
        if ($own) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($own && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not cancel the recovery invoice: ' . $e->getMessage()];
    }
    return ['ok' => true, 'error' => ''];
}
