<?php
/**
 * Legal - Issues: one issue - details, tenant & contract, evidence gallery, status and timeline.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/legal_helper.php';
require_once __DIR__ . '/includes/legal_issues_helper.php';
require_once __DIR__ . '/includes/legal_lease_watch.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
if (!legal_issues_ready($conn)) { header('Location: legal_issues.php'); exit; }

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$load = function () use ($conn, $id) {
    $st = $conn->prepare("
        SELECT i.*, b.name AS building_name, u.unit_number, c.name AS company_name,
               l.lease_number, l.status AS lease_status, l.start_date, l.end_date, l.annual_rent,
               t.first_name, t.last_name, t.company_name AS t_company, t.tenant_type, t.phone, t.email,
               cu.fullname AS created_by_name
        FROM re_legal_issues i
        LEFT JOIN re_buildings b ON b.id = i.building_id
        LEFT JOIN re_units u ON u.id = i.unit_id
        LEFT JOIN companies c ON c.id = i.company_id
        LEFT JOIN re_leases l ON l.id = i.lease_id
        LEFT JOIN re_tenants t ON t.id = i.tenant_id
        LEFT JOIN user cu ON cu.id = i.created_by
        WHERE i.id = ? AND i.deleted_at IS NULL");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};
$issue = $load();
if (!$issue || !legal_issue_can_company($conn, (int)$issue['company_id'], $currentCompanyId)) {
    http_response_code(404);
    $pageTitle = 'Issue not found';
    require_once __DIR__ . '/includes/legal_layout_header.php';
    echo '<div class="lw-empty-state"><i class="bi bi-flag"></i>Issue not found. <a href="legal_issues.php">Back to Issues</a></div>';
    require_once __DIR__ . '/includes/legal_layout_footer.php';
    exit;
}
$cid = (int)$issue['company_id'];
$statuses = legal_issue_statuses();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = (string)($_POST['act'] ?? '');
    $msg = '';
    if ($act === 'status') {
        $new = (string)($_POST['status'] ?? '');
        $note = trim((string)($_POST['note'] ?? ''));
        if (isset($statuses[$new]) && $new !== $issue['status']) {
            $conn->prepare("UPDATE re_legal_issues SET status = ?, resolved_at = " . (in_array($new, ['resolved', 'closed'], true) ? 'COALESCE(resolved_at, NOW())' : 'NULL') . " WHERE id = ? AND company_id = ?")
                 ->execute([$new, $id, $cid]);
            legal_issue_log($conn, $cid, $id, 'status', $note !== '' ? $note : null, $issue['status'], $new);
            $msg = 'Status changed to ' . $statuses[$new] . '.';
        } elseif ($note !== '') {
            legal_issue_log($conn, $cid, $id, 'note', $note);
            $msg = 'Note added.';
        }
    } elseif ($act === 'note') {
        $note = trim((string)($_POST['note'] ?? ''));
        if ($note !== '') { legal_issue_log($conn, $cid, $id, 'note', $note); $msg = 'Note added.'; }
    } elseif ($act === 'files') {
        [$saved, $errs] = legal_issue_save_files($conn, $cid, $id);
        if ($saved) legal_issue_log($conn, $cid, $id, 'evidence', $saved . ' evidence file' . ($saved === 1 ? '' : 's') . ' added');
        $msg = ($saved ? $saved . ' file' . ($saved === 1 ? '' : 's') . ' added.' : 'No files added.') . ($errs ? ' ' . implode(' ', $errs) : '');
    } elseif ($act === 'delete_file') {
        $st = $conn->prepare("SELECT file_path, file_name FROM re_legal_issue_files WHERE id = ? AND issue_id = ? AND company_id = ?");
        $st->execute([(int)($_POST['file_id'] ?? 0), $id, $cid]);
        if ($f = $st->fetch(PDO::FETCH_ASSOC)) {
            $base = realpath(LEGAL_ISSUE_DIR);
            $path = realpath(dirname(__DIR__, 2) . '/' . $f['file_path']);
            if ($base && $path && strpos($path, $base . DIRECTORY_SEPARATOR) === 0) @unlink($path);
            $conn->prepare("DELETE FROM re_legal_issue_files WHERE id = ? AND issue_id = ? AND company_id = ?")->execute([(int)$_POST['file_id'], $id, $cid]);
            legal_issue_log($conn, $cid, $id, 'evidence', 'Removed evidence file ' . $f['file_name']);
            $msg = 'File removed.';
        }
    } elseif ($act === 'delete_issue') {
        $conn->prepare("UPDATE re_legal_issues SET deleted_at = NOW() WHERE id = ? AND company_id = ?")->execute([$id, $cid]);
        $_SESSION['legal_issue_flash'] = 'Issue ' . $issue['issue_number'] . ' deleted.';
        header('Location: legal_issues.php');
        exit;
    }
    $_SESSION['legal_issue_flash'] = $msg;
    header('Location: legal_issue_view.php?id=' . $id);
    exit;
}

$flash = $_SESSION['legal_issue_flash'] ?? '';
unset($_SESSION['legal_issue_flash']);
$files = legal_issue_files($conn, $cid, $id);
$st = $conn->prepare("SELECT u.*, us.fullname AS full_name FROM re_legal_issue_updates u LEFT JOIN user us ON us.id = u.created_by
                      WHERE u.issue_id = ? AND u.company_id = ? ORDER BY u.id DESC");
$st->execute([$id, $cid]);
$timeline = $st->fetchAll(PDO::FETCH_ASSOC);

// What the contract owes right now (same figures as the Legal Dashboard).
$owed = null;
if ($issue['lease_id'] && $cid === (int)$currentCompanyId) {
    try {
        foreach (legal_lease_watch_rows($conn, $cid) as $r) {
            if ((int)$r['id'] === (int)$issue['lease_id']) { $owed = $r; break; }
        }
    } catch (Throwable $e) { $owed = null; }
}

$tenantLabel = $issue['tenant_id'] ? legal_tenant_name(['first_name' => $issue['first_name'], 'last_name' => $issue['last_name'],
                                     'company_name' => $issue['t_company'], 'tenant_type' => $issue['tenant_type']]) : '';
$wa = legal_wa_number($issue['phone']);
$sm = legal_issue_status_meta()[$issue['status']];
$types = legal_issue_types();
$caseUrl = 'legal_case_add.php?' . http_build_query(array_filter([
    'case_type' => $issue['issue_type'] === 'illegal_occupancy' ? 'eviction' : ($issue['issue_type'] === 'contract_breach' ? 'contract_breach' : 'other'),
    'case_source' => 'manual', 'title' => $issue['title'] . ' (' . $issue['issue_number'] . ')',
    'building_id' => $issue['building_id'], 'unit_id' => $issue['unit_id'], 'tenant_id' => $issue['tenant_id'], 'lease_id' => $issue['lease_id'],
    'priority' => in_array($issue['priority'], ['high', 'urgent'], true) ? 'high' : $issue['priority'],
]));
$fmtDT = fn($d) => $d ? date('j M Y, g:i a', strtotime($d)) : '';

$pageTitle = $issue['issue_number'];
$pageHead = '<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<link href="assets/legal-issues.css?v=' . (@filemtime(__DIR__ . '/assets/legal-issues.css') ?: 1) . '" rel="stylesheet">';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
<div class="lw-page li-page">
    <div class="li-head">
        <div class="min-w-0">
            <div class="li-crumb"><a href="legal_issues.php">Issues</a> / <?= h($issue['issue_number']) ?></div>
            <h1 class="li-title"><?= h($issue['title']) ?></h1>
            <div class="li-meta">
                <span class="li-st li-st-<?= h($sm[1]) ?>"><i class="bi <?= h($sm[2]) ?>"></i><?= h($sm[0]) ?></span>
                <span class="li-pri li-pri-<?= h($issue['priority']) ?>"><?= h(legal_issue_priorities()[$issue['priority']] ?? $issue['priority']) ?></span>
                <span class="lw-muted"><i class="bi bi-tag"></i> <?= h($types[$issue['issue_type']] ?? $issue['issue_type']) ?></span>
                <span class="lw-muted"><i class="bi bi-calendar3"></i> <?= h(date('j M Y', strtotime($issue['issue_date']))) ?></span>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= h($caseUrl) ?>" class="btn lw-btn-grad"><i class="bi bi-briefcase"></i> Open legal case</a>
            <a href="legal_notice_add.php" class="btn lw-btn-soft"><i class="bi bi-envelope-paper"></i> Draft notice</a>
        </div>
    </div>
    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="lw-panel mb-3">
                <div class="lw-panel-head"><h2>Details</h2></div>
                <div class="li-desc"><?= $issue['description'] ? nl2br(h($issue['description'])) : '<span class="lw-muted">No description.</span>' ?></div>
                <div class="li-facts">
                    <div><span>Company</span><b><?= h($issue['company_name']) ?></b></div>
                    <div><span>Building</span><b><?= h($issue['building_name'] ?: '—') ?></b></div>
                    <div><span>Unit</span><b><?= h($issue['unit_number'] ?: '—') ?></b></div>
                    <div><span>Logged by</span><b><?= h($issue['created_by_name'] ?: '—') ?></b><small class="lw-muted"><?= h($fmtDT($issue['created_at'])) ?></small></div>
                </div>
            </div>

            <div class="lw-panel mb-3">
                <div class="lw-panel-head">
                    <h2>Evidence <span class="lw-muted">(<?= count($files) ?>)</span></h2>
                    <form method="POST" enctype="multipart/form-data" id="liAddFiles" class="m-0">
                        <?php csrf_field(); ?><input type="hidden" name="act" value="files"><input type="hidden" name="id" value="<?= $id ?>">
                        <label class="btn lw-btn-soft btn-sm m-0"><i class="bi bi-plus-lg"></i> Add photos
                            <input type="file" name="evidence[]" multiple accept="image/jpeg,image/png,image/webp,image/gif,application/pdf" class="d-none" onchange="this.form.submit()">
                        </label>
                    </form>
                </div>
                <?php if (!$files): ?>
                    <div class="lw-empty-state"><i class="bi bi-images"></i>No evidence yet. Add photos of the issue.</div>
                <?php else: ?>
                    <div class="li-gallery">
                        <?php foreach ($files as $n => $f): $isImg = str_starts_with((string)$f['mime_type'], 'image/'); ?>
                            <div class="li-gitem">
                                <?php if ($isImg): ?>
                                    <button type="button" class="li-gimg" data-idx="<?= $n ?>" title="<?= h($f['file_name']) ?>">
                                        <img src="legal_issue_file.php?id=<?= (int)$f['id'] ?>" alt="<?= h($f['file_name']) ?>" loading="lazy">
                                    </button>
                                <?php else: ?>
                                    <a class="li-gimg li-gpdf" href="legal_issue_file.php?id=<?= (int)$f['id'] ?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i><span>PDF</span></a>
                                <?php endif; ?>
                                <div class="li-gcap">
                                    <span class="text-truncate" title="<?= h($f['file_name']) ?>"><?= h($f['file_name']) ?></span>
                                    <a href="legal_issue_file.php?id=<?= (int)$f['id'] ?>&mode=download" title="Download"><i class="bi bi-download"></i></a>
                                    <form method="POST" class="m-0" onsubmit="return confirm('Remove this file?')">
                                        <?php csrf_field(); ?><input type="hidden" name="act" value="delete_file"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                                        <button type="submit" class="li-del" title="Remove"><i class="bi bi-trash3"></i></button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="lw-panel mb-3">
                <div class="lw-panel-head"><h2>Timeline</h2></div>
                <form method="POST" class="li-note-form">
                    <?php csrf_field(); ?><input type="hidden" name="act" value="note"><input type="hidden" name="id" value="<?= $id ?>">
                    <input type="text" name="note" class="form-control" placeholder="Add a note — call made, visit done, tenant reply…" maxlength="2000" required>
                    <button type="submit" class="btn lw-btn-grad"><i class="bi bi-send"></i></button>
                </form>
                <ul class="li-timeline">
                    <?php foreach ($timeline as $t):
                        $ico = ['created' => 'bi-flag-fill', 'status' => 'bi-arrow-left-right', 'note' => 'bi-chat-left-text-fill', 'evidence' => 'bi-images'][$t['kind']] ?? 'bi-dot'; ?>
                        <li class="li-tl-<?= h($t['kind']) ?>">
                            <span class="li-tl-ico"><i class="bi <?= $ico ?>"></i></span>
                            <div>
                                <?php if ($t['kind'] === 'status'): ?>
                                    <b>Status: <?= h($statuses[$t['old_status']] ?? $t['old_status']) ?> → <?= h($statuses[$t['new_status']] ?? $t['new_status']) ?></b>
                                    <?php if ($t['note']): ?><div><?= nl2br(h($t['note'])) ?></div><?php endif; ?>
                                <?php else: ?>
                                    <div><?= nl2br(h((string)$t['note'])) ?></div>
                                <?php endif; ?>
                                <small class="lw-muted"><?= h($t['full_name'] ?: 'System') ?> · <?= h($fmtDT($t['created_at'])) ?></small>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="lw-panel mb-3">
                <div class="lw-panel-head"><h2>Status</h2></div>
                <form method="POST">
                    <?php csrf_field(); ?><input type="hidden" name="act" value="status"><input type="hidden" name="id" value="<?= $id ?>">
                    <select name="status" class="form-select mb-2" id="liStatus">
                        <?php foreach ($statuses as $k => $v): ?><option value="<?= h($k) ?>" <?= $k === $issue['status'] ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
                    </select>
                    <textarea name="note" class="form-control mb-2" rows="2" placeholder="Note (optional)"></textarea>
                    <button type="submit" class="btn lw-btn-grad w-100">Update status</button>
                </form>
                <?php if ($issue['resolved_at']): ?><div class="lw-muted mt-2"><i class="bi bi-check2-circle"></i> Resolved <?= h($fmtDT($issue['resolved_at'])) ?></div><?php endif; ?>
            </div>

            <div class="lw-panel mb-3">
                <div class="lw-panel-head"><h2>Tenant &amp; contract</h2></div>
                <?php if (!$issue['tenant_id']): ?>
                    <div class="li-tenant-empty"><i class="bi bi-person-badge"></i>No contract linked — logged against the unit only.</div>
                <?php else: ?>
                    <div class="li-tenant">
                        <div class="li-tname"><?= h($tenantLabel) ?></div>
                        <div class="li-tlinks">
                            <?php if ($wa): ?><a class="li-wa" target="_blank" rel="noopener" href="https://wa.me/<?= h($wa) ?>"><i class="bi bi-whatsapp"></i><?= h($issue['phone']) ?></a><?php endif; ?>
                            <?php if ($issue['email']): ?><a class="li-mail" href="mailto:<?= h($issue['email']) ?>"><i class="bi bi-envelope"></i><?= h($issue['email']) ?></a><?php endif; ?>
                        </div>
                        <dl class="li-dl">
                            <?php if ($issue['lease_id']): ?>
                                <dt>Contract</dt><dd><a href="../realestate/lease_view.php?id=<?= (int)$issue['lease_id'] ?>"><?= h($issue['lease_number'] ?: '#' . $issue['lease_id']) ?></a>
                                    <span class="lw-status <?= h($issue['lease_status']) ?>"><?= h(str_replace('_', ' ', (string)$issue['lease_status'])) ?></span></dd>
                                <dt>Period</dt><dd><?= h(date('j M Y', strtotime($issue['start_date']))) ?> – <?= h(date('j M Y', strtotime($issue['end_date']))) ?>
                                    <?php if ($issue['end_date'] < date('Y-m-d')): ?><span class="li-ended">ended</span><?php endif; ?></dd>
                                <?php if ((float)$issue['annual_rent'] > 0): ?><dt>Annual rent</dt><dd>AED <?= number_format((float)$issue['annual_rent']) ?></dd><?php endif; ?>
                            <?php endif; ?>
                            <?php if ($owed): ?>
                                <dt>Owed now</dt><dd><?= $owed['outstanding'] > 0.005 ? '<b class="text-danger">AED ' . number_format((float)$owed['outstanding']) . '</b>' : '<span class="text-success">Nothing owed</span>' ?></dd>
                                <?php if ($owed['flags']): ?><dt>Flags</dt><dd>
                                    <?php foreach ($owed['flags'] as $fl): [$fl0, , $fi] = legal_watch_flag_meta()[$fl]; ?>
                                        <span class="lw-flag lw-flag-<?= h($fl) ?>"><i class="bi <?= h($fi) ?>"></i> <?= h($fl0) ?></span>
                                    <?php endforeach; ?></dd><?php endif; ?>
                            <?php endif; ?>
                        </dl>
                    </div>
                <?php endif; ?>
            </div>

            <form method="POST" onsubmit="return confirm('Delete this issue? It disappears from the list.')">
                <?php csrf_field(); ?><input type="hidden" name="act" value="delete_issue"><input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn btn-link text-danger p-0 small"><i class="bi bi-trash3"></i> Delete issue</button>
            </form>
        </div>
    </div>

    <!-- Photo viewer -->
    <div class="modal fade" id="liViewer" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content li-viewer">
                <button type="button" class="li-vx" data-bs-dismiss="modal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
                <button type="button" class="li-vnav prev" aria-label="Previous"><i class="bi bi-chevron-left"></i></button>
                <img id="liViewerImg" alt="">
                <button type="button" class="li-vnav next" aria-label="Next"><i class="bi bi-chevron-right"></i></button>
                <div class="li-vcap" id="liViewerCap"></div>
            </div>
        </div>
    </div>
</div>
<?php
$pageScripts = <<<'HTML'
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function () {
    if (window.jQuery && jQuery.fn.select2) jQuery('#liStatus').select2({theme: 'bootstrap-5', width: '100%'});
    const imgs = Array.from(document.querySelectorAll('.li-gimg[data-idx] img'));
    if (!imgs.length || !window.bootstrap) return;
    const modalEl = document.getElementById('liViewer'), big = document.getElementById('liViewerImg'), cap = document.getElementById('liViewerCap');
    let i = 0;
    const show = n => { i = (n + imgs.length) % imgs.length; big.src = imgs[i].src; cap.textContent = (i + 1) + ' / ' + imgs.length + ' · ' + imgs[i].alt; };
    imgs.forEach((im, n) => im.parentElement.addEventListener('click', () => { show(n); bootstrap.Modal.getOrCreateInstance(modalEl).show(); }));
    modalEl.querySelector('.prev').addEventListener('click', () => show(i - 1));
    modalEl.querySelector('.next').addEventListener('click', () => show(i + 1));
    document.addEventListener('keydown', e => {
        if (!modalEl.classList.contains('show')) return;
        if (e.key === 'ArrowLeft') show(i - 1);
        if (e.key === 'ArrowRight') show(i + 1);
    });
})();
</script>
HTML;
require_once __DIR__ . '/includes/legal_layout_footer.php';
?>
