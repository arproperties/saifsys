<?php
/**
 * Operations → Store → one entry: what it moved, and the office's changes.
 * The store keeper can change his own only on the day he made it; after that
 * it is done here. A count cannot be changed, only deleted. ?edit=1 shows the form.
 */
require_once __DIR__ . '/includes/ops_store_page.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$self = $opsBase . '/store_entry.php?id=' . $id;
$list = $opsBase . '/store_history.php';

if (!$stReady) {
    ops_flash('The store tables are not set up yet. Run migrations/staff_store.sql.', 'danger');
    header('Location: ' . $opsBase . '/store.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $do = (string)($_POST['do'] ?? '');
    $back = $self;
    try {
        if ($do === 'save') {
            $current = st_get($conn, $stWho, $id);
            $lines = [];
            foreach ((array)($_POST['line_item'] ?? []) as $i => $itemId) {
                $qty = trim((string)($_POST['line_qty'][$i] ?? ''));
                if ((int)$itemId <= 0 && $qty === '') {
                    continue; // an empty row left in the form
                }
                $lines[] = ['item_id' => (int)$itemId, 'qty' => $qty];
            }
            $r = $current ? st_save($conn, $stWho, $id, [
                'kind' => $current['kind'],
                'store_id' => (int)($_POST['store_id'] ?? 0),
                'to_store_id' => (int)($_POST['to_store_id'] ?? 0),
                'given_to_employee_id' => (int)($_POST['given_to_employee_id'] ?? 0),
                'for_note' => $_POST['for_note'] ?? '',
                'source' => $_POST['source'] ?? '',
                'notes' => $_POST['notes'] ?? '',
                'lines' => $lines,
            ]) : null;
            ops_flash($r ? 'Entry saved. The stock was changed to match.' : 'That entry is not there any more.', $r ? 'success' : 'danger');
        } elseif ($do === 'delete') {
            $r = st_delete($conn, $stWho, $id);
            ops_flash($r ? 'Entry deleted. The stock went back to what it was before it.' : 'That entry is not there any more.', $r ? 'success' : 'danger');
            $back = $list;
        }
    } catch (StError $e) {
        ops_flash($e->getMessage(), 'danger');
        if ($do === 'save') {
            $back = $self . '&edit=1';
        }
    }
    header('Location: ' . $back);
    exit;
}

$m = st_get($conn, $stWho, $id);
if (!$m) {
    ops_flash('That entry is not there any more.', 'danger');
    header('Location: ' . $list);
    exit;
}
$editing = isset($_GET['edit']) && $m['kind'] !== 'count';
$pageTitle = 'Store entry #' . $id;
$stores = st_stores($conn, false);
$items = st_items($conn, '', false);
$people = $m['kind'] === 'out' ? st_people($conn) : [];
if ($m['kind'] === 'out' && $m['given_to'] && !in_array($m['given_to']['id'], array_column($people, 'id'), true)) {
    $people[] = ['id' => (int)$m['given_to']['id'], 'name' => $m['given_to']['name'], 'code' => '']; // someone who has since left
}

require __DIR__ . '/includes/ops_layout_header.php';
?>

<div class="d-flex flex-wrap align-items-start gap-2 mb-3">
  <div>
    <a href="<?= h($list) ?>" class="small text-muted text-decoration-none"><i class="bi bi-arrow-left"></i> Store history</a>
    <div class="page-header-label mt-1"><?= h(ST_KINDS[$m['kind']]) ?> <span class="text-muted fw-normal">#<?= (int)$m['id'] ?></span></div>
    <div class="text-muted small">
      <?= h($m['store']['name']) ?><?php if ($m['to_store']): ?> → <?= h($m['to_store']['name']) ?><?php endif; ?>
      · <?= h(date('d M Y H:i', strtotime($m['created_at']))) ?> by <?= h($m['by']['name']) ?>
      <?php if ($m['updated_by'] && $m['updated_at'] !== $m['created_at']): ?> · last changed <?= h(date('d M Y H:i', strtotime($m['updated_at']))) ?> by <?= h($m['updated_by']) ?><?php endif; ?>
    </div>
  </div>
  <?php if (!$editing): ?>
  <div class="ms-auto d-flex gap-2">
    <?php if ($m['kind'] !== 'count'): ?>
      <a href="<?= h($self) ?>&amp;edit=1" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Edit</a>
    <?php endif; ?>
    <form method="post" class="m-0" onsubmit="return confirm('Delete this entry? The stock goes back to what it was before it.');">
      <?php csrf_field(); ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="do" value="delete">
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete</button></form>
  </div>
  <?php endif; ?>
</div>

<?php if ($editing): ?>
<form method="post" class="card card-round mb-3">
  <div class="card-body">
    <?php csrf_field(); ?>
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="do" value="save">
    <div class="row g-2">
      <div class="col-md-4">
        <label class="form-label small text-muted mb-1"><?= $m['kind'] === 'move' ? 'From store' : 'Store' ?></label>
        <select name="store_id" class="form-select form-select-sm">
          <?php foreach ($stores as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $m['store']['id'] === $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php if ($m['kind'] === 'move'): ?>
      <div class="col-md-4">
        <label class="form-label small text-muted mb-1">To store</label>
        <select name="to_store_id" class="form-select form-select-sm">
          <?php foreach ($stores as $s): ?><option value="<?= (int)$s['id'] ?>" <?= ($m['to_store']['id'] ?? 0) === $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php elseif ($m['kind'] === 'out'): ?>
      <div class="col-md-4">
        <label class="form-label small text-muted mb-1">Taken by</label>
        <select name="given_to_employee_id" class="form-select form-select-sm">
          <?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>" <?= ($m['given_to']['id'] ?? 0) === $p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label small text-muted mb-1">For</label>
        <input name="for_note" maxlength="<?= ST_NOTE_MAX ?>" value="<?= h($m['for_note'] ?? '') ?>" class="form-control form-control-sm">
      </div>
      <?php else: ?>
      <div class="col-md-4">
        <label class="form-label small text-muted mb-1">From</label>
        <input name="source" maxlength="120" value="<?= h($m['source'] ?? '') ?>" class="form-control form-control-sm">
      </div>
      <?php endif; ?>
    </div>

    <table class="table table-sm align-middle mt-3 mb-2" id="st-lines">
      <thead><tr><th>Item</th><th style="width:160px">How many</th><th style="width:40px"></th></tr></thead>
      <tbody>
        <?php foreach (array_merge($m['lines'], [['item_id' => 0, 'qty' => '']]) as $l): ?>
        <tr>
          <td><select name="line_item[]" class="form-select form-select-sm"><option value="">—</option>
            <?php foreach ($items as $it): ?><option value="<?= (int)$it['id'] ?>" <?= $l['item_id'] === $it['id'] ? 'selected' : '' ?>><?= h($it['name'] . ($it['unit'] ? ' (' . $it['unit'] . ')' : '')) ?></option><?php endforeach; ?>
          </select></td>
          <td><input name="line_qty[]" inputmode="decimal" value="<?= $l['qty'] === '' ? '' : h(st_qty($l['qty'])) ?>" class="form-control form-control-sm text-end"></td>
          <td><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="this.closest('tr').remove()" title="Remove"><i class="bi bi-x-lg"></i></button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="st-add-line"><i class="bi bi-plus-lg me-1"></i>Add item</button>
    <div class="mt-3">
      <label class="form-label small text-muted mb-1">Note</label>
      <textarea name="notes" maxlength="<?= ST_NOTES_MAX ?>" rows="2" class="form-control form-control-sm"><?= h($m['notes'] ?? '') ?></textarea>
    </div>
    <div class="small text-muted mt-2">Saving changes the stock to match. It is refused if a store would go below 0.</div>
    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-sm btn-primary">Save</button>
      <a href="<?= h($self) ?>" class="btn btn-sm btn-outline-secondary">Cancel</a>
    </div>
  </div>
</form>
<script>
document.getElementById('st-add-line').addEventListener('click', function () {
  var body = document.querySelector('#st-lines tbody');
  var row = body.lastElementChild.cloneNode(true);
  row.querySelector('select').value = '';
  row.querySelector('input').value = '';
  body.appendChild(row);
});
</script>
<?php else: ?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card card-round">
      <div class="table-responsive">
        <table class="table ops-table align-middle mb-0">
          <thead><tr><th>Item</th><th class="text-end"><?= $m['kind'] === 'count' ? 'Counted (change)' : 'How many' ?></th></tr></thead>
          <tbody>
            <?php foreach ($m['lines'] as $l): ?>
              <tr>
                <td><?= h($l['name']) ?></td>
                <td class="num text-end text-nowrap fw-semibold">
                  <?= $m['kind'] === 'count' ? h(st_qty($l['counted'])) . ' (' . ($l['qty'] > 0 ? '+' : '') . h(st_qty($l['qty'])) . ')' : h(st_qty($l['qty'])) ?>
                  <?= $l['unit'] ? h($l['unit']) : '' ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card card-round mt-3"><div class="card-body small">
      <?php if ($m['given_to']): ?><div><span class="text-muted">Taken by:</span> <?= h($m['given_to']['name']) ?></div><?php endif; ?>
      <?php if ($m['for_note']): ?><div><span class="text-muted">For:</span> <?= h($m['for_note']) ?></div><?php endif; ?>
      <?php if ($m['source']): ?><div><span class="text-muted">From:</span> <?= h($m['source']) ?></div><?php endif; ?>
      <?php if ($m['reason']): ?><div><span class="text-muted">Why:</span> <?= h(ST_REASONS[$m['reason']] ?? $m['reason']) ?></div><?php endif; ?>
      <?php if ($m['notes']): ?><div><span class="text-muted">Note:</span> <?= nl2br(h($m['notes'])) ?></div><?php endif; ?>
      <?php if ($m['kind'] === 'count'): ?><div class="text-muted mt-2">A count cannot be changed. Delete it and the store keeper counts again.</div><?php endif; ?>
    </div></div>
  </div>
  <?php if ($m['photo']): ?>
  <div class="col-lg-5">
    <div class="card card-round"><div class="card-body">
      <div class="fw-semibold mb-2">Photo</div>
      <a href="<?= h($opsBase) ?>/store_file.php?move=<?= (int)$m['id'] ?>" target="_blank" rel="noopener">
        <img src="<?= h($opsBase) ?>/store_file.php?move=<?= (int)$m['id'] ?>" alt="" class="border rounded" style="max-width:100%;max-height:320px;object-fit:contain"></a>
    </div></div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/ops_layout_footer.php'; ?>
