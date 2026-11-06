<?php
/**
 * Operations → Store → Stores & items: the list of stores (add, rename,
 * close) and the one item list they share (name, unit, low-stock level,
 * photo). Store keepers also add items from the phone with Goods in.
 */
require_once __DIR__ . '/includes/ops_store_page.php';

$self = $opsBase . '/store_setup.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $stReady) {
    csrf_verify();
    $do = (string)($_POST['do'] ?? '');
    try {
        if ($do === 'store') {
            st_save_store($conn, (int)($_POST['store_id'] ?? 0), (string)($_POST['name'] ?? ''), ($_POST['active'] ?? '1') === '1');
            ops_flash('Store saved.');
        } elseif ($do === 'item') {
            $itemId = st_save_item($conn, $stWho, (int)($_POST['item_id'] ?? 0), [
                'name' => $_POST['name'] ?? '',
                'unit' => $_POST['unit'] ?? '',
                'min_qty' => $_POST['min_qty'] ?? '',
                'active' => ($_POST['active'] ?? '1') === '1',
            ]);
            $photo = st_uploaded_file('photo');
            if ($photo !== null) {
                st_set_item_photo($conn, $itemId, $photo);
            }
            ops_flash('Item saved.');
        }
    } catch (StError $e) {
        ops_flash($e->getMessage(), 'danger');
    }
    header('Location: ' . $self . (isset($_POST['item_id']) ? '#items' : ''));
    exit;
}

$pageTitle = 'Stores & items';
$stores = $stReady ? st_stores($conn, false) : [];
$items = $stReady ? st_items($conn, '', false) : [];

require __DIR__ . '/includes/ops_layout_header.php';
?>

<div class="mb-3">
  <div class="page-header-label">Store</div>
  <div class="text-muted small">The stores, and the one item list they all share. A store with stock in it cannot be closed.</div>
</div>

<?= st_page_tabs($opsBase, 'store_setup.php') ?>
<?php if (!$stReady): ?><?= st_not_ready_alert() ?><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card card-round"><div class="card-body">
      <div class="fw-semibold mb-2">Stores</div>
      <?php foreach ($stores as $s): ?>
        <form method="post" class="d-flex gap-2 mb-2">
          <?php csrf_field(); ?><input type="hidden" name="do" value="store"><input type="hidden" name="store_id" value="<?= (int)$s['id'] ?>">
          <input name="name" value="<?= h($s['name']) ?>" maxlength="<?= ST_STORE_MAX ?>" class="form-control form-control-sm <?= $s['active'] ? '' : 'text-muted' ?>" required>
          <select name="active" class="form-select form-select-sm" style="width:105px"><option value="1" <?= $s['active'] ? 'selected' : '' ?>>Open</option><option value="0" <?= $s['active'] ? '' : 'selected' ?>>Closed</option></select>
          <button class="btn btn-sm btn-outline-primary">Save</button>
        </form>
      <?php endforeach; ?>
      <form method="post" class="d-flex gap-2 mt-3">
        <?php csrf_field(); ?><input type="hidden" name="do" value="store">
        <input name="name" maxlength="<?= ST_STORE_MAX ?>" placeholder="New store, e.g. Camp store" class="form-control form-control-sm" required>
        <button class="btn btn-sm btn-primary text-nowrap">Add store</button>
      </form>
    </div></div>
  </div>
  <div class="col-lg-8" id="items">
    <div class="card card-round"><div class="card-body">
      <div class="fw-semibold mb-2">Items</div>
      <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end mb-3">
        <?php csrf_field(); ?><input type="hidden" name="do" value="item"><input type="hidden" name="item_id" value="0">
        <div class="col-md-4"><label class="form-label small text-muted mb-1">New item</label><input name="name" maxlength="<?= ST_NAME_MAX ?>" class="form-control form-control-sm" required></div>
        <div class="col-md-2"><label class="form-label small text-muted mb-1">Unit</label><input name="unit" maxlength="<?= ST_UNIT_MAX ?>" placeholder="pcs" class="form-control form-control-sm"></div>
        <div class="col-md-2"><label class="form-label small text-muted mb-1">Low below</label><input name="min_qty" inputmode="decimal" class="form-control form-control-sm"></div>
        <div class="col-md-2"><label class="form-label small text-muted mb-1">Photo</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Add item</button></div>
      </form>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th></th><th>Name</th><th style="width:90px">Unit</th><th style="width:100px">Low below</th><th style="width:105px"></th><th>Photo</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($items as $it): ?>
              <?php $formId = 'st-item-' . (int)$it['id']; ?>
              <tr class="<?= $it['active'] ? '' : 'text-muted' ?>">
                <td style="width:44px"><?php if ($it['photo']): ?><a href="<?= h($opsBase) ?>/store_file.php?item=<?= (int)$it['id'] ?>" target="_blank" rel="noopener"><img src="<?= h($opsBase) ?>/store_file.php?item=<?= (int)$it['id'] ?>" alt="" class="rounded" style="width:36px;height:36px;object-fit:cover"></a><?php endif; ?></td>
                <td><input form="<?= h($formId) ?>" name="name" value="<?= h($it['name']) ?>" maxlength="<?= ST_NAME_MAX ?>" class="form-control form-control-sm" required></td>
                <td><input form="<?= h($formId) ?>" name="unit" value="<?= h($it['unit'] ?? '') ?>" maxlength="<?= ST_UNIT_MAX ?>" class="form-control form-control-sm"></td>
                <td><input form="<?= h($formId) ?>" name="min_qty" value="<?= $it['min_qty'] !== null ? h(st_qty($it['min_qty'])) : '' ?>" inputmode="decimal" class="form-control form-control-sm"></td>
                <td><select form="<?= h($formId) ?>" name="active" class="form-select form-select-sm"><option value="1" <?= $it['active'] ? 'selected' : '' ?>>In use</option><option value="0" <?= $it['active'] ? '' : 'selected' ?>>Hidden</option></select></td>
                <td><input form="<?= h($formId) ?>" type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm" style="max-width:190px"></td>
                <td>
                  <form method="post" enctype="multipart/form-data" id="<?= h($formId) ?>" class="m-0">
                    <?php csrf_field(); ?><input type="hidden" name="do" value="item"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                    <button class="btn btn-sm btn-outline-primary">Save</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?><tr><td colspan="7" class="text-muted small py-3">No items yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div></div>
  </div>
</div>

<?php require __DIR__ . '/includes/ops_layout_footer.php'; ?>
