<?php
// operation/ajax_bulk_update_status.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';

header('Content-Type: application/json');

// Require login
if (!current_user_id()) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$order_ids = $input['order_ids'] ?? [];
$new_status = trim($input['status'] ?? '');

if (empty($order_ids) || !is_array($order_ids)) {
    echo json_encode(['success' => false, 'error' => 'No orders selected']);
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

// Sanitize order IDs
$order_ids = array_map('intval', $order_ids);
$order_ids = array_filter($order_ids, function($id) { return $id > 0; });

if (empty($order_ids)) {
    echo json_encode(['success' => false, 'error' => 'Invalid order IDs']);
    exit;
}

try {
    $conn->beginTransaction();
    
    $updated_count = 0;
    $errors = [];
    
    foreach ($order_ids as $order_id) {
        // Get current status
        $st = $conn->prepare("SELECT status FROM make_order WHERE id = ?");
        $st->execute([$order_id]);
        $current = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$current) {
            $errors[] = "Order #{$order_id} not found";
            continue;
        }
        
        $old_status = $current['status'];
        
        // Don't allow changing from cancelled
        if ($old_status === 'cancelled') {
            $errors[] = "Order #{$order_id} is cancelled and cannot be changed";
            continue;
        }
        
        // Update the status
        $update_st = $conn->prepare("
            UPDATE make_order 
            SET status = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        
        if ($update_st->execute([$new_status, $order_id])) {
            $updated_count++;
            
            // Log the change using AuditService if available
            try {
                require_once __DIR__ . '/../includes/AuditService.php';
                AuditService::logStatusChange(
                    'make_order',
                    $order_id,
                    $old_status ?: 'none',
                    $new_status,
                    "Bulk status update: {$old_status} → {$new_status}",
                    current_user_id()
                );
            } catch (\Throwable $e) {
                // Audit logging is nice-to-have
            }
        } else {
            $errors[] = "Failed to update order #{$order_id}";
        }
    }
    
    $conn->commit();
    
    $response = [
        'success' => true,
        'updated' => $updated_count,
        'total' => count($order_ids),
        'message' => "Successfully updated {$updated_count} order(s)"
    ];
    
    if (!empty($errors)) {
        $response['errors'] = $errors;
        $response['message'] .= ' (with ' . count($errors) . ' error(s))';
    }
    
    echo json_encode($response);
    
} catch (\Throwable $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

