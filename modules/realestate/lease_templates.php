<?php
/**
 * Real Estate Module - Contract Templates Management
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    $templateId = !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null;
    
    if ($action === 'create' || $action === 'update') {
        $templateName = trim($_POST['template_name'] ?? '');
        $templateType = $_POST['template_type'] ?? 'standard';
        $description = trim($_POST['description'] ?? '');
        $templateContent = trim($_POST['template_content'] ?? '');
        $templateHtml = trim($_POST['template_html'] ?? '');
        $templateFormat = $_POST['template_format'] ?? 'text';
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        $isDefault = !empty($_POST['is_default']) ? 1 : 0;
        
        if ($templateName && ($templateContent || $templateHtml)) {
            try {
                $conn->beginTransaction();
                
                // If setting as default, unset other defaults
                if ($isDefault) {
                    $stmt = $conn->prepare("UPDATE re_contract_templates SET is_default = 0 WHERE company_id = ?");
                    $stmt->execute([$currentCompanyId]);
                }
                
                if ($action === 'update' && $templateId) {
                    $stmt = $conn->prepare("
                        UPDATE re_contract_templates 
                        SET template_name = ?, template_type = ?, description = ?, 
                            template_content = ?, template_html = ?, template_format = ?,
                            is_active = ?, is_default = ?
                        WHERE id = ? AND company_id = ?
                    ");
                    $stmt->execute([$templateName, $templateType, $description, 
                                  $templateContent, $templateHtml, $templateFormat,
                                  $isActive, $isDefault, $templateId, $currentCompanyId]);
                    $success = "Template updated successfully";
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO re_contract_templates 
                        (company_id, template_name, template_type, description, 
                         template_content, template_html, template_format,
                         is_active, is_default, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$currentCompanyId, $templateName, $templateType, $description,
                                  $templateContent, $templateHtml, $templateFormat,
                                  $isActive, $isDefault, $userId]);
                    $success = "Template created successfully";
                }
                
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error: " . $e->getMessage();
            }
        } else {
            $error = "Template name and content are required";
        }
    } elseif ($action === 'delete' && $templateId) {
        try {
            $stmt = $conn->prepare("DELETE FROM re_contract_templates WHERE id = ? AND company_id = ?");
            $stmt->execute([$templateId, $currentCompanyId]);
            $success = "Template deleted successfully";
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Get templates
$templates = $conn->prepare("
    SELECT t.*, u.username as created_by_name
    FROM re_contract_templates t
    LEFT JOIN user u ON u.id = t.created_by
    WHERE t.company_id = ?
    ORDER BY t.is_default DESC, t.template_name
");
$templates->execute([$currentCompanyId]);
$templates = $templates->fetchAll(PDO::FETCH_ASSOC);

// Get template for editing
$editTemplate = null;
if (!empty($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM re_contract_templates WHERE id = ? AND company_id = ?");
    $stmt->execute([$editId, $currentCompanyId]);
    $editTemplate = $stmt->fetch(PDO::FETCH_ASSOC);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Contract Templates';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label">Contract Templates</div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#templateModal">
                <i class="bi bi-plus-circle"></i> New Template
            </button>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= h($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Template Name</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Default</th>
                                <th>Created By</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($templates)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">No templates found. Create your first template!</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($templates as $tpl): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($tpl['template_name']) ?></strong>
                                            <?php if ($tpl['description']): ?>
                                                <br><small class="text-muted"><?= h(substr($tpl['description'], 0, 100)) ?><?= strlen($tpl['description']) > 100 ? '...' : '' ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?= ucfirst(str_replace('_', ' ', $tpl['template_type'])) ?></span>
                                        </td>
                                        <td>
                                            <?php if ($tpl['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($tpl['is_default']): ?>
                                                <span class="badge bg-primary">Default</span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($tpl['created_by_name'] ?? '-') ?></td>
                                        <td><?= date('Y-m-d', strtotime($tpl['created_at'])) ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="?edit=<?= $tpl['id'] ?>" class="btn btn-outline-primary">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this template?');">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="template_id" value="<?= $tpl['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Template Modal -->
        <div class="modal fade" id="templateModal" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><?= $editTemplate ? 'Edit' : 'New' ?> Contract Template</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="<?= $editTemplate ? 'update' : 'create' ?>">
                        <?php if ($editTemplate): ?>
                            <input type="hidden" name="template_id" value="<?= $editTemplate['id'] ?>">
                        <?php endif; ?>
                        
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label">Template Name *</label>
                                    <input type="text" class="form-control" name="template_name" 
                                           value="<?= $editTemplate ? h($editTemplate['template_name']) : '' ?>" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Template Type *</label>
                                    <select name="template_type" class="form-select" required>
                                        <option value="standard" <?= ($editTemplate && $editTemplate['template_type'] == 'standard') ? 'selected' : '' ?>>Standard</option>
                                        <option value="commercial" <?= ($editTemplate && $editTemplate['template_type'] == 'commercial') ? 'selected' : '' ?>>Commercial</option>
                                        <option value="short_term" <?= ($editTemplate && $editTemplate['template_type'] == 'short_term') ? 'selected' : '' ?>>Short Term</option>
                                        <option value="long_term" <?= ($editTemplate && $editTemplate['template_type'] == 'long_term') ? 'selected' : '' ?>>Long Term</option>
                                        <option value="renewal" <?= ($editTemplate && $editTemplate['template_type'] == 'renewal') ? 'selected' : '' ?>>Renewal</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="2"><?= $editTemplate ? h($editTemplate['description']) : '' ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Template Format *</label>
                                <select name="template_format" id="templateFormat" class="form-select mb-3" required>
                                    <option value="text" <?= ($editTemplate && ($editTemplate['template_format'] ?? 'text') == 'text') ? 'selected' : '' ?>>Text Template (Simple)</option>
                                    <option value="html" <?= ($editTemplate && ($editTemplate['template_format'] ?? '') == 'html') ? 'selected' : '' ?>>HTML Template (Advanced - for PDF)</option>
                                </select>
                                <small class="form-text text-muted">
                                    <strong>HTML Template:</strong> Use for professional PDF generation with full formatting, locked clauses, and signatures. 
                                    <strong>Text Template:</strong> Use for simple text-based contracts.
                                </small>
                            </div>
                            
                            <div class="mb-3" id="textTemplateSection" <?= ($editTemplate && ($editTemplate['template_format'] ?? 'text') == 'html') ? 'style="display: none;"' : '' ?>>
                                <label class="form-label">Template Content (Text) *</label>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="text-muted">Paste your contract template content below. Use placeholders to auto-fill lease information.</span>
                                    <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#placeholdersModal">
                                        <i class="bi bi-info-circle"></i> View All Placeholders
                                    </button>
                                </div>
                                <textarea class="form-control" name="template_content" id="templateContent" rows="20" 
                                          placeholder="Paste your contract template here. Use placeholders like {{TENANT_NAME}}, {{UNIT_NUMBER}}, {{ANNUAL_RENT}}, etc."><?= $editTemplate ? h($editTemplate['template_content'] ?? '') : '' ?></textarea>
                            </div>
                            
                            <div class="mb-3" id="htmlTemplateSection" <?= ($editTemplate && ($editTemplate['template_format'] ?? 'text') == 'html') ? '' : 'style="display: none;"' ?>>
                                <label class="form-label">HTML Template Content *</label>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="text-muted">Paste your HTML template below. Use placeholders like {{TENANT_NAME}}, {{UNIT_NUMBER}}, etc.</span>
                                    <div>
                                        <button type="button" class="btn btn-sm btn-outline-primary" id="insertPlaceholderBtn">
                                            <i class="bi bi-code"></i> Insert Placeholder
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#placeholdersModal">
                                            <i class="bi bi-info-circle"></i> View All Placeholders
                                        </button>
                                    </div>
                                </div>
                                <textarea name="template_html" id="templateHtml" rows="25" class="form-control" style="font-family: 'Courier New', monospace; font-size: 13px;" placeholder="Paste your HTML template here. Use placeholders like {{TENANT_NAME}}, {{UNIT_NUMBER}}, {{ANNUAL_RENT}}, etc.&#10;&#10;Example:&#10;&lt;html&gt;&#10;&lt;head&gt;&lt;style&gt;...&lt;/style&gt;&lt;/head&gt;&#10;&lt;body&gt;&#10;  &lt;p&gt;Tenant: {{TENANT_NAME}}&lt;/p&gt;&#10;  &lt;p class=&quot;ar&quot;&gt;المستأجر: {{TENANT_NAME_AR}}&lt;/p&gt;&#10;&lt;/body&gt;&#10;&lt;/html&gt;"><?= $editTemplate ? htmlspecialchars_decode($editTemplate['template_html'] ?? '', ENT_QUOTES | ENT_HTML5) : '' ?></textarea>
                                <div class="alert alert-info mt-2">
                                    <strong>HTML Template Tips:</strong>
                                    <ul class="mb-0 mt-2">
                                        <li>Paste your complete HTML template (including &lt;html&gt;, &lt;head&gt;, &lt;style&gt;, and &lt;body&gt; tags)</li>
                                        <li>Use <code>{{PLACEHOLDER}}</code> format (double curly braces) for auto-filled data</li>
                                        <li>Locked clauses (1-31) should be marked with <code>data-locked="true"</code> or class <code>locked</code></li>
                                        <li>Arabic text should use <code>direction: rtl</code> and <code>text-align: right</code> in CSS</li>
                                    </ul>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" 
                                               <?= ($editTemplate && $editTemplate['is_active']) || !$editTemplate ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="is_active">Active</label>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_default" id="is_default" 
                                               <?= ($editTemplate && $editTemplate['is_default']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="is_default">Set as Default</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary"><?= $editTemplate ? 'Update' : 'Create' ?> Template</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Placeholders Help Modal -->
        <div class="modal fade" id="placeholdersModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Available Placeholders</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <?php
                        require_once __DIR__ . '/includes/contract_generator.php';
                        $placeholders = get_available_placeholders();
                        foreach ($placeholders as $category => $items):
                        ?>
                            <div class="mb-4">
                                <h6 class="text-primary"><?= h($category) ?></h6>
                                <table class="table table-sm table-bordered">
                                    <thead>
                                        <tr>
                                            <th width="30%">Placeholder</th>
                                            <th>Description</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $placeholder => $description): ?>
                                        <tr>
                                            <td><code><?= h($placeholder) ?></code></td>
                                            <td><?= h($description) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                        <div class="alert alert-info">
                            <strong>How to use:</strong> In your template content, replace actual values with these placeholders. 
                            For example, if your template says "Tenant: John Doe", change it to "Tenant: {TENANT_NAME}". 
                            The system will automatically replace {TENANT_NAME} with the actual tenant name when generating the contract.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .contract-bilingual-wrapper {
                display: flex;
                gap: 20px;
                font-family: 'Times New Roman', serif;
                font-size: 11pt;
                line-height: 1.8;
                max-width: 100%;
            }
            .contract-english-column {
                flex: 1;
                padding: 15px;
                text-align: left;
                direction: ltr;
                border-right: 2px solid #ddd;
            }
            .contract-arabic-column {
                flex: 1;
                padding: 15px;
                text-align: right;
                direction: rtl;
                font-family: 'Arial', 'Tahoma', 'DejaVu Sans', sans-serif;
            }
            .contract-line {
                min-height: 1.8em;
                margin-bottom: 2px;
            }
            #templatePreview {
                max-height: 600px;
                overflow-y: auto;
            }
        </style>
        
        <!-- CodeMirror for HTML editing (Better for raw HTML templates) -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/monokai.min.css">
        <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/xml/xml.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/htmlmixed/htmlmixed.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/css/css.min.js"></script>
        
        <script>
            let codeMirrorEditor = null;
            
            document.addEventListener('DOMContentLoaded', function() {
                <?php if ($editTemplate): ?>
                // Auto-open modal if editing (wait for DOM to be ready)
                var modalElement = document.getElementById('templateModal');
                if (modalElement) {
                    var modal = new bootstrap.Modal(modalElement);
                    modal.show();
                    
                    // Initialize CodeMirror if HTML template is selected
                    setTimeout(function() {
                        var formatSelect = document.getElementById('templateFormat');
                        if (formatSelect && formatSelect.value === 'html') {
                            initCodeMirror();
                        }
                    }, 300);
                }
                
                // Clean up URL after modal opens (remove ?edit=X parameter)
                setTimeout(function() {
                    if (window.location.search.includes('edit=')) {
                        var url = new URL(window.location);
                        url.searchParams.delete('edit');
                        window.history.replaceState({}, '', url);
                    }
                }, 500);
                <?php endif; ?>
                
                // Template format switcher
                const templateFormat = document.getElementById('templateFormat');
                const textSection = document.getElementById('textTemplateSection');
                const htmlSection = document.getElementById('htmlTemplateSection');
                const templateContent = document.getElementById('templateContent');
                
                function switchTemplateFormat() {
                    if (!templateFormat || !textSection || !htmlSection) {
                        console.error('Template format elements not found');
                        return;
                    }
                    
                    const format = templateFormat.value;
                    console.log('Switching to format:', format);
                    
                    if (format === 'html') {
                        textSection.style.display = 'none';
                        htmlSection.style.display = 'block';
                        if (templateContent) {
                            templateContent.removeAttribute('required');
                        }
                        // Initialize CodeMirror after a short delay to ensure DOM is ready
                        setTimeout(function() {
                            initCodeMirror();
                        }, 200);
                    } else {
                        textSection.style.display = 'block';
                        htmlSection.style.display = 'none';
                        if (templateContent) {
                            templateContent.setAttribute('required', 'required');
                        }
                        if (codeMirrorEditor) {
                            codeMirrorEditor.toTextArea();
                            codeMirrorEditor = null;
                        }
                    }
                }
                
                if (templateFormat) {
                    templateFormat.addEventListener('change', switchTemplateFormat);
                    
                    // Initialize on load if HTML format is selected
                    if (templateFormat.value === 'html') {
                        setTimeout(switchTemplateFormat, 100);
                    }
                }
                
                // Also check when modal opens (for new templates)
                const templateModal = document.getElementById('templateModal');
                if (templateModal) {
                    templateModal.addEventListener('shown.bs.modal', function() {
                        setTimeout(function() {
                            if (templateFormat && templateFormat.value === 'html') {
                                switchTemplateFormat();
                            }
                        }, 200);
                    });
                }
                
                // Initialize CodeMirror for HTML editing
                function initCodeMirror() {
                    // Check if already initialized
                    if (codeMirrorEditor || !document.getElementById('templateHtml')) {
                        return;
                    }
                    
                    // Check if CodeMirror is loaded
                    if (typeof CodeMirror === 'undefined') {
                        console.log('CodeMirror not loaded. Using standard textarea.');
                        return;
                    }
                    
                    try {
                        codeMirrorEditor = CodeMirror.fromTextArea(document.getElementById('templateHtml'), {
                            mode: 'htmlmixed',
                            theme: 'monokai',
                            lineNumbers: true,
                            lineWrapping: true,
                            indentUnit: 2,
                            indentWithTabs: false,
                            autoCloseTags: true,
                            matchTags: true,
                            foldGutter: true,
                            gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter'],
                            extraKeys: {
                                'Ctrl-Space': 'autocomplete',
                                'Ctrl-J': 'toMatchingTag'
                            }
                        });
                        
                        // Set height
                        codeMirrorEditor.setSize(null, 600);
                    } catch (error) {
                        console.error('CodeMirror initialization error:', error);
                    }
                }
                
                // Placeholder insertion menu
                function showPlaceholderMenu(editor) {
                    // Get current cursor position if CodeMirror
                    let cursorPos = null;
                    if (codeMirrorEditor) {
                        cursorPos = codeMirrorEditor.getCursor();
                    }
                    const placeholders = [
                        {cat: 'Basic Info', items: [
                            '{{LANDLORD_NAME}}', '{{LANDLORD_NAME_AR}}',
                            '{{TENANT_NAME}}', '{{TENANT_NAME_AR}}',
                            '{{PROPERTY_NAME}}', '{{PROPERTY_NAME_AR}}',
                            '{{UNIT_NO}}', '{{UNIT_NO_AR}}'
                        ]},
                        {cat: 'Dates', items: [
                            '{{START_DATE}}', '{{START_DATE_AR}}',
                            '{{END_DATE}}', '{{END_DATE_AR}}',
                            '{{TODAY_DATE}}'
                        ]},
                        {cat: 'Financial', items: [
                            '{{ANNUAL_RENT}}', '{{ANNUAL_RENT_AR}}',
                            '{{SECURITY_DEPOSIT}}', '{{SECURITY_DEPOSIT_AR}}'
                        ]},
                        {cat: 'Ejari', items: [
                            '{{EJARI_NUMBER}}', '{{EJARI_DATE}}', '{{EJARI_PROPERTY_CODE}}'
                        ]},
                        {cat: 'Signatures', items: [
                            '{{LANDLORD_SIGNATURE}}', '{{TENANT_SIGNATURE}}', '{{COMPANY_STAMP}}'
                        ]}
                    ];
                    
                    let menuHtml = '<div class="placeholder-menu" style="max-height: 400px; overflow-y: auto;">';
                    placeholders.forEach(cat => {
                        menuHtml += `<h6>${cat.cat}</h6><div style="display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 15px;">`;
                        cat.items.forEach(ph => {
                            menuHtml += `<button type="button" class="btn btn-sm btn-outline-primary placeholder-btn" data-placeholder="${ph}">${ph}</button>`;
                        });
                        menuHtml += '</div>';
                    });
                    menuHtml += '</div>';
                    
                    // Show in modal or dropdown
                    const modal = document.createElement('div');
                    modal.className = 'modal fade';
                    modal.innerHTML = `
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Insert Placeholder</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">${menuHtml}</div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    `;
                    document.body.appendChild(modal);
                    const bsModal = new bootstrap.Modal(modal);
                    bsModal.show();
                    
                    // Handle placeholder clicks
                    modal.querySelectorAll('.placeholder-btn').forEach(btn => {
                        btn.addEventListener('click', function() {
                            const placeholder = this.getAttribute('data-placeholder');
                            
                            if (codeMirrorEditor) {
                                // Insert into CodeMirror
                                const doc = codeMirrorEditor.getDoc();
                                const cursor = doc.getCursor();
                                doc.replaceRange(placeholder, cursor);
                            } else {
                                // Insert into textarea
                                const textarea = document.getElementById('templateHtml');
                                const start = textarea.selectionStart;
                                const end = textarea.selectionEnd;
                                const text = textarea.value;
                                textarea.value = text.substring(0, start) + placeholder + text.substring(end);
                                textarea.selectionStart = textarea.selectionEnd = start + placeholder.length;
                                textarea.focus();
                            }
                            
                            bsModal.hide();
                            setTimeout(() => modal.remove(), 300);
                        });
                    });
                    
                    modal.addEventListener('hidden.bs.modal', () => modal.remove());
                }
                
                // Insert placeholder button
                document.getElementById('insertPlaceholderBtn')?.addEventListener('click', function() {
                    if (tinymceEditor) {
                        showPlaceholderMenu(tinymceEditor);
                    }
                });
                
                // Preview button functionality
                const previewBtn = document.getElementById('previewTemplateBtn');
                const templateContent = document.getElementById('templateContent');
                const templatePreview = document.getElementById('templatePreview');
                const previewTab = document.getElementById('preview-tab');
                
                function updatePreview() {
                    const content = templateContent.value;
                    if (!content.trim()) {
                        templatePreview.innerHTML = '<p class="text-muted text-center">No content to preview. Add content in the Edit tab.</p>';
                        return;
                    }
                    
                    // Show loading
                    templatePreview.innerHTML = '<p class="text-center"><i class="bi bi-hourglass-split"></i> Generating preview...</p>';
                    
                    // Use AJAX to format the content
                    fetch('ajax_preview_template.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'content=' + encodeURIComponent(content)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            templatePreview.innerHTML = '<p class="text-danger">Error: ' + data.error + '</p>';
                        } else {
                            templatePreview.innerHTML = data.content;
                        }
                    })
                    .catch(error => {
                        // Fallback: format client-side
                        formatPreviewClientSide(content);
                    });
                }
                
                function formatPreviewClientSide(content) {
                    // Simple client-side formatting (basic version)
                    const lines = content.split('\n');
                    const rows = [];
                    const arabicPattern = /[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]/;
                    
                    let i = 0;
                    while (i < lines.length) {
                        const line = lines[i].trim();
                        if (!line) {
                            rows.push({english: '', arabic: ''});
                            i++;
                            continue;
                        }
                        
                        const hasArabic = arabicPattern.test(line);
                        const arabicCount = (line.match(arabicPattern) || []).length;
                        const totalChars = line.length;
                        const isArabic = arabicCount > (totalChars * 0.2);
                        
                        if (isArabic) {
                            const arabicBlock = [line];
                            i++;
                            while (i < lines.length) {
                                const nextLine = lines[i].trim();
                                if (!nextLine) break;
                                const nextHasArabic = arabicPattern.test(nextLine);
                                const nextArabicCount = (nextLine.match(arabicPattern) || []).length;
                                const nextTotalChars = nextLine.length;
                                const nextIsArabic = nextArabicCount > (nextTotalChars * 0.2);
                                if (nextIsArabic) {
                                    arabicBlock.push(nextLine);
                                    i++;
                                } else {
                                    break;
                                }
                            }
                            
                            // Look backwards for English
                            const englishBlock = [];
                            let j = i - arabicBlock.length - 1;
                            let lookBack = 0;
                            while (j >= 0 && lookBack < 10) {
                                const prevLine = lines[j].trim();
                                if (!prevLine) {
                                    j--;
                                    lookBack++;
                                    continue;
                                }
                                const prevArabicCount = (prevLine.match(arabicPattern) || []).length;
                                const prevTotalChars = prevLine.length;
                                const prevIsArabic = prevArabicCount > (prevTotalChars * 0.2);
                                if (!prevIsArabic) {
                                    englishBlock.unshift(prevLine);
                                    lookBack++;
                                } else {
                                    break;
                                }
                                j--;
                            }
                            
                            const maxPairs = Math.max(englishBlock.length, arabicBlock.length);
                            for (let k = 0; k < maxPairs; k++) {
                                rows.push({
                                    english: englishBlock[k] || '',
                                    arabic: arabicBlock[k] || ''
                                });
                            }
                        } else {
                            const englishBlock = [line];
                            i++;
                            while (i < lines.length) {
                                const nextLine = lines[i].trim();
                                if (!nextLine) {
                                    i++;
                                    continue;
                                }
                                const nextArabicCount = (nextLine.match(arabicPattern) || []).length;
                                const nextTotalChars = nextLine.length;
                                const nextIsArabic = nextArabicCount > (nextTotalChars * 0.2);
                                if (!nextIsArabic) {
                                    englishBlock.push(nextLine);
                                    i++;
                                } else {
                                    break;
                                }
                            }
                            
                            if (i >= lines.length || !arabicPattern.test(lines[i].trim())) {
                                englishBlock.forEach(eng => {
                                    rows.push({english: eng, arabic: ''});
                                });
                            }
                        }
                    }
                    
                    // Build HTML
                    let html = '<div class="contract-bilingual-wrapper">';
                    html += '<div class="contract-english-column">';
                    rows.forEach(row => {
                        html += '<div class="contract-line">' + escapeHtml(row.english) + '</div>';
                    });
                    html += '</div>';
                    html += '<div class="contract-arabic-column">';
                    rows.forEach(row => {
                        html += '<div class="contract-line">' + escapeHtml(row.arabic) + '</div>';
                    });
                    html += '</div>';
                    html += '</div>';
                    
                    templatePreview.innerHTML = html;
                }
                
                function escapeHtml(text) {
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML.replace(/\n/g, '<br>');
                }
                
                // Preview button click
                if (previewBtn) {
                    previewBtn.addEventListener('click', function() {
                        // Switch to preview tab
                        const previewTabEl = new bootstrap.Tab(previewTab);
                        previewTabEl.show();
                        updatePreview();
                    });
                }
                
                // Update preview when switching to preview tab
                const previewTabEl = document.getElementById('preview-tab');
                if (previewTabEl) {
                    previewTabEl.addEventListener('shown.bs.tab', function() {
                        updatePreview();
                    });
                }
                
                // Auto-update preview when content changes (debounced)
                let previewTimeout;
                if (templateContent) {
                    templateContent.addEventListener('input', function() {
                        clearTimeout(previewTimeout);
                        previewTimeout = setTimeout(function() {
                            // Only update if preview tab is active
                            const previewTabEl = document.getElementById('preview-tab');
                            if (previewTabEl && previewTabEl.classList.contains('active')) {
                                updatePreview();
                            }
                        }, 500);
                    });
                }
            });
        </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

