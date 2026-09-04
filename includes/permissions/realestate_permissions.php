<?php
/**
 * Real Estate Module Permission Definitions
 * Defines all available permissions for the Real Estate module
 * 
 * Note: This module includes its own finance features (payments, billing, collections)
 * which are separate from the Finance module used by Cleaning business.
 */

return [
    'buildings' => [
        'view',
        'create',
        'edit',
        'delete'
    ],
    'units' => [
        'view',
        'create',
        'edit',
        'delete',
        'change_status'
    ],
    'tenants' => [
        'view',
        'create',
        'edit',
        'delete',
        'view_documents'
    ],
    'leases' => [
        'view',
        'create',
        'edit',
        'delete',
        'generate_contract',
        'send_contract',
        'renew',
        'terminate'
    ],
    'payments' => [
        'view',
        'create',
        'edit',
        'delete',
        'print_receipts'
    ],
    'billing' => [
        'view',
        'create',
        'edit',
        'delete',
        'generate_invoices'
    ],
    'collections' => [
        'view',
        'manage',
        'send_notices'
    ],
    'cheques' => [
        'view',
        'create',
        'edit',
        'update_status'
    ],
    'maintenance' => [
        'view',
        'create',
        'edit',
        'assign',
        'complete',
        'view_costs'
    ],
    'vendors' => [
        'view',
        'create',
        'edit',
        'delete',
        'manage_agreements'
    ],
    'move_in' => [
        'view',
        'create',
        'complete'
    ],
    'move_out' => [
        'view',
        'create',
        'complete'
    ],
    'tasks' => [
        'view',
        'create',
        'edit',
        'assign',
        'complete'
    ],
    'compliance' => [
        'view',
        'manage'
    ],
    'documents' => [
        'view',
        'upload',
        'delete'
    ],
    'reports' => [
        'view',
        'export',
        'financial'
    ]
];
