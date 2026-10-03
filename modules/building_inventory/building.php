<?php
/**
 * Building Inventory — one building: its items grouped by place, areas first and
 * then units in natural order. Areas are added and removed here.
 * Everything shown is Reem's answer; everything changed is sent to Reem.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/binv_helper.php';

require_login(get_application_web_root() . '/login');
$binv = binv_boot($conn);
$code = $binv['code'];

$binvBase = get_application_web_root() . '/modules/building_inventory';
$buildingId = (int)($_GET['id'] ?? 0);
$self = $binvBase . '/building.php?id=' . $buildingId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add_area') {
            $area = binv_reem($code, 'POST', '/' . $buildingId . '/areas', ['name' => (string)($_POST['name'] ?? '')]);
            binv_flash('Area "' . ($area['name'] ?? '') . '" added.');
        } elseif ($action === 'delete_area') {
            $done = binv_reem($code, 'DELETE', '/' . $buildingId . '/areas/' . (int)($_POST['area_id'] ?? 0));
            $removed = (int)($done['removed'] ?? 0);
            binv_flash('Area removed' . ($removed > 0 ? ', with the ' . $removed . ' item' . ($removed === 1 ? '' : 's') . ' in it.' : '.'));
        }
    } catch (BinvError $e) {
        binv_flash($e->getMessage(), 'danger');
    }
    header('Location: ' . $self);
    exit;
}

try {
    $inv = binv_reem($code, 'GET', '/' . $buildingId);
} catch (BinvError $e) {
    if ($e->status === 404) {
        binv_flash('That building could not be found, or you do not keep its inventory.', 'danger');
        header('Location: ' . $binvBase . '/index.php?all=1');
        exit;
    }
    binv_stop($e);
}
$building = $inv['building'] ?? ['id' => $buildingId, 'name' => ''];
$areas = $inv['areas'] ?? [];
$items = $inv['items'] ?? [];

$counts = ['items' => count($items), 'damaged' => 0, 'missing' => 0];
foreach ($items as $it) {
    if (isset($counts[$it['condition'] ?? ''])) {
        $counts[$it['condition']]++;
    }
}

// Group the items by place. Every area shows, empty or not, so it can be filled or
// removed; a unit shows only once something is in it.
$groups = [];
foreach ($areas as $a) {
    $groups['a:' . $a['id']] = ['label' => (string)$a['name'], 'area_id' => (int)$a['id'], 'items' => []];
}
$unitGroups = [];
foreach ($items as $it) {
    $key = (string)($it['place']['key'] ?? '');
    if (isset($groups[$key])) {
        $groups[$key]['items'][] = $it;
        continue;
    }
    if (!isset($unitGroups[$key])) {
        $unitGroups[$key] = ['label' => (string)($it['place']['label'] ?? ''), 'area_id' => 0, 'items' => []];
    }
    $unitGroups[$key]['items'][] = $it;
}
uasort($unitGroups, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));
$groups += $unitGroups;

$pageTitle = $building['name'];
require __DIR__ . '/includes/binv_layout_header.php';
?>

<a href="<?= h($binvBase) ?>/index.php?all=1" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> All buildings</a>
<?php if (!empty($inv['units_error'])): ?>
  <div class="alert alert-warning mt-2 mb-0">The unit list could not be read just now (<?= h($inv['units_error']) ?>). Items already listed are shown; adding to a unit may have to wait.</div>
<?php endif; ?>
<div class="d-flex flex-wrap align-items-end gap-3 mt-2 mb-3">
  <div class="me-auto">
    <div class="page-header-label"><?= h($building['name']) ?></div>
    <div class="text-muted">
      <?= (int)$counts['items'] ?> item<?= $counts['items'] === 1 ? '' : 's' ?>
      <?php if ($counts['damaged'] > 0): ?> · <span class="text-warning-emphasis fw-semibold"><?= (int)$counts['damaged'] ?> damaged</span><?php endif; ?>
      <?php if ($counts['missing'] > 0): ?> · <span class="text-danger fw-semibold"><?= (int)$counts['missing'] ?> missing</span><?php endif; ?>
    </div>
  </div>
  <a href="<?= h($binvBase) ?>/item_form.php?building=<?= $buildingId ?>" class="btn btn-lg text-white" style="background:var(--primary)">
    <i class="bi bi-plus-lg"></i> Add item
  </a>
</div>

<div class="row g-2 mb-4">
  <div class="col-12 col-lg-7">
    <div class="input-group">
      <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
      <input type="search" id="binvSearch" class="form-control" placeholder="Search item, unit, area or condition" autocomplete="off">
    </div>
  </div>
  <div class="col-12 col-lg-5">
    <form method="post" class="input-group">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="add_area">
      <input type="text" name="name" class="form-control" maxlength="<?= BINV_NAME_MAX ?>" placeholder="New area, e.g. Lobby, Store room, Roof" required>
      <button class="btn btn-outline-secondary"><i class="bi bi-plus-lg"></i> Add area</button>
    </form>
  </div>
</div>

<?php if (!$groups): ?>
  <div class="card card-round"><div class="card-body text-center text-muted py-5">
    Nothing here yet. <a href="<?= h($binvBase) ?>/item_form.php?building=<?= $buildingId ?>">Add the first item</a> — for example "Split AC" in Unit 101.
  </div></div>
<?php endif; ?>

<div id="binvNoMatch" class="text-center text-muted py-5 d-none">Nothing matches that search.</div>

<?php foreach ($groups as $key => $g): ?>
  <div class="card card-round mb-3 binv-group" data-place="<?= h(mb_strtolower($g['label'])) ?>">
    <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2 border-bottom">
      <i class="bi <?= $g['area_id'] ? 'bi-geo-alt' : 'bi-door-closed' ?>" style="color:var(--primary)"></i>
      <span class="fw-bold"><?= h($g['label']) ?></span>
      <span class="badge bg-light text-dark border"><?= count($g['items']) ?></span>
      <span class="ms-auto d-flex gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?= h($binvBase) ?>/item_form.php?building=<?= $buildingId ?>&amp;place=<?= h(rawurlencode((string)$key)) ?>">
          <i class="bi bi-plus-lg"></i> Add here
        </a>
        <?php if ($g['area_id']): ?>
          <form method="post" class="d-inline"
                onsubmit="return confirm(<?= h(json_encode('Remove the area "' . $g['label'] . '"'
                    . (count($g['items']) ? ' and the ' . count($g['items']) . ' item(s) in it' : '') . '? This cannot be undone.')) ?>);">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="delete_area">
            <input type="hidden" name="area_id" value="<?= (int)$g['area_id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Remove area</button>
          </form>
        <?php endif; ?>
      </span>
    </div>
    <?php if (!$g['items']): ?>
      <div class="card-body text-muted small py-3 binv-empty">No items in this area yet.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table binv-table align-middle">
        <thead>
          <tr><th style="width:60px"></th><th>Item</th><th>How many</th><th>Condition</th><th>Last change</th></tr>
        </thead>
        <tbody>
          <?php foreach ($g['items'] as $it): ?>
            <?php
            $edit = $binvBase . '/item_form.php?building=' . $buildingId . '&id=' . (int)$it['id'];
            $photo = $binvBase . '/photo.php?building=' . $buildingId . '&id=' . (int)$it['id'] . '&v=' . (int)($it['updated_at'] ?? 0);
            $condition = (string)($it['condition'] ?? '');
            $search = mb_strtolower($it['name'] . ' ' . $g['label'] . ' ' . $condition . ' ' . ($it['notes'] ?? ''));
            ?>
            <tr class="binv-row" data-search="<?= h($search) ?>">
              <td>
                <?php if (!empty($it['photo'])): ?>
                  <a href="<?= h($photo) ?>" target="_blank" rel="noopener">
                    <img class="binv-thumb" loading="lazy" alt="" src="<?= h($photo) ?>">
                  </a>
                <?php else: ?>
                  <span class="binv-thumb-empty"><i class="bi bi-image"></i></span>
                <?php endif; ?>
              </td>
              <td>
                <a class="fw-semibold text-decoration-none" href="<?= h($edit) ?>"><?= h($it['name']) ?></a>
                <?php if (!empty($it['notes'])): ?>
                  <div class="small text-muted"><?= h($it['notes']) ?></div>
                <?php endif; ?>
              </td>
              <td class="num"><?= h(binv_qty_label($it['quantity'] ?? 0, $it['counted_in'] ?? null)) ?></td>
              <td><span class="badge text-bg-<?= h(binv_condition_color($condition)) ?>"><?= h(ucfirst($condition)) ?></span></td>
              <td class="small text-muted">
                <?= h($it['updated_by'] ?? '') ?><br><?= h(binv_when($it['updated_at'] ?? 0)) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php
ob_start();
?>
<script>
  // Search as you type: an item shows when every word is somewhere in its name,
  // place, condition or notes. A place with nothing left to show is hidden too.
  (function () {
    var box = document.getElementById('binvSearch');
    var none = document.getElementById('binvNoMatch');
    if (!box) { return; }
    box.addEventListener('input', function () {
      var words = this.value.toLowerCase().split(/\s+/).filter(Boolean);
      var anyShown = false;
      document.querySelectorAll('.binv-group').forEach(function (group) {
        var shown = 0;
        group.querySelectorAll('.binv-row').forEach(function (row) {
          var text = row.getAttribute('data-search');
          var hit = words.every(function (w) { return text.indexOf(w) !== -1; });
          row.classList.toggle('d-none', !hit);
          if (hit) { shown++; }
        });
        // An empty area still answers to its own name.
        var placeHit = words.length && words.every(function (w) { return group.getAttribute('data-place').indexOf(w) !== -1; });
        var visible = !words.length || shown > 0 || placeHit;
        group.classList.toggle('d-none', !visible);
        if (visible) { anyShown = true; }
      });
      none.classList.toggle('d-none', anyShown || !words.length);
    });
  })();
</script>
<?php
$pageScripts = ob_get_clean();
require __DIR__ . '/includes/binv_layout_footer.php';
