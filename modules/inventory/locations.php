<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/inventory/inv_company_settings.php';
require_once __DIR__ . '/../../includes/inventory/inv_pos_retail_profiles.php';

require_login();
require_module_access($conn, MODULE_INVENTORY);
require_department_access(MODULE_INVENTORY, DEPT_INVENTORY, $conn);
require_permission('inventory_locations.view', MODULE_INVENTORY, $conn);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;
$message = $messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (!empty($_POST['save_default_pos_location'])) {
        require_permission('inventory_locations.edit', MODULE_INVENTORY, $conn);
        $defLoc = (int)($_POST['default_pos_location_id'] ?? 0);
        if (!$companyId) {
            $message = 'Select a company first.';
            $messageType = 'warning';
        } else {
            try {
                inv_save_default_pos_location($conn, $companyId, $defLoc > 0 ? $defLoc : null);
                $message = $defLoc > 0 ? 'Default Retail POS location saved.' : 'Default cleared — cashiers will choose a location on the POS until you set one again.';
                $messageType = 'success';
            } catch (Throwable $e) {
                $message = $e->getMessage();
                $messageType = 'warning';
            }
        }
    } elseif (!empty($_POST['add_pos_profile'])) {
        require_permission('inventory_locations.edit', MODULE_INVENTORY, $conn);
        if (!$companyId) {
            $message = 'Select a company first.';
            $messageType = 'warning';
        } else {
            try {
                inv_pos_profile_create(
                    $conn,
                    $companyId,
                    (string)($_POST['profile_label'] ?? ''),
                    (string)($_POST['profile_code'] ?? ''),
                    (int)($_POST['profile_location_id'] ?? 0)
                );
                $message = 'POS profile created. Open Retail POS with the URL code in the table below.';
                $messageType = 'success';
            } catch (Throwable $e) {
                $message = $e->getMessage();
                $messageType = 'warning';
            }
        }
    } elseif (!empty($_POST['delete_pos_profile'])) {
        require_permission('inventory_locations.edit', MODULE_INVENTORY, $conn);
        if (!$companyId) {
            $message = 'Select a company first.';
            $messageType = 'warning';
        } else {
            try {
                inv_pos_profile_delete($conn, $companyId, (int)($_POST['profile_id'] ?? 0));
                $message = 'POS profile removed.';
                $messageType = 'success';
            } catch (Throwable $e) {
                $message = $e->getMessage();
                $messageType = 'warning';
            }
        }
    } else {
        require_permission('inventory_locations.create', MODULE_INVENTORY, $conn);

        $code = strtoupper(trim($_POST['code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $type = trim($_POST['location_type'] ?? 'warehouse');

        if (!$companyId) {
            $message = 'Select a company first.';
            $messageType = 'warning';
        } elseif ($code === '' || $name === '') {
            $message = 'Code and name are required.';
            $messageType = 'warning';
        } else {
            try {
                $stmt = $conn->prepare("
                    INSERT INTO inv_locations (company_id, code, name, location_type, is_active)
                    VALUES (?,?,?,?,1)
                ");
                $stmt->execute([$companyId, $code, $name, $type]);
                $message = 'Location created.';
                $messageType = 'success';
            } catch (Throwable $e) {
                $message = 'Could not create location: ' . $e->getMessage();
                $messageType = 'warning';
            }
        }
    }
}

$invCoSettings = $companyId ? inv_get_company_settings($conn, $companyId) : [];
$defaultPosLocationId = isset($invCoSettings['default_pos_location_id']) ? (int)$invCoSettings['default_pos_location_id'] : 0;
$posProfiles = $companyId ? inv_pos_profiles_list($conn, $companyId) : [];

$locations = [];
if ($companyId) {
    $stmt = $conn->prepare("SELECT * FROM inv_locations WHERE company_id = ? ORDER BY id DESC LIMIT 200");
    $stmt->execute([$companyId]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Locations';
require_once __DIR__ . '/includes/inv_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-header-label">Locations</div>
</div>

<?php if ($message): ?>
  <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'warning' ? 'warning' : 'info') ?>"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($companyId && has_permission('inventory_locations.edit', MODULE_INVENTORY, $conn)): ?>
<div class="card p-3 mb-3" id="pos-profiles">
  <div class="fw-semibold mb-2">Retail POS — profiles (per store / register)</div>
  <p class="small text-muted mb-2">Create one profile per physical POS or store. Each profile has its own <strong>default selling location</strong> and a short <strong>URL code</strong>. Cashiers open
    <code>…/modules/grocery/pos_retail.php?pos=<em>yourcode</em></code> (bookmark it on that register). Carts are separate per profile so two stores do not share the same basket.</p>
  <?php
  $locPick = $locations;
  usort($locPick, function ($a, $b) {
      return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
  });
  ?>
  <form method="POST" class="row g-2 align-items-end mb-3 pb-3 border-bottom">
    <?php csrf_field(); ?>
    <div class="col-md-3">
      <label class="form-label">Profile name</label>
      <input class="form-control" name="profile_label" required maxlength="120" placeholder="Store 1 register">
    </div>
    <div class="col-md-2">
      <label class="form-label">URL code</label>
      <input class="form-control" name="profile_code" required maxlength="32" placeholder="store1" pattern="[A-Za-z0-9_-]+" title="Letters, numbers, hyphen, underscore">
      <div class="form-text">Used in <code>?pos=</code></div>
    </div>
    <div class="col-md-4">
      <label class="form-label">Default selling location</label>
      <select class="form-select" name="profile_location_id" required>
        <option value="">— Select —</option>
        <?php foreach ($locPick as $l): ?>
          <option value="<?= (int)$l['id'] ?>"><?= h($l['name']) ?> (<?= h($l['code']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <button type="submit" class="btn btn-primary" name="add_pos_profile" value="1">Add profile</button>
    </div>
  </form>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead>
        <tr>
          <th>Name</th>
          <th>URL code</th>
          <th>Default location</th>
          <th>Open POS</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php
        $retailPosPath = (function_exists('get_application_web_root') ? get_application_web_root() : '') . '/modules/grocery/pos_retail.php';
        foreach ($posProfiles as $pp):
            $href = $retailPosPath . '?pos=' . rawurlencode((string)$pp['profile_code']);
            ?>
          <tr>
            <td><?= h((string)$pp['label']) ?></td>
            <td><code><?= h((string)$pp['profile_code']) ?></code></td>
            <td><?= h((string)$pp['location_name']) ?> <span class="text-muted">(<?= h((string)$pp['location_code']) ?>)</span></td>
            <td><a class="btn btn-sm btn-outline-primary" href="<?= h($href) ?>" target="_blank" rel="noopener">Open</a></td>
            <td class="text-end">
              <form method="POST" class="d-inline" onsubmit="return confirm('Remove this POS profile?');">
                <?php csrf_field(); ?>
                <input type="hidden" name="profile_id" value="<?= (int)$pp['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" name="delete_pos_profile" value="1">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$posProfiles): ?>
          <tr><td colspan="5" class="text-muted">No profiles yet. Add one for each store (e.g. code <code>store1</code> → Store 1).</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">Retail POS — fallback default (no profile URL)</div>
  <p class="small text-muted mb-2">When a cashier opens <strong>Retail POS</strong> without <code>?pos=…</code>, this location is used automatically (if set). If you use only profile URLs, you can leave this empty so the generic POS page asks for a store or uses a single-location shortcut.</p>
  <form method="POST" class="row g-2 align-items-end">
    <?php csrf_field(); ?>
    <div class="col-md-6">
      <label class="form-label">Default location when <code>pos</code> is not in the URL</label>
      <select class="form-select" name="default_pos_location_id">
        <option value="">— Cashier chooses (no default) —</option>
        <?php foreach ($locPick as $l): ?>
          <option value="<?= (int)$l['id'] ?>" <?= $defaultPosLocationId === (int)$l['id'] ? 'selected' : '' ?>><?= h($l['name']) ?> (<?= h($l['code']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <button type="submit" class="btn btn-primary" name="save_default_pos_location" value="1">Save</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">Create location</div>
  <form method="POST" class="row g-2">
    <?php csrf_field(); ?>
    <div class="col-md-3">
      <label class="form-label">Code</label>
      <input class="form-control" name="code" required placeholder="WH1">
    </div>
    <div class="col-md-6">
      <label class="form-label">Name</label>
      <input class="form-control" name="name" required placeholder="Main Warehouse">
    </div>
    <div class="col-md-3">
      <label class="form-label">Type</label>
      <select class="form-select" name="location_type">
        <?php foreach (['warehouse','store','site','kitchen','maintenance','cleaning','ars'] as $t): ?>
          <option value="<?= h($t) ?>"><?= h(ucfirst($t)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12">
      <button class="btn btn-primary">Create</button>
    </div>
  </form>
</div>

<div class="card p-3">
  <div class="fw-semibold mb-2">Locations</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>ID</th>
          <th>Code</th>
          <th>Name</th>
          <th>Type</th>
          <th>Active</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($locations as $l): ?>
          <tr>
            <td><?= (int)$l['id'] ?></td>
            <td class="fw-semibold"><?= h($l['code']) ?></td>
            <td><?= h($l['name']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= h($l['location_type']) ?></span></td>
            <td><?= !empty($l['is_active']) ? 'Yes' : 'No' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$locations): ?>
          <tr><td colspan="5" class="text-muted">No locations yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>

