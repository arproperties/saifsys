<?php
/**
 * Administration UI — presentational helpers only (no business logic).
 */

if (!function_exists('admin_ui_h')) {
    function admin_ui_h($s): string
    {
        return function_exists('h')
            ? h($s)
            : htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_ui_scope_badge')) {
    function admin_ui_scope_badge(string $scope): string
    {
        $scope = trim($scope);
        if ($scope === '') {
            return '';
        }
        return '<span class="admin-pill admin-pill-muted">' . admin_ui_h($scope) . '</span>';
    }
}

if (!function_exists('admin_ui_status_pill')) {
    function admin_ui_status_pill(string $status, ?string $label = null): string
    {
        $key = strtolower(trim($status));
        $map = [
            'success' => 'admin-pill-ok',
            'ok' => 'admin-pill-ok',
            'active' => 'admin-pill-ok',
            'failure' => 'admin-pill-fail',
            'fail' => 'admin-pill-fail',
            'error' => 'admin-pill-fail',
            'warning' => 'admin-pill-warn',
            'pending' => 'admin-pill-warn',
            'info' => 'admin-pill-info',
            'api' => 'admin-pill-info',
            'system' => 'admin-pill-muted',
            'job' => 'admin-pill-muted',
            'user' => 'admin-pill-gold',
        ];
        $cls = $map[$key] ?? 'admin-pill-muted';
        $text = $label ?? str_replace('_', ' ', $status);
        return '<span class="admin-pill ' . $cls . '">' . admin_ui_h($text) . '</span>';
    }
}

/**
 * @param list<array{label:string,href?:string}> $crumbs
 * @param string|list<string> $actions
 */
if (!function_exists('admin_ui_page_header')) {
    function admin_ui_page_header(string $title, string $description = '', array $crumbs = [], $actions = ''): string
    {
        $crumbHtml = '';
        if ($crumbs) {
            $parts = [];
            foreach ($crumbs as $c) {
                $label = admin_ui_h($c['label'] ?? '');
                if (!empty($c['href'])) {
                    $parts[] = '<a href="' . admin_ui_h($c['href']) . '" class="admin-crumb">' . $label . '</a>';
                } else {
                    $parts[] = '<span class="admin-crumb">' . $label . '</span>';
                }
            }
            $crumbHtml = '<nav class="admin-crumb small mb-1">' . implode(' <span class="mx-1">›</span> ', $parts) . '</nav>';
        }
        $actionsHtml = is_array($actions) ? implode(' ', $actions) : (string)$actions;
        return '<div class="admin-page-header mb-3 d-flex flex-wrap justify-content-between align-items-start gap-3">'
            . '<div>' . $crumbHtml
            . '<h1>' . admin_ui_h($title) . '</h1>'
            . ($description !== '' ? '<p class="admin-page-sub small mb-0 mt-1">' . admin_ui_h($description) . '</p>' : '')
            . '</div>'
            . ($actionsHtml !== '' ? '<div class="d-flex flex-wrap gap-2">' . $actionsHtml . '</div>' : '')
            . '</div>';
    }
}

/**
 * @param array{label:string,value:string,sub?:string,icon?:string,tone?:string,href?:string} $kpi
 */
if (!function_exists('admin_ui_kpi')) {
    function admin_ui_kpi(array $kpi): string
    {
        $label = admin_ui_h($kpi['label'] ?? '');
        $value = admin_ui_h($kpi['value'] ?? '—');
        $sub = admin_ui_h($kpi['sub'] ?? '');
        $icon = $kpi['icon'] ?? 'activity';
        $inner = '<div class="d-flex justify-content-between align-items-start gap-2">'
            . '<div><div class="kpi-label">' . $label . '</div>'
            . '<div class="kpi-value">' . $value . '</div>'
            . ($sub !== '' ? '<div class="kpi-sub">' . $sub . '</div>' : '')
            . '</div>'
            . '<div class="kpi-icon"><i data-lucide="' . admin_ui_h($icon) . '" style="width:20px;height:20px"></i></div>'
            . '</div>';
        if (!empty($kpi['href'])) {
            return '<a class="admin-kpi text-decoration-none d-block" href="' . admin_ui_h($kpi['href']) . '">' . $inner . '</a>';
        }
        return '<div class="admin-kpi">' . $inner . '</div>';
    }
}

if (!function_exists('admin_ui_empty')) {
    function admin_ui_empty(string $message, string $icon = 'inbox'): string
    {
        return '<div class="admin-empty">'
            . '<div class="mb-2"><i data-lucide="' . admin_ui_h($icon) . '" style="width:36px;height:36px;opacity:.45"></i></div>'
            . '<p class="mb-0">' . admin_ui_h($message) . '</p>'
            . '</div>';
    }
}

if (!function_exists('admin_ui_alert')) {
    function admin_ui_alert(string $html, string $type = 'info'): string
    {
        $allowed = ['info', 'success', 'warning', 'danger'];
        if (!in_array($type, $allowed, true)) {
            $type = 'info';
        }
        return '<div class="alert alert-' . $type . ' border-0 shadow-sm">' . $html . '</div>';
    }
}
