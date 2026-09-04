/**
 * Construction UI v2 — progressive enhancement helpers (module-scoped).
 * Depends on Alpine.js (optional) and Lucide (optional). Safe if either is missing.
 */
(function (window, document) {
  'use strict';

  function cssVar(name, fallback) {
    try {
      var v = getComputedStyle(document.body).getPropertyValue(name).trim();
      return v || fallback;
    } catch (e) {
      return fallback;
    }
  }

  function buildChartTheme() {
    var mode = document.body.getAttribute('data-co-theme-mode') || 'light';
    var muted = cssVar('--construction-muted-text', '#64748b');
    var card = cssVar('--construction-card-bg', mode === 'light' ? '#ffffff' : '#151e2e');
    return {
      gold: cssVar('--erp-chart-1', cssVar('--construction-chart-1', '#2563eb')),
      teal: cssVar('--erp-chart-2', cssVar('--construction-chart-2', '#10b981')),
      danger: cssVar('--erp-chart-4', cssVar('--construction-chart-4', '#dc2626')),
      info: cssVar('--erp-chart-3', cssVar('--construction-chart-3', '#f59e0b')),
      muted: muted,
      grid: mode === 'light' ? 'rgba(15,23,42,0.08)' : 'rgba(212,175,55,0.08)',
      chart1: cssVar('--erp-chart-1', '#2563eb'),
      chart2: cssVar('--erp-chart-2', '#10b981'),
      chart3: cssVar('--erp-chart-3', '#f59e0b'),
      chart4: cssVar('--erp-chart-4', '#8b5cf6'),
      chart5: cssVar('--erp-chart-5', '#ec4899'),
      border: card,
      text: cssVar('--construction-body-text', muted),
    };
  }

  function initLucide() {
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      window.lucide.createIcons();
    }
  }

  function syncMethodTiles(root) {
    root.querySelectorAll('.co-method').forEach(function (el) {
      var input = el.querySelector('input[type="radio"]');
      if (!input) return;
      el.classList.toggle('is-selected', input.checked);
      input.addEventListener('change', function () {
        var name = input.name;
        root.querySelectorAll('.co-method input[name="' + name + '"]').forEach(function (r) {
          var tile = r.closest('.co-method');
          if (tile) tile.classList.toggle('is-selected', r.checked);
        });
      });
    });
  }

  function chartDefaults() {
    if (!window.Chart) return;
    var theme = buildChartTheme();
    Chart.defaults.color = theme.text || theme.muted;
    Chart.defaults.borderColor = theme.grid;
    Chart.defaults.font.family = "'DM Sans', system-ui, sans-serif";
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (!document.body.classList.contains('co-ui-v2')) return;
    initLucide();
    chartDefaults();
    syncMethodTiles(document);
    document.body.addEventListener('htmx:afterSwap', function () {
      initLucide();
    });
  });

  window.CoUiV2 = {
    initLucide: initLucide,
    applyChartDefaults: chartDefaults,
    get chartTheme() {
      return buildChartTheme();
    },
  };
})(window, document);
