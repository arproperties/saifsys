<?php
/**
 * Detect likely duplicates between Construction Quick Paid Expenses (QPE)
 * and Supplier Invoices / Supplier Payments for the same company.
 *
 * Uses co_suppliers / co_supplier_* only — never re_vendors / re_vendor_*.
 * Suggested cleanup: keep formal AP; remove the QPE side.
 */

/**
 * @return list<array<string,mixed>>
 */
function qpe_co_find_duplicate_matches(PDO $conn, int $companyId, int $dateWindowDays = 3): array
{
    if ($companyId <= 0) {
        return [];
    }
    $window = max(0, min(14, $dateWindowDays));
    $hasLegacyCol = erp_expense_headers_has_legacy_archive_column($conn);
    $legacySelect = $hasLegacyCol ? 'COALESCE(e.legacy_archive, 0)' : '0';

    $sql = "
        SELECT
            e.id AS expense_id,
            e.expense_number,
            e.expense_date,
            e.total AS expense_total,
            e.status AS expense_status,
            e.journal_id AS expense_journal_id,
            {$legacySelect} AS legacy_archive,
            e.co_supplier_id AS supplier_id,
            s.supplier_name,
            si.id AS bill_id,
            si.invoice_number AS bill_number,
            si.invoice_date AS bill_date,
            si.total AS bill_total,
            si.status AS bill_status,
            sp.id AS payment_id,
            sp.payment_date,
            sp.amount AS payment_amount,
            DATEDIFF(e.expense_date, si.invoice_date) AS bill_date_diff,
            DATEDIFF(e.expense_date, sp.payment_date) AS payment_date_diff
        FROM erp_expense_headers e
        INNER JOIN co_suppliers s
            ON s.id = e.co_supplier_id AND s.company_id = e.company_id
        LEFT JOIN co_supplier_invoices si
            ON si.company_id = e.company_id
           AND si.supplier_id = e.co_supplier_id
           AND si.status NOT IN ('draft', 'voided')
           AND ABS(si.total - e.total) < 0.015
           AND ABS(DATEDIFF(e.expense_date, si.invoice_date)) <= ?
        LEFT JOIN co_supplier_payments sp
            ON sp.company_id = e.company_id
           AND sp.supplier_id = e.co_supplier_id
           AND ABS(sp.amount - e.total) < 0.015
           AND ABS(DATEDIFF(e.expense_date, sp.payment_date)) <= ?
        WHERE e.company_id = ?
          AND e.source_module = 'construction'
          AND e.status IN ('posted', 'draft', 'cancelled')
          AND e.co_supplier_id IS NOT NULL
          AND e.co_supplier_id > 0
          AND (si.id IS NOT NULL OR sp.id IS NOT NULL)
        ORDER BY e.expense_date DESC, e.id DESC, si.id DESC, sp.id DESC
        LIMIT 500
    ";

    $st = $conn->prepare($sql);
    $st->execute([$window, $window, $companyId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $matches = [];
    $seen = [];
    foreach ($rows as $row) {
        $expenseId = (int)$row['expense_id'];
        $isLegacyArchive = (int)($row['legacy_archive'] ?? 0) === 1;
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
        if ($sameDay && ($billId > 0 || $paymentId > 0)) {
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
            'supplier_id' => (int)($row['supplier_id'] ?? 0),
            'supplier_name' => (string)($row['supplier_name'] ?? ''),
            'bill_id' => $billId,
            'bill_number' => (string)($row['bill_number'] ?? ''),
            'bill_date' => (string)($row['bill_date'] ?? ''),
            'bill_total' => (float)($row['bill_total'] ?? 0),
            'bill_status' => (string)($row['bill_status'] ?? ''),
            'payment_id' => $paymentId,
            'payment_date' => (string)($row['payment_date'] ?? ''),
            'payment_amount' => (float)($row['payment_amount'] ?? 0),
            'confidence' => $confidence,
            'suggested_action' => $isLegacyArchive
                ? 'Historical archive (read-only) — Supplier Invoice/Payment is the record of truth'
                : 'Keep Supplier Invoice/Payment → Remove Quick Paid Expense',
            'is_legacy_archive' => $isLegacyArchive,
            'can_hard_remove' => !$isLegacyArchive
                && in_array((string)($row['expense_status'] ?? ''), ['posted', 'draft', 'cancelled'], true),
        ];
    }

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
 * @return list<array{type:string,id:int,label:string,date:string,amount:float}>
 */
function qpe_co_find_overlaps_for_new_expense(
    PDO $conn,
    int $companyId,
    int $supplierId,
    float $total,
    string $expenseDate,
    int $dateWindowDays = 3
): array {
    if ($companyId <= 0 || $supplierId <= 0 || $total <= 0 || $expenseDate === '') {
        return [];
    }
    $window = max(0, min(14, $dateWindowDays));
    $out = [];

    $billSql = "
        SELECT id, invoice_number, invoice_date, total
        FROM co_supplier_invoices
        WHERE company_id = ?
          AND supplier_id = ?
          AND status NOT IN ('draft', 'voided')
          AND ABS(total - ?) < 0.015
          AND ABS(DATEDIFF(invoice_date, ?)) <= ?
        ORDER BY invoice_date DESC, id DESC
        LIMIT 10
    ";
    $st = $conn->prepare($billSql);
    $st->execute([$companyId, $supplierId, $total, $expenseDate, $window]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $b) {
        $out[] = [
            'type' => 'bill',
            'id' => (int)$b['id'],
            'label' => (string)($b['invoice_number'] ?: ('Invoice #' . $b['id'])),
            'date' => (string)$b['invoice_date'],
            'amount' => (float)$b['total'],
        ];
    }

    $paySql = "
        SELECT id, payment_date, amount, reference_number
        FROM co_supplier_payments
        WHERE company_id = ?
          AND supplier_id = ?
          AND ABS(amount - ?) < 0.015
          AND ABS(DATEDIFF(payment_date, ?)) <= ?
        ORDER BY payment_date DESC, id DESC
        LIMIT 10
    ";
    try {
        $st = $conn->prepare($paySql);
        $st->execute([$companyId, $supplierId, $total, $expenseDate, $window]);
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
    } catch (Throwable $e) {
        $paySql2 = "
            SELECT id, payment_date, amount
            FROM co_supplier_payments
            WHERE company_id = ?
              AND supplier_id = ?
              AND ABS(amount - ?) < 0.015
              AND ABS(DATEDIFF(payment_date, ?)) <= ?
            ORDER BY payment_date DESC, id DESC
            LIMIT 10
        ";
        $st = $conn->prepare($paySql2);
        $st->execute([$companyId, $supplierId, $total, $expenseDate, $window]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            $out[] = [
                'type' => 'payment',
                'id' => (int)$p['id'],
                'label' => 'Payment #' . $p['id'],
                'date' => (string)$p['payment_date'],
                'amount' => (float)$p['amount'],
            ];
        }
    }

    return $out;
}

/**
 * @return array{ok:bool,error?:string,expense?:array}
 */
function qpe_co_match_still_valid(
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
        SELECT id, co_supplier_id, total, expense_date, status, journal_id, expense_number, source_module
        FROM erp_expense_headers
        WHERE id = ? AND company_id = ? AND source_module = 'construction'
        LIMIT 1
    ");
    $es->execute([$expenseId, $companyId]);
    $e = $es->fetch(PDO::FETCH_ASSOC);
    if (!$e) {
        return ['ok' => false, 'error' => 'Quick Paid Expense not found.'];
    }

    $supplierId = (int)($e['co_supplier_id'] ?? 0);
    $total = (float)$e['total'];
    $expenseDate = (string)$e['expense_date'];
    if ($supplierId <= 0) {
        return ['ok' => false, 'error' => 'Expense has no supplier; cannot confirm AP duplicate.'];
    }

    $billOk = $billId <= 0;
    if ($billId > 0) {
        $bs = $conn->prepare("
            SELECT id FROM co_supplier_invoices
            WHERE id = ? AND company_id = ? AND supplier_id = ?
              AND status NOT IN ('draft', 'voided')
              AND ABS(total - ?) < 0.015
              AND ABS(DATEDIFF(invoice_date, ?)) <= ?
            LIMIT 1
        ");
        $bs->execute([$billId, $companyId, $supplierId, $total, $expenseDate, $window]);
        $billOk = (bool)$bs->fetchColumn();
    }

    $payOk = $paymentId <= 0;
    if ($paymentId > 0) {
        $ps = $conn->prepare("
            SELECT id FROM co_supplier_payments
            WHERE id = ? AND company_id = ? AND supplier_id = ?
              AND ABS(amount - ?) < 0.015
              AND ABS(DATEDIFF(payment_date, ?)) <= ?
            LIMIT 1
        ");
        $ps->execute([$paymentId, $companyId, $supplierId, $total, $expenseDate, $window]);
        $payOk = (bool)$ps->fetchColumn();
    }

    if (!$billOk || !$payOk) {
        return ['ok' => false, 'error' => 'Match is no longer valid (invoice/payment changed or removed).'];
    }

    return ['ok' => true, 'expense' => $e];
}
