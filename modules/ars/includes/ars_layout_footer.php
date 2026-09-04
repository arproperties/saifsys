        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
    var sb = document.getElementById('sb');
    var backdrop = document.getElementById('sbBackdrop');
    var hamburger = document.getElementById('sbHamburger');
    var sbToggle = document.getElementById('sbToggle');
    var isMobile = function() { return window.innerWidth < 992; };

    function openMobileSidebar() {
        if (sb) sb.classList.add('mobile-open');
        if (backdrop) backdrop.classList.add('active');
    }
    function closeMobileSidebar() {
        if (sb) sb.classList.remove('mobile-open');
        if (backdrop) backdrop.classList.remove('active');
    }

    if (hamburger) {
        hamburger.addEventListener('click', function() {
            if (sb && sb.classList.contains('mobile-open')) closeMobileSidebar();
            else openMobileSidebar();
        });
    }
    if (backdrop) {
        backdrop.addEventListener('click', closeMobileSidebar);
    }

    if (sb) {
        sb.querySelectorAll('.ars-nav-link').forEach(function(link) {
            link.addEventListener('click', function() {
                if (isMobile()) closeMobileSidebar();
            });
        });
    }

    if (sbToggle) {
        sbToggle.addEventListener('click', function() {
            if (isMobile()) return;
            var icon = this.querySelector('i');
            sb.classList.toggle('collapsed');
            if (icon) { icon.classList.toggle('bi-chevron-left'); icon.classList.toggle('bi-chevron-right'); }
            try { localStorage.setItem('ars_sidebar_collapsed', sb.classList.contains('collapsed') ? '1' : '0'); } catch(e){}
        });
    }

    try {
        if (!isMobile() && localStorage.getItem('ars_sidebar_collapsed') === '1') {
            if (sb) {
                sb.classList.add('collapsed');
                if (sbToggle) {
                    var i = sbToggle.querySelector('i');
                    if (i) { i.classList.remove('bi-chevron-left'); i.classList.add('bi-chevron-right'); }
                }
            }
        }
    } catch(e){}
})();
</script>
<?php if (isset($pageScripts)) echo $pageScripts; ?>
</body>
</html>
