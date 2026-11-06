<?php
/**
 * Real Estate Module - Affection Plan / Site Plan View
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

$planId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$planId) {
    header('Location: buildings.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT p.*, b.name AS building_display_name
    FROM re_affection_site_plans p
    LEFT JOIN re_buildings b ON b.id = p.building_id
    WHERE p.id = ? AND p.company_id = ?
");
$stmt->execute([$planId, $currentCompanyId]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    header('Location: buildings.php');
    exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$planTypeLabels = [
    'affection_plan' => 'Affection Plan',
    'site_plan' => 'Site Plan',
];
$planTypeLabel = $planTypeLabels[$plan['plan_type']] ?? $plan['plan_type'];

$hasFile = !empty($plan['file_path']);
$isPdf = $hasFile && (($plan['mime_type'] ?? '') === 'application/pdf'
    || strtolower(pathinfo($plan['file_name'] ?? '', PATHINFO_EXTENSION)) === 'pdf');
$isImage = $hasFile && !$isPdf;

$pageTitle = $planTypeLabel;
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-header-label"><?= h($planTypeLabel) ?></h1>
            <p class="text-muted mb-0"><?= h($plan['building_display_name'] ?? '') ?></p>
        </div>
        <div class="btn-group">
            <a href="affection_site_plan_form.php?id=<?= $planId ?>" class="btn btn-primary">
                <i class="bi bi-pencil"></i> Edit
            </a>
            <?php if ($hasFile): ?>
                <a href="affection_site_plan_file.php?id=<?= $planId ?>&mode=download" class="btn btn-outline-primary">
                    <i class="bi bi-download"></i> Download
                </a>
            <?php endif; ?>
            <a href="building_view.php?id=<?= (int)$plan['building_id'] ?>" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Details</h5>
                </div>
                <div class="card-body">
                    <table class="table table-borderless mb-0">
                        <tr>
                            <th width="40%">Type:</th>
                            <td><?= h($planTypeLabel) ?></td>
                        </tr>
                        <tr>
                            <th>File Name:</th>
                            <td><?= h($plan['file_name'] ?: '-') ?></td>
                        </tr>
                        <?php if (!empty($plan['notes'])): ?>
                        <tr>
                            <th>Notes:</th>
                            <td><?= nl2br(h($plan['notes'])) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-8 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Document</h5>
                    <?php if ($hasFile): ?>
                        <small><?= h($plan['file_name']) ?></small>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!$hasFile): ?>
                        <p class="text-muted text-center py-5 mb-0">No file uploaded.</p>
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
                        <iframe id="deedPdfFrame" src="affection_site_plan_file.php?id=<?= $planId ?>&mode=view#toolbar=0&navpanes=0&zoom=page-width"
                                style="width:100%; height:70vh; border:1px solid #dee2e6; border-radius:.25rem;"></iframe>
                        <script>
                            (function() {
                                var zoom = null; // null = fit width
                                var frame = document.getElementById('deedPdfFrame');
                                var label = document.getElementById('pdfZoomLabel');
                                var baseSrc = 'affection_site_plan_file.php?id=<?= $planId ?>&mode=view';

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
                        <a href="affection_site_plan_file.php?id=<?= $planId ?>&mode=view" target="_blank">
                            <img src="affection_site_plan_file.php?id=<?= $planId ?>&mode=view" alt="<?= h($planTypeLabel) ?>"
                                 style="max-width:100%; border:1px solid #dee2e6; border-radius:.25rem;">
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
