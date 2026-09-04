<?php
/**
 * Dashboard Statistics API
 * Provides real-time data for dashboard widgets and charts
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/ar_helpers.php';
require_once __DIR__ . '/../includes/service_management_settings.php';
require_once __DIR__ . '/../includes/service_accounting_service.php';

header('Content-Type: application/json');

// Verify user is logged in
$hasUserObject = !empty($_SESSION['user']) && is_array($_SESSION['user']);
$hasLegacy = !empty($_SESSION['username']) || !empty($_SESSION['fullname']);
if (!$hasUserObject && !$hasLegacy) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? 'summary';
$days = (int)($_GET['days'] ?? 30); // Default to 30 days, can be changed to 7

try {
    switch ($action) {
        case 'summary':
            echo json_encode(getDashboardSummary($conn, $days));
            break;
        
        case 'revenue_vs_expenses':
            echo json_encode(getRevenueVsExpenses($conn, $days));
            break;
        
        case 'work_orders':
            echo json_encode(getWorkOrdersStats($conn, $days));
            break;
        
        case 'top_clients':
            echo json_encode(getTopClients($conn, $days));
            break;
        
        case 'recent_activity':
            echo json_encode(getRecentActivity($conn));
            break;
        
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Get dashboard summary metrics
 */
function getDashboardSummary($conn, $days) {
    $svc = new ServiceAccountingService($conn);
    return $svc->getDashboardSummary($days);
}

/**
 * Get revenue vs expenses chart data (daily)
 */
function getRevenueVsExpenses($conn, $days) {
    $startDate = date('Y-m-d', strtotime("-{$days} days"));
    
    // Revenue by day
    $revQuery = $conn->prepare("
        SELECT DATE(issue_date) as date, SUM(total) as amount
        FROM invoices
        WHERE status != 'void'
        AND issue_date >= ?
        GROUP BY DATE(issue_date)
        ORDER BY date ASC
    ");
    $revQuery->execute([$startDate]);
    $revenue = $revQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // Expenses by day
    $expQuery = $conn->prepare("
        SELECT DATE(expense_date) as date, SUM(total) as amount
        FROM expenses
        WHERE status = 'posted'
        AND expense_date >= ?
        GROUP BY DATE(expense_date)
        ORDER BY date ASC
    ");
    $expQuery->execute([$startDate]);
    $expenses = $expQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // Build complete date range
    $dates = [];
    $revenueMap = [];
    $expenseMap = [];
    
    foreach ($revenue as $r) {
        $revenueMap[$r['date']] = (float)$r['amount'];
    }
    foreach ($expenses as $e) {
        $expenseMap[$e['date']] = (float)$e['amount'];
    }
    
    // Fill all dates in range
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $dates[] = $date;
    }
    
    $revenueData = [];
    $expenseData = [];
    
    foreach ($dates as $date) {
        $revenueData[] = round($revenueMap[$date] ?? 0, 2);
        $expenseData[] = round($expenseMap[$date] ?? 0, 2);
    }
    
    return [
        'labels' => $dates,
        'revenue' => $revenueData,
        'expenses' => $expenseData
    ];
}

/**
 * Get work orders statistics
 */
function getWorkOrdersStats($conn, $days) {
    $startDate = date('Y-m-d', strtotime("-{$days} days"));
    
    $stats = $conn->prepare("
        SELECT 
            status,
            COUNT(*) as count,
            SUM(total) as amount
        FROM make_order
        WHERE date >= ?
        AND status != 'cancelled'
        GROUP BY status
    ");
    $stats->execute([$startDate]);
    $results = $stats->fetchAll(PDO::FETCH_ASSOC);
    
    $data = [
        'completed' => 0,
        'confirmed' => 0,
        'pending' => 0,
        'invoiced' => 0,
        'other' => 0
    ];
    
    $amounts = [
        'completed' => 0,
        'confirmed' => 0,
        'pending' => 0,
        'invoiced' => 0,
        'other' => 0
    ];
    
    foreach ($results as $r) {
        $status = strtolower($r['status'] ?? 'other');
        if (isset($data[$status])) {
            $data[$status] = (int)$r['count'];
            $amounts[$status] = (float)$r['amount'];
        } else {
            $data['other'] += (int)$r['count'];
            $amounts['other'] += (float)$r['amount'];
        }
    }
    
    return [
        'labels' => array_keys($data),
        'counts' => array_values($data),
        'amounts' => array_values($amounts)
    ];
}

/**
 * Get top 5 clients by billing
 */
function getTopClients($conn, $days) {
    $startDate = date('Y-m-d', strtotime("-{$days} days"));
    
    $query = $conn->prepare("
        SELECT 
            c.client_name,
            COUNT(i.id) as invoice_count,
            SUM(i.total) as total_billed
        FROM invoices i
        JOIN client c ON c.id = i.client_id
        WHERE i.status != 'void'
        AND i.issue_date >= ?
        GROUP BY c.id, c.client_name
        ORDER BY total_billed DESC
        LIMIT 5
    ");
    $query->execute([$startDate]);
    $results = $query->fetchAll(PDO::FETCH_ASSOC);
    
    $clients = [];
    $amounts = [];
    $invoiceCounts = [];
    
    foreach ($results as $r) {
        $clients[] = $r['client_name'];
        $amounts[] = round((float)$r['total_billed'], 2);
        $invoiceCounts[] = (int)$r['invoice_count'];
    }
    
    return [
        'clients' => $clients,
        'amounts' => $amounts,
        'invoiceCounts' => $invoiceCounts
    ];
}

/**
 * Get recent system activity
 */
function getRecentActivity($conn) {
    $activities = [];
    
    // Recent invoices
    $invs = $conn->query("
        SELECT 'invoice' as type, invoice_no as reference, issue_date as date, 
               total as amount, c.client_name
        FROM invoices i
        LEFT JOIN client c ON c.id = i.client_id
        WHERE i.status != 'void'
        ORDER BY i.id DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent work orders
    $wos = $conn->query("
        SELECT 'work_order' as type, id as reference, date, 
               total as amount, client_name
        FROM make_order
        WHERE status != 'cancelled'
        ORDER BY id DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent payments
    $pays = $conn->query("
        SELECT 'payment' as type, receipt_no as reference, receipt_date as date,
               amount, c.client_name
        FROM receipts r
        LEFT JOIN client c ON c.id = r.client_id
        ORDER BY r.id DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Merge and sort by date
    $activities = array_merge($invs, $wos, $pays);
    usort($activities, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    
    return array_slice($activities, 0, 10);
}

