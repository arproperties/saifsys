<?php
/**
 * Real Estate Module - Title Deed View
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

$titleDeedId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$titleDeedId) {
    header('Location: buildings.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT td.*, b.name AS building_display_name
    FROM re_title_deeds td
    LEFT JOIN re_buildings b ON b.id = td.building_id
    WHERE td.id = ? AND td.company_id = ?
");
$stmt->execute([$titleDeedId, $currentCompanyId]);
$titleDeed = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$titleDeed) {
    header('Location: buildings.php');
    exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$hasFile = !empty($titleDeed['file_path']);
$isPdf = $hasFile && (($titleDeed['mime_type'] ?? '') === 'application/pdf'
    || strtolower(pathinfo($titleDeed['file_name'] ?? '', PATHINFO_EXTENSION)) === 'pdf');
$isImage = $hasFile && !$isPdf;

$pageTitle = 'Title Deed: ' . ($titleDeed['title_deed_no'] ?: '#' . $titleDeed['id']);
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-header-label">Title Deed: <?= h($titleDeed['title_deed_no'] ?: '#' . $titleDeed['id']) ?></h1>
            <p class="text-muted mb-0"><?= h($titleDeed['building_display_name'] ?? '') ?></p>
        </div>
        <div class="btn-group">
            <a href="title_deed_form.php?id=<?= $titleDeedId ?>" class="btn btn-primary">
                <i class="bi bi-pencil"></i> Edit
            </a>
            <?php if ($hasFile): ?>
                <a href="title_deed_file.php?id=<?= $titleDeedId ?>&mode=download" class="btn btn-outline-primary">
                    <i class="bi bi-download"></i> Download
                </a>
            <?php endif; ?>
            <a href="building_view.php?id=<?= (int)$titleDeed['building_id'] ?>" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-5 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Deed Details</h5>
                </div>
                <div class="card-body">
                    <table class="table table-borderless mb-0">
                        <tr>
                            <th width="45%">Title Deed No / Year:</th>
                            <td><?= h($titleDeed['title_deed_no'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <th>Issue Date:</th>
                            <td><?= h($titleDeed['issue_date'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <th>Property Type:</th>
                            <td><?= h($titleDeed['property_type'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <th>Building Name:</th>
                            <td><?= h($titleDeed['building_name'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <th>Building No:</th>
                            <td><?= h($titleDeed['building_no'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <th>Property No:</th>
                            <td><?= h($titleDeed['property_no'] ?: '-') ?></td>
                        </tr>
                        <?php if (!empty($titleDeed['notes'])): ?>
                        <tr>
                            <th>Notes:</th>
                            <td><?= nl2br(h($titleDeed['notes'])) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-7 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Document</h5>
                    <?php if ($hasFile): ?>
                        <small><?= h($titleDeed['file_name']) ?></small>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!$hasFile): ?>
                        <p class="text-muted text-center py-5 mb-0">No file uploaded for this title deed.</p>
                    <?php elseif ($isPdf): ?>
                        <div class="d-flex justify-content-end align-items-center gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="pdfZoomStep(-25)" title="Zoom out">
                                <i class="bi bi-dash-lg"></i>
                            </button>
                            <span id="pdfZoomLabel" class="text-muted small" style="min-width:3.5em; text-align:center;">Fit</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="pdfZoomStep(25)" title="Zoom in">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="pdfZoomReset()" title="Fit width">
                                <i class="bi bi-aspect-ratio"></i>
                            </button>
                        </div>
                        <iframe id="deedPdfFrame" src="title_deed_file.php?id=<?= $titleDeedId ?>&mode=view#toolbar=0&navpanes=0&zoom=page-width"
                                style="width:100%; height:70vh; border:1px solid #dee2e6; border-radius:.25rem;"></iframe>
                        <script>
                            (function() {
                                var zoom = null; // null = fit width
                                var frame = document.getElementById('deedPdfFrame');
                                var label = document.getElementById('pdfZoomLabel');
                                var baseSrc = 'title_deed_file.php?id=<?= $titleDeedId ?>&mode=view';

                                function apply() {
                                    var z = zoom === null ? 'page-width' : zoom;
                                    // A cache-busting param is required: changing only the #fragment on an
                                    // otherwise-identical src does not force the browser's PDF viewer to reload,
                                    // so the new zoom= value is silently ignored without it.
                                    frame.src = baseSrc + '&_z=' + Date.now() + '#toolbar=0&navpanes=0&zoom=' + z;
                                    label.textContent = zoom === null ? 'Fit' : zoom + '%';
                                }
                                window.pdfZoomStep = function(delta) {
                                    zoom = Math.max(25, Math.min(300, (zoom === null ? 100 : zoom) + delta));
                                    apply();
                                };
                                window.pdfZoomReset = function() {
                                    zoom = null;
                                    apply();
                                };
                            })();
                        </script>
                    <?php elseif ($isImage): ?>
                        <a href="title_deed_file.php?id=<?= $titleDeedId ?>&mode=view" target="_blank">
                            <img src="title_deed_file.php?id=<?= $titleDeedId ?>&mode=view" alt="Title Deed"
                                 style="max-width:100%; border:1px solid #dee2e6; border-radius:.25rem;">
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
