<?php
/**
 * Finance > Balance Sheet for the new chart: assets, liabilities and equity as
 * at a date, for the company selected in the session.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_tb_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_ledger_table_ready($conn) && fin_voucher_tables_ready($conn);
$err = '';

$asAt = date('Y-m-d');
$reqDate = (string)($_GET['date'] ?? '');
if ($reqDate !== '') {
    if (fin_tb_valid_date($reqDate)) {
        $asAt = $reqDate;
    } else {
        $err = 'Choose a valid date.';
    }
}
$bs = ($tableOk && $company) ? fin_balance_sheet($conn, $companyId, $asAt) : null;
$t = $bs['totals'] ?? [];
$hasAccounts = $bs && ($bs['sections']['assets'] || $bs['sections']['liabilities'] || $bs['sections']['equity']);
$balanced = $bs && abs($t['difference']) < 0.005;
// An account opens its ledger from the start of the year up to the date shown.
$ledgerPeriod = '&from=' . date('Y-01-01', strtotime($asAt)) . '&to=' . $asAt;

/** Amount cell: negatives in brackets, zero greyed. */
function fin_bs_cell(float $v, string $class = ''): string
{
    $text = $v < 0 ? '(' . number_format(-$v, 2) . ')' : number_format($v, 2);
    return '<td class="bs-num ' . $class . (abs($v) < 0.005 ? ' bs-zero' : '') . '">' . $text . '</td>';
}

$pageTitle = 'Balance Sheet';
$finActive = 'bs';
$pageSubtitle = ($tableOk && $company) ? 'As at ' . date('d M Y', strtotime($asAt)) : '';
$pageStyles = '
  .bs-table { font-size: .9rem; }
  .bs-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; width: 190px; }
  .bs-table td.bs-zero { color: #ced4da; }
  tr.bs-section td { font-weight: 700; text-transform: uppercase; letter-spacing: .04em; font-size: .78rem; color: #495057; --bs-table-bg: #eef2f7; }
  tr.bs-head td { font-weight: 600; }
  tr.bs-total td { font-weight: 700; border-top: 1px solid #343a40; }
  tr.bs-grand td { font-weight: 700; border-top: 2px solid #343a40; border-bottom: 3px double #343a40; }
  .bs-toggle { width: 1.1rem; display: inline-block; cursor: pointer; color: #6c757d; }
  .bs-code { color: #6c757d; }
  .bs-name a:hover { text-decoration: underline !important; }';
require __DIR__ . '/../includes/fin_layout_header.php';

$sectionLabels = ['assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity'];
/** Rows of one section, indented by level. */
$renderRows = static function (array $rows) use ($ledgerPeriod): void {
    foreach ($rows as $r):
        $isLedger = $r['is_leaf']; ?>
          <tr class="<?= $isLedger ? '' : 'bs-head' ?>" data-id="<?= (int)$r['id'] ?>" data-parent="<?= (int)$r['parent_id'] ?>" data-active="<?= abs($r['amount']) >= 0.005 ? 1 : 0 ?>">
            <td class="bs-name" style="padding-left:<?= 0.5 + (int)$r['depth'] * 1.1 ?>rem">
              <span class="bs-toggle"><?php if (!$isLedger): ?><i class="bi bi-caret-down-fill"></i><?php endif; ?></span><?php if ($isLedger): ?><a href="fin_account_ledger.php?account_id=<?= (int)$r['id'] ?><?= h($ledgerPeriod) ?>" class="text-decoration-none text-reset" title="Open account ledger"><span class="bs-code"><?= h($r['code']) ?></span> <?= h($r['name']) ?></a><?php else: ?><span class="bs-code"><?= h($r['code']) ?></span> <?= h($r['name']) ?><?php endif; ?>
            </td>
            <?= fin_bs_cell($r['amount']) ?>
          </tr>
    <?php endforeach;
};
?>

  <?php if ($err): ?><div class="alert alert-danger fin-noprint"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_chart_of_accounts.sql, fin_ledger_entries.sql, fin_vouchers.sql and fin_partners.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company to see its balance sheet.</div>
  <?php elseif (!$hasAccounts): ?>
    <div class="alert alert-info">This company has no accounts yet. <a href="fin_chart_of_accounts.php">Set up the chart of accounts</a> first.</div>
  <?php else: ?>

  <div class="card mb-3 fin-noprint">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="bsDate">As at</label>
          <input type="date" class="form-control form-control-sm" id="bsDate" name="date" value="<?= h($asAt) ?>" required>
        </div>
        <div class="col-auto"><button class="btn btn-sm btn-primary">Show</button></div>
        <div class="col-auto ms-md-auto d-flex flex-wrap align-items-center gap-2">
          <div class="form-check form-check-inline mb-0">
            <input class="form-check-input" type="checkbox" id="bsHideZero" checked>
            <label class="form-check-label small" for="bsHideZero">Hide accounts with no balance</label>
          </div>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="bsExpand">Expand all</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="bsCollapse">Collapse all</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0 bs-table" id="bsTable">
        <thead><tr><th>Account</th><th class="text-end">Amount (AED)</th></tr></thead>
        <tbody>
          <tr class="bs-section"><td colspan="2"><?= h($sectionLabels['assets']) ?></td></tr>
          <?php $renderRows($bs['sections']['assets']); ?>
          <tr class="bs-grand"><td>Total assets</td><?= fin_bs_cell($t['assets']) ?></tr>

          <tr class="bs-section"><td colspan="2"><?= h($sectionLabels['liabilities']) ?></td></tr>
          <?php $renderRows($bs['sections']['liabilities']); ?>
          <tr class="bs-total"><td>Total liabilities</td><?= fin_bs_cell($t['liabilities']) ?></tr>

          <tr class="bs-section"><td colspan="2"><?= h($sectionLabels['equity']) ?></td></tr>
          <?php $renderRows($bs['sections']['equity']); ?>
          <tr title="Revenue less expenses from the first entry up to this date. It is not posted to an equity account.">
            <td class="bs-name" style="padding-left:.5rem"><span class="bs-toggle"></span><?= $t['profit'] < 0 ? 'Loss to date' : 'Profit to date' ?> <span class="text-muted small">(revenue less expenses)</span></td>
            <?= fin_bs_cell($t['profit']) ?>
          </tr>
          <tr class="bs-total"><td>Total equity</td><?= fin_bs_cell($t['equity_total']) ?></tr>

          <tr class="bs-grand"><td>Total liabilities and equity</td><?= fin_bs_cell($t['liabilities_equity']) ?></tr>
        </tbody>
      </table>
    </div>
    <div class="card-footer small <?= $balanced ? 'text-success' : 'text-danger' ?>">
      <?php if ($balanced): ?>
        <i class="bi bi-check-circle"></i> Assets equal liabilities and equity.
      <?php else: ?>
        <i class="bi bi-exclamation-triangle"></i> Assets and liabilities plus equity differ by <?= number_format(abs($t['difference']), 2) ?> AED. Entries posted to accounts outside groups 1 to 5 cause this.
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
<script>
(function () {
  const table = document.getElementById('bsTable');
  if (!table) return;
  const rows = Array.from(table.querySelectorAll('tbody tr[data-id]'));
  const byId = new Map(rows.map(r => [r.dataset.id, r]));
  const collapsed = new Set();
  const hideZero = document.getElementById('bsHideZero');

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
      const icon = r.querySelector('.bs-toggle i');
      if (icon) icon.className = 'bi ' + (collapsed.has(r.dataset.id) ? 'bi-caret-right-fill' : 'bi-caret-down-fill');
    });
  }

  table.addEventListener('click', e => {
    const row = e.target.closest('tr.bs-head');
    if (!row || !e.target.closest('.bs-name')) return;
    collapsed.has(row.dataset.id) ? collapsed.delete(row.dataset.id) : collapsed.add(row.dataset.id);
    render();
  });
  hideZero.addEventListener('change', render);
  document.getElementById('bsExpand').addEventListener('click', () => { collapsed.clear(); render(); });
  document.getElementById('bsCollapse').addEventListener('click', () => {
    rows.forEach(r => { if (r.classList.contains('bs-head')) collapsed.add(r.dataset.id); });
    render();
  });
  render();
})();
</script>
</body>
</html>
