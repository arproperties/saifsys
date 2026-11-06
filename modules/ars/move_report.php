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
require_once __DIR__ . '/includes/ars_permissions.php';
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
// Sort: newest date first unless chosen otherwise
$sortCols = ['date' => 'Date', 'guest' => 'Guest', 'unit' => 'Unit', 'building' => 'Building', 'nights' => 'Nights', 'status' => 'Status'];
$sort = $_GET['sort'] ?? 'date';
if (!isset($sortCols[$sort])) {
    $sort = 'date';
}
$dir = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : ((($_GET['dir'] ?? '') === 'desc') ? 'desc' : ($sort === 'date' || $sort === 'nights' ? 'desc' : 'asc'));

$doneFor = [
    'in' => ['checked_in', 'checked_out', 'completed'],
    'out' => ['checked_out', 'completed'],
];
$fetchMoves = static function (string $type) use ($conn, $arsCompanyId, $dateFrom, $dateTo, $search, $doneFor): array {
    // A guest who left early moved out on the actual date, not the booked one.
    $dateCol = $type === 'out' ? 'COALESCE(b.actual_check_out, b.check_out)' : 'b.check_in';
    $sql = "
        SELECT b.id, b.booking_number, b.status, b.check_in, b.check_out, b.actual_check_out, b.unit_id,
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
// Move-In / Move-Out process per booking: "bookingId:in|out" => status (cancelled ones left out)
require_once __DIR__ . '/includes/ars_move_process.php';
$processes = [];
// Process rule: stays of at least N nights, or units set to always use it; shorter stays check in / out on the booking
$ruleMin = amp_ready($conn) ? amp_min_nights($conn, $arsCompanyId) : 30;
$ruleUnits = amp_ready($conn) ? amp_forced_units($conn, $arsCompanyId) : [];
$ruleOn = static fn(array $r): bool => isset($ruleUnits[(int)($r['unit_id'] ?? 0)]) || amp_booking_nights($r) >= $ruleMin;
if ($allRows && amp_ready($conn)) {
    $ids = array_values(array_unique(array_map('intval', array_column($allRows, 'id'))));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach (['in' => 'ars_move_ins', 'out' => 'ars_move_outs'] as $pt => $ptable) {
        $ps = $conn->prepare("SELECT booking_id, status FROM $ptable WHERE company_id = ? AND status <> 'cancelled' AND booking_id IN ($ph) ORDER BY id");
        $ps->execute(array_merge([$arsCompanyId], $ids));
        foreach ($ps->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            $processes[$p['booking_id'] . ':' . $pt] = $p['status'];
        }
    }
}

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
$sortKey = static function (array $r) use ($sort, $nightsOf): array {
    $guest = mb_strtolower(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')));
    switch ($sort) {
        case 'guest': return [$guest];
        case 'unit': return [mb_strtolower((string)$r['unit_number'])];
        case 'building': return [mb_strtolower((string)$r['building_name']), mb_strtolower((string)$r['unit_number'])];
        case 'nights': return [(int)$nightsOf($r)];
        case 'status': return [(string)$r['status']];
        default: return [(string)$r['move_date']];
    }
};
usort($rows, static function (array $a, array $b) use ($sortKey, $dir): int {
    $c = $sortKey($a) <=> $sortKey($b);
    if ($c === 0) {
        // Same value: newest date first, then unit and booking
        $c = [$b['move_date'], (string)$a['unit_number'], $a['booking_number']] <=> [$a['move_date'], (string)$b['unit_number'], $b['booking_number']];
        return $c;
    }
    return $dir === 'asc' ? $c : -$c;
});

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

$baseQuery = ['from' => $dateFrom, 'to' => $dateTo, 'q' => $search, 'sort' => $sort, 'dir' => $dir];
// Column heading that sorts by that column (click again to reverse)
$sortTh = static function (string $col, string $label, string $class = '') use ($sort, $dir, $baseQuery, $tab, $state): string {
    $on = $sort === $col;
    $next = $on ? ($dir === 'asc' ? 'desc' : 'asc') : ($col === 'date' || $col === 'nights' ? 'desc' : 'asc');
    $url = '?' . http_build_query(array_merge($baseQuery, ['tab' => $tab, 'state' => $state, 'sort' => $col, 'dir' => $next]));
    $icon = $on ? ($dir === 'asc' ? 'bi-sort-up' : 'bi-sort-down') : 'bi-arrow-down-up opacity-25';
    return '<th class="' . $class . '"' . ($on ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '') . '>'
        . '<a class="text-reset text-decoration-none d-inline-flex align-items-center gap-1" href="' . h($url) . '" title="Sort by ' . h(strtolower($label)) . '">' . h($label) . ' <i class="bi ' . $icon . '"></i></a></th>';
};
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

// Start Move-In / Move-Out by unit: per unit, the bookings that can move in (pending / confirmed)
// or move out (checked in), plus any process still open
$pickUnits = [];
if (amp_ready($conn)) {
    foreach (ars_fetch_short_term_units($conn, $arsCompanyId) as $u) {
        $pickUnits[(int)$u['id']] = ['id' => (int)$u['id'], 'unit' => (string)$u['unit_number'], 'building' => (string)($u['building_name'] ?? ''), 'title' => (string)($u['listing_title'] ?? ''), 'bookings' => []];
    }
    $pq = $conn->prepare("
        SELECT b.id, b.booking_number, b.status, b.unit_id, b.check_in, b.check_out, b.nights,
               TRIM(CONCAT(COALESCE(g.first_name, ''), ' ', COALESCE(g.last_name, ''))) AS guest,
               (SELECT mi.status FROM ars_move_ins mi WHERE mi.booking_id = b.id AND mi.status <> 'cancelled' ORDER BY mi.id DESC LIMIT 1) AS in_status,
               (SELECT mo.status FROM ars_move_outs mo WHERE mo.booking_id = b.id AND mo.status <> 'cancelled' ORDER BY mo.id DESC LIMIT 1) AS out_status
        FROM ars_bookings b
        LEFT JOIN ars_guests g ON g.id = b.guest_id
        WHERE b.company_id = ? AND b.status IN ('pending', 'confirmed', 'checked_in', 'checked_out')
        ORDER BY b.check_in
    ");
    $pq->execute([$arsCompanyId]);
    $pMin = amp_min_nights($conn, $arsCompanyId);
    $pForced = amp_forced_units($conn, $arsCompanyId);
    foreach ($pq->fetchAll(PDO::FETCH_ASSOC) ?: [] as $pb) {
        $canIn = in_array($pb['status'], ['pending', 'confirmed'], true) || $pb['in_status'] === 'in_progress';
        $canOut = $pb['status'] === 'checked_in' || $pb['out_status'] === 'in_progress';
        if ((!$canIn && !$canOut) || !isset($pickUnits[(int)$pb['unit_id']])) {
            continue;
        }
        $pickUnits[(int)$pb['unit_id']]['bookings'][] = [
            'id' => (int)$pb['id'], 'no' => (string)$pb['booking_number'], 'guest' => $pb['guest'] !== '' ? $pb['guest'] : '—',
            'stay' => $fmtDate($pb['check_in']) . ' → ' . $fmtDate($pb['check_out']), 'nights' => amp_booking_nights($pb),
            'status' => $statusLabel((string)$pb['status']),
            'in' => $canIn ? ($pb['in_status'] === 'in_progress' ? 'open' : 'start') : '',
            'out' => $canOut ? ($pb['out_status'] === 'in_progress' ? 'open' : 'start') : '',
            'short' => !isset($pForced[(int)$pb['unit_id']]) && amp_booking_nights($pb) < $pMin && !$pb['in_status'] && !$pb['out_status'],
        ];
    }
    // Units with a booking to move in / out first
    $pickUnits = array_values($pickUnits);
    usort($pickUnits, static fn($a, $b) => [count($b['bookings']) > 0, $a['building'], $a['unit']] <=> [count($a['bookings']) > 0, $b['building'], $b['unit']]);
}

ars_shell_begin([
    'title' => $tab === 'in' ? 'Move-In' : ($tab === 'out' ? 'Move-Out' : 'Move-In / Move-Out'),
    'subtitle' => $fmtDate($dateFrom) . ' to ' . $fmtDate($dateTo),
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Reports', 'href' => 'reports.php'],
        ['label' => $tab === 'in' ? 'Move-In' : ($tab === 'out' ? 'Move-Out' : 'Move-In / Move-Out')],
    ],
    'actions_html' => ($pickUnits ? '<button type="button" class="inline-flex items-center gap-1 rounded-ars-md border-0 px-3 py-2 text-ars-sm font-semibold text-white" style="background:#b8860b" data-bs-toggle="modal" data-bs-target="#ampPickModal">'
        . ars_ui_icon('door-open', ['class' => 'h-4 w-4']) . ' Start Move-In / Move-Out</button> ' : '')
        . '<a class="no-underline inline-flex items-center gap-1 rounded-ars-md border border-ars-border px-3 py-2 text-ars-sm font-semibold text-ars-text hover:border-ars-ink/40" href="' . h($exportUrl) . '">'
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
            <div class="col-sm-3 col-lg-2">
                <label class="form-label small fw-semibold mb-1">Sort by</label>
                <select class="form-select form-select-sm" name="sortby" onchange="var v=this.value.split(':'); this.form.sort.value=v[0]; this.form.dir.value=v[1]; this.form.submit();">
                    <?php foreach ([
                        'date:desc' => 'Date: newest first', 'date:asc' => 'Date: oldest first',
                        'guest:asc' => 'Guest: A to Z', 'guest:desc' => 'Guest: Z to A',
                        'unit:asc' => 'Unit', 'building:asc' => 'Building',
                        'nights:desc' => 'Nights: most first', 'nights:asc' => 'Nights: fewest first',
                        'status:asc' => 'Status',
                    ] as $sv => $sl): ?>
                        <option value="<?= $sv ?>" <?= $sv === $sort . ':' . $dir ? 'selected' : '' ?>><?= h($sl) ?></option>
                    <?php endforeach; ?>
                    <?php if (!in_array($sort . ':' . $dir, ['date:desc', 'date:asc', 'guest:asc', 'guest:desc', 'unit:asc', 'building:asc', 'nights:desc', 'nights:asc', 'status:asc'], true)): ?>
                        <option value="<?= h($sort . ':' . $dir) ?>" selected><?= h($sortCols[$sort] . ($dir === 'asc' ? ' (ascending)' : ' (descending)')) ?></option>
                    <?php endif; ?>
                </select>
                <input type="hidden" name="sort" value="<?= h($sort) ?>"><input type="hidden" name="dir" value="<?= h($dir) ?>">
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label small fw-semibold mb-1">Search</label>
                <input class="form-control form-control-sm" name="q" placeholder="Guest, unit, building, booking #" value="<?= h($search) ?>">
            </div>
            <div class="col-sm-2 col-lg-auto">
                <button class="btn btn-sm btn-ars w-100 text-nowrap px-3"><i class="bi bi-funnel me-1"></i>Filter</button>
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
                <?= $sortTh('date', $isAll ? 'Date' : ($isOut ? 'Move-out' : 'Move-in')) ?>
                <?php if ($isAll): ?><th>Type</th><?php endif; ?>
                <th>Booking</th>
                <?= $sortTh('guest', 'Guest') ?>
                <th>Phone</th>
                <?= $sortTh('unit', 'Unit') ?>
                <?= $sortTh('building', 'Building') ?>
                <th><?= $isAll ? 'Stay' : ($isOut ? 'Moved in' : 'Move-out') ?></th>
                <?= $sortTh('nights', 'Nights', 'text-end') ?>
                <th class="text-end">Guests</th>
                <?= $sortTh('status', 'Status') ?>
                <th>Move-in / out process</th>
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
            $inspUrl = ($r['move_type'] === 'out' ? 'move_out_view.php' : 'move_in_view.php') . '?booking_id=' . (int)$r['id'];
            $proc = $processes[$r['id'] . ':' . $r['move_type']] ?? null;
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
                    <?php if ($proc): ?>
                        <a class="badge text-decoration-none <?= $proc === 'completed' ? 'bg-success' : 'bg-warning text-dark' ?>" href="<?= h($inspUrl) ?>"><?= $proc === 'completed' ? 'Completed' : 'In progress' ?></a>
                    <?php elseif (($r['move_type'] === 'in' && in_array($r['status'], ['pending', 'confirmed'], true)) || ($r['move_type'] === 'out' && $r['status'] === 'checked_in')): ?>
                        <?php if ($ruleOn($r)): ?>
                        <a class="btn btn-sm btn-ars-outline py-0" href="<?= h($inspUrl) ?>"><i class="bi bi-play-circle me-1"></i>Start</a>
                        <?php else: ?>
                        <a class="small text-decoration-none" href="booking_view.php?id=<?= (int)$r['id'] ?>" title="Under <?= (int)$ruleMin ?> nights: <?= $r['move_type'] === 'out' ? 'check out' : 'check in' ?> on the booking (no inspection process)">Short stay · <?= $r['move_type'] === 'out' ? 'Check out' : 'Check in' ?></a>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted small" title="Done before the move-in / move-out process">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= $isAll ? 12 : 11 ?>" class="text-center text-muted py-4">No <?= $isAll ? 'moves' : ($isOut ? 'move-outs' : 'move-ins') ?> in this date range.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
</div>

<?php if ($pickUnits): ?>
<style>
#ampPickModal .amp-pick-units { max-height: 360px; overflow: auto; border: 1px solid var(--bs-border-color); border-radius: .5rem; }
#ampPickModal .amp-pick-unit { display: flex; align-items: center; gap: .6rem; width: 100%; text-align: left; padding: .5rem .75rem; border: 0; border-bottom: 1px solid var(--bs-border-color-translucent); background: #fff; }
#ampPickModal .amp-pick-unit:last-child { border-bottom: 0; }
#ampPickModal .amp-pick-unit:hover { background: rgba(184,134,11,.06); }
#ampPickModal .amp-pick-unit.is-active { background: rgba(184,134,11,.14); }
#ampPickModal .amp-pick-unit .cnt { margin-left: auto; flex-shrink: 0; }
#ampPickModal .amp-pick-bk { border: 1px solid var(--bs-border-color); border-radius: .5rem; padding: .65rem .8rem; margin-bottom: .5rem; }
#ampPickModal .btn-amp-gold { background: #b8860b; border-color: #b8860b; color: #fff; }
#ampPickModal .btn-amp-gold:hover { background: #9a7009; border-color: #9a7009; color: #fff; }
</style>
<div class="modal fade" id="ampPickModal" tabindex="-1" aria-labelledby="ampPickTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="ampPickTitle"><i class="bi bi-door-open me-2"></i>Start Move-In / Move-Out</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-5">
            <label class="form-label fw-semibold small" for="ampPickFind">1. Select the unit</label>
            <input type="search" id="ampPickFind" class="form-control form-control-sm mb-2" placeholder="Find unit or building" autocomplete="off">
            <div class="amp-pick-units" id="ampPickUnits"></div>
          </div>
          <div class="col-md-7">
            <div class="form-label fw-semibold small">2. Choose the booking</div>
            <div id="ampPickBookings" class="small text-muted">Select a unit to see its bookings.</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
    var units = <?= json_encode($pickUnits, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var minNights = <?= (int)amp_min_nights($conn, $arsCompanyId) ?>;
    var list = document.getElementById("ampPickUnits"), find = document.getElementById("ampPickFind"), out = document.getElementById("ampPickBookings");
    var esc = function (v) { return String(v).replace(/[&<>"']/g, function (c) { return {"&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"}[c]; }); };
    var active = null;
    function drawUnits() {
        var q = find.value.trim().toLowerCase();
        list.innerHTML = units.filter(function (u) { return !q || (u.unit + " " + u.building + " " + u.title).toLowerCase().indexOf(q) !== -1; }).map(function (u) {
            return '<button type="button" class="amp-pick-unit' + (active === u.id ? " is-active" : "") + '" data-unit="' + u.id + '"><strong>' + esc(u.unit) + '</strong><span class="small text-muted text-truncate">' + esc(u.building) + '</span>'
                + '<span class="cnt badge ' + (u.bookings.length ? "bg-warning text-dark" : "bg-light text-muted border") + '">' + (u.bookings.length || "none") + '</span></button>';
        }).join("") || '<div class="small text-muted p-3">No unit matches.</div>';
    }
    function action(b, type) {
        var st = b[type];
        if (!st) return "";
        var label = type === "in" ? "Move-In" : "Move-Out", url = (type === "in" ? "move_in_view.php" : "move_out_view.php") + "?booking_id=" + b.id;
        return '<a class="btn btn-sm ' + (st === "open" ? "btn-outline-secondary" : "btn-amp-gold") + '" href="' + url + '"><i class="bi ' + (type === "in" ? "bi-box-arrow-in-right" : "bi-box-arrow-right") + ' me-1"></i>' + (st === "open" ? "Continue " : "Start ") + label + '</a>';
    }
    function drawBookings(u) {
        if (!u.bookings.length) {
            out.innerHTML = '<div class="alert alert-light border small mb-2">Unit <strong>' + esc(u.unit) + '</strong> has no booking to move in or out (pending, confirmed or checked in).</div><a class="btn btn-sm btn-outline-secondary" href="booking_add.php"><i class="bi bi-plus-circle me-1"></i>New booking</a>';
            return;
        }
        out.innerHTML = '<div class="small text-muted mb-2">Unit <strong class="text-body">' + esc(u.unit) + '</strong> · ' + esc(u.building) + '</div>' + u.bookings.map(function (b) {
            return '<div class="amp-pick-bk"><div class="d-flex flex-wrap justify-content-between gap-2"><div><a class="fw-semibold font-monospace text-decoration-none" href="booking_view.php?id=' + b.id + '">' + esc(b.no) + '</a> · ' + esc(b.guest)
                + '<div class="text-muted">' + esc(b.stay) + ' · ' + b.nights + ' night' + (b.nights === 1 ? "" : "s") + ' · ' + esc(b.status) + '</div>'
                + (b.short ? '<div class="text-muted"><i class="bi bi-info-circle"></i> Short stay (under ' + minNights + ' nights): the process is optional</div>' : '')
                + '</div><div class="d-flex flex-wrap gap-1 align-items-start">' + action(b, "in") + action(b, "out") + '</div></div></div>';
        }).join("");
    }
    list.addEventListener("click", function (e) {
        var btn = e.target.closest("[data-unit]");
        if (!btn) return;
        active = +btn.dataset.unit;
        drawUnits();
        drawBookings(units.find(function (u) { return u.id === active; }));
    });
    find.addEventListener("input", drawUnits);
    document.getElementById("ampPickModal").addEventListener("shown.bs.modal", function () { find.focus(); });
    drawUnits();
})();
</script>
<?php endif; ?>

<?php ars_shell_end(); ?>
