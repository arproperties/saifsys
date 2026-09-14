<?php
/**
 * Manual correction of an issued Invoice Mode invoice.
 *
 * Amount changes reverse the original recognition journal and repost it at the
 * new figures (same invoice date). If the invoice was already paid beyond the
 * new total, the excess moves from the invoice to tenant credit:
 * receipt allocations shift invoice → tenant_credit, and Dr AR / Cr 2410.
 * Text-only changes (item name, description, notes) touch no accounting.
 */
declare(strict_types=1);

require_once __DIR__ . '/payment_allocation_helper.php';
require_once __DIR__ . '/../accounting/accounting_integration.php';

if (!function_exists('re_invoice_edit_money')) {
    function re_invoice_edit_money($value): float
    {
        return round((float)$value, 2);
    }
}

if (!function_exists('re_invoice_edit_load')) {
    /**
     * @return array{invoice:?array<string,mixed>,items:list<array<string,mixed>>}
     */
    function re_invoice_edit_load(PDO $conn, int $companyId, int $invoiceId, bool $forUpdate = false): array
    {
        $lock = $forUpdate ? 'FOR UPDATE' : '';
        $stmt = $conn->prepare("
            SELECT i.*, l.tenant_id, l.lease_number, COALESCE(l.accounting_mode, 'legacy') AS accounting_mode,
                   t.first_name, t.last_name, u.unit_number, b.name AS building_name
            FROM re_invoices i
            JOIN re_leases l ON l.id = i.lease_id AND l.company_id = i.company_id
            JOIN re_tenants t ON t.id = l.tenant_id
            JOIN re_units u ON u.id = l.unit_id
            JOIN re_buildings b ON b.id = u.building_id
            WHERE i.id = ? AND i.company_id = ?
            LIMIT 1
            {$lock}
        ");
        $stmt->execute([$invoiceId, $companyId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $items = [];
        if ($invoice) {
            $itemStmt = $conn->prepare("
                SELECT * FROM re_invoice_items
                WHERE invoice_id = ? AND company_id = ?
                ORDER BY display_order, id
                {$lock}
            ");
            $itemStmt->execute([$invoiceId, $companyId]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        return ['invoice' => $invoice, 'items' => $items];
    }
}

if (!function_exists('re_invoice_edit_recognition_journal_id')) {
    function re_invoice_edit_recognition_journal_id(PDO $conn, int $companyId, int $invoiceId): int
    {
        $stmt = $conn->prepare("
            SELECT id
            FROM re_journal_headers
            WHERE company_id = ?
              AND journal_type = 'invoice'
              AND reference_type = 'invoice'
              AND reference_id = ?
              AND is_posted = 1
              AND is_reversed = 0
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$companyId, $invoiceId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }
}

if (!function_exists('re_invoice_edit_ar_line')) {
    /**
     * @return array{id:int,amount:float}
     */
    function re_invoice_edit_ar_line(PDO $conn, int $journalId, int $arAccountId, string $side): array
    {
        $column = $side === 'debit' ? 'debit_amount' : 'credit_amount';
        $stmt = $conn->prepare("
            SELECT id, {$column} AS amount
            FROM re_journal_lines
            WHERE journal_id = ? AND account_id = ? AND {$column} > 0
            ORDER BY id
            LIMIT 1
        ");
        $stmt->execute([$journalId, $arAccountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Receivable line not found on journal #' . $journalId . '.');
        }
        return ['id' => (int)$row['id'], 'amount' => re_invoice_edit_money($row['amount'])];
    }
}

if (!function_exists('re_invoice_edit_blocker')) {
    /**
     * Reason the invoice cannot be edited at all, or null.
     */
    function re_invoice_edit_blocker(PDO $conn, int $companyId, array $invoice, array $items): ?string
    {
        if ((string)$invoice['status'] === 'cancelled') {
            return 'This invoice is cancelled and cannot be edited.';
        }
        if ((string)$invoice['accounting_mode'] !== 'invoice') {
            return 'Only Invoice Mode invoices can be edited here.';
        }
        if (!$items) {
            return 'This invoice has no line items.';
        }
        if (re_invoice_edit_recognition_journal_id($conn, $companyId, (int)$invoice['id']) <= 0) {
            return 'This invoice has no posted accounting entry, so it cannot be corrected safely.';
        }
        if (function_exists('is_period_locked') && is_period_locked($companyId, (string)$invoice['invoice_date'])) {
            return 'The accounting period for ' . (string)$invoice['invoice_date'] . ' is closed.';
        }
        return null;
    }
}

if (!function_exists('re_invoice_edit_apply')) {
    /**
     * @param array<int,array<string,mixed>> $postedLines keyed by re_invoice_items.id:
     *        item_name, item_description, quantity, unit_price, tax_rate, tax_amount
     * @return array<string,mixed>
     */
    function re_invoice_edit_apply(PDO $conn, int $companyId, int $invoiceId, array $postedLines, string $notes, string $reason, ?int $userId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'error' => 'Please enter the reason for the correction.'];
        }

        $startedHere = !$conn->inTransaction();
        if ($startedHere) {
            $conn->beginTransaction();
        }

        try {
            $loaded = re_invoice_edit_load($conn, $companyId, $invoiceId, true);
            $invoice = $loaded['invoice'];
            $items = $loaded['items'];
            if (!$invoice) {
                throw new RuntimeException('Invoice not found.');
            }
            $blocker = re_invoice_edit_blocker($conn, $companyId, $invoice, $items);
            if ($blocker !== null) {
                throw new RuntimeException($blocker);
            }

            $newLines = [];
            $subtotal = 0.0;
            $taxTotal = 0.0;
            $amountsChanged = false;
            $textChanged = trim((string)($invoice['notes'] ?? '')) !== trim($notes);

            foreach ($items as $item) {
                $itemId = (int)$item['id'];
                $posted = $postedLines[$itemId] ?? null;
                if (!is_array($posted)) {
                    throw new RuntimeException('Line items changed while editing. Reload and try again.');
                }
                $name = trim((string)($posted['item_name'] ?? ''));
                $description = trim((string)($posted['item_description'] ?? ''));
                $qty = re_invoice_edit_money($posted['quantity'] ?? 0);
                $price = re_invoice_edit_money($posted['unit_price'] ?? 0);
                $taxRate = re_invoice_edit_money($posted['tax_rate'] ?? 0);
                $taxAmount = re_invoice_edit_money($posted['tax_amount'] ?? 0);

                if ($name === '') {
                    throw new RuntimeException('Item name is required.');
                }
                if ($qty <= 0) {
                    throw new RuntimeException('Quantity must be greater than zero.');
                }
                if ($price < 0 || $taxAmount < 0) {
                    throw new RuntimeException('Price and VAT cannot be negative.');
                }
                if ($taxRate < 0 || $taxRate > 100) {
                    throw new RuntimeException('VAT % must be between 0 and 100.');
                }

                $base = re_invoice_edit_money($qty * $price);
                $lineTotal = re_invoice_edit_money($base + $taxAmount);
                $subtotal += $base;
                $taxTotal += $taxAmount;

                if (abs($qty - (float)$item['quantity']) > 0.004
                    || abs($price - (float)$item['unit_price']) > 0.004
                    || abs($taxRate - (float)$item['tax_rate']) > 0.004
                    || abs($taxAmount - (float)$item['tax_amount']) > 0.004
                    || abs($lineTotal - (float)$item['line_total']) > 0.004) {
                    $amountsChanged = true;
                }
                if ($name !== (string)$item['item_name'] || $description !== (string)($item['item_description'] ?? '')) {
                    $textChanged = true;
                }

                $newLines[$itemId] = [
                    'item' => $item,
                    'item_name' => $name,
                    'item_description' => $description,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'base' => $base,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal,
                ];
            }

            if (!$amountsChanged && !$textChanged) {
                if ($startedHere) {
                    $conn->rollBack();
                }
                return ['success' => true, 'no_change' => true];
            }

            $oldTotal = re_invoice_edit_money($invoice['total_amount']);
            $subtotal = re_invoice_edit_money($subtotal);
            $taxTotal = re_invoice_edit_money($taxTotal);
            $discount = re_invoice_edit_money($invoice['discount_amount'] ?? 0);
            $newTotal = re_invoice_edit_money($subtotal + $taxTotal - $discount);
            if ($amountsChanged && $newTotal <= 0.005) {
                throw new RuntimeException('Invoice total must be greater than zero. Use void to cancel an invoice.');
            }

            $updateItem = $conn->prepare("
                UPDATE re_invoice_items
                SET item_name = ?, item_description = ?, quantity = ?, unit_price = ?,
                    tax_rate = ?, tax_amount = ?, line_total = ?
                WHERE id = ? AND company_id = ?
            ");
            foreach ($newLines as $itemId => $line) {
                $updateItem->execute([
                    $line['item_name'],
                    $line['item_description'] !== '' ? $line['item_description'] : null,
                    $line['quantity'],
                    $line['unit_price'],
                    $line['tax_rate'],
                    $line['tax_amount'],
                    $line['line_total'],
                    $itemId,
                    $companyId,
                ]);
            }

            $invoiceNumber = (string)$invoice['invoice_number'];
            $userName = (string)($_SESSION['user']['username'] ?? $_SESSION['username'] ?? '');
            $auditNote = 'Edited ' . date('Y-m-d H:i') . ($userName !== '' ? ' by ' . $userName : '')
                . ($amountsChanged ? ': total ' . number_format($oldTotal, 2) . ' → ' . number_format($newTotal, 2) : '')
                . '. Reason: ' . $reason;
            $finalNotes = trim($notes);
            $finalNotes = trim($finalNotes . ($finalNotes !== '' ? "\n" : '') . $auditNote);

            $result = ['success' => true, 'amounts_changed' => $amountsChanged, 'old_total' => $oldTotal, 'new_total' => $newTotal, 'credited' => 0.0];

            if (!$amountsChanged) {
                $conn->prepare("UPDATE re_invoices SET notes = ? WHERE id = ? AND company_id = ?")
                    ->execute([$finalNotes, $invoiceId, $companyId]);
            } else {
                $tenantId = (int)$invoice['tenant_id'];
                $arAccount = find_account_by_code('1310', $companyId);
                $deferredAccount = find_account_by_code('2410', $companyId);
                if (!$arAccount || !$deferredAccount) {
                    throw new RuntimeException('Accounts 1310 (Rent Receivable) and 2410 (Deferred Revenue) are required.');
                }
                $tenantLedger = get_or_create_tenant_ledger($tenantId, (int)$arAccount['id'], $companyId);

                // 1. Reverse the original recognition journal and its tenant ledger debit.
                $oldJournalId = re_invoice_edit_recognition_journal_id($conn, $companyId, $invoiceId);
                $reverse = reverse_journal($oldJournalId, 'Invoice ' . $invoiceNumber . ' corrected: ' . $reason, $userId);
                if (empty($reverse['success'])) {
                    throw new RuntimeException('Could not reverse the original invoice entry: ' . ($reverse['error'] ?? 'unknown error'));
                }
                $reversalId = (int)$reverse['reversal_journal_id'];
                $arLine = re_invoice_edit_ar_line($conn, $reversalId, (int)$arAccount['id'], 'credit');
                if (!post_to_tenant_ledger($tenantLedger['id'], (string)$invoice['invoice_date'], 0, $arLine['amount'],
                    "Invoice {$invoiceNumber} reversed (correction)", $invoiceNumber, $companyId, $reversalId, $arLine['id'])) {
                    throw new RuntimeException('Could not update the tenant ledger for the reversal.');
                }

                // 2. Header at the new figures, then repost recognition from it.
                $firstLine = reset($newLines);
                $conn->prepare("
                    UPDATE re_invoices
                    SET subtotal = ?, tax_rate = ?, tax_amount = ?, total_amount = ?, notes = ?
                    WHERE id = ? AND company_id = ?
                ")->execute([$subtotal, $firstLine['tax_rate'], $taxTotal, $newTotal, $finalNotes, $invoiceId, $companyId]);

                $posting = post_invoice_to_accounting($invoiceId, $companyId, $userId);
                if (empty($posting['success']) || empty($posting['journal_id'])) {
                    throw new RuntimeException('Could not post the corrected invoice entry: ' . ($posting['error'] ?? 'unknown error'));
                }
                $newJournalId = (int)$posting['journal_id'];
                $result['journal_id'] = $newJournalId;

                // 3. Paid beyond the new total → move the excess to tenant credit.
                $paid = re_invoice_edit_money($invoice['paid_amount'] ?? 0);
                $excess = re_invoice_edit_money($paid - $newTotal);
                if ($excess > 0.005) {
                    $remaining = $excess;
                    $allocStmt = $conn->prepare("
                        SELECT id, payment_id, amount_allocated
                        FROM re_receipt_allocations
                        WHERE company_id = ? AND invoice_id = ? AND target_type = 'invoice' AND amount_allocated > 0
                        ORDER BY id DESC
                        FOR UPDATE
                    ");
                    $allocStmt->execute([$companyId, $invoiceId]);
                    foreach ($allocStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $alloc) {
                        if ($remaining <= 0.005) {
                            break;
                        }
                        $take = re_invoice_edit_money(min((float)$alloc['amount_allocated'], $remaining));
                        $left = re_invoice_edit_money((float)$alloc['amount_allocated'] - $take);
                        $paymentId = (int)$alloc['payment_id'];
                        if ($left <= 0.005) {
                            $conn->prepare("DELETE FROM re_receipt_allocations WHERE id = ?")->execute([(int)$alloc['id']]);
                        } else {
                            $conn->prepare("UPDATE re_receipt_allocations SET amount_allocated = ? WHERE id = ?")->execute([$left, (int)$alloc['id']]);
                        }

                        $existingCredit = $conn->prepare("
                            SELECT id FROM re_receipt_allocations
                            WHERE company_id = ? AND payment_id = ? AND target_type = 'tenant_credit'
                            LIMIT 1
                            FOR UPDATE
                        ");
                        $existingCredit->execute([$companyId, $paymentId]);
                        $creditRowId = (int)($existingCredit->fetchColumn() ?: 0);
                        if ($creditRowId > 0) {
                            $conn->prepare("UPDATE re_receipt_allocations SET amount_allocated = amount_allocated + ? WHERE id = ?")
                                ->execute([$take, $creditRowId]);
                        } else {
                            $conn->prepare("
                                INSERT INTO re_receipt_allocations
                                    (company_id, payment_id, lease_id, tenant_id, target_type, invoice_id, obligation_id, amount_allocated, notes, created_by)
                                VALUES (?, ?, ?, ?, 'tenant_credit', NULL, NULL, ?, ?, ?)
                            ")->execute([$companyId, $paymentId, (int)$invoice['lease_id'], $tenantId, $take,
                                'Moved from invoice ' . $invoiceNumber . ' after correction', $userId]);
                        }
                        $conn->prepare("UPDATE re_payments SET allocation_status = 'overpaid' WHERE id = ? AND company_id = ?")
                            ->execute([$paymentId, $companyId]);

                        update_tenant_credit($conn, $tenantId, $companyId, $take, 'credit', $paymentId, null,
                            'Invoice ' . $invoiceNumber . ' corrected - excess payment', 'invoice_edit');
                        $remaining = re_invoice_edit_money($remaining - $take);
                    }
                    // Portion that was settled from earlier tenant credit (no allocation row).
                    if ($remaining > 0.005) {
                        update_tenant_credit($conn, $tenantId, $companyId, $remaining, 'credit', null, null,
                            'Invoice ' . $invoiceNumber . ' corrected - credit returned', 'invoice_edit');
                    }

                    $desc = "Invoice {$invoiceNumber} corrected - excess payment to tenant credit";
                    $creditJournal = create_and_post_journal($companyId, 'adjustment', 'invoice_edit', $invoiceId, [
                        ['account_id' => (int)$arAccount['id'], 'debit' => $excess, 'credit' => 0, 'description' => $desc, 'reference' => $invoiceNumber],
                        ['account_id' => (int)$deferredAccount['id'], 'debit' => 0, 'credit' => $excess, 'description' => $desc, 'reference' => $invoiceNumber],
                    ], $desc, (string)$invoice['invoice_date'], $userId);
                    if (empty($creditJournal['success'])) {
                        throw new RuntimeException('Could not post the tenant credit entry: ' . ($creditJournal['error'] ?? 'unknown error'));
                    }
                    $arLine = re_invoice_edit_ar_line($conn, (int)$creditJournal['journal_id'], (int)$arAccount['id'], 'debit');
                    if (!post_to_tenant_ledger($tenantLedger['id'], (string)$invoice['invoice_date'], $excess, 0,
                        $desc, $invoiceNumber, $companyId, (int)$creditJournal['journal_id'], $arLine['id'])) {
                        throw new RuntimeException('Could not update the tenant ledger for the tenant credit entry.');
                    }

                    $paid = $newTotal;
                    $result['credited'] = $excess;
                }

                $outstanding = re_invoice_edit_money(max(0, $newTotal - $paid));
                if ($outstanding <= 0.005) {
                    $status = 'paid';
                } elseif ($paid > 0.005) {
                    $status = 'partial';
                } else {
                    $status = in_array((string)$invoice['status'], ['draft', 'sent', 'overdue'], true) ? (string)$invoice['status'] : 'sent';
                }
                $conn->prepare("
                    UPDATE re_invoices SET paid_amount = ?, outstanding_amount = ?, status = ?
                    WHERE id = ? AND company_id = ?
                ")->execute([$paid, $outstanding, $status, $invoiceId, $companyId]);

                // 4. Keep the source obligation in step with its invoice line.
                foreach ($newLines as $line) {
                    $obligationId = (int)($line['item']['obligation_id'] ?? 0);
                    if ($obligationId <= 0) {
                        continue;
                    }
                    $ob = $conn->prepare("SELECT allocated_amount, status FROM re_obligations WHERE id = ? AND company_id = ? FOR UPDATE");
                    $ob->execute([$obligationId, $companyId]);
                    $obligation = $ob->fetch(PDO::FETCH_ASSOC);
                    if (!$obligation) {
                        continue;
                    }
                    $share = $newTotal > 0 ? $line['line_total'] / $newTotal : 0;
                    $allocated = count($newLines) === 1
                        ? $paid
                        : re_invoice_edit_money($paid * $share);
                    $allocated = re_invoice_edit_money(min($allocated, $line['line_total']));
                    $obStatus = (string)$obligation['status'];
                    if (!in_array($obStatus, ['cancelled', 'waived'], true)) {
                        $obStatus = $allocated >= $line['line_total'] - 0.005 ? 'settled' : ($allocated > 0.005 ? 'partially_allocated' : 'open');
                    }
                    $conn->prepare("
                        UPDATE re_obligations
                        SET subtotal_amount = ?, vat_amount = ?, total_amount = ?, tax_rate = ?,
                            allocated_amount = ?, status = ?, recognition_journal_id = ?
                        WHERE id = ? AND company_id = ?
                    ")->execute([
                        $line['base'], $line['tax_amount'], $line['line_total'], $line['tax_rate'],
                        $allocated, $obStatus, $newJournalId,
                        $obligationId, $companyId,
                    ]);
                }
            }

            accounting_audit_log($companyId, 'invoice_edit', 'invoice', $invoiceId, $userId, json_encode([
                'invoice_number' => $invoiceNumber,
                'old_total' => $oldTotal,
                'new_total' => $newTotal,
                'credited' => $result['credited'],
                'reason' => $reason,
                'old_items' => array_map(static fn($l) => [
                    'id' => (int)$l['item']['id'],
                    'item_name' => $l['item']['item_name'],
                    'quantity' => $l['item']['quantity'],
                    'unit_price' => $l['item']['unit_price'],
                    'tax_rate' => $l['item']['tax_rate'],
                    'tax_amount' => $l['item']['tax_amount'],
                ], array_values($newLines)),
            ]));

            if ($startedHere) {
                $conn->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($startedHere && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
