<?php
/**
 * Real Estate - Lease Penalty Generation
 *
 * Generates Late-Fee and Bounced-Cheque penalty billing items for all active
 * leases. This used to run on every lease_view.php GET request; it now runs here
 * so that viewing a lease never mutates data.
 *
 * Run via cron (e.g. once daily):
 *   php /path/to/modules/realestate/cron_lease_penalties.php
 */

if (php_sapi_name() !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/billing_helper.php';

$now = new DateTimeImmutable('now');
$totalCreated = 0;

try {
    // All leases that can still accrue penalties.
    $stmt = $conn->query("
        SELECT id, company_id
        FROM re_leases
        WHERE status NOT IN ('terminated', 'cancelled', 'expired')
        ORDER BY company_id, id
    ");
    $leases = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($leases as $lease) {
        try {
            $totalCreated += re_generate_lease_penalties($conn, (int)$lease['company_id'], (int)$lease['id']);
        } catch (Throwable $e) {
            error_log('cron_lease_penalties lease #' . $lease['id'] . ': ' . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    error_log('cron_lease_penalties: ' . $e->getMessage());
}

echo 'Lease penalties processed at ' . $now->format('Y-m-d H:i:s')
    . ' — created ' . $totalCreated . ' item(s).' . PHP_EOL;
