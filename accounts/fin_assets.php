<?php
/**
 * Finance > Fixed Assets: the asset list with cost, depreciation so far and
 * book value. Opening it also makes any depreciation month that has come due.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_asset_helper.php';
require_once __DIR__ . '/../includes/fin_allocation_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_asset_ready($conn);
$msg = $_GET['msg'] ?? '';
if ($tableOk && $company) {
    fin_auto_generate($conn, $companyId);
}
$assets = ($tableOk && $company) ? fin_asset_list($conn, $companyId) : [];
$pending = ($tableOk && $company) ? count(fin_auto_entries($conn, $companyId, 'pending', 'asset')) : 0;
$sum = ['cost' => 0.0, 'before' => 0.0, 'posted' => 0.0, 'book_value' => 0.0];
foreach ($assets as $a) {
    $sum['cost'] += (float)$a['cost'];
    foreach (['before', 'posted', 'book_value'] as $k) {
        $sum[$k] += $a['totals'][$k];
    }
}

$pageTitle = 'Fixed Assets';
$finActive = 'assets';
$pageStyles = '.v-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_fixed_assets.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company to see its assets.</div>
  <?php else: ?>

  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php if ($pending): ?>
      <a href="fin_pending_entries.php" class="btn btn-sm btn-warning"><i class="bi bi-hourglass-split"></i> <?= $pending ?> depreciation <?= $pending === 1 ? 'entry' : 'entries' ?> waiting for approval</a>
    <?php endif; ?>
    <a href="fin_asset_form.php" class="btn btn-sm btn-success ms-auto"><i class="bi bi-plus-lg"></i> New Asset</a>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>Asset</th><th>Account</th><th>Start</th><th class="text-end">Life</th>
            <th class="text-end">Cost</th><th class="text-end">Monthly</th><th class="text-end">Before system</th>
            <th class="text-end">Posted</th><th class="text-end">Book value</th><th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($assets as $a): ?>
          <tr>
            <td><a href="fin_asset_view.php?id=<?= (int)$a['id'] ?>"><?= h($a['name']) ?></a><?php if ($a['source_no']): ?><div class="text-muted small"><?= h($a['source_no']) ?></div><?php endif; ?></td>
            <td class="small"><?= h($a['asset_account']) ?></td>
            <td class="text-nowrap"><?= h(date('d M Y', strtotime($a['start_date']))) ?></td>
            <td class="v-num"><?= h(fin_asset_life_label((int)$a['life_months'])) ?></td>
            <td class="v-num"><?= number_format((float)$a['cost'], 2) ?></td>
            <td class="v-num"><?= number_format($a['totals']['monthly'], 2) ?></td>
            <td class="v-num"><?= $a['totals']['before'] > 0 ? number_format($a['totals']['before'], 2) : '' ?></td>
            <td class="v-num"><?= number_format($a['totals']['posted'], 2) ?></td>
            <td class="v-num fw-bold"><?= number_format($a['totals']['book_value'], 2) ?></td>
            <td><?= $a['status'] === 'stopped' ? '<span class="badge text-bg-secondary">Stopped ' . h(date('d M Y', strtotime($a['stop_date']))) . '</span>' : '<span class="badge text-bg-success">Active</span>' ?></td>
          </tr>
        <?php endforeach; if (!$assets): ?>
          <tr><td colspan="10" class="text-muted text-center py-4">No assets yet. Add one here, or from a supplier invoice.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($assets): ?>
        <tfoot>
          <tr class="fw-bold">
            <td colspan="4">Total (<?= count($assets) ?>)</td>
            <td class="v-num"><?= number_format($sum['cost'], 2) ?></td><td></td>
            <td class="v-num"><?= number_format($sum['before'], 2) ?></td>
            <td class="v-num"><?= number_format($sum['posted'], 2) ?></td>
            <td class="v-num"><?= number_format($sum['book_value'], 2) ?></td><td></td>
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
