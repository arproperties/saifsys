<?php
// operation/ajax_client_insights.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';

header('Content-Type: application/json');

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

if ($client_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
    exit;
}

try {
    $insights = generateClientInsights($conn, $client_id);
    
    echo json_encode([
        'success' => true,
        'insights' => $insights
    ]);
    
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Failed to generate insights: ' . $e->getMessage()]);
}

function generateClientInsights(PDO $conn, int $client_id): array {
    $insights = [];
    
    // Get client basic info
    $client = getClientInfo($conn, $client_id);
    
    // Get order patterns
    $orderPatterns = analyzeOrderPatterns($conn, $client_id);
    
    // Get payment behavior
    $paymentBehavior = analyzePaymentBehavior($conn, $client_id);
    
    // Get service preferences
    $servicePreferences = analyzeServicePreferences($conn, $client_id);
    
    // Get worker preferences
    $workerPreferences = analyzeWorkerPreferences($conn, $client_id);
    
    // Generate insights based on analysis
    $insights = array_merge(
        generateOrderInsights($client, $orderPatterns),
        generatePaymentInsights($client, $paymentBehavior),
        generateServiceInsights($client, $servicePreferences),
        generateWorkerInsights($client, $workerPreferences),
        generateRiskInsights($client, $orderPatterns, $paymentBehavior)
    );
    
    // Sort insights by priority
    usort($insights, function($a, $b) {
        return $b['priority'] - $a['priority'];
    });
    
    return $insights;
}

function getClientInfo(PDO $conn, int $client_id): array {
    $sql = "
        SELECT 
            c.*,
            COUNT(mo.id) as total_orders,
            COALESCE(SUM(mo.hours), 0) as total_hours,
            COALESCE(SUM(mo.grand_total), 0) as total_spent,
            MAX(mo.service_date) as last_order_date,
            MIN(mo.service_date) as first_order_date
        FROM client c
        LEFT JOIN make_order mo ON mo.client_id = c.id AND mo.status != 'cancelled'
        WHERE c.id = ?
        GROUP BY c.id
    ";
    
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

function analyzeOrderPatterns(PDO $conn, int $client_id): array {
    $sql = "
        SELECT 
            mo.service_date,
            mo.time,
            mo.hours,
            mo.grand_total,
            DAYOFWEEK(mo.service_date) as day_of_week,
            HOUR(STR_TO_DATE(mo.time, '%H:%i')) as hour_of_day,
            mo.worker_name,
            mo.status
        FROM make_order mo
        WHERE mo.client_id = ? 
        AND mo.status != 'cancelled'
        AND mo.service_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        ORDER BY mo.service_date DESC
    ";
    
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $orders = $st->fetchAll(PDO::FETCH_ASSOC);
    
    $patterns = [
        'total_orders' => count($orders),
        'avg_order_value' => 0,
        'avg_hours' => 0,
        'preferred_days' => [],
        'preferred_times' => [],
        'frequency' => 0,
        'recent_trend' => 'stable'
    ];
    
    if (empty($orders)) {
        return $patterns;
    }
    
    // Calculate averages
    $total_value = array_sum(array_column($orders, 'grand_total'));
    $total_hours = array_sum(array_column($orders, 'hours'));
    
    $patterns['avg_order_value'] = $total_value / count($orders);
    $patterns['avg_hours'] = $total_hours / count($orders);
    
    // Analyze day preferences
    $days = array_filter(array_column($orders, 'day_of_week'), function($value) {
        return $value !== null && $value !== '';
    });
    $day_counts = array_count_values($days);
    $patterns['preferred_days'] = $day_counts;
    
    // Analyze time preferences
    $hours = array_filter(array_column($orders, 'hour_of_day'), function($value) {
        return $value !== null && $value !== '';
    });
    $time_counts = array_count_values($hours);
    $patterns['preferred_times'] = $time_counts;
    
    // Calculate frequency (orders per month)
    if (count($orders) >= 2) {
        $first_date = new DateTime($orders[count($orders) - 1]['service_date']);
        $last_date = new DateTime($orders[0]['service_date']);
        $months = $first_date->diff($last_date)->m + ($first_date->diff($last_date)->y * 12);
        $patterns['frequency'] = $months > 0 ? count($orders) / max(1, $months) : 0;
    }
    
    // Analyze recent trend
    if (count($orders) >= 4) {
        $recent_orders = array_slice($orders, 0, 3);
        $older_orders = array_slice($orders, 3, 3);
        
        $recent_avg = array_sum(array_column($recent_orders, 'grand_total')) / count($recent_orders);
        $older_avg = array_sum(array_column($older_orders, 'grand_total')) / count($older_orders);
        
        if ($recent_avg > $older_avg * 1.1) {
            $patterns['recent_trend'] = 'increasing';
        } elseif ($recent_avg < $older_avg * 0.9) {
            $patterns['recent_trend'] = 'decreasing';
        }
    }
    
    return $patterns;
}

function analyzePaymentBehavior(PDO $conn, int $client_id): array {
    $sql = "
        SELECT 
            i.issue_date,
            i.total,
            i.due_date,
            COALESCE(SUM(ra.amount_applied), 0) as paid_amount,
            DATEDIFF(
                COALESCE(MAX(r.receipt_date), CURDATE()), 
                i.issue_date
            ) as days_to_pay
        FROM invoices i
        LEFT JOIN receipt_allocations ra ON ra.invoice_id = i.id
        LEFT JOIN receipts r ON r.id = ra.receipt_id
        WHERE i.client_id = ?
        GROUP BY i.id
        ORDER BY i.issue_date DESC
    ";
    
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $invoices = $st->fetchAll(PDO::FETCH_ASSOC);
    
    $behavior = [
        'total_invoices' => count($invoices),
        'avg_payment_days' => 0,
        'on_time_percentage' => 0,
        'payment_consistency' => 'good',
        'outstanding_amount' => 0
    ];
    
    if (empty($invoices)) {
        return $behavior;
    }
    
    $payment_days = [];
    $on_time_count = 0;
    $total_outstanding = 0;
    
    foreach ($invoices as $invoice) {
        $total = (float)$invoice['total'];
        $paid = (float)$invoice['paid_amount'];
        $days_to_pay = (int)$invoice['days_to_pay'];
        
        if ($paid > 0) {
            $payment_days[] = $days_to_pay;
            if ($days_to_pay <= 30) { // Assuming 30-day terms
                $on_time_count++;
            }
        }
        
        if ($paid < $total) {
            $total_outstanding += ($total - $paid);
        }
    }
    
    if (!empty($payment_days)) {
        $behavior['avg_payment_days'] = array_sum($payment_days) / count($payment_days);
        $behavior['on_time_percentage'] = ($on_time_count / count($payment_days)) * 100;
        
        // Determine payment consistency
        $std_dev = calculateStandardDeviation($payment_days);
        if ($std_dev < 7) {
            $behavior['payment_consistency'] = 'excellent';
        } elseif ($std_dev < 14) {
            $behavior['payment_consistency'] = 'good';
        } else {
            $behavior['payment_consistency'] = 'variable';
        }
    }
    
    $behavior['outstanding_amount'] = $total_outstanding;
    
    return $behavior;
}

function analyzeServicePreferences(PDO $conn, int $client_id): array {
    // This would analyze service types, frequencies, special requirements
    // For now, we'll return basic preferences based on order data
    return [
        'preferred_frequency' => 'weekly',
        'service_type' => 'standard_cleaning',
        'special_requirements' => []
    ];
}

function analyzeWorkerPreferences(PDO $conn, int $client_id): array {
    $sql = "
        SELECT 
            mo.worker_name,
            COUNT(*) as order_count,
            AVG(mo.grand_total) as avg_value,
            AVG(mo.hours) as avg_hours
        FROM make_order mo
        WHERE mo.client_id = ? 
        AND mo.status != 'cancelled'
        AND mo.worker_name IS NOT NULL
        GROUP BY mo.worker_name
        ORDER BY order_count DESC
    ";
    
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $workers = $st->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'preferred_workers' => $workers,
        'worker_consistency' => count($workers) <= 2 ? 'high' : 'variable'
    ];
}

function generateOrderInsights(array $client, array $patterns): array {
    $insights = [];
    
    if ($patterns['total_orders'] == 0) {
        $insights[] = [
            'type' => 'warning',
            'title' => 'New Client',
            'message' => 'This is a new client with no order history yet.',
            'priority' => 5,
            'action' => 'Consider reaching out to schedule their first service.'
        ];
        return $insights;
    }
    
    // Order frequency insights
    if ($patterns['frequency'] < 0.5) {
        $insights[] = [
            'type' => 'info',
            'title' => 'Low Service Frequency',
            'message' => "Client orders services approximately " . number_format($patterns['frequency'], 1) . " times per month. Consider offering more frequent service options.",
            'priority' => 6,
            'action' => 'Propose weekly or bi-weekly service packages.'
        ];
    } elseif ($patterns['frequency'] > 2) {
        $insights[] = [
            'type' => 'success',
            'title' => 'High-Value Client',
            'message' => "Client orders services " . number_format($patterns['frequency'], 1) . " times per month - excellent engagement!",
            'priority' => 8,
            'action' => 'Consider VIP status or loyalty rewards.'
        ];
    }
    
    // Order trend insights
    if ($patterns['recent_trend'] == 'increasing') {
        $insights[] = [
            'type' => 'success',
            'title' => 'Growing Client',
            'message' => 'Recent orders show increasing value - client satisfaction is high.',
            'priority' => 7,
            'action' => 'Perfect time to introduce premium services.'
        ];
    } elseif ($patterns['recent_trend'] == 'decreasing') {
        $insights[] = [
            'type' => 'warning',
            'title' => 'Decreasing Engagement',
            'message' => 'Recent orders show declining value. Client may be considering alternatives.',
            'priority' => 9,
            'action' => 'Reach out to address any concerns and offer incentives.'
        ];
    }
    
    return $insights;
}

function generatePaymentInsights(array $client, array $behavior): array {
    $insights = [];
    
    if ($behavior['total_invoices'] == 0) {
        return $insights;
    }
    
    // Payment speed insights
    if ($behavior['avg_payment_days'] > 45) {
        $insights[] = [
            'type' => 'warning',
            'title' => 'Slow Payer',
            'message' => "Average payment time is " . number_format($behavior['avg_payment_days'], 0) . " days. Consider shorter payment terms.",
            'priority' => 8,
            'action' => 'Offer early payment discounts or require advance payments.'
        ];
    } elseif ($behavior['avg_payment_days'] < 15) {
        $insights[] = [
            'type' => 'success',
            'title' => 'Excellent Payer',
            'message' => "Average payment time is " . number_format($behavior['avg_payment_days'], 0) . " days - very reliable client.",
            'priority' => 6,
            'action' => 'Consider extending credit limit or offering preferred client benefits.'
        ];
    }
    
    // Outstanding balance insights
    if ($behavior['outstanding_amount'] > 5000) {
        $insights[] = [
            'type' => 'danger',
            'title' => 'High Outstanding Balance',
            'message' => "Outstanding balance is AED " . number_format($behavior['outstanding_amount'], 2) . ". Immediate attention required.",
            'priority' => 10,
            'action' => 'Contact client immediately and consider suspending services until payment.'
        ];
    }
    
    return $insights;
}

function generateServiceInsights(array $client, array $preferences): array {
    $insights = [];
    
    // This would contain more sophisticated service preference analysis
    $insights[] = [
        'type' => 'info',
        'title' => 'Service Preferences',
        'message' => 'Client prefers standard cleaning services with weekly frequency.',
        'priority' => 4,
        'action' => 'Consider offering package deals for regular service.'
    ];
    
    return $insights;
}

function generateWorkerInsights(array $client, array $preferences): array {
    $insights = [];
    
    if (empty($preferences['preferred_workers'])) {
        return $insights;
    }
    
    $top_worker = $preferences['preferred_workers'][0];
    
    $insights[] = [
        'type' => 'info',
        'title' => 'Worker Preference',
        'message' => "Client prefers {$top_worker['worker_name']} (used for {$top_worker['order_count']} orders).",
        'priority' => 5,
        'action' => 'Try to assign this worker for future bookings when possible.'
    ];
    
    if ($preferences['worker_consistency'] == 'high') {
        $insights[] = [
            'type' => 'success',
            'title' => 'High Worker Loyalty',
            'message' => 'Client shows strong preference for specific workers - great for relationship building.',
            'priority' => 6,
            'action' => 'Maintain worker consistency to ensure client satisfaction.'
        ];
    }
    
    return $insights;
}

function generateRiskInsights(array $client, array $orderPatterns, array $paymentBehavior): array {
    $insights = [];
    
    // Calculate overall risk score
    $risk_factors = 0;
    $risk_message = '';
    
    if ($orderPatterns['recent_trend'] == 'decreasing') $risk_factors++;
    if ($paymentBehavior['avg_payment_days'] > 30) $risk_factors++;
    if ($paymentBehavior['outstanding_amount'] > 2000) $risk_factors++;
    if ($orderPatterns['total_orders'] == 0) $risk_factors++;
    
    if ($risk_factors >= 3) {
        $insights[] = [
            'type' => 'danger',
            'title' => 'High Risk Client',
            'message' => 'Multiple risk factors detected. Immediate attention required.',
            'priority' => 10,
            'action' => 'Schedule a call to address concerns and improve relationship.'
        ];
    } elseif ($risk_factors >= 2) {
        $insights[] = [
            'type' => 'warning',
            'title' => 'At-Risk Client',
            'message' => 'Some risk factors present. Monitor closely.',
            'priority' => 7,
            'action' => 'Proactive outreach to maintain relationship.'
        ];
    }
    
    return $insights;
}

function calculateStandardDeviation(array $values): float {
    $mean = array_sum($values) / count($values);
    $variance = array_sum(array_map(function($x) use ($mean) {
        return pow($x - $mean, 2);
    }, $values)) / count($values);
    
    return sqrt($variance);
}
