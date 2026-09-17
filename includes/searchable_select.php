<?php
/**
 * Type-to-search dropdowns (Select2).
 *
 * Call searchable_select_assets() once in the page body, then add
 * data-search to any <select>. Selects added later (e.g. cloned line rows)
 * are picked up automatically.
 */
function searchable_select_assets(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<style>
select[data-search] + .select2-container{width:100%!important;}
.select2-container--bootstrap-5 .select2-dropdown.ss-dropdown{min-width:320px;max-width:calc(100vw - 24px);}
.select2-container--bootstrap-5 .select2-selection__rendered{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
</style>
<script>window.jQuery || document.write('<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"><\/script>');</script>
<script>(window.jQuery && jQuery.fn.select2) || document.write('<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"><\/script>');</script>
<script>
(function(){
  if (!window.jQuery || !jQuery.fn.select2) return;
  var $ = jQuery;
  function enhance(el){
    if (el.classList.contains('select2-hidden-accessible') || el.closest('template')) return;
    var first = el.querySelector('option');
    var small = el.classList.contains('form-select-sm');
    $(el).select2({
      theme: 'bootstrap-5',
      width: '100%',
      placeholder: first ? first.textContent.trim() : '',
      selectionCssClass: small ? 'select2--small' : '',
      dropdownCssClass: 'ss-dropdown' + (small ? ' select2--small' : '')
    });
  }
  function scan(root){
    if (root.matches && root.matches('select[data-search]')) enhance(root);
    if (root.querySelectorAll) root.querySelectorAll('select[data-search]').forEach(enhance);
  }
  // Put the cursor in the search box as soon as a dropdown opens.
  $(document).on('select2:open', function(){
    var f = document.querySelector('.select2-container--open .select2-search__field');
    if (f) f.focus();
  });
  // Wait for the full page so no select is enhanced before its options are parsed.
  document.addEventListener('DOMContentLoaded', function(){
    scan(document);
    new MutationObserver(function(muts){
      muts.forEach(function(m){ m.addedNodes.forEach(function(n){ if (n.nodeType === 1) scan(n); }); });
    }).observe(document.body, {childList: true, subtree: true});
  });
})();
</script>
<?php
}
