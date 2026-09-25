<?php
/**
 * Vendor Advances / Unapplied Payments helpers (RE shared re_* stack).
 * Additive; legacy fully-allocated payments (advance_amount=0) unchanged.
 *
 * M7 Vendor Advance Refunds: see vendor_advance_refund_helper.php
 * Journal: Dr Bank/Cash / Cr Vendor Advances (1410). Remaining = advance − applied − VAT − refunded.
 */
declare(strict_types=1);

if (!function_exists('re_ap_advance_account')) {
    function re_ap_advance_account(PDO $conn, int $companyId): ?array
    {
        $code = re_ap_setting($conn, 're_vendor_advance_account_code', '1410');
        return find_account_by_code($code, $companyId) ?: find_account_by_code('1410', $companyId);
    }

    function re_ap_advance_table_ready(PDO $conn): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $conn->query('SELECT 1 FROM re_vendor_advance_balances LIMIT 1');
            $conn->query('SELECT 1 FROM re_vendor_advance_applications LIMIT 1');
            $col = $conn->query("SHOW COLUMNS FROM re_vendor_payments LIKE 'advance_amount'");
            $ready = (bool)$col->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }

    function re_ap_advance_applied_on_bill(PDO $conn, int $companyId, int $billId): float
    {
        if (!re_ap_advance_table_ready($conn)) {
            return 0.0;
        }
        $st = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM re_vendor_advance_applications
            WHERE company_id = ? AND vendor_invoice_id = ? AND status = 'posted'
        ");
        $st->execute([$companyId, $billId]);
        return re_ap_money($st->fetchColumn());
    }

    function re_ap_payment_advance_applied(PDO $conn, int $companyId, int $paymentId): float
    {
        if (!re_ap_advance_table_ready($conn)) {
            return 0.0;
        }
        $st = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM re_vendor_advance_applications
            WHERE company_id = ? AND vendor_payment_id = ? AND status = 'posted'
        ");
        $st->execute([$companyId, $paymentId]);
        return re_ap_money($st->fetchColumn());
    }

    function re_ap_payment_advance_remaining(PDO $conn, int $companyId, int $paymentId): float
    {
        $st = $conn->prepare("SELECT COALESCE(advance_amount, 0) FROM re_vendor_payments WHERE id = ? AND company_id = ?");
        $st->execute([$paymentId, $companyId]);
        $orig = re_ap_money($st->fetchColumn());
        $vat = function_exists('re_ap_payment_advance_vat_posted')
            ? re_ap_payment_advance_vat_posted($conn, $companyId, $paymentId)
            : 0.0;
        $refunded = function_exists('re_ap_payment_advance_refunded')
            ? re_ap_payment_advance_refunded($conn, $companyId, $paymentId)
            : 0.0;
        return re_ap_money(max(
            0,
            $orig - re_ap_payment_advance_applied($conn, $companyId, $paymentId) - $vat - $refunded
        ));
    }

    function re_ap_vendor_advance_balance(PDO $conn, int $companyId, int $vendorId): float
    {
        if (!re_ap_advance_table_ready($conn)) {
            return 0.0;
        }
        $st = $conn->prepare("SELECT balance_aed FROM re_vendor_advance_balances WHERE company_id = ? AND vendor_id = ? LIMIT 1");
        $st->execute([$companyId, $vendorId]);
        $v = $st->fetchColumn();
        return $v === false ? 0.0 : re_ap_money($v);
    }

    /**
     * Ensure balance row exists and lock it (caller must be in a transaction).
     */
    function re_ap_lock_vendor_advance_balance(PDO $conn, int $companyId, int $vendorId): array
    {
        $ins = $conn->prepare("
            INSERT IGNORE INTO re_vendor_advance_balances (company_id, vendor_id, balance_aed)
            VALUES (?, ?, 0)
        ");
        $ins->execute([$companyId, $vendorId]);
        $st = $conn->prepare("
            SELECT * FROM re_vendor_advance_balances
            WHERE company_id = ? AND vendor_id = ?
            FOR UPDATE
        ");
        $st->execute([$companyId, $vendorId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Unable to lock vendor advance balance.');
        }
        return $row;
    }

    function re_ap_adjust_vendor_advance_balance(PDO $conn, int $companyId, int $vendorId, float $delta): float
    {
        $row = re_ap_lock_vendor_advance_balance($conn, $companyId, $vendorId);
        $new = re_ap_money(((float)$row['balance_aed']) + $delta);
        if ($new < -0.005) {
            throw new RuntimeException('Vendor advance balance cannot go negative.');
        }
        if ($new < 0) {
            $new = 0.0;
        }
        $conn->prepare("UPDATE re_vendor_advance_balances SET balance_aed = ? WHERE company_id = ? AND vendor_id = ?")
            ->execute([$new, $companyId, $vendorId]);
        return $new;
    }

    /**
     * Source payments with remaining advance (FIFO by payment_date, id).
     * @return list<array{id:int,payment_date:string,remaining:float}>
     */
    function re_ap_advance_source_payments(PDO $conn, int $companyId, int $vendorId, ?array $forcePaymentIds = null): array
    {
        $vatSub = '0';
        if (function_exists('re_ap_advance_vat_table_ready') && re_ap_advance_vat_table_ready($conn)) {
            $vatSub = "COALESCE((
                       SELECT SUM(d.vat_amount) FROM re_vendor_advance_vat_documents d
                       WHERE d.company_id = vp.company_id AND d.vendor_payment_id = vp.id AND d.status = 'posted'
                   ), 0)";
        }
        $refundSub = '0';
        if (function_exists('re_ap_advance_refund_table_ready') && re_ap_advance_refund_table_ready($conn)) {
            $refundSub = "COALESCE((
                       SELECT SUM(r.amount) FROM re_vendor_advance_refunds r
                       WHERE r.company_id = vp.company_id AND r.vendor_payment_id = vp.id AND r.status = 'posted'
                   ), 0)";
        }
        $sql = "
            SELECT vp.id, vp.payment_date, vp.advance_amount,
                   COALESCE((
                       SELECT SUM(a.amount) FROM re_vendor_advance_applications a
                       WHERE a.company_id = vp.company_id AND a.vendor_payment_id = vp.id AND a.status = 'posted'
                   ), 0) AS applied,
                   {$vatSub} AS vat_posted,
                   {$refundSub} AS refunded
            FROM re_vendor_payments vp
            WHERE vp.company_id = ? AND vp.vendor_id = ?
              AND vp.status = 'posted'
              AND COALESCE(vp.advance_amount, 0) > 0.005
        ";
        $params = [$companyId, $vendorId];
        if ($forcePaymentIds !== null && $forcePaymentIds !== []) {
            $ids = array_values(array_unique(array_map('intval', $forcePaymentIds)));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $sql .= " AND vp.id IN ($in)";
            $params = array_merge($params, $ids);
        }
        $sql .= " ORDER BY vp.payment_date ASC, vp.id ASC";
        $st = $conn->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $rem = re_ap_money(
                (float)$r['advance_amount'] - (float)$r['applied'] - (float)$r['vat_posted'] - (float)$r['refunded']
            );
            if ($rem > 0.005) {
                $out[] = [
                    'id' => (int)$r['id'],
                    'payment_date' => (string)$r['payment_date'],
                    'remaining' => $rem,
                ];
            }
        }
        return $out;
    }

    /**
     * Allocate apply amount across source payments (FIFO or forced list order).
     * @return list<array{payment_id:int,amount:float}>
     */
    function re_ap_advance_plan_consumption(array $sources, float $amount): array
    {
        $left = re_ap_money($amount);
        $plan = [];
        $available = 0.0;
        foreach ($sources as $src) {
            $available = re_ap_money($available + (float)$src['remaining']);
            if ($left <= 0.005) {
                break;
            }
            $take = re_ap_money(min((float)$src['remaining'], $left));
            if ($take <= 0.005) {
                continue;
            }
            $plan[] = ['payment_id' => (int)$src['id'], 'amount' => $take];
            $left = re_ap_money($left - $take);
        }
        if ($left > 0.005) {
            throw new RuntimeException(
                'Insufficient vendor advance remaining on selected source payments. '
                . 'Available on selectable payments: ' . number_format($available, 2)
                . ' AED; requested: ' . number_format($amount, 2) . ' AED. '
                . 'Leave Source payments blank to use FIFO across all advance payments.'
            );
        }
        return $plan;
    }

    /**
     * Resolve journal line id for account on a journal. Fail closed if missing.
     */
    function re_ap_journal_line_id_for_account(PDO $conn, int $journalId, int $accountId): int
    {
        $st = $conn->prepare("
            SELECT id FROM re_journal_lines
            WHERE journal_id = ? AND account_id = ?
            ORDER BY line_number
            LIMIT 1
        ");
        $st->execute([$journalId, $accountId]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id <= 0) {
            throw new RuntimeException(
                "Journal line not found for journal #{$journalId} account #{$accountId}."
            );
        }
        return $id;
    }

    /**
     * Post vendor sub-ledger with resolved journal_line_id. Fail closed on any error.
     */
    function re_ap_post_to_vendor_ledger_required(
        PDO $conn,
        int $ledgerId,
        string $entryDate,
        float $debit,
        float $credit,
        string $description,
        ?string $reference,
        int $companyId,
        int $journalId,
        int $accountId
    ): void {
        $lineId = re_ap_journal_line_id_for_account($conn, $journalId, $accountId);
        $ok = post_to_vendor_ledger(
            $ledgerId,
            $entryDate,
            $debit,
            $credit,
            $description,
            $reference,
            $companyId,
            $journalId,
            $lineId
        );
        if ($ok !== true) {
            throw new RuntimeException(
                "Vendor sub-ledger posting failed for journal #{$journalId} account #{$accountId}."
            );
        }
    }
}

/**
 * Apply vendor advance to a bill. Atomic; one application row per source payment.
 *
 * @param list<int>|null $sourcePaymentIds null = FIFO all; non-empty = manual selection order then remaining FIFO among selected only
 */
function re_ap_apply_vendor_advance(
    PDO $conn,
    int $companyId,
    int $vendorId,
    int $billId,
    float $amount,
    ?int $userId = null,
    ?array $sourcePaymentIds = null
): array {
    if ($companyId <= 0) {
        return ['success' => false, 'error' => 'Company context is required.', 'application_ids' => [], 'journal_id' => null];
    }
    if (!re_ap_advance_table_ready($conn)) {
        return ['success' => false, 'error' => 'Vendor advance schema not installed.', 'application_ids' => [], 'journal_id' => null];
    }
    $amount = re_ap_money($amount);
    if ($amount <= 0.005) {
        return ['success' => false, 'error' => 'Apply amount must be greater than zero.', 'application_ids' => [], 'journal_id' => null];
    }

    $ownTx = !$conn->inTransaction();
    try {
        if ($ownTx) {
            $conn->beginTransaction();
        }

        re_ap_lock_vendor_advance_balance($conn, $companyId, $vendorId);

        $bill = re_ap_load_bill($conn, $companyId, $billId);
        if (!$bill || (int)$bill['vendor_id'] !== $vendorId) {
            throw new RuntimeException('Bill not found for vendor.');
        }
        if (($bill['posting_status'] ?? '') !== 'posted' || in_array($bill['status'], ['void', 'cancelled', 'draft', 'paid'], true)) {
            throw new RuntimeException('Bill is not open for advance application.');
        }

        $bal = re_ap_vendor_advance_balance($conn, $companyId, $vendorId);
        if ($amount > $bal + 0.005) {
            throw new RuntimeException('Apply amount exceeds available vendor advance.');
        }

        re_ap_refresh_bill_status($conn, $companyId, $billId);
        $bill = re_ap_load_bill($conn, $companyId, $billId);
        $due = function_exists('re_ap_bill_outstanding')
            ? re_ap_bill_outstanding($conn, $companyId, $billId)
            : re_ap_money($bill['balance_due'] ?? 0);
        if ($amount > $due + 0.005) {
            throw new RuntimeException('Apply amount exceeds bill balance due (' . number_format($due, 2) . ' AED).');
        }

        // Empty / invalid forced IDs → FIFO across all payments (do not treat [] as a filter).
        $forcedIds = null;
        if ($sourcePaymentIds !== null) {
            $forcedIds = array_values(array_filter(array_map('intval', $sourcePaymentIds), static function ($id) {
                return $id > 0;
            }));
            if ($forcedIds === []) {
                $forcedIds = null;
            }
        }

        $sources = re_ap_advance_source_payments($conn, $companyId, $vendorId, $forcedIds);
        // If explicit payment IDs have no remaining but vendor still has balance, fall back to FIFO.
        if (!$sources && $forcedIds !== null) {
            $sources = re_ap_advance_source_payments($conn, $companyId, $vendorId, null);
            if ($sources) {
                // Continue with FIFO; do not fail closed on stale/wrong source IDs.
                $forcedIds = null;
            }
        }
        if (!$sources) {
            $hint = '';
            if ($bal > 0.005) {
                $hint = ' Vendor advance balance is ' . number_format($bal, 2)
                    . ' AED but no payment has remaining advance (check VAT documents and prior applications).';
            }
            throw new RuntimeException(
                'No source payments with remaining vendor advance were found for this vendor.' . $hint
            );
        }
        $plan = re_ap_advance_plan_consumption($sources, $amount);

        $ap = re_ap_account($conn, $companyId);
        $adv = re_ap_advance_account($conn, $companyId);
        if (!$ap || !$adv) {
            throw new RuntimeException('AP or Vendor Advances account not found.');
        }

        $apLedger = get_or_create_vendor_ledger($vendorId, (int)$ap['id'], $companyId);
        $advLedger = get_or_create_vendor_ledger($vendorId, (int)$adv['id'], $companyId);
        $ins = $conn->prepare("
            INSERT INTO re_vendor_advance_applications
            (company_id, vendor_id, vendor_payment_id, vendor_invoice_id, amount, journal_id, status, created_by)
            VALUES (?, ?, ?, ?, ?, NULL, 'posted', ?)
        ");
        $upd = $conn->prepare("
            UPDATE re_vendor_advance_applications
            SET journal_id = ?
            WHERE id = ? AND company_id = ?
        ");
        $appIds = [];
        $lastJournalId = null;
        foreach ($plan as $p) {
            $slice = re_ap_money($p['amount']);
            // Insert application first so journal reference_id is unique per application
            // (engine enforces one posted journal per reference_type + reference_id).
            $ins->execute([$companyId, $vendorId, $p['payment_id'], $billId, $slice, $userId]);
            $applicationId = (int)$conn->lastInsertId();
            if ($applicationId <= 0) {
                throw new RuntimeException('Failed to create advance application row.');
            }

            $desc = 'Apply vendor advance to bill ' . $bill['invoice_number'] . ' (PAY-' . (int)$p['payment_id'] . ')';
            $lines = [
                ['account_id' => (int)$ap['id'], 'debit' => $slice, 'credit' => 0, 'description' => $desc, 'reference' => $bill['invoice_number']],
                ['account_id' => (int)$adv['id'], 'debit' => 0, 'credit' => $slice, 'description' => $desc, 'reference' => $bill['invoice_number']],
            ];
            $res = create_and_post_journal(
                $companyId,
                'payment',
                'vendor_advance_application',
                $applicationId,
                $lines,
                $desc,
                date('Y-m-d'),
                $userId
            );
            if (empty($res['success'])) {
                throw new RuntimeException($res['error'] ?? 'Advance application journal failed');
            }
            $journalId = (int)$res['journal_id'];
            $lastJournalId = $journalId;
            $upd->execute([$journalId, $applicationId, $companyId]);
            re_ap_post_to_vendor_ledger_required(
                $conn,
                (int)$apLedger['id'],
                date('Y-m-d'),
                $slice,
                0,
                $desc,
                $bill['invoice_number'],
                $companyId,
                $journalId,
                (int)$ap['id']
            );
            re_ap_post_to_vendor_ledger_required(
                $conn,
                (int)$advLedger['id'],
                date('Y-m-d'),
                0,
                $slice,
                $desc,
                $bill['invoice_number'],
                $companyId,
                $journalId,
                (int)$adv['id']
            );
            $appIds[] = $applicationId;
        }

        re_ap_adjust_vendor_advance_balance($conn, $companyId, $vendorId, -$amount);
        re_ap_refresh_bill_status($conn, $companyId, $billId);
        re_ap_audit($conn, $companyId, $vendorId, $billId, (int)$plan[0]['payment_id'], 'advance_applied', null, (string)json_encode($plan), $amount, 'Vendor advance applied to bill', $userId, 'vendor_advance_apply', $lastJournalId);

        if ($ownTx) {
            $conn->commit();
        }
        return ['success' => true, 'error' => null, 'application_ids' => $appIds, 'journal_id' => $lastJournalId];
    } catch (Throwable $e) {
        if ($ownTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage(), 'application_ids' => [], 'journal_id' => null];
    }
}

function re_ap_unapply_vendor_advance(PDO $conn, int $companyId, int $applicationId, string $reason = '', ?int $userId = null): array
{
    if ($companyId <= 0) {
        return ['success' => false, 'error' => 'Company context is required.', 'reversal_journal_id' => null];
    }
    $ownTx = !$conn->inTransaction();
    try {
        if ($ownTx) {
            $conn->beginTransaction();
        }
        $st = $conn->prepare("SELECT * FROM re_vendor_advance_applications WHERE id = ? AND company_id = ? FOR UPDATE");
        $st->execute([$applicationId, $companyId]);
        $app = $st->fetch(PDO::FETCH_ASSOC);
        if (!$app) {
            throw new RuntimeException('Advance application not found.');
        }
        if (($app['status'] ?? '') === 'reversed') {
            if ($ownTx) {
                $conn->commit();
            }
            return ['success' => true, 'error' => null, 'reversal_journal_id' => null, 'already_reversed' => true];
        }
        $vendorId = (int)$app['vendor_id'];
        $billId = (int)$app['vendor_invoice_id'];
        $amount = re_ap_money($app['amount']);
        $journalId = (int)($app['journal_id'] ?? 0);

        re_ap_lock_vendor_advance_balance($conn, $companyId, $vendorId);

        $reversalJournalId = null;
        if ($journalId > 0) {
            $rev = reverse_journal($journalId, $reason ?: 'Vendor advance unapplied', $userId);
            if (empty($rev['success'])) {
                throw new RuntimeException($rev['error'] ?? 'Failed to reverse advance application journal');
            }
            $reversalJournalId = (int)$rev['reversal_journal_id'];
            $ap = re_ap_account($conn, $companyId);
            $adv = re_ap_advance_account($conn, $companyId);
            if (!$ap || !$adv) {
                throw new RuntimeException('AP or Vendor Advances account not found for unapply sub-ledger.');
            }
            $apLedger = get_or_create_vendor_ledger($vendorId, (int)$ap['id'], $companyId);
            $advLedger = get_or_create_vendor_ledger($vendorId, (int)$adv['id'], $companyId);
            re_ap_post_to_vendor_ledger_required(
                $conn,
                (int)$apLedger['id'],
                date('Y-m-d'),
                0,
                $amount,
                'Unapply vendor advance',
                null,
                $companyId,
                $reversalJournalId,
                (int)$ap['id']
            );
            re_ap_post_to_vendor_ledger_required(
                $conn,
                (int)$advLedger['id'],
                date('Y-m-d'),
                $amount,
                0,
                'Unapply vendor advance',
                null,
                $companyId,
                $reversalJournalId,
                (int)$adv['id']
            );
        }

        $conn->prepare("UPDATE re_vendor_advance_applications SET status = 'reversed' WHERE id = ? AND company_id = ?")
            ->execute([$applicationId, $companyId]);
        re_ap_adjust_vendor_advance_balance($conn, $companyId, $vendorId, $amount);
        re_ap_refresh_bill_status($conn, $companyId, $billId);
        re_ap_audit($conn, $companyId, $vendorId, $billId, (int)$app['vendor_payment_id'], 'advance_unapplied', (string)$journalId, (string)($reversalJournalId ?? ''), $amount, $reason ?: 'Vendor advance unapplied', $userId, 'vendor_advance_unapply', $reversalJournalId);

        if ($ownTx) {
            $conn->commit();
        }
        return ['success' => true, 'error' => null, 'reversal_journal_id' => $reversalJournalId];
    } catch (Throwable $e) {
        if ($ownTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage(), 'reversal_journal_id' => null];
    }
}

/**
 * Reverse an original vendor payment. Requires no posted advance applications.
 * Bill allocations normally block the reversal; pass $clearAllocations = true to drop
 * them first (used when a wrongly entered payment is being undone with its bill).
 */
function re_ap_reverse_vendor_payment(PDO $conn, int $companyId, int $paymentId, string $reason = '', ?int $userId = null, bool $clearAllocations = false): array
{
    if ($companyId <= 0) {
        return ['success' => false, 'error' => 'Company context is required.', 'reversal_journal_id' => null];
    }
    $ownTx = !$conn->inTransaction();
    try {
        if ($ownTx) {
            $conn->beginTransaction();
        }
        $st = $conn->prepare("SELECT * FROM re_vendor_payments WHERE id = ? AND company_id = ? FOR UPDATE");
        $st->execute([$paymentId, $companyId]);
        $pay = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pay) {
            throw new RuntimeException('Vendor payment not found.');
        }
        if (($pay['status'] ?? '') === 'void') {
            if ($ownTx) {
                $conn->commit();
            }
            return ['success' => true, 'error' => null, 'reversal_journal_id' => null, 'already_void' => true];
        }
        $vendorId = (int)$pay['vendor_id'];
        re_ap_lock_vendor_advance_balance($conn, $companyId, $vendorId);

        $apps = $conn->prepare("
            SELECT COUNT(*) FROM re_vendor_advance_applications
            WHERE company_id = ? AND vendor_payment_id = ? AND status = 'posted'
        ");
        $apps->execute([$companyId, $paymentId]);
        if ((int)$apps->fetchColumn() > 0) {
            throw new RuntimeException('Unapply all advance applications from this payment before reversing it.');
        }

        if (function_exists('re_ap_payment_advance_vat_posted') && re_ap_payment_advance_vat_posted($conn, $companyId, $paymentId) > 0.005) {
            throw new RuntimeException('Reverse all advance VAT documents on this payment before reversing it.');
        }

        if (function_exists('re_ap_payment_advance_refunded') && re_ap_payment_advance_refunded($conn, $companyId, $paymentId) > 0.005) {
            throw new RuntimeException('Reverse all advance refunds on this payment before reversing it.');
        }

        $allocRows = $conn->prepare("
            SELECT vendor_invoice_id, amount_allocated FROM re_vendor_payment_allocations
            WHERE company_id = ? AND vendor_payment_id = ?
        ");
        $allocRows->execute([$companyId, $paymentId]);
        $allocRows = $allocRows->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $allocatedTotal = 0.0;
        $touchedBills = [];
        foreach ($allocRows as $row) {
            $allocatedTotal += (float)$row['amount_allocated'];
            $touchedBills[] = (int)$row['vendor_invoice_id'];
        }
        if ($allocatedTotal > 0.005) {
            if (!$clearAllocations) {
                throw new RuntimeException('Clear bill allocations on this payment before reversing it.');
            }
            $conn->prepare("DELETE FROM re_vendor_payment_allocations WHERE company_id = ? AND vendor_payment_id = ?")
                ->execute([$companyId, $paymentId]);
        }

        $journalId = (int)($pay['journal_id'] ?? 0);
        if ($journalId <= 0) {
            throw new RuntimeException('Payment has no posted journal to reverse.');
        }
        $jh = $conn->prepare("SELECT is_posted,is_reversed,reversal_journal_id FROM re_journal_headers WHERE id = ? AND company_id = ?");
        $jh->execute([$journalId, $companyId]);
        $jhRow = $jh->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($jhRow && (int)($jhRow['is_reversed'] ?? 0) === 1 && (int)($jhRow['reversal_journal_id'] ?? 0) > 0) {
            // Journal was already reversed straight from the journal screen; only the
            // payment record and its sub-ledger are still out of sync.
            $reversalJournalId = (int)$jhRow['reversal_journal_id'];
        } else {
            $rev = reverse_journal($journalId, $reason ?: 'Vendor payment reversed', $userId);
            if (empty($rev['success'])) {
                throw new RuntimeException($rev['error'] ?? 'Payment journal reversal failed');
            }
            $reversalJournalId = (int)$rev['reversal_journal_id'];
        }

        $advanceAmount = re_ap_money($pay['advance_amount'] ?? 0);
        $remainingAdv = re_ap_payment_advance_remaining($conn, $companyId, $paymentId);
        if ($remainingAdv > 0.005) {
            re_ap_adjust_vendor_advance_balance($conn, $companyId, $vendorId, -$remainingAdv);
            $adv = re_ap_advance_account($conn, $companyId);
            if (!$adv) {
                throw new RuntimeException('Vendor Advances account not found for payment reversal sub-ledger.');
            }
            $advLedger = get_or_create_vendor_ledger($vendorId, (int)$adv['id'], $companyId);
            // Reverse original advance debit: credit the asset sub-ledger
            re_ap_post_to_vendor_ledger_required(
                $conn,
                (int)$advLedger['id'],
                date('Y-m-d'),
                0,
                $remainingAdv,
                'Reverse vendor advance payment',
                $pay['reference_number'] ?? null,
                $companyId,
                $reversalJournalId,
                (int)$adv['id']
            );
        }

        $allocated = re_ap_money((float)$pay['amount'] - $advanceAmount);
        if ($allocated > 0.005) {
            $ap = re_ap_account($conn, $companyId);
            if (!$ap) {
                throw new RuntimeException('AP account not found for payment reversal sub-ledger.');
            }
            $apLedger = get_or_create_vendor_ledger($vendorId, (int)$ap['id'], $companyId);
            re_ap_post_to_vendor_ledger_required(
                $conn,
                (int)$apLedger['id'],
                date('Y-m-d'),
                0,
                $allocated,
                'Reverse vendor payment',
                $pay['reference_number'] ?? null,
                $companyId,
                $reversalJournalId,
                (int)$ap['id']
            );
        }

        $conn->prepare("UPDATE re_vendor_payments SET status = 'void' WHERE id = ? AND company_id = ?")
            ->execute([$paymentId, $companyId]);
        foreach (array_unique($touchedBills) as $touchedBillId) {
            if ($touchedBillId > 0 && function_exists('re_ap_refresh_bill_status')) {
                re_ap_refresh_bill_status($conn, $companyId, $touchedBillId);
            }
        }
        re_ap_audit($conn, $companyId, $vendorId, null, $paymentId, 'payment_reversed', (string)$journalId, (string)$reversalJournalId, (float)$pay['amount'], $reason ?: 'Vendor payment reversed', $userId, 'vendor_payment_reverse', $reversalJournalId);

        if ($ownTx) {
            $conn->commit();
        }
        return ['success' => true, 'error' => null, 'reversal_journal_id' => $reversalJournalId];
    } catch (Throwable $e) {
        if ($ownTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage(), 'reversal_journal_id' => null];
    }
}

// Soft-load refund helpers so remaining/refunded stay consistent.
$__reAdvRefundHelper = __DIR__ . "/vendor_advance_refund_helper.php";
if (is_file($__reAdvRefundHelper) && !function_exists("re_ap_payment_advance_refunded")) {
    require_once $__reAdvRefundHelper;
}
unset($__reAdvRefundHelper);
