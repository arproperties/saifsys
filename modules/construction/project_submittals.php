<?php
/**
 * Construction Module — Project Submittals (Phase 3)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';

require_login();
$hasAccess = has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_CORE, $conn)
    || has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_PROJECTS, $conn);
if (!$hasAccess) require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$project_id = (int)($_GET['project_id'] ?? 0);
if (!$project_id) { header('Location: projects.php'); exit; }

$stmt = $conn->prepare("SELECT id, project_code, project_name FROM co_projects WHERE id = ? AND company_id = ?");
$stmt->execute([$project_id, $cid]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) { header('Location: projects.php'); exit; }

$rows = $conn->prepare("
    SELECT s.*, c.contractor_name
    FROM co_submittals s
    LEFT JOIN co_contractors c ON c.id = s.contractor_id
    WHERE s.project_id = ? AND s.company_id = ?
    ORDER BY s.created_at DESC
");
$rows->execute([$project_id, $cid]);
$rows = $rows->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Submittals — ' . $project['project_name'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="project_view.php?id=<?= $project_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back to Project</a>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <h1 class="h4 mb-0">Submittals</h1>
            <button type="button" class="btn btn-link btn-sm text-muted p-0 align-top" data-bs-toggle="modal" data-bs-target="#helpSubmittalsModal" title="What are submittals?"><i class="bi bi-question-circle fs-5"></i></button>
        </div>
        <a href="project_submittal_add.php?project_id=<?= $project_id ?>" class="btn btn-primary">+ Submittal</a>
    </div>
    <p class="text-muted mb-0 mt-1"><?= h($project['project_code']) ?> — <?= h($project['project_name']) ?></p>
</div>

<!-- Help modal: What are Submittals? -->
<div class="modal fade" id="helpSubmittalsModal" tabindex="-1" aria-labelledby="helpSubmittalsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="helpSubmittalsModalLabel"><i class="bi bi-file-earmark-check me-2"></i>What are Submittals?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p><strong>Submittals</strong> are documents or items the contractor sends to you for <strong>review and approval</strong> before they are used on site.</p>
                <p class="mb-2"><strong>Common types:</strong></p>
                <ul>
                    <li><strong>Drawing</strong> — shop drawings, as-built, coordination drawings</li>
                    <li><strong>Specification</strong> — product data, method statements</li>
                    <li><strong>Sample</strong> — materials, finishes, mock-ups</li>
                </ul>
                <p><strong>Why use them?</strong> To ensure what is proposed matches the contract and design before it is built, and to keep a clear approval trail.</p>
                <p class="mb-2"><strong>Typical flow:</strong></p>
                <ol>
                    <li>Contractor submits (Draft → <strong>Submitted</strong>)</li>
                    <li>You review (<strong>Under review</strong>)</li>
                    <li>You respond: <strong>Approved</strong>, <strong>Rejected</strong>, or <strong>Revised</strong> (revise and resubmit)</li>
                </ol>
                <p class="mb-0 small text-muted">Use the response date and response notes to record your decision. Optionally link a contractor to show who submitted each item.</p>
            </div>
        </div>
    </div>
</div>

<div class="card card-round">
    <div class="card-body">
        <?php if (empty($rows)): ?>
            <p class="text-muted mb-0">No submittals yet. <a href="project_submittal_add.php?project_id=<?= $project_id ?>">Add the first submittal</a>.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Number</th><th>Title</th><th>Type</th><th>Contractor</th><th>Status</th><th>Due</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h($r['submittal_number']) ?></td>
                        <td><?= h($r['title']) ?></td>
                        <td><span class="badge bg-secondary"><?= h($r['submittal_type']) ?></span></td>
                        <td><?= h($r['contractor_name'] ?? '-') ?></td>
                        <td><span class="badge bg-<?= $r['status'] === 'approved' ? 'success' : ($r['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= h($r['status']) ?></span></td>
                        <td><?= $r['due_date'] ? date('M j, Y', strtotime($r['due_date'])) : '-' ?></td>
                        <td><a href="project_submittal_edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
