<?php
/**
 * Search box for a module's left-side menu.
 *
 * Print it inside the sidebar, directly above the first menu link:
 *
 *     <?= nav_search_box() ?>
 *
 * Every link after the box becomes searchable; whatever sits before it (logo,
 * company picker) is left alone. The filtering lives in assets/js/nav-search.js.
 *
 * The box takes its text colour from the sidebar and draws its fill and border
 * as translucent grey, so it reads on the dark gradient sidebars and on the
 * light ARS shell alike without a colour of its own.
 */

require_once __DIR__ . '/url_helper.php';

if (!function_exists('nav_search_web_root')) {
    /**
     * Web path of the application root, worked out from where the running script
     * sits on disk. get_application_web_root() only knows about /modules, /api
     * and /hr, so from /operation/*.php it would look for the script under
     * /operation/assets.
     */
    function nav_search_web_root(): string
    {
        $appDir = realpath(dirname(__DIR__));
        $script = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $name = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($appDir !== false && $script !== false && strpos($script, $appDir) === 0) {
            $rel = str_replace('\\', '/', substr($script, strlen($appDir)));
            if ($rel !== '' && substr($name, -strlen($rel)) === $rel) {
                return rtrim(substr($name, 0, -strlen($rel)), '/');
            }
        }
        return get_application_web_root();
    }
}

if (!function_exists('nav_search_box')) {
    function nav_search_box(string $placeholder = 'Search menu'): string
    {
        static $assetsPrinted = false;

        $html = '';
        if (!$assetsPrinted) {
            // Inline rather than in the script, so the box never flashes unstyled.
            $html .= '<style>'
                . '.ns-box{margin:.6rem .5rem .5rem}'
                . '.ns-field{position:relative}'
                . '.ns-ico{position:absolute;left:.65rem;top:50%;transform:translateY(-50%);width:15px;height:15px;opacity:.7;pointer-events:none}'
                . '.ns-input{display:block;width:100%;box-sizing:border-box;margin:0;padding:.45rem 1.9rem .45rem 2.1rem;'
                . 'font:inherit;font-size:.875rem;line-height:1.3;color:inherit;background:rgba(127,127,127,.14);'
                . 'border:1px solid rgba(127,127,127,.4);border-radius:10px;outline:0;-webkit-appearance:none;appearance:none}'
                . '.ns-input::placeholder{color:inherit;opacity:.7}'
                . '.ns-input:focus{border-color:currentColor;background:rgba(127,127,127,.22)}'
                . '.ns-kbd{position:absolute;right:.55rem;top:50%;transform:translateY(-50%);padding:.1rem .35rem;'
                . 'font:600 .7rem/1.2 ui-monospace,SFMono-Regular,Menlo,monospace;color:inherit;background:none;box-shadow:none;'
                . 'border:1px solid rgba(127,127,127,.45);border-radius:4px;opacity:.7;pointer-events:none}'
                . '.ns-input:focus~.ns-kbd,.ns-input:not(:placeholder-shown)~.ns-kbd{display:none}'
                . '@media (hover:none){.ns-kbd{display:none}}'
                . '.ns-empty{margin:.5rem .35rem 0;font-size:.8rem;opacity:.75}'
                . '.ns-empty:empty{display:none}'
                . '.ns-hide,.ns-narrow{display:none!important}'
                . '</style>';

            $file = dirname(__DIR__) . '/assets/js/nav-search.js';
            $src = nav_search_web_root() . '/assets/js/nav-search.js?v=' . (@filemtime($file) ?: 1);
            $html .= '<script src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" defer></script>';
            $assetsPrinted = true;
        }

        $html .= '<div class="ns-box" data-nav-search>'
            . '<div class="ns-field">'
            . '<svg class="ns-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">'
            . '<circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>'
            . '<input type="search" class="ns-input" placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '"'
            . ' aria-label="Search the menu" autocomplete="off" spellcheck="false">'
            . '<kbd class="ns-kbd" aria-hidden="true">/</kbd>'
            . '</div>'
            . '<div class="ns-empty" role="status" aria-live="polite"></div>'
            . '</div>';

        return $html;
    }
}
