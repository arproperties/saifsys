<?php
/**
 * Finance > General Ledger for the new chart: every account's entries for a
 * month, a quarter, a year or any two dates, with a running balance, for the
 * company selected in the session. It can be narrowed to one group or account.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_tb_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_ledger_table_ready($conn) && fin_voucher_tables_ready($conn);

$sel = fin_report_period([], date('Y-m-d'));
$gl = null;
$chart = [];
$years = [(int)date('Y')];
$underId = (int)($_GET['account_id'] ?? 0);
if ($tableOk && $company) {
    // With no filter the page opens on the month of the latest entry.
    $sel = fin_report_period($_GET, fin_tb_default_period($conn, $companyId)[0]);
    $chart = fin_coa_accounts($conn, $companyId);
    if ($underId && !in_array($underId, array_map('intval', array_column($chart, 'id')), true)) {
        $underId = 0; // another company's account, after switching company
    }
    $gl = fin_general_ledger($conn, $companyId, $sel['from'], $sel['to'], $underId ?: null);
    // Years on offer: from the first entry to this year, plus the one chosen.
    $first = $conn->prepare("SELECT MIN(YEAR(entry_date)) FROM fin_ledger_entries WHERE company_id = ? AND is_void = 0");
    $first->execute([$companyId]);
    $years = range((int)date('Y'), min((int)($first->fetchColumn() ?: date('Y')), (int)date('Y'), $sel['year']));
    if (!in_array($sel['year'], $years, true)) {
        array_unshift($years, $sel['year']);
    }
}
$err = $sel['error'];
$from = $sel['from'];
$to = $sel['to'];
$t = $gl['totals'] ?? ['debit' => 0.0, 'credit' => 0.0];

$periodLabel = fin_tb_period_label($from, $to);
if ($sel['period'] === 'quarter') {
    $periodLabel = 'Q' . $sel['q'] . ' ' . $sel['year'] . ' (' . date('M', strtotime($from)) . ' - ' . date('M Y', strtotime($to)) . ')';
} elseif ($sel['period'] === 'year') {
    $periodLabel = 'Year ' . $sel['year'];
}

$pageTitle = 'General Ledger';
$finActive = 'gl';
$pageSubtitle = ($tableOk && $company) ? $periodLabel : '';
$pageStyles = '
  .gl-table { font-size: .875rem; }
  .gl-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  tr.gl-account td { font-weight: 700; --bs-table-bg: #eef2f7; border-top: 2px solid #ced4da; }
  tr.gl-account a { color: inherit; text-decoration: none; }
  tr.gl-account a:hover { text-decoration: underline; }
  tr.gl-open td, tr.gl-close td { color: #495057; font-style: italic; }
  tr.gl-close td { font-weight: 600; font-style: normal; }
  tr.gl-grand td { font-weight: 700; border-top: 2px solid #343a40; border-bottom: 3px double #343a40; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($err): ?><div class="alert alert-danger fin-noprint"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_chart_of_accounts.sql, fin_ledger_entries.sql, fin_vouchers.sql and fin_partners.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company to see its general ledger.</div>
  <?php elseif (!$chart): ?>
    <div class="alert alert-info">This company has no accounts yet. <a href="fin_chart_of_accounts.php">Set up the chart of accounts</a> first.</div>
  <?php else: ?>

  <div class="card mb-3 fin-noprint">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end" id="glForm">
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="glPeriod">Period</label>
          <select class="form-select form-select-sm" id="glPeriod" name="period">
            <?php foreach (['month' => 'Monthly', 'quarter' => 'Quarterly', 'year' => 'Yearly', 'custom' => 'Custom dates'] as $k => $label): ?>
              <option value="<?= h($k) ?>" <?= $sel['period'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2" data-for="month">
          <label class="form-label small mb-1" for="glMonth">Month</label>
          <input type="month" class="form-control form-control-sm" id="glMonth" name="month" value="<?= h($sel['month']) ?>" required>
        </div>
        <div class="col-6 col-md-2" data-for="quarter">
          <label class="form-label small mb-1" for="glQuarter">Quarter</label>
          <select class="form-select form-select-sm" id="glQuarter" name="q">
            <?php foreach ([1 => 'Q1 (Jan - Mar)', 2 => 'Q2 (Apr - Jun)', 3 => 'Q3 (Jul - Sep)', 4 => 'Q4 (Oct - Dec)'] as $k => $label): ?>
              <option value="<?= $k ?>" <?= $sel['q'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2" data-for="quarter year">
          <label class="form-label small mb-1" for="glYear">Year</label>
          <select class="form-select form-select-sm" id="glYear" name="year">
            <?php foreach ($years as $y): ?>
              <option value="<?= (int)$y ?>" <?= $sel['year'] === (int)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2" data-for="custom">
          <label class="form-label small mb-1" for="glFrom">From</label>
          <input type="date" class="form-control form-control-sm" id="glFrom" name="from" value="<?= h($from) ?>" required>
        </div>
        <div class="col-6 col-md-2" data-for="custom">
          <label class="form-label small mb-1" for="glTo">To</label>
          <input type="date" class="form-control form-control-sm" id="glTo" name="to" value="<?= h($to) ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label small mb-1" for="glAccount">Group or account</label>
          <select class="form-select form-select-sm" id="glAccount" name="account_id">
            <option value="">All accounts</option>
            <?php foreach ($chart as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === $underId ? 'selected' : '' ?>><?= h($a['code'] . ' ' . $a['name'] . ($a['is_leaf'] ? '' : ' (group)')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto"><button class="btn btn-sm btn-primary">Show</button></div>
        <div class="col-auto ms-md-auto">
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex justify-content-between"><span><?= h($periodLabel) ?></span><span class="text-muted small"><?= h(date('d M Y', strtotime($from))) ?> to <?= h(date('d M Y', strtotime($to))) ?> · <?= count($gl['accounts']) ?> accounts</span></div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0 gl-table">
        <thead><tr><th>Date</th><th>Voucher</th><th>Description</th><th class="text-end">Debit (AED)</th><th class="text-end">Credit (AED)</th><th class="text-end">Balance (AED)</th></tr></thead>
        <tbody>
        <?php foreach ($gl['accounts'] as $acc): ?>
          <tr class="gl-account">
            <td colspan="6"><a href="fin_account_ledger.php?account_id=<?= (int)$acc['id'] ?>&amp;from=<?= h($from) ?>&amp;to=<?= h($to) ?>" title="Open this account on its own"><?= h($acc['code'] . ' ' . $acc['name']) ?></a><?= $acc['is_active'] ? '' : ' <span class="badge text-bg-secondary">Inactive</span>' ?></td>
          </tr>
          <tr class="gl-open"><td colspan="5">Opening balance</td><td class="gl-num"><?= h(fin_balance_label($acc['opening'])) ?></td></tr>
          <?php foreach ($acc['lines'] as $l): ?>
          <tr>
            <td class="text-nowrap"><?= h(date('d M Y', strtotime($l['entry_date']))) ?></td>
            <td class="text-nowrap"><a href="fin_voucher_view.php?id=<?= (int)$l['voucher_id'] ?>"><?= h($l['voucher_no']) ?></a></td>
            <td><?= h($l['description'] !== null && $l['description'] !== '' ? $l['description'] : $l['party']) ?><?= fin_partner_link($l) ?></td>
            <td class="gl-num"><?= (float)$l['debit'] > 0 ? number_format((float)$l['debit'], 2) : '' ?></td>
            <td class="gl-num"><?= (float)$l['credit'] > 0 ? number_format((float)$l['credit'], 2) : '' ?></td>
            <td class="gl-num"><?= h(fin_balance_label((float)$l['balance'])) ?></td>
          </tr>
          <?php endforeach; ?>
          <tr class="gl-close">
            <td colspan="3">Closing balance</td>
            <td class="gl-num"><?= number_format($acc['debit'], 2) ?></td>
            <td class="gl-num"><?= number_format($acc['credit'], 2) ?></td>
            <td class="gl-num"><?= h(fin_balance_label($acc['closing'])) ?></td>
          </tr>
        <?php endforeach; if (!$gl['accounts']): ?>
          <tr><td colspan="6" class="text-muted text-center py-4">No entries or balances <?= $underId ? 'for this choice ' : '' ?>in this period.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($gl['accounts']): ?>
        <tfoot>
          <tr class="gl-grand">
            <td colspan="3">Total for the period</td>
            <td class="gl-num"><?= number_format($t['debit'], 2) ?></td>
            <td class="gl-num"><?= number_format($t['credit'], 2) ?></td>
            <td></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
    <?php if ($gl['accounts'] && !$underId): $balanced = abs($t['debit'] - $t['credit']) < 0.005; ?>
    <div class="card-footer small <?= $balanced ? 'text-success' : 'text-danger' ?>">
      <?php if ($balanced): ?>
        <i class="bi bi-check-circle"></i> Debits equal credits.
      <?php else: ?>
        <i class="bi bi-exclamation-triangle"></i> Debits and credits differ by <?= number_format(abs($t['debit'] - $t['credit']), 2) ?> AED.
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
<script>
(function () {
  // Only the boxes of the chosen kind of period are shown and sent.
  const period = document.getElementById('glPeriod');
  if (!period) return;
  const boxes = Array.from(document.querySelectorAll('#glForm [data-for]'));
  const sync = () => boxes.forEach(b => {
    const on = b.dataset.for.split(' ').includes(period.value);
    b.classList.toggle('d-none', !on);
    b.querySelectorAll('input, select').forEach(f => { f.disabled = !on; });
  });
  period.addEventListener('change', sync);
  sync();
})();
</script>
</body>
</html>
