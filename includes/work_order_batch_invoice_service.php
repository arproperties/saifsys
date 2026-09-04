<?php
/**
 * Hybrid batch invoicing — summary invoice + mandatory per-WO child invoices (Phase 4).
 */

require_once __DIR__ . '/work_order_financial_guard.php';
require_once __DIR__ . '/service_management_settings.php';
require_once __DIR__ . '/ar_helpers.php';

if (!function_exists('sm_hybrid_batch_enabled')) {
    function sm_hybrid_batch_enabled(PDO $conn): bool
    {
        return sm_get_setting($conn, 'sm_hybrid_batch_invoicing', '1') === '1'
            && sm_batch_tables_exist($conn);
    }
}

if (!function_exists('sm_batch_tables_exist')) {
    function sm_batch_tables_exist(PDO $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $st = $conn->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME IN ('sm_invoice_batches', 'sm_invoice_batch_lines')
            ");
            $st->execute();
            $ok = (int)$st->fetchColumn() === 2;
        } catch (Throwable $e) {
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('sm_invoice_is_batch_summary')) {
    function sm_invoice_is_batch_summary(PDO $conn, int $invoiceId): bool
    {
        if (!sm4_invoice_has_batch_flag($conn)) {
            return false;
        }
        $st = $conn->prepare('SELECT is_batch_summary FROM invoices WHERE id = ?');
        $st->execute([$invoiceId]);
        return (int)$st->fetchColumn() === 1;
    }
}

if (!function_exists('sm4_invoice_has_batch_flag')) {
    function sm4_invoice_has_batch_flag(PDO $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $st = $conn->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'is_batch_summary'
            ");
            $st->execute();
            $ok = (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('sm_batch_order_batched_subquery')) {
    /** Orders already on a non-void batch. */
    function sm_batch_order_batched_subquery(): string
    {
        return "
            SELECT bl.order_id
            FROM sm_invoice_batch_lines bl
            INNER JOIN sm_invoice_batches b ON b.id = bl.batch_id
            WHERE b.status <> 'void'
        ";
    }
}

if (!function_exists('sm_batch_eligible_sql_fragment')) {
    /**
     * @return array{join:string, where:string}
     */
    function sm_batch_eligible_sql_fragment(PDO $conn, bool $includeBatched = false): array
    {
        $join = "
            INNER JOIN invoices ci ON ci.order_id = mo.id AND ci.status <> 'void'
        ";
        $where = "
            AND COALESCE(mo.status,'') <> 'cancelled'
        ";
        if (wo_column_exists($conn, 'is_finalized')) {
            $where .= " AND COALESCE(mo.is_finalized, 0) = 1";
        } else {
            $where .= " AND mo.invoice_id IS NOT NULL AND mo.invoice_id = ci.id";
        }
        if (!$includeBatched && sm_batch_tables_exist($conn)) {
            $where .= " AND mo.id NOT IN (" . sm_batch_order_batched_subquery() . ")";
        }
        return ['join' => $join, 'where' => $where];
    }
}

if (!function_exists('sm_fetch_batch_eligible_orders')) {
    function sm_fetch_batch_eligible_orders(
        PDO $conn,
        int $clientId,
        string $rangeStart,
        string $rangeEnd,
        array $orderIds = [],
        bool $includeBatched = false
    ): array {
        $frag = sm_batch_eligible_sql_fragment($conn, $includeBatched);
        $params = [];
        $where = "mo.client_id = ? AND mo.svc_date_calc BETWEEN ? AND ? " . $frag['where'];
        $params[] = $clientId;
        $params[] = $rangeStart;
        $params[] = $rangeEnd;

        if ($orderIds) {
            $ph = implode(',', array_fill(0, count($orderIds), '?'));
            $where .= " AND mo.id IN ($ph)";
            foreach ($orderIds as $oid) {
                $params[] = (int)$oid;
            }
        }

        $sql = "
            SELECT
                mo.id,
                mo.svc_date_calc,
                mo.start_time,
                mo.end_time,
                mo.worker_name,
                mo.hours,
                mo.hourly_rate,
                mo.total,
                mo.vat_rate,
                mo.vat_amount,
                mo.grand_total,
                mo.invoice_id,
                mo.is_finalized,
                ci.id AS child_invoice_id,
                ci.invoice_no AS child_invoice_no,
                ci.subtotal AS child_subtotal,
                ci.vat_amount AS child_vat,
                ci.total AS child_total
            FROM make_order mo
            {$frag['join']}
            WHERE $where
            ORDER BY mo.svc_date_calc, mo.id
        ";
        $st = $conn->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('sm_next_batch_summary_invoice_no')) {
    function sm_next_batch_summary_invoice_no(PDO $conn): string
    {
        $year = date('Y');
        $st = $conn->prepare("
            SELECT MAX(CAST(SUBSTRING_INDEX(invoice_no,'-',-1) AS UNSIGNED))
            FROM invoices WHERE invoice_no LIKE ?
        ");
        $st->execute(["BINV-$year-%"]);
        $next = (int)($st->fetchColumn() ?: 0) + 1;
        return sprintf('BINV-%s-%05d', $year, $next);
    }
}

if (!function_exists('sm_generate_hybrid_batch_invoice')) {
    /**
     * Create batch summary invoice linked to existing per-WO child invoices (no GL duplicate).
     * @return int summary invoice id
     */
    function sm_generate_hybrid_batch_invoice(
        PDO $conn,
        int $clientId,
        string $rangeStart,
        string $rangeEnd,
        array $orderIds = [],
        string $itemization = 'per_order',
        ?int $actorUserId = null
    ): int {
        if (!sm_batch_tables_exist($conn)) {
            throw new RuntimeException('Hybrid batch tables not installed. Run tools/sm_apply_phase4_schema.php');
        }

        $orders = sm_fetch_batch_eligible_orders($conn, $clientId, $rangeStart, $rangeEnd, $orderIds, false);
        if (!$orders) {
            throw new RuntimeException('No finalized work orders with child invoices ready for batch summary.');
        }

        $cst = $conn->prepare('SELECT client_name, terms, default_vat_rate FROM client WHERE id = ?');
        $cst->execute([$clientId]);
        $client = $cst->fetch(PDO::FETCH_ASSOC);
        if (!$client) {
            throw new RuntimeException('Client not found.');
        }

        $sumSub = $sumVat = $sumTot = $sumHours = 0.0;
        foreach ($orders as $o) {
            $sumSub += (float)($o['child_subtotal'] ?? $o['total']);
            $sumVat += (float)($o['child_vat'] ?? $o['vat_amount']);
            $sumTot += (float)($o['child_total'] ?? $o['grand_total']);
            $sumHours += (float)$o['hours'];
        }
        $sumSub = round($sumSub, 2);
        $sumVat = round($sumVat, 2);
        $sumTot = round($sumTot, 2);
        $sumHours = round($sumHours, 2);

        $issueDate = date('Y-m-d');
        $dueDate = ar_compute_due_date($issueDate, $client['terms'] ?? null);
        $invoiceNo = sm_next_batch_summary_invoice_no($conn);
        $actorUserId = $actorUserId ?? (function_exists('current_user_id') ? current_user_id() : null);
        $vatRate = (float)($client['default_vat_rate'] ?? 5);

        $conn->beginTransaction();
        try {
            $batchFlagSql = sm4_invoice_has_batch_flag($conn) ? ', is_batch_summary' : '';
            $batchFlagVal = sm4_invoice_has_batch_flag($conn) ? ', 1' : '';
            $ins = $conn->prepare("
                INSERT INTO invoices
                  (client_id, invoice_no, issue_date, due_date, range_start, range_end,
                   subtotal, vat_rate, vat_amount, total, balance_due,
                   status, notes, created_at, created_by{$batchFlagSql})
                VALUES
                  (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'issued', ?, NOW(), ?{$batchFlagVal})
            ");
            $notes = "Batch summary {$rangeStart} to {$rangeEnd} — GL posted on child invoices only.";
            $ins->execute([
                $clientId,
                $invoiceNo,
                $issueDate,
                $dueDate,
                $rangeStart,
                $rangeEnd,
                $sumSub,
                $vatRate,
                $sumVat,
                $sumTot,
                $sumTot,
                $notes,
                $actorUserId,
            ]);
            $summaryInvoiceId = (int)$conn->lastInsertId();

            $batchIns = $conn->prepare("
                INSERT INTO sm_invoice_batches
                  (client_id, batch_invoice_id, range_start, range_end, child_count,
                   subtotal, vat_amount, grand_total, status, notes, created_by, issued_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'issued', ?, ?, NOW())
            ");
            $batchIns->execute([
                $clientId,
                $summaryInvoiceId,
                $rangeStart,
                $rangeEnd,
                count($orders),
                $sumSub,
                $sumVat,
                $sumTot,
                "Hybrid batch — {$invoiceNo}",
                $actorUserId,
            ]);
            $batchId = (int)$conn->lastInsertId();

            $lineIns = $conn->prepare("
                INSERT INTO sm_invoice_batch_lines
                  (batch_id, order_id, child_invoice_id, line_subtotal, line_vat, line_total)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $itemIns = $conn->prepare("
                INSERT INTO invoice_items
                  (invoice_id, order_id, line_no, description, qty, unit, unit_price,
                   line_subtotal, vat_rate, line_vat, line_total)
                VALUES (?, ?, ?, ?, ?, 'hr', ?, ?, ?, ?, ?)
            ");

            $lineNo = 1;
            if ($itemization === 'single_line') {
                $desc = "Cleaning services {$rangeStart} – {$rangeEnd} (" . count($orders) . " visits, {$sumHours}h)";
                $unitPrice = $sumHours > 0 ? round($sumSub / $sumHours, 2) : 0;
                $itemIns->execute([
                    $summaryInvoiceId,
                    null,
                    1,
                    $desc,
                    $sumHours,
                    $unitPrice,
                    $sumSub,
                    $vatRate,
                    $sumVat,
                    $sumTot,
                ]);
            }

            foreach ($orders as $o) {
                $childId = (int)$o['child_invoice_id'];
                $lineSub = round((float)($o['child_subtotal'] ?? $o['total']), 2);
                $lineVat = round((float)($o['child_vat'] ?? $o['vat_amount']), 2);
                $lineTot = round((float)($o['child_total'] ?? $o['grand_total']), 2);

                $lineIns->execute([$batchId, (int)$o['id'], $childId, $lineSub, $lineVat, $lineTot]);

                if ($itemization !== 'single_line') {
                    $desc = sprintf(
                        'WO #%d — %s %s–%s (%s) → %s',
                        (int)$o['id'],
                        (string)$o['svc_date_calc'],
                        substr((string)$o['start_time'], 0, 5),
                        substr((string)$o['end_time'], 0, 5),
                        (string)$o['worker_name'],
                        (string)($o['child_invoice_no'] ?? 'INV')
                    );
                    $itemIns->execute([
                        $summaryInvoiceId,
                        (int)$o['id'],
                        $lineNo++,
                        $desc,
                        (float)$o['hours'],
                        (float)$o['hourly_rate'],
                        $lineSub,
                        (float)($o['vat_rate'] ?? $vatRate),
                        $lineVat,
                        $lineTot,
                    ]);
                }
            }

            require_once __DIR__ . '/AuditService.php';
            AuditService::logCreate('sm_invoice_batches', $batchId, [
                'batch_invoice_id' => $summaryInvoiceId,
                'client_id' => $clientId,
                'child_count' => count($orders),
                'grand_total' => $sumTot,
            ], "Hybrid batch {$invoiceNo} for client #{$clientId} ({$rangeStart}–{$rangeEnd})");

            if (class_exists('ServiceAccountingService')) {
                require_once __DIR__ . '/service_accounting_service.php';
                (new ServiceAccountingService($conn))->invalidateFinancialCache();
            }

            $conn->commit();
            return $summaryInvoiceId;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('sm_get_batch_for_summary_invoice')) {
    function sm_get_batch_for_summary_invoice(PDO $conn, int $summaryInvoiceId): ?array
    {
        if (!sm_batch_tables_exist($conn)) {
            return null;
        }
        $st = $conn->prepare('SELECT * FROM sm_invoice_batches WHERE batch_invoice_id = ? LIMIT 1');
        $st->execute([$summaryInvoiceId]);
        $batch = $st->fetch(PDO::FETCH_ASSOC);
        if (!$batch) {
            return null;
        }
        $lines = $conn->prepare("
            SELECT bl.*, mo.svc_date_calc, mo.worker_name, ci.invoice_no AS child_invoice_no
            FROM sm_invoice_batch_lines bl
            INNER JOIN make_order mo ON mo.id = bl.order_id
            INNER JOIN invoices ci ON ci.id = bl.child_invoice_id
            WHERE bl.batch_id = ?
            ORDER BY mo.svc_date_calc, bl.order_id
        ");
        $lines->execute([(int)$batch['id']]);
        $batch['lines'] = $lines->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $batch;
    }
}

if (!function_exists('sm_batch_window_totals')) {
    /** Totals for billing queue / batch preview (hybrid-eligible orders only). */
    function sm_batch_window_totals(PDO $conn, int $clientId, string $from, string $to): array
    {
        $orders = sm_fetch_batch_eligible_orders($conn, $clientId, $from, $to, [], false);
        $out = ['orders_cnt' => 0, 'hours' => 0.0, 'subtotal' => 0.0, 'vat' => 0.0, 'grand_total' => 0.0];
        foreach ($orders as $o) {
            $out['orders_cnt']++;
            $out['hours'] += (float)$o['hours'];
            $out['subtotal'] += (float)($o['child_subtotal'] ?? $o['total']);
            $out['vat'] += (float)($o['child_vat'] ?? $o['vat_amount']);
            $out['grand_total'] += (float)($o['child_total'] ?? $o['grand_total']);
        }
        foreach (['hours', 'subtotal', 'vat', 'grand_total'] as $k) {
            $out[$k] = round((float)$out[$k], 2);
        }
        return $out;
    }
}

if (!function_exists('sm_batch_client_unbilled_bounds')) {
    /** Date span of finalized WOs with child invoices not yet on a BINV summary. */
    function sm_batch_client_unbilled_bounds(PDO $conn, int $clientId): ?array
    {
        $frag = sm_batch_eligible_sql_fragment($conn, false);
        $st = $conn->prepare("
            SELECT
              MIN(mo.svc_date_calc) AS first_date,
              MAX(mo.svc_date_calc) AS last_date
            FROM make_order mo
            {$frag['join']}
            WHERE mo.client_id = ?
            {$frag['where']}
        ");
        $st->execute([$clientId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || empty($r['last_date'])) {
            return null;
        }
        return [
            'first_date' => (string)$r['first_date'],
            'last_date' => (string)$r['last_date'],
        ];
    }
}

if (!function_exists('sm_compute_batch_next_range')) {
    /**
     * Next billable window for hybrid batch (uses batch-eligible orders, not legacy uninvoiced view).
     * @return array{from:string,to:string}|null
     */
    function sm_compute_batch_next_range(PDO $conn, int $clientId, ?string $forceLastDate = null): ?array
    {
        $bounds = sm_batch_client_unbilled_bounds($conn, $clientId);
        if (!$bounds) {
            return null;
        }

        $q = $conn->prepare("
            SELECT c.payment AS cadence, cb.last_billed_to
            FROM client c
            LEFT JOIN client_billing cb ON cb.client_id = c.id
            WHERE c.id = ?
        ");
        $q->execute([$clientId]);
        $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];

        $cad = (string)($r['cadence'] ?? 'M');
        $lastBilledTo = !empty($r['last_billed_to']) ? (string)$r['last_billed_to'] : null;
        $firstUnbilled = $bounds['first_date'];
        $overallLast = $forceLastDate ?: $bounds['last_date'];

        $start = $lastBilledTo
            ? date('Y-m-d', strtotime($lastBilledTo . ' +1 day'))
            : $firstUnbilled;

        // Stale last_billed_to can hide clients that still have batch-eligible orders.
        if ($start > $overallLast) {
            $start = $firstUnbilled;
        }

        if ($start > $overallLast) {
            return null;
        }

        for ($i = 0; $i < 24; $i++) {
            if ($cad === 'D') {
                $end = $start;
            } elseif ($cad === 'W') {
                $weekEnd = new DateTime($start);
                $weekEnd->modify('next sunday');
                $end = $weekEnd->format('Y-m-d');
            } elseif ($cad === 'Bi-W') {
                $end = date('Y-m-d', strtotime($start . ' +13 day'));
            } else {
                $end = (new DateTime($start))->modify('last day of this month')->format('Y-m-d');
            }

            if ($end > $overallLast) {
                $end = $overallLast;
            }
            if ($end < $start) {
                return null;
            }

            $win = sm_batch_window_totals($conn, $clientId, $start, $end);
            if (($win['orders_cnt'] ?? 0) > 0 && ($win['grand_total'] ?? 0) > 0.0001) {
                return ['from' => $start, 'to' => $end];
            }

            $start = date('Y-m-d', strtotime($end . ' +1 day'));
            if ($start > $overallLast) {
                return null;
            }
        }

        return null;
    }
}
