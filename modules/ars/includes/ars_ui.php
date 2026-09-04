<?php
/**
 * ARS UI foundation (Phase 3B Wave 0)
 *
 * Presentational helpers only. No DB queries, financial calculations,
 * posting, journal logic, or Stay portal dependencies.
 *
 * Usage:
 *   require_once __DIR__ . '/ars_ui.php';
 *   ars_ui_assets(); // once in <head> or before #ars-app
 *   echo ars_ui_app_open();
 *   // components...
 *   echo ars_ui_app_close();
 */

if (!function_exists('ars_ui_h')) {
    function ars_ui_h($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ars_ui_asset_base')) {
    function ars_ui_asset_base(): string
    {
        static $base = null;
        if ($base !== null) {
            return $base;
        }
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        // tools/*.php → ../assets ; pages in modules/ars → assets
        if (strpos($script, '/modules/ars/tools/') !== false) {
            $base = '../assets';
        } else {
            $base = 'assets';
        }
        return $base;
    }
}

if (!function_exists('ars_ui_asset_version')) {
    function ars_ui_asset_version(string $relativePath): string
    {
        $full = dirname(__DIR__) . '/assets/' . ltrim($relativePath, '/');
        return is_file($full) ? (string)filemtime($full) : '0';
    }
}

/**
 * Emit CSS/JS for #ars-app islands. Do not call from Stay portal pages.
 */
if (!function_exists('ars_ui_assets')) {
    function ars_ui_assets(array $opts = []): void
    {
        static $emitted = false;
        if ($emitted && empty($opts['force'])) {
            return;
        }
        $emitted = true;
        $base = ars_ui_asset_base();
        $css = !empty($opts['dev']) ? 'dist/ars-app.css' : 'dist/ars-app.min.css';
        if (!is_file(dirname(__DIR__) . '/assets/' . $css)) {
            $css = 'dist/ars-app.css';
        }
        $cssV = ars_ui_asset_version($css);
        $alpineV = ars_ui_asset_version('vendor/alpine.min.js');
        $lucideV = ars_ui_asset_version('vendor/lucide.min.js');
        $uiV = ars_ui_asset_version('js/ars-ui.js');

        echo '<link rel="stylesheet" href="' . ars_ui_h($base . '/' . $css) . '?v=' . ars_ui_h($cssV) . '">' . "\n";
        // Alpine deferred; Lucide before ars-ui
        echo '<script defer src="' . ars_ui_h($base . '/vendor/alpine.min.js') . '?v=' . ars_ui_h($alpineV) . '"></script>' . "\n";
        echo '<script defer src="' . ars_ui_h($base . '/vendor/lucide.min.js') . '?v=' . ars_ui_h($lucideV) . '"></script>' . "\n";
        echo '<script defer src="' . ars_ui_h($base . '/js/ars-ui.js') . '?v=' . ars_ui_h($uiV) . '"></script>' . "\n";
    }
}

if (!function_exists('ars_ui_app_open')) {
    function ars_ui_app_open(array $attrs = []): string
    {
        $class = trim(' ' . ($attrs['class'] ?? ''));
        $extra = '';
        if (!empty($attrs['id']) && $attrs['id'] !== 'ars-app') {
            // Always keep #ars-app as primary isolation root; allow nested regions via class.
        }
        return '<div id="ars-app" class="ars-app-root font-ars text-ars-base text-ars-text bg-ars-bg' . ars_ui_h($class) . '"'
            . ' data-ars-ui="wave0">' . "\n";
    }
}

if (!function_exists('ars_ui_app_close')) {
    function ars_ui_app_close(): string
    {
        return "</div><!-- /#ars-app -->\n";
    }
}

if (!function_exists('ars_ui_icon')) {
    function ars_ui_icon(string $name, array $opts = []): string
    {
        $class = ars_ui_h($opts['class'] ?? 'h-4 w-4');
        $label = $opts['label'] ?? null;
        $attrs = ' data-lucide="' . ars_ui_h($name) . '" class="' . $class . '" aria-hidden="' . ($label ? 'false' : 'true') . '"';
        if ($label) {
            $attrs .= ' aria-label="' . ars_ui_h($label) . '" role="img"';
        }
        return '<i' . $attrs . '></i>';
    }
}

if (!function_exists('ars_ui_button')) {
    /**
     * @param string $variant primary|secondary|danger|ghost
     * @param string $size sm|md|lg
     */
    function ars_ui_button(string $label, array $opts = []): string
    {
        $variant = $opts['variant'] ?? 'primary';
        $size = $opts['size'] ?? 'md';
        $type = ars_ui_h($opts['type'] ?? 'button');
        $disabled = !empty($opts['disabled']);
        $locked = !empty($opts['locked']);
        $href = $opts['href'] ?? null;
        $icon = $opts['icon'] ?? null;
        $classExtra = $opts['class'] ?? '';
        $attrs = $opts['attrs'] ?? '';
        $id = isset($opts['id']) ? ' id="' . ars_ui_h($opts['id']) . '"' : '';

        $base = 'inline-flex items-center justify-center gap-2 rounded-ars-md font-medium transition-all duration-ars-base min-h-ars-touch px-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2';
        $sizes = [
            'sm' => 'text-ars-sm min-h-[40px] px-3',
            'md' => 'text-ars-sm min-h-ars-touch px-4',
            'lg' => 'text-ars-md min-h-12 px-5',
        ];
        $variants = [
            'primary' => 'bg-ars-ink text-white shadow-ars-sm hover:bg-ars-ink-hover hover:shadow-ars-md',
            'secondary' => 'bg-ars-surface text-ars-text border border-ars-border hover:bg-ars-bg shadow-ars-sm',
            'outline' => 'bg-transparent text-ars-ink border border-ars-ink hover:bg-[rgba(184,134,11,0.08)]',
            'danger' => 'bg-ars-danger text-white hover:opacity-90',
            'danger-outline' => 'bg-transparent text-ars-danger border border-ars-danger hover:bg-red-50',
            'ghost' => 'bg-transparent text-ars-ink hover:bg-ars-bg',
        ];
        $cls = $base . ' ' . ($sizes[$size] ?? $sizes['md']) . ' ' . ($variants[$variant] ?? $variants['primary']) . ' ' . $classExtra;
        if ($disabled || $locked) {
            $cls .= ' opacity-50 cursor-not-allowed';
        }

        $inner = ($icon ? ars_ui_icon($icon, ['class' => 'h-4 w-4']) . ' ' : '') . ars_ui_h($label);
        if ($locked) {
            $inner .= ' ' . ars_ui_icon('lock', ['class' => 'h-3.5 w-3.5', 'label' => 'Financially locked']);
            $attrs .= ' aria-disabled="true" title="Financially locked"';
        }

        if ($href && !$disabled && !$locked) {
            return '<a href="' . ars_ui_h($href) . '"' . $id . ' class="' . ars_ui_h(trim($cls)) . '" ' . $attrs . '>' . $inner . '</a>';
        }
        $dis = ($disabled || $locked) ? ' disabled' : '';
        return '<button type="' . $type . '"' . $id . ' class="' . ars_ui_h(trim($cls)) . '"' . $dis . ' ' . $attrs . '>' . $inner . '</button>';
    }
}

if (!function_exists('ars_ui_icon_button')) {
    function ars_ui_icon_button(string $icon, string $label, array $opts = []): string
    {
        $opts['icon'] = $icon;
        $opts['class'] = trim(($opts['class'] ?? '') . ' !px-0 min-w-ars-touch');
        $opts['variant'] = $opts['variant'] ?? 'ghost';
        // Accessible name via aria-label; visible text sr-only
        $html = ars_ui_button($label, array_merge($opts, [
            'attrs' => trim(($opts['attrs'] ?? '') . ' aria-label="' . ars_ui_h($label) . '"'),
            'class' => ($opts['class'] ?? '') . ' ars-icon-btn',
        ]));
        // Replace label text with sr-only span for cleaner icon button
        return preg_replace(
            '/>' . preg_quote(ars_ui_h($label), '/') . '/',
            '><span class="sr-only">' . ars_ui_h($label) . '</span>',
            $html,
            1
        ) ?: $html;
    }
}

if (!function_exists('ars_ui_badge')) {
    function ars_ui_badge(string $label, array $opts = []): string
    {
        $tone = $opts['tone'] ?? 'neutral'; // neutral|success|warning|danger|info|ink
        $icon = $opts['icon'] ?? null;
        $map = [
            'neutral' => 'bg-slate-100 text-slate-600 border-slate-200',
            'success' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'warning' => 'bg-amber-50 text-amber-700 border-amber-200',
            'danger' => 'bg-red-50 text-red-700 border-red-200',
            'info' => 'bg-sky-50 text-sky-700 border-sky-200',
            'ink' => 'bg-[rgba(184,134,11,0.12)] text-[#8a6808] border-[rgba(184,134,11,0.28)]',
        ];
        $cls = 'inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-ars-xs font-semibold ' . ($map[$tone] ?? $map['neutral']);
        $inner = ($icon ? ars_ui_icon($icon, ['class' => 'h-3 w-3']) . ' ' : '') . ars_ui_h($label);
        return '<span class="' . ars_ui_h($cls) . '">' . $inner . '</span>';
    }
}

if (!function_exists('ars_ui_status_badge')) {
    /** Booking / HK / Maint / Financial — always icon + text (+ colour). */
    function ars_ui_status_badge(string $domain, string $status, array $opts = []): string
    {
        $domain = strtolower($domain);
        $status = strtolower(str_replace([' ', '_'], '-', $status));
        $catalog = [
            'booking' => [
                'pending' => ['Pending', 'clock', 'warning'],
                'confirmed' => ['Confirmed', 'check-circle', 'ink'],
                'checked-in' => ['Checked in', 'log-in', 'success'],
                'checked-out' => ['Checked out', 'log-out', 'neutral'],
                'cancelled' => ['Cancelled', 'x-circle', 'danger'],
                'no-show' => ['No-show', 'user-x', 'danger'],
            ],
            'housekeeping' => [
                'dirty' => ['Dirty', 'sparkles', 'warning'],
                'in-progress' => ['In progress', 'loader', 'info'],
                'inspect' => ['Inspect', 'search', 'info'],
                'ready' => ['Ready', 'check', 'success'],
            ],
            'maintenance' => [
                'open' => ['Open', 'wrench', 'warning'],
                'assigned' => ['Assigned', 'user', 'info'],
                'in-progress' => ['In progress', 'loader', 'ink'],
                'done' => ['Done', 'check', 'success'],
                'blocked' => ['Blocked', 'ban', 'danger'],
            ],
            'financial' => [
                'draft' => ['Draft', 'file', 'neutral'],
                'pending' => ['Pending', 'clock', 'warning'],
                'posted' => ['Posted', 'check-circle', 'success'],
                'reversed' => ['Reversed', 'rotate-ccw', 'info'],
                'voided' => ['Voided', 'x-circle', 'neutral'],
                'locked' => ['Locked', 'lock', 'neutral'],
            ],
        ];
        $row = $catalog[$domain][$status] ?? [ucfirst($status), 'circle', 'neutral'];
        $label = $opts['label'] ?? $row[0];
        return ars_ui_badge($label, ['icon' => $row[1], 'tone' => $row[2]]);
    }
}

if (!function_exists('ars_ui_lock_indicator')) {
    function ars_ui_lock_indicator(string $reason = 'Financially locked'): string
    {
        return '<span class="inline-flex items-center gap-1.5 rounded-ars-sm border border-ars-border bg-ars-bg px-2 py-1 text-ars-xs text-ars-muted" role="status">'
            . ars_ui_icon('lock', ['class' => 'h-3.5 w-3.5'])
            . '<span>' . ars_ui_h($reason) . '</span></span>';
    }
}

if (!function_exists('ars_ui_page_header')) {
    function ars_ui_page_header(string $title, array $opts = []): string
    {
        $subtitle = $opts['subtitle'] ?? '';
        $actions = $opts['actions_html'] ?? '';
        $html = '<header class="mb-6 flex flex-wrap items-start justify-between gap-3">';
        $html .= '<div><h1 class="text-ars-2xl font-semibold text-ars-text m-0">' . ars_ui_h($title) . '</h1>';
        if ($subtitle !== '') {
            $html .= '<p class="mt-1 text-ars-sm text-ars-muted m-0">' . ars_ui_h($subtitle) . '</p>';
        }
        $html .= '</div>';
        if ($actions !== '') {
            $html .= '<div class="flex flex-wrap gap-2">' . $actions . '</div>';
        }
        $html .= '</header>';
        return $html;
    }
}

if (!function_exists('ars_ui_breadcrumbs')) {
    /** @param list<array{label:string,href?:string}> $items */
    function ars_ui_breadcrumbs(array $items): string
    {
        $html = '<nav aria-label="Breadcrumb" class="text-ars-xs text-ars-muted"><ol class="m-0 flex flex-wrap items-center gap-0.5 p-0 list-none">';
        $last = count($items) - 1;
        foreach ($items as $i => $item) {
            $label = ars_ui_h($item['label'] ?? '');
            if ($i > 0) {
                $html .= '<li aria-hidden="true" class="px-1 opacity-50">/</li>';
            }
            if ($i === $last || empty($item['href'])) {
                $html .= '<li class="truncate text-ars-muted font-medium" aria-current="page">' . $label . '</li>';
            } else {
                $html .= '<li class="truncate"><a class="text-ars-muted no-underline hover:text-ars-text" href="' . ars_ui_h($item['href']) . '">' . $label . '</a></li>';
            }
        }
        return $html . '</ol></nav>';
    }
}

if (!function_exists('ars_ui_action_toolbar')) {
    function ars_ui_action_toolbar(string $innerHtml, array $opts = []): string
    {
        $class = ars_ui_h($opts['class'] ?? '');
        return '<div class="mb-4 flex flex-wrap items-center gap-2 ' . $class . '" role="toolbar">' . $innerHtml . '</div>';
    }
}

if (!function_exists('ars_ui_kpi_card')) {
    function ars_ui_kpi_card(string $label, string $value, array $opts = []): string
    {
        $hint = $opts['hint'] ?? '';
        $icon = $opts['icon'] ?? 'activity';
        $alert = !empty($opts['alert']);
        $border = $alert ? 'border-ars-warning' : 'border-ars-border';
        $html = '<div class="rounded-ars-lg border ' . $border . ' bg-ars-surface p-4 shadow-ars-sm transition-shadow hover:shadow-ars-md">';
        $html .= '<div class="flex items-start justify-between gap-2">';
        $html .= '<div><div class="text-ars-xs font-medium uppercase tracking-wide text-ars-muted">' . ars_ui_h($label) . '</div>';
        $html .= '<div class="mt-1.5 text-ars-2xl font-semibold tracking-tight ars-tabular text-ars-text">' . ars_ui_h($value) . '</div>';
        if ($hint !== '') {
            $html .= '<div class="mt-1.5 text-ars-xs text-ars-muted">' . ars_ui_h($hint) . '</div>';
        }
        $html .= '</div><span class="flex h-9 w-9 items-center justify-center rounded-ars-md bg-[rgba(184,134,11,0.12)] text-ars-ink">' . ars_ui_icon($icon, ['class' => 'h-4 w-4']) . '</span></div></div>';
        return $html;
    }
}

if (!function_exists('ars_ui_alert_card')) {
    function ars_ui_alert_card(string $title, string $body, array $opts = []): string
    {
        $tone = $opts['tone'] ?? 'warning'; // info|warning|danger|success
        $borders = [
            'info' => 'border-l-ars-info',
            'warning' => 'border-l-ars-warning',
            'danger' => 'border-l-ars-danger',
            'success' => 'border-l-ars-success',
        ];
        $icons = [
            'info' => 'info',
            'warning' => 'alert-triangle',
            'danger' => 'alert-circle',
            'success' => 'check-circle',
        ];
        $b = $borders[$tone] ?? $borders['warning'];
        $ic = $icons[$tone] ?? 'alert-triangle';
        return '<div class="rounded-ars-md border border-ars-border border-l-4 ' . $b . ' bg-ars-surface p-3" role="status">'
            . '<div class="flex gap-2">' . ars_ui_icon($ic, ['class' => 'h-4 w-4 mt-0.5'])
            . '<div><div class="font-medium text-ars-sm">' . ars_ui_h($title) . '</div>'
            . '<div class="text-ars-sm text-ars-muted">' . ars_ui_h($body) . '</div></div></div></div>';
    }
}

if (!function_exists('ars_ui_empty_state')) {
    function ars_ui_empty_state(string $title, string $body = '', array $opts = []): string
    {
        $action = $opts['action_html'] ?? '';
        return '<div class="rounded-ars-lg border border-dashed border-ars-border bg-ars-surface px-6 py-10 text-center">'
            . ars_ui_icon($opts['icon'] ?? 'inbox', ['class' => 'mx-auto h-8 w-8 text-ars-muted'])
            . '<h2 class="mt-3 text-ars-md font-semibold m-0">' . ars_ui_h($title) . '</h2>'
            . ($body !== '' ? '<p class="mt-1 text-ars-sm text-ars-muted m-0">' . ars_ui_h($body) . '</p>' : '')
            . ($action !== '' ? '<div class="mt-4">' . $action . '</div>' : '')
            . '</div>';
    }
}

if (!function_exists('ars_ui_error_banner')) {
    function ars_ui_error_banner(string $message, array $opts = []): string
    {
        $id = $opts['id'] ?? 'ars-error-live';
        return '<div id="' . ars_ui_h($id) . '" class="mb-4 rounded-ars-md border border-ars-danger bg-red-50 px-4 py-3 text-ars-sm text-ars-danger" role="alert" aria-live="assertive">'
            . ars_ui_icon('alert-circle', ['class' => 'inline h-4 w-4 mr-1']) . ' '
            . ars_ui_h($message) . '</div>';
    }
}

if (!function_exists('ars_ui_skeleton')) {
    function ars_ui_skeleton(array $opts = []): string
    {
        $lines = (int)($opts['lines'] ?? 3);
        $html = '<div class="animate-pulse space-y-2" aria-hidden="true" aria-busy="true">';
        for ($i = 0; $i < $lines; $i++) {
            $w = $i === $lines - 1 ? 'w-2/3' : 'w-full';
            $html .= '<div class="h-3 ' . $w . ' rounded-ars-sm bg-ars-border"></div>';
        }
        return $html . '<span class="sr-only">Loading</span></div>';
    }
}

if (!function_exists('ars_ui_form_field')) {
    function ars_ui_form_field(string $name, string $label, array $opts = []): string
    {
        $id = $opts['id'] ?? ('ars-field-' . preg_replace('/[^a-z0-9_-]+/i', '-', $name));
        $type = ars_ui_h($opts['type'] ?? 'text');
        $value = ars_ui_h($opts['value'] ?? '');
        $required = !empty($opts['required']);
        $error = $opts['error'] ?? '';
        $help = $opts['help'] ?? '';
        $disabled = !empty($opts['disabled']);
        $described = [];
        if ($help !== '') {
            $described[] = $id . '-help';
        }
        if ($error !== '') {
            $described[] = $id . '-error';
        }
        $html = '<div class="mb-4">';
        $html .= '<label for="' . ars_ui_h($id) . '" class="mb-1 block text-ars-sm font-medium text-ars-text">'
            . ars_ui_h($label)
            . ($required ? ' <span class="text-ars-danger" aria-hidden="true">*</span><span class="sr-only"> (required)</span>' : '')
            . '</label>';
        $html .= '<input type="' . $type . '" id="' . ars_ui_h($id) . '" name="' . ars_ui_h($name) . '" value="' . $value . '"'
            . ($required ? ' required aria-required="true"' : '')
            . ($disabled ? ' disabled' : '')
            . ($error !== '' ? ' aria-invalid="true"' : '')
            . ($described ? ' aria-describedby="' . ars_ui_h(implode(' ', $described)) . '"' : '')
            . ' class="block w-full min-h-ars-touch rounded-ars-md border border-ars-border bg-ars-surface px-3 text-ars-sm text-ars-text" />';
        if ($help !== '') {
            $html .= '<p id="' . ars_ui_h($id) . '-help" class="mt-1 text-ars-xs text-ars-muted m-0">' . ars_ui_h($help) . '</p>';
        }
        if ($error !== '') {
            $html .= ars_ui_form_error($error, $id . '-error');
        }
        return $html . '</div>';
    }
}

if (!function_exists('ars_ui_form_error')) {
    function ars_ui_form_error(string $message, string $id = ''): string
    {
        $idAttr = $id !== '' ? ' id="' . ars_ui_h($id) . '"' : '';
        return '<p' . $idAttr . ' class="mt-1 text-ars-xs text-ars-danger m-0" role="alert">' . ars_ui_h($message) . '</p>';
    }
}

if (!function_exists('ars_ui_filter_bar')) {
    function ars_ui_filter_bar(string $innerHtml): string
    {
        return '<div class="mb-4 flex flex-wrap items-end gap-3 rounded-ars-lg border border-ars-border bg-ars-surface p-4 shadow-ars-sm" role="search">'
            . $innerHtml . '</div>';
    }
}

if (!function_exists('ars_ui_table_shell')) {
    function ars_ui_table_shell(string $caption, string $theadHtml, string $tbodyHtml, array $opts = []): string
    {
        return '<div class="overflow-hidden rounded-ars-lg border border-ars-border bg-ars-surface shadow-ars-sm">'
            . '<div class="overflow-x-auto"><table class="ars-data-table min-w-full text-left text-ars-sm">'
            . '<caption class="sr-only">' . ars_ui_h($caption) . '</caption>'
            . '<thead>' . $theadHtml . '</thead>'
            . '<tbody>' . $tbodyHtml . '</tbody>'
            . '</table></div></div>';
    }
}

if (!function_exists('ars_ui_drawer_shell')) {
    function ars_ui_drawer_shell(string $id, string $title, string $bodyHtml, array $opts = []): string
    {
        $side = ($opts['side'] ?? 'right') === 'left' ? 'left-0' : 'right-0';
        $idEsc = ars_ui_h($id);
        $titleEsc = ars_ui_h($title);
        return <<<HTML
<div x-data="{ open: false }" class="relative inline" id="{$idEsc}-root">
  <div x-show="open" x-cloak class="fixed inset-0 z-[1080]" role="dialog" aria-modal="true" aria-labelledby="{$idEsc}-title"
       data-ars-focus-trap @keydown.escape.window="open = false">
    <div class="absolute inset-0 bg-black/30" @click="open = false"></div>
    <div class="absolute top-0 {$side} flex h-full w-full max-w-md flex-col bg-ars-surface shadow-ars-md" @click.stop>
      <div class="flex items-center justify-between border-b border-ars-border px-4 py-3">
        <h2 id="{$idEsc}-title" class="text-ars-md font-semibold m-0">{$titleEsc}</h2>
        <button type="button" class="min-h-ars-touch min-w-ars-touch rounded-ars-md" @click="open = false" aria-label="Close drawer">✕</button>
      </div>
      <div class="flex-1 overflow-y-auto p-4">{$bodyHtml}</div>
    </div>
  </div>
</div>
HTML;
    }
}

if (!function_exists('ars_ui_modal_shell')) {
    function ars_ui_modal_shell(string $id, string $title, string $bodyHtml, array $opts = []): string
    {
        $idEsc = ars_ui_h($id);
        $titleEsc = ars_ui_h($title);
        return <<<HTML
<div x-data="{ open: false }" id="{$idEsc}-root" class="inline">
  <div x-show="open" x-cloak class="fixed inset-0 z-[1090] flex items-center justify-center p-4" role="dialog" aria-modal="true"
       aria-labelledby="{$idEsc}-title" data-ars-focus-trap @keydown.escape.window="open = false">
    <div class="absolute inset-0 bg-black/40" @click="open = false"></div>
    <div class="relative z-10 w-full max-w-lg rounded-ars-lg border border-ars-border bg-ars-surface p-5 shadow-ars-md" @click.stop>
      <h2 id="{$idEsc}-title" class="text-ars-lg font-semibold m-0 mb-3">{$titleEsc}</h2>
      <div>{$bodyHtml}</div>
      <div class="mt-4 flex justify-end gap-2">
        <button type="button" class="rounded-ars-md border border-ars-border px-4 min-h-ars-touch" @click="open = false">Close</button>
      </div>
    </div>
  </div>
</div>
HTML;
    }
}

if (!function_exists('ars_ui_confirm_dialog')) {
    function ars_ui_confirm_dialog(string $id, string $title, string $message, array $opts = []): string
    {
        $danger = !empty($opts['danger']);
        $confirmLabel = ars_ui_h($opts['confirm_label'] ?? 'Confirm');
        $idEsc = ars_ui_h($id);
        $titleEsc = ars_ui_h($title);
        $msgEsc = ars_ui_h($message);
        $btnClass = $danger ? 'bg-ars-danger text-white' : 'bg-ars-ink text-white';
        return <<<HTML
<div x-data="{ open: false }" id="{$idEsc}-root" class="inline">
  <div x-show="open" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" role="alertdialog" aria-modal="true"
       aria-labelledby="{$idEsc}-title" aria-describedby="{$idEsc}-desc" data-ars-focus-trap @keydown.escape.window="open = false">
    <div class="absolute inset-0 bg-black/40" @click="open = false"></div>
    <div class="relative z-10 w-full max-w-md rounded-ars-lg border border-ars-border bg-ars-surface p-5 shadow-ars-md" @click.stop>
      <h2 id="{$idEsc}-title" class="text-ars-lg font-semibold m-0">{$titleEsc}</h2>
      <p id="{$idEsc}-desc" class="mt-2 text-ars-sm text-ars-muted">{$msgEsc}</p>
      <div class="mt-4 flex justify-end gap-2">
        <button type="button" class="rounded-ars-md border border-ars-border px-4 min-h-ars-touch" @click="open = false">Cancel</button>
        <button type="button" class="rounded-ars-md px-4 min-h-ars-touch {$btnClass}" @click="open = false">{$confirmLabel}</button>
      </div>
    </div>
  </div>
</div>
HTML;
    }
}

if (!function_exists('ars_ui_toast_region')) {
    function ars_ui_toast_region(): string
    {
        return '<div id="ars-toast-region" class="pointer-events-none fixed bottom-4 right-4 z-[1200] w-80 max-w-[90vw]" aria-live="polite" aria-relevant="additions"></div>';
    }
}

if (!function_exists('ars_ui_timeline_item')) {
    function ars_ui_timeline_item(string $title, string $meta, string $body = ''): string
    {
        return '<div class="relative border-l border-ars-border pl-4 pb-4">'
            . '<span class="absolute -left-1.5 top-1 h-3 w-3 rounded-full bg-ars-ink" aria-hidden="true"></span>'
            . '<div class="text-ars-sm font-medium">' . ars_ui_h($title) . '</div>'
            . '<div class="text-ars-xs text-ars-muted">' . ars_ui_h($meta) . '</div>'
            . ($body !== '' ? '<div class="mt-1 text-ars-sm text-ars-muted">' . ars_ui_h($body) . '</div>' : '')
            . '</div>';
    }
}

if (!function_exists('ars_ui_permission_disabled')) {
    function ars_ui_permission_disabled(string $label, string $reason): string
    {
        return '<button type="button" class="inline-flex min-h-ars-touch items-center gap-2 rounded-ars-md border border-ars-border bg-ars-bg px-4 text-ars-sm text-ars-muted opacity-60 cursor-not-allowed" disabled aria-disabled="true" title="' . ars_ui_h($reason) . '">'
            . ars_ui_h($label) . ' ' . ars_ui_icon('shield-off', ['class' => 'h-3.5 w-3.5'])
            . '</button>';
    }
}

if (!function_exists('ars_ui_network_error')) {
    function ars_ui_network_error(string $message = 'Network error. Check your connection and try again.', array $opts = []): string
    {
        $retryId = $opts['retry_id'] ?? '';
        $html = '<div class="rounded-ars-md border border-ars-danger bg-red-50 p-4" role="alert">';
        $html .= '<div class="flex items-start gap-2">' . ars_ui_icon('wifi-off', ['class' => 'h-5 w-5 text-ars-danger'])
            . '<div><div class="font-medium text-ars-sm text-ars-danger">Connection problem</div>'
            . '<p class="m-0 text-ars-sm text-ars-muted">' . ars_ui_h($message) . '</p>';
        if ($retryId !== '') {
            $html .= '<button type="button" id="' . ars_ui_h($retryId) . '" class="mt-2 rounded-ars-md border border-ars-border bg-ars-surface px-3 min-h-ars-touch text-ars-sm">Retry</button>';
        }
        return $html . '</div></div></div>';
    }
}

/** Alpine Focus plugin is optional; provide lightweight trap note via data attribute when plugin absent. */
if (!function_exists('ars_ui_versions')) {
    function ars_ui_versions(): array
    {
        return [
            'ars_ui' => '0.1.0-wave0',
            'tailwind' => '3.4.17',
            'alpine' => '3.14.8',
            'lucide' => '0.469.0',
            'font' => 'Plus Jakarta Sans (self-hosted woff2)',
        ];
    }
}
