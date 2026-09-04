<?php
/**
 * HR Module Permission Definitions
 * Defines all available permissions for the HR module
 */

return [
    'employees' => [
        'view',
        'create',
        'edit',
        'delete',
        'view_salary'
    ],
    'attendance' => [
        'view',
        'record',
        'approve',
        'edit'
    ],
    'leave' => [
        'view',
        'request',
        'approve',
        'manage'
    ],
    'payroll' => [
        'view',
        'run',
        'edit',
        'approve',
        'view_all',
        'override_validation' // Save/post payroll despite WPS / take-home guards (audited)
    ],
    'documents' => [
        'view',
        'upload',
        'delete'
    ],
    'company_documents' => [
        'view',
        'manage'
    ],
    'performance' => [
        'view',
        'create',
        'edit'
    ],
    'recruitment' => [
        'view',
        'create',
        'manage'
    ],
    'reports' => [
        'view',
        'export',
        'payroll'
    ]
];
