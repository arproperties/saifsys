<?php
/**
 * HR module sidebar IA (manager + worker self-service).
 */

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

    return [
        [
            'label' => 'Overview',
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
            'label' => 'People',
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
            ],
        ],
        [
            'label' => 'Fleet',
            'items' => [
                [
                    'key' => 'fleet_live',
                    'label' => 'Live Map',
                    'icon' => 'map',
                    'href' => $hrBase . '/fleet_live',
                    'pages' => ['fleet_live.php'],
                ],
                [
                    'key' => 'fleet_history',
                    'label' => 'Trip History',
                    'icon' => 'route',
                    'href' => $hrBase . '/fleet_history',
                    'pages' => ['fleet_history.php', 'fleet_trip.php'],
                ],
                [
                    'key' => 'fleet_checks',
                    'label' => 'Daily Checks',
                    'icon' => 'clipboard-list',
                    'href' => $hrBase . '/fleet_checks',
                    'pages' => ['fleet_checks.php'],
                ],
                // Pickup points and routes are hidden for now — see fleet_routes_enabled().
                ...(fleet_routes_enabled() ? [[
                    'key' => 'fleet_pickup_report',
                    'label' => 'Pickup Report',
                    'icon' => 'clipboard-check',
                    'href' => $hrBase . '/fleet_pickup_report',
                    'pages' => ['fleet_pickup_report.php'],
                ]] : []),
                [
                    'key' => 'vehicles',
                    'label' => 'Vehicles',
                    'icon' => 'car',
                    'href' => $hrBase . '/vehicles',
                    'pages' => ['vehicles.php', 'vehicle_view.php'],
                ],
                ...(fleet_routes_enabled() ? [
                    [
                        'key' => 'fleet_routes',
                        'label' => 'Routes',
                        'icon' => 'signpost',
                        'href' => $hrBase . '/fleet_routes',
                        'pages' => ['fleet_routes.php'],
                    ],
                    [
                        'key' => 'fleet_points',
                        'label' => 'Pickup Points',
                        'icon' => 'map-pin',
                        'href' => $hrBase . '/fleet_points',
                        'pages' => ['fleet_points.php'],
                    ],
                ] : []),
            ],
        ],
        [
            'label' => 'Time & Attendance',
            'items' => [
                [
                    'key' => 'attendance',
                    'label' => 'Attendance',
                    'icon' => 'calendar-check-2',
                    'href' => $hrBase . '/attendance',
                    'pages' => ['attendance.php', 'attendance_bulk.php', 'attendance_summary.php', 'attendance_edit.php'],
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
                    'icon' => 'clock',
                    'href' => $hrBase . '/overtime',
                    'pages' => ['overtime.php'],
                ],
                [
                    'key' => 'leave',
                    'label' => 'Leave',
                    'icon' => 'calendar-x',
                    'href' => $hrBase . '/leave_requests',
                    'pages' => ['leave_requests.php', 'leave_types.php', 'leave_balances.php'],
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
            'label' => 'Payroll & Finance',
            'items' => [
                [
                    'key' => 'payroll',
                    'label' => 'Payroll',
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
            ],
        ],
        [
            'label' => 'Administration',
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
                    'key' => 'performance',
                    'label' => 'Performance',
                    'icon' => 'trending-up',
                    'href' => $hrBase . '/performance',
                    'pages' => ['performance.php', 'performance_detail.php', 'perf_settings.php'],
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
        'fleet_live.php' => 'Live Map',
        'fleet_history.php' => 'Trip History',
        'fleet_checks.php' => 'Daily Checks',
        'vehicles.php' => 'Vehicles',
        'vehicle_view.php' => 'Vehicle',
        'fleet_pickup_report.php' => 'Pickup Report',
        'fleet_routes.php' => 'Routes',
        'fleet_points.php' => 'Pickup Points',
        'attendance.php' => 'Attendance',
        'attendance_bulk.php' => 'Bulk attendance',
        'attendance_summary.php' => 'Attendance summary',
        'attendance_edit.php' => 'Edit attendance',
        'attendance_missing.php' => 'Missing check-ins',
        'overtime.php' => 'Overtime',
        'leave_requests.php' => 'Leave requests',
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
    ];
    return $map[$currentPage] ?? 'Human Resources';
}
