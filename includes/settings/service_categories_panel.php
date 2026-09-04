<?php
/**
 * Settings panel — Service Management categories (Phase 5 + 5b catalog).
 */
require_once __DIR__ . '/../service_category_helper.php';
require_once __DIR__ . '/../cleaning_accounting_context.php';

$smCatCompanyId = cleaning_accounting_company_id($conn);
$smCatTableReady = sm_service_category_table_exists($conn);
$smCatMessage = '';
$smCatMessageType = 'info';
$smHasMaterialsCol = $smCatTableReady && sm_category_column_exists($conn, 'materials_rate_per_hour');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    try {
        if (!$smCatTableReady) {
            throw new RuntimeException('Run tools/sm_apply_phase5_schema.php first.');
        }
        if ($postAction === 'save_service_category') {
            $savedId = sm_save_service_category($conn, [
                'id' => (int)($_POST['id'] ?? 0),
                'company_id' => $smCatCompanyId,
                'code' => $_POST['code'] ?? '',
                'name' => $_POST['name'] ?? '',
                'icon' => $_POST['icon'] ?? '',
                'sort_order' => (int)($_POST['sort_order'] ?? 0),
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
            ]);
            if ($smHasMaterialsCol && !empty($_POST['save_materials_rate'])) {
                sm_save_category_materials_rate($conn, $savedId, (float)($_POST['materials_rate_per_hour'] ?? 0));
            }
            $smCatMessage = $savedId ? "Category saved (ID {$savedId})." : 'Category saved.';
            $smCatMessageType = 'success';
        } elseif ($postAction === 'save_catalog_item') {
            $catId = (int)($_POST['category_id'] ?? 0);
            $itemId = sm_save_catalog_item($conn, $catId, [
                'id' => (int)($_POST['catalog_id'] ?? 0),
                'name' => $_POST['catalog_name'] ?? '',
                'price' => $_POST['catalog_price'] ?? 0,
                'description' => $_POST['catalog_description'] ?? '',
                'sort_order' => (int)($_POST['catalog_sort_order'] ?? 0),
                'is_active' => isset($_POST['catalog_is_active']) ? 1 : 0,
            ], $smCatCompanyId);
            $smCatMessage = "Catalog item saved (ID {$itemId}).";
            $smCatMessageType = 'success';
            $_GET['edit_cat'] = $catId;
        } elseif ($postAction === 'delete_catalog_item') {
            sm_delete_catalog_item($conn, (int)($_POST['category_id'] ?? 0), (int)($_POST['catalog_id'] ?? 0));
            $smCatMessage = 'Catalog item removed.';
            $smCatMessageType = 'success';
            $_GET['edit_cat'] = (int)($_POST['category_id'] ?? 0);
        }
    } catch (Throwable $e) {
        $smCatMessage = $e->getMessage();
        $smCatMessageType = 'danger';
    }
}

$smCategories = $smCatTableReady ? sm_list_service_categories($conn, $smCatCompanyId, false) : [];
$editId = (int)($_GET['edit_cat'] ?? 0);
$editRow = null;
foreach ($smCategories as $c) {
    if ((int)$c['id'] === $editId) {
        $editRow = sm_get_category_by_id($conn, $editId, $smCatCompanyId) ?: $c;
        break;
    }
}
$editIsCleaning = ($editRow['code'] ?? '') === 'cleaning';
$editCatalog = (!$editIsCleaning && $editRow) ? sm_list_category_catalog($conn, (int)$editRow['id'], false) : [];
$editCatalogId = (int)($_GET['edit_item'] ?? 0);
$editCatalogRow = null;
foreach ($editCatalog as $item) {
    if ((int)$item['id'] === $editCatalogId) {
        $editCatalogRow = $item;
        break;
    }
}
?>
<div class="settings-card">
  <div class="settings-header">
    <h5 class="mb-0"><i class="bi bi-grid-3x3-gap"></i> Service Categories</h5>
    <small class="text-muted">Manage business lines, cleaning materials rate, and catalog prices for field services.</small>
  </div>
  <div class="card-body">
    <?php if (!$smCatTableReady): ?>
      <div class="alert alert-warning">
        Phase 5 tables are not installed. Run
        <code>php tools/sm_apply_phase5_schema.php</code>
        or open <a href="<?= h(get_base_path()) ?>/tools/sm_apply_phase5_schema.php">sm_apply_phase5_schema.php</a> as Admin.
      </div>
    <?php else: ?>
      <?php if (!$smHasMaterialsCol): ?>
        <div class="alert alert-warning py-2 small">
          Run <code>php tools/sm_apply_phase5b_schema.php</code> to enable cleaning materials rate and multi-service bookings.
        </div>
      <?php endif; ?>
      <?php if ($smCatMessage): ?>
        <div class="alert alert-<?= h($smCatMessageType) ?>"><?= h($smCatMessage) ?></div>
      <?php endif; ?>

      <div class="row g-4">
        <div class="col-lg-7">
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead class="table-light">
                <tr>
                  <th>Icon</th>
                  <th>Name</th>
                  <th>Code</th>
                  <th>Pricing</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$smCategories): ?>
                  <tr><td colspan="6" class="text-muted">No categories yet.</td></tr>
                <?php else: foreach ($smCategories as $cat):
                  $full = sm_get_category_by_id($conn, (int)$cat['id'], $smCatCompanyId) ?: $cat;
                  $isCleaning = ($full['code'] ?? '') === 'cleaning';
                  $catalogCount = $isCleaning ? 0 : count(sm_list_category_catalog($conn, (int)$full['id'], true));
                ?>
                  <tr>
                    <td><?= h($full['icon'] ?? '') ?></td>
                    <td><strong><?= h($full['name']) ?></strong></td>
                    <td><code><?= h($full['code']) ?></code></td>
                    <td class="small text-muted">
                      <?php if ($isCleaning): ?>
                        Hourly labour + materials
                        <?php if ($smHasMaterialsCol): ?>
                          <br><span class="text-dark">Materials: AED <?= number_format((float)($full['materials_rate_per_hour'] ?? 0), 2) ?>/h/worker</span>
                        <?php endif; ?>
                      <?php else: ?>
                        <?= (int)$catalogCount ?> catalog item<?= $catalogCount === 1 ? '' : 's' ?>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ((int)$full['is_active'] === 1): ?>
                        <span class="badge text-bg-success">Active</span>
                      <?php else: ?>
                        <span class="badge text-bg-secondary">Inactive</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <a class="btn btn-sm btn-outline-primary" href="<?= h(get_base_path()) ?>/settings.php?tab=service_categories&amp;edit_cat=<?= (int)$full['id'] ?>">Configure</a>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
          <p class="small text-muted mb-0">
            <strong>Cleaning</strong> is priced by hours × rate × workers, plus optional materials per hour per worker.
            Other categories (Pest Control, AC, etc.) use fixed catalog items you define here — no SQL required.
            On <strong>Worker Availability → Add Order</strong>, admins can combine Cleaning + another category in one booking.
          </p>
        </div>

        <div class="col-lg-5">
          <div class="card border-0 bg-light">
            <div class="card-body">
              <h6 class="mb-3"><?= $editRow ? 'Configure category' : 'Add category' ?></h6>
              <form method="post">
                <input type="hidden" name="action" value="save_service_category">
                <input type="hidden" name="id" value="<?= (int)($editRow['id'] ?? 0) ?>">
                <div class="mb-2">
                  <label class="form-label">Name *</label>
                  <input type="text" name="name" class="form-control" required maxlength="100"
                         value="<?= h($editRow['name'] ?? '') ?>" placeholder="e.g. Pest Control">
                </div>
                <div class="mb-2">
                  <label class="form-label">Code *</label>
                  <input type="text" name="code" class="form-control" required maxlength="32"
                         pattern="[a-z][a-z0-9_]{1,30}"
                         value="<?= h($editRow['code'] ?? '') ?>" placeholder="pest_control"
                         <?= $editRow && ($editRow['code'] ?? '') === 'cleaning' ? 'readonly' : '' ?>>
                  <div class="form-text">Lowercase, unique per company. Cleaning code is locked.</div>
                </div>
                <div class="mb-2">
                  <label class="form-label">Icon (emoji or short text)</label>
                  <input type="text" name="icon" class="form-control" maxlength="50" value="<?= h($editRow['icon'] ?? '') ?>">
                </div>
                <div class="mb-2">
                  <label class="form-label">Sort order</label>
                  <input type="number" name="sort_order" class="form-control" value="<?= (int)($editRow['sort_order'] ?? 0) ?>">
                </div>
                <?php if ($editIsCleaning && $smHasMaterialsCol): ?>
                <div class="mb-3 p-3 border rounded bg-white">
                  <label class="form-label fw-semibold">Cleaning materials rate</label>
                  <div class="input-group">
                    <span class="input-group-text">AED</span>
                    <input type="number" step="0.01" min="0" name="materials_rate_per_hour" class="form-control"
                           value="<?= number_format((float)($editRow['materials_rate_per_hour'] ?? 0), 2, '.', '') ?>">
                    <span class="input-group-text">per hour per worker</span>
                  </div>
                  <div class="form-text">Added to the order when “Cleaning materials: Yes” is selected at booking. Labour still uses the client hourly rate.</div>
                  <input type="hidden" name="save_materials_rate" value="1">
                </div>
                <?php endif; ?>
                <div class="form-check mb-3">
                  <input class="form-check-input" type="checkbox" name="is_active" id="sm_cat_active" value="1"
                    <?= !isset($editRow['is_active']) || (int)($editRow['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                  <label class="form-check-label" for="sm_cat_active">Active (available for new jobs)</label>
                </div>
                <div class="d-flex gap-2">
                  <button type="submit" class="btn btn-primary btn-sm">Save category</button>
                  <?php if ($editRow): ?>
                    <a href="<?= h(get_base_path()) ?>/settings.php?tab=service_categories" class="btn btn-outline-secondary btn-sm">Cancel</a>
                  <?php endif; ?>
                </div>
              </form>
            </div>
          </div>

          <?php if ($editRow && !$editIsCleaning): ?>
          <div class="card border-0 mt-3">
            <div class="card-header bg-white py-2">
              <strong>Catalog items — <?= h($editRow['name']) ?></strong>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-sm mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Item</th>
                      <th class="text-end">Price (AED)</th>
                      <th>Status</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$editCatalog): ?>
                      <tr><td colspan="4" class="text-muted p-3">No items yet. Add your first service below.</td></tr>
                    <?php else: foreach ($editCatalog as $item): ?>
                      <tr>
                        <td><?= h($item['name']) ?></td>
                        <td class="text-end"><?= number_format((float)($item['default_price'] ?? 0), 2) ?></td>
                        <td>
                          <?php if ((int)$item['is_active'] === 1): ?>
                            <span class="badge text-bg-success">Active</span>
                          <?php else: ?>
                            <span class="badge text-bg-secondary">Off</span>
                          <?php endif; ?>
                        </td>
                        <td class="text-end">
                          <a class="btn btn-sm btn-link" href="<?= h(get_base_path()) ?>/settings.php?tab=service_categories&amp;edit_cat=<?= (int)$editRow['id'] ?>&amp;edit_item=<?= (int)$item['id'] ?>">Edit</a>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
            <div class="card-body border-top bg-light">
              <h6 class="mb-2"><?= $editCatalogRow ? 'Edit item' : 'Add item' ?></h6>
              <form method="post">
                <input type="hidden" name="action" value="save_catalog_item">
                <input type="hidden" name="category_id" value="<?= (int)$editRow['id'] ?>">
                <input type="hidden" name="catalog_id" value="<?= (int)($editCatalogRow['id'] ?? 0) ?>">
                <div class="mb-2">
                  <label class="form-label">Item name *</label>
                  <input type="text" name="catalog_name" class="form-control" required maxlength="120"
                         value="<?= h($editCatalogRow['name'] ?? '') ?>" placeholder="e.g. General pest treatment">
                </div>
                <div class="mb-2">
                  <label class="form-label">Default price (AED) *</label>
                  <input type="number" step="0.01" min="0" name="catalog_price" class="form-control" required
                         value="<?= $editCatalogRow ? number_format((float)($editCatalogRow['default_price'] ?? 0), 2, '.', '') : '' ?>">
                </div>
                <div class="mb-2">
                  <label class="form-label">Description (optional)</label>
                  <input type="text" name="catalog_description" class="form-control" maxlength="255"
                         value="<?= h($editCatalogRow['description'] ?? '') ?>">
                </div>
                <div class="row g-2 mb-2">
                  <div class="col-6">
                    <label class="form-label">Sort order</label>
                    <input type="number" name="catalog_sort_order" class="form-control" value="<?= (int)($editCatalogRow['sort_order'] ?? 0) ?>">
                  </div>
                  <div class="col-6 d-flex align-items-end">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="catalog_is_active" id="catalog_is_active" value="1"
                        <?= !$editCatalogRow || (int)($editCatalogRow['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                      <label class="form-check-label" for="catalog_is_active">Active</label>
                    </div>
                  </div>
                </div>
                <div class="d-flex gap-2">
                  <button type="submit" class="btn btn-primary btn-sm"><?= $editCatalogRow ? 'Update item' : 'Add item' ?></button>
                  <?php if ($editCatalogRow): ?>
                    <a href="<?= h(get_base_path()) ?>/settings.php?tab=service_categories&amp;edit_cat=<?= (int)$editRow['id'] ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
                  <?php endif; ?>
                </div>
              </form>
              <?php if ($editCatalogRow): ?>
              <form method="post" class="mt-2" onsubmit="return confirm('Remove this catalog item?');">
                <input type="hidden" name="action" value="delete_catalog_item">
                <input type="hidden" name="category_id" value="<?= (int)$editRow['id'] ?>">
                <input type="hidden" name="catalog_id" value="<?= (int)$editCatalogRow['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate item</button>
              </form>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
