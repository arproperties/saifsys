<?php
/**
 * Construction Shop Rental Phase 1.75 — multi-shop, occupancy, overlap, control-center metrics.
 * Read/write operational only. Does not change invoice/payment/recognition posting.
 */

function co_shop_phase175_schema_ready(PDO $conn): bool {
    return co_db_table_exists($conn, 'co_shop_rental_contract_shops');
}

/**
 * @return list<array>
 */
function co_shop_contract_shops(PDO $conn, int $companyId, int $contractId): array {
    if (!co_shop_phase175_schema_ready($conn)) {
        $stmt = $conn->prepare("
            SELECT u.id AS shop_unit_id, u.shop_number, u.shop_name, u.status AS unit_status, 1 AS is_primary
            FROM co_shop_rental_contracts c
            JOIN co_shop_units u ON u.id = c.shop_unit_id
            WHERE c.id = ? AND c.company_id = ?
        ");
        $stmt->execute([$contractId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? [$row] : [];
    }
    $stmt = $conn->prepare("
        SELECT cs.shop_unit_id, cs.is_primary, u.shop_number, u.shop_name, u.status AS unit_status
        FROM co_shop_rental_contract_shops cs
        JOIN co_shop_units u ON u.id = cs.shop_unit_id AND u.company_id = cs.company_id
        WHERE cs.company_id = ? AND cs.contract_id = ?
        ORDER BY cs.is_primary DESC, u.shop_number ASC
    ");
    $stmt->execute([$companyId, $contractId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        return $rows;
    }
    // Legacy fallback if junction empty
    $stmt = $conn->prepare("
        SELECT u.id AS shop_unit_id, u.shop_number, u.shop_name, u.status AS unit_status, 1 AS is_primary
        FROM co_shop_rental_contracts c
        JOIN co_shop_units u ON u.id = c.shop_unit_id
        WHERE c.id = ? AND c.company_id = ?
    ");
    $stmt->execute([$contractId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? [$row] : [];
}

function co_shop_contract_shops_label(PDO $conn, int $companyId, int $contractId): string {
    $shops = co_shop_contract_shops($conn, $companyId, $contractId);
    if (!$shops) {
        return '';
    }
    $parts = [];
    foreach ($shops as $s) {
        $label = (string)$s['shop_number'];
        if (!empty($s['is_primary'])) {
            $label .= ' (primary)';
        }
        $parts[] = $label;
    }
    return implode(', ', $parts);
}

/**
 * Normalize posted shop ids + optional primary.
 * @param list<int|string> $shopIds
 * @return array{shop_ids: list<int>, primary_id: int}
 */
function co_shop_normalize_shop_selection(array $shopIds, ?int $primaryId): array {
    $ids = [];
    foreach ($shopIds as $id) {
        $id = (int)$id;
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }
    if (!$ids) {
        throw new RuntimeException('Select at least one shop.');
    }
    if ($primaryId && in_array($primaryId, $ids, true)) {
        $primary = $primaryId;
    } else {
        $primary = $ids[0];
    }
    return ['shop_ids' => $ids, 'primary_id' => $primary];
}

/**
 * Active-only date overlap for a shop. Excludes $excludeContractId.
 * @return list<array>
 */
function co_shop_find_active_overlaps(
    PDO $conn,
    int $companyId,
    int $shopUnitId,
    string $startDate,
    string $endDate,
    ?int $excludeContractId = null
): array {
    if ($endDate < $startDate) {
        throw new RuntimeException('End date must be on or after start date.');
    }
    if (co_shop_phase175_schema_ready($conn)) {
        $sql = "
            SELECT c.id, c.contract_number, c.start_date, c.end_date, c.status, u.shop_number
            FROM co_shop_rental_contract_shops cs
            JOIN co_shop_rental_contracts c ON c.id = cs.contract_id AND c.company_id = cs.company_id
            JOIN co_shop_units u ON u.id = cs.shop_unit_id
            WHERE cs.company_id = ?
              AND cs.shop_unit_id = ?
              AND c.status = 'active'
              AND c.start_date <= ?
              AND c.end_date >= ?
        ";
        $params = [$companyId, $shopUnitId, $endDate, $startDate];
        if ($excludeContractId) {
            $sql .= " AND c.id <> ?";
            $params[] = $excludeContractId;
        }
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $sql = "
        SELECT c.id, c.contract_number, c.start_date, c.end_date, c.status, u.shop_number
        FROM co_shop_rental_contracts c
        JOIN co_shop_units u ON u.id = c.shop_unit_id
        WHERE c.company_id = ?
          AND c.shop_unit_id = ?
          AND c.status = 'active'
          AND c.start_date <= ?
          AND c.end_date >= ?
    ";
    $params = [$companyId, $shopUnitId, $endDate, $startDate];
    if ($excludeContractId) {
        $sql .= " AND c.id <> ?";
        $params[] = $excludeContractId;
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Soft warnings for draft contracts overlapping ACTIVE leases (does not block).
 * @param list<int> $shopIds
 * @return list<string>
 */
function co_shop_draft_overlap_warnings(
    PDO $conn,
    int $companyId,
    array $shopIds,
    string $startDate,
    string $endDate,
    ?int $excludeContractId = null
): array {
    $warnings = [];
    foreach ($shopIds as $shopId) {
        foreach (co_shop_find_active_overlaps($conn, $companyId, (int)$shopId, $startDate, $endDate, $excludeContractId) as $hit) {
            $warnings[] = 'Shop ' . $hit['shop_number'] . ' is on active contract '
                . $hit['contract_number'] . ' (' . $hit['start_date'] . ' → ' . $hit['end_date'] . '). '
                . 'Draft does not occupy the shop, but activate will be blocked until dates/shops are resolved.';
        }
    }
    return array_values(array_unique($warnings));
}

/**
 * Hard-block when status is active (or activating) and any shop overlaps another ACTIVE contract.
 * @param list<int> $shopIds
 */
function co_shop_assert_no_active_overlap(
    PDO $conn,
    int $companyId,
    array $shopIds,
    string $startDate,
    string $endDate,
    ?int $excludeContractId = null
): void {
    foreach ($shopIds as $shopId) {
        $hits = co_shop_find_active_overlaps($conn, $companyId, (int)$shopId, $startDate, $endDate, $excludeContractId);
        if ($hits) {
            $h = $hits[0];
            throw new RuntimeException(
                'Shop ' . $h['shop_number'] . ' overlaps active contract '
                . $h['contract_number'] . ' (' . $h['start_date'] . ' → ' . $h['end_date'] . ').'
            );
        }
    }
}

/**
 * Ensure all shop ids belong to company.
 * @param list<int> $shopIds
 */
function co_shop_assert_shops_in_company(PDO $conn, int $companyId, array $shopIds): void {
    if (!$shopIds) {
        throw new RuntimeException('Select at least one shop.');
    }
    $placeholders = implode(',', array_fill(0, count($shopIds), '?'));
    $params = array_merge([$companyId], $shopIds);
    $stmt = $conn->prepare("SELECT COUNT(*) FROM co_shop_units WHERE company_id = ? AND id IN ($placeholders)");
    $stmt->execute($params);
    if ((int)$stmt->fetchColumn() !== count($shopIds)) {
        throw new RuntimeException('One or more shops are invalid for this company.');
    }
}

/**
 * Replace contract shop links and sync legacy shop_unit_id to primary.
 * Caller must run inside a transaction when combined with status/occupancy.
 * @param list<int> $shopIds
 */
function co_shop_sync_contract_shops(
    PDO $conn,
    int $companyId,
    int $contractId,
    array $shopIds,
    int $primaryId
): void {
    if (!co_shop_phase175_schema_ready($conn)) {
        $conn->prepare("UPDATE co_shop_rental_contracts SET shop_unit_id = ? WHERE id = ? AND company_id = ?")
            ->execute([$primaryId, $contractId, $companyId]);
        return;
    }
    co_shop_assert_shops_in_company($conn, $companyId, $shopIds);
    if (!in_array($primaryId, $shopIds, true)) {
        $primaryId = $shopIds[0];
    }

    $old = co_shop_contract_shops($conn, $companyId, $contractId);
    $oldIds = array_map(static fn($r) => (int)$r['shop_unit_id'], $old);

    $conn->prepare("DELETE FROM co_shop_rental_contract_shops WHERE company_id = ? AND contract_id = ?")
        ->execute([$companyId, $contractId]);
    $ins = $conn->prepare("
        INSERT INTO co_shop_rental_contract_shops (company_id, contract_id, shop_unit_id, is_primary)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($shopIds as $sid) {
        $ins->execute([$companyId, $contractId, $sid, $sid === $primaryId ? 1 : 0]);
    }
    $conn->prepare("UPDATE co_shop_rental_contracts SET shop_unit_id = ? WHERE id = ? AND company_id = ?")
        ->execute([$primaryId, $contractId, $companyId]);

    // Release shops removed from this contract if no other active lease uses them
    $removed = array_diff($oldIds, $shopIds);
    foreach ($removed as $sid) {
        co_shop_try_release_unit($conn, $companyId, (int)$sid);
    }
}

function co_shop_shop_has_active_contract(PDO $conn, int $companyId, int $shopUnitId, ?int $excludeContractId = null): bool {
    return (bool)co_shop_find_active_overlaps(
        $conn,
        $companyId,
        $shopUnitId,
        '1900-01-01',
        '9999-12-31',
        $excludeContractId
    );
}

/** Mark shop available only if no other active contract uses it. */
function co_shop_try_release_unit(PDO $conn, int $companyId, int $shopUnitId): bool {
    if (co_shop_shop_has_active_contract($conn, $companyId, $shopUnitId, null)) {
        return false;
    }
    $conn->prepare("
        UPDATE co_shop_units SET status = 'available'
        WHERE id = ? AND company_id = ? AND status = 'occupied'
    ")->execute([$shopUnitId, $companyId]);
    return true;
}

/** Occupy all shops on an active contract. */
function co_shop_occupy_contract_shops(PDO $conn, int $companyId, int $contractId): void {
    $shops = co_shop_contract_shops($conn, $companyId, $contractId);
    foreach ($shops as $s) {
        $conn->prepare("
            UPDATE co_shop_units SET status = 'occupied'
            WHERE id = ? AND company_id = ? AND status <> 'inactive'
        ")->execute([(int)$s['shop_unit_id'], $companyId]);
    }
}

/** Release all shops on this contract that are not used by another active lease. */
function co_shop_release_contract_shops(PDO $conn, int $companyId, int $contractId): void {
    $shops = co_shop_contract_shops($conn, $companyId, $contractId);
    foreach ($shops as $s) {
        // Exclude this contract when checking — it is being expired/terminated
        $sid = (int)$s['shop_unit_id'];
        if (!co_shop_shop_has_active_contract($conn, $companyId, $sid, $contractId)) {
            $conn->prepare("
                UPDATE co_shop_units SET status = 'available'
                WHERE id = ? AND company_id = ? AND status = 'occupied'
            ")->execute([$sid, $companyId]);
        }
    }
}

/**
 * Change contract status with occupancy rules. Manual only — no silent expiry.
 * Renewed is normally set via co_shop_activate_renewal (parent flip).
 */
function co_shop_set_contract_status(PDO $conn, int $companyId, int $contractId, string $newStatus): void {
    $allowed = co_shop_phase2a_schema_ready($conn)
        ? co_shop_allowed_statuses()
        : ['draft', 'active', 'expired', 'terminated'];
    if (!in_array($newStatus, $allowed, true)) {
        throw new RuntimeException('Invalid contract status.');
    }
    $stmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$contractId, $companyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        throw new RuntimeException('Contract not found.');
    }
    $old = (string)$contract['status'];
    if ($old === $newStatus) {
        return;
    }
    if (function_exists('co_shop_status_transition_allowed') && !co_shop_status_transition_allowed($old, $newStatus)) {
        // Allow renew→active already handled; allow activate without parent via normal path
        if (!($old === 'draft' && $newStatus === 'active')
            && !($old === 'active' && in_array($newStatus, ['expired', 'terminated', 'renewed'], true))
            && !($old === 'expired' && $newStatus === 'active')
            && !(in_array($old, ['draft', 'expired', 'terminated', 'renewed'], true) && $newStatus === 'archived')
        ) {
            throw new RuntimeException("Invalid status transition from {$old} to {$newStatus}.");
        }
    }

    $shopIds = array_map(
        static fn($r) => (int)$r['shop_unit_id'],
        co_shop_contract_shops($conn, $companyId, $contractId)
    );
    if (!$shopIds) {
        throw new RuntimeException('Contract has no shops linked.');
    }

    if ($newStatus === 'active') {
        co_shop_assert_no_active_overlap(
            $conn,
            $companyId,
            $shopIds,
            (string)$contract['start_date'],
            (string)$contract['end_date'],
            $contractId
        );
    }

    if ($newStatus === 'archived') {
        $conn->prepare("UPDATE co_shop_rental_contracts SET status = ?, archived_at = NOW() WHERE id = ? AND company_id = ?")
            ->execute([$newStatus, $contractId, $companyId]);
    } elseif ($newStatus === 'terminated' && co_db_column_exists($conn, 'co_shop_rental_contracts', 'terminated_at')) {
        $conn->prepare("UPDATE co_shop_rental_contracts SET status = ?, terminated_at = COALESCE(terminated_at, NOW()) WHERE id = ? AND company_id = ?")
            ->execute([$newStatus, $contractId, $companyId]);
    } else {
        $conn->prepare("UPDATE co_shop_rental_contracts SET status = ? WHERE id = ? AND company_id = ?")
            ->execute([$newStatus, $contractId, $companyId]);
    }

    if ($newStatus === 'active') {
        co_shop_occupy_contract_shops($conn, $companyId, $contractId);
    } elseif (in_array($newStatus, ['expired', 'terminated', 'draft', 'renewed', 'archived'], true) && $old === 'active') {
        co_shop_release_contract_shops($conn, $companyId, $contractId);
    }
    if (function_exists('co_shop_log_event')) {
        co_shop_log_event($conn, $companyId, $contractId, 'status_changed', ['from' => $old, 'to' => $newStatus], null);
    }
}

/**
 * SQL expression for shop label on a contract alias (e.g. rc / c).
 * Prefer junction GROUP_CONCAT; fall back to primary unit.
 */
function co_shop_sql_shops_label(string $contractAlias = 'c'): string {
    return "(
        SELECT GROUP_CONCAT(u2.shop_number ORDER BY cs2.is_primary DESC, u2.shop_number SEPARATOR ', ')
        FROM co_shop_rental_contract_shops cs2
        JOIN co_shop_units u2 ON u2.id = cs2.shop_unit_id
        WHERE cs2.contract_id = {$contractAlias}.id AND cs2.company_id = {$contractAlias}.company_id
    )";
}

/**
 * Control Center portfolio snapshot — contract-level money, separate shop counts.
 */
function co_shop_control_center_snapshot(PDO $conn, int $companyId): array {
    $today = date('Y-m-d');
    $soon = date('Y-m-d', strtotime('+60 days'));

    $units = ['available' => 0, 'occupied' => 0, 'inactive' => 0, 'total' => 0];
    if (co_db_table_exists($conn, 'co_shop_units')) {
        $u = $conn->prepare("SELECT status, COUNT(*) AS n FROM co_shop_units WHERE company_id = ? GROUP BY status");
        $u->execute([$companyId]);
        foreach ($u->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $units[$row['status']] = (int)$row['n'];
            $units['total'] += (int)$row['n'];
        }
    }

    $contracts = ['draft' => 0, 'active' => 0, 'renewed' => 0, 'expired' => 0, 'terminated' => 0, 'archived' => 0, 'total' => 0, 'expiring_soon' => 0, 'past_end_active' => 0, 'pending_renewal_drafts' => 0];
    if (co_db_table_exists($conn, 'co_shop_rental_contracts')) {
        $c = $conn->prepare("SELECT status, COUNT(*) AS n FROM co_shop_rental_contracts WHERE company_id = ? GROUP BY status");
        $c->execute([$companyId]);
        foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $contracts[$row['status']] = (int)$row['n'];
            $contracts['total'] += (int)$row['n'];
        }
        $ex = $conn->prepare("
            SELECT COUNT(*) FROM co_shop_rental_contracts
            WHERE company_id = ? AND status = 'active' AND end_date BETWEEN ? AND ?
        ");
        $ex->execute([$companyId, $today, $soon]);
        $contracts['expiring_soon'] = (int)$ex->fetchColumn();
        $pe = $conn->prepare("
            SELECT COUNT(*) FROM co_shop_rental_contracts
            WHERE company_id = ? AND status = 'active' AND end_date < ?
        ");
        $pe->execute([$companyId, $today]);
        $contracts['past_end_active'] = (int)$pe->fetchColumn();
        if (co_db_column_exists($conn, 'co_shop_rental_contracts', 'parent_contract_id')) {
            $pr = $conn->prepare("
                SELECT COUNT(*) FROM co_shop_rental_contracts
                WHERE company_id = ? AND status = 'draft' AND parent_contract_id IS NOT NULL
            ");
            $pr->execute([$companyId]);
            $contracts['pending_renewal_drafts'] = (int)$pr->fetchColumn();
        }
    }

    // Financials: one row per contract (never join shops)
    $invoiced = $collected = $outstanding = $overdue = 0.0;
    $deferred = $recognized = $depositsHeld = $tenantCredit = 0.0;
    $pendingInvoices = $pendingDeposits = $pendingRecognition = 0;

    if (co_db_table_exists($conn, 'co_client_invoices')) {
        $fin = $conn->prepare("
            SELECT
              COALESCE(SUM(i.total_amount), 0) AS invoiced,
              COALESCE(SUM(COALESCE(a.paid_amount, 0)), 0) AS collected,
              COALESCE(SUM(GREATEST(i.total_amount - COALESCE(a.paid_amount, 0), 0)), 0) AS outstanding,
              COALESCE(SUM(CASE
                WHEN GREATEST(i.total_amount - COALESCE(a.paid_amount, 0), 0) > 0.005
                 AND i.due_date < ? THEN GREATEST(i.total_amount - COALESCE(a.paid_amount, 0), 0)
                ELSE 0 END), 0) AS overdue
            FROM co_client_invoices i
            LEFT JOIN (
                SELECT invoice_id, company_id, SUM(allocated_amount) AS paid_amount
                FROM co_client_payment_allocations
                GROUP BY invoice_id, company_id
            ) a ON a.invoice_id = i.id AND a.company_id = i.company_id
            WHERE i.company_id = ?
              AND i.source_type = 'shop_rental'
              AND i.status <> 'cancelled'
              AND i.source_id IN (SELECT id FROM co_shop_rent_schedules WHERE company_id = ?)
        ");
        $fin->execute([$today, $companyId, $companyId]);
        $f = $fin->fetch(PDO::FETCH_ASSOC) ?: [];
        $invoiced = (float)($f['invoiced'] ?? 0);
        $collected = (float)($f['collected'] ?? 0);
        $outstanding = (float)($f['outstanding'] ?? 0);
        $overdue = (float)($f['overdue'] ?? 0);
    }

    if (co_db_table_exists($conn, 'co_shop_rent_schedules')) {
        $def = $conn->prepare("
            SELECT
              COALESCE((
                SELECT SUM(s.amount) FROM co_shop_rent_schedules s
                JOIN co_shop_rental_contracts c ON c.id = s.contract_id
                WHERE s.company_id = ? AND s.status = 'invoiced' AND c.accrual_deferred_rent = 1
              ), 0)
              -
              COALESCE((
                SELECT SUM(r.amount) FROM co_shop_rent_recognitions r WHERE r.company_id = ?
              ), 0) AS bal,
              COALESCE((
                SELECT SUM(r.amount) FROM co_shop_rent_recognitions r WHERE r.company_id = ?
              ), 0) AS recognized
        ");
        $def->execute([$companyId, $companyId, $companyId]);
        $d = $def->fetch(PDO::FETCH_ASSOC) ?: [];
        $deferred = max(0, (float)($d['bal'] ?? 0));
        $recognized = (float)($d['recognized'] ?? 0);

        $pi = $conn->prepare("
            SELECT COUNT(*) FROM co_shop_rent_schedules
            WHERE company_id = ? AND status = 'pending' AND invoice_id IS NULL
        ");
        $pi->execute([$companyId]);
        $pendingInvoices = (int)$pi->fetchColumn();

        if (co_db_table_exists($conn, 'co_shop_rent_recognitions')) {
            $pr = $conn->prepare("
                SELECT COUNT(*) FROM co_shop_rent_schedules s
                JOIN co_shop_rental_contracts c ON c.id = s.contract_id
                WHERE s.company_id = ? AND c.accrual_deferred_rent = 1 AND s.status = 'invoiced'
                  AND s.period_start <= ?
                  AND COALESCE((
                    SELECT SUM(r.amount) FROM co_shop_rent_recognitions r
                    WHERE r.schedule_id = s.id AND r.company_id = s.company_id
                  ), 0) + 0.005 < s.amount
            ");
            $pr->execute([$companyId, date('Y-m-01')]);
            $pendingRecognition = (int)$pr->fetchColumn();
        }
    }

    if (co_db_table_exists($conn, 'co_shop_rental_contracts')) {
        $dep = $conn->prepare("
            SELECT COALESCE(SUM(deposit_received_amount), 0),
                   COALESCE(SUM(CASE WHEN security_deposit > deposit_received_amount + 0.005 THEN 1 ELSE 0 END), 0)
            FROM co_shop_rental_contracts WHERE company_id = ? AND status IN ('active','draft')
        ");
        $dep->execute([$companyId]);
        $depRow = $dep->fetch(PDO::FETCH_NUM);
        $depositsHeld = (float)($depRow[0] ?? 0);
        $pendingDeposits = (int)($depRow[1] ?? 0);
    }

    if (co_db_table_exists($conn, 'co_client_credit_balances')) {
        $cr = $conn->prepare("
            SELECT COALESCE(SUM(b.balance_aed), 0)
            FROM co_client_credit_balances b
            WHERE b.company_id = ?
              AND b.client_id IN (
                SELECT DISTINCT client_id FROM co_shop_rental_contracts WHERE company_id = ?
              )
        ");
        $cr->execute([$companyId, $companyId]);
        $tenantCredit = (float)$cr->fetchColumn();
    }

    $commission = [
        'invoiced' => 0.0,
        'collected' => 0.0,
        'outstanding' => 0.0,
        'pending_contracts' => 0,
    ];
    if (co_shop_commission_schema_ready($conn) && co_db_table_exists($conn, 'co_client_invoices')) {
        $ci = $conn->prepare("
            SELECT
              COALESCE(SUM(i.total_amount), 0) AS invoiced,
              COALESCE(SUM(COALESCE(a.paid_amount, 0)), 0) AS collected,
              COALESCE(SUM(GREATEST(i.total_amount - COALESCE(a.paid_amount, 0), 0)), 0) AS outstanding
            FROM co_client_invoices i
            LEFT JOIN (
                SELECT invoice_id, company_id, SUM(allocated_amount) AS paid_amount
                FROM co_client_payment_allocations
                GROUP BY invoice_id, company_id
            ) a ON a.invoice_id = i.id AND a.company_id = i.company_id
            WHERE i.company_id = ? AND i.source_type = 'shop_commission' AND i.status <> 'cancelled'
        ");
        $ci->execute([$companyId]);
        $crow = $ci->fetch(PDO::FETCH_ASSOC) ?: [];
        $commission['invoiced'] = (float)($crow['invoiced'] ?? 0);
        $commission['collected'] = (float)($crow['collected'] ?? 0);
        $commission['outstanding'] = (float)($crow['outstanding'] ?? 0);
        $pend = $conn->prepare("
            SELECT COUNT(*) FROM co_shop_rental_contracts
            WHERE company_id = ? AND status = 'active' AND commission_enabled = 1
              AND commission_net_amount > 0 AND commission_invoice_id IS NULL
        ");
        $pend->execute([$companyId]);
        $commission['pending_contracts'] = (int)$pend->fetchColumn();
    }

    $expiring = [];
    if (co_db_table_exists($conn, 'co_shop_rental_contracts')) {
        $es = $conn->prepare("
            SELECT c.id, c.contract_number, c.end_date, c.status, cl.client_name
            FROM co_shop_rental_contracts c
            JOIN co_clients cl ON cl.id = c.client_id
            WHERE c.company_id = ? AND c.status = 'active' AND c.end_date <= ?
            ORDER BY c.end_date ASC
            LIMIT 12
        ");
        $es->execute([$companyId, $soon]);
        $expiring = $es->fetchAll(PDO::FETCH_ASSOC);
        foreach ($expiring as &$row) {
            $row['shops_label'] = co_shop_contract_shops_label($conn, $companyId, (int)$row['id']);
        }
        unset($row);
    }

    return [
        'units' => $units,
        'contracts' => $contracts,
        'finance' => [
            'invoiced' => $invoiced,
            'collected' => $collected,
            'outstanding' => $outstanding,
            'overdue' => $overdue,
            'deferred' => $deferred,
            'recognized' => $recognized,
            'deposits_held' => $depositsHeld,
            'tenant_credit' => $tenantCredit,
        ],
        'pending' => [
            'invoices' => $pendingInvoices,
            'deposits' => $pendingDeposits,
            'recognition' => $pendingRecognition,
            'commission' => $commission['pending_contracts'] ?? 0,
        ],
        'commission' => $commission,
        'expiring' => $expiring,
        'as_of' => $today,
    ];
}
