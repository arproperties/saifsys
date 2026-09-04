<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/inventory/inv_posting.php';
require_once __DIR__ . '/../../includes/inventory/inv_helpers.php';

require_login();
require_module_access($conn, MODULE_INVENTORY);
require_department_access(MODULE_INVENTORY, DEPT_INVENTORY, $conn);
require_permission('inventory_docs.view', MODULE_INVENTORY, $conn);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;
$docId = (int)($_GET['id'] ?? 0);
$message = $messageType = '';

if (!$docId) { header('Location: documents.php'); exit; }

$hdrStmt = $conn->prepare("SELECT * FROM inv_doc_headers WHERE id = ? AND company_id = ? LIMIT 1");
$hdrStmt->execute([$docId, $companyId]);
$doc = $hdrStmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) { header('Location: documents.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_line') {
        require_permission('inventory_docs.edit', MODULE_INVENTORY, $conn);
        if (($doc['status'] ?? '') !== 'draft') {
            $message = 'Only draft documents can be edited.';
            $messageType = 'warning';
        } else {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $uomId  = (int)($_POST['uom_id'] ?? 0);
            $qty    = (float)($_POST['qty'] ?? 0);
            $unitCost = ($_POST['unit_cost'] ?? '') !== '' ? (float)$_POST['unit_cost'] : null;
            $lotNo  = trim($_POST['lot_number'] ?? '');
            $exp    = trim($_POST['expiry_date'] ?? '');
            $serNo  = trim($_POST['serial_number'] ?? '');

            try {
                $lf = (int)($_POST['location_from_id'] ?? 0);
                $lt = (int)($_POST['location_to_id'] ?? 0);
                inv_add_line($conn, $docId, [
                    'item_id' => $itemId,
                    'uom_id' => $uomId,
                    'qty' => $qty,
                    'unit_cost' => $unitCost,
                    'lot_number' => $lotNo ?: null,
                    'expiry_date' => $exp ?: null,
                    'serial_number' => $serNo ?: null,
                    'location_from_id' => $lf ?: null,
                    'location_to_id' => $lt ?: null,
                ]);
                $message = 'Line added.';
                $messageType = 'success';
            } catch (Throwable $e) {
                $message = 'Could not add line: ' . $e->getMessage();
                $messageType = 'warning';
            }
        }
    } elseif ($action === 'post') {
        require_permission('inventory_docs.post', MODULE_INVENTORY, $conn);
        $res = inv_post_doc($conn, $docId, current_user_id() ?: 0, has_permission('inventory_adjustments.post', MODULE_INVENTORY, $conn));
        if (!empty($res['success'])) {
            $message = 'Document posted.';
            $messageType = 'success';
        } else {
            $message = 'Could not post: ' . ($res['error'] ?? 'Unknown error');
            $messageType = 'warning';
        }
    }

    // reload header after actions
    $hdrStmt->execute([$docId, $companyId]);
    $doc = $hdrStmt->fetch(PDO::FETCH_ASSOC);
}

$linesStmt = $conn->prepare("SELECT l.*, i.item_code, i.name AS item_name, i.track_lot, i.track_expiry, i.track_serial FROM inv_doc_lines l JOIN inv_items i ON i.id = l.item_id WHERE l.header_id = ? ORDER BY l.id ASC");
$linesStmt->execute([$docId]);
$lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);

$items = [];
$uoms = [];
$locations = [];
if ($companyId) {
    $stmt = $conn->prepare("SELECT id, item_code, name, base_uom_id FROM inv_items WHERE company_id = ? AND is_active = 1 ORDER BY name LIMIT 500");
    $stmt->execute([$companyId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $uoms = $conn->query("SELECT id, code, name FROM inv_uoms WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $conn->prepare("SELECT id, code, name FROM inv_locations WHERE company_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([$companyId]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$locById = [];
foreach ($locations as $l) {
    $locById[(int)$l['id']] = $l['code'] . ' — ' . $l['name'];
}

$pageTitle = 'Document ' . ($doc['doc_no'] ?? '');
require_once __DIR__ . '/includes/inv_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="page-header-label"><?= h($doc['doc_no']) ?></div>
    <div class="text-muted small"><?= h($doc['doc_type']) ?> · <?= h($doc['doc_date']) ?></div>
  </div>
  <div class="text-end">
    <?php $st = $doc['status'] ?? 'draft'; ?>
    <span class="badge bg-<?= $st === 'posted' ? 'success' : ($st === 'void' ? 'danger' : 'secondary') ?>"><?= h($st) ?></span>
    <?php if (($doc['doc_type'] ?? '') === 'issue' && !empty($doc['is_emergency_issue'])): ?>
      <div class="mt-1"><span class="badge bg-warning text-dark">Emergency manual issue</span></div>
    <?php endif; ?>
  </div>
</div>

<?php if ($message): ?>
  <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'warning' ? 'warning' : 'info') ?>"><?= h($message) ?></div>
<?php endif; ?>

<?php if (($doc['status'] ?? '') === 'draft'): ?>
<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">Add line</div>
  <p class="small text-muted mb-2">
    <strong>Receipt / opening:</strong> set <em>To</em> location. <strong>Issue / sale / wastage / return:</strong> <em>From</em>.
    <strong>Transfer:</strong> both. <strong>Stock take:</strong> counted qty in <em>Qty</em>, <em>To</em> = count location; optional unit cost on positive variance.
  </p>
  <form method="POST" class="row g-2">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="add_line">
    <div class="col-md-5">
      <label class="form-label">Item</label>
      <select class="form-select" name="item_id" required>
        <option value="">— Select —</option>
        <?php foreach ($items as $it): ?>
          <option value="<?= (int)$it['id'] ?>"><?= h($it['item_code'] . ' — ' . $it['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">UoM</label>
      <select class="form-select" name="uom_id" required>
        <option value="">—</option>
        <?php foreach ($uoms as $u): ?>
          <option value="<?= (int)$u['id'] ?>"><?= h($u['code']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Qty</label>
      <input class="form-control" type="number" step="0.0001" name="qty" required>
      <div class="form-text">Use negative qty for adjustment out.</div>
    </div>
    <div class="col-md-3">
      <label class="form-label">Unit cost</label>
      <input class="form-control" type="number" step="0.0001" min="0" name="unit_cost" placeholder="receipt / opening / adj+">
    </div>
    <div class="col-md-3">
      <label class="form-label">From location</label>
      <select class="form-select" name="location_from_id">
        <option value="">—</option>
        <?php foreach ($locations as $l): ?>
          <option value="<?= (int)$l['id'] ?>"><?= h($l['code'] . ' — ' . $l['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">To location</label>
      <select class="form-select" name="location_to_id">
        <option value="">—</option>
        <?php foreach ($locations as $l): ?>
          <option value="<?= (int)$l['id'] ?>"><?= h($l['code'] . ' — ' . $l['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Lot number</label>
      <input class="form-control" name="lot_number" placeholder="optional">
    </div>
    <div class="col-md-3">
      <label class="form-label">Expiry date</label>
      <input class="form-control" type="date" name="expiry_date">
    </div>
    <div class="col-md-5">
      <label class="form-label">Serial number</label>
      <input class="form-control" name="serial_number" placeholder="optional (qty must be 1)">
    </div>
    <div class="col-12">
      <button class="btn btn-primary">Add line</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">Lines</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>#</th>
          <th>Item</th>
          <th class="text-end">Qty</th>
          <th>From</th>
          <th>To</th>
          <th>Lot</th>
          <th>Expiry</th>
          <th>Serial</th>
          <th class="text-end">Unit cost</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $ln): ?>
          <tr>
            <td><?= (int)$ln['id'] ?></td>
            <td class="fw-semibold"><?= h($ln['item_code']) ?> <span class="text-muted">— <?= h($ln['item_name']) ?></span></td>
            <td class="text-end"><?= number_format((float)$ln['qty'], 4) ?></td>
            <td><?= $ln['location_from_id'] ? h($locById[(int)$ln['location_from_id']] ?? ('#' . (int)$ln['location_from_id'])) : '—' ?></td>
            <td><?= $ln['location_to_id'] ? h($locById[(int)$ln['location_to_id']] ?? ('#' . (int)$ln['location_to_id'])) : '—' ?></td>
            <td><?= h($ln['lot_number'] ?: '—') ?></td>
            <td><?= h($ln['expiry_date'] ?: '—') ?></td>
            <td><?= h($ln['serial_number'] ?: '—') ?></td>
            <td class="text-end"><?= $ln['unit_cost'] !== null ? number_format((float)$ln['unit_cost'], 4) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$lines): ?>
          <tr><td colspan="9" class="text-muted">No lines yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (($doc['status'] ?? '') === 'draft'): ?>
<form method="POST">
  <?php csrf_field(); ?>
  <input type="hidden" name="action" value="post">
  <button class="btn btn-success" onclick="return confirm('Post this document? This will update stock.');">Post document</button>
  <a class="btn btn-outline-secondary ms-2" href="documents.php">Back</a>
</form>
<?php else: ?>
  <a class="btn btn-outline-secondary" href="documents.php">Back</a>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>

