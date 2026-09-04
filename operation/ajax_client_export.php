<?php
// operation/ajax_client_export.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';

header('Content-Type: application/json');

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$format = $_GET['format'] ?? 'pdf';
$type = $_GET['type'] ?? 'profile';

if ($client_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
    exit;
}

try {
    switch ($format) {
        case 'pdf':
            $result = generatePDFReport($conn, $client_id, $type);
            break;
            
        case 'excel':
            $result = generateExcelReport($conn, $client_id, $type);
            break;
            
        case 'csv':
            $result = generateCSVReport($conn, $client_id, $type);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Unsupported format']);
            exit;
    }
    
    echo json_encode($result);
    
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Export failed: ' . $e->getMessage()]);
}

function generatePDFReport(PDO $conn, int $client_id, string $type): array {
    // For now, return a placeholder response
    // In a full implementation, you'd use a PDF library like TCPDF or mPDF
    return [
        'success' => true,
        'message' => 'PDF export functionality would be implemented here',
        'download_url' => '#',
        'filename' => "client_{$client_id}_report.pdf"
    ];
}

function generateExcelReport(PDO $conn, int $client_id, string $type): array {
    // For now, return a placeholder response
    // In a full implementation, you'd use a library like PhpSpreadsheet
    return [
        'success' => true,
        'message' => 'Excel export functionality would be implemented here',
        'download_url' => '#',
        'filename' => "client_{$client_id}_report.xlsx"
    ];
}

function generateCSVReport(PDO $conn, int $client_id, string $type): array {
    $client_data = getClientData($conn, $client_id);
    
    if (!$client_data) {
        return ['success' => false, 'error' => 'Client not found'];
    }
    
    // Generate CSV content
    $csv_content = generateCSVContent($client_data, $type);
    
    // Save to file
    $filename = "client_{$client_id}_" . date('Y-m-d') . ".csv";
    $filepath = __DIR__ . '/../uploads/exports/' . $filename;
    
    // Create directory if it doesn't exist
    $export_dir = dirname($filepath);
    if (!is_dir($export_dir)) {
        mkdir($export_dir, 0755, true);
    }
    
    file_put_contents($filepath, $csv_content);
    
    return [
        'success' => true,
        'message' => 'CSV exported successfully',
        'download_url' => '../uploads/exports/' . $filename,
        'filename' => $filename
    ];
}

function getClientData(PDO $conn, int $client_id): array {
    // Get comprehensive client data
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
    $client = $st->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        return null;
    }
    
    // Get recent orders
    $orders_sql = "
        SELECT mo.*, i.invoice_no, i.total as invoice_total
        FROM make_order mo
        LEFT JOIN invoices i ON i.order_id = mo.id
        WHERE mo.client_id = ?
        ORDER BY mo.service_date DESC
        LIMIT 20
    ";
    
    $st = $conn->prepare($orders_sql);
    $st->execute([$client_id]);
    $client['recent_orders'] = $st->fetchAll(PDO::FETCH_ASSOC);
    
    // Get invoices
    $invoices_sql = "
        SELECT 
            i.*,
            COALESCE(SUM(ra.amount_applied), 0) as paid_amount,
            (i.total - COALESCE(SUM(ra.amount_applied), 0)) as outstanding
        FROM invoices i
        LEFT JOIN receipt_allocations ra ON ra.invoice_id = i.id
        WHERE i.client_id = ?
        GROUP BY i.id
        ORDER BY i.issue_date DESC
        LIMIT 20
    ";
    
    $st = $conn->prepare($invoices_sql);
    $st->execute([$client_id]);
    $client['invoices'] = $st->fetchAll(PDO::FETCH_ASSOC);
    
    return $client;
}

function generateCSVContent(array $client_data, string $type): string {
    $output = '';
    
    switch ($type) {
        case 'profile':
            $output = generateProfileCSV($client_data);
            break;
            
        case 'orders':
            $output = generateOrdersCSV($client_data);
            break;
            
        case 'invoices':
            $output = generateInvoicesCSV($client_data);
            break;
            
        case 'complete':
            $output = generateCompleteCSV($client_data);
            break;
            
        default:
            $output = generateProfileCSV($client_data);
    }
    
    return $output;
}

function generateProfileCSV(array $client_data): string {
    $csv = "Client Profile Export\n";
    $csv .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    $csv .= "Basic Information\n";
    $csv .= "Client Name," . $client_data['client_name'] . "\n";
    $csv .= "Email," . $client_data['email'] . "\n";
    $csv .= "Mobile," . $client_data['mobile_num'] . "\n";
    $csv .= "Address," . $client_data['address'] . "\n";
    $csv .= "TRN," . $client_data['trn'] . "\n";
    $csv .= "Payment Terms," . $client_data['terms'] . "\n";
    $csv .= "Credit Limit," . $client_data['credit_limit'] . "\n\n";
    
    $csv .= "Summary Statistics\n";
    $csv .= "Total Orders," . $client_data['total_orders'] . "\n";
    $csv .= "Total Hours," . $client_data['total_hours'] . "\n";
    $csv .= "Total Spent," . $client_data['total_spent'] . "\n";
    $csv .= "First Order," . $client_data['first_order_date'] . "\n";
    $csv .= "Last Order," . $client_data['last_order_date'] . "\n";
    
    return $csv;
}

function generateOrdersCSV(array $client_data): string {
    $csv = "Client Orders Export\n";
    $csv .= "Client: " . $client_data['client_name'] . "\n";
    $csv .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    $csv .= "Order ID,Service Date,Time,Worker,Hours,Total,Invoice No,Status\n";
    
    foreach ($client_data['recent_orders'] as $order) {
        $csv .= sprintf(
            "%s,%s,%s,%s,%s,%s,%s,%s\n",
            $order['id'],
            $order['service_date'],
            $order['time'],
            $order['worker_name'],
            $order['hours'],
            $order['grand_total'],
            $order['invoice_no'] ?: 'N/A',
            $order['status']
        );
    }
    
    return $csv;
}

function generateInvoicesCSV(array $client_data): string {
    $csv = "Client Invoices Export\n";
    $csv .= "Client: " . $client_data['client_name'] . "\n";
    $csv .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    $csv .= "Invoice No,Issue Date,Due Date,Total,Paid,Outstanding,Status\n";
    
    foreach ($client_data['invoices'] as $invoice) {
        $csv .= sprintf(
            "%s,%s,%s,%s,%s,%s,%s\n",
            $invoice['invoice_no'],
            $invoice['issue_date'],
            $invoice['due_date'],
            $invoice['total'],
            $invoice['paid_amount'],
            $invoice['outstanding'],
            $invoice['status']
        );
    }
    
    return $csv;
}

function generateCompleteCSV(array $client_data): string {
    $csv = "Complete Client Report\n";
    $csv .= "Client: " . $client_data['client_name'] . "\n";
    $csv .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Client info
    $csv .= "=== CLIENT INFORMATION ===\n";
    $csv .= "Name: " . $client_data['client_name'] . "\n";
    $csv .= "Email: " . $client_data['email'] . "\n";
    $csv .= "Mobile: " . $client_data['mobile_num'] . "\n";
    $csv .= "Address: " . $client_data['address'] . "\n";
    $csv .= "TRN: " . $client_data['trn'] . "\n";
    $csv .= "Payment Terms: " . $client_data['terms'] . "\n";
    $csv .= "Credit Limit: " . $client_data['credit_limit'] . "\n\n";
    
    // Summary
    $csv .= "=== SUMMARY ===\n";
    $csv .= "Total Orders: " . $client_data['total_orders'] . "\n";
    $csv .= "Total Hours: " . $client_data['total_hours'] . "\n";
    $csv .= "Total Spent: " . $client_data['total_spent'] . "\n";
    $csv .= "First Order: " . $client_data['first_order_date'] . "\n";
    $csv .= "Last Order: " . $client_data['last_order_date'] . "\n\n";
    
    // Recent orders
    $csv .= "=== RECENT ORDERS ===\n";
    $csv .= "ID,Date,Time,Worker,Hours,Total,Invoice,Status\n";
    foreach ($client_data['recent_orders'] as $order) {
        $csv .= sprintf(
            "%s,%s,%s,%s,%s,%s,%s,%s\n",
            $order['id'],
            $order['service_date'],
            $order['time'],
            $order['worker_name'],
            $order['hours'],
            $order['grand_total'],
            $order['invoice_no'] ?: 'N/A',
            $order['status']
        );
    }
    
    $csv .= "\n=== INVOICES ===\n";
    $csv .= "Invoice No,Issue Date,Due Date,Total,Paid,Outstanding,Status\n";
    foreach ($client_data['invoices'] as $invoice) {
        $csv .= sprintf(
            "%s,%s,%s,%s,%s,%s,%s\n",
            $invoice['invoice_no'],
            $invoice['issue_date'],
            $invoice['due_date'],
            $invoice['total'],
            $invoice['paid_amount'],
            $invoice['outstanding'],
            $invoice['status']
        );
    }
    
    return $csv;
}
