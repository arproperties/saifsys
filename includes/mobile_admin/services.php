<?php
/**
 * Services Management Page
 */

if (!function_exists('handleCatalogImageUpload')) {
    /**
     * Handle catalog image upload and return relative file path.
     *
     * @param string $inputName
     * @param string|null $existingPath
     * @return string|null
     * @throws Exception
     */
    function handleCatalogImageUpload(string $inputName, ?string $existingPath = null): ?string
    {
        if (
            !isset($_FILES[$inputName]) ||
            !is_array($_FILES[$inputName]) ||
            $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE
        ) {
            return $existingPath;
        }

        $file = $_FILES[$inputName];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Failed to upload image. Please try again.');
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $maxFileSize = 5 * 1024 * 1024; // 5MB

        if ($file['size'] > $maxFileSize) {
            throw new Exception('Image is too large. Maximum size is 5MB.');
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions, true)) {
            throw new Exception('Invalid image format. Allowed types: jpg, jpeg, png, gif, webp.');
        }

        $rootPath = dirname(__DIR__, 2);
        $uploadDirRelative = 'uploads/service_catalog/';
        $uploadDir = $rootPath . '/' . $uploadDirRelative;

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new Exception('Unable to create image upload directory.');
        }

        $filename = uniqid('catalog_', true) . '.' . $extension;
        $destination = $uploadDir . $filename;

        if (!is_uploaded_file($file['tmp_name']) || !move_uploaded_file($file['tmp_name'], $destination)) {
            throw new Exception('Unable to save uploaded image.');
        }

        $relativePath = $uploadDirRelative . $filename;

        if ($existingPath && strpos($existingPath, $uploadDirRelative) === 0) {
            $existingFullPath = $rootPath . '/' . $existingPath;
            if (is_file($existingFullPath)) {
                @unlink($existingFullPath);
            }
        }

        return $relativePath;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'create' || $action === 'update') {
            $id = $_POST['id'] ?? null;
            $category_id = $_POST['category_id'];
            $name = $_POST['name'];
            $description = $_POST['description'] ?? null;
            $price = $_POST['price'];
            $base_price = $_POST['base_price'];
            $price_per_unit = !empty($_POST['price_per_unit']) ? $_POST['price_per_unit'] : null;
            $pricing_rule_id = $_POST['pricing_rule_id'];
            $duration_minutes = $_POST['duration_minutes'];
            $min_hours = !empty($_POST['min_hours']) ? $_POST['min_hours'] : null;
            $max_hours = !empty($_POST['max_hours']) ? $_POST['max_hours'] : null;
            $min_professionals = $_POST['min_professionals'] ?? 1;
            $max_professionals = $_POST['max_professionals'] ?? 4;
            $requires_materials = isset($_POST['requires_materials']) ? 1 : 0;
            $materials_cost = !empty($_POST['materials_cost']) ? $_POST['materials_cost'] : 20.00;
            $allows_frequency = isset($_POST['allows_frequency']) ? 1 : 0;
            $sort_order = $_POST['sort_order'] ?? 0;
            
            // Handle service image upload
            $serviceImageExisting = $_POST['service_image_existing'] ?? null;
            $serviceImage = handleCatalogImageUpload('service_image_file', $serviceImageExisting);
            if ($serviceImage === '') {
                $serviceImage = null;
            }
            
            if ($action === 'create') {
                $stmt = $conn->prepare("
                    INSERT INTO services 
                    (category_id, name, description, image_url, price, base_price, price_per_unit, pricing_rule_id,
                     duration_minutes, min_hours, max_hours, min_professionals, max_professionals,
                     requires_materials, materials_cost, allows_frequency, sort_order, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([$category_id, $name, $description, $serviceImage, $price, $base_price, $price_per_unit,
                               $pricing_rule_id, $duration_minutes, $min_hours, $max_hours, 
                               $min_professionals, $max_professionals, $requires_materials, 
                               $materials_cost, $allows_frequency, $sort_order]);
                $success = "Service created successfully!";
            } else {
                $stmt = $conn->prepare("
                    UPDATE services 
                    SET category_id = ?, name = ?, description = ?, image_url = ?, price = ?, base_price = ?, 
                        price_per_unit = ?, pricing_rule_id = ?, duration_minutes = ?, 
                        min_hours = ?, max_hours = ?, min_professionals = ?, max_professionals = ?,
                        requires_materials = ?, materials_cost = ?, allows_frequency = ?, sort_order = ?
                    WHERE id = ?
                ");
                $stmt->execute([$category_id, $name, $description, $serviceImage, $price, $base_price, $price_per_unit,
                               $pricing_rule_id, $duration_minutes, $min_hours, $max_hours,
                               $min_professionals, $max_professionals, $requires_materials,
                               $materials_cost, $allows_frequency, $sort_order, $id]);
                $success = "Service updated successfully!";
            }
        } elseif ($action === 'delete') {
            $id = $_POST['id'];
            $stmt = $conn->prepare("UPDATE services SET is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            $success = "Service deleted successfully!";
        } elseif ($action === 'toggle_status') {
            $id = $_POST['id'];
            $stmt = $conn->prepare("UPDATE services SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$id]);
            $success = "Service status updated!";
        } elseif (in_array($action, ['create_group', 'update_group'], true)) {
            $groupId = $_POST['group_id'] ?? null;
            $groupServiceId = $_POST['group_service_id'];
            $groupName = trim($_POST['group_name']);
            $groupDescription = $_POST['group_description'] ?? null;
            $groupImageExisting = $_POST['group_image_existing'] ?? null;
            $groupImage = handleCatalogImageUpload('group_image_file', $groupImageExisting);
            if ($groupImage === '') {
                $groupImage = null;
            }
            $groupSortOrder = $_POST['group_sort_order'] ?? 0;
            $groupIsActive = isset($_POST['group_is_active']) ? 1 : 0;

            if (empty($groupServiceId) || empty($groupName)) {
                throw new Exception('Service and group name are required.');
            }

            if ($action === 'create_group') {
                $stmt = $conn->prepare("
                    INSERT INTO service_item_groups 
                        (service_id, name, description, image_url, sort_order, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $groupServiceId,
                    $groupName,
                    $groupDescription,
                    $groupImage,
                    $groupSortOrder,
                    $groupIsActive,
                ]);
                $success = "Item group created successfully!";
            } else {
                $stmt = $conn->prepare("
                    UPDATE service_item_groups 
                    SET service_id = ?, name = ?, description = ?, image_url = ?, sort_order = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $groupServiceId,
                    $groupName,
                    $groupDescription,
                    $groupImage,
                    $groupSortOrder,
                    $groupIsActive,
                    $groupId,
                ]);
                $success = "Item group updated successfully!";
            }
        } elseif ($action === 'delete_group') {
            $groupId = $_POST['group_id'];
            $stmt = $conn->prepare("DELETE FROM service_item_groups WHERE id = ?");
            $stmt->execute([$groupId]);
            $success = "Item group deleted successfully!";
        } elseif (in_array($action, ['create_item', 'update_item'], true)) {
            $itemId = $_POST['item_id'] ?? null;
            $itemServiceId = $_POST['item_service_id'];
            $itemGroupId = $_POST['item_group_id'] ?? null;
            $itemName = trim($_POST['item_name']);
            $itemDescription = $_POST['item_description'] ?? null;
            $itemPrice = $_POST['item_price'];
            $itemOriginalPrice = $_POST['item_original_price'] ?? null;
            $itemDuration = $_POST['item_duration_minutes'] ?? null;
            $itemMinQty = $_POST['item_min_quantity'] ?? 0;
            $itemMaxQty = $_POST['item_max_quantity'] ?? 10;
            $itemDefaultQty = $_POST['item_default_quantity'] ?? 0;
            $itemBadge = $_POST['item_badge_text'] ?? null;
            $itemImageExisting = $_POST['item_image_existing'] ?? null;
            $itemImage = handleCatalogImageUpload('item_image_file', $itemImageExisting);
            if ($itemImage === '') {
                $itemImage = null;
            }
            $itemSortOrder = $_POST['item_sort_order'] ?? 0;
            $itemIsActive = isset($_POST['item_is_active']) ? 1 : 0;

            if (empty($itemServiceId) || empty($itemName) || $itemPrice === '') {
                throw new Exception('Service, item name, and price are required.');
            }

            if ($itemGroupId === '' || $itemGroupId === '0') {
                $itemGroupId = null;
            }

            if ($action === 'create_item') {
                $stmt = $conn->prepare("
                    INSERT INTO service_items (
                        service_id, group_id, name, description, price, original_price, duration_minutes,
                        badge_text, image_url, min_quantity, max_quantity, default_quantity, sort_order, is_active
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $itemServiceId,
                    $itemGroupId,
                    $itemName,
                    $itemDescription,
                    $itemPrice,
                    $itemOriginalPrice !== '' ? $itemOriginalPrice : null,
                    $itemDuration !== '' ? $itemDuration : null,
                    $itemBadge,
                    $itemImage,
                    $itemMinQty,
                    $itemMaxQty,
                    $itemDefaultQty,
                    $itemSortOrder,
                    $itemIsActive,
                ]);
                $success = "Service item created successfully!";
            } else {
                $stmt = $conn->prepare("
                    UPDATE service_items
                    SET service_id = ?, group_id = ?, name = ?, description = ?, price = ?, original_price = ?, 
                        duration_minutes = ?, badge_text = ?, image_url = ?, min_quantity = ?, max_quantity = ?,
                        default_quantity = ?, sort_order = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $itemServiceId,
                    $itemGroupId,
                    $itemName,
                    $itemDescription,
                    $itemPrice,
                    $itemOriginalPrice !== '' ? $itemOriginalPrice : null,
                    $itemDuration !== '' ? $itemDuration : null,
                    $itemBadge,
                    $itemImage,
                    $itemMinQty,
                    $itemMaxQty,
                    $itemDefaultQty,
                    $itemSortOrder,
                    $itemIsActive,
                    $itemId,
                ]);
                $success = "Service item updated successfully!";
            }
        } elseif ($action === 'delete_item') {
            $itemId = $_POST['item_id'];
            $stmt = $conn->prepare("DELETE FROM service_items WHERE id = ?");
            $stmt->execute([$itemId]);
            $success = "Service item deleted successfully!";
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get all services
$services = $conn->query("
    SELECT 
        s.*,
        c.name as category_name,
        pr.name as pricing_rule_name,
        pr.calculation_type
    FROM services s
    LEFT JOIN service_categories c ON s.category_id = c.id
    LEFT JOIN pricing_rules pr ON s.pricing_rule_id = pr.id
    ORDER BY c.name, s.sort_order, s.name
")->fetchAll(PDO::FETCH_ASSOC);

// Get categories for dropdown
$categories = $conn->query("
    SELECT id, name, parent_id 
    FROM service_categories 
    WHERE is_active = 1 
    ORDER BY parent_id, sort_order, name
")->fetchAll(PDO::FETCH_ASSOC);

// Get pricing rules
$pricingRules = $conn->query("
    SELECT * FROM pricing_rules ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

$serviceOptions = $conn->query("
    SELECT id, name 
    FROM services 
    WHERE is_active = 1 
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// Get editing service if specified
$editService = null;
if (isset($_GET['edit'])) {
    $stmt = $conn->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editService = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Prepare data for item groups management
$groupEdit = null;
if (isset($_GET['group_edit'])) {
    $stmt = $conn->prepare("SELECT * FROM service_item_groups WHERE id = ?");
    $stmt->execute([$_GET['group_edit']]);
    $groupEdit = $stmt->fetch(PDO::FETCH_ASSOC);
}

$groupServiceId = $_GET['group_service_id'] ?? ($groupEdit['service_id'] ?? ($editService['id'] ?? ($serviceOptions[0]['id'] ?? null)));

$serviceGroups = [];
if ($groupServiceId) {
    $stmt = $conn->prepare("
        SELECT * FROM service_item_groups 
        WHERE service_id = ? 
        ORDER BY sort_order, name
    ");
    $stmt->execute([$groupServiceId]);
    $serviceGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Prepare data for service items management
$itemEdit = null;
if (isset($_GET['item_edit'])) {
    $stmt = $conn->prepare("SELECT * FROM service_items WHERE id = ?");
    $stmt->execute([$_GET['item_edit']]);
    $itemEdit = $stmt->fetch(PDO::FETCH_ASSOC);
}

$itemServiceId = $_GET['item_service_id'] ?? ($itemEdit['service_id'] ?? $groupServiceId);

$itemGroupsForService = [];
if ($itemServiceId) {
    $stmt = $conn->prepare("
        SELECT id, name 
        FROM service_item_groups 
        WHERE service_id = ? 
        ORDER BY sort_order, name
    ");
    $stmt->execute([$itemServiceId]);
    $itemGroupsForService = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$itemGroupFilter = $_GET['item_group_filter'] ?? ($itemEdit['group_id'] ?? ($itemGroupsForService[0]['id'] ?? null));

$serviceItems = [];
if ($itemServiceId) {
    $sql = "
        SELECT si.*, sig.name AS group_name 
        FROM service_items si
        LEFT JOIN service_item_groups sig ON si.group_id = sig.id
        WHERE si.service_id = ?
    ";
    $params = [$itemServiceId];
    if ($itemGroupFilter && $itemGroupFilter !== 'all') {
        $sql .= " AND si.group_id = ?";
        $params[] = $itemGroupFilter;
    }
    $sql .= " ORDER BY si.sort_order, si.name";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $serviceItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <!-- Service Form -->
    <div class="col-md-5">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-<?= $editService ? 'edit' : 'plus' ?>"></i>
                    <?= $editService ? 'Edit Service' : 'Add New Service' ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="<?= $editService ? 'update' : 'create' ?>">
                    <?php if ($editService): ?>
                        <input type="hidden" name="id" value="<?= $editService['id'] ?>">
                        <input type="hidden" name="service_image_existing" value="<?= htmlspecialchars($editService['image_url'] ?? '') ?>">
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Category *</label>
                        <select name="category_id" class="form-select" required>
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" 
                                    <?= ($editService && $editService['category_id'] == $cat['id']) ? 'selected' : '' ?>>
                                    <?= $cat['parent_id'] ? '→ ' : '' ?><?= htmlspecialchars($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Service Name *</label>
                        <input type="text" name="name" class="form-control" required
                               value="<?= $editService ? htmlspecialchars($editService['name'] ?? '') : '' ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"><?= $editService ? htmlspecialchars($editService['description'] ?? '') : '' ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Service Image</label>
                        <input type="file" name="service_image_file" class="form-control" accept="image/*">
                        <small class="text-muted">Upload JPG, PNG, GIF, or WebP (max 5MB). Recommended: Square image (300x300px or larger).</small>
                    </div>
                    <?php if ($editService && !empty($editService['image_url'])): ?>
                        <div class="mb-3">
                            <label class="form-label d-block">Current Image</label>
                            <img src="<?= htmlspecialchars($editService['image_url']) ?>" alt="Service image" class="img-thumbnail" style="max-height: 150px; max-width: 150px; object-fit: cover;">
                        </div>
                    <?php endif; ?>
                    
                    <div class="alert alert-info pricing-conditional" data-show-for="all">
                        <strong><i class="fas fa-info-circle"></i> Pricing Guide:</strong>
                        <ul class="mb-0 mt-2" style="font-size: 13px;">
                            <li><strong>Display Price:</strong> The "Starting from" price shown on service cards</li>
                            <li><strong>Base Price:</strong> Fixed cost added to every booking (e.g., travel fee)</li>
                            <li class="pricing-conditional" data-show-for="hourly,per_sqm,per_room,custom,fixed">
                                <strong>Hourly / Unit Rate:</strong> Price per hour (or unit) per worker (for hourly and unit-based services)
                            </li>
                            <li class="pricing-conditional" data-show-for="hourly,per_sqm,per_room,custom,fixed">
                                <strong>Materials Cost:</strong> Fixed cost if customer needs cleaning materials
                            </li>
                            <li class="pricing-conditional" data-show-for="per_item">
                                <strong>Per Item:</strong> Build item groups and items in the catalog section below. Totals are based on selected items.
                            </li>
                        </ul>
                    </div>
                    
                    <div class="row pricing-conditional" data-show-for="all">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                Display Price (AED) * 
                                <i class="fas fa-question-circle text-muted" 
                                   data-bs-toggle="tooltip" 
                                   title="The 'Starting from' price shown on service cards in the app"></i>
                            </label>
                            <input type="number" name="price" class="form-control" step="0.01" required
                                   value="<?= $editService ? $editService['price'] : '' ?>"
                                   placeholder="e.g., 60">
                            <small class="text-muted">Shown as "From AED X"</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                Base Price (AED) * 
                                <i class="fas fa-question-circle text-muted" 
                                   data-bs-toggle="tooltip" 
                                   title="Fixed fee added to every booking (e.g., travel/setup fee)"></i>
                            </label>
                            <input type="number" name="base_price" class="form-control" step="0.01" required
                                   value="<?= $editService ? $editService['base_price'] : '' ?>"
                                   placeholder="e.g., 10">
                            <small class="text-muted">Fixed fee per booking</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Pricing Rule *</label>
                        <select name="pricing_rule_id" id="pricing_rule_id" class="form-select" required>
                            <?php foreach ($pricingRules as $rule): ?>
                                <option value="<?= $rule['id'] ?>" 
                                    data-calculation="<?= htmlspecialchars($rule['calculation_type']) ?>"
                                    <?= ($editService && $editService['pricing_rule_id'] == $rule['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($rule['name']) ?> (<?= $rule['calculation_type'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">
                            How the final price is calculated. Choose <strong>Per Item</strong> to manage prices via service items.
                        </small>
                    </div>
                    
                    <div class="row pricing-conditional" data-show-for="hourly,per_sqm,per_room,custom,fixed">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                Hourly Rate (AED/hr) * 
                                <i class="fas fa-question-circle text-muted" 
                                   data-bs-toggle="tooltip" 
                                   title="Price per hour per worker. Formula: Hours × Workers × Hourly Rate"></i>
                            </label>
                            <input type="number" name="price_per_unit" class="form-control" step="0.01"
                                   value="<?= $editService ? $editService['price_per_unit'] : '' ?>"
                                   placeholder="e.g., 30">
                            <small class="text-muted">Per hour, per worker</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                Materials Cost (AED) 
                                <i class="fas fa-question-circle text-muted" 
                                   data-bs-toggle="tooltip" 
                                   title="Fixed cost if customer selects 'Yes, please' for materials"></i>
                            </label>
                            <input type="number" name="materials_cost" class="form-control" step="0.01"
                                   value="<?= $editService ? ($editService['materials_cost'] ?? '20') : '20' ?>"
                                   placeholder="e.g., 20">
                            <small class="text-muted">Cost for cleaning materials</small>
                        </div>
                    </div>
                    
                    <div class="alert alert-secondary pricing-conditional" data-show-for="hourly,per_sqm,per_room,custom" style="font-size: 13px;">
                        <strong>💡 Pricing Example:</strong> If a customer selects 2 hours, 1 worker, with materials:<br>
                        <code>Base (10) + (2 hrs × 1 worker × 30/hr) + Materials (20) = AED 90</code><br>
                        <small class="text-muted">+ 5% service fee = Final Total</small>
                    </div>
                    <div class="alert alert-secondary pricing-conditional" data-show-for="per_item" style="font-size: 13px;">
                        <strong>💡 Per Item Pricing:</strong> Configure item groups and service items in the catalog section below.
                        The total price will be calculated from the selected items plus service fees.
                    </div>
                    
                    <div class="row pricing-conditional" data-show-for="hourly,per_sqm,per_room,custom,fixed">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">
                                Estimated Duration (min) 
                                <i class="fas fa-question-circle text-muted" 
                                   data-bs-toggle="tooltip" 
                                   title="Average service duration (for display only, not used in pricing)"></i>
                            </label>
                            <input type="number" name="duration_minutes" class="form-control"
                                   value="<?= $editService ? $editService['duration_minutes'] : '60' ?>"
                                   placeholder="60">
                            <small class="text-muted">Display only</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">
                                Min Hours 
                                <i class="fas fa-question-circle text-muted" 
                                   data-bs-toggle="tooltip" 
                                   title="Minimum hours customer can select"></i>
                            </label>
                            <input type="number" name="min_hours" class="form-control" step="0.5"
                                   value="<?= $editService ? $editService['min_hours'] : '2' ?>"
                                   placeholder="2">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">
                                Max Hours 
                                <i class="fas fa-question-circle text-muted" 
                                   data-bs-toggle="tooltip" 
                                   title="Maximum hours customer can select"></i>
                            </label>
                            <input type="number" name="max_hours" class="form-control" step="0.5"
                                   value="<?= $editService ? $editService['max_hours'] : '8' ?>"
                                   placeholder="8">
                        </div>
                    </div>
                    
                    <div class="row pricing-conditional" data-show-for="hourly,per_sqm,per_room,custom,fixed">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Min Professionals</label>
                            <input type="number" name="min_professionals" class="form-control"
                                   value="<?= $editService ? $editService['min_professionals'] : '1' ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Max Professionals</label>
                            <input type="number" name="max_professionals" class="form-control"
                                   value="<?= $editService ? $editService['max_professionals'] : '4' ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control"
                               value="<?= $editService ? $editService['sort_order'] : '0' ?>">
                    </div>
                    
                    <div class="mb-3 form-check pricing-conditional" data-show-for="hourly,per_sqm,per_room,custom,fixed">
                        <input type="checkbox" name="requires_materials" class="form-check-input" id="requires_materials"
                               <?= ($editService && $editService['requires_materials']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="requires_materials">Requires Materials</label>
                    </div>
                    
                    <div class="mb-3 form-check pricing-conditional" data-show-for="hourly,per_sqm,per_room,custom,fixed">
                        <input type="checkbox" name="allows_frequency" class="form-check-input" id="allows_frequency"
                               <?= ($editService && $editService['allows_frequency']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="allows_frequency">Allows Frequency Discounts</label>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-gradient">
                            <i class="fas fa-save"></i> <?= $editService ? 'Update' : 'Create' ?> Service
                        </button>
                        <?php if ($editService): ?>
                            <a href="?page=services" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Services List -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Services List (<?= count($services) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-sm">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($services as $svc): ?>
                                <tr class="<?= !$svc['is_active'] ? 'table-secondary' : '' ?>">
                                    <td>
                                        <strong><?= htmlspecialchars($svc['name'] ?? '') ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars(substr($svc['description'] ?? '', 0, 40)) ?>...</small>
                                    </td>
                                    <td><span class="badge bg-info"><?= htmlspecialchars($svc['category_name'] ?? 'No Category') ?></span></td>
                                    <td>
                                        <strong>AED <?= number_format($svc['price'], 2) ?></strong>
                                        <?php if ($svc['price_per_unit']): ?>
                                            <br><small>+<?= $svc['price_per_unit'] ?>/unit</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-secondary"><?= $svc['calculation_type'] ?></span></td>
                                    <td>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="id" value="<?= $svc['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-<?= $svc['is_active'] ? 'success' : 'secondary' ?>">
                                                <?= $svc['is_active'] ? 'Active' : 'Inactive' ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <a href="?page=services&edit=<?= $svc['id'] ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="POST" style="display:inline;" 
                                              onsubmit="return confirm('Delete this service?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $svc['id'] ?>">
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
            </div>
        </div>
    </div>
</div>

<div class="row mt-5">
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-1"><i class="fas fa-layer-group"></i> Service Catalog (Per Item Pricing)</h5>
                    <small class="text-light">Configure item groups and items used when a service uses the <strong>Per Item</strong> pricing rule.</small>
                </div>
                <span class="badge bg-info text-dark">Catalog Manager</span>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-lg-4">
                        <h6 class="text-uppercase text-muted mb-3">Item Groups</h6>
                        <form class="row g-2 mb-3 align-items-end" method="get">
                            <input type="hidden" name="page" value="services">
                            <div class="col-12">
                                <label class="form-label">Service</label>
                                <select name="group_service_id" class="form-select" onchange="this.form.submit()">
                                    <?php foreach ($serviceOptions as $serviceOption): ?>
                                        <option value="<?= $serviceOption['id'] ?>"
                                            <?= (string)$groupServiceId === (string)$serviceOption['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($serviceOption['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if (isset($_GET['item_service_id'])): ?>
                                <input type="hidden" name="item_service_id" value="<?= htmlspecialchars($_GET['item_service_id']) ?>">
                                <?php if (isset($_GET['item_group_filter'])): ?>
                                    <input type="hidden" name="item_group_filter" value="<?= htmlspecialchars($_GET['item_group_filter']) ?>">
                                <?php endif; ?>
                            <?php endif; ?>
                        </form>

                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-body">
                                <form method="POST" class="row g-3" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="<?= $groupEdit ? 'update_group' : 'create_group' ?>">
                                    <?php if ($groupEdit): ?>
                                        <input type="hidden" name="group_id" value="<?= $groupEdit['id'] ?>">
                                    <?php endif; ?>
                                    <input type="hidden" name="group_image_existing" value="<?= $groupEdit ? htmlspecialchars($groupEdit['image_url']) : '' ?>">
                                    <div class="col-12">
                                        <label class="form-label">Group Name *</label>
                                        <input type="text" class="form-control" name="group_name" required
                                               value="<?= $groupEdit ? htmlspecialchars($groupEdit['name']) : '' ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Service *</label>
                                        <select name="group_service_id" class="form-select" required>
                                            <?php foreach ($serviceOptions as $serviceOption): ?>
                                                <option value="<?= $serviceOption['id'] ?>"
                                                    <?= (string)($groupEdit['service_id'] ?? $groupServiceId) === (string)$serviceOption['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($serviceOption['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Description</label>
                                        <textarea name="group_description" class="form-control" rows="2"><?= $groupEdit ? htmlspecialchars($groupEdit['description']) : '' ?></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Group Image</label>
                                        <input type="file" class="form-control" name="group_image_file" accept="image/*">
                                        <small class="text-muted">Upload JPG, PNG, GIF, or WebP (max 5MB).</small>
                                    </div>
                                    <?php if (!empty($groupEdit['image_url'])): ?>
                                        <div class="col-12">
                                            <label class="form-label d-block">Current Image</label>
                                            <img src="<?= htmlspecialchars($groupEdit['image_url']) ?>" alt="Group image" class="img-thumbnail" style="max-height: 120px;">
                                        </div>
                                    <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Sort Order</label>
                                        <input type="number" class="form-control" name="group_sort_order"
                                               value="<?= $groupEdit ? htmlspecialchars($groupEdit['sort_order']) : 0 ?>">
                                    </div>
                                    <div class="col-md-6 d-flex align-items-center">
                                        <div class="form-check mt-4">
                                            <input class="form-check-input" type="checkbox" name="group_is_active" id="group_is_active"
                                                   <?= !$groupEdit || $groupEdit['is_active'] ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="group_is_active">Active</label>
                                        </div>
                                    </div>
                                    <div class="col-12 d-grid gap-2">
                                        <button class="btn btn-gradient" type="submit">
                                            <i class="fas fa-save"></i> <?= $groupEdit ? 'Update Group' : 'Create Group' ?>
                                        </button>
                                        <?php if ($groupEdit): ?>
                                            <a href="?page=services&group_service_id=<?= $groupServiceId ?>" class="btn btn-outline-secondary">
                                                <i class="fas fa-times"></i> Cancel
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="table-responsive bg-light rounded-3 p-3">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Group</th>
                                        <th>Items</th>
                                        <th>Sort</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($serviceGroups)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-3">
                                                <i class="fas fa-info-circle me-2"></i>No item groups yet.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($serviceGroups as $group): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($group['name']) ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($group['description'] ?? '—') ?></small>
                                            </td>
                                            <td>
                                                <?php
                                                    $countStmt = $conn->prepare("SELECT COUNT(*) FROM service_items WHERE group_id = ?");
                                                    $countStmt->execute([$group['id']]);
                                                    $itemCount = $countStmt->fetchColumn();
                                                ?>
                                                <span class="badge bg-primary"><?= $itemCount ?> item<?= $itemCount == 1 ? '' : 's' ?></span>
                                            </td>
                                            <td><span class="badge bg-light text-dark"><?= (int)$group['sort_order'] ?></span></td>
                                            <td>
                                                <span class="badge bg-<?= $group['is_active'] ? 'success' : 'secondary' ?>">
                                                    <?= $group['is_active'] ? 'Active' : 'Inactive' ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <a href="?page=services&group_service_id=<?= $group['service_id'] ?>&group_edit=<?= $group['id'] ?>" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this group? This will also detach its items.');">
                                                    <input type="hidden" name="action" value="delete_group">
                                                    <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                                                    <button class="btn btn-sm btn-danger">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-lg-8">
                        <h6 class="text-uppercase text-muted mb-3">Service Items</h6>
                        <form class="row g-2 mb-3 align-items-end" method="get">
                            <input type="hidden" name="page" value="services">
                            <div class="col-md-6">
                                <label class="form-label">Service</label>
                                <select name="item_service_id" class="form-select" onchange="this.form.submit()">
                                    <?php foreach ($serviceOptions as $serviceOption): ?>
                                        <option value="<?= $serviceOption['id'] ?>"
                                            <?= (string)$itemServiceId === (string)$serviceOption['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($serviceOption['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Group Filter</label>
                                <select name="item_group_filter" class="form-select" onchange="this.form.submit()">
                                    <option value="all">All Groups</option>
                                    <?php foreach ($itemGroupsForService as $group): ?>
                                        <option value="<?= $group['id'] ?>"
                                            <?= (string)$itemGroupFilter === (string)$group['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($group['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if (isset($_GET['group_service_id'])): ?>
                                <input type="hidden" name="group_service_id" value="<?= htmlspecialchars($_GET['group_service_id']) ?>">
                            <?php endif; ?>
                        </form>

                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-body">
                                <form method="POST" class="row g-3" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="<?= $itemEdit ? 'update_item' : 'create_item' ?>">
                                    <?php if ($itemEdit): ?>
                                        <input type="hidden" name="item_id" value="<?= $itemEdit['id'] ?>">
                                    <?php endif; ?>
                                    <input type="hidden" name="item_image_existing" value="<?= $itemEdit ? htmlspecialchars($itemEdit['image_url']) : '' ?>">
                                    <div class="col-12">
                                        <label class="form-label">Item Name *</label>
                                        <input type="text" class="form-control" name="item_name" required
                                               value="<?= $itemEdit ? htmlspecialchars($itemEdit['name']) : '' ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Service *</label>
                                        <select name="item_service_id" class="form-select" required>
                                            <?php foreach ($serviceOptions as $serviceOption): ?>
                                                <option value="<?= $serviceOption['id'] ?>"
                                                    <?= (string)($itemEdit['service_id'] ?? $itemServiceId) === (string)$serviceOption['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($serviceOption['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Group</label>
                                        <select name="item_group_id" class="form-select">
                                            <option value="">-- Optional --</option>
                                            <?php
                                                $groupsForDropdown = $itemGroupsForService;
                                                if ($itemEdit && $itemEdit['service_id'] != $itemServiceId) {
                                                    $stmt = $conn->prepare("SELECT id, name FROM service_item_groups WHERE service_id = ? ORDER BY sort_order, name");
                                                    $stmt->execute([$itemEdit['service_id']]);
                                                    $groupsForDropdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                                }
                                            ?>
                                            <?php foreach ($groupsForDropdown as $group): ?>
                                                <option value="<?= $group['id'] ?>"
                                                    <?= ($itemEdit && (string)$itemEdit['group_id'] === (string)$group['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($group['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Description</label>
                                        <textarea name="item_description" class="form-control" rows="2"><?= $itemEdit ? htmlspecialchars($itemEdit['description']) : '' ?></textarea>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Price (AED) *</label>
                                        <input type="number" class="form-control" step="0.01" name="item_price" required
                                               value="<?= $itemEdit ? htmlspecialchars($itemEdit['price']) : '' ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Original Price (AED)</label>
                                        <input type="number" class="form-control" step="0.01" name="item_original_price"
                                               value="<?= $itemEdit ? htmlspecialchars($itemEdit['original_price']) : '' ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Duration (min)</label>
                                        <input type="number" class="form-control" name="item_duration_minutes"
                                               value="<?= $itemEdit ? htmlspecialchars($itemEdit['duration_minutes']) : '' ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Min Qty</label>
                                        <input type="number" class="form-control" name="item_min_quantity"
                                               value="<?= $itemEdit ? htmlspecialchars($itemEdit['min_quantity']) : 0 ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Max Qty</label>
                                        <input type="number" class="form-control" name="item_max_quantity"
                                               value="<?= $itemEdit ? htmlspecialchars($itemEdit['max_quantity']) : 10 ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Default Qty</label>
                                        <input type="number" class="form-control" name="item_default_quantity"
                                               value="<?= $itemEdit ? htmlspecialchars($itemEdit['default_quantity']) : 0 ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Badge Text</label>
                                        <input type="text" class="form-control" name="item_badge_text"
                                               value="<?= $itemEdit ? htmlspecialchars($itemEdit['badge_text']) : '' ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Item Image</label>
                                        <input type="file" class="form-control" name="item_image_file" accept="image/*">
                                        <small class="text-muted">Upload JPG, PNG, GIF, or WebP (max 5MB).</small>
                                    </div>
                                    <?php if (!empty($itemEdit['image_url'])): ?>
                                        <div class="col-12">
                                            <label class="form-label d-block">Current Image</label>
                                            <img src="<?= htmlspecialchars($itemEdit['image_url']) ?>" alt="Item image" class="img-thumbnail" style="max-height: 120px;">
                                        </div>
                                    <?php endif; ?>
                                    <div class="col-md-6">
                                        <label class="form-label">Sort Order</label>
                                        <input type="number" class="form-control" name="item_sort_order"
                                               value="<?= $itemEdit ? htmlspecialchars($itemEdit['sort_order']) : 0 ?>">
                                    </div>
                                    <div class="col-md-6 d-flex align-items-center">
                                        <div class="form-check mt-4">
                                            <input class="form-check-input" type="checkbox" name="item_is_active" id="item_is_active"
                                                   <?= !$itemEdit || $itemEdit['is_active'] ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="item_is_active">Active</label>
                                        </div>
                                    </div>
                                    <div class="col-12 d-grid gap-2">
                                        <button class="btn btn-gradient" type="submit">
                                            <i class="fas fa-save"></i> <?= $itemEdit ? 'Update Item' : 'Create Item' ?>
                                        </button>
                                        <?php if ($itemEdit): ?>
                                            <a href="?page=services&item_service_id=<?= $itemServiceId ?>&group_service_id=<?= $groupServiceId ?>&item_group_filter=<?= htmlspecialchars($itemGroupFilter) ?>" class="btn btn-outline-secondary">
                                                <i class="fas fa-times"></i> Cancel
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="table-responsive bg-light rounded-3 p-3">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Group</th>
                                        <th>Pricing</th>
                                        <th>Quantity</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($serviceItems)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-3">
                                                <i class="fas fa-info-circle me-2"></i>No service items found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($serviceItems as $item): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($item['name']) ?></strong>
                                                <?php if (!empty($item['badge_text'])): ?>
                                                    <span class="badge bg-warning text-dark ms-1"><?= htmlspecialchars($item['badge_text']) ?></span>
                                                <?php endif; ?>
                                                <br>
                                                <small class="text-muted"><?= htmlspecialchars($item['description'] ?? '—') ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($item['group_name'] ?? '—') ?></td>
                                            <td>
                                                <strong>AED <?= number_format($item['price'], 2) ?></strong>
                                                <?php if (!empty($item['original_price'])): ?>
                                                    <br><small class="text-decoration-line-through text-muted">AED <?= number_format($item['original_price'], 2) ?></small>
                                                <?php endif; ?>
                                                <?php if (!empty($item['duration_minutes'])): ?>
                                                    <br><small class="text-muted"><?= (int)$item['duration_minutes'] ?> min</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    Min <?= (int)$item['min_quantity'] ?> /
                                                    Max <?= (int)$item['max_quantity'] ?><br>
                                                    Default <?= (int)$item['default_quantity'] ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $item['is_active'] ? 'success' : 'secondary' ?>">
                                                    <?= $item['is_active'] ? 'Active' : 'Inactive' ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <a href="?page=services&item_service_id=<?= $item['service_id'] ?>&item_edit=<?= $item['id'] ?>&group_service_id=<?= $groupServiceId ?>&item_group_filter=<?= htmlspecialchars($itemGroupFilter) ?>" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this item?');">
                                                    <input type="hidden" name="action" value="delete_item">
                                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                                    <button class="btn btn-sm btn-danger">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function() {
        const pricingRuleSelect = document.getElementById('pricing_rule_id');

        function togglePricingFields() {
            const calculationType = pricingRuleSelect?.selectedOptions?.[0]?.dataset.calculation || 'hourly';
            document.querySelectorAll('.pricing-conditional').forEach(function (block) {
                const showFor = (block.dataset.showFor || 'all')
                    .split(',')
                    .map(function(value) { return value.trim(); });
                const shouldShow = showFor.includes('all') || showFor.includes(calculationType);
                block.style.display = shouldShow ? '' : 'none';
                block.classList.toggle('d-none', !shouldShow);
            });
        }

        if (pricingRuleSelect) {
            pricingRuleSelect.addEventListener('change', togglePricingFields);
            togglePricingFields();
        }
    })();
</script>

