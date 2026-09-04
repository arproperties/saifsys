<?php
/**
 * Real Estate Module - Service Agreements Management
 * Manage service agreements/contracts with vendors
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
$agreement = null;
$isEdit = false;
$vendorId = !empty($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : null;

// Get agreement if editing/viewing
if (!empty($_GET['id'])) {
    $agreementId = (int)$_GET['id'];
    $stmt = $conn->prepare("
        SELECT sa.*, v.vendor_name 
        FROM re_service_agreements sa
        JOIN re_vendors v ON v.id = sa.vendor_id
        WHERE sa.id = ? AND sa.company_id = ?
    ");
    $stmt->execute([$agreementId, $currentCompanyId]);
    $agreement = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($agreement) {
        $isEdit = true;
        $vendorId = $agreement['vendor_id'];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $agreementId = (int)$_POST['id'];
        try {
            $stmt = $conn->prepare("DELETE FROM re_service_agreements WHERE id = ? AND company_id = ?");
            $stmt->execute([$agreementId, $currentCompanyId]);
            $success = "Agreement deleted successfully";
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    } else {
        $agreementNumber = trim($_POST['agreement_number'] ?? '');
        $agreementName = trim($_POST['agreement_name'] ?? '');
        $vendorId = (int)$_POST['vendor_id'];
        $serviceType = $_POST['service_type'] ?? 'other';
        $startDate = $_POST['start_date'] ?? '';
        $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $renewalDate = !empty($_POST['renewal_date']) ? $_POST['renewal_date'] : null;
        $autoRenew = isset($_POST['auto_renew']) ? 1 : 0;
        $billingFrequency = $_POST['billing_frequency'] ?? 'monthly';
        $contractValue = !empty($_POST['contract_value']) ? (float)$_POST['contract_value'] : 0;
        $currency = $_POST['currency'] ?? 'AED';
        $paymentTerms = trim($_POST['payment_terms'] ?? '');
        $scopeOfWork = trim($_POST['scope_of_work'] ?? '');
        $termsConditions = trim($_POST['terms_and_conditions'] ?? '');
        $slaRequirements = trim($_POST['sla_requirements'] ?? '');
        $penaltyClauses = trim($_POST['penalty_clauses'] ?? '');
        $status = $_POST['status'] ?? 'draft';
        $signedDate = !empty($_POST['signed_date']) ? $_POST['signed_date'] : null;
        $signedBy = trim($_POST['signed_by'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if (empty($agreementNumber)) {
            $error = "Agreement number is required";
        } elseif (empty($agreementName)) {
            $error = "Agreement name is required";
        } elseif (empty($vendorId)) {
            $error = "Vendor is required";
        } elseif (empty($startDate)) {
            $error = "Start date is required";
        } else {
            try {
                if ($isEdit) {
                    $stmt = $conn->prepare("
                        UPDATE re_service_agreements 
                        SET agreement_number = ?, agreement_name = ?, vendor_id = ?, service_type = ?,
                            start_date = ?, end_date = ?, renewal_date = ?, auto_renew = ?,
                            billing_frequency = ?, contract_value = ?, currency = ?, payment_terms = ?,
                            scope_of_work = ?, terms_and_conditions = ?, sla_requirements = ?,
                            penalty_clauses = ?, status = ?, signed_date = ?, signed_by = ?,
                            notes = ?, updated_at = NOW()
                        WHERE id = ? AND company_id = ?
                    ");
                    $stmt->execute([
                        $agreementNumber, $agreementName, $vendorId, $serviceType,
                        $startDate, $endDate, $renewalDate, $autoRenew,
                        $billingFrequency, $contractValue, $currency, $paymentTerms,
                        $scopeOfWork, $termsConditions, $slaRequirements,
                        $penaltyClauses, $status, $signedDate, $signedBy,
                        $notes,
                        $agreement['id'], $currentCompanyId
                    ]);
                    $success = "Agreement updated successfully";
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO re_service_agreements 
                        (company_id, vendor_id, agreement_number, agreement_name, service_type,
                         start_date, end_date, renewal_date, auto_renew,
                         billing_frequency, contract_value, currency, payment_terms,
                         scope_of_work, terms_and_conditions, sla_requirements,
                         penalty_clauses, status, signed_date, signed_by,
                         notes, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $currentCompanyId, $vendorId, $agreementNumber, $agreementName, $serviceType,
                        $startDate, $endDate, $renewalDate, $autoRenew,
                        $billingFrequency, $contractValue, $currency, $paymentTerms,
                        $scopeOfWork, $termsConditions, $slaRequirements,
                        $penaltyClauses, $status, $signedDate, $signedBy,
                        $notes, $userId
                    ]);
                    $agreementId = $conn->lastInsertId();
                    $success = "Agreement created successfully";
                    header('Location: vendor_agreements.php?id=' . $agreementId);
                    exit;
                }
                
                // Reload agreement
                if ($isEdit) {
                    $stmt = $conn->prepare("
                        SELECT sa.*, v.vendor_name 
                        FROM re_service_agreements sa
                        JOIN re_vendors v ON v.id = sa.vendor_id
                        WHERE sa.id = ? AND sa.company_id = ?
                    ");
                    $stmt->execute([$agreement['id'], $currentCompanyId]);
                    $agreement = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

// Get vendors for dropdown
$vendors = $conn->prepare("SELECT id, vendor_name FROM re_vendors WHERE company_id = ? AND status = 'active' ORDER BY vendor_name");
$vendors->execute([$currentCompanyId]);
$vendors = $vendors->fetchAll(PDO::FETCH_ASSOC);

// Get agreements list if not editing
$agreements = [];
if (!$isEdit) {
    $where = ["sa.company_id = ?"];
    $params = [$currentCompanyId];
    
    if ($vendorId) {
        $where[] = "sa.vendor_id = ?";
        $params[] = $vendorId;
    }
    
    $filterStatus = $_GET['status'] ?? 'all';
    if ($filterStatus !== 'all') {
        $where[] = "sa.status = ?";
        $params[] = $filterStatus;
    }
    
    $agreementsStmt = $conn->prepare("
        SELECT sa.*, v.vendor_name, COUNT(DISTINCT vs.id) as service_count
        FROM re_service_agreements sa
        JOIN re_vendors v ON v.id = sa.vendor_id
        LEFT JOIN re_vendor_services vs ON vs.agreement_id = sa.id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY sa.id
        ORDER BY sa.start_date DESC
    ");
    $agreementsStmt->execute($params);
    $agreements = $agreementsStmt->fetchAll(PDO::FETCH_ASSOC);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function getStatusBadge($status) {
    $badges = ['draft' => 'bg-secondary', 'active' => 'bg-success', 'expired' => 'bg-danger', 'terminated' => 'bg-dark', 'renewed' => 'bg-info'];
    return $badges[$status] ?? 'bg-secondary';
}

// Set page title and include layout
$pageTitle = ($isEdit ? 'Edit' : 'Service') . ' Agreements';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-file-text"></i> Service Agreements</h1>
            <?php if (!$isEdit): ?>
                <a href="vendor_agreements.php?action=add<?= $vendorId ? '&vendor_id=' . $vendorId : '' ?>" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> New Agreement
                </a>
            <?php else: ?>
                <a href="vendor_agreements.php<?= $vendorId ? '?vendor_id=' . $vendorId : '' ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Agreements
                </a>
            <?php endif; ?>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($isEdit): ?>
            <!-- Edit/View Agreement Form -->
            <form method="POST" class="card">
                <?php csrf_field(); ?>
                <div class="card-body">
                    <h5 class="mb-3">Agreement Information</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Agreement Number *</label>
                            <input type="text" name="agreement_number" class="form-control" 
                                   value="<?= h($agreement['agreement_number'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Agreement Name *</label>
                            <input type="text" name="agreement_name" class="form-control" 
                                   value="<?= h($agreement['agreement_name'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Vendor *</label>
                            <select name="vendor_id" class="form-select" required>
                                <option value="">Select Vendor</option>
                                <?php foreach ($vendors as $v): ?>
                                    <option value="<?= $v['id'] ?>" 
                                            <?= ($agreement['vendor_id'] ?? null) == $v['id'] ? 'selected' : '' ?>>
                                        <?= h($v['vendor_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Service Type *</label>
                            <select name="service_type" class="form-select" required>
                                <option value="maintenance" <?= ($agreement['service_type'] ?? '') === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                <option value="cleaning" <?= ($agreement['service_type'] ?? '') === 'cleaning' ? 'selected' : '' ?>>Cleaning</option>
                                <option value="security" <?= ($agreement['service_type'] ?? '') === 'security' ? 'selected' : '' ?>>Security</option>
                                <option value="landscaping" <?= ($agreement['service_type'] ?? '') === 'landscaping' ? 'selected' : '' ?>>Landscaping</option>
                                <option value="plumbing" <?= ($agreement['service_type'] ?? '') === 'plumbing' ? 'selected' : '' ?>>Plumbing</option>
                                <option value="electrical" <?= ($agreement['service_type'] ?? '') === 'electrical' ? 'selected' : '' ?>>Electrical</option>
                                <option value="hvac" <?= ($agreement['service_type'] ?? '') === 'hvac' ? 'selected' : '' ?>>HVAC</option>
                                <option value="pest_control" <?= ($agreement['service_type'] ?? '') === 'pest_control' ? 'selected' : '' ?>>Pest Control</option>
                                <option value="waste_management" <?= ($agreement['service_type'] ?? '') === 'waste_management' ? 'selected' : '' ?>>Waste Management</option>
                                <option value="other" <?= ($agreement['service_type'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Start Date *</label>
                            <input type="date" name="start_date" class="form-control" 
                                   value="<?= $agreement['start_date'] ?? '' ?>" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-control" 
                                   value="<?= $agreement['end_date'] ?? '' ?>">
                            <small class="text-muted">Leave empty for ongoing</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Renewal Date</label>
                            <input type="date" name="renewal_date" class="form-control" 
                                   value="<?= $agreement['renewal_date'] ?? '' ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="auto_renew" id="autoRenew" 
                                       value="1" <?= ($agreement['auto_renew'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="autoRenew">
                                    Auto Renew
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Billing Frequency</label>
                            <select name="billing_frequency" class="form-select">
                                <option value="one_time" <?= ($agreement['billing_frequency'] ?? 'monthly') === 'one_time' ? 'selected' : '' ?>>One Time</option>
                                <option value="monthly" <?= ($agreement['billing_frequency'] ?? 'monthly') === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                <option value="quarterly" <?= ($agreement['billing_frequency'] ?? '') === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                                <option value="semi_annual" <?= ($agreement['billing_frequency'] ?? '') === 'semi_annual' ? 'selected' : '' ?>>Semi-Annual</option>
                                <option value="annual" <?= ($agreement['billing_frequency'] ?? '') === 'annual' ? 'selected' : '' ?>>Annual</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contract Value</label>
                            <input type="number" step="0.01" name="contract_value" class="form-control" 
                                   value="<?= $agreement['contract_value'] ?? '0' ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Currency</label>
                            <select name="currency" class="form-select">
                                <option value="AED" <?= ($agreement['currency'] ?? 'AED') === 'AED' ? 'selected' : '' ?>>AED</option>
                                <option value="USD" <?= ($agreement['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>USD</option>
                                <option value="EUR" <?= ($agreement['currency'] ?? '') === 'EUR' ? 'selected' : '' ?>>EUR</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Terms</label>
                        <input type="text" name="payment_terms" class="form-control" 
                               value="<?= h($agreement['payment_terms'] ?? '') ?>" 
                               placeholder="e.g., Net 30, COD, etc.">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Scope of Work</label>
                        <textarea name="scope_of_work" class="form-control" rows="4"><?= h($agreement['scope_of_work'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Terms and Conditions</label>
                        <textarea name="terms_and_conditions" class="form-control" rows="4"><?= h($agreement['terms_and_conditions'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">SLA Requirements</label>
                        <textarea name="sla_requirements" class="form-control" rows="3"><?= h($agreement['sla_requirements'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Penalty Clauses</label>
                        <textarea name="penalty_clauses" class="form-control" rows="3"><?= h($agreement['penalty_clauses'] ?? '') ?></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="draft" <?= ($agreement['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                                <option value="active" <?= ($agreement['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="expired" <?= ($agreement['status'] ?? '') === 'expired' ? 'selected' : '' ?>>Expired</option>
                                <option value="terminated" <?= ($agreement['status'] ?? '') === 'terminated' ? 'selected' : '' ?>>Terminated</option>
                                <option value="renewed" <?= ($agreement['status'] ?? '') === 'renewed' ? 'selected' : '' ?>>Renewed</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Signed Date</label>
                            <input type="date" name="signed_date" class="form-control" 
                                   value="<?= $agreement['signed_date'] ?? '' ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Signed By</label>
                        <input type="text" name="signed_by" class="form-control" 
                               value="<?= h($agreement['signed_by'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"><?= h($agreement['notes'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Save Agreement
                    </button>
                    <a href="vendor_agreements.php<?= $vendorId ? '?vendor_id=' . $vendorId : '' ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        <?php else: ?>
            <!-- Agreements List -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Agreements (<?= count($agreements) ?>)</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($agreements)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> No agreements found. 
                            <a href="vendor_agreements.php?action=add<?= $vendorId ? '&vendor_id=' . $vendorId : '' ?>">Create your first agreement</a>.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Agreement</th>
                                        <th>Vendor</th>
                                        <th>Service Type</th>
                                        <th>Start Date</th>
                                        <th>End Date</th>
                                        <th>Value</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($agreements as $agr): ?>
                                        <tr>
                                            <td>
                                                <strong><?= h($agr['agreement_name']) ?></strong><br>
                                                <small class="text-muted"><?= h($agr['agreement_number']) ?></small>
                                            </td>
                                            <td><?= h($agr['vendor_name']) ?></td>
                                            <td><?= ucfirst(str_replace('_', ' ', $agr['service_type'])) ?></td>
                                            <td><?= date('M d, Y', strtotime($agr['start_date'])) ?></td>
                                            <td><?= $agr['end_date'] ? date('M d, Y', strtotime($agr['end_date'])) : 'Ongoing' ?></td>
                                            <td><?= number_format($agr['contract_value'], 2) ?> <?= h($agr['currency']) ?></td>
                                            <td>
                                                <span class="badge <?= getStatusBadge($agr['status']) ?>">
                                                    <?= ucfirst($agr['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="vendor_agreements.php?id=<?= $agr['id'] ?>" class="btn btn-sm btn-primary">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

