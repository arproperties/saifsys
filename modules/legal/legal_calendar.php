<?php
/**
 * Legal Module - Hearings & Deadlines Calendar.
 *
 * Month grid with six layers, each toggleable:
 *   hearings, case deadlines, notice deadlines   (legal records)
 *   cheques due      — unpaid cheques dated that day (same rule as the dashboard:
 *                      not cleared / fully collected, not bounced or returned)
 *   bounces          — cheques with status bounced/returned, by bounced_date
 *   leases ending    — lease end_date that day while the tenant still owes
 *                      (bounced + overdue, as on the dashboard)
 * Side panel: the next 14 days (same window as the dashboard "Due in 14 days").
 * Grid chips, the day pop-up and the agenda all read the same $events array.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/legal_helper.php';
require_once __DIR__ . '/includes/legal_lease_watch.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$re = legal_re_path();

// "Today" from the database, so the 14-day window matches the dashboard (CURDATE()).
$today = (string)($conn->query('SELECT CURDATE()')->fetchColumn() ?: date('Y-m-d'));
$todayTs = strtotime($today);

$year = (int)($_GET['year'] ?? date('Y', $todayTs));
$month = (int)($_GET['month'] ?? date('n', $todayTs));
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }
if ($year < 2000 || $year > 2100) { $year = (int)date('Y', $todayTs); }
$firstDay = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = (int)date('t', $firstDay);
$monthStart = date('Y-m-01', $firstDay);
$monthEnd = date('Y-m-t', $firstDay);

$agendaFrom = $today;
$agendaTo = date('Y-m-d', strtotime('+' . LEGAL_WATCH_DUE_SOON_DAYS . ' days', $todayTs));
$from = min($monthStart, $agendaFrom);
$to = max($monthEnd, $agendaTo);

// Layer definitions: key => [label, colour, icon, short description]
$layers = [
    'hearing'  => ['Hearings',         '#4f46e5', 'bi-bank2',            'Court hearings and scheduled events'],
    'deadline' => ['Case deadlines',   '#db2777', 'bi-flag-fill',        'Deadline date of open legal cases'],
    'notice'   => ['Notice deadlines', '#0891b2', 'bi-envelope-paper-fill', 'Response deadline of legal notices'],
    'due'      => ['Cheques due',      '#eda100', 'bi-cash-coin',        'Unpaid cheques dated that day (past days = still unpaid, overdue)'],
    'bounce'   => ['Bounces',          '#e34948', 'bi-x-octagon-fill',   'Cheques that bounced or were returned that day (by bounce date)'],
    'ending'   => ['Leases ending',    '#7c3aed', 'bi-door-open-fill',   'Lease end date that day while the tenant still owes (bounced + overdue)'],
];

$events = [];   // date => list of items
$loadError = null;
$add = function (string $date, array $item) use (&$events) {
    $events[$date][] = $item;
};

// ---- Hearings ----
try {
    $h = $conn->prepare("
        SELECT h.*, c.case_number FROM re_legal_hearings h
        LEFT JOIN re_legal_cases c ON c.id = h.case_id
        WHERE h.company_id = ? AND h.scheduled_date BETWEEN ? AND ?
        ORDER BY h.scheduled_date, h.scheduled_time
    ");
    $h->execute([$currentCompanyId, $from, $to]);
    $hTypes = legal_hearing_types();
    foreach ($h->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $links = [];
        if (!empty($row['case_id'])) $links[] = ['Case ' . ($row['case_number'] ?: '#' . $row['case_id']), 'legal_case_view.php?id=' . (int)$row['case_id'], 'bi-briefcase'];
        $sub = array_filter([
            $hTypes[$row['event_type']] ?? ucfirst((string)$row['event_type']),
            $row['scheduled_time'] ? date('g:i A', strtotime($row['scheduled_time'])) : null,
            trim(($row['location'] ?? '') . ($row['court_room'] ? ' · Room ' . $row['court_room'] : '')) ?: null,
            $row['case_number'] ? 'Case ' . $row['case_number'] : null,
        ]);
        $add($row['scheduled_date'], [
            'layer' => 'hearing', 'title' => (string)$row['title'], 'sub' => implode(' · ', $sub),
            'status' => (string)$row['status'], 'time' => (string)($row['scheduled_time'] ?? ''), 'amt' => 0, 'links' => $links,
        ]);
    }
} catch (Throwable $e) { $loadError = $e->getMessage(); }

// ---- Case deadlines ----
try {
    $cd = $conn->prepare("
        SELECT id, case_number, title, deadline_date FROM re_legal_cases
        WHERE company_id = ? AND deleted_at IS NULL AND deadline_date BETWEEN ? AND ?
          AND status NOT IN ('settled','closed','withdrawn')
    ");
    $cd->execute([$currentCompanyId, $from, $to]);
    foreach ($cd->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $add($row['deadline_date'], [
            'layer' => 'deadline', 'title' => $row['case_number'] . ' deadline', 'sub' => (string)$row['title'],
            'status' => '', 'time' => '', 'amt' => 0,
            'links' => [['Open case', 'legal_case_view.php?id=' . (int)$row['id'], 'bi-briefcase']],
        ]);
    }
} catch (Throwable $e) { $loadError = $loadError ?: $e->getMessage(); }

// ---- Notice response deadlines ----
try {
    $nd = $conn->prepare("
        SELECT id, reference_number, subject, response_deadline FROM re_legal_notices
        WHERE company_id = ? AND response_deadline BETWEEN ? AND ?
    ");
    $nd->execute([$currentCompanyId, $from, $to]);
    foreach ($nd->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $add($row['response_deadline'], [
            'layer' => 'notice', 'title' => (string)$row['reference_number'], 'sub' => 'Response due · ' . $row['subject'],
            'status' => '', 'time' => '', 'amt' => 0,
            'links' => [['Open notice', 'legal_notice_view.php?id=' . (int)$row['id'], 'bi-envelope-paper']],
        ]);
    }
} catch (Throwable $e) { $loadError = $loadError ?: $e->getMessage(); }

// ---- Cheques due + bounces (same lease scope and "collected" rule as Lease Watch) ----
try {
    $hasLinks = (bool)$conn->query("SHOW TABLES LIKE 're_receipt_cheque_links'")->fetchColumn();
} catch (Throwable $e) { $hasLinks = false; }
$collSql = "SELECT p.cheque_id, p.amount AS amt FROM re_payments p WHERE p.company_id = :c1 AND p.cheque_id IS NOT NULL";
if ($hasLinks) {
    $collSql .= " UNION ALL SELECT k.cheque_id, k.amount_applied FROM re_receipt_cheque_links k WHERE k.company_id = :c2";
}
try {
    $sql = "
        SELECT c.id, c.lease_id, c.status, c.cheque_date, c.bounced_date, c.cheque_amount, c.cheque_number, c.bank_name,
               LEAST(COALESCE(col.got, 0), c.cheque_amount) AS got,
               (c.status IN ('cleared','paid','deposited') OR COALESCE(col.got,0) >= c.cheque_amount - 0.005) AS is_paid,
               (c.replaced_by_cheque_id IS NOT NULL OR c.settlement_payment_id IS NOT NULL) AS is_settled,
               l.lease_number, u.id AS unit_id, u.unit_number, b.id AS building_id, b.name AS building_name,
               t.id AS tenant_id, t.first_name, t.last_name, t.company_name, t.tenant_type, t.phone
        FROM re_post_dated_cheques c
        JOIN re_leases l ON l.id = c.lease_id
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        JOIN re_tenants t ON t.id = l.tenant_id
        LEFT JOIN (SELECT cheque_id, SUM(amt) AS got FROM ({$collSql}) x GROUP BY cheque_id) col ON col.cheque_id = c.id
        WHERE c.company_id = :c3 AND c.status <> 'cancelled'
          AND l.company_id = :c4
          AND " . re_lease_not_deleted_sql($conn, 'l') . "
          AND b.name NOT LIKE '%test building%'
          AND ((c.cheque_date BETWEEN :f1 AND :t1)
               OR (c.status IN ('bounced','returned') AND c.bounced_date BETWEEN :f2 AND :t2))
        ORDER BY c.cheque_amount DESC
    ";
    $params = [':c1' => $currentCompanyId, ':c3' => $currentCompanyId, ':c4' => $currentCompanyId,
               ':f1' => $from, ':t1' => $to, ':f2' => $from, ':t2' => $to];
    if ($hasLinks) $params[':c2'] = $currentCompanyId;
    $st = $conn->prepare($sql);
    $st->execute($params);
    while ($c = $st->fetch(PDO::FETCH_ASSOC)) {
        $amt = (float)$c['cheque_amount'];
        $owed = max(0.0, $amt - (float)$c['got']);
        $bad = in_array($c['status'], ['bounced', 'returned'], true);
        $tenant = legal_tenant_name($c);
        $unit = trim($c['building_name'] . ' · Unit ' . $c['unit_number']);
        $leaseLink = [$c['lease_number'] ?: 'Lease #' . $c['lease_id'], $re . 'lease_view.php?id=' . (int)$c['lease_id'] . '#installments', 'bi-file-earmark-text'];
        $chqLink = ['Cheque', $re . 'billing_cheque_view.php?id=' . (int)$c['id'], 'bi-receipt'];
        $chqNo = $c['cheque_number'] ? 'Chq ' . $c['cheque_number'] : 'Chq #' . $c['id'];

        // Due layer: unpaid, not bounced/returned, dated in range.
        if (!$c['is_paid'] && !$bad && $c['cheque_date'] >= $from && $c['cheque_date'] <= $to) {
            $late = $c['cheque_date'] < $today ? (int)round(($todayTs - strtotime($c['cheque_date'])) / 86400) : 0;
            $statusTxt = $late > 0 ? 'Overdue ' . $late . ' day' . ($late === 1 ? '' : 's') : ($c['cheque_date'] === $today ? 'Due today' : 'Due');
            if ($c['status'] === 'held_by_finance') $statusTxt .= ' · held by finance';
            if ($c['status'] === 'legal_escalated') $statusTxt .= ' · with legal';
            $add($c['cheque_date'], [
                'layer' => 'due', 'title' => $tenant, 'sub' => $chqNo . ($c['bank_name'] ? ' · ' . $c['bank_name'] : '') . ' · ' . $unit,
                'status' => $statusTxt, 'late' => $late > 0, 'phone' => (string)$c['phone'], 'time' => '',
                'amt' => round($owed, 2), 'face' => round($amt, 2), 'links' => [$leaseLink, $chqLink],
            ]);
        }
        // Bounce layer: by bounced_date.
        if ($bad && $c['bounced_date'] && $c['bounced_date'] >= $from && $c['bounced_date'] <= $to) {
            $resolved = $c['is_paid'] || $c['is_settled'];
            $caseUrl = 'legal_case_add.php?' . http_build_query([
                'case_source' => 'bounced_cheque', 'lease_id' => $c['lease_id'], 'tenant_id' => $c['tenant_id'],
                'building_id' => $c['building_id'], 'unit_id' => $c['unit_id'], 'priority' => 'high',
                'title' => 'Bounced cheque – ' . $tenant . ' (' . trim($c['building_name'] . ' ' . $c['unit_number']) . ')',
                'claim_amount' => $owed > 0 ? round($owed, 2) : null,
            ]);
            $links = [$leaseLink, $chqLink];
            if (!$resolved) $links[] = ['New case', $caseUrl, 'bi-plus-lg'];
            $add($c['bounced_date'], [
                'layer' => 'bounce', 'title' => $tenant, 'sub' => $chqNo . ' · dated ' . date('j M Y', strtotime($c['cheque_date'])) . ' · ' . $unit,
                'status' => ucfirst($c['status']) . ($resolved ? ' · since replaced / paid' : ' · AED ' . number_format($owed) . ' still owed'),
                'late' => !$resolved, 'phone' => (string)$c['phone'], 'time' => '',
                'amt' => round($amt, 2), 'owed' => $resolved ? 0 : round($owed, 2), 'links' => $links,
            ]);
        }
    }
} catch (Throwable $e) { $loadError = $loadError ?: $e->getMessage(); }

// ---- Leases ending while the tenant still owes (Lease Watch "outstanding") ----
try {
    foreach (legal_lease_watch_rows($conn, (int)$currentCompanyId) as $r) {
        if (!$r['end_date'] || $r['end_date'] < $from || $r['end_date'] > $to || (float)$r['outstanding'] <= 0.005) continue;
        $parts = [];
        if ($r['bounced_count'] > 0) $parts[] = (int)$r['bounced_count'] . ' bounced AED ' . number_format((float)$r['bounced_amt']);
        if ($r['overdue_count'] > 0) $parts[] = (int)$r['overdue_count'] . ' overdue AED ' . number_format((float)$r['overdue_amt']);
        $links = [[$r['lease_number'] ?: 'Lease #' . $r['id'], $re . 'lease_view.php?id=' . (int)$r['id'] . '#installments', 'bi-file-earmark-text']];
        if ($r['open_cases'] > 0) {
            $links[] = ['Case', 'legal_case_list.php?search=' . urlencode((string)$r['lease_number']), 'bi-briefcase'];
        } else {
            $links[] = ['New case', 'legal_case_add.php?' . http_build_query([
                'case_source' => $r['bounced_count'] > 0 ? 'bounced_cheque' : 'manual',
                'lease_id' => $r['id'], 'tenant_id' => $r['tenant_id'], 'building_id' => $r['building_id'], 'unit_id' => $r['unit_id'],
                'priority' => $r['bounced_count'] > 0 ? 'high' : null,
                'title' => ($r['bounced_count'] > 0 ? 'Bounced cheques' : 'Rent arrears') . ' – ' . $r['tenant_label'] . ' (' . $r['unit_label'] . ')',
                'claim_amount' => round((float)$r['outstanding'], 2),
            ]), 'bi-plus-lg'];
        }
        $add($r['end_date'], [
            'layer' => 'ending', 'title' => $r['tenant_label'], 'sub' => ($r['lease_number'] ?: '#' . $r['id']) . ' · ' . $r['building_name'] . ' · Unit ' . $r['unit_number'],
            'status' => 'Lease ' . str_replace('_', ' ', $r['status']) . ' · owes ' . implode(' + ', $parts),
            'late' => true, 'phone' => (string)$r['phone'], 'time' => '',
            'amt' => round((float)$r['outstanding'], 2), 'links' => $links,
        ]);
    }
} catch (Throwable $e) { $loadError = $loadError ?: $e->getMessage(); }

// Order inside a day: legal records first (by time), then money.
$layerOrder = array_flip(array_keys($layers));
foreach ($events as &$list) {
    usort($list, fn($a, $b) => [$layerOrder[$a['layer']], $a['time'], -$a['amt']] <=> [$layerOrder[$b['layer']], $b['time'], -$b['amt']]);
}
unset($list);
ksort($events);

/** Per-layer count + AED for a set of dates. */
function lcal_totals(array $events, string $fromD, string $toD, array $layers): array
{
    $t = [];
    foreach ($layers as $k => $_) $t[$k] = ['n' => 0, 'amt' => 0.0, 'owed' => 0.0];
    foreach ($events as $d => $items) {
        if ($d < $fromD || $d > $toD) continue;
        foreach ($items as $it) {
            $t[$it['layer']]['n']++;
            $t[$it['layer']]['amt'] += (float)$it['amt'];
            $t[$it['layer']]['owed'] += (float)($it['owed'] ?? 0);
        }
    }
    return $t;
}
$monthTot = lcal_totals($events, $monthStart, $monthEnd, $layers);
$agendaTot = lcal_totals($events, $agendaFrom, $agendaTo, $layers);
$legalMonth = $monthTot['hearing']['n'] + $monthTot['deadline']['n'] + $monthTot['notice']['n'];
$legalAgenda = $agendaTot['hearing']['n'] + $agendaTot['deadline']['n'] + $agendaTot['notice']['n'];

/** Chips for one day: legal records one each; money layers one chip per layer with count + AED. */
function lcal_chips(array $items, array $layers): array
{
    $chips = [];
    $agg = [];
    foreach ($items as $it) {
        if (in_array($it['layer'], ['hearing', 'deadline', 'notice'], true)) {
            $chips[] = [$it['layer'], $it['title'], $it['title'] . ($it['sub'] ? ' — ' . $it['sub'] : ''), ''];
        } else {
            $agg[$it['layer']] ??= [0, 0.0];
            $agg[$it['layer']][0]++;
            $agg[$it['layer']][1] += (float)$it['amt'];
        }
    }
    $words = ['due' => ['due', 'due'], 'bounce' => ['bounced', 'bounced'], 'ending' => ['ending', 'ending']];
    // Label (count + word) can shorten in a narrow cell; the AED part always stays visible.
    foreach (['bounce', 'ending', 'due'] as $k) {
        if (!isset($agg[$k])) continue;
        [$n, $amt] = $agg[$k];
        $label = $n . ' ' . $words[$k][$n === 1 ? 0 : 1];
        $tip = $layers[$k][0] . ': ' . $n . ($k === 'ending' ? ' lease' . ($n === 1 ? '' : 's') . ', owing AED ' : ' cheque' . ($n === 1 ? '' : 's') . ', AED ') . number_format($amt);
        $chips[] = [$k, $label, $tip, lw_aed($amt)];
    }
    // Most urgent first in the cell.
    $rank = ['bounce' => 0, 'hearing' => 1, 'deadline' => 2, 'notice' => 3, 'ending' => 4, 'due' => 5];
    usort($chips, fn($a, $b) => $rank[$a[0]] <=> $rank[$b[0]]);
    return $chips;
}

$prevM = $month - 1; $prevY = $year;
$nextM = $month + 1; $nextY = $year;
if ($prevM < 1) { $prevM = 12; $prevY--; }
if ($nextM > 12) { $nextM = 1; $nextY++; }
$isThisMonth = $monthStart === date('Y-m-01', $todayTs);

// Grid: Monday-first weeks; Sat + Sun shaded (UAE weekend).
$lead = ((int)date('N', $firstDay)) - 1;
$cells = (int)ceil(($lead + $daysInMonth) / 7) * 7;
$gridStart = strtotime("-{$lead} days", $firstDay);

$success = $_SESSION['success'] ?? null;
unset($_SESSION['success']);

// Payload for the pop-up (only dates that are shown: this month + agenda window).
$payload = [];
foreach ($events as $d => $items) {
    if (($d >= $monthStart && $d <= $monthEnd) || ($d >= $agendaFrom && $d <= $agendaTo)) $payload[$d] = $items;
}

$pageTitle = 'Hearings Calendar';
$pageHead = '<link href="assets/legal-calendar.css?v=' . (@filemtime(__DIR__ . '/assets/legal-calendar.css') ?: 1) . '" rel="stylesheet">';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
<div class="lw-page lcal">

    <div class="lw-head d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h1 class="mb-1">Hearings Calendar</h1>
            <div class="lw-sub">Hearings, deadlines and the lease money that falls due · <?= h(date('l, j F Y', $todayTs)) ?></div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="legal_case_list.php" class="btn lw-btn-soft"><i class="bi bi-briefcase"></i> Cases</a>
            <a href="legal_notices.php" class="btn lw-btn-soft"><i class="bi bi-envelope-paper"></i> Notices</a>
            <a href="legal_hearing_add.php" class="btn lw-btn-grad"><i class="bi bi-plus-lg"></i> Add hearing</a>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><i class="bi bi-check-circle"></i> <?= h($success) ?></div><?php endif; ?>
    <?php if ($loadError): ?><div class="alert alert-danger">Some calendar data could not be loaded: <?= h($loadError) ?></div><?php endif; ?>

    <!-- This month at a glance — every figure opens the matching list -->
    <div class="lw-strip mb-4">
        <?php
        $segs = [
            ['navy', 'bi-bank2', (string)$legalMonth, 'Hearings & deadlines', $monthTot['hearing']['n'] . ' hearings · ' . ($monthTot['deadline']['n'] + $monthTot['notice']['n']) . ' deadlines', 'legal'],
            ['amber', 'bi-cash-coin', 'AED ' . lw_aed($monthTot['due']['amt']), 'Cheques unpaid', $monthTot['due']['n'] . ' cheques dated in ' . date('M', $firstDay) . ', not paid yet', 'due'],
            ['rose', 'bi-x-octagon-fill', (string)$monthTot['bounce']['n'], 'Bounced this month', 'AED ' . lw_aed($monthTot['bounce']['amt']) . ' · AED ' . lw_aed($monthTot['bounce']['owed']) . ' still owed', 'bounce'],
            ['violet', 'bi-door-open-fill', (string)$monthTot['ending']['n'], 'Leases ending, still owe', 'AED ' . lw_aed($monthTot['ending']['amt']) . ' owed', 'ending'],
        ];
        foreach ($segs as [$tone, $icon, $big, $label, $sub, $key]): ?>
            <div class="lw-alert lw-seg lw-st-<?= h($tone) ?>" title="<?= h(date('F Y', $firstDay)) ?> — click for the list">
                <button type="button" class="lw-alert-btn" data-month-layer="<?= h($key) ?>" aria-label="<?= h($label) ?>: open list"></button>
                <span class="lw-seg-ico"><i class="bi <?= h($icon) ?>"></i></span>
                <div class="lw-seg-body">
                    <div class="lw-seg-label"><?= h($label) ?> · <?= h(date('M', $firstDay)) ?></div>
                    <div class="lw-seg-big"><?= h($big) ?></div>
                    <div class="lw-seg-sub"><?= h($sub) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">
        <!-- Calendar -->
        <div class="col-xxl-9 col-xl-8">
            <div class="lw-panel lcal-panel">
                <div class="lcal-toolbar">
                    <div class="d-flex align-items-center gap-2">
                        <a href="?year=<?= $prevY ?>&month=<?= $prevM ?>" class="lcal-nav" title="Previous month" aria-label="Previous month"><i class="bi bi-chevron-left"></i></a>
                        <a href="?year=<?= (int)date('Y', $todayTs) ?>&month=<?= (int)date('n', $todayTs) ?>" class="lcal-today <?= $isThisMonth ? 'is-on' : '' ?>">Today</a>
                        <a href="?year=<?= $nextY ?>&month=<?= $nextM ?>" class="lcal-nav" title="Next month" aria-label="Next month"><i class="bi bi-chevron-right"></i></a>
                        <h2 class="lcal-month ms-2"><?= h(date('F', $firstDay)) ?> <span><?= h(date('Y', $firstDay)) ?></span></h2>
                    </div>
                    <div class="lcal-legend" id="lcalLegend" aria-label="Show or hide layers">
                        <?php foreach ($layers as $k => [$label, $color, $icon, $desc]): ?>
                            <button type="button" class="lcal-lg is-on" data-layer="<?= h($k) ?>" style="--c:<?= h($color) ?>" title="<?= h($desc) ?> — click to show / hide" aria-pressed="true">
                                <i class="bi <?= h($icon) ?>"></i> <?= h($label) ?> <span class="lcal-lg-n"><?= (int)$monthTot[$k]['n'] ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="lcal-grid" role="grid" aria-label="<?= h(date('F Y', $firstDay)) ?>">
                    <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $i => $dn): ?>
                        <div class="lcal-dow <?= $i >= 5 ? 'is-we' : '' ?>"><?= $dn ?></div>
                    <?php endforeach; ?>
                    <?php for ($i = 0; $i < $cells; $i++):
                        $ts = strtotime("+{$i} days", $gridStart);
                        $d = date('Y-m-d', $ts);
                        $inMonth = $d >= $monthStart && $d <= $monthEnd;
                        $dow = (int)date('N', $ts);
                        $items = $inMonth ? ($events[$d] ?? []) : [];
                        $chips = $items ? lcal_chips($items, $layers) : [];
                        $cls = ['lcal-day'];
                        if (!$inMonth) $cls[] = 'is-out';
                        if ($dow >= 6) $cls[] = 'is-we';
                        if ($d === $today) $cls[] = 'is-today';
                        if ($d < $today) $cls[] = 'is-past';
                        if ($items) $cls[] = 'has-ev';
                    ?>
                        <div class="<?= implode(' ', $cls) ?>" <?= $items ? 'data-day="' . h($d) . '" tabindex="0" role="button" aria-label="' . h(date('l j F', $ts)) . ': ' . count($items) . ' items"' : '' ?>>
                            <div class="lcal-dnum"><span><?= (int)date('j', $ts) ?></span><?php if ($d === $today): ?><em>Today</em><?php endif; ?></div>
                            <?php foreach ($chips as [$layer, $label, $tip, $amtTxt]): ?>
                                <span class="lcal-chip" data-layer="<?= h($layer) ?>" style="--c:<?= h($layers[$layer][1]) ?>" title="<?= h($tip) ?>"><i class="bi <?= h($layers[$layer][2]) ?>"></i><span><?= h($label) ?></span><?php if ($amtTxt !== ''): ?><b><?= h($amtTxt) ?></b><?php endif; ?></span>
                            <?php endforeach; ?>
                            <?php if ($chips): ?><span class="lcal-more d-none"></span><?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
                <div class="lw-muted mt-3 d-flex flex-wrap gap-3 justify-content-between">
                    <span><i class="bi bi-hand-index"></i> Click a day to see everything on it. Click a legend chip to hide or show that layer.</span>
                    <span>Cheques due on past days are still unpaid (overdue). Bounces are placed on the bounce date.</span>
                </div>
            </div>
        </div>

        <!-- Next 14 days -->
        <div class="col-xxl-3 col-xl-4">
            <div class="lw-panel lcal-agenda">
                <div class="lw-panel-head mb-3">
                    <div>
                        <h2>Next 14 days</h2>
                        <div class="lw-muted"><?= h(date('j M', strtotime($agendaFrom))) ?> – <?= h(date('j M Y', strtotime($agendaTo))) ?></div>
                    </div>
                    <span class="lw-sq lw-tone-violet"><i class="bi bi-calendar2-week"></i></span>
                </div>

                <div class="lcal-ag-tots">
                    <button type="button" class="lcal-ag-tot" data-agenda-layer="due" style="--c:#eda100">
                        <span class="lcal-ag-ico"><i class="bi bi-cash-coin"></i></span>
                        <span class="lcal-ag-lbl">AED due</span>
                        <strong>AED <?= h(lw_aed($agendaTot['due']['amt'])) ?></strong>
                        <small><?= (int)$agendaTot['due']['n'] ?> unpaid cheque<?= $agendaTot['due']['n'] === 1 ? '' : 's' ?></small>
                    </button>
                    <button type="button" class="lcal-ag-tot" data-agenda-layer="ending" style="--c:#7c3aed">
                        <span class="lcal-ag-ico"><i class="bi bi-door-open-fill"></i></span>
                        <span class="lcal-ag-lbl">Leases ending</span>
                        <strong><?= (int)$agendaTot['ending']['n'] ?></strong>
                        <small>with a balance · AED <?= h(lw_aed($agendaTot['ending']['amt'])) ?></small>
                    </button>
                    <button type="button" class="lcal-ag-tot" data-agenda-layer="legal" style="--c:#4f46e5">
                        <span class="lcal-ag-ico"><i class="bi bi-bank2"></i></span>
                        <span class="lcal-ag-lbl">Hearings &amp; deadlines</span>
                        <strong><?= (int)$legalAgenda ?></strong>
                        <small><?= (int)$agendaTot['hearing']['n'] ?> hearing<?= $agendaTot['hearing']['n'] === 1 ? '' : 's' ?></small>
                    </button>
                    <?php $todayTot = lcal_totals($events, $today, $today, $layers)['due']; ?>
                    <button type="button" class="lcal-ag-tot" data-day="<?= h($today) ?>" style="--c:#0284c7">
                        <span class="lcal-ag-ico"><i class="bi bi-sun"></i></span>
                        <span class="lcal-ag-lbl">Due today</span>
                        <strong>AED <?= h(lw_aed($todayTot['amt'])) ?></strong>
                        <small><?= (int)$todayTot['n'] ?> cheque<?= $todayTot['n'] === 1 ? '' : 's' ?> · part of AED due</small>
                    </button>
                </div>

                <div class="lcal-ag-list">
                    <?php
                    $any = false;
                    for ($ts = strtotime($agendaFrom); $ts <= strtotime($agendaTo); $ts = strtotime('+1 day', $ts)):
                        $d = date('Y-m-d', $ts);
                        $items = $events[$d] ?? [];
                        if (!$items) continue;
                        $any = true;
                        // Same grouping as the grid chips: legal records one by one, money per layer.
                        $legal = array_values(array_filter($items, fn($x) => in_array($x['layer'], ['hearing', 'deadline', 'notice'], true)));
                        $money = [];
                        foreach ($items as $x) {
                            if (in_array($x['layer'], ['hearing', 'deadline', 'notice'], true)) continue;
                            $money[$x['layer']] ??= ['n' => 0, 'amt' => 0.0, 'names' => []];
                            $money[$x['layer']]['n']++;
                            $money[$x['layer']]['amt'] += (float)$x['amt'];
                            $money[$x['layer']]['names'][] = $x['title'];
                        }
                        $diff = (int)round(($ts - $todayTs) / 86400);
                        $rel = $diff === 0 ? 'Today' : ($diff === 1 ? 'Tomorrow' : 'In ' . $diff . ' days');
                    ?>
                        <div class="lcal-ag-day <?= $diff === 0 ? 'is-today' : '' ?>">
                            <button type="button" class="lcal-ag-head" data-day="<?= h($d) ?>">
                                <span class="lcal-ag-date"><b><?= date('j', $ts) ?></b><small><?= date('M', $ts) ?></small></span>
                                <span class="flex-grow-1 text-start"><strong><?= h(date('l', $ts)) ?></strong><small><?= h($rel) ?> · <?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></small></span>
                                <i class="bi bi-chevron-right"></i>
                            </button>
                            <?php foreach ($legal as $x): [$ll, $lc, $li] = $layers[$x['layer']]; ?>
                                <div class="lcal-ag-item" style="--c:<?= h($lc) ?>">
                                    <i class="bi <?= h($li) ?>"></i>
                                    <span class="min-w-0"><b class="text-truncate d-block"><?= h($x['title']) ?></b><small class="text-truncate d-block"><?= h($x['sub'] ?: $ll) ?></small></span>
                                </div>
                            <?php endforeach; ?>
                            <?php foreach (['bounce', 'ending', 'due'] as $k): if (empty($money[$k])) continue; $m = $money[$k]; [$ll, $lc, $li] = $layers[$k];
                                $nm = array_values(array_unique($m['names']));
                                $who = implode(', ', array_slice($nm, 0, 2)) . (count($nm) > 2 ? ' +' . (count($nm) - 2) . ' more' : '');
                                $what = $k === 'due' ? $m['n'] . ' cheque' . ($m['n'] === 1 ? '' : 's') . ' due'
                                      : ($k === 'bounce' ? $m['n'] . ' bounced' : $m['n'] . ' lease' . ($m['n'] === 1 ? '' : 's') . ' end, still owe'); ?>
                                <div class="lcal-ag-item" style="--c:<?= h($lc) ?>">
                                    <i class="bi <?= h($li) ?>"></i>
                                    <span class="min-w-0 flex-grow-1"><b class="d-block"><?= h($what) ?></b><small class="text-truncate d-block"><?= h($who) ?></small></span>
                                    <span class="lcal-ag-amt"><?= h(lw_aed($m['amt'])) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endfor; ?>
                    <?php if (!$any): ?>
                        <div class="lw-empty-state"><i class="bi bi-calendar-check"></i>Nothing due in the next 14 days.</div>
                    <?php endif; ?>
                </div>
                <?php if ($agendaTot['hearing']['n'] === 0): ?>
                    <a href="legal_hearing_add.php" class="lcal-ag-cta"><i class="bi bi-plus-circle"></i> No hearings booked in the next 14 days — schedule one</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Day / list pop-up -->
    <div class="modal fade" id="lcalModal" tabindex="-1" aria-labelledby="lcalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content lw-modal lcal-modal">
                <div class="lw-mhead">
                    <div class="d-flex align-items-center gap-3 min-w-0">
                        <span class="lw-mhead-ico" id="lcalIco"><i class="bi bi-calendar-event"></i></span>
                        <div class="min-w-0">
                            <h2 id="lcalTitle">Day</h2>
                            <div class="lw-mhead-stats" id="lcalStats"></div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <a href="legal_hearing_add.php" class="lw-glass-btn text-decoration-none"><i class="bi bi-plus-lg"></i> Add hearing</a>
                        <button type="button" class="lw-glass-btn lw-glass-x" data-bs-dismiss="modal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>
                <div class="modal-body lcal-mbody" id="lcalBody"></div>
            </div>
        </div>
    </div>
</div>

<?php
$jsData = [
    'events' => $payload,
    'layers' => array_map(fn($l) => ['label' => $l[0], 'color' => $l[1], 'icon' => $l[2]], $layers),
    'month' => [$monthStart, $monthEnd, date('F Y', $firstDay)],
    'agenda' => [$agendaFrom, $agendaTo],
    'today' => $today,
];
$pageScripts = '<script>window.LCAL = ' . json_encode($jsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>' . <<<'JS'
<script>
(function () {
    const D = window.LCAL;
    const L = D.layers;
    const CAP = 3;
    const legal = ['hearing', 'deadline', 'notice'];
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const aed = v => 'AED ' + Math.round(v).toLocaleString('en-US');
    const fmtDay = d => new Date(d + 'T00:00:00').toLocaleDateString('en-GB', {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'});
    const fmtShort = d => new Date(d + 'T00:00:00').toLocaleDateString('en-GB', {weekday: 'short', day: 'numeric', month: 'short'});

    // ---------- Layer toggles ----------
    const KEY = 'lcalLayersOff';
    let off = [];
    try { off = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { off = []; }
    const legend = document.querySelectorAll('#lcalLegend .lcal-lg');

    function layout() {
        legend.forEach(b => {
            const on = !off.includes(b.dataset.layer);
            b.classList.toggle('is-on', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        document.querySelectorAll('.lcal-day.has-ev').forEach(cell => {
            const chips = Array.from(cell.querySelectorAll('.lcal-chip'));
            let shown = 0, hidden = 0;
            chips.forEach(ch => {
                const vis = !off.includes(ch.dataset.layer);
                if (vis && shown < CAP) { ch.classList.remove('d-none'); shown++; }
                else { ch.classList.add('d-none'); if (vis) hidden++; }
            });
            const more = cell.querySelector('.lcal-more');
            if (more) {
                more.textContent = '+' + hidden + ' more';
                more.classList.toggle('d-none', hidden === 0);
            }
            cell.classList.toggle('is-dim', shown === 0);
        });
    }
    legend.forEach(b => b.addEventListener('click', () => {
        const k = b.dataset.layer;
        off = off.includes(k) ? off.filter(x => x !== k) : off.concat(k);
        try { localStorage.setItem(KEY, JSON.stringify(off)); } catch (e) {}
        layout();
    }));
    layout();

    // ---------- Pop-up ----------
    const modalEl = document.getElementById('lcalModal');
    const body = document.getElementById('lcalBody');

    function itemHtml(it, showDate) {
        const ly = L[it.layer];
        const money = it.amt > 0 ? '<div class="lcal-it-amt' + (it.layer === 'bounce' ? ' red' : it.layer === 'ending' ? ' violet' : ' amber') + '">' + aed(it.amt)
            + (it.layer === 'due' && it.face && it.face - it.amt > 0.5 ? '<small>of ' + aed(it.face) + '</small>' : '')
            + (it.layer === 'bounce' ? '<small>cheque amount</small>' : '') + (it.layer === 'ending' ? '<small>owed now</small>' : '') + '</div>' : '';
        const links = (it.links || []).map(l => '<a class="lcal-it-btn' + (l[0] === 'New case' ? ' is-grad' : '') + '" href="' + esc(l[1]) + '"><i class="bi ' + esc(l[2]) + '"></i> ' + esc(l[0]) + '</a>').join('');
        const phone = it.phone ? '<a class="lcal-it-btn" href="tel:' + esc(it.phone) + '"><i class="bi bi-telephone"></i> ' + esc(it.phone) + '</a>' : '';
        const status = it.status ? '<span class="lcal-it-status' + (it.late ? ' is-late' : '') + (it.status === 'cancelled' ? ' is-x' : '') + '">' + esc(it.status) + '</span>' : '';
        return '<div class="lcal-it" style="--c:' + ly.color + '">'
            + '<span class="lcal-it-ico"><i class="bi ' + ly.icon + '"></i></span>'
            + '<div class="lcal-it-main min-w-0">'
            + '<div class="lcal-it-title">' + (showDate ? '<span class="lcal-it-date">' + esc(fmtShort(it._d)) + '</span>' : '') + esc(it.title) + '</div>'
            + '<div class="lcal-it-sub">' + esc(it.sub) + '</div>'
            + (status ? '<div class="mt-1">' + status + '</div>' : '')
            + '<div class="lcal-it-links">' + links + phone + '</div>'
            + '</div>' + money + '</div>';
    }
    function summary(items) {
        const t = {};
        items.forEach(it => { t[it.layer] = t[it.layer] || [0, 0, 0]; t[it.layer][0]++; t[it.layer][1] += +it.amt || 0; t[it.layer][2] += +it.owed || 0; });
        return Object.keys(L).filter(k => t[k]).map(k => {
            const [n, a, o] = t[k];
            if (k === 'due') return n + ' cheque' + (n === 1 ? '' : 's') + ' due ' + aed(a);
            if (k === 'bounce') return n + ' bounced ' + aed(a) + ' (' + aed(o) + ' still owed)';
            if (k === 'ending') return n + ' lease' + (n === 1 ? '' : 's') + ' ending, owing ' + aed(a);
            return n + ' ' + L[k].label.toLowerCase().replace(/s$/, n === 1 ? '' : 's');
        }).join(' · ');
    }
    function groupHtml(head, items, showDate) {
        return '<div class="lcal-grp">' + head + items.map(it => itemHtml(it, showDate)).join('') + '</div>';
    }
    function open(title, icon, tone, items, groups) {
        document.getElementById('lcalTitle').textContent = title;
        const ic = document.getElementById('lcalIco');
        ic.style.color = tone;
        ic.innerHTML = '<i class="bi ' + icon + '"></i>';
        document.getElementById('lcalStats').textContent = items.length ? summary(items) : 'Nothing here';
        body.innerHTML = items.length ? groups : '<div class="lw-empty-state"><i class="bi bi-calendar-check"></i>Nothing on this list.</div>';
        if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
    // One day, grouped by layer.
    function openDay(d) {
        const items = (D.events[d] || []).map(it => Object.assign({_d: d}, it));
        let html = '';
        Object.keys(L).forEach(k => {
            const xs = items.filter(it => it.layer === k);
            if (!xs.length) return;
            const amt = xs.reduce((s, it) => s + (+it.amt || 0), 0);
            html += groupHtml('<div class="lcal-grp-head" style="--c:' + L[k].color + '"><i class="bi ' + L[k].icon + '"></i> ' + esc(L[k].label)
                + '<span class="lcal-grp-n">' + xs.length + '</span>' + (amt > 0 ? '<span class="ms-auto">' + aed(amt) + '</span>' : '') + '</div>', xs, false);
        });
        open(fmtDay(d), 'bi-calendar-event', '#7c3aed', items, html);
    }
    // One layer over a date range, grouped by date.
    function openRange(keys, a, b, title, icon, tone) {
        const items = [];
        Object.keys(D.events).sort().forEach(d => {
            if (d < a || d > b) return;
            D.events[d].forEach(it => { if (keys.includes(it.layer)) items.push(Object.assign({_d: d}, it)); });
        });
        let html = '', cur = null, bucket = [];
        const flush = () => {
            if (!bucket.length) return;
            const amt = bucket.reduce((s, it) => s + (+it.amt || 0), 0);
            html += groupHtml('<button type="button" class="lcal-grp-head is-date" data-open-day="' + cur + '"><i class="bi bi-calendar3"></i> ' + esc(fmtDay(cur))
                + '<span class="lcal-grp-n">' + bucket.length + '</span>' + (amt > 0 ? '<span class="ms-auto">' + aed(amt) + '</span>' : '') + '</button>', bucket, false);
        };
        items.forEach(it => { if (it._d !== cur) { flush(); cur = it._d; bucket = []; } bucket.push(it); });
        flush();
        open(title, icon, tone, items, html);
    }
    const groupsFor = k => k === 'legal' ? legal : [k];
    const titleFor = {legal: ['Hearings & deadlines', 'bi-bank2', '#4f46e5'], due: ['Unpaid cheques', 'bi-cash-coin', '#d18f00'],
                      bounce: ['Bounced cheques', 'bi-x-octagon-fill', '#e34948'], ending: ['Leases ending, still owe', 'bi-door-open-fill', '#7c3aed']};

    document.querySelectorAll('.lcal-day[data-day]').forEach(c => {
        c.addEventListener('click', e => { if (!e.target.closest('a')) openDay(c.dataset.day); });
        c.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDay(c.dataset.day); } });
    });
    document.querySelectorAll('.lcal-ag-head[data-day], .lcal-ag-tot[data-day]').forEach(b => b.addEventListener('click', () => openDay(b.dataset.day)));
    document.querySelectorAll('[data-month-layer]').forEach(b => b.addEventListener('click', () => {
        const k = b.dataset.monthLayer, t = titleFor[k];
        openRange(groupsFor(k), D.month[0], D.month[1], t[0] + ' · ' + D.month[2], t[1], t[2]);
    }));
    document.querySelectorAll('[data-agenda-layer]').forEach(b => b.addEventListener('click', () => {
        const k = b.dataset.agendaLayer, t = titleFor[k];
        openRange(groupsFor(k), D.agenda[0], D.agenda[1], t[0] + ' · next 14 days', t[1], t[2]);
    }));
    body.addEventListener('click', e => {
        const b = e.target.closest('[data-open-day]');
        if (b) openDay(b.dataset.openDay);
    });
})();
</script>
JS;
require_once __DIR__ . '/includes/legal_layout_footer.php';
?>
