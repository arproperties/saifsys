<?php
/**
 * Administration Control Center sidebar IA.
 * Returns groups filtered by current user roles.
 *
 * @return list<array{label:string,items:list<array{tab:string,label:string,icon:string,scope?:string,roles?:list<string>}>}>
 */
function admin_nav_groups(PDO $conn): array
{
    $groups = [
        [
            'label' => 'Organization',
            'items' => [
                ['tab' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard'],
                ['tab' => 'companies', 'label' => 'Companies & Modules', 'icon' => 'building-2', 'roles' => ['Owner', 'Admin']],
                ['tab' => 'company', 'label' => 'Company Info', 'icon' => 'building', 'scope' => 'Company'],
                ['tab' => 'branding', 'label' => 'Branding', 'icon' => 'palette', 'scope' => 'Global'],
                ['tab' => 'system', 'label' => 'Locale & VAT', 'icon' => 'sliders-horizontal', 'scope' => 'Global'],
            ],
        ],
        [
            'label' => 'Access',
            'items' => [
                ['tab' => 'users', 'label' => 'Users', 'icon' => 'users'],
                ['tab' => 'roles', 'label' => 'Roles', 'icon' => 'shield'],
                ['tab' => 'departments', 'label' => 'Departments', 'icon' => 'network', 'roles' => ['Owner', 'Admin']],
            ],
        ],
        [
            'label' => 'People (HR)',
            'items' => [
                ['tab' => 'cash_advance_policy', 'label' => 'Cash advance policy', 'icon' => 'wallet'],
                ['tab' => 'emp_profile', 'label' => 'Emp profile', 'icon' => 'sparkles'],
                ['tab' => 'module_hub', 'label' => 'HR & module links', 'icon' => 'external-link', 'scope' => 'Hub'],
            ],
        ],
        [
            'label' => 'Communications',
            'items' => [
                ['tab' => 'email', 'label' => 'SMTP', 'icon' => 'mail', 'scope' => 'Global'],
                ['tab' => 're_email', 'label' => 'RE notifications', 'icon' => 'bell', 'scope' => 'Company'],
            ],
        ],
        [
            'label' => 'Finance & modules',
            'items' => [
                ['tab' => 'accounting', 'label' => 'Cleaning accounting', 'icon' => 'calculator', 'scope' => 'Cleaning'],
                ['tab' => 'service_categories', 'label' => 'Service categories', 'icon' => 'grid-3x3', 'scope' => 'Cleaning'],
            ],
        ],
        [
            'label' => 'Integrations',
            'items' => [
                ['tab' => 'mobile_app', 'label' => 'Mobile App Management', 'icon' => 'smartphone', 'scope' => 'Global', 'roles' => ['Owner', 'Admin']],
                ['tab' => 'integrations', 'label' => 'Integrations', 'icon' => 'plug', 'roles' => ['Owner', 'Admin']],
                ['tab' => 'ai_platform', 'label' => 'AI Platform', 'icon' => 'bot', 'scope' => 'Global', 'roles' => ['Owner', 'Admin']],
            ],
        ],
        [
            'label' => 'Security & audit',
            'items' => [
                ['tab' => 'security', 'label' => 'Security & retention', 'icon' => 'shield-check', 'roles' => ['Owner']],
                ['tab' => 'history', 'label' => 'Audit History', 'icon' => 'history', 'roles' => ['Owner']],
            ],
        ],
    ];

    $out = [];
    foreach ($groups as $group) {
        $items = [];
        foreach ($group['items'] as $item) {
            $rolesOk = empty($item['roles']);
            if (!$rolesOk) {
                foreach ($item['roles'] as $rn) {
                    if (function_exists('has_role') && has_role($rn, $conn)) {
                        $rolesOk = true;
                        break;
                    }
                }
            }
            if ($rolesOk) {
                $items[] = $item;
            }
        }
        if ($items) {
            $out[] = ['label' => $group['label'], 'items' => $items];
        }
    }
    return $out;
}

function admin_nav_tab_meta(string $tab): array
{
    $titles = [
        'dashboard' => ['Administration', 'Control Center overview'],
        'companies' => ['Companies & Modules', 'Enable modules and manage company setup'],
        'company' => ['Company Information', 'Profile for the selected company'],
        'branding' => ['Branding', 'Global look and feel'],
        'system' => ['Locale & VAT defaults', 'System-wide locale and tax defaults'],
        'users' => ['Users', 'Accounts, roles, and company access'],
        'roles' => ['Roles', 'Role definitions and permissions'],
        'departments' => ['Departments', 'RBAC department access'],
        'cash_advance_policy' => ['Cash advance policy', 'Loan / advance eligibility rules'],
        'emp_profile' => ['Employee profile', 'Recognition widgets and awards'],
        'module_hub' => ['HR & module links', 'Deep-links to in-module settings'],
        'email' => ['Email / SMTP', 'Canonical outbound mail settings'],
        're_email' => ['RE notifications', 'Real Estate notification recipients'],
        'accounting' => ['Cleaning accounting', 'Cleaning GL and prepaid defaults'],
        'service_categories' => ['Service categories', 'Cleaning service management categories'],
        'integrations' => ['Integrations', 'Status only — secrets never shown'],
        'mobile_app' => ['Mobile App Management', 'Versions, force update, store links, maintenance'],
        'ai_platform' => ['AI Platform', 'Kill switches and status — Phase 1A foundation (DEC-019)'],
        'security' => ['Security & retention', 'Audit archive policy'],
        'history' => ['Audit History', 'Owner activity center'],
    ];
    return $titles[$tab] ?? ['System Settings', 'Administration Control Center'];
}
