<?php
/**
 * Promotional Banners Management
 */

function handleBannerImageUpload(string $fieldName, ?string $existingPath = null): ?string {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return $existingPath;
    }

    $file = $_FILES[$fieldName];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Failed to upload image. Please try again.');
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new Exception('Invalid image format. Allowed: JPG, PNG, GIF, WEBP.');
    }

    $rootPath = dirname(__DIR__, 2);
    $uploadDir = $rootPath . '/uploads/mobile_banners/';

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        throw new Exception('Unable to create banner upload directory.');
    }

    try {
        $random = bin2hex(random_bytes(4));
    } catch (Exception $e) {
        $random = bin2hex(openssl_random_pseudo_bytes(4));
    }

    $uniqueName = 'banner_' . date('YmdHis') . '_' . $random . '.' . $extension;
    $destination = $uploadDir . $uniqueName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new Exception('Failed to save uploaded image.');
    }

    // Remove previous file if replaced and exists inside banner directory
    $prefix = 'uploads/mobile_banners/';
    if ($existingPath && substr($existingPath, 0, strlen($prefix)) === $prefix) {
        $existingFile = $rootPath . '/' . $existingPath;
        if (is_file($existingFile)) {
            @unlink($existingFile);
        }
    }

    return 'uploads/mobile_banners/' . $uniqueName;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'create' || $action === 'update') {
            $id = $_POST['id'] ?? null;
            $title = $_POST['title'];
            $description = $_POST['description'] ?? null;
            $link_type = $_POST['link_type'] ?? 'url';
            $link_url = ($link_type === 'url') ? ($_POST['link_url'] ?? null) : null;
            $service_id = ($link_type === 'service') ? (!empty($_POST['service_id']) ? (int)$_POST['service_id'] : null) : null;
            $sort_order = $_POST['sort_order'] ?? 0;
            $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $existing_image = $_POST['existing_image'] ?? null;

            $image_url = handleBannerImageUpload('image_file', $existing_image);

            if ($action === 'create' && !$image_url) {
                throw new Exception('Banner image is required.');
            }
            
            if ($action === 'create') {
                $stmt = $conn->prepare("
                    INSERT INTO promotional_banners 
                    (title, description, image_url, link_type, link_url, service_id, sort_order, start_date, end_date, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([$title, $description, $image_url, $link_type, $link_url, $service_id, $sort_order, $start_date, $end_date]);
                $success = "Banner created successfully!";
            } else {
                $stmt = $conn->prepare("
                    UPDATE promotional_banners 
                    SET title = ?, description = ?, image_url = ?, link_type = ?, link_url = ?, service_id = ?, 
                        sort_order = ?, start_date = ?, end_date = ?
                    WHERE id = ?
                ");
                $stmt->execute([$title, $description, $image_url, $link_type, $link_url, $service_id, $sort_order, $start_date, $end_date, $id]);
                $success = "Banner updated successfully!";
            }
        } elseif ($action === 'delete') {
            $id = $_POST['id'];
            $stmt = $conn->prepare("DELETE FROM promotional_banners WHERE id = ?");
            $stmt->execute([$id]);
            $success = "Banner deleted successfully!";
        } elseif ($action === 'toggle_status') {
            $id = $_POST['id'];
            $stmt = $conn->prepare("UPDATE promotional_banners SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$id]);
            $success = "Banner status updated!";
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get all banners
$banners = $conn->query("SELECT * FROM promotional_banners ORDER BY sort_order, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Get editing banner if specified
$editBanner = null;
if (isset($_GET['edit'])) {
    $stmt = $conn->prepare("SELECT * FROM promotional_banners WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editBanner = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all active services for dropdown
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
    <!-- Banner Form -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-<?= $editBanner ? 'edit' : 'plus' ?>"></i>
                    <?= $editBanner ? 'Edit Banner' : 'Add New Banner' ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="<?= $editBanner ? 'update' : 'create' ?>">
                    <?php if ($editBanner): ?>
                        <input type="hidden" name="id" value="<?= $editBanner['id'] ?>">
                        <input type="hidden" name="existing_image" value="<?= htmlspecialchars($editBanner['image_url'] ?? '') ?>">
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Title *</label>
                        <input type="text" name="title" class="form-control" required
                               value="<?= $editBanner ? htmlspecialchars($editBanner['title'] ?? '') : '' ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"><?= $editBanner ? htmlspecialchars($editBanner['description'] ?? '') : '' ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Banner Image <?= $editBanner ? '' : '*' ?></label>
                        <?php if ($editBanner && !empty($editBanner['image_url'])): ?>
                            <div class="mb-2">
                                <img src="<?= htmlspecialchars($editBanner['image_url']) ?>" alt="Current banner"
                                     class="img-fluid rounded border" style="max-height: 160px; object-fit: cover;">
                            </div>
                        <?php endif; ?>
                        <input type="file" name="image_file" class="form-control" <?= $editBanner ? '' : 'required' ?> accept="image/*">
                        <small class="text-muted">Upload JPG, PNG, GIF, or WEBP. Recommended size: 800x300px.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Link Type *</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="link_type" id="link_type_url" value="url" 
                                   <?= (!$editBanner || ($editBanner['link_type'] ?? 'url') === 'url') ? 'checked' : '' ?> 
                                   onchange="toggleLinkFields()">
                            <label class="form-check-label" for="link_type_url">
                                URL Link
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="link_type" id="link_type_service" value="service"
                                   <?= ($editBanner && ($editBanner['link_type'] ?? 'url') === 'service') ? 'checked' : '' ?>
                                   onchange="toggleLinkFields()">
                            <label class="form-check-label" for="link_type_service">
                                Open Service
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3" id="link_url_field">
                        <label class="form-label">Link URL</label>
                        <input type="text" name="link_url" id="link_url_input" class="form-control"
                               placeholder="https://..."
                               value="<?= $editBanner && ($editBanner['link_type'] ?? 'url') === 'url' ? htmlspecialchars($editBanner['link_url'] ?? '') : '' ?>">
                    </div>
                    
                    <div class="mb-3" id="service_id_field" style="display: <?= ($editBanner && ($editBanner['link_type'] ?? 'url') === 'service') ? 'block' : 'none' ?>;">
                        <label class="form-label">Select Service</label>
                        <select name="service_id" id="service_id_input" class="form-select">
                            <option value="">-- Select a Service --</option>
                            <?php foreach ($services as $service): ?>
                                <option value="<?= $service['id'] ?>" 
                                        <?= ($editBanner && isset($editBanner['service_id']) && $editBanner['service_id'] == $service['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($service['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <script>
                    function toggleLinkFields() {
                        const linkType = document.querySelector('input[name="link_type"]:checked').value;
                        const urlField = document.getElementById('link_url_field');
                        const serviceField = document.getElementById('service_id_field');
                        const urlInput = document.getElementById('link_url_input');
                        const serviceInput = document.getElementById('service_id_input');
                        
                        if (linkType === 'url') {
                            urlField.style.display = 'block';
                            serviceField.style.display = 'none';
                            serviceInput.value = '';
                        } else {
                            urlField.style.display = 'none';
                            serviceField.style.display = 'block';
                            urlInput.value = '';
                        }
                    }
                    </script>
                    
                    <div class="mb-3">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control"
                               value="<?= $editBanner ? $editBanner['sort_order'] : '0' ?>">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-control"
                                   value="<?= $editBanner && $editBanner['start_date'] ? date('Y-m-d', strtotime($editBanner['start_date'])) : '' ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-control"
                                   value="<?= $editBanner && $editBanner['end_date'] ? date('Y-m-d', strtotime($editBanner['end_date'])) : '' ?>">
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-gradient">
                            <i class="fas fa-save"></i> <?= $editBanner ? 'Update' : 'Create' ?> Banner
                        </button>
                        <?php if ($editBanner): ?>
                            <a href="?page=banners" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Banners List -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Promotional Banners</h5>
            </div>
            <div class="card-body">
                <?php if (empty($banners)): ?>
                    <p class="text-muted">No banners yet. Create your first one!</p>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($banners as $banner): ?>
                            <div class="col-md-12 mb-3">
                                <div class="card <?= !$banner['is_active'] ? 'border-secondary' : '' ?>">
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-3">
                                                <img src="<?= htmlspecialchars($banner['image_url'] ?? '') ?>" 
                                                     class="img-fluid rounded" 
                                                     alt="<?= htmlspecialchars($banner['title'] ?? 'Banner') ?>"
                                                     onerror="this.src='https://via.placeholder.com/200x75/667eea/ffffff?text=Banner'">
                                            </div>
                                            <div class="col-md-6">
                                                <h6><?= htmlspecialchars($banner['title'] ?? '') ?></h6>
                                                <p class="text-muted mb-2"><?= htmlspecialchars($banner['description'] ?? '') ?></p>
                                                <div class="small">
                                                    <span class="badge bg-info">Order: <?= $banner['sort_order'] ?></span>
                                                    <?php if ($banner['start_date']): ?>
                                                        <span class="badge bg-secondary">From: <?= date('M d, Y', strtotime($banner['start_date'])) ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($banner['end_date']): ?>
                                                        <span class="badge bg-secondary">To: <?= date('M d, Y', strtotime($banner['end_date'])) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-md-3 text-end">
                                                <form method="POST" class="mb-2">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="id" value="<?= $banner['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-<?= $banner['is_active'] ? 'success' : 'secondary' ?> w-100">
                                                        <?= $banner['is_active'] ? 'Active' : 'Inactive' ?>
                                                    </button>
                                                </form>
                                                <a href="?page=banners&edit=<?= $banner['id'] ?>" class="btn btn-sm btn-primary w-100 mb-1">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <form method="POST" onsubmit="return confirm('Delete this banner?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $banner['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger w-100">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

