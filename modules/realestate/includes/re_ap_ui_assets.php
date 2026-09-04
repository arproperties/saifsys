<?php
/**
 * Scoped UI assets for Real Estate Accounts Payable pages only.
 * Opt-in: set $reApUiEnhanced = true before re_layout_header.php.
 *
 * Allowed: Alpine.js, Lucide Icons, ApexCharts, SweetAlert2 (+ Bootstrap 5 / RE layout).
 * Not used: Flatpickr, Tom Select, Tailwind.
 */

if (!function_exists('re_ap_ui_assets_head')) {
    function re_ap_ui_assets_head(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        ?>
<style>
[x-cloak] { display: none !important; }

/* Lucide: hard-cap icon size so SVGs cannot fill the page */
i[data-lucide], svg.lucide {
  width: 1.15rem !important;
  height: 1.15rem !important;
  max-width: 1.25rem !important;
  max-height: 1.25rem !important;
  stroke-width: 2;
  display: inline-block;
  vertical-align: -0.2em;
  flex-shrink: 0;
}
.page-header-label i[data-lucide],
.page-header-label svg.lucide {
  width: 1.25rem !important;
  height: 1.25rem !important;
}

.re-ap-card-metric .metric-label { font-size: .8rem; color: #6c757d; }
.re-ap-card-metric .metric-value { font-size: 1.35rem; font-weight: 700; font-variant-numeric: tabular-nums; }
.re-ap-alloc-summary { position: sticky; top: .75rem; z-index: 2; }
.re-ap-alloc-summary.warn { border-color: #ffc107; box-shadow: 0 0 0 .15rem rgba(255,193,7,.15); }
.re-ap-alloc-summary.ok { border-color: #198754; box-shadow: 0 0 0 .15rem rgba(25,135,84,.12); }
.re-ap-table td, .re-ap-table th { vertical-align: middle; }
.re-ap-bar-row { display: flex; align-items: center; gap: .75rem; margin-bottom: .65rem; }
.re-ap-bar-label { width: 8.5rem; flex-shrink: 0; font-size: .85rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.re-ap-bar-track { flex: 1; height: .75rem; background: #e9ecef; border-radius: .375rem; overflow: hidden; }
.re-ap-bar-fill { height: 100%; background: #0d6efd; border-radius: .375rem; }
.re-ap-bar-fill.green { background: #198754; }
.re-ap-bar-amt { width: 6.5rem; text-align: right; font-variant-numeric: tabular-nums; font-size: .85rem; flex-shrink: 0; }
.re-ap-toolbar .btn { white-space: nowrap; }
.re-ap-summary-line { font-variant-numeric: tabular-nums; }
.re-ap-chart-wrap { width: 100%; max-width: 100%; height: 280px; overflow: hidden; position: relative; }
.re-ap-chart-wrap .apexcharts-canvas,
.re-ap-chart-wrap svg { max-width: 100% !important; }
@media print {
  .sidebar, .re-sidebar, #sb, .topbar, .navbar, .re-ap-print-hide, .btn, .slink, .nav-group { display: none !important; }
  .main-content, .content-area, .page-content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
  .card { break-inside: avoid; box-shadow: none !important; border: 1px solid #ddd !important; }
}
</style>
        <?php
    }
}

if (!function_exists('re_ap_ui_assets_footer')) {
    function re_ap_ui_assets_footer(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/lucide@0.469.0/dist/umd/lucide.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.54.1/dist/apexcharts.min.js"></script>
<script>
/* Register Alpine components before Alpine boots (must be before alpinejs script). */
document.addEventListener('alpine:init', function () {
  if (window.Alpine && typeof Alpine.data === 'function' && typeof window.vendorPaymentAlloc === 'function') {
    Alpine.data('vendorPaymentAlloc', window.vendorPaymentAlloc);
  }
});
</script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.lucide && typeof lucide.createIcons === 'function') {
    lucide.createIcons({
      attrs: {
        width: '1.15rem',
        height: '1.15rem',
        'stroke-width': '2'
      }
    });
  }
});
</script>
        <?php
    }
}
