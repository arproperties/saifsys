<?php
/**
 * Finance > Partner Ledger: the statement of one tenant or supplier for a period
 * (?partner=tenant:12&from=&to=): every line that carries them, with the account
 * it was posted to, a running balance and a link to each voucher.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_tb_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_voucher_tables_ready($conn);
$partnerKey = (string)($_GET['partner'] ?? '');
$err = '';

$from = $to = date('Y-m-d');
$ledger = null;
$partners = [];
if ($tableOk && $company) {
    // With no dates the page opens on the year of the latest entry, up to the end of its month.
    $to = fin_tb_default_period($conn, $companyId)[1];
    $from = date('Y-01-01', strtotime($to));
    $reqFrom = (string)($_GET['from'] ?? '');
    $reqTo = (string)($_GET['to'] ?? '');
    if ($reqFrom !== '' || $reqTo !== '') {
        if (fin_tb_valid_date($reqFrom) && fin_tb_valid_date($reqTo) && $reqFrom <= $reqTo) {
            [$from, $to] = [$reqFrom, $reqTo];
        } else {
            $err = 'Choose a valid period: the From date must be on or before the To date.';
        }
    }
    $partners = fin_partners($conn, $companyId);
    if ($partnerKey !== '') {
        $ledger = fin_partner_ledger($conn, $companyId, $partnerKey, $from, $to);
    }
}
$period = '&from=' . $from . '&to=' . $to;

$pageTitle = 'Partner Ledger';
$finActive = 'pled';
$finReturnPath = dirname(strtok($_SERVER['REQUEST_URI'] ?? '', '?')) . '/fin_partner_ledger.php';
$pageSubtitle = $ledger ? $ledger['partner']['name'] : '';
$pageStyles = '
  .v-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($err): ?><div class="alert alert-danger fin-noprint"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_chart_of_accounts.sql, fin_ledger_entries.sql, fin_vouchers.sql and fin_partners.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company to see its tenants and suppliers.</div>
  <?php elseif (!$partners): ?>
    <div class="alert alert-info"><?= h($company['name']) ?> has no tenants or suppliers yet.</div>
  <?php else: ?>

  <div class="card mb-3 fin-noprint">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-md-5">
          <label class="form-label small mb-1" for="plPartner">Tenant / supplier</label>
          <select class="form-select form-select-sm" id="plPartner" name="partner" required>
            <option value="">Choose a tenant or supplier</option>
            <?php foreach (FIN_PARTNER_KINDS as $k => $label): ?>
            <optgroup label="<?= h($label) ?>">
              <?php foreach ($partners as $p): if ($p['kind'] !== $k) { continue; } ?>
                <option value="<?= h($p['key']) ?>" <?= $p['key'] === $partnerKey ? 'selected' : '' ?>><?= h($p['name']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="plFrom">From</label>
          <input type="date" class="form-control form-control-sm" id="plFrom" name="from" value="<?= h($from) ?>" required>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="plTo">To</label>
          <input type="date" class="form-control form-control-sm" id="plTo" name="to" value="<?= h($to) ?>" required>
        </div>
        <div class="col-auto"><button class="btn btn-sm btn-primary">Show</button></div>
        <div class="col-auto ms-md-auto d-flex gap-2">
          <a href="fin_partner_balances.php?as_at=<?= h($to) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Partner Balances</a>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($partnerKey !== '' && !$ledger): ?>
    <div class="alert alert-info">This tenant or supplier does not exist in <?= h($company['name']) ?>. Choose one above.</div>
  <?php elseif (!$ledger): ?>
    <div class="alert alert-info">Choose a tenant or supplier to see their statement.</div>
  <?php else: ?>
  <div class="card">
    <div class="card-header d-flex flex-wrap gap-2">
      <span class="fw-bold me-auto"><?= h($ledger['partner']['name']) ?> <span class="badge text-bg-light border"><?= $ledger['partner']['kind'] === 'tenant' ? 'Tenant' : 'Supplier' ?></span></span>
      <span class="text-muted small"><?= h(date('d M Y', strtotime($from))) ?> to <?= h(date('d M Y', strtotime($to))) ?></span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>Date</th><th>Voucher</th><th>Account</th><th>Description</th><th class="text-end">Debit (AED)</th><th class="text-end">Credit (AED)</th><th class="text-end">Balance (AED)</th></tr></thead>
        <tbody>
          <tr class="table-light"><td colspan="6">Opening balance</td><td class="v-num"><?= h(fin_balance_label($ledger['opening'])) ?></td></tr>
        <?php foreach ($ledger['lines'] as $l): ?>
          <tr>
            <td class="text-nowrap"><?= h(date('d M Y', strtotime($l['entry_date']))) ?></td>
            <td class="text-nowrap"><a href="fin_voucher_view.php?id=<?= (int)$l['voucher_id'] ?>"><?= h($l['voucher_no']) ?></a></td>
            <td><a href="fin_account_ledger.php?account_id=<?= (int)$l['account_id'] ?><?= h($period) ?>" class="text-reset text-decoration-none"><?= h($l['code'] . ' ' . $l['account_name']) ?></a></td>
            <td><?= h($l['description'] !== null && $l['description'] !== '' ? $l['description'] : $l['reference']) ?></td>
            <td class="v-num"><?= (float)$l['debit'] > 0 ? number_format((float)$l['debit'], 2) : '' ?></td>
            <td class="v-num"><?= (float)$l['credit'] > 0 ? number_format((float)$l['credit'], 2) : '' ?></td>
            <td class="v-num"><?= h(fin_balance_label((float)$l['balance'])) ?></td>
          </tr>
        <?php endforeach; if (!$ledger['lines']): ?>
          <tr><td colspan="7" class="text-muted text-center py-3">No entries in this period.</td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot>
          <tr class="fw-bold">
            <td colspan="4">Closing balance</td>
            <td class="v-num"><?= number_format($ledger['debit'], 2) ?></td>
            <td class="v-num"><?= number_format($ledger['credit'], 2) ?></td>
            <td class="v-num"><?= h(fin_balance_label($ledger['closing'])) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
</body>
</html>
