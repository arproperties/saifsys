<?php
/**
 * Legal Department — Dashboard.
 *
 * Top: Lease Watch — every lease with its cheque position (bounced, overdue,
 * held, with legal, due soon), most urgent first. Data comes from the same
 * tables as Real Estate → Leases; see includes/legal_lease_watch.php.
 * Bottom: legal cases and notices.
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

// ---- Lease Watch ----
$watchRows = [];
$watchError = null;
try {
    $watchRows = legal_lease_watch_rows($conn, (int)$currentCompanyId);
} catch (Throwable $e) {
    $watchError = $e->getMessage();
}
$tot = legal_lease_watch_totals($watchRows);
$flagMeta = legal_watch_flag_meta();
$buildings = [];
foreach ($watchRows as $r) {
    $buildings[$r['building_id']] = $r['building_name'];
}
asort($buildings);

$flagCounts = array_fill_keys(array_keys($flagMeta), 0);
foreach ($watchRows as $r) {
    foreach ($r['flags'] as $f) $flagCounts[$f]++;
}

// ---- Charts, alerts, highlights ----
$ins = ['open' => [], 'monthly' => [], 'upcoming' => []];
try {
    $ins = legal_watch_cheque_insights($conn, (int)$currentCompanyId);
} catch (Throwable $e) {
    $watchError = $watchError ?: $e->getMessage();
}
$ageing = legal_watch_ageing($ins['open']);

$byBuilding = [];
foreach ($ins['open'] as $c) {
    $bid = (int)$c['building_id'];
    $byBuilding[$bid] ??= ['name' => $c['building_name'], 'bounced' => 0.0, 'overdue' => 0.0];
    $byBuilding[$bid][$c['kind']] += $c['owed'];
}
uasort($byBuilding, fn($a, $b) => ($b['bounced'] + $b['overdue']) <=> ($a['bounced'] + $a['overdue']));
$byBuilding = array_slice($byBuilding, 0, 8, true);

$topDebtors = array_values(array_filter($watchRows, fn($r) => $r['outstanding'] > 0.005));
usort($topDebtors, fn($a, $b) => $b['outstanding'] <=> $a['outstanding']);
$topDebtors = array_slice($topDebtors, 0, 6);
$topMax = $topDebtors ? (float)$topDebtors[0]['outstanding'] : 1;

// Alerts — each one filters the table when clicked.
$weekAgo = date('Y-m-d', strtotime('-7 days'));
$newBounces = array_filter($ins['open'], fn($c) => $c['kind'] === 'bounced' && $c['bounced_date'] && $c['bounced_date'] >= $weekAgo);
$oldOverdue = array_filter($ins['open'], fn($c) => $c['kind'] === 'overdue' && $c['age_days'] > 90);
$perLease = function (array $cheques): array {
    $out = [];
    foreach ($cheques as $c) {
        $out[(int)$c['lease_id']] ??= [0, 0.0];
        $out[(int)$c['lease_id']][0]++;
        $out[(int)$c['lease_id']][1] += (float)$c['owed'];
    }
    return $out;
};
$newBounceByLease = $perLease($newBounces);
$overdue90ByLease = $perLease($oldOverdue);
$endedRows  = array_filter($watchRows, fn($r) => in_array('ended_balance', $r['flags'], true));
$notRenewedRows = array_filter($watchRows, fn($r) => in_array('not_renewed', $r['flags'], true));
$thisWeek   = (float)($ins['upcoming'][date('Y-m-d', strtotime('monday this week'))] ?? 0);
$sumOwed    = fn(array $xs) => array_sum(array_map(fn($x) => (float)($x['owed'] ?? $x['outstanding'] ?? 0), $xs));
$names      = function (array $xs): string {
    $n = array_values(array_unique(array_map(fn($x) => $x['tenant_label'], $xs)));
    return implode(', ', array_slice($n, 0, 3)) . (count($n) > 3 ? ' +' . (count($n) - 3) . ' more' : '');
};

$alerts = [];
$firstName = fn(array $xs) => ($n = array_values($xs)) ? explode(' ', trim($n[0]['tenant_label']))[0] : '';
if ($newBounces) {
    $alerts[] = ['rose', 'bi-lightning-charge-fill', count($newBounces), 'New bounce' . (count($newBounces) > 1 ? 's' : ''),
        'AED ' . lw_aed($sumOwed($newBounces)), 'new_bounce',
        count($newBounces) . ' cheque(s) bounced in the last 7 days: ' . $names($newBounces)];
}
if ($endedRows) {
    $alerts[] = ['violet', 'bi-door-open-fill', count($endedRows), 'Left, still owe',
        'AED ' . lw_aed($sumOwed($endedRows)), 'ended_balance',
        'Lease expired or terminated with money still owed — recover before deposits are released'];
}
if ($notRenewedRows) {
    $oldestExp = max(array_map(fn($r) => (int)$r['days_expired'], $notRenewedRows));
    $alerts[] = ['navy', 'bi-calendar-x-fill', count($notRenewedRows), 'Expired, not renewed',
        'oldest ' . $oldestExp . ' days', 'not_renewed',
        'Lease end date has passed, no renewal, no move-out and the unit was not let again — tenant may still be in the unit: '
        . $names($notRenewedRows)];
}
if ($oldOverdue) {
    $alerts[] = ['amber', 'bi-alarm-fill', count($oldOverdue), 'Overdue 90+ days',
        'AED ' . lw_aed($sumOwed($oldOverdue)), 'overdue90',
        count($oldOverdue) . ' cheques more than 90 days overdue: ' . $names($oldOverdue)];
}
if ($tot['held_count'] > 0) {
    $alerts[] = ['navy', 'bi-pause-circle-fill', (int)$tot['held_count'], 'Held by finance',
        'Not deposited yet', 'held', 'Cheques on hold — confirm whether to present or escalate'];
}
if ($tot['due_soon_count'] > 0) {
    $alerts[] = ['sky', 'bi-calendar2-week-fill', 'AED ' . lw_aed((float)$tot['due_soon_amt']), 'Due in 14 days',
        (int)$tot['due_soon_count'] . ' cheques · chase early', 'due_soon',
        'Unpaid cheques dated in the next 14 days' . ($thisWeek > 0 ? ' (AED ' . number_format($thisWeek) . ' of it this week)' : '')];
}

// Real monthly series for the strip sparklines (last 6 months, by cheque date).
$m6 = array_slice($ins['monthly'], -6, null, true);
$alertSpark = [
    'bounced' => ['Bounced cheques per month', array_map(fn($k, $v) => [date('M', strtotime($k . '-01')), (int)$v['bounced']], array_keys($m6), $m6)],
    'overdue' => ['Unpaid cheques per month', array_map(fn($k, $v) => [date('M', strtotime($k . '-01')), (int)$v['unpaid']], array_keys($m6), $m6)],
];

$chartData = [
    'ageing' => [
        'labels'  => array_map(fn($k) => $k . ' days', array_keys($ageing)),
        'bounced' => array_map(fn($v) => round($v['bounced']), array_values($ageing)),
        'overdue' => array_map(fn($v) => round($v['overdue']), array_values($ageing)),
    ],
    'building' => [
        'ids'     => array_keys($byBuilding),
        'labels'  => array_map(fn($v) => $v['name'], array_values($byBuilding)),
        'bounced' => array_map(fn($v) => round($v['bounced']), array_values($byBuilding)),
        'overdue' => array_map(fn($v) => round($v['overdue']), array_values($byBuilding)),
    ],
    'monthly' => [
        'labels' => array_map(fn($k) => date('M y', strtotime($k . '-01')), array_keys($ins['monthly'])),
        'paid'   => array_column(array_values($ins['monthly']), 'paid'),
        'bounced'=> array_column(array_values($ins['monthly']), 'bounced'),
        'unpaid' => array_column(array_values($ins['monthly']), 'unpaid'),
        'paid_amt'    => array_map('round', array_column(array_values($ins['monthly']), 'paid_amt')),
        'bounced_amt' => array_map('round', array_column(array_values($ins['monthly']), 'bounced_amt')),
        'unpaid_amt'  => array_map('round', array_column(array_values($ins['monthly']), 'unpaid_amt')),
    ],
];
$m12 = array_values($ins['monthly']);
$chqDue = array_sum(array_column($m12, 'paid')) + array_sum(array_column($m12, 'bounced')) + array_sum(array_column($m12, 'unpaid'));
$collRate = $chqDue > 0 ? (int)floor(array_sum(array_column($m12, 'paid')) / $chqDue * 100) : 0;

// ---- Extra figures for the card layout ----
$outTotal = (float)$tot['bounced_amt'] + (float)$tot['overdue_amt'];
$endedAmt = array_sum(array_map(fn($r) => (float)$r['outstanding'], $endedRows));
$share = fn(float $v) => $outTotal > 0 ? (int)round($v / $outTotal * 100) : 0;
$bMax = 0.0;
foreach ($byBuilding as $b) $bMax = max($bMax, $b['bounced'] + $b['overdue']);
$okLeasesPct   = $tot['all'] > 0 ? (int)floor(($tot['all'] - $tot['attention']) / $tot['all'] * 100) : 0;
$withCasePct   = $tot['attention'] > 0 ? (int)round($tot['legal_leases'] / $tot['attention'] * 100) : 0;
$ageTotals     = array_map(fn($v) => $v['bounced'] + $v['overdue'], $ageing);
$ageSum        = array_sum($ageTotals);
$over180       = (float)($ageTotals['180+'] ?? 0);
$debtTabs = [];
foreach (['all' => 'outstanding', 'bounced' => 'bounced_amt', 'overdue' => 'overdue_amt'] as $tab => $col) {
    $list = array_values(array_filter($watchRows, fn($r) => (float)$r[$col] > 0.005));
    usort($list, fn($a, $b) => $b[$col] <=> $a[$col]);
    $debtTabs[$tab] = ['col' => $col, 'rows' => array_slice($list, 0, 5), 'count' => count($list)];
}


$pageTitle = 'Legal Dashboard';
$pageHead = '
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
<div class="lw-page">

    <div class="lw-head d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h1 class="mb-1">Legal Dashboard</h1>
            <div class="lw-sub">Lease collections at a glance · <?= h(date('l, j F Y')) ?></div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="legal_cheque_notifications.php" class="btn lw-btn-soft"><i class="bi bi-bell"></i> Cheque Notifications</a>
            <a href="legal_case_list.php" class="btn lw-btn-soft"><i class="bi bi-briefcase"></i> Cases</a>
            <a href="legal_case_add.php" class="btn lw-btn-grad"><i class="bi bi-plus-lg"></i> New Case</a>
        </div>
    </div>

    <?php if ($watchError): ?>
        <div class="alert alert-danger">Lease data could not be loaded: <?= h($watchError) ?></div>
    <?php endif; ?>

    <!-- Alerts: one strip -->
    <?php if ($alerts): ?>
    <div class="lw-strip mb-4" id="lwAlerts">
        <?php foreach ($alerts as [$tone, $icon, $big, $label, $sub, $filter, $tip]): $sp = $alertSpark[$filter] ?? null; ?>
            <div class="lw-alert lw-seg lw-st-<?= h($tone) ?>" data-key="<?= h(md5($label . date('Y-m-d'))) ?>" title="<?= h($tip) ?>">
                <button type="button" class="lw-alert-btn" data-filter="<?= h($filter) ?>" aria-label="<?= h($label) ?>: open list"></button>
                <span class="lw-seg-ico"><i class="bi <?= h($icon) ?>"></i></span>
                <div class="lw-seg-body">
                    <div class="lw-seg-label"><?= h($label) ?></div>
                    <div class="lw-seg-big"><?= h((string)$big) ?></div>
                    <div class="lw-seg-sub"><?= h($sub) ?></div>
                </div>
                <?php if ($sp): ?><span class="lw-seg-spark" title="<?= h($sp[0]) ?>"><?= lw_spark($sp[1]) ?></span><?php endif; ?>
                <button type="button" class="lw-alert-x" title="Hide for today" aria-label="Hide"><i class="bi bi-x"></i></button>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <!-- Column 1: outstanding -->
        <div class="col-xl-4 col-lg-6">
            <div class="lw-panel h-100">
                <div class="lw-panel-head">
                    <h2>Outstanding<br>Rent</h2>
                    <a href="#" class="lw-link" data-go data-filter="attention">View leases <i class="bi bi-arrow-right"></i></a>
                </div>
                <a class="lw-hero-card" data-go data-filter="attention" href="#">
                    <div class="lw-hero-label">Total owed to us</div>
                    <div class="lw-hero-sub">Bounced + overdue cheques</div>
                    <div class="lw-hero-amt">AED <?= number_format($outTotal, 0) ?></div>
                    <div class="lw-hero-foot"><?= (int)$tot['attention'] ?> of <?= (int)$tot['all'] ?> leases need attention</div>
                </a>

                <?php
                $metrics = [
                    ['bounced', 'Bounced cheques', 'Not replaced or paid', 'bi-x-octagon', 'pink', (float)$tot['bounced_amt'], (int)$tot['bounced_count'] . ' cheques', (int)$tot['bounced_leases'] . ' leases'],
                    ['overdue', 'Rent overdue', 'Cheque date has passed', 'bi-alarm', 'indigo', (float)$tot['overdue_amt'], (int)$tot['overdue_count'] . ' cheques', (int)$tot['overdue_leases'] . ' leases'],
                    ['ended_balance', 'Ended with balance', 'Lease over, still owing', 'bi-hourglass-bottom', 'cyan', (float)$endedAmt, (int)$tot['ended_balance'] . ' leases', 'Recover first'],
                ];
                foreach ($metrics as [$f, $title, $sub, $icon, $tone, $amt, $pillA, $pillB]):
                    $p = $share($amt);
                ?>
                <div class="lw-metric lw-tone-<?= $tone ?>">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="lw-sq"><i class="bi <?= $icon ?>"></i></span>
                        <div>
                            <div class="lw-metric-title"><?= h($title) ?></div>
                            <div class="lw-muted"><?= h($sub) ?></div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="lw-donut" style="--p:<?= $p ?>"><span></span></div>
                        <div>
                            <div class="lw-metric-num"><?= h(lw_aed($amt)) ?></div>
                            <div class="lw-metric-pct"><i class="bi bi-pie-chart"></i> <?= $p ?>% of total owed</div>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="lw-pill lw-pill-fill" data-go data-filter="<?= $f ?>">
                            <small><i class="bi bi-receipt"></i> <?= h($pillA) ?></small><strong>AED <?= h(lw_aed($amt)) ?></strong>
                        </button>
                        <button type="button" class="lw-pill" data-go data-filter="<?= $f ?>">
                            <small><i class="bi bi-buildings"></i> Leases</small><strong><?= h($pillB) ?></strong>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Column 2: buildings + ageing -->
        <div class="col-xl-4 col-lg-6 d-flex flex-column gap-4">
            <div class="lw-panel">
                <div class="lw-panel-head">
                    <h2>Where is the<br>money stuck?</h2>
                    <span class="lw-muted text-end">Click a bar to<br>filter the table</span>
                </div>
                <div class="lw-date-row mb-3">
                    <span class="lw-date-pill"><i class="bi bi-buildings"></i> Top <?= count($byBuilding) ?> buildings</span>
                    <span class="lw-muted">by amount owed</span>
                </div>
                <?php if (!$byBuilding): ?><div class="lw-muted">Nothing owed.</div><?php endif; ?>
                <?php foreach ($byBuilding as $bid => $b):
                    $amt = $b['bounced'] + $b['overdue'];
                    $wb = $bMax > 0 ? $b['bounced'] / $bMax * 100 : 0;
                    $wo = $bMax > 0 ? $b['overdue'] / $bMax * 100 : 0; ?>
                    <button type="button" class="lw-hbar" data-building-go="<?= (int)$bid ?>" title="<?= h($b['name']) ?> — bounced AED <?= number_format($b['bounced']) ?>, overdue AED <?= number_format($b['overdue']) ?>. Click to filter the table.">
                        <span class="lw-hbar-top">
                            <span class="lw-hbar-name"><?= h($b['name']) ?></span>
                            <span class="lw-hbar-amt">AED <?= h(lw_aed($amt)) ?> <span class="lw-hbar-pct"><?= $share($amt) ?>%</span></span>
                        </span>
                        <span class="lw-hbar-track">
                            <?php if ($wb > 0): ?><span class="lw-hbar-seg b" style="width:<?= max(2, round($wb, 1)) ?>%"></span><?php endif; ?>
                            <?php if ($wo > 0): ?><span class="lw-hbar-seg o" style="width:<?= max(2, round($wo, 1)) ?>%"></span><?php endif; ?>
                        </span>
                    </button>
                <?php endforeach; ?>
                <div class="lw-legend mt-2"><span><i style="background:#e34948"></i>Bounced</span><span><i style="background:#eda100"></i>Overdue</span></div>

                <div class="mt-3">
                    <?php
                    $progress = [
                        ['bi-check2-circle', 'green', 'Cheques cleared', 'Last 12 months, ' . number_format($chqDue) . ' cheques', $collRate],
                        ['bi-shield-check', 'violet', 'Leases with no issues', ($tot['all'] - $tot['attention']) . ' of ' . $tot['all'] . ' leases', $okLeasesPct],
                        ['bi-bank2', 'pink', 'Flagged leases with legal', $tot['legal_leases'] . ' of ' . $tot['attention'] . ' flagged', $withCasePct],
                    ];
                    foreach ($progress as [$icon, $tone, $label, $sub, $pct]): ?>
                        <div class="lw-prog lw-tone-<?= $tone ?>">
                            <span class="lw-sq lw-sq-sm"><i class="bi <?= $icon ?>"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold"><?= h($label) ?></div>
                                <div class="lw-muted text-truncate"><?= h($sub) ?></div>
                            </div>
                            <div class="lw-prog-right">
                                <div><strong><?= (int)$pct ?>%</strong></div>
                                <div class="lw-prog-track"><span style="width:<?= (int)$pct ?>%"></span></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="lw-panel flex-grow-1">
                <div class="lw-panel-head">
                    <h2>How old is<br>the debt?</h2>
                    <span class="lw-muted text-end">Days since cheque<br>or bounce date</span>
                </div>
                <?php
                $ageCols = ['#b19cfb', '#9177f5', '#7c3aed', '#5b21b6', '#3b1478'];
                $agePct  = fn(float $v) => $ageSum > 0 ? $v / $ageSum * 100 : 0;
                $ageMax  = $ageTotals ? max($ageTotals) : 0;
                ?>
                <div class="lw-age-callout">
                    <span class="lw-age-ico"><i class="bi bi-hourglass-split"></i></span>
                    <div class="min-w-0">
                        <div class="lw-age-amt">AED <?= h(lw_aed($over180)) ?></div>
                        <div class="lw-age-note"><strong><?= (int)round($agePct($over180)) ?>%</strong> of the debt is older than 180 days — the hardest to recover</div>
                    </div>
                </div>

                <div class="lw-age-split" role="img" aria-label="Debt split by age">
                    <?php $i = 0; foreach ($ageTotals as $label => $v): if ($v > 0): ?>
                        <span style="width:<?= round($agePct($v), 2) ?>%;background:<?= $ageCols[$i] ?>" title="<?= h($label) ?> days · AED <?= number_format($v) ?> · <?= (int)round($agePct($v)) ?>%"></span>
                    <?php endif; $i++; endforeach; ?>
                </div>

                <div class="lw-age-list">
                    <?php $i = 0; foreach ($ageing as $label => $a): $v = $a['bounced'] + $a['overdue']; ?>
                        <div class="lw-age-row" title="Bounced AED <?= number_format($a['bounced']) ?> · overdue AED <?= number_format($a['overdue']) ?>">
                            <span class="lw-age-dot" style="background:<?= $ageCols[$i] ?>"></span>
                            <span class="lw-age-label"><?= h($label) ?> <small>days</small></span>
                            <span class="lw-age-bar"><span style="width:<?= $ageMax > 0 ? round($v / $ageMax * 100, 1) : 0 ?>%;background:<?= $ageCols[$i] ?>"></span></span>
                            <span class="lw-age-val">AED <?= h(lw_aed($v)) ?></span>
                            <span class="lw-age-pct"><?= (int)round($agePct($v)) ?>%</span>
                            <span class="lw-age-cnt"><?= (int)$a['count'] ?> chq</span>
                        </div>
                    <?php $i++; endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Column 3: debtors + upcoming -->
        <div class="col-xl-4 col-lg-12 d-flex flex-column gap-4">
            <div class="lw-panel">
                <div class="lw-panel-head">
                    <h2>Who owes<br>the most</h2>
                    <span class="lw-muted text-end">Click a name to<br>find the lease</span>
                </div>
                <div class="lw-tabs" role="tablist">
                    <?php foreach (['all' => 'All', 'bounced' => 'Bounced', 'overdue' => 'Overdue'] as $tk => $tl): ?>
                        <button type="button" class="lw-tab <?= $tk === 'all' ? 'is-active' : '' ?>" data-tab="<?= $tk ?>"><?= $tl ?> <span>(<?= (int)$debtTabs[$tk]['count'] ?>)</span></button>
                    <?php endforeach; ?>
                </div>
                <div class="lw-search-box mb-2">
                    <i class="bi bi-search"></i>
                    <input type="search" id="lwDebtSearch" placeholder="Type a tenant or lease, press Enter">
                </div>
                <?php foreach ($debtTabs as $tk => $tab): ?>
                    <div class="lw-debt-list <?= $tk === 'all' ? '' : 'd-none' ?>" data-list="<?= $tk ?>">
                        <?php if (!$tab['rows']): ?><div class="lw-muted p-3">Nobody here. 🎉</div><?php endif; ?>
                        <?php foreach ($tab['rows'] as $i => $d): ?>
                            <button type="button" class="lw-person <?= $i === 0 ? 'is-top' : '' ?>" data-search="<?= h($d['lease_number']) ?>">
                                <span class="lw-avatar lw-av-<?= lw_avatar_tone($d['tenant_label']) ?>"><?= h(lw_initials($d['tenant_label'])) ?><i class="lw-dot <?= $d['bounced_count'] > 0 ? 'red' : 'amber' ?>"></i></span>
                                <span class="flex-grow-1 min-w-0 text-start">
                                    <span class="d-flex justify-content-between gap-2">
                                        <span class="fw-semibold text-truncate"><?= h($d['tenant_label']) ?></span>
                                        <span class="lw-person-amt"><?= h(lw_aed((float)$d[$tab['col']])) ?></span>
                                    </span>
                                    <span class="lw-muted d-block text-truncate"><?= h($d['lease_number']) ?> · <?= h($d['building_name']) ?></span>
                                    <span class="lw-muted d-block">
                                        <?php if ($d['bounced_count'] > 0): ?><?= (int)$d['bounced_count'] ?> bounced<?php endif; ?>
                                        <?php if ($d['bounced_count'] > 0 && $d['overdue_count'] > 0): ?> · <?php endif; ?>
                                        <?php if ($d['overdue_count'] > 0): ?><?= (int)$d['overdue_count'] ?> overdue, <?= (int)$d['days_overdue'] ?> days<?php endif; ?>
                                    </span>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="lw-panel flex-grow-1">
                <div class="lw-panel-head">
                    <h2>Keep an<br>eye on</h2>
                </div>
                <?php
                $rings = [
                    ['due_soon', 'sky', (int)$tot['due_soon_count'], 'Due in 14 days', 'AED ' . number_format($tot['due_soon_amt'], 0) . ' — chase before they bounce'],
                    ['held', 'slate', (int)$tot['held_count'], 'Held by finance', 'Cheques on hold, not deposited'],
                    ['not_renewed', 'indigo', (int)$tot['not_renewed'], 'Expired, not renewed', 'Lease over, no renewal, tenant not moved out'],
                    ['legal', 'violet', (int)$tot['legal_leases'], 'With legal', 'Leases escalated or with a case'],
                ];
                foreach ($rings as [$f, $tone, $n, $label, $sub]): ?>
                    <div class="lw-ring-row lw-tone-<?= $tone ?>">
                        <span class="lw-ring"><span><?= $n ?></span></span>
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-bold"><?= h($label) ?></div>
                            <div class="lw-muted"><?= h($sub) ?></div>
                        </div>
                        <button type="button" class="lw-detail-btn" data-go data-filter="<?= $f ?>"><i class="bi bi-eye"></i> Detail</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Monthly trend -->
    <div class="lw-panel mb-4">
        <div class="lw-panel-head">
            <div>
                <h2>Bounced &amp; unpaid cheques by month</h2>
                <div class="lw-muted">Number of problem cheques, by the month each was due. Hover a month for AED and how many cleared.</div>
            </div>
            <div class="text-end">
                <div class="lw-hero-pct"><?= (int)$collRate ?>%</div>
                <div class="lw-muted">of <?= number_format($chqDue) ?> cheques cleared</div>
            </div>
        </div>
        <div class="lw-chart-box" style="height:300px"><canvas id="chMonthly" aria-label="Bounced and unpaid cheques per month"></canvas></div>
    </div>

    <!-- Lease Watch: opens as a pop-up from any card, alert, bar or name -->
    <div class="modal fade" id="lwModal" tabindex="-1" aria-labelledby="lwModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content lw-page lw-modal">
    <div class="lw-card" id="lwWatch">
        <div class="lw-mhead">
            <div class="d-flex align-items-center gap-3 min-w-0">
                <span class="lw-mhead-ico" id="lwModalIcon"><i class="bi bi-binoculars"></i></span>
                <div class="min-w-0">
                    <h2 id="lwModalTitle">Leases</h2>
                    <div class="lw-mhead-stats" id="lwModalStats"></div>
                </div>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <button type="button" class="lw-glass-btn" id="lwExport"><i class="bi bi-download"></i> Export</button>
                <button type="button" class="lw-glass-btn lw-glass-x" data-bs-dismiss="modal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>
        <div class="lw-card-head">
            <div class="lw-toolbar">
                <div class="lw-chips mb-2" id="lwChips">
                    <button type="button" class="lw-chip is-active" data-filter="attention"><i class="bi bi-exclamation-circle"></i> Needs attention <span class="lw-count"><?= (int)$tot['attention'] ?></span></button>
                    <?php foreach ($flagMeta as $key => [$label, , $icon]): ?>
                        <button type="button" class="lw-chip" data-filter="<?= h($key) ?>"><i class="bi <?= h($icon) ?>"></i> <?= h($label) ?> <span class="lw-count"><?= (int)$flagCounts[$key] ?></span></button>
                    <?php endforeach; ?>
                    <button type="button" class="lw-chip" data-filter="all"><i class="bi bi-list-ul"></i> All leases <span class="lw-count"><?= (int)$tot['all'] ?></span></button>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <div style="width:260px">
                        <select id="lwBuilding" class="form-select form-select-sm">
                            <option value="">All buildings</option>
                            <?php foreach ($buildings as $bid => $bname): ?>
                                <option value="<?= (int)$bid ?>"><?= h($bname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="search" id="lwSearch" class="form-control form-control-sm" style="width:320px;max-width:100%" placeholder="Search lease, tenant, unit, phone">
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table lw-table" id="lwTable">
                <thead><tr>
                    <th>Lease</th><th>Tenant</th><th>Unit</th><th>Flags</th>
                    <th class="text-end">Bounced</th><th class="text-end">Overdue</th>
                    <th>Collected</th><th>Next cheque</th><th class="text-end">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($watchRows as $r):
                    $attention = (bool)array_intersect(['bounced', 'overdue', 'held', 'legal', 'ended_balance', 'not_renewed'], $r['flags']);
                    $sev = $r['bounced_count'] > 0 || in_array('ended_balance', $r['flags'], true) ? 3
                         : ($r['overdue_count'] > 0 || $r['not_renewed'] ? 2 : ($r['flags'] ? 1 : 0));
                    $isBounce = $r['bounced_count'] > 0;
                    $isNr = !$isBounce && $r['not_renewed'];
                    $pct = $r['face_total'] > 0 ? max(0, min(100, round($r['collected'] / $r['face_total'] * 100))) : 0;
                    $claim = round((float)$r['outstanding'], 2);
                    $caseUrl = 'legal_case_add.php?' . http_build_query([
                        'case_type' => $isBounce ? 'cheque_bounce' : ($isNr ? 'eviction' : 'rent_recovery'),
                        'case_source' => $isBounce ? 'bounced_cheque' : ($isNr ? 'renewal' : 'manual'),
                        'lease_id' => $r['id'], 'tenant_id' => $r['tenant_id'], 'building_id' => $r['building_id'], 'unit_id' => $r['unit_id'], 'priority' => $isBounce || $isNr ? 'high' : null,
                        'title' => ($isBounce ? 'Bounced cheques' : ($isNr ? 'Expired lease not renewed' : 'Rent arrears')) . ' – ' . $r['tenant_label'] . ' (' . $r['unit_label'] . ')',
                        'claim_amount' => $claim > 0 ? $claim : null,
                    ]);
                    $search = strtolower(implode(' ', [$r['lease_number'], $r['tenant_label'], $r['unit_label'], $r['phone'], $r['email']]));
                ?>
                    <tr class="lw-sev-<?= $sev ?>"
                        data-flags="<?= h(implode(' ', $r['flags']) . ($attention ? ' attention' : '')
                            . (isset($newBounceByLease[(int)$r['id']]) ? ' new_bounce' : '')
                            . (isset($overdue90ByLease[(int)$r['id']]) ? ' overdue90' : '')) ?>"
                        data-m="<?= h(json_encode([
                            'bounced'    => [(int)$r['bounced_count'], round((float)$r['bounced_amt'], 2)],
                            'overdue'    => [(int)$r['overdue_count'], round((float)$r['overdue_amt'], 2)],
                            'held'       => [(int)$r['held_count'], 0],
                            'due_soon'   => [(int)$r['due_soon_count'], round((float)$r['due_soon_amt'], 2)],
                            'new_bounce' => $newBounceByLease[(int)$r['id']] ?? [0, 0],
                            'overdue90'  => $overdue90ByLease[(int)$r['id']] ?? [0, 0],
                        ])) ?>"
                        data-building="<?= (int)$r['building_id'] ?>"
                        data-owed="<?= round((float)$r['outstanding'], 2) ?>"
                        data-search="<?= h($search) ?>"
                        data-csv="<?= h(json_encode([
                            $r['lease_number'], $r['status'], $r['tenant_label'], $r['phone'], $r['unit_label'],
                            implode('; ', array_map(fn($f) => $flagMeta[$f][0], $r['flags'])),
                            $r['bounced_count'], round((float)$r['bounced_amt'], 2),
                            $r['overdue_count'], round((float)$r['overdue_amt'], 2), $r['days_overdue'],
                            round((float)$r['collected'], 2), round((float)$r['face_total'], 2), $r['next_due'],
                        ])) ?>">
                        <td class="lw-lease">
                            <a href="<?= h($re) ?>lease_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['lease_number'] ?: '#' . $r['id']) ?></a><br>
                            <span class="lw-status <?= h($r['status']) ?>"><?= h(str_replace('_', ' ', $r['status'])) ?></span>
                            <?php if ($r['end_date']): ?><span class="lw-muted ms-1">to <?= h(date('j M y', strtotime($r['end_date']))) ?></span><?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="lw-avatar lw-avatar-sm lw-av-<?= lw_avatar_tone($r['tenant_label']) ?>"><?= h(lw_initials($r['tenant_label'])) ?></span>
                                <div class="min-w-0">
                                    <div class="lw-tname"><?= h($r['tenant_label']) ?></div>
                                    <?php if ($r['phone']): ?><a class="lw-muted text-decoration-none" href="tel:<?= h($r['phone']) ?>"><i class="bi bi-telephone"></i> <?= h($r['phone']) ?></a><?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><?= h($r['building_name']) ?><br><span class="lw-muted">Unit <?= h($r['unit_number']) ?></span></td>
                        <td style="max-width:220px">
                            <?php foreach ($r['flags'] as $f): [$fl, $fc, $fi] = $flagMeta[$f]; ?>
                                <span class="lw-flag lw-flag-<?= h($f) ?>"><i class="bi <?= h($fi) ?>"></i>
                                    <?= h($fl) ?><?php if ($f === 'bounced'): ?> ×<?= (int)$r['bounced_count'] ?><?php elseif ($f === 'overdue'): ?> · <?= (int)$r['days_overdue'] ?>d<?php elseif ($f === 'held'): ?> ×<?= (int)$r['held_count'] ?><?php elseif ($f === 'not_renewed'): ?> · <?= (int)$r['days_expired'] ?>d<?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                            <?php if (!$r['flags']): ?><span class="lw-muted"><i class="bi bi-check-circle text-success"></i> Clear</span><?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($r['bounced_amt'] > 0): ?>
                                <div class="lw-money red"><?= number_format((float)$r['bounced_amt'], 0) ?></div>
                                <span class="lw-muted"><?= (int)$r['bounced_count'] ?> cheque<?= $r['bounced_count'] > 1 ? 's' : '' ?></span>
                            <?php else: ?><span class="lw-muted">—</span><?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($r['overdue_amt'] > 0): ?>
                                <div class="lw-money amber"><?= number_format((float)$r['overdue_amt'], 0) ?></div>
                                <span class="lw-muted"><?= (int)$r['overdue_count'] ?> · since <?= h(date('j M y', strtotime($r['oldest_overdue']))) ?></span>
                            <?php else: ?><span class="lw-muted">—</span><?php endif; ?>
                        </td>
                        <td style="min-width:130px">
                            <?php if ($r['face_total'] > 0): ?>
                                <div class="lw-progress mb-1"><span style="width:<?= $pct ?>%"></span></div>
                                <span class="lw-muted"><?= lw_aed((float)$r['collected']) ?> / <?= lw_aed((float)$r['face_total']) ?> · <?= $pct ?>%</span>
                            <?php else: ?><span class="lw-muted">No cheques</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['next_due']): ?><?= h(date('j M Y', strtotime($r['next_due']))) ?><?php else: ?><span class="lw-muted">—</span><?php endif; ?>
                        </td>
                        <td class="text-end lw-actions text-nowrap">
                            <a class="btn btn-outline-secondary" href="<?= h($re) ?>lease_view.php?id=<?= (int)$r['id'] ?>#installments" title="Open lease and cheques"><i class="bi bi-box-arrow-up-right"></i></a>
                            <?php if ($r['open_cases'] > 0): ?>
                                <a class="btn btn-outline-primary" href="legal_case_list.php?search=<?= h(urlencode((string)$r['lease_number'])) ?>" title="View legal case"><i class="bi bi-briefcase"></i> Case</a>
                            <?php elseif ($attention): ?>
                                <a class="btn btn-dark" href="<?= h($caseUrl) ?>" title="Open a legal case for this lease"><i class="bi bi-plus-lg"></i> Case</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="lw-empty d-none" id="lwEmpty"><i class="bi bi-check2-circle"></i>Nothing here — no leases match this filter.</div>
        </div>
        <div class="px-3 py-2 border-top lw-muted d-flex justify-content-between">
            <span id="lwShown"></span>
            <span>Overdue = cheque date passed and not cleared or fully collected.</span>
        </div>
    </div>
    </div>
    </div>
    </div>

</div>

<?php
$pageScripts = '<script>window.LW_CHARTS = ' . json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . <<<'JS'
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const rows = Array.from(document.querySelectorAll('#lwTable tbody tr'));
    const chips = document.querySelectorAll('#lwChips .lw-chip');
    const kpis = document.querySelectorAll('[data-go]');
    const search = document.getElementById('lwSearch');
    const building = document.getElementById('lwBuilding');
    const empty = document.getElementById('lwEmpty');
    const shown = document.getElementById('lwShown');
    const watch = document.getElementById('lwWatch');
    const hasS2 = !!(window.jQuery && jQuery.fn.select2);
    let filter = 'attention';

    function apply() {
        const q = search.value.trim().toLowerCase();
        const b = building.value;
        let n = 0;
        rows.forEach(tr => {
            const ok = (filter === 'all' || (' ' + tr.dataset.flags + ' ').includes(' ' + filter + ' '))
                && (!b || tr.dataset.building === b)
                && (!q || tr.dataset.search.includes(q));
            tr.classList.toggle('d-none', !ok);
            if (ok) n++;
        });
        empty.classList.toggle('d-none', n > 0);
        // Header figures match the thing that was clicked.
        const word = {bounced: 'bounced', new_bounce: 'bounced this week', overdue: 'overdue', overdue90: 'over 90 days',
                      held: 'held by finance', due_soon: 'due in 14 days'}[filter];
        let chq = 0, amt = 0;
        rows.forEach(tr => {
            if (tr.classList.contains('d-none')) return;
            if (word) { const m = JSON.parse(tr.dataset.m)[filter]; chq += m[0]; amt += m[1]; }
            else amt += parseFloat(tr.dataset.owed || 0);
        });
        const money = 'AED ' + Math.round(amt).toLocaleString('en-US');
        let stat = n + ' lease' + (n === 1 ? '' : 's');
        if (word) stat += ' · ' + chq + ' cheque' + (chq === 1 ? '' : 's') + (amt > 0 ? ' · ' + money : '') + ' ' + word;
        else if (amt > 0) stat += ' · ' + money + ' owed in total (bounced + overdue)';
        const st = document.getElementById('lwModalStats');
        if (st) st.textContent = stat;
        const chip = document.querySelector('#lwChips .lw-chip[data-filter="' + filter + '"]');
        let txt = 'Showing ' + n + ' of ' + rows.length + ' leases';
        if (filter !== 'all' && chip) txt += ' · ' + chip.textContent.replace(/\d+\s*$/, '').trim();
        if (b) txt += ' · ' + building.options[building.selectedIndex].text;
        if (q) txt += ' · "' + search.value.trim() + '"';
        shown.textContent = txt + ' ';
        if (filter !== 'attention' || b || q) {
            const r = document.createElement('a'); r.href = '#'; r.textContent = 'Reset filters';
            r.addEventListener('click', e => { e.preventDefault(); reset(); });
            shown.append(r);
        }
        chips.forEach(c => c.classList.toggle('is-active', c.dataset.filter === filter));
        kpis.forEach(k => k.classList.toggle('is-active', k.dataset.filter === filter));
    }
    function setBuilding(id) {
        building.value = id;
        if (hasS2) jQuery(building).trigger('change.select2');
    }
    function reset() { filter = 'attention'; search.value = ''; setBuilding(''); apply(); }
    const modalEl = document.getElementById('lwModal');
    const titles = {attention: ['Leases needing attention', 'bi-exclamation-circle', 'violet'],
                    bounced: ['Bounced cheques', 'bi-x-octagon', 'pink'], overdue: ['Rent overdue', 'bi-alarm', 'indigo'],
                    held: ['Held by finance', 'bi-pause-circle', 'slate'], legal: ['With legal', 'bi-bank2', 'violet'],
                    ended_balance: ['Ended with balance', 'bi-hourglass-bottom', 'cyan'],
                    not_renewed: ['Expired, not renewed', 'bi-calendar-x', 'indigo'],
                    due_soon: ['Due in the next 14 days', 'bi-calendar-event', 'sky'], all: ['All leases', 'bi-list-ul', 'slate'],
                    new_bounce: ['Bounced in the last 7 days', 'bi-lightning-charge', 'pink'],
                    overdue90: ['Overdue more than 90 days', 'bi-alarm', 'indigo']};
    function setTitle() {
        const t = titles[filter] || titles.all;
        let txt = t[0];
        if (building.value) txt += ' · ' + building.options[building.selectedIndex].text;
        document.getElementById('lwModalTitle').textContent = txt;
        const ic = document.getElementById('lwModalIcon');
        ic.className = 'lw-mhead-ico lw-tone-' + t[2];
        ic.innerHTML = '<i class="bi ' + t[1] + '"></i>';
    }
    function jump() {
        setTitle();
        if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
    function show(opts) {
        if (opts.filter) filter = opts.filter;
        if ('search' in opts) search.value = opts.search;
        if ('building' in opts) setBuilding(String(opts.building));
        apply(); jump();
    }

    chips.forEach(c => c.addEventListener('click', () => { filter = c.dataset.filter; apply(); setTitle(); }));
    kpis.forEach(k => k.addEventListener('click', e => {
        e.preventDefault();
        show({filter: filter === k.dataset.filter ? 'attention' : k.dataset.filter});
    }));
    document.querySelectorAll('.lw-alert-btn').forEach(b => b.addEventListener('click', () => show({filter: b.dataset.filter, search: '', building: ''})));
    document.querySelectorAll('.lw-person').forEach(b => b.addEventListener('click', () => show({filter: 'all', search: b.dataset.search, building: ''})));
    search.addEventListener('input', apply);
    document.querySelectorAll('[data-building-go]').forEach(b => b.addEventListener('click',
        () => show({filter: 'attention', search: '', building: b.dataset.buildingGo})));
    document.querySelectorAll('.lw-tab').forEach(t => t.addEventListener('click', () => {
        document.querySelectorAll('.lw-tab').forEach(x => x.classList.toggle('is-active', x === t));
        document.querySelectorAll('.lw-debt-list').forEach(l => l.classList.toggle('d-none', l.dataset.list !== t.dataset.tab));
    }));
    const debtSearch = document.getElementById('lwDebtSearch');
    if (debtSearch) debtSearch.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); show({filter: 'all', search: debtSearch.value.trim(), building: ''}); }
    });
    search.addEventListener('keydown', e => { if (e.key === 'Escape') { search.value = ''; apply(); } });

    // Alerts: hide for today (per browser).
    const hidKey = 'lwHiddenAlerts';
    let hidden = [];
    try { hidden = JSON.parse(localStorage.getItem(hidKey) || '[]'); } catch (e) {}
    document.querySelectorAll('.lw-alert').forEach(a => {
        if (hidden.includes(a.dataset.key)) { a.remove(); return; }
        a.querySelector('.lw-alert-x').addEventListener('click', () => {
            hidden.push(a.dataset.key);
            try { localStorage.setItem(hidKey, JSON.stringify(hidden.slice(-50))); } catch (e) {}
            a.remove();
        });
    });

    if (hasS2) {
        jQuery(building).select2({theme: 'bootstrap-5', width: '100%', placeholder: 'All buildings', allowClear: true,
                                  dropdownParent: jQuery(modalEl)})
            .on('change', () => { apply(); setTitle(); });
    } else {
        building.addEventListener('change', apply);
    }

    document.getElementById('lwExport').addEventListener('click', () => {
        const head = ['Lease','Status','Tenant','Phone','Unit','Flags','Bounced cheques','Bounced AED',
                      'Overdue cheques','Overdue AED','Days overdue','Collected AED','Cheques total AED','Next cheque'];
        const esc = v => { v = v == null ? '' : String(v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
        const lines = [head.map(esc).join(',')];
        rows.filter(tr => !tr.classList.contains('d-none'))
            .forEach(tr => lines.push(JSON.parse(tr.dataset.csv).map(esc).join(',')));
        const blob = new Blob(['﻿' + lines.join('\n')], {type: 'text/csv;charset=utf-8'});
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'lease-watch-' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
        URL.revokeObjectURL(a.href);
    });

    apply();

    // ---------- Charts ----------
    if (!window.Chart || !window.LW_CHARTS) return;
    const D = window.LW_CHARTS;
    const C = {blue: '#2a78d6', red: '#e34948', amber: '#eda100', ink: '#52514e', grid: '#eceef2'};
    const aed = v => 'AED ' + Math.round(v).toLocaleString('en-US');
    const short = v => v >= 1e6 ? (v / 1e6).toFixed(1) + 'M' : v >= 1e3 ? Math.round(v / 1e3) + 'k' : v;
    Chart.defaults.font.family = "'Plus Jakarta Sans', system-ui, sans-serif";
    Chart.defaults.color = C.ink;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 8;
    Chart.defaults.plugins.legend.labels.boxHeight = 8;
    Chart.defaults.plugins.legend.labels.pointStyleWidth = 10;
    Chart.defaults.plugins.tooltip.backgroundColor = '#111827';
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 8;
    const bar = {borderRadius: 4, borderColor: '#fff', maxBarThickness: 34};
    // Vertical gradient fill: lighter at the bottom, full colour at the top.
    const grad = (top, bottom) => ctx => {
        const area = ctx.chart.chartArea;
        if (!area) return top;
        const g = ctx.chart.ctx.createLinearGradient(0, area.bottom, 0, area.top);
        g.addColorStop(0, bottom);
        g.addColorStop(1, top);
        return g;
    };
    const mBar = {borderRadius: 8, borderSkipped: false, borderColor: '#fff', borderWidth: 2,
                  barPercentage: .62, categoryPercentage: .8, maxBarThickness: 44};

    // Area fill: colour fading to transparent toward the baseline.
    const fade = (rgb) => ctx => {
        const area = ctx.chart.chartArea;
        if (!area) return 'rgba(' + rgb + ',.12)';
        const g = ctx.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
        g.addColorStop(0, 'rgba(' + rgb + ',.28)');
        g.addColorStop(1, 'rgba(' + rgb + ',0)');
        return g;
    };
    const line = {borderWidth: 2.5, tension: .4, cubicInterpolationMode: 'monotone', fill: true, pointRadius: 4, pointHoverRadius: 7,
                  pointBackgroundColor: '#fff', pointBorderWidth: 2.5, pointHoverBorderWidth: 3};
    new Chart(document.getElementById('chMonthly'), {
        type: 'line',
        data: {
            labels: D.monthly.labels,
            datasets: [
                {label: 'Bounced / returned', key: 'bounced', data: D.monthly.bounced, borderColor: C.red,
                 pointBorderColor: C.red, pointHoverBackgroundColor: C.red, backgroundColor: fade('227,73,72'), ...line},
                {label: 'Still unpaid', key: 'unpaid', data: D.monthly.unpaid, borderColor: C.amber,
                 pointBorderColor: C.amber, pointHoverBackgroundColor: C.amber, backgroundColor: fade('237,161,0'), ...line},
            ],
        },
        options: {
            maintainAspectRatio: false,
            interaction: {mode: 'index', intersect: false},
            plugins: {legend: {position: 'top', align: 'end'},
                      tooltip: {callbacks: {
                          label: c => ' ' + c.dataset.label + ': ' + c.parsed.y + ' cheques · ' + aed(D.monthly[c.dataset.key + '_amt'][c.dataIndex]),
                          footer: items => {
                              const i = items[0].dataIndex;
                              const paid = D.monthly.paid[i], t = paid + D.monthly.bounced[i] + D.monthly.unpaid[i];
                              return t ? 'Cleared: ' + paid + ' of ' + t + ' (' + Math.floor(paid / t * 100) + '%)' : '';
                          },
                      }}},
            scales: {
                x: {grid: {display: false}, border: {display: false}},
                y: {beginAtZero: true, grid: {color: C.grid}, border: {display: false}, ticks: {precision: 0}},
            },
        },
    });

})();
</script>
JS;
require_once __DIR__ . '/includes/legal_layout_footer.php';
?>
