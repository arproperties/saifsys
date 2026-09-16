<?php
/**
 * ARS Application Shell (Phase 3B Wave 1)
 *
 * Permanent staff layout framework. Presentational + navigation only.
 * No financial logic. Stay portal must not use this file.
 *
 * Usage (opt-in):
 *   require_once __DIR__ . '/ars_shell.php';
 *   ars_shell_begin([
 *     'title' => 'Page title',
 *     'breadcrumbs' => [['label'=>'ARS'], ['label'=>'Page']],
 *     'actions_html' => '',
 *     'legacy_bootstrap' => false, // true when page body still uses Bootstrap markup
 *   ]);
 *   // page body
 *   ars_shell_end();
 */

require_once __DIR__ . '/ars_ui.php';

if (!function_exists('ars_shell_base_href')) {
    /** Prefix for links when script lives under tools/. */
    function ars_shell_base_href(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        return (strpos($script, '/modules/ars/tools/') !== false) ? '../' : '';
    }
}

if (!function_exists('ars_shell_href')) {
    function ars_shell_href(string $page): string
    {
        if ($page === '' || $page === '#') {
            return '#';
        }
        if (isset($page[0]) && $page[0] === '/') {
            return $page;
        }
        if (strpos($page, 'http://') === 0 || strpos($page, 'https://') === 0) {
            return $page;
        }
        return ars_shell_base_href() . ltrim($page, '/');
    }
}

if (!function_exists('ars_shell_app_base')) {
    function ars_shell_app_base(): string
    {
        return (strpos($_SERVER['PHP_SELF'] ?? '', '/herosysgro') !== false) ? '/herosysgro' : '';
    }
}

/**
 * Build permission-aware navigation tree (approved IA).
 * Empty accounting/ stub is intentionally omitted (retired from shell).
 *
 * @return list<array<string,mixed>>
 */
if (!function_exists('ars_shell_nav_items')) {
    function ars_shell_nav_items(PDO $conn): array
    {
        if (!function_exists('has_department_access')) {
            require_once dirname(__DIR__, 3) . '/includes/rbac_department.php';
        }
        $hasCore = has_department_access(MODULE_ARS, DEPT_ARS_CORE, $conn);
        $hasOps = has_department_access(MODULE_ARS, DEPT_ARS_OPERATIONS, $conn);
        $current = basename($_SERVER['PHP_SELF'] ?? '');

        $is = static function (array $pages) use ($current): bool {
            return in_array($current, $pages, true);
        };

        $items = [];

        if ($hasCore || $hasOps) {
            $items[] = [
                'id' => 'command-center',
                'label' => 'Command Center',
                'icon' => 'layout-dashboard',
                'href' => 'index.php',
                'active' => $is(['index.php']),
                'visible' => true,
            ];
        }

        if ($hasCore) {
            $items[] = [
                'id' => 'reservations',
                'label' => 'Reservations',
                'icon' => 'calendar-check',
                'href' => 'bookings.php',
                'active' => $is(['bookings.php', 'booking_add.php', 'booking_view.php']),
                'visible' => true,
            ];
            $items[] = [
                'id' => 'calendar',
                'label' => 'Calendar',
                'icon' => 'calendar-days',
                'href' => 'calendar.php',
                'active' => $is(['calendar.php']),
                'visible' => true,
            ];
            $items[] = [
                'id' => 'guests',
                'label' => 'Guests',
                'icon' => 'users',
                'href' => 'guests.php',
                'active' => $is(['guests.php', 'guest_view.php']),
                'visible' => true,
            ];
            $items[] = [
                'id' => 'units',
                'label' => 'Properties & Units',
                'icon' => 'building-2',
                'href' => 'units.php',
                'active' => $is(['units.php', 'unit_edit.php', 'unit_profile.php', 'unit_history_search.php']),
                'visible' => true,
            ];
        }

        if ($hasOps) {
            $items[] = [
                'id' => 'operations',
                'label' => 'Operations',
                'icon' => 'clipboard-list',
                'href' => null,
                'active' => $is(['housekeeping.php', 'maintenance.php', 'blocked_dates.php']),
                'visible' => true,
                'children' => [
                    [
                        'id' => 'housekeeping',
                        'label' => 'Housekeeping',
                        'icon' => 'sparkles',
                        'href' => 'housekeeping.php',
                        'active' => $is(['housekeeping.php']),
                    ],
                    [
                        'id' => 'maintenance',
                        'label' => 'Maintenance',
                        'icon' => 'wrench',
                        'href' => 'maintenance.php',
                        'active' => $is(['maintenance.php']),
                    ],
                    [
                        'id' => 'blocked',
                        'label' => 'Blocked Dates',
                        'icon' => 'calendar-x',
                        'href' => 'blocked_dates.php',
                        'active' => $is(['blocked_dates.php']),
                    ],
                ],
            ];
        }

        if ($hasCore) {
            $items[] = [
                'id' => 'finance',
                'label' => 'Finance',
                'icon' => 'wallet',
                'href' => 'revenue.php',
                'active' => $is([
                    'revenue.php',
                    'expenses.php',
                    'expense_add.php',
                    'expense_edit.php',
                    'chart_of_accounts.php',
                ]),
                'visible' => true,
                'children' => [
                    [
                        'id' => 'fin-revenue',
                        'label' => 'Revenue',
                        'icon' => 'trending-up',
                        'href' => 'revenue.php',
                        'active' => $is(['revenue.php']),
                    ],
                    [
                        'id' => 'fin-expenses',
                        'label' => 'Expenses',
                        'icon' => 'receipt',
                        'href' => 'expenses.php',
                        'active' => $is(['expenses.php', 'expense_add.php', 'expense_edit.php']),
                    ],
                    [
                        'id' => 'fin-coa',
                        'label' => 'Chart of Accounts',
                        'icon' => 'list',
                        'href' => 'chart_of_accounts.php',
                        'active' => $is(['chart_of_accounts.php']),
                    ],
                ],
            ];

            $items[] = [
                'id' => 'activity',
                'label' => 'Activity Center',
                'icon' => 'activity',
                'href' => 'activity_center.php',
                'active' => $is(['activity_center.php']),
                'visible' => true,
            ];

            $items[] = [
                'id' => 'reports',
                'label' => 'Reports',
                'icon' => 'pie-chart',
                'href' => 'reports.php',
                'active' => $is([
                    'reports.php',
                    'financial_reports.php',
                    'financial_document_view.php',
                ]),
                'visible' => true,
                'badge' => 'Hub',
            ];

            $items[] = [
                'id' => 'settings',
                'label' => 'Settings',
                'icon' => 'settings',
                'href' => 'settings.php',
                'active' => $is(['settings.php', 'pricing.php', 'document_branding.php', 'airbnb_sync.php']),
                'visible' => true,
                'children' => [
                    [
                        'id' => 'set-main',
                        'label' => 'Company Settings',
                        'icon' => 'settings',
                        'href' => 'settings.php',
                        'active' => $is(['settings.php']),
                    ],
                    [
                        'id' => 'set-branding',
                        'label' => 'Document Branding',
                        'icon' => 'file-text',
                        'href' => 'document_branding.php',
                        'active' => $is(['document_branding.php']),
                    ],
                    [
                        'id' => 'set-pricing',
                        'label' => 'Pricing',
                        'icon' => 'tags',
                        'href' => 'pricing.php',
                        'active' => $is(['pricing.php']),
                    ],
                    [
                        'id' => 'set-airbnb',
                        'label' => 'Airbnb Sync',
                        'icon' => 'refresh-cw',
                        'href' => 'airbnb_sync.php',
                        'active' => $is(['airbnb_sync.php']),
                    ],
                ],
            ];
        }

        // Filter invisible
        return array_values(array_filter($items, static fn($i) => !empty($i['visible'])));
    }
}

if (!function_exists('ars_shell_render_nav_link')) {
    function ars_shell_render_nav_link(array $item, bool $child = false): string
    {
        $base = 'ars-shell-nav-link group flex items-center gap-2.5 rounded-ars-md px-3 py-2 text-ars-sm min-h-ars-touch transition-colors duration-ars-base no-underline'
            . ($child ? ' ars-shell-nav-link--child' : '');
        $icon = '<span class="ars-shell-nav-ico">' . ars_ui_icon($item['icon'] ?? 'circle', ['class' => 'h-4 w-4']) . '</span>';

        if (!empty($item['disabled'])) {
            $reason = ars_ui_h($item['disabled_reason'] ?? 'Coming soon');
            return '<span class="' . $base . ' cursor-not-allowed opacity-40" title="' . $reason . '" aria-disabled="true">'
                . $icon
                . '<span class="truncate ars-shell-nav-label">' . ars_ui_h($item['label']) . '</span>'
                . '<span class="ml-auto text-[10px] uppercase tracking-wide opacity-50">Soon</span>'
                . '</span>';
        }

        $active = !empty($item['active']);
        $cls = $base . ($active ? ' is-active' : '');
        $aria = $active ? ' aria-current="page"' : '';
        $href = ars_shell_href((string)($item['href'] ?? '#'));
        $badge = '';
        if (!empty($item['badge'])) {
            $badge = '<span class="ml-auto rounded-full bg-ars-bg px-1.5 py-0.5 text-[10px] text-ars-muted">'
                . ars_ui_h($item['badge']) . '</span>';
        }

        return '<a href="' . ars_ui_h($href) . '" class="' . $cls . '"' . $aria . '>'
            . $icon
            . '<span class="truncate ars-shell-nav-label">' . ars_ui_h($item['label']) . '</span>'
            . $badge
            . '</a>';
    }
}

if (!function_exists('ars_shell_begin')) {
    /**
     * @param array{
     *   title?:string,
     *   subtitle?:string,
     *   breadcrumbs?:list<array{label:string,href?:string}>,
     *   actions_html?:string,
     *   hide_page_header?:bool,
     *   legacy_bootstrap?:bool,
     *   show_mobile_nav?:bool,
     *   toolbar_html?:string
     * } $opts
     */
    function ars_shell_begin(array $opts = []): void
    {
        global $conn, $brand, $pageTitle, $pageHead, $pageScripts;

        if (!isset($brand) && isset($conn) && $conn instanceof PDO) {
            if (!function_exists('getBrandSettings')) {
                require_once dirname(__DIR__, 3) . '/includes/branding.php';
            }
            $brand = getBrandSettings($conn);
        }
        $brand = $brand ?? ['system_name' => 'HeroSysgro', 'dark_mode_enabled' => 0];

        if (!isset($conn) || !($conn instanceof PDO)) {
            throw new RuntimeException('ars_shell_begin requires $conn PDO');
        }

        $title = $opts['title'] ?? ($pageTitle ?? 'ARS');
        $subtitle = $opts['subtitle'] ?? '';
        $breadcrumbs = $opts['breadcrumbs'] ?? [
            ['label' => 'ARS', 'href' => ars_shell_href('index.php')],
            ['label' => $title],
        ];
        $actionsHtml = $opts['actions_html'] ?? '';
        $toolbarHtml = $opts['toolbar_html'] ?? '';
        $legacyBootstrap = !empty($opts['legacy_bootstrap']);
        $showMobileNav = array_key_exists('show_mobile_nav', $opts) ? (bool)$opts['show_mobile_nav'] : true;

        $hasUserObject = !empty($_SESSION['user']) && is_array($_SESSION['user']);
        $U = $hasUserObject ? $_SESSION['user'] : [];
        $fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
        $userName = $U['username'] ?? ($_SESSION['username'] ?? '');
        $avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));

        $navItems = ars_shell_nav_items($conn);
        $appBase = ars_shell_app_base();

        if (!function_exists('tasks_nav_user_can_access')) {
            require_once dirname(__DIR__, 3) . '/includes/tasks_nav_helper.php';
        }
        $showTasks = tasks_nav_user_can_access($conn);

        if (!function_exists('get_user_companies')) {
            require_once dirname(__DIR__, 3) . '/includes/company_helper.php';
        }
        $userId = function_exists('current_user_id') ? current_user_id() : null;
        $userCompanies = ($userId && function_exists('get_user_companies')) ? get_user_companies($conn, $userId) : [];
        $showSwitch = count($userCompanies) > 1;

        $csrf = function_exists('csrf_token') ? csrf_token() : '';

        echo '<!DOCTYPE html>' . "\n";
        echo '<html lang="en">' . "\n";
        echo '<head>' . "\n";
        echo '<meta charset="UTF-8">' . "\n";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
        echo '<title>' . ars_ui_h($title) . ' · ARS · ' . ars_ui_h($brand['system_name'] ?? 'HeroSysgro') . '</title>' . "\n";
        if ($legacyBootstrap) {
            echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n";
            echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">' . "\n";
            $arsStyles = dirname(__DIR__) . '/assets/ars_styles.css';
            $arsStylesV = is_file($arsStyles) ? filemtime($arsStyles) : '0';
            echo '<link href="' . ars_ui_h(ars_shell_base_href() . 'assets/ars_styles.css') . '?v=' . ars_ui_h((string)$arsStylesV) . '" rel="stylesheet">' . "\n";
        }
        // Alpine/Lucide via ars_ui_assets; shell JS also echoed at end of body for reliability
        ars_ui_assets();
        echo '<meta name="ars-csrf" content="' . ars_ui_h($csrf) . '">' . "\n";
        echo '<script>window.ARS_CSRF = ' . json_encode($csrf) . ';</script>' . "\n";
        if (!empty($pageHead)) {
            echo $pageHead;
        }
        echo '</head>' . "\n";

        $bodyClass = !empty($brand['dark_mode_enabled']) ? ' class="dark-mode ars-shell-body"' : ' class="ars-shell-body"';
        echo '<body' . $bodyClass . '>' . "\n";

        echo ars_ui_app_open(['class' => ' ars-shell min-h-screen']);
        echo ars_ui_toast_region();

        echo '<a href="#ars-shell-main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[1300] focus:rounded-ars-md focus:bg-ars-surface focus:px-3 focus:py-2 focus:text-ars-sm focus:shadow-ars-md">Skip to content</a>' . "\n";

        echo '<div class="ars-shell-frame flex min-h-screen">' . "\n";

        // Backdrop (mobile)
        echo '<div class="ars-shell-backdrop" data-ars-mobile-backdrop hidden aria-hidden="true"></div>' . "\n";

        // Sidebar — light HR-style surface
        echo '<aside id="ars-shell-sidebar" class="ars-shell-sidebar" role="navigation" aria-label="ARS primary">' . "\n";

        // Brand
        echo '<div class="ars-shell-brand">' . "\n";
        $logoSrc = function_exists('brand_logo_src') ? brand_logo_src($brand) : '';
        if ($logoSrc) {
            echo '<img src="' . ars_ui_h($logoSrc) . '" alt="" class="ars-shell-brand-logo">' . "\n";
        } else {
            echo '<span class="ars-shell-brand-mark">' . ars_ui_icon('building-2', ['class' => 'h-4 w-4']) . '</span>' . "\n";
        }
        echo '<div class="ars-shell-brand-text min-w-0 flex-1">' . "\n";
        echo '<div class="truncate text-ars-sm font-bold tracking-tight text-ars-text">ARS Rentals</div>' . "\n";
        echo '<div class="truncate text-[11px] text-ars-muted">Holiday Homes</div>' . "\n";
        echo '</div>' . "\n";
        echo '<button type="button" class="ars-shell-icon-ghost hidden lg:inline-flex" data-ars-collapse-btn aria-expanded="true" aria-controls="ars-shell-sidebar" aria-label="Collapse sidebar">' . "\n";
        echo ars_ui_icon('panel-left-close', ['class' => 'h-4 w-4 ars-shell-collapse-icon-open']);
        echo ars_ui_icon('panel-left-open', ['class' => 'h-4 w-4 ars-shell-collapse-icon-closed']);
        echo '</button>' . "\n";
        echo '<button type="button" class="ars-shell-icon-ghost lg:hidden" data-ars-mobile-close aria-label="Close menu">' . ars_ui_icon('x', ['class' => 'h-5 w-5']) . '</button>' . "\n";
        echo '</div>' . "\n";

        require_once dirname(__DIR__, 3) . '/includes/nav_search.php';
        echo nav_search_box() . "\n";

        // Nav list
        echo '<nav class="ars-shell-nav flex-1 overflow-y-auto" aria-label="Module">' . "\n";
        echo '<ul class="m-0 flex list-none flex-col gap-0.5 p-0">' . "\n";
        foreach ($navItems as $item) {
            $id = $item['id'] ?? '';
            $hasChildren = !empty($item['children']);
            echo '<li>' . "\n";
            if ($hasChildren) {
                $open = !empty($item['active']);
                echo '<details class="ars-shell-nav-group"' . ($open ? ' open' : '') . '>' . "\n";
                echo '<summary class="ars-shell-nav-summary">' . "\n";
                echo '<span class="ars-shell-nav-section-label">' . ars_ui_h($item['label']) . '</span>' . "\n";
                echo '<span class="ars-shell-nav-chevron">' . ars_ui_icon('chevron-down', ['class' => 'h-3.5 w-3.5']) . '</span>' . "\n";
                echo '</summary>' . "\n";
                echo '<ul class="m-0 list-none space-y-0.5 p-0 pb-1" id="ars-nav-' . ars_ui_h($id) . '">' . "\n";
                foreach ($item['children'] as $child) {
                    echo '<li>' . ars_shell_render_nav_link($child, true) . '</li>' . "\n";
                }
                echo '</ul>' . "\n";
                echo '</details>' . "\n";
                // Collapsed rail: first child shortcut
                $firstChild = $item['children'][0] ?? null;
                if ($firstChild && !empty($firstChild['href'])) {
                    echo '<div class="ars-shell-nav-collapsed-only">' . ars_shell_render_nav_link([
                        'label' => $item['label'],
                        'icon' => $item['icon'] ?? ($firstChild['icon'] ?? 'circle'),
                        'href' => $firstChild['href'],
                        'active' => !empty($item['active']),
                    ]) . '</div>' . "\n";
                }
            } else {
                echo ars_shell_render_nav_link($item) . "\n";
            }
            echo '</li>' . "\n";
        }

        if ($showTasks) {
            echo '<li class="mt-2 border-t border-ars-border pt-2">' . "\n";
            echo ars_shell_render_nav_link([
                'label' => 'Tasks',
                'icon' => 'check-square',
                'href' => $appBase . '/modules/tasks/tasks.php',
                'active' => false,
            ]);
            echo '</li>' . "\n";
        }
        if ($showSwitch) {
            echo '<li>' . "\n";
            echo ars_shell_render_nav_link([
                'label' => 'Switch Module',
                'icon' => 'arrow-left-right',
                'href' => $appBase . '/select-module',
                'active' => false,
            ]);
            echo '</li>' . "\n";
        }

        echo '</ul></nav>' . "\n";
        echo '<div class="ars-shell-sidebar-footer">ARS · Staff</div>' . "\n";
        echo '</aside>' . "\n";

        // Main column
        echo '<div class="ars-shell-maincol flex min-w-0 flex-1 flex-col bg-ars-bg">' . "\n";

        // Top header — search is always-visible; menus use fixed panels
        echo '<header class="ars-shell-topbar" role="banner">' . "\n";
        echo '<button type="button" class="ars-shell-icon-btn lg:hidden" data-ars-mobile-open aria-controls="ars-shell-sidebar" aria-label="Open menu">' . "\n";
        echo ars_ui_icon('menu', ['class' => 'h-5 w-5']);
        echo '</button>' . "\n";

        echo '<div class="ars-shell-crumbs hidden min-w-0 sm:block">' . "\n";
        echo ars_ui_breadcrumbs($breadcrumbs);
        echo '</div>' . "\n";
        echo '<div class="ars-shell-mobile-title min-w-0 flex-1 truncate text-ars-sm font-semibold text-ars-text md:hidden">' . ars_ui_h($title) . '</div>' . "\n";

        // Always-visible search form (desktop)
        $searchAction = ars_ui_h(ars_shell_href('bookings.php'));
        echo '<form class="ars-shell-search" method="get" action="' . $searchAction . '" role="search">' . "\n";
        echo '<span class="ars-shell-search-ico" aria-hidden="true">' . ars_ui_icon('search', ['class' => 'h-4 w-4']) . '</span>' . "\n";
        echo '<input id="ars-shell-search-input" name="q" type="search" class="ars-shell-search-input" placeholder="Search reservations, guests, units…" autocomplete="off" enterkeyhint="search">' . "\n";
        echo '<kbd class="ars-shell-search-kbd" aria-hidden="true">⌘K</kbd>' . "\n";
        echo '</form>' . "\n";

        echo '<div class="ars-shell-top-actions">' . "\n";

        // Notifications
        echo '<button type="button" class="ars-shell-icon-btn" id="ars-notify-btn" aria-label="Notifications" aria-expanded="false" aria-haspopup="dialog" aria-controls="ars-notify-panel">' . "\n";
        echo ars_ui_icon('bell', ['class' => 'h-4 w-4']);
        echo '</button>' . "\n";

        // User menu — horizontal pill (HR-style)
        echo '<button type="button" class="ars-shell-user-btn" id="ars-user-btn" aria-expanded="false" aria-haspopup="menu" aria-controls="ars-user-panel">' . "\n";
        echo '<span class="ars-shell-user-avatar" aria-hidden="true">' . ars_ui_h($avatarInitial) . '</span>' . "\n";
        echo '<span class="ars-shell-user-meta">' . "\n";
        echo '<span class="ars-shell-user-name">' . ars_ui_h($fullName) . '</span>' . "\n";
        echo '<span class="ars-shell-user-role">ARS</span>' . "\n";
        echo '</span>' . "\n";
        echo '<svg class="ars-shell-user-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>' . "\n";
        echo '</button>' . "\n";

        echo '</div>' . "\n"; // .ars-shell-top-actions
        echo '</header>' . "\n";

        // Dropdown panels outside header (no clipping)
        echo '<div id="ars-notify-panel" class="ars-shell-dropdown" role="dialog" aria-label="Notifications" hidden>' . "\n";
        echo '<div class="ars-shell-dropdown-head">Notifications</div>' . "\n";
        echo '<div class="ars-shell-dropdown-body"><p>No new alerts.</p><p class="ars-shell-dropdown-hint">Operational alerts appear on the Command Center.</p></div>' . "\n";
        echo '<div class="ars-shell-dropdown-foot"><a href="' . ars_ui_h(ars_shell_href('index.php')) . '">Go to Command Center</a></div>' . "\n";
        echo '</div>' . "\n";

        echo '<div id="ars-user-panel" class="ars-shell-dropdown" role="menu" hidden>' . "\n";
        echo '<div class="ars-shell-dropdown-head"><strong>' . ars_ui_h($fullName) . '</strong><span>' . ars_ui_h($userName) . '</span></div>' . "\n";
        echo '<a role="menuitem" class="ars-shell-dropdown-item" href="' . ars_ui_h($appBase . '/profile') . '">Profile</a>' . "\n";
        echo '<a role="menuitem" class="ars-shell-dropdown-item" href="' . ars_ui_h(ars_shell_href('settings.php')) . '">Settings</a>' . "\n";
        echo '<div class="ars-shell-dropdown-divider"></div>' . "\n";
        echo '<a role="menuitem" class="ars-shell-dropdown-item ars-shell-dropdown-item--danger" href="' . ars_ui_h($appBase . '/logout') . '">Logout</a>' . "\n";
        echo '</div>' . "\n";

        // Inline controller — works even if external shell JS fails
        echo '<script>(function(){'
            . 'function place(btn,panel){var r=btn.getBoundingClientRect(),w=Math.min(280,window.innerWidth-16),l=Math.min(Math.max(8,r.right-w),window.innerWidth-w-8);'
            . 'panel.style.cssText="position:fixed;z-index:5000;top:"+(r.bottom+8)+"px;left:"+l+"px;width:"+w+"px;display:block";}'
            . 'function closeAll(){["ars-user-panel","ars-notify-panel"].forEach(function(id){var p=document.getElementById(id);if(p){p.hidden=true;p.style.display="none";}});'
            . '["ars-user-btn","ars-notify-btn"].forEach(function(id){var b=document.getElementById(id);if(b)b.setAttribute("aria-expanded","false");});}'
            . 'function toggle(btnId,panelId){var b=document.getElementById(btnId),p=document.getElementById(panelId);if(!b||!p)return;'
            . 'var open=b.getAttribute("aria-expanded")==="true";closeAll();if(open)return;'
            . 'p.hidden=false;place(b,p);b.setAttribute("aria-expanded","true");}'
            . 'document.addEventListener("click",function(e){'
            . 'var u=document.getElementById("ars-user-btn"),n=document.getElementById("ars-notify-btn");'
            . 'if(u&&(e.target===u||u.contains(e.target))){e.preventDefault();e.stopPropagation();toggle("ars-user-btn","ars-user-panel");return;}'
            . 'if(n&&(e.target===n||n.contains(e.target))){e.preventDefault();e.stopPropagation();toggle("ars-notify-btn","ars-notify-panel");return;}'
            . 'var up=document.getElementById("ars-user-panel"),np=document.getElementById("ars-notify-panel");'
            . 'if((up&&!up.hidden&&up.contains(e.target))||(np&&!np.hidden&&np.contains(e.target)))return;'
            . 'closeAll();},true);'
            . 'document.addEventListener("keydown",function(e){if(e.key==="Escape")closeAll();});'
            . '})();</script>' . "\n";

        // Workspace
        $hidePageHeader = !empty($opts['hide_page_header']);
        echo '<main id="ars-shell-main" class="ars-shell-workspace flex-1 px-3 py-4 sm:px-5 sm:py-6' . ($showMobileNav ? ' pb-24 lg:pb-6' : '') . '" tabindex="-1">' . "\n";
        if (!$hidePageHeader) {
            echo '<div class="mb-5 flex flex-wrap items-start justify-between gap-3">' . "\n";
            echo '<div class="min-w-0">' . "\n";
            echo '<h1 class="m-0 text-xl font-bold tracking-tight text-ars-text sm:text-ars-2xl">' . ars_ui_h($title) . '</h1>' . "\n";
            if ($subtitle !== '') {
                echo '<p class="mt-1 m-0 text-ars-sm text-ars-muted">' . ars_ui_h($subtitle) . '</p>' . "\n";
            }
            echo '</div>' . "\n";
            if ($actionsHtml !== '') {
                echo '<div class="flex flex-wrap items-center gap-2">' . $actionsHtml . '</div>' . "\n";
            }
            echo '</div>' . "\n";
        }

        if ($toolbarHtml !== '') {
            echo ars_ui_action_toolbar($toolbarHtml);
        }

        echo '<div class="ars-shell-content">' . "\n";

        // Stash for end()
        $GLOBALS['ars_shell_state'] = [
            'legacy_bootstrap' => $legacyBootstrap,
            'show_mobile_nav' => $showMobileNav,
            'pageScripts' => $pageScripts ?? '',
        ];
    }
}

if (!function_exists('ars_shell_end')) {
    function ars_shell_end(): void
    {
        $state = $GLOBALS['ars_shell_state'] ?? [
            'legacy_bootstrap' => false,
            'show_mobile_nav' => true,
            'pageScripts' => '',
        ];

        echo '</div><!-- /.ars-shell-content -->' . "\n";
        echo '</main>' . "\n";

        if (!empty($state['show_mobile_nav'])) {
            $home = ars_ui_h(ars_shell_href('index.php'));
            $cal = ars_ui_h(ars_shell_href('calendar.php'));
            $arr = ars_ui_h(ars_shell_href('bookings.php'));
            $ops = ars_ui_h(ars_shell_href('housekeeping.php'));
            echo '<nav class="ars-shell-mobile-nav fixed bottom-0 inset-x-0 z-[1040] flex border-t border-ars-border bg-ars-surface lg:hidden" aria-label="Mobile">' . "\n";
            echo '<a class="flex flex-1 flex-col items-center justify-center gap-0.5 py-2 text-[10px] text-ars-muted min-h-ars-touch" href="' . $home . '">' . ars_ui_icon('layout-dashboard', ['class' => 'h-5 w-5']) . 'Home</a>' . "\n";
            echo '<a class="flex flex-1 flex-col items-center justify-center gap-0.5 py-2 text-[10px] text-ars-muted min-h-ars-touch" href="' . $cal . '">' . ars_ui_icon('calendar-days', ['class' => 'h-5 w-5']) . 'Calendar</a>' . "\n";
            echo '<a class="flex flex-1 flex-col items-center justify-center gap-0.5 py-2 text-[10px] text-ars-muted min-h-ars-touch" href="' . $arr . '">' . ars_ui_icon('log-in', ['class' => 'h-5 w-5']) . 'Arrivals</a>' . "\n";
            echo '<a class="flex flex-1 flex-col items-center justify-center gap-0.5 py-2 text-[10px] text-ars-muted min-h-ars-touch" href="' . $ops . '">' . ars_ui_icon('clipboard-list', ['class' => 'h-5 w-5']) . 'Ops</a>' . "\n";
            echo '<button type="button" class="flex flex-1 flex-col items-center justify-center gap-0.5 py-2 text-[10px] text-ars-muted min-h-ars-touch" data-ars-mobile-open>' . ars_ui_icon('more-horizontal', ['class' => 'h-5 w-5']) . 'More</button>' . "\n";
            echo '</nav>' . "\n";
        }

        echo '</div><!-- main column -->' . "\n";
        echo '</div><!-- frame -->' . "\n";
        echo ars_ui_app_close();

        if (!empty($state['legacy_bootstrap'])) {
            echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>' . "\n";
        }
        if (!empty($state['pageScripts'])) {
            echo $state['pageScripts'];
        }
        // Shell chrome JS at end of body (after DOM) — guarantees menus work
        $shellJsV = ars_ui_asset_version('js/ars-shell.js');
        echo '<script src="' . ars_ui_h(ars_ui_asset_base() . '/js/ars-shell.js') . '?v=' . ars_ui_h($shellJsV) . '"></script>' . "\n";
        echo '</body></html>' . "\n";
        unset($GLOBALS['ars_shell_state']);
    }
}

if (!function_exists('ars_shell_page_state')) {
    /** Shared empty / loading / error blocks for shell pages. */
    function ars_shell_page_state(string $kind, string $title, string $body = '', array $opts = []): string
    {
        if ($kind === 'loading') {
            return '<div class="rounded-ars-lg border border-ars-border bg-ars-surface p-6">' . ars_ui_skeleton(['lines' => 5]) . '</div>';
        }
        if ($kind === 'error') {
            return ars_ui_error_banner($title . ($body !== '' ? ' — ' . $body : ''));
        }
        return ars_ui_empty_state($title, $body, $opts);
    }
}
