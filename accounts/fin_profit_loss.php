<?php
/**
 * Finance > Profit & Loss for the new chart: revenue, expenses and the profit or
 * loss of a month, a quarter, a year or any two dates, for the company selected
 * in the session.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_tb_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_ledger_table_ready($conn) && fin_voucher_tables_ready($conn);

$sel = fin_report_period([], date('Y-m-d'));
$pl = null;
$years = [(int)date('Y')];
if ($tableOk && $company) {
    // With no filter the page opens on the month of the latest entry.
    $sel = fin_report_period($_GET, fin_tb_default_period($conn, $companyId)[0]);
    $pl = fin_profit_loss($conn, $companyId, $sel['from'], $sel['to']);
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
$t = $pl['totals'] ?? [];
$hasAccounts = $pl && ($pl['sections']['revenue'] || $pl['sections']['expenses']);
$ledgerPeriod = '&from=' . $from . '&to=' . $to;

$periodLabel = fin_tb_period_label($from, $to);
if ($sel['period'] === 'quarter') {
    $periodLabel = 'Q' . $sel['q'] . ' ' . $sel['year'] . ' (' . date('M', strtotime($from)) . ' - ' . date('M Y', strtotime($to)) . ')';
} elseif ($sel['period'] === 'year') {
    $periodLabel = 'Year ' . $sel['year'];
}

/** Amount cell: negatives in brackets, zero greyed. */
function fin_pl_cell(float $v): string
{
    $text = $v < 0 ? '(' . number_format(-$v, 2) . ')' : number_format($v, 2);
    return '<td class="pl-num' . (abs($v) < 0.005 ? ' pl-zero' : '') . '">' . $text . '</td>';
}

$pageTitle = 'Profit & Loss';
$finActive = 'pl';
$pageSubtitle = ($tableOk && $company) ? $periodLabel : '';
$pageStyles = '
  .pl-table { font-size: .9rem; }
  .pl-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; width: 190px; }
  .pl-table td.pl-zero { color: #ced4da; }
  tr.pl-section td { font-weight: 700; text-transform: uppercase; letter-spacing: .04em; font-size: .78rem; color: #495057; --bs-table-bg: #eef2f7; }
  tr.pl-head td { font-weight: 600; }
  tr.pl-total td { font-weight: 700; border-top: 1px solid #343a40; }
  tr.pl-grand td { font-weight: 700; font-size: 1rem; border-top: 2px solid #343a40; border-bottom: 3px double #343a40; }
  tr.pl-grand.pl-loss td { color: #b02a37; }
  tr.pl-grand.pl-gain td { color: #146c43; }
  .pl-toggle { width: 1.1rem; display: inline-block; cursor: pointer; color: #6c757d; }
  .pl-code { color: #6c757d; }
  .pl-name a:hover { text-decoration: underline !important; }';
require __DIR__ . '/../includes/fin_layout_header.php';

/** Rows of one section, indented by level. */
$renderRows = static function (array $rows) use ($ledgerPeriod): void {
    foreach ($rows as $r):
        $isLedger = $r['is_leaf']; ?>
          <tr class="<?= $isLedger ? '' : 'pl-head' ?>" data-id="<?= (int)$r['id'] ?>" data-parent="<?= (int)$r['parent_id'] ?>" data-active="<?= abs($r['amount']) >= 0.005 ? 1 : 0 ?>">
            <td class="pl-name" style="padding-left:<?= 0.5 + (int)$r['depth'] * 1.1 ?>rem">
              <span class="pl-toggle"><?php if (!$isLedger): ?><i class="bi bi-caret-down-fill"></i><?php endif; ?></span><?php if ($isLedger): ?><a href="fin_account_ledger.php?account_id=<?= (int)$r['id'] ?><?= h($ledgerPeriod) ?>" class="text-decoration-none text-reset" title="Open account ledger"><span class="pl-code"><?= h($r['code']) ?></span> <?= h($r['name']) ?></a><?php else: ?><span class="pl-code"><?= h($r['code']) ?></span> <?= h($r['name']) ?><?php endif; ?>
            </td>
            <?= fin_pl_cell($r['amount']) ?>
          </tr>
    <?php endforeach;
};
?>

  <?php if ($err): ?><div class="alert alert-danger fin-noprint"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_chart_of_accounts.sql, fin_ledger_entries.sql, fin_vouchers.sql and fin_partners.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company to see its profit and loss.</div>
  <?php elseif (!$hasAccounts): ?>
    <div class="alert alert-info">This company has no revenue or expense accounts yet. <a href="fin_chart_of_accounts.php">Set up the chart of accounts</a> first.</div>
  <?php else: ?>

  <div class="card mb-3 fin-noprint">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end" id="plForm">
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="plPeriod">Period</label>
          <select class="form-select form-select-sm" id="plPeriod" name="period">
            <?php foreach (['month' => 'Monthly', 'quarter' => 'Quarterly', 'year' => 'Yearly', 'custom' => 'Custom dates'] as $k => $label): ?>
              <option value="<?= h($k) ?>" <?= $sel['period'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2" data-for="month">
          <label class="form-label small mb-1" for="plMonth">Month</label>
          <input type="month" class="form-control form-control-sm" id="plMonth" name="month" value="<?= h($sel['month']) ?>" required>
        </div>
        <div class="col-6 col-md-2" data-for="quarter">
          <label class="form-label small mb-1" for="plQuarter">Quarter</label>
          <select class="form-select form-select-sm" id="plQuarter" name="q">
            <?php foreach ([1 => 'Q1 (Jan - Mar)', 2 => 'Q2 (Apr - Jun)', 3 => 'Q3 (Jul - Sep)', 4 => 'Q4 (Oct - Dec)'] as $k => $label): ?>
              <option value="<?= $k ?>" <?= $sel['q'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2" data-for="quarter year">
          <label class="form-label small mb-1" for="plYear">Year</label>
          <select class="form-select form-select-sm" id="plYear" name="year">
            <?php foreach ($years as $y): ?>
              <option value="<?= (int)$y ?>" <?= $sel['year'] === (int)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2" data-for="custom">
          <label class="form-label small mb-1" for="plFrom">From</label>
          <input type="date" class="form-control form-control-sm" id="plFrom" name="from" value="<?= h($from) ?>" required>
        </div>
        <div class="col-6 col-md-2" data-for="custom">
          <label class="form-label small mb-1" for="plTo">To</label>
          <input type="date" class="form-control form-control-sm" id="plTo" name="to" value="<?= h($to) ?>" required>
        </div>
        <div class="col-auto"><button class="btn btn-sm btn-primary">Show</button></div>
        <div class="col-auto ms-md-auto d-flex flex-wrap align-items-center gap-2">
          <div class="form-check form-check-inline mb-0">
            <input class="form-check-input" type="checkbox" id="plHideZero" checked>
            <label class="form-check-label small" for="plHideZero">Hide accounts with no amount</label>
          </div>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="plExpand">Expand all</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="plCollapse">Collapse all</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex justify-content-between"><span><?= h($periodLabel) ?></span><span class="text-muted small"><?= h(date('d M Y', strtotime($from))) ?> to <?= h(date('d M Y', strtotime($to))) ?></span></div>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0 pl-table" id="plTable">
        <thead><tr><th>Account</th><th class="text-end">Amount (AED)</th></tr></thead>
        <tbody>
          <tr class="pl-section"><td colspan="2">Revenue</td></tr>
          <?php $renderRows($pl['sections']['revenue']); ?>
          <tr class="pl-total"><td>Total revenue</td><?= fin_pl_cell($t['revenue']) ?></tr>

          <tr class="pl-section"><td colspan="2">Expenses</td></tr>
          <?php $renderRows($pl['sections']['expenses']); ?>
          <tr class="pl-total"><td>Total expenses</td><?= fin_pl_cell($t['expenses']) ?></tr>

          <tr class="pl-grand <?= $t['profit'] < 0 ? 'pl-loss' : 'pl-gain' ?>"><td><?= $t['profit'] < 0 ? 'Net loss' : 'Net profit' ?></td><?= fin_pl_cell($t['profit']) ?></tr>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
<script>
(function () {
  // Only the boxes of the chosen kind of period are shown and sent.
  const period = document.getElementById('plPeriod');
  if (period) {
    const boxes = Array.from(document.querySelectorAll('#plForm [data-for]'));
    const sync = () => boxes.forEach(b => {
      const on = b.dataset.for.split(' ').includes(period.value);
      b.classList.toggle('d-none', !on);
      b.querySelectorAll('input, select').forEach(f => { f.disabled = !on; });
    });
    period.addEventListener('change', sync);
    sync();
  }

  const table = document.getElementById('plTable');
  if (!table) return;
  const rows = Array.from(table.querySelectorAll('tbody tr[data-id]'));
  const byId = new Map(rows.map(r => [r.dataset.id, r]));
  const collapsed = new Set();
  const hideZero = document.getElementById('plHideZero');

  function hiddenByParent(row) {
    let p = row.dataset.parent;
    while (p && p !== '0') {
      if (collapsed.has(p)) return true;
      const pr = byId.get(p);
      p = pr ? pr.dataset.parent : null;
    }
    return false;
  }

  function render() {
    rows.forEach(r => {
      // The top line of each section always stays, so an empty section still shows its heading.
      const off = hiddenByParent(r) || (hideZero.checked && r.dataset.active === '0' && r.dataset.parent !== '0');
      r.classList.toggle('d-none', off);
      const icon = r.querySelector('.pl-toggle i');
      if (icon) icon.className = 'bi ' + (collapsed.has(r.dataset.id) ? 'bi-caret-right-fill' : 'bi-caret-down-fill');
    });
  }

  table.addEventListener('click', e => {
    const row = e.target.closest('tr.pl-head');
    if (!row || !e.target.closest('.pl-name')) return;
    collapsed.has(row.dataset.id) ? collapsed.delete(row.dataset.id) : collapsed.add(row.dataset.id);
    render();
  });
  hideZero.addEventListener('change', render);
  document.getElementById('plExpand').addEventListener('click', () => { collapsed.clear(); render(); });
  document.getElementById('plCollapse').addEventListener('click', () => {
    rows.forEach(r => { if (r.classList.contains('pl-head')) collapsed.add(r.dataset.id); });
    render();
  });
  render();
})();
</script>
</body>
</html>
