<?php
// includes/gl_posting.php
// One canonical set of GL helpers + posting functions (invoice, receipt, expense)

require_once __DIR__.'/db_connect.php';

/* =========================
   Shared helpers
   ========================= */

/**
 * Get COA id by account_no (throws if missing)
 * 
 * This function retrieves the chart of accounts ID for a given account number.
 * It throws an exception if the account doesn't exist, ensuring all GL postings
 * reference valid accounts.
 * 
 * @param PDO $db Database connection
 * @param string $account_no Account number (e.g., '1110', '4010')
 * @return int Account ID from chart_of_accounts table
 * @throws RuntimeException If account not found
 */
function coa_id(PDO $db, string $account_no): int {
  $st = $db->prepare("SELECT id, name, type, is_active FROM chart_of_accounts WHERE account_no=? LIMIT 1");
  $st->execute([$account_no]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row || (int)$row['id'] <= 0) {
    throw new RuntimeException("Chart of Accounts account not found: '{$account_no}'. Please create this account in the Chart of Accounts first.");
  }
  if (!$row['is_active']) {
    throw new RuntimeException("Chart of Accounts account '{$account_no}' ({$row['name']}) is inactive. Please activate it in the Chart of Accounts.");
  }
  return (int)$row['id'];
}

function gl_column_exists(PDO $db, string $table, string $column): bool {
  static $cache = [];
  $key = "{$table}.{$column}";
  if (array_key_exists($key, $cache)) return $cache[$key];

  $st = $db->prepare("
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
  ");
  $st->execute([$table, $column]);
  return $cache[$key] = ((int)$st->fetchColumn() > 0);
}

/**
 * Insert a posted journal and its lines; also bump period balances. Returns journal id.
 * Transaction-aware: only opens/commits if the caller hasn't already.
 */
function gl_create_journal(PDO $db, array $j, array $lines): int {
  // $j = ['date' => 'YYYY-MM-DD', 'source' => 'invoice|receipt|expense|reversal',
  //       'source_id' => int|null, 'memo' => string|null, 'created_by' => int|null,
  //       'company_id' => int|null]
  $ownsTxn = !$db->inTransaction();
  if ($ownsTxn) $db->beginTransaction();

  try {
    // Make journal header
    $jrno = strtoupper(substr($j['source'],0,3)).'-'.date('Ymd').'-'.mt_rand(100,999);
    $hasCompany = gl_column_exists($db, 'gl_journals', 'company_id');
    if ($hasCompany) {
      $insH = $db->prepare("
        INSERT INTO gl_journals (journal_no, journal_date, source, source_id, memo, is_posted, is_reversed, created_by, company_id)
        VALUES (?,?,?,?,?,1,0,?,?)
      ");
      $insH->execute([
        $jrno,
        $j['date'],
        $j['source'],
        $j['source_id'] ?? null,
        $j['memo'] ?? null,
        $j['created_by'] ?? null,
        (int)($j['company_id'] ?? 1),
      ]);
    } else {
      $insH = $db->prepare("
        INSERT INTO gl_journals (journal_no, journal_date, source, source_id, memo, is_posted, is_reversed, created_by)
        VALUES (?,?,?,?,?,1,0,?)
      ");
      $insH->execute([$jrno, $j['date'], $j['source'], $j['source_id'] ?? null, $j['memo'] ?? null, $j['created_by'] ?? null]);
    }
    $jid = (int)$db->lastInsertId();

    $insL = $db->prepare("
      INSERT INTO gl_journal_lines (journal_id, line_no, account_id, description, debit, credit)
      VALUES (?,?,?,?,?,?)
    ");
    $period = substr($j['date'],0,7); // YYYY-MM
    $bal = $db->prepare("
      INSERT INTO gl_account_balances (account_id, period, debit, credit)
      VALUES (?,?,?,?)
      ON DUPLICATE KEY UPDATE debit=debit+VALUES(debit), credit=credit+VALUES(credit)
    ");

    $ln = 1; $sumDr = 0.0; $sumCr = 0.0;
    foreach ($lines as $L) {
      $debit  = round((float)($L['debit']  ?? 0), 2);
      $credit = round((float)($L['credit'] ?? 0), 2);
      $accId  = (int)$L['account_id'];
      $desc   = $L['desc'] ?? null;

      $insL->execute([$jid, $ln++, $accId, $desc, $debit, $credit]);
      $bal->execute([$accId, $period, $debit, $credit]);

      $sumDr += $debit; $sumCr += $credit;
    }

    if (round($sumDr,2) !== round($sumCr,2)) {
      throw new RuntimeException("Journal not balanced (Dr {$sumDr} ≠ Cr {$sumCr})");
    }

    if ($ownsTxn) $db->commit();
    return $jid;
  } catch (Throwable $e) {
    if ($ownsTxn && $db->inTransaction()) $db->rollBack();
    throw $e;
  }
}

/**
 * Mirror all lines into a new posted journal; mark original as reversed. Returns new journal id.
 * Transaction-aware like gl_create_journal.
 */
function gl_reverse_journal(PDO $db, int $journal_id): int {
  $ownsTxn = !$db->inTransaction();
  if ($ownsTxn) $db->beginTransaction();

  try {
    $h = $db->prepare("SELECT * FROM gl_journals WHERE id=? FOR UPDATE");
    $h->execute([$journal_id]);
    $j = $h->fetch(PDO::FETCH_ASSOC);
    if (!$j) throw new RuntimeException("Journal not found: {$journal_id}");
    
    // CRITICAL: Prevent double-reversal - ALWAYS check if a reversal already exists
    // This check happens regardless of is_reversed flag to catch race conditions and data inconsistencies
    $revCheck = $db->prepare("SELECT id, journal_no, is_posted FROM gl_journals WHERE source='reversal' AND source_id=? AND is_posted=1 LIMIT 1");
    $revCheck->execute([$journal_id]);
    $existingRev = $revCheck->fetch(PDO::FETCH_ASSOC);
    if ($existingRev) {
      if ($ownsTxn && $db->inTransaction()) $db->rollBack();
      throw new RuntimeException("Journal #{$journal_id} is already reversed (reversal journal #{$existingRev['id']} - {$existingRev['journal_no']} exists). Cannot reverse again.");
    }
    
    // Also check if journal is already marked as reversed (even if reversal journal doesn't exist - data inconsistency)
    if ((int)$j['is_reversed'] === 1) {
      if ($ownsTxn && $db->inTransaction()) $db->rollBack();
      throw new RuntimeException("Journal #{$journal_id} is already marked as reversed. Cannot reverse again.");
    }
    
    // Also prevent reversing a reversal journal itself
    if ($j['source'] === 'reversal') {
      if ($ownsTxn && $db->inTransaction()) $db->rollBack();
      throw new RuntimeException("Cannot reverse a reversal journal (#{$journal_id}). Reversal journals cannot be reversed.");
    }

    $revNo = 'REV-'.date('Ymd').'-'.mt_rand(100,999);
    $memo = 'Auto reversal of J#'.$journal_id.(isset($j['memo']) ? ' — '.$j['memo'] : '');
    if (gl_column_exists($db, 'gl_journals', 'company_id')) {
      $insH = $db->prepare("
        INSERT INTO gl_journals (journal_no, journal_date, source, source_id, memo, is_posted, is_reversed, created_by, company_id)
        VALUES (?,?,?,?,?,1,0,?,?)
      ");
      $insH->execute([$revNo, $j['journal_date'], 'reversal', $journal_id, $memo, $j['created_by'] ?? null, (int)($j['company_id'] ?? 1)]);
    } else {
      $insH = $db->prepare("
        INSERT INTO gl_journals (journal_no, journal_date, source, source_id, memo, is_posted, is_reversed, created_by)
        VALUES (?,?,?,?,?,1,0,?)
      ");
      $insH->execute([$revNo, $j['journal_date'], 'reversal', $journal_id, $memo, $j['created_by'] ?? null]);
    }
    $newId = (int)$db->lastInsertId();

    $L = $db->prepare("SELECT account_id, description, debit, credit FROM gl_journal_lines WHERE journal_id=? ORDER BY line_no");
    $L->execute([$journal_id]);
    $rows = $L->fetchAll(PDO::FETCH_ASSOC);

    $insL = $db->prepare("INSERT INTO gl_journal_lines (journal_id,line_no,account_id,description,debit,credit) VALUES (?,?,?,?,?,?)");
    $period = substr($j['journal_date'],0,7);
    $bal = $db->prepare("
      INSERT INTO gl_account_balances (account_id, period, debit, credit)
      VALUES (?,?,?,?)
      ON DUPLICATE KEY UPDATE debit=debit+VALUES(debit), credit=credit+VALUES(credit)
    ");

    $n=1;
    foreach ($rows as $r) {
      $dr = round((float)$r['credit'],2);
      $cr = round((float)$r['debit'],2);
      $insL->execute([$newId,$n++,(int)$r['account_id'],$r['description'],$dr,$cr]);
      $bal->execute([(int)$r['account_id'],$period,$dr,$cr]);
    }

    $db->prepare("UPDATE gl_journals SET is_reversed=1 WHERE id=?")->execute([$journal_id]);

    if ($ownsTxn) $db->commit();
    return $newId;
  } catch (Throwable $e) {
    if ($ownsTxn && $db->inTransaction()) $db->rollBack();
    throw $e;
  }
}

/* =========================
   Invoices (AR)
   ========================= */

function gl_invoice_expected_journal(PDO $db, int $invoice_id): array {
  $st = $db->prepare("SELECT i.*, c.client_name FROM invoices i LEFT JOIN client c ON c.id=i.client_id WHERE i.id=?");
  $st->execute([$invoice_id]);
  $inv = $st->fetch(PDO::FETCH_ASSOC);
  if (!$inv) throw new RuntimeException("Invoice not found: {$invoice_id}");

  // Get account IDs - these account numbers must exist in chart_of_accounts
  $ar     = coa_id($db,'1110'); // Trade Receivables (Asset)
  $rev    = coa_id($db,'4010'); // Cleaning Services Revenue (Revenue)
  $disc   = coa_id($db,'4100'); // Discounts Allowed (Contra-Revenue)
  $vatOut = coa_id($db,'2210'); // VAT Payable (Output VAT - Liability)

  $subtotal = (float)$inv['subtotal'];
  $vat      = (float)$inv['vat_amount'];
  $discount = (float)$inv['discount_amount'];
  $total    = (float)$inv['total'];  // subtotal - discount + vat

  $lines = [
    ['account_id'=>$ar,  'desc'=>"AR Invoice {$inv['invoice_no']}",     'debit'=>$total,    'credit'=>0],
    ['account_id'=>$rev, 'desc'=>"Revenue Invoice {$inv['invoice_no']}",'debit'=>0,         'credit'=>$subtotal],
  ];
  if ($discount > 0) $lines[] = ['account_id'=>$disc, 'desc'=>"Discount {$inv['invoice_no']}", 'debit'=>$discount, 'credit'=>0];
  if ($vat > 0)      $lines[] = ['account_id'=>$vatOut,'desc'=>"VAT Output {$inv['invoice_no']}",'debit'=>0, 'credit'=>$vat];

  return [
    'invoice' => $inv,
    'lines' => $lines,
  ];
}

function gl_invoice_journal_matches(PDO $db, int $journal_id, int $invoice_id): bool {
  $expected = gl_invoice_expected_journal($db, $invoice_id);
  $expectedByAccount = [];
  foreach ($expected['lines'] as $line) {
    $accountId = (int)$line['account_id'];
    if (!isset($expectedByAccount[$accountId])) {
      $expectedByAccount[$accountId] = ['debit' => 0.0, 'credit' => 0.0];
    }
    $expectedByAccount[$accountId]['debit'] += round((float)$line['debit'], 2);
    $expectedByAccount[$accountId]['credit'] += round((float)$line['credit'], 2);
  }

  $st = $db->prepare("
    SELECT account_id, ROUND(SUM(debit),2) AS debit, ROUND(SUM(credit),2) AS credit
    FROM gl_journal_lines
    WHERE journal_id = ?
    GROUP BY account_id
  ");
  $st->execute([$journal_id]);
  $actualRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if (count($actualRows) !== count($expectedByAccount)) return false;

  foreach ($actualRows as $row) {
    $accountId = (int)$row['account_id'];
    if (!isset($expectedByAccount[$accountId])) return false;
    if (abs((float)$row['debit'] - (float)$expectedByAccount[$accountId]['debit']) > 0.01) return false;
    if (abs((float)$row['credit'] - (float)$expectedByAccount[$accountId]['credit']) > 0.01) return false;
  }

  return true;
}

/**
 * Post an invoice to the General Ledger.
 */
function gl_post_invoice(PDO $db, int $invoice_id): int {
  $expected = gl_invoice_expected_journal($db, $invoice_id);
  $inv = $expected['invoice'];
  $lines = $expected['lines'];

  return gl_create_journal($db, [
    'date'      => $inv['issue_date'],
    'source'    => 'invoice',
    'source_id' => $invoice_id,
    'memo'      => "Invoice {$inv['invoice_no']} {$inv['client_name']}",
    'created_by'=> $inv['created_by'] ?? null,
    'company_id' => $inv['company_id'] ?? 1,
  ], $lines);
}

function gl_find_invoice_journals(PDO $db, int $invoice_id): array {
  $q = $db->prepare("SELECT id FROM gl_journals WHERE source='invoice' AND source_id=? AND is_posted=1 AND is_reversed=0");
  $q->execute([$invoice_id]);
  return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}

function gl_repost_invoice(PDO $db, int $invoice_id): void {
  $journalIds = gl_find_invoice_journals($db, $invoice_id);
  
  foreach ($journalIds as $jid) {
    try {
      // Double-check this journal hasn't been reversed already (race condition protection)
      $checkSt = $db->prepare("SELECT is_reversed FROM gl_journals WHERE id=? FOR UPDATE");
      $checkSt->execute([$jid]);
      $journal = $checkSt->fetch(PDO::FETCH_ASSOC);
      
      if ($journal && (int)$journal['is_reversed'] === 0) {
        // Check if a reversal already exists (additional safety check)
        $revExists = $db->prepare("SELECT COUNT(*) FROM gl_journals WHERE source='reversal' AND source_id=? AND is_posted=1");
        $revExists->execute([$jid]);
        if ($revExists->fetchColumn() == 0) {
          gl_reverse_journal($db, $jid);
        } else {
          // Reversal already exists, just mark the original as reversed if not already
          $db->prepare("UPDATE gl_journals SET is_reversed=1 WHERE id=? AND is_reversed=0")->execute([$jid]);
        }
      }
    } catch (RuntimeException $e) {
      // If reversal already exists or journal already reversed, log and continue
      // This prevents duplicate reversals from being created
      error_log("Skipping reversal for journal #{$jid}: " . $e->getMessage());
      // Mark as reversed if not already (data consistency)
      $db->prepare("UPDATE gl_journals SET is_reversed=1 WHERE id=? AND is_reversed=0")->execute([$jid]);
    }
  }
  
  gl_post_invoice($db, $invoice_id);
}

/* =========================
   Receipts (cash in)
   ========================= */

/**
 * Post a receipt (payment) to the General Ledger
 * 
 * Creates a journal entry:
 * - Dr Cash/Bank Account (default 1020) - cash received
 * - Cr Accounts Receivable (1110) - reduces AR balance
 * 
 * Account numbers used:
 * - 1020: Bank Account (default, Asset, debit normal balance)
 * - 1010: Cash Account (if specified, Asset, debit normal balance)
 * - 1110: Trade Receivables (Asset, debit normal balance)
 * 
 * @param PDO $db Database connection
 * @param int $receipt_id Receipt ID to post
 * @param string|null $legacy_bank_account_no If receipt.deposit_account_no is NULL (legacy rows), use this; if also null, infer cash→1010 else 1020 from method.
 * @return int Journal ID
 * @throws RuntimeException If receipt not found or accounts missing
 */
function gl_receipt_expected_journal(PDO $db, int $receipt_id, ?string $legacy_bank_account_no = null): array {
  $st = $db->prepare("SELECT r.*, c.client_name FROM receipts r LEFT JOIN client c ON c.id=r.client_id WHERE r.id=?");
  $st->execute([$receipt_id]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  if (!$r) throw new RuntimeException("Receipt not found: {$receipt_id}");

  $stored = trim((string)($r['deposit_account_no'] ?? ''));
  if ($stored !== '') {
    $bank_account_no = $stored;
  } elseif ($legacy_bank_account_no !== null && trim($legacy_bank_account_no) !== '') {
    $bank_account_no = trim($legacy_bank_account_no);
  } else {
    $m = strtolower(trim((string)($r['method'] ?? '')));
    $bank_account_no = ($m === 'cash') ? '1010' : '1020';
  }

  $bank = coa_id($db, $bank_account_no); // 1010/1020/1030/1040... (Cash/Bank accounts)
  $ar   = coa_id($db, '1110'); // Trade Receivables

  $amt = (float)$r['amount'];
  $lines = [
    ['account_id'=>$bank, 'desc'=>"Receipt {$r['receipt_no']}", 'debit'=>$amt, 'credit'=>0],
    ['account_id'=>$ar,   'desc'=>"Receipt {$r['receipt_no']}", 'debit'=>0,    'credit'=>$amt],
  ];

  return [
    'receipt' => $r,
    'lines' => $lines,
  ];
}

function gl_receipt_journal_matches(PDO $db, int $journal_id, int $receipt_id): bool {
  $expected = gl_receipt_expected_journal($db, $receipt_id);
  $expectedByAccount = [];
  foreach ($expected['lines'] as $line) {
    $accountId = (int)$line['account_id'];
    if (!isset($expectedByAccount[$accountId])) {
      $expectedByAccount[$accountId] = ['debit' => 0.0, 'credit' => 0.0];
    }
    $expectedByAccount[$accountId]['debit'] += round((float)$line['debit'], 2);
    $expectedByAccount[$accountId]['credit'] += round((float)$line['credit'], 2);
  }

  $st = $db->prepare("
    SELECT account_id, ROUND(SUM(debit),2) AS debit, ROUND(SUM(credit),2) AS credit
    FROM gl_journal_lines
    WHERE journal_id = ?
    GROUP BY account_id
  ");
  $st->execute([$journal_id]);
  $actualRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if (count($actualRows) !== count($expectedByAccount)) return false;

  foreach ($actualRows as $row) {
    $accountId = (int)$row['account_id'];
    if (!isset($expectedByAccount[$accountId])) return false;
    if (abs((float)$row['debit'] - (float)$expectedByAccount[$accountId]['debit']) > 0.01) return false;
    if (abs((float)$row['credit'] - (float)$expectedByAccount[$accountId]['credit']) > 0.01) return false;
  }

  return true;
}

function gl_post_receipt(PDO $db, int $receipt_id, ?string $legacy_bank_account_no = null): int {
  $expected = gl_receipt_expected_journal($db, $receipt_id, $legacy_bank_account_no);
  $r = $expected['receipt'];
  $lines = $expected['lines'];

  return gl_create_journal($db, [
    'date'      => $r['receipt_date'],
    'source'    => 'receipt',
    'source_id' => $receipt_id,
    'memo'      => "Receipt {$r['receipt_no']} {$r['client_name']}",
    'created_by'=> $r['created_by'] ?? null,
    'company_id' => $r['company_id'] ?? 1,
  ], $lines);
}

/* =========================
   Expenses (header + lines)
   =========================
   Rules:
   - Dr each expense line (5xxx) by line_subtotal
   - Dr VAT Recoverable (1260) by sum(line_vat), if > 0
   - Cr Cash/Bank (when paid_via cash/bank) using pay_account_no
     OR Cr Accounts Payable (2000) when paid_via='ap'
*/

function gl_post_expense(PDO $db, int $expense_id): int {
  // header
  $H = $db->prepare("SELECT * FROM expenses WHERE id=?");
  $H->execute([$expense_id]);
  $h = $H->fetch(PDO::FETCH_ASSOC);
  if (!$h) throw new RuntimeException("Expense not found: {$expense_id}");

  // lines
  $L = $db->prepare("SELECT * FROM expense_lines WHERE expense_id=? ORDER BY line_no");
  $L->execute([$expense_id]);
  $rows = $L->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) throw new RuntimeException("No lines for expense: {$expense_id}");

  $date = $h['expense_date'];

  // Credit account (where the money comes from)
  if (($h['paid_via'] ?? 'bank') === 'ap') {
    $credit_no = '2000'; // Accounts Payable (Liability, credit normal balance)
  } else {
    $pay = trim((string)($h['pay_account_no'] ?? ''));
    if ($pay !== '') {
      $credit_no = $pay;
    } else {
      // Legacy rows only: missing pay_account_no — infer from paid_via (no silent remap to 1020 for bank)
      $pv = $h['paid_via'] ?? 'bank';
      $credit_no = ($pv === 'cash') ? '1010' : '1020';
    }
  }
  $credit_id = coa_id($db, $credit_no);

  // VAT recoverable (Input VAT - Asset account)
  $vat_recover_id = null;
  $vat_total = (float)$h['vat_amount'];
  if ($vat_total > 0) $vat_recover_id = coa_id($db, '1260'); // Input VAT (Asset, debit normal balance)

  // build lines
  $glLines = [];
  $sumNet = 0.0;
  foreach ($rows as $r) {
    $accId = coa_id($db, $r['account_no']);          // must be an Expense account (5xxx)
    $net   = round((float)$r['line_subtotal'], 2);   // net of VAT
    if ($net <= 0) continue;
    $glLines[] = ['account_id'=>$accId, 'desc'=>$r['description'] ?: 'Expense line', 'debit'=>$net, 'credit'=>0];
    $sumNet += $net;
  }

  if ($vat_total > 0) {
    $glLines[] = ['account_id'=>$vat_recover_id, 'desc'=>'VAT Recoverable', 'debit'=>round($vat_total,2), 'credit'=>0];
  }

  $creditAmt = (float)$h['total']; // subtotal - header discount + vat
  $glLines[] = ['account_id'=>$credit_id, 'desc'=>'Payment / Liability', 'debit'=>0, 'credit'=>round($creditAmt,2)];

  $jid = gl_create_journal($db, [
    'date'      => $date,
    'source'    => 'expense',
    'source_id' => $expense_id,
    'memo'      => 'Expense #'.$expense_id.($h['reference_no'] ? ' (Ref '.$h['reference_no'].')' : ''),
    'created_by'=> $h['created_by'] ?? null,
  ], $glLines);

  // tie back
  $db->prepare("UPDATE expenses SET gl_journal_id=?, status='posted' WHERE id=?")->execute([$jid, $expense_id]);
  return $jid;
}

function gl_find_expense_journals(PDO $db, int $expense_id): array {
  $q = $db->prepare("SELECT id FROM gl_journals WHERE source='expense' AND source_id=? AND is_posted=1 AND is_reversed=0");
  $q->execute([$expense_id]);
  return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}

function gl_repost_expense(PDO $db, int $expense_id): void {
  foreach (gl_find_expense_journals($db, $expense_id) as $jid) gl_reverse_journal($db, $jid);
  gl_post_expense($db, $expense_id);
}
