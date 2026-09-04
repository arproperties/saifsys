<?php
/**
 * Work Order vs Invoice reconciliation report (read-only).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/cleaning_accounting_context.php';
require_once __DIR__ . '/../includes/gl_posting.php';
require_once __DIR__ . '/../includes/service_accounting_service.php';
require_once __DIR__ . '/../includes/service_health_service.php';
require_once __DIR__ . '/../includes/report_date_helpers.php';

require_role(['Owner', 'Admin', 'Account'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($n) { return number_format((float)$n, 2); }
}

$dates = report_date_range();
$from = $dates['from'];
$to = $dates['to'];
$export = isset($_GET['export']) && $_GET['export'] === 'csv';
$companyId = cleaning_accounting_company_id($conn);

$moCompany = '';
$params = [$from, $to, $from, $to];
if (function_exists('gl_column_exists') && gl_column_exists($conn, 'make_order', 'company_id')) {
    $moCompany = ' AND mo.company_id = ?';
    $params[] = $companyId;
}

$sql = "
    SELECT
        mo.id AS order_id,
        COALESCE(mo.service_date, mo.`date`) AS service_date,
        COALESCE(c.client_name, mo.client_name) AS client_name,
        mo.status AS wo_status,
        COALESCE(mo.grand_total, mo.total + COALESCE(mo.vat_amount, 0)) AS wo_total,
        i.id AS invoice_id,
        i.invoice_no,
        i.issue_date AS invoice_date,
        i.status AS invoice_status,
        i.total AS invoice_total,
        COALESCE(pa.amount_paid, 0) AS paid_amount,
        GREATEST(ROUND(COALESCE(i.total, 0) - COALESCE(pa.amount_paid, 0), 2), 0) AS balance_due,
        CASE
            WHEN i.id IS NULL THEN 'missing_invoice'
            WHEN ABS(COALESCE(mo.grand_total, mo.total + COALESCE(mo.vat_amount, 0)) - i.total) > 0.02 THEN 'amount_mismatch'
            WHEN mo.status = 'cancelled' AND i.status <> 'void' THEN 'cancelled_with_invoice'
            ELSE 'matched'
        END AS reconciliation_status,
        ABS(COALESCE(mo.grand_total, mo.total + COALESCE(mo.vat_amount, 0)) - COALESCE(i.total, 0)) AS difference
    FROM make_order mo
    LEFT JOIN client c ON c.id = mo.client_id
    LEFT JOIN invoices i ON i.order_id = mo.id AND i.status <> 'void'
    LEFT JOIN (
        SELECT ra.invoice_id, ROUND(SUM(ra.amount_applied), 2) AS amount_paid
        FROM receipt_allocations ra
        GROUP BY ra.invoice_id
    ) pa ON pa.invoice_id = i.id
    WHERE COALESCE(mo.status, '') <> 'cancelled'
      AND (
        mo.service_date BETWEEN ? AND ?
        OR (mo.service_date IS NULL AND mo.`date` BETWEEN ? AND ?)
      )
      {$moCompany}
    ORDER BY service_date DESC, mo.id DESC
    LIMIT 2000
";
$st = $conn->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totals = [
    'wo_total' => 0.0,
    'invoice_total' => 0.0,
    'paid_amount' => 0.0,
    'balance_due' => 0.0,
    'matched' => 0,
    'missing_invoice' => 0,
    'amount_mismatch' => 0,
    'cancelled_with_invoice' => 0,
];
foreach ($rows as $r) {
    $totals['wo_total'] += (float)$r['wo_total'];
    $totals['invoice_total'] += (float)($r['invoice_total'] ?? 0);
    $totals['paid_amount'] += (float)($r['paid_amount'] ?? 0);
    $totals['balance_due'] += (float)($r['balance_due'] ?? 0);
    $stKey = (string)($r['reconciliation_status'] ?? 'matched');
    if (isset($totals[$stKey])) {
        $totals[$stKey]++;
    }
}
$totals['difference'] = round($totals['wo_total'] - $totals['invoice_total'], 2);

if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="wo_invoice_reconciliation_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    if ($rows) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
    }
    fclose($out);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>WO vs Invoice Reconciliation</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  body{background:#f6f7f9}
  .hero{background:#fff;border-radius:18px;box-shadow:0 10px 24px rgba(0,0,0,.06);padding:18px 22px;margin-bottom:18px}
  .stat-card{background:#fff;border-radius:12px;padding:1rem;box-shadow:0 4px 12px rgba(0,0,0,.05)}
</style>
</head>
<body>
<div class="container-fluid my-4 px-4">
  <div class="hero d-flex align-items-center flex-wrap gap-2">
    <div>
      <h3 class="mb-0">Work Order vs Invoice Reconciliation</h3>
    </div>
    <div class="ms-auto d-flex gap-2">
      <a href="system_health_check.php" class="btn btn-outline-secondary"><i class="bi bi-heart-pulse"></i> Health Check</a>
      <a href="reports.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Reports</a>
    </div>
  </div>

  <form class="row g-2 mb-4 align-items-end" method="get">
    <div class="col-md-2">
      <label class="form-label">From</label>
      <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">To</label>
      <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
    </div>
    <div class="col-md-2">
      <button class="btn btn-primary w-100">Run</button>
    </div>
    <div class="col-md-2">
      <a class="btn btn-outline-secondary w-100" href="?from=<?= h($from) ?>&to=<?= h($to) ?>&export=csv">Export CSV</a>
    </div>
  </form>

  <div class="row g-3 mb-4">
    <div class="col-md-2"><div class="stat-card"><div class="small text-muted">WO total</div><div class="fw-bold">AED <?= money($totals['wo_total']) ?></div></div></div>
    <div class="col-md-2"><div class="stat-card"><div class="small text-muted">Invoice total</div><div class="fw-bold">AED <?= money($totals['invoice_total']) ?></div></div></div>
    <div class="col-md-2"><div class="stat-card"><div class="small text-muted">Paid</div><div class="fw-bold text-success">AED <?= money($totals['paid_amount']) ?></div></div></div>
    <div class="col-md-2"><div class="stat-card"><div class="small text-muted">Outstanding</div><div class="fw-bold text-warning">AED <?= money($totals['balance_due']) ?></div></div></div>
    <div class="col-md-2"><div class="stat-card"><div class="small text-muted">WO − Invoice</div><div class="fw-bold <?= abs($totals['difference']) > 0.02 ? 'text-danger' : 'text-success' ?>">AED <?= money($totals['difference']) ?></div></div></div>
    <div class="col-md-2"><div class="stat-card"><div class="small text-muted">Exceptions</div><div class="fw-bold text-danger"><?= (int)$totals['missing_invoice'] + (int)$totals['amount_mismatch'] ?></div></div></div>
  </div>

  <div class="alert alert-info small">
    <strong>Matched:</strong> <?= (int)$totals['matched'] ?> |
    <strong>Missing invoice:</strong> <?= (int)$totals['missing_invoice'] ?> |
    <strong>Amount mismatch:</strong> <?= (int)$totals['amount_mismatch'] ?>
  </div>

  <div class="table-responsive bg-white rounded shadow-sm">
    <table class="table table-sm table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>WO #</th>
          <th>Service date</th>
          <th>Client</th>
          <th>WO status</th>
          <th class="text-end">WO total</th>
          <th>Invoice</th>
          <th>Inv. status</th>
          <th class="text-end">Invoice total</th>
          <th class="text-end">Paid</th>
          <th class="text-end">Balance</th>
          <th>Status</th>
          <th class="text-end">Diff</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="12" class="text-center text-muted py-4">No work orders in this period.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <?php
            $badge = match ($r['reconciliation_status']) {
                'matched' => 'success',
                'missing_invoice' => 'danger',
                'amount_mismatch' => 'warning',
                default => 'secondary',
            };
          ?>
          <tr>
            <td><a href="../operation/order_edit.php?id=<?= (int)$r['order_id'] ?>">#<?= (int)$r['order_id'] ?></a></td>
            <td><?= h($r['service_date']) ?></td>
            <td><?= h($r['client_name']) ?></td>
            <td><?= h($r['wo_status']) ?></td>
            <td class="text-end"><?= money($r['wo_total']) ?></td>
            <td><?= $r['invoice_no'] ? h($r['invoice_no']) : '—' ?></td>
            <td><?= h($r['invoice_status'] ?? '—') ?></td>
            <td class="text-end"><?= $r['invoice_total'] !== null ? money($r['invoice_total']) : '—' ?></td>
            <td class="text-end"><?= money($r['paid_amount'] ?? 0) ?></td>
            <td class="text-end"><?= money($r['balance_due'] ?? 0) ?></td>
            <td><span class="badge text-bg-<?= $badge ?>"><?= h($r['reconciliation_status']) ?></span></td>
            <td class="text-end"><?= money($r['difference'] ?? 0) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
