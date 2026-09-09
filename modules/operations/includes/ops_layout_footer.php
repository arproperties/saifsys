    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
  // Every dropdown in this module becomes searchable. Short fixed lists (Type,
  // Priority, a weekday) keep the plain look — a search box over four options
  // is noise — but anything longer gets one.
  //
  // data-search on a dropdown overrides that: a list that is short today and
  // long next month (the stock items) should not gain and lose its search box
  // as rows are added, so it always has one.
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
        containerCssClass: size,
        selectionCssClass: size,
        dropdownCssClass: size,
        minimumResultsForSearch: ($s.is('[data-search]') || $s.find('option').length > 4) ? 0 : Infinity
      });

      // Select2 announces a pick through jQuery only, which never reaches a
      // plain addEventListener('change') — so any page script watching a
      // dropdown would silently stop working. Re-fire it as a real DOM event.
      $s.on('select2:select select2:unselect select2:clear', function () {
        this.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });

    // Bound natively, so each fires exactly once off the event forwarded above.
    var companySelect = document.getElementById('opsCompanySelect');
    if (companySelect) {
      companySelect.addEventListener('change', function () {
        document.getElementById('opsCompanyId').value = this.value;
        document.getElementById('opsCompanyForm').submit();
      });
    }
    document.querySelectorAll('select[data-autosubmit]').forEach(function (el) {
      el.addEventListener('change', function () { this.form.submit(); });
    });
  })();
</script>
<script>
  // Sidebar collapse — same behaviour as the cleaning module.
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
</body>
</html>
