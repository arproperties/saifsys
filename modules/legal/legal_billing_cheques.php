<?php
/**
 * Legal Department — Post-Dated Cheques (read-only, legal-focused).
 *
 * Every cheque on a lease in the Lease Watch scope (company, lease not deleted,
 * test buildings hidden, cancelled cheques left out) with its position:
 *
 *   cleared  — status cleared/paid/deposited, or receipts cover the cheque
 *   bounced  — bounced/returned, not replaced, not settled, not fully collected
 *   settled  — bounced/returned but replaced or settled another way
 *   overdue  — cheque date has passed and it is not paid (no grace period)
 *   due      — unpaid, dated today … +14 days
 *   pending  — unpaid, dated after that
 *
 * "Collected" = re_payments.cheque_id + re_receipt_cheque_links, exactly as
 * includes/legal_lease_watch.php counts it, so the totals here agree with the
 * Legal Dashboard. The list is rendered client-side from compact JSON.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
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
$currentCompanyId = (int)(current_company_id($conn) ?: 1);
$re = legal_re_path();

$pdcError = null;
$today = date('Y-m-d');
$R = [];        // cheque rows (compact arrays)
$L = [];        // leases
$T = [];        // tenants
$B = [];        // buildings
$banks = [];

try {
    $today = (string)$conn->query('SELECT CURDATE()')->fetchColumn();

    $hasLinks = false;
    try {
        $hasLinks = (bool)$conn->query("SHOW TABLES LIKE 're_receipt_cheque_links'")->fetchColumn();
    } catch (Throwable $e) {
        $hasLinks = false;
    }
    $collSql = "SELECT p.cheque_id, p.amount AS amt, p.payment_date AS pd FROM re_payments p
                WHERE p.company_id = :c1 AND p.cheque_id IS NOT NULL";
    if ($hasLinks) {
        $collSql .= " UNION ALL SELECT k.cheque_id, k.amount_applied AS amt, kp.payment_date AS pd
                      FROM re_receipt_cheque_links k LEFT JOIN re_payments kp ON kp.id = k.payment_id
                      WHERE k.company_id = :c2";
    }

    $sql = "
        SELECT c.id, c.lease_id, c.cheque_number, c.cheque_date, c.cheque_amount, c.status, c.bank_name,
               c.bounced_date, c.cleared_date, c.deposited_date, c.replaced_by_cheque_id, c.settlement_payment_id,
               COALESCE(col.got, 0) AS got_raw, col.last_paid,
               l.lease_number, l.status AS lease_status, l.end_date, l.tenant_id,
               u.id AS unit_id, u.unit_number, b.id AS building_id, b.name AS building_name,
               t.first_name, t.last_name, t.company_name, t.tenant_type, t.phone
        FROM re_post_dated_cheques c
        JOIN re_leases l    ON l.id = c.lease_id
        JOIN re_units u     ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        JOIN re_tenants t   ON t.id = l.tenant_id
        LEFT JOIN (SELECT cheque_id, SUM(amt) AS got, MAX(pd) AS last_paid FROM ({$collSql}) x GROUP BY cheque_id) col
               ON col.cheque_id = c.id
        WHERE c.company_id = :c3 AND c.status <> 'cancelled'
          AND l.company_id = :c4
          AND " . re_lease_not_deleted_sql($conn, 'l') . "
          AND b.name NOT LIKE '%test building%'
        ORDER BY c.cheque_date DESC, c.id DESC
    ";
    $params = [':c1' => $currentCompanyId, ':c3' => $currentCompanyId, ':c4' => $currentCompanyId];
    if ($hasLinks) {
        $params[':c2'] = $currentCompanyId;
    }
    $st = $conn->prepare($sql);
    $st->execute($params);

    // Legal cases already opened against a cheque.
    $caseByCheque = [];
    try {
        $cs = $conn->prepare("SELECT primary_cheque_id, MAX(id) FROM re_legal_cases
                              WHERE company_id = ? AND deleted_at IS NULL AND primary_cheque_id IS NOT NULL
                              GROUP BY primary_cheque_id");
        $cs->execute([$currentCompanyId]);
        $caseByCheque = $cs->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) { /* optional */ }

    $todayTs = strtotime($today);
    $soon = date('Y-m-d', strtotime($today . ' +' . LEGAL_WATCH_DUE_SOON_DAYS . ' days'));

    while ($c = $st->fetch(PDO::FETCH_ASSOC)) {
        $amt   = (float)$c['cheque_amount'];
        $got   = min((float)$c['got_raw'], $amt);
        $paid  = in_array($c['status'], ['cleared', 'paid', 'deposited'], true) || (float)$c['got_raw'] >= $amt - 0.005;
        $bad   = in_array($c['status'], ['bounced', 'returned'], true);
        $settl = !empty($c['replaced_by_cheque_id']) || !empty($c['settlement_payment_id']);
        $date  = (string)$c['cheque_date'];

        if ($paid)                 $state = 'cleared';
        elseif ($bad && $settl)    $state = 'settled';
        elseif ($bad)              $state = 'bounced';
        elseif ($date < $today)    $state = 'overdue';
        elseif ($date <= $soon)    $state = 'due';
        else                       $state = 'pending';

        $ref  = $state === 'bounced' && $c['bounced_date'] ? $c['bounced_date'] : $date;
        $days = (int)round(((strtotime($ref) ?: $todayTs) - $todayTs) / 86400);
        $paidDate = $paid ? ($c['cleared_date'] ?: ($c['last_paid'] ?: ($c['deposited_date'] ?: ''))) : '';
        $bank = trim((string)$c['bank_name']);
        if ($bank !== '') $banks[$bank] = true;

        $lid = (int)$c['lease_id'];
        $tid = (int)$c['tenant_id'];
        $bid = (int)$c['building_id'];
        if (!isset($L[$lid])) {
            $L[$lid] = [(string)$c['lease_number'], (string)$c['lease_status'], $tid, $bid, (int)$c['unit_id'], (string)$c['unit_number'], (string)$c['end_date']];
        }
        if (!isset($T[$tid])) {
            $name = legal_tenant_name($c + ['id' => $tid]);
            $T[$tid] = [$name, (string)$c['phone'], lw_initials($name), lw_avatar_tone($name)];
        }
        $B[$bid] = (string)$c['building_name'];

        $R[] = [
            (int)$c['id'], (string)$c['cheque_number'], $date, round($amt, 2), round($got, 2), $state,
            (string)$c['status'], $bank, $lid, $days, (string)$paidDate, (string)($c['bounced_date'] ?? ''),
            (int)($c['replaced_by_cheque_id'] ?? 0), (int)($caseByCheque[$c['id']] ?? 0),
        ];
    }
} catch (Throwable $e) {
    $pdcError = $e->getMessage();
}
asort($B);
$banks = array_keys($banks);
natcasesort($banks);
$banks = array_values($banks);

// ---- Strip totals (same definitions the JS filters use) ----
$monthStart = substr($today, 0, 7) . '-01';
$tile = fn() => ['n' => 0, 'amt' => 0.0];
$S = ['pending' => $tile(), 'due' => $tile(), 'overdue' => $tile(), 'bounced' => $tile(), 'held' => $tile(), 'cleared_month' => $tile()];
$byBank = [];
$byBld = [];
$in90 = date('Y-m-d', strtotime($today . ' +90 days'));
$weeks = [];
for ($w = strtotime('monday this week', strtotime($today)); $w <= strtotime($in90); $w += 7 * 86400) {
    $weeks[date('Y-m-d', $w)] = ['amt' => 0.0, 'n' => 0];
}
foreach ($R as $r) {
    [$id, , $date, $amt, $got, $state, $status, $bank] = $r;
    $owed = max(0, $amt - $got);
    if ($state === 'due' || $state === 'pending') { $S['pending']['n']++; $S['pending']['amt'] += $owed; }
    if ($state === 'due')     { $S['due']['n']++;     $S['due']['amt'] += $owed; }
    if ($state === 'overdue') { $S['overdue']['n']++; $S['overdue']['amt'] += $owed; }
    if ($state === 'bounced') { $S['bounced']['n']++; $S['bounced']['amt'] += $owed; }
    if ($status === 'held_by_finance' && $state !== 'cleared') { $S['held']['n']++; $S['held']['amt'] += $owed; }
    if ($state === 'cleared' && $r[10] !== '' && $r[10] >= $monthStart && $r[10] <= $today) {
        $S['cleared_month']['n']++; $S['cleared_month']['amt'] += $amt;
    }
    if ($state === 'overdue' || $state === 'bounced') {
        $bk = $bank !== '' ? $bank : '';
        $byBank[$bk] ??= ['bounced' => 0.0, 'overdue' => 0.0, 'n' => 0];
        $byBank[$bk][$state] += $owed;
        $byBank[$bk]['n']++;
        $bd = (int)($L[$r[8]][3] ?? 0);
        $byBld[$bd] ??= ['bounced' => 0.0, 'overdue' => 0.0, 'n' => 0];
        $byBld[$bd][$state] += $owed;
        $byBld[$bd]['n']++;
    }
    if (($state === 'due' || $state === 'pending') && $date <= $in90) {
        $wk = date('Y-m-d', strtotime('monday this week', strtotime($date)));
        if (isset($weeks[$wk])) { $weeks[$wk]['amt'] += $owed; $weeks[$wk]['n']++; }
    }
}
uasort($byBank, fn($a, $b) => ($b['bounced'] + $b['overdue']) <=> ($a['bounced'] + $a['overdue']));
uasort($byBld, fn($a, $b) => ($b['bounced'] + $b['overdue']) <=> ($a['bounced'] + $a['overdue']));
$bars = [
    'building' => ['Where are the<br>problem cheques?', 'By building', array_slice($byBld, 0, 7, true), 'data-building-go', fn($k) => $B[$k] ?? 'Building #' . $k],
    'bank'     => ['Problem cheques<br>by bank', 'By bank', array_slice($byBank, 0, 7, true), 'data-bank-go', fn($k) => $k !== '' ? (string)$k : 'No bank recorded'],
];
$problemTotal = array_sum(array_map(fn($b) => $b['bounced'] + $b['overdue'], $byBank));
$pipe90 = array_sum(array_column($weeks, 'amt'));
$pipe90n = array_sum(array_column($weeks, 'n'));

$strip = [
    ['pending', 'sky', 'bi-hourglass-split', 'Pending · not yet due', $S['pending'], 'unpaid · dated today or later'],
    ['due', 'violet', 'bi-calendar2-week-fill', 'Due in 14 days', $S['due'], 'unpaid · chase before they bounce'],
    ['overdue', 'amber', 'bi-alarm-fill', 'Overdue', $S['overdue'], 'unpaid · date has passed'],
    ['bounced', 'rose', 'bi-x-octagon-fill', 'Bounced / returned', $S['bounced'], 'unpaid · not replaced or settled'],
    ['held', 'navy', 'bi-pause-circle-fill', 'Held by finance', $S['held'], 'unpaid · not presented'],
    ['cleared_month', 'green', 'bi-check2-circle', 'Cleared this month', $S['cleared_month'], 'cleared in ' . date('F', strtotime($today))],
];

$chart = [
    'labels' => array_map(fn($k) => date('j M', strtotime($k)), array_keys($weeks)),
    'from'   => array_keys($weeks),
    'amt'    => array_map(fn($w) => round($w['amt']), array_values($weeks)),
    'n'      => array_column(array_values($weeks), 'n'),
];

$payload = ['today' => $today, 'soon' => date('Y-m-d', strtotime($today . ' +' . LEGAL_WATCH_DUE_SOON_DAYS . ' days')),
            'month' => $monthStart, 'in90' => $in90, 'R' => $R, 'L' => $L, 'T' => $T, 'B' => $B, 'chart' => $chart];

$pageTitle = 'Post-Dated Cheques';
$pageHead = '
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<link href="assets/legal-pdc.css?v=' . (@filemtime(__DIR__ . '/assets/legal-pdc.css') ?: 1) . '" rel="stylesheet">';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
<div class="lw-page pdc-page">

    <div class="pdc-head">
        <div class="min-w-0">
            <h1>Post-Dated Cheques</h1>
            <span class="lw-muted"><?= number_format(count($R)) ?> cheques · <?= number_format(count($L)) ?> leases · <?= h(date('j M Y', strtotime($today))) ?></span>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn lw-btn-soft" data-bs-toggle="modal" data-bs-target="#pdcInsights"><i class="bi bi-graph-up"></i> Insights</button>
            <button type="button" class="btn lw-btn-grad" id="pdcExport"><i class="bi bi-download"></i> Export CSV</button>
        </div>
    </div>

    <?php if ($pdcError): ?>
        <div class="alert alert-danger py-2">Cheque data could not be loaded: <?= h($pdcError) ?></div>
    <?php endif; ?>

    <div class="lw-strip pdc-strip" id="pdcStrip">
        <?php foreach ($strip as [$key, $tone, $icon, $label, $v, $sub]): ?>
            <div class="lw-alert lw-seg lw-st-<?= h($tone) ?>" data-filter="<?= h($key) ?>" title="<?= h($label . ': ' . $v['n'] . ' cheques, AED ' . number_format($v['amt'], 2) . ' — ' . $sub) ?>">
                <button type="button" class="lw-alert-btn" data-filter="<?= h($key) ?>" aria-label="<?= h($label) ?>: show these cheques"></button>
                <span class="lw-seg-ico"><i class="bi <?= h($icon) ?>"></i></span>
                <div class="lw-seg-body">
                    <div class="lw-seg-label"><?= h($label) ?></div>
                    <div class="lw-seg-big"><?= number_format($v['n']) ?> <small>AED <?= h(lw_aed($v['amt'])) ?></small></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Cheque list -->
    <div class="lw-panel pdc-list" id="pdcList">
        <div class="pdc-bar">
            <div class="pdc-f-status">
                <select id="pdcStatus" class="form-select form-select-sm" aria-label="Show"></select>
            </div>
            <div class="pdc-f-sel">
                <select id="pdcBuilding" class="form-select form-select-sm" aria-label="Building">
                    <option value="">All buildings</option>
                    <?php foreach ($B as $bid => $bname): ?>
                        <option value="<?= (int)$bid ?>"><?= h($bname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pdc-f-sel">
                <select id="pdcBank" class="form-select form-select-sm" aria-label="Bank">
                    <option value="">All banks</option>
                    <?php foreach ($banks as $bk): ?>
                        <option value="<?= h($bk) ?>"><?= h($bk) ?></option>
                    <?php endforeach; ?>
                    <option value="__none__">No bank recorded</option>
                </select>
            </div>
            <div class="pdc-f-date">
                <input type="date" id="pdcFrom" class="form-control form-control-sm" aria-label="Cheque date from" title="Cheque date from">
                <span class="lw-muted">–</span>
                <input type="date" id="pdcTo" class="form-control form-control-sm" aria-label="Cheque date to" title="Cheque date to">
            </div>
            <div class="lw-search-box pdc-f-search">
                <i class="bi bi-search"></i>
                <input type="search" id="pdcSearch" placeholder="Cheque, tenant, lease, unit">
            </div>
        </div>
        <div class="pdc-stats">
            <span id="pdcStats"></span>
            <a href="#" id="pdcReset" class="d-none">Reset filters</a>
            <i class="bi bi-info-circle ms-auto lw-muted" tabindex="0" title="Overdue = cheque date passed and not cleared or fully collected (no grace period). Bounced = bounced/returned, not replaced, not settled, not fully collected. Collected = receipts linked to the cheque. Cancelled cheques, deleted leases and test buildings are left out."></i>
        </div>

        <div class="table-responsive">
            <table class="table lw-table pdc-table" id="pdcTable">
                <thead><tr>
                    <th><button type="button" class="pdc-sort" data-sort="no">Cheque</button></th>
                    <th><button type="button" class="pdc-sort" data-sort="date">Date</button></th>
                    <th class="text-end"><button type="button" class="pdc-sort" data-sort="amt">Amount</button></th>
                    <th>Status</th>
                    <th>Tenant</th>
                    <th>Unit · lease</th>
                    <th>Paid</th>
                    <th><button type="button" class="pdc-sort" data-sort="days">Days</button></th>
                    <th class="text-end"></th>
                </tr></thead>
                <tbody id="pdcBody"></tbody>
            </table>
            <div class="lw-empty-state d-none" id="pdcEmpty"><i class="bi bi-check2-circle"></i>No cheques match these filters.</div>
        </div>
        <div class="pdc-foot">
            <span id="pdcShown" class="lw-muted"></span>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="lw-detail-btn" id="pdcPrev" aria-label="Previous page"><i class="bi bi-chevron-left"></i></button>
                <span class="lw-muted" id="pdcPage"></span>
                <button type="button" class="lw-detail-btn" id="pdcNext" aria-label="Next page"><i class="bi bi-chevron-right"></i></button>
            </div>
        </div>
    </div>

    <!-- Insights pop-up: 90-day pipeline + problem cheques by building and bank -->
    <div class="modal fade" id="pdcInsights" tabindex="-1" aria-labelledby="pdcInsTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content lw-page lw-modal">
        <div class="lw-mhead">
            <div class="d-flex align-items-center gap-3 min-w-0">
                <span class="lw-mhead-ico"><i class="bi bi-graph-up"></i></span>
                <div class="min-w-0">
                    <h2 id="pdcInsTitle">Cheque insights</h2>
                    <div class="lw-mhead-stats">AED <?= h(lw_aed($pipe90)) ?> due in the next 90 days (<?= number_format($pipe90n) ?> cheques) · AED <?= h(lw_aed($problemTotal)) ?> bounced or overdue</div>
                </div>
            </div>
            <button type="button" class="lw-glass-btn lw-glass-x" data-bs-dismiss="modal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="p-3">
            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="lw-panel h-100">
                        <div class="lw-panel-head"><div><h2>Coming due — next 90 days</h2><div class="lw-muted">Unpaid cheques per week (AED). Click a bar to list them.</div></div></div>
                        <div class="lw-chart-box" style="height:260px"><canvas id="pdcChart" aria-label="AED of unpaid cheques due per week for the next 90 days"></canvas></div>
                    </div>
                </div>
                <?php foreach ($bars as $bk => [$title, $pill, $list, $attr, $nameFn]):
                    $max = 0.0;
                    foreach ($list as $b) $max = max($max, $b['bounced'] + $b['overdue']); ?>
                <div class="col-lg-3 col-md-6">
                    <div class="lw-panel h-100">
                        <div class="lw-panel-head"><div><h2><?= h($pill) ?></h2><div class="lw-muted">Bounced + overdue, AED unpaid</div></div></div>
                        <?php if (!$list): ?>
                            <div class="lw-empty-state"><i class="bi bi-check2-circle"></i>No bounced or overdue cheques.</div>
                        <?php endif; ?>
                        <?php foreach ($list as $key => $b):
                            $tot = $b['bounced'] + $b['overdue'];
                            $wb = $max > 0 ? $b['bounced'] / $max * 100 : 0;
                            $wo = $max > 0 ? $b['overdue'] / $max * 100 : 0;
                            $nm = $nameFn($key); ?>
                            <button type="button" class="lw-hbar" <?= $attr ?>="<?= h((string)$key) ?>"
                                    title="<?= h($nm . ' — ' . $b['n'] . ' cheques: bounced AED ' . number_format($b['bounced']) . ', overdue AED ' . number_format($b['overdue'])) ?>">
                                <span class="lw-hbar-top">
                                    <span class="lw-hbar-name"><?= h($nm) ?> <span class="lw-muted">· <?= (int)$b['n'] ?></span></span>
                                    <span class="lw-hbar-amt">AED <?= h(lw_aed($tot)) ?></span>
                                </span>
                                <span class="lw-hbar-track">
                                    <?php if ($wb > 0): ?><span class="lw-hbar-seg b" style="width:<?= max(2, round($wb, 1)) ?>%"></span><?php endif; ?>
                                    <?php if ($wo > 0): ?><span class="lw-hbar-seg o" style="width:<?= max(2, round($wo, 1)) ?>%"></span><?php endif; ?>
                                </span>
                            </button>
                        <?php endforeach; ?>
                        <div class="lw-legend mt-2"><span><i style="background:#e34948"></i>Bounced</span><span><i style="background:#eda100"></i>Overdue</span></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    </div>
    </div>

</div>

<?php
$pageScripts = '<script>window.PDC = ' . json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ';</script>' . <<<'JS'
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const P = window.PDC;
    if (!P) return;
    const RE = '../realestate/';
    // Row layout: 0 id, 1 no, 2 date, 3 amt, 4 got, 5 state, 6 status, 7 bank, 8 lease, 9 days, 10 paid date, 11 bounced date, 12 replaced by, 13 case id
    const rows = P.R.map(r => {
        const l = P.L[r[8]] || ['', '', 0, 0, 0, '', ''];
        const t = P.T[l[2]] || ['', '', '?', 0];
        return {r, l, t, owed: Math.max(0, r[3] - r[4]),
                s: [r[1], t[0], t[1], l[0], l[5], P.B[l[3]] || '', r[7]].join(' ').toLowerCase()};
    });

    const FILTERS = {
        attention:     ['Needs action', x => ['bounced', 'overdue', 'due'].includes(x.r[5]) || isHeld(x)],
        pending:       ['Pending · not yet due', x => x.r[5] === 'due' || x.r[5] === 'pending'],
        due:           ['Due in 14 days', x => x.r[5] === 'due'],
        overdue:       ['Overdue', x => x.r[5] === 'overdue'],
        bounced:       ['Bounced / returned', x => x.r[5] === 'bounced'],
        problem:       ['Bounced or overdue', x => x.r[5] === 'bounced' || x.r[5] === 'overdue'],
        held:          ['Held by finance', isHeld],
        escalated:     ['Legal escalated', x => x.r[6] === 'legal_escalated' && x.r[5] !== 'cleared'],
        settled:       ['Bounced, replaced or settled', x => x.r[5] === 'settled'],
        cleared_month: ['Cleared this month', x => x.r[5] === 'cleared' && x.r[10] >= P.month && x.r[10] <= P.today],
        cleared:       ['All cleared', x => x.r[5] === 'cleared'],
        all:           ['All cheques', () => true],
    };
    function isHeld(x) { return x.r[6] === 'held_by_finance' && x.r[5] !== 'cleared'; }
    const UNPAID_FILTERS = ['attention', 'pending', 'due', 'overdue', 'bounced', 'problem', 'held', 'escalated'];

    const STATE = {
        cleared: ['Cleared', 'pdc-st-cleared', 'bi-check-circle-fill'],
        bounced: ['Bounced', 'pdc-st-bounced', 'bi-x-octagon-fill'],
        settled: ['Settled', 'pdc-st-settled', 'bi-arrow-repeat'],
        overdue: ['Overdue', 'pdc-st-overdue', 'bi-alarm-fill'],
        due:     ['Due soon', 'pdc-st-due', 'bi-calendar-event'],
        pending: ['Pending', 'pdc-st-pending', 'bi-hourglass-split'],
    };

    const $ = id => document.getElementById(id);
    const body = $('pdcBody'), statusEl = $('pdcStatus'), search = $('pdcSearch'), from = $('pdcFrom'), to = $('pdcTo');
    const building = $('pdcBuilding'), bank = $('pdcBank');
    const hasS2 = !!(window.jQuery && jQuery.fn.select2);
    let PER = 15;
    let filter = 'attention', page = 0, sortKey = 'date', sortDir = 0, current = [];

    const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const fmt = v => Math.round(v).toLocaleString('en-US');
    const fmt2 = v => v.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const dshow = d => { if (!d) return ''; const p = d.split('-'); return +p[2] + ' ' + MON[+p[1] - 1] + ' ' + p[0].slice(2); };
    // UAE numbers → international digits for wa.me (05x… → 9715x…, 00… → …).
    const waNum = p => {
        let d = String(p || '').replace(/\D/g, '');
        if (d.startsWith('00')) d = d.slice(2);
        else if (d.startsWith('0')) d = '971' + d.slice(1);
        else if (d.length === 9 && d[0] === '5') d = '971' + d;
        return d.length >= 9 ? d : '';
    };
    const lwAed = v => v >= 1e6 ? (v / 1e6).toFixed(2) + 'M' : v >= 1e4 ? (v / 1e3).toFixed(1) + 'k' : fmt(v);

    // Status dropdown (searchable) — same filters the strip uses, with live counts.
    const ORDER = ['attention', 'pending', 'due', 'overdue', 'bounced', 'problem', 'held', 'escalated', 'settled', 'cleared_month', 'cleared', 'all'];
    statusEl.innerHTML = ORDER.map(k => '<option value="' + k + '">' + esc(FILTERS[k][0]) + '</option>').join('');

    // Everything except the status filter.
    function baseMatch(x) {
        const q = search.value.trim().toLowerCase();
        const b = building.value, bk = bank.value, f = from.value, t = to.value;
        if (b && String(x.l[3]) !== b) return false;
        if (bk && (bk === '__none__' ? x.r[7] !== '' : x.r[7] !== bk)) return false;
        if (f && x.r[2] < f) return false;
        if (t && x.r[2] > t) return false;
        if (q && !x.s.includes(q)) return false;
        return true;
    }

    function sortRows(list) {
        // Default: problems first (most days late), then soonest due, cleared last by newest.
        const rank = {bounced: 0, overdue: 1, due: 2, pending: 3, settled: 4, cleared: 5};
        if (sortDir === 0) {
            return list.sort((a, b) => {
                const ra = rank[a.r[5]], rb = rank[b.r[5]];
                if (ra !== rb) return ra - rb;
                if (ra <= 1) return a.r[9] - b.r[9];           // oldest problem first
                if (ra <= 3) return a.r[2] < b.r[2] ? -1 : a.r[2] > b.r[2] ? 1 : 0;
                return a.r[2] < b.r[2] ? 1 : a.r[2] > b.r[2] ? -1 : 0;
            });
        }
        const key = {no: x => x.r[1].toLowerCase(), date: x => x.r[2], amt: x => x.r[3], days: x => x.r[9]}[sortKey];
        return list.sort((a, b) => { const va = key(a), vb = key(b); return (va < vb ? -1 : va > vb ? 1 : 0) * sortDir; });
    }

    function renderStatus(base) {
        Array.from(statusEl.options).forEach(o => {
            o.textContent = FILTERS[o.value][0] + ' (' + base.filter(FILTERS[o.value][1]).length.toLocaleString('en-US') + ')';
        });
        statusEl.value = filter;
        if (hasS2) jQuery(statusEl).trigger('change.select2');
    }

    function rowHtml(x) {
        const r = x.r, l = x.l, t = x.t;
        const st = STATE[r[5]];
        let tags = '';
        if (r[6] === 'held_by_finance') tags += '<i class="bi bi-pause-circle-fill pdc-tag held" title="Held by finance"></i>';
        if (r[6] === 'legal_escalated') tags += '<i class="bi bi-bank2 pdc-tag legal" title="Escalated to legal"></i>';
        if (r[6] === 'returned' && r[5] !== 'cleared') tags += '<i class="bi bi-arrow-return-left pdc-tag" title="Returned"></i>';
        if (r[12]) tags += '<a class="pdc-tag" href="' + RE + 'billing_cheque_view.php?id=' + r[12] + '" title="Replaced by cheque #' + r[12] + '"><i class="bi bi-arrow-repeat"></i></a>';

        const pct = r[3] > 0 ? Math.max(0, Math.min(100, Math.round(r[4] / r[3] * 100))) : 0;
        const paid = r[5] === 'cleared' && r[4] <= 0
            ? '<span class="lw-muted" title="Cleared by bank">bank</span>'
            : '<span class="pdc-mini" title="Collected AED ' + fmt2(r[4]) + ' of ' + fmt2(r[3]) + '"><span style="width:' + pct + '%"></span></span><span class="pdc-pct">' + pct + '%</span>';

        let due;
        const d = r[9];
        if (r[5] === 'cleared') due = r[10] ? '<span class="lw-muted" title="Cleared ' + esc(dshow(r[10])) + '"><i class="bi bi-check2"></i> ' + esc(dshow(r[10])) + '</span>' : '<span class="lw-muted">—</span>';
        else if (r[5] === 'bounced') due = '<span class="pdc-days red" title="Days since ' + (r[11] ? 'bounce' : 'cheque date (no bounce date recorded)') + '">' + Math.abs(d) + 'd</span>';
        else if (r[5] === 'settled') due = '<span class="lw-muted">—</span>';
        else if (d < 0) due = '<span class="pdc-days amber" title="Days late">' + (-d) + 'd late</span>';
        else if (d === 0) due = '<span class="pdc-days sky">Today</span>';
        else due = '<span class="pdc-days ' + (r[5] === 'due' ? 'sky' : 'muted') + '">in ' + d + 'd</span>';

        let act = '<a class="btn btn-outline-secondary" href="' + RE + 'billing_cheque_view.php?id=' + r[0] + '" title="Open cheque"><i class="bi bi-eye"></i></a>'
                + '<a class="btn btn-outline-secondary" href="legal_documents.php?lease_id=' + r[8] + '&tenant_id=' + l[2] + '" title="Lease &amp; tenant documents"><i class="bi bi-folder2-open"></i></a>';
        if (r[13]) {
            act += '<a class="btn btn-outline-primary" href="legal_case_view.php?id=' + r[13] + '" title="Open linked legal case"><i class="bi bi-briefcase"></i></a>';
        } else if (r[5] === 'bounced' || r[5] === 'overdue') {
            const q = new URLSearchParams({
                case_type: r[5] === 'bounced' ? 'cheque_bounce' : 'rent_recovery', case_source: 'bounced_cheque',
                title: (r[5] === 'bounced' ? 'Bounced cheque recovery - ' : 'Overdue cheque recovery - ') + r[1],
                building_id: l[3], unit_id: l[4], tenant_id: l[2], lease_id: r[8], primary_cheque_id: r[0],
                claim_amount: x.owed.toFixed(2), priority: r[5] === 'bounced' ? 'high' : 'medium'});
            act += '<a class="btn btn-dark" href="legal_case_add.php?' + esc(q.toString()) + '" title="Escalate: open a legal case for this cheque" aria-label="Escalate to legal"><i class="bi bi-bank"></i></a>';
        }

        const partial = x.owed > 0.005 && x.owed < r[3] - 0.005 && r[5] !== 'cleared';
        const sev = r[5] === 'bounced' ? 3 : r[5] === 'overdue' ? 2 : r[5] === 'due' ? 1 : 0;
        const bld = P.B[l[3]] || '';
        return '<tr class="lw-sev-' + sev + '">'
            + '<td class="text-nowrap"><a class="pdc-no" href="' + RE + 'billing_cheque_view.php?id=' + r[0] + '" title="' + esc(r[7] || 'No bank recorded') + '">' + esc(r[1] || '#' + r[0]) + '</a></td>'
            + '<td class="text-nowrap">' + esc(dshow(r[2])) + '</td>'
            + '<td class="text-end text-nowrap"><span class="lw-money' + (r[5] === 'bounced' ? ' red' : r[5] === 'overdue' ? ' amber' : '') + '"'
            +   (partial ? ' title="AED ' + fmt2(x.owed) + ' still unpaid"' : '') + '>' + fmt2(r[3]) + '</span>'
            +   (partial ? '<span class="pdc-part" title="AED ' + fmt2(x.owed) + ' still unpaid">part</span>' : '') + '</td>'
            + '<td class="text-nowrap"><span class="pdc-st ' + st[1] + '"><i class="bi ' + st[2] + '"></i>' + st[0] + '</span>' + tags + '</td>'
            + '<td><div class="pdc-cell"><span class="lw-avatar lw-avatar-sm lw-av-' + t[3] + '">' + esc(t[2]) + '</span>'
            +   '<span class="pdc-trunc pdc-tenant" title="' + esc(t[0] + (t[1] ? ' · ' + t[1] : '')) + '">' + esc(t[0]) + '</span>'
            +   (waNum(t[1]) ? '<a class="pdc-wa" href="https://wa.me/' + waNum(t[1]) + '" target="_blank" rel="noopener" title="WhatsApp ' + esc(t[1]) + '"><i class="bi bi-whatsapp"></i>' + esc(t[1]) + '</a>' : '') + '</div></td>'
            + '<td><div class="pdc-cell"><a class="pdc-trunc pdc-unit" href="' + RE + 'lease_view.php?id=' + r[8] + '#installments" title="' + esc('Lease ' + l[0] + ' (' + l[1].replace(/_/g, ' ') + ') · ' + bld + ' · Unit ' + l[5]) + '">'
            +   esc(bld) + ' · ' + esc(l[5]) + '</a><span class="pdc-ldot ' + esc(l[1]) + '" title="Lease ' + esc(l[1].replace(/_/g, ' ')) + '"></span></div></td>'
            + '<td class="text-nowrap">' + paid + '</td>'
            + '<td class="text-nowrap">' + due + '</td>'
            + '<td class="text-end lw-actions text-nowrap">' + act + '</td></tr>';
    }

    function apply(keepPage) {
        const base = rows.filter(baseMatch);
        current = sortRows(base.filter(FILTERS[filter][1]));
        if (!keepPage) page = 0;
        const pages = Math.max(1, Math.ceil(current.length / PER));
        page = Math.min(page, pages - 1);
        const slice = current.slice(page * PER, page * PER + PER);
        body.innerHTML = slice.map(rowHtml).join('');
        $('pdcEmpty').classList.toggle('d-none', current.length > 0);
        renderStatus(base);

        // Figures = exactly the rows in the list.
        let face = 0, owed = 0;
        current.forEach(x => { face += x.r[3]; owed += x.owed; });
        let stat = '<b>' + current.length.toLocaleString('en-US') + '</b> cheque' + (current.length === 1 ? '' : 's') + ' · AED ' + fmt(face) + ' face value';
        if (UNPAID_FILTERS.includes(filter) || owed > 0.005) stat += ' · <b class="pdc-owed">AED ' + fmt(owed) + ' unpaid</b>';
        $('pdcStats').innerHTML = stat;
        $('pdcReset').classList.toggle('d-none', !(filter !== 'attention' || building.value || bank.value || from.value || to.value || search.value));

        const a = current.length ? page * PER + 1 : 0, b = Math.min(current.length, page * PER + PER);
        $('pdcShown').textContent = a + '–' + b + ' of ' + current.length.toLocaleString('en-US');
        $('pdcPage').textContent = (page + 1) + ' / ' + pages;
        $('pdcPrev').disabled = page === 0;
        $('pdcNext').disabled = page >= pages - 1;
        document.querySelectorAll('#pdcStrip .lw-seg').forEach(s => s.classList.toggle('is-active', s.dataset.filter === filter));
        document.querySelectorAll('.pdc-sort').forEach(s => {
            s.classList.toggle('is-asc', sortDir === 1 && s.dataset.sort === sortKey);
            s.classList.toggle('is-desc', sortDir === -1 && s.dataset.sort === sortKey);
        });
    }

    // Rows per page = what fits in the window, so the page doesn't scroll.
    function fit() {
        const tr = body.querySelector('tr');
        const rowH = tr ? tr.getBoundingClientRect().height : 44;
        const top = body.getBoundingClientRect().top + window.scrollY;
        const foot = document.querySelector('.pdc-foot').getBoundingClientRect().height + 28;
        const n = Math.max(6, Math.min(50, Math.floor((window.innerHeight - top - foot) / rowH)));
        if (n !== PER) { PER = n; apply(true); }
    }

    function setSel(el, v) { el.value = v; if (hasS2) jQuery(el).trigger('change.select2'); }
    function clearOthers() { search.value = ''; from.value = ''; to.value = ''; setSel(building, ''); setSel(bank, ''); }
    function reset() { filter = 'attention'; sortDir = 0; clearOthers(); apply(); }
    const insEl = $('pdcInsights');
    function closeInsights() { if (window.bootstrap && insEl.classList.contains('show')) bootstrap.Modal.getOrCreateInstance(insEl).hide(); }

    $('pdcReset').addEventListener('click', e => { e.preventDefault(); reset(); });
    // Strip: show exactly what the tile counts (other filters cleared).
    document.querySelectorAll('#pdcStrip .lw-alert-btn').forEach(b => b.addEventListener('click', () => {
        filter = b.dataset.filter; clearOthers(); apply();
    }));
    document.querySelectorAll('[data-building-go]').forEach(b => b.addEventListener('click', () => {
        filter = 'problem'; clearOthers(); setSel(building, b.dataset.buildingGo); apply(); closeInsights();
    }));
    document.querySelectorAll('[data-bank-go]').forEach(b => b.addEventListener('click', () => {
        filter = 'problem'; clearOthers(); setSel(bank, b.dataset.bankGo === '' ? '__none__' : b.dataset.bankGo); apply(); closeInsights();
    }));
    let t0;
    search.addEventListener('input', () => { clearTimeout(t0); t0 = setTimeout(() => apply(), 150); });
    search.addEventListener('keydown', e => { if (e.key === 'Escape') { search.value = ''; apply(); } });
    [from, to].forEach(el => el.addEventListener('change', () => apply()));
    $('pdcPrev').addEventListener('click', () => { page--; apply(true); });
    $('pdcNext').addEventListener('click', () => { page++; apply(true); });
    document.querySelectorAll('.pdc-sort').forEach(s => s.addEventListener('click', () => {
        if (sortKey !== s.dataset.sort) { sortKey = s.dataset.sort; sortDir = 1; }
        else sortDir = sortDir === 1 ? -1 : sortDir === -1 ? 0 : 1;
        apply();
    }));

    const onStatus = () => { if (statusEl.value && statusEl.value !== filter) { filter = statusEl.value; apply(); } };
    if (hasS2) {
        jQuery(statusEl).select2({theme: 'bootstrap-5', width: '100%'}).on('change', onStatus);
        jQuery(building).select2({theme: 'bootstrap-5', width: '100%', placeholder: 'All buildings', allowClear: true}).on('change', () => apply());
        jQuery(bank).select2({theme: 'bootstrap-5', width: '100%', placeholder: 'All banks', allowClear: true}).on('change', () => apply());
    } else {
        statusEl.addEventListener('change', onStatus);
        building.addEventListener('change', () => apply());
        bank.addEventListener('change', () => apply());
    }

    function exportCsv() {
        const head = ['Cheque no', 'Cheque date', 'Amount AED', 'Collected AED', 'Unpaid AED', 'Position', 'PDC status', 'Bank',
                      'Tenant', 'Phone', 'Lease', 'Lease status', 'Lease end', 'Building', 'Unit', 'Days (+ to due / - late)', 'Cleared/paid date', 'Bounced date', 'Replaced by cheque'];
        const q = v => { v = v == null ? '' : String(v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
        const lines = [head.map(q).join(',')];
        current.forEach(x => {
            const r = x.r, l = x.l, t = x.t;
            lines.push([r[1], r[2], r[3].toFixed(2), r[4].toFixed(2), x.owed.toFixed(2), STATE[r[5]][0], r[6], r[7],
                        t[0], t[1], l[0], l[1], l[6], P.B[l[3]] || '', l[5], r[5] === 'cleared' ? '' : r[9], r[10], r[11], r[12] || ''].map(q).join(','));
        });
        const blob = new Blob(['﻿' + lines.join('\n')], {type: 'text/csv;charset=utf-8'});
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'legal-pdc-' + filter + '-' + P.today + '.csv';
        a.click();
        URL.revokeObjectURL(a.href);
    }
    $('pdcExport').addEventListener('click', exportCsv);

    apply();
    fit();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);
    window.addEventListener('load', fit);
    setTimeout(fit, 400);
    let t1;
    window.addEventListener('resize', () => { clearTimeout(t1); t1 = setTimeout(fit, 200); });

    // ---------- Chart (inside the Insights pop-up): AED due per week, next 90 days ----------
    let chartMade = false;
    insEl.addEventListener('shown.bs.modal', () => {
        if (chartMade || !window.Chart) return;
        chartMade = true;
        const D = P.chart;
        Chart.defaults.font.family = "'Plus Jakarta Sans', system-ui, sans-serif";
        Chart.defaults.color = '#52514e';
        Chart.defaults.plugins.tooltip.backgroundColor = '#111827';
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.tooltip.cornerRadius = 8;
        const grad = ctx => {
            const area = ctx.chart.chartArea;
            if (!area) return '#2a78d6';
            const g = ctx.chart.ctx.createLinearGradient(0, area.bottom, 0, area.top);
            g.addColorStop(0, '#8fbaf0');
            g.addColorStop(1, '#2a78d6');
            return g;
        };
        const addDays = (d, n) => { const x = new Date(d + 'T00:00:00Z'); x.setUTCDate(x.getUTCDate() + n); return x.toISOString().slice(0, 10); };
        const short = v => v >= 1e6 ? (v / 1e6).toFixed(1) + 'M' : v >= 1e3 ? Math.round(v / 1e3) + 'k' : v;
        new Chart($('pdcChart'), {
            type: 'bar',
            data: {labels: D.labels.map(l => 'w/c ' + l), datasets: [{label: 'Unpaid cheques due', data: D.amt, backgroundColor: grad,
                   borderRadius: 8, borderSkipped: false, maxBarThickness: 44, hoverBackgroundColor: '#1d5fb0'}]},
            options: {
                maintainAspectRatio: false,
                plugins: {legend: {display: false},
                          tooltip: {callbacks: {
                              title: items => 'Week of ' + D.labels[items[0].dataIndex],
                              label: c => ' AED ' + fmt(c.parsed.y) + ' · ' + D.n[c.dataIndex] + ' cheque' + (D.n[c.dataIndex] === 1 ? '' : 's'),
                              footer: () => 'Click to list them'}}},
                scales: {x: {grid: {display: false}, border: {display: false}},
                         y: {beginAtZero: true, grid: {color: '#eceef2'}, border: {display: false}, ticks: {callback: v => 'AED ' + short(v)}}},
                onHover: (e, els) => { e.native.target.style.cursor = els.length ? 'pointer' : 'default'; },
                onClick: (e, els) => {
                    if (!els.length) return;
                    const i = els[0].index;
                    filter = 'pending'; clearOthers();
                    from.value = D.from[i]; to.value = [addDays(D.from[i], 6), P.in90].sort()[0];
                    apply(); closeInsights();
                },
            },
        });
    });
})();
</script>
JS;
require_once __DIR__ . '/includes/legal_layout_footer.php';
?>
