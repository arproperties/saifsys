<?php
/**
 * Construction Shop Rental helpers — Phase 1 commercial leasing (Madar Al Wadi).
 * Company-isolated. Does not touch Real Estate lease tables.
 */

require_once __DIR__ . '/construction_income_helpers.php';
require_once __DIR__ . '/construction_shop_rental_multishop_helpers.php';
require_once __DIR__ . '/construction_shop_rental_commission_helpers.php';
require_once __DIR__ . '/construction_shop_rental_charge_helpers.php';
require_once __DIR__ . '/construction_shop_rental_concession_helpers.php';
require_once __DIR__ . '/construction_shop_rental_lifecycle_helpers.php';
require_once __DIR__ . '/construction_shop_rental_inspection_helpers.php';
require_once __DIR__ . '/construction_shop_rental_deposit_settlement_helpers.php';
require_once __DIR__ . '/construction_shop_rental_cheque_lifecycle_helpers.php';
require_once __DIR__ . '/construction_shop_rental_termination_helpers.php';
require_once __DIR__ . '/construction_shop_rental_analytics_helpers.php';
require_once __DIR__ . '/construction_shop_rental_delete_helpers.php';

const CO_SHOP_VAT_INCLUDED = 'included_in_installment';
const CO_SHOP_VAT_PROPORTIONAL = 'proportional';
const CO_SHOP_VAT_SEPARATE = 'separate';

function co_shop_require_company_id(PDO $conn): int {
    $cid = current_company_id($conn);
    if (!$cid || (int)$cid <= 0) {
        http_response_code(400);
        echo 'Company context is required. Select a company before continuing.';
        exit;
    }
    return (int)$cid;
}

/**
 * Load a shop rental contract scoped to company (with unit + client labels).
 */
function co_shop_contract_load(PDO $conn, int $companyId, int $contractId): ?array {
    if ($companyId <= 0 || $contractId <= 0) {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT c.*, u.shop_number, u.shop_name, cl.client_name
        FROM co_shop_rental_contracts c
        LEFT JOIN co_shop_units u ON u.id = c.shop_unit_id AND u.company_id = c.company_id
        JOIN co_clients cl ON cl.id = c.client_id AND cl.company_id = c.company_id
        WHERE c.id = ? AND c.company_id = ?
    ");
    $stmt->execute([$contractId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function co_shop_vat_collection_options(): array {
    return [
        CO_SHOP_VAT_INCLUDED => 'VAT included in each rent installment',
        CO_SHOP_VAT_PROPORTIONAL => 'VAT distributed proportionally across rent installments',
        CO_SHOP_VAT_SEPARATE => 'VAT collected once up front (prepaid); monthly invoices are Tax Invoices',
    ];
}

function co_shop_normalize_vat_collection(?string $method): string {
    $method = (string)$method;
    $allowed = array_keys(co_shop_vat_collection_options());
    return in_array($method, $allowed, true) ? $method : CO_SHOP_VAT_INCLUDED;
}

function co_shop_normalize_vat_mode(?string $mode): string {
    return ($mode === 'inclusive') ? 'inclusive' : 'exclusive';
}

function co_shop_phase1_schema_ready(PDO $conn): bool {
    return co_db_table_exists($conn, 'co_shop_rental_contracts')
        && co_db_column_exists($conn, 'co_shop_rental_contracts', 'vat_collection_method')
        && co_db_table_exists($conn, 'co_shop_deposit_receipts')
        && co_db_table_exists($conn, 'co_client_credit_balances');
}

/**
 * Whether VAT collection method may be changed and plans regenerated.
 * Fail-closed: blocked once any rent invoice, linked/cleared rent cheque, or non-pending schedule exists.
 *
 * @return array{allowed:bool,blockers:list<string>,current:?string}
 */
function co_shop_vat_collection_change_eligibility(PDO $conn, int $companyId, int $contractId): array {
    $blockers = [];
    $stmt = $conn->prepare("SELECT status, vat_collection_method FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$contractId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['allowed' => false, 'blockers' => ['Contract not found in current company.'], 'current' => null];
    }
    $status = (string)($row['status'] ?? '');
    $current = co_shop_normalize_vat_collection($row['vat_collection_method'] ?? CO_SHOP_VAT_INCLUDED);
    if (!in_array($status, ['draft', 'active'], true)) {
        $blockers[] = 'VAT method can only be changed while the contract is Draft or Active (current: ' . $status . ').';
    }

    if (co_db_table_exists($conn, 'co_shop_rent_schedules')) {
        $sch = $conn->prepare("
            SELECT COUNT(*) FROM co_shop_rent_schedules
            WHERE company_id = ? AND contract_id = ?
              AND (invoice_id IS NOT NULL OR COALESCE(status, 'pending') <> 'pending')
        ");
        $sch->execute([$companyId, $contractId]);
        $n = (int)$sch->fetchColumn();
        if ($n > 0) {
            $blockers[] = $n . ' schedule row(s) already invoiced or not pending — reverse/cancel those first.';
        }
    }

    if (co_db_table_exists($conn, 'co_client_invoices') && co_db_table_exists($conn, 'co_shop_rent_schedules')) {
        $inv = $conn->prepare("
            SELECT COUNT(*) FROM co_client_invoices i
            WHERE i.company_id = ? AND i.source_type = 'shop_rental' AND i.status <> 'cancelled'
              AND i.source_id IN (
                  SELECT id FROM co_shop_rent_schedules WHERE company_id = ? AND contract_id = ?
              )
        ");
        $inv->execute([$companyId, $companyId, $contractId]);
        $nInv = (int)$inv->fetchColumn();
        if ($nInv > 0) {
            $blockers[] = $nInv . ' rent/VAT invoice(s) already exist for this contract.';
        }
    }

    if (co_db_table_exists($conn, 'co_shop_rent_cheques')) {
        $hasPay = co_db_column_exists($conn, 'co_shop_rent_cheques', 'payment_id');
        $hasJnl = co_db_column_exists($conn, 'co_shop_rent_cheques', 'journal_id');
        $hasInv = co_db_column_exists($conn, 'co_shop_rent_cheques', 'invoice_id');
        $conds = ["status = 'cleared'"];
        if ($hasPay) {
            $conds[] = 'payment_id IS NOT NULL';
        }
        if ($hasJnl) {
            $conds[] = 'journal_id IS NOT NULL';
        }
        if ($hasInv) {
            $conds[] = 'invoice_id IS NOT NULL';
        }
        $chq = $conn->prepare("
            SELECT COUNT(*) FROM co_shop_rent_cheques
            WHERE company_id = ? AND contract_id = ? AND cheque_type = 'rent'
              AND (" . implode(' OR ', $conds) . ")
        ");
        $chq->execute([$companyId, $contractId]);
        $nChq = (int)$chq->fetchColumn();
        if ($nChq > 0) {
            $blockers[] = $nChq . ' rent/VAT cheque(s) are cleared or linked to payment/invoice/journal.';
        }
    }

    if (co_db_table_exists($conn, 'co_shop_rent_recognitions') && co_db_table_exists($conn, 'co_shop_rent_schedules')) {
        $rec = $conn->prepare("
            SELECT COUNT(*) FROM co_shop_rent_recognitions r
            JOIN co_shop_rent_schedules s ON s.id = r.schedule_id AND s.company_id = r.company_id
            WHERE r.company_id = ? AND s.contract_id = ?
        ");
        $rec->execute([$companyId, $contractId]);
        $nRec = (int)$rec->fetchColumn();
        if ($nRec > 0) {
            $blockers[] = $nRec . ' revenue recognition row(s) already posted — change is blocked.';
        }
    }

    return [
        'allowed' => $blockers === [],
        'blockers' => $blockers,
        'current' => $current,
    ];
}

/**
 * Change VAT collection method and rebuild uninvoiced cheque plan + earning schedules.
 *
 * @return array{from:string,to:string,cheques:int,schedules:int,amendment_id:int}
 */
function co_shop_change_vat_collection_method(
    PDO $conn,
    int $companyId,
    int $contractId,
    string $newMethod,
    string $reason,
    ?int $userId
): array {
    if (!co_shop_phase1_schema_ready($conn)) {
        throw new RuntimeException('Run migrations/construction_shop_rental_phase1.sql first.');
    }
    $newMethod = co_shop_normalize_vat_collection($newMethod);
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('Amendment reason is required to change VAT collection method.');
    }

    $elig = co_shop_vat_collection_change_eligibility($conn, $companyId, $contractId);
    if (!$elig['allowed']) {
        throw new RuntimeException(implode(' ', $elig['blockers']));
    }

    $stmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$contractId, $companyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        throw new RuntimeException('Contract not found in current company.');
    }
    $from = co_shop_normalize_vat_collection($contract['vat_collection_method'] ?? CO_SHOP_VAT_INCLUDED);
    if ($from === $newMethod) {
        throw new RuntimeException('VAT collection method is already set to that value.');
    }

    $options = co_shop_vat_collection_options();
    $before = function_exists('co_shop_phase2a_schema_ready') && co_shop_phase2a_schema_ready($conn)
        ? co_shop_contract_amendment_snapshot($conn, $companyId, $contract)
        : null;

    $ownTxn = !$conn->inTransaction();
    if ($ownTxn) {
        $conn->beginTransaction();
    }
    try {
        $conn->prepare("
            UPDATE co_shop_rental_contracts
            SET vat_collection_method = ?
            WHERE id = ? AND company_id = ?
        ")->execute([$newMethod, $contractId, $companyId]);

        $rentCount = max(0, (int)($contract['rent_cheque_count'] ?? 0));
        $depCount = max(0, (int)($contract['deposit_cheque_count'] ?? 0));
        $chequeCreated = 0;
        if ($rentCount > 0 || $depCount > 0) {
            $chequeCreated = co_create_shop_cheque_plan($conn, $companyId, $contractId, $rentCount, $depCount);
        }

        if (co_db_table_exists($conn, 'co_shop_rent_schedules')) {
            $conn->prepare("
                DELETE FROM co_shop_rent_schedules
                WHERE company_id = ? AND contract_id = ? AND status = 'pending' AND invoice_id IS NULL
            ")->execute([$companyId, $contractId]);
        }
        $scheduleCreated = co_generate_shop_schedules_from_cheques($conn, $companyId, $contractId);

        $amendmentId = 0;
        if ($before !== null) {
            $fresh = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
            $fresh->execute([$contractId, $companyId]);
            $afterContract = $fresh->fetch(PDO::FETCH_ASSOC);
            $after = co_shop_contract_amendment_snapshot($conn, $companyId, $afterContract ?: $contract);
            $amendmentId = co_shop_record_amendment($conn, $companyId, $contractId, $reason, $before, $after, $userId);
        }

        if (function_exists('co_shop_log_event')) {
            co_shop_log_event($conn, $companyId, $contractId, 'vat_collection_changed', [
                'from' => $from,
                'to' => $newMethod,
                'from_label' => $options[$from] ?? $from,
                'to_label' => $options[$newMethod] ?? $newMethod,
                'reason' => $reason,
                'cheques_created' => $chequeCreated,
                'schedules_created' => $scheduleCreated,
                'amendment_id' => $amendmentId,
            ], $userId);
        }

        if ($ownTxn) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($ownTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    return [
        'from' => $from,
        'to' => $newMethod,
        'cheques' => $chequeCreated,
        'schedules' => $scheduleCreated,
        'amendment_id' => $amendmentId,
    ];
}

/**
 * Commercial totals from rent_amount = total contract rent (Option B / BR-CO-SHOP-007).
 *
 * @return array{net:float,vat:float,gross:float,vat_mode:string,method:string}
 */
function co_shop_contract_rent_totals(array $contract): array {
    $vatRate = (float)($contract['vat_rate'] ?? 0);
    $vatMode = co_shop_normalize_vat_mode($contract['vat_mode'] ?? 'exclusive');
    $method = co_shop_normalize_vat_collection($contract['vat_collection_method'] ?? CO_SHOP_VAT_INCLUDED);
    $entered = (float)($contract['rent_amount'] ?? 0);
    $amounts = co_compute_invoice_vat_amounts($entered, $vatRate, $vatMode);
    return [
        'net' => (float)$amounts['subtotal'],
        'vat' => (float)$amounts['vat_amount'],
        'gross' => (float)$amounts['total'],
        'vat_mode' => $vatMode,
        'method' => $method,
    ];
}

/**
 * Accounting earning months (always monthly over the lease Occupancy term).
 * When Rent Concession is enabled, months overlapping the concession window are
 * skipped entirely (no AED 0 rows). rent_amount is equal-split across chargeable months only.
 * Independent of payment_frequency (DEC-013 / BR-CO-SHOP-007).
 *
 * @return array<int,array{period_start:string,period_end:string,due_date:string,net:float,vat:float,gross:float}>
 */
function co_shop_build_earning_months(array $contract): array {
    $totals = co_shop_contract_rent_totals($contract);

    // Chargeable windows = occupancy minus concession overlap (no AED 0 rows).
    $windows = co_shop_chargeable_month_windows($contract);
    if (!$windows) {
        return [];
    }

    $monthCount = count($windows);
    $baseNet = round($totals['net'] / $monthCount, 2);
    $netRem = $totals['net'];
    // Separate VAT is still collected once via the VAT schedule/payment, but rent
    // earning invoices must display & report VAT equally across months (prepaid).
    $spreadVat = (float)$totals['vat'];
    $baseVat = round($spreadVat / $monthCount, 2);
    $vatRem = $spreadVat;

    $periods = [];
    foreach ($windows as $i => $w) {
        $net = ($i === $monthCount - 1) ? round($netRem, 2) : $baseNet;
        $vat = ($i === $monthCount - 1) ? round($vatRem, 2) : $baseVat;
        $periods[] = [
            'period_start' => $w['period_start'],
            'period_end' => $w['period_end'],
            'due_date' => $w['due_date'],
            'net' => $net,
            'vat' => $vat,
            'gross' => round($net + $vat, 2),
        ];
        $netRem = round($netRem - $net, 2);
        $vatRem = round($vatRem - $vat, 2);
    }
    return $periods;
}

/**
 * Monthly equivalent net rent derived from total contract rent ÷ chargeable earning months.
 */
function co_shop_monthly_equivalent_net(array $contract): float {
    if (function_exists('co_shop_chargeable_month_count')) {
        $n = co_shop_chargeable_month_count($contract);
    } else {
        $cursor = new DateTime((string)$contract['start_date']);
        $end = new DateTime((string)$contract['end_date']);
        $n = 0;
        while ($cursor <= $end) {
            $n++;
            $cursor->modify('+1 month');
            if ($n > 120) {
                break;
            }
        }
        $n = max(1, $n);
    }
    $totals = co_shop_contract_rent_totals($contract);
    return round($totals['net'] / max(1, $n), 2);
}

/** @deprecated Use co_shop_build_earning_months — kept as alias for callers. */
function co_shop_build_periods(array $contract): array {
    return co_shop_build_earning_months($contract);
}

/**
 * Operational rent cheque plan: split total contract rent across N cheques.
 * Dates spaced evenly across the lease term (payment_frequency is label/plan hint only for spacing preference).
 *
 * @return array<int,array{cheque_date:string,net:float,vat:float,gross:float,period_start:?string,period_end:?string}>
 */
function co_shop_distribute_rent_cheques(array $contract, array $periods, int $rentChequeCount): array {
    $rentChequeCount = max(0, $rentChequeCount);
    if ($rentChequeCount <= 0) {
        return [];
    }

    $totals = co_shop_contract_rent_totals($contract);
    $method = $totals['method'];
    $totalNet = $totals['net'];
    $totalVat = $totals['vat'];
    $startDate = (string)$contract['start_date'];
    $endDate = (string)$contract['end_date'];
    $termMonths = max(1, co_months_inclusive($startDate, $endDate));

    // Prefer even spacing across term; fall back to frequency step when it divides cleanly
    $freqStep = co_frequency_months((string)($contract['payment_frequency'] ?? 'monthly'));
    $useFreq = ($freqStep > 0 && ($termMonths % $rentChequeCount === 0) && ($freqStep === (int)floor($termMonths / $rentChequeCount)));

    $buckets = [];
    $baseNet = round($totalNet / $rentChequeCount, 2);
    $netRem = $totalNet;
    for ($i = 0; $i < $rentChequeCount; $i++) {
        if ($useFreq) {
            $date = co_income_add_months($startDate, $i * $freqStep);
        } else {
            $date = co_income_add_months($startDate, (int)floor($i * $termMonths / $rentChequeCount));
        }
        if ($date > $endDate) {
            $date = $endDate;
        }
        $net = ($i === $rentChequeCount - 1) ? round($netRem, 2) : $baseNet;
        if ($net < 0) {
            $net = 0.0;
        }
        $buckets[] = [
            'cheque_date' => $date,
            'net' => $net,
            'vat' => 0.0,
            'gross' => $net,
            'period_start' => null,
            'period_end' => null,
        ];
        $netRem = round($netRem - $net, 2);
    }

    if ($method === CO_SHOP_VAT_SEPARATE) {
        for ($i = 0; $i < $rentChequeCount; $i++) {
            $buckets[$i]['vat'] = 0.0;
            $buckets[$i]['gross'] = $buckets[$i]['net'];
        }
    } elseif ($method === CO_SHOP_VAT_PROPORTIONAL || $method === CO_SHOP_VAT_INCLUDED) {
        $baseVat = round($totalVat / $rentChequeCount, 2);
        $vatRem = $totalVat;
        for ($i = 0; $i < $rentChequeCount; $i++) {
            $v = ($i === $rentChequeCount - 1) ? round($vatRem, 2) : $baseVat;
            if ($v < 0) {
                $v = 0.0;
            }
            $buckets[$i]['vat'] = $v;
            $buckets[$i]['gross'] = round($buckets[$i]['net'] + $v, 2);
            $vatRem = round($vatRem - $v, 2);
        }
    }

    return $buckets;
}

function co_shop_invoice_open_balance(PDO $conn, int $companyId, int $invoiceId): float {
    $hasApplied = co_db_column_exists($conn, 'co_client_invoices', 'prepaid_vat_applied');
    $appliedSelect = $hasApplied ? 'i.prepaid_vat_applied' : '0 AS prepaid_vat_applied';
    $stmt = $conn->prepare("
        SELECT i.id, i.total_amount, i.subtotal, i.vat_amount, i.source_type, i.source_id,
               {$appliedSelect},
               COALESCE(SUM(a.allocated_amount), 0) AS allocated_amount
        FROM co_client_invoices i
        LEFT JOIN co_client_payment_allocations a
               ON a.invoice_id = i.id AND a.company_id = i.company_id
        WHERE i.id = ? AND i.company_id = ? AND i.status <> 'cancelled'
        GROUP BY i.id, i.total_amount, i.subtotal, i.vat_amount, i.source_type, i.source_id
                 " . ($hasApplied ? ', i.prepaid_vat_applied' : '') . "
    ");
    $stmt->execute([$invoiceId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 0.0;
    }
    $collectible = co_shop_invoice_collectible_amount($conn, $companyId, $row);
    return max(0, round($collectible - (float)$row['allocated_amount'], 2));
}

/**
 * Shop-rental rent invoice on a Separate VAT contract (monthly Tax Invoice).
 * VAT is recognized on this invoice; cash may already be held in prepaid liability.
 */
function co_shop_invoice_has_prepaid_separate_vat(PDO $conn, int $companyId, array $invoice): bool {
    if (($invoice['source_type'] ?? '') !== 'shop_rental') {
        return false;
    }
    $sourceId = (int)($invoice['source_id'] ?? 0);
    if ($sourceId <= 0 || (float)($invoice['vat_amount'] ?? 0) <= 0.005) {
        return false;
    }
    try {
        $st = $conn->prepare("
            SELECT COALESCE(s.schedule_type, 'rent') AS schedule_type,
                   COALESCE(c.vat_collection_method, 'included_in_installment') AS vat_collection_method
            FROM co_shop_rent_schedules s
            JOIN co_shop_rental_contracts c ON c.id = s.contract_id AND c.company_id = s.company_id
            WHERE s.id = ? AND s.company_id = ?
            LIMIT 1
        ");
        $st->execute([$sourceId, $companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $method = co_shop_normalize_vat_collection($row['vat_collection_method'] ?? CO_SHOP_VAT_INCLUDED);
        return $method === CO_SHOP_VAT_SEPARATE && (($row['schedule_type'] ?? 'rent') === 'rent');
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Amount the tenant still owes against an invoice (excludes prepaid VAT already applied).
 */
function co_shop_invoice_collectible_amount(PDO $conn, int $companyId, array $invoice): float {
    $total = round((float)($invoice['total_amount'] ?? 0), 2);
    $applied = round((float)($invoice['prepaid_vat_applied'] ?? 0), 2);
    if ($applied <= 0.005 && array_key_exists('id', $invoice) && co_db_column_exists($conn, 'co_client_invoices', 'prepaid_vat_applied')) {
        $st = $conn->prepare("SELECT prepaid_vat_applied FROM co_client_invoices WHERE id = ? AND company_id = ?");
        $st->execute([(int)$invoice['id'], $companyId]);
        $applied = round((float)$st->fetchColumn(), 2);
    }
    if ($applied > 0.005) {
        return max(0.0, round($total - $applied, 2));
    }
    return $total;
}

/**
 * True when invoice is a legacy Separate VAT collection schedule (retired workflow).
 */
function co_shop_invoice_is_separate_vat_collection(PDO $conn, int $companyId, array $invoice): bool {
    if (($invoice['source_type'] ?? '') !== 'shop_rental') {
        return false;
    }
    $sourceId = (int)($invoice['source_id'] ?? 0);
    if ($sourceId <= 0) {
        return false;
    }
    try {
        $st = $conn->prepare("
            SELECT COALESCE(s.schedule_type, 'rent') AS schedule_type
            FROM co_shop_rent_schedules s
            WHERE s.id = ? AND s.company_id = ?
            LIMIT 1
        ");
        $st->execute([$sourceId, $companyId]);
        return (($st->fetchColumn() ?: 'rent') === 'vat');
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Contract prepaid VAT cash balance (liability 2330), schema-safe.
 */
function co_shop_contract_prepaid_vat_balance(PDO $conn, int $companyId, int $contractId): float {
    if (!co_db_column_exists($conn, 'co_shop_rental_contracts', 'prepaid_vat_balance')) {
        return 0.0;
    }
    $st = $conn->prepare("SELECT prepaid_vat_balance FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $st->execute([$contractId, $companyId]);
    return round((float)$st->fetchColumn(), 2);
}

/**
 * Increase prepaid VAT balance after a Separate VAT cash receipt.
 */
function co_shop_add_prepaid_vat_balance(PDO $conn, int $companyId, int $contractId, float $amount): void {
    $amount = round($amount, 2);
    if ($amount <= 0.005 || !co_db_column_exists($conn, 'co_shop_rental_contracts', 'prepaid_vat_balance')) {
        return;
    }
    $conn->prepare("
        UPDATE co_shop_rental_contracts
        SET prepaid_vat_balance = ROUND(prepaid_vat_balance + ?, 2)
        WHERE id = ? AND company_id = ?
    ")->execute([$amount, $contractId, $companyId]);
}

/**
 * After a monthly Tax Invoice posts (full Output VAT + AR), consume prepaid VAT:
 * Dr 2330 / Cr 1310 for min(invoice VAT remaining, contract prepaid balance).
 *
 * @return array{applied:float,journal_id:?int}
 */
function co_shop_apply_prepaid_vat_to_invoice(PDO $conn, int $companyId, int $invoiceId, ?int $createdBy = null): array {
    if ($companyId <= 0 || $invoiceId <= 0) {
        return ['applied' => 0.0, 'journal_id' => null];
    }
    if (!co_db_column_exists($conn, 'co_client_invoices', 'prepaid_vat_applied')
        || !co_db_column_exists($conn, 'co_shop_rental_contracts', 'prepaid_vat_balance')) {
        return ['applied' => 0.0, 'journal_id' => null];
    }

    $st = $conn->prepare("
        SELECT i.id, i.invoice_number, i.invoice_date, i.subtotal, i.vat_amount, i.total_amount,
               i.source_type, i.source_id, i.prepaid_vat_applied, i.client_id,
               s.contract_id, COALESCE(c.vat_collection_method, 'included_in_installment') AS vat_collection_method,
               COALESCE(s.schedule_type, 'rent') AS schedule_type,
               COALESCE(c.prepaid_vat_balance, 0) AS prepaid_vat_balance
        FROM co_client_invoices i
        LEFT JOIN co_shop_rent_schedules s
               ON s.id = i.source_id AND s.company_id = i.company_id AND i.source_type = 'shop_rental'
        LEFT JOIN co_shop_rental_contracts c
               ON c.id = s.contract_id AND c.company_id = i.company_id
        WHERE i.id = ? AND i.company_id = ? AND i.status <> 'cancelled'
        LIMIT 1
    ");
    $st->execute([$invoiceId, $companyId]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv || ($inv['source_type'] ?? '') !== 'shop_rental') {
        return ['applied' => 0.0, 'journal_id' => null];
    }
    if (co_shop_normalize_vat_collection($inv['vat_collection_method'] ?? '') !== CO_SHOP_VAT_SEPARATE) {
        return ['applied' => 0.0, 'journal_id' => null];
    }
    if (($inv['schedule_type'] ?? 'rent') !== 'rent') {
        return ['applied' => 0.0, 'journal_id' => null];
    }

    $vat = round((float)$inv['vat_amount'], 2);
    $already = round((float)$inv['prepaid_vat_applied'], 2);
    $remainingVat = max(0.0, round($vat - $already, 2));
    $balance = round((float)$inv['prepaid_vat_balance'], 2);
    $apply = round(min($remainingVat, $balance), 2);
    if ($apply <= 0.005) {
        return ['applied' => 0.0, 'journal_id' => null];
    }

    require_once dirname(__DIR__, 2) . '/realestate/accounting/accounting_engine.php';
    if (!defined('CO_ACCOUNT_PREPAID_OUTPUT_VAT')) {
        require_once __DIR__ . '/construction_accounting_integration.php';
    }

    $arAccount = find_account_by_code(CO_ACCOUNT_AR, $companyId);
    $prepaidAccount = find_account_by_code(CO_ACCOUNT_PREPAID_OUTPUT_VAT, $companyId);
    if (!$arAccount || !$prepaidAccount) {
        throw new RuntimeException('AR (1310) or VAT Collected in Advance (2330) account not found. Run construction COA setup.');
    }

    $date = $inv['invoice_date'] ?: date('Y-m-d');
    $desc = 'Prepaid VAT applied to ' . ($inv['invoice_number'] ?: ('invoice #' . $invoiceId));
    $ref = ($inv['invoice_number'] ?: ('CO-CINV-' . $invoiceId)) . '-PPV';
    $lines = [
        [
            'account_id' => $prepaidAccount['id'],
            'debit' => $apply,
            'credit' => 0,
            'description' => $desc,
            'reference' => $ref,
        ],
        [
            'account_id' => $arAccount['id'],
            'debit' => 0,
            'credit' => $apply,
            'description' => $desc . ' (reduce AR)',
            'reference' => $ref,
        ],
    ];
    $jr = create_and_post_journal(
        $companyId,
        'adjustment',
        'co_prepaid_vat_apply',
        $invoiceId,
        $lines,
        $desc,
        $date,
        $createdBy
    );
    if (empty($jr['success'])) {
        throw new RuntimeException($jr['error'] ?? 'Prepaid VAT apply journal failed.');
    }

    $conn->prepare("
        UPDATE co_client_invoices
        SET prepaid_vat_applied = ROUND(prepaid_vat_applied + ?, 2)
        WHERE id = ? AND company_id = ?
    ")->execute([$apply, $invoiceId, $companyId]);
    $conn->prepare("
        UPDATE co_shop_rental_contracts
        SET prepaid_vat_balance = ROUND(GREATEST(0, prepaid_vat_balance - ?), 2)
        WHERE id = ? AND company_id = ?
    ")->execute([$apply, (int)$inv['contract_id'], $companyId]);

    if (function_exists('co_update_client_invoice_status')) {
        co_update_client_invoice_status($conn, $companyId, $invoiceId);
    }

    return ['applied' => $apply, 'journal_id' => isset($jr['journal_id']) ? (int)$jr['journal_id'] : null];
}

/**
 * Record Separate VAT cash as a Payment Receipt only (no Tax Invoice, no AR).
 * Posts Dr Bank / Cr 2330 and increases contract prepaid_vat_balance.
 *
 * @return array{payment_id:int,journal_id:int,amount:float}
 */
function co_shop_record_prepaid_vat_receipt(
    PDO $conn,
    int $companyId,
    int $clientId,
    int $contractId,
    float $amount,
    int $payAccountId,
    string $paymentDate,
    ?string $reference,
    ?int $userId
): array {
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }
    if ($payAccountId <= 0) {
        throw new RuntimeException('Choose the bank/cash account that received this payment.');
    }
    if ($companyId <= 0 || $clientId <= 0 || $contractId <= 0) {
        throw new RuntimeException('Company, client and contract are required.');
    }

    $contract = co_shop_contract_load($conn, $companyId, $contractId);
    if (!$contract) {
        throw new RuntimeException('Contract not found.');
    }
    if ((int)($contract['client_id'] ?? 0) !== $clientId) {
        throw new RuntimeException('Client does not match this contract.');
    }
    if (co_shop_normalize_vat_collection($contract['vat_collection_method'] ?? '') !== CO_SHOP_VAT_SEPARATE) {
        throw new RuntimeException('Prepaid VAT receipts apply only to Separate VAT contracts.');
    }

    $hasPurpose = co_db_column_exists($conn, 'co_client_payments', 'receipt_purpose');
    $hasExtra = co_db_column_exists($conn, 'co_client_payments', 'unallocated_amount');
    $hasContract = co_db_column_exists($conn, 'co_client_payments', 'contract_id');

    if ($hasPurpose && $hasExtra && $hasContract) {
        $hasPrepaidCol = co_db_column_exists($conn, 'co_client_payments', 'prepaid_vat_amount');
        if ($hasPrepaidCol) {
            $payStmt = $conn->prepare("
                INSERT INTO co_client_payments
                    (company_id, invoice_id, client_id, contract_id, payment_date, amount, unallocated_amount, credit_amount,
                     prepaid_vat_amount, allocation_status, pay_account_id, reference, receipt_purpose, created_by)
                VALUES (?, NULL, ?, ?, ?, ?, 0, 0, ?, 'allocated', ?, ?, 'prepaid_output_vat', ?)
            ");
            $payStmt->execute([
                $companyId, $clientId, $contractId, $paymentDate, $amount, $amount,
                $payAccountId, $reference, $userId,
            ]);
        } else {
            $payStmt = $conn->prepare("
                INSERT INTO co_client_payments
                    (company_id, invoice_id, client_id, contract_id, payment_date, amount, unallocated_amount, credit_amount,
                     allocation_status, pay_account_id, reference, receipt_purpose, created_by)
                VALUES (?, NULL, ?, ?, ?, ?, 0, 0, 'allocated', ?, ?, 'prepaid_output_vat', ?)
            ");
            $payStmt->execute([
                $companyId, $clientId, $contractId, $paymentDate, $amount,
                $payAccountId, $reference, $userId,
            ]);
        }
    } elseif ($hasExtra && $hasContract) {
        $payStmt = $conn->prepare("
            INSERT INTO co_client_payments
                (company_id, invoice_id, client_id, contract_id, payment_date, amount, unallocated_amount, credit_amount,
                 allocation_status, pay_account_id, reference, created_by)
            VALUES (?, NULL, ?, ?, ?, ?, 0, 0, 'allocated', ?, ?, ?)
        ");
        $payStmt->execute([
            $companyId, $clientId, $contractId, $paymentDate, $amount,
            $payAccountId, $reference, $userId,
        ]);
    } else {
        throw new RuntimeException('Payment workspace schema is required for prepaid VAT receipts. Run migrations.');
    }
    $paymentId = (int)$conn->lastInsertId();

    co_shop_add_prepaid_vat_balance($conn, $companyId, $contractId, $amount);

    if (!function_exists('co_post_client_payment_to_accounting')) {
        require_once __DIR__ . '/construction_accounting_integration.php';
    }
    // Ensure purpose is visible to poster even if column insert path differed
    if ($hasPurpose) {
        $conn->prepare("UPDATE co_client_payments SET receipt_purpose = 'prepaid_output_vat' WHERE id = ? AND company_id = ?")
            ->execute([$paymentId, $companyId]);
    }

    $postResult = co_post_client_payment_to_accounting($paymentId, $companyId, $userId);
    if (!$postResult['success']) {
        throw new RuntimeException($postResult['error'] ?? 'Prepaid VAT receipt posting failed.');
    }
    $conn->prepare("UPDATE co_client_payments SET journal_id = ? WHERE id = ? AND company_id = ?")
        ->execute([$postResult['journal_id'], $paymentId, $companyId]);

    // Auto-consume against any open Separate VAT rent invoices already posted
    if (function_exists('co_shop_contract_financial_summary')) {
        $summary = co_shop_contract_financial_summary($conn, $companyId, $contractId);
        foreach ($summary['open_invoices'] ?? [] as $openInv) {
            if (($openInv['kind'] ?? '') !== 'rent') {
                continue;
            }
            if (co_shop_contract_prepaid_vat_balance($conn, $companyId, $contractId) <= 0.005) {
                break;
            }
            co_shop_apply_prepaid_vat_to_invoice($conn, $companyId, (int)$openInv['id'], $userId);
        }
    }

    return [
        'payment_id' => $paymentId,
        'journal_id' => (int)$postResult['journal_id'],
        'amount' => $amount,
        'allocated' => 0.0,
        'credit' => 0.0,
        'allocations' => [],
    ];
}

function co_shop_get_client_credit(PDO $conn, int $companyId, int $clientId): float {
    if (!co_db_table_exists($conn, 'co_client_credit_balances')) {
        return 0.0;
    }
    $stmt = $conn->prepare("SELECT balance_aed FROM co_client_credit_balances WHERE company_id = ? AND client_id = ?");
    $stmt->execute([$companyId, $clientId]);
    return round((float)($stmt->fetchColumn() ?: 0), 2);
}

function co_shop_adjust_client_credit(
    PDO $conn,
    int $companyId,
    int $clientId,
    float $delta,
    string $txnType,
    ?int $paymentId = null,
    ?int $invoiceId = null,
    ?string $notes = null,
    ?int $userId = null
): void {
    if (!co_db_table_exists($conn, 'co_client_credit_balances') || abs($delta) < 0.005) {
        return;
    }
    $conn->prepare("
        INSERT INTO co_client_credit_balances (client_id, company_id, balance_aed)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE balance_aed = ROUND(balance_aed + VALUES(balance_aed), 2)
    ")->execute([$clientId, $companyId, $delta]);

    if (co_db_table_exists($conn, 'co_client_credit_transactions')) {
        $conn->prepare("
            INSERT INTO co_client_credit_transactions
                (company_id, client_id, payment_id, invoice_id, txn_type, amount, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $companyId, $clientId, $paymentId, $invoiceId, $txnType, $delta, $notes, $userId,
        ]);
    }
}


/**
 * Record invoice payment with allocation + overpayment credit.
 */
function co_shop_record_invoice_payment(
    PDO $conn,
    int $companyId,
    int $invoiceId,
    float $amount,
    int $payAccountId,
    string $paymentDate,
    ?string $reference,
    ?int $userId
): array {
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }
    if ($payAccountId <= 0) {
        throw new RuntimeException('Choose the bank/cash account that received this payment.');
    }

    $invStmt = $conn->prepare("
        SELECT id, client_id, total_amount, status
        FROM co_client_invoices
        WHERE id = ? AND company_id = ? AND status <> 'cancelled'
    ");
    $invStmt->execute([$invoiceId, $companyId]);
    $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        throw new RuntimeException('Invoice was not found.');
    }

    $open = co_shop_invoice_open_balance($conn, $companyId, $invoiceId);
    $allocate = min($amount, $open);
    $credit = round($amount - $allocate, 2);

    $hasExtra = co_db_column_exists($conn, 'co_client_payments', 'unallocated_amount');
    if ($hasExtra) {
        $payStmt = $conn->prepare("
            INSERT INTO co_client_payments
                (company_id, invoice_id, client_id, payment_date, amount, unallocated_amount, credit_amount, pay_account_id, reference, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $payStmt->execute([
            $companyId, $invoiceId, (int)$invoice['client_id'], $paymentDate, $amount,
            $credit, $credit, $payAccountId, $reference, $userId,
        ]);
    } else {
        $payStmt = $conn->prepare("
            INSERT INTO co_client_payments
                (company_id, invoice_id, client_id, payment_date, amount, pay_account_id, reference, created_by)
            VALUES (?,?,?,?,?,?,?,?)
        ");
        $payStmt->execute([
            $companyId, $invoiceId, (int)$invoice['client_id'], $paymentDate, $amount,
            $payAccountId, $reference, $userId,
        ]);
    }
    $paymentId = (int)$conn->lastInsertId();

    if ($allocate > 0) {
        co_allocate_client_payment($conn, $companyId, (int)$invoice['client_id'], $paymentId, $invoiceId, $allocate);
    }
    if ($credit > 0) {
        co_shop_adjust_client_credit(
            $conn, $companyId, (int)$invoice['client_id'], $credit, 'overpayment',
            $paymentId, $invoiceId, 'Overpayment on invoice #' . $invoiceId, $userId
        );
    }

    $postResult = co_post_client_payment_to_accounting($paymentId, $companyId, $userId);
    if (!$postResult['success']) {
        throw new RuntimeException($postResult['error'] ?? 'Receipt posting failed.');
    }
    $conn->prepare("UPDATE co_client_payments SET journal_id = ? WHERE id = ? AND company_id = ?")
        ->execute([$postResult['journal_id'], $paymentId, $companyId]);
    co_update_client_invoice_status($conn, $companyId, $invoiceId);

    return [
        'payment_id' => $paymentId,
        'journal_id' => (int)$postResult['journal_id'],
        'allocated' => $allocate,
        'credit' => $credit,
    ];
}

/**
 * Record one bank receipt and allocate across multiple open invoices (FIFO / ordered list).
 * Aligns with BR-CO-SHOP-007: cheque clear allocates to open invoices.
 *
 * @param list<int> $invoiceIds Ordered preferred invoice ids (empty = caller supplies open list order via $targets)
 * @param list<array{id:int,balance:float}> $targets Open invoices in allocation order
 * @return array{payment_id:int,journal_id:int,allocated:float,credit:float,allocations:list<array{invoice_id:int,amount:float}>}
 */
function co_shop_record_multi_invoice_payment(
    PDO $conn,
    int $companyId,
    int $clientId,
    float $amount,
    int $payAccountId,
    string $paymentDate,
    ?string $reference,
    array $targets,
    ?int $userId,
    float $prepaidVatAmount = 0.0,
    ?int $contractId = null
): array {
    $amount = round($amount, 2);
    $prepaidVatAmount = round($prepaidVatAmount, 2);
    if ($amount <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }
    if ($payAccountId <= 0) {
        throw new RuntimeException('Choose the bank/cash account that received this payment.');
    }
    if (!$targets && $prepaidVatAmount <= 0.005) {
        throw new RuntimeException('No open invoices available to allocate this payment.');
    }

    $plan = [];
    $remaining = round($amount - $prepaidVatAmount, 2);
    if ($remaining < -0.005) {
        throw new RuntimeException('Prepaid VAT amount exceeds payment amount.');
    }
    if ($remaining < 0) {
        $remaining = 0.0;
    }
    foreach ($targets as $t) {
        if ($remaining <= 0.005) {
            break;
        }
        $invId = (int)($t['id'] ?? 0);
        $bal = round((float)($t['balance'] ?? 0), 2);
        if ($invId <= 0 || $bal <= 0.005) {
            continue;
        }
        $apply = min($remaining, $bal);
        if ($apply <= 0.005) {
            continue;
        }
        $plan[] = ['invoice_id' => $invId, 'amount' => $apply];
        $remaining = round($remaining - $apply, 2);
    }
    if (!$plan && $prepaidVatAmount <= 0.005) {
        throw new RuntimeException('Could not allocate payment — selected invoices have no open balance.');
    }
    $allocatedTotal = round(array_sum(array_column($plan, 'amount')), 2);
    $credit = round($amount - $allocatedTotal - $prepaidVatAmount, 2);
    if ($credit < 0) {
        $credit = 0.0;
    }
    $primaryInvoiceId = $plan ? (int)$plan[0]['invoice_id'] : null;

    $hasExtra = co_db_column_exists($conn, 'co_client_payments', 'unallocated_amount');
    $hasPurpose = co_db_column_exists($conn, 'co_client_payments', 'receipt_purpose');
    $hasPrepaidCol = co_db_column_exists($conn, 'co_client_payments', 'prepaid_vat_amount');
    $hasContract = co_db_column_exists($conn, 'co_client_payments', 'contract_id');
    $purpose = ($prepaidVatAmount + 0.005 >= $amount && $allocatedTotal <= 0.005)
        ? 'prepaid_output_vat'
        : 'invoice_allocation';

    if ($hasExtra && $hasPurpose && $hasPrepaidCol && $hasContract) {
        $payStmt = $conn->prepare("
            INSERT INTO co_client_payments
                (company_id, invoice_id, client_id, contract_id, payment_date, amount, unallocated_amount, credit_amount,
                 prepaid_vat_amount, receipt_purpose, pay_account_id, reference, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $payStmt->execute([
            $companyId, $primaryInvoiceId, $clientId, $contractId, $paymentDate, $amount,
            $credit, $credit, $prepaidVatAmount, $purpose, $payAccountId, $reference, $userId,
        ]);
    } elseif ($hasExtra) {
        $payStmt = $conn->prepare("
            INSERT INTO co_client_payments
                (company_id, invoice_id, client_id, payment_date, amount, unallocated_amount, credit_amount, pay_account_id, reference, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $payStmt->execute([
            $companyId, $primaryInvoiceId, $clientId, $paymentDate, $amount,
            $credit, $credit, $payAccountId, $reference, $userId,
        ]);
    } else {
        $payStmt = $conn->prepare("
            INSERT INTO co_client_payments
                (company_id, invoice_id, client_id, payment_date, amount, pay_account_id, reference, created_by)
            VALUES (?,?,?,?,?,?,?,?)
        ");
        $payStmt->execute([
            $companyId, $primaryInvoiceId, $clientId, $paymentDate, $amount,
            $payAccountId, $reference, $userId,
        ]);
    }
    $paymentId = (int)$conn->lastInsertId();

    foreach ($plan as $row) {
        co_allocate_client_payment($conn, $companyId, $clientId, $paymentId, (int)$row['invoice_id'], (float)$row['amount']);
    }
    if ($credit > 0.005) {
        co_shop_adjust_client_credit(
            $conn, $companyId, $clientId, $credit, 'overpayment',
            $paymentId, $primaryInvoiceId, 'Overpayment / unallocated receipt', $userId
        );
    }
    if ($prepaidVatAmount > 0.005 && $contractId) {
        co_shop_add_prepaid_vat_balance($conn, $companyId, $contractId, $prepaidVatAmount);
    }

    $postResult = co_post_client_payment_to_accounting($paymentId, $companyId, $userId);
    if (!$postResult['success']) {
        throw new RuntimeException($postResult['error'] ?? 'Receipt posting failed.');
    }
    $conn->prepare("UPDATE co_client_payments SET journal_id = ? WHERE id = ? AND company_id = ?")
        ->execute([$postResult['journal_id'], $paymentId, $companyId]);
    foreach ($plan as $row) {
        co_update_client_invoice_status($conn, $companyId, (int)$row['invoice_id']);
    }

    if ($prepaidVatAmount > 0.005 && $contractId) {
        $summary = co_shop_contract_financial_summary($conn, $companyId, $contractId);
        foreach ($summary['open_invoices'] ?? [] as $openInv) {
            if (($openInv['kind'] ?? '') !== 'rent') {
                continue;
            }
            if (co_shop_contract_prepaid_vat_balance($conn, $companyId, $contractId) <= 0.005) {
                break;
            }
            co_shop_apply_prepaid_vat_to_invoice($conn, $companyId, (int)$openInv['id'], $userId);
        }
    }

    return [
        'payment_id' => $paymentId,
        'journal_id' => (int)$postResult['journal_id'],
        'allocated' => $allocatedTotal,
        'credit' => $credit,
        'prepaid_vat' => $prepaidVatAmount,
        'allocations' => $plan,
    ];
}

/**
 * Clear a rent/VAT cheque by posting bank receipt against accountant-selected invoices.
 * BR-CO-SHOP-007: cheque clear allocates to open invoices — selection is explicit.
 *
 * @param list<int> $invoiceIds Required non-empty ordered invoice ids chosen by the accountant
 * @param array<int,float> $amountByInvoice Optional per-invoice allocate amounts (invoice_id => amount)
 * @param string|null $reference Bank / reconciliation reference (falls back to cheque number)
 * @param bool $allowMixed When true, Rent + VAT + Commission may be selected on one receipt (one GL journal)
 */
function co_shop_clear_cheque_allocate_invoices(
    PDO $conn,
    int $companyId,
    int $contractId,
    int $chequeId,
    int $payAccountId,
    string $clearDate,
    ?float $payAmount,
    array $invoiceIds,
    ?int $userId,
    ?string $reference = null,
    array $amountByInvoice = [],
    bool $allowMixed = false
): array {
    $stmt = $conn->prepare("
        SELECT * FROM co_shop_rent_cheques
        WHERE id = ? AND company_id = ? AND contract_id = ? AND status <> 'cleared' AND status <> 'cancelled'
    ");
    $stmt->execute([$chequeId, $companyId, $contractId]);
    $cheque = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cheque) {
        throw new RuntimeException('Cheque not found or already cleared.');
    }
    if (($cheque['cheque_type'] ?? '') === 'security_deposit') {
        throw new RuntimeException('Use Record Deposit for security deposit cheques.');
    }

    $invoiceIds = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));
    if (!$invoiceIds) {
        throw new RuntimeException('Select at least one invoice to allocate this cheque against.');
    }

    $cStmt = $conn->prepare("SELECT client_id FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $cStmt->execute([$contractId, $companyId]);
    $clientId = (int)$cStmt->fetchColumn();
    if ($clientId <= 0) {
        throw new RuntimeException('Contract client missing.');
    }

    $summary = co_shop_contract_financial_summary($conn, $companyId, $contractId);
    $isVatSep = (($cheque['notes'] ?? '') === 'VAT_SEPARATE');
    if ($isVatSep && !$allowMixed) {
        throw new RuntimeException(
            'Separate VAT cheques must be cleared via Payment Workspace as a prepaid VAT receipt '
            . '(Dr Bank / Cr 2330). Do not allocate against invoices.'
        );
    }
    $expectedKind = 'rent';
    $byId = [];
    foreach ($summary['open_invoices'] ?? [] as $inv) {
        $byId[(int)$inv['id']] = $inv;
    }
    // Commission is a separate invoice source — include when mixed combined payment is allowed
    if ($allowMixed && !empty($summary['commission']['invoice']) && (($summary['commission']['outstanding'] ?? 0) > 0.005)) {
        $cInv = $summary['commission']['invoice'];
        $cId = (int)($cInv['id'] ?? 0);
        if ($cId > 0) {
            $byId[$cId] = [
                'id' => $cId,
                'invoice_number' => $cInv['invoice_number'] ?? ('#' . $cId),
                'balance' => (float)($cInv['balance'] ?? $summary['commission']['outstanding']),
                'kind' => 'commission',
                'kind_label' => 'Commission',
                'due_date' => $cInv['due_date'] ?? null,
            ];
        }
    }

    $targets = [];
    $chequeAmt = round((float)$cheque['amount'], 2);
    $amount = ($payAmount !== null && $payAmount > 0) ? round($payAmount, 2) : $chequeAmt;
    $remaining = $amount;

    // Combined bank instrument may exceed the original operational PDC face value
    if ($allowMixed && $amount > $chequeAmt + 0.005) {
        $conn->prepare("UPDATE co_shop_rent_cheques SET amount = ? WHERE id = ? AND company_id = ? AND contract_id = ?")
            ->execute([$amount, $chequeId, $companyId, $contractId]);
        $chequeAmt = $amount;
    }

    foreach ($invoiceIds as $invId) {
        if (!isset($byId[$invId])) {
            throw new RuntimeException('Invoice #' . $invId . ' is not an open invoice on this contract.');
        }
        $inv = $byId[$invId];
        $kind = (string)($inv['kind'] ?? 'rent');
        if (!$allowMixed && $kind !== $expectedKind) {
            throw new RuntimeException(
                'Invoice ' . $inv['invoice_number'] . ' is ' . strtoupper($kind)
                . ' but this cheque is for ' . strtoupper($expectedKind)
                . '. Enable “Combined payment” to allocate Rent + VAT + Commission on one receipt, or choose matching invoice type(s) only.'
            );
        }
        if ($allowMixed && !in_array($kind, ['rent', 'vat', 'commission'], true)) {
            throw new RuntimeException('Unsupported invoice type for combined allocation: ' . $kind);
        }
        $openBal = round((float)$inv['balance'], 2);
        if ($openBal <= 0.005) {
            throw new RuntimeException('Invoice ' . $inv['invoice_number'] . ' has no open balance.');
        }
        $requested = null;
        if (isset($amountByInvoice[$invId]) && (float)$amountByInvoice[$invId] > 0) {
            $requested = round((float)$amountByInvoice[$invId], 2);
        }
        if ($requested !== null) {
            if ($requested > $openBal + 0.005) {
                throw new RuntimeException(
                    'Allocate amount for ' . $inv['invoice_number'] . ' (' . number_format($requested, 2)
                    . ') exceeds open balance (' . number_format($openBal, 2) . ').'
                );
            }
            $apply = $requested;
        } else {
            if ($remaining <= 0.005) {
                break;
            }
            $apply = min($remaining, $openBal);
        }
        if ($apply <= 0.005) {
            continue;
        }
        $targets[] = [
            'id' => $invId,
            'balance' => $apply,
            'invoice_number' => $inv['invoice_number'] ?? '',
        ];
        $remaining = round($remaining - $apply, 2);
    }
    if (!$targets) {
        throw new RuntimeException('No allocation amounts to post. Check selected invoices and amounts.');
    }

    $planned = round(array_sum(array_column($targets, 'balance')), 2);
    $postAmount = $amount;
    if ($planned + 0.005 < $amount && $amountByInvoice) {
        $postAmount = $planned;
    }

    $ref = trim((string)($reference ?? ''));
    if ($ref === '') {
        $ref = trim((string)($cheque['cheque_number'] ?? '')) ?: ('SHOP-CHEQUE-' . $chequeId);
    }

    $payResult = co_shop_record_multi_invoice_payment(
        $conn, $companyId, $clientId, $postAmount, $payAccountId, $clearDate, $ref, $targets, $userId
    );

    $clearedNow = ($postAmount + 0.005 >= $chequeAmt);
    if ($clearedNow) {
        $conn->prepare("
            UPDATE co_shop_rent_cheques
            SET status = 'cleared', payment_id = ?, journal_id = ?, invoice_id = COALESCE(invoice_id, ?)
            WHERE id = ? AND company_id = ?
        ")->execute([
            $payResult['payment_id'], $payResult['journal_id'],
            (int)$payResult['allocations'][0]['invoice_id'], $chequeId, $companyId,
        ]);
        $chequeStatus = 'cleared';
    } else {
        $conn->prepare("
            UPDATE co_shop_rent_cheques
            SET status = 'deposited', payment_id = ?, journal_id = ?
            WHERE id = ? AND company_id = ?
        ")->execute([$payResult['payment_id'], $payResult['journal_id'], $chequeId, $companyId]);
        $chequeStatus = 'deposited';
    }

    if (function_exists('co_shop_log_event')) {
        co_shop_log_event($conn, $companyId, $contractId, 'cheque_cleared_allocated', [
            'cheque_id' => $chequeId,
            'mixed' => $allowMixed ? 1 : 0,
            'payment_id' => $payResult['payment_id'],
            'journal_id' => $payResult['journal_id'],
            'amount' => $postAmount,
            'reference' => $ref,
            'invoice_ids' => array_column($payResult['allocations'], 'invoice_id'),
        ], $userId);
    }

    return array_merge($payResult, [
        'cheque_status' => $chequeStatus,
        'cheque_id' => $chequeId,
        'reference' => $ref,
        'posted_amount' => $postAmount,
        'mixed' => $allowMixed,
    ]);
}

function co_shop_apply_client_credit_to_invoice(
    PDO $conn,
    int $companyId,
    int $clientId,
    int $invoiceId,
    float $amount,
    ?int $userId
): array {
    $amount = round($amount, 2);
    $available = co_shop_get_client_credit($conn, $companyId, $clientId);
    $open = co_shop_invoice_open_balance($conn, $companyId, $invoiceId);
    $apply = min($amount, $available, $open);
    if ($apply <= 0) {
        throw new RuntimeException('No credit available to apply.');
    }

    // Insert payment row first so journal reference_id is stable (idempotent on re-post attempts).
    $payStmt = $conn->prepare("
        INSERT INTO co_client_payments
            (company_id, invoice_id, client_id, payment_date, amount, pay_account_id, reference, created_by)
        VALUES (?,?,?,?,?,?,?,?)
    ");
    $payStmt->execute([
        $companyId, $invoiceId, $clientId, date('Y-m-d'), $apply, null,
        'CREDIT-APPLY', $userId,
    ]);
    $paymentId = (int)$conn->lastInsertId();

    $result = co_post_client_credit_application_to_accounting(
        $companyId,
        $clientId,
        $invoiceId,
        $apply,
        $userId,
        $paymentId
    );
    if (!$result['success']) {
        $conn->prepare("DELETE FROM co_client_payments WHERE id = ? AND company_id = ?")
            ->execute([$paymentId, $companyId]);
        throw new RuntimeException($result['error'] ?? 'Credit application posting failed.');
    }

    $conn->prepare("UPDATE co_client_payments SET journal_id = ? WHERE id = ? AND company_id = ?")
        ->execute([(int)$result['journal_id'], $paymentId, $companyId]);
    co_allocate_client_payment($conn, $companyId, $clientId, $paymentId, $invoiceId, $apply);
    co_shop_adjust_client_credit(
        $conn, $companyId, $clientId, -$apply, 'apply', $paymentId, $invoiceId, 'Applied credit to invoice', $userId
    );
    co_update_client_invoice_status($conn, $companyId, $invoiceId);

    return ['payment_id' => $paymentId, 'journal_id' => (int)$result['journal_id'], 'applied' => $apply];
}

function co_shop_record_deposit_receipt(
    PDO $conn,
    int $companyId,
    int $contractId,
    float $amount,
    int $payAccountId,
    string $receiptDate,
    ?int $chequeId,
    ?string $reference,
    ?int $userId
): array {
    $amount = round($amount, 2);
    if ($amount <= 0 || $payAccountId <= 0) {
        throw new RuntimeException('Deposit amount and received-to account are required.');
    }
    if (!co_db_table_exists($conn, 'co_shop_deposit_receipts')) {
        throw new RuntimeException('Run migrations/construction_shop_rental_phase1.sql to enable deposit receipts.');
    }

    $stmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$contractId, $companyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        throw new RuntimeException('Shop rental contract not found.');
    }

    $remaining = round((float)$contract['security_deposit'] - (float)$contract['deposit_received_amount'], 2);
    if ($amount - $remaining > 0.005) {
        throw new RuntimeException('Deposit exceeds remaining security deposit balance (' . number_format($remaining, 2) . ').');
    }

    if ($chequeId) {
        $cStmt = $conn->prepare("
            SELECT * FROM co_shop_rent_cheques
            WHERE id = ? AND company_id = ? AND contract_id = ?
              AND cheque_type = 'security_deposit' AND status <> 'cleared' AND status <> 'cancelled'
        ");
        $cStmt->execute([$chequeId, $companyId, $contractId]);
        $cheque = $cStmt->fetch(PDO::FETCH_ASSOC);
        if (!$cheque) {
            throw new RuntimeException('Deposit cheque not found or already cleared.');
        }
        $dup = $conn->prepare("SELECT id FROM co_shop_deposit_receipts WHERE cheque_id = ? AND company_id = ?");
        $dup->execute([$chequeId, $companyId]);
        if ($dup->fetchColumn()) {
            throw new RuntimeException('This deposit cheque already has a posted receipt.');
        }
        if (abs((float)$cheque['amount'] - $amount) > 0.005) {
            throw new RuntimeException('Deposit amount must match the linked cheque amount.');
        }
    }

    $ins = $conn->prepare("
        INSERT INTO co_shop_deposit_receipts
            (company_id, contract_id, cheque_id, amount, receipt_date, pay_account_id, reference, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
        $companyId, $contractId, $chequeId ?: null, $amount, $receiptDate,
        $payAccountId, $reference, $userId,
    ]);
    $receiptId = (int)$conn->lastInsertId();

    $postResult = co_post_shop_deposit_to_accounting(
        $contractId, $companyId, $amount, $payAccountId, $receiptDate, $reference ?: '', $userId, $receiptId
    );
    if (!$postResult['success']) {
        throw new RuntimeException($postResult['error'] ?? 'Deposit posting failed.');
    }

    $conn->prepare("UPDATE co_shop_deposit_receipts SET journal_id = ? WHERE id = ? AND company_id = ?")
        ->execute([$postResult['journal_id'], $receiptId, $companyId]);
    $conn->prepare("
        UPDATE co_shop_rental_contracts
        SET deposit_received_amount = deposit_received_amount + ?, deposit_journal_id = ?
        WHERE id = ? AND company_id = ?
    ")->execute([$amount, $postResult['journal_id'], $contractId, $companyId]);

    if ($chequeId) {
        $hasDepCol = co_db_column_exists($conn, 'co_shop_rent_cheques', 'deposit_receipt_id');
        if ($hasDepCol) {
            $conn->prepare("
                UPDATE co_shop_rent_cheques
                SET status = 'cleared', journal_id = ?, deposit_receipt_id = ?
                WHERE id = ? AND company_id = ?
            ")->execute([$postResult['journal_id'], $receiptId, $chequeId, $companyId]);
        } else {
            $conn->prepare("
                UPDATE co_shop_rent_cheques
                SET status = 'cleared', journal_id = ?
                WHERE id = ? AND company_id = ?
            ")->execute([$postResult['journal_id'], $chequeId, $companyId]);
        }
    }

    return ['receipt_id' => $receiptId, 'journal_id' => (int)$postResult['journal_id']];
}


function co_shop_create_cheque_plan_impl(PDO $conn, int $companyId, int $contractId, int $rentChequeCount, int $depositChequeCount): int {
    $stmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$contractId, $companyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract || !co_db_table_exists($conn, 'co_shop_rent_cheques')) {
        return 0;
    }

    $created = 0;
    $hasVatCols = co_db_column_exists($conn, 'co_shop_rent_cheques', 'vat_amount');
    $hasNotes = co_db_column_exists($conn, 'co_shop_rent_cheques', 'notes');
    $userId = function_exists('current_user_id') ? (current_user_id() ?: null) : null;
    $combinedFirst = co_db_column_exists($conn, 'co_shop_rental_contracts', 'combined_first_cheque')
        && !empty($contract['combined_first_cheque']);
    $method = co_shop_normalize_vat_collection($contract['vat_collection_method'] ?? CO_SHOP_VAT_INCLUDED);

    $rentChequeCount = max(0, $rentChequeCount);
    if ($rentChequeCount > 0) {
        $conn->prepare("
            DELETE FROM co_shop_rent_cheques
            WHERE company_id = ? AND contract_id = ? AND cheque_type = 'rent'
              AND invoice_id IS NULL AND payment_id IS NULL AND status <> 'cleared'
        ")->execute([$companyId, $contractId]);

        $periods = co_shop_build_periods($contract);
        $buckets = co_shop_distribute_rent_cheques($contract, $periods, $rentChequeCount);
        $combinedMeta = null;
        if ($combinedFirst && $buckets) {
            // Keep net/vat as 1st rent installment; inflate face (amount) only for bank instrument
            $combinedMeta = co_shop_combined_first_cheque_breakdown($contract, $buckets[0]);
            $buckets[0]['gross'] = (float)$combinedMeta['total'];
            $buckets[0]['notes'] = 'COMBINED_FIRST';
            $buckets[0]['combined'] = $combinedMeta;
        }

        foreach ($buckets as $idx => $bucket) {
            $notes = $bucket['notes'] ?? null;
            if ($hasVatCols && $hasNotes) {
                $conn->prepare("
                    INSERT INTO co_shop_rent_cheques
                        (company_id, contract_id, cheque_type, cheque_date, amount, vat_amount, net_amount, status, notes, created_by)
                    VALUES (?, ?, 'rent', ?, ?, ?, ?, 'received', ?, ?)
                ")->execute([
                    $companyId, $contractId, $bucket['cheque_date'],
                    $bucket['gross'], $bucket['vat'], $bucket['net'], $notes, $userId,
                ]);
            } elseif ($hasVatCols) {
                $conn->prepare("
                    INSERT INTO co_shop_rent_cheques
                        (company_id, contract_id, cheque_type, cheque_date, amount, vat_amount, net_amount, status, created_by)
                    VALUES (?, ?, 'rent', ?, ?, ?, ?, 'received', ?)
                ")->execute([
                    $companyId, $contractId, $bucket['cheque_date'],
                    $bucket['gross'], $bucket['vat'], $bucket['net'], $userId,
                ]);
            } elseif ($hasNotes) {
                $conn->prepare("
                    INSERT INTO co_shop_rent_cheques
                        (company_id, contract_id, cheque_type, cheque_date, amount, status, notes, created_by)
                    VALUES (?, ?, 'rent', ?, ?, 'received', ?, ?)
                ")->execute([
                    $companyId, $contractId, $bucket['cheque_date'], $bucket['gross'], $notes, $userId,
                ]);
            } else {
                $conn->prepare("
                    INSERT INTO co_shop_rent_cheques
                        (company_id, contract_id, cheque_type, cheque_date, amount, status, created_by)
                    VALUES (?, ?, 'rent', ?, ?, 'received', ?)
                ")->execute([
                    $companyId, $contractId, $bucket['cheque_date'], $bucket['gross'], $userId,
                ]);
            }
            $created++;
        }

        // Separate VAT cheque only when not folded into combined first collection
        if ($method === CO_SHOP_VAT_SEPARATE && !$combinedFirst) {
            $totalVat = (float)co_shop_contract_rent_totals($contract)['vat'];
            if ($totalVat > 0) {
                if ($hasVatCols) {
                    $conn->prepare("
                        INSERT INTO co_shop_rent_cheques
                            (company_id, contract_id, cheque_type, cheque_date, amount, vat_amount, net_amount, status, notes, created_by)
                        VALUES (?, ?, 'rent', ?, ?, ?, 0, 'received', 'VAT_SEPARATE', ?)
                    ")->execute([
                        $companyId, $contractId, $contract['start_date'], $totalVat, $totalVat, $userId,
                    ]);
                } else {
                    $conn->prepare("
                        INSERT INTO co_shop_rent_cheques
                            (company_id, contract_id, cheque_type, cheque_date, amount, status, notes, created_by)
                        VALUES (?, ?, 'rent', ?, ?, 'received', 'VAT_SEPARATE', ?)
                    ")->execute([
                        $companyId, $contractId, $contract['start_date'], $totalVat, $userId,
                    ]);
                }
                $created++;
            }
        }
    }

    $depositChequeCount = max(0, $depositChequeCount);
    if ($depositChequeCount > 0 && (float)$contract['security_deposit'] > 0) {
        $delSql = "
            DELETE FROM co_shop_rent_cheques
            WHERE company_id = ? AND contract_id = ? AND cheque_type = 'security_deposit'
              AND journal_id IS NULL AND status <> 'cleared'
        ";
        $conn->prepare($delSql)->execute([$companyId, $contractId]);
        $base = round(((float)$contract['security_deposit']) / $depositChequeCount, 2);
        $remaining = round((float)$contract['security_deposit'], 2);
        for ($i = 0; $i < $depositChequeCount; $i++) {
            $amount = ($i === $depositChequeCount - 1) ? $remaining : $base;
            if ($hasVatCols) {
                $conn->prepare("
                    INSERT INTO co_shop_rent_cheques
                        (company_id, contract_id, cheque_type, cheque_date, amount, vat_amount, net_amount, status, created_by)
                    VALUES (?, ?, 'security_deposit', ?, ?, 0, ?, 'received', ?)
                ")->execute([$companyId, $contractId, $contract['start_date'], $amount, $amount, $userId]);
            } else {
                $conn->prepare("
                    INSERT INTO co_shop_rent_cheques
                        (company_id, contract_id, cheque_type, cheque_date, amount, status, created_by)
                    VALUES (?, ?, 'security_deposit', ?, ?, 'received', ?)
                ")->execute([$companyId, $contractId, $contract['start_date'], $amount, $userId]);
            }
            $remaining = round($remaining - $amount, 2);
            $created++;
        }
    }

    return $created;
}

/**
 * Preview / apply breakdown for opt-in combined first collection cheque.
 * Face = 1st rent cheque (as planned) + separate VAT (if any) + commission gross.
 *
 * @param array $firstBucket From co_shop_distribute_rent_cheques first element
 * @return array{rent_net:float,rent_vat:float,rent_gross:float,vat_separate:float,commission_net:float,commission_vat:float,commission_gross:float,total:float,enabled:bool}
 */
function co_shop_combined_first_cheque_breakdown(array $contract, array $firstBucket = []): array {
    $method = co_shop_normalize_vat_collection($contract['vat_collection_method'] ?? CO_SHOP_VAT_INCLUDED);
    $rentNet = round((float)($firstBucket['net'] ?? 0), 2);
    $rentVat = round((float)($firstBucket['vat'] ?? 0), 2);
    $rentGross = round((float)($firstBucket['gross'] ?? ($rentNet + $rentVat)), 2);
    $vatSeparate = 0.0;
    if ($method === CO_SHOP_VAT_SEPARATE) {
        $vatSeparate = round((float)co_shop_contract_rent_totals($contract)['vat'], 2);
    }
    $commNet = 0.0;
    $commVat = 0.0;
    $commGross = 0.0;
    if (function_exists('co_shop_commission_amounts')) {
        $c = co_shop_commission_amounts($contract);
        if (!empty($c['enabled'])) {
            $commNet = (float)$c['net'];
            $commVat = (float)$c['vat'];
            $commGross = (float)$c['gross'];
        }
    }
    $total = round($rentGross + $vatSeparate + $commGross, 2);
    return [
        'rent_net' => $rentNet,
        'rent_vat' => $rentVat,
        'rent_gross' => $rentGross,
        'vat_separate' => $vatSeparate,
        'commission_net' => $commNet,
        'commission_vat' => $commVat,
        'commission_gross' => $commGross,
        'total' => $total,
        'enabled' => true,
        'vat_method' => $method,
    ];
}

function co_shop_generate_schedules_impl(PDO $conn, int $companyId, int $contractId): int {
    $stmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$contractId, $companyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        return 0;
    }

    $hasScheduleType = co_db_column_exists($conn, 'co_shop_rent_schedules', 'schedule_type');
    $hasNet = co_db_column_exists($conn, 'co_shop_rent_schedules', 'net_amount');
    $method = co_shop_normalize_vat_collection($contract['vat_collection_method'] ?? CO_SHOP_VAT_INCLUDED);
    $periods = co_shop_build_earning_months($contract);
    $created = 0;

    $chequesStmt = $conn->prepare("
        SELECT *
        FROM co_shop_rent_cheques
        WHERE company_id = ?
          AND contract_id = ?
          AND cheque_type = 'rent'
          AND status <> 'cancelled'
          AND (notes IS NULL OR notes <> 'VAT_SEPARATE')
        ORDER BY cheque_date, id
    ");
    $chequesStmt->execute([$companyId, $contractId]);
    $cheques = $chequesStmt->fetchAll(PDO::FETCH_ASSOC);
    $chequeCount = count($cheques);
    $monthCount = count($periods);

    foreach ($periods as $idx => $period) {
        // Soft operational link only: map earning month to covering cheque bucket
        $chequeId = null;
        if ($chequeCount > 0 && $monthCount > 0) {
            $bucketIdx = (int)floor($idx * $chequeCount / $monthCount);
            if ($bucketIdx >= $chequeCount) {
                $bucketIdx = $chequeCount - 1;
            }
            $chequeId = (int)$cheques[$bucketIdx]['id'];
        }
        $net = (float)$period['net'];
        $vat = (float)$period['vat'];

        if ($hasScheduleType && $hasNet) {
            $ins = $conn->prepare("
                INSERT IGNORE INTO co_shop_rent_schedules
                    (company_id, contract_id, schedule_type, period_start, period_end, due_date, amount, net_amount, vat_amount, cheque_id)
                VALUES (?, ?, 'rent', ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $companyId, $contractId, $period['period_start'], $period['period_end'],
                $period['due_date'], $net, $net, $vat, $chequeId,
            ]);
        } else {
            $ins = $conn->prepare("
                INSERT IGNORE INTO co_shop_rent_schedules
                    (company_id, contract_id, period_start, period_end, due_date, amount, vat_amount, cheque_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $companyId, $contractId, $period['period_start'], $period['period_end'],
                $period['due_date'], $net, $vat, $chequeId,
            ]);
        }
        if ($ins->rowCount() > 0) {
            $scheduleId = (int)$conn->lastInsertId();
            if ($chequeId) {
                // Keep cheque.schedule_id as first linked earning month for that bucket
                $conn->prepare("
                    UPDATE co_shop_rent_cheques
                    SET schedule_id = COALESCE(schedule_id, ?)
                    WHERE id = ? AND company_id = ?
                ")->execute([$scheduleId, $chequeId, $companyId]);
            }
            $created++;
        }
    }

    // Separate VAT is collected via Payment Receipt → prepaid liability (2330).
    // Do NOT create VAT-only schedule/tax invoices. Remove any pending legacy VAT rows.
    if ($method === CO_SHOP_VAT_SEPARATE && $hasScheduleType) {
        $conn->prepare("
            DELETE FROM co_shop_rent_schedules
            WHERE company_id = ? AND contract_id = ?
              AND schedule_type = 'vat' AND status = 'pending' AND invoice_id IS NULL
        ")->execute([$companyId, $contractId]);
    }

    if (function_exists('co_shop_concession_persist_value')) {
        co_shop_concession_persist_value($conn, $companyId, $contractId);
    }

    return $created;
}

function co_shop_contract_financial_summary(PDO $conn, int $companyId, int $contractId): array {
    $contractStmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $contractStmt->execute([$contractId, $companyId]);
    $contract = $contractStmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        return [];
    }

    $hasApplied = co_db_column_exists($conn, 'co_client_invoices', 'prepaid_vat_applied');
    $appliedSelect = $hasApplied ? 'i.prepaid_vat_applied' : '0 AS prepaid_vat_applied';
    $invStmt = $conn->prepare("
        SELECT i.id, i.invoice_number, i.invoice_date, i.due_date, i.subtotal, i.vat_amount, i.total_amount, i.status, i.journal_id,
               i.source_type, i.source_id, {$appliedSelect},
               COALESCE(s.schedule_type, 'rent') AS schedule_type,
               s.period_start, s.period_end,
               COALESCE((
                   SELECT SUM(a.allocated_amount)
                   FROM co_client_payment_allocations a
                   WHERE a.invoice_id = i.id AND a.company_id = i.company_id
               ), 0) AS paid_amount
        FROM co_client_invoices i
        LEFT JOIN co_shop_rent_schedules s
               ON s.id = i.source_id AND s.company_id = i.company_id AND i.source_type = 'shop_rental'
        WHERE i.company_id = ?
          AND i.source_type = 'shop_rental'
          AND i.status <> 'cancelled'
          AND i.source_id IN (SELECT id FROM co_shop_rent_schedules WHERE contract_id = ? AND company_id = ?)
        ORDER BY i.due_date ASC, i.id ASC
    ");
    $invStmt->execute([$companyId, $contractId, $companyId]);
    $invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

    $invoiced = 0.0;
    $collected = 0.0;
    $vatInvoiced = 0.0;
    $outstanding = 0.0;
    $overdue = 0.0;
    $today = date('Y-m-d');
    $openInvoices = [];
    foreach ($invoices as &$inv) {
        $paid = (float)$inv['paid_amount'];
        $total = (float)$inv['total_amount'];
        $collectible = co_shop_invoice_collectible_amount($conn, $companyId, $inv);
        $balance = max(0, round($collectible - $paid, 2));
        $inv['balance'] = $balance;
        $inv['collectible_amount'] = $collectible;
        $inv['kind'] = (($inv['schedule_type'] ?? 'rent') === 'vat') ? 'vat' : 'rent';
        $inv['kind_label'] = $inv['kind'] === 'vat' ? 'VAT (legacy)' : 'Rent';
        $inv['prepaid_vat'] = ((float)($inv['prepaid_vat_applied'] ?? 0) > 0.005)
            || ($inv['kind'] === 'rent' && $collectible + 0.005 < $total);
        $invoiced = round($invoiced + $total, 2);
        $collected = round($collected + $paid, 2);
        // Official Output VAT lives on monthly rent Tax Invoices only.
        // Legacy VAT-only collection invoices are excluded from VAT totals.
        if ($inv['kind'] !== 'vat') {
            $vatInvoiced = round($vatInvoiced + (float)$inv['vat_amount'], 2);
        }
        $outstanding = round($outstanding + $balance, 2);
        if ($balance > 0.005 && !empty($inv['due_date']) && $inv['due_date'] < $today) {
            $overdue = round($overdue + $balance, 2);
        }
        if ($balance > 0.005) {
            $openInvoices[] = $inv;
        }
    }
    unset($inv);

    // Recent list: newest first (display), but keep full open list for allocation UI
    $recentSorted = $invoices;
    usort($recentSorted, static function ($a, $b) {
        $cmp = strcmp((string)($b['invoice_date'] ?? ''), (string)($a['invoice_date'] ?? ''));
        return $cmp !== 0 ? $cmp : ((int)$b['id'] - (int)$a['id']);
    });


    $deferred = 0.0;
    if (!empty($contract['accrual_deferred_rent']) && co_db_table_exists($conn, 'co_shop_rent_recognitions')) {
        $defStmt = $conn->prepare("
            SELECT
              COALESCE((SELECT SUM(s.amount) FROM co_shop_rent_schedules s
                        WHERE s.company_id = ? AND s.contract_id = ? AND s.status = 'invoiced'), 0)
              -
              COALESCE((SELECT SUM(r.amount) FROM co_shop_rent_recognitions r
                        JOIN co_shop_rent_schedules sx ON sx.id = r.schedule_id
                        WHERE sx.contract_id = ? AND r.company_id = ?), 0)
            AS bal
        ");
        $defStmt->execute([$companyId, $contractId, $contractId, $companyId]);
        $deferred = max(0, round((float)$defStmt->fetchColumn(), 2));
    }

    $receipts = [];
    $seenRcpt = [];
    $payStmt = $conn->prepare("
        SELECT p.id, p.payment_date, p.amount, p.reference, p.journal_id, i.invoice_number, i.source_type
        FROM co_client_payments p
        JOIN co_client_invoices i ON i.id = p.invoice_id AND i.company_id = p.company_id
        WHERE p.company_id = ?
          AND (
            (i.source_type = 'shop_rental'
             AND i.source_id IN (SELECT id FROM co_shop_rent_schedules WHERE contract_id = ? AND company_id = ?))
            OR (i.source_type = 'shop_commission' AND i.source_id = ?)
            OR (i.source_type = 'shop_charge' AND i.source_id = ?)
          )
        ORDER BY p.payment_date DESC, p.id DESC
        LIMIT 12
    ");
    $payStmt->execute([$companyId, $contractId, $companyId, $contractId, $contractId]);
    foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $seenRcpt[(int)$r['id']] = true;
        $receipts[] = $r;
    }
    if (co_db_column_exists($conn, 'co_client_payments', 'contract_id')) {
        $pay2 = $conn->prepare("
            SELECT p.id, p.payment_date, p.amount, p.reference, p.journal_id,
                   NULL AS invoice_number, 'workspace' AS source_type
            FROM co_client_payments p
            WHERE p.company_id = ? AND p.contract_id = ?
            ORDER BY p.payment_date DESC, p.id DESC
            LIMIT 12
        ");
        $pay2->execute([$companyId, $contractId]);
        foreach ($pay2->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pid = (int)$r['id'];
            if (isset($seenRcpt[$pid])) {
                continue;
            }
            $seenRcpt[$pid] = true;
            $receipts[] = $r;
        }
        usort($receipts, static function ($a, $b) {
            $cmp = strcmp((string)$b['payment_date'], (string)$a['payment_date']);
            return $cmp !== 0 ? $cmp : ((int)$b['id'] - (int)$a['id']);
        });
        $receipts = array_slice($receipts, 0, 12);
    }

    // Contract charge invoices (Key Money + custom shop_charge) — Workspace + summary
    $chargeInvoices = [];
    $keyMoneyOutstanding = 0.0;
    if (function_exists('co_shop_charges_schema_ready') && co_shop_charges_schema_ready($conn)
        && co_db_table_exists($conn, 'co_client_invoices')) {
        $chInvSql = "
            SELECT i.id, i.invoice_number, i.invoice_date, i.due_date, i.subtotal, i.vat_amount, i.total_amount,
                   i.status, i.journal_id, i.source_type, i.contract_charge_id,
                   ct.code AS charge_code, ct.name AS charge_name, ct.allocation_priority,
                   COALESCE((
                       SELECT SUM(a.allocated_amount)
                       FROM co_client_payment_allocations a
                       WHERE a.invoice_id = i.id AND a.company_id = i.company_id
                   ), 0) AS paid_amount
            FROM co_client_invoices i
            LEFT JOIN co_shop_contract_charges cc
              ON cc.id = i.contract_charge_id AND cc.company_id = i.company_id
            LEFT JOIN co_shop_charge_types ct
              ON ct.id = cc.charge_type_id AND ct.company_id = cc.company_id
            WHERE i.company_id = ?
              AND i.source_type = 'shop_charge'
              AND i.source_id = ?
              AND i.status <> 'cancelled'
            ORDER BY i.due_date ASC, i.id ASC
        ";
        $chStmt = $conn->prepare($chInvSql);
        $chStmt->execute([$companyId, $contractId]);
        foreach ($chStmt->fetchAll(PDO::FETCH_ASSOC) as $inv) {
            $paid = (float)$inv['paid_amount'];
            $total = (float)$inv['total_amount'];
            $balance = max(0, round($total - $paid, 2));
            $inv['balance'] = $balance;
            $code = (string)($inv['charge_code'] ?? '');
            if ($code === 'key_money') {
                $inv['kind'] = 'key_money';
                $inv['kind_label'] = 'Key Money';
                $keyMoneyOutstanding = round($keyMoneyOutstanding + $balance, 2);
            } else {
                $inv['kind'] = 'charge';
                $inv['kind_label'] = (string)($inv['charge_name'] ?? 'Charge');
            }
            $inv['allocation_priority'] = (int)($inv['allocation_priority'] ?? 50);
            $chargeInvoices[] = $inv;
            $invoiced = round($invoiced + $total, 2);
            $collected = round($collected + $paid, 2);
            $vatInvoiced = round($vatInvoiced + (float)$inv['vat_amount'], 2);
            $outstanding = round($outstanding + $balance, 2);
            if ($balance > 0.005 && !empty($inv['due_date']) && $inv['due_date'] < $today) {
                $overdue = round($overdue + $balance, 2);
            }
            if ($balance > 0.005) {
                $openInvoices[] = $inv;
            }
        }
    }

    $keyMoneyStatus = null;
    if (function_exists('co_shop_key_money_status')) {
        $keyMoneyStatus = co_shop_key_money_status($conn, $companyId, $contractId);
        // Prefer status block outstanding when invoice exists (already in open list)
        if ($keyMoneyStatus && empty($keyMoneyStatus['invoice']) && ($keyMoneyStatus['enabled'] ?? false)) {
            // not yet invoiced — do not add to AR outstanding
        }
    }

    return [
        'invoiced' => $invoiced,
        'collected' => $collected,
        'outstanding' => $outstanding,
        'overdue' => $overdue,
        'deferred_revenue' => $deferred,
        'deposit_required' => (float)$contract['security_deposit'],
        'deposit_received' => (float)$contract['deposit_received_amount'],
        'deposit_outstanding' => max(0, round((float)$contract['security_deposit'] - (float)$contract['deposit_received_amount'], 2)),
        'vat_invoiced' => $vatInvoiced,
        'client_credit' => co_shop_get_client_credit($conn, $companyId, (int)$contract['client_id']),
        'recent_invoices' => array_slice($recentSorted, 0, 20),
        'open_invoices' => $openInvoices,
        'all_invoices' => $invoices,
        'charge_invoices' => $chargeInvoices,
        'recent_receipts' => $receipts,
        'vat_collection_method' => $contract['vat_collection_method'] ?? CO_SHOP_VAT_INCLUDED,
        'vat_mode' => $contract['vat_mode'] ?? 'exclusive',
        'accrual_deferred_rent' => (int)($contract['accrual_deferred_rent'] ?? 0),
        'commission' => co_shop_commission_schema_ready($conn)
            ? co_shop_commission_status($conn, $companyId, $contract)
            : null,
        'key_money' => $keyMoneyStatus,
    ];
}

function co_shop_contract_timeline(PDO $conn, int $companyId, int $contractId): array {
    $events = [];

    $c = $conn->prepare("SELECT contract_number, start_date, end_date, created_at, status FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $c->execute([$contractId, $companyId]);
    $contract = $c->fetch(PDO::FETCH_ASSOC);
    if ($contract) {
        $events[] = [
            'date' => substr((string)$contract['created_at'], 0, 10),
            'label' => 'Contract created',
            'detail' => $contract['contract_number'] . ' · ' . $contract['status'],
            'tone' => 'primary',
        ];
        $events[] = [
            'date' => $contract['start_date'],
            'label' => 'Lease start',
            'detail' => 'Period begins',
            'tone' => 'success',
        ];
        $events[] = [
            'date' => $contract['end_date'],
            'label' => 'Lease end',
            'detail' => 'Contract end date',
            'tone' => 'secondary',
        ];
    }

    $hasScheduleType = co_db_column_exists($conn, 'co_shop_rent_schedules', 'schedule_type');
    $typeCol = $hasScheduleType ? 'schedule_type' : "'rent' AS schedule_type";
    $sched = $conn->prepare("
        SELECT period_start, due_date, amount, vat_amount, status, invoice_id, {$typeCol}
        FROM co_shop_rent_schedules WHERE company_id = ? AND contract_id = ?
        ORDER BY period_start
    ");
    $sched->execute([$companyId, $contractId]);
    foreach ($sched->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $type = (($s['schedule_type'] ?? 'rent') === 'vat') ? 'VAT schedule' : 'Rent schedule';
        $events[] = [
            'date' => $s['due_date'] ?: $s['period_start'],
            'label' => $type . ' · ' . $s['status'],
            'detail' => co_format_money((float)$s['amount'] + (float)$s['vat_amount']),
            'tone' => $s['invoice_id'] ? 'success' : 'warning',
        ];
    }

    $chq = $conn->prepare("
        SELECT cheque_date, cheque_type, amount, status, cheque_number, notes
        FROM co_shop_rent_cheques WHERE company_id = ? AND contract_id = ?
        ORDER BY cheque_date, id
    ");
    $chq->execute([$companyId, $contractId]);
    foreach ($chq->fetchAll(PDO::FETCH_ASSOC) as $ch) {
        $labelType = (($ch['notes'] ?? '') === 'VAT_SEPARATE')
            ? 'VAT'
            : ucwords(str_replace('_', ' ', $ch['cheque_type']));
        $events[] = [
            'date' => $ch['cheque_date'],
            'label' => $labelType . ' cheque · ' . $ch['status'],
            'detail' => trim(($ch['cheque_number'] ?: '') . ' ' . co_format_money((float)$ch['amount'])),
            'tone' => $ch['status'] === 'cleared' ? 'success' : ($ch['status'] === 'bounced' ? 'danger' : 'info'),
        ];
    }

    if (co_db_table_exists($conn, 'co_shop_deposit_receipts')) {
        $dep = $conn->prepare("
            SELECT receipt_date, amount, reference FROM co_shop_deposit_receipts
            WHERE company_id = ? AND contract_id = ? ORDER BY receipt_date, id
        ");
        $dep->execute([$companyId, $contractId]);
        foreach ($dep->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $events[] = [
                'date' => $d['receipt_date'],
                'label' => 'Security deposit received',
                'detail' => co_format_money((float)$d['amount']) . ($d['reference'] ? ' · ' . $d['reference'] : ''),
                'tone' => 'success',
            ];
        }
    }

    usort($events, static function ($a, $b) {
        return strcmp($a['date'], $b['date']);
    });
    return $events;
}
