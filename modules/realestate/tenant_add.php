<?php
/**
 * Real Estate Module - Add/Edit Tenant
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

$tenantId = !empty($_GET['id']) ? (int)$_GET['id'] : null;
$tenant = null;
$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $tenantType = $_POST['tenant_type'] ?? 'individual';
    $companyName = trim($_POST['company_name'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $phoneAlt = trim($_POST['phone_alt'] ?? '');
    $idType = $_POST['id_type'] ?? 'emirates_id';
    $idNumber = trim($_POST['id_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $emergencyContactName = trim($_POST['emergency_contact_name'] ?? '');
    $emergencyContactPhone = trim($_POST['emergency_contact_phone'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    // Validation
    if ($tenantType === 'company' && !$companyName) {
        $error = "Company Name is required for company tenants";
    } elseif ($tenantType === 'individual' && (!$firstName || !$lastName)) {
        $error = "First Name and Last Name are required for individual tenants";
    } elseif (!$idNumber) {
        $error = "ID Number is required";
    }
    
    if (!$error) {
        if ($tenantId) {
            // Update
            $stmt = $conn->prepare("
                UPDATE re_tenants 
                SET tenant_type = ?, company_name = ?, first_name = ?, last_name = ?, email = ?, phone = ?, phone_alt = ?,
                    id_type = ?, id_number = ?, address = ?, emergency_contact_name = ?,
                    emergency_contact_phone = ?, notes = ?, is_active = ?
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([$tenantType, $companyName ?: null, $firstName, $lastName, $email, $phone, $phoneAlt, $idType, $idNumber, 
                          $address, $emergencyContactName, $emergencyContactPhone, $notes, $isActive, 
                          $tenantId, $currentCompanyId]);

            // Keep portal greeting name in sync with ERP tenant master (Customer App uses me.display_name).
            $portalDisplayName = $tenantType === 'company'
                ? trim($companyName)
                : trim($firstName . ' ' . $lastName);
            if ($portalDisplayName !== '') {
                try {
                    $sync = $conn->prepare("
                        UPDATE tenant_portal_users
                        SET display_name = ?
                        WHERE tenant_id = ? AND company_id = ?
                    ");
                    $sync->execute([$portalDisplayName, $tenantId, $currentCompanyId]);
                } catch (Throwable $e) {
                    // Fail soft — app still prefers re_tenants via API payload.
                    error_log('tenant_add portal display_name sync: ' . $e->getMessage());
                }
            }

            $success = "Tenant updated successfully";
        } else {
            // Insert
            $stmt = $conn->prepare("
                INSERT INTO re_tenants 
                (company_id, tenant_type, company_name, first_name, last_name, email, phone, phone_alt, id_type, id_number,
                 address, emergency_contact_name, emergency_contact_phone, notes, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$currentCompanyId, $tenantType, $companyName ?: null, $firstName, $lastName, $email, $phone, $phoneAlt, 
                          $idType, $idNumber, $address, $emergencyContactName, $emergencyContactPhone, 
                          $notes, $isActive]);
            $success = "Tenant added successfully";
            header('Location: tenants.php');
            exit;
        }
    }
}

// Load tenant if editing
if ($tenantId) {
    $stmt = $conn->prepare("SELECT * FROM re_tenants WHERE id = ? AND company_id = ?");
    $stmt->execute([$tenantId, $currentCompanyId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) {
        header('Location: tenants.php');
        exit;
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = ($tenantId ? 'Edit' : 'Add') . ' Tenant';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <h1><?= $tenantId ? 'Edit' : 'Add' ?> Tenant</h1>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= h($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST" id="tenantForm">
                    <?php csrf_field(); ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tenant Type *</label>
                            <select name="tenant_type" class="form-select" id="tenantTypeSelect" required>
                                <option value="individual" <?= (!$tenant || ($tenant && $tenant['tenant_type'] == 'individual')) ? 'selected' : '' ?>>Individual</option>
                                <option value="company" <?= ($tenant && $tenant['tenant_type'] == 'company') ? 'selected' : '' ?>>Company</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3" id="companyNameField" style="display: <?= ($tenant && $tenant['tenant_type'] == 'company') ? 'block' : 'none' ?>;">
                            <label class="form-label">Company Name *</label>
                            <input type="text" class="form-control" name="company_name" id="companyNameInput" value="<?= $tenant ? h($tenant['company_name'] ?? '') : '' ?>">
                        </div>
                    </div>

                    <div class="row" id="individualFields">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">First Name <span id="firstNameRequired">*</span></label>
                            <input type="text" class="form-control" name="first_name" id="firstNameInput" value="<?= $tenant ? h($tenant['first_name']) : '' ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Last Name <span id="lastNameRequired">*</span></label>
                            <input type="text" class="form-control" name="last_name" id="lastNameInput" value="<?= $tenant ? h($tenant['last_name']) : '' ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="<?= $tenant ? h($tenant['email']) : '' ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone" value="<?= $tenant ? h($tenant['phone']) : '' ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Alternate Phone</label>
                            <input type="text" class="form-control" name="phone_alt" value="<?= $tenant ? h($tenant['phone_alt']) : '' ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ID Type *</label>
                            <select name="id_type" class="form-select" id="idTypeSelect" required>
                                <option value="emirates_id" <?= ($tenant && $tenant['id_type'] == 'emirates_id') ? 'selected' : '' ?>>Emirates ID</option>
                                <option value="passport" <?= ($tenant && $tenant['id_type'] == 'passport') ? 'selected' : '' ?>>Passport</option>
                                <option value="visa" <?= ($tenant && $tenant['id_type'] == 'visa') ? 'selected' : '' ?>>Visa</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ID Number *</label>
                            <?php
                            // Format ID number for display if it's Emirates ID and doesn't have dashes
                            $idNumberDisplay = '';
                            if ($tenant && $tenant['id_number']) {
                                $idNumberDisplay = $tenant['id_number'];
                                // If Emirates ID and doesn't contain dashes, format it
                                if ($tenant['id_type'] == 'emirates_id' && strpos($idNumberDisplay, '-') === false) {
                                    $numbers = preg_replace('/\D/', '', $idNumberDisplay);
                                    if (strlen($numbers) >= 3) {
                                        $formatted = substr($numbers, 0, 3);
                                        if (strlen($numbers) > 3) {
                                            $formatted .= '-' . substr($numbers, 3, 4);
                                        }
                                        if (strlen($numbers) > 7) {
                                            $formatted .= '-' . substr($numbers, 7, 7);
                                        }
                                        if (strlen($numbers) > 14) {
                                            $formatted .= '-' . substr($numbers, 14, 1);
                                        }
                                        $idNumberDisplay = $formatted;
                                    }
                                }
                            }
                            ?>
                            <input type="text" class="form-control" name="id_number" id="idNumberInput" value="<?= h($idNumberDisplay) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" <?= ($tenant && $tenant['is_active']) ? 'checked' : 'checked' ?>>
                                <label class="form-check-label" for="isActive">Active</label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" rows="2"><?= $tenant ? h($tenant['address']) : '' ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Emergency Contact Name</label>
                            <input type="text" class="form-control" name="emergency_contact_name" value="<?= $tenant ? h($tenant['emergency_contact_name']) : '' ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Emergency Contact Phone</label>
                            <input type="text" class="form-control" name="emergency_contact_phone" value="<?= $tenant ? h($tenant['emergency_contact_phone']) : '' ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3"><?= $tenant ? h($tenant['notes']) : '' ?></textarea>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary"><?= $tenantId ? 'Update' : 'Add' ?> Tenant</button>
                        <a href="tenants.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

    <script>
        // Handle tenant type change
        document.getElementById('tenantTypeSelect').addEventListener('change', function() {
            const tenantType = this.value;
            const companyNameField = document.getElementById('companyNameField');
            const individualFields = document.getElementById('individualFields');
            const companyNameInput = document.getElementById('companyNameInput');
            const firstNameInput = document.getElementById('firstNameInput');
            const lastNameInput = document.getElementById('lastNameInput');
            const firstNameRequired = document.getElementById('firstNameRequired');
            const lastNameRequired = document.getElementById('lastNameRequired');
            
            if (tenantType === 'company') {
                companyNameField.style.display = 'block';
                companyNameInput.required = true;
                firstNameInput.required = false;
                lastNameInput.required = false;
                firstNameRequired.style.display = 'none';
                lastNameRequired.style.display = 'none';
            } else {
                companyNameField.style.display = 'none';
                companyNameInput.required = false;
                firstNameInput.required = true;
                lastNameInput.required = true;
                firstNameRequired.style.display = 'inline';
                lastNameRequired.style.display = 'inline';
            }
        });

        // Format UAE ID Number (Emirates ID) automatically
        // Format: XXX-XXXX-XXXXXXX-X (3-4-7-1 digits)
        function formatUAEID(value) {
            // Remove all non-numeric characters
            const numbers = value.replace(/\D/g, '');
            
            // Limit to 15 digits (3+4+7+1)
            const limited = numbers.substring(0, 15);
            
            // Add dashes at appropriate positions
            let formatted = '';
            if (limited.length > 0) {
                formatted = limited.substring(0, 3);
            }
            if (limited.length > 3) {
                formatted += '-' + limited.substring(3, 7);
            }
            if (limited.length > 7) {
                formatted += '-' + limited.substring(7, 14);
            }
            if (limited.length > 14) {
                formatted += '-' + limited.substring(14, 15);
            }
            
            return formatted;
        }

        const idNumberInput = document.getElementById('idNumberInput');
        const idTypeSelect = document.getElementById('idTypeSelect');

        // Format on input (as user types)
        idNumberInput.addEventListener('input', function(e) {
            if (idTypeSelect.value === 'emirates_id') {
                const cursorPosition = e.target.selectionStart;
                const oldValue = e.target.value;
                const newValue = formatUAEID(oldValue);
                
                // Calculate new cursor position
                const oldLength = oldValue.length;
                const newLength = newValue.length;
                const diff = newLength - oldLength;
                
                e.target.value = newValue;
                
                // Adjust cursor position
                const newCursorPosition = Math.max(0, cursorPosition + diff);
                e.target.setSelectionRange(newCursorPosition, newCursorPosition);
            }
        });

        // Format on paste
        idNumberInput.addEventListener('paste', function(e) {
            if (idTypeSelect.value === 'emirates_id') {
                setTimeout(() => {
                    e.target.value = formatUAEID(e.target.value);
                }, 0);
            }
        });

        // Re-format when ID type changes
        idTypeSelect.addEventListener('change', function() {
            if (this.value === 'emirates_id' && idNumberInput.value) {
                idNumberInput.value = formatUAEID(idNumberInput.value);
            }
        });

        // Format on page load if editing and ID type is Emirates ID
        <?php if ($tenant && $tenant['id_type'] == 'emirates_id' && $tenant['id_number']): ?>
        document.addEventListener('DOMContentLoaded', function() {
            if (idTypeSelect.value === 'emirates_id' && idNumberInput.value) {
                idNumberInput.value = formatUAEID(idNumberInput.value);
            }
        });
        <?php endif; ?>
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

