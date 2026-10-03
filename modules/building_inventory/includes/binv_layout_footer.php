    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
  // Every dropdown in this module becomes searchable. Short fixed lists (Condition)
  // keep the plain look — a search box over three options is noise — unless the
  // dropdown carries data-search.
  (function () {
    if (typeof $ === 'undefined' || !$.fn || !$.fn.select2) { return; }
    $('select.form-select').each(function () {
      var $s = $(this);
      if ($s.is('[data-no-search]')) { return; }
      var size = $s.hasClass('form-select-lg') ? 'select2--large'
               : ($s.hasClass('form-select-sm') ? 'select2--small' : '');
      $s.select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: $s.data('placeholder') || undefined,
        containerCssClass: size,
        selectionCssClass: size,
        dropdownCssClass: size,
        minimumResultsForSearch: ($s.is('[data-search]') || $s.find('option').length > 4) ? 0 : Infinity
      });

      // Select2 announces a pick through jQuery only, which never reaches a
      // plain addEventListener('change'). Re-fire it as a real DOM event.
      $s.on('select2:select select2:unselect select2:clear', function () {
        this.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });
  })();
</script>
<script>
  // Sidebar collapse — same behaviour as the operations module.
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
