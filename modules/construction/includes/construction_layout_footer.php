        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($coUiV2)): ?>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/htmx.org@1.9.12/dist/htmx.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/lucide@0.469.0/dist/umd/lucide.min.js"></script>
<script src="<?= $constructionBase ?? ((strpos($_SERVER['PHP_SELF'] ?? '', '/herosysgro') !== false ? '/herosysgro' : '') . '/modules/construction') ?>/assets/construction-ui-v2.js?v=20260729-light1"></script>
<?php endif; ?>
<script>
    (function() {
        var STORAGE_KEY = 'co_nav_sections';
        var sidebar = document.querySelector('#co-sidebar');
        var overlay = document.getElementById('co-sidebar-overlay');
        var menuBtn = document.getElementById('co-mobile-menu-btn');
        function closeMobileSidebar() {
            if (sidebar) sidebar.classList.remove('mobile-open');
            if (overlay) overlay.classList.remove('show');
            document.body.style.overflow = '';
        }
        function openMobileSidebar() {
            if (sidebar) sidebar.classList.add('mobile-open');
            if (overlay) overlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        function toggleMobileSidebar() {
            if (sidebar && sidebar.classList.contains('mobile-open')) closeMobileSidebar();
            else openMobileSidebar();
        }
        if (menuBtn) menuBtn.addEventListener('click', toggleMobileSidebar);
        if (overlay) overlay.addEventListener('click', closeMobileSidebar);
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeMobileSidebar(); });
        if (sidebar) {
            sidebar.querySelectorAll('.slink').forEach(function(a) { a.addEventListener('click', closeMobileSidebar); });
        }
        if (!sidebar) return;
        function getCollapses() { return sidebar.querySelectorAll('.nav-group .collapse'); }
        function getOpenIds() {
            var ids = [];
            getCollapses().forEach(function(el) {
                if (el.id && el.classList.contains('show')) ids.push(el.id);
            });
            return ids;
        }
        function sectionHasActive(el) {
            return !!(el && el.querySelector('.slink.active'));
        }
        function setSectionOpen(el, shouldShow) {
            if (!el || !el.id) return;
            var btn = sidebar.querySelector('[data-bs-target="#' + el.id + '"]');
            if (btn) {
                btn.setAttribute('aria-expanded', shouldShow ? 'true' : 'false');
                btn.classList.toggle('is-active-section', sectionHasActive(el));
            }
            el.classList.toggle('show', shouldShow);
        }
        function applySaved() {
            try {
                var saved = localStorage.getItem(STORAGE_KEY);
                var ids = saved ? JSON.parse(saved) : [];
                getCollapses().forEach(function(el) {
                    if (!el.id) return;
                    // Always open the section that contains the current page
                    var shouldShow = sectionHasActive(el) || ids.indexOf(el.id) !== -1;
                    setSectionOpen(el, shouldShow);
                });
            } catch (e) {
                getCollapses().forEach(function(el) {
                    if (sectionHasActive(el)) setSectionOpen(el, true);
                });
            }
        }
        function saveOpen() {
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(getOpenIds())); } catch (e) {}
        }
        sidebar.querySelectorAll('.nav-group .collapse').forEach(function(el) {
            el.addEventListener('shown.bs.collapse', saveOpen);
            el.addEventListener('hidden.bs.collapse', saveOpen);
            var btn = sidebar.querySelector('[data-bs-target="#' + el.id + '"]');
            if (btn) btn.classList.toggle('is-active-section', sectionHasActive(el));
        });
        setTimeout(applySaved, 0);
    })();
</script>
<?php if (isset($pageScripts)) echo $pageScripts; ?>
</body>
</html>
