<?php
/**
 * Detect likely duplicates between Real Estate Quick Paid Expenses (QPE)
 * and Vendor Bills / Vendor Payments for the same company.
 *
 * Suggested cleanup: keep formal AP (bill/payment); remove the QPE side.
 * Hard-delete lives in includes/erp_expense_posting.php (loaded by the finder page).
 */

/**
 * @return list<array<string,mixed>>
 */
function qpe_ap_find_duplicate_matches(PDO $conn, int $companyId, int $dateWindowDays = 3): array
{
    if ($companyId <= 0) {
        return [];
    }
    $window = max(0, min(14, $dateWindowDays));

    $sql = "
        SELECT
            e.id AS expense_id,
            e.expense_number,
            e.expense_date,
            e.total AS expense_total,
            e.status AS expense_status,
            e.journal_id AS expense_journal_id,
            e.vendor_id,
            v.vendor_name,
            vi.id AS bill_id,
            vi.invoice_number AS bill_number,
            vi.invoice_date AS bill_date,
            vi.total_amount AS bill_total,
            vi.status AS bill_status,
            vp.id AS payment_id,
            vp.payment_date,
            vp.amount AS payment_amount,
            vp.status AS payment_status,
            DATEDIFF(e.expense_date, vi.invoice_date) AS bill_date_diff,
            DATEDIFF(e.expense_date, vp.payment_date) AS payment_date_diff
        FROM erp_expense_headers e
        INNER JOIN re_vendors v
            ON v.id = e.vendor_id AND v.company_id = e.company_id
        LEFT JOIN re_vendor_invoices vi
            ON vi.company_id = e.company_id
           AND vi.vendor_id = e.vendor_id
           AND vi.status NOT IN ('cancelled', 'void', 'rejected', 'draft')
           AND ABS(vi.total_amount - e.total) < 0.015
           AND ABS(DATEDIFF(e.expense_date, vi.invoice_date)) <= ?
        LEFT JOIN re_vendor_payments vp
            ON vp.company_id = e.company_id
           AND vp.vendor_id = e.vendor_id
           AND vp.status <> 'void'
           AND ABS(vp.amount - e.total) < 0.015
           AND ABS(DATEDIFF(e.expense_date, vp.payment_date)) <= ?
        WHERE e.company_id = ?
          AND e.source_module = 'realestate'
          AND e.status IN ('posted', 'draft', 'cancelled')
          AND e.vendor_id IS NOT NULL
          AND e.vendor_id > 0
          AND (vi.id IS NOT NULL OR vp.id IS NOT NULL)
        ORDER BY e.expense_date DESC, e.id DESC, vi.id DESC, vp.id DESC
        LIMIT 500
    ";

    $st = $conn->prepare($sql);
    $st->execute([$window, $window, $companyId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $matches = [];
    $seen = [];
    foreach ($rows as $row) {
        $expenseId = (int)$row['expense_id'];
        $billId = (int)($row['bill_id'] ?? 0);
        $paymentId = (int)($row['payment_id'] ?? 0);
        $key = $expenseId . ':' . $billId . ':' . $paymentId;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $billDiff = $row['bill_date_diff'];
        $payDiff = $row['payment_date_diff'];
        $sameDay = false;
        $withinWindow = false;
        if ($billId > 0 && $billDiff !== null) {
            $withinWindow = true;
            if ((int)$billDiff === 0) {
                $sameDay = true;
            }
        }
        if ($paymentId > 0 && $payDiff !== null) {
            $withinWindow = true;
            if ((int)$payDiff === 0) {
                $sameDay = true;
            }
        }

        $confidence = 'medium';
        if ($sameDay && $billId > 0 && $paymentId > 0) {
            $confidence = 'high';
        } elseif ($sameDay && ($billId > 0 || $paymentId > 0)) {
            $confidence = 'high';
        } elseif ($withinWindow) {
            $confidence = 'medium';
        }

        $matches[] = [
            'expense_id' => $expenseId,
            'expense_number' => (string)($row['expense_number'] ?? ''),
            'expense_date' => (string)($row['expense_date'] ?? ''),
            'expense_total' => (float)($row['expense_total'] ?? 0),
            'expense_status' => (string)($row['expense_status'] ?? ''),
            'expense_journal_id' => (int)($row['expense_journal_id'] ?? 0),
            'vendor_id' => (int)($row['vendor_id'] ?? 0),
            'vendor_name' => (string)($row['vendor_name'] ?? ''),
            'bill_id' => $billId,
            'bill_number' => (string)($row['bill_number'] ?? ''),
            'bill_date' => (string)($row['bill_date'] ?? ''),
            'bill_total' => (float)($row['bill_total'] ?? 0),
            'bill_status' => (string)($row['bill_status'] ?? ''),
            'payment_id' => $paymentId,
            'payment_date' => (string)($row['payment_date'] ?? ''),
            'payment_amount' => (float)($row['payment_amount'] ?? 0),
            'payment_status' => (string)($row['payment_status'] ?? ''),
            'confidence' => $confidence,
            'suggested_action' => 'Keep Vendor Bill/Payment → Remove Quick Paid Expense',
            'can_hard_remove' => in_array((string)($row['expense_status'] ?? ''), ['posted', 'draft', 'cancelled'], true),
        ];
    }

    // Prefer high confidence first
    usort($matches, static function ($a, $b) {
        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
        $ra = $rank[$a['confidence']] ?? 9;
        $rb = $rank[$b['confidence']] ?? 9;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return strcmp((string)$b['expense_date'], (string)$a['expense_date']);
    });

    return $matches;
}

/**
 * Overlaps used on expense_add prevention (vendor + amount + ±3 days).
 *
 * @return list<array{type:string,id:int,label:string,date:string,amount:float}>
 */
function qpe_ap_find_overlaps_for_new_expense(
    PDO $conn,
    int $companyId,
    int $vendorId,
    float $total,
    string $expenseDate,
    int $dateWindowDays = 3
): array {
    if ($companyId <= 0 || $vendorId <= 0 || $total <= 0 || $expenseDate === '') {
        return [];
    }
    $window = max(0, min(14, $dateWindowDays));
    $out = [];

    $billSql = "
        SELECT id, invoice_number, invoice_date, total_amount
        FROM re_vendor_invoices
        WHERE company_id = ?
          AND vendor_id = ?
          AND status NOT IN ('cancelled', 'void', 'rejected', 'draft')
          AND ABS(total_amount - ?) < 0.015
          AND ABS(DATEDIFF(invoice_date, ?)) <= ?
        ORDER BY invoice_date DESC, id DESC
        LIMIT 10
    ";
    $st = $conn->prepare($billSql);
    $st->execute([$companyId, $vendorId, $total, $expenseDate, $window]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $b) {
        $out[] = [
            'type' => 'bill',
            'id' => (int)$b['id'],
            'label' => (string)($b['invoice_number'] ?: ('Bill #' . $b['id'])),
            'date' => (string)$b['invoice_date'],
            'amount' => (float)$b['total_amount'],
        ];
    }

    $paySql = "
        SELECT id, payment_date, amount, reference_number
        FROM re_vendor_payments
        WHERE company_id = ?
          AND vendor_id = ?
          AND status <> 'void'
          AND ABS(amount - ?) < 0.015
          AND ABS(DATEDIFF(payment_date, ?)) <= ?
        ORDER BY payment_date DESC, id DESC
        LIMIT 10
    ";
    $st = $conn->prepare($paySql);
    $st->execute([$companyId, $vendorId, $total, $expenseDate, $window]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
        $ref = trim((string)($p['reference_number'] ?? ''));
        $out[] = [
            'type' => 'payment',
            'id' => (int)$p['id'],
            'label' => $ref !== '' ? $ref : ('Payment #' . $p['id']),
            'date' => (string)$p['payment_date'],
            'amount' => (float)$p['amount'],
        ];
    }

    return $out;
}

/**
 * Re-check that a specific expense still matches the given bill/payment before cleanup.
 */
function qpe_ap_match_still_valid(
    PDO $conn,
    int $companyId,
    int $expenseId,
    int $billId,
    int $paymentId,
    int $dateWindowDays = 3
): array {
    if ($companyId <= 0 || $expenseId <= 0 || ($billId <= 0 && $paymentId <= 0)) {
        return ['ok' => false, 'error' => 'Invalid match selection.'];
    }
    $window = max(0, min(14, $dateWindowDays));

    $es = $conn->prepare("
        SELECT id, vendor_id, total, expense_date, status, journal_id, expense_number, source_module
        FROM erp_expense_headers
        WHERE id = ? AND company_id = ? AND source_module = 'realestate'
        LIMIT 1
    ");
    $es->execute([$expenseId, $companyId]);
    $e = $es->fetch(PDO::FETCH_ASSOC);
    if (!$e) {
        return ['ok' => false, 'error' => 'Quick Paid Expense not found.'];
    }

    $vendorId = (int)($e['vendor_id'] ?? 0);
    $total = (float)$e['total'];
    $expenseDate = (string)$e['expense_date'];
    if ($vendorId <= 0) {
        return ['ok' => false, 'error' => 'Expense has no vendor; cannot confirm AP duplicate.'];
    }

    $billOk = $billId <= 0;
    if ($billId > 0) {
        $bs = $conn->prepare("
            SELECT id FROM re_vendor_invoices
            WHERE id = ? AND company_id = ? AND vendor_id = ?
              AND status NOT IN ('cancelled', 'void', 'rejected', 'draft')
              AND ABS(total_amount - ?) < 0.015
              AND ABS(DATEDIFF(invoice_date, ?)) <= ?
            LIMIT 1
        ");
        $bs->execute([$billId, $companyId, $vendorId, $total, $expenseDate, $window]);
        $billOk = (bool)$bs->fetchColumn();
    }

    $payOk = $paymentId <= 0;
    if ($paymentId > 0) {
        $ps = $conn->prepare("
            SELECT id FROM re_vendor_payments
            WHERE id = ? AND company_id = ? AND vendor_id = ?
              AND status <> 'void'
              AND ABS(amount - ?) < 0.015
              AND ABS(DATEDIFF(payment_date, ?)) <= ?
            LIMIT 1
        ");
        $ps->execute([$paymentId, $companyId, $vendorId, $total, $expenseDate, $window]);
        $payOk = (bool)$ps->fetchColumn();
    }

    if (!$billOk || !$payOk) {
        return ['ok' => false, 'error' => 'Match is no longer valid (bill/payment changed or removed).'];
    }

    return ['ok' => true, 'expense' => $e];
}
