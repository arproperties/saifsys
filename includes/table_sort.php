<?php
/**
 * Click-to-sort table headers.
 *
 * Print the assets once per page, anywhere before the table:
 *
 *     <?= table_sort_assets() ?>
 *
 * then mark the table and its headers:
 *
 *     <table data-sortable>
 *       <thead><tr>
 *         <th>Guest</th>                    header text sorts the column
 *         <th data-sort="number">Total</th> forces a type instead of guessing
 *         <th data-sort="none">Actions</th> not sortable
 *       </tr></thead>
 *
 * A cell whose text does not sort the way it reads carries the key itself:
 * <td data-sort-value="2026-09-10">10 Sep 2026</td>. The sorting is done in the
 * browser on the rows already rendered, so it leaves the queries alone.
 */

require_once __DIR__ . '/url_helper.php';
require_once __DIR__ . '/nav_search.php'; // nav_search_web_root()

if (!function_exists('table_sort_assets')) {
    function table_sort_assets(): string
    {
        static $printed = false;
        if ($printed) {
            return '';
        }
        $printed = true;

        // Inline, so headers never show an unstyled pair of arrows on first paint.
        $css = '<style>'
            . '.tsort-th{cursor:pointer;user-select:none;white-space:nowrap}'
            . '.tsort-th:focus-visible{outline:2px solid currentColor;outline-offset:-2px}'
            . '.tsort-ind{display:inline-block;margin-left:.35em;vertical-align:-1px;line-height:0;color:currentColor}'
            . '.tsort-ind svg{fill:currentColor}'
            . '.tsort-ind path{opacity:.28}'
            . '.tsort-th:hover .tsort-ind path{opacity:.5}'
            . '.tsort-th[aria-sort] .tsort-ind path{opacity:.2}'
            . '.tsort-th[aria-sort="ascending"] .tsort-ind .tsort-up{opacity:1}'
            . '.tsort-th[aria-sort="descending"] .tsort-ind .tsort-dn{opacity:1}'
            . '</style>';

        $file = dirname(__DIR__) . '/assets/js/table-sort.js';
        $src = nav_search_web_root() . '/assets/js/table-sort.js?v=' . (@filemtime($file) ?: 1);

        return $css . '<script src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" defer></script>';
    }
}
