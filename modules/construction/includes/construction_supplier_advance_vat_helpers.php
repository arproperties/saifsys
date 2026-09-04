<?php
/**
 * Construction Supplier Advance VAT Documents (Phase 3).
 * Document-driven Input VAT: Dr 2130 / Cr configurable Advances.
 * Links to draft invoices; invoice post uses remaining Input VAT only.
 */

if (!function_exists('co_supplier_advance_schema_ready')) {
    require_once __DIR__ . '/construction_supplier_advance_helpers.php';
}

function co_supplier_advance_vat_table_ready(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $ready = function_exists('co_db_table_exists')
        && co_db_table_exists($conn, 'co_supplier_advance_vat_documents')
        && co_db_table_exists($conn, 'co_supplier_advance_vat_invoice_links');
    return $ready;
}

function co_supplier_payment_advance_vat_posted(PDO $conn, int $companyId, int $paymentId): float {
    if (!co_supplier_advance_vat_table_ready($conn)) {
        return 0.0;
    }
    $st = $conn->prepare("
        SELECT COALESCE(SUM(vat_amount), 0)
        FROM co_supplier_advance_vat_documents
        WHERE company_id = ? AND supplier_payment_id = ? AND status = 'posted'
    ");
    $st->execute([$companyId, $paymentId]);
    return co_supplier_money($st->fetchColumn());
}

function co_supplier_advance_vat_linked_to_invoice(PDO $conn, int $companyId, int $invoiceId): float {
    if (!co_supplier_advance_vat_table_ready($conn)) {
        return 0.0;
    }
    $st = $conn->prepare("
        SELECT COALESCE(SUM(vat_amount_linked), 0)
        FROM co_supplier_advance_vat_invoice_links
        WHERE company_id = ? AND supplier_invoice_id = ? AND status = 'posted'
    ");
    $st->execute([$companyId, $invoiceId]);
    return co_supplier_money($st->fetchColumn());
}

function co_supplier_invoice_remaining_input_vat(PDO $conn, int $companyId, int $invoiceId): float {
    $inv = function_exists('co_supplier_invoice_load')
        ? co_supplier_invoice_load($conn, $companyId, $invoiceId)
        : null;
    if (!$inv) {
        return 0.0;
    }
    $eligible = co_supplier_money($inv['vat_amount'] ?? 0);
    $linked = co_supplier_advance_vat_linked_to_invoice($conn, $companyId, $invoiceId);
    return co_supplier_money(max(0, $eligible - $linked));
}

function co_supplier_advance_vat_doc_consumed(PDO $conn, int $companyId, int $docId): float {
    if (!co_supplier_advance_vat_table_ready($conn)) {
        return 0.0;
    }
    $st = $conn->prepare("
        SELECT COALESCE(SUM(vat_amount_linked), 0)
        FROM co_supplier_advance_vat_invoice_links
        WHERE company_id = ? AND advance_vat_document_id = ? AND status = 'posted'
    ");
    $st->execute([$companyId, $docId]);
    return co_supplier_money($st->fetchColumn());
}

function co_supplier_advance_vat_doc_remaining(PDO $conn, int $companyId, int $docId): float {
    $doc = co_supplier_load_advance_vat_doc($conn, $companyId, $docId);
    if (!$doc || ($doc['status'] ?? '') !== 'posted') {
        return 0.0;
    }
    return co_supplier_money(max(0, (float)$doc['vat_amount'] - co_supplier_advance_vat_doc_consumed($conn, $companyId, $docId)));
}

function co_supplier_load_advance_vat_doc(PDO $conn, int $companyId, int $docId): ?array {
    if (!co_supplier_advance_vat_table_ready($conn)) {
        return null;
    }
    $st = $conn->prepare("SELECT * FROM co_supplier_advance_vat_documents WHERE id = ? AND company_id = ? LIMIT 1");
    $st->execute([$docId, $companyId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * @return array{success:bool,error:?string,id:?int}
 */
function co_supplier_save_advance_vat_draft(PDO $conn, int $companyId, array $data, ?int $userId = null, ?int $docId = null): array {
    if ($companyId <= 0 || !co_supplier_advance_vat_table_ready($conn)) {
        return ['success' => false, 'error' => 'Advance VAT schema not ready.', 'id' => null];
    }
    try {
        $paymentId = (int)($data['supplier_payment_id'] ?? 0);
        $invNo = trim((string)($data['supplier_invoice_number'] ?? ''));
        $invDate = (string)($data['supplier_invoice_date'] ?? date('Y-m-d'));
        $trn = trim((string)($data['supplier_trn'] ?? ''));
        $taxable = co_supplier_money($data['taxable_amount'] ?? 0);
        $vat = co_supplier_money($data['vat_amount'] ?? 0);
        $gross = co_supplier_money($data['gross_amount'] ?? ($taxable + $vat));
        $notes = trim((string)($data['notes'] ?? ''));
        if ($paymentId <= 0 || $invNo === '' || $vat <= 0.005) {
            throw new RuntimeException('Payment, supplier tax invoice number, and VAT amount are required.');
        }
        if (abs(($taxable + $vat) - $gross) > 0.005) {
            $gross = co_supplier_money($taxable + $vat);
        }
        $pay = $conn->prepare("SELECT * FROM co_supplier_payments WHERE id = ? AND company_id = ?");
        $pay->execute([$paymentId, $companyId]);
        $payment = $pay->fetch(PDO::FETCH_ASSOC);
        if (!$payment || empty($payment['journal_id'])) {
            throw new RuntimeException('Source advance payment must be posted.');
        }
        if (co_supplier_money($payment['advance_amount'] ?? 0) <= 0.005) {
            throw new RuntimeException('Selected payment has no advance portion.');
        }
        $supplierId = (int)$payment['supplier_id'];

        if ($docId) {
            $existing = co_supplier_load_advance_vat_doc($conn, $companyId, $docId);
            if (!$existing || ($existing['status'] ?? '') !== 'draft') {
                throw new RuntimeException('Only draft VAT documents can be edited.');
            }
            $conn->prepare("
                UPDATE co_supplier_advance_vat_documents
                SET supplier_payment_id = ?, supplier_invoice_number = ?, supplier_invoice_date = ?,
                    supplier_trn = ?, taxable_amount = ?, vat_amount = ?, gross_amount = ?, notes = ?, updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ")->execute([
                $paymentId, $invNo, $invDate, $trn !== '' ? $trn : null,
                $taxable, $vat, $gross, $notes !== '' ? $notes : null, $docId, $companyId,
            ]);
            return ['success' => true, 'error' => null, 'id' => $docId];
        }

        $conn->prepare("
            INSERT INTO co_supplier_advance_vat_documents
                (company_id, supplier_id, supplier_payment_id, supplier_invoice_number, supplier_invoice_date,
                 supplier_trn, taxable_amount, vat_amount, gross_amount, currency_code, status, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,'AED','draft',?,?)
        ")->execute([
            $companyId, $supplierId, $paymentId, $invNo, $invDate,
            $trn !== '' ? $trn : null, $taxable, $vat, $gross,
            $notes !== '' ? $notes : null, $userId,
        ]);
        return ['success' => true, 'error' => null, 'id' => (int)$conn->lastInsertId()];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage(), 'id' => null];
    }
}

/**
 * @return array{success:bool,error:?string,journal_id:?int}
 */
function co_supplier_post_advance_vat_document(PDO $conn, int $companyId, int $docId, ?int $userId = null): array {
    if ($companyId <= 0) {
        return ['success' => false, 'error' => 'Company context is required.', 'journal_id' => null];
    }
    if (!co_supplier_advance_vat_table_ready($conn)) {
        return ['success' => false, 'error' => 'Run migrations/construction_supplier_ap_phase3_advance_vat_refunds.sql', 'journal_id' => null];
    }
    if (!function_exists('create_and_post_journal')) {
        require_once __DIR__ . '/construction_accounting_integration.php';
    }
    try {
        $doc = co_supplier_load_advance_vat_doc($conn, $companyId, $docId);
        if (!$doc) {
            throw new RuntimeException('VAT document not found.');
        }
        if (($doc['status'] ?? '') === 'posted' && !empty($doc['journal_id'])) {
            return ['success' => true, 'error' => null, 'journal_id' => (int)$doc['journal_id'], 'already_posted' => true];
        }
        if (($doc['status'] ?? '') !== 'draft') {
            throw new RuntimeException('Only draft VAT documents can be posted.');
        }
        $vat = co_supplier_money($doc['vat_amount']);
        if ($vat <= 0.005) {
            throw new RuntimeException('VAT amount must be greater than zero.');
        }
        $paymentId = (int)$doc['supplier_payment_id'];
        $supplierId = (int)$doc['supplier_id'];
        $capacity = co_supplier_payment_advance_remaining($conn, $companyId, $paymentId);
        if ($vat > $capacity + 0.005) {
            throw new RuntimeException('VAT exceeds remaining advance on this payment (' . number_format($capacity, 2) . ' AED).');
        }
        $adv = co_supplier_advance_account($conn, $companyId);
        $vatAcc = find_account_by_code(CO_ACCOUNT_INPUT_VAT, $companyId);
        if (!$adv || !$vatAcc) {
            throw new RuntimeException('Supplier Advances or Input VAT (2130) account not found.');
        }
        $desc = 'Advance VAT ' . $doc['supplier_invoice_number'] . ' / PAY-' . $paymentId;
        $lines = [
            ['account_id' => (int)$vatAcc['id'], 'debit' => $vat, 'credit' => 0, 'description' => $desc, 'reference' => $doc['supplier_invoice_number']],
            ['account_id' => (int)$adv['id'], 'debit' => 0, 'credit' => $vat, 'description' => $desc, 'reference' => $doc['supplier_invoice_number']],
        ];
        $res = create_and_post_journal(
            $companyId,
            'manual',
            'co_supplier_advance_vat_document',
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
        co_supplier_adjust_advance_balance($conn, $companyId, $supplierId, -$vat);
        $conn->prepare("
            UPDATE co_supplier_advance_vat_documents
            SET status = 'posted', journal_id = ?, posted_by = ?, posted_at = NOW(), updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([$journalId, $userId, $docId, $companyId]);
        if (function_exists('co_supplier_ap_audit')) {
            co_supplier_ap_audit(
                $conn, $companyId, $supplierId, null, $paymentId,
                'advance_vat_posted', null, (string)$doc['supplier_invoice_number'], $vat,
                'Supplier advance VAT posted', $userId, 'supplier_advance_vat', $journalId
            );
        }
        return ['success' => true, 'error' => null, 'journal_id' => $journalId];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage(), 'journal_id' => null];
    }
}

/**
 * @return array{success:bool,error:?string,reversal_journal_id:?int}
 */
function co_supplier_reverse_advance_vat_document(PDO $conn, int $companyId, int $docId, string $reason = '', ?int $userId = null): array {
    if ($companyId <= 0 || !co_supplier_advance_vat_table_ready($conn)) {
        return ['success' => false, 'error' => 'Advance VAT not available.', 'reversal_journal_id' => null];
    }
    if (!function_exists('reverse_journal')) {
        require_once __DIR__ . '/construction_accounting_integration.php';
    }
    try {
        $doc = co_supplier_load_advance_vat_doc($conn, $companyId, $docId);
        if (!$doc) {
            throw new RuntimeException('VAT document not found.');
        }
        if (($doc['status'] ?? '') === 'reversed') {
            return ['success' => true, 'error' => null, 'reversal_journal_id' => null, 'already_reversed' => true];
        }
        if (($doc['status'] ?? '') !== 'posted') {
            throw new RuntimeException('Only posted VAT documents can be reversed.');
        }
        $links = $conn->prepare("
            SELECT COUNT(*) FROM co_supplier_advance_vat_invoice_links
            WHERE company_id = ? AND advance_vat_document_id = ? AND status = 'posted'
        ");
        $links->execute([$companyId, $docId]);
        if ((int)$links->fetchColumn() > 0) {
            throw new RuntimeException('Unlink this VAT document from all supplier invoices before reversing.');
        }
        $journalId = (int)($doc['journal_id'] ?? 0);
        $reversalId = null;
        if ($journalId > 0) {
            $rev = reverse_journal($journalId, $reason !== '' ? $reason : 'Advance VAT reversed', $userId);
            if (empty($rev['success'])) {
                throw new RuntimeException($rev['error'] ?? 'VAT journal reversal failed');
            }
            $reversalId = isset($rev['reversal_journal_id']) ? (int)$rev['reversal_journal_id'] : null;
        }
        $vat = co_supplier_money($doc['vat_amount']);
        co_supplier_adjust_advance_balance($conn, $companyId, (int)$doc['supplier_id'], $vat);
        $newNumber = (string)$doc['supplier_invoice_number'] . '#REV-' . $docId;
        $conn->prepare("
            UPDATE co_supplier_advance_vat_documents
            SET status = 'reversed', reversed_by = ?, reversed_at = NOW(),
                reversal_journal_id = ?, supplier_invoice_number = ?, updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([$userId, $reversalId, $newNumber, $docId, $companyId]);
        if (function_exists('co_supplier_ap_audit')) {
            co_supplier_ap_audit(
                $conn, $companyId, (int)$doc['supplier_id'], null, (int)$doc['supplier_payment_id'],
                'advance_vat_reversed', (string)$journalId, (string)($reversalId ?? ''), $vat,
                $reason !== '' ? $reason : 'Advance VAT reversed', $userId, 'supplier_advance_vat', $reversalId
            );
        }
        return ['success' => true, 'error' => null, 'reversal_journal_id' => $reversalId];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage(), 'reversal_journal_id' => null];
    }
}

/**
 * Link posted advance VAT to a draft invoice (before GL post).
 * @param list<array{document_id:int,vat_amount:float,taxable_amount?:float}> $links
 */
function co_supplier_link_advance_vat_to_invoice(
    PDO $conn,
    int $companyId,
    int $invoiceId,
    array $links,
    ?int $userId = null
): array {
    if ($companyId <= 0 || !co_supplier_advance_vat_table_ready($conn)) {
        return ['success' => false, 'error' => 'Advance VAT not available.'];
    }
    try {
        $inv = co_supplier_invoice_load($conn, $companyId, $invoiceId);
        if (!$inv) {
            throw new RuntimeException('Invoice not found.');
        }
        if (!empty($inv['journal_id']) || (function_exists('co_supplier_invoice_status') && co_supplier_invoice_status($inv) !== 'draft')) {
            throw new RuntimeException('Link advance VAT only on draft invoices before posting.');
        }
        $supplierId = (int)$inv['supplier_id'];
        $billVat = co_supplier_money($inv['vat_amount'] ?? 0);
        $existing = co_supplier_advance_vat_linked_to_invoice($conn, $companyId, $invoiceId);
        $validated = [];
        $newVat = 0.0;
        foreach ($links as $lnk) {
            $docId = (int)($lnk['document_id'] ?? 0);
            $vatAmt = co_supplier_money($lnk['vat_amount'] ?? 0);
            $taxAmt = co_supplier_money($lnk['taxable_amount'] ?? 0);
            if ($docId <= 0 || $vatAmt <= 0.005) {
                continue;
            }
            $doc = co_supplier_load_advance_vat_doc($conn, $companyId, $docId);
            if (!$doc || ($doc['status'] ?? '') !== 'posted') {
                throw new RuntimeException('VAT document #' . $docId . ' is not posted.');
            }
            if ((int)$doc['supplier_id'] !== $supplierId) {
                throw new RuntimeException('VAT document supplier does not match the invoice.');
            }
            $rem = co_supplier_advance_vat_doc_remaining($conn, $companyId, $docId);
            if ($vatAmt > $rem + 0.005) {
                throw new RuntimeException('Link VAT exceeds remaining on document #' . $docId . '.');
            }
            $validated[] = [$docId, $vatAmt, max(0, $taxAmt)];
            $newVat = co_supplier_money($newVat + $vatAmt);
        }
        $totalLinked = co_supplier_money($existing + $newVat);
        if ($totalLinked > $billVat + 0.005) {
            throw new RuntimeException('Linked advance VAT exceeds invoice VAT.');
        }
        if (!$validated) {
            throw new RuntimeException('No valid advance VAT amounts to link.');
        }
        $ins = $conn->prepare("
            INSERT INTO co_supplier_advance_vat_invoice_links
                (company_id, advance_vat_document_id, supplier_invoice_id, vat_amount_linked, taxable_amount_linked, status, created_by)
            VALUES (?,?,?,?,?,'posted',?)
        ");
        foreach ($validated as [$docId, $vatAmt, $taxAmt]) {
            $ins->execute([$companyId, $docId, $invoiceId, $vatAmt, $taxAmt, $userId]);
        }
        if (function_exists('co_supplier_ap_audit')) {
            co_supplier_ap_audit(
                $conn, $companyId, $supplierId, $invoiceId, null,
                'advance_vat_linked_invoice', null, (string)json_encode($links), $newVat,
                'Advance VAT linked to draft invoice', $userId, 'supplier_advance_vat', null
            );
        }
        return [
            'success' => true,
            'error' => null,
            'linked_vat' => $totalLinked,
            'remaining_invoice_vat' => co_supplier_money(max(0, $billVat - $totalLinked)),
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function co_supplier_unlink_advance_vat_from_invoice(
    PDO $conn,
    int $companyId,
    int $linkId,
    ?int $userId = null
): array {
    if ($companyId <= 0 || !co_supplier_advance_vat_table_ready($conn)) {
        return ['success' => false, 'error' => 'Advance VAT not available.'];
    }
    try {
        $st = $conn->prepare("SELECT * FROM co_supplier_advance_vat_invoice_links WHERE id = ? AND company_id = ?");
        $st->execute([$linkId, $companyId]);
        $link = $st->fetch(PDO::FETCH_ASSOC);
        if (!$link) {
            throw new RuntimeException('VAT link not found.');
        }
        if (($link['status'] ?? '') === 'reversed') {
            return ['success' => true, 'error' => null, 'already_reversed' => true];
        }
        $inv = co_supplier_invoice_load($conn, $companyId, (int)$link['supplier_invoice_id']);
        if ($inv && !empty($inv['journal_id'])) {
            throw new RuntimeException('Cannot unlink advance VAT from a posted invoice. Void/amend first.');
        }
        $conn->prepare("
            UPDATE co_supplier_advance_vat_invoice_links
            SET status = 'reversed', reversed_by = ?, reversed_at = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([$userId, $linkId, $companyId]);
        return ['success' => true, 'error' => null];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/** Reverse all posted VAT links on invoice (e.g. before void). */
function co_supplier_reverse_advance_vat_links_on_invoice(
    PDO $conn,
    int $companyId,
    int $invoiceId,
    ?int $userId = null
): void {
    if (!co_supplier_advance_vat_table_ready($conn)) {
        return;
    }
    $conn->prepare("
        UPDATE co_supplier_advance_vat_invoice_links
        SET status = 'reversed', reversed_by = ?, reversed_at = NOW()
        WHERE company_id = ? AND supplier_invoice_id = ? AND status = 'posted'
    ")->execute([$userId, $companyId, $invoiceId]);
}

/**
 * Eligible posted VAT docs for a supplier with remaining linkable VAT.
 * @return list<array>
 */
function co_supplier_eligible_advance_vat_docs(PDO $conn, int $companyId, int $supplierId): array {
    if (!co_supplier_advance_vat_table_ready($conn)) {
        return [];
    }
    $st = $conn->prepare("
        SELECT d.*
        FROM co_supplier_advance_vat_documents d
        WHERE d.company_id = ? AND d.supplier_id = ? AND d.status = 'posted'
        ORDER BY d.supplier_invoice_date ASC, d.id ASC
    ");
    $st->execute([$companyId, $supplierId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $doc) {
        $rem = co_supplier_advance_vat_doc_remaining($conn, $companyId, (int)$doc['id']);
        if ($rem > 0.005) {
            $doc['remaining_vat'] = $rem;
            $out[] = $doc;
        }
    }
    return $out;
}
