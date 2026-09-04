/**
 * ARS UI helpers (Wave 0) — presentation only.
 * No business rules, financial calculations, posting, or journal logic.
 */
(function (global) {
  'use strict';

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function initIcons(root) {
    if (global.lucide && typeof global.lucide.createIcons === 'function') {
      global.lucide.createIcons({
        attrs: { 'stroke-width': 1.75 },
        nameAttr: 'data-lucide',
        root: root || document.getElementById('ars-app') || document.body,
      });
    }
  }

  function toast(message, type) {
    var region = document.getElementById('ars-toast-region');
    if (!region) return;
    var el = document.createElement('div');
    el.setAttribute('role', 'status');
    el.className =
      'ars-toast mb-2 rounded-ars-md border border-ars-border bg-ars-surface px-4 py-3 text-ars-sm text-ars-text shadow-ars-sm';
    if (type === 'error') {
      el.className += ' border-ars-danger text-ars-danger';
    } else if (type === 'success') {
      el.className += ' border-ars-success text-ars-success';
    }
    el.textContent = String(message || '');
    region.appendChild(el);
    setTimeout(function () {
      if (el.parentNode) el.parentNode.removeChild(el);
    }, 4000);
  }

  /** Lightweight focus trap for [data-ars-focus-trap] overlays (no Alpine Focus plugin). */
  function bindFocusTraps(root) {
    var scope = root || document;
    scope.querySelectorAll('[data-ars-focus-trap]').forEach(function (panel) {
      if (panel.getAttribute('data-ars-trap-bound') === '1') return;
      panel.setAttribute('data-ars-trap-bound', '1');
      panel.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab') return;
        var focusables = panel.querySelectorAll(
          'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
        );
        if (!focusables.length) return;
        var first = focusables[0];
        var last = focusables[focusables.length - 1];
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      });
    });
  }

  global.ArsUI = {
    initIcons: initIcons,
    toast: toast,
    bindFocusTraps: bindFocusTraps,
    version: '0.1.0-wave0',
  };

  ready(function () {
    initIcons();
    bindFocusTraps(document.getElementById('ars-app') || document);
  });
})(window);
