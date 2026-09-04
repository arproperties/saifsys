<?php
// settings_ajax_invoice_templates.php - AJAX handler for invoice template management

// Start output buffering to prevent any accidental output
ob_start();

// Suppress deprecation warnings to prevent JSON corruption
error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR);

require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/db_connect.php';

// Check database connection
if (!$conn) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit;
}

require_role(['Owner','Admin'], $conn);

// Clear any output that might have been generated
ob_clean();
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_templates':
            $templates = $conn->query("
                SELECT * FROM invoice_templates 
                ORDER BY is_default DESC, created_at ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'templates' => $templates
            ]);
            break;
            
        case 'get_template':
            $template_id = (int)($_GET['id'] ?? 0);
            if ($template_id <= 0) {
                throw new Exception('Invalid template ID');
            }
            
            $template = $conn->prepare("SELECT * FROM invoice_templates WHERE id = ?");
            $template->execute([$template_id]);
            $template_data = $template->fetch(PDO::FETCH_ASSOC);
            
            if (!$template_data) {
                throw new Exception('Template not found');
            }
            
            echo json_encode([
                'success' => true,
                'template' => $template_data
            ]);
            break;
            
        case 'save_template':
            $template_id = (int)($_POST['template_id'] ?? 0);
            $is_new = $template_id === 0;
            
            // Validate required fields
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) {
                throw new Exception('Template name is required');
            }
            
            // Handle signature upload
            $signature_path = null;
            
            // Debug: Log file upload info
            error_log('File upload debug: ' . print_r($_FILES, true));
            
            if (isset($_FILES['signature_file'])) {
                $upload_error = $_FILES['signature_file']['error'];
                if ($upload_error !== UPLOAD_ERR_OK) {
                    $error_messages = [
                        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
                        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
                        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                        UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
                    ];
                    throw new Exception('Upload error: ' . ($error_messages[$upload_error] ?? 'Unknown error (code: ' . $upload_error . ')'));
                }
            }
            
            if (isset($_FILES['signature_file']) && $_FILES['signature_file']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/uploads/signatures/';
                if (!is_dir($upload_dir)) {
                    if (!mkdir($upload_dir, 0777, true)) {
                        throw new Exception('Failed to create upload directory');
                    }
                }
                
                // Check if directory is writable
                if (!is_writable($upload_dir)) {
                    throw new Exception('Upload directory is not writable');
                }
                
                $file_extension = strtolower(pathinfo($_FILES['signature_file']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['png', 'jpg', 'jpeg', 'gif'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    // Validate file size (2MB max)
                    if ($_FILES['signature_file']['size'] > 2 * 1024 * 1024) {
                        throw new Exception('File size must be less than 2MB');
                    }
                    
                    $filename = 'signature_' . time() . '_' . uniqid() . '.' . $file_extension;
                    $file_path = $upload_dir . $filename;
                    
                    // Check if temp file exists and is readable
                    if (!file_exists($_FILES['signature_file']['tmp_name'])) {
                        throw new Exception('Temporary file not found');
                    }
                    
                    if (!is_readable($_FILES['signature_file']['tmp_name'])) {
                        throw new Exception('Temporary file is not readable');
                    }
                    
                    if (move_uploaded_file($_FILES['signature_file']['tmp_name'], $file_path)) {
                        $signature_path = 'uploads/signatures/' . $filename;
                    } else {
                        throw new Exception('Failed to move uploaded file. Check directory permissions.');
                    }
                } else {
                    throw new Exception('Invalid file type. Please upload PNG, JPG, JPEG, or GIF images only.');
                }
            } elseif ($is_new) {
                // For new templates, check if there's an existing signature_path from form
                $signature_path = $_POST['signature_path'] ?? null;
            } else {
                // For existing templates, keep current signature_path if no new file uploaded
                $existing_template = $conn->prepare("SELECT signature_path FROM invoice_templates WHERE id = ?");
                $existing_template->execute([$template_id]);
                $existing = $existing_template->fetch(PDO::FETCH_ASSOC);
                $signature_path = $existing['signature_path'] ?? null;
            }
            
            // Prepare data
            $data = [
                'name' => $name,
                'primary_color' => $_POST['primary_color'] ?? '#0b2a4a',
                'accent_color' => $_POST['accent_color'] ?? '#e53935',
                'background_color' => $_POST['background_color'] ?? '#ffffff',
                'text_color' => $_POST['text_color'] ?? '#333333',
                'border_color' => $_POST['border_color'] ?? '#e6e7eb',
                'show_company_name' => isset($_POST['show_company_name']) ? 1 : 0,
                'show_logo' => isset($_POST['show_logo']) ? 1 : 0,
                'header_layout' => $_POST['header_layout'] ?? 'logo_left',
                'invoice_title' => $_POST['invoice_title'] ?? 'TAX INVOICE',
                'show_invoice_number' => isset($_POST['show_invoice_number']) ? 1 : 0,
                'show_invoice_date' => isset($_POST['show_invoice_date']) ? 1 : 0,
                'show_due_date' => isset($_POST['show_due_date']) ? 1 : 0,
                'show_bill_to' => isset($_POST['show_bill_to']) ? 1 : 0,
                'show_company_info' => isset($_POST['show_company_info']) ? 1 : 0,
                'show_bank_details' => isset($_POST['show_bank_details']) ? 1 : 0,
                'show_terms' => isset($_POST['show_terms']) ? 1 : 0,
                'show_signature' => isset($_POST['show_signature']) ? 1 : 0,
                'signature_path' => $signature_path,
                'table_header_bg' => $_POST['table_header_bg'] ?? '#eef0f3',
                'table_stripe_bg' => $_POST['table_stripe_bg'] ?? '#f5f6f8',
                'table_border_color' => $_POST['table_border_color'] ?? '#dee2e6',
                'footer_text' => $_POST['footer_text'] ?? 'Thank you for your business',
                'show_amount_in_words' => isset($_POST['show_amount_in_words']) ? 1 : 0,
                'font_family' => $_POST['font_family'] ?? 'system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, sans-serif',
                'font_size_base' => $_POST['font_size_base'] ?? '14px',
                'font_size_title' => $_POST['font_size_title'] ?? '24px',
                'font_size_header' => $_POST['font_size_header'] ?? '18px',
                'page_margin' => $_POST['page_margin'] ?? '28px',
                'section_spacing' => $_POST['section_spacing'] ?? '20px'
            ];
            
            $conn->beginTransaction();
            
            if ($is_new) {
                // Create new template
                $sql = "INSERT INTO invoice_templates (" . implode(', ', array_keys($data)) . ") VALUES (" . 
                       str_repeat('?,', count($data) - 1) . "?)";
                $stmt = $conn->prepare($sql);
                $stmt->execute(array_values($data));
                $template_id = $conn->lastInsertId();
            } else {
                // Update existing template
                $sql = "UPDATE invoice_templates SET " . 
                       implode(' = ?, ', array_keys($data)) . " = ?, updated_at = NOW() WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute(array_merge(array_values($data), [$template_id]));
            }
            
            $conn->commit();
            
            // Audit Log
            require_once __DIR__ . '/includes/AuditService.php';
            AuditService::log([
                'action' => $is_new ? 'insert' : 'update',
                'object_type' => 'invoice_templates',
                'object_id' => (string)$template_id,
                'summary' => ($is_new ? 'Created' : 'Updated') . " invoice template: {$name}",
                'new_data' => $data,
                'success' => true
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Template ' . ($is_new ? 'created' : 'updated') . ' successfully',
                'template_id' => $template_id
            ]);
            break;
            
        case 'set_default':
            $template_id = (int)($_POST['template_id'] ?? 0);
            if ($template_id <= 0) {
                throw new Exception('Invalid template ID');
            }
            
            $conn->beginTransaction();
            
            // Remove default from all templates
            $conn->exec("UPDATE invoice_templates SET is_default = 0");
            
            // Set new default
            $stmt = $conn->prepare("UPDATE invoice_templates SET is_default = 1 WHERE id = ?");
            $stmt->execute([$template_id]);
            
            $conn->commit();
            
            // Audit Log
            require_once __DIR__ . '/includes/AuditService.php';
            AuditService::log([
                'action' => 'update',
                'object_type' => 'invoice_templates',
                'object_id' => (string)$template_id,
                'summary' => "Set template as default",
                'success' => true
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Default template updated successfully'
            ]);
            break;
            
        case 'delete_template':
            $template_id = (int)($_POST['template_id'] ?? 0);
            if ($template_id <= 0) {
                throw new Exception('Invalid template ID');
            }
            
            // Check if it's the default template
            $stmt = $conn->prepare("SELECT is_default FROM invoice_templates WHERE id = ?");
            $stmt->execute([$template_id]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($template && $template['is_default']) {
                throw new Exception('Cannot delete the default template');
            }
            
            $conn->beginTransaction();
            
            $stmt = $conn->prepare("DELETE FROM invoice_templates WHERE id = ?");
            $stmt->execute([$template_id]);
            
            $conn->commit();
            
            // Audit Log
            require_once __DIR__ . '/includes/AuditService.php';
            AuditService::log([
                'action' => 'delete',
                'object_type' => 'invoice_templates',
                'object_id' => (string)$template_id,
                'summary' => "Deleted invoice template",
                'success' => true
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Template deleted successfully'
            ]);
            break;
            
        case 'preview_template':
            $template_id = (int)($_GET['template_id'] ?? 0);
            if ($template_id <= 0) {
                throw new Exception('Invalid template ID');
            }
            
            $template = $conn->prepare("SELECT * FROM invoice_templates WHERE id = ?");
            $template->execute([$template_id]);
            $template_data = $template->fetch(PDO::FETCH_ASSOC);
            
            if (!$template_data) {
                throw new Exception('Template not found');
            }
            
            // Generate CSS from template settings
            $css = generateTemplateCSS($template_data);
            
            echo json_encode([
                'success' => true,
                'css' => $css,
                'template' => $template_data
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    ob_clean();
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

// End output buffering and send clean response
ob_end_flush();

function generateTemplateCSS($template) {
    $css = '
    :root {
        --brand-dark: ' . $template['primary_color'] . ';
        --brand-accent: ' . $template['accent_color'] . ';
        --table-band: ' . $template['table_stripe_bg'] . ';
        --text-color: ' . $template['text_color'] . ';
        --border-color: ' . $template['border_color'] . ';
        --table-header-bg: ' . $template['table_header_bg'] . ';
        --table-border: ' . $template['table_border_color'] . ';
    }
    
    body { 
        background: ' . $template['background_color'] . '; 
        color: ' . $template['text_color'] . ';
        font-family: ' . $template['font_family'] . ';
        font-size: ' . $template['font_size_base'] . ';
    }
    
    .page { 
        max-width: 1000px; 
        margin: ' . $template['page_margin'] . ' auto 64px; 
    }
    
    .logo { 
        max-height: 90px; 
        max-width: 290px; 
        object-fit: contain; 
    }
    
    .bar-top { 
        display:flex; 
        justify-content:space-between; 
        align-items:center; 
        color:#fff; 
        margin-top:' . $template['section_spacing'] . '; 
    }
    
    .bar-left { 
        background: var(--brand-dark); 
        padding:10px 16px; 
        border-radius:6px 0 0 6px; 
        min-width: 280px; 
    }
    
    .bar-mid { 
        background: var(--brand-accent); 
        padding:10px 16px; 
        flex:1; 
        text-align:center; 
    }
    
    .bar-right{ 
        background: var(--brand-dark); 
        padding:10px 16px; 
        border-radius:0 6px 6px 0; 
        min-width: 220px; 
        text-align:right; 
    }
    
    .title { 
        text-align:center; 
        margin:24px 0 10px; 
        font-weight:700; 
        letter-spacing: .5px; 
        font-size: ' . $template['font_size_title'] . ';
    }
    
    .panel { 
        border:1px solid var(--border-color); 
        border-radius:10px; 
        padding:16px; 
    }
    
    .table thead th { 
        background: var(--table-header-bg); 
    }
    
    .table tbody tr:nth-child(even) { 
        background: var(--table-band); 
    }
    
    .table td, .table th {
        border-color: var(--table-border);
    }
    
    .totals-box { 
        width: 320px; 
        border:2px solid var(--brand-accent); 
        border-radius:10px; 
        overflow:hidden; 
    }
    
    .totals-box .row { 
        margin:0; 
    }
    
    .totals-box .cell { 
        padding:12px 14px; 
        border-bottom:1px solid rgba(0,0,0,.05); 
    }
    
    .totals-box .label { 
        background: var(--brand-accent); 
        color:#fff; 
        font-weight:700; 
    }
    
    .signature { 
        margin-top: 60px; 
        border-top:1px solid #ced4da; 
        width: 280px; 
    }
    
    .muted { 
        color:#6c757d; 
    }
    
    @media print {
        .no-print { display:none !important; }
        .page { margin:0; }
        a[href]:after { content:""; }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
    ';
    
    return $css;
}
?>
