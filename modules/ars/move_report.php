<?php
/**
 * ARS Move-in / Move-out report — guest check-ins and check-outs by date range.
 * Read-only: lists reservations from ars_bookings, no money is calculated here.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';
require_once __DIR__ . '/includes/ars_move_inspection.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);

$tab = $_GET['tab'] ?? 'all';
if (!in_array($tab, ['all', 'in', 'out'], true)) {
    $tab = 'all';
}
$isAll = $tab === 'all';
$isOut = $tab === 'out';

$isDate = static fn($v): bool => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
$dateFrom = $isDate($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-01');
$dateTo = $isDate($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-t');
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
$search = trim((string)($_GET['q'] ?? ''));
$state = $_GET['state'] ?? '';
if (!in_array($state, ['', 'done', 'pending'], true)) {
    $state = '';
}

$doneFor = [
    'in' => ['checked_in', 'checked_out', 'completed'],
    'out' => ['checked_out', 'completed'],
];
$fetchMoves = static function (string $type) use ($conn, $arsCompanyId, $dateFrom, $dateTo, $search, $doneFor): array {
    // A guest who left early moved out on the actual date, not the booked one.
    $dateCol = $type === 'out' ? 'COALESCE(b.actual_check_out, b.check_out)' : 'b.check_in';
    $sql = "
        SELECT b.id, b.booking_number, b.status, b.check_in, b.check_out, b.actual_check_out,
               b.is_early_checkout, b.num_guests, b.booking_source,
               $dateCol AS move_date,
               g.first_name, g.last_name, g.phone AS guest_phone, g.nationality,
               u.unit_number, bl.name AS building_name
        FROM ars_bookings b
        LEFT JOIN ars_guests g ON g.id = b.guest_id
        LEFT JOIN re_units u ON u.id = b.unit_id
        LEFT JOIN re_buildings bl ON bl.id = u.building_id
        WHERE b.company_id = ?
          AND b.status NOT IN ('cancelled','expired')
          AND $dateCol BETWEEN ? AND ?
    ";
    $params = [$arsCompanyId, $dateFrom, $dateTo];
    if ($search !== '') {
        $sql .= " AND (b.booking_number LIKE ? OR g.first_name LIKE ? OR g.last_name LIKE ? OR u.unit_number LIKE ? OR bl.name LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['move_type'] = $type;
        $r['move_done'] = in_array($r['status'], $doneFor[$type], true);
    }
    unset($r);
    return $rows;
};

// All = both lists together; a stay that starts and ends in the range shows twice.
$allRows = [];
foreach ($isAll ? ['in', 'out'] : [$tab] as $type) {
    $allRows = array_merge($allRows, $fetchMoves($type));
}
usort($allRows, static fn(array $a, array $b): int => [$a['move_date'], $a['move_type'], (string)$a['unit_number'], $a['booking_number']]
    <=> [$b['move_date'], $b['move_type'], (string)$b['unit_number'], $b['booking_number']]);

$doneCount = 0;
$inCount = 0;
foreach ($allRows as $r) {
    $doneCount += $r['move_done'] ? 1 : 0;
    $inCount += $r['move_type'] === 'in' ? 1 : 0;
}
$pendingCount = count($allRows) - $doneCount;
$outCount = count($allRows) - $inCount;
$inspections = ars_move_inspection_summary($conn, $arsCompanyId, array_column($allRows, 'id'));

$rows = $allRows;
if ($state !== '') {
    $rows = array_values(array_filter($allRows, static fn(array $r): bool => $state === 'done' ? $r['move_done'] : !$r['move_done']));
}

$nightsOf = static function (array $r): string {
    $in = (string)($r['check_in'] ?? '');
    $out = (string)($r['actual_check_out'] ?: ($r['check_out'] ?? ''));
    if ($in === '' || $out === '') {
        return '';
    }
    return (string)max(0, (int)(new DateTime($in))->diff(new DateTime($out))->format('%r%a'));
};
$statusLabel = static fn(string $s): string => ucwords(str_replace('_', ' ', $s));
$fmtDate = static fn($d): string => $d ? date('d M Y', strtotime((string)$d)) : '—';

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ars-move-' . $tab . '-' . $dateFrom . '-to-' . $dateTo . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Type', 'Booking #', 'Guest', 'Phone', 'Nationality', 'Unit', 'Building',
        'Check-in', 'Check-out', 'Nights', 'Guests', 'Source', 'Status', 'Done', 'Inspection']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['move_date'],
            $r['move_type'] === 'out' ? 'Move-out' : 'Move-in',
            $r['booking_number'],
            trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            $r['guest_phone'] ?? '',
            $r['nationality'] ?? '',
            $r['unit_number'] ?? '',
            $r['building_name'] ?? '',
            $r['check_in'],
            $r['actual_check_out'] ?: $r['check_out'],
            $nightsOf($r),
            $r['num_guests'],
            $r['booking_source'],
            $statusLabel((string)$r['status']),
            $r['move_done'] ? 'Yes' : 'No',
            ars_move_inspection_summary_label($inspections[$r['id'] . ':' . $r['move_type']] ?? null),
        ]);
    }
    fclose($out);
    exit;
}

$baseQuery = ['from' => $dateFrom, 'to' => $dateTo, 'q' => $search];
$tabLink = static fn(string $t): string => '?' . http_build_query(array_merge($baseQuery, ['tab' => $t]));
$exportUrl = '?' . http_build_query(array_merge($baseQuery, ['tab' => $tab, 'state' => $state, 'export' => 'csv']));

$pageTitle = 'Move-in / Move-out Report';
$tabClass = static fn(bool $on): string => 'no-underline rounded-ars-md px-3 py-1.5 text-ars-sm font-semibold '
    . ($on ? 'bg-ars-ink text-white' : 'text-ars-muted hover:text-ars-text');
$tabsHtml = '<div class="inline-flex flex-wrap gap-1 rounded-ars-lg border border-ars-border bg-ars-surface p-1">'
    . '<a class="' . $tabClass($isAll) . '" href="' . h($tabLink('all')) . '">All</a>'
    . '<a class="' . $tabClass($tab === 'in') . '" href="' . h($tabLink('in')) . '">Move-ins</a>'
    . '<a class="' . $tabClass($isOut) . '" href="' . h($tabLink('out')) . '">Move-outs</a>'
    . '</div>';

ars_shell_begin([
    'title' => 'Move-in / Move-out Report',
    'subtitle' => $fmtDate($dateFrom) . ' to ' . $fmtDate($dateTo),
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Reports', 'href' => 'reports.php'],
        ['label' => 'Move-in / Move-out'],
    ],
    'actions_html' => '<a class="no-underline inline-flex items-center gap-1 rounded-ars-md border border-ars-border px-3 py-2 text-ars-sm font-semibold text-ars-text hover:border-ars-ink/40" href="' . h($exportUrl) . '">'
        . ars_ui_icon('download', ['class' => 'h-4 w-4']) . ' CSV</a>'
        . ' ' . ars_move_inspection_nav_html('list'),
    'legacy_bootstrap' => true,
]);
?>

<div class="mb-4"><?= $tabsHtml ?></div>

<div class="row g-3 mb-3">
<?php if ($isAll): ?>
    <div class="col-12 col-lg-4"><?= ars_ds_stat_tile('All moves', (string)count($allRows), ['tone' => 'default', 'icon' => 'list', 'hint' => $doneCount . ' done · ' . $pendingCount . ' not yet']) ?></div>
    <div class="col-6 col-lg-4"><?= ars_ds_stat_tile('Move-ins', (string)$inCount, ['tone' => 'default', 'icon' => 'log-in', 'href' => $tabLink('in'), 'hint' => 'In this date range']) ?></div>
    <div class="col-6 col-lg-4"><?= ars_ds_stat_tile('Move-outs', (string)$outCount, ['tone' => 'default', 'icon' => 'log-out', 'href' => $tabLink('out'), 'hint' => 'In this date range']) ?></div>
<?php else: ?>
    <div class="col-12 col-lg-4"><?= ars_ds_stat_tile($isOut ? 'Move-outs' : 'Move-ins', (string)count($allRows), ['tone' => 'default', 'icon' => $isOut ? 'log-out' : 'log-in', 'hint' => 'In this date range']) ?></div>
    <div class="col-6 col-lg-4"><?= ars_ds_stat_tile($isOut ? 'Moved out' : 'Moved in', (string)$doneCount, ['tone' => 'ok', 'icon' => 'check-circle', 'hint' => $isOut ? 'Checked out' : 'Checked in']) ?></div>
    <div class="col-6 col-lg-4"><?= ars_ds_stat_tile('Not yet', (string)$pendingCount, ['tone' => $pendingCount > 0 ? 'warn' : 'ok', 'icon' => 'clock', 'hint' => $isOut ? 'Still to check out' : 'Still to check in']) ?></div>
<?php endif; ?>
</div>

<div class="ars-card mb-3">
    <div class="card-body py-3">
        <form class="row g-2 align-items-end" method="get">
            <input type="hidden" name="tab" value="<?= h($tab) ?>">
            <div class="col-sm-3 col-lg-2">
                <label class="form-label small fw-semibold mb-1">From</label>
                <input class="form-control form-control-sm" name="from" type="date" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label small fw-semibold mb-1">To</label>
                <input class="form-control form-control-sm" name="to" type="date" value="<?= h($dateTo) ?>">
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label small fw-semibold mb-1">Show</label>
                <select class="form-select form-select-sm" name="state">
                    <option value="">All</option>
                    <option value="done" <?= $state === 'done' ? 'selected' : '' ?>><?= $isAll ? 'Done' : ($isOut ? 'Moved out' : 'Moved in') ?></option>
                    <option value="pending" <?= $state === 'pending' ? 'selected' : '' ?>>Not yet</option>
                </select>
            </div>
            <div class="col-sm-3 col-lg-3">
                <label class="form-label small fw-semibold mb-1">Search</label>
                <input class="form-control form-control-sm" name="q" placeholder="Guest, unit, building, booking #" value="<?= h($search) ?>">
            </div>
            <div class="col-sm-2 col-lg-2">
                <button class="btn btn-sm btn-ars w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
            </div>
            <div class="col-auto">
                <a class="btn btn-sm btn-outline-secondary" href="?tab=<?= h($tab) ?>">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="ars-card">
<div class="table-responsive">
    <table class="table table-sm ars-table ars-mobile-cards mb-0 align-middle">
        <thead>
            <tr>
                <th><?= $isAll ? 'Date' : ($isOut ? 'Move-out' : 'Move-in') ?></th>
                <?php if ($isAll): ?><th>Type</th><?php endif; ?>
                <th>Booking</th>
                <th>Guest</th>
                <th>Phone</th>
                <th>Unit</th>
                <th>Building</th>
                <th><?= $isAll ? 'Stay' : ($isOut ? 'Moved in' : 'Move-out') ?></th>
                <th class="text-end">Nights</th>
                <th class="text-end">Guests</th>
                <th>Status</th>
                <th>Inspection</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php
            $rowOut = $r['move_type'] === 'out';
            $endDate = $r['actual_check_out'] ?: $r['check_out'];
            $otherDate = $isAll
                ? ars_ds_format_stay((string)$r['check_in'], (string)$endDate)
                : $fmtDate($rowOut ? $r['check_in'] : $endDate);
            $early = $rowOut && !empty($r['actual_check_out']) && $r['actual_check_out'] < $r['check_out'];
            $insp = $inspections[$r['id'] . ':' . $r['move_type']] ?? null;
            $inspOpen = $insp || in_array($r['status'], ars_move_inspection_allowed_statuses($r['move_type']), true);
            $inspUrl = 'move_inspection.php?' . http_build_query(['booking_id' => (int)$r['id'], 'type' => $r['move_type']]);
            ?>
            <tr>
                <td data-label="Date" class="text-nowrap fw-semibold">
                    <?= h($fmtDate($r['move_date'])) ?>
                    <?php if ($early): ?><span class="badge bg-warning text-dark ms-1">Early</span><?php endif; ?>
                </td>
                <?php if ($isAll): ?>
                <td data-label="Type"><span class="badge <?= $rowOut ? 'bg-secondary' : 'bg-primary' ?>"><?= $rowOut ? 'Move-out' : 'Move-in' ?></span></td>
                <?php endif; ?>
                <td data-label="Booking"><a class="text-decoration-none fw-semibold font-monospace small" href="booking_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['booking_number']) ?></a></td>
                <td data-label="Guest"><?= h(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: '—') ?></td>
                <td data-label="Phone" class="text-nowrap"><?= h($r['guest_phone'] ?: '—') ?></td>
                <td data-label="Unit" class="fw-semibold"><?= h($r['unit_number'] ?: '—') ?></td>
                <td data-label="Building"><?= h($r['building_name'] ?: '—') ?></td>
                <td data-label="<?= $isAll ? 'Stay' : ($rowOut ? 'Moved in' : 'Move-out') ?>" class="text-nowrap"><?= h($otherDate) ?></td>
                <td data-label="Nights" class="text-end ars-tabular"><?= h($nightsOf($r)) ?></td>
                <td data-label="Guests" class="text-end ars-tabular"><?= (int)$r['num_guests'] ?></td>
                <td data-label="Status"><span class="badge <?= $r['move_done'] ? 'bg-success' : 'bg-warning text-dark' ?>"><?= h($statusLabel((string)$r['status'])) ?></span></td>
                <td data-label="Inspection" class="text-nowrap">
                    <?php if (!$inspOpen): ?>
                        <span class="text-muted small" title="Opens once the guest is checked in">—</span>
                    <?php elseif (!$insp): ?>
                        <a class="btn btn-sm btn-ars-outline py-0" href="<?= h($inspUrl) ?>"><i class="bi bi-list-check me-1"></i>Start</a>
                    <?php else: ?>
                        <a class="badge text-decoration-none <?= $insp['status'] === 'completed' ? 'bg-success' : 'bg-warning text-dark' ?>" href="<?= h($inspUrl) ?>"><?= h(ars_move_inspection_summary_label($insp)) ?></a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= $isAll ? 12 : 11 ?>" class="text-center text-muted py-4">No <?= $isAll ? 'moves' : ($isOut ? 'move-outs' : 'move-ins') ?> in this date range.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
</div>

<?php ars_shell_end(); ?>
