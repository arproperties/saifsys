<?php
/**
 * ARS Housekeeping — AJAX Actions
 * Quick status updates for cleaning work orders.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';

header('Content-Type: application/json');

require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_activity.php';
$arsCompanyId = arsPageAuth($conn);
ars_ajax_csrf_verify();
$settings = getArsSettings($conn, $arsCompanyId);
$cleaningCompanyId = $settings['cleaning_company_id'] ?? null;

if (!$cleaningCompanyId) {
    echo json_encode(['success' => false, 'error' => 'No cleaning company configured.']);
    exit;
}

$action  = $_POST['action'] ?? '';
$orderId = (int)($_POST['order_id'] ?? 0);

if (!$orderId) {
    echo json_encode(['success' => false, 'error' => 'Order ID is required.']);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM make_order WHERE id = ? AND company_id = ?");
$stmt->execute([$orderId, $cleaningCompanyId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo json_encode(['success' => false, 'error' => 'Order not found.']);
    exit;
}

try {
    $logHk = static function (string $eventType, string $title, string $newStatus) use ($conn, $arsCompanyId, $order, $orderId): void {
        $bookingId = (int)($order['ars_booking_id'] ?? 0);
        if ($bookingId <= 0) {
            return;
        }
        ars_booking_activity_log($conn, [
            'company_id' => $arsCompanyId,
            'booking_id' => $bookingId,
            'event_category' => 'housekeeping',
            'event_type' => $eventType,
            'title' => $title,
            'new_value' => $newStatus,
            'related_entity_type' => 'housekeeping_order',
            'related_entity_id' => $orderId,
            'status' => $newStatus,
            'source' => 'user',
            'created_by' => current_user_id(),
            'dedupe_key' => 'hk:' . $orderId . ':' . $eventType,
        ]);
    };

    switch ($action) {
        case 'start':
            if (!in_array($order['status'], ['confirmed', 'scheduled'])) {
                echo json_encode(['success' => false, 'error' => 'Only confirmed/scheduled orders can be started.']);
                exit;
            }
            $conn->prepare("UPDATE make_order SET status = 'in_progress', updated_at = NOW() WHERE id = ?")
                ->execute([$orderId]);
            $logHk('housekeeping_started', 'Housekeeping started', 'in_progress');
            echo json_encode(['success' => true, 'new_status' => 'in_progress']);
            break;

        case 'complete':
            if ($order['status'] !== 'in_progress') {
                echo json_encode(['success' => false, 'error' => 'Only in-progress orders can be completed.']);
                exit;
            }
            $conn->prepare("UPDATE make_order SET status = 'completed', updated_at = NOW() WHERE id = ?")
                ->execute([$orderId]);
            // Do not overwrite start_time/end_time/hours/total — ARS WOs use a flat fee_charged.
            // Setting end_time=CURTIME() caused cleaning recalc to zero totals (hourly_rate "0.00" truthiness).
            $logHk('housekeeping_completed', 'Housekeeping completed', 'completed');
            echo json_encode(['success' => true, 'new_status' => 'completed']);
            break;

        case 'cancel':
            if (in_array($order['status'], ['completed', 'invoiced', 'cancelled'])) {
                echo json_encode(['success' => false, 'error' => 'This order cannot be cancelled.']);
                exit;
            }
            $reason = trim($_POST['reason'] ?? 'Cancelled from ARS Housekeeping');
            $conn->prepare("UPDATE make_order SET status = 'cancelled', cancel_reason = ?, cancelled_at = NOW(), cancelled_by = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$reason, current_user_id(), $orderId]);
            $logHk('housekeeping_cancelled', 'Housekeeping cancelled', 'cancelled');
            echo json_encode(['success' => true, 'new_status' => 'cancelled']);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
