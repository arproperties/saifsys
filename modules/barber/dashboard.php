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

$today = date('Y-m-d');
$kpis = ['total' => 0.0, 'cash' => 0.0, 'card' => 0.0, 'tx' => 0];
$byBarber = [];
$tableMissing = false;

try {
    $st = $conn->prepare("
        SELECT COALESCE(SUM(total),0) AS tot,
               COALESCE(SUM(CASE WHEN payment_method='cash' THEN total ELSE 0 END),0) AS cash,
               COALESCE(SUM(CASE WHEN payment_method='card' THEN total ELSE 0 END),0) AS card,
               COUNT(*) AS cnt
        FROM barber_sales WHERE company_id = ? AND DATE(sale_at) = ?
    ");
    $st->execute([$companyId, $today]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $kpis['total'] = (float)$r['tot'];
        $kpis['cash'] = (float)$r['cash'];
        $kpis['card'] = (float)$r['card'];
        $kpis['tx'] = (int)$r['cnt'];
    }
    $st = $conn->prepare("
        SELECT s.barber_staff_id, b.display_name, COALESCE(SUM(s.total),0) AS tot, COUNT(*) AS cnt
        FROM barber_sales s
        JOIN barber_staff b ON b.id = s.barber_staff_id
        WHERE s.company_id = ? AND DATE(s.sale_at) = ?
        GROUP BY s.barber_staff_id, b.display_name
        ORDER BY tot DESC
    ");
    $st->execute([$companyId, $today]);
    $byBarber = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $tableMissing = true;
}

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/barber_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div class="page-header-label">Dashboard</div>
    <a href="pos.php" class="btn btn-dark"><i class="bi bi-tablet"></i> Open POS</a>
</div>

<?php if ($tableMissing): ?>
<div class="alert alert-warning">Run <code>migrations/barber_phase_b1_schema.sql</code>.</div>
<?php else: ?>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">Sales today</div>
        <div class="fs-3 fw-bold"><?= number_format($kpis['total'], 2) ?> <span class="fs-6 text-muted">AED</span></div>
    </div></div></div>
    <div class="col-md-3"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">Cash</div>
        <div class="fs-4 fw-bold text-success"><?= number_format($kpis['cash'], 2) ?></div>
    </div></div></div>
    <div class="col-md-3"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">Card</div>
        <div class="fs-4 fw-bold text-primary"><?= number_format($kpis['card'], 2) ?></div>
    </div></div></div>
    <div class="col-md-3"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">Transactions</div>
        <div class="fs-3 fw-bold"><?= (int)$kpis['tx'] ?></div>
    </div></div></div>
</div>

<div class="card mb-4"><div class="card-header fw-semibold">Sales by barber (today)</div>
<div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead class="table-light"><tr><th>Barber</th><th class="text-end">Total AED</th><th class="text-end">Sales</th></tr></thead>
<tbody>
<?php if (empty($byBarber)): ?>
<tr><td colspan="3" class="text-center text-muted py-4">No sales today.</td></tr>
<?php else: foreach ($byBarber as $row): ?>
<tr>
    <td><?= h($row['display_name']) ?></td>
    <td class="text-end"><?= number_format((float)$row['tot'], 2) ?></td>
    <td class="text-end"><?= (int)$row['cnt'] ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div></div>

<div class="d-flex gap-2 flex-wrap">
    <?php if (has_permission('barber_backoffice.manage_services', MODULE_BARBER, $conn)): ?>
    <a href="services.php" class="btn btn-outline-primary">Manage services</a>
    <?php endif; ?>
    <?php if (has_permission('barber_backoffice.manage_team', MODULE_BARBER, $conn)): ?>
    <a href="barbers.php" class="btn btn-outline-secondary">Team / barbers</a>
    <?php endif; ?>
</div>

<?php endif;
require_once __DIR__ . '/includes/barber_layout_footer.php';
