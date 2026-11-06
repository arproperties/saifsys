<?php
/**
 * Finance > Pending Approval: the monthly entries made from schedules
 * (depreciation of fixed assets, prepaid / accrued allocations). Nothing reaches the
 * ledger until Accounts approve it here. A month can be skipped instead, and a
 * skipped one brought back (?show=skipped). Opening the page also makes any
 * entry that has come due.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_asset_helper.php';
require_once __DIR__ . '/../includes/fin_allocation_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_asset_ready($conn);
$show = ($_GET['show'] ?? '') === 'skipped' ? 'skipped' : 'pending';
$msg = $_GET['msg'] ?? '';
$err = '';

if ($tableOk && $company && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ids = (array)($_POST['entry'] ?? []);
    try {
        switch ((string)($_POST['action'] ?? '')) {
            case 'approve':
                $made = fin_auto_approve($conn, $companyId, $ids, $userId);
                $done = count($made) === 1 ? '1 entry approved and posted.' : count($made) . ' entries approved and posted.';
                break;
            case 'skip':
                $done = fin_auto_skip($conn, $companyId, $ids, $userId) . ' skipped. Nothing was posted for them.';
                break;
            case 'restore':
                $done = fin_auto_skip($conn, $companyId, $ids, $userId, true) . ' brought back to pending.';
                break;
            default:
                throw new InvalidArgumentException('Unknown action.');
        }
        header('Location: fin_pending_entries.php?show=' . $show . '&msg=' . urlencode($done));
        exit;
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        error_log('fin_pending_entries: ' . $e->getMessage());
        $err = 'The entries could not be saved.';
    }
}

if ($tableOk && $company) {
    fin_auto_generate($conn, $companyId);
}
$rows = ($tableOk && $company) ? fin_auto_entries($conn, $companyId, $show) : [];
$counts = ['pending' => 0, 'skipped' => 0];
if ($tableOk && $company) {
    foreach (array_keys($counts) as $st) {
        $counts[$st] = count($show === $st ? $rows : fin_auto_entries($conn, $companyId, $st));
    }
}
$accountNames = [];
foreach (($tableOk && $company) ? fin_voucher_ledger_accounts($conn, $companyId) : [] as $acc) {
    $accountNames[(int)$acc['id']] = $acc['code'] . ' ' . $acc['name'];
}
$sourceLabel = ['asset' => 'Depreciation', 'allocation' => 'Allocation'];
$sourceLink = ['asset' => 'fin_asset_view.php?id=', 'allocation' => 'fin_allocation_view.php?id='];

$pageTitle = 'Pending Approval';
$finActive = 'pending';
$pageStyles = '
  .v-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .v-lines { font-size: .8rem; color: #6c757d; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_fixed_assets.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company.</div>
  <?php else: ?>

  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $show === 'pending' ? 'active' : '' ?>" href="?show=pending">Waiting for approval <span class="badge text-bg-warning"><?= $counts['pending'] ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?= $show === 'skipped' ? 'active' : '' ?>" href="?show=skipped">Skipped <span class="badge text-bg-secondary"><?= $counts['skipped'] ?></span></a></li>
  </ul>

  <form method="post" action="?show=<?= h($show) ?>" id="aForm">
    <?php csrf_field(); ?>
    <div class="card">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th style="width:2rem"><input type="checkbox" class="form-check-input" id="aAll" aria-label="Tick all"></th>
              <th>Date</th><th>Kind</th><th>Entry</th><th class="text-end">Amount (AED)</th>
            </tr>
          </thead>
          <tbody>
          <?php $total = 0.0; foreach ($rows as $r): $total += (float)$r['amount']; ?>
            <tr>
              <td><input type="checkbox" class="form-check-input a-pick" name="entry[]" value="<?= (int)$r['id'] ?>" aria-label="Tick"></td>
              <td class="text-nowrap"><?= h(date('d M Y', strtotime($r['entry_date']))) ?></td>
              <td><?= h($sourceLabel[$r['source_type']] ?? $r['source_type']) ?></td>
              <td>
                <?php if (isset($sourceLink[$r['source_type']])): ?><a href="<?= h($sourceLink[$r['source_type']] . (int)$r['source_id']) ?>"><?= h($r['narration']) ?></a><?php else: ?><?= h($r['narration']) ?><?php endif; ?>
                <div class="v-lines">
                  <?php foreach (json_decode($r['lines_json'], true) ?: [] as $l): ?>
                    <div><?= (float)$l['debit'] > 0 ? 'Dr' : 'Cr' ?> <?= h($accountNames[(int)$l['account_id']] ?? ('#' . (int)$l['account_id'])) ?></div>
                  <?php endforeach; ?>
                </div>
              </td>
              <td class="v-num"><?= number_format((float)$r['amount'], 2) ?></td>
            </tr>
          <?php endforeach; if (!$rows): ?>
            <tr><td colspan="5" class="text-muted text-center py-4"><?= $show === 'pending' ? 'Nothing waiting for approval.' : 'Nothing skipped.' ?></td></tr>
          <?php endif; ?>
          </tbody>
          <?php if ($rows): ?>
          <tfoot><tr class="fw-bold"><td colspan="4">Total (<?= count($rows) ?>)</td><td class="v-num"><?= number_format($total, 2) ?></td></tr></tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>
    <?php if ($rows): ?>
    <div class="d-flex flex-wrap gap-2 mt-3">
      <?php if ($show === 'pending'): ?>
      <button class="btn btn-success" name="action" value="approve" onclick="return confirm('Post the ticked entries to the ledger, each on its own date?');"><i class="bi bi-check2-circle"></i> Approve and post</button>
      <button class="btn btn-outline-secondary" name="action" value="skip" onclick="return confirm('Skip the ticked months? Nothing will be posted for them.');">Skip</button>
      <?php else: ?>
      <button class="btn btn-outline-primary" name="action" value="restore">Bring back to pending</button>
      <?php endif; ?>
      <span class="text-muted small align-self-center" id="aCount"></span>
    </div>
    <?php endif; ?>
  </form>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
<script>
(function () {
  const all = document.getElementById('aAll');
  if (!all) return;
  const picks = () => Array.from(document.querySelectorAll('.a-pick'));
  const count = () => { const n = picks().filter(p => p.checked).length; const c = document.getElementById('aCount'); if (c) c.textContent = n ? n + ' ticked' : ''; };
  all.addEventListener('change', () => { picks().forEach(p => { p.checked = all.checked; }); count(); });
  picks().forEach(p => p.addEventListener('change', count));
})();
</script>
</body>
</html>
