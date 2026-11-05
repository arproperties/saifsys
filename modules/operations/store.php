<?php
/**
 * Operations → Store → Stock: how many of each item are in each store now,
 * with low stock in red. The store keepers change it from the staff app
 * (Store); every change is in History. Quantities only.
 */
require_once __DIR__ . '/includes/ops_store_page.php';

$pageTitle = 'Store';
$q = (string)($_GET['q'] ?? '');
$lowOnly = !empty($_GET['low']);
$stores = $stReady ? st_stores($conn) : [];
$items = $stReady ? st_items($conn, $q) : [];

$isLow = static function (array $it, array $stores): bool {
    if ($it['min_qty'] === null) {
        return false;
    }
    foreach ($stores as $s) {
        if ((float)(((array)$it['stock'])[$s['id']] ?? 0) < $it['min_qty']) {
            return true;
        }
    }
    return false;
};
$lowCount = count(array_filter($items, static fn(array $it): bool => $isLow($it, $stores)));
if ($lowOnly) {
    $items = array_values(array_filter($items, static fn(array $it): bool => $isLow($it, $stores)));
}

require __DIR__ . '/includes/ops_layout_header.php';
?>

<div class="mb-3">
  <div class="page-header-label">Store</div>
  <div class="text-muted small">
    What is in each store now. The store keepers record goods in, goods out, moves and count corrections in the staff app (Store).
    Give someone the Store button in HR → employee → Mobile App PIN.
  </div>
</div>

<?= st_page_tabs($opsBase, 'store.php') ?>
<?php if (!$stReady): ?><?= st_not_ready_alert() ?><?php endif; ?>

<form method="get" class="card card-round mb-3">
  <div class="card-body row g-2 align-items-end">
    <div class="col-md-4">
      <label class="form-label small text-muted mb-1">Search</label>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Item name" class="form-control form-control-sm">
    </div>
    <div class="col-md-3">
      <div class="form-check mt-2">
        <input class="form-check-input" type="checkbox" name="low" value="1" id="st-low" <?= $lowOnly ? 'checked' : '' ?>>
        <label class="form-check-label small" for="st-low">Only low stock (<?= (int)$lowCount ?>)</label>
      </div>
    </div>
    <div class="col-md-2 ms-auto"><button class="btn btn-sm btn-primary w-100">Show</button></div>
  </div>
</form>

<div class="card card-round">
  <div class="table-responsive">
    <table class="table ops-table align-middle">
      <thead>
        <tr>
          <th>Item</th>
          <?php foreach ($stores as $s): ?><th class="text-end"><?= h($s['name']) ?></th><?php endforeach; ?>
          <th class="text-end">Total</th>
          <th class="text-end">Low below</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$items): ?>
          <tr><td colspan="<?= count($stores) + 3 ?>" class="text-center text-muted py-5">No items<?= $q !== '' || $lowOnly ? ' match' : ' yet. The store keeper adds them with Goods in, or add them in Stores & items' ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($items as $it): ?>
          <tr>
            <td class="fw-semibold">
              <a href="<?= h($opsBase) ?>/store_history.php?item=<?= (int)$it['id'] ?>&amp;from=<?= h(date('Y-m-d', strtotime('-90 days'))) ?>" class="text-decoration-none"><?= h($it['name']) ?></a>
              <?php if ($it['photo']): ?><a href="<?= h($opsBase) ?>/store_file.php?item=<?= (int)$it['id'] ?>" target="_blank" rel="noopener" class="ms-1 text-muted"><i class="bi bi-image"></i></a><?php endif; ?>
              <?php if ($it['unit']): ?><span class="small text-muted ms-1"><?= h($it['unit']) ?></span><?php endif; ?>
            </td>
            <?php foreach ($stores as $s): ?>
              <?php $have = (float)(((array)$it['stock'])[$s['id']] ?? 0); $low = $it['min_qty'] !== null && $have < $it['min_qty']; ?>
              <td class="num text-end <?= $low ? 'text-danger fw-bold' : ($have == 0 ? 'text-muted' : '') ?>"><?= h(st_qty($have)) ?></td>
            <?php endforeach; ?>
            <td class="num text-end fw-bold"><?= h(st_qty($it['total'])) ?></td>
            <td class="num text-end text-muted"><?= $it['min_qty'] !== null ? h(st_qty($it['min_qty'])) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/ops_layout_footer.php'; ?>
