<?php
/**
 * Editing an Invoice Mode receipt after it was confirmed.
 *
 * A confirmed receipt has three things hanging off it: its allocation to invoices (and any
 * overpayment parked as tenant credit), its journal, and the tenant ledger entry. Changing the
 * amount has to redo all three, otherwise the invoices keep the old paid amounts and the journal
 * no longer matches the receipt.
 */

require_once __DIR__ . '/receipt_allocation_engine.php';

if (!function_exists('re_receipt_edit_block_reason')) {
    /**
     * Why this receipt cannot be edited right now, or null when it can.
     *
     * @param array<string,mixed> $payment   current re_payments row
     * @param bool $postingChanges           amount, date, bank or receipt number is changing
     */
    function re_receipt_edit_block_reason(PDO $conn, int $companyId, array $payment, float $newAmount, bool $postingChanges): ?string
    {
        if (!$postingChanges) {
            return null;
        }
        $paymentId = (int)$payment['id'];

        try {
            $st = $conn->prepare("
                SELECT 1
                FROM re_bank_reconciliation_matches m
                WHERE m.company_id = ? AND m.status = 'confirmed'
                  AND (
                        (m.source_table IN ('re_payments', 'payment') AND m.source_id = ?)
                     OR m.gl_line_id IN (
                            SELECT gl.id
                            FROM re_general_ledger gl
                            JOIN re_journal_headers jh ON jh.id = gl.journal_id
                            WHERE jh.company_id = ? AND jh.reference_type = 'payment' AND jh.reference_id = ?
                        )
                  )
                LIMIT 1
            ");
            $st->execute([$companyId, $paymentId, $companyId, $paymentId]);
            if ($st->fetchColumn()) {
                return 'This receipt is already reconciled to a bank statement line. Undo the reconciliation first, then edit the receipt.';
            }
        } catch (Throwable $e) {
            // Bank reconciliation tables not installed: nothing can be reconciled.
        }

        if (abs($newAmount - (float)$payment['amount']) < 0.005) {
            return null;
        }

        $usage = re_receipt_overpayment_credit_usage($conn, $companyId, $paymentId);
        if (!empty($usage['is_overpayment_receipt']) && ($usage['usage'] ?? 'unused') !== 'unused') {
            return 'The overpayment from this receipt (AED ' . number_format((float)$usage['credited'], 2)
                . ') was kept as tenant credit and AED ' . number_format((float)$usage['used'], 2)
                . ' of it has already been used, so the amount cannot be changed.';
        }

        $st = $conn->prepare("
            SELECT 1 FROM re_receipt_allocations
            WHERE company_id = ? AND payment_id = ? AND target_type = 'obligation'
            LIMIT 1
        ");
        $st->execute([$companyId, $paymentId]);
        if ($st->fetchColumn()) {
            return 'Part of this receipt was applied directly to a deposit or charge that has no invoice, so the amount cannot be changed here.';
        }

        $chequeId = (int)($payment['cheque_id'] ?? 0);
        if ($chequeId > 0) {
            $cheque = re_cheque_load($conn, $companyId, $chequeId);
            $chequeAmount = re_receipt_money($cheque['cheque_amount'] ?? 0);
            $st = $conn->prepare("
                SELECT COALESCE(SUM(amount), 0)
                FROM re_payments
                WHERE company_id = ? AND lease_id = ? AND cheque_id = ? AND id <> ?
                  AND accounting_mode = 'invoice' AND receipt_status = 'cleared'
            ");
            $st->execute([$companyId, (int)$payment['lease_id'], $chequeId, $paymentId]);
            $others = re_receipt_money($st->fetchColumn() ?: 0);
            if ($chequeAmount > 0 && ($others + $newAmount) > $chequeAmount + 0.02) {
                return 'The new amount exceeds the remaining cheque balance of AED ' . number_format(max(0, $chequeAmount - $others), 2) . '.';
            }
        }

        return null;
    }
}

if (!function_exists('re_receipt_edit_reallocate')) {
    /**
     * Undo the receipt's allocation and allocate its current amount again. The re_payments row
     * must already hold the new amount. Runs inside the caller's transaction and throws on failure.
     *
     * @return array{allocated:float,tenant_credit:float}
     */
    function re_receipt_edit_reallocate(PDO $conn, int $companyId, int $paymentId, ?int $userId): array
    {
        $st = $conn->prepare("
            SELECT p.*, COALESCE(p.tenant_id, l.tenant_id) AS resolved_tenant_id
            FROM re_payments p
            JOIN re_leases l ON l.id = p.lease_id AND l.company_id = p.company_id
            WHERE p.id = ? AND p.company_id = ? AND p.accounting_mode = 'invoice'
            LIMIT 1
            FOR UPDATE
        ");
        $st->execute([$paymentId, $companyId]);
        $payment = $st->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            throw new RuntimeException('Invoice Mode receipt not found.');
        }
        $leaseId = (int)$payment['lease_id'];
        $tenantId = (int)$payment['resolved_tenant_id'];
        $amount = re_receipt_money($payment['amount']);
        $chequeId = (int)($payment['cheque_id'] ?? 0);
        $receiptNumber = (string)($payment['receipt_number'] ?: ('PAY-' . $paymentId));

        // 1. Take the old allocation back off the invoices.
        $st = $conn->prepare("
            SELECT id, target_type, invoice_id, amount_allocated
            FROM re_receipt_allocations
            WHERE company_id = ? AND payment_id = ?
            ORDER BY id
        ");
        $st->execute([$companyId, $paymentId]);
        $oldKeys = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $old) {
            $oldAmount = re_receipt_money($old['amount_allocated']);
            if ($old['target_type'] === 'invoice') {
                $invoiceId = (int)$old['invoice_id'];
                $inv = $conn->prepare("
                    SELECT id, total_amount, paid_amount
                    FROM re_invoices
                    WHERE id = ? AND company_id = ?
                    LIMIT 1
                    FOR UPDATE
                ");
                $inv->execute([$invoiceId, $companyId]);
                $invoice = $inv->fetch(PDO::FETCH_ASSOC);
                if (!$invoice) {
                    throw new RuntimeException('Invoice of the old allocation was not found.');
                }
                $newPaid = re_receipt_money(max(0, (float)$invoice['paid_amount'] - $oldAmount));
                $newOutstanding = re_receipt_money(max(0, (float)$invoice['total_amount'] - $newPaid));
                $status = $newOutstanding <= 0.005 ? 'paid' : ($newPaid > 0.005 ? 'partial' : 'sent');
                $conn->prepare("
                    UPDATE re_invoices
                    SET paid_amount = ?, outstanding_amount = ?, status = ?
                    WHERE id = ? AND company_id = ?
                ")->execute([$newPaid, $newOutstanding, $status, $invoiceId, $companyId]);

                // Same per-line share as re_receipt_apply_invoice_allocation, taken back.
                $lines = $conn->prepare("
                    SELECT obligation_id, line_total
                    FROM re_invoice_items
                    WHERE invoice_id = ? AND company_id = ? AND obligation_id IS NOT NULL
                ");
                $lines->execute([$invoiceId, $companyId]);
                $invoiceLines = $lines->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $lineTotal = array_sum(array_map(static fn($r) => (float)$r['line_total'], $invoiceLines));
                foreach ($invoiceLines as $line) {
                    $share = $lineTotal > 0 ? re_receipt_money($oldAmount * ((float)$line['line_total'] / $lineTotal)) : $oldAmount;
                    re_receipt_apply_obligation_amount($conn, $companyId, (int)$line['obligation_id'], -$share);
                }
                $oldKeys[] = 'invoice:' . $invoiceId;
            } elseif ($old['target_type'] === 'tenant_credit') {
                // Unused (checked before the edit), so the parked overpayment simply goes away.
                $conn->prepare("
                    UPDATE re_tenant_credit_balances
                    SET balance_aed = balance_aed - ?
                    WHERE tenant_id = ? AND company_id = ?
                ")->execute([$oldAmount, $tenantId, $companyId]);
                $conn->prepare("
                    DELETE FROM re_tenant_credit_transactions
                    WHERE company_id = ? AND payment_id = ? AND type = 'credit'
                ")->execute([$companyId, $paymentId]);
            } else {
                throw new RuntimeException('This receipt has a direct deposit/charge allocation and cannot be re-allocated.');
            }
        }
        $conn->prepare("DELETE FROM re_receipt_allocations WHERE company_id = ? AND payment_id = ?")
            ->execute([$companyId, $paymentId]);

        // 2. Allocate the new amount: the invoices it paid before first, then the usual order.
        $preview = re_receipt_preview($conn, $companyId, $leaseId, $amount, $chequeId);
        if (empty($preview['success'])) {
            throw new RuntimeException((string)($preview['error'] ?? 'Could not load allocation targets.'));
        }
        $targets = array_values(array_filter(
            $preview['targets'] ?? [],
            static fn($t) => ($t['target_type'] ?? '') === 'invoice'
        ));
        $preferred = array_values(array_unique(array_merge($oldKeys, $preview['preferred_target_keys'] ?? [])));
        $allocations = re_receipt_default_allocations_preferring($targets, $amount, $preferred);

        $allocatedTotal = 0.0;
        foreach ($allocations as $key => $allocAmount) {
            $allocAmount = re_receipt_money($allocAmount);
            if ($allocAmount <= 0 || !preg_match('/^invoice:(\d+)$/', (string)$key, $m)) {
                continue;
            }
            re_receipt_apply_invoice_allocation($conn, $companyId, (int)$m[1], $allocAmount, $paymentId, $leaseId, $tenantId, $userId);
            $allocatedTotal += $allocAmount;
        }
        $allocatedTotal = re_receipt_money($allocatedTotal);

        // 3. Anything left over is an overpayment, parked as tenant credit like a new receipt.
        $creditAmount = re_receipt_money($amount - $allocatedTotal);
        if ($creditAmount > 0.005 && $tenantId > 0) {
            update_tenant_credit(
                $conn,
                $tenantId,
                $companyId,
                $creditAmount,
                'credit',
                $paymentId,
                null,
                'Invoice Mode overpayment from receipt ' . $receiptNumber,
                'overpayment'
            );
            $conn->prepare("
                INSERT INTO re_receipt_allocations
                    (company_id, payment_id, lease_id, tenant_id, target_type, invoice_id, obligation_id, amount_allocated, notes, created_by)
                VALUES (?, ?, ?, ?, 'tenant_credit', NULL, NULL, ?, 'Unallocated balance credited to tenant', ?)
            ")->execute([$companyId, $paymentId, $leaseId, $tenantId, $creditAmount, $userId]);
        } else {
            $creditAmount = 0.0;
        }

        $allocationStatus = 'unallocated';
        if ($creditAmount > 0.005) {
            $allocationStatus = 'overpaid';
        } elseif ($allocatedTotal >= $amount - 0.005) {
            $allocationStatus = 'allocated';
        } elseif ($allocatedTotal > 0.005) {
            $allocationStatus = 'partial';
        }
        $conn->prepare("UPDATE re_payments SET allocation_status = ? WHERE id = ? AND company_id = ?")
            ->execute([$allocationStatus, $paymentId, $companyId]);

        if (function_exists('re_cheque_sync_cleared_from_financial_coverage')) {
            $coverageSync = re_cheque_sync_cleared_from_financial_coverage($conn, $companyId, $leaseId, $userId, $paymentId);
            if (!empty($coverageSync['errors'])) {
                throw new RuntimeException(implode(' ', $coverageSync['errors']));
            }
        }

        return ['allocated' => $allocatedTotal, 'tenant_credit' => $creditAmount];
    }
}

if (!function_exists('re_receipt_edit_repost_journal')) {
    /**
     * Reverse the receipt's live journal (and its tenant ledger entry) and post it again from the
     * current receipt and allocation. Runs inside the caller's transaction and throws on failure.
     *
     * @return array{journal_id:int,reversed_journal_id:?int}
     */
    function re_receipt_edit_repost_journal(PDO $conn, int $companyId, int $paymentId, ?int $userId): array
    {
        $st = $conn->prepare("
            SELECT id
            FROM re_journal_headers
            WHERE company_id = ? AND reference_type = 'payment' AND reference_id = ?
              AND is_posted = 1 AND is_reversed = 0 AND journal_type <> 'reversal'
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([$companyId, $paymentId]);
        $liveJournalId = (int)($st->fetchColumn() ?: 0);

        if ($liveJournalId > 0) {
            $reversal = reverse_journal($liveJournalId, "Reversal — payment #{$paymentId} edited", $userId, null);
            if (empty($reversal['success'])) {
                throw new RuntimeException('Could not reverse the receipt journal: ' . ($reversal['error'] ?? 'Unknown error'));
            }
            re_receipt_edit_reverse_tenant_ledger($conn, $companyId, $liveJournalId, (int)$reversal['reversal_journal_id']);
        }

        $posting = post_invoice_mode_receipt_to_accounting($paymentId, $companyId, $userId);
        if (empty($posting['success']) || empty($posting['journal_id'])) {
            throw new RuntimeException('Receipt accounting posting failed: ' . ($posting['error'] ?? 'Unknown error'));
        }

        return ['journal_id' => (int)$posting['journal_id'], 'reversed_journal_id' => $liveJournalId ?: null];
    }
}

if (!function_exists('re_receipt_edit_reverse_tenant_ledger')) {
    /**
     * reverse_journal() only reverses the general ledger. Put the matching entry on the tenant
     * ledger so the re-posted receipt is not counted twice there.
     */
    function re_receipt_edit_reverse_tenant_ledger(PDO $conn, int $companyId, int $journalId, int $reversalJournalId): void
    {
        $st = $conn->prepare("
            SELECT e.ledger_id, MAX(e.entry_date) AS entry_date, MAX(e.reference) AS reference,
                   MAX(jl.account_id) AS account_id,
                   COALESCE(SUM(e.credit_amount), 0) - COALESCE(SUM(e.debit_amount), 0) AS net_credit
            FROM re_account_ledger_entries e
            LEFT JOIN re_journal_lines jl ON jl.id = e.journal_line_id
            WHERE e.company_id = ? AND e.journal_id = ?
            GROUP BY e.ledger_id
        ");
        $st->execute([$companyId, $journalId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $net = round((float)$row['net_credit'], 2);
            if (abs($net) < 0.005) {
                continue;
            }
            // The ledger entry must point at a journal line: the reversal's line on the same account.
            $line = $conn->prepare("
                SELECT id FROM re_journal_lines
                WHERE journal_id = ?
                ORDER BY (account_id = ?) DESC, id
                LIMIT 1
            ");
            $line->execute([$reversalJournalId, (int)$row['account_id']]);
            $lineId = (int)($line->fetchColumn() ?: 0);
            $ok = $lineId > 0 && post_to_tenant_ledger(
                (int)$row['ledger_id'],
                (string)$row['entry_date'],
                $net > 0 ? $net : 0,
                $net < 0 ? abs($net) : 0,
                'Reversal - receipt edited - ' . (string)$row['reference'],
                (string)$row['reference'],
                $companyId,
                $reversalJournalId,
                $lineId
            );
            if (!$ok) {
                throw new RuntimeException('Could not reverse the tenant ledger entry of the receipt.');
            }
        }
    }
}
