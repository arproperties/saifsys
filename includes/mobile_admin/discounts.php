<?php
/**
 * Discounts Management
 */

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'create' || $action === 'update') {
            $id = $_POST['id'] ?? null;
            $title = $_POST['title'];
            $discount_type = $_POST['discount_type'];
            $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            $service_id = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
            $zone_id = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;
            $amount_type = $_POST['amount_type'];
            $amount = (float)$_POST['amount'];
            $min_purchase_amount = (float)($_POST['min_purchase_amount'] ?? 0);
            $max_discount_amount = !empty($_POST['max_discount_amount']) ? (float)$_POST['max_discount_amount'] : null;
            $start_date = $_POST['start_date'] . ' 00:00:00';
            $end_date = $_POST['end_date'] . ' 23:59:59';

            // Validate discount type requirements
            if ($discount_type === 'category' && !$category_id) {
                throw new Exception('Category is required for Category Wise discount.');
            }
            if ($discount_type === 'service' && !$service_id) {
                throw new Exception('Service is required for Service Wise discount.');
            }
            if ($discount_type === 'mixed' && !$category_id && !$service_id) {
                throw new Exception('At least one category or service is required for Mixed discount.');
            }

            if ($action === 'create') {
                $stmt = $conn->prepare("
                    INSERT INTO discounts 
                    (title, discount_type, category_id, service_id, zone_id, amount_type, amount, 
                     min_purchase_amount, max_discount_amount, start_date, end_date, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([
                    $title, $discount_type, $category_id, $service_id, $zone_id, 
                    $amount_type, $amount, $min_purchase_amount, $max_discount_amount, 
                    $start_date, $end_date
                ]);
                $success = "Discount created successfully!";
            } else {
                $stmt = $conn->prepare("
                    UPDATE discounts 
                    SET title = ?, discount_type = ?, category_id = ?, service_id = ?, zone_id = ?, 
                        amount_type = ?, amount = ?, min_purchase_amount = ?, max_discount_amount = ?, 
                        start_date = ?, end_date = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $title, $discount_type, $category_id, $service_id, $zone_id, 
                    $amount_type, $amount, $min_purchase_amount, $max_discount_amount, 
                    $start_date, $end_date, $id
                ]);
                $success = "Discount updated successfully!";
            }
        } elseif ($action === 'delete') {
            $id = $_POST['id'];
            $stmt = $conn->prepare("DELETE FROM discounts WHERE id = ?");
            $stmt->execute([$id]);
            $success = "Discount deleted successfully!";
        } elseif ($action === 'toggle_status') {
            $id = $_POST['id'];
            $stmt = $conn->prepare("UPDATE discounts SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$id]);
            $success = "Discount status updated!";
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get all discounts
$discounts = $conn->query("
    SELECT d.*, 
           c.name as category_name,
           s.name as service_name
    FROM discounts d
    LEFT JOIN service_categories c ON d.category_id = c.id
    LEFT JOIN services s ON d.service_id = s.id
    ORDER BY d.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get editing discount if specified
$editDiscount = null;
if (isset($_GET['edit'])) {
    $stmt = $conn->prepare("SELECT * FROM discounts WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editDiscount = $stmt->fetch(PDO::FETCH_ASSOC);
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
    <!-- Discount Form -->
    <div class="col-md-5">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-<?= $editDiscount ? 'edit' : 'plus' ?>"></i>
                    <?= $editDiscount ? 'Edit Discount' : 'Add New Discount' ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" id="discountForm">
                    <input type="hidden" name="action" value="<?= $editDiscount ? 'update' : 'create' ?>">
                    <?php if ($editDiscount): ?>
                        <input type="hidden" name="id" value="<?= $editDiscount['id'] ?>">
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Discount Title *</label>
                        <input type="text" name="title" class="form-control" required
                               placeholder="Discount Title *"
                               value="<?= $editDiscount ? htmlspecialchars($editDiscount['title'] ?? '') : '' ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Discount type</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="discount_type" id="type_category" value="category"
                                   <?= ($editDiscount && ($editDiscount['discount_type'] ?? 'service') === 'category') ? 'checked' : '' ?>
                                   onchange="toggleDiscountFields()">
                            <label class="form-check-label" for="type_category">
                                Category Wise
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="discount_type" id="type_service" value="service"
                                   <?= (!$editDiscount || ($editDiscount['discount_type'] ?? 'service') === 'service') ? 'checked' : '' ?>
                                   onchange="toggleDiscountFields()">
                            <label class="form-check-label" for="type_service">
                                Service Wise
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="discount_type" id="type_mixed" value="mixed"
                                   <?= ($editDiscount && ($editDiscount['discount_type'] ?? 'service') === 'mixed') ? 'checked' : '' ?>
                                   onchange="toggleDiscountFields()">
                            <label class="form-check-label" for="type_mixed">
                                Mixed
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3" id="category_field" style="display: <?= ($editDiscount && in_array($editDiscount['discount_type'] ?? 'service', ['category', 'mixed'])) ? 'block' : 'none' ?>;">
                        <label class="form-label">Select Category</label>
                        <select name="category_id" id="category_id" class="form-select">
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['id'] ?>" 
                                        <?= ($editDiscount && isset($editDiscount['category_id']) && $editDiscount['category_id'] == $category['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3" id="service_field" style="display: <?= ($editDiscount && in_array($editDiscount['discount_type'] ?? 'service', ['service', 'mixed'])) ? 'block' : 'none' ?>;">
                        <label class="form-label">Select Service</label>
                        <select name="service_id" id="service_id" class="form-select">
                            <option value="">-- Select Service --</option>
                            <?php foreach ($services as $service): ?>
                                <option value="<?= $service['id'] ?>" 
                                        <?= ($editDiscount && isset($editDiscount['service_id']) && $editDiscount['service_id'] == $service['id']) ? 'selected' : '' ?>>
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
                                   <?= (!$editDiscount || ($editDiscount['amount_type'] ?? 'percentage') === 'percentage') ? 'checked' : '' ?>
                                   onchange="toggleAmountField()">
                            <label class="form-check-label" for="amount_percentage">
                                Percentage
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="amount_type" id="amount_fixed" value="fixed"
                                   <?= ($editDiscount && ($editDiscount['amount_type'] ?? 'percentage') === 'fixed') ? 'checked' : '' ?>
                                   onchange="toggleAmountField()">
                            <label class="form-check-label" for="amount_fixed">
                                Fixed Amount
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label" id="amount_label">Amount (%) *</label>
                        <input type="number" name="amount" class="form-control" required step="0.01" min="0"
                               value="<?= $editDiscount ? $editDiscount['amount'] : '0' ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Min Purchase Amount (AED) *</label>
                        <input type="number" name="min_purchase_amount" class="form-control" required step="0.01" min="0"
                               value="<?= $editDiscount ? $editDiscount['min_purchase_amount'] : '0' ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Max Discount (AED) *</label>
                        <input type="number" name="max_discount_amount" class="form-control" step="0.01" min="0"
                               placeholder="Leave empty for no limit"
                               value="<?= $editDiscount ? $editDiscount['max_discount_amount'] : '' ?>">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date *</label>
                            <input type="date" name="start_date" class="form-control" required
                                   value="<?= $editDiscount && $editDiscount['start_date'] ? date('Y-m-d', strtotime($editDiscount['start_date'])) : date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Date *</label>
                            <input type="date" name="end_date" class="form-control" required
                                   value="<?= $editDiscount && $editDiscount['end_date'] ? date('Y-m-d', strtotime($editDiscount['end_date'])) : date('Y-m-d', strtotime('+2 days')) ?>">
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-gradient">
                            <i class="fas fa-save"></i> <?= $editDiscount ? 'Update' : 'Submit' ?> Discount
                        </button>
                        <?php if ($editDiscount): ?>
                            <a href="?page=discounts" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Discounts List -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Discounts</h5>
            </div>
            <div class="card-body">
                <?php if (empty($discounts)): ?>
                    <p class="text-muted">No discounts yet. Create your first one!</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Type</th>
                                    <th>Discount</th>
                                    <th>Valid Period</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($discounts as $discount): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($discount['title']) ?></strong><br>
                                            <small class="text-muted">
                                                <?php if ($discount['category_name']): ?>
                                                    Category: <?= htmlspecialchars($discount['category_name']) ?><br>
                                                <?php endif; ?>
                                                <?php if ($discount['service_name']): ?>
                                                    Service: <?= htmlspecialchars($discount['service_name']) ?>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?= ucfirst($discount['discount_type']) ?></span><br>
                                            <span class="badge bg-secondary"><?= ucfirst($discount['amount_type']) ?></span>
                                        </td>
                                        <td>
                                            <?php if ($discount['amount_type'] === 'percentage'): ?>
                                                <?= number_format($discount['amount'], 2) ?>%
                                            <?php else: ?>
                                                AED <?= number_format($discount['amount'], 2) ?>
                                            <?php endif; ?>
                                            <?php if ($discount['min_purchase_amount'] > 0): ?>
                                                <br><small>Min: AED <?= number_format($discount['min_purchase_amount'], 2) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small>
                                                <?= date('M d, Y', strtotime($discount['start_date'])) ?><br>
                                                to<br>
                                                <?= date('M d, Y', strtotime($discount['end_date'])) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="id" value="<?= $discount['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-<?= $discount['is_active'] ? 'success' : 'secondary' ?>">
                                                    <?= $discount['is_active'] ? 'Active' : 'Inactive' ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td>
                                            <a href="?page=discounts&edit=<?= $discount['id'] ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this discount?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $discount['id'] ?>">
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
function toggleDiscountFields() {
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
        amountLabel.textContent = 'Amount (AED) *';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleDiscountFields();
    toggleAmountField();
});
</script>
