<?php
/**
 * Real Estate Module - Vendor Performance Tracking
 * Track and rate vendor performance
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

$success = '';
$error = '';
$performance = null;
$isEdit = false;
$vendorId = !empty($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : null;

// Get performance if editing
if (!empty($_GET['id'])) {
    $perfId = (int)$_GET['id'];
    $stmt = $conn->prepare("
        SELECT p.*, v.vendor_name 
        FROM re_vendor_performance p
        JOIN re_vendors v ON v.id = p.vendor_id
        WHERE p.id = ? AND p.company_id = ?
    ");
    $stmt->execute([$perfId, $currentCompanyId]);
    $performance = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($performance) {
        $isEdit = true;
        $vendorId = $performance['vendor_id'];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $vendorId = (int)$_POST['vendor_id'];
    $agreementId = !empty($_POST['agreement_id']) ? (int)$_POST['agreement_id'] : null;
    $maintenanceRequestId = !empty($_POST['maintenance_request_id']) ? (int)$_POST['maintenance_request_id'] : null;
    $taskId = !empty($_POST['task_id']) ? (int)$_POST['task_id'] : null;
    $performanceDate = $_POST['performance_date'] ?? date('Y-m-d');
    $rating = (int)$_POST['rating'];
    $qualityScore = !empty($_POST['quality_score']) ? (int)$_POST['quality_score'] : null;
    $timelinessScore = !empty($_POST['timeliness_score']) ? (int)$_POST['timeliness_score'] : null;
    $communicationScore = !empty($_POST['communication_score']) ? (int)$_POST['communication_score'] : null;
    $costEffectivenessScore = !empty($_POST['cost_effectiveness_score']) ? (int)$_POST['cost_effectiveness_score'] : null;
    $comments = trim($_POST['comments'] ?? '');
    $issuesEncountered = trim($_POST['issues_encountered'] ?? '');
    $recommendations = trim($_POST['recommendations'] ?? '');
    
    // Calculate overall score
    $scores = array_filter([$qualityScore, $timelinessScore, $communicationScore, $costEffectivenessScore]);
    $overallScore = !empty($scores) ? round(array_sum($scores) / count($scores), 2) : null;
    
    if (empty($vendorId)) {
        $error = "Vendor is required";
    } elseif ($rating < 1 || $rating > 5) {
        $error = "Rating must be between 1 and 5";
    } else {
        try {
            if ($isEdit) {
                $stmt = $conn->prepare("
                    UPDATE re_vendor_performance 
                    SET vendor_id = ?, agreement_id = ?, maintenance_request_id = ?, task_id = ?,
                        performance_date = ?, rating = ?, quality_score = ?, timeliness_score = ?,
                        communication_score = ?, cost_effectiveness_score = ?, overall_score = ?,
                        comments = ?, issues_encountered = ?, recommendations = ?
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([
                    $vendorId, $agreementId, $maintenanceRequestId, $taskId,
                    $performanceDate, $rating, $qualityScore, $timelinessScore,
                    $communicationScore, $costEffectivenessScore, $overallScore,
                    $comments, $issuesEncountered, $recommendations,
                    $performance['id'], $currentCompanyId
                ]);
                $success = "Performance review updated successfully";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO re_vendor_performance 
                    (company_id, vendor_id, agreement_id, maintenance_request_id, task_id,
                     performance_date, rating, quality_score, timeliness_score,
                     communication_score, cost_effectiveness_score, overall_score,
                     comments, issues_encountered, recommendations, reviewed_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $currentCompanyId, $vendorId, $agreementId, $maintenanceRequestId, $taskId,
                    $performanceDate, $rating, $qualityScore, $timelinessScore,
                    $communicationScore, $costEffectivenessScore, $overallScore,
                    $comments, $issuesEncountered, $recommendations, $userId
                ]);
                
                // Update vendor rating
                $avgStmt = $conn->prepare("
                    UPDATE re_vendors 
                    SET rating = (
                        SELECT AVG(rating) 
                        FROM re_vendor_performance 
                        WHERE vendor_id = ? AND company_id = ?
                    )
                    WHERE id = ? AND company_id = ?
                ");
                $avgStmt->execute([$vendorId, $currentCompanyId, $vendorId, $currentCompanyId]);
                
                $success = "Performance review created successfully";
                header('Location: vendor_performance.php?vendor_id=' . $vendorId);
                exit;
            }
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Get vendors
$vendors = $conn->prepare("SELECT id, vendor_name FROM re_vendors WHERE company_id = ? ORDER BY vendor_name");
$vendors->execute([$currentCompanyId]);
$vendors = $vendors->fetchAll(PDO::FETCH_ASSOC);

// Get agreements
$agreements = [];
if ($vendorId) {
    $agreementsStmt = $conn->prepare("
        SELECT id, agreement_name, agreement_number 
        FROM re_service_agreements 
        WHERE vendor_id = ? AND company_id = ? 
        ORDER BY agreement_name
    ");
    $agreementsStmt->execute([$vendorId, $currentCompanyId]);
    $agreements = $agreementsStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get performance list if not editing
$performanceList = [];
if (!$isEdit) {
    $where = ["p.company_id = ?"];
    $params = [$currentCompanyId];
    
    if ($vendorId) {
        $where[] = "p.vendor_id = ?";
        $params[] = $vendorId;
    }
    
    $perfStmt = $conn->prepare("
        SELECT p.*, v.vendor_name, sa.agreement_name
        FROM re_vendor_performance p
        JOIN re_vendors v ON v.id = p.vendor_id
        LEFT JOIN re_service_agreements sa ON sa.id = p.agreement_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.performance_date DESC
    ");
    $perfStmt->execute($params);
    $performanceList = $perfStmt->fetchAll(PDO::FETCH_ASSOC);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Vendor Performance';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label"><i class="bi bi-star"></i> Vendor Performance</div>
            <?php if (!$isEdit): ?>
                <a href="vendor_performance.php?action=add<?= $vendorId ? '&vendor_id=' . $vendorId : '' ?>" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> New Review
                </a>
            <?php else: ?>
                <a href="vendor_performance.php<?= $vendorId ? '?vendor_id=' . $vendorId : '' ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            <?php endif; ?>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($isEdit || (isset($_GET['action']) && $_GET['action'] === 'add')): ?>
            <!-- Add/Edit Performance Form -->
            <form method="POST" class="card">
                <?php csrf_field(); ?>
                <div class="card-body">
                    <h5 class="mb-3">Performance Review</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Vendor *</label>
                            <select name="vendor_id" class="form-select" required id="vendorSelect" onchange="loadAgreements()">
                                <option value="">Select Vendor</option>
                                <?php foreach ($vendors as $v): ?>
                                    <option value="<?= $v['id'] ?>" 
                                            <?= ($vendorId ?? null) == $v['id'] ? 'selected' : '' ?>>
                                        <?= h($v['vendor_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Performance Date *</label>
                            <input type="date" name="performance_date" class="form-control" 
                                   value="<?= $performance['performance_date'] ?? date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Agreement (Optional)</label>
                            <select name="agreement_id" class="form-select" id="agreementSelect">
                                <option value="">None</option>
                                <?php foreach ($agreements as $a): ?>
                                    <option value="<?= $a['id'] ?>" 
                                            <?= ($performance['agreement_id'] ?? null) == $a['id'] ? 'selected' : '' ?>>
                                        <?= h($a['agreement_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Maintenance Request ID (Optional)</label>
                            <input type="number" name="maintenance_request_id" class="form-control" 
                                   value="<?= $performance['maintenance_request_id'] ?? '' ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Task ID (Optional)</label>
                            <input type="number" name="task_id" class="form-control" 
                                   value="<?= $performance['task_id'] ?? '' ?>">
                        </div>
                    </div>
                    <hr>
                    <h6>Rating & Scores</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Overall Rating * (1-5 stars)</label>
                            <select name="rating" class="form-select" required id="ratingSelect">
                                <option value="1" <?= ($performance['rating'] ?? 0) == 1 ? 'selected' : '' ?>>1 Star</option>
                                <option value="2" <?= ($performance['rating'] ?? 0) == 2 ? 'selected' : '' ?>>2 Stars</option>
                                <option value="3" <?= ($performance['rating'] ?? 0) == 3 ? 'selected' : '' ?>>3 Stars</option>
                                <option value="4" <?= ($performance['rating'] ?? 0) == 4 ? 'selected' : '' ?>>4 Stars</option>
                                <option value="5" <?= ($performance['rating'] ?? 0) == 5 ? 'selected' : '' ?>>5 Stars</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quality Score (1-10)</label>
                            <input type="number" name="quality_score" class="form-control" 
                                   value="<?= $performance['quality_score'] ?? '' ?>" min="1" max="10">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Timeliness Score (1-10)</label>
                            <input type="number" name="timeliness_score" class="form-control" 
                                   value="<?= $performance['timeliness_score'] ?? '' ?>" min="1" max="10">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Communication Score (1-10)</label>
                            <input type="number" name="communication_score" class="form-control" 
                                   value="<?= $performance['communication_score'] ?? '' ?>" min="1" max="10">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Cost Effectiveness Score (1-10)</label>
                            <input type="number" name="cost_effectiveness_score" class="form-control" 
                                   value="<?= $performance['cost_effectiveness_score'] ?? '' ?>" min="1" max="10">
                        </div>
                    </div>
                    <div class="alert alert-info">
                        <strong>Overall Score:</strong> <span id="overallScoreDisplay">-</span>/10
                        <small class="text-muted">(Calculated automatically from individual scores)</small>
                    </div>
                    <hr>
                    <h6>Review Details</h6>
                    <div class="mb-3">
                        <label class="form-label">Comments</label>
                        <textarea name="comments" class="form-control" rows="4"><?= h($performance['comments'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Issues Encountered</label>
                        <textarea name="issues_encountered" class="form-control" rows="3"><?= h($performance['issues_encountered'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Recommendations</label>
                        <textarea name="recommendations" class="form-control" rows="3"><?= h($performance['recommendations'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Save Review
                    </button>
                    <a href="vendor_performance.php<?= $vendorId ? '?vendor_id=' . $vendorId : '' ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        <?php else: ?>
            <!-- Performance List -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Performance Reviews (<?= count($performanceList) ?>)</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($performanceList)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> No performance reviews found. 
                            <a href="vendor_performance.php?action=add<?= $vendorId ? '&vendor_id=' . $vendorId : '' ?>">Create your first review</a>.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Vendor</th>
                                        <th>Agreement</th>
                                        <th>Rating</th>
                                        <th>Overall Score</th>
                                        <th>Comments</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($performanceList as $perf): ?>
                                        <tr>
                                            <td><?= date('M d, Y', strtotime($perf['performance_date'])) ?></td>
                                            <td><?= h($perf['vendor_name']) ?></td>
                                            <td><?= h($perf['agreement_name'] ?: '-') ?></td>
                                            <td>
                                                <span class="text-warning">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <i class="bi bi-star<?= $i <= $perf['rating'] ? '-fill' : '' ?>"></i>
                                                    <?php endfor; ?>
                                                </span>
                                            </td>
                                            <td><?= $perf['overall_score'] ? number_format($perf['overall_score'], 1) : '-' ?>/10</td>
                                            <td><?= h(substr($perf['comments'] ?: '-', 0, 50)) ?><?= strlen($perf['comments'] ?: '') > 50 ? '...' : '' ?></td>
                                            <td>
                                                <a href="vendor_performance.php?id=<?= $perf['id'] ?>" class="btn btn-sm btn-primary">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function loadAgreements() {
            const vendorId = document.getElementById('vendorSelect').value;
            const agreementSelect = document.getElementById('agreementSelect');
            
            agreementSelect.innerHTML = '<option value="">Loading...</option>';
            
            if (!vendorId) {
                agreementSelect.innerHTML = '<option value="">None</option>';
                return;
            }
            
            fetch(`ajax_get_vendor_agreements.php?vendor_id=${vendorId}`)
                .then(response => response.json())
                .then(data => {
                    agreementSelect.innerHTML = '<option value="">None</option>';
                    if (data.success && data.agreements) {
                        data.agreements.forEach(agreement => {
                            const option = document.createElement('option');
                            option.value = agreement.id;
                            option.textContent = agreement.agreement_name;
                            agreementSelect.appendChild(option);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    agreementSelect.innerHTML = '<option value="">Error loading agreements</option>';
                });
        }

        // Calculate overall score
        function updateOverallScore() {
            const scores = [
                document.querySelector('input[name="quality_score"]').value,
                document.querySelector('input[name="timeliness_score"]').value,
                document.querySelector('input[name="communication_score"]').value,
                document.querySelector('input[name="cost_effectiveness_score"]').value
            ].filter(s => s && s > 0).map(s => parseFloat(s));
            
            if (scores.length > 0) {
                const avg = scores.reduce((a, b) => a + b, 0) / scores.length;
                document.getElementById('overallScoreDisplay').textContent = avg.toFixed(1);
            } else {
                document.getElementById('overallScoreDisplay').textContent = '-';
            }
        }

        document.querySelectorAll('input[name$="_score"]').forEach(input => {
            input.addEventListener('input', updateOverallScore);
        });
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

