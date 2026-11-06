<?php
/**
 * Real Estate Module - Add/Edit Title Deed
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

$titleDeedId = !empty($_GET['id']) ? (int)$_GET['id'] : null;
$isEdit = $titleDeedId !== null;
$titleDeed = null;
$error = '';

$buildingId = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : 0;

if ($isEdit) {
    $stmt = $conn->prepare("SELECT * FROM re_title_deeds WHERE id = ? AND company_id = ?");
    $stmt->execute([$titleDeedId, $currentCompanyId]);
    $titleDeed = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$titleDeed) {
        header('Location: buildings.php');
        exit;
    }
    $buildingId = (int)$titleDeed['building_id'];
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $titleDeedNo = trim((string)($_POST['title_deed_no'] ?? ''));
    $issueDateRaw = trim((string)($_POST['issue_date'] ?? ''));
    $propertyType = trim((string)($_POST['property_type'] ?? ''));
    $buildingName = trim((string)($_POST['building_name'] ?? ''));
    $buildingNo = trim((string)($_POST['building_no'] ?? ''));
    $propertyNo = trim((string)($_POST['property_no'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    $issueDateSql = null;
    if ($issueDateRaw !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $issueDateRaw);
        $issueDateSql = $dt ? $dt->format('Y-m-d') : null;
    }

    // File upload (required on add, optional on edit — keep existing file if none provided)
    $fileName = $isEdit ? ($titleDeed['file_name'] ?? null) : null;
    $filePath = $isEdit ? ($titleDeed['file_path'] ?? null) : null;
    $fileSize = $isEdit ? ($titleDeed['file_size'] ?? null) : null;
    $mimeType = $isEdit ? ($titleDeed['mime_type'] ?? null) : null;

    if (!empty($_FILES['deed_file']['tmp_name']) && $_FILES['deed_file']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['deed_file']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed, true)) {
            $error = 'File type not allowed. Allowed: ' . implode(', ', $allowed);
        } elseif ($_FILES['deed_file']['size'] > 10 * 1024 * 1024) {
            $error = 'File size exceeds 10MB limit.';
        } else {
            $uploadDir = __DIR__ . '/../../uploads/title_deeds';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }
            if (!is_writable($uploadDir)) {
                @chmod($uploadDir, 0777);
            }
            if (!is_writable($uploadDir)) {
                $error = 'Upload directory is not writable: uploads/title_deeds. Ask an administrator to run: chmod -R a+rwX uploads/title_deeds';
            } else {
                $newFileName = uniqid('deed_') . '_' . time() . '.' . $ext;
                $newFilePath = $uploadDir . '/' . $newFileName;
                if (move_uploaded_file($_FILES['deed_file']['tmp_name'], $newFilePath)) {
                    $fileName = $_FILES['deed_file']['name'];
                    $fileSize = filesize($newFilePath);
                    $mimeType = mime_content_type($newFilePath) ?: $_FILES['deed_file']['type'];
                    $filePath = 'uploads/title_deeds/' . $newFileName;
                } else {
                    $error = 'Failed to save uploaded file.';
                }
            }
        }
    } elseif (!$isEdit) {
        $error = 'Please upload the Title Deed file.';
    }

    if (empty($error)) {
        if ($isEdit) {
            $stmt = $conn->prepare("
                UPDATE re_title_deeds SET
                    title_deed_no = ?, issue_date = ?, property_type = ?, building_name = ?,
                    building_no = ?, property_no = ?, notes = ?,
                    file_name = ?, file_path = ?, file_size = ?, mime_type = ?,
                    updated_by = ?
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([
                $titleDeedNo !== '' ? $titleDeedNo : null,
                $issueDateSql,
                $propertyType !== '' ? $propertyType : null,
                $buildingName !== '' ? $buildingName : null,
                $buildingNo !== '' ? $buildingNo : null,
                $propertyNo !== '' ? $propertyNo : null,
                $notes !== '' ? $notes : null,
                $fileName, $filePath, $fileSize, $mimeType,
                $userId,
                $titleDeedId, $currentCompanyId,
            ]);
            $_SESSION['flash_success'] = 'Title Deed updated.';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO re_title_deeds
                    (company_id, building_id, title_deed_no, issue_date, property_type, building_name,
                     building_no, property_no, notes, file_name, file_path, file_size, mime_type, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $currentCompanyId, $buildingId,
                $titleDeedNo !== '' ? $titleDeedNo : null,
                $issueDateSql,
                $propertyType !== '' ? $propertyType : null,
                $buildingName !== '' ? $buildingName : null,
                $buildingNo !== '' ? $buildingNo : null,
                $propertyNo !== '' ? $propertyNo : null,
                $notes !== '' ? $notes : null,
                $fileName, $filePath, $fileSize, $mimeType,
                $userId,
            ]);
            $titleDeedId = (int)$conn->lastInsertId();
            $_SESSION['flash_success'] = 'Title Deed added.';
        }

        header('Location: building_view.php?id=' . $buildingId);
        exit;
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = $isEdit ? 'Edit Title Deed' : 'Add Title Deed';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-header-label"><?= $isEdit ? 'Edit Title Deed' : 'Add Title Deed' ?></h1>
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
                <h5 class="mb-0">Title Deed File</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label">Deed File <?= $isEdit ? '' : '<span class="text-danger">*</span>' ?></label>
                        <input type="file" name="deed_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" <?= $isEdit ? '' : 'required' ?>>
                        <small class="text-muted">
                            Max size: 10MB. Allowed: PDF, JPG, PNG.
                            <?php if ($isEdit && !empty($titleDeed['file_name'])): ?>
                                Current file: <strong><?= h($titleDeed['file_name']) ?></strong> — leave blank to keep it.
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">Deed Details</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Title Deed No / Year</label>
                        <input type="text" name="title_deed_no" class="form-control" placeholder="e.g. 0123456/2022"
                               value="<?= h($titleDeed['title_deed_no'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Issue Date</label>
                        <input type="date" name="issue_date" class="form-control"
                               value="<?= h($titleDeed['issue_date'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Property Type</label>
                        <input type="text" name="property_type" class="form-control" placeholder="e.g. Flat, Villa, Office, Land"
                               value="<?= h($titleDeed['property_type'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Building Name</label>
                        <input type="text" name="building_name" class="form-control"
                               value="<?= h($titleDeed['building_name'] ?? $building['name']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Building No</label>
                        <input type="text" name="building_no" class="form-control"
                               value="<?= h($titleDeed['building_no'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Property No</label>
                        <input type="text" name="property_no" class="form-control"
                               value="<?= h($titleDeed['property_no'] ?? '') ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"><?= h($titleDeed['notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="building_view.php?id=<?= $buildingId ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save"></i> <?= $isEdit ? 'Update Title Deed' : 'Save Title Deed' ?>
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
