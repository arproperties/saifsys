<?php
/**
 * Real Estate Module - Compliance Tracking Dashboard
 * Track document expiry, missing documents, legal status, Ejari
 *
 * Expiry figures come from the same source as the expiry summary e-mail
 * (re_doc_digest_rows: active leases, current copies only), so the page,
 * the e-mail and the alerts page always show the same numbers.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/compliance_helper.php';
require_once __DIR__ . '/includes/compliance_ui.php';

require_login();
// Check department access (backward compatible: fallback to module access)
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_COMPLIANCE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

// Expired + expiring documents on active leases (most urgent first)
$docRows   = re_doc_digest_rows($conn, $currentCompanyId);
$docGroups = re_doc_digest_group($docRows);
$expiredCount  = count(array_filter($docRows, static function ($r) { return $r['days_left'] < 0; }));
$expiringCount = count($docRows) - $expiredCount;
$urgentCount   = count(array_filter($docRows, static function ($r) { return $r['days_left'] >= 0 && $r['days_left'] <= 7; }));

// By document type
$byType = [];
foreach ($docRows as $r) {
    $k = $r['doc_label'];
    $byType[$k] = $byType[$k] ?? ['expired' => 0, 'expiring' => 0];
    $byType[$k][$r['days_left'] < 0 ? 'expired' : 'expiring']++;
}
uasort($byType, static function ($a, $b) {
    return ($b['expired'] + $b['expiring']) <=> ($a['expired'] + $a['expiring']);
});
$maxType = $byType ? max(array_map(static function ($c) { return $c['expired'] + $c['expiring']; }, $byType)) : 1;

// Reminder e-mail status
$recipients = re_doc_digest_all_recipients($conn, $currentCompanyId);
$lastSent   = re_doc_digest_last_sent($conn, $currentCompanyId);

// Missing documents
$missingDocuments = $conn->prepare("
    SELECT
        md.*,
        dt.document_type_name,
        dt.is_required,
        CASE md.related_type
            WHEN 'lease' THEN (SELECT CONCAT(b.name, ' - ', u.unit_number) FROM re_leases l JOIN re_units u ON u.id = l.unit_id JOIN re_buildings b ON b.id = u.building_id WHERE l.id = md.related_id)
            WHEN 'tenant' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM re_tenants WHERE id = md.related_id)
            WHEN 'unit' THEN (SELECT CONCAT(b.name, ' - ', u.unit_number) FROM re_units u JOIN re_buildings b ON b.id = u.building_id WHERE u.id = md.related_id)
            ELSE 'Other'
        END as related_name
    FROM re_missing_documents md
    JOIN re_document_types dt ON dt.id = md.document_type_id
    WHERE md.company_id = ?
    AND md.status IN ('missing', 'pending_upload')
    ORDER BY dt.is_required DESC, md.created_at DESC
");
$missingDocuments->execute([$currentCompanyId]);
$missingDocuments = $missingDocuments->fetchAll(PDO::FETCH_ASSOC);
$missingRequired = count(array_filter($missingDocuments, static function ($m) { return (int)$m['is_required'] === 1; }));

// Unit legal status
$unitLegalStatus = $conn->prepare("
    SELECT
        uls.*,
        u.unit_number,
        b.name as building_name,
        l.lease_number,
        t.first_name,
        t.last_name
    FROM re_unit_legal_status uls
    JOIN re_units u ON u.id = uls.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_leases l ON l.unit_id = u.id AND l.status = 'active'
    LEFT JOIN re_tenants t ON t.id = l.tenant_id
    WHERE uls.company_id = ?
    ORDER BY
        CASE uls.legal_status
            WHEN 'non_compliant' THEN 1
            WHEN 'at_risk' THEN 2
            WHEN 'pending_review' THEN 3
            WHEN 'compliant' THEN 4
        END,
        b.name, u.unit_number
");
$unitLegalStatus->execute([$currentCompanyId]);
$unitLegalStatus = $unitLegalStatus->fetchAll(PDO::FETCH_ASSOC);
$nonCompliantUnits = count(array_filter($unitLegalStatus, static function ($s) { return $s['legal_status'] === 'non_compliant'; }));

// Ejari tracking
$ejariTracking = $conn->prepare("
    SELECT
        e.*,
        l.lease_number,
        u.unit_number,
        b.name as building_name,
        t.first_name,
        t.last_name,
        DATEDIFF(e.expiry_date, CURDATE()) as days_until_expiry
    FROM re_ejari_tracking e
    JOIN re_leases l ON l.id = e.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_tenants t ON t.id = l.tenant_id
    WHERE e.company_id = ?
    ORDER BY
        CASE e.registration_status
            WHEN 'expired' THEN 1
            WHEN 'pending' THEN 2
            WHEN 'registered' THEN 3
            ELSE 4
        END,
        e.expiry_date ASC
");
$ejariTracking->execute([$currentCompanyId]);
$ejariTracking = $ejariTracking->fetchAll(PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function cx_tenant_name(array $r): string {
    if (($r['tenant_type'] ?? '') === 'company' && trim((string)($r['company_name'] ?? '')) !== '') {
        return trim((string)$r['company_name']);
    }
    return trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
}
$legalPill = [
    'compliant'      => ['bg-green', 'Compliant'],
    'non_compliant'  => ['bg-red', 'Non-compliant'],
    'at_risk'        => ['bg-amber', 'At risk'],
    'pending_review' => ['bg-blue', 'Pending review'],
];

$pageTitle = 'Compliance Tracking';
require_once __DIR__ . '/includes/re_layout_header.php';

re_cx_header(
    'overview',
    'Compliance Tracking',
    'Tenant and lease documents on active leases that have expired or expire within their reminder window.',
    '<a class="cx-btn" href="compliance_send_alerts.php?preview=summary" target="_blank" rel="noopener"><i class="bi bi-eye"></i> Preview summary e-mail</a>'
    . '<a class="cx-btn cx-btn-primary" href="compliance_send_alerts.php"><i class="bi bi-envelope"></i> Expiry alerts</a>'
);
re_cx_flash();
?>

<div class="cx-kpis">
    <div class="cx-kpi">
        <div class="cx-kpi-icon bg-red"><i class="bi bi-x-octagon"></i></div>
        <div><div class="cx-kpi-label">Expired</div>
             <div class="cx-kpi-value t-red"><?= $expiredCount ?></div>
             <div class="cx-kpi-note">documents on active leases</div></div>
    </div>
    <div class="cx-kpi">
        <div class="cx-kpi-icon bg-amber"><i class="bi bi-hourglass-split"></i></div>
        <div><div class="cx-kpi-label">Expiring soon</div>
             <div class="cx-kpi-value t-amber"><?= $expiringCount ?></div>
             <div class="cx-kpi-note"><?= $urgentCount ?> within 7 days</div></div>
    </div>
    <div class="cx-kpi">
        <div class="cx-kpi-icon bg-blue"><i class="bi bi-house-door"></i></div>
        <div><div class="cx-kpi-label">Leases needing action</div>
             <div class="cx-kpi-value t-blue"><?= count($docGroups) ?></div>
             <div class="cx-kpi-note">with at least one item</div></div>
    </div>
    <div class="cx-kpi">
        <div class="cx-kpi-icon <?= $missingRequired ? 'bg-red' : 'bg-green' ?>"><i class="bi bi-file-earmark-excel"></i></div>
        <div><div class="cx-kpi-label">Missing required</div>
             <div class="cx-kpi-value <?= $missingRequired ? 't-red' : 't-green' ?>"><?= $missingRequired ?></div>
             <div class="cx-kpi-note">documents</div></div>
    </div>
    <div class="cx-kpi">
        <div class="cx-kpi-icon <?= $nonCompliantUnits ? 'bg-red' : 'bg-green' ?>"><i class="bi bi-building-exclamation"></i></div>
        <div><div class="cx-kpi-label">Non-compliant units</div>
             <div class="cx-kpi-value <?= $nonCompliantUnits ? 't-red' : 't-green' ?>"><?= $nonCompliantUnits ?></div>
             <div class="cx-kpi-note">units</div></div>
    </div>
</div>

<div class="cx-grid-2">
    <!-- Action list -->
    <section class="cx-card" aria-labelledby="cx-actions-title">
        <div class="cx-card-head">
            <div>
                <h2 class="cx-card-title" id="cx-actions-title"><i class="bi bi-list-check"></i> Documents needing action</h2>
                <p class="cx-card-sub">Most urgent first. Uploading a renewed copy removes the item.</p>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <div class="cx-chips" role="group" aria-label="Filter">
                    <button type="button" class="cx-chip is-active" data-cx-filter="all">All <?= count($docRows) ?></button>
                    <button type="button" class="cx-chip" data-cx-filter="expired">Expired <?= $expiredCount ?></button>
                    <button type="button" class="cx-chip" data-cx-filter="week">≤ 7 days <?= $urgentCount ?></button>
                    <button type="button" class="cx-chip" data-cx-filter="soon">Expiring <?= $expiringCount ?></button>
                </div>
                <input type="search" class="cx-search" id="cxSearch" placeholder="Search unit, tenant, document…" aria-label="Search">
            </div>
        </div>
        <?php if (!$docRows): ?>
            <div class="cx-empty"><i class="bi bi-check-circle"></i>All documents on active leases are valid.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="cx-table" id="cxDocTable">
                <thead><tr>
                    <th>Document</th>
                    <th>Property / tenant</th>
                    <th class="cx-hide-sm">Expiry date</th>
                    <th>Status</th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($docRows as $r):
                    $band = $r['days_left'] < 0 ? 'expired' : ($r['days_left'] <= 7 ? 'week' : 'soon');
                    $prop = trim(($r['building_name'] ?? '') . ($r['unit_number'] ? ' – ' . $r['unit_number'] : ''));
                    $tenant = cx_tenant_name($r);
                    $search = strtolower($r['doc_label'] . ' ' . $prop . ' ' . $tenant . ' ' . ($r['lease_number'] ?? ''));
                ?>
                    <tr data-band="<?= $band ?>" data-search="<?= h($search) ?>">
                        <td><span class="cx-strong"><?= h($r['doc_label']) ?></span></td>
                        <td><div class="cx-strong"><?= h($prop ?: '—') ?></div>
                            <div class="cx-dim"><?= h($tenant ?: '—') ?><?= $r['lease_number'] ? ' · ' . h($r['lease_number']) : '' ?></div></td>
                        <td class="cx-hide-sm"><?= h(date('d M Y', strtotime($r['expiry_date']))) ?></td>
                        <td><?= re_cx_status_pill((int)$r['days_left']) ?></td>
                        <td class="num"><?php if ($r['group_lease_id']): ?>
                            <a class="cx-btn cx-btn-sm" href="lease_view.php?id=<?= (int)$r['group_lease_id'] ?>" title="Open lease">Open <i class="bi bi-arrow-right"></i></a>
                        <?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="cx-empty" id="cxNoMatch" hidden>No documents match this filter.</div>
        </div>
        <?php endif; ?>
    </section>

    <!-- Side column -->
    <div>
        <section class="cx-card" aria-labelledby="cx-type-title">
            <div class="cx-card-head"><h2 class="cx-card-title" id="cx-type-title"><i class="bi bi-bar-chart"></i> By document</h2></div>
            <div class="cx-card-body">
                <?php if (!$byType): ?>
                    <p class="cx-dim mb-0">Nothing to show.</p>
                <?php else: ?>
                    <?php foreach ($byType as $label => $c): $tot = $c['expired'] + $c['expiring']; ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between" style="font-size:.88rem;">
                                <span class="cx-strong"><?= h($label) ?></span>
                                <span><span class="t-red"><?= $c['expired'] ?></span> <span class="cx-dim">expired ·</span>
                                      <span class="t-amber"><?= $c['expiring'] ?></span> <span class="cx-dim">expiring</span></span>
                            </div>
                            <div class="cx-bar mt-1" role="img" aria-label="<?= h("$label: {$c['expired']} expired, {$c['expiring']} expiring") ?>"
                                 style="width:<?= max(8, round($tot / $maxType * 100)) ?>%;">
                                <span style="width:<?= $tot ? round($c['expired'] / $tot * 100) : 0 ?>%;background:var(--cx-red);"></span>
                                <span style="width:<?= $tot ? round($c['expiring'] / $tot * 100) : 0 ?>%;background:#f79009;"></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="cx-card" aria-labelledby="cx-mail-title">
            <div class="cx-card-head"><h2 class="cx-card-title" id="cx-mail-title"><i class="bi bi-envelope-paper"></i> Expiry summary e-mail</h2></div>
            <div class="cx-card-body">
                <ul class="cx-list">
                    <li><span class="k">Recipients</span>
                        <span><?php if ($recipients): ?><?= h(implode(', ', $recipients)) ?>
                              <?php else: ?><span class="cx-pill bg-red no-dot">Not set</span>
                              <a href="../../settings.php?tab=re_email" class="d-block mt-1" style="font-size:.85rem;">Add in Settings →</a><?php endif; ?></span></li>
                    <li><span class="k">Last sent</span><span><?= $lastSent ? h(date('d M Y', strtotime($lastSent))) : 'Never' ?></span></li>
                    <li><span class="k">Schedule</span><span>Daily when something is new · full list every Monday</span></li>
                </ul>
                <a class="cx-btn w-100 justify-content-center mt-2" href="compliance_send_alerts.php"><i class="bi bi-gear"></i> Manage alerts</a>
            </div>
        </section>
    </div>
</div>

<?php if (!empty($missingDocuments)): ?>
<section class="cx-card">
    <div class="cx-card-head"><h2 class="cx-card-title"><i class="bi bi-exclamation-triangle"></i> Missing documents</h2></div>
    <div class="table-responsive">
        <table class="cx-table">
            <thead><tr><th>Document type</th><th>Related to</th><th>Status</th><th class="num"></th></tr></thead>
            <tbody>
            <?php foreach ($missingDocuments as $missing): ?>
                <tr>
                    <td><span class="cx-strong"><?= h($missing['document_type_name']) ?></span>
                        <?php if ($missing['is_required']): ?><span class="cx-pill bg-red no-dot ms-2">Required</span><?php endif; ?></td>
                    <td><?= h($missing['related_name'] ?: '—') ?></td>
                    <td><span class="cx-pill <?= $missing['status'] === 'missing' ? 'bg-red' : 'bg-amber' ?>"><?= h(ucfirst(str_replace('_', ' ', $missing['status']))) ?></span></td>
                    <td class="num"><a class="cx-btn cx-btn-sm" href="compliance_upload_document.php?related_type=<?= h($missing['related_type']) ?>&related_id=<?= (int)$missing['related_id'] ?>&document_type_id=<?= (int)$missing['document_type_id'] ?>"><i class="bi bi-upload"></i> Upload</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($unitLegalStatus)): ?>
<section class="cx-card">
    <div class="cx-card-head"><h2 class="cx-card-title"><i class="bi bi-shield-check"></i> Unit legal status</h2></div>
    <div class="table-responsive">
        <table class="cx-table">
            <thead><tr><th>Unit</th><th>Lease / tenant</th><th>Legal status</th><th class="num">Score</th><th class="cx-hide-sm">Last review</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($unitLegalStatus as $status): [$pc, $pl] = $legalPill[$status['legal_status']] ?? ['bg-grey', ucfirst((string)$status['legal_status'])]; ?>
                <tr>
                    <td class="cx-strong"><?= h($status['building_name']) ?> – <?= h($status['unit_number']) ?></td>
                    <td><?= h($status['lease_number'] ?: '—') ?><div class="cx-dim"><?= $status['first_name'] ? h($status['first_name'] . ' ' . $status['last_name']) : '—' ?></div></td>
                    <td><span class="cx-pill <?= $pc ?>"><?= h($pl) ?></span></td>
                    <td class="num"><?php if ($status['compliance_score'] !== null): $sc = (int)$status['compliance_score']; ?>
                        <span class="<?= $sc >= 80 ? 't-green' : ($sc >= 60 ? 't-amber' : 't-red') ?> cx-strong"><?= $sc ?>%</span>
                        <?php else: ?><span class="cx-dim">—</span><?php endif; ?></td>
                    <td class="cx-hide-sm"><?= $status['last_review_date'] ? h(date('d M Y', strtotime($status['last_review_date']))) : '—' ?></td>
                    <td class="num"><a class="cx-btn cx-btn-sm" href="compliance_unit_status.php?unit_id=<?= (int)$status['unit_id'] ?>">Review</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($ejariTracking)): ?>
<section class="cx-card">
    <div class="cx-card-head"><h2 class="cx-card-title"><i class="bi bi-file-earmark-text"></i> Ejari tracking</h2>
        <a class="cx-btn cx-btn-sm" href="compliance_ejari.php">View all</a></div>
    <div class="table-responsive">
        <table class="cx-table">
            <thead><tr><th>Ejari number</th><th>Unit / tenant</th><th class="cx-hide-sm">Registered</th><th>Expiry</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($ejariTracking as $ejari):
                $rs = (string)$ejari['registration_status'];
                $rc = $rs === 'registered' ? 'bg-green' : ($rs === 'expired' ? 'bg-red' : ($rs === 'pending' ? 'bg-amber' : 'bg-grey'));
            ?>
                <tr>
                    <td class="cx-strong"><?= h($ejari['ejari_number']) ?><div class="cx-dim"><?= h($ejari['lease_number']) ?></div></td>
                    <td><?= h($ejari['building_name']) ?> – <?= h($ejari['unit_number']) ?><div class="cx-dim"><?= h(trim($ejari['first_name'] . ' ' . $ejari['last_name'])) ?></div></td>
                    <td class="cx-hide-sm"><?= $ejari['registration_date'] ? h(date('d M Y', strtotime($ejari['registration_date']))) : '—' ?></td>
                    <td><?= $ejari['expiry_date'] ? h(date('d M Y', strtotime($ejari['expiry_date']))) : '—' ?>
                        <?php if ($ejari['expiry_date'] && $ejari['days_until_expiry'] !== null): ?><div><?= re_cx_status_pill((int)$ejari['days_until_expiry']) ?></div><?php endif; ?></td>
                    <td><span class="cx-pill <?= $rc ?>"><?= h(ucfirst($rs)) ?></span></td>
                    <td class="num"><a class="cx-btn cx-btn-sm" href="compliance_ejari_view.php?id=<?= (int)$ejari['id'] ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<script>
(function () {
    var table = document.getElementById('cxDocTable');
    if (!table) return;
    var rows = Array.prototype.slice.call(table.tBodies[0].rows);
    var chips = document.querySelectorAll('[data-cx-filter]');
    var search = document.getElementById('cxSearch');
    var noMatch = document.getElementById('cxNoMatch');
    var band = 'all';
    function apply() {
        var q = (search.value || '').trim().toLowerCase(), shown = 0;
        rows.forEach(function (tr) {
            var b = tr.getAttribute('data-band');
            var okBand = band === 'all' || b === band || (band === 'soon' && b === 'week');
            var ok = okBand && (!q || tr.getAttribute('data-search').indexOf(q) !== -1);
            tr.hidden = !ok;
            if (ok) shown++;
        });
        noMatch.hidden = shown !== 0;
    }
    chips.forEach(function (c) {
        c.addEventListener('click', function () {
            chips.forEach(function (x) { x.classList.remove('is-active'); });
            c.classList.add('is-active');
            band = c.getAttribute('data-cx-filter');
            apply();
        });
    });
    search.addEventListener('input', apply);
})();
</script>

<?php re_cx_footer(); ?>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
