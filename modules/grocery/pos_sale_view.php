<?php
/**
 * Single POS sale detail — lines, VAT, inventory doc link, receipt.
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
require_once __DIR__ . '/../../includes/url_helper.php';

require_login();
require_module_access($conn, MODULE_GROCERY);
require_grocery_backoffice_department($conn);
require_permission('grocery_reports.view', MODULE_GROCERY, $conn);

ensure_current_company_supports_module($conn, MODULE_GROCERY);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;
$appBase = get_application_web_root();
$saleId = (int)($_GET['id'] ?? 0);

$sale = null;
$lines = [];
$invDoc = null;

if ($companyId && $saleId) {
    $st = $conn->prepare("
        SELECT ps.*, COALESCE(u.fullname, u.username) AS cashier_name, loc.name AS location_name
        FROM pos_sales ps
        LEFT JOIN user u ON u.id = ps.posted_by
        LEFT JOIN inv_locations loc ON loc.id = ps.location_id AND loc.company_id = ps.company_id
        WHERE ps.id = ? AND ps.company_id = ?
        LIMIT 1
    ");
    $st->execute([$saleId, $companyId]);
    $sale = $st->fetch(PDO::FETCH_ASSOC);
    if ($sale) {
        $st = $conn->prepare("
            SELECT l.*, i.item_code, i.name AS item_name
            FROM pos_sale_lines l
            JOIN inv_items i ON i.id = l.item_id AND i.company_id = l.company_id
            WHERE l.header_id = ? AND l.company_id = ?
            ORDER BY l.line_no
        ");
        $st->execute([$saleId, $companyId]);
        $lines = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($sale['inv_doc_id'])) {
            $d = $conn->prepare("SELECT id, doc_no, doc_type, status FROM inv_doc_headers WHERE id = ? AND company_id = ? LIMIT 1");
            $d->execute([(int)$sale['inv_doc_id'], $companyId]);
            $invDoc = $d->fetch(PDO::FETCH_ASSOC);
        }
    }
}

$pageTitle = 'POS sale';
require_once __DIR__ . '/includes/grocery_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-header-label">POS sale</div>
  <a class="btn btn-outline-secondary btn-sm" href="<?= h($appBase) ?>/modules/grocery/pos_sales_list.php">← Sales list</a>
</div>

<?php if (!$sale): ?>
  <div class="alert alert-warning">Sale not found.</div>
<?php else: ?>

<div class="card p-3 mb-3">
  <div class="row">
    <div class="col-md-6">
      <div><strong>Sale no.</strong> <?= h($sale['sale_no']) ?></div>
      <div><strong>Status</strong> <span class="badge bg-light text-dark border"><?= h($sale['status']) ?></span></div>
      <div><strong>Time</strong> <?= h($sale['posted_at'] ?? $sale['created_at']) ?></div>
      <div><strong>Cashier</strong> <?= h($sale['cashier_name'] ?: '—') ?></div>
      <div><strong>Location</strong> <?= h($sale['location_name'] ?: '—') ?></div>
      <div><strong>Payment</strong> <?= h($sale['payment_method'] ?: '—') ?></div>
      <div><strong>Source</strong> <?= h($sale['source_module'] ?: '—') ?></div>
    </div>
    <div class="col-md-6">
      <div><strong>Subtotal (ex VAT)</strong> <?= number_format((float)$sale['subtotal_excl'], 2) ?></div>
      <div><strong>VAT</strong> <?= number_format((float)$sale['tax_total'], 2) ?></div>
      <div><strong>Total (incl.)</strong> <?= number_format((float)$sale['grand_total_incl'], 2) ?></div>
      <?php if (!empty($sale['notes'])): ?>
        <div><strong>Notes</strong> <?= h($sale['notes']) ?></div>
      <?php endif; ?>
      <div class="mt-2">
        <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="<?= h($appBase) ?>/modules/grocery/pos_receipt.php?id=<?= (int)$sale['id'] ?>">Print receipt</a>
        <?php if ($invDoc): ?>
          <a class="btn btn-sm btn-outline-success" href="<?= h($appBase) ?>/modules/inventory/document_edit.php?id=<?= (int)$invDoc['id'] ?>">Inventory doc <?= h($invDoc['doc_no']) ?> (<?= h($invDoc['status']) ?>)</a>
        <?php else: ?>
          <span class="text-muted small">No inventory document (service-only or no stock lines).</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card p-3">
  <div class="fw-semibold mb-2">Lines</div>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead>
        <tr>
          <th>#</th>
          <th>SKU</th>
          <th>Item</th>
          <th class="text-end">Qty</th>
          <th class="text-end">Unit ex VAT</th>
          <th class="text-end">VAT %</th>
          <th class="text-end">Line total (incl.)</th>
          <th>Service</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $ln): ?>
          <tr>
            <td><?= (int)$ln['line_no'] ?></td>
            <td><?= h($ln['item_code']) ?></td>
            <td>
              <?= h($ln['item_name']) ?>
              <a class="small" href="<?= h($appBase) ?>/modules/grocery/pos_report_item.php?item_id=<?= (int)$ln['item_id'] ?>&amp;preset=today">Item history</a>
            </td>
            <td class="text-end"><?= h(rtrim(rtrim(number_format((float)$ln['qty'], 4, '.', ''), '0'), '.')) ?></td>
            <td class="text-end"><?= number_format((float)$ln['unit_price_excl'], 4) ?></td>
            <td class="text-end"><?= number_format((float)$ln['vat_rate'], 3) ?></td>
            <td class="text-end"><?= number_format((float)$ln['line_total_incl'], 2) ?></td>
            <td><?= !empty($ln['is_service']) ? 'Yes' : 'No' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/grocery_layout_footer.php'; ?>
