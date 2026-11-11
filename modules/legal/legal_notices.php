<?php
/**
 * Legal Department — Legal Notices.
 *
 * Top:    notice figures from re_legal_notices (each one filters the list).
 * Middle: "Tenants who should get a notice" — built from the lease data
 *         (includes/legal_lease_watch.php), grouped by the notice that fits.
 *         Each lease sits in exactly one group, so the group counts add up.
 * Bottom: the notices list with its filters.
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

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$noticeTypes    = legal_notice_types();
$deliveryStats  = legal_delivery_statuses();
$deliveryMeths  = legal_notice_delivery_methods();
$today          = date('Y-m-d');
$in7            = date('Y-m-d', strtotime('+7 days'));

// ---------------------------------------------------------------------------
// Notice figures (whole company, ignoring the list filters)
// ---------------------------------------------------------------------------
$quickDefs = [
    'awaiting'  => ['Awaiting delivery', "n.delivery_status IN ('draft','sent')"],
    'delivered' => ['Delivered', "n.delivery_status IN ('delivered','acknowledged','no_response')"],
    'due_week'  => ['Response due in 7 days', "n.delivery_status <> 'acknowledged' AND COALESCE(n.response_deadline, n.deadline_date) BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY"],
    'overdue'   => ['Response overdue', "n.delivery_status <> 'acknowledged' AND COALESCE(n.response_deadline, n.deadline_date) < CURDATE()"],
];

$ns = ['total' => 0, 'draft' => 0, 'sent' => 0, 'delivered' => 0, 'acknowledged' => 0, 'no_response' => 0,
       'due_week' => 0, 'overdue' => 0];
$noticeMonthly = [];
for ($m = 5; $m >= 0; $m--) {
    $noticeMonthly[date('Y-m', strtotime("first day of -$m months"))] = 0;
}
try {
    $st = $conn->prepare("
        SELECT n.delivery_status, COALESCE(n.response_deadline, n.deadline_date) AS dl, n.created_at
        FROM re_legal_notices n WHERE n.company_id = ?
    ");
    $st->execute([$currentCompanyId]);
    while ($n = $st->fetch(PDO::FETCH_ASSOC)) {
        $ns['total']++;
        if (isset($ns[$n['delivery_status']])) $ns[$n['delivery_status']]++;
        if ($n['delivery_status'] !== 'acknowledged' && $n['dl']) {
            if ($n['dl'] < $today) $ns['overdue']++;
            elseif ($n['dl'] <= $in7) $ns['due_week']++;
        }
        $mk = substr((string)$n['created_at'], 0, 7);
        if (isset($noticeMonthly[$mk])) $noticeMonthly[$mk]++;
    }
} catch (Throwable $e) {
    $error = $error ?: 'Notice figures could not be loaded: ' . $e->getMessage();
}
$ns['awaiting']      = $ns['draft'] + $ns['sent'];
$ns['delivered_all'] = $ns['delivered'] + $ns['acknowledged'] + $ns['no_response'];

// ---------------------------------------------------------------------------
// Notices list (existing filters + optional quick filter from the strip)
// ---------------------------------------------------------------------------
$typeFilter   = $_GET['notice_type'] ?? 'all';
$statusFilter = $_GET['delivery_status'] ?? 'all';
$search       = trim($_GET['search'] ?? '');
$quick        = $_GET['quick'] ?? '';
if (!isset($quickDefs[$quick])) $quick = '';

$where = ["n.company_id = ?"];
$params = [$currentCompanyId];
if ($typeFilter !== 'all' && array_key_exists($typeFilter, $noticeTypes)) {
    $where[] = "n.notice_type = ?";
    $params[] = $typeFilter;
}
if ($statusFilter !== 'all' && array_key_exists($statusFilter, $deliveryStats)) {
    $where[] = "n.delivery_status = ?";
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = "(n.reference_number LIKE ? OR n.subject LIKE ? OR n.recipient_name LIKE ? OR l.lease_number LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($quick !== '') {
    $where[] = $quickDefs[$quick][1];
}
$filtersOn = ($typeFilter !== 'all' || $statusFilter !== 'all' || $search !== '' || $quick !== '');

$notices = [];
try {
    $stmt = $conn->prepare("
        SELECT n.*, c.case_number, l.lease_number, u.unit_number, b.name AS building_name
        FROM re_legal_notices n
        LEFT JOIN re_legal_cases c ON c.id = n.case_id
        LEFT JOIN re_leases l      ON l.id = n.lease_id
        LEFT JOIN re_units u       ON u.id = n.unit_id
        LEFT JOIN re_buildings b   ON b.id = n.building_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY n.created_at DESC
    ");
    $stmt->execute($params);
    $notices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = $error ?: 'Notices could not be loaded: ' . $e->getMessage();
}

// ---------------------------------------------------------------------------
// Tenants who should get a notice (lease data)
// ---------------------------------------------------------------------------
$watchRows = [];
$watchError = null;
$ins = ['open' => []];
try {
    $watchRows = legal_lease_watch_rows($conn, (int)$currentCompanyId);
    $ins = legal_watch_cheque_insights($conn, (int)$currentCompanyId);
} catch (Throwable $e) {
    $watchError = $e->getMessage();
}
$flagMeta = legal_watch_flag_meta();

// Oldest open debt + bounced cheque numbers, per lease.
$leaseDebt = [];
foreach ($ins['open'] as $c) {
    $lid = (int)$c['lease_id'];
    $leaseDebt[$lid] ??= ['age' => 0, 'bounced_nos' => []];
    $leaseDebt[$lid]['age'] = max($leaseDebt[$lid]['age'], (int)$c['age_days']);
    if ($c['kind'] === 'bounced' && $c['cheque_number'] !== null && $c['cheque_number'] !== '') {
        $leaseDebt[$lid]['bounced_nos'][] = (string)$c['cheque_number'];
    }
}

// Leases that already got a notice in the last 30 days, and open cases per lease.
$recentNotice = [];
$openCaseId = [];
try {
    $st = $conn->prepare("SELECT lease_id, MAX(created_at) FROM re_legal_notices
                          WHERE company_id = ? AND lease_id IS NOT NULL AND created_at >= CURDATE() - INTERVAL 30 DAY
                          GROUP BY lease_id");
    $st->execute([$currentCompanyId]);
    $recentNotice = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $st = $conn->prepare("SELECT lease_id, MAX(id) FROM re_legal_cases
                          WHERE company_id = ? AND deleted_at IS NULL AND lease_id IS NOT NULL
                            AND status NOT IN ('settled','closed','withdrawn')
                          GROUP BY lease_id");
    $st->execute([$currentCompanyId]);
    $openCaseId = $st->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) { /* optional tables */ }

// Groups, checked in this order; a lease goes into the first that fits.
$groups = [
    'final' => [
        'type' => 'final_notice', 'title' => 'Final demand', 'tone' => 'pink', 'icon' => 'bi-door-open-fill',
        'rule' => 'Lease expired, terminated or past its end date, and money is still owed (bounced + overdue).',
    ],
    'vacate' => [
        'type' => 'eviction_30day', 'title' => 'Renew or vacate', 'tone' => 'indigo', 'icon' => 'bi-calendar-x-fill',
        'rule' => 'Lease end date has passed, no renewal, no move-out, unit not let again — tenant is likely still in the unit with no contract.',
    ],
    'bounced' => [
        'type' => 'cheque_bounce_demand', 'title' => 'Bounced cheque demand', 'tone' => 'violet', 'icon' => 'bi-x-octagon-fill',
        'rule' => 'At least one cheque bounced or returned, not replaced, not settled and not fully collected.',
    ],
    'demand' => [
        'type' => 'demand_payment', 'title' => 'Demand for payment', 'tone' => 'indigo', 'icon' => 'bi-cash-coin',
        'rule' => 'Oldest unpaid cheque is 90 days or more past its date.',
    ],
    'reminder' => [
        'type' => 'warning', 'title' => 'Payment reminder', 'tone' => 'sky', 'icon' => 'bi-alarm-fill',
        'rule' => 'Oldest unpaid cheque is 30 to 89 days past its date.',
    ],
];
foreach ($groups as &$g) { $g['rows'] = []; $g['amt'] = 0.0; }
unset($g);

$skippedRecent = 0;
foreach ($watchRows as $r) {
    $nr = !empty($r['not_renewed']);
    if ((float)$r['outstanding'] <= 0.005 && !$nr) continue;
    $key = null;
    if (in_array('ended_balance', $r['flags'], true))             $key = 'final';
    elseif ($nr)                                                   $key = 'vacate';
    elseif ((int)$r['bounced_count'] > 0)                          $key = 'bounced';
    elseif ((int)$r['overdue_count'] > 0 && $r['days_overdue'] >= 90) $key = 'demand';
    elseif ((int)$r['overdue_count'] > 0 && $r['days_overdue'] >= 30) $key = 'reminder';
    if ($key === null) continue;
    if (isset($recentNotice[$r['id']])) { $skippedRecent++; continue; }
    $r['debt_age'] = $key === 'vacate' ? (int)$r['days_expired'] : (int)($leaseDebt[(int)$r['id']]['age'] ?? $r['days_overdue']);
    $r['bounced_nos'] = $leaseDebt[(int)$r['id']]['bounced_nos'] ?? [];
    $r['case_id'] = (int)($openCaseId[$r['id']] ?? 0);
    $groups[$key]['rows'][] = $r;
    $groups[$key]['amt'] += (float)$r['outstanding'];
}
foreach ($groups as &$g) {
    usort($g['rows'], fn($a, $b) => $b['outstanding'] <=> $a['outstanding'] ?: $b['debt_age'] <=> $a['debt_age']);
}
unset($g);
$sugCount = array_sum(array_map(fn($g) => count($g['rows']), $groups));
$sugAmt   = array_sum(array_map(fn($g) => $g['amt'], $groups));
$sugBuildings = [];
foreach ($groups as $g) foreach ($g['rows'] as $r) $sugBuildings[$r['building_id']] = $r['building_name'];
asort($sugBuildings);

// ---------------------------------------------------------------------------
// Display helpers (page-local)
// ---------------------------------------------------------------------------
if (!function_exists('ln_type_tone')) {
    function ln_type_tone(string $t): string {
        return [
            'warning' => 'amber', 'demand_payment' => 'indigo', 'eviction_30day' => 'rose',
            'eviction_12month_notarized' => 'rose', 'cheque_bounce_demand' => 'red',
            'contract_termination' => 'slate', 'final_notice' => 'violet', 'other' => 'slate',
        ][$t] ?? 'slate';
    }
    function ln_method_icon(string $m): string {
        return [
            'email' => 'bi-envelope', 'courier' => 'bi-truck', 'hand' => 'bi-person-walking',
            'registered_post' => 'bi-mailbox', 'notary' => 'bi-patch-check', 'other' => 'bi-three-dots',
        ][$m] ?? 'bi-send';
    }
    function ln_status_icon(string $s): string {
        return [
            'draft' => 'bi-pencil', 'sent' => 'bi-send', 'delivered' => 'bi-box-arrow-in-down',
            'acknowledged' => 'bi-check2-circle', 'no_response' => 'bi-hourglass-split',
        ][$s] ?? 'bi-circle';
    }
}

$stripSpark = array_map(fn($k, $v) => [date('M', strtotime($k . '-01')), $v], array_keys($noticeMonthly), $noticeMonthly);
$qUrl = fn(string $q) => 'legal_notices.php' . ($q !== '' ? '?quick=' . urlencode($q) : '') . '#ln-list';

$pageTitle = 'Legal Notices';
$pageHead = '
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<link href="assets/legal-notices.css?v=' . (int)@filemtime(__DIR__ . '/assets/legal-notices.css') . '" rel="stylesheet">';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
<div class="lw-page ln-page">

    <div class="lw-head d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h1 class="mb-1">Legal Notices</h1>
            <div class="lw-sub">Warnings, demands and eviction notices · <?= h(date('l, j F Y')) ?></div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="legal_dashboard.php" class="btn lw-btn-soft"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a href="legal_case_list.php" class="btn lw-btn-soft"><i class="bi bi-briefcase"></i> Cases</a>
            <a href="legal_notice_add.php" class="btn lw-btn-grad"><i class="bi bi-plus-lg"></i> New Notice</a>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
    <?php if ($watchError): ?><div class="alert alert-danger">Lease data could not be loaded: <?= h($watchError) ?></div><?php endif; ?>

    <!-- Notice figures -->
    <?php
    $segs = [
        ['navy',   'bi-envelope-paper-fill', (string)$ns['total'], 'Notices on file', $ns['total'] ? 'All types and statuses' : 'None issued yet', '', 'Every legal notice recorded for this company'],
        ['violet', 'bi-send-fill', (string)$ns['awaiting'], 'Awaiting delivery', $ns['draft'] . ' draft · ' . $ns['sent'] . ' sent', 'awaiting', 'Drafted or sent, not yet confirmed delivered'],
        ['sky',    'bi-check2-all', (string)$ns['delivered_all'], 'Delivered', $ns['acknowledged'] . ' acknowledged · ' . $ns['no_response'] . ' no response', 'delivered', 'Delivered, acknowledged or delivered with no response'],
        ['amber',  'bi-calendar2-week-fill', (string)$ns['due_week'], 'Response due ≤ 7 days', 'Deadline ' . date('j M', strtotime($today)) . ' – ' . date('j M', strtotime($in7)), 'due_week', 'Response deadline in the next 7 days, not yet acknowledged'],
        ['rose',   'bi-alarm-fill', (string)$ns['overdue'], 'Response overdue', 'Deadline passed, not acknowledged', 'overdue', 'Response deadline has passed and the tenant has not acknowledged'],
        ['rose',   'bi-person-exclamation', (string)$sugCount, 'Should get a notice', 'Leases · AED ' . lw_aed($sugAmt) . ' owed', '#sug', 'Leases the cheque data says need a notice — see the groups below'],
    ];
    ?>
    <div class="lw-strip mb-4">
        <?php foreach ($segs as $i => [$tone, $icon, $big, $label, $sub, $go, $tip]): ?>
            <div class="lw-alert lw-seg lw-st-<?= h($tone) ?> <?= $go !== '' && $go === $quick ? 'ln-seg-on' : '' ?>" title="<?= h($tip) ?>">
                <?php if ($go === '#sug'): ?>
                    <button type="button" class="lw-alert-btn" data-sug-open="all" aria-label="<?= h($label) ?>: open list"></button>
                <?php elseif ($go !== ''): ?>
                    <a class="lw-alert-btn" href="<?= h($quick === $go ? $qUrl('') : $qUrl($go)) ?>" aria-label="<?= h($label) ?>: filter the list"></a>
                <?php endif; ?>
                <span class="lw-seg-ico"><i class="bi <?= h($icon) ?>"></i></span>
                <div class="lw-seg-body">
                    <div class="lw-seg-label"><?= h($label) ?></div>
                    <div class="lw-seg-big"><?= h($big) ?></div>
                    <div class="lw-seg-sub"><?= h($sub) ?></div>
                </div>
                <?php if ($i === 0 && $ns['total'] > 0): ?><span class="lw-seg-spark" title="Notices created per month"><?= lw_spark($stripSpark) ?></span><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Tenants who should get a notice -->
    <div class="lw-panel mb-4" id="sug">
        <div class="lw-panel-head flex-wrap">
            <div>
                <h2>Tenants who should get a notice</h2>
                <div class="lw-muted mt-1">From the lease cheques today · <?= (int)$sugCount ?> leases · AED <?= number_format($sugAmt, 0) ?> owed (bounced + overdue)</div>
            </div>
            <button type="button" class="btn lw-btn-soft" data-sug-open="all"><i class="bi bi-list-ul"></i> See all <?= (int)$sugCount ?></button>
        </div>

        <?php if (!$sugCount): ?>
            <div class="lw-empty-state"><i class="bi bi-emoji-smile"></i>No lease needs a notice right now — nothing bounced, ended with a balance or 30+ days overdue.</div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($groups as $gk => $g): $cnt = count($g['rows']); ?>
                <div class="col-xl-3 col-md-6">
                    <div class="ln-group lw-tone-<?= h($g['tone']) ?> <?= $cnt ? '' : 'is-empty' ?>">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <span class="lw-sq"><i class="bi <?= h($g['icon']) ?>"></i></span>
                            <div class="min-w-0">
                                <div class="ln-group-title"><?= h($g['title']) ?></div>
                                <div class="lw-muted">Notice type: <?= h($noticeTypes[$g['type']]) ?></div>
                            </div>
                        </div>
                        <div class="d-flex align-items-end justify-content-between gap-2 mb-2">
                            <div>
                                <div class="ln-group-num"><?= $cnt ?> <small><?= $cnt === 1 ? 'lease' : 'leases' ?></small></div>
                                <div class="ln-group-amt">AED <?= number_format($g['amt'], 0) ?> owed</div>
                            </div>
                            <?php if ($cnt): ?>
                            <div class="ln-stack" aria-hidden="true">
                                <?php foreach (array_slice($g['rows'], 0, 4) as $r): ?>
                                    <span class="lw-avatar lw-avatar-sm lw-av-<?= lw_avatar_tone($r['tenant_label']) ?>" title="<?= h($r['tenant_label']) ?>"><?= h(lw_initials($r['tenant_label'])) ?></span>
                                <?php endforeach; ?>
                                <?php if ($cnt > 4): ?><span class="lw-avatar lw-avatar-sm ln-more">+<?= $cnt - 4 ?></span><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="ln-rule"><i class="bi bi-info-circle"></i> <?= h($g['rule']) ?></div>
                        <?php if ($cnt): ?>
                            <div class="ln-top">
                                <?php foreach (array_slice($g['rows'], 0, 3) as $r): ?>
                                    <button type="button" class="ln-top-row" data-sug-open="<?= h($gk) ?>" data-sug-search="<?= h((string)$r['lease_number']) ?>">
                                        <span class="text-truncate"><?= h($r['tenant_label']) ?></span>
                                        <span class="ln-top-amt"><?= h(lw_aed((float)$r['outstanding'])) ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn ln-group-btn w-100 mt-2" data-sug-open="<?= h($gk) ?>">
                                <i class="bi bi-envelope-plus"></i> View &amp; draft <?= $cnt ?>
                            </button>
                        <?php else: ?>
                            <div class="lw-muted mt-3"><i class="bi bi-check2-circle text-success"></i> Nobody in this group.</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="lw-muted mt-3">
            <i class="bi bi-signpost-split"></i>
            Each lease is listed once, in the first group that fits (final demand → bounced → 90+ days → 30–89 days).
            Leases that already got a notice in the last 30 days are left out<?= $skippedRecent ? ' (' . (int)$skippedRecent . ' now)' : '' ?>.
            Overdue = cheque date passed and not cleared or fully collected.
        </div>
    </div>

    <!-- Notices list -->
    <div class="lw-panel mb-4" id="ln-list">
        <div class="lw-panel-head flex-wrap">
            <div>
                <h2>Notices</h2>
                <div class="lw-muted mt-1">
                    Showing <?= count($notices) ?> of <?= (int)$ns['total'] ?> notices<?php if ($quick): ?> · <?= h($quickDefs[$quick][0]) ?><?php endif; ?>
                </div>
            </div>
            <?php if ($quick): ?>
                <a href="<?= h('legal_notices.php?' . http_build_query(array_filter(['search' => $search, 'notice_type' => $typeFilter !== 'all' ? $typeFilter : null, 'delivery_status' => $statusFilter !== 'all' ? $statusFilter : null])) . '#ln-list') ?>"
                   class="lw-chip is-active text-decoration-none"><i class="bi bi-funnel"></i> <?= h($quickDefs[$quick][0]) ?> <i class="bi bi-x"></i></a>
            <?php endif; ?>
        </div>

        <form method="GET" class="row g-2 mb-3 ln-filters" action="legal_notices.php#ln-list">
            <?php if ($quick): ?><input type="hidden" name="quick" value="<?= h($quick) ?>"><?php endif; ?>
            <div class="col-lg-4 col-md-6">
                <label class="form-label">Search</label>
                <div class="lw-search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" value="<?= h($search) ?>" placeholder="Reference, subject, recipient, lease">
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <label class="form-label">Type</label>
                <select name="notice_type" class="form-select form-select-sm ln-s2">
                    <option value="all">All types</option>
                    <?php foreach ($noticeTypes as $k => $v): ?>
                        <option value="<?= h($k) ?>" <?= $typeFilter === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3 col-md-6">
                <label class="form-label">Status</label>
                <select name="delivery_status" class="form-select form-select-sm ln-s2">
                    <option value="all">All statuses</option>
                    <?php foreach ($deliveryStats as $k => $v): ?>
                        <option value="<?= h($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-6 d-flex align-items-end gap-2">
                <button class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-funnel"></i> Filter</button>
                <a href="legal_notices.php#ln-list" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>

        <?php if (!$notices): ?>
            <?php if ($filtersOn && $ns['total'] > 0): ?>
                <div class="lw-empty-state">
                    <i class="bi bi-search"></i>
                    No notices match these filters.
                    <div class="mt-3"><a href="legal_notices.php#ln-list" class="btn lw-btn-soft btn-sm">Reset filters</a></div>
                </div>
            <?php else: ?>
                <div class="ln-empty">
                    <span class="ln-empty-ico"><i class="bi bi-envelope-paper"></i></span>
                    <div class="ln-empty-title">No legal notices yet</div>
                    <div class="lw-muted mb-3">
                        <?php if ($sugCount): ?>
                            The lease data shows <strong><?= (int)$sugCount ?> leases</strong> owing AED <?= number_format($sugAmt, 0) ?> that should get one.
                            Start with the groups above — each row has a <em>Draft notice</em> button.
                        <?php else: ?>
                            Notices you draft will show here with their delivery status and response deadline.
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2 justify-content-center flex-wrap">
                        <?php if ($sugCount): ?><button type="button" class="btn lw-btn-soft" data-sug-open="all"><i class="bi bi-person-exclamation"></i> Who needs a notice</button><?php endif; ?>
                        <a href="legal_notice_add.php" class="btn lw-btn-grad"><i class="bi bi-plus-lg"></i> New Notice</a>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table lw-table ln-table align-middle">
                <thead><tr>
                    <th>Reference</th><th>Type</th><th>Recipient / lease</th><th>Case</th>
                    <th>Delivery</th><th>Issued</th><th>Response deadline</th><th class="text-end">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($notices as $n):
                    $dl = $n['response_deadline'] ?: $n['deadline_date'];
                    $ack = $n['delivery_status'] === 'acknowledged';
                    $daysLeft = $dl ? (int)floor((strtotime($dl) - strtotime($today)) / 86400) : null;
                    $sev = !$ack && $dl && $daysLeft < 0 ? 3 : (!$ack && $dl && $daysLeft <= 7 ? 2 : 0);
                ?>
                    <tr class="lw-sev-<?= $sev ?>">
                        <td>
                            <a class="ln-ref" href="legal_notice_view.php?id=<?= (int)$n['id'] ?>"><?= h($n['reference_number']) ?></a>
                            <div class="lw-muted text-truncate" style="max-width:260px" title="<?= h($n['subject']) ?>"><?= h($n['subject']) ?></div>
                        </td>
                        <td><span class="ln-type ln-t-<?= h(ln_type_tone((string)$n['notice_type'])) ?>"><?= h($noticeTypes[$n['notice_type']] ?? $n['notice_type']) ?></span></td>
                        <td>
                            <?php $rn = $n['recipient_name'] ?: '—'; ?>
                            <div class="d-flex align-items-center gap-2">
                                <span class="lw-avatar lw-avatar-sm lw-av-<?= lw_avatar_tone($rn) ?>"><?= h(lw_initials($rn)) ?></span>
                                <div class="min-w-0">
                                    <div class="lw-tname"><?= h($rn) ?></div>
                                    <?php if ($n['lease_id']): ?>
                                        <a class="lw-muted" href="<?= h($re) ?>lease_view.php?id=<?= (int)$n['lease_id'] ?>"><?= h($n['lease_number'] ?: '#' . $n['lease_id']) ?></a>
                                        <?php if ($n['building_name']): ?><span class="lw-muted"> · <?= h($n['building_name']) ?> <?= h((string)$n['unit_number']) ?></span><?php endif; ?>
                                    <?php elseif ($n['recipient_email']): ?>
                                        <span class="lw-muted"><?= h($n['recipient_email']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><?= $n['case_number'] ? '<a href="legal_case_view.php?id=' . (int)$n['case_id'] . '">' . h($n['case_number']) . '</a>' : '<span class="lw-muted">—</span>' ?></td>
                        <td class="text-nowrap">
                            <span class="ln-method" title="<?= h($deliveryMeths[$n['delivery_method']] ?? $n['delivery_method']) ?>"><i class="bi <?= h(ln_method_icon((string)$n['delivery_method'])) ?>"></i> <?= h($deliveryMeths[$n['delivery_method']] ?? $n['delivery_method']) ?></span>
                            <span class="ln-st ln-st-<?= h($n['delivery_status']) ?>"><i class="bi <?= h(ln_status_icon((string)$n['delivery_status'])) ?>"></i> <?= h($deliveryStats[$n['delivery_status']] ?? $n['delivery_status']) ?></span>
                        </td>
                        <td class="text-nowrap">
                            <?= $n['issue_date'] ? h(date('j M Y', strtotime($n['issue_date']))) : '<span class="lw-muted">—</span>' ?>
                            <?php if ($n['delivered_at']): ?><div class="lw-muted">Delivered <?= h(date('j M', strtotime($n['delivered_at']))) ?></div><?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                            <?php if (!$dl): ?>
                                <span class="lw-muted">No deadline</span>
                            <?php elseif ($ack): ?>
                                <span class="ln-dl ok"><i class="bi bi-check2-circle"></i> Acknowledged</span>
                                <div class="lw-muted"><?= h(date('j M Y', strtotime($dl))) ?></div>
                            <?php elseif ($daysLeft < 0): ?>
                                <span class="ln-dl late"><i class="bi bi-alarm-fill"></i> <?= abs($daysLeft) ?> day<?= abs($daysLeft) === 1 ? '' : 's' ?> late</span>
                                <div class="lw-muted"><?= h(date('j M Y', strtotime($dl))) ?></div>
                            <?php elseif ($daysLeft <= 7): ?>
                                <span class="ln-dl soon"><i class="bi bi-hourglass-split"></i> <?= $daysLeft === 0 ? 'Today' : 'In ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') ?></span>
                                <div class="lw-muted"><?= h(date('j M Y', strtotime($dl))) ?></div>
                            <?php else: ?>
                                <span class="ln-dl"><i class="bi bi-calendar-event"></i> <?= h(date('j M Y', strtotime($dl))) ?></span>
                                <div class="lw-muted">in <?= $daysLeft ?> days</div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end lw-actions text-nowrap">
                            <a href="legal_notice_view.php?id=<?= (int)$n['id'] ?>" class="btn btn-outline-secondary" title="View notice"><i class="bi bi-eye"></i></a>
                            <a href="legal_notice_pdf.php?id=<?= (int)$n['id'] ?>&mode=view" target="_blank" rel="noopener" class="btn btn-outline-secondary" title="PDF"><i class="bi bi-file-earmark-pdf"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Suggested notices pop-up -->
    <div class="modal fade" id="lnModal" tabindex="-1" aria-labelledby="lnModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content lw-page lw-modal">
    <div class="lw-card" id="lnWatch">
        <div class="lw-mhead">
            <div class="d-flex align-items-center gap-3 min-w-0">
                <span class="lw-mhead-ico" id="lnModalIcon"><i class="bi bi-person-exclamation"></i></span>
                <div class="min-w-0">
                    <h2 id="lnModalTitle">Tenants who should get a notice</h2>
                    <div class="lw-mhead-stats" id="lnModalStats"></div>
                </div>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <button type="button" class="lw-glass-btn" id="lnExport"><i class="bi bi-download"></i> Export</button>
                <button type="button" class="lw-glass-btn lw-glass-x" data-bs-dismiss="modal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>
        <div class="lw-card-head">
            <div class="lw-chips mb-2" id="lnChips">
                <button type="button" class="lw-chip is-active" data-group="all"><i class="bi bi-list-ul"></i> All suggested <span class="lw-count"><?= (int)$sugCount ?></span></button>
                <?php foreach ($groups as $gk => $g): ?>
                    <button type="button" class="lw-chip" data-group="<?= h($gk) ?>"><i class="bi <?= h($g['icon']) ?>"></i> <?= h($g['title']) ?> <span class="lw-count"><?= count($g['rows']) ?></span></button>
                <?php endforeach; ?>
            </div>
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <div style="width:260px">
                    <select id="lnBuilding" class="form-select form-select-sm">
                        <option value="">All buildings</option>
                        <?php foreach ($sugBuildings as $bid => $bname): ?>
                            <option value="<?= (int)$bid ?>"><?= h($bname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="search" id="lnSearch" class="form-control form-control-sm" style="width:320px;max-width:100%" placeholder="Search tenant, lease, unit, phone">
                <span class="lw-muted ms-auto" id="lnRule"></span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table lw-table" id="lnTable">
                <thead><tr>
                    <th>Tenant</th><th>Lease</th><th>Building / unit</th><th>Suggested notice</th><th>Flags</th>
                    <th class="text-end">Owed</th><th class="text-end">Oldest debt</th><th class="text-end">Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ($groups as $gk => $g): foreach ($g['rows'] as $r):
                    $draft = 'legal_notice_add.php?' . http_build_query([
                        'case_id'     => $r['case_id'] ?: null,
                        'notice_type' => $g['type'],
                        'lease_id'    => $r['id'],
                        'tenant_id'   => $r['tenant_id'],
                    ]);
                    $search = strtolower(implode(' ', [$r['lease_number'], $r['tenant_label'], $r['unit_label'], $r['phone'], $r['email']]));
                    $sev = $gk === 'final' || $gk === 'bounced' ? 3 : ($gk === 'demand' ? 2 : 1);
                ?>
                    <tr class="lw-sev-<?= $sev ?>" data-group="<?= h($gk) ?>" data-building="<?= (int)$r['building_id'] ?>"
                        data-owed="<?= round((float)$r['outstanding'], 2) ?>" data-search="<?= h($search) ?>"
                        data-csv="<?= h(json_encode([
                            $g['title'], $noticeTypes[$g['type']], $r['tenant_label'], $r['phone'], $r['email'], $r['lease_number'], $r['status'],
                            $r['building_name'], $r['unit_number'], round((float)$r['bounced_amt'], 2), round((float)$r['overdue_amt'], 2),
                            round((float)$r['outstanding'], 2), $r['debt_age'], implode('; ', array_map(fn($f) => $flagMeta[$f][0], $r['flags'])),
                        ])) ?>">
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="lw-avatar lw-avatar-sm lw-av-<?= lw_avatar_tone($r['tenant_label']) ?>"><?= h(lw_initials($r['tenant_label'])) ?></span>
                                <div class="min-w-0">
                                    <div class="lw-tname"><?= h($r['tenant_label']) ?></div>
                                    <?php if ($r['phone']): ?><a class="lw-muted text-decoration-none" href="tel:<?= h($r['phone']) ?>"><i class="bi bi-telephone"></i> <?= h($r['phone']) ?></a><?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="lw-lease">
                            <a href="<?= h($re) ?>lease_view.php?id=<?= (int)$r['id'] ?>#installments"><?= h($r['lease_number'] ?: '#' . $r['id']) ?></a><br>
                            <span class="lw-status <?= h($r['status']) ?>"><?= h(str_replace('_', ' ', $r['status'])) ?></span>
                            <?php if ($r['end_date']): ?><span class="lw-muted ms-1"><?= $r['lease_ended'] ? 'ended' : 'to' ?> <?= h(date('j M y', strtotime($r['end_date']))) ?></span><?php endif; ?>
                        </td>
                        <td><?= h($r['building_name']) ?><br><span class="lw-muted">Unit <?= h($r['unit_number']) ?></span></td>
                        <td>
                            <span class="ln-type ln-t-<?= h(ln_type_tone($g['type'])) ?>"><?= h($noticeTypes[$g['type']]) ?></span>
                            <?php if ($r['bounced_nos']): ?>
                                <div class="lw-muted mt-1 text-truncate" style="max-width:200px" title="<?= h(implode(', ', $r['bounced_nos'])) ?>">Chq <?= h(implode(', ', array_slice($r['bounced_nos'], 0, 3))) ?><?= count($r['bounced_nos']) > 3 ? ' +' . (count($r['bounced_nos']) - 3) : '' ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="max-width:220px">
                            <?php foreach ($r['flags'] as $f): [$fl, , $fi] = $flagMeta[$f]; ?>
                                <span class="lw-flag lw-flag-<?= h($f) ?>"><i class="bi <?= h($fi) ?>"></i>
                                    <?= h($fl) ?><?php if ($f === 'bounced'): ?> ×<?= (int)$r['bounced_count'] ?><?php elseif ($f === 'overdue'): ?> · <?= (int)$r['days_overdue'] ?>d<?php elseif ($f === 'held'): ?> ×<?= (int)$r['held_count'] ?><?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </td>
                        <td class="text-end">
                            <div class="lw-money <?= $r['bounced_amt'] > 0 ? 'red' : 'amber' ?>"><?= number_format((float)$r['outstanding'], 0) ?></div>
                            <span class="lw-muted">
                                <?php if ($r['bounced_amt'] > 0): ?><?= h(lw_aed((float)$r['bounced_amt'])) ?> bounced<?php endif; ?>
                                <?php if ($r['bounced_amt'] > 0 && $r['overdue_amt'] > 0): ?> · <?php endif; ?>
                                <?php if ($r['overdue_amt'] > 0): ?><?= h(lw_aed((float)$r['overdue_amt'])) ?> overdue<?php endif; ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <div class="ln-days <?= $r['debt_age'] > 90 ? 'hot' : '' ?>"><?= (int)$r['debt_age'] ?> <small>days</small></div>
                        </td>
                        <td class="text-end lw-actions text-nowrap">
                            <a class="btn btn-outline-secondary" href="<?= h($re) ?>lease_view.php?id=<?= (int)$r['id'] ?>#installments" title="Open lease and cheques"><i class="bi bi-box-arrow-up-right"></i></a>
                            <a class="btn btn-dark" href="<?= h($draft) ?>" title="<?= $r['case_id'] ? 'Draft a notice linked to the open case (recipient pre-filled)' : 'Draft a notice — pick the type shown here' ?>"><i class="bi bi-envelope-plus"></i> Draft notice</a>
                        </td>
                    </tr>
                <?php endforeach; endforeach; ?>
                </tbody>
            </table>
            <div class="lw-empty d-none" id="lnEmpty"><i class="bi bi-check2-circle"></i>Nobody here — no leases match this filter.</div>
        </div>
        <div class="px-3 py-2 border-top lw-muted d-flex justify-content-between flex-wrap gap-2">
            <span id="lnShown"></span>
            <span>Draft notice opens the New Notice form; choose the suggested type there. Leases with an open case link the notice to it.</span>
        </div>
    </div>
    </div>
    </div>
    </div>

</div>

<?php
$lnGroupsJs = [];
foreach ($groups as $gk => $g) $lnGroupsJs[$gk] = [$g['title'], $g['icon'], $g['tone'], $g['rule']];
$pageScripts = '<script>window.LN_GROUPS = ' . json_encode($lnGroupsJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ';</script>' . <<<'JS'
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function () {
    const hasS2 = !!(window.jQuery && jQuery.fn.select2);
    // Searchable filter dropdowns on the page.
    if (hasS2) {
        jQuery('.ln-s2').each(function () {
            jQuery(this).select2({theme: 'bootstrap-5', width: '100%'})
                .on('change', function () { this.form.submit(); });
        });
    }

    const modalEl = document.getElementById('lnModal');
    if (!modalEl) return;
    const rows = Array.from(document.querySelectorAll('#lnTable tbody tr'));
    const chips = document.querySelectorAll('#lnChips .lw-chip');
    const search = document.getElementById('lnSearch');
    const building = document.getElementById('lnBuilding');
    const empty = document.getElementById('lnEmpty');
    const shown = document.getElementById('lnShown');
    const G = window.LN_GROUPS || {};
    let group = 'all';

    function apply() {
        const q = search.value.trim().toLowerCase();
        const b = building.value;
        let n = 0, amt = 0;
        rows.forEach(tr => {
            const ok = (group === 'all' || tr.dataset.group === group)
                && (!b || tr.dataset.building === b)
                && (!q || tr.dataset.search.includes(q));
            tr.classList.toggle('d-none', !ok);
            if (ok) { n++; amt += parseFloat(tr.dataset.owed || 0); }
        });
        empty.classList.toggle('d-none', n > 0);
        document.getElementById('lnModalStats').textContent = n + ' lease' + (n === 1 ? '' : 's') +
            ' · AED ' + Math.round(amt).toLocaleString('en-US') + ' owed (bounced + overdue)';
        const g = G[group];
        document.getElementById('lnModalTitle').textContent = (g ? g[0] : 'Tenants who should get a notice') +
            (b ? ' · ' + building.options[building.selectedIndex].text : '');
        const ic = document.getElementById('lnModalIcon');
        ic.className = 'lw-mhead-ico lw-tone-' + (g ? g[2] : 'violet');
        ic.innerHTML = '<i class="bi ' + (g ? g[1] : 'bi-person-exclamation') + '"></i>';
        document.getElementById('lnRule').textContent = g ? 'Why listed: ' + g[3] : 'Each lease appears once, in the first group that fits.';
        let txt = 'Showing ' + n + ' of ' + rows.length + ' leases';
        if (q) txt += ' · "' + search.value.trim() + '"';
        shown.textContent = txt + ' ';
        if (group !== 'all' || b || q) {
            const r = document.createElement('a'); r.href = '#'; r.textContent = 'Reset filters';
            r.addEventListener('click', e => { e.preventDefault(); group = 'all'; search.value = ''; setBuilding(''); apply(); });
            shown.append(r);
        }
        chips.forEach(c => c.classList.toggle('is-active', c.dataset.group === group));
    }
    function setBuilding(id) {
        building.value = id;
        if (hasS2) jQuery(building).trigger('change.select2');
    }
    chips.forEach(c => c.addEventListener('click', () => { group = c.dataset.group; apply(); }));
    search.addEventListener('input', apply);
    search.addEventListener('keydown', e => { if (e.key === 'Escape') { search.value = ''; apply(); } });
    document.querySelectorAll('[data-sug-open]').forEach(b => b.addEventListener('click', () => {
        group = b.dataset.sugOpen;
        search.value = b.dataset.sugSearch || '';
        setBuilding('');
        apply();
        if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }));
    if (hasS2) {
        jQuery(building).select2({theme: 'bootstrap-5', width: '100%', placeholder: 'All buildings', allowClear: true,
                                  dropdownParent: jQuery(modalEl)}).on('change', apply);
    } else {
        building.addEventListener('change', apply);
    }

    document.getElementById('lnExport').addEventListener('click', () => {
        const head = ['Group','Suggested notice','Tenant','Phone','Email','Lease','Lease status','Building','Unit',
                      'Bounced AED','Overdue AED','Owed AED','Oldest debt (days)','Flags'];
        const esc = v => { v = v == null ? '' : String(v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
        const lines = [head.map(esc).join(',')];
        rows.filter(tr => !tr.classList.contains('d-none'))
            .forEach(tr => lines.push(JSON.parse(tr.dataset.csv).map(esc).join(',')));
        const blob = new Blob(['﻿' + lines.join('\n')], {type: 'text/csv;charset=utf-8'});
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'notices-to-send-' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
        URL.revokeObjectURL(a.href);
    });
    apply();
})();
</script>
JS;
require_once __DIR__ . '/includes/legal_layout_footer.php';
?>
