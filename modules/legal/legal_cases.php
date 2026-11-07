<?php
/**
 * Legal Department — Legal Cases (list).
 *
 * Top: case stats (from re_legal_cases) in the dashboard's alert strip.
 * Middle: "Leases that need a case" — leases with bounced cheques, ended with
 * a balance, or overdue more than 90 days, and no open case. Data comes from
 * includes/legal_lease_watch.php (same numbers as the Legal Dashboard).
 * Bottom: the cases themselves, with every filter of the old page.
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

// Filters
$statusFilter   = $_GET['status'] ?? 'all';
$typeFilter     = $_GET['case_type'] ?? 'all';
$sourceFilter   = $_GET['case_source'] ?? 'all';
$priorityFilter = $_GET['priority'] ?? 'all';
$buildingFilter = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : 0;
$assignedFilter = !empty($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : 0;
$deadlineFilter = $_GET['deadline'] ?? 'all'; // all | overdue | due_soon
$search         = trim($_GET['search'] ?? '');

$where = ["c.company_id = ?", "c.deleted_at IS NULL"];
$params = [$currentCompanyId];

if ($statusFilter !== 'all' && array_key_exists($statusFilter, legal_case_statuses())) {
    $where[] = "c.status = ?";
    $params[] = $statusFilter;
}
if ($typeFilter !== 'all' && array_key_exists($typeFilter, legal_case_types())) {
    $where[] = "c.case_type = ?";
    $params[] = $typeFilter;
}
if ($sourceFilter !== 'all' && array_key_exists($sourceFilter, legal_case_sources())) {
    $where[] = "c.case_source = ?";
    $params[] = $sourceFilter;
}
if ($priorityFilter !== 'all' && array_key_exists($priorityFilter, legal_priorities())) {
    $where[] = "c.priority = ?";
    $params[] = $priorityFilter;
}
if ($buildingFilter > 0) {
    $where[] = "c.building_id = ?";
    $params[] = $buildingFilter;
}
if ($assignedFilter > 0) {
    $where[] = "c.assigned_to = ?";
    $params[] = $assignedFilter;
}
if ($deadlineFilter === 'overdue') {
    $where[] = "c.deadline_date IS NOT NULL AND c.deadline_date < CURDATE() AND c.status NOT IN ('settled','closed','withdrawn')";
} elseif ($deadlineFilter === 'due_soon') {
    $where[] = "c.deadline_date IS NOT NULL AND c.deadline_date >= CURDATE() AND c.deadline_date <= DATE_ADD(CURDATE(), INTERVAL c.reminder_days_before DAY) AND c.status NOT IN ('settled','closed','withdrawn')";
}
if ($search !== '') {
    $where[] = "(c.case_number LIKE ? OR c.title LIKE ? OR l.lease_number LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ? OR t.company_name LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

$sql = "
    SELECT c.*,
           b.name AS building_name,
           u.unit_number,
           l.lease_number,
           t.first_name, t.last_name, t.company_name, t.tenant_type,
           o.name AS owner_name,
           COALESCE(NULLIF(usr.fullname,''), usr.username) AS assigned_name
    FROM re_legal_cases c
    LEFT JOIN re_buildings b ON b.id = c.building_id
    LEFT JOIN re_units u ON u.id = c.unit_id
    LEFT JOIN re_leases l ON l.id = c.lease_id
    LEFT JOIN re_tenants t ON t.id = c.tenant_id
    LEFT JOIN re_property_owners o ON o.id = c.owner_id
    LEFT JOIN user usr ON usr.id = c.assigned_to
    WHERE " . implode(' AND ', $where) . "
    ORDER BY
        (c.deadline_date IS NOT NULL AND c.deadline_date < CURDATE() AND c.status NOT IN ('settled','closed','withdrawn')) DESC,
        FIELD(c.priority,'urgent','high','medium','low'),
        c.created_at DESC
";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary stats (company-wide, not filtered, except deleted)
$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status NOT IN ('settled','closed','withdrawn') THEN 1 ELSE 0 END) AS open_cases,
        SUM(CASE WHEN deadline_date IS NOT NULL AND deadline_date < CURDATE() AND status NOT IN ('settled','closed','withdrawn') THEN 1 ELSE 0 END) AS overdue,
        SUM(CASE WHEN deadline_date IS NOT NULL AND deadline_date >= CURDATE() AND deadline_date <= DATE_ADD(CURDATE(), INTERVAL reminder_days_before DAY) AND status NOT IN ('settled','closed','withdrawn') THEN 1 ELSE 0 END) AS due_soon,
        COALESCE(SUM(CASE WHEN status NOT IN ('settled','closed','withdrawn') THEN claim_amount ELSE 0 END),0) AS open_claim,
        COALESCE(SUM(claim_amount),0) AS total_claim,
        COALESCE(SUM(recovered_amount),0) AS recovered
    FROM re_legal_cases
    WHERE company_id = ? AND deleted_at IS NULL
");
$statsStmt->execute([$currentCompanyId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$buildings = legal_fetch_buildings($conn, $currentCompanyId);
$assignableUsers = legal_fetch_assignable_users($conn, $currentCompanyId);

// ---- Lease data: which leases need a case ----
$watchRows = [];
$watchError = null;
try {
    $watchRows = legal_lease_watch_rows($conn, (int)$currentCompanyId);
} catch (Throwable $e) {
    $watchError = $e->getMessage();
}
$flagMeta = legal_watch_flag_meta();
$watchById = [];
foreach ($watchRows as $r) $watchById[(int)$r['id']] = $r;

// Categories: bounced (unpaid bounced cheque), ended (lease over, still owing),
// over90 (oldest overdue cheque more than 90 days old). Only leases with no open case.
$needCats = [
    'bounced' => ['Bounced', 'bi-x-octagon-fill'],
    'ended'   => ['Ended with balance', 'bi-hourglass-bottom'],
    'over90'  => ['Overdue 90+ days', 'bi-alarm-fill'],
    'notrenew'=> ['Expired, not renewed', 'bi-calendar-x-fill'],
];
$needRows = [];
$needCount = ['all' => 0, 'bounced' => 0, 'ended' => 0, 'over90' => 0, 'notrenew' => 0];
$needChq = 0;
$needAmt = 0.0;
$needBuildings = [];
foreach ($watchRows as $r) {
    if ((int)$r['open_cases'] > 0) continue;
    $cats = [];
    if ((int)$r['bounced_count'] > 0) $cats[] = 'bounced';
    if (in_array('ended_balance', $r['flags'], true)) $cats[] = 'ended';
    if ((int)$r['overdue_count'] > 0 && (int)$r['days_overdue'] > 90) $cats[] = 'over90';
    if (!empty($r['not_renewed'])) $cats[] = 'notrenew';
    if (!$cats) continue;
    $r['need_cats'] = $cats;
    $needRows[] = $r;
    $needCount['all']++;
    foreach ($cats as $k) $needCount[$k]++;
    $needChq += (int)$r['bounced_count'] + (int)$r['overdue_count'];
    $needAmt += (float)$r['outstanding'];
    $needBuildings[(int)$r['building_id']] = $r['building_name'];
}
asort($needBuildings);

$totalCases = (int)($stats['total'] ?? 0);
$openCases  = (int)($stats['open_cases'] ?? 0);
$overdueN   = (int)($stats['overdue'] ?? 0);
$dueSoonN   = (int)($stats['due_soon'] ?? 0);
$openClaim  = (float)($stats['open_claim'] ?? 0);
$totalClaim = (float)($stats['total_claim'] ?? 0);
$recovered  = (float)($stats['recovered'] ?? 0);
$recPct     = $totalClaim > 0 ? (int)floor($recovered / $totalClaim * 100) : 0;

$filtersOn = $statusFilter !== 'all' || $typeFilter !== 'all' || $sourceFilter !== 'all' || $priorityFilter !== 'all'
    || $buildingFilter > 0 || $assignedFilter > 0 || $deadlineFilter !== 'all' || $search !== '';
$activeFilterCount = count(array_filter([
    $statusFilter !== 'all', $typeFilter !== 'all', $sourceFilter !== 'all', $priorityFilter !== 'all',
    $buildingFilter > 0, $assignedFilter > 0, $deadlineFilter !== 'all', $search !== '',
]));

// ---- Page helpers ----
if (!function_exists('lc_case_url_for_lease')) {
    /** Same pre-filled "New case" link the Legal Dashboard builds. */
    function lc_case_url_for_lease(array $r): string
    {
        $claim = round((float)$r['outstanding'], 2);
        $isB = $r['bounced_count'] > 0;
        $isNr = !$isB && !empty($r['not_renewed']);
        return 'legal_case_add.php?' . http_build_query([
            'case_type' => $isB ? 'cheque_bounce' : ($isNr ? 'eviction' : 'rent_recovery'),
            'case_source' => $isB ? 'bounced_cheque' : ($isNr ? 'renewal' : 'manual'),
            'lease_id' => $r['id'], 'tenant_id' => $r['tenant_id'], 'building_id' => $r['building_id'], 'unit_id' => $r['unit_id'],
            'priority' => $isB || $isNr ? 'high' : null,
            'title' => ($isB ? 'Bounced cheques' : ($isNr ? 'Expired lease not renewed' : 'Rent arrears')) . ' – ' . $r['tenant_label'] . ' (' . $r['unit_label'] . ')',
            'claim_amount' => $claim > 0 ? $claim : null,
        ]);
    }
}

if (!function_exists('lc_deadline_html')) {
    function lc_deadline_html(array $c): string
    {
        if (empty($c['deadline_date'])) return '<span class="lw-muted">No deadline</span>';
        $state = legal_deadline_state($c['deadline_date'], (int)$c['reminder_days_before'], (string)$c['status']);
        $d = strtotime($c['deadline_date']);
        $days = (int)round(($d - strtotime('today')) / 86400);
        $date = '<div class="lc-dl-date">' . h(date('j M Y', $d)) . '</div>';
        if ($state === 'overdue') {
            return $date . '<span class="lc-dl lc-dl-late"><i class="bi bi-exclamation-triangle-fill"></i> ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' late</span>';
        }
        if ($state === 'due_soon') {
            return $date . '<span class="lc-dl lc-dl-soon"><i class="bi bi-hourglass-split"></i> ' . ($days === 0 ? 'Today' : 'In ' . $days . ' day' . ($days === 1 ? '' : 's')) . '</span>';
        }
        if (legal_status_is_terminal((string)$c['status'])) {
            return $date . '<span class="lc-dl lc-dl-done"><i class="bi bi-check2"></i> Case closed</span>';
        }
        return $date . '<span class="lc-dl lc-dl-ok"><i class="bi bi-calendar-check"></i> In ' . $days . ' days</span>';
    }
}

if (!function_exists('lc_case_row')) {
    /** One row of the cases table. */
    function lc_case_row(array $c, array $watchById, string $re): string
    {
        $statuses = legal_case_statuses();
        $prios = legal_priorities();
        $types = legal_case_types();
        $sources = legal_case_sources();
        $prioIcon = ['urgent' => 'bi-fire', 'high' => 'bi-arrow-up-circle-fill', 'medium' => 'bi-dash-circle-fill', 'low' => 'bi-arrow-down-circle'];
        $statusIcon = ['draft' => 'bi-pencil', 'open' => 'bi-folder2-open', 'notice_sent' => 'bi-envelope-paper', 'filed' => 'bi-file-earmark-text',
                       'in_hearing' => 'bi-bank2', 'judgment' => 'bi-hammer', 'execution' => 'bi-lightning-charge', 'settled' => 'bi-check2-circle',
                       'closed' => 'bi-lock', 'withdrawn' => 'bi-x-circle'];
        $tName = legal_tenant_name($c);
        $state = legal_deadline_state($c['deadline_date'] ?? null, (int)($c['reminder_days_before'] ?? 0), (string)$c['status']);
        $claim = (float)$c['claim_amount'];
        $rec = (float)$c['recovered_amount'];
        $pct = $claim > 0 ? max(0, min(100, (int)round($rec / $claim * 100))) : 0;
        $w = $c['lease_id'] ? ($watchById[(int)$c['lease_id']] ?? null) : null;
        $sev = $state === 'overdue' ? 'late' : ($c['priority'] === 'urgent' ? 'urgent' : ($c['priority'] === 'high' ? 'high' : 'none'));

        ob_start(); ?>
        <tr class="lc-row lc-sev-<?= h($sev) ?>">
            <td class="lc-case-no">
                <a href="legal_case_view.php?id=<?= (int)$c['id'] ?>"><?= h($c['case_number']) ?></a>
                <div class="lw-muted"><?= $c['opened_date'] ? 'Opened ' . h(date('j M Y', strtotime($c['opened_date']))) : 'Created ' . h(date('j M Y', strtotime($c['created_at']))) ?></div>
            </td>
            <td style="min-width:220px">
                <div class="lc-title"><?= h($c['title']) ?></div>
                <span class="lc-type"><?= h($types[$c['case_type']] ?? $c['case_type']) ?></span>
                <span class="lw-muted">· <?= h($sources[$c['case_source']] ?? $c['case_source']) ?></span>
            </td>
            <td class="text-nowrap">
                <span class="lc-pill lc-st-<?= h($c['status']) ?>"><i class="bi <?= h($statusIcon[$c['status']] ?? 'bi-circle') ?>"></i> <?= h($statuses[$c['status']] ?? $c['status']) ?></span><br>
                <span class="lc-pill lc-pr-<?= h($c['priority']) ?>"><i class="bi <?= h($prioIcon[$c['priority']] ?? 'bi-circle') ?>"></i> <?= h($prios[$c['priority']] ?? $c['priority']) ?></span>
            </td>
            <td style="min-width:210px">
                <?php if ($tName || $c['lease_number'] || $c['building_name']): ?>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($tName): ?><span class="lw-avatar lw-avatar-sm lw-av-<?= lw_avatar_tone($tName) ?>"><?= h(lw_initials($tName)) ?></span><?php endif; ?>
                    <div class="min-w-0">
                        <?php if ($tName): ?><div class="lw-tname text-truncate"><?= h($tName) ?></div><?php endif; ?>
                        <?php if ($c['lease_number']): ?>
                            <a class="lc-lease-link" href="<?= h($re) ?>lease_view.php?id=<?= (int)$c['lease_id'] ?>"><i class="bi bi-file-earmark-text"></i> <?= h($c['lease_number']) ?></a>
                        <?php endif; ?>
                        <?php if ($c['building_name']): ?><div class="lw-muted text-truncate"><?= h($c['building_name']) ?><?= $c['unit_number'] ? ' · Unit ' . h($c['unit_number']) : '' ?></div><?php endif; ?>
                        <?php if ($w && $w['outstanding'] > 0.005): ?>
                            <a class="lc-owes" href="<?= h($re) ?>lease_view.php?id=<?= (int)$w['id'] ?>#installments" title="Bounced + overdue cheques on this lease today">
                                <i class="bi bi-exclamation-circle"></i> Lease owes AED <?= h(lw_aed((float)$w['outstanding'])) ?> now</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?><span class="lw-muted">Not linked</span><?php endif; ?>
            </td>
            <td class="text-nowrap"><?= lc_deadline_html($c) ?></td>
            <td class="text-end" style="min-width:140px">
                <div class="lc-claim">AED <?= number_format($claim, 0) ?></div>
                <?php if ($claim > 0): ?>
                    <div class="lc-rec-track ms-auto"><span style="width:<?= $pct ?>%"></span></div>
                <?php endif; ?>
                <div class="lw-muted"><?= $rec > 0 ? 'Recovered AED ' . number_format($rec, 0) . ($claim > 0 ? ' · ' . $pct . '%' : '') : 'Nothing recovered' ?></div>
            </td>
            <td>
                <?php if ($c['assigned_name']): ?>
                    <span class="lc-assignee"><span class="lw-avatar lc-av-xs lw-av-<?= lw_avatar_tone((string)$c['assigned_name']) ?>"><?= h(lw_initials((string)$c['assigned_name'])) ?></span><?= h($c['assigned_name']) ?></span>
                <?php else: ?><span class="lw-muted">Unassigned</span><?php endif; ?>
            </td>
            <td class="text-end">
                <a href="legal_case_view.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm lc-view-btn"><i class="bi bi-eye"></i> View</a>
            </td>
        </tr>
        <?php return (string)ob_get_clean();
    }
}

$pageTitle = 'Legal Cases';
$cssVer = @filemtime(__DIR__ . '/assets/legal-cases.css') ?: 1;
$pageHead = '
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<link href="assets/legal-cases.css?v=' . $cssVer . '" rel="stylesheet">';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
<div class="lw-page lc-page">

    <div class="lw-head d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h1 class="mb-1">Legal Cases</h1>
            <div class="lw-sub">Cases on file and leases waiting for one · <?= h(date('l, j F Y')) ?></div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="legal_dashboard.php" class="btn lw-btn-soft"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a href="legal_notices.php" class="btn lw-btn-soft"><i class="bi bi-envelope-paper"></i> Notices</a>
            <a href="legal_case_add.php" class="btn lw-btn-grad"><i class="bi bi-plus-lg"></i> New Case</a>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
    <?php if ($watchError): ?><div class="alert alert-danger">Lease data could not be loaded: <?= h($watchError) ?></div><?php endif; ?>

    <!-- Stats strip -->
    <?php
    $segs = [
        ['rose', 'bi-briefcase', 'Leases need a case', (string)$needCount['all'],
            $needCount['all'] > 0 ? 'AED ' . lw_aed($needAmt) . ' owed' : 'Every flagged lease has a case',
            '#lcNeed', 'Leases with bounced cheques, ended with a balance, overdue more than 90 days, or expired and not renewed — and no open case'],
        ['violet', 'bi-folder2-open', 'Open cases', (string)$openCases,
            $totalCases > 0 ? 'of ' . $totalCases . ' case' . ($totalCases === 1 ? '' : 's') . ' on file' : 'No cases on file yet',
            '#lcCases', 'Cases not settled, closed or withdrawn'],
        ['amber', 'bi-alarm-fill', 'Overdue actions', (string)$overdueN,
            $overdueN > 0 ? 'Deadline passed · case open' : 'No deadline missed',
            'legal_cases.php?deadline=overdue#lcCases', 'Open cases whose deadline date has passed'],
        ['sky', 'bi-calendar2-week-fill', 'Due soon', (string)$dueSoonN,
            $dueSoonN > 0 ? 'Deadline within reminder days' : 'No deadline coming up',
            'legal_cases.php?deadline=due_soon#lcCases', 'Open cases whose deadline falls within the case\'s reminder window'],
        ['navy', 'bi-cash-stack', 'Open claims', 'AED ' . lw_aed($openClaim),
            $openCases > 0 ? 'Claimed on ' . $openCases . ' open case' . ($openCases === 1 ? '' : 's') : 'No open claims',
            null, 'Sum of the claim amount on open cases'],
        ['green', 'bi-arrow-down-circle-fill', 'Recovered', 'AED ' . lw_aed($recovered),
            $totalClaim > 0 ? $recPct . '% of AED ' . lw_aed($totalClaim) . ' claimed' : 'Nothing recovered yet',
            null, 'Recovered amount on all cases, open and closed'],
    ];
    ?>
    <div class="lw-strip mb-4">
        <?php foreach ($segs as [$tone, $icon, $label, $big, $sub, $href, $tip]):
            $zero = in_array($big, ['0', 'AED 0'], true); ?>
            <div class="lw-alert lw-seg lw-st-<?= h($tone) ?><?= $zero ? ' lc-zero' : '' ?>" title="<?= h($tip) ?>">
                <?php if ($href): ?><a class="lw-alert-btn" href="<?= h($href) ?>" aria-label="<?= h($label) ?>"></a><?php endif; ?>
                <span class="lw-seg-ico"><i class="bi <?= h($icon) ?>"></i></span>
                <div class="lw-seg-body">
                    <div class="lw-seg-label"><?= h($label) ?></div>
                    <div class="lw-seg-big"><?= h($big) ?></div>
                    <div class="lw-seg-sub"><?= h($sub) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Leases that need a case -->
    <div class="lw-panel mb-4" id="lcNeed">
        <div class="lw-panel-head flex-wrap">
            <div class="d-flex align-items-center gap-3 min-w-0">
                <span class="lw-sq lw-tone-pink"><i class="bi bi-briefcase"></i></span>
                <div class="min-w-0">
                    <h2>Leases that need a case</h2>
                    <div class="lc-need-stat" id="lcNeedStat">
                        <?= (int)$needCount['all'] ?> lease<?= $needCount['all'] === 1 ? '' : 's' ?> · <?= (int)$needChq ?> unpaid cheque<?= $needChq === 1 ? '' : 's' ?> · AED <?= number_format($needAmt, 0) ?> owed (bounced + overdue)
                    </div>
                </div>
            </div>
            <span class="lw-muted text-end">No open case yet · most urgent first<br>Overdue = cheque date passed, not cleared</span>
        </div>

        <?php if (!$needRows): ?>
            <div class="lw-empty-state"><i class="bi bi-shield-check"></i>Every lease with a bounced cheque, an unpaid balance after it ended, or rent overdue more than 90 days already has an open case.</div>
        <?php else: ?>
            <div class="d-flex justify-content-between align-items-end flex-wrap gap-3">
                <div class="lw-tabs mb-0 flex-wrap" role="tablist" id="lcNeedTabs">
                    <button type="button" class="lw-tab is-active" data-tab="all"><i class="bi bi-list-ul"></i> All <span>(<b><?= (int)$needCount['all'] ?></b>)</span></button>
                    <?php foreach ($needCats as $k => [$label, $icon]): ?>
                        <button type="button" class="lw-tab" data-tab="<?= h($k) ?>"><i class="bi <?= h($icon) ?>"></i> <?= h($label) ?> <span>(<b><?= (int)$needCount[$k] ?></b>)</span></button>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex gap-2 flex-wrap lc-need-tools">
                    <div style="width:230px">
                        <select id="lcNeedBuilding" class="form-select form-select-sm">
                            <option value="">All buildings</option>
                            <?php foreach ($needBuildings as $bid => $bname): ?>
                                <option value="<?= (int)$bid ?>"><?= h($bname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lw-search-box" style="width:250px">
                        <i class="bi bi-search"></i>
                        <input type="search" id="lcNeedSearch" placeholder="Tenant, lease, unit, phone">
                    </div>
                </div>
            </div>

            <div class="lc-need-list mt-3" id="lcNeedList">
                <table class="table lw-table lc-need-table mb-0">
                    <thead><tr>
                        <th>Tenant</th><th>Lease</th><th>Building / Unit</th><th>Why</th>
                        <th class="text-end">Owed</th><th class="text-end">Action</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($needRows as $r):
                        $chq = (int)$r['bounced_count'] + (int)$r['overdue_count'];
                        $sev = in_array('bounced', $r['need_cats'], true) || in_array('ended', $r['need_cats'], true) ? 3 : 2;
                        $searchTxt = strtolower(implode(' ', [$r['lease_number'], $r['tenant_label'], $r['building_name'], $r['unit_number'], $r['phone'], $r['email']]));
                    ?>
                        <tr class="lw-sev-<?= $sev ?>" data-cats="<?= h(implode(' ', $r['need_cats'])) ?>" data-building="<?= (int)$r['building_id'] ?>"
                            data-search="<?= h($searchTxt) ?>" data-owed="<?= round((float)$r['outstanding'], 2) ?>" data-chq="<?= $chq ?>">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="lw-avatar lw-avatar-sm lw-av-<?= lw_avatar_tone($r['tenant_label']) ?>"><?= h(lw_initials($r['tenant_label'])) ?><i class="lw-dot <?= $r['bounced_count'] > 0 ? 'red' : 'amber' ?>"></i></span>
                                    <div class="min-w-0">
                                        <div class="lw-tname"><?= h($r['tenant_label']) ?></div>
                                        <?php if ($r['phone']): ?><a class="lw-muted text-decoration-none" href="tel:<?= h($r['phone']) ?>"><i class="bi bi-telephone"></i> <?= h($r['phone']) ?></a><?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="lw-lease">
                                <a href="<?= h($re) ?>lease_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['lease_number'] ?: '#' . $r['id']) ?></a><br>
                                <span class="lw-status <?= h($r['status']) ?>"><?= h(str_replace('_', ' ', $r['status'])) ?></span>
                                <?php if ($r['end_date']): ?><span class="lw-muted ms-1"><?= $r['lease_ended'] ? 'ended' : 'to' ?> <?= h(date('j M y', strtotime($r['end_date']))) ?></span><?php endif; ?>
                            </td>
                            <td><?= h($r['building_name']) ?><br><span class="lw-muted">Unit <?= h($r['unit_number']) ?></span></td>
                            <td class="lc-why">
                                <?php foreach ($r['flags'] as $f): if ($f === 'due_soon') continue; [$fl, , $fi] = $flagMeta[$f]; ?>
                                    <span class="lw-flag lw-flag-<?= h($f) ?>"><i class="bi <?= h($fi) ?>"></i>
                                        <?= h($fl) ?><?php if ($f === 'bounced'): ?> ×<?= (int)$r['bounced_count'] ?><?php elseif ($f === 'overdue'): ?> · <?= (int)$r['days_overdue'] ?>d<?php elseif ($f === 'held'): ?> ×<?= (int)$r['held_count'] ?><?php elseif ($f === 'not_renewed'): ?> · <?= (int)$r['days_expired'] ?>d<?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <div class="lw-money lc-owed"><?= number_format((float)$r['outstanding'], 0) ?></div>
                                <span class="lw-muted">
                                    <?php if ($r['bounced_count'] > 0): ?><span class="lc-b"><?= (int)$r['bounced_count'] ?> bounced</span><?php endif; ?>
                                    <?php if ($r['bounced_count'] > 0 && $r['overdue_count'] > 0): ?> · <?php endif; ?>
                                    <?php if ($r['overdue_count'] > 0): ?><span class="lc-o"><?= (int)$r['overdue_count'] ?> overdue</span><?php endif; ?>
                                </span>
                            </td>
                            <td class="text-end text-nowrap lw-actions">
                                <a class="btn btn-outline-secondary" href="<?= h($re) ?>lease_view.php?id=<?= (int)$r['id'] ?>#installments" title="Open lease and cheques"><i class="bi bi-box-arrow-up-right"></i></a>
                                <a class="btn lc-open-case" href="<?= h(lc_case_url_for_lease($r)) ?>" title="Open a legal case for this lease (pre-filled)"><i class="bi bi-plus-lg"></i> Open case</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="lw-empty-state d-none" id="lcNeedEmpty"><i class="bi bi-search"></i>No leases match this filter.</div>
            </div>
            <div class="lc-need-foot lw-muted" id="lcNeedShown"></div>
        <?php endif; ?>
    </div>

    <!-- Cases -->
    <div class="lw-panel" id="lcCases">
        <div class="lw-panel-head flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="lw-sq lw-tone-violet"><i class="bi bi-folder2-open"></i></span>
                <div>
                    <h2>Cases on file</h2>
                    <div class="lc-need-stat">
                        <?php if ($filtersOn): ?>
                            Showing <?= count($cases) ?> of <?= $totalCases ?> case<?= $totalCases === 1 ? '' : 's' ?> · <?= $activeFilterCount ?> filter<?= $activeFilterCount === 1 ? '' : 's' ?> on
                        <?php else: ?>
                            <?= $totalCases ?> case<?= $totalCases === 1 ? '' : 's' ?> · <?= $openCases ?> open · AED <?= number_format($openClaim, 0) ?> claimed on open cases
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($filtersOn): ?><a href="legal_cases.php#lcCases" class="btn lw-btn-soft btn-sm"><i class="bi bi-x-circle"></i> Clear filters</a><?php endif; ?>
        </div>

        <form method="GET" action="legal_cases.php#lcCases" class="lc-filters row g-2 mb-3">
            <div class="col-xl-3 col-md-6">
                <label class="form-label">Search</label>
                <div class="lw-search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" value="<?= h($search) ?>" placeholder="Case #, title, tenant, lease">
                </div>
            </div>
            <div class="col-xl-2 col-md-3 col-6">
                <label class="form-label">Status</label>
                <select name="status" class="form-select form-select-sm lc-s2">
                    <option value="all">All</option>
                    <?php foreach (legal_case_statuses() as $k => $v): ?>
                        <option value="<?= h($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-xl-2 col-md-3 col-6">
                <label class="form-label">Type</label>
                <select name="case_type" class="form-select form-select-sm lc-s2">
                    <option value="all">All</option>
                    <?php foreach (legal_case_types() as $k => $v): ?>
                        <option value="<?= h($k) ?>" <?= $typeFilter === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-xl-2 col-md-3 col-6">
                <label class="form-label">Source</label>
                <select name="case_source" class="form-select form-select-sm lc-s2">
                    <option value="all">All</option>
                    <?php foreach (legal_case_sources() as $k => $v): ?>
                        <option value="<?= h($k) ?>" <?= $sourceFilter === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-xl-1 col-md-3 col-6">
                <label class="form-label">Priority</label>
                <select name="priority" class="form-select form-select-sm lc-s2">
                    <option value="all">All</option>
                    <?php foreach (legal_priorities() as $k => $v): ?>
                        <option value="<?= h($k) ?>" <?= $priorityFilter === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-xl-2 col-md-3 col-6">
                <label class="form-label">Deadline</label>
                <select name="deadline" class="form-select form-select-sm lc-s2">
                    <option value="all" <?= $deadlineFilter === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="overdue" <?= $deadlineFilter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                    <option value="due_soon" <?= $deadlineFilter === 'due_soon' ? 'selected' : '' ?>>Due soon</option>
                </select>
            </div>
            <div class="col-xl-3 col-md-4">
                <label class="form-label">Building</label>
                <select name="building_id" class="form-select form-select-sm lc-s2" data-placeholder="All Buildings">
                    <option value="">All Buildings</option>
                    <?php foreach ($buildings as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $buildingFilter === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-xl-3 col-md-4">
                <label class="form-label">Assigned To</label>
                <select name="assigned_to" class="form-select form-select-sm lc-s2" data-placeholder="Anyone">
                    <option value="">Anyone</option>
                    <?php foreach ($assignableUsers as $usr): ?>
                        <option value="<?= (int)$usr['id'] ?>" <?= $assignedFilter === (int)$usr['id'] ? 'selected' : '' ?>><?= h($usr['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-xl-6 col-md-4 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Filter</button>
                <a href="legal_cases.php#lcCases" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>

        <?php if (empty($cases)): ?>
            <?php if ($totalCases === 0): ?>
                <div class="lc-empty">
                    <span class="lc-empty-ico"><i class="bi bi-briefcase"></i></span>
                    <h3>No legal cases yet</h3>
                    <?php if ($needCount['all'] > 0): ?>
                        <p><?= (int)$needCount['all'] ?> lease<?= $needCount['all'] === 1 ? '' : 's' ?> with bounced or long-overdue cheques, or expired and not renewed, <?= $needCount['all'] === 1 ? 'is' : 'are' ?> waiting for one —
                            AED <?= number_format($needAmt, 0) ?> owed. Start with the most urgent lease in the list above; the case form is pre-filled.</p>
                        <div class="d-flex gap-2 justify-content-center flex-wrap">
                            <a href="#lcNeed" class="btn lw-btn-grad"><i class="bi bi-arrow-up"></i> See leases that need a case</a>
                            <a href="legal_case_add.php" class="btn lw-btn-soft"><i class="bi bi-plus-lg"></i> Blank case</a>
                        </div>
                    <?php else: ?>
                        <p>Nothing on file. Create a case when a tenant dispute starts.</p>
                        <a href="legal_case_add.php" class="btn lw-btn-grad"><i class="bi bi-plus-lg"></i> New Case</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="lw-empty-state"><i class="bi bi-funnel"></i>No cases match these filters. <a href="legal_cases.php#lcCases">Clear filters</a></div>
            <?php endif; ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table lw-table lc-cases-table mb-0">
                    <thead><tr>
                        <th>Case #</th><th>Title</th><th>Status / Priority</th><th>Lease / Tenant</th>
                        <th>Deadline</th><th class="text-end">Claim / Recovered</th><th>Assigned</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($cases as $c) echo lc_case_row($c, $watchById, $re); ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php
$pageScripts = <<<'JS'
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function () {
    const hasS2 = !!(window.jQuery && jQuery.fn.select2);

    // Filter form: every dropdown searchable.
    if (hasS2) {
        jQuery('.lc-s2').each(function () {
            const ph = this.dataset.placeholder;
            jQuery(this).select2({theme: 'bootstrap-5', width: '100%', placeholder: ph || undefined, allowClear: !!ph});
        });
    }

    // Leases that need a case: tabs + building + search.
    const list = document.getElementById('lcNeedList');
    if (!list) return;
    const rows = Array.from(list.querySelectorAll('tbody tr'));
    const tabs = document.querySelectorAll('#lcNeedTabs .lw-tab');
    const search = document.getElementById('lcNeedSearch');
    const building = document.getElementById('lcNeedBuilding');
    const stat = document.getElementById('lcNeedStat');
    const shown = document.getElementById('lcNeedShown');
    const empty = document.getElementById('lcNeedEmpty');
    const names = {all: 'All', bounced: 'Bounced', ended: 'Ended with balance', over90: 'Overdue 90+ days', notrenew: 'Expired, not renewed'};
    let tab = 'all';

    function apply() {
        const q = search.value.trim().toLowerCase();
        const b = building.value;
        const counts = {all: 0, bounced: 0, ended: 0, over90: 0, notrenew: 0};
        let n = 0, chq = 0, amt = 0;
        rows.forEach(tr => {
            const base = (!b || tr.dataset.building === b) && (!q || tr.dataset.search.includes(q));
            const cats = tr.dataset.cats.split(' ');
            if (base) { counts.all++; cats.forEach(c => counts[c]++); }
            const ok = base && (tab === 'all' || cats.includes(tab));
            tr.classList.toggle('d-none', !ok);
            if (ok) { n++; chq += +tr.dataset.chq; amt += parseFloat(tr.dataset.owed || 0); }
        });
        tabs.forEach(t => {
            t.classList.toggle('is-active', t.dataset.tab === tab);
            t.querySelector('b').textContent = counts[t.dataset.tab];
        });
        list.querySelector('table').classList.toggle('d-none', n === 0);
        empty.classList.toggle('d-none', n > 0);
        stat.textContent = n + ' lease' + (n === 1 ? '' : 's') + ' · ' + chq + ' unpaid cheque' + (chq === 1 ? '' : 's')
            + ' · AED ' + Math.round(amt).toLocaleString('en-US') + ' owed (bounced + overdue)';
        let txt = 'Showing ' + n + ' of ' + rows.length + ' leases';
        if (tab !== 'all') txt += ' · ' + names[tab];
        if (b) txt += ' · ' + building.options[building.selectedIndex].text;
        if (q) txt += ' · "' + search.value.trim() + '"';
        shown.textContent = txt + ' ';
        if (tab !== 'all' || b || q) {
            const r = document.createElement('a'); r.href = '#'; r.textContent = 'Reset';
            r.addEventListener('click', e => {
                e.preventDefault(); tab = 'all'; search.value = ''; building.value = '';
                if (hasS2) jQuery(building).trigger('change.select2');
                apply();
            });
            shown.append(r);
        }
    }
    tabs.forEach(t => t.addEventListener('click', () => { tab = t.dataset.tab; apply(); }));
    search.addEventListener('input', apply);
    search.addEventListener('keydown', e => { if (e.key === 'Escape') { search.value = ''; apply(); } });
    if (hasS2) {
        jQuery(building).select2({theme: 'bootstrap-5', width: '100%', placeholder: 'All buildings', allowClear: true})
            .on('change', apply);
    } else {
        building.addEventListener('change', apply);
    }
    apply();
})();
</script>
JS;
require_once __DIR__ . '/includes/legal_layout_footer.php';
?>
