<?php
/**
 * Legal Module — Cheque Notifications inbox.
 *
 * Requests raised by accounting from the Lease View page (bounced / escalated /
 * hold), with the acknowledge → resolve workflow, enriched with each lease's
 * current position (Lease Watch). Below: every bounced/returned cheque, with
 * whether it is still unrecovered.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/legal_helper.php';
require_once __DIR__ . '/includes/legal_lease_watch.php';
require_once __DIR__ . '/../realestate/includes/cheque_legal_helper.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$re = legal_re_path();
$hasTable = cheque_legal_escalations_table_exists($conn);

// ---- Workflow actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['escalation_action']) && $hasTable) {
    csrf_verify();
    $eid = (int)($_POST['escalation_id'] ?? 0);
    $act = (string)$_POST['escalation_action'];
    if ($eid > 0) {
        try {
            if ($act === 'acknowledge') {
                $conn->prepare("UPDATE re_legal_cheque_escalations SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = NOW() WHERE id = ? AND company_id = ?")
                    ->execute([current_user_id(), $eid, $currentCompanyId]);
                $_SESSION['success'] = 'Marked as acknowledged.';
            } elseif ($act === 'resolve') {
                $conn->prepare("UPDATE re_legal_cheque_escalations SET status = 'resolved', resolved_by = ?, resolved_at = NOW() WHERE id = ? AND company_id = ?")
                    ->execute([current_user_id(), $eid, $currentCompanyId]);
                $_SESSION['success'] = 'Marked as resolved.';
            } elseif ($act === 'reopen') {
                $conn->prepare("UPDATE re_legal_cheque_escalations SET status = 'new', resolved_by = NULL, resolved_at = NULL WHERE id = ? AND company_id = ?")
                    ->execute([$eid, $currentCompanyId]);
                $_SESSION['success'] = 'Re-opened.';
            }
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Could not update: ' . $e->getMessage();
        }
    }
    $back = in_array($_POST['return_status'] ?? '', ['open', 'all'], true) ? $_POST['return_status'] : 'open';
    header('Location: legal_cheque_notifications.php?status=' . $back);
    exit;
}

$filter = ($_GET['status'] ?? 'open') === 'all' ? 'all' : 'open'; // open | all

// ---- Page helpers (unique prefix) ----
if (!function_exists('cn_ago')) {
    function cn_ago(?string $dt): string
    {
        if (!$dt) return '—';
        $ts = strtotime($dt);
        if (!$ts) return '—';
        $days = (int)floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $ts))) / 86400);
        if ($days <= 0) {
            $mins = (int)floor((time() - $ts) / 60);
            if ($mins < 60) return max(1, $mins) . ' min ago';
            return (int)floor($mins / 60) . ' h ago';
        }
        if ($days === 1) return 'yesterday';
        if ($days < 30) return $days . ' days ago';
        if ($days < 365) { $m = (int)floor($days / 30); return $m . ' month' . ($m > 1 ? 's' : '') . ' ago'; }
        $y = (int)floor($days / 365);
        return $y . ' year' . ($y > 1 ? 's' : '') . ' ago';
    }
}
if (!function_exists('cn_case_url')) {
    /** Pre-filled "new case" link, same shape as the dashboard's $caseUrl. */
    function cn_case_url(array $p): string
    {
        $claim = round((float)($p['claim'] ?? 0), 2);
        return 'legal_case_add.php?' . http_build_query([
            'case_source' => 'bounced_cheque',
            'primary_cheque_id' => !empty($p['cheque_id']) ? (int)$p['cheque_id'] : null,
            'lease_id' => (int)$p['lease_id'],
            'tenant_id' => $p['tenant_id'] ? (int)$p['tenant_id'] : null,
            'building_id' => $p['building_id'] ? (int)$p['building_id'] : null,
            'unit_id' => $p['unit_id'] ? (int)$p['unit_id'] : null,
            'priority' => 'high',
            'title' => 'Bounced cheque' . (!empty($p['cheque_number']) ? ' ' . $p['cheque_number'] : '')
                . ' – ' . $p['tenant_label'] . (trim((string)$p['unit_label']) !== '' ? ' (' . $p['unit_label'] . ')' : ''),
            'claim_amount' => $claim > 0 ? $claim : null,
        ]);
    }
}
if (!function_exists('cn_cheque_state')) {
    /** Where a cheque stands today: [key, label, owed]. Same rules as Lease Watch. */
    function cn_cheque_state(array $c): array
    {
        $amt  = (float)$c['cheque_amount'];
        $got  = (float)$c['got'];
        $owed = max(0.0, $amt - $got);
        $bad  = in_array($c['status'], ['bounced', 'returned'], true);
        $paid = in_array($c['status'], ['cleared', 'paid', 'deposited'], true) || $got >= $amt - 0.005;
        if ($paid) return ['cleared', $c['status'] === 'cleared' ? 'Cleared since' : 'Collected since', 0.0];
        if ($bad && !empty($c['replaced_by_cheque_id'])) return ['replaced', 'Replaced by a new cheque', 0.0];
        if ($bad && !empty($c['settlement_payment_id'])) return ['replaced', 'Settled by payment', 0.0];
        if ($bad) return ['unrecovered', 'Still bounced', $owed];
        if ($c['status'] === 'cancelled') return ['other', 'Cancelled', 0.0];
        if ($c['status'] === 'legal_escalated') return ['legal', 'Escalated to legal', $owed];
        if ($c['status'] === 'held_by_finance') return ['held', 'Held by finance', $owed];
        if ($c['cheque_date'] && $c['cheque_date'] < date('Y-m-d')) return ['overdue', 'Overdue', $owed];
        return ['other', 'Pending', $owed];
    }
}

// ---- Lease Watch (current position of every lease) ----
$watchByLease = [];
$watchError = null;
try {
    foreach (legal_lease_watch_rows($conn, (int)$currentCompanyId) as $w) {
        $watchByLease[(int)$w['id']] = $w;
    }
} catch (Throwable $e) {
    $watchError = $e->getMessage();
}
$tot = legal_lease_watch_totals(array_values($watchByLease));
$flagMeta = legal_watch_flag_meta();

// ---- Cheques: every bounced/returned cheque + every cheque a request points at ----
$cheques = [];
try {
    $hasLinks = (bool)$conn->query("SHOW TABLES LIKE 're_receipt_cheque_links'")->fetchColumn();
    $collSql = "SELECT p.cheque_id, p.amount AS amt FROM re_payments p WHERE p.company_id = :c1 AND p.cheque_id IS NOT NULL";
    if ($hasLinks) {
        $collSql .= " UNION ALL SELECT k.cheque_id, k.amount_applied AS amt FROM re_receipt_cheque_links k WHERE k.company_id = :c2";
    }
    $reqIds = $hasTable
        ? " OR c.id IN (SELECT e.cheque_id FROM re_legal_cheque_escalations e WHERE e.company_id = :c5 AND e.cheque_id IS NOT NULL)"
        : '';
    $sql = "
        SELECT c.id, c.lease_id, c.status, c.cheque_number, c.cheque_amount, c.cheque_date, c.bounced_date, c.bounced_reason,
               c.replaced_by_cheque_id, c.settlement_payment_id,
               LEAST(COALESCE(col.got, 0), c.cheque_amount) AS got,
               l.lease_number, l.unit_id, u.unit_number, b.id AS building_id, b.name AS building_name,
               t.id AS tenant_id, t.first_name, t.last_name, t.company_name, t.tenant_type, t.phone,
               (" . re_lease_not_deleted_sql($conn, 'l') . " AND b.name NOT LIKE '%test building%') AS in_scope
        FROM re_post_dated_cheques c
        JOIN re_leases l     ON l.id = c.lease_id
        JOIN re_units u      ON u.id = l.unit_id
        JOIN re_buildings b  ON b.id = u.building_id
        JOIN re_tenants t    ON t.id = l.tenant_id
        LEFT JOIN (SELECT cheque_id, SUM(amt) AS got FROM ({$collSql}) x GROUP BY cheque_id) col ON col.cheque_id = c.id
        WHERE c.company_id = :c3 AND l.company_id = :c4
          AND (c.status IN ('bounced','returned'){$reqIds})
    ";
    $params = [':c1' => $currentCompanyId, ':c3' => $currentCompanyId, ':c4' => $currentCompanyId];
    if ($hasLinks) $params[':c2'] = $currentCompanyId;
    if ($hasTable) $params[':c5'] = $currentCompanyId;
    $st = $conn->prepare($sql);
    $st->execute($params);
    while ($c = $st->fetch(PDO::FETCH_ASSOC)) {
        [$c['state'], $c['state_label'], $c['owed']] = cn_cheque_state($c);
        $c['tenant_label'] = legal_tenant_name($c);
        $cheques[(int)$c['id']] = $c;
    }
} catch (Throwable $e) {
    $watchError = $watchError ?: $e->getMessage();
}

// ---- Requests from accounting (all of them; the Open/All toggle filters on screen) ----
$escalations = [];
$counts = ['new' => 0, 'acknowledged' => 0, 'resolved' => 0];
if ($hasTable) {
    $stmt = $conn->prepare("
        SELECT e.*, l.lease_number, l.unit_id, l.tenant_id AS lease_tenant_id,
               TRIM(CONCAT(COALESCE(t.first_name,''),' ',COALESCE(t.last_name,''))) AS tenant_name,
               t.first_name, t.last_name, t.company_name, t.tenant_type, t.phone,
               u.unit_number, b.id AS building_id, b.name AS building_name,
               cu.fullname AS raised_by
        FROM re_legal_cheque_escalations e
        LEFT JOIN re_leases l ON l.id = e.lease_id
        LEFT JOIN re_tenants t ON t.id = COALESCE(e.tenant_id, l.tenant_id)
        LEFT JOIN re_units u ON u.id = l.unit_id
        LEFT JOIN re_buildings b ON b.id = u.building_id
        LEFT JOIN `user` cu ON cu.id = e.created_by
        WHERE e.company_id = ?
        ORDER BY FIELD(e.status, 'new', 'acknowledged', 'resolved'), e.created_at DESC
    ");
    $stmt->execute([$currentCompanyId]);
    $escalations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$reqByCheque = [];
$typeCount = ['bounced' => 0, 'escalated' => 0, 'hold' => 0];
$escOpen = ['escalated' => 0, 'escalated_amt' => 0.0];
$newAmt = 0.0;
$oldestNew = null;
$ins = ['owed' => [0, []], 'ready' => [0], 'ended' => [0, []]];
$insOwedAmt = [];
$months = [];
for ($m = 11; $m >= 0; $m--) {
    $months[date('Y-m', strtotime("first day of -$m months"))] = ['req' => 0, 'bounced' => 0];
}
foreach ($escalations as &$e) {
    $counts[$e['status']] = ($counts[$e['status']] ?? 0) + 1;
    $typeCount[$e['type']] = ($typeCount[$e['type']] ?? 0) + 1;
    $e['tenant_label'] = !empty($e['first_name']) || !empty($e['company_name']) ? legal_tenant_name($e) : '—';
    $e['tid'] = (int)($e['tenant_id'] ?: $e['lease_tenant_id']);
    $e['unit_label'] = trim((string)$e['building_name'] . ' ' . (string)$e['unit_number']);
    $e['watch'] = $watchByLease[(int)$e['lease_id']] ?? null;
    $e['chq'] = !empty($e['cheque_id']) ? ($cheques[(int)$e['cheque_id']] ?? null) : null;
    $open = $e['status'] !== 'resolved';
    $w = $e['watch'];
    $e['ins'] = [];
    if ($w && $w['outstanding'] > 0.005) $e['ins'][] = 'owed';
    if ($e['chq'] && in_array($e['chq']['state'], ['cleared', 'replaced'], true)) $e['ins'][] = 'ready';
    if ($w && in_array('ended_balance', $w['flags'], true)) $e['ins'][] = 'ended';
    if ($open) {
        foreach ($e['ins'] as $k) $ins[$k][0]++;
        if (in_array('owed', $e['ins'], true)) $insOwedAmt[(int)$e['lease_id']] = (float)$w['outstanding'];
        if (in_array('ended', $e['ins'], true)) $ins['ended'][1][(int)$e['lease_id']] = (float)$w['outstanding'];
        if ($e['type'] === 'escalated') { $escOpen['escalated']++; }
    }
    if ($e['type'] === 'escalated') $escOpen['escalated_amt'] += (float)$e['cheque_amount'];
    if ($e['status'] === 'new') {
        $newAmt += (float)$e['cheque_amount'];
        if (!$oldestNew || $e['created_at'] < $oldestNew) $oldestNew = $e['created_at'];
    }
    $k = substr((string)$e['created_at'], 0, 7);
    if (isset($months[$k])) $months[$k]['req']++;
    if (!empty($e['cheque_id']) && !isset($reqByCheque[(int)$e['cheque_id']])) {
        $reqByCheque[(int)$e['cheque_id']] = $e; // newest first per status order; first seen wins
    }
}
unset($e);
$openCount = $counts['new'] + $counts['acknowledged'];

// ---- Bounced cheques (in Lease Watch scope) ----
$bounced = array_values(array_filter($cheques, fn($c) => $c['in_scope'] && in_array($c['status'], ['bounced', 'returned'], true)));
// Most recent bounce first; cheques with no bounce date recorded go after, newest cheque date first.
usort($bounced, fn($a, $b) => (empty($a['bounced_date']) <=> empty($b['bounced_date']))
    ?: strcmp((string)($b['bounced_date'] ?: $b['cheque_date']), (string)($a['bounced_date'] ?: $a['cheque_date']))
    ?: $b['id'] <=> $a['id']);
$unrec = array_values(array_filter($bounced, fn($c) => $c['state'] === 'unrecovered'));
$unrecAmt = array_sum(array_column($unrec, 'owed'));
$unrecLeases = count(array_unique(array_column($unrec, 'lease_id')));
$bBuildings = [];
foreach ($bounced as $c) {
    $bBuildings[(int)$c['building_id']] = $c['building_name'];
    $k = substr((string)($c['bounced_date'] ?: $c['cheque_date']), 0, 7);
    if (isset($months[$k])) $months[$k]['bounced']++;
}
asort($bBuildings);
$rBuildings = [];
foreach ($escalations as $e) if ($e['building_id']) $rBuildings[(int)$e['building_id']] = $e['building_name'];
asort($rBuildings);

$chart = [
    'labels'  => array_map(fn($k) => date('M y', strtotime($k . '-01')), array_keys($months)),
    'req'     => array_column(array_values($months), 'req'),
    'bounced' => array_column(array_values($months), 'bounced'),
];
$req12 = array_sum($chart['req']);
$bnc12 = array_sum($chart['bounced']);

$typeMeta = [
    'bounced'   => ['Bounced', 'bi-x-octagon-fill'],
    'escalated' => ['Escalated', 'bi-bank2'],
    'hold'      => ['Hold', 'bi-pause-circle-fill'],
];
$statusMeta = [
    'new'          => ['New', 'bi-envelope-exclamation-fill'],
    'acknowledged' => ['Acknowledged', 'bi-eye-fill'],
    'resolved'     => ['Resolved', 'bi-check2-circle'],
];
$stateTone = ['unrecovered' => 'red', 'legal' => 'violet', 'overdue' => 'amber', 'held' => 'slate', 'cleared' => 'green', 'replaced' => 'green', 'other' => 'slate'];

/** Lease position chips for one Lease Watch row. */
$leaseFlags = function (?array $w) use ($flagMeta): string {
    if (!$w) return '<span class="lw-muted"><i class="bi bi-info-circle"></i> Lease not in Lease Watch</span>';
    $out = '';
    if ($w['bounced_count'] > 0) {
        $out .= '<span class="lw-flag lw-flag-bounced" title="Unrecovered bounced cheques on this lease"><i class="bi bi-x-octagon-fill"></i> Bounced ×' . (int)$w['bounced_count'] . ' · AED ' . h(lw_aed((float)$w['bounced_amt'])) . '</span>';
    }
    if ($w['overdue_count'] > 0) {
        $out .= '<span class="lw-flag lw-flag-overdue" title="' . (int)$w['overdue_count'] . ' cheque(s) past their date, not cleared"><i class="bi bi-alarm-fill"></i> Overdue AED ' . h(lw_aed((float)$w['overdue_amt'])) . ' · ' . (int)$w['days_overdue'] . 'd</span>';
    }
    if (in_array('ended_balance', $w['flags'], true)) {
        $out .= '<span class="lw-flag lw-flag-ended_balance" title="Lease ended with money still owed"><i class="bi bi-hourglass-bottom"></i> Ended with balance</span>';
    }
    if ($w['held_count'] > 0) {
        $out .= '<span class="lw-flag lw-flag-held"><i class="bi bi-pause-circle-fill"></i> Held ×' . (int)$w['held_count'] . '</span>';
    }
    if (in_array('legal', $w['flags'], true)) {
        $out .= '<span class="lw-flag lw-flag-legal"><i class="bi bi-bank2"></i> With legal' . ($w['open_cases'] > 0 ? ' · ' . (int)$w['open_cases'] . ' case' : '') . '</span>';
    }
    if ($out === '') $out = '<span class="cn-clear"><i class="bi bi-check-circle-fill"></i> Lease is clear now</span>';
    return $out;
};

$pageTitle = 'Cheque Notifications';
$cssV = @filemtime(__DIR__ . '/assets/legal-cheque-notifications.css') ?: 1;
$pageHead = '
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<link href="assets/legal-cheque-notifications.css?v=' . $cssV . '" rel="stylesheet">';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
<div class="lw-page cn-page">

    <div class="lw-head d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h1 class="mb-1">Cheque Notifications</h1>
            <div class="lw-sub">Requests from accounting and bounced cheques · <?= h(date('l, j F Y')) ?></div>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <div class="cn-toggle" role="group" aria-label="Show open or all requests">
                <a href="?status=open" class="cn-toggle-btn <?= $filter === 'open' ? 'is-active' : '' ?>" data-toggle-status="open">
                    <i class="bi bi-inbox"></i> Open <span><?= (int)$openCount ?></span></a>
                <a href="?status=all" class="cn-toggle-btn <?= $filter === 'all' ? 'is-active' : '' ?>" data-toggle-status="all">
                    <i class="bi bi-collection"></i> All <span><?= count($escalations) ?></span></a>
            </div>
            <a href="legal_dashboard.php" class="btn lw-btn-soft"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a href="legal_case_add.php?case_source=bounced_cheque" class="btn lw-btn-grad"><i class="bi bi-plus-lg"></i> New Case</a>
        </div>
    </div>

    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i> <?= h($_SESSION['success']); unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?= h($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>
    <?php if ($watchError): ?>
        <div class="alert alert-danger">Lease data could not be loaded: <?= h($watchError) ?></div>
    <?php endif; ?>
    <?php if (!$hasTable): ?>
        <div class="alert alert-warning">
            The legal notification inbox table is not installed yet. Run
            <code>migrations/add_cheque_hold_and_legal_escalations.sql</code> to enable escalation tracking.
            Bounced cheques are still listed below.
        </div>
    <?php endif; ?>

    <!-- Strip: every tile filters the list below to exactly the number it shows -->
    <?php
    $tiles = [
        ['amber', 'bi-envelope-exclamation-fill', (string)$counts['new'], 'New requests',
            ($counts['new'] ? 'AED ' . lw_aed($newAmt) . ' · since ' . date('j M', strtotime($oldestNew)) : 'Nothing waiting'),
            ['req' => 'new'], 'Requests from accounting nobody in legal has acknowledged yet', null, ''],
        ['sky', 'bi-eye-fill', (string)$counts['acknowledged'], 'Acknowledged', 'Seen, not resolved yet',
            ['req' => 'acknowledged'], 'Requests legal has acknowledged but not resolved', null, ''],
        ['navy', 'bi-check2-all', (string)$counts['resolved'], 'Resolved', 'Closed by legal',
            ['req' => 'resolved'], 'Requests marked resolved', null, ''],
        ['rose', 'bi-x-octagon-fill', (string)count($unrec), 'Bounced cheques',
            'AED ' . lw_aed($unrecAmt) . ' owed · ' . $unrecLeases . ' lease' . ($unrecLeases === 1 ? '' : 's'),
            ['bounced' => 'unrecovered'], 'Bounced/returned cheques not replaced, not settled and not fully collected', null, ''],
        ['violet', 'bi-bank2', (string)$typeCount['escalated'], 'Escalated to legal',
            $typeCount['escalated'] . ' request' . ($typeCount['escalated'] === 1 ? '' : 's') . ' · ' . $escOpen['escalated'] . ' still open',
            ['type' => 'escalated'], 'Requests accounting raised as "escalate to legal" (all statuses)', null, ''],
    ];
    ?>
    <div class="lw-strip mb-4" id="cnStrip">
        <?php foreach ($tiles as [$tone, $icon, $big, $label, $sub, $go, $tip, $spark, $sparkTip]): ?>
            <div class="lw-alert lw-seg lw-st-<?= h($tone) ?>" title="<?= h($tip) ?>">
                <button type="button" class="lw-alert-btn" data-go='<?= h(json_encode($go)) ?>' aria-label="<?= h($label) ?>: show list"></button>
                <span class="lw-seg-ico"><i class="bi <?= h($icon) ?>"></i></span>
                <div class="lw-seg-body">
                    <div class="lw-seg-label"><?= h($label) ?></div>
                    <div class="lw-seg-big"><?= h($big) ?></div>
                    <div class="lw-seg-sub"><?= h($sub) ?></div>
                </div>
                <?php if ($spark): ?><span class="lw-seg-spark" title="<?= h($sparkTip) ?>"><?= lw_spark(array_slice($spark, -6)) ?></span><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="lw-panel h-100">
                <div class="lw-panel-head">
                    <div>
                        <h2>Requests &amp; bounces by month</h2>
                        <div class="lw-muted">Requests raised by accounting vs cheques that bounced (by bounce date), last 12 months</div>
                    </div>
                    <div class="text-end">
                        <div class="lw-hero-pct"><?= (int)$req12 ?></div>
                        <div class="lw-muted">requests · <?= (int)$bnc12 ?> bounces in 12 months</div>
                    </div>
                </div>
                <div class="lw-chart-box" style="height:260px"><canvas id="cnChart" aria-label="Requests raised and cheques bounced per month"></canvas></div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="lw-panel h-100">
                <div class="lw-panel-head">
                    <h2>Behind the<br>open requests</h2>
                    <span class="lw-muted text-end">What the leases<br>say today</span>
                </div>
                <?php
                $owedAmt = array_sum($insOwedAmt);
                $endedAmt = array_sum($ins['ended'][1]);
                $rings = [
                    ['owed', 'pink', $ins['owed'][0], 'Lease still owes', 'AED ' . number_format($owedAmt, 0) . ' bounced + overdue across ' . count($insOwedAmt) . ' leases'],
                    ['ended', 'violet', $ins['ended'][0], 'Lease ended, still owes', 'AED ' . number_format($endedAmt, 0) . ' across ' . count($ins['ended'][1]) . ' leases — recover first'],
                    ['ready', 'green', $ins['ready'][0], 'Cheque since cleared', 'Cheque cleared, replaced or settled — can likely be resolved'],
                ];
                foreach ($rings as [$key, $tone, $n, $label, $sub]): ?>
                    <div class="lw-ring-row lw-tone-<?= $tone ?>">
                        <span class="lw-ring"><span><?= (int)$n ?></span></span>
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-bold"><?= h($label) ?></div>
                            <div class="lw-muted"><?= h($sub) ?></div>
                        </div>
                        <button type="button" class="lw-detail-btn" data-go='<?= h(json_encode(['req' => 'open', 'ins' => $key])) ?>'><i class="bi bi-funnel"></i> Show</button>
                    </div>
                <?php endforeach; ?>
                <div class="lw-muted mt-2">Counts are open requests (new + acknowledged).</div>
            </div>
        </div>
    </div>

    <!-- Inbox -->
    <div class="lw-panel mb-4 cn-inbox" id="cnInbox">
        <div class="lw-panel-head mb-3">
            <div>
                <h2><i class="bi bi-inbox-fill cn-h-ico"></i> Requests from accounting</h2>
                <div class="lw-muted" id="cnShown"></div>
            </div>
            <div class="d-flex gap-2 flex-wrap align-items-center cn-tools">
                <div style="width:220px"><select id="cnBuilding" class="form-select form-select-sm">
                    <option value="">All buildings</option>
                    <?php foreach ($rBuildings as $bid => $bn): ?><option value="<?= (int)$bid ?>"><?= h($bn) ?></option><?php endforeach; ?>
                </select></div>
                <div style="width:190px"><select id="cnSort" class="form-select form-select-sm">
                    <option value="new">Newest raised first</option>
                    <option value="old">Oldest raised first</option>
                    <option value="amt">Largest cheque first</option>
                    <option value="owed">Lease owes most first</option>
                </select></div>
                <div class="lw-search-box" style="width:250px"><i class="bi bi-search"></i>
                    <input type="search" id="cnSearch" placeholder="Tenant, lease, cheque no, unit"></div>
            </div>
        </div>

        <div class="cn-chipbar">
            <div class="lw-chips" id="cnReqChips">
                <?php foreach (['open' => ['Open', 'bi-inbox'], 'new' => ['New', 'bi-envelope-exclamation'], 'acknowledged' => ['Acknowledged', 'bi-eye'],
                                'resolved' => ['Resolved', 'bi-check2-circle'], 'all' => ['All', 'bi-collection']] as $k => [$l, $ic]): ?>
                    <button type="button" class="lw-chip" data-req="<?= $k ?>"><i class="bi <?= $ic ?>"></i> <?= $l ?> <span class="lw-count">0</span></button>
                <?php endforeach; ?>
            </div>
            <span class="cn-chip-sep"></span>
            <div class="lw-chips" id="cnTypeChips">
                <button type="button" class="lw-chip" data-type="all">All types <span class="lw-count">0</span></button>
                <?php foreach ($typeMeta as $k => [$l, $ic]): ?>
                    <button type="button" class="lw-chip" data-type="<?= $k ?>"><i class="bi <?= $ic ?>"></i> <?= $l ?> <span class="lw-count">0</span></button>
                <?php endforeach; ?>
            </div>
            <span class="cn-ins-tag d-none" id="cnInsTag"></span>
        </div>

        <?php if (!$escalations): ?>
            <div class="lw-empty-state"><i class="bi bi-inbox"></i>No requests from accounting yet.</div>
        <?php endif; ?>

        <?php foreach ($statusMeta as $sk => [$sl, $sic]): ?>
        <div class="cn-group" data-group="<?= $sk ?>">
            <div class="cn-group-head"><span class="cn-st <?= $sk ?>"><i class="bi <?= $sic ?>"></i> <?= $sl ?></span><span class="cn-group-n">0</span></div>
            <div class="cn-list">
            <?php foreach ($escalations as $e): if ($e['status'] !== $sk) continue;
                $w = $e['watch']; $c = $e['chq'];
                $chqState = $c ? $c['state'] : null;
                $tone = $chqState ? $stateTone[$chqState] : 'slate';
                $caseUrl = cn_case_url([
                    'cheque_id' => $e['cheque_id'], 'cheque_number' => $e['cheque_number'], 'lease_id' => $e['lease_id'],
                    'tenant_id' => $e['tid'], 'building_id' => $e['building_id'], 'unit_id' => $e['unit_id'],
                    'tenant_label' => $e['tenant_label'], 'unit_label' => $e['unit_label'],
                    'claim' => $w && $w['outstanding'] > 0.005 ? $w['outstanding'] : ($c['owed'] ?? 0),
                ]);
                $search = strtolower(implode(' ', [$e['tenant_label'], $e['lease_number'], $e['cheque_number'], $e['unit_label'], $e['reason'], $e['phone'], $e['raised_by']]));
            ?>
                <article class="cn-item cn-edge-<?= $tone ?>" data-status="<?= h($e['status']) ?>" data-type="<?= h($e['type']) ?>"
                         data-building="<?= (int)$e['building_id'] ?>" data-ins="<?= h(implode(' ', $e['ins'])) ?>"
                         data-created="<?= (int)strtotime($e['created_at']) ?>" data-amt="<?= round((float)$e['cheque_amount'], 2) ?>"
                         data-owed="<?= $w ? round((float)$w['outstanding'], 2) : 0 ?>" data-search="<?= h($search) ?>">
                    <div class="cn-who">
                        <span class="lw-avatar lw-avatar-sm lw-av-<?= lw_avatar_tone($e['tenant_label']) ?>"><?= h(lw_initials($e['tenant_label'])) ?></span>
                        <div class="min-w-0">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="cn-type <?= h($e['type']) ?>"><i class="bi <?= h($typeMeta[$e['type']][1] ?? 'bi-flag') ?>"></i> <?= h($typeMeta[$e['type']][0] ?? ucfirst($e['type'])) ?></span>
                                <span class="lw-tname text-truncate"><?= h($e['tenant_label']) ?></span>
                            </div>
                            <div class="cn-sub">
                                <a href="<?= h($re) ?>lease_view.php?id=<?= (int)$e['lease_id'] ?>#installments" class="cn-lease"><?= h($e['lease_number'] ?: ('#' . $e['lease_id'])) ?></a>
                                <?php if ($e['unit_label'] !== ''): ?><span>· <i class="bi bi-building"></i> <?= h($e['unit_label']) ?></span><?php endif; ?>
                                <?php if (!empty($e['phone'])): ?><a class="lw-muted text-decoration-none" href="tel:<?= h($e['phone']) ?>">· <i class="bi bi-telephone"></i> <?= h($e['phone']) ?></a><?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="cn-chq">
                        <div class="cn-chq-top">
                            <i class="bi bi-receipt"></i>
                            <?php if (!empty($e['cheque_id'])): ?>
                                <a href="<?= h($re) ?>billing_cheque_view.php?id=<?= (int)$e['cheque_id'] ?>"><?= h($e['cheque_number'] ?: ('#' . $e['cheque_id'])) ?></a>
                            <?php else: ?><span><?= h($e['cheque_number'] ?: 'No cheque') ?></span><?php endif; ?>
                            <?php if (!empty($e['cheque_date'])): ?><span class="lw-muted">· <?= h(date('j M Y', strtotime($e['cheque_date']))) ?></span><?php endif; ?>
                        </div>
                        <div class="cn-amt"><?= $e['cheque_amount'] !== null ? 'AED ' . number_format((float)$e['cheque_amount'], 2) : '—' ?></div>
                        <?php if ($c): ?>
                            <span class="cn-now <?= $stateTone[$chqState] ?>" title="Where this cheque stands today">
                                <i class="bi <?= in_array($chqState, ['cleared', 'replaced'], true) ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>"></i>
                                <?= h($c['state_label']) ?><?= $c['owed'] > 0.005 ? ' · AED ' . h(number_format($c['owed'], 0)) . ' owed' : '' ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="cn-ctx">
                        <div class="cn-flags"><?= $leaseFlags($w) ?></div>
                        <?php if (trim((string)$e['reason']) !== ''): ?>
                            <button type="button" class="cn-reason" title="<?= h($e['reason']) ?>"><i class="bi bi-chat-left-text"></i> <span><?= h($e['reason']) ?></span></button>
                        <?php else: ?>
                            <span class="lw-muted"><i class="bi bi-chat-left"></i> No reason given</span>
                        <?php endif; ?>
                    </div>

                    <div class="cn-side">
                        <div class="cn-when">
                            <span class="cn-st <?= h($e['status']) ?>"><i class="bi <?= h($statusMeta[$e['status']][1] ?? 'bi-circle') ?>"></i> <?= h($statusMeta[$e['status']][0] ?? ucfirst($e['status'])) ?></span>
                            <div class="lw-muted" title="<?= h(date('j M Y H:i', strtotime($e['created_at']))) ?>">
                                Raised <?= h(cn_ago($e['created_at'])) ?><?= $e['raised_by'] ? ' by ' . h(explode(' ', trim($e['raised_by']))[0]) : '' ?>
                                <?php if ($e['status'] === 'acknowledged' && $e['acknowledged_at']): ?><br>Ack'd <?= h(cn_ago($e['acknowledged_at'])) ?><?php endif; ?>
                                <?php if ($e['status'] === 'resolved' && $e['resolved_at']): ?><br>Resolved <?= h(cn_ago($e['resolved_at'])) ?><?php endif; ?>
                            </div>
                        </div>
                        <div class="cn-acts">
                            <?php if ($e['status'] === 'new'): ?>
                                <form method="post" class="m-0"><?php csrf_field(); ?>
                                    <input type="hidden" name="escalation_id" value="<?= (int)$e['id'] ?>">
                                    <input type="hidden" name="escalation_action" value="acknowledge">
                                    <input type="hidden" name="return_status" value="<?= h($filter) ?>" class="cn-ret">
                                    <button class="cn-act ack" title="Acknowledge — legal has seen it"><i class="bi bi-eye"></i> Acknowledge</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($e['status'] !== 'resolved'): ?>
                                <form method="post" class="m-0"><?php csrf_field(); ?>
                                    <input type="hidden" name="escalation_id" value="<?= (int)$e['id'] ?>">
                                    <input type="hidden" name="escalation_action" value="resolve">
                                    <input type="hidden" name="return_status" value="<?= h($filter) ?>" class="cn-ret">
                                    <button class="cn-act resolve" title="Resolve"><i class="bi bi-check2"></i> Resolve</button>
                                </form>
                            <?php else: ?>
                                <form method="post" class="m-0"><?php csrf_field(); ?>
                                    <input type="hidden" name="escalation_id" value="<?= (int)$e['id'] ?>">
                                    <input type="hidden" name="escalation_action" value="reopen">
                                    <input type="hidden" name="return_status" value="<?= h($filter) ?>" class="cn-ret">
                                    <button class="cn-act reopen" title="Re-open"><i class="bi bi-arrow-counterclockwise"></i> Reopen</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!empty($e['legal_case_id'])): ?>
                                <a class="cn-act case" href="legal_case_view.php?id=<?= (int)$e['legal_case_id'] ?>"><i class="bi bi-briefcase"></i> View case</a>
                            <?php elseif ($w && $w['open_cases'] > 0): ?>
                                <a class="cn-act case" href="legal_cases.php?search=<?= h(urlencode((string)$e['lease_number'])) ?>"><i class="bi bi-briefcase"></i> View case</a>
                            <?php else: ?>
                                <a class="cn-act case" href="<?= h($caseUrl) ?>" title="Open a legal case pre-filled from this request"><i class="bi bi-plus-lg"></i> Open case</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="lw-empty-state d-none" id="cnEmpty"><i class="bi bi-check2-circle"></i>No requests match this filter.</div>
    </div>

    <!-- All bounced cheques -->
    <div class="lw-panel mb-4" id="cnBounced">
        <div class="lw-panel-head mb-3">
            <div>
                <h2><i class="bi bi-x-octagon-fill cn-h-ico red"></i> All bounced cheques</h2>
                <div class="lw-muted" id="cnBShown"></div>
            </div>
            <div class="d-flex gap-2 flex-wrap align-items-center cn-tools">
                <div style="width:220px"><select id="cnBBuilding" class="form-select form-select-sm">
                    <option value="">All buildings</option>
                    <?php foreach ($bBuildings as $bid => $bn): ?><option value="<?= (int)$bid ?>"><?= h($bn) ?></option><?php endforeach; ?>
                </select></div>
                <div class="lw-search-box" style="width:250px"><i class="bi bi-search"></i>
                    <input type="search" id="cnBSearch" placeholder="Tenant, lease, cheque no, unit"></div>
            </div>
        </div>
        <div class="lw-chips mb-3" id="cnBChips">
            <button type="button" class="lw-chip" data-b="unrecovered"><i class="bi bi-exclamation-octagon"></i> Unrecovered <span class="lw-count">0</span></button>
            <button type="button" class="lw-chip" data-b="recovered"><i class="bi bi-check2-circle"></i> Recovered (replaced, settled or collected) <span class="lw-count">0</span></button>
            <button type="button" class="lw-chip" data-b="all"><i class="bi bi-list-ul"></i> All bounced/returned <span class="lw-count">0</span></button>
        </div>
        <?php if (!$bounced): ?>
            <div class="lw-empty-state"><i class="bi bi-emoji-smile"></i>No bounced cheques.</div>
        <?php else: ?>
        <div class="table-responsive cn-btable">
            <table class="table lw-table align-middle mb-0" id="cnBTable">
                <thead><tr>
                    <th>Bounced</th><th>Tenant, lease &amp; unit</th><th>Cheque</th>
                    <th>Now &amp; request</th><th>Lease position</th><th>Reason</th><th class="text-end">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($bounced as $c):
                    $w = $watchByLease[(int)$c['lease_id']] ?? null;
                    $unitLabel = trim($c['building_name'] . ' ' . $c['unit_number']);
                    $rq = $reqByCheque[(int)$c['id']] ?? null;
                    $bd = $c['bounced_date'] ?: null;
                    $caseUrl = cn_case_url([
                        'cheque_id' => $c['id'], 'cheque_number' => $c['cheque_number'], 'lease_id' => $c['lease_id'],
                        'tenant_id' => $c['tenant_id'], 'building_id' => $c['building_id'], 'unit_id' => $c['unit_id'],
                        'tenant_label' => $c['tenant_label'], 'unit_label' => $unitLabel,
                        'claim' => $w && $w['outstanding'] > 0.005 ? $w['outstanding'] : $c['owed'],
                    ]);
                    $isUn = $c['state'] === 'unrecovered';
                    $search = strtolower(implode(' ', [$c['tenant_label'], $c['lease_number'], $c['cheque_number'], $unitLabel, $c['bounced_reason'], $c['phone']]));
                ?>
                    <tr class="<?= $isUn ? 'lw-sev-3' : '' ?>" data-state="<?= $isUn ? 'unrecovered' : 'recovered' ?>" data-building="<?= (int)$c['building_id'] ?>"
                        data-owed="<?= round((float)$c['owed'], 2) ?>" data-lease="<?= (int)$c['lease_id'] ?>" data-search="<?= h($search) ?>">
                        <td class="text-nowrap">
                            <?php if ($bd): ?><div class="fw-semibold"><?= h(date('j M Y', strtotime($bd))) ?></div><span class="lw-muted"><?= h(cn_ago($bd)) ?></span>
                            <?php else: ?><span class="lw-muted" title="No bounce date recorded on the cheque">No bounce date</span><?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="lw-avatar lw-avatar-sm lw-av-<?= lw_avatar_tone($c['tenant_label']) ?>"><?= h(lw_initials($c['tenant_label'])) ?></span>
                                <div class="min-w-0">
                                    <div class="lw-tname cn-tcell" title="<?= h($c['tenant_label']) ?>"><?= h($c['tenant_label']) ?></div>
                                    <a class="cn-lease" href="<?= h($re) ?>lease_view.php?id=<?= (int)$c['lease_id'] ?>#installments"><?= h($c['lease_number'] ?: ('#' . $c['lease_id'])) ?></a>
                                    <div class="lw-muted cn-tcell"><i class="bi bi-building"></i> <?= h($unitLabel) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="text-nowrap"><a href="<?= h($re) ?>billing_cheque_view.php?id=<?= (int)$c['id'] ?>"><?= h($c['cheque_number'] ?: ('#' . $c['id'])) ?></a>
                            <div class="lw-money">AED <?= number_format((float)$c['cheque_amount'], 2) ?></div>
                            <?php if (!empty($c['cheque_date'])): ?><span class="lw-muted">dated <?= h(date('j M Y', strtotime($c['cheque_date']))) ?></span><?php endif; ?></td>
                        <td><span class="cn-now <?= $stateTone[$c['state']] ?>"><i class="bi <?= $isUn ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill' ?>"></i>
                            <?= $isUn ? 'Unrecovered · AED ' . h(number_format($c['owed'], 0)) : h($c['state_label']) ?></span>
                            <div class="mt-1 text-nowrap">
                            <?php if ($rq): ?><span class="lw-muted">Request</span> <span class="cn-st <?= h($rq['status']) ?>" title="Request from accounting for this cheque"><i class="bi <?= h($statusMeta[$rq['status']][1]) ?>"></i> <?= h($statusMeta[$rq['status']][0]) ?></span>
                            <?php else: ?><span class="lw-muted" title="Accounting has not raised a request for this cheque"><i class="bi bi-dash-circle"></i> No request raised</span><?php endif; ?>
                            </div></td>
                        <td style="max-width:210px"><div class="cn-flags"><?= $leaseFlags($w) ?></div></td>
                        <td class="cn-rcell">
                            <?php if (trim((string)$c['bounced_reason']) !== ''): ?>
                                <button type="button" class="cn-reason" title="<?= h($c['bounced_reason']) ?>"><span><?= h($c['bounced_reason']) ?></span></button>
                            <?php else: ?><span class="lw-muted">—</span><?php endif; ?>
                        </td>
                        <td class="text-end lw-actions text-nowrap">
                            <a class="btn btn-outline-secondary" href="<?= h($re) ?>lease_view.php?id=<?= (int)$c['lease_id'] ?>#installments" title="Open lease and cheques"><i class="bi bi-box-arrow-up-right"></i></a>
                            <?php if ($w && $w['open_cases'] > 0): ?>
                                <a class="btn btn-outline-primary" href="legal_cases.php?search=<?= h(urlencode((string)$c['lease_number'])) ?>" title="View legal case"><i class="bi bi-briefcase"></i> Case</a>
                            <?php else: ?>
                                <a class="btn cn-btn-case" href="<?= h($caseUrl) ?>" title="Open a legal case pre-filled with this cheque"><i class="bi bi-plus-lg"></i> Open case</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="lw-empty-state d-none" id="cnBEmpty"><i class="bi bi-check2-circle"></i>No bounced cheques match this filter.</div>
        </div>
        <div class="lw-muted mt-2">Bounced = bounced or returned, not replaced, not settled and not fully collected. Test buildings and deleted leases are left out, as on the Leases page.</div>
        <?php endif; ?>
    </div>

</div>

<?php
$pageScripts = '<script>window.CN_CHART = ' . json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP) . ';window.CN_INIT = ' . json_encode($filter) . ';</script>' . <<<'JS'
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const hasS2 = !!(window.jQuery && jQuery.fn.select2);
    const $ = s => document.querySelector(s), $$ = s => Array.from(document.querySelectorAll(s));
    const money = v => 'AED ' + Math.round(v).toLocaleString('en-US');

    // ---------- Inbox ----------
    const items = $$('.cn-item');
    const S = {req: window.CN_INIT === 'all' ? 'all' : 'open', type: 'all', ins: '', q: '', b: '', sort: 'new'};
    const insWords = {owed: 'lease still owes', ended: 'lease ended, still owes', ready: 'cheque since cleared'};
    const reqOk = (it, v) => v === 'all' || (v === 'open' ? it.dataset.status !== 'resolved' : it.dataset.status === v);
    function match(it, o) {
        const s = Object.assign({}, S, o || {});
        return reqOk(it, s.req) && (s.type === 'all' || it.dataset.type === s.type)
            && (!s.ins || (' ' + it.dataset.ins + ' ').includes(' ' + s.ins + ' '))
            && (!s.b || it.dataset.building === s.b) && (!s.q || it.dataset.search.includes(s.q));
    }
    function sortItems() {
        const key = {new: it => -it.dataset.created, old: it => +it.dataset.created,
                     amt: it => -it.dataset.amt, owed: it => -it.dataset.owed}[S.sort];
        $$('.cn-list').forEach(list => Array.from(list.children).sort((a, b) => key(a) - key(b)).forEach(x => list.append(x)));
    }
    function applyInbox() {
        let n = 0, amt = 0;
        items.forEach(it => { const ok = match(it); it.classList.toggle('d-none', !ok); if (ok) { n++; amt += +it.dataset.amt; } });
        $$('.cn-group').forEach(g => {
            const k = g.querySelectorAll('.cn-item:not(.d-none)').length;
            g.querySelector('.cn-group-n').textContent = k + ' request' + (k === 1 ? '' : 's');
            g.classList.toggle('d-none', k === 0);
        });
        $$('#cnReqChips .lw-chip').forEach(c => {
            c.classList.toggle('is-active', c.dataset.req === S.req);
            c.querySelector('.lw-count').textContent = items.filter(it => match(it, {req: c.dataset.req})).length;
        });
        $$('#cnTypeChips .lw-chip').forEach(c => {
            c.classList.toggle('is-active', c.dataset.type === S.type);
            c.querySelector('.lw-count').textContent = items.filter(it => match(it, {type: c.dataset.type})).length;
        });
        $$('[data-toggle-status]').forEach(a => a.classList.toggle('is-active', a.dataset.toggleStatus === S.req));
        $$('.cn-ret').forEach(i => i.value = S.req === 'all' ? 'all' : 'open');
        const tag = $('#cnInsTag');
        tag.classList.toggle('d-none', !S.ins);
        if (S.ins) tag.innerHTML = '<i class="bi bi-funnel-fill"></i> ' + insWords[S.ins] + ' <button type="button" aria-label="Clear">&times;</button>';
        $('#cnEmpty').classList.toggle('d-none', n > 0 || !items.length);
        let txt = 'Showing ' + n + ' of ' + items.length + ' requests' + (n ? ' · cheques worth ' + money(amt) : '');
        if (S.b) { const o = $('#cnBuilding'); txt += ' · ' + o.options[o.selectedIndex].text; }
        if (S.q) txt += ' · "' + S.q + '"';
        $('#cnShown').textContent = txt;
        // Strip: highlight the tile that matches the current filter.
        $$('#cnStrip .lw-seg').forEach(seg => {
            const g = JSON.parse(seg.querySelector('.lw-alert-btn').dataset.go);
            seg.classList.toggle('is-active', (g.req && g.req === S.req && S.type === 'all' && !S.ins)
                || (g.type && g.type === S.type && S.req === 'all' && !S.ins) || (g.bounced && B.f === 'unrecovered' && B.flash));
        });
    }
    function setSel(el, v) { el.value = v; if (hasS2) jQuery(el).trigger('change.select2'); }

    // ---------- Bounced table ----------
    const bRows = $$('#cnBTable tbody tr');
    const B = {f: 'unrecovered', q: '', b: '', flash: false};
    const bMatch = (tr, o) => { const s = Object.assign({}, B, o || {});
        return (s.f === 'all' || tr.dataset.state === s.f) && (!s.b || tr.dataset.building === s.b) && (!s.q || tr.dataset.search.includes(s.q)); };
    function applyBounced() {
        let n = 0, owed = 0; const leases = new Set();
        bRows.forEach(tr => { const ok = bMatch(tr); tr.classList.toggle('d-none', !ok);
            if (ok) { n++; owed += +tr.dataset.owed; leases.add(tr.dataset.lease); } });
        $$('#cnBChips .lw-chip').forEach(c => {
            c.classList.toggle('is-active', c.dataset.b === B.f);
            c.querySelector('.lw-count').textContent = bRows.filter(tr => bMatch(tr, {f: c.dataset.b})).length;
        });
        const em = $('#cnBEmpty'); if (em) em.classList.toggle('d-none', n > 0);
        const sh = $('#cnBShown');
        if (sh) sh.textContent = n + ' cheque' + (n === 1 ? '' : 's') + ' on ' + leases.size + ' lease' + (leases.size === 1 ? '' : 's')
            + (owed > 0 ? ' · ' + money(owed) + ' unrecovered' : '') + ' · most recent bounce first';
    }

    function go(g) {
        if (g.bounced) {
            B.f = g.bounced; B.q = ''; B.b = ''; B.flash = true;
            const s = $('#cnBSearch'); if (s) s.value = '';
            const bb = $('#cnBBuilding'); if (bb) setSel(bb, '');
            applyBounced(); applyInbox();
            const p = $('#cnBounced'); p.scrollIntoView({behavior: 'smooth', block: 'start'});
            p.classList.remove('cn-flash'); void p.offsetWidth; p.classList.add('cn-flash');
            return;
        }
        B.flash = false;
        S.req = g.req || 'all'; S.type = g.type || 'all'; S.ins = g.ins || ''; S.q = ''; S.b = '';
        $('#cnSearch').value = ''; setSel($('#cnBuilding'), '');
        applyInbox();
        const p = $('#cnInbox'); p.scrollIntoView({behavior: 'smooth', block: 'start'});
        p.classList.remove('cn-flash'); void p.offsetWidth; p.classList.add('cn-flash');
    }
    $$('[data-go]').forEach(b => b.addEventListener('click', () => go(JSON.parse(b.dataset.go))));
    $$('[data-toggle-status]').forEach(a => a.addEventListener('click', e => {
        e.preventDefault(); S.req = a.dataset.toggleStatus; B.flash = false; applyInbox();
        try { history.replaceState(null, '', '?status=' + S.req); } catch (x) {}
    }));
    $$('#cnReqChips .lw-chip').forEach(c => c.addEventListener('click', () => { S.req = c.dataset.req; B.flash = false; applyInbox(); }));
    $$('#cnTypeChips .lw-chip').forEach(c => c.addEventListener('click', () => { S.type = c.dataset.type; B.flash = false; applyInbox(); }));
    $('#cnInsTag').addEventListener('click', e => { if (e.target.closest('button')) { S.ins = ''; applyInbox(); } });
    $('#cnSearch').addEventListener('input', e => { S.q = e.target.value.trim().toLowerCase(); applyInbox(); });
    $$('#cnBChips .lw-chip').forEach(c => c.addEventListener('click', () => { B.f = c.dataset.b; B.flash = false; applyBounced(); applyInbox(); }));
    const bs = $('#cnBSearch'); if (bs) bs.addEventListener('input', e => { B.q = e.target.value.trim().toLowerCase(); applyBounced(); });

    // Reason: click to expand / collapse the full text.
    $$('.cn-reason').forEach(r => r.addEventListener('click', () => r.classList.toggle('is-open')));

    const s2 = (el, cb) => {
        if (!el) return;
        if (hasS2) jQuery(el).select2({theme: 'bootstrap-5', width: '100%', placeholder: el.options[0].text, allowClear: el.id !== 'cnSort'})
            .on('change', () => cb(el.value));
        else el.addEventListener('change', () => cb(el.value));
    };
    s2($('#cnBuilding'), v => { S.b = v; applyInbox(); });
    s2($('#cnSort'), v => { S.sort = v || 'new'; sortItems(); });
    s2($('#cnBBuilding'), v => { B.b = v; applyBounced(); });

    applyBounced(); sortItems(); applyInbox();

    // ---------- Chart ----------
    if (!window.Chart || !window.CN_CHART) return;
    const D = window.CN_CHART;
    Chart.defaults.font.family = "'Plus Jakarta Sans', system-ui, sans-serif";
    Chart.defaults.color = '#52514e';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 8;
    Chart.defaults.plugins.legend.labels.boxHeight = 8;
    Chart.defaults.plugins.tooltip.backgroundColor = '#111827';
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 8;
    new Chart(document.getElementById('cnChart'), {
        data: {
            labels: D.labels,
            datasets: [
                {type: 'bar', label: 'Requests raised by accounting', data: D.req, backgroundColor: '#2a78d6',
                 borderRadius: 6, borderSkipped: false, maxBarThickness: 30, order: 2},
                {type: 'line', label: 'Cheques bounced', data: D.bounced, borderColor: '#e34948', backgroundColor: '#e34948',
                 borderWidth: 2.5, tension: .35, pointRadius: 3.5, pointHoverRadius: 6, pointBackgroundColor: '#fff',
                 pointBorderColor: '#e34948', pointBorderWidth: 2, order: 1},
            ],
        },
        options: {
            maintainAspectRatio: false,
            interaction: {mode: 'index', intersect: false},
            plugins: {legend: {position: 'top', align: 'end'},
                      tooltip: {callbacks: {label: c => ' ' + c.dataset.label + ': ' + c.parsed.y + (c.datasetIndex ? ' cheques' : ' requests')}}},
            scales: {
                x: {grid: {display: false}, border: {display: false}},
                y: {beginAtZero: true, grid: {color: '#eceef2'}, border: {display: false}, ticks: {precision: 0}},
            },
        },
    });
})();
</script>
JS;
require_once __DIR__ . '/includes/legal_layout_footer.php';
?>
