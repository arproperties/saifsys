<?php
/**
 * Construction -> Inventory bridge
 */

require_once dirname(__DIR__, 3) . '/includes/inventory/inv_integration.php';

function co_inv_source_project(int $projectId): array {
    return inv_build_source_ref('construction', 'co_projects', $projectId);
}

