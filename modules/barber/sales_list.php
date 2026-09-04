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

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$dFrom = $_GET['from'] ?? date('Y-m-01');
$dTo = $_GET['to'] ?? date('Y-m-d');
$barberId = (int)($_GET['barber_id'] ?? 0);
$pay = $_GET['payment'] ?? '';

$where = ['s.company_id = ?', 'DATE(s.sale_at) >= ?', 'DATE(s.sale_at) <= ?'];
$args = [$companyId, $dFrom, $dTo];
if ($barberId > 0) {
    $where[] = 's.barber_staff_id = ?';
    $args[] = $barberId;
}
if ($pay === 'cash' || $pay === 'card') {
    $where[] = 's.payment_method = ?';
    $args[] = $pay;
}

$barbers = [];
try {
    $bst = $conn->prepare('SELECT id, display_name FROM barber_staff WHERE company_id=? AND is_active=1 ORDER BY sort_order');
    $bst->execute([$companyId]);
    $barbers = $bst->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $barbers = [];
}

$sql = "
SELECT s.*, b.display_name AS barber_name,
  (SELECT GROUP_CONCAT(sl.service_name_snapshot ORDER BY sl.id SEPARATOR ', ')
   FROM barber_sale_lines sl WHERE sl.sale_id = s.id) AS services_summary
FROM barber_sales s
JOIN barber_staff b ON b.id = s.barber_staff_id
WHERE " . implode(' AND ', $where) . "
ORDER BY s.sale_at DESC, s.id DESC
LIMIT 500";
$rows = [];
try {
    $st = $conn->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = [];
}

$pageTitle = 'Sales';
require_once __DIR__ . '/includes/barber_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div class="page-header-label">Sales</div>
    <a href="pos.php" class="btn btn-dark btn-sm">POS</a>
</div>

<div class="card mb-4"><div class="card-body">
<form method="get" class="row g-2 align-items-end">
    <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= h($dFrom) ?>"></div>
    <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= h($dTo) ?>"></div>
    <div class="col-md-3"><label class="form-label">Barber</label>
        <select name="barber_id" class="form-select"><option value="0">All</option>
        <?php foreach ($barbers as $b): ?>
        <option value="<?= (int)$b['id'] ?>" <?= $barberId === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['display_name']) ?></option>
        <?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Payment</label>
        <select name="payment" class="form-select">
            <option value="" <?= $pay === '' ? 'selected' : '' ?>>All</option>
            <option value="cash" <?= $pay === 'cash' ? 'selected' : '' ?>>Cash</option>
            <option value="card" <?= $pay === 'card' ? 'selected' : '' ?>>Card</option>
        </select></div>
    <div class="col-md-1"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-search"></i></button></div>
</form>
</div></div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead class="table-light"><tr><th>When</th><th>#</th><th>Barber</th><th>Services</th><th>Pay</th><th class="text-end">Total</th><th></th></tr></thead>
<tbody>
<?php if (empty($rows)): ?>
<tr><td colspan="7" class="text-center text-muted py-4">No sales.</td></tr>
<?php else: foreach ($rows as $r): ?>
<tr>
    <td><?= h(date('Y-m-d H:i', strtotime($r['sale_at']))) ?></td>
    <td><?= h($r['sale_number'] ?: '#' . $r['id']) ?></td>
    <td><?= h($r['barber_name']) ?></td>
    <td class="small"><?= h($r['services_summary'] ?: '—') ?></td>
    <td><?= h($r['payment_method']) ?></td>
    <td class="text-end"><?= number_format((float)$r['total'], 2) ?></td>
    <td><a class="btn btn-sm btn-outline-primary" href="sale_view.php?id=<?= (int)$r['id'] ?>">View</a></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div></div></div>

<?php require_once __DIR__ . '/includes/barber_layout_footer.php';
