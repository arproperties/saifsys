<?php
/**
 * App Settings Management Page
 */

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'update_settings') {
            $serviceFee = $_POST['service_fee_percentage'];
            $vat = $_POST['vat_percentage'];
            
            // Update service fee
            $stmt = $conn->prepare("UPDATE app_settings SET setting_value = ? WHERE setting_key = 'service_fee_percentage'");
            $stmt->execute([$serviceFee]);
            
            // Update VAT
            $stmt = $conn->prepare("UPDATE app_settings SET setting_value = ? WHERE setting_key = 'vat_percentage'");
            $stmt->execute([$vat]);
            
            $success = "Settings updated successfully!";
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get current settings
$settings = [];
$stmt = $conn->query("SELECT * FROM app_settings WHERE setting_key IN ('service_fee_percentage', 'vat_percentage')");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$serviceFeePercentage = $settings['service_fee_percentage'] ?? '5.00';
$vatPercentage = $settings['vat_percentage'] ?? '5.00';
?>

<?php if (isset($success)): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Settings Form -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-cog"></i> Pricing Settings
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <strong><i class="fas fa-info-circle"></i> How It Works:</strong>
                    <p class="mb-0 mt-2" style="font-size: 14px;">
                        These percentages are applied to every booking:
                    </p>
                    <ul class="mb-0 mt-2" style="font-size: 13px;">
                        <li><strong>Service Fee:</strong> Platform fee (% of subtotal after discount)</li>
                        <li><strong>VAT:</strong> Value Added Tax (% of total including service fee)</li>
                    </ul>
                </div>
                
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_settings">
                    
                    <div class="mb-4">
                        <label class="form-label">
                            Service Fee (%) 
                            <i class="fas fa-question-circle text-muted" 
                               data-bs-toggle="tooltip" 
                               title="Platform fee charged on each booking. Set to 0 to disable."></i>
                        </label>
                        <div class="input-group">
                            <input type="number" name="service_fee_percentage" class="form-control" 
                                   value="<?= htmlspecialchars($serviceFeePercentage) ?>"
                                   step="0.01" min="0" max="100" required>
                            <span class="input-group-text">%</span>
                        </div>
                        <small class="text-muted">
                            Current: <?= $serviceFeePercentage ?>% | Set to 0 to disable service fee
                        </small>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">
                            VAT / Tax (%) 
                            <i class="fas fa-question-circle text-muted" 
                               data-bs-toggle="tooltip" 
                               title="Tax applied to the total amount (after service fee). Set to 0 to disable."></i>
                        </label>
                        <div class="input-group">
                            <input type="number" name="vat_percentage" class="form-control" 
                                   value="<?= htmlspecialchars($vatPercentage) ?>"
                                   step="0.01" min="0" max="100" required>
                            <span class="input-group-text">%</span>
                        </div>
                        <small class="text-muted">
                            Current: <?= $vatPercentage ?>% | Set to 0 to disable VAT
                        </small>
                    </div>
                    
                    <div class="alert alert-secondary" style="font-size: 13px;">
                        <strong>💡 Pricing Example:</strong> Customer books service for AED 100<br>
                        <code>
                            Subtotal: AED 100.00<br>
                            Service Fee (<?= $serviceFeePercentage ?>%): +AED <?= number_format($serviceFeePercentage, 2) ?><br>
                            After Fee: AED <?= number_format(100 + (float)$serviceFeePercentage, 2) ?><br>
                            VAT (<?= $vatPercentage ?>%): +AED <?= number_format((100 + (float)$serviceFeePercentage) * ((float)$vatPercentage / 100), 2) ?><br>
                            <strong>Total: AED <?= number_format((100 + (float)$serviceFeePercentage) * (1 + (float)$vatPercentage / 100), 2) ?></strong>
                        </code>
                    </div>
                    
                    <button type="submit" class="btn btn-gradient w-100">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Information -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0 text-dark">
                    <i class="fas fa-calculator"></i> How Charges Are Applied
                </h5>
            </div>
            <div class="card-body">
                <h6 class="text-primary">Service Fee</h6>
                <p style="font-size: 14px;">
                    The service fee is a platform charge applied to each booking. It's calculated as a percentage 
                    of the subtotal (after any discounts).
                </p>
                <p style="font-size: 13px;" class="text-muted">
                    <strong>Formula:</strong> <code>Service Fee = (Subtotal - Discount) × (Service Fee % / 100)</code>
                </p>
                
                <hr class="my-4">
                
                <h6 class="text-primary">VAT / Tax</h6>
                <p style="font-size: 14px;">
                    VAT (Value Added Tax) is applied to the total amount including the service fee. 
                    This is the final charge before payment.
                </p>
                <p style="font-size: 13px;" class="text-muted">
                    <strong>Formula:</strong> <code>VAT = (Subtotal + Service Fee) × (VAT % / 100)</code>
                </p>
                
                <hr class="my-4">
                
                <h6 class="text-primary">Complete Calculation</h6>
                <div class="bg-light p-3 rounded" style="font-size: 13px;">
                    <div class="d-flex justify-content-between mb-1">
                        <span>1. Base Price + Hourly + Materials</span>
                        <strong>= Subtotal</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span>2. Subtotal - Discount (if any)</span>
                        <strong>= After Discount</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-1 text-primary">
                        <span>3. + Service Fee (<?= $serviceFeePercentage ?>%)</span>
                        <strong>= With Fee</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-1 text-success">
                        <span>4. + VAT (<?= $vatPercentage ?>%)</span>
                        <strong>= Final Total</strong>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <h6 class="text-warning"><i class="fas fa-exclamation-triangle"></i> Important Notes</h6>
                <ul style="font-size: 13px;">
                    <li>Changes take effect immediately for all new bookings</li>
                    <li>Existing bookings are not affected</li>
                    <li>Set to 0 to disable any charge</li>
                    <li>Price breakdown is shown to customers in the app</li>
                    <li>All percentages can have up to 2 decimal places (e.g., 5.50)</li>
                </ul>
            </div>
        </div>
    </div>
</div>

