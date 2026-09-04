<?php
/**
 * Real Estate Accounting - Journal Templates
 * Save and load common journal entry lines
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && !empty($_POST['id'])) {
        $stmt = $conn->prepare("DELETE FROM re_journal_templates WHERE id = ? AND company_id = ?");
        $stmt->execute([(int)$_POST['id'], $currentCompanyId]);
        $success = 'Template deleted.';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'save' && !empty($_POST['name'])) {
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');
        $linesJson = $_POST['lines_json'] ?? '[]';
        $lines = json_decode($linesJson, true);
        if (is_array($lines) && count($lines) >= 2) {
            $stmt = $conn->prepare("INSERT INTO re_journal_templates (company_id, name, description, lines_json, created_by) VALUES (?,?,?,?,?)");
            $stmt->execute([$currentCompanyId, $name, $description, $linesJson, current_user_id()]);
            $success = 'Template saved.';
        } else {
            $error = 'At least 2 lines required.';
        }
    }
}

$stmt = $conn->prepare("SELECT * FROM re_journal_templates WHERE company_id = ? ORDER BY name");
$stmt->execute([$currentCompanyId]);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Journal Templates';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><?= h($success) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><?= h($error) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label"><i class="bi bi-journal-bookmark"></i> Journal Templates</div>
    <a href="journal_entry_add.php" class="btn btn-outline-secondary">New Journal Entry</a>
</div>

<div class="card card-round mb-4">
    <div class="card-header bg-light">Save current entry as template</div>
    <div class="card-body">
        <p class="small text-muted">Create a template from Journal Entry: add your lines there, then come back and use "Save as template" from the journal form, or create a template below with the same line structure.</p>
        <a href="journal_entry_add.php" class="btn btn-primary">Go to Journal Entry</a>
    </div>
</div>

<div class="card card-round">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Lines</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($templates)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No templates. Save one from <a href="journal_entry_add.php">Journal Entry</a> (use "Save as template" there).</td></tr>
                    <?php else: ?>
                        <?php foreach ($templates as $t): ?>
                            <?php $lineCount = count(json_decode($t['lines_json'], true) ?: []); ?>
                            <tr>
                                <td><strong><?= h($t['name']) ?></strong></td>
                                <td><?= h($t['description'] ?: '-') ?></td>
                                <td><?= $lineCount ?> lines</td>
                                <td>
                                    <a href="journal_entry_add.php?template_id=<?= $t['id'] ?>" class="btn btn-sm btn-primary">Use Template</a>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this template?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
