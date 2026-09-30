<?php
/**
 * A booking's total and open balance, worked out the way the booking page
 * shows them, so the calendar, dashboard and lists never disagree with it.
 *
 * ars_bookings.balance_due is only a cache. ars_recalc_booking_totals()
 * rebuilds it from nightly charges, so it misses extension invoices and
 * ignores a Total amount the office typed on the Payments table. The booking
 * page reads, in order, and the last one that applies wins:
 *   1. the saved booking figures,
 *   2. the invoices less credit notes, once any invoice exists,
 *   3. the Payments table, once a Total amount is typed on any row.
 */

require_once __DIR__ . '/ars_helpers.php';
require_once __DIR__ . '/ars_payment_plan.php';

/**
 * Roll up a booking's financial documents. Drafts, voided and reversed
 * documents are skipped; a credit note reduces both the invoiced total and
 * the open balance.
 */
function ars_booking_docs_rollup(array $docs): array {
    $invoiced = 0.0;
    $credited = 0.0;
    $openBalance = 0.0;
    $invoiceCount = 0;
    $byType = ['original_invoice' => 0.0, 'extension_invoice' => 0.0, 'other_invoice' => 0.0];
    $extensionCount = 0;
    foreach ($docs as $fd) {
        $status = strtolower((string)($fd['status'] ?? ''));
        if (in_array($status, ['draft', 'voided', 'reversed'], true)) {
            continue;
        }
        $type = (string)($fd['document_type'] ?? '');
        $total = (float)($fd['total_amount'] ?? 0);
        $balance = (float)($fd['balance_due'] ?? 0);
        if ($type === 'credit_note') {
            $credited += $total;
            $openBalance -= $balance;
            continue;
        }
        $invoiced += $total;
        $openBalance += $balance;
        $invoiceCount++;
        if ($type === 'original_invoice' || $type === 'extension_invoice') {
            $byType[$type] += $total;
            if ($type === 'extension_invoice') {
                $extensionCount++;
            }
        } else {
            $byType['other_invoice'] += $total;
        }
    }
    foreach ($byType as $k => $v) {
        $byType[$k] = round($v, 2);
    }
    return [
        'invoiced' => round($invoiced, 2),
        'credited' => round($credited, 2),
        'net_invoiced' => round($invoiced - $credited, 2),
        'open_balance' => round($openBalance, 2),
        'invoice_count' => $invoiceCount,
        'by_type' => $byType,
        'extension_count' => $extensionCount,
    ];
}

/**
 * Walk the Payments table rows (ordered by payment_date, id) the way the
 * booking page draws them. Two shapes, because a typed total and an invoiced
 * total mean different things:
 *
 *   Total amount typed by hand -> each row is its own line for one period, and
 *   what it leaves unpaid carries down to the next row:
 *       outstanding = previous outstanding + (this Total - this Received)
 *   So 470 still owed, then a 910 line with 500 received, shows 880.
 *
 *   No total typed -> the row falls back to the booking's invoiced total, so
 *   the rows are instalments against one figure:
 *       outstanding = invoiced total - everything received down to this row
 *   Carrying shortfalls forward there would add the same total in again on
 *   every row.
 *
 * It deliberately does NOT replay invoices by date. Payments are allocated
 * when recorded, not according to document_date, so a backdated payment
 * legitimately settles invoices raised after its own date.
 *
 * Either way the figure floors at zero, and what falls below becomes credit.
 */
function ars_payment_rows_walk(array $payments, float $stayTotal): array {
    $balanceAfter = [];
    $creditFrom = [];
    $rowTotal = [];
    $runningReceived = 0.0;
    $priorCredit = 0.0;
    $prevOutstanding = 0.0;
    $received = 0.0;
    $hasManualTotal = false;
    foreach ($payments as $pm) {
        $id = (int)$pm['id'];
        $pmReceived = (float)$pm['amount'];
        $manual = !(($pm['total_amount'] ?? null) === null || $pm['total_amount'] === '');
        $pmTotal = $manual ? (float)$pm['total_amount'] : $stayTotal;
        $rowTotal[$id] = $pmTotal;
        $received += $pmReceived;
        $runningReceived += $pmReceived;

        if ($manual) {
            $hasManualTotal = true;
            $carried = round($prevOutstanding + ($pmTotal - $pmReceived), 2);
            $balanceAfter[$id] = max(0.0, $carried);
            $creditFrom[$id] = max(0.0, round(-$carried, 2));
            $prevOutstanding = max(0.0, $carried);
            continue;
        }

        $creditToDate = max(0.0, round($runningReceived - $pmTotal, 2));
        $balanceAfter[$id] = max(0.0, round($pmTotal - $runningReceived, 2));
        $creditFrom[$id] = round($creditToDate - $priorCredit, 2);
        $priorCredit = $creditToDate;
        $prevOutstanding = $balanceAfter[$id];
    }
    return [
        'balance_after' => $balanceAfter,
        'credit_from' => $creditFrom,
        'row_total' => $rowTotal,
        'received' => round($received, 2),
        'has_manual_total' => $hasManualTotal,
        'outstanding' => $balanceAfter ? (float)end($balanceAfter) : 0.0,
    ];
}

/**
 * Paid / partial / unpaid from the figures actually shown. Statuses the
 * figures cannot express (refunded, failed) are kept as saved.
 */
function ars_booking_payment_status_from(float $total, float $balance, float $received, string $saved): string {
    if (!in_array($saved, ['', 'unpaid', 'partial', 'paid'], true)) {
        return $saved;
    }
    if ($balance <= 0.009 && $total > 0.009) {
        return 'paid';
    }
    return $received > 0.009 ? 'partial' : 'unpaid';
}

/**
 * Figures for many bookings at once, keyed by booking id:
 * total, balance, received, source (booking | invoices | payments).
 * $rows need id, total_amount, balance_due and paid_amount.
 */
function ars_booking_balances(PDO $conn, int $companyId, array $rows): array {
    $out = [];
    $ids = [];
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $ids[] = $id;
        $out[$id] = [
            'total' => round((float)($r['total_amount'] ?? 0), 2),
            'balance' => round((float)($r['balance_due'] ?? 0), 2),
            'received' => round((float)($r['paid_amount'] ?? 0), 2),
            'source' => 'booking',
        ];
    }
    $ids = array_values(array_unique($ids));
    if (!$ids || $companyId <= 0) {
        return $out;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));

    // Not ars_financial_adapter_tables_ready(): that file drags the accounting
    // engine into every list page. A company without the adapter simply has
    // no rows here.
    $docsByBooking = [];
    try {
        $stmt = $conn->prepare("
            SELECT booking_id, document_type, status, total_amount, balance_due
            FROM ars_financial_documents
            WHERE company_id = ? AND booking_id IN ($in)
        ");
        $stmt->execute(array_merge([$companyId], $ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $docsByBooking[(int)$d['booking_id']][] = $d;
        }
    } catch (Throwable $e) {
        error_log('ARS booking balances (documents): ' . $e->getMessage());
    }
    foreach ($docsByBooking as $bid => $docs) {
        $roll = ars_booking_docs_rollup($docs);
        if ($roll['invoice_count'] > 0) {
            $out[$bid]['total'] = $roll['net_invoiced'];
            $out[$bid]['balance'] = $roll['open_balance'];
            $out[$bid]['received'] = max(0.0, round($roll['net_invoiced'] - $roll['open_balance'], 2));
            $out[$bid]['source'] = 'invoices';
        }
    }

    ars_ensure_payment_total_column($conn);
    $paymentsByBooking = [];
    try {
        $stmt = $conn->prepare("
            SELECT id, booking_id, amount, total_amount
            FROM ars_booking_payments
            WHERE booking_id IN ($in)
            ORDER BY booking_id, payment_date, id
        ");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $paymentsByBooking[(int)$p['booking_id']][] = $p;
        }
    } catch (Throwable $e) {
        error_log('ARS booking balances (payments): ' . $e->getMessage());
    }
    foreach ($paymentsByBooking as $bid => $payments) {
        $walk = ars_payment_rows_walk($payments, $out[$bid]['total']);
        if ($walk['has_manual_total']) {
            $out[$bid]['total'] = round($walk['received'] + $walk['outstanding'], 2);
            $out[$bid]['balance'] = round($walk['outstanding'], 2);
            $out[$bid]['received'] = $walk['received'];
            $out[$bid]['source'] = 'payments';
        }
    }
    return $out;
}

/**
 * Put the booking page's figures onto list rows: total_amount, paid_amount,
 * balance_due and payment_status. With $dueNowOnPlan, a booking on a monthly
 * plan then shows only what is due today (months still to come are owed but
 * not due). Rows need id, check_in, check_out, total_amount, balance_due and
 * paid_amount.
 */
function ars_booking_rows_apply_balances(PDO $conn, int $companyId, array $rows, bool $dueNowOnPlan = false): array {
    if (!$rows) {
        return $rows;
    }
    $figures = ars_booking_balances($conn, $companyId, $rows);
    $plans = $dueNowOnPlan ? ars_payment_plans_for_bookings($conn, $companyId, array_keys($figures)) : [];
    foreach ($rows as $i => $r) {
        $id = (int)($r['id'] ?? 0);
        if (!isset($figures[$id])) {
            continue;
        }
        $f = $figures[$id];
        if ($f['source'] !== 'booking') {
            $rows[$i]['total_amount'] = $f['total'];
            $rows[$i]['paid_amount'] = $f['received'];
            $rows[$i]['balance_due'] = $f['balance'];
            $rows[$i]['payment_status'] = ars_booking_payment_status_from(
                $f['total'], $f['balance'], $f['received'], (string)($r['payment_status'] ?? '')
            );
        }
        if (isset($plans[$id])) {
            $rows[$i]['balance_due'] = ars_payment_plan_row_due_now($plans[$id], $rows[$i]);
        }
    }
    return $rows;
}
