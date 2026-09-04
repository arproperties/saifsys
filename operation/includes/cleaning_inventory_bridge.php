<?php
/**
 * Cleaning (Operations) -> Inventory bridge
 */

require_once dirname(__DIR__, 2) . '/includes/inventory/inv_integration.php';
require_once dirname(__DIR__, 2) . '/includes/inventory/inv_request_links.php';

function cleaning_inv_source_job(int $jobId): array {
    return inv_build_source_ref('cleaning', 'make_order', $jobId);
}

/** Web URL to pre-filled Inventory material request for a work order (make_order.id). */
function cleaning_inv_material_request_url(PDO $conn, int $orderId): string {
    return inv_request_material_create_url($conn, [
        'source_module' => 'cleaning',
        'source_table' => 'make_order',
        'source_id' => $orderId,
        'context_cleaning_job_id' => $orderId,
        'notes_hint' => 'Cleaning work order #' . $orderId,
    ]);
}

