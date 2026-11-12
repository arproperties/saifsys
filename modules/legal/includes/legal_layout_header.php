<?php
/**
 * Legal Department — standalone module layout (no Real Estate sidebar).
 */
if (!isset($brand)) {
    require_once dirname(__DIR__, 3) . '/includes/branding.php';
    $brand = getBrandSettings($conn);
}
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$currentPage = basename($_SERVER['PHP_SELF']);
$appBase = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/herosysgro') !== false) ? '/herosysgro' : '';
$legalBase = $appBase . '/modules/legal';

$U = $_SESSION['user'] ?? [];
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username'] ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));

require_once dirname(__DIR__, 3) . '/includes/rbac_department.php';
require_once __DIR__ . '/legal_helper.php';
require_once dirname(__DIR__, 3) . '/includes/csrf.php';
$hasLegal = legal_can_manage($conn);

// Hide "Switch Module" for users locked to Legal only.
// Owners/Admins (or anyone with access to more than one module) keep the link.
$canSwitchModule = true;
try {
    require_once dirname(__DIR__, 3) . '/includes/module_access.php';
    $legalUid = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
    $legalRoleNames = $_SESSION['role_names'] ?? [];
    $isOwnerAdmin = in_array('Owner', $legalRoleNames, true) || in_array('Admin', $legalRoleNames, true);
    if (!$isOwnerAdmin && $legalUid > 0) {
        $legalUserModules = get_user_modules($conn, $legalUid);
        $legalModuleKeys = array_filter(array_unique(array_map(
            static function ($m) { return is_array($m) ? ($m['module'] ?? '') : (string)$m; },
            $legalUserModules
        )));
        $canSwitchModule = count($legalModuleKeys) > 1;
    }
} catch (Throwable $e) {
    $canSwitchModule = true; // fail open so users are never trapped
}

$leasePages = ['legal_cases.php'];
$casePages = ['legal_case_list.php','legal_case_add.php','legal_case_edit.php','legal_case_view.php'];
$noticePages = ['legal_notices.php','legal_notice_add.php','legal_notice_view.php'];
$pdcPages = ['legal_billing_cheques.php'];
$issuePages = ['legal_issues.php','legal_issue_add.php','legal_issue_view.php'];
$timelinePages = ['legal_tenant_timeline.php'];
$documentPages = ['legal_documents.php'];
$calendarPages = ['legal_calendar.php','legal_hearing_add.php','legal_hearing_edit.php'];
$counselPages = ['legal_counsel.php','legal_counsel_add.php','legal_counsel_edit.php'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? h($pageTitle) . ' - Legal | ' : 'Legal | ' ?><?= h($brand['system_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/legal.css?v=<?= @filemtime(__DIR__ . '/../assets/legal.css') ?: 1 ?>" rel="stylesheet">
    <?php if (isset($pageHead)) echo $pageHead; ?>
</head>
<body>
<?php
// Check-out bar for staff who have checked in. Prints nothing when the
// feature is off or the person has no attendance to record.
$asWidget = dirname(__DIR__, 3) . '/includes/attendance_self_widget.php';
if (is_file($asWidget)) { require $asWidget; }
?>
<div class="d-flex">
    <aside id="legalSb" class="legal-sidebar d-flex flex-column">
        <div class="brand">
            <?php if ($logoSrc = brand_logo_src($brand)): ?>
                <img src="<?= h($logoSrc) ?>" alt="<?= h($brand['system_name']) ?>" style="width:34px;height:34px;object-fit:contain;border-radius:8px;">
            <?php else: ?>
                <i class="bi bi-bank2"></i>
            <?php endif; ?>
            <span class="sb-label">Legal Department</span>
        </div>
        <?php if ($hasLegal): ?>
        <?php require_once dirname(__DIR__, 3) . '/includes/nav_search.php'; ?>
        <?= nav_search_box() ?>
        <nav class="flex-grow-1 py-2">
            <div class="legal-nav-section">Overview</div>
            <a href="legal_dashboard.php" class="legal-nav-link <?= $currentPage === 'legal_dashboard.php' ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i><span class="sb-label">Dashboard</span>
            </a>
            <div class="legal-nav-section">Cases &amp; Notices</div>
            <a href="legal_cases.php" class="legal-nav-link <?= in_array($currentPage, $leasePages, true) ? 'active' : '' ?>">
                <i class="bi bi-house-exclamation"></i><span class="sb-label">Leases</span>
            </a>
            <a href="legal_case_list.php" class="legal-nav-link <?= in_array($currentPage, $casePages, true) ? 'active' : '' ?>">
                <i class="bi bi-briefcase"></i><span class="sb-label">Legal Cases</span>
            </a>
            <a href="legal_notices.php" class="legal-nav-link <?= in_array($currentPage, $noticePages, true) ? 'active' : '' ?>">
                <i class="bi bi-envelope-paper"></i><span class="sb-label">Legal Notices</span>
            </a>
            <a href="legal_issues.php" class="legal-nav-link <?= in_array($currentPage, $issuePages, true) ? 'active' : '' ?>">
                <i class="bi bi-flag"></i><span class="sb-label">Issues</span>
            </a>
            <a href="legal_tenant_timeline.php" class="legal-nav-link <?= in_array($currentPage, $timelinePages, true) ? 'active' : '' ?>">
                <i class="bi bi-clock-history"></i><span class="sb-label">Tenant Timeline</span>
            </a>
            <div class="legal-nav-section">Collections &amp; Files</div>
            <a href="legal_cheque_notifications.php" class="legal-nav-link <?= $currentPage === 'legal_cheque_notifications.php' ? 'active' : '' ?>">
                <i class="bi bi-bell"></i><span class="sb-label">Cheque Notifications</span>
            </a>
            <a href="legal_billing_cheques.php" class="legal-nav-link <?= in_array($currentPage, $pdcPages, true) ? 'active' : '' ?>">
                <i class="bi bi-bank"></i><span class="sb-label">Post-Dated Cheques</span>
            </a>
            <a href="legal_documents.php" class="legal-nav-link <?= in_array($currentPage, $documentPages, true) ? 'active' : '' ?>">
                <i class="bi bi-folder2-open"></i><span class="sb-label">Documents</span>
            </a>
            <div class="legal-nav-section">Planning &amp; Reports</div>
            <a href="legal_calendar.php" class="legal-nav-link <?= in_array($currentPage, $calendarPages, true) ? 'active' : '' ?>">
                <i class="bi bi-calendar3"></i><span class="sb-label">Hearings Calendar</span>
            </a>
            <a href="legal_counsel.php" class="legal-nav-link <?= in_array($currentPage, $counselPages, true) ? 'active' : '' ?>">
                <i class="bi bi-person-badge"></i><span class="sb-label">External Counsel</span>
            </a>
            <a href="legal_reports.php" class="legal-nav-link <?= $currentPage === 'legal_reports.php' ? 'active' : '' ?>">
                <i class="bi bi-bar-chart-line"></i><span class="sb-label">Reports</span>
            </a>
        </nav>
        <?php endif; ?>
        <?php if ($canSwitchModule): ?>
        <div class="p-3 border-top border-secondary">
            <a href="<?= h($appBase) ?>/select-module" class="legal-nav-link"><i class="bi bi-grid"></i><span class="sb-label">Switch Module</span></a>
        </div>
        <?php endif; ?>
    </aside>
    <div class="legal-main">
        <div class="legal-topbar d-flex justify-content-between align-items-center">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="legalSbToggle"><i class="bi bi-list"></i></button>
            <div class="d-flex align-items-center gap-2">
                <a href="legal_dashboard.php" class="lw-top-btn" id="lwBackBtn" title="Back to the previous screen"><i class="bi bi-arrow-left"></i><span>Back</span></a>
                <a href="<?= $canSwitchModule ? h($appBase) . '/select-module' : 'legal_dashboard.php' ?>" class="lw-top-btn lw-top-home" title="<?= $canSwitchModule ? 'Home — all modules' : 'Legal home' ?>"><i class="bi bi-house-door"></i><span>Home</span></a>
                <div class="lw-bell-wrap" id="lwBellWrap">
                    <button type="button" class="lw-bell" id="lwBell" aria-label="Notifications" aria-expanded="false" title="Notifications">
                        <i class="bi bi-bell"></i><span class="lw-bell-count d-none" id="lwBellCount">0</span>
                    </button>
                    <div class="lw-bell-menu" id="lwBellMenu" role="menu" hidden>
                        <div class="lw-bell-head">
                            <b>Notifications</b>
                            <button type="button" class="lw-bell-sound" id="lwBellSound" title="Sound on/off"><i class="bi bi-volume-up"></i></button>
                        </div>
                        <div class="lw-bell-list" id="lwBellList"><div class="lw-bell-empty">Loading…</div></div>
                        <a class="lw-bell-foot" href="legal_cheque_notifications.php">Open Cheque Notifications <i class="bi bi-arrow-right"></i></a>
                    </div>
                </div>
                <span class="lw-top-sep"></span>
                <span class="lw-top-badge">Legal</span>
                <span class="lw-top-name"><?= h($fullName ?: $userName) ?></span>
                <span class="lw-top-avatar"><?= h($avatarInitial) ?></span>
            </div>
        </div>
        <div class="lw-toast" id="lwToast" hidden></div>
        <script>
        (function () {
            // ---- Notification bell: polls every 30 s, rings when something new arrives ----
            var csrf = <?= json_encode(csrf_token()) ?>;
            var bell = document.getElementById('lwBell'), menu = document.getElementById('lwBellMenu'),
                list = document.getElementById('lwBellList'), cnt = document.getElementById('lwBellCount'),
                toast = document.getElementById('lwToast'), sndBtn = document.getElementById('lwBellSound');
            if (!bell) return;
            var KEY = 'lwBellLatest', SND = 'lwBellSound', actx = null;
            function ls(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } }
            function soundOn() { return ls(SND) !== 'off'; }
            function paintSnd() { sndBtn.innerHTML = '<i class="bi bi-volume-' + (soundOn() ? 'up' : 'mute') + '"></i>'; }
            paintSnd();
            // Browsers only allow sound after the user has clicked somewhere on the page once.
            document.addEventListener('pointerdown', function () {
                var AC = window.AudioContext || window.webkitAudioContext;
                if (!actx && AC) actx = new AC();
                if (actx && actx.state === 'suspended') actx.resume();
            });
            function ding() {
                if (!soundOn() || !actx) return;
                var t = actx.currentTime;
                [[880, 0], [1320, 0.16]].forEach(function (n) {
                    var o = actx.createOscillator(), g = actx.createGain();
                    o.type = 'sine'; o.frequency.value = n[0];
                    g.gain.setValueAtTime(0.0001, t + n[1]);
                    g.gain.exponentialRampToValueAtTime(0.25, t + n[1] + 0.02);
                    g.gain.exponentialRampToValueAtTime(0.0001, t + n[1] + 0.45);
                    o.connect(g); g.connect(actx.destination); o.start(t + n[1]); o.stop(t + n[1] + 0.5);
                });
            }
            sndBtn.addEventListener('click', function (e) { e.stopPropagation(); ls(SND, soundOn() ? 'off' : 'on'); paintSnd(); setTimeout(ding, 50); });
            var ESC = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};
            function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return ESC[c]; }); }
            function ago(at, now) {
                var s = (new Date(now.replace(' ', 'T')) - new Date(at.replace(' ', 'T'))) / 1000;
                if (s < 60) return 'just now';
                if (s < 3600) return Math.floor(s / 60) + ' min ago';
                if (s < 86400) return Math.floor(s / 3600) + ' h ago';
                if (s < 86400 * 30) return Math.floor(s / 86400) + ' d ago';
                return at.slice(0, 10);
            }
            var ICON = {bounce: 'bi-x-octagon-fill', cheque: 'bi-bank', 'case': 'bi-briefcase-fill', issue: 'bi-flag-fill'};
            function render(d) {
                cnt.textContent = d.unread > 99 ? '99+' : d.unread;
                cnt.classList.toggle('d-none', !d.unread);
                bell.classList.toggle('has-new', d.unread > 0);
                list.innerHTML = d.items.length ? d.items.map(function (it) {
                    return '<a class="lw-bell-item' + (it.unread ? ' unread' : '') + (it.done ? ' done' : '') + '" href="' + esc(it.url) + '">'
                        + '<span class="lw-bell-ico k-' + esc(it.kind) + '"><i class="bi ' + (ICON[it.kind] || 'bi-bell') + '"></i></span>'
                        + '<span class="lw-bell-txt"><b>' + esc(it.title) + '</b><span>' + esc(it.sub) + '</span>'
                        + '<small>' + (it.who ? esc(it.who) + ' · ' : '') + esc(ago(it.at, d.now)) + (it.done ? ' · handled' : '') + '</small></span></a>';
                }).join('') : '<div class="lw-bell-empty"><i class="bi bi-bell-slash"></i>No notifications yet</div>';
            }
            function poll() {
                fetch('legal_notifications.php', {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (d) {
                        if (!d || !d.ok) return;
                        render(d);
                        var prev = ls(KEY);
                        if (d.latest && prev && d.latest > prev) {
                            var fresh = d.items.filter(function (it) { return it.at > prev; });
                            ding();
                            bell.classList.remove('ring'); void bell.offsetWidth; bell.classList.add('ring');
                            if (fresh[0]) {
                                toast.innerHTML = '<i class="bi bi-bell-fill"></i><div><b>' + esc(fresh[0].title) + '</b><span>' + esc(fresh[0].sub) + '</span></div>';
                                toast.hidden = false;
                                clearTimeout(toast._t);
                                toast._t = setTimeout(function () { toast.hidden = true; }, 6000);
                                toast.onclick = function () { location.href = fresh[0].url; };
                            }
                        }
                        if (d.latest) ls(KEY, d.latest);
                    }).catch(function () {});
            }
            function open(v) {
                menu.hidden = !v;
                bell.setAttribute('aria-expanded', v ? 'true' : 'false');
                if (v && !cnt.classList.contains('d-none')) {
                    var b = new FormData(); b.append('action', 'seen'); b.append('_csrf', csrf);
                    fetch('legal_notifications.php', {method: 'POST', body: b, credentials: 'same-origin'}).then(function () {
                        cnt.classList.add('d-none'); bell.classList.remove('has-new');
                    });
                }
            }
            bell.addEventListener('click', function (e) { e.stopPropagation(); open(menu.hidden); });
            document.addEventListener('click', function (e) { if (!menu.hidden && !e.target.closest('#lwBellWrap')) open(false); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') open(false); });
            poll();
            setInterval(function () { if (!document.hidden) poll(); }, 30000);
            document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
        })();
        (function () {
            // Back = the previous screen when there is one in this tab, else the Legal dashboard.
            var b = document.getElementById('lwBackBtn');
            if (b) b.addEventListener('click', function (e) {
                var ref = document.referrer;
                if (window.history.length > 1 && ref && ref.indexOf(location.host) !== -1 && ref !== location.href) { e.preventDefault(); history.back(); }
            });
        })();
        </script>
        <div class="container-fluid py-4 px-4 lw-page lw-shell">
