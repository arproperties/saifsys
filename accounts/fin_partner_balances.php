<?php
/**
 * Finance > Partner Balances: what each tenant and supplier stands at on a date
 * (?kind=tenant|supplier&as_at=), from the ledger lines that carry a partner.
 * Each name opens that partner's statement.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_tb_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_voucher_tables_ready($conn);
$err = '';

$kind = (string)($_GET['kind'] ?? '');
if (!isset(FIN_PARTNER_KINDS[$kind])) {
    $kind = '';
}
$asAt = date('Y-m-d');
$bal = ['rows' => [], 'totals' => ['debit' => 0.0, 'credit' => 0.0, 'balance' => 0.0]];
if ($tableOk && $company) {
    // With no date the page opens on the end of the month of the latest entry.
    $asAt = fin_tb_default_period($conn, $companyId)[1];
    $reqAsAt = (string)($_GET['as_at'] ?? '');
    if ($reqAsAt !== '') {
        if (fin_tb_valid_date($reqAsAt)) {
            $asAt = $reqAsAt;
        } else {
            $err = 'Choose a valid date.';
        }
    }
    $bal = fin_partner_balances($conn, $companyId, $asAt, $kind ?: null);
}
$t = $bal['totals'];
$ledgerPeriod = '&from=' . date('Y-01-01', strtotime($asAt)) . '&to=' . $asAt;

$pageTitle = 'Partner Balances';
$finActive = 'pbal';
$pageSubtitle = ($tableOk && $company) ? 'As at ' . date('d M Y', strtotime($asAt)) : '';
$pageStyles = '
  .v-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($err): ?><div class="alert alert-danger fin-noprint"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_chart_of_accounts.sql, fin_ledger_entries.sql, fin_vouchers.sql and fin_partners.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company to see its tenants and suppliers.</div>
  <?php else: ?>

  <div class="card mb-3 fin-noprint">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label small mb-1" for="pbKind">Show</label>
          <select class="form-select form-select-sm" id="pbKind" name="kind">
            <option value="">Tenants and suppliers</option>
            <?php foreach (FIN_PARTNER_KINDS as $k => $label): ?>
              <option value="<?= h($k) ?>" <?= $k === $kind ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="pbAsAt">As at</label>
          <input type="date" class="form-control form-control-sm" id="pbAsAt" name="as_at" value="<?= h($asAt) ?>" required>
        </div>
        <div class="col-auto"><button class="btn btn-sm btn-primary">Show</button></div>
        <div class="col-auto ms-md-auto">
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        </div>
      </form>
    </div>
  </div>

  <?php if (!$bal['rows']): ?>
    <div class="alert alert-info">No entries carry a <?= $kind === 'supplier' ? 'supplier' : ($kind === 'tenant' ? 'tenant' : 'tenant or supplier') ?> up to this date. Choose one on a voucher line (Tenant / supplier), or post a lease, and it shows here.</div>
  <?php else: ?>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>Name</th><th>Type</th><th class="text-end">Debit (AED)</th><th class="text-end">Credit (AED)</th><th class="text-end">Balance (AED)</th></tr></thead>
        <tbody>
        <?php foreach ($bal['rows'] as $r): ?>
          <tr>
            <td><a href="fin_partner_ledger.php?partner=<?= h(rawurlencode($r['key'])) ?><?= h($ledgerPeriod) ?>"><?= h($r['name']) ?></a></td>
            <td><?= $r['kind'] === 'tenant' ? 'Tenant' : 'Supplier' ?></td>
            <td class="v-num"><?= number_format($r['debit'], 2) ?></td>
            <td class="v-num"><?= number_format($r['credit'], 2) ?></td>
            <td class="v-num fw-semibold"><?= h(fin_balance_label($r['balance'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="fw-bold">
            <td colspan="2">Total (<?= count($bal['rows']) ?>)</td>
            <td class="v-num"><?= number_format($t['debit'], 2) ?></td>
            <td class="v-num"><?= number_format($t['credit'], 2) ?></td>
            <td class="v-num"><?= h(fin_balance_label($t['balance'])) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
  <p class="text-muted small mt-2 mb-0">Dr is owed to <?= h($company['name']) ?>; Cr is owed by it. Only lines with a tenant or supplier chosen on them are counted.</p>
  <?php endif; ?>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
</body>
</html>
