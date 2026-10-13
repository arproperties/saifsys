<?php
/**
 * Finance > Vouchers: one voucher (?id=) with its posted lines and its history
 * (who added, edited and deleted it). A posted voucher can be edited or deleted here.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_voucher_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_voucher_tables_ready($conn);
$id = (int)($_GET['id'] ?? 0);
$msg = $_GET['msg'] ?? '';
$err = '';

if ($tableOk && $company && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        if (($_POST['action'] ?? '') !== 'delete') {
            throw new InvalidArgumentException('Unknown action.');
        }
        $gone = fin_voucher_get($conn, $companyId, $id);
        fin_voucher_delete($conn, $companyId, $id, $userId);
        $done = urlencode($gone['voucher_no'] . ' deleted. Its amounts no longer count in any balance.');
        // Deleted from the list: go back to the list.
        header('Location: ' . (($_POST['back'] ?? '') === 'list' ? FIN_VOUCHER_TYPES[$gone['type']]['list'] . '?msg=' . $done : 'fin_voucher_view.php?id=' . $id . '&msg=' . $done));
        exit;
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        error_log('fin_voucher_view: ' . $e->getMessage());
        $err = 'The voucher could not be deleted.';
    }
}

$v = ($tableOk && $company && $id > 0) ? fin_voucher_get($conn, $companyId, $id) : null;
$type = $v ? $v['type'] : 'jv';
$vt = FIN_VOUCHER_TYPES[$type];
$history = $v ? array_reverse(fin_voucher_history($conn, $companyId, ['voucher_id' => $id])) : [];
$totalDebit = $totalCredit = 0.0;
if ($v) {
    foreach ($v['lines'] as $l) {
        $totalDebit += (float)$l['debit'];
        $totalCredit += (float)$l['credit'];
    }
}
// Month of the voucher, so an account link opens the ledger on the right period.
$ledgerPeriod = $v ? '&from=' . date('Y-m-01', strtotime($v['voucher_date'])) . '&to=' . date('Y-m-t', strtotime($v['voucher_date'])) : '';

$pageTitle = $v ? $vt['label'] . ' ' . $v['voucher_no'] : 'Voucher';
$finActive = $type;
$finReturnPath = dirname(strtok($_SERVER['REQUEST_URI'] ?? '', '?')) . '/' . $vt['list'];
$pageStyles = '
  .v-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .v-meta dt { font-weight: 400; color: #6c757d; }
  .v-meta dd { margin-bottom: .35rem; }
  .v-hist { list-style: none; margin: 0; padding: 0; }
  .v-hist li { padding: .7rem 1rem; border-top: 1px solid #edf0f3; }
  .v-hist li:first-child { border-top: 0; }
  .v-hist .v-when { color: #6c757d; font-size: .85rem; }
  .v-hist table { font-size: .85rem; margin: .4rem 0 0; }
  .v-hist td.v-old { color: #b02a37; }
  .v-hist td.v-new { color: #146c43; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($msg): ?><div class="alert alert-success fin-noprint"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger fin-noprint"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_chart_of_accounts.sql, fin_ledger_entries.sql, fin_vouchers.sql and fin_partners.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company to see its vouchers.</div>
  <?php elseif (!$v): ?>
    <div class="alert alert-info">This voucher does not exist in <?= h($company['name']) ?>. <a href="<?= h(FIN_VOUCHER_TYPES['jv']['list']) ?>">Go to the voucher list</a>.</div>
  <?php else: ?>

  <div class="d-flex flex-wrap gap-2 mb-3 fin-noprint">
    <a href="<?= h($vt['list']) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> <?= h($vt['plural']) ?></a>
    <a href="<?= h($vt['new']) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-plus-lg"></i> New <?= h($vt['label']) ?></a>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    <?php if ($v['status'] === 'posted'): ?>
    <a href="fin_voucher_edit.php?id=<?= (int)$v['id'] ?>" class="btn btn-sm btn-outline-primary ms-auto"><i class="bi bi-pencil"></i> Edit</a>
    <form method="post" onsubmit="return confirm('Delete <?= h($v['voucher_no']) ?>? Its amounts will stop counting in the trial balance. This cannot be undone.');">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="delete">
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Delete</button>
    </form>
    <?php endif; ?>
  </div>

  <?php if ($v['status'] === 'void'): ?>
    <div class="alert alert-secondary">This voucher was deleted<?= $v['voided_by_name'] ? ' by ' . h($v['voided_by_name']) : '' ?><?= $v['voided_at'] ? ' on ' . h(date('d M Y H:i', strtotime($v['voided_at']))) : '' ?>. Its lines are kept for the record and do not count in any balance.</div>
  <?php endif; ?>
  <div class="card mb-3">
    <div class="card-body">
      <dl class="row mb-0 v-meta">
        <dt class="col-sm-2">Voucher no.</dt><dd class="col-sm-4 fw-bold"><?= h($v['voucher_no']) ?></dd>
        <dt class="col-sm-2">Date</dt><dd class="col-sm-4"><?= h(date('d M Y', strtotime($v['voucher_date']))) ?></dd>
        <?php if ($vt['party'] !== ''): ?>
        <dt class="col-sm-2"><?= h($vt['party']) ?></dt><dd class="col-sm-4"><?= $v['party'] !== null ? h($v['party']) : '—' ?></dd>
        <?php endif; ?>
        <dt class="col-sm-2">Reference</dt><dd class="col-sm-4"><?= $v['reference'] !== null ? h($v['reference']) : '—' ?></dd>
        <dt class="col-sm-2">Narration</dt><dd class="col-sm-10"><?= $v['narration'] !== null ? h($v['narration']) : '—' ?></dd>
        <dt class="col-sm-2">Amount</dt><dd class="col-sm-4 fw-bold"><?= number_format((float)$v['amount'], 2) ?> AED</dd>
        <dt class="col-sm-2">Status</dt><dd class="col-sm-4"><?= $v['status'] === 'void' ? '<span class="badge text-bg-secondary">Deleted</span>' : '<span class="badge text-bg-success">Posted</span>' ?></dd>
      </dl>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Account</th><th>Tenant / supplier</th><th>Description</th><th class="text-end">Debit (AED)</th><th class="text-end">Credit (AED)</th></tr></thead>
        <tbody>
        <?php foreach ($v['lines'] as $l): ?>
          <tr>
            <td><a href="fin_account_ledger.php?account_id=<?= (int)$l['account_id'] ?><?= h($ledgerPeriod) ?>"><?= h($l['code'] . ' ' . $l['name']) ?></a></td>
            <td><?= fin_partner_link($l, false) ?></td>
            <td><?= h($l['description']) ?></td>
            <td class="v-num"><?= (float)$l['debit'] > 0 ? number_format((float)$l['debit'], 2) : '' ?></td>
            <td class="v-num"><?= (float)$l['credit'] > 0 ? number_format((float)$l['credit'], 2) : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="fw-bold"><td colspan="3">Total</td><td class="v-num"><?= number_format($totalDebit, 2) ?></td><td class="v-num"><?= number_format($totalCredit, 2) ?></td></tr>
        </tfoot>
      </table>
    </div>
  </div>

  <div class="card mt-3 fin-noprint">
    <div class="card-header">History</div>
    <ul class="v-hist">
    <?php foreach ($history as $hrow): ?>
      <li>
        <?= fin_voucher_action_badge($hrow['action']) ?>
        by <strong><?= h($hrow['user_name'] ?? 'Unknown user') ?></strong>
        <span class="v-when">on <?= h(date('d M Y, h:i A', strtotime($hrow['created_at']))) ?></span>
        <?php if ($hrow['changes']): ?>
        <table class="table table-sm table-borderless w-auto">
          <?php foreach ($hrow['changes'] as [$what, $old, $new]): ?>
          <tr><td class="text-muted pe-3"><?= h($what) ?></td><td class="v-old"><?= $old !== '' ? '<s>' . h($old) . '</s>' : '' ?></td><td class="px-2 text-muted"><?= $old !== '' && $new !== '' ? '→' : '' ?></td><td class="v-new"><?= h($new) ?></td></tr>
          <?php endforeach; ?>
        </table>
        <?php endif; ?>
      </li>
    <?php endforeach; if (!$history): ?>
      <li class="text-muted">No history recorded for this voucher.</li>
    <?php endif; ?>
    </ul>
  </div>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
</body>
</html>
