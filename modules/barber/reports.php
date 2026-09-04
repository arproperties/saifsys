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
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

if ($export && !has_permission('barber_backoffice.export', MODULE_BARBER, $conn)) {
    http_response_code(403);
    exit('Export not allowed');
}

$byBarber = [];
$byService = [];
$daily = [];
$paySummary = [];

try {
    $st = $conn->prepare("
        SELECT b.display_name, COALESCE(SUM(s.total),0) AS tot, COUNT(*) AS cnt
        FROM barber_sales s
        JOIN barber_staff b ON b.id = s.barber_staff_id
        WHERE s.company_id = ? AND DATE(s.sale_at) BETWEEN ? AND ?
        GROUP BY b.id, b.display_name
        ORDER BY tot DESC
    ");
    $st->execute([$companyId, $dFrom, $dTo]);
    $byBarber = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $conn->prepare("
        SELECT sl.service_name_snapshot AS svc, COALESCE(SUM(sl.line_total),0) AS tot, SUM(sl.qty) AS qty
        FROM barber_sale_lines sl
        JOIN barber_sales s ON s.id = sl.sale_id
        WHERE s.company_id = ? AND DATE(s.sale_at) BETWEEN ? AND ?
        GROUP BY sl.service_name_snapshot
        ORDER BY tot DESC
    ");
    $st->execute([$companyId, $dFrom, $dTo]);
    $byService = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $conn->prepare("
        SELECT DATE(s.sale_at) AS d, COALESCE(SUM(s.total),0) AS tot, COUNT(*) AS cnt
        FROM barber_sales s
        WHERE s.company_id = ? AND DATE(s.sale_at) BETWEEN ? AND ?
        GROUP BY DATE(s.sale_at)
        ORDER BY d
    ");
    $st->execute([$companyId, $dFrom, $dTo]);
    $daily = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $conn->prepare("
        SELECT s.payment_method, COALESCE(SUM(s.total),0) AS tot, COUNT(*) AS cnt
        FROM barber_sales s
        WHERE s.company_id = ? AND DATE(s.sale_at) BETWEEN ? AND ?
        GROUP BY s.payment_method
    ");
    $st->execute([$companyId, $dFrom, $dTo]);
    $paySummary = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // leave empty
}

if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="barber_report_' . $dFrom . '_' . $dTo . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Section', 'Label', 'Amount', 'Count']);
    foreach ($byBarber as $r) {
        fputcsv($out, ['By barber', $r['display_name'], $r['tot'], $r['cnt']]);
    }
    foreach ($byService as $r) {
        fputcsv($out, ['By service', $r['svc'], $r['tot'], $r['qty']]);
    }
    foreach ($daily as $r) {
        fputcsv($out, ['Daily', $r['d'], $r['tot'], $r['cnt']]);
    }
    foreach ($paySummary as $r) {
        fputcsv($out, ['Payment', $r['payment_method'], $r['tot'], $r['cnt']]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Reports';
require_once __DIR__ . '/includes/barber_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div class="page-header-label">Reports</div>
    <?php if (has_permission('barber_backoffice.export', MODULE_BARBER, $conn)): ?>
    <a class="btn btn-outline-primary btn-sm" href="reports.php?<?= h(http_build_query(['from' => $dFrom, 'to' => $dTo, 'export' => 'csv'])) ?>">Export CSV</a>
    <?php endif; ?>
</div>

<div class="card mb-4"><div class="card-body">
<form method="get" class="row g-2 align-items-end">
    <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= h($dFrom) ?>"></div>
    <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= h($dTo) ?>"></div>
    <div class="col-md-2"><button class="btn btn-primary" type="submit">Apply</button></div>
</form>
</div></div>

<div class="row g-3">
    <div class="col-lg-6"><div class="card h-100"><div class="card-header fw-semibold">Sales by barber</div><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm mb-0"><thead class="table-light"><tr><th>Barber</th><th class="text-end">Total</th><th class="text-end">Sales</th></tr></thead><tbody>
        <?php foreach ($byBarber as $r): ?>
        <tr><td><?= h($r['display_name']) ?></td><td class="text-end"><?= number_format((float)$r['tot'], 2) ?></td><td class="text-end"><?= (int)$r['cnt'] ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$byBarber): ?><tr><td colspan="3" class="text-muted text-center py-3">No data</td></tr><?php endif; ?>
        </tbody></table></div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header fw-semibold">Sales by service</div><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm mb-0"><thead class="table-light"><tr><th>Service</th><th class="text-end">Qty</th><th class="text-end">Total</th></tr></thead><tbody>
        <?php foreach ($byService as $r): ?>
        <tr><td><?= h($r['svc']) ?></td><td class="text-end"><?= number_format((float)$r['qty'], 2) ?></td><td class="text-end"><?= number_format((float)$r['tot'], 2) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$byService): ?><tr><td colspan="3" class="text-muted text-center py-3">No data</td></tr><?php endif; ?>
        </tbody></table></div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header fw-semibold">Daily summary</div><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm mb-0"><thead class="table-light"><tr><th>Date</th><th class="text-end">Total</th><th class="text-end">Tx</th></tr></thead><tbody>
        <?php foreach ($daily as $r): ?>
        <tr><td><?= h($r['d']) ?></td><td class="text-end"><?= number_format((float)$r['tot'], 2) ?></td><td class="text-end"><?= (int)$r['cnt'] ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$daily): ?><tr><td colspan="3" class="text-muted text-center py-3">No data</td></tr><?php endif; ?>
        </tbody></table></div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header fw-semibold">Payment summary</div><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm mb-0"><thead class="table-light"><tr><th>Method</th><th class="text-end">Total</th><th class="text-end">Tx</th></tr></thead><tbody>
        <?php foreach ($paySummary as $r): ?>
        <tr><td><?= h($r['payment_method']) ?></td><td class="text-end"><?= number_format((float)$r['tot'], 2) ?></td><td class="text-end"><?= (int)$r['cnt'] ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$paySummary): ?><tr><td colspan="3" class="text-muted text-center py-3">No data</td></tr><?php endif; ?>
        </tbody></table></div></div></div>
</div>

<?php require_once __DIR__ . '/includes/barber_layout_footer.php';
