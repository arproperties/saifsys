<?php
/**
 * Legal Department — Reports.
 *
 * Lease-collection reports built from the same data as the Legal Dashboard
 * (includes/legal_lease_watch.php), each with a chart and a table, plus the
 * legal-case summary. ?building=ID re-scopes every section.
 *
 * Totals agree by construction: arrears by building, debt ageing and the
 * debtor list all come from the same open-cheque set (bounced + overdue),
 * which equals the per-lease totals of the Lease Watch.
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

$currentCompanyId = current_company_id($conn) ?: 1;
$re = legal_re_path();

/**
 * Every non-cancelled cheque dated $from … $today, same lease scope and
 * "paid" rule as legal_watch_cheque_insights(), with its building — so the
 * monthly trend and collection rate can be scoped by building.
 */
if (!function_exists('lrp_window_cheques')) {
    function lrp_window_cheques(PDO $conn, int $companyId, string $from, string $today): array
    {
        $hasLinks = false;
        try {
            $hasLinks = (bool)$conn->query("SHOW TABLES LIKE 're_receipt_cheque_links'")->fetchColumn();
        } catch (Throwable $e) {
        }
        $collSql = "SELECT p.cheque_id, p.amount AS amt FROM re_payments p
                    WHERE p.company_id = :c1 AND p.cheque_id IS NOT NULL";
        if ($hasLinks) {
            $collSql .= " UNION ALL SELECT k.cheque_id, k.amount_applied FROM re_receipt_cheque_links k WHERE k.company_id = :c2";
        }
        $sql = "
            SELECT c.id, c.status, c.cheque_date, c.cheque_amount,
                   LEAST(COALESCE(col.got, 0), c.cheque_amount) AS got,
                   (c.status IN ('cleared','paid','deposited') OR COALESCE(col.got,0) >= c.cheque_amount - 0.005) AS is_paid,
                   b.id AS building_id, b.name AS building_name
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
              AND c.cheque_date BETWEEN :from AND :today
        ";
        $params = [':c1' => $companyId, ':c3' => $companyId, ':c4' => $companyId, ':from' => $from, ':today' => $today];
        if ($hasLinks) $params[':c2'] = $companyId;
        $st = $conn->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('lrp_pct')) {
    function lrp_pct(float $part, float $whole, int $dec = 1): string
    {
        return $whole > 0 ? number_format($part / $whole * 100, $dec) . '%' : '—';
    }
}

// ---------------------------------------------------------------- data
$loadError = null;
$watchRows = [];
$ins = ['open' => [], 'monthly' => [], 'upcoming' => []];
$winCheques = [];
$today = date('Y-m-d');
$winFrom = date('Y-m-01', strtotime('-12 months'));   // same window as the dashboard: 12 months + this month
try {
    $watchRows  = legal_lease_watch_rows($conn, (int)$currentCompanyId);
    $ins        = legal_watch_cheque_insights($conn, (int)$currentCompanyId);
    $winCheques = lrp_window_cheques($conn, (int)$currentCompanyId, $winFrom, $today);
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

$buildings = [];
foreach ($watchRows as $r) $buildings[(int)$r['building_id']] = $r['building_name'];
asort($buildings, SORT_NATURAL | SORT_FLAG_CASE);

$bid = (int)($_GET['building'] ?? 0);
if (!isset($buildings[$bid])) $bid = 0;
$scopeName = $bid ? $buildings[$bid] : 'All buildings';

// Scope everything to the chosen building.
$inScope = fn(array $x) => !$bid || (int)$x['building_id'] === $bid;
$rows    = array_values(array_filter($watchRows, $inScope));
$open    = array_values(array_filter($ins['open'], $inScope));
$win     = array_values(array_filter($winCheques, $inScope));
$tot     = legal_lease_watch_totals($rows);
$flagMeta = legal_watch_flag_meta();

// (a) Arrears by building — from the open-cheque set.
$arrears = [];
foreach ($open as $c) {
    $k = (int)$c['building_id'];
    $arrears[$k] ??= ['name' => $c['building_name'], 'leases' => [], 'bounced' => 0.0, 'overdue' => 0.0, 'bounced_n' => 0, 'overdue_n' => 0];
    $arrears[$k][$c['kind']] += (float)$c['owed'];
    $arrears[$k][$c['kind'] . '_n']++;
    $arrears[$k]['leases'][(int)$c['lease_id']] = true;
}
uasort($arrears, fn($a, $b) => ($b['bounced'] + $b['overdue']) <=> ($a['bounced'] + $a['overdue']));
$arrTot = ['bounced' => 0.0, 'overdue' => 0.0, 'bounced_n' => 0, 'overdue_n' => 0];
$arrLeases = [];
foreach ($open as $c) {
    $arrTot[$c['kind']] += (float)$c['owed'];
    $arrTot[$c['kind'] . '_n']++;
    $arrLeases[(int)$c['lease_id']] = true;
}
$owedTotal = $arrTot['bounced'] + $arrTot['overdue'];

// (b) Ageing — amounts/counts from the shared helper, kind counts alongside.
$ageing = legal_watch_ageing($open);
$ageBounds = ['0–30' => [0, 30], '31–60' => [31, 60], '61–90' => [61, 90], '91–180' => [91, 180], '180+' => [181, PHP_INT_MAX]];
$ageKindN = array_fill_keys(array_keys($ageBounds), ['bounced' => 0, 'overdue' => 0]);
foreach ($open as $c) {
    foreach ($ageBounds as $lbl => [$lo, $hi]) {
        if ($c['age_days'] >= $lo && $c['age_days'] <= $hi) { $ageKindN[$lbl][$c['kind']]++; break; }
    }
}
$ageTotal = 0.0;
foreach ($ageing as $a) $ageTotal += $a['bounced'] + $a['overdue'];
$over90 = 0.0;
foreach (['91–180', '180+'] as $k) $over90 += $ageing[$k]['bounced'] + $ageing[$k]['overdue'];

// (c) Monthly trend and (e) collection rate — from the window cheques.
$months = [];
for ($m = 12; $m >= 0; $m--) {
    $k = date('Y-m', strtotime("first day of -$m months"));
    $months[$k] = ['paid' => 0, 'bounced' => 0, 'unpaid' => 0, 'paid_amt' => 0.0, 'bounced_amt' => 0.0, 'unpaid_amt' => 0.0];
}
$coll = [];
foreach ($win as $c) {
    $amt   = (float)$c['cheque_amount'];
    $owed  = max(0.0, $amt - (float)$c['got']);
    $state = $c['is_paid'] ? 'paid' : (in_array($c['status'], ['bounced', 'returned'], true) ? 'bounced' : 'unpaid');
    $k = substr((string)$c['cheque_date'], 0, 7);
    if (isset($months[$k])) {
        $months[$k][$state]++;
        $months[$k][$state . '_amt'] += $state === 'paid' ? $amt : $owed;
    }
    $b = (int)$c['building_id'];
    $coll[$b] ??= ['name' => $c['building_name'], 'paid' => 0, 'bounced' => 0, 'unpaid' => 0, 'due_amt' => 0.0, 'paid_amt' => 0.0];
    $coll[$b][$state]++;
    $coll[$b]['due_amt'] += $amt;
    if ($state === 'paid') $coll[$b]['paid_amt'] += $amt;
}
foreach ($coll as &$cb) {
    $cb['due']  = $cb['paid'] + $cb['bounced'] + $cb['unpaid'];
    $cb['rate'] = $cb['due'] > 0 ? $cb['paid'] / $cb['due'] * 100 : 0;
}
unset($cb);
uasort($coll, fn($a, $b) => $a['rate'] <=> $b['rate'] ?: $b['due'] <=> $a['due']);
$mTot = ['paid' => 0, 'bounced' => 0, 'unpaid' => 0, 'paid_amt' => 0.0, 'bounced_amt' => 0.0, 'unpaid_amt' => 0.0];
foreach ($months as $mv) foreach ($mTot as $k => $_) $mTot[$k] += $mv[$k];
$chqDue   = $mTot['paid'] + $mTot['bounced'] + $mTot['unpaid'];
$collRate = $chqDue > 0 ? (int)floor($mTot['paid'] / $chqDue * 100) : 0;
$winLabel = date('M Y', strtotime($winFrom)) . ' – ' . date('j M Y');

// (d) Top 20 debtors — lease rows; "oldest debt" from the same open cheques.
$oldest = [];
foreach ($open as $c) $oldest[(int)$c['lease_id']] = max($oldest[(int)$c['lease_id']] ?? 0, (int)$c['age_days']);
$debtors = array_values(array_filter($rows, fn($r) => (float)$r['outstanding'] > 0.005));
usort($debtors, fn($a, $b) => $b['outstanding'] <=> $a['outstanding']);
$debtorCount = count($debtors);
$top = array_slice($debtors, 0, 20);
$topSum = array_sum(array_map(fn($r) => (float)$r['outstanding'], $top));

// (f) Legal cases — existing queries, scoped by building when one is chosen.
$caseWhere = 'company_id = ? AND deleted_at IS NULL' . ($bid ? ' AND building_id = ?' : '');
$caseArgs  = $bid ? [$currentCompanyId, $bid] : [$currentCompanyId];
$summary = [];
$costSummary = [];
$byStatus = $byType = $topCosts = [];
try {
    $st = $conn->prepare("
        SELECT
            COUNT(*) AS total_cases,
            SUM(CASE WHEN status NOT IN ('settled','closed','withdrawn') THEN 1 ELSE 0 END) AS open_cases,
            COALESCE(SUM(claim_amount),0) AS total_claim,
            COALESCE(SUM(recovered_amount),0) AS total_recovered,
            COALESCE(SUM(CASE WHEN status NOT IN ('settled','closed','withdrawn') THEN claim_amount ELSE 0 END),0) AS open_claim
        FROM re_legal_cases WHERE $caseWhere
    ");
    $st->execute($caseArgs);
    $summary = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $costSql = "SELECT COALESCE(SUM(co.amount),0) AS total_cost, COALESCE(SUM(CASE WHEN co.is_recovered=1 THEN co.amount ELSE 0 END),0) AS recovered_cost
                FROM re_legal_case_costs co" . ($bid ? " JOIN re_legal_cases c ON c.id = co.case_id AND c.building_id = ?" : '') . "
                WHERE co.company_id = ?";
    $st = $conn->prepare($costSql);
    $st->execute($bid ? [$bid, $currentCompanyId] : [$currentCompanyId]);
    $costSummary = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $st = $conn->prepare("SELECT status, COUNT(*) cnt FROM re_legal_cases WHERE $caseWhere GROUP BY status ORDER BY cnt DESC");
    $st->execute($caseArgs);
    $byStatus = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $conn->prepare("SELECT case_type, COUNT(*) cnt, COALESCE(SUM(claim_amount),0) claim FROM re_legal_cases WHERE $caseWhere GROUP BY case_type ORDER BY cnt DESC");
    $st->execute($caseArgs);
    $byType = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $conn->prepare("
        SELECT c.id, c.case_number, c.title, COALESCE(SUM(co.amount),0) AS total_cost
        FROM re_legal_case_costs co JOIN re_legal_cases c ON c.id = co.case_id
        WHERE co.company_id = ?" . ($bid ? " AND c.building_id = ?" : '') . "
        GROUP BY co.case_id ORDER BY total_cost DESC LIMIT 10
    ");
    $st->execute($bid ? [$currentCompanyId, $bid] : [$currentCompanyId]);
    $topCosts = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $loadError = $loadError ?: $e->getMessage();
}
$hasCases = (int)($summary['total_cases'] ?? 0) > 0 || (float)($costSummary['total_cost'] ?? 0) > 0;

// ---------------------------------------------------------------- charts
$ageCols = ['#b19cfb', '#9177f5', '#7c3aed', '#5b21b6', '#3b1478'];
$chartData = [
    'arrears' => [
        'labels'  => array_values(array_map(fn($v) => $v['name'], $arrears)),
        'bounced' => array_values(array_map(fn($v) => round($v['bounced']), $arrears)),
        'overdue' => array_values(array_map(fn($v) => round($v['overdue']), $arrears)),
    ],
    'ageing' => [
        'labels'  => array_map(fn($k) => $k . ' days', array_keys($ageing)),
        'total'   => array_values(array_map(fn($v) => round($v['bounced'] + $v['overdue']), $ageing)),
        'bounced' => array_values(array_map(fn($v) => round($v['bounced']), $ageing)),
        'overdue' => array_values(array_map(fn($v) => round($v['overdue']), $ageing)),
        'count'   => array_values(array_map(fn($v) => (int)$v['count'], $ageing)),
        'colors'  => $ageCols,
    ],
    'monthly' => [
        'labels'  => array_map(fn($k) => date('M y', strtotime($k . '-01')), array_keys($months)),
        'paid'    => array_column(array_values($months), 'paid'),
        'bounced' => array_column(array_values($months), 'bounced'),
        'unpaid'  => array_column(array_values($months), 'unpaid'),
        'bounced_amt' => array_map('round', array_column(array_values($months), 'bounced_amt')),
        'unpaid_amt'  => array_map('round', array_column(array_values($months), 'unpaid_amt')),
    ],
    'coll' => [
        'labels' => array_values(array_map(fn($v) => $v['name'], $coll)),
        'rate'   => array_values(array_map(fn($v) => floor($v['rate'] * 10) / 10, $coll)),
        'paid'   => array_values(array_map(fn($v) => $v['paid'], $coll)),
        'due'    => array_values(array_map(fn($v) => $v['due'], $coll)),
    ],
];

$rateSpark = lw_spark(array_map(fn($k, $v) => [date('M', strtotime($k . '-01')),
    ($t = $v['paid'] + $v['bounced'] + $v['unpaid']) > 0 ? (int)floor($v['paid'] / $t * 100) : 0],
    array_slice(array_keys($months), -6), array_slice($months, -6)));

$pageTitle = 'Legal Reports';
$cssVer = @filemtime(__DIR__ . '/assets/legal-reports.css') ?: 1;
$pageHead = '
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<link href="assets/legal-reports.css?v=' . (int)$cssVer . '" rel="stylesheet">';
require_once __DIR__ . '/includes/legal_layout_header.php';

$csvBtn = fn(string $table) => '<button type="button" class="lw-detail-btn lr-noprint" data-csv-table="' . h($table) . '"><i class="bi bi-download"></i> CSV</button>';
?>
<div class="lw-page lr-page">

    <div class="lw-head d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
        <div>
            <h1 class="mb-1">Legal Reports</h1>
            <div class="lw-sub">Lease collections · <strong><?= h($scopeName) ?></strong> · as of <?= h(date('l, j F Y')) ?></div>
        </div>
        <form method="get" class="d-flex gap-2 flex-wrap align-items-center lr-noprint" id="lrFilter">
            <div style="width:280px;max-width:100%">
                <label for="lrBuilding" class="visually-hidden">Building</label>
                <select id="lrBuilding" name="building" class="form-select form-select-sm">
                    <option value="">All buildings</option>
                    <?php foreach ($buildings as $id => $name): ?>
                        <option value="<?= (int)$id ?>" <?= $id === $bid ? 'selected' : '' ?>><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript><button class="btn lw-btn-soft btn-sm">Apply</button></noscript>
            <?php if ($bid): ?><a href="legal_reports.php" class="btn lw-btn-soft"><i class="bi bi-x-lg"></i> Clear</a><?php endif; ?>
            <button type="button" class="btn lw-btn-grad" id="lrPrint"><i class="bi bi-printer"></i> Print / Save PDF</button>
        </form>
    </div>

    <?php if ($loadError): ?>
        <div class="alert alert-danger">Some report data could not be loaded: <?= h($loadError) ?></div>
    <?php endif; ?>

    <!-- Summary strip -->
    <div class="lw-strip mb-4 lr-strip">
        <?php
        $segs = [
            ['navy', 'bi-cash-stack', 'Owed to us', 'AED ' . lw_aed($owedTotal),
                ($arrTot['bounced_n'] + $arrTot['overdue_n']) . ' cheques · ' . count($arrLeases) . ' leases', ''],
            ['rose', 'bi-x-octagon-fill', 'Bounced, not recovered', 'AED ' . lw_aed($arrTot['bounced']),
                $arrTot['bounced_n'] . ' cheques · ' . $tot['bounced_leases'] . ' leases', ''],
            ['amber', 'bi-alarm-fill', 'Overdue (date passed)', 'AED ' . lw_aed($arrTot['overdue']),
                $arrTot['overdue_n'] . ' cheques · ' . $tot['overdue_leases'] . ' leases', ''],
            ['violet', 'bi-hourglass-split', 'Debt older than 90 days', 'AED ' . lw_aed($over90),
                lrp_pct($over90, $ageTotal, 0) . ' of what is owed', ''],
            ['sky', 'bi-check2-circle', 'Cheques cleared', $collRate . '%',
                number_format($mTot['paid']) . ' of ' . number_format($chqDue) . ' due · 12 months', $rateSpark],
        ];
        foreach ($segs as [$tone, $icon, $label, $big, $sub, $sp]): ?>
            <div class="lw-alert lw-seg lw-st-<?= $tone ?>">
                <span class="lw-seg-ico"><i class="bi <?= $icon ?>"></i></span>
                <div class="lw-seg-body">
                    <div class="lw-seg-label"><?= h($label) ?></div>
                    <div class="lw-seg-big"><?= h($big) ?></div>
                    <div class="lw-seg-sub"><?= h($sub) ?></div>
                </div>
                <?php if ($sp): ?><span class="lw-seg-spark" title="Last 6 months"><?= $sp ?></span><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4 mb-4">
        <!-- (a) Arrears by building -->
        <div class="col-xxl-7">
            <section class="lw-panel h-100 lr-section" aria-labelledby="lrA">
                <div class="lw-panel-head">
                    <div>
                        <h2 id="lrA"><span class="lr-num">A</span> Arrears by building</h2>
                        <div class="lw-muted">Unpaid bounced cheques and overdue cheques, AED owed now</div>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <span class="lr-total">AED <?= number_format($owedTotal) ?></span>
                        <?= $csvBtn('lrTblA') ?>
                    </div>
                </div>
                <?php if (!$arrears): ?>
                    <div class="lw-empty-state"><i class="bi bi-emoji-smile"></i>Nothing is owed in <?= h($scopeName) ?>.</div>
                <?php else: ?>
                    <div class="lw-legend mb-2"><span><i style="background:#e34948"></i>Bounced</span><span><i style="background:#eda100"></i>Overdue</span></div>
                    <div class="lw-chart-box lr-chart" style="height:<?= max(140, count($arrears) * 30 + 50) ?>px">
                        <canvas id="chArrears" role="img" aria-label="Stacked bars: bounced and overdue AED per building. The table below has the same figures."></canvas>
                    </div>
                    <div class="table-responsive lr-tbl-wrap mt-3">
                        <table class="table table-sm lr-table" id="lrTblA" data-csv-name="arrears-by-building">
                            <thead><tr>
                                <th>Building</th><th class="text-end">Leases</th>
                                <th class="text-end">Bounced chq</th><th class="text-end">Bounced AED</th>
                                <th class="text-end">Overdue chq</th><th class="text-end">Overdue AED</th>
                                <th class="text-end">Total AED</th><th class="text-end">Share</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($arrears as $id => $a): $t = $a['bounced'] + $a['overdue']; ?>
                                <tr>
                                    <td><a href="?building=<?= (int)$id ?>" title="Show only this building"><?= h($a['name']) ?></a></td>
                                    <td class="text-end"><?= count($a['leases']) ?></td>
                                    <td class="text-end"><?= (int)$a['bounced_n'] ?></td>
                                    <td class="text-end lw-money red" data-csv="<?= round($a['bounced'], 2) ?>"><?= number_format($a['bounced']) ?></td>
                                    <td class="text-end"><?= (int)$a['overdue_n'] ?></td>
                                    <td class="text-end lw-money amber" data-csv="<?= round($a['overdue'], 2) ?>"><?= number_format($a['overdue']) ?></td>
                                    <td class="text-end lw-money" data-csv="<?= round($t, 2) ?>"><?= number_format($t) ?></td>
                                    <td class="text-end"><?= lrp_pct($t, $owedTotal) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot><tr>
                                <th>Total</th><th class="text-end"><?= count($arrLeases) ?></th>
                                <th class="text-end"><?= (int)$arrTot['bounced_n'] ?></th>
                                <th class="text-end" data-csv="<?= round($arrTot['bounced'], 2) ?>"><?= number_format($arrTot['bounced']) ?></th>
                                <th class="text-end"><?= (int)$arrTot['overdue_n'] ?></th>
                                <th class="text-end" data-csv="<?= round($arrTot['overdue'], 2) ?>"><?= number_format($arrTot['overdue']) ?></th>
                                <th class="text-end" data-csv="<?= round($owedTotal, 2) ?>"><?= number_format($owedTotal) ?></th>
                                <th class="text-end">100%</th>
                            </tr></tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <!-- (b) Debt ageing -->
        <div class="col-xxl-5">
            <section class="lw-panel h-100 lr-section" aria-labelledby="lrB">
                <div class="lw-panel-head">
                    <div>
                        <h2 id="lrB"><span class="lr-num">B</span> Debt ageing</h2>
                        <div class="lw-muted">Days since the cheque date (or bounce date for bounced cheques)</div>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <span class="lr-total">AED <?= number_format($ageTotal) ?></span>
                        <?= $csvBtn('lrTblB') ?>
                    </div>
                </div>
                <?php if ($ageTotal <= 0): ?>
                    <div class="lw-empty-state"><i class="bi bi-emoji-smile"></i>No open debt to age.</div>
                <?php else: ?>
                    <div class="lw-chart-box lr-chart" style="height:220px">
                        <canvas id="chAgeing" role="img" aria-label="Bars: AED owed in each age bucket. The table below has the same figures."></canvas>
                    </div>
                    <div class="table-responsive lr-tbl-wrap mt-3">
                        <table class="table table-sm lr-table" id="lrTblB" data-csv-name="debt-ageing">
                            <thead><tr>
                                <th>Age</th><th class="text-end">Cheques</th>
                                <th class="text-end">Bounced</th><th class="text-end">Overdue</th>
                                <th class="text-end">Total AED</th><th class="text-end">Share</th>
                            </tr></thead>
                            <tbody>
                            <?php $i = 0; foreach ($ageing as $lbl => $a): $t = $a['bounced'] + $a['overdue']; ?>
                                <tr>
                                    <td class="text-nowrap"><span class="lr-sw" style="background:<?= $ageCols[$i] ?>"></span><?= h($lbl) ?> days</td>
                                    <td class="text-end"><?= (int)$a['count'] ?></td>
                                    <td class="text-end" data-csv="<?= round($a['bounced'], 2) ?>"><span class="lw-money red"><?= number_format($a['bounced']) ?></span><span class="lw-muted d-block"><?= $ageKindN[$lbl]['bounced'] ?> chq</span></td>
                                    <td class="text-end" data-csv="<?= round($a['overdue'], 2) ?>"><span class="lw-money amber"><?= number_format($a['overdue']) ?></span><span class="lw-muted d-block"><?= $ageKindN[$lbl]['overdue'] ?> chq</span></td>
                                    <td class="text-end lw-money" data-csv="<?= round($t, 2) ?>"><?= number_format($t) ?></td>
                                    <td class="text-end"><?= lrp_pct($t, $ageTotal) ?></td>
                                </tr>
                            <?php $i++; endforeach; ?>
                            </tbody>
                            <tfoot><tr>
                                <th>Total</th><th class="text-end"><?= count($open) ?></th>
                                <th class="text-end" data-csv="<?= round($arrTot['bounced'], 2) ?>"><?= number_format($arrTot['bounced']) ?></th>
                                <th class="text-end" data-csv="<?= round($arrTot['overdue'], 2) ?>"><?= number_format($arrTot['overdue']) ?></th>
                                <th class="text-end" data-csv="<?= round($ageTotal, 2) ?>"><?= number_format($ageTotal) ?></th>
                                <th class="text-end">100%</th>
                            </tr></tfoot>
                        </table>
                    </div>
                    <?php if (abs($ageTotal - $owedTotal) < 1): ?>
                        <div class="lr-check"><i class="bi bi-check2-circle"></i> Same total as Arrears by building (AED <?= number_format($owedTotal) ?>)</div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <!-- (c) Bounce & unpaid trend -->
    <section class="lw-panel mb-4 lr-section" aria-labelledby="lrC">
        <div class="lw-panel-head">
            <div>
                <h2 id="lrC"><span class="lr-num">C</span> Bounce &amp; unpaid trend</h2>
                <div class="lw-muted">Cheques by the month they were due, <?= h($winLabel) ?>. Status as of today.</div>
            </div>
            <div class="d-flex gap-3 align-items-center">
                <div class="text-end">
                    <div class="lw-hero-pct"><?= (int)$collRate ?>%</div>
                    <div class="lw-muted">of <?= number_format($chqDue) ?> cheques cleared</div>
                </div>
                <?= $csvBtn('lrTblC') ?>
            </div>
        </div>
        <?php if ($chqDue === 0): ?>
            <div class="lw-empty-state"><i class="bi bi-calendar-x"></i>No cheques were due in this period.</div>
        <?php else: ?>
            <div class="lw-chart-box lr-chart" style="height:260px">
                <canvas id="chMonthly" role="img" aria-label="Lines: bounced and still-unpaid cheques per month. The table below has the same figures."></canvas>
            </div>
            <div class="table-responsive lr-tbl-wrap mt-3">
                <table class="table table-sm lr-table" id="lrTblC" data-csv-name="bounce-unpaid-trend">
                    <thead><tr>
                        <th>Month</th><th class="text-end">Cheques due</th><th class="text-end">Cleared</th>
                        <th class="text-end">Bounced</th><th class="text-end">Bounced AED</th>
                        <th class="text-end">Still unpaid</th><th class="text-end">Unpaid AED</th>
                        <th class="text-end">% cleared</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach (array_reverse($months, true) as $k => $mv): $due = $mv['paid'] + $mv['bounced'] + $mv['unpaid']; ?>
                        <tr>
                            <td><?= h(date('M Y', strtotime($k . '-01'))) ?><?= $k === date('Y-m') ? ' <span class="lw-muted">(to date)</span>' : '' ?></td>
                            <td class="text-end"><?= $due ?></td>
                            <td class="text-end"><?= (int)$mv['paid'] ?></td>
                            <td class="text-end"><?= (int)$mv['bounced'] ?></td>
                            <td class="text-end lw-money red" data-csv="<?= round($mv['bounced_amt'], 2) ?>"><?= $mv['bounced_amt'] > 0 ? number_format($mv['bounced_amt']) : '—' ?></td>
                            <td class="text-end"><?= (int)$mv['unpaid'] ?></td>
                            <td class="text-end lw-money amber" data-csv="<?= round($mv['unpaid_amt'], 2) ?>"><?= $mv['unpaid_amt'] > 0 ? number_format($mv['unpaid_amt']) : '—' ?></td>
                            <td class="text-end"><?= $due > 0 ? (int)floor($mv['paid'] / $due * 100) . '%' : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr>
                        <th>Total</th><th class="text-end"><?= $chqDue ?></th><th class="text-end"><?= (int)$mTot['paid'] ?></th>
                        <th class="text-end"><?= (int)$mTot['bounced'] ?></th>
                        <th class="text-end" data-csv="<?= round($mTot['bounced_amt'], 2) ?>"><?= number_format($mTot['bounced_amt']) ?></th>
                        <th class="text-end"><?= (int)$mTot['unpaid'] ?></th>
                        <th class="text-end" data-csv="<?= round($mTot['unpaid_amt'], 2) ?>"><?= number_format($mTot['unpaid_amt']) ?></th>
                        <th class="text-end"><?= $collRate ?>%</th>
                    </tr></tfoot>
                </table>
            </div>
            <div class="lw-muted mt-2">Bounced here counts every bounced cheque not yet paid, including ones already replaced or settled — so it can be higher than the bounced figure in A.</div>
        <?php endif; ?>
    </section>

    <!-- (d) Top 20 debtors -->
    <section class="lw-panel mb-4 lr-section" aria-labelledby="lrD">
        <div class="lw-panel-head">
            <div>
                <h2 id="lrD"><span class="lr-num">D</span> Top 20 debtors</h2>
                <div class="lw-muted">
                    <?php if ($debtorCount): ?>
                        Top <?= count($top) ?> of <?= $debtorCount ?> leases that owe money · AED <?= number_format($topSum) ?> of AED <?= number_format($owedTotal) ?> (<?= lrp_pct($topSum, $owedTotal, 0) ?>)
                    <?php else: ?>Leases ranked by bounced + overdue AED<?php endif; ?>
                </div>
            </div>
            <?= $csvBtn('lrTblD') ?>
        </div>
        <?php if (!$top): ?>
            <div class="lw-empty-state"><i class="bi bi-emoji-smile"></i>No lease owes money in <?= h($scopeName) ?>.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table lw-table lr-table" id="lrTblD" data-csv-name="top-debtors">
                    <thead><tr>
                        <th>#</th><th>Tenant</th><th>Lease</th><th>Building / unit</th>
                        <th class="text-end">Bounced AED</th><th class="text-end">Overdue AED</th>
                        <th class="text-end">Total AED</th><th class="text-end">Oldest debt</th>
                        <th>Flags</th><th class="text-end lr-noprint" data-csv-skip>Open</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($top as $i => $r): $sev = $r['bounced_count'] > 0 ? 3 : 2; ?>
                        <tr class="lw-sev-<?= $sev ?>">
                            <td class="lw-muted"><?= $i + 1 ?></td>
                            <td data-csv="<?= h($r['tenant_label']) ?>">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="lw-avatar lw-avatar-sm lw-av-<?= lw_avatar_tone($r['tenant_label']) ?>"><?= h(lw_initials($r['tenant_label'])) ?></span>
                                    <div class="min-w-0">
                                        <div class="lw-tname"><?= h($r['tenant_label']) ?></div>
                                        <?php if ($r['phone']): ?><span class="lw-muted"><?= h($r['phone']) ?></span><?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="lw-lease" data-csv="<?= h($r['lease_number']) ?>">
                                <a href="<?= h($re) ?>lease_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['lease_number'] ?: '#' . $r['id']) ?></a><br>
                                <span class="lw-status <?= h($r['status']) ?>"><?= h(str_replace('_', ' ', $r['status'])) ?></span>
                            </td>
                            <td data-csv="<?= h($r['building_name'] . ' ' . $r['unit_number']) ?>"><?= h($r['building_name']) ?><br><span class="lw-muted">Unit <?= h($r['unit_number']) ?></span></td>
                            <td class="text-end" data-csv="<?= round((float)$r['bounced_amt'], 2) ?>">
                                <?php if ($r['bounced_amt'] > 0): ?><div class="lw-money red"><?= number_format((float)$r['bounced_amt']) ?></div><span class="lw-muted"><?= (int)$r['bounced_count'] ?> chq</span><?php else: ?><span class="lw-muted">—</span><?php endif; ?>
                            </td>
                            <td class="text-end" data-csv="<?= round((float)$r['overdue_amt'], 2) ?>">
                                <?php if ($r['overdue_amt'] > 0): ?><div class="lw-money amber"><?= number_format((float)$r['overdue_amt']) ?></div><span class="lw-muted"><?= (int)$r['overdue_count'] ?> chq</span><?php else: ?><span class="lw-muted">—</span><?php endif; ?>
                            </td>
                            <td class="text-end lw-money" data-csv="<?= round((float)$r['outstanding'], 2) ?>"><?= number_format((float)$r['outstanding']) ?></td>
                            <td class="text-end text-nowrap" data-csv="<?= (int)($oldest[(int)$r['id']] ?? 0) ?>"><?= (int)($oldest[(int)$r['id']] ?? 0) ?> days</td>
                            <td style="max-width:240px" data-csv="<?= h(implode('; ', array_map(fn($f) => $flagMeta[$f][0], $r['flags']))) ?>">
                                <?php foreach ($r['flags'] as $f): [$fl, , $fi] = $flagMeta[$f]; ?>
                                    <span class="lw-flag lw-flag-<?= h($f) ?>"><i class="bi <?= h($fi) ?>"></i> <?= h($fl) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td class="text-end lw-actions text-nowrap lr-noprint" data-csv-skip>
                                <a class="btn btn-outline-secondary" href="<?= h($re) ?>lease_view.php?id=<?= (int)$r['id'] ?>#installments" title="Open lease and cheques"><i class="bi bi-box-arrow-up-right"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <div class="row g-4 mb-4">
        <!-- (e) Collection rate by building -->
        <div class="col-xxl-7">
            <section class="lw-panel h-100 lr-section" aria-labelledby="lrE">
                <div class="lw-panel-head">
                    <div>
                        <h2 id="lrE"><span class="lr-num">E</span> Collection rate by building</h2>
                        <div class="lw-muted">Cheques cleared ÷ cheques due, <?= h($winLabel) ?> · lowest first</div>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <span class="lr-total"><?= $collRate ?>% overall</span>
                        <?= $csvBtn('lrTblE') ?>
                    </div>
                </div>
                <?php if (!$coll): ?>
                    <div class="lw-empty-state"><i class="bi bi-calendar-x"></i>No cheques were due in this period.</div>
                <?php else: ?>
                    <div class="lw-chart-box lr-chart" style="height:<?= max(140, count($coll) * 28 + 50) ?>px">
                        <canvas id="chColl" role="img" aria-label="Bars: percent of cheques cleared per building. The table below has the same figures."></canvas>
                    </div>
                    <div class="table-responsive lr-tbl-wrap mt-3">
                        <table class="table table-sm lr-table" id="lrTblE" data-csv-name="collection-rate-by-building">
                            <thead><tr>
                                <th>Building</th><th class="text-end">Cheques due</th><th class="text-end">Cleared</th>
                                <th class="text-end">Bounced</th><th class="text-end">Unpaid</th>
                                <th class="text-end">AED due</th><th class="text-end">% cleared</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($coll as $id => $c): ?>
                                <tr>
                                    <td><a href="?building=<?= (int)$id ?>" title="Show only this building"><?= h($c['name']) ?></a></td>
                                    <td class="text-end"><?= $c['due'] ?></td>
                                    <td class="text-end"><?= $c['paid'] ?></td>
                                    <td class="text-end"><?= $c['bounced'] ?></td>
                                    <td class="text-end"><?= $c['unpaid'] ?></td>
                                    <td class="text-end" data-csv="<?= round($c['due_amt'], 2) ?>"><?= number_format($c['due_amt']) ?></td>
                                    <td class="text-end"><span class="lr-rate <?= $c['rate'] < 80 ? 'is-low' : ($c['rate'] >= 95 ? 'is-good' : '') ?>"><?= number_format(floor($c['rate'] * 10) / 10, 1) ?>%</span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot><tr>
                                <th>Total</th><th class="text-end"><?= $chqDue ?></th><th class="text-end"><?= (int)$mTot['paid'] ?></th>
                                <th class="text-end"><?= (int)$mTot['bounced'] ?></th><th class="text-end"><?= (int)$mTot['unpaid'] ?></th>
                                <th class="text-end" data-csv="<?= round(array_sum(array_column($coll, 'due_amt')), 2) ?>"><?= number_format(array_sum(array_column($coll, 'due_amt'))) ?></th>
                                <th class="text-end"><?= $chqDue > 0 ? number_format(floor($mTot['paid'] / $chqDue * 1000) / 10, 1) : '0.0' ?>%</th>
                            </tr></tfoot>
                        </table>
                    </div>
                    <div class="lw-muted mt-2"><span class="lr-rate is-low">below 80%</span> <span class="lr-rate is-good">95% or more</span> · totals match the trend in C.</div>
                <?php endif; ?>
            </section>
        </div>

        <!-- (f) Legal cases -->
        <div class="col-xxl-5">
            <section class="lw-panel h-100 lr-section" aria-labelledby="lrF">
                <div class="lw-panel-head">
                    <div>
                        <h2 id="lrF"><span class="lr-num">F</span> Legal cases</h2>
                        <div class="lw-muted">Cases, claims and legal costs<?= $bid ? ' for ' . h($scopeName) : '' ?></div>
                    </div>
                    <a href="legal_cases.php" class="lw-link lr-noprint">All cases <i class="bi bi-arrow-right"></i></a>
                </div>
                <?php if (!$hasCases): ?>
                    <div class="lr-nocase">
                        <i class="bi bi-briefcase"></i>
                        <div>
                            <div>No legal cases recorded<?= $bid ? ' for this building' : '' ?> yet.</div>
                            <div class="lw-muted"><?= (int)$tot['legal_leases'] ?> lease<?= $tot['legal_leases'] == 1 ? '' : 's' ?> here <?= $tot['legal_leases'] == 1 ? 'is' : 'are' ?> escalated to legal by accounting; <?= count($arrLeases) ?> owe money (see A and D).</div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row g-2 mb-3">
                        <?php foreach ([
                            ['bi-briefcase', 'violet', 'Open cases', number_format((int)($summary['open_cases'] ?? 0)), 'of ' . (int)($summary['total_cases'] ?? 0) . ' total'],
                            ['bi-cash-coin', 'pink', 'Open claim', 'AED ' . lw_aed((float)($summary['open_claim'] ?? 0)), 'claimed in open cases'],
                            ['bi-arrow-down-circle', 'green', 'Recovered', 'AED ' . lw_aed((float)($summary['total_recovered'] ?? 0)), 'of AED ' . lw_aed((float)($summary['total_claim'] ?? 0)) . ' claimed'],
                            ['bi-receipt', 'slate', 'Legal costs', 'AED ' . lw_aed((float)($costSummary['total_cost'] ?? 0)), 'AED ' . lw_aed((float)($costSummary['recovered_cost'] ?? 0)) . ' recovered'],
                        ] as [$icon, $tone, $label, $big, $sub]): ?>
                            <div class="col-6">
                                <div class="lr-mini lw-tone-<?= $tone ?>">
                                    <span class="lw-sq lw-sq-sm"><i class="bi <?= $icon ?>"></i></span>
                                    <div class="min-w-0">
                                        <div class="lw-muted"><?= h($label) ?></div>
                                        <div class="lr-mini-num"><?= h($big) ?></div>
                                        <div class="lw-muted text-truncate"><?= h($sub) ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($byStatus): ?>
                        <div class="lr-sub-h">By status</div>
                        <div class="lw-chips mb-3">
                            <?php foreach ($byStatus as $s): ?>
                                <span class="lw-chip"><?= h(legal_case_statuses()[$s['status']] ?? $s['status']) ?> <span class="lw-count"><?= (int)$s['cnt'] ?></span></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($byType): ?>
                        <div class="lr-sub-h">By type</div>
                        <table class="table table-sm lr-table mb-3"><tbody>
                            <?php foreach ($byType as $t): ?>
                                <tr><td><?= h(legal_case_types()[$t['case_type']] ?? $t['case_type']) ?></td><td class="text-end"><?= (int)$t['cnt'] ?> case<?= $t['cnt'] == 1 ? '' : 's' ?></td><td class="text-end lw-money">AED <?= number_format((float)$t['claim']) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody></table>
                    <?php endif; ?>
                    <?php if ($topCosts): ?>
                        <div class="lr-sub-h">Top cases by legal cost</div>
                        <table class="table table-sm lr-table mb-0"><tbody>
                            <?php foreach ($topCosts as $t): ?>
                                <tr><td><a href="legal_case_view.php?id=<?= (int)$t['id'] ?>"><?= h($t['case_number']) ?></a></td><td class="text-truncate" style="max-width:200px"><?= h($t['title']) ?></td><td class="text-end lw-money">AED <?= number_format((float)$t['total_cost'], 2) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody></table>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <div class="lw-muted lr-foot">
        Bounced = cheque bounced or returned, not replaced, settled or fully collected. Overdue = cheque date has passed and the cheque is not cleared or fully collected.
        Collected = receipts linked to the cheque. Test buildings excluded. Printed <?= h(date('j M Y, H:i')) ?>.
    </div>
</div>

<?php
$pageScripts = '<script>window.LR_CHARTS = ' . json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . <<<'JS'
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const form = document.getElementById('lrFilter');
    const sel = document.getElementById('lrBuilding');
    if (window.jQuery && jQuery.fn.select2) {
        jQuery(sel).select2({theme: 'bootstrap-5', width: '100%', placeholder: 'All buildings', allowClear: true})
            .on('change', () => form.submit());
    } else {
        sel.addEventListener('change', () => form.submit());
    }
    document.getElementById('lrPrint').addEventListener('click', () => window.print());

    // CSV of any table: data-csv on a cell wins over its text; data-csv-skip cells are left out.
    document.querySelectorAll('[data-csv-table]').forEach(btn => btn.addEventListener('click', () => {
        const t = document.getElementById(btn.dataset.csvTable);
        if (!t) return;
        const esc = v => { v = v == null ? '' : String(v).replace(/\s+/g, ' ').trim(); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
        const lines = Array.from(t.querySelectorAll('tr')).map(tr =>
            Array.from(tr.children).filter(td => !td.hasAttribute('data-csv-skip'))
                .map(td => esc(td.hasAttribute('data-csv') ? td.dataset.csv : td.textContent)).join(','));
        const blob = new Blob(['﻿' + lines.join('\n')], {type: 'text/csv;charset=utf-8'});
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = (t.dataset.csvName || 'report') + '-' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
        URL.revokeObjectURL(a.href);
    }));

    if (!window.Chart || !window.LR_CHARTS) return;
    const D = window.LR_CHARTS;
    const C = {blue: '#2a78d6', red: '#e34948', amber: '#eda100', ink: '#52514e', grid: '#eceef2'};
    const aed = v => 'AED ' + Math.round(v).toLocaleString('en-US');
    const short = v => v >= 1e6 ? (v / 1e6).toFixed(1) + 'M' : v >= 1e3 ? Math.round(v / 1e3) + 'k' : v;
    Chart.defaults.font.family = "'Plus Jakarta Sans', system-ui, sans-serif";
    Chart.defaults.color = C.ink;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 8;
    Chart.defaults.plugins.legend.labels.boxHeight = 8;
    Chart.defaults.plugins.tooltip.backgroundColor = '#111827';
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 8;
    const charts = [];
    const el = id => document.getElementById(id);
    const hBar = {borderRadius: 4, borderSkipped: false, borderColor: '#fff', borderWidth: 1, barPercentage: .75, categoryPercentage: .85, maxBarThickness: 22};

    if (el('chArrears')) charts.push(new Chart(el('chArrears'), {
        type: 'bar',
        data: {labels: D.arrears.labels, datasets: [
            {label: 'Bounced', data: D.arrears.bounced, backgroundColor: C.red, ...hBar},
            {label: 'Overdue', data: D.arrears.overdue, backgroundColor: C.amber, ...hBar},
        ]},
        options: {
            indexAxis: 'y', maintainAspectRatio: false,
            interaction: {mode: 'index', intersect: false},
            plugins: {legend: {display: false},
                      tooltip: {callbacks: {label: c => ' ' + c.dataset.label + ': ' + aed(c.parsed.x),
                                            footer: it => 'Total: ' + aed(D.arrears.bounced[it[0].dataIndex] + D.arrears.overdue[it[0].dataIndex])}}},
            scales: {x: {stacked: true, beginAtZero: true, grid: {color: C.grid}, border: {display: false}, ticks: {callback: short}},
                     y: {stacked: true, grid: {display: false}, border: {display: false}, ticks: {autoSkip: false, font: {size: 11}, callback: function (v) { const s = this.getLabelForValue(v); return s.length > 24 ? s.slice(0, 23) + '…' : s; }}}},
        },
    }));

    if (el('chAgeing')) charts.push(new Chart(el('chAgeing'), {
        type: 'bar',
        data: {labels: D.ageing.labels, datasets: [
            {label: 'Owed', data: D.ageing.total, backgroundColor: D.ageing.colors, borderRadius: 8, borderSkipped: false, maxBarThickness: 56},
        ]},
        options: {
            maintainAspectRatio: false, layout: {padding: {left: 8}},
            plugins: {legend: {display: false},
                      tooltip: {callbacks: {label: c => ' ' + aed(c.parsed.y) + ' · ' + D.ageing.count[c.dataIndex] + ' cheques',
                                            afterLabel: c => ' Bounced ' + aed(D.ageing.bounced[c.dataIndex]) + ' · overdue ' + aed(D.ageing.overdue[c.dataIndex])}}},
            scales: {x: {grid: {display: false}, border: {display: false}},
                     y: {beginAtZero: true, grid: {color: C.grid}, border: {display: false}, ticks: {callback: short}}},
        },
    }));

    if (el('chMonthly')) {
        const line = {borderWidth: 2.5, tension: .35, cubicInterpolationMode: 'monotone', pointRadius: 4, pointHoverRadius: 7,
                      pointBackgroundColor: '#fff', pointBorderWidth: 2.5};
        charts.push(new Chart(el('chMonthly'), {
            type: 'line',
            data: {labels: D.monthly.labels, datasets: [
                {label: 'Bounced / returned', key: 'bounced', data: D.monthly.bounced, borderColor: C.red, pointBorderColor: C.red, backgroundColor: C.red, ...line},
                {label: 'Still unpaid', key: 'unpaid', data: D.monthly.unpaid, borderColor: C.amber, pointBorderColor: C.amber, backgroundColor: C.amber, ...line},
            ]},
            options: {
                maintainAspectRatio: false,
                interaction: {mode: 'index', intersect: false},
                plugins: {legend: {position: 'top', align: 'end'},
                          tooltip: {callbacks: {
                              label: c => ' ' + c.dataset.label + ': ' + c.parsed.y + ' cheques · ' + aed(D.monthly[c.dataset.key + '_amt'][c.dataIndex]),
                              footer: it => {
                                  const i = it[0].dataIndex, p = D.monthly.paid[i], t = p + D.monthly.bounced[i] + D.monthly.unpaid[i];
                                  return t ? 'Cleared: ' + p + ' of ' + t + ' (' + Math.floor(p / t * 100) + '%)' : '';
                              }}}},
                scales: {x: {grid: {display: false}, border: {display: false}},
                         y: {beginAtZero: true, grid: {color: C.grid}, border: {display: false}, ticks: {precision: 0}}},
            },
        }));
    }

    if (el('chColl')) charts.push(new Chart(el('chColl'), {
        type: 'bar',
        data: {labels: D.coll.labels, datasets: [{label: '% cleared', data: D.coll.rate, backgroundColor: C.blue, ...hBar}]},
        options: {
            indexAxis: 'y', maintainAspectRatio: false,
            plugins: {legend: {display: false},
                      tooltip: {callbacks: {label: c => ' ' + c.parsed.x.toFixed(1) + '% cleared · ' + D.coll.paid[c.dataIndex] + ' of ' + D.coll.due[c.dataIndex] + ' cheques'}}},
            scales: {x: {min: 0, max: 100, grid: {color: C.grid}, border: {display: false}, ticks: {callback: v => v + '%'}},
                     y: {grid: {display: false}, border: {display: false}, ticks: {autoSkip: false, font: {size: 11}, callback: function (v) { const s = this.getLabelForValue(v); return s.length > 24 ? s.slice(0, 23) + '…' : s; }}}},
        },
    }));

    // Re-fit charts to the paper width when printing.
    window.addEventListener('beforeprint', () => charts.forEach(c => c.resize()));
    window.addEventListener('afterprint', () => charts.forEach(c => c.resize()));
})();
</script>
JS;
require_once __DIR__ . '/includes/legal_layout_footer.php';
?>
