<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_role(['Owner','Admin','Account'], $conn);

$asof = $_GET['asof'] ?? date('Y-m-d');
$from_date = $_GET['from_date'] ?? null;
$to_date = $_GET['to_date'] ?? null;
$action = $_GET['action'] ?? null;
$invoice_id = $_GET['invoice_id'] ?? null;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function moneyv($n){ return number_format((float)$n, 2); }

// Handle actions
$actionMessage = '';
$actionError = '';
if ($action === 'post_invoice' && $invoice_id) {
  require_once __DIR__ . '/../includes/ar_helpers.php';
  require_once __DIR__ . '/../includes/gl_posting.php';
  try {
    ar_post_or_repost_invoice($conn, (int)$invoice_id);
    // Redirect to show updated results
    $redirectParams = ['asof' => $asof];
    if ($from_date) $redirectParams['from_date'] = $from_date;
    if ($to_date) $redirectParams['to_date'] = $to_date;
    $redirectParams['msg'] = 'posted_single';
    header('Location: ?' . http_build_query($redirectParams));
    exit;
  } catch (Exception $e) {
    $actionError = "Failed to post invoice: " . $e->getMessage();
  }
} elseif ($action === 'repost_invoice' && $invoice_id) {
  require_once __DIR__ . '/../includes/ar_helpers.php';
  require_once __DIR__ . '/../includes/gl_posting.php';
  try {
    ar_post_or_repost_invoice($conn, (int)$invoice_id);
    // Redirect to show updated results
    $redirectParams = ['asof' => $asof];
    if ($from_date) $redirectParams['from_date'] = $from_date;
    if ($to_date) $redirectParams['to_date'] = $to_date;
    $redirectParams['msg'] = 'reposted_single';
    header('Location: ?' . http_build_query($redirectParams));
    exit;
  } catch (Exception $e) {
    $actionError = "Failed to repost invoice: " . $e->getMessage();
  }
} elseif ($action === 'repost_all_mismatched') {
  require_once __DIR__ . '/../includes/ar_helpers.php';
  require_once __DIR__ . '/../includes/gl_posting.php';
  try {
    $conn->beginTransaction();
    $reposted = 0;
    $errors = [];
    
    // Get all invoices in AR Ageing (same query as main diagnostic - simplified)
    $arSql = "
      SELECT i.id, i.invoice_no
      FROM invoices i
      LEFT JOIN (
        SELECT invoice_id, SUM(amount_applied) as amount_paid
        FROM receipt_allocations
        GROUP BY invoice_id
      ) pa ON pa.invoice_id = i.id
      WHERE i.status IN ('issued', 'partially_paid')
        AND i.status != 'void'
        AND " . ar_collectible_invoice_sql('i') . "
        AND (i.total - COALESCE(pa.amount_paid, 0)) > 0
        " . ($from_date && $to_date ? "AND i.issue_date BETWEEN ? AND ?" : "") . "
    ";
    $arParams = [];
    if ($from_date && $to_date) {
      $arParams[] = $from_date;
      $arParams[] = $to_date;
    }
    $st = $conn->prepare($arSql);
    $st->execute($arParams);
    $invoicesToRepost = $st->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($invoicesToRepost as $inv) {
      try {
        ar_post_or_repost_invoice($conn, (int)$inv['id']);
        $reposted++;
      } catch (Exception $e) {
        $errors[] = "Invoice #{$inv['invoice_no']}: " . $e->getMessage();
      }
    }
    
    $conn->commit();
    // Redirect to show updated results
    $redirectParams = ['asof' => $asof];
    if ($from_date) $redirectParams['from_date'] = $from_date;
    if ($to_date) $redirectParams['to_date'] = $to_date;
    $redirectParams['msg'] = 'reposted_all';
    $redirectParams['reposted_count'] = $reposted;
    if (!empty($errors)) {
      $redirectParams['errors'] = count($errors);
    }
    header('Location: ?' . http_build_query($redirectParams));
    exit;
  } catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    $actionError = "Failed to repost invoices: " . $e->getMessage();
  }
} elseif ($action === 'post_all_missing') {
  require_once __DIR__ . '/../includes/ar_helpers.php';
  require_once __DIR__ . '/../includes/gl_posting.php';
  try {
    $conn->beginTransaction();
    $posted = 0;
    $errors = [];
    
    // Get invoices in AR Ageing that don't have GL journals
    $missingSt = $conn->prepare("
      SELECT DISTINCT i.id
      FROM invoices i
      LEFT JOIN (
        SELECT invoice_id, SUM(amount_applied) as amount_paid
        FROM receipt_allocations
        GROUP BY invoice_id
      ) pa ON pa.invoice_id = i.id
      WHERE i.status IN ('issued', 'partially_paid', 'paid')
        AND i.status != 'void'
        AND " . ar_collectible_invoice_sql('i') . "
        AND i.total > COALESCE(pa.amount_paid, 0)
        AND NOT EXISTS (
          SELECT 1 FROM gl_journals j
          WHERE j.source = 'invoice'
            AND j.source_id = i.id
            AND j.is_posted = 1
            AND j.is_reversed = 0
        )
        AND NOT EXISTS (
          SELECT 1 
          FROM gl_journals inv_j
          JOIN gl_journals rev_j ON rev_j.source = 'reversal' AND rev_j.source_id = inv_j.id
          WHERE inv_j.source = 'invoice' 
            AND inv_j.source_id = i.id
            AND inv_j.is_posted = 1
            AND rev_j.is_posted = 1
            AND rev_j.is_reversed = 0
        )
    ");
    $missingSt->execute();
    $missingIds = $missingSt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($missingIds as $invId) {
      try {
        ar_post_or_repost_invoice($conn, (int)$invId);
        $posted++;
      } catch (Exception $e) {
        $errors[] = "Invoice #{$invId}: " . $e->getMessage();
      }
    }
    
    $conn->commit();
    // Redirect to show updated results
    $redirectParams = ['asof' => $asof];
    if ($from_date) $redirectParams['from_date'] = $from_date;
    if ($to_date) $redirectParams['to_date'] = $to_date;
    $redirectParams['msg'] = 'posted_all';
    $redirectParams['posted_count'] = $posted;
    if (!empty($errors)) {
      $redirectParams['errors'] = count($errors);
    }
    header('Location: ?' . http_build_query($redirectParams));
    exit;
  } catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    $actionError = "Failed to post invoices: " . $e->getMessage();
  }
}

// Handle success messages from redirect
if (isset($_GET['msg'])) {
  if ($_GET['msg'] === 'posted_single') {
    $actionMessage = "Invoice has been posted to GL successfully. The page has been refreshed with updated data.";
  } elseif ($_GET['msg'] === 'posted_all') {
    $count = (int)($_GET['posted_count'] ?? 0);
    $actionMessage = "Posted {$count} invoice(s) to GL successfully. The page has been refreshed with updated data.";
    if (isset($_GET['errors']) && (int)$_GET['errors'] > 0) {
      $actionError = "Some invoices failed to post. Please check the list below.";
    }
  } elseif ($_GET['msg'] === 'reposted_single') {
    $actionMessage = "Invoice has been reposted to GL successfully. The page has been refreshed with updated data.";
  } elseif ($_GET['msg'] === 'reposted_all') {
    $count = (int)($_GET['reposted_count'] ?? 0);
    $actionMessage = "Reposted {$count} invoice(s) to GL successfully. The page has been refreshed with updated data.";
    if (isset($_GET['errors']) && (int)$_GET['errors'] > 0) {
      $actionError = "Some invoices failed to repost. Please check the list below.";
    }
  }
}

// Get AR Ageing Report Total (same logic as report_ar_ap_ageing.php - simplified)
$arSql = "
  SELECT 
    i.id,
    i.invoice_no,
    i.issue_date,
    i.total as invoice_total,
    COALESCE(pa.amount_paid, 0) as amount_paid,
    GREATEST(ROUND(i.total - COALESCE(pa.amount_paid, 0), 2), 0) as balance_due,
    i.status
  FROM invoices i
  LEFT JOIN (
    SELECT invoice_id, SUM(amount_applied) as amount_paid
    FROM receipt_allocations
    GROUP BY invoice_id
  ) pa ON pa.invoice_id = i.id
  WHERE i.status IN ('issued', 'partially_paid')
    AND i.status != 'void'
    AND " . ar_collectible_invoice_sql('i') . "
    AND (i.total - COALESCE(pa.amount_paid, 0)) > 0
    " . ($from_date && $to_date ? "AND i.issue_date BETWEEN ? AND ?" : "") . "
  ORDER BY i.issue_date
";

$arParams = [];
if ($from_date && $to_date) {
  $arParams[] = $from_date;
  $arParams[] = $to_date;
}
$st = $conn->prepare($arSql);
$st->execute($arParams);
$arInvoices = $st->fetchAll(PDO::FETCH_ASSOC);

$arAgeingTotal = 0;
foreach ($arInvoices as $inv) {
  $arAgeingTotal += (float)$inv['balance_due'];
}

// Get GL Trade Receivables Balance (same logic as report_trial_balance.php)
// Handle both YYYY-MM and YYYY-MM-DD formats
if (strlen($asof) === 7) {
  $periodEnd = date('Y-m-t', strtotime($asof . '-01')); // Last day of the month
} else {
  $periodEnd = $asof; // Use the date as-is
}

// Get GL Trade Receivables Balance - use INNER JOIN to ensure we only get accounts with journal entries
// This matches the logic from report_trial_balance.php exactly
$glSt = $conn->prepare("
  SELECT 
    a.id, 
    a.account_no, 
    a.name,
    a.normal_balance,
    COALESCE(SUM(l.debit), 0) AS total_debit,
    COALESCE(SUM(l.credit), 0) AS total_credit,
    COALESCE(SUM(
      CASE WHEN a.normal_balance = 'debit' 
        THEN l.debit - l.credit 
        ELSE l.credit - l.debit 
      END
    ), 0) AS net_balance
  FROM chart_of_accounts a
  INNER JOIN gl_journal_lines l ON l.account_id = a.id
  INNER JOIN gl_journals j ON j.id = l.journal_id
  WHERE a.account_no = '1110'
    AND a.is_active = 1
    AND j.journal_date <= ?
    AND j.is_posted = 1 
    AND j.is_reversed = 0
    AND NOT (j.source = 'reversal' AND EXISTS (SELECT 1 FROM gl_journals aj WHERE aj.source = 'adjustment' AND aj.source_id = j.id))
    AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Compensating entry: Voiding duplicate reversal%')
    AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Void incorrect bank transfer%')
    AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Bank Transfer%' AND j.is_reversed = 1)
    -- Exclude orphaned reversals: reversals for voided invoices where original debit journal doesn't exist
    AND NOT (j.source = 'reversal' AND j.source_id IS NOT NULL AND EXISTS (
      SELECT 1 FROM gl_journals orig_j
      LEFT JOIN invoices inv ON inv.id = orig_j.source_id
      WHERE orig_j.id = j.source_id
        AND orig_j.source = 'invoice'
        AND (inv.status = 'void' OR inv.id IS NULL)
        AND NOT EXISTS (
          SELECT 1 FROM gl_journal_lines orig_l
          JOIN gl_journals orig_j2 ON orig_j2.id = orig_l.journal_id
          WHERE orig_l.journal_id = orig_j.id
            AND orig_l.account_id = a.id
            AND orig_l.debit > 0
            AND orig_j2.is_posted = 1
        )
    ))
  GROUP BY a.id, a.account_no, a.name, a.normal_balance
");
$glSt->execute([$periodEnd]);
$glBalance = $glSt->fetch(PDO::FETCH_ASSOC);

// If no GL entries found, set default values
if (!$glBalance) {
  $glBalance = [
    'total_debit' => 0,
    'total_credit' => 0,
    'net_balance' => 0
  ];
}

// Get detailed breakdown
// 1. Total invoice debits (invoices posted to GL)
$invoiceDebitsSt = $conn->prepare("
  SELECT 
    COALESCE(SUM(l.debit), 0) as total_debit
  FROM gl_journal_lines l
  JOIN gl_journals j ON j.id = l.journal_id
  JOIN chart_of_accounts a ON a.id = l.account_id
  WHERE a.account_no = '1110'
    AND j.source = 'invoice'
    AND j.journal_date <= ?
    AND j.is_posted = 1
    AND j.is_reversed = 0
    AND NOT EXISTS (
      SELECT 1 FROM gl_journals rev_j 
      WHERE rev_j.source = 'reversal' 
        AND rev_j.source_id = j.id
        AND rev_j.is_posted = 1
        AND rev_j.is_reversed = 0
        AND NOT EXISTS (
          SELECT 1 FROM gl_journals adj_j 
          WHERE adj_j.source = 'adjustment' 
            AND adj_j.source_id = rev_j.id
        )
    )
");
$invoiceDebitsSt->execute([$periodEnd]);
$invoiceDebits = (float)$invoiceDebitsSt->fetchColumn();

// 2. Total receipt credits (payments posted to GL)
$receiptCreditsSt = $conn->prepare("
  SELECT 
    COALESCE(SUM(l.credit), 0) as total_credit
  FROM gl_journal_lines l
  JOIN gl_journals j ON j.id = l.journal_id
  JOIN chart_of_accounts a ON a.id = l.account_id
  WHERE a.account_no = '1110'
    AND j.source = 'receipt'
    AND j.journal_date <= ?
    AND j.is_posted = 1
    AND j.is_reversed = 0
    AND NOT EXISTS (
      SELECT 1 FROM gl_journals rev_j 
      WHERE rev_j.source = 'reversal' 
        AND rev_j.source_id = j.id
        AND rev_j.is_posted = 1
        AND rev_j.is_reversed = 0
        AND NOT EXISTS (
          SELECT 1 FROM gl_journals adj_j 
          WHERE adj_j.source = 'adjustment' 
            AND adj_j.source_id = rev_j.id
        )
    )
");
$receiptCreditsSt->execute([$periodEnd]);
$receiptCredits = (float)$receiptCreditsSt->fetchColumn();

// 2b. Total reversal credits (excluding orphaned reversals for voided invoices)
$reversalCreditsSt = $conn->prepare("
  SELECT 
    COALESCE(SUM(l.credit), 0) as total_credit
  FROM gl_journal_lines l
  JOIN gl_journals j ON j.id = l.journal_id
  JOIN chart_of_accounts a ON a.id = l.account_id
  WHERE a.account_no = '1110'
    AND j.source = 'reversal'
    AND j.journal_date <= ?
    AND j.is_posted = 1
    AND j.is_reversed = 0
    AND NOT EXISTS (
      SELECT 1 FROM gl_journals rev_j 
      WHERE rev_j.source = 'reversal' 
        AND rev_j.source_id = j.id
        AND rev_j.is_posted = 1
        AND rev_j.is_reversed = 0
        AND NOT EXISTS (
          SELECT 1 FROM gl_journals adj_j 
          WHERE adj_j.source = 'adjustment' 
            AND adj_j.source_id = rev_j.id
        )
    )
    AND NOT (j.source_id IS NOT NULL AND EXISTS (
      SELECT 1 FROM gl_journals orig_j
      LEFT JOIN invoices inv ON inv.id = orig_j.source_id
      WHERE orig_j.id = j.source_id
        AND orig_j.source = 'invoice'
        AND (inv.status = 'void' OR inv.id IS NULL)
        AND NOT EXISTS (
          SELECT 1 FROM gl_journal_lines orig_l
          JOIN gl_journals orig_j2 ON orig_j2.id = orig_l.journal_id
          WHERE orig_l.journal_id = orig_j.id
            AND orig_l.account_id = a.id
            AND orig_l.debit > 0
            AND orig_j2.is_posted = 1
        )
    ))
");
$reversalCreditsSt->execute([$periodEnd]);
$reversalCredits = (float)$reversalCreditsSt->fetchColumn();

// 2c. Find orphaned reversals (reversals for voided invoices without original debits)
$orphanedReversalsSt = $conn->prepare("
  SELECT 
    j.id as journal_id,
    j.journal_no,
    j.journal_date,
    j.source_id as original_journal_id,
    l.credit as credit_amount,
    inv.invoice_no,
    inv.status as invoice_status
  FROM gl_journal_lines l
  JOIN gl_journals j ON j.id = l.journal_id
  JOIN chart_of_accounts a ON a.id = l.account_id
  LEFT JOIN gl_journals orig_j ON orig_j.id = j.source_id
  LEFT JOIN invoices inv ON inv.id = orig_j.source_id
  WHERE a.account_no = '1110'
    AND j.source = 'reversal'
    AND j.journal_date <= ?
    AND j.is_posted = 1
    AND j.is_reversed = 0
    AND j.source_id IS NOT NULL
    AND orig_j.source = 'invoice'
    AND (inv.status = 'void' OR inv.id IS NULL)
    AND NOT EXISTS (
      SELECT 1 FROM gl_journal_lines orig_l
      JOIN gl_journals orig_j2 ON orig_j2.id = orig_l.journal_id
      WHERE orig_l.journal_id = orig_j.id
        AND orig_l.account_id = a.id
        AND orig_l.debit > 0
        AND orig_j2.is_posted = 1
    )
    AND NOT EXISTS (
      SELECT 1 FROM gl_journals adj_j 
      WHERE adj_j.source = 'adjustment' 
        AND adj_j.source_id = j.id
    )
");
$orphanedReversalsSt->execute([$periodEnd]);
$orphanedReversals = $orphanedReversalsSt->fetchAll(PDO::FETCH_ASSOC);
$orphanedReversalsTotal = array_sum(array_column($orphanedReversals, 'credit_amount'));

// 3. Total allocated payments (from receipt_allocations)
$allocatedPaymentsSt = $conn->prepare("
  SELECT COALESCE(SUM(amount_applied), 0) as total_allocated
  FROM receipt_allocations ra
  JOIN receipts r ON r.id = ra.receipt_id
  WHERE r.receipt_date <= ?
");
$allocatedPaymentsSt->execute([$periodEnd]);
$allocatedPayments = (float)$allocatedPaymentsSt->fetchColumn();

// 4. Total receipt amounts (full receipt amounts, not just allocated)
$totalReceiptsSt = $conn->prepare("
  SELECT COALESCE(SUM(amount), 0) as total_receipts
  FROM receipts r
  WHERE r.receipt_date <= ?
");
$totalReceiptsSt->execute([$periodEnd]);
$totalReceipts = (float)$totalReceiptsSt->fetchColumn();

// 5. Check for invoices in AR Ageing that might not be in GL
$invoicesNotInGL = [];
$invoicesNotInGLTotal = 0;
foreach ($arInvoices as $inv) {
  $checkSt = $conn->prepare("
    SELECT COUNT(*) 
    FROM gl_journals j
    WHERE j.source = 'invoice'
      AND j.source_id = ?
      AND j.is_posted = 1
      AND j.is_reversed = 0
      AND NOT EXISTS (
        SELECT 1 FROM gl_journals rev_j 
        WHERE rev_j.source = 'reversal' 
          AND rev_j.source_id = j.id
          AND rev_j.is_posted = 1
          AND rev_j.is_reversed = 0
          AND NOT EXISTS (
            SELECT 1 FROM gl_journals adj_j 
            WHERE adj_j.source = 'adjustment' 
              AND adj_j.source_id = rev_j.id
          )
      )
  ");
  $checkSt->execute([$inv['id']]);
  $hasGL = $checkSt->fetchColumn() > 0;
  if (!$hasGL) {
    $invoicesNotInGL[] = $inv;
    $invoicesNotInGLTotal += (float)$inv['balance_due'];
  }
}

// 6. Check for invoices in GL that might not be in AR Ageing
$glInvoicesSt = $conn->prepare("
  SELECT DISTINCT j.source_id as invoice_id
  FROM gl_journals j
  JOIN gl_journal_lines l ON l.journal_id = j.id
  JOIN chart_of_accounts a ON a.id = l.account_id
  WHERE a.account_no = '1110'
    AND j.source = 'invoice'
    AND j.journal_date <= ?
    AND j.is_posted = 1
    AND j.is_reversed = 0
    AND NOT EXISTS (
      SELECT 1 FROM gl_journals rev_j 
      WHERE rev_j.source = 'reversal' 
        AND rev_j.source_id = j.id
        AND rev_j.is_posted = 1
        AND rev_j.is_reversed = 0
        AND NOT EXISTS (
          SELECT 1 FROM gl_journals adj_j 
          WHERE adj_j.source = 'adjustment' 
            AND adj_j.source_id = rev_j.id
        )
    )
");
$glInvoicesSt->execute([$periodEnd]);
$glInvoiceIds = $glInvoicesSt->fetchAll(PDO::FETCH_COLUMN);

$invoicesInGLNotInAgeing = [];
if (!empty($glInvoiceIds)) {
  $placeholders = str_repeat('?,', count($glInvoiceIds) - 1) . '?';
  $checkSt = $conn->prepare("
    SELECT i.id, i.invoice_no, i.total, i.status
    FROM invoices i
    WHERE i.id IN ($placeholders)
      AND (
        i.status NOT IN ('issued', 'partially_paid', 'paid')
        OR i.status = 'void'
        OR i.total <= COALESCE((
          SELECT SUM(amount_applied) 
          FROM receipt_allocations 
          WHERE invoice_id = i.id
        ), 0)
      )
  ");
  $checkSt->execute($glInvoiceIds);
  $invoicesInGLNotInAgeing = $checkSt->fetchAll(PDO::FETCH_ASSOC);
}

$difference = abs($arAgeingTotal - ($glBalance['net_balance'] ?? 0));

// 7. Detailed invoice-by-invoice comparison
// For each invoice in AR Ageing, calculate what GL shows
$invoiceComparison = [];
foreach ($arInvoices as $inv) {
  // Get GL balance for this specific invoice
  $invGLSt = $conn->prepare("
    SELECT 
      COALESCE(SUM(
        CASE WHEN a.normal_balance = 'debit' 
          THEN l.debit - l.credit 
          ELSE l.credit - l.debit 
        END
      ), 0) AS gl_balance
    FROM gl_journal_lines l
    JOIN gl_journals j ON j.id = l.journal_id
    JOIN chart_of_accounts a ON a.id = l.account_id
    WHERE a.account_no = '1110'
      AND j.journal_date <= ?
      AND j.is_posted = 1
      AND j.is_reversed = 0
      AND NOT (j.source = 'reversal' AND EXISTS (SELECT 1 FROM gl_journals aj WHERE aj.source = 'adjustment' AND aj.source_id = j.id))
      AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Compensating entry: Voiding duplicate reversal%')
      AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Void incorrect bank transfer%')
      AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Bank Transfer%' AND j.is_reversed = 1)
      AND (
        (j.source = 'invoice' AND j.source_id = ?)
        OR (j.source = 'receipt' AND EXISTS (
          SELECT 1 FROM receipt_allocations ra 
          WHERE ra.receipt_id = j.source_id 
          AND ra.invoice_id = ?
        ))
        OR (j.source = 'reversal' AND EXISTS (
          SELECT 1 FROM gl_journals orig_j
          WHERE orig_j.id = j.source_id
          AND orig_j.source = 'invoice'
          AND orig_j.source_id = ?
        ))
      )
  ");
  $invGLSt->execute([$periodEnd, $inv['id'], $inv['id'], $inv['id']]);
  $invGLBalance = (float)($invGLSt->fetchColumn() ?: 0);
  
  // Get invoice debit from GL
  $invDebitSt = $conn->prepare("
    SELECT COALESCE(SUM(l.debit), 0) as invoice_debit
    FROM gl_journal_lines l
    JOIN gl_journals j ON j.id = l.journal_id
    JOIN chart_of_accounts a ON a.id = l.account_id
    WHERE a.account_no = '1110'
      AND j.source = 'invoice'
      AND j.source_id = ?
      AND j.journal_date <= ?
      AND j.is_posted = 1
      AND j.is_reversed = 0
      AND NOT EXISTS (
        SELECT 1 FROM gl_journals rev_j 
        WHERE rev_j.source = 'reversal' 
          AND rev_j.source_id = j.id
          AND rev_j.is_posted = 1
          AND rev_j.is_reversed = 0
          AND NOT EXISTS (
            SELECT 1 FROM gl_journals adj_j 
            WHERE adj_j.source = 'adjustment' 
              AND adj_j.source_id = rev_j.id
          )
      )
  ");
  $invDebitSt->execute([$inv['id'], $periodEnd]);
  $invDebit = (float)($invDebitSt->fetchColumn() ?: 0);
  
  // Get receipt credits for this invoice from GL
  $invCreditSt = $conn->prepare("
    SELECT COALESCE(SUM(l.credit), 0) as receipt_credit
    FROM gl_journal_lines l
    JOIN gl_journals j ON j.id = l.journal_id
    JOIN chart_of_accounts a ON a.id = l.account_id
    JOIN receipt_allocations ra ON ra.receipt_id = j.source_id
    WHERE a.account_no = '1110'
      AND j.source = 'receipt'
      AND ra.invoice_id = ?
      AND j.journal_date <= ?
      AND j.is_posted = 1
      AND j.is_reversed = 0
      AND NOT EXISTS (
        SELECT 1 FROM gl_journals rev_j 
        WHERE rev_j.source = 'reversal' 
          AND rev_j.source_id = j.id
          AND rev_j.is_posted = 1
          AND rev_j.is_reversed = 0
          AND NOT EXISTS (
            SELECT 1 FROM gl_journals adj_j 
            WHERE adj_j.source = 'adjustment' 
              AND adj_j.source_id = rev_j.id
          )
      )
  ");
  $invCreditSt->execute([$inv['id'], $periodEnd]);
  $invCredit = (float)($invCreditSt->fetchColumn() ?: 0);
  
  $arBalance = (float)$inv['balance_due'];
  $glBalanceForInvoice = $invDebit - $invCredit;
  $invoiceDiff = abs($arBalance - $glBalanceForInvoice);
  
  $invoiceComparison[] = [
    'invoice_id' => $inv['id'],
    'invoice_no' => $inv['invoice_no'],
    'issue_date' => $inv['issue_date'],
    'invoice_total' => (float)$inv['invoice_total'],
    'amount_paid' => (float)$inv['amount_paid'],
    'ar_balance' => $arBalance,
    'gl_debit' => $invDebit,
    'gl_credit' => $invCredit,
    'gl_balance' => $glBalanceForInvoice,
    'difference' => $invoiceDiff
  ];
}

// Sort by difference (largest first)
usort($invoiceComparison, function($a, $b) {
  return $b['difference'] <=> $a['difference'];
});

// Find invoices with significant differences
$problematicInvoices = array_filter($invoiceComparison, function($inv) {
  return $inv['difference'] > 0.01; // More than 1 cent difference
});

// Calculate total difference from problematic invoices
$totalProblematicDiff = array_sum(array_column($problematicInvoices, 'difference'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>AR Discrepancy Diagnosis</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .summary-box { background: #f8f9fa; border-left: 4px solid #0d6efd; padding: 15px; margin-bottom: 20px; }
  .difference-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-bottom: 20px; }
  .error-box { background: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin-bottom: 20px; }
</style>
</head>
<body class="bg-light">
<div class="container my-4">
  <h3><i class="bi bi-search"></i> AR Discrepancy Diagnosis</h3>
  <p class="text-muted">Comparing AR Ageing Report vs Trade Receivables (1110) in GL/Trial Balance</p>

  <form method="GET" class="row g-3 mb-4">
    <div class="col-md-3">
      <label class="form-label">As of Date</label>
      <input type="date" name="asof" value="<?= h($asof) ?>" class="form-control">
    </div>
    <div class="col-md-3">
      <label class="form-label">From Date (Optional)</label>
      <input type="date" name="from_date" value="<?= h($from_date ?? '') ?>" class="form-control">
    </div>
    <div class="col-md-3">
      <label class="form-label">To Date (Optional)</label>
      <input type="date" name="to_date" value="<?= h($to_date ?? '') ?>" class="form-control">
    </div>
    <div class="col-md-3">
      <label class="form-label">&nbsp;</label>
      <div class="d-grid">
        <button type="submit" class="btn btn-primary">Analyze</button>
      </div>
    </div>
  </form>

  <!-- Summary -->
  <div class="summary-box">
    <h5>Summary</h5>
    <?php if ($from_date && $to_date): ?>
      <div class="alert alert-warning mb-3">
        <i class="bi bi-exclamation-triangle"></i> <strong>Date Filter Active:</strong> 
        AR Ageing Report is filtered to show only invoices issued between <strong><?= h($from_date) ?></strong> and <strong><?= h($to_date) ?></strong>.
        To see ALL outstanding AR, remove the date filters.
        <br><small class="text-muted">The GL Balance below shows ALL invoices up to <?= h($periodEnd) ?>, not filtered by issue date.</small>
      </div>
    <?php endif; ?>
    <div class="row">
      <div class="col-md-6">
        <strong>AR Ageing Report Total:</strong><br>
        <span class="h4 text-primary"><?= moneyv($arAgeingTotal) ?> AED</span><br>
        <small class="text-muted">
          Based on: invoice_total - allocated_payments
          <?php if ($from_date && $to_date): ?>
            <br><span class="text-warning">⚠ Filtered by issue date: <?= h($from_date) ?> to <?= h($to_date) ?></span>
          <?php else: ?>
            <br>All outstanding invoices
          <?php endif; ?>
        </small>
      </div>
      <div class="col-md-6">
        <strong>Trade Receivables (1110) GL Balance:</strong><br>
        <span class="h4 <?= ($glBalance['net_balance'] ?? 0) < 0 ? 'text-danger' : 'text-success' ?>">
          <?= moneyv($glBalance['net_balance'] ?? 0) ?> AED
        </span>
        <?php if (($glBalance['net_balance'] ?? 0) < 0): ?>
          <br><small class="text-danger"><i class="bi bi-exclamation-triangle"></i> Warning: Negative balance (unusual for Trade Receivables)</small>
        <?php endif; ?>
        <br>
        <small class="text-muted">
          Based on: GL journal entries (debits - credits) up to <?= h($periodEnd) ?>
          <br>Shows ALL invoices, not filtered by issue date
        </small>
      </div>
    </div>
    <hr>
    <div class="row">
      <div class="col-md-12">
        <strong>Difference:</strong><br>
        <span class="h3 <?= abs($difference) < 0.01 ? 'text-success' : 'text-danger' ?>">
          <?= moneyv($difference) ?> AED
        </span>
        <?php if (abs($difference) < 0.01): ?>
          <span class="badge bg-success ms-2">Balanced!</span>
        <?php else: ?>
          <span class="badge bg-danger ms-2">Mismatch!</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Detailed Breakdown -->
  <div class="card mb-4">
    <div class="card-header">
      <h5 class="mb-0">Detailed Breakdown</h5>
    </div>
    <div class="card-body">
      <table class="table table-bordered">
        <tr>
          <th>Component</th>
          <th class="text-end">Amount (AED)</th>
          <th>Description</th>
        </tr>
        <tr>
          <td><strong>GL: Invoice Debits</strong></td>
          <td class="text-end"><?= moneyv($invoiceDebits) ?></td>
          <td>Total invoice amounts posted to Trade Receivables (1110)</td>
        </tr>
        <tr>
          <td><strong>GL: Receipt Credits</strong></td>
          <td class="text-end text-danger">-<?= moneyv($receiptCredits) ?></td>
          <td>Total receipt amounts credited to Trade Receivables (1110)</td>
        </tr>
        <tr>
          <td><strong>GL: Reversal Credits</strong></td>
          <td class="text-end text-danger">-<?= moneyv($reversalCredits) ?></td>
          <td>Total reversal amounts credited to Trade Receivables (1110) - excluding orphaned reversals</td>
        </tr>
        <?php if (!empty($orphanedReversals)): ?>
        <tr class="table-warning">
          <td><strong>GL: Orphaned Reversals (Excluded)</strong></td>
          <td class="text-end text-danger">-<?= moneyv($orphanedReversalsTotal) ?></td>
          <td>Orphaned reversals for voided invoices without original debits - now excluded from GL balance</td>
        </tr>
        <?php endif; ?>
        <tr class="table-light">
          <td><strong>GL: Net Balance</strong></td>
          <td class="text-end"><strong><?= moneyv($glBalance['net_balance'] ?? 0) ?></strong></td>
          <td>Invoice Debits - (Receipt Credits + Valid Reversal Credits)</td>
        </tr>
        <tr>
          <td colspan="3"><hr></td>
        </tr>
        <tr>
          <td><strong>Receipts: Total Receipt Amounts</strong></td>
          <td class="text-end"><?= moneyv($totalReceipts) ?></td>
          <td>Full amounts of all receipts (matches GL receipt credits)</td>
        </tr>
        <tr>
          <td><strong>Receipts: Allocated Amounts</strong></td>
          <td class="text-end"><?= moneyv($allocatedPayments) ?></td>
          <td>Amounts allocated to invoices via receipt_allocations</td>
        </tr>
        <tr class="table-warning">
          <td><strong>Receipts: Unallocated Amounts</strong></td>
          <td class="text-end"><strong><?= moneyv($totalReceipts - $allocatedPayments) ?></strong></td>
          <td>Receipt amounts not allocated to any invoice (this could cause discrepancy!)</td>
        </tr>
        <tr>
          <td colspan="3"><hr></td>
        </tr>
        <tr>
          <td><strong>AR Ageing: Total Outstanding</strong></td>
          <td class="text-end"><strong><?= moneyv($arAgeingTotal) ?></strong></td>
          <td>Sum of (invoice_total - allocated_payments) for eligible invoices</td>
        </tr>
      </table>
    </div>
  </div>

  <!-- Orphaned Reversals for Voided Invoices -->
  <?php if (!empty($orphanedReversals)): ?>
    <div class="error-box">
      <h5><i class="bi bi-exclamation-triangle"></i> Found <?= count($orphanedReversals) ?> Orphaned Reversal(s) for Voided Invoices</h5>
      <p class="mb-2">These reversals have credits but no corresponding original debit journal. Total: <strong><?= moneyv($orphanedReversalsTotal) ?> AED</strong></p>
      <p class="mb-2 text-danger"><strong>This was causing the negative GL balance!</strong> These reversals are now excluded from GL calculations in all reports.</p>
      <div class="table-responsive">
        <table class="table table-sm table-bordered">
          <thead>
            <tr>
              <th>Journal No</th>
              <th>Date</th>
              <th>Invoice No</th>
              <th>Invoice Status</th>
              <th class="text-end">Credit Amount</th>
              <th>Issue</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orphanedReversals as $rev): ?>
              <tr>
                <td><?= h($rev['journal_no']) ?></td>
                <td><?= h($rev['journal_date']) ?></td>
                <td><?= h($rev['invoice_no'] ?? 'N/A') ?></td>
                <td><?= h($rev['invoice_status'] ?? 'Deleted') ?></td>
                <td class="text-end text-danger"><?= moneyv($rev['credit_amount']) ?></td>
                <td>
                  <small class="text-danger">
                    Reversal for voided/deleted invoice without original debit journal
                  </small>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="table-light">
              <th colspan="4" class="text-end">Total:</th>
              <th class="text-end text-danger"><?= moneyv($orphanedReversalsTotal) ?></th>
              <th></th>
            </tr>
          </tfoot>
        </table>
      </div>
      <p class="mt-2 small text-muted">
        <strong>Note:</strong> These orphaned reversals are now excluded from GL balance calculations in all reports (Trial Balance, General Ledger, Balance Sheet). 
        The GL balance should now be correct. If you want to remove these orphaned reversals completely from the database, use the 
        <a href="validate_trade_receivable.php" class="alert-link">Validate Trade Receivable</a> tool.
      </p>
    </div>
  <?php endif; ?>

  <!-- Issues Found -->
  <?php if (!empty($invoicesNotInGL)): ?>
    <div class="error-box">
      <h5><i class="bi bi-exclamation-triangle"></i> Invoices in AR Ageing but NOT in GL</h5>
      <p>These invoices appear in the AR Ageing Report but don't have posted GL journals:</p>
      <table class="table table-sm">
        <thead>
          <tr>
            <th>Invoice No</th>
            <th>Issue Date</th>
            <th>Total</th>
            <th>Status</th>
            <th class="text-end">Balance Due</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($invoicesNotInGL as $inv): ?>
            <tr>
              <td><?= h($inv['invoice_no']) ?></td>
              <td><?= h($inv['issue_date']) ?></td>
              <td><?= moneyv($inv['invoice_total']) ?></td>
              <td><?= h($inv['status']) ?></td>
              <td class="text-end"><?= moneyv($inv['balance_due']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if (!empty($invoicesInGLNotInAgeing)): ?>
    <div class="error-box">
      <h5><i class="bi bi-exclamation-triangle"></i> Invoices in GL but NOT in AR Ageing</h5>
      <p>These invoices have GL journals but are excluded from AR Ageing Report:</p>
      <table class="table table-sm">
        <thead>
          <tr>
            <th>Invoice No</th>
            <th>Total</th>
            <th>Status</th>
            <th>Reason</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($invoicesInGLNotInAgeing as $inv): ?>
            <tr>
              <td><?= h($inv['invoice_no']) ?></td>
              <td><?= moneyv($inv['total']) ?></td>
              <td><?= h($inv['status']) ?></td>
              <td>
                <?php
                  $allocSt = $conn->prepare("SELECT COALESCE(SUM(amount_applied), 0) FROM receipt_allocations WHERE invoice_id = ?");
                  $allocSt->execute([$inv['id']]);
                  $allocated = (float)$allocSt->fetchColumn();
                  $reasons = [];
                  if (!in_array($inv['status'], ['issued', 'partially_paid', 'paid'])) {
                    $reasons[] = "Status: " . $inv['status'];
                  }
                  if ($inv['status'] === 'void') {
                    $reasons[] = "Voided";
                  }
                  if ($inv['total'] <= $allocated) {
                    $reasons[] = "Fully paid (total: " . moneyv($inv['total']) . ", allocated: " . moneyv($allocated) . ")";
                  }
                  echo implode(', ', $reasons);
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <!-- Action Messages -->
  <?php if ($actionMessage): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <i class="bi bi-check-circle"></i> <?= h($actionMessage) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  <?php if ($actionError): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <i class="bi bi-exclamation-triangle"></i> <?= h($actionError) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Reconciliation Actions -->
  <?php if (abs($difference) > 0.01): ?>
    <div class="card mt-4 border-warning">
      <div class="card-header bg-warning bg-opacity-10">
        <h5 class="mb-0"><i class="bi bi-tools"></i> Reconciliation & Fix Actions</h5>
      </div>
      <div class="card-body">
        <p class="mb-3">The following actions can help resolve the discrepancy:</p>
        
        <!-- Invoices Not in GL -->
        <?php if (!empty($invoicesNotInGL)): ?>
          <div class="alert alert-info">
            <h6><i class="bi bi-info-circle"></i> Found <?= count($invoicesNotInGL) ?> invoice(s) in AR Ageing that are NOT posted to GL</h6>
            <p class="mb-2">Total outstanding amount: <strong><?= moneyv($invoicesNotInGLTotal) ?> AED</strong></p>
            <p class="mb-2 small text-muted">These invoices need to be posted to GL to match the AR Ageing report. This is likely the main cause of the discrepancy.</p>
            <div class="d-flex gap-2">
              <a href="?<?= http_build_query(array_merge($_GET, ['action' => 'post_all_missing'])) ?>" 
                 class="btn btn-primary"
                 onclick="return confirm('This will post all <?= count($invoicesNotInGL) ?> missing invoices to GL. Continue?')">
                <i class="bi bi-upload"></i> Post All Missing Invoices to GL
              </a>
              <button type="button" class="btn btn-outline-info" data-bs-toggle="collapse" data-bs-target="#missingInvoicesList">
                <i class="bi bi-list"></i> View List
              </button>
            </div>
            <div class="collapse mt-3" id="missingInvoicesList">
              <div class="table-responsive">
                <table class="table table-sm table-bordered">
                  <thead>
                    <tr>
                      <th>Invoice No</th>
                      <th>Issue Date</th>
                      <th>Total</th>
                      <th>Allocated</th>
                      <th class="text-end">Balance Due</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($invoicesNotInGL as $inv): ?>
                      <tr>
                        <td><?= h($inv['invoice_no']) ?></td>
                        <td><?= h($inv['issue_date']) ?></td>
                        <td><?= moneyv($inv['invoice_total']) ?></td>
                        <td><?= moneyv($inv['amount_paid']) ?></td>
                        <td class="text-end"><?= moneyv($inv['balance_due']) ?></td>
                        <td>
                          <a href="?<?= http_build_query(array_merge($_GET, ['action' => 'post_invoice', 'invoice_id' => $inv['id']])) ?>" 
                             class="btn btn-sm btn-outline-primary"
                             onclick="return confirm('Post invoice <?= h($inv['invoice_no']) ?> to GL?')">
                            <i class="bi bi-upload"></i> Post
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                  <tfoot>
                    <tr class="table-light">
                      <th colspan="4" class="text-end">Total:</th>
                      <th class="text-end"><?= moneyv($invoicesNotInGLTotal) ?></th>
                      <th></th>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div class="alert alert-success">
            <i class="bi bi-check-circle"></i> All invoices in AR Ageing are posted to GL.
          </div>
        <?php endif; ?>

        <!-- Summary of Fix Impact -->
        <?php if (!empty($invoicesNotInGL)): ?>
          <div class="alert alert-warning mt-3">
            <h6><i class="bi bi-calculator"></i> Expected Impact After Fix</h6>
            <p class="mb-1">If you post all missing invoices to GL:</p>
            <ul class="mb-0">
              <li>GL Trade Receivables will increase by approximately <strong><?= moneyv($invoicesNotInGLTotal) ?> AED</strong></li>
              <li>New GL Balance: <strong><?= moneyv($glBalance['net_balance'] + $invoicesNotInGLTotal) ?> AED</strong></li>
              <li>Remaining Difference: <strong><?= moneyv(abs($arAgeingTotal - ($glBalance['net_balance'] + $invoicesNotInGLTotal))) ?> AED</strong></li>
            </ul>
            <p class="mt-2 mb-0 small text-muted">
              <strong>Note:</strong> The remaining difference (if any) may be due to:
              - Date filtering differences
              - Invoices that should be excluded from AR Ageing but aren't
              - Other timing/status differences
            </p>
          </div>
        <?php endif; ?>

        <!-- Invoice-by-Invoice Comparison -->
        <?php if (!empty($problematicInvoices)): ?>
          <div class="alert alert-warning mt-3">
            <h6><i class="bi bi-exclamation-triangle"></i> Found <?= count($problematicInvoices) ?> Invoice(s) with Mismatched Balances</h6>
            <p class="mb-2">These invoices show different balances in AR Ageing vs GL. Total difference: <strong><?= moneyv($totalProblematicDiff) ?> AED</strong></p>
            <div class="d-flex gap-2 mb-2">
              <a href="?<?= http_build_query(array_merge($_GET, ['action' => 'repost_all_mismatched'])) ?>" 
                 class="btn btn-warning btn-sm"
                 onclick="return confirm('This will repost all <?= count($arInvoices) ?> invoices in AR Ageing to GL to sync balances. Continue?')">
                <i class="bi bi-arrow-clockwise"></i> Repost All AR Ageing Invoices to GL
              </a>
              <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="collapse" data-bs-target="#invoiceComparison">
                <i class="bi bi-list"></i> View Detailed Comparison
              </button>
            </div>
            <div class="collapse mt-3" id="invoiceComparison">
              <div class="table-responsive">
                <table class="table table-sm table-bordered">
                  <thead class="table-light">
                    <tr>
                      <th>Invoice No</th>
                      <th>Issue Date</th>
                      <th class="text-end">Invoice Total</th>
                      <th class="text-end">Allocated</th>
                      <th class="text-end">AR Balance</th>
                      <th class="text-end">GL Debit</th>
                      <th class="text-end">GL Credit</th>
                      <th class="text-end">GL Balance</th>
                      <th class="text-end">Difference</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($problematicInvoices as $comp): ?>
                      <tr class="<?= $comp['difference'] > 1 ? 'table-warning' : '' ?>">
                        <td><?= h($comp['invoice_no']) ?></td>
                        <td><?= h($comp['issue_date']) ?></td>
                        <td class="text-end"><?= moneyv($comp['invoice_total']) ?></td>
                        <td class="text-end"><?= moneyv($comp['amount_paid']) ?></td>
                        <td class="text-end"><strong><?= moneyv($comp['ar_balance']) ?></strong></td>
                        <td class="text-end"><?= moneyv($comp['gl_debit']) ?></td>
                        <td class="text-end"><?= moneyv($comp['gl_credit']) ?></td>
                        <td class="text-end"><strong><?= moneyv($comp['gl_balance']) ?></strong></td>
                        <td class="text-end text-danger"><strong><?= moneyv($comp['difference']) ?></strong></td>
                        <td>
                          <a href="?<?= http_build_query(array_merge($_GET, ['action' => 'repost_invoice', 'invoice_id' => $comp['invoice_id']])) ?>" 
                             class="btn btn-sm btn-outline-warning"
                             onclick="return confirm('Repost invoice <?= h($comp['invoice_no']) ?> to GL?')"
                             title="Repost this invoice to sync GL balance">
                            <i class="bi bi-arrow-clockwise"></i>
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                  <tfoot class="table-light">
                    <tr>
                      <th colspan="4" class="text-end">Total Difference:</th>
                      <th class="text-end"><?= moneyv(array_sum(array_column($problematicInvoices, 'ar_balance'))) ?></th>
                      <th class="text-end"><?= moneyv(array_sum(array_column($problematicInvoices, 'gl_debit'))) ?></th>
                      <th class="text-end"><?= moneyv(array_sum(array_column($problematicInvoices, 'gl_credit'))) ?></th>
                      <th class="text-end"><?= moneyv(array_sum(array_column($problematicInvoices, 'gl_balance'))) ?></th>
                      <th class="text-end text-danger"><strong><?= moneyv($totalProblematicDiff) ?></strong></th>
                      <th></th>
                    </tr>
                  </tfoot>
                </table>
              </div>
              <p class="mt-2 small text-muted">
                <strong>Explanation:</strong> AR Balance = Invoice Total - Allocated Payments. GL Balance = GL Invoice Debit - GL Receipt Credits.
                Differences can occur if:
                - Receipts are allocated but not yet posted to GL
                - Invoice amounts changed after GL posting
                - Date filtering excludes some GL entries
              </p>
            </div>
          </div>
        <?php elseif (!empty($invoiceComparison)): ?>
          <div class="alert alert-success mt-3">
            <i class="bi bi-check-circle"></i> All invoices in AR Ageing match their GL balances (within rounding tolerance).
          </div>
        <?php endif; ?>

        <!-- Other Recommendations -->
        <div class="mt-3">
          <h6>Other Recommendations:</h6>
          <ul>
            <li>Check if date filtering is causing the difference. Try removing date filters and compare again.</li>
            <li>Verify that all invoices have correct statuses (should be 'issued', 'partially_paid', or 'paid').</li>
            <li>Ensure all receipts are properly allocated to invoices.</li>
            <li>Review the "Invoices in GL but NOT in AR Ageing" section above - these are fully paid invoices that are correctly excluded from AR Ageing.</li>
            <?php if (!empty($problematicInvoices)): ?>
              <li><strong>Action:</strong> Review the invoices with mismatched balances above. You may need to:
                <ul>
                  <li>Repost invoices that have been modified</li>
                  <li>Ensure all receipts are posted to GL</li>
                  <li>Check if date filtering is excluding relevant GL entries</li>
                </ul>
              </li>
            <?php endif; ?>
          </ul>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Explanation -->
  <div class="card mt-4">
    <div class="card-header">
      <h5 class="mb-0">Why There Might Be a Difference</h5>
    </div>
    <div class="card-body">
      <ol>
        <li><strong>Unallocated Receipts:</strong> If receipts have unallocated amounts, the GL credits Trade Receivables by the full receipt amount, but AR Ageing only counts allocated amounts. This creates a discrepancy.</li>
        <li><strong>Date Filtering:</strong> <strong class="text-danger">IMPORTANT:</strong> If you have date filters (From Date/To Date) applied, the AR Ageing Report will ONLY show invoices issued in that date range. The GL Balance shows ALL invoices up to the "As of Date", regardless of issue date. To see ALL outstanding AR, remove the date filters from the AR Ageing Report.</li>
        <li><strong>Invoice Status:</strong> AR Ageing only includes invoices with status 'issued', 'partially_paid', or 'paid'. Invoices with other statuses won't appear in AR Ageing but may still be in GL.</li>
        <li><strong>Timing Differences:</strong> If an invoice is created but not yet posted to GL, or if a payment is allocated but not yet posted to GL, there will be a temporary discrepancy.</li>
        <li><strong>Reversal Handling:</strong> Both reports exclude reversed invoices, but the logic might differ slightly in edge cases.</li>
      </ol>
      <p class="text-muted mt-3">
        <strong>Expected Behavior:</strong> The AR Ageing Report should match the Trade Receivables GL balance when:
        - All receipts are fully allocated to invoices
        - All invoices are posted to GL
        - No date filtering is applied (or both use the same date range)
        - All invoices have valid statuses
      </p>
    </div>
  </div>

  <div class="mt-3">
    <a href="reports.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Reports</a>
  </div>
</div>
</body>
</html>

