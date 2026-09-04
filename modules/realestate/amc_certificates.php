<?php
/**
 * Real Estate Module - AMC Certificates Management
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_CORE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

$contractId = !empty($_GET['contract_id']) ? (int)$_GET['contract_id'] : 0;
$certId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

$success = '';
$error = '';

// Get contract info
$contract = null;
if ($contractId) {
    $stmt = $conn->prepare("
        SELECT ac.*, b.name as building_name, cat.name as category_name
        FROM re_amc_contracts ac
        LEFT JOIN re_buildings b ON b.id = ac.building_id
        LEFT JOIN re_amc_categories cat ON cat.id = ac.category_id
        WHERE ac.id = ? AND ac.company_id = ?
    ");
    $stmt->execute([$contractId, $currentCompanyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $certificateType = $_POST['certificate_type'] ?? 'compliance';
    $certificateNumber = trim($_POST['certificate_number'] ?? '');
    $issuedBy = trim($_POST['issued_by'] ?? '');
    $issueDate = $_POST['issue_date'] ?? '';
    $expiryDate = $_POST['expiry_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    
    // File upload handling
    $fileName = null;
    $filePath = null;
    $fileSize = null;
    
    if (!empty($_FILES['certificate_file']['tmp_name']) && $_FILES['certificate_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../../uploads/amc_certificates';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $ext = strtolower(pathinfo($_FILES['certificate_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        
        if (in_array($ext, $allowed)) {
            $fileName = uniqid() . '_' . time() . '.' . $ext;
            $filePath = $uploadDir . '/' . $fileName;
            
            if (move_uploaded_file($_FILES['certificate_file']['tmp_name'], $filePath)) {
                $fileSize = filesize($filePath);
                $filePath = 'uploads/amc_certificates/' . $fileName; // Relative path
            }
        }
    }
    
    // Determine status
    $expired = strtotime($expiryDate) < time();
    $expiringSoon = strtotime($expiryDate) < strtotime('+30 days');
    $status = $expired ? 'expired' : ($expiringSoon ? 'expiring_soon' : 'valid');
    
    if ($certId) {
        // Update existing
        $stmt = $conn->prepare("
            UPDATE re_amc_certificates SET
                certificate_type = ?, certificate_number = ?, issued_by = ?,
                issue_date = ?, expiry_date = ?, notes = ?, status = ?
            " . ($fileName ? ", file_name = ?, file_path = ?, file_size = ?" : "") . "
            WHERE id = ? AND contract_id = ?
        ");
        
        if ($fileName) {
            $stmt->execute([$certificateType, $certificateNumber, $issuedBy, $issueDate, $expiryDate, 
                           $notes, $status, $fileName, $filePath, $fileSize, $certId, $contractId]);
        } else {
            $stmt->execute([$certificateType, $certificateNumber, $issuedBy, $issueDate, $expiryDate, 
                           $notes, $status, $certId, $contractId]);
        }
        $success = "Certificate updated successfully!";
    } else {
        // Insert new
        $stmt = $conn->prepare("
            INSERT INTO re_amc_certificates (
                contract_id, certificate_type, certificate_number, issued_by,
                issue_date, expiry_date, file_name, file_path, file_size, status, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$contractId, $certificateType, $certificateNumber, $issuedBy, 
                       $issueDate, $expiryDate, $fileName, $filePath, $fileSize, $status, $notes]);
        $success = "Certificate added successfully!";
    }
}

// Get certificates
$certificates = [];
if ($contractId) {
    $stmt = $conn->prepare("
        SELECT c.*, ac.contract_number, b.name as building_name
        FROM re_amc_certificates c
        LEFT JOIN re_amc_contracts ac ON ac.id = c.contract_id
        LEFT JOIN re_buildings b ON b.id = ac.building_id
        WHERE c.contract_id = ?
        ORDER BY c.expiry_date ASC
    ");
    $stmt->execute([$contractId]);
    $certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get certificate for editing
$cert = null;
if ($certId) {
    $stmt = $conn->prepare("SELECT * FROM re_amc_certificates WHERE id = ?");
    $stmt->execute([$certId]);
    $cert = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cert && !$contractId) {
        $contractId = $cert['contract_id'];
        // Reload contract
        $stmt = $conn->prepare("
            SELECT ac.*, b.name as building_name, cat.name as category_name
            FROM re_amc_contracts ac
            LEFT JOIN re_buildings b ON b.id = ac.building_id
            LEFT JOIN re_amc_categories cat ON cat.id = ac.category_id
            WHERE ac.id = ? AND ac.company_id = ?
        ");
        $stmt->execute([$contractId, $currentCompanyId]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = $contract ? 'AMC Certificates: ' . h($contract['contract_number']) : 'AMC Certificates';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-header-label">AMC Certificates</h1>
            <?php if ($contract): ?>
                <p class="text-muted mb-0">
                    <strong><?= h($contract['contract_number']) ?></strong> - 
                    <?= h($contract['building_name']) ?> - <?= h($contract['category_name']) ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="btn-group">
            <?php if ($contractId): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCertModal">
                    <i class="bi bi-plus-circle"></i> Add Certificate
                </button>
            <?php endif; ?>
            <?php if ($contractId): ?>
                <a href="amc_view.php?id=<?= $contractId ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Contract
                </a>
            <?php else: ?>
                <a href="amc.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to AMC
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= h($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Certificates Table -->
    <div class="card shadow-sm">
        <div class="card-body">
            <?php if (empty($certificates)): ?>
                <p class="text-center text-muted py-4">No certificates found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Type</th>
                                <th>Certificate #</th>
                                <th>Issued By</th>
                                <th>Issue Date</th>
                                <th>Expiry Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($certificates as $c): 
                                $expired = strtotime($c['expiry_date']) < time();
                                $expiringSoon = strtotime($c['expiry_date']) < strtotime('+30 days');
                            ?>
                                <tr>
                                    <td><span class="badge bg-info"><?= ucfirst(str_replace('_', ' ', $c['certificate_type'])) ?></span></td>
                                    <td><?= h($c['certificate_number'] ?: 'N/A') ?></td>
                                    <td><?= h($c['issued_by'] ?: 'N/A') ?></td>
                                    <td><?= h($c['issue_date']) ?></td>
                                    <td>
                                        <?= h($c['expiry_date']) ?>
                                        <?php if ($expired || $expiringSoon): ?>
                                            <br><small class="text-<?= $expired ? 'danger' : 'warning' ?>">
                                                <?= $expired ? 'Expired' : 'Expiring Soon' ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $expired ? 'danger' : ($expiringSoon ? 'warning' : 'success') ?>">
                                            <?= ucfirst($c['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($c['file_path']): ?>
                                            <a href="<?= h($c['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-file-earmark-pdf"></i>
                                            </a>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-secondary" onclick="editCert(<?= $c['id'] ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Certificate Modal -->
<div class="modal fade" id="addCertModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <?php csrf_field(); ?>
                <input type="hidden" name="contract_id" value="<?= $contractId ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Add Certificate</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Certificate Type <span class="text-danger">*</span></label>
                            <select name="certificate_type" class="form-select" required>
                                <option value="compliance">Compliance</option>
                                <option value="inspection">Inspection</option>
                                <option value="safety">Safety</option>
                                <option value="renewal">Renewal</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Certificate Number</label>
                            <input type="text" name="certificate_number" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Issued By <span class="text-danger">*</span></label>
                            <input type="text" name="issued_by" class="form-control" required 
                                placeholder="e.g., Dubai Civil Defense">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Issue Date <span class="text-danger">*</span></label>
                            <input type="date" name="issue_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Expiry Date <span class="text-danger">*</span></label>
                            <input type="date" name="expiry_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Certificate File (PDF/Image)</label>
                            <input type="file" name="certificate_file" class="form-control" 
                                accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Certificate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editCert(id) {
    window.location.href = 'amc_certificates.php?id=' + id + '&contract_id=<?= $contractId ?>';
}
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
