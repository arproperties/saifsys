<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

$invoice_id = (int)($_GET['invoice_id'] ?? 0);
if ($invoice_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid invoice ID']);
    exit;
}

try {
    $activities = [];
    
    // Get invoice payments from receipts and receipt_allocations
    $stmt = $conn->prepare("
        SELECT 
            'payment' as type,
            r.receipt_date as activity_date,
            CONCAT('Payment of ', FORMAT(SUM(ra.amount_applied), 2), ' AED via ', r.method) as description,
            CONCAT('Payment recorded via ', r.method) as details,
            'success' as status_class,
            'bi-credit-card' as icon,
            GROUP_CONCAT(r.notes SEPARATOR '; ') as additional_info
        FROM receipt_allocations ra
        INNER JOIN receipts r ON r.id = ra.receipt_id
        WHERE ra.invoice_id = ?
        GROUP BY r.id
        ORDER BY r.receipt_date DESC, r.id DESC
    ");
    $stmt->execute([$invoice_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $activities = array_merge($activities, $payments);
    
    // Get invoice status changes from audit log
    $stmt = $conn->prepare("
        SELECT 
            'status_change' as type,
            created_at as activity_date,
            summary,
            new_data,
            old_data
        FROM audit_log 
        WHERE object_type = 'invoices' 
        AND object_id = ? 
        AND action = 'update'
        AND JSON_EXTRACT(new_data, '$.status') IS NOT NULL
        AND JSON_EXTRACT(old_data, '$.status') IS NOT NULL
        ORDER BY created_at DESC
    ");
    $stmt->execute([$invoice_id]);
    $status_changes_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process in PHP to handle JSON
    foreach ($status_changes_raw as $row) {
        $old_data = json_decode($row['old_data'], true);
        $new_data = json_decode($row['new_data'], true);
        $old_status = $old_data['status'] ?? '';
        $new_status = $new_data['status'] ?? '';
        
        $activities[] = [
            'type' => 'status_change',
            'activity_date' => $row['created_at'],
            'description' => 'Status changed to ' . strtoupper(str_replace('_', ' ', $new_status)),
            'details' => 'Changed from ' . strtoupper(str_replace('_', ' ', $old_status)) . ' to ' . strtoupper(str_replace('_', ' ', $new_status)),
            'status_class' => $new_status === 'paid' ? 'success' : ($new_status === 'void' ? 'danger' : ($new_status === 'partially_paid' ? 'warning' : 'info')),
            'icon' => $new_status === 'paid' ? 'bi-check-circle' : ($new_status === 'void' ? 'bi-x-circle' : ($new_status === 'partially_paid' ? 'bi-clock' : 'bi-info-circle')),
            'additional_info' => $row['summary']
        ];
    }
    
    // Get invoice edits from audit log (excluding status-only changes we already processed)
    $stmt = $conn->prepare("
        SELECT 
            'edit' as type,
            created_at as activity_date,
            summary as description,
            summary as details,
            'primary' as status_class,
            'bi-pencil' as icon,
            NULL as additional_info
        FROM audit_log 
        WHERE object_type = 'invoices' 
        AND object_id = ? 
        AND action = 'update'
        AND (
            JSON_EXTRACT(new_data, '$.status') IS NULL 
            OR (JSON_EXTRACT(old_data, '$.status') = JSON_EXTRACT(new_data, '$.status'))
        )
        ORDER BY created_at DESC
    ");
    $stmt->execute([$invoice_id]);
    $edits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $activities = array_merge($activities, $edits);
    
    // Get invoice item changes from audit log
    $stmt = $conn->prepare("
        SELECT 
            'item_edit' as type,
            created_at as activity_date,
            'Invoice item updated' as description,
            summary as details,
            'info' as status_class,
            'bi-list-ul' as icon,
            CONCAT('Item ID: ', object_id) as additional_info
        FROM audit_log 
        WHERE object_type = 'invoice_items' 
        AND object_id IN (
            SELECT id FROM invoice_items WHERE invoice_id = ?
        )
        AND action = 'update'
        ORDER BY created_at DESC
    ");
    $stmt->execute([$invoice_id]);
    $item_edits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $activities = array_merge($activities, $item_edits);
    
    // Get invoice creation
    $stmt = $conn->prepare("
        SELECT 
            'created' as type,
            created_at as activity_date,
            'Invoice created' as description,
            'Invoice was created and added to the system' as details,
            'success' as status_class,
            'bi-plus-circle' as icon,
            'Initial invoice creation' as additional_info
        FROM audit_log 
        WHERE object_type = 'invoices' 
        AND object_id = ? 
        AND action = 'create'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$invoice_id]);
    $creation = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $activities = array_merge($activities, $creation);
    
    // Get email activities
    $stmt = $conn->prepare("
        SELECT 
            'email' as type,
            created_at as activity_date,
            CONCAT('Email sent to ', recipient_email) as description,
            CONCAT('Invoice email sent via template #', template_id) as details,
            CASE 
                WHEN status = 'sent' THEN 'success'
                WHEN status = 'failed' THEN 'danger'
                ELSE 'warning'
            END as status_class,
            CASE 
                WHEN status = 'sent' THEN 'bi-envelope-check'
                WHEN status = 'failed' THEN 'bi-envelope-x'
                ELSE 'bi-envelope'
            END as icon,
            CONCAT('Status: ', status, IF(error_message IS NOT NULL, CONCAT(' - ', error_message), '')) as additional_info
        FROM email_log 
        WHERE entity_type = 'invoice' 
        AND entity_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$invoice_id]);
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $activities = array_merge($activities, $emails);
    
    // Get discount changes
    $stmt = $conn->prepare("
        SELECT 
            created_at,
            summary,
            new_data,
            old_data
        FROM audit_log 
        WHERE object_type = 'invoices' 
        AND object_id = ? 
        AND action = 'update'
        AND JSON_EXTRACT(new_data, '$.discount_amount') IS NOT NULL
        ORDER BY created_at DESC
    ");
    $stmt->execute([$invoice_id]);
    $discounts_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process in PHP to handle JSON
    foreach ($discounts_raw as $row) {
        $old_data = json_decode($row['old_data'], true);
        $new_data = json_decode($row['new_data'], true);
        $old_discount = number_format((float)($old_data['discount_amount'] ?? 0), 2);
        $new_discount = number_format((float)($new_data['discount_amount'] ?? 0), 2);
        
        $activities[] = [
            'type' => 'discount',
            'activity_date' => $row['created_at'],
            'description' => 'Discount updated to ' . $new_discount . ' AED',
            'details' => $row['summary'],
            'status_class' => 'warning',
            'icon' => 'bi-percent',
            'additional_info' => 'Previous: ' . $old_discount . ' AED'
        ];
    }
    
    // Sort all activities by date (newest first)
    usort($activities, function($a, $b) {
        return strtotime($b['activity_date']) - strtotime($a['activity_date']);
    });
    
    // Format dates for display
    foreach ($activities as &$activity) {
        $activity['formatted_date'] = date('M j, Y', strtotime($activity['activity_date']));
        $activity['formatted_time'] = date('g:i A', strtotime($activity['activity_date']));
    }
    
    echo json_encode(['success' => true, 'activities' => $activities]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
