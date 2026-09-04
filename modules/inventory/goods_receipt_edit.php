<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/inventory/inv_purchasing.php';

require_login();
require_module_access($conn, MODULE_INVENTORY);
require_department_access(MODULE_INVENTORY, DEPT_INVENTORY, $conn);
require_permission('inventory_purchasing.view', MODULE_INVENTORY, $conn);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;
$grnId = (int)($_GET['id'] ?? 0);
$poIdParam = (int)($_GET['po_id'] ?? 0);
$message = $messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_from_po' && $poIdParam && !$grnId) {
    csrf_verify();
    require_permission('inventory_purchasing.create', MODULE_INVENTORY, $conn);
    $locId = (int)($_POST['location_to_id'] ?? 0);
    $rd = $_POST['receipt_date'] ?? date('Y-m-d');
    $ref = trim($_POST['reference_no'] ?? '');
    $over = !empty($_POST['allow_over_receipt']);
    $pstmt = $conn->prepare("SELECT * FROM inv_purchase_orders WHERE id = ? AND company_id = ? AND status = 'open'");
    $pstmt->execute([$poIdParam, $companyId]);
    $po = $pstmt->fetch(PDO::FETCH_ASSOC);
    if (!$po || !$locId) {
        $message = 'Open PO and receive location required.';
        $messageType = 'warning';
    } else {
        try {
            $newId = inv_grn_create($conn, [
                'company_id' => $companyId,
                'vendor_id' => (int)$po['vendor_id'],
                'po_id' => $poIdParam,
                'receipt_date' => $rd,
                'location_to_id' => $locId,
                'reference_no' => $ref !== '' ? $ref : null,
                'allow_over_receipt' => $over ? 1 : 0,
                'created_by' => current_user_id(),
            ]);
            header('Location: goods_receipt_edit.php?id=' . $newId);
            exit;
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'warning';
        }
    }
}

$grn = null;
if ($grnId) {
    $stmt = $conn->prepare("
        SELECT g.*, v.vendor_name, p.po_no
        FROM inv_goods_receipts g
        JOIN re_vendors v ON v.id = g.vendor_id AND v.company_id = g.company_id
        LEFT JOIN inv_purchase_orders p ON p.id = g.po_id AND p.company_id = g.company_id
        WHERE g.id = ? AND g.company_id = ?
    ");
    $stmt->execute([$grnId, $companyId]);
    $grn = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($poIdParam && !$grnId && !$message && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $chk = $conn->prepare("SELECT id, vendor_id, po_no FROM inv_purchase_orders WHERE id = ? AND company_id = ? AND status = 'open'");
    $chk->execute([$poIdParam, $companyId]);
    $poRow = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$poRow) {
        $message = 'Purchase order not found or not open.';
        $messageType = 'warning';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $grnId && $grn) {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $st = $grn['status'] ?? '';

    if ($action === 'patch_header' && $st === 'draft') {
        require_permission('inventory_purchasing.edit', MODULE_INVENTORY, $conn);
        $ref = trim($_POST['reference_no'] ?? '');
        $over = !empty($_POST['allow_over_receipt']) ? 1 : 0;
        $conn->prepare("UPDATE inv_goods_receipts SET reference_no = ?, allow_over_receipt = ?, updated_at = ? WHERE id = ? AND company_id = ?")
             ->execute([$ref !== '' ? $ref : null, $over, date('Y-m-d H:i:s'), $grnId, $companyId]);
        $message = 'Saved.';
        $messageType = 'success';
    } elseif ($action === 'add_line' && $st === 'draft') {
        require_permission('inventory_purchasing.edit', MODULE_INVENTORY, $conn);
        $qty = (float)($_POST['qty_received'] ?? 0);
        $ucIn = $_POST['unit_cost'] ?? '';
        $polId = (int)($_POST['po_line_id'] ?? 0);
        try {
            if ($polId) {
                $ps = $conn->prepare("SELECT * FROM inv_purchase_order_lines WHERE id = ? AND company_id = ?");
                $ps->execute([$polId, $companyId]);
                $pol = $ps->fetch(PDO::FETCH_ASSOC);
                if (!$pol) {
                    throw new RuntimeException('Invalid PO line');
                }
                if (!empty($grn['po_id']) && (int)$pol['header_id'] !== (int)$grn['po_id']) {
                    throw new RuntimeException('PO line does not match this GRN');
                }
                $itemId = (int)$pol['item_id'];
                $uomId = (int)$pol['uom_id'];
                $unitCost = $ucIn !== '' ? (float)$ucIn : (float)$pol['unit_price'];
            } else {
                if (!empty($grn['po_id'])) {
                    throw new RuntimeException('Select a PO line for this GRN');
                }
                $itemId = (int)($_POST['item_id'] ?? 0);
                $uomId = (int)($_POST['uom_id'] ?? 0);
                $unitCost = $ucIn !== '' ? (float)$ucIn : 0;
                if (!$itemId || !$uomId || $qty <= 0) {
                    throw new RuntimeException('Item, UoM, and positive qty required');
                }
            }
            if ($qty <= 0) {
                throw new RuntimeException('Qty must be positive');
            }
            if ($unitCost < 0) {
                throw new RuntimeException('Unit cost must be >= 0');
            }
            inv_grn_add_line($conn, $grnId, [
                'po_line_id' => $polId ?: null,
                'item_id' => $itemId,
                'uom_id' => $uomId,
                'qty_received' => $qty,
                'unit_cost' => $unitCost,
                'lot_number' => trim($_POST['lot_number'] ?? '') ?: null,
                'expiry_date' => trim($_POST['expiry_date'] ?? '') ?: null,
                'serial_number' => trim($_POST['serial_number'] ?? '') ?: null,
            ]);
            $message = 'Line added.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'warning';
        }
    } elseif ($action === 'post' && $st === 'draft') {
        require_permission('inventory_purchasing.post', MODULE_INVENTORY, $conn);
        $allowNeg = has_permission('inventory_adjustments.post', MODULE_INVENTORY, $conn);
        $res = inv_grn_post($conn, $grnId, current_user_id() ?: 0, $allowNeg);
        if (!empty($res['success'])) {
            $message = 'GRN posted. Inventory receipt #' . (int)($res['inventory_doc_id'] ?? 0);
            $messageType = 'success';
        } else {
            $message = $res['error'] ?? 'Post failed';
            $messageType = 'warning';
        }
    } elseif ($action === 'reversal_draft' && $st === 'posted') {
        require_permission('inventory_purchasing.edit', MODULE_INVENTORY, $conn);
        try {
            $docNew = inv_create_reversal_receipt_for_grn($conn, $grnId, current_user_id() ?: 0);
            header('Location: document_edit.php?id=' . $docNew);
            exit;
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'warning';
        }
    }

    if ($grnId) {
        $stmt = $conn->prepare("
            SELECT g.*, v.vendor_name, p.po_no
            FROM inv_goods_receipts g
            JOIN re_vendors v ON v.id = g.vendor_id AND v.company_id = g.company_id
            LEFT JOIN inv_purchase_orders p ON p.id = g.po_id AND p.company_id = g.company_id
            WHERE g.id = ? AND g.company_id = ?
        ");
        $stmt->execute([$grnId, $companyId]);
        $grn = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

$lines = [];
$polOptions = [];
$items = [];
$uoms = [];
if ($grnId && $grn) {
    $lstmt = $conn->prepare("
        SELECT l.*, i.item_code, i.name AS item_name
        FROM inv_goods_receipt_lines l
        JOIN inv_items i ON i.id = l.item_id
        WHERE l.header_id = ?
        ORDER BY l.id
    ");
    $lstmt->execute([$grnId]);
    $lines = $lstmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!empty($grn['po_id'])) {
    $poid = (int)$grn['po_id'];
    $stmt = $conn->prepare("
        SELECT pol.id, pol.item_id, pol.uom_id, pol.qty_ordered, pol.qty_received, pol.unit_price,
               i.item_code, i.name AS item_name,
               (pol.qty_ordered - pol.qty_received) AS open_qty
        FROM inv_purchase_order_lines pol
        JOIN inv_items i ON i.id = pol.item_id
        WHERE pol.header_id = ?
          AND (pol.qty_ordered - pol.qty_received) > 0.0001
        ORDER BY pol.id
    ");
    $stmt->execute([$poid]);
    $polOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($companyId) {
    $stmt = $conn->prepare("SELECT id, item_code, name, base_uom_id FROM inv_items WHERE company_id = ? AND is_active = 1 ORDER BY name LIMIT 500");
    $stmt->execute([$companyId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $uoms = $conn->query("SELECT id, code FROM inv_uoms WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

$locs = [];
if ($companyId) {
    $stmt = $conn->prepare("SELECT id, code, name FROM inv_locations WHERE company_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([$companyId]);
    $locs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = $grn ? $grn['grn_no'] : 'New GRN';
require_once __DIR__ . '/includes/inv_layout_header.php';
?>

<?php if ($poIdParam && !$grnId): ?>
  <div class="page-header-label mb-3">New GRN from PO</div>
  <?php if ($message): ?><div class="alert alert-warning"><?= h($message) ?></div><?php endif; ?>
  <?php
    $chk = $conn->prepare("SELECT po_no FROM inv_purchase_orders WHERE id = ? AND company_id = ? AND status = 'open'");
    $chk->execute([$poIdParam, $companyId]);
    $pon = $chk->fetchColumn();
  ?>
  <?php if ($pon): ?>
  <div class="card p-3">
    <p class="text-muted">PO <strong><?= h($pon) ?></strong></p>
    <form method="POST" class="row g-2">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="create_from_po">
      <div class="col-md-4">
        <label class="form-label">Receive into</label>
        <select class="form-select" name="location_to_id" required>
          <?php foreach ($locs as $l): ?>
            <option value="<?= (int)$l['id'] ?>"><?= h($l['code'] . ' — ' . $l['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Receipt date</label>
        <input class="form-control" type="date" name="receipt_date" value="<?= h(date('Y-m-d')) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Reference</label>
        <input class="form-control" name="reference_no" placeholder="Delivery note / invoice #">
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="allow_over_receipt" id="ov2">
          <label class="form-check-label" for="ov2">Allow receive over PO qty</label>
        </div>
      </div>
      <div class="col-12">
        <button class="btn btn-primary">Create draft GRN</button>
        <a class="btn btn-outline-secondary ms-2" href="purchase_order_edit.php?id=<?= (int)$poIdParam ?>">Back to PO</a>
      </div>
    </form>
  </div>
  <?php else: ?>
    <div class="alert alert-warning">PO not open or not found. Open the PO from the PO screen before receiving.</div>
    <p><a href="purchase_order_edit.php?id=<?= (int)$poIdParam ?>">Back to PO</a></p>
  <?php endif; ?>
<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; exit; endif; ?>

<?php if (!$grn): ?>
  <div class="alert alert-warning">GRN not found.</div>
  <p><a href="goods_receipts.php">Back</a></p>
  <?php require_once __DIR__ . '/includes/inv_layout_footer.php'; exit; endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="page-header-label"><?= h($grn['grn_no']) ?></div>
    <div class="text-muted small"><?= h($grn['vendor_name']) ?> · <?= h($grn['receipt_date']) ?>
      <?php if (!empty($grn['po_no'])): ?> · PO <?= h($grn['po_no']) ?><?php endif; ?>
    </div>
  </div>
  <span class="badge bg-secondary"><?= h($grn['status']) ?></span>
</div>

<?php if ($message): ?>
  <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'warning' ?>"><?= h($message) ?></div>
<?php endif; ?>

<?php if (($grn['status'] ?? '') === 'draft' && has_permission('inventory_purchasing.edit', MODULE_INVENTORY, $conn)): ?>
<div class="card p-3 mb-3">
  <form method="POST" class="row g-2 align-items-end">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="patch_header">
    <div class="col-md-4">
      <label class="form-label">Reference (delivery note / invoice)</label>
      <input class="form-control" name="reference_no" value="<?= h($grn['reference_no'] ?? '') ?>" placeholder="Optional">
    </div>
    <div class="col-md-3">
      <div class="form-check mt-4">
        <input class="form-check-input" type="checkbox" name="allow_over_receipt" id="ov3" <?= !empty($grn['allow_over_receipt']) ? 'checked' : '' ?>>
        <label class="form-check-label" for="ov3">Allow receive over PO qty</label>
      </div>
    </div>
    <div class="col-md-2">
      <button class="btn btn-outline-primary">Save header</button>
    </div>
  </form>
</div>

<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">Add line</div>
  <form method="POST" class="row g-2">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="add_line">
    <?php if (!empty($grn['po_id']) && $polOptions): ?>
      <div class="col-12">
        <label class="form-label">PO line (remaining &gt; 0)</label>
        <select class="form-select" name="po_line_id" required>
          <option value="">— Select —</option>
          <?php foreach ($polOptions as $po): ?>
            <option value="<?= (int)$po['id'] ?>" data-defcost="<?= h((float)$po['unit_price']) ?>">
              <?= h($po['item_code']) ?> — <?= h($po['item_name']) ?> (open <?= number_format((float)$po['open_qty'], 4) ?>, PO price <?= number_format((float)$po['unit_price'], 4) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php elseif (!empty($grn['po_id'])): ?>
      <p class="text-warning">No open qty left on PO lines.</p>
    <?php else: ?>
      <div class="col-md-4">
        <select class="form-select" name="item_id" required>
          <option value="">Item</option>
          <?php foreach ($items as $it): ?>
            <option value="<?= (int)$it['id'] ?>"><?= h($it['item_code'] . ' — ' . $it['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select class="form-select" name="uom_id" required>
          <?php foreach ($uoms as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= h($u['code']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="col-md-2">
      <label class="form-label">Qty</label>
      <input class="form-control" type="number" step="0.0001" name="qty_received" required>
    </div>
    <div class="col-md-2">
      <label class="form-label">Unit cost</label>
      <input class="form-control" type="number" step="0.0001" name="unit_cost" placeholder="Default: PO price">
    </div>
    <div class="col-md-2">
      <label class="form-label">Lot</label>
      <input class="form-control" name="lot_number">
    </div>
    <div class="col-md-2">
      <label class="form-label">Expiry</label>
      <input class="form-control" type="date" name="expiry_date">
    </div>
    <div class="col-md-3">
      <label class="form-label">Serial</label>
      <input class="form-control" name="serial_number">
    </div>
    <div class="col-12">
      <button class="btn btn-primary">Add line</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if (($grn['status'] ?? '') === 'draft' && has_permission('inventory_purchasing.post', MODULE_INVENTORY, $conn)): ?>
<form method="POST" onsubmit="return confirm('Post this GRN to inventory?');">
  <?php csrf_field(); ?>
  <input type="hidden" name="action" value="post">
  <button class="btn btn-success">Post GRN</button>
</form>
<?php endif; ?>

<?php if (($grn['status'] ?? '') === 'posted'): ?>
  <p>
    <?php if (!empty($grn['inventory_doc_id'])): ?>
      <a class="btn btn-outline-primary" href="document_edit.php?id=<?= (int)$grn['inventory_doc_id'] ?>">View inventory receipt</a>
    <?php endif; ?>
    <?php if (has_permission('inventory_purchasing.edit', MODULE_INVENTORY, $conn)): ?>
    <form method="POST" class="d-inline" onsubmit="return confirm('Create a draft negative receipt for reversal? Post it from Documents.');">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="reversal_draft">
      <button class="btn btn-outline-warning">Create reversal receipt (negative)</button>
    </form>
    <?php endif; ?>
  </p>
  <p class="small text-muted">Posted GRNs cannot be edited. Wrong receipt? Post the reversal draft, then create a new GRN.</p>
<?php endif; ?>

<div class="card p-3 mt-3">
  <div class="fw-semibold mb-2">Lines</div>
  <table class="table table-sm">
    <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Unit cost</th></tr></thead>
    <tbody>
      <?php foreach ($lines as $ln): ?>
        <tr>
          <td><?= h($ln['item_code']) ?> — <?= h($ln['item_name']) ?></td>
          <td class="text-end"><?= number_format((float)$ln['qty_received'], 4) ?></td>
          <td class="text-end"><?= number_format((float)$ln['unit_cost'], 4) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$lines): ?>
        <tr><td colspan="3" class="text-muted">No lines.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<p class="mt-3"><a href="goods_receipts.php">Back to list</a></p>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>
