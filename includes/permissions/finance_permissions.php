<?php
/**
 * Finance Module Permission Definitions (For Cleaning Business)
 * Defines all available permissions for the Finance/Accounts module
 * 
 * Note: This is for Cleaning business accounting.
 * Real Estate has its own finance features in the realestate module.
 */

return [
    'invoices' => [
        'view',
        'create',
        'edit',
        'delete',
        'approve',
        'send'
    ],
    'payments' => [
        'view',
        'create',
        'edit',
        'delete',
        'reconcile'
    ],
    'expenses' => [
        'view',
        'create',
        'edit',
        'delete',
        'approve'
    ],
    'accounts' => [
        'view',
        'create',
        'edit',
        'delete'
    ],
    'journal' => [
        'view',
        'create',
        'edit',
        'delete',
        'post'
    ],
    'reports' => [
        'view',
        'export',
        'balance_sheet',
        'profit_loss',
        'cash_flow'
    ],
    'reconciliation' => [
        'view',
        'manage',
        'approve'
    ],
    'settings' => [
        'view',
        'edit',
        'manage_accounts'
    ]
];
