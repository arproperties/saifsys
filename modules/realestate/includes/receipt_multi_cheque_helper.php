<?php
/**
 * Invoice Mode: one receipt linked to multiple operational cheques.
 *
 * Isolated from single-cheque Allocate Payment (re_payments.cheque_id path).
 * Multi receipts leave cheque_id NULL and write re_receipt_cheque_links only.
 */
declare(strict_types=1);

require_once __DIR__ . '/cheque_lifecycle_helper.php';
require_once __DIR__ . '/cheque_financial_coverage_helper.php';
require_once __DIR__ . '/payment_allocation_helper.php';

// Callers must load receipt_allocation_engine.php before this file.

if (!function_exists('re_receipt_cheque_links_ensure_table')) {
    function re_receipt_cheque_links_ensure_table(PDO $conn): bool
    {
        static $ready = null;
        if ($ready === true) {
            return true;
        }
        if (function_exists('re_obligation_table_exists') && re_obligation_table_exists($conn, 're_receipt_cheque_links')) {
            $ready = true;
            return true;
        }
        try {
            $conn->exec("
                CREATE TABLE IF NOT EXISTS re_receipt_cheque_links (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    company_id INT(11) NOT NULL,
                    payment_id INT(11) NOT NULL,
                    cheque_id INT(11) NOT NULL,
                    amount_applied DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    created_by INT(11) DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_re_receipt_cheque_link (company_id, payment_id, cheque_id),
                    KEY idx_re_receipt_cheque_payment (company_id, payment_id),
                    KEY idx_re_receipt_cheque_cheque (company_id, cheque_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $ready = true;
            return true;
        } catch (Throwable $e) {
            error_log('re_receipt_cheque_links_ensure_table: ' . $e->getMessage());
            $ready = false;
            return false;
        }
    }
}

if (!function_exists('re_receipt_cheque_linked_cleared_total')) {
    /**
     * Total cleared receipt face already applied toward a cheque
     * (single cheque_id receipts + multi-cheque junction shares).
     */
    function re_receipt_cheque_linked_cleared_total(PDO $conn, int $companyId, int $leaseId, int $chequeId): float
    {
        if ($companyId <= 0 || $leaseId <= 0 || $chequeId <= 0) {
            return 0.0;
        }
        $total = 0.0;
        if (re_obligation_column_exists($conn, 're_payments', 'cheque_id')) {
            $st = $conn->prepare("
                SELECT COALESCE(SUM(amount), 0)
                FROM re_payments
                WHERE company_id = ?
                  AND lease_id = ?
                  AND cheque_id = ?
                  AND accounting_mode = 'invoice'
                  AND receipt_status = 'cleared'
            ");
            $st->execute([$companyId, $leaseId, $chequeId]);
            $total = re_receipt_money($st->fetchColumn() ?: 0);
        }
        if (re_receipt_cheque_links_ensure_table($conn)) {
            // Multi-cheque receipts leave re_payments.cheque_id NULL and store shares here.
            $st = $conn->prepare("
                SELECT COALESCE(SUM(l.amount_applied), 0)
                FROM re_receipt_cheque_links l
                JOIN re_payments p ON p.id = l.payment_id AND p.company_id = l.company_id
                WHERE l.company_id = ?
                  AND l.cheque_id = ?
                  AND p.lease_id = ?
                  AND p.accounting_mode = 'invoice'
                  AND p.receipt_status = 'cleared'
                  AND (p.cheque_id IS NULL OR p.cheque_id = 0)
            ");
            $st->execute([$companyId, $chequeId, $leaseId]);
            $total = re_receipt_money($total + re_receipt_money($st->fetchColumn() ?: 0));
        }
        return $total;
    }
}

if (!function_exists('re_receipt_load_cheque_links_for_payment')) {
    /**
     * @return list<array<string,mixed>>
     */
    function re_receipt_load_cheque_links_for_payment(PDO $conn, int $companyId, int $paymentId): array
    {
        if ($paymentId <= 0 || !re_receipt_cheque_links_ensure_table($conn)) {
            return [];
        }
        $st = $conn->prepare("
            SELECT l.cheque_id, l.amount_applied, c.cheque_number, c.cheque_amount, c.status
            FROM re_receipt_cheque_links l
            JOIN re_post_dated_cheques c ON c.id = l.cheque_id AND c.company_id = l.company_id
            WHERE l.company_id = ? AND l.payment_id = ?
            ORDER BY l.id ASC
        ");
        $st->execute([$companyId, $paymentId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('re_receipt_payments_linked_to_cheque')) {
    /**
     * Explicit receipt rows for a cheque (cheque_id column and/or junction).
     *
     * @return list<array<string,mixed>>
     */
    function re_receipt_payments_linked_to_cheque(PDO $conn, int $companyId, int $leaseId, int $chequeId): array
    {
        $byId = [];
        if (re_obligation_column_exists($conn, 're_payments', 'cheque_id')) {
            $st = $conn->prepare("
                SELECT id, receipt_number, amount, payment_date, cleared_date, allocation_status,
                       reference_number, notes, cheque_id, installment_id
                FROM re_payments
                WHERE company_id = ? AND lease_id = ? AND cheque_id = ?
                  AND accounting_mode = 'invoice' AND receipt_status = 'cleared'
            ");
            $st->execute([$companyId, $leaseId, $chequeId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $byId[(int)$row['id']] = $row;
            }
        }
        if (re_receipt_cheque_links_ensure_table($conn)) {
            $st = $conn->prepare("
                SELECT p.id, p.receipt_number, p.amount, p.payment_date, p.cleared_date, p.allocation_status,
                       p.reference_number, p.notes, p.cheque_id, p.installment_id, l.amount_applied
                FROM re_receipt_cheque_links l
                JOIN re_payments p ON p.id = l.payment_id AND p.company_id = l.company_id
                WHERE l.company_id = ? AND l.cheque_id = ? AND p.lease_id = ?
                  AND p.accounting_mode = 'invoice' AND p.receipt_status = 'cleared'
            ");
            $st->execute([$companyId, $chequeId, $leaseId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $pid = (int)$row['id'];
                if (!isset($byId[$pid])) {
                    // Display share for this cheque when multi-linked.
                    $row['display_amount'] = re_receipt_money($row['amount_applied'] ?? $row['amount'] ?? 0);
                    $byId[$pid] = $row;
                }
            }
        }
        return array_values($byId);
    }
}

if (!function_exists('re_receipt_preview_multi_cheque')) {
    /**
     * Prefill allocation for one receipt covering multiple cheques' coverage targets.
     *
     * @param list<int> $chequeIds
     * @return array<string,mixed>
     */
    function re_receipt_preview_multi_cheque(
        PDO $conn,
        int $companyId,
        int $leaseId,
        array $chequeIds,
        float $amount
    ): array {
        if (!re_receipt_schema_ready($conn) || !re_receipt_cheque_links_ensure_table($conn)) {
            return ['success' => false, 'error' => 'Multi-cheque receipt schema is not ready.'];
        }
        $ready = re_invoice_engine_load_invoice_mode_lease($conn, $companyId, $leaseId);
        if (empty($ready['success'])) {
            return $ready;
        }

        $ids = [];
        foreach ($chequeIds as $cid) {
            $cid = (int)$cid;
            if ($cid > 0) {
                $ids[$cid] = true;
            }
        }
        $ids = array_keys($ids);
        if (count($ids) < 2) {
            return ['success' => false, 'error' => 'Select at least two cheques for a combined receipt.'];
        }

        $amount = re_receipt_money($amount);
        $coverageMap = re_cheque_resolve_lease_coverage_map($conn, $companyId, $leaseId);
        $chequeRows = [];
        $sharePlan = [];
        $expectedFaceSum = 0.0;
        $preferredCaps = [];
        $invoiceIdSet = [];

        foreach ($ids as $chequeId) {
            $cheque = re_cheque_load($conn, $companyId, $chequeId);
            if (!$cheque || (int)($cheque['lease_id'] ?? 0) !== $leaseId) {
                return ['success' => false, 'error' => 'Cheque #' . $chequeId . ' was not found for this lease.'];
            }
            $status = re_cheque_normalize_status((string)($cheque['status'] ?? ''));
            if (in_array($status, ['replaced', 'cancelled', 'returned'], true)) {
                return ['success' => false, 'error' => 'Cheque ' . ($cheque['cheque_number'] ?? ('#' . $chequeId)) . ' cannot be included (' . $status . ').'];
            }
            $face = re_receipt_money($cheque['cheque_amount'] ?? 0);
            $already = re_receipt_cheque_linked_cleared_total($conn, $companyId, $leaseId, $chequeId);
            $remaining = re_receipt_money(max(0.0, $face - $already));
            if ($remaining <= 0.005) {
                return ['success' => false, 'error' => 'Cheque ' . ($cheque['cheque_number'] ?? ('#' . $chequeId)) . ' is already fully covered by linked receipts.'];
            }
            $chequeRows[] = [
                'id' => $chequeId,
                'cheque_number' => (string)($cheque['cheque_number'] ?? ''),
                'cheque_amount' => $face,
                'already_collected' => $already,
                'remaining' => $remaining,
                'status' => $status,
            ];
            $sharePlan[$chequeId] = $remaining;
            $expectedFaceSum = re_receipt_money($expectedFaceSum + $remaining);

            $coverage = $coverageMap[$chequeId] ?? null;
            if ($coverage) {
                foreach (($coverage['preferred_caps'] ?? []) as $key => $cap) {
                    $preferredCaps[$key] = re_receipt_money(($preferredCaps[$key] ?? 0) + re_receipt_money($cap));
                }
                foreach (($coverage['invoice_ids'] ?? []) as $invId) {
                    $invId = (int)$invId;
                    if ($invId > 0) {
                        $invoiceIdSet[$invId] = true;
                    }
                }
            }
        }

        if ($amount <= 0.005) {
            $amount = $expectedFaceSum;
        }

        // Open invoices for lease — suggest allocation preferring coverage targets.
        $invStmt = $conn->prepare("
            SELECT i.id, i.invoice_number, i.due_date, i.total_amount, i.outstanding_amount, i.status,
                   (
                       SELECT o.obligation_type
                       FROM re_invoice_items ii
                       JOIN re_obligations o ON o.id = ii.obligation_id AND o.company_id = ii.company_id
                       WHERE ii.invoice_id = i.id AND ii.company_id = i.company_id
                       ORDER BY ii.id ASC
                       LIMIT 1
                   ) AS obligation_type
            FROM re_invoices i
            WHERE i.company_id = ? AND i.lease_id = ?
              AND i.status NOT IN ('cancelled', 'void')
              AND i.outstanding_amount > 0.005
            ORDER BY i.due_date ASC, i.id ASC
        ");
        $invStmt->execute([$companyId, $leaseId]);
        $openInvoices = $invStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $suggestions = [];
        $remainingReceipt = $amount;
        // Pass 1: coverage preferred invoices
        foreach ($openInvoices as $inv) {
            if ($remainingReceipt <= 0.005) {
                break;
            }
            $invId = (int)$inv['id'];
            if (!isset($invoiceIdSet[$invId])) {
                continue;
            }
            $open = re_receipt_money($inv['outstanding_amount'] ?? 0);
            $capKey = 'invoice:' . $invId;
            if (isset($preferredCaps[$capKey])) {
                $open = min($open, re_receipt_money($preferredCaps[$capKey]));
            }
            $take = re_receipt_money(min($open, $remainingReceipt));
            if ($take <= 0.005) {
                continue;
            }
            $suggestions['invoice:' . $invId] = $take;
            $remainingReceipt = re_receipt_money($remainingReceipt - $take);
        }
        // Pass 2: do not FIFO-spill into unrelated months — leftover becomes tenant credit.
        $defaultCredit = $remainingReceipt;

        return [
            'success' => true,
            'amount' => $amount,
            'cheque_rows' => $chequeRows,
            'cheque_shares' => $sharePlan,
            'expected_cheques_total' => $expectedFaceSum,
            'suggestions' => $suggestions,
            'default_credit' => $defaultCredit,
            'open_invoices' => $openInvoices,
            'coverage_invoice_ids' => array_keys($invoiceIdSet),
        ];
    }
}

if (!function_exists('re_receipt_confirm_multi_cheque')) {
    /**
     * Confirm one cleared receipt linked to multiple cheques.
     *
     * @param list<int> $chequeIds
     * @param array<string,float|int|string> $allocations
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    function re_receipt_confirm_multi_cheque(
        PDO $conn,
        int $companyId,
        int $leaseId,
        array $chequeIds,
        array $data,
        array $allocations,
        ?int $userId = null
    ): array {
        if (!re_receipt_schema_ready($conn) || !re_receipt_cheque_links_ensure_table($conn)) {
            return ['success' => false, 'error' => 'Multi-cheque receipt schema is not ready.'];
        }
        if (!function_exists('post_invoice_mode_receipt_to_accounting')) {
            require_once __DIR__ . '/../accounting/accounting_integration.php';
        }

        $preview = re_receipt_preview_multi_cheque(
            $conn,
            $companyId,
            $leaseId,
            $chequeIds,
            (float)($data['amount'] ?? 0)
        );
        if (empty($preview['success'])) {
            return $preview;
        }

        $amount = re_receipt_money($data['amount'] ?? $preview['amount'] ?? 0);
        if ($amount <= 0.005) {
            return ['success' => false, 'error' => 'Cleared amount must be greater than zero.'];
        }
        $expected = re_receipt_money($preview['expected_cheques_total'] ?? 0);
        if ($expected > 0.005 && $amount > $expected + 0.02) {
            return [
                'success' => false,
                'error' => 'Receipt amount exceeds the remaining balance of the selected cheques (AED ' . number_format($expected, 2) . ').',
            ];
        }

        $cleanAllocations = [];
        $allocatedTotal = 0.0;
        foreach ($allocations as $key => $value) {
            $alloc = re_receipt_money($value);
            if ($alloc <= 0) {
                continue;
            }
            if (!preg_match('/^(invoice|obligation):(\d+)$/', (string)$key, $m)) {
                return ['success' => false, 'error' => 'Invalid allocation target.'];
            }
            $cleanAllocations[] = ['type' => $m[1], 'id' => (int)$m[2], 'amount' => $alloc];
            $allocatedTotal += $alloc;
        }
        $allocatedTotal = re_receipt_money($allocatedTotal);
        if ($allocatedTotal > $amount + 0.005) {
            return ['success' => false, 'error' => 'Allocated amount cannot exceed receipt amount.'];
        }

        $ready = re_invoice_engine_load_invoice_mode_lease($conn, $companyId, $leaseId);
        if (empty($ready['success'])) {
            return $ready;
        }
        $lease = $ready['lease'] ?? [];

        $startedHere = !$conn->inTransaction();
        if ($startedHere) {
            $conn->beginTransaction();
        }

        try {
            $receiptNumber = re_receipt_generate_number($conn, $companyId);
            $source = (string)($data['receipt_source'] ?? 'bank_transfer');
            $paymentMethod = re_receipt_payment_method_for_source($source);
            $clearedDate = (string)($data['cleared_date'] ?? date('Y-m-d'));
            $tenantId = (int)($lease['tenant_id'] ?? 0);
            $unitId = (int)($lease['unit_id'] ?? 0);
            $receiptAccountType = in_array((string)($data['receipt_account_type'] ?? ''), ['bank', 'cash', 'card_clearing'], true)
                ? (string)$data['receipt_account_type']
                : null;
            $receiptAccountId = !empty($data['receipt_account_id']) ? (int)$data['receipt_account_id'] : null;
            if (!$receiptAccountType || !$receiptAccountId) {
                throw new RuntimeException('Receipt Account is required for bank/GL posting.');
            }
            $bankAccountId = $receiptAccountType === 'bank' ? $receiptAccountId : null;
            $reference = trim((string)($data['reference_number'] ?? ''));
            $notes = trim((string)($data['notes'] ?? ''));

            // Intentionally leave cheque_id NULL — multi links are only in junction table.
            $stmt = $conn->prepare("
                INSERT INTO re_payments (
                    company_id, accounting_mode, lease_id, tenant_id, unit_id,
                    installment_id, invoice_id, payment_date, amount, payment_method,
                    receipt_source, receipt_status, allocation_status, cleared_date,
                    bank_account_id, receipt_account_type, receipt_account_id,
                    reference_number, receipt_number, notes, created_by
                ) VALUES (
                    ?, 'invoice', ?, ?, ?,
                    NULL, NULL, ?, ?, ?,
                    ?, 'cleared', 'unallocated', ?,
                    ?, ?, ?,
                    ?, ?, ?, ?
                )
            ");
            $stmt->execute([
                $companyId,
                $leaseId,
                $tenantId ?: null,
                $unitId ?: null,
                $clearedDate,
                $amount,
                $paymentMethod,
                $source,
                $clearedDate,
                $bankAccountId,
                $receiptAccountType,
                $receiptAccountId,
                $reference !== '' ? $reference : null,
                $receiptNumber,
                $notes !== '' ? $notes : null,
                $userId,
            ]);
            $paymentId = (int)$conn->lastInsertId();

            // Scale share plan if accountant received less than full remaining faces.
            $shares = $preview['cheque_shares'] ?? [];
            $shareSum = 0.0;
            foreach ($shares as $shareAmt) {
                $shareSum = re_receipt_money($shareSum + re_receipt_money($shareAmt));
            }
            $linkStmt = $conn->prepare("
                INSERT INTO re_receipt_cheque_links
                    (company_id, payment_id, cheque_id, amount_applied, created_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $appliedShares = [];
            $assigned = 0.0;
            $chequeList = array_keys($shares);
            $lastIdx = count($chequeList) - 1;
            foreach ($chequeList as $idx => $chequeId) {
                $chequeId = (int)$chequeId;
                $raw = re_receipt_money($shares[$chequeId] ?? 0);
                if ($shareSum > 0.005 && abs($amount - $shareSum) > 0.02) {
                    if ($idx === $lastIdx) {
                        $applied = re_receipt_money(max(0.0, $amount - $assigned));
                    } else {
                        $applied = re_receipt_money(($raw / $shareSum) * $amount);
                        $assigned = re_receipt_money($assigned + $applied);
                    }
                } else {
                    $applied = $raw;
                }
                if ($applied <= 0.005) {
                    continue;
                }
                $linkStmt->execute([$companyId, $paymentId, $chequeId, $applied, $userId]);
                $appliedShares[$chequeId] = $applied;
            }

            foreach ($cleanAllocations as $allocation) {
                if ($allocation['type'] === 'invoice') {
                    re_receipt_apply_invoice_allocation($conn, $companyId, $allocation['id'], $allocation['amount'], $paymentId, $leaseId, $tenantId, $userId);
                } else {
                    re_receipt_apply_obligation_allocation($conn, $companyId, $allocation['id'], $allocation['amount'], $paymentId, $leaseId, $tenantId, $userId);
                }
            }

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
                    'Invoice Mode overpayment from multi-cheque receipt ' . $receiptNumber,
                    'overpayment'
                );
                $conn->prepare("
                    INSERT INTO re_receipt_allocations
                        (company_id, payment_id, lease_id, tenant_id, target_type, invoice_id, obligation_id, amount_allocated, notes, created_by)
                    VALUES (?, ?, ?, ?, 'tenant_credit', NULL, NULL, ?, 'Unallocated balance credited to tenant', ?)
                ")->execute([$companyId, $paymentId, $leaseId, $tenantId, $creditAmount, $userId]);
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

            // Clear each selected cheque when linked receipts reach its face.
            foreach ($preview['cheque_rows'] as $row) {
                $chequeId = (int)$row['id'];
                $face = re_receipt_money($row['cheque_amount'] ?? 0);
                $linkedTotal = re_receipt_cheque_linked_cleared_total($conn, $companyId, $leaseId, $chequeId);
                if ($face > 0 && $linkedTotal >= $face - 0.02) {
                    $clearResult = re_cheque_update_status(
                        $conn,
                        $companyId,
                        $chequeId,
                        'cleared',
                        $userId,
                        'Cleared through multi-cheque receipt ' . $receiptNumber,
                        'receipt_multi_cheque',
                        $paymentId,
                        null,
                        true
                    );
                    if (empty($clearResult['success'])) {
                        throw new RuntimeException(
                            'Receipt saved but could not clear cheque '
                            . ($row['cheque_number'] ?? ('#' . $chequeId)) . ': '
                            . (string)($clearResult['error'] ?? 'unknown')
                        );
                    }
                }
            }

            // Coverage sync for any edge remainders (Option C) — safe no-op for uncleared.
            if (function_exists('re_cheque_sync_cleared_from_financial_coverage')) {
                re_cheque_sync_cleared_from_financial_coverage($conn, $companyId, $leaseId, $userId, $paymentId);
            }

            if (function_exists('post_invoice_mode_receipt_to_accounting')) {
                $post = post_invoice_mode_receipt_to_accounting($paymentId, $companyId, $userId);
                if (empty($post['success'])) {
                    throw new RuntimeException((string)($post['error'] ?? 'Could not post receipt to accounting.'));
                }
            }

            if ($startedHere) {
                $conn->commit();
            }

            return [
                'success' => true,
                'payment_id' => $paymentId,
                'receipt_number' => $receiptNumber,
                'allocated' => $allocatedTotal,
                'tenant_credit' => $creditAmount,
                'cheque_shares' => $appliedShares,
                'error' => null,
            ];
        } catch (Throwable $e) {
            if ($startedHere && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
