<?php
/**
 * Cleaning Module Permission Definitions
 * Defines all available permissions for the Cleaning module
 */

return [
    'clients' => [
        'view',
        'create',
        'edit',
        'delete'
    ],
    'orders' => [
        'view',
        'create',
        'edit',
        'delete',
        'assign'
    ],
    'workorders' => [
        'view',
        'create',
        'edit',
        'complete',
        'cancel'
    ],
    'sm' => [
        'jobs.create',
        'jobs.edit',
        'jobs.complete',
        'jobs.cancel',
        'jobs.finalize',
        'jobs.request_adjustment'
    ],
    'services' => [
        'view',
        'create',
        'edit',
        'delete'
    ],
    'employees' => [
        'view',
        'assign'
    ],
    'scheduling' => [
        'view',
        'manage',
        'approve'
    ],
    'invoicing' => [
        'view',
        'create',
        'edit',
        'delete'
    ],
    'reports' => [
        'view',
        'export',
        'financial'
    ]
];
