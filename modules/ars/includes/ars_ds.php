<?php
/**
 * ARS Design System extensions (Phase 3B + Flagship Visual Redesign).
 * Builds on Wave 0 ars_ui.php — presentational helpers only. No financial calculations.
 */
require_once __DIR__ . '/ars_ui.php';

if (!function_exists('ars_ds_guest_initials')) {
    function ars_ds_guest_initials(string $first = '', string $last = '', string $fallback = 'G'): string
    {
        $a = strtoupper(substr(trim($first), 0, 1));
        $b = strtoupper(substr(trim($last), 0, 1));
        $out = $a . $b;
        return $out !== '' ? $out : strtoupper(substr($fallback, 0, 1));
    }
}

if (!function_exists('ars_ds_avatar')) {
    function ars_ds_avatar(string $initials, array $opts = []): string
    {
        $size = ($opts['size'] ?? 'md') === 'sm' ? ' ars-avatar-sm' : '';
        return '<span class="ars-avatar' . $size . '" aria-hidden="true">' . ars_ui_h($initials) . '</span>';
    }
}

if (!function_exists('ars_ds_segment')) {
    /** @param list<array{id:string,label:string,href?:string,active?:bool,icon?:string}> $items */
    function ars_ds_segment(array $items, array $opts = []): string
    {
        $html = '<div class="inline-flex flex-wrap gap-1 rounded-ars-lg border border-ars-border bg-ars-surface p-1 shadow-ars-sm" role="tablist">';
        foreach ($items as $it) {
            $active = !empty($it['active']);
            $cls = 'no-underline rounded-ars-md px-3.5 py-1.5 text-ars-sm min-h-[40px] inline-flex items-center gap-1.5 transition-all duration-ars-base ars-btn-press ' . ($active
                ? 'bg-ars-ink text-white font-semibold shadow-ars-sm'
                : 'text-ars-muted hover:bg-ars-bg hover:text-ars-text');
            $icon = !empty($it['icon']) ? ars_ui_icon($it['icon'], ['class' => 'h-3.5 w-3.5']) : '';
            $label = $icon . '<span>' . ars_ui_h($it['label']) . '</span>';
            if (!empty($it['href'])) {
                $html .= '<a role="tab" aria-selected="' . ($active ? 'true' : 'false') . '" class="' . $cls . '" href="' . ars_ui_h($it['href']) . '">' . $label . '</a>';
            } else {
                $html .= '<button type="button" role="tab" aria-selected="' . ($active ? 'true' : 'false') . '" class="' . $cls . '" data-ars-seg="' . ars_ui_h($it['id']) . '">' . $label . '</button>';
            }
        }
        return $html . '</div>';
    }
}

if (!function_exists('ars_ds_stat_tile')) {
    /**
     * @param array{href?:?string,tone?:string,icon?:string,hint?:string,delta?:string,delta_tone?:string} $opts
     */
    function ars_ds_stat_tile(string $label, string $value, array $opts = []): string
    {
        $href = $opts['href'] ?? null;
        $tone = $opts['tone'] ?? 'default'; // default|warn|danger|ok
        $icon = $opts['icon'] ?? 'activity';
        $hint = $opts['hint'] ?? '';
        $delta = $opts['delta'] ?? '';
        $deltaTone = $opts['delta_tone'] ?? 'muted';
        $border = [
            'default' => '',
            'warn' => ' ars-cc-kpi--warn',
            'danger' => ' ars-cc-kpi--danger',
            'ok' => ' ars-cc-kpi--ok',
        ][$tone] ?? '';
        $iconWrap = [
            'default' => 'bg-[rgba(184,134,11,0.12)] text-ars-ink',
            'warn' => 'bg-orange-50 text-ars-warning',
            'danger' => 'bg-red-50 text-ars-danger',
            'ok' => 'bg-emerald-50 text-ars-success',
        ][$tone] ?? 'bg-[rgba(184,134,11,0.12)] text-ars-ink';
        $deltaCls = [
            'up' => 'text-ars-success',
            'down' => 'text-ars-danger',
            'muted' => 'text-ars-muted',
        ][$deltaTone] ?? 'text-ars-muted';

        $inner = '<div class="ars-cc-kpi group ars-interactive' . $border . '">'
            . '<div class="flex items-start justify-between gap-3">'
            . '<div class="min-w-0">'
            . '<div class="text-ars-xs font-medium uppercase tracking-wide text-ars-muted">' . ars_ui_h($label) . '</div>'
            . '<div class="mt-1.5 text-ars-2xl font-semibold tracking-tight ars-tabular text-ars-text">' . ars_ui_h($value) . '</div>';
        if ($delta !== '') {
            $inner .= '<div class="mt-1.5 text-ars-xs font-medium ' . $deltaCls . '">' . ars_ui_h($delta) . '</div>';
        } elseif ($hint !== '') {
            $inner .= '<div class="mt-1.5 text-ars-xs text-ars-muted">' . ars_ui_h($hint) . '</div>';
        }
        $inner .= '</div>'
            . '<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-ars-md ' . $iconWrap . '">'
            . ars_ui_icon($icon, ['class' => 'h-4 w-4'])
            . '</span>'
            . '</div></div>';
        if ($href) {
            return '<a class="ars-cc-kpi-link" href="' . ars_ui_h($href) . '">' . $inner . '</a>';
        }
        return $inner;
    }
}

if (!function_exists('ars_ds_list_row')) {
    function ars_ds_list_row(string $title, string $meta, string $href = '', string $trailingHtml = '', array $opts = []): string
    {
        $avatar = $opts['avatar'] ?? '';
        $left = ($avatar !== '' ? $avatar : '')
            . '<div class="min-w-0"><div class="truncate text-ars-sm font-semibold text-ars-text">' . ars_ui_h($title) . '</div>'
            . '<div class="mt-0.5 truncate text-ars-xs text-ars-muted">' . ars_ui_h($meta) . '</div></div>';
        $body = '<div class="ars-cc-ops-row">'
            . '<div class="flex min-w-0 flex-1 items-center gap-3">' . $left . '</div>'
            . '<div class="shrink-0 flex items-center gap-2">' . $trailingHtml . '</div></div>';
        if ($href !== '') {
            return '<a class="block no-underline text-inherit ars-row-link" href="' . ars_ui_h($href) . '">' . $body . '</a>';
        }
        return $body;
    }
}

if (!function_exists('ars_ds_panel')) {
    function ars_ds_panel(string $title, string $bodyHtml, array $opts = []): string
    {
        $action = $opts['action_html'] ?? '';
        $icon = $opts['icon'] ?? '';
        $class = ars_ui_h($opts['class'] ?? '');
        $titleHtml = ($icon !== '' ? '<span class="mr-2 inline-flex text-ars-ink opacity-80">' . ars_ui_icon($icon, ['class' => 'h-4 w-4']) . '</span>' : '')
            . ars_ui_h($title);
        return '<section class="ars-cc-panel ars-interactive ' . $class . '">'
            . '<div class="ars-cc-panel-head">'
            . '<h2 class="ars-cc-panel-title flex items-center">' . $titleHtml . '</h2>'
            . ($action !== '' ? '<div class="shrink-0">' . $action . '</div>' : '')
            . '</div><div class="p-0">' . $bodyHtml . '</div></section>';
    }
}

if (!function_exists('ars_ds_filter_card')) {
    function ars_ds_filter_card(string $innerHtml): string
    {
        return '<div class="mb-4 rounded-ars-lg border border-ars-border bg-ars-surface p-3 shadow-ars-sm sm:p-4">'
            . $innerHtml
            . '</div>';
    }
}

if (!function_exists('ars_ds_table_card')) {
    function ars_ds_table_card(string $tableHtml, array $opts = []): string
    {
        $class = ars_ui_h($opts['class'] ?? '');
        return '<div class="overflow-hidden rounded-ars-lg border border-ars-border bg-ars-surface shadow-ars-sm ' . $class . '">'
            . '<div class="overflow-x-auto">' . $tableHtml . '</div></div>';
    }
}

if (!function_exists('ars_ds_workspace_tabs')) {
    /** @param list<array{id:string,label:string,active?:bool,icon?:string}> $tabs */
    function ars_ds_workspace_tabs(array $tabs): string
    {
        $html = '<div class="ars-ws-tabs" role="tablist">';
        foreach ($tabs as $t) {
            $active = !empty($t['active']);
            $icon = !empty($t['icon']) ? ars_ui_icon($t['icon'], ['class' => 'h-3.5 w-3.5']) : '';
            $html .= '<button type="button" role="tab" class="ars-ws-tab' . ($active ? ' is-active' : '') . '"'
                . ' data-ars-ws-tab="' . ars_ui_h($t['id']) . '"'
                . ' aria-selected="' . ($active ? 'true' : 'false') . '"'
                . ' aria-controls="ars-ws-panel-' . ars_ui_h($t['id']) . '">'
                . $icon . '<span>' . ars_ui_h($t['label']) . '</span></button>';
        }
        return $html . '</div>';
    }
}

if (!function_exists('ars_ds_wizard_step_rail')) {
    /**
     * Static step rail for booking wizard (vanilla JS toggles classes).
     * Classes: is-current | is-done | is-todo
     * @param list<string> $labels
     */
    function ars_ds_wizard_step_rail(array $labels): string
    {
        $html = '<ol class="ars-wizard-rail" aria-label="Wizard steps" data-ars-wizard-rail>';
        foreach ($labels as $i => $label) {
            $n = $i + 1;
            $state = $n === 1 ? 'is-current' : 'is-todo';
            $html .= '<li class="ars-wizard-rail-item">'
                . '<button type="button" class="ars-wizard-rail-btn ' . $state . '"'
                . ' data-ars-wizard-goto="' . $n . '"'
                . ' aria-current="' . ($n === 1 ? 'step' : 'false') . '">'
                . '<span class="ars-wizard-rail-num" aria-hidden="true">' . $n . '</span>'
                . '<span class="ars-wizard-rail-label">' . ars_ui_h($label) . '</span>'
                . '</button>';
            if ($i < count($labels) - 1) {
                $html .= '<span class="ars-wizard-rail-sep" aria-hidden="true"></span>';
            }
            $html .= '</li>';
        }
        return $html . '</ol>';
    }
}

if (!function_exists('ars_ds_format_stay')) {
    function ars_ds_format_stay(string $checkIn, string $checkOut): string
    {
        $inTs = strtotime($checkIn);
        $outTs = strtotime($checkOut);
        if (!$inTs || !$outTs) {
            return trim($checkIn . ' → ' . $checkOut);
        }
        $in = date('d M', $inTs);
        $out = date('d M Y', $outTs);
        $nights = max(0, (int)round(($outTs - $inTs) / 86400));
        return $in . ' → ' . $out . ($nights ? ' · ' . $nights . 'n' : '');
    }
}
