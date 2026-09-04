/**
 * ARS Shell chrome — header menus, mobile sidebar, collapse.
 * Vanilla JS. Menus use position:fixed so header backdrop-filter cannot clip them.
 */
(function () {
  'use strict';

  var root = null;
  var openPanel = null;

  function $(sel, ctx) {
    return (ctx || document).querySelector(sel);
  }

  function $all(sel, ctx) {
    return Array.prototype.slice.call((ctx || document).querySelectorAll(sel));
  }

  function closeMenus() {
    $all('[data-ars-menu-panel]').forEach(function (panel) {
      panel.hidden = true;
      panel.classList.remove('is-open');
      panel.style.top = '';
      panel.style.left = '';
      panel.style.right = '';
      panel.style.width = '';
    });
    $all('[data-ars-menu-trigger]').forEach(function (btn) {
      btn.setAttribute('aria-expanded', 'false');
    });
    openPanel = null;
  }

  function positionPanel(trigger, panel) {
    var rect = trigger.getBoundingClientRect();
    var gap = 8;
    var width = Math.min(320, window.innerWidth - 16);
    panel.style.position = 'fixed';
    panel.style.zIndex = '2000';
    panel.style.width = width + 'px';
    panel.style.right = 'auto';

    var left = rect.right - width;
    if (left < 8) left = 8;
    if (left + width > window.innerWidth - 8) {
      left = window.innerWidth - width - 8;
    }
    panel.style.left = left + 'px';
    panel.style.top = Math.min(rect.bottom + gap, window.innerHeight - 80) + 'px';
  }

  function openMenu(wrap) {
    var btn = $('[data-ars-menu-trigger]', wrap);
    var panel = wrap.__arsPanel || $('[data-ars-menu-panel]', wrap);
    if (!panel || !btn) return;

    var alreadyOpen = !panel.hidden && openPanel === panel;
    closeMenus();
    if (alreadyOpen) return;

    // Move to body so no ancestor overflow/stacking clips the menu
    if (panel.parentElement !== document.body) {
      document.body.appendChild(panel);
    }
    wrap.__arsPanel = panel;
    panel.classList.add('ars-shell-popover');

    panel.hidden = false;
    panel.classList.add('is-open');
    btn.setAttribute('aria-expanded', 'true');
    positionPanel(btn, panel);
    openPanel = panel;
  }

  function setCollapsed(on) {
    var sidebar = $('#ars-shell-sidebar', root);
    var frame = $('.ars-shell-frame', root);
    if (!sidebar) return;
    sidebar.classList.toggle('is-collapsed', !!on);
    if (frame) frame.classList.toggle('is-collapsed', !!on);
    try {
      localStorage.setItem('ars_shell_collapsed', on ? '1' : '0');
    } catch (e) {}
  }

  function setMobileOpen(on) {
    var sidebar = $('#ars-shell-sidebar', root);
    var backdrop = $('[data-ars-mobile-backdrop]', root);
    if (sidebar) sidebar.classList.toggle('is-mobile-open', !!on);
    if (backdrop) {
      backdrop.hidden = !on;
      backdrop.classList.toggle('is-open', !!on);
    }
    document.body.classList.toggle('ars-shell-mobile-open', !!on);
  }

  function onDocClick(e) {
    var trigger = e.target.closest && e.target.closest('[data-ars-menu-trigger]');
    if (trigger && root.contains(trigger)) {
      e.preventDefault();
      e.stopPropagation();
      var wrap = trigger.closest('[data-ars-menu]');
      if (wrap) openMenu(wrap);
      return;
    }

    if (e.target.closest && e.target.closest('[data-ars-collapse-btn]')) {
      e.preventDefault();
      var sidebar = $('#ars-shell-sidebar', root);
      setCollapsed(!(sidebar && sidebar.classList.contains('is-collapsed')));
      return;
    }

    if (e.target.closest && e.target.closest('[data-ars-mobile-open]')) {
      e.preventDefault();
      setMobileOpen(true);
      return;
    }

    if (e.target.closest && (e.target.closest('[data-ars-mobile-close]') || e.target.closest('[data-ars-mobile-backdrop]'))) {
      e.preventDefault();
      setMobileOpen(false);
      return;
    }

    if (openPanel && !(e.target.closest && (e.target.closest('[data-ars-menu]') || e.target.closest('[data-ars-menu-panel]')))) {
      closeMenus();
    }
  }

  function init() {
    if (window.__arsShellReady) return;
    root = document.getElementById('ars-app');
    if (!root || !root.classList.contains('ars-shell')) return;
    window.__arsShellReady = true;

    try {
      if (localStorage.getItem('ars_shell_collapsed') === '1' && window.matchMedia('(min-width: 1024px)').matches) {
        setCollapsed(true);
      }
    } catch (e) {}

    // Capture phase so we win over other handlers
    document.addEventListener('click', onDocClick, true);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeMenus();
        setMobileOpen(false);
      }
      if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
        var input = $('#ars-shell-search-input', root);
        if (input) {
          e.preventDefault();
          input.focus();
          input.select();
        }
      }
    });

    window.addEventListener('resize', function () {
      if (!openPanel) return;
      var btn =
        $('[data-ars-menu="user"] [data-ars-menu-trigger]', root) &&
        openPanel.id === 'ars-user-panel'
          ? $('#ars-user-btn', root)
          : openPanel.id === 'ars-notify-panel'
            ? $('#ars-notify-btn', root)
            : null;
      if (!btn) {
        // fallback: any expanded trigger
        btn = $('[data-ars-menu-trigger][aria-expanded="true"]', root);
      }
      if (btn) positionPanel(btn, openPanel);
    });

    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      try {
        window.lucide.createIcons();
      } catch (e) {}
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.addEventListener('load', function () {
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      try {
        window.lucide.createIcons();
      } catch (e) {}
    }
  });
})();
