<?php
/**
 * Closes the shell opened by fin_layout_header.php and loads Bootstrap, jQuery
 * and Select2. The page adds its own scripts after this, then closes body and html.
 *
 * Every dropdown on these pages is searchable. A page that adds a <select> later
 * (a new voucher line) calls finSearchable(container); one that changes a
 * dropdown's value from script calls finSetSelect(select, value).
 */
?>
</div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function () {
  const sb = document.getElementById('sb');
  const sbToggle = document.getElementById('sbToggle');
  sbToggle.addEventListener('click', () => {
    sb.classList.toggle('collapsed');
    sbToggle.querySelector('i').classList.toggle('bi-chevron-right');
    sbToggle.querySelector('i').classList.toggle('bi-chevron-left');
  });
})();

// Turns every dropdown inside `root` (default: the page) into a searchable one.
window.finSearchable = function (root) {
  if (typeof jQuery === 'undefined' || !jQuery.fn.select2) return; // plain dropdowns if the library did not load
  jQuery(root || document).find('select').addBack('select').each(function () {
    const $s = jQuery(this);
    if ($s.hasClass('select2-hidden-accessible')) return;
    const size = $s.hasClass('form-select-sm') ? 'select2--small' : '';
    const $modal = $s.closest('.modal');
    $s.select2({
      theme: 'bootstrap-5',
      width: '100%',
      minimumResultsForSearch: 0,
      selectionCssClass: size,
      dropdownCssClass: size,
      // Inside a pop-up the list must be attached to it, or the search box cannot take focus.
      dropdownParent: $modal.length ? $modal : jQuery(document.body)
    });
    // Select2 announces a pick through jQuery only, which never reaches a plain
    // addEventListener('change'). Re-fire it as a real DOM event.
    $s.on('select2:select select2:unselect select2:clear', function () {
      this.dispatchEvent(new Event('change', { bubbles: true }));
    });
    // Put the cursor in the search box as soon as the list opens.
    $s.on('select2:open', function () {
      const box = document.querySelector('.select2-container--open .select2-search__field');
      if (box) box.focus();
    });
  });
};

// Sets a dropdown's value from script and keeps the searchable box in step.
window.finSetSelect = function (select, value) {
  select.value = value;
  if (typeof jQuery !== 'undefined' && jQuery.fn.select2) jQuery(select).trigger('change.select2');
};

window.finSearchable();

// The company switcher submits as soon as a company is picked.
(function () {
  const sw = document.getElementById('finCompanySwitch');
  if (sw) sw.addEventListener('change', () => sw.form.submit());
})();
</script>
