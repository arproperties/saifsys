<?php
/**
 * Core Module Permission Definitions
 * Defines all available permissions for the Core system module
 */

return [
    'users' => [
        'view',
        'create',
        'edit',
        'delete',
        'assign_roles'
    ],
    'companies' => [
        'view',
        'create',
        'edit',
        'delete',
        'manage_users'
    ],
    'settings' => [
        'view',
        'edit',
        'manage_branding'
    ],
    'audit' => [
        'view',
        'export'
    ],
    'roles' => [
        'view',
        'create',
        'edit',
        'delete',
        'manage_permissions'
    ]
];
