<?php
/**
 * Inventory Module Permission Definitions
 * Shared across all business modules/companies.
 */

return [
    'inventory_items' => [
        'view',
        'create',
        'edit',
        'deactivate'
    ],
    'inventory_locations' => [
        'view',
        'create',
        'edit',
        'deactivate'
    ],
    'inventory_docs' => [
        'view',
        'create',
        'edit',
        'post',
        'void'
    ],
    'inventory_adjustments' => [
        'create',
        'post'
    ],
    'inventory_reports' => [
        'view',
        'export'
    ],
    'inventory_settings' => [
        'view',
        'edit'
    ],
    'inventory_purchasing' => [
        'view',
        'create',
        'edit',
        'post'
    ],
    'inventory_requests' => [
        'view',
        'create',
        'approve',
        'reject'
    ]
];

