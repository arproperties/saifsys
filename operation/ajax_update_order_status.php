<?php
// operation/ajax_update_order_status.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/work_order_financial_guard.php';

header('Content-Type: application/json');

// Require login
if (!current_user_id()) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$new_status = trim($_POST['status'] ?? '');

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
    exit;
}

if (empty($new_status)) {
    echo json_encode(['success' => false, 'error' => 'Status is required']);
    exit;
}

// Valid statuses
$valid_statuses = ['draft', 'scheduled', 'confirmed', 'in_progress', 'completed', 'invoiced', 'cancelled'];
if (!in_array($new_status, $valid_statuses)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit;
}

try {
    $st = $conn->prepare("SELECT * FROM make_order WHERE id = ?");
    $st->execute([$order_id]);
    $current = $st->fetch(PDO::FETCH_ASSOC);
    
    if (!$current) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }
    
    $old_status = $current['status'];

    if (wo_ops_is_locked($conn, $current)) {
        echo json_encode(['success' => false, 'error' => wo_ops_lock_reason($conn, $current)]);
        exit;
    }

    if ($new_status === 'cancelled') {
        [$canCancel, $cancelBlock] = wo_ops_can_direct_cancel($conn, $order_id);
        if (!$canCancel) {
            echo json_encode(['success' => false, 'error' => $cancelBlock]);
            exit;
        }
    }
    
    // Don't allow changing from cancelled
    if ($old_status === 'cancelled') {
        echo json_encode(['success' => false, 'error' => 'Cannot change status of cancelled orders']);
        exit;
    }
    
    // Update the status
    $update_st = $conn->prepare("
        UPDATE make_order 
        SET status = ?, updated_at = NOW() 
        WHERE id = ?
    ");
    $success = $update_st->execute([$new_status, $order_id]);
    
    if ($success) {
        // Sync booking status if this order is linked to a booking
        try {
            $bookingStmt = $conn->prepare("
                SELECT id, status 
                FROM online_bookings 
                WHERE work_order_id = ?
            ");
            $bookingStmt->execute([$order_id]);
            $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($booking) {
                // Map order status to booking status
                $bookingStatusMap = [
                    'draft' => 'pending',
                    'scheduled' => 'confirmed',
                    'confirmed' => 'assigned',
                    'in_progress' => 'in_progress',
                    'completed' => 'completed',
                    'invoiced' => 'completed',
                    'cancelled' => 'cancelled'
                ];
                
                $newBookingStatus = $bookingStatusMap[$new_status] ?? $booking['status'];
                
                // Only update if status actually changed
                if ($newBookingStatus !== $booking['status']) {
                    $updateBookingStmt = $conn->prepare("
                        UPDATE online_bookings 
                        SET status = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $updateBookingStmt->execute([$newBookingStatus, $booking['id']]);
                    
                    // Log booking status change
                    $eventStmt = $conn->prepare("
                        INSERT INTO booking_events (booking_id, event_type, event_data, user_id)
                        VALUES (?, 'status_changed', ?, ?)
                    ");
                    $eventStmt->execute([
                        $booking['id'],
                        json_encode([
                            'old_status' => $booking['status'],
                            'new_status' => $newBookingStatus,
                            'triggered_by' => 'order_status_change',
                            'order_id' => $order_id,
                            'order_status' => $new_status
                        ]),
                        current_user_id()
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Don't fail order update if booking sync fails
            error_log("Warning: Failed to sync booking status - " . $e->getMessage());
        }
        
        // Log the change using AuditService if available
        try {
            require_once __DIR__ . '/../includes/AuditService.php';
            AuditService::logStatusChange(
                'make_order',
                $order_id,
                $old_status ?: 'none',
                $new_status,
                "Order status changed from {$old_status} to {$new_status}",
                current_user_id()
            );
        } catch (\Throwable $e) {
            // Audit logging is nice-to-have, don't fail if it errors
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Status updated successfully',
            'old_status' => $old_status,
            'new_status' => $new_status
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update status']);
    }
    
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

