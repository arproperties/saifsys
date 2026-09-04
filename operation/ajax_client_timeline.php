<?php
// operation/ajax_client_timeline.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';

header('Content-Type: application/json');

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$type_filter = $_GET['type'] ?? ''; // Filter by activity type

if ($client_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
    exit;
}

try {
    $timeline = [];
    
    // 1. Orders
    if (empty($type_filter) || $type_filter === 'orders') {
        $sql = "
            SELECT 
                'order' as type,
                mo.id as activity_id,
                mo.service_date as date,
                mo.created_at as timestamp,
                CONCAT('Order #', mo.id, ' - ', mo.worker_name) as title,
                CONCAT('Service on ', mo.service_date, ' from ', mo.start_time, ' to ', mo.end_time, 
                       '. Hours: ', mo.hours, ', Value: AED ', FORMAT(mo.grand_total, 2)) as description,
                mo.status as status,
                mo.grand_total as amount,
                NULL as user_id,
                NULL as user_name
            FROM make_order mo
            WHERE mo.client_id = ?
            ORDER BY mo.service_date DESC, mo.created_at DESC
            LIMIT 50
        ";
        $st = $conn->prepare($sql);
        $st->execute([$client_id]);
        $orders = $st->fetchAll(PDO::FETCH_ASSOC);
        $timeline = array_merge($timeline, $orders);
    }
    
    // 2. Invoices
    if (empty($type_filter) || $type_filter === 'invoices') {
        $sql = "
            SELECT 
                'invoice' as type,
                i.id as activity_id,
                i.issue_date as date,
                i.created_at as timestamp,
                CONCAT('Invoice #', i.invoice_no) as title,
                CONCAT('Amount: AED ', FORMAT(i.total, 2), ', Status: ', i.status, 
                       CASE WHEN i.due_date IS NOT NULL THEN CONCAT(', Due: ', i.due_date) ELSE '' END) as description,
                i.status as status,
                i.total as amount,
                i.created_by as user_id,
                u.fullname as user_name
            FROM invoices i
            LEFT JOIN user u ON u.id = i.created_by
            WHERE i.client_id = ?
            ORDER BY i.issue_date DESC, i.created_at DESC
            LIMIT 50
        ";
        $st = $conn->prepare($sql);
        $st->execute([$client_id]);
        $invoices = $st->fetchAll(PDO::FETCH_ASSOC);
        $timeline = array_merge($timeline, $invoices);
    }
    
    // 3. Payments
    if (empty($type_filter) || $type_filter === 'payments') {
        $sql = "
            SELECT 
                'payment' as type,
                r.id as activity_id,
                r.receipt_date as date,
                r.created_at as timestamp,
                CONCAT('Payment - ', r.method, ' - AED ', FORMAT(r.amount, 2)) as title,
                CONCAT('Receipt #', r.receipt_no, 
                       CASE WHEN r.notes IS NOT NULL AND r.notes != '' THEN CONCAT(', Notes: ', r.notes) ELSE '' END) as description,
                'completed' as status,
                r.amount as amount,
                r.created_by as user_id,
                u.fullname as user_name
            FROM receipts r
            LEFT JOIN user u ON u.id = r.created_by
            WHERE r.client_id = ?
            ORDER BY r.receipt_date DESC, r.created_at DESC
            LIMIT 50
        ";
        $st = $conn->prepare($sql);
        $st->execute([$client_id]);
        $payments = $st->fetchAll(PDO::FETCH_ASSOC);
        $timeline = array_merge($timeline, $payments);
    }
    
    // 4. Communications
    if (empty($type_filter) || $type_filter === 'communications') {
        $sql = "
            SELECT 
                CONCAT('comm_', cc.type) as type,
                cc.id as activity_id,
                DATE(cc.sent_at) as date,
                cc.sent_at as timestamp,
                CONCAT(UCASE(cc.type), ' - ', COALESCE(cc.subject, 'No Subject')) as title,
                SUBSTRING(cc.message, 1, 100) as description,
                cc.status as status,
                NULL as amount,
                cc.sent_by as user_id,
                u.fullname as user_name
            FROM client_communications cc
            LEFT JOIN user u ON u.id = cc.sent_by
            WHERE cc.client_id = ?
            ORDER BY cc.sent_at DESC
            LIMIT 50
        ";
        $st = $conn->prepare($sql);
        $st->execute([$client_id]);
        $communications = $st->fetchAll(PDO::FETCH_ASSOC);
        $timeline = array_merge($timeline, $communications);
    }
    
    // 5. Notes
    if (empty($type_filter) || $type_filter === 'notes') {
        $sql = "
            SELECT 
                'note' as type,
                cn.id as activity_id,
                DATE(cn.created_at) as date,
                cn.created_at as timestamp,
                CONCAT('Note - ', cn.note_type) as title,
                SUBSTRING(cn.content, 1, 100) as description,
                'active' as status,
                NULL as amount,
                cn.created_by as user_id,
                u.fullname as user_name
            FROM client_notes cn
            LEFT JOIN user u ON u.id = cn.created_by
            WHERE cn.client_id = ?
            ORDER BY cn.created_at DESC
            LIMIT 50
        ";
        $st = $conn->prepare($sql);
        $st->execute([$client_id]);
        $notes = $st->fetchAll(PDO::FETCH_ASSOC);
        $timeline = array_merge($timeline, $notes);
    }
    
    // 6. Tasks
    if (empty($type_filter) || $type_filter === 'tasks') {
        $sql = "
            SELECT 
                'task' as type,
                ct.id as activity_id,
                DATE(ct.created_at) as date,
                ct.created_at as timestamp,
                CONCAT('Task - ', ct.task_type, ': ', ct.title) as title,
                CONCAT(SUBSTRING(ct.description, 1, 80), 
                       CASE WHEN ct.due_date IS NOT NULL THEN CONCAT(' (Due: ', ct.due_date, ')') ELSE '' END) as description,
                ct.status as status,
                NULL as amount,
                ct.assigned_to as user_id,
                u.fullname as user_name
            FROM client_tasks ct
            LEFT JOIN user u ON u.id = ct.assigned_to
            WHERE ct.client_id = ?
            ORDER BY ct.created_at DESC
            LIMIT 50
        ";
        $st = $conn->prepare($sql);
        $st->execute([$client_id]);
        $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
        $timeline = array_merge($timeline, $tasks);
    }
    
    // 7. Document uploads
    if (empty($type_filter) || $type_filter === 'documents') {
        $sql = "
            SELECT 
                'document' as type,
                cd.id as activity_id,
                DATE(cd.created_at) as date,
                cd.created_at as timestamp,
                CONCAT('Document: ', cd.file_name) as title,
                CONCAT('Type: ', cd.doc_type, 
                       CASE WHEN cd.expires_at IS NOT NULL THEN CONCAT(', Expires: ', cd.expires_at) ELSE '' END) as description,
                CASE WHEN cd.expires_at IS NOT NULL AND cd.expires_at < CURDATE() THEN 'expired' ELSE 'active' END as status,
                NULL as amount,
                cd.uploaded_by as user_id,
                u.fullname as user_name
            FROM client_documents cd
            LEFT JOIN user u ON u.id = cd.uploaded_by
            WHERE cd.client_id = ?
            ORDER BY cd.created_at DESC
            LIMIT 50
        ";
        $st = $conn->prepare($sql);
        $st->execute([$client_id]);
        $documents = $st->fetchAll(PDO::FETCH_ASSOC);
        $timeline = array_merge($timeline, $documents);
    }
    
    // 8. Quality ratings
    if (empty($type_filter) || $type_filter === 'ratings') {
        $sql = "
            SELECT 
                'rating' as type,
                cqr.id as activity_id,
                DATE(cqr.created_at) as date,
                cqr.created_at as timestamp,
                CONCAT('Rating: ', cqr.rating, '/5 stars') as title,
                CONCAT('Order #', cqr.order_id, 
                       CASE WHEN cqr.feedback IS NOT NULL AND cqr.feedback != '' THEN CONCAT(' - ', SUBSTRING(cqr.feedback, 1, 60)) ELSE '' END) as description,
                'completed' as status,
                NULL as amount,
                cqr.rated_by as user_id,
                u.fullname as user_name
            FROM client_quality_ratings cqr
            LEFT JOIN user u ON u.id = cqr.rated_by
            WHERE cqr.client_id = ?
            ORDER BY cqr.created_at DESC
            LIMIT 50
        ";
        $st = $conn->prepare($sql);
        $st->execute([$client_id]);
        $ratings = $st->fetchAll(PDO::FETCH_ASSOC);
        $timeline = array_merge($timeline, $ratings);
    }
    
    // Sort timeline by timestamp (most recent first)
    usort($timeline, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    // Limit final results
    $timeline = array_slice($timeline, 0, $limit);
    
    // Add activity icons and colors
    foreach ($timeline as &$item) {
        $item['icon'] = getActivityIcon($item['type']);
        $item['color'] = getActivityColor($item['type'], $item['status']);
        $item['formatted_date'] = formatTimelineDate($item['timestamp']);
    }
    
    echo json_encode([
        'success' => true,
        'timeline' => $timeline,
        'count' => count($timeline)
    ]);
    
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load timeline: ' . $e->getMessage()
    ]);
}

function getActivityIcon(string $type): string {
    return match($type) {
        'order' => 'bi-briefcase',
        'invoice' => 'bi-receipt',
        'payment' => 'bi-cash-coin',
        'comm_email' => 'bi-envelope',
        'comm_sms' => 'bi-chat-dots',
        'comm_whatsapp' => 'bi-whatsapp',
        'comm_call' => 'bi-telephone',
        'note' => 'bi-sticky',
        'task' => 'bi-check-square',
        'document' => 'bi-file-earmark',
        'rating' => 'bi-star',
        default => 'bi-circle'
    };
}

function getActivityColor(string $type, string $status): string {
    $base_colors = [
        'order' => '#0d6efd',
        'invoice' => '#fd7e14',
        'payment' => '#20c997',
        'comm_email' => '#6f42c1',
        'comm_sms' => '#198754',
        'comm_whatsapp' => '#25d366',
        'comm_call' => '#dc3545',
        'note' => '#6c757d',
        'task' => '#0dcaf0',
        'document' => '#ffc107',
        'rating' => '#fd7e14'
    ];
    
    $color = $base_colors[$type] ?? '#6c757d';
    
    // Adjust color based on status
    if ($status === 'cancelled' || $status === 'failed') {
        return '#dc3545';
    } elseif ($status === 'completed' || $status === 'paid') {
        return '#198754';
    } elseif ($status === 'expired') {
        return '#fd7e14';
    }
    
    return $color;
}

function formatTimelineDate(string $timestamp): string {
    $date = new DateTime($timestamp);
    $now = new DateTime();
    $diff = $now->diff($date);
    
    if ($diff->days === 0) {
        return 'Today at ' . $date->format('H:i');
    } elseif ($diff->days === 1) {
        return 'Yesterday at ' . $date->format('H:i');
    } elseif ($diff->days < 7) {
        return $diff->days . ' days ago';
    } elseif ($diff->days < 30) {
        $weeks = floor($diff->days / 7);
        return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
    } elseif ($diff->days < 365) {
        $months = floor($diff->days / 30);
        return $months . ' month' . ($months > 1 ? 's' : '') . ' ago';
    } else {
        return $date->format('M j, Y');
    }
}
