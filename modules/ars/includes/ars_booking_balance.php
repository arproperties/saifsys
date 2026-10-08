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
    $shareCharged = false;
    foreach ($payments as $pm) {
        $id = (int)$pm['id'];
        $pmReceived = (float)$pm['amount'];
        $manual = !(($pm['total_amount'] ?? null) === null || $pm['total_amount'] === '');
        // Beside billed extensions a row with no typed total stands only for
        // its share of the invoiced total (see ars_payment_rows_add_billed_extensions()).
        $shared = !$manual && isset($pm['fallback_total']);
        $pmTotal = $manual ? (float)$pm['total_amount'] : ($shared ? (float)$pm['fallback_total'] : $stayTotal);
        $rowTotal[$id] = $pmTotal;
        $received += $pmReceived;
        $runningReceived += $pmReceived;

        if ($manual || $shared) {
            // An extension line is drawn, not typed: on its own it does not
            // make the table the office's statement.
            if ($manual && empty($pm['is_extension_line'])) {
                $hasManualTotal = true;
            }
            // The share is owed once, however many payments go against it.
            $charge = $pmTotal;
            if ($shared) {
                $charge = $shareCharged ? 0.0 : $pmTotal;
                $shareCharged = true;
            }
            $carried = round($prevOutstanding + ($charge - $pmReceived), 2);
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
 * Add billed extensions to the Payments rows as lines of their own, so a
 * typed-total table still shows what an extension invoice left owing:
 * Total = the invoice, Received = 0, and the walk carries it down as
 * outstanding until a payment clears it. The lines are drawn, never saved --
 * no payment row, journal or receipt stands behind them.
 *
 * Only for what the typed totals do not already cover: an extension the
 * office typed onto a payment row is counted there, so its line is left out
 * rather than charged twice. Payments with no typed total are handed
 * fallback_total -- the invoiced total less what the lines and typed rows
 * carry -- so the walk does not count an extension on them a second time.
 *
 * $extensions: id, date, total, open, from, to, nights -- one per live
 * extension invoice. Lines carry a negative id so they never collide with a payment.
 */
function ars_payment_rows_add_billed_extensions(array $payments, array $extensions, float $netInvoiced): array {
    $typedTotal = 0.0;
    $hasUntyped = false;
    foreach ($payments as $pm) {
        if (!(($pm['total_amount'] ?? null) === null || $pm['total_amount'] === '')) {
            $typedTotal += (float)$pm['total_amount'];
        } else {
            $hasUntyped = true;
        }
    }
    $extensions = array_values(array_filter($extensions, static function (array $ext): bool {
        return round((float)($ext['total'] ?? 0), 2) > 0.009;
    }));
    if (!$extensions) {
        return $payments;
    }
    $extensionTotal = 0.0;
    foreach ($extensions as $ext) {
        $extensionTotal += (float)$ext['total'];
    }
    $uncovered = round($netInvoiced - $typedTotal, 2);
    if ($hasUntyped) {
        // The rows with no typed total already stand for the rest of the
        // invoices, so only the extensions themselves are left to draw.
        $uncovered = min($uncovered, round($extensionTotal - $typedTotal, 2));
    }
    // Which extensions the typed totals leave uncovered: the set that fills
    // the gap best, and between equals the one with more still owing -- a
    // paid extension is the likelier one to have been typed onto its payment.
    // Taking them in date order instead drops the wrong line as soon as the
    // first of two extensions is paid.
    $count = min(count($extensions), 12);
    $bestMask = 0;
    $bestSum = 0.0;
    $bestOpen = 0.0;
    for ($mask = 1; $mask < (1 << $count); $mask++) {
        $sum = 0.0;
        $open = 0.0;
        for ($i = 0; $i < $count; $i++) {
            if ($mask & (1 << $i)) {
                $sum += (float)$extensions[$i]['total'];
                $open += (float)($extensions[$i]['open'] ?? $extensions[$i]['total']);
            }
        }
        if ($sum > $uncovered + 0.009) {
            continue;
        }
        if ($sum > $bestSum + 0.009 || (abs($sum - $bestSum) <= 0.009 && $open > $bestOpen + 0.009)) {
            $bestMask = $mask;
            $bestSum = $sum;
            $bestOpen = $open;
        }
    }
    $lines = [];
    foreach ($extensions as $i => $ext) {
        if ($i >= $count || !($bestMask & (1 << $i))) {
            continue;
        }
        $total = round((float)$ext['total'], 2);
        $lines[] = [
            'id' => -(int)$ext['id'],
            'is_extension_line' => true,
            'payment_date' => (string)($ext['date'] ?? ''),
            'amount' => 0,
            'total_amount' => $total,
            'extended_from' => (string)($ext['from'] ?? ''),
            'extended_to' => (string)($ext['to'] ?? ''),
            'nights' => (int)($ext['nights'] ?? 0),
            'open' => round((float)($ext['open'] ?? $total), 2),
        ];
    }
    // A row with no typed total used to take the whole invoiced total, so an
    // extension was counted on it and again on its own line or on the payment
    // typed for it. It now stands for what those do not carry.
    $coveredElsewhere = $bestSum + min($typedTotal, max(0.0, $extensionTotal - $bestSum));
    $fallbackTotal = max(0.0, round($netInvoiced - $coveredElsewhere, 2));
    foreach ($payments as $k => $pm) {
        if (($pm['total_amount'] ?? null) === null || $pm['total_amount'] === '') {
            $payments[$k]['fallback_total'] = $fallbackTotal;
        }
    }
    if (!$lines) {
        return $payments;
    }
    // A line sits ahead of the payments dated the same day, so the money
    // taken that day reads as settling it.
    $rows = [];
    foreach (array_merge($lines, $payments) as $i => $row) {
        $rows[] = [substr((string)($row['payment_date'] ?? ''), 0, 10), $i, $row];
    }
    usort($rows, static function (array $a, array $b): int {
        return [$a[0], $a[1]] <=> [$b[0], $b[1]];
    });
    return array_column($rows, 2);
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
            SELECT id, booking_id, document_type, status, document_date, total_amount, balance_due
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
            SELECT id, booking_id, payment_date, amount, total_amount
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
    // The dates each extension invoice covers, so its line lands where the
    // booking page puts it. Without them the line falls back to the invoice date.
    $extensionFrom = [];
    try {
        $stmt = $conn->prepare("
            SELECT e.document_id, e.prior_check_out
            FROM ars_extension_documents e
            INNER JOIN ars_financial_documents d ON d.id = e.document_id
            WHERE d.company_id = ? AND d.booking_id IN ($in)
        ");
        $stmt->execute(array_merge([$companyId], $ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $extensionFrom[(int)$e['document_id']] = (string)$e['prior_check_out'];
        }
    } catch (Throwable $ignored) {
    }
    foreach ($paymentsByBooking as $bid => $payments) {
        $extensions = [];
        foreach ($docsByBooking[$bid] ?? [] as $d) {
            if (($d['document_type'] ?? '') !== 'extension_invoice'
                || in_array(strtolower((string)($d['status'] ?? '')), ['draft', 'voided', 'reversed'], true)) {
                continue;
            }
            $extensions[] = [
                'id' => (int)$d['id'],
                'date' => $extensionFrom[(int)$d['id']] ?? (string)$d['document_date'],
                'total' => (float)$d['total_amount'],
                'open' => (float)$d['balance_due'],
            ];
        }
        if ($extensions && $out[$bid]['source'] === 'invoices') {
            $payments = ars_payment_rows_add_billed_extensions($payments, $extensions, $out[$bid]['total']);
        }
        $walk = ars_payment_rows_walk($payments, $out[$bid]['total']);
        if ($walk['has_manual_total']) {
            $out[$bid]['total'] = round($walk['received'] + $walk['outstanding'], 2);
            $out[$bid]['balance'] = round($walk['outstanding'], 2);
            $out[$bid]['received'] = $walk['received'];
            $out[$bid]['source'] = 'payments';
        }
    }
    // Cancelled before check-in: nothing owed (same rule as the booking page).
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id > 0 && in_array((string)($r['status'] ?? ''), ['cancelled', 'expired'], true)) {
            $out[$id]['total'] = 0.0;
            $out[$id]['balance'] = 0.0;
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
