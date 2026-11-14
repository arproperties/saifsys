    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
  // Searchable dropdowns (the employee list), re-firing the change as a real DOM event.
  (function () {
    if (typeof $ === 'undefined' || !$.fn || !$.fn.select2) { return; }
    $('select.lt-search').each(function () {
      var $s = $(this);
      $s.select2({ theme: 'bootstrap-5', width: '100%', placeholder: $s.data('placeholder') || undefined });
      $s.on('select2:select select2:unselect select2:clear', function () {
        this.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });
  })();
  // Sidebar collapse — same behaviour as the other modules.
  (function () {
    var sb = document.getElementById('sb');
    var t = document.getElementById('sbToggle');
    if (!sb || !t) { return; }
    t.addEventListener('click', function () {
      sb.classList.toggle('collapsed');
      t.querySelector('i').classList.toggle('bi-chevron-right');
      t.querySelector('i').classList.toggle('bi-chevron-left');
    });
  })();
</script>
<?php if (!empty($pageScripts)) { echo $pageScripts; } ?>
</body>
</html>
