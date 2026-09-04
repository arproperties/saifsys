<?php
/**
 * Real Estate Module - Add/Edit Vendor
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
$vendor = null;
$isEdit = false;

// Get vendor if editing
if (!empty($_GET['id'])) {
    $vendorId = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM re_vendors WHERE id = ? AND company_id = ?");
    $stmt->execute([$vendorId, $currentCompanyId]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($vendor) {
        $isEdit = true;
    } else {
        header('Location: vendors.php');
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $vendorName = trim($_POST['vendor_name'] ?? '');
    $vendorType = $_POST['vendor_type'] ?? 'contractor';
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $postalCode = trim($_POST['postal_code'] ?? '');
    $taxId = trim($_POST['tax_id'] ?? '');
    $licenseNumber = trim($_POST['license_number'] ?? '');
    $licenseExpiry = !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null;
    $insuranceProvider = trim($_POST['insurance_provider'] ?? '');
    $insurancePolicyNumber = trim($_POST['insurance_policy_number'] ?? '');
    $insuranceExpiry = !empty($_POST['insurance_expiry']) ? $_POST['insurance_expiry'] : null;
    $paymentTerms = trim($_POST['payment_terms'] ?? '');
    $bankName = trim($_POST['bank_name'] ?? '');
    $bankAccountNumber = trim($_POST['bank_account_number'] ?? '');
    $bankIban = trim($_POST['bank_iban'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $notes = trim($_POST['notes'] ?? '');
    
    if (empty($vendorName)) {
        $error = "Vendor name is required";
    } else {
        try {
            if ($isEdit) {
                $stmt = $conn->prepare("
                    UPDATE re_vendors 
                    SET vendor_name = ?, vendor_type = ?, contact_person = ?, email = ?, phone = ?, mobile = ?,
                        address = ?, city = ?, state = ?, country = ?, postal_code = ?,
                        tax_id = ?, license_number = ?, license_expiry = ?,
                        insurance_provider = ?, insurance_policy_number = ?, insurance_expiry = ?,
                        payment_terms = ?, bank_name = ?, bank_account_number = ?, bank_iban = ?,
                        status = ?, notes = ?, updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([
                    $vendorName, $vendorType, $contactPerson, $email, $phone, $mobile,
                    $address, $city, $state, $country, $postalCode,
                    $taxId, $licenseNumber, $licenseExpiry,
                    $insuranceProvider, $insurancePolicyNumber, $insuranceExpiry,
                    $paymentTerms, $bankName, $bankAccountNumber, $bankIban,
                    $status, $notes,
                    $vendor['id'], $currentCompanyId
                ]);
                $success = "Vendor updated successfully";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO re_vendors 
                    (company_id, vendor_name, vendor_type, contact_person, email, phone, mobile,
                     address, city, state, country, postal_code,
                     tax_id, license_number, license_expiry,
                     insurance_provider, insurance_policy_number, insurance_expiry,
                     payment_terms, bank_name, bank_account_number, bank_iban,
                     status, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $currentCompanyId, $vendorName, $vendorType, $contactPerson, $email, $phone, $mobile,
                    $address, $city, $state, $country, $postalCode,
                    $taxId, $licenseNumber, $licenseExpiry,
                    $insuranceProvider, $insurancePolicyNumber, $insuranceExpiry,
                    $paymentTerms, $bankName, $bankAccountNumber, $bankIban,
                    $status, $notes
                ]);
                $vendorId = $conn->lastInsertId();
                $success = "Vendor created successfully";
                header('Location: vendors_view.php?id=' . $vendorId);
                exit;
            }
            
            // Reload vendor
            if ($isEdit) {
                $stmt = $conn->prepare("SELECT * FROM re_vendors WHERE id = ? AND company_id = ?");
                $stmt->execute([$vendor['id'], $currentCompanyId]);
                $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = ($isEdit ? 'Edit' : 'Add') . ' Vendor';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label"><i class="bi bi-<?= $isEdit ? 'pencil' : 'plus-circle' ?>"></i> <?= $isEdit ? 'Edit' : 'Add' ?> Vendor</div>
            <a href="vendors.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Vendors
            </a>
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

        <form method="POST" class="card">
            <?php csrf_field(); ?>
            <div class="card-body">
                <h5 class="mb-3">Basic Information</h5>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Vendor Name *</label>
                        <input type="text" name="vendor_name" class="form-control" 
                               value="<?= h($vendor['vendor_name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Vendor Type *</label>
                        <select name="vendor_type" class="form-select" required>
                            <option value="contractor" <?= ($vendor['vendor_type'] ?? 'contractor') === 'contractor' ? 'selected' : '' ?>>Contractor</option>
                            <option value="supplier" <?= ($vendor['vendor_type'] ?? '') === 'supplier' ? 'selected' : '' ?>>Supplier</option>
                            <option value="service_provider" <?= ($vendor['vendor_type'] ?? '') === 'service_provider' ? 'selected' : '' ?>>Service Provider</option>
                            <option value="maintenance" <?= ($vendor['vendor_type'] ?? '') === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                            <option value="cleaning" <?= ($vendor['vendor_type'] ?? '') === 'cleaning' ? 'selected' : '' ?>>Cleaning</option>
                            <option value="security" <?= ($vendor['vendor_type'] ?? '') === 'security' ? 'selected' : '' ?>>Security</option>
                            <option value="other" <?= ($vendor['vendor_type'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" class="form-control" 
                               value="<?= h($vendor['contact_person'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Status *</label>
                        <select name="status" class="form-select" required>
                            <option value="active" <?= ($vendor['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= ($vendor['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            <option value="suspended" <?= ($vendor['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                            <option value="blacklisted" <?= ($vendor['status'] ?? '') === 'blacklisted' ? 'selected' : '' ?>>Blacklisted</option>
                        </select>
                    </div>
                </div>

                <hr>
                <h5 class="mb-3">Contact Information</h5>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" 
                               value="<?= h($vendor['email'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" 
                               value="<?= h($vendor['phone'] ?? '') ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Mobile</label>
                        <input type="text" name="mobile" class="form-control" 
                               value="<?= h($vendor['mobile'] ?? '') ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2"><?= h($vendor['address'] ?? '') ?></textarea>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">City</label>
                        <input type="text" name="city" class="form-control" 
                               value="<?= h($vendor['city'] ?? '') ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">State</label>
                        <input type="text" name="state" class="form-control" 
                               value="<?= h($vendor['state'] ?? '') ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Country</label>
                        <input type="text" name="country" class="form-control" 
                               value="<?= h($vendor['country'] ?? 'UAE') ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Postal Code</label>
                        <input type="text" name="postal_code" class="form-control" 
                               value="<?= h($vendor['postal_code'] ?? '') ?>">
                    </div>
                </div>

                <hr>
                <h5 class="mb-3">Legal & Compliance</h5>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Tax ID</label>
                        <input type="text" name="tax_id" class="form-control" 
                               value="<?= h($vendor['tax_id'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">License Number</label>
                        <input type="text" name="license_number" class="form-control" 
                               value="<?= h($vendor['license_number'] ?? '') ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">License Expiry</label>
                        <input type="date" name="license_expiry" class="form-control" 
                               value="<?= $vendor['license_expiry'] ?? '' ?>">
                    </div>
                </div>

                <hr>
                <h5 class="mb-3">Insurance</h5>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Insurance Provider</label>
                        <input type="text" name="insurance_provider" class="form-control" 
                               value="<?= h($vendor['insurance_provider'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Policy Number</label>
                        <input type="text" name="insurance_policy_number" class="form-control" 
                               value="<?= h($vendor['insurance_policy_number'] ?? '') ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Insurance Expiry</label>
                        <input type="date" name="insurance_expiry" class="form-control" 
                               value="<?= $vendor['insurance_expiry'] ?? '' ?>">
                    </div>
                </div>

                <hr>
                <h5 class="mb-3">Payment Information</h5>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Payment Terms</label>
                        <input type="text" name="payment_terms" class="form-control" 
                               value="<?= h($vendor['payment_terms'] ?? '') ?>" 
                               placeholder="e.g., Net 30, COD, etc.">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Bank Name</label>
                        <input type="text" name="bank_name" class="form-control" 
                               value="<?= h($vendor['bank_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Account Number</label>
                        <input type="text" name="bank_account_number" class="form-control" 
                               value="<?= h($vendor['bank_account_number'] ?? '') ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">IBAN</label>
                        <input type="text" name="bank_iban" class="form-control" 
                               value="<?= h($vendor['bank_iban'] ?? '') ?>">
                    </div>
                </div>

                <hr>
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="4"><?= h($vendor['notes'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle"></i> <?= $isEdit ? 'Update' : 'Create' ?> Vendor
                </button>
                <a href="vendors.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

