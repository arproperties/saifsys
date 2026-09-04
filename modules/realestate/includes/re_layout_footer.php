        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Sidebar collapse functionality
    document.getElementById('sbToggle')?.addEventListener('click', function() {
        const sidebar = document.getElementById('sb');
        const icon = this.querySelector('i');
        sidebar.classList.toggle('collapsed');
        icon.classList.toggle('bi-chevron-left');
        icon.classList.toggle('bi-chevron-right');
    });

    // Persist nav section open/closed state
    (function() {
        var STORAGE_KEY = 're_nav_sections';
        var sidebar = document.getElementById('sb');
        if (!sidebar) return;
        function getCollapses() { return sidebar.querySelectorAll('.nav-group .collapse'); }
        function getOpenIds() {
            var ids = [];
            getCollapses().forEach(function(el) {
                if (el.id && el.classList.contains('show')) ids.push(el.id);
            });
            return ids;
        }
        function applySaved() {
            try {
                var saved = localStorage.getItem(STORAGE_KEY);
                if (!saved) return;
                var ids = JSON.parse(saved);
                getCollapses().forEach(function(el) {
                    if (!el.id) return;
                    var btn = sidebar.querySelector('[data-bs-target="#' + el.id + '"]');
                    var shouldShow = ids.indexOf(el.id) !== -1;
                    if (btn) btn.setAttribute('aria-expanded', shouldShow ? 'true' : 'false');
                    el.classList.toggle('show', shouldShow);
                });
            } catch (e) {}
        }
        function saveOpen() {
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(getOpenIds())); } catch (e) {}
        }
        document.querySelectorAll('.nav-group .collapse').forEach(function(el) {
            el.addEventListener('shown.bs.collapse', saveOpen);
            el.addEventListener('hidden.bs.collapse', saveOpen);
        });
        // Restore saved state after a tick so server-rendered state is overridden for returning visitors
        setTimeout(applySaved, 0);
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

