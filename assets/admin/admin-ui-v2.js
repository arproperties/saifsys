/**
 * Administration UI v2 — progressive enhancement (light gold shell).
 */
(function (window, document) {
  'use strict';

  function initLucide() {
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      window.lucide.createIcons();
    }
  }

  function chartDefaults() {
    if (!window.Chart) return;
    Chart.defaults.color = '#6b7280';
    Chart.defaults.borderColor = 'rgba(184,134,11,0.15)';
    Chart.defaults.font.family = "'DM Sans', system-ui, sans-serif";
  }

  function initMobileSidebar() {
    var sidebar = document.getElementById('admin-sidebar');
    var overlay = document.getElementById('admin-sidebar-overlay');
    var btn = document.getElementById('admin-mobile-menu-btn');
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
      if (e.key === 'Escape') {
        close();
        closeDrawer();
      }
    });
    sidebar.querySelectorAll('.admin-nav-link').forEach(function (a) {
      a.addEventListener('click', close);
    });
  }

  function initNavSearch() {
    var input = document.getElementById('admin-nav-search');
    if (!input) return;
    input.addEventListener('input', function () {
      var q = (input.value || '').toLowerCase().trim();
      document.querySelectorAll('.admin-nav-group').forEach(function (group) {
        var any = false;
        group.querySelectorAll('.admin-nav-link').forEach(function (link) {
          var label = (link.getAttribute('data-label') || link.textContent || '').toLowerCase();
          var show = !q || label.indexOf(q) !== -1;
          link.style.display = show ? '' : 'none';
          if (show) any = true;
        });
        group.style.display = any ? '' : 'none';
      });
    });
  }

  var drawerEl = null;
  var backdropEl = null;

  function openDrawer() {
    if (!drawerEl) drawerEl = document.getElementById('admin-drawer');
    if (!backdropEl) backdropEl = document.getElementById('admin-drawer-backdrop');
    if (drawerEl) drawerEl.classList.add('open');
    if (backdropEl) backdropEl.classList.add('show');
    document.body.style.overflow = 'hidden';
    var closeBtn = drawerEl && drawerEl.querySelector('[data-admin-drawer-close]');
    if (closeBtn) closeBtn.focus();
  }

  function closeDrawer() {
    if (!drawerEl) drawerEl = document.getElementById('admin-drawer');
    if (!backdropEl) backdropEl = document.getElementById('admin-drawer-backdrop');
    if (drawerEl) drawerEl.classList.remove('open');
    if (backdropEl) backdropEl.classList.remove('show');
    document.body.style.overflow = '';
  }

  function initDrawer() {
    backdropEl = document.getElementById('admin-drawer-backdrop');
    drawerEl = document.getElementById('admin-drawer');
    if (backdropEl) backdropEl.addEventListener('click', closeDrawer);
    document.querySelectorAll('[data-admin-drawer-close]').forEach(function (btn) {
      btn.addEventListener('click', closeDrawer);
    });
  }

  function initUnsavedForms() {
    document.querySelectorAll('form[data-admin-unsaved]').forEach(function (form) {
      var bar = document.getElementById('admin-unsaved-bar');
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

  function showToast(message, tone) {
    var host = document.getElementById('admin-toast-host');
    if (!host) {
      host = document.createElement('div');
      host.id = 'admin-toast-host';
      host.className = 'admin-toast-host';
      document.body.appendChild(host);
    }
    var el = document.createElement('div');
    el.className = 'admin-toast';
    if (tone === 'danger') el.style.borderLeftColor = 'var(--admin-danger)';
    el.textContent = message;
    host.appendChild(el);
    setTimeout(function () {
      el.remove();
    }, 3500);
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (!document.body.classList.contains('admin-ui-v2')) return;
    initLucide();
    chartDefaults();
    initMobileSidebar();
    initNavSearch();
    initDrawer();
    initUnsavedForms();
  });

  window.AdminUiV2 = {
    initLucide: initLucide,
    openDrawer: openDrawer,
    closeDrawer: closeDrawer,
    showToast: showToast,
    chartTheme: {
      gold: '#b8860b',
      goldSoft: '#d4af37',
      success: '#059669',
      danger: '#dc2626',
      info: '#0284c7',
      muted: '#6b7280',
      grid: 'rgba(184,134,11,0.12)',
    },
  };
})(window, document);
