<?php
/**
 * Vendor Advance VAT Documents (M5) — document-driven Input VAT for RE advances.
 * Payment journals (M1–M4) unchanged. VAT JE: Dr Input VAT / Cr 1410.
 */
declare(strict_types=1);

require_once __DIR__ . '/vendor_advance_helper.php';

function re_ap_advance_vat_table_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $conn->query('SELECT 1 FROM re_vendor_advance_vat_documents LIMIT 1');
        $conn->query('SELECT 1 FROM re_vendor_advance_vat_bill_links LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function re_ap_payment_advance_vat_posted(PDO $conn, int $companyId, int $paymentId): float
{
    if (!re_ap_advance_vat_table_ready($conn)) {
        return 0.0;
    }
    $st = $conn->prepare("
        SELECT COALESCE(SUM(vat_amount), 0)
        FROM re_vendor_advance_vat_documents
        WHERE company_id = ? AND vendor_payment_id = ? AND status = 'posted'
    ");
    $st->execute([$companyId, $paymentId]);
    return re_ap_money($st->fetchColumn());
}

/**
 * Advance remaining on a payment available for bill apply OR further VAT docs.
 * = original advance − posted applications − posted VAT − posted refunds.
 */
function re_ap_payment_advance_net_remaining(PDO $conn, int $companyId, int $paymentId): float
{
    return re_ap_payment_advance_remaining($conn, $companyId, $paymentId);
}

function re_ap_advance_vat_doc_consumed(PDO $conn, int $companyId, int $docId): float
{
    if (!re_ap_advance_vat_table_ready($conn)) {
        return 0.0;
    }
    $st = $conn->prepare("
        SELECT COALESCE(SUM(vat_amount_linked), 0)
        FROM re_vendor_advance_vat_bill_links
        WHERE company_id = ? AND advance_vat_document_id = ? AND status = 'posted'
    ");
    $st->execute([$companyId, $docId]);
    return re_ap_money($st->fetchColumn());
}

function re_ap_advance_vat_doc_remaining(PDO $conn, int $companyId, int $docId): float
{
    $st = $conn->prepare("SELECT vat_amount, status FROM re_vendor_advance_vat_documents WHERE id = ? AND company_id = ?");
    $st->execute([$docId, $companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || ($row['status'] ?? '') !== 'posted') {
        return 0.0;
    }
    return re_ap_money(max(0, (float)$row['vat_amount'] - re_ap_advance_vat_doc_consumed($conn, $companyId, $docId)));
}

function re_ap_advance_vat_linked_to_bill(PDO $conn, int $companyId, int $billId): float
{
    if (!re_ap_advance_vat_table_ready($conn)) {
        return 0.0;
    }
    $st = $conn->prepare("
        SELECT COALESCE(SUM(vat_amount_linked), 0)
        FROM re_vendor_advance_vat_bill_links
        WHERE company_id = ? AND vendor_invoice_id = ? AND status = 'posted'
    ");
    $st->execute([$companyId, $billId]);
    return re_ap_money($st->fetchColumn());
}

function re_ap_bill_eligible_vat(PDO $conn, int $companyId, int $billId): float
{
    $st = $conn->prepare("
        SELECT COALESCE(SUM(vat_amount), 0)
        FROM re_vendor_invoice_items
        WHERE company_id = ? AND invoice_id = ?
    ");
    $st->execute([$companyId, $billId]);
    return re_ap_money($st->fetchColumn());
}

function re_ap_bill_remaining_input_vat(PDO $conn, int $companyId, int $billId): float
{
    $eligible = re_ap_bill_eligible_vat($conn, $companyId, $billId);
    $linked = re_ap_advance_vat_linked_to_bill($conn, $companyId, $billId);
    return re_ap_money(max(0, $eligible - $linked));
}

function re_ap_load_advance_vat_doc(PDO $conn, int $companyId, int $docId): ?array
{
    $st = $conn->prepare("SELECT * FROM re_vendor_advance_vat_documents WHERE id = ? AND company_id = ? LIMIT 1");
    $st->execute([$docId, $companyId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function re_ap_save_advance_vat_draft(
    PDO $conn,
    int $companyId,
    array $data,
    ?int $userId = null,
    ?int $docId = null
): array {
    if ($companyId <= 0) {
        return ['success' => false, 'error' => 'Company context is required.', 'id' => null];
    }
    if (!re_ap_advance_vat_table_ready($conn)) {
        return ['success' => false, 'error' => 'Advance VAT schema not installed.', 'id' => null];
    }
    try {
        $vendorId = (int)($data['vendor_id'] ?? 0);
        $paymentId = (int)($data['vendor_payment_id'] ?? 0);
        $invNo = trim((string)($data['supplier_invoice_number'] ?? ''));
        $invDate = (string)($data['supplier_invoice_date'] ?? '');
        $trn = trim((string)($data['supplier_trn'] ?? ''));
        $taxable = re_ap_money($data['taxable_amount'] ?? 0);
        $vat = re_ap_money($data['vat_amount'] ?? 0);
        $gross = re_ap_money($data['gross_amount'] ?? ($taxable + $vat));
        $currency = strtoupper(substr(trim((string)($data['currency_code'] ?? 'AED')), 0, 3)) ?: 'AED';
        $notes = trim((string)($data['notes'] ?? ''));

        if ($vendorId <= 0 || $paymentId <= 0) {
            throw new RuntimeException('Vendor and advance payment are required.');
        }
        if ($invNo === '' || $invDate === '') {
            throw new RuntimeException('Supplier invoice number and date are required.');
        }
        if ($vat <= 0.005) {
            throw new RuntimeException('VAT amount must be greater than zero.');
        }
        if (abs(($taxable + $vat) - $gross) > 0.005) {
            throw new RuntimeException('Gross amount must equal taxable amount plus VAT.');
        }

        $pay = $conn->prepare("
            SELECT * FROM re_vendor_payments
            WHERE id = ? AND company_id = ? AND vendor_id = ? AND status = 'posted'
            LIMIT 1
        ");
        $pay->execute([$paymentId, $companyId, $vendorId]);
        $payment = $pay->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            throw new RuntimeException('Posted vendor advance payment not found for this vendor.');
        }
        if (re_ap_money($payment['advance_amount'] ?? 0) <= 0.005) {
            throw new RuntimeException('Selected payment has no advance portion.');
        }

        $dup = $conn->prepare("
            SELECT id FROM re_vendor_advance_vat_documents
            WHERE company_id = ? AND vendor_id = ? AND supplier_invoice_number = ?
              AND status <> 'reversed'
              AND (? = 0 OR id <> ?)
            LIMIT 1
        ");
        $dup->execute([$companyId, $vendorId, $invNo, $docId ?? 0, $docId ?? 0]);
        if ($dup->fetchColumn()) {
            throw new RuntimeException('A VAT document with this supplier invoice number already exists for the vendor.');
        }

        if ($docId) {
            $existing = re_ap_load_advance_vat_doc($conn, $companyId, $docId);
            if (!$existing) {
                throw new RuntimeException('VAT document not found.');
            }
            if (($existing['status'] ?? '') !== 'draft') {
                throw new RuntimeException('Only draft VAT documents can be edited.');
            }
            $conn->prepare("
                UPDATE re_vendor_advance_vat_documents SET
                    vendor_id = ?, vendor_payment_id = ?, supplier_invoice_number = ?,
                    supplier_invoice_date = ?, supplier_trn = ?, taxable_amount = ?,
                    vat_amount = ?, gross_amount = ?, currency_code = ?, notes = ?,
                    updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ")->execute([
                $vendorId, $paymentId, $invNo, $invDate, $trn !== '' ? $trn : null,
                $taxable, $vat, $gross, $currency, $notes !== '' ? $notes : null,
                $docId, $companyId,
            ]);
            return ['success' => true, 'error' => null, 'id' => $docId];
        }

        $conn->prepare("
            INSERT INTO re_vendor_advance_vat_documents
            (company_id, vendor_id, vendor_payment_id, supplier_invoice_number, supplier_invoice_date,
             supplier_trn, taxable_amount, vat_amount, gross_amount, currency_code, status, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,'draft',?,?)
        ")->execute([
            $companyId, $vendorId, $paymentId, $invNo, $invDate,
            $trn !== '' ? $trn : null, $taxable, $vat, $gross, $currency,
            $notes !== '' ? $notes : null, $userId,
        ]);
        return ['success' => true, 'error' => null, 'id' => (int)$conn->lastInsertId()];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage(), 'id' => null];
    }
}

function re_ap_post_advance_vat_document(PDO $conn, int $companyId, int $docId, ?int $userId = null): array
{
    if ($companyId <= 0) {
        return ['success' => false, 'error' => 'Company context is required.', 'journal_id' => null];
    }
    if (!re_ap_advance_vat_table_ready($conn)) {
        return ['success' => false, 'error' => 'Advance VAT schema not installed.', 'journal_id' => null];
    }
    $ownTx = !$conn->inTransaction();
    try {
        if ($ownTx) {
            $conn->beginTransaction();
        }
        $st = $conn->prepare("SELECT * FROM re_vendor_advance_vat_documents WHERE id = ? AND company_id = ? FOR UPDATE");
        $st->execute([$docId, $companyId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            throw new RuntimeException('VAT document not found.');
        }
        if (($doc['status'] ?? '') === 'posted' && !empty($doc['journal_id'])) {
            if ($ownTx) {
                $conn->commit();
            }
            return ['success' => true, 'error' => null, 'journal_id' => (int)$doc['journal_id'], 'already_posted' => true];
        }
        if (($doc['status'] ?? '') !== 'draft') {
            throw new RuntimeException('Only draft VAT documents can be posted.');
        }

        $vendorId = (int)$doc['vendor_id'];
        $paymentId = (int)$doc['vendor_payment_id'];
        $vat = re_ap_money($doc['vat_amount']);
        $taxable = re_ap_money($doc['taxable_amount']);
        $gross = re_ap_money($doc['gross_amount']);
        if ($vat <= 0.005) {
            throw new RuntimeException('VAT amount must be greater than zero.');
        }
        if (abs(($taxable + $vat) - $gross) > 0.005) {
            throw new RuntimeException('Gross amount must equal taxable plus VAT.');
        }

        $pay = $conn->prepare("SELECT * FROM re_vendor_payments WHERE id = ? AND company_id = ? AND vendor_id = ? FOR UPDATE");
        $pay->execute([$paymentId, $companyId, $vendorId]);
        $payment = $pay->fetch(PDO::FETCH_ASSOC);
        if (!$payment || ($payment['status'] ?? '') !== 'posted') {
            throw new RuntimeException('Source advance payment is not posted.');
        }

        re_ap_lock_vendor_advance_balance($conn, $companyId, $vendorId);
        $capacity = re_ap_payment_advance_net_remaining($conn, $companyId, $paymentId);
        // Draft is not yet in posted VAT sum; capacity already excludes other posted VAT + apps.
        if ($vat > $capacity + 0.005) {
            throw new RuntimeException('VAT amount exceeds remaining eligible advance on this payment (' . number_format($capacity, 2) . ').');
        }

        $adv = re_ap_advance_account($conn, $companyId);
        $vatAcc = re_ap_input_vat_account($conn, $companyId);
        if (!$adv || !$vatAcc) {
            throw new RuntimeException('Vendor Advances or Input VAT account not found.');
        }

        $desc = 'Advance VAT ' . $doc['supplier_invoice_number'] . ' / PAY-' . $paymentId;
        $lines = [
            ['account_id' => (int)$vatAcc['id'], 'debit' => $vat, 'credit' => 0, 'description' => $desc, 'reference' => $doc['supplier_invoice_number']],
            ['account_id' => (int)$adv['id'], 'debit' => 0, 'credit' => $vat, 'description' => $desc, 'reference' => $doc['supplier_invoice_number']],
        ];
        $res = create_and_post_journal(
            $companyId,
            'manual',
            'vendor_advance_vat_document',
            $docId,
            $lines,
            $desc,
            $doc['supplier_invoice_date'],
            $userId
        );
        if (empty($res['success'])) {
            throw new RuntimeException($res['error'] ?? 'Advance VAT journal failed');
        }
        $journalId = (int)$res['journal_id'];

        $advLedger = get_or_create_vendor_ledger($vendorId, (int)$adv['id'], $companyId);
        re_ap_post_to_vendor_ledger_required(
            $conn,
            (int)$advLedger['id'],
            (string)$doc['supplier_invoice_date'],
            0,
            $vat,
            $desc,
            $doc['supplier_invoice_number'],
            $companyId,
            $journalId,
            (int)$adv['id']
        );

        re_ap_adjust_vendor_advance_balance($conn, $companyId, $vendorId, -$vat);

        $conn->prepare("
            UPDATE re_vendor_advance_vat_documents
            SET status = 'posted', journal_id = ?, posted_by = ?, posted_at = NOW(), updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([$journalId, $userId, $docId, $companyId]);

        re_ap_audit(
            $conn,
            $companyId,
            $vendorId,
            null,
            $paymentId,
            'advance_vat_posted',
            null,
            (string)$docId,
            $vat,
            'Advance VAT document posted: ' . $doc['supplier_invoice_number'],
            $userId,
            'vendor_advance_vat',
            $journalId
        );

        if ($ownTx) {
            $conn->commit();
        }
        return ['success' => true, 'error' => null, 'journal_id' => $journalId];
    } catch (Throwable $e) {
        if ($ownTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage(), 'journal_id' => null];
    }
}

function re_ap_reverse_advance_vat_document(
    PDO $conn,
    int $companyId,
    int $docId,
    string $reason = '',
    ?int $userId = null
): array {
    if ($companyId <= 0) {
        return ['success' => false, 'error' => 'Company context is required.', 'reversal_journal_id' => null];
    }
    $ownTx = !$conn->inTransaction();
    try {
        if ($ownTx) {
            $conn->beginTransaction();
        }
        $st = $conn->prepare("SELECT * FROM re_vendor_advance_vat_documents WHERE id = ? AND company_id = ? FOR UPDATE");
        $st->execute([$docId, $companyId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            throw new RuntimeException('VAT document not found.');
        }
        if (($doc['status'] ?? '') === 'reversed') {
            if ($ownTx) {
                $conn->commit();
            }
            return ['success' => true, 'error' => null, 'reversal_journal_id' => (int)($doc['reversal_journal_id'] ?? 0) ?: null, 'already_reversed' => true];
        }
        if (($doc['status'] ?? '') !== 'posted') {
            throw new RuntimeException('Only posted VAT documents can be reversed.');
        }

        $linkCnt = $conn->prepare("
            SELECT COUNT(*) FROM re_vendor_advance_vat_bill_links
            WHERE company_id = ? AND advance_vat_document_id = ? AND status = 'posted'
        ");
        $linkCnt->execute([$companyId, $docId]);
        if ((int)$linkCnt->fetchColumn() > 0) {
            throw new RuntimeException('Unlink or reverse bill VAT links before reversing this advance VAT document.');
        }

        $vendorId = (int)$doc['vendor_id'];
        $vat = re_ap_money($doc['vat_amount']);
        $journalId = (int)($doc['journal_id'] ?? 0);
        if ($journalId <= 0) {
            throw new RuntimeException('VAT document has no journal to reverse.');
        }

        re_ap_lock_vendor_advance_balance($conn, $companyId, $vendorId);

        $rev = reverse_journal($journalId, $reason ?: 'Advance VAT document reversed', $userId);
        if (empty($rev['success'])) {
            throw new RuntimeException($rev['error'] ?? 'Journal reversal failed');
        }
        $reversalJournalId = (int)$rev['reversal_journal_id'];

        $adv = re_ap_advance_account($conn, $companyId);
        if (!$adv) {
            throw new RuntimeException('Vendor Advances account not found.');
        }
        $advLedger = get_or_create_vendor_ledger($vendorId, (int)$adv['id'], $companyId);
        re_ap_post_to_vendor_ledger_required(
            $conn,
            (int)$advLedger['id'],
            date('Y-m-d'),
            $vat,
            0,
            'Reverse advance VAT ' . $doc['supplier_invoice_number'],
            $doc['supplier_invoice_number'],
            $companyId,
            $reversalJournalId,
            (int)$adv['id']
        );

        re_ap_adjust_vendor_advance_balance($conn, $companyId, $vendorId, $vat);

        // Free unique supplier invoice number for valid re-entry after reverse/correction.
        $freedInv = $doc['supplier_invoice_number'] . '#REV-' . $docId;
        $conn->prepare("
            UPDATE re_vendor_advance_vat_documents
            SET status = 'reversed', supplier_invoice_number = ?, reversed_by = ?, reversed_at = NOW(),
                reversal_journal_id = ?, updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([$freedInv, $userId, $reversalJournalId, $docId, $companyId]);

        re_ap_audit(
            $conn,
            $companyId,
            $vendorId,
            null,
            (int)$doc['vendor_payment_id'],
            'advance_vat_reversed',
            (string)$journalId,
            (string)$reversalJournalId,
            $vat,
            $reason ?: 'Advance VAT document reversed',
            $userId,
            'vendor_advance_vat',
            $reversalJournalId
        );

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
 * Link (partial) advance VAT document to a final bill. Manual amounts.
 *
 * @param list<array{document_id:int,vat_amount:float,taxable_amount?:float}> $links
 */
function re_ap_link_advance_vat_to_bill(
    PDO $conn,
    int $companyId,
    int $billId,
    array $links,
    ?int $userId = null
): array {
    if ($companyId <= 0) {
        return ['success' => false, 'error' => 'Company context is required.'];
    }
    $ownTx = !$conn->inTransaction();
    try {
        if ($ownTx) {
            $conn->beginTransaction();
        }
        $bill = re_ap_load_bill($conn, $companyId, $billId);
        if (!$bill) {
            throw new RuntimeException('Bill not found.');
        }
        if (($bill['posting_status'] ?? '') === 'posted') {
            throw new RuntimeException('Cannot change VAT links on an already posted bill. Void/amend first.');
        }
        $vendorId = (int)$bill['vendor_id'];
        $billVat = re_ap_bill_eligible_vat($conn, $companyId, $billId);
        $billTaxable = 0.0;
        $tx = $conn->prepare("SELECT COALESCE(SUM(subtotal),0) FROM re_vendor_invoice_items WHERE company_id=? AND invoice_id=?");
        $tx->execute([$companyId, $billId]);
        $billTaxable = re_ap_money($tx->fetchColumn());

        $existingLinked = re_ap_advance_vat_linked_to_bill($conn, $companyId, $billId);
        $newVatTotal = 0.0;
        $newTaxTotal = 0.0;

        $ins = $conn->prepare("
            INSERT INTO re_vendor_advance_vat_bill_links
            (company_id, advance_vat_document_id, vendor_invoice_id, vat_amount_linked, taxable_amount_linked, status, created_by)
            VALUES (?,?,?,?,?,'posted',?)
        ");

        foreach ($links as $lnk) {
            $docId = (int)($lnk['document_id'] ?? 0);
            $vatAmt = re_ap_money($lnk['vat_amount'] ?? 0);
            $taxAmt = re_ap_money($lnk['taxable_amount'] ?? 0);
            if ($docId <= 0 || $vatAmt <= 0.005) {
                continue;
            }
            $docSt = $conn->prepare("SELECT * FROM re_vendor_advance_vat_documents WHERE id = ? AND company_id = ? FOR UPDATE");
            $docSt->execute([$docId, $companyId]);
            $doc = $docSt->fetch(PDO::FETCH_ASSOC);
            if (!$doc || ($doc['status'] ?? '') !== 'posted') {
                throw new RuntimeException('VAT document #' . $docId . ' is not posted.');
            }
            if ((int)$doc['vendor_id'] !== $vendorId) {
                throw new RuntimeException('VAT document vendor does not match the bill.');
            }
            $rem = re_ap_advance_vat_doc_remaining($conn, $companyId, $docId);
            if ($vatAmt > $rem + 0.005) {
                throw new RuntimeException('Link VAT exceeds remaining on document #' . $docId . '.');
            }
            if ($taxAmt < 0) {
                $taxAmt = 0.0;
            }
            $ins->execute([$companyId, $docId, $billId, $vatAmt, $taxAmt, $userId]);
            $newVatTotal = re_ap_money($newVatTotal + $vatAmt);
            $newTaxTotal = re_ap_money($newTaxTotal + $taxAmt);
        }

        $totalLinked = re_ap_money($existingLinked + $newVatTotal);
        if ($totalLinked > $billVat + 0.005) {
            throw new RuntimeException('Linked advance VAT exceeds bill eligible VAT.');
        }
        if ($newTaxTotal > $billTaxable + 0.005 && $billTaxable > 0.005) {
            throw new RuntimeException('Linked taxable amount exceeds bill taxable amount.');
        }

        re_ap_audit(
            $conn,
            $companyId,
            $vendorId,
            $billId,
            null,
            'advance_vat_linked_bill',
            null,
            (string)json_encode($links),
            $newVatTotal,
            'Advance VAT linked to bill',
            $userId,
            'vendor_advance_vat'
        );

        if ($ownTx) {
            $conn->commit();
        }
        return ['success' => true, 'error' => null, 'linked_vat' => $totalLinked, 'remaining_bill_vat' => re_ap_money(max(0, $billVat - $totalLinked))];
    } catch (Throwable $e) {
        if ($ownTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function re_ap_unlink_advance_vat_from_bill(
    PDO $conn,
    int $companyId,
    int $linkId,
    ?int $userId = null
): array {
    if ($companyId <= 0) {
        return ['success' => false, 'error' => 'Company context is required.'];
    }
    $ownTx = !$conn->inTransaction();
    try {
        if ($ownTx) {
            $conn->beginTransaction();
        }
        $st = $conn->prepare("SELECT * FROM re_vendor_advance_vat_bill_links WHERE id = ? AND company_id = ? FOR UPDATE");
        $st->execute([$linkId, $companyId]);
        $link = $st->fetch(PDO::FETCH_ASSOC);
        if (!$link) {
            throw new RuntimeException('VAT link not found.');
        }
        if (($link['status'] ?? '') === 'reversed') {
            if ($ownTx) {
                $conn->commit();
            }
            return ['success' => true, 'error' => null, 'already_reversed' => true];
        }
        $billId = (int)$link['vendor_invoice_id'];
        $bill = re_ap_load_bill($conn, $companyId, $billId);
        if ($bill && ($bill['posting_status'] ?? '') === 'posted') {
            throw new RuntimeException('Cannot unlink VAT from a posted bill. Void/amend the bill first.');
        }
        $conn->prepare("
            UPDATE re_vendor_advance_vat_bill_links
            SET status = 'reversed', reversed_by = ?, reversed_at = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([$userId, $linkId, $companyId]);

        re_ap_audit(
            $conn,
            $companyId,
            $bill ? (int)$bill['vendor_id'] : null,
            $billId,
            null,
            'advance_vat_unlinked_bill',
            (string)$linkId,
            null,
            re_ap_money($link['vat_amount_linked']),
            'Advance VAT unlinked from bill',
            $userId,
            'vendor_advance_vat'
        );

        if ($ownTx) {
            $conn->commit();
        }
        return ['success' => true, 'error' => null];
    } catch (Throwable $e) {
        if ($ownTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/** Reverse all posted VAT links on a bill (for void/amend). */
function re_ap_reverse_advance_vat_links_on_bill(PDO $conn, int $companyId, int $billId, ?int $userId = null): void
{
    if (!re_ap_advance_vat_table_ready($conn)) {
        return;
    }
    $st = $conn->prepare("
        SELECT id FROM re_vendor_advance_vat_bill_links
        WHERE company_id = ? AND vendor_invoice_id = ? AND status = 'posted'
        FOR UPDATE
    ");
    $st->execute([$companyId, $billId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $linkId) {
        $conn->prepare("
            UPDATE re_vendor_advance_vat_bill_links
            SET status = 'reversed', reversed_by = ?, reversed_at = NOW()
            WHERE id = ? AND company_id = ? AND status = 'posted'
        ")->execute([$userId, (int)$linkId, $companyId]);
    }
}

/**
 * Eligible posted advance VAT documents for a vendor (remaining VAT > 0).
 * @return list<array<string,mixed>>
 */
function re_ap_eligible_advance_vat_docs_for_vendor(PDO $conn, int $companyId, int $vendorId): array
{
    if (!re_ap_advance_vat_table_ready($conn) || $companyId <= 0 || $vendorId <= 0) {
        return [];
    }
    $st = $conn->prepare("
        SELECT d.*,
               COALESCE((
                   SELECT SUM(l.vat_amount_linked)
                   FROM re_vendor_advance_vat_bill_links l
                   WHERE l.company_id = d.company_id
                     AND l.advance_vat_document_id = d.id
                     AND l.status = 'posted'
               ), 0) AS vat_consumed
        FROM re_vendor_advance_vat_documents d
        WHERE d.company_id = ? AND d.vendor_id = ? AND d.status = 'posted'
        ORDER BY d.supplier_invoice_date ASC, d.id ASC
    ");
    $st->execute([$companyId, $vendorId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $rem = re_ap_money((float)$r['vat_amount'] - (float)$r['vat_consumed']);
        if ($rem <= 0.005) {
            continue;
        }
        $r['vat_remaining'] = $rem;
        $out[] = $r;
    }
    return $out;
}

function re_ap_advance_vat_attachments(PDO $conn, int $companyId, int $docId): array
{
    if (!re_ap_advance_vat_table_ready($conn)) {
        return [];
    }
    try {
        $st = $conn->prepare("
            SELECT * FROM re_vendor_advance_vat_attachments
            WHERE company_id = ? AND advance_vat_document_id = ?
            ORDER BY uploaded_at DESC, id DESC
        ");
        $st->execute([$companyId, $docId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Store uploaded attachments for an advance VAT document.
 * @param array $files $_FILES['attachments'] style multi-upload
 */
function re_ap_store_advance_vat_attachments(
    PDO $conn,
    int $companyId,
    int $docId,
    array $files,
    ?int $userId = null
): array {
    if ($companyId <= 0 || $docId <= 0) {
        return ['success' => false, 'error' => 'Invalid document context.', 'count' => 0];
    }
    $doc = re_ap_load_advance_vat_doc($conn, $companyId, $docId);
    if (!$doc) {
        return ['success' => false, 'error' => 'VAT document not found.', 'count' => 0];
    }

    // modules/realestate/includes → project root /uploads/vendor_advance_vat
    $dir = dirname(__DIR__, 3) . '/uploads/vendor_advance_vat';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['success' => false, 'error' => 'Could not create upload directory.', 'count' => 0];
        }
    }
    @chmod($dir, 0775);
    if (!is_writable($dir)) {
        @chmod($dir, 0777);
    }
    if (!is_writable($dir)) {
        return [
            'success' => false,
            'error' => 'Upload folder is not writable by the web server. Fix permissions on uploads/vendor_advance_vat.',
            'count' => 0,
        ];
    }

    $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'webp'];
    $count = 0;
    $lastError = null;
    $names = $files['name'] ?? [];
    if (!is_array($names)) {
        $names = [$names];
        $files = [
            'name' => $names,
            'type' => [$files['type'] ?? ''],
            'tmp_name' => [$files['tmp_name'] ?? ''],
            'error' => [$files['error'] ?? UPLOAD_ERR_NO_FILE],
            'size' => [$files['size'] ?? 0],
        ];
    }
    foreach ($names as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $lastError = 'File type not allowed. Use PDF, PNG, JPG, or WEBP.';
            continue;
        }
        $tmp = (string)($files['tmp_name'][$i] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $lastError = 'Invalid upload temp file.';
            continue;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: ($files['type'][$i] ?? null);
        $allowedMime = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'];
        if ($mime && !in_array($mime, $allowedMime, true)) {
            $lastError = 'File MIME type not allowed.';
            continue;
        }
        $safe = 'adv_vat_' . $docId . '_' . time() . '_' . $i . '.' . $ext;
        $path = $dir . '/' . $safe;
        if (!@move_uploaded_file($tmp, $path)) {
            $lastError = 'Could not save uploaded file (permission denied or disk error).';
            continue;
        }
        @chmod($path, 0644);
        $rel = 'uploads/vendor_advance_vat/' . $safe;
        $conn->prepare("
            INSERT INTO re_vendor_advance_vat_attachments
            (company_id, advance_vat_document_id, file_name, file_path, mime_type, file_size, uploaded_by)
            VALUES (?,?,?,?,?,?,?)
        ")->execute([
            $companyId,
            $docId,
            $name,
            $rel,
            $mime,
            (int)($files['size'][$i] ?? 0),
            $userId,
        ]);
        $count++;
    }
    if ($count <= 0) {
        return ['success' => false, 'error' => $lastError ?: 'No valid attachments uploaded.', 'count' => 0];
    }
    return ['success' => true, 'error' => null, 'count' => $count];
}
