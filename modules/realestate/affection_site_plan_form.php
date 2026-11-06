<?php
/**
 * Real Estate Module - Add/Edit Affection Plan / Site Plan
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
$userId = current_user_id();

$planId = !empty($_GET['id']) ? (int)$_GET['id'] : null;
$isEdit = $planId !== null;
$plan = null;
$error = '';

$buildingId = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : 0;

if ($isEdit) {
    $stmt = $conn->prepare("SELECT * FROM re_affection_site_plans WHERE id = ? AND company_id = ?");
    $stmt->execute([$planId, $currentCompanyId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        header('Location: buildings.php');
        exit;
    }
    $buildingId = (int)$plan['building_id'];
}

if (!$buildingId) {
    header('Location: buildings.php');
    exit;
}

$stmt = $conn->prepare("SELECT id, name FROM re_buildings WHERE id = ? AND company_id = ?");
$stmt->execute([$buildingId, $currentCompanyId]);
$building = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$building) {
    header('Location: buildings.php');
    exit;
}

$planTypeLabels = [
    'affection_plan' => 'Affection Plan',
    'site_plan' => 'Site Plan',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $planType = $_POST['plan_type'] ?? '';
    $notes = trim((string)($_POST['notes'] ?? ''));

    if (!isset($planTypeLabels[$planType])) {
        $error = 'Please select a valid plan type.';
    }

    $fileName = $isEdit ? ($plan['file_name'] ?? null) : null;
    $filePath = $isEdit ? ($plan['file_path'] ?? null) : null;
    $fileSize = $isEdit ? ($plan['file_size'] ?? null) : null;
    $mimeType = $isEdit ? ($plan['mime_type'] ?? null) : null;

    if (empty($error) && !empty($_FILES['plan_file']['tmp_name']) && $_FILES['plan_file']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['plan_file']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed, true)) {
            $error = 'File type not allowed. Allowed: ' . implode(', ', $allowed);
        } elseif ($_FILES['plan_file']['size'] > 10 * 1024 * 1024) {
            $error = 'File size exceeds 10MB limit.';
        } else {
            $uploadDir = __DIR__ . '/../../uploads/affection_site_plans';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }
            if (!is_writable($uploadDir)) {
                @chmod($uploadDir, 0777);
            }
            if (!is_writable($uploadDir)) {
                $error = 'Upload directory is not writable: uploads/affection_site_plans. Ask an administrator to run: chmod -R a+rwX uploads/affection_site_plans';
            } else {
                $newFileName = uniqid('plan_') . '_' . time() . '.' . $ext;
                $newFilePath = $uploadDir . '/' . $newFileName;
                if (move_uploaded_file($_FILES['plan_file']['tmp_name'], $newFilePath)) {
                    $fileName = $_FILES['plan_file']['name'];
                    $fileSize = filesize($newFilePath);
                    $mimeType = mime_content_type($newFilePath) ?: $_FILES['plan_file']['type'];
                    $filePath = 'uploads/affection_site_plans/' . $newFileName;
                } else {
                    $error = 'Failed to save uploaded file.';
                }
            }
        }
    } elseif (empty($error) && !$isEdit) {
        $error = 'Please upload the plan file.';
    }

    if (empty($error)) {
        if ($isEdit) {
            $stmt = $conn->prepare("
                UPDATE re_affection_site_plans SET
                    plan_type = ?, notes = ?,
                    file_name = ?, file_path = ?, file_size = ?, mime_type = ?,
                    updated_by = ?
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([
                $planType, $notes !== '' ? $notes : null,
                $fileName, $filePath, $fileSize, $mimeType,
                $userId,
                $planId, $currentCompanyId,
            ]);
            $_SESSION['flash_success'] = $planTypeLabels[$planType] . ' updated.';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO re_affection_site_plans
                    (company_id, building_id, plan_type, notes, file_name, file_path, file_size, mime_type, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $currentCompanyId, $buildingId, $planType,
                $notes !== '' ? $notes : null,
                $fileName, $filePath, $fileSize, $mimeType,
                $userId,
            ]);
            $_SESSION['flash_success'] = $planTypeLabels[$planType] . ' added.';
        }

        header('Location: building_view.php?id=' . $buildingId);
        exit;
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = $isEdit ? 'Edit Affection Plan / Site Plan' : 'Add Affection Plan / Site Plan';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-header-label"><?= $isEdit ? 'Edit Affection Plan / Site Plan' : 'Add Affection Plan / Site Plan' ?></h1>
            <p class="text-muted mb-0"><?= h($building['name']) ?></p>
        </div>
        <a href="building_view.php?id=<?= $buildingId ?>" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back to Building
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <?php csrf_field(); ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Plan Details</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Plan Type <span class="text-danger">*</span></label>
                        <select name="plan_type" class="form-select" required>
                            <option value="">Select Type</option>
                            <?php foreach ($planTypeLabels as $val => $label): ?>
                                <option value="<?= h($val) ?>" <?= ($plan['plan_type'] ?? '') === $val ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">File <?= $isEdit ? '' : '<span class="text-danger">*</span>' ?></label>
                        <input type="file" name="plan_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" <?= $isEdit ? '' : 'required' ?>>
                    </div>
                    <div class="col-md-12">
                        <small class="text-muted">
                            Max size: 10MB. Allowed: PDF, JPG, PNG.
                            <?php if ($isEdit && !empty($plan['file_name'])): ?>
                                Current file: <strong><?= h($plan['file_name']) ?></strong> — leave blank to keep it.
                            <?php endif; ?>
                        </small>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"><?= h($plan['notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="building_view.php?id=<?= $buildingId ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save"></i> <?= $isEdit ? 'Update' : 'Save' ?>
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
