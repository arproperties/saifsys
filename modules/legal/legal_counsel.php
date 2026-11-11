<?php
/**
 * Legal Department — External Counsel.
 *
 * Counsel cards (with open cases, fees and last case from re_legal_cases /
 * re_legal_case_costs), a workload chart when there is data, and a
 * "Ready to hand to counsel" list built from lease data (see
 * includes/legal_lease_watch.php): leases with no open legal case that have
 * a bounced cheque not replaced or paid, a cheque overdue more than 90 days,
 * or ended with money still owed.
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
$counselTypes = legal_counsel_types();

$flash = $_SESSION['success'] ?? '';
unset($_SESSION['success']);

// ---- Counsel ----
$stmt = $conn->prepare("SELECT * FROM re_legal_counsel WHERE company_id = ? ORDER BY is_active DESC, name");
$stmt->execute([$currentCompanyId]);
$list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$closedStatuses = ['settled', 'closed', 'withdrawn'];
$caseAgg = [];          // counsel_id => totals
$lastCase = [];         // counsel_id => newest case
$openCasesAll = 0;
$openNoCounsel = 0;
$feeAgg = [];           // counsel_id => fees
$dataError = null;
try {
    $cs = $conn->prepare("
        SELECT counsel_id,
               COUNT(*) AS total_cases,
               SUM(status NOT IN ('settled','closed','withdrawn')) AS open_cases,
               SUM(CASE WHEN status NOT IN ('settled','closed','withdrawn') THEN claim_amount ELSE 0 END) AS open_claim,
               SUM(recovered_amount) AS recovered
        FROM re_legal_cases
        WHERE company_id = ? AND deleted_at IS NULL
        GROUP BY counsel_id");
    $cs->execute([$currentCompanyId]);
    foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $openCasesAll += (int)$a['open_cases'];
        if ($a['counsel_id'] === null) {
            $openNoCounsel += (int)$a['open_cases'];
            continue;
        }
        $caseAgg[(int)$a['counsel_id']] = $a;
    }

    $ls = $conn->prepare("
        SELECT id, counsel_id, case_number, title, status, COALESCE(opened_date, DATE(created_at)) AS case_date
        FROM re_legal_cases
        WHERE company_id = ? AND deleted_at IS NULL AND counsel_id IS NOT NULL
        ORDER BY COALESCE(opened_date, DATE(created_at)) DESC, id DESC");
    $ls->execute([$currentCompanyId]);
    while ($c = $ls->fetch(PDO::FETCH_ASSOC)) {
        $lastCase[(int)$c['counsel_id']] ??= $c;
    }

    // Fees: every case cost booked to the counsel — the cost's own counsel,
    // or (when the cost names none) the counsel on its case.
    $fs = $conn->prepare("
        SELECT COALESCE(k.counsel_id, c.counsel_id) AS cid,
               SUM(k.amount) AS fees,
               SUM(CASE WHEN k.cost_type = 'counsel_fee' THEN k.amount ELSE 0 END) AS counsel_fees,
               COUNT(*) AS cost_rows
        FROM re_legal_case_costs k
        LEFT JOIN re_legal_cases c ON c.id = k.case_id AND c.company_id = k.company_id
        WHERE k.company_id = ? AND (c.id IS NULL OR c.deleted_at IS NULL)
        GROUP BY COALESCE(k.counsel_id, c.counsel_id)");
    $fs->execute([$currentCompanyId]);
    foreach ($fs->fetchAll(PDO::FETCH_ASSOC) as $f) {
        if ($f['cid'] !== null) $feeAgg[(int)$f['cid']] = $f;
    }
} catch (Throwable $e) {
    $dataError = $e->getMessage();
}

$activeCount = 0;
$openWithCounsel = 0;
$feesTotal = 0.0;
$chart = ['labels' => [], 'open' => [], 'total' => [], 'fees' => [], 'counsel_fees' => []];
foreach ($list as &$c) {
    $id = (int)$c['id'];
    $c['open_cases']   = (int)($caseAgg[$id]['open_cases'] ?? 0);
    $c['total_cases']  = (int)($caseAgg[$id]['total_cases'] ?? 0);
    $c['open_claim']   = (float)($caseAgg[$id]['open_claim'] ?? 0);
    $c['fees']         = (float)($feeAgg[$id]['fees'] ?? 0);
    $c['counsel_fees'] = (float)($feeAgg[$id]['counsel_fees'] ?? 0);
    $c['last_case']    = $lastCase[$id] ?? null;
    if ($c['is_active']) $activeCount++;
    $openWithCounsel += $c['open_cases'];
    $feesTotal += $c['fees'];
    if ($c['total_cases'] > 0 || $c['fees'] > 0) {
        $chart['labels'][]       = $c['name'];
        $chart['open'][]         = $c['open_cases'];
        $chart['total'][]        = $c['total_cases'];
        $chart['fees'][]         = round($c['fees']);
        $chart['counsel_fees'][] = round($c['counsel_fees']);
    }
}
unset($c);
$hasChart = count($chart['labels']) > 0;

// ---- Ready to hand to counsel (lease data) ----
$watchRows = [];
$ins = ['open' => []];
try {
    $watchRows = legal_lease_watch_rows($conn, (int)$currentCompanyId);
    $ins = legal_watch_cheque_insights($conn, (int)$currentCompanyId);
} catch (Throwable $e) {
    $dataError = $dataError ?: $e->getMessage();
}
$flagMeta = legal_watch_flag_meta();

$chequesByLease = [];
foreach ($ins['open'] as $chq) $chequesByLease[(int)$chq['lease_id']][] = $chq;

$ready = [];
$readyCheques = [];
foreach ($watchRows as $r) {
    if ($r['open_cases'] > 0) continue;
    $reasons = [];
    if ($r['bounced_count'] > 0) $reasons[] = 'bounced';
    if ($r['overdue_count'] > 0 && $r['days_overdue'] > 90) $reasons[] = 'overdue90';
    if (in_array('ended_balance', $r['flags'], true)) $reasons[] = 'ended';
    if (!empty($r['not_renewed'])) $reasons[] = 'notrenew';
    if (!$reasons) continue;

    $chqs = $chequesByLease[(int)$r['id']] ?? [];
    $oldest = 0; $over180 = 0.0;
    foreach ($chqs as $chq) {
        $oldest = max($oldest, (int)$chq['age_days']);
        if ($chq['age_days'] > 180) $over180 += (float)$chq['owed'];
        $readyCheques[] = $chq;
    }
    $claim = round((float)$r['outstanding'], 2);
    $r['reasons'] = $reasons;
    $r['oldest']  = $oldest;
    $r['over180'] = $over180;
    $isNr = $r['bounced_count'] <= 0 && !empty($r['not_renewed']);
    $r['case_url'] = 'legal_case_add.php?' . http_build_query([
        'case_source'  => $r['bounced_count'] > 0 ? 'bounced_cheque' : ($isNr ? 'renewal' : 'manual'),
        'case_type'    => $r['bounced_count'] > 0 ? 'cheque_bounce' : ($isNr ? 'eviction' : 'rent_recovery'),
        'lease_id'     => $r['id'], 'tenant_id' => $r['tenant_id'],
        'building_id'  => $r['building_id'], 'unit_id' => $r['unit_id'],
        'priority'     => ($r['bounced_count'] > 0 || $oldest > 180 || $isNr) ? 'high' : null,
        'title'        => ($r['bounced_count'] > 0 ? 'Bounced cheques' : ($isNr ? 'Expired lease not renewed' : 'Rent arrears')) . ' – ' . $r['tenant_label'] . ' (' . $r['unit_label'] . ')',
        'claim_amount' => $claim > 0 ? $claim : null,
    ]);
    $ready[] = $r;
}
$readyCount = count($ready);
$readyOwed = array_sum(array_map(fn($r) => (float)$r['outstanding'], $ready));
$readyBounced = count(array_filter($ready, fn($r) => in_array('bounced', $r['reasons'], true)));
$readyOver90 = count(array_filter($ready, fn($r) => in_array('overdue90', $r['reasons'], true)));
$readyEnded  = count(array_filter($ready, fn($r) => in_array('ended', $r['reasons'], true)));
$readyNotRenewed = count(array_filter($ready, fn($r) => in_array('notrenew', $r['reasons'], true)));

$readyAge = legal_watch_ageing($readyCheques);
$readyAgeTotals = array_map(fn($v) => $v['bounced'] + $v['overdue'], $readyAge);
$readyAgeSum = array_sum($readyAgeTotals);
$ready180 = (float)($readyAgeTotals['180+'] ?? 0);
$ready180Cheques = (int)($readyAge['180+']['count'] ?? 0);
$ready180Leases = count(array_filter($ready, fn($r) => $r['over180'] > 0.005));
$ready180Pct = $readyAgeSum > 0 ? (int)round($ready180 / $readyAgeSum * 100) : 0;
$ageCols = ['#b19cfb', '#9177f5', '#7c3aed', '#5b21b6', '#3b1478'];

$byAmount = $ready;
usort($byAmount, fn($a, $b) => $b['outstanding'] <=> $a['outstanding'] ?: $b['oldest'] <=> $a['oldest']);
$byAge = $ready;
usort($byAge, fn($a, $b) => $b['oldest'] <=> $a['oldest'] ?: $b['outstanding'] <=> $a['outstanding']);
$readyTabs = ['amount' => array_slice($byAmount, 0, 6), 'age' => array_slice($byAge, 0, 6)];

$readyBuildings = [];
foreach ($ready as $r) $readyBuildings[$r['building_id']] = $r['building_name'];
asort($readyBuildings);

if (!function_exists('lc_initials')) {
    /** Initials from the first two words that start with a letter ("Hadef & Partners" → "HP"). */
    function lc_initials(string $name): string
    {
        $words = array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: [], fn($w) => preg_match('/^\p{L}/u', $w)));
        $ini = mb_strtoupper(mb_substr($words[0] ?? '', 0, 1) . mb_substr($words[1] ?? '', 0, 1));
        return $ini !== '' ? $ini : lw_initials($name);
    }
}

$daysClass = fn(int $d) => $d > 180 ? 'd3' : ($d > 90 ? 'd2' : 'd1');

$pageTitle = 'External Counsel';
$pageHead = '
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<link href="assets/legal-counsel.css?v=' . (@filemtime(__DIR__ . '/assets/legal-counsel.css') ?: 1) . '" rel="stylesheet">';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
<div class="lw-page">

    <div class="lw-head d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h1 class="mb-1">External Counsel</h1>
            <div class="lc-sub">Law firms and lawyers we hand cases to — their workload, what we spend, and which leases are ready to go to them</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="legal_case_list.php" class="btn lw-btn-soft"><i class="bi bi-briefcase"></i> Cases</a>
            <a href="legal_counsel_add.php" class="btn lw-btn-grad"><i class="bi bi-plus-lg"></i> Add counsel</a>
        </div>
    </div>

    <?php if ($flash): ?><div class="alert alert-success"><i class="bi bi-check-circle"></i> <?= h($flash) ?></div><?php endif; ?>
    <?php if ($dataError): ?><div class="alert alert-danger">Some data could not be loaded: <?= h($dataError) ?></div><?php endif; ?>

    <!-- Summary strip -->
    <?php
    $segs = [
        ['navy', 'bi-person-badge-fill', (string)$activeCount, 'Active counsel',
            count($list) . ' registered' . (count($list) - $activeCount > 0 ? ' · ' . (count($list) - $activeCount) . ' inactive' : ''), '#lcCounsel', null],
        ['violet', 'bi-briefcase-fill', (string)$openWithCounsel, 'Open cases with counsel',
            $openCasesAll . ' open case' . ($openCasesAll === 1 ? '' : 's') . ' in total · ' . $openNoCounsel . ' with no counsel', '#lcCounsel', null],
        ['sky', 'bi-cash-coin', 'AED ' . lw_aed($feesTotal), 'Fees & costs booked',
            'All case costs booked to a counsel', $hasChart ? '#lcWorkload' : '#lcCounsel', null],
        ['rose', 'bi-send-fill', (string)$readyCount, 'Leases ready to hand over',
            'AED ' . lw_aed($readyOwed) . ' owed · no open case', '#lcReady', 'modal'],
    ];
    ?>
    <div class="lw-strip mb-4">
        <?php foreach ($segs as [$tone, $icon, $big, $label, $sub, $target, $mode]): ?>
            <div class="lw-alert lw-seg lw-st-<?= h($tone) ?>" title="<?= h($label . ' — ' . $sub) ?>">
                <button type="button" class="lw-alert-btn" data-scroll="<?= h($target) ?>" <?= $mode ? 'data-open-ready' : '' ?> aria-label="<?= h($label) ?>"></button>
                <span class="lw-seg-ico"><i class="bi <?= h($icon) ?>"></i></span>
                <div class="lw-seg-body">
                    <div class="lw-seg-label"><?= h($label) ?></div>
                    <div class="lw-seg-big"><?= h($big) ?></div>
                    <div class="lw-seg-sub"><?= h($sub) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="d-flex flex-column gap-4 mb-4">
        <!-- Counsel -->
            <div class="lw-panel" id="lcCounsel">
                <div class="lw-panel-head">
                    <div>
                        <h2>Our counsel</h2>
                        <div class="lw-muted">Open cases = not settled, closed or withdrawn. Fees = case costs booked to the counsel.</div>
                    </div>
                    <?php if ($list): ?><span class="lw-date-pill"><i class="bi bi-people"></i> <?= count($list) ?> counsel</span><?php endif; ?>
                </div>

                <?php if (!$list): ?>
                    <div class="lc-empty">
                        <div class="lc-empty-ico"><i class="bi bi-person-badge"></i></div>
                        <h3>No external counsel yet</h3>
                        <p>Add the law firms and lawyers you work with. Once a case is assigned to them, this page shows each one's open cases, what you have spent and their latest case.
                            <?php if ($readyCount): ?><br><strong><?= $readyCount ?> lease<?= $readyCount === 1 ? '' : 's' ?></strong> (AED <?= h(number_format($readyOwed, 0)) ?> owed) are already ready to hand over.<?php endif; ?></p>
                        <a href="legal_counsel_add.php" class="btn lw-btn-grad btn-lg px-4"><i class="bi bi-plus-lg"></i> Add your first counsel</a>
                        <div class="lc-empty-steps">
                            <span><b>1</b>Add a firm or lawyer</span>
                            <span><b>2</b>Open a case from a lease below</span>
                            <span><b>3</b>Assign the counsel on the case</span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="lc-toolbar">
                        <div class="lw-search-box">
                            <i class="bi bi-search"></i>
                            <input type="search" id="lcSearch" placeholder="Search name, contact, email, phone, specialisation">
                        </div>
                        <div class="lc-sel">
                            <select id="lcType" class="form-select form-select-sm">
                                <option value="">All types</option>
                                <?php foreach ($counselTypes as $k => $lbl): ?><option value="<?= h($k) ?>"><?= h($lbl) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="lc-sel">
                            <select id="lcStatus" class="form-select form-select-sm">
                                <option value="">Active &amp; inactive</option>
                                <option value="1">Active only</option>
                                <option value="0">Inactive only</option>
                            </select>
                        </div>
                    </div>
                    <div class="lc-shown" id="lcShown"></div>
                    <div class="row g-3" id="lcGrid">
                        <?php foreach ($list as $c):
                            $typeLabel = $counselTypes[$c['counsel_type']] ?? $c['counsel_type'];
                            $search = strtolower(implode(' ', [$c['name'], $c['contact_person'], $c['email'], $c['phone'], $c['specialization'], $c['license_number']]));
                            $lc = $c['last_case'];
                        ?>
                        <div class="col-md-6 col-xxl-4 lc-item" data-type="<?= h($c['counsel_type']) ?>" data-active="<?= $c['is_active'] ? 1 : 0 ?>" data-search="<?= h($search) ?>">
                            <div class="lc-card <?= $c['is_active'] ? '' : 'is-inactive' ?>">
                                <div class="lc-top">
                                    <span class="lw-avatar lc-av lw-av-<?= lw_avatar_tone($c['name']) ?>"><?= h(lc_initials($c['name'])) ?></span>
                                    <div class="min-w-0">
                                        <div class="lc-name text-truncate" title="<?= h($c['name']) ?>"><?= h($c['name']) ?></div>
                                        <div class="lc-firm text-truncate">
                                            <?php if ($c['counsel_type'] === 'firm' && $c['contact_person']): ?><i class="bi bi-person"></i> <?= h($c['contact_person']) ?>
                                            <?php elseif ($c['specialization']): ?><?= h($c['specialization']) ?>
                                            <?php elseif ($c['contact_person']): ?><?= h($c['contact_person']) ?>
                                            <?php else: ?><span class="lw-muted">No contact person</span><?php endif; ?>
                                        </div>
                                        <div class="lc-tags">
                                            <span class="lc-tag <?= h($c['counsel_type']) ?>"><i class="bi <?= $c['counsel_type'] === 'firm' ? 'bi-building' : 'bi-person' ?>"></i> <?= h($typeLabel) ?></span>
                                            <span class="lc-tag <?= $c['is_active'] ? 'on' : 'off' ?>"><i class="bi <?= $c['is_active'] ? 'bi-check-circle-fill' : 'bi-pause-circle' ?>"></i> <?= $c['is_active'] ? 'Active' : 'Inactive' ?></span>
                                        </div>
                                    </div>
                                    <a href="legal_counsel_edit.php?id=<?= (int)$c['id'] ?>" class="lc-edit" title="Edit <?= h($c['name']) ?>" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                                </div>

                                <div class="lc-contact">
                                    <?php if ($c['phone']): ?><a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $c['phone'])) ?>"><i class="bi bi-telephone"></i> <?= h($c['phone']) ?></a><?php endif; ?>
                                    <?php if ($c['email']): ?><a href="mailto:<?= h($c['email']) ?>"><i class="bi bi-envelope"></i> <?= h($c['email']) ?></a><?php endif; ?>
                                    <?php if (!$c['phone'] && !$c['email']): ?><span><i class="bi bi-telephone-x"></i> No phone or email</span><?php endif; ?>
                                </div>

                                <div class="lc-stats">
                                    <div class="lc-stat <?= $c['open_cases'] > 0 ? 'hot' : '' ?>">
                                        <small>Open cases</small>
                                        <strong><?= (int)$c['open_cases'] ?></strong>
                                        <span class="lc-stat-note"><?= (int)$c['total_cases'] ?> in total</span>
                                    </div>
                                    <div class="lc-stat" title="<?= $c['fees'] > 0 ? h('AED ' . number_format($c['fees'], 2) . ' booked · counsel fees AED ' . number_format($c['counsel_fees'], 2)) : 'No costs booked yet' ?>">
                                        <small>Total fees</small>
                                        <strong><?= $c['fees'] > 0 ? 'AED ' . h(lw_aed($c['fees'])) : '—' ?></strong>
                                        <span class="lc-stat-note"><?= $c['open_claim'] > 0 ? 'AED ' . h(lw_aed($c['open_claim'])) . ' claimed' : 'from case costs' ?></span>
                                    </div>
                                    <div class="lc-stat">
                                        <small>Last case</small>
                                        <?php if ($lc): ?>
                                            <a href="legal_case_view.php?id=<?= (int)$lc['id'] ?>" title="<?= h($lc['title']) ?>">
                                                <strong><?= h($lc['case_number']) ?></strong>
                                                <span class="lc-stat-note"><?= $lc['case_date'] ? h(date('j M Y', strtotime($lc['case_date']))) : '' ?></span>
                                            </a>
                                        <?php else: ?>
                                            <strong>—</strong><span class="lc-stat-note">No cases yet</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="lc-foot">
                                    <?php if ($c['specialization'] && $c['counsel_type'] === 'firm'): ?><span><i class="bi bi-bookmark-star"></i> <?= h($c['specialization']) ?></span><?php endif; ?>
                                    <?php if ($c['hourly_rate']): ?><span><i class="bi bi-clock"></i> AED <?= h(number_format((float)$c['hourly_rate'], 0)) ?>/hr</span><?php endif; ?>
                                    <?php if ($c['license_number']): ?><span><i class="bi bi-patch-check"></i> Lic. <?= h($c['license_number']) ?></span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="lc-nomatch d-none" id="lcNoMatch"><i class="bi bi-search d-block fs-3 mb-1"></i>No counsel match this search.</div>
                <?php endif; ?>
            </div>

            <?php if ($hasChart): ?>
            <div class="lw-panel" id="lcWorkload">
                <div class="lw-panel-head">
                    <div>
                        <h2>Workload &amp; spend per counsel</h2>
                        <div class="lw-muted" id="lcChartNote">Open cases per counsel (not settled, closed or withdrawn)</div>
                    </div>
                    <div class="lc-seg" role="tablist">
                        <button type="button" class="is-active" data-metric="open">Open cases</button>
                        <button type="button" data-metric="total">All cases</button>
                        <button type="button" data-metric="fees">Fees (AED)</button>
                    </div>
                </div>
                <div class="lw-chart-box" style="height:<?= max(180, 46 * count($chart['labels']) + 50) ?>px"><canvas id="lcChart" aria-label="Workload and spend per counsel"></canvas></div>
            </div>
            <?php endif; ?>

        <!-- Ready to hand to counsel -->
            <div class="lw-panel" id="lcReady">
                <div class="lw-panel-head lc-ready-head">
                    <div>
                        <h2><i class="bi bi-send text-danger"></i> Ready to hand to counsel</h2>
                        <div class="lw-muted">Built from lease cheque data — the debt that is hardest to recover without a lawyer</div>
                    </div>
                    <span class="lw-date-pill"><i class="bi bi-house-door"></i> <?= $readyCount ?> lease<?= $readyCount === 1 ? '' : 's' ?> · AED <?= h(lw_aed($readyOwed)) ?></span>
                </div>
                <div class="row g-4">
                <div class="col-lg-5 col-xl-4">
                <div class="lc-what">
                    Leases with <b>no open legal case</b> that have a <b>bounced cheque not replaced or paid</b> (<?= $readyBounced ?>),
                    a <b>cheque overdue more than 90 days</b> (<?= $readyOver90 ?>), or <b>ended with money owed</b> (<?= $readyEnded ?>) — a lease can be in more than one.
                    Amount = everything still owed on the lease, bounced + overdue.
                </div>

                <?php if ($ready): ?>
                    <div class="lw-age-callout">
                        <span class="lw-age-ico"><i class="bi bi-hourglass-split"></i></span>
                        <div class="min-w-0">
                            <div class="lw-age-amt">AED <?= h(lw_aed($ready180)) ?></div>
                            <div class="lw-age-note"><strong><?= $ready180Pct ?>%</strong> of the AED <?= h(lw_aed($readyAgeSum)) ?> these leases owe is over 180 days old — <?= $ready180Cheques ?> cheque<?= $ready180Cheques === 1 ? '' : 's' ?> on <?= $ready180Leases ?> lease<?= $ready180Leases === 1 ? '' : 's' ?></div>
                        </div>
                    </div>
                    <div class="lw-age-split" role="img" aria-label="Amount owed on these leases, by age">
                        <?php $i = 0; foreach ($readyAgeTotals as $label => $v): if ($v > 0): ?>
                            <span style="width:<?= round($v / $readyAgeSum * 100, 2) ?>%;background:<?= $ageCols[$i] ?>" title="<?= h($label) ?> days · AED <?= number_format($v) ?> · <?= (int)$readyAge[$label]['count'] ?> cheques"></span>
                        <?php endif; $i++; endforeach; ?>
                    </div>
                    <div class="lc-age-mini">
                        <?php $i = 0; foreach ($readyAgeTotals as $label => $v): ?>
                            <span title="<?= (int)$readyAge[$label]['count'] ?> cheques"><i style="background:<?= $ageCols[$i] ?>"></i><?= h($label) ?>d <b><?= h(lw_aed($v)) ?></b></span>
                        <?php $i++; endforeach; ?>
                    </div>
                    <button type="button" class="btn lw-btn-soft lc-all-btn" data-open-ready><i class="bi bi-list-ul"></i> View all <?= $readyCount ?> leases · AED <?= h(lw_aed($readyOwed)) ?></button>
                <?php endif; ?>
                </div>
                <div class="col-lg-7 col-xl-8">
                <?php if (!$ready): ?>
                    <div class="lw-empty-state"><i class="bi bi-check2-circle"></i>No lease needs handing over right now.</div>
                <?php else: ?>
                    <div class="lw-tabs" role="tablist">
                        <button type="button" class="lw-tab is-active" data-rtab="amount">Biggest amount</button>
                        <button type="button" class="lw-tab" data-rtab="age">Oldest debt</button>
                    </div>
                    <?php foreach ($readyTabs as $tk => $rows): ?>
                        <div class="lc-ready-list <?= $tk === 'amount' ? '' : 'd-none' ?>" data-rlist="<?= $tk ?>">
                            <?php foreach ($rows as $r): ?>
                                <div class="lc-row">
                                    <span class="lw-avatar lw-av-<?= lw_avatar_tone($r['tenant_label']) ?>"><?= h(lw_initials($r['tenant_label'])) ?><i class="lw-dot <?= $r['bounced_count'] > 0 ? 'red' : 'amber' ?>"></i></span>
                                    <div class="lc-row-main">
                                        <div class="lc-row-name" title="<?= h($r['tenant_label']) ?>"><?= h($r['tenant_label']) ?></div>
                                        <div class="lc-row-meta"><a href="<?= h($re) ?>lease_view.php?id=<?= (int)$r['id'] ?>#installments"><?= h($r['lease_number'] ?: '#' . $r['id']) ?></a> · <?= h($r['building_name']) ?> · Unit <?= h($r['unit_number']) ?></div>
                                        <div class="lc-row-flags">
                                            <?php foreach ($r['flags'] as $f): if ($f === 'due_soon') continue; [$fl, , $fi] = $flagMeta[$f]; ?>
                                                <span class="lw-flag lw-flag-<?= h($f) ?>"><i class="bi <?= h($fi) ?>"></i> <?= h($fl) ?><?php if ($f === 'bounced'): ?> ×<?= (int)$r['bounced_count'] ?><?php elseif ($f === 'overdue'): ?> ×<?= (int)$r['overdue_count'] ?><?php elseif ($f === 'not_renewed'): ?> · <?= (int)$r['days_expired'] ?>d<?php endif; ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="lc-row-right">
                                        <span class="lc-amt">AED <?= h(lw_aed((float)$r['outstanding'])) ?></span>
                                        <span class="lc-days <?= $daysClass((int)$r['oldest']) ?>" title="Oldest unpaid cheque: days since its cheque date (or bounce date)"><i class="bi bi-hourglass-split"></i> <?= (int)$r['oldest'] ?>d</span>
                                        <a class="lc-case-btn" href="<?= h($r['case_url']) ?>" title="Open a legal case for this lease, pre-filled"><i class="bi bi-plus-lg"></i> Open case</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
                </div>
            </div>
    </div>

    <?php if ($ready): ?>
    <!-- All leases ready to hand over -->
    <div class="modal fade" id="lcModal" tabindex="-1" aria-labelledby="lcModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content lw-page lw-modal">
    <div class="lw-card">
        <div class="lw-mhead">
            <div class="d-flex align-items-center gap-3 min-w-0">
                <span class="lw-mhead-ico lw-tone-pink"><i class="bi bi-send"></i></span>
                <div class="min-w-0">
                    <h2 id="lcModalTitle">Ready to hand to counsel</h2>
                    <div class="lw-mhead-stats" id="lcModalStats"></div>
                </div>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <button type="button" class="lw-glass-btn" id="lcExport"><i class="bi bi-download"></i> Export</button>
                <button type="button" class="lw-glass-btn lw-glass-x" data-bs-dismiss="modal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>
        <div class="lw-card-head">
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <div style="width:240px"><select id="lcmBuilding" class="form-select form-select-sm">
                    <option value="">All buildings</option>
                    <?php foreach ($readyBuildings as $bid => $bname): ?><option value="<?= (int)$bid ?>"><?= h($bname) ?></option><?php endforeach; ?>
                </select></div>
                <div style="width:220px"><select id="lcmReason" class="form-select form-select-sm">
                    <option value="">Any reason</option>
                    <option value="bounced">Bounced cheque (<?= $readyBounced ?>)</option>
                    <option value="overdue90">Overdue 90+ days (<?= $readyOver90 ?>)</option>
                    <option value="ended">Ended with balance (<?= $readyEnded ?>)</option>
                    <option value="notrenew">Expired, not renewed (<?= $readyNotRenewed ?>)</option>
                </select></div>
                <div style="width:200px"><select id="lcmSort" class="form-select form-select-sm">
                    <option value="amount">Sort: biggest amount</option>
                    <option value="age">Sort: oldest debt</option>
                </select></div>
                <input type="search" id="lcmSearch" class="form-control form-control-sm" style="width:280px;max-width:100%" placeholder="Search tenant, lease, unit, phone">
            </div>
        </div>
        <div class="table-responsive">
            <table class="table lw-table" id="lcmTable">
                <thead><tr>
                    <th>Tenant</th><th>Lease</th><th>Building / unit</th><th>Flags</th>
                    <th class="text-end">AED owed</th><th class="text-end">Over 180 days</th><th class="text-end">Oldest</th><th class="text-end">Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ($ready as $r):
                    $search = strtolower(implode(' ', [$r['lease_number'], $r['tenant_label'], $r['unit_label'], $r['phone'], $r['email']])); ?>
                    <tr data-building="<?= (int)$r['building_id'] ?>" data-reasons="<?= h(implode(' ', $r['reasons'])) ?>"
                        data-owed="<?= round((float)$r['outstanding'], 2) ?>" data-age="<?= (int)$r['oldest'] ?>"
                        data-search="<?= h($search) ?>"
                        data-csv="<?= h(json_encode([$r['tenant_label'], $r['phone'], $r['lease_number'], $r['status'], $r['building_name'], $r['unit_number'],
                            implode('; ', array_map(fn($f) => $flagMeta[$f][0], $r['flags'])),
                            round((float)$r['bounced_amt'], 2), round((float)$r['overdue_amt'], 2), round((float)$r['outstanding'], 2),
                            round((float)$r['over180'], 2), (int)$r['oldest']])) ?>">
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
                        </td>
                        <td><?= h($r['building_name']) ?><br><span class="lw-muted">Unit <?= h($r['unit_number']) ?></span></td>
                        <td style="max-width:220px">
                            <?php foreach ($r['flags'] as $f): if ($f === 'due_soon') continue; [$fl, , $fi] = $flagMeta[$f]; ?>
                                <span class="lw-flag lw-flag-<?= h($f) ?>"><i class="bi <?= h($fi) ?>"></i> <?= h($fl) ?><?php if ($f === 'bounced'): ?> ×<?= (int)$r['bounced_count'] ?><?php elseif ($f === 'overdue'): ?> ×<?= (int)$r['overdue_count'] ?><?php endif; ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td class="text-end">
                            <div class="lw-money red"><?= number_format((float)$r['outstanding'], 0) ?></div>
                            <span class="lw-muted"><?php if ($r['bounced_amt'] > 0): ?>bounced <?= h(lw_aed((float)$r['bounced_amt'])) ?><?php endif; ?><?php if ($r['bounced_amt'] > 0 && $r['overdue_amt'] > 0): ?> · <?php endif; ?><?php if ($r['overdue_amt'] > 0): ?>overdue <?= h(lw_aed((float)$r['overdue_amt'])) ?><?php endif; ?></span>
                        </td>
                        <td class="text-end"><?= $r['over180'] > 0.005 ? '<span class="lw-money">' . h(number_format($r['over180'], 0)) . '</span>' : '<span class="lw-muted">—</span>' ?></td>
                        <td class="text-end"><span class="lc-days <?= $daysClass((int)$r['oldest']) ?>"><?= (int)$r['oldest'] ?> days</span></td>
                        <td class="text-end"><a class="lc-case-btn" href="<?= h($r['case_url']) ?>"><i class="bi bi-plus-lg"></i> Open case</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="lw-empty d-none" id="lcmEmpty"><i class="bi bi-check2-circle"></i>No leases match this filter.</div>
        </div>
        <div class="px-3 py-2 border-top lw-muted d-flex justify-content-between flex-wrap gap-2">
            <span id="lcmShown"></span>
            <span>Oldest = days since the oldest unpaid cheque's date (bounce date for bounced cheques).</span>
        </div>
    </div>
    </div>
    </div>
    </div>
    <?php endif; ?>

</div>

<?php
$pageScripts = '<script>window.LC_CHART = ' . json_encode($hasChart ? $chart : null, JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>'
    . '<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>'
    . '<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>'
    . ($hasChart ? '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>' : '')
    . <<<'JS'
<script>
(function () {
    const hasS2 = !!(window.jQuery && jQuery.fn.select2);
    const s2 = (el, opts, onChange) => {
        if (!el) return;
        if (hasS2) jQuery(el).select2(Object.assign({theme: 'bootstrap-5', width: '100%'}, opts)).on('change', onChange);
        else el.addEventListener('change', onChange);
    };

    // ---- Counsel cards: search + type/status filters ----
    const items = Array.from(document.querySelectorAll('.lc-item'));
    const cSearch = document.getElementById('lcSearch');
    const cType = document.getElementById('lcType');
    const cStatus = document.getElementById('lcStatus');
    function applyCounsel() {
        if (!items.length) return;
        const q = cSearch.value.trim().toLowerCase(), t = cType.value, s = cStatus.value;
        let n = 0;
        items.forEach(it => {
            const ok = (!q || it.dataset.search.includes(q)) && (!t || it.dataset.type === t) && (s === '' || it.dataset.active === s);
            it.classList.toggle('d-none', !ok);
            if (ok) n++;
        });
        document.getElementById('lcNoMatch').classList.toggle('d-none', n > 0);
        document.getElementById('lcShown').textContent = 'Showing ' + n + ' of ' + items.length + ' counsel';
    }
    if (items.length) {
        cSearch.addEventListener('input', applyCounsel);
        s2(cType, {minimumResultsForSearch: 0}, applyCounsel);
        s2(cStatus, {minimumResultsForSearch: 0}, applyCounsel);
        applyCounsel();
    }

    // ---- Strip: scroll to a section (or open the list) ----
    const modalEl = document.getElementById('lcModal');
    const openReady = () => { if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).show(); };
    document.querySelectorAll('[data-scroll]').forEach(b => b.addEventListener('click', () => {
        if (b.hasAttribute('data-open-ready') && modalEl) { openReady(); return; }
        const t = document.querySelector(b.dataset.scroll);
        if (t) t.scrollIntoView({behavior: 'smooth', block: 'start'});
    }));
    document.querySelectorAll('.lc-all-btn[data-open-ready]').forEach(b => b.addEventListener('click', openReady));

    // ---- Ready tabs ----
    document.querySelectorAll('[data-rtab]').forEach(t => t.addEventListener('click', () => {
        document.querySelectorAll('[data-rtab]').forEach(x => x.classList.toggle('is-active', x === t));
        document.querySelectorAll('[data-rlist]').forEach(l => l.classList.toggle('d-none', l.dataset.rlist !== t.dataset.rtab));
    }));

    // ---- Modal list ----
    if (modalEl) {
        const tbody = document.querySelector('#lcmTable tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const mSearch = document.getElementById('lcmSearch');
        const mBuilding = document.getElementById('lcmBuilding');
        const mReason = document.getElementById('lcmReason');
        const mSort = document.getElementById('lcmSort');
        function applyModal() {
            const q = mSearch.value.trim().toLowerCase(), b = mBuilding.value, r = mReason.value, srt = mSort.value;
            const key = srt === 'age' ? (x => [+x.dataset.age, +x.dataset.owed]) : (x => [+x.dataset.owed, +x.dataset.age]);
            rows.slice().sort((a, c) => { const ka = key(a), kc = key(c); return kc[0] - ka[0] || kc[1] - ka[1]; })
                .forEach(tr => tbody.appendChild(tr));
            let n = 0, amt = 0;
            rows.forEach(tr => {
                const ok = (!b || tr.dataset.building === b) && (!r || (' ' + tr.dataset.reasons + ' ').includes(' ' + r + ' '))
                    && (!q || tr.dataset.search.includes(q));
                tr.classList.toggle('d-none', !ok);
                if (ok) { n++; amt += parseFloat(tr.dataset.owed || 0); }
            });
            document.getElementById('lcmEmpty').classList.toggle('d-none', n > 0);
            document.getElementById('lcModalStats').textContent = n + ' lease' + (n === 1 ? '' : 's') + ' with no open case · AED '
                + Math.round(amt).toLocaleString('en-US') + ' owed in total (bounced + overdue)';
            let txt = 'Showing ' + n + ' of ' + rows.length + ' leases';
            if (r) txt += ' · ' + mReason.options[mReason.selectedIndex].text.replace(/\s*\(\d+\)$/, '');
            if (b) txt += ' · ' + mBuilding.options[mBuilding.selectedIndex].text;
            if (q) txt += ' · "' + mSearch.value.trim() + '"';
            document.getElementById('lcmShown').textContent = txt;
        }
        const mOpts = {dropdownParent: window.jQuery ? jQuery(modalEl) : undefined, minimumResultsForSearch: 0};
        s2(mBuilding, Object.assign({placeholder: 'All buildings', allowClear: true}, mOpts), applyModal);
        s2(mReason, Object.assign({placeholder: 'Any reason', allowClear: true}, mOpts), applyModal);
        s2(mSort, mOpts, applyModal);
        mSearch.addEventListener('input', applyModal);
        applyModal();

        document.getElementById('lcExport').addEventListener('click', () => {
            const head = ['Tenant','Phone','Lease','Status','Building','Unit','Flags','Bounced AED','Overdue AED','Owed AED','Over 180 days AED','Oldest (days)'];
            const esc = v => { v = v == null ? '' : String(v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
            const lines = [head.map(esc).join(',')];
            Array.from(tbody.querySelectorAll('tr')).filter(tr => !tr.classList.contains('d-none'))
                .forEach(tr => lines.push(JSON.parse(tr.dataset.csv).map(esc).join(',')));
            const blob = new Blob(['﻿' + lines.join('\n')], {type: 'text/csv;charset=utf-8'});
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'ready-for-counsel-' + new Date().toISOString().slice(0, 10) + '.csv';
            a.click();
            URL.revokeObjectURL(a.href);
        });
    }

    // ---- Workload / spend chart (only rendered when data exists) ----
    const D = window.LC_CHART;
    if (!D || !window.Chart) return;
    Chart.defaults.font.family = "'Plus Jakarta Sans', system-ui, sans-serif";
    Chart.defaults.color = '#52514e';
    const notes = {open: 'Open cases per counsel (not settled, closed or withdrawn)', total: 'Every case assigned to each counsel, open or closed',
                   fees: 'Case costs booked to each counsel, AED (hover for the counsel-fee part)'};
    const labels = {open: 'Open cases', total: 'All cases', fees: 'Fees & costs (AED)'};
    const fmt = (m, v) => m === 'fees' ? 'AED ' + Math.round(v).toLocaleString('en-US') : v + ' case' + (v === 1 ? '' : 's');
    let metric = 'open';
    const ch = new Chart(document.getElementById('lcChart'), {
        type: 'bar',
        data: {labels: D.labels, datasets: [{label: labels.open, data: D.open, backgroundColor: '#2a78d6', borderRadius: 6, maxBarThickness: 26}]},
        options: {
            indexAxis: 'y', maintainAspectRatio: false,
            plugins: {legend: {display: false},
                      tooltip: {backgroundColor: '#111827', padding: 10, cornerRadius: 8, callbacks: {
                          label: c => ' ' + fmt(metric, c.parsed.x),
                          afterLabel: c => metric === 'fees' ? 'Counsel fees: AED ' + Math.round(D.counsel_fees[c.dataIndex]).toLocaleString('en-US')
                                         : 'Open ' + D.open[c.dataIndex] + ' of ' + D.total[c.dataIndex],
                      }}},
            scales: {x: {beginAtZero: true, grid: {color: '#eceef2'}, border: {display: false},
                         ticks: {precision: 0, callback: v => metric === 'fees' ? (v >= 1e3 ? Math.round(v / 1e3) + 'k' : v) : v}},
                     y: {grid: {display: false}, border: {display: false}}},
        },
    });
    document.querySelectorAll('.lc-seg button').forEach(b => b.addEventListener('click', () => {
        metric = b.dataset.metric;
        document.querySelectorAll('.lc-seg button').forEach(x => x.classList.toggle('is-active', x === b));
        ch.data.datasets[0].data = D[metric];
        ch.data.datasets[0].label = labels[metric];
        ch.update();
        document.getElementById('lcChartNote').textContent = notes[metric];
    }));
})();
</script>
JS;
require_once __DIR__ . '/includes/legal_layout_footer.php';
?>
