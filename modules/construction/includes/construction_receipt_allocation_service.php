<?php
/**
 * Construction Payment Workspace — receipt allocation service (Payment Maturity Phase 2).
 * Company-scoped co_* only. Philosophy aligned with RE Invoice Mode; does NOT call RE receipt engine.
 * Posts only via existing co_post_* → create_and_post_journal (Shared GL engine unchanged).
 */

require_once __DIR__ . '/construction_helpers.php';
require_once __DIR__ . '/construction_income_helpers.php';
require_once __DIR__ . '/construction_shop_rental_helpers.php';
require_once __DIR__ . '/construction_shop_rental_cheque_lifecycle_helpers.php';
require_once __DIR__ . '/construction_accounting_integration.php';

function co_receipt_workspace_schema_ready(PDO $conn): bool {
    return co_db_table_exists($conn, 'co_client_payment_funding_sources')
        && co_db_table_exists($conn, 'co_client_payment_events')
        && co_db_column_exists($conn, 'co_client_payments', 'allocation_status');
}

/**
 * @return list<string>
 */
function co_receipt_allocation_strategies(): array {
    return ['fifo', 'oldest_due', 'charge_priority', 'manual'];
}

function co_receipt_company_strategy(PDO $conn, int $companyId): string {
    $strategy = 'fifo';
    if (function_exists('co_shop_get_settings')) {
        $settings = co_shop_get_settings($conn, $companyId);
        $strategy = (string)($settings['allocation_strategy'] ?? 'fifo');
    }
    if (!in_array($strategy, co_receipt_allocation_strategies(), true)) {
        return 'fifo';
    }
    return $strategy;
}

/**
 * @param array<string,mixed> $payload
 */
function co_receipt_log_event(
    PDO $conn,
    int $companyId,
    int $paymentId,
    string $eventType,
    array $payload = [],
    ?int $contractId = null,
    ?int $userId = null
): void {
    if (!co_db_table_exists($conn, 'co_client_payment_events') || $paymentId <= 0) {
        return;
    }
    $conn->prepare("
        INSERT INTO co_client_payment_events
            (company_id, payment_id, contract_id, event_type, payload_json, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([
        $companyId,
        $paymentId,
        $contractId,
        $eventType,
        $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        $userId,
    ]);
}

/**
 * Open invoices for a shop rental contract (rent/VAT/commission/penalty/charge).
 * @return list<array<string,mixed>>
 */
function co_receipt_open_invoices_for_contract(PDO $conn, int $companyId, int $contractId): array {
    if ($companyId <= 0 || $contractId <= 0) {
        return [];
    }
    $summary = co_shop_contract_financial_summary($conn, $companyId, $contractId);
    $rows = [];
    foreach ($summary['open_invoices'] ?? [] as $inv) {
        $bal = round((float)($inv['balance'] ?? 0), 2);
        if ($bal <= 0.005) {
            continue;
        }
        $rows[] = [
            'id' => (int)$inv['id'],
            'invoice_number' => (string)($inv['invoice_number'] ?? ('#' . $inv['id'])),
            'balance' => $bal,
            'due_date' => $inv['due_date'] ?? null,
            'invoice_date' => $inv['invoice_date'] ?? null,
            'kind' => (string)($inv['kind'] ?? 'rent'),
            'kind_label' => (string)($inv['kind_label'] ?? 'Rent'),
            'source_type' => (string)($inv['source_type'] ?? ''),
            'contract_charge_id' => isset($inv['contract_charge_id']) ? (int)$inv['contract_charge_id'] : null,
            'allocation_priority' => (int)($inv['allocation_priority'] ?? 100),
        ];
    }
    if (!empty($summary['commission']['invoice']) && (($summary['commission']['outstanding'] ?? 0) > 0.005)) {
        $cInv = $summary['commission']['invoice'];
        $cId = (int)($cInv['id'] ?? 0);
        $exists = false;
        foreach ($rows as $r) {
            if ((int)$r['id'] === $cId) {
                $exists = true;
                break;
            }
        }
        if ($cId > 0 && !$exists) {
            $rows[] = [
                'id' => $cId,
                'invoice_number' => (string)($cInv['invoice_number'] ?? ('#' . $cId)),
                'balance' => round((float)($cInv['balance'] ?? $summary['commission']['outstanding']), 2),
                'due_date' => $cInv['due_date'] ?? null,
                'invoice_date' => $cInv['invoice_date'] ?? null,
                'kind' => 'commission',
                'kind_label' => 'Commission',
                'source_type' => 'shop_commission',
                'contract_charge_id' => null,
                'allocation_priority' => 20,
            ];
        }
    }
    return $rows;
}

/**
 * Enrich open invoices with charge allocation_priority when schema ready.
 * @param list<array<string,mixed>> $invoices
 * @return list<array<string,mixed>>
 */
function co_receipt_enrich_invoice_priorities(PDO $conn, int $companyId, array $invoices): array {
    if (!$invoices || !co_db_column_exists($conn, 'co_client_invoices', 'contract_charge_id')) {
        return $invoices;
    }
    $ids = array_values(array_filter(array_map(static fn($r) => (int)($r['id'] ?? 0), $invoices)));
    if (!$ids) {
        return $invoices;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$companyId], $ids);
    $sql = "
        SELECT i.id, i.contract_charge_id, i.source_type,
               COALESCE(ct.allocation_priority, 100) AS allocation_priority
        FROM co_client_invoices i
        LEFT JOIN co_shop_contract_charges cc
          ON cc.id = i.contract_charge_id AND cc.company_id = i.company_id
        LEFT JOIN co_shop_charge_types ct
          ON ct.id = cc.charge_type_id AND ct.company_id = cc.company_id
        WHERE i.company_id = ? AND i.id IN ($placeholders)
    ";
    if (!co_db_table_exists($conn, 'co_shop_contract_charges')) {
        return $invoices;
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $prio = (int)$row['allocation_priority'];
        $src = (string)($row['source_type'] ?? '');
        if ($src === 'shop_commission') {
            $prio = min($prio, 20);
        } elseif ($src === 'shop_termination_penalty') {
            $prio = min($prio, 30);
        }
        $map[(int)$row['id']] = $prio;
    }
    foreach ($invoices as &$inv) {
        $id = (int)$inv['id'];
        if (isset($map[$id])) {
            $inv['allocation_priority'] = $map[$id];
        }
    }
    unset($inv);
    return $invoices;
}

/**
 * Sort open invoices for a strategy (manual = leave as provided).
 * @param list<array<string,mixed>> $invoices
 * @return list<array<string,mixed>>
 */
function co_receipt_sort_invoices_for_strategy(array $invoices, string $strategy): array {
    if ($strategy === 'manual' || count($invoices) < 2) {
        return array_values($invoices);
    }
    usort($invoices, static function (array $a, array $b) use ($strategy): int {
        if ($strategy === 'oldest_due') {
            $da = (string)($a['due_date'] ?? $a['invoice_date'] ?? '9999-12-31');
            $db = (string)($b['due_date'] ?? $b['invoice_date'] ?? '9999-12-31');
            if ($da !== $db) {
                return $da <=> $db;
            }
            return ((int)$a['id']) <=> ((int)$b['id']);
        }
        if ($strategy === 'charge_priority') {
            $pa = (int)($a['allocation_priority'] ?? 100);
            $pb = (int)($b['allocation_priority'] ?? 100);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            $da = (string)($a['due_date'] ?? $a['invoice_date'] ?? '9999-12-31');
            $db = (string)($b['due_date'] ?? $b['invoice_date'] ?? '9999-12-31');
            if ($da !== $db) {
                return $da <=> $db;
            }
            return ((int)$a['id']) <=> ((int)$b['id']);
        }
        // fifo — invoice date then id
        $da = (string)($a['invoice_date'] ?? $a['due_date'] ?? '9999-12-31');
        $db = (string)($b['invoice_date'] ?? $b['due_date'] ?? '9999-12-31');
        if ($da !== $db) {
            return $da <=> $db;
        }
        return ((int)$a['id']) <=> ((int)$b['id']);
    });
    return array_values($invoices);
}

/**
 * Invoice kinds a cheque is meant to settle (operational purpose).
 * VAT_SEPARATE → prepaid Output VAT receipt (no tax invoice allocation).
 *
 * @return list<string>
 */
function co_shop_cheque_preferred_invoice_kinds(array $cheque): array {
    $notes = (string)($cheque['notes'] ?? '');
    if ($notes === 'VAT_SEPARATE') {
        return []; // prepaid VAT cash — not allocated to invoices
    }
    if ($notes === 'COMBINED_FIRST') {
        return ['rent', 'commission'];
    }
    return ['rent'];
}

/**
 * True when funding cheques are exclusively Separate VAT (prepaid liability receipt).
 *
 * @param list<array<string,mixed>> $cheques
 */
function co_receipt_is_prepaid_vat_cheque_purpose(array $cheques): bool {
    if (!$cheques) {
        return false;
    }
    foreach ($cheques as $ch) {
        if ((string)($ch['notes'] ?? '') !== 'VAT_SEPARATE') {
            return false;
        }
    }
    return true;
}

/**
 * Union of preferred invoice kinds across funding cheques.
 * null = no cheque purpose filter (cash / transfer / card).
 *
 * @param list<array<string,mixed>> $cheques
 * @return list<string>|null
 */
function co_receipt_preferred_kinds_from_cheques(array $cheques): ?array {
    if (!$cheques) {
        return null;
    }
    $set = [];
    foreach ($cheques as $ch) {
        foreach (co_shop_cheque_preferred_invoice_kinds($ch) as $k) {
            $set[$k] = true;
        }
    }
    // Empty list = cheque purpose is prepaid VAT (no invoice kinds).
    return array_keys($set);
}

/**
 * Load cheque rows (company + contract scoped) for purpose inference.
 *
 * @param list<int> $chequeIds
 * @return list<array<string,mixed>>
 */
function co_receipt_load_cheques_for_purpose(
    PDO $conn,
    int $companyId,
    int $contractId,
    array $chequeIds
): array {
    $chequeIds = array_values(array_unique(array_filter(array_map('intval', $chequeIds))));
    if (!$chequeIds || $companyId <= 0 || $contractId <= 0) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($chequeIds), '?'));
    $params = array_merge([$companyId, $contractId], $chequeIds);
    $stmt = $conn->prepare("
        SELECT id, notes, cheque_type, amount, cheque_date, cheque_number, status
        FROM co_shop_rent_cheques
        WHERE company_id = ? AND contract_id = ? AND id IN ($placeholders)
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Human label for preferred-kinds banner.
 *
 * @param list<string>|null $kinds
 */
function co_receipt_preferred_kinds_label(?array $kinds): string {
    if ($kinds === null) {
        return '';
    }
    if ($kinds === []) {
        return 'Separate VAT (prepaid — no invoice allocation)';
    }
    $labels = [
        'rent' => 'Rent',
        'vat' => 'VAT',
        'commission' => 'Commission',
    ];
    $out = [];
    foreach ($kinds as $k) {
        $out[] = $labels[$k] ?? ucfirst($k);
    }
    return implode(' + ', $out);
}

/**
 * Build suggested allocation lines from amount + ordered invoices.
 * Manual overrides: map invoice_id => amount (0 = skip).
 * When $preferredKinds is set (cheque purpose), suggestions fill matching invoices only
 * (no spill into other types). Operator may still override Allocate amounts.
 *
 * @param list<array<string,mixed>> $invoices
 * @param array<int,float> $manualByInvoice
 * @param list<string>|null $preferredKinds
 * @return list<array{invoice_id:int,invoice_number:string,balance:float,suggested:float,amount:float}>
 */
function co_receipt_build_allocation_plan(
    array $invoices,
    float $paymentAmount,
    string $strategy,
    array $manualByInvoice = [],
    ?array $preferredKinds = null
): array {
    $paymentAmount = round($paymentAmount, 2);
    $plan = [];
    if ($strategy === 'manual' && $manualByInvoice) {
        foreach ($invoices as $inv) {
            $id = (int)$inv['id'];
            $bal = round((float)$inv['balance'], 2);
            $amt = isset($manualByInvoice[$id]) ? round((float)$manualByInvoice[$id], 2) : 0.0;
            if ($amt < 0) {
                $amt = 0.0;
            }
            if ($amt > $bal) {
                $amt = $bal;
            }
            $plan[] = [
                'invoice_id' => $id,
                'invoice_number' => (string)$inv['invoice_number'],
                'balance' => $bal,
                'kind' => (string)($inv['kind'] ?? ''),
                'kind_label' => (string)($inv['kind_label'] ?? ''),
                'due_date' => $inv['due_date'] ?? null,
                'suggested' => $amt,
                'amount' => $amt,
            ];
        }
        return $plan;
    }

    $suggestPool = $invoices;
    if ($preferredKinds !== null) {
        if ($preferredKinds === []) {
            // Prepaid VAT cheque purpose — do not suggest invoice allocations.
            $suggestPool = [];
        } else {
            $allow = array_flip($preferredKinds);
            $suggestPool = [];
            foreach ($invoices as $inv) {
                $kind = (string)($inv['kind'] ?? 'rent');
                if (isset($allow[$kind])) {
                    $suggestPool[] = $inv;
                }
            }
        }
    }

    $remaining = $paymentAmount;
    $ordered = co_receipt_sort_invoices_for_strategy(
        $suggestPool,
        $strategy === 'manual' ? 'fifo' : $strategy
    );
    $suggestedMap = [];
    foreach ($ordered as $inv) {
        $id = (int)$inv['id'];
        $bal = round((float)$inv['balance'], 2);
        $sug = 0.0;
        if ($remaining > 0.005 && $bal > 0.005) {
            $cand = min($remaining, $bal);
            // Skip currency-dust partials (e.g. 40,000 − 3×13,333.33 = 0.01).
            // Auto-suggesting fils onto the next invoice marks it partial and breaks
            // the following cheque's clean full-invoice allocation. Leave dust unallocated;
            // operators can still type a manual Allocate amount if needed.
            if ($cand + 0.00001 < $bal && $cand <= 0.05) {
                break;
            }
            $sug = $cand;
            $remaining = round($remaining - $sug, 2);
        }
        $suggestedMap[$id] = $sug;
    }
    foreach ($invoices as $inv) {
        $id = (int)$inv['id'];
        $bal = round((float)$inv['balance'], 2);
        $sug = round((float)($suggestedMap[$id] ?? 0), 2);
        $amt = array_key_exists($id, $manualByInvoice)
            ? min($bal, max(0, round((float)$manualByInvoice[$id], 2)))
            : $sug;
        $plan[] = [
            'invoice_id' => $id,
            'invoice_number' => (string)$inv['invoice_number'],
            'balance' => $bal,
            'kind' => (string)($inv['kind'] ?? ''),
            'kind_label' => (string)($inv['kind_label'] ?? ''),
            'due_date' => $inv['due_date'] ?? null,
            'suggested' => $sug,
            'amount' => $amt,
        ];
    }
    return $plan;
}

/**
 * Normalize funding rows; amounts must be > 0.
 * @param list<array<string,mixed>> $funding
 * @return list<array<string,mixed>>
 */
function co_receipt_funding_methods(): array {
    return [
        'bank_transfer' => 'Bank transfer',
        'cash' => 'Cash',
        'card' => 'Card / POS',
        'online' => 'Online payment',
        'cheque' => 'Cheque',
    ];
}

/** True when funding ENUM includes card/online (Phase 5A migration). */
function co_receipt_funding_methods_ready(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!co_db_table_exists($conn, 'co_client_payment_funding_sources')) {
        $ready = false;
        return false;
    }
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM co_client_payment_funding_sources LIKE 'method'");
        $col = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $type = (string)($col['Type'] ?? '');
        $ready = str_contains($type, 'card') && str_contains($type, 'online');
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function co_receipt_normalize_funding(array $funding): array {
    $allowed = array_keys(co_receipt_funding_methods());
    $out = [];
    foreach ($funding as $row) {
        $amount = round((float)($row['amount'] ?? 0), 2);
        if ($amount <= 0.005) {
            continue;
        }
        $method = (string)($row['method'] ?? 'bank_transfer');
        if (!in_array($method, $allowed, true)) {
            $method = 'bank_transfer';
        }
        $out[] = [
            'method' => $method,
            'amount' => $amount,
            'reference' => trim((string)($row['reference'] ?? '')) ?: null,
            'funding_date' => ($row['funding_date'] ?? null) ?: null,
            'bank_account_id' => !empty($row['bank_account_id']) ? (int)$row['bank_account_id'] : null,
            'cheque_id' => !empty($row['cheque_id']) ? (int)$row['cheque_id'] : null,
            'remarks' => trim((string)($row['remarks'] ?? '')) ?: null,
        ];
    }
    return $out;
}

/**
 * Preview only — no GL / no persistence.
 *
 * @param array{
 *   company_id:int,client_id:int,contract_id:int,amount:float,pay_account_id:int,
 *   payment_date?:string,reference?:?string,strategy?:string,
 *   funding?:list<array>,manual_allocations?:array<int,float>,cheque_ids?:list<int>
 * } $input
 * @return array<string,mixed>
 */
function co_receipt_preview(PDO $conn, array $input): array {
    $companyId = (int)($input['company_id'] ?? 0);
    $clientId = (int)($input['client_id'] ?? 0);
    $contractId = (int)($input['contract_id'] ?? 0);
    $amount = round((float)($input['amount'] ?? 0), 2);
    $payAccountId = (int)($input['pay_account_id'] ?? 0);
    $strategy = (string)($input['strategy'] ?? co_receipt_company_strategy($conn, $companyId));
    if (!in_array($strategy, co_receipt_allocation_strategies(), true)) {
        $strategy = 'fifo';
    }
    if ($companyId <= 0 || $clientId <= 0 || $contractId <= 0) {
        throw new RuntimeException('Company, client and contract are required.');
    }
    if ($amount <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }
    if ($payAccountId <= 0) {
        throw new RuntimeException('Choose the receiving bank/cash account (GL debit).');
    }

    $funding = co_receipt_normalize_funding($input['funding'] ?? []);
    if (!$funding) {
        $funding = [[
            'method' => 'bank_transfer',
            'amount' => $amount,
            'reference' => $input['reference'] ?? null,
            'funding_date' => $input['payment_date'] ?? date('Y-m-d'),
            'bank_account_id' => $payAccountId,
            'cheque_id' => null,
            'remarks' => null,
        ]];
    }
    $fundingTotal = round(array_sum(array_column($funding, 'amount')), 2);
    $fundingBalanced = abs($fundingTotal - $amount) < 0.005;

    $chequeIds = array_values(array_unique(array_filter(array_map('intval', $input['cheque_ids'] ?? []))));
    foreach ($funding as $f) {
        if (!empty($f['cheque_id'])) {
            $chequeIds[] = (int)$f['cheque_id'];
        }
    }
    $chequeIds = array_values(array_unique(array_filter($chequeIds)));
    $purposeCheques = co_receipt_load_cheques_for_purpose($conn, $companyId, $contractId, $chequeIds);
    $preferredKinds = co_receipt_preferred_kinds_from_cheques($purposeCheques);
    $isPrepaidVat = co_receipt_is_prepaid_vat_cheque_purpose($purposeCheques);

    $invoices = co_receipt_open_invoices_for_contract($conn, $companyId, $contractId);
    $invoices = co_receipt_enrich_invoice_priorities($conn, $companyId, $invoices);
    $manual = [];
    foreach (($input['manual_allocations'] ?? []) as $invId => $amt) {
        $manual[(int)$invId] = (float)$amt;
    }

    if ($isPrepaidVat) {
        // Separate VAT cheque: no invoice allocation — full amount is prepaid Output VAT.
        $lines = [];
        foreach ($invoices as $inv) {
            $lines[] = [
                'invoice_id' => (int)$inv['id'],
                'invoice_number' => (string)$inv['invoice_number'],
                'balance' => round((float)$inv['balance'], 2),
                'kind' => (string)($inv['kind'] ?? ''),
                'kind_label' => (string)($inv['kind_label'] ?? ''),
                'due_date' => $inv['due_date'] ?? null,
                'suggested' => 0.0,
                'amount' => 0.0,
            ];
        }
        $allocated = 0.0;
        $credit = 0.0;
    } else {
        $lines = co_receipt_build_allocation_plan($invoices, $amount, $strategy, $manual, $preferredKinds);
        $allocated = round(array_sum(array_column($lines, 'amount')), 2);
        $credit = round(max(0, $amount - $allocated), 2);
    }
    $availableCredit = co_shop_get_client_credit($conn, $companyId, $clientId);

    $errors = [];
    if (!$fundingBalanced) {
        $errors[] = 'Funding sources total (' . number_format($fundingTotal, 2) . ') must equal payment amount (' . number_format($amount, 2) . ').';
    }
    if ($isPrepaidVat) {
        if ($amount <= 0.005) {
            $errors[] = 'Enter the Separate VAT payment amount.';
        }
    } elseif ($allocated <= 0.005 && $credit <= 0.005) {
        $errors[] = 'Nothing to allocate — select invoices or increase payment amount.';
    }

    // Combined-first: leftover after rent/commission should become prepaid VAT when contract is separate.
    $prepaidVatAmount = $isPrepaidVat ? $amount : 0.0;
    if (!$isPrepaidVat && $credit > 0.005 && $purposeCheques) {
        $hasCombined = false;
        foreach ($purposeCheques as $ch) {
            if ((string)($ch['notes'] ?? '') === 'COMBINED_FIRST') {
                $hasCombined = true;
                break;
            }
        }
        if ($hasCombined) {
            $c = co_shop_contract_load($conn, $companyId, $contractId);
            if ($c && co_shop_normalize_vat_collection($c['vat_collection_method'] ?? '') === CO_SHOP_VAT_SEPARATE) {
                $vatDue = round((float)co_shop_contract_rent_totals($c)['vat'], 2);
                $already = co_shop_contract_prepaid_vat_balance($conn, $companyId, $contractId);
                $vatRemaining = max(0.0, round($vatDue - $already, 2));
                $prepaidVatAmount = round(min($credit, $vatRemaining), 2);
                if ($prepaidVatAmount > 0.005) {
                    $credit = round($credit - $prepaidVatAmount, 2);
                }
            }
        }
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'company_id' => $companyId,
        'client_id' => $clientId,
        'contract_id' => $contractId,
        'amount' => $amount,
        'pay_account_id' => $payAccountId,
        'payment_date' => (string)($input['payment_date'] ?? date('Y-m-d')),
        'reference' => isset($input['reference']) ? trim((string)$input['reference']) : null,
        'strategy' => $strategy,
        'funding' => $funding,
        'funding_total' => $fundingTotal,
        'funding_balanced' => $fundingBalanced,
        'allocation_lines' => $lines,
        'allocated_total' => $allocated,
        'credit_amount' => $credit,
        'prepaid_vat_amount' => $prepaidVatAmount,
        'is_prepaid_vat_receipt' => $isPrepaidVat,
        'available_credit' => $availableCredit,
        'cheque_ids' => $chequeIds,
        'preferred_kinds' => $preferredKinds,
        'open_invoice_count' => count($invoices),
    ];
}

/**
 * Persist funding + cheque links + allocation_status after payment insert.
 */
function co_receipt_attach_workspace_meta(
    PDO $conn,
    int $companyId,
    int $paymentId,
    ?int $contractId,
    array $funding,
    array $chequeIds,
    float $allocated,
    float $credit,
    ?int $userId = null
): void {
    if ($paymentId <= 0 || !co_receipt_workspace_schema_ready($conn)) {
        return;
    }
    $status = 'allocated';
    if ($credit > 0.005) {
        $status = 'overpaid';
    } elseif ($allocated <= 0.005) {
        $status = 'unallocated';
    }
    $sets = ['allocation_status = ?'];
    $params = [$status];
    if (co_db_column_exists($conn, 'co_client_payments', 'contract_id') && $contractId) {
        $sets[] = 'contract_id = ?';
        $params[] = $contractId;
    }
    $params[] = $paymentId;
    $params[] = $companyId;
    $conn->prepare('UPDATE co_client_payments SET ' . implode(', ', $sets) . ' WHERE id = ? AND company_id = ?')
        ->execute($params);

    if (co_db_table_exists($conn, 'co_client_payment_funding_sources')) {
        $ins = $conn->prepare("
            INSERT INTO co_client_payment_funding_sources
                (company_id, payment_id, method, amount, reference, funding_date, bank_account_id, cheque_id, remarks)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($funding as $f) {
            $ins->execute([
                $companyId,
                $paymentId,
                $f['method'],
                $f['amount'],
                $f['reference'],
                $f['funding_date'],
                $f['bank_account_id'],
                $f['cheque_id'],
                $f['remarks'],
            ]);
            if (!empty($f['cheque_id'])) {
                $chequeIds[] = (int)$f['cheque_id'];
            }
        }
    }

    $chequeIds = array_values(array_unique(array_filter(array_map('intval', $chequeIds))));
    $amountByCheque = [];
    if ($chequeIds && co_db_table_exists($conn, 'co_shop_receipt_cheque_links')) {
        $link = $conn->prepare("
            INSERT IGNORE INTO co_shop_receipt_cheque_links (company_id, payment_id, cheque_id, amount)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($chequeIds as $chId) {
            $amt = 0.0;
            foreach ($funding as $f) {
                if ((int)($f['cheque_id'] ?? 0) === $chId) {
                    $amt = (float)$f['amount'];
                    break;
                }
            }
            $amountByCheque[$chId] = $amt;
            $link->execute([$companyId, $paymentId, $chId, $amt]);
        }
    }
    if ($chequeIds && function_exists('co_shop_cheques_mark_allocated')) {
        co_shop_cheques_mark_allocated($conn, $companyId, $paymentId, $chequeIds, $amountByCheque, $userId);
    } elseif ($chequeIds && co_db_column_exists($conn, 'co_shop_rent_cheques', 'payment_id')) {
        foreach ($chequeIds as $chId) {
            $conn->prepare("
                UPDATE co_shop_rent_cheques
                SET payment_id = ?
                WHERE id = ? AND company_id = ? AND (payment_id IS NULL OR payment_id = 0)
            ")->execute([$paymentId, $chId, $companyId]);
        }
    }

    co_receipt_log_event($conn, $companyId, $paymentId, 'confirm', [
        'allocated' => $allocated,
        'credit' => $credit,
        'funding_count' => count($funding),
        'cheque_ids' => $chequeIds,
        'status' => $status,
    ], $contractId, $userId);
}

function co_receipt_assert_journal_company(PDO $conn, int $companyId, int $journalId): void {
    if ($journalId <= 0 || $companyId <= 0) {
        throw new RuntimeException('Journal and company are required.');
    }
    $stmt = $conn->prepare("SELECT company_id FROM re_journal_headers WHERE id = ? LIMIT 1");
    $stmt->execute([$journalId]);
    $jCompany = (int)$stmt->fetchColumn();
    if ($jCompany <= 0) {
        throw new RuntimeException('Journal #' . $journalId . ' not found.');
    }
    if ($jCompany !== $companyId) {
        throw new RuntimeException('Journal does not belong to the current company.');
    }
}

/**
 * Guard: linked cheques must still be allocatable (prevents double confirm).
 * @param list<int> $chequeIds
 */
function co_receipt_assert_cheques_allocatable(PDO $conn, int $companyId, int $contractId, array $chequeIds): void {
    $chequeIds = array_values(array_unique(array_filter(array_map('intval', $chequeIds))));
    if (!$chequeIds) {
        return;
    }
    if (function_exists('co_shop_cheques_for_allocate')) {
        co_shop_cheques_for_allocate($conn, $companyId, $contractId, $chequeIds);
        return;
    }
    foreach ($chequeIds as $chId) {
        $stmt = $conn->prepare("SELECT id, status, payment_id FROM co_shop_rent_cheques WHERE id = ? AND company_id = ? AND contract_id = ?");
        $stmt->execute([$chId, $companyId, $contractId]);
        $ch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ch) {
            throw new RuntimeException('Cheque #' . $chId . ' not found on this contract.');
        }
        if (!empty($ch['payment_id']) || ($ch['status'] ?? '') === 'allocated') {
            throw new RuntimeException('Cheque #' . $chId . ' is already allocated/linked to a payment.');
        }
    }
}

/**
 * Confirm payment + allocations + post GL. Uses existing multi-invoice helper.
 *
 * @param array<string,mixed> $input Same shape as co_receipt_preview
 * @return array<string,mixed>
 */
function co_receipt_confirm(PDO $conn, array $input, ?int $userId = null): array {
    $preview = co_receipt_preview($conn, $input);
    if (!$preview['ok']) {
        throw new RuntimeException(implode(' ', $preview['errors']));
    }

    $companyId = (int)$preview['company_id'];
    $contractId = (int)$preview['contract_id'];
    $chequeIds = $preview['cheque_ids'] ?? [];
    foreach ($preview['funding'] as $f) {
        if (!empty($f['cheque_id'])) {
            $chequeIds[] = (int)$f['cheque_id'];
        }
    }
    $chequeIds = array_values(array_unique(array_filter(array_map('intval', $chequeIds))));
    co_receipt_assert_cheques_allocatable($conn, $companyId, $contractId, $chequeIds);

    // Soft double-submit guard: same contract + amount + receiving account + date within 90s
    if (co_db_column_exists($conn, 'co_client_payments', 'contract_id')) {
        $dup = $conn->prepare("
            SELECT id FROM co_client_payments
            WHERE company_id = ? AND contract_id = ? AND pay_account_id = ?
              AND payment_date = ? AND ABS(amount - ?) < 0.005
              AND (allocation_status IS NULL OR allocation_status NOT IN ('reversed','voided'))
              AND created_at >= (NOW() - INTERVAL 90 SECOND)
            ORDER BY id DESC LIMIT 1
        ");
        try {
            $dup->execute([
                $companyId,
                $contractId,
                (int)$preview['pay_account_id'],
                (string)$preview['payment_date'],
                (float)$preview['amount'],
            ]);
            $dupId = (int)$dup->fetchColumn();
            if ($dupId > 0) {
                throw new RuntimeException(
                    'A matching payment (#' . $dupId . ') was just posted. Refresh before confirming again to avoid duplicate receipts.'
                );
            }
        } catch (PDOException $e) {
            // created_at may be missing on older schemas — ignore guard
        }
    }

    $prepaidVatAmount = round((float)($preview['prepaid_vat_amount'] ?? 0), 2);
    $isPrepaidOnly = !empty($preview['is_prepaid_vat_receipt']);

    if ($isPrepaidOnly) {
        $result = co_shop_record_prepaid_vat_receipt(
            $conn,
            $companyId,
            (int)$preview['client_id'],
            $contractId,
            (float)$preview['amount'],
            (int)$preview['pay_account_id'],
            (string)$preview['payment_date'],
            $preview['reference'] ?: null,
            $userId
        );
        co_receipt_attach_workspace_meta(
            $conn,
            $companyId,
            (int)$result['payment_id'],
            $contractId,
            $preview['funding'],
            $chequeIds,
            0.0,
            0.0,
            $userId
        );
        if (function_exists('co_shop_log_event')) {
            co_shop_log_event($conn, $companyId, $contractId, 'payment_workspace_prepaid_vat', [
                'payment_id' => $result['payment_id'],
                'journal_id' => $result['journal_id'],
                'amount' => $preview['amount'],
                'cheque_ids' => $chequeIds,
            ], $userId);
        }
        return array_merge($result, [
            'preview' => $preview,
            'allocation_status' => 'allocated',
        ]);
    }

    $targets = [];
    foreach ($preview['allocation_lines'] as $line) {
        $amt = round((float)$line['amount'], 2);
        if ($amt > 0.005) {
            $targets[] = ['id' => (int)$line['invoice_id'], 'balance' => $amt];
        }
    }
    if (!$targets && $prepaidVatAmount <= 0.005 && $preview['credit_amount'] > 0.005) {
        throw new RuntimeException(
            'Cannot post credit-only receipt without at least one invoice context. '
            . 'Open an invoice or allocate part of the payment first.'
        );
    }
    if (!$targets && $prepaidVatAmount <= 0.005) {
        throw new RuntimeException('No allocation amounts to confirm.');
    }

    $result = co_shop_record_multi_invoice_payment(
        $conn,
        $companyId,
        (int)$preview['client_id'],
        (float)$preview['amount'],
        (int)$preview['pay_account_id'],
        (string)$preview['payment_date'],
        $preview['reference'] ?: null,
        $targets,
        $userId,
        $prepaidVatAmount,
        $contractId
    );

    co_receipt_attach_workspace_meta(
        $conn,
        $companyId,
        (int)$result['payment_id'],
        $contractId,
        $preview['funding'],
        $chequeIds,
        (float)$result['allocated'],
        (float)$result['credit'],
        $userId
    );

    if (function_exists('co_shop_log_event')) {
        co_shop_log_event($conn, $companyId, $contractId, 'payment_workspace_confirm', [
            'payment_id' => $result['payment_id'],
            'journal_id' => $result['journal_id'],
            'amount' => $preview['amount'],
            'allocated' => $result['allocated'],
            'credit' => $result['credit'],
            'prepaid_vat' => $result['prepaid_vat'] ?? 0,
            'strategy' => $preview['strategy'],
            'cheque_ids' => $chequeIds,
        ], $userId);
    }

    return array_merge($result, [
        'preview' => $preview,
        'allocation_status' => ((float)$result['credit'] > 0.005) ? 'overpaid' : 'allocated',
    ]);
}

/**
 * Apply existing customer credit to an invoice (no new cash). Alias wrapper.
 */
function co_receipt_apply_credit(
    PDO $conn,
    int $companyId,
    int $clientId,
    int $invoiceId,
    float $amount,
    ?int $userId = null,
    ?int $contractId = null
): array {
    $result = co_shop_apply_client_credit_to_invoice($conn, $companyId, $clientId, $invoiceId, $amount, $userId);
    if (co_receipt_workspace_schema_ready($conn) && !empty($result['payment_id'])) {
        if (co_db_column_exists($conn, 'co_client_payments', 'contract_id') && $contractId) {
            $conn->prepare("
                UPDATE co_client_payments
                SET allocation_status = 'allocated', contract_id = ?
                WHERE id = ? AND company_id = ?
            ")->execute([$contractId, (int)$result['payment_id'], $companyId]);
        } else {
            $conn->prepare("
                UPDATE co_client_payments
                SET allocation_status = 'allocated'
                WHERE id = ? AND company_id = ?
            ")->execute([(int)$result['payment_id'], $companyId]);
        }
        co_receipt_log_event($conn, $companyId, (int)$result['payment_id'], 'apply_credit', [
            'invoice_id' => $invoiceId,
            'applied' => $result['applied'],
        ], $contractId, $userId);
    }
    return $result;
}

/**
 * Load payment scoped to company (fail closed).
 * @return array<string,mixed>
 */
function co_receipt_load_payment(PDO $conn, int $companyId, int $paymentId): array {
    if ($companyId <= 0 || $paymentId <= 0) {
        throw new RuntimeException('Company and payment are required.');
    }
    $stmt = $conn->prepare("SELECT * FROM co_client_payments WHERE id = ? AND company_id = ?");
    $stmt->execute([$paymentId, $companyId]);
    $pay = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pay) {
        throw new RuntimeException('Payment not found for this company.');
    }
    return $pay;
}

/**
 * @return list<array<string,mixed>>
 */
function co_receipt_payment_allocations(PDO $conn, int $companyId, int $paymentId): array {
    if (!co_client_allocations_ready($conn)) {
        return [];
    }
    $stmt = $conn->prepare("
        SELECT a.*, i.invoice_number, i.status AS invoice_status
        FROM co_client_payment_allocations a
        LEFT JOIN co_client_invoices i ON i.id = a.invoice_id AND i.company_id = a.company_id
        WHERE a.company_id = ? AND a.payment_id = ?
        ORDER BY a.id ASC
    ");
    $stmt->execute([$companyId, $paymentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Reverse a confirmed cash receipt (or credit-apply): reversing JV, reopen invoices,
 * unwind overpayment / restore applied credit, unlink cheques.
 */
function co_receipt_reverse(
    PDO $conn,
    int $companyId,
    int $paymentId,
    string $reason,
    ?int $userId = null,
    ?string $reversalDate = null
): array {
    require_once dirname(__DIR__, 2) . '/realestate/accounting/accounting_engine.php';

    $pay = co_receipt_load_payment($conn, $companyId, $paymentId);
    $status = (string)($pay['allocation_status'] ?? '');
    if (in_array($status, ['reversed', 'voided'], true)) {
        throw new RuntimeException('Payment is already ' . $status . '.');
    }

    $isCreditApply = (($pay['reference'] ?? '') === 'CREDIT-APPLY') || empty($pay['pay_account_id']);
    $allocs = co_receipt_payment_allocations($conn, $companyId, $paymentId);
    $snapshot = [
        'payment' => [
            'id' => (int)$pay['id'],
            'amount' => (float)$pay['amount'],
            'credit_amount' => (float)($pay['credit_amount'] ?? 0),
            'journal_id' => (int)($pay['journal_id'] ?? 0),
            'reference' => $pay['reference'] ?? null,
            'is_credit_apply' => $isCreditApply ? 1 : 0,
        ],
        'allocations' => $allocs,
        'reason' => $reason,
    ];

    $reversalJournalId = null;
    $journalId = (int)($pay['journal_id'] ?? 0);
    if ($journalId > 0) {
        if (function_exists('is_period_locked') && is_period_locked($companyId, $pay['payment_date'] ?? date('Y-m-d'))) {
            throw new RuntimeException('Accounting period is locked for this payment date. Unlock the period before reverse.');
        }
        co_receipt_assert_journal_company($conn, $companyId, $journalId);
        $rev = reverse_journal($journalId, $reason !== '' ? $reason : 'Payment reverse', $userId, $reversalDate);
        if (empty($rev['success'])) {
            throw new RuntimeException($rev['error'] ?? 'Failed to reverse payment journal.');
        }
        $reversalJournalId = (int)$rev['reversal_journal_id'];
    }

    foreach ($allocs as $a) {
        $conn->prepare("DELETE FROM co_client_payment_allocations WHERE id = ? AND company_id = ? AND payment_id = ?")
            ->execute([(int)$a['id'], $companyId, $paymentId]);
        co_update_client_invoice_status($conn, $companyId, (int)$a['invoice_id']);
    }

    if ($isCreditApply && !empty($pay['client_id'])) {
        // Restore customer credit that was consumed by apply
        $applied = round((float)$pay['amount'], 2);
        if ($applied > 0.005) {
            co_shop_adjust_client_credit(
                $conn,
                $companyId,
                (int)$pay['client_id'],
                $applied,
                'adjustment',
                $paymentId,
                null,
                'Reverse credit apply on payment #' . $paymentId,
                $userId
            );
        }
    } else {
        $credit = round((float)($pay['credit_amount'] ?? 0), 2);
        if ($credit > 0.005 && !empty($pay['client_id'])) {
            co_shop_adjust_client_credit(
                $conn,
                $companyId,
                (int)$pay['client_id'],
                -$credit,
                'adjustment',
                $paymentId,
                null,
                'Reverse overpayment credit on payment #' . $paymentId,
                $userId
            );
        }
    }

    if (function_exists('co_shop_cheques_unallocate_for_payment')) {
        co_shop_cheques_unallocate_for_payment($conn, $companyId, $paymentId, 'cleared', $userId);
    } elseif (co_db_column_exists($conn, 'co_shop_rent_cheques', 'payment_id')) {
        $conn->prepare("UPDATE co_shop_rent_cheques SET payment_id = NULL WHERE company_id = ? AND payment_id = ?")
            ->execute([$companyId, $paymentId]);
    }
    if (co_db_table_exists($conn, 'co_shop_receipt_cheque_links')) {
        $conn->prepare("DELETE FROM co_shop_receipt_cheque_links WHERE company_id = ? AND payment_id = ?")
            ->execute([$companyId, $paymentId]);
    }

    if (co_db_column_exists($conn, 'co_client_payments', 'allocation_status')) {
        $conn->prepare("
            UPDATE co_client_payments
            SET allocation_status = 'reversed', unallocated_amount = amount, credit_amount = 0
            WHERE id = ? AND company_id = ?
        ")->execute([$paymentId, $companyId]);
    }

    $contractId = !empty($pay['contract_id']) ? (int)$pay['contract_id'] : null;
    co_receipt_log_event($conn, $companyId, $paymentId, 'reverse', array_merge($snapshot, [
        'reversal_journal_id' => $reversalJournalId,
    ]), $contractId, $userId);

    if ($contractId && function_exists('co_shop_log_event')) {
        co_shop_log_event($conn, $companyId, $contractId, 'payment_reversed', [
            'payment_id' => $paymentId,
            'reversal_journal_id' => $reversalJournalId,
            'reason' => $reason,
            'credit_apply' => $isCreditApply ? 1 : 0,
        ], $userId);
    }

    return [
        'payment_id' => $paymentId,
        'reversal_journal_id' => $reversalJournalId,
        'allocations_removed' => count($allocs),
        'credit_unwound' => $isCreditApply ? 0.0 : round((float)($pay['credit_amount'] ?? 0), 2),
        'credit_restored' => $isCreditApply ? round((float)$pay['amount'], 2) : 0.0,
    ];
}

/**
 * Void payment: if not already reversed, reverse first; then mark terminal voided (no silent delete).
 */
function co_receipt_void(
    PDO $conn,
    int $companyId,
    int $paymentId,
    string $reason,
    ?int $userId = null
): array {
    $pay = co_receipt_load_payment($conn, $companyId, $paymentId);
    $status = (string)($pay['allocation_status'] ?? '');
    if ($status === 'voided') {
        throw new RuntimeException('Payment is already voided.');
    }

    $result = ['payment_id' => $paymentId, 'reversed' => false];
    if ($status !== 'reversed') {
        $result = array_merge(
            $result,
            co_receipt_reverse($conn, $companyId, $paymentId, $reason !== '' ? $reason : 'Void payment', $userId)
        );
        $result['reversed'] = true;
    }

    if (co_db_column_exists($conn, 'co_client_payments', 'allocation_status')) {
        $conn->prepare("
            UPDATE co_client_payments
            SET allocation_status = 'voided', voided_at = NOW(), void_reason = ?
            WHERE id = ? AND company_id = ?
        ")->execute([mb_substr($reason, 0, 500), $paymentId, $companyId]);
    }

    $contractId = !empty($pay['contract_id']) ? (int)$pay['contract_id'] : null;
    co_receipt_log_event($conn, $companyId, $paymentId, 'void', [
        'reason' => $reason,
        'prior_status' => $status,
    ], $contractId, $userId);

    return $result;
}

/**
 * Reallocate: reverse prior payment, then confirm a new payment with updated splits (audit trail).
 *
 * @param array<string,mixed> $newInput Preview/confirm input (amount/funding/allocations)
 * @return array<string,mixed>
 */
function co_receipt_reallocate(
    PDO $conn,
    int $companyId,
    int $paymentId,
    array $newInput,
    string $reason,
    ?int $userId = null
): array {
    $pay = co_receipt_load_payment($conn, $companyId, $paymentId);
    $status = (string)($pay['allocation_status'] ?? '');
    if (in_array($status, ['reversed', 'voided'], true)) {
        throw new RuntimeException('Cannot reallocate a ' . $status . ' payment.');
    }

    $reverse = co_receipt_reverse(
        $conn,
        $companyId,
        $paymentId,
        $reason !== '' ? $reason : 'Reallocate payment',
        $userId
    );

    $newInput['company_id'] = $companyId;
    if (empty($newInput['client_id'])) {
        $newInput['client_id'] = (int)$pay['client_id'];
    }
    if (empty($newInput['contract_id']) && !empty($pay['contract_id'])) {
        $newInput['contract_id'] = (int)$pay['contract_id'];
    }
    if (empty($newInput['pay_account_id'])) {
        $newInput['pay_account_id'] = (int)$pay['pay_account_id'];
    }
    if (empty($newInput['amount'])) {
        $newInput['amount'] = (float)$pay['amount'];
    }
    if (empty($newInput['payment_date'])) {
        $newInput['payment_date'] = $pay['payment_date'] ?? date('Y-m-d');
    }

    $confirm = co_receipt_confirm($conn, $newInput, $userId);
    $newPaymentId = (int)$confirm['payment_id'];

    if (co_db_column_exists($conn, 'co_client_payments', 'reversed_payment_id')) {
        $conn->prepare("UPDATE co_client_payments SET reversed_payment_id = ? WHERE id = ? AND company_id = ?")
            ->execute([$newPaymentId, $paymentId, $companyId]);
    }

    co_receipt_log_event($conn, $companyId, $paymentId, 'reallocate', [
        'reason' => $reason,
        'new_payment_id' => $newPaymentId,
        'reverse' => $reverse,
    ], !empty($newInput['contract_id']) ? (int)$newInput['contract_id'] : null, $userId);
    co_receipt_log_event($conn, $companyId, $newPaymentId, 'reallocate_successor', [
        'prior_payment_id' => $paymentId,
        'reason' => $reason,
    ], !empty($newInput['contract_id']) ? (int)$newInput['contract_id'] : null, $userId);

    return [
        'prior_payment_id' => $paymentId,
        'payment_id' => $newPaymentId,
        'journal_id' => $confirm['journal_id'] ?? null,
        'reverse' => $reverse,
        'confirm' => $confirm,
    ];
}
