<?php
/**
 * Operations — one purchase: what was bought, the photos, and the office's
 * changes. The buyer can change his own only on the day he made it; after that
 * it is done here. ?edit=1 shows the form. Nothing is deleted: Owner / Admin /
 * HR inactivate (with a reason) and activate again; others cannot open an
 * inactive one.
 * Rules: includes/ops_purchases.php.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/ops_helper.php';
require_once __DIR__ . '/includes/ops_purchases.php';

require_login(get_application_web_root() . '/login');
ops_require_access($conn);

$appBase = get_application_web_root();
$opsBase = $appBase . '/modules/operations';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$self = $opsBase . '/purchase_view.php?id=' . $id;
$list = $opsBase . '/purchases.php';

if (!pur_tables_ready($conn)) {
    ops_flash('The purchase tables are not set up yet. Run migrations/staff_purchases.sql.', 'danger');
    header('Location: ' . $list);
    exit;
}

$U = !empty($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : [];
$who = [
    'user_id' => (int)current_user_id(),
    'name' => (string)($U['full_name'] ?? $U['fullname'] ?? $_SESSION['fullname'] ?? $U['username'] ?? 'Office'),
    'office' => true,
];
$canInactive = ops_can_inactivate($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $do = (string)($_POST['do'] ?? '');
    $back = $self;
    try {
        if ($do === 'save') {
            $lines = [];
            foreach ((array)($_POST['line_name'] ?? []) as $i => $name) {
                $qty = $_POST['line_qty'][$i] ?? '';
                $price = $_POST['line_price'][$i] ?? '';
                if (trim((string)$name) === '' && trim((string)$qty) === '' && trim((string)$price) === '') {
                    continue; // an empty row left in the form
                }
                $lines[] = ['name' => $name, 'qty' => $qty, 'price' => $price, 'unit' => $_POST['line_unit'][$i] ?? '', 'note' => $_POST['line_note'][$i] ?? ''];
            }
            $r = pur_save($conn, $who, $id, [
                'kind' => $_POST['kind'] ?? '',
                'supplier' => $_POST['supplier'] ?? '',
                'bought_on' => $_POST['bought_on'] ?? '',
                'notes' => $_POST['notes'] ?? '',
                'lines' => $lines,
            ]);
            ops_flash($r ? 'Purchase saved.' : 'That purchase is not there any more.', $r ? 'success' : 'danger');
        } elseif (($do === 'inactivate' || $do === 'activate') && $canInactive) {
            $r = pur_set_active($conn, $who, $id, $do === 'activate', (string)($_POST['reason'] ?? ''));
            ops_flash($r ? ($do === 'activate' ? 'Purchase is active again. The buyer sees it.' : 'Purchase is inactive. The buyer no longer sees it.') : 'That purchase is not there any more.', $r ? 'success' : 'danger');
        } elseif ($do === 'received') {
            pur_mark_received($conn, $who, $id, pur_uploaded_files('photos'));
            ops_flash('Marked as received.');
        } elseif ($do === 'not_received') {
            pur_mark_not_received($conn, $who, $id);
            ops_flash('Back to waiting to arrive.');
        } elseif ($do === 'add_photo') {
            pur_add_files($conn, $who, $id, (string)($_POST['kind'] ?? ''), pur_uploaded_files('photos'));
            ops_flash('Photo added.');
        } elseif ($do === 'remove_photo') {
            pur_remove_file($conn, $who, $id, (int)($_POST['file_id'] ?? 0));
            ops_flash('Photo deleted.');
        }
    } catch (PurError $e) {
        ops_flash($e->getMessage(), 'danger');
        if ($do === 'save') {
            $back = $self . '&edit=1';
        }
    }
    header('Location: ' . $back);
    exit;
}

$p = pur_get($conn, $who, $id);
if (!$p || (!$p['active'] && !$canInactive)) {
    ops_flash('That purchase is not there any more.', 'danger');
    header('Location: ' . $list);
    exit;
}
$editing = isset($_GET['edit']);
$pageTitle = 'Purchase #' . $id;
$money = static fn($n): string => 'AED ' . number_format((float)$n, 2);
$suppliers = pur_list_suppliers($conn);

require __DIR__ . '/includes/ops_layout_header.php';
?>

<div class="d-flex flex-wrap align-items-start gap-2 mb-3">
  <div>
    <a href="<?= h($list . ($p['active'] ? '' : '?tab=inactive')) ?>" class="small text-muted text-decoration-none"><i class="bi bi-arrow-left"></i> Purchases</a>
    <div class="page-header-label mt-1"><?= h($p['supplier']['name']) ?> <span class="text-muted fw-normal">#<?= (int)$p['id'] ?></span></div>
    <div class="text-muted small">
      <?= h($p['kind'] === 'cash' ? PUR_KINDS['cash'] : PUR_KINDS['order'] . ' · ' . PUR_STATUSES[$p['status']]) ?> · bought <?= h(date('d M Y', strtotime($p['bought_on']))) ?> by <?= h($p['by']['name']) ?>
      <?php if ($p['received_on']): ?> · received <?= h(date('d M Y', strtotime($p['received_on']))) ?><?php endif; ?>
    </div>
  </div>
  <?php if (!$editing && !$p['active'] && $canInactive): ?>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <form method="post" class="m-0" onsubmit="return confirm('Make this purchase active again? The buyer will see it.');"><?php csrf_field(); ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="do" value="activate">
      <button class="btn btn-sm btn-success"><i class="bi bi-arrow-counterclockwise me-1"></i>Activate</button></form>
  </div>
  <?php elseif (!$editing && $p['active']): ?>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <?php if ($p['status'] === 'ordered'): ?>
      <form method="post" class="m-0"><?php csrf_field(); ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="do" value="received">
        <button class="btn btn-sm btn-success"><i class="bi bi-box-seam me-1"></i>Mark received</button></form>
    <?php elseif ($p['status'] === 'received'): ?>
      <form method="post" class="m-0" onsubmit="return confirm('Put this order back to waiting to arrive?');"><?php csrf_field(); ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="do" value="not_received">
        <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1"></i>Not received</button></form>
    <?php endif; ?>
    <a href="<?= h($self) ?>&amp;edit=1" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Edit</a>
    <?php if ($canInactive): ?>
      <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#pur-inactivate"><i class="bi bi-slash-circle me-1"></i>Inactivate</button>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if (!$editing && $p['active'] && $canInactive): ?>
<form method="post" id="pur-inactivate" class="collapse card card-round border-danger-subtle mb-3">
  <div class="card-body d-flex flex-wrap gap-2 align-items-end">
    <?php csrf_field(); ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="do" value="inactivate">
    <div class="flex-grow-1">
      <label class="form-label small text-muted mb-1">Why is it being made inactive?</label>
      <input name="reason" maxlength="<?= PUR_REASON_MAX ?>" class="form-control form-control-sm" placeholder="e.g. Duplicate entry" required>
    </div>
    <button class="btn btn-sm btn-danger">Inactivate</button>
  </div>
  <div class="card-footer small text-muted">The buyer no longer sees it. It stays here, in the Inactive tab, with its photos.</div>
</form>
<?php endif; ?>

<?php if (!$p['active']): ?>
<div class="alert alert-warning d-flex gap-2">
  <i class="bi bi-exclamation-triangle"></i>
  <div>
    <strong>Inactive</strong> — by <?= h($p['inactive']['by']) ?> on <?= h(date('d M Y, H:i', strtotime($p['inactive']['at']))) ?>
    <div>Reason: <?= h($p['inactive']['reason']) ?></div>
    <div class="small text-muted">The buyer does not see it. Activate it to change it.</div>
  </div>
</div>
<?php endif; ?>

<div class="alert alert-light border small py-2">
  <i class="bi bi-info-circle me-1"></i>Added by <strong><?= h($p['by']['name']) ?></strong> on <?= h(date('d M Y, H:i', strtotime($p['created_at']))) ?> (staff app)
  <?php if ($p['updated_by'] && $p['updated_at'] !== $p['created_at']): ?><div class="ms-4">Last changed by <?= h($p['updated_by']) ?> on <?= h(date('d M Y, H:i', strtotime($p['updated_at']))) ?></div><?php endif; ?>
</div>

<?php if ($editing): ?>
<form method="post" class="card card-round mb-3">
  <div class="card-body">
    <?php csrf_field(); ?>
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="do" value="save">
    <div class="row g-2">
      <div class="col-md-3">
        <label class="form-label small text-muted mb-1">How</label>
        <select name="kind" class="form-select form-select-sm">
          <?php foreach (PUR_KINDS as $k => $label): ?>
            <option value="<?= h($k) ?>" <?= $p['kind'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label small text-muted mb-1">Shop / supplier</label>
        <input name="supplier" list="pur-suppliers" maxlength="<?= PUR_NAME_MAX ?>" value="<?= h($p['supplier']['name']) ?>" class="form-control form-control-sm" required>
        <datalist id="pur-suppliers"><?php foreach ($suppliers as $s): ?><option value="<?= h($s['name']) ?>"><?php endforeach; ?></datalist>
      </div>
      <div class="col-md-4">
        <label class="form-label small text-muted mb-1">Date bought</label>
        <input type="date" name="bought_on" value="<?= h($p['bought_on']) ?>" max="<?= h(date('Y-m-d')) ?>" class="form-control form-control-sm">
      </div>
    </div>

    <table class="table table-sm align-middle mt-3 mb-2" id="pur-lines">
      <thead><tr><th>Item</th><th style="width:110px">Qty</th><th style="width:100px">Unit</th><th style="width:140px">Price for one</th><th style="width:200px">Note</th><th style="width:40px"></th></tr></thead>
      <tbody>
        <?php foreach (array_merge($p['lines'], [['name' => '', 'qty' => '', 'unit' => '', 'price' => '', 'note' => '']]) as $l): ?>
        <tr>
          <td><input name="line_name[]" maxlength="<?= PUR_LINE_MAX ?>" value="<?= h($l['name']) ?>" class="form-control form-control-sm"></td>
          <td><input name="line_qty[]" inputmode="decimal" value="<?= $l['qty'] === '' ? '' : h(pur_qty($l['qty'])) ?>" class="form-control form-control-sm text-end"></td>
          <td><input name="line_unit[]" maxlength="<?= PUR_UNIT_MAX ?>" value="<?= h($l['unit'] ?? '') ?>" class="form-control form-control-sm"></td>
          <td><input name="line_price[]" inputmode="decimal" value="<?= $l['price'] === '' ? '' : h(number_format((float)$l['price'], 2, '.', '')) ?>" class="form-control form-control-sm text-end"></td>
          <td><input name="line_note[]" maxlength="<?= PUR_NOTE_MAX ?>" value="<?= h($l['note'] ?? '') ?>" class="form-control form-control-sm"></td>
          <td><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="this.closest('tr').remove()" title="Remove"><i class="bi bi-x-lg"></i></button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="pur-add-line"><i class="bi bi-plus-lg me-1"></i>Add item</button>

    <div class="mt-3">
      <label class="form-label small text-muted mb-1">Note</label>
      <textarea name="notes" maxlength="<?= PUR_NOTES_MAX ?>" rows="2" class="form-control form-control-sm"><?= h($p['notes'] ?? '') ?></textarea>
    </div>
    <div class="small text-muted mt-2">Changing paid cash to an order (or back) starts its status over. The total is worked out from the items.</div>
    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-sm btn-primary">Save</button>
      <a href="<?= h($self) ?>" class="btn btn-sm btn-outline-secondary">Cancel</a>
    </div>
  </div>
</form>
<script>
document.getElementById('pur-add-line').addEventListener('click', function () {
  var body = document.querySelector('#pur-lines tbody');
  var row = body.lastElementChild.cloneNode(true);
  row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
  body.appendChild(row);
  row.querySelector('input').focus();
});
</script>
<?php else: ?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card card-round">
      <div class="table-responsive">
        <table class="table ops-table align-middle mb-0">
          <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Price for one</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
            <?php foreach ($p['lines'] as $l): ?>
              <tr>
                <td><?= h($l['name']) ?><?php if ($l['note']): ?><div class="small text-muted"><?= h($l['note']) ?></div><?php endif; ?></td>
                <td class="num text-end text-nowrap"><?= h(pur_qty($l['qty']) . ($l['unit'] ? ' ' . $l['unit'] : '')) ?></td>
                <td class="num text-end text-nowrap"><?= h($money($l['price'])) ?></td>
                <td class="num text-end text-nowrap fw-semibold"><?= h($money($l['amount'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th colspan="3" class="text-end">Total</th><th class="num text-end text-nowrap"><?= h($money($p['total'])) ?></th></tr></tfoot>
        </table>
      </div>
    </div>
    <?php if ($p['outsource']): ?>
      <div class="card card-round mt-3"><div class="card-body"><span class="badge bg-info-subtle text-info-emphasis">For outsource</span><?php if ($p['outsource']['note'] !== ''): ?> <?= h($p['outsource']['note']) ?><?php endif; ?></div></div>
    <?php endif; ?>
    <?php if ($p['notes']): ?>
      <div class="card card-round mt-3"><div class="card-body"><div class="small text-muted">Note</div><?= nl2br(h($p['notes'])) ?></div></div>
    <?php endif; ?>
  </div>
  <div class="col-lg-5">
    <?php foreach (PUR_FILE_KINDS as $kind => $label): ?>
      <?php $files = array_values(array_filter($p['files'], static fn(array $f): bool => $f['kind'] === $kind)); ?>
      <div class="card card-round mb-3">
        <div class="card-body">
          <div class="fw-semibold mb-2"><?= h($label) ?></div>
          <?php if (!$files): ?><div class="small text-muted">No photo.</div><?php endif; ?>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach ($files as $fl): ?>
              <?php $url = $opsBase . '/purchase_file.php?id=' . (int)$p['id'] . '&file=' . (int)$fl['id']; ?>
              <div class="position-relative">
                <a href="<?= h($url) ?>" target="_blank" rel="noopener">
                  <?php if ($fl['mime'] === 'application/pdf'): ?>
                    <span class="d-grid border rounded bg-light" style="width:110px;height:110px;place-items:center"><i class="bi bi-file-earmark-pdf fs-2 text-danger"></i></span>
                  <?php else: ?>
                    <img src="<?= h($url) ?>" alt="" class="border rounded" style="width:110px;height:110px;object-fit:cover">
                  <?php endif; ?>
                </a>
                <form method="post" class="position-absolute top-0 end-0 m-1" onsubmit="return confirm('Delete this photo?');">
                  <?php csrf_field(); ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="do" value="remove_photo"><input type="hidden" name="file_id" value="<?= (int)$fl['id'] ?>">
                  <button class="btn btn-sm btn-light py-0 px-1" title="Delete"><i class="bi bi-x-lg"></i></button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
          <form method="post" enctype="multipart/form-data" class="d-flex gap-2 mt-3">
            <?php csrf_field(); ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="do" value="add_photo"><input type="hidden" name="kind" value="<?= h($kind) ?>">
            <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp,application/pdf" multiple class="form-control form-control-sm" required>
            <button class="btn btn-sm btn-outline-primary text-nowrap">Add</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/ops_layout_footer.php'; ?>
