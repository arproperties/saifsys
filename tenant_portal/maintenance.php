<?php
/**
 * Tenant Portal — Maintenance requests (list + create)
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

require_tenant_login($conn);
require_once __DIR__ . '/includes/tenant_lease_loader.php';

if (!$lease) {
    header('Location: login.php');
    exit;
}

$lease_id = (int)$lease['lease_id'];
$tenant_id = (int)$lease['tenant_id'];
$unit_id = (int)$lease['unit_id'];
$company_id = (int)$lease['company_id'];

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? 'general');
    $priority = $_POST['priority'] ?? 'medium';
    if (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
        $priority = 'medium';
    }
    if ($description === '') {
        $message = 'Please enter a description.';
        $messageType = 'danger';
    } else {
        $ins = $conn->prepare("
            INSERT INTO re_maintenance_requests (company_id, unit_id, tenant_id, lease_id, request_date, priority, category, description, status, created_by)
            VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, 'pending', NULL)
        ");
        $ins->execute([$company_id, $unit_id, $tenant_id, $lease_id, $priority, $category ?: 'general', $description]);
        $requestId = (int) $conn->lastInsertId();
        $message = 'Your maintenance request has been submitted. We will update you on the status.';
        $messageType = 'success';

        // Optional: handle photo uploads (store like RE module). Support multiple files.
        if ($requestId && !empty($_FILES['photos']['name'] ?? null)) {
            try {
                $names = $_FILES['photos']['name'];
                $tmpNames = $_FILES['photos']['tmp_name'];
                $errors = $_FILES['photos']['error'];
                $sizes = $_FILES['photos']['size'];

                $uploadBaseDir = __DIR__ . '/../uploads/realestate/maintenance';
                $uploadDir = $uploadBaseDir . '/' . $requestId;
                if (!is_dir($uploadBaseDir)) {
                    @mkdir($uploadBaseDir, 0777, true);
                }
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0777, true);
                }

                if (is_dir($uploadDir) && is_writable($uploadDir)) {
                    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    $allowedMime = ['image/jpeg','image/png','image/gif','image/webp'];
                    $maxSize = 5 * 1024 * 1024; // 5MB per file

                    foreach ((array)$names as $idx => $originalName) {
                        if (($errors[$idx] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                            continue;
                        }
                        $tmp = $tmpNames[$idx] ?? null;
                        $size = (int)($sizes[$idx] ?? 0);
                        if (!$tmp || !is_uploaded_file($tmp)) {
                            continue;
                        }
                        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                        if (!in_array($ext, $allowedTypes, true) || $size <= 0 || $size > $maxSize) {
                            continue;
                        }
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mimeType = $finfo ? finfo_file($finfo, $tmp) : null;
                        if ($finfo) {
                            finfo_close($finfo);
                        }
                        if (!$mimeType || !in_array($mimeType, $allowedMime, true)) {
                            continue;
                        }

                        $timestamp = time();
                        $random = bin2hex(random_bytes(4));
                        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
                        $newName = $safeName . '_' . $timestamp . '_' . $random . '.' . $ext;
                        $filePath = $uploadDir . '/' . $newName;
                        $relativePath = 'uploads/realestate/maintenance/' . $requestId . '/' . $newName;

                        if (move_uploaded_file($tmp, $filePath)) {
                            $stmt = $conn->prepare("
                                INSERT INTO re_maintenance_photos
                                    (company_id, maintenance_request_id, photo_type, file_name, file_path, file_size, mime_type, description, uploaded_by)
                                VALUES (?, ?, 'before', ?, ?, ?, ?, NULL, NULL)
                            ");
                            $stmt->execute([
                                $company_id,
                                $requestId,
                                $originalName,
                                $relativePath,
                                $size,
                                $mimeType,
                            ]);
                        }
                    }
                }
            } catch (Throwable $e) {
                // Ignore photo errors; request itself is already saved
            }
        }

        // Notify management
        if ($requestId && file_exists(__DIR__ . '/../modules/realestate/includes/re_email_helper.php')) {
            require_once __DIR__ . '/../modules/realestate/includes/re_email_helper.php';
            send_maintenance_request_notification($conn, $requestId, $company_id);
        }
    }
}

$requests = $conn->prepare("
    SELECT id, request_date, priority, category, description, status, completed_at, notes
    FROM re_maintenance_requests
    WHERE lease_id = ?
    ORDER BY request_date DESC, id DESC
");
$requests->execute([$lease_id]);
$requests = $requests->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Maintenance';
require_once __DIR__ . '/includes/tenant_layout_header.php';
?>

<h4 class="page-title">Maintenance requests</h4>
<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="portal-card card mb-4">
    <div class="card-header">Submit a new request</div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <?php csrf_field(); ?>
            <div class="mb-3">
                <label class="form-label">Category</label>
                <select name="category" class="form-select">
                    <option value="general">General</option>
                    <option value="plumbing">Plumbing</option>
                    <option value="electrical">Electrical</option>
                    <option value="ac">AC / HVAC</option>
                    <option value="pest_control">Pest control</option>
                    <option value="cleaning">Cleaning</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Priority</label>
                <select name="priority" class="form-select">
                    <option value="low">Low</option>
                    <option value="medium" selected>Medium</option>
                    <option value="high">High</option>
                    <option value="urgent">Urgent</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Description <span class="text-danger">*</span></label>
                <textarea name="description" class="form-control" rows="3" required placeholder="Describe the issue..."></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Photos (optional)</label>
                <input type="file" name="photos[]" class="form-control" accept="image/*" multiple>
                <div class="form-text">You can attach up to a few photos of the issue to help maintenance.</div>
            </div>
            <button type="submit" class="btn btn-primary w-100 w-md-auto">Submit request</button>
        </form>
    </div>
</div>

<h5 class="section-title">Your requests</h5>
<div class="portal-table-wrap">
    <table class="table table-hover">
        <thead>
            <tr>
                <th>Date</th>
                <th>Category</th>
                <th>Priority</th>
                <th>Description</th>
                <th>Status</th>
                <th>Completed</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($requests as $row): ?>
                <tr>
                    <td data-label="Date"><?= htmlspecialchars(date('M j, Y', strtotime($row['request_date']))) ?></td>
                    <td data-label="Category"><?= htmlspecialchars($row['category'] ?: '—') ?></td>
                    <td data-label="Priority"><span class="badge bg-<?= $row['priority'] === 'urgent' ? 'danger' : ($row['priority'] === 'high' ? 'warning' : 'secondary') ?>"><?= htmlspecialchars($row['priority']) ?></span></td>
                    <td data-label="Description"><?= nl2br(htmlspecialchars(mb_substr($row['description'], 0, 80) . (mb_strlen($row['description']) > 80 ? '…' : ''))) ?></td>
                    <td data-label="Status"><span class="badge bg-<?= $row['status'] === 'completed' ? 'success' : ($row['status'] === 'in_progress' ? 'primary' : 'secondary') ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                    <td data-label="Completed"><?= $row['completed_at'] ? date('M j, Y', strtotime($row['completed_at'])) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if (empty($requests)): ?>
    <p class="portal-empty">No maintenance requests yet.</p>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/tenant_layout_footer.php'; ?>
