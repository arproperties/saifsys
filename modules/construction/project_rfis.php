<?php
/**
 * Construction Module — Project RFIs (Phase 3)
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
    SELECT r.*, c.contractor_name
    FROM co_rfis r
    LEFT JOIN co_project_contractors pc ON pc.id = r.project_contractor_id
    LEFT JOIN co_contractors c ON c.id = pc.contractor_id
    WHERE r.project_id = ? AND r.company_id = ?
    ORDER BY r.issued_date DESC, r.id DESC
");
$rows->execute([$project_id, $cid]);
$rows = $rows->fetchAll(PDO::FETCH_ASSOC);
$pageTitle = 'RFIs — ' . $project['project_name'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="mb-4">
    <a href="project_view.php?id=<?= $project_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back to Project</a>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <h1 class="h4 mb-0">Request for Information (RFI)</h1>
            <button type="button" class="btn btn-link btn-sm text-muted p-0 align-top" data-bs-toggle="modal" data-bs-target="#helpRfiModal" title="What are RFIs?"><i class="bi bi-question-circle fs-5"></i></button>
        </div>
        <a href="project_rfi_add.php?project_id=<?= $project_id ?>" class="btn btn-primary">+ RFI</a>
    </div>
    <p class="text-muted mb-0 mt-1"><?= h($project['project_code']) ?> — <?= h($project['project_name']) ?></p>
</div>

<!-- Help modal: What are RFIs? -->
<div class="modal fade" id="helpRfiModal" tabindex="-1" aria-labelledby="helpRfiModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="helpRfiModalLabel"><i class="bi bi-question-circle me-2"></i>What are RFIs?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p><strong>RFI (Request for Information)</strong> is a formal question about the project — design, scope, materials, or contract. The contractor (or you) raises an RFI when something is unclear, missing, or conflicting.</p>
                <p class="mb-2"><strong>Examples:</strong></p>
                <ul>
                    <li>“Dimension on drawing A doesn’t match drawing B — which is correct?”</li>
                    <li>“Spec says product X; only product Y is available locally — can we substitute?”</li>
                    <li>“How should we connect the new slab to the existing foundation?”</li>
                </ul>
                <p><strong>Why use them?</strong> To get a written answer so there’s no “he said / she said,” and to resolve conflicts or gaps before building. Answers can support variation orders or claims later.</p>
                <p class="mb-2"><strong>Typical flow:</strong></p>
                <ol>
                    <li>Someone raises the question (status: <strong>Open</strong>)</li>
                    <li>You (or the consultant) answer in the <strong>Response</strong> field and set <strong>Answered date</strong></li>
                    <li>Set status to <strong>Answered</strong> or <strong>Closed</strong></li>
                </ol>
                <p class="mb-0 small text-muted">Use the subject and description for the question; use the response field for your answer. Optionally link the project–contractor to show who asked.</p>
            </div>
        </div>
    </div>
</div>

<div class="card card-round">
    <div class="card-body">
        <?php if (empty($rows)): ?>
            <p class="text-muted mb-0">No RFIs yet. <a href="project_rfi_add.php?project_id=<?= $project_id ?>">Add the first RFI</a>.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Number</th><th>Subject</th><th>Contractor</th><th>Status</th><th>Issued</th><th>Due</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h($r['rfi_number']) ?></td>
                        <td><?= h($r['subject']) ?></td>
                        <td><?= h($r['contractor_name'] ?? '-') ?></td>
                        <td><span class="badge bg-<?= $r['status'] === 'closed' ? 'secondary' : ($r['status'] === 'answered' ? 'success' : 'warning') ?>"><?= h($r['status']) ?></span></td>
                        <td><?= date('M j, Y', strtotime($r['issued_date'])) ?></td>
                        <td><?= $r['due_date'] ? date('M j, Y', strtotime($r['due_date'])) : '-' ?></td>
                        <td><a href="project_rfi_edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
