<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_role(['Owner','Admin','Account'], $conn);

// Helper function for HTML escaping
if (!function_exists('h')) {
    function h($s) { 
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
    }
}

$template_id = (int)($_GET['id'] ?? 0);

if ($template_id <= 0) {
    echo '<div class="alert alert-danger">Invalid template ID</div>';
    exit;
}

try {
    $stmt = $conn->prepare("SELECT * FROM email_templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        echo '<div class="alert alert-danger">Template not found</div>';
        exit;
    }
    
    // Sample variables for preview
    $variables = [
        'invoice_no' => 'INV-2024-00001',
        'client_name' => 'John Doe',
        'client_email' => 'john@example.com',
        'total' => '1,250.00',
        'due_date' => '2024-02-15',
        'issue_date' => '2024-01-15',
        'company_name' => 'BMSystem',
        'company_address' => '123 Business Street, Dubai, UAE',
        'company_phone' => '+971 4 123 4567',
        'company_email' => 'info@bmsystem.com'
    ];
    
    // Replace variables in HTML
    $preview_html = $template['body_html'];
    foreach ($variables as $key => $value) {
        $preview_html = str_replace('{{' . $key . '}}', $value, $preview_html);
    }
    
    echo '<div class="border rounded p-3">';
    echo '<h6>Subject: ' . htmlspecialchars($template['subject']) . '</h6>';
    echo '<hr>';
    echo '<div class="preview-content">';
    echo $preview_html;
    echo '</div>';
    echo '</div>';
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error loading preview: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
