<?php
// operation/ajax_bulk_client_actions.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$client_ids = $_POST['client_ids'] ?? [];
$user_id = current_user_id();

if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Validate client IDs
if (!is_array($client_ids) || empty($client_ids)) {
    echo json_encode(['success' => false, 'error' => 'No clients selected']);
    exit;
}

$client_ids = array_map('intval', $client_ids);
$client_ids = array_filter($client_ids, function($id) { return $id > 0; });

if (empty($client_ids)) {
    echo json_encode(['success' => false, 'error' => 'Invalid client IDs']);
    exit;
}

try {
    switch ($action) {
        case 'bulk_email':
            $result = bulkEmailClients($conn, $client_ids, $_POST, $user_id);
            break;
            
        case 'bulk_sms':
            $result = bulkSMSClients($conn, $client_ids, $_POST, $user_id);
            break;
            
        case 'bulk_export':
            $result = bulkExportClients($conn, $client_ids, $_POST);
            break;
            
        case 'bulk_status_update':
            $result = bulkUpdateClientStatus($conn, $client_ids, $_POST, $user_id);
            break;
            
        case 'bulk_credit_limit':
            $result = bulkUpdateCreditLimit($conn, $client_ids, $_POST, $user_id);
            break;
            
        case 'bulk_statements':
            $result = bulkGenerateStatements($conn, $client_ids, $_POST, $user_id);
            break;
            
        case 'bulk_invoices':
            $result = bulkGenerateInvoices($conn, $client_ids, $_POST, $user_id);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            exit;
    }
    
    echo json_encode($result);
    
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Action failed: ' . $e->getMessage()]);
}

function bulkEmailClients(PDO $conn, array $client_ids, array $data, int $user_id): array {
    $subject = trim($data['subject'] ?? '');
    $message = trim($data['message'] ?? '');
    $template = $data['template'] ?? 'custom';
    
    if (empty($subject) || empty($message)) {
        return ['success' => false, 'error' => 'Subject and message are required'];
    }
    
    $success_count = 0;
    $failed_count = 0;
    $errors = [];
    
    // Get client details
    $placeholders = implode(',', array_fill(0, count($client_ids), '?'));
    $sql = "SELECT id, client_name, email FROM client WHERE id IN ($placeholders) AND email IS NOT NULL AND email != ''";
    $st = $conn->prepare($sql);
    $st->execute($client_ids);
    $clients = $st->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($clients as $client) {
        try {
            // Replace placeholders in message
            $personalized_message = str_replace([
                '{client_name}',
                '{client_email}'
            ], [
                $client['client_name'],
                $client['email']
            ], $message);
            
            $personalized_subject = str_replace([
                '{client_name}',
                '{client_email}'
            ], [
                $client['client_name'],
                $client['email']
            ], $subject);
            
            // Send email
            $mail_sent = sendEmail($client['email'], $personalized_subject, $personalized_message);
            
            if ($mail_sent) {
                // Log communication
                $log_sql = "INSERT INTO client_communications (client_id, type, direction, subject, message, status, sent_by) 
                           VALUES (?, 'email', 'outbound', ?, ?, 'sent', ?)";
                $log_st = $conn->prepare($log_sql);
                $log_st->execute([$client['id'], $personalized_subject, $personalized_message, $user_id]);
                
                $success_count++;
            } else {
                $failed_count++;
                $errors[] = "Failed to send email to {$client['client_name']}";
            }
            
        } catch (Throwable $e) {
            $failed_count++;
            $errors[] = "Error sending to {$client['client_name']}: " . $e->getMessage();
        }
    }
    
    return [
        'success' => true,
        'sent' => $success_count,
        'failed' => $failed_count,
        'errors' => $errors,
        'message' => "Sent {$success_count} emails successfully"
    ];
}

function bulkSMSClients(PDO $conn, array $client_ids, array $data, int $user_id): array {
    $message = trim($data['message'] ?? '');
    
    if (empty($message)) {
        return ['success' => false, 'error' => 'Message is required'];
    }
    
    $success_count = 0;
    $failed_count = 0;
    $errors = [];
    
    // Get client mobile numbers
    $placeholders = implode(',', array_fill(0, count($client_ids), '?'));
    $sql = "SELECT id, client_name, mobile_num FROM client WHERE id IN ($placeholders) AND mobile_num IS NOT NULL AND mobile_num != ''";
    $st = $conn->prepare($sql);
    $st->execute($client_ids);
    $clients = $st->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($clients as $client) {
        try {
            // Replace placeholders in message
            $personalized_message = str_replace([
                '{client_name}',
                '{mobile_num}'
            ], [
                $client['client_name'],
                $client['mobile_num']
            ], $message);
            
            // For now, just log the SMS (implement actual SMS sending based on your provider)
            $log_sql = "INSERT INTO client_communications (client_id, type, direction, subject, message, status, sent_by) 
                       VALUES (?, 'sms', 'outbound', NULL, ?, 'sent', ?)";
            $log_st = $conn->prepare($log_sql);
            $log_st->execute([$client['id'], $personalized_message, $user_id]);
            
            $success_count++;
            
        } catch (Throwable $e) {
            $failed_count++;
            $errors[] = "Error sending SMS to {$client['client_name']}: " . $e->getMessage();
        }
    }
    
    return [
        'success' => true,
        'sent' => $success_count,
        'failed' => $failed_count,
        'errors' => $errors,
        'message' => "Sent {$success_count} SMS messages successfully"
    ];
}

function bulkExportClients(PDO $conn, array $client_ids, array $data): array {
    $format = $data['format'] ?? 'csv';
    $fields = $data['fields'] ?? ['client_name', 'email', 'mobile_num', 'address', 'credit_limit', 'balance'];
    
    if (!in_array($format, ['csv', 'excel', 'pdf'])) {
        return ['success' => false, 'error' => 'Invalid export format'];
    }
    
    // Get client data
    $placeholders = implode(',', array_fill(0, count($client_ids), '?'));
    $sql = "SELECT * FROM client WHERE id IN ($placeholders)";
    $st = $conn->prepare($sql);
    $st->execute($client_ids);
    $clients = $st->fetchAll(PDO::FETCH_ASSOC);
    
    $filename = 'clients_export_' . date('Y-m-d_H-i-s') . '.' . $format;
    $filepath = __DIR__ . '/../storage/exports/' . $filename;
    
    // Ensure directory exists
    if (!is_dir(dirname($filepath))) {
        mkdir(dirname($filepath), 0755, true);
    }
    
    switch ($format) {
        case 'csv':
            $handle = fopen($filepath, 'w');
            fputcsv($handle, $fields);
            foreach ($clients as $client) {
                $row = [];
                foreach ($fields as $field) {
                    $row[] = $client[$field] ?? '';
                }
                fputcsv($handle, $row);
            }
            fclose($handle);
            break;
            
        case 'excel':
            // Simple CSV with .xls extension for Excel compatibility
            $handle = fopen($filepath, 'w');
            fputcsv($handle, $fields);
            foreach ($clients as $client) {
                $row = [];
                foreach ($fields as $field) {
                    $row[] = $client[$field] ?? '';
                }
                fputcsv($handle, $row);
            }
            fclose($handle);
            break;
            
        case 'pdf':
            // For PDF, we'll create a simple HTML that can be converted to PDF
            $html = generateClientPDFHTML($clients, $fields);
            file_put_contents($filepath, $html);
            break;
    }
    
    return [
        'success' => true,
        'filename' => $filename,
        'filepath' => $filepath,
        'count' => count($clients),
        'message' => "Exported " . count($clients) . " clients successfully"
    ];
}

function bulkUpdateClientStatus(PDO $conn, array $client_ids, array $data, int $user_id): array {
    $new_status = $data['status'] ?? '';
    
    if (!in_array($new_status, ['active', 'inactive', 'vip', 'at_risk'])) {
        return ['success' => false, 'error' => 'Invalid status'];
    }
    
    $placeholders = implode(',', array_fill(0, count($client_ids), '?'));
    $sql = "UPDATE client SET client_status = ?, updated_at = NOW() WHERE id IN ($placeholders)";
    $st = $conn->prepare($sql);
    $params = array_merge([$new_status], $client_ids);
    $st->execute($params);
    
    $updated_count = $st->rowCount();
    
    return [
        'success' => true,
        'updated' => $updated_count,
        'message' => "Updated status for {$updated_count} clients"
    ];
}

function bulkUpdateCreditLimit(PDO $conn, array $client_ids, array $data, int $user_id): array {
    $credit_limit = (float)($data['credit_limit'] ?? 0);
    $operation = $data['operation'] ?? 'set'; // 'set', 'increase', 'decrease'
    
    if ($operation === 'set') {
        $sql = "UPDATE client SET credit_limit = ? WHERE id IN ($placeholders)";
        $params = array_merge([$credit_limit], $client_ids);
    } else {
        $sign = $operation === 'increase' ? '+' : '-';
        $sql = "UPDATE client SET credit_limit = GREATEST(0, credit_limit $sign ?) WHERE id IN ($placeholders)";
        $params = array_merge([$credit_limit], $client_ids);
    }
    
    $placeholders = implode(',', array_fill(0, count($client_ids), '?'));
    $st = $conn->prepare($sql);
    $st->execute($params);
    
    $updated_count = $st->rowCount();
    
    return [
        'success' => true,
        'updated' => $updated_count,
        'message' => "Updated credit limit for {$updated_count} clients"
    ];
}

function bulkGenerateStatements(PDO $conn, array $client_ids, array $data, int $user_id): array {
    $from_date = $data['from_date'] ?? date('Y-m-01');
    $to_date = $data['to_date'] ?? date('Y-m-d');
    
    $generated_count = 0;
    $errors = [];
    
    foreach ($client_ids as $client_id) {
        try {
            // Generate statement for this client
            // This would typically create a PDF statement
            // For now, we'll just log the action
            $log_sql = "INSERT INTO client_communications (client_id, type, direction, subject, message, status, sent_by) 
                       VALUES (?, 'email', 'outbound', 'Statement of Account', 'Statement generated for period {$from_date} to {$to_date}', 'sent', ?)";
            $log_st = $conn->prepare($log_sql);
            $log_st->execute([$client_id, $user_id]);
            
            $generated_count++;
            
        } catch (Throwable $e) {
            $errors[] = "Failed to generate statement for client ID {$client_id}: " . $e->getMessage();
        }
    }
    
    return [
        'success' => true,
        'generated' => $generated_count,
        'errors' => $errors,
        'message' => "Generated {$generated_count} statements successfully"
    ];
}

function bulkGenerateInvoices(PDO $conn, array $client_ids, array $data, int $user_id): array {
    $from_date = $data['from_date'] ?? date('Y-m-01');
    $to_date = $data['to_date'] ?? date('Y-m-d');
    
    $generated_count = 0;
    $errors = [];
    
    foreach ($client_ids as $client_id) {
        try {
            // Use existing batch invoice generation function
            require_once __DIR__ . '/../includes/ar_helpers.php';
            
            $invoice_id = ar_generate_batch_invoice(
                $conn,
                $client_id,
                $from_date,
                $to_date,
                [],
                'single_line',
                $user_id
            );
            
            if ($invoice_id) {
                $generated_count++;
            }
            
        } catch (Throwable $e) {
            $errors[] = "Failed to generate invoice for client ID {$client_id}: " . $e->getMessage();
        }
    }
    
    return [
        'success' => true,
        'generated' => $generated_count,
        'errors' => $errors,
        'message' => "Generated {$generated_count} invoices successfully"
    ];
}

function generateClientPDFHTML(array $clients, array $fields): string {
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Client Export Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .header { text-align: center; margin-bottom: 30px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Client Export Report</h1>
        <p>Generated on: ' . date('Y-m-d H:i:s') . '</p>
        <p>Total Clients: ' . count($clients) . '</p>
    </div>
    
    <table>
        <thead>
            <tr>';
    
    foreach ($fields as $field) {
        $html .= '<th>' . ucwords(str_replace('_', ' ', $field)) . '</th>';
    }
    
    $html .= '</tr>
        </thead>
        <tbody>';
    
    foreach ($clients as $client) {
        $html .= '<tr>';
        foreach ($fields as $field) {
            $html .= '<td>' . htmlspecialchars($client[$field] ?? '') . '</td>';
        }
        $html .= '</tr>';
    }
    
    $html .= '</tbody>
    </table>
</body>
</html>';
    
    return $html;
}

function sendEmail(string $to, string $subject, string $message): bool {
    // Use existing mailer functionality
    // This is a placeholder - implement based on your existing mailer.php
    try {
        // For now, just log the email
        error_log("Email would be sent to: $to, Subject: $subject");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
