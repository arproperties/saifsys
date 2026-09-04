<?php
/**
 * Construction Module — Accounting Integration
 * Posts construction transactions to the shared accounting engine.
 * All postings use company_id so construction company data is separate from real estate.
 */

require_once dirname(__DIR__, 3) . '/modules/realestate/accounting/accounting_engine.php';

/** Account codes used for construction (per company) */
const CO_ACCOUNT_CIP = '1515';           // Construction in Progress (Asset)
const CO_ACCOUNT_PROJECT_COGS = '5125'; // Project COGS (Expense)
const CO_ACCOUNT_BANK = '1210';         // Bank
const CO_ACCOUNT_CASH = '1110';         // Cash on Hand
const CO_ACCOUNT_CONTRACTOR_PAYABLE = '2145'; // Contractor Payable
const CO_ACCOUNT_RETENTION_PAYABLE = '2125';  // Retention Payable
const CO_ACCOUNT_SUPPLIER_PAYABLE = '2110';   // Supplier / Trade Payable
const CO_ACCOUNT_INPUT_VAT = '2130';          // Input VAT (VAT Recoverable)
const CO_ACCOUNT_AR = '1310';                 // Accounts Receivable - Customers/Tenants
const CO_ACCOUNT_SECURITY_DEPOSITS = '2200';  // Tenant Security Deposits
const CO_ACCOUNT_OUTPUT_VAT = '2310';         // Output VAT
const CO_ACCOUNT_PREPAID_OUTPUT_VAT = '2330'; // VAT collected in advance (Separate VAT cash)
const CO_ACCOUNT_DEFERRED_RENT_REVENUE = '2215'; // Deferred Rent Revenue
const CO_ACCOUNT_CLIENT_ADVANCES = '2410';     // Client / tenant credit (overpayments)
const CO_ACCOUNT_CONSTRUCTION_INCOME = '4105'; // Construction/Manual Income
const CO_ACCOUNT_CAMP_MANAGEMENT_INCOME = '4115';
const CO_ACCOUNT_SHOP_RENTAL_INCOME = '4120';
const CO_ACCOUNT_MAINTENANCE_INCOME = '4130';
const CO_ACCOUNT_SHOP_COMMISSION_INCOME = '4140'; // Tenant commission / letting fee income (Madar shop rentals)
const CO_ACCOUNT_SHOP_TERMINATION_PENALTY = '4150'; // Early termination penalty income
const CO_ACCOUNT_DEPOSIT_RECOVERY_INCOME = '4160'; // Damage / utility / cleaning / forfeiture recoveries
const CO_ACCOUNT_SHOP_KEY_MONEY = '4170'; // One-time Key Money income (shop rental)
const CO_ACCOUNT_AGENT_COMMISSION_EXPENSE = '5140';

/**
 * Post contractor payment to accounting.
 * Debit CIP (OWNER) or Project COGS (CLIENT), Credit Bank, Credit Retention Payable.
 *
 * @param int $paymentId co_contractor_payments.id
 * @param int $companyId
 * @param int|null $createdBy
 * @return array ['success' => bool, 'journal_id' => int|null, 'error' => string|null]
 */
function co_post_contractor_payment_to_accounting($paymentId, $companyId, $createdBy = null) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT cp.*, pc.project_id, pc.contractor_id, pc.retention_pct,
               p.project_type, p.project_name, c.contractor_name
        FROM co_contractor_payments cp
        JOIN co_project_contractors pc ON pc.id = cp.project_contractor_id
        JOIN co_projects p ON p.id = pc.project_id
        JOIN co_contractors c ON c.id = pc.contractor_id
        WHERE cp.id = ? AND cp.company_id = ?
    ");
    $stmt->execute([$paymentId, $companyId]);
    $pay = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pay) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Payment not found'];
    }

    $debitAccountCode = ($pay['project_type'] === 'OWNER') ? CO_ACCOUNT_CIP : CO_ACCOUNT_PROJECT_COGS;
    $debitAccount = find_account_by_code($debitAccountCode, $companyId);
    $bankAccount = find_account_by_code(CO_ACCOUNT_BANK, $companyId);
    if (!$bankAccount) {
        $bankAccount = find_account_by_code(CO_ACCOUNT_CASH, $companyId);
    }
    $retentionAccount = find_account_by_code(CO_ACCOUNT_RETENTION_PAYABLE, $companyId);

    if (!$debitAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => "Account $debitAccountCode not found. Run construction chart of accounts setup."];
    }
    if (!$bankAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Bank/Cash account (1210 or 1110) not found.'];
    }

    $amount = (float)$pay['amount'];
    $retention_held = (float)$pay['retention_held'];
    $net_paid = (float)$pay['net_paid'];
    $payment_date = $pay['payment_date'];
    $desc = "Contractor payment: " . $pay['contractor_name'] . " - " . $pay['project_name'];
    $ref = $pay['reference'] ?: 'CO-PAY-' . $paymentId;

    $lines = [];
    $lines[] = [
        'account_id' => $debitAccount['id'],
        'debit' => $amount,
        'credit' => 0,
        'description' => $desc,
        'reference' => $ref
    ];
    $lines[] = [
        'account_id' => $bankAccount['id'],
        'debit' => 0,
        'credit' => $net_paid,
        'description' => $desc . ' (net paid)',
        'reference' => $ref
    ];
    if ($retention_held > 0 && $retentionAccount) {
        $lines[] = [
            'account_id' => $retentionAccount['id'],
            'debit' => 0,
            'credit' => $retention_held,
            'description' => $desc . ' (retention held)',
            'reference' => $ref
        ];
    }

    $result = create_and_post_journal(
        $companyId,
        'payment',
        'co_contractor_payment',
        $paymentId,
        $lines,
        $desc,
        $payment_date,
        $createdBy
    );

    return $result;
}

/**
 * Post project cost to accounting.
 * Debit CIP (OWNER) or Project COGS (CLIENT), Credit Bank.
 *
 * @param int $costId co_project_costs.id
 * @param int $companyId
 * @param int|null $createdBy
 * @return array ['success' => bool, 'journal_id' => int|null, 'error' => string|null]
 */
function co_post_project_cost_to_accounting($costId, $companyId, $createdBy = null) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT co.*, p.project_type, p.project_name
        FROM co_project_costs co
        JOIN co_projects p ON p.id = co.project_id
        WHERE co.id = ? AND co.company_id = ?
    ");
    $stmt->execute([$costId, $companyId]);
    $cost = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cost) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Project cost not found'];
    }

    $debitAccountCode = ($cost['project_type'] === 'OWNER') ? CO_ACCOUNT_CIP : CO_ACCOUNT_PROJECT_COGS;
    $debitAccount = find_account_by_code($debitAccountCode, $companyId);
    $bankAccount = find_account_by_code(CO_ACCOUNT_BANK, $companyId);
    if (!$bankAccount) {
        $bankAccount = find_account_by_code(CO_ACCOUNT_CASH, $companyId);
    }

    if (!$debitAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => "Account $debitAccountCode not found. Run construction chart of accounts setup."];
    }
    if (!$bankAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Bank/Cash account (1210 or 1110) not found.'];
    }

    $amount = (float)$cost['amount'];
    $cost_date = $cost['cost_date'];
    $desc = "Project cost: " . $cost['project_name'] . " - " . ($cost['description'] ?: $cost['cost_type']);
    $ref = $cost['reference'] ?: 'CO-COST-' . $costId;

    $lines = [
        [
            'account_id' => $debitAccount['id'],
            'debit' => $amount,
            'credit' => 0,
            'description' => $desc,
            'reference' => $ref
        ],
        [
            'account_id' => $bankAccount['id'],
            'debit' => 0,
            'credit' => $amount,
            'description' => $desc,
            'reference' => $ref
        ]
    ];

    return create_and_post_journal(
        $companyId,
        'manual',
        'co_project_cost',
        $costId,
        $lines,
        $desc,
        $cost_date,
        $createdBy
    );
}

/**
 * Post retention release to accounting.
 * Debit Retention Payable, Credit Bank.
 *
 * @param int $releaseId co_retention_releases.id
 * @param int $companyId
 * @param int|null $createdBy
 * @return array ['success' => bool, 'journal_id' => int|null, 'error' => string|null]
 */
function co_post_retention_release_to_accounting($releaseId, $companyId, $createdBy = null) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT rr.*, pc.project_id, pc.contractor_id, p.project_name, c.contractor_name
        FROM co_retention_releases rr
        JOIN co_project_contractors pc ON pc.id = rr.project_contractor_id
        JOIN co_projects p ON p.id = pc.project_id
        JOIN co_contractors c ON c.id = pc.contractor_id
        WHERE rr.id = ? AND rr.company_id = ?
    ");
    $stmt->execute([$releaseId, $companyId]);
    $rr = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rr) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Retention release not found'];
    }

    $retentionAccount = find_account_by_code(CO_ACCOUNT_RETENTION_PAYABLE, $companyId);
    $bankAccount = find_account_by_code(CO_ACCOUNT_BANK, $companyId);
    if (!$bankAccount) {
        $bankAccount = find_account_by_code(CO_ACCOUNT_CASH, $companyId);
    }

    if (!$retentionAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Retention Payable account (2125) not found.'];
    }
    if (!$bankAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Bank/Cash account (1210 or 1110) not found.'];
    }

    $amount = (float)$rr['amount'];
    $release_date = $rr['release_date'];
    $desc = 'Retention release: ' . $rr['contractor_name'] . ' - ' . $rr['project_name'];
    $ref = 'CO-RET-' . $releaseId;

    $lines = [
        [
            'account_id' => $retentionAccount['id'],
            'debit' => $amount,
            'credit' => 0,
            'description' => $desc,
            'reference' => $ref
        ],
        [
            'account_id' => $bankAccount['id'],
            'debit' => 0,
            'credit' => $amount,
            'description' => $desc,
            'reference' => $ref
        ]
    ];

    return create_and_post_journal(
        $companyId,
        'payment',
        'co_retention_release',
        $releaseId,
        $lines,
        $desc,
        $release_date,
        $createdBy
    );
}

/**
 * Post supplier invoice to accounting.
 * Debit Expense (5125) or CIP (1515) for subtotal, Debit Input VAT (2130) for vat_amount, Credit Supplier Payable (2110) for total.
 *
 * @param int $invoiceId co_supplier_invoices.id
 * @param int $companyId
 * @param int|null $createdBy
 * @return array ['success' => bool, 'journal_id' => int|null, 'error' => string|null]
 */
function co_post_supplier_invoice_to_accounting($invoiceId, $companyId, $createdBy = null) {
    global $conn;

    $companyId = (int)$companyId;
    $invoiceId = (int)$invoiceId;
    if ($companyId <= 0 || $invoiceId <= 0) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Company and invoice are required.'];
    }
    if (!function_exists('co_supplier_ap_audit')) {
        require_once __DIR__ . '/construction_supplier_ap_helpers.php';
    }

    $stmt = $conn->prepare("
        SELECT si.*, s.supplier_name,
               p.project_type, p.project_name
        FROM co_supplier_invoices si
        JOIN co_suppliers s ON s.id = si.supplier_id
        LEFT JOIN co_projects p ON p.id = si.project_id
        WHERE si.id = ? AND si.company_id = ?
    ");
    $stmt->execute([$invoiceId, $companyId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Supplier invoice not found'];
    }
    if (!empty($inv['journal_id'])) {
        return [
            'success' => true,
            'journal_id' => (int)$inv['journal_id'],
            'already_posted' => true,
            'error' => null,
        ];
    }
    if (function_exists('co_supplier_invoice_status') && co_supplier_invoice_status($inv) === 'voided') {
        return ['success' => false, 'journal_id' => null, 'error' => 'Voided invoices cannot be posted.'];
    }

    $total = (float)$inv['total'];
    $inputVatAccount = find_account_by_code(CO_ACCOUNT_INPUT_VAT, $companyId);
    $supplierPayableAccount = find_account_by_code(CO_ACCOUNT_SUPPLIER_PAYABLE, $companyId);
    if (!$supplierPayableAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Supplier Payable account (2110) not found. Run construction COA setup.'];
    }

    $invoice_date = $inv['invoice_date'];
    $desc = 'Supplier invoice: ' . $inv['supplier_name'] . ' - ' . ($inv['invoice_number'] ?? '');
    $ref = $inv['reference'] ?: 'CO-SINV-' . $invoiceId;

    $lines = [];
    $invoiceLines = function_exists('co_supplier_invoice_lines') ? co_supplier_invoice_lines($conn, $companyId, (int)$invoiceId) : [];
    if ($invoiceLines) {
        foreach ($invoiceLines as $invoiceLine) {
            $accountId = (int)($invoiceLine['expense_account_id'] ?? 0);
            if ($accountId <= 0) {
                return ['success' => false, 'journal_id' => null, 'error' => 'Expense account missing on supplier invoice line.'];
            }
            $accountStmt = $conn->prepare("
                SELECT id
                FROM re_chart_of_accounts
                WHERE id = ? AND company_id = ? AND is_active = 1 AND is_header = 0
                LIMIT 1
            ");
            $accountStmt->execute([$accountId, $companyId]);
            if (!$accountStmt->fetchColumn()) {
                return ['success' => false, 'journal_id' => null, 'error' => 'Invalid expense account on supplier invoice line.'];
            }
            $lineSubtotal = (float)($invoiceLine['subtotal'] ?? 0);
            if ($lineSubtotal > 0) {
                $lines[] = [
                    'account_id' => $accountId,
                    'debit' => $lineSubtotal,
                    'credit' => 0,
                    'description' => ($invoiceLine['description'] ?: $desc) . ' (expense)',
                    'reference' => $ref
                ];
            }
        }
    } else {
        $expenseAccount = null;
        if (!empty($inv['expense_account_id'])) {
            $accountStmt = $conn->prepare("
                SELECT id, account_code, account_name
                FROM re_chart_of_accounts
                WHERE id = ? AND company_id = ? AND is_active = 1 AND is_header = 0
                LIMIT 1
            ");
            $accountStmt->execute([(int)$inv['expense_account_id'], $companyId]);
            $expenseAccount = $accountStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$expenseAccount) {
                return ['success' => false, 'journal_id' => null, 'error' => 'Selected expense account is not valid for this company.'];
            }
        }
        if (!$expenseAccount) {
            $expenseAccountCode = ($inv['project_id'] && ($inv['project_type'] ?? '') === 'OWNER') ? CO_ACCOUNT_CIP : CO_ACCOUNT_PROJECT_COGS;
            if (!$inv['project_id']) {
                $expenseAccountCode = CO_ACCOUNT_PROJECT_COGS;
            }
            $expenseAccount = find_account_by_code($expenseAccountCode, $companyId);
        }
        if (!$expenseAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Expense account not found. Choose a valid account or run construction chart of accounts setup.'];
        }
        $lines[] = [
            'account_id' => $expenseAccount['id'],
            'debit' => (float)$inv['subtotal'],
            'credit' => 0,
            'description' => $desc . ' (expense)',
            'reference' => $ref
        ];
    }
    $vat_amount = (float)$inv['vat_amount'];
    $linkedAdvanceVat = 0.0;
    if (!function_exists('co_supplier_advance_vat_linked_to_invoice')
        && is_file(__DIR__ . '/construction_supplier_advance_vat_helpers.php')) {
        require_once __DIR__ . '/construction_supplier_advance_vat_helpers.php';
    }
    if (function_exists('co_supplier_advance_vat_linked_to_invoice')) {
        $linkedAdvanceVat = co_supplier_advance_vat_linked_to_invoice($conn, $companyId, $invoiceId);
    }
    if ($linkedAdvanceVat > $vat_amount + 0.005) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Linked advance VAT exceeds invoice VAT. Adjust VAT document links before posting.'];
    }
    $remainingInputVat = round(max(0, $vat_amount - $linkedAdvanceVat), 2);
    if ($remainingInputVat > 0.005 && $inputVatAccount) {
        $lines[] = [
            'account_id' => $inputVatAccount['id'],
            'debit' => $remainingInputVat,
            'credit' => 0,
            'description' => $desc . ($linkedAdvanceVat > 0.005 ? ' (input VAT net of advance VAT)' : ' (input VAT)'),
            'reference' => $ref
        ];
    } elseif ($remainingInputVat > 0.005 && !$inputVatAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Input VAT account (2130) not found. Run construction COA setup.'];
    }
    $apCredit = round($total - $linkedAdvanceVat, 2);
    $lines[] = [
        'account_id' => $supplierPayableAccount['id'],
        'debit' => 0,
        'credit' => $apCredit,
        'description' => $desc . ($linkedAdvanceVat > 0.005 ? ' (net of advance VAT)' : ''),
        'reference' => $ref
    ];

    $result = create_and_post_journal(
        $companyId,
        'invoice',
        'co_supplier_invoice',
        $invoiceId,
        $lines,
        $desc,
        $invoice_date,
        $createdBy
    );
    if (!empty($result['success']) && !empty($result['journal_id'])) {
        if (function_exists('co_supplier_invoice_lifecycle_ready') && co_supplier_invoice_lifecycle_ready($conn)) {
            $conn->prepare("UPDATE co_supplier_invoices SET journal_id = ?, status = 'posted' WHERE id = ? AND company_id = ? AND COALESCE(status,'draft') <> 'voided'")
                ->execute([(int)$result['journal_id'], $invoiceId, $companyId]);
        }
        co_supplier_ap_audit(
            $conn,
            $companyId,
            (int)$inv['supplier_id'],
            $invoiceId,
            null,
            'invoice_posted',
            null,
            (string)($inv['invoice_number'] ?? ''),
            $total,
            'Supplier invoice posted to GL',
            $createdBy ? (int)$createdBy : null,
            'supplier_invoice',
            (int)$result['journal_id']
        );
    }
    return $result;
}

/**
 * Post supplier payment to accounting.
 * Allocated portion: Debit Supplier Payable (2110).
 * Unallocated remainder (advance_amount): Debit configurable Supplier Advances asset (default 1410).
 * Credit Bank for full payment amount.
 *
 * @param int $paymentId co_supplier_payments.id
 * @param int $companyId
 * @param int|null $createdBy
 * @return array ['success' => bool, 'journal_id' => int|null, 'error' => string|null]
 */
function co_post_supplier_payment_to_accounting($paymentId, $companyId, $createdBy = null) {
    global $conn;

    $companyId = (int)$companyId;
    $paymentId = (int)$paymentId;
    if ($companyId <= 0 || $paymentId <= 0) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Company and payment are required.'];
    }
    if (!function_exists('co_supplier_ap_audit')) {
        require_once __DIR__ . '/construction_supplier_ap_helpers.php';
    }
    if (!function_exists('co_supplier_advance_schema_ready')) {
        require_once __DIR__ . '/construction_supplier_advance_helpers.php';
    }

    $stmt = $conn->prepare("
        SELECT sp.*, s.supplier_name
        FROM co_supplier_payments sp
        JOIN co_suppliers s ON s.id = sp.supplier_id
        WHERE sp.id = ? AND sp.company_id = ?
    ");
    $stmt->execute([$paymentId, $companyId]);
    $pay = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pay) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Supplier payment not found'];
    }
    if (!empty($pay['journal_id'])) {
        return [
            'success' => true,
            'journal_id' => (int)$pay['journal_id'],
            'already_posted' => true,
            'error' => null,
        ];
    }

    $supplierPayableAccount = find_account_by_code(CO_ACCOUNT_SUPPLIER_PAYABLE, $companyId);
    $bankAccount = null;
    if (!empty($pay['pay_account_id'])) {
        $accountStmt = $conn->prepare("
            SELECT id, account_code, account_name, account_type, normal_balance
            FROM re_chart_of_accounts
            WHERE id = ? AND company_id = ? AND is_active = 1 AND is_header = 0
            LIMIT 1
        ");
        $accountStmt->execute([(int)$pay['pay_account_id'], $companyId]);
        $bankAccount = $accountStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$bankAccount) {
        $bankAccount = find_account_by_code(CO_ACCOUNT_BANK, $companyId);
        if (!$bankAccount) {
            $bankAccount = find_account_by_code(CO_ACCOUNT_CASH, $companyId);
        }
    }

    if (!$supplierPayableAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Supplier Payable account (2110) not found. Run construction COA setup.'];
    }
    if (!$bankAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Paid From account not found. Choose a valid cash/bank chart-of-account.'];
    }

    $amount = round((float)$pay['amount'], 2);
    $advanceAmount = 0.0;
    if (co_supplier_advance_schema_ready($conn) && array_key_exists('advance_amount', $pay)) {
        $advanceAmount = round((float)$pay['advance_amount'], 2);
    }
    if ($advanceAmount < -0.005) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Invalid advance_amount on payment.'];
    }
    if ($advanceAmount < 0) {
        $advanceAmount = 0.0;
    }

    $allocated = 0.0;
    if (function_exists('co_supplier_allocations_ready') && co_supplier_allocations_ready($conn)) {
        $allocStmt = $conn->prepare("
            SELECT COALESCE(SUM(allocated_amount), 0)
            FROM co_supplier_payment_allocations
            WHERE company_id = ? AND payment_id = ?
        ");
        $allocStmt->execute([$companyId, $paymentId]);
        $allocated = round((float)$allocStmt->fetchColumn(), 2);
    }

    // When advance schema ready: allocated + advance must equal payment amount.
    // Legacy rows (no advance column usage): treat full amount as AP clearance.
    if (co_supplier_advance_schema_ready($conn)) {
        if (abs(($allocated + $advanceAmount) - $amount) > 0.005) {
            // Auto-heal: remainder after allocations is advance.
            $advanceAmount = round(max(0, $amount - $allocated), 2);
            $conn->prepare("UPDATE co_supplier_payments SET advance_amount = ? WHERE id = ? AND company_id = ?")
                ->execute([$advanceAmount, $paymentId, $companyId]);
        }
        if (abs(($allocated + $advanceAmount) - $amount) > 0.005) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Payment amount must equal invoice allocations plus advance_amount.'];
        }
    } else {
        $allocated = $amount;
        $advanceAmount = 0.0;
    }

    $payment_date = $pay['payment_date'];
    $desc = 'Supplier payment: ' . $pay['supplier_name'];
    $ref = $pay['reference'] ?: 'CO-SPAY-' . $paymentId;
    $supplierId = (int)$pay['supplier_id'];

    $lines = [];
    if ($allocated > 0.005) {
        $lines[] = [
            'account_id' => $supplierPayableAccount['id'],
            'debit' => $allocated,
            'credit' => 0,
            'description' => $advanceAmount > 0.005 ? ($desc . ' (AP)') : $desc,
            'reference' => $ref
        ];
    }
    if ($advanceAmount > 0.005) {
        $advanceAccount = co_supplier_advance_account($conn, $companyId);
        if (!$advanceAccount) {
            return [
                'success' => false,
                'journal_id' => null,
                'error' => 'Supplier Advances account (' . co_supplier_advance_account_code($conn)
                    . ') not found. Configure it under Construction Setup Accounts or run Phase 2 migration.',
            ];
        }
        $lines[] = [
            'account_id' => $advanceAccount['id'],
            'debit' => $advanceAmount,
            'credit' => 0,
            'description' => $desc . ' (Advance)',
            'reference' => $ref
        ];
    }
    if (!$lines) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Payment has no allocatable or advance amount.'];
    }
    $lines[] = [
        'account_id' => $bankAccount['id'],
        'debit' => 0,
        'credit' => $amount,
        'description' => $desc,
        'reference' => $ref
    ];

    $result = create_and_post_journal(
        $companyId,
        'payment',
        'co_supplier_payment',
        $paymentId,
        $lines,
        $desc,
        $payment_date,
        $createdBy
    );
    if (!empty($result['success']) && !empty($result['journal_id'])) {
        if ($advanceAmount > 0.005) {
            try {
                co_supplier_advance_on_payment_posted(
                    $conn,
                    $companyId,
                    $supplierId,
                    $paymentId,
                    $advanceAmount,
                    $createdBy ? (int)$createdBy : null,
                    (int)$result['journal_id']
                );
            } catch (Throwable $e) {
                return [
                    'success' => false,
                    'journal_id' => (int)$result['journal_id'],
                    'error' => 'Payment journal posted but advance balance update failed: ' . $e->getMessage(),
                ];
            }
        }
        co_supplier_ap_audit(
            $conn,
            $companyId,
            $supplierId,
            null,
            $paymentId,
            'payment_posted',
            $advanceAmount > 0.005 ? (string)$advanceAmount : null,
            (string)($pay['reference'] ?? ''),
            $amount,
            $advanceAmount > 0.005
                ? 'Supplier payment posted with advance remainder'
                : 'Supplier payment posted to GL',
            $createdBy ? (int)$createdBy : null,
            'supplier_payment',
            (int)$result['journal_id']
        );
    }
    return $result;
}

/**
 * Post Construction client/income invoice to accounting.
 * Debit AR, Credit Income, Credit Output VAT.
 */
function co_post_client_invoice_to_accounting($invoiceId, $companyId, $createdBy = null) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT i.*, c.client_name, p.project_name
        FROM co_client_invoices i
        JOIN co_clients c ON c.id = i.client_id
        LEFT JOIN co_projects p ON p.id = i.project_id
        WHERE i.id = ? AND i.company_id = ?
    ");
    $stmt->execute([$invoiceId, $companyId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Client invoice not found'];
    }
    if (!empty($inv['journal_id'])) {
        return ['success' => true, 'journal_id' => (int)$inv['journal_id'], 'error' => null];
    }

    $arAccount = find_account_by_code(CO_ACCOUNT_AR, $companyId);
    $incomeAccount = null;
    $deferredRent = false;
    if (($inv['source_type'] ?? '') === 'shop_rental' && !empty($inv['source_id'])) {
        try {
            $deferredStmt = $conn->prepare("
                SELECT c.accrual_deferred_rent
                FROM co_shop_rent_schedules s
                JOIN co_shop_rental_contracts c ON c.id = s.contract_id
                WHERE s.id = ? AND s.company_id = ?
                LIMIT 1
            ");
            $deferredStmt->execute([(int)$inv['source_id'], $companyId]);
            $deferredRent = ((int)$deferredStmt->fetchColumn() === 1);
        } catch (Throwable $e) {
            $deferredRent = false;
        }
    }
    if (!empty($inv['income_account_id'])) {
        $accountStmt = $conn->prepare("
            SELECT id, account_code, account_name, account_type, normal_balance
            FROM re_chart_of_accounts
            WHERE id = ? AND company_id = ? AND is_active = 1 AND is_header = 0
              AND account_type IN ('Income', 'Liability')
            LIMIT 1
        ");
        $accountStmt->execute([(int)$inv['income_account_id'], $companyId]);
        $incomeAccount = $accountStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($deferredRent) {
        $incomeAccount = find_account_by_code(CO_ACCOUNT_DEFERRED_RENT_REVENUE, $companyId);
    }
    if (!$incomeAccount) {
        $fallbackCode = CO_ACCOUNT_CONSTRUCTION_INCOME;
        if (($inv['source_type'] ?? '') === 'shop_rental') {
            $fallbackCode = CO_ACCOUNT_SHOP_RENTAL_INCOME;
        } elseif (($inv['source_type'] ?? '') === 'shop_commission') {
            $fallbackCode = CO_ACCOUNT_SHOP_COMMISSION_INCOME;
        } elseif (($inv['source_type'] ?? '') === 'shop_termination_penalty') {
            $fallbackCode = CO_ACCOUNT_SHOP_TERMINATION_PENALTY;
        } elseif (($inv['source_type'] ?? '') === 'shop_charge') {
            // Prefer invoice income_account_id (set from charge COA, typically 4170 for Key Money).
            $fallbackCode = CO_ACCOUNT_SHOP_RENTAL_INCOME;
        } elseif (($inv['source_type'] ?? '') === 'camp_management') {
            $fallbackCode = CO_ACCOUNT_CAMP_MANAGEMENT_INCOME;
        } elseif (($inv['source_type'] ?? '') === 'maintenance_service') {
            $fallbackCode = CO_ACCOUNT_MAINTENANCE_INCOME;
        }
        $incomeAccount = find_account_by_code($fallbackCode, $companyId);
    }
    $vatAccount = find_account_by_code(CO_ACCOUNT_OUTPUT_VAT, $companyId);

    if (!$arAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Accounts Receivable account (1310) not found. Run construction COA setup.'];
    }
    if (!$incomeAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Income/deferred revenue account not found. Run construction COA setup or choose an account.'];
    }

    $vatAmount = (float)($inv['vat_amount'] ?? 0);
    $total = (float)$inv['total_amount'];
    $subtotal = (float)($inv['subtotal'] ?? 0);
    if ($subtotal <= 0) {
        $subtotal = max(0, $total - $vatAmount);
    }

    // Monthly tax invoices always post full AR + Output VAT.
    // Separate VAT cash is held in liability 2330 and consumed after this journal
    // (Dr 2330 / Cr 1310) so collectible AR = rent only.
    $arDebit = $total;
    $postVatAmount = $vatAmount;

    $date = $inv['invoice_date'] ?: date('Y-m-d');
    $desc = 'Construction income invoice ' . ($inv['invoice_number'] ?? ('#' . $invoiceId)) . ' - ' . ($inv['client_name'] ?? 'Client');
    $ref = $inv['invoice_number'] ?: 'CO-CINV-' . $invoiceId;

    $lines = [[
        'account_id' => $arAccount['id'],
        'debit' => $arDebit,
        'credit' => 0,
        'description' => $desc,
        'reference' => $ref,
    ]];
    $invoiceLines = function_exists('co_client_invoice_lines') ? co_client_invoice_lines($conn, $companyId, (int)$invoiceId) : [];
    if ($invoiceLines) {
        foreach ($invoiceLines as $invoiceLine) {
            $credit = (float)($invoiceLine['amount'] ?? 0);
            if ($credit <= 0) continue;
            $lineIncomeAccount = $incomeAccount;
            if (!$deferredRent && !empty($invoiceLine['income_account_id'])) {
                $accStmt = $conn->prepare("
                    SELECT id, account_code, account_name, account_type, normal_balance
                    FROM re_chart_of_accounts
                    WHERE id = ? AND company_id = ? AND is_active = 1 AND is_header = 0
                      AND account_type IN ('Income', 'Liability')
                    LIMIT 1
                ");
                $accStmt->execute([(int)$invoiceLine['income_account_id'], $companyId]);
                $lineIncomeAccount = $accStmt->fetch(PDO::FETCH_ASSOC) ?: $incomeAccount;
            }
            $lines[] = [
                'account_id' => $lineIncomeAccount['id'],
                'debit' => 0,
                'credit' => $credit,
                'description' => ($invoiceLine['description'] ?: $desc) . ($deferredRent ? ' (deferred rent revenue)' : ' (income)'),
                'reference' => $ref,
            ];
        }
    } else {
        $lines[] = [
            'account_id' => $incomeAccount['id'],
            'debit' => 0,
            'credit' => $subtotal,
            'description' => $desc . ($deferredRent ? ' (deferred rent revenue)' : ' (income)'),
            'reference' => $ref,
        ];
    }
    if ($postVatAmount > 0) {
        if (!$vatAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Output VAT account (2310) not found. Run construction COA setup.'];
        }
        $lines[] = [
            'account_id' => $vatAccount['id'],
            'debit' => 0,
            'credit' => $postVatAmount,
            'description' => $desc . ' (output VAT)',
            'reference' => $ref,
        ];
    }

    $result = create_and_post_journal(
        $companyId,
        'invoice',
        'co_client_invoice',
        $invoiceId,
        $lines,
        $desc,
        $date,
        $createdBy
    );

    if (!empty($result['success']) && function_exists('co_shop_apply_prepaid_vat_to_invoice')) {
        require_once __DIR__ . '/construction_shop_rental_helpers.php';
        if (function_exists('co_shop_apply_prepaid_vat_to_invoice')) {
            try {
                co_shop_apply_prepaid_vat_to_invoice($conn, (int)$companyId, (int)$invoiceId, $createdBy);
            } catch (Throwable $e) {
                // Invoice journal already posted — surface consume failure so caller can reverse/fix.
                return [
                    'success' => false,
                    'journal_id' => $result['journal_id'] ?? null,
                    'error' => 'Invoice posted but prepaid VAT consume failed: ' . $e->getMessage(),
                ];
            }
        }
    }

    return $result;
}

/**
 * Post Construction client payment/receipt to accounting.
 * Debit selected Bank/Cash, Credit AR.
 */
function co_post_client_payment_to_accounting($paymentId, $companyId, $createdBy = null) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT cp.*, c.client_name
        FROM co_client_payments cp
        LEFT JOIN co_clients c ON c.id = cp.client_id
        WHERE cp.id = ? AND cp.company_id = ?
    ");
    $stmt->execute([$paymentId, $companyId]);
    $pay = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pay) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Client payment not found'];
    }
    if (!empty($pay['journal_id'])) {
        return ['success' => true, 'journal_id' => (int)$pay['journal_id'], 'error' => null];
    }

    $arAccount = find_account_by_code(CO_ACCOUNT_AR, $companyId);
    $bankAccount = null;
    if (!empty($pay['pay_account_id'])) {
        $accountStmt = $conn->prepare("
            SELECT id, account_code, account_name, account_type, normal_balance
            FROM re_chart_of_accounts
            WHERE id = ? AND company_id = ? AND is_active = 1 AND is_header = 0
            LIMIT 1
        ");
        $accountStmt->execute([(int)$pay['pay_account_id'], $companyId]);
        $bankAccount = $accountStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$bankAccount) {
        $bankAccount = find_account_by_code(CO_ACCOUNT_BANK, $companyId);
        if (!$bankAccount) {
            $bankAccount = find_account_by_code(CO_ACCOUNT_CASH, $companyId);
        }
    }

    if (!$arAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Accounts Receivable account (1310) not found. Run construction COA setup.'];
    }
    if (!$bankAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Received To account not found. Choose a valid cash/bank chart-of-account.'];
    }

    $amount = (float)$pay['amount'];
    $creditAmount = 0.0;
    if (array_key_exists('credit_amount', $pay)) {
        $creditAmount = round((float)$pay['credit_amount'], 2);
    } elseif (function_exists('co_db_table_exists') && co_db_table_exists($conn, 'co_client_credit_transactions')) {
        $cr = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM co_client_credit_transactions WHERE payment_id = ? AND company_id = ? AND txn_type = 'overpayment'");
        $cr->execute([$paymentId, $companyId]);
        $creditAmount = round((float)$cr->fetchColumn(), 2);
    }
    $purpose = (string)($pay['receipt_purpose'] ?? 'invoice_allocation');
    $isPrepaidVat = ($purpose === 'prepaid_output_vat');
    $prepaidVatAmount = 0.0;
    if (array_key_exists('prepaid_vat_amount', $pay)) {
        $prepaidVatAmount = round((float)$pay['prepaid_vat_amount'], 2);
    }
    if ($isPrepaidVat && $prepaidVatAmount <= 0.005) {
        $prepaidVatAmount = $amount;
    }

    $date = $pay['payment_date'] ?: date('Y-m-d');
    $desc = $isPrepaidVat
        ? ('VAT collected in advance: ' . ($pay['client_name'] ?? 'Client'))
        : ('Client receipt: ' . ($pay['client_name'] ?? 'Client'));
    $ref = $pay['reference'] ?: 'CO-CRCP-' . $paymentId;

    $arAmount = round($amount - $creditAmount - $prepaidVatAmount, 2);
    if ($arAmount < -0.005) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Receipt split exceeds payment amount (AR/credit/prepaid VAT).'];
    }
    if ($arAmount < 0) {
        $arAmount = 0.0;
    }

    $lines = [
        [
            'account_id' => $bankAccount['id'],
            'debit' => $amount,
            'credit' => 0,
            'description' => $desc,
            'reference' => $ref,
        ],
    ];
    if ($arAmount > 0.005) {
        $lines[] = [
            'account_id' => $arAccount['id'],
            'debit' => 0,
            'credit' => $arAmount,
            'description' => $desc . ' (AR)',
            'reference' => $ref,
        ];
    }
    if ($prepaidVatAmount > 0.005) {
        $prepaidAccount = find_account_by_code(CO_ACCOUNT_PREPAID_OUTPUT_VAT, $companyId);
        if (!$prepaidAccount) {
            return [
                'success' => false,
                'journal_id' => null,
                'error' => 'VAT Collected in Advance account (2330) not found. Run construction COA setup.',
            ];
        }
        $lines[] = [
            'account_id' => $prepaidAccount['id'],
            'debit' => 0,
            'credit' => $prepaidVatAmount,
            'description' => $desc . ' (prepaid Output VAT)',
            'reference' => $ref,
        ];
    }
    if ($creditAmount > 0.005) {
        $advanceAccount = find_account_by_code(CO_ACCOUNT_CLIENT_ADVANCES, $companyId);
        if (!$advanceAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Client Advances account (2410) not found. Run construction COA setup.'];
        }
        $lines[] = [
            'account_id' => $advanceAccount['id'],
            'debit' => 0,
            'credit' => $creditAmount,
            'description' => $desc . ' (client credit)',
            'reference' => $ref,
        ];
    }
    if (count($lines) < 2) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Receipt has no credit lines to post.'];
    }

    return create_and_post_journal(
        $companyId,
        'payment',
        'co_client_payment',
        $paymentId,
        $lines,
        $desc,
        $date,
        $createdBy
    );
}

/**
 * Apply client credit to invoice: Dr 2410 / Cr 1310.
 */
function co_post_client_credit_application_to_accounting($companyId, $clientId, $invoiceId, $amount, $createdBy = null, $paymentId = null) {
    global $conn;
    $amount = round((float)$amount, 2);
    if ($amount <= 0) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Credit apply amount must be positive.'];
    }

    $arAccount = find_account_by_code(CO_ACCOUNT_AR, $companyId);
    $advanceAccount = find_account_by_code(CO_ACCOUNT_CLIENT_ADVANCES, $companyId);
    if (!$arAccount || !$advanceAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'AR (1310) or Client Advances (2410) account not found.'];
    }

    $inv = $conn->prepare("SELECT invoice_number FROM co_client_invoices WHERE id = ? AND company_id = ?");
    $inv->execute([(int)$invoiceId, (int)$companyId]);
    $invoiceNumber = $inv->fetchColumn() ?: ('#' . $invoiceId);
    $desc = 'Apply client credit to invoice ' . $invoiceNumber;
    $payKey = $paymentId ? (int)$paymentId : ((int)$invoiceId * 100000 + ((int)(time() % 100000)));
    $ref = 'CO-CREDIT-APPLY-' . $payKey;
    $referenceId = $payKey;
    $lines = [
        ['account_id' => $advanceAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => $desc, 'reference' => $ref],
        ['account_id' => $arAccount['id'], 'debit' => 0, 'credit' => $amount, 'description' => $desc, 'reference' => $ref],
    ];
    return create_and_post_journal($companyId, 'manual', 'co_client_credit_apply', $referenceId, $lines, $desc, date('Y-m-d'), $createdBy);
}

/**
 * Post camp agent settlement.
 * Debit AR for net receivable, debit commission/deductions/input VAT, credit gross camp rent income and output VAT.
 */
function co_post_camp_agent_settlement_to_accounting($settlementId, $companyId, $createdBy = null) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT s.*, c.contract_number, camp.camp_name, cl.client_name
        FROM co_camp_agent_settlements s
        JOIN co_camp_management_contracts c ON c.id = s.contract_id
        JOIN co_labor_camps camp ON camp.id = c.camp_id
        JOIN co_clients cl ON cl.id = c.client_id
        WHERE s.id = ? AND s.company_id = ?
    ");
    $stmt->execute([$settlementId, $companyId]);
    $settlement = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$settlement) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Camp agent settlement not found'];
    }
    if (!empty($settlement['journal_id'])) {
        return ['success' => true, 'journal_id' => (int)$settlement['journal_id'], 'error' => null];
    }

    $arAccount = find_account_by_code(CO_ACCOUNT_AR, $companyId);
    $incomeAccount = find_account_by_code(CO_ACCOUNT_CAMP_MANAGEMENT_INCOME, $companyId);
    $commissionAccount = find_account_by_code(CO_ACCOUNT_AGENT_COMMISSION_EXPENSE, $companyId);
    $outputVatAccount = find_account_by_code(CO_ACCOUNT_OUTPUT_VAT, $companyId);
    $inputVatAccount = find_account_by_code(CO_ACCOUNT_INPUT_VAT, $companyId);

    if (!$arAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Accounts Receivable account (1310) not found. Run construction COA setup.'];
    }
    if (!$incomeAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Camp Rental Income account (4115) not found. Run construction COA setup.'];
    }
    if (!$commissionAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Agent Commission / Deductions Expense account (5140) not found. Run construction COA setup.'];
    }

    $grossRent = (float)$settlement['gross_rent_amount'];
    $outputVat = (float)$settlement['output_vat_amount'];
    $commission = (float)$settlement['commission_amount'];
    $commissionVat = (float)$settlement['commission_vat_amount'];
    $otherDeductions = (float)$settlement['other_deductions'];
    $netReceivable = (float)$settlement['net_receivable'];
    $date = $settlement['settlement_date'] ?: date('Y-m-d');
    $desc = 'Camp agent settlement ' . $settlement['settlement_number'] . ' - ' . $settlement['camp_name'] . ' / ' . $settlement['client_name'];
    $ref = $settlement['settlement_number'] ?: 'CO-CAMP-SET-' . $settlementId;

    $lines = [];
    if ($netReceivable > 0) {
        $lines[] = ['account_id' => $arAccount['id'], 'debit' => $netReceivable, 'credit' => 0, 'description' => $desc . ' (net receivable)', 'reference' => $ref];
    }
    if ($commission > 0) {
        $lines[] = ['account_id' => $commissionAccount['id'], 'debit' => $commission, 'credit' => 0, 'description' => $desc . ' (agent commission)', 'reference' => $ref];
    }
    if ($commissionVat > 0) {
        if (!$inputVatAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Input VAT account (2130) not found. Run construction COA setup.'];
        }
        $lines[] = ['account_id' => $inputVatAccount['id'], 'debit' => $commissionVat, 'credit' => 0, 'description' => $desc . ' (commission VAT)', 'reference' => $ref];
    }
    if ($otherDeductions > 0) {
        $lines[] = ['account_id' => $commissionAccount['id'], 'debit' => $otherDeductions, 'credit' => 0, 'description' => $desc . ' (other deductions)', 'reference' => $ref];
    }
    if ($grossRent > 0) {
        $lines[] = ['account_id' => $incomeAccount['id'], 'debit' => 0, 'credit' => $grossRent, 'description' => $desc . ' (gross camp rent)', 'reference' => $ref];
    }
    if ($outputVat > 0) {
        if (!$outputVatAccount) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Output VAT account (2310) not found. Run construction COA setup.'];
        }
        $lines[] = ['account_id' => $outputVatAccount['id'], 'debit' => 0, 'credit' => $outputVat, 'description' => $desc . ' (output VAT)', 'reference' => $ref];
    }

    return create_and_post_journal($companyId, 'invoice', 'co_camp_agent_settlement', $settlementId, $lines, $desc, $date, $createdBy);
}

/**
 * Post receipt/remittance from camp management agent.
 * Debit selected Bank/Cash, Credit AR.
 */
function co_post_camp_agent_receipt_to_accounting($receiptId, $companyId, $createdBy = null) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT r.*, s.settlement_number, cl.client_name
        FROM co_camp_agent_receipts r
        JOIN co_camp_agent_settlements s ON s.id = r.settlement_id
        JOIN co_camp_management_contracts c ON c.id = s.contract_id
        JOIN co_clients cl ON cl.id = c.client_id
        WHERE r.id = ? AND r.company_id = ?
    ");
    $stmt->execute([$receiptId, $companyId]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$receipt) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Camp agent receipt not found'];
    }
    if (!empty($receipt['journal_id'])) {
        return ['success' => true, 'journal_id' => (int)$receipt['journal_id'], 'error' => null];
    }

    $arAccount = find_account_by_code(CO_ACCOUNT_AR, $companyId);
    $bankAccount = null;
    if (!empty($receipt['pay_account_id'])) {
        $accountStmt = $conn->prepare("
            SELECT id, account_code, account_name, account_type, normal_balance
            FROM re_chart_of_accounts
            WHERE id = ? AND company_id = ? AND is_active = 1 AND is_header = 0
            LIMIT 1
        ");
        $accountStmt->execute([(int)$receipt['pay_account_id'], $companyId]);
        $bankAccount = $accountStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$arAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Accounts Receivable account (1310) not found. Run construction COA setup.'];
    }
    if (!$bankAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Received To account not found.'];
    }

    $amount = (float)$receipt['amount'];
    $date = $receipt['receipt_date'] ?: date('Y-m-d');
    $desc = 'Camp agent remittance: ' . $receipt['settlement_number'] . ' - ' . $receipt['client_name'];
    $ref = $receipt['reference'] ?: 'CO-CAMP-RCP-' . $receiptId;
    $lines = [
        ['account_id' => $bankAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => $desc, 'reference' => $ref],
        ['account_id' => $arAccount['id'], 'debit' => 0, 'credit' => $amount, 'description' => $desc, 'reference' => $ref],
    ];

    return create_and_post_journal($companyId, 'payment', 'co_camp_agent_receipt', $receiptId, $lines, $desc, $date, $createdBy);
}

/**
 * Post shop rental security deposit receipt to liability.
 */
function co_post_shop_deposit_to_accounting($contractId, $companyId, $amount, $payAccountId, $paymentDate, $reference = '', $createdBy = null, $referenceId = null) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT c.contract_number, cl.client_name
        FROM co_shop_rental_contracts c
        JOIN co_clients cl ON cl.id = c.client_id
        WHERE c.id = ? AND c.company_id = ?
    ");
    $stmt->execute([$contractId, $companyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Shop rental contract not found'];
    }

    $depositAccount = find_account_by_code(CO_ACCOUNT_SECURITY_DEPOSITS, $companyId);
    $bankAccount = null;
    if ($payAccountId) {
        $accountStmt = $conn->prepare("
            SELECT id, account_code, account_name, account_type, normal_balance
            FROM re_chart_of_accounts
            WHERE id = ? AND company_id = ? AND is_active = 1 AND is_header = 0
            LIMIT 1
        ");
        $accountStmt->execute([(int)$payAccountId, $companyId]);
        $bankAccount = $accountStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$bankAccount) {
        $bankAccount = find_account_by_code(CO_ACCOUNT_BANK, $companyId) ?: find_account_by_code(CO_ACCOUNT_CASH, $companyId);
    }
    if (!$depositAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Tenant Security Deposits account (2200) not found. Run construction COA setup.'];
    }
    if (!$bankAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Deposit receipt account not found.'];
    }

    $desc = 'Shop rental security deposit: ' . $contract['contract_number'] . ' - ' . $contract['client_name'];
    $ref = $reference ?: 'CO-SHOP-DEP-' . ($referenceId ?: $contractId);
    $lines = [
        ['account_id' => $bankAccount['id'], 'debit' => (float)$amount, 'credit' => 0, 'description' => $desc, 'reference' => $ref],
        ['account_id' => $depositAccount['id'], 'debit' => 0, 'credit' => (float)$amount, 'description' => $desc, 'reference' => $ref],
    ];

    // Prefer deposit receipt id as idempotent source key (Phase 1 unified path).
    $refType = $referenceId ? 'co_shop_deposit_receipt' : 'co_shop_deposit';
    return create_and_post_journal(
        $companyId,
        'payment',
        $refType,
        $referenceId ?: $contractId,
        $lines,
        $desc,
        $paymentDate ?: date('Y-m-d'),
        $createdBy
    );
}

/**
 * Recognize deferred shop rent revenue for one schedule/month.
 * Debit Deferred Rent Revenue, Credit Shop Rental Income.
 */
function co_post_shop_rent_recognition($scheduleId, $companyId, $recognitionMonth, $amount, $createdBy = null) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT s.*, c.contract_number, c.accrual_deferred_rent, u.shop_number, cl.client_name
        FROM co_shop_rent_schedules s
        JOIN co_shop_rental_contracts c ON c.id = s.contract_id
        JOIN co_shop_units u ON u.id = c.shop_unit_id
        JOIN co_clients cl ON cl.id = c.client_id
        WHERE s.id = ? AND s.company_id = ?
    ");
    $stmt->execute([$scheduleId, $companyId]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$schedule) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Rent schedule not found'];
    }
    if ((int)$schedule['accrual_deferred_rent'] !== 1) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Contract is not using deferred rent revenue.'];
    }

    $deferredAccount = find_account_by_code(CO_ACCOUNT_DEFERRED_RENT_REVENUE, $companyId);
    $incomeAccount = find_account_by_code(CO_ACCOUNT_SHOP_RENTAL_INCOME, $companyId);
    if (!$deferredAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Deferred Rent Revenue account (2215) not found. Run construction COA setup.'];
    }
    if (!$incomeAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Shop Rental Income account (4120) not found. Run construction COA setup.'];
    }

    $monthDate = date('Y-m-01', strtotime($recognitionMonth));
    $desc = 'Deferred rent recognition: ' . $schedule['shop_number'] . ' - ' . $schedule['client_name'] . ' (' . date('M Y', strtotime($monthDate)) . ')';
    $ref = 'CO-RENT-REC-' . $scheduleId . '-' . date('Ym', strtotime($monthDate));
    $lines = [
        ['account_id' => $deferredAccount['id'], 'debit' => (float)$amount, 'credit' => 0, 'description' => $desc, 'reference' => $ref],
        ['account_id' => $incomeAccount['id'], 'debit' => 0, 'credit' => (float)$amount, 'description' => $desc, 'reference' => $ref],
    ];

    // Stable idempotency key: schedule_id * 1000000 + YYYYMM
    $referenceId = ((int)$scheduleId * 1000000) + (int)date('Ym', strtotime($monthDate));
    return create_and_post_journal(
        $companyId,
        'manual',
        'co_shop_rent_recognition',
        $referenceId,
        $lines,
        $desc,
        $monthDate,
        $createdBy
    );
}
