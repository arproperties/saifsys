<?php
/**
 * Finance > Account ledger: every posted line of one account for a period
 * (?account_id=&from=&to=), with a running balance and a link to each voucher.
 * Opened from the Trial Balance, the Chart of Accounts and a voucher.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_voucher_helper.php';
require_once __DIR__ . '/../includes/fin_tb_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_voucher_tables_ready($conn);
$accountId = (int)($_GET['account_id'] ?? 0);
$err = '';

$from = $to = date('Y-m-d');
$ledger = null;
$accounts = [];
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
    // Last-level accounts, plus any account that holds entries. Inactive ones
    // stay in the picker: they can still hold past entries.
    $stmt = $conn->prepare("
        SELECT a.id, a.code, a.name
          FROM fin_coa_accounts a
         WHERE a.company_id = ? AND a.level <> 'total'
           AND (NOT EXISTS (SELECT 1 FROM fin_coa_accounts c WHERE c.parent_id = a.id AND c.level <> 'total')
                OR EXISTS (SELECT 1 FROM fin_ledger_entries e WHERE e.account_id = a.id))
         ORDER BY a.code
    ");
    $stmt->execute([$companyId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($accountId > 0) {
        $ledger = fin_account_ledger($conn, $companyId, $accountId, $from, $to);
    }
}
$period = '&from=' . $from . '&to=' . $to;

$pageTitle = 'Account Ledger';
$finActive = 'tb';
$finReturnPath = dirname(strtok($_SERVER['REQUEST_URI'] ?? '', '?')) . '/fin_trial_balance.php';
$pageSubtitle = $ledger ? $ledger['account']['code'] . ' ' . $ledger['account']['name'] : '';
$pageStyles = '
  .v-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($err): ?><div class="alert alert-danger fin-noprint"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_chart_of_accounts.sql, fin_ledger_entries.sql, fin_vouchers.sql and fin_partners.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company to see its accounts.</div>
  <?php elseif (!$accounts): ?>
    <div class="alert alert-info">This company has no accounts yet. <a href="fin_chart_of_accounts.php">Set up the chart of accounts</a> first.</div>
  <?php else: ?>

  <div class="card mb-3 fin-noprint">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-md-5">
          <label class="form-label small mb-1" for="lAccount">Account</label>
          <select class="form-select form-select-sm" id="lAccount" name="account_id" required>
            <option value="">Choose an account</option>
            <?php foreach ($accounts as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === $accountId ? 'selected' : '' ?>><?= h($a['code'] . ' ' . $a['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="lFrom">From</label>
          <input type="date" class="form-control form-control-sm" id="lFrom" name="from" value="<?= h($from) ?>" required>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="lTo">To</label>
          <input type="date" class="form-control form-control-sm" id="lTo" name="to" value="<?= h($to) ?>" required>
        </div>
        <div class="col-auto"><button class="btn btn-sm btn-primary">Show</button></div>
        <div class="col-auto ms-md-auto d-flex gap-2">
          <a href="fin_trial_balance.php?<?= h(ltrim($period, '&')) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Trial Balance</a>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($accountId > 0 && !$ledger): ?>
    <div class="alert alert-info">This account does not exist in <?= h($company['name']) ?>. Choose an account above.</div>
  <?php elseif (!$ledger): ?>
    <div class="alert alert-info">Choose an account to see its entries.</div>
  <?php else: ?>
  <div class="card">
    <div class="card-header d-flex flex-wrap gap-2">
      <span class="fw-bold me-auto"><?= h($ledger['account']['code'] . ' ' . $ledger['account']['name']) ?><?= $ledger['account']['is_active'] ? '' : ' <span class="badge text-bg-secondary">Inactive</span>' ?></span>
      <span class="text-muted small"><?= h(date('d M Y', strtotime($from))) ?> to <?= h(date('d M Y', strtotime($to))) ?></span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>Date</th><th>Voucher</th><th>Description</th><th class="text-end">Debit (AED)</th><th class="text-end">Credit (AED)</th><th class="text-end">Balance (AED)</th></tr></thead>
        <tbody>
          <tr class="table-light"><td colspan="5">Opening balance</td><td class="v-num"><?= h(fin_balance_label($ledger['opening'])) ?></td></tr>
        <?php foreach ($ledger['lines'] as $l): ?>
          <tr>
            <td class="text-nowrap"><?= h(date('d M Y', strtotime($l['entry_date']))) ?></td>
            <td class="text-nowrap"><a href="fin_voucher_view.php?id=<?= (int)$l['voucher_id'] ?>"><?= h($l['voucher_no']) ?></a></td>
            <td><?= h($l['description'] !== null && $l['description'] !== '' ? $l['description'] : $l['party']) ?><?= fin_partner_link($l) ?></td>
            <td class="v-num"><?= (float)$l['debit'] > 0 ? number_format((float)$l['debit'], 2) : '' ?></td>
            <td class="v-num"><?= (float)$l['credit'] > 0 ? number_format((float)$l['credit'], 2) : '' ?></td>
            <td class="v-num"><?= h(fin_balance_label((float)$l['balance'])) ?></td>
          </tr>
        <?php endforeach; if (!$ledger['lines']): ?>
          <tr><td colspan="6" class="text-muted text-center py-3">No entries in this period.</td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot>
          <tr class="fw-bold">
            <td colspan="3">Closing balance</td>
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
