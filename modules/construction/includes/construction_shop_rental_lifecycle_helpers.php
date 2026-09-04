<?php
/**
 * Construction Shop Rental Phase 2A — lifecycle: status, renewal, amendments, events.
 * Company-isolated. Does not touch Real Estate.
 */

function co_shop_phase2a_schema_ready(PDO $conn): bool {
    return co_db_column_exists($conn, 'co_shop_rental_contracts', 'parent_contract_id')
        && co_db_table_exists($conn, 'co_shop_contract_events')
        && co_db_table_exists($conn, 'co_shop_contract_amendments')
        && co_db_table_exists($conn, 'co_shop_move_out_inspections')
        && co_db_table_exists($conn, 'co_shop_deposit_settlements')
        && co_db_table_exists($conn, 'co_shop_contract_terminations');
}

function co_shop_allowed_statuses(): array {
    return ['draft', 'active', 'renewed', 'expired', 'terminated', 'archived'];
}

function co_shop_status_transition_allowed(string $from, string $to): bool {
    if ($from === $to) {
        return true;
    }
    $map = [
        'draft' => ['active', 'archived'],
        'active' => ['renewed', 'expired', 'terminated'],
        'renewed' => ['archived'],
        'expired' => ['archived', 'active'], // re-activate rare repair
        'terminated' => ['archived'],
        'archived' => [],
    ];
    return in_array($to, $map[$from] ?? [], true);
}

function co_shop_log_event(
    PDO $conn,
    int $companyId,
    int $contractId,
    string $eventType,
    array $payload = [],
    ?int $userId = null
): void {
    if (!co_db_table_exists($conn, 'co_shop_contract_events')) {
        return;
    }
    $conn->prepare("
        INSERT INTO co_shop_contract_events (company_id, contract_id, event_type, payload_json, created_by)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        $companyId,
        $contractId,
        $eventType,
        $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        $userId,
    ]);
}

function co_shop_get_settings(PDO $conn, int $companyId): array {
    $defaults = [
        'default_termination_penalty_months' => 2.0,
        'suggested_damage_amount' => 500.0,
        'suggested_cleaning_amount' => 300.0,
        'suggested_utility_amount' => 200.0,
        'suggested_missing_keys_amount' => 150.0,
        'allocation_strategy' => 'fifo',
    ];
    if (!co_db_table_exists($conn, 'co_shop_rental_settings')) {
        return $defaults;
    }
    $stmt = $conn->prepare("SELECT * FROM co_shop_rental_settings WHERE company_id = ?");
    $stmt->execute([$companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $conn->prepare("INSERT IGNORE INTO co_shop_rental_settings (company_id) VALUES (?)")->execute([$companyId]);
        return $defaults;
    }
    $strategy = (string)($row['allocation_strategy'] ?? 'fifo');
    if (!in_array($strategy, ['fifo', 'oldest_due', 'charge_priority', 'manual'], true)) {
        $strategy = 'fifo';
    }
    return array_merge($defaults, [
        'default_termination_penalty_months' => (float)$row['default_termination_penalty_months'],
        'suggested_damage_amount' => (float)$row['suggested_damage_amount'],
        'suggested_cleaning_amount' => (float)$row['suggested_cleaning_amount'],
        'suggested_utility_amount' => (float)$row['suggested_utility_amount'],
        'suggested_missing_keys_amount' => (float)$row['suggested_missing_keys_amount'],
        'allocation_strategy' => $strategy,
    ]);
}

/**
 * Snapshot of amendable commercial fields.
 */
function co_shop_contract_amendment_snapshot(PDO $conn, int $companyId, array $contract): array {
    $shops = [];
    if (function_exists('co_shop_contract_shops')) {
        foreach (co_shop_contract_shops($conn, $companyId, (int)$contract['id']) as $s) {
            $shops[] = [
                'shop_unit_id' => (int)$s['shop_unit_id'],
                'shop_number' => $s['shop_number'] ?? '',
                'is_primary' => (int)($s['is_primary'] ?? 0),
            ];
        }
    }
    return [
        'start_date' => $contract['start_date'] ?? null,
        'end_date' => $contract['end_date'] ?? null,
        'rent_amount' => (float)($contract['rent_amount'] ?? 0),
        'payment_frequency' => $contract['payment_frequency'] ?? null,
        'rent_cheque_count' => (int)($contract['rent_cheque_count'] ?? 0),
        'deposit_cheque_count' => (int)($contract['deposit_cheque_count'] ?? 0),
        'combined_first_cheque' => (int)($contract['combined_first_cheque'] ?? 0),
        'security_deposit' => (float)($contract['security_deposit'] ?? 0),
        'vat_rate' => (float)($contract['vat_rate'] ?? 0),
        'vat_mode' => $contract['vat_mode'] ?? null,
        'vat_collection_method' => $contract['vat_collection_method'] ?? null,
        'accrual_deferred_rent' => (int)($contract['accrual_deferred_rent'] ?? 0),
        'commission_enabled' => (int)($contract['commission_enabled'] ?? 0),
        'commission_basis' => $contract['commission_basis'] ?? null,
        'commission_percent' => (float)($contract['commission_percent'] ?? 0),
        'commission_fixed_amount' => (float)($contract['commission_fixed_amount'] ?? 0),
        'commission_net_amount' => (float)($contract['commission_net_amount'] ?? 0),
        'commission_vat_enabled' => (int)($contract['commission_vat_enabled'] ?? 0),
        'commission_vat_rate' => (float)($contract['commission_vat_rate'] ?? 0),
        'payment_terms' => $contract['payment_terms'] ?? null,
        'notes' => $contract['notes'] ?? null,
        'shops' => $shops,
    ];
}

function co_shop_record_amendment(
    PDO $conn,
    int $companyId,
    int $contractId,
    string $reason,
    array $before,
    array $after,
    ?int $userId
): int {
    if (!co_shop_phase2a_schema_ready($conn)) {
        throw new RuntimeException('Run migrations/construction_shop_rental_phase2a.sql');
    }
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('Amendment reason is required.');
    }
    if ($before == $after) {
        return 0;
    }
    $conn->prepare("
        INSERT INTO co_shop_contract_amendments (company_id, contract_id, reason, before_json, after_json, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([
        $companyId,
        $contractId,
        $reason,
        json_encode($before, JSON_UNESCAPED_UNICODE),
        json_encode($after, JSON_UNESCAPED_UNICODE),
        $userId,
    ]);
    $id = (int)$conn->lastInsertId();
    co_shop_log_event($conn, $companyId, $contractId, 'amendment', ['amendment_id' => $id, 'reason' => $reason], $userId);
    return $id;
}

/**
 * Monthly net rent equivalent (Option B).
 * When Rent Concession is active, divides by chargeable months (not Occupancy months).
 */
function co_shop_monthly_rent_equivalent(array $contract): float {
    $rent = (float)($contract['rent_amount'] ?? 0);
    $start = $contract['start_date'] ?? null;
    $end = $contract['end_date'] ?? null;
    if ($rent <= 0 || !$start || !$end) {
        return 0.0;
    }
    if (function_exists('co_shop_chargeable_month_count')) {
        $months = co_shop_chargeable_month_count($contract);
    } else {
        $months = co_shop_contract_month_count($start, $end);
    }
    if ($months <= 0) {
        return 0.0;
    }
    return round($rent / $months, 2);
}

function co_shop_contract_month_count(string $startDate, string $endDate): int {
    // Match rent earning windows (BR-CO-SHOP-007): start..end in +1 month -1 day steps.
    // Calendar inclusive month math wrongly turns 11 Jul Y → 10 Jul Y+1 into 13.
    try {
        $cursor = new DateTime($startDate);
        $end = new DateTime($endDate);
        if ($end < $cursor) {
            return 0;
        }
        $n = 0;
        while ($cursor <= $end) {
            $n++;
            $cursor->modify('+1 month');
            if ($n > 120) {
                break;
            }
        }
        return max(1, $n);
    } catch (Throwable $e) {
        return 1;
    }
}

/**
 * Create renewal draft from existing contract. Parent stays active.
 * @return int new contract id
 */
function co_shop_create_renewal_draft(
    PDO $conn,
    int $companyId,
    int $sourceContractId,
    array $overrides,
    ?int $userId
): int {
    if (!co_shop_phase2a_schema_ready($conn)) {
        throw new RuntimeException('Run migrations/construction_shop_rental_phase2a.sql');
    }
    $stmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$sourceContractId, $companyId]);
    $src = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$src) {
        throw new RuntimeException('Source contract not found.');
    }
    if (!in_array($src['status'], ['active', 'expired'], true)) {
        throw new RuntimeException('Only active or expired contracts can be renewed.');
    }
    $dup = $conn->prepare("
        SELECT id FROM co_shop_rental_contracts
        WHERE company_id = ? AND parent_contract_id = ? AND status IN ('draft','active')
        LIMIT 1
    ");
    $dup->execute([$companyId, $sourceContractId]);
    if ($dup->fetchColumn()) {
        throw new RuntimeException('A renewal draft/active contract already exists for this lease.');
    }

    $start = $overrides['start_date'] ?? date('Y-m-d', strtotime($src['end_date'] . ' +1 day'));
    $end = $overrides['end_date'] ?? date('Y-m-d', strtotime($start . ' +1 year -1 day'));
    $rent = isset($overrides['rent_amount']) ? (float)$overrides['rent_amount'] : (float)$src['rent_amount'];
    $shopIds = null;
    if (!empty($overrides['shop_unit_ids'])) {
        $sel = co_shop_normalize_shop_selection($overrides['shop_unit_ids'], $overrides['primary_shop_unit_id'] ?? null);
        $shopIds = $sel['shop_ids'];
        $primaryId = $sel['primary_id'];
    } else {
        $shops = co_shop_contract_shops($conn, $companyId, $sourceContractId);
        $shopIds = array_map(static fn($r) => (int)$r['shop_unit_id'], $shops);
        $primaryId = (int)$src['shop_unit_id'];
        foreach ($shops as $s) {
            if (!empty($s['is_primary'])) {
                $primaryId = (int)$s['shop_unit_id'];
            }
        }
    }
    if ($rent <= 0) {
        throw new RuntimeException('Rent amount must be greater than zero.');
    }
    co_shop_assert_shops_in_company($conn, $companyId, $shopIds);
    $warn = co_shop_draft_overlap_warnings($conn, $companyId, $shopIds, $start, $end, $sourceContractId);

    $number = co_next_document_number($conn, $companyId, 'SHOP', 'co_shop_rental_contracts', 'contract_number');
    $hasComm = co_shop_commission_schema_ready($conn);

    if ($hasComm) {
        $ins = $conn->prepare("
            INSERT INTO co_shop_rental_contracts
                (company_id, shop_unit_id, client_id, parent_contract_id, contract_number, start_date, end_date,
                 rent_amount, payment_frequency, rent_cheque_count, deposit_cheque_count, accrual_deferred_rent,
                 vat_rate, vat_mode, vat_collection_method, security_deposit, payment_terms, status, notes,
                 commission_enabled, commission_basis, commission_percent, commission_fixed_amount, commission_net_amount,
                 commission_manual_override, commission_vat_enabled, commission_vat_rate, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $commEnabled = array_key_exists('commission_enabled', $overrides)
            ? (!empty($overrides['commission_enabled']) ? 1 : 0)
            : (int)($src['commission_enabled'] ?? 1);
        $commBasis = $overrides['commission_basis'] ?? ($src['commission_basis'] ?? 'percent');
        $commPct = (float)($overrides['commission_percent'] ?? ($src['commission_percent'] ?? 5));
        $commFixed = (float)($overrides['commission_fixed_amount'] ?? ($src['commission_fixed_amount'] ?? 0));
        $commManual = !empty($overrides['commission_manual_override']) ? 1 : (int)($src['commission_manual_override'] ?? 0);
        $commVatEn = array_key_exists('commission_vat_enabled', $overrides)
            ? (!empty($overrides['commission_vat_enabled']) ? 1 : 0)
            : (int)($src['commission_vat_enabled'] ?? 1);
        $commVatRate = (float)($overrides['commission_vat_rate'] ?? ($src['commission_vat_rate'] ?? 5));
        $tmp = [
            'rent_amount' => $rent,
            'commission_basis' => $commBasis,
            'commission_percent' => $commPct,
            'commission_fixed_amount' => $commFixed,
            'commission_manual_override' => $commManual,
            'commission_net_amount' => $overrides['commission_net_amount'] ?? null,
            'commission_enabled' => $commEnabled,
            'commission_vat_enabled' => $commVatEn,
            'commission_vat_rate' => $commVatRate,
        ];
        if ($commManual && isset($overrides['commission_net_amount'])) {
            $commNet = max(0, round((float)$overrides['commission_net_amount'], 2));
        } else {
            $commNet = co_shop_commission_compute_net($tmp);
        }
        $ins->execute([
            $companyId, $primaryId, (int)$src['client_id'], $sourceContractId, $number, $start, $end,
            $rent,
            $overrides['payment_frequency'] ?? $src['payment_frequency'],
            (int)($overrides['rent_cheque_count'] ?? $src['rent_cheque_count']),
            (int)($overrides['deposit_cheque_count'] ?? $src['deposit_cheque_count']),
            array_key_exists('accrual_deferred_rent', $overrides) ? (!empty($overrides['accrual_deferred_rent']) ? 1 : 0) : (int)$src['accrual_deferred_rent'],
            (float)($overrides['vat_rate'] ?? $src['vat_rate']),
            $overrides['vat_mode'] ?? $src['vat_mode'],
            $overrides['vat_collection_method'] ?? $src['vat_collection_method'],
            (float)($overrides['security_deposit'] ?? $src['security_deposit']),
            $overrides['payment_terms'] ?? $src['payment_terms'],
            'draft',
            $overrides['notes'] ?? $src['notes'],
            $commEnabled, $commBasis, $commPct, $commFixed, $commNet, $commManual, $commVatEn, $commVatRate,
            $userId,
        ]);
    } else {
        $ins = $conn->prepare("
            INSERT INTO co_shop_rental_contracts
                (company_id, shop_unit_id, client_id, parent_contract_id, contract_number, start_date, end_date,
                 rent_amount, payment_frequency, rent_cheque_count, deposit_cheque_count, accrual_deferred_rent,
                 vat_rate, vat_mode, vat_collection_method, security_deposit, payment_terms, status, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $ins->execute([
            $companyId, $primaryId, (int)$src['client_id'], $sourceContractId, $number, $start, $end,
            $rent,
            $overrides['payment_frequency'] ?? $src['payment_frequency'],
            (int)($overrides['rent_cheque_count'] ?? $src['rent_cheque_count']),
            (int)($overrides['deposit_cheque_count'] ?? $src['deposit_cheque_count']),
            (int)$src['accrual_deferred_rent'],
            (float)($overrides['vat_rate'] ?? $src['vat_rate']),
            $overrides['vat_mode'] ?? $src['vat_mode'],
            $overrides['vat_collection_method'] ?? $src['vat_collection_method'],
            (float)($overrides['security_deposit'] ?? $src['security_deposit']),
            $overrides['payment_terms'] ?? $src['payment_terms'],
            'draft',
            $overrides['notes'] ?? $src['notes'],
            $userId,
        ]);
    }
    $newId = (int)$conn->lastInsertId();
    co_shop_sync_contract_shops($conn, $companyId, $newId, $shopIds, $primaryId);
    if (co_db_column_exists($conn, 'co_shop_rental_contracts', 'combined_first_cheque')) {
        $combinedFlag = array_key_exists('combined_first_cheque', $overrides)
            ? (!empty($overrides['combined_first_cheque']) ? 1 : 0)
            : (int)($src['combined_first_cheque'] ?? 0);
        $conn->prepare("UPDATE co_shop_rental_contracts SET combined_first_cheque = ? WHERE id = ? AND company_id = ?")
            ->execute([$combinedFlag, $newId, $companyId]);
    }
    $conn->prepare("UPDATE co_shop_rental_contracts SET renewed_to_contract_id = ? WHERE id = ? AND company_id = ?")
        ->execute([$newId, $sourceContractId, $companyId]);
    co_shop_log_event($conn, $companyId, $sourceContractId, 'renewal_draft_created', [
        'new_contract_id' => $newId,
        'warnings' => $warn,
    ], $userId);
    co_shop_log_event($conn, $companyId, $newId, 'created_as_renewal', [
        'parent_contract_id' => $sourceContractId,
    ], $userId);
    return $newId;
}

/**
 * Activate renewal: parent active→renewed, then child→active (same txn caller).
 */
function co_shop_activate_renewal(PDO $conn, int $companyId, int $renewalContractId, ?int $userId = null): void {
    $stmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$renewalContractId, $companyId]);
    $child = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$child) {
        throw new RuntimeException('Renewal contract not found.');
    }
    $parentId = (int)($child['parent_contract_id'] ?? 0);
    if ($parentId <= 0) {
        throw new RuntimeException('This contract is not a renewal.');
    }
    if ($child['status'] !== 'draft') {
        throw new RuntimeException('Only draft renewals can be activated via renewal flow.');
    }
    $pstmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $pstmt->execute([$parentId, $companyId]);
    $parent = $pstmt->fetch(PDO::FETCH_ASSOC);
    if (!$parent) {
        throw new RuntimeException('Parent contract not found.');
    }
    if ($parent['status'] === 'active') {
        // Mark renewed first so overlap check no longer sees parent as active
        $conn->prepare("UPDATE co_shop_rental_contracts SET status = 'renewed' WHERE id = ? AND company_id = ?")
            ->execute([$parentId, $companyId]);
        co_shop_release_contract_shops($conn, $companyId, $parentId);
        co_shop_log_event($conn, $companyId, $parentId, 'status_renewed', [
            'renewed_to' => $renewalContractId,
        ], $userId);
    } elseif (!in_array($parent['status'], ['renewed', 'expired'], true)) {
        throw new RuntimeException('Parent contract cannot be renewed from status ' . $parent['status']);
    }

    co_shop_set_contract_status($conn, $companyId, $renewalContractId, 'active');
    co_shop_log_event($conn, $companyId, $renewalContractId, 'renewal_activated', [
        'parent_contract_id' => $parentId,
    ], $userId);
}
