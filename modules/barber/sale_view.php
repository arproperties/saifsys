<?php
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
require_module_access($conn, MODULE_BARBER);
require_barber_backoffice_department($conn);
require_permission('barber_backoffice.view', MODULE_BARBER, $conn);
ensure_current_company_supports_module($conn, MODULE_BARBER);

$brand = getBrandSettings($conn);
$companyId = (int)(current_company_id($conn) ?: 0);
$id = (int)($_GET['id'] ?? 0);

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$sale = null;
$lines = [];
if ($id > 0) {
    $st = $conn->prepare('
        SELECT s.*, b.display_name AS barber_name
        FROM barber_sales s
        JOIN barber_staff b ON b.id = s.barber_staff_id
        WHERE s.id = ? AND s.company_id = ?
    ');
    $st->execute([$id, $companyId]);
    $sale = $st->fetch(PDO::FETCH_ASSOC);
    if ($sale) {
        $st = $conn->prepare('SELECT * FROM barber_sale_lines WHERE sale_id = ? ORDER BY id');
        $st->execute([$id]);
        $lines = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

$pageTitle = 'Sale detail';
require_once __DIR__ . '/includes/barber_layout_header.php';
?>
<div class="d-flex align-items-center mb-4 gap-2">
    <a href="sales_list.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <div class="page-header-label mb-0">Sale <?= $sale ? h($sale['sale_number'] ?: '#' . $id) : '' ?></div>
</div>

<?php if (!$sale): ?>
<div class="alert alert-warning">Sale not found.</div>
<?php else: ?>

<div class="card mb-3"><div class="card-body">
    <div class="row g-2">
        <div class="col-md-3"><strong>Date/time</strong><br><?= h($sale['sale_at']) ?></div>
        <div class="col-md-3"><strong>Barber</strong><br><?= h($sale['barber_name']) ?></div>
        <div class="col-md-3"><strong>Payment</strong><br><?= h($sale['payment_method']) ?> <span class="text-muted small">(card = recorded only)</span></div>
        <div class="col-md-3"><strong>Total</strong><br><span class="fs-4"><?= number_format((float)$sale['total'], 2) ?> AED</span></div>
    </div>
</div></div>

<div class="card"><div class="card-header">Lines</div><div class="card-body p-0"><div class="table-responsive">
<table class="table mb-0">
<thead class="table-light"><tr><th>Service</th><th class="text-end">Qty</th><th class="text-end">Unit</th><th class="text-end">Line total</th></tr></thead>
<tbody>
<?php foreach ($lines as $ln): ?>
<tr>
    <td><?= h($ln['service_name_snapshot']) ?></td>
    <td class="text-end"><?= h((string)(float)$ln['qty']) ?></td>
    <td class="text-end"><?= number_format((float)$ln['unit_price'], 2) ?></td>
    <td class="text-end"><?= number_format((float)$ln['line_total'], 2) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div></div>

<?php endif;
require_once __DIR__ . '/includes/barber_layout_footer.php';
