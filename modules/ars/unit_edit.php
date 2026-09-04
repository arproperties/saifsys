<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);
$unitId = (int)($_GET['id'] ?? 0);

if (!$unitId) { header('Location: units.php'); exit; }

try {
    ars_assert_unit_usable_for_ars($conn, $unitId, $arsCompanyId);
} catch (Throwable $e) {
    header('Location: units.php');
    exit;
}

[$unitWhere, $unitParams] = ars_short_term_units_where($arsCompanyId, 'u');
$stmt = $conn->prepare("SELECT u.*, b.name AS building_name FROM re_units u LEFT JOIN re_buildings b ON b.id = u.building_id WHERE u.id = ? AND {$unitWhere}");
$stmt->execute(array_merge([$unitId], $unitParams));
$unit = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$unit) { header('Location: units.php'); exit; }

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_unit'])) {
    csrf_verify();
    $nightlyRate    = max(0, (float)($_POST['nightly_rate'] ?? 0));
    $monthlyRate    = max(0, (float)($_POST['monthly_rate'] ?? 0));
    $listingTitle   = trim($_POST['listing_title'] ?? '');
    $shortDesc      = trim($_POST['short_description'] ?? '');
    $amenitiesRaw   = trim($_POST['amenities'] ?? '');
    $maxGuests      = max(1, (int)($_POST['max_guests'] ?? 2));
    $isListed       = isset($_POST['is_listed']) ? 1 : 0;

    $amenities = [];
    if ($amenitiesRaw) {
        foreach (explode(',', $amenitiesRaw) as $a) {
            $a = trim($a);
            if ($a !== '') $amenities[] = $a;
        }
    }
    $amenitiesJson = !empty($amenities) ? json_encode($amenities) : null;

    try {
        $upd = $conn->prepare("
            UPDATE re_units u SET
                nightly_rate = ?, monthly_rate = ?, listing_title = ?, short_description = ?,
                amenities_json = ?, max_guests = ?, is_listed = ?
            WHERE u.id = ? AND {$unitWhere}
        ");
        $upd->execute(array_merge(
            [$nightlyRate, $monthlyRate, $listingTitle ?: null, $shortDesc ?: null, $amenitiesJson, $maxGuests, $isListed, $unitId],
            $unitParams
        ));
        $success = 'Unit updated successfully.';
        // Refresh
        $stmt = $conn->prepare("SELECT u.*, b.name AS building_name FROM re_units u LEFT JOIN re_buildings b ON b.id = u.building_id WHERE u.id = ? AND {$unitWhere}");
        $stmt->execute(array_merge([$unitId], $unitParams));
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = 'Failed to save: ' . $e->getMessage();
    }
}

// Load photos
$photos = [];
try {
    $stmt = $conn->prepare("SELECT * FROM ars_unit_photos WHERE unit_id = ? ORDER BY sort_order, id");
    $stmt->execute([$unitId]);
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$amenitiesList = $unit['amenities_json'] ? json_decode($unit['amenities_json'], true) : [];
$amenitiesStr = is_array($amenitiesList) ? implode(', ', $amenitiesList) : '';

$pageTitle = 'Edit Unit';
ars_shell_begin([
    'title' => 'Edit Unit',
    'subtitle' => ($unit['unit_number'] ?? '') . (!empty($unit['building_name']) ? ' · ' . $unit['building_name'] : ''),
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Units', 'href' => 'units.php'],
        ['label' => 'Edit'],
    ],
    'actions_html' => '<div class="d-flex gap-2 flex-wrap">'
        . ars_ui_button('Profile / history', ['href' => 'unit_profile.php?id=' . (int)$unitId, 'size' => 'sm', 'icon' => 'history'])
        . ars_ui_button('Back to units', ['href' => 'units.php', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'arrow-left'])
        . '</div>',
    'legacy_bootstrap' => true,
]);
?>


<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="save_unit" value="1">
            <div class="ars-card mb-4">
                <div class="card-header">Unit Details — <?= h($unit['unit_number']) ?> (<?= h($unit['building_name']) ?>)</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Listing Title</label>
                            <input type="text" name="listing_title" class="form-control" value="<?= h($unit['listing_title'] ?? '') ?>" placeholder="e.g. Cozy Studio in Park Place">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Nightly Rate (AED, ex VAT)</label>
                            <input type="number" step="0.01" min="0" name="nightly_rate" class="form-control" value="<?= h($unit['nightly_rate'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Monthly package (AED/mo, ex VAT)</label>
                            <input type="number" step="0.01" min="0" name="monthly_rate" class="form-control" value="<?= h($unit['monthly_rate'] ?? '0') ?>" placeholder="0 = not used">
                            <small class="text-muted">Optional; admin “monthly package” uses nights ÷ 30.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Short Description</label>
                            <textarea name="short_description" class="form-control" rows="3" placeholder="Brief description for the listing card"><?= h($unit['short_description'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Amenities</label>
                            <input type="text" name="amenities" class="form-control" value="<?= h($amenitiesStr) ?>" placeholder="WiFi, Pool, Gym, Parking">
                            <small class="text-muted">Comma-separated list</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Max Guests</label>
                            <input type="number" min="1" max="50" name="max_guests" class="form-control" value="<?= (int)($unit['max_guests'] ?? 2) ?>">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_listed" id="isListed" <?= ($unit['is_listed'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="isListed">Listed on Portal</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?= ars_ui_button('Save changes', ['type' => 'submit', 'icon' => 'check']) ?>
        </form>
    </div>

    <div class="col-lg-5">
        <div class="ars-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-images me-2"></i>Photos</span>
                <label class="btn btn-ars btn-sm mb-0" for="photoUpload"><i class="bi bi-plus-lg me-1"></i>Upload</label>
                <input type="file" id="photoUpload" accept="image/*" multiple style="display:none">
            </div>
            <div class="card-body">
                <div id="photoGallery" class="ars-photo-gallery">
                    <?php if (empty($photos)): ?>
                    <p class="text-muted text-center py-3" id="noPhotos">No photos uploaded yet.</p>
                    <?php endif; ?>
                    <?php foreach ($photos as $p): ?>
                    <div class="ars-photo-item" data-id="<?= $p['id'] ?>">
                        <img src="../../<?= h($p['file_path']) ?>" alt="">
                        <div class="ars-photo-actions">
                            <?php if (!$p['is_primary']): ?>
                            <button class="btn btn-sm btn-light" onclick="photoAction('primary',<?= $p['id'] ?>)" title="Set as primary"><i class="bi bi-star"></i></button>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark"><i class="bi bi-star-fill"></i> Primary</span>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-danger" onclick="photoAction('delete',<?= $p['id'] ?>)" title="Delete"><i class="bi bi-trash"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$pageScripts = <<<JS
<script>
const unitId = {$unitId};

document.getElementById('photoUpload').addEventListener('change', function() {
    const files = this.files;
    if (!files.length) return;
    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('_csrf', window.ARS_CSRF || '');
    fd.append('unit_id', unitId);
    for (let i = 0; i < files.length; i++) fd.append('photos[]', files[i]);
    fetch('ajax_photo_upload.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else alert(d.error || 'Upload failed'); })
        .catch(e => alert('Upload error'));
    this.value = '';
});

function photoAction(action, photoId) {
    if (action === 'delete' && !confirm('Delete this photo?')) return;
    const fd = new FormData();
    fd.append('action', action);
    fd.append('_csrf', window.ARS_CSRF || '');
    fd.append('photo_id', photoId);
    fd.append('unit_id', unitId);
    fetch('ajax_photo_upload.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else alert(d.error || 'Action failed'); })
        .catch(e => alert('Error'));
}
</script>
JS;
if (!empty($pageScripts) && !empty($GLOBALS['ars_shell_state'])) {
    $GLOBALS['ars_shell_state']['pageScripts'] = $pageScripts;
}
ars_shell_end();
?>
