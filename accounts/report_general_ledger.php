<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/cleaning_accounting_context.php';
require_once __DIR__.'/../includes/report_date_helpers.php';
require_role(['Owner','Admin','Account'], $conn);

$dates = report_date_range();
$from = $dates['from'];
$to = $dates['to'];
$account_id = $_GET['account_id'] ?? null;
$export = isset($_GET['export']) && $_GET['export']==='csv';
$cleaningCompanyId = cleaning_accounting_company_id($conn);
$cleaningCompanyName = cleaning_accounting_company_name($conn);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function moneyv($n){ return number_format((float)$n, 2); }

// Get all accounts for dropdown
$accounts = [];
$st = $conn->prepare("
  SELECT id, account_no, name, type 
  FROM chart_of_accounts 
  WHERE is_active=1 AND is_header=0 AND company_id = ?
  ORDER BY account_no
");
$st->execute([$cleaningCompanyId]);
$accounts = $st->fetchAll(PDO::FETCH_ASSOC);

// Build the main query for GL entries
$whereClause = "j.journal_date BETWEEN ? AND ? AND j.company_id = ? AND j.is_posted=1 AND j.is_reversed=0";
$params = [$from, $to, $cleaningCompanyId];

if ($account_id) {
  $whereClause .= " AND l.account_id = ?";
  $params[] = $account_id;
}

$whereClause .= " AND NOT (j.source = 'reversal' AND EXISTS (SELECT 1 FROM gl_journals aj WHERE aj.source = 'adjustment' AND aj.source_id = j.id))";
$whereClause .= " AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Compensating entry: Voiding duplicate reversal%')";
$whereClause .= " AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Void incorrect bank transfer%')";
$whereClause .= " AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Bank Transfer%' AND j.is_reversed = 1)";
// Exclude orphaned reversals: reversals for voided invoices where original debit journal doesn't exist or wasn't properly reversed
$whereClause .= " AND NOT (j.source = 'reversal' AND j.source_id IS NOT NULL AND EXISTS (
  SELECT 1 FROM gl_journals orig_j
  LEFT JOIN invoices inv ON inv.id = orig_j.source_id
  WHERE orig_j.id = j.source_id
    AND orig_j.source = 'invoice'
    AND (inv.status = 'void' OR inv.id IS NULL)
    AND NOT EXISTS (
      SELECT 1 FROM gl_journal_lines orig_l
      JOIN gl_journals orig_j2 ON orig_j2.id = orig_l.journal_id
      WHERE orig_l.journal_id = orig_j.id
        AND orig_l.account_id = l.account_id
        AND orig_l.debit > 0
        AND orig_j2.is_posted = 1
    )
))";
// Exclude orphaned reversals (reversals without valid original invoice journals OR where invoice was deleted)
$whereClause .= " AND NOT (j.source = 'reversal' AND j.source_id IS NOT NULL AND (
    -- Original journal doesn't exist
    NOT EXISTS (SELECT 1 FROM gl_journals orig WHERE orig.id = j.source_id)
    -- OR original journal is not an invoice
    OR NOT EXISTS (SELECT 1 FROM gl_journals orig WHERE orig.id = j.source_id AND orig.source = 'invoice' AND orig.is_posted = 1)
    -- OR original invoice journal exists but invoice was deleted
    OR EXISTS (
      SELECT 1 FROM gl_journals orig 
      LEFT JOIN invoices inv ON inv.id = orig.source_id 
      WHERE orig.id = j.source_id 
        AND orig.source = 'invoice' 
        AND orig.is_posted = 1
        AND orig.source_id IS NOT NULL
        AND inv.id IS NULL
    )
  ))";

$sql = "
  SELECT 
    j.id as journal_id,
    j.journal_no,
    j.journal_date,
    j.source,
    j.source_id,
    j.is_reversed,
    j.memo,
    a.account_no,
    a.name as account_name,
    a.type as account_type,
    a.normal_balance,
    l.description as line_description,
    l.debit,
    l.credit,
    -- Get original invoice info for reversals and invoices
    CASE 
      WHEN j.source = 'reversal' AND j.source_id IS NOT NULL THEN
        (SELECT CONCAT('Reverses: ', i.invoice_no, ' (J#', orig_j.id, ')')
         FROM gl_journals orig_j
         LEFT JOIN invoices i ON i.id = orig_j.source_id AND orig_j.source = 'invoice'
         WHERE orig_j.id = j.source_id)
      WHEN j.source = 'invoice' AND j.source_id IS NOT NULL THEN
        (SELECT CONCAT('Invoice: ', invoice_no) FROM invoices WHERE id = j.source_id)
      WHEN j.source = 'receipt' AND j.source_id IS NOT NULL THEN
        (SELECT GROUP_CONCAT(DISTINCT i.invoice_no ORDER BY i.invoice_no SEPARATOR ', ')
         FROM receipt_allocations ra
         INNER JOIN invoices i ON i.id = ra.invoice_id
         WHERE ra.receipt_id = j.source_id)
      ELSE NULL
    END as invoice_info
  FROM gl_journal_lines l
  JOIN gl_journals j ON j.id = l.journal_id
  JOIN chart_of_accounts a ON a.id = l.account_id
  WHERE {$whereClause}
  ORDER BY a.account_no, j.journal_date, j.journal_no, l.line_no
";

$st = $conn->prepare($sql);
$st->execute($params);
$allEntries = $st->fetchAll(PDO::FETCH_ASSOC);

// Filter out duplicate invoice entries - keep only the most recent journal per invoice
// Also filter out entries for deleted invoices (orphaned journals) if desired
$filterOrphaned = true; // Set to false if you want to keep orphaned journal entries

// First, identify all unique invoice+journal combinations
$invoiceJournalMap = []; // invoice_id => [journal_id => true]
$validInvoiceIds = []; // Cache of valid invoice IDs

if ($filterOrphaned) {
  // Get all valid invoice IDs to filter out orphaned journals
  $validInvs = $conn->query("SELECT id FROM invoices")->fetchAll(PDO::FETCH_COLUMN);
  $validInvoiceIds = array_flip(array_map('intval', $validInvs));
}

foreach ($allEntries as $entry) {
  if ($entry['source'] === 'invoice' && !empty($entry['source_id'])) {
    $invoiceId = (int)$entry['source_id'];
    
    // Skip orphaned journals if filtering is enabled
    if ($filterOrphaned && !isset($validInvoiceIds[$invoiceId])) {
      continue; // Skip entries for deleted invoices
    }
    
    $journalId = (int)$entry['journal_id'];
    
    if (!isset($invoiceJournalMap[$invoiceId])) {
      $invoiceJournalMap[$invoiceId] = [];
    }
    $invoiceJournalMap[$invoiceId][$journalId] = true;
  }
}

// For each invoice with multiple journals, identify which one to keep (most recent = highest ID)
$keepJournalIds = [];
$excludeJournalIds = [];

foreach ($invoiceJournalMap as $invoiceId => $journalIds) {
  $journalIdArray = array_keys($journalIds);
  
  if (count($journalIdArray) > 1) {
    // Multiple journals for same invoice - keep only the most recent (highest ID)
    $keepJournalId = max($journalIdArray);
    $keepJournalIds[$keepJournalId] = true;
    
    // Mark others as duplicates to exclude
    foreach ($journalIdArray as $jid) {
      if ($jid !== $keepJournalId) {
        $excludeJournalIds[$jid] = true;
      }
    }
  } else {
    // Single journal - keep it
    $keepJournalIds[$journalIdArray[0]] = true;
  }
}

// Build final entries array - exclude duplicate invoice journals and orphaned entries
$entries = [];
foreach ($allEntries as $entry) {
  $journalId = (int)$entry['journal_id'];
  
  // If it's an invoice journal, check if we should exclude it
  if ($entry['source'] === 'invoice' && !empty($entry['source_id'])) {
    $invoiceId = (int)$entry['source_id'];
    
    // Skip orphaned journals (deleted invoices) if filtering is enabled
    if ($filterOrphaned && !isset($validInvoiceIds[$invoiceId])) {
      continue; // Skip entries for deleted invoices
    }
    
    // Skip duplicate journals
    if (isset($excludeJournalIds[$journalId])) {
      continue; // This is a duplicate - skip it
    }
  }
  
  // Include this entry (either not an invoice, or it's the kept journal, or it's a valid invoice)
  $entries[] = $entry;
}

// Get opening balances (before the date range) for each account
$openingBalances = [];
if ($account_id) {
  // Single account - get opening balance
  $openSt = $conn->prepare("
    SELECT 
      a.id,
      a.account_no,
      a.normal_balance,
      COALESCE(SUM(
        CASE WHEN a.normal_balance = 'debit' 
          THEN l.debit - l.credit 
          ELSE l.credit - l.debit 
        END
      ), 0) AS opening_balance
    FROM chart_of_accounts a
    LEFT JOIN gl_journal_lines l ON l.account_id = a.id
    LEFT JOIN gl_journals j ON j.id = l.journal_id
    WHERE a.id = ?
      AND j.journal_date < ?
      AND j.company_id = ?
      AND j.is_posted = 1 
      AND j.is_reversed = 0
      AND NOT (j.source = 'reversal' AND EXISTS (SELECT 1 FROM gl_journals aj WHERE aj.source = 'adjustment' AND aj.source_id = j.id))
      AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Compensating entry: Voiding duplicate reversal%')
      AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Void incorrect bank transfer%')
      AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Bank Transfer%' AND j.is_reversed = 1)
      -- Exclude orphaned reversals for voided invoices
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
    GROUP BY a.id, a.account_no, a.normal_balance
  ");
  $openSt->execute([$account_id, $from, $cleaningCompanyId]);
  $openRow = $openSt->fetch(PDO::FETCH_ASSOC);
  if ($openRow) {
    $openingBalances[$openRow['account_no']] = (float)$openRow['opening_balance'];
  }
} else {
  // All accounts - get opening balances for all
  $openSt = $conn->prepare("
    SELECT 
      a.account_no,
      a.normal_balance,
      COALESCE(SUM(
        CASE WHEN a.normal_balance = 'debit' 
          THEN l.debit - l.credit 
          ELSE l.credit - l.debit 
        END
      ), 0) AS opening_balance
    FROM chart_of_accounts a
    LEFT JOIN gl_journal_lines l ON l.account_id = a.id
    LEFT JOIN gl_journals j ON j.id = l.journal_id
    WHERE j.journal_date < ?
      AND j.company_id = ?
      AND j.is_posted = 1 
      AND j.is_reversed = 0
      AND NOT (j.source = 'reversal' AND EXISTS (SELECT 1 FROM gl_journals aj WHERE aj.source = 'adjustment' AND aj.source_id = j.id))
      AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Compensating entry: Voiding duplicate reversal%')
      AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Void incorrect bank transfer%')
      AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Bank Transfer%' AND j.is_reversed = 1)
      -- Exclude orphaned reversals for voided invoices
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
    GROUP BY a.account_no, a.normal_balance
  ");
  $openSt->execute([$from, $cleaningCompanyId]);
  while ($openRow = $openSt->fetch(PDO::FETCH_ASSOC)) {
    $openingBalances[$openRow['account_no']] = (float)$openRow['opening_balance'];
  }
}

// Group entries by account and calculate running balances
$ledger = [];
$runningBalances = [];

foreach ($entries as $entry) {
  $account_id = $entry['account_no'];
  
  if (!isset($ledger[$account_id])) {
    // Initialize with opening balance
    $openingBal = $openingBalances[$account_id] ?? 0.0;
    $runningBalances[$account_id] = $openingBal;
    
    $ledger[$account_id] = [
      'account_no' => $entry['account_no'],
      'account_name' => $entry['account_name'],
      'account_type' => $entry['account_type'],
      'normal_balance' => $entry['normal_balance'],
      'opening_balance' => $openingBal,
      'entries' => []
    ];
    
    // Add opening balance as first entry if not zero
    if (abs($openingBal) > 0.01) {
      $ledger[$account_id]['entries'][] = [
        'journal_no' => 'OPENING',
        'journal_date' => $from,
        'source' => 'opening',
        'memo' => 'Opening Balance',
        'description' => 'Opening Balance (before ' . $from . ')',
        'debit' => 0,
        'credit' => 0,
        'running_balance' => $openingBal,
        'is_opening' => true
      ];
    }
  }
  
  $debit = (float)$entry['debit'];
  $credit = (float)$entry['credit'];
  
  // Calculate running balance based on normal balance
  if ($entry['normal_balance'] === 'debit') {
    $runningBalances[$account_id] += $debit - $credit;
  } else {
    $runningBalances[$account_id] += $credit - $debit;
  }
  
  $ledger[$account_id]['entries'][] = [
    'journal_id' => $entry['journal_id'],
    'journal_no' => $entry['journal_no'],
    'journal_date' => $entry['journal_date'],
    'source' => $entry['source'],
    'source_id' => $entry['source_id'],
    'is_reversed' => $entry['is_reversed'],
    'memo' => $entry['memo'],
    'description' => $entry['line_description'],
    'invoice_info' => $entry['invoice_info'] ?? null,
    'debit' => $debit,
    'credit' => $credit,
    'running_balance' => $runningBalances[$account_id],
    'is_opening' => false
  ];
}

if ($export) {
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="general_ledger_'.$from.'_to_'.$to.'.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Company', $cleaningCompanyName]);
  fputcsv($out, ['Account No', 'Account Name', 'Date', 'Journal No', 'Source', 'Description', 'Debit', 'Credit', 'Running Balance']);
  
  foreach ($ledger as $account) {
    foreach ($account['entries'] as $entry) {
      fputcsv($out, [
        $account['account_no'],
        $account['account_name'],
        $entry['journal_date'],
        $entry['journal_no'],
        $entry['source'],
        $entry['description'] ?: $entry['memo'],
        moneyv($entry['debit']),
        moneyv($entry['credit']),
        moneyv($entry['running_balance'])
      ]);
    }
  }
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>General Ledger</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .account-header { background: #f8f9fa; border-left: 4px solid #800000; }
  .entry-row { border-bottom: 1px solid #dee2e6; }
  .entry-row:hover { background: #f8f9fa; }
  .running-balance { font-weight: bold; }
  .debit { color: #dc3545; }
  .credit { color: #198754; }
</style>
</head>
<body class="bg-light">
<div class="container my-4">
  
  <div class="row mb-4">
    <div class="col">
      <h3><i class="bi bi-journal-text"></i> General Ledger</h3>
    </div>
    <div class="col-auto">
      <a href="fix_duplicate_invoice_journals.php" class="btn btn-outline-warning me-2" title="Check and fix duplicate invoice journals">
        <i class="bi bi-exclamation-triangle"></i> Fix Duplicates
      </a>
      <a href="reports.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Reports</a>
    </div>
  </div>

  <!-- Filters -->
  <div class="card mb-4">
    <div class="card-body">
      <form method="GET" class="row g-3">
        <div class="col-md-3">
          <label class="form-label">From Date</label>
          <input type="date" name="from" value="<?= h($from) ?>" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label">To Date</label>
          <input type="date" name="to" value="<?= h($to) ?>" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label">Account (Optional)</label>
          <select name="account_id" class="form-select">
            <option value="">All Accounts</option>
            <?php foreach ($accounts as $acc): ?>
              <option value="<?= $acc['id'] ?>" <?= $account_id == $acc['id'] ? 'selected' : '' ?>>
                <?= h($acc['account_no']) ?> - <?= h($acc['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">&nbsp;</label>
          <div class="d-grid">
            <button type="submit" class="btn btn-primary">Run</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Export Button -->
  <div class="mb-3">
    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" 
       class="btn btn-outline-success">
      <i class="bi bi-download"></i> Export CSV
    </a>
  </div>

  <!-- Diagnostic: Check for duplicate reversals -->
  <?php if ($account_id): 
    // Check if there are multiple reversals for the same original journal
    $dupCheck = $conn->prepare("
      SELECT 
        j.source_id as original_journal_id,
        COUNT(*) as reversal_count,
        GROUP_CONCAT(j.id ORDER BY j.id) as reversal_journal_ids,
        GROUP_CONCAT(j.journal_no ORDER BY j.id SEPARATOR ', ') as reversal_journal_nos,
        orig_j.memo as original_memo
      FROM gl_journals j
      JOIN gl_journals orig_j ON orig_j.id = j.source_id
      WHERE j.source = 'reversal'
        AND j.source_id IS NOT NULL
        AND j.is_posted = 1
        AND EXISTS (
          SELECT 1 FROM gl_journal_lines l 
          WHERE l.journal_id = j.id 
          AND l.account_id = ?
        )
      GROUP BY j.source_id
      HAVING reversal_count > 1
    ");
    $dupCheck->execute([$account_id]);
    $duplicates = $dupCheck->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($duplicates)):
  ?>
    <div class="alert alert-danger mb-3">
      <h6><i class="bi bi-exclamation-triangle"></i> <strong>Warning: Duplicate Reversals Detected!</strong></h6>
      <p class="mb-2">The following journals have been reversed multiple times (this should not happen):</p>
      <ul class="mb-0">
        <?php foreach ($duplicates as $dup): ?>
          <li>
            <strong>Original Journal #<?= $dup['original_journal_id'] ?></strong>: 
            <?= h($dup['original_memo']) ?><br>
            <span class="text-danger">Reversed <?= $dup['reversal_count'] ?> times</span> 
            (Journals: <?= h($dup['reversal_journal_nos']) ?>)
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="mb-0 mt-2"><small>This indicates a bug where the same journal was reversed multiple times. The system now prevents this, but existing duplicate reversals need to be manually corrected.</small></p>
    </div>
  <?php endif; endif; ?>

  <!-- Ledger Display -->
  <?php if (empty($ledger)): ?>
    <div class="alert alert-info">
      <i class="bi bi-info-circle"></i> No entries found for the selected period.
    </div>
  <?php else: ?>
    <?php foreach ($ledger as $account): ?>
      <div class="card mb-4">
        <div class="card-header account-header">
          <div class="row">
            <div class="col">
              <h5 class="mb-0">
                <?= h($account['account_no']) ?> - <?= h($account['account_name']) ?>
              </h5>
              <small class="text-muted"><?= h($account['account_type']) ?> (<?= h($account['normal_balance']) ?>)</small>
            </div>
            <div class="col-auto">
              <span class="badge bg-primary"><?= count($account['entries']) ?> entries</span>
              <?php if (abs($account['opening_balance']) > 0.01): ?>
                <span class="badge bg-warning ms-1">Opening: <?= moneyv($account['opening_balance']) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead class="table-light">
                <tr>
                  <th>Date</th>
                  <th>Journal No</th>
                  <th>Source</th>
                  <th>Description</th>
                  <th class="text-end">Debit</th>
                  <th class="text-end">Credit</th>
                  <th class="text-end">Balance</th>
                </tr>
              </thead>
              <tbody>
                <?php if (abs($account['opening_balance']) > 0.01): ?>
                  <tr class="entry-row" style="background-color: #fff3cd;">
                    <td><strong><?= h($from) ?></strong></td>
                    <td><code>OPENING</code></td>
                    <td><span class="badge bg-warning">Opening</span></td>
                    <td><strong>Opening Balance (before <?= h($from) ?>)</strong></td>
                    <td class="text-end">—</td>
                    <td class="text-end">—</td>
                    <td class="text-end running-balance">
                      <strong><?= moneyv($account['opening_balance']) ?></strong>
                    </td>
                  </tr>
                <?php endif; ?>
                <?php foreach ($account['entries'] as $entry): 
                  if (isset($entry['is_opening']) && $entry['is_opening']) continue; // Skip duplicate opening entry
                ?>
                  <tr class="entry-row">
                    <td><?= h($entry['journal_date']) ?></td>
                    <td>
                      <code><?= h($entry['journal_no']) ?></code>
                    </td>
                    <td>
                      <span class="badge bg-secondary"><?= h($entry['source']) ?></span>
                    </td>
                    <td>
                      <div><?= h($entry['description'] ?: $entry['memo']) ?></div>
                      <?php if ($entry['invoice_info']): ?>
                        <?php if ($entry['source'] === 'receipt'): ?>
                          <small class="text-muted"><strong>Invoice(s):</strong> <?= h($entry['invoice_info']) ?></small>
                        <?php else: ?>
                          <small class="text-muted"><?= h($entry['invoice_info']) ?></small>
                        <?php endif; ?>
                      <?php endif; ?>
                      <?php if ($entry['is_reversed']): ?>
                        <br><small class="text-danger"><i class="bi bi-exclamation-triangle"></i> This journal has been reversed</small>
                      <?php endif; ?>
                    </td>
                    <td class="text-end debit">
                      <?= $entry['debit'] > 0 ? moneyv($entry['debit']) : '' ?>
                    </td>
                    <td class="text-end credit">
                      <?= $entry['credit'] > 0 ? moneyv($entry['credit']) : '' ?>
                    </td>
                    <td class="text-end running-balance">
                      <?= moneyv($entry['running_balance']) ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!empty($account['entries'])): 
                  $finalBalance = end($account['entries'])['running_balance'];
                ?>
                  <tr class="table-light" style="border-top: 2px solid #000;">
                    <td colspan="4" class="text-end"><strong>Closing Balance (as of <?= h($to) ?>)</strong></td>
                    <td class="text-end">—</td>
                    <td class="text-end">—</td>
                    <td class="text-end running-balance">
                      <strong><?= moneyv($finalBalance) ?></strong>
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>
</body>
</html>
