<?php
/**
 * Phase 7 security deposit controls.
 *
 * Security deposits are refundable liabilities, not revenue. These helpers are
 * additive and avoid historical correction/reposting.
 */
declare(strict_types=1);

require_once __DIR__ . '/obligation_preview_helper.php';
require_once __DIR__ . '/../accounting/accounting_integration.php';

if (!function_exists('re_sd_money')) {
    function re_sd_money($value): float
    {
        return round((float)$value, 2);
    }
}

if (!function_exists('re_sd_setting')) {
    function re_sd_setting(PDO $conn, string $key, string $default = ''): string
    {
        try {
            $stmt = $conn->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return $value === false ? $default : (string)$value;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('re_sd_log')) {
    function re_sd_log(PDO $conn, int $companyId, int $leaseId, ?int $tenantId, string $actionType, ?string $oldValue = null, ?string $newValue = null, ?float $amount = null, string $reason = '', ?int $userId = null, string $source = 'system', ?int $receiptId = null, ?int $journalId = null, ?int $moveOutId = null, ?int $obligationId = null, ?int $refundId = null): void
    {
        try {
            $stmt = $conn->prepare("
                INSERT INTO re_security_deposit_audit
                    (company_id, lease_id, tenant_id, action_type, old_value, new_value, amount, reason,
                     changed_by, source, related_receipt_id, related_journal_id, related_move_out_id,
                     related_obligation_id, related_refund_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $companyId,
                $leaseId,
                $tenantId,
                $actionType,
                $oldValue,
                $newValue,
                $amount,
                $reason ?: null,
                $userId,
                $source,
                $receiptId,
                $journalId,
                $moveOutId,
                $obligationId,
                $refundId,
            ]);
        } catch (Throwable $e) {
            error_log('Security deposit audit failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('re_sd_load_lease')) {
    function re_sd_load_lease(PDO $conn, int $companyId, int $leaseId): ?array
    {
        $stmt = $conn->prepare("
            SELECT l.*, t.first_name, t.last_name, t.company_name, t.tenant_type
            FROM re_leases l
            LEFT JOIN re_tenants t ON t.id = l.tenant_id
            WHERE l.id = ? AND l.company_id = ?
            LIMIT 1
        ");
        $stmt->execute([$leaseId, $companyId]);
        $lease = $stmt->fetch(PDO::FETCH_ASSOC);
        return $lease ?: null;
    }
}

if (!function_exists('re_sd_deposit_lease_id')) {
    /**
     * The lease that holds the tenant's security deposit. A renewal lease
     * carries no deposit of its own (re_sd_summary() expects 0 on it): the
     * deposit stays on the first lease of the tenancy. Walks parent_lease_id
     * up to the first lease that is not a renewal or has a deposit obligation;
     * renewals saved without the link fall back to the first lease of the same
     * tenant and unit (move_in_chain_helper.php).
     */
    function re_sd_deposit_lease_id(PDO $conn, int $companyId, int $leaseId): int
    {
        $current = $leaseId;
        $seen = [];
        $obl = $conn->prepare("SELECT COUNT(*) FROM re_obligations WHERE company_id = ? AND lease_id = ? AND obligation_type = 'security_deposit'");
        for ($i = 0; $i < 20 && !isset($seen[$current]); $i++) {
            $seen[$current] = true;
            $lease = re_sd_load_lease($conn, $companyId, $current);
            if (!$lease) {
                break;
            }
            if (empty($lease['is_renewal_lease'])) {
                return $current;
            }
            try {
                $obl->execute([$companyId, $current]);
                if ((int)$obl->fetchColumn() > 0) {
                    return $current;
                }
            } catch (Throwable $e) {
            }
            $parent = (int)($lease['parent_lease_id'] ?? 0);
            if ($parent <= 0) {
                require_once __DIR__ . '/move_in_chain_helper.php';
                $first = re_move_in_first_lease($conn, $companyId, $current);
                return $first ? (int)$first['id'] : $current;
            }
            $current = $parent;
        }
        return $current ?: $leaseId;
    }
}

if (!function_exists('re_sd_summary')) {
    /**
     * @return array<string,mixed>
     */
    function re_sd_summary(PDO $conn, int $companyId, int $leaseId): array
    {
        $lease = re_sd_load_lease($conn, $companyId, $leaseId) ?: [];
        $isRenewalLease = !empty($lease['is_renewal_lease']);
        $storedSecurityDeposit = re_sd_money($lease['security_deposit'] ?? 0);
        $expected = $isRenewalLease ? 0.0 : $storedSecurityDeposit;
        $tenantId = !empty($lease['tenant_id']) ? (int)$lease['tenant_id'] : null;

        $obligation = null;
        $obligationAmount = 0.0;
        $allocated = 0.0;
        try {
            $stmt = $conn->prepare("
                SELECT *
                FROM re_obligations
                WHERE company_id = ? AND lease_id = ? AND obligation_type = 'security_deposit'
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmt->execute([$companyId, $leaseId]);
            $obligation = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($obligation) {
                $obligationAmount = re_sd_money($obligation['total_amount'] ?? 0);
                $allocated = re_sd_money($obligation['allocated_amount'] ?? 0);
            }
        } catch (Throwable $e) {}

        $receiptRows = [];
        $receiptTotal = 0.0;
        try {
            $stmt = $conn->prepare("
                SELECT p.id, p.receipt_number, p.payment_date, p.cleared_date, p.amount AS receipt_amount,
                       ra.amount_allocated, p.payment_method, p.reference_number
                FROM re_receipt_allocations ra
                JOIN re_payments p ON p.id = ra.payment_id AND p.company_id = ra.company_id
                WHERE ra.company_id = ? AND ra.lease_id = ? AND ra.target_type = 'obligation'
                  AND ra.obligation_id = ?
                ORDER BY p.payment_date ASC, p.id ASC
            ");
            $stmt->execute([$companyId, $leaseId, (int)($obligation['id'] ?? 0)]);
            $receiptRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($receiptRows as $row) {
                $receiptTotal += (float)($row['amount_allocated'] ?? 0);
            }
            $receiptTotal = re_sd_money($receiptTotal);
        } catch (Throwable $e) {}

        $liabilityPosted = 0.0;
        $journalRows = [];
        try {
            $stmt = $conn->prepare("
                SELECT DISTINCT jh.id, jh.journal_number, jh.journal_date, jh.total_credit
                FROM re_security_deposit_audit a
                JOIN re_journal_headers jh ON jh.id = a.related_journal_id
                WHERE a.company_id = ? AND a.lease_id = ? AND a.action_type = 'liability_posted'
                ORDER BY jh.journal_date ASC, jh.id ASC
            ");
            $stmt->execute([$companyId, $leaseId]);
            $journalRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($journalRows as $row) {
                $liabilityPosted += (float)($row['total_credit'] ?? 0);
            }
            $liabilityPosted = re_sd_money($liabilityPosted);
        } catch (Throwable $e) {}

        $pendingCollections = 0.0;
        try {
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(cheque_amount), 0)
                FROM re_post_dated_cheques
                WHERE company_id = ? AND lease_id = ?
                  AND status IN ('pending','collected','hold','held_by_finance','deposited')
                  AND LOWER(COALESCE(notes, '')) LIKE '%security%deposit%'
            ");
            $stmt->execute([$companyId, $leaseId]);
            $pendingCollections = re_sd_money($stmt->fetchColumn());
        } catch (Throwable $e) {}

        $deductions = 0.0;
        try {
            $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM re_security_deposit_deductions WHERE company_id = ? AND lease_id = ? AND status IN ('approved','applied')");
            $stmt->execute([$companyId, $leaseId]);
            $deductions = re_sd_money($stmt->fetchColumn());
        } catch (Throwable $e) {}

        $refunds = 0.0;
        try {
            $stmt = $conn->prepare("SELECT COALESCE(SUM(refund_amount), 0) FROM re_security_deposit_settlements WHERE company_id = ? AND lease_id = ? AND status IN ('refunded','partially_refunded')");
            $stmt->execute([$companyId, $leaseId]);
            $refunds = re_sd_money($stmt->fetchColumn());
        } catch (Throwable $e) {}

        $refundable = re_sd_money(max(0, $allocated - $deductions - $refunds));
        $status = 'not_expected';
        if ($expected > 0) {
            if ($refunds >= $allocated - 0.005 && $allocated > 0) {
                $status = 'refunded';
            } elseif ($deductions > 0 && $refundable <= 0.005) {
                $status = 'deducted';
            } elseif ($allocated <= 0.005 && $pendingCollections > 0) {
                $status = 'pending_clearance';
            } elseif ($allocated <= 0.005) {
                $status = 'expected';
            } elseif ($allocated + 0.005 < $expected) {
                $status = 'partially_received';
            } else {
                $status = 'fully_received';
            }
        }

        return [
            'lease' => $lease,
            'tenant_id' => $tenantId,
            'expected' => $expected,
            'stored_security_deposit' => $storedSecurityDeposit,
            'is_renewal_lease' => $isRenewalLease ? 1 : 0,
            'obligation' => $obligation,
            'obligation_amount' => $obligationAmount,
            'allocated' => $allocated,
            'received' => $receiptTotal,
            'pending_collections' => $pendingCollections,
            'liability_posted' => $liabilityPosted,
            'refundable' => $refundable,
            'deductions' => $deductions,
            'refunds' => $refunds,
            'status' => $status,
            'receipts' => $receiptRows,
            'journals' => $journalRows,
        ];
    }
}

if (!function_exists('re_sd_post_liability_for_receipt_allocation')) {
    /**
     * Post Dr Bank/Cash, Cr Security Deposit Liability for a receipt amount
     * allocated to a security deposit obligation. Idempotent per receipt +
     * obligation via audit record.
     */
    function re_sd_post_liability_for_receipt_allocation(PDO $conn, int $companyId, int $paymentId, int $leaseId, int $obligationId, float $amount, ?int $userId = null): array
    {
        $amount = re_sd_money($amount);
        if ($amount <= 0) {
            return ['success' => true, 'journal_id' => null, 'skipped' => true, 'error' => null];
        }

        $exists = $conn->prepare("
            SELECT related_journal_id
            FROM re_security_deposit_audit
            WHERE company_id = ? AND lease_id = ? AND related_receipt_id = ?
              AND related_obligation_id = ? AND action_type = 'liability_posted'
            LIMIT 1
        ");
        $exists->execute([$companyId, $leaseId, $paymentId, $obligationId]);
        $existingJournal = $exists->fetchColumn();
        if ($existingJournal) {
            return ['success' => true, 'journal_id' => (int)$existingJournal, 'skipped' => true, 'error' => null];
        }

        $paymentStmt = $conn->prepare("
            SELECT p.*, l.lease_number, l.tenant_id,
                   t.first_name, t.last_name, t.company_name, t.tenant_type
            FROM re_payments p
            JOIN re_leases l ON l.id = p.lease_id AND l.company_id = p.company_id
            LEFT JOIN re_tenants t ON t.id = l.tenant_id
            WHERE p.id = ? AND p.company_id = ? AND p.lease_id = ?
            LIMIT 1
        ");
        $paymentStmt->execute([$paymentId, $companyId, $leaseId]);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Deposit receipt not found'];
        }

        $accountCode = re_sd_setting($conn, 're_security_deposit_liability_account_code', '2200');
        $liability = find_account_by_code($accountCode, $companyId);
        if (!$liability) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Security deposit liability account (' . $accountCode . ') not found'];
        }

        $bankAccount = null;
        if (!empty($payment['bank_account_id'])) {
            $bankStmt = $conn->prepare("
                SELECT coa.id, coa.account_code, coa.account_name
                FROM re_bank_accounts ba
                JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id
                WHERE ba.id = ? AND ba.company_id = ? AND ba.is_active = 1
                LIMIT 1
            ");
            $bankStmt->execute([(int)$payment['bank_account_id'], $companyId]);
            $bankAccount = $bankStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$bankAccount) {
            $bankAccount = find_account_by_code(determine_bank_account((string)$payment['payment_method'], $companyId), $companyId)
                ?: find_account_by_code('1110', $companyId);
        }
        if (!$bankAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Bank/Cash account not found'];
        }

        $tenantName = ($payment['tenant_type'] ?? '') === 'company'
            ? (string)($payment['company_name'] ?? 'Tenant')
            : trim((string)($payment['first_name'] ?? '') . ' ' . (string)($payment['last_name'] ?? ''));
        $ref = (string)($payment['receipt_number'] ?? ('PAY-' . $paymentId));
        $desc = 'Security deposit receipt - Lease ' . (string)$payment['lease_number'] . ' - ' . $tenantName;
        $lines = [
            [
                'account_id' => $bankAccount['id'],
                'debit' => $amount,
                'credit' => 0,
                'description' => $desc,
                'reference' => $ref,
            ],
            [
                'account_id' => $liability['id'],
                'debit' => 0,
                'credit' => $amount,
                'description' => $desc,
                'reference' => $ref,
            ],
        ];

        $result = create_and_post_journal($companyId, 'deposit', 'security_deposit_receipt', $paymentId, $lines, $desc, (string)$payment['payment_date'], $userId);
        if (!empty($result['success'])) {
            re_sd_log($conn, $companyId, $leaseId, !empty($payment['tenant_id']) ? (int)$payment['tenant_id'] : null, 'liability_posted', null, null, $amount, 'Security deposit receipt liability posted', $userId, 'receipt_allocation', $paymentId, (int)$result['journal_id'], null, $obligationId, null);
        }
        return $result;
    }
}

if (!function_exists('re_sd_diagnostics')) {
    function re_sd_diagnostics(PDO $conn, int $companyId): array
    {
        $out = [
            'missing_obligation' => [],
            'obligation_no_receipt' => [],
            'received_no_liability_journal' => [],
            'liability_without_receipt' => [],
            'refund_without_settlement' => [],
            'deduction_without_approval' => [],
            'mismatches' => [],
        ];

        try {
            $stmt = $conn->prepare("
                SELECT l.id, l.lease_number, l.security_deposit
                FROM re_leases l
                LEFT JOIN re_obligations o ON o.company_id = l.company_id AND o.lease_id = l.id AND o.obligation_type = 'security_deposit'
                WHERE l.company_id = ? AND COALESCE(l.accounting_mode, 'legacy') = 'invoice'
                  AND COALESCE(l.is_renewal_lease, 0) = 0
                  AND l.security_deposit > 0 AND o.id IS NULL
                ORDER BY l.id DESC
            ");
            $stmt->execute([$companyId]);
            $out['missing_obligation'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}

        try {
            $stmt = $conn->prepare("
                SELECT o.id, o.lease_id, l.lease_number, o.total_amount, o.allocated_amount
                FROM re_obligations o
                JOIN re_leases l ON l.id = o.lease_id AND l.company_id = o.company_id
                WHERE o.company_id = ? AND o.obligation_type = 'security_deposit'
                  AND o.allocated_amount <= 0.005
                ORDER BY o.due_date ASC
            ");
            $stmt->execute([$companyId]);
            $out['obligation_no_receipt'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}

        try {
            $stmt = $conn->prepare("
                SELECT ra.payment_id, ra.lease_id, l.lease_number, ra.obligation_id, ra.amount_allocated
                FROM re_receipt_allocations ra
                JOIN re_obligations o ON o.id = ra.obligation_id AND o.company_id = ra.company_id
                JOIN re_leases l ON l.id = ra.lease_id AND l.company_id = ra.company_id
                LEFT JOIN re_security_deposit_audit a
                  ON a.company_id = ra.company_id AND a.related_receipt_id = ra.payment_id
                 AND a.related_obligation_id = ra.obligation_id AND a.action_type = 'liability_posted'
                WHERE ra.company_id = ? AND o.obligation_type = 'security_deposit' AND a.id IS NULL
                ORDER BY ra.created_at DESC
            ");
            $stmt->execute([$companyId]);
            $out['received_no_liability_journal'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}

        try {
            $stmt = $conn->prepare("
                SELECT a.*
                FROM re_security_deposit_audit a
                LEFT JOIN re_payments p ON p.id = a.related_receipt_id AND p.company_id = a.company_id
                WHERE a.company_id = ? AND a.action_type = 'liability_posted' AND p.id IS NULL
            ");
            $stmt->execute([$companyId]);
            $out['liability_without_receipt'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}

        try {
            $stmt = $conn->prepare("
                SELECT r.id, r.lease_id, l.lease_number, r.amount, r.refund_date
                FROM re_tenant_refunds r
                JOIN re_leases l ON l.id = r.lease_id AND l.company_id = r.company_id
                LEFT JOIN re_security_deposit_settlements s ON s.company_id = r.company_id AND s.refund_id = r.id
                WHERE r.company_id = ? AND s.id IS NULL AND LOWER(COALESCE(r.refund_reason, '')) LIKE '%deposit%'
            ");
            $stmt->execute([$companyId]);
            $out['refund_without_settlement'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}

        try {
            $stmt = $conn->prepare("SELECT * FROM re_security_deposit_deductions WHERE company_id = ? AND status = 'draft' AND amount > 0 ORDER BY created_at DESC");
            $stmt->execute([$companyId]);
            $out['deduction_without_approval'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}

        try {
            $stmt = $conn->prepare("SELECT id FROM re_leases WHERE company_id = ? AND security_deposit > 0 ORDER BY id DESC LIMIT 250");
            $stmt->execute([$companyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $leaseId) {
                $summary = re_sd_summary($conn, $companyId, (int)$leaseId);
                if (abs((float)$summary['received'] - (float)$summary['liability_posted']) > 0.02) {
                    $out['mismatches'][] = [
                        'lease_id' => (int)$leaseId,
                        'lease_number' => $summary['lease']['lease_number'] ?? ('#' . $leaseId),
                        'expected' => $summary['expected'],
                        'received' => $summary['received'],
                        'liability_posted' => $summary['liability_posted'],
                        'refundable' => $summary['refundable'],
                    ];
                }
            }
        } catch (Throwable $e) {}

        return $out;
    }
}


if (!function_exists('re_sd_load_move_out')) {
    function re_sd_load_move_out(PDO $conn, int $companyId, int $moveOutId): ?array
    {
        $stmt = $conn->prepare("
            SELECT mo.*, l.lease_number, l.tenant_id, l.accounting_mode,
                   t.first_name, t.last_name, t.company_name, t.tenant_type
            FROM re_move_outs mo
            JOIN re_leases l ON l.id = mo.lease_id AND l.company_id = mo.company_id
            LEFT JOIN re_tenants t ON t.id = l.tenant_id
            WHERE mo.id = ? AND mo.company_id = ?
            LIMIT 1
        ");
        $stmt->execute([$moveOutId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('re_sd_settlement_for_move_out')) {
    function re_sd_settlement_for_move_out(PDO $conn, int $companyId, int $moveOutId): ?array
    {
        try {
            $stmt = $conn->prepare("SELECT * FROM re_security_deposit_settlements WHERE company_id = ? AND move_out_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$companyId, $moveOutId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('re_sd_sync_move_out_deductions')) {
    /**
     * Rebuild a move-out's draft deductions from the current damage costs and
     * standard move-out charges. Run when the settlement is prepared and again
     * just before it is approved, so a cost or charge changed in between is
     * never approved at its old amount. Call inside a transaction.
     */
    function re_sd_sync_move_out_deductions(PDO $conn, int $companyId, int $moveOutId, int $leaseId, ?int $tenantId, ?int $userId): void
    {
        $dt = $conn->prepare("SELECT COALESCE(SUM(repair_cost), 0) FROM re_move_out_damages WHERE move_out_id = ? AND company_id = ?");
        $dt->execute([$moveOutId, $companyId]);
        $damageTotal = re_sd_money($dt->fetchColumn());
        // Keep the damage deduction in line with the current damage assessment
        $existing = $conn->prepare("
            SELECT id, amount FROM re_security_deposit_deductions
            WHERE company_id = ? AND move_out_id = ? AND deduction_type = 'damage' AND status IN ('draft','approved')
            LIMIT 1
        ");
        $existing->execute([$companyId, $moveOutId]);
        $existingDeduction = $existing->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existingDeduction && $damageTotal <= 0) {
            $conn->prepare("UPDATE re_security_deposit_deductions SET status = 'cancelled' WHERE id = ?")
                ->execute([(int)$existingDeduction['id']]);
        } elseif ($existingDeduction && re_sd_money($existingDeduction['amount']) !== $damageTotal) {
            $conn->prepare("UPDATE re_security_deposit_deductions SET amount = ?, status = 'draft', approved_by = NULL, approved_at = NULL WHERE id = ?")
                ->execute([$damageTotal, (int)$existingDeduction['id']]);
        }

        if ($damageTotal > 0) {
            if (!$existingDeduction) {
                $conn->prepare("
                    INSERT INTO re_security_deposit_deductions
                        (company_id, lease_id, tenant_id, move_out_id, deduction_type, amount, reason, status, created_by)
                    VALUES (?, ?, ?, ?, 'damage', ?, 'Damage assessment total from move-out inspection', 'draft', ?)
                ")->execute([$companyId, $leaseId, $tenantId, $moveOutId, $damageTotal, $userId]);
                re_sd_log($conn, $companyId, $leaseId, $tenantId, 'deduction_requested', null, 'damage', $damageTotal, 'Damage assessment deduction drafted', $userId, 'move_out_view', null, null, $moveOutId, null, null);
            }
        }

        // Standard move-out charges (cleaning, painting, ...): one deduction each
        require_once __DIR__ . '/move_out_charges_helper.php';
        rmoc_sync_deductions($conn, $companyId, $moveOutId, $leaseId, $tenantId, $userId);
    }
}

if (!function_exists('re_sd_prepare_move_out_settlement')) {
    function re_sd_prepare_move_out_settlement(PDO $conn, int $companyId, int $moveOutId, ?int $userId = null): array
    {
        $moveOut = re_sd_load_move_out($conn, $companyId, $moveOutId);
        if (!$moveOut) {
            return ['success' => false, 'error' => 'Move-out record not found.'];
        }
        if (in_array((string)($moveOut['status'] ?? ''), ['completed', 'cancelled'], true)) {
            return ['success' => false, 'error' => 'This move-out is ' . $moveOut['status'] . '; the deposit settlement can no longer be changed.'];
        }
        // A renewal's deposit sits on the first lease of the tenancy
        $leaseId = re_sd_deposit_lease_id($conn, $companyId, (int)$moveOut['lease_id']);
        $tenantId = !empty($moveOut['tenant_id']) ? (int)$moveOut['tenant_id'] : null;
        $summary = re_sd_summary($conn, $companyId, $leaseId);
        $received = (float)$summary['allocated'];
        $expected = (float)$summary['expected'];
        $damageTotal = re_sd_money($moveOut['damage_assessment_total'] ?? 0);

        $conn->beginTransaction();
        try {
            $settlement = re_sd_settlement_for_move_out($conn, $companyId, $moveOutId);
            if (!$settlement) {
                $stmt = $conn->prepare("
                    INSERT INTO re_security_deposit_settlements
                        (company_id, lease_id, tenant_id, move_out_id, expected_amount, received_amount,
                         deduction_amount, refund_amount, retained_amount, status, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, 0.00, ?, 0.00, 'draft', 'Prepared from move-out deposit settlement preview', ?)
                ");
                $stmt->execute([$companyId, $leaseId, $tenantId, $moveOutId, $expected, $received, $received, $userId]);
                $settlementId = (int)$conn->lastInsertId();
            } else {
                $settlementId = (int)$settlement['id'];
                if (!empty($settlement['journal_id']) || !in_array((string)$settlement['status'], ['draft', 'approved', 'cancelled'], true)) {
                    throw new RuntimeException('This deposit settlement is already processed and cannot be recalculated.');
                }
                // Recalculating an approved (or closed, unposted) settlement sends it back for approval
                $conn->prepare("
                    UPDATE re_security_deposit_settlements
                    SET expected_amount = ?, received_amount = ?, deduction_amount = 0.00, refund_amount = ?, retained_amount = 0.00,
                        status = 'draft', approved_by = NULL, approved_at = NULL, updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ")->execute([$expected, $received, $received, $settlementId, $companyId]);
            }

            // Damage and standard charge deductions, from the current figures
            re_sd_sync_move_out_deductions($conn, $companyId, $moveOutId, $leaseId, $tenantId, $userId);

            $conn->prepare("UPDATE re_move_outs SET status = 'deposit_processing' WHERE id = ? AND company_id = ?")
                ->execute([$moveOutId, $companyId]);
            re_sd_log($conn, $companyId, $leaseId, $tenantId, 'settlement_prepared', null, null, $received, 'Deposit settlement preview prepared', $userId, 'move_out_view', null, null, $moveOutId, null, null);
            $conn->commit();
            return ['success' => true, 'settlement_id' => $settlementId, 'received' => $received, 'damage_total' => $damageTotal];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('re_sd_approve_move_out_deductions')) {
    /**
     * Approve deductions and work out the refund. Refuses when a deposit is
     * expected but none has been received, unless $confirmNoDeposit is set:
     * then the settlement is closed (status 'cancelled', nothing to post).
     * Returns 'excess' = approved damage not covered by the deposit, which
     * must be charged to the tenant separately.
     */
    function re_sd_approve_move_out_deductions(PDO $conn, int $companyId, int $moveOutId, ?int $userId, string $reason, bool $confirmNoDeposit = false): array
    {
        $moveOut = re_sd_load_move_out($conn, $companyId, $moveOutId);
        if (!$moveOut) {
            return ['success' => false, 'error' => 'Move-out record not found.'];
        }
        if (in_array((string)($moveOut['status'] ?? ''), ['completed', 'cancelled'], true)) {
            return ['success' => false, 'error' => 'This move-out is ' . $moveOut['status'] . '; the deposit settlement can no longer be changed.'];
        }
        if (trim($reason) === '') {
            return ['success' => false, 'error' => 'Approval reason is required.'];
        }
        // A renewal's deposit sits on the first lease of the tenancy
        $leaseId = re_sd_deposit_lease_id($conn, $companyId, (int)$moveOut['lease_id']);
        $tenantId = !empty($moveOut['tenant_id']) ? (int)$moveOut['tenant_id'] : null;
        $settlement = re_sd_settlement_for_move_out($conn, $companyId, $moveOutId);
        if (!$settlement) {
            $prepared = re_sd_prepare_move_out_settlement($conn, $companyId, $moveOutId, $userId);
            if (empty($prepared['success'])) {
                return $prepared;
            }
            $settlement = re_sd_settlement_for_move_out($conn, $companyId, $moveOutId);
        }
        if (!empty($settlement['journal_id']) || (string)($settlement['status'] ?? '') !== 'draft') {
            return ['success' => false, 'error' => 'Only a draft settlement can be approved. Click Recalculate first.'];
        }

        // Use the live received amount, not the figure stored when the preview was prepared
        $summary = re_sd_summary($conn, $companyId, $leaseId);
        $received = re_sd_money($summary['allocated'] ?? 0);
        $expected = re_sd_money($summary['expected'] ?? 0);
        if ($expected > 0 && $received <= 0 && !$confirmNoDeposit) {
            return [
                'success' => false,
                'error' => 'No security deposit has been received for this lease (expected AED ' . number_format($expected, 2) . '). '
                    . 'If the tenant paid it, allocate the receipt to the Security Deposit in Receipts & Allocations and click Recalculate. '
                    . 'If no deposit was ever collected, tick the confirmation box to close the settlement.',
                'code' => 'no_deposit',
            ];
        }

        $conn->beginTransaction();
        try {
            // Approve what the move-out shows now, not what it showed when prepared
            re_sd_sync_move_out_deductions($conn, $companyId, $moveOutId, $leaseId, $tenantId, $userId);
            // The approval note is kept in the audit log (deduction_approved), not
            // appended to each reason: that grew past the column size and failed
            $conn->prepare("
                UPDATE re_security_deposit_deductions
                SET status = 'approved', approved_by = ?, approved_at = NOW()
                WHERE company_id = ? AND move_out_id = ? AND status = 'draft'
            ")->execute([$userId, $companyId, $moveOutId]);

            $deductionStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM re_security_deposit_deductions WHERE company_id = ? AND move_out_id = ? AND status IN ('approved','applied')");
            $deductionStmt->execute([$companyId, $moveOutId]);
            $requestedDeductions = re_sd_money($deductionStmt->fetchColumn());
            $deductions = min($requestedDeductions, $received);
            $excess = re_sd_money(max(0, $requestedDeductions - $deductions));
            $refund = re_sd_money(max(0, $received - $deductions));

            if ($received <= 0) {
                // Nothing was collected, so nothing to refund or post: close the settlement
                $status = 'cancelled';
                $note = 'Closed without settlement: no security deposit received.'
                    . ($excess > 0 ? ' Damage of AED ' . number_format($excess, 2) . ' must be charged to the tenant separately.' : '');
            } else {
                $status = $refund > 0 ? 'approved' : 'deducted';
                $note = $excess > 0 ? 'Damage of AED ' . number_format($excess, 2) . ' exceeds the deposit and must be charged to the tenant separately.' : null;
            }

            $conn->prepare("
                UPDATE re_security_deposit_settlements
                SET received_amount = ?, expected_amount = ?, deduction_amount = ?, refund_amount = ?, retained_amount = ?, status = ?,
                    notes = COALESCE(?, notes), approved_by = ?, approved_at = NOW(), updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ")->execute([$received, $expected, $deductions, $refund, $deductions, $status, $note, $userId, (int)$settlement['id'], $companyId]);

            $depositStatus = $refund > 0 ? ($deductions > 0 ? 'partial' : 'processing') : 'forfeited';
            $conn->prepare("
                UPDATE re_move_outs
                SET deposit_deduction_amount = ?, deposit_refund_amount = ?, deposit_status = ?, status = 'deposit_processing'
                WHERE id = ? AND company_id = ?
            ")->execute([$deductions, $refund, $depositStatus, $moveOutId, $companyId]);
            re_sd_log($conn, $companyId, $leaseId, $tenantId, 'deduction_approved', null, null, $deductions, $reason . ($note ? ' | ' . $note : ''), $userId, 'move_out_view', null, null, $moveOutId, null, null);
            $conn->commit();
            return ['success' => true, 'deductions' => $deductions, 'refund' => $refund, 'excess' => $excess, 'closed' => $status === 'cancelled'];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('re_sd_notify_tenant')) {
    function re_sd_notify_tenant(PDO $conn, int $companyId, int $leaseId, string $type, string $title, string $body, string $entityType = 'security_deposit', ?int $entityId = null): void
    {
        try {
            require_once dirname(__DIR__, 3) . '/includes/tenant_notifications.php';
            tenant_notification_create($conn, [
                'company_id' => $companyId,
                'lease_id' => $leaseId,
                'type' => $type,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'title' => $title,
                'body' => $body,
                'dedup_window_minutes' => 10,
            ]);
        } catch (Throwable $e) {
            error_log('Security deposit tenant notification failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('re_sd_process_move_out_refund')) {
    function re_sd_process_move_out_refund(PDO $conn, int $companyId, int $moveOutId, string $refundMethod, string $refundReference, ?int $userId = null): array
    {
        $moveOut = re_sd_load_move_out($conn, $companyId, $moveOutId);
        if (!$moveOut) {
            return ['success' => false, 'error' => 'Move-out record not found.'];
        }
        if (in_array((string)($moveOut['status'] ?? ''), ['completed', 'cancelled'], true)) {
            return ['success' => false, 'error' => 'This move-out is ' . $moveOut['status'] . '; the deposit settlement can no longer be changed.'];
        }
        $settlement = re_sd_settlement_for_move_out($conn, $companyId, $moveOutId);
        if ($settlement && !empty($settlement['journal_id'])) {
            return ['success' => false, 'error' => 'This deposit settlement has already been processed.'];
        }
        if (!$settlement || !in_array((string)$settlement['status'], ['approved','deducted'], true)) {
            return ['success' => false, 'error' => 'Deposit settlement must be approved before refund/deduction posting.'];
        }
        // A renewal's deposit sits on the first lease of the tenancy
        $leaseId = re_sd_deposit_lease_id($conn, $companyId, (int)$moveOut['lease_id']);
        $tenantId = !empty($moveOut['tenant_id']) ? (int)$moveOut['tenant_id'] : null;
        $refund = re_sd_money($settlement['refund_amount'] ?? 0);
        $deduction = re_sd_money($settlement['deduction_amount'] ?? 0);
        if (($refund + $deduction) <= 0) {
            return ['success' => false, 'error' => 'Nothing to process: no security deposit was received for this lease, so there is no refund or deduction to post.'];
        }

        $conn->beginTransaction();
        try {
            $refundId = null;
            if ($refund > 0) {
                $stmt = $conn->prepare("
                    INSERT INTO re_tenant_refunds
                        (company_id, lease_id, tenant_id, refund_date, amount, payment_method, reference_number, receipt_number, refund_reason, notes, created_by)
                    VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, 'Security deposit refund', 'Approved move-out security deposit refund', ?)
                ");
                $receiptNo = 'SD-REF-' . $moveOutId . '-' . date('YmdHis');
                $stmt->execute([$companyId, $leaseId, $tenantId, $refund, $refundMethod ?: 'bank_transfer', $refundReference ?: null, $receiptNo, $userId]);
                $refundId = (int)$conn->lastInsertId();
            }

            $liability = find_account_by_code(re_sd_setting($conn, 're_security_deposit_liability_account_code', '2200'), $companyId);
            if (!$liability) {
                throw new RuntimeException('Security deposit liability account not found.');
            }
            $bankAccount = find_account_by_code(determine_bank_account($refundMethod ?: 'bank_transfer', $companyId), $companyId) ?: find_account_by_code('1110', $companyId);
            if ($refund > 0 && !$bankAccount) {
                throw new RuntimeException('Bank/Cash account not found for refund.');
            }
            $recoveryAccount = find_account_by_code(re_sd_setting($conn, 're_deposit_damage_recovery_account_code', '4300'), $companyId)
                ?: find_account_by_code(re_sd_setting($conn, 're_deposit_forfeiture_income_account_code', '4400'), $companyId);
            if ($deduction > 0 && !$recoveryAccount) {
                throw new RuntimeException('Deposit deduction recovery income account not found.');
            }

            $tenantName = ($moveOut['tenant_type'] ?? '') === 'company'
                ? (string)($moveOut['company_name'] ?? 'Tenant')
                : trim((string)($moveOut['first_name'] ?? '') . ' ' . (string)($moveOut['last_name'] ?? ''));
            $desc = 'Security deposit settlement - Lease ' . (string)$moveOut['lease_number'] . ' - ' . $tenantName;
            $lines = [[
                'account_id' => $liability['id'],
                'debit' => re_sd_money($refund + $deduction),
                'credit' => 0,
                'description' => $desc,
                'reference' => 'SD-SETTLE-' . $moveOutId,
            ]];
            if ($refund > 0) {
                $lines[] = [
                    'account_id' => $bankAccount['id'],
                    'debit' => 0,
                    'credit' => $refund,
                    'description' => 'Security deposit refund paid - ' . $tenantName,
                    'reference' => 'SD-SETTLE-' . $moveOutId,
                ];
            }
            if ($deduction > 0) {
                $lines[] = [
                    'account_id' => $recoveryAccount['id'],
                    'debit' => 0,
                    'credit' => $deduction,
                    'description' => 'Approved security deposit deduction - ' . $tenantName,
                    'reference' => 'SD-SETTLE-' . $moveOutId,
                ];
            }
            $journal = create_and_post_journal($companyId, 'refund', 'security_deposit_settlement', (int)$settlement['id'], $lines, $desc, date('Y-m-d'), $userId);
            if (empty($journal['success'])) {
                throw new RuntimeException((string)($journal['error'] ?? 'Could not post deposit settlement journal.'));
            }
            $status = $refund > 0 && $deduction > 0 ? 'partially_refunded' : ($refund > 0 ? 'refunded' : 'deducted');
            $conn->prepare("UPDATE re_security_deposit_settlements SET status = ?, refund_id = ?, journal_id = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
                ->execute([$status, $refundId, (int)$journal['journal_id'], (int)$settlement['id'], $companyId]);
            $conn->prepare("UPDATE re_security_deposit_deductions SET status = 'applied', journal_id = ? WHERE company_id = ? AND move_out_id = ? AND status = 'approved'")
                ->execute([(int)$journal['journal_id'], $companyId, $moveOutId]);
            $conn->prepare("
                UPDATE re_move_outs
                SET deposit_status = ?, deposit_refunded_at = CASE WHEN ? > 0 THEN NOW() ELSE deposit_refunded_at END,
                    deposit_refund_method = ?, deposit_refund_reference = ?
                WHERE id = ? AND company_id = ?
            ")->execute([$status === 'deducted' ? 'forfeited' : 'refunded', $refund, $refundMethod ?: null, $refundReference ?: null, $moveOutId, $companyId]);
            if ($refundId) {
                $conn->prepare("UPDATE re_tenant_refunds SET journal_id = ? WHERE id = ? AND company_id = ?")
                    ->execute([(int)$journal['journal_id'], $refundId, $companyId]);
            }
            re_sd_log($conn, $companyId, $leaseId, $tenantId, 'refund_processed', null, null, $refund, 'Approved security deposit refund processed', $userId, 'move_out_view', null, (int)$journal['journal_id'], $moveOutId, null, $refundId);
            if ($deduction > 0) {
                re_sd_log($conn, $companyId, $leaseId, $tenantId, 'deduction_applied', null, null, $deduction, 'Approved security deposit deduction applied', $userId, 'move_out_view', null, (int)$journal['journal_id'], $moveOutId, null, $refundId);
            }
            $conn->commit();
            // Notify after commit: the push path runs CREATE TABLE IF NOT EXISTS,
            // which implicitly commits an open MySQL transaction.
            re_sd_notify_tenant($conn, $companyId, $leaseId, 'security_deposit_settlement', 'Security deposit settlement processed', 'Your security deposit settlement has been processed. Refund: AED ' . number_format($refund, 2) . ', deductions: AED ' . number_format($deduction, 2) . '.', 'move_out', $moveOutId);
            return ['success' => true, 'journal_id' => (int)$journal['journal_id'], 'refund_id' => $refundId, 'refund' => $refund, 'deduction' => $deduction];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}


if (!function_exists('re_sd_settlement_journal_reversed')) {
    /**
     * The settlement journal was reversed (Journal Entry > Reverse): put the
     * deposit settlement back to draft, so it can be corrected, approved and
     * processed again. Called from reverse_journal() for journals with
     * reference_type 'security_deposit_settlement'.
     *  - settlement: status draft, no journal / refund, approval cleared, note added
     *  - deductions posted by it: back to draft
     *  - tenant refund it created: removed (its details stay in the notes and audit log)
     *  - move-out: deposit fields cleared; a completed move-out reopens at Deposit Settlement
     * Returns ['success' => bool, 'move_out_id' => int|null, 'error' => string].
     */
    function re_sd_settlement_journal_reversed(PDO $conn, int $settlementId, int $journalId, int $reversalJournalId, ?int $userId = null, string $reason = ''): array
    {
        $st = $conn->prepare("SELECT * FROM re_security_deposit_settlements WHERE id = ?");
        $st->execute([$settlementId]);
        $set = $st->fetch(PDO::FETCH_ASSOC);
        if (!$set || (int)$set['journal_id'] !== $journalId) {
            return ['success' => true, 'move_out_id' => null, 'error' => '']; // not (or no longer) this settlement's journal
        }
        $companyId = (int)$set['company_id'];
        $moveOutId = (int)($set['move_out_id'] ?? 0);
        $leaseId = (int)$set['lease_id'];
        $tenantId = !empty($set['tenant_id']) ? (int)$set['tenant_id'] : null;
        $own = !$conn->inTransaction();
        if ($own) {
            $conn->beginTransaction();
        }
        try {
            $refundNote = '';
            if (!empty($set['refund_id'])) {
                $rf = $conn->prepare("SELECT * FROM re_tenant_refunds WHERE id = ? AND company_id = ?");
                $rf->execute([(int)$set['refund_id'], $companyId]);
                if ($r = $rf->fetch(PDO::FETCH_ASSOC)) {
                    $refundNote = ' Refund ' . ($r['receipt_number'] ?: '#' . $r['id']) . ' of AED ' . number_format((float)$r['amount'], 2)
                        . ' (' . $r['payment_method'] . ($r['reference_number'] ? ', ref ' . $r['reference_number'] : '') . ', ' . $r['refund_date'] . ') removed.';
                    $conn->prepare("DELETE FROM re_tenant_refunds WHERE id = ? AND company_id = ?")->execute([(int)$r['id'], $companyId]);
                }
            }
            $note = '[' . date('Y-m-d H:i') . '] Settlement journal #' . $journalId . ' reversed (reversal journal #' . $reversalJournalId . ')'
                . ($reason !== '' ? ': ' . $reason : '') . '. Settlement set back to draft.' . $refundNote;
            $conn->prepare("
                UPDATE re_security_deposit_settlements
                SET status = 'draft', journal_id = NULL, refund_id = NULL, approved_by = NULL, approved_at = NULL,
                    notes = TRIM(CONCAT(COALESCE(notes, ''), '\n', ?)), updated_at = NOW()
                WHERE id = ?
            ")->execute([$note, $settlementId]);
            $conn->prepare("
                UPDATE re_security_deposit_deductions
                SET status = 'draft', journal_id = NULL, approved_by = NULL, approved_at = NULL
                WHERE company_id = ? AND journal_id = ?
            ")->execute([$companyId, $journalId]);
            if ($moveOutId) {
                $conn->prepare("
                    UPDATE re_move_outs
                    SET deposit_status = 'processing', deposit_refunded_at = NULL, deposit_refund_method = NULL, deposit_refund_reference = NULL,
                        status = IF(status IN ('completed', 'deposit_processing', 'inspection_completed'), 'deposit_processing', status)
                    WHERE id = ? AND company_id = ?
                ")->execute([$moveOutId, $companyId]);
            }
            re_sd_log($conn, $companyId, $leaseId, $tenantId, 'settlement_reversed', null, 'draft', (float)$set['refund_amount'] + (float)$set['deduction_amount'],
                $note, $userId, 'journal_reversal', null, $reversalJournalId, $moveOutId ?: null, null, !empty($set['refund_id']) ? (int)$set['refund_id'] : null);
            if ($own) {
                $conn->commit();
            }
            return ['success' => true, 'move_out_id' => $moveOutId ?: null, 'error' => ''];
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log('Deposit settlement reversal sync failed (settlement ' . $settlementId . '): ' . $e->getMessage());
            return ['success' => false, 'move_out_id' => $moveOutId ?: null, 'error' => $e->getMessage()];
        }
    }
}
