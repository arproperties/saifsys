<?php
/**
 * Printable receipt for a posted POS sale (retail / API).
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

require_login();
require_module_access($conn, MODULE_GROCERY);
require_grocery_pos_department($conn);
require_permission('grocery_pos.view', MODULE_GROCERY, $conn);

ensure_current_company_supports_module($conn, MODULE_GROCERY);

$companyId = current_company_id($conn) ?: 0;
$saleId = (int)($_GET['id'] ?? 0);
if ($companyId <= 0 || $saleId <= 0) {
    http_response_code(400);
    echo 'Invalid request';
    exit;
}

$st = $conn->prepare("SELECT * FROM pos_sales WHERE id = ? AND company_id = ? LIMIT 1");
$st->execute([$saleId, $companyId]);
$sale = $st->fetch(PDO::FETCH_ASSOC);
if (!$sale) {
    http_response_code(404);
    echo 'Sale not found';
    exit;
}

$st = $conn->prepare("
    SELECT l.*, i.name AS item_name, i.item_code
    FROM pos_sale_lines l
    JOIN inv_items i ON i.id = l.item_id AND i.company_id = l.company_id
    WHERE l.header_id = ? AND l.company_id = ?
    ORDER BY l.line_no ASC
");
$st->execute([$saleId, $companyId]);
$lines = $st->fetchAll(PDO::FETCH_ASSOC);

$brand = getBrandSettings($conn);
$name = $brand['system_name'] ?? 'POS';

if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Receipt <?= h($sale['sale_no'] ?? '') ?></title>
  <style>
    body { font-family: system-ui, Segoe UI, sans-serif; margin: 0; padding: 16px; color: #111; font-size: 14px; }
    .rcpt { max-width: 360px; margin: 0 auto; }
    .rcpt h1 { font-size: 1.1rem; margin: 0 0 4px; text-align: center; }
    .rcpt .meta { text-align: center; font-size: 12px; color: #444; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 6px 4px; text-align: left; border-bottom: 1px solid #eee; }
    th { font-size: 11px; text-transform: uppercase; color: #666; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .totals { margin-top: 12px; font-size: 13px; }
    .totals div { display: flex; justify-content: space-between; padding: 4px 0; }
    .totals .grand { font-weight: 700; font-size: 1.05rem; border-top: 2px solid #111; margin-top: 8px; padding-top: 8px; }
    @media print {
      body { padding: 8px; }
      .no-print { display: none; }
    }
  </style>
</head>
<body>
  <div class="rcpt">
    <h1><?= h($name) ?></h1>
    <div class="meta">
      <?= h($sale['sale_no'] ?? '') ?><br>
      <?= h($sale['posted_at'] ?? $sale['created_at'] ?? '') ?>
      <?php if (!empty($sale['payment_method'])): ?>
        <br><?= h(ucfirst((string)$sale['payment_method'])) ?>
      <?php endif; ?>
    </div>
    <table>
      <thead>
        <tr>
          <th>Item</th>
          <th class="num">Qty</th>
          <th class="num">Incl.</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $ln): ?>
          <tr>
            <td><?= h($ln['item_name'] ?? '') ?><br><small style="color:#888"><?= h($ln['item_code'] ?? '') ?></small></td>
            <td class="num"><?= h(rtrim(rtrim(number_format((float)($ln['qty'] ?? 0), 4, '.', ''), '0'), '.')) ?></td>
            <td class="num"><?= h(number_format((float)($ln['line_total_incl'] ?? 0), 2)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="totals">
      <div><span>Subtotal (ex VAT)</span><span><?= h(number_format((float)($sale['subtotal_excl'] ?? 0), 2)) ?></span></div>
      <div><span>VAT</span><span><?= h(number_format((float)($sale['tax_total'] ?? 0), 2)) ?></span></div>
      <div class="grand"><span>Total</span><span><?= h(number_format((float)($sale['grand_total_incl'] ?? 0), 2)) ?></span></div>
    </div>
    <p class="no-print" style="margin-top:20px;text-align:center;">
      <button type="button" onclick="window.print()">Print</button>
      <button type="button" class="no-print" onclick="window.close()">Close</button>
    </p>
  </div>
</body>
</html>
