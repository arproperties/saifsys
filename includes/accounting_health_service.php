<?php
/**
 * Cleaning accounting health checks.
 *
 * These functions are intentionally read-only, except the assert helpers which
 * throw when a just-written invoice/receipt violates the accounting invariants.
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/gl_posting.php';
require_once __DIR__ . '/work_order_batch_invoice_service.php';

if (!function_exists('accounting_health_gl_company_sql')) {
  function accounting_health_gl_company_sql(PDO $conn, string $alias, ?int $companyId, array &$params): string {
    if ($companyId === null || !function_exists('gl_column_exists') || !gl_column_exists($conn, 'gl_journals', 'company_id')) {
      return '';
    }
    $params[] = $companyId;
    return " AND {$alias}.company_id = ?";
  }
}

if (!function_exists('accounting_health_invoice_active_journal_ids')) {
  function accounting_health_invoice_active_journal_ids(PDO $conn, int $invoiceId): array {
    $st = $conn->prepare("
      SELECT id
      FROM gl_journals
      WHERE source = 'invoice'
        AND source_id = ?
        AND is_posted = 1
        AND is_reversed = 0
      ORDER BY id ASC
    ");
    $st->execute([$invoiceId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
  }
}

if (!function_exists('accounting_health_receipt_active_journal_ids')) {
  function accounting_health_receipt_active_journal_ids(PDO $conn, int $receiptId): array {
    $st = $conn->prepare("
      SELECT id
      FROM gl_journals
      WHERE source = 'receipt'
        AND source_id = ?
        AND is_posted = 1
        AND is_reversed = 0
      ORDER BY id ASC
    ");
    $st->execute([$receiptId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
  }
}

if (!function_exists('accounting_health_invoice_allocation_state')) {
  function accounting_health_invoice_allocation_state(PDO $conn, int $invoiceId): array {
    $st = $conn->prepare("
      SELECT i.id, i.invoice_no, i.status, i.total, i.amount_paid, i.balance_due,
             COALESCE(SUM(ra.amount_applied), 0) AS allocated
      FROM invoices i
      LEFT JOIN receipt_allocations ra ON ra.invoice_id = i.id
      WHERE i.id = ?
      GROUP BY i.id
      LIMIT 1
    ");
    $st->execute([$invoiceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [];
    $total = round((float)$row['total'], 2);
    $allocated = round((float)$row['allocated'], 2);
    $expectedBalance = max(0.0, round($total - $allocated, 2));
    $expectedStatus = ((string)$row['status'] === 'void')
      ? 'void'
      : (($allocated >= $total && $total > 0) ? 'paid' : (($allocated > 0) ? 'partially_paid' : 'issued'));
    return [
      'row' => $row,
      'allocated' => $allocated,
      'expected_balance' => $expectedBalance,
      'expected_status' => $expectedStatus,
    ];
  }
}

if (!function_exists('accounting_health_collect')) {
  function accounting_health_collect(PDO $conn, ?int $companyId = null): array {
    $out = [
      'duplicate_invoice_journals' => ['count' => 0, 'rows' => []],
      'missing_invoice_journals' => ['count' => 0, 'rows' => []],
      'void_invoice_active_journals' => ['count' => 0, 'rows' => []],
      'duplicate_receipt_journals' => ['count' => 0, 'rows' => []],
      'missing_receipt_journals' => ['count' => 0, 'rows' => []],
      'allocation_status_mismatches' => ['count' => 0, 'rows' => []],
      'overallocated_receipts' => ['count' => 0, 'rows' => []],
      'broken_allocations' => ['count' => 0, 'rows' => []],
      'invalid_trade_receivable_lines' => ['count' => 0, 'rows' => []],
    ];

    $invoiceCompanySql = $companyId !== null ? ' AND i.company_id = ?' : '';
    $receiptCompanySql = $companyId !== null ? ' AND r.company_id = ?' : '';

    $params = [];
    $glCompanySql = accounting_health_gl_company_sql($conn, 'j', $companyId, $params);
    $sql = "
      SELECT j.source_id AS invoice_id, MAX(i.invoice_no) AS invoice_no,
             COUNT(*) AS journal_count, GROUP_CONCAT(j.id ORDER BY j.id) AS journal_ids
      FROM gl_journals j
      LEFT JOIN invoices i ON i.id = j.source_id
      WHERE j.source = 'invoice' AND j.is_posted = 1 AND j.is_reversed = 0 {$glCompanySql}
      GROUP BY j.source_id
      HAVING journal_count > 1
      ORDER BY journal_count DESC, invoice_id DESC
      LIMIT 200
    ";
    $st = $conn->prepare($sql);
    $st->execute($params);
    $out['duplicate_invoice_journals']['rows'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out['duplicate_invoice_journals']['count'] = count($out['duplicate_invoice_journals']['rows']);

    $params = $companyId !== null ? [$companyId] : [];
    $batchExcludeSql = (function_exists('sm4_invoice_has_batch_flag') && sm4_invoice_has_batch_flag($conn))
        ? ' AND COALESCE(i.is_batch_summary, 0) = 0'
        : '';
    $st = $conn->prepare("
      SELECT i.id, i.invoice_no, i.issue_date, i.status, i.total
      FROM invoices i
      WHERE i.status IN ('issued','partially_paid','paid') {$invoiceCompanySql}
        {$batchExcludeSql}
        AND NOT EXISTS (
          SELECT 1 FROM gl_journals j
          WHERE j.source = 'invoice' AND j.source_id = i.id AND j.is_posted = 1 AND j.is_reversed = 0
        )
      ORDER BY i.issue_date DESC, i.id DESC
      LIMIT 200
    ");
    $st->execute($params);
    $out['missing_invoice_journals']['rows'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out['missing_invoice_journals']['count'] = count($out['missing_invoice_journals']['rows']);

    $params = $companyId !== null ? [$companyId] : [];
    $st = $conn->prepare("
      SELECT i.id, i.invoice_no, i.status, i.total, GROUP_CONCAT(j.id ORDER BY j.id) AS journal_ids
      FROM invoices i
      JOIN gl_journals j ON j.source = 'invoice' AND j.source_id = i.id AND j.is_posted = 1 AND j.is_reversed = 0
      WHERE i.status IN ('draft','void') {$invoiceCompanySql}
      GROUP BY i.id
      ORDER BY i.id DESC
      LIMIT 200
    ");
    $st->execute($params);
    $out['void_invoice_active_journals']['rows'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out['void_invoice_active_journals']['count'] = count($out['void_invoice_active_journals']['rows']);

    $params = [];
    $glCompanySql = accounting_health_gl_company_sql($conn, 'j', $companyId, $params);
    $st = $conn->prepare("
      SELECT j.source_id AS receipt_id, MAX(r.receipt_no) AS receipt_no,
             COUNT(*) AS journal_count, GROUP_CONCAT(j.id ORDER BY j.id) AS journal_ids
      FROM gl_journals j
      LEFT JOIN receipts r ON r.id = j.source_id
      WHERE j.source = 'receipt' AND j.is_posted = 1 AND j.is_reversed = 0 {$glCompanySql}
      GROUP BY j.source_id
      HAVING journal_count > 1
      ORDER BY journal_count DESC, receipt_id DESC
      LIMIT 200
    ");
    $st->execute($params);
    $out['duplicate_receipt_journals']['rows'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out['duplicate_receipt_journals']['count'] = count($out['duplicate_receipt_journals']['rows']);

    $params = $companyId !== null ? [$companyId] : [];
    $st = $conn->prepare("
      SELECT r.id, r.receipt_no, r.receipt_date, r.amount, r.method
      FROM receipts r
      WHERE 1=1 {$receiptCompanySql}
        AND NOT EXISTS (
          SELECT 1 FROM gl_journals j
          WHERE j.source = 'receipt' AND j.source_id = r.id AND j.is_posted = 1 AND j.is_reversed = 0
        )
      ORDER BY r.receipt_date DESC, r.id DESC
      LIMIT 200
    ");
    $st->execute($params);
    $out['missing_receipt_journals']['rows'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out['missing_receipt_journals']['count'] = count($out['missing_receipt_journals']['rows']);

    $params = $companyId !== null ? [$companyId] : [];
    $st = $conn->prepare("
      SELECT i.id, i.invoice_no, i.status, i.total, i.amount_paid, i.balance_due,
             COALESCE(SUM(ra.amount_applied), 0) AS allocated,
             GREATEST(ROUND(i.total - COALESCE(SUM(ra.amount_applied), 0), 2), 0) AS expected_balance
      FROM invoices i
      LEFT JOIN receipt_allocations ra ON ra.invoice_id = i.id
      WHERE i.status <> 'void' {$invoiceCompanySql}
      GROUP BY i.id
      HAVING ABS(COALESCE(i.amount_paid,0) - allocated) > 0.01
          OR ABS(COALESCE(i.balance_due,0) - expected_balance) > 0.01
          OR (allocated <= 0.01 AND i.status = 'partially_paid')
          OR (allocated > 0.01 AND allocated < i.total - 0.01 AND i.status <> 'partially_paid')
          OR (allocated >= i.total - 0.01 AND i.total > 0 AND i.status <> 'paid')
      ORDER BY i.id DESC
      LIMIT 200
    ");
    $st->execute($params);
    $out['allocation_status_mismatches']['rows'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out['allocation_status_mismatches']['count'] = count($out['allocation_status_mismatches']['rows']);

    $params = $companyId !== null ? [$companyId] : [];
    $st = $conn->prepare("
      SELECT r.id, r.receipt_no, r.amount, COALESCE(SUM(ra.amount_applied),0) AS allocated
      FROM receipts r
      JOIN receipt_allocations ra ON ra.receipt_id = r.id
      WHERE 1=1 {$receiptCompanySql}
      GROUP BY r.id
      HAVING allocated > r.amount + 0.01
      ORDER BY r.id DESC
      LIMIT 200
    ");
    $st->execute($params);
    $out['overallocated_receipts']['rows'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out['overallocated_receipts']['count'] = count($out['overallocated_receipts']['rows']);

    $params = [];
    if ($companyId !== null) $params[] = $companyId;
    $st = $conn->prepare("
      SELECT ra.id, ra.receipt_id, ra.invoice_id, ra.amount_applied
      FROM receipt_allocations ra
      LEFT JOIN receipts r ON r.id = ra.receipt_id
      LEFT JOIN invoices i ON i.id = ra.invoice_id
      WHERE (r.id IS NULL OR i.id IS NULL)" . ($companyId !== null ? " OR (r.company_id <> ? OR i.company_id <> r.company_id)" : "") . "
      ORDER BY ra.id DESC
      LIMIT 200
    ");
    $st->execute($params);
    $out['broken_allocations']['rows'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out['broken_allocations']['count'] = count($out['broken_allocations']['rows']);

    $arAccountId = null;
    try {
      $arAccountId = coa_id($conn, '1110');
    } catch (Throwable $e) {
      $arAccountId = null;
    }
    if ($arAccountId) {
      $params = [$arAccountId];
      $glCompanySql = accounting_health_gl_company_sql($conn, 'j', $companyId, $params);
      $st = $conn->prepare("
        SELECT j.id AS journal_id, j.journal_no, j.source, j.source_id, l.debit, l.credit, l.description
        FROM gl_journal_lines l
        JOIN gl_journals j ON j.id = l.journal_id
        LEFT JOIN invoices i ON i.id = j.source_id AND j.source = 'invoice'
        LEFT JOIN receipts r ON r.id = j.source_id AND j.source = 'receipt'
        LEFT JOIN gl_journals oj ON oj.id = j.source_id AND j.source = 'reversal'
        WHERE l.account_id = ?
          AND j.is_posted = 1
          {$glCompanySql}
          AND (
            (l.debit > 0 AND (j.source <> 'invoice' OR i.id IS NULL))
            OR (l.credit > 0 AND (
                  (j.source = 'receipt' AND r.id IS NULL)
                  OR (j.source = 'reversal' AND (oj.id IS NULL OR oj.source <> 'invoice'))
                  OR (j.source NOT IN ('receipt','reversal'))
                ))
          )
        ORDER BY j.journal_date DESC, j.id DESC
        LIMIT 200
      ");
      $st->execute($params);
      $out['invalid_trade_receivable_lines']['rows'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $out['invalid_trade_receivable_lines']['count'] = count($out['invalid_trade_receivable_lines']['rows']);
    }

    $out['critical_count'] = array_sum(array_map(static fn($row) => (int)($row['count'] ?? 0), $out));
    return $out;
  }
}

if (!function_exists('accounting_health_assert_invoice')) {
  function accounting_health_assert_invoice(PDO $conn, int $invoiceId): void {
    $st = $conn->prepare("SELECT id, invoice_no, status FROM invoices WHERE id = ? LIMIT 1");
    $st->execute([$invoiceId]);
    $invoice = $st->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) throw new RuntimeException("Invoice #{$invoiceId} not found after write.");

    $journals = accounting_health_invoice_active_journal_ids($conn, $invoiceId);
    $status = (string)$invoice['status'];
    if (in_array($status, ['issued','partially_paid','paid'], true) && count($journals) !== 1) {
      throw new RuntimeException("Invoice {$invoice['invoice_no']} must have exactly one active GL journal; found " . count($journals) . ".");
    }
    if (in_array($status, ['draft','void'], true) && count($journals) > 0) {
      throw new RuntimeException("Invoice {$invoice['invoice_no']} is {$status} but still has active GL journal(s).");
    }

    $state = accounting_health_invoice_allocation_state($conn, $invoiceId);
    if ($state) {
      $row = $state['row'];
      if (abs((float)$row['amount_paid'] - (float)$state['allocated']) > 0.01) {
        throw new RuntimeException("Invoice {$row['invoice_no']} amount_paid does not match receipt allocations.");
      }
      if (abs((float)$row['balance_due'] - (float)$state['expected_balance']) > 0.01) {
        throw new RuntimeException("Invoice {$row['invoice_no']} balance_due does not match receipt allocations.");
      }
    }
  }
}

if (!function_exists('accounting_health_assert_receipt')) {
  function accounting_health_assert_receipt(PDO $conn, int $receiptId): void {
    $st = $conn->prepare("SELECT id, receipt_no, amount FROM receipts WHERE id = ? LIMIT 1");
    $st->execute([$receiptId]);
    $receipt = $st->fetch(PDO::FETCH_ASSOC);
    if (!$receipt) throw new RuntimeException("Receipt #{$receiptId} not found after write.");

    $journals = accounting_health_receipt_active_journal_ids($conn, $receiptId);
    if (count($journals) !== 1) {
      throw new RuntimeException("Receipt {$receipt['receipt_no']} must have exactly one active GL journal; found " . count($journals) . ".");
    }

    $allocSt = $conn->prepare("SELECT COALESCE(SUM(amount_applied),0) FROM receipt_allocations WHERE receipt_id = ?");
    $allocSt->execute([$receiptId]);
    $allocated = (float)$allocSt->fetchColumn();
    if ($allocated > (float)$receipt['amount'] + 0.01) {
      throw new RuntimeException("Receipt {$receipt['receipt_no']} is overallocated.");
    }
  }
}
