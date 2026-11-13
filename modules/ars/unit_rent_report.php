<?php
/**
 * ARS Unit Sizes & Rent — every ARS unit with premise number, type, size, whether it is
 * rented today and the monthly rent of the current booking. Read-only.
 * Rules live in includes/ars_unit_rent.php (shared with the Jarvis API).
 *
 * ?export=pdf prints what is on screen with no building name or "ARS" in the title,
 * so it can go to a broker as it is.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_unit_rent.php';

$arsCompanyId = arsPageAuth($conn);

$buildings = ars_unit_rent_buildings($conn, $arsCompanyId);
$buildingId = (int)($_GET['building'] ?? 0);
if ($buildingId && !isset($buildings[$buildingId])) {
    $buildingId = 0;
}
$status = (string)($_GET['status'] ?? '');
if (!in_array($status, ['', 'rented', 'vacant'], true)) {
    $status = '';
}
$today = ars_unit_rent_today();
$rows = ars_unit_rent_rows($conn, $arsCompanyId, $buildingId, $status, $today);
$tot = ars_unit_rent_totals($rows);
// One building on screen: no need for a Building column
$showBuilding = $buildingId === 0 && count($buildings) > 1;

$fmtSize = static fn($v): string => $v === null ? '—' : number_format((float)$v, 2);
$fmtRent = static fn($v): string => $v === null ? '—' : number_format((float)$v);
$fmtDate = static fn($d): string => $d ? date('d M Y', strtotime((string)$d)) : '—';

$export = (string)($_GET['export'] ?? '');

if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="unit-sizes-rent-' . $today . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $head = ['#', 'Unit', 'Premise No.', 'Type', 'Size (sq ft)', 'Status', 'Rent (AED / month)'];
    if ($showBuilding) {
        array_splice($head, 2, 0, ['Building']);
    }
    fputcsv($out, $head);
    foreach ($rows as $i => $r) {
        $line = [$i + 1, $r['unit'], $r['premise_no'], $r['type_label'], $r['size_sqft'],
            $r['status'] === 'rented' ? 'Rented' : 'Vacant', $r['rent_month']];
        if ($showBuilding) {
            array_splice($line, 2, 0, [$r['building']]);
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

if ($export === 'pdf') {
    $e = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $tr = '';
    foreach ($rows as $i => $r) {
        $tr .= '<tr><td>' . ($i + 1) . '</td><td>' . $e($r['unit']) . '</td>'
            . ($showBuilding ? '<td>' . $e($r['building']) . '</td>' : '')
            . '<td>' . $e($r['premise_no'] ?: '—') . '</td><td>' . $e($r['type_label']) . '</td>'
            . '<td class="n">' . $fmtSize($r['size_sqft']) . '</td>'
            . '<td class="n">' . ($r['status'] === 'rented' ? $fmtRent($r['rent_month']) : 'Vacant') . '</td></tr>';
    }
    if (!$rows) {
        $tr = '<tr><td colspan="' . ($showBuilding ? 7 : 6) . '">No units.</td></tr>';
    }
    $what = $status === 'vacant' ? 'vacant units' : ($status === 'rented' ? 'rented units' : 'units');
    $html = '<html><head><meta charset="utf-8"><style>
        body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222}
        p{margin:0 0 10px;color:#555}
        table{border-collapse:collapse;width:100%}
        th,td{border:1px solid #999;padding:4px 8px}
        th{background:#eee;text-align:left}
        .n{text-align:right}
        </style></head><body>'
        . '<p>' . count($rows) . ' ' . $what . ' · ' . $e(date('d M Y', strtotime($today))) . '</p>'
        . '<table><tr><th>#</th><th>Unit</th>' . ($showBuilding ? '<th>Building</th>' : '')
        . '<th>Premise No.</th><th>Type</th><th class="n">Size (sq ft)</th><th class="n">Rent (AED / month)</th></tr>'
        . $tr
        . '<tr><th></th><th>Total</th>' . ($showBuilding ? '<th></th>' : '') . '<th></th><th></th>'
        . '<th class="n">' . number_format($tot['size_sqft'], 2) . '</th><th></th></tr>'
        . '</table></body></html>';

    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }
    if (!class_exists('\Dompdf\Dompdf')) {
        http_response_code(500);
        exit('PDF library not available.');
    }
    $opts = new \Dompdf\Options();
    $opts->set('isRemoteEnabled', false);
    $pdf = new \Dompdf\Dompdf($opts);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();
    $pdf->stream('Units-' . $today . '.pdf', ['Attachment' => true]);
    exit;
}

$baseQuery = ['building' => $buildingId ?: null, 'status' => $status ?: null];
$exportUrl = static fn(string $kind): string => '?' . http_build_query(array_filter($baseQuery + ['export' => $kind]));
$statusLink = static fn(string $s): string => '?' . http_build_query(array_filter(['building' => $buildingId ?: null, 'status' => $s ?: null]));

$pageTitle = 'Unit Sizes & Rent';
$btn = 'no-underline inline-flex items-center gap-1 rounded-ars-md border border-ars-border px-3 py-2 text-ars-sm font-semibold text-ars-text hover:border-ars-ink/40';
ars_shell_begin([
    'title' => 'Unit Sizes & Rent',
    'subtitle' => ($buildingId ? $buildings[$buildingId] . ' · ' : '') . 'As of ' . $fmtDate($today),
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Reports', 'href' => 'reports.php'],
        ['label' => 'Unit Sizes & Rent'],
    ],
    'actions_html' => '<a class="' . $btn . '" href="' . h($exportUrl('pdf')) . '" title="No building name or ARS on it — safe to send to a broker">'
        . ars_ui_icon('file-text', ['class' => 'h-4 w-4']) . ' PDF</a> '
        . '<a class="' . $btn . '" href="' . h($exportUrl('csv')) . '">'
        . ars_ui_icon('download', ['class' => 'h-4 w-4']) . ' CSV</a>',
    'legacy_bootstrap' => true,
]);
?>

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-4"><?= ars_ds_stat_tile('Units', (string)$tot['units'], ['tone' => 'default', 'icon' => 'home', 'href' => $statusLink(''), 'hint' => number_format($tot['size_sqft'], 2) . ' sq ft in all']) ?></div>
    <div class="col-6 col-lg-4"><?= ars_ds_stat_tile('Rented', (string)$tot['rented'], ['tone' => 'ok', 'icon' => 'check-circle', 'href' => $statusLink('rented'), 'hint' => 'AED ' . number_format($tot['rent_month']) . ' / month']) ?></div>
    <div class="col-6 col-lg-4"><?= ars_ds_stat_tile('Vacant', (string)$tot['vacant'], ['tone' => $tot['vacant'] > 0 ? 'warn' : 'ok', 'icon' => 'door-open', 'href' => $statusLink('vacant'), 'hint' => 'No booking covering today']) ?></div>
</div>

<div class="ars-card mb-3">
    <div class="card-body py-3">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-sm-4 col-lg-3">
                <label class="form-label small fw-semibold mb-1">Building</label>
                <select class="form-select form-select-sm" name="building">
                    <option value="">All buildings</option>
                    <?php foreach ($buildings as $bid => $bname): ?>
                        <option value="<?= (int)$bid ?>" <?= $bid === $buildingId ? 'selected' : '' ?>><?= h($bname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-4 col-lg-2">
                <label class="form-label small fw-semibold mb-1">Show</label>
                <select class="form-select form-select-sm" name="status">
                    <option value="">All units</option>
                    <option value="rented" <?= $status === 'rented' ? 'selected' : '' ?>>Rented</option>
                    <option value="vacant" <?= $status === 'vacant' ? 'selected' : '' ?>>Vacant</option>
                </select>
            </div>
            <div class="col-sm-2 col-lg-auto">
                <button class="btn btn-sm btn-ars w-100 text-nowrap px-3"><i class="bi bi-funnel me-1"></i>Filter</button>
            </div>
            <div class="col-auto">
                <a class="btn btn-sm btn-outline-secondary" href="unit_rent_report.php">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="ars-card">
<div class="table-responsive">
    <table class="table table-sm ars-table ars-mobile-cards mb-0 align-middle">
        <thead>
            <tr>
                <th>#</th>
                <th>Unit</th>
                <?php if ($showBuilding): ?><th>Building</th><?php endif; ?>
                <th>Premise No.</th>
                <th>Type</th>
                <th class="text-end">Size (sq ft)</th>
                <th>Status</th>
                <th class="text-end">Rent (AED / month)</th>
                <th>Booking</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r): $rented = $r['status'] === 'rented'; ?>
            <tr>
                <td data-label="#" class="text-muted"><?= $i + 1 ?></td>
                <td data-label="Unit" class="fw-semibold"><a class="text-decoration-none" href="unit_profile.php?id=<?= (int)$r['unit_id'] ?>"><?= h($r['unit']) ?></a></td>
                <?php if ($showBuilding): ?><td data-label="Building"><?= h($r['building'] ?: '—') ?></td><?php endif; ?>
                <td data-label="Premise No." class="font-monospace small"><?= h($r['premise_no'] ?: '—') ?></td>
                <td data-label="Type"><?= h($r['type_label']) ?></td>
                <td data-label="Size (sq ft)" class="text-end ars-tabular"><?= h($fmtSize($r['size_sqft'])) ?></td>
                <td data-label="Status"><span class="badge <?= $rented ? 'bg-success' : 'bg-warning text-dark' ?>"><?= $rented ? 'Rented' : 'Vacant' ?></span></td>
                <td data-label="Rent (AED / month)" class="text-end ars-tabular fw-semibold"><?= h($fmtRent($r['rent_month'])) ?></td>
                <td data-label="Booking" class="small text-nowrap">
                    <?php if ($rented): ?>
                        <a class="text-decoration-none font-monospace" href="booking_view.php?id=<?= (int)$r['booking_id'] ?>"><?= h($r['booking_number']) ?></a>
                        <span class="text-muted">· until <?= h($fmtDate($r['check_out'])) ?></span>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= $showBuilding ? 9 : 8 ?>" class="text-center text-muted py-4">No units.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot>
            <tr class="fw-semibold">
                <td></td><td>Total</td><?php if ($showBuilding): ?><td></td><?php endif; ?><td></td><td></td>
                <td class="text-end ars-tabular"><?= number_format($tot['size_sqft'], 2) ?></td>
                <td></td>
                <td class="text-end ars-tabular"><?= number_format($tot['rent_month']) ?></td>
                <td></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>
</div>
<p class="mt-3 mb-0 text-ars-sm text-ars-muted">Rented = a checked-in or confirmed booking covering today. Rent per month = that booking's nightly rate × 30.</p>

<?php ars_shell_end(); ?>
