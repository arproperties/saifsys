        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Real Estate menu: drawer on tablet / phone, foldable rail on desktop
(function () {
    var sb = document.getElementById('sb');
    if (!sb) return;
    var html = document.documentElement, body = document.body;
    var btn = document.getElementById('sbToggle'), closeBtn = document.getElementById('sbClose'), backdrop = document.getElementById('reNavBackdrop');
    var desktop = window.matchMedia('(min-width: 992px)');
    var RAIL_KEY = 're_nav_rail', OPEN_KEY = 're_nav_sections';
    var lastFocus = null;

    function store(k, v) { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) {} }
    function read(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }

    // Hover names for the icons, only while the menu is folded to icons
    function syncTips() {
        var rail = desktop.matches && html.classList.contains('re-rail');
        sb.querySelectorAll('[data-tip]').forEach(function (el) {
            if (rail) el.setAttribute('title', el.getAttribute('data-tip')); else el.removeAttribute('title');
        });
    }
    function setRail(on) {
        html.classList.toggle('re-rail', on);
        store(RAIL_KEY, on ? '1' : null);
        syncTips();
        syncBtn();
    }
    function isOpen() { return body.classList.contains('re-nav-open'); }
    function openDrawer() {
        lastFocus = document.activeElement;
        body.classList.add('re-nav-open');
        syncBtn();
        var target = sb.querySelector('.slink.active') || sb.querySelector('.ns-input') || closeBtn;
        setTimeout(function () { try { (closeBtn || target).focus({ preventScroll: true }); } catch (e) {} }, 60);
        revealActive();
    }
    function closeDrawer(restore) {
        if (!isOpen()) return;
        body.classList.remove('re-nav-open');
        syncBtn();
        if (restore !== false && lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) {} }
    }
    function syncBtn() {
        if (!btn) return;
        var expanded = desktop.matches ? !html.classList.contains('re-rail') : isOpen();
        btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        btn.setAttribute('aria-label', desktop.matches ? (expanded ? 'Fold menu' : 'Unfold menu') : (expanded ? 'Close menu' : 'Open menu'));
        btn.title = btn.getAttribute('aria-label');
    }
    function revealActive() {
        var a = sb.querySelector('.slink.active');
        if (a && a.scrollIntoView) { try { a.scrollIntoView({ block: 'nearest' }); } catch (e) {} }
    }

    if (btn) btn.addEventListener('click', function () {
        if (desktop.matches) setRail(!html.classList.contains('re-rail'));
        else if (isOpen()) closeDrawer(); else openDrawer();
    });
    if (closeBtn) closeBtn.addEventListener('click', function () { closeDrawer(); });
    if (backdrop) backdrop.addEventListener('click', function () { closeDrawer(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen()) { closeDrawer(); }
        // Keep Tab inside the open drawer
        if (e.key === 'Tab' && isOpen()) {
            var f = [].slice.call(sb.querySelectorAll('a[href], button, input')).filter(function (el) { return el.offsetParent !== null; });
            if (!f.length) return;
            if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
            else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
        }
    });
    // A link in the drawer closes it (the page then changes)
    sb.addEventListener('click', function (e) {
        var a = e.target.closest('a[href]');
        if (a && !desktop.matches && !e.metaKey && !e.ctrlKey && !e.shiftKey) closeDrawer(false);
    });
    // Folded rail: a group icon unfolds the menu and opens that group
    sb.addEventListener('click', function (e) {
        var t = e.target.closest('.nav-sect-toggle');
        if (!t || !desktop.matches || !html.classList.contains('re-rail')) return;
        e.preventDefault();
        e.stopPropagation();
        setRail(false);
        var el = document.querySelector(t.getAttribute('data-bs-target'));
        if (el && window.bootstrap) bootstrap.Collapse.getOrCreateInstance(el, { toggle: false }).show();
    }, true);
    // Going from phone to desktop size: no drawer state left behind
    var onChange = function () { if (desktop.matches) closeDrawer(false); syncTips(); syncBtn(); };
    if (desktop.addEventListener) desktop.addEventListener('change', onChange); else if (desktop.addListener) desktop.addListener(onChange);

    // Only the section of the current page opens on load: opened sections are not remembered
    store(OPEN_KEY, null);

    syncTips();
    syncBtn();
    if (desktop.matches && !html.classList.contains('re-rail')) revealActive();
})();
</script>
<?php
if (!empty($reApUiEnhanced)) {
    require_once __DIR__ . '/re_ap_ui_assets.php';
    re_ap_ui_assets_footer();
}
?>
<?php if (isset($pageScripts)) echo $pageScripts; ?>
</body>
</html>
