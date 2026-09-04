<?php
/**
 * ARS -> Inventory bridge
 */

require_once dirname(__DIR__, 3) . '/includes/inventory/inv_integration.php';

function ars_inv_source_booking(int $bookingId): array {
    return inv_build_source_ref('ars', 'ars_bookings', $bookingId);
}

