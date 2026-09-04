<?php
/**
 * Real Estate Accounting - VAT Configuration
 * Manage VAT settings for the company
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$success = '';
$error = '';

// Get current VAT configuration
$stmt = $conn->prepare("
    SELECT vc.*, 
           coa_input.account_code as input_vat_code, coa_input.account_name as input_vat_name,
           coa_output.account_code as output_vat_code, coa_output.account_name as output_vat_name
    FROM re_vat_config vc
    LEFT JOIN re_chart_of_accounts coa_input ON coa_input.id = vc.input_vat_account_id
    LEFT JOIN re_chart_of_accounts coa_output ON coa_output.id = vc.output_vat_account_id
    WHERE vc.company_id = ? AND vc.is_active = 1
    ORDER BY vc.effective_from DESC
    LIMIT 1
");
$stmt->execute([$currentCompanyId]);
$vatConfig = $stmt->fetch(PDO::FETCH_ASSOC);

// Get VAT accounts for dropdown
$vatAccounts = $conn->prepare("
    SELECT id, account_code, account_name
    FROM re_chart_of_accounts
    WHERE company_id = ? 
    AND account_code IN ('2310', '2320')
    AND is_active = 1
    ORDER BY account_code
");
$vatAccounts->execute([$currentCompanyId]);
$allVatAccounts = $vatAccounts->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $vatRate = !empty($_POST['vat_rate']) ? (float)$_POST['vat_rate'] : 5.00;
    $vatRegNumber = trim($_POST['vat_registration_number'] ?? '');
    $inputVatAccountId = !empty($_POST['input_vat_account_id']) ? (int)$_POST['input_vat_account_id'] : null;
    $outputVatAccountId = !empty($_POST['output_vat_account_id']) ? (int)$_POST['output_vat_account_id'] : null;
    $effectiveFrom = !empty($_POST['effective_from']) ? $_POST['effective_from'] : date('Y-m-d');
    $effectiveTo = !empty($_POST['effective_to']) ? $_POST['effective_to'] : null;
    
    // Validation
    if ($vatRate < 0 || $vatRate > 100) {
        $error = "VAT rate must be between 0 and 100";
    } elseif (!$inputVatAccountId || !$outputVatAccountId) {
        $error = "Both Input VAT and Output VAT accounts are required";
    } else {
        try {
            // Deactivate existing active config
            if ($vatConfig) {
                $stmt = $conn->prepare("
                    UPDATE re_vat_config
                    SET is_active = 0, effective_to = ?
                    WHERE company_id = ? AND is_active = 1
                ");
                $stmt->execute([date('Y-m-d', strtotime('-1 day')), $currentCompanyId]);
            }
            
            // Create new VAT configuration
            $stmt = $conn->prepare("
                INSERT INTO re_vat_config 
                (company_id, vat_rate, vat_registration_number, input_vat_account_id, 
                 output_vat_account_id, effective_from, effective_to, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $currentCompanyId, 
                $vatRate, 
                $vatRegNumber ?: null,
                $inputVatAccountId,
                $outputVatAccountId,
                $effectiveFrom,
                $effectiveTo ?: null
            ]);
            
            $success = "VAT configuration saved successfully";
            
            // Refresh config
            $stmt->execute([$currentCompanyId]);
            $vatConfig = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            $error = "Error saving VAT configuration: " . $e->getMessage();
        }
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'VAT Configuration';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle"></i> <?= h($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle"></i> <?= h($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">
        <i class="bi bi-receipt-cutoff"></i> VAT Configuration
    </div>
    <div>
        <a href="vat_report.php" class="btn btn-outline-primary">
            <i class="bi bi-graph-up"></i> View VAT Report
        </a>
    </div>
</div>

<!-- Current Configuration -->
<?php if ($vatConfig): ?>
<div class="card card-round mb-4">
    <div class="card-header bg-light">
        <h6 class="mb-0"><i class="bi bi-info-circle"></i> Current Active Configuration</h6>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <strong>VAT Rate:</strong><br>
                <span class="h5"><?= number_format($vatConfig['vat_rate'], 2) ?>%</span>
            </div>
            <div class="col-md-3">
                <strong>VAT Registration Number:</strong><br>
                <?= h($vatConfig['vat_registration_number'] ?: 'Not set') ?>
            </div>
            <div class="col-md-3">
                <strong>Effective From:</strong><br>
                <?= date('F d, Y', strtotime($vatConfig['effective_from'])) ?>
            </div>
            <div class="col-md-3">
                <strong>Status:</strong><br>
                <span class="badge bg-success">Active</span>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-md-6">
                <strong>Input VAT Account:</strong><br>
                <code><?= h($vatConfig['input_vat_code']) ?></code> - <?= h($vatConfig['input_vat_name']) ?>
            </div>
            <div class="col-md-6">
                <strong>Output VAT Account:</strong><br>
                <code><?= h($vatConfig['output_vat_code']) ?></code> - <?= h($vatConfig['output_vat_name']) ?>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i> No VAT configuration found. Please set up VAT configuration below.
</div>
<?php endif; ?>

<!-- VAT Configuration Form -->
<div class="card card-round">
    <div class="card-header bg-light">
        <h6 class="mb-0">
            <i class="bi bi-gear"></i> <?= $vatConfig ? 'Update' : 'Create' ?> VAT Configuration
        </h6>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrf_field() ?>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">VAT Rate (%) *</label>
                    <input type="number" step="0.01" min="0" max="100" name="vat_rate" 
                           class="form-control" value="<?= $vatConfig ? h($vatConfig['vat_rate']) : '5.00' ?>" 
                           required>
                    <small class="text-muted">UAE standard VAT rate is 5%</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">VAT Registration Number</label>
                    <input type="text" name="vat_registration_number" class="form-control" 
                           value="<?= $vatConfig ? h($vatConfig['vat_registration_number']) : '' ?>" 
                           placeholder="e.g., 100012345600003">
                    <small class="text-muted">FTA VAT registration number</small>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Input VAT Account *</label>
                    <select name="input_vat_account_id" class="form-select" required>
                        <option value="">-- Select Account --</option>
                        <?php foreach ($allVatAccounts as $acc): ?>
                            <?php if ($acc['account_code'] === '2320'): ?>
                                <option value="<?= $acc['id'] ?>" 
                                        <?= ($vatConfig && $vatConfig['input_vat_account_id'] == $acc['id']) ? 'selected' : '' ?>>
                                    <?= h($acc['account_code']) ?> - <?= h($acc['account_name']) ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Account for Input VAT (VAT paid on purchases)</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Output VAT Account *</label>
                    <select name="output_vat_account_id" class="form-select" required>
                        <option value="">-- Select Account --</option>
                        <?php foreach ($allVatAccounts as $acc): ?>
                            <?php if ($acc['account_code'] === '2310'): ?>
                                <option value="<?= $acc['id'] ?>" 
                                        <?= ($vatConfig && $vatConfig['output_vat_account_id'] == $acc['id']) ? 'selected' : '' ?>>
                                    <?= h($acc['account_code']) ?> - <?= h($acc['account_name']) ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Account for Output VAT (VAT collected on sales)</small>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Effective From *</label>
                    <input type="date" name="effective_from" class="form-control" 
                           value="<?= $vatConfig ? h($vatConfig['effective_from']) : date('Y-m-d') ?>" 
                           required>
                    <small class="text-muted">Date from which this configuration is effective</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Effective To</label>
                    <input type="date" name="effective_to" class="form-control" 
                           value="<?= $vatConfig && $vatConfig['effective_to'] ? h($vatConfig['effective_to']) : '' ?>">
                    <small class="text-muted">Leave blank if currently active</small>
                </div>
            </div>
            
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> <strong>Note:</strong> Creating a new configuration will automatically deactivate the previous one. This allows you to maintain a history of VAT rate changes.
            </div>
            
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle"></i> <?= $vatConfig ? 'Update' : 'Create' ?> Configuration
                </button>
            </div>
        </form>
    </div>
</div>

<!-- VAT Information -->
<div class="card card-round mt-4">
    <div class="card-header bg-light">
        <h6 class="mb-0"><i class="bi bi-book"></i> UAE VAT Information</h6>
    </div>
    <div class="card-body">
        <h6>Standard VAT Rate</h6>
        <p>The standard VAT rate in the UAE is <strong>5%</strong>. This applies to most goods and services, including rental income from real estate.</p>
        
        <h6>VAT Registration</h6>
        <p>Companies with annual taxable supplies exceeding AED 375,000 must register for VAT with the Federal Tax Authority (FTA).</p>
        
        <h6>VAT on Real Estate</h6>
        <ul>
            <li><strong>Residential Rentals:</strong> Generally exempt from VAT</li>
            <li><strong>Commercial Rentals:</strong> Subject to 5% VAT</li>
            <li><strong>Service Charges:</strong> Subject to 5% VAT</li>
            <li><strong>Penalties:</strong> Subject to 5% VAT</li>
        </ul>
        
        <h6>VAT Return</h6>
        <p>VAT returns must be filed quarterly or monthly (depending on registration) with the FTA. Use the <a href="vat_report.php">VAT Report</a> to generate data for your VAT return.</p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
