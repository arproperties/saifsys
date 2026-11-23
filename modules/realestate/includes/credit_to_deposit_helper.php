<?php
/**
 * Move money a receipt parked as tenant credit onto the lease's security deposit.
 *
 * A receipt that should have paid the deposit but was confirmed without a deposit line keeps the
 * whole amount as tenant credit, and tenant credit can only ever pay invoices. This rewrites that
 * receipt's allocation as if the deposit line had been filled at confirm time:
 *   - the receipt's tenant_credit allocation (and its credit pocket) go down by the amount,
 *   - a deposit allocation row is added on the same receipt,
 *   - the deposit liability journal is posted (Dr Bank / Cr 2200, same as a normal deposit receipt),
 *   - the receipt journal is reversed and posted again, so it now leaves the deposit part out.
 * Net effect on the books: Dr 2410 tenant credit / Cr 2200 deposit liability. Bank is unchanged.
 *
 * Undo puts every one of those back.
 */

require_once __DIR__ . '/receipt_edit_helper.php';
require_once __DIR__ . '/security_deposit_helper.php';
require_once __DIR__ . '/payment_allocation_helper.php';
require_once __DIR__ . '/../accounting/accounting_integration.php';

if (!defined('RE_CTD_NOTE')) {
    define('RE_CTD_NOTE', 'Moved from tenant credit to security deposit');
}

if (!function_exists('re_ctd_deposit_obligation')) {
    /** The lease's own deposit obligation (not invoiced), locked when $forUpdate. */
    function re_ctd_deposit_obligation(PDO $conn, int $companyId, int $leaseId, bool $forUpdate = false): ?array
    {
        $st = $conn->prepare("
            SELECT o.*
            FROM re_obligations o
            WHERE o.company_id = ? AND o.lease_id = ? AND o.obligation_type = 'security_deposit'
              AND NOT EXISTS (
                    SELECT 1 FROM re_invoice_items ii
                    WHERE ii.company_id = o.company_id AND ii.obligation_id = o.id
              )
            ORDER BY o.id ASC
            LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : ''));
        $st->execute([$companyId, $leaseId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('re_ctd_state')) {
    /**
     * What the page needs: the deposit still owed, the tenant credit, the receipts of this lease
     * whose parked credit is still unused, and the moves already made (for Undo).
     *
     * @return array<string,mixed>
     */
    function re_ctd_state(PDO $conn, int $companyId, int $leaseId): array
    {
        $out = [
            'available' => false,
            'deposit' => null,
            'deposit_open' => 0.0,
            'credit_balance' => 0.0,
            'receipts' => [],
            'moves' => [],
        ];
        try {
            $st = $conn->prepare("SELECT tenant_id, COALESCE(accounting_mode, 'legacy') AS accounting_mode FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1");
            $st->execute([$leaseId, $companyId]);
            $lease = $st->fetch(PDO::FETCH_ASSOC);
            if (!$lease || $lease['accounting_mode'] !== 'invoice' || (int)$lease['tenant_id'] <= 0) {
                return $out;
            }
            $tenantId = (int)$lease['tenant_id'];

            $deposit = re_ctd_deposit_obligation($conn, $companyId, $leaseId);
            $out['deposit'] = $deposit;
            if ($deposit) {
                $out['deposit_open'] = re_receipt_money(max(0, (float)$deposit['total_amount'] - (float)$deposit['allocated_amount']));
            }
            $out['credit_balance'] = re_receipt_money(get_tenant_credit_balance($conn, $tenantId, $companyId));

            $st = $conn->prepare("
                SELECT p.id, p.receipt_number, p.payment_date, p.amount, SUM(ra.amount_allocated) AS parked
                FROM re_receipt_allocations ra
                JOIN re_payments p ON p.id = ra.payment_id AND p.company_id = ra.company_id
                WHERE ra.company_id = ? AND ra.lease_id = ? AND ra.target_type = 'tenant_credit'
                  AND p.accounting_mode = 'invoice'
                GROUP BY p.id, p.receipt_number, p.payment_date, p.amount
                ORDER BY p.payment_date ASC, p.id ASC
            ");
            $st->execute([$companyId, $leaseId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $usage = re_receipt_overpayment_credit_usage($conn, $companyId, (int)$row['id'], $tenantId);
                $unused = re_receipt_money(min((float)$row['parked'], (float)($usage['remaining'] ?? 0)));
                if ($unused > 0.005) {
                    $row['unused'] = $unused;
                    $out['receipts'][] = $row;
                }
            }

            if ($deposit) {
                $st = $conn->prepare("
                    SELECT ra.id, ra.payment_id, ra.amount_allocated, ra.created_at, p.receipt_number
                    FROM re_receipt_allocations ra
                    JOIN re_payments p ON p.id = ra.payment_id AND p.company_id = ra.company_id
                    WHERE ra.company_id = ? AND ra.lease_id = ? AND ra.target_type = 'obligation'
                      AND ra.obligation_id = ? AND ra.notes = ?
                    ORDER BY ra.id ASC
                ");
                $st->execute([$companyId, $leaseId, (int)$deposit['id'], RE_CTD_NOTE]);
                $out['moves'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            $out['available'] = $deposit !== null
                && $out['deposit_open'] > 0.005
                && $out['credit_balance'] > 0.005
                && $out['receipts'] !== [];
        } catch (Throwable $e) {
            error_log('re_ctd_state: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('re_ctd_refresh_payment_status')) {
    function re_ctd_refresh_payment_status(PDO $conn, int $companyId, int $paymentId): void
    {
        $st = $conn->prepare("
            SELECT p.amount,
                   COALESCE(SUM(CASE WHEN ra.target_type IN ('invoice','obligation') THEN ra.amount_allocated END), 0) AS allocated,
                   COALESCE(SUM(CASE WHEN ra.target_type = 'tenant_credit' THEN ra.amount_allocated END), 0) AS credit
            FROM re_payments p
            LEFT JOIN re_receipt_allocations ra ON ra.payment_id = p.id AND ra.company_id = p.company_id
            WHERE p.id = ? AND p.company_id = ?
            GROUP BY p.id, p.amount
        ");
        $st->execute([$paymentId, $companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $status = 'unallocated';
        if ((float)$row['credit'] > 0.005) {
            $status = 'overpaid';
        } elseif ((float)$row['allocated'] >= (float)$row['amount'] - 0.005) {
            $status = 'allocated';
        } elseif ((float)$row['allocated'] > 0.005) {
            $status = 'partial';
        }
        $conn->prepare("UPDATE re_payments SET allocation_status = ? WHERE id = ? AND company_id = ?")
            ->execute([$status, $paymentId, $companyId]);
    }
}

if (!function_exists('re_ctd_change_parked_credit')) {
    /**
     * Raise (+) or lower (-) what this receipt parked as tenant credit: its allocation row,
     * its credit pocket in the credit log, and the tenant's balance.
     */
    function re_ctd_change_parked_credit(PDO $conn, int $companyId, int $paymentId, int $leaseId, int $tenantId, float $delta, ?int $userId): void
    {
        $delta = re_receipt_money($delta);

        $st = $conn->prepare("
            SELECT id, amount_allocated FROM re_receipt_allocations
            WHERE company_id = ? AND payment_id = ? AND target_type = 'tenant_credit'
            ORDER BY id ASC LIMIT 1 FOR UPDATE
        ");
        $st->execute([$companyId, $paymentId]);
        $alloc = $st->fetch(PDO::FETCH_ASSOC);
        if ($alloc) {
            $new = re_receipt_money((float)$alloc['amount_allocated'] + $delta);
            if ($new < -0.005) {
                throw new RuntimeException('The receipt does not have that much parked credit.');
            }
            if ($new <= 0.005) {
                $conn->prepare("DELETE FROM re_receipt_allocations WHERE id = ?")->execute([(int)$alloc['id']]);
            } else {
                $conn->prepare("UPDATE re_receipt_allocations SET amount_allocated = ? WHERE id = ?")->execute([$new, (int)$alloc['id']]);
            }
        } elseif ($delta > 0.005) {
            $conn->prepare("
                INSERT INTO re_receipt_allocations
                    (company_id, payment_id, lease_id, tenant_id, target_type, invoice_id, obligation_id, amount_allocated, notes, created_by)
                VALUES (?, ?, ?, ?, 'tenant_credit', NULL, NULL, ?, 'Unallocated balance credited to tenant', ?)
            ")->execute([$companyId, $paymentId, $leaseId, $tenantId, $delta, $userId]);
        } else {
            throw new RuntimeException('The receipt has no parked credit.');
        }

        $st = $conn->prepare("
            SELECT id, amount_aed FROM re_tenant_credit_transactions
            WHERE company_id = ? AND tenant_id = ? AND payment_id = ? AND type = 'credit'
            ORDER BY id ASC LIMIT 1 FOR UPDATE
        ");
        $st->execute([$companyId, $tenantId, $paymentId]);
        $txn = $st->fetch(PDO::FETCH_ASSOC);
        if ($txn) {
            $new = re_receipt_money((float)$txn['amount_aed'] + $delta);
            if ($new <= 0.005) {
                $conn->prepare("DELETE FROM re_tenant_credit_transactions WHERE id = ?")->execute([(int)$txn['id']]);
            } else {
                $conn->prepare("UPDATE re_tenant_credit_transactions SET amount_aed = ? WHERE id = ?")->execute([$new, (int)$txn['id']]);
            }
            $conn->prepare("UPDATE re_tenant_credit_balances SET balance_aed = balance_aed + ? WHERE tenant_id = ? AND company_id = ?")
                ->execute([$delta, $tenantId, $companyId]);
        } elseif ($delta > 0.005) {
            update_tenant_credit($conn, $tenantId, $companyId, $delta, 'credit', $paymentId, null, 'Unallocated balance credited to tenant', 'overpayment');
        } else {
            throw new RuntimeException('The credit log has no entry for this receipt.');
        }
    }
}

if (!function_exists('re_ctd_load_receipt')) {
    function re_ctd_load_receipt(PDO $conn, int $companyId, int $leaseId, int $paymentId): array
    {
        $st = $conn->prepare("
            SELECT p.*, l.tenant_id AS lease_tenant_id
            FROM re_payments p
            JOIN re_leases l ON l.id = p.lease_id AND l.company_id = p.company_id
            WHERE p.id = ? AND p.company_id = ? AND p.lease_id = ? AND p.accounting_mode = 'invoice'
            LIMIT 1 FOR UPDATE
        ");
        $st->execute([$paymentId, $companyId, $leaseId]);
        $payment = $st->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            throw new RuntimeException('Receipt not found on this lease.');
        }
        $block = re_receipt_edit_block_reason($conn, $companyId, $payment, (float)$payment['amount'], true);
        if ($block !== null) {
            throw new RuntimeException($block);
        }
        return $payment;
    }
}

if (!function_exists('re_ctd_apply')) {
    /**
     * @return array{success:bool,applied?:float,error?:string}
     */
    function re_ctd_apply(PDO $conn, int $companyId, int $leaseId, int $paymentId, float $amount, ?int $userId): array
    {
        $amount = re_receipt_money($amount);
        if ($amount <= 0.005) {
            return ['success' => false, 'error' => 'Enter an amount.'];
        }
        $conn->beginTransaction();
        try {
            $payment = re_ctd_load_receipt($conn, $companyId, $leaseId, $paymentId);
            $tenantId = (int)($payment['tenant_id'] ?: $payment['lease_tenant_id']);

            $deposit = re_ctd_deposit_obligation($conn, $companyId, $leaseId, true);
            if (!$deposit || !in_array((string)$deposit['status'], ['open', 'partially_allocated'], true)) {
                throw new RuntimeException('This lease has no open security deposit.');
            }
            $depositOpen = re_receipt_money((float)$deposit['total_amount'] - (float)$deposit['allocated_amount']);
            if ($amount > $depositOpen + 0.005) {
                throw new RuntimeException('Only AED ' . number_format($depositOpen, 2) . ' of the deposit is still open.');
            }

            $st = $conn->prepare("SELECT 1 FROM re_receipt_allocations WHERE company_id = ? AND payment_id = ? AND target_type = 'obligation' AND obligation_id = ? LIMIT 1");
            $st->execute([$companyId, $paymentId, (int)$deposit['id']]);
            if ($st->fetchColumn()) {
                throw new RuntimeException('This receipt already pays part of the deposit. Undo that first, then move the full amount in one go.');
            }

            $usage = re_receipt_overpayment_credit_usage($conn, $companyId, $paymentId, $tenantId);
            $unused = re_receipt_money((float)($usage['remaining'] ?? 0));
            if ($amount > $unused + 0.005) {
                throw new RuntimeException('Only AED ' . number_format($unused, 2) . ' of this receipt\'s credit is still unused.');
            }
            $balance = re_receipt_money(get_tenant_credit_balance($conn, $tenantId, $companyId));
            if ($amount > $balance + 0.005) {
                throw new RuntimeException('The tenant has only AED ' . number_format($balance, 2) . ' credit.');
            }

            re_ctd_change_parked_credit($conn, $companyId, $paymentId, $leaseId, $tenantId, -$amount, $userId);

            $conn->prepare("
                INSERT INTO re_receipt_allocations
                    (company_id, payment_id, lease_id, tenant_id, target_type, invoice_id, obligation_id, amount_allocated, notes, created_by)
                VALUES (?, ?, ?, ?, 'obligation', NULL, ?, ?, ?, ?)
            ")->execute([$companyId, $paymentId, $leaseId, $tenantId, (int)$deposit['id'], $amount, RE_CTD_NOTE, $userId]);
            re_receipt_apply_obligation_amount($conn, $companyId, (int)$deposit['id'], $amount);

            $posting = re_sd_post_liability_for_receipt_allocation($conn, $companyId, $paymentId, $leaseId, (int)$deposit['id'], $amount, $userId);
            if (empty($posting['success']) || !empty($posting['skipped'])) {
                throw new RuntimeException('Security deposit journal could not be posted: ' . ($posting['error'] ?? 'an earlier deposit journal exists for this receipt'));
            }

            re_receipt_edit_repost_journal($conn, $companyId, $paymentId, $userId);
            re_ctd_refresh_payment_status($conn, $companyId, $paymentId);

            re_sd_log($conn, $companyId, $leaseId, $tenantId, 'credit_moved_to_deposit', null, null, $amount,
                'Tenant credit from ' . (string)$payment['receipt_number'] . ' moved to security deposit', $userId,
                'credit_to_deposit', $paymentId, (int)$posting['journal_id'], null, (int)$deposit['id'], null);

            $conn->commit();
            return ['success' => true, 'applied' => $amount];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('re_ctd_undo')) {
    /**
     * @return array{success:bool,restored?:float,error?:string}
     */
    function re_ctd_undo(PDO $conn, int $companyId, int $leaseId, int $allocationId, ?int $userId): array
    {
        $conn->beginTransaction();
        try {
            $st = $conn->prepare("
                SELECT * FROM re_receipt_allocations
                WHERE id = ? AND company_id = ? AND lease_id = ? AND target_type = 'obligation' AND notes = ?
                LIMIT 1 FOR UPDATE
            ");
            $st->execute([$allocationId, $companyId, $leaseId, RE_CTD_NOTE]);
            $alloc = $st->fetch(PDO::FETCH_ASSOC);
            if (!$alloc) {
                throw new RuntimeException('That move was not found.');
            }
            $paymentId = (int)$alloc['payment_id'];
            $obligationId = (int)$alloc['obligation_id'];
            $amount = re_receipt_money($alloc['amount_allocated']);

            $payment = re_ctd_load_receipt($conn, $companyId, $leaseId, $paymentId);
            $tenantId = (int)($payment['tenant_id'] ?: $payment['lease_tenant_id']);

            $summary = re_sd_summary($conn, $companyId, $leaseId);
            if ((float)$summary['deductions'] > 0.005 || (float)$summary['refunds'] > 0.005) {
                throw new RuntimeException('The deposit already has deductions or a refund, so this cannot be undone.');
            }

            $st = $conn->prepare("
                SELECT id, related_journal_id FROM re_security_deposit_audit
                WHERE company_id = ? AND lease_id = ? AND related_receipt_id = ? AND related_obligation_id = ?
                  AND action_type = 'liability_posted'
                ORDER BY id DESC LIMIT 1
            ");
            $st->execute([$companyId, $leaseId, $paymentId, $obligationId]);
            $audit = $st->fetch(PDO::FETCH_ASSOC);
            if (!$audit || (int)$audit['related_journal_id'] <= 0) {
                throw new RuntimeException('The deposit journal of this move was not found.');
            }
            $rev = reverse_journal((int)$audit['related_journal_id'], 'Undo: credit moved to security deposit', $userId, null);
            if (empty($rev['success'])) {
                throw new RuntimeException('Could not reverse the deposit journal: ' . ($rev['error'] ?? 'Unknown error'));
            }
            // The summary counts liability_posted rows; this one no longer holds.
            $conn->prepare("UPDATE re_security_deposit_audit SET action_type = 'liability_posted_undone' WHERE id = ?")
                ->execute([(int)$audit['id']]);

            $conn->prepare("DELETE FROM re_receipt_allocations WHERE id = ?")->execute([$allocationId]);
            re_receipt_apply_obligation_amount($conn, $companyId, $obligationId, -$amount);
            re_ctd_change_parked_credit($conn, $companyId, $paymentId, $leaseId, $tenantId, $amount, $userId);

            re_receipt_edit_repost_journal($conn, $companyId, $paymentId, $userId);
            re_ctd_refresh_payment_status($conn, $companyId, $paymentId);

            re_sd_log($conn, $companyId, $leaseId, $tenantId, 'credit_moved_to_deposit_undone', null, null, $amount,
                'Undo: AED ' . number_format($amount, 2) . ' back to tenant credit on ' . (string)$payment['receipt_number'], $userId,
                'credit_to_deposit', $paymentId, (int)$rev['reversal_journal_id'], null, $obligationId, null);

            $conn->commit();
            return ['success' => true, 'restored' => $amount];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
