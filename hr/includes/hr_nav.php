<?php
/**
 * HR module sidebar IA (manager + worker self-service).
 */

// Fleet's pages moved to Operations; HR still sets who may use the Driver app
// (employee_view.php), which needs these.
require_once __DIR__ . '/hr_fleet.php';

/**
 * @return list<array{label:string,items:list<array{key:string,label:string,icon:string,href:string,pages:list<string>}>}>
 */
function hr_nav_groups(string $hrBase, bool $isWorkerNavigation, string $selfProfileUrl = 'employee_view'): array
{
    if ($isWorkerNavigation) {
        return [
            [
                'label' => 'Self Service',
                'items' => [
                    [
                        'key' => 'profile',
                        'label' => 'My Profile',
                        'icon' => 'user-circle',
                        'href' => $selfProfileUrl . '#tab-overview',
                        'pages' => ['employee_view.php'],
                    ],
                    [
                        'key' => 'leave',
                        'label' => 'Apply Leave',
                        'icon' => 'calendar-check',
                        'href' => $selfProfileUrl . '#tab-leave',
                        'pages' => [],
                    ],
                    [
                        'key' => 'loans',
                        'label' => 'Loans / Advances',
                        'icon' => 'wallet',
                        'href' => $selfProfileUrl . '#tab-cashadv',
                        'pages' => [],
                    ],
                    [
                        'key' => 'deduct',
                        'label' => 'Deductions',
                        'icon' => 'minus-circle',
                        'href' => $selfProfileUrl . '#tab-deduct',
                        'pages' => [],
                    ],
                ],
            ],
        ];
    }

    // Dashboard on its own, then four menus that open and close ('collapsible').
    return [
        [
            'key' => 'overview',
            'label' => '',
            'items' => [
                [
                    'key' => 'dashboard',
                    'label' => 'Dashboard',
                    'icon' => 'layout-dashboard',
                    'href' => $hrBase . '/dashboard',
                    'pages' => ['dashboard.php'],
                ],
            ],
        ],
        [
            'key' => 'people',
            'label' => 'People',
            'icon' => 'users',
            'collapsible' => true,
            'items' => [
                [
                    'key' => 'employees',
                    'label' => 'Employees',
                    'icon' => 'users',
                    'href' => $hrBase . '/employees',
                    'pages' => ['employees.php', 'employee_view.php', 'employee_add.php', 'employee_edit.php'],
                ],
                [
                    'key' => 'org',
                    'label' => 'Organization',
                    'icon' => 'network',
                    'href' => $hrBase . '/org_units',
                    'pages' => ['org_units.php'],
                ],
                [
                    'key' => 'employee_pins',
                    'label' => 'Staff app PINs',
                    'icon' => 'key-round',
                    'href' => $hrBase . '/employee_pins_bulk',
                    'pages' => ['employee_pins_bulk.php'],
                ],
                [
                    'key' => 'performance',
                    'label' => 'Performance',
                    'icon' => 'trending-up',
                    'href' => $hrBase . '/performance',
                    'pages' => ['performance.php', 'performance_detail.php', 'perf_settings.php'],
                ],
            ],
        ],
        [
            'key' => 'attendance_menu',
            'label' => 'Attendance',
            'icon' => 'calendar-check-2',
            'collapsible' => true,
            'items' => [
                [
                    'key' => 'attendance',
                    'label' => 'Attendance',
                    'icon' => 'calendar-check-2',
                    'href' => $hrBase . '/attendance',
                    // Bulk apply and Monthly summary open from the buttons on Attendance.
                    'pages' => ['attendance.php', 'attendance_edit.php', 'attendance_bulk.php', 'attendance_summary.php'],
                ],
                [
                    'key' => 'attendance_missing',
                    'label' => 'Missing check-ins',
                    'icon' => 'user-x',
                    'href' => $hrBase . '/attendance_missing',
                    'pages' => ['attendance_missing.php'],
                ],
                [
                    'key' => 'overtime',
                    'label' => 'Overtime',
                    'icon' => 'timer',
                    'href' => $hrBase . '/overtime',
                    'pages' => ['overtime.php', 'overtime_export.php'],
                ],
            ],
        ],
        [
            'key' => 'leave_menu',
            'label' => 'Leave',
            'icon' => 'calendar-x',
            'collapsible' => true,
            'items' => [
                [
                    'key' => 'leave',
                    'label' => 'Leave requests',
                    'icon' => 'calendar-x',
                    'href' => $hrBase . '/leave_requests',
                    // Leave types and balances open from the buttons on Leave requests.
                    'pages' => ['leave_requests.php', 'leave_types.php', 'leave_balances.php'],
                ],
                [
                    'key' => 'leave_calendar',
                    'label' => 'Leave calendar',
                    'icon' => 'calendar-range',
                    'href' => $hrBase . '/leave_calendar',
                    'pages' => ['leave_calendar.php'],
                ],
                [
                    'key' => 'holidays',
                    'label' => 'Holidays',
                    'icon' => 'calendar-days',
                    'href' => $hrBase . '/holidays',
                    'pages' => ['holidays.php'],
                ],
            ],
        ],
        [
            'key' => 'payroll_menu',
            'label' => 'Payroll',
            'icon' => 'banknote',
            'collapsible' => true,
            'items' => [
                [
                    'key' => 'payroll',
                    'label' => 'Payroll runs',
                    'icon' => 'banknote',
                    'href' => $hrBase . '/payroll_runs',
                    'pages' => ['payroll_runs.php', 'payroll_run_build.php', 'payroll_run_view.php', 'payslip.php'],
                ],
                [
                    'key' => 'loans',
                    'label' => 'Loans / Advances',
                    'icon' => 'wallet',
                    'href' => $hrBase . '/cash_advances',
                    'pages' => ['cash_advances.php', 'loan_settle_cash.php'],
                ],
                [
                    'key' => 'deductions',
                    'label' => 'Deductions',
                    'icon' => 'minus-circle',
                    'href' => $hrBase . '/employee_deductions',
                    'pages' => ['employee_deductions.php'],
                ],
            ],
        ],
        [
            'key' => 'admin',
            'label' => 'Administration',
            'icon' => 'settings-2',
            'collapsible' => true,
            'items' => [
                [
                    'key' => 'documents',
                    'label' => 'Documents',
                    'icon' => 'folder-open',
                    'href' => $hrBase . '/documents',
                    'pages' => ['documents.php'],
                ],
                [
                    'key' => 'company_docs',
                    'label' => 'Company Documents',
                    'icon' => 'building-2',
                    'href' => $hrBase . '/company_documents',
                    'pages' => ['company_documents.php'],
                ],
                [
                    'key' => 'access',
                    'label' => 'Access',
                    'icon' => 'shield',
                    'href' => $hrBase . '/access',
                    'pages' => ['access.php'],
                ],
                [
                    'key' => 'reports',
                    'label' => 'Reports',
                    'icon' => 'bar-chart-3',
                    'href' => $hrBase . '/reports',
                    'pages' => ['reports.php'],
                ],
            ],
        ],
    ];
}

function hr_nav_page_title(string $currentPage): string
{
    $map = [
        'dashboard.php' => 'HR Dashboard',
        'employees.php' => 'Employees',
        'employee_view.php' => 'Employee profile',
        'employee_edit.php' => 'Edit employee',
        'org_units.php' => 'Organization',
        'attendance.php' => 'Attendance',
        'attendance_bulk.php' => 'Bulk attendance',
        'attendance_summary.php' => 'Attendance summary',
        'attendance_edit.php' => 'Edit attendance',
        'attendance_missing.php' => 'Missing check-ins',
        'employee_pins_bulk.php' => 'Staff app PINs',
        'overtime.php' => 'Overtime',
        'leave_requests.php' => 'Leave requests',
        'leave_calendar.php' => 'Leave calendar',
        'leave_types.php' => 'Leave types',
        'leave_balances.php' => 'Leave balances',
        'holidays.php' => 'Holidays',
        'payroll_runs.php' => 'Payroll runs',
        'payroll_run_build.php' => 'Build payroll run',
        'payroll_run_view.php' => 'Payroll run',
        'payslip.php' => 'Payslip',
        'cash_advances.php' => 'Loans / Advances',
        'loan_settle_cash.php' => 'Settle loan',
        'documents.php' => 'Documents',
        'company_documents.php' => 'Company Documents',
        'access.php' => 'Access',
        'performance.php' => 'Performance',
        'performance_detail.php' => 'Performance detail',
        'perf_settings.php' => 'Performance settings',
        'reports.php' => 'Reports',
        'employee_deductions.php' => 'Deductions',
    ];
    return $map[$currentPage] ?? 'Human Resources';
}
