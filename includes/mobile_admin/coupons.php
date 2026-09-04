<?php
/**
 * Coupons Management
 */

// Generate unique coupon code
function generateCouponCode($length = 8) {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'create' || $action === 'update') {
            $id = $_POST['id'] ?? null;
            $couponCode = $_POST['coupon_code'] ?? generateCouponCode();
            $couponType = $_POST['coupon_type'] ?? 'discount';
            $title = $_POST['title'] ?? '';
            $titleEn = $_POST['title_en'] ?? null;
            $titleAr = $_POST['title_ar'] ?? null;
            $discountType = $_POST['discount_type'];
            $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            $serviceId = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
            $zoneId = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;
            $amountType = $_POST['amount_type'];
            $amount = (float)$_POST['amount'];
            $minPurchaseAmount = (float)($_POST['min_purchase_amount'] ?? 0);
            $maxDiscountAmount = !empty($_POST['max_discount_amount']) ? (float)$_POST['max_discount_amount'] : null;
            $startDate = $_POST['start_date'] . ' 00:00:00';
            $endDate = $_POST['end_date'] . ' 23:59:59';
            $limitPerUser = !empty($_POST['limit_per_user']) ? (int)$_POST['limit_per_user'] : null;

            // Validate discount type requirements
            if ($discountType === 'category' && !$categoryId) {
                throw new Exception('Category is required for Category Wise coupon.');
            }
            if ($discountType === 'service' && !$serviceId) {
                throw new Exception('Service is required for Service Wise coupon.');
            }
            if ($discountType === 'mixed' && !$categoryId && !$serviceId) {
                throw new Exception('At least one category or service is required for Mixed coupon.');
            }

            // Check if coupon code already exists (for create)
            if ($action === 'create') {
                $checkStmt = $conn->prepare("SELECT id FROM coupons WHERE coupon_code = ?");
                $checkStmt->execute([$couponCode]);
                if ($checkStmt->fetch()) {
                    $couponCode = generateCouponCode(); // Generate new if exists
                }
            }

            if ($action === 'create') {
                $stmt = $conn->prepare("
                    INSERT INTO coupons 
                    (coupon_code, coupon_type, title, title_en, title_ar, discount_type, category_id, service_id, zone_id, 
                     amount_type, amount, min_purchase_amount, max_discount_amount, start_date, end_date, limit_per_user, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([
                    $couponCode, $couponType, $title, $titleEn, $titleAr, $discountType, $categoryId, $serviceId, $zoneId, 
                    $amountType, $amount, $minPurchaseAmount, $maxDiscountAmount, $startDate, $endDate, $limitPerUser
                ]);
                $success = "Coupon created successfully! Code: $couponCode";
            } else {
                $stmt = $conn->prepare("
                    UPDATE coupons 
                    SET coupon_code = ?, coupon_type = ?, title = ?, title_en = ?, title_ar = ?, 
                        discount_type = ?, category_id = ?, service_id = ?, zone_id = ?, 
                        amount_type = ?, amount = ?, min_purchase_amount = ?, max_discount_amount = ?, 
                        start_date = ?, end_date = ?, limit_per_user = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $couponCode, $couponType, $title, $titleEn, $titleAr, $discountType, $categoryId, $serviceId, $zoneId, 
                    $amountType, $amount, $minPurchaseAmount, $maxDiscountAmount, $startDate, $endDate, $limitPerUser, $id
                ]);
                $success = "Coupon updated successfully!";
            }
        } elseif ($action === 'delete') {
            $id = $_POST['id'];
            $stmt = $conn->prepare("DELETE FROM coupons WHERE id = ?");
            $stmt->execute([$id]);
            $success = "Coupon deleted successfully!";
        } elseif ($action === 'toggle_status') {
            $id = $_POST['id'];
            $stmt = $conn->prepare("UPDATE coupons SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$id]);
            $success = "Coupon status updated!";
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get all coupons
$coupons = $conn->query("
    SELECT c.*, 
           cat.name as category_name,
           s.name as service_name,
           (SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = c.id) as usage_count
    FROM coupons c
    LEFT JOIN service_categories cat ON c.category_id = cat.id
    LEFT JOIN services s ON c.service_id = s.id
    ORDER BY c.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get editing coupon if specified
$editCoupon = null;
if (isset($_GET['edit'])) {
    $stmt = $conn->prepare("SELECT * FROM coupons WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editCoupon = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get categories and services for dropdowns
$categories = $conn->query("SELECT id, name FROM service_categories WHERE is_active = 1 AND parent_id IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$services = $conn->query("SELECT id, name FROM services WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Alerts -->
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
    <!-- Coupon Form -->
    <div class="col-md-5">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-<?= $editCoupon ? 'edit' : 'plus' ?>"></i>
                    <?= $editCoupon ? 'Edit Coupon' : 'Add New Coupon' ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" id="couponForm">
                    <input type="hidden" name="action" value="<?= $editCoupon ? 'update' : 'create' ?>">
                    <?php if ($editCoupon): ?>
                        <input type="hidden" name="id" value="<?= $editCoupon['id'] ?>">
                    <?php endif; ?>
                    
                    <!-- Language Tabs -->
                    <ul class="nav nav-tabs mb-3" id="langTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="default-tab" data-bs-toggle="tab" data-bs-target="#default" type="button" role="tab">
                                Default
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="english-tab" data-bs-toggle="tab" data-bs-target="#english" type="button" role="tab">
                                English
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="arabic-tab" data-bs-toggle="tab" data-bs-target="#arabic" type="button" role="tab">
                                Arabic - العربية
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="langTabContent">
                        <!-- Default Tab -->
                        <div class="tab-pane fade show active" id="default" role="tabpanel">
                            <div class="mb-3">
                                <label class="form-label">Discount Title (Default) *</label>
                                <input type="text" name="title" class="form-control" required
                                       placeholder="Discount Title (Default)"
                                       value="<?= $editCoupon ? htmlspecialchars($editCoupon['title'] ?? '') : '' ?>">
                            </div>
                        </div>
                        
                        <!-- English Tab -->
                        <div class="tab-pane fade" id="english" role="tabpanel">
                            <div class="mb-3">
                                <label class="form-label">Discount Title (English)</label>
                                <input type="text" name="title_en" class="form-control"
                                       placeholder="Discount Title (English)"
                                       value="<?= $editCoupon ? htmlspecialchars($editCoupon['title_en'] ?? '') : '' ?>">
                            </div>
                        </div>
                        
                        <!-- Arabic Tab -->
                        <div class="tab-pane fade" id="arabic" role="tabpanel">
                            <div class="mb-3">
                                <label class="form-label">Discount Title (Arabic)</label>
                                <input type="text" name="title_ar" class="form-control"
                                       placeholder="Discount Title (Arabic)"
                                       value="<?= $editCoupon ? htmlspecialchars($editCoupon['title_ar'] ?? '') : '' ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Select coupon type</label>
                        <select name="coupon_type" class="form-select">
                            <option value="discount" <?= ($editCoupon && ($editCoupon['coupon_type'] ?? 'discount') === 'discount') ? 'selected' : '' ?>>Discount</option>
                            <option value="free_shipping" <?= ($editCoupon && ($editCoupon['coupon_type'] ?? 'discount') === 'free_shipping') ? 'selected' : '' ?>>Free Shipping</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Coupon Code</label>
                        <div class="input-group">
                            <input type="text" name="coupon_code" id="coupon_code" class="form-control" 
                                   value="<?= $editCoupon ? htmlspecialchars($editCoupon['coupon_code'] ?? '') : generateCouponCode() ?>" 
                                   <?= $editCoupon ? 'readonly' : '' ?> required>
                            <?php if (!$editCoupon): ?>
                                <button class="btn btn-outline-secondary" type="button" onclick="generateCode()">
                                    <i class="fas fa-sync"></i> Generate
                                </button>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted"><?= $editCoupon ? 'Coupon code cannot be changed' : 'Leave empty to auto-generate' ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Discount type</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="discount_type" id="type_category" value="category"
                                   <?= ($editCoupon && ($editCoupon['discount_type'] ?? 'service') === 'category') ? 'checked' : '' ?>
                                   onchange="toggleCouponFields()">
                            <label class="form-check-label" for="type_category">
                                Category Wise
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="discount_type" id="type_service" value="service"
                                   <?= (!$editCoupon || ($editCoupon['discount_type'] ?? 'service') === 'service') ? 'checked' : '' ?>
                                   onchange="toggleCouponFields()">
                            <label class="form-check-label" for="type_service">
                                Service Wise
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="discount_type" id="type_mixed" value="mixed"
                                   <?= ($editCoupon && ($editCoupon['discount_type'] ?? 'service') === 'mixed') ? 'checked' : '' ?>
                                   onchange="toggleCouponFields()">
                            <label class="form-check-label" for="type_mixed">
                                Mixed
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3" id="category_field" style="display: <?= ($editCoupon && in_array($editCoupon['discount_type'] ?? 'service', ['category', 'mixed'])) ? 'block' : 'none' ?>;">
                        <label class="form-label">Select Category</label>
                        <select name="category_id" id="category_id" class="form-select">
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['id'] ?>" 
                                        <?= ($editCoupon && isset($editCoupon['category_id']) && $editCoupon['category_id'] == $category['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3" id="service_field" style="display: <?= ($editCoupon && in_array($editCoupon['discount_type'] ?? 'service', ['service', 'mixed'])) ? 'block' : 'none' ?>;">
                        <label class="form-label">Select Service</label>
                        <select name="service_id" id="service_id" class="form-select">
                            <option value="">-- Select Service --</option>
                            <?php foreach ($services as $service): ?>
                                <option value="<?= $service['id'] ?>" 
                                        <?= ($editCoupon && isset($editCoupon['service_id']) && $editCoupon['service_id'] == $service['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($service['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Select Zone</label>
                        <select name="zone_id" class="form-select">
                            <option value="">-- All Zones --</option>
                            <!-- Zones can be added later if needed -->
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Discount amount type *</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="amount_type" id="amount_percentage" value="percentage"
                                   <?= (!$editCoupon || ($editCoupon['amount_type'] ?? 'percentage') === 'percentage') ? 'checked' : '' ?>
                                   onchange="toggleAmountField()">
                            <label class="form-check-label" for="amount_percentage">
                                Percentage
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="amount_type" id="amount_fixed" value="fixed"
                                   <?= ($editCoupon && ($editCoupon['amount_type'] ?? 'percentage') === 'fixed') ? 'checked' : '' ?>
                                   onchange="toggleAmountField()">
                            <label class="form-check-label" for="amount_fixed">
                                Fixed Amount
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label" id="amount_label">Amount (%) *</label>
                        <input type="number" name="amount" class="form-control" required step="0.01" min="0"
                               value="<?= $editCoupon ? $editCoupon['amount'] : '0' ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Min Purchase ($.) *</label>
                        <input type="number" name="min_purchase_amount" class="form-control" required step="0.01" min="0"
                               value="<?= $editCoupon ? $editCoupon['min_purchase_amount'] : '0' ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Max Discount ($.) *</label>
                        <input type="number" name="max_discount_amount" class="form-control" step="0.01" min="0"
                               placeholder="Leave empty for no limit"
                               value="<?= $editCoupon ? $editCoupon['max_discount_amount'] : '' ?>">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date *</label>
                            <input type="date" name="start_date" class="form-control" required
                                   value="<?= $editCoupon && $editCoupon['start_date'] ? date('Y-m-d', strtotime($editCoupon['start_date'])) : date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Date *</label>
                            <input type="date" name="end_date" class="form-control" required
                                   value="<?= $editCoupon && $editCoupon['end_date'] ? date('Y-m-d', strtotime($editCoupon['end_date'])) : date('Y-m-d', strtotime('+2 days')) ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Limit For Same User</label>
                        <input type="number" name="limit_per_user" class="form-control" min="1"
                               placeholder="Leave empty for unlimited"
                               value="<?= $editCoupon ? $editCoupon['limit_per_user'] : '' ?>">
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-gradient">
                            <i class="fas fa-save"></i> <?= $editCoupon ? 'Update' : 'Submit' ?> Coupon
                        </button>
                        <?php if ($editCoupon): ?>
                            <a href="?page=coupons" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        <?php endif; ?>
                        <button type="reset" class="btn btn-outline-secondary">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Coupons List -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Coupons</h5>
            </div>
            <div class="card-body">
                <?php if (empty($coupons)): ?>
                    <p class="text-muted">No coupons yet. Create your first one!</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Title</th>
                                    <th>Type</th>
                                    <th>Discount</th>
                                    <th>Valid Period</th>
                                    <th>Usage</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($coupons as $coupon): ?>
                                    <tr>
                                        <td>
                                            <strong class="text-primary"><?= htmlspecialchars($coupon['coupon_code']) ?></strong>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($coupon['title']) ?></strong><br>
                                            <small class="text-muted">
                                                <?php if ($coupon['category_name']): ?>
                                                    Category: <?= htmlspecialchars($coupon['category_name']) ?><br>
                                                <?php endif; ?>
                                                <?php if ($coupon['service_name']): ?>
                                                    Service: <?= htmlspecialchars($coupon['service_name']) ?>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?= ucfirst($coupon['discount_type']) ?></span><br>
                                            <span class="badge bg-secondary"><?= ucfirst($coupon['amount_type']) ?></span>
                                        </td>
                                        <td>
                                            <?php if ($coupon['amount_type'] === 'percentage'): ?>
                                                <?= number_format($coupon['amount'], 2) ?>%
                                            <?php else: ?>
                                                AED <?= number_format($coupon['amount'], 2) ?>
                                            <?php endif; ?>
                                            <?php if ($coupon['min_purchase_amount'] > 0): ?>
                                                <br><small>Min: AED <?= number_format($coupon['min_purchase_amount'], 2) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small>
                                                <?= date('M d, Y', strtotime($coupon['start_date'])) ?><br>
                                                to<br>
                                                <?= date('M d, Y', strtotime($coupon['end_date'])) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary"><?= $coupon['usage_count'] ?> uses</span>
                                            <?php if ($coupon['limit_per_user']): ?>
                                                <br><small>Limit: <?= $coupon['limit_per_user'] ?>/user</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="id" value="<?= $coupon['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-<?= $coupon['is_active'] ? 'success' : 'secondary' ?>">
                                                    <?= $coupon['is_active'] ? 'Active' : 'Inactive' ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td>
                                            <a href="?page=coupons&edit=<?= $coupon['id'] ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this coupon?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $coupon['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
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
</div>

<script>
function generateCode() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    let code = '';
    for (let i = 0; i < 8; i++) {
        code += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('coupon_code').value = code;
}

function toggleCouponFields() {
    const discountType = document.querySelector('input[name="discount_type"]:checked').value;
    const categoryField = document.getElementById('category_field');
    const serviceField = document.getElementById('service_field');
    const categorySelect = document.getElementById('category_id');
    const serviceSelect = document.getElementById('service_id');
    
    if (discountType === 'category') {
        categoryField.style.display = 'block';
        serviceField.style.display = 'none';
        serviceSelect.value = '';
    } else if (discountType === 'service') {
        categoryField.style.display = 'none';
        serviceField.style.display = 'block';
        categorySelect.value = '';
    } else if (discountType === 'mixed') {
        categoryField.style.display = 'block';
        serviceField.style.display = 'block';
    }
}

function toggleAmountField() {
    const amountType = document.querySelector('input[name="amount_type"]:checked').value;
    const amountLabel = document.getElementById('amount_label');
    
    if (amountType === 'percentage') {
        amountLabel.textContent = 'Amount (%) *';
    } else {
        amountLabel.textContent = 'Amount ($.) *';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleCouponFields();
    toggleAmountField();
});
</script>
