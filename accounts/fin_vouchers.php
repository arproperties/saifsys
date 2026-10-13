<?php
/**
 * Finance > Vouchers: list of one kind for the company selected in the session.
 * Opened through fin_journal_vouchers.php, fin_petty_cash.php,
 * fin_payment_vouchers.php or fin_receipt_vouchers.php, which set $finVoucherType.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_voucher_helper.php';

$type = (string)($finVoucherType ?? $_GET['type'] ?? 'jv');
if (!isset(FIN_VOUCHER_TYPES[$type])) {
    $type = 'jv';
}
$vt = FIN_VOUCHER_TYPES[$type];

$tableOk = fin_coa_table_ready($conn) && fin_voucher_tables_ready($conn);
$msg = $_GET['msg'] ?? '';
$err = '';

$from = (string)($_GET['from'] ?? '');
$to = (string)($_GET['to'] ?? '');
if (($from !== '' && !fin_voucher_valid_date($from)) || ($to !== '' && !fin_voucher_valid_date($to)) || ($from !== '' && $to !== '' && $from > $to)) {
    $err = 'Choose a valid period: the From date must be on or before the To date.';
    $from = $to = '';
}

$showDeleted = !empty($_GET['deleted']);
$rows = ($tableOk && $company) ? fin_voucher_list($conn, $companyId, $type, $from ?: null, $to ?: null, $showDeleted) : [];
$total = 0.0;
foreach ($rows as $r) {
    if ($r['status'] === 'posted') {
        $total += (float)$r['amount'];
    }
}

$pageTitle = $vt['plural'];
$finActive = $type;
$finReturnPath = dirname(strtok($_SERVER['REQUEST_URI'] ?? '', '?')) . '/' . $vt['list'];
$pageStyles = '
  .v-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  tr.v-void td { color: #adb5bd; text-decoration: line-through; }
  tr.v-void td.v-keep { text-decoration: none; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_chart_of_accounts.sql, fin_ledger_entries.sql, fin_vouchers.sql and fin_partners.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company to see its vouchers.</div>
  <?php else: ?>

  <div class="card mb-3">
    <div class="card-body">
      <form method="get" action="<?= h($vt['list']) ?>" class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="vFrom">From</label>
          <input type="date" class="form-control form-control-sm" id="vFrom" name="from" value="<?= h($from) ?>">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="vTo">To</label>
          <input type="date" class="form-control form-control-sm" id="vTo" name="to" value="<?= h($to) ?>">
        </div>
        <div class="col-auto">
          <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" id="vDeleted" name="deleted" value="1" <?= $showDeleted ? 'checked' : '' ?>>
            <label class="form-check-label small" for="vDeleted">Show deleted</label>
          </div>
        </div>
        <div class="col-auto">
          <button class="btn btn-sm btn-primary">Show</button>
          <?php if ($from !== '' || $to !== '' || $showDeleted): ?><a href="<?= h($vt['list']) ?>" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
        </div>
        <div class="col-auto ms-md-auto">
          <a href="fin_voucher_history.php?type=<?= h($type) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-clock-history"></i> History</a>
          <a href="<?= h($vt['new']) ?>" class="btn btn-sm btn-success"><i class="bi bi-plus-lg"></i> New <?= h($vt['label']) ?></a>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>Voucher no.</th><th>Date</th>
            <?php if ($vt['party'] !== ''): ?><th><?= h($vt['party']) ?></th><?php endif; ?>
            <th>Reference</th><th>Narration</th><th class="text-end">Amount (AED)</th><th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr class="<?= $r['status'] === 'void' ? 'v-void' : '' ?>">
            <td class="v-keep"><a href="fin_voucher_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['voucher_no']) ?></a></td>
            <td><?= h(date('d M Y', strtotime($r['voucher_date']))) ?></td>
            <?php if ($vt['party'] !== ''): ?><td><?= h($r['party']) ?></td><?php endif; ?>
            <td><?= h($r['reference']) ?></td>
            <td><?= h($r['narration']) ?></td>
            <td class="v-num"><?= number_format((float)$r['amount'], 2) ?></td>
            <td class="v-keep">
              <?= $r['status'] === 'void' ? '<span class="badge text-bg-secondary">Deleted</span>' : '<span class="badge text-bg-success">Posted</span>' ?>
            </td>
            <td class="v-keep text-end text-nowrap">
              <?php if ($r['status'] === 'posted'): ?>
              <a href="fin_voucher_edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-link py-0">Edit</a>
              <form method="post" action="fin_voucher_view.php?id=<?= (int)$r['id'] ?>" class="d-inline" onsubmit="return confirm('Delete <?= h($r['voucher_no']) ?>? Its amounts will stop counting in the trial balance. This cannot be undone.');">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="back" value="list">
                <button class="btn btn-sm btn-link text-danger py-0">Delete</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td colspan="8" class="text-muted text-center py-4">No <?= h(strtolower($vt['plural'])) ?> <?= ($from !== '' || $to !== '') ? 'in this period' : 'yet' ?>.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot>
          <tr class="fw-bold">
            <td colspan="<?= $vt['party'] !== '' ? 5 : 4 ?>">Total posted (<?= count($rows) ?> shown)</td>
            <td class="v-num"><?= number_format($total, 2) ?></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
</body>
</html>
