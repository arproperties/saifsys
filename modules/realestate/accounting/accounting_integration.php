<?php
/**
 * Real Estate Accounting Integration Functions
 * Functions to post transactions from Real Estate module to accounting
 *
 * Payment posting rules (allocation-aware):
 * - Partial balances remain in AR (only allocated amount credits AR).
 * - Overpayment/advance stored as deferred/unearned revenue liability (2410).
 * - Apply-credit reduces liability and credits AR.
 * - Full audit trail via journal + re_tenant_credit_transactions / re_payment_allocations.
 */

require_once __DIR__ . '/accounting_engine.php';
require_once __DIR__ . '/../includes/payment_allocation_helper.php';
require_once __DIR__ . '/../includes/lease_vat_calculator.php';
require_once __DIR__ . '/../includes/re_income_account_roles.php';

/**
 * Post invoice to accounting
 * 
 * @param int $invoiceId Invoice ID from re_invoices table
 * @param int $companyId Company ID
 * @param int|null $createdBy User ID
 * @return array ['success' => bool, 'journal_id' => int|null, 'error' => string|null]
 */
function post_invoice_to_accounting($invoiceId, $companyId, $createdBy = null) {
    global $conn;
    
    try {
        // Get invoice details
        $stmt = $conn->prepare("
            SELECT i.*, l.tenant_id, t.first_name, t.last_name, t.company_name, t.tenant_type,
                   u.unit_type
            FROM re_invoices i
            JOIN re_leases l ON l.id = i.lease_id
            LEFT JOIN re_units u ON u.id = l.unit_id
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE i.id = ? AND i.company_id = ?
        ");
        $stmt->execute([$invoiceId, $companyId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invoice) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Invoice not found'];
        }
        
        // Get invoice items
        $stmt = $conn->prepare("
            SELECT * FROM re_invoice_items 
            WHERE invoice_id = ? AND company_id = ?
            ORDER BY display_order, id
        ");
        $stmt->execute([$invoiceId, $companyId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($items)) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Invoice has no items'];
        }
        
        // Get VAT configuration
        $vatConfig = get_vat_config($companyId);
        $vatRate = $vatConfig ? (float)$vatConfig['vat_rate'] : 5.00;
        
        // Get or create tenant ledger (Accounts Receivable)
        $arAccount = find_account_by_code('1310', $companyId); // Rent Receivable
        if (!$arAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Accounts Receivable account (1310) not found'];
        }
        
        $tenantLedger = get_or_create_tenant_ledger($invoice['tenant_id'], $arAccount['id'], $companyId);
        
        // Get VAT accounts
        $outputVatAccount = find_account_by_code('2310', $companyId); // Output VAT
        if (!$outputVatAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Output VAT account (2310) not found'];
        }
        
        // Build journal lines
        $lines = [];
        $totalDebit = 0;
        $totalCredit = 0;

        // Load obligation classification for lines (structured mapping)
        $obligationMeta = [];
        $obligationIds = [];
        foreach ($items as $item) {
            $oid = (int)($item['obligation_id'] ?? 0);
            if ($oid > 0) {
                $obligationIds[$oid] = true;
            }
        }
        if ($obligationIds) {
            $ids = array_keys($obligationIds);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $ost = $conn->prepare("
                SELECT id, obligation_type, accounting_class
                FROM re_obligations
                WHERE company_id = ? AND id IN ($ph)
            ");
            $ost->execute(array_merge([(int)$companyId], $ids));
            foreach ($ost->fetchAll(PDO::FETCH_ASSOC) as $ob) {
                $obligationMeta[(int)$ob['id']] = $ob;
            }
        }
        
        // Group items by income account
        $incomeAccounts = [];
        foreach ($items as $item) {
            $oid = (int)($item['obligation_id'] ?? 0);
            $ob = $obligationMeta[$oid] ?? [];
            $resolved = re_resolve_income_account_for_line($conn, (int)$companyId, [
                'income_account_id' => $item['income_account_id'] ?? null,
                'obligation_type' => $ob['obligation_type'] ?? ($item['obligation_type'] ?? null),
                'accounting_class' => $ob['accounting_class'] ?? ($item['accounting_class'] ?? null),
                'unit_type' => (string)($invoice['unit_type'] ?? ''),
                'item_name' => (string)($item['item_name'] ?? ''),
            ]);

            $lineTotal = (float)$item['line_total'];
            $itemVat = (float)$item['tax_amount'];
            $lineBase = $lineTotal - $itemVat;

            // VAT-only / liability: no income credit for base (VAT handled separately from header)
            if (!empty($resolved['skip_income'])) {
                continue;
            }

            if (empty($resolved['account']['id'])) {
                return [
                    'success' => false,
                    'journal_id' => null,
                    'error' => $resolved['error'] ?: 'Income account could not be resolved for invoice line',
                ];
            }

            $incomeAccount = $resolved['account'];
            $accountId = (int)$incomeAccount['id'];
            
            // Accumulate by account
            if (!isset($incomeAccounts[$accountId])) {
                $incomeAccounts[$accountId] = [
                    'base' => 0,
                    'vat' => 0,
                    'account' => $incomeAccount,
                    'unmapped' => false,
                    'roles' => [],
                ];
            }
            $incomeAccounts[$accountId]['base'] += $lineBase;
            $incomeAccounts[$accountId]['vat'] += $itemVat;
            if (!empty($resolved['unmapped'])) {
                $incomeAccounts[$accountId]['unmapped'] = true;
            }
            if (!empty($resolved['role'])) {
                $incomeAccounts[$accountId]['roles'][$resolved['role']] = true;
            }
        }
        
        // Add income account credits
        foreach ($incomeAccounts as $accountId => $data) {
            if ($data['base'] > 0) {
                $desc = "Invoice {$invoice['invoice_number']} - {$data['account']['account_name']}";
                if (!empty($data['unmapped'])) {
                    $roleHint = $data['roles'] ? implode(',', array_keys($data['roles'])) : 'OTHER_INCOME';
                    $desc = "[Unmapped income→{$roleHint}] " . $desc;
                }
                $lines[] = [
                    'account_id' => $accountId,
                    'debit' => 0,
                    'credit' => $data['base'],
                    'description' => $desc,
                    'reference' => $invoice['invoice_number']
                ];
                $totalCredit += $data['base'];
            }
        }
        
        // Add VAT credit if applicable
        if ($invoice['tax_amount'] > 0) {
            $lines[] = [
                'account_id' => $outputVatAccount['id'],
                'debit' => 0,
                'credit' => $invoice['tax_amount'],
                'description' => "Invoice {$invoice['invoice_number']} - Output VAT",
                'reference' => $invoice['invoice_number']
            ];
            $totalCredit += $invoice['tax_amount'];
        }
        
        // Add Accounts Receivable debit
        $totalDebit = $invoice['total_amount'];
        $tenantName = $invoice['tenant_type'] === 'company' 
            ? $invoice['company_name'] 
            : ($invoice['first_name'] . ' ' . $invoice['last_name']);
        
        $lines[] = [
            'account_id' => $arAccount['id'],
            'debit' => $totalDebit,
            'credit' => 0,
            'description' => "Invoice {$invoice['invoice_number']} - {$tenantName}",
            'reference' => $invoice['invoice_number']
        ];
        
        // Create and post journal
        $description = "Invoice {$invoice['invoice_number']} - {$tenantName}";
        $result = create_and_post_journal(
            $companyId,
            'invoice',
            'invoice',
            $invoiceId,
            $lines,
            $description,
            $invoice['invoice_date'],
            $createdBy
        );
        
        // Post to tenant ledger (after journal is created)
        if ($result['success'] && $result['journal_id']) {
            // Get the AR line from journal
            $stmt = $conn->prepare("
                SELECT id FROM re_journal_lines 
                WHERE journal_id = ? AND account_id = ? AND debit_amount > 0
                LIMIT 1
            ");
            $stmt->execute([$result['journal_id'], $arAccount['id']]);
            $arLine = $stmt->fetch(PDO::FETCH_ASSOC);
            
            post_to_tenant_ledger($tenantLedger['id'], $invoice['invoice_date'], $totalDebit, 0, 
                "Invoice {$invoice['invoice_number']}", $invoice['invoice_number'], $companyId,
                $result['journal_id'], $arLine ? $arLine['id'] : null);
        }
        
        // Log posting
        if ($result['success']) {
            log_accounting_posting($companyId, $result['journal_id'], 'invoice', $invoiceId, 'posted', null);
        } else {
            log_accounting_posting($companyId, null, 'invoice', $invoiceId, 'failed', $result['error']);
        }
        
        return $result;
        
    } catch (Exception $e) {
        log_accounting_posting($companyId, null, 'invoice', $invoiceId, 'failed', $e->getMessage());
        return ['success' => false, 'journal_id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Post payment to accounting
 * Allocation-aware: credits AR only for allocated + applied credit; overpayment goes to Deferred Revenue (2410).
 *
 * @param int $paymentId Payment ID from re_payments table
 * @param int $companyId Company ID
 * @param int|null $createdBy User ID
 * @param int|null $bankAccountId Bank account ID from form (optional)
 * @return array ['success' => bool, 'journal_id' => int|null, 'error' => string|null]
 */
function post_payment_to_accounting($paymentId, $companyId, $createdBy = null, $bankAccountId = null) {
    global $conn;

    try {
        $stmt = $conn->prepare("
            SELECT p.*, l.tenant_id, t.first_name, t.last_name, t.company_name, t.tenant_type
            FROM re_payments p
            JOIN re_leases l ON l.id = p.lease_id
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE p.id = ? AND p.company_id = ?
        ");
        $stmt->execute([$paymentId, $companyId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Payment not found'];
        }

        $bankAccount = null;
        if ($bankAccountId) {
            $stmt = $conn->prepare("
                SELECT coa.id, coa.account_code, coa.account_name
                FROM re_bank_accounts ba
                JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id
                WHERE ba.id = ? AND ba.company_id = ? AND ba.is_active = 1
            ");
            $stmt->execute([$bankAccountId, $companyId]);
            $bankAccount = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$bankAccount) {
            $bankAccountCode = determine_bank_account($payment['payment_method'], $companyId);
            $bankAccount = find_account_by_code($bankAccountCode, $companyId);
        }
        if (!$bankAccount) {
            $bankAccount = find_account_by_code('1110', $companyId);
        }
        if (!$bankAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Bank/Cash account not found'];
        }

        $arAccount = find_account_by_code('1310', $companyId);
        if (!$arAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Accounts Receivable account (1310) not found'];
        }

        $tenantLedger = get_or_create_tenant_ledger($payment['tenant_id'], $arAccount['id'], $companyId);
        $amount = (float)$payment['amount'];
        $tenantName = $payment['tenant_type'] === 'company'
            ? $payment['company_name']
            : ($payment['first_name'] . ' ' . $payment['last_name']);
        $receiptRef = $payment['receipt_number'] ?: "PAY-{$paymentId}";

        $useAllocation = false;
        $allocSum = 0.0;
        $applyCredit = 0.0;
        $overpayment = 0.0;

        if (payment_allocation_tables_exist($conn)) {
            $stmt = $conn->prepare("SELECT COALESCE(SUM(amount_allocated), 0) FROM re_payment_allocations WHERE payment_id = ?");
            $stmt->execute([$paymentId]);
            $allocSum = (float)($stmt->fetchColumn() ?: 0);
            $stmt = $conn->prepare("
                SELECT type, COALESCE(SUM(amount_aed), 0) as total
                FROM re_tenant_credit_transactions WHERE payment_id = ? GROUP BY type
            ");
            $stmt->execute([$paymentId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($row['type'] === 'debit') {
                    $applyCredit = (float)$row['total'];
                } else {
                    $overpayment = (float)$row['total'];
                }
            }
            $arCredit = $allocSum + $applyCredit;
            if ($allocSum > 0 || $applyCredit > 0 || $overpayment > 0) {
                $useAllocation = true;
            }
        }

        if ($useAllocation) {
            $arCredit = $allocSum + $applyCredit;
            $tenantAdvanceAccount = find_account_by_code('2410', $companyId);
            if (!$tenantAdvanceAccount) {
                log_accounting_posting($companyId, null, 'payment', $paymentId, 'failed', 'Deferred Revenue account (2410) not found');
                return ['success' => false, 'journal_id' => null, 'error' => 'Deferred Revenue account (2410) not found. Add it in Chart of Accounts for allocation/credit posting.'];
            }
            $lines = [
                [
                    'account_id' => $bankAccount['id'],
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => "Payment from {$tenantName} - Receipt {$receiptRef}",
                    'reference' => $receiptRef
                ],
                [
                    'account_id' => $arAccount['id'],
                    'debit' => 0,
                    'credit' => $arCredit,
                    'description' => "Payment from {$tenantName} - Receipt {$receiptRef} (allocated + credit applied)",
                    'reference' => $receiptRef
                ]
            ];
            if ($overpayment > 0) {
                $lines[] = [
                    'account_id' => $tenantAdvanceAccount['id'],
                    'debit' => 0,
                    'credit' => $overpayment,
                    'description' => "Tenant deferred revenue/overpayment - Payment #{$paymentId}",
                    'reference' => $receiptRef
                ];
            }
            if ($applyCredit > 0) {
                $lines[] = [
                    'account_id' => $tenantAdvanceAccount['id'],
                    'debit' => $applyCredit,
                    'credit' => 0,
                    'description' => "Tenant credit applied - Payment #{$paymentId}",
                    'reference' => $receiptRef
                ];
            }
            $description = "Payment from {$tenantName} - Receipt {$receiptRef} (allocated)";
            $result = create_and_post_journal(
                $companyId,
                'payment',
                'payment',
                $paymentId,
                $lines,
                $description,
                $payment['payment_date'],
                $createdBy
            );
            $ledgerAmount = $arCredit;
        } else {
            $lines = [
                [
                    'account_id' => $bankAccount['id'],
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => "Payment from {$tenantName} - Receipt {$receiptRef}",
                    'reference' => $receiptRef
                ],
                [
                    'account_id' => $arAccount['id'],
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => "Payment from {$tenantName} - Receipt {$receiptRef}",
                    'reference' => $receiptRef
                ]
            ];
            $description = "Payment from {$tenantName} - Receipt {$receiptRef}";
            $result = create_and_post_journal(
                $companyId,
                'payment',
                'payment',
                $paymentId,
                $lines,
                $description,
                $payment['payment_date'],
                $createdBy
            );
            $ledgerAmount = $amount;
        }

        if ($result['success'] && $result['journal_id']) {
            $stmt = $conn->prepare("
                SELECT id FROM re_journal_lines
                WHERE journal_id = ? AND account_id = ? AND credit_amount > 0
                LIMIT 1
            ");
            $stmt->execute([$result['journal_id'], $arAccount['id']]);
            $arLine = $stmt->fetch(PDO::FETCH_ASSOC);
            post_to_tenant_ledger($tenantLedger['id'], $payment['payment_date'], 0, $ledgerAmount,
                "Payment - Receipt {$receiptRef}", $receiptRef,
                $companyId, $result['journal_id'], $arLine ? $arLine['id'] : null);
        }

        if ($result['success']) {
            log_accounting_posting($companyId, $result['journal_id'], 'payment', $paymentId, 'posted', null);
        } else {
            log_accounting_posting($companyId, null, 'payment', $paymentId, 'failed', $result['error']);
        }

        return $result;
    } catch (Exception $e) {
        log_accounting_posting($companyId, null, 'payment', $paymentId, 'failed', $e->getMessage());
        return ['success' => false, 'journal_id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Post an Invoice Mode receipt allocation to accounting.
 * Dr Bank/Cash, Cr AR for allocated invoices/obligations, Cr Deferred Revenue for tenant credit.
 */
function post_invoice_mode_receipt_to_accounting($paymentId, $companyId, $createdBy = null): array {
    global $conn;

    try {
        $stmt = $conn->prepare("
            SELECT p.*, l.tenant_id, t.first_name, t.last_name, t.company_name, t.tenant_type
            FROM re_payments p
            JOIN re_leases l ON l.id = p.lease_id AND l.company_id = p.company_id
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE p.id = ? AND p.company_id = ? AND p.accounting_mode = 'invoice'
            LIMIT 1
        ");
        $stmt->execute([$paymentId, $companyId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) return ['success' => false, 'journal_id' => null, 'error' => 'Invoice Mode receipt not found'];

        $existing = $conn->prepare("SELECT id FROM re_journal_headers WHERE company_id = ? AND reference_type = 'payment' AND reference_id = ? AND is_posted = 1 AND is_reversed = 0 LIMIT 1");
        $existing->execute([$companyId, $paymentId]);
        if ($jid = $existing->fetchColumn()) {
            return ['success' => true, 'journal_id' => (int)$jid, 'already_posted' => true, 'error' => null];
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
        if (!$bankAccount && !empty($payment['receipt_account_type']) && !empty($payment['receipt_account_id'])) {
            if ($payment['receipt_account_type'] === 'bank') {
                $bankStmt = $conn->prepare("
                    SELECT coa.id, coa.account_code, coa.account_name
                    FROM re_bank_accounts ba
                    JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id
                    WHERE ba.id = ? AND ba.company_id = ? AND ba.is_active = 1
                    LIMIT 1
                ");
                $bankStmt->execute([(int)$payment['receipt_account_id'], $companyId]);
                $bankAccount = $bankStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } else {
                $accStmt = $conn->prepare("SELECT id, account_code, account_name FROM re_chart_of_accounts WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1");
                $accStmt->execute([(int)$payment['receipt_account_id'], $companyId]);
                $bankAccount = $accStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
        if (!$bankAccount) {
            $bankAccount = find_account_by_code(determine_bank_account($payment['payment_method'], $companyId), $companyId) ?: find_account_by_code('1110', $companyId);
        }
        if (!$bankAccount) return ['success' => false, 'journal_id' => null, 'error' => 'Bank/Cash account not found'];

        $arAccount = find_account_by_code('1310', $companyId);
        if (!$arAccount) return ['success' => false, 'journal_id' => null, 'error' => 'Accounts Receivable account (1310) not found'];

        $tenantAdvanceAccount = find_account_by_code('2410', $companyId);
        $allocatedStmt = $conn->prepare("
            SELECT COALESCE(SUM(CASE WHEN target_type IN ('invoice','obligation') THEN amount_allocated ELSE 0 END),0) allocated_ar,
                   COALESCE(SUM(CASE WHEN target_type = 'tenant_credit' THEN amount_allocated ELSE 0 END),0) tenant_credit
            FROM re_receipt_allocations
            WHERE company_id = ? AND payment_id = ?
        ");
        $allocatedStmt->execute([$companyId, $paymentId]);
        $alloc = $allocatedStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $arCredit = round((float)($alloc['allocated_ar'] ?? 0), 2);
        $tenantCredit = round((float)($alloc['tenant_credit'] ?? 0), 2);
        $receiptAmount = round((float)$payment['amount'], 2);
        if ($tenantCredit > 0 && !$tenantAdvanceAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Deferred Revenue account (2410) not found'];
        }

        $tenantLedger = get_or_create_tenant_ledger((int)$payment['tenant_id'], (int)$arAccount['id'], $companyId);
        $tenantName = ($payment['tenant_type'] ?? '') === 'company'
            ? (string)$payment['company_name']
            : trim((string)$payment['first_name'] . ' ' . (string)$payment['last_name']);
        $receiptRef = $payment['receipt_number'] ?: "PAY-{$paymentId}";
        $desc = "Invoice Mode receipt {$receiptRef} - {$tenantName}";
        $lines = [[
            'account_id' => (int)$bankAccount['id'],
            'debit' => $receiptAmount,
            'credit' => 0,
            'description' => $desc,
            'reference' => $receiptRef,
        ]];
        if ($arCredit > 0) {
            $lines[] = [
                'account_id' => (int)$arAccount['id'],
                'debit' => 0,
                'credit' => $arCredit,
                'description' => $desc . ' (allocated)',
                'reference' => $receiptRef,
            ];
        }
        if ($tenantCredit > 0) {
            $lines[] = [
                'account_id' => (int)$tenantAdvanceAccount['id'],
                'debit' => 0,
                'credit' => $tenantCredit,
                'description' => $desc . ' (deferred revenue / tenant credit)',
                'reference' => $receiptRef,
            ];
        }
        $result = create_and_post_journal($companyId, 'payment', 'payment', $paymentId, $lines, $desc, (string)$payment['payment_date'], $createdBy);
        if (!empty($result['success'])) {
            $jl = $conn->prepare("SELECT id FROM re_journal_lines WHERE journal_id = ? AND account_id = ? AND credit_amount > 0 LIMIT 1");
            $jl->execute([(int)$result['journal_id'], (int)$arAccount['id']]);
            $arLineId = (int)($jl->fetchColumn() ?: 0);
            if ($arCredit > 0) {
                post_to_tenant_ledger($tenantLedger['id'], (string)$payment['payment_date'], 0, $arCredit, "Receipt allocation - {$receiptRef}", $receiptRef, $companyId, (int)$result['journal_id'], $arLineId ?: null);
            }
            log_accounting_posting($companyId, (int)$result['journal_id'], 'payment', $paymentId, 'posted', null);
        } else {
            log_accounting_posting($companyId, null, 'payment', $paymentId, 'failed', $result['error'] ?? null);
        }
        return $result;
    } catch (Throwable $e) {
        log_accounting_posting($companyId, null, 'payment', $paymentId, 'failed', $e->getMessage());
        return ['success' => false, 'journal_id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Apply available tenant credit to an issued Invoice Mode invoice.
 *
 * Tenant-facing balance remains in re_tenant_credit_balances; GL carries the
 * liability in Deferred Revenue / Unearned Revenue (2410).
 *
 * Journal:
 *   Dr Deferred Revenue [2410]
 *      Cr Rent Receivable [1310]
 */
/**
 * Apply tenant credit to an open invoice (AR ↔ deferred liability 2410→1310).
 *
 * @param bool $requireFullSettle When true (auto paths), skip unless credit covers the full
 *                                outstanding — never chips a few fils into a rent/fee month.
 *                                Manual/accountant-driven calls may pass false to allow partial apply.
 */
function apply_tenant_credit_to_invoice_accounting(int $invoiceId, int $companyId, $createdBy = null, bool $requireFullSettle = false): array {
    global $conn;

    try {
        $stmt = $conn->prepare("
            SELECT i.*, l.tenant_id, t.first_name, t.last_name, t.company_name, t.tenant_type
            FROM re_invoices i
            JOIN re_leases l ON l.id = i.lease_id AND l.company_id = i.company_id
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE i.id = ? AND i.company_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$invoiceId, $companyId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            return ['success' => false, 'applied' => 0.0, 'error' => 'Invoice not found'];
        }

        $tenantId = (int)($invoice['tenant_id'] ?? 0);
        $outstanding = round((float)($invoice['outstanding_amount'] ?? 0), 2);
        if ($tenantId <= 0 || $outstanding <= 0.005) {
            return ['success' => true, 'applied' => 0.0, 'error' => null];
        }

        $creditBalance = get_tenant_credit_balance($conn, $tenantId, $companyId);
        if ($requireFullSettle && $creditBalance + 0.005 < $outstanding) {
            // Leave credit parked — partial chip into later months creates messy cheque / AR trails.
            return ['success' => true, 'applied' => 0.0, 'error' => null];
        }
        $applyAmount = round(min($creditBalance, $outstanding), 2);
        if ($applyAmount <= 0.005) {
            return ['success' => true, 'applied' => 0.0, 'error' => null];
        }

        $deferredAccount = find_account_by_code('2410', $companyId);
        if (!$deferredAccount) {
            return ['success' => false, 'applied' => 0.0, 'error' => 'Deferred Revenue account (2410) not found'];
        }
        $arAccount = find_account_by_code('1310', $companyId);
        if (!$arAccount) {
            return ['success' => false, 'applied' => 0.0, 'error' => 'Accounts Receivable account (1310) not found'];
        }

        $creditTxnId = update_tenant_credit(
            $conn,
            $tenantId,
            $companyId,
            $applyAmount,
            'debit',
            null,
            null,
            'Applied tenant credit to invoice ' . (string)$invoice['invoice_number'],
            'applied_invoice'
        );

        $tenantName = ($invoice['tenant_type'] ?? '') === 'company'
            ? (string)$invoice['company_name']
            : trim((string)$invoice['first_name'] . ' ' . (string)$invoice['last_name']);
        $ref = (string)($invoice['invoice_number'] ?: ('INV-' . $invoiceId));
        $desc = "Apply tenant credit to invoice {$ref} - {$tenantName}";
        $result = create_and_post_journal(
            $companyId,
            'adjustment',
            'tenant_credit',
            $creditTxnId ?: $invoiceId,
            [
                [
                    'account_id' => (int)$deferredAccount['id'],
                    'debit' => $applyAmount,
                    'credit' => 0,
                    'description' => $desc,
                    'reference' => $ref,
                ],
                [
                    'account_id' => (int)$arAccount['id'],
                    'debit' => 0,
                    'credit' => $applyAmount,
                    'description' => $desc,
                    'reference' => $ref,
                ],
            ],
            $desc,
            (string)$invoice['invoice_date'],
            $createdBy
        );
        if (empty($result['success'])) {
            throw new RuntimeException((string)($result['error'] ?? 'Could not post tenant credit application journal.'));
        }

        $newPaid = round((float)$invoice['paid_amount'] + $applyAmount, 2);
        $newOutstanding = round(max(0, (float)$invoice['total_amount'] - $newPaid), 2);
        $status = $newOutstanding <= 0.005 ? 'paid' : 'partial';
        $conn->prepare("
            UPDATE re_invoices
            SET paid_amount = ?, outstanding_amount = ?, status = ?
            WHERE id = ? AND company_id = ?
        ")->execute([$newPaid, $newOutstanding, $status, $invoiceId, $companyId]);

        $lines = $conn->prepare("
            SELECT obligation_id, line_total
            FROM re_invoice_items
            WHERE invoice_id = ? AND company_id = ? AND obligation_id IS NOT NULL
        ");
        $lines->execute([$invoiceId, $companyId]);
        $invoiceLines = $lines->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $lineTotal = array_sum(array_map(static fn($r) => (float)$r['line_total'], $invoiceLines));
        foreach ($invoiceLines as $line) {
            $share = $lineTotal > 0 ? round($applyAmount * ((float)$line['line_total'] / $lineTotal), 2) : $applyAmount;
            if ($share <= 0) {
                continue;
            }
            $obligationId = (int)$line['obligation_id'];
            $ob = $conn->prepare("SELECT total_amount, allocated_amount FROM re_obligations WHERE id = ? AND company_id = ? LIMIT 1 FOR UPDATE");
            $ob->execute([$obligationId, $companyId]);
            $obligation = $ob->fetch(PDO::FETCH_ASSOC);
            if (!$obligation) {
                continue;
            }
            $newAllocated = round((float)$obligation['allocated_amount'] + $share, 2);
            $obTotal = (float)$obligation['total_amount'];
            $obStatus = $newAllocated >= $obTotal - 0.005 ? 'settled' : ($newAllocated > 0 ? 'partially_allocated' : 'open');
            $conn->prepare("UPDATE re_obligations SET allocated_amount = ?, status = ? WHERE id = ? AND company_id = ?")
                ->execute([$newAllocated, $obStatus, $obligationId, $companyId]);
        }

        $tenantLedger = get_or_create_tenant_ledger($tenantId, (int)$arAccount['id'], $companyId);
        $jl = $conn->prepare("SELECT id FROM re_journal_lines WHERE journal_id = ? AND account_id = ? AND credit_amount > 0 LIMIT 1");
        $jl->execute([(int)$result['journal_id'], (int)$arAccount['id']]);
        $arLineId = (int)($jl->fetchColumn() ?: 0);
        post_to_tenant_ledger($tenantLedger['id'], (string)$invoice['invoice_date'], 0, $applyAmount, "Tenant credit applied - {$ref}", $ref, $companyId, (int)$result['journal_id'], $arLineId ?: null);

        return ['success' => true, 'applied' => $applyAmount, 'journal_id' => (int)$result['journal_id'], 'error' => null];
    } catch (Throwable $e) {
        return ['success' => false, 'applied' => 0.0, 'error' => $e->getMessage()];
    }
}

/**
 * Post a payment that includes billing-item allocations (service charges / penalties).
 * Rent allocation is posted to AR or Deferred Rent; service allocations are posted to income.
 */
function post_payment_with_billing_allocations_to_accounting($paymentId, $companyId, $createdBy = null, $bankAccountId = null): array {
    global $conn;

    try {
        $stmt = $conn->prepare("
            SELECT p.*, l.tenant_id, l.lease_number, COALESCE(l.deferred_revenue_mode, 0) AS deferred_revenue_mode,
                   t.first_name, t.last_name, t.company_name, t.tenant_type
            FROM re_payments p
            JOIN re_leases l ON l.id = p.lease_id
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE p.id = ? AND p.company_id = ?
        ");
        $stmt->execute([$paymentId, $companyId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Payment not found'];
        }

        $bankAccount = null;
        if ($bankAccountId) {
            $stmt = $conn->prepare("
                SELECT coa.id, coa.account_code, coa.account_name
                FROM re_bank_accounts ba
                JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id
                WHERE ba.id = ? AND ba.company_id = ? AND ba.is_active = 1
            ");
            $stmt->execute([$bankAccountId, $companyId]);
            $bankAccount = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$bankAccount) {
            $bankAccount = find_account_by_code(determine_bank_account($payment['payment_method'], $companyId), $companyId)
                ?: find_account_by_code('1110', $companyId);
        }
        if (!$bankAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Bank/Cash account not found'];
        }

        $arAccount = find_account_by_code('1310', $companyId);
        $deferredAccount = find_account_by_code('2410', $companyId);
        $tenantAdvanceAccount = find_account_by_code('2410', $companyId);
        $serviceIncomeAccount = find_account_by_code('4200', $companyId);
        if (!$serviceIncomeAccount) {
            $conn->prepare("
                INSERT IGNORE INTO re_chart_of_accounts
                    (company_id, account_code, account_name, account_type, normal_balance, is_active, description)
                VALUES (?, '4200', 'Service Charge Income', 'income', 'credit', 1, 'Service charges collected from tenants')
            ")->execute([$companyId]);
            $serviceIncomeAccount = find_account_by_code('4200', $companyId);
        }
        $penaltyIncomeAccount = find_account_by_code('4300', $companyId);
        if (!$penaltyIncomeAccount) {
            $conn->prepare("
                INSERT IGNORE INTO re_chart_of_accounts
                    (company_id, account_code, account_name, account_type, normal_balance, is_active, description)
                VALUES (?, '4300', 'Penalty Income', 'income', 'credit', 1, 'Late fees and penalties collected')
            ")->execute([$companyId]);
            $penaltyIncomeAccount = find_account_by_code('4300', $companyId);
        }

        if (!$tenantAdvanceAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Deferred Revenue account (2410) not found'];
        }

        $rentAlloc = 0.0;
        try {
            $stmt = $conn->prepare("SELECT COALESCE(SUM(amount_allocated), 0) FROM re_payment_allocations WHERE payment_id = ?");
            $stmt->execute([$paymentId]);
            $rentAlloc = (float)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $rentAlloc = 0.0;
        }

        $billingByType = [];
        try {
            $stmt = $conn->prepare("
                SELECT bi.item_type, COALESCE(SUM(bipa.amount_allocated), 0) AS amount
                FROM re_billing_item_payment_allocations bipa
                JOIN re_billing_items bi ON bi.id = bipa.billing_item_id
                WHERE bipa.payment_id = ? AND bipa.company_id = ?
                GROUP BY bi.item_type
            ");
            $stmt->execute([$paymentId, $companyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $billingByType[$row['item_type']] = (float)$row['amount'];
            }
        } catch (Throwable $e) {
            $stmt = $conn->prepare("
                SELECT item_type, COALESCE(SUM(paid_amount), 0) AS amount
                FROM re_billing_items
                WHERE payment_id = ? AND company_id = ?
                GROUP BY item_type
            ");
            $stmt->execute([$paymentId, $companyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $billingByType[$row['item_type']] = (float)$row['amount'];
            }
        }

        $applyCredit = 0.0;
        $overpayment = 0.0;
        try {
            $stmt = $conn->prepare("
                SELECT type, COALESCE(SUM(amount_aed), 0) AS total
                FROM re_tenant_credit_transactions
                WHERE payment_id = ?
                GROUP BY type
            ");
            $stmt->execute([$paymentId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['type'] === 'debit') {
                    $applyCredit = (float)$row['total'];
                } else {
                    $overpayment = (float)$row['total'];
                }
            }
        } catch (Throwable $e) {
            $applyCredit = 0.0;
            $overpayment = 0.0;
        }

        $amount = (float)$payment['amount'];
        $tenantName = $payment['tenant_type'] === 'company'
            ? $payment['company_name']
            : trim($payment['first_name'] . ' ' . $payment['last_name']);
        $receiptRef = $payment['receipt_number'] ?: "PAY-{$paymentId}";
        $lines = [[
            'account_id' => $bankAccount['id'],
            'debit' => $amount,
            'credit' => 0,
            'description' => "Payment from {$tenantName} - Receipt {$receiptRef}",
            'reference' => $receiptRef,
        ]];

        // Allocation inputs already include any tenant credit applied, so do not add
        // $applyCredit again to AR/deferred credits.
        $rentCredit = $rentAlloc;
        if ($rentCredit > 0) {
            $rentAccount = !empty($payment['deferred_revenue_mode']) ? $deferredAccount : $arAccount;
            if (!$rentAccount) {
                return ['success' => false, 'journal_id' => null, 'error' => !empty($payment['deferred_revenue_mode'])
                    ? 'Deferred Rent Revenue account (2410) not found'
                    : 'Accounts Receivable account (1310) not found'];
            }
            $lines[] = [
                'account_id' => $rentAccount['id'],
                'debit' => 0,
                'credit' => $rentCredit,
                'description' => (!empty($payment['deferred_revenue_mode']) ? 'Deferred rent' : 'Rent receivable') . " collection - {$tenantName}",
                'reference' => $receiptRef,
            ];
        }

        foreach ($billingByType as $type => $billingAmount) {
            if ($billingAmount <= 0) {
                continue;
            }
            $incomeAccount = ($type === 'penalty') ? $penaltyIncomeAccount : $serviceIncomeAccount;
            if (!$incomeAccount) {
                return ['success' => false, 'journal_id' => null, 'error' => 'Service/Penalty income account not found'];
            }
            $lines[] = [
                'account_id' => $incomeAccount['id'],
                'debit' => 0,
                'credit' => $billingAmount,
                'description' => ucfirst(str_replace('_', ' ', $type)) . " collected - {$tenantName}",
                'reference' => $receiptRef,
            ];
        }

        if ($applyCredit > 0) {
            $lines[] = [
                'account_id' => $tenantAdvanceAccount['id'],
                'debit' => $applyCredit,
                'credit' => 0,
                'description' => "Tenant credit applied - Payment #{$paymentId}",
                'reference' => $receiptRef,
            ];
        }
        if ($overpayment > 0) {
            $lines[] = [
                'account_id' => $tenantAdvanceAccount['id'],
                'debit' => 0,
                'credit' => $overpayment,
                'description' => "Tenant deferred revenue/overpayment - Payment #{$paymentId}",
                'reference' => $receiptRef,
            ];
        }

        $result = create_and_post_journal(
            $companyId,
            'payment',
            'payment',
            $paymentId,
            $lines,
            "Payment from {$tenantName} - Receipt {$receiptRef} (rent/services allocated)",
            $payment['payment_date'],
            $createdBy
        );

        if ($result['success'] && $result['journal_id'] && $rentCredit > 0 && $arAccount) {
            if (!empty($payment['deferred_revenue_mode']) && $rentAlloc > 0) {
                _populate_recognition_schedule($paymentId, $payment, $rentAlloc, $companyId);
            }
            $tenantLedger = get_or_create_tenant_ledger($payment['tenant_id'], $arAccount['id'], $companyId);
            $stmt = $conn->prepare("
                SELECT id FROM re_journal_lines
                WHERE journal_id = ? AND account_id = ? AND credit_amount > 0
                LIMIT 1
            ");
            $stmt->execute([$result['journal_id'], $arAccount['id']]);
            $arLine = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($arLine) {
                post_to_tenant_ledger($tenantLedger['id'], $payment['payment_date'], 0, $rentCredit,
                    "Payment - Receipt {$receiptRef}", $receiptRef, $companyId, $result['journal_id'], $arLine['id']);
            }
        }

        log_accounting_posting(
            $companyId,
            $result['success'] ? $result['journal_id'] : null,
            'payment',
            $paymentId,
            $result['success'] ? 'posted' : 'failed',
            $result['success'] ? null : ($result['error'] ?? 'Unknown error')
        );
        return $result;
    } catch (Exception $e) {
        log_accounting_posting($companyId, null, 'payment', $paymentId, 'failed', $e->getMessage());
        return ['success' => false, 'journal_id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Post security deposit to accounting
 * 
 * @param int $leaseId Lease ID
 * @param int $companyId Company ID
 * @param int|null $createdBy User ID
 * @return array ['success' => bool, 'journal_id' => int|null, 'error' => string|null]
 */
function post_security_deposit_to_accounting($leaseId, $companyId, $createdBy = null) {
    global $conn;
    
    try {
        // Get lease details
        $stmt = $conn->prepare("
            SELECT l.*, t.first_name, t.last_name, t.company_name, t.tenant_type
            FROM re_leases l
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE l.id = ? AND l.company_id = ?
        ");
        $stmt->execute([$leaseId, $companyId]);
        $lease = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$lease) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Lease not found'];
        }
        
        $depositAmount = (float)($lease['security_deposit'] ?? 0);
        if ($depositAmount <= 0) {
            return ['success' => false, 'journal_id' => null, 'error' => 'No security deposit to post'];
        }
        
        // Get bank/cash account (use default bank account)
        $bankAccount = find_account_by_code('1210', $companyId); // Default bank account
        if (!$bankAccount) {
            $bankAccount = find_account_by_code('1110', $companyId); // Cash on Hand
        }
        
        if (!$bankAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Bank/Cash account not found'];
        }
        
        // Get Security Deposits Payable account
        $depositPayableAccount = find_account_by_code('2200', $companyId);
        if (!$depositPayableAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Security Deposits Payable account (2200) not found'];
        }
        
        // Build journal lines
        $tenantName = $lease['tenant_type'] === 'company' 
            ? $lease['company_name'] 
            : ($lease['first_name'] . ' ' . $lease['last_name']);
        
        $lines = [
            [
                'account_id' => $bankAccount['id'],
                'debit' => $depositAmount,
                'credit' => 0,
                'description' => "Security Deposit - Lease {$lease['lease_number']} - {$tenantName}",
                'reference' => $lease['lease_number']
            ],
            [
                'account_id' => $depositPayableAccount['id'],
                'debit' => 0,
                'credit' => $depositAmount,
                'description' => "Security Deposit - Lease {$lease['lease_number']} - {$tenantName}",
                'reference' => $lease['lease_number']
            ]
        ];
        
        // Create and post journal
        $description = "Security Deposit - Lease {$lease['lease_number']} - {$tenantName}";
        $result = create_and_post_journal(
            $companyId,
            'deposit',
            'lease',
            $leaseId,
            $lines,
            $description,
            $lease['start_date'],
            $createdBy
        );
        
        // Log posting
        if ($result['success']) {
            log_accounting_posting($companyId, $result['journal_id'], 'lease', $leaseId, 'posted', null);
        } else {
            log_accounting_posting($companyId, null, 'lease', $leaseId, 'failed', $result['error']);
        }
        
        return $result;
        
    } catch (Exception $e) {
        log_accounting_posting($companyId, null, 'lease', $leaseId, 'failed', $e->getMessage());
        return ['success' => false, 'journal_id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Helper: Get or create tenant ledger
 */
function get_or_create_tenant_ledger($tenantId, $accountId, $companyId) {
    global $conn;
    
    // Check if ledger exists
    $stmt = $conn->prepare("
        SELECT * FROM re_account_ledgers 
        WHERE company_id = ? AND account_id = ? AND sub_account_type = 'tenant' AND sub_account_id = ?
    ");
    $stmt->execute([$companyId, $accountId, $tenantId]);
    $ledger = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ledger) {
        return $ledger;
    }
    
    // Get tenant name
    $stmt = $conn->prepare("
        SELECT first_name, last_name, company_name, tenant_type 
        FROM re_tenants 
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$tenantId, $companyId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tenant) {
        throw new Exception("Tenant not found: $tenantId");
    }
    
    $tenantName = $tenant['tenant_type'] === 'company' 
        ? $tenant['company_name'] 
        : ($tenant['first_name'] . ' ' . $tenant['last_name']);
    
    // Create ledger
    $stmt = $conn->prepare("
        INSERT INTO re_account_ledgers 
        (company_id, account_id, sub_account_type, sub_account_id, sub_account_name, opening_balance, current_balance)
        VALUES (?, ?, 'tenant', ?, ?, 0, 0)
    ");
    $stmt->execute([$companyId, $accountId, $tenantId, $tenantName]);
    
    return [
        'id' => $conn->lastInsertId(),
        'company_id' => $companyId,
        'account_id' => $accountId,
        'sub_account_type' => 'tenant',
        'sub_account_id' => $tenantId,
        'sub_account_name' => $tenantName
    ];
}

/**
 * Helper: Post to tenant ledger
 * Note: This should be called AFTER journal is created and posted
 */
function post_to_tenant_ledger($ledgerId, $entryDate, $debit, $credit, $description, $reference, $companyId, $journalId = null, $journalLineId = null) {
    global $conn;
    
    try {
        // Get current balance
        $stmt = $conn->prepare("
            SELECT balance FROM re_account_ledger_entries 
            WHERE ledger_id = ? AND company_id = ?
            ORDER BY entry_date DESC, id DESC 
            LIMIT 1
        ");
        $stmt->execute([$ledgerId, $companyId]);
        $lastEntry = $stmt->fetch(PDO::FETCH_ASSOC);
        $previousBalance = $lastEntry ? (float)$lastEntry['balance'] : 0;
        
        // Get ledger opening balance if no entries
        if ($previousBalance == 0) {
            $stmt = $conn->prepare("
                SELECT opening_balance FROM re_account_ledgers 
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([$ledgerId, $companyId]);
            $ledger = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($ledger) {
                $previousBalance = (float)$ledger['opening_balance'];
            }
        }
        
        // Calculate new balance (AR is debit normal, so debit increases, credit decreases)
        $newBalance = $previousBalance + $debit - $credit;
        
        // Insert ledger entry
        $stmt = $conn->prepare("
            INSERT INTO re_account_ledger_entries 
            (company_id, ledger_id, journal_id, journal_line_id, entry_date, 
             debit_amount, credit_amount, balance, description, reference)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $companyId, $ledgerId, $journalId, $journalLineId, $entryDate,
            $debit, $credit, $newBalance, $description, $reference
        ]);
        
        // Update ledger current balance
        $stmt = $conn->prepare("
            UPDATE re_account_ledgers 
            SET current_balance = ?
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$newBalance, $ledgerId, $companyId]);
        
        return true;
    } catch (Exception $e) {
        error_log("Error posting to tenant ledger: " . $e->getMessage());
        return false;
    }
}

/**
 * Helper: Get or create vendor ledger (AP sub-ledger)
 */
function get_or_create_vendor_ledger($vendorId, $accountId, $companyId) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT * FROM re_account_ledgers
        WHERE company_id = ? AND account_id = ? AND sub_account_type = 'vendor' AND sub_account_id = ?
    ");
    $stmt->execute([$companyId, $accountId, $vendorId]);
    $ledger = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($ledger) {
        return $ledger;
    }

    $stmt = $conn->prepare("SELECT vendor_name FROM re_vendors WHERE id = ? AND company_id = ?");
    $stmt->execute([$vendorId, $companyId]);
    $v = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$v) {
        throw new Exception("Vendor not found: $vendorId");
    }

    $stmt = $conn->prepare("
        INSERT INTO re_account_ledgers
        (company_id, account_id, sub_account_type, sub_account_id, sub_account_name, opening_balance, current_balance)
        VALUES (?, ?, 'vendor', ?, ?, 0, 0)
    ");
    $stmt->execute([$companyId, $accountId, $vendorId, $v['vendor_name']]);
    $id = $conn->lastInsertId();
    return [
        'id' => $id,
        'company_id' => $companyId,
        'account_id' => $accountId,
        'sub_account_type' => 'vendor',
        'sub_account_id' => $vendorId,
        'sub_account_name' => $v['vendor_name']
    ];
}

/**
 * Post vendor bill to accounting (Dr Expense, Cr AP / vendor ledger).
 * Call after saving re_vendor_invoices and re_vendor_invoice_items.
 *
 * @param int $vendorInvoiceId re_vendor_invoices.id
 * @param int $companyId
 * @param int|null $createdBy
 * @return array ['success' => bool, 'journal_id' => int|null, 'error' => string|null]
 */
function post_vendor_bill_to_accounting($vendorInvoiceId, $companyId, $createdBy = null) {
    global $conn;

    try {
        $stmt = $conn->prepare("
            SELECT vi.*, v.vendor_name
            FROM re_vendor_invoices vi
            JOIN re_vendors v ON v.id = vi.vendor_id AND v.company_id = vi.company_id
            WHERE vi.id = ? AND vi.company_id = ?
        ");
        $stmt->execute([$vendorInvoiceId, $companyId]);
        $bill = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bill) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Vendor invoice not found'];
        }

        $stmt = $conn->prepare("
            SELECT * FROM re_vendor_invoice_items WHERE invoice_id = ? AND company_id = ?
            ORDER BY id
        ");
        $stmt->execute([$vendorInvoiceId, $companyId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($items)) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Vendor invoice has no items'];
        }

        $apAccount = find_account_by_code('2130', $companyId);
        if (!$apAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Vendor Payable account (2130) not found'];
        }

        $vendorLedger = get_or_create_vendor_ledger($bill['vendor_id'], $apAccount['id'], $companyId);
        $expenseAccount = find_account_by_code('5100', $companyId); // General Operating Expense
        if (!$expenseAccount) {
            $expenseAccount = find_account_by_code('5000', $companyId);
        }
        if (!$expenseAccount) {
            $stmt = $conn->prepare("
                SELECT id, account_code, account_name FROM re_chart_of_accounts
                WHERE company_id = ? AND account_type = 'Expense' AND is_header = 0 AND is_active = 1
                ORDER BY account_code LIMIT 1
            ");
            $stmt->execute([$companyId]);
            $expenseAccount = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$expenseAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'No expense account found in Chart of Accounts'];
        }

        $totalAmount = (float) $bill['total_amount'];
        $lines = [
            [
                'account_id' => $expenseAccount['id'],
                'debit' => $totalAmount,
                'credit' => 0,
                'description' => 'Vendor bill: ' . $bill['invoice_number'] . ' - ' . $bill['vendor_name'],
                'reference' => $bill['invoice_number']
            ],
            [
                'account_id' => $apAccount['id'],
                'debit' => 0,
                'credit' => $totalAmount,
                'description' => 'AP: ' . $bill['vendor_name'],
                'reference' => $bill['invoice_number']
            ]
        ];

        $description = 'Vendor bill ' . $bill['invoice_number'] . ' - ' . $bill['vendor_name'];
        $result = create_and_post_journal(
            $companyId,
            'manual',
            'vendor_invoice',
            (int) $vendorInvoiceId,
            $lines,
            $description,
            $bill['invoice_date'],
            $createdBy
        );
        if (!$result['success']) {
            return $result;
        }

        $journalId = $result['journal_id'];
        $stmt = $conn->prepare("
            SELECT id FROM re_journal_lines
            WHERE journal_id = ? AND account_id = ? ORDER BY line_number LIMIT 1
        ");
        $stmt->execute([$journalId, $apAccount['id']]);
        $journalLine = $stmt->fetch(PDO::FETCH_ASSOC);
        $journalLineId = $journalLine ? $journalLine['id'] : null;

        post_to_vendor_ledger(
            $vendorLedger['id'],
            $bill['invoice_date'],
            0,
            $totalAmount,
            $description,
            $bill['invoice_number'],
            $companyId,
            $journalId,
            $journalLineId
        );

        log_accounting_posting($companyId, $journalId, 'vendor_invoice', $vendorInvoiceId, 'posted');
        return ['success' => true, 'journal_id' => $journalId, 'error' => null];
    } catch (Exception $e) {
        error_log("post_vendor_bill_to_accounting: " . $e->getMessage());
        return ['success' => false, 'journal_id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Post to vendor ledger (AP sub-ledger). AP is credit normal: credit increases balance.
 */
function post_to_vendor_ledger($ledgerId, $entryDate, $debit, $credit, $description, $reference, $companyId, $journalId = null, $journalLineId = null) {
    global $conn;
    try {
        $stmt = $conn->prepare("
            SELECT balance FROM re_account_ledger_entries
            WHERE ledger_id = ? AND company_id = ?
            ORDER BY entry_date DESC, id DESC LIMIT 1
        ");
        $stmt->execute([$ledgerId, $companyId]);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);
        $prev = $last ? (float)$last['balance'] : 0;

        $stmt = $conn->prepare("
            SELECT opening_balance FROM re_account_ledgers WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$ledgerId, $companyId]);
        $ledger = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($prev == 0 && $ledger) {
            $prev = (float)$ledger['opening_balance'];
        }
        $newBalance = $prev - $debit + $credit;

        $stmt = $conn->prepare("
            INSERT INTO re_account_ledger_entries
            (company_id, ledger_id, journal_id, journal_line_id, entry_date, debit_amount, credit_amount, balance, description, reference)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$companyId, $ledgerId, $journalId, $journalLineId, $entryDate, $debit, $credit, $newBalance, $description, $reference]);

        $stmt = $conn->prepare("
            UPDATE re_account_ledgers SET current_balance = ? WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$newBalance, $ledgerId, $companyId]);
        return true;
    } catch (Exception $e) {
        error_log("post_to_vendor_ledger: " . $e->getMessage());
        return false;
    }
}

/**
 * Helper: Determine income account based on item name and lease/unit type.
 * Legacy wrapper — delegates to role helper (seed COA defaults only inside helper).
 * Prefer re_resolve_income_account_for_line() for new code.
 *
 * @return string account_code
 */
function determine_income_account($itemName, $companyId, string $unitType = '') {
    global $conn;
    $mapped = re_income_role_for_obligation(null, null, $unitType, (string)$itemName);
    $role = (string)($mapped['role'] ?? 'OTHER_INCOME');
    if (!empty($mapped['skip_income'])) {
        $role = 'OTHER_INCOME';
    }
    $pdo = ($conn instanceof PDO) ? $conn : null;
    if ($pdo) {
        $code = re_income_role_account_code($pdo, (int)$companyId, $role);
        if ($code) {
            return $code;
        }
    }
    $defaults = re_income_account_role_seed_defaults();
    return $defaults[$role] ?? $defaults['OTHER_INCOME'];
}

/**
 * Helper: Determine bank account based on payment method
 */
function determine_bank_account($paymentMethod, $companyId) {
    switch ($paymentMethod) {
        case 'bank_transfer':
        case 'cheque':
        case 'auto_debit':
            return '1210'; // Bank Account - ADCB
        case 'cash':
        case 'cash_deposit':
            return '1110'; // Cash on Hand
        default:
            return '1110'; // Default to Cash
    }
}

/**
 * Helper: Get VAT configuration
 */
function get_vat_config($companyId) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT * FROM re_vat_config 
        WHERE company_id = ? AND is_active = 1
        ORDER BY effective_from DESC 
        LIMIT 1
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Post credit note to accounting (Dr Income, Cr AR) and optionally reduce invoice outstanding.
 *
 * @param int $invoiceId re_invoices.id
 * @param float $amount Credit note amount (must be <= invoice outstanding)
 * @param int $companyId
 * @param int|null $createdBy
 * @param string $reason Optional reason/description
 * @return array ['success' => bool, 'journal_id' => int|null, 'error' => string|null]
 */
function post_credit_note_to_accounting($invoiceId, $amount, $companyId, $createdBy = null, $reason = '') {
    global $conn;
    $amount = (float)$amount;
    if ($amount <= 0) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Credit note amount must be positive'];
    }
    try {
        $stmt = $conn->prepare("
            SELECT i.*, l.tenant_id, t.first_name, t.last_name, t.company_name, t.tenant_type
            FROM re_invoices i
            JOIN re_leases l ON l.id = i.lease_id
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE i.id = ? AND i.company_id = ?
        ");
        $stmt->execute([$invoiceId, $companyId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Invoice not found'];
        }
        $outstanding = (float)$invoice['outstanding_amount'];
        if ($amount > $outstanding) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Credit note amount cannot exceed outstanding amount (' . number_format($outstanding, 2) . ')'];
        }
        $arAccount = find_account_by_code('1310', $companyId);
        if (!$arAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'AR account (1310) not found'];
        }
        $incomeAccount = find_account_by_code('4110', $companyId);
        if (!$incomeAccount) {
            $incomeAccount = find_account_by_code('4100', $companyId);
        }
        if (!$incomeAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Income account not found'];
        }
        $tenantLedger = get_or_create_tenant_ledger($invoice['tenant_id'], $arAccount['id'], $companyId);
        $tenantName = $invoice['tenant_type'] === 'company' ? $invoice['company_name'] : ($invoice['first_name'] . ' ' . $invoice['last_name']);
        $desc = 'Credit note for Invoice ' . $invoice['invoice_number'] . ' - ' . $tenantName . ($reason ? ': ' . $reason : '');
        $lines = [
            ['account_id' => $incomeAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => $desc, 'reference' => 'CN-' . $invoiceId],
            ['account_id' => $arAccount['id'], 'debit' => 0, 'credit' => $amount, 'description' => $desc, 'reference' => 'CN-' . $invoiceId]
        ];
        $result = create_and_post_journal(
            $companyId,
            'credit_note',
            'credit_note',
            0,
            $lines,
            $desc,
            date('Y-m-d'),
            $createdBy
        );
        if (!$result['success']) {
            return $result;
        }
        post_to_tenant_ledger($tenantLedger['id'], date('Y-m-d'), 0, $amount, $desc, 'CN-' . $invoiceId, $companyId, $result['journal_id'], null);
        $newOutstanding = $outstanding - $amount;
        $newPaid = (float)$invoice['paid_amount'];
        $stmt = $conn->prepare("
            UPDATE re_invoices SET outstanding_amount = ?, paid_amount = ?,
            status = IF(? <= 0.01, 'paid', status)
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$newOutstanding, $newPaid, $newOutstanding, $invoiceId, $companyId]);
        log_accounting_posting($companyId, $result['journal_id'], 'credit_note', $invoiceId, 'posted');
        return ['success' => true, 'journal_id' => $result['journal_id'], 'error' => null];
    } catch (Exception $e) {
        error_log("post_credit_note_to_accounting: " . $e->getMessage());
        return ['success' => false, 'journal_id' => null, 'error' => $e->getMessage()];
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// PRIORITY 1 — DEFERRED REVENUE / ACCRUAL ACCOUNTING
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Post payment for a DEFERRED-REVENUE lease.
 *
 * Instead of Cr. AR, posts:
 *   Dr. Bank  →  Cr. Deferred Rent Revenue [2410]
 *
 * Also populates re_rent_recognition_schedule for each installment
 * the payment covers, so that the monthly recognition run can later
 * Dr. Deferred Rent Revenue → Cr. Rental Income.
 *
 * @param int      $paymentId     re_payments.id
 * @param int      $companyId
 * @param int|null $createdBy     User ID
 * @param int|null $bankAccountId From form (overrides payment_method lookup)
 * @return array   ['success', 'journal_id', 'error']
 */
function post_deferred_payment_to_accounting($paymentId, $companyId, $createdBy = null, $bankAccountId = null) {
    global $conn;

    try {
        // Load payment + lease + tenant
        $stmt = $conn->prepare("
            SELECT p.*, l.tenant_id, l.lease_number, l.start_date,
                   t.first_name, t.last_name, t.company_name, t.tenant_type
            FROM re_payments p
            JOIN re_leases l ON l.id = p.lease_id
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE p.id = ? AND p.company_id = ?
        ");
        $stmt->execute([$paymentId, $companyId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Payment not found'];
        }

        // Resolve bank/cash account
        $bankAccount = null;
        if ($bankAccountId) {
            $s = $conn->prepare("
                SELECT coa.id, coa.account_code, coa.account_name
                FROM re_bank_accounts ba
                JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id
                WHERE ba.id = ? AND ba.company_id = ? AND ba.is_active = 1
            ");
            $s->execute([$bankAccountId, $companyId]);
            $bankAccount = $s->fetch(PDO::FETCH_ASSOC);
        }
        if (!$bankAccount) {
            $code = determine_bank_account($payment['payment_method'], $companyId);
            $bankAccount = find_account_by_code($code, $companyId) ?: find_account_by_code('1110', $companyId);
        }
        if (!$bankAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Bank/Cash account not found'];
        }

        // Deferred Rent Revenue account [2410]
        $deferredAccount = find_account_by_code('2410', $companyId);
        if (!$deferredAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Deferred Rent Revenue account (2410) not found. Run the accounting_deferred_revenue.sql migration first.'];
        }

        $amount    = (float)$payment['amount'];
        $receiptRef = $payment['receipt_number'] ?: "PAY-{$paymentId}";
        $tenantName = $payment['tenant_type'] === 'company'
            ? $payment['company_name']
            : ($payment['first_name'] . ' ' . $payment['last_name']);

        $lines = [
            [
                'account_id' => $bankAccount['id'],
                'debit'      => $amount,
                'credit'     => 0,
                'description' => "Advance rent received - {$tenantName} - Receipt {$receiptRef}",
                'reference'  => $receiptRef,
            ],
            [
                'account_id' => $deferredAccount['id'],
                'debit'      => 0,
                'credit'     => $amount,
                'description' => "Deferred rent - {$tenantName} - Receipt {$receiptRef}",
                'reference'  => $receiptRef,
            ],
        ];

        $description = "Advance rent - Lease {$payment['lease_number']} - {$tenantName} - {$receiptRef}";
        $result = create_and_post_journal(
            $companyId, 'payment', 'payment', $paymentId,
            $lines, $description, $payment['payment_date'], $createdBy
        );

        if ($result['success']) {
            // Populate recognition schedule — distribute this payment across
            // installments that are still pending recognition, in date order.
            _populate_recognition_schedule($paymentId, $payment, $amount, $companyId);
            log_accounting_posting($companyId, $result['journal_id'], 'payment', $paymentId, 'posted_deferred', null);
        } else {
            log_accounting_posting($companyId, null, 'payment', $paymentId, 'failed', $result['error']);
        }

        return $result;

    } catch (Exception $e) {
        log_accounting_posting($companyId, null, 'payment', $paymentId, 'failed', $e->getMessage());
        return ['success' => false, 'journal_id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Internal helper: fill re_rent_recognition_schedule rows for a payment.
 * Distributes $amount across unfilled pending installments (FIFO by date).
 */
function _populate_recognition_schedule($paymentId, $payment, $amount, $companyId) {
    global $conn;

    // Step 1: Update existing pre-seeded rows (deferred_payment_id IS NULL) first,
    // distributing this payment across installments in date order (FIFO).
    $stmt = $conn->prepare("
        SELECT rrs.id, rrs.installment_id, rrs.amount, li.installment_date
        FROM re_rent_recognition_schedule rrs
        JOIN re_lease_installments li ON li.id = rrs.installment_id
        WHERE rrs.lease_id = ? AND rrs.company_id = ?
          AND rrs.status = 'pending' AND rrs.deferred_payment_id IS NULL
        ORDER BY li.installment_date ASC, rrs.id ASC
    ");
    $stmt->execute([$payment['lease_id'], $companyId]);
    $existingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $remaining = $amount;
    $coveredInstallmentIds = [];

    foreach ($existingRows as $row) {
        if ($remaining <= 0) break;
        $conn->prepare("
            UPDATE re_rent_recognition_schedule
            SET deferred_payment_id = ?
            WHERE id = ? AND deferred_payment_id IS NULL
        ")->execute([$paymentId, $row['id']]);
        $coveredInstallmentIds[] = $row['installment_id'];
        $remaining -= (float)$row['amount'];
    }

    // Step 2: For any installments not yet in the schedule at all, insert new rows.
    if ($remaining > 0) {
        $stmt = $conn->prepare("
            SELECT li.id, li.installment_date, li.amount
            FROM re_lease_installments li
            LEFT JOIN re_rent_recognition_schedule rrs
                   ON rrs.installment_id = li.id AND rrs.company_id = ?
            WHERE li.lease_id = ? AND li.company_id = ? AND rrs.id IS NULL
            ORDER BY li.installment_date ASC, li.id ASC
        ");
        $stmt->execute([$companyId, $payment['lease_id'], $companyId]);
        $newInstallments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($newInstallments as $inst) {
            if ($remaining <= 0) break;
            $toRecognise = min($remaining, (float)$inst['amount']);
            $conn->prepare("
                INSERT IGNORE INTO re_rent_recognition_schedule
                    (company_id, lease_id, installment_id, recognition_date,
                     amount, status, deferred_payment_id)
                VALUES (?, ?, ?, ?, ?, 'pending', ?)
            ")->execute([
                $companyId,
                $payment['lease_id'],
                $inst['id'],
                $inst['installment_date'],
                $toRecognise,
                $paymentId,
            ]);
            $remaining -= $toRecognise;
        }
    }
}

/**
 * Pre-seed re_rent_recognition_schedule for every installment of a lease.
 *
 * Called when a lease is saved with deferred_revenue_mode = 1 so the
 * Revenue Recognition page shows the full plan even before payments arrive.
 * Uses INSERT IGNORE so it is safe to call multiple times (re-saves, etc.).
 * Rows created without a deferred_payment_id (NULL) — the payment link is
 * filled in later by _populate_recognition_schedule() when cash is received.
 *
 * If deferred_revenue_mode is later turned OFF, existing 'pending' rows are
 * removed so the schedule stays clean.
 *
 * @param int $leaseId
 * @param int $companyId
 */
function seed_recognition_schedule_for_lease(int $leaseId, int $companyId): void {
    global $conn;

    // Check whether deferred mode is actually on for this lease
    $s = $conn->prepare("SELECT deferred_revenue_mode FROM re_leases WHERE id = ? AND company_id = ?");
    $s->execute([$leaseId, $companyId]);
    $lease = $s->fetch(PDO::FETCH_ASSOC);
    if (!$lease) return;

    if (!(int)$lease['deferred_revenue_mode']) {
        // Deferred mode turned off — remove any unprocessed seed rows
        $conn->prepare("
            DELETE FROM re_rent_recognition_schedule
            WHERE lease_id = ? AND company_id = ? AND status = 'pending' AND deferred_payment_id IS NULL
        ")->execute([$leaseId, $companyId]);
        return;
    }

    // Load all installments for this lease (company_id now exists after migration)
    $s = $conn->prepare("
        SELECT id, installment_date, amount
        FROM re_lease_installments
        WHERE lease_id = ? AND company_id = ?
        ORDER BY installment_date ASC, id ASC
    ");
    $s->execute([$leaseId, $companyId]);
    $installments = $s->fetchAll(PDO::FETCH_ASSOC);

    foreach ($installments as $inst) {
        $conn->prepare("
            INSERT IGNORE INTO re_rent_recognition_schedule
                (company_id, lease_id, installment_id, recognition_date, amount, status)
            VALUES (?, ?, ?, ?, ?, 'pending')
        ")->execute([
            $companyId,
            $leaseId,
            $inst['id'],
            $inst['installment_date'],
            $inst['amount'],
        ]);
    }
}

/**
 * Run the monthly revenue recognition for all (or specific) leases.
 *
 * For each pending schedule row where recognition_date <= $upToDate:
 *   Dr. Deferred Rent Revenue [2410]   amount
 *     Cr. Rental Income [4110]         amount
 *
 * Returns ['recognised' => N, 'errors' => [...]]
 *
 * @param int    $companyId
 * @param int    $createdBy
 * @param string $upToDate   'Y-m-d'  (defaults to today)
 * @param int[]  $leaseIds   Optional filter — only process these leases
 */
function process_revenue_recognition($companyId, $createdBy, $upToDate = null, $leaseIds = []) {
    global $conn;

    if (!$upToDate) $upToDate = date('Y-m-d');

    $deferredAccount = find_account_by_code('2410', $companyId);
    if (!$deferredAccount) {
        return ['recognised' => 0, 'errors' => ['Required account (2410) not found']];
    }

    // Build query
    $leaseFilter = '';
    $params = [$companyId, $upToDate];
    if (!empty($leaseIds)) {
        $placeholders = implode(',', array_fill(0, count($leaseIds), '?'));
        $leaseFilter  = "AND rrs.lease_id IN ($placeholders)";
        $params       = array_merge($params, $leaseIds);
    }

    $stmt = $conn->prepare("
        SELECT rrs.*, l.lease_number, u.unit_type,
               t.first_name, t.last_name, t.company_name, t.tenant_type,
               COALESCE(li.vat_amount, 0) AS inst_vat_amount
        FROM re_rent_recognition_schedule rrs
        JOIN re_leases l ON l.id = rrs.lease_id
        LEFT JOIN re_units u ON u.id = l.unit_id
        JOIN re_tenants t ON t.id = l.tenant_id
        LEFT JOIN re_lease_installments li ON li.id = rrs.installment_id AND li.company_id = rrs.company_id
        WHERE rrs.company_id = ?
          AND rrs.status = 'pending'
          AND rrs.recognition_date <= ?
          AND rrs.deferred_payment_id IS NOT NULL
          AND NOT (
              l.status = 'terminated'
              AND l.termination_date IS NOT NULL
              AND rrs.recognition_date >= l.termination_date
          )
          $leaseFilter
        ORDER BY rrs.recognition_date ASC, rrs.id ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $recognised = 0;
    $errors     = [];

    $outputVatAccount = find_account_by_code('2310', $companyId);

    foreach ($rows as $row) {
        $amount = (float)$row['amount'];
        $vatPart = (float)($row['inst_vat_amount'] ?? 0);
        if ($vatPart < 0) {
            $vatPart = 0.0;
        }
        if ($vatPart > $amount) {
            $vatPart = $amount;
        }
        $basePart = round($amount - $vatPart, 2);
        $tenantName = $row['tenant_type'] === 'company'
            ? $row['company_name']
            : ($row['first_name'] . ' ' . $row['last_name']);
        $incomeAccount = find_account_by_code(determine_income_account('rent', $companyId, (string)($row['unit_type'] ?? '')), $companyId)
            ?: find_account_by_code('4110', $companyId);
        if (!$incomeAccount) {
            $errors[] = "Row #{$row['id']}: Rental income account not found.";
            continue;
        }
        $desc = "Revenue recognition - Lease {$row['lease_number']} - {$tenantName} - {$row['recognition_date']}";
        $ref  = "REC-{$row['id']}";

        $lines = [
            [
                'account_id' => $deferredAccount['id'],
                'debit'      => $amount,
                'credit'     => 0,
                'description' => $desc,
                'reference'  => $ref,
            ],
            [
                'account_id' => $incomeAccount['id'],
                'debit'      => 0,
                'credit'     => $basePart,
                'description' => $desc,
                'reference'  => $ref,
            ],
        ];
        if ($vatPart > 0.00001 && $outputVatAccount) {
            $lines[] = [
                'account_id' => $outputVatAccount['id'],
                'debit'      => 0,
                'credit'     => $vatPart,
                'description' => $desc . ' (Output VAT)',
                'reference'  => $ref,
            ];
        } elseif ($vatPart > 0.00001 && !$outputVatAccount) {
            $errors[] = "Row #{$row['id']}: Output VAT account (2310) not found; cannot split VAT on recognition.";
            continue;
        }

        $result = create_and_post_journal(
            $companyId, 'adjustment', 'recognition_schedule', (int)$row['id'],
            $lines, $desc, $row['recognition_date'], $createdBy
        );

        if ($result['success']) {
            $conn->prepare("
                UPDATE re_rent_recognition_schedule
                SET status = 'recognised',
                    recognition_journal_id = ?,
                    recognised_at = NOW(),
                    recognised_by = ?
                WHERE id = ?
            ")->execute([$result['journal_id'], $createdBy, $row['id']]);
            $recognised++;
        } else {
            $errors[] = "Row #{$row['id']} (Lease {$row['lease_number']}): " . $result['error'];
        }
    }

    return ['recognised' => $recognised, 'errors' => $errors];
}

// ═══════════════════════════════════════════════════════════════════════════════
// PRIORITY 2 — SECURITY DEPOSIT REFUND ON MOVE-OUT
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Post security deposit refund to accounting on move-out.
 *
 * Full refund:
 *   Dr. Security Deposits Payable [2200]   deposit_amount
 *     Cr. Bank [1210]                      deposit_amount
 *
 * With deductions (unpaid rent / damages):
 *   Dr. Security Deposits Payable [2200]   deposit_amount
 *     Cr. Bank [1210]                      refunded_amount
 *     Cr. Rental Income [4110]             deduction_amount
 *
 * @param int   $moveOutId       re_move_outs.id
 * @param int   $companyId
 * @param int   $createdBy
 * @param float $depositAmount   Total deposit held
 * @param float $refundAmount    Amount actually returned to tenant
 * @param float $deductionAmount Amount kept (damages / unpaid rent)
 * @param int   $bankAccountId  Which bank to credit for the refund (optional)
 * @return array ['success', 'journal_id', 'error']
 */
function post_deposit_refund_to_accounting($moveOutId, $companyId, $createdBy,
    $depositAmount, $refundAmount, $deductionAmount = 0.0, $bankAccountId = null
) {
    global $conn;

    $depositAmount   = (float)$depositAmount;
    $refundAmount    = (float)$refundAmount;
    $deductionAmount = (float)$deductionAmount;

    if ($depositAmount <= 0) {
        return ['success' => false, 'journal_id' => null, 'error' => 'No deposit amount to process'];
    }

    try {
        // Load move-out and lease details
        $stmt = $conn->prepare("
            SELECT mo.*, l.lease_number, l.tenant_id,
                   t.first_name, t.last_name, t.company_name, t.tenant_type
            FROM re_move_outs mo
            JOIN re_leases l ON l.id = mo.lease_id
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE mo.id = ? AND mo.company_id = ?
        ");
        $stmt->execute([$moveOutId, $companyId]);
        $moveOut = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$moveOut) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Move-out record not found'];
        }

        $tenantName = $moveOut['tenant_type'] === 'company'
            ? $moveOut['company_name']
            : ($moveOut['first_name'] . ' ' . $moveOut['last_name']);
        $ref = "MOVEOUT-{$moveOutId}";

        // Accounts
        $depositPayable = find_account_by_code('2200', $companyId);
        if (!$depositPayable) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Security Deposits Payable account (2200) not found'];
        }

        // Bank account for refund
        $bankAccount = null;
        if ($bankAccountId) {
            $s = $conn->prepare("
                SELECT coa.id, coa.account_code, coa.account_name
                FROM re_bank_accounts ba
                JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id
                WHERE ba.id = ? AND ba.company_id = ? AND ba.is_active = 1
            ");
            $s->execute([$bankAccountId, $companyId]);
            $bankAccount = $s->fetch(PDO::FETCH_ASSOC);
        }
        if (!$bankAccount) {
            $bankAccount = find_account_by_code('1210', $companyId) ?: find_account_by_code('1110', $companyId);
        }
        if (!$bankAccount && $refundAmount > 0) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Bank/Cash account not found'];
        }

        $desc = "Deposit refund on move-out - Lease {$moveOut['lease_number']} - {$tenantName}";

        // Build lines
        $lines = [];

        // Debit: release the liability
        $lines[] = [
            'account_id' => $depositPayable['id'],
            'debit'      => $depositAmount,
            'credit'     => 0,
            'description' => $desc,
            'reference'  => $ref,
        ];

        // Credit: bank for refunded amount
        if ($refundAmount > 0 && $bankAccount) {
            $lines[] = [
                'account_id' => $bankAccount['id'],
                'debit'      => 0,
                'credit'     => $refundAmount,
                'description' => "Deposit refund paid to tenant - {$tenantName}",
                'reference'  => $ref,
            ];
        }

        // Credit: income for any deductions retained
        if ($deductionAmount > 0) {
            $incomeAccount = find_account_by_code('4110', $companyId) ?: find_account_by_code('4400', $companyId);
            if ($incomeAccount) {
                $lines[] = [
                    'account_id' => $incomeAccount['id'],
                    'debit'      => 0,
                    'credit'     => $deductionAmount,
                    'description' => "Deposit deduction retained - {$tenantName}",
                    'reference'  => $ref,
                ];
            }
        }

        $moveOutDate = $moveOut['move_out_date'] ?? date('Y-m-d');
        $result = create_and_post_journal(
            $companyId, 'refund', 'move_out', $moveOutId,
            $lines, $desc, $moveOutDate, $createdBy
        );

        if ($result['success']) {
            log_accounting_posting($companyId, $result['journal_id'], 'move_out', $moveOutId, 'posted', null);
        } else {
            log_accounting_posting($companyId, null, 'move_out', $moveOutId, 'failed', $result['error']);
        }

        return $result;

    } catch (Exception $e) {
        log_accounting_posting($companyId, null, 'move_out', $moveOutId, 'failed', $e->getMessage());
        return ['success' => false, 'journal_id' => null, 'error' => $e->getMessage()];
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// PRIORITY 3 — PAYMENT EDIT: REVERSE ORIGINAL JOURNAL THEN REPOST
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Reverse the existing accounting journal for a payment, then repost with
 * the current (updated) payment data from the database.
 *
 * Call this from payment_edit.php AFTER saving the new values to re_payments.
 *
 * @param int      $paymentId
 * @param int      $companyId
 * @param int|null $userId
 * @param int|null $bankAccountId
 * @return array   ['success', 'journal_id', 'error']
 */
function reverse_and_repost_payment($paymentId, $companyId, $userId = null, $bankAccountId = null) {
    global $conn;

    try {
        // Find the existing posted journal for this payment
        $stmt = $conn->prepare("
            SELECT id, is_reversed
            FROM re_journal_headers
            WHERE reference_type = 'payment'
              AND reference_id   = ?
              AND company_id     = ?
              AND is_posted      = 1
              AND is_reversed    = 0
              AND journal_type  != 'reversal'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$paymentId, $companyId]);
        $original = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($original) {
            // Reverse the old journal
            // reverse_journal($journalId, $reason, $reversedBy, $reversalDate)
            $reverseResult = reverse_journal(
                $original['id'],
                "Reversal — payment #{$paymentId} edited",
                $userId,
                null  // use original journal's date so the reversal lands in the same period
            );
            if (!$reverseResult['success']) {
                return ['success' => false, 'journal_id' => null,
                    'error' => 'Could not reverse original journal: ' . $reverseResult['error']];
            }
        }

        // Check if the lease uses deferred revenue
        $stmt = $conn->prepare("
            SELECT l.deferred_revenue_mode
            FROM re_payments p
            JOIN re_leases l ON l.id = p.lease_id
            WHERE p.id = ? AND p.company_id = ?
        ");
        $stmt->execute([$paymentId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $deferredMode = !empty($row['deferred_revenue_mode']);

        if ($deferredMode) {
            // Reset any pending recognition schedule rows funded by this payment
            // (they will be re-created by the repost)
            $conn->prepare("
                DELETE FROM re_rent_recognition_schedule
                WHERE deferred_payment_id = ? AND status = 'pending' AND company_id = ?
            ")->execute([$paymentId, $companyId]);

            return post_deferred_payment_to_accounting($paymentId, $companyId, $userId, $bankAccountId);
        } else {
            return post_payment_to_accounting($paymentId, $companyId, $userId, $bankAccountId);
        }

    } catch (Exception $e) {
        return ['success' => false, 'journal_id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Post a penalty billing item payment to accounting.
 *
 * Journal entry:
 *   Debit : Bank / Cash (based on payment method)
 *   Credit: Late Fee Income (account 4200) — auto-created if absent
 *
 * @param int      $billingItemId  re_billing_items.id that was paid
 * @param int      $paymentId      re_payments.id the collection belongs to
 * @param int      $companyId
 * @param int|null $createdBy
 * @param int|null $bankAccountId  re_bank_accounts.id (optional, falls back to payment method)
 * @return array ['success'=>bool, 'journal_id'=>int|null, 'error'=>string|null]
 */
function post_penalty_payment_to_accounting(int $billingItemId, int $paymentId, int $companyId, $createdBy = null, $bankAccountId = null): array {
    global $conn;

    try {
        // Load billing item + payment + tenant details
        $stmt = $conn->prepare("
            SELECT bi.id, bi.item_name, bi.paid_amount, bi.due_date, bi.lease_id, bi.penalty_rule_id,
                   pr.penalty_type,
                   p.payment_method, p.payment_date, p.receipt_number, p.bank_account_id AS p_bank_id,
                   l.tenant_id, t.first_name, t.last_name, t.company_name, t.tenant_type
            FROM re_billing_items bi
            LEFT JOIN re_penalty_rules pr ON pr.id = bi.penalty_rule_id
            JOIN re_payments p ON p.id = ?
            JOIN re_leases l ON l.id = bi.lease_id
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE bi.id = ? AND bi.company_id = ?
        ");
        $stmt->execute([$paymentId, $billingItemId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['success' => false, 'journal_id' => null, 'error' => "Billing item {$billingItemId} not found"];
        }

        $amount = (float)$row['paid_amount'];
        if ($amount <= 0) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Paid amount is zero'];
        }

        // Resolve bank / cash account
        $effectiveBankId = $bankAccountId ?: $row['p_bank_id'];
        $bankAccount = null;
        if ($effectiveBankId) {
            $s2 = $conn->prepare("
                SELECT coa.id, coa.account_code, coa.account_name
                FROM re_bank_accounts ba
                JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id
                WHERE ba.id = ? AND ba.company_id = ? AND ba.is_active = 1
            ");
            $s2->execute([$effectiveBankId, $companyId]);
            $bankAccount = $s2->fetch(PDO::FETCH_ASSOC);
        }
        if (!$bankAccount) {
            $code = determine_bank_account($row['payment_method'], $companyId);
            $bankAccount = find_account_by_code($code, $companyId) ?: find_account_by_code('1110', $companyId);
        }
        if (!$bankAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Bank/Cash account not found'];
        }

        // Find Penalty Income account (4300). Fall back to creating it if absent.
        $incomeAccount = find_account_by_code('4300', $companyId);
        if (!$incomeAccount) {
            $conn->prepare("
                INSERT IGNORE INTO re_chart_of_accounts
                    (company_id, account_code, account_name, account_type, normal_balance, is_active, description)
                VALUES (?, '4300', 'Penalty Income', 'income', 'credit', 1,
                        'Late fees and penalties collected')
            ")->execute([$companyId]);
            $incomeAccount = find_account_by_code('4300', $companyId);
        }
        if (!$incomeAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Penalty Income account (4300) could not be found or created'];
        }

        $tenantName = ($row['tenant_type'] === 'company')
            ? $row['company_name']
            : trim($row['first_name'] . ' ' . $row['last_name']);
        $penaltyLabel = ($row['penalty_type'] === 'bounced_cheque') ? 'Bounced Fee' : 'Late Fee';
        $ref  = $row['receipt_number'] ?: "PAY-{$paymentId}";
        $desc = "{$penaltyLabel} collected — {$tenantName} — {$row['item_name']}";

        $journalResult = create_journal_entry($companyId, [
            'journal_date'      => $row['payment_date'],
            'reference_number'  => $ref,
            'description'       => $desc,
            'journal_type'      => 'payment',
            'source_type'       => 'billing_item',
            'source_id'         => $billingItemId,
            'created_by'        => $createdBy,
            'lines'             => [
                [
                    'account_id'  => $bankAccount['id'],
                    'description' => "Received {$penaltyLabel} — {$tenantName}",
                    'debit'       => $amount,
                    'credit'      => 0,
                ],
                [
                    'account_id'  => $incomeAccount['id'],
                    'description' => "{$penaltyLabel} income — {$tenantName}",
                    'debit'       => 0,
                    'credit'      => $amount,
                ],
            ],
        ]);

        return $journalResult;

    } catch (Exception $e) {
        return ['success' => false, 'journal_id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Post a tenant refund to accounting.
 *
 * Journal:
 *   Debit : Deferred Revenue / Tenant Credit Liability (2410)
 *   Credit: Bank / Cash (based on refund method)
 *
 * @param int      $refundId       re_tenant_refunds.id
 * @param int      $companyId
 * @param int|null $createdBy
 * @return array ['success'=>bool, 'journal_id'=>int|null, 'error'=>string|null]
 */
function post_refund_to_accounting(int $refundId, int $companyId, $createdBy = null): array {
    global $conn;
    try {
        $stmt = $conn->prepare("
            SELECT r.*, l.tenant_id,
                   t.first_name, t.last_name, t.company_name, t.tenant_type
            FROM re_tenant_refunds r
            JOIN re_leases l   ON l.id  = r.lease_id
            JOIN re_tenants t  ON t.id  = l.tenant_id
            WHERE r.id = ? AND r.company_id = ?
        ");
        $stmt->execute([$refundId, $companyId]);
        $refund = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$refund) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Refund record not found'];
        }

        $amount = (float)$refund['amount'];
        if ($amount <= 0) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Refund amount is zero'];
        }

        // Resolve the bank / cash account
        $bankAccount = null;
        if ($refund['bank_account_id']) {
            $s = $conn->prepare("
                SELECT coa.id, coa.account_code, coa.account_name
                FROM re_bank_accounts ba
                JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id
                WHERE ba.id = ? AND ba.company_id = ? AND ba.is_active = 1
            ");
            $s->execute([$refund['bank_account_id'], $companyId]);
            $bankAccount = $s->fetch(PDO::FETCH_ASSOC);
        }
        if (!$bankAccount) {
            $code        = determine_bank_account($refund['payment_method'], $companyId);
            $bankAccount = find_account_by_code($code, $companyId) ?: find_account_by_code('1110', $companyId);
        }
        if (!$bankAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Bank/Cash account not found'];
        }

        // Deferred Revenue / Tenant Credit liability (2410)
        $liabilityAccount = find_account_by_code('2410', $companyId);
        if (!$liabilityAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Deferred Revenue account (2410) not found'];
        }

        $tenantName = ($refund['tenant_type'] === 'company')
            ? $refund['company_name']
            : trim($refund['first_name'] . ' ' . $refund['last_name']);
        $ref    = $refund['receipt_number'] ?: "REFUND-{$refundId}";
        $reason = $refund['refund_reason'] ?: 'Overpayment refund';
        $desc   = "Refund to tenant {$tenantName} — {$reason}";

        $journalResult = create_journal_entry($companyId, [
            'journal_date'     => $refund['refund_date'],
            'reference_number' => $ref,
            'description'      => $desc,
            'journal_type'     => 'payment',
            'source_type'      => 'refund',
            'source_id'        => $refundId,
            'created_by'       => $createdBy,
            'lines'            => [
                [
                    'account_id'  => $liabilityAccount['id'],
                    'description' => "Deferred revenue / tenant credit cleared — {$tenantName}",
                    'debit'       => $amount,
                    'credit'      => 0,
                ],
                [
                    'account_id'  => $bankAccount['id'],
                    'description' => "Refund paid to {$tenantName}",
                    'debit'       => 0,
                    'credit'      => $amount,
                ],
            ],
        ]);

        // Store the journal_id back on the refund record
        if ($journalResult['success'] && $journalResult['journal_id']) {
            $conn->prepare("UPDATE re_tenant_refunds SET journal_id = ? WHERE id = ?")
                 ->execute([$journalResult['journal_id'], $refundId]);
        }

        return $journalResult;

    } catch (Exception $e) {
        return ['success' => false, 'journal_id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Helper: Log accounting posting
 */
function log_accounting_posting($companyId, $journalId, $sourceType, $sourceId, $status, $errorMessage = null) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO re_accounting_postings 
            (company_id, journal_id, source_type, source_id, posting_status, error_message)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$companyId, $journalId, $sourceType, $sourceId, $status, $errorMessage]);
    } catch (Exception $e) {
        error_log("Error logging accounting posting: " . $e->getMessage());
    }
}
