<?php
/**
 * Legal - Issues register: problems logged against a unit / tenant / contract, with photo evidence.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/legal_helper.php';
require_once __DIR__ . '/includes/legal_issues_helper.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = (int)current_user_id();
$ready = legal_issues_ready($conn);
$flash = $_SESSION['legal_issue_flash'] ?? '';
unset($_SESSION['legal_issue_flash']);

// Every company the user can see.
$companies = legal_issue_companies($conn, $userId, $currentCompanyId);
$cids = array_keys($companies);

$issues = [];
if ($ready && $cids) {
    $in = implode(',', array_fill(0, count($cids), '?'));
    $st = $conn->prepare("
        SELECT i.id, i.company_id, i.issue_number, i.title, i.issue_type, i.priority, i.status, i.issue_date, i.created_at, i.updated_at,
               i.building_id, i.unit_id, i.lease_id, i.tenant_id,
               b.name AS building_name, u.unit_number, l.lease_number,
               t.first_name, t.last_name, t.company_name AS t_company, t.tenant_type, t.phone,
               (SELECT COUNT(*) FROM re_legal_issue_files f WHERE f.issue_id = i.id) AS files,
               (SELECT f.id FROM re_legal_issue_files f WHERE f.issue_id = i.id AND f.mime_type LIKE 'image/%' ORDER BY f.id LIMIT 1) AS thumb
        FROM re_legal_issues i
        LEFT JOIN re_buildings b ON b.id = i.building_id
        LEFT JOIN re_units u ON u.id = i.unit_id
        LEFT JOIN re_leases l ON l.id = i.lease_id
        LEFT JOIN re_tenants t ON t.id = i.tenant_id
        WHERE i.company_id IN ($in) AND i.deleted_at IS NULL
        ORDER BY FIELD(i.status, 'open', 'in_progress', 'resolved', 'closed'),
                 FIELD(i.priority, 'urgent', 'high', 'medium', 'low'), i.issue_date DESC, i.id DESC");
    $st->execute($cids);
    $issues = $st->fetchAll(PDO::FETCH_ASSOC);
}

$types = legal_issue_types();
$sMeta = legal_issue_status_meta();
$cnt = ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0, 'urgent' => 0, 'month' => 0];
$byType = [];
foreach ($issues as &$i) {
    $i['tenant_label'] = $i['tenant_id'] ? legal_tenant_name(['first_name' => $i['first_name'], 'last_name' => $i['last_name'],
                          'company_name' => $i['t_company'], 'tenant_type' => $i['tenant_type']]) : '';
    $cnt[$i['status']]++;
    $active = in_array($i['status'], ['open', 'in_progress'], true);
    if ($active && in_array($i['priority'], ['urgent', 'high'], true)) $cnt['urgent']++;
    if (substr($i['issue_date'], 0, 7) === date('Y-m')) $cnt['month']++;
    if ($active) $byType[$i['issue_type']] = ($byType[$i['issue_type']] ?? 0) + 1;
}
unset($i);
arsort($byType);
$buildings = [];
foreach ($issues as $i) if ($i['building_id']) $buildings[(int)$i['building_id']] = $i['building_name'];
asort($buildings);

$strip = [
    ['active', 'rose', 'bi-exclamation-circle-fill', 'Open', $cnt['open'] + $cnt['in_progress'], $cnt['in_progress'] . ' in progress'],
    ['urgent', 'violet', 'bi-lightning-charge-fill', 'Urgent / high', $cnt['urgent'], 'still open'],
    ['month', 'navy', 'bi-calendar3', 'Logged this month', $cnt['month'], date('F Y')],
    ['resolved', 'sky', 'bi-check-circle-fill', 'Resolved / closed', $cnt['resolved'] + $cnt['closed'], 'all time'],
];

$pageTitle = 'Issues';
$pageHead = '
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<link href="assets/legal-issues.css?v=' . (@filemtime(__DIR__ . '/assets/legal-issues.css') ?: 1) . '" rel="stylesheet">';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
<div class="lw-page li-page">
    <div class="li-head">
        <div>
            <h1><i class="bi bi-flag"></i> Issues</h1>
            <span class="lw-muted">Problems logged against a unit, its tenant and contract — with photo evidence.</span>
        </div>
        <a href="legal_issue_add.php" class="btn lw-btn-grad"><i class="bi bi-plus-lg"></i> New issue</a>
    </div>

    <?php if (!$ready): ?>
        <div class="alert alert-danger">The Issues tables are missing. Run <code>migrations/re_legal_issues.sql</code> in phpMyAdmin.</div>
    <?php endif; ?>
    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>

    <div class="lw-strip mb-3" id="liStrip">
        <?php foreach ($strip as [$key, $tone, $icon, $label, $n, $sub]): ?>
            <div class="lw-alert lw-seg lw-st-<?= h($tone) ?>" data-filter="<?= h($key) ?>">
                <button type="button" class="lw-alert-btn" data-filter="<?= h($key) ?>" aria-label="<?= h($label) ?>"></button>
                <span class="lw-seg-ico"><i class="bi <?= h($icon) ?>"></i></span>
                <div class="lw-seg-body">
                    <div class="lw-seg-label"><?= h($label) ?></div>
                    <div class="lw-seg-big"><?= (int)$n ?></div>
                    <div class="lw-seg-sub"><?= h($sub) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="lw-panel p-0 li-list">
        <div class="li-bar">
            <div class="li-f"><select id="liFStatus" class="form-select form-select-sm">
                <option value="active">Open &amp; in progress</option>
                <option value="">All statuses</option>
                <?php foreach (legal_issue_statuses() as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
                <option value="urgent">Urgent / high, still open</option>
                <option value="month">Logged this month</option>
                <option value="resolved">Resolved / closed</option>
            </select></div>
            <div class="li-f"><select id="liFType" class="form-select form-select-sm">
                <option value="">All issue types</option>
                <?php foreach ($types as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
            </select></div>
            <div class="li-f"><select id="liFBuilding" class="form-select form-select-sm">
                <option value="">All buildings</option>
                <?php foreach ($buildings as $bid => $bn): ?><option value="<?= (int)$bid ?>"><?= h($bn) ?></option><?php endforeach; ?>
            </select></div>
            <div class="lw-search-box flex-grow-1"><i class="bi bi-search"></i><input type="search" id="liFSearch" placeholder="Issue no, title, tenant, unit, contract"></div>
        </div>

        <?php if (!$issues): ?>
            <div class="lw-empty-state py-5"><i class="bi bi-flag"></i>No issues logged yet.<br><a href="legal_issue_add.php" class="btn lw-btn-grad btn-sm mt-3"><i class="bi bi-plus-lg"></i> Log the first issue</a></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table lw-table li-table mb-0">
                    <thead><tr><th></th><th>Issue</th><th>Type</th><th>Unit</th><th>Tenant · contract</th><th>Status</th><th>Date</th><th></th></tr></thead>
                    <tbody id="liBody">
                    <?php foreach ($issues as $i):
                        $sm = $sMeta[$i['status']];
                        $active = in_array($i['status'], ['open', 'in_progress'], true);
                        $tags = [$i['status'], $active ? 'active' : 'resolved'];
                        if ($active && in_array($i['priority'], ['urgent', 'high'], true)) $tags[] = 'urgent';
                        if (substr($i['issue_date'], 0, 7) === date('Y-m')) $tags[] = 'month';
                        $search = strtolower(implode(' ', [$i['issue_number'], $i['title'], $i['tenant_label'], $i['building_name'], $i['unit_number'], $i['lease_number'], $i['phone']]));
                        $wa = legal_wa_number($i['phone']); ?>
                        <tr class="li-row li-p-<?= h($i['priority']) ?>" data-tags="<?= h(implode(' ', $tags)) ?>" data-type="<?= h($i['issue_type']) ?>"
                            data-building="<?= (int)$i['building_id'] ?>" data-search="<?= h($search) ?>" data-href="legal_issue_view.php?id=<?= (int)$i['id'] ?>">
                            <td class="li-thumbcell">
                                <?php if ($i['thumb']): ?><img src="legal_issue_file.php?id=<?= (int)$i['thumb'] ?>" alt="" loading="lazy">
                                <?php else: ?><span class="li-nothumb"><i class="bi bi-image"></i></span><?php endif; ?>
                                <?php if ($i['files'] > 1): ?><span class="li-fcount"><?= (int)$i['files'] ?></span><?php endif; ?>
                            </td>
                            <td class="li-issue">
                                <a href="legal_issue_view.php?id=<?= (int)$i['id'] ?>"><?= h($i['title']) ?></a>
                                <span class="lw-muted d-block"><?= h($i['issue_number']) ?> · <span class="li-pri li-pri-<?= h($i['priority']) ?>"><?= h(ucfirst($i['priority'])) ?></span></span>
                            </td>
                            <td><span class="li-type"><?= h($types[$i['issue_type']] ?? $i['issue_type']) ?></span></td>
                            <td class="text-nowrap"><?= h($i['building_name'] ?: '—') ?><span class="lw-muted d-block">Unit <?= h($i['unit_number'] ?: '—') ?></span></td>
                            <td>
                                <?php if ($i['tenant_label']): ?>
                                    <div class="li-tcell"><span class="li-ten text-truncate"><?= h($i['tenant_label']) ?></span>
                                        <?php if ($wa): ?><a class="li-wa li-wa-sm" target="_blank" rel="noopener" href="https://wa.me/<?= h($wa) ?>" title="WhatsApp <?= h($i['phone']) ?>"><i class="bi bi-whatsapp"></i><?= h($i['phone']) ?></a><?php endif; ?></div>
                                    <span class="lw-muted"><?= h($i['lease_number'] ?: '') ?></span>
                                <?php else: ?><span class="lw-muted">Unit only</span><?php endif; ?>
                            </td>
                            <td><span class="li-st li-st-<?= h($sm[1]) ?>"><i class="bi <?= h($sm[2]) ?>"></i><?= h($sm[0]) ?></span></td>
                            <td class="text-nowrap"><?= h(date('j M Y', strtotime($i['issue_date']))) ?></td>
                            <td class="text-end"><a class="btn btn-outline-secondary btn-sm" href="legal_issue_view.php?id=<?= (int)$i['id'] ?>"><i class="bi bi-chevron-right"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="lw-empty-state d-none" id="liEmpty"><i class="bi bi-search"></i>No issues match these filters.</div>
            </div>
            <div class="li-foot lw-muted" id="liShown"></div>
        <?php endif; ?>
    </div>
</div>
<?php
$pageScripts = <<<'HTML'
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function () {
    const rows = Array.from(document.querySelectorAll('#liBody tr'));
    const st = document.getElementById('liFStatus'), ty = document.getElementById('liFType'), bd = document.getElementById('liFBuilding'), q = document.getElementById('liFSearch');
    const hasS2 = !!(window.jQuery && jQuery.fn.select2);
    function apply() {
        const s = st.value, t = ty.value, b = bd.value, k = q.value.trim().toLowerCase();
        let n = 0;
        rows.forEach(r => {
            const ok = (!s || (' ' + r.dataset.tags + ' ').includes(' ' + s + ' ')) && (!t || r.dataset.type === t)
                && (!b || r.dataset.building === b) && (!k || r.dataset.search.includes(k));
            r.classList.toggle('d-none', !ok);
            if (ok) n++;
        });
        const e = document.getElementById('liEmpty'); if (e) e.classList.toggle('d-none', n > 0 || !rows.length);
        const sh = document.getElementById('liShown'); if (sh) sh.textContent = 'Showing ' + n + ' of ' + rows.length + ' issues';
        document.querySelectorAll('#liStrip .lw-seg').forEach(x => x.classList.toggle('is-active', x.dataset.filter === s));
    }
    if (hasS2) {
        [st, ty, bd].forEach(el => jQuery(el).select2({theme: 'bootstrap-5', width: '100%'}).on('change', apply));
    } else [st, ty, bd].forEach(el => el.addEventListener('change', apply));
    q.addEventListener('input', apply);
    document.querySelectorAll('#liStrip .lw-alert-btn').forEach(b => b.addEventListener('click', () => {
        st.value = b.dataset.filter; ty.value = ''; bd.value = ''; q.value = '';
        if (hasS2) [st, ty, bd].forEach(el => jQuery(el).trigger('change.select2'));
        apply();
    }));
    rows.forEach(r => r.addEventListener('click', e => { if (!e.target.closest('a,button')) location.href = r.dataset.href; }));
    apply();
})();
</script>
HTML;
require_once __DIR__ . '/includes/legal_layout_footer.php';
?>
