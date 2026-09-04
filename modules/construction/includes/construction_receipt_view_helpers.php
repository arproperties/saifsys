<?php
/**
 * Construction client payment receipt — view model (Phase 5B).
 * Single source of truth for HTML Receipt page and PDF. Read-only; no posting.
 */

require_once __DIR__ . '/construction_helpers.php';
require_once __DIR__ . '/construction_income_helpers.php';
require_once __DIR__ . '/construction_receipt_allocation_service.php';

function co_receipt_display_number(int $paymentId): string {
    return 'CR-' . str_pad((string)$paymentId, 6, '0', STR_PAD_LEFT);
}

function co_receipt_view_url(int $paymentId): string {
    return 'client_payment_receipt.php?id=' . (int)$paymentId;
}

function co_receipt_pdf_url(int $paymentId): string {
    return 'client_receipt_pdf.php?id=' . (int)$paymentId;
}

/**
 * Resolve payment id linked to a shop rent cheque (allocated / legacy / link table).
 */
function co_receipt_payment_id_for_cheque(PDO $conn, int $companyId, array $cheque): ?int {
    $payId = (int)($cheque['allocated_payment_id'] ?? 0);
    if ($payId <= 0) {
        $payId = (int)($cheque['payment_id'] ?? 0);
    }
    if ($payId > 0) {
        return $payId;
    }
    $chequeId = (int)($cheque['id'] ?? 0);
    if ($chequeId <= 0 || $companyId <= 0 || !co_db_table_exists($conn, 'co_shop_receipt_cheque_links')) {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT payment_id FROM co_shop_receipt_cheque_links
        WHERE company_id = ? AND cheque_id = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$companyId, $chequeId]);
    $id = (int)$stmt->fetchColumn();
    return $id > 0 ? $id : null;
}

/**
 * Load full receipt view model (company-scoped).
 *
 * @return array<string,mixed>
 */
function co_receipt_load_view(PDO $conn, int $companyId, int $paymentId): array {
    if ($companyId <= 0 || $paymentId <= 0) {
        throw new RuntimeException('Company and payment are required.');
    }

    $hasContractCol = co_db_column_exists($conn, 'co_client_payments', 'contract_id');
    $hasAllocStatus = co_db_column_exists($conn, 'co_client_payments', 'allocation_status');
    $hasCreditAmt = co_db_column_exists($conn, 'co_client_payments', 'credit_amount');

    $sql = "
        SELECT p.*,
               c.client_name, c.contact_person, c.email AS client_email, c.phone AS client_phone,
               c.address AS client_address, c.tax_number AS client_trn,
               coa.account_code AS pay_account_code, coa.account_name AS pay_account_name,
               jh.journal_number, jh.journal_date AS journal_date,
               CASE
                 WHEN jh.id IS NULL THEN NULL
                 WHEN jh.is_reversed = 1 THEN 'reversed'
                 WHEN jh.is_posted = 1 THEN 'posted'
                 ELSE 'unposted'
               END AS journal_status,
               u.username AS created_by_name
        FROM co_client_payments p
        LEFT JOIN co_clients c ON c.id = p.client_id AND c.company_id = p.company_id
        LEFT JOIN re_chart_of_accounts coa ON coa.id = p.pay_account_id AND coa.company_id = p.company_id
        LEFT JOIN re_journal_headers jh ON jh.id = p.journal_id AND jh.company_id = p.company_id
        LEFT JOIN `user` u ON u.id = p.created_by
        WHERE p.id = ? AND p.company_id = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$paymentId, $companyId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        throw new RuntimeException('Payment not found for this company.');
    }

    $contract = null;
    $contractId = $hasContractCol ? (int)($payment['contract_id'] ?? 0) : 0;
    if ($contractId > 0 && co_db_table_exists($conn, 'co_shop_rental_contracts')) {
        $cStmt = $conn->prepare("
            SELECT id, contract_number, status, client_id, start_date, end_date, rent_amount
            FROM co_shop_rental_contracts
            WHERE id = ? AND company_id = ?
        ");
        $cStmt->execute([$contractId, $companyId]);
        $contract = $cStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Funding sources (workspace); synthesize one row for legacy receipts
    $funding = [];
    if (co_db_table_exists($conn, 'co_client_payment_funding_sources')) {
        $fStmt = $conn->prepare("
            SELECT f.*, ch.cheque_number, ch.bank_name AS cheque_bank, ch.cheque_date, ch.status AS cheque_status
            FROM co_client_payment_funding_sources f
            LEFT JOIN co_shop_rent_cheques ch ON ch.id = f.cheque_id AND ch.company_id = f.company_id
            WHERE f.company_id = ? AND f.payment_id = ?
            ORDER BY f.id ASC
        ");
        $fStmt->execute([$companyId, $paymentId]);
        $funding = $fStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // Cheque links (multi) — load before synthetic funding so we can reuse them
    $chequeLinks = [];
    if (co_db_table_exists($conn, 'co_shop_receipt_cheque_links')) {
        $cl = $conn->prepare("
            SELECT l.*, ch.cheque_number, ch.bank_name, ch.cheque_date, ch.status AS cheque_status, ch.notes AS cheque_notes
            FROM co_shop_receipt_cheque_links l
            JOIN co_shop_rent_cheques ch ON ch.id = l.cheque_id AND ch.company_id = l.company_id
            WHERE l.company_id = ? AND l.payment_id = ?
            ORDER BY l.id ASC
        ");
        $cl->execute([$companyId, $paymentId]);
        $chequeLinks = $cl->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if (!$funding) {
        if ($chequeLinks) {
            foreach ($chequeLinks as $link) {
                $funding[] = [
                    'method' => 'cheque',
                    'amount' => (float)($link['amount'] ?? 0),
                    'reference' => $link['cheque_number'] ?? null,
                    'funding_date' => $link['cheque_date'] ?? ($payment['payment_date'] ?? null),
                    'cheque_id' => (int)$link['cheque_id'],
                    'cheque_number' => $link['cheque_number'] ?? null,
                    'cheque_bank' => $link['bank_name'] ?? null,
                    'cheque_date' => $link['cheque_date'] ?? null,
                    'cheque_status' => $link['cheque_status'] ?? null,
                    'remarks' => null,
                    '_synthetic' => 1,
                ];
            }
        } else {
            $legacyCheque = null;
            if (co_db_table_exists($conn, 'co_shop_rent_cheques')) {
                $hasAllocPay = co_db_column_exists($conn, 'co_shop_rent_cheques', 'allocated_payment_id');
                if ($hasAllocPay) {
                    $lq = $conn->prepare("
                        SELECT id, cheque_number, bank_name, cheque_date, amount, status
                        FROM co_shop_rent_cheques
                        WHERE company_id = ? AND (payment_id = ? OR allocated_payment_id = ?)
                        ORDER BY id ASC LIMIT 1
                    ");
                    $lq->execute([$companyId, $paymentId, $paymentId]);
                } else {
                    $lq = $conn->prepare("
                        SELECT id, cheque_number, bank_name, cheque_date, amount, status
                        FROM co_shop_rent_cheques
                        WHERE company_id = ? AND payment_id = ?
                        ORDER BY id ASC LIMIT 1
                    ");
                    $lq->execute([$companyId, $paymentId]);
                }
                $legacyCheque = $lq->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $method = $legacyCheque ? 'cheque' : 'bank_transfer';
            $funding[] = [
                'method' => $method,
                'amount' => (float)$payment['amount'],
                'reference' => $payment['reference'] ?? null,
                'funding_date' => $payment['payment_date'] ?? null,
                'cheque_id' => $legacyCheque ? (int)$legacyCheque['id'] : null,
                'cheque_number' => $legacyCheque['cheque_number'] ?? null,
                'cheque_bank' => $legacyCheque['bank_name'] ?? null,
                'cheque_date' => $legacyCheque['cheque_date'] ?? null,
                'cheque_status' => $legacyCheque['status'] ?? null,
                'remarks' => null,
                '_synthetic' => 1,
            ];
        }
    }

    // Allocations with invoice context
    $allocations = [];
    $allocatedTotal = 0.0;
    if (co_client_allocations_ready($conn)) {
        $aStmt = $conn->prepare("
            SELECT a.id, a.invoice_id, a.allocated_amount, a.created_at,
                   i.invoice_number, i.invoice_date, i.due_date, i.total_amount,
                   i.status AS invoice_status, i.source_type,
                   COALESCE((
                       SELECT SUM(a2.allocated_amount)
                       FROM co_client_payment_allocations a2
                       WHERE a2.company_id = a.company_id AND a2.invoice_id = a.invoice_id
                   ), 0) AS invoice_paid_total
            FROM co_client_payment_allocations a
            LEFT JOIN co_client_invoices i ON i.id = a.invoice_id AND i.company_id = a.company_id
            WHERE a.company_id = ? AND a.payment_id = ?
            ORDER BY i.due_date IS NULL, i.due_date, i.id, a.id
        ");
        $aStmt->execute([$companyId, $paymentId]);
        foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $allocAmt = round((float)$row['allocated_amount'], 2);
            $allocatedTotal = round($allocatedTotal + $allocAmt, 2);
            $invTotal = round((float)($row['total_amount'] ?? 0), 2);
            $invPaid = round((float)($row['invoice_paid_total'] ?? 0), 2);
            $remaining = max(0, round($invTotal - $invPaid, 2));
            $kind = 'other';
            $kindLabel = 'Invoice';
            $src = (string)($row['source_type'] ?? '');
            if ($src === 'shop_commission') {
                $kind = 'commission';
                $kindLabel = 'Commission';
            } elseif ($src === 'shop_termination_penalty') {
                $kind = 'penalty';
                $kindLabel = 'Penalty';
            } elseif ($src === 'shop_charge') {
                $kind = 'charge';
                $kindLabel = 'Charge';
            } elseif ($src === 'shop_rental') {
                $kind = 'rent';
                $kindLabel = 'Rent';
            }
            $allocations[] = [
                'invoice_id' => (int)($row['invoice_id'] ?? 0),
                'invoice_number' => (string)($row['invoice_number'] ?? ('#' . ($row['invoice_id'] ?? ''))),
                'invoice_date' => $row['invoice_date'] ?? null,
                'due_date' => $row['due_date'] ?? null,
                'invoice_total' => $invTotal,
                'allocated_amount' => $allocAmt,
                'invoice_remaining' => $remaining,
                'invoice_status' => $row['invoice_status'] ?? null,
                'kind' => $kind,
                'kind_label' => $kindLabel,
                'source_type' => $src,
            ];
        }
    } elseif (!empty($payment['invoice_id'])) {
        // Legacy single-invoice payment
        $invId = (int)$payment['invoice_id'];
        $iStmt = $conn->prepare("
            SELECT i.id, i.invoice_number, i.invoice_date, i.due_date, i.total_amount, i.status, i.source_type,
                   COALESCE((
                       SELECT SUM(a.allocated_amount)
                       FROM co_client_payment_allocations a
                       WHERE a.company_id = i.company_id AND a.invoice_id = i.id
                   ), 0) AS invoice_paid_total
            FROM co_client_invoices i
            WHERE i.id = ? AND i.company_id = ?
        ");
        $iStmt->execute([$invId, $companyId]);
        $inv = $iStmt->fetch(PDO::FETCH_ASSOC);
        if ($inv) {
            $allocAmt = round((float)$payment['amount'], 2);
            $allocatedTotal = $allocAmt;
            $invTotal = round((float)$inv['total_amount'], 2);
            $invPaid = round((float)($inv['invoice_paid_total'] ?? 0), 2);
            $allocations[] = [
                'invoice_id' => $invId,
                'invoice_number' => (string)$inv['invoice_number'],
                'invoice_date' => $inv['invoice_date'],
                'due_date' => $inv['due_date'],
                'invoice_total' => $invTotal,
                'allocated_amount' => $allocAmt,
                'invoice_remaining' => max(0, round($invTotal - $invPaid, 2)),
                'invoice_status' => $inv['status'],
                'kind' => 'rent',
                'kind_label' => 'Invoice',
                'source_type' => $inv['source_type'] ?? '',
            ];
        }
    }

    // Enrich rent vs VAT kind from schedule when possible
    if ($allocations && co_db_table_exists($conn, 'co_shop_rent_schedules')
        && co_db_column_exists($conn, 'co_client_invoices', 'source_id')) {
        foreach ($allocations as &$al) {
            if (($al['source_type'] ?? '') !== 'shop_rental' || ($al['invoice_id'] ?? 0) <= 0) {
                continue;
            }
            $s = $conn->prepare("
                SELECT s.schedule_type
                FROM co_client_invoices i
                JOIN co_shop_rent_schedules s ON s.id = i.source_id AND s.company_id = i.company_id
                WHERE i.id = ? AND i.company_id = ?
                LIMIT 1
            ");
            $s->execute([(int)$al['invoice_id'], $companyId]);
            $st = (string)($s->fetchColumn() ?: 'rent');
            if ($st === 'vat') {
                $al['kind'] = 'vat';
                $al['kind_label'] = 'VAT';
            } else {
                $al['kind'] = 'rent';
                $al['kind_label'] = 'Rent';
            }
        }
        unset($al);
    }

    // Enrich shop_charge → Key Money / charge name
    if ($allocations && co_db_column_exists($conn, 'co_client_invoices', 'contract_charge_id')
        && co_db_table_exists($conn, 'co_shop_contract_charges')) {
        foreach ($allocations as &$al) {
            if (($al['source_type'] ?? '') !== 'shop_charge' || ($al['invoice_id'] ?? 0) <= 0) {
                continue;
            }
            $s = $conn->prepare("
                SELECT ct.code, ct.name
                FROM co_client_invoices i
                LEFT JOIN co_shop_contract_charges cc
                  ON cc.id = i.contract_charge_id AND cc.company_id = i.company_id
                LEFT JOIN co_shop_charge_types ct
                  ON ct.id = cc.charge_type_id AND ct.company_id = cc.company_id
                WHERE i.id = ? AND i.company_id = ?
                LIMIT 1
            ");
            $s->execute([(int)$al['invoice_id'], $companyId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                continue;
            }
            if (($row['code'] ?? '') === 'key_money') {
                $al['kind'] = 'key_money';
                $al['kind_label'] = 'Key Money';
            } elseif (!empty($row['name'])) {
                $al['kind'] = 'charge';
                $al['kind_label'] = (string)$row['name'];
            }
        }
        unset($al);
    }

    $amount = round((float)$payment['amount'], 2);
    $creditCreated = $hasCreditAmt ? round((float)($payment['credit_amount'] ?? 0), 2) : 0.0;
    $isCreditApply = (($payment['reference'] ?? '') === 'CREDIT-APPLY') || empty($payment['pay_account_id']);
    $appliedCredit = $isCreditApply ? $amount : 0.0;

    // Contract outstanding (current open AR) — informational, not historical snapshot
    $contractOutstanding = null;
    if ($contractId > 0 && function_exists('co_receipt_open_invoices_for_contract')) {
        try {
            $open = co_receipt_open_invoices_for_contract($conn, $companyId, $contractId);
            $contractOutstanding = round(array_sum(array_map(static fn($r) => (float)$r['balance'], $open)), 2);
        } catch (Throwable $e) {
            error_log('co_receipt_load_view outstanding: ' . $e->getMessage());
            $contractOutstanding = null;
        }
    }

    $events = [];
    if (co_db_table_exists($conn, 'co_client_payment_events')) {
        try {
            $eStmt = $conn->prepare("
                SELECT e.*, u.username AS created_by_name
                FROM co_client_payment_events e
                LEFT JOIN `user` u ON u.id = e.created_by
                WHERE e.company_id = ? AND e.payment_id = ?
                ORDER BY e.created_at ASC, e.id ASC
            ");
            $eStmt->execute([$companyId, $paymentId]);
            $events = $eStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('co_receipt_load_view events: ' . $e->getMessage());
            $events = [];
        }
    }

    $methodLabels = function_exists('co_receipt_funding_methods')
        ? co_receipt_funding_methods()
        : [
            'bank_transfer' => 'Bank transfer',
            'cash' => 'Cash',
            'card' => 'Card / POS',
            'online' => 'Online payment',
            'cheque' => 'Cheque',
        ];

    $fundingMethodsSummary = [];
    foreach ($funding as $f) {
        $m = (string)($f['method'] ?? '');
        $lab = $methodLabels[$m] ?? ucwords(str_replace('_', ' ', $m));
        if (!in_array($lab, $fundingMethodsSummary, true)) {
            $fundingMethodsSummary[] = $lab;
        }
    }

    $chequeNumbers = [];
    foreach ($chequeLinks as $clink) {
        $n = trim((string)($clink['cheque_number'] ?? ''));
        $chequeNumbers[] = $n !== '' ? $n : ('#' . (int)$clink['cheque_id']);
    }
    if (!$chequeNumbers) {
        foreach ($funding as $f) {
            if (($f['method'] ?? '') !== 'cheque') {
                continue;
            }
            $n = trim((string)($f['cheque_number'] ?? ''));
            if ($n === '' && !empty($f['cheque_id'])) {
                $n = '#' . (int)$f['cheque_id'];
            }
            if ($n !== '') {
                $chequeNumbers[] = $n;
            }
        }
    }
    $chequeNumbers = array_values(array_unique($chequeNumbers));

    $bankTransferRefs = [];
    foreach ($funding as $f) {
        if (in_array(($f['method'] ?? ''), ['bank_transfer', 'online', 'card'], true)) {
            $ref = trim((string)($f['reference'] ?? ''));
            if ($ref !== '') {
                $bankTransferRefs[] = $ref;
            }
        }
    }
    if (!$bankTransferRefs && !$isCreditApply) {
        $pref = trim((string)($payment['reference'] ?? ''));
        if ($pref !== '' && $pref !== 'CREDIT-APPLY') {
            $bankTransferRefs[] = $pref;
        }
    }

    $status = $hasAllocStatus
        ? (string)($payment['allocation_status'] ?? 'allocated')
        : (!empty($payment['journal_id']) ? 'posted' : 'recorded');

    $isPrepaidVat = co_receipt_is_prepaid_vat_payment($payment);
    $statusLabel = co_receipt_status_display_label($status, $payment);

    $journalId = (int)($payment['journal_id'] ?? 0);
    $journalLines = [];
    $journalDebitTotal = 0.0;
    $journalCreditTotal = 0.0;
    $journalDescription = '';
    if ($journalId > 0 && co_db_table_exists($conn, 're_journal_lines')) {
        try {
            $jhExtra = $conn->prepare("
                SELECT description, total_debit, total_credit
                FROM re_journal_headers
                WHERE id = ? AND company_id = ?
                LIMIT 1
            ");
            $jhExtra->execute([$journalId, $companyId]);
            $jhRow = $jhExtra->fetch(PDO::FETCH_ASSOC) ?: [];
            $journalDescription = (string)($jhRow['description'] ?? '');
            $journalDebitTotal = round((float)($jhRow['total_debit'] ?? 0), 2);
            $journalCreditTotal = round((float)($jhRow['total_credit'] ?? 0), 2);

            $jl = $conn->prepare("
                SELECT jl.line_number, jl.account_id, jl.debit_amount, jl.credit_amount,
                       jl.description, jl.reference,
                       coa.account_code, coa.account_name
                FROM re_journal_lines jl
                LEFT JOIN re_chart_of_accounts coa
                  ON coa.id = jl.account_id AND coa.company_id = jl.company_id
                WHERE jl.company_id = ? AND jl.journal_id = ?
                ORDER BY jl.line_number ASC, jl.id ASC
            ");
            $jl->execute([$companyId, $journalId]);
            foreach ($jl->fetchAll(PDO::FETCH_ASSOC) as $line) {
                $dr = round((float)($line['debit_amount'] ?? 0), 2);
                $cr = round((float)($line['credit_amount'] ?? 0), 2);
                if ($journalDebitTotal <= 0.005 && $journalCreditTotal <= 0.005) {
                    $journalDebitTotal = round($journalDebitTotal + $dr, 2);
                    $journalCreditTotal = round($journalCreditTotal + $cr, 2);
                }
                $code = trim((string)($line['account_code'] ?? ''));
                $name = trim((string)($line['account_name'] ?? ''));
                $journalLines[] = [
                    'line_number' => (int)($line['line_number'] ?? 0),
                    'account_id' => (int)($line['account_id'] ?? 0),
                    'account_code' => $code,
                    'account_name' => $name,
                    'account_label' => trim(($code !== '' ? $code . ' — ' : '') . $name) ?: ('Account #' . (int)($line['account_id'] ?? 0)),
                    'debit' => $dr,
                    'credit' => $cr,
                    'description' => (string)($line['description'] ?? ''),
                    'reference' => (string)($line['reference'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            error_log('co_receipt_load_view journal lines: ' . $e->getMessage());
            $journalLines = [];
        }
    }

    return [
        'payment' => $payment,
        'payment_id' => $paymentId,
        'receipt_number' => co_receipt_display_number($paymentId),
        'status' => $status,
        'status_label' => $statusLabel,
        'is_prepaid_vat' => $isPrepaidVat,
        'is_credit_apply' => $isCreditApply,
        'client' => [
            'id' => (int)($payment['client_id'] ?? 0),
            'name' => (string)($payment['client_name'] ?? ''),
            'contact' => (string)($payment['contact_person'] ?? ''),
            'email' => (string)($payment['client_email'] ?? ''),
            'phone' => (string)($payment['client_phone'] ?? ''),
            'address' => (string)($payment['client_address'] ?? ''),
            'trn' => (string)($payment['client_trn'] ?? ''),
        ],
        'contract' => $contract,
        'contract_id' => $contractId,
        'receiving_gl' => [
            'id' => (int)($payment['pay_account_id'] ?? 0),
            'code' => (string)($payment['pay_account_code'] ?? ''),
            'name' => (string)($payment['pay_account_name'] ?? ''),
            'label' => trim(
                (($payment['pay_account_code'] ?? '') !== '' ? $payment['pay_account_code'] . ' — ' : '')
                . ($payment['pay_account_name'] ?? '')
            ) ?: '—',
        ],
        'journal' => [
            'id' => $journalId,
            'number' => (string)($payment['journal_number'] ?? ''),
            'date' => $payment['journal_date'] ?? null,
            'status' => $payment['journal_status'] ?? null,
            'description' => $journalDescription,
            'total_debit' => $journalDebitTotal,
            'total_credit' => $journalCreditTotal,
            'lines' => $journalLines,
        ],
        'funding' => $funding,
        'funding_methods_summary' => $fundingMethodsSummary,
        'cheque_links' => $chequeLinks,
        'cheque_numbers' => $chequeNumbers,
        'bank_transfer_refs' => array_values(array_unique($bankTransferRefs)),
        'allocations' => $allocations,
        'amount' => $amount,
        'allocated_total' => $allocatedTotal,
        'credit_created' => $creditCreated,
        'applied_credit' => $appliedCredit,
        'contract_outstanding' => $contractOutstanding,
        'events' => $events,
        'method_labels' => $methodLabels,
        'created_by_name' => (string)($payment['created_by_name'] ?? ''),
        'created_at' => $payment['created_at'] ?? null,
        'void_reason' => $payment['void_reason'] ?? null,
        'voided_at' => $payment['voided_at'] ?? null,
    ];
}

function co_receipt_status_badge_class(string $status): string {
    switch ($status) {
        case 'allocated':
        case 'posted':
        case 'vat_prepaid':
            return 'success';
        case 'overpaid':
            return 'info';
        case 'partial':
        case 'unallocated':
            return 'warning';
        case 'reversed':
        case 'voided':
            return 'danger';
        default:
            return 'secondary';
    }
}

/**
 * True when this receipt is Separate VAT collected in advance (display / UX only).
 */
function co_receipt_is_prepaid_vat_payment(array $payment): bool {
    if ((string)($payment['receipt_purpose'] ?? '') === 'prepaid_output_vat') {
        return true;
    }
    return round((float)($payment['prepaid_vat_amount'] ?? 0), 2) > 0.005
        && (string)($payment['receipt_purpose'] ?? '') !== 'invoice_allocation'
        && empty($payment['invoice_id']);
}

/**
 * Human-readable payment status for receipt UI (does not change stored allocation_status).
 */
function co_receipt_status_display_label(string $status, array $payment = []): string {
    if (co_receipt_is_prepaid_vat_payment($payment)
        && !in_array($status, ['reversed', 'voided'], true)) {
        return 'VAT Prepaid';
    }
    $labels = [
        'allocated' => 'Allocated',
        'partial' => 'Partial',
        'unallocated' => 'Unallocated',
        'overpaid' => 'Overpaid',
        'reversed' => 'Reversed',
        'voided' => 'Voided',
        'posted' => 'Posted',
        'recorded' => 'Recorded',
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/**
 * Badge class for display label / stored status (prepaid VAT uses success).
 */
function co_receipt_display_badge_class(string $status, array $payment = []): string {
    if (co_receipt_is_prepaid_vat_payment($payment)
        && !in_array($status, ['reversed', 'voided'], true)) {
        return 'success';
    }
    return co_receipt_status_badge_class($status);
}
