<?php
/**
 * Diagnostic and repair tool for duplicate reversal issues
 * Shows all invoices with duplicate reversals and allows voiding extra reversals
 */
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_role(['Owner','Admin','Account'], $conn);

$action = $_POST['action'] ?? '';
$invoice_id = (int)($_POST['invoice_id'] ?? 0);
$journal_id = (int)($_POST['journal_id'] ?? 0);
$journal_ids = $_POST['journal_ids'] ?? [];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function moneyv($n){ return number_format((float)$n, 2); }

/**
 * Helper: fetch all "extra" reversal journal IDs across the whole system.
 * Rules:
 *  - For active invoices (issued/partially_paid/paid): ALL posted reversals are extra.
 *  - For any original journal with multiple posted reversals: keep the first (lowest id), others are extra.
 */
function get_all_extra_reversal_ids(PDO $conn): array {
  $sql = "
    SELECT rev.id
      FROM gl_journals rev
      JOIN gl_journals orig_j ON orig_j.id = rev.source_id
      LEFT JOIN invoices i ON i.id = orig_j.source_id AND orig_j.source = 'invoice'
     WHERE rev.source = 'reversal'
       AND rev.is_posted = 1
       AND (
         -- Active invoices should not have posted reversals at all
         (LOWER(COALESCE(i.status,'')) IN ('issued','partially_paid','paid'))
         OR
         -- For duplicates, keep the first (min id), others are extra
         (rev.id > (
           SELECT MIN(r2.id)
             FROM gl_journals r2
            WHERE r2.source = 'reversal'
              AND r2.source_id = rev.source_id
              AND r2.is_posted = 1
         ))
       )
  ";
  $st = $conn->prepare($sql);
  $st->execute();
  $ids = [];
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $ids[] = (int)$row['id'];
  }
  return $ids;
}

// Handle AJAX actions
if ($action === 'void_extra_reversal' && $journal_id > 0) {
  header('Content-Type: application/json');
  
  try {
    $conn->beginTransaction();
    
    // Get the reversal journal
    $revSt = $conn->prepare("SELECT * FROM gl_journals WHERE id=? AND source='reversal' FOR UPDATE");
    $revSt->execute([$journal_id]);
    $revJournal = $revSt->fetch(PDO::FETCH_ASSOC);
    
    if (!$revJournal) {
      throw new Exception("Reversal journal not found");
    }
    
    // Get the original journal it reverses
    $origJournalId = (int)$revJournal['source_id'];
    $origSt = $conn->prepare("SELECT * FROM gl_journals WHERE id=? FOR UPDATE");
    $origSt->execute([$origJournalId]);
    $origJournal = $origSt->fetch(PDO::FETCH_ASSOC);
    
    if (!$origJournal) {
      throw new Exception("Original journal not found");
    }
    
    // Check how many reversals exist for this original journal
    $countSt = $conn->prepare("SELECT COUNT(*) FROM gl_journals WHERE source='reversal' AND source_id=? AND is_posted=1");
    $countSt->execute([$origJournalId]);
    $revCount = (int)$countSt->fetchColumn();
    
    if ($revCount <= 1) {
      throw new Exception("Cannot delete the only reversal for this journal");
    }
    
    // Get all reversal journal lines to reverse their impact
    $linesSt = $conn->prepare("SELECT account_id, description, debit, credit FROM gl_journal_lines WHERE journal_id=? ORDER BY line_no");
    $linesSt->execute([$journal_id]);
    $lines = $linesSt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($lines)) {
      throw new Exception("No journal lines found for this reversal");
    }
    
    $period = substr($revJournal['journal_date'], 0, 7);
    
    // Create a compensating journal entry to reverse the impact of voiding this reversal
    // This is essentially reversing the reversal (double negative = positive)
    // This creates an audit trail showing why the reversal was voided
    // Generate unique journal number (check for duplicates)
    $maxAttempts = 10;
    $compensatingNo = null;
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
      $candidate = 'VOID-'.date('Ymd').'-'.str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
      $checkSt = $conn->prepare("SELECT COUNT(*) FROM gl_journals WHERE journal_no = ?");
      $checkSt->execute([$candidate]);
      if ($checkSt->fetchColumn() == 0) {
        $compensatingNo = $candidate;
        break;
      }
    }
    if (!$compensatingNo) {
      // Fallback: use timestamp + random
      $compensatingNo = 'VOID-'.date('YmdHis').'-'.mt_rand(1000, 9999);
    }
    
    // Create compensating journal header
    $compHdrSt = $conn->prepare("
      INSERT INTO gl_journals (journal_no, journal_date, source, source_id, memo, is_posted, is_reversed, created_by)
      VALUES (?, ?, 'adjustment', ?, ?, 1, 0, ?)
    ");
    $compMemo = "Compensating entry: Voiding duplicate reversal {$revJournal['journal_no']}";
    $compHdrSt->execute([
      $compensatingNo,
      $revJournal['journal_date'],
      $journal_id,
      $compMemo,
      $_SESSION['user_id'] ?? null
    ]);
    $compJournalId = (int)$conn->lastInsertId();
    
    // Create compensating journal lines (reverse the reversal = original amounts)
    $compLineSt = $conn->prepare("
      INSERT INTO gl_journal_lines (journal_id, line_no, account_id, description, debit, credit)
      VALUES (?, ?, ?, ?, ?, ?)
    ");
    $compBalSt = $conn->prepare("
      INSERT INTO gl_account_balances (account_id, period, debit, credit)
      VALUES (?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE debit=debit+VALUES(debit), credit=credit+VALUES(credit)
    ");
    
    $lineNo = 1;
    $sumDr = 0.0;
    $sumCr = 0.0;
    
    foreach ($lines as $line) {
      // Reverse the reversal: if reversal debited, we credit; if reversal credited, we debit
      $compDebit = (float)$line['credit'];  // Opposite of what the reversal did
      $compCredit = (float)$line['debit'];   // Opposite of what the reversal did
      
      $compLineSt->execute([
        $compJournalId,
        $lineNo++,
        (int)$line['account_id'],
        "Void duplicate reversal: " . ($line['description'] ?: ''),
        $compDebit,
        $compCredit
      ]);
      
      $compBalSt->execute([
        (int)$line['account_id'],
        $period,
        $compDebit,
        $compCredit
      ]);
      
      $sumDr += $compDebit;
      $sumCr += $compCredit;
    }
    
    // Verify journal is balanced
    if (round($sumDr, 2) !== round($sumCr, 2)) {
      throw new Exception("Compensating journal not balanced (Dr {$sumDr} ≠ Cr {$sumCr})");
    }
    
    // Mark the reversal journal as voided (set is_posted=0, is_reversed=1)
    $voidSt = $conn->prepare("
      UPDATE gl_journals 
      SET is_posted=0, is_reversed=1, memo=CONCAT(COALESCE(memo,''), ' [VOIDED - Duplicate Reversal Removed - Compensated by ', ?, ']')
      WHERE id=?
    ");
    $voidSt->execute([$compensatingNo, $journal_id]);
    
    // Audit log
    require_once __DIR__ . '/../includes/AuditService.php';
    AuditService::log([
      'action' => 'update',
      'object_type' => 'gl_journals',
      'object_id' => (string)$journal_id,
      'summary' => "Voided duplicate reversal journal #{$revJournal['journal_no']} (reversed journal #{$origJournal['journal_no']})",
      'old_data' => ['is_posted' => 1, 'is_reversed' => 0],
      'new_data' => ['is_posted' => 0, 'is_reversed' => 1],
      'success' => true
    ]);
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Duplicate reversal voided successfully']);
  } catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

// Handle voiding ALL extra reversals system-wide
if ($action === 'void_all_extra_global') {
  header('Content-Type: application/json');
  
  $voidedCount = 0;
  $errors = [];
  
  try {
    $conn->beginTransaction();
    
    $candidateIds = get_all_extra_reversal_ids($conn);
    if (empty($candidateIds)) {
      throw new Exception("No extra reversals found to void.");
    }
    
    foreach ($candidateIds as $journal_id) {
      try {
        // Get the reversal journal
        $revSt = $conn->prepare("SELECT * FROM gl_journals WHERE id=? AND source='reversal' FOR UPDATE");
        $revSt->execute([$journal_id]);
        $revJournal = $revSt->fetch(PDO::FETCH_ASSOC);
        if (!$revJournal || !$revJournal['is_posted']) {
          continue; // already voided or not found
        }
        
        // Get the original journal it reverses
        $origJournalId = (int)$revJournal['source_id'];
        $origSt = $conn->prepare("SELECT * FROM gl_journals WHERE id=? FOR UPDATE");
        $origSt->execute([$origJournalId]);
        $origJournal = $origSt->fetch(PDO::FETCH_ASSOC);
        if (!$origJournal) {
          $errors[] = "Original journal for reversal #{$revJournal['journal_no']} not found";
          continue;
        }
        
        // Get all reversal journal lines to reverse their impact
        $linesSt = $conn->prepare("SELECT account_id, description, debit, credit FROM gl_journal_lines WHERE journal_id=? ORDER BY line_no");
        $linesSt->execute([$journal_id]);
        $lines = $linesSt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($lines)) {
          $errors[] = "No journal lines found for reversal #{$revJournal['journal_no']}";
          continue;
        }
        
        $period = substr($revJournal['journal_date'], 0, 7);
        
        // Generate compensating header number
        $maxAttempts = 10;
        $compensatingNo = null;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
          $candidate = 'VOID-'.date('Ymd').'-'.str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
          $checkSt = $conn->prepare("SELECT COUNT(*) FROM gl_journals WHERE journal_no = ?");
          $checkSt->execute([$candidate]);
          if ($checkSt->fetchColumn() == 0) {
            $compensatingNo = $candidate;
            break;
          }
        }
        if (!$compensatingNo) {
          $compensatingNo = 'VOID-'.date('YmdHis').'-'.mt_rand(1000, 9999);
        }
        
        // Create compensating journal header
        $compHdrSt = $conn->prepare("
          INSERT INTO gl_journals (journal_no, journal_date, source, source_id, memo, is_posted, is_reversed, created_by)
          VALUES (?, ?, 'adjustment', ?, ?, 1, 0, ?)
        ");
        $compMemo = "Compensating entry: Voiding duplicate reversal {$revJournal['journal_no']}";
        $compHdrSt->execute([
          $compensatingNo,
          $revJournal['journal_date'],
          $journal_id,
          $compMemo,
          $_SESSION['user_id'] ?? null
        ]);
        $compJournalId = (int)$conn->lastInsertId();
        
        // Create compensating journal lines
        $compLineSt = $conn->prepare("
          INSERT INTO gl_journal_lines (journal_id, line_no, account_id, description, debit, credit)
          VALUES (?, ?, ?, ?, ?, ?)
        ");
        $compBalSt = $conn->prepare("
          INSERT INTO gl_account_balances (account_id, period, debit, credit)
          VALUES (?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE debit=debit+VALUES(debit), credit=credit+VALUES(credit)
        ");
        
        $lineNo = 1;
        $sumDr = 0.0;
        $sumCr = 0.0;
        foreach ($lines as $line) {
          $compDebit = (float)$line['credit'];
          $compCredit = (float)$line['debit'];
          $compLineSt->execute([$compJournalId, $lineNo++, (int)$line['account_id'], "Void duplicate reversal: " . ($line['description'] ?: ''), $compDebit, $compCredit]);
          $compBalSt->execute([(int)$line['account_id'], $period, $compDebit, $compCredit]);
          $sumDr += $compDebit;
          $sumCr += $compCredit;
        }
        if (round($sumDr, 2) !== round($sumCr, 2)) {
          throw new Exception("Compensating journal not balanced (Dr {$sumDr} ≠ Cr {$sumCr})");
        }
        
        // Mark the reversal journal as voided
        $voidSt = $conn->prepare("
          UPDATE gl_journals 
             SET is_posted=0, is_reversed=1, memo=CONCAT(COALESCE(memo,''), ' [VOIDED - Duplicate Reversal Removed - Compensated by ', ?, ']')
           WHERE id=?
        ");
        $voidSt->execute([$compensatingNo, $journal_id]);
        
        // Audit
        require_once __DIR__ . '/../includes/AuditService.php';
        AuditService::log([
          'action' => 'update',
          'object_type' => 'gl_journals',
          'object_id' => (string)$journal_id,
          'summary' => "Voided duplicate reversal journal #{$revJournal['journal_no']} (system-wide bulk)",
          'old_data' => ['is_posted' => 1, 'is_reversed' => 0],
          'new_data' => ['is_posted' => 0, 'is_reversed' => 1],
          'success' => true
        ]);
        
        $voidedCount++;
      } catch (Exception $e) {
        $errors[] = "Error voiding reversal #{$journal_id}: " . $e->getMessage();
      }
    }
    
    $conn->commit();
    echo json_encode([
      'success' => true,
      'message' => "Voided {$voidedCount} extra reversal(s)." . (!empty($errors) ? " Some errors: " . implode('; ', $errors) : '')
    ]);
  } catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

// Handle voiding all extra reversals at once
if ($action === 'void_all_extra' && !empty($journal_ids)) {
  header('Content-Type: application/json');
  
  $voidedCount = 0;
  $errors = [];
  
  try {
    $conn->beginTransaction();
    
    // Validate all journal IDs are integers
    $validJournalIds = [];
    foreach ($journal_ids as $jid) {
      $jid = (int)$jid;
      if ($jid > 0) {
        $validJournalIds[] = $jid;
      }
    }
    
    if (empty($validJournalIds)) {
      throw new Exception("No valid journal IDs provided");
    }
    
    // Process each reversal
    foreach ($validJournalIds as $journal_id) {
      try {
        // Get the reversal journal
        $revSt = $conn->prepare("SELECT * FROM gl_journals WHERE id=? AND source='reversal' FOR UPDATE");
        $revSt->execute([$journal_id]);
        $revJournal = $revSt->fetch(PDO::FETCH_ASSOC);
        
        if (!$revJournal) {
          $errors[] = "Reversal journal #{$journal_id} not found";
          continue;
        }
        
        if (!$revJournal['is_posted']) {
          $errors[] = "Reversal journal #{$revJournal['journal_no']} is already voided";
          continue;
        }
        
        // Get the original journal it reverses
        $origJournalId = (int)$revJournal['source_id'];
        $origSt = $conn->prepare("SELECT * FROM gl_journals WHERE id=? FOR UPDATE");
        $origSt->execute([$origJournalId]);
        $origJournal = $origSt->fetch(PDO::FETCH_ASSOC);
        
        if (!$origJournal) {
          $errors[] = "Original journal for reversal #{$revJournal['journal_no']} not found";
          continue;
        }
        
        // Get all reversal journal lines to reverse their impact
        $linesSt = $conn->prepare("SELECT account_id, description, debit, credit FROM gl_journal_lines WHERE journal_id=? ORDER BY line_no");
        $linesSt->execute([$journal_id]);
        $lines = $linesSt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($lines)) {
          $errors[] = "No journal lines found for reversal #{$revJournal['journal_no']}";
          continue;
        }
        
        $period = substr($revJournal['journal_date'], 0, 7);
        
        // Create a compensating journal entry to reverse the impact of voiding this reversal
        // Generate unique journal number (check for duplicates)
        $maxAttempts = 10;
        $compensatingNo = null;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
          $candidate = 'VOID-'.date('Ymd').'-'.str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
          $checkSt = $conn->prepare("SELECT COUNT(*) FROM gl_journals WHERE journal_no = ?");
          $checkSt->execute([$candidate]);
          if ($checkSt->fetchColumn() == 0) {
            $compensatingNo = $candidate;
            break;
          }
        }
        if (!$compensatingNo) {
          // Fallback: use timestamp + random
          $compensatingNo = 'VOID-'.date('YmdHis').'-'.mt_rand(1000, 9999);
        }
        
        // Create compensating journal header
        $compHdrSt = $conn->prepare("
          INSERT INTO gl_journals (journal_no, journal_date, source, source_id, memo, is_posted, is_reversed, created_by)
          VALUES (?, ?, 'adjustment', ?, ?, 1, 0, ?)
        ");
        $compMemo = "Compensating entry: Voiding duplicate reversal {$revJournal['journal_no']}";
        $compHdrSt->execute([
          $compensatingNo,
          $revJournal['journal_date'],
          $journal_id,
          $compMemo,
          $_SESSION['user_id'] ?? null
        ]);
        $compJournalId = (int)$conn->lastInsertId();
        
        // Create compensating journal lines (reverse the reversal = original amounts)
        $compLineSt = $conn->prepare("
          INSERT INTO gl_journal_lines (journal_id, line_no, account_id, description, debit, credit)
          VALUES (?, ?, ?, ?, ?, ?)
        ");
        $compBalSt = $conn->prepare("
          INSERT INTO gl_account_balances (account_id, period, debit, credit)
          VALUES (?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE debit=debit+VALUES(debit), credit=credit+VALUES(credit)
        ");
        
        $lineNo = 1;
        $sumDr = 0.0;
        $sumCr = 0.0;
        
        foreach ($lines as $line) {
          // Reverse the reversal: if reversal debited, we credit; if reversal credited, we debit
          $compDebit = (float)$line['credit'];  // Opposite of what the reversal did
          $compCredit = (float)$line['debit'];   // Opposite of what the reversal did
          
          $compLineSt->execute([
            $compJournalId,
            $lineNo++,
            (int)$line['account_id'],
            "Void duplicate reversal: " . ($line['description'] ?: ''),
            $compDebit,
            $compCredit
          ]);
          
          $compBalSt->execute([
            (int)$line['account_id'],
            $period,
            $compDebit,
            $compCredit
          ]);
          
          $sumDr += $compDebit;
          $sumCr += $compCredit;
        }
        
        // Verify journal is balanced
        if (round($sumDr, 2) !== round($sumCr, 2)) {
          throw new Exception("Compensating journal not balanced (Dr {$sumDr} ≠ Cr {$sumCr})");
        }
        
        // Mark the reversal journal as voided
        $voidSt = $conn->prepare("
          UPDATE gl_journals 
          SET is_posted=0, is_reversed=1, memo=CONCAT(COALESCE(memo,''), ' [VOIDED - Duplicate Reversal Removed - Compensated by ', ?, ']')
          WHERE id=?
        ");
        $voidSt->execute([$compensatingNo, $journal_id]);
        
        // Audit log
        require_once __DIR__ . '/../includes/AuditService.php';
        AuditService::log([
          'action' => 'update',
          'object_type' => 'gl_journals',
          'object_id' => (string)$journal_id,
          'summary' => "Voided duplicate reversal journal #{$revJournal['journal_no']} (reversed journal #{$origJournal['journal_no']})",
          'old_data' => ['is_posted' => 1, 'is_reversed' => 0],
          'new_data' => ['is_posted' => 0, 'is_reversed' => 1],
          'success' => true
        ]);
        
        $voidedCount++;
      } catch (Exception $e) {
        $errors[] = "Error voiding reversal #{$journal_id}: " . $e->getMessage();
      }
    }
    
    if ($voidedCount === 0 && !empty($errors)) {
      throw new Exception("Failed to void any reversals: " . implode("; ", $errors));
    }
    
    $conn->commit();
    echo json_encode([
      'success' => true, 
      'message' => "Successfully voided {$voidedCount} reversal(s)" . (!empty($errors) ? ". " . count($errors) . " error(s): " . implode("; ", $errors) : ""),
      'voided_count' => $voidedCount,
      'errors' => $errors
    ]);
  } catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

// Get all invoices with duplicate reversals
$allIssuesSt = $conn->prepare("
  SELECT 
    i.id as invoice_id,
    i.invoice_no,
    i.status,
    i.total,
    i.subtotal,
    orig_j.id as original_journal_id,
    orig_j.journal_no as original_journal_no,
    COUNT(rev.id) as reversal_count,
    GROUP_CONCAT(rev.id ORDER BY rev.id) as reversal_journal_ids,
    GROUP_CONCAT(rev.journal_no ORDER BY rev.id SEPARATOR ', ') as reversal_journal_nos,
    GROUP_CONCAT(rev.id ORDER BY rev.id) as reversal_ids,
    SUM(CASE WHEN l.account_id = (SELECT id FROM chart_of_accounts WHERE account_no='4010' LIMIT 1) 
        THEN l.debit ELSE 0 END) as total_revenue_reversed
  FROM invoices i
  JOIN gl_journals orig_j ON orig_j.source='invoice' AND orig_j.source_id=i.id
  JOIN gl_journals rev ON rev.source='reversal' AND rev.source_id=orig_j.id
  LEFT JOIN gl_journal_lines l ON l.journal_id=rev.id
  WHERE rev.is_posted=1
  GROUP BY i.id, orig_j.id
  HAVING reversal_count > 1
  ORDER BY i.invoice_no, orig_j.id
");
$allIssuesSt->execute();
$allIssues = $allIssuesSt->fetchAll(PDO::FETCH_ASSOC);

// Group by invoice
$invoicesWithIssues = [];
foreach ($allIssues as $issue) {
  $invId = $issue['invoice_id'];
  if (!isset($invoicesWithIssues[$invId])) {
    $invoicesWithIssues[$invId] = [
      'invoice_id' => $invId,
      'invoice_no' => $issue['invoice_no'],
      'status' => $issue['status'],
      'total' => $issue['total'],
      'subtotal' => $issue['subtotal'],
      'issues' => []
    ];
  }
  $invoicesWithIssues[$invId]['issues'][] = $issue;
}

// If specific invoice requested, get detailed view
$invoice_no = $_GET['invoice_no'] ?? null;
$detailedInvoice = null;
if ($invoice_no) {
  $invSt = $conn->prepare("SELECT id, invoice_no, status, total, subtotal, vat_amount FROM invoices WHERE invoice_no = ?");
  $invSt->execute([$invoice_no]);
  $detailedInvoice = $invSt->fetch(PDO::FETCH_ASSOC);
  
  if ($detailedInvoice) {
    // Get all journals for this invoice
    $journalsSt = $conn->prepare("
      SELECT 
        j.id,
        j.journal_no,
        j.journal_date,
        j.source,
        j.is_reversed,
        j.is_posted,
        j.memo,
        j.created_at,
        (SELECT COUNT(*) FROM gl_journals rev WHERE rev.source='reversal' AND rev.source_id=j.id AND rev.is_posted=1) as reversal_count
      FROM gl_journals j
      WHERE j.source = 'invoice' AND j.source_id = ?
      ORDER BY j.created_at
    ");
    $journalsSt->execute([$detailedInvoice['id']]);
    $journals = $journalsSt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all reversals with details (including voided ones for display, but we'll filter totals)
    $reversalsSt = $conn->prepare("
      SELECT 
        rev.id,
        rev.journal_no,
        rev.journal_date,
        rev.source_id as reverses_journal_id,
        rev.is_posted,
        orig_j.journal_no as reverses_journal_no,
        rev.memo,
        rev.created_at,
        SUM(CASE WHEN l.account_id = (SELECT id FROM chart_of_accounts WHERE account_no='4010' LIMIT 1) 
            THEN l.debit ELSE 0 END) as revenue_debit,
        SUM(CASE WHEN l.account_id = (SELECT id FROM chart_of_accounts WHERE account_no='4010' LIMIT 1) 
            THEN l.credit ELSE 0 END) as revenue_credit
      FROM gl_journals rev
      JOIN gl_journals orig_j ON orig_j.id = rev.source_id
      LEFT JOIN gl_journal_lines l ON l.journal_id = rev.id
      WHERE rev.source = 'reversal'
        AND orig_j.source = 'invoice'
        AND orig_j.source_id = ?
      GROUP BY rev.id
      ORDER BY rev.created_at
    ");
    $reversalsSt->execute([$detailedInvoice['id']]);
    $reversals = $reversalsSt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get duplicate reversals grouped by original journal
    $dupSt = $conn->prepare("
      SELECT 
        rev.source_id as original_journal_id,
        orig_j.journal_no as original_journal_no,
        COUNT(*) as reversal_count,
        GROUP_CONCAT(rev.id ORDER BY rev.id) as reversal_ids,
        GROUP_CONCAT(rev.journal_no ORDER BY rev.id SEPARATOR ', ') as reversal_journals,
        MIN(rev.id) as first_reversal_id,
        MIN(rev.journal_no) as first_reversal_no
      FROM gl_journals rev
      JOIN gl_journals orig_j ON orig_j.id = rev.source_id
      WHERE rev.source = 'reversal'
        AND orig_j.source = 'invoice'
        AND orig_j.source_id = ?
        AND rev.is_posted = 1
      GROUP BY rev.source_id
      HAVING reversal_count > 1
    ");
    $dupSt->execute([$detailedInvoice['id']]);
    $duplicates = $dupSt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build a map of which reversals should be kept (first) vs voided (extra)
    // Key: reversal_id, Value: true if should keep, false if should void
    $reversalKeepMap = [];
    foreach ($duplicates as $dup) {
      $revIds = explode(',', $dup['reversal_ids']);
      $firstId = (int)$dup['first_reversal_id'];
      foreach ($revIds as $revId) {
        $revId = (int)$revId;
        $reversalKeepMap[$revId] = ($revId === $firstId);
      }
    }
    
    // Also check for journals with multiple reversals that weren't caught by the duplicates query
    // Build a map of first reversal ID for each original journal
    $firstReversalMap = [];
    foreach ($reversals as $rev) {
      $origJournalId = (int)$rev['reverses_journal_id'];
      if (!isset($firstReversalMap[$origJournalId])) {
        $firstReversalMap[$origJournalId] = (int)$rev['id'];
      } else {
        // Keep the minimum ID (first reversal)
        if ((int)$rev['id'] < $firstReversalMap[$origJournalId]) {
          $firstReversalMap[$origJournalId] = (int)$rev['id'];
        }
      }
    }
    
    // Count reversals per original journal
    $reversalCountMap = [];
    foreach ($reversals as $rev) {
      $origJournalId = (int)$rev['reverses_journal_id'];
      if (!isset($reversalCountMap[$origJournalId])) {
        $reversalCountMap[$origJournalId] = 0;
      }
      if ($rev['is_posted']) {
        $reversalCountMap[$origJournalId]++;
      }
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Reversal Diagnostic & Repair Tool</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .issue-badge { font-size: 0.9rem; }
  .reversal-row { background-color: #fff3cd; }
  .reversal-row.extra { background-color: #f8d7da; }
</style>
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="d-flex align-items-center mb-3">
    <h3 class="me-auto"><i class="bi bi-bug"></i> Reversal Diagnostic & Repair Tool</h3>
    <a href="reports.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Reports</a>
  </div>

  <?php if ($detailedInvoice): ?>
    <!-- Detailed view for specific invoice -->
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Invoice: <?= h($detailedInvoice['invoice_no']) ?></span>
        <a href="?invoice_no=" class="btn btn-sm btn-outline-secondary">View All Issues</a>
      </div>
      <div class="card-body">
        <p><strong>Invoice ID:</strong> <?= $detailedInvoice['id'] ?></p>
        <p><strong>Status:</strong> <?= h($detailedInvoice['status']) ?></p>
        <p><strong>Total:</strong> <?= moneyv($detailedInvoice['total']) ?> AED</p>
        <p><strong>Subtotal:</strong> <?= moneyv($detailedInvoice['subtotal']) ?> AED</p>
      </div>
    </div>

    <?php if (!empty($duplicates)): ?>
    <div class="alert alert-danger">
      <h5><i class="bi bi-exclamation-triangle"></i> CRITICAL: Duplicate Reversals Found!</h5>
      <p>The following journals have been reversed multiple times:</p>
      <?php foreach ($duplicates as $dup): 
        $revIds = explode(',', $dup['reversal_ids']);
        $firstId = (int)$dup['first_reversal_id'];
      ?>
        <div class="card mb-2">
          <div class="card-body">
            <h6>Original Journal: <code><?= h($dup['original_journal_no']) ?></code> (J#<?= $dup['original_journal_id'] ?>)</h6>
            <p class="mb-2">Reversed <strong><?= $dup['reversal_count'] ?> times</strong> (should be 1)</p>
            <table class="table table-sm">
              <thead>
                <tr>
                  <th>Reversal Journal</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $revNos = explode(', ', $dup['reversal_journals']);
                foreach ($revIds as $idx => $revId): 
                  $revId = (int)$revId;
                  $isFirst = ($revId === $firstId);
                  $revNo = $revNos[$idx] ?? '';
                ?>
                  <tr class="<?= $isFirst ? 'table-success' : 'table-danger' ?>">
                    <td><code><?= h($revNo) ?></code> (J#<?= $revId ?>)</td>
                    <td>
                      <?php if ($isFirst): ?>
                        <span class="badge bg-success">First (Keep)</span>
                      <?php else: ?>
                        <span class="badge bg-danger">Extra (Should be voided)</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!$isFirst): ?>
                        <button class="btn btn-sm btn-danger void-reversal-btn" 
                                data-journal-id="<?= $revId ?>"
                                data-journal-no="<?= h($revNo) ?>">
                          <i class="bi bi-trash"></i> Void This Reversal
                        </button>
                      <?php else: ?>
                        <span class="text-muted">Keep this one</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card mb-3">
      <div class="card-header">All Journals for This Invoice (<?= count($journals) ?>)</div>
      <div class="card-body">
        <table class="table table-sm">
          <thead>
            <tr>
              <th>Journal #</th>
              <th>Date</th>
              <th>Reversed?</th>
              <th>Posted?</th>
              <th>Reversal Count</th>
              <th>Memo</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($journals as $j): ?>
              <tr class="<?= $j['is_reversed'] ? 'table-warning' : '' ?>">
                <td><code><?= h($j['journal_no']) ?></code></td>
                <td><?= h($j['journal_date']) ?></td>
                <td><?= $j['is_reversed'] ? '<span class="badge bg-danger">Yes</span>' : '<span class="badge bg-success">No</span>' ?></td>
                <td><?= $j['is_posted'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                <td><?= $j['reversal_count'] ?></td>
                <td><?= h($j['memo']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>All Reversals for This Invoice (<?= count($reversals) ?>)</span>
        <span id="voidAllExtraBtnContainer"></span>
      </div>
      <div class="card-body">
        <table class="table table-sm">
          <thead>
            <tr>
              <th>Reversal Journal #</th>
              <th>Reverses Journal</th>
              <th>Date</th>
              <th>Posted?</th>
              <th>Revenue Debit</th>
              <th>Revenue Credit</th>
              <th>Status</th>
              <th>Action</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $totalRevDebit = 0;
            $totalRevCredit = 0;
            // Calculate total only for POSTED reversals (exclude voided ones)
            foreach ($reversals as $rev): 
              // Only include posted reversals in totals
              if ($rev['is_posted']) {
                $totalRevDebit += (float)$rev['revenue_debit'];
                $totalRevCredit += (float)$rev['revenue_credit'];
              }
            endforeach;
            
            // Check if reversals exceed invoice amount (indicates problem)
            $reversalsExceedInvoice = ($totalRevDebit > (float)$detailedInvoice['subtotal']);
            
            // Find the most recent POSTED reversal (should be kept - it reversed the previous journal to create current one)
            $mostRecentReversalId = null;
            if (!empty($reversals)) {
              // Filter to only posted reversals, then sort by created_at descending and get the first one
              $postedReversals = array_filter($reversals, function($r) { return $r['is_posted']; });
              if (!empty($postedReversals)) {
                $sortedReversals = array_values($postedReversals);
                usort($sortedReversals, function($a, $b) {
                  return strcmp($b['created_at'], $a['created_at']);
                });
                $mostRecentReversalId = (int)$sortedReversals[0]['id'];
              }
            }
            
            // Collect all extra reversal IDs for bulk void
            $extraReversalIds = [];
            
            foreach ($reversals as $rev): 
              // Check if this is an extra reversal (not the first one for its original journal)
              $isExtra = false;
              $shouldKeep = false;
              $origJournalId = (int)$rev['reverses_journal_id'];
              
              // Rule 1: If invoice is currently active (issued/partially_paid/paid), no reversal should remain posted.
              // Mark ALL posted reversals as extra (none kept).
              $activeStatuses = ['issued','partially_paid','paid'];
              if (in_array(strtolower((string)$detailedInvoice['status']), $activeStatuses, true)) {
                $shouldKeep = false;
                $isExtra = (bool)$rev['is_posted'];
              } elseif (isset($reversalKeepMap[$rev['id']])) {
                // Already identified in duplicates query
                $shouldKeep = $reversalKeepMap[$rev['id']];
                $isExtra = !$shouldKeep;
              } elseif (isset($reversalCountMap[$origJournalId]) && $reversalCountMap[$origJournalId] > 1) {
                // Multiple reversals exist for this original journal
                $firstId = $firstReversalMap[$origJournalId] ?? null;
                if ($firstId !== null) {
                  $shouldKeep = ((int)$rev['id'] === $firstId);
                  $isExtra = !$shouldKeep;
                }
              } elseif ($reversalsExceedInvoice) {
                // If reversals exceed invoice, mark all except the most recent one as potentially problematic
                if ($mostRecentReversalId && (int)$rev['id'] === $mostRecentReversalId) {
                  $shouldKeep = true;
                  $isExtra = false;
                } else {
                  // This reversal might be extra/problematic
                  $isExtra = true;
                  $shouldKeep = false;
                }
              }
              
              // Collect extra reversal IDs for bulk void button
              if ($isExtra && $rev['is_posted']) {
                $extraReversalIds[] = (int)$rev['id'];
              }
            ?>
              <tr class="<?= $isExtra ? 'reversal-row extra' : 'reversal-row' ?>">
                <td><code><?= h($rev['journal_no']) ?></code></td>
                <td><code><?= h($rev['reverses_journal_no']) ?></code> (J#<?= $rev['reverses_journal_id'] ?>)</td>
                <td><?= h($rev['journal_date']) ?></td>
                <td><?= $rev['is_posted'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                <td class="text-end"><?= moneyv($rev['revenue_debit']) ?></td>
                <td class="text-end"><?= moneyv($rev['revenue_credit']) ?></td>
                <td>
                  <?php if ($isExtra): ?>
                    <span class="badge bg-danger">Extra (Should be voided)</span>
                  <?php elseif ($shouldKeep): ?>
                    <span class="badge bg-success">First (Keep)</span>
                  <?php elseif ($mostRecentReversalId && (int)$rev['id'] === $mostRecentReversalId): ?>
                    <span class="badge bg-warning">Most Recent (Keep)</span>
                  <?php else: ?>
                    <span class="badge bg-info">Single</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($isExtra && $rev['is_posted']): ?>
                    <button class="btn btn-sm btn-danger void-reversal-btn" 
                            data-journal-id="<?= $rev['id'] ?>"
                            data-journal-no="<?= h($rev['journal_no']) ?>">
                      <i class="bi bi-trash"></i> Void This Reversal
                    </button>
                  <?php elseif ($reversalsExceedInvoice && $rev['is_posted'] && (int)$rev['id'] !== $mostRecentReversalId): ?>
                    <button class="btn btn-sm btn-warning void-reversal-btn" 
                            data-journal-id="<?= $rev['id'] ?>"
                            data-journal-no="<?= h($rev['journal_no']) ?>">
                      <i class="bi bi-trash"></i> Void (Exceeds Invoice)
                    </button>
                  <?php elseif ($shouldKeep || ($mostRecentReversalId && (int)$rev['id'] === $mostRecentReversalId)): ?>
                    <span class="text-muted small">Keep this one</span>
                  <?php elseif ($rev['is_posted']): ?>
                    <button class="btn btn-sm btn-outline-danger void-reversal-btn" 
                            data-journal-id="<?= $rev['id'] ?>"
                            data-journal-no="<?= h($rev['journal_no']) ?>">
                      <i class="bi bi-trash"></i> Void
                    </button>
                  <?php else: ?>
                    <span class="text-muted small">-</span>
                  <?php endif; ?>
                </td>
                <td><?= h($rev['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="table-light">
              <th colspan="5">Total Reversals:</th>
              <th class="text-end"><?= moneyv($totalRevDebit) ?></th>
              <th class="text-end"><?= moneyv($totalRevCredit) ?></th>
              <th colspan="2"></th>
            </tr>
          </tfoot>
        </table>
        <?php if (!empty($extraReversalIds)): ?>
          <script>
            (function() {
              const container = document.getElementById('voidAllExtraBtnContainer');
              if (container) {
                container.innerHTML = '<button class="btn btn-sm btn-danger" id="voidAllExtraBtn" data-journal-ids="<?= htmlspecialchars(json_encode($extraReversalIds)) ?>"><i class="bi bi-trash"></i> Void All Extra Reversals (<?= count($extraReversalIds) ?>)</button>';
              }
            })();
          </script>
        <?php endif; ?>
        <div class="alert alert-info mt-3">
          <strong>Analysis:</strong><br>
          Original Invoice Subtotal: <?= moneyv($detailedInvoice['subtotal']) ?> AED<br>
          Total Revenue Reversed (Debits) - Active Reversals Only: <?= moneyv($totalRevDebit) ?> AED<br>
          <?php 
          $voidedCount = 0;
          $activeCount = 0;
          foreach ($reversals as $r) {
            if ($r['is_posted']) {
              $activeCount++;
            } else {
              $voidedCount++;
            }
          }
          ?>
          <small class="text-muted">(Active: <?= $activeCount ?>, Voided: <?= $voidedCount ?>)</small><br>
          <strong>Difference:</strong> <?= moneyv($totalRevDebit - $detailedInvoice['subtotal']) ?> AED
          <?php if ($totalRevDebit > $detailedInvoice['subtotal']): ?>
            <br><span class="text-danger">⚠️ Reversals exceed original invoice amount!</span>
          <?php elseif ($totalRevDebit <= $detailedInvoice['subtotal'] && $voidedCount > 0): ?>
            <br><span class="text-success">✓ After voiding <?= $voidedCount ?> reversal(s), totals are now correct.</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

  <?php else: ?>
    <!-- List view: All invoices with issues -->
    <div class="card mb-3">
      <div class="card-header">
        <h5 class="mb-0">All Invoices with Duplicate Reversals (<?= count($invoicesWithIssues) ?>)</h5>
      </div>
      <div class="card-body">
        <?php if (empty($invoicesWithIssues)): ?>
          <div class="alert alert-success">
            <i class="bi bi-check-circle"></i> <strong>Great news!</strong> No invoices with duplicate reversals found.
          </div>
        <?php else: ?>
          <p class="text-muted">Click on an invoice to see detailed information and fix duplicate reversals.</p>
          <?php 
            // Get global count of extra reversals to offer one-click bulk fix
            $globalExtraIds = get_all_extra_reversal_ids($conn);
            $globalExtraCount = count($globalExtraIds);
          ?>
          <?php if ($globalExtraCount > 0): ?>
            <div class="mb-3">
              <button class="btn btn-danger" id="voidAllGlobalBtn" data-count="<?= (int)$globalExtraCount ?>">
                <i class="bi bi-trash"></i> Void All Extra Reversals (<?= (int)$globalExtraCount ?>)
              </button>
            </div>
          <?php endif; ?>
          <div class="table-responsive">
            <table class="table table-hover">
              <thead>
                <tr>
                  <th>Invoice #</th>
                  <th>Status</th>
                  <th>Total</th>
                  <th>Issues</th>
                  <th>Total Reversals</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($invoicesWithIssues as $inv): 
                  $totalReversals = 0;
                  $totalRevAmount = 0;
                  foreach ($inv['issues'] as $issue) {
                    $totalReversals += (int)$issue['reversal_count'];
                    $totalRevAmount += (float)$issue['total_revenue_reversed'];
                  }
                ?>
                  <tr>
                    <td><strong><?= h($inv['invoice_no']) ?></strong></td>
                    <td><span class="badge bg-secondary"><?= h($inv['status']) ?></span></td>
                    <td><?= moneyv($inv['total']) ?> AED</td>
                    <td>
                      <span class="badge bg-danger issue-badge">
                        <?= count($inv['issues']) ?> journal<?= count($inv['issues']) > 1 ? 's' : '' ?> with duplicate reversals
                      </span>
                    </td>
                    <td>
                      <span class="badge bg-warning"><?= $totalReversals ?> total reversals</span><br>
                      <small class="text-muted">Revenue reversed: <?= moneyv($totalRevAmount) ?> AED</small>
                    </td>
                    <td>
                      <a href="?invoice_no=<?= urlencode($inv['invoice_no']) ?>" class="btn btn-sm btn-primary">
                        <i class="bi bi-eye"></i> View Details
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header">How This Works</div>
      <div class="card-body">
        <p><strong>What this tool does:</strong></p>
        <ul>
          <li>Scans all invoices to find journals that have been reversed multiple times</li>
          <li>Shows which invoices have duplicate reversal issues</li>
          <li>Allows you to void extra reversal journals (keeps the first one, voids the rest)</li>
          <li>Automatically reverses the GL impact of voided reversals</li>
        </ul>
        <p><strong>How to fix:</strong></p>
        <ol>
          <li>Click "View Details" on any invoice with issues</li>
          <li>Review the duplicate reversals</li>
          <li>Click "Void This Reversal" on extra reversals (the first one is kept automatically)</li>
          <li>The system will reverse the GL impact and mark the reversal as voided</li>
        </ol>
      </div>
    </div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const voidButtons = document.querySelectorAll('.void-reversal-btn');
  
  voidButtons.forEach(btn => {
    btn.addEventListener('click', async function() {
      const journalId = this.dataset.journalId;
      const journalNo = this.dataset.journalNo;
      
      if (!confirm(`Are you sure you want to void reversal journal ${journalNo}?\n\nThis will:\n- Mark the reversal as voided\n- Reverse its GL impact\n- Keep the first reversal intact\n\nThis action cannot be undone easily.`)) {
        return;
      }
      
      this.disabled = true;
      this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing...';
      
      try {
        const formData = new FormData();
        formData.append('action', 'void_extra_reversal');
        formData.append('journal_id', journalId);
        
        const response = await fetch('diagnose_reversals.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
          alert('Reversal voided successfully! Page will reload.');
          location.reload();
        } else {
          alert('Error: ' + (result.error || 'Failed to void reversal'));
          this.disabled = false;
          this.innerHTML = '<i class="bi bi-trash"></i> Void This Reversal';
        }
      } catch (error) {
        alert('Error: ' + error.message);
        this.disabled = false;
        this.innerHTML = '<i class="bi bi-trash"></i> Void This Reversal';
      }
    });
  });
  
  // Handle "Void All Extra" button
  const voidAllBtn = document.getElementById('voidAllExtraBtn');
  if (voidAllBtn) {
    voidAllBtn.addEventListener('click', async function() {
      const journalIds = JSON.parse(this.dataset.journalIds || '[]');
      const count = journalIds.length;
      
      if (count === 0) {
        alert('No extra reversals to void.');
        return;
      }
      
      if (!confirm(`Are you sure you want to void ALL ${count} extra reversal(s)?\n\nThis will:\n- Mark all ${count} extra reversals as voided\n- Reverse their GL impact with compensating entries\n- Keep the first/most recent reversals intact\n\nThis action cannot be undone easily.`)) {
        return;
      }
      
      this.disabled = true;
      const originalText = this.innerHTML;
      this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing...';
      
      try {
        const formData = new FormData();
        formData.append('action', 'void_all_extra');
        journalIds.forEach(id => {
          formData.append('journal_ids[]', id);
        });
        
        const response = await fetch('diagnose_reversals.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
          alert(result.message || `Successfully voided ${result.voided_count || count} reversal(s)! Page will reload.`);
          location.reload();
        } else {
          alert('Error: ' + (result.error || 'Failed to void reversals'));
          this.disabled = false;
          this.innerHTML = originalText;
        }
      } catch (error) {
        alert('Error: ' + error.message);
        this.disabled = false;
        this.innerHTML = originalText;
      }
    });
  }
});
</script>
<script>
// Global bulk void (all invoices)
document.addEventListener('DOMContentLoaded', function() {
  const globalBtn = document.getElementById('voidAllGlobalBtn');
  if (!globalBtn) return;
  
  globalBtn.addEventListener('click', async function() {
    const count = parseInt(this.dataset.count || '0', 10);
    if (!count) return;
    
    if (!confirm(`Are you sure you want to void ALL ${count} extra reversal(s) across all invoices?\n\nThis will:\n- Mark all identified extra reversals as voided\n- Post compensating entries to reverse their GL impact\n- Keep correct journals intact\n\nThis action cannot be undone easily.`)) {
      return;
    }
    
    this.disabled = true;
    const originalText = this.innerHTML;
    this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing...';
    
    try {
      const formData = new FormData();
      formData.append('action', 'void_all_extra_global');
      const response = await fetch('diagnose_reversals.php', { method: 'POST', body: formData });
      const result = await response.json();
      if (result.success) {
        alert(result.message || `Voided ${count} reversal(s).`);
        location.reload();
      } else {
        alert('Error: ' + (result.error || 'Failed to void reversals'));
        this.disabled = false;
        this.innerHTML = originalText;
      }
    } catch (e) {
      alert('Error: ' + e.message);
      this.disabled = false;
      this.innerHTML = originalText;
    }
  });
});
</script>
</body>
</html>
