<?php
/**
 * Finance > Trial Balance for the new chart: balance before the period, movement
 * in it and balance at its end, for the company selected in the session.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_tb_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_ledger_table_ready($conn) && fin_voucher_tables_ready($conn);
$err = '';

$from = $to = date('Y-m-d');
$tb = ['rows' => [], 'totals' => array_fill_keys(FIN_TB_COLUMNS, 0.0)];
$hasEntries = false;
if ($tableOk && $company) {
    [$from, $to] = fin_tb_default_period($conn, $companyId);
    $reqFrom = (string)($_GET['from'] ?? '');
    $reqTo = (string)($_GET['to'] ?? '');
    if ($reqFrom !== '' || $reqTo !== '') {
        if (fin_tb_valid_date($reqFrom) && fin_tb_valid_date($reqTo) && $reqFrom <= $reqTo) {
            [$from, $to] = [$reqFrom, $reqTo];
        } else {
            $err = 'Choose a valid period: the From date must be on or before the To date.';
        }
    }
    $tb = fin_trial_balance($conn, $companyId, $from, $to);
    $chk = $conn->prepare("SELECT COUNT(*) FROM fin_ledger_entries WHERE company_id = ?");
    $chk->execute([$companyId]);
    $hasEntries = (int)$chk->fetchColumn() > 0;
}
$periodLabel = fin_tb_period_label($from, $to);
$t = $tb['totals'];
$balanced = abs($t['end_debit'] - $t['end_credit']) < 0.005 && abs($t['period_debit'] - $t['period_credit']) < 0.005;

function fin_tb_cell(float $v): string
{
    return '<td class="tb-num' . (abs($v) < 0.005 ? ' tb-zero' : '') . '">' . number_format($v, 2) . '</td>';
}

$pageTitle = 'Trial Balance';
$finActive = 'tb';
$pageSubtitle = ($tableOk && $company) ? date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to)) : '';
$pageStyles = '
  .tb-table { font-size: .875rem; }
  .tb-table th, .tb-table td { white-space: nowrap; }
  .tb-table td.tb-name { white-space: normal; min-width: 260px; }
  .tb-table thead th { text-align: center; font-weight: 600; background: #fff; }
  .tb-table thead tr.tb-sub th { text-align: right; font-weight: 400; color: #495057; }
  .tb-num { text-align: right; font-variant-numeric: tabular-nums; }
  .tb-table td.tb-zero { color: #ced4da; }
  .tb-table td.tb-g1 { --bs-table-bg: #f6f7f9; }
  .tb-table .tb-edge { border-left: 1px solid #dee2e6; }
  tr.tb-head td { font-weight: 600; }
  tr.tb-total td { font-weight: 700; border-top: 2px solid #343a40; }
  .tb-toggle { width: 1.1rem; display: inline-block; cursor: pointer; color: #6c757d; }
  .tb-code { color: #6c757d; }
  .tb-name a:hover { text-decoration: underline !important; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($err): ?><div class="alert alert-danger fin-noprint"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_chart_of_accounts.sql, fin_ledger_entries.sql, fin_vouchers.sql and fin_partners.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company to see its trial balance.</div>
  <?php elseif (!$tb['rows']): ?>
    <div class="alert alert-info">This company has no accounts yet. <a href="fin_chart_of_accounts.php">Set up the chart of accounts</a> first.</div>
  <?php else: ?>

  <div class="card mb-3 fin-noprint">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="tbFrom">From</label>
          <input type="date" class="form-control form-control-sm" id="tbFrom" name="from" value="<?= h($from) ?>" required>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="tbTo">To</label>
          <input type="date" class="form-control form-control-sm" id="tbTo" name="to" value="<?= h($to) ?>" required>
        </div>
        <div class="col-auto"><button class="btn btn-sm btn-primary">Show</button></div>
        <div class="col-auto ms-md-auto d-flex flex-wrap align-items-center gap-2">
          <div class="form-check form-check-inline mb-0">
            <input class="form-check-input" type="checkbox" id="tbHideZero">
            <label class="form-check-label small" for="tbHideZero">Hide accounts with no balance</label>
          </div>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="tbExpand">Expand all</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="tbCollapse">Collapse all</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        </div>
      </form>
    </div>
  </div>

  <?php if (!$hasEntries): ?>
  <div class="alert alert-info fin-noprint">Nothing has been posted to this company's accounts yet, so every balance is zero. Record a <a href="fin_journal_voucher_new.php">journal voucher</a>, <a href="fin_payment_voucher_new.php">payment</a> or <a href="fin_receipt_voucher_new.php">receipt</a> to start.</div>
  <?php endif; ?>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0 tb-table" id="tbTable">
        <thead>
          <tr>
            <th rowspan="2" class="text-start">Account</th>
            <th rowspan="2" class="tb-edge">Type</th>
            <th colspan="2" class="tb-edge">Initial Balance</th>
            <th colspan="2" class="tb-edge"><?= h($periodLabel) ?></th>
            <th colspan="2" class="tb-edge">End Balance</th>
          </tr>
          <tr class="tb-sub">
            <th class="tb-edge">Debit (AED)</th><th>Credit (AED)</th>
            <th class="tb-edge">Debit (AED)</th><th>Credit (AED)</th>
            <th class="tb-edge">Debit (AED)</th><th>Credit (AED)</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($tb['rows'] as $r): $isLedger = $r['is_leaf']; ?>
          <tr class="<?= $isLedger ? '' : 'tb-head' ?>" data-id="<?= (int)$r['id'] ?>" data-parent="<?= (int)$r['parent_id'] ?>" data-active="<?= $r['has_activity'] ? 1 : 0 ?>">
            <td class="tb-name" style="padding-left:<?= 0.5 + (int)$r['depth'] * 1.1 ?>rem">
              <span class="tb-toggle"><?php if (!$isLedger): ?><i class="bi bi-caret-down-fill"></i><?php endif; ?></span><?php if ($isLedger): ?><a href="fin_account_ledger.php?account_id=<?= (int)$r['id'] ?>&amp;from=<?= h($from) ?>&amp;to=<?= h($to) ?>" class="text-decoration-none text-reset" title="Open account ledger"><span class="tb-code"><?= h($r['code']) ?></span> <?= h($r['name']) ?></a><?php else: ?><span class="tb-code"><?= h($r['code']) ?></span> <?= h($r['name']) ?><?php endif; ?>
            </td>
            <td class="tb-edge text-muted"><?= h($r['type']) ?></td>
            <?php foreach (FIN_TB_COLUMNS as $i => $c): ?>
              <?= str_replace('class="tb-num', 'class="' . ($i % 2 === 0 ? 'tb-edge ' : '') . ($i < 2 || $i > 3 ? 'tb-g1 ' : '') . 'tb-num', fin_tb_cell($r[$c])) ?>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="tb-total">
            <td>Total</td>
            <td class="tb-edge"></td>
            <?php foreach (FIN_TB_COLUMNS as $i => $c): ?>
              <?= str_replace('class="tb-num', 'class="' . ($i % 2 === 0 ? 'tb-edge ' : '') . 'tb-num', fin_tb_cell($t[$c])) ?>
            <?php endforeach; ?>
          </tr>
        </tfoot>
      </table>
    </div>
    <div class="card-footer small <?= $balanced ? 'text-success' : 'text-danger' ?>">
      <?php if ($balanced): ?>
        <i class="bi bi-check-circle"></i> Debits equal credits.
      <?php else: ?>
        <i class="bi bi-exclamation-triangle"></i> Debits and credits differ by <?= number_format(abs($t['end_debit'] - $t['end_credit']), 2) ?> AED at the end of the period.
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
<script>
(function () {
  const table = document.getElementById('tbTable');
  if (!table) return;
  const rows = Array.from(table.querySelectorAll('tbody tr[data-id]'));
  const byId = new Map(rows.map(r => [r.dataset.id, r]));
  const collapsed = new Set();
  const hideZero = document.getElementById('tbHideZero');

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
      const off = hiddenByParent(r) || (hideZero.checked && r.dataset.active === '0');
      r.classList.toggle('d-none', off);
      const icon = r.querySelector('.tb-toggle i');
      if (icon) icon.className = 'bi ' + (collapsed.has(r.dataset.id) ? 'bi-caret-right-fill' : 'bi-caret-down-fill');
    });
  }

  table.addEventListener('click', e => {
    const row = e.target.closest('tr.tb-head');
    if (!row || !e.target.closest('.tb-name')) return;
    collapsed.has(row.dataset.id) ? collapsed.delete(row.dataset.id) : collapsed.add(row.dataset.id);
    render();
  });
  hideZero.addEventListener('change', render);
  document.getElementById('tbExpand').addEventListener('click', () => { collapsed.clear(); render(); });
  document.getElementById('tbCollapse').addEventListener('click', () => {
    rows.forEach(r => { if (r.classList.contains('tb-head')) collapsed.add(r.dataset.id); });
    render();
  });
})();
</script>
</body>
</html>
