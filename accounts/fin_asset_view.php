<?php
/**
 * Finance > Fixed Assets: one asset (?id=) with its month-by-month depreciation
 * and where each month stands. It can be stopped (sold, scrapped), started
 * again, or deleted while nothing of it is posted.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_asset_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_asset_ready($conn);
$id = (int)($_GET['id'] ?? 0);
$msg = $_GET['msg'] ?? '';
$err = '';

if ($tableOk && $company && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        switch ((string)($_POST['action'] ?? '')) {
            case 'stop':
                fin_asset_stop($conn, $companyId, $id, trim((string)($_POST['stop_date'] ?? '')), $userId);
                $done = 'Depreciation stopped.';
                break;
            case 'restart':
                fin_asset_stop($conn, $companyId, $id, null, $userId);
                $done = 'Depreciation started again.';
                break;
            case 'delete':
                fin_asset_delete($conn, $companyId, $id);
                header('Location: fin_assets.php?msg=' . urlencode('Asset deleted.'));
                exit;
            default:
                throw new InvalidArgumentException('Unknown action.');
        }
        header('Location: fin_asset_view.php?id=' . $id . '&msg=' . urlencode($done));
        exit;
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        error_log('fin_asset_view: ' . $e->getMessage());
        $err = 'The change could not be saved.';
    }
}

$a = ($tableOk && $company && $id) ? fin_asset_get($conn, $companyId, $id) : null;
if ($a) {
    fin_asset_generate($conn, $companyId, date('Y-m-d'), $id);
}
$schedule = $a ? fin_asset_schedule($conn, $companyId, $a) : [];
$totals = $a ? fin_asset_totals($a, $schedule) : null;
$posted = $a ? fin_asset_posted_count($conn, $companyId, $id) : 0;
$states = [
    'before'    => ['Before system', 'text-bg-light border'],
    'posted'    => ['Posted', 'text-bg-success'],
    'pending'   => ['Pending approval', 'text-bg-warning'],
    'skipped'   => ['Skipped', 'text-bg-secondary'],
    'due later' => ['Due later', 'text-bg-light border text-muted'],
];

$pageTitle = $a ? $a['name'] : 'Asset';
$finActive = 'assets';
$finReturnPath = dirname(strtok($_SERVER['REQUEST_URI'] ?? '', '?')) . '/fin_assets.php';
$pageStyles = '
  .v-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .v-meta dt { font-weight: 400; color: #6c757d; }
  .v-meta dd { margin-bottom: .35rem; }
  tr.v-later td { color: #98a2b3; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($msg): ?><div class="alert alert-success fin-noprint"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger fin-noprint"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_fixed_assets.sql first.</div>
  <?php elseif (!$a): ?>
    <div class="alert alert-info">This asset does not exist in this company. <a href="fin_assets.php">Go to Fixed Assets</a>.</div>
  <?php else: ?>

  <div class="d-flex flex-wrap gap-2 mb-3 fin-noprint">
    <a href="fin_assets.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Fixed Assets</a>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    <a href="fin_asset_form.php?id=<?= (int)$a['id'] ?>" class="btn btn-sm btn-outline-primary ms-auto"><i class="bi bi-pencil"></i> Edit</a>
    <?php if ($a['status'] === 'active'): ?>
    <form method="post" class="d-flex gap-1" onsubmit="return confirm('Stop depreciating this asset from this date? Later months will not be made.');">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="stop">
      <input type="date" name="stop_date" class="form-control form-control-sm" required title="Date sold or scrapped">
      <button class="btn btn-sm btn-outline-warning text-nowrap">Stop</button>
    </form>
    <?php else: ?>
    <form method="post">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="restart">
      <button class="btn btn-sm btn-outline-success">Start again</button>
    </form>
    <?php endif; ?>
    <?php if (!$posted): ?>
    <form method="post" onsubmit="return confirm('Delete this asset and its months not posted?');">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="delete">
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Delete</button>
    </form>
    <?php endif; ?>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <dl class="row mb-0 v-meta">
        <dt class="col-sm-2">Cost</dt><dd class="col-sm-4 fw-bold"><?= number_format((float)$a['cost'], 2) ?> AED</dd>
        <dt class="col-sm-2">Useful life</dt><dd class="col-sm-4"><?= h(fin_asset_life_label((int)$a['life_months'])) ?> · about <?= number_format($totals['monthly'], 2) ?> a month</dd>
        <dt class="col-sm-2">Starts</dt><dd class="col-sm-4"><?= h(date('d M Y', strtotime($a['start_date']))) ?></dd>
        <dt class="col-sm-2">Ends</dt><dd class="col-sm-4"><?= h(date('d M Y', strtotime(fin_schedule_end($a['start_date'], (int)$a['life_months'])))) ?><?= $a['status'] === 'stopped' ? ' · <span class="badge text-bg-secondary">Stopped ' . h(date('d M Y', strtotime($a['stop_date']))) . '</span>' : '' ?></dd>
        <dt class="col-sm-2">Asset account</dt><dd class="col-sm-4"><?= h($a['asset_account']) ?></dd>
        <dt class="col-sm-2">Posts from</dt><dd class="col-sm-4"><?= h(date('M Y', strtotime($a['post_from']))) ?></dd>
        <dt class="col-sm-2">Accumulated dep.</dt><dd class="col-sm-4"><?= h($a['accum_account']) ?></dd>
        <dt class="col-sm-2">Expense</dt><dd class="col-sm-4"><?= h($a['expense_account']) ?></dd>
        <?php if ($a['source_no']): ?><dt class="col-sm-2">Bought on</dt><dd class="col-sm-4"><a href="fin_voucher_view.php?id=<?= (int)$a['source_voucher_id'] ?>"><?= h($a['source_no']) ?></a></dd><?php endif; ?>
        <?php if ($a['notes']): ?><dt class="col-sm-2">Notes</dt><dd class="col-sm-10"><?= h($a['notes']) ?></dd><?php endif; ?>
      </dl>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <?php foreach ([['Before system', $totals['before']], ['Posted', $totals['posted']], ['Book value', $totals['book_value']]] as [$label, $val]): ?>
    <div class="col-sm-4"><div class="card"><div class="card-body py-2"><div class="small text-muted"><?= h($label) ?></div><div class="fs-5 fw-bold"><?= number_format($val, 2) ?> AED</div></div></div></div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="card-header d-flex">Month by month <a href="fin_pending_entries.php" class="ms-auto small fin-noprint">Pending Approval</a></div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Month</th><th class="text-end">Days</th><th class="text-end">Depreciation (AED)</th><th>Status</th><th>Voucher</th></tr></thead>
        <tbody>
        <?php foreach ($schedule as $r): [$label, $class] = $states[$r['state']]; ?>
          <tr class="<?= $r['state'] === 'due later' ? 'v-later' : '' ?>">
            <td><?= h(date('M Y', strtotime($r['period']))) ?></td>
            <td class="v-num"><?= (int)$r['days'] ?></td>
            <td class="v-num"><?= number_format($r['amount'], 2) ?></td>
            <td><span class="badge <?= h($class) ?>"><?= h($label) ?></span></td>
            <td><?= $r['voucher_no'] ? '<a href="fin_voucher_view.php?id=' . (int)$r['voucher_id'] . '">' . h($r['voucher_no']) . '</a>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
</body>
</html>
