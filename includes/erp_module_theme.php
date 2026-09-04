<?php
/**
 * Company-scoped module appearance themes (presentation only).
 * Table: erp_module_themes (company_id + module_key + theme_json).
 * Construction is the first consumer (module_key = construction).
 */

if (!defined('ERP_THEME_MODULE_CONSTRUCTION')) {
    define('ERP_THEME_MODULE_CONSTRUCTION', 'construction');
}

/** Accessible foreground for a solid background (WCAG-oriented). */
function erp_theme_contrasting_text(string $bgHex): string {
    $dark = '#0f172a';
    $light = '#ffffff';
    return erp_theme_contrast_ratio($dark, $bgHex) >= erp_theme_contrast_ratio($light, $bgHex)
        ? $dark
        : $light;
}

function erp_theme_is_light_surface(string $hex): bool {
    return erp_theme_luminance($hex) >= 0.55;
}

/** @return array<string,string> */
function erp_theme_defaults(string $moduleKey = ERP_THEME_MODULE_CONSTRUCTION): array {
    // Recommended Construction default: Cloud Blue (light, long-session friendly)
    $construction = [
        'primary' => '#2563eb',
        'primary_hover' => '#1d4ed8',
        'secondary' => '#64748b',
        'accent' => '#f59e0b',
        'button_primary' => '#2563eb',
        'sidebar_bg' => '#f8fafc',
        'sidebar_text' => '#334155',
        'sidebar_active' => '#dbeafe',
        'page_bg' => '#f1f5f9',
        'card_bg' => '#ffffff',
        'heading' => '#0f172a',
        'body_text' => '#334155',
        'muted_text' => '#64748b',
        'border' => '#dce3ec',
        'success' => '#16a34a',
        'warning' => '#d97706',
        'danger' => '#dc2626',
        'info' => '#0284c7',
        'link' => '#2563eb',
        'chart_1' => '#2563eb',
        'chart_2' => '#10b981',
        'chart_3' => '#f59e0b',
        'chart_4' => '#8b5cf6',
        'chart_5' => '#ec4899',
        'border_radius' => '10',
        'shadow_intensity' => 'low',
    ];
    if ($moduleKey === ERP_THEME_MODULE_CONSTRUCTION || $moduleKey === 'construction') {
        return $construction;
    }
    return $construction;
}

function erp_theme_table_ready(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $conn->query('SELECT 1 FROM erp_module_themes LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/** Normalize #RGB / #RRGGBB; return null if invalid. */
function erp_theme_normalize_hex(?string $value): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if ($value[0] !== '#') {
        $value = '#' . $value;
    }
    if (preg_match('/^#([0-9a-fA-F]{3})$/', $value, $m)) {
        $h = $m[1];
        return '#' . strtolower($h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]);
    }
    if (preg_match('/^#([0-9a-fA-F]{6})$/', $value)) {
        return strtolower($value);
    }
    return null;
}

/** Relative luminance 0–1 (sRGB). */
function erp_theme_luminance(string $hex): float {
    $hex = ltrim(erp_theme_normalize_hex($hex) ?? '#000000', '#');
    $r = hexdec(substr($hex, 0, 2)) / 255;
    $g = hexdec(substr($hex, 2, 2)) / 255;
    $b = hexdec(substr($hex, 4, 2)) / 255;
    $conv = static function (float $c): float {
        return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * $conv($r) + 0.7152 * $conv($g) + 0.0722 * $conv($b);
}

function erp_theme_contrast_ratio(string $hexA, string $hexB): float {
    $l1 = erp_theme_luminance($hexA);
    $l2 = erp_theme_luminance($hexB);
    $hi = max($l1, $l2);
    $lo = min($l1, $l2);
    return ($hi + 0.05) / ($lo + 0.05);
}

/**
 * Validate and sanitize theme payload against defaults.
 * @param array $payload
 * @return array{ok:bool,theme:array,errors:list<string>}
 */
function erp_theme_validate(array $payload, string $moduleKey = ERP_THEME_MODULE_CONSTRUCTION): array {
    $defaults = erp_theme_defaults($moduleKey);
    $errors = [];
    $theme = $defaults;
    $colorKeys = [
        'primary', 'primary_hover', 'secondary', 'accent', 'button_primary',
        'sidebar_bg', 'sidebar_text', 'sidebar_active',
        'page_bg', 'card_bg', 'heading', 'body_text', 'muted_text', 'border',
        'success', 'warning', 'danger', 'info', 'link',
        'chart_1', 'chart_2', 'chart_3', 'chart_4', 'chart_5',
    ];
    foreach ($colorKeys as $key) {
        if (!array_key_exists($key, $payload)) {
            continue;
        }
        $hex = erp_theme_normalize_hex((string)$payload[$key]);
        if ($hex === null) {
            $errors[] = ucfirst(str_replace('_', ' ', $key)) . ' must be a valid hex color.';
            continue;
        }
        $theme[$key] = $hex;
    }

    $radius = isset($payload['border_radius']) ? (string)$payload['border_radius'] : $defaults['border_radius'];
    if (!in_array($radius, ['8', '10', '12', '16'], true)) {
        $errors[] = 'Border radius must be 8, 10, 12, or 16.';
    } else {
        $theme['border_radius'] = $radius;
    }

    $shadow = isset($payload['shadow_intensity']) ? strtolower((string)$payload['shadow_intensity']) : $defaults['shadow_intensity'];
    if ($shadow === 'soft') {
        $shadow = 'low';
    }
    if (!in_array($shadow, ['low', 'medium', 'high'], true)) {
        $errors[] = 'Shadow intensity must be soft/low, medium, or high.';
    } else {
        $theme['shadow_intensity'] = $shadow;
    }

    $isLight = erp_theme_is_light_surface($theme['page_bg']);

    // Contrast checks (soft fail → revert pair to defaults)
    $pairs = [
        ['body_text', 'page_bg', 'Body text vs page background'],
        ['heading', 'card_bg', 'Heading vs card background'],
        ['sidebar_text', 'sidebar_bg', 'Sidebar text vs sidebar background'],
    ];
    // Dark themes: sidebar_active is text color. Light themes: tinted active background.
    if (!$isLight) {
        $pairs[] = ['sidebar_active', 'sidebar_bg', 'Sidebar active vs sidebar background'];
    } else {
        $activeFg = erp_theme_contrasting_text($theme['sidebar_active']);
        $activeRatio = erp_theme_contrast_ratio($activeFg, $theme['sidebar_active']);
        if ($activeRatio < 3.0) {
            $errors[] = 'Sidebar active background contrast is too low (ratio ' . round($activeRatio, 2) . '). Adjust colors or Reset.';
            $theme['sidebar_active'] = $defaults['sidebar_active'];
        }
    }
    foreach ($pairs as [$fg, $bg, $label]) {
        $ratio = erp_theme_contrast_ratio($theme[$fg], $theme[$bg]);
        if ($ratio < 3.0) {
            $errors[] = $label . ' contrast is too low (ratio ' . round($ratio, 2) . '). Adjust colors or Reset.';
            $theme[$fg] = $defaults[$fg];
            $theme[$bg] = $defaults[$bg];
        }
    }
    $btnFg = erp_theme_contrasting_text($theme['button_primary']);
    $btnRatio = erp_theme_contrast_ratio($btnFg, $theme['button_primary']);
    if ($btnRatio < 2.5) {
        $errors[] = 'Primary button color may be hard to read.';
        $theme['button_primary'] = $defaults['button_primary'];
    }

    return [
        'ok' => $errors === [],
        'theme' => $theme,
        'errors' => $errors,
    ];
}

/**
 * @return array<string,string>
 */
function erp_theme_get(PDO $conn, int $companyId, string $moduleKey = ERP_THEME_MODULE_CONSTRUCTION): array {
    $defaults = erp_theme_defaults($moduleKey);
    if ($companyId <= 0 || !erp_theme_table_ready($conn)) {
        return $defaults;
    }
    static $cache = [];
    $ck = $companyId . ':' . $moduleKey;
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    try {
        $stmt = $conn->prepare('SELECT theme_json FROM erp_module_themes WHERE company_id = ? AND module_key = ? LIMIT 1');
        $stmt->execute([$companyId, $moduleKey]);
        $raw = $stmt->fetchColumn();
        if ($raw) {
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded)) {
                $validated = erp_theme_validate($decoded, $moduleKey);
                $cache[$ck] = array_merge($defaults, $validated['theme']);
                return $cache[$ck];
            }
        }
    } catch (Throwable $e) {
        // fall through to defaults
    }
    $cache[$ck] = $defaults;
    return $defaults;
}

function erp_theme_save(PDO $conn, int $companyId, string $moduleKey, array $payload, ?int $userId): array {
    if ($companyId <= 0) {
        return ['ok' => false, 'errors' => ['Company context is required.'], 'theme' => erp_theme_defaults($moduleKey)];
    }
    if (!erp_theme_table_ready($conn)) {
        return ['ok' => false, 'errors' => ['Run migrations/erp_module_themes.sql first.'], 'theme' => erp_theme_defaults($moduleKey)];
    }
    $validated = erp_theme_validate($payload, $moduleKey);
    // Allow save with soft contrast fixes if only contrast warnings caused ok=false
    // but still require valid hex — if hard hex errors, reject
    $hard = [];
    foreach ($validated['errors'] as $e) {
        if (stripos($e, 'contrast') === false && stripos($e, 'hard to read') === false) {
            $hard[] = $e;
        }
    }
    if ($hard) {
        return ['ok' => false, 'errors' => $hard, 'theme' => $validated['theme']];
    }
    $json = json_encode($validated['theme'], JSON_UNESCAPED_UNICODE);
    $conn->prepare('
        INSERT INTO erp_module_themes (company_id, module_key, theme_json, updated_by)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE theme_json = VALUES(theme_json), updated_by = VALUES(updated_by)
    ')->execute([$companyId, $moduleKey, $json, $userId]);
    return ['ok' => true, 'errors' => $validated['errors'], 'theme' => $validated['theme']];
}

function erp_theme_reset(PDO $conn, int $companyId, string $moduleKey): bool {
    if ($companyId <= 0 || !erp_theme_table_ready($conn)) {
        return false;
    }
    $conn->prepare('DELETE FROM erp_module_themes WHERE company_id = ? AND module_key = ?')
        ->execute([$companyId, $moduleKey]);
    return true;
}

/**
 * Emit CSS custom properties for Construction design system.
 * Maps stored theme keys → --construction-*, --co-*, and --erp-* aliases.
 */
function erp_theme_css_variables(array $theme): string {
    $radius = (int)($theme['border_radius'] ?? 10);
    $pageBg = $theme['page_bg'] ?? '#f1f5f9';
    $cardBg = $theme['card_bg'] ?? '#ffffff';
    $sidebarBg = $theme['sidebar_bg'] ?? '#f8fafc';
    $border = $theme['border'] ?? '#dce3ec';
    $primary = $theme['primary'] ?? '#2563eb';
    $buttonPrimary = $theme['button_primary'] ?? $primary;
    $isLight = erp_theme_is_light_surface($pageBg);
    $buttonText = erp_theme_contrasting_text($buttonPrimary);
    $shadowKey = $theme['shadow_intensity'] ?? 'low';
    if ($shadowKey === 'soft') {
        $shadowKey = 'low';
    }
    $shadowMap = [
        'low' => $isLight ? '0 4px 14px rgba(15,23,42,0.08)' : '0 4px 12px rgba(0,0,0,0.25)',
        'medium' => $isLight ? '0 8px 24px rgba(15,23,42,0.10)' : '0 8px 24px rgba(0,0,0,0.35)',
        'high' => $isLight ? '0 16px 40px rgba(15,23,42,0.14)' : '0 16px 40px rgba(0,0,0,0.5)',
    ];
    $shadow = $shadowMap[$shadowKey] ?? $shadowMap['low'];
    $elevated = $isLight ? $cardBg : $sidebarBg;
    $inputBg = $isLight ? '#ffffff' : $elevated;
    $cardHover = $isLight ? '#f8fafc' : '#1a2438';
    $glowA = $isLight ? '#e2e8f0' : '#1a2744';
    $glowB = $isLight ? '#fef3c7' : '#1c1830';
    $coBorder = $isLight ? $border : 'color-mix(in srgb, ' . $border . ' 22%, transparent)';
    $coBorderStrong = $isLight
        ? 'color-mix(in srgb, ' . $border . ' 85%, #64748b)'
        : 'color-mix(in srgb, ' . $border . ' 40%, transparent)';
    $tableHover = $isLight
        ? 'color-mix(in srgb, ' . $primary . ' 06%, ' . $cardBg . ')'
        : 'rgba(255, 255, 255, 0.04)';
    $tableHead = $isLight
        ? 'color-mix(in srgb, ' . $primary . ' 08%, ' . $cardBg . ')'
        : 'color-mix(in srgb, ' . $primary . ' 12%, transparent)';

    $heading = $theme['heading'] ?? '#0f172a';
    $body = $theme['body_text'] ?? '#334155';
    $muted = $theme['muted_text'] ?? '#64748b';
    $sidebarText = $theme['sidebar_text'] ?? '#334155';
    $sidebarActive = $theme['sidebar_active'] ?? '#dbeafe';
    $sidebarActiveText = $isLight ? $heading : $sidebarActive;

    $lines = [
        '--construction-primary: ' . $primary,
        '--construction-primary-hover: ' . ($theme['primary_hover'] ?? '#1d4ed8'),
        '--construction-secondary: ' . ($theme['secondary'] ?? '#64748b'),
        '--construction-accent: ' . ($theme['accent'] ?? '#f59e0b'),
        '--construction-sidebar-bg: ' . $sidebarBg,
        '--construction-sidebar-text: ' . $sidebarText,
        '--construction-sidebar-active: ' . $sidebarActive,
        '--construction-sidebar-active-text: ' . $sidebarActiveText,
        '--construction-page-bg: ' . $pageBg,
        '--construction-card-bg: ' . $cardBg,
        '--construction-heading-color: ' . $heading,
        '--construction-body-text: ' . $body,
        '--construction-muted-text: ' . $muted,
        '--construction-border-color: ' . $border,
        '--construction-success: ' . ($theme['success'] ?? '#16a34a'),
        '--construction-warning: ' . ($theme['warning'] ?? '#d97706'),
        '--construction-danger: ' . ($theme['danger'] ?? '#dc2626'),
        '--construction-info: ' . ($theme['info'] ?? '#0284c7'),
        '--construction-link: ' . ($theme['link'] ?? $primary),
        '--construction-shadow: ' . $shadow,
        '--construction-border-radius: ' . $radius . 'px',
        '--construction-button-primary: ' . $buttonPrimary,
        '--construction-button-text: ' . $buttonText,
        '--construction-chart-1: ' . ($theme['chart_1'] ?? $primary),
        '--construction-chart-2: ' . ($theme['chart_2'] ?? '#10b981'),
        '--construction-chart-3: ' . ($theme['chart_3'] ?? '#f59e0b'),
        '--construction-chart-4: ' . ($theme['chart_4'] ?? '#8b5cf6'),
        '--construction-chart-5: ' . ($theme['chart_5'] ?? '#ec4899'),
        '--construction-theme-mode: ' . ($isLight ? 'light' : 'dark'),
        '--erp-primary: var(--construction-primary)',
        '--erp-primary-hover: var(--construction-primary-hover)',
        '--erp-secondary: var(--construction-secondary)',
        '--erp-accent: var(--construction-accent)',
        '--erp-page-bg: var(--construction-page-bg)',
        '--erp-card-bg: var(--construction-card-bg)',
        '--erp-sidebar-bg: var(--construction-sidebar-bg)',
        '--erp-sidebar-text: var(--construction-sidebar-text)',
        '--erp-sidebar-active: var(--construction-sidebar-active)',
        '--erp-heading: var(--construction-heading-color)',
        '--erp-body-text: var(--construction-body-text)',
        '--erp-muted-text: var(--construction-muted-text)',
        '--erp-border: var(--construction-border-color)',
        '--erp-success: var(--construction-success)',
        '--erp-warning: var(--construction-warning)',
        '--erp-danger: var(--construction-danger)',
        '--erp-info: var(--construction-info)',
        '--erp-link: var(--construction-link)',
        '--erp-chart-1: var(--construction-chart-1)',
        '--erp-chart-2: var(--construction-chart-2)',
        '--erp-chart-3: var(--construction-chart-3)',
        '--erp-chart-4: var(--construction-chart-4)',
        '--erp-chart-5: var(--construction-chart-5)',
        '--co-bg: var(--construction-page-bg)',
        '--co-bg-card: var(--construction-card-bg)',
        '--co-bg-elevated: ' . $elevated,
        '--co-input-bg: ' . $inputBg,
        '--co-bg-card-hover: ' . $cardHover,
        '--co-page-glow-a: ' . $glowA,
        '--co-page-glow-b: ' . $glowB,
        '--co-border: ' . $coBorder,
        '--co-border-strong: ' . $coBorderStrong,
        '--co-table-hover: ' . $tableHover,
        '--co-table-head: ' . $tableHead,
        '--co-text: var(--construction-body-text)',
        '--co-text-muted: var(--construction-muted-text)',
        '--co-text-dim: var(--construction-muted-text)',
        '--co-gold: var(--construction-primary)',
        '--co-gold-soft: var(--construction-primary-hover)',
        '--co-teal: var(--construction-success)',
        '--co-danger: var(--construction-danger)',
        '--co-warn: var(--construction-warning)',
        '--co-info: var(--construction-info)',
        '--co-radius: var(--construction-border-radius)',
        '--co-shadow: var(--construction-shadow)',
        '--primary: var(--construction-primary)',
        '--primary-light: var(--construction-primary-hover)',
        '--accent: var(--construction-accent)',
        '--success: var(--construction-success)',
        '--warning: var(--construction-warning)',
        '--danger: var(--construction-danger)',
        '--info: var(--construction-info)',
    ];
    return implode(';', $lines) . ';';
}
