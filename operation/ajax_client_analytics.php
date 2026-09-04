<?php
// operation/ajax_client_analytics.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';

header('Content-Type: application/json');

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$period = $_GET['period'] ?? '12months'; // 3months, 6months, 12months, 24months

if ($client_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
    exit;
}

try {
    // Calculate date ranges
    $end_date = date('Y-m-d');
    $start_date = match($period) {
        '3months' => date('Y-m-d', strtotime('-3 months')),
        '6months' => date('Y-m-d', strtotime('-6 months')),
        '12months' => date('Y-m-d', strtotime('-12 months')),
        '24months' => date('Y-m-d', strtotime('-24 months')),
        default => date('Y-m-d', strtotime('-12 months'))
    };

    $analytics = [];

    // 1. Revenue Trends (Monthly)
    $revenue_trends = [];
    $sql = "
        SELECT 
            DATE_FORMAT(mo.service_date, '%Y-%m') as month,
            SUM(mo.grand_total) as revenue,
            COUNT(*) as orders,
            SUM(mo.hours) as hours
        FROM make_order mo 
        WHERE mo.client_id = ? 
            AND mo.service_date BETWEEN ? AND ?
            AND mo.status != 'cancelled'
        GROUP BY DATE_FORMAT(mo.service_date, '%Y-%m')
        ORDER BY month ASC
    ";
    $st = $conn->prepare($sql);
    $st->execute([$client_id, $start_date, $end_date]);
    $revenue_data = $st->fetchAll(PDO::FETCH_ASSOC);

    // Fill missing months with zero values
    $months = [];
    $current = new DateTime($start_date);
    $end = new DateTime($end_date);
    
    while ($current <= $end) {
        $months[] = $current->format('Y-m');
        $current->modify('+1 month');
    }

    foreach ($months as $month) {
        $found = false;
        foreach ($revenue_data as $row) {
            if ($row['month'] === $month) {
                $revenue_trends[] = [
                    'month' => $month,
                    'revenue' => (float)$row['revenue'],
                    'orders' => (int)$row['orders'],
                    'hours' => (float)$row['hours']
                ];
                $found = true;
                break;
            }
        }
        if (!$found) {
            $revenue_trends[] = [
                'month' => $month,
                'revenue' => 0.0,
                'orders' => 0,
                'hours' => 0.0
            ];
        }
    }

    $analytics['revenue_trends'] = $revenue_trends;

    // 2. Order Distribution by Payment Status
    $sql = "
        SELECT 
            CASE 
                WHEN i.status = 'paid' THEN 'Paid'
                WHEN i.status = 'partially_paid' THEN 'Partially Paid'
                WHEN i.status = 'issued' THEN 'Outstanding'
                ELSE 'Other'
            END as payment_status,
            COUNT(*) as count,
            SUM(i.total) as amount
        FROM make_order mo
        LEFT JOIN invoices i ON i.order_id = mo.id
        WHERE mo.client_id = ? 
            AND mo.service_date BETWEEN ? AND ?
            AND mo.status != 'cancelled'
        GROUP BY i.status
    ";
    $st = $conn->prepare($sql);
    $st->execute([$client_id, $start_date, $end_date]);
    $order_distribution = $st->fetchAll(PDO::FETCH_ASSOC);
    $analytics['order_distribution'] = $order_distribution;

    // 3. Payment Pattern Analysis
    $sql = "
        SELECT 
            r.method as payment_method,
            COUNT(*) as count,
            SUM(r.amount) as total_amount,
            AVG(DATEDIFF(r.receipt_date, i.issue_date)) as avg_days_to_pay
        FROM receipts r
        JOIN receipt_allocations ra ON ra.receipt_id = r.id
        JOIN invoices i ON i.id = ra.invoice_id
        WHERE r.client_id = ? 
            AND r.receipt_date BETWEEN ? AND ?
        GROUP BY r.method
    ";
    $st = $conn->prepare($sql);
    $st->execute([$client_id, $start_date, $end_date]);
    $payment_patterns = $st->fetchAll(PDO::FETCH_ASSOC);
    $analytics['payment_patterns'] = $payment_patterns;

    // 4. Hours vs Revenue Correlation
    $sql = "
        SELECT 
            mo.hours,
            mo.grand_total as revenue,
            mo.service_date,
            mo.worker_name
        FROM make_order mo
        WHERE mo.client_id = ? 
            AND mo.service_date BETWEEN ? AND ?
            AND mo.status != 'cancelled'
            AND mo.hours > 0
        ORDER BY mo.service_date ASC
    ";
    $st = $conn->prepare($sql);
    $st->execute([$client_id, $start_date, $end_date]);
    $hours_revenue = $st->fetchAll(PDO::FETCH_ASSOC);
    $analytics['hours_revenue'] = $hours_revenue;

    // 5. Client Health Score Calculation
    $health_score = calculateClientHealthScore($conn, $client_id);
    $analytics['health_score'] = $health_score;

    // 6. AR Aging Analysis
    $sql = "
        SELECT 
            CASE 
                WHEN DATEDIFF(CURDATE(), i.issue_date) <= 30 THEN 'Current'
                WHEN DATEDIFF(CURDATE(), i.issue_date) <= 60 THEN '30-60 Days'
                WHEN DATEDIFF(CURDATE(), i.issue_date) <= 90 THEN '60-90 Days'
                ELSE '90+ Days'
            END as aging_bucket,
            COUNT(*) as invoice_count,
            SUM(i.total - COALESCE(ra_paid.paid_amount, 0)) as outstanding_amount
        FROM invoices i
        LEFT JOIN (
            SELECT invoice_id, SUM(amount_applied) as paid_amount
            FROM receipt_allocations
            GROUP BY invoice_id
        ) ra_paid ON ra_paid.invoice_id = i.id
        WHERE i.client_id = ?
            AND i.status IN ('issued', 'partially_paid')
        GROUP BY aging_bucket
        ORDER BY 
            CASE aging_bucket
                WHEN 'Current' THEN 1
                WHEN '30-60 Days' THEN 2
                WHEN '60-90 Days' THEN 3
                WHEN '90+ Days' THEN 4
            END
    ";
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $ar_aging = $st->fetchAll(PDO::FETCH_ASSOC);
    $analytics['ar_aging'] = $ar_aging;

    // 7. Service Frequency Analysis
    $sql = "
        SELECT 
            DAYOFWEEK(mo.service_date) as day_of_week,
            HOUR(mo.start_time) as hour_of_day,
            COUNT(*) as frequency
        FROM make_order mo
        WHERE mo.client_id = ? 
            AND mo.service_date BETWEEN ? AND ?
            AND mo.status != 'cancelled'
            AND mo.start_time IS NOT NULL
        GROUP BY DAYOFWEEK(mo.service_date), HOUR(mo.start_time)
        ORDER BY frequency DESC
        LIMIT 10
    ";
    $st = $conn->prepare($sql);
    $st->execute([$client_id, $start_date, $end_date]);
    $service_frequency = $st->fetchAll(PDO::FETCH_ASSOC);
    $analytics['service_frequency'] = $service_frequency;

    // 8. Worker Performance per Client
    $sql = "
        SELECT 
            mo.worker_name,
            COUNT(*) as orders,
            SUM(mo.hours) as total_hours,
            SUM(mo.grand_total) as total_revenue,
            AVG(mo.grand_total / NULLIF(mo.hours, 0)) as avg_hourly_rate,
            AVG(COALESCE(qr.rating, 0)) as avg_rating
        FROM make_order mo
        LEFT JOIN client_quality_ratings qr ON qr.order_id = mo.id
        WHERE mo.client_id = ? 
            AND mo.service_date BETWEEN ? AND ?
            AND mo.status != 'cancelled'
            AND mo.worker_name IS NOT NULL
        GROUP BY mo.worker_name
        ORDER BY total_revenue DESC
    ";
    $st = $conn->prepare($sql);
    $st->execute([$client_id, $start_date, $end_date]);
    $worker_performance = $st->fetchAll(PDO::FETCH_ASSOC);
    $analytics['worker_performance'] = $worker_performance;

    // 9. Summary KPIs
    $total_revenue = array_sum(array_column($revenue_trends, 'revenue'));
    $total_orders = array_sum(array_column($revenue_trends, 'orders'));
    $total_hours = array_sum(array_column($revenue_trends, 'hours'));
    $avg_order_value = $total_orders > 0 ? $total_revenue / $total_orders : 0;
    $avg_hourly_rate = $total_hours > 0 ? $total_revenue / $total_hours : 0;

    // Calculate trends vs previous period
    $prev_start = date('Y-m-d', strtotime($start_date . ' -' . (strtotime($end_date) - strtotime($start_date)) . ' days'));
    $prev_end = $start_date;

    $sql = "
        SELECT 
            SUM(mo.grand_total) as prev_revenue,
            COUNT(*) as prev_orders,
            SUM(mo.hours) as prev_hours
        FROM make_order mo 
        WHERE mo.client_id = ? 
            AND mo.service_date BETWEEN ? AND ?
            AND mo.status != 'cancelled'
    ";
    $st = $conn->prepare($sql);
    $st->execute([$client_id, $prev_start, $prev_end]);
    $prev_data = $st->fetch(PDO::FETCH_ASSOC);

    $revenue_trend = $prev_data['prev_revenue'] > 0 ? 
        (($total_revenue - $prev_data['prev_revenue']) / $prev_data['prev_revenue']) * 100 : 0;
    
    $orders_trend = $prev_data['prev_orders'] > 0 ? 
        (($total_orders - $prev_data['prev_orders']) / $prev_data['prev_orders']) * 100 : 0;

    $analytics['summary'] = [
        'total_revenue' => round($total_revenue, 2),
        'total_orders' => $total_orders,
        'total_hours' => round($total_hours, 2),
        'avg_order_value' => round($avg_order_value, 2),
        'avg_hourly_rate' => round($avg_hourly_rate, 2),
        'revenue_trend' => round($revenue_trend, 1),
        'orders_trend' => round($orders_trend, 1),
        'period' => $period,
        'date_range' => ['start' => $start_date, 'end' => $end_date]
    ];

    echo json_encode([
        'success' => true,
        'analytics' => $analytics
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to generate analytics: ' . $e->getMessage()
    ]);
}

/**
 * Calculate comprehensive client health score
 */
function calculateClientHealthScore(PDO $conn, int $client_id): array {
    $score = 50; // Base score
    $factors = [];

    // 1. Payment behavior (30% weight)
    $sql = "
        SELECT 
            COUNT(*) as total_invoices,
            AVG(DATEDIFF(r.receipt_date, i.issue_date)) as avg_days_to_pay,
            SUM(CASE WHEN DATEDIFF(r.receipt_date, i.issue_date) <= 30 THEN 1 ELSE 0 END) as on_time_payments
        FROM invoices i
        LEFT JOIN receipt_allocations ra ON ra.invoice_id = i.id
        LEFT JOIN receipts r ON r.id = ra.receipt_id
        WHERE i.client_id = ?
        AND i.issue_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    ";
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $payment_data = $st->fetch(PDO::FETCH_ASSOC);

    if ($payment_data['total_invoices'] > 0) {
        $on_time_rate = ($payment_data['on_time_payments'] / $payment_data['total_invoices']) * 100;
        $payment_score = min(100, $on_time_rate);
        $factors['payment_behavior'] = [
            'score' => $payment_score,
            'weight' => 30,
            'details' => [
                'on_time_rate' => round($on_time_rate, 1),
                'avg_days_to_pay' => round($payment_data['avg_days_to_pay'] ?? 0, 1)
            ]
        ];
        $score += ($payment_score - 50) * 0.3;
    }

    // 2. Order frequency (25% weight)
    $sql = "
        SELECT COUNT(*) as orders_last_6m
        FROM make_order 
        WHERE client_id = ? 
        AND service_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        AND status != 'cancelled'
    ";
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $order_count = (int)$st->fetchColumn();

    $frequency_score = min(100, $order_count * 10); // 10 orders = 100 points
    $factors['order_frequency'] = [
        'score' => $frequency_score,
        'weight' => 25,
        'details' => ['orders_last_6m' => $order_count]
    ];
    $score += ($frequency_score - 50) * 0.25;

    // 3. Outstanding balance (25% weight)
    $sql = "
        SELECT SUM(i.total - COALESCE(ra_paid.paid_amount, 0)) as outstanding
        FROM invoices i
        LEFT JOIN (
            SELECT invoice_id, SUM(amount_applied) as paid_amount
            FROM receipt_allocations
            GROUP BY invoice_id
        ) ra_paid ON ra_paid.invoice_id = i.id
        WHERE i.client_id = ?
        AND i.status IN ('issued', 'partially_paid')
    ";
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $outstanding = (float)$st->fetchColumn();

    // Get client's credit limit
    $sql = "SELECT credit_limit FROM client WHERE id = ?";
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $credit_limit = (float)$st->fetchColumn();

    $balance_score = 100;
    if ($credit_limit > 0 && $outstanding > 0) {
        $utilization = ($outstanding / $credit_limit) * 100;
        $balance_score = max(0, 100 - ($utilization * 1.5)); // Penalty for high utilization
    } elseif ($outstanding > 1000) { // Penalty for high absolute outstanding
        $balance_score = max(0, 100 - ($outstanding / 100));
    }

    $factors['outstanding_balance'] = [
        'score' => $balance_score,
        'weight' => 25,
        'details' => [
            'outstanding' => round($outstanding, 2),
            'credit_limit' => $credit_limit,
            'utilization' => $credit_limit > 0 ? round(($outstanding / $credit_limit) * 100, 1) : 0
        ]
    ];
    $score += ($balance_score - 50) * 0.25;

    // 4. Recent activity (20% weight)
    $sql = "
        SELECT MAX(service_date) as last_order,
               MAX(r.receipt_date) as last_payment
        FROM make_order mo
        LEFT JOIN invoices i ON i.order_id = mo.id
        LEFT JOIN receipt_allocations ra ON ra.invoice_id = i.id
        LEFT JOIN receipts r ON r.id = ra.receipt_id
        WHERE mo.client_id = ?
    ";
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $activity_data = $st->fetch(PDO::FETCH_ASSOC);

    $days_since_order = $activity_data['last_order'] ? 
        (strtotime(date('Y-m-d')) - strtotime($activity_data['last_order'])) / 86400 : 365;
    $days_since_payment = $activity_data['last_payment'] ? 
        (strtotime(date('Y-m-d')) - strtotime($activity_data['last_payment'])) / 86400 : 365;

    $activity_score = 100;
    if ($days_since_order > 90) $activity_score -= 40;
    elseif ($days_since_order > 60) $activity_score -= 20;
    elseif ($days_since_order > 30) $activity_score -= 10;

    if ($days_since_payment > 60) $activity_score -= 20;
    elseif ($days_since_payment > 30) $activity_score -= 10;

    $factors['recent_activity'] = [
        'score' => max(0, $activity_score),
        'weight' => 20,
        'details' => [
            'days_since_order' => (int)$days_since_order,
            'days_since_payment' => (int)$days_since_payment
        ]
    ];
    $score += (max(0, $activity_score) - 50) * 0.2;

    // Normalize final score
    $final_score = max(0, min(100, round($score)));

    // Determine risk level
    $risk_level = match(true) {
        $final_score >= 80 => 'Low Risk',
        $final_score >= 60 => 'Medium Risk',
        $final_score >= 40 => 'High Risk',
        default => 'Critical Risk'
    };

    return [
        'overall_score' => $final_score,
        'risk_level' => $risk_level,
        'factors' => $factors,
        'recommendations' => generateRecommendations($factors, $final_score)
    ];
}

function generateRecommendations(array $factors, int $score): array {
    $recommendations = [];

    if (isset($factors['payment_behavior']) && $factors['payment_behavior']['score'] < 70) {
        $recommendations[] = "Consider implementing stricter payment terms or requiring deposits";
    }

    if (isset($factors['order_frequency']) && $factors['order_frequency']['score'] < 60) {
        $recommendations[] = "Client shows low activity - consider outreach for re-engagement";
    }

    if (isset($factors['outstanding_balance']) && $factors['outstanding_balance']['details']['utilization'] > 80) {
        $recommendations[] = "High credit utilization - monitor closely or reduce credit limit";
    }

    if (isset($factors['recent_activity']) && $factors['recent_activity']['details']['days_since_order'] > 60) {
        $recommendations[] = "No recent orders - schedule follow-up call";
    }

    if ($score >= 80) {
        $recommendations[] = "Excellent client - consider for VIP status or upsell opportunities";
    }

    return $recommendations;
}
