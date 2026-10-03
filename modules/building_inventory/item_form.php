<?php
/**
 * Building Inventory — add or edit one item, see its changes, delete it.
 * The form only collects; Reem checks every field and keeps the item.
 *
 *   item_form.php?building=3              a new item in building 3
 *   item_form.php?building=3&place=a:12   ...with area 12 (or u:304 = unit 304) already picked
 *   item_form.php?building=3&id=77        edit item 77
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
$buildingId = (int)($_GET['building'] ?? 0);
$id = (int)($_GET['id'] ?? 0);
$backUrl = $binvBase . '/building.php?id=' . $buildingId;
$selfUrl = $binvBase . '/item_form.php?building=' . $buildingId . ($id ? '&id=' . $id : '');

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

$item = null;
if ($id > 0) {
    foreach ($inv['items'] ?? [] as $row) {
        if ((int)$row['id'] === $id) {
            $item = $row;
            break;
        }
    }
    if (!$item) {
        binv_flash('That item could not be found.', 'danger');
        header('Location: ' . $backUrl);
        exit;
    }
}

$errors = [];
// What the form shows: the saved item, or what was just typed if Reem refused it.
$form = [
    'place' => $item ? (string)($item['place']['key'] ?? '') : (string)($_GET['place'] ?? ''),
    'name' => $item['name'] ?? '',
    'counted_in' => $item['counted_in'] ?? '',
    'quantity' => $item ? binv_qty($item['quantity'] ?? 1) : '1',
    'condition' => $item['condition'] ?? 'good',
    'notes' => $item['notes'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A request bigger than post_max_size arrives with nothing in it at all — say so,
    // rather than letting it fail as a missing CSRF token.
    if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        binv_flash('That photo is bigger than the server allows. Nothing was saved.', 'danger');
        header('Location: ' . $selfUrl);
        exit;
    }
    csrf_verify();

    if (($_POST['action'] ?? '') === 'delete' && $item) {
        try {
            binv_reem($code, 'DELETE', '/' . $buildingId . '/items/' . $id);
            binv_flash('"' . $item['name'] . '" removed.');
        } catch (BinvError $e) {
            binv_flash($e->getMessage(), 'danger');
        }
        header('Location: ' . $backUrl);
        exit;
    }

    foreach (['place', 'name', 'counted_in', 'quantity', 'condition', 'notes'] as $k) {
        $form[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    }

    try {
        $saved = binv_reem($code, $item ? 'PUT' : 'POST', '/' . $buildingId . '/items' . ($item ? '/' . $id : ''), $form);
        $savedId = (int)$saved['id'];

        // The photo comes second, and its failure is said out loud: the item is
        // saved either way, and the person is told which half did not happen.
        $photoError = null;
        $file = $_FILES['photo'] ?? null;
        if (is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $photoError = binv_upload_error($file);
            if ($photoError === null) {
                try {
                    binv_send_photo($code, $buildingId, $savedId, (string)$file['tmp_name']);
                } catch (BinvError $e) {
                    $photoError = $e->getMessage();
                }
            }
        } elseif ($item && !empty($_POST['remove_photo'])) {
            try {
                binv_reem($code, 'DELETE', '/' . $buildingId . '/items/' . $savedId . '/photo');
            } catch (BinvError $e) {
                $photoError = $e->getMessage();
            }
        }

        if ($photoError !== null) {
            binv_flash('"' . $saved['name'] . '" was saved, but its photo was not: ' . $photoError, 'warning');
            header('Location: ' . $binvBase . '/item_form.php?building=' . $buildingId . '&id=' . $savedId);
            exit;
        }
        if ($item) {
            binv_flash('"' . $saved['name'] . '" saved.');
            header('Location: ' . $backUrl);
            exit;
        }
        // A new item: back to an empty form with the same place, ready for the next one.
        binv_flash('"' . $saved['name'] . '" added to ' . ($saved['place']['label'] ?? 'the building') . '. Add the next one, or go back to the building.');
        header('Location: ' . $binvBase . '/item_form.php?building=' . $buildingId . '&place=' . rawurlencode((string)($saved['place']['key'] ?? $form['place'])));
        exit;
    } catch (BinvError $e) {
        $errors[] = $e->getMessage();
    }
}

$areas = $inv['areas'] ?? [];
$units = $inv['units'] ?? [];
// What an item can be counted in, as Reem lists it: grouped for the dropdown.
$countedIn = [];
foreach ($inv['counted_in'] ?? [] as $c) {
    $countedIn[(string)($c['group'] ?? '')][(string)$c['value']] = (string)($c['label'] ?? $c['value']);
}
$conditions = $inv['conditions'] ?? ['good', 'damaged', 'missing'];
$photoUrl = $item && !empty($item['photo'])
    ? $binvBase . '/photo.php?building=' . $buildingId . '&id=' . $id . '&v=' . (int)($item['updated_at'] ?? 0)
    : null;

$history = [];
if ($item) {
    try {
        $history = binv_reem($code, 'GET', '/' . $buildingId . '/items/' . $id . '/history');
    } catch (BinvError $e) {
        $errors[] = 'The changes could not be read just now: ' . $e->getMessage();
    }
}

$pageTitle = $item ? 'Edit item' : 'Add item';
require __DIR__ . '/includes/binv_layout_header.php';
?>

<a href="<?= h($backUrl) ?>" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to <?= h($building['name']) ?></a>
<div class="page-header-label mt-2 mb-4"><?= $item ? 'Edit item' : 'Add item' ?></div>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<div class="card card-round mb-4" style="max-width:680px">
  <div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-3" action="<?= h($selfUrl) ?>">
      <?php csrf_field(); ?>

      <div class="col-12">
        <label class="form-label fw-semibold">Where is it?</label>
        <select name="place" class="form-select" data-search data-placeholder="Pick an area or a unit" required>
          <option value=""></option>
          <?php if ($areas): ?>
            <optgroup label="Areas">
              <?php foreach ($areas as $a): ?>
                <option value="a:<?= (int)$a['id'] ?>"<?= $form['place'] === 'a:' . $a['id'] ? ' selected' : '' ?>><?= h($a['name']) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
          <?php if ($units): ?>
            <optgroup label="Units">
              <?php foreach ($units as $u): ?>
                <option value="u:<?= h($u) ?>"<?= $form['place'] === 'u:' . $u ? ' selected' : '' ?>>Unit <?= h($u) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
        </select>
        <?php if (!empty($inv['units_error'])): ?>
          <div class="form-text text-danger">The unit list could not be read just now: <?= h($inv['units_error']) ?></div>
        <?php endif; ?>
        <div class="form-text">Not a unit? Add the area (lobby, store room, roof) on the building page first.</div>
      </div>

      <div class="col-12">
        <label class="form-label fw-semibold">What is it?</label>
        <input type="text" name="name" class="form-control form-control-lg" maxlength="<?= BINV_NAME_MAX ?>"
               value="<?= h($form['name']) ?>" placeholder="e.g. Split AC" required <?= $item ? '' : 'autofocus' ?>>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Counted in <span class="text-muted fw-normal small">(optional)</span></label>
        <select name="counted_in" class="form-select" data-search>
          <option value="">— not counted in any unit —</option>
          <?php foreach ($countedIn as $groupLabel => $codes): ?>
            <optgroup label="<?= h($groupLabel) ?>">
              <?php foreach ($codes as $value => $label): ?>
                <option value="<?= h($value) ?>"<?= (string)$form['counted_in'] === (string)$value ? ' selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">How many?</label>
        <input type="number" name="quantity" class="form-control" step="0.01" min="0" max="1000000"
               value="<?= h($form['quantity']) ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Condition</label>
        <select name="condition" class="form-select">
          <?php foreach ($conditions as $c): ?>
            <option value="<?= h($c) ?>"<?= $form['condition'] === $c ? ' selected' : '' ?>><?= h(ucfirst((string)$c)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12">
        <label class="form-label fw-semibold">Photo <span class="text-muted fw-normal small">(optional)</span></label>
        <?php if ($photoUrl): ?>
          <div class="d-flex align-items-center gap-3 mb-2">
            <a href="<?= h($photoUrl) ?>" target="_blank" rel="noopener">
              <img src="<?= h($photoUrl) ?>" alt=""
                   style="max-width:160px;max-height:160px;border-radius:10px;border:1px solid rgba(0,0,0,.15)">
            </a>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="remove_photo" value="1" id="binvRemovePhoto">
              <label class="form-check-label" for="binvRemovePhoto">Remove this photo</label>
            </div>
          </div>
        <?php endif; ?>
        <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/webp">
        <div class="form-text">JPG, PNG or WebP, 8 MB at most.<?= $photoUrl ? ' Choosing a new one replaces the photo above.' : '' ?></div>
      </div>

      <div class="col-12">
        <label class="form-label fw-semibold">Notes <span class="text-muted fw-normal small">(optional)</span></label>
        <textarea name="notes" class="form-control" rows="2" maxlength="<?= BINV_NOTES_MAX ?>"
                  placeholder="Brand, serial number, what is wrong with it"><?= h($form['notes']) ?></textarea>
      </div>

      <div class="col-12 d-flex gap-2">
        <button class="btn btn-lg text-white" style="background:var(--primary)">
          <i class="bi bi-check-lg"></i> <?= $item ? 'Save changes' : 'Add item' ?>
        </button>
        <a href="<?= h($backUrl) ?>" class="btn btn-lg btn-light"><?= $item ? 'Cancel' : 'Done' ?></a>
      </div>
    </form>
  </div>
</div>

<?php if ($item): ?>
  <div class="card card-round mb-4" style="max-width:680px">
    <div class="card-body pb-2"><div class="fw-bold">Changes</div></div>
    <div class="table-responsive">
      <table class="table binv-table align-middle">
        <thead><tr><th>What</th><th>Who</th><th>When</th></tr></thead>
        <tbody>
          <?php if (!$history): ?>
            <tr><td colspan="3" class="text-muted text-center py-4">No changes recorded.</td></tr>
          <?php endif; ?>
          <?php foreach ($history as $row): ?>
            <tr>
              <td><?= h($row['what']) ?></td>
              <td class="text-nowrap"><?= h($row['by'] ?? '') ?></td>
              <td class="small text-muted text-nowrap"><?= h(binv_when($row['at'] ?? 0)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <form method="post" action="<?= h($selfUrl) ?>" style="max-width:680px"
        onsubmit="return confirm('Delete this item? Its photo goes with it. This cannot be undone.');">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="delete">
    <button class="btn btn-outline-danger"><i class="bi bi-trash"></i> Delete item</button>
  </form>
<?php endif; ?>

<?php require __DIR__ . '/includes/binv_layout_footer.php'; ?>
