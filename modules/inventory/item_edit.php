<?php
/**
 * Inventory item edit: Phase 4 fields + images (original + thumbnail).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/inventory/inv_item_images.php';
require_once __DIR__ . '/../../includes/inventory/inv_item_pos_location_settings.php';

require_login();
require_module_access($conn, MODULE_INVENTORY);
require_department_access(MODULE_INVENTORY, DEPT_INVENTORY, $conn);
require_permission('inventory_items.view', MODULE_INVENTORY, $conn);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;
$itemId = (int)($_GET['id'] ?? 0);
$message = $messageType = '';

if (!$itemId || !$companyId) {
    header('Location: items.php');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM inv_items WHERE id = ? AND company_id = ? LIMIT 1");
$stmt->execute([$itemId, $companyId]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$item) {
    header('Location: items.php');
    exit;
}

$imgStmt = $conn->prepare("SELECT * FROM inv_item_images WHERE company_id = ? AND item_id = ? ORDER BY is_primary DESC, id ASC");
$imgStmt->execute([$companyId, $itemId]);
$images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);

$uoms = [];
try {
    $uoms = $conn->query("SELECT id, code, name FROM inv_uoms WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$categories = [];
$stmt = $conn->prepare("SELECT id, name FROM inv_item_categories WHERE company_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$companyId]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$posLocations = [];
$plsByLoc = [];
try {
    $locStmt = $conn->prepare("SELECT id, code, name FROM inv_locations WHERE company_id = ? AND is_active = 1 ORDER BY name");
    $locStmt->execute([$companyId]);
    $posLocations = $locStmt->fetchAll(PDO::FETCH_ASSOC);
    $plsStmt = $conn->prepare("SELECT * FROM inv_item_pos_location_settings WHERE company_id = ? AND item_id = ?");
    $plsStmt->execute([$companyId, $itemId]);
    foreach ($plsStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $plsByLoc[(int)$s['location_id']] = $s;
    }
} catch (Throwable $e) {
    $posLocations = [];
    $plsByLoc = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = $_POST['action'] ?? '';

    if ($act === 'save' && has_permission('inventory_items.edit', MODULE_INVENTORY, $conn)) {
        $name = trim($_POST['name'] ?? '');
        $barcode = trim($_POST['barcode'] ?? '');
        $itemType = trim($_POST['item_type'] ?? 'stock_item');
        $baseUom = (int)($_POST['base_uom_id'] ?? 0);
        $salePrice = (float)($_POST['sale_price'] ?? 0);
        $vatRate = (float)($_POST['vat_rate'] ?? 0);
        $purchasePrice = (float)($_POST['default_purchase_price'] ?? 0);
        $reorder = (float)($_POST['reorder_level'] ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $trackLot = !empty($_POST['track_lot']) ? 1 : 0;
        $trackExpiry = !empty($_POST['track_expiry']) ? 1 : 0;
        $trackSerial = !empty($_POST['track_serial']) ? 1 : 0;
        $isSellable = !empty($_POST['is_sellable']) ? 1 : 0;
        $isPurchasable = !empty($_POST['is_purchasable']) ? 1 : 0;
        $isConsumable = !empty($_POST['is_consumable']) ? 1 : 0;
        $isService = !empty($_POST['is_service']) ? 1 : 0;
        $isFavorite = !empty($_POST['is_favorite']) ? 1 : 0;
        $favoriteSortOrder = (int)($_POST['favorite_sort_order'] ?? 0);
        $isOffer = !empty($_POST['is_offer']) ? 1 : 0;
        $offerPrice = isset($_POST['offer_price']) ? (float)$_POST['offer_price'] : 0;
        $offerStart = trim((string)($_POST['offer_start'] ?? ''));
        $offerEnd = trim((string)($_POST['offer_end'] ?? ''));
        $offerNote = trim((string)($_POST['offer_note'] ?? ''));
        $offerBadge = strtoupper(trim((string)($_POST['offer_badge'] ?? '')));
        if (!in_array($offerBadge, ['', 'OFFER', 'DISCOUNT', 'EXPIRY_SOON'], true)) {
            $offerBadge = '';
        }
        if ($offerBadge === '') {
            $offerBadge = null;
        }

        if ($name === '' || !$baseUom) {
            $message = 'Name and base UoM are required.';
            $messageType = 'warning';
        } elseif ($isOffer && ($offerPrice <= 0 || $offerStart === '' || $offerEnd === '')) {
            $message = 'Active offer requires offer price (> 0), start date, and end date (inclusive).';
            $messageType = 'warning';
        } elseif ($isOffer && $offerStart !== '' && $offerEnd !== '' && $offerStart > $offerEnd) {
            $message = 'Offer start date must be on or before end date.';
            $messageType = 'warning';
        } else {
            try {
                $offerPriceVal = $isOffer ? $offerPrice : null;
                $offerStartVal = ($isOffer && $offerStart !== '') ? $offerStart : null;
                $offerEndVal = ($isOffer && $offerEnd !== '') ? $offerEnd : null;
                $offerNoteVal = ($isOffer && $offerNote !== '') ? $offerNote : null;
                $conn->prepare("
                    UPDATE inv_items SET
                        barcode = ?, name = ?, category_id = ?, item_type = ?, base_uom_id = ?,
                        sale_price = ?, vat_rate = ?, default_purchase_price = ?, reorder_level = ?,
                        track_lot = ?, track_expiry = ?, track_serial = ?,
                        is_sellable = ?, is_purchasable = ?, is_consumable = ?, is_service = ?,
                        is_favorite = ?, favorite_sort_order = ?,
                        is_offer = ?, offer_price = ?, offer_start = ?, offer_end = ?, offer_note = ?, offer_badge = ?,
                        updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ")->execute([
                    $barcode !== '' ? $barcode : null,
                    $name,
                    $categoryId ?: null,
                    $itemType,
                    $baseUom,
                    $salePrice,
                    $vatRate,
                    $purchasePrice,
                    $reorder,
                    $trackLot,
                    $trackExpiry,
                    $trackSerial,
                    $isSellable,
                    $isPurchasable,
                    $isConsumable,
                    $isService,
                    $isFavorite,
                    $favoriteSortOrder,
                    $isOffer,
                    $offerPriceVal,
                    $offerStartVal,
                    $offerEndVal,
                    $offerNoteVal,
                    $isOffer ? $offerBadge : null,
                    $itemId,
                    $companyId,
                ]);
                $message = 'Item saved.';
                $messageType = 'success';
                $stmt = $conn->prepare("SELECT * FROM inv_items WHERE id = ? AND company_id = ? LIMIT 1");
                $stmt->execute([$itemId, $companyId]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $message = $e->getMessage();
                $messageType = 'warning';
            }
        }
    }

    if ($act === 'save_pos_locations' && has_permission('inventory_items.edit', MODULE_INVENTORY, $conn)) {
        $posted = $_POST['pos_loc'] ?? [];
        if (!is_array($posted)) {
            $posted = [];
        }
        $err = '';
        try {
            foreach ($posLocations as $lr) {
                $lid = (int)$lr['id'];
                $slot = $posted[$lid] ?? $posted[(string)$lid] ?? [];
                if (!is_array($slot)) {
                    $slot = [];
                }
                $ov = ((string)($slot['override'] ?? '0')) === '1';
                $isFav = ((string)($slot['is_favorite'] ?? '0')) === '1' ? 1 : 0;
                $isOff = ((string)($slot['is_offer'] ?? '0')) === '1' ? 1 : 0;
                $favSort = (int)($slot['favorite_sort_order'] ?? 0);
                $oPriceRaw = trim((string)($slot['offer_price'] ?? ''));
                $oPrice = $oPriceRaw !== '' ? (float)$slot['offer_price'] : 0.0;
                $oStart = trim((string)($slot['offer_start'] ?? ''));
                $oEnd = trim((string)($slot['offer_end'] ?? ''));
                $oNote = trim((string)($slot['offer_note'] ?? ''));
                $oBadgeRaw = trim((string)($slot['offer_badge'] ?? ''));
                $hasOfferFields = $oPrice > 0 || $oStart !== '' || $oEnd !== '' || $oNote !== '' || $oBadgeRaw !== '';
                $useStoreRow = $ov || $isFav === 1 || $isOff === 1 || $hasOfferFields;
                if (!$useStoreRow) {
                    inv_item_pos_location_settings_delete($conn, $companyId, $itemId, $lid);
                    continue;
                }
                $oBadge = strtoupper($oBadgeRaw);
                if (!in_array($oBadge, ['', 'OFFER', 'DISCOUNT', 'EXPIRY_SOON'], true)) {
                    $oBadge = '';
                }
                if ($oBadge === '') {
                    $oBadge = null;
                }
                if ($isOff && ($oPrice <= 0 || $oStart === '' || $oEnd === '')) {
                    $err = 'Store "' . ($lr['name'] ?? $lid) . '": active offer requires a positive price, start, and end date.';
                    break;
                }
                if (!$isOff && $hasOfferFields && ($oPrice > 0 || $oStart !== '' || $oEnd !== '')) {
                    $err = 'Store "' . ($lr['name'] ?? $lid) . '": enable Offer or clear offer price / dates.';
                    break;
                }
                if ($isOff && $oStart !== '' && $oEnd !== '' && $oStart > $oEnd) {
                    $err = 'Store "' . ($lr['name'] ?? $lid) . '": offer start must be on or before end date.';
                    break;
                }
                $offerPriceVal = $isOff ? $oPrice : null;
                $offerStartVal = ($isOff && $oStart !== '') ? $oStart : null;
                $offerEndVal = ($isOff && $oEnd !== '') ? $oEnd : null;
                $offerNoteVal = ($isOff && $oNote !== '') ? $oNote : null;
                inv_item_pos_location_settings_upsert($conn, $companyId, $itemId, $lid, [
                    'is_favorite' => $isFav,
                    'favorite_sort_order' => $favSort,
                    'is_offer' => $isOff,
                    'offer_price' => $offerPriceVal,
                    'offer_start' => $offerStartVal,
                    'offer_end' => $offerEndVal,
                    'offer_note' => $offerNoteVal,
                    'offer_badge' => $isOff ? $oBadge : null,
                ]);
            }
            if ($err === '') {
                $plsByLoc = [];
                $plsStmt = $conn->prepare("SELECT * FROM inv_item_pos_location_settings WHERE company_id = ? AND item_id = ?");
                $plsStmt->execute([$companyId, $itemId]);
                foreach ($plsStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
                    $plsByLoc[(int)$s['location_id']] = $s;
                }
                $message = 'Per-store POS settings saved.';
                $messageType = 'success';
            } else {
                $message = $err;
                $messageType = 'warning';
            }
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'warning';
        }
    }

    if ($act === 'upload_image' && has_permission('inventory_items.edit', MODULE_INVENTORY, $conn)) {
        if (!empty($_FILES['image']['tmp_name'])) {
            $setPrimary = !empty($_POST['set_primary']) || !$images;
            $res = inv_item_image_process_upload($conn, $companyId, $itemId, $_FILES['image']);
            if (!empty($res['ok']) && !empty($res['paths'])) {
                try {
                    inv_item_image_save_row(
                        $conn,
                        $companyId,
                        $itemId,
                        $res['paths']['original'],
                        $res['paths']['thumb'],
                        $res['paths']['mime'],
                        $setPrimary
                    );
                    $message = 'Image uploaded.';
                    $messageType = 'success';
                } catch (Throwable $e) {
                    $message = $e->getMessage();
                    $messageType = 'warning';
                }
            } else {
                $message = $res['error'] ?? 'Upload failed';
                $messageType = 'warning';
            }
        }
        $imgStmt->execute([$companyId, $itemId]);
        $images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($act === 'delete_image' && has_permission('inventory_items.edit', MODULE_INVENTORY, $conn)) {
        $imgDel = (int)($_POST['image_id'] ?? 0);
        if ($imgDel && inv_item_image_delete($conn, $companyId, $imgDel)) {
            $message = 'Image removed.';
            $messageType = 'success';
        }
        $imgStmt->execute([$companyId, $itemId]);
        $images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $conn->prepare("SELECT * FROM inv_items WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->execute([$itemId, $companyId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($act === 'set_primary_image' && has_permission('inventory_items.edit', MODULE_INVENTORY, $conn)) {
        $pid = (int)($_POST['image_id'] ?? 0);
        if ($pid && inv_item_image_set_primary($conn, $companyId, $itemId, $pid)) {
            $message = 'Primary image updated.';
            $messageType = 'success';
        }
        $imgStmt->execute([$companyId, $itemId]);
        $images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $conn->prepare("SELECT * FROM inv_items WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->execute([$itemId, $companyId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

$pageTitle = 'Item: ' . ($item['item_code'] ?? '');
require_once __DIR__ . '/includes/inv_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div class="page-header-label"><?= h($item['item_code']) ?></div>
  <a class="btn btn-outline-secondary btn-sm" href="items.php">Back to items</a>
</div>

<?php if ($message): ?>
  <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'warning' ?>"><?= h($message) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card p-3">
      <div class="fw-semibold mb-2">Details</div>
      <p class="small text-muted">Sale price is <strong>VAT-exclusive</strong>; VAT % is applied for POS totals.</p>
      <?php if (has_permission('inventory_items.edit', MODULE_INVENTORY, $conn)): ?>
      <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">Name</label>
            <input class="form-control" name="name" value="<?= h($item['name']) ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">SKU</label>
            <input class="form-control" value="<?= h($item['item_code']) ?>" disabled>
          </div>
          <div class="col-md-3">
            <label class="form-label">Barcode</label>
            <input class="form-control" name="barcode" value="<?= h($item['barcode'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Category</label>
            <select class="form-select" name="category_id">
              <option value="">—</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (int)($item['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Item type</label>
            <select class="form-select" name="item_type">
              <?php foreach (['stock_item','consumable','raw_material','finished_product','resale_item','service'] as $t): ?>
                <option value="<?= h($t) ?>" <?= ($item['item_type'] ?? '') === $t ? 'selected' : '' ?>><?= h(str_replace('_',' ', $t)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Base UoM</label>
            <select class="form-select" name="base_uom_id" required>
              <?php foreach ($uoms as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (int)$item['base_uom_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['code'] . ' — ' . $u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Sale price (ex VAT)</label>
            <input class="form-control" type="number" step="0.01" name="sale_price" value="<?= h((string)$item['sale_price']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">VAT rate %</label>
            <input class="form-control" type="number" step="0.001" name="vat_rate" value="<?= h((string)($item['vat_rate'] ?? '0')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Purchase cost</label>
            <input class="form-control" type="number" step="0.01" name="default_purchase_price" value="<?= h((string)$item['default_purchase_price']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Reorder level</label>
            <input class="form-control" type="number" step="0.01" name="reorder_level" value="<?= h((string)$item['reorder_level']) ?>">
          </div>
            <div class="col-12 border-top pt-3 mt-1">
            <div class="fw-semibold mb-2">Retail POS — defaults</div>
            <p class="small text-muted mb-2">Used for every store that does not have its own row under per-store settings below. A store row fully overrides these fields for that location.</p>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="is_favorite" id="is_favorite" <?= !empty($item['is_favorite']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="is_favorite">Favorite on POS (Favorites tab)</label>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-md-4">
                <label class="form-label">Favorite sort order</label>
                <input class="form-control" type="number" step="1" name="favorite_sort_order" value="<?= h((string)(int)($item['favorite_sort_order'] ?? 0)) ?>" title="Lower numbers appear first in Favorites">
              </div>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="is_offer" id="is_offer" <?= !empty($item['is_offer']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="is_offer">Promotional offer (VAT-exclusive offer price; dates inclusive)</label>
            </div>
            <div class="row g-2">
              <div class="col-md-3">
                <label class="form-label">Offer price (ex VAT)</label>
                <input class="form-control" type="number" step="0.0001" name="offer_price" value="<?= h((string)($item['offer_price'] ?? '')) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Offer start</label>
                <input class="form-control" type="date" name="offer_start" value="<?= h(substr((string)($item['offer_start'] ?? ''), 0, 10)) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Offer end (inclusive)</label>
                <input class="form-control" type="date" name="offer_end" value="<?= h(substr((string)($item['offer_end'] ?? ''), 0, 10)) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Badge</label>
                <select class="form-select" name="offer_badge">
                  <?php
                  $rawOb = $item['offer_badge'] ?? null;
                  $ob = $rawOb !== null && $rawOb !== '' ? strtoupper((string)$rawOb) : '';
                  ?>
                  <option value="" <?= $ob === '' ? 'selected' : '' ?>>Default (Offer)</option>
                  <option value="OFFER" <?= $ob === 'OFFER' ? 'selected' : '' ?>>Offer</option>
                  <option value="DISCOUNT" <?= $ob === 'DISCOUNT' ? 'selected' : '' ?>>Discount</option>
                  <option value="EXPIRY_SOON" <?= $ob === 'EXPIRY_SOON' ? 'selected' : '' ?>>Expiry soon</option>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label">Offer note (optional)</label>
                <input class="form-control" name="offer_note" maxlength="255" value="<?= h((string)($item['offer_note'] ?? '')) ?>" placeholder="e.g. Near expiry, Weekend promo">
              </div>
            </div>
            </div>
            <div class="col-12">
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="is_sellable" id="is_sellable" <?= !empty($item['is_sellable']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="is_sellable">Sellable</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="is_purchasable" id="is_purchasable" <?= !empty($item['is_purchasable']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="is_purchasable">Purchasable</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="is_consumable" id="is_consumable" <?= !empty($item['is_consumable']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="is_consumable">Consumable</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="is_service" id="is_service" <?= !empty($item['is_service']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="is_service">Service (POS: no stock movement)</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="track_lot" id="tl" <?= !empty($item['track_lot']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="tl">Track lot</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="track_expiry" id="te" <?= !empty($item['track_expiry']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="te">Track expiry</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="track_serial" id="ts" <?= !empty($item['track_serial']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="ts">Track serial</label>
            </div>
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-primary">Save</button>
          </div>
        </div>
      </form>
      <?php else: ?>
        <p class="text-muted">You do not have permission to edit.</p>
      <?php endif; ?>
    </div>

    <?php if ($posLocations && has_permission('inventory_items.edit', MODULE_INVENTORY, $conn)): ?>
    <div class="card p-3 mt-3">
      <div class="fw-semibold mb-2">Per-store Retail POS</div>
      <p class="small text-muted mb-2"><strong>Fav</strong> or <strong>Offer</strong> (or <strong>Store-specific</strong>) saves a row for that store. Leave all off and clear offer fields, then save, to remove overrides. Use <strong>Store-specific</strong> with Fav off to force “not a favorite” at this store while the default above stays on.</p>
      <form method="POST" class="table-responsive">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="save_pos_locations">
        <table class="table table-sm align-middle mb-2">
          <thead>
            <tr>
              <th>Location</th>
              <th>Override</th>
              <th>Fav</th>
              <th>Sort</th>
              <th>Offer</th>
              <th>Offer ex VAT</th>
              <th>Start</th>
              <th>End</th>
              <th>Badge</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($posLocations as $lr):
              $lid = (int)$lr['id'];
              $row = $plsByLoc[$lid] ?? null;
              $hasRow = $row !== null;
              $chkFav = $hasRow ? !empty($row['is_favorite']) : !empty($item['is_favorite']);
              $chkOff = $hasRow ? !empty($row['is_offer']) : !empty($item['is_offer']);
              $favSort = $hasRow ? (int)($row['favorite_sort_order'] ?? 0) : (int)($item['favorite_sort_order'] ?? 0);
              $op = $hasRow ? ($row['offer_price'] ?? '') : ($item['offer_price'] ?? '');
              $os = $hasRow ? ($row['offer_start'] ?? '') : ($item['offer_start'] ?? '');
              $oe = $hasRow ? ($row['offer_end'] ?? '') : ($item['offer_end'] ?? '');
              $on = $hasRow ? ($row['offer_note'] ?? '') : ($item['offer_note'] ?? '');
              $obRaw = $hasRow ? ($row['offer_badge'] ?? '') : ($item['offer_badge'] ?? '');
              $ob = $obRaw !== null && $obRaw !== '' ? strtoupper((string)$obRaw) : '';
              ?>
            <tr>
              <td class="text-nowrap small"><?= h($lr['name']) ?><br><span class="text-muted"><?= h($lr['code']) ?></span></td>
              <td>
                <input type="hidden" name="pos_loc[<?= $lid ?>][override]" value="0">
                <input type="checkbox" class="form-check-input" name="pos_loc[<?= $lid ?>][override]" value="1" <?= $hasRow ? 'checked' : '' ?> title="Store-specific (optional; use with Fav off to block default favorite)">
              </td>
              <td>
                <input type="hidden" name="pos_loc[<?= $lid ?>][is_favorite]" value="0">
                <input type="checkbox" class="form-check-input" name="pos_loc[<?= $lid ?>][is_favorite]" value="1" <?= $chkFav ? 'checked' : '' ?>>
              </td>
              <td><input class="form-control form-control-sm" type="number" step="1" name="pos_loc[<?= $lid ?>][favorite_sort_order]" value="<?= (int)$favSort ?>" style="width:4rem"></td>
              <td>
                <input type="hidden" name="pos_loc[<?= $lid ?>][is_offer]" value="0">
                <input type="checkbox" class="form-check-input" name="pos_loc[<?= $lid ?>][is_offer]" value="1" <?= $chkOff ? 'checked' : '' ?>>
              </td>
              <td><input class="form-control form-control-sm" type="number" step="0.0001" name="pos_loc[<?= $lid ?>][offer_price]" value="<?= $op !== '' && $op !== null ? h((string)$op) : '' ?>" style="width:5.5rem"></td>
              <td><input class="form-control form-control-sm" type="date" name="pos_loc[<?= $lid ?>][offer_start]" value="<?= h(substr((string)$os, 0, 10)) ?>"></td>
              <td><input class="form-control form-control-sm" type="date" name="pos_loc[<?= $lid ?>][offer_end]" value="<?= h(substr((string)$oe, 0, 10)) ?>"></td>
              <td>
                <select class="form-select form-select-sm" name="pos_loc[<?= $lid ?>][offer_badge]" style="width:6rem">
                  <option value="" <?= $ob === '' ? 'selected' : '' ?>>—</option>
                  <option value="OFFER" <?= $ob === 'OFFER' ? 'selected' : '' ?>>Offer</option>
                  <option value="DISCOUNT" <?= $ob === 'DISCOUNT' ? 'selected' : '' ?>>Discount</option>
                  <option value="EXPIRY_SOON" <?= $ob === 'EXPIRY_SOON' ? 'selected' : '' ?>>Expiry</option>
                </select>
              </td>
              <td><input class="form-control form-control-sm" name="pos_loc[<?= $lid ?>][offer_note]" value="<?= h((string)$on) ?>" maxlength="255" style="min-width:8rem"></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <button type="submit" class="btn btn-primary btn-sm">Save per-store POS</button>
      </form>
    </div>
    <?php elseif ($posLocations): ?>
    <div class="card p-3 mt-3">
      <div class="fw-semibold mb-2">Per-store Retail POS</div>
      <p class="text-muted small mb-0">Per-store overrides are configured on this screen; you need edit permission to change them.</p>
    </div>
    <?php endif; ?>
  </div>
  <div class="col-lg-5">
    <div class="card p-3">
      <div class="fw-semibold mb-2">Images</div>
      <p class="small text-muted">Original + optimized thumbnail stored on disk. Primary image is used in lists.</p>
      <?php if (has_permission('inventory_items.edit', MODULE_INVENTORY, $conn)): ?>
      <form method="POST" enctype="multipart/form-data" class="mb-3 border rounded p-2">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="upload_image">
        <label class="form-label small">Upload</label>
        <input class="form-control form-control-sm" type="file" name="image" accept="image/*" required>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" name="set_primary" id="setp" value="1" checked>
          <label class="form-check-label small" for="setp">Set as primary</label>
        </div>
        <button type="submit" class="btn btn-sm btn-primary mt-2">Upload</button>
      </form>
      <?php endif; ?>
      <div class="row g-2">
        <?php foreach ($images as $im): ?>
          <div class="col-6">
            <div class="border rounded p-1 text-center">
              <a href="<?= h(inv_item_image_public_url($im['path_original'])) ?>" target="_blank" rel="noopener">
                <img src="<?= h(inv_item_image_public_url($im['path_thumb'])) ?>" alt="" class="img-fluid rounded" style="max-height:120px;object-fit:contain">
              </a>
              <div class="small mt-1">
                <?php if (!empty($im['is_primary'])): ?><span class="badge bg-primary">Primary</span><?php endif; ?>
              </div>
              <?php if (has_permission('inventory_items.edit', MODULE_INVENTORY, $conn)): ?>
              <div class="btn-group btn-group-sm mt-1">
                <?php if (empty($im['is_primary'])): ?>
                <form method="POST" class="d-inline"><?php csrf_field(); ?>
                  <input type="hidden" name="action" value="set_primary_image">
                  <input type="hidden" name="image_id" value="<?= (int)$im['id'] ?>">
                  <button type="submit" class="btn btn-outline-secondary btn-sm">Primary</button>
                </form>
                <?php endif; ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this image?');"><?php csrf_field(); ?>
                  <input type="hidden" name="action" value="delete_image">
                  <input type="hidden" name="image_id" value="<?= (int)$im['id'] ?>">
                  <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                </form>
              </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$images): ?>
          <div class="col-12 text-muted small">No images yet.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>
