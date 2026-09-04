<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/permissions.php';

require_login();
require_module_access($conn, MODULE_INVENTORY);
require_department_access(MODULE_INVENTORY, DEPT_INVENTORY, $conn);
require_permission('inventory_items.view', MODULE_INVENTORY, $conn);
require_once __DIR__ . '/../../includes/inventory/inv_item_images.php';

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;
$message = $messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    require_permission('inventory_items.create', MODULE_INVENTORY, $conn);

    $itemCode = trim($_POST['item_code'] ?? '');
    $barcode  = trim($_POST['barcode'] ?? '');
    $name     = trim($_POST['name'] ?? '');
    $itemType = trim($_POST['item_type'] ?? 'stock_item');
    $baseUom  = (int)($_POST['base_uom_id'] ?? 0);
    $salePrice = (float)($_POST['sale_price'] ?? 0);
    $vatRate = (float)($_POST['vat_rate'] ?? 0);
    $purchasePrice = (float)($_POST['default_purchase_price'] ?? 0);
    $reorder = (float)($_POST['reorder_level'] ?? 0);
    $trackLot = !empty($_POST['track_lot']) ? 1 : 0;
    $trackExpiry = !empty($_POST['track_expiry']) ? 1 : 0;
    $trackSerial = !empty($_POST['track_serial']) ? 1 : 0;
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $isSellable = !empty($_POST['is_sellable']) ? 1 : 0;
    $isPurchasable = !empty($_POST['is_purchasable']) ? 1 : 0;
    $isConsumable = !empty($_POST['is_consumable']) ? 1 : 0;
    $isService = !empty($_POST['is_service']) ? 1 : 0;

    if (!$companyId) {
        $message = 'Select a company first.';
        $messageType = 'warning';
    } elseif ($itemCode === '' || $name === '' || !$baseUom) {
        $message = 'Item code, name, and base UoM are required.';
        $messageType = 'warning';
    } else {
        try {
            $stmt = $conn->prepare("
                INSERT INTO inv_items
                    (company_id, item_code, barcode, name, category_id, item_type, base_uom_id,
                     sale_price, vat_rate, default_purchase_price, reorder_level,
                     track_lot, track_expiry, track_serial,
                     is_sellable, is_purchasable, is_consumable, is_service, is_active)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)
            ");
            $stmt->execute([
                $companyId,
                $itemCode,
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
            ]);
            $message = 'Item created.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = 'Could not create item: ' . $e->getMessage();
            $messageType = 'warning';
        }
    }
}

$uoms = [];
try {
    $uoms = $conn->query("SELECT id, code, name FROM inv_uoms WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$categories = [];
if ($companyId) {
    $stmt = $conn->prepare("SELECT id, name FROM inv_item_categories WHERE company_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([$companyId]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$items = [];
if ($companyId) {
    $stmt = $conn->prepare("
        SELECT i.*, th.path_thumb AS primary_thumb_path
        FROM inv_items i
        LEFT JOIN inv_item_images th ON th.id = i.primary_image_id
        WHERE i.company_id = ?
        ORDER BY i.id DESC LIMIT 200
    ");
    $stmt->execute([$companyId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Items';
require_once __DIR__ . '/includes/inv_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-header-label">Items</div>
</div>

<?php if ($message): ?>
  <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'warning' ? 'warning' : 'info') ?>"><?= h($message) ?></div>
<?php endif; ?>

<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">Create item</div>
  <p class="small text-muted">Sale price is <strong>VAT-exclusive</strong>. Set VAT % for POS/inclusive display.</p>
  <form method="POST" class="row g-2">
    <?php csrf_field(); ?>
    <div class="col-md-3">
      <label class="form-label">Item code (SKU)</label>
      <input class="form-control" name="item_code" required>
    </div>
    <div class="col-md-3">
      <label class="form-label">Barcode</label>
      <input class="form-control" name="barcode">
    </div>
    <div class="col-md-4">
      <label class="form-label">Name</label>
      <input class="form-control" name="name" required>
    </div>
    <div class="col-md-2">
      <label class="form-label">Category</label>
      <select class="form-select" name="category_id">
        <option value="">—</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Item type</label>
      <select class="form-select" name="item_type">
        <?php foreach (['stock_item','consumable','raw_material','finished_product','resale_item','service'] as $t): ?>
          <option value="<?= h($t) ?>"><?= h(str_replace('_',' ', $t)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Base UoM</label>
      <select class="form-select" name="base_uom_id" required>
        <option value="">— Select —</option>
        <?php foreach ($uoms as $u): ?>
          <option value="<?= (int)$u['id'] ?>"><?= h($u['code'] . ' — ' . $u['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Sale (ex VAT)</label>
      <input class="form-control" type="number" step="0.01" name="sale_price" value="0">
    </div>
    <div class="col-md-2">
      <label class="form-label">VAT %</label>
      <input class="form-control" type="number" step="0.001" name="vat_rate" value="0">
    </div>
    <div class="col-md-2">
      <label class="form-label">Purchase cost</label>
      <input class="form-control" type="number" step="0.01" name="default_purchase_price" value="0">
    </div>
    <div class="col-md-2">
      <label class="form-label">Reorder level</label>
      <input class="form-control" type="number" step="0.01" name="reorder_level" value="0">
    </div>
    <div class="col-12">
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="checkbox" name="is_sellable" id="is_sellable" checked>
        <label class="form-check-label" for="is_sellable">Sellable</label>
      </div>
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="checkbox" name="is_purchasable" id="is_purchasable" checked>
        <label class="form-check-label" for="is_purchasable">Purchasable</label>
      </div>
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="checkbox" name="is_consumable" id="is_consumable" checked>
        <label class="form-check-label" for="is_consumable">Consumable</label>
      </div>
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="checkbox" name="is_service" id="is_service">
        <label class="form-check-label" for="is_service">Service (no stock move in POS)</label>
      </div>
    </div>
    <div class="col-12">
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="checkbox" name="track_lot" id="tl">
        <label class="form-check-label" for="tl">Track lot</label>
      </div>
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="checkbox" name="track_expiry" id="te">
        <label class="form-check-label" for="te">Track expiry</label>
      </div>
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="checkbox" name="track_serial" id="ts">
        <label class="form-check-label" for="ts">Track serial</label>
      </div>
    </div>
    <div class="col-12">
      <button class="btn btn-primary">Create</button>
    </div>
  </form>
</div>

<div class="card p-3">
  <div class="fw-semibold mb-2">Latest items</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th style="width:56px"></th>
          <th>ID</th>
          <th>SKU</th>
          <th>Name</th>
          <th>Type</th>
          <th>Barcode</th>
          <th class="text-end">Sale ex VAT</th>
          <th class="text-end">VAT %</th>
          <th class="text-end">Cost</th>
          <th class="text-end">Reorder</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td class="p-1">
              <?php if (!empty($it['primary_thumb_path'])): ?>
                <img src="<?= h(inv_item_image_public_url($it['primary_thumb_path'])) ?>" alt="" class="rounded border" style="width:48px;height:48px;object-fit:contain">
              <?php else: ?>
                <div class="bg-light border rounded d-flex align-items-center justify-content-center text-muted small" style="width:48px;height:48px">—</div>
              <?php endif; ?>
            </td>
            <td><?= (int)$it['id'] ?></td>
            <td class="fw-semibold"><?= h($it['item_code']) ?></td>
            <td><?= h($it['name']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= h($it['item_type']) ?></span></td>
            <td><?= h($it['barcode'] ?: '—') ?></td>
            <td class="text-end"><?= number_format((float)$it['sale_price'], 2) ?></td>
            <td class="text-end"><?= number_format((float)($it['vat_rate'] ?? 0), 3) ?></td>
            <td class="text-end"><?= number_format((float)$it['default_purchase_price'], 2) ?></td>
            <td class="text-end"><?= number_format((float)$it['reorder_level'], 2) ?></td>
            <td><a class="btn btn-sm btn-outline-primary" href="item_edit.php?id=<?= (int)$it['id'] ?>">Edit</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?>
          <tr><td colspan="11" class="text-muted">No items yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>

