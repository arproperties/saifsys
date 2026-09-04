<?php
/**
 * Categories Management Page
 */

if (!function_exists('handleCategoryImageUpload')) {
    /**
     * Handle category image upload and return relative file path.
     *
     * @param string $inputName
     * @param string|null $existingPath
     * @return string|null
     * @throws Exception
     */
    function handleCategoryImageUpload(string $inputName, ?string $existingPath = null): ?string
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
        $uploadDirRelative = 'uploads/categories/';
        $uploadDir = $rootPath . '/' . $uploadDirRelative;

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new Exception('Unable to create image upload directory.');
        }

        $filename = uniqid('category_', true) . '.' . $extension;
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
            $parent_id = !empty($_POST['parent_id']) ? $_POST['parent_id'] : null;
            $name = $_POST['name'];
            $name_ar = $_POST['name_ar'] ?? null;
            $description = $_POST['description'] ?? null;
            $icon_url = $_POST['icon_url'] ?? null;
            
            // Handle image upload
            $imageExisting = $_POST['image_existing'] ?? null;
            $image_url = handleCategoryImageUpload('image_file', $imageExisting);
            if ($image_url === '') {
                $image_url = null;
            }
            
            $sort_order = $_POST['sort_order'] ?? 0;
            $show_on_home = isset($_POST['show_on_home']) ? 1 : 0;
            
            if ($action === 'create') {
                $stmt = $conn->prepare("
                    INSERT INTO service_categories 
                    (parent_id, name, name_ar, description, icon_url, image_url, sort_order, is_active, show_on_home)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)
                ");
                $stmt->execute([$parent_id, $name, $name_ar, $description, $icon_url, $image_url, $sort_order, $show_on_home]);
                $success = "Category created successfully!";
            } else {
                $stmt = $conn->prepare("
                    UPDATE service_categories 
                    SET parent_id = ?, name = ?, name_ar = ?, description = ?, 
                        icon_url = ?, image_url = ?, sort_order = ?, show_on_home = ?
                    WHERE id = ?
                ");
                $stmt->execute([$parent_id, $name, $name_ar, $description, $icon_url, $image_url, $sort_order, $show_on_home, $id]);
                $success = "Category updated successfully!";
            }
        } elseif ($action === 'delete') {
            $id = $_POST['id'];
            
            // Check if category has children
            $stmt = $conn->prepare("SELECT COUNT(*) FROM service_categories WHERE parent_id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Cannot delete category with sub-categories. Delete sub-categories first.";
            } else {
                $stmt = $conn->prepare("UPDATE service_categories SET is_active = 0 WHERE id = ?");
                $stmt->execute([$id]);
                $success = "Category deleted successfully!";
            }
        } elseif ($action === 'toggle_status') {
            $id = $_POST['id'];
            $stmt = $conn->prepare("UPDATE service_categories SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$id]);
            $success = "Category status updated!";
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get all categories
$categories = $conn->query("
    SELECT 
        c.*,
        p.name as parent_name,
        (SELECT COUNT(*) FROM service_categories WHERE parent_id = c.id) as children_count,
        (SELECT COUNT(*) FROM services WHERE category_id = c.id AND is_active = 1) as services_count
    FROM service_categories c
    LEFT JOIN service_categories p ON c.parent_id = p.id
    ORDER BY c.parent_id, c.sort_order, c.name
")->fetchAll(PDO::FETCH_ASSOC);

// Get parent categories for dropdown
$parentCategories = $conn->query("
    SELECT id, name FROM service_categories 
    WHERE parent_id IS NULL AND is_active = 1 
    ORDER BY sort_order, name
")->fetchAll(PDO::FETCH_ASSOC);

// Get editing category if specified
$editCategory = null;
if (isset($_GET['edit'])) {
    $stmt = $conn->prepare("SELECT * FROM service_categories WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editCategory = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!-- Alerts -->
<?php if (isset($success)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Category Form -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-<?= $editCategory ? 'edit' : 'plus' ?>"></i>
                    <?= $editCategory ? 'Edit Category' : 'Add New Category' ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="<?= $editCategory ? 'update' : 'create' ?>">
                    <?php if ($editCategory): ?>
                        <input type="hidden" name="id" value="<?= $editCategory['id'] ?>">
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Parent Category</label>
                        <select name="parent_id" class="form-select">
                            <option value="">-- Main Category --</option>
                            <?php foreach ($parentCategories as $parent): ?>
                                <option value="<?= $parent['id'] ?>" 
                                    <?= ($editCategory && $editCategory['parent_id'] == $parent['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($parent['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Leave empty for main category</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Name (English) *</label>
                        <input type="text" name="name" class="form-control" required
                               value="<?= $editCategory ? htmlspecialchars($editCategory['name'] ?? '') : '' ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Name (Arabic)</label>
                        <input type="text" name="name_ar" class="form-control" dir="rtl"
                               value="<?= $editCategory ? htmlspecialchars($editCategory['name_ar'] ?? '') : '' ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"><?= $editCategory ? htmlspecialchars($editCategory['description'] ?? '') : '' ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Icon (Emoji)</label>
                        <input type="text" name="icon_url" class="form-control" 
                               placeholder="🧹 🐛 🔧 ✨"
                               value="<?= $editCategory ? htmlspecialchars($editCategory['icon_url'] ?? '') : '' ?>">
                        <small class="text-muted">Optional: Use emoji as icon (fallback if no image)</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Category Image</label>
                        <input type="file" name="image_file" class="form-control" accept="image/*">
                        <small class="text-muted">Upload JPG, PNG, GIF, or WebP (max 5MB). Recommended: Square image (300x300px or larger).</small>
                        <input type="hidden" name="image_existing" value="<?= $editCategory ? htmlspecialchars($editCategory['image_url'] ?? '') : '' ?>">
                    </div>
                    <?php if ($editCategory && !empty($editCategory['image_url'])): ?>
                        <div class="mb-3">
                            <label class="form-label d-block">Current Image</label>
                            <img src="<?= htmlspecialchars($editCategory['image_url']) ?>" alt="Category image" class="img-thumbnail" style="max-height: 150px; max-width: 150px; object-fit: cover;">
                        </div>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="<?= $editCategory ? $editCategory['sort_order'] : '0' ?>">
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="show_on_home" class="form-check-input" id="show_on_home"
                               <?= ($editCategory && $editCategory['show_on_home']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="show_on_home">
                            Show on Home Screen
                        </label>
                        <small class="d-block text-muted">Only for main categories</small>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-gradient">
                            <i class="fas fa-save"></i> <?= $editCategory ? 'Update' : 'Create' ?> Category
                        </button>
                        <?php if ($editCategory): ?>
                            <a href="?page=categories" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Categories List -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Categories List</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Icon</th>
                                <th>Name</th>
                                <th>Parent</th>
                                <th>Services</th>
                                <th>Order</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $cat): ?>
                                <tr class="<?= !$cat['is_active'] ? 'table-secondary' : '' ?>">
                                    <td>
                                        <?php if (!empty($cat['image_url'])): ?>
                                            <img src="<?= htmlspecialchars($cat['image_url']) ?>" alt="Category" style="width: 40px; height: 40px; object-fit: cover; border-radius: 8px;">
                                        <?php else: ?>
                                            <span style="font-size: 24px;"><?= htmlspecialchars($cat['icon_url'] ?? '📁') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($cat['name'] ?? '') ?></strong>
                                        <?php if ($cat['show_on_home']): ?>
                                            <span class="badge bg-primary">Home</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $cat['parent_name'] ? htmlspecialchars($cat['parent_name'] ?? '') : '<em class="text-muted">Main</em>' ?></td>
                                    <td>
                                        <span class="badge bg-info"><?= $cat['services_count'] ?> services</span>
                                        <?php if ($cat['children_count'] > 0): ?>
                                            <span class="badge bg-secondary"><?= $cat['children_count'] ?> sub</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $cat['sort_order'] ?></td>
                                    <td>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-<?= $cat['is_active'] ? 'success' : 'secondary' ?>">
                                                <?= $cat['is_active'] ? 'Active' : 'Inactive' ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <a href="?page=categories&edit=<?= $cat['id'] ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="POST" style="display:inline;" 
                                              onsubmit="return confirm('Are you sure you want to delete this category?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $cat['id'] ?>">
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

