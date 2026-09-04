<?php
/**
 * Real Estate -> Inventory bridge
 */

require_once dirname(__DIR__, 3) . '/includes/inventory/inv_integration.php';

function re_inv_source_workorder(int $workorderId): array {
    return inv_build_source_ref('realestate', 're_workorders', $workorderId);
}

function re_inv_source_unit(int $unitId): array {
    return inv_build_source_ref('realestate', 're_units', $unitId);
}

