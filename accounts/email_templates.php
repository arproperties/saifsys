<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/email_service.php';
require_role(['Owner','Admin','Account'], $conn);

// Helper function for HTML escaping
if (!function_exists('h')) {
    function h($s) { 
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
    }
}

$msg = $err = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        $template_id = (int)($_POST['template_id'] ?? 0);
        
        if ($action === 'save') {
            $template_name = trim($_POST['template_name'] ?? '');
            $template_type = trim($_POST['template_type'] ?? '');
            $subject = trim($_POST['subject'] ?? '');
            $body_html = trim($_POST['body_html'] ?? '');
            $body_plain = trim($_POST['body_plain'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($template_name) || empty($template_type) || empty($subject)) {
                throw new Exception('Template name, type, and subject are required');
            }
            
            if ($template_id > 0) {
                // Update existing template
                $stmt = $conn->prepare("
                    UPDATE email_templates 
                    SET template_name = ?, template_type = ?, subject = ?, 
                        body_html = ?, body_plain = ?, is_active = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$template_name, $template_type, $subject, $body_html, $body_plain, $is_active, $template_id]);
                $msg = "Template updated successfully";
            } else {
                // Create new template
                $stmt = $conn->prepare("
                    INSERT INTO email_templates (template_name, template_type, subject, body_html, body_plain, is_active)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$template_name, $template_type, $subject, $body_html, $body_plain, $is_active]);
                $msg = "Template created successfully";
            }
        } elseif ($action === 'delete') {
            if ($template_id <= 0) {
                throw new Exception('Invalid template ID');
            }
            
            $stmt = $conn->prepare("DELETE FROM email_templates WHERE id = ?");
            $stmt->execute([$template_id]);
            $msg = "Template deleted successfully";
        }
        
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

// Load templates
$emailService = new EmailService($conn);
$templates = $emailService->getTemplates();

// Load specific template for editing
$edit_template = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    foreach ($templates as $template) {
        if ($template['id'] == $edit_id) {
            $edit_template = $template;
            break;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Email Templates | BMSystem</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        .template-card { transition: transform 0.2s; }
        .template-card:hover { transform: translateY(-2px); }
        .code-editor { font-family: 'Courier New', monospace; font-size: 0.9rem; }
    </style>
</head>
<body class="bg-light">
<div class="container my-4">
    <div class="d-flex align-items-center mb-4">
        <a href="../account" class="btn btn-outline-secondary me-3">
            <i class="bi bi-arrow-left"></i> Back to Accounts
        </a>
        <h2 class="mb-0">Email Templates</h2>
        <button class="btn btn-primary ms-auto" data-bs-toggle="modal" data-bs-target="#templateModal">
            <i class="bi bi-plus"></i> New Template
        </button>
    </div>

    <?php if($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
    <?php if($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

    <div class="row">
        <?php foreach ($templates as $template): ?>
        <div class="col-md-6 col-lg-4 mb-3">
            <div class="card template-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><?= h($template['template_name']) ?></h6>
                    <span class="badge bg-<?= $template['is_active'] ? 'success' : 'secondary' ?>">
                        <?= $template['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-2">
                        <i class="bi bi-tag"></i> <?= h($template['template_type']) ?>
                    </p>
                    <p class="small mb-2">
                        <strong>Subject:</strong> <?= h($template['subject']) ?>
                    </p>
                    <p class="small text-muted">
                        <i class="bi bi-calendar"></i> 
                        Created: <?= date('M j, Y', strtotime($template['created_at'])) ?>
                    </p>
                </div>
                <div class="card-footer">
                    <div class="btn-group btn-group-sm w-100">
                        <button class="btn btn-outline-primary" onclick="editTemplate(<?= $template['id'] ?>)">
                            <i class="bi bi-pencil"></i> Edit
                        </button>
                        <button class="btn btn-outline-info" onclick="previewTemplate(<?= $template['id'] ?>)">
                            <i class="bi bi-eye"></i> Preview
                        </button>
                        <button class="btn btn-outline-danger" onclick="deleteTemplate(<?= $template['id'] ?>)">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (empty($templates)): ?>
        <div class="col-12">
            <div class="text-center py-5">
                <i class="bi bi-envelope display-1 text-muted"></i>
                <h4 class="text-muted mt-3">No Email Templates</h4>
                <p class="text-muted">Create your first email template to get started.</p>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#templateModal">
                    <i class="bi bi-plus"></i> Create Template
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Template Modal -->
<div class="modal fade" id="templateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <form class="modal-content" method="post" id="templateForm">
            <div class="modal-header">
                <h5 class="modal-title" id="templateModalTitle">New Email Template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="template_id" id="template_id" value="0">
                
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Template Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="template_name" id="template_name" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Template Type <span class="text-danger">*</span></label>
                        <select class="form-select" name="template_type" id="template_type" required>
                            <option value="">Select Type</option>
                            <option value="invoice">Invoice</option>
                            <option value="statement">Statement</option>
                            <option value="receipt">Receipt</option>
                            <option value="general">General</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Subject <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="subject" id="subject" required>
                        <div class="form-text">Use variables like {{client_name}}, {{invoice_no}}, etc.</div>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">HTML Body</label>
                        <textarea class="form-control code-editor" name="body_html" id="body_html" rows="10" 
                                  placeholder="<html><body><h2>Hello {{client_name}}</h2><p>Your invoice {{invoice_no}} is ready.</p></body></html>"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Plain Text Body</label>
                        <textarea class="form-control code-editor" name="body_plain" id="body_plain" rows="6" 
                                  placeholder="Hello {{client_name}}\n\nYour invoice {{invoice_no}} is ready."></textarea>
                    </div>
                </div>
                
                <div class="mt-3">
                    <h6>Available Variables:</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Invoice Variables:</h6>
                            <ul class="small">
                                <li><code>{{invoice_no}}</code> - Invoice number</li>
                                <li><code>{{client_name}}</code> - Client name</li>
                                <li><code>{{total}}</code> - Invoice total</li>
                                <li><code>{{due_date}}</code> - Due date</li>
                                <li><code>{{issue_date}}</code> - Issue date</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>Company Variables:</h6>
                            <ul class="small">
                                <li><code>{{company_name}}</code> - Company name</li>
                                <li><code>{{company_address}}</code> - Company address</li>
                                <li><code>{{company_phone}}</code> - Company phone</li>
                                <li><code>{{company_email}}</code> - Company email</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Template</button>
            </div>
        </form>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Template Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="previewContent"></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editTemplate(templateId) {
    // Load template data via AJAX
    const url = `ajax/ajax_get_template.php?id=${templateId}`;
    console.log('Loading template from:', url);
    
    fetch(url)
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('Template data:', data);
            if (data.success) {
                document.getElementById('template_id').value = data.template.id;
                document.getElementById('template_name').value = data.template.template_name;
                document.getElementById('template_type').value = data.template.template_type;
                document.getElementById('subject').value = data.template.subject;
                document.getElementById('body_html').value = data.template.body_html;
                document.getElementById('body_plain').value = data.template.body_plain;
                document.getElementById('is_active').checked = data.template.is_active == 1;
                document.getElementById('templateModalTitle').textContent = 'Edit Email Template';
                
                new bootstrap.Modal(document.getElementById('templateModal')).show();
            } else {
                alert('Error loading template: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading template: ' + error.message);
        });
}

function previewTemplate(templateId) {
    // Load template preview via AJAX
    const url = `ajax/ajax_preview_template.php?id=${templateId}`;
    console.log('Loading preview from:', url);
    
    fetch(url)
        .then(response => {
            console.log('Preview response status:', response.status);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text();
        })
        .then(html => {
            console.log('Preview HTML received');
            document.getElementById('previewContent').innerHTML = html;
            new bootstrap.Modal(document.getElementById('previewModal')).show();
        })
        .catch(error => {
            console.error('Preview error:', error);
            alert('Error loading preview: ' + error.message);
        });
}

function deleteTemplate(templateId) {
    if (confirm('Are you sure you want to delete this template?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="template_id" value="${templateId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Reset form when modal is closed
document.getElementById('templateModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('templateForm').reset();
    document.getElementById('template_id').value = '0';
    document.getElementById('templateModalTitle').textContent = 'New Email Template';
});
</script>
<script>
  // Clean URL navigation for data-tab links
  document.querySelectorAll('a[data-tab]').forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      const tab = this.getAttribute('data-tab');
      const form = document.createElement('form');
      form.method = 'POST';
      // Use absolute path based on current location
      const currentPath = window.location.pathname;
      const basePath = currentPath.substring(0, currentPath.indexOf('/accounts'));
      form.action = basePath + '/account';
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'tab';
      input.value = tab;
      form.appendChild(input);
      document.body.appendChild(form);
      form.submit();
    });
  });
</script>
</body>
</html>
