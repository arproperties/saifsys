/**
 * HR UI v2 — progressive enhancement (light gold shell).
 */
(function (window, document) {
  'use strict';

  function initLucide() {
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      window.lucide.createIcons();
    }
  }

  function initMobileSidebar() {
    var sidebar = document.getElementById('hr-sidebar');
    var overlay = document.getElementById('hr-sidebar-overlay');
    var btn = document.getElementById('hr-mobile-menu-btn');
    if (!sidebar) return;

    function close() {
      sidebar.classList.remove('mobile-open');
      if (overlay) overlay.classList.remove('show');
      document.body.style.overflow = '';
    }
    function open() {
      sidebar.classList.add('mobile-open');
      if (overlay) overlay.classList.add('show');
      document.body.style.overflow = 'hidden';
    }
    function toggle() {
      if (sidebar.classList.contains('mobile-open')) close();
      else open();
    }
    if (btn) btn.addEventListener('click', toggle);
    if (overlay) overlay.addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') close();
    });
    sidebar.querySelectorAll('.hr-nav-link').forEach(function (a) {
      a.addEventListener('click', close);
    });
  }

  function initNavSearch() {
    var input = document.getElementById('hr-nav-search');
    if (!input) return;
    input.addEventListener('input', function () {
      var q = (input.value || '').toLowerCase().trim();
      document.querySelectorAll('.hr-nav-group').forEach(function (group) {
        var any = false;
        group.querySelectorAll('.hr-nav-link').forEach(function (link) {
          var label = (link.getAttribute('data-label') || link.textContent || '').toLowerCase();
          var show = !q || label.indexOf(q) !== -1;
          link.style.display = show ? '' : 'none';
          if (show) any = true;
        });
        group.style.display = any ? '' : 'none';
      });
    });
  }

  function initUnsavedForms() {
    document.querySelectorAll('form[data-hr-unsaved]').forEach(function (form) {
      var bar = document.getElementById('hr-unsaved-bar');
      var initial = new FormData(form);
      var dirty = false;

      function serialize(fd) {
        var parts = [];
        fd.forEach(function (v, k) {
          parts.push(k + '=' + String(v));
        });
        return parts.sort().join('&');
      }
      var baseline = serialize(initial);

      function check() {
        dirty = serialize(new FormData(form)) !== baseline;
        if (bar) bar.classList.toggle('show', dirty);
      }

      form.addEventListener('input', check);
      form.addEventListener('change', check);
      form.addEventListener('submit', function () {
        dirty = false;
        if (bar) bar.classList.remove('show');
      });

      window.addEventListener('beforeunload', function (e) {
        if (!dirty) return;
        e.preventDefault();
        e.returnValue = '';
      });
    });
  }

  function cleanPhpUrls() {
    if (window.history && window.history.replaceState && window.location.pathname.endsWith('.php')) {
      var cleanUrl = window.location.pathname.replace(/\.php$/, '') + window.location.search + window.location.hash;
      window.history.replaceState({}, document.title, cleanUrl);
    }
  }

  function initFilterLinks() {
    document.querySelectorAll('a[data-filters]').forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        var filters = JSON.parse(this.getAttribute('data-filters') || '{}');
        var href = this.getAttribute('href');
        fetch('../accounts/ajax/store_filters.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            page: 'hr_' + href.replace(/^\//, '').replace(/\//g, '_'),
            filters: filters,
          }),
        })
          .then(function () {
            window.location.href = href;
          })
          .catch(function () {
            window.location.href = href;
          });
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (!document.body.classList.contains('hr-ui-v2')) return;
    initLucide();
    initMobileSidebar();
    initNavSearch();
    initUnsavedForms();
    cleanPhpUrls();
    initFilterLinks();
  });

  window.HrUiV2 = {
    initLucide: initLucide,
  };
})(window, document);
