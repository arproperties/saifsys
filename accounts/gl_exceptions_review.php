<?php
/**
 * Admin review: receipts/expenses with missing or inconsistent GL linkage.
 * Cleaning module accounts only — not real-estate accounting.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_role(['Owner', 'Admin', 'Account'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// --- Receipts: no GL journal posted ---
$noGl = $conn->query("
    SELECT r.id, r.receipt_no, r.receipt_date, r.method, r.amount, r.client_id, r.gl_journal_id
    FROM receipts r
    WHERE r.gl_journal_id IS NULL
    ORDER BY r.receipt_date DESC, r.id DESC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

// --- Receipts: gl_journal_id set but journal row missing ---
$orphanPointer = $conn->query("
    SELECT r.id, r.receipt_no, r.receipt_date, r.method, r.amount, r.gl_journal_id
    FROM receipts r
    LEFT JOIN gl_journals j ON j.id = r.gl_journal_id
    WHERE r.gl_journal_id IS NOT NULL AND j.id IS NULL
    ORDER BY r.receipt_date DESC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

// --- Receipts: journal exists but does not belong to this receipt ---
$mismatchedJournal = $conn->query("
    SELECT r.id, r.receipt_no, r.receipt_date, r.amount, r.gl_journal_id,
           j.source, j.source_id, j.is_posted, j.is_reversed
    FROM receipts r
    INNER JOIN gl_journals j ON j.id = r.gl_journal_id
    WHERE j.source <> 'receipt' OR j.source_id <> r.id
    ORDER BY r.receipt_date DESC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

// --- Expenses: cash/bank without pay_account_no ---
$expenseMissingPay = $conn->query("
    SELECT e.id, e.expense_date, e.paid_via, e.pay_account_no, e.total, e.status
    FROM expenses e
    WHERE e.paid_via IN ('cash','bank')
      AND (e.pay_account_no IS NULL OR TRIM(e.pay_account_no) = '')
    ORDER BY e.expense_date DESC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

// --- Expenses: pay_account_no not found on active COA ---
$expenseInvalidCoa = $conn->query("
    SELECT e.id, e.expense_date, e.paid_via, e.pay_account_no, e.total, e.status
    FROM expenses e
    LEFT JOIN chart_of_accounts c ON c.account_no = e.pay_account_no AND c.is_active = 1
    WHERE e.pay_account_no IS NOT NULL AND TRIM(e.pay_account_no) <> ''
      AND c.id IS NULL
    ORDER BY e.expense_date DESC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

$counts = [
    'no_gl' => count($noGl),
    'orphan' => count($orphanPointer),
    'mismatch' => count($mismatchedJournal),
    'exp_missing' => count($expenseMissingPay),
    'exp_invalid' => count($expenseInvalidCoa),
];
$totalIssues = array_sum($counts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>GL exceptions review</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background: #f6f7f9; }
    .hero { background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,.06); padding: 1rem 1.25rem; margin-bottom: 1rem; }
    .section-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,.06); margin-bottom: 1.25rem; overflow: hidden; }
    .section-head { padding: .75rem 1rem; border-bottom: 1px solid #eee; font-weight: 600; }
    .table-sm td, .table-sm th { font-size: .9rem; }
  </style>
</head>
<body>
<div class="container py-4">
  <div class="hero d-flex flex-wrap align-items-center gap-2">
    <div>
      <div class="text-uppercase small text-muted">Cleaning accounts</div>
      <h4 class="mb-0">GL exceptions review</h4>
      <div class="text-muted small">Receipts and expenses that need attention (historical or data issues).</div>
    </div>
    <div class="ms-auto d-flex gap-2">
      <span class="badge <?= $totalIssues ? 'bg-warning text-dark' : 'bg-success' ?> rounded-pill">
        <?= (int)$totalIssues ?> issue row(s) shown (capped at 500 per section)
      </span>
      <a href="reports.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Reports</a>
      <a href="../account.php?tab=dashboard" class="btn btn-outline-primary btn-sm">AR Dashboard</a>
    </div>
  </div>

  <div class="alert alert-info small mb-3">
    <strong>Strict delete/edit:</strong> payments cannot be removed or fully corrected in the UI while GL reversal fails or while the receipt points to a missing/mismatched journal. Fix underlying data or post missing journals before retrying.
    <?php if ($counts['no_gl'] > 0 && $counts['orphan'] === 0 && $counts['mismatch'] === 0): ?>
      <span class="d-block mt-2">To backfill missing receipt journals (dry run, then execute): <a href="repair_receipt_gl_backfill.php" class="alert-link">repair_receipt_gl_backfill.php</a></span>
    <?php endif; ?>
  </div>

  <?php
  $renderTable = function (string $title, array $rows, array $columns) {
      echo '<div class="section-card"><div class="section-head">' . h($title) . ' <span class="badge bg-secondary">' . count($rows) . '</span></div>';
      if (!$rows) {
          echo '<div class="p-3 text-muted">None.</div></div>';
          return;
      }
      echo '<div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead class="table-light"><tr>';
      foreach ($columns as $col) {
          echo '<th>' . h($col['label']) . '</th>';
      }
      echo '</tr></thead><tbody>';
      foreach ($rows as $r) {
          echo '<tr>';
          foreach ($columns as $col) {
              $key = $col['key'];
              $v = $r[$key] ?? '';
              if (!empty($col['money'])) {
                  $v = is_numeric($v) ? number_format((float)$v, 2) : h((string)$v);
              } else {
                  $v = h((string)$v);
              }
              echo '<td>' . $v . '</td>';
          }
          echo '</tr>';
      }
      echo '</tbody></table></div></div>';
  };

  $renderTable('Receipts — gl_journal_id IS NULL (never posted)', $noGl, [
      ['key' => 'id', 'label' => 'ID'],
      ['key' => 'receipt_no', 'label' => 'Receipt #'],
      ['key' => 'receipt_date', 'label' => 'Date'],
      ['key' => 'method', 'label' => 'Method'],
      ['key' => 'amount', 'label' => 'Amount', 'money' => true],
      ['key' => 'client_id', 'label' => 'Client ID'],
  ]);

  $renderTable('Receipts — gl_journal_id points to missing journal row', $orphanPointer, [
      ['key' => 'id', 'label' => 'ID'],
      ['key' => 'receipt_no', 'label' => 'Receipt #'],
      ['key' => 'receipt_date', 'label' => 'Date'],
      ['key' => 'gl_journal_id', 'label' => 'Stale gl_journal_id'],
      ['key' => 'amount', 'label' => 'Amount', 'money' => true],
  ]);

  $renderTable('Receipts — linked journal does not match receipt (source/source_id)', $mismatchedJournal, [
      ['key' => 'id', 'label' => 'ID'],
      ['key' => 'receipt_no', 'label' => 'Receipt #'],
      ['key' => 'receipt_date', 'label' => 'Date'],
      ['key' => 'gl_journal_id', 'label' => 'Journal ID'],
      ['key' => 'source', 'label' => 'J.source'],
      ['key' => 'source_id', 'label' => 'J.source_id'],
      ['key' => 'is_reversed', 'label' => 'J.rev'],
  ]);

  $renderTable('Expenses — cash/bank missing pay_account_no', $expenseMissingPay, [
      ['key' => 'id', 'label' => 'ID'],
      ['key' => 'expense_date', 'label' => 'Date'],
      ['key' => 'paid_via', 'label' => 'Paid via'],
      ['key' => 'total', 'label' => 'Total', 'money' => true],
      ['key' => 'status', 'label' => 'Status'],
  ]);

  $renderTable('Expenses — pay_account_no not found on active chart of accounts', $expenseInvalidCoa, [
      ['key' => 'id', 'label' => 'ID'],
      ['key' => 'expense_date', 'label' => 'Date'],
      ['key' => 'pay_account_no', 'label' => 'pay_account_no'],
      ['key' => 'paid_via', 'label' => 'Paid via'],
      ['key' => 'total', 'label' => 'Total', 'money' => true],
  ]);
  ?>
</div>
</body>
</html>
