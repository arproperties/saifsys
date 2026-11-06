<?php
/**
 * Finance > Allocations: prepaid expenses and accrued revenue spread over
 * months, with what is posted and what is left. Opening it also makes any
 * month that has come due.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_allocation_helper.php';
require_once __DIR__ . '/../includes/fin_asset_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_alc_ready($conn);
$msg = $_GET['msg'] ?? '';
if ($tableOk && $company) {
    fin_auto_generate($conn, $companyId);
}
$rows = ($tableOk && $company) ? fin_alc_list($conn, $companyId) : [];
$pending = ($tableOk && $company) ? count(fin_auto_entries($conn, $companyId, 'pending', 'allocation')) : 0;

$pageTitle = 'Allocations';
$pageSubtitle = 'Prepaid expenses and accrued revenue';
$finActive = 'alc';
$pageStyles = '.v-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_allocation_journals.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company.</div>
  <?php else: ?>

  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php if ($pending): ?>
      <a href="fin_pending_entries.php" class="btn btn-sm btn-warning"><i class="bi bi-hourglass-split"></i> <?= $pending ?> allocation <?= $pending === 1 ? 'entry' : 'entries' ?> waiting for approval</a>
    <?php endif; ?>
    <a href="fin_allocation_form.php?kind=prepaid" class="btn btn-sm btn-success ms-auto"><i class="bi bi-plus-lg"></i> New Prepaid Expense</a>
    <a href="fin_allocation_form.php?kind=accrued" class="btn btn-sm btn-success"><i class="bi bi-plus-lg"></i> New Accrued Revenue</a>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead>
          <tr><th>Name</th><th>Kind</th><th>Dr / Cr each month</th><th>Start</th><th class="text-end">Months</th>
              <th class="text-end">Amount</th><th class="text-end">Monthly</th><th class="text-end">Posted</th><th class="text-end">Left</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $s): ?>
          <tr>
            <td><a href="fin_allocation_view.php?id=<?= (int)$s['id'] ?>"><?= h($s['name']) ?></a><?php if ($s['source_no']): ?><div class="text-muted small"><?= h($s['source_no']) ?></div><?php endif; ?></td>
            <td><?= h(FIN_ALC_KINDS[$s['kind']]['label']) ?></td>
            <td class="small">Dr <?= h($s['debit_account']) ?><br>Cr <?= h($s['credit_account']) ?></td>
            <td class="text-nowrap"><?= h(date('d M Y', strtotime($s['start_date']))) ?></td>
            <td class="v-num"><?= (int)$s['months'] ?></td>
            <td class="v-num"><?= number_format((float)$s['amount'], 2) ?></td>
            <td class="v-num"><?= number_format($s['totals']['monthly'], 2) ?></td>
            <td class="v-num"><?= number_format($s['totals']['posted'], 2) ?></td>
            <td class="v-num fw-bold"><?= number_format($s['totals']['left'], 2) ?></td>
            <td><?= $s['status'] === 'stopped' ? '<span class="badge text-bg-secondary">Stopped ' . h(date('d M Y', strtotime($s['stop_date']))) . '</span>' : '<span class="badge text-bg-success">Active</span>' ?></td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td colspan="10" class="text-muted text-center py-4">No allocations yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
</body>
</html>
