<?php
/**
 * Construction Shop Rental Phase 2B — management analytics, forecasting, report queries.
 * Read-only. Does NOT post or change accounting behaviour.
 *
 * Loaded only via construction_shop_rental_helpers.php (after multishop/commission).
 * Do not require helpers.php here — circular dependency.
 */

/** Batch shop labels for many contracts (avoids N+1). @return array<int,string> */
function co_shop_batch_shops_labels(PDO $conn, int $companyId, array $contractIds): array {
    $out = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $contractIds))));
    if (!$ids) {
        return $out;
    }
    if (!co_shop_phase175_schema_ready($conn)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("
            SELECT c.id, u.shop_number
            FROM co_shop_rental_contracts c
            JOIN co_shop_units u ON u.id = c.shop_unit_id
            WHERE c.company_id = ? AND c.id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$companyId], $ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['id']] = (string)$row['shop_number'];
        }
        return $out;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
        SELECT cs.contract_id,
               GROUP_CONCAT(u.shop_number ORDER BY cs.is_primary DESC, u.shop_number SEPARATOR ', ') AS shops
        FROM co_shop_rental_contract_shops cs
        JOIN co_shop_units u ON u.id = cs.shop_unit_id
        WHERE cs.company_id = ? AND cs.contract_id IN ($placeholders)
        GROUP BY cs.contract_id
    ");
    $stmt->execute(array_merge([$companyId], $ids));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['contract_id']] = (string)$row['shops'];
    }
    return $out;
}

function co_shop_invoice_alloc_join(): string {
    return "
        LEFT JOIN (
            SELECT invoice_id, company_id, SUM(allocated_amount) AS paid_amount
            FROM co_client_payment_allocations
            GROUP BY invoice_id, company_id
        ) a ON a.invoice_id = i.id AND a.company_id = i.company_id
    ";
}

/**
 * Penalty income KPIs (shop_termination_penalty → 4150).
 */
function co_shop_penalty_income_snapshot(PDO $conn, int $companyId): array {
    $out = ['invoiced' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0, 'count' => 0];
    if (!co_db_table_exists($conn, 'co_client_invoices')) {
        return $out;
    }
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS n,
               COALESCE(SUM(i.total_amount), 0) AS invoiced,
               COALESCE(SUM(COALESCE(a.paid_amount, 0)), 0) AS collected,
               COALESCE(SUM(GREATEST(i.total_amount - COALESCE(a.paid_amount, 0), 0)), 0) AS outstanding
        FROM co_client_invoices i
        " . co_shop_invoice_alloc_join() . "
        WHERE i.company_id = ? AND i.source_type = 'shop_termination_penalty' AND i.status <> 'cancelled'
    ");
    $stmt->execute([$companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $out['count'] = (int)($row['n'] ?? 0);
    $out['invoiced'] = (float)($row['invoiced'] ?? 0);
    $out['collected'] = (float)($row['collected'] ?? 0);
    $out['outstanding'] = (float)($row['outstanding'] ?? 0);
    return $out;
}

function co_shop_monthly_recognized(PDO $conn, int $companyId, ?string $ym = null): float {
    if (!co_db_table_exists($conn, 'co_shop_rent_recognitions')) {
        return 0.0;
    }
    $ym = $ym ?: date('Y-m');
    $from = $ym . '-01';
    $to = date('Y-m-t', strtotime($from));
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM co_shop_rent_recognitions
        WHERE company_id = ? AND recognition_month BETWEEN ? AND ?
    ");
    $stmt->execute([$companyId, $from, $to]);
    return (float)$stmt->fetchColumn();
}

/**
 * Last N months trends for charts (read-only).
 * @return array{labels:list<string>,revenue:list<float>,collections:list<float>,recognized:list<float>,commission:list<float>,penalty:list<float>,outstanding:list<float>}
 */
function co_shop_financial_trends(PDO $conn, int $companyId, int $months = 12): array {
    $months = max(3, min(24, $months));
    $labels = [];
    $revenue = $collections = $recognized = $commission = $penalty = $outstanding = [];
    $alloc = co_shop_invoice_alloc_join();
    $rangeFrom = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
    $rangeTo = date('Y-m-t');

    // Seed months
    for ($i = $months - 1; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        $labels[] = $ym;
        $revenue[$ym] = 0.0;
        $collections[$ym] = 0.0;
        $recognized[$ym] = 0.0;
        $commission[$ym] = 0.0;
        $penalty[$ym] = 0.0;
        $outstanding[$ym] = 0.0;
    }

    // Invoiced by source_type × month (1 query)
    try {
        $stmt = $conn->prepare("
            SELECT DATE_FORMAT(i.invoice_date, '%Y-%m') AS ym, i.source_type, COALESCE(SUM(i.total_amount), 0) AS amt
            FROM co_client_invoices i
            WHERE i.company_id = ?
              AND i.source_type IN ('shop_rental','shop_commission','shop_termination_penalty')
              AND i.status <> 'cancelled'
              AND i.invoice_date BETWEEN ? AND ?
            GROUP BY ym, i.source_type
        ");
        $stmt->execute([$companyId, $rangeFrom, $rangeTo]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ym = (string)$row['ym'];
            if (!isset($revenue[$ym])) {
                continue;
            }
            $amt = round((float)$row['amt'], 2);
            if ($row['source_type'] === 'shop_rental') {
                $revenue[$ym] = $amt;
            } elseif ($row['source_type'] === 'shop_commission') {
                $commission[$ym] = $amt;
            } else {
                $penalty[$ym] = $amt;
            }
        }
    } catch (Throwable $e) {
        // leave zeros
    }

    // Collections by payment month (1 query)
    try {
        $stmt = $conn->prepare("
            SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS ym, COALESCE(SUM(a.allocated_amount), 0) AS amt
            FROM co_client_payment_allocations a
            JOIN co_client_payments p ON p.id = a.payment_id AND p.company_id = a.company_id
            JOIN co_client_invoices i ON i.id = a.invoice_id AND i.company_id = a.company_id
            WHERE a.company_id = ?
              AND i.source_type IN ('shop_rental','shop_commission','shop_termination_penalty')
              AND i.status <> 'cancelled'
              AND p.payment_date BETWEEN ? AND ?
            GROUP BY ym
        ");
        $stmt->execute([$companyId, $rangeFrom, $rangeTo]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ym = (string)$row['ym'];
            if (isset($collections[$ym])) {
                $collections[$ym] = round((float)$row['amt'], 2);
            }
        }
    } catch (Throwable $e) {
        // leave zeros
    }

    // Recognitions by month (1 query)
    if (co_db_table_exists($conn, 'co_shop_rent_recognitions')) {
        try {
            $stmt = $conn->prepare("
                SELECT DATE_FORMAT(recognition_month, '%Y-%m') AS ym, COALESCE(SUM(amount), 0) AS amt
                FROM co_shop_rent_recognitions
                WHERE company_id = ? AND recognition_month BETWEEN ? AND ?
                GROUP BY ym
            ");
            $stmt->execute([$companyId, $rangeFrom, $rangeTo]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $ym = (string)$row['ym'];
                if (isset($recognized[$ym])) {
                    $recognized[$ym] = round((float)$row['amt'], 2);
                }
            }
        } catch (Throwable $e) {
            // leave zeros
        }
    }

    // Outstanding at each month-end still needs per-month snapshot (open balance as-of)
    $stmtOs = $conn->prepare("
        SELECT COALESCE(SUM(GREATEST(i.total_amount - COALESCE(a.paid_amount, 0), 0)), 0)
        FROM co_client_invoices i
        $alloc
        WHERE i.company_id = ? AND i.source_type = 'shop_rental' AND i.status <> 'cancelled'
          AND i.invoice_date <= ?
    ");
    foreach ($labels as $ym) {
        $to = date('Y-m-t', strtotime($ym . '-01'));
        $stmtOs->execute([$companyId, $to]);
        $outstanding[$ym] = round((float)$stmtOs->fetchColumn(), 2);
    }

    return [
        'labels' => $labels,
        'revenue' => array_values(array_map(static fn($ym) => $revenue[$ym], $labels)),
        'collections' => array_values(array_map(static fn($ym) => $collections[$ym], $labels)),
        'recognized' => array_values(array_map(static fn($ym) => $recognized[$ym], $labels)),
        'commission' => array_values(array_map(static fn($ym) => $commission[$ym], $labels)),
        'penalty' => array_values(array_map(static fn($ym) => $penalty[$ym], $labels)),
        'outstanding' => array_values(array_map(static fn($ym) => $outstanding[$ym], $labels)),
    ];
}

/**
 * Forward-looking forecast (operational, not GL).
 */
function co_shop_management_forecast(PDO $conn, int $companyId): array {
    $today = date('Y-m-d');
    $d30 = date('Y-m-d', strtotime('+30 days'));
    $d60 = date('Y-m-d', strtotime('+60 days'));
    $d90 = date('Y-m-d', strtotime('+90 days'));

    $collect = static function (PDO $conn, int $companyId, string $to) use ($today): float {
        // Expected collections ≈ open rent/commission/penalty invoices due by $to
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(GREATEST(i.total_amount - COALESCE(a.paid_amount, 0), 0)), 0)
            FROM co_client_invoices i
            " . co_shop_invoice_alloc_join() . "
            WHERE i.company_id = ?
              AND i.source_type IN ('shop_rental','shop_commission','shop_termination_penalty')
              AND i.status <> 'cancelled'
              AND GREATEST(i.total_amount - COALESCE(a.paid_amount, 0), 0) > 0.005
              AND COALESCE(i.due_date, i.invoice_date) BETWEEN ? AND ?
        ");
        $stmt->execute([$companyId, $today, $to]);
        return round((float)$stmt->fetchColumn(), 2);
    };

    $pendingSched = ['amount' => 0.0, 'vat' => 0.0, 'count' => 0];
    if (co_db_table_exists($conn, 'co_shop_rent_schedules')) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS n,
                   COALESCE(SUM(COALESCE(net_amount, amount)), 0) AS net,
                   COALESCE(SUM(vat_amount), 0) AS vat
            FROM co_shop_rent_schedules
            WHERE company_id = ? AND status = 'pending' AND invoice_id IS NULL
              AND due_date BETWEEN ? AND ?
        ");
        $stmt->execute([$companyId, $today, $d90]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $pendingSched = [
            'count' => (int)($row['n'] ?? 0),
            'amount' => round((float)($row['net'] ?? 0), 2),
            'vat' => round((float)($row['vat'] ?? 0), 2),
        ];
    }

    $expiries = ['d30' => 0, 'd60' => 0, 'd90' => 0];
    $renewals = ['drafts' => 0, 'expiring_active' => 0];
    $depositRefunds = 0.0;
    $expectedCommission = 0.0;

    if (co_db_table_exists($conn, 'co_shop_rental_contracts')) {
        foreach (['d30' => $d30, 'd60' => $d60, 'd90' => $d90] as $k => $to) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM co_shop_rental_contracts
                WHERE company_id = ? AND status = 'active' AND end_date BETWEEN ? AND ?
            ");
            $stmt->execute([$companyId, $today, $to]);
            $expiries[$k] = (int)$stmt->fetchColumn();
        }
        if (co_db_column_exists($conn, 'co_shop_rental_contracts', 'parent_contract_id')) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM co_shop_rental_contracts
                WHERE company_id = ? AND status = 'draft' AND parent_contract_id IS NOT NULL
            ");
            $stmt->execute([$companyId]);
            $renewals['drafts'] = (int)$stmt->fetchColumn();
        }
        $renewals['expiring_active'] = $expiries['d90'];

        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(deposit_received_amount), 0)
            FROM co_shop_rental_contracts
            WHERE company_id = ? AND status = 'active' AND end_date BETWEEN ? AND ?
              AND deposit_received_amount > 0.005
        ");
        $stmt->execute([$companyId, $today, $d90]);
        $depositRefunds = round((float)$stmt->fetchColumn(), 2);

        if (co_shop_commission_schema_ready($conn)) {
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(
                    CASE WHEN commission_vat_enabled = 1
                         THEN commission_net_amount * (1 + commission_vat_rate / 100)
                         ELSE commission_net_amount END
                ), 0)
                FROM co_shop_rental_contracts
                WHERE company_id = ? AND status = 'active' AND commission_enabled = 1
                  AND commission_net_amount > 0 AND commission_invoice_id IS NULL
            ");
            $stmt->execute([$companyId]);
            $expectedCommission = round((float)$stmt->fetchColumn(), 2);
        }
    }

    return [
        'collections_30' => $collect($conn, $companyId, $d30),
        'collections_60' => $collect($conn, $companyId, $d60),
        'collections_90' => $collect($conn, $companyId, $d90),
        'expected_revenue_90' => $pendingSched['amount'],
        'expected_vat_90' => $pendingSched['vat'],
        'pending_schedules_90' => $pendingSched['count'],
        'expiries' => $expiries,
        'renewals' => $renewals,
        'expected_deposit_refunds_90' => $depositRefunds,
        'expected_commission' => $expectedCommission,
        'as_of' => $today,
    ];
}

/**
 * Enrich control-center snapshot with Phase 2B KPIs (mutates/returns extended array).
 */
function co_shop_control_center_snapshot_v2(PDO $conn, int $companyId): array {
    $snap = co_shop_control_center_snapshot($conn, $companyId);
    $u = $snap['units'];
    $leasable = max(0, (int)$u['occupied'] + (int)$u['available']);
    $snap['units']['occupancy_pct'] = $leasable > 0
        ? round(100 * (int)$u['occupied'] / $leasable, 1)
        : 0.0;
    $snap['finance']['recognized_lifetime'] = (float)($snap['finance']['recognized'] ?? 0);
    $snap['finance']['recognized_mtd'] = co_shop_monthly_recognized($conn, $companyId);
    $snap['penalty'] = co_shop_penalty_income_snapshot($conn, $companyId);
    $snap['trends'] = co_shop_financial_trends($conn, $companyId, 12);
    $snap['forecast'] = co_shop_management_forecast($conn, $companyId);

    // Batch shop labels for expiring list
    if (!empty($snap['expiring'])) {
        $ids = array_map(static fn($r) => (int)$r['id'], $snap['expiring']);
        $labels = co_shop_batch_shops_labels($conn, $companyId, $ids);
        foreach ($snap['expiring'] as &$row) {
            $row['shops_label'] = $labels[(int)$row['id']] ?? ($row['shops_label'] ?? '');
        }
        unset($row);
    }

    // Collection performance (lifetime rent)
    $inv = (float)($snap['finance']['invoiced'] ?? 0);
    $col = (float)($snap['finance']['collected'] ?? 0);
    $snap['finance']['collection_rate_pct'] = $inv > 0.005 ? round(100 * $col / $inv, 1) : 0.0;

    return $snap;
}
