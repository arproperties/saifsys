<?php
/**
 * HR UI — presentational helpers only (no business logic).
 */

if (!function_exists('hr_ui_h')) {
    function hr_ui_h($s): string
    {
        return function_exists('h')
            ? h($s)
            : htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('hr_ui_status_pill')) {
    function hr_ui_status_pill(string $status, ?string $label = null): string
    {
        $key = strtolower(trim($status));
        $map = [
            'success' => 'hr-pill-ok',
            'ok' => 'hr-pill-ok',
            'active' => 'hr-pill-ok',
            'approved' => 'hr-pill-ok',
            'present' => 'hr-pill-ok',
            'failure' => 'hr-pill-fail',
            'fail' => 'hr-pill-fail',
            'error' => 'hr-pill-fail',
            'rejected' => 'hr-pill-fail',
            'absent' => 'hr-pill-fail',
            'warning' => 'hr-pill-warn',
            'pending' => 'hr-pill-warn',
            'draft' => 'hr-pill-warn',
            'half' => 'hr-pill-warn',
            'info' => 'hr-pill-info',
            'posted' => 'hr-pill-info',
            'left' => 'hr-pill-muted',
            'inactive' => 'hr-pill-muted',
        ];
        $cls = $map[$key] ?? 'hr-pill-muted';
        $text = $label ?? str_replace('_', ' ', $status);
        return '<span class="hr-pill ' . $cls . '">' . hr_ui_h($text) . '</span>';
    }
}

/**
 * @param list<array{label:string,href?:string}> $crumbs
 * @param string|list<string> $actions
 */
if (!function_exists('hr_ui_page_header')) {
    function hr_ui_page_header(string $title, string $description = '', array $crumbs = [], $actions = ''): string
    {
        $crumbHtml = '';
        if ($crumbs) {
            $parts = [];
            foreach ($crumbs as $c) {
                $label = hr_ui_h($c['label'] ?? '');
                if (!empty($c['href'])) {
                    $parts[] = '<a href="' . hr_ui_h($c['href']) . '" class="hr-crumb">' . $label . '</a>';
                } else {
                    $parts[] = '<span class="hr-crumb">' . $label . '</span>';
                }
            }
            $crumbHtml = '<nav class="hr-crumb small mb-1">' . implode(' <span class="mx-1">›</span> ', $parts) . '</nav>';
        }
        $actionsHtml = is_array($actions) ? implode(' ', $actions) : (string)$actions;
        return '<div class="hr-page-header mb-3 d-flex flex-wrap justify-content-between align-items-start gap-3">'
            . '<div>' . $crumbHtml
            . '<h1>' . hr_ui_h($title) . '</h1>'
            . ($description !== '' ? '<p class="hr-page-sub small mb-0 mt-1">' . hr_ui_h($description) . '</p>' : '')
            . '</div>'
            . ($actionsHtml !== '' ? '<div class="d-flex flex-wrap gap-2">' . $actionsHtml . '</div>' : '')
            . '</div>';
    }
}

/**
 * @param array{label:string,value:string,sub?:string,icon?:string,href?:string} $kpi
 */
if (!function_exists('hr_ui_kpi')) {
    function hr_ui_kpi(array $kpi): string
    {
        $label = hr_ui_h($kpi['label'] ?? '');
        $value = hr_ui_h($kpi['value'] ?? '—');
        $sub = (string)($kpi['sub'] ?? '');
        $icon = $kpi['icon'] ?? 'activity';
        $subHtml = $sub !== '' ? '<div class="kpi-sub">' . hr_ui_h($sub) . '</div>' : '';
        $inner = '<div class="d-flex justify-content-between align-items-start gap-2">'
            . '<div><div class="kpi-label">' . $label . '</div>'
            . '<div class="kpi-value">' . $value . '</div>'
            . $subHtml
            . '</div>'
            . '<div class="kpi-icon"><i data-lucide="' . hr_ui_h($icon) . '" style="width:20px;height:20px"></i></div>'
            . '</div>';
        if (!empty($kpi['href'])) {
            return '<a class="hr-kpi text-decoration-none d-block" href="' . hr_ui_h($kpi['href']) . '">' . $inner . '</a>';
        }
        return '<div class="hr-kpi">' . $inner . '</div>';
    }
}

if (!function_exists('hr_ui_empty')) {
    function hr_ui_empty(string $message, string $icon = 'inbox'): string
    {
        return '<div class="hr-empty">'
            . '<div class="mb-2"><i data-lucide="' . hr_ui_h($icon) . '" style="width:36px;height:36px;opacity:.45"></i></div>'
            . '<p class="mb-0">' . hr_ui_h($message) . '</p>'
            . '</div>';
    }
}

if (!function_exists('hr_ui_alert')) {
    function hr_ui_alert(string $html, string $type = 'info'): string
    {
        $allowed = ['info', 'success', 'warning', 'danger'];
        if (!in_array($type, $allowed, true)) {
            $type = 'info';
        }
        return '<div class="alert alert-' . $type . ' border-0 shadow-sm">' . $html . '</div>';
    }
}
