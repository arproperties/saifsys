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
$poId = (int)($_GET['id'] ?? 0);
$message = $messageType = '';

if (!$poId) {
    header('Location: purchase_orders.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT p.*, v.vendor_name
    FROM inv_purchase_orders p
    JOIN re_vendors v ON v.id = p.vendor_id AND v.company_id = p.company_id
    WHERE p.id = ? AND p.company_id = ?
");
$stmt->execute([$poId, $companyId]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$po) {
    header('Location: purchase_orders.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_line' && ($po['status'] ?? '') === 'draft') {
        require_permission('inventory_purchasing.edit', MODULE_INVENTORY, $conn);
        $itemId = (int)($_POST['item_id'] ?? 0);
        $uomId = (int)($_POST['uom_id'] ?? 0);
        $qty = (float)($_POST['qty_ordered'] ?? 0);
        $price = (float)($_POST['unit_price'] ?? 0);
        if (!$itemId || !$uomId || $qty <= 0) {
            $message = 'Item, UoM, and positive qty required.';
            $messageType = 'warning';
        } else {
            try {
                inv_po_add_line($conn, $poId, [
                    'item_id' => $itemId,
                    'uom_id' => $uomId,
                    'qty_ordered' => $qty,
                    'unit_price' => $price,
                ]);
                $message = 'Line added.';
                $messageType = 'success';
            } catch (Throwable $e) {
                $message = $e->getMessage();
                $messageType = 'warning';
            }
        }
    } elseif ($action === 'open' && ($po['status'] ?? '') === 'draft') {
        require_permission('inventory_purchasing.edit', MODULE_INVENTORY, $conn);
        $conn->prepare("UPDATE inv_purchase_orders SET status='open', updated_at=? WHERE id=? AND company_id=?")
             ->execute([date('Y-m-d H:i:s'), $poId, $companyId]);
        $message = 'PO is now Open for receiving.';
        $messageType = 'success';
    } elseif ($action === 'close' && ($po['status'] ?? '') === 'open') {
        require_permission('inventory_purchasing.edit', MODULE_INVENTORY, $conn);
        $conn->prepare("UPDATE inv_purchase_orders SET status='closed', updated_at=? WHERE id=? AND company_id=?")
             ->execute([date('Y-m-d H:i:s'), $poId, $companyId]);
        $message = 'PO closed.';
        $messageType = 'success';
    } elseif ($action === 'cancel' && in_array($po['status'] ?? '', ['draft', 'open'], true)) {
        require_permission('inventory_purchasing.edit', MODULE_INVENTORY, $conn);
        $chk = $conn->prepare("SELECT SUM(qty_received) FROM inv_purchase_order_lines WHERE header_id = ?");
        $chk->execute([$poId]);
        $recv = (float)$chk->fetchColumn();
        if ($recv > 0.0001) {
            $message = 'Cannot cancel: lines already received.';
            $messageType = 'warning';
        } else {
            $conn->prepare("UPDATE inv_purchase_orders SET status='cancelled', updated_at=? WHERE id=? AND company_id=?")
                 ->execute([date('Y-m-d H:i:s'), $poId, $companyId]);
            $message = 'PO cancelled.';
            $messageType = 'success';
        }
    }

    $stmt->execute([$poId, $companyId]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);
}

$lines = [];
$lstmt = $conn->prepare("
    SELECT l.*, i.item_code, i.name AS item_name
    FROM inv_purchase_order_lines l
    JOIN inv_items i ON i.id = l.item_id
    WHERE l.header_id = ?
    ORDER BY l.id
");
$lstmt->execute([$poId]);
$lines = $lstmt->fetchAll(PDO::FETCH_ASSOC);

$items = [];
$uoms = [];
if ($companyId) {
    $stmt = $conn->prepare("SELECT id, item_code, name, base_uom_id FROM inv_items WHERE company_id = ? AND is_active = 1 ORDER BY name LIMIT 500");
    $stmt->execute([$companyId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $uoms = $conn->query("SELECT id, code, name FROM inv_uoms WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = $po['po_no'];
require_once __DIR__ . '/includes/inv_layout_header.php';
$st = $po['status'] ?? '';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="page-header-label"><?= h($po['po_no']) ?></div>
    <div class="text-muted small"><?= h($po['vendor_name']) ?> · <?= h($po['po_date']) ?></div>
  </div>
  <span class="badge bg-secondary"><?= h($st) ?></span>
</div>

<?php if ($message): ?>
  <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'warning' ?>"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($st === 'draft' && has_permission('inventory_purchasing.edit', MODULE_INVENTORY, $conn)): ?>
<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">Add line</div>
  <form method="POST" class="row g-2">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="add_line">
    <div class="col-md-4">
      <select class="form-select" name="item_id" required>
        <option value="">Item</option>
        <?php foreach ($items as $it): ?>
          <option value="<?= (int)$it['id'] ?>"><?= h($it['item_code'] . ' — ' . $it['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select class="form-select" name="uom_id" required><option value="">UoM</option>
        <?php foreach ($uoms as $u): ?>
          <option value="<?= (int)$u['id'] ?>"><?= h($u['code']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <input class="form-control" type="number" step="0.0001" name="qty_ordered" placeholder="Qty" required>
    </div>
    <div class="col-md-2">
      <input class="form-control" type="number" step="0.01" name="unit_price" value="0" placeholder="Unit price">
    </div>
    <div class="col-md-2">
      <button class="btn btn-primary">Add</button>
    </div>
  </form>
  <form method="POST" class="mt-2">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="open">
    <button class="btn btn-success" <?= !$lines ? 'disabled' : '' ?>>Mark open for receiving</button>
  </form>
</div>
<?php elseif ($st === 'open'): ?>
<div class="card p-3 mb-3">
  <a class="btn btn-primary" href="goods_receipt_edit.php?po_id=<?= (int)$poId ?>">New goods receipt (GRN)</a>
  <form method="POST" class="d-inline ms-2" onsubmit="return confirm('Close this PO?');">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="close">
    <button class="btn btn-outline-secondary">Close PO</button>
  </form>
</div>
<?php endif; ?>

<?php if (in_array($st, ['draft', 'open'], true) && has_permission('inventory_purchasing.edit', MODULE_INVENTORY, $conn)): ?>
<div class="mb-3">
  <form method="POST" class="d-inline" onsubmit="return confirm('Cancel this PO?');">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="cancel">
    <button class="btn btn-outline-danger btn-sm">Cancel PO</button>
  </form>
</div>
<?php endif; ?>

<div class="card p-3">
  <div class="fw-semibold mb-2">Lines</div>
  <table class="table table-sm">
    <thead><tr><th>Item</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Unit price</th><th class="text-end">Open</th></tr></thead>
    <tbody>
      <?php foreach ($lines as $ln): ?>
        <?php
          $open = round((float)$ln['qty_ordered'] - (float)$ln['qty_received'], 4);
        ?>
        <tr>
          <td><?= h($ln['item_code']) ?> — <?= h($ln['item_name']) ?></td>
          <td class="text-end"><?= number_format((float)$ln['qty_ordered'], 4) ?></td>
          <td class="text-end"><?= number_format((float)$ln['qty_received'], 4) ?></td>
          <td class="text-end"><?= number_format((float)$ln['unit_price'], 4) ?></td>
          <td class="text-end"><?= number_format($open, 4) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$lines): ?>
        <tr><td colspan="5" class="text-muted">No lines.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<p class="mt-3"><a href="purchase_orders.php">Back</a></p>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>
