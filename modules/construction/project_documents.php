<?php
/**
 * Construction Module — Project Documents
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$project_id = (int)($_GET['project_id'] ?? 0);
if (!$project_id) { header('Location: projects.php'); exit; }

$stmt = $conn->prepare("SELECT id, project_code, project_name FROM co_projects WHERE id = ? AND company_id = ?");
$stmt->execute([$project_id, $cid]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) { header('Location: projects.php'); exit; }

$docs = $conn->prepare("SELECT * FROM co_documents WHERE company_id = ? AND project_id = ? ORDER BY uploaded_at DESC");
$docs->execute([$cid, $project_id]);
$docs = $docs->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Project Documents — ' . $project['project_name'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="project_view.php?id=<?= $project_id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back to Project</a>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-0">Project Documents</h1>
            <p class="text-muted mb-0"><?= h($project['project_code']) ?> — <?= h($project['project_name']) ?></p>
        </div>
        <a href="project_document_add.php?project_id=<?= $project_id ?>" class="btn btn-primary">+ Document</a>
    </div>
</div>

<div class="card card-round">
    <div class="card-body">
        <?php if (empty($docs)): ?>
            <p class="text-muted mb-0">No documents yet. <a href="project_document_add.php?project_id=<?= $project_id ?>">Attach the first document</a>.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Title</th><th>Type</th><th>File</th><th>Uploaded</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td><?= h($d['title']) ?></td>
                        <td><span class="badge bg-secondary"><?= h($d['doc_type']) ?></span></td>
                        <td><a href="<?= (strpos($d['file_path'], 'http') === 0) ? h($d['file_path']) : h($appBase . '/' . $d['file_path']) ?>" target="_blank" rel="noopener">Open</a></td>
                        <td><?= date('M j, Y H:i', strtotime($d['uploaded_at'])) ?></td>
                        <td>
                            <a href="project_document_edit.php?id=<?= (int)$d['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <a href="project_document_delete.php?id=<?= (int)$d['id'] ?>&project_id=<?= $project_id ?>" class="btn btn-sm btn-outline-danger">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
