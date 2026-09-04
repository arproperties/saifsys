<?php
/**
 * Frequency Discounts Management
 */

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'update') {
            $id = $_POST['id'];
            $discountPercentage = (float)$_POST['discount_percentage'];
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $sortOrder = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
            
            $stmt = $conn->prepare("
                UPDATE frequency_discounts 
                SET discount_percentage = ?, is_active = ?, sort_order = ?
                WHERE id = ?
            ");
            $stmt->execute([$discountPercentage, $isActive, $sortOrder, $id]);
            $success = "Frequency discount updated successfully!";
        } elseif ($action === 'toggle_status') {
            $id = $_POST['id'];
            $stmt = $conn->prepare("UPDATE frequency_discounts SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$id]);
            $success = "Frequency discount status updated!";
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get all frequency discounts
$frequencyDiscounts = $conn->query("
    SELECT * FROM frequency_discounts 
    ORDER BY sort_order ASC, id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Get editing frequency discount if specified
$editFrequency = null;
if (isset($_GET['edit'])) {
    $stmt = $conn->prepare("SELECT * FROM frequency_discounts WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editFrequency = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Helper function to get frequency type label
function getFrequencyTypeLabel($type) {
    $labels = [
        'one_time' => 'One Time Service',
        'weekly' => 'Once a Week',
        'biweekly' => 'Every Two Weeks',
        'multiple' => 'Multiple Times a Week'
    ];
    return $labels[$type] ?? ucfirst($type);
}
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
    <!-- Frequency Discount Form -->
    <div class="col-md-5">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-<?= $editFrequency ? 'edit' : 'info-circle' ?>"></i>
                    <?= $editFrequency ? 'Edit Frequency Discount' : 'Frequency Discounts Info' ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if ($editFrequency): ?>
                    <form method="POST" id="frequencyForm">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= $editFrequency['id'] ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">Frequency Type</label>
                            <input type="text" class="form-control" readonly
                                   value="<?= getFrequencyTypeLabel($editFrequency['frequency_type']) ?>">
                            <small class="text-muted">Frequency type cannot be changed</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Frequency Name</label>
                            <input type="text" name="frequency_name" class="form-control" readonly
                                   value="<?= htmlspecialchars($editFrequency['frequency_name']) ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Discount Percentage (%) *</label>
                            <input type="number" name="discount_percentage" class="form-control" required 
                                   step="0.01" min="0" max="100"
                                   value="<?= $editFrequency['discount_percentage'] ?>">
                            <small class="text-muted">Enter discount percentage (0-100)</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Sort Order</label>
                            <input type="number" name="sort_order" class="form-control" 
                                   min="0"
                                   value="<?= $editFrequency['sort_order'] ?>">
                            <small class="text-muted">Lower numbers appear first</small>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                                       <?= $editFrequency['is_active'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_active">
                                    Active
                                </label>
                            </div>
                            <small class="text-muted">Enable or disable this frequency discount</small>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-gradient">
                                <i class="fas fa-save"></i> Update Frequency Discount
                            </button>
                            <a href="?page=frequency" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <strong>How it works:</strong><br>
                        Frequency discounts are applied automatically when customers select recurring booking frequencies.
                        <ul class="mt-2 mb-0">
                            <li><strong>One Time:</strong> No discount</li>
                            <li><strong>Every Two Weeks:</strong> Typically 5% discount</li>
                            <li><strong>Once a Week:</strong> Typically 10% discount</li>
                            <li><strong>Multiple Times a Week:</strong> Typically 25% discount</li>
                        </ul>
                        <p class="mt-2 mb-0">
                            Click "Edit" on any frequency type to customize the discount percentage.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Frequency Discounts List -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Frequency Discounts</h5>
            </div>
            <div class="card-body">
                <?php if (empty($frequencyDiscounts)): ?>
                    <p class="text-muted">No frequency discounts configured. Please run the database migration.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Frequency Type</th>
                                    <th>Name</th>
                                    <th>Discount</th>
                                    <th>Sort Order</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($frequencyDiscounts as $fd): ?>
                                    <tr>
                                        <td>
                                            <strong><?= getFrequencyTypeLabel($fd['frequency_type']) ?></strong><br>
                                            <small class="text-muted"><?= $fd['frequency_type'] ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($fd['frequency_name']) ?></td>
                                        <td>
                                            <span class="badge bg-success fs-6">
                                                <?= number_format($fd['discount_percentage'], 2) ?>%
                                            </span>
                                        </td>
                                        <td><?= $fd['sort_order'] ?></td>
                                        <td>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="id" value="<?= $fd['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-<?= $fd['is_active'] ? 'success' : 'secondary' ?>">
                                                    <?= $fd['is_active'] ? 'Active' : 'Inactive' ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td>
                                            <a href="?page=frequency&edit=<?= $fd['id'] ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="alert alert-light mt-3">
                        <i class="fas fa-lightbulb"></i> 
                        <strong>Note:</strong> These discounts are applied automatically when customers book services with the corresponding frequency. 
                        Make sure services have "Allows Frequency Discounts" enabled to use these discounts.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

