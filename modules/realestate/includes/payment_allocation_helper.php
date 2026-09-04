<?php
/**
 * Payment allocation and tenant credit helper
 * Supports multi-allocation, partial payments, overpayment → credit, apply credit.
 */

/**
 * Check if allocation tables exist
 */
function payment_allocation_tables_exist(PDO $conn): bool {
    try {
        $conn->query("SELECT 1 FROM re_payment_allocations LIMIT 1");
        $conn->query("SELECT 1 FROM re_tenant_credit_balances LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get total paid amount for an installment, without double-counting.
 *
 * Three link styles exist across old and new server data:
 *   A) re_payment_allocations.installment_id  (most accurate — partial splits)
 *   B) re_payments.installment_id             (direct, one payment → one installment)
 *   C) re_lease_installments.payment_id       (legacy reverse-link)
 *
 * On live servers, the same payment can be linked via BOTH A and B at the same
 * time, which would double-count if summed naively.
 * Strategy: use A as authoritative; count B only for payments NOT in A;
 * count C only for payments not in A or B.
 */
function get_installment_total_paid(PDO $conn, int $installmentId): float {
    $total = 0.0;

    // ── Step 1: sum all allocation records (most authoritative) ──────────────
    // Collect payment_ids covered here so we skip them in later steps.
    $coveredByAllocation = [];
    try {
        $stmt = $conn->prepare("
            SELECT pa.payment_id, pa.amount_allocated
            FROM re_payment_allocations pa
            WHERE pa.installment_id = ?
        ");
        $stmt->execute([$installmentId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $total += (float)$row['amount_allocated'];
            $coveredByAllocation[] = (int)$row['payment_id'];
        }
    } catch (Throwable $e) {
        // Allocation table absent — that is fine, continue with other sources
    }

    // ── Step 2: direct installment_id link, skip if already in allocations ───
    $coveredByDirect = [];
    try {
        $stmt = $conn->prepare("
            SELECT id, amount FROM re_payments WHERE installment_id = ?
        ");
        $stmt->execute([$installmentId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!in_array((int)$row['id'], $coveredByAllocation, true)) {
                $total += (float)$row['amount'];
            }
            $coveredByDirect[] = (int)$row['id'];
        }
    } catch (Throwable $e) {
        // ignore
    }

    // ── Step 3: legacy li.payment_id reverse-link, skip if already counted ───
    $alreadyCounted = array_unique(array_merge($coveredByAllocation, $coveredByDirect));
    try {
        $stmt = $conn->prepare("
            SELECT p.id, p.amount
            FROM re_payments p
            JOIN re_lease_installments li ON li.payment_id = p.id AND li.id = ?
            WHERE p.installment_id IS NULL
        ");
        $stmt->execute([$installmentId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!in_array((int)$row['id'], $alreadyCounted, true)) {
                $total += (float)$row['amount'];
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    return $total;
}

/**
 * Batch variant of get_installment_total_paid for schedule reads (avoids N+1).
 * Same three-source precedence as the single-id helper.
 *
 * @param list<int> $installmentIds
 * @return array<int,float> installment_id => paid total
 */
function get_installments_total_paid_map(PDO $conn, array $installmentIds): array
{
    $ids = [];
    foreach ($installmentIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    $ids = array_keys($ids);
    $out = [];
    foreach ($ids as $id) {
        $out[$id] = 0.0;
    }
    if ($ids === []) {
        return $out;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $coveredByAllocation = [];

    try {
        $stmt = $conn->prepare("
            SELECT installment_id, payment_id, amount_allocated
            FROM re_payment_allocations
            WHERE installment_id IN ($placeholders)
        ");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $iid = (int)$row['installment_id'];
            $out[$iid] = ($out[$iid] ?? 0.0) + (float)$row['amount_allocated'];
            $coveredByAllocation[$iid][(int)$row['payment_id']] = true;
        }
    } catch (Throwable $e) {
    }

    $coveredByDirect = [];
    try {
        $stmt = $conn->prepare("
            SELECT id, installment_id, amount
            FROM re_payments
            WHERE installment_id IN ($placeholders)
        ");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $iid = (int)$row['installment_id'];
            $pid = (int)$row['id'];
            if (empty($coveredByAllocation[$iid][$pid])) {
                $out[$iid] = ($out[$iid] ?? 0.0) + (float)$row['amount'];
            }
            $coveredByDirect[$iid][$pid] = true;
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = $conn->prepare("
            SELECT li.id AS installment_id, p.id AS payment_id, p.amount
            FROM re_lease_installments li
            JOIN re_payments p ON p.id = li.payment_id
            WHERE li.id IN ($placeholders)
              AND p.installment_id IS NULL
        ");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $iid = (int)$row['installment_id'];
            $pid = (int)$row['payment_id'];
            if (!empty($coveredByAllocation[$iid][$pid]) || !empty($coveredByDirect[$iid][$pid])) {
                continue;
            }
            $out[$iid] = ($out[$iid] ?? 0.0) + (float)$row['amount'];
        }
    } catch (Throwable $e) {
    }

    return $out;
}

/**
 * Get tenant credit balance (AED)
 */
function get_tenant_credit_balance(PDO $conn, int $tenantId, int $companyId): float {
    if (!payment_allocation_tables_exist($conn)) {
        return 0.0;
    }
    try {
        $stmt = $conn->prepare("SELECT balance_aed FROM re_tenant_credit_balances WHERE tenant_id = ? AND company_id = ?");
        $stmt->execute([$tenantId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (float)$row['balance_aed'] : 0.0;
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * How much of a receipt's overpayment credit is still unused vs later applied (FIFO).
 * Debits (applied_invoice / refund / manual) consume oldest credit pockets first.
 *
 * @return array{
 *   is_overpayment_receipt:bool,
 *   credited:float,
 *   remaining:float,
 *   used:float,
 *   usage:string  unused|partial|used
 * }
 */
function re_receipt_overpayment_credit_usage(PDO $conn, int $companyId, int $paymentId, ?int $tenantId = null): array
{
    $empty = [
        'is_overpayment_receipt' => false,
        'credited' => 0.0,
        'remaining' => 0.0,
        'used' => 0.0,
        'usage' => 'unused',
    ];
    if ($companyId <= 0 || $paymentId <= 0 || !payment_allocation_tables_exist($conn)) {
        return $empty;
    }

    try {
        $creditFromAlloc = 0.0;
        try {
            $st = $conn->prepare("
                SELECT COALESCE(SUM(amount_allocated), 0)
                FROM re_receipt_allocations
                WHERE company_id = ? AND payment_id = ? AND target_type = 'tenant_credit'
            ");
            $st->execute([$companyId, $paymentId]);
            $creditFromAlloc = round((float)$st->fetchColumn(), 2);
        } catch (Throwable $e) {
            $creditFromAlloc = 0.0;
        }

        if ($tenantId === null || $tenantId <= 0) {
            $st = $conn->prepare("
                SELECT tenant_id
                FROM re_payments
                WHERE id = ? AND company_id = ?
                LIMIT 1
            ");
            $st->execute([$paymentId, $companyId]);
            $tenantId = (int)($st->fetchColumn() ?: 0);
        }
        if ($tenantId <= 0) {
            return $empty;
        }

        $st = $conn->prepare("
            SELECT id, amount_aed, type, payment_id
            FROM re_tenant_credit_transactions
            WHERE company_id = ? AND tenant_id = ?
            ORDER BY id ASC
        ");
        $st->execute([$companyId, $tenantId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Fallback if receipt has overpaid status but no allocation row (legacy).
        if ($creditFromAlloc <= 0.005) {
            foreach ($rows as $row) {
                if ((int)($row['payment_id'] ?? 0) === $paymentId
                    && (string)($row['type'] ?? '') === 'credit'
                ) {
                    $creditFromAlloc = round((float)($row['amount_aed'] ?? 0), 2);
                    break;
                }
            }
        }
        if ($creditFromAlloc <= 0.005) {
            return $empty;
        }

        /** @var list<array{payment_id:int, remaining:float}> $pockets */
        $pockets = [];
        $targetRemaining = 0.0;
        $foundTarget = false;

        foreach ($rows as $row) {
            $amount = round(abs((float)($row['amount_aed'] ?? 0)), 2);
            if ($amount <= 0.005) {
                continue;
            }
            $type = (string)($row['type'] ?? '');
            $rowPaymentId = (int)($row['payment_id'] ?? 0);

            if ($type === 'credit') {
                $pockets[] = [
                    'payment_id' => $rowPaymentId,
                    'remaining' => $amount,
                ];
                if ($rowPaymentId === $paymentId) {
                    $foundTarget = true;
                    $targetRemaining = $amount;
                }
                continue;
            }

            if ($type !== 'debit') {
                continue;
            }

            // Consume oldest unused credit first.
            $need = $amount;
            foreach ($pockets as &$pocket) {
                if ($need <= 0.005) {
                    break;
                }
                if ($pocket['remaining'] <= 0.005) {
                    continue;
                }
                $take = min($pocket['remaining'], $need);
                $pocket['remaining'] = round($pocket['remaining'] - $take, 2);
                $need = round($need - $take, 2);
                if ($pocket['payment_id'] === $paymentId) {
                    $targetRemaining = $pocket['remaining'];
                    $foundTarget = true;
                }
            }
            unset($pocket);
        }

        if (!$foundTarget) {
            // Allocation said credit existed but txn log missing — treat as unused.
            return [
                'is_overpayment_receipt' => true,
                'credited' => $creditFromAlloc,
                'remaining' => $creditFromAlloc,
                'used' => 0.0,
                'usage' => 'unused',
            ];
        }

        // Recompute remaining specifically for this payment after FIFO (in case multiple
        // credit legs shared the same payment_id).
        $targetRemaining = 0.0;
        foreach ($pockets as $pocket) {
            if ((int)$pocket['payment_id'] === $paymentId) {
                $targetRemaining = round($targetRemaining + (float)$pocket['remaining'], 2);
            }
        }
        $used = round(max(0.0, $creditFromAlloc - $targetRemaining), 2);
        $usage = 'unused';
        if ($used > 0.005 && $targetRemaining > 0.005) {
            $usage = 'partial';
        } elseif ($used > 0.005) {
            $usage = 'used';
        }

        return [
            'is_overpayment_receipt' => true,
            'credited' => $creditFromAlloc,
            'remaining' => max(0.0, $targetRemaining),
            'used' => $used,
            'usage' => $usage,
        ];
    } catch (Throwable $e) {
        return $empty;
    }
}

/**
 * Display label + Bootstrap badge class for Invoice Mode allocation_status,
 * enriching Overpaid when later credit applies have consumed the surplus.
 *
 * @return array{label:string,class:string,title:string}
 */
function re_payment_allocation_status_badge(
    PDO $conn,
    int $companyId,
    array $payment,
    ?array $overpaymentUsage = null
): array {
    $status = strtolower(trim((string)($payment['allocation_status'] ?? '')));
    $label = $status !== '' ? ucwords(str_replace('_', ' ', $status)) : 'n/a';
    $class = 'secondary';
    $title = '';

    if ($status === 'allocated') {
        $class = 'success';
    } elseif ($status === 'partial') {
        $class = 'warning text-dark';
    } elseif ($status === 'overpaid') {
        $class = 'info';
        $paymentId = (int)($payment['id'] ?? 0);
        $tenantId = (int)($payment['tenant_id'] ?? 0);
        $usage = $overpaymentUsage;
        if ($usage === null && $paymentId > 0) {
            $usage = re_receipt_overpayment_credit_usage($conn, $companyId, $paymentId, $tenantId ?: null);
        }
        if (!empty($usage['is_overpayment_receipt'])) {
            $credited = (float)($usage['credited'] ?? 0);
            $used = (float)($usage['used'] ?? 0);
            $remaining = (float)($usage['remaining'] ?? 0);
            if (($usage['usage'] ?? '') === 'used') {
                $label = 'Overpaid (credit later used)';
                $class = 'success';
                $title = sprintf(
                    'This receipt parked AED %.2f as tenant credit; that credit has since been fully applied to later invoices (AR only — receipt unchanged).',
                    $credited
                );
            } elseif (($usage['usage'] ?? '') === 'partial') {
                $label = 'Overpaid (credit partly used)';
                $class = 'primary';
                $title = sprintf(
                    'This receipt parked AED %.2f as tenant credit; AED %.2f applied later, AED %.2f still parked.',
                    $credited,
                    $used,
                    $remaining
                );
            } else {
                $title = sprintf(
                    'AED %.2f from this receipt remains as tenant credit until applied to later invoices.',
                    $credited
                );
            }
        }
    } elseif ($status === 'unallocated') {
        $class = 'secondary';
    }

    return ['label' => $label, 'class' => $class, 'title' => $title];
}

/**
 * Add or deduct tenant credit (with audit in re_tenant_credit_transactions).
 *
 * @param string $transactionType  Human-readable sub-type:
 *   'overpayment'          – cash received beyond what was owed
 *   'applied_installment'  – credit used to settle an installment
 *   'refund_issued'        – cash returned to tenant
 *   'manual_credit'        – manual adjustment (add)
 *   'manual_debit'         – manual adjustment (deduct)
 *   ''                     – auto-derived from $type (legacy)
 */
function update_tenant_credit(PDO $conn, int $tenantId, int $companyId, float $amountAed, string $type, ?int $paymentId = null, ?int $installmentId = null, string $reference = '', string $transactionType = ''): int {
    if (!payment_allocation_tables_exist($conn)) {
        return 0;
    }
    if ($transactionType === '') {
        $transactionType = ($type === 'credit') ? 'overpayment' : 'applied_installment';
    }
    $sign = ($type === 'credit') ? 1 : -1;
    $amountAed = abs($amountAed) * $sign;
    $stmt = $conn->prepare("
        INSERT INTO re_tenant_credit_balances (tenant_id, company_id, balance_aed)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE balance_aed = balance_aed + ?
    ");
    $stmt->execute([$tenantId, $companyId, $amountAed, $amountAed]);

    // Insert into transaction log (transaction_type column may not exist on older DBs — ignore gracefully)
    try {
        $stmt = $conn->prepare("
            INSERT INTO re_tenant_credit_transactions
                (tenant_id, company_id, amount_aed, type, transaction_type, payment_id, installment_id, reference)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tenantId, $companyId, abs($amountAed), $type, $transactionType, $paymentId, $installmentId, $reference]);
    } catch (Throwable $e) {
        // Fallback: column may not exist yet (pre-migration) — insert without transaction_type
        $stmt = $conn->prepare("
            INSERT INTO re_tenant_credit_transactions
                (tenant_id, company_id, amount_aed, type, payment_id, installment_id, reference)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tenantId, $companyId, abs($amountAed), $type, $paymentId, $installmentId, $reference]);
    }
    return (int)$conn->lastInsertId();
}

/**
 * Keep PDC cheque status aligned when the linked installment has been fully paid.
 * Only operationally open cheques are changed; bounced, returned, and cancelled stay untouched.
 *
 * @return array{pdc:int,lease_cheques:int}
 */
function sync_pdc_status_for_paid_installment(PDO $conn, int $installmentId, ?int $paymentId = null): array {
    $stmt = $conn->prepare("
        SELECT li.id,
               COALESCE(NULLIF(li.company_id, 0), pdc_meta.company_id, lc_meta.company_id) AS company_id,
               li.lease_id, li.status, li.paid_at, li.payment_id,
               COALESCE(l.accounting_mode, 'legacy') AS accounting_mode,
               COALESCE(
                   ?,
                   li.payment_id,
                   (SELECT p.id FROM re_payments p WHERE p.installment_id = li.id ORDER BY p.payment_date DESC, p.id DESC LIMIT 1),
                   (SELECT pa.payment_id FROM re_payment_allocations pa WHERE pa.installment_id = li.id ORDER BY pa.id DESC LIMIT 1)
               ) AS resolved_payment_id,
               COALESCE(
                   DATE(li.paid_at),
                   (SELECT p.payment_date FROM re_payments p WHERE p.installment_id = li.id ORDER BY p.payment_date DESC, p.id DESC LIMIT 1),
                   (SELECT p.payment_date FROM re_payment_allocations pa JOIN re_payments p ON p.id = pa.payment_id WHERE pa.installment_id = li.id ORDER BY p.payment_date DESC, p.id DESC LIMIT 1),
                   CURDATE()
               ) AS resolved_paid_date
        FROM re_lease_installments li
        LEFT JOIN re_leases l ON l.id = li.lease_id
        LEFT JOIN (
            SELECT installment_id, lease_id, MIN(company_id) AS company_id
            FROM re_post_dated_cheques
            WHERE installment_id = ?
            GROUP BY installment_id, lease_id
        ) pdc_meta ON pdc_meta.installment_id = li.id AND pdc_meta.lease_id = li.lease_id
        LEFT JOIN (
            SELECT installment_id, lease_id, MIN(company_id) AS company_id
            FROM re_lease_cheques
            WHERE installment_id = ?
            GROUP BY installment_id, lease_id
        ) lc_meta ON lc_meta.installment_id = li.id AND lc_meta.lease_id = li.lease_id
        WHERE li.id = ?
        LIMIT 1
    ");
    $stmt->execute([$paymentId, $installmentId, $installmentId, $installmentId]);
    $installment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$installment || $installment['status'] !== 'paid' || ($installment['accounting_mode'] ?? 'legacy') === 'invoice') {
        return ['pdc' => 0, 'lease_cheques' => 0];
    }

    $resolvedPaymentId = !empty($installment['resolved_payment_id']) ? (int)$installment['resolved_payment_id'] : null;
    $resolvedPaidDate = $installment['resolved_paid_date'] ?: date('Y-m-d');

    $stmt = $conn->prepare("
        UPDATE re_post_dated_cheques
        SET status = 'cleared',
            cleared_date = COALESCE(cleared_date, ?),
            payment_id = COALESCE(payment_id, ?),
            updated_at = NOW()
        WHERE company_id = ?
          AND lease_id = ?
          AND installment_id = ?
          AND status IN ('pending', 'deposited')
    ");
    $stmt->execute([
        $resolvedPaidDate,
        $resolvedPaymentId,
        (int)$installment['company_id'],
        (int)$installment['lease_id'],
        $installmentId,
    ]);
    $pdcUpdated = $stmt->rowCount();

    $stmt = $conn->prepare("
        UPDATE re_lease_cheques
        SET status = 'cleared',
            cleared_date = COALESCE(cleared_date, ?),
            updated_at = NOW()
        WHERE company_id = ?
          AND lease_id = ?
          AND installment_id = ?
          AND status IN ('pending', 'deposited')
    ");
    $stmt->execute([
        $resolvedPaidDate,
        (int)$installment['company_id'],
        (int)$installment['lease_id'],
        $installmentId,
    ]);

    return ['pdc' => $pdcUpdated, 'lease_cheques' => $stmt->rowCount()];
}

/**
 * One-time repair for old rows where installments are already paid but PDC status is stale.
 *
 * @return array{candidates:int,pdc:int,lease_cheques:int}
 */
function sync_stale_paid_pdc_statuses(PDO $conn, int $companyId, bool $apply = false): array {
    $stmt = $conn->prepare("
        SELECT DISTINCT li.id
        FROM re_lease_installments li
        JOIN re_leases l ON l.id = li.lease_id
        JOIN re_post_dated_cheques pdc
          ON pdc.installment_id = li.id
         AND pdc.lease_id = li.lease_id
        WHERE pdc.company_id = ?
          AND COALESCE(l.accounting_mode, 'legacy') <> 'invoice'
          AND li.status = 'paid'
          AND pdc.status IN ('pending', 'deposited')
        ORDER BY li.id ASC
    ");
    $stmt->execute([$companyId]);
    $installmentIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $result = ['candidates' => count($installmentIds), 'pdc' => 0, 'lease_cheques' => 0];
    if (!$apply) {
        return $result;
    }

    foreach ($installmentIds as $installmentId) {
        $stats = sync_pdc_status_for_paid_installment($conn, $installmentId);
        $result['pdc'] += $stats['pdc'];
        $result['lease_cheques'] += $stats['lease_cheques'];
    }

    return $result;
}

/**
 * Update installment status based on total allocated vs amount
 */
function update_installment_status_from_allocations(PDO $conn, int $installmentId): void {
    $stmt = $conn->prepare("SELECT amount, status FROM re_lease_installments WHERE id = ?");
    $stmt->execute([$installmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;
    $amount = (float)$row['amount'];
    $totalPaid = get_installment_total_paid($conn, $installmentId);
    if ($totalPaid >= $amount) {
        $newStatus = 'paid';
    } elseif ($totalPaid > 0) {
        $newStatus = 'partial';
    } else {
        $newStatus = in_array($row['status'], ['overdue', 'pending']) ? $row['status'] : 'pending';
    }
    $stmt = $conn->prepare("
        UPDATE re_lease_installments 
        SET status = ?, paid_at = CASE WHEN ? = 'paid' THEN NOW() ELSE paid_at END
        WHERE id = ?
    ");
    $stmt->execute([$newStatus, $newStatus, $installmentId]);
    sync_pdc_status_for_paid_installment($conn, $installmentId);
}

/**
 * Check if billing-item allocation table exists.
 */
function billing_item_allocations_table_exists(PDO $conn): bool {
    try {
        $conn->query("SELECT 1 FROM re_billing_item_payment_allocations LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Total paid against a billing item, allocation-aware with legacy fallback.
 */
function get_billing_item_total_paid(PDO $conn, int $billingItemId): float {
    $total = 0.0;
    $hasAllocRows = false;

    try {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(amount_allocated), 0)
            FROM re_billing_item_payment_allocations
            WHERE billing_item_id = ?
        ");
        $stmt->execute([$billingItemId]);
        $total = (float)$stmt->fetchColumn();
        $hasAllocRows = $total > 0.005;
    } catch (Throwable $e) {
        $hasAllocRows = false;
    }

    if ($hasAllocRows) {
        return round($total, 2);
    }

    try {
        $stmt = $conn->prepare("
            SELECT COALESCE(paid_amount, 0)
            FROM re_billing_items
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$billingItemId]);
        return round((float)$stmt->fetchColumn(), 2);
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * Update billing item paid/status values from allocation rows.
 */
function update_billing_item_status_from_allocations(PDO $conn, int $billingItemId, ?int $paymentId = null, ?string $paidDate = null): void {
    $stmt = $conn->prepare("
        SELECT id, total_amount, due_date, status
        FROM re_billing_items
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$billingItemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    if (($row['status'] ?? '') === 'waived') {
        return;
    }

    $due = (float)$row['total_amount'];
    $paid = get_billing_item_total_paid($conn, $billingItemId);
    $status = 'pending';
    $isPaid = 0;
    $effectivePaidDate = null;

    if ($paid >= $due - 0.005 && $due > 0) {
        $status = 'paid';
        $isPaid = 1;
        $effectivePaidDate = $paidDate ?: date('Y-m-d');
    } elseif (!empty($row['due_date']) && $row['due_date'] < date('Y-m-d')) {
        $status = 'overdue';
    }

    $stmt = $conn->prepare("
        UPDATE re_billing_items
        SET paid_amount = ?,
            is_paid = ?,
            status = ?,
            paid_date = CASE WHEN ? = 1 THEN COALESCE(paid_date, ?) ELSE NULL END,
            payment_id = CASE WHEN ? = 1 THEN COALESCE(payment_id, ?) ELSE payment_id END
        WHERE id = ?
    ");
    $stmt->execute([
        $paid,
        $isPaid,
        $status,
        $isPaid,
        $effectivePaidDate,
        $isPaid,
        $paymentId,
        $billingItemId,
    ]);
}
