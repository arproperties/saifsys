<?php
/**
 * Real Estate Module - Document View
 * View document details, versions, history, sharing
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../legal/includes/legal_helper.php';

require_login();
if (!legal_can_manage($conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$currentUserId = $_SESSION['user_id'] ?? null;

$documentId = !empty($_GET['id']) ? (int)$_GET['id'] : null;

if (!$documentId) {
    header('Location: documents.php');
    exit;
}

// Get document
$document = $conn->prepare("
    SELECT 
        d.*,
        dt.document_type_name,
        u.username as uploaded_by_name,
        CASE d.related_type
            WHEN 'lease' THEN (SELECT CONCAT(b.name, ' - ', u2.unit_number, ' (', l.lease_number, ')') FROM re_leases l JOIN re_units u2 ON u2.id = l.unit_id JOIN re_buildings b ON b.id = u2.building_id WHERE l.id = d.related_id)
            WHEN 'tenant' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM re_tenants WHERE id = d.related_id)
            WHEN 'unit' THEN (SELECT CONCAT(b.name, ' - ', u2.unit_number) FROM re_units u2 JOIN re_buildings b ON b.id = u2.building_id WHERE u2.id = d.related_id)
            WHEN 'building' THEN (SELECT name FROM re_buildings WHERE id = d.related_id)
            WHEN 'maintenance' THEN (SELECT CONCAT('MR-', id) FROM re_maintenance_requests WHERE id = d.related_id)
            ELSE 'Other'
        END as related_name,
        (SELECT COUNT(*) FROM re_document_favorites WHERE document_id = d.id AND user_id = ?) as is_favorite,
        DATEDIFF(d.expiry_date, CURDATE()) as days_until_expiry
    FROM re_documents d
    LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
    LEFT JOIN user u ON u.id = d.uploaded_by
    WHERE d.id = ? AND d.company_id = ?
");
$document->execute([$currentUserId, $documentId, $currentCompanyId]);
$document = $document->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    header('Location: documents.php');
    exit;
}

// Log view
$conn->prepare("
    INSERT INTO re_document_access_log
    (company_id, document_id, user_id, action, ip_address, user_agent)
    VALUES (?, ?, ?, 'viewed', ?, ?)
")->execute([
    $currentCompanyId, $documentId, $currentUserId,
    $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? ''
]);

$conn->prepare("UPDATE re_documents SET view_count = view_count + 1 WHERE id = ?")->execute([$documentId]);

// Get document tags
$tags = $conn->prepare("
    SELECT dt.id, dt.tag_name, dt.tag_color
    FROM re_document_tags dt
    JOIN re_document_tag_relations dtr ON dtr.tag_id = dt.id
    WHERE dtr.document_id = ?
");
$tags->execute([$documentId]);
$tags = $tags->fetchAll(PDO::FETCH_ASSOC);

// Get document versions
$versions = $conn->prepare("
    SELECT dv.*, u.username as uploaded_by_name
    FROM re_document_versions dv
    LEFT JOIN user u ON u.id = dv.uploaded_by
    WHERE dv.document_id = ?
    ORDER BY dv.version_number DESC
");
$versions->execute([$documentId]);
$versions = $versions->fetchAll(PDO::FETCH_ASSOC);

// Get access history
$accessHistory = $conn->prepare("
    SELECT dal.*, u.username
    FROM re_document_access_log dal
    LEFT JOIN user u ON u.id = dal.user_id
    WHERE dal.document_id = ?
    ORDER BY dal.created_at DESC
    LIMIT 20
");
$accessHistory->execute([$documentId]);
$accessHistory = $accessHistory->fetchAll(PDO::FETCH_ASSOC);

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Document Details';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-file-earmark"></i> <?= h($document['document_name'] ?: $document['file_name']) ?></h1>
            <div>
                <button class="btn btn-outline-warning" onclick="toggleFavorite(<?= $documentId ?>, <?= $document['is_favorite'] ? 'true' : 'false' ?>)">
                    <i class="bi bi-star<?= $document['is_favorite'] ? '-fill' : '' ?>"></i> Favorite
                </button>
                <a href="documents_file.php?id=<?= $documentId ?>&mode=view" target="_blank" rel="noopener" class="btn btn-info text-white">
                    <i class="bi bi-eye"></i> View
                </a>
                <a href="documents_file.php?id=<?= $documentId ?>&mode=download" class="btn btn-primary">
                    <i class="bi bi-download"></i> Download
                </a>
                <a href="documents.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <div class="row">
            <!-- Main Content -->
            <div class="col-md-8">
                <!-- Document Info -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Document Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Document Type:</strong><br>
                                <?= h($document['document_type_name'] ?: ucfirst(str_replace('_', ' ', $document['document_type'] ?? 'other'))) ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Related To:</strong><br>
                                <?= ucfirst($document['related_type']) ?>: <?= h($document['related_name'] ?: '-') ?>
                            </div>
                        </div>
                        
                        <?php if ($document['document_number']): ?>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Document Number:</strong><br>
                                <?= h($document['document_number']) ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>File Name:</strong><br>
                                <?= h($document['file_name']) ?>
                            </div>
                            <div class="col-md-6">
                                <strong>File Size:</strong><br>
                                <?= formatFileSize($document['file_size'] ?? 0) ?>
                            </div>
                        </div>
                        
                        <?php if ($document['issue_date']): ?>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Issue Date:</strong><br>
                                <?= date('M d, Y', strtotime($document['issue_date'])) ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($document['expiry_date']): ?>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Expiry Date:</strong><br>
                                <span class="text-<?= 
                                    $document['days_until_expiry'] < 0 ? 'danger' : 
                                    ($document['days_until_expiry'] <= 30 ? 'warning' : 'muted') 
                                ?>">
                                    <?= date('M d, Y', strtotime($document['expiry_date'])) ?>
                                    <?php if ($document['days_until_expiry'] !== null): ?>
                                        (<?= $document['days_until_expiry'] < 0 ? abs($document['days_until_expiry']) . ' days expired' : $document['days_until_expiry'] . ' days left' ?>)
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($document['notes']): ?>
                        <div class="mb-3">
                            <strong>Notes:</strong><br>
                            <?= nl2br(h($document['notes'])) ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($tags)): ?>
                        <div class="mb-3">
                            <strong>Tags:</strong><br>
                            <?php foreach ($tags as $tag): ?>
                                <span class="badge" style="background-color: <?= h($tag['tag_color']) ?>">
                                    <?= h($tag['tag_name']) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Uploaded By:</strong><br>
                                <?= h($document['uploaded_by_name'] ?? 'Unknown') ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Uploaded On:</strong><br>
                                <?= date('M d, Y H:i', strtotime($document['created_at'])) ?>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <strong>Views:</strong> <?= $document['view_count'] ?? 0 ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Downloads:</strong> <?= $document['download_count'] ?? 0 ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Versions -->
                <?php if (!empty($versions)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-clock-history"></i> Document Versions (<?= count($versions) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Version</th>
                                        <th>File</th>
                                        <th>Size</th>
                                        <th>Uploaded By</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($versions as $version): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-<?= $version['version_number'] == $document['current_version'] ? 'primary' : 'secondary' ?>">
                                                    v<?= $version['version_number'] ?>
                                                    <?= $version['version_number'] == $document['current_version'] ? '(Current)' : '' ?>
                                                </span>
                                            </td>
                                            <td><?= h($version['file_name']) ?></td>
                                            <td><?= formatFileSize($version['file_size'] ?? 0) ?></td>
                                            <td><?= h($version['uploaded_by_name'] ?? 'Unknown') ?></td>
                                            <td><?= date('M d, Y', strtotime($version['created_at'])) ?></td>
                                            <td>
                                                <a href="<?= h($version['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Access History -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-list-ul"></i> Recent Access History</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Action</th>
                                        <th>Date</th>
                                        <th>IP Address</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($accessHistory as $access): ?>
                                        <tr>
                                            <td><?= h($access['username'] ?? 'Unknown') ?></td>
                                            <td>
                                                <span class="badge bg-<?= 
                                                    $access['action'] === 'downloaded' ? 'primary' : 
                                                    ($access['action'] === 'viewed' ? 'info' : 'secondary') 
                                                ?>">
                                                    <?= ucfirst($access['action']) ?>
                                                </span>
                                            </td>
                                            <td><?= date('M d, Y H:i', strtotime($access['created_at'])) ?></td>
                                            <td><?= h($access['ip_address'] ?: '-') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-lightning"></i> Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <a href="documents_file.php?id=<?= $documentId ?>&mode=view" target="_blank" rel="noopener" class="btn btn-info text-white w-100 mb-2">
                            <i class="bi bi-eye"></i> View Document
                        </a>
                        <a href="documents_file.php?id=<?= $documentId ?>&mode=download" class="btn btn-primary w-100 mb-2">
                            <i class="bi bi-download"></i> Download Document
                        </a>
                        <a href="documents_upload.php?related_type=<?= h($document['related_type']) ?>&related_id=<?= $document['related_id'] ?>" 
                           class="btn btn-outline-primary w-100 mb-2">
                            <i class="bi bi-upload"></i> Upload New Version
                        </a>
                        <a href="compliance.php" class="btn btn-outline-info w-100 mb-2">
                            <i class="bi bi-shield-check"></i> Compliance
                        </a>
                    </div>
                </div>

                <!-- Status -->
                <div class="card mb-4">
                    <div class="card-header bg-<?= 
                        $document['status'] === 'active' ? 'success' : 
                        ($document['status'] === 'expired' ? 'danger' : 'secondary') 
                    ?> text-white">
                        <h5 class="mb-0">Status</h5>
                    </div>
                    <div class="card-body text-center">
                        <h3 class="mb-0">
                            <span class="badge bg-<?= 
                                $document['status'] === 'active' ? 'success' : 
                                ($document['status'] === 'expired' ? 'danger' : 'secondary') 
                            ?>">
                                <?= ucfirst($document['status']) ?>
                            </span>
                        </h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function logDocumentAccess(docId, action) {
            fetch('ajax_log_document_access.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({document_id: docId, action: action})
            });
        }
        
        function toggleFavorite(docId, isFavorite) {
            fetch('ajax_toggle_favorite.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({document_id: docId, is_favorite: isFavorite})
            }).then(() => {
                location.reload();
            });
        }
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

