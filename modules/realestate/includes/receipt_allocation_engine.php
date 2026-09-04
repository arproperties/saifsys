<?php
/**
 * Phase 4 Receipt & Allocation Engine for Invoice Mode leases.
 *
 * Write boundary:
 * - re_payments for cleared Invoice Mode receipt headers
 * - re_receipt_sequences for receipt numbers
 * - re_receipt_allocations
 * - re_invoices paid/outstanding/status for issued invoice allocations
 * - re_obligations allocated_amount/status for obligation allocations
 * - tenant credit tables via the existing helper for overpayments
 *
 * No cheque status changes, no installment updates, no reports, and no GL posting.
 */
declare(strict_types=1);

require_once __DIR__ . '/invoice_engine.php';
require_once __DIR__ . '/payment_allocation_helper.php';
require_once __DIR__ . '/receipt_allocation_read_helper.php';
require_once __DIR__ . '/cheque_lifecycle_helper.php';
require_once __DIR__ . '/cheque_financial_coverage_helper.php';
require_once __DIR__ . '/security_deposit_helper.php';

if (!function_exists('re_receipt_schema_ready')) {
    function re_receipt_schema_ready(PDO $conn): bool
    {
        return re_obligation_table_exists($conn, 're_receipt_allocations')
            && re_obligation_table_exists($conn, 're_receipt_sequences')
            && re_obligation_column_exists($conn, 're_payments', 'accounting_mode')
            && re_obligation_column_exists($conn, 're_payments', 'receipt_source')
            && re_obligation_column_exists($conn, 're_payments', 'receipt_status')
            && re_obligation_column_exists($conn, 're_payments', 'cleared_date')
            && re_obligation_column_exists($conn, 're_payments', 'allocation_status')
            && re_obligation_column_exists($conn, 're_payments', 'bank_account_id')
            && re_obligation_column_exists($conn, 're_payments', 'tenant_id')
            && re_obligation_column_exists($conn, 're_payments', 'unit_id')
            && re_obligation_column_exists($conn, 're_payments', 'receipt_account_type')
            && re_obligation_column_exists($conn, 're_payments', 'receipt_account_id');
    }
}

if (!function_exists('re_receipt_load_invoice_mode_lease')) {
    /**
     * @return array<string,mixed>
     */
    function re_receipt_load_invoice_mode_lease(PDO $conn, int $companyId, int $leaseId): array
    {
        $ready = re_invoice_engine_load_invoice_mode_lease($conn, $companyId, $leaseId);
        if (empty($ready['success'])) {
            return $ready;
        }
        if (!re_receipt_schema_ready($conn)) {
            return [
                'success' => false,
                'error' => 'Phase 4 receipt allocation migration has not been applied.',
                'lease' => $ready['lease'] ?? null,
            ];
        }
        return $ready;
    }
}

if (!function_exists('re_receipt_generate_number')) {
    function re_receipt_generate_number(PDO $conn, int $companyId, string $prefix = 'REC'): string
    {
        $year = (int)date('Y');
        $stmt = $conn->prepare("
            INSERT INTO re_receipt_sequences (company_id, year, prefix, sequence_number)
            VALUES (?, ?, ?, 0)
            ON DUPLICATE KEY UPDATE prefix = VALUES(prefix)
        ");
        $stmt->execute([$companyId, $year, $prefix]);

        $stmt = $conn->prepare("
            UPDATE re_receipt_sequences
            SET sequence_number = sequence_number + 1
            WHERE company_id = ? AND year = ?
        ");
        $stmt->execute([$companyId, $year]);

        $stmt = $conn->prepare("SELECT sequence_number FROM re_receipt_sequences WHERE company_id = ? AND year = ?");
        $stmt->execute([$companyId, $year]);
        return sprintf('%s-%d-%05d', $prefix, $year, (int)$stmt->fetchColumn());
    }
}

if (!function_exists('re_receipt_invoice_targets')) {
    /**
     * @return list<array<string,mixed>>
     */
    function re_receipt_invoice_targets(PDO $conn, int $companyId, int $leaseId): array
    {
        $stmt = $conn->prepare("
            SELECT i.id AS target_id,
                   'invoice' AS target_type,
                   i.id AS invoice_id,
                   NULL AS obligation_id,
                   CONCAT('Invoice ', i.invoice_number) AS label,
                   MIN(o.due_date) AS due_date,
                   i.total_amount,
                   i.paid_amount,
                   i.outstanding_amount AS open_amount,
                   GROUP_CONCAT(DISTINCT
                       CASE
                           WHEN o.source_type = 'billing_item' AND o.obligation_type = 'service'
                               THEN 'extra_service'
                           ELSE o.obligation_type
                       END
                       ORDER BY
                       CASE
                           WHEN o.source_type = 'billing_item' AND o.obligation_type = 'service'
                               THEN 'extra_service'
                           ELSE o.obligation_type
                       END
                       SEPARATOR ', '
                   ) AS source_types
            FROM re_invoices i
            JOIN re_invoice_items ii ON ii.invoice_id = i.id AND ii.company_id = i.company_id
            JOIN re_obligations o ON o.id = ii.obligation_id AND o.company_id = i.company_id
            WHERE i.company_id = ?
              AND i.lease_id = ?
              AND i.status IN ('sent', 'partial', 'overdue')
              AND i.outstanding_amount > 0.005
            GROUP BY i.id
            ORDER BY i.due_date ASC, i.id ASC
        ");
        $stmt->execute([$companyId, $leaseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('re_receipt_obligation_targets')) {
    /**
     * @return list<array<string,mixed>>
     */
    function re_receipt_obligation_targets(PDO $conn, int $companyId, int $leaseId): array
    {
        $stmt = $conn->prepare("
            SELECT o.id AS target_id,
                   'obligation' AS target_type,
                   NULL AS invoice_id,
                   o.id AS obligation_id,
                   COALESCE(o.description, CONCAT('Obligation #', o.id)) AS label,
                   o.due_date,
                   o.total_amount,
                   o.allocated_amount AS paid_amount,
                   GREATEST(0, o.total_amount - o.allocated_amount) AS open_amount,
                   o.obligation_type AS source_types
            FROM re_obligations o
            WHERE o.company_id = ?
              AND o.lease_id = ?
              AND o.status IN ('open', 'partially_allocated')
              AND o.total_amount > o.allocated_amount + 0.005
              AND o.obligation_type IN ('security_deposit', 'penalty')
              AND NOT EXISTS (
                    SELECT 1
                    FROM re_invoice_items ii
                    WHERE ii.company_id = o.company_id
                      AND ii.obligation_id = o.id
              )
            ORDER BY o.due_date ASC, o.id ASC
        ");
        $stmt->execute([$companyId, $leaseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('re_receipt_allocation_targets')) {
    /**
     * @return list<array<string,mixed>>
     */
    function re_receipt_allocation_targets(PDO $conn, int $companyId, int $leaseId): array
    {
        $targets = array_merge(
            re_receipt_invoice_targets($conn, $companyId, $leaseId),
            re_receipt_obligation_targets($conn, $companyId, $leaseId)
        );

        $priority = 'oldest_due';
        try {
            $st = $conn->prepare("SELECT `value` FROM settings WHERE `key` = 're_receipt_allocation_priority' LIMIT 1");
            $st->execute();
            $v = (string)($st->fetchColumn() ?: '');
            if (in_array($v, ['oldest_due', 'rent_first', 'service_first'], true)) {
                $priority = $v;
            }
        } catch (Throwable $e) {
            $priority = 'oldest_due';
        }

        $typeRank = static function (array $row) use ($priority): int {
            if ($priority === 'oldest_due') {
                return 0;
            }
            $types = strtolower((string)($row['source_types'] ?? ''));
            $isRent = str_contains($types, 'rent');
            $isService = str_contains($types, 'service');
            if ($priority === 'rent_first') {
                if ($isRent) {
                    return 0;
                }
                if ($isService) {
                    return 2;
                }
                return 1;
            }
            // service_first
            if ($isService) {
                return 0;
            }
            if ($isRent) {
                return 2;
            }
            return 1;
        };

        usort($targets, static function (array $a, array $b) use ($typeRank): int {
            $ra = $typeRank($a);
            $rb = $typeRank($b);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return strcmp((string)($a['due_date'] ?? ''), (string)($b['due_date'] ?? ''))
                ?: ((int)$a['target_id'] <=> (int)$b['target_id']);
        });
        return $targets;
    }
}

if (!function_exists('re_receipt_default_allocations')) {
    /**
     * @return array<string,float>
     */
    function re_receipt_default_allocations(array $targets, float $amount): array
    {
        return re_receipt_default_allocations_preferring($targets, $amount, [], []);
    }
}

if (!function_exists('re_receipt_default_allocations_preferring')) {
    /**
     * FIFO defaults, optionally preferring cheque-coverage targets first.
     * $preferredCaps limits how much to suggest on a preferred key (e.g. embedded VAT share).
     *
     * @param list<array<string,mixed>> $targets
     * @param list<string> $preferredKeys e.g. ['invoice:12','obligation:3']
     * @param array<string,float> $preferredCaps
     * @return array<string,float>
     */
    function re_receipt_default_allocations_preferring(
        array $targets,
        float $amount,
        array $preferredKeys,
        array $preferredCaps = []
    ): array {
        $remaining = re_receipt_money($amount);
        $allocations = [];
        $preferredSet = [];
        foreach ($preferredKeys as $key) {
            $key = (string)$key;
            if ($key !== '') {
                $preferredSet[$key] = true;
            }
        }

        $preferredTargets = [];
        $otherTargets = [];
        foreach ($targets as $target) {
            $key = $target['target_type'] . ':' . (int)$target['target_id'];
            if (isset($preferredSet[$key])) {
                $preferredTargets[] = $target;
            } else {
                $otherTargets[] = $target;
            }
        }

        // When preferred_caps are present (e.g. separate-VAT → embedded fee VAT shares),
        // do not spill remainder onto unrelated open invoices such as rent.
        $ordered = $preferredCaps !== []
            ? $preferredTargets
            : array_merge($preferredTargets, $otherTargets);

        foreach ($ordered as $target) {
            if ($remaining <= 0.005) {
                break;
            }
            $key = $target['target_type'] . ':' . (int)$target['target_id'];
            $open = (float)$target['open_amount'];
            $cap = $open;
            if (isset($preferredSet[$key]) && isset($preferredCaps[$key])) {
                $cap = min($open, re_receipt_money($preferredCaps[$key]));
            }
            $alloc = min($remaining, $cap);
            if ($alloc > 0.005) {
                $allocations[$key] = re_receipt_money($alloc);
                $remaining = re_receipt_money($remaining - $alloc);
            }
        }
        return $allocations;
    }
}

if (!function_exists('re_receipt_preview')) {
    /**
     * @param array<string,mixed> $options Optional: prefer_extra_service, service_charge_id, billing_item_id
     * @return array<string,mixed>
     */
    function re_receipt_preview(PDO $conn, int $companyId, int $leaseId, float $amount, int $chequeId = 0, array $options = []): array
    {
        $ready = re_receipt_load_invoice_mode_lease($conn, $companyId, $leaseId);
        if (empty($ready['success'])) {
            return $ready;
        }
        $targets = re_receipt_allocation_targets($conn, $companyId, $leaseId);

        $preferredKeys = [];
        $preferredCaps = [];
        $coveragePreferred = false;
        $coverageEmpty = false;
        if ($chequeId > 0) {
            $coverageMap = re_cheque_resolve_lease_coverage_map($conn, $companyId, $leaseId);
            $coverage = $coverageMap[$chequeId] ?? null;
            if (!$coverage) {
                $coverage = re_cheque_resolve_service_plan_coverage($conn, $companyId, $leaseId, $chequeId);
            }
            if ($coverage) {
                foreach ($coverage['invoice_ids'] ?? [] as $invoiceId) {
                    $invoiceId = (int)$invoiceId;
                    if ($invoiceId > 0) {
                        $preferredKeys[] = 'invoice:' . $invoiceId;
                    }
                }
                foreach ($coverage['obligation_ids'] ?? [] as $obligationId) {
                    $obligationId = (int)$obligationId;
                    if ($obligationId > 0) {
                        $preferredKeys[] = 'obligation:' . $obligationId;
                    }
                }
                $preferredCaps = $coverage['preferred_caps'] ?? [];
                $coveragePreferred = $preferredKeys !== [];
                $coverageEmpty = !$coveragePreferred;
            } else {
                $coverageEmpty = true;
            }
        } elseif (!empty($options['prefer_extra_service'])
            || (int)($options['service_charge_id'] ?? 0) > 0
            || (int)($options['billing_item_id'] ?? 0) > 0
        ) {
            $picked = re_cheque_pick_extra_service_targets(
                $conn,
                $companyId,
                $leaseId,
                $amount,
                (int)($options['service_charge_id'] ?? 0),
                (int)($options['billing_item_id'] ?? 0)
            );
            $preferredKeys = $picked['preferred_keys'] ?? [];
            $preferredCaps = $picked['preferred_caps'] ?? [];
            $coveragePreferred = $preferredKeys !== [];
            $coverageEmpty = !$coveragePreferred;
        }

        $defaults = re_receipt_default_allocations_preferring($targets, $amount, $preferredKeys, $preferredCaps);
        $allocated = re_receipt_money(array_sum($defaults));

        // One-fils / two-fils alignment: schedule cheque face can be slightly below the
        // sum of matched open invoices (monthly rounding). Bump suggested receipt amount
        // so prefer-caps settle invoices fully without leaving tiny residuals.
        if ($preferredCaps !== []) {
            $capsTotal = re_receipt_money(array_sum($preferredCaps));
            if ($capsTotal > $amount + 0.005 && ($capsTotal - $amount) <= 0.025) {
                $amount = $capsTotal;
                $defaults = re_receipt_default_allocations_preferring($targets, $amount, $preferredKeys, $preferredCaps);
                $allocated = re_receipt_money(array_sum($defaults));
            }
        }

        return [
            'success' => true,
            'lease' => $ready['lease'],
            'targets' => $targets,
            'default_allocations' => $defaults,
            'amount' => re_receipt_money($amount),
            'default_allocated' => re_receipt_money($allocated),
            'default_credit' => re_receipt_money(max(0, $amount - $allocated)),
            'cheque_id' => $chequeId,
            'coverage_preferred' => $coveragePreferred,
            'coverage_empty' => $coverageEmpty,
            'preferred_target_keys' => $preferredKeys,
            'diagnostics' => re_receipt_diagnostics($conn, $companyId, $leaseId),
        ];
    }
}

if (!function_exists('re_receipt_payment_method_for_source')) {
    function re_receipt_payment_method_for_source(string $source): string
    {
        return match ($source) {
            'cleared_cheque' => 'cheque',
            'cash' => 'cash',
            'cash_deposit' => 'cash_deposit',
            'card' => 'card',
            default => 'bank_transfer',
        };
    }
}

if (!function_exists('re_receipt_source_options')) {
    /**
     * @return array<string,string>
     */
    function re_receipt_source_options(): array
    {
        return [
            'bank_transfer' => 'Bank Transfer',
            'cleared_cheque' => 'Cleared Cheque',
            'cash_deposit' => 'Cash Deposit',
            'cash' => 'Cash',
            'card' => 'Card',
        ];
    }
}

if (!function_exists('re_receipt_apply_invoice_allocation')) {
    function re_receipt_apply_invoice_allocation(PDO $conn, int $companyId, int $invoiceId, float $amount, int $paymentId, int $leaseId, int $tenantId, ?int $userId): void
    {
        $stmt = $conn->prepare("
            SELECT id, total_amount, paid_amount, outstanding_amount
            FROM re_invoices
            WHERE id = ? AND company_id = ? AND lease_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$invoiceId, $companyId, $leaseId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new RuntimeException('Invoice target not found.');
        }
        $open = (float)$invoice['outstanding_amount'];
        if ($amount > $open + 0.005) {
            throw new RuntimeException('Invoice allocation exceeds outstanding amount.');
        }

        $conn->prepare("
            INSERT INTO re_receipt_allocations
                (company_id, payment_id, lease_id, tenant_id, target_type, invoice_id, obligation_id, amount_allocated, notes, created_by)
            VALUES (?, ?, ?, ?, 'invoice', ?, NULL, ?, 'Allocated to issued invoice', ?)
        ")->execute([$companyId, $paymentId, $leaseId, $tenantId, $invoiceId, $amount, $userId]);

        $newPaid = re_receipt_money((float)$invoice['paid_amount'] + $amount);
        $newOutstanding = re_receipt_money(max(0, (float)$invoice['total_amount'] - $newPaid));
        $status = $newOutstanding <= 0.005 ? 'paid' : 'partial';
        $conn->prepare("
            UPDATE re_invoices
            SET paid_amount = ?, outstanding_amount = ?, status = ?
            WHERE id = ? AND company_id = ?
        ")->execute([$newPaid, $newOutstanding, $status, $invoiceId, $companyId]);

        // Keep obligation allocation visible for diagnostics without changing cheque or legacy installment behavior.
        $lines = $conn->prepare("
            SELECT obligation_id, line_total
            FROM re_invoice_items
            WHERE invoice_id = ? AND company_id = ? AND obligation_id IS NOT NULL
        ");
        $lines->execute([$invoiceId, $companyId]);
        $invoiceLines = $lines->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $lineTotal = array_sum(array_map(static fn($r) => (float)$r['line_total'], $invoiceLines));
        foreach ($invoiceLines as $line) {
            $share = $lineTotal > 0 ? re_receipt_money($amount * ((float)$line['line_total'] / $lineTotal)) : $amount;
            re_receipt_apply_obligation_amount($conn, $companyId, (int)$line['obligation_id'], $share);
        }
    }
}

if (!function_exists('re_receipt_apply_obligation_amount')) {
    function re_receipt_apply_obligation_amount(PDO $conn, int $companyId, int $obligationId, float $amount): void
    {
        $stmt = $conn->prepare("
            SELECT id, total_amount, allocated_amount
            FROM re_obligations
            WHERE id = ? AND company_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$obligationId, $companyId]);
        $obligation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$obligation) {
            throw new RuntimeException('Obligation target not found.');
        }

        $newAllocated = re_receipt_money((float)$obligation['allocated_amount'] + $amount);
        $total = (float)$obligation['total_amount'];
        $status = $newAllocated >= $total - 0.005 ? 'settled' : ($newAllocated > 0 ? 'partially_allocated' : 'open');
        $conn->prepare("
            UPDATE re_obligations
            SET allocated_amount = ?, status = ?
            WHERE id = ? AND company_id = ?
        ")->execute([$newAllocated, $status, $obligationId, $companyId]);
    }
}

if (!function_exists('re_receipt_apply_obligation_allocation')) {
    function re_receipt_apply_obligation_allocation(PDO $conn, int $companyId, int $obligationId, float $amount, int $paymentId, int $leaseId, int $tenantId, ?int $userId): void
    {
        $stmt = $conn->prepare("
            SELECT id, total_amount, allocated_amount, obligation_type
            FROM re_obligations
            WHERE id = ? AND company_id = ? AND lease_id = ?
              AND status IN ('open', 'partially_allocated')
              AND NOT EXISTS (
                    SELECT 1 FROM re_invoice_items ii
                    WHERE ii.company_id = re_obligations.company_id
                      AND ii.obligation_id = re_obligations.id
              )
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$obligationId, $companyId, $leaseId]);
        $obligation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$obligation) {
            throw new RuntimeException('Obligation target not found or already invoiced.');
        }
        $open = re_receipt_money((float)$obligation['total_amount'] - (float)$obligation['allocated_amount']);
        if ($amount > $open + 0.005) {
            throw new RuntimeException('Obligation allocation exceeds outstanding amount.');
        }

        $conn->prepare("
            INSERT INTO re_receipt_allocations
                (company_id, payment_id, lease_id, tenant_id, target_type, invoice_id, obligation_id, amount_allocated, notes, created_by)
            VALUES (?, ?, ?, ?, 'obligation', NULL, ?, ?, 'Allocated directly to obligation', ?)
        ")->execute([$companyId, $paymentId, $leaseId, $tenantId, $obligationId, $amount, $userId]);
        re_receipt_apply_obligation_amount($conn, $companyId, $obligationId, $amount);
        if ((string)($obligation['obligation_type'] ?? '') === 'security_deposit') {
            $posting = re_sd_post_liability_for_receipt_allocation($conn, $companyId, $paymentId, $leaseId, $obligationId, $amount, $userId);
            if (empty($posting['success'])) {
                throw new RuntimeException('Security deposit liability posting failed: ' . ($posting['error'] ?? 'Unknown error'));
            }
        }
    }
}

if (!function_exists('re_receipt_confirm')) {
    /**
     * @param array<string,float|numeric-string> $allocations keys invoice:ID / obligation:ID
     * @return array<string,mixed>
     */
    function re_receipt_confirm(PDO $conn, int $companyId, int $leaseId, array $data, array $allocations, ?int $userId = null): array
    {
        $ready = re_receipt_load_invoice_mode_lease($conn, $companyId, $leaseId);
        if (empty($ready['success'])) {
            return $ready;
        }
        $lease = $ready['lease'];
        $amount = re_receipt_money($data['amount'] ?? 0);
        $source = (string)($data['receipt_source'] ?? '');
        $clearedDate = (string)($data['cleared_date'] ?? date('Y-m-d'));
        $allowedSources = ['cleared_cheque', 'bank_transfer', 'cash_deposit', 'cash', 'card'];
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'Receipt amount must be greater than zero.'];
        }
        if (!in_array($source, $allowedSources, true)) {
            return ['success' => false, 'error' => 'Invalid receipt source.'];
        }
        if (empty($data['receipt_account_type']) || empty($data['receipt_account_id'])) {
            return ['success' => false, 'error' => 'Receipt Account is required for bank/GL posting.'];
        }
        if ($clearedDate === '') {
            return ['success' => false, 'error' => 'Cleared date is required.'];
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

        $startedHere = !$conn->inTransaction();
        if ($startedHere) {
            $conn->beginTransaction();
        }

        try {
            $receiptNumber = re_receipt_generate_number($conn, $companyId);
            $paymentMethod = re_receipt_payment_method_for_source($source);
            $tenantId = (int)($lease['tenant_id'] ?? 0);
            $unitId = (int)($lease['unit_id'] ?? 0);
            $receiptAccountType = in_array((string)($data['receipt_account_type'] ?? ''), ['bank', 'cash', 'card_clearing'], true)
                ? (string)$data['receipt_account_type']
                : null;
            $receiptAccountId = !empty($data['receipt_account_id']) ? (int)$data['receipt_account_id'] : null;
            if (!$receiptAccountType || !$receiptAccountId) {
                throw new RuntimeException('Receipt Account is required for bank/GL posting.');
            }
            $bankAccountId = $receiptAccountType === 'bank' ? $receiptAccountId : (!empty($data['bank_account_id']) ? (int)$data['bank_account_id'] : null);
            $reference = trim((string)($data['reference_number'] ?? ''));
            $notes = trim((string)($data['notes'] ?? ''));
            $chequeId = !empty($data['cheque_id']) ? (int)$data['cheque_id'] : null;
            $cheque = null;
            $existingChequeReceiptTotal = 0.0;
            $chequeAmount = 0.0;
            if ($chequeId) {
                $cheque = re_cheque_load($conn, $companyId, $chequeId);
                if (!$cheque || (int)$cheque['lease_id'] !== $leaseId) {
                    throw new RuntimeException('Selected cheque was not found for this lease.');
                }
                if (re_accounting_normalize_mode((string)($cheque['accounting_mode'] ?? 'legacy')) !== 'invoice') {
                    throw new RuntimeException('Cheque receipt allocation is available only for Invoice Mode leases.');
                }
                $chequeStatus = re_cheque_normalize_status((string)($cheque['status'] ?? ''));
                if ($chequeStatus === 'replaced') {
                    throw new RuntimeException('This cheque was replaced and cannot receive new receipts.');
                }
                $chequeAmount = re_receipt_money($cheque['cheque_amount'] ?? 0);
                if (re_obligation_column_exists($conn, 're_payments', 'cheque_id')) {
                    $existingChequeReceipt = $conn->prepare("
                        SELECT COALESCE(SUM(amount), 0)
                        FROM re_payments
                        WHERE company_id = ?
                          AND lease_id = ?
                          AND cheque_id = ?
                          AND accounting_mode = 'invoice'
                          AND receipt_status = 'cleared'
                        FOR UPDATE
                    ");
                    $existingChequeReceipt->execute([$companyId, $leaseId, $chequeId]);
                    $existingChequeReceiptTotal = re_receipt_money($existingChequeReceipt->fetchColumn() ?: 0);
                    if ($chequeStatus === 'cleared' && $chequeAmount > 0 && $existingChequeReceiptTotal >= $chequeAmount - 0.005) {
                        throw new RuntimeException('This cheque is already fully covered by linked receipts.');
                    }
                    if ($chequeAmount > 0 && ($existingChequeReceiptTotal + $amount) > $chequeAmount + 0.02) {
                        throw new RuntimeException('Receipt amount exceeds the remaining cheque balance of AED ' . number_format(max(0, $chequeAmount - $existingChequeReceiptTotal), 2));
                    }
                } elseif ($chequeStatus === 'cleared') {
                    throw new RuntimeException('This cheque is already marked cleared.');
                }
            }

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
                $reference ?: null,
                $receiptNumber,
                $notes ?: null,
                $userId,
            ]);
            $paymentId = (int)$conn->lastInsertId();
            if ($chequeId && re_obligation_column_exists($conn, 're_payments', 'cheque_id')) {
                $conn->prepare("UPDATE re_payments SET cheque_id = ? WHERE id = ? AND company_id = ?")
                    ->execute([$chequeId, $paymentId, $companyId]);
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
                    'Invoice Mode overpayment from receipt ' . $receiptNumber,
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
                $allocationStatus = $allocatedTotal > 0.005 ? 'overpaid' : 'overpaid';
            } elseif ($allocatedTotal >= $amount - 0.005) {
                $allocationStatus = 'allocated';
            } elseif ($allocatedTotal > 0.005) {
                $allocationStatus = 'partial';
            }
            $conn->prepare("UPDATE re_payments SET allocation_status = ? WHERE id = ? AND company_id = ?")
                ->execute([$allocationStatus, $paymentId, $companyId]);

            if ($chequeId && ($chequeAmount <= 0 || ($existingChequeReceiptTotal + $amount) >= $chequeAmount - 0.005)) {
                $clearResult = re_cheque_update_status(
                    $conn,
                    $companyId,
                    $chequeId,
                    'cleared',
                    $userId,
                    'Cleared through receipt allocation ' . $receiptNumber,
                    'receipt_allocation',
                    $paymentId,
                    null,
                    true
                );
                if (empty($clearResult['success'])) {
                    throw new RuntimeException((string)($clearResult['error'] ?? 'Could not mark cheque cleared.'));
                }
            }

            if (function_exists('re_cheque_sync_cleared_from_financial_coverage')) {
                $coverageSync = re_cheque_sync_cleared_from_financial_coverage(
                    $conn,
                    $companyId,
                    $leaseId,
                    $userId,
                    $paymentId
                );
                if (!empty($coverageSync['errors'])) {
                    throw new RuntimeException(implode(' ', $coverageSync['errors']));
                }
            }

            if (function_exists('post_invoice_mode_receipt_to_accounting')) {
                $posting = post_invoice_mode_receipt_to_accounting($paymentId, $companyId, $userId);
                if (empty($posting['success'])) {
                    throw new RuntimeException('Receipt accounting posting failed: ' . ($posting['error'] ?? 'Unknown error'));
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
            ];
        } catch (Throwable $e) {
            if ($startedHere && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'error' => 'Could not confirm receipt allocation: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('re_receipt_apply_available_tenant_credit')) {
    /**
     * Auto-apply tenant credit only when credit fully clears an open invoice.
     * Never chips leftover fils into a later rent/fee month — remaining credit stays parked.
     * AR adjustment only (2410→1310); receipt/cheque/bank unchanged.
     *
     * @return array{success:bool,applied:float,invoice_count:int,invoice_numbers:list<string>,remaining_credit:float,error:?string}
     */
    function re_receipt_apply_available_tenant_credit(
        PDO $conn,
        int $companyId,
        int $leaseId,
        ?int $userId = null
    ): array {
        $empty = [
            'success' => true,
            'applied' => 0.0,
            'invoice_count' => 0,
            'invoice_numbers' => [],
            'remaining_credit' => 0.0,
            'error' => null,
        ];

        if ($companyId <= 0 || $leaseId <= 0) {
            return $empty;
        }
        if (!function_exists('apply_tenant_credit_to_invoice_accounting') || !function_exists('get_tenant_credit_balance')) {
            return $empty;
        }

        $leaseStmt = $conn->prepare("
            SELECT tenant_id
            FROM re_leases
            WHERE id = ? AND company_id = ?
            LIMIT 1
        ");
        $leaseStmt->execute([$leaseId, $companyId]);
        $tenantId = (int)($leaseStmt->fetchColumn() ?: 0);
        if ($tenantId <= 0) {
            return $empty;
        }

        $creditBalance = re_receipt_money(get_tenant_credit_balance($conn, $tenantId, $companyId));
        if ($creditBalance <= 0.005) {
            return $empty;
        }

        // Oldest open first; full-settle gate inside apply skips months credit cannot clear.
        $invoiceStmt = $conn->prepare("
            SELECT id, invoice_number, outstanding_amount
            FROM re_invoices
            WHERE company_id = ?
              AND lease_id = ?
              AND status NOT IN ('cancelled', 'void')
              AND outstanding_amount > 0.005
            ORDER BY due_date ASC, id ASC
        ");
        $invoiceStmt->execute([$companyId, $leaseId]);
        $invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($invoices === []) {
            $empty['remaining_credit'] = $creditBalance;
            return $empty;
        }

        $appliedTotal = 0.0;
        $invoiceCount = 0;
        $invoiceNumbers = [];
        $errors = [];

        foreach ($invoices as $invoiceRow) {
            $creditBalance = re_receipt_money(get_tenant_credit_balance($conn, $tenantId, $companyId));
            if ($creditBalance <= 0.005) {
                break;
            }
            $invoiceId = (int)($invoiceRow['id'] ?? 0);
            $outstanding = re_receipt_money($invoiceRow['outstanding_amount'] ?? 0);
            if ($invoiceId <= 0 || $outstanding <= 0.005) {
                continue;
            }
            // Skip months that would only receive a partial chip (mirrors apply gate).
            if ($creditBalance + 0.005 < $outstanding) {
                continue;
            }
            $result = apply_tenant_credit_to_invoice_accounting($invoiceId, $companyId, $userId, true);
            if (empty($result['success'])) {
                $errors[] = (string)($result['error'] ?? ('Credit apply failed for invoice #' . $invoiceId));
                break;
            }
            $applied = re_receipt_money($result['applied'] ?? 0);
            if ($applied > 0.005) {
                $appliedTotal = re_receipt_money($appliedTotal + $applied);
                $invoiceCount++;
                $num = trim((string)($invoiceRow['invoice_number'] ?? ''));
                if ($num !== '') {
                    $invoiceNumbers[] = $num;
                }
            }
        }

        $remaining = re_receipt_money(get_tenant_credit_balance($conn, $tenantId, $companyId));

        return [
            'success' => $errors === [],
            'applied' => $appliedTotal,
            'invoice_count' => $invoiceCount,
            'invoice_numbers' => $invoiceNumbers,
            'remaining_credit' => $remaining,
            'error' => $errors !== [] ? implode(' ', $errors) : null,
        ];
    }
}

if (!function_exists('re_cheque_reference_tokens')) {
    /**
     * @return array<int,string>
     */
    function re_cheque_reference_tokens(array $cheque): array
    {
        $tokens = [];
        foreach (['reference_number', 'cheque_number'] as $field) {
            $raw = trim((string)($cheque[$field] ?? ''));
            if ($raw === '') {
                continue;
            }
            $tokens[] = strtoupper($raw);
            foreach (preg_split('/\s*&\s*/', $raw) ?: [] as $part) {
                $part = trim(strtoupper((string)$part));
                if ($part !== '') {
                    $tokens[] = $part;
                }
            }
        }

        return array_values(array_unique($tokens));
    }
}

if (!function_exists('re_payment_matches_cheque_reference')) {
    function re_payment_matches_cheque_reference(array $payment, array $cheque): bool
    {
        $ref = strtoupper(trim((string)($payment['reference_number'] ?? '')));
        if ($ref === '') {
            return false;
        }
        foreach (re_cheque_reference_tokens($cheque) as $token) {
            if ($ref === $token || str_contains($ref, $token) || str_contains($token, $ref)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('re_payment_matches_cheque_notes')) {
    function re_payment_matches_cheque_notes(array $payment, array $cheque): bool
    {
        $notes = strtoupper((string)($payment['notes'] ?? ''));
        if ($notes === '') {
            return false;
        }
        foreach (re_cheque_reference_tokens($cheque) as $token) {
            if ($token !== '' && (str_contains($notes, $token) || str_contains($notes, 'CLEARED CHEQUE ' . $token))) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('re_cheque_build_receipt_summary')) {
    /**
     * Operational collected summary for a cheque row.
     * When financial coverage is present, collected = allocations to that cheque's
     * mapped invoices (capped at cheque face) — never sum full multi-invoice receipt faces.
     *
     * @param array<int,array<string,mixed>> $receipts Explicitly linked receipts (cheque_id / exclusive match)
     * @param array<string,mixed>|null $financialCoverage
     * @return array<string,mixed>
     */
    function re_cheque_build_receipt_summary(array $cheque, array $receipts, ?array $financialCoverage = null): array
    {
        $chequeId = (int)($cheque['id'] ?? 0);
        $chequeAmount = re_receipt_money($cheque['cheque_amount'] ?? 0);

        /** @var array<int,float> $shares payment_id => amount allocated to this cheque's targets */
        $shares = [];
        if ($financialCoverage) {
            foreach ($financialCoverage['receipt_shares'] ?? [] as $pid => $share) {
                $shares[(int)$pid] = re_receipt_money($share);
            }
            foreach ($financialCoverage['receipts'] ?? [] as $row) {
                $pid = (int)($row['id'] ?? 0);
                if ($pid > 0 && isset($row['allocated_to_targets'])) {
                    $shares[$pid] = re_receipt_money($row['allocated_to_targets']);
                }
            }
        }

        $displayReceipts = [];
        $seenIds = [];
        $addDisplay = static function (array $row, bool $inferred) use (&$displayReceipts, &$seenIds, $shares): void {
            $paymentId = (int)($row['id'] ?? 0);
            if ($paymentId <= 0 || isset($seenIds[$paymentId])) {
                return;
            }
            $seenIds[$paymentId] = true;
            $face = re_receipt_money($row['amount'] ?? 0);
            $share = $shares[$paymentId] ?? null;
            $row['receipt_face_amount'] = $face;
            if (isset($row['amount_applied']) && re_receipt_money($row['amount_applied']) > 0.005) {
                $row['display_amount'] = re_receipt_money($row['amount_applied']);
            } elseif (isset($row['display_amount']) && re_receipt_money($row['display_amount']) > 0.005) {
                $row['display_amount'] = re_receipt_money($row['display_amount']);
            } else {
                $row['display_amount'] = $share !== null ? $share : $face;
            }
            // Inferred = came from financial coverage merge (not an exclusive cheque_id link).
            $row['is_coverage_inferred'] = $inferred;
            $displayReceipts[] = $row;
        };

        foreach ($receipts as $row) {
            $addDisplay($row, false);
        }
        if ($financialCoverage) {
            foreach ($financialCoverage['receipts'] ?? [] as $row) {
                // Never list another cheque's exclusive receipt as "Inferred" on this row.
                $linkedChequeId = (int)($row['cheque_id'] ?? 0);
                if ($linkedChequeId > 0 && $chequeId > 0 && $linkedChequeId !== $chequeId) {
                    continue;
                }
                $addDisplay($row, true);
            }
        }

        $hasCoverageAlloc = $financialCoverage && re_receipt_money($financialCoverage['allocated_total'] ?? 0) > 0.005;
        if ($hasCoverageAlloc) {
            $collected = re_receipt_money($financialCoverage['allocated_total'] ?? 0);
            if ($chequeAmount > 0) {
                $collected = re_receipt_money(min($chequeAmount, $collected));
            }
        } else {
            $collected = 0.0;
            foreach ($receipts as $row) {
                $pid = (int)($row['id'] ?? 0);
                $collected += $shares[$pid] ?? re_receipt_money($row['amount'] ?? 0);
            }
            $collected = re_receipt_money($collected);
            if ($chequeAmount > 0) {
                $collected = re_receipt_money(min($chequeAmount, $collected));
            }
        }

        // Explicit cheque_id-linked receipts are authoritative for collection display.
        // Coverage remap can temporarily include only leftover scraps after allocation;
        // do not let that understate collected below the linked receipt face(s).
        $linkedFaceTotal = 0.0;
        foreach ($receipts as $row) {
            if (isset($row['amount_applied']) && re_receipt_money($row['amount_applied']) > 0.005) {
                $linkedFaceTotal = re_receipt_money($linkedFaceTotal + re_receipt_money($row['amount_applied']));
            } elseif (isset($row['display_amount']) && re_receipt_money($row['display_amount']) > 0.005) {
                $linkedFaceTotal = re_receipt_money($linkedFaceTotal + re_receipt_money($row['display_amount']));
            } else {
                $linkedFaceTotal = re_receipt_money($linkedFaceTotal + re_receipt_money($row['amount'] ?? 0));
            }
        }
        if ($linkedFaceTotal > 0.005) {
            $linkedApplied = $chequeAmount > 0
                ? re_receipt_money(min($chequeAmount, $linkedFaceTotal))
                : $linkedFaceTotal;
            if ($linkedApplied > $collected + 0.005) {
                $collected = $linkedApplied;
            }
        }

        $remaining = re_receipt_money(max(0.0, $chequeAmount - $collected));
        $financiallyCovered = !empty($financialCoverage['is_fully_covered']);
        // Linked receipts covering the cheque face mean operationally collected even if
        // a one-fils invoice scrap remains open on a period target.
        if (!$financiallyCovered && $chequeAmount > 0 && $remaining <= 0.02) {
            $financiallyCovered = true;
        }

        $summary = [
            'cheque_id' => $chequeId,
            'cheque_number' => (string)($cheque['cheque_number'] ?? ''),
            'cheque_amount' => $chequeAmount,
            'cheque_status' => re_cheque_normalize_status((string)($cheque['status'] ?? '')),
            'collected_total' => $collected,
            'remaining' => $remaining,
            'receipt_count' => count($displayReceipts),
            'receipts' => array_values($displayReceipts),
            'is_fully_collected' => $financiallyCovered || ($chequeAmount > 0 && $remaining <= 0.005),
            'financial_coverage' => $financialCoverage,
        ];
        $summary['display_status'] = re_cheque_collection_display_status(
            $summary,
            (string)($cheque['status'] ?? '')
        );

        return $summary;
    }
}

if (!function_exists('re_lease_cheque_receipt_summaries')) {
    /**
     * Resolve cleared Invoice Mode receipts for every schedule instrument on a lease.
     * Matches explicit cheque_id links first, then reference/notes, then amount+date proximity.
     * Optionally backfills missing payment.cheque_id when the match is unambiguous.
     *
     * @return array<int,array<string,mixed>>
     */
    function re_lease_cheque_receipt_summaries(PDO $conn, int $companyId, int $leaseId, bool $persistLinks = true): array
    {
        if ($leaseId <= 0 || !re_obligation_column_exists($conn, 're_payments', 'cheque_id')) {
            return [];
        }

        $chequeStmt = $conn->prepare("
            SELECT c.*, li.installment_date
            FROM re_post_dated_cheques c
            LEFT JOIN re_lease_installments li ON li.id = c.installment_id AND li.lease_id = c.lease_id
            WHERE c.company_id = ? AND c.lease_id = ?
            ORDER BY COALESCE(li.installment_date, c.cheque_date) ASC, c.id ASC
        ");
        $chequeStmt->execute([$companyId, $leaseId]);
        $cheques = $chequeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$cheques) {
            return [];
        }

        $paymentStmt = $conn->prepare("
            SELECT id, receipt_number, amount, payment_date, cleared_date, allocation_status,
                   reference_number, notes, cheque_id, installment_id
            FROM re_payments
            WHERE company_id = ? AND lease_id = ? AND accounting_mode = 'invoice'
              AND receipt_status = 'cleared'
            ORDER BY COALESCE(cleared_date, payment_date) ASC, id ASC
        ");
        $paymentStmt->execute([$companyId, $leaseId]);
        $allPayments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        /** @var array<int,int> $assigned payment_id => first cheque_id (for optional backfill only) */
        $assigned = [];
        /** @var array<int,array<int,array<string,mixed>>> $chequeReceipts */
        $chequeReceipts = [];
        foreach ($cheques as $cheque) {
            $chequeReceipts[(int)$cheque['id']] = [];
        }

        $assignPayment = static function (array $payment, int $chequeId) use (&$assigned, &$chequeReceipts): void {
            $paymentId = (int)$payment['id'];
            if ($chequeId <= 0 || !isset($chequeReceipts[$chequeId])) {
                return;
            }
            foreach ($chequeReceipts[$chequeId] as $existing) {
                if ((int)$existing['id'] === $paymentId) {
                    return;
                }
            }
            $chequeReceipts[$chequeId][] = $payment;
            if (!isset($assigned[$paymentId])) {
                $assigned[$paymentId] = $chequeId;
            }
        };

        foreach ($allPayments as $payment) {
            $chequeId = (int)($payment['cheque_id'] ?? 0);
            if ($chequeId > 0) {
                $assignPayment($payment, $chequeId);
            }
        }

        // Explicit multi-cheque junction links (separate from single-cheque cheque_id path).
        try {
            if (!re_obligation_table_exists($conn, 're_receipt_cheque_links')) {
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
            }
            if (re_obligation_table_exists($conn, 're_receipt_cheque_links')) {
                $linkStmt = $conn->prepare("
                    SELECT p.id, p.receipt_number, p.amount, p.payment_date, p.cleared_date, p.allocation_status,
                           p.reference_number, p.notes, p.cheque_id, p.installment_id, l.cheque_id AS linked_cheque_id,
                           l.amount_applied
                    FROM re_receipt_cheque_links l
                    JOIN re_payments p ON p.id = l.payment_id AND p.company_id = l.company_id
                    WHERE l.company_id = ? AND p.lease_id = ?
                      AND p.accounting_mode = 'invoice' AND p.receipt_status = 'cleared'
                ");
                $linkStmt->execute([$companyId, $leaseId]);
                foreach ($linkStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $paymentRow) {
                    $linkedChequeId = (int)($paymentRow['linked_cheque_id'] ?? 0);
                    if ($linkedChequeId <= 0 || !isset($chequeReceipts[$linkedChequeId])) {
                        continue;
                    }
                    $paymentRow['display_amount'] = re_receipt_money($paymentRow['amount_applied'] ?? $paymentRow['amount'] ?? 0);
                    $assignPayment($paymentRow, $linkedChequeId);
                }
            }
        } catch (Throwable $e) {
            error_log('re_lease_cheque_receipt_summaries multi-cheque links: ' . $e->getMessage());
        }

        foreach ($cheques as $cheque) {
            $paymentId = (int)($cheque['payment_id'] ?? 0);
            if ($paymentId <= 0) {
                continue;
            }
            foreach ($allPayments as $payment) {
                if ((int)$payment['id'] === $paymentId) {
                    $assignPayment($payment, (int)$cheque['id']);
                    break;
                }
            }
        }

        foreach ($allPayments as $payment) {
            $paymentId = (int)$payment['id'];
            if (isset($assigned[$paymentId])) {
                continue;
            }
            $matchedChequeIds = [];
            foreach ($cheques as $cheque) {
                if (re_payment_matches_cheque_reference($payment, $cheque)
                    || re_payment_matches_cheque_notes($payment, $cheque)) {
                    $matchedChequeIds[] = (int)$cheque['id'];
                }
            }
            $matchedChequeIds = array_values(array_unique($matchedChequeIds));
            if (count($matchedChequeIds) === 1) {
                $assignPayment($payment, $matchedChequeIds[0]);
            }
        }

        $pairs = [];
        foreach ($allPayments as $payment) {
            $paymentId = (int)$payment['id'];
            if (isset($assigned[$paymentId])) {
                continue;
            }
            $payAmount = re_receipt_money($payment['amount'] ?? 0);
            $payDate = strtotime((string)($payment['cleared_date'] ?? $payment['payment_date'] ?? '')) ?: 0;
            if ($payDate <= 0) {
                continue;
            }
            foreach ($cheques as $cheque) {
                $chequeId = (int)$cheque['id'];
                if (!empty($chequeReceipts[$chequeId])) {
                    continue;
                }
                $chequeAmount = re_receipt_money($cheque['cheque_amount'] ?? 0);
                if (abs($chequeAmount - $payAmount) > 0.01) {
                    continue;
                }
                $chequeDate = strtotime((string)($cheque['installment_date'] ?? $cheque['cheque_date'] ?? '')) ?: 0;
                if ($chequeDate <= 0) {
                    continue;
                }
                $distance = abs($payDate - $chequeDate);
                if ($distance > 45 * 86400) {
                    continue;
                }
                $pairs[] = [
                    'distance' => $distance,
                    'payment' => $payment,
                    'cheque_id' => $chequeId,
                ];
            }
        }
        usort($pairs, static fn(array $a, array $b): int => $a['distance'] <=> $b['distance']);
        $usedCheques = [];
        foreach ($chequeReceipts as $chequeId => $receipts) {
            if (!empty($receipts)) {
                $usedCheques[(int)$chequeId] = true;
            }
        }
        foreach ($pairs as $pair) {
            $paymentId = (int)$pair['payment']['id'];
            $chequeId = (int)$pair['cheque_id'];
            if (isset($assigned[$paymentId]) || isset($usedCheques[$chequeId])) {
                continue;
            }
            $assignPayment($pair['payment'], $chequeId);
            $usedCheques[$chequeId] = true;
        }

        if ($persistLinks) {
            $updateStmt = $conn->prepare("
                UPDATE re_payments
                SET cheque_id = ?
                WHERE id = ? AND company_id = ?
                  AND (cheque_id IS NULL OR cheque_id = 0)
            ");
            foreach ($assigned as $paymentId => $chequeId) {
                $linkedChequeCount = 0;
                foreach ($chequeReceipts as $linkedChequeId => $receiptRows) {
                    foreach ($receiptRows as $receiptRow) {
                        if ((int)($receiptRow['id'] ?? 0) === $paymentId) {
                            $linkedChequeCount++;
                            break;
                        }
                    }
                }
                if ($linkedChequeCount !== 1) {
                    continue;
                }
                foreach ($allPayments as $payment) {
                    if ((int)$payment['id'] !== $paymentId) {
                        continue;
                    }
                    if ((int)($payment['cheque_id'] ?? 0) > 0) {
                        break;
                    }
                    $updateStmt->execute([$chequeId, $paymentId, $companyId]);
                    break;
                }
            }
        }

        $coverageMap = re_cheque_resolve_lease_coverage_map($conn, $companyId, $leaseId);
        $coverageByCheque = [];
        foreach ($coverageMap as $chequeId => $coverage) {
            $coverageByCheque[(int)$chequeId] = re_cheque_coverage_targets_status($conn, $companyId, $coverage, (int)$chequeId);
            // Do not assignPayment coverage receipts onto every cheque — that listed full
            // multi-invoice payment faces on multiple rows. Coverage drives collected via
            // re_cheque_build_receipt_summary (allocated_to_targets / allocated_total).
        }

        $summaries = [];
        foreach ($cheques as $cheque) {
            $chequeId = (int)$cheque['id'];
            $summaries[$chequeId] = re_cheque_build_receipt_summary(
                $cheque,
                $chequeReceipts[$chequeId] ?? [],
                $coverageByCheque[$chequeId] ?? null
            );
        }

        if ($persistLinks && function_exists('re_cheque_sync_cleared_from_financial_coverage')) {
            re_cheque_sync_cleared_from_financial_coverage($conn, $companyId, $leaseId, null, null);
            foreach ($summaries as $chequeId => $summary) {
                if (empty($summary['is_fully_collected'])) {
                    continue;
                }
                $summaries[$chequeId]['cheque_status'] = 'cleared';
                $summaries[$chequeId]['display_status'] = 'cleared';
            }
        }

        return $summaries;
    }
}

if (!function_exists('re_cheque_receipt_summary')) {
    /**
     * Read-only summary of cleared invoice-mode receipts linked to a cheque.
     *
     * @return array{
     *   cheque_id:int,
     *   cheque_number:string,
     *   cheque_amount:float,
     *   cheque_status:string,
     *   collected_total:float,
     *   remaining:float,
     *   receipt_count:int,
     *   receipts:array<int,array<string,mixed>>,
     *   is_fully_collected:bool
     * }
     */
    function re_cheque_receipt_summary(PDO $conn, int $companyId, int $chequeId): array
    {
        $empty = [
            'cheque_id' => $chequeId,
            'cheque_number' => '',
            'cheque_amount' => 0.0,
            'cheque_status' => '',
            'collected_total' => 0.0,
            'remaining' => 0.0,
            'receipt_count' => 0,
            'receipts' => [],
            'is_fully_collected' => false,
            'display_status' => 'pending',
        ];
        if ($chequeId <= 0) {
            return $empty;
        }

        $cheque = re_cheque_load($conn, $companyId, $chequeId);
        if (!$cheque) {
            return $empty;
        }

        $leaseId = (int)($cheque['lease_id'] ?? 0);
        if ($leaseId > 0) {
            $summaries = re_lease_cheque_receipt_summaries($conn, $companyId, $leaseId, true);
            if (isset($summaries[$chequeId])) {
                return $summaries[$chequeId];
            }
        }

        return re_cheque_build_receipt_summary($cheque, []);
    }
}

if (!function_exists('re_cheque_collection_display_status')) {
    /**
     * UI/schedule status that reflects linked receipt progress for Invoice Mode instruments.
     */
    function re_cheque_collection_display_status(array $summary, ?string $dbStatus = null): string
    {
        $dbStatus = re_cheque_normalize_status((string)($dbStatus ?? ($summary['cheque_status'] ?? 'pending')));
        if (!empty($summary['is_fully_collected']) || $dbStatus === 'cleared') {
            return 'cleared';
        }
        if (($summary['collected_total'] ?? 0) > 0.005) {
            return 'partial';
        }
        return $dbStatus;
    }
}

if (!function_exists('re_cheque_collection_display_label')) {
    function re_cheque_collection_display_label(string $status): string
    {
        return match ($status) {
            'partial' => 'Partially Collected',
            'held_by_finance' => 'Held by Finance',
            'legal_escalated' => 'Legal Escalated',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}

if (!function_exists('re_cheque_allocate_payment_url')) {
    function re_cheque_allocate_payment_url(int $leaseId, int $chequeId, array $chequeRow, array $summary): string
    {
        $remaining = re_receipt_money($summary['remaining'] ?? 0);
        $chequeAmount = re_receipt_money($chequeRow['cheque_amount'] ?? ($summary['cheque_amount'] ?? 0));
        $defaultAmount = $remaining > 0.005 ? $remaining : $chequeAmount;

        $params = [
            'lease_id' => $leaseId,
            'cheque_id' => $chequeId,
            'receipt_source' => 'cleared_cheque',
            'cleared_date' => !empty($chequeRow['cheque_date'])
                ? date('Y-m-d', strtotime((string)$chequeRow['cheque_date']))
                : date('Y-m-d'),
            'reference_number' => (string)($chequeRow['reference_number'] ?? $chequeRow['cheque_number'] ?? ''),
            'notes' => 'Receipt from cleared cheque ' . (string)($chequeRow['cheque_number'] ?? ('#' . $chequeId)),
        ];
        if ($defaultAmount > 0.005) {
            $params['amount'] = number_format($defaultAmount, 2, '.', '');
        }

        return 'accounting/receipt_allocation.php?' . http_build_query($params);
    }
}

if (!function_exists('re_receipt_diagnostics')) {
    /**
     * @return array<string,mixed>
     */
    function re_receipt_diagnostics(PDO $conn, int $companyId, ?int $leaseId = null): array
    {
        if (!re_receipt_schema_ready($conn)) {
            return [
                'invoice_mode_receipts' => [],
                'unallocated_receipts' => [],
                'invoice_allocations' => [],
                'obligation_allocations' => [],
                'tenant_credit_allocations' => [],
                'receipt_mode_counts' => [],
                'bounced_cheque_receipt_flags' => [],
            ];
        }

        $leaseSql = $leaseId ? 'AND p.lease_id = ?' : '';
        $args = $leaseId ? [$companyId, $leaseId] : [$companyId];

        $receiptRegister = $conn->prepare("
            SELECT p.id, p.receipt_number, p.payment_date, p.cleared_date, p.amount,
                   p.payment_method, p.receipt_source, p.allocation_status,
                   p.reference_number, l.lease_number,
                   COALESCE(SUM(ra.amount_allocated), 0) AS allocated_amount,
                   COALESCE(SUM(CASE WHEN ra.target_type = 'tenant_credit' THEN ra.amount_allocated ELSE 0 END), 0) AS tenant_credit_amount
            FROM re_payments p
            JOIN re_leases l ON l.id = p.lease_id AND l.company_id = p.company_id
            LEFT JOIN re_receipt_allocations ra ON ra.payment_id = p.id AND ra.company_id = p.company_id
            WHERE p.company_id = ?
              AND p.accounting_mode = 'invoice'
              AND p.receipt_status = 'cleared'
              {$leaseSql}
            GROUP BY p.id
            ORDER BY p.cleared_date DESC, p.id DESC
        ");
        $receiptRegister->execute($args);

        $unallocated = $conn->prepare("
            SELECT p.id, p.receipt_number, p.payment_date, p.cleared_date, p.amount, p.payment_method,
                   p.receipt_source, p.allocation_status, l.lease_number
            FROM re_payments p
            JOIN re_leases l ON l.id = p.lease_id AND l.company_id = p.company_id
            WHERE p.company_id = ?
              AND p.accounting_mode = 'invoice'
              AND p.receipt_status = 'cleared'
              AND (p.allocation_status IS NULL OR p.allocation_status IN ('unallocated','partial'))
              {$leaseSql}
            ORDER BY p.cleared_date DESC, p.id DESC
        ");
        $unallocated->execute($args);

        $invoiceAlloc = $conn->prepare("
            SELECT ra.*, p.receipt_number, p.cleared_date, i.invoice_number
            FROM re_receipt_allocations ra
            JOIN re_payments p ON p.id = ra.payment_id AND p.company_id = ra.company_id
            JOIN re_invoices i ON i.id = ra.invoice_id AND i.company_id = ra.company_id
            WHERE ra.company_id = ? AND ra.target_type = 'invoice' {$leaseSql}
            ORDER BY ra.created_at DESC, ra.id DESC
        ");
        $invoiceAlloc->execute($args);

        $obligationAlloc = $conn->prepare("
            SELECT ra.*, p.receipt_number, p.cleared_date, o.obligation_type, o.description
            FROM re_receipt_allocations ra
            JOIN re_payments p ON p.id = ra.payment_id AND p.company_id = ra.company_id
            JOIN re_obligations o ON o.id = ra.obligation_id AND o.company_id = ra.company_id
            WHERE ra.company_id = ? AND ra.target_type = 'obligation' {$leaseSql}
            ORDER BY ra.created_at DESC, ra.id DESC
        ");
        $obligationAlloc->execute($args);

        $creditAlloc = $conn->prepare("
            SELECT ra.*, p.receipt_number, p.cleared_date
            FROM re_receipt_allocations ra
            JOIN re_payments p ON p.id = ra.payment_id AND p.company_id = ra.company_id
            WHERE ra.company_id = ? AND ra.target_type = 'tenant_credit' {$leaseSql}
            ORDER BY ra.created_at DESC, ra.id DESC
        ");
        $creditAlloc->execute($args);

        $counts = $conn->prepare("
            SELECT COALESCE(accounting_mode, 'legacy') AS mode, COUNT(*) AS receipt_count, COALESCE(SUM(amount), 0) AS total_amount
            FROM re_payments
            WHERE company_id = ? " . ($leaseId ? 'AND lease_id = ?' : '') . "
            GROUP BY COALESCE(accounting_mode, 'legacy')
        ");
        $counts->execute($args);

        $bounced = $conn->prepare("
            SELECT p.id, p.receipt_number, p.amount, p.cheque_id, c.status AS cheque_status
            FROM re_payments p
            JOIN re_post_dated_cheques c ON c.id = p.cheque_id AND c.company_id = p.company_id
            WHERE p.company_id = ?
              AND p.accounting_mode = 'invoice'
              AND p.receipt_status = 'cleared'
              AND c.status = 'bounced'
              " . ($leaseId ? 'AND p.lease_id = ?' : '') . "
            ORDER BY p.id DESC
        ");
        $bounced->execute($args);

        return [
            'invoice_mode_receipts' => $receiptRegister->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'unallocated_receipts' => $unallocated->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'invoice_allocations' => $invoiceAlloc->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'obligation_allocations' => $obligationAlloc->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'tenant_credit_allocations' => $creditAlloc->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'receipt_mode_counts' => $counts->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'bounced_cheque_receipt_flags' => $bounced->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }
}

