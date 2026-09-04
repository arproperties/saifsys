<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/cleaning_accounting_context.php';
require_once __DIR__.'/../includes/report_date_helpers.php';
require_role(['Owner','Admin','Account'], $conn);

// Legacy YYYY-MM period links → from/to
if (!empty($_GET['period']) && empty($_GET['from'])) {
    $_GET['from'] = $_GET['period'] . '-01';
    $_GET['to'] = date('Y-m-t', strtotime($_GET['period'] . '-01'));
}
$dates = report_date_range();
$from = $dates['from'];
$to = $dates['to'];
$export = isset($_GET['export']) && $_GET['export']==='csv';
$cleaningCompanyId = cleaning_accounting_company_id($conn);

function tb_fetch_rows(PDO $conn, int $cleaningCompanyId, string $from, string $to): array
{
    $st = $conn->prepare("
      SELECT 
        a.id, 
        a.account_no, 
        a.name, 
        a.type, 
        a.normal_balance,
        COALESCE(SUM(l.debit), 0) AS total_debit,
        COALESCE(SUM(l.credit), 0) AS total_credit,
        COALESCE(SUM(
          CASE WHEN a.normal_balance = 'debit' 
            THEN l.debit - l.credit 
            ELSE l.credit - l.debit 
          END
        ), 0) AS cumulative_balance
      FROM chart_of_accounts a
      LEFT JOIN gl_journal_lines l ON l.account_id = a.id
      LEFT JOIN gl_journals j ON j.id = l.journal_id
      WHERE a.is_active = 1
        AND a.company_id = ?
        AND j.journal_date BETWEEN ? AND ?
        AND j.company_id = ?
        AND j.is_posted = 1 
        AND j.is_reversed = 0
        AND NOT (j.source = 'reversal' AND EXISTS (SELECT 1 FROM gl_journals aj WHERE aj.source = 'adjustment' AND aj.source_id = j.id))
        AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Compensating entry: Voiding duplicate reversal%')
        AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Void incorrect bank transfer%')
        AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Bank Transfer%' AND j.is_reversed = 1)
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
                AND orig_l.account_id = l.account_id
                AND orig_l.debit > 0
                AND orig_j2.is_posted = 1
            )
        ))
      GROUP BY a.id, a.account_no, a.name, a.type, a.normal_balance
      ORDER BY a.account_no
    ");
    $st->execute([$cleaningCompanyId, $from, $to, $cleaningCompanyId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['debit'] = (float)$row['total_debit'];
        $row['credit'] = (float)$row['total_credit'];
        $row['net_balance'] = (float)$row['cumulative_balance'];

        $txnSt = $conn->prepare("
            SELECT COUNT(DISTINCT l.journal_id) as txn_count
            FROM gl_journal_lines l
            JOIN gl_journals j ON j.id = l.journal_id
            WHERE l.account_id = ? 
              AND j.journal_date BETWEEN ? AND ?
              AND j.company_id = ?
              AND j.is_posted = 1 
              AND j.is_reversed = 0
              AND NOT (j.source = 'reversal' AND EXISTS (SELECT 1 FROM gl_journals aj WHERE aj.source = 'adjustment' AND aj.source_id = j.id))
              AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Void incorrect bank transfer%')
              AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Bank Transfer%' AND j.is_reversed = 1)
              AND NOT (j.source = 'adjustment' AND j.memo LIKE 'Compensating entry: Voiding duplicate reversal%')
        ");
        $txnSt->execute([$row['id'], $from, $to, $cleaningCompanyId]);
        $row['txn_count'] = (int)$txnSt->fetchColumn();
    }
    unset($row);
    return $rows;
}

$rows = tb_fetch_rows($conn, $cleaningCompanyId, $from, $to);

if ($export) {
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="trial_balance_'.$from.'_to_'.$to.'.csv"');
  $out = fopen('php://output','w');
  fputcsv($out, ['Account No','Account Name','Type','Debit','Credit','Net Balance']);
  foreach ($rows as $r) {
    fputcsv($out, [
      $r['account_no'], $r['name'], $r['type'],
      number_format($r['debit'], 2), number_format($r['credit'], 2),
      number_format($r['net_balance'], 2),
    ]);
  }
  exit;
}

// totals
$totDr = $totCr = 0.0;
foreach ($rows as $r){ $totDr += (float)$r['debit']; $totCr += (float)$r['credit']; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function moneyv($n){ return number_format((float)$n, 2); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><title>Trial Balance</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .account-info { cursor: help; }
  .net-balance { font-weight: 600; }
  .net-debit { color: #dc3545; }
  .net-credit { color: #198754; }
  .account-explanation { font-size: 0.85rem; color: #6c757d; margin-top: 0.25rem; }
  .info-card { background: #e7f3ff; border-left: 4px solid #0d6efd; }
  .example-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 12px; margin-top: 10px; }
</style>
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="d-flex align-items-center">
    <h3 class="me-auto">Trial Balance</h3>
    <a class="btn btn-outline-secondary me-2" href="reports.php">&larr; Back</a>
    <a class="btn btn-secondary" href="?<?= report_date_query($from, $to, ['export' => 'csv']) ?>">Export CSV</a>
  </div>

  <?php report_date_filter_form($from, $to, [], ['hint' => 'Activity in selected range · ' . report_date_period_label($from, $to)]); ?>

  <div class="table-responsive mt-3">
    <table class="table table-striped align-middle">
      <thead class="table-light">
        <tr>
          <th>Account</th>
          <th>Name</th>
          <th class="text-end">Debit</th>
          <th class="text-end">Credit</th>
          <th class="text-end">Net Balance</th>
          <th class="text-center">Details</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): 
          $netBal = $r['net_balance'];
          // For display: show actual sign (positive or negative)
          // For debit accounts: positive = normal, negative = unusual
          // For credit accounts: positive = normal, negative = unusual (debits exceed credits)
          $netClass = ($netBal >= 0) ? 'net-debit' : 'net-credit';
          $netSign = ($netBal < 0) ? '-' : '';
          
          // Get account explanation
          $explanation = '';
          if ($r['account_no'] === '1110') {
            $explanation = 'Money customers owe you (from invoices). Debit = invoices issued, Credit = payments received.';
            if ($netBal < 0) {
              $explanation .= ' <strong class="text-danger">⚠ WARNING: Negative balance indicates receipt credits exceed invoice debits - investigate GL entries.</strong>';
            }
          } elseif ($r['account_no'] === '4010') {
            $explanation = 'Revenue from cleaning services. Credit = revenue earned (invoices), Debit = reversals/refunds. Net = Revenue - Returns.';
          } elseif ($r['account_no'] === '2210') {
            $explanation = 'VAT you collected from customers (Output VAT). Credit = VAT on invoices, Debit = VAT paid to tax authority.';
          } elseif ($r['account_no'] === '1260') {
            $explanation = 'VAT you paid on purchases (Input VAT). Debit = VAT on expenses, Credit = VAT recovered/claimed.';
          } elseif ($r['type'] === 'Asset') {
            $explanation = 'Assets you own. Debit increases, Credit decreases.';
          } elseif ($r['type'] === 'Liability') {
            $explanation = 'Money you owe. Credit increases, Debit decreases.';
          } elseif ($r['type'] === 'Revenue') {
            $explanation = 'Income earned. Credit increases revenue, Debit decreases (refunds/adjustments).';
          } elseif ($r['type'] === 'Expense') {
            $explanation = 'Costs incurred. Debit increases expenses, Credit decreases (reversals).';
          }
        ?>
          <tr>
            <td><code><?= h($r['account_no']) ?></code></td>
            <td>
              <div><?= h($r['name']) ?></div>
              <?php if ($explanation): ?>
                <div class="account-explanation">
                  <i class="bi bi-info-circle" style="font-size: 0.75rem;"></i> <?= h($explanation) ?>
                </div>
              <?php endif; ?>
            </td>
            <td class="text-end"><?= moneyv($r['debit']) ?></td>
            <td class="text-end"><?= moneyv($r['credit']) ?></td>
            <td class="text-end net-balance <?= $netClass ?>">
              <?= $netSign ?><?= moneyv(abs($netBal)) ?>
              <?php if ($netBal < 0 && $r['type'] === 'Revenue'): ?>
                <br><small class="text-muted">(Debits exceed credits - unusual)</small>
              <?php elseif ($netBal < 0 && $r['type'] === 'Asset'): ?>
                <br><small class="text-muted">(Credits exceed debits - unusual)</small>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($r['txn_count'] > 0): ?>
                <a href="report_general_ledger.php?account_id=<?= $r['id'] ?>&from=<?= h($from) ?>&to=<?= h($to) ?>" 
                   class="btn btn-sm btn-outline-primary" 
                   title="View <?= $r['txn_count'] ?> transaction(s)">
                  <i class="bi bi-eye"></i> View
                </a>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; if(!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted">No data.</td></tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr class="table-light">
          <th colspan="2" class="text-end">Total</th>
          <th class="text-end"><?= moneyv($totDr) ?></th>
          <th class="text-end"><?= moneyv($totCr) ?></th>
          <th class="text-end">
            <?php 
              $diff = abs($totDr - $totCr);
              if ($diff < 0.01): 
                echo '<span class="text-success"><i class="bi bi-check-circle"></i> Balanced</span>';
              else:
                echo '<span class="text-danger">Difference: ' . moneyv($diff) . '</span>';
              endif;
            ?>
          </th>
          <th></th>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
</body>
</html>
