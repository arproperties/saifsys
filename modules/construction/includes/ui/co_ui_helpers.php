<?php
/**
 * Construction UI — presentational helpers only (no business / accounting logic).
 */

if (!function_exists('h') && !function_exists('co_ui_h')) {
    function co_ui_h($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('co_ui_h')) {
    function co_ui_h($s): string {
        return function_exists('h') ? h($s) : htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('co_ui_status_pill')) {
    function co_ui_status_pill(string $status, ?string $label = null): string {
        $key = strtolower(trim($status));
        $map = [
            'active' => 'co-pill-active',
            'draft' => 'co-pill-draft',
            'expired' => 'co-pill-expired',
            'terminated' => 'co-pill-terminated',
            'renewed' => 'co-pill-renewed',
            'archived' => 'co-pill-archived',
            'pending' => 'co-pill-warn',
            'submitted' => 'co-pill-warn',
            'under_review' => 'co-pill-warn',
            'approved' => 'co-pill-active',
            'rejected' => 'co-pill-terminated',
            'posted' => 'co-pill-active',
            'paid' => 'co-pill-active',
            'partially_paid' => 'co-pill-warn',
            'overdue' => 'co-pill-terminated',
            'completed' => 'co-pill-renewed',
            'cancelled' => 'co-pill-archived',
            'canceled' => 'co-pill-archived',
            'reversed' => 'co-pill-archived',
            'inactive' => 'co-pill-archived',
            'on_hold' => 'co-pill-warn',
            'open' => 'co-pill-warn',
            'closed' => 'co-pill-archived',
        ];
        $cls = $map[$key] ?? 'co-pill-expired';
        $text = $label ?? str_replace('_', ' ', $status);
        return '<span class="co-pill ' . $cls . '">' . co_ui_h($text) . '</span>';
    }
}

if (!function_exists('co_ui_kpi_ring')) {
    function co_ui_kpi_ring(float $pct): string {
        $pct = max(0, min(100, $pct));
        $r = 14;
        $c = 2 * M_PI * $r;
        $dash = round($c * $pct / 100, 2);
        $gap = round($c - $dash, 2);
        return '<div class="co-kpi-ring" aria-hidden="true"><svg width="52" height="52" viewBox="0 0 36 36">'
            . '<circle class="track" cx="18" cy="18" r="' . $r . '"></circle>'
            . '<circle class="prog" cx="18" cy="18" r="' . $r . '" stroke-dasharray="' . $dash . ' ' . $gap . '"></circle>'
            . '</svg></div>';
    }
}

/**
 * @param list<array{label:string,href?:string}> $crumbs
 * @param string|list<string> $actions HTML buttons/links
 */
if (!function_exists('co_ui_page_header')) {
    function co_ui_page_header(string $title, string $description = '', array $crumbs = [], $actions = ''): string {
        $crumbHtml = '';
        if ($crumbs) {
            $parts = [];
            foreach ($crumbs as $c) {
                $label = co_ui_h($c['label'] ?? '');
                if (!empty($c['href'])) {
                    $parts[] = '<a href="' . co_ui_h($c['href']) . '" style="color:var(--co-text-muted)">' . $label . '</a>';
                } else {
                    $parts[] = '<span>' . $label . '</span>';
                }
            }
            $crumbHtml = '<nav class="co-crumb small mb-1" style="color:var(--co-text-dim)">' . implode(' <span class="mx-1">›</span> ', $parts) . '</nav>';
        }
        $actionsHtml = is_array($actions) ? implode(' ', $actions) : (string)$actions;
        return '<div class="co-page-header mb-3 d-flex flex-wrap justify-content-between align-items-start gap-3">'
            . '<div>' . $crumbHtml
            . '<h1 class="h4 mb-0" style="font-family:var(--co-font-display);color:var(--construction-heading-color,var(--co-text))">' . co_ui_h($title) . '</h1>'
            . ($description !== '' ? '<p class="co-page-sub text-muted small mb-0 mt-1">' . co_ui_h($description) . '</p>' : '')
            . '</div>'
            . ($actionsHtml !== '' ? '<div class="co-page-actions d-flex flex-wrap gap-2">' . $actionsHtml . '</div>' : '')
            . '</div>';
    }
}

/**
 * @param array{label:string,value:string,sub?:string,icon?:string,tone?:string,href?:string} $kpi
 */
if (!function_exists('co_ui_kpi')) {
    function co_ui_kpi(array $kpi): string {
        $tone = $kpi['tone'] ?? '';
        $iconCls = $tone ? 'co-kpi-icon ' . $tone : 'co-kpi-icon';
        $icon = !empty($kpi['icon'])
            ? '<div class="' . co_ui_h($iconCls) . '"><i data-lucide="' . co_ui_h($kpi['icon']) . '" style="width:18px;height:18px"></i></div>'
            : '';
        $valCls = 'val';
        if ($tone === 'danger') {
            $valCls .= ' danger';
        } elseif ($tone === 'teal') {
            $valCls .= ' teal';
        } elseif ($tone === '' || $tone === 'gold') {
            $valCls .= ' gold';
        }
        $inner = $icon
            . '<div class="lbl">' . co_ui_h($kpi['label'] ?? '') . '</div>'
            . '<div class="' . $valCls . '">' . ($kpi['value'] ?? '') . '</div>'
            . (!empty($kpi['sub']) ? '<div class="sub">' . co_ui_h($kpi['sub']) . '</div>' : '');
        if (!empty($kpi['href'])) {
            return '<a href="' . co_ui_h($kpi['href']) . '" class="co-kpi text-decoration-none d-block">' . $inner . '</a>';
        }
        return '<div class="co-kpi">' . $inner . '</div>';
    }
}

if (!function_exists('co_ui_empty')) {
    function co_ui_empty(string $message, string $icon = 'folder-open'): string {
        return '<div class="co-empty"><div class="ico"><i data-lucide="' . co_ui_h($icon) . '" style="width:32px;height:32px"></i></div>'
            . co_ui_h($message) . '</div>';
    }
}

if (!function_exists('co_ui_alert')) {
    function co_ui_alert(string $message, string $type = 'info'): string {
        $map = [
            'success' => 'alert-success',
            'warning' => 'alert-warning',
            'danger' => 'alert-danger',
            'error' => 'alert-danger',
            'info' => 'alert-info',
        ];
        $cls = $map[$type] ?? 'alert-info';
        return '<div class="alert ' . $cls . '">' . $message . '</div>';
    }
}

if (!function_exists('co_ui_filter_bar_open')) {
    function co_ui_filter_bar_open(): string {
        return '<div class="co-filter-bar card card-round mb-3"><div class="card-body py-3">';
    }
}

if (!function_exists('co_ui_filter_bar_close')) {
    function co_ui_filter_bar_close(): string {
        return '</div></div>';
    }
}
