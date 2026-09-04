<?php
// includes/ar_helpers.php
// Utility + AR helper functions used by accounts/* and operation/* pages.

require_once __DIR__ . '/gl_posting.php';
require_once __DIR__ . '/cleaning_payment_accounts.php';

/* -----------------------------------------------------------
   Small general helpers
----------------------------------------------------------- */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
  function money($n){ return number_format((float)$n, 2); }
}

/** Return a single scalar value from a query. */
if (!function_exists('single_val')) {
  function single_val(PDO $conn, string $sql, array $params = []) {
    $st = $conn->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
  }
}

/** Child job invoices only — excludes BINV batch statements from AR/collections. */
if (!function_exists('ar_collectible_invoice_sql')) {
  function ar_collectible_invoice_sql(string $alias = 'i'): string {
    return "COALESCE({$alias}.is_batch_summary, 0) = 0";
  }
}

/* ===========================================================
   Core: (Re)post an invoice to the GL and link it back
=========================================================== */
// includes/ar_helpers.php  (replace this function)
if (!function_exists('ar_post_or_repost_invoice')) {
  function ar_post_or_repost_invoice(PDO $conn, int $invoice_id): void {
    // Load invoice
    $st = $conn->prepare("SELECT id, status FROM invoices WHERE id=?");
    $st->execute([$invoice_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;

    require_once __DIR__ . '/work_order_batch_invoice_service.php';
    if (sm_invoice_is_batch_summary($conn, $invoice_id)) {
      return;
    }

    require_once __DIR__ . '/gl_posting.php';

    $journalSt = $conn->prepare("
      SELECT id 
      FROM gl_journals 
      WHERE source='invoice' 
        AND source_id=? 
        AND is_posted=1 
        AND is_reversed=0 
      ORDER BY id ASC
      FOR UPDATE
    ");
    $journalSt->execute([$invoice_id]);
    $activeJournalIds = array_map('intval', $journalSt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $reverseJournal = function(int $jid) use ($conn): void {
      try {
        $revExists = $conn->prepare("SELECT COUNT(*) FROM gl_journals WHERE source='reversal' AND source_id=? AND is_posted=1");
        $revExists->execute([$jid]);
        if ((int)$revExists->fetchColumn() === 0) {
          gl_reverse_journal($conn, $jid);
        } else {
          $conn->prepare("UPDATE gl_journals SET is_reversed=1 WHERE id=? AND is_reversed=0")->execute([$jid]);
        }
      } catch (RuntimeException $e) {
        error_log("Skipping reversal for journal #{$jid}: " . $e->getMessage());
        $conn->prepare("UPDATE gl_journals SET is_reversed=1 WHERE id=? AND is_reversed=0")->execute([$jid]);
      }
    };

    if (in_array($row['status'], ['issued','partially_paid','paid'], true)) {
      $keptJournalId = null;
      foreach ($activeJournalIds as $jid) {
        if ($keptJournalId === null && function_exists('gl_invoice_journal_matches') && gl_invoice_journal_matches($conn, $jid, $invoice_id)) {
          $keptJournalId = $jid;
          continue;
        }
        $reverseJournal($jid);
      }

      $jid = $keptJournalId ?: gl_post_invoice($conn, $invoice_id);

      // Link back
      if ($jid) {
        $up = $conn->prepare("UPDATE invoices SET gl_journal_id=?, posted_at=NOW() WHERE id=?");
        $up->execute([(int)$jid, $invoice_id]);
      }
    } else {
      foreach ($activeJournalIds as $jid) {
        $reverseJournal($jid);
      }
      // keep pointer clean for void/cancel flows
      $conn->prepare("UPDATE invoices SET gl_journal_id=NULL WHERE id=?")->execute([$invoice_id]);
    }
  }
}

if (!function_exists('ar_post_or_repost_receipt')) {
  function ar_post_or_repost_receipt(PDO $conn, int $receipt_id): void {
    require_once __DIR__ . '/gl_posting.php';

    $st = $conn->prepare("SELECT id FROM receipts WHERE id=?");
    $st->execute([$receipt_id]);
    if (!$st->fetch(PDO::FETCH_ASSOC)) return;

    $journalSt = $conn->prepare("
      SELECT id
      FROM gl_journals
      WHERE source='receipt'
        AND source_id=?
        AND is_posted=1
        AND is_reversed=0
      ORDER BY id ASC
      FOR UPDATE
    ");
    $journalSt->execute([$receipt_id]);
    $activeJournalIds = array_map('intval', $journalSt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $reverseJournal = function(int $jid) use ($conn): void {
      try {
        $revExists = $conn->prepare("SELECT COUNT(*) FROM gl_journals WHERE source='reversal' AND source_id=? AND is_posted=1");
        $revExists->execute([$jid]);
        if ((int)$revExists->fetchColumn() === 0) {
          gl_reverse_journal($conn, $jid);
        } else {
          $conn->prepare("UPDATE gl_journals SET is_reversed=1 WHERE id=? AND is_reversed=0")->execute([$jid]);
        }
      } catch (RuntimeException $e) {
        error_log("Skipping receipt reversal for journal #{$jid}: " . $e->getMessage());
        $conn->prepare("UPDATE gl_journals SET is_reversed=1 WHERE id=? AND is_reversed=0")->execute([$jid]);
      }
    };

    $keptJournalId = null;
    foreach ($activeJournalIds as $jid) {
      if ($keptJournalId === null && function_exists('gl_receipt_journal_matches') && gl_receipt_journal_matches($conn, $jid, $receipt_id)) {
        $keptJournalId = $jid;
        continue;
      }
      $reverseJournal($jid);
    }

    $jid = $keptJournalId ?: gl_post_receipt($conn, $receipt_id);
    $conn->prepare("UPDATE receipts SET gl_journal_id=?, posted_at=NOW() WHERE id=?")
         ->execute([(int)$jid, $receipt_id]);
  }
}

/* ===========================================================
   1) AR: build/refresh invoice for an order
=========================================================== */
function ar_ensure_invoice_for_order(PDO $conn, int $order_id, ?int $user_id = null) {
  // Load order + client terms
  $sql = "SELECT mo.*, c.terms AS client_terms
            FROM make_order mo
       LEFT JOIN client c ON c.id = mo.client_id
           WHERE mo.id = :id";
  $st  = $conn->prepare($sql);
  $st->execute([':id'=>$order_id]);
  $mo = $st->fetch(PDO::FETCH_ASSOC);
  if (!$mo) return false;

  // Dates/terms
  $issue_date = $mo['service_date'] ?: substr((string)($mo['date'] ?: $mo['created_at']), 0, 10);
  $terms     = $mo['client_terms'] ?? 'cash';
  // Use shared AR helper so:
  // - 'cash' / 'prepaid' / empty => due date = issue date
  // - '15d' / '30d' / '45d' / '60d' => offset from issue date
  $due_date  = ar_compute_due_date($issue_date, $terms);

  // Find/create invoice shell
  $inv = $conn->prepare("SELECT * FROM invoices WHERE order_id = :oid");
  $inv->execute([':oid'=>$order_id]);
  $invoice = $inv->fetch(PDO::FETCH_ASSOC);

  if (!$invoice) {
    $invoice_no = 'INV-'.date('Y', strtotime($issue_date)).'-'.str_pad($order_id, 6, '0', STR_PAD_LEFT);
    $ins = $conn->prepare("
      INSERT INTO invoices
        (order_id, client_id, invoice_no, issue_date, due_date, terms, currency,
         subtotal, discount_amount, vat_rate, vat_amount, rounding, total,
         status, notes, created_at, created_by)
      VALUES
        (:order_id, :client_id, :invoice_no, :issue_date, :due_date, :terms, 'AED',
         0, :discount_amount, :vat_rate, 0, 0, 0,
         :status, NULL, NOW(), :uid)
    ");
    $status = ($mo['payment_status']==='paid') ? 'paid'
            : (($mo['payment_status']==='partially_paid') ? 'partially_paid'
            : (($mo['status']==='cancelled') ? 'void' : 'issued'));
    $ins->execute([
      ':order_id'        => $order_id,
      ':client_id'       => $mo['client_id'] ?: null,
      ':invoice_no'      => $invoice_no,
      ':issue_date'      => $issue_date,
      ':due_date'        => $due_date,
      ':terms'           => $terms,
      ':discount_amount' => (float)($mo['discount_amount'] ?? 0),
      ':vat_rate'        => (float)($mo['vat_rate'] ?? 5.00),
      ':status'          => $status,
      ':uid'             => $user_id,
    ]);
    $invoice_id = (int)$conn->lastInsertId();
    
    // Audit Log: Track invoice creation
    require_once __DIR__ . '/AuditService.php';
    AuditService::logCreate('invoices', $invoice_id, [
      'invoice_no' => $invoice_no,
      'order_id' => $order_id,
      'client_id' => $mo['client_id'],
      'issue_date' => $issue_date,
      'due_date' => $due_date,
      'terms' => $terms,
      'status' => $status,
      'total' => 0
    ], "Created invoice #{$invoice_no} for order #{$order_id}", $user_id);
  } else {
    $invoice_id = (int)$invoice['id'];
    $up = $conn->prepare("
      UPDATE invoices
         SET client_id = :client_id,
             issue_date = :issue_date,
             due_date = :due_date,
             terms = :terms,
             vat_rate = :vat_rate,
             discount_amount = :discount_amount,
             updated_at = NOW()
       WHERE id = :id
    ");
    $up->execute([
      ':client_id'       => $mo['client_id'] ?: null,
      ':issue_date'      => $issue_date,
      ':due_date'        => $due_date,
      ':terms'           => $terms,
      ':vat_rate'        => (float)($mo['vat_rate'] ?? 5.00),
      ':discount_amount' => (float)($mo['discount_amount'] ?? 0),
      ':id'              => $invoice_id,
    ]);
  }

  // (Re)build items
  $conn->prepare("DELETE FROM invoice_items WHERE invoice_id = :iid")
       ->execute([':iid'=>$invoice_id]);

  $svc = $conn->prepare("
    SELECT os.*, s.name AS service_name
      FROM order_services os
 LEFT JOIN services s ON s.id = os.service_id
     WHERE os.order_id = :oid
  ORDER BY os.id
  ");
  $svc->execute([':oid'=>$order_id]);
  $svc_rows = $svc->fetchAll(PDO::FETCH_ASSOC);

  $line_no          = 0;
  $subtotal         = 0.00;
  $vat_total        = 0.00;
  $header_vat_rate  = (float)($mo['vat_rate'] ?? 5.00);
  $vat_included     = isset($mo['vat_included']) ? (string)$mo['vat_included'] : 'yes';
  $should_add_vat   = ($vat_included === 'no');

  if ($svc_rows) {
    require_once __DIR__ . '/work_order_pricing_service.php';
    $computedTotals = wo_pricing_totals_from_order_services($conn, $order_id, $mo);

    // Use line-item sums for header totals (not stale make_order columns)
    $stored_sub  = $computedTotals['subtotal'] ?? (float)($mo['total'] ?? 0.00);
    $stored_vat  = $computedTotals['vat_amount'] ?? (float)($mo['vat_amount'] ?? 0.00);
    $stored_tot  = $computedTotals['grand_total'] ?? (float)($mo['grand_total'] ?? ($stored_sub + $stored_vat));

    if ($computedTotals && function_exists('wo_financial_is_locked') && !wo_financial_is_locked($conn, $order_id)) {
        wo_sync_order_totals_from_services($conn, $order_id);
    }
    
    // Calculate proportional VAT per line item
    $insItem = $conn->prepare("
      INSERT INTO invoice_items
        (invoice_id, line_no, description, qty, unit, unit_price,
         line_subtotal, vat_rate, vat_value, line_total)
      VALUES
        (:iid, :ln, :desc, :qty, :unit, :price, :sub, :vr, :vv, :tot)
    ");
    
    $proportional_vat_total = 0.00;
    $lines_data = [];
    
    foreach ($svc_rows as $r) {
      $line_no++;

      $desc = $r['service_name'] ?: 'Service';
      if (!empty($r['description'])) $desc .= ' — '.$r['description'];

      $qty   = (float)$r['qty'];
      $unit  = $r['unit'] ?: null;
      $price = (float)$r['unit_price'];
      $line_sub = round($qty * $price, 2);
      $vr    = is_null($r['vat_rate']) ? $header_vat_rate : (float)$r['vat_rate'];
      
      $lines_data[] = [
        'desc'=>$desc, 'qty'=>$qty, 'unit'=>$unit, 'price'=>$price,
        'sub'=>$line_sub, 'vr'=>$vr
      ];
    }
    
    // Distribute VAT proportionally across line items
    $final_line_no = 0;
    foreach ($lines_data as $idx => $line) {
      $final_line_no++;
      $line_proportion = $stored_sub > 0 ? $line['sub'] / $stored_sub : 1 / count($lines_data);
      $line_vat = round($stored_vat * $line_proportion, 2);
      
      // Last line gets any rounding difference
      if ($idx === count($lines_data) - 1) {
        $line_vat = round($stored_vat - $proportional_vat_total, 2);
      }
      $proportional_vat_total += $line_vat;
      
      $line_tot = round($line['sub'] + $line_vat, 2);
      $vat_total += $line_vat;

      $insItem->execute([
        ':iid'=>$invoice_id, ':ln'=>$final_line_no, ':desc'=>$line['desc'],
        ':qty'=>$line['qty'], ':unit'=>$line['unit'], ':price'=>$line['price'],
        ':sub'=>$line['sub'], ':vr'=>$line['vr'], ':vv'=>$line_vat, ':tot'=>$line_tot,
      ]);
    }
    
    // Use stored totals to ensure accuracy
    $subtotal = $stored_sub;
    $vat_total = $stored_vat;
  } else {
      // Fallback: use the order’s stored money (no re-compute)
      $wcnt = (int)single_val(
        $conn,
        "SELECT COUNT(*) FROM order_workers WHERE order_id = :oid",
        [':oid'=>$order_id]
      );
      if ($wcnt <= 0) $wcnt = 1;

      // Hours shown on the invoice (already includes all cleaners)
      $total_hours = (float)($mo['hours'] ?? 0);
      if ($total_hours <= 0 && isset($mo['net_hours'])) {
        $total_hours = (float)$mo['net_hours'] * $wcnt;
      }
      $qty         = round($total_hours, 2);
      $unit_price  = (float)($mo['hourly_rate'] ?: $mo['fee_charged'] ?: 0);

      // *** Use the stored order figures ***
      $sub         = (float)($mo['total']        ?? 0.00);      // net
      $vv          = (float)($mo['vat_amount']   ?? 0.00);      // vat
      $tot         = (float)($mo['grand_total']  ?? ($sub+$vv));// gross
      $vr          = (float)($mo['vat_rate']     ?? $header_vat_rate);

      $per_cleaner = isset($mo['net_hours']) ? (float)$mo['net_hours'] : null;
      $desc = ($per_cleaner !== null)
        ? "Cleaning service: {$wcnt} cleaner(s) × {$per_cleaner} h on {$issue_date}"
        : "Cleaning service: {$qty} h on {$issue_date}";

      $insItem = $conn->prepare("
        INSERT INTO invoice_items
          (invoice_id, line_no, description, qty, unit, unit_price,
           line_subtotal, vat_rate, vat_value, line_total)
        VALUES
          (:iid, 1, :desc, :qty, 'h', :price, :sub, :vr, :vv, :tot)
      ");
      $insItem->execute([
        ':iid'=>$invoice_id, ':desc'=>$desc, ':qty'=>$qty, ':price'=>$unit_price,
        ':sub'=>$sub, ':vr'=>$vr, ':vv'=>$vv, ':tot'=>$tot
      ]);

      $subtotal  += $sub;
      $vat_total += $vv;
  }

  // Header totals
  $discount = (float)($mo['discount_amount'] ?? 0);
  $rounding = 0.00;
  $total    = round($subtotal - $discount + $vat_total + $rounding, 2);

  $upH = $conn->prepare("
    UPDATE invoices
       SET subtotal = :sub,
           vat_amount = :vat,
           total = :tot,
           rounding = :rnd,
           updated_at = NOW()
     WHERE id = :iid
  ");
  $upH->execute([
    ':sub'=>$subtotal, ':vat'=>$vat_total, ':tot'=>$total,
    ':rnd'=>$rounding, ':iid'=>$invoice_id
  ]);

  // Post (or repost) to GL
  ar_post_or_repost_invoice($conn, $invoice_id);

  return $invoice_id;
}



/**
 * Return client ops cadence value. Example values in your UI: 'D','W','Bi-W','M'
 */
function ar_get_client_cadence(PDO $conn, int $clientId): ?string {
  $st = $conn->prepare("SELECT payment FROM client WHERE id=?"); // your 'Payment Type (Ops cadence)'
  $st->execute([$clientId]);
  $v = $st->fetchColumn();
  return $v !== false ? (string)$v : null;
}

/** True if client prefers batch (periodic) invoicing. */
function ar_client_is_batch(PDO $conn, int $clientId): bool {
  $cad = ar_get_client_cadence($conn, $clientId);
  return in_array($cad, ['W','Bi-W','M'], true);
}

/** Compute due date from client AR terms (client.terms) */
function ar_compute_due_date(string $issueDate, ?string $terms): string {
  $d = new DateTime($issueDate);
  $terms = strtolower((string)$terms);
  if ($terms === 'cash' || $terms === 'prepaid') {
    // same day - due date is the issue date
  } elseif ($terms === '15d') { $d->modify('+15 day');
  } elseif ($terms === '30d') { $d->modify('+30 day');
  } elseif ($terms === '45d') { $d->modify('+45 day');
  } elseif ($terms === '60d') { $d->modify('+60 day'); }
  return $d->format('Y-m-d');
}

/**
 * Generate a *single* invoice for many orders (same client).
 * - Picks all un-invoiced orders in [rangeStart, rangeEnd] unless $orderIds passed.
 * - Creates invoice header + one line per order (default) or a single summarized line.
 * - Updates make_order.invoice_id for all included orders.
 *
 * Returns the new invoice_id.
 */
function ar_generate_batch_invoice(
  PDO $conn,
  int $clientId,
  string $rangeStart,
  string $rangeEnd,
  array $orderIds = [],                  // optional explicit list
  string $itemization = 'single_line',     // 'per_order' | 'single_line'
  ?int $actorUserId = null
): int {
  require_once __DIR__ . '/work_order_batch_invoice_service.php';
  if (sm_hybrid_batch_enabled($conn)) {
    return sm_generate_hybrid_batch_invoice(
      $conn, $clientId, $rangeStart, $rangeEnd, $orderIds, $itemization, $actorUserId
    );
  }

  // --- Legacy: single invoice overwrites make_order.invoice_id ---
  // 1) Client info (for VAT rate + due date terms)
  $cst = $conn->prepare("
    SELECT client_name, email, address, mobile_num, terms, default_vat_rate
    FROM client WHERE id=?
  ");
  $cst->execute([$clientId]);
  $c = $cst->fetch(PDO::FETCH_ASSOC);
  if (!$c) throw new Exception('Client not found for batch invoice');

  $clientName = (string)$c['client_name'];
  $clientVatRate = (float)($c['default_vat_rate'] ?? 5.0);

  $issueDate  = date('Y-m-d');
  $dueDate    = ar_compute_due_date($issueDate, $c['terms'] ?? null);

  // 2) Pick eligible orders
  if ($orderIds) {
    $ph = implode(',', array_fill(0, count($orderIds), '?'));
    $st = $conn->prepare("
      SELECT id, svc_date_calc, hourly_rate, hours, total, vat_rate, vat_amount, grand_total,
             client_id, client_name, worker_name, start_time, end_time
      FROM make_order
      WHERE id IN ($ph)
        AND client_id = ?
        AND invoice_id IS NULL
        AND COALESCE(status,'') <> 'cancelled'
      ORDER BY svc_date_calc, id
    ");
    $params = $orderIds; $params[] = $clientId;
    $st->execute($params);
  } else {
    $st = $conn->prepare("
      SELECT id, svc_date_calc, hourly_rate, hours, total, vat_rate, vat_amount, grand_total,
             client_id, client_name, worker_name, start_time, end_time
      FROM make_order
      WHERE client_id = ?
        AND svc_date_calc BETWEEN ? AND ?
        AND invoice_id IS NULL
        AND COALESCE(status,'') <> 'cancelled'
      ORDER BY svc_date_calc, id
    ");
    $st->execute([$clientId, $rangeStart, $rangeEnd]);
  }
  $orders = $st->fetchAll(PDO::FETCH_ASSOC);
  if (!$orders) throw new Exception('No eligible orders to invoice');

  // 3) Sum money from orders
  $sumHours = 0.0; $sumSub = 0.0; $sumVat = 0.0; $sumTot = 0.0;
  foreach ($orders as $o) {
    $sumHours += (float)$o['hours'];
    $sumSub   += (float)$o['total'];
    $sumVat   += (float)$o['vat_amount'];
    $sumTot   += (float)$o['grand_total'];
  }
  $sumHours = round($sumHours, 2);
  $sumSub   = round($sumSub,   2);
  $sumVat   = round($sumVat,   2);
  $sumTot   = round($sumTot,   2);

  // 4) Decide VAT handling
  // If orders already carry VAT (>0), keep it. Otherwise recompute with client's VAT rate.
  $recomputeVat = ($sumVat <= 0.00001);

  $headerVatRate  = $recomputeVat ? $clientVatRate : ($orders[0]['vat_rate'] ?? $clientVatRate);
  $headerVatValue = $recomputeVat ? round($sumSub * ($headerVatRate/100), 2) : $sumVat;
  $headerTotal    = $recomputeVat ? round($sumSub + $headerVatValue, 2)     : $sumTot;

  // 5) Insert invoice header
  $conn->beginTransaction();
  try {
    // Generate invoice number: INV-YYYY-xxxxxx (standardized to 6 digits)
    $year = date('Y');
    $maxQ = $conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(invoice_no,'-',-1) AS UNSIGNED)) FROM invoices WHERE invoice_no LIKE ?");
    $maxQ->execute(["INV-$year-%"]);
    $next = (int)($maxQ->fetchColumn() ?: 0) + 1;
    $invoiceNo = sprintf("INV-%s-%06d", $year, $next); // Changed from %05d to %06d for consistency

    $ins = $conn->prepare("
      INSERT INTO invoices
        (client_id, invoice_no, issue_date, due_date, range_start, range_end,
         subtotal, vat_rate, vat_amount, total,
         status, notes, created_at, created_by)
      VALUES
        (:client_id, :invoice_no, :issue_date, :due_date, :rs, :re,
         :sub, :vr, :vat, :tot,
         'issued', :notes, NOW(), :uid)
    ");
    $notes = "Period: $rangeStart to $rangeEnd";
    $ins->execute([
      ':client_id' => $clientId,
      ':invoice_no'=> $invoiceNo,
      ':issue_date'=> $issueDate,
      ':due_date'  => $dueDate,
      ':rs'        => $rangeStart,
      ':re'        => $rangeEnd,
      ':sub'       => $sumSub,
      ':vr'        => $headerVatRate,
      ':vat'       => $headerVatValue,
      ':tot'       => $headerTotal,
      ':notes'     => $notes,
      ':uid'       => $actorUserId
    ]);
    $invoiceId = (int)$conn->lastInsertId();

    // Audit Log: Track batch invoice creation
    require_once __DIR__ . '/AuditService.php';
    AuditService::logCreate('invoices', $invoiceId, [
      'invoice_no' => $invoiceNo,
      'client_id' => $clientId,
      'issue_date' => $issueDate,
      'due_date' => $dueDate,
      'range_start' => $rangeStart,
      'range_end' => $rangeEnd,
      'subtotal' => $sumSub,
      'vat_rate' => $headerVatRate,
      'vat_amount' => $headerVatValue,
      'total' => $headerTotal,
      'status' => 'issued'
    ], "Created batch invoice #{$invoiceNo} for client #{$clientId} (period: {$rangeStart} to {$rangeEnd})");

    // 6) Lines
    $lineNo = 1;

    if ($itemization === 'single_line') {
      // One summarized line
      $desc = "Cleaning services $rangeStart – $rangeEnd ({$sumHours}h)";
      $unitPrice = $sumHours > 0 ? round($sumSub / $sumHours, 2) : 0.00;

      $li = $conn->prepare("
        INSERT INTO invoice_items
          (invoice_id, order_id, line_no, description, qty, unit, unit_price,
           line_subtotal, vat_rate, line_vat, line_total)
        VALUES (:iid, NULL, :ln, :d, :qty, 'hr', :price, :sub, :vr, :vat, :tot)
      ");
      $li->execute([
        ':iid'  => $invoiceId,
        ':ln'   => $lineNo++,
        ':d'    => $desc,
        ':qty'  => $sumHours,
        ':price'=> $unitPrice,
        ':sub'  => $sumSub,
        ':vr'   => $headerVatRate,
        ':vat'  => $headerVatValue,
        ':tot'  => $headerTotal
      ]);

    } else {
      // One line per order
      $li = $conn->prepare("
        INSERT INTO invoice_items
          (invoice_id, order_id, line_no, description, qty, unit, unit_price,
           line_subtotal, vat_rate, line_vat, line_total)
        VALUES (:iid, :oid, :ln, :d, :qty, 'hr', :price, :sub, :vr, :vat, :tot)
      ");

      // If we recompute VAT, distribute proportionally across lines
      $runningVat = 0.00;
      $n = count($orders);
      foreach ($orders as $idx => $o) {
        $lineSub  = (float)$o['total'];          // your 'total' is order subtotal before VAT
        $lineQty  = (float)$o['hours'];
        $lineRate = (float)$o['hourly_rate'];

        if ($recomputeVat) {
          // proportional share of the header VAT
          $lineVat = ($sumSub > 0) ? round($headerVatValue * ($lineSub / $sumSub), 2) : 0.00;

          // put rounding residue on the last line to make sums match header exactly
          if ($idx === $n - 1) {
            $lineVat = round($headerVatValue - $runningVat, 2);
          }
          $runningVat = round($runningVat + $lineVat, 2);

          $lineTot = round($lineSub + $lineVat, 2);
          $lineVr  = $headerVatRate;

        } else {
          // keep order's own VAT figures
          $lineVat = (float)$o['vat_amount'];
          $lineTot = (float)$o['grand_total'];
          $lineVr  = (float)($o['vat_rate'] ?? $headerVatRate);
        }

        $desc = sprintf(
          "Order #%d — %s %s–%s — %s",
          (int)$o['id'],
          (string)$o['svc_date_calc'],
          substr((string)$o['start_time'],0,5),
          substr((string)$o['end_time'],0,5),
          (string)$o['worker_name']
        );

        $li->execute([
          ':iid'   => $invoiceId,
          ':oid'   => (int)$o['id'],
          ':ln'    => $lineNo++,
          ':d'     => $desc,
          ':qty'   => $lineQty,
          ':price' => $lineRate,
          ':sub'   => $lineSub,
          ':vr'    => $lineVr,
          ':vat'   => $lineVat,
          ':tot'   => $lineTot,
        ]);
      }
    }

    // 7) Attach the invoice to all orders
    $upd = $conn->prepare("UPDATE make_order SET invoice_id = :iid WHERE id = :id");
    foreach ($orders as $o) {
      $upd->execute([':iid' => $invoiceId, ':id' => $o['id']]);
    }

    $conn->commit();
    return $invoiceId;

  } catch (Throwable $e) {
    $conn->rollBack();
    throw $e;
  }
}


/* ===========================================================
   2) Read helpers (used by invoice_view.php)
=========================================================== */
function ar_get_invoice(PDO $conn, int $invoice_id) {
  $sql = "
    SELECT i.*,
           c.client_name,
           c.address    AS client_address,
           c.mobile_num AS client_phone,
           c.email      AS client_email,
           c.trn        AS client_trn,
           COALESCE(SUM(ra.amount_applied),0) AS amount_paid
      FROM invoices i
 LEFT JOIN client c               ON c.id = i.client_id
 LEFT JOIN receipt_allocations ra ON ra.invoice_id = i.id
     WHERE i.id = ?
  GROUP BY i.id
  LIMIT 1";
  $st = $conn->prepare($sql);
  $st->execute([$invoice_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;
  $row['balance_due'] = (float)$row['total'] - (float)$row['amount_paid'];
  return $row;
}

function ar_get_invoice_items(PDO $conn, int $invoice_id): array {
  $st = $conn->prepare("SELECT * FROM invoice_items WHERE invoice_id=:id ORDER BY line_no, id");
  $st->execute([':id'=>$invoice_id]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function ar_get_invoice_payments(PDO $conn, int $invoice_id): array {
  $st = $conn->prepare("
    SELECT r.id AS receipt_id, r.receipt_no, r.receipt_date AS payment_date,
           r.method AS payment_method, r.deposit_account_no, ra.amount_applied AS amount, r.notes
      FROM receipt_allocations ra
INNER JOIN receipts r ON r.id = ra.receipt_id
     WHERE ra.invoice_id = :id
  ORDER BY r.receipt_date, r.id");
  $st->execute([':id'=>$invoice_id]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ===========================================================
   3) Totals + status
=========================================================== */
function ar_recalc_invoice_totals(PDO $conn, int $invoice_id): bool {
  $st = $conn->prepare("SELECT subtotal, discount_amount, vat_rate FROM invoices WHERE id=?");
  $st->execute([$invoice_id]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  if (!$r) return false;

  $subtotal  = (float)$r['subtotal'];
  $discount  = max(0, (float)$r['discount_amount']);
  $taxBase   = max(0, $subtotal - $discount);
  $vatRate   = (float)$r['vat_rate'];
  $vatAmount = round($taxBase * $vatRate / 100, 2);
  $total     = $taxBase + $vatAmount;

  $up = $conn->prepare("UPDATE invoices SET vat_amount=?, total=?, updated_at=NOW() WHERE id=?");
  $ok = $up->execute([$vatAmount, $total, $invoice_id]);

  if ($ok) {
    // ensure GL mirrors the change
    ar_post_or_repost_invoice($conn, $invoice_id);
  }
  return $ok;
}

/** Recompute paid/balance/status from allocations. */
if (!function_exists('ar_refresh_status_from_allocations')) {
  function ar_refresh_status_from_allocations(PDO $conn, int $invoice_id): void {
    $st = $conn->prepare("SELECT total FROM invoices WHERE id=?");
    $st->execute([$invoice_id]);
    $total = (float)$st->fetchColumn();

    $paid = (float)single_val(
      $conn,
      "SELECT COALESCE(SUM(amount_applied),0) FROM receipt_allocations WHERE invoice_id=:id",
      [':id'=>$invoice_id]
    );

    $balance = max(0, $total - $paid);
    $status  = ($paid >= $total && $total > 0) ? 'paid'
             : (($paid > 0) ? 'partially_paid' : 'issued');

    $up = $conn->prepare("
      UPDATE invoices
         SET amount_paid=:paid, balance_due=:bal, status=:st,
             paid_at = CASE WHEN :st='paid' THEN NOW() ELSE paid_at END,
             updated_at=NOW()
       WHERE id=:id
    ");
    $up->execute([':paid'=>$paid, ':bal'=>$balance, ':st'=>$status, ':id'=>$invoice_id]);
  }
}

/* ===========================================================
   4) Set / edit discount safely (used by ajax_set_invoice_discount.php)
=========================================================== */
if (!function_exists('ar_set_invoice_discount')) {
  function ar_set_invoice_discount(PDO $conn, int $invoice_id, float $discount): bool {
    if ($discount < 0) $discount = 0.0;
    $up = $conn->prepare("UPDATE invoices SET discount_amount=?, updated_at=NOW() WHERE id=?");
    $ok = $up->execute([$discount, $invoice_id]);
    if ($ok) {
      ar_recalc_invoice_totals($conn, $invoice_id); // this will also repost to GL
    }
    return $ok;
  }
}

/* ===========================================================
   5) Add a payment (receipt), allocate, post to GL
=========================================================== */
// includes/ar_helpers.php  (replace ar_add_payment with this)
function ar_add_payment(PDO $conn, int $invoice_id, string $pay_date, float $amount,
                        string $method = 'cash', string $notes = '', ?int $user_id = null,
                        ?string $deposit_account_no = null): array {
  // Return format: ['success' => bool, 'message' => string]
  if ($amount <= 0) {
    return ['success' => false, 'message' => 'Payment amount must be greater than 0'];
  }
  $dep = trim((string)($deposit_account_no ?? ''));
  if ($dep === '') {
    return ['success' => false, 'message' => 'Deposit account is required.'];
  }
  try {
    cleaning_validate_payment_account_no($conn, $dep);
  } catch (Throwable $e) {
    return ['success' => false, 'message' => $e->getMessage()];
  }

  require_once __DIR__ . '/work_order_batch_invoice_service.php';
  if (sm_invoice_is_batch_summary($conn, $invoice_id)) {
    return [
      'success' => false,
      'message' => 'This is a batch statement (BINV) for the client only. Allocate payment to the child job invoices (INV-) listed inside this BINV.',
    ];
  }

  try {
    $conn->beginTransaction();

    // Lock invoice and get totals
    $st = $conn->prepare("SELECT client_id, total FROM invoices WHERE id = :id FOR UPDATE");
    $st->execute([':id'=>$invoice_id]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv) { 
      $conn->rollBack(); 
      return ['success' => false, 'message' => 'Invoice not found'];
    }

    // How much has already been applied to THIS invoice?
    $paid_so_far = (float)single_val(
      $conn,
      "SELECT COALESCE(SUM(amount_applied),0) FROM receipt_allocations WHERE invoice_id = :id",
      [':id'=>$invoice_id]
    );
    $balance_due = max(0.0, (float)$inv['total'] - $paid_so_far);

    // Create the receipt for the FULL amount received
    $receipt_no = 'RCT-'.date('YmdHis').'-'.mt_rand(100,999);
    $insR = $conn->prepare("
      INSERT INTO receipts
        (client_id, receipt_no, receipt_date, method, deposit_account_no, reference, amount, notes, created_at, created_by)
      VALUES
        (:cid, :no, :dt, :m, :dep, NULL, :amt, :n, NOW(), :uid)
    ");
    $insR->execute([
      ':cid'=>$inv['client_id'] ?: null,
      ':no'=>$receipt_no,
      ':dt'=>$pay_date,
      ':m'=>$method,
      ':dep'=>$dep,
      ':amt'=>$amount,
      ':n'=>$notes,
      ':uid'=>$user_id
    ]);
    $receipt_id = (int)$conn->lastInsertId();

    // Allocate only up to the balance due
    $apply = min($amount, $balance_due);
    if ($apply > 0) {
      $insA = $conn->prepare("
        INSERT INTO receipt_allocations (receipt_id, invoice_id, amount_applied)
        VALUES (:rid, :iid, :amt)
      ");
      $insA->execute([':rid'=>$receipt_id, ':iid'=>$invoice_id, ':amt'=>$apply]);
    }
    // Any remainder (amount - apply) stays UNAPPLIED credit on this receipt.

    // Post the full receipt to GL (uses receipts.deposit_account_no)
    ar_post_or_repost_receipt($conn, $receipt_id);

    // Refresh invoice status from allocations
    ar_refresh_status_from_allocations($conn, $invoice_id);

    // Invoice AR subledger must match GL — fail the whole payment if repost fails
    ar_post_or_repost_invoice($conn, $invoice_id);

    require_once __DIR__ . '/accounting_health_service.php';
    accounting_health_assert_receipt($conn, $receipt_id);
    accounting_health_assert_invoice($conn, $invoice_id);

    // Audit Log: Track payment addition
    require_once __DIR__ . '/AuditService.php';
    AuditService::log([
      'action' => 'insert',
      'object_type' => 'receipts',
      'object_id' => (string)$receipt_id,
      'summary' => "Added payment of " . number_format($amount, 2) . " AED to invoice #{$invoice_id} via {$method}",
      'new_data' => [
        'receipt_no' => $receipt_no,
        'receipt_date' => $pay_date,
        'method' => $method,
        'amount' => $amount,
        'notes' => $notes,
        'invoice_id' => $invoice_id,
        'amount_applied' => $apply,
        'balance_due' => $balance_due
      ],
      'success' => true
    ]);

    $conn->commit();
    return ['success' => true, 'message' => 'Payment recorded successfully'];
  } catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log("ar_add_payment error: " . $e->getMessage() . " | Invoice: {$invoice_id} | Amount: {$amount}");
    return ['success' => false, 'message' => 'Failed to record payment: ' . $e->getMessage()];
  }
}




function validateAvailability(PDO $conn, int $workerId, string $svcDate, string $start, string $end, array &$errors): bool {
    $ok = true;

    // 1. Check time-off
    $st = $conn->prepare("SELECT 1 FROM worker_unavailability 
        WHERE worker_id=? AND `date`=? 
          AND NOT (end_time <= ? OR start_time >= ?)");
    $st->execute([$workerId, $svcDate, $start, $end]);
    if ($st->fetch()) {
        $errors[] = "Worker has time-off during this slot";
        $ok = false;
    }

    // 2. Check overlapping jobs
    $st = $conn->prepare("SELECT 1 FROM make_order mo
        JOIN order_workers ow ON ow.order_id=mo.id
        WHERE ow.worker_id=? AND mo.svc_date_calc=? 
          AND mo.status NOT IN ('cancelled')
          AND NOT (mo.end_time <= ? OR mo.start_time >= ?)");
    $st->execute([$workerId, $svcDate, $start, $end]);
    if ($st->fetch()) {
        $errors[] = "Worker already has a job during this slot";
        $ok = false;
    }

    // 3. Check travel gap
    $gap = (int)getRequiredGapMinutes($conn); // your global gap function
    if ($gap > 0) {
        $st = $conn->prepare("SELECT start_time, end_time FROM make_order mo
            JOIN order_workers ow ON ow.order_id=mo.id
            WHERE ow.worker_id=? AND mo.svc_date_calc=?");
        $st->execute([$workerId, $svcDate]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (abs(strtotime($start) - strtotime($row['end_time'])) < $gap*60 ||
                abs(strtotime($row['start_time']) - strtotime($end)) < $gap*60) {
                $errors[] = "Not enough travel buffer (requires {$gap} min)";
                $ok = false;
                break;
            }
        }
    }

    return $ok;
}



/* =========================
   Client credit (unapplied receipts)
   ========================= */

/** Sum of client's receipts minus what’s allocated from those receipts. */
function ar_get_client_unapplied_credit(PDO $conn, int $client_id): float {
  $total = (float)single_val($conn,
    "SELECT COALESCE(SUM(amount),0) FROM receipts WHERE client_id=:cid",
    [':cid'=>$client_id]
  );
  $applied = (float)single_val($conn, "
    SELECT COALESCE(SUM(ra.amount_applied),0)
      FROM receipt_allocations ra
      JOIN receipts r ON r.id = ra.receipt_id
     WHERE r.client_id = :cid", [':cid'=>$client_id]
  );
  return round($total - $applied, 2);
}

/** List receipts for a client with their remaining (unapplied) amount. */
function ar_list_unapplied_receipts(PDO $conn, int $client_id): array {
  $sql = "
    SELECT r.id, r.receipt_no, r.receipt_date, r.method, r.amount,
           (r.amount - COALESCE(a.applied,0)) AS remaining
      FROM receipts r
      LEFT JOIN (
        SELECT receipt_id, SUM(amount_applied) AS applied
          FROM receipt_allocations
         GROUP BY receipt_id
      ) a ON a.receipt_id = r.id
     WHERE r.client_id = :cid
       AND (r.amount - COALESCE(a.applied,0)) > 0
     ORDER BY r.receipt_date DESC, r.id DESC";
  $st = $conn->prepare($sql);
  $st->execute([':cid'=>$client_id]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Apply credit from one receipt to one invoice (caps to min(balance, remaining)). */
function ar_apply_credit(PDO $conn, int $invoice_id, int $receipt_id, float $amount): bool {
  if ($amount <= 0) return false;

  try {
    $ownsTxn = !$conn->inTransaction();
    if ($ownsTxn) $conn->beginTransaction();

    // Lock invoice
    $invSt = $conn->prepare("SELECT client_id, total FROM invoices WHERE id=? FOR UPDATE");
    $invSt->execute([$invoice_id]);
    $inv = $invSt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) { if ($ownsTxn) $conn->rollBack(); return false; }

    // Only allow same client (or null client)
    $rSt = $conn->prepare("SELECT client_id, amount FROM receipts WHERE id=? FOR UPDATE");
    $rSt->execute([$receipt_id]);
    $r = $rSt->fetch(PDO::FETCH_ASSOC);
    if (!$r) { if ($ownsTxn) $conn->rollBack(); return false; }
    if ($inv['client_id'] && $r['client_id'] && ((int)$inv['client_id'] !== (int)$r['client_id'])) {
      if ($ownsTxn) $conn->rollBack(); return false; // safety
    }

    // Remaining on receipt
    $appliedOnReceipt = (float)single_val($conn,
      "SELECT COALESCE(SUM(amount_applied),0) FROM receipt_allocations WHERE receipt_id=?",
      [$receipt_id]
    );
    $remaining = max(0.0, (float)$r['amount'] - $appliedOnReceipt);

    // Balance on invoice
    $paidOnInvoice = (float)single_val($conn,
      "SELECT COALESCE(SUM(amount_applied),0) FROM receipt_allocations WHERE invoice_id=?",
      [$invoice_id]
    );
    $balance = max(0.0, (float)$inv['total'] - $paidOnInvoice);

    $apply = min($amount, $remaining, $balance);
    if ($apply <= 0) { if ($ownsTxn) $conn->rollBack(); return false; }

    $insA = $conn->prepare("INSERT INTO receipt_allocations (receipt_id, invoice_id, amount_applied) VALUES (:rid, :iid, :amt)");
    $insA->execute([':rid'=>$receipt_id, ':iid'=>$invoice_id, ':amt'=>$apply]);

    // Refresh invoice + keep AR control tidy
    ar_refresh_status_from_allocations($conn, $invoice_id);
    ar_post_or_repost_invoice($conn, $invoice_id);

    require_once __DIR__ . '/accounting_health_service.php';
    accounting_health_assert_receipt($conn, $receipt_id);
    accounting_health_assert_invoice($conn, $invoice_id);

    if ($ownsTxn) $conn->commit();
    return true;
  } catch (Throwable $e) {
    if (isset($ownsTxn) && $ownsTxn && $conn->inTransaction()) {
      $conn->rollBack();
      return false;
    }
    throw $e;
    return false;
  }
}

/* -----------------------------------------------------------
   Client credit & open-invoice helpers (for client profile)
----------------------------------------------------------- */

/** Total unapplied receipts for a client (available credit) */
function ar_client_available_credit(PDO $conn, int $client_id): float {
  $sql = "
    SELECT COALESCE(SUM(r.amount) - SUM(COALESCE(ra_applied.applied,0)), 0) AS credit
      FROM receipts r
 LEFT JOIN (
        SELECT receipt_id, SUM(amount_applied) AS applied
          FROM receipt_allocations
         GROUP BY receipt_id
      ) ra_applied ON ra_applied.receipt_id = r.id
     WHERE r.client_id = :cid
  ";
  $st = $conn->prepare($sql);
  $st->execute([':cid'=>$client_id]);
  return (float)$st->fetchColumn();
}

/** Unapplied receipts (each line shows remaining that can still be used) */
function ar_client_unapplied_receipts(PDO $conn, int $client_id): array {
  $sql = "
    SELECT r.id, r.receipt_no, r.receipt_date, r.method, r.amount,
           (r.amount - COALESCE(SUM(ra.amount_applied),0)) AS remaining
      FROM receipts r
 LEFT JOIN receipt_allocations ra ON ra.receipt_id = r.id
     WHERE r.client_id = :cid
  GROUP BY r.id
    HAVING remaining > 0
  ORDER BY r.receipt_date DESC, r.id DESC
  ";
  $st = $conn->prepare($sql);
  $st->execute([':cid'=>$client_id]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Open invoices for a client with the current balance */
function ar_client_open_invoices(PDO $conn, int $client_id): array {
  $sql = "
    SELECT i.id, i.invoice_no, i.issue_date,
           i.total - COALESCE(SUM(ra.amount_applied),0) AS balance
      FROM invoices i
 LEFT JOIN receipt_allocations ra ON ra.invoice_id = i.id
     WHERE i.client_id = :cid
       AND i.status IN ('issued','partially_paid')
  GROUP BY i.id
    HAVING balance > 0
  ORDER BY i.issue_date DESC, i.id DESC
  ";
  $st = $conn->prepare($sql);
  $st->execute([':cid'=>$client_id]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
