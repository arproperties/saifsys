<?php
// operation/ajax_client_preferences.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';

header('Content-Type: application/json');

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : (isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0);
$action = $_POST['action'] ?? $_GET['action'] ?? 'get';

if ($client_id <= 0 && $action !== 'add_rating') {
    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
    exit;
}

try {
    switch ($action) {
        case 'get':
            $result = getClientPreferences($conn, $client_id);
            break;
            
        case 'update':
            $result = updateClientPreferences($conn, $_POST);
            break;
            
        case 'quality_ratings':
            $result = getQualityRatings($conn, $client_id);
            break;
            
        case 'add_rating':
            $result = addQualityRating($conn, $_POST);
            break;
            
        case 'get_orders':
            $result = getClientOrders($conn, $client_id);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            exit;
    }
    
    echo json_encode($result);
    
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Action failed: ' . $e->getMessage()]);
}

function getClientPreferences(PDO $conn, int $client_id): array {
    $sql = "
        SELECT 
            preferred_workers,
            service_preferences,
            special_instructions
        FROM client
        WHERE id = ?
    ";
    
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $client = $st->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        return ['success' => false, 'error' => 'Client not found'];
    }
    
    // Parse JSON fields
    $preferences = [
        'preferred_workers' => $client['preferred_workers'] ? explode(',', $client['preferred_workers']) : [],
        'service_preferences' => $client['service_preferences'] ? json_decode($client['service_preferences'], true) : [],
        'special_instructions' => $client['special_instructions'] ?? ''
    ];
    
    // Get worker preferences from order history
    $worker_stats = getWorkerStats($conn, $client_id);
    
    // Get preferred time slots
    $time_stats = getTimePreferences($conn, $client_id);
    
    // Get service frequency
    $frequency_stats = getFrequencyStats($conn, $client_id);
    
    return [
        'success' => true,
        'preferences' => $preferences,
        'analytics' => [
            'workers' => $worker_stats,
            'time_slots' => $time_stats,
            'frequency' => $frequency_stats
        ]
    ];
}

function updateClientPreferences(PDO $conn, array $post): array {
    $client_id = (int)($post['client_id'] ?? 0);
    $preferred_workers = trim($post['preferred_workers'] ?? '');
    $special_instructions = trim($post['special_instructions'] ?? '');
    
    // Build service preferences JSON from form fields
    $service_preferences = [
        'service_frequency' => $post['service_frequency'] ?? 'as-needed',
        'vat_mode' => $post['vat_mode'] ?? 'yes',
        'time_slots' => $post['time_slots'] ?? [],
        'access_instructions' => trim($post['access_instructions'] ?? ''),
        'special_requirements' => trim($post['special_requirements'] ?? '')
    ];
    
    if ($client_id <= 0) {
        return ['success' => false, 'error' => 'Invalid client ID'];
    }
    
    $sql = "
        UPDATE client 
        SET preferred_workers = ?,
            service_preferences = ?,
            special_instructions = ?
        WHERE id = ?
    ";
    
    $st = $conn->prepare($sql);
    $ok = $st->execute([
        $preferred_workers,
        json_encode($service_preferences),
        $special_instructions,
        $client_id
    ]);
    
    if (!$ok) {
        return ['success' => false, 'error' => 'Failed to update preferences'];
    }
    
    return [
        'success' => true,
        'message' => 'Preferences updated successfully'
    ];
}

function getWorkerStats(PDO $conn, int $client_id): array {
    $sql = "
        SELECT 
            worker_name,
            COUNT(*) as order_count,
            AVG(hours) as avg_hours,
            AVG(grand_total) as avg_value,
            MAX(service_date) as last_service
        FROM make_order
        WHERE client_id = ? 
        AND status != 'cancelled'
        AND worker_name IS NOT NULL
        GROUP BY worker_name
        ORDER BY order_count DESC
        LIMIT 10
    ";
    
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function getTimePreferences(PDO $conn, int $client_id): array {
    $sql = "
        SELECT 
            time,
            COUNT(*) as frequency,
            DAYOFWEEK(service_date) as day_of_week
        FROM make_order
        WHERE client_id = ? 
        AND status != 'cancelled'
        AND service_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY time, day_of_week
        ORDER BY frequency DESC
        LIMIT 10
    ";
    
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $results = $st->fetchAll(PDO::FETCH_ASSOC);
    
    // Format day names
    $day_names = ['', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    foreach ($results as &$result) {
        $result['day_name'] = $day_names[$result['day_of_week']] ?? 'Unknown';
    }
    
    return $results;
}

function getFrequencyStats(PDO $conn, int $client_id): array {
    $sql = "
        SELECT 
            COUNT(*) as total_orders,
            MIN(service_date) as first_order,
            MAX(service_date) as last_order,
            DATEDIFF(MAX(service_date), MIN(service_date)) as days_span
        FROM make_order
        WHERE client_id = ? 
        AND status != 'cancelled'
    ";
    
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $stats = $st->fetch(PDO::FETCH_ASSOC);
    
    if ($stats['total_orders'] > 1 && $stats['days_span'] > 0) {
        $stats['avg_days_between_orders'] = $stats['days_span'] / ($stats['total_orders'] - 1);
        $stats['orders_per_month'] = ($stats['total_orders'] / $stats['days_span']) * 30;
    } else {
        $stats['avg_days_between_orders'] = 0;
        $stats['orders_per_month'] = 0;
    }
    
    return $stats;
}

function getQualityRatings(PDO $conn, int $client_id): array {
    $sql = "
        SELECT 
            qr.*,
            mo.service_date,
            mo.start_time,
            mo.end_time,
            mo.worker_name,
            mo.grand_total,
            u.fullname as rated_by_name
        FROM client_quality_ratings qr
        LEFT JOIN make_order mo ON mo.id = qr.order_id
        LEFT JOIN user u ON u.id = qr.rated_by
        WHERE qr.client_id = ?
        ORDER BY qr.created_at DESC
        LIMIT 50
    ";
    
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $ratings = $st->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate average ratings
    $avg_sql = "
        SELECT 
            AVG(quality_score) as avg_quality,
            AVG(timeliness_score) as avg_timeliness,
            AVG(professionalism_score) as avg_professionalism,
            COUNT(*) as total_ratings
        FROM client_quality_ratings
        WHERE client_id = ?
    ";
    
    $st = $conn->prepare($avg_sql);
    $st->execute([$client_id]);
    $averages = $st->fetch(PDO::FETCH_ASSOC);
    
    // Ensure numeric values for averages
    if ($averages) {
        $averages['avg_quality'] = $averages['avg_quality'] ? (float)$averages['avg_quality'] : 0;
        $averages['avg_timeliness'] = $averages['avg_timeliness'] ? (float)$averages['avg_timeliness'] : 0;
        $averages['avg_professionalism'] = $averages['avg_professionalism'] ? (float)$averages['avg_professionalism'] : 0;
        $averages['total_ratings'] = (int)$averages['total_ratings'];
    } else {
        $averages = [
            'avg_quality' => 0,
            'avg_timeliness' => 0,
            'avg_professionalism' => 0,
            'total_ratings' => 0
        ];
    }
    
    return [
        'success' => true,
        'ratings' => $ratings,
        'averages' => $averages
    ];
}

function addQualityRating(PDO $conn, array $post): array {
    $client_id = (int)($post['client_id'] ?? 0);
    $order_id = (int)($post['order_id'] ?? 0);
    $quality_score = (int)($post['quality_score'] ?? 0);
    $timeliness_score = (int)($post['timeliness_score'] ?? 0);
    $professionalism_score = (int)($post['professionalism_score'] ?? 0);
    $feedback = trim($post['feedback'] ?? '');
    $user_id = current_user_id();
    
    if ($client_id <= 0) {
        return ['success' => false, 'error' => 'Invalid client ID'];
    }
    
    if (!$user_id) {
        return ['success' => false, 'error' => 'User not authenticated'];
    }
    
    if ($quality_score < 1 || $quality_score > 5) {
        return ['success' => false, 'error' => 'Quality score must be between 1 and 5'];
    }
    
    if ($timeliness_score < 1 || $timeliness_score > 5) {
        return ['success' => false, 'error' => 'Timeliness score must be between 1 and 5'];
    }
    
    if ($professionalism_score < 1 || $professionalism_score > 5) {
        return ['success' => false, 'error' => 'Professionalism score must be between 1 and 5'];
    }
    
    $sql = "
        INSERT INTO client_quality_ratings 
        (client_id, order_id, quality_score, timeliness_score, professionalism_score, feedback, rated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ";
    
    $st = $conn->prepare($sql);
    $ok = $st->execute([
        $client_id,
        $order_id > 0 ? $order_id : null,
        $quality_score,
        $timeliness_score,
        $professionalism_score,
        $feedback,
        $user_id
    ]);
    
    if (!$ok) {
        return ['success' => false, 'error' => 'Failed to add rating'];
    }
    
    return [
        'success' => true,
        'message' => 'Quality rating added successfully'
    ];
}

function getClientOrders(PDO $conn, int $client_id): array {
    $sql = "
        SELECT 
            id,
            service_date,
            start_time,
            end_time,
            grand_total,
            status,
            CONCAT('Order #', id, ' - ', service_date, ' (', start_time, '-', end_time, ') - AED ', FORMAT(grand_total, 2)) as display_text
        FROM make_order 
        WHERE client_id = ? 
        AND status != 'cancelled'
        ORDER BY service_date DESC, id DESC
        LIMIT 50
    ";
    
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $orders = $st->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'success' => true,
        'orders' => $orders
    ];
}
