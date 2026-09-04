<?php
/**
 * Real Estate Module - Generate Contract PDF
 * 
 * This page handles:
 * - Contract PDF generation using mPDF
 * - Preview before generation
 * - Download generated PDF
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

$leaseId = !empty($_GET['lease_id']) ? (int)$_GET['lease_id'] : null;
$action = $_GET['action'] ?? '';

$error = '';
$success = '';

// Load lease data
if ($leaseId) {
    $stmt = $conn->prepare("
        SELECT l.*, 
               t.first_name, t.last_name, t.email, t.phone, t.id_number,
               u.unit_number, u.unit_type, u.area_sqm,
               b.name as building_name, b.address as building_address,
               c.name as company_name
        FROM re_leases l
        JOIN re_tenants t ON t.id = l.tenant_id
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        JOIN companies c ON c.id = l.company_id
        WHERE l.id = ? AND l.company_id = ?
    ");
    $stmt->execute([$leaseId, $currentCompanyId]);
    $lease = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lease) {
        $error = "Lease not found";
        $leaseId = null;
    }
} else {
    $error = "Lease ID is required";
}

// Handle PDF generation
if ($action === 'generate' && $leaseId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    try {
        require_once __DIR__ . '/includes/contract_pdf_generator.php';
        $generator = new ContractPDFGenerator($conn, $currentCompanyId);
        
        // Generate PDF using mPDF for shared hosting and Arabic support.
        $pdfPath = $generator->generateFromLease($leaseId);
        
        $success = "Contract PDF generated successfully!";
        
        // Redirect to download
        header("Location: ?lease_id=$leaseId&action=download&file=" . urlencode($pdfPath));
        exit;
        
    } catch (Exception $e) {
        $error = "Error generating PDF: " . $e->getMessage();
    }
}

// Handle PDF download
if ($action === 'download' && $leaseId) {
    $file = $_GET['file'] ?? '';
    if (empty($file)) {
        // Get from lease record
        $stmt = $conn->prepare("SELECT generated_contract_path FROM re_leases WHERE id = ? AND company_id = ?");
        $stmt->execute([$leaseId, $currentCompanyId]);
        $lease = $stmt->fetch(PDO::FETCH_ASSOC);
        $file = $lease['generated_contract_path'] ?? '';
    }
    
    if ($file && file_exists(__DIR__ . '/../../' . $file)) {
        $fullPath = __DIR__ . '/../../' . $file;
        $filename = basename($file);
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    } else {
        $error = "PDF file not found";
    }
}

// Handle preview
if ($action === 'preview' && $leaseId) {
    try {
        require_once __DIR__ . '/includes/contract_pdf_generator.php';
        $generator = new ContractPDFGenerator($conn, $currentCompanyId);
        
        // Load template and replace placeholders (without generating PDF)
        $templateId = $lease['template_id'];
        if (!$templateId) {
            $stmt = $conn->prepare("
                SELECT * FROM re_contract_templates 
                WHERE company_id = ? AND is_active = 1 AND is_default = 1
                LIMIT 1
            ");
            $stmt->execute([$currentCompanyId]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $conn->prepare("
                SELECT * FROM re_contract_templates 
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([$templateId, $currentCompanyId]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if ($template) {
            $htmlContent = !empty($template['template_html']) 
                ? $template['template_html'] 
                : $template['template_content'];
            
            // Use reflection to access private method (or make it public)
            $reflection = new ReflectionClass($generator);
            $method = $reflection->getMethod('replacePlaceholders');
            $method->setAccessible(true);
            $previewHtml = $method->invoke($generator, $htmlContent, $lease, $template);
            
            // Output preview
            header('Content-Type: text/html; charset=UTF-8');
            echo $previewHtml;
            exit;
        } else {
            $error = "Template not found";
        }
    } catch (Exception $e) {
        $error = "Error generating preview: " . $e->getMessage();
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Generate Contract';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label">Generate Contract PDF</div>
            <?php if ($leaseId): ?>
                <a href="lease_view.php?id=<?= $leaseId ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Lease
                </a>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= h($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($leaseId && $lease): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5>Lease Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Lease Number:</strong> <?= h($lease['lease_number']) ?></p>
                            <p><strong>Tenant:</strong> <?= h($lease['first_name'] . ' ' . $lease['last_name']) ?></p>
                            <p><strong>Unit:</strong> <?= h($lease['unit_number'] . ' - ' . $lease['building_name']) ?></p>
                            <p><strong>Period:</strong> <?= date('d/m/Y', strtotime($lease['start_date'])) ?> - <?= date('d/m/Y', strtotime($lease['end_date'])) ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Annual Rent:</strong> AED <?= number_format($lease['annual_rent'] ?? $lease['monthly_rent'] * 12, 2) ?></p>
                            <p><strong>Security Deposit:</strong> AED <?= number_format($lease['security_deposit'], 2) ?></p>
                            <p><strong>Ejari Number:</strong> <?= h($lease['ejari_registration_number'] ?? 'Not set') ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5>Contract Generation</h5>
                </div>
                <div class="card-body">
                    <?php
                    require_once __DIR__ . '/includes/contract_pdf_generator.php';
                    $generator = new ContractPDFGenerator($conn, $currentCompanyId);
                    $mpdfAvailable = $generator->isMpdfAvailable();
                    $pdfEngineLabel = $generator->getPdfEngineLabel();
                    ?>
                    
                    <?php if (!$mpdfAvailable): ?>
                        <div class="alert alert-warning">
                            <strong>PDF engine unavailable:</strong> Composer cannot load mPDF with the current PHP setup.
                            Current PHP version: <code><?= h(PHP_VERSION) ?></code>. Upgrade PHP to <code>8.3+</code>
                            or regenerate Composer dependencies for PHP 8.2 before generating contracts.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <strong>PDF engine:</strong> <?= h($pdfEngineLabel) ?> is ready. Click
                            <strong>Generate Contract PDF</strong> below.
                        </div>
                    <?php endif; ?>
                    
                    <div class="d-flex gap-2 mb-3">
                        <form method="POST" action="?lease_id=<?= $leaseId ?>&action=generate" style="display: inline;">
                            <?php csrf_field(); ?>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-file-pdf"></i> Generate Contract PDF
                            </button>
                        </form>
                        
                        <a href="?lease_id=<?= $leaseId ?>&action=preview" target="_blank" class="btn btn-outline-primary">
                            <i class="bi bi-eye"></i> Preview Contract
                        </a>
                        
                        <?php if ($lease['generated_contract_path'] && file_exists(__DIR__ . '/../../' . $lease['generated_contract_path'])): ?>
                            <a href="?lease_id=<?= $leaseId ?>&action=download" class="btn btn-success">
                                <i class="bi bi-download"></i> Download PDF
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($lease['generated_contract_path']): ?>
                        <div class="alert alert-info">
                            <strong>Contract Generated:</strong> <?= date('d/m/Y H:i', strtotime($lease['contract_generated_at'])) ?>
                            <br><small>File: <?= h($lease['generated_contract_path']) ?></small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                Please select a lease to generate contract.
            </div>
        <?php endif; ?>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
