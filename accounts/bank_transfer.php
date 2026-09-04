<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/gl_posting.php';
require_role(['Owner','Admin','Account'], $conn);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function moneyv($n){ return number_format((float)$n, 2); }

// Get current balances for 1020 and 1030
$period = date('Y-m'); // Current month
$periodEnd = date('Y-m-t'); // Last day of current month

$balance1020 = 0.0;
$balance1030 = 0.0;

// Get account IDs
$acc1020 = $conn->prepare("SELECT id, account_no, name, normal_balance FROM chart_of_accounts WHERE account_no='1020' LIMIT 1");
$acc1020->execute();
$acc1020Data = $acc1020->fetch(PDO::FETCH_ASSOC);

$acc1030 = $conn->prepare("SELECT id, account_no, name, normal_balance FROM chart_of_accounts WHERE account_no='1030' LIMIT 1");
$acc1030->execute();
$acc1030Data = $acc1030->fetch(PDO::FETCH_ASSOC);

if ($acc1020Data && $acc1030Data) {
  // Calculate cumulative balances up to period end
  $balSt = $conn->prepare("
    SELECT 
      a.id,
      a.account_no,
      a.normal_balance,
      COALESCE(SUM(
        CASE WHEN a.normal_balance = 'debit' 
          THEN l.debit - l.credit 
          ELSE l.credit - l.debit 
        END
      ), 0) AS cumulative_balance
    FROM chart_of_accounts a
    LEFT JOIN gl_journal_lines l ON l.account_id = a.id
    LEFT JOIN gl_journals j ON j.id = l.journal_id
    WHERE a.account_no IN ('1020', '1030')
      AND j.journal_date <= ?
      AND j.is_posted = 1 
      AND j.is_reversed = 0
      AND NOT (j.source = 'reversal' AND EXISTS (SELECT 1 FROM gl_journals aj WHERE aj.source = 'adjustment' AND aj.source_id = j.id))
      AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Bank Transfer%' AND j.is_reversed = 0)
      AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Void incorrect bank transfer%')
    GROUP BY a.id, a.account_no, a.normal_balance
  ");
  $balSt->execute([$periodEnd]);
  $balances = $balSt->fetchAll(PDO::FETCH_ASSOC);
  
  foreach ($balances as $bal) {
    if ($bal['account_no'] === '1020') {
      $balance1020 = (float)$bal['cumulative_balance'];
    } elseif ($bal['account_no'] === '1030') {
      $balance1030 = (float)$bal['cumulative_balance'];
    }
  }
}

$msg = '';
$err = '';

// Handle cleanup of incorrect bank transfer entries
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup'])) {
  try {
    csrf_verify();
    
    $conn->beginTransaction();
    
    // Find all bank transfer adjustment journals
    $transferJournals = $conn->prepare("
      SELECT j.id, j.journal_no, j.journal_date, j.memo,
             GROUP_CONCAT(CONCAT(l.account_id, ':', l.debit, ':', l.credit) ORDER BY l.line_no SEPARATOR '|') as line_data
      FROM gl_journals j
      JOIN gl_journal_lines l ON l.journal_id = j.id
      WHERE j.source = 'adjustment' 
        AND j.memo LIKE 'Bank Transfer%'
        AND j.is_posted = 1
        AND j.is_reversed = 0
      GROUP BY j.id
      ORDER BY j.journal_date DESC, j.id DESC
    ");
    $transferJournals->execute();
    $journals = $transferJournals->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($journals)) {
      throw new RuntimeException("No bank transfer journals found to clean up.");
    }
    
    // First, identify which entry is correct (Dr 1030, Cr 1020)
    $correctJournalId = null;
    foreach ($journals as $journal) {
      $journalId = (int)$journal['id'];
      
      $linesSt = $conn->prepare("
        SELECT l.account_id, a.account_no, l.debit, l.credit
        FROM gl_journal_lines l
        JOIN chart_of_accounts a ON a.id = l.account_id
        WHERE l.journal_id = ?
        ORDER BY l.line_no
      ");
      $linesSt->execute([$journalId]);
      $lines = $linesSt->fetchAll(PDO::FETCH_ASSOC);
      
      $hasDr1030 = false;
      $hasCr1020 = false;
      $hasDr1020 = false;
      $hasCr1030 = false;
      
      foreach ($lines as $line) {
        if ($line['account_no'] === '1030' && (float)$line['debit'] > 0) $hasDr1030 = true;
        if ($line['account_no'] === '1020' && (float)$line['credit'] > 0) $hasCr1020 = true;
        if ($line['account_no'] === '1020' && (float)$line['debit'] > 0) $hasDr1020 = true;
        if ($line['account_no'] === '1030' && (float)$line['credit'] > 0) $hasCr1030 = true;
      }
      
      // This is the correct entry: Dr 1030, Cr 1020
      if ($hasDr1030 && $hasCr1020 && !$hasDr1020 && !$hasCr1030) {
        $correctJournalId = $journalId;
        break;
      }
    }
    
    // Now void all incorrect entries (and their reversals if they exist)
    $voidedCount = 0;
    foreach ($journals as $journal) {
      $journalId = (int)$journal['id'];
      
      // Skip the correct entry
      if ($journalId === $correctJournalId) {
        continue;
      }
      
      // Check if this journal is already voided
      $checkSt = $conn->prepare("SELECT is_reversed, is_posted FROM gl_journals WHERE id=?");
      $checkSt->execute([$journalId]);
      $check = $checkSt->fetch(PDO::FETCH_ASSOC);
      if ($check && (int)$check['is_reversed'] === 1) {
        continue; // Already voided
      }
      
      // Get the lines for this incorrect entry
      $linesSt = $conn->prepare("
        SELECT l.account_id, a.account_no, l.debit, l.credit
        FROM gl_journal_lines l
        JOIN chart_of_accounts a ON a.id = l.account_id
        WHERE l.journal_id = ?
        ORDER BY l.line_no
      ");
      $linesSt->execute([$journalId]);
      $lines = $linesSt->fetchAll(PDO::FETCH_ASSOC);
      
      // Create reversing entry: swap debit/credit for each line
      $revLines = [];
      foreach ($lines as $line) {
        $revDebit = (float)$line['credit'];
        $revCredit = (float)$line['debit'];
        
        if ($revDebit > 0 || $revCredit > 0) {
          $revLines[] = [
            'account_id' => (int)$line['account_id'],
            'desc' => 'Void incorrect bank transfer: Reverse ' . $journal['journal_no'],
            'debit' => $revDebit,
            'credit' => $revCredit
          ];
        }
      }
      
      if (!empty($revLines)) {
        $revJournalId = gl_create_journal($conn, [
          'date' => date('Y-m-d'),
          'source' => 'adjustment',
          'source_id' => $journalId,
          'memo' => 'Void incorrect bank transfer: ' . $journal['journal_no'],
          'created_by' => $_SESSION['user_id'] ?? null,
        ], $revLines);
      }
      
      // Mark original as reversed and unpost it
      $conn->prepare("UPDATE gl_journals SET is_reversed=1, is_posted=0 WHERE id=?")->execute([$journalId]);
      
      $voidedCount++;
    }
    
    // If no correct entry exists, we need to create it
    if ($correctJournalId === null && $voidedCount > 0) {
      // Calculate the amount from the first incorrect entry (they should all be the same amount)
      $firstJournal = $journals[0];
      $firstLinesSt = $conn->prepare("
        SELECT l.account_id, a.account_no, l.debit, l.credit
        FROM gl_journal_lines l
        JOIN chart_of_accounts a ON a.id = l.account_id
        WHERE l.journal_id = ?
        ORDER BY l.line_no
      ");
      $firstLinesSt->execute([(int)$firstJournal['id']]);
      $firstLines = $firstLinesSt->fetchAll(PDO::FETCH_ASSOC);
      
      $transferAmount = 0.0;
      foreach ($firstLines as $line) {
        if ($line['account_no'] === '1030' || $line['account_no'] === '1020') {
          $transferAmount = max($transferAmount, (float)$line['debit'], (float)$line['credit']);
        }
      }
      
      if ($transferAmount > 0.01) {
        // Create the correct entry: Dr 1030, Cr 1020
        $correctLines = [
          ['account_id' => (int)$acc1030Data['id'], 'desc' => 'Bank Transfer: Correct Secondary Bank (1030) - reverse incorrect expense credits', 'debit' => $transferAmount, 'credit' => 0],
          ['account_id' => (int)$acc1020Data['id'], 'desc' => 'Bank Transfer: Apply to Main Bank (1020) - expenses should have come from here', 'debit' => 0, 'credit' => $transferAmount],
        ];
        
        $correctJournalId = gl_create_journal($conn, [
          'date' => date('Y-m-d'),
          'source' => 'adjustment',
          'source_id' => null,
          'memo' => 'Bank Transfer: Secondary Bank (1030) to Main Bank (1020) - Corrected',
          'created_by' => $_SESSION['user_id'] ?? null,
        ], $correctLines);
      }
    }
    
    $conn->commit();
    
    if ($voidedCount > 0) {
      $msg = "Cleanup completed! Voided {$voidedCount} incorrect bank transfer journal(s).";
    } else {
      $msg = "No incorrect entries found. All bank transfer journals are correct.";
    }
    
    // Refresh balances
    $balSt->execute([$periodEnd]);
    $balances = $balSt->fetchAll(PDO::FETCH_ASSOC);
    $balance1020 = 0.0;
    $balance1030 = 0.0;
    foreach ($balances as $bal) {
      if ($bal['account_no'] === '1020') {
        $balance1020 = (float)$bal['cumulative_balance'];
      } elseif ($bal['account_no'] === '1030') {
        $balance1030 = (float)$bal['cumulative_balance'];
      }
    }
    
  } catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    $err = $e->getMessage();
  }
}

// Check for existing bank transfer entries
$existingTransfers = [];
if ($acc1020Data && $acc1030Data) {
  $checkSt = $conn->prepare("
    SELECT j.id, j.journal_no, j.journal_date, j.memo, j.is_posted, j.is_reversed,
           GROUP_CONCAT(CONCAT(a.account_no, ':', l.debit, ':', l.credit) ORDER BY l.line_no SEPARATOR '|') as line_data
    FROM gl_journals j
    JOIN gl_journal_lines l ON l.journal_id = j.id
    JOIN chart_of_accounts a ON a.id = l.account_id
    WHERE j.source = 'adjustment' 
      AND j.memo LIKE 'Bank Transfer%'
      AND a.account_no IN ('1020', '1030')
    GROUP BY j.id
    ORDER BY j.journal_date DESC, j.id DESC
  ");
  $checkSt->execute();
  $existingTransfers = $checkSt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle transfer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer'])) {
  try {
    csrf_verify();
    
    if (!$acc1020Data || !$acc1030Data) {
      throw new RuntimeException("Bank accounts 1020 or 1030 not found in Chart of Accounts");
    }
    
    // Check if a correct transfer already exists
    $existingCheck = $conn->prepare("
      SELECT j.id, j.journal_no
      FROM gl_journals j
      JOIN gl_journal_lines l1030 ON l1030.journal_id = j.id AND l1030.account_id = ? AND l1030.debit > 0
      JOIN gl_journal_lines l1020 ON l1020.journal_id = j.id AND l1020.account_id = ? AND l1020.credit > 0
      WHERE j.source = 'adjustment' 
        AND j.memo LIKE 'Bank Transfer%'
        AND j.is_posted = 1
        AND j.is_reversed = 0
      LIMIT 1
    ");
    $existingCheck->execute([(int)$acc1030Data['id'], (int)$acc1020Data['id']]);
    if ($existingCheck->fetch()) {
      throw new RuntimeException("A correct bank transfer entry already exists. Please use the cleanup tool first if you need to fix incorrect entries.");
    }
    
    // Calculate transfer amount (absolute value of 1030 balance)
    $transferAmount = abs($balance1030);
    
    if ($transferAmount <= 0.01) {
      throw new RuntimeException("No balance to transfer. Secondary Bank Account (1030) balance is already zero or positive.");
    }
    
    $transferDate = $_POST['transfer_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    $memo = 'Bank Transfer: Secondary Bank (1030) to Main Bank (1020)' . ($notes ? ' — ' . $notes : '');
    
    $conn->beginTransaction();
    
    // Create transfer journal entry
    // Since 1030 has a negative balance (-38,291.82), it means credits exceed debits
    // This happened because expenses incorrectly credited 1030 instead of 1020
    // To correct: We need to DEBIT 1030 (reverse the incorrect credits) and CREDIT 1020 (apply to Main Bank)
    // Simple balanced entry: Dr 1030, Cr 1020
    $lines = [
      ['account_id' => (int)$acc1030Data['id'], 'desc' => 'Bank Transfer: Correct Secondary Bank (1030) - reverse incorrect expense credits', 'debit' => $transferAmount, 'credit' => 0],
      ['account_id' => (int)$acc1020Data['id'], 'desc' => 'Bank Transfer: Apply to Main Bank (1020) - expenses should have come from here', 'debit' => 0, 'credit' => $transferAmount],
    ];
    
    $journalId = gl_create_journal($conn, [
      'date' => $transferDate,
      'source' => 'adjustment',
      'source_id' => null,
      'memo' => $memo,
      'created_by' => $_SESSION['user_id'] ?? null,
    ], $lines);
    
    // Audit log
    require_once __DIR__ . '/../includes/AuditService.php';
    AuditService::log([
      'action' => 'insert',
      'object_type' => 'gl_journals',
      'object_id' => (string)$journalId,
      'summary' => "Bank Transfer: Corrected " . moneyv($transferAmount) . " AED - reversed incorrect expense credits from Secondary Bank (1030) and applied to Main Bank (1020)",
      'new_data' => [
        'journal_id' => $journalId,
        'from_account' => '1030',
        'to_account' => '1020',
        'amount' => $transferAmount,
        'transfer_date' => $transferDate
      ],
      'success' => true
    ]);
    
    $conn->commit();
    
    $msg = "Transfer completed successfully! " . moneyv($transferAmount) . " AED corrected - Secondary Bank (1030) balance reset to 0.00, Main Bank (1020) adjusted to reflect expenses.";
    
    // Refresh balances
    $balSt->execute([$periodEnd]);
    $balances = $balSt->fetchAll(PDO::FETCH_ASSOC);
    $balance1020 = 0.0;
    $balance1030 = 0.0;
    foreach ($balances as $bal) {
      if ($bal['account_no'] === '1020') {
        $balance1020 = (float)$bal['cumulative_balance'];
      } elseif ($bal['account_no'] === '1030') {
        $balance1030 = (float)$bal['cumulative_balance'];
      }
    }
    
  } catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    $err = $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Bank Transfer Tool</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .balance-card { border-left: 4px solid #0d6efd; }
  .balance-positive { color: #198754; }
  .balance-negative { color: #dc3545; }
</style>
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="d-flex align-items-center mb-4">
    <h3 class="me-auto"><i class="bi bi-arrow-left-right"></i> Bank Transfer Tool</h3>
    <a href="reports.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Reports</a>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <i class="bi bi-check-circle"></i> <?= h($msg) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <i class="bi bi-exclamation-triangle"></i> <?= h($err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-md-6">
      <div class="card balance-card">
        <div class="card-body">
          <h5 class="card-title">1020 - Main Bank Account</h5>
          <h3 class="<?= $balance1020 >= 0 ? 'balance-positive' : 'balance-negative' ?>">
            <?= moneyv($balance1020) ?> AED
          </h3>
          <small class="text-muted">Current balance as of <?= h($periodEnd) ?></small>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card balance-card">
        <div class="card-body">
          <h5 class="card-title">1030 - Secondary Bank Account</h5>
          <h3 class="<?= $balance1030 >= 0 ? 'balance-positive' : 'balance-negative' ?>">
            <?= moneyv($balance1030) ?> AED
          </h3>
          <small class="text-muted">Current balance as of <?= h($periodEnd) ?></small>
          <?php if ($balance1030 < 0): ?>
            <div class="alert alert-warning mt-2 mb-0">
              <small><i class="bi bi-info-circle"></i> Negative balance indicates credits exceed debits. This transfer will move funds to Main Bank.</small>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if ($acc1020Data && $acc1030Data): ?>
    <div class="card">
      <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-arrow-left-right"></i> Transfer Funds</h5>
      </div>
      <div class="card-body">
        <div class="alert alert-info">
          <h6><i class="bi bi-info-circle"></i> What This Does:</h6>
          <ul class="mb-0">
            <li>Corrects the Secondary Bank Account (1030) negative balance to <strong>0.00</strong></li>
            <li>Reduces Main Bank Account (1020) by <?= moneyv(abs($balance1030)) ?> AED (reflects that expenses should have come from Main Bank)</li>
            <li>Creates a proper GL journal entry: <strong>Dr 1030, Cr 1020</strong></li>
            <li>This reverses the incorrect expense credits from 1030 and applies them to 1020 where they should have been</li>
            <li>After transfer: 1030 = <strong>0.00</strong>, 1020 will decrease by <?= moneyv(abs($balance1030)) ?> AED</li>
            <li><strong>Note:</strong> This is a correction - the expenses were incorrectly posted to 1030, so we're moving them to 1020</li>
          </ul>
        </div>

        <?php if (!empty($existingTransfers)): ?>
          <div class="alert alert-warning mb-3">
            <h6><i class="bi bi-exclamation-triangle"></i> Existing Bank Transfer Entries Found</h6>
            <p>Found <?= count($existingTransfers) ?> bank transfer journal(s). Some may be incorrect duplicates.</p>
            <table class="table table-sm">
              <thead>
                <tr>
                  <th>Journal #</th>
                  <th>Date</th>
                  <th>Status</th>
                  <th>Lines</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($existingTransfers as $et): 
                  $lineData = explode('|', $et['line_data'] ?? '');
                  $lineDesc = [];
                  foreach ($lineData as $line) {
                    if (empty($line)) continue;
                    list($accNo, $dr, $cr) = explode(':', $line);
                    if ((float)$dr > 0) $lineDesc[] = "Dr {$accNo}: " . moneyv($dr);
                    if ((float)$cr > 0) $lineDesc[] = "Cr {$accNo}: " . moneyv($cr);
                  }
                ?>
                  <tr class="<?= $et['is_reversed'] ? 'table-secondary' : '' ?>">
                    <td><code><?= h($et['journal_no']) ?></code></td>
                    <td><?= h($et['journal_date']) ?></td>
                    <td>
                      <?php if ($et['is_reversed']): ?>
                        <span class="badge bg-secondary">Voided</span>
                      <?php else: ?>
                        <span class="badge bg-success">Active</span>
                      <?php endif; ?>
                    </td>
                    <td><small><?= implode(', ', $lineDesc) ?></small></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <form method="post" class="mt-2">
              <?php csrf_field(); ?>
              <button type="submit" name="cleanup" class="btn btn-warning" onclick="return confirm('This will void all INCORRECT bank transfer entries (keeping only Dr 1030, Cr 1020). Continue?');">
                <i class="bi bi-broom"></i> Clean Up Incorrect Entries
              </button>
            </form>
          </div>
        <?php endif; ?>

        <?php if (abs($balance1030) > 0.01): ?>
          <form method="post">
            <?php csrf_field(); ?>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Transfer Date</label>
                <input type="date" class="form-control" name="transfer_date" value="<?= h(date('Y-m-d')) ?>" required>
              </div>
              <div class="col-md-8">
                <label class="form-label">Transfer Amount</label>
                <input type="text" class="form-control" value="<?= moneyv(abs($balance1030)) ?> AED" readonly>
                <small class="text-muted">This is the absolute value of Secondary Bank (1030) balance</small>
              </div>
              <div class="col-md-12">
                <label class="form-label">Notes (Optional)</label>
                <input type="text" class="form-control" name="notes" placeholder="e.g., Consolidating to Main Bank Account">
              </div>
              <div class="col-md-12">
                <button type="submit" name="transfer" class="btn btn-primary btn-lg" onclick="return confirm('Are you sure you want to transfer <?= moneyv(abs($balance1030)) ?> AED from Secondary Bank (1030) to Main Bank (1020)?\n\nThis will create: Dr 1030, Cr 1020\n\nThis action cannot be undone easily.');">
                  <i class="bi bi-arrow-left-right"></i> Execute Transfer
                </button>
              </div>
            </div>
          </form>
        <?php else: ?>
          <div class="alert alert-success">
            <i class="bi bi-check-circle"></i> <strong>No transfer needed!</strong> Secondary Bank Account (1030) balance is already zero or positive.
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="alert alert-danger">
      <i class="bi bi-exclamation-triangle"></i> <strong>Error:</strong> Bank accounts 1020 or 1030 not found in Chart of Accounts. Please ensure these accounts exist.
    </div>
  <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

